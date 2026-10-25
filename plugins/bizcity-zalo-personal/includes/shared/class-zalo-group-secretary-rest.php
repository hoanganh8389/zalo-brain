<?php
/**
 * BizCity_Zalo_Group_Secretary_REST — Channel Gateway › số Zalo › "Nhóm của số" (PHASE-0.124 B10, D124-12, ma trận R-CONFIG-MATRIX).
 *
 *   GET  bizcity-channel/v1/bot/policy/{binding_id}/groups                    nhóm của số + cài đặt (quản trị: mọi nhóm, sửa được;
 *                                                                               nhân sự của số: chỉ nhóm mình được giao, chỉ xem)
 *   POST bizcity-channel/v1/bot/policy/{binding_id}/groups/{gid}              MỘT ô của ma trận {follow|save_files|brief_every|assignees|notice}
 *   GET  bizcity-channel/v1/bot/policy/{binding_id}/groups/{gid}/members      người đã nhắn trong nhóm (chọn người được giao)
 *
 * Cài đặt lưu MỘT chỗ (BizCity_CRM_Group_Secretary, option theo số) — cùng chỗ lệnh chat "@trợ lý theo dõi nhóm này" ghi (R-AF-16).
 * Quyền như trang nhân sự của số (D-W2-8): quản trị site / supervisor CRM thay chủ số (Q124-12: chỉ chủ đổi cài đặt). Lỗi theo R-ERROR-UX.
 *
 * Biz Central Brain — Johnny Chu (Chu Hoàng Anh).
 *
 * [2026-10-19 11:13 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.124 B10 — new file.
 *
 * @package Bizcity_Twin_AI
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Zalo_Group_Secretary_REST', false ) ) {
	return;
}

final class BizCity_Zalo_Group_Secretary_REST {

	const NS       = 'bizcity-channel/v1';
	const BASE     = '/bot/policy/(?P<binding_id>\d+)/groups';
	const PLATFORM = 'ZALO_PERSONAL';

	const MESSAGES = array(
		'groups_forbidden'        => array( 'Cần quyền quản trị site hoặc supervisor CRM để đổi cài đặt nhóm.', 'Người được giao chỉ xem được nhóm của mình; nhờ quản trị đổi giúp.' ),
		'groups_view_forbidden'   => array( 'Bạn không phải người được giao của nhóm nào ở số này.', 'Nhờ chủ số giao nhóm cho bạn (Channel Gateway › Nhóm của số).' ),
		'binding_missing'         => array( 'Số chưa được gắn Agent Guru.', 'Lưu cài đặt bot của số một lần rồi quay lại.' ),
		'not_zalo_hub'            => array( 'Chỉ số chạy Zalo Hub mới có thư ký nhóm.', 'Số này vẫn trả lời khách bình thường.' ),
		'module_missing'          => array( 'Thư ký nhóm chưa bật trên site này.', 'Cập nhật plugin bizcity-twin-ai (PHASE-0.124).' ),
		'group_gid_invalid'       => array( 'Mã nhóm không hợp lệ.', 'Tải lại danh sách nhóm rồi thử lại.' ),
	);

	/** Test seams: binding(id) · can_manage() · current_user() · provider(bridge) · staff_uid(bridge, user_id): string */
	public static $readers = array();

	private static $initialized = false;

	public static function init(): void {
		if ( self::$initialized ) {
			return;
		}
		self::$initialized = true;
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes(): void {
		$logged_in = static function () { return function_exists( 'is_user_logged_in' ) && is_user_logged_in(); };
		register_rest_route( self::NS, self::BASE, array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'handle_list' ), 'permission_callback' => $logged_in ) );
		register_rest_route( self::NS, self::BASE . '/(?P<gid>\d{3,30})', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'handle_save' ), 'permission_callback' => $logged_in ) );
		register_rest_route( self::NS, self::BASE . '/(?P<gid>\d{3,30})/members', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'handle_members' ), 'permission_callback' => $logged_in ) );
	}

	public static function handle_list( WP_REST_Request $req ): WP_REST_Response {
		$b = self::binding( (int) $req->get_param( 'binding_id' ) );
		if ( $b instanceof WP_REST_Response ) {
			return $b;
		}
		if ( ! class_exists( 'BizCity_CRM_Group_Secretary', false ) ) {
			return self::error( 'module_missing', 503 );
		}
		$manage = self::can_manage();
		$groups = BizCity_CRM_Group_Secretary::web_groups( $b['bridge_id'] );
		// [2026-10-20 08:46 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.124 — còn nhóm mang tên tạm ("Nhóm Zalo 78066454") ⇒ kéo tên thật cell đã đọc bằng zca (danh bạ qua Hub), nhập CRM, đọc lại
		$pending = self::placeholder_count( $groups );
		// [2026-10-20 09:10 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.124 — việc kéo tên là phụ: thiếu hàm (file chưa lên đủ) hoặc lỗi ⇒ bỏ qua, KHÔNG làm hỏng danh sách (từng 500)
		// [2026-10-25] PHASE-0.136 D136-17 — 100 % nhóm: kéo danh bạ nhóm của cell (zca) theo nhịp của refresh_for_bridge (≤ 1 lần / số / giờ), không chỉ khi còn tên tạm
		if ( class_exists( 'BizCity_CRM_Zalo_Directory' ) && method_exists( 'BizCity_CRM_Zalo_Directory', 'refresh_for_bridge' ) ) {
			try {
				$r = BizCity_CRM_Zalo_Directory::refresh_for_bridge( $b['bridge_id'] );
				if ( ! empty( $r['imported'] ) ) {
					$groups  = BizCity_CRM_Group_Secretary::web_groups( $b['bridge_id'] );
					$pending = self::placeholder_count( $groups );
				}
			} catch ( Throwable $e ) {
				$pending = $pending; // giữ nguyên danh sách tên tạm
			}
		}
		if ( ! $manage ) {
			$uid    = self::staff_uid( $b['bridge_id'], self::current_user() );
			$groups = '' === $uid ? array() : array_values( array_filter( $groups, static function ( $g ) use ( $uid ) {
				return in_array( $uid, array_column( (array) $g['settings']['assignees'], 'uid' ), true );
			} ) );
			if ( ! $groups ) {
				return self::error( 'groups_view_forbidden', 403 );
			}
		}
		return new WP_REST_Response( array(
			'ok'        => true,
			'can_edit'  => $manage,
			'owner_set' => '' !== BizCity_CRM_Group_Secretary::owner_uid( $b['bridge_id'] ),
			'names_pending' => $pending,
			// [2026-10-25] PHASE-0.136 D136-17/18 — 100 % nhóm (cả nhóm chưa có hội thoại) + trần gói: "Đang quản lý count / limit"
			'managed'   => method_exists( 'BizCity_CRM_Group_Secretary', 'managed_summary' ) ? BizCity_CRM_Group_Secretary::managed_summary( $b['bridge_id'] ) : null,
			'groups'    => array_map( array( __CLASS__, 'view' ), $groups ),
			'limits'    => array( 'brief_min' => BizCity_CRM_Group_Secretary::BRIEF_MIN, 'brief_max' => BizCity_CRM_Group_Secretary::BRIEF_MAX, 'assignees_max' => BizCity_CRM_Group_Secretary::MAX_ASSIGNEES, 'notice_max' => BizCity_CRM_Group_Secretary::NOTICE_MAX ),
		), 200 );
	}

	public static function handle_save( WP_REST_Request $req ): WP_REST_Response {
		$b = self::binding( (int) $req->get_param( 'binding_id' ) );
		if ( $b instanceof WP_REST_Response ) {
			return $b;
		}
		if ( ! class_exists( 'BizCity_CRM_Group_Secretary', false ) ) {
			return self::error( 'module_missing', 503 );
		}
		if ( ! self::can_manage() ) {
			return self::error( 'groups_forbidden', 403 );
		}
		$gid = BizCity_CRM_Group_Secretary::clean_gid( (string) $req->get_param( 'gid' ) );
		if ( '' === $gid ) {
			return self::error( 'group_gid_invalid', 400 );
		}
		$body = $req->get_json_params();
		$r    = BizCity_CRM_Group_Secretary::set_from_web( $b['bridge_id'], $gid, is_array( $body ) ? $body : array() );
		if ( is_wp_error( $r ) ) {
			$data = $r->get_error_data();
			return new WP_REST_Response( array( 'ok' => false, 'code' => $r->get_error_code(), 'message' => $r->get_error_message(), 'hint' => 'Sửa lại ô này rồi bấm Xem & lưu.', 'help_code' => 'S124-B10-' . ( is_array( $data ) ? (int) ( $data['status'] ?? 400 ) : 400 ) ), is_array( $data ) ? (int) ( $data['status'] ?? 400 ) : 400 );
		}
		return new WP_REST_Response( array( 'ok' => true, 'thread_id' => $gid, 'settings' => self::settings_view( $r ) ), 200 );
	}

	public static function handle_members( WP_REST_Request $req ): WP_REST_Response {
		$b = self::binding( (int) $req->get_param( 'binding_id' ) );
		if ( $b instanceof WP_REST_Response ) {
			return $b;
		}
		if ( ! class_exists( 'BizCity_CRM_Group_Secretary', false ) ) {
			return self::error( 'module_missing', 503 );
		}
		if ( ! self::can_manage() ) {
			return self::error( 'groups_forbidden', 403 );
		}
		$gid = BizCity_CRM_Group_Secretary::clean_gid( (string) $req->get_param( 'gid' ) );
		if ( '' === $gid ) {
			return self::error( 'group_gid_invalid', 400 );
		}
		$staff = array();
		if ( class_exists( 'BizCity_Zalo_Agent_Principals' ) ) {
			foreach ( BizCity_Zalo_Agent_Principals::from_policy( $b['policy'] ) as $row ) {
				$staff[ (string) ( $row['uid'] ?? '' ) ] = true;
			}
		}
		$items = array_map( static function ( $m ) use ( $staff ) {
			return $m + array( 'is_staff' => isset( $staff[ $m['uid'] ] ) ); // mockup: người không phải nhân sự ⇒ cảnh báo "đang giao cho khách"
		}, BizCity_CRM_Group_Secretary::recent_senders( $b['bridge_id'], $gid ) );
		return new WP_REST_Response( array( 'ok' => true, 'items' => $items ), 200 );
	}

	/* ── helpers ──────────────────────────────────────────────────── */

	public static function view( array $g ): array {
		return array(
			'group_ref' => (string) $g['group_ref'],
			'thread_id' => (string) $g['thread_id'],
			'name'      => (string) $g['name'],
			'last_at'   => (string) $g['last_at'],
			'settings'  => self::settings_view( (array) $g['settings'] ),
			'managed'   => ! empty( $g['managed'] ), // [2026-10-25] PHASE-0.136 D136-18 — nhóm đang giữ suất (lưu tệp / theo dõi)
		);
	}

	public static function settings_view( array $s ): array {
		return array(
			'follow'      => ! empty( $s['follow'] ),
			'save_files'  => ! array_key_exists( 'save_files', $s ) || ! empty( $s['save_files'] ),
			'brief_every' => (int) ( $s['brief_every'] ?? 30 ),
			'assignees'   => array_values( (array) ( $s['assignees'] ?? array() ) ),
			'notice'      => (string) ( $s['notice'] ?? '' ),
			'updated_at'  => (string) ( $s['updated_at'] ?? '' ),
			'updated_by'  => (string) ( $s['updated_by'] ?? '' ),
		);
	}

	/** @return array{binding_id:int,bridge_id:string,policy:array}|WP_REST_Response */
	/** [2026-10-20 08:46 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.124 — số nhóm còn tên tạm do site đặt khi cell chưa gửi tên */
	public static function placeholder_count( array $groups ): int {
		$n = 0;
		foreach ( $groups as $g ) {
			if ( preg_match( '/^Nhóm( Zalo)? [0-9]{4,12}$/u', (string) ( $g['name'] ?? '' ) ) ) {
				++$n;
			}
		}
		return $n;
	}

	private static function binding( int $id ) {
		$row = isset( self::$readers['binding'] ) ? call_user_func( self::$readers['binding'], $id ) : ( class_exists( 'BizCity_Channel_Binding' ) ? BizCity_Channel_Binding::find( $id ) : null );
		if ( ! is_array( $row ) || self::PLATFORM !== strtoupper( (string) ( $row['platform'] ?? '' ) ) ) {
			return self::error( 'binding_missing', 404 );
		}
		$bridge = (string) ( $row['account_id'] ?? '' );
		$prov   = isset( self::$readers['provider'] ) ? (string) call_user_func( self::$readers['provider'], $bridge ) : ( class_exists( 'BizCity_Zalo_Account_Flags' ) ? (string) BizCity_Zalo_Account_Flags::provider( $bridge ) : '' );
		if ( 'zalo_hub' !== $prov ) {
			return self::error( 'not_zalo_hub', 409 );
		}
		$raw    = $row['policy_json'] ?? '';
		$policy = is_array( $raw ) ? $raw : json_decode( (string) $raw, true );
		return array( 'binding_id' => $id, 'bridge_id' => $bridge, 'policy' => is_array( $policy ) ? $policy : array() );
	}

	private static function can_manage(): bool {
		if ( isset( self::$readers['can_manage'] ) ) {
			return (bool) call_user_func( self::$readers['can_manage'] );
		}
		return class_exists( 'BizCity_Zalo_Staff_Principals_REST', false ) && BizCity_Zalo_Staff_Principals_REST::can_manage();
	}

	private static function current_user(): int {
		return isset( self::$readers['current_user'] ) ? (int) call_user_func( self::$readers['current_user'] ) : ( function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0 );
	}

	/** UID Zalo của nhân sự (WP user) ở số này — từ danh sách nhân sự của số (staff principals) */
	private static function staff_uid( string $bridge, int $user_id ): string {
		if ( isset( self::$readers['staff_uid'] ) ) {
			return (string) call_user_func( self::$readers['staff_uid'], $bridge, $user_id );
		}
		if ( $user_id <= 0 || ! class_exists( 'BizCity_Zalo_Agent_Principals' ) ) {
			return '';
		}
		foreach ( BizCity_Zalo_Agent_Principals::from_policy( BizCity_Zalo_Agent_Principals::policy( $bridge ) ) as $row ) {
			if ( (int) ( $row['user_id'] ?? 0 ) === $user_id ) {
				return (string) ( $row['uid'] ?? '' );
			}
		}
		return '';
	}

	private static function error( string $code, int $status ): WP_REST_Response {
		$m = self::MESSAGES[ $code ] ?? array( 'Không thực hiện được.', 'Thử lại sau.' );
		return new WP_REST_Response( array( 'ok' => false, 'code' => $code, 'message' => $m[0], 'hint' => $m[1], 'help_code' => 'S124-B10-' . $status ), $status );
	}
}
