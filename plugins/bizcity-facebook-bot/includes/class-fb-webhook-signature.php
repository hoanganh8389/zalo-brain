<?php
/**
 * Facebook webhook signature (X-Hub-Signature-256) for the legacy entry points `/bizfbhook/` (`?fbhook=1`) and the
 * network `/facehook/` — PHASE-0.93 (the "⚙ App của bạn" sheet points Meta at `/bizfbhook/`).
 *
 * Before this, both endpoints handed ANY POST to the bot and the cell: whoever knew the URL could inject a "customer
 * message". Meta signs every event with the app secret: sha256=HMAC(body, app_secret).
 *
 * [2026-10-25 Johnny Chu - Chu Hoàng Anh] PHASE-0.126 R126-SEC H3 — fail closed, secret bound to the page:
 *   - site/network secrets (options bizcity_facebook_bot_app_secret, bztfb_app_secret, fb_app_secret, network option
 *     bizcity_fb_app_secret) are the admin-configured apps and verify events for ANY page of this site — the payload does
 *     not say which app signed it, so these stay "any known site app" (documented limit);
 *   - a per-bot app_secret (bizcity_facebook_bots.app_secret, e.g. a user's own app) verifies ONLY events whose every
 *     entry.id is a page stored with that same secret — one user's app secret can no longer forge another page's events;
 *   - no secret known at all ⇒ REFUSED (was: allowed); missing / wrong header ⇒ refused;
 *   - the option `bizcity_fb_signature_mode` = 'log' escape is removed (always enforce).
 * The GET verify-token handshake is not affected (handled by the callers, not by this class).
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

	/** @var array<string,callable> Tests: secrets(): string[] (treated as site-level secrets), page_secrets(): array<string,string[]>. */
	public static $seams = array();

	/** @return string[] [2026-10-25 Johnny Chu - Chu Hoàng Anh] PHASE-0.126 R126-SEC H3 — site/network-level app secrets (valid for every page) */
	public static function site_secrets(): array {
		if ( isset( self::$seams['secrets'] ) ) {
			return self::clean( (array) call_user_func( self::$seams['secrets'] ) );
		}
		return self::clean( array(
			(string) get_option( 'bizcity_facebook_bot_app_secret', '' ),
			(string) get_option( 'bztfb_app_secret', '' ),
			(string) get_option( 'fb_app_secret', '' ),
			function_exists( 'get_site_option' ) ? (string) get_site_option( 'bizcity_fb_app_secret', '' ) : '',
		) );
	}

	/** @return array<string,string[]> [2026-10-25 Johnny Chu - Chu Hoàng Anh] PHASE-0.126 R126-SEC H3 — page_id => per-bot app secrets stored for that page */
	public static function page_secrets(): array {
		if ( isset( self::$seams['page_secrets'] ) ) {
			return (array) call_user_func( self::$seams['page_secrets'] );
		}
		if ( isset( self::$seams['secrets'] ) ) {
			return array();
		}
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return array();
		}
		$wpdb->suppress_errors( true );
		$rows = $wpdb->get_results( "SELECT page_id, app_secret FROM {$wpdb->prefix}bizcity_facebook_bots WHERE app_secret IS NOT NULL AND app_secret != '' AND page_id IS NOT NULL AND page_id != '' LIMIT 500" );
		$wpdb->suppress_errors( false );
		$out = array();
		foreach ( (array) $rows as $r ) {
			$page   = trim( (string) ( $r->page_id ?? '' ) );
			$secret = (string) ( $r->app_secret ?? '' );
			if ( '' === $page || '' === trim( $secret ) ) {
				continue;
			}
			$out[ $page ][] = $secret;
		}
		foreach ( $out as $page => $list ) {
			$out[ $page ] = array_values( array_unique( $list ) );
		}
		return $out;
	}

	/** @return string[] distinct non-empty app secrets this site/network knows (site-level + per-bot) — kept for existing callers */
	public static function secrets(): array {
		$all = self::site_secrets();
		foreach ( self::page_secrets() as $list ) {
			$all = array_merge( $all, (array) $list );
		}
		return self::clean( $all );
	}

	/** @return string 'ok' | 'no_secret' | 'missing' | 'unbound' | 'mismatch' */
	public static function check( string $body, string $header ): string {
		$r = self::resolve( $body, $header );
		return $r['status'];
	}

	/**
	 * [2026-10-14 10:22 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.109 S109-L19 — the App Secret that ACTUALLY verifies this request ('' when none does). The cell must be given exactly this one: a site can hold several
	 * (option, network, per-bot column) and guessing which is Meta's was the way a wrong secret would silently make the cell refuse everything.
	 * [2026-10-25 Johnny Chu - Chu Hoàng Anh] PHASE-0.126 R126-SEC H3 — same page binding as allow(): an unbound per-bot secret returns ''.
	 */
	public static function matching_secret( string $body, string $header ): string {
		$r = self::resolve( $body, $header );
		return 'ok' === $r['status'] ? $r['secret'] : '';
	}

	/** Should this POST reach the handler? Logs every refusal (no body, no secret). Fail closed: only 'ok' passes. */
	public static function allow( string $body, string $header, string $where ): bool {
		$r = self::check( $body, $header );
		if ( 'ok' === $r ) {
			return true;
		}
		// [2026-10-25 Johnny Chu - Chu Hoàng Anh] PHASE-0.126 R126-SEC H3 — no 'log' mode, no 'no_secret' pass: every non-ok result is refused.
		error_log( sprintf( '[BizCity FB webhook] %s signature %s (refused)', $where, $r ) );
		return false;
	}

	public static function header(): string {
		return (string) ( $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '' );
	}

	/**
	 * [2026-10-25 Johnny Chu - Chu Hoàng Anh] PHASE-0.126 R126-SEC H3 — verify + bind.
	 *
	 * @return array{status:string,secret:string}
	 */
	private static function resolve( string $body, string $header ): array {
		$site  = self::site_secrets();
		$pages = self::page_secrets();
		if ( ! $site && ! $pages ) {
			return array( 'status' => 'no_secret', 'secret' => '' );
		}
		$header = trim( $header );
		if ( '' === $header ) {
			return array( 'status' => 'missing', 'secret' => '' );
		}
		foreach ( $site as $s ) {
			if ( self::sig_ok( $body, $s, $header ) ) {
				return array( 'status' => 'ok', 'secret' => (string) $s );
			}
		}
		$entry_pages = self::entry_page_ids( $body );
		$unbound     = false;
		$tried       = array();
		foreach ( $pages as $list ) {
			foreach ( (array) $list as $s ) {
				$s = (string) $s;
				if ( isset( $tried[ $s ] ) ) {
					continue;
				}
				$tried[ $s ] = true;
				if ( ! self::sig_ok( $body, $s, $header ) ) {
					continue;
				}
				// the signing secret must belong to EVERY page the payload claims to be about
				$bound = ! empty( $entry_pages );
				foreach ( $entry_pages as $p ) {
					if ( empty( $pages[ $p ] ) || ! in_array( $s, (array) $pages[ $p ], true ) ) {
						$bound = false;
						break;
					}
				}
				if ( $bound ) {
					return array( 'status' => 'ok', 'secret' => $s );
				}
				$unbound = true;
			}
		}
		return array( 'status' => $unbound ? 'unbound' : 'mismatch', 'secret' => '' );
	}

	private static function sig_ok( string $body, string $secret, string $header ): bool {
		return hash_equals( 'sha256=' . hash_hmac( 'sha256', $body, $secret ), $header );
	}

	/** @return string[] distinct entry[].id of a Meta page payload */
	private static function entry_page_ids( string $body ): array {
		$data = json_decode( $body, true );
		if ( ! is_array( $data ) || empty( $data['entry'] ) || ! is_array( $data['entry'] ) ) {
			return array();
		}
		$ids = array();
		foreach ( $data['entry'] as $entry ) {
			$id = is_array( $entry ) ? trim( (string) ( $entry['id'] ?? '' ) ) : '';
			if ( '' === $id ) {
				return array(); // an entry without a page cannot be bound
			}
			$ids[ $id ] = true;
		}
		return array_map( 'strval', array_keys( $ids ) );
	}

	/** @return string[] */
	private static function clean( array $list ): array {
		return array_values( array_unique( array_filter( array_map( 'strval', $list ), static function ( $s ) { return '' !== trim( $s ); } ) ) );
	}
}
