<?php
/**
 * "Kết nối nhanh" — Gmail · Google Drive · Facebook Page through Composio on the CELL (PHASE-0.113, composio-connect@1).
 *
 * The admin clicks "Kết nối", logs in at the provider, comes back — no app to create, no secret to paste. Composio keeps the OAuth
 * tokens; the cell keeps only ids; this site keeps NOTHING (no token, no Composio key). One axis: site → Hub → cell (R-ONE-VERTICAL-CELL).
 *
 * bizcity-channel/v1 (admin = BizCity_Connect_REST::perm_admin):
 *   GET    connect/composio/status
 *   POST   connect/composio/connect          {toolkit}            ⇒ {redirect_url} (callback_url = this admin screen, built here)
 *   POST   connect/composio/refresh          {connected_account_id}
 *   DELETE connect/composio/connection?id=
 *   GET    connect/composio/facebook/pages?connection=
 *   PUT    connect/composio/facebook/pages   {connected_account_id, page_ids[]}
 *   PUT    connect/composio/pages/{page}/auto  {mode: manual|assisted|auto}   admin: the cell polls the page and drafts (assisted) / sends (auto) a reply
 *   POST   connect/composio/read             {op, input}
 *   GET    connect/composio/actions?status=&limit=
 *   POST   connect/composio/actions          {kind, input}         a draft (never sends)
 *   POST   connect/composio/actions/{id}/(approve|reject) {text?}  approve = the only send
 *
 * Pure pipe: the cell's JSON and status are returned unchanged (its error carries code/message/hint already).
 *
 * [2026-10-15 03:30 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.113 S113-L1 — new file. No class_exists() self-guard (early-bind bug):
 * loaded once by require_once in bootstrap.php.
 *
 * @package BizCity_Twin_AI
 * @subpackage Channel_Gateway
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_Connect_Composio_REST {

	const NS      = 'bizcity-channel/v1';
	const HUB     = '/zalo-hub/composio';
	const TIMEOUT = 50;

	/** @var callable|null Tests: fn(string $method, string $path, array $query, array $body): array{status:int, body:array} */
	public static $hub_seam = null;

	/** @var callable|null Tests: fn(bool $force): bool — stands for BizCity_Twin_Web_Home::ensure_pushed */
	public static $web_home_seam = null;

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes(): void {
		$admin = array( 'BizCity_Connect_REST', 'perm_admin' );
		$base  = '/connect/composio';
		$r     = function ( string $path, string $methods, string $cb ) use ( $admin, $base ) {
			register_rest_route( self::NS, $base . $path, array( 'methods' => $methods, 'callback' => array( __CLASS__, $cb ), 'permission_callback' => $admin ) );
		};
		$staff0 = array( __CLASS__, 'perm_channel' );
		// [2026-10-16 08:40 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.116 S116-LB6 — a person connects, refreshes, removes and defaults THEIR OWN Gmail / Drive…: any channel-granted person; the cell refuses Facebook (shop) to a non-admin and shows each person only their own logins
		foreach ( array( array( '/status', 'GET', 'pipe' ), array( '/connect', 'POST', 'post_connect' ), array( '/refresh', 'POST', 'pipe' ), array( '/connection', 'DELETE', 'pipe' ), array( '/default', 'PUT', 'pipe' ) ) as $row ) {
			register_rest_route( self::NS, $base . $row[0], array( 'methods' => $row[1], 'callback' => array( __CLASS__, $row[2] ), 'permission_callback' => $staff0 ) );
		}
		// [2026-10-16 10:46 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.117 S117-S1 — the person's own cash-book sheet: list / bind (checks row 1 against the template) / unbind
		register_rest_route( self::NS, $base . '/sheets-binding', array(
			array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'get_sheet_binding' ), 'permission_callback' => $staff0 ),
			array( 'methods' => 'PUT', 'callback' => array( __CLASS__, 'put_sheet_binding' ), 'permission_callback' => $staff0 ),
			array( 'methods' => 'DELETE', 'callback' => array( __CLASS__, 'delete_sheet_binding' ), 'permission_callback' => $staff0 ),
		) );
		// [2026-10-17 03:33 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.117 S117-K3 — what THIS person can run (Skill Editor ②): a thin proxy, the cell alone computes readiness; any signed-in person, about themselves only
		// [2026-10-17 03:50 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.117 S117-K4 — Skill Editor ①: my description ⇒ a draft (the cell asks the model once; nothing is stored)
		register_rest_route( self::NS, $base . '/skills-draft', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'post_skills_draft' ), 'permission_callback' => array( __CLASS__, 'perm_signed_in' ) ) );
		// [2026-10-17 07:25 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.117 S117-K3 — step-by-step check of MY permission bundle on the cell: GET reads only; POST re-sends it once
		register_rest_route( self::NS, $base . '/workspace-check', array(
			array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'workspace_check' ), 'permission_callback' => array( __CLASS__, 'perm_signed_in' ) ),
			array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'workspace_check' ), 'permission_callback' => array( __CLASS__, 'perm_signed_in' ) ),
		) );
		register_rest_route( self::NS, $base . '/skills-readiness', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'get_skills_readiness' ), 'permission_callback' => array( __CLASS__, 'perm_signed_in' ) ) );
		$r( '/assign', 'PUT', 'pipe' ); // admin: give a login made before the per-person split to a person
		$r( '/facebook/pages', 'GET,PUT', 'pipe' );
		$r( '/pages/(?P<page>[0-9]{5,30})/auto', 'PUT', 'pipe' ); // [2026-10-15 07:35 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.114 S114-U1: what the cell does with that page's customer messages (admin only)
		// [2026-10-15 04:40 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.113 Q113-3 — staff granted the `channel` agent mode read, draft and APPROVE too;
		// connect / remove / page selection stay admin-only (they change what the whole site is wired to)
		$staff = array( __CLASS__, 'perm_channel' );
		// [2026-10-16 10:57 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.114 S114-R3 — the daily Fanpage report goes to the owner's Zalo; staff with the channel grant may change its hour / cancel it, as they may by chat (connected_apps_report)
		foreach ( array( array( '/report', 'GET,PUT' ), array( '/report/send', 'POST' ) ) as $row ) {
			register_rest_route( self::NS, $base . $row[0], array( 'methods' => $row[1], 'callback' => array( __CLASS__, 'pipe' ), 'permission_callback' => $staff ) );
		}
		foreach ( array( array( '/read', 'POST' ), array( '/actions', 'GET,POST' ), array( '/actions/(?P<id>act_[A-Za-z0-9_-]{8,40})/(?P<decision>approve|reject)', 'POST' ) ) as $row ) {
			register_rest_route( self::NS, $base . $row[0], array( 'methods' => $row[1], 'callback' => array( __CLASS__, 'pipe' ), 'permission_callback' => $staff ) );
		}
	}

	/** Site admin, or a user granted agent mode `channel` (agent-mode-access@1: meta cap bizcity_agent_mode_channel). */
	public static function perm_channel() {
		$uid = get_current_user_id();
		if ( $uid > 0 && ( BizCity_Connect_REST::can_manage() || user_can( $uid, 'bizcity_agent_mode_channel' ) ) ) {
			return true;
		}
		return BizCity_Connect_REST::err( 'not_allowed', 'Bạn chưa được cấp quyền kênh (đăng bài, trả lời khách).', 'Nhờ quản trị viên cấp quyền "Kênh khách & Gmail".', 403 );
	}

	/** Where Composio sends the browser back after consent: the Channel Gateway tools screen of THIS site. */
	public static function callback_url(): string {
		return admin_url( 'admin.php?page=bizchat-gateway-spa&composio_return=1#/tools' );
	}

	public static function post_connect( WP_REST_Request $request ): WP_REST_Response {
		$p       = $request->get_json_params();
		$toolkit = is_array( $p ) ? (string) ( $p['toolkit'] ?? '' ) : '';
		if ( ! in_array( $toolkit, array( 'gmail', 'googledrive', 'facebook', 'googlesheets', 'googlecalendar', 'googlemeet', 'canva' ), true ) ) {
			return self::error( 400, 'invalid_param', 'Chọn ứng dụng cần kết nối.', 'gmail | googledrive | facebook | googlesheets | googlecalendar | googlemeet | canva' );
		}
		self::ensure_web_home( true ); // [2026-10-15 11:05 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.114 S114-L2: a site that only ever used Kết nối nhanh must be known to the Hub too
		$url = self::callback_url();
		// the cell refuses non-https callbacks (an http dev site still connects: Composio then shows its own "done" page)
		return self::send( 'POST', '/connect', array(), array( 'toolkit' => $toolkit, 'callback_url' => 0 === strpos( $url, 'https://' ) ? $url : '' ) );
	}

	/** [2026-10-17 03:33 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.117 S117-K3 — readiness is about the caller only, so any signed-in person may ask */
	public static function perm_signed_in() {
		return get_current_user_id() > 0 ? true : BizCity_Connect_REST::err( 'not_signed_in', 'Bạn cần đăng nhập.', 'Đăng nhập rồi mở lại trang.', 401 );
	}

	/**
	 * [2026-10-17 03:33 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.117 S117-K3 — `?executors=a.b,c.d` (≤ 30 ids) or nothing (= every executor I could use).
	 * Never recomputed here. Hub / cell / old cell unreachable ⇒ 200 + _degraded (R-GW-8): the Editor shows "Không kiểm được lúc này", never a green tick.
	 */
	public static function get_skills_readiness( WP_REST_Request $request ): WP_REST_Response {
		$ids = self::parse_executor_ids( (string) $request->get_param( 'executors' ) );
		if ( null === $ids ) {
			return self::error( 400, 'invalid_param', 'Danh sách bước không hợp lệ.', 'Gửi tối đa 30 mã executor, cách nhau bằng dấu phẩy.' );
		}
		$resp = self::send_with_workspace( 'GET', '/skills/readiness', $ids ? array( 'executors' => implode( ',', $ids ) ) : array(), array() );
		$out  = self::readiness_reply( $resp->get_status(), (array) $resp->get_data() );
		return new WP_REST_Response( $out['body'], $out['status'] );
	}

	/** [2026-10-17 03:50 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.117 S117-K4 — body {name, text}; only these two fields go to the cell */
	public static function post_skills_draft( WP_REST_Request $request ): WP_REST_Response {
		$p    = $request->get_json_params();
		$name = is_array( $p ) ? trim( (string) ( $p['name'] ?? '' ) ) : '';
		$text = is_array( $p ) ? trim( (string) ( $p['text'] ?? '' ) ) : '';
		if ( '' === $name || mb_strlen( $text ) < 8 || mb_strlen( $name ) > 120 || strlen( $text ) > 8192 ) {
			return self::error( 400, 'invalid_param', 'Cần tên việc và mô tả (8 ký tự – 8 KB).', 'Viết rõ: đọc gì, ghi vào đâu, gửi cho ai.' );
		}
		$resp = self::send_with_workspace( 'POST', '/skills/draft', array(), array( 'name' => $name, 'text' => $text ) );
		$out  = self::readiness_reply( $resp->get_status(), (array) $resp->get_data(), 'twin-skill-draft@1' );
		return new WP_REST_Response( $out['body'], $out['status'] );
	}

	/**
	 * [2026-10-17 05:46 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.117 S117-K3 — the cell answers `workspace_not_found` when it has no bundle for this person
	 * (new cell, other cell, lost row) while the site still remembers "already pushed". Push it again once, ignoring that memory, then ask again.
	 */
	private static function send_with_workspace( string $method, string $path, array $query, array $body ): WP_REST_Response {
		$resp = self::send( $method, $path, $query, $body );
		$data = (array) $resp->get_data();
		if ( 404 !== $resp->get_status() || 'workspace_not_found' !== ( $data['code'] ?? '' ) || ! class_exists( 'BizCity_User_Workspace_Pusher' ) ) {
			return $resp;
		}
		$pushed = BizCity_User_Workspace_Pusher::push( get_current_user_id(), true, true );
		return 'pushed' === $pushed ? self::send( $method, $path, $query, $body ) : $resp;
	}

	/**
	 * [2026-10-17 07:25 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.117 S117-K3 — where does MY user-workspace@1 bundle stop? W1 pusher / builder loaded ·
	 * W2 the bundle builds (rev, counts) · W3 the rev the site believes it pushed · W4 what the cell holds (Hub GET zalo-hub/user-workspace) ·
	 * W5 (POST only) push it again now and report the Hub's status / code. Never returns the bundle, a raw id, a phone or an e-mail.
	 */
	public static function workspace_check( WP_REST_Request $request ): WP_REST_Response {
		$uid = get_current_user_id();
		$out = array( 'ok' => true, 'contract' => 'workspace-check@1.0.0' );
		$out['w1_loaded'] = class_exists( 'BizCity_User_Workspace_Builder' ) && class_exists( 'BizCity_User_Workspace_Pusher' ) && class_exists( 'BizCity_Agent_Mode_Access' );
		if ( ! $out['w1_loaded'] ) {
			return new WP_REST_Response( $out, 200 );
		}
		$ref    = BizCity_Agent_Mode_Access::user_ref( $uid );
		$bundle = BizCity_User_Workspace_Builder::build( $uid );
		$out['w2_bundle'] = is_array( $bundle ) ? array(
			'rev'         => (int) ( $bundle['rev'] ?? 0 ),
			'ref_tail'    => substr( (string) ( $bundle['user_ref'] ?? '' ), -6 ),
			'ref_matches' => (string) ( $bundle['user_ref'] ?? '' ) === $ref,
			'role'        => (string) ( $bundle['profile']['role'] ?? '' ),
			'max_risk'    => (string) ( $bundle['plan']['max_risk'] ?? '' ),
			'assistants'  => count( (array) ( $bundle['assistants'] ?? array() ) ),
			'connections' => count( (array) ( $bundle['connections'] ?? array() ) ),
			'modes'       => array_values( array_map( 'strval', (array) ( $bundle['modes'] ?? array() ) ) ),
		) : null;
		$out['w3_pushed_rev'] = (int) get_user_meta( $uid, BizCity_User_Workspace_Pusher::META_PUSHED, true );
		// [2026-10-17 07:40 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.117 S117-K3 — the last real push attempt (login, role change, self-heal): when, and what the Hub answered
		$last = get_transient( BizCity_User_Workspace_Pusher::LAST_KEY . $uid );
		$out['w3_last_push'] = is_array( $last ) ? array_intersect_key( $last, array_flip( array( 'at', 'result', 'status', 'code', 'help', 'msg' ) ) ) : null;
		if ( 'POST' === strtoupper( $request->get_method() ) ) {
			$out['w5_push'] = array( 'result' => BizCity_User_Workspace_Pusher::push( $uid, true, true ), 'hub' => BizCity_User_Workspace_Pusher::$last ); // hub.msg = the cell's own refusal reason (field paths only)
		}
		$out['w4_cell'] = self::cell_workspace( $ref );
		return new WP_REST_Response( $out, 200 );
	}

	/** What the cell holds for this user_ref: {status, code, rev?} through the Hub's GET zalo-hub/user-workspace. */
	private static function cell_workspace( string $ref ): array {
		if ( is_callable( self::$hub_seam ) ) {
			$r = (array) call_user_func( self::$hub_seam, 'GET', '/__user-workspace', array( 'user_ref' => $ref ), array() );
			$raw = (array) ( $r['body'] ?? array() ) + array( 'http_code' => (int) ( $r['status'] ?? 200 ) );
		} elseif ( class_exists( 'BizCity_LLM_Client' ) && '' !== BizCity_Connect_REST::api_key() ) {
			$raw = BizCity_LLM_Client::instance()->gateway_get( '/zalo-hub/user-workspace', array( 'user_ref' => $ref ), 'GET', 20, false );
			$raw = is_array( $raw ) ? $raw : array();
		} else {
			return array( 'status' => 0, 'code' => 'key_missing', 'help' => '' );
		}
		$status = (int) ( $raw['http_code'] ?? ( ! empty( $raw['_degraded'] ) ? 424 : 200 ) );
		$cell   = array( 'status' => $status, 'code' => substr( (string) ( $raw['code'] ?? '' ), 0, 40 ), 'help' => substr( (string) ( $raw['help_code'] ?? '' ), 0, 40 ) );
		if ( 200 === $status ) {
			$cell['rev']       = (int) ( $raw['rev'] ?? 0 );
			$cell['executors'] = count( (array) ( $raw['executors'] ?? array() ) );
		}
		return $cell;
	}

	/** Pure: '' ⇒ array(); bad id or more than 30 ⇒ null. @return string[]|null */
	public static function parse_executor_ids( string $raw ): ?array {
		$ids = array_values( array_filter( array_map( 'trim', explode( ',', $raw ) ), 'strlen' ) );
		if ( count( $ids ) > 30 ) {
			return null;
		}
		foreach ( $ids as $id ) {
			if ( 1 !== preg_match( '/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*){1,2}$/', $id ) ) {
				return null;
			}
		}
		return $ids;
	}

	/**
	 * Pure: map the cell's answer for the browser. 200 with the readiness contract passes through; the cell's own refusals
	 * (no bundle yet, bad id) pass through with a help_code; anything else (Hub down, old Hub / cell without the path) is degraded.
	 *
	 * @return array{status:int, body:array}
	 */
	public static function readiness_reply( int $status, array $data, string $contract = 'skill-readiness@1' ): array {
		if ( 200 === $status && 0 === strpos( (string) ( $data['contract'] ?? '' ), $contract ) ) {
			return array( 'status' => 200, 'body' => $data );
		}
		$code = (string) ( $data['code'] ?? '' );
		if ( in_array( $code, array( 'workspace_not_found', 'invalid_param', 'user_ref_required' ), true ) ) {
			return array( 'status' => $status >= 400 && $status < 500 ? $status : 400, 'body' => array(
				'ok'        => false,
				'code'      => $code,
				'message'   => (string) ( $data['message'] ?? 'Chưa kiểm được.' ),
				'hint'      => (string) ( $data['hint'] ?? '' ) !== '' ? (string) $data['hint'] : 'Đăng xuất rồi đăng nhập lại để website gửi lại quyền của bạn.',
				'help_code' => 'S117-' . strtoupper( str_replace( '_', '-', $code ) ),
			) );
		}
		return array( 'status' => 200, 'body' => array(
			'ok'        => false,
			'_degraded' => true,
			'code'      => 'readiness_unavailable',
			'message'   => 'Không kiểm được lúc này.',
			'hint'      => 'Thử lại sau ít phút; nếu vẫn vậy, báo quản trị viên cập nhật máy chủ trợ lý.',
			'help_code' => 'S117-READINESS-UNAVAILABLE',
			// [2026-10-17 04:20 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.117 S117-K3 — which link answered and how (status + code only, never a body): tells "Hub not updated" from "cell not updated" from "cell down"
			'upstream'  => array(
				'status'   => $status,
				'code'     => substr( preg_replace( '/[^a-z0-9_.-]/i', '', (string) ( $data['code'] ?? '' ) ), 0, 40 ),
				'contract' => substr( preg_replace( '/[^a-z0-9_.@-]/i', '', (string) ( $data['contract'] ?? '' ) ), 0, 40 ),
				// [2026-10-17 04:27 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.117 S117-K3 — the cell's catch-all also answers 404 `not_found`; only the Hub adds help_code S113-H1-404
				'help'     => substr( preg_replace( '/[^a-z0-9_.-]/i', '', (string) ( $data['help_code'] ?? '' ) ), 0, 40 ),
			),
		) );
	}

	public static function get_sheet_binding( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );
		return new WP_REST_Response( array( 'ok' => true, 'bindings' => BizCity_Sheet_Bindings::get( get_current_user_id() ) ), 200 );
	}

	/** [2026-10-16 10:46 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.117 S117-S1: ask the cell whether row 1 of the tab matches the template; bind only when it does, then push the bundle at once */
	public static function put_sheet_binding( WP_REST_Request $request ): WP_REST_Response {
		$uid  = get_current_user_id();
		$p    = $request->get_json_params();
		$p    = is_array( $p ) ? $p : array();
		$tpl  = (string) ( $p['template'] ?? 'cashbook_v1' );
		$id   = BizCity_Sheet_Bindings::id_from( $p['spreadsheet'] ?? ( $p['spreadsheet_id'] ?? '' ) );
		$tab  = trim( (string) ( $p['tab'] ?? '' ) );
		$chk0 = BizCity_Sheet_Bindings::validate( $tpl, $id, $tab );
		if ( empty( $chk0['ok'] ) ) {
			return self::error( 400, (string) $chk0['code'], (string) $chk0['message'], '' );
		}
		$chk = self::send( 'POST', '/sheets/bind-check', array(), array( 'spreadsheet_id' => $id, 'tab' => $tab, 'template' => $tpl ) );
		if ( $chk->get_status() >= 400 ) {
			return $chk; // not connected / needs_choice / no access: the cell's own sentence
		}
		$d = (array) $chk->get_data();
		if ( empty( $d['match'] ) ) {
			$empty = ! empty( $d['empty'] );
			return new WP_REST_Response( array(
				'ok'       => false,
				'code'     => 'sheet_header_mismatch',
				'message'  => $empty ? 'Tab đang trống. Dán dòng tiêu đề mẫu vào ô A1 của tab rồi liên kết lại.' : 'Dòng 1 của tab chưa đúng mẫu: thiếu hoặc sai ' . implode( ', ', array_slice( (array) ( $d['missing'] ?? array() ), 0, 6 ) ) . '.',
				'hint'     => 'Hệ thống không tự sửa bảng tính của bạn.',
				'empty'    => $empty,
				'expected' => (array) ( $d['expected'] ?? array() ),
				'missing'  => (array) ( $d['missing'] ?? array() ),
			), 422 );
		}
		BizCity_Sheet_Bindings::set( $uid, $tpl, $id, $tab );
		$pushed = class_exists( 'BizCity_User_Workspace_Pusher' ) ? BizCity_User_Workspace_Pusher::push( $uid, true ) : 'no_pusher';
		return new WP_REST_Response( array( 'ok' => true, 'bindings' => BizCity_Sheet_Bindings::get( $uid ), 'pushed' => $pushed ), 200 );
	}

	public static function delete_sheet_binding( WP_REST_Request $request ): WP_REST_Response {
		$uid = get_current_user_id();
		BizCity_Sheet_Bindings::remove( $uid, (string) ( $request->get_param( 'template' ) ?: 'cashbook_v1' ) );
		$pushed = class_exists( 'BizCity_User_Workspace_Pusher' ) ? BizCity_User_Workspace_Pusher::push( $uid, true ) : 'no_pusher';
		return new WP_REST_Response( array( 'ok' => true, 'bindings' => BizCity_Sheet_Bindings::get( $uid ), 'pushed' => $pushed ), 200 );
	}

	/** Every other route: same path at the Hub, whitelisted query, JSON body as is. */
	public static function pipe( WP_REST_Request $request ): WP_REST_Response {
		$route = (string) $request->get_route(); // /bizcity-channel/v1/connect/composio/...
		$path  = substr( $route, strlen( '/' . self::NS . '/connect/composio' ) );
		if ( false === $path || '' === $path ) {
			return self::error( 404, 'not_found', 'Không có đường này.', '' );
		}
		$query = array();
		foreach ( array( 'connection', 'status', 'limit', 'id', 'all' ) as $q ) {
			if ( 'all' === $q && ! BizCity_Connect_REST::can_manage() ) {
				continue; // whole-site listing is for admins
			}
			$v = $request->get_param( $q );
			if ( null !== $v && '' !== $v && is_scalar( $v ) ) {
				$query[ $q ] = substr( sanitize_text_field( (string) $v ), 0, 80 );
			}
		}
		$method = strtoupper( $request->get_method() );
		$body   = in_array( $method, array( 'POST', 'PUT' ), true ) ? $request->get_json_params() : array();
		// [2026-10-15 11:05 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.114 S114-L2 — the cell reaches THIS site (product search, orders, CRM records) only through the web-home callback this site registered at the Hub.
		// Nothing else registers it for a site that only uses Composio (it is pushed by a web chat turn or the Facebook Dev setup), and without it every customer
		// turn runs with no site tool (`callback_credential_missing`). Force it when the owner connects / picks pages / changes the mode; a cheap cached check on the status read.
		$force  = in_array( $method, array( 'POST', 'PUT' ), true ) && 1 === preg_match( '#^/(refresh|facebook/pages|pages/[0-9]{5,30}/auto)$#', $path );
		$pushed = self::ensure_web_home( $force );
		$resp   = self::send( $method, $path, $query, is_array( $body ) ? $body : array() );
		if ( 'GET' === $method && '/status' === $path ) {
			$data = $resp->get_data();
			if ( is_array( $data ) ) {
				$data['web_home_pushed'] = $pushed; // the setup screen and the selfcheck show it: false ⇒ the cell cannot call this site
				$resp->set_data( $data );
			}
		}
		return $resp;
	}

	/**
	 * [2026-10-16 09:50 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.116 S116-I7 — toolkits that have an ACTIVE connection, from the cell's own status (60 s cache). `null` = unknown (Hub unreachable, no key): callers must not
	 * treat unknown as "not connected". The Phân quyền module screen uses it to open or fold the Composio row.
	 *
	 * @return string[]|null
	 */
	public static function active_toolkits(): ?array {
		$cached = get_transient( 'bizcity_cg_composio_active_' . get_current_user_id() );
		if ( is_array( $cached ) ) {
			return $cached['list'];
		}
		$resp = self::send( 'GET', '/status', array(), array() );
		$data = $resp->get_data();
		if ( $resp->get_status() >= 400 || ! is_array( $data ) || ! isset( $data['toolkits'] ) || ! is_array( $data['toolkits'] ) ) {
			return null;
		}
		$list = array();
		foreach ( $data['toolkits'] as $t ) {
			foreach ( (array) ( $t['connections'] ?? array() ) as $c ) {
				if ( 'ACTIVE' === ( $c['status'] ?? '' ) && ! empty( $t['toolkit'] ) ) {
					$list[] = (string) $t['toolkit'];
					break;
				}
			}
		}
		set_transient( 'bizcity_cg_composio_active_' . get_current_user_id(), array( 'list' => $list ), 60 );
		return $list;
	}

	/**
	 * [2026-10-17 12:40 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.117 S117-M6 — the cell's per-person rollup for the admin "Người dùng" page
	 * (admin-users-rollup@1.0.0: logins + pending drafts per user_ref, masked). Only for a site admin: send() marks `_admin` after can_manage(),
	 * and the cell refuses without it. `null` = not measured (no key, Hub / cell down, old cell) — the page then shows "—", never 0.
	 *
	 * @return array|null
	 */
	public static function admin_rollup(): ?array {
		if ( ! BizCity_Connect_REST::can_manage() ) {
			return null;
		}
		$resp = self::send( 'GET', '/admin/rollup', array(), array() );
		$data = $resp->get_data();
		if ( 200 !== $resp->get_status() || ! is_array( $data ) || empty( $data['ok'] ) || 'admin-users-rollup@1.0.0' !== ( $data['contract'] ?? '' ) || ! is_array( $data['people'] ?? null ) ) {
			return null;
		}
		return $data;
	}

	/** Register this site's web-home (callback URL + bridge token) at the Hub; true when the Hub has the current one. Never throws. */
	public static function ensure_web_home( bool $force ): bool {
		if ( is_callable( self::$web_home_seam ) ) {
			return (bool) call_user_func( self::$web_home_seam, $force );
		}
		if ( ! class_exists( 'BizCity_Twin_Web_Home' ) ) {
			return false;
		}
		try {
			return (bool) BizCity_Twin_Web_Home::ensure_pushed( $force );
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	private static function send( string $method, string $path, array $query, array $body ): WP_REST_Response {
		$query['_actor'] = 'wp' . get_current_user_id(); // the trail on the cell shows who clicked (never used to authorize)
		// [2026-10-16 08:40 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.116 S116-LB6 — WHOSE login: the site alone computes user_ref (sha256 of blog|user|salt) and checks manage_options; the Hub and cell only carry them
		$uid = get_current_user_id();
		if ( $uid > 0 && class_exists( 'BizCity_Agent_Mode_Access' ) ) {
			$query['_user_ref'] = BizCity_Agent_Mode_Access::user_ref( $uid );
			if ( BizCity_Connect_REST::can_manage() ) {
				$query['_admin'] = '1';
			}
		}
		if ( is_callable( self::$hub_seam ) ) {
			$r = (array) call_user_func( self::$hub_seam, $method, $path, $query, $body );
			return new WP_REST_Response( (array) ( $r['body'] ?? array() ), (int) ( $r['status'] ?? 200 ) );
		}
		if ( ! class_exists( 'BizCity_LLM_Client' ) || '' === BizCity_Connect_REST::api_key() ) {
			return self::error( 424, 'key_missing', 'Website chưa có API key BizCity.', 'Vào Cấu hình Zalo › ① dán API key rồi thử lại.' );
		}
		$llm = BizCity_LLM_Client::instance();
		$hub = self::HUB . $path;
		if ( 'GET' === $method || 'DELETE' === $method ) {
			$raw = $llm->gateway_get( $hub, $query, $method, self::TIMEOUT, false );
		} else {
			$url = $hub . '?' . http_build_query( 'PUT' === $method ? $query + array( '_method' => 'PUT' ) : $query ); // WP REST honours ?_method=PUT
			$raw = $llm->gateway_post( $url, $body, self::TIMEOUT, false );
		}
		$raw    = is_array( $raw ) ? $raw : array();
		$status = (int) ( $raw['http_code'] ?? ( ! empty( $raw['_degraded'] ) ? 424 : 200 ) );
		unset( $raw['_degraded'], $raw['http_code'] );
		if ( isset( $raw['error'] ) && ! isset( $raw['code'] ) && 424 === $status ) {
			return self::error( 424, 'hub_unreachable', 'Không gọi được máy chủ BizCity.', 'Thử lại sau ít giây.' );
		}
		// 502/504 bodies get replaced by Cloudflare: never hand those to the UI (lesson test-buttons-424)
		return new WP_REST_Response( $raw, $status >= 500 ? 424 : $status );
	}

	private static function error( int $status, string $code, string $message, string $hint ): WP_REST_Response {
		return new WP_REST_Response( array( 'ok' => false, 'code' => $code, 'message' => $message, 'hint' => $hint, 'help_code' => 'S113-' . strtoupper( str_replace( '_', '-', $code ) ) ), $status );
	}
}
