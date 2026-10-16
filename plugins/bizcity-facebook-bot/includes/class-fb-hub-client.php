<?php
/**
 * Facebook / Messenger channel adapter - the ONE place that talks to the Hub about channels (PHASE-0.90 S90-F1/F2).
 *
 * Routes (bizcity/v1 like the sibling zalo-hub routes, through BizCity_LLM_Client's gateway helpers):
 *   PUT    zalo-hub/channel/register  {platform, channel_ref}   -> {ok, platform, channel_ref, cell_id, status}
 *   DELETE zalo-hub/channel/register  {platform, channel_ref}
 *   POST   zalo-hub/channel/inbound   channel-inbound@1.0.0     -> channel-outbound@1.0.0 (reply | skip)
 *   PUT|GET zalo-hub/channel/config    channel-config@1.0.0       (PHASE-0.109: pages, Guru, tools, secrets -> the cell, which answers Meta by itself)
 *   GET    zalo-hub/channel/tools|preflight?channel_ref=             (PHASE-0.109: what the cell can use / what is ready)
 * Every call returns {ok, status, data, error{code,message,hint,help_code}|null}; nothing here throws or shows a secret.
 * Tests (and a changed path) use the two seams: $seams['transport'](method, path, payload) and $seams['ready'](), $seams['key_id']().
 *
 * @package BizCity_Facebook_Bot
 * @since   PHASE-0.90 S90-F2
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_FB_Hub_Client', false ) ) {
	return;
}

final class BizCity_FB_Hub_Client {

	const PATH_REGISTER = '/zalo-hub/channel/register';
	const PATH_INBOUND  = '/zalo-hub/channel/inbound';
	// [2026-10-14 08:04 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.109 S109-L10/L13 — cell answers Messenger directly: config push + read relays
	const PATH_CONFIG    = '/zalo-hub/channel/config';
	const PATH_TOOLS     = '/zalo-hub/channel/tools';
	const PATH_PREFLIGHT = '/zalo-hub/channel/preflight';
	const PATH_TRACE     = '/zalo-hub/channel/trace';
	const PATH_NOTIFY    = '/zalo-hub/channel/notify'; // [2026-10-15 Johnny Chu - Chu Hoàng Anh] PHASE-0.115 S115-W5
	const KEY_ID_OPTION = 'bizcity_twin_web_turn_key_id';

	/** @var array<string,callable> */
	public static $seams = array();

	/** Seconds for a POST/PUT to the Hub. The webhook request lowers it while it adopts a page (Meta waits ~20 s for the whole answer), then restores it. */
	public static $timeout = 25;

	public static function is_ready(): bool {
		if ( isset( self::$seams['ready'] ) ) {
			return (bool) call_user_func( self::$seams['ready'] );
		}
		return class_exists( 'BizCity_LLM_Client' ) && '' !== (string) BizCity_LLM_Client::instance()->get_api_key( false );
	}

	/** This blog's key id at the Hub (the web-turn code caches it 12 h from the Zalo hub health route). */
	public static function key_id( bool $allow_health = true ): int {
		if ( isset( self::$seams['key_id'] ) ) {
			return (int) call_user_func( self::$seams['key_id'] );
		}
		$cached = function_exists( 'get_transient' ) ? (int) get_transient( self::KEY_ID_OPTION ) : 0;
		if ( $cached > 0 || ! $allow_health || ! class_exists( 'BizCity_Zalo_Personal_Hub_Client' ) ) {
			return $cached;
		}
		$health = BizCity_Zalo_Personal_Hub_Client::instance()->health();
		$id     = (int) ( $health['key_id'] ?? 0 );
		if ( $id > 0 && function_exists( 'set_transient' ) ) {
			set_transient( self::KEY_ID_OPTION, $id, 12 * HOUR_IN_SECONDS );
		}
		return $id;
	}

	public static function register( string $platform, string $channel_ref ): array {
		return self::call( 'PUT', self::PATH_REGISTER, array( 'platform' => $platform, 'channel_ref' => $channel_ref ) );
	}

	public static function unregister( string $platform, string $channel_ref ): array {
		return self::call( 'DELETE', self::PATH_REGISTER, array( 'platform' => $platform, 'channel_ref' => $channel_ref ) );
	}

	/** @param array $body channel-inbound@1.0.0 */
	public static function inbound( array $body ): array {
		return self::call( 'POST', self::PATH_INBOUND, $body );
	}

	/** channel-config@1.0.0 to the cell through the Hub. Secrets in $body are relayed, never kept or logged here. */
	public static function config_put( array $body ): array {
		return self::call( 'PUT', self::PATH_CONFIG, $body );
	}

	public static function config_get(): array {
		return self::call( 'GET', self::PATH_CONFIG, array() );
	}

	/** Tools that can reach a customer turn of this page, on / off / locked (cell answer, channel-tools@1.0.0) */
	public static function tools( string $page ): array {
		return self::call( 'GET', self::PATH_TOOLS, array( 'channel_ref' => $page ) );
	}

	/** Setup checks the cell can verify (token works, Meta reached it, route has its secret…) */
	public static function preflight( string $page ): array {
		return self::call( 'GET', self::PATH_PREFLIGHT, array( 'channel_ref' => $page ) );
	}

	/** The last turns of a page, ms per leg, no text (cell answer, channel-trace@1.0.0) */
	public static function trace( string $page, int $limit = 20 ): array {
		return self::call( 'GET', self::PATH_TRACE, array( 'channel_ref' => $page, 'limit' => $limit ) );
	}

	/** [2026-10-15 Johnny Chu - Chu Hoàng Anh] PHASE-0.115 S115-W5 — channel-notify@1.0.0: tell the cell an order of this page's customer was paid (the cell messages the customer) */
	public static function notify( array $body ): array {
		return self::call( 'POST', self::PATH_NOTIFY, $body );
	}

	private static function call( string $method, string $path, array $payload ): array {
		if ( ! self::is_ready() ) {
			return self::fail( 0, array( 'code' => 'api_key_missing', 'message' => 'Chưa có khoá 1API trên site này.', 'hint' => 'Dán khoá 1API ở bước 1 rồi thử lại.', 'help_code' => 'S90-F-KEY' ) );
		}
		$raw = isset( self::$seams['transport'] )
			? call_user_func( self::$seams['transport'], $method, $path, $payload )
			: self::default_transport( $method, $path, $payload );
		if ( ! is_array( $raw ) ) {
			return self::fail( 0, array() );
		}
		$http = (int) ( $raw['http_code'] ?? 200 );
		$bad  = ! empty( $raw['_degraded'] ) || $http >= 400
			|| ( isset( $raw['code'] ) && ! isset( $raw['status'] ) && ! isset( $raw['ok'] ) );
		if ( $bad ) {
			return self::fail( $http, $raw );
		}
		unset( $raw['_degraded'], $raw['http_code'] );
		return array( 'ok' => true, 'status' => $http > 0 ? $http : 200, 'data' => $raw, 'error' => null );
	}

	private static function default_transport( string $method, string $path, array $payload ) {
		$llm = BizCity_LLM_Client::instance();
		if ( 'DELETE' === $method ) {
			return $llm->gateway_get( $path, $payload, 'DELETE', 10, false );
		}
		if ( 'GET' === $method ) {
			return $llm->gateway_get( $path, $payload, 'GET', 20, false );
		}
		// gateway_post only POSTs: WordPress REST honours ?_method=PUT, so the PUT route is reached through it.
		if ( 'PUT' === $method ) {
			$path .= '?_method=PUT';
		}
		return $llm->gateway_post( $path, $payload, max( 1, (int) self::$timeout ), false );
	}

	/** R-ERROR-UX frame: always code, message, hint, help_code. */
	private static function fail( int $http, array $raw ): array {
		$code = (string) ( $raw['code'] ?? ( is_string( $raw['error'] ?? null ) ? $raw['error'] : '' ) );
		if ( '' === $code ) {
			$code = 'hub_unreachable';
		}
		$frame = array(
			'code'      => $code,
			'message'   => (string) ( $raw['message'] ?? 'Chưa kết nối được máy chủ BizCity.' ),
			'hint'      => (string) ( $raw['hint'] ?? 'Kiểm tra khoá 1API và kết nối mạng rồi thử lại.' ),
			'help_code' => (string) ( $raw['help_code'] ?? 'S90-F-HUB' ),
		);
		return array( 'ok' => false, 'status' => $http, 'data' => array(), 'error' => $frame );
	}
}
