<?php
/**
 * BizCity_CRM_Group_Secretary — thư ký nhóm Zalo (PHASE-0.124 W1): cài đặt nhóm MỘT chỗ, bậc quyền, đọc tin nhóm chuẩn hoá.
 *
 * | Việc | Ở đâu |
 * |---|---|
 * | Cài đặt theo nhóm (Theo dõi · Người được giao · Brief mỗi N tin) | option `bizcity_group_secretary_<bridge>` (một số Zalo = một option; ≤ 200 nhóm, ≤ 10 người được giao / nhóm). Lệnh chat (MCP `group.settings.set`) và Channel Gateway ghi CÙNG chỗ này (D124-12, R-AF-16) |
 * | Bậc quyền (D124-11) | 1 = UID chủ số (`policy.owner_uid` của binding) · 2 = người được giao của ĐÚNG nhóm đó · 3 = còn lại (khách) |
 * | Tin nhóm | `bizcity_crm_messages` của hội thoại `group:<gid>` (nguồn sự thật, D124-1) ⇒ `group-transcript@1` (không UID / SĐT; nhãn + vai trò + khoá s1…) |
 * | Xuống cell | `bundle()` ⇒ `owner_agent.groups[]` (owner-agent-block@1.6, cộng thêm) |
 *
 * Không bảng mới (R-LEAN-4). Không gọi model. Bậc 3 / nhóm của số khác / blog khác ⇒ `group_not_found` (không phân biệt "không có" và
 * "không được xem"). Mọi truy cập DB đi qua `$readers` để test được không cần WordPress.
 *
 * Biz Central Brain — Johnny Chu (Chu Hoàng Anh).
 *
 * [2026-10-19 09:40 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.124 W1 (B1–B2) — new file.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\CRM
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_CRM_Group_Secretary', false ) ) {
	return;
}

final class BizCity_CRM_Group_Secretary {

	const CONTRACT        = 'group-transcript@1';
	const OPTION_PREFIX   = 'bizcity_group_secretary_';
	const MAX_GROUPS      = 200;
	const MAX_ASSIGNEES   = 10;
	const PAGE_MAX_MSGS   = 200;
	const PAGE_MAX_BYTES  = 24000;
	const TEXT_MAX        = 2000;
	const BRIEF_DEFAULT   = 30;
	const BRIEF_MIN       = 10;
	const BRIEF_MAX       = 100;
	const LIST_MAX        = 50;
	const RANGE_MAX_DAYS  = 31;

	// [2026-10-19 10:11 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.124 W2 (B7/B9) — lưu tệp nhóm (theo lệnh / khi theo dõi), danh sách tài liệu nhóm.
	/** bridge => [gid…] đang theo dõi — chỉ mục nhỏ để hook sau mỗi tin CRM thoát ngay (0 truy vấn) khi không số nào theo dõi */
	const OPTION_FOLLOWING = 'bizcity_group_secretary_following';
	const SAVE_WINDOW_MIN  = 15;
	const SAVE_WINDOW_MAX  = 120;
	const SAVE_MAX_FILES   = 10;
	const FILES_LIST_MAX   = 20;
	/** tag journal của tài liệu nhóm (encode_tags chỉ giữ a-z0-9 và "-") */
	const TAG_PREFIX       = 'grp-';

	/**
	 * Test seams: get_option · update_option · policy(bridge) · groups(bridge, q, limit, gid) · conversation(conv_id) · messages(conv_id, q) ·
	 * on_change(bridge) · message_by_external(conv_id, external_id) · recent_with_files(conv_id, since, limit) · messages_by_ids(conv_id, ids) ·
	 * capture(bridge, files, group) · journal_find(filter) · owner_user(bridge) · patch_attachment(att_id, patch)
	 */
	public static $readers = array();

	private static $hooked = false;

	public static function init(): void {
		if ( self::$hooked || ! function_exists( 'add_action' ) ) {
			return;
		}
		self::$hooked = true;
		add_action( 'bizcity_crm_message_persisted', array( __CLASS__, 'on_message_persisted' ), 20 );
	}

	/* ── cài đặt (một chỗ) ─────────────────────────────────────────────── */

	public static function option_key( string $bridge ): string {
		return self::OPTION_PREFIX . substr( preg_replace( '/[^a-z0-9_\-]/i', '', $bridge ), 0, 40 );
	}

	/** @return array<string, array{follow:bool, assignees:array<int,array{uid:string,label:string}>, brief_every:int, updated_at:string}> */
	public static function all_settings( string $bridge ): array {
		$raw = isset( self::$readers['get_option'] ) ? call_user_func( self::$readers['get_option'], self::option_key( $bridge ) ) : get_option( self::option_key( $bridge ), array() );
		$out = array();
		foreach ( is_array( $raw ) ? $raw : array() as $gid => $s ) {
			$gid = self::clean_gid( (string) $gid );
			if ( '' !== $gid && is_array( $s ) ) {
				$out[ $gid ] = self::normalize_setting( $s );
			}
		}
		return $out;
	}

	public static function setting( string $bridge, string $gid ): array {
		$all = self::all_settings( $bridge );
		return $all[ self::clean_gid( $gid ) ] ?? self::normalize_setting( array() );
	}

	public static function normalize_setting( array $s ): array {
		$assignees = array();
		foreach ( (array) ( $s['assignees'] ?? array() ) as $a ) {
			$uid = is_array( $a ) ? self::clean_uid( (string) ( $a['uid'] ?? '' ) ) : '';
			if ( '' === $uid || isset( $assignees[ $uid ] ) ) {
				continue;
			}
			$assignees[ $uid ] = array( 'uid' => $uid, 'label' => self::clean_label( (string) ( $a['label'] ?? '' ) ) );
			if ( count( $assignees ) >= self::MAX_ASSIGNEES ) {
				break;
			}
		}
		$every = (int) ( $s['brief_every'] ?? self::BRIEF_DEFAULT );
		return array(
			'follow'      => ! empty( $s['follow'] ),
			'assignees'   => array_values( $assignees ),
			'brief_every' => max( self::BRIEF_MIN, min( self::BRIEF_MAX, $every > 0 ? $every : self::BRIEF_DEFAULT ) ),
			'notice'      => self::valid_notice( (string) ( $s['notice'] ?? '' ) ) ? self::clean_notice( (string) $s['notice'] ) : '',
			'save_files'  => ! array_key_exists( 'save_files', $s ) || ! empty( $s['save_files'] ),
			'updated_at'  => (string) ( $s['updated_at'] ?? '' ),
			'updated_by'  => in_array( (string) ( $s['updated_by'] ?? '' ), array( 'chat', 'web' ), true ) ? (string) $s['updated_by'] : '',
		);
	}

	// [2026-10-19 11:00 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.124 B10 (Q124-13 chủ duyệt mockup) — mẫu câu báo do chủ sửa: phải giữ {nhóm}, {người được giao} và cách dừng "tắt theo dõi";
	// biến {giờ}, {N} tuỳ chọn. Rỗng = mẫu mặc định của cell. ≤ 600 ký tự.
	const NOTICE_MAX = 600;

	public static function clean_notice( string $s ): string {
		$s = trim( str_replace( array( "\r\n", "\r" ), "\n", $s ) );
		$s = (string) preg_replace( '/[\x00-\x08\x0B-\x1F\x7F<>]+/u', '', $s );
		return function_exists( 'mb_substr' ) ? mb_substr( $s, 0, self::NOTICE_MAX ) : substr( $s, 0, self::NOTICE_MAX );
	}

	public static function valid_notice( string $s ): bool {
		$s = self::clean_notice( $s );
		$low = function_exists( 'mb_strtolower' ) ? mb_strtolower( $s ) : strtolower( $s );
		return '' !== $s && false !== strpos( $s, '{nhóm}' ) && false !== strpos( $s, '{người được giao}' ) && false !== strpos( $low, 'tắt theo dõi' );
	}

	/**
	 * Lệnh của CHỦ (bậc 1) lên cài đặt một nhóm. Bậc 2 không bật / tắt theo dõi, không giao người khác (Q124-12).
	 *
	 * @param string $action follow_on · follow_off · assign · unassign · brief_every
	 * @return array|WP_Error setting sau khi lưu + `changed`
	 */
	public static function apply_command( string $bridge, string $gid, string $action, array $args, string $requester_uid ) {
		$gid = self::clean_gid( $gid );
		if ( '' === $gid ) {
			return self::not_found();
		}
		if ( 1 !== self::tier( $bridge, $gid, $requester_uid ) ) {
			return new WP_Error( 'group_owner_only', 'Chỉ chủ số mới bật / tắt theo dõi nhóm hoặc giao người được giao.', array( 'status' => 403 ) );
		}
		$all = self::all_settings( $bridge );
		$cur = $all[ $gid ] ?? self::normalize_setting( array() );
		$new = $cur;
		switch ( $action ) {
			case 'follow_on':
				$new['follow'] = true;
				break;
			case 'follow_off':
				$new['follow'] = false;
				break;
			case 'assign':
				$uid = self::clean_uid( (string) ( $args['uid'] ?? '' ) );
				if ( '' === $uid ) {
					return new WP_Error( 'group_assignee_missing', 'Thiếu người được giao (cần @nhắc đúng người trong nhóm).', array( 'status' => 400 ) );
				}
				if ( $uid === self::owner_uid( $bridge ) ) {
					return new WP_Error( 'group_assignee_is_owner', 'Chủ số đã có mọi quyền, không cần giao.', array( 'status' => 400 ) );
				}
				$kept = array_values( array_filter( $cur['assignees'], static function ( $a ) use ( $uid ) { return $a['uid'] !== $uid; } ) );
				if ( count( $kept ) >= self::MAX_ASSIGNEES ) {
					return new WP_Error( 'group_assignee_limit', 'Mỗi nhóm giao tối đa ' . self::MAX_ASSIGNEES . ' người.', array( 'status' => 400 ) );
				}
				$kept[]           = array( 'uid' => $uid, 'label' => self::clean_label( (string) ( $args['label'] ?? '' ) ) );
				$new['assignees'] = $kept;
				break;
			case 'unassign':
				$uid              = self::clean_uid( (string) ( $args['uid'] ?? '' ) );
				$new['assignees'] = array_values( array_filter( $cur['assignees'], static function ( $a ) use ( $uid ) { return $a['uid'] !== $uid; } ) );
				break;
			case 'brief_every':
				$new['brief_every'] = (int) ( $args['n'] ?? self::BRIEF_DEFAULT );
				break;
			case 'notice':
				$text = (string) ( $args['text'] ?? '' );
				if ( '' !== trim( $text ) && ! self::valid_notice( $text ) ) {
					return new WP_Error( 'group_notice_invalid', 'Câu báo phải giữ {nhóm}, {người được giao} và cách dừng "tắt theo dõi".', array( 'status' => 400 ) );
				}
				$new['notice'] = '' === trim( $text ) ? '' : self::clean_notice( $text );
				break;
			case 'save_files_on':
			case 'save_files_off':
				$new['save_files'] = 'save_files_on' === $action;
				break;
			default:
				return new WP_Error( 'group_action_unknown', 'Lệnh không hợp lệ.', array( 'status' => 400 ) );
		}
		$new     = self::normalize_setting( $new );
		$changed = $new['follow'] !== $cur['follow'] || $new['assignees'] !== $cur['assignees'] || $new['brief_every'] !== $cur['brief_every'] || $new['notice'] !== $cur['notice'] || $new['save_files'] !== $cur['save_files'];
		if ( $changed ) {
			if ( ! isset( $all[ $gid ] ) && count( $all ) >= self::MAX_GROUPS ) {
				return new WP_Error( 'group_settings_limit', 'Số này đã cài đặt ' . self::MAX_GROUPS . ' nhóm — tắt bớt nhóm cũ trước.', array( 'status' => 400 ) );
			}
			$new['updated_at'] = gmdate( 'c' );
			$new['updated_by'] = 'web' === (string) ( $args['via'] ?? '' ) ? 'web' : 'chat'; // nhãn "đổi bởi lệnh chat lúc …" của ma trận
			$all[ $gid ]       = $new;
			// Nhóm không còn gì (tắt theo dõi, không người được giao, N mặc định) ⇒ bỏ khỏi option, giữ option nhỏ.
			if ( ! $new['follow'] && ! $new['assignees'] && self::BRIEF_DEFAULT === $new['brief_every'] && '' === $new['notice'] && $new['save_files'] ) {
				unset( $all[ $gid ] );
			}
			self::save_all( $bridge, $all );
		}
		return $new + array( 'changed' => $changed );
	}

	private static function save_all( string $bridge, array $all ): void {
		if ( isset( self::$readers['update_option'] ) ) {
			call_user_func( self::$readers['update_option'], self::option_key( $bridge ), $all );
		} else {
			update_option( self::option_key( $bridge ), $all, false );
		}
		// chỉ mục "đang theo dõi" (W2): hook sau mỗi tin CRM đọc option nhỏ, tự nạp này trước khi chạm DB
		$idx = self::following_index();
		$on  = array_keys( array_filter( $all, static function ( $s ) { return ! empty( $s['follow'] ); } ) );
		if ( $on ) {
			$idx[ $bridge ] = array_map( 'strval', $on );
		} else {
			unset( $idx[ $bridge ] );
		}
		if ( isset( self::$readers['update_option'] ) ) {
			call_user_func( self::$readers['update_option'], self::OPTION_FOLLOWING, $idx );
		} else {
			update_option( self::OPTION_FOLLOWING, $idx, true );
		}
		if ( isset( self::$readers['on_change'] ) ) {
			call_user_func( self::$readers['on_change'], $bridge );
		} elseif ( class_exists( 'BizCity_Zalo_Hub_Config_Sync' ) ) {
			BizCity_Zalo_Hub_Config_Sync::schedule(); // cell nhận owner_agent.groups mới (debounce sẵn có)
		}
		if ( class_exists( 'BizCity_CRM_Group_Index_Pack', false ) ) {
			BizCity_CRM_Group_Index_Pack::changed( $bridge ); // [2026-10-20 06:01 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.124 G0 — gói group_index đổi theo (nhóm mới theo dõi / bỏ)
		}
	}

	/* ── bậc quyền ─────────────────────────────────────────────────────── */

	public static function owner_uid( string $bridge ): string {
		$policy = isset( self::$readers['policy'] ) ? (array) call_user_func( self::$readers['policy'], $bridge )
			: ( class_exists( 'BizCity_Zalo_Agent_Principals' ) ? BizCity_Zalo_Agent_Principals::policy( $bridge ) : array() );
		return self::clean_uid( (string) ( $policy['owner_uid'] ?? '' ) );
	}

	/** 1 chủ · 2 người được giao của nhóm này · 3 còn lại. UID rỗng / chủ chưa cấu hình ⇒ 3 (fail closed). */
	public static function tier( string $bridge, string $gid, string $uid ): int {
		$uid = self::clean_uid( $uid );
		if ( '' === $uid ) {
			return 3;
		}
		$owner = self::owner_uid( $bridge );
		if ( '' !== $owner && hash_equals( $owner, $uid ) ) {
			return 1;
		}
		foreach ( self::setting( $bridge, $gid )['assignees'] as $a ) {
			if ( hash_equals( $a['uid'], $uid ) ) {
				return 2;
			}
		}
		return 3;
	}

	/* ── nhóm ──────────────────────────────────────────────────────────── */

	public static function group_ref( int $conv_id ): string {
		return 'NH-' . $conv_id;
	}

	public static function parse_group_ref( string $ref ): int {
		return preg_match( '/^NH-(\d{1,12})$/i', trim( $ref ), $m ) ? (int) $m[1] : 0;
	}

	/** [2026-10-20 05:48 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.124 S124-X1 — NH-<conv> ⇒ gid nhóm ('' nếu không phải nhóm). Cho Data Engine lọc tag grp-<gid>; KHÔNG kiểm bậc (sổ đã lọc theo chủ). */
	public static function gid_for_ref( string $ref ): string {
		$conv_id = self::parse_group_ref( $ref );
		$row     = $conv_id > 0 ? self::conversation( $conv_id ) : null;
		return is_array( $row ) ? self::gid_of( $row ) : '';
	}

	/** gid của một dòng hội thoại (contact_inboxes.source_id = group:<gid>), '' nếu không phải nhóm */
	public static function gid_of( array $row ): string {
		$src = (string) ( $row['source_id'] ?? '' );
		return 0 === strpos( $src, 'group:' ) ? self::clean_gid( substr( $src, 6 ) ) : '';
	}

	/**
	 * Nhóm người hỏi được xem: chủ ⇒ mọi nhóm của số; bậc 2 ⇒ chỉ nhóm mình được giao.
	 *
	 * @return array<int, array> items group.list
	 */
	public static function list_for( string $bridge, string $requester_uid, string $q = '', int $limit = 20 ): array {
		$limit = max( 1, min( self::LIST_MAX, $limit ) );
		$owner = self::owner_uid( $bridge );
		$uid   = self::clean_uid( $requester_uid );
		$is_owner = '' !== $owner && '' !== $uid && hash_equals( $owner, $uid );
		$all   = self::all_settings( $bridge );
		$items = array();
		// Chủ đọc theo trang cần; bậc 2 lọc trong PHP nên đọc rộng hơn (tối đa LIST_MAX × 4 hội thoại nhóm của số).
		foreach ( self::group_rows( $bridge, $q, $is_owner ? $limit : self::LIST_MAX * 4 ) as $row ) {
			$gid = self::gid_of( $row );
			if ( '' === $gid ) {
				continue;
			}
			$tier = $is_owner ? 1 : self::tier( $bridge, $gid, $uid );
			if ( 3 === $tier ) {
				continue;
			}
			$s       = $all[ $gid ] ?? self::normalize_setting( array() );
			$items[] = array(
				'group_ref'   => self::group_ref( (int) $row['id'] ),
				'thread_id'   => $gid,
				'name'        => self::group_name( $row ),
				'last_at'     => (string) ( $row['last_activity_at'] ?? $row['last_message_at'] ?? '' ),
				'follow'      => $s['follow'],
				'assignees'   => array_map( static function ( $a ) { return $a['label']; }, $s['assignees'] ),
				'brief_every' => $s['brief_every'],
				'my_tier'     => $tier,
			);
			if ( count( $items ) >= $limit ) {
				break;
			}
		}
		return $items;
	}

	/**
	 * Một nhóm theo `NH-<id>` hoặc theo `thread_id` (gid Zalo) — thuộc đúng số này, là nhóm, người hỏi bậc 1 / 2.
	 *
	 * @return array{conv_id:int, gid:string, name:string, tier:int}|WP_Error
	 */
	public static function resolve( string $bridge, string $group_ref, string $thread_id, string $requester_uid ) {
		$conv_id = self::parse_group_ref( $group_ref );
		$row     = null;
		if ( $conv_id > 0 ) {
			$row = self::conversation( $conv_id );
		} elseif ( '' !== self::clean_gid( $thread_id ) ) {
			foreach ( self::group_rows( $bridge, '', self::LIST_MAX * 4, self::clean_gid( $thread_id ) ) as $r ) {
				$row = $r;
				break;
			}
		}
		if ( ! is_array( $row ) ) {
			return self::not_found();
		}
		$ref = (string) ( $row['inbox_ref_id'] ?? '' );
		$acc = (string) ( $row['account_id'] ?? '' );
		$gid = self::gid_of( $row );
		if ( '' === $gid || ( $bridge !== $ref && $bridge !== $acc ) ) {
			return self::not_found();
		}
		$tier = self::tier( $bridge, $gid, $requester_uid );
		if ( 3 === $tier ) {
			return self::not_found();
		}
		return array( 'conv_id' => (int) $row['id'], 'gid' => $gid, 'name' => self::group_name( $row ), 'tier' => $tier );
	}

	/**
	 * Tin của một nhóm theo `group-transcript@1`. Thứ tự: after_msg_ref ⇒ tăng dần từ đó · from/to ⇒ tăng dần trong khoảng ·
	 * không gì ⇒ trang MỚI NHẤT. Trần 200 tin hoặc 24 KB / trang; tối đa 31 ngày / lần.
	 *
	 * @return array|WP_Error {group_ref, name, items[], cursor, has_more, covered{from,to}, truncated}
	 */
	public static function messages( string $bridge, array $args, string $requester_uid ) {
		$g = self::resolve( $bridge, (string) ( $args['group_ref'] ?? '' ), (string) ( $args['thread_id'] ?? '' ), $requester_uid );
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$q = array( 'limit' => max( 1, min( self::PAGE_MAX_MSGS, (int) ( $args['limit'] ?? self::PAGE_MAX_MSGS ) ) ) );
		$after = self::msg_id_of( (string) ( $args['after_msg_ref'] ?? '' ), $g['conv_id'] );
		$from  = self::day( (string) ( $args['from'] ?? '' ) );
		$to    = self::day( (string) ( $args['to'] ?? '' ) );
		if ( '' !== $from && '' !== $to ) {
			if ( $to < $from ) {
				return new WP_Error( 'group_range_invalid', 'Khoảng ngày ngược (to trước from).', array( 'status' => 400 ) );
			}
			if ( ( strtotime( $to ) - strtotime( $from ) ) / 86400 > self::RANGE_MAX_DAYS ) {
				return new WP_Error( 'group_range_too_long', 'Mỗi lần đọc tối đa ' . self::RANGE_MAX_DAYS . ' ngày — chia nhỏ kỳ.', array( 'status' => 400 ) );
			}
		}
		if ( $after > 0 ) {
			$q['after_id'] = $after;
		}
		if ( '' !== $from ) {
			$q['from'] = $from . ' 00:00:00';
		}
		if ( '' !== $to ) {
			$q['to'] = gmdate( 'Y-m-d', strtotime( $to . ' +1 day' ) ) . ' 00:00:00'; // to là ngày cuối, gồm cả ngày đó
		}
		$q['order'] = ( $after > 0 || '' !== $from ) ? 'asc' : 'latest';
		$fetch          = $q;
		$fetch['limit'] = $q['limit'] + 1; // một dòng dư = biết còn trang sau
		$rows = self::message_rows( $g['conv_id'], $fetch );
		$has_more = count( $rows ) > $q['limit'];
		if ( $has_more ) {
			$rows = 'latest' === $q['order'] ? array_slice( $rows, 1 ) : array_slice( $rows, 0, $q['limit'] );
		}
		$norm = self::normalize_rows( $rows, $g['conv_id'], self::owner_uid( $bridge ), self::setting( $bridge, $g['gid'] )['assignees'] );
		$items = $norm['items'];
		$last  = $items ? $items[ count( $items ) - 1 ] : null;
		$first = $items ? $items[0] : null;
		return array(
			'contract'  => self::CONTRACT,
			'group_ref' => self::group_ref( $g['conv_id'] ),
			'name'      => $g['name'],
			'my_tier'   => $g['tier'],
			'order'     => $q['order'],
			'items'     => $items,
			// asc: đọc tiếp sau tin cuối · latest: đọc lùi thì gọi lại với `to` sớm hơn (W1 chỉ cần trang mới nhất)
			'cursor'    => $last ? $last['msg_ref'] : '',
			'has_more'  => $has_more || $norm['truncated'],
			'covered'   => array( 'from' => $first ? $first['at'] : '', 'to' => $last ? $last['at'] : '' ),
			'truncated' => $norm['truncated'],
		);
	}

	/**
	 * Dòng CRM ⇒ group-transcript@1. Người gửi: tin `outgoing` = trợ lý (bot); `incoming` theo `ai_metadata_json.sender_user_id`
	 * so với UID chủ / người được giao. Khoá s1… ổn định trong MỘT lần trả; UID không bao giờ ra ngoài.
	 *
	 * @return array{items: array<int,array>, bytes:int, truncated:bool}
	 */
	public static function normalize_rows( array $rows, int $conv_id, string $owner_uid, array $assignees ): array {
		$keys  = array();
		$staff = array();
		foreach ( $assignees as $a ) {
			$staff[ (string) $a['uid'] ] = (string) $a['label'];
		}
		$items = array();
		$bytes = 0;
		$cut   = false;
		foreach ( $rows as $r ) {
			$meta = json_decode( (string) ( $r['ai_metadata_json'] ?? '' ), true );
			$meta = is_array( $meta ) ? $meta : array();
			list( $uid, $role, $label ) = self::sender_of( $r, $owner_uid, $staff );
			$k = '' !== $uid ? $uid : '__anon';
			if ( ! isset( $keys[ $k ] ) ) {
				$keys[ $k ] = 's' . ( count( $keys ) + 1 );
			}
			$text = trim( (string) ( $r['content'] ?? '' ) );
			$trunc = false;
			if ( function_exists( 'mb_strlen' ) ? mb_strlen( $text ) > self::TEXT_MAX : strlen( $text ) > self::TEXT_MAX ) {
				$text  = function_exists( 'mb_substr' ) ? mb_substr( $text, 0, self::TEXT_MAX ) : substr( $text, 0, self::TEXT_MAX );
				$trunc = true;
			}
			$atts = (array) ( $r['attachments'] ?? array() );
			$type = self::type_of( (string) ( $r['content_type'] ?? 'text' ), $atts );
			$item = array(
				'msg_ref' => $conv_id . '.' . (int) $r['id'],
				'at'      => substr( (string) ( $r['created_at'] ?? '' ), 0, 16 ),
				'sender'  => array( 'label' => $label, 'role' => $role, 'key' => $keys[ $k ] ),
				'type'    => $type,
				'text'    => $text,
			);
			if ( $trunc ) {
				$item['truncated'] = true;
			}
			$file = self::file_of( $atts );
			if ( null !== $file ) {
				$item['file_name']  = $file['name'];
				$item['file_ref']   = $file['ref'];
				$item['file_state'] = null === $file['ref'] ? 'not_kept' : 'kept';
			}
			if ( ! empty( $meta['mention_detected'] ) ) {
				$item['mentions_bot'] = true;
			}
			$size = strlen( (string) json_encode( $item ) );
			if ( $items && $bytes + $size > self::PAGE_MAX_BYTES ) {
				$cut = true;
				break;
			}
			$bytes  += $size;
			$items[] = $item;
		}
		return array( 'items' => $items, 'bytes' => $bytes, 'truncated' => $cut );
	}

	/**
	 * Người gửi của MỘT dòng tin CRM: [uid nội bộ (không ra ngoài), vai trò, nhãn]. Tin `outgoing` = trợ lý.
	 *
	 * @param array<string,string> $staff uid ⇒ nhãn chủ đặt cho người được giao
	 * @return array{0:string,1:string,2:string}
	 */
	public static function sender_of( array $r, string $owner_uid, array $staff ): array {
		$meta = json_decode( (string) ( $r['ai_metadata_json'] ?? '' ), true );
		$meta = is_array( $meta ) ? $meta : array();
		if ( 'outgoing' === (string) ( $r['message_type'] ?? '' ) ) {
			return array( '__bot', 'bot', 'Trợ lý' );
		}
		$uid  = self::clean_uid( (string) ( $meta['sender_user_id'] ?? '' ) );
		$name = self::clean_label( (string) ( $meta['sender_name'] ?? '' ) );
		if ( '' !== $owner_uid && $uid === $owner_uid ) {
			return array( $uid, 'owner', 'Chủ' );
		}
		if ( '' !== $uid && isset( $staff[ $uid ] ) ) {
			return array( $uid, 'staff', '' !== $staff[ $uid ] ? $staff[ $uid ] : ( '' !== $name ? $name : 'Người được giao' ) );
		}
		return array( $uid, 'member', '' !== $name ? $name : 'Thành viên' );
	}

	/* ── W2: lưu tệp nhóm, tài liệu nhóm ───────────────────────────────── */

	/**
	 * "Nhắc lưu là lưu" (R-JN-27): chủ / người được giao ra lệnh ⇒ tệp của tin được trích dẫn (`zalo_msg_id`), hoặc mọi tệp + ảnh gửi trong
	 * `minutes` phút gần nhất (mặc định 15, ≤ 120, ≤ 10 tệp), vào sổ của chủ số. Lưu chạy nền (cron) — kết quả có mã TL- qua biên nhận của chủ.
	 *
	 * @return array|WP_Error {group_ref, queued, files[] (tên), window_minutes}
	 */
	public static function save_files( string $bridge, array $args, string $requester_uid ) {
		$g = self::resolve( $bridge, (string) ( $args['group_ref'] ?? '' ), (string) ( $args['thread_id'] ?? '' ), $requester_uid );
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$rows    = array();
		$minutes = 0;
		$quoted  = preg_replace( '/[^0-9]/', '', (string) ( $args['zalo_msg_id'] ?? '' ) );
		if ( '' !== $quoted ) {
			$row = self::call_reader( 'message_by_external', array( $g['conv_id'], 'zalo:' . $quoted ), null );
			if ( ! is_array( $row ) ) {
				return new WP_Error( 'group_message_not_found', 'Không thấy tin được trích dẫn trong nhóm này (có thể tin chưa về website).', array( 'status' => 404 ) );
			}
			$rows = array( $row );
		} else {
			$minutes = max( 1, min( self::SAVE_WINDOW_MAX, (int) ( $args['minutes'] ?? self::SAVE_WINDOW_MIN ) ) );
			$since   = gmdate( 'Y-m-d H:i:s', self::now_local() - $minutes * 60 );
			$rows    = (array) self::call_reader( 'recent_with_files', array( $g['conv_id'], $since, 50 ), array() );
		}
		$files = self::files_from_rows( $rows, $g['conv_id'], self::owner_uid( $bridge ), self::setting( $bridge, $g['gid'] )['assignees'], true );
		if ( ! $files ) {
			return new WP_Error( 'group_no_files', '' !== $quoted ? 'Tin được trích dẫn không có tệp nào.' : 'Không có tệp / ảnh nào gửi trong ' . $minutes . ' phút gần nhất của nhóm.', array( 'status' => 404 ) );
		}
		$files = array_slice( $files, -self::SAVE_MAX_FILES );
		$res   = self::capture( $bridge, $files, array( 'gid' => $g['gid'], 'conv_id' => $g['conv_id'], 'name' => $g['name'], 'trigger' => 'command' ) );
		if ( empty( $res['ok'] ) ) {
			return new WP_Error( 'group_capture_' . sanitize_key( (string) ( $res['code'] ?? 'failed' ) ), 'Website chưa nhận lưu được (' . (string) ( $res['code'] ?? 'failed' ) . ').', array( 'status' => 400 ) );
		}
		return array(
			'group_ref'      => self::group_ref( $g['conv_id'] ),
			'queued'         => count( $files ),
			'files'          => array_map( static function ( $f ) { return '' !== $f['file_name'] ? $f['file_name'] : ( 'image' === $f['kind'] ? 'ảnh' : 'tệp' ); }, $files ),
			'window_minutes' => $minutes,
		);
	}

	/**
	 * Hook `bizcity_crm_message_persisted`: nhóm đang THEO DÕI nhận tệp (tài liệu, không ảnh) ⇒ tự lưu vào sổ của chủ, im lặng.
	 * Không số nào theo dõi ⇒ thoát trước mọi truy vấn.
	 */
	public static function on_message_persisted( $p ): void {
		if ( ! is_array( $p ) || 'zalo_personal' !== (string) ( $p['adapter_code'] ?? '' ) ) {
			return;
		}
		$idx = self::following_index();
		if ( ! $idx ) {
			return;
		}
		$conv_id = (int) ( $p['conversation_id'] ?? 0 );
		$msg_id  = (int) ( $p['message_id'] ?? 0 );
		$row     = $conv_id > 0 && $msg_id > 0 ? self::conversation( $conv_id ) : null;
		if ( ! is_array( $row ) ) {
			return;
		}
		$gid    = self::gid_of( $row );
		$bridge = '' !== (string) ( $row['account_id'] ?? '' ) ? (string) $row['account_id'] : (string) ( $row['inbox_ref_id'] ?? '' );
		if ( '' === $gid || ! in_array( $gid, (array) ( $idx[ $bridge ] ?? array() ), true ) || ! self::setting( $bridge, $gid )['save_files'] ) {
			return; // B10: cột "Tự lưu tệp" tắt ⇒ chỉ lưu theo lệnh
		}
		$msgs  = (array) self::call_reader( 'messages_by_ids', array( $conv_id, array( $msg_id ) ), array() );
		$files = self::files_from_rows( $msgs, $conv_id, self::owner_uid( $bridge ), self::setting( $bridge, $gid )['assignees'], false );
		if ( $files ) {
			self::capture( $bridge, $files, array( 'gid' => $gid, 'conv_id' => $conv_id, 'name' => self::group_name( $row ), 'trigger' => 'follow' ) );
		}
	}

	/**
	 * Tài liệu đã lưu của một nhóm (dòng journal tag `grp-<gid>` trong sổ của CHỦ SỐ) — mới nhất trước.
	 *
	 * @return array|WP_Error {group_ref, name, items[{doc_code, title, kind, date, sender, state}], total}
	 */
	public static function files( string $bridge, array $args, string $requester_uid ) {
		$g = self::resolve( $bridge, (string) ( $args['group_ref'] ?? '' ), (string) ( $args['thread_id'] ?? '' ), $requester_uid );
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		// sổ của CHỦ SỐ (R-JN-27), kể cả khi người hỏi là người được giao
		$owner = (int) self::call_reader( 'owner_user', array( $bridge ), 0 );
		if ( $owner <= 0 ) {
			return new WP_Error( 'group_no_owner', 'Số này chưa gắn chủ trên website nên chưa có sổ tài liệu.', array( 'status' => 400 ) );
		}
		$filter = array( 'owner_user_id' => $owner, 'tags' => array( self::TAG_PREFIX . $g['gid'] ), 'limit' => max( 1, min( self::FILES_LIST_MAX, (int) ( $args['limit'] ?? self::FILES_LIST_MAX ) ) ), 'offset' => max( 0, (int) ( $args['offset'] ?? 0 ) ) );
		foreach ( array( 'from', 'to' ) as $k ) {
			if ( '' !== self::day( (string) ( $args[ $k ] ?? '' ) ) ) {
				$filter[ $k ] = self::day( (string) $args[ $k ] );
			}
		}
		$res = isset( self::$readers['journal_find'] ) ? call_user_func( self::$readers['journal_find'], $filter )
			: ( class_exists( 'BizCity_Journal_Index' ) ? BizCity_Journal_Index::find( $filter ) : array( 'items' => array(), 'total' => 0 ) );
		$items = array();
		foreach ( (array) ( $res['items'] ?? array() ) as $e ) {
			$grp     = is_array( $e['group'] ?? null ) ? $e['group'] : array();
			$items[] = array(
				'doc_code' => (string) ( $e['original']['doc_code'] ?? '' ),
				'title'    => (string) ( $e['title'] ?? '' ),
				'kind'     => (string) ( $e['kind'] ?? '' ),
				'date'     => substr( (string) ( $e['created_at'] ?? '' ), 0, 16 ),
				'sender'   => (string) ( $grp['sender_label'] ?? '' ),
				'state'    => (string) ( $e['state'] ?? '' ),
				'summary'  => (string) ( $e['summary'] ?? '' ),
			);
		}
		return array( 'group_ref' => self::group_ref( $g['conv_id'] ), 'name' => $g['name'], 'items' => $items, 'total' => (int) ( $res['total'] ?? count( $items ) ) );
	}

	/** Sau khi giữ được bản gốc: tệp đính kèm CRM nhớ mã TL- + dòng sổ (bản chép tin nhóm trả `file_ref`). */
	public static function mark_attachment_kept( int $att_id, string $doc_code, int $entry_id ): bool {
		if ( $att_id <= 0 || ! preg_match( '/^TL-\d+$/', $doc_code ) ) {
			return false;
		}
		$patch = array( 'doc_code' => $doc_code, 'journal_entry_id' => $entry_id );
		if ( isset( self::$readers['patch_attachment'] ) ) {
			return (bool) call_user_func( self::$readers['patch_attachment'], $att_id, $patch );
		}
		if ( ! class_exists( 'BizCity_CRM_DB_Installer_V2' ) ) {
			return false;
		}
		global $wpdb;
		$tbl  = BizCity_CRM_DB_Installer_V2::tbl_attachments();
		$raw  = $wpdb->get_var( $wpdb->prepare( "SELECT meta_json FROM {$tbl} WHERE id = %d", $att_id ) );
		if ( null === $raw ) {
			return false;
		}
		$meta = is_string( $raw ) && '' !== $raw ? json_decode( $raw, true ) : array();
		$meta = array_merge( is_array( $meta ) ? $meta : array(), $patch );
		return false !== $wpdb->update( $tbl, array( 'meta_json' => wp_json_encode( $meta, JSON_UNESCAPED_UNICODE ) ), array( 'id' => $att_id ), array( '%s' ), array( '%d' ) );
	}

	/**
	 * Tệp của các dòng tin (đã hydrate `attachments`) ⇒ danh sách cho from_group_files. Ảnh chỉ khi `$with_images` (lệnh lưu);
	 * tự lưu khi theo dõi chỉ lấy tài liệu. Tệp đã giữ (có doc_code) bị bỏ.
	 */
	public static function files_from_rows( array $rows, int $conv_id, string $owner_uid, array $assignees, bool $with_images ): array {
		$staff = array();
		foreach ( $assignees as $a ) {
			$staff[ (string) $a['uid'] ] = (string) $a['label'];
		}
		$out = array();
		foreach ( $rows as $r ) {
			list( , $role, $label ) = self::sender_of( $r, $owner_uid, $staff );
			foreach ( (array) ( $r['attachments'] ?? array() ) as $a ) {
				$type = (string) ( $a['file_type'] ?? '' );
				if ( ! in_array( $type, $with_images ? array( 'file', 'image' ) : array( 'file' ), true ) ) {
					continue;
				}
				$meta = json_decode( (string) ( $a['meta_json'] ?? '' ), true );
				$meta = is_array( $meta ) ? $meta : array();
				if ( '' !== (string) ( $meta['doc_code'] ?? '' ) ) {
					continue;
				}
				$url = (string) ( $a['data_url'] ?? '' );
				if ( ! preg_match( '#^https?://#i', $url ) || (int) ( $a['id'] ?? 0 ) <= 0 ) {
					continue;
				}
				$out[] = array(
					'kind'              => 'image' === $type ? 'image' : 'file',
					'url'               => $url,
					'file_name'         => self::clean_label( (string) ( $meta['file_name'] ?? '' ), 120 ),
					'crm_attachment_id' => (int) $a['id'],
					'crm_message_id'    => (int) ( $r['id'] ?? 0 ),
					'sender_label'      => $label,
					'sender_role'       => $role,
					'msg_ref'           => $conv_id . '.' . (int) ( $r['id'] ?? 0 ),
				);
			}
		}
		return $out;
	}

	private static function capture( string $bridge, array $files, array $group ): array {
		if ( isset( self::$readers['capture'] ) ) {
			return (array) call_user_func( self::$readers['capture'], $bridge, $files, $group );
		}
		return class_exists( 'BizCity_Zalo_Personal_Knowledge_Capture' ) && method_exists( 'BizCity_Zalo_Personal_Knowledge_Capture', 'from_group_files' )
			? BizCity_Zalo_Personal_Knowledge_Capture::from_group_files( $bridge, $files, $group )
			: array( 'ok' => false, 'code' => 'capture_unsupported' );
	}

	private static function following_index(): array {
		$v = isset( self::$readers['get_option'] ) ? call_user_func( self::$readers['get_option'], self::OPTION_FOLLOWING ) : get_option( self::OPTION_FOLLOWING, array() );
		return is_array( $v ) ? $v : array();
	}

	/** Giờ site (created_at của CRM là current_time('mysql')) */
	private static function now_local(): int {
		if ( isset( self::$readers['now'] ) ) {
			return (int) call_user_func( self::$readers['now'] );
		}
		return function_exists( 'current_time' ) ? (int) current_time( 'timestamp' ) : time();
	}

	/** Reader có seam thì gọi seam, không thì đường DB thật bên dưới (`db_<name>`). */
	private static function call_reader( string $name, array $args, $default ) {
		if ( isset( self::$readers[ $name ] ) ) {
			return call_user_func_array( self::$readers[ $name ], $args );
		}
		$m = 'db_' . $name;
		return method_exists( __CLASS__, $m ) ? call_user_func_array( array( __CLASS__, $m ), $args ) : $default;
	}

	private static function db_message_by_external( int $conv_id, string $external ): ?array {
		if ( ! class_exists( 'BizCity_CRM_DB_Installer_V2' ) || ! class_exists( 'BizCity_CRM_Repository' ) ) {
			return null;
		}
		global $wpdb;
		$tbl = BizCity_CRM_DB_Installer_V2::tbl_messages();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tbl} WHERE conversation_id = %d AND external_source_id = %s LIMIT 1", $conv_id, $external ), ARRAY_A );
		if ( ! is_array( $row ) ) {
			return null;
		}
		$h = BizCity_CRM_Repository::hydrate_messages( array( $row ) );
		return $h ? $h[0] : null;
	}

	private static function db_recent_with_files( int $conv_id, string $since, int $limit ): array {
		if ( ! class_exists( 'BizCity_CRM_DB_Installer_V2' ) || ! class_exists( 'BizCity_CRM_Repository' ) ) {
			return array();
		}
		global $wpdb;
		$tbl  = BizCity_CRM_DB_Installer_V2::tbl_messages();
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$tbl} WHERE conversation_id = %d AND created_at >= %s AND content_type IN ('file','image') ORDER BY id ASC LIMIT %d",
			$conv_id, $since, max( 1, min( 100, $limit ) )
		), ARRAY_A );
		return BizCity_CRM_Repository::hydrate_messages( is_array( $rows ) ? $rows : array() );
	}

	private static function db_owner_user( string $bridge ): int {
		return class_exists( 'BizCity_Zalo_Agent_Principals' ) ? BizCity_Zalo_Agent_Principals::owner_user_id( $bridge ) : 0;
	}

	private static function db_messages_by_ids( int $conv_id, array $ids ): array {
		return class_exists( 'BizCity_CRM_Repository' ) ? BizCity_CRM_Repository::get_messages_by_ids( $conv_id, $ids ) : array();
	}

	/* ── B10: cửa web (Channel Gateway › Nhóm của số) ─────────────────── */
	// [2026-10-19 11:11 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.124 B10 — màn ma trận đọc / ghi CÙNG option với lệnh chat (D124-12, R-AF-16).
	// Quyền do REST kiểm (quản trị site / supervisor CRM = thay chủ số; nhân sự chỉ xem nhóm mình được giao).

	/**
	 * Mọi nhóm của số (hội thoại CRM `group:<gid>`) + nhóm đã cài đặt nhưng chưa có hội thoại, kèm cài đặt.
	 *
	 * @return array<int,array{group_ref:string,thread_id:string,name:string,last_at:string,settings:array}>
	 */
	public static function web_groups( string $bridge ): array {
		$all  = self::all_settings( $bridge );
		$out  = array();
		$seen = array();
		foreach ( self::group_rows( $bridge, '', self::MAX_GROUPS ) as $row ) {
			$gid = self::gid_of( $row );
			if ( '' === $gid || isset( $seen[ $gid ] ) ) {
				continue;
			}
			$seen[ $gid ] = true;
			$out[]        = array(
				'group_ref' => self::group_ref( (int) $row['id'] ),
				'thread_id' => $gid,
				'name'      => self::group_name( $row ),
				'last_at'   => (string) ( $row['last_activity_at'] ?? $row['last_message_at'] ?? '' ),
				'settings'  => $all[ $gid ] ?? self::normalize_setting( array() ),
			);
		}
		foreach ( $all as $gid => $s ) {
			if ( ! isset( $seen[ $gid ] ) ) {
				$out[] = array( 'group_ref' => '', 'thread_id' => (string) $gid, 'name' => 'Nhóm ' . substr( (string) $gid, -6 ), 'last_at' => '', 'settings' => $s );
			}
		}
		return $out;
	}

	/**
	 * Một lần lưu từ ma trận (một ô): chạy qua CÙNG apply_command với quyền của chủ (REST đã kiểm người bấm), đóng dấu `via: web`.
	 *
	 * @param array $patch một trong {follow:bool} · {save_files:bool} · {brief_every:int} · {assignees:[{uid,label}]} · {notice:string}
	 * @return array|WP_Error cài đặt sau khi lưu
	 */
	public static function set_from_web( string $bridge, string $gid, array $patch ) {
		$owner = self::owner_uid( $bridge );
		if ( '' === $owner ) {
			return new WP_Error( 'group_owner_missing', 'Số chưa có "UID chủ tài khoản" — điền ở Cài đặt bot của số trước.', array( 'status' => 409 ) );
		}
		$web = array( 'via' => 'web' );
		$out = null;
		if ( array_key_exists( 'follow', $patch ) ) {
			$out = self::apply_command( $bridge, $gid, ! empty( $patch['follow'] ) ? 'follow_on' : 'follow_off', $web, $owner );
		} elseif ( array_key_exists( 'save_files', $patch ) ) {
			$out = self::apply_command( $bridge, $gid, ! empty( $patch['save_files'] ) ? 'save_files_on' : 'save_files_off', $web, $owner );
		} elseif ( array_key_exists( 'brief_every', $patch ) ) {
			$n = (int) $patch['brief_every'];
			if ( $n < self::BRIEF_MIN || $n > self::BRIEF_MAX ) {
				return new WP_Error( 'group_brief_every_range', 'Tóm tắt mỗi N tin: N từ ' . self::BRIEF_MIN . ' đến ' . self::BRIEF_MAX . '.', array( 'status' => 400 ) );
			}
			$out = self::apply_command( $bridge, $gid, 'brief_every', $web + array( 'n' => $n ), $owner );
		} elseif ( array_key_exists( 'notice', $patch ) ) {
			$out = self::apply_command( $bridge, $gid, 'notice', $web + array( 'text' => (string) $patch['notice'] ), $owner );
		} elseif ( array_key_exists( 'assignees', $patch ) && is_array( $patch['assignees'] ) ) {
			// đặt cả danh sách: bỏ người không còn, thêm người mới (mỗi bước là một lệnh của chủ, cùng luật trần 10 người / không giao chủ)
			$want = array();
			foreach ( $patch['assignees'] as $a ) {
				$uid = is_array( $a ) ? self::clean_uid( (string) ( $a['uid'] ?? '' ) ) : '';
				if ( '' !== $uid ) {
					$want[ $uid ] = self::clean_label( (string) ( $a['label'] ?? '' ) );
				}
			}
			if ( count( $want ) > self::MAX_ASSIGNEES ) {
				return new WP_Error( 'group_assignee_limit', 'Mỗi nhóm giao tối đa ' . self::MAX_ASSIGNEES . ' người.', array( 'status' => 400 ) );
			}
			foreach ( self::setting( $bridge, $gid )['assignees'] as $a ) {
				if ( ! isset( $want[ $a['uid'] ] ) ) {
					$r = self::apply_command( $bridge, $gid, 'unassign', $web + array( 'uid' => $a['uid'] ), $owner );
					if ( is_wp_error( $r ) ) {
						return $r;
					}
				}
			}
			foreach ( $want as $uid => $label ) {
				$r = self::apply_command( $bridge, $gid, 'assign', $web + array( 'uid' => $uid, 'label' => $label ), $owner );
				if ( is_wp_error( $r ) ) {
					return $r;
				}
			}
			$out = self::setting( $bridge, $gid );
		}
		return null === $out ? new WP_Error( 'group_patch_empty', 'Không có thay đổi nào để lưu.', array( 'status' => 400 ) ) : $out;
	}

	/**
	 * Người đã nhắn trong nhóm (để chọn "người được giao"): uid + tên gần nhất + số tin, từ ≤ 500 tin gần nhất của hội thoại CRM.
	 * Chỉ cho màn quản trị (REST đã kiểm quyền); chủ số không nằm trong danh sách.
	 *
	 * @return array<int,array{uid:string,name:string,messages:int}>
	 */
	public static function recent_senders( string $bridge, string $gid ): array {
		$rows = self::group_rows( $bridge, '', 1, $gid );
		if ( ! $rows ) {
			return array();
		}
		$owner = self::owner_uid( $bridge );
		$msgs  = self::message_rows( (int) $rows[0]['id'], array( 'limit' => 500, 'order' => 'latest' ) );
		$by    = array();
		foreach ( $msgs as $r ) {
			list( $uid, $role, $label ) = self::sender_of( $r, $owner, array() );
			if ( 'member' !== $role || '' === $uid ) {
				continue;
			}
			$by[ $uid ] = array( 'uid' => $uid, 'name' => $label, 'messages' => ( $by[ $uid ]['messages'] ?? 0 ) + 1 );
		}
		$out = array_values( $by );
		usort( $out, static function ( $a, $b ) { return $b['messages'] <=> $a['messages']; } );
		return array_slice( $out, 0, 100 );
	}

	/* ── B13: tab "Sổ nhóm" của hội thoại CRM ──────────────────────────── */
	// [2026-10-19 11:20 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.124 B13 (mockup S124-A4 chủ duyệt) — một lần đọc cho tab: sổ ghi chép · tài liệu · việc · sản phẩm.
	// Quyền đọc = quyền xem hội thoại trong CRM (REST kiểm). Sổ là của CHỦ SỐ (R-JN-27).

	/**
	 * @return array|WP_Error {group_ref, name, follow, assignees[], briefs[], files[], tasks[], products[]}
	 */
	public static function notebook( int $conv_id ) {
		$row = self::conversation( $conv_id );
		$gid = is_array( $row ) ? self::gid_of( $row ) : '';
		if ( '' === $gid ) {
			return new WP_Error( 'group_not_group', 'Hội thoại này không phải nhóm Zalo.', array( 'status' => 404 ) );
		}
		$bridge = '' !== (string) ( $row['account_id'] ?? '' ) ? (string) $row['account_id'] : (string) ( $row['inbox_ref_id'] ?? '' );
		$s      = self::setting( $bridge, $gid );
		$briefs = array();
		$tasks  = array();
		$seen   = array();
		foreach ( self::find_briefs( $bridge, $gid, '', '', '', 20 ) as $e ) {
			$c        = is_array( $e['card'] ?? null ) ? $e['card'] : array();
			$briefs[] = array( 'brief_ref' => 'GB-' . (int) ( $e['entry_id'] ?? 0 ), 'level' => (string) ( $c['level'] ?? 'chunk' ), 'period' => array( 'from' => (string) ( $c['from_at'] ?? '' ), 'to' => (string) ( $c['to_at'] ?? '' ) ), 'summary' => (string) ( $e['summary'] ?? '' ), 'card' => $c );
			if ( 'chunk' !== (string) ( $c['level'] ?? 'chunk' ) ) {
				continue;
			}
			foreach ( (array) ( $c['tasks'] ?? array() ) as $t ) {
				$ref = is_array( $t ) ? (string) ( $t['msg_ref'] ?? '' ) : '';
				if ( '' === $ref || isset( $seen[ $ref ] ) ) {
					continue;
				}
				$seen[ $ref ] = true;
				$tasks[]      = $t + array( 'recorded' => null !== self::call_reader( 'journal_by_key', array( self::task_key( $gid, $ref ) ), null ) );
			}
		}
		$owner = (int) self::call_reader( 'owner_user', array( $bridge ), 0 );
		$files = array();
		$prods = array();
		if ( $owner > 0 ) {
			$find = static function ( array $f ) {
				return isset( self::$readers['journal_find'] ) ? call_user_func( self::$readers['journal_find'], $f ) : ( class_exists( 'BizCity_Journal_Index' ) ? BizCity_Journal_Index::find( $f ) : array( 'items' => array() ) );
			};
			foreach ( (array) ( $find( array( 'owner_user_id' => $owner, 'tags' => array( self::TAG_PREFIX . $gid ), 'limit' => 40, 'offset' => 0 ) )['items'] ?? array() ) as $e ) {
				$st = (string) ( $e['source_type'] ?? '' );
				if ( 'group_capture' === $st ) {
					$files[] = array( 'doc_code' => (string) ( $e['original']['doc_code'] ?? '' ), 'title' => (string) ( $e['title'] ?? '' ), 'date' => substr( (string) ( $e['created_at'] ?? '' ), 0, 16 ), 'sender' => (string) ( $e['group']['sender_label'] ?? '' ), 'state' => (string) ( $e['state'] ?? '' ), 'entry_id' => (int) ( $e['entry_id'] ?? 0 ) );
				} elseif ( 'cell_output' === $st ) { // W4: sản phẩm làm cho nhóm (R-FILE-VERSION, tag fv-<họ> + grp-<gid>)
					$prods[] = array( 'doc_code' => (string) ( $e['original']['doc_code'] ?? '' ), 'title' => (string) ( $e['title'] ?? '' ), 'date' => substr( (string) ( $e['created_at'] ?? '' ), 0, 16 ), 'version' => (int) ( $e['card']['version'] ?? 1 ), 'family' => (string) ( $e['card']['version_family'] ?? '' ), 'note' => (string) ( $e['summary'] ?? '' ) );
				}
			}
		}
		return array(
			'group_ref' => self::group_ref( $conv_id ),
			'name'      => self::group_name( $row ),
			'follow'    => $s['follow'],
			'assignees' => array_map( static function ( $a ) { return $a['label']; }, $s['assignees'] ),
			'briefs'    => $briefs,
			'files'     => array_slice( $files, 0, 20 ),
			'tasks'     => $tasks,
			'products'  => $prods,
		);
	}

	/**
	 * "Ghi vào sổ" một việc của brief (Q124-7: người có quyền XÁC NHẬN, không tự ghi) ⇒ một dòng `task.todo` trong sổ của chủ số, tag `grp-<gid>`,
	 * khoá `gt:<blog>:<gid>:<msg_ref>` (bấm lại ⇒ cùng dòng). msg_ref phải thuộc nhóm này.
	 *
	 * @param array $t {who, what, due, msg_ref}
	 * @return array|WP_Error {entry_id, ref_code}
	 */
	public static function track_task( int $conv_id, array $t, int $by_user ) {
		$row = self::conversation( $conv_id );
		$gid = is_array( $row ) ? self::gid_of( $row ) : '';
		if ( '' === $gid ) {
			return new WP_Error( 'group_not_group', 'Hội thoại này không phải nhóm Zalo.', array( 'status' => 404 ) );
		}
		$ref = (string) ( $t['msg_ref'] ?? '' );
		if ( self::msg_id_of( $ref, $conv_id ) <= 0 ) {
			return new WP_Error( 'group_task_ref_invalid', 'Việc này không trỏ về tin của nhóm.', array( 'status' => 400 ) );
		}
		$what = self::clean_label( (string) ( $t['what'] ?? '' ), 200 );
		if ( '' === $what ) {
			return new WP_Error( 'group_task_empty', 'Thiếu nội dung việc.', array( 'status' => 400 ) );
		}
		$bridge = '' !== (string) ( $row['account_id'] ?? '' ) ? (string) $row['account_id'] : (string) ( $row['inbox_ref_id'] ?? '' );
		$owner  = (int) self::call_reader( 'owner_user', array( $bridge ), 0 );
		if ( $owner <= 0 ) {
			return new WP_Error( 'group_no_owner', 'Số này chưa gắn chủ trên website nên chưa có sổ.', array( 'status' => 400 ) );
		}
		$due   = preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) ( $t['due'] ?? '' ) ) ? (string) $t['due'] : '';
		$entry = array(
			'owner_user_id'   => $owner,
			'kind'            => 'task.todo',
			'title'           => $what,
			'summary'         => $what,
			'doc_date'        => '' !== $due ? $due : null,
			'tags'            => array( self::TAG_PREFIX . $gid ),
			'card'            => array( 'who' => self::clean_label( (string) ( $t['who'] ?? '' ) ), 'due' => $due, 'msg_ref' => $ref, 'group_ref' => self::group_ref( $conv_id ) ),
			'origin'          => array( 'surface' => 'zalo_group', 'ref' => self::group_ref( $conv_id ) ),
			'group'           => array( 'ref' => self::group_ref( $conv_id ), 'gid' => $gid, 'name' => self::group_name( $row ), 'msg_ref' => $ref ),
			'source_type'     => 'crm',
			'idempotency_key' => self::task_key( $gid, $ref ),
			'by'              => array( 'role' => 'person', 'user_id' => $by_user ),
		);
		$r = isset( self::$readers['journal_add'] ) ? call_user_func( self::$readers['journal_add'], $entry ) : ( class_exists( 'BizCity_Journal_Index' ) ? BizCity_Journal_Index::add( $entry ) : new WP_Error( 'journal_unavailable', 'Sổ chưa sẵn sàng.' ) );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		return array( 'entry_id' => (int) $r['entry_id'], 'ref_code' => 'VC-' . (int) $r['entry_id'] );
	}

	/** Người đang đăng nhập có được XÁC NHẬN ghi sổ cho nhóm này: quản trị / supervisor CRM, hoặc nhân sự là người được giao của nhóm. */
	public static function can_confirm( int $conv_id, int $user_id ): bool {
		if ( isset( self::$readers['can_confirm'] ) ) {
			return (bool) call_user_func( self::$readers['can_confirm'], $conv_id, $user_id );
		}
		if ( $user_id <= 0 ) {
			return false;
		}
		if ( class_exists( 'BizCity_CRM_Staff_Policy' ) && in_array( BizCity_CRM_Staff_Policy::role( $user_id ), array( 'admin', 'supervisor' ), true ) ) {
			return true;
		}
		if ( function_exists( 'current_user_can' ) && current_user_can( 'manage_options' ) ) {
			return true;
		}
		$row = self::conversation( $conv_id );
		$gid = is_array( $row ) ? self::gid_of( $row ) : '';
		if ( '' === $gid || ! class_exists( 'BizCity_Zalo_Agent_Principals' ) ) {
			return false;
		}
		$bridge = '' !== (string) ( $row['account_id'] ?? '' ) ? (string) $row['account_id'] : (string) ( $row['inbox_ref_id'] ?? '' );
		foreach ( BizCity_Zalo_Agent_Principals::from_policy( BizCity_Zalo_Agent_Principals::policy( $bridge ) ) as $p ) {
			if ( (int) ( $p['user_id'] ?? 0 ) === $user_id ) {
				return 2 === self::tier( $bridge, $gid, (string) ( $p['uid'] ?? '' ) );
			}
		}
		return false;
	}

	/* ── W4: từ chat (cell) ─────────────────────────────────────────────── */
	// [2026-10-19 11:45 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.124 W4 (B15/B16) — hai cửa cell gọi khi lượt ở TRONG nhóm (bộ ghi tệp của lượt chủ / nhân sự không chạy).

	/** "Ghi việc vào sổ" từ chat sau khi chủ / người được giao XÁC NHẬN (Q124-7). @return array|WP_Error */
	public static function track_from_chat( string $bridge, array $args, string $requester_uid ) {
		$g = self::resolve( $bridge, (string) ( $args['group_ref'] ?? '' ), (string) ( $args['thread_id'] ?? '' ), $requester_uid );
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		return self::track_task( $g['conv_id'], $args, 0 );
	}

	/**
	 * Một phiên bản tài liệu làm cho nhóm (R-FILE-VERSION FV-7/12/13) ⇒ một dòng sổ của chủ số: tag grp-<gid> + fv-<họ>, thẻ = họ · số bản · bản cha ·
	 * nguyên liệu (workpack) · ai yêu cầu (nhãn + bậc). Khoá `gp:<blog>:<họ>:<v>` ⇒ gửi lại không thêm dòng.
	 *
	 * @return array|WP_Error {entry_id}
	 */
	public static function record_product( string $bridge, array $args, string $requester_uid ) {
		$g = self::resolve( $bridge, (string) ( $args['group_ref'] ?? '' ), (string) ( $args['thread_id'] ?? '' ), $requester_uid );
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$family = (string) ( $args['family'] ?? '' );
		$v      = (int) ( $args['v'] ?? 0 );
		if ( ! preg_match( '/^fv_[a-f0-9]{12}$/', $family ) || $v < 1 ) {
			return new WP_Error( 'group_product_invalid', 'Thiếu họ / số bản của tài liệu.', array( 'status' => 400 ) );
		}
		$owner = (int) self::call_reader( 'owner_user', array( $bridge ), 0 );
		if ( $owner <= 0 ) {
			return new WP_Error( 'group_no_owner', 'Số này chưa gắn chủ trên website nên chưa có sổ.', array( 'status' => 400 ) );
		}
		$cov   = is_array( $args['coverage'] ?? null ) ? $args['coverage'] : array();
		$name  = self::clean_label( (string) ( $args['file_name'] ?? '' ), 200 );
		$entry = array(
			'owner_user_id'   => $owner,
			'kind'            => 'media.document.output',
			'title'           => '' !== $name ? $name : 'Tài liệu nhóm ' . $g['name'],
			'summary'         => self::clean_label( (string) ( $args['note'] ?? '' ), 600 ),
			'tags'            => array( self::TAG_PREFIX . $g['gid'], 'fv-' . substr( $family, 3 ), 'v' . $v ),
			'card'            => array(
				'version_family' => $family,
				'version'        => $v,
				'version_parent' => (int) ( $args['parent_v'] ?? 0 ),
				'version_at'     => gmdate( 'c' ),
				'workpack_hash'  => preg_replace( '/[^a-f0-9]/', '', (string) ( $args['workpack_hash'] ?? '' ) ),
				'coverage'       => array( 'msg_from' => self::clean_label( (string) ( $cov['msg_from'] ?? '' ), 32 ), 'msg_to' => self::clean_label( (string) ( $cov['msg_to'] ?? '' ), 32 ), 'has_more' => ! empty( $cov['has_more'] ) ),
				'requested_by'   => array( 'label' => self::clean_label( (string) ( $args['requested_by'] ?? '' ) ), 'tier' => 1 === $g['tier'] ? 1 : 2 ),
				'group_ref'      => self::group_ref( $g['conv_id'] ),
			),
			'original'        => array( 'file_name' => $name, 'mime' => (string) ( $args['mime'] ?? '' ), 'doc_code' => '' ),
			'origin'          => array( 'surface' => 'zalo_group', 'ref' => self::group_ref( $g['conv_id'] ) ),
			'group'           => array( 'ref' => self::group_ref( $g['conv_id'] ), 'gid' => $g['gid'], 'name' => $g['name'] ),
			'source_type'     => 'cell_output',
			'idempotency_key' => 'gp:' . self::blog() . ':' . $family . ':' . $v,
			'by'              => array( 'role' => 'cell' ),
		);
		$r = isset( self::$readers['journal_add'] ) ? call_user_func( self::$readers['journal_add'], $entry ) : ( class_exists( 'BizCity_Journal_Index' ) ? BizCity_Journal_Index::add( $entry ) : new WP_Error( 'journal_unavailable', 'Sổ chưa sẵn sàng.' ) );
		return is_wp_error( $r ) ? $r : array( 'entry_id' => (int) $r['entry_id'] );
	}

	private static function task_key( string $gid, string $msg_ref ): string {
		return 'gt:' . self::blog() . ':' . $gid . ':' . $msg_ref;
	}

	private static function db_journal_by_key( string $key ): ?array {
		return class_exists( 'BizCity_Journal_Database' ) ? BizCity_Journal_Database::instance()->get_by_idempotency_key( $key ) : null;
	}

	/* ── W3: sổ ghi chép nhóm (grp.brief) ─────────────────────────────── */
	// [2026-10-19 10:38 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.124 W3 (B11/B12) — brief do CELL viết (model nhỏ), SITE kiểm từng trích dẫn rồi mới ghi sổ;
	// cuộn ngày / tuần bằng MÃ (không model ở PHP, R-PF-2). "Brief để định vị, nguồn để khẳng định": brief không bao giờ là nguồn số liệu.

	const BRIEF_KIND      = 'grp.brief';
	const BRIEF_LEVELS    = array( 'chunk', 'day', 'week' );
	const BRIEF_ITEMS_MAX = 20;
	const BRIEF_TEXT_MAX  = 300;

	/**
	 * Ghi MỘT brief chunk (chỉ nhóm đang theo dõi; gọi từ job của cell bằng principal chủ, requester = UID chủ). Mục thiếu / sai `msg_ref`
	 * (khác nhóm, ngoài khoảng from..to) bị BỎ, không sửa hộ; số phải là chuỗi số. Rỗng sau khi lọc ⇒ không ghi.
	 *
	 * @return array|WP_Error {entry_id, dropped, kept, rolled[]}
	 */
	public static function record_brief( string $bridge, array $args, string $requester_uid ) {
		$g = self::resolve( $bridge, (string) ( $args['group_ref'] ?? '' ), (string) ( $args['thread_id'] ?? '' ), $requester_uid );
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		if ( 1 !== $g['tier'] ) {
			return new WP_Error( 'group_owner_only', 'Sổ ghi chép nhóm chỉ do trợ lý của chủ ghi.', array( 'status' => 403 ) );
		}
		if ( ! self::setting( $bridge, $g['gid'] )['follow'] ) {
			return new WP_Error( 'group_not_following', 'Nhóm chưa bật theo dõi — không ghi sổ ghi chép.', array( 'status' => 409 ) );
		}
		$from = self::msg_id_of( (string) ( $args['from_msg_ref'] ?? '' ), $g['conv_id'] );
		$to   = self::msg_id_of( (string) ( $args['to_msg_ref'] ?? '' ), $g['conv_id'] );
		if ( $from <= 0 || $to < $from ) {
			return new WP_Error( 'group_brief_range_invalid', 'Khoảng tin của brief không thuộc nhóm này hoặc bị ngược.', array( 'status' => 400 ) );
		}
		$ends = (array) self::call_reader( 'messages_by_ids', array( $g['conv_id'], array( $from, $to ) ), array() );
		$at   = array();
		foreach ( $ends as $r ) {
			$at[ (int) $r['id'] ] = (string) ( $r['created_at'] ?? '' );
		}
		if ( ! isset( $at[ $from ], $at[ $to ] ) ) {
			return new WP_Error( 'group_brief_range_invalid', 'Tin đầu / cuối của brief không có trong nhóm.', array( 'status' => 400 ) );
		}
		$card = self::clean_brief_card( $args, $g['conv_id'], $from, $to );
		if ( '' === $card['summary'] && ! $card['kept'] ) {
			return array( 'entry_id' => 0, 'dropped' => $card['dropped'], 'kept' => 0, 'rolled' => array() );
		}
		$day   = substr( $at[ $to ], 0, 10 );
		$entry = self::write_brief( $bridge, $g, 'chunk', $card, $day, $at[ $from ], $at[ $to ], 'gb:' . self::blog() . ':' . $g['gid'] . ':' . $to . ':chunk' );
		if ( is_wp_error( $entry ) ) {
			return $entry;
		}
		return array( 'entry_id' => (int) $entry['entry_id'], 'dropped' => $card['dropped'], 'kept' => $card['kept'], 'rolled' => self::roll_up_behind( $bridge, $g, $day ) );
	}

	/**
	 * Sổ ghi chép của một nhóm theo kỳ / mức (bậc 1 + 2), mới nhất trước. `latest:1` ⇒ cell lấy con trỏ `to_msg_ref` để brief tiếp.
	 *
	 * @return array|WP_Error {group_ref, name, items[{level, period, summary, card}], total}
	 */
	public static function briefs( string $bridge, array $args, string $requester_uid ) {
		$g = self::resolve( $bridge, (string) ( $args['group_ref'] ?? '' ), (string) ( $args['thread_id'] ?? '' ), $requester_uid );
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$level = in_array( (string) ( $args['level'] ?? '' ), self::BRIEF_LEVELS, true ) ? (string) $args['level'] : '';
		$rows  = self::find_briefs( $bridge, $g['gid'], $level, self::day( (string) ( $args['from'] ?? '' ) ), self::day( (string) ( $args['to'] ?? '' ) ), ! empty( $args['latest'] ) ? 1 : max( 1, min( 50, (int) ( $args['limit'] ?? 20 ) ) ) );
		$items = array();
		foreach ( $rows as $e ) {
			$c       = is_array( $e['card'] ?? null ) ? $e['card'] : array();
			$items[] = array(
				'brief_ref' => 'GB-' . (int) ( $e['entry_id'] ?? 0 ),
				'level'     => (string) ( $c['level'] ?? 'chunk' ),
				'period'    => array( 'from' => (string) ( $c['from_at'] ?? '' ), 'to' => (string) ( $c['to_at'] ?? '' ) ),
				'summary'   => (string) ( $e['summary'] ?? '' ),
				'card'      => $c,
			);
		}
		return array( 'group_ref' => self::group_ref( $g['conv_id'] ), 'name' => $g['name'], 'items' => $items, 'total' => count( $items ) );
	}

	/**
	 * Cuộn bằng mã: mọi chunk của một NGÀY ⇒ một brief ngày; mọi brief ngày của một TUẦN (thứ 2 → CN) ⇒ một brief tuần. Gộp + khử trùng theo
	 * `msg_ref`; tóm = nối tóm của con (≤ 600 ký tự). Khoá `gb:<blog>:<gid>:<ngày|tuần>:<mức>` ⇒ chạy lại không thêm dòng.
	 *
	 * @param string $level day | week · $key YYYY-MM-DD (day) hoặc thứ 2 đầu tuần (week)
	 * @return array|WP_Error|null dòng journal, hoặc null khi không có con
	 */
	public static function roll_up( string $bridge, array $g, string $level, string $key ) {
		$child = 'day' === $level ? 'chunk' : 'day';
		$from  = $key;
		$to    = 'day' === $level ? $key : gmdate( 'Y-m-d', strtotime( $key . ' +6 days' ) );
		$kids  = array_reverse( self::find_briefs( $bridge, $g['gid'], $child, $from, $to, 200 ) ); // cũ → mới
		if ( ! $kids ) {
			return null;
		}
		$merged = array( 'summary' => array(), 'decisions' => array(), 'tasks' => array(), 'numbers' => array(), 'files' => array(), 'open_questions' => array(), 'participants' => array(), 'msg_count' => 0 );
		$first  = null;
		$last   = null;
		foreach ( $kids as $e ) {
			$c = is_array( $e['card'] ?? null ) ? $e['card'] : array();
			$first = $first ?? $c;
			$last  = $c;
			if ( '' !== (string) ( $e['summary'] ?? '' ) ) {
				$merged['summary'][] = (string) $e['summary'];
			}
			foreach ( array( 'decisions', 'tasks', 'numbers', 'open_questions' ) as $k ) {
				foreach ( (array) ( $c[ $k ] ?? array() ) as $it ) {
					$ref = is_array( $it ) ? (string) ( $it['msg_ref'] ?? '' ) : '';
					if ( '' !== $ref && ! isset( $merged[ $k ][ $ref ] ) ) {
						$merged[ $k ][ $ref ] = $it;
					}
				}
			}
			$merged['files']        = array_merge( $merged['files'], (array) ( $c['files'] ?? array() ) );
			$merged['participants'] = array_merge( $merged['participants'], (array) ( $c['participants'] ?? array() ) );
			$merged['msg_count']   += (int) ( $c['msg_count'] ?? 0 );
		}
		$summary = implode( ' ', $merged['summary'] );
		$card    = array(
			'summary'        => function_exists( 'mb_substr' ) ? mb_substr( $summary, 0, 600 ) : substr( $summary, 0, 600 ),
			'from_msg_ref'   => (string) ( $first['from_msg_ref'] ?? '' ),
			'to_msg_ref'     => (string) ( $last['to_msg_ref'] ?? '' ),
			'msg_count'      => $merged['msg_count'],
			'participants'   => array_values( array_unique( $merged['participants'] ) ),
			'decisions'      => array_values( $merged['decisions'] ),
			'tasks'          => array_values( $merged['tasks'] ),
			'numbers'        => array_values( $merged['numbers'] ),
			'files'          => array_values( array_unique( $merged['files'] ) ),
			'open_questions' => array_values( $merged['open_questions'] ),
			'children'       => count( $kids ),
			'kept'           => 0,
			'dropped'        => 0,
		);
		return self::write_brief( $bridge, $g, $level, $card, $key, (string) ( $first['from_at'] ?? '' ), (string) ( $last['to_at'] ?? '' ), 'gb:' . self::blog() . ':' . $g['gid'] . ':' . $key . ':' . $level );
	}

	/** Brief đầu tiên của một ngày mới ⇒ cuộn ngày hôm trước; đầu tuần mới ⇒ cuộn tuần trước (theo sự kiện, không cron). */
	private static function roll_up_behind( string $bridge, array $g, string $day ): array {
		$rolled = array();
		$prev   = self::find_briefs( $bridge, $g['gid'], 'chunk', '', gmdate( 'Y-m-d', strtotime( $day . ' -1 day' ) ), 1 );
		$pday   = $prev ? (string) ( $prev[0]['doc_date'] ?? '' ) : '';
		if ( '' !== $pday && ! self::find_briefs( $bridge, $g['gid'], 'day', $pday, $pday, 1 ) ) {
			$r = self::roll_up( $bridge, $g, 'day', $pday );
			if ( is_array( $r ) && ! is_wp_error( $r ) ) {
				$rolled[] = 'day:' . $pday;
			}
		}
		$monday  = gmdate( 'Y-m-d', strtotime( 'monday this week', strtotime( $day ) ) );
		$pmonday = '' !== $pday ? gmdate( 'Y-m-d', strtotime( 'monday this week', strtotime( $pday ) ) ) : ''; // tuần CHỨA brief trước (nhóm có thể im nhiều tuần)
		if ( '' !== $pday && $pday < $monday && ! self::find_briefs( $bridge, $g['gid'], 'week', $pmonday, $pmonday, 1 ) ) {
			$r = self::roll_up( $bridge, $g, 'week', $pmonday );
			if ( is_array( $r ) && ! is_wp_error( $r ) ) {
				$rolled[] = 'week:' . $pmonday;
			}
		}
		return $rolled;
	}

	/**
	 * Thẻ brief sạch: chỉ mục có msg_ref của ĐÚNG nhóm và trong khoảng [from, to]; số là chuỗi số; trần 20 mục / loại, 300 ký tự / câu.
	 *
	 * @return array card + summary + kept + dropped
	 */
	public static function clean_brief_card( array $a, int $conv_id, int $from, int $to ): array {
		$kept    = 0;
		$dropped = 0;
		$inside  = static function ( $ref ) use ( $conv_id, $from, $to ): bool {
			return preg_match( '/^(\d{1,12})\.(\d{1,15})$/', (string) $ref, $m ) && (int) $m[1] === $conv_id && (int) $m[2] >= $from && (int) $m[2] <= $to;
		};
		$text = static function ( $s, int $max = self::BRIEF_TEXT_MAX ): string {
			$s = trim( preg_replace( '/[\x00-\x1F\x7F<>]+/u', ' ', (string) $s ) ?? '' );
			return function_exists( 'mb_substr' ) ? mb_substr( $s, 0, $max ) : substr( $s, 0, $max );
		};
		$list = static function ( $items, callable $shape ) use ( $inside, &$kept, &$dropped ): array {
			$out = array();
			foreach ( array_slice( is_array( $items ) ? $items : array(), 0, self::BRIEF_ITEMS_MAX ) as $it ) {
				$clean = is_array( $it ) && $inside( $it['msg_ref'] ?? '' ) ? $shape( $it ) : null;
				if ( null === $clean ) {
					$dropped++;
					continue;
				}
				$kept++;
				$out[] = $clean;
			}
			return $out;
		};
		$card = array(
			'summary'        => $text( $a['summary'] ?? '', 600 ),
			'from_msg_ref'   => $conv_id . '.' . $from,
			'to_msg_ref'     => $conv_id . '.' . $to,
			'msg_count'      => max( 0, min( 1000, (int) ( $a['msg_count'] ?? 0 ) ) ),
			'participants'   => array_slice( array_values( array_filter( array_map( $text, (array) ( $a['participants'] ?? array() ) ) ) ), 0, 30 ),
			'decisions'      => $list( $a['decisions'] ?? array(), static function ( $it ) use ( $text ) {
				$t = $text( $it['text'] ?? '' );
				return '' === $t ? null : array( 'text' => $t, 'msg_ref' => (string) $it['msg_ref'] );
			} ),
			'tasks'          => $list( $a['tasks'] ?? array(), static function ( $it ) use ( $text ) {
				$what = $text( $it['what'] ?? '' );
				$due  = preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) ( $it['due'] ?? '' ) ) ? (string) $it['due'] : '';
				return '' === $what ? null : array( 'who' => $text( $it['who'] ?? '', 60 ), 'what' => $what, 'due' => $due, 'msg_ref' => (string) $it['msg_ref'] );
			} ),
			'numbers'        => $list( $a['numbers'] ?? array(), static function ( $it ) use ( $text ) {
				$v = str_replace( array( ' ', ',' ), '', (string) ( $it['value'] ?? '' ) );
				return preg_match( '/^-?\d{1,15}(\.\d{1,4})?$/', $v ) ? array( 'label' => $text( $it['label'] ?? '', 80 ), 'value' => $v, 'unit' => $text( $it['unit'] ?? '', 10 ), 'msg_ref' => (string) $it['msg_ref'] ) : null;
			} ),
			'open_questions' => $list( $a['open_questions'] ?? array(), static function ( $it ) use ( $text ) {
				$t = $text( $it['text'] ?? '' );
				return '' === $t ? null : array( 'text' => $t, 'msg_ref' => (string) $it['msg_ref'] );
			} ),
			'files'          => array_slice( array_values( array_filter( (array) ( $a['files'] ?? array() ), static function ( $c ) { return (bool) preg_match( '/^TL-\d+$/', (string) $c ); } ) ), 0, self::BRIEF_ITEMS_MAX ),
		);
		$card['kept']    = $kept;
		$card['dropped'] = $dropped;
		return $card;
	}

	private static function write_brief( string $bridge, array $g, string $level, array $card, string $day, string $from_at, string $to_at, string $key ) {
		$owner = (int) self::call_reader( 'owner_user', array( $bridge ), 0 );
		if ( $owner <= 0 ) {
			return new WP_Error( 'group_no_owner', 'Số này chưa gắn chủ trên website nên chưa có sổ.', array( 'status' => 400 ) );
		}
		$names = array( 'chunk' => 'Ghi chép', 'day' => 'Ngày', 'week' => 'Tuần' );
		$card  = array_merge( $card, array( 'level' => $level, 'group_ref' => self::group_ref( $g['conv_id'] ), 'from_at' => substr( $from_at, 0, 16 ), 'to_at' => substr( $to_at, 0, 16 ) ) );
		$entry = array(
			'owner_user_id'   => $owner,
			'kind'            => self::BRIEF_KIND,
			'title'           => $names[ $level ] . ' nhóm ' . $g['name'] . ' · ' . ( 'week' === $level ? 'tuần từ ' . $day : $card['from_at'] . ( 'chunk' === $level ? '–' . substr( $card['to_at'], 11, 5 ) : '' ) ),
			'summary'         => (string) $card['summary'],
			'doc_date'        => $day,
			'tags'            => array( self::TAG_PREFIX . $g['gid'], 'brief-' . $level ),
			'card'            => $card,
			'confidence'      => 1.0,
			'origin'          => array( 'surface' => 'zalo_group', 'ref' => self::group_ref( $g['conv_id'] ) ),
			'group'           => array( 'ref' => self::group_ref( $g['conv_id'] ), 'gid' => $g['gid'], 'name' => $g['name'] ),
			'source_type'     => 'group_brief',
			'idempotency_key' => $key,
			'by'              => array( 'role' => 'cell' ),
		);
		if ( isset( self::$readers['journal_add'] ) ) {
			return call_user_func( self::$readers['journal_add'], $entry );
		}
		return class_exists( 'BizCity_Journal_Index' ) ? BizCity_Journal_Index::add( $entry ) : new WP_Error( 'journal_unavailable', 'Sổ chưa sẵn sàng.' );
	}

	/** @return array<int,array> journal-entry@1 rows (mới nhất trước) */
	private static function find_briefs( string $bridge, string $gid, string $level, string $from, string $to, int $limit ): array {
		$owner = (int) self::call_reader( 'owner_user', array( $bridge ), 0 );
		if ( $owner <= 0 ) {
			return array();
		}
		$filter = array( 'owner_user_id' => $owner, 'kind' => self::BRIEF_KIND, 'tags' => array_values( array_filter( array( self::TAG_PREFIX . $gid, '' !== $level ? 'brief-' . $level : '' ) ) ), 'limit' => $limit, 'offset' => 0 );
		if ( '' !== $from ) {
			$filter['from'] = $from;
		}
		if ( '' !== $to ) {
			$filter['to'] = $to;
		}
		$res = isset( self::$readers['journal_find'] ) ? call_user_func( self::$readers['journal_find'], $filter )
			: ( class_exists( 'BizCity_Journal_Index' ) ? BizCity_Journal_Index::find( $filter ) : array( 'items' => array() ) );
		return (array) ( $res['items'] ?? array() );
	}

	private static function blog(): int {
		return function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
	}

	/**
	 * [2026-10-20 06:01 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.124 G0 (B12) — dòng của gói `group_index` (group-index@1): mỗi nhóm ĐANG THEO DÕI hoặc CÓ NGƯỜI ĐƯỢC GIAO của số,
	 * mới hoạt động trước, ≤ `$max` nhóm: tên, NH-, gid, lần cuối có tin, tệp gần đây (mã TL-, tên, ngày), brief mới nhất (≤ 3 dòng +
	 * số tin đã tóm). KHÔNG UID, không nội dung tin, không tên người gửi (nhãn người được giao do cài đặt đã có ở owner_agent.groups).
	 * Cắt ≤ `$max_bytes` mỗi nhóm (bỏ tệp trước, rồi brief).
	 *
	 * @return array<int,array>
	 */
	public static function index_rows( string $bridge, int $max = 30, int $max_bytes = 4096 ): array {
		$all = self::all_settings( $bridge );
		$on  = array();
		foreach ( $all as $gid => $s ) {
			if ( $s['follow'] || array() !== $s['assignees'] ) {
				$on[ (string) $gid ] = $s;
			}
		}
		if ( array() === $on ) {
			return array();
		}
		$rows = array();
		foreach ( self::group_rows( $bridge, '', self::MAX_GROUPS ) as $row ) {
			$gid = self::gid_of( $row );
			if ( '' !== $gid && isset( $on[ $gid ] ) && ! isset( $rows[ $gid ] ) ) {
				$rows[ $gid ] = $row;
			}
		}
		$owner = (int) self::call_reader( 'owner_user', array( $bridge ), 0 );
		$find  = static function ( array $f ) {
			return isset( self::$readers['journal_find'] ) ? call_user_func( self::$readers['journal_find'], $f ) : ( class_exists( 'BizCity_Journal_Index' ) ? BizCity_Journal_Index::find( $f ) : array( 'items' => array() ) );
		};
		$out = array();
		foreach ( $on as $gid => $s ) {
			$row  = $rows[ $gid ] ?? null;
			$item = array(
				'row'     => 'group',
				'ref'     => is_array( $row ) ? self::group_ref( (int) $row['id'] ) : '',
				'gid'     => (string) $gid,
				'name'    => is_array( $row ) ? self::group_name( $row ) : 'Nhóm ' . substr( (string) $gid, -6 ),
				'last_at' => is_array( $row ) ? (string) ( $row['last_activity_at'] ?? $row['last_message_at'] ?? '' ) : '',
				'follow'  => (bool) $s['follow'],
				'files'   => array(),
			);
			if ( $owner > 0 ) {
				foreach ( (array) ( $find( array( 'owner_user_id' => $owner, 'tags' => array( self::TAG_PREFIX . $gid ), 'limit' => 12, 'offset' => 0 ) )['items'] ?? array() ) as $e ) {
					$code = (string) ( $e['original']['doc_code'] ?? '' );
					if ( '' === $code || ! in_array( (string) ( $e['source_type'] ?? '' ), array( 'group_capture', 'cell_output' ), true ) || count( $item['files'] ) >= 5 ) {
						continue;
					}
					$item['files'][] = array( 'code' => $code, 'title' => self::cut( (string) ( $e['title'] ?? '' ), 80 ), 'date' => substr( (string) ( $e['created_at'] ?? '' ), 0, 10 ) );
				}
				$b = self::find_briefs( $bridge, (string) $gid, '', '', '', 1 );
				if ( array() !== $b ) {
					$c     = is_array( $b[0]['card'] ?? null ) ? $b[0]['card'] : array();
					$lines = array_values( array_filter( array_map( 'trim', preg_split( '/(?<=[.!?…])\s+|\n+/u', (string) ( $b[0]['summary'] ?? '' ) ) ?: array() ), 'strlen' ) );
					$item['brief'] = array(
						'ref'       => 'GB-' . (int) ( $b[0]['entry_id'] ?? 0 ),
						'level'     => (string) ( $c['level'] ?? 'chunk' ),
						'to'        => (string) ( $c['to_at'] ?? '' ),
						'msg_count' => (int) ( $c['msg_count'] ?? 0 ),
						'lines'     => array_map( static function ( $l ) { return self::cut( $l, 200 ); }, array_slice( $lines, 0, 3 ) ),
					);
				}
			}
			while ( strlen( (string) wp_json_encode( $item ) ) > $max_bytes ) {
				if ( array() !== $item['files'] ) {
					array_pop( $item['files'] );
				} elseif ( ! empty( $item['brief']['lines'] ) ) {
					array_pop( $item['brief']['lines'] );
				} else {
					unset( $item['brief'] );
					break;
				}
			}
			$out[] = $item;
		}
		usort( $out, static function ( $a, $b ) { return strcmp( $b['last_at'], $a['last_at'] ); } );
		return array_slice( $out, 0, max( 1, $max ) );
	}

	private static function cut( string $s, int $max ): string {
		return function_exists( 'mb_substr' ) ? mb_substr( $s, 0, $max ) : substr( $s, 0, $max );
	}

	/** owner_agent.groups[] cho cell — chỉ nhóm có cài đặt khác mặc định. UID của người được giao đi xuống cell (cell đã có UID của họ trong tin). */
	public static function bundle( string $bridge ): array {
		$out = array();
		foreach ( self::all_settings( $bridge ) as $gid => $s ) {
			$row = array( 'gid' => (string) $gid, 'follow' => $s['follow'], 'assignees' => $s['assignees'], 'brief_every' => $s['brief_every'] );
			if ( '' !== $s['notice'] ) {
				$row['notice'] = $s['notice']; // Q124-13: chỉ gửi khi chủ đã sửa (bundle cũ không đổi)
			}
			if ( ! $s['save_files'] ) {
				$row['save_files'] = false;
			}
			$out[] = $row;
		}
		return $out;
	}

	/* ── đọc DB (thay được bằng $readers) ──────────────────────────────── */

	private static function group_rows( string $bridge, string $q, int $limit, string $gid = '' ): array {
		if ( isset( self::$readers['groups'] ) ) {
			return (array) call_user_func( self::$readers['groups'], $bridge, $q, $limit, $gid );
		}
		if ( ! class_exists( 'BizCity_CRM_Repository' ) ) {
			return array();
		}
		$args = array( 'thread_kind' => 'group', 'account_id' => $bridge, 'limit' => $limit );
		if ( '' !== $gid ) {
			$args['external_uid'] = 'group:' . $gid;
		}
		if ( '' !== trim( $q ) ) {
			$args['q'] = substr( trim( $q ), 0, 80 );
		}
		return BizCity_CRM_Repository::list_conversations( $args );
	}

	private static function conversation( int $conv_id ): ?array {
		if ( isset( self::$readers['conversation'] ) ) {
			$r = call_user_func( self::$readers['conversation'], $conv_id );
			return is_array( $r ) ? $r : null;
		}
		if ( ! class_exists( 'BizCity_CRM_DB_Installer_V2' ) ) {
			return null;
		}
		// Cùng cột với list_conversations (source_id + inbox_ref_id — get_conversation không có hai cột này).
		global $wpdb;
		$c   = BizCity_CRM_DB_Installer_V2::tbl_conversations();
		$ci  = BizCity_CRM_DB_Installer_V2::tbl_contact_inboxes();
		$ibx = BizCity_CRM_DB_Installer_V2::tbl_inboxes();
		$ct  = BizCity_CRM_DB_Installer_V2::tbl_contacts();
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT c.id, c.account_id, c.last_activity_at, i.channel_ref_id AS inbox_ref_id, ci.source_id,
				ct.name AS contact_name, ct.additional_attributes AS contact_attributes
			 FROM {$c} c
			 LEFT JOIN {$ci} ci ON ci.id = c.contact_inbox_id
			 LEFT JOIN {$ibx} i ON i.id = c.inbox_id
			 LEFT JOIN {$ct} ct ON ct.id = ci.contact_id
			 WHERE c.id = %d",
			$conv_id
		), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/** @param array{limit:int, order:string, after_id?:int, from?:string, to?:string} $q */
	private static function message_rows( int $conv_id, array $q ): array {
		if ( isset( self::$readers['messages'] ) ) {
			return (array) call_user_func( self::$readers['messages'], $conv_id, $q );
		}
		if ( ! class_exists( 'BizCity_CRM_DB_Installer_V2' ) || ! class_exists( 'BizCity_CRM_Repository' ) ) {
			return array();
		}
		global $wpdb;
		$tbl    = BizCity_CRM_DB_Installer_V2::tbl_messages();
		$where  = array( 'conversation_id = %d' );
		$params = array( $conv_id );
		if ( ! empty( $q['after_id'] ) ) {
			$where[]  = 'id > %d';
			$params[] = (int) $q['after_id'];
		}
		if ( ! empty( $q['from'] ) ) {
			$where[]  = 'created_at >= %s';
			$params[] = (string) $q['from'];
		}
		if ( ! empty( $q['to'] ) ) {
			$where[]  = 'created_at < %s';
			$params[] = (string) $q['to'];
		}
		$latest   = 'latest' === $q['order'];
		$params[] = (int) $q['limit'];
		$rows     = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$tbl} WHERE " . implode( ' AND ', $where ) . ' ORDER BY id ' . ( $latest ? 'DESC' : 'ASC' ) . ' LIMIT %d',
			$params
		), ARRAY_A );
		$rows = is_array( $rows ) ? $rows : array();
		return BizCity_CRM_Repository::hydrate_messages( $latest ? array_reverse( $rows ) : $rows );
	}

	/* ── nhỏ ───────────────────────────────────────────────────────────── */

	private static function msg_id_of( string $msg_ref, int $conv_id ): int {
		return preg_match( '/^(\d{1,12})\.(\d{1,15})$/', trim( $msg_ref ), $m ) && (int) $m[1] === $conv_id ? (int) $m[2] : 0;
	}

	private static function day( string $d ): string {
		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', trim( $d ) ) ? trim( $d ) : '';
	}

	private static function type_of( string $content_type, array $atts ): string {
		foreach ( $atts as $a ) {
			$t = (string) ( $a['file_type'] ?? '' );
			if ( 'image' === $t ) {
				return 'image';
			}
			if ( 'audio' === $t ) {
				return 'voice';
			}
			return 'file';
		}
		return in_array( $content_type, array( 'sticker', 'image', 'file' ), true ) ? $content_type : 'text';
	}

	/** Tệp của tin: tên + mã TL- nếu đã về kho của site (W2 ghi `journal_entry_id` vào meta). */
	private static function file_of( array $atts ): ?array {
		foreach ( $atts as $a ) {
			if ( 'image' === (string) ( $a['file_type'] ?? '' ) ) {
				continue;
			}
			$meta = json_decode( (string) ( $a['meta_json'] ?? '' ), true );
			$meta = is_array( $meta ) ? $meta : array();
			$name = self::clean_label( (string) ( $meta['file_name'] ?? $meta['name'] ?? 'tệp' ), 120 );
			$code = (string) ( $meta['doc_code'] ?? '' ); // W2: mark_attachment_kept ghi mã TL- của bản gốc đã giữ
			return array( 'name' => '' !== $name ? $name : 'tệp', 'ref' => preg_match( '/^TL-\d+$/', $code ) ? $code : null );
		}
		return null;
	}

	private static function group_name( array $row ): string {
		$attrs = json_decode( (string) ( $row['contact_attributes'] ?? '' ), true );
		$name  = is_array( $attrs ) ? (string) ( $attrs['group_name'] ?? '' ) : '';
		return self::clean_label( '' !== $name ? $name : (string) ( $row['contact_name'] ?? '' ), 120 );
	}

	public static function clean_uid( string $uid ): string {
		$uid = trim( $uid );
		return preg_match( '/^[0-9]{3,30}$/', $uid ) ? $uid : '';
	}

	public static function clean_gid( string $gid ): string {
		$gid = trim( $gid );
		return preg_match( '/^[0-9]{3,30}$/', $gid ) ? $gid : '';
	}

	private static function clean_label( string $s, int $max = 60 ): string {
		$s = trim( preg_replace( '/[\x00-\x1F\x7F<>]+/u', ' ', $s ) ?? '' );
		return function_exists( 'mb_substr' ) ? mb_substr( $s, 0, $max ) : substr( $s, 0, $max );
	}

	private static function not_found(): WP_Error {
		return new WP_Error( 'group_not_found', 'Không thấy nhóm này trong các nhóm bạn được xem.', array( 'status' => 404 ) );
	}
}
