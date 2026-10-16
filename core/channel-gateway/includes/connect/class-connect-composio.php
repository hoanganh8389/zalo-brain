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
