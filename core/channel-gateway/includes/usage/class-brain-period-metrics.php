<?php
/**
 * Per-window figures of one number (7d / 30d / month, each with the window right before it) — the data of the leaderboard's period picker
 * (PHASE-0.108 S108-L11a, 10-SPEC §3, decision D108-U1).
 *
 * Why not read `bizcity_usage_daily`: the daily roll-up has no `contact_key`, so the 20-per-customer-per-day cap (R-BRAIN-JOURNEY luật 3) cannot be
 * applied to it. These figures come from the per-turn ledger through the ONE shared rule (BizCity_Brain_Standing_Rules::capped_turns) and are cached in
 * `bizcity_brain_metrics_period`, one row per (account, window), rebuilt whole by the evaluator — a leaderboard request never scans the ledger.
 *
 * Money: `chi_usd` is token cost in USD (daily roll-up, LLM rows only); `thu_usd` is Woo revenue converted to USD by the Hub rate, 0 when there is no rate.
 *
 * // [2026-10-14 03:55 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.108 S108-L11a.
 *
 * @package BizCity_Twin_AI
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_Brain_Period_Metrics {

	/**
	 * Test seams: window(account_id, from_day, to_day): array{replies,customers,tasks,billed_tokens,chi_usd,thu_usd} · today(): "Y-m-d"
	 *
	 * @var array<string,callable>
	 */
	public static $readers = array();

	public static function today(): string {
		return isset( self::$readers['today'] ) ? (string) call_user_func( self::$readers['today'] ) : gmdate( 'Y-m-d' );
	}

	/**
	 * Rebuild every window of one account and store it. Called by the evaluator right after the lifetime metrics are saved.
	 *
	 * @return array<string,array<string,mixed>> range => row (for tests)
	 */
	public static function refresh( string $account_id, bool $force = false ): array {
		// A busy number triggers an ingest every few seconds; the leaderboard does not need minute-level freshness, so rebuild at most every 10 minutes (the hourly sweep always passes this gate).
		$gate = 'bizcity_brain_period_' . md5( $account_id );
		if ( ! $force && function_exists( 'get_transient' ) && ! isset( self::$readers['window'] ) && get_transient( $gate ) ) {
			return array();
		}
		if ( function_exists( 'set_transient' ) && ! isset( self::$readers['window'] ) ) {
			set_transient( $gate, 1, 10 * MINUTE_IN_SECONDS );
		}
		$today = self::today();
		$rows  = array();
		foreach ( BizCity_Brain_Board::RANGES as $range ) {
			$b    = BizCity_Brain_Board::range_bounds( $range, $today );
			$cur  = self::window( $account_id, $b['from'], $b['to'] );
			$prev = self::window( $account_id, $b['prev_from'], $b['prev_to'] );
			$rows[ $range ] = array(
				'account_id' => $account_id, 'range_key' => $range, 'period_from' => $b['from'], 'period_to' => $b['to'],
				'replies' => $cur['replies'], 'customers' => $cur['customers'], 'tasks' => $cur['tasks'], 'billed_tokens' => $cur['billed_tokens'],
				'chi_usd' => $cur['chi_usd'], 'thu_usd' => $cur['thu_usd'],
				'replies_prev' => $prev['replies'], 'customers_prev' => $prev['customers'], 'chi_usd_prev' => $prev['chi_usd'], 'thu_usd_prev' => $prev['thu_usd'],
			);
			self::save( $rows[ $range ] );
		}
		return $rows;
	}

	/**
	 * Figures of one account between two UTC days (inclusive).
	 *
	 * @return array{replies:int,customers:int,tasks:int,billed_tokens:int,chi_usd:float,thu_usd:float}
	 */
	public static function window( string $account_id, string $from_day, string $to_day ): array {
		if ( isset( self::$readers['window'] ) ) {
			return (array) call_user_func( self::$readers['window'], $account_id, $from_day, $to_day );
		}
		global $wpdb;
		$rules  = BizCity_Brain_Milestones_Catalog::rules();
		$cap    = max( 1, (int) ( $rules['customer_reply_daily_cap_per_contact'] ?? 20 ) );
		$role   = (string) ( $rules['actor_role_for_value_metrics'] ?? 'customer' );
		$ledger = BizCity_Usage_Schema::ledger_table();
		$daily  = BizCity_Usage_Schema::daily_table();

		$groups = (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT contact_key, occurred_day, COUNT(DISTINCT turn_ref) AS day_turns FROM {$ledger}
			 WHERE account_id = %s AND kind = 'llm' AND actor_role = %s AND contact_key <> '' AND occurred_day BETWEEN %s AND %s
			 GROUP BY contact_key, occurred_day",
			$account_id, $role, $from_day, $to_day
		), ARRAY_A );
		$rule_groups = array();
		foreach ( $groups as $g ) {
			$rule_groups[] = array( 'contact' => (string) $g['contact_key'], 'day' => (string) $g['occurred_day'], 'turns' => (int) $g['day_turns'] );
		}
		$capped = BizCity_Brain_Standing_Rules::capped_turns( $rule_groups, $cap );

		$tasks = 0;
		$tools = (array) get_option( BizCity_Brain_Journey_Evaluator::OPT_ACTION_TOOLS, array() );
		$tools = (array) apply_filters( 'bizcity_brain_action_tool_names', $tools, $account_id );
		if ( $tools ) {
			$ph    = implode( ',', array_fill( 0, count( $tools ), '%s' ) );
			$tasks = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$ledger} WHERE account_id = %s AND kind = 'tool' AND is_error = 0 AND occurred_day BETWEEN %s AND %s AND tool IN ({$ph})",
				array_merge( array( $account_id, $from_day, $to_day ), array_values( $tools ) )
			) );
		}

		$cost = (array) $wpdb->get_row( $wpdb->prepare(
			"SELECT COALESCE(SUM(billed_tokens),0) AS tok, COALESCE(SUM(CASE WHEN kind <> 'tool' THEN billed_usd ELSE 0 END),0) AS chi
			 FROM {$daily} WHERE account_id = %s AND day BETWEEN %s AND %s",
			$account_id, $from_day, $to_day
		), ARRAY_A );

		$thu = class_exists( 'BizCity_Brain_ROI' ) ? BizCity_Brain_ROI::revenue_usd_between( $account_id, $from_day . ' 00:00:00', $to_day . ' 23:59:59' ) : 0.0;

		return array(
			'replies' => $capped['turns'], 'customers' => $capped['customers'], 'tasks' => $tasks,
			'billed_tokens' => (int) ( $cost['tok'] ?? 0 ), 'chi_usd' => (float) ( $cost['chi'] ?? 0 ), 'thu_usd' => (float) $thu,
		);
	}

	/** @param array<string,mixed> $row */
	private static function save( array $row ): void {
		if ( isset( self::$readers['save'] ) ) {
			call_user_func( self::$readers['save'], $row );
			return;
		}
		global $wpdb;
		$row['computed_at'] = current_time( 'mysql', true );
		$wpdb->replace( BizCity_Brain_Journey_Schema::period_table(), $row );
	}

	/**
	 * Every stored row of one window, keyed by account id. Rows older than 2 days are still served (the hourly sweep refreshes them);
	 * the caller shows `computed_at` only through the leaderboard's "cập nhật lúc".
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function load( string $range ): array {
		if ( isset( self::$readers['load'] ) ) {
			return (array) call_user_func( self::$readers['load'], $range );
		}
		global $wpdb;
		$rows = (array) $wpdb->get_results( $wpdb->prepare(
			'SELECT * FROM ' . BizCity_Brain_Journey_Schema::period_table() . ' WHERE range_key = %s', $range // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		), ARRAY_A );
		$out = array();
		foreach ( $rows as $r ) {
			$out[ (string) $r['account_id'] ] = $r;
		}
		return $out;
	}
}
