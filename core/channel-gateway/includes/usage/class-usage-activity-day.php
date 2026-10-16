<?php
/**
 * `POST bizcity-channel/v1/usage/activity-day` — the site receives the CELL's daily ACTIVITY COUNTS (PHASE-0.112 S112-L1,
 * contract activity-day@1, fixture zalo-hub/contracts/fixtures/s112/activity-day.v1.json).
 *
 * Path: cell `activity-rollup` → Hub `POST /zalo-hub/activity-day` (pure forwarder, stores nothing) → here. Auth = exactly the usage-ledger check: the Bearer must
 * equal BizCity_Zalo_Bridge_Client::expected_inbound_token() of the number the Hub authenticated with (header `X-BizCity-Auth-Account`, else body `auth_account_id`).
 *
 * D112-2: the site READS the cell's counts, it does not count customers / replies / groups itself. One request = one (account, day) and OVERWRITES that whole row,
 * so a re-send is harmless and the running day is refreshed whenever a count changes. `measured:false` is stored as such: the reader answers null for it, so no
 * screen prints "0 customers" for a day the cell was not measuring (R-ACTIVITY-7).
 *
 * R-ACTIVITY-2: a batch carrying a money / token / cost / contact / text key at ANY depth is REFUSED (400 `forbidden_key`), not silently trimmed — a cell that sends one is broken.
 * Only counts are stored, capped (channels ≤ 20, task labels ≤ 30), strings cleaned.
 *
 * // [2026-10-15 05:50 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.112 S112-L1 — activity-day ingest + the one reader the bulletins and Thành tích use.
 *
 * @package BizCity_Twin_AI
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_Usage_Activity_Day {

	const NS       = 'bizcity-channel/v1';
	const CONTRACT = 'activity-day@1';
	const ID_RE    = '/^[A-Za-z0-9._:-]{1,64}$/';
	const FORBIDDEN = '/(^|_)(billed|cost|revenue|usd|vnd|token|tokens|price|markup|contact|contact_ref|phone|message|text|content)(_|$)/i';
	const MAX_CHANNELS = 20;
	const MAX_LABELS   = 30;

	/**
	 * Test seams: token(account): string · store(row): bool · now(): int · log(message, context): void
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
		register_rest_route( self::NS, '/usage/activity-day', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'handle' ),
			'permission_callback' => '__return_true', // per-account Bearer verified in handler (same as usage/ledger-ingest)
		) );
	}

	public static function handle( WP_REST_Request $request ): WP_REST_Response {
		$body = $request->get_json_params();
		$body = is_array( $body ) ? $body : array();
		$auth = self::clean_id( (string) $request->get_header( 'x_bizcity_auth_account' ) );
		if ( '' === $auth ) {
			$auth = self::clean_id( (string) ( $body['auth_account_id'] ?? '' ) );
		}
		if ( '' === $auth ) {
			$auth = self::clean_id( (string) ( $body['account_id'] ?? '' ) );
		}
		$token  = '' !== $auth ? self::token( $auth ) : '';
		$header = (string) $request->get_header( 'authorization' );
		$bearer = stripos( $header, 'Bearer ' ) === 0 ? trim( substr( $header, 7 ) ) : '';
		if ( '' === $token || '' === $bearer || ! hash_equals( $token, $bearer ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'unauthorized' ), 401 );
		}
		$account = self::clean_id( (string) ( $body['account_id'] ?? '' ) );
		$day     = (string) ( $body['day'] ?? '' );
		if ( 0 !== strpos( (string) ( $body['contract'] ?? '' ), self::CONTRACT ) || '' === $account || ! self::valid_day( $day ) || ! isset( $body['events'] ) || ! is_array( $body['events'] ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'invalid_param', 'message' => 'contract, account_id, day (YYYY-MM-DD) and events are required.' ), 400 );
		}
		$bad = self::forbidden_path( $body );
		if ( null !== $bad ) {
			self::log( 'activity-day@1 refused: forbidden key.', array( 'account_id' => $account, 'path' => $bad ) );
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'forbidden_key', 'message' => 'activity-day@1 carries counts only (no money, token, contact or text): ' . $bad ), 400 );
		}
		if ( ! isset( self::$readers['store'] ) && class_exists( 'BizCity_Usage_Schema' ) ) {
			BizCity_Usage_Schema::maybe_install();
		}
		$row = self::project( $account, $day, $body );
		if ( ! self::store( $row ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'store_failed' ), 500 );
		}
		do_action( 'bizcity_usage_activity_day_stored', $account, $day, $row );
		return new WP_REST_Response( array( 'ok' => true, 'contract' => self::CONTRACT, 'account_id' => $account, 'day' => $day, 'stored' => true ), 200 );
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

	/** First key path (any depth) that looks like money / token / contact / text, or null. */
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

	private static function count( $v ): int {
		return max( 0, min( 2000000000, (int) $v ) );
	}

	/** 0–20 with one decimal, or null ("chưa đủ việc để chấm") — never coerced to 0. */
	private static function efficiency( $v ): ?float {
		return is_numeric( $v ) ? round( max( 0.0, min( 20.0, (float) $v ) ), 1 ) : null;
	}

	private static function tag( string $s, int $max ): string {
		return substr( (string) preg_replace( '/[^a-z0-9_.:-]/', '', strtolower( trim( $s ) ) ), 0, $max );
	}

	private static function text( string $s, int $max ): string {
		$s = function_exists( 'wp_strip_all_tags' ) ? wp_strip_all_tags( $s ) : strip_tags( $s );
		$s = trim( (string) preg_replace( '/\s+/u', ' ', $s ) );
		return function_exists( 'mb_substr' ) ? mb_substr( $s, 0, $max ) : substr( $s, 0, $max );
	}

	/**
	 * One wire body ⇒ one stored row. Whitelisted, counts clamped, JSON parts capped.
	 *
	 * @param array<string,mixed> $b
	 * @return array<string,mixed>
	 */
	public static function project( string $account, string $day, array $b ): array {
		$e = (array) $b['events'];
		$g = isset( $b['gauges'] ) && is_array( $b['gauges'] ) ? $b['gauges'] : array();
		$labels = array();
		foreach ( (array) ( $e['tasks_by_label'] ?? array() ) as $label => $n ) {
			if ( count( $labels ) >= self::MAX_LABELS ) {
				break;
			}
			$l = self::text( (string) $label, 40 );
			if ( '' !== $l ) {
				$labels[ $l ] = self::count( $n );
			}
		}
		$kinds = array();
		foreach ( (array) ( $b['jobs_by_kind'] ?? array() ) as $label => $n ) {
			if ( count( $kinds ) >= self::MAX_LABELS ) {
				break;
			}
			$l = self::text( (string) $label, 40 );
			if ( '' !== $l ) {
				$kinds[ $l ] = self::count( $n );
			}
		}
		$channels = array();
		foreach ( (array) ( $e['by_channel'] ?? array() ) as $c ) {
			if ( ! is_array( $c ) || count( $channels ) >= self::MAX_CHANNELS ) {
				continue;
			}
			$channels[] = array(
				'platform'    => self::tag( (string) ( $c['platform'] ?? '' ), 24 ),
				'channel_ref' => self::text( (string) ( $c['channel_ref'] ?? '' ), 96 ),
				'label'       => self::text( (string) ( $c['label'] ?? '' ), 40 ),
				'customers'   => self::count( $c['customers'] ?? 0 ),
				'replies'     => self::count( $c['replies'] ?? 0 ),
				'comments'    => self::count( $c['comments'] ?? 0 ),
			);
		}
		return array(
			'account_id'         => $account,
			'day'                => $day,
			'measured'           => array_key_exists( 'measured', $b ) && ! $b['measured'] ? 0 : 1,
			'customers'          => self::count( $e['customers'] ?? 0 ),
			'replies'            => self::count( $e['replies'] ?? 0 ),
			'customers_new'      => self::count( $e['customers_new'] ?? 0 ),
			'replies_failed'     => self::count( $e['replies_failed'] ?? 0 ),
			'groups_active'      => self::count( $e['groups_active'] ?? 0 ),
			'tasks'              => self::count( $e['tasks'] ?? 0 ),
			'tasks_by_label'     => wp_json_encode( $labels ),
			'by_channel'         => wp_json_encode( $channels ),
			'groups_joined'      => self::count( $g['groups_joined'] ?? 0 ),
			'connections_active' => self::count( $g['connections_active'] ?? 0 ),
			'streak_days'        => self::count( $b['streak_days'] ?? 0 ),
			'ledger_to_seq'      => self::count( $b['ledger_to_seq'] ?? 0 ),
			// activity-day@1.1 (doc 20): the cell's completion points and its 0–20 efficiency score. NULL efficiency = not enough jobs to grade — never stored as 0.
			'points'             => self::count( $b['points'] ?? 0 ),
			'jobs_done'          => self::count( $b['jobs_done'] ?? 0 ),
			'jobs_failed'        => self::count( $b['jobs_failed'] ?? 0 ),
			'jobs_by_kind'       => wp_json_encode( $kinds ),
			'efficiency_score'   => self::efficiency( $b['efficiency_score'] ?? null ),
			'points_rev'         => substr( (string) preg_replace( '/[^A-Za-z0-9._:@-]/', '', (string) ( $b['points_rev'] ?? '' ) ), 0, 40 ),
			'received_at'        => gmdate( 'Y-m-d H:i:s', self::now() ),
		);
	}

	/* ── reading (the one place bulletins / Thành tích read the cell's counts) ─────────── */

	/**
	 * The cell's counts of one number for one day, or null when there is NO usable figure: no row yet (the cell has not sent it) or `measured = 0`.
	 * Null is "chưa có số" — callers must say so, never print it as zero (R-ACTIVITY-7).
	 *
	 * @return array<string,mixed>|null
	 */
	public static function for_day( string $account, string $day ): ?array {
		if ( isset( self::$readers['for_day'] ) ) {
			$r = call_user_func( self::$readers['for_day'], $account, $day );
			return is_array( $r ) ? $r : null;
		}
		global $wpdb;
		$t   = BizCity_Usage_Schema::activity_day_table();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE account_id = %s AND day = %s", $account, $day ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! is_array( $row ) || empty( $row['measured'] ) ) {
			return null;
		}
		return self::decode( $row );
	}

	/**
	 * Every usable (measured) row of one day in this site's table — all numbers AND the web / Messenger / OA accounts — decoded like for_day(). The admin bulletin sums these.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function rows_for_day( string $day ): array {
		if ( isset( self::$readers['rows_for_day'] ) ) {
			return (array) call_user_func( self::$readers['rows_for_day'], $day );
		}
		global $wpdb;
		$t    = BizCity_Usage_Schema::activity_day_table();
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE day = %s AND measured = 1 LIMIT 500", $day ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$out  = array();
		foreach ( $rows as $row ) {
			$out[] = self::decode( $row );
		}
		return $out;
	}

	/**
	 * Groups each number is in, from the cell's LATEST measured row of that number (`groups_joined` gauge). Numbers the cell has not reported are absent from the answer
	 * (callers show "—", never 0). One query for the whole list.
	 *
	 * @param string[] $accounts
	 * @return array<string,int> account_id ⇒ groups
	 */
	public static function latest_groups_joined( array $accounts ): array {
		$accounts = array_values( array_unique( array_filter( array_map( 'strval', $accounts ), static fn( $a ) => '' !== $a ) ) );
		if ( ! $accounts ) {
			return array();
		}
		if ( isset( self::$readers['latest_groups_joined'] ) ) {
			return (array) call_user_func( self::$readers['latest_groups_joined'], $accounts );
		}
		global $wpdb;
		$t  = BizCity_Usage_Schema::activity_day_table();
		$ph = implode( ',', array_fill( 0, count( $accounts ), '%s' ) );
		$rows = (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT a.account_id, a.groups_joined FROM {$t} a
			 JOIN ( SELECT account_id, MAX(day) AS d FROM {$t} WHERE measured = 1 AND account_id IN ({$ph}) GROUP BY account_id ) m ON m.account_id = a.account_id AND m.d = a.day",
			$accounts
		), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$out = array();
		foreach ( $rows as $r ) {
			$out[ (string) $r['account_id'] ] = (int) $r['groups_joined'];
		}
		return $out;
	}

	/**
	 * Completion points of the given numbers: the figure of `$day` and the running total of every stored day (doc 20 §7). null = the cell sent nothing measured for these numbers
	 * (callers print no points line, never "+0"). The total counts the days this site HAS received; the cell resends only today and yesterday.
	 *
	 * @param string[] $accounts
	 * @return array{day:int,total:int}|null
	 */
	public static function points_for( array $accounts, string $day ): ?array {
		$accounts = array_values( array_unique( array_filter( array_map( 'strval', $accounts ), static fn( $a ) => '' !== $a ) ) );
		if ( ! $accounts ) {
			return null;
		}
		if ( isset( self::$readers['points_for'] ) ) {
			$r = call_user_func( self::$readers['points_for'], $accounts, $day );
			return is_array( $r ) ? $r : null;
		}
		global $wpdb;
		$t  = BizCity_Usage_Schema::activity_day_table();
		$ph = implode( ',', array_fill( 0, count( $accounts ), '%s' ) );
		$r  = $wpdb->get_row( $wpdb->prepare(
			"SELECT COUNT(*) AS n, COALESCE(SUM(points),0) AS total, COALESCE(SUM(CASE WHEN day = %s THEN points ELSE 0 END),0) AS d FROM {$t} WHERE measured = 1 AND account_id IN ({$ph})",
			array_merge( array( $day ), $accounts )
		), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		return is_array( $r ) && (int) $r['n'] > 0 ? array( 'day' => (int) $r['d'], 'total' => (int) $r['total'] ) : null;
	}

	/** Completion-point stages (doc 20 §8, D112-10): lifetime points ⇒ stage. The digest's POINT_STAGES must stay identical (test). */
	const STAGES = array( 100 => 'Khởi động', 1000 => 'Vào guồng', 10000 => 'Thành thạo', 100000 => 'Chuyên gia' );

	/**
	 * Where a lifetime point total sits on the ladder.
	 *
	 * @return array{reached:?array{need:int,name:string},next:?array{need:int,name:string},remaining:int,pct:int}
	 */
	public static function stage_of( int $total ): array {
		$reached = null;
		$next    = null;
		$floor   = 0;
		foreach ( self::STAGES as $need => $name ) {
			if ( $total >= $need ) {
				$reached = array( 'need' => (int) $need, 'name' => $name );
				$floor   = (int) $need;
			} elseif ( null === $next ) {
				$next = array( 'need' => (int) $need, 'name' => $name );
			}
		}
		$remaining = null === $next ? 0 : $next['need'] - $total;
		$pct       = null === $next ? 100 : (int) floor( ( $total - $floor ) * 100 / max( 1, $next['need'] - $floor ) );
		return array( 'reached' => $reached, 'next' => $next, 'remaining' => $remaining, 'pct' => max( 0, min( 100, $pct ) ) );
	}

	/**
	 * The scoreboard figures per number (doc 20 §7), from the days this site HAS received (no money, no tokens). `$today` is the site-local date; 7d / 30d are the
	 * windows ending there. Efficiency is the latest NON-null score (the cell grades only with ≥ 20 jobs in 30 days).
	 *
	 * @param string[] $accounts
	 * @return array<string,array<string,mixed>> account_id ⇒ figures
	 */
	public static function scoreboard( array $accounts, string $today ): array {
		$accounts = array_values( array_unique( array_filter( array_map( 'strval', $accounts ), static fn( $a ) => '' !== $a ) ) );
		if ( ! $accounts ) {
			return array();
		}
		if ( isset( self::$readers['scoreboard_rows'] ) ) {
			$rows = (array) call_user_func( self::$readers['scoreboard_rows'], $accounts );
		} else {
			global $wpdb;
			$t    = BizCity_Usage_Schema::activity_day_table();
			$ph   = implode( ',', array_fill( 0, count( $accounts ), '%s' ) );
			$rows = (array) $wpdb->get_results( $wpdb->prepare(
				"SELECT account_id, day, points, jobs_done, jobs_failed, jobs_by_kind, efficiency_score FROM {$t} WHERE measured = 1 AND account_id IN ({$ph}) ORDER BY day DESC LIMIT 20000",
				$accounts
			), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		}
		$d7  = gmdate( 'Y-m-d', strtotime( $today . ' -6 days' ) );
		$d30 = gmdate( 'Y-m-d', strtotime( $today . ' -29 days' ) );
		$out = array();
		foreach ( $rows as $r ) {
			$id = (string) $r['account_id'];
			$o  = $out[ $id ] ?? array( 'points_today' => 0, 'points_7d' => 0, 'points_30d' => 0, 'points_total' => 0, 'jobs_done_30d' => 0, 'jobs_failed_30d' => 0, 'jobs_by_kind_30d' => array(), 'efficiency' => null, 'last_day' => '' );
			$day = (string) $r['day'];
			$p   = (int) $r['points'];
			$o['points_total'] += $p;
			if ( $day === $today ) {
				$o['points_today'] += $p;
			}
			if ( $day >= $d7 && $day <= $today ) {
				$o['points_7d'] += $p;
			}
			if ( $day >= $d30 && $day <= $today ) {
				$o['points_30d']      += $p;
				$o['jobs_done_30d']   += (int) $r['jobs_done'];
				$o['jobs_failed_30d'] += (int) $r['jobs_failed'];
				foreach ( (array) json_decode( (string) ( $r['jobs_by_kind'] ?? '[]' ), true ) as $k => $n ) {
					$o['jobs_by_kind_30d'][ (string) $k ] = (int) ( $o['jobs_by_kind_30d'][ (string) $k ] ?? 0 ) + (int) $n;
				}
			}
			if ( '' === $o['last_day'] ) {
				$o['last_day'] = $day; // rows come newest first
			}
			if ( null === $o['efficiency'] && isset( $r['efficiency_score'] ) && is_numeric( $r['efficiency_score'] ) ) {
				$o['efficiency'] = (float) $r['efficiency_score'];
			}
			$out[ $id ] = $o;
		}
		foreach ( $out as $id => $o ) {
			arsort( $o['jobs_by_kind_30d'] );
			$out[ $id ] = $o;
		}
		return $out;
	}

	/**
	 * The cell's rows of the 30 days ending at `$today`, newest first, decoded — what the maturity score reads (BizCity_Brain_Maturity::inputs_from_rows).
	 *
	 * @param string[] $accounts
	 * @return array<int,array<string,mixed>>
	 */
	public static function rows_for_maturity( array $accounts, string $today ): array {
		$accounts = array_values( array_unique( array_filter( array_map( 'strval', $accounts ), static fn( $a ) => '' !== $a ) ) );
		if ( ! $accounts ) {
			return array();
		}
		if ( isset( self::$readers['rows_for_maturity'] ) ) {
			return (array) call_user_func( self::$readers['rows_for_maturity'], $accounts, $today );
		}
		global $wpdb;
		$t    = BizCity_Usage_Schema::activity_day_table();
		$ph   = implode( ',', array_fill( 0, count( $accounts ), '%s' ) );
		$from = gmdate( 'Y-m-d', strtotime( $today . ' -29 days' ) );
		$rows = (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$t} WHERE measured = 1 AND day >= %s AND day <= %s AND account_id IN ({$ph}) ORDER BY day DESC LIMIT 20000",
			array_merge( array( $from, $today ), $accounts )
		), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		return array_map( array( __CLASS__, 'decode' ), $rows );
	}

	/** @param array<string,mixed> $row @return array<string,mixed> */
	private static function decode( array $row ): array {
		foreach ( array( 'customers', 'replies', 'customers_new', 'replies_failed', 'groups_active', 'tasks', 'groups_joined', 'connections_active', 'streak_days', 'ledger_to_seq', 'points', 'jobs_done', 'jobs_failed' ) as $k ) {
			$row[ $k ] = (int) ( $row[ $k ] ?? 0 );
		}
		$row['jobs_by_kind']     = (array) json_decode( (string) ( $row['jobs_by_kind'] ?? '[]' ), true );
		$row['efficiency_score'] = isset( $row['efficiency_score'] ) && is_numeric( $row['efficiency_score'] ) ? (float) $row['efficiency_score'] : null;
		$row['tasks_by_label'] = (array) json_decode( (string) ( $row['tasks_by_label'] ?? '[]' ), true );
		$row['by_channel']     = (array) json_decode( (string) ( $row['by_channel'] ?? '[]' ), true );
		return $row;
	}

	/* ── seams ───────────────────────────────────────────────────────── */

	private static function store( array $row ): bool {
		if ( isset( self::$readers['store'] ) ) {
			return (bool) call_user_func( self::$readers['store'], $row );
		}
		global $wpdb;
		$text    = array( 'account_id', 'day', 'tasks_by_label', 'by_channel', 'jobs_by_kind', 'points_rev', 'received_at' );
		$formats = array();
		foreach ( array_keys( $row ) as $col ) {
			$formats[] = in_array( $col, $text, true ) ? '%s' : ( 'efficiency_score' === $col ? '%f' : '%d' );
		}
		// a NULL efficiency stays NULL: wpdb::replace writes SQL NULL for a null value whatever its format.
		return false !== $wpdb->replace( BizCity_Usage_Schema::activity_day_table(), $row, $formats );
	}

	private static function now(): int {
		return isset( self::$readers['now'] ) ? (int) call_user_func( self::$readers['now'] ) : time();
	}

	private static function token( string $account ): string {
		if ( isset( self::$readers['token'] ) ) {
			return (string) call_user_func( self::$readers['token'], $account );
		}
		return class_exists( 'BizCity_Zalo_Bridge_Client' ) ? (string) BizCity_Zalo_Bridge_Client::instance()->expected_inbound_token( $account ) : '';
	}

	private static function log( string $message, array $context ): void {
		if ( isset( self::$readers['log'] ) ) {
			call_user_func( self::$readers['log'], $message, $context );
			return;
		}
		if ( class_exists( 'BizCity_Channel_File_Logger' ) && method_exists( 'BizCity_Channel_File_Logger', 'write' ) ) {
			BizCity_Channel_File_Logger::write( BizCity_Channel_File_Logger::CH_CHANNEL_GATEWAY, BizCity_Channel_File_Logger::LEVEL_WARN, 'usage_activity_day_refused', $message, $context );
		}
	}
}
