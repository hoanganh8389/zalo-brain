<?php
/**
 * Settings of the "Thành tích" tab (PHASE-0.108 S108-L15, 10-SPEC §6): the knobs behind the "làm thay ≈" estimate, the list of
 * "việc xong" tools, and the daily bulletin switches.
 *
 *   GET/PUT /bizcity-channel/v1/brain/settings     — site ADMIN only (manage_options): minutes per reply, wage per hour,
 *                                                    action tool list, bulletin on/off + send time. GET also returns the tool
 *                                                    names already seen in the ledger so the owner picks, not types.
 *   GET/PUT /bizcity-channel/v1/brain/settings/me  — any logged-in person: ONLY their own bulletin switch (user meta).
 *
 * Storage is the options the evaluator / ROI already read (OPT_MINUTES, OPT_WAGE_VND, OPT_ACTION_TOOLS) — this class adds no table;
 * it only gives those options a REST door with hard bounds, so a typo cannot turn the estimate into nonsense. Defaults are the
 * Q108-9 proposals (2 min / reply, 30.000 ₫ / hour) and are NOT locked by the owner yet.
 *
 * // [2026-10-14 02:00 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.108 S108-L15.
 *
 * @package BizCity_Twin_AI
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_Brain_Settings {

	const NS = 'bizcity-channel/v1';

	const OPT_DIGEST_ON   = 'bizcity_brain_digest_enabled';  // site default for the owner bulletin (Q: mặc định bật)
	const OPT_DIGEST_TIME = 'bizcity_brain_digest_time';     // "HH:MM" site time
	const META_DIGEST_OFF = 'bizcity_brain_digest_off';      // user meta: '1' = this person opted out

	const MINUTES_MIN = 0.5;
	const MINUTES_MAX = 60.0;
	const WAGE_MAX    = 10000000.0;
	const TOOLS_MAX   = 100;

	private static $initialized = false;

	/** @var array<string,callable> test seam: tools_seen() → string[] */
	public static $readers = array();

	public static function init(): void {
		if ( self::$initialized ) {
			return;
		}
		self::$initialized = true;
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes(): void {
		register_rest_route( self::NS, '/brain/settings', array(
			array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'route_get' ), 'permission_callback' => array( __CLASS__, 'can_admin' ) ),
			array( 'methods' => 'PUT', 'callback' => array( __CLASS__, 'route_put' ), 'permission_callback' => array( __CLASS__, 'can_admin' ) ),
		) );
		register_rest_route( self::NS, '/brain/settings/me', array(
			array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'route_me_get' ), 'permission_callback' => array( __CLASS__, 'can_me' ) ),
			array( 'methods' => 'PUT', 'callback' => array( __CLASS__, 'route_me_put' ), 'permission_callback' => array( __CLASS__, 'can_me' ) ),
		) );
	}

	public static function can_admin(): bool {
		return function_exists( 'current_user_can' ) && current_user_can( 'manage_options' );
	}

	public static function can_me(): bool {
		return function_exists( 'is_user_logged_in' ) && is_user_logged_in();
	}

	/* ================================================================
	 *  Pure sanitizers (unit-tested)
	 * ================================================================ */

	public static function clean_minutes( $v ): float {
		$f = is_numeric( $v ) ? (float) $v : 2.0;
		return round( min( self::MINUTES_MAX, max( self::MINUTES_MIN, $f ) ), 1 );
	}

	public static function clean_wage( $v ): int {
		$f = is_numeric( $v ) ? (float) $v : 30000.0;
		return (int) round( min( self::WAGE_MAX, max( 0.0, $f ) ) );
	}

	/** "HH:MM" 24h; anything else → default 07:30. */
	public static function clean_time( $v ): string {
		$s = is_string( $v ) ? trim( $v ) : '';
		if ( preg_match( '/^([01]?\d|2[0-3]):([0-5]\d)$/', $s, $m ) ) {
			return sprintf( '%02d:%s', (int) $m[1], $m[2] );
		}
		return '07:30';
	}

	/**
	 * Tool names: letters, digits, `._:-`, max 80 chars, unique, order kept, capped. A name the ledger column cannot hold (VARCHAR 80)
	 * or that contains anything else is dropped, never "fixed" into a different tool.
	 *
	 * @param mixed $v
	 * @return string[]
	 */
	public static function clean_tools( $v ): array {
		$out = array();
		foreach ( is_array( $v ) ? $v : array() as $name ) {
			if ( ! is_string( $name ) ) {
				continue;
			}
			$name = trim( $name );
			if ( '' === $name || strlen( $name ) > 80 || ! preg_match( '/^[A-Za-z0-9._:\-]+$/', $name ) ) {
				continue;
			}
			$out[ $name ] = true;
			if ( count( $out ) >= self::TOOLS_MAX ) {
				break;
			}
		}
		return array_keys( $out );
	}

	/** The "làm thay" formula, shown live under the two inputs so the owner sees the effect (10-SPEC §6). */
	public static function formula_preview( float $minutes, int $wage_vnd, int $sample_replies = 100 ): array {
		$hours = ( $sample_replies * $minutes ) / 60;
		return array( 'sample_replies' => $sample_replies, 'hours' => round( $hours, 1 ), 'vnd_estimate' => (int) round( $hours * $wage_vnd ), 'estimate' => true );
	}

	/* ================================================================
	 *  Current values
	 * ================================================================ */

	public static function current(): array {
		$minutes = self::clean_minutes( get_option( BizCity_Brain_ROI::OPT_MINUTES, 2 ) );
		$wage    = self::clean_wage( get_option( BizCity_Brain_ROI::OPT_WAGE_VND, 30000 ) );
		return array(
			'minutes_per_reply' => $minutes,
			'wage_vnd_per_hour' => $wage,
			'action_tools'      => self::clean_tools( (array) get_option( BizCity_Brain_Journey_Evaluator::OPT_ACTION_TOOLS, array() ) ),
			'digest_enabled'    => (bool) get_option( self::OPT_DIGEST_ON, true ),
			'digest_time'       => self::clean_time( get_option( self::OPT_DIGEST_TIME, '07:30' ) ),
		);
	}

	/** Tool names that actually appear in the ledger (kind=tool), most used first — so the owner picks from real names. */
	public static function tools_seen( int $limit = 60 ): array {
		if ( isset( self::$readers['tools_seen'] ) ) {
			return (array) call_user_func( self::$readers['tools_seen'] );
		}
		if ( ! class_exists( 'BizCity_Usage_Schema' ) || ! BizCity_Usage_Schema::installed() ) {
			return array();
		}
		global $wpdb;
		$ledger = BizCity_Usage_Schema::ledger_table();
		$rows   = (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT tool, COUNT(*) AS n FROM {$ledger} WHERE kind = 'tool' AND tool <> '' GROUP BY tool ORDER BY n DESC LIMIT %d",
			max( 1, $limit )
		), ARRAY_A );
		return array_map( static function ( $r ) { return array( 'tool' => (string) $r['tool'], 'calls' => (int) $r['n'] ); }, $rows );
	}

	/* ================================================================
	 *  Routes
	 * ================================================================ */

	public static function route_get(): WP_REST_Response {
		$cur = self::current();
		return new WP_REST_Response( array(
			'contract'     => 'brain-journey@1',
			'settings'     => $cur,
			'tools_seen'   => self::tools_seen(),
			'preview'      => self::formula_preview( $cur['minutes_per_reply'], $cur['wage_vnd_per_hour'] ),
			'defaults'     => array( 'minutes_per_reply' => 2, 'wage_vnd_per_hour' => 30000, 'digest_time' => '07:30', 'digest_enabled' => true ),
		) );
	}

	public static function route_put( WP_REST_Request $request ): WP_REST_Response {
		$body = $request->get_json_params();
		$body = is_array( $body ) ? $body : array();
		// Only the keys the caller sent are changed; an absent key keeps its stored value.
		if ( array_key_exists( 'minutes_per_reply', $body ) ) {
			update_option( BizCity_Brain_ROI::OPT_MINUTES, self::clean_minutes( $body['minutes_per_reply'] ), false );
		}
		if ( array_key_exists( 'wage_vnd_per_hour', $body ) ) {
			update_option( BizCity_Brain_ROI::OPT_WAGE_VND, self::clean_wage( $body['wage_vnd_per_hour'] ), false );
		}
		if ( array_key_exists( 'action_tools', $body ) ) {
			update_option( BizCity_Brain_Journey_Evaluator::OPT_ACTION_TOOLS, self::clean_tools( $body['action_tools'] ), false );
		}
		if ( array_key_exists( 'digest_enabled', $body ) ) {
			update_option( self::OPT_DIGEST_ON, (bool) $body['digest_enabled'] ? 1 : 0, false );
		}
		if ( array_key_exists( 'digest_time', $body ) ) {
			update_option( self::OPT_DIGEST_TIME, self::clean_time( $body['digest_time'] ), false );
		}
		return self::route_get();
	}

	public static function owner_digest_on( int $user_id ): bool {
		return '1' !== (string) get_user_meta( $user_id, self::META_DIGEST_OFF, true );
	}

	public static function route_me_get(): WP_REST_Response {
		return new WP_REST_Response( array(
			'digest_enabled' => self::owner_digest_on( get_current_user_id() ),
			'site_enabled'   => (bool) get_option( self::OPT_DIGEST_ON, true ),
		) );
	}

	public static function route_me_put( WP_REST_Request $request ): WP_REST_Response {
		$body = $request->get_json_params();
		$body = is_array( $body ) ? $body : array();
		if ( array_key_exists( 'digest_enabled', $body ) ) {
			$uid = get_current_user_id();
			if ( (bool) $body['digest_enabled'] ) {
				delete_user_meta( $uid, self::META_DIGEST_OFF );
			} else {
				update_user_meta( $uid, self::META_DIGEST_OFF, '1' );
			}
		}
		return self::route_me_get();
	}
}
