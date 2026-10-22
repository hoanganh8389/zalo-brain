<?php
/**
 * BizCity_Zalo_Schedule_REST — Channel Gateway › số Zalo › tab "Lịch" (PHASE-0.127 S127-C7, D127-15, doc 56 §2, ma trận R-CONFIG-MATRIX).
 *
 *   GET    bizcity-channel/v1/bot/policy/{binding_id}/schedule                  mọi lịch của số + dòng hệ thống (cell là chủ lịch — D-SCH-CELL-1)
 *   POST   bizcity-channel/v1/bot/policy/{binding_id}/schedule/{job}            MỘT ô: {enabled} | {minutes} | {cron} (sửa lịch chủ tạo bằng chat)
 *   DELETE bizcity-channel/v1/bot/policy/{binding_id}/schedule/{job}            xoá lịch (R2 — chủ đã xem trước/sau ở ma trận)
 *   POST   bizcity-channel/v1/bot/policy/{binding_id}/schedule/{job}/run        chạy thử ngay
 *   POST   bizcity-channel/v1/bot/policy/{binding_id}/schedule/system/idle      {hours} (null = về mặc định) — "Phiên mới khi nghỉ" của số
 *
 * Đường: site → Hub (`zalo-personal-bridge/brain/schedule…`, danh sách đường cứng ở Hub client `schedule_call`) → cell `/wp/brain/schedule…`.
 * Không lưu gì ở site. Trình duyệt KHÔNG thấy mã hội thoại (thread) / UID: mỗi lần sửa, site đọc lại danh sách ở cell và tự tìm thread của lịch.
 * Dòng `origin = site` (đẩy lên từ Lịch site) sửa ở nguồn site ⇒ khoá có lý do. Quyền như tab "Nhóm": quản trị site / supervisor CRM sửa; nhân sự của số chỉ xem.
 *
 * Biz Central Brain — Johnny Chu (Chu Hoàng Anh).
 *
 * [2026-10-21 11:55 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.127 S127-C7 — new file.
 *
 * @package Bizcity_Twin_AI
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Zalo_Schedule_REST', false ) ) {
	return;
}

final class BizCity_Zalo_Schedule_REST {

	const NS       = 'bizcity-channel/v1';
	const BASE     = '/bot/policy/(?P<binding_id>\d+)/schedule';
	const JOB_RE   = '[A-Za-z0-9_-]{1,64}';
	const PLATFORM = 'ZALO_PERSONAL';
	/** brain-schedule@1: một lịch lặp không dày hơn 15 phút (cell kiểm lại bằng SCHEDULER_MIN_INTERVAL_MINUTES) */
	const MIN_EVERY = 15;

	const MESSAGES = array(
		'schedule_forbidden'      => array( 'Cần quyền quản trị site hoặc supervisor CRM để đổi lịch của số.', 'Nhân sự của số chỉ xem được; nhờ quản trị đổi giúp.' ),
		'schedule_view_forbidden' => array( 'Bạn không phải nhân sự của số này.', 'Nhờ chủ số thêm bạn vào danh sách nhân sự (tab Người dùng).' ),
		'binding_missing'         => array( 'Số chưa được gắn Agent Guru.', 'Lưu cài đặt bot của số một lần rồi quay lại.' ),
		'not_zalo_hub'            => array( 'Chỉ số chạy Zalo Hub mới có lịch của trợ lý.', 'Số này vẫn trả lời khách bình thường.' ),
		'hub_unavailable'         => array( 'Chưa đọc được lịch từ trợ lý (Hub / cell không trả lời).', 'Bấm Thử lại sau ít phút.' ),
		'job_not_found'           => array( 'Không thấy lịch này nữa (có thể vừa bị xoá trong chat).', 'Tải lại danh sách.' ),
		'job_locked_site'         => array( 'Lịch này đến từ Lịch của website.', 'Sửa ở Lịch website; cell nhận bản mới tự động.' ),
		'invalid_param'           => array( 'Giá trị không hợp lệ.', 'Sửa lại ô này rồi bấm Xem & lưu.' ),
	);

	/** Test seams: binding(id) · can_manage() · current_user() · provider(bridge) · staff_uid(bridge, user_id) · hub(method, path, bridge, query, body) */
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
		register_rest_route( self::NS, self::BASE . '/system/idle', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'handle_idle' ), 'permission_callback' => $logged_in ) );
		register_rest_route( self::NS, self::BASE . '/(?P<job>' . self::JOB_RE . ')', array(
			array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'handle_save' ), 'permission_callback' => $logged_in ),
			array( 'methods' => 'DELETE', 'callback' => array( __CLASS__, 'handle_delete' ), 'permission_callback' => $logged_in ),
		) );
		register_rest_route( self::NS, self::BASE . '/(?P<job>' . self::JOB_RE . ')/run', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'handle_run' ), 'permission_callback' => $logged_in ) );
	}

	public static function handle_list( WP_REST_Request $req ): WP_REST_Response {
		$b = self::binding( (int) $req->get_param( 'binding_id' ) );
		if ( $b instanceof WP_REST_Response ) {
			return $b;
		}
		$manage = self::can_manage();
		if ( ! $manage && '' === self::staff_uid( $b['bridge_id'], self::current_user() ) ) {
			return self::error( 'schedule_view_forbidden', 403 );
		}
		// "fresh" = vừa lưu ⇒ bỏ qua bộ đệm 15 s của Hub (khoá đệm gồm cả query)
		$query = $req->get_param( 'fresh' ) ? array( 'fresh' => (string) time() ) : array();
		$raw   = self::hub( 'GET', 'schedule', $b['bridge_id'], $query );
		if ( empty( $raw['ok'] ) || ! isset( $raw['items'] ) || ! is_array( $raw['items'] ) ) {
			return self::error( 'hub_unavailable', 502, array( 'code_hub' => (string) ( $raw['code'] ?? '' ) ) );
		}
		$items = array_map( array( __CLASS__, 'view' ), array_values( array_filter( $raw['items'], 'is_array' ) ) );
		return new WP_REST_Response( array(
			'ok'       => true,
			'contract' => 'zalo-schedule-view@1.0.0',
			'can_edit' => $manage,
			'items'    => $items,
			'system'   => array_map( array( __CLASS__, 'system_view' ), array_values( array_filter( (array) ( $raw['system'] ?? array() ), 'is_array' ) ) ),
			'timezone' => (string) ( $raw['timezone'] ?? 'Asia/Ho_Chi_Minh' ),
			'limits'   => array( 'min_every_minutes' => self::MIN_EVERY ),
		), 200 );
	}

	/** MỘT ô: enabled (bool) · minutes (lịch "every") · cron (lịch "cron", 5 trường) */
	public static function handle_save( WP_REST_Request $req ): WP_REST_Response {
		$ctx = self::editable_job( $req );
		if ( $ctx instanceof WP_REST_Response ) {
			return $ctx;
		}
		$p    = (array) $req->get_json_params();
		$body = array( 'conversation_id' => $ctx['thread'] );
		if ( array_key_exists( 'enabled', $p ) ) {
			$body['enabled'] = (bool) $p['enabled'];
		} elseif ( array_key_exists( 'minutes', $p ) ) {
			$m = (int) $p['minutes'];
			if ( $m < self::MIN_EVERY || $m > 525600 || 'every' !== $ctx['job']['scheduleKind'] ) {
				return self::error( 'invalid_param', 400, array( 'field' => 'minutes' ) );
			}
			$body['schedule'] = array( 'kind' => 'every', 'minutes' => $m );
		} elseif ( array_key_exists( 'cron', $p ) ) {
			$cron = trim( preg_replace( '/\s+/', ' ', (string) $p['cron'] ) );
			if ( 1 !== preg_match( '/^[0-9*,\/-]+( [0-9*,\/-]+){4}$/', $cron ) || 'cron' !== $ctx['job']['scheduleKind'] ) {
				return self::error( 'invalid_param', 400, array( 'field' => 'cron' ) );
			}
			$body['schedule'] = array( 'kind' => 'cron', 'expr' => $cron );
		} else {
			return self::error( 'invalid_param', 400 );
		}
		$r = self::hub( 'PATCH', 'schedule/' . $ctx['id'], $ctx['bridge'], array(), $body );
		if ( empty( $r['ok'] ) ) {
			return self::relay_error( $r );
		}
		return new WP_REST_Response( array( 'ok' => true, 'item' => is_array( $r['job'] ?? null ) ? self::view( $r['job'] ) : null ), 200 );
	}

	public static function handle_delete( WP_REST_Request $req ): WP_REST_Response {
		$ctx = self::editable_job( $req );
		if ( $ctx instanceof WP_REST_Response ) {
			return $ctx;
		}
		$r = self::hub( 'DELETE', 'schedule/' . $ctx['id'], $ctx['bridge'], array( 'conversation_id' => $ctx['thread'] ) );
		return empty( $r['ok'] ) ? self::relay_error( $r ) : new WP_REST_Response( array( 'ok' => true, 'deleted' => $ctx['id'] ), 200 );
	}

	public static function handle_run( WP_REST_Request $req ): WP_REST_Response {
		$ctx = self::editable_job( $req, false );
		if ( $ctx instanceof WP_REST_Response ) {
			return $ctx;
		}
		$r = self::hub( 'POST', 'schedule/' . $ctx['id'] . '/run', $ctx['bridge'], array(), array( 'conversation_id' => $ctx['thread'] ) );
		if ( empty( $r['ok'] ) ) {
			return self::relay_error( $r );
		}
		$run = is_array( $r['run'] ?? null ) ? $r['run'] : array();
		return new WP_REST_Response( array( 'ok' => true, 'run' => array( 'status' => (string) ( $run['status'] ?? '' ), 'error' => self::clip( (string) ( $run['error'] ?? '' ), 160 ) ) ), 200 );
	}

	public static function handle_idle( WP_REST_Request $req ): WP_REST_Response {
		$b = self::binding( (int) $req->get_param( 'binding_id' ) );
		if ( $b instanceof WP_REST_Response ) {
			return $b;
		}
		if ( ! self::can_manage() ) {
			return self::error( 'schedule_forbidden', 403 );
		}
		$p     = (array) $req->get_json_params();
		$hours = array_key_exists( 'hours', $p ) && null !== $p['hours'] ? (int) $p['hours'] : null;
		if ( null !== $hours && ( $hours < 1 || $hours > 48 ) ) {
			return self::error( 'invalid_param', 400, array( 'field' => 'hours' ) );
		}
		$r = self::hub( 'PATCH', 'schedule/system/idle', $b['bridge_id'], array(), array( 'hours' => $hours ) );
		if ( empty( $r['ok'] ) ) {
			return self::relay_error( $r );
		}
		return new WP_REST_Response( array( 'ok' => true, 'system' => array_map( array( __CLASS__, 'system_view' ), array_values( array_filter( (array) ( $r['system'] ?? array() ), 'is_array' ) ) ) ), 200 );
	}

	/* ── view (không UID, không mã hội thoại) ──────────────────────── */

	/** @param array<string,mixed> $j ScheduledJob của cell */
	public static function view( array $j ): array {
		$kind  = (string) ( $j['scheduleKind'] ?? '' );
		$type  = ! empty( $j['skillName'] ) ? 'skill' : ( ! empty( $j['publishScenario'] ) ? 'scenario' : ( ! empty( $j['workflowId'] ) ? 'workflow' : ( 'agent' === ( $j['kind'] ?? '' ) ? 'task' : 'reminder' ) ) );
		$site  = 'site' === (string) ( $j['origin'] ?? '' );
		return array(
			'id'           => (string) ( $j['id'] ?? '' ),
			'name'         => self::clip( (string) ( $j['name'] ?? '' ), 100 ),
			'type'         => $type,
			'skill'        => self::clip( (string) ( $j['skillName'] ?? '' ), 64 ),
			'preview'      => self::clip( wp_strip_all_tags( (string) ( $j['payload'] ?? '' ) ), 80 ),
			'target'       => 1 === (int) ( $j['threadType'] ?? 0 ) ? 'group' : 'owner',
			'when'         => array(
				'kind'    => $kind,
				'cron'    => 'cron' === $kind ? (string) ( $j['cronExpr'] ?? '' ) : null,
				'minutes' => 'every' === $kind ? (int) ( $j['everyMinutes'] ?? 0 ) : null,
				'run_at'  => 'once' === $kind ? (string) ( $j['runAt'] ?? '' ) : null,
			),
			'enabled'      => ! empty( $j['enabled'] ),
			'next_run_at'  => (string) ( $j['nextRunAt'] ?? '' ),
			'last_run_at'  => (string) ( $j['lastRunAt'] ?? '' ),
			'last_status'  => (string) ( $j['lastStatus'] ?? '' ),
			'last_error'   => self::clip( (string) ( $j['lastError'] ?? '' ), 120 ),
			'run_count'    => (int) ( $j['runCount'] ?? 0 ),
			'origin'       => $site ? 'site' : (string) ( $j['createdBy'] ?? 'chat' ),
			'locked'       => $site ? self::MESSAGES['job_locked_site'][0] : '',
		);
	}

	public static function system_view( array $s ): array {
		$out = array( 'id' => (string) ( $s['id'] ?? '' ), 'name' => self::clip( (string) ( $s['name'] ?? '' ), 80 ), 'editable' => array_values( array_map( 'strval', (array) ( $s['editable'] ?? array() ) ) ) );
		if ( isset( $s['hours'] ) ) {
			$out += array( 'hours' => (int) $s['hours'], 'origin' => (string) ( $s['origin'] ?? '' ), 'min' => (int) ( $s['min'] ?? 1 ), 'max' => (int) ( $s['max'] ?? 48 ) );
		}
		if ( isset( $s['every_minutes'] ) ) {
			$out['every_minutes'] = (int) $s['every_minutes'];
		}
		return $out;
	}

	/* ── helpers ──────────────────────────────────────────────────── */

	/** quyền sửa + lịch có thật ở cell + không phải dòng site ⇒ {id, bridge, thread, job} */
	private static function editable_job( WP_REST_Request $req, bool $edit = true ) {
		$b = self::binding( (int) $req->get_param( 'binding_id' ) );
		if ( $b instanceof WP_REST_Response ) {
			return $b;
		}
		if ( ! self::can_manage() ) {
			return self::error( 'schedule_forbidden', 403 );
		}
		$id = (string) $req->get_param( 'job' );
		if ( 1 !== preg_match( '/^' . self::JOB_RE . '$/', $id ) ) {
			return self::error( 'job_not_found', 404 );
		}
		$raw = self::hub( 'GET', 'schedule', $b['bridge_id'], array( 'fresh' => (string) time() ) );
		if ( empty( $raw['ok'] ) || ! is_array( $raw['items'] ?? null ) ) {
			return self::error( 'hub_unavailable', 502 );
		}
		foreach ( $raw['items'] as $j ) {
			if ( is_array( $j ) && (string) ( $j['id'] ?? '' ) === $id ) {
				if ( $edit && 'site' === (string) ( $j['origin'] ?? '' ) ) {
					return self::error( 'job_locked_site', 409 );
				}
				return array( 'id' => $id, 'bridge' => $b['bridge_id'], 'thread' => (string) ( $j['threadId'] ?? '' ), 'job' => $j );
			}
		}
		return self::error( 'job_not_found', 404 );
	}

	private static function hub( string $method, string $path, string $bridge, array $query = array(), array $body = array() ): array {
		if ( isset( self::$readers['hub'] ) ) {
			return (array) call_user_func( self::$readers['hub'], $method, $path, $bridge, $query, $body );
		}
		if ( ! class_exists( 'BizCity_Zalo_Personal_Hub_Client' ) || ! method_exists( 'BizCity_Zalo_Personal_Hub_Client', 'schedule_call' ) ) {
			return array( 'ok' => false, 'code' => 'hub_client_missing' );
		}
		return BizCity_Zalo_Personal_Hub_Client::instance()->schedule_call( $method, $path, $bridge, $query, $body );
	}

	/** lỗi cell / Hub ⇒ R-ERROR-UX (giữ lời của cell khi có: "Biểu thức cron…", "Lịch hẹn đang chạy…") */
	private static function relay_error( array $r ): WP_REST_Response {
		$code   = preg_replace( '/[^a-z0-9_]/', '', strtolower( (string) ( $r['code'] ?? 'hub_unavailable' ) ) );
		$status = in_array( $code, array( 'invalid_schedule', 'invalid_param', 'out_of_range', 'job_cap' ), true ) ? 400 : ( in_array( $code, array( 'job_running', 'not_editable' ), true ) ? 409 : ( 'job_not_found' === $code ? 404 : 502 ) );
		$msg    = (string) ( $r['message'] ?? $r['error'] ?? '' );
		return new WP_REST_Response( array( 'ok' => false, 'code' => '' !== $code ? $code : 'hub_unavailable', 'message' => '' !== $msg ? self::clip( $msg, 200 ) : self::MESSAGES['hub_unavailable'][0], 'hint' => self::clip( (string) ( $r['hint'] ?? self::MESSAGES['hub_unavailable'][1] ), 200 ), 'help_code' => 'S127-C7-' . $status ), $status );
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

	/** UID Zalo của nhân sự (WP user) ở số này — chỉ để biết người này có thuộc số không; KHÔNG trả ra trình duyệt */
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

	private static function clip( string $s, int $n ): string {
		return function_exists( 'mb_substr' ) ? mb_substr( $s, 0, $n ) : substr( $s, 0, $n );
	}

	private static function error( string $code, int $status, array $extra = array() ): WP_REST_Response {
		$m = self::MESSAGES[ $code ] ?? array( 'Không thực hiện được.', 'Thử lại sau.' );
		return new WP_REST_Response( array( 'ok' => false, 'code' => $code, 'message' => $m[0], 'hint' => $m[1], 'help_code' => 'S127-C7-' . $status ) + $extra, $status );
	}
}
