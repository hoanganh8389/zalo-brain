<?php
/**
 * Facebook webhook signature (X-Hub-Signature-256) for the legacy entry points `/bizfbhook/` (`?fbhook=1`) and the
 * network `/facehook/` — PHASE-0.93 (the "⚙ App của bạn" sheet points Meta at `/bizfbhook/`).
 *
 * Before this, both endpoints handed ANY POST to the bot and the cell: whoever knew the URL could inject a "customer
 * message". Meta signs every event with the app secret: sha256=HMAC(body, app_secret).
 *
 * Secrets known on this site/network: options bizcity_facebook_bot_app_secret, bztfb_app_secret, fb_app_secret, network
 * option bizcity_fb_app_secret, and each distinct app_secret of bizcity_facebook_bots. Any one matching = valid.
 *   - no secret known at all  ⇒ allowed (an install that never saved an app — nothing to check against)
 *   - secrets known           ⇒ the header must match one of them
 * Way back (prefer auto defaults, documented escape): option `bizcity_fb_signature_mode` = 'log' accepts and only logs
 * mismatches; default 'enforce'.
 *
 * [2026-10-07 01:20 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.93 S93-C5 — new file.
 *
 * @package BizCity_Facebook_Bot
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_FB_Webhook_Signature', false ) ) {
	return;
}

final class BizCity_FB_Webhook_Signature {

	const OPT_MODE = 'bizcity_fb_signature_mode';

	/** @var array<string,callable> Tests: secrets(): string[]. */
	public static $seams = array();

	/** @return string[] distinct non-empty app secrets this site/network knows */
	public static function secrets(): array {
		if ( isset( self::$seams['secrets'] ) ) {
			return (array) call_user_func( self::$seams['secrets'] );
		}
		$out = array(
			(string) get_option( 'bizcity_facebook_bot_app_secret', '' ),
			(string) get_option( 'bztfb_app_secret', '' ),
			(string) get_option( 'fb_app_secret', '' ),
			function_exists( 'get_site_option' ) ? (string) get_site_option( 'bizcity_fb_app_secret', '' ) : '',
		);
		global $wpdb;
		if ( isset( $wpdb ) ) {
			$wpdb->suppress_errors( true );
			$rows = $wpdb->get_col( "SELECT DISTINCT app_secret FROM {$wpdb->prefix}bizcity_facebook_bots WHERE app_secret IS NOT NULL AND app_secret != '' LIMIT 20" );
			$wpdb->suppress_errors( false );
			$out = array_merge( $out, is_array( $rows ) ? array_map( 'strval', $rows ) : array() );
		}
		return array_values( array_unique( array_filter( $out, static function ( $s ) { return '' !== trim( $s ); } ) ) );
	}

	/** @return string 'ok' | 'no_secret' | 'missing' | 'mismatch' */
	public static function check( string $body, string $header ): string {
		$secrets = self::secrets();
		if ( ! $secrets ) {
			return 'no_secret';
		}
		$header = trim( $header );
		if ( '' === $header ) {
			return 'missing';
		}
		foreach ( $secrets as $s ) {
			if ( hash_equals( 'sha256=' . hash_hmac( 'sha256', $body, $s ), $header ) ) {
				return 'ok';
			}
		}
		return 'mismatch';
	}

	/** Should this POST reach the handler? Logs every refusal (no body, no secret). */
	public static function allow( string $body, string $header, string $where ): bool {
		$r = self::check( $body, $header );
		if ( 'ok' === $r || 'no_secret' === $r ) {
			return true;
		}
		$log_only = 'log' === (string) get_option( self::OPT_MODE, 'enforce' );
		error_log( sprintf( '[BizCity FB webhook] %s signature %s (%s)', $where, $r, $log_only ? 'allowed: mode=log' : 'refused' ) );
		return $log_only;
	}

	public static function header(): string {
		return (string) ( $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '' );
	}
}
