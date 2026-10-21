<?php
/**
 * `POST bizcity-channel/v1/usage/ops-day` — the site receives the OWNER copy of the cell's day as an Orchestrator (PHASE-0.127 S127-C2,
 * contract ops-day@1 audience=owner, fixture zalo-hub/contracts/fixtures/s127/ops-day.v1.json valid[1]).
 *
 * Path: cell `ops-day-sync` → Hub `POST /zalo-hub/ops-day` (forwarder; refuses money / token / level keys) → here. Auth = exactly activity-day's:
 * the Bearer must equal BizCity_Zalo_Bridge_Client::expected_inbound_token() of the number the Hub authenticated with.
 *
 * What it carries: prompts, background jobs opened / their outcomes, steps per job, owner questions, minutes p50, ≤ 20 job lines (goal clipped, phone masked
 * by the cell). Never tokens, money or the cell's maturity level (the admin copy stays on the cell dashboard, Q127-1). A key of that kind at ANY depth
 * ⇒ 400 `forbidden_key` (same rule as activity-day R-ACTIVITY-2).
 *
 * Storage (D127-3 "no new table", R-EXT-STORAGE-CONTEXT small data ⇒ option): ONE autoload=no option per number, `bizcity_ops_day_<account>`,
 * holding the last 14 days keyed by date. A re-send of a day overwrites that day.
 *
 * // [2026-10-21 09:29 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.127 S127-C2 — new file.
 *
 * @package BizCity_Twin_AI
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_Usage_Ops_Day {

	const NS        = 'bizcity-channel/v1';
	const CONTRACT  = 'ops-day@1';
	const ID_RE     = '/^[A-Za-z0-9._:-]{1,64}$/';
	const FORBIDDEN = '/^(?:token|tokens|cost|price|billed|revenue|usd|vnd|spend|level|levels|markup|phone|contact|contact_ref)(?:_|$)/i';
	const KEEP_DAYS = 14;
	const OPTION    = 'bizcity_ops_day_';

	/**
	 * Test seams: token(account): string · get(account): array · put(account, days): bool
	 *
	 * @var array<string,callable>
	 */
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
		register_rest_route( self::NS, '/usage/ops-day', array(
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle' ),
				'permission_callback' => '__return_true', // per-number Bearer verified in handler (same as usage/activity-day)
			),
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'rest_read' ),
				'permission_callback' => array( __CLASS__, 'can_read' ),
				'args'                => array( 'account_id' => array( 'required' => true ) ),
			),
		) );
	}

	/** reading a number's days: site admins, or whoever the `bizcity_ops_day_can_read` filter allows (the number's owner / BrainLedger, 0.126 brain-access) */
	public static function can_read( WP_REST_Request $request ): bool {
		$account = self::clean_id( (string) $request->get_param( 'account_id' ) );
		if ( '' === $account ) {
			return false;
		}
		$allowed = function_exists( 'current_user_can' ) && current_user_can( 'manage_options' );
		return (bool) apply_filters( 'bizcity_ops_day_can_read', $allowed, $account, function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0 );
	}

	public static function handle( WP_REST_Request $request ): WP_REST_Response {
		$body = $request->get_json_params();
		$body = is_array( $body ) ? $body : array();
		$auth = self::clean_id( (string) $request->get_header( 'x_bizcity_auth_account' ) );
		if ( '' === $auth ) {
			$auth = self::clean_id( (string) ( $body['auth_account_id'] ?? '' ) );
		}
		$token  = '' !== $auth ? self::token( $auth ) : '';
		$header = (string) $request->get_header( 'authorization' );
		$bearer = stripos( $header, 'Bearer ' ) === 0 ? trim( substr( $header, 7 ) ) : '';
		if ( '' === $token || '' === $bearer || ! hash_equals( $token, $bearer ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'unauthorized' ), 401 );
		}
		$account = self::clean_id( (string) ( $body['account_id'] ?? '' ) );
		$date    = (string) ( $body['date'] ?? '' );
		if ( 0 !== strpos( (string) ( $body['contract'] ?? '' ), self::CONTRACT ) || ( $body['audience'] ?? '' ) !== 'owner' || '' === $account || ! self::valid_day( $date ) || ! isset( $body['counts'] ) || ! is_array( $body['counts'] ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'invalid_param', 'message' => 'contract ops-day@1, audience owner, account_id, date (YYYY-MM-DD) and counts are required.' ), 400 );
		}
		$bad = self::forbidden_path( $body );
		if ( null !== $bad ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'forbidden_key', 'message' => 'ops-day@1 owner copy carries counts only (no token, money or level): ' . $bad ), 400 );
		}
		$days          = self::get( $account );
		$days[ $date ] = self::project( $body );
		krsort( $days );
		$days = array_slice( $days, 0, self::KEEP_DAYS, true );
		if ( ! self::put( $account, $days ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'store_failed' ), 500 );
		}
		do_action( 'bizcity_usage_ops_day_stored', $account, $date, $days[ $date ] );
		return new WP_REST_Response( array( 'ok' => true, 'contract' => self::CONTRACT, 'account_id' => $account, 'date' => $date, 'stored' => true ), 200 );
	}

	public static function rest_read( WP_REST_Request $request ): WP_REST_Response {
		$account = self::clean_id( (string) $request->get_param( 'account_id' ) );
		return new WP_REST_Response( array( 'ok' => true, 'contract' => self::CONTRACT, 'account_id' => $account, 'days' => self::days( $account ) ), 200 );
	}

	/** the stored days of a number, newest first (reader for BrainLedger "Hôm nay của trợ lý") */
	public static function days( string $account ): array {
		$days = self::get( $account );
		krsort( $days );
		return $days;
	}

	/* ── pure ───────────────────────────────────────────────────────── */

	public static function clean_id( string $raw ): string {
		$s = trim( $raw );
		return 1 === preg_match( self::ID_RE, $s ) ? $s : '';
	}

	public static function valid_day( string $day ): bool {
		if ( 1 !== preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $day, $m ) ) {
			return false;
		}
		return checkdate( (int) $m[2], (int) $m[3], (int) $m[1] );
	}

	public static function forbidden_path( $payload, string $path = '$' ): ?string {
		if ( ! is_array( $payload ) ) {
			return null;
		}
		foreach ( $payload as $k => $v ) {
			if ( is_string( $k ) && 1 === preg_match( self::FORBIDDEN, $k ) ) {
				return $path . '.' . $k;
			}
			$inner = self::forbidden_path( $v, $path . '.' . ( is_int( $k ) ? '[' . $k . ']' : $k ) );
			if ( null !== $inner ) {
				return $inner;
			}
		}
		return null;
	}

	/** only the known counts, capped; job lines ≤ 20 with clipped words */
	public static function project( array $b ): array {
		$c   = (array) $b['counts'];
		$n   = static function ( $v ): ?int { return is_numeric( $v ) ? max( 0, min( 2000000000, (int) $v ) ) : null; };
		$f   = static function ( $v ): ?float { return is_numeric( $v ) ? round( max( 0.0, (float) $v ), 2 ) : null; };
		$txt = static function ( $s, int $max ): string {
			$s = function_exists( 'wp_strip_all_tags' ) ? wp_strip_all_tags( (string) $s ) : strip_tags( (string) $s );
			$s = trim( (string) preg_replace( '/\s+/u', ' ', $s ) );
			return function_exists( 'mb_substr' ) ? mb_substr( $s, 0, $max ) : substr( $s, 0, $max );
		};
		$outcome = array();
		foreach ( (array) ( $c['jobs_outcome'] ?? array() ) as $k => $v ) {
			if ( is_string( $k ) && 1 === preg_match( '/^[a-z_]{2,30}$/', $k ) && count( $outcome ) < 12 ) {
				$outcome[ $k ] = (int) $n( $v );
			}
		}
		$jobs = array();
		foreach ( array_slice( (array) ( $b['jobs'] ?? array() ), 0, 20 ) as $j ) {
			if ( ! is_array( $j ) ) {
				continue;
			}
			$jobs[] = array(
				'job_id'  => 1 === preg_match( '/^job_[0-9A-Za-z_]{6,40}$/', (string) ( $j['job_id'] ?? '' ) ) ? (string) $j['job_id'] : '',
				'goal'    => $txt( $j['goal'] ?? '', 80 ),
				'status'  => 1 === preg_match( '/^[a-z_]{2,30}$/', (string) ( $j['status'] ?? '' ) ) ? (string) $j['status'] : '',
				'steps'   => 1 === preg_match( '/^\d{1,2}\/\d{1,2}$/', (string) ( $j['steps'] ?? '' ) ) ? (string) $j['steps'] : '',
				'minutes' => $f( $j['minutes'] ?? null ),
			);
		}
		$m = (array) ( $b['measured'] ?? array() );
		return array(
			'prompts'          => (int) $n( $c['prompts'] ?? 0 ),
			'jobs_opened'      => (int) $n( $c['jobs_opened'] ?? 0 ),
			'jobs_outcome'     => $outcome,
			'steps_avg'        => $f( $c['steps_avg'] ?? null ),
			'owner_asked'      => (int) $n( $c['owner_asked'] ?? 0 ),
			'duration_p50_min' => $f( $c['duration_p50_min'] ?? null ),
			'feedback_up'      => $n( $c['feedback_up'] ?? null ),
			'feedback_down'    => $n( $c['feedback_down'] ?? null ),
			'measured'         => array( 'turns' => ! empty( $m['turns'] ), 'jobs' => ! empty( $m['jobs'] ), 'traces' => ! empty( $m['traces'] ), 'feedback' => ! empty( $m['feedback'] ) ),
			'jobs'             => $jobs,
			'received_at'      => gmdate( 'Y-m-d\TH:i:s\Z' ),
		);
	}

	/* ── seams ─────────────────────────────────────────────────────── */

	private static function token( string $account ): string {
		if ( isset( self::$readers['token'] ) ) {
			return (string) call_user_func( self::$readers['token'], $account );
		}
		return class_exists( 'BizCity_Zalo_Bridge_Client' ) ? (string) BizCity_Zalo_Bridge_Client::instance()->expected_inbound_token( $account ) : '';
	}

	private static function get( string $account ): array {
		if ( isset( self::$readers['get'] ) ) {
			return (array) call_user_func( self::$readers['get'], $account );
		}
		$v = get_option( self::OPTION . $account, array() );
		return is_array( $v ) ? $v : array();
	}

	private static function put( string $account, array $days ): bool {
		if ( isset( self::$readers['put'] ) ) {
			return (bool) call_user_func( self::$readers['put'], $account, $days );
		}
		update_option( self::OPTION . $account, $days, false );
		return true;
	}
}
