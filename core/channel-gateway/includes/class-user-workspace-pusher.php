<?php
/**
 * BizCity Channel Gateway — sends ONE person's user-workspace@1 bundle to the cell (PHASE-0.116 S116-I5).
 *
 *   site (this class)  →  Hub  PUT zalo-hub/user-workspace  →  cell  /wp/user-workspace   (the cell keeps it by (tenant, user_ref, rev))
 *
 * When: a person logs in, their role changes, or their profile is saved. The builder only raises `rev` when the content changed; the pusher
 * remembers the last rev the cell accepted (user meta) and sends only a NEWER one, at most once a minute per person. A failure never blocks
 * a login: it is swallowed and retried at the next trigger. The bundle holds `user_ref` only — no raw id, phone, email or token.
 *
 * @package BizCity_Channel_Gateway
 */
// [2026-10-16 09:40 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.116 S116-I5

defined( 'ABSPATH' ) || exit;

final class BizCity_User_Workspace_Pusher {

	const META_PUSHED = '_bizcity_uw_pushed_rev';
	const DEBOUNCE_S  = 60;
	const HUB         = '/zalo-hub/user-workspace';
	const TIMEOUT     = 15;

	/** @var callable|null Tests: fn(array $bundle): array{status:int, body:array} */
	public static $hub_seam = null;

	public static function init(): void {
		add_action( 'wp_login', array( __CLASS__, 'on_login' ), 20, 2 );
		add_action( 'set_user_role', array( __CLASS__, 'on_user' ), 20, 1 );
		add_action( 'profile_update', array( __CLASS__, 'on_user' ), 20, 1 );
	}

	/** @param string $login @param WP_User $user */
	public static function on_login( $login, $user ): void {
		unset( $login );
		if ( $user instanceof WP_User ) {
			self::on_user( (int) $user->ID );
		}
	}

	public static function on_user( $user_id ): void {
		try {
			self::push( (int) $user_id );
		} catch ( \Throwable $e ) {
			// never break a login / save because the cell is unreachable
			unset( $e );
		}
	}

	/** @return string pushed | same | debounced | unknown_user | no_hub | failed */
	public static function push( int $user_id ): string {
		if ( $user_id <= 0 || ! class_exists( 'BizCity_User_Workspace_Builder' ) ) {
			return 'unknown_user';
		}
		$key = 'bizcity_uw_push_' . $user_id;
		if ( false !== get_transient( $key ) ) {
			return 'debounced';
		}
		set_transient( $key, 1, self::DEBOUNCE_S );
		$bundle = BizCity_User_Workspace_Builder::build( $user_id );
		if ( ! is_array( $bundle ) ) {
			return 'unknown_user';
		}
		$rev = (int) ( $bundle['rev'] ?? 0 );
		if ( $rev <= (int) get_user_meta( $user_id, self::META_PUSHED, true ) ) {
			return 'same';
		}
		$r = self::send( $bundle );
		if ( null === $r ) {
			return 'no_hub';
		}
		if ( $r['status'] >= 200 && $r['status'] < 300 ) {
			update_user_meta( $user_id, self::META_PUSHED, $rev );
			return 'pushed';
		}
		return 'failed';
	}

	/** @return array{status:int, body:array}|null null when this site has no Hub key / client */
	private static function send( array $bundle ): ?array {
		if ( is_callable( self::$hub_seam ) ) {
			return (array) call_user_func( self::$hub_seam, $bundle );
		}
		if ( ! class_exists( 'BizCity_LLM_Client' ) || ! class_exists( 'BizCity_Connect_REST' ) || '' === BizCity_Connect_REST::api_key() ) {
			return null;
		}
		$raw    = BizCity_LLM_Client::instance()->gateway_post( self::HUB . '?_method=PUT', $bundle, self::TIMEOUT, false );
		$raw    = is_array( $raw ) ? $raw : array();
		$status = (int) ( $raw['http_code'] ?? ( ! empty( $raw['_degraded'] ) ? 424 : 200 ) );
		return array( 'status' => $status, 'body' => $raw );
	}
}
