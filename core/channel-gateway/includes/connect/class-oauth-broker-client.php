<?php
/**
 * Site → Hub client of the shared OAuth broker `oauth-broker@1` (PHASE-0.93 S93-C1).
 *
 * The site never holds the secret of an "app BizCity": it asks the Hub for the provider status, gets an authorize URL for
 * the popup, then trades the one-time `exchange` code the popup posts back for ITS OWN tokens. Refresh of Google / Zalo OA
 * tokens goes back through the Hub (the secret lives there). Every call returns {ok, data, error{code,message,hint}|null}
 * and never throws; the transport is BizCity_LLM_Client's gateway helpers (Bearer = the site's 1API key).
 *
 * [2026-10-06 10:20 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.93 S93-C1 — new file.
 *
 * @package BizCity_Twin_AI
 * @subpackage Channel_Gateway
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_OAuth_Broker_Client', false ) ) {
	return;
}

final class BizCity_OAuth_Broker_Client {

	const BASE = '/oauth-broker/';

	/** @var array<string,callable> Tests: transport(method, path, body): array, ready(): bool. */
	public static $seams = array();

	public static function is_ready(): bool {
		if ( isset( self::$seams['ready'] ) ) {
			return (bool) call_user_func( self::$seams['ready'] );
		}
		return class_exists( 'BizCity_LLM_Client' ) && '' !== (string) BizCity_LLM_Client::instance()->get_api_key( false );
	}

	/** Cached 5 minutes per provider (step ② renders on every page view); $force skips the cache. */
	public static function status( string $provider, bool $force = false ): array {
		$k = 'bizcity_connect_broker_status_' . $provider;
		if ( ! $force ) {
			$cached = get_transient( $k );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}
		$r = self::call( 'GET', $provider . '/status', array() );
		if ( $r['ok'] || 'hub_unreachable' !== ( $r['error']['code'] ?? '' ) ) {
			set_transient( $k, $r, $r['ok'] ? 5 * MINUTE_IN_SECONDS : MINUTE_IN_SECONDS );
		}
		return $r;
	}

	public static function start( string $provider, array $scopes, string $return_origin ): array {
		return self::call( 'POST', $provider . '/start', array( 'scopes' => array_values( $scopes ), 'return_origin' => $return_origin ) );
	}

	/** One-time code from the popup, or — when the browser cut window.opener — the login's state to poll (answer `pending` until it finishes). */
	public static function exchange( string $provider, string $exchange, string $state = '' ): array {
		return self::call( 'POST', $provider . '/exchange', '' !== $exchange ? array( 'exchange' => $exchange ) : array( 'state' => $state ) );
	}

	public static function refresh( string $provider, string $refresh_token ): array {
		return self::call( 'POST', $provider . '/refresh', array( 'refresh_token' => $refresh_token ) );
	}

	public static function revoke( string $provider, array $args ): array {
		return self::call( 'POST', $provider . '/revoke', $args );
	}

	private static function call( string $method, string $path, array $body ): array {
		if ( ! self::is_ready() ) {
			return self::fail( array( 'code' => 'key_missing', 'message' => 'Chưa có khoá 1API trên website.', 'hint' => 'Làm bước ① trước.' ) );
		}
		$raw = isset( self::$seams['transport'] )
			? call_user_func( self::$seams['transport'], $method, self::BASE . $path, $body )
			: ( 'GET' === $method
				? BizCity_LLM_Client::instance()->gateway_get( self::BASE . $path, $body, 'GET', 15, false )
				: BizCity_LLM_Client::instance()->gateway_post( self::BASE . $path, $body, 25, false ) );
		if ( ! is_array( $raw ) ) {
			return self::fail( array() );
		}
		$http = (int) ( $raw['http_code'] ?? 200 );
		if ( ! empty( $raw['_degraded'] ) || $http >= 400 ) {
			// WP_Error from the Hub arrives as {code, message, data:{status, hint, help_code}}
			$hint = (string) ( $raw['data']['hint'] ?? $raw['hint'] ?? '' );
			$code = (string) ( $raw['code'] ?? $raw['error'] ?? '' );
			if ( 404 === $http && 'rest_no_route' === $code ) {
				$code = 'hub_outdated';
			}
			return self::fail( array( 'code' => $code, 'message' => (string) ( $raw['message'] ?? '' ), 'hint' => $hint ) );
		}
		unset( $raw['http_code'] );
		return array( 'ok' => true, 'data' => $raw, 'error' => null );
	}

	private static function fail( array $e ): array {
		$code = '' !== (string) ( $e['code'] ?? '' ) ? (string) $e['code'] : 'hub_unreachable';
		if ( in_array( $code, array( 'http_request_failed', 'decode_failed', 'no_api_key' ), true ) ) {
			$code = 'no_api_key' === $code ? 'key_missing' : 'hub_unreachable';
		}
		return array(
			'ok'    => false,
			'data'  => array(),
			'error' => array(
				'code'    => $code,
				'message' => '' !== (string) ( $e['message'] ?? '' ) ? (string) $e['message'] : 'Chưa kết nối được máy chủ BizCity.',
				'hint'    => '' !== (string) ( $e['hint'] ?? '' ) ? (string) $e['hint'] : 'Kiểm tra khoá 1API rồi thử lại.',
			),
		);
	}
}
