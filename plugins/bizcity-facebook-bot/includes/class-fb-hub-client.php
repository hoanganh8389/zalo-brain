<?php
/**
 * Facebook / Messenger channel adapter - the ONE place that talks to the Hub about channels (PHASE-0.90 S90-F1/F2).
 *
 * Routes (bizcity/v1 like the sibling zalo-hub routes, through BizCity_LLM_Client's gateway helpers):
 *   PUT    zalo-hub/channel/register  {platform, channel_ref}   -> {ok, platform, channel_ref, cell_id, status}
 *   DELETE zalo-hub/channel/register  {platform, channel_ref}
 *   POST   zalo-hub/channel/inbound   channel-inbound@1.0.0     -> channel-outbound@1.0.0 (reply | skip)
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
	const KEY_ID_OPTION = 'bizcity_twin_web_turn_key_id';

	/** @var array<string,callable> */
	public static $seams = array();

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
		// gateway_post only POSTs: WordPress REST honours ?_method=PUT, so the PUT route is reached through it.
		if ( 'PUT' === $method ) {
			$path .= '?_method=PUT';
		}
		return $llm->gateway_post( $path, $payload, 25, false );
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
