<?php
/**
 * bizcity-panel/v1 — what an owner panel opened from a Zalo link may call (PHASE-0.122 doc 20 §4.5, R-PL-6).
 *
 * A thin front: every route calls the service that already exists; nothing is re-implemented. Auth = the panel cookie (never a
 * WordPress login) + header `X-BizCity-Panel: 1` (forces a CORS preflight, so no other site can drive it) + the panel's group.
 * Inside the callback the panel's person becomes the current user for THIS request only, so the old services run unchanged and
 * keep their own permission checks (quyền chỉ bớt: a person without the channel grant still cannot connect).
 *
 *   GET  session                         every group   panel, group, label, who (masked), expires_at, panels of the group
 *   GET  key  · POST key/reset           every group   open sessions / waiting links / log · rotate the key now
 *   GET  connect/status                  connect       BizCity_Connect_Composio_REST (status of MY logins)
 *   POST connect/start {toolkit}         connect       start a Composio login, callback = this panel page
 *   POST connect/refresh {connected_account_id}  connect
 *   PUT  connect/default {connected_account_id}  connect
 *   GET  jobs · GET jobs/{id}            jobs          BizCity_Job_View_REST
 *   GET  jobs/next                       jobs          the person's calendar for 14 days (cell reminders mirrored as cell_job + site events) — read only
 *   GET  brain/overview · brain/find · brain/{id}   brain   BizCity_Journal_REST (the person's own register)
 *   PATCH brain/{id} {pinned|review}     brain         nothing else (delete = "XOÁ <mã>" in Zalo, D122-14)
 *   GET  brain/{id}/file                 brain         15-minute signed link of the private original
 *   GET  brain/map · PUT brain/map/receipt   brain     brain-map@1 of the person (BizCity_Journal_Brain_Map) · map link in the receipt on/off (PHASE-0.129)
 * [2026-10-18 07:55 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.122 S122-F2/F3 — jobs.next, brain.docs, brain.notes.
 *
 * [2026-10-18 05:10 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.122 S122-P3 — new file. Loaded once by require_once in bootstrap.php.
 *
 * @package BizCity_Twin_AI
 * @subpackage Channel_Gateway
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_Panel_REST {

	const NS     = 'bizcity-panel/v1';
	const HEADER = 'x-bizcity-panel';

	/** @var array|null the session of this request, set by the permission callback */
	private static $session = null;

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes(): void {
		$routes = array(
			array( '/session', 'GET', 'get_session', '' ),
			array( '/key', 'GET', 'get_key', '' ),
			array( '/key/reset', 'POST', 'post_key_reset', '' ),
			// [2026-10-23 08:14 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.128 S128-CL4 / U8 — "Thiết bị đã ghi nhớ" of the Link screen: list · forget one (forget all = key reset)
			array( '/devices', 'GET', 'devices', '' ),
			array( '/devices/(?P<id>[0-9a-f]{10})/forget', 'POST', 'device_forget', '' ),
			array( '/connect/status', 'GET', 'connect_status', 'connect' ),
			array( '/connect/start', 'POST', 'connect_start', 'connect' ),
			array( '/connect/refresh', 'POST', 'connect_refresh', 'connect' ),
			array( '/connect/default', 'PUT', 'connect_default', 'connect' ),
			array( '/connect/facebook/pages', 'GET', 'fb_pages', 'connect' ), // [2026-10-18 10:00 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.122 W4 — pick the Fanpage(s) (shop owner / admin only)
			array( '/connect/facebook/pages', 'PUT', 'fb_pages_select', 'connect' ),
			array( '/connect/facebook/pages/(?P<page>[0-9]{5,30})/mode', 'PUT', 'fb_page_mode', 'connect' ),
			array( '/jobs', 'GET', 'jobs', 'jobs' ),
			array( '/jobs/next', 'GET', 'jobs_next', 'jobs' ), // [2026-10-18 07:55 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.122 S122-F2 (registered before jobs/{id})
			array( '/brain/overview', 'GET', 'brain_overview', 'brain' ), // [2026-10-18 07:55 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.122 S122-F3
			array( '/brain/find', 'GET', 'brain_find', 'brain' ),
			array( '/brain/map', 'GET', 'brain_map', 'brain' ), // [2026-10-22 01:50 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.129 W2 — Bản đồ não (registered before brain/{id})
			array( '/brain/map/receipt', 'PUT', 'brain_map_receipt', 'brain' ), // [2026-10-22 01:50 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.129 W2 — "Tắt / bật gửi bản đồ" after each save (the person's own notification)
			array( '/brain/(?P<id>[0-9]+)', 'GET', 'brain_get', 'brain' ),
			array( '/brain/(?P<id>[0-9]+)', 'PATCH', 'brain_patch', 'brain' ),
			array( '/brain/(?P<id>[0-9]+)/file', 'GET', 'brain_file', 'brain' ),
			array( '/brain/note', 'POST', 'brain_note', 'brain' ), // [2026-10-18 09:00 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.122 S122-F4 — a text note (R1)
			array( '/brain/(?P<id>[0-9]+)/title', 'PUT', 'brain_title', 'brain' ), // S122-F4 — rename an entry (R1)
			array( '/users', 'GET', 'users', 'users' ), // [2026-10-18 09:00 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.122 S122-F5 — read only; every change goes through Zalo (R3, two steps)
			array( '/jobs/(?P<id>job_[0-9A-Za-z]{10,40})/answer', 'POST', 'job_answer', 'jobs' ),
			array( '/jobs/(?P<id>job_[0-9A-Za-z]{10,40})/control', 'POST', 'job_control', 'jobs' ), // [2026-10-21 09:05 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.125 C3 (D125-19) — pause / resume / cancel / skip / note from the Quest Map // [2026-10-21 11:21 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.125 Q1.7 C2 (Q125-17) — answer the background job from the Quest Map
			array( '/jobs/(?P<id>job_[0-9A-Za-z]{10,40})/feedback', 'POST', 'job_feedback', 'jobs' ), // [2026-10-21 11:04 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.127 S127-C3 — 👍 / 👎 / "sửa…" on a finished job from the Quest Map
			array( '/jobs/(?P<id>[A-Za-z0-9_.:\-]{4,80})', 'GET', 'job', 'jobs' ),
			array( '/tz/ask', 'POST', 'tz_ask', 'twinzalo' ), // [2026-10-22 08:50 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.128 TwinZalo U5 — the ask sheet = Cell Chat of the owner's number (SSE)
			// [2026-10-22 11:46 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.128 TwinZalo U15 ⑭ · U17 ⑱ — views built by BizCity_Panel_TZ_Views (web-83: cell → Hub → site); admin check for accounts is inside it
			array( '/tz/track', 'GET', 'tz_track', 'twinzalo' ),
			array( '/tz/accounts', 'GET', 'tz_accounts', 'twinzalo' ),
			// [2026-10-23 07:24 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.128 TwinZalo U17 ⑱ "Thêm số QR" (chủ "code tiếp" = đề xuất 1: route panel bọc luồng QR, chỉ admin) —
			// wraps the wp-admin zalo-bridge routes with rest_do_request as the panel person, so their own `can_manage` check still decides; only numbers this person owns
			// [2026-10-23 07:36 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.128 TwinZalo U10 ④ — the person's pending Composio drafts (email · Fanpage · sheet) next to the job questions;
			// approve = the only send path of the cell (R2), reject, or approve with corrected text. Same composio pipe as job_answer (the cell scopes them to this person).
			array( '/drafts', 'GET', 'drafts', 'connect' ),
			// [2026-10-23 07:40 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.128 TwinZalo ② U9 (Q128-9) — the person's own Google Calendar (Composio read `calendar.events`, next 7 days)
			array( '/calendar/google', 'GET', 'calendar_google', 'connect' ),
			array( '/drafts/(?P<id>act_[A-Za-z0-9_-]{8,40})/(?P<decision>approve|reject)', 'POST', 'draft_decide', 'connect' ),
			array( '/tz/number', 'POST', 'tz_number_create', 'twinzalo' ),
			array( '/tz/number/(?P<id>[A-Za-z0-9_.:\-]{1,80})/qr', 'POST', 'tz_number_qr', 'twinzalo' ),
			array( '/tz/number/(?P<id>[A-Za-z0-9_.:\-]{1,80})/qr-status', 'GET', 'tz_number_qr_status', 'twinzalo' ),
		);
		foreach ( $routes as $r ) {
			$group = $r[3];
			$cb    = $r[2];
			register_rest_route( self::NS, $r[0], array(
				'methods'             => $r[1],
				'callback'            => array( __CLASS__, $r[2] ),
				// [2026-10-22 06:45 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.129 reflect #1 — + the route's callback, so a brain.map session reaches only the map routes
				'permission_callback' => static function ( WP_REST_Request $request ) use ( $group, $cb ) { return self::perm( $request, $group, $cb ); },
			) );
		}
	}

	/**
	 * [2026-10-22 06:45 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.129 reflect #1 — panels that open UNASKED (the brain.map link rides every second-brain
	 * receipt) get a narrow session: only these callbacks, never the rest of their group (all originals, pin, rename, note). Pure.
	 */
	const PANEL_ROUTES = array(
		'brain.map' => array( 'get_session', 'get_key', 'post_key_reset', 'brain_map', 'brain_map_receipt', 'brain_file' ),
	);

	public static function panel_route_allowed( string $panel, string $callback ): bool {
		return ! isset( self::PANEL_ROUTES[ $panel ] ) || '' === $callback || in_array( $callback, self::PANEL_ROUTES[ $panel ], true );
	}

	/**
	 * Live session + our header + (when the route belongs to a group) that group. Pure decision in `allows()`; this binds the request.
	 *
	 * @return true|WP_Error
	 */
	public static function perm( WP_REST_Request $request, string $group, string $callback = '' ) {
		$s = BizCity_Panel_Gate::current_session();
		$v = self::allows( $s, (string) $request->get_header( self::HEADER ), $group );
		// [2026-10-22 06:45 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.129 reflect #1 — a narrow panel (brain.map) reaches only its own routes
		if ( true === $v && ! self::panel_route_allowed( (string) ( $s['panel'] ?? '' ), $callback ) ) {
			$v = 'wrong_group';
		}
		if ( true !== $v ) {
			return new WP_Error( $v, 'expired' === $v ? 'Bảng nhanh đã hết hạn hoặc đã bị khoá. Nhắn trợ lý trên Zalo để lấy link mới.' : 'Bảng nhanh này không mở được mục này.', array( 'status' => 'expired' === $v ? 401 : 403 ) );
		}
		// [2026-10-23 08:13 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.128 S128-CL4 (doc 67 B) — a session reopened by a remembered device is READ ONLY: any write route answers
		// `stepup_required` (the panel then asks the assistant for a fresh one-time link in Zalo = a write session). Asking the assistant is not a panel write:
		// the same person can already type it in their own Zalo, and the cell's own gates (R2 / R3) still ask. Revoking (key reset, forget a device) is always allowed.
		if ( true === $v && ! self::scope_allows( (string) ( $s['scope'] ?? 'write' ), strtoupper( (string) $request->get_method() ), $callback ) ) {
			return new WP_Error( 'stepup_required', 'Đang ở chế độ xem (thiết bị đã ghi nhớ). Thao tác này cần xác nhận qua Zalo.', array( 'status' => 403, 'hint' => 'Bấm "Xác nhận qua Zalo": em gửi link mới vào Zalo của anh, mở link đó là làm được.' ) );
		}
		self::$session = $s;
		wp_set_current_user( (int) $s['user_id'] );
		// [2026-10-18 11:00 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.122 review fix — the panel person is the current user for THIS route only; no later hook of the same request runs as them
		static $hooked = false;
		if ( ! $hooked ) {
			$hooked = true;
			add_filter( 'rest_post_dispatch', static function ( $result ) {
				wp_set_current_user( 0 );
				return $result;
			}, 999 );
		}
		return true;
	}

	/** S128-CL4 — what a READ-ONLY (device) session may still do besides reading: ask the assistant, revoke (key reset, forget a device). */
	const VIEW_WRITES_OK = array( 'tz_ask', 'post_key_reset', 'device_forget' );

	/** Pure: may a session of `$scope` call `$callback` with `$method`? A write session: everything; a view session: GET + VIEW_WRITES_OK. */
	public static function scope_allows( string $scope, string $method, string $callback ): bool {
		return 'view' !== $scope || 'GET' === $method || 'HEAD' === $method || in_array( $callback, self::VIEW_WRITES_OK, true );
	}

	/** Pure: true, or the refusal code (`expired` · `bad_header` · `wrong_group`). */
	public static function allows( ?array $session, string $header, string $group ) {
		if ( ! $session ) {
			return 'expired';
		}
		if ( '1' !== $header ) {
			return 'bad_header';
		}
		$sg = (string) ( $session['group'] ?? '' );
		// [2026-10-22 08:50 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.128 TwinZalo — a twinzalo session reaches the routes of its groups (jobs · brain · connect), not `users`
		if ( '' !== $group && $sg !== $group && ! ( 'twinzalo' === $sg && in_array( $group, BizCity_Panel_Link::TWINZALO_GROUPS, true ) ) ) {
			return 'wrong_group';
		}
		return true;
	}

	// ── session / key ────────────────────────────────────────────────────

	public static function get_session(): WP_REST_Response {
		$s     = self::$session;
		$cat   = BizCity_Panel_Link::catalog();
		$group = (string) $s['group'];
		$mine  = array();
		$narrow = isset( self::PANEL_ROUTES[ (string) $s['panel'] ] ); // [2026-10-22 06:45 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.129 reflect #1 — a narrow session offers only itself + the key
		foreach ( $cat as $id => $row ) {
			if ( BizCity_Panel_Link::group_allows( $group, $id ) && ( ! $narrow || $id === (string) $s['panel'] || 'key' === $id ) ) {
				$mine[] = array( 'id' => $id, 'label' => $row['label'], 'body' => $row['body'], 'toolkit' => $row['toolkit'] );
			}
		}
		return new WP_REST_Response( array(
			'ok'         => true,
			'contract'   => 'owner-panel@1.0.0',
			'panel'      => (string) $s['panel'],
			'group'      => $group,
			'label'      => (string) ( $cat[ (string) $s['panel'] ]['label'] ?? $s['panel'] ),
			'who'        => BizCity_Panel_Gate::masked_name( (int) $s['user_id'] ),
			'expires_at' => (int) $s['expires_at'],
			'panels'     => $mine,
			'focus'      => (string) ( $s['focus'] ?? '' ), // [2026-10-20 03:30 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.125 Q1 — the job of a Quest Map link
			'zalo_hint'  => self::suggestions( (string) $s['panel'] ),
			'scope'      => 'view' === ( $s['scope'] ?? '' ) ? 'view' : 'write', // [2026-10-23 08:14 PM Johnny Chu - Chu Hoàng Anh] S128-CL4 — view = reopened by a remembered device (read only)
		), 200 );
	}

	/** [2026-10-23 08:14 PM Johnny Chu - Chu Hoàng Anh] S128-CL4 — the person's remembered devices (no hash: a 10-char id), `current` = this browser. */
	public static function devices(): WP_REST_Response {
		return new WP_REST_Response( array( 'ok' => true, 'devices' => BizCity_Panel_Link::devices( (int) self::$session['user_id'], BizCity_Panel_Gate::current_device_cookie() ), 'ttl_days' => (int) ( BizCity_Panel_Link::DEVICE_TTL / 86400 ) ), 200 );
	}

	/** S128-CL4 — forget one remembered device (always allowed, even read only: it only takes access away). Forgetting THIS browser also drops its cookie. */
	public static function device_forget( WP_REST_Request $request ): WP_REST_Response {
		$uid = (int) self::$session['user_id'];
		$id  = (string) $request['id'];
		$cur = array_values( array_filter( BizCity_Panel_Link::devices( $uid, BizCity_Panel_Gate::current_device_cookie() ), static function ( $d ) use ( $id ) { return $d['id'] === $id && $d['current']; } ) );
		if ( ! BizCity_Panel_Link::forget_device( $uid, $id ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'not_found', 'message' => 'Không thấy thiết bị này.', 'hint' => 'Tải lại danh sách.' ), 404 );
		}
		if ( $cur ) {
			BizCity_Panel_Gate::set_cookie( '', 0, BizCity_Panel_Link::DEV_COOKIE );
		}
		return new WP_REST_Response( array( 'ok' => true, 'forgotten' => $id ), 200 );
	}

	public static function get_key(): WP_REST_Response {
		return new WP_REST_Response( array( 'ok' => true ) + BizCity_Panel_Link::state( (int) self::$session['user_id'], (string) self::$session['h'] ), 200 );
	}

	public static function post_key_reset(): WP_REST_Response {
		BizCity_Panel_Link::reset( (int) self::$session['user_id'], 'user' );
		BizCity_Panel_Gate::set_cookie( '', 0 );
		return new WP_REST_Response( array( 'ok' => true, 'reset' => true, 'message' => 'Đã đổi khoá. Mọi link và trang đang mở đều khoá lại. Cần mở lại thì nhắn trợ lý.' ), 200 );
	}

	// ── connect (Composio through the cell, same code as the Channel Gateway screen) ──

	public static function connect_status(): WP_REST_Response {
		$deny = self::channel_denied();
		return $deny ?: BizCity_Connect_Composio_REST::pipe( self::inner( 'GET', '/status' ) );
	}

	public static function connect_start( WP_REST_Request $request ): WP_REST_Response {
		$deny = self::channel_denied();
		if ( $deny ) {
			return $deny;
		}
		$toolkit = (string) ( ( (array) $request->get_json_params() )['toolkit'] ?? '' );
		if ( ! isset( BizCity_Panel_Link::catalog()[ 'connect.' . $toolkit ] ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'panel_unknown', 'message' => 'Ứng dụng này chưa nối được qua bảng nhanh.' ), 400 );
		}
		return BizCity_Connect_Composio_REST::start_connect( $toolkit, BizCity_Panel_Gate::open_url( 'connect.' . $toolkit, true ) );
	}

	public static function connect_refresh( WP_REST_Request $request ): WP_REST_Response {
		$deny = self::channel_denied();
		if ( $deny ) {
			return $deny;
		}
		// [2026-10-18 07:05 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.122 S122-B5 — the owner panel asks the cell to send the small-win note to Zalo when this login just became ACTIVE (once per login, cell-side)
		$body    = self::only( $request, array( 'connected_account_id', 'toolkit' ) );
		$toolkit = (string) ( $body['toolkit'] ?? '' );
		unset( $body['toolkit'] );
		$body['notify_owner'] = true;
		$body['suggestions']  = self::suggestions( 'connect.' . $toolkit );
		// Facebook: the note waits for the Fanpage pick (W4) — the cell answers small_win=needs_pages here
		return BizCity_Connect_Composio_REST::pipe( self::inner( 'POST', '/refresh', $body ) );
	}

	public static function connect_default( WP_REST_Request $request ): WP_REST_Response {
		$deny = self::channel_denied();
		return $deny ?: BizCity_Connect_Composio_REST::pipe( self::inner( 'PUT', '/default', self::only( $request, array( 'connected_account_id', 'toolkit' ) ) ) );
	}

	// ── Facebook Fanpage (PHASE-0.122 W4): the shop's login ⇒ only the site manager picks pages and the page mode ──

	/** Live pages of one Facebook login + which are selected. */
	public static function fb_pages( WP_REST_Request $request ): WP_REST_Response {
		$deny = self::shop_denied();
		if ( $deny ) {
			return $deny;
		}
		$conn = self::clean_id( (string) $request->get_param( 'connection' ) );
		if ( '' === $conn ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'invalid_param', 'message' => 'Thiếu tài khoản Facebook cần chọn trang.' ), 400 );
		}
		$r = self::inner( 'GET', '/facebook/pages' );
		$r->set_param( 'connection', $conn );
		return BizCity_Connect_Composio_REST::pipe( $r );
	}

	/** Select pages; the cell sends the small-win note to Zalo once a page is picked (once per login). */
	public static function fb_pages_select( WP_REST_Request $request ): WP_REST_Response {
		$deny = self::shop_denied();
		if ( $deny ) {
			return $deny;
		}
		$p    = (array) $request->get_json_params();
		$conn = self::clean_id( (string) ( $p['connected_account_id'] ?? '' ) );
		$ids  = self::page_ids( $p['page_ids'] ?? array() );
		if ( '' === $conn || null === $ids ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'invalid_param', 'message' => 'Chọn ít nhất một Fanpage hợp lệ.' ), 400 );
		}
		return BizCity_Connect_Composio_REST::pipe( self::inner( 'PUT', '/facebook/pages', array( 'connected_account_id' => $conn, 'page_ids' => $ids, 'notify_owner' => true, 'suggestions' => self::suggestions( 'connect.facebook' ) ) ) );
	}

	/** Page mode from the panel: only the two that never send to a customer — manual (nothing) and assisted (the cell drafts, the owner approves). Auto-send stays on the web screen. */
	public static function fb_page_mode( WP_REST_Request $request ): WP_REST_Response {
		$deny = self::shop_denied();
		if ( $deny ) {
			return $deny;
		}
		$mode = (string) ( ( (array) $request->get_json_params() )['mode'] ?? '' );
		if ( ! in_array( $mode, array( 'manual', 'assisted' ), true ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'invalid_param', 'message' => 'Bảng nhanh chỉ bật "chỉ xem" hoặc "tự soạn nháp". Chế độ tự gửi bật ở trang Công cụ trên website.' ), 400 );
		}
		return BizCity_Connect_Composio_REST::pipe( self::inner( 'PUT', '/pages/' . (string) $request['page'] . '/auto', array( 'mode' => $mode ) ) );
	}

	/** The shop's Facebook login belongs to the site manager (the cell refuses anyone else too). */
	private static function shop_denied(): ?WP_REST_Response {
		$deny = self::channel_denied();
		if ( $deny ) {
			return $deny;
		}
		if ( ! BizCity_Connect_REST::can_manage() ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'admin_only', 'message' => 'Fanpage là của cả cửa hàng: chỉ quản trị website chọn trang và đổi chế độ.', 'hint' => 'Nhờ chủ cửa hàng / quản trị làm bước này.' ), 403 );
		}
		return null;
	}

	/** Pure: a connected_account_id (Composio ca_…) or ''. */
	public static function clean_id( string $id ): string {
		return 1 === preg_match( '/^[A-Za-z0-9_-]{6,80}$/', $id ) ? $id : '';
	}

	/** Pure: 1–50 numeric Facebook page ids (deduplicated), else null. @return string[]|null */
	public static function page_ids( $raw ): ?array {
		if ( ! is_array( $raw ) ) {
			return null;
		}
		$out = array();
		foreach ( $raw as $id ) {
			if ( is_scalar( $id ) && 1 === preg_match( '/^[0-9]{5,30}$/', (string) $id ) ) {
				$out[ (string) $id ] = (string) $id;
			}
		}
		return $out && count( $out ) <= 50 ? array_values( $out ) : null;
	}

	// ── jobs ─────────────────────────────────────────────────────────────

	public static function jobs( WP_REST_Request $request ): WP_REST_Response {
		return BizCity_Job_View_REST::list_jobs( $request );
	}

	public static function job( WP_REST_Request $request ): WP_REST_Response {
		return BizCity_Job_View_REST::get_job( $request );
	}

	/** Next 14 days of the person's calendar. */
	/**
	 * [2026-10-21 11:21 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.125 Q1.7 C2 (Q125-16/17) — the owner answers their background job on the Quest Map:
	 * the open question (`ask_id` + option `n` and/or `text`) and/or corrections of the "em tạm hiểu" assumptions (`asks`). Rides the composio pipe
	 * (site → Hub → cell, `_user_ref` of the panel person); the CELL refuses anyone who did not give the job.
	 */
	public static function job_answer( WP_REST_Request $request ): WP_REST_Response {
		$p    = (array) $request->get_json_params();
		$body = array( 'job_id' => (string) $request->get_param( 'id' ) );
		if ( isset( $p['ask_id'] ) && is_string( $p['ask_id'] ) && 1 === preg_match( '/^ask_[0-9A-Za-z_.-]{3,120}$/', $p['ask_id'] ) ) {
			$body['ask_id'] = $p['ask_id'];
		}
		if ( isset( $p['n'] ) && is_numeric( $p['n'] ) ) {
			$body['n'] = max( 0, min( 9, (int) $p['n'] ) );
		}
		if ( isset( $p['text'] ) && is_string( $p['text'] ) && '' !== trim( $p['text'] ) ) {
			$body['text'] = mb_substr( sanitize_textarea_field( $p['text'] ), 0, 1500 );
		}
		foreach ( array_slice( is_array( $p['asks'] ?? null ) ? $p['asks'] : array(), 0, 3 ) as $a ) {
			if ( is_array( $a ) && 1 === preg_match( '/^m[1-9]$/', (string) ( $a['id'] ?? '' ) ) && is_string( $a['text'] ?? null ) && '' !== trim( $a['text'] ) ) {
				$body['asks'][] = array( 'id' => (string) $a['id'], 'text' => mb_substr( sanitize_text_field( $a['text'] ), 0, 300 ) );
			}
		}
		if ( ! isset( $body['ask_id'] ) && empty( $body['asks'] ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'invalid_param', 'message' => 'Chưa có câu trả lời nào.' ), 400 );
		}
		return BizCity_Connect_Composio_REST::pipe( self::inner( 'POST', '/quest/answer', $body ) );
	}

	/**
	 * [2026-10-21 09:05 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.125 C3 (D125-19) — the Quest Map's buttons and prompt box: `action` pause|resume|cancel|skip|note,
	 * `step_id` (skip), `text` (note). Same composio pipe as job_answer; the CELL refuses anyone who did not give the job.
	 * [2026-10-21 09:07 PM Johnny Chu - Chu Hoàng Anh] C3 — + `redo` (a finished step again, `step_id` + optional `text` = what to change).
	 */
	public static function job_control( WP_REST_Request $request ): WP_REST_Response {
		$p      = (array) $request->get_json_params();
		$action = (string) ( $p['action'] ?? '' );
		if ( ! in_array( $action, array( 'pause', 'resume', 'cancel', 'skip', 'redo', 'note' ), true ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'invalid_param', 'message' => 'Thao tác không hợp lệ.' ), 400 );
		}
		$body = array( 'job_id' => (string) $request->get_param( 'id' ), 'action' => $action );
		if ( in_array( $action, array( 'skip', 'redo' ), true ) && is_string( $p['step_id'] ?? null ) && 1 === preg_match( '/^[a-z][a-z0-9_]{0,39}$/', $p['step_id'] ) ) {
			$body['step_id'] = $p['step_id'];
		}
		if ( in_array( $action, array( 'note', 'redo' ), true ) && is_string( $p['text'] ?? null ) ) {
			$body['text'] = mb_substr( sanitize_textarea_field( $p['text'] ), 0, 500 );
		}
		return BizCity_Connect_Composio_REST::pipe( self::inner( 'POST', '/quest/control', $body ) );
	}

	/**
	 * [2026-10-21 11:04 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.127 S127-C3 (D127-8) — the owner's feedback on a FINISHED job from the Quest Map: `verdict` up|down|fix,
	 * `text` only with fix (3–500 chars, what to change). Same composio pipe as job_control; the CELL refuses anyone who did not give the job,
	 * a job that is not finished, and a second answer (one per job from the Map).
	 */
	public static function job_feedback( WP_REST_Request $request ): WP_REST_Response {
		$p       = (array) $request->get_json_params();
		$verdict = (string) ( $p['verdict'] ?? '' );
		if ( ! in_array( $verdict, array( 'up', 'down', 'fix' ), true ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'invalid_param', 'message' => 'Phản hồi không hợp lệ.' ), 400 );
		}
		$body = array( 'job_id' => (string) $request->get_param( 'id' ), 'verdict' => $verdict );
		if ( 'fix' === $verdict ) {
			$text = is_string( $p['text'] ?? null ) ? trim( mb_substr( sanitize_textarea_field( $p['text'] ), 0, 500 ) ) : '';
			if ( mb_strlen( $text ) < 3 ) {
				return new WP_REST_Response( array( 'ok' => false, 'code' => 'invalid_param', 'message' => 'Anh ghi điều cần sửa giúp em nhé.' ), 400 );
			}
			$body['text'] = $text;
		}
		return BizCity_Connect_Composio_REST::pipe( self::inner( 'POST', '/quest/feedback', $body ) );
	}

	public static function jobs_next(): WP_REST_Response {
		if ( ! class_exists( 'BizCity_Scheduler_Manager' ) ) {
			return new WP_REST_Response( array( 'ok' => true, 'items' => array(), 'note' => 'scheduler_off' ), 200 );
		}
		$now  = current_time( 'mysql' );
		$to   = gmdate( 'Y-m-d H:i:s', (int) strtotime( $now ) + 14 * DAY_IN_SECONDS );
		$rows = BizCity_Scheduler_Manager::instance()->get_events( get_current_user_id(), $now, $to, 'active' );
		$tz   = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
		return new WP_REST_Response( array( 'ok' => true, 'items' => self::next_rows( $rows, $tz ) ), 200 );
	}

	/** Pure: calendar rows ⇒ [{id, title, at (unix), kind}] soonest first, ≤ 30. `start_at` is the site's wall clock. */
	/**
	 * [2026-10-22 08:51 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.128 TwinZalo U5 (doc 68) — the ask sheet of TwinZalo is ONE Cell Chat turn (PHASE-0.105):
	 * the panel person's own Zalo 1-1 thread on the cell (same memory, same tools, same risk gates as typing in Zalo), streamed back as SSE
	 * (final_token · final_done · error · end). The user is the panel session's, never a body field; the number = the first one they OWN
	 * (none ⇒ the site's web home). Only a `twinzalo` session reaches this route. Streams and exits.
	 */
	public static function tz_ask( WP_REST_Request $request ): WP_REST_Response {
		$text = mb_substr( sanitize_textarea_field( (string) ( ( (array) $request->get_json_params() )['text'] ?? '' ) ), 0, 2000 );
		if ( 'twinzalo' !== (string) ( self::$session['panel'] ?? '' ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'wrong_panel', 'message' => 'Chỉ TwinZalo hỏi được ở đây.' ), 403 );
		}
		if ( ! class_exists( 'BizCity_Twin_Web_Turn' ) || ! method_exists( 'BizCity_Twin_Web_Turn', 'cell_chat_run' ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'cell_chat_missing', 'message' => 'Website chưa có Cell Chat.', 'hint' => 'Cập nhật plugin BizCity Twin AI rồi thử lại.' ), 503 );
		}
		$uid = (int) self::$session['user_id'];
		BizCity_Twin_Web_Turn::cell_chat_run( array(
			'user_id'    => $uid,
			'session_id' => '',
			'text'       => $text,
			'account_id' => self::tz_account( BizCity_Twin_Web_Turn::owned_rows( $uid ) ),
		) );
		exit;
	}

	/**
	 * [2026-10-22 11:46 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.128 TwinZalo U15 ⑭ — track-view@1.0.0 (week of jobs · gate rates · feedback) of the panel person.
	 * The view class (web-83) is a separate file; without it the route says so instead of fataling.
	 */
	public static function tz_track(): WP_REST_Response {
		if ( ! class_exists( 'BizCity_Panel_TZ_Views' ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'view_missing', 'message' => 'Website chưa có màn Theo dõi.', 'hint' => 'Cập nhật plugin BizCity Twin AI rồi mở lại.' ), 503 );
		}
		$v = BizCity_Panel_TZ_Views::track( (int) self::$session['user_id'] );
		return new WP_REST_Response( $v, (int) ( $v['status'] ?? 200 ) );
	}

	/** [2026-10-22 11:46 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.128 TwinZalo U17 ⑱ — bot-accounts@1.0.0; only a site admin gets rows (403 `admin_only` inside the view). */
	public static function tz_accounts(): WP_REST_Response {
		if ( ! class_exists( 'BizCity_Panel_TZ_Views' ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'view_missing', 'message' => 'Website chưa có màn Tài khoản bot.', 'hint' => 'Cập nhật plugin BizCity Twin AI rồi mở lại.' ), 503 );
		}
		$v = BizCity_Panel_TZ_Views::accounts( (int) self::$session['user_id'] );
		return new WP_REST_Response( $v, (int) ( $v['status'] ?? 200 ) );
	}

	// ── ④ Nháp chờ duyệt (U10) ────────────────────────────────────────────
	// [2026-10-23 07:36 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.128 TwinZalo — composio-connect@1 `GET /actions?status=pending` · `POST /actions/{id}/(approve|reject) {text?}`.

	/** GET drafts — the panel person's pending drafts (≤ 20), as the cell's action view (no token, no Composio id). */
	public static function drafts(): WP_REST_Response {
		if ( ! class_exists( 'BizCity_Connect_Composio_REST' ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'composio_missing', 'message' => 'Website chưa bật kết nối ứng dụng.', 'hint' => 'Cập nhật plugin BizCity Twin AI.' ), 503 );
		}
		$r = self::inner( 'GET', '/actions' );
		$r->set_param( 'status', 'pending' );
		$r->set_param( 'limit', 20 );
		return BizCity_Connect_Composio_REST::pipe( $r );
	}

	/**
	 * [2026-10-23 07:40 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.128 TwinZalo ② U9 — GET calendar/google: the cell reads the person's OWN Google Calendar (composio read
	 * `calendar.events`, 7 days, ≤ 25 events). Not connected / several Google logins ⇒ the cell's own answer (code + message + hint) goes back as it is.
	 */
	public static function calendar_google(): WP_REST_Response {
		if ( ! class_exists( 'BizCity_Connect_Composio_REST' ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'composio_missing', 'message' => 'Website chưa bật kết nối ứng dụng.' ), 503 );
		}
		return BizCity_Connect_Composio_REST::pipe( self::inner( 'POST', '/read', array( 'op' => 'calendar.events', 'input' => array( 'days' => 7, 'max_results' => 25 ) ) ) );
	}

	/** Pure: the body of a draft decision — `text` only when the owner corrected it (approve), ≤ 5000 chars. */
	public static function draft_body( string $decision, $p ): array {
		$text = 'approve' === $decision && is_array( $p ) && is_string( $p['text'] ?? null ) ? trim( (string) $p['text'] ) : '';
		return '' !== $text ? array( 'text' => mb_substr( sanitize_textarea_field( $text ), 0, 5000 ) ) : array();
	}

	/** POST drafts/{id}/(approve|reject) {text?} — approve sends (the cell's only send path), reject drops it. */
	public static function draft_decide( WP_REST_Request $request ): WP_REST_Response {
		if ( ! class_exists( 'BizCity_Connect_Composio_REST' ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'composio_missing', 'message' => 'Website chưa bật kết nối ứng dụng.' ), 503 );
		}
		$decision = (string) $request['decision'];
		return BizCity_Connect_Composio_REST::pipe( self::inner( 'POST', '/actions/' . (string) $request['id'] . '/' . $decision, self::draft_body( $decision, $request->get_json_params() ) ) );
	}

	// ── ⑱ Thêm số QR (U17) ────────────────────────────────────────────────
	// [2026-10-23 07:24 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.128 TwinZalo — the add-number flow of Bot Studio (AddZaloNumberSheet: members/{uid}/accounts → accounts/{id}/qr
	// → qr-status) for the panel person, from the phone. The original routes keep deciding (permission `can_manage`, quota, R-SETUP-4 checks inside create);
	// the panel only adds: site admin first, the number is the person's own (mapping owner_user_id), and a short answer (never a token, never a path).

	const NUMBER_NS = '/bizcity-channel/v1/zalo-bridge';

	/** Pure: the label of a new number from the request body ('' when missing), ≤ 60 chars, one line. */
	public static function tz_number_label( $p ): string {
		$raw = is_array( $p ) ? (string) ( $p['label'] ?? '' ) : '';
		return mb_substr( trim( (string) preg_replace( '/\s+/u', ' ', sanitize_text_field( $raw ) ) ), 0, 60 );
	}

	/** Pure: what the panel returns of a QR answer — the picture (base64 PNG), the status and an error code; nothing else of the sidecar's reply. */
	public static function tz_qr_view( array $d, int $status ): array {
		$qr   = (string) ( $d['qr_base64'] ?? '' );
		$code = (string) ( $d['code'] ?? $d['reason_bucket'] ?? '' );
		$ok   = $status < 400 && ( '' !== $qr || 'already_connected' === $code || ! empty( $d['status'] ) );
		$out  = array(
			'ok'     => $ok,
			'status' => substr( sanitize_key( (string) ( $d['status'] ?? ( 'already_connected' === $code ? 'connected' : ( '' !== $qr ? 'waiting' : '' ) ) ) ), 0, 30 ),
		);
		if ( '' !== $qr && 1 === preg_match( '/^(data:image\/png;base64,)?[A-Za-z0-9+\/=\r\n]{100,}$/', $qr ) ) {
			$out['qr_base64'] = $qr;
		}
		if ( ! $ok ) {
			$out['code']    = '' !== $code ? substr( sanitize_key( $code ), 0, 40 ) : 'qr_failed';
			$out['message'] = mb_substr( (string) ( $d['message'] ?? 'Chưa tạo được mã QR.' ), 0, 200 );
			$out['hint']    = mb_substr( (string) ( $d['hint'] ?? 'Thử lại sau ít phút, hoặc thêm số trong Bot Studio trên website.' ), 0, 200 );
		}
		return $out;
	}

	/** The person may add numbers (site admin — same capability the Bot Studio routes check) ⇒ null, else the 403 answer. */
	private static function tz_number_guard(): ?WP_REST_Response {
		$can = class_exists( 'BizCity_Zalo_Bridge_REST' ) && method_exists( 'BizCity_Zalo_Bridge_REST', 'can_manage' ) ? BizCity_Zalo_Bridge_REST::can_manage() : current_user_can( 'manage_options' );
		if ( ! $can ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'admin_only', 'message' => 'Chỉ quản trị viên website thêm được số Zalo.', 'hint' => 'Nhờ quản trị viên thêm số, hoặc nhắn trợ lý.' ), 403 );
		}
		return null;
	}

	/** The number `$id` is one this panel person owns (never a number of another member). */
	private static function tz_number_mine( string $id ): bool {
		if ( ! class_exists( 'BizCity_Zalo_Mapping_Repo' ) || ! method_exists( 'BizCity_Zalo_Mapping_Repo', 'find_account_by_bridge_id' ) ) {
			return false;
		}
		$a = BizCity_Zalo_Mapping_Repo::find_account_by_bridge_id( 'personal', $id );
		return is_array( $a ) && (int) ( $a['owner_user_id'] ?? 0 ) === (int) self::$session['user_id'];
	}

	/** One inner call to a zalo-bridge route as the current (panel) user; [data array, status]. */
	private static function tz_bridge( string $method, string $path, array $body = array() ): array {
		$r = new WP_REST_Request( $method, self::NUMBER_NS . $path );
		if ( $body ) {
			$r->set_header( 'content-type', 'application/json' );
			$r->set_body( (string) wp_json_encode( $body ) );
		}
		$res = rest_do_request( $r );
		$d   = $res->is_error() ? (array) ( $res->as_error()->get_error_data() ?: array() ) + array( 'code' => $res->as_error()->get_error_code(), 'message' => $res->as_error()->get_error_message() ) : (array) $res->get_data();
		return array( $d, (int) $res->get_status() );
	}

	/** POST tz/number {label} — create the number for the panel person, then start its QR (one tap on the phone). */
	public static function tz_number_create( WP_REST_Request $request ): WP_REST_Response {
		$deny = self::tz_number_guard();
		if ( $deny ) {
			return $deny;
		}
		$label = self::tz_number_label( $request->get_json_params() );
		if ( '' === $label ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'invalid_param', 'message' => 'Nhập tên cho số Zalo.', 'hint' => 'Ví dụ: "Nick CSKH chính".' ), 400 );
		}
		$uid          = (int) self::$session['user_id'];
		list( $c, $s ) = self::tz_bridge( 'POST', '/members/' . $uid . '/accounts', array( 'label' => $label, 'kind' => 'personal' ) );
		$id           = (string) ( $c['id'] ?? '' );
		if ( $s >= 400 || '' === $id ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => substr( sanitize_key( (string) ( $c['code'] ?? 'create_failed' ) ), 0, 40 ), 'message' => mb_substr( (string) ( $c['message'] ?? 'Chưa tạo được số.' ), 0, 200 ), 'hint' => mb_substr( (string) ( $c['hint'] ?? 'Kiểm tra hạn mức số của gói rồi thử lại.' ), 0, 200 ) ), $s >= 400 ? $s : 500 );
		}
		list( $q, $qs ) = self::tz_bridge( 'POST', '/accounts/' . rawurlencode( $id ) . '/qr' );
		return new WP_REST_Response( array( 'account_id' => $id, 'label' => $label ) + self::tz_qr_view( $q, $qs ), 200 );
	}

	/** POST tz/number/{id}/qr {relogin?} — a new QR for the person's own number (expired code, or sign the number in again). */
	public static function tz_number_qr( WP_REST_Request $request ): WP_REST_Response {
		$deny = self::tz_number_guard();
		if ( $deny ) {
			return $deny;
		}
		$id = (string) $request['id'];
		if ( ! self::tz_number_mine( $id ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'not_yours', 'message' => 'Đây không phải số Zalo của anh/chị.', 'hint' => 'Mở danh sách Tài khoản bot rồi chọn lại.' ), 403 );
		}
		$relogin        = ! empty( ( (array) $request->get_json_params() )['relogin'] );
		list( $q, $qs ) = self::tz_bridge( 'POST', '/accounts/' . rawurlencode( $id ) . ( $relogin ? '/qr/reset' : '/qr' ) );
		return new WP_REST_Response( array( 'account_id' => $id ) + self::tz_qr_view( $q, $qs ), 200 );
	}

	/** GET tz/number/{id}/qr-status — polled every 3 s while the QR is on screen. */
	public static function tz_number_qr_status( WP_REST_Request $request ): WP_REST_Response {
		$deny = self::tz_number_guard();
		if ( $deny ) {
			return $deny;
		}
		$id = (string) $request['id'];
		if ( ! self::tz_number_mine( $id ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'not_yours', 'message' => 'Đây không phải số Zalo của anh/chị.' ), 403 );
		}
		list( $q, $qs ) = self::tz_bridge( 'GET', '/accounts/' . rawurlencode( $id ) . '/qr-status' );
		return new WP_REST_Response( array( 'account_id' => $id ) + self::tz_qr_view( $q, $qs ), 200 );
	}

	/** Pure: the number TwinZalo asks through — the first the person OWNS, else '' (web home). Staff rows are not a TwinZalo owner's. */
	public static function tz_account( array $rows ): string {
		foreach ( $rows as $r ) {
			if ( 'owner' === (string) ( $r['role'] ?? '' ) && '' !== (string) ( $r['account_id'] ?? '' ) ) {
				return (string) $r['account_id'];
			}
		}
		return '';
	}

	public static function next_rows( array $rows, DateTimeZone $tz ): array {
		$out = array();
		foreach ( $rows as $r ) {
			$r = (array) $r;
			try {
				$at = ( new DateTimeImmutable( (string) ( $r['start_at'] ?? '' ), $tz ) )->getTimestamp();
			} catch ( Exception $e ) {
				continue;
			}
			$type  = (string) ( $r['event_type'] ?? '' );
			$out[] = array(
				'id'    => (int) ( $r['id'] ?? 0 ),
				'title' => mb_substr( (string) ( $r['title'] ?? '' ), 0, 120 ),
				'at'    => $at,
				'kind'  => 'cell_job' === $type || 'cell' === (string) ( $r['dispatcher'] ?? '' ) ? 'Trợ lý nhắc' : ( 'fb_post' === $type || 'web_post' === $type ? 'Lịch đăng bài' : 'Lịch' ),
			);
		}
		usort( $out, static function ( $a, $b ) { return $a['at'] <=> $b['at']; } );
		return array_slice( $out, 0, 30 );
	}

	// ── brain (the person's own register, BizCity_Journal_REST) ───────────

	public static function brain_overview(): WP_REST_Response {
		$resp = class_exists( 'BizCity_Journal_REST' ) ? BizCity_Journal_REST::overview( new WP_REST_Request( 'GET', '/bizcity-knowledge/v2/documents/overview' ) ) : self::brain_off();
		// [2026-10-22 09:22 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.128 TwinZalo ㉑ (U19) — + storage of the person's brain: used (every file, once) / quota (50 GB by default)
		if ( 200 === $resp->get_status() && class_exists( 'BizCity_Journal_Database' ) && method_exists( 'BizCity_Journal_Database', 'storage_bytes' ) ) {
			$uid = (int) get_current_user_id();
			$s   = BizCity_Journal_Database::instance()->storage_bytes( $uid );
			$resp->set_data( (array) $resp->get_data() + array( 'storage' => self::storage_view( $s, (int) apply_filters( 'bizcity_brain_storage_quota_bytes', self::STORAGE_QUOTA_DEFAULT, $uid ) ) ) );
		}
		return $resp;
	}

	const STORAGE_QUOTA_DEFAULT = 53687091200; // 50 GB (chủ 2026-10-22 20:2x); a plan may set its own through `bizcity_brain_storage_quota_bytes`

	/** Pure: storage numbers the panel shows (bytes only — never a path or a file name). */
	public static function storage_view( array $s, int $quota ): array {
		$quota = $quota > 0 ? $quota : self::STORAGE_QUOTA_DEFAULT;
		$used  = max( 0, (int) ( $s['bytes'] ?? 0 ) );
		return array( 'used_bytes' => $used, 'quota_bytes' => $quota, 'files' => max( 0, (int) ( $s['files'] ?? 0 ) ), 'pct' => (int) min( 100, round( 100 * $used / $quota ) ), 'capped' => ! empty( $s['capped'] ) );
	}

	public static function brain_find( WP_REST_Request $request ): WP_REST_Response {
		if ( ! class_exists( 'BizCity_Journal_REST' ) ) {
			return self::brain_off();
		}
		$r = new WP_REST_Request( 'GET', '/bizcity-knowledge/v2/journal/find' );
		foreach ( array( 'kind', 'status', 'q', 'offset' ) as $k ) {
			$v = $request->get_param( $k );
			if ( null !== $v && '' !== $v && is_scalar( $v ) ) {
				$r->set_param( $k, substr( sanitize_text_field( (string) $v ), 0, 80 ) );
			}
		}
		$r->set_param( 'limit', 30 );
		return BizCity_Journal_REST::find( $r );
	}

	/**
	 * [2026-10-22 01:50 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.129 W2 — brain-map@1 of the panel person (BizCity_Journal_Brain_Map, their own rows). `entry` wins,
	 * else the row the link was issued for (`entry:<id>` focus of the session). + `receipt_on` for the "Tắt gửi bản đồ" button.
	 */
	public static function brain_map( WP_REST_Request $request ): WP_REST_Response {
		if ( ! class_exists( 'BizCity_Journal_Brain_Map' ) ) {
			return self::brain_off();
		}
		$focus = class_exists( 'BizCity_Panel_Link' ) ? BizCity_Panel_Link::focus_entry( (string) ( self::$session['focus'] ?? '' ) ) : 0;
		$resp  = BizCity_Journal_Brain_Map::rest( $request, $focus );
		if ( 200 === $resp->get_status() ) {
			$data = (array) $resp->get_data();
			$resp->set_data( array( 'ok' => true, 'receipt_on' => BizCity_Journal_Brain_Map::receipt_on( get_current_user_id() ), 'journal_url' => self::journal_map_url( (int) ( $data['focus']['entry_id'] ?? 0 ) ) ) + $data );
		}
		return $resp;
	}

	/** [2026-10-22 01:50 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.129 W2 — TwinChat › Journal › Bản đồ on the same row (one `ledger` key through the Twin shell; needs a WordPress login there). */
	private static function journal_map_url( int $entry ): string {
		$base = class_exists( 'BizCity_Twin_Route' ) ? BizCity_Twin_Route::url( 'twinchat' ) : admin_url( 'admin.php?page=bizcity-twinchat&plugin=twinchat' );
		return add_query_arg( 'ledger', rawurlencode( 'mode=map' . ( $entry > 0 ? '&entry=' . $entry : '' ) ), $base );
	}

	/** [2026-10-22 01:50 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.129 W2 — {on: bool}: the receipt of the next saves carries (or not) a map link. Only the person's own notification. */
	public static function brain_map_receipt( WP_REST_Request $request ): WP_REST_Response {
		$p = (array) $request->get_json_params();
		if ( ! array_key_exists( 'on', $p ) || ! is_bool( $p['on'] ) || ! class_exists( 'BizCity_Journal_Brain_Map' ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'invalid_param', 'message' => 'Thiếu lựa chọn bật / tắt.', 'hint' => 'Bấm lại nút trên bảng.', 'help_code' => 'brain_map' ), 400 );
		}
		BizCity_Journal_Brain_Map::set_receipt( get_current_user_id(), (bool) $p['on'] );
		return new WP_REST_Response( array( 'ok' => true, 'receipt_on' => BizCity_Journal_Brain_Map::receipt_on( get_current_user_id() ) ), 200 );
	}

	public static function brain_get( WP_REST_Request $request ): WP_REST_Response {
		if ( ! class_exists( 'BizCity_Journal_REST' ) ) {
			return self::brain_off();
		}
		$r = new WP_REST_Request( 'GET', '/bizcity-knowledge/v2/journal/' . (int) $request['id'] );
		$r->set_param( 'id', (int) $request['id'] );
		return BizCity_Journal_REST::get( $r );
	}

	/** Only pinned / review from the panel. */
	public static function brain_patch( WP_REST_Request $request ): WP_REST_Response {
		if ( ! class_exists( 'BizCity_Journal_REST' ) ) {
			return self::brain_off();
		}
		$p    = (array) $request->get_json_params();
		$body = array();
		foreach ( array( 'pinned', 'review' ) as $k ) {
			if ( array_key_exists( $k, $p ) ) {
				$body[ $k ] = ! empty( $p[ $k ] );
			}
		}
		if ( ! $body ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'invalid_param', 'message' => 'Bảng nhanh chỉ ghim / đánh dấu cần xem lại.' ), 400 );
		}
		$r = new WP_REST_Request( 'PATCH', '/bizcity-knowledge/v2/journal/' . (int) $request['id'] );
		$r->set_param( 'id', (int) $request['id'] );
		$r->set_header( 'content-type', 'application/json' );
		$r->set_body( (string) wp_json_encode( $body ) );
		return BizCity_Journal_REST::patch( $r );
	}

	/** The person's own original, as a 15-minute signed link (never another person's). */
	public static function brain_file( WP_REST_Request $request ): WP_REST_Response {
		// [2026-10-22 06:45 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.129 reflect #1 — a brain.map session (link sent unasked) downloads only the file it was sent for
		if ( 'brain.map' === (string) ( self::$session['panel'] ?? '' ) && (int) $request['id'] !== BizCity_Panel_Link::focus_entry( (string) ( self::$session['focus'] ?? '' ) ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'focus_only', 'message' => 'Link bản đồ chỉ mở được file vừa lưu.', 'hint' => 'Mở trong Journal (đăng nhập website) để tải file khác.', 'help_code' => 'brain_map' ), 403 );
		}
		$e   = class_exists( 'BizCity_Journal_Index' ) ? BizCity_Journal_Index::get( (int) $request['id'], get_current_user_id() ) : null;
		$aid = is_array( $e ) ? (int) ( $e['original']['attachment_id'] ?? 0 ) : 0;
		if ( $aid <= 0 || ! class_exists( 'BizCity_KG_Private_File_Store' ) || ! BizCity_KG_Private_File_Store::has_bytes( $aid ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'no_file', 'message' => 'Mục này không có file gốc.' ), 404 );
		}
		return new WP_REST_Response( array( 'ok' => true, 'url' => BizCity_KG_Private_File_Store::signed_url( $aid ), 'expires_in' => BizCity_KG_Private_File_Store::SIGNED_TTL ), 200 );
	}

	/**
	 * [2026-10-18 09:00 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.122 S122-F4 — a text note into the person's own register through the ONE write door
	 * (`bizcity_journal_record`, R-JN-17), kind `common.note`. Idempotent per request id the panel sends.
	 */
	public static function brain_note( WP_REST_Request $request ): WP_REST_Response {
		$p    = (array) $request->get_json_params();
		$note = self::note_input( (string) ( $p['title'] ?? '' ), (string) ( $p['text'] ?? '' ) );
		if ( is_string( $note ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'invalid_param', 'message' => $note ), 400 );
		}
		if ( ! function_exists( 'bizcity_journal_record' ) ) {
			return self::brain_off();
		}
		$req = substr( preg_replace( '/[^A-Za-z0-9_-]/', '', (string) ( $p['request_id'] ?? '' ) ), 0, 40 );
		$r   = bizcity_journal_record( array(
			'kind'            => 'common.note',
			'title'           => $note['title'],
			'summary'         => $note['text'],
			'owner_user_id'   => get_current_user_id(),
			'source_type'     => 'web_upload', // closed set (R-JN-19); the panel is a web door
			'origin'          => array( 'surface' => 'owner_panel', 'ref' => '' ),
			'idempotency_key' => 'panel_note:' . get_current_user_id() . ':' . ( '' !== $req ? $req : md5( $note['title'] . '|' . $note['text'] ) ),
		) );
		if ( is_wp_error( $r ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => $r->get_error_code(), 'message' => $r->get_error_message() ), 422 );
		}
		return new WP_REST_Response( array( 'ok' => true, 'entry' => $r ), 201 );
	}

	/** Pure: a note title (≤ 120, else the first words of the text) + text (≤ 4000); a sentence when refused. @return array|string */
	public static function note_input( string $title, string $text ) {
		$text  = trim( preg_replace( "/\r\n?/", "\n", $text ) );
		$title = trim( preg_replace( '/\s+/u', ' ', $title ) );
		if ( '' === $text && '' === $title ) {
			return 'Ghi chú đang trống.';
		}
		if ( mb_strlen( $text ) > 4000 ) {
			return 'Ghi chú dài quá 4000 ký tự — gửi file vào Zalo thay vì gõ ở đây.';
		}
		if ( '' === $title ) {
			$title = mb_substr( preg_replace( '/\s+/u', ' ', $text ), 0, 60 );
		}
		return array( 'title' => mb_substr( $title, 0, 120 ), 'text' => $text );
	}

	/** S122-F4 — rename one of the person's own entries (title only; the row stays theirs). */
	public static function brain_title( WP_REST_Request $request ): WP_REST_Response {
		$title = trim( preg_replace( '/\s+/u', ' ', (string) ( ( (array) $request->get_json_params() )['title'] ?? '' ) ) );
		if ( '' === $title || mb_strlen( $title ) > 120 ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'invalid_param', 'message' => 'Tên từ 1 đến 120 ký tự.' ), 400 );
		}
		if ( ! class_exists( 'BizCity_Journal_Database' ) || ! class_exists( 'BizCity_Journal_Index' ) ) {
			return self::brain_off();
		}
		$r = BizCity_Journal_Database::instance()->update( (int) $request['id'], get_current_user_id(), array( 'title' => $title ) ); // owner-checked inside
		if ( is_wp_error( $r ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => $r->get_error_code(), 'message' => $r->get_error_message() ), 404 );
		}
		if ( class_exists( 'BizCity_Journal_Doc_Index_Pack_Exporter' ) ) {
			BizCity_Journal_Doc_Index_Pack_Exporter::flush( get_current_user_id() ); // the assistant's copy learns the new name
			do_action( 'bizcity_twin_agent_packs_changed', array( 'doc_index' ) );
		}
		return new WP_REST_Response( array( 'ok' => true, 'entry' => BizCity_Journal_Index::get( (int) $request['id'], get_current_user_id() ) ), 200 );
	}

	/**
	 * [2026-10-18 09:00 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.122 S122-F5 — the site's people (same rows as Channel Gateway › Người dùng, which keeps its own
	 * admin gate and leak guard). Read only: add / change level / remove = a sentence the owner sends in Zalo (create_website_account / set_website_account_role, R3 two steps).
	 */
	public static function users(): WP_REST_Response {
		if ( ! class_exists( 'BizCity_Admin_Users_Overview_REST' ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'users_unavailable', 'message' => 'Danh sách người dùng chưa có trên website này.' ), 503 );
		}
		$gate = BizCity_Admin_Users_Overview_REST::can_manage();
		if ( true !== $gate ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'admin_only', 'message' => 'Chỉ quản trị website xem được danh sách người dùng.' ), 403 );
		}
		$r = new WP_REST_Request( 'GET', '/bizcity-channel/v1/admin/users-overview' );
		$r->set_param( 'range', '7d' );
		$r->set_param( 'per_page', 50 );
		$resp = rest_ensure_response( BizCity_Admin_Users_Overview_REST::route( $r ) );
		$data = (array) $resp->get_data();
		return new WP_REST_Response( array( 'ok' => 200 === $resp->get_status() && isset( $data['rows'] ), 'people' => self::people_rows( (array) ( $data['rows'] ?? array() ) ) ), $resp->get_status() );
	}

	/** Pure: the overview rows ⇒ what the panel shows (name, role, level, numbers, last active) — nothing else leaves. */
	public static function people_rows( array $rows ): array {
		$out = array();
		foreach ( $rows as $row ) {
			$u     = (array) ( $row['user'] ?? array() );
			$out[] = array(
				'id'          => (int) ( $u['id'] ?? 0 ),
				'name'        => mb_substr( (string) ( $u['name'] ?? '' ), 0, 60 ),
				'wp_role'     => (string) ( $u['wp_role'] ?? '' ),
				'kind'        => (string) ( $u['kind'] ?? '' ),
				'level'       => isset( $row['level'] ) ? (string) $row['level'] : '',
				'numbers'     => count( (array) ( $row['numbers'] ?? array() ) ),
				'last_active' => (string) ( $row['last_active'] ?? '' ),
			);
		}
		return $out;
	}

	private static function brain_off(): WP_REST_Response {
		return new WP_REST_Response( array( 'ok' => false, 'code' => 'journal_unavailable', 'message' => 'Sổ tài liệu chưa sẵn sàng trên website này.' ), 503 );
	}

	// ── helpers ──────────────────────────────────────────────────────────

	/** The connect services keep their own gate: the panel person must hold the channel grant (or be admin). */
	private static function channel_denied(): ?WP_REST_Response {
		$ok = BizCity_Connect_Composio_REST::perm_channel();
		if ( true === $ok ) {
			return null;
		}
		$msg = is_wp_error( $ok ) ? $ok->get_error_message() : 'Bạn chưa được cấp quyền kênh.';
		return new WP_REST_Response( array( 'ok' => false, 'code' => 'not_allowed', 'message' => $msg, 'hint' => 'Nhờ quản trị viên cấp quyền "Kênh khách & Gmail".' ), 403 );
	}

	/** A request on the Channel Gateway route the existing pipe() expects. */
	private static function inner( string $method, string $path, array $body = array() ): WP_REST_Request {
		$r = new WP_REST_Request( $method, '/bizcity-channel/v1/connect/composio' . $path );
		if ( $body ) {
			$r->set_header( 'content-type', 'application/json' );
			$r->set_body( (string) wp_json_encode( $body ) );
		}
		return $r;
	}

	private static function only( WP_REST_Request $request, array $keys ): array {
		$p = (array) $request->get_json_params();
		$o = array();
		foreach ( $keys as $k ) {
			if ( isset( $p[ $k ] ) && is_scalar( $p[ $k ] ) ) {
				$o[ $k ] = substr( sanitize_text_field( (string) $p[ $k ] ), 0, 120 );
			}
		}
		return $o;
	}

	/** One sentence to send the assistant next, from the manifest's `chat_suggestions` (else a plain default). */
	public static function suggestions( string $panel ): array {
		$toolkit = 0 === strpos( $panel, 'connect.' ) ? substr( $panel, 8 ) : '';
		if ( '' === $toolkit || ! class_exists( 'BizCity_Connector_Registry' ) ) {
			return array();
		}
		foreach ( BizCity_Connector_Registry::instance()->all() as $m ) {
			if ( $toolkit === (string) ( $m['auth']['toolkit'] ?? '' ) ) {
				$out = array();
				foreach ( (array) ( $m['chat_suggestions'] ?? array() ) as $s ) {
					if ( ! empty( $s['text'] ) ) {
						$out[] = substr( (string) $s['text'], 0, 120 );
					}
				}
				return array_slice( $out, 0, 3 );
			}
		}
		return array();
	}
}
