<?php
/**
 * Month-by-month payback + cost-per-customer history for ONE Zalo Brain (PHASE-0.108 S108-L14, G-8 of 10-SPEC §9).
 *
 * What it feeds (brain-milestones@1 groups): `payback.silver` (3 consecutive CLOSED months where real revenue >= token cost),
 * `payback.gold` (12), `savings.bronze` (cost per customer fell >= 30 % from the first month with data to the last closed month).
 * It also gives the owner ladder its two missing counts (`payback_3mo_brains`, `diamond_tier_brains`) so Master / Grandmaster
 * stop failing closed.
 *
 * Rules (all pure, unit-tested):
 *  - Only CLOSED calendar months count — the running month is never part of a streak ("chốt theo tháng"), so a brain cannot
 *    earn silver halfway through a month it may still lose.
 *  - A month pays back only when `chi > 0` AND `thu >= chi` (an idle 0/0 month never trivially pays back — same rule as the
 *    bronze event in BizCity_Brain_ROI) and a missing month breaks the streak.
 *  - Real revenue is never mixed with the "làm thay" estimate (D108-5): `thu` is Woo revenue only.
 *
 * // [2026-10-14 12:50 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.108 S108-L14.
 *
 * @package BizCity_Twin_AI
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_Brain_Payback_History {

	/** Longest streak the catalog can ask for is 12; look back one extra so a 12-month streak is provable. */
	const LOOKBACK_MONTHS = 13;

	/**
	 * Test seam: series(account_id): array<'YYYY-MM',array{chi:float,thu:float,customers:int}>.
	 *
	 * @var array<string,callable>
	 */
	public static $readers = array();

	/* ================================================================
	 *  Pure rules
	 * ================================================================ */

	/** 'YYYY-MM' of the month before `$ym`. */
	public static function prev_month( string $ym ): string {
		list( $y, $m ) = array_map( 'intval', explode( '-', $ym ) );
		$m--;
		if ( $m < 1 ) {
			$m = 12;
			$y--;
		}
		return sprintf( '%04d-%02d', $y, $m );
	}

	/**
	 * Consecutive closed months ending at the month before `$current_ym` where thu >= chi and chi > 0.
	 *
	 * @param array<string,array{chi:float,thu:float,customers?:int}> $months
	 */
	public static function payback_streak( array $months, string $current_ym ): int {
		$streak = 0;
		$ym     = self::prev_month( $current_ym );
		while ( isset( $months[ $ym ] ) && $streak < 24 ) {
			$chi = (float) $months[ $ym ]['chi'];
			$thu = (float) $months[ $ym ]['thu'];
			if ( $chi <= 0 || $thu < $chi ) {
				break;
			}
			$streak++;
			$ym = self::prev_month( $ym );
		}
		return $streak;
	}

	/**
	 * Percent (0..100, integer, floor) cost-per-customer fell from the FIRST month with data to the LAST closed month.
	 * 0 when there are fewer than two usable closed months or the cost did not fall.
	 *
	 * @param array<string,array{chi:float,thu?:float,customers:int}> $months
	 */
	public static function cost_per_customer_drop_pct( array $months, string $current_ym ): int {
		$usable = array();
		foreach ( $months as $ym => $row ) {
			if ( $ym >= $current_ym ) {
				continue; // running month is not closed.
			}
			if ( (float) $row['chi'] > 0 && (int) ( $row['customers'] ?? 0 ) > 0 ) {
				$usable[ $ym ] = (float) $row['chi'] / (int) $row['customers'];
			}
		}
		if ( count( $usable ) < 2 ) {
			return 0;
		}
		ksort( $usable );
		$first = reset( $usable );
		$last  = end( $usable );
		if ( $first <= 0 || $last >= $first ) {
			return 0;
		}
		return (int) floor( ( ( $first - $last ) / $first ) * 100 );
	}

	/* ================================================================
	 *  Reader
	 * ================================================================ */

	/**
	 * Monthly series for one account, oldest → newest, at most LOOKBACK_MONTHS closed months plus the running month.
	 * Revenue is only looked up for months that have cost (idle months can never pay back, saving Woo queries).
	 *
	 * @return array<string,array{chi:float,thu:float,customers:int}>
	 */
	public static function series( string $account_id ): array {
		if ( isset( self::$readers['series'] ) ) {
			return (array) call_user_func( self::$readers['series'], $account_id );
		}
		if ( ! class_exists( 'BizCity_Usage_Schema' ) ) {
			return array();
		}
		global $wpdb;
		$daily  = BizCity_Usage_Schema::daily_table();
		$ledger = BizCity_Usage_Schema::ledger_table();
		$from   = gmdate( 'Y-m-01', strtotime( '-' . self::LOOKBACK_MONTHS . ' months', strtotime( gmdate( 'Y-m-01' ) ) ) );

		$cost = (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT SUBSTR(day,1,7) AS ym, COALESCE(SUM(billed_usd),0) AS chi FROM {$daily}
			 WHERE account_id = %s AND day >= %s AND kind <> 'tool' GROUP BY SUBSTR(day,1,7)",
			$account_id, $from
		), ARRAY_A );
		$cust = (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT SUBSTR(occurred_day,1,7) AS ym, COUNT(DISTINCT contact_key) AS customers FROM {$ledger}
			 WHERE account_id = %s AND kind = 'llm' AND actor_role = 'customer' AND contact_key <> '' AND occurred_day >= %s
			 GROUP BY SUBSTR(occurred_day,1,7)",
			$account_id, $from
		), ARRAY_A );
		$customers = array();
		foreach ( $cust as $r ) {
			$customers[ (string) $r['ym'] ] = (int) $r['customers'];
		}

		$out = array();
		foreach ( $cost as $r ) {
			$ym  = (string) $r['ym'];
			$chi = (float) $r['chi'];
			$thu = 0.0;
			if ( $chi > 0 && class_exists( 'BizCity_Brain_ROI' ) ) {
				$start = $ym . '-01 00:00:00';
				$end   = gmdate( 'Y-m-t 23:59:59', strtotime( $start ) );
				$thu   = BizCity_Brain_ROI::revenue_usd_between( $account_id, $start, $end );
			}
			$out[ $ym ] = array( 'chi' => $chi, 'thu' => $thu, 'customers' => (int) ( $customers[ $ym ] ?? 0 ) );
		}
		ksort( $out );
		return $out;
	}

	/** @return array{payback_streak_months:int,cost_per_customer_drop_pct:int} */
	public static function metrics_for( string $account_id ): array {
		$series  = self::series( $account_id );
		$current = gmdate( 'Y-m' );
		return array(
			'payback_streak_months'      => self::payback_streak( $series, $current ),
			'cost_per_customer_drop_pct' => self::cost_per_customer_drop_pct( $series, $current ),
		);
	}

	/* ================================================================
	 *  Owner counts (from the append-only journey — milestones are never revoked)
	 * ================================================================ */

	/**
	 * Brains of this owner that ever earned `payback.silver` (3 consecutive months) — the Master condition `payback_3mo_brains`.
	 */
	public static function payback_3mo_brains( int $owner_user_id ): int {
		if ( isset( self::$readers['owner_counts'] ) ) {
			return (int) ( (array) call_user_func( self::$readers['owner_counts'], $owner_user_id ) )['payback_3mo_brains'];
		}
		return self::count_distinct_brains( $owner_user_id, "code IN ('payback.silver','payback.gold')" );
	}

	/** Brains of this owner holding at least one `*.diamond` milestone — the Grandmaster condition `diamond_tier_brains`. */
	public static function diamond_tier_brains( int $owner_user_id ): int {
		if ( isset( self::$readers['owner_counts'] ) ) {
			return (int) ( (array) call_user_func( self::$readers['owner_counts'], $owner_user_id ) )['diamond_tier_brains'];
		}
		return self::count_distinct_brains( $owner_user_id, "tier = 'diamond'" );
	}

	private static function count_distinct_brains( int $owner_user_id, string $code_sql ): int {
		if ( $owner_user_id <= 0 || ! class_exists( 'BizCity_Brain_Journey_Schema' ) ) {
			return 0;
		}
		global $wpdb;
		$journey = BizCity_Brain_Journey_Schema::journey_table();
		// $code_sql is one of two literals above, never request input.
		return (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			"SELECT COUNT(DISTINCT subject_id) FROM {$journey} WHERE subject_kind = 'brain' AND owner_user_id = %d AND event IN ('milestone','payback') AND {$code_sql}",
			$owner_user_id
		) );
	}
}
