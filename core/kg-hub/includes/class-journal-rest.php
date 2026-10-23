<?php
/**
 * BizCity_Journal_REST — `bizcity-knowledge/v2/journal` (PHASE-0.100 S100-L22, doc 92 §9, R-JOURNAL R-JN-5).
 *
 * Same handlers as the MCP tools (`BizCity_Journal_MCP_Tools::find/get/summarize`) — one logic, two doors — read
 * only, `is_user_logged_in()` gate, owner = the logged-in WordPress user (REST never acts as a delegated principal).
 *
 * Biz Central Brain — Johnny Chu (Chu Hoàng Anh).
 *
 * [2026-10-12 12:05 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.100 S100-L22 — new file.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\KG_Hub
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Journal_REST', false ) ) {
	return;
}

final class BizCity_Journal_REST {

	const NS = 'bizcity-knowledge/v2';

	private static $hooked = false;

	public static function init(): void {
		if ( self::$hooked ) {
			return;
		}
		self::$hooked = true;
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes(): void {
		register_rest_route( self::NS, '/journal/find', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'find' ),
			'permission_callback' => array( __CLASS__, 'permission' ),
		) );
		register_rest_route( self::NS, '/journal/(?P<id>\d+)', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get' ),
				'permission_callback' => array( __CLASS__, 'permission' ),
			),
			// [2026-10-17 09:35 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.121 B4-2 (S121-R6, doc 70 §6) — a PERSON edits labels / verifies on the web (never MCP).
			array(
				'methods'             => 'PATCH, POST',
				'callback'            => array( __CLASS__, 'patch' ),
				'permission_callback' => array( __CLASS__, 'permission' ),
			),
		) );
		// [2026-10-17 09:35 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.121 B4-2 (S121-R6, doc 70 §6) — the four web screens read these (bizcity-twin-crm apps/documents).
		register_rest_route( self::NS, '/documents/overview', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'overview' ),
			'permission_callback' => array( __CLASS__, 'permission' ),
		) );
		// [2026-10-17 10:10 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.121 B4-1 (S121-R6, doc 70 §6) — "Học kỹ" / "Ngừng học sâu" from the web = the same handler as MCP document.learn.set (I-7, L3 refused).
		register_rest_route( self::NS, '/journal/(?P<id>\d+)/learn', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'learn' ),
			'permission_callback' => array( __CLASS__, 'permission' ),
		) );
		// [2026-10-17 11:10 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.121 Q121-8 web — what staff sent into the numbers I own (read-only).
		register_rest_route( self::NS, '/journal/team', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'team' ),
			'permission_callback' => array( __CLASS__, 'permission' ),
		) );
		// [2026-10-19 09:18 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.121 S121-R6 (doc 70 §6) — "Hợp đồng" over every contract of the person (≤ scan_max), not the first page of find.
		register_rest_route( self::NS, '/documents/contracts', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'contracts' ),
			'permission_callback' => array( __CLASS__, 'permission' ),
		) );
		// [2026-10-19 10:40 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.121 R-CONTACT-ID — the documents that belong to the contact / Zalo group of ONE CRM conversation (inbox drawer + group view).
		register_rest_route( self::NS, '/documents/for-conversation', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'for_conversation' ),
			'permission_callback' => array( __CLASS__, 'permission' ),
		) );
		register_rest_route( self::NS, '/journal/export', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'export_csv' ),
			'permission_callback' => array( __CLASS__, 'permission' ),
		) );
		register_rest_route( self::NS, '/journal/summarize', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'summarize' ),
			'permission_callback' => array( __CLASS__, 'permission' ),
		) );
		// [2026-10-20 10:53 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.126 S126-U3 (doc 20 §5, R-FILE-VERSION) — every version row of one document (root_ref = the first version's code), oldest first.
		register_rest_route( self::NS, '/journal/versions', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'versions' ),
			'permission_callback' => array( __CLASS__, 'permission' ),
		) );
	}

	/** Pure: the version chain of `$root_ref` from a page of entries (rows whose version.root_ref or own code equals it), by version.n then id. */
	public static function versions_from_entries( array $entries, string $root_ref ): array {
		$root = strtoupper( trim( $root_ref ) );
		$keep = array();
		foreach ( $entries as $e ) {
			if ( 'void' === (string) ( $e['state'] ?? '' ) ) {
				continue;
			}
			$own = (string) ( $e['ref_code'] ?? '' );
			$vr  = (string) ( $e['version']['root_ref'] ?? '' );
			if ( $root === $own || ( '' !== $vr && $root === $vr ) ) {
				$keep[] = $e;
			}
		}
		usort( $keep, static function ( $a, $b ) {
			return ( (int) ( $a['version']['n'] ?? 1 ) <=> (int) ( $b['version']['n'] ?? 1 ) ) ?: ( (int) ( $a['entry_id'] ?? 0 ) <=> (int) ( $b['entry_id'] ?? 0 ) );
		} );
		$of = count( $keep );
		foreach ( $keep as $i => $e ) {
			$keep[ $i ]['version']['of'] = $of;
		}
		return $keep;
	}

	public static function versions( WP_REST_Request $request ): WP_REST_Response {
		if ( ! class_exists( 'BizCity_Journal_Database' ) || ! class_exists( 'BizCity_Journal_Index' ) ) {
			return new WP_REST_Response( array( 'code' => 'journal_unavailable', 'message' => 'Chỉ mục nhật ký chưa sẵn sàng trên site này.', 'hint' => '', 'help_code' => 'S126-JN-REST-1' ), 503 );
		}
		$root = strtoupper( trim( (string) $request->get_param( 'root_ref' ) ) );
		if ( 1 !== preg_match( '/^[A-Z]{2}-\d{1,12}$/', $root ) ) {
			return new WP_REST_Response( array( 'code' => 'field_type_invalid', 'message' => 'root_ref phải là mã dòng (vd TL-47).', 'hint' => '', 'help_code' => 'S126-JN-REST-400' ), 400 );
		}
		$owner   = (int) get_current_user_id();
		$entries = array_map( array( 'BizCity_Journal_Index', 'to_journal_entry' ), BizCity_Journal_Database::instance()->register_rows( $owner, 500 ) );
		$items   = self::versions_from_entries( $entries, $root );
		$brief   = array();
		foreach ( $items as $e ) {
			$brief[] = self::brief_item( $e ) + array( 'version' => $e['version'] );
		}
		return new WP_REST_Response( array( 'root_ref' => $root, 'items' => $brief, 'of' => count( $items ) ), 200 );
	}

	public static function permission() {
		return is_user_logged_in();
	}

	public static function find( WP_REST_Request $request ): WP_REST_Response {
		if ( ! class_exists( 'BizCity_Journal_MCP_Tools' ) ) {
			return new WP_REST_Response( array( 'code' => 'journal_unavailable', 'message' => 'Chỉ mục nhật ký chưa sẵn sàng trên site này.', 'hint' => '', 'help_code' => 'S100-JN-REST-1' ), 503 );
		}
		$args = self::read_params( $request, array( 'kind', 'kinds', 'kind_prefix', 'tags', 'subject_hash', 'from', 'to', 'q', 'limit', 'offset', 'status', 'has_file', 'job', 'layer', 'context_scope' ) ); // [2026-10-24 12:40 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.131 S131-L3 — + context_scope (context-scope@1), one handler two doors // [2026-10-20 10:53 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.126 S126-J5 — + job (JB-…), layer
		$args = self::scope_filter( $args, (int) $request->get_param( 'contact_id' ), (string) $request->get_param( 'group' ) ); // [2026-10-19 10:40 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.121 R-CONTACT-ID
		$res  = BizCity_Journal_MCP_Tools::find( $args, self::ctx() );
		return self::respond( $res );
	}

	/**
	 * [2026-10-19 10:40 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.121 R-CONTACT-ID — pure: "documents of THIS contact / THIS Zalo group" as the two index filters the journal already has
	 * (`subject_hash` column, `grp-<gid>` tag). Ids are numbers; anything else is ignored (never a free-form value into the filter).
	 */
	public static function scope_filter( array $args, int $contact_id, string $gid ): array {
		if ( $contact_id > 0 ) {
			$args['subject_hash'] = BizCity_Journal_Index::contact_subject_hash( $contact_id );
		}
		$tag = BizCity_Journal_Index::group_tag( trim( $gid ) );
		if ( '' !== $tag ) {
			$tags = array_values( array_filter( array_map( 'strval', (array) ( $args['tags'] ?? array() ) ) ) );
			$tags[] = $tag;
			$args['tags'] = array_values( array_unique( $tags ) );
		}
		return $args;
	}

	public static function get( WP_REST_Request $request ): WP_REST_Response {
		if ( ! class_exists( 'BizCity_Journal_MCP_Tools' ) ) {
			return new WP_REST_Response( array( 'code' => 'journal_unavailable', 'message' => 'Chỉ mục nhật ký chưa sẵn sàng trên site này.', 'hint' => '', 'help_code' => 'S100-JN-REST-1' ), 503 );
		}
		$res = BizCity_Journal_MCP_Tools::get( array( 'entry_id' => (int) $request->get_param( 'id' ) ), self::ctx() );
		if ( is_wp_error( $res ) || ! is_array( $res ) || isset( $res['code'] ) ) {
			$team = self::team_entries( (int) get_current_user_id(), (int) $request->get_param( 'id' ) ); // [2026-10-17 11:10 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.121 Q121-8 web
			if ( array() !== $team ) {
				return new WP_REST_Response( $team[0], 200 );
			}
		}
		return self::respond( $res );
	}

	public static function summarize( WP_REST_Request $request ): WP_REST_Response {
		if ( ! class_exists( 'BizCity_Journal_MCP_Tools' ) ) {
			return new WP_REST_Response( array( 'code' => 'journal_unavailable', 'message' => 'Chỉ mục nhật ký chưa sẵn sàng trên site này.', 'hint' => '', 'help_code' => 'S100-JN-REST-1' ), 503 );
		}
		$args = self::read_params( $request, array( 'kind', 'kinds', 'subject_hash', 'from', 'to', 'field', 'group_by' ) );
		$res  = BizCity_Journal_MCP_Tools::summarize( $args, self::ctx() );
		return self::respond( $res );
	}

	// ── [2026-10-17 09:35 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.121 B4-2 (S121-R6, doc 70 §6) ──────────────────────────────────────

	/** Keys a person may patch from the web. [2026-10-20 10:53 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.126 S126-P3/U3 — + customer (off · view · download), delivered_to / undeliver, checkpoint_label. */
	const PATCH_KEYS = array( 'kind', 'tags', 'doc_date', 'pinned', 'review', 'summary', 'card', 'contact_id', 'customer', 'delivered_to', 'undeliver', 'checkpoint_label' );

	/**
	 * Pure: split a web patch into the file() patch and the state change. `verified:true` ⇒ state verified (by this person);
	 * `verified:false` ⇒ back to captured; `state:void` ⇒ void ("xoá khỏi sổ", row kept). Anything else is ignored.
	 *
	 * @return array{patch:array,state:string}
	 */
	public static function split_patch( array $body ): array {
		$patch = array_intersect_key( $body, array_flip( self::PATCH_KEYS ) );
		if ( isset( $patch['card'] ) ) { // scalar card fields only (e.g. a contract end_date typed on the web)
			$patch['card'] = is_array( $patch['card'] ) ? array_map( 'strval', array_filter( $patch['card'], 'is_scalar' ) ) : array();
		}
		$state = '';
		if ( array_key_exists( 'verified', $body ) ) {
			$state = ! empty( $body['verified'] ) ? 'verified' : 'captured';
		}
		if ( 'void' === (string) ( $body['state'] ?? '' ) ) {
			$state = 'void';
		}
		// [2026-10-19 09:18 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.121 S121-R6 / PHASE-0.123 doc 20 §6 — the rev the editor saw.
		if ( (int) ( $body['expected_rev'] ?? 0 ) > 0 ) {
			$patch['expected_revision'] = (int) $body['expected_rev'];
		}
		return array( 'patch' => $patch, 'state' => $state );
	}

	/**
	 * [2026-10-19 09:18 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.121 S121-R6 / PHASE-0.123 doc 20 §2 — pure: check a web patch against doc-register@1
	 * for a row of `$kind`. Card keys outside views.detail.editable_card[kind] ⇒ field_not_editable; a date that is not YYYY-MM-DD
	 * (or '' to clear) ⇒ field_type_invalid. `$editable_card` comes from BizCity_Journal_Index::register_editable_card().
	 *
	 * @return array{code:string,message:string,hint:string}|null null = acceptable
	 */
	public static function validate_patch( array $patch, array $editable_card ): ?array {
		$date_ok = static function ( $v ): bool {
			$v = (string) $v;
			if ( '' === $v ) {
				return true;
			}
			return 1 === preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m ) && checkdate( (int) $m[2], (int) $m[3], (int) $m[1] );
		};
		foreach ( array_keys( (array) ( $patch['card'] ?? array() ) ) as $k ) {
			if ( ! in_array( (string) $k, $editable_card, true ) ) {
				return array( 'code' => 'field_not_editable', 'message' => 'Trường "' . sanitize_key( (string) $k ) . '" không sửa được ở sổ tài liệu.', 'hint' => 'Chỉ sửa nhãn, ngày, tóm tắt và ngày hết hạn hợp đồng.' );
			}
		}
		$dates = array();
		if ( array_key_exists( 'doc_date', $patch ) ) {
			$dates['doc_date'] = $patch['doc_date'];
		}
		foreach ( (array) ( $patch['card'] ?? array() ) as $k => $v ) {
			if ( '_date' === substr( (string) $k, -5 ) || 'date' === (string) $k ) {
				$dates[ (string) $k ] = $v;
			}
		}
		foreach ( $dates as $k => $v ) {
			if ( ! is_scalar( $v ) || ! $date_ok( $v ) ) {
				return array( 'code' => 'field_type_invalid', 'message' => 'Ngày "' . sanitize_key( (string) $k ) . '" không hợp lệ.', 'hint' => 'Nhập ngày dạng năm-tháng-ngày, ví dụ 2026-12-31.' );
			}
		}
		if ( array_key_exists( 'contact_id', $patch ) && ( ! is_numeric( $patch['contact_id'] ) || (int) $patch['contact_id'] < 0 || (int) $patch['contact_id'] != $patch['contact_id'] ) ) {
			return array( 'code' => 'field_type_invalid', 'message' => 'Liên hệ không hợp lệ.', 'hint' => 'Chọn một liên hệ trong danh sách, hoặc bỏ liên kết.' );
		}
		if ( array_key_exists( 'summary', $patch ) && mb_strlen( (string) $patch['summary'] ) > 2000 ) {
			return array( 'code' => 'field_type_invalid', 'message' => 'Tóm tắt dài quá 2 000 ký tự.', 'hint' => 'Rút gọn tóm tắt.' );
		}
		if ( array_key_exists( 'tags', $patch ) && count( (array) $patch['tags'] ) > 12 ) {
			return array( 'code' => 'field_type_invalid', 'message' => 'Tối đa 12 nhãn.', 'hint' => 'Bớt nhãn.' );
		}
		// [2026-10-20 10:53 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.126 S126-P3 (brain-access@1 row switch) — three steps only; refs are h:/p: + 16 hex (R-CONTACT-ID), never a phone / uid.
		if ( array_key_exists( 'customer', $patch ) && ! in_array( (string) $patch['customer'], array( 'off', 'view', 'download' ), true ) ) {
			return array( 'code' => 'field_type_invalid', 'message' => 'Công tắc khách chỉ nhận off · view · download.', 'hint' => 'Tắt · Cho khách xem · Cho khách tải.' );
		}
		foreach ( array( 'delivered_to', 'undeliver' ) as $k ) {
			if ( array_key_exists( $k, $patch ) ) {
				foreach ( (array) $patch[ $k ] as $r ) {
					if ( ! is_string( $r ) || 1 !== preg_match( '/^[hp]:[0-9a-f]{16}$/', $r ) ) {
						return array( 'code' => 'field_type_invalid', 'message' => 'Người nhận không hợp lệ.', 'hint' => 'Chọn người nhận trong danh sách liên hệ.' );
					}
				}
			}
		}
		if ( array_key_exists( 'checkpoint_label', $patch ) && mb_strlen( (string) $patch['checkpoint_label'] ) > 40 ) {
			return array( 'code' => 'field_type_invalid', 'message' => 'Tên mốc dài quá 40 ký tự.', 'hint' => 'Ví dụ: "bản gửi khách 20/10".' );
		}
		return null;
	}

	/**
	 * [2026-10-20 10:53 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.126 S126-U3 — pure: a `customer` switch on a sensitive row is refused BEFORE anything is written.
	 *
	 * @return array{code:string,message:string,hint:string}|null
	 */
	public static function validate_access_patch( array $patch, array $cur ): ?array {
		if ( array_key_exists( 'customer', $patch ) && 'off' !== (string) $patch['customer'] && '' !== (string) ( $cur['flags']['sensitive'] ?? '' ) ) {
			return array( 'code' => 'audience_locked', 'message' => 'Dòng này thuộc loại nhạy cảm (tài chính · sức khoẻ · trẻ em · cá nhân): không mở cho khách được.', 'hint' => 'Giữ Tắt; muốn chia sẻ thì tạo bản tóm tắt riêng không chứa số liệu.' );
		}
		return null;
	}

	public static function patch( WP_REST_Request $request ): WP_REST_Response {
		if ( ! class_exists( 'BizCity_Journal_Index' ) ) {
			return new WP_REST_Response( array( 'code' => 'journal_unavailable', 'message' => 'Chỉ mục nhật ký chưa sẵn sàng trên site này.', 'hint' => '', 'help_code' => 'S121-JN-REST-1' ), 503 );
		}
		$id    = (int) $request->get_param( 'id' );
		$owner = (int) get_current_user_id();
		$body  = $request->get_json_params();
		$split = self::split_patch( is_array( $body ) ? $body : (array) $request->get_body_params() );
		$cur   = BizCity_Journal_Index::get( $id, $owner );
		if ( null === $cur ) {
			return self::respond( new WP_Error( 'journal_not_found', 'Không tìm thấy mục nhật ký.' ) );
		}
		$bad = self::validate_patch( $split['patch'], BizCity_Journal_Index::register_editable_card( (string) ( $cur['kind'] ?? '' ) ) );
		if ( null === $bad ) {
			$bad = self::validate_access_patch( $split['patch'], $cur ); // [2026-10-20 10:53 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.126 S126-P3 — audience_locked
		}
		if ( null !== $bad ) {
			return new WP_REST_Response( $bad + array( 'help_code' => 'S121-JN-REST-400' ), 400 );
		}
		// [2026-10-19 10:40 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.121 R-CONTACT-ID — link only to a contact this person may see (an id from the client is never trusted); 0 = unlink.
		if ( isset( $split['patch']['contact_id'] ) ) {
			$split['patch']['contact_id'] = (int) $split['patch']['contact_id'];
			if ( $split['patch']['contact_id'] > 0 && ! BizCity_Journal_Index::contact_visible( $split['patch']['contact_id'], $owner ) ) {
				return new WP_REST_Response( array( 'code' => 'contact_not_found', 'message' => 'Không tìm thấy liên hệ này.', 'hint' => 'Liên hệ đã bị xoá hoặc anh/chị không có quyền xem.', 'help_code' => 'S121-JN-REST-404' ), 404 );
			}
		}
		$out   = null;
		if ( array() !== $split['patch'] ) {
			$out = BizCity_Journal_Index::file( $id, $owner, $split['patch'] );
			if ( is_wp_error( $out ) ) {
				return self::respond( $out );
			}
		}
		if ( '' !== $split['state'] ) {
			$out = BizCity_Journal_Index::set_state( $id, $owner, $split['state'], array(), 'verified' === $split['state'] ? $owner : 0 );
		}
		return self::respond( null === $out ? BizCity_Journal_Index::get( $id, $owner ) : $out );
	}

	/**
	 * Pure: the "Tổng quan" numbers from the person's journal-entry@1.1 rows (newest first).
	 *
	 * @return array{total:int,by_kind:array,contracts:int,expiring:array,review:int,failed:int,mirror_pending:int,levels:array}
	 */
	public static function overview_from_entries( array $entries, string $today ): array {
		$soon = gmdate( 'Y-m-d', (int) strtotime( $today . ' +30 days UTC' ) );
		$out  = array( 'total' => 0, 'by_kind' => array(), 'contracts' => 0, 'expiring' => array(), 'review' => 0, 'failed' => 0, 'mirror_pending' => 0, 'levels' => array( 'L0' => 0, 'L1' => 0, 'L2' => 0, 'L3' => 0 ), 'by_group' => array(), 'by_contact' => array(), 'unassigned' => 0,
			// [2026-10-20 10:53 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.126 S126-U3 (doc 20 §5) — the BrainLedger header numbers: per layer, per tier (documents only), rows open to customers, jobs still running.
			'by_layer' => array( 'document' => 0, 'notebook' => 0, 'twin_sheet' => 0, 'skill' => 0, 'job' => 0, 'output' => 0 ), 'by_tier' => array( 'L1' => 0, 'L2' => 0, 'L3' => 0 ), 'public_count' => 0, 'jobs_running' => 0 );
		$groups   = array();
		$contacts = array();
		foreach ( $entries as $e ) {
			$state = (string) ( $e['state'] ?? 'captured' );
			if ( 'void' === $state ) {
				continue;
			}
			$out['total']++;
			$layer = (string) ( $e['layer'] ?? 'document' );
			if ( isset( $out['by_layer'][ $layer ] ) ) {
				$out['by_layer'][ $layer ]++;
			}
			$tier = (string) ( $e['tier'] ?? '' );
			if ( '' !== $tier && isset( $out['by_tier'][ $tier ] ) ) {
				$out['by_tier'][ $tier ]++;
			}
			if ( 'customer' === (string) ( $e['audience'] ?? 'owner' ) ) {
				$out['public_count']++;
			}
			if ( 'job' === $layer && in_array( (string) ( $e['card']['status'] ?? '' ), array( 'pending', 'running', 'reviewing', 'blocked' ), true ) ) {
				$out['jobs_running']++;
			}
			// [2026-10-19 10:40 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.121 R-CONTACT-ID — per Zalo group / per CRM contact (ids only; names are looked up by the caller).
			$gid = (string) ( $e['group']['gid'] ?? '' );
			$cid = (int) ( $e['contact_id'] ?? 0 );
			if ( '' !== $gid ) {
				$groups[ $gid ] = array( 'gid' => $gid, 'name' => (string) ( $groups[ $gid ]['name'] ?? '' ) !== '' ? $groups[ $gid ]['name'] : (string) ( $e['group']['name'] ?? '' ), 'count' => (int) ( $groups[ $gid ]['count'] ?? 0 ) + 1 );
			}
			if ( $cid > 0 ) {
				$contacts[ $cid ] = (int) ( $contacts[ $cid ] ?? 0 ) + 1;
			}
			if ( '' === $gid && 0 === $cid ) {
				$out['unassigned']++;
			}
			$kind = (string) ( $e['kind'] ?? '' );
			$out['by_kind'][ $kind ] = ( $out['by_kind'][ $kind ] ?? 0 ) + 1;
			if ( 'review' === $state || ! empty( $e['flags']['review'] ) ) {
				$out['review']++;
			}
			if ( 'failed' === $state ) {
				$out['failed']++;
			}
			if ( 'pending' === (string) ( $e['mirror']['sheet'] ?? '' ) || 'failed' === (string) ( $e['mirror']['sheet'] ?? '' ) ) {
				$out['mirror_pending']++;
			}
			$lvl = (string) ( $e['learning']['level'] ?? 'L0' );
			if ( isset( $out['levels'][ $lvl ] ) ) {
				$out['levels'][ $lvl ]++;
			}
			if ( 'biz.contract' === $kind ) {
				$out['contracts']++;
				$end = is_scalar( $e['card']['end_date'] ?? null ) ? (string) $e['card']['end_date'] : '';
				if ( '' !== $end && $end >= $today && $end <= $soon ) {
					$out['expiring'][] = array( 'entry_id' => (int) ( $e['entry_id'] ?? 0 ), 'code' => (string) ( $e['original']['doc_code'] ?? '' ), 'title' => (string) ( $e['title'] ?? '' ), 'end_date' => $end );
				}
			}
		}
		arsort( $out['by_kind'] );
		$by_count = static function ( $a, $b ) { return $b['count'] <=> $a['count']; };
		$g = array_values( $groups );
		usort( $g, $by_count );
		$out['by_group'] = array_slice( $g, 0, 20 );
		arsort( $contacts );
		foreach ( array_slice( $contacts, 0, 20, true ) as $cid => $n ) {
			$out['by_contact'][] = array( 'contact_id' => (int) $cid, 'count' => (int) $n );
		}
		usort( $out['expiring'], static function ( $a, $b ) { return strcmp( $a['end_date'], $b['end_date'] ); } );
		return $out;
	}

	public static function overview( WP_REST_Request $request ): WP_REST_Response {
		if ( ! class_exists( 'BizCity_Journal_Database' ) || ! class_exists( 'BizCity_Journal_Index' ) ) {
			return new WP_REST_Response( array( 'code' => 'journal_unavailable', 'message' => 'Chỉ mục nhật ký chưa sẵn sàng trên site này.', 'hint' => '', 'help_code' => 'S121-JN-REST-1' ), 503 );
		}
		$owner   = (int) get_current_user_id();
		if ( class_exists( 'BizCity_Journal_Layer_Rows', false ) ) { // [2026-10-21 11:14 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.126 S126-B3 — notebooks / sheets made before the NB-/SO- rows existed get theirs (once a day, bounded)
			BizCity_Journal_Layer_Rows::reconcile_owner( $owner );
		}
		$entries = array_map( array( 'BizCity_Journal_Index', 'to_journal_entry' ), BizCity_Journal_Database::instance()->register_rows( $owner, 500 ) );
		$out     = self::overview_from_entries( $entries, gmdate( 'Y-m-d' ) );
		$out['upcoming_reminders'] = self::upcoming_reminders( $owner );
		$out['team_total']         = count( self::team_entries( $owner ) ); // [2026-10-17 11:10 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.121 Q121-8 web
		$out['kind_labels']        = array();
		foreach ( array_keys( $out['by_kind'] ) as $k ) {
			$out['kind_labels'][ $k ] = class_exists( 'BizCity_Journal_Kinds' ) ? BizCity_Journal_Kinds::label( (string) $k ) : (string) $k;
		}
		foreach ( $out['by_contact'] as $i => $c ) { // contact names come from the CRM the person already sees; one they may not see stays a number
			$row = BizCity_Journal_Index::contact_visible( (int) $c['contact_id'], $owner ) ? BizCity_CRM_Repository::get_contact( (int) $c['contact_id'] ) : null;
			$out['by_contact'][ $i ]['name'] = is_array( $row ) ? (string) ( $row['name'] ?? '' ) : '';
		}
		$out['sheet'] = BizCity_Journal_Index::register_describe(); // [2026-10-19 09:18 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.121 S121-R4/R6 — the web reads columns + labels from doc-register@1
		return new WP_REST_Response( $out, 200 );
	}

	/**
	 * [2026-10-19 09:18 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.121 S121-R6 — pure: the "Hợp đồng" list from biz.contract entries. `$days > 0` keeps
	 * contracts ending within [today, today + days]; order = end_date ascending, rows without end_date last (newest doc_date first among them).
	 */
	public static function contracts_from_entries( array $entries, string $today, int $days ): array {
		$limit = $days > 0 ? gmdate( 'Y-m-d', (int) strtotime( $today . ' +' . $days . ' days UTC' ) ) : '';
		$keep  = array();
		foreach ( $entries as $e ) {
			if ( 'biz.contract' !== (string) ( $e['kind'] ?? '' ) || 'void' === (string) ( $e['state'] ?? '' ) ) {
				continue;
			}
			$end = is_scalar( $e['card']['end_date'] ?? null ) ? (string) $e['card']['end_date'] : '';
			if ( $days > 0 && ( '' === $end || $end < $today || $end > $limit ) ) {
				continue;
			}
			$keep[] = $e;
		}
		usort( $keep, static function ( $a, $b ) {
			$ea = is_scalar( $a['card']['end_date'] ?? null ) ? (string) $a['card']['end_date'] : '';
			$eb = is_scalar( $b['card']['end_date'] ?? null ) ? (string) $b['card']['end_date'] : '';
			if ( $ea !== $eb ) {
				if ( '' === $ea ) {
					return 1;
				}
				if ( '' === $eb ) {
					return -1;
				}
				return strcmp( $ea, $eb );
			}
			return strcmp( (string) ( $b['doc_date'] ?? '' ), (string) ( $a['doc_date'] ?? '' ) );
		} );
		return $keep;
	}

	/** Pure: one entry as the small card the inbox drawer shows — no URL, no hash, no phone, no uid. */
	public static function brief_item( array $e ): array {
		return array(
			'entry_id' => (int) ( $e['entry_id'] ?? 0 ),
			'code'     => (string) ( $e['original']['doc_code'] ?? '' ) !== '' ? (string) $e['original']['doc_code'] : 'JE-' . (int) ( $e['entry_id'] ?? 0 ),
			'title'    => (string) ( $e['title'] ?? '' ),
			'kind'     => (string) ( $e['kind'] ?? '' ),
			'doc_date' => (string) ( $e['doc_date'] ?? '' ),
			'state'    => (string) ( $e['state'] ?? '' ),
			'end_date' => is_scalar( $e['card']['end_date'] ?? null ) ? (string) $e['card']['end_date'] : '',
		);
	}

	/**
	 * Pure: what a CRM conversation maps to. `source_id` is `group:<gid>` for a Zalo group, else the thread belongs to its contact.
	 *
	 * @return array{scope:string,gid:string,contact_id:int}
	 */
	public static function conversation_scope( string $source_id, int $contact_id ): array {
		if ( 0 === strpos( $source_id, 'group:' ) && '' !== BizCity_Journal_Index::group_tag( substr( $source_id, 6 ) ) ) {
			return array( 'scope' => 'group', 'gid' => substr( $source_id, 6 ), 'contact_id' => 0 );
		}
		return $contact_id > 0 ? array( 'scope' => 'contact', 'gid' => '', 'contact_id' => $contact_id ) : array( 'scope' => 'none', 'gid' => '', 'contact_id' => 0 );
	}

	public static function for_conversation( WP_REST_Request $request ): WP_REST_Response {
		if ( ! class_exists( 'BizCity_Journal_Index' ) || ! class_exists( 'BizCity_CRM_Repository' ) || ! class_exists( 'BizCity_CRM_Inbox_Access' ) ) {
			return new WP_REST_Response( array( 'code' => 'journal_unavailable', 'message' => 'Chỉ mục nhật ký chưa sẵn sàng trên site này.', 'hint' => '', 'help_code' => 'S121-JN-REST-1' ), 503 );
		}
		$conv  = (int) $request->get_param( 'conversation_id' );
		$owner = (int) get_current_user_id();
		if ( $conv <= 0 || ! BizCity_CRM_Inbox_Access::can_view_conversation( $conv, $owner ) ) {
			return new WP_REST_Response( array( 'code' => 'conversation_not_found', 'message' => 'Không tìm thấy cuộc trò chuyện này.', 'hint' => 'Cuộc trò chuyện đã bị xoá hoặc anh/chị không có quyền xem.', 'help_code' => 'S121-JN-REST-404' ), 404 ); // 404, never 403: no existence leak
		}
		$ref   = BizCity_CRM_Repository::get_conversation_thread_ref( $conv );
		$scope = self::conversation_scope( (string) $ref['source_id'], (int) $ref['contact_id'] );
		$limit = max( 1, min( 20, (int) $request->get_param( 'limit' ) ?: 10 ) );
		$items = array();
		$total = 0;
		$name  = '';
		if ( 'none' !== $scope['scope'] ) {
			$filter = self::scope_filter( array( 'owner_user_id' => $owner, 'limit' => $limit ), $scope['contact_id'], $scope['gid'] );
			$page   = BizCity_Journal_Index::find( $filter );
			$total  = (int) $page['total'];
			foreach ( $page['items'] as $e ) {
				$items[] = self::brief_item( $e );
			}
			if ( 'group' === $scope['scope'] ) {
				$name = (string) ( $page['items'][0]['group']['name'] ?? '' );
			} else {
				$row  = BizCity_CRM_Repository::get_contact( $scope['contact_id'] );
				$name = is_array( $row ) ? (string) ( $row['name'] ?? '' ) : '';
			}
		}
		return new WP_REST_Response( array( 'scope' => $scope['scope'], 'gid' => $scope['gid'], 'contact_id' => $scope['contact_id'], 'name' => $name, 'items' => $items, 'total' => $total ), 200 );
	}

	public static function contracts( WP_REST_Request $request ): WP_REST_Response {
		if ( ! class_exists( 'BizCity_Journal_Index' ) ) {
			return new WP_REST_Response( array( 'code' => 'journal_unavailable', 'message' => 'Chỉ mục nhật ký chưa sẵn sàng trên site này.', 'hint' => '', 'help_code' => 'S121-JN-REST-1' ), 503 );
		}
		$view = BizCity_Journal_Index::register_view( 'contracts' );
		$max  = max( 50, (int) ( $view['scan_max'] ?? 500 ) );
		$days = max( 0, min( 366, (int) $request->get_param( 'expiring_days' ) ) );
		$all  = array();
		for ( $offset = 0; $offset < $max; $offset += 50 ) {
			$page = BizCity_Journal_Index::find( array( 'owner_user_id' => (int) get_current_user_id(), 'kind' => 'biz.contract', 'limit' => 50, 'offset' => $offset ) );
			$all  = array_merge( $all, $page['items'] );
			if ( count( $page['items'] ) < 50 ) {
				break;
			}
		}
		$items = self::contracts_from_entries( $all, gmdate( 'Y-m-d' ), $days );
		return new WP_REST_Response( array( 'items' => $items, 'total' => count( $items ), 'scanned' => count( $all ), 'truncated' => count( $all ) >= $max ), 200 );
	}

	/** [2026-10-17 11:10 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.121 Q121-8 web — Zalo Cá nhân numbers whose CURRENT owner is $user_id. */
	public static function owned_bridges( int $user_id ): array {
		if ( $user_id <= 0 || ! class_exists( 'BizCity_Zalo_Mapping_Repo' ) ) {
			return array();
		}
		$out = array();
		foreach ( (array) BizCity_Zalo_Mapping_Repo::list_personal_accounts_for_owner( $user_id ) as $row ) {
			$b = (string) ( $row['bridge_account_id'] ?? '' );
			if ( '' !== $b ) {
				$out[] = $b;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/** Staff rows of my numbers as journal entries, flagged `read_only` + `team` (no edit / verify / learn from here). */
	public static function team_entries( int $user_id, int $entry_id = 0, int $max = 200 ): array {
		if ( ! class_exists( 'BizCity_Journal_Database' ) || ! class_exists( 'BizCity_Journal_Index' ) ) {
			return array();
		}
		$out = array();
		foreach ( BizCity_Journal_Database::instance()->number_rows( self::owned_bridges( $user_id ), $user_id, $max, $entry_id ) as $row ) {
			$e = BizCity_Journal_Index::to_journal_entry( $row );
			unset( $e['owner_user_id'] );
			$e['team']      = true;
			$e['read_only'] = true;
			$out[] = $e;
		}
		return $out;
	}

	public static function team( WP_REST_Request $request ): WP_REST_Response {
		$items = self::team_entries( (int) get_current_user_id() );
		return new WP_REST_Response( array( 'items' => $items, 'total' => count( $items ) ), 200 );
	}

	public static function learn( WP_REST_Request $request ): WP_REST_Response {
		if ( ! class_exists( 'BizCity_KG_Private_File_Store' ) ) {
			return new WP_REST_Response( array( 'code' => 'journal_unavailable', 'message' => 'Chỉ mục nhật ký chưa sẵn sàng trên site này.', 'hint' => '', 'help_code' => 'S121-JN-REST-1' ), 503 );
		}
		$ctx = self::ctx();
		return self::respond( BizCity_KG_Private_File_Store::mcp_learn_set(
			array( 'entry_id' => (int) $request->get_param( 'id' ), 'level' => (string) $request->get_param( 'level' ) ),
			array( 'user_id' => (int) $ctx['owner_user_id'], 'role' => (string) $ctx['role'], 'allowed_notebook_ids' => array() )
		) );
	}

	/** Reminders of the next 7 days (site mirror of the cell clock, bizcity_crm_events). Count only — the calendar page lists them. */
	private static function upcoming_reminders( int $owner ): int {
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return 0;
		}
		$tbl = $wpdb->prefix . 'bizcity_crm_events';
		if ( $tbl !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tbl ) ) ) {
			return 0;
		}
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tbl} WHERE user_id = %d AND start_at BETWEEN UTC_TIMESTAMP() AND DATE_ADD(UTC_TIMESTAMP(), INTERVAL 7 DAY) AND status NOT IN ('cancelled','done')", $owner ) );
	}

	/** Pure: doc-register@1 rows ⇒ CSV (UTF-8 BOM for Excel; formula-leading cells neutralised). Columns = DOC-REGISTER-v1.json projections.csv. */
	public static function csv_of( array $rows ): string {
		$cols = BizCity_Journal_Index::register_columns( 'csv' );
		$esc  = static function ( $v ): string {
			$v = (string) $v;
			if ( '' !== $v && false !== strpos( '=+-@', $v[0] ) ) {
				$v = "'" . $v; // CSV injection guard
			}
			return '"' . str_replace( '"', '""', $v ) . '"';
		};
		$out = "\xEF\xBB\xBF" . implode( ',', $cols ) . "\r\n";
		foreach ( $rows as $r ) {
			$line = array();
			foreach ( $cols as $c ) {
				$line[] = $esc( $r[ $c ] ?? '' );
			}
			$out .= implode( ',', $line ) . "\r\n";
		}
		return $out;
	}

	/** GET journal/export — the register as CSV (no Google Sheet needed, R-JN-15). */
	public static function export_csv( WP_REST_Request $request ) {
		if ( ! class_exists( 'BizCity_Journal_Database' ) || ! class_exists( 'BizCity_Journal_Index' ) ) {
			return new WP_REST_Response( array( 'code' => 'journal_unavailable', 'message' => 'Chỉ mục nhật ký chưa sẵn sàng trên site này.', 'hint' => '', 'help_code' => 'S121-JN-REST-1' ), 503 );
		}
		$owner = (int) get_current_user_id();
		$rows  = array();
		foreach ( BizCity_Journal_Database::instance()->register_rows( $owner, 500 ) as $r ) {
			$rows[] = BizCity_Journal_Index::register_row( BizCity_Journal_Index::to_journal_entry( $r ) );
		}
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="so-tai-lieu-' . gmdate( 'Ymd' ) . '.csv"' );
		echo self::csv_of( $rows ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV body, cells quoted + injection-guarded
		exit;
	}

	private static function ctx(): array {
		return array( 'owner_user_id' => get_current_user_id(), 'role' => current_user_can( 'manage_options' ) ? 'owner' : 'staff' );
	}

	private static function read_params( WP_REST_Request $request, array $keys ): array {
		$out = array();
		foreach ( $keys as $k ) {
			$v = $request->get_param( $k );
			if ( null !== $v ) {
				$out[ $k ] = $v;
			}
		}
		return $out;
	}

	/** @param array|WP_Error $res */
	private static function respond( $res ): WP_REST_Response {
		if ( is_wp_error( $res ) ) {
			$data = $res->get_error_data();
			// [2026-10-19 09:18 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.123 doc 20 §9 — not mine / unknown ⇒ 404 (never leaks existence); stale rev ⇒ 409.
			$by_code = array( 'journal_not_found' => 404, 'journal_revision_conflict' => 409, 'audience_locked' => 400, 'access_denied' => 404 ); // [2026-10-20 10:53 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.126 S126-P3 — brain-access@1 errors
			$status  = is_array( $data ) && ! empty( $data['status'] ) ? (int) $data['status'] : ( $by_code[ $res->get_error_code() ] ?? 400 );
			$hints = array( 'journal_revision_conflict' => 'Người khác vừa sửa tài liệu này — đã tải bản mới, xem lại rồi lưu lần nữa.', 'journal_not_found' => 'Tài liệu không còn trong sổ của anh/chị.', 'audience_locked' => 'Loại nhạy cảm luôn đóng với khách.' );
			return new WP_REST_Response( array(
				'code'      => $res->get_error_code(),
				'message'   => $res->get_error_message(),
				'hint'      => $hints[ $res->get_error_code() ] ?? '',
				'help_code' => 'S100-JN-REST-' . $status,
			), $status );
		}
		return new WP_REST_Response( $res, 200 );
	}
}
