<?php
/**
 * BizCity_Zalo_Agent_Config_REST — Channel Gateway › số Zalo › tab "Cấu hình" › "Cấu hình trợ lý của số" (PHASE-0.127 S127-C5, D127-9, doc 56 §1.4).
 *
 *   GET  bizcity-channel/v1/bot/policy/{binding_id}/agent-config     agent-config@1 của số: mỗi khoá có giá trị cell (chỉ xem), tầng tenant (gói — chỉ xem),
 *                                                                    giá trị riêng của số (sửa được) và giá trị đang dùng; model theo việc (chỉ xem); ô khoá theo luật
 *   POST bizcity-channel/v1/bot/policy/{binding_id}/agent-config     MỘT ô của tầng số: {key, value} (value null = bỏ ghi đè, về tầng dưới)
 *   GET  bizcity-channel/v1/bot/policy/{binding_id}/registry         (0.128 S128-C2) "Trợ lý làm được gì": registry-view@1 của tenant, chỉ xem
 *
 * Đường: site → Hub (`zalo-personal-bridge/brain/agent-config`, gốc mới H2) → cell `/wp/brain/agent-config`. Cell giữ giá trị (account_settings), site
 * không lưu gì. Quyền như tab "Nhóm" / "Lịch": quản trị site / supervisor CRM sửa; nhân sự của số chỉ xem. Lỗi theo R-ERROR-UX.
 *
 * Biz Central Brain — Johnny Chu (Chu Hoàng Anh).
 *
 * [2026-10-22 12:02 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.127 S127-C5 — new file.
 *
 * @package Bizcity_Twin_AI
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Zalo_Agent_Config_REST', false ) ) {
	return;
}

final class BizCity_Zalo_Agent_Config_REST {

	const NS       = 'bizcity-channel/v1';
	const BASE     = '/bot/policy/(?P<binding_id>\d+)/agent-config';
	const PLATFORM = 'ZALO_PERSONAL';
	/** khoá tầng số được sửa (cell kiểm lại khoảng / khoá) */
	const KEYS = array( 'QUEST_PARALLEL', 'QUEST_DEADLINE_S', 'QUEST_OWNER_WAIT_MIN', 'SESSION_IDLE_HOURS', 'QUEST_STAFF' );
	// [2026-10-22 05:14 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.128 S128-C2 — "Trợ lý làm được gì" (registry-view@1, read-only) beside the config
	const REGISTRY_BASE = '/bot/policy/(?P<binding_id>\d+)/registry';
	const WORK_KINDS    = array( 'executor', 'subagent', 'hermes', 'workflow', 'gate', 'evaluation' );

	const MESSAGES = array(
		'config_forbidden'      => array( 'Cần quyền quản trị site hoặc supervisor CRM để đổi cấu hình trợ lý của số.', 'Nhân sự của số chỉ xem được; nhờ quản trị đổi giúp.' ),
		'config_view_forbidden' => array( 'Bạn không phải nhân sự của số này.', 'Nhờ chủ số thêm bạn vào danh sách nhân sự (tab Người dùng).' ),
		'binding_missing'       => array( 'Số chưa được gắn Agent Guru.', 'Lưu cài đặt bot của số một lần rồi quay lại.' ),
		'not_zalo_hub'          => array( 'Chỉ số chạy Zalo Hub mới có cấu hình trợ lý ở cell.', 'Số này vẫn trả lời khách bình thường.' ),
		'hub_unavailable'       => array( 'Chưa đọc được cấu hình từ trợ lý (Hub / cell không trả lời).', 'Bấm Thử lại sau ít phút.' ),
		'invalid_param'         => array( 'Ô không hợp lệ.', 'Sửa lại ô này rồi bấm Xem & lưu.' ),
	);

	/** Test seams: binding(id) · can_manage() · current_user() · provider(bridge) · staff_uid(bridge, user_id) · hub(method, bridge, body, query) */
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
		register_rest_route( self::NS, self::BASE, array(
			array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'handle_get' ), 'permission_callback' => $logged_in ),
			array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'handle_save' ), 'permission_callback' => $logged_in ),
		) );
		register_rest_route( self::NS, self::REGISTRY_BASE, array(
			array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'handle_registry' ), 'permission_callback' => $logged_in ),
		) );
	}

	/**
	 * [2026-10-22 05:14 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.128 S128-C2 (D128-3) — GET …/registry: "Trợ lý làm được gì", read-only for everyone who may see
	 * the tab (admin / supervisor and the number's staff). Cell → Hub `brain/registry` → here; only known fields are copied.
	 */
	public static function handle_registry( WP_REST_Request $req ): WP_REST_Response {
		$b = self::binding( (int) $req->get_param( 'binding_id' ) );
		if ( $b instanceof WP_REST_Response ) {
			return $b;
		}
		if ( ! self::can_manage() && '' === self::staff_uid( $b['bridge_id'], self::current_user() ) ) {
			return self::error( 'config_view_forbidden', 403 );
		}
		$query = $req->get_param( 'fresh' ) ? array( 'fresh' => (string) time() ) : array();
		$raw   = isset( self::$readers['registry'] ) ? (array) call_user_func( self::$readers['registry'], $b['bridge_id'], $query ) : self::hub_registry( $b['bridge_id'], $query );
		if ( empty( $raw['ok'] ) || ! is_array( $raw['rows'] ?? null ) ) {
			return self::error( 'hub_unavailable', 502, array( 'code_hub' => (string) ( $raw['code'] ?? '' ) ) );
		}
		return new WP_REST_Response( self::registry_view( $raw ), 200 );
	}

	/** registry-view@1 cut for the site: no tenant id, no schema / evidence / status columns; R2 / R3 rows say the owner approves first */
	public static function registry_view( array $r ): array {
		$enum = static function ( $v, array $allowed ) {
			return in_array( $v, $allowed, true ) ? $v : null;
		};
		$rows = array();
		foreach ( array_slice( array_filter( (array) $r['rows'], 'is_array' ), 0, 600 ) as $x ) {
			$risk   = $enum( $x['risk'] ?? null, array( 'R0', 'R1', 'R2', 'R3' ) );
			$rows[] = array(
				'id'        => substr( (string) ( $x['id'] ?? '' ), 0, 120 ),
				'label'     => substr( (string) ( $x['label'] ?? '' ), 0, 160 ),
				'source'    => (string) $enum( $x['source'] ?? null, array( 'builtin', 'mcp', 'skill', 'cell_code' ) ),
				'group'     => isset( $x['group'] ) ? substr( (string) $x['group'], 0, 40 ) : null,
				'risk'      => $risk,
				'needs_ok'  => in_array( $risk, array( 'R2', 'R3' ), true ),
				'work_kind' => (string) $enum( $x['work_kind'] ?? null, self::WORK_KINDS ),
				'in_quest'  => ! empty( $x['in_quest'] ),
				'why_not'   => $enum( $x['why_not'] ?? null, array( 'mcp_only', 'excluded', 'not_a_tool' ) ),
			);
		}
		$counts = array();
		foreach ( self::WORK_KINDS as $k ) {
			$counts[ $k ] = count( array_filter( $rows, static function ( $row ) use ( $k ) { return $row['work_kind'] === $k; } ) );
		}
		$src = is_array( $r['sources'] ?? null ) ? $r['sources'] : array();
		return array(
			'ok'       => true,
			'contract' => 'registry-view@1.0.0',
			'sources'  => array( 'builtin' => (int) ( $src['builtin'] ?? 0 ), 'mcp' => (int) ( $src['mcp'] ?? 0 ), 'skill' => (int) ( $src['skill'] ?? 0 ), 'mcp_cache' => (string) $enum( $src['mcp_cache'] ?? null, array( 'listed', 'empty', 'no_tenant' ) ) ),
			'counts'   => $counts,
			'rows'     => $rows,
		);
	}

	private static function hub_registry( string $bridge, array $query ): array {
		if ( ! class_exists( 'BizCity_Zalo_Personal_Hub_Client' ) || ! method_exists( 'BizCity_Zalo_Personal_Hub_Client', 'registry_call' ) ) {
			return array( 'ok' => false, 'code' => 'hub_client_missing' );
		}
		return BizCity_Zalo_Personal_Hub_Client::instance()->registry_call( $bridge, $query );
	}

	public static function handle_get( WP_REST_Request $req ): WP_REST_Response {
		$b = self::binding( (int) $req->get_param( 'binding_id' ) );
		if ( $b instanceof WP_REST_Response ) {
			return $b;
		}
		$manage = self::can_manage();
		if ( ! $manage && '' === self::staff_uid( $b['bridge_id'], self::current_user() ) ) {
			return self::error( 'config_view_forbidden', 403 );
		}
		$query = $req->get_param( 'fresh' ) ? array( 'fresh' => (string) time() ) : array();
		$raw   = self::hub( 'GET', $b['bridge_id'], array(), $query );
		if ( empty( $raw['ok'] ) || ! is_array( $raw['limits'] ?? null ) ) {
			return self::error( 'hub_unavailable', 502, array( 'code_hub' => (string) ( $raw['code'] ?? '' ) ) );
		}
		return new WP_REST_Response( self::view( $raw ) + array( 'can_edit' => $manage ), 200 );
	}

	public static function handle_save( WP_REST_Request $req ): WP_REST_Response {
		$b = self::binding( (int) $req->get_param( 'binding_id' ) );
		if ( $b instanceof WP_REST_Response ) {
			return $b;
		}
		if ( ! self::can_manage() ) {
			return self::error( 'config_forbidden', 403 );
		}
		$p   = (array) $req->get_json_params();
		$key = (string) ( $p['key'] ?? '' );
		if ( ! in_array( $key, self::KEYS, true ) || ! array_key_exists( 'value', $p ) || ( null !== $p['value'] && ! is_numeric( $p['value'] ) && ! is_bool( $p['value'] ) ) ) {
			return self::error( 'invalid_param', 400 );
		}
		$value = null === $p['value'] ? null : ( is_bool( $p['value'] ) ? ( $p['value'] ? 1 : 0 ) : (int) $p['value'] );
		$r     = self::hub( 'PATCH', $b['bridge_id'], array( 'key' => $key, 'value' => $value ) );
		if ( empty( $r['ok'] ) ) {
			$code   = preg_replace( '/[^a-z0-9_]/', '', strtolower( (string) ( $r['code'] ?? 'hub_unavailable' ) ) );
			$status = in_array( $code, array( 'out_of_range', 'invalid_param', 'unknown_key' ), true ) ? 400 : ( 'locked' === $code ? 409 : 502 );
			return new WP_REST_Response( array( 'ok' => false, 'code' => $code, 'message' => (string) ( $r['message'] ?? self::MESSAGES['hub_unavailable'][0] ), 'hint' => 'Sửa lại ô này rồi bấm Xem & lưu.', 'help_code' => 'S127-C5-' . $status ), $status );
		}
		return new WP_REST_Response( self::view( $r ) + array( 'can_edit' => true ), 200 );
	}

	/** chỉ các trường đã biết (không chép nguyên response của cell) */
	public static function view( array $r ): array {
		$layer = static function ( $x ) {
			$x = is_array( $x ) ? $x : array();
			return array( 'value' => $x['value'] ?? null, 'origin' => (string) ( $x['origin'] ?? '' ) );
		};
		return array(
			'ok'       => true,
			'contract' => 'agent-config-view@1.0.0',
			'limits'   => array_values( array_map( static function ( $l ) use ( $layer ) {
				return array( 'key' => (string) ( $l['key'] ?? '' ), 'label' => (string) ( $l['label'] ?? '' ), 'unit' => (string) ( $l['unit'] ?? '' ), 'min' => (int) ( $l['min'] ?? 0 ), 'max' => (int) ( $l['max'] ?? 0 ), 'default' => (int) ( $l['default'] ?? 0 ), 'cell' => $layer( $l['cell'] ?? null ), 'identity' => isset( $l['identity'] ) ? (int) $l['identity'] : null, 'effective' => $layer( $l['effective'] ?? null ) );
			}, array_filter( (array) ( $r['limits'] ?? array() ), 'is_array' ) ) ),
			'switches' => array_values( array_map( static function ( $s ) use ( $layer ) {
				return array( 'key' => (string) ( $s['key'] ?? '' ), 'label' => (string) ( $s['label'] ?? '' ), 'cell' => $layer( $s['cell'] ?? null ), 'identity' => isset( $s['identity'] ) ? (bool) $s['identity'] : null, 'effective' => $layer( $s['effective'] ?? null ) );
			}, array_filter( (array) ( $r['switches'] ?? array() ), 'is_array' ) ) ),
			'purposes' => array_values( array_map( static function ( $p ) {
				return array( 'purpose' => (string) ( $p['purpose'] ?? '' ), 'model' => (string) ( $p['model'] ?? '' ), 'origin' => (string) ( $p['origin'] ?? '' ), 'locked' => isset( $p['locked'] ) ? (string) $p['locked'] : null );
			}, array_filter( (array) ( $r['purposes'] ?? array() ), 'is_array' ) ) ),
			'fixed'    => array_values( array_map( static function ( $f ) {
				return array( 'key' => (string) ( $f['key'] ?? '' ), 'value' => (int) ( $f['value'] ?? 0 ), 'why' => (string) ( $f['why'] ?? '' ) );
			}, array_filter( (array) ( $r['fixed'] ?? array() ), 'is_array' ) ) ),
		);
	}

	/* ── helpers (cùng nếp BizCity_Zalo_Schedule_REST) ─────────────── */

	private static function hub( string $method, string $bridge, array $body = array(), array $query = array() ): array {
		if ( isset( self::$readers['hub'] ) ) {
			return (array) call_user_func( self::$readers['hub'], $method, $bridge, $body, $query );
		}
		if ( ! class_exists( 'BizCity_Zalo_Personal_Hub_Client' ) || ! method_exists( 'BizCity_Zalo_Personal_Hub_Client', 'agent_config_call' ) ) {
			return array( 'ok' => false, 'code' => 'hub_client_missing' );
		}
		return BizCity_Zalo_Personal_Hub_Client::instance()->agent_config_call( $method, $bridge, $body, $query );
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
		return array( 'binding_id' => $id, 'bridge_id' => $bridge );
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

	private static function error( string $code, int $status, array $extra = array() ): WP_REST_Response {
		$m = self::MESSAGES[ $code ] ?? array( 'Không thực hiện được.', 'Thử lại sau.' );
		return new WP_REST_Response( array( 'ok' => false, 'code' => $code, 'message' => $m[0], 'hint' => $m[1], 'help_code' => 'S127-C5-' . $status ) + $extra, $status );
	}
}
