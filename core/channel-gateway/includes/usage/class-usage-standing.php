<?php
/**
 * The site's window on its OWN domain title, issued by the Hub from numbers the CELL measured (PHASE-0.108 S108-L23, 20-REVENUE-PER-NUMBER.md §6.4, mockup ⑧).
 *
 *   GET  usage/revenue/standing               ⇐ Hub GET zalo-hub/standing (the site's OWN key; main-site fallback OFF), cached 10 min
 *   POST usage/revenue/standing/visibility    ⇒ Hub POST zalo-hub/standing/visibility {public:bool} — appear by name on the public board (default hidden)
 *
 * Managers only. The site computes NOTHING about the title: what is missing for the next tier, the ladder states and the chain check all come from the Hub; this class only whitelists the body
 * (a Hub answer is data, never markup: strings are cut, lists are capped, unknown keys dropped, any 64-hex blanked) and relays the one switch the site owner owns. A Hub without the route, or
 * unreachable, is `state: unavailable` — the tab then says so and nothing else on the page is affected (D108-R2 spirit: a Hub hiccup never blocks the site's business).
 *
 * Test seams ($readers): is_admin() · logged_in() · fetch(refresh): array · post(body): array · cache_get() · cache_set(array) · cache_clear() · now()
 *
 * // [2026-10-13 Johnny Chu - Chu Hoàng Anh] PHASE-0.108 S108-L23.
 *
 * @package BizCity_Twin_AI
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_Usage_Standing {

	const NS        = 'bizcity-channel/v1';
	const PATH_GET  = '/zalo-hub/standing';
	const PATH_VIS  = '/zalo-hub/standing/visibility';
	const TRANSIENT = 'bizcity_usage_standing';
	const TTL       = 600;
	const TTL_FAIL  = 60;

	/** @var array<string,callable> */
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
		$p = array( __CLASS__, 'can_manage' );
		register_rest_route( self::NS, '/usage/revenue/standing', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'route_get' ), 'permission_callback' => $p ) );
		register_rest_route( self::NS, '/usage/revenue/standing/visibility', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'route_visibility' ), 'permission_callback' => $p ) );
	}

	public static function can_manage() {
		$logged = isset( self::$readers['logged_in'] ) ? (bool) call_user_func( self::$readers['logged_in'] ) : (bool) is_user_logged_in();
		if ( ! $logged ) {
			return new WP_Error( 'rest_forbidden', 'Login required.', array( 'status' => 401 ) );
		}
		if ( ! self::is_admin() ) {
			return new WP_Error( 'rest_forbidden', 'Chỉ quản trị website được xem danh hiệu domain.', array( 'status' => 403 ) );
		}
		return true;
	}

	/* ── routes ────────────────────────────────────────────────────── */

	public static function route_get( WP_REST_Request $request ): WP_REST_Response {
		return new WP_REST_Response( self::view( ! empty( $request->get_param( 'refresh' ) ) ), 200 );
	}

	public static function route_visibility( WP_REST_Request $request ): WP_REST_Response {
		$b = (array) $request->get_json_params();
		if ( ! array_key_exists( 'public', $b ) || ! is_bool( $b['public'] ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'invalid_param', 'message' => 'Chọn bật hoặc tắt công khai.' ), 400 );
		}
		$r = self::post( array( 'public' => $b['public'] ) );
		if ( empty( $r['ok'] ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'hub_unavailable', 'message' => 'Chưa gửi được yêu cầu tới BizCity Hub. Thử lại sau.' ), 502 );
		}
		self::cache_clear();
		return new WP_REST_Response( array( 'ok' => true, 'public' => (bool) $b['public'] ), 200 );
	}

	/* ── view ──────────────────────────────────────────────────────── */

	/**
	 * @return array{contract:string,state:string,data:?array}  state: ok | unavailable
	 */
	public static function view( bool $refresh = false ): array {
		if ( ! $refresh ) {
			$c = self::cache_get();
			if ( is_array( $c ) && isset( $c['state'] ) ) {
				return $c;
			}
		}
		$raw = self::fetch();
		$out = ( ! empty( $raw ) && empty( $raw['_degraded'] ) && 0 === strpos( (string) ( $raw['contract'] ?? '' ), 'brain-standing@1' ) )
			? array( 'contract' => 'brain-standing@1', 'state' => 'ok', 'data' => self::filter( $raw ) )
			: array( 'contract' => 'brain-standing@1', 'state' => 'unavailable', 'data' => null );
		self::cache_set( $out );
		return $out;
	}

	/**
	 * Whitelist rebuild of the Hub body (pure; unit-tested).
	 *
	 * @param array<string,mixed> $raw
	 * @return array<string,mixed>
	 */
	public static function filter( array $raw ): array {
		$tier = static function ( $t ): array {
			$t = is_array( $t ) ? $t : array();
			return array( 'level' => max( 0, min( 6, (int) ( $t['level'] ?? 0 ) ) ), 'code' => self::str( $t['code'] ?? 'none', 20 ), 'label' => self::str( $t['label'] ?? '', 40 ) );
		};
		$ladder = array();
		foreach ( array_slice( (array) ( $raw['ladder'] ?? array() ), 0, 6 ) as $r ) {
			if ( is_array( $r ) ) {
				$st       = (string) ( $r['state'] ?? 'todo' );
				$ladder[] = $tier( $r ) + array( 'state' => in_array( $st, array( 'done', 'now', 'todo' ), true ) ? $st : 'todo' );
			}
		}
		$missing = array();
		foreach ( array_slice( (array) ( $raw['missing'] ?? array() ), 0, 4 ) as $m ) {
			if ( is_array( $m ) ) {
				$missing[] = array( 'code' => self::str( $m['code'] ?? '', 30 ), 'label' => self::str( $m['label'] ?? '', 80 ), 'value' => max( 0, (int) ( $m['value'] ?? 0 ) ), 'target' => max( 0, (int) ( $m['target'] ?? 0 ) ) );
			}
		}
		$history = array();
		foreach ( array_slice( (array) ( $raw['history'] ?? array() ), 0, 12 ) as $h ) {
			if ( is_array( $h ) ) {
				$history[] = $tier( $h ) + array( 'reached_at' => self::str( $h['reached_at'] ?? '', 25 ), 'hash12' => preg_replace( '/[^a-f0-9]/', '', strtolower( self::str( $h['hash12'] ?? '', 12 ) ) ) );
			}
		}
		$highest = null;
		if ( isset( $raw['highest'] ) && is_array( $raw['highest'] ) ) {
			$highest = $tier( $raw['highest'] ) + array( 'reached_at' => self::str( $raw['highest']['reached_at'] ?? '', 25 ), 'hash12' => preg_replace( '/[^a-f0-9]/', '', strtolower( self::str( $raw['highest']['hash12'] ?? '', 12 ) ) ) );
		}
		$metrics = null;
		if ( isset( $raw['metrics'] ) && is_array( $raw['metrics'] ) ) {
			$metrics = array();
			foreach ( array( 'numbers_total', 'numbers_active_7d', 'customers_30d', 'customer_turns_30d', 'groups_active_30d', 'active_streak_days' ) as $k ) {
				$metrics[ $k ] = max( 0, (int) ( $raw['metrics'][ $k ] ?? 0 ) );
			}
		}
		return array(
			'domain'      => self::str( $raw['domain'] ?? '', 253 ),
			'public'      => ! empty( $raw['public'] ),
			'tier'        => $tier( $raw['tier'] ?? array() ),
			'highest'     => $highest,
			'ladder'      => $ladder,
			'missing'     => $missing,
			'metrics'     => $metrics,
			'gold_months' => max( 0, min( 99, (int) ( $raw['gold_months'] ?? 0 ) ) ),
			'history'     => $history,
			'as_of'       => self::str( $raw['as_of'] ?? '', 10 ),
			'chain_ok'    => ! empty( $raw['chain_ok'] ),
		);
	}

	/* ── seams ─────────────────────────────────────────────────────── */

	private static function str( $v, int $max ): string {
		$s = trim( function_exists( 'wp_strip_all_tags' ) ? (string) wp_strip_all_tags( (string) $v ) : strip_tags( (string) $v ) ); // tags removed (a Hub answer is data, never markup)
		$s = (string) preg_replace( '/[0-9a-f]{64}/i', '', $s ); // never echo a full hash back into the UI (the Hub sends hash12 on purpose)
		return function_exists( 'mb_substr' ) ? mb_substr( $s, 0, $max ) : substr( $s, 0, $max );
	}

	private static function fetch(): array {
		if ( isset( self::$readers['fetch'] ) ) {
			return (array) call_user_func( self::$readers['fetch'] );
		}
		if ( ! class_exists( 'BizCity_LLM_Client' ) ) {
			return array();
		}
		$r = BizCity_LLM_Client::instance()->gateway_get( self::PATH_GET, array(), 'GET', 8, false ); // the site's OWN key only
		return is_array( $r ) ? $r : array();
	}

	private static function post( array $body ): array {
		if ( isset( self::$readers['post'] ) ) {
			return (array) call_user_func( self::$readers['post'], $body );
		}
		if ( ! class_exists( 'BizCity_LLM_Client' ) ) {
			return array();
		}
		$r = BizCity_LLM_Client::instance()->gateway_post( self::PATH_VIS, $body, 15, false );
		return is_array( $r ) && empty( $r['_degraded'] ) ? $r : array();
	}

	private static function cache_get() {
		if ( isset( self::$readers['cache_get'] ) ) {
			return call_user_func( self::$readers['cache_get'] );
		}
		return function_exists( 'get_transient' ) ? get_transient( self::TRANSIENT ) : false;
	}

	private static function cache_set( array $v ): void {
		if ( isset( self::$readers['cache_set'] ) ) {
			call_user_func( self::$readers['cache_set'], $v );
			return;
		}
		if ( function_exists( 'set_transient' ) ) {
			set_transient( self::TRANSIENT, $v, 'ok' === $v['state'] ? self::TTL : self::TTL_FAIL );
		}
	}

	private static function cache_clear(): void {
		if ( isset( self::$readers['cache_clear'] ) ) {
			call_user_func( self::$readers['cache_clear'] );
			return;
		}
		if ( function_exists( 'delete_transient' ) ) {
			delete_transient( self::TRANSIENT );
		}
	}

	private static function is_admin(): bool {
		if ( isset( self::$readers['is_admin'] ) ) {
			return (bool) call_user_func( self::$readers['is_admin'] );
		}
		return class_exists( 'BizCity_Network_Admin_Capability' ) ? BizCity_Network_Admin_Capability::can_manage() : current_user_can( 'manage_options' );
	}
}

