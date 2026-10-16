<?php
/**
 * The pure rules behind the "Thành tích" leaderboard and a brain's journey (PHASE-0.108 S108-L11a/L11b/L12, 10-SPEC §2–§4, §9).
 *
 * No database, no WordPress: every function takes the numbers and returns the answer, so the screen's rules are tested once
 * (BrainBoardS108Test) and the REST layer only gathers the rows. What lives here:
 *   - range_bounds()      — the 7d / 30d / month window and the equally long window right before it (for ▲▼)
 *   - flags               — 💤 sleeping (AI on + idle ≥ 3 days) · 🔥 expensive (cost per reply > 2 × site median) · ⏳ plan about to run out
 *   - rank()              — the four sort tabs (Chăm chỉ · Hiệu quả · Kiếm tiền · Học giỏi) with their tie-breaks and exclusions
 *   - next_milestones()   — the 3 closest unreached tiers (one component for ① and ③, G5)
 *   - badges()            — the 9-cell cabinet of ③
 *
 * Money stays in USD here; the REST layer multiplies by the Hub rate (G3) and never guesses a rate.
 *
 * // [2026-10-14 03:40 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.108 S108-L11a/L11b/L12.
 *
 * @package BizCity_Twin_AI
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_Brain_Board {

	const RANGES        = array( '7d', '30d', 'month' );
	const SORTS         = array( 'hardworking', 'efficient', 'earning', 'trained' );
	const SLEEP_DAYS    = 3;     // D108-U4 / 10-SPEC §3: AI on and no customer for 3 days
	const EXPENSIVE_X   = 2.0;   // 🔥 = cost per reply above 2 × the site median
	const TRIAL_DAYS    = 2;     // ⏳ D108-U4
	const PLAN_DAYS     = 3;
	const ALLOWANCE_PCT = 90;

	/** Tier order, lowest first. */
	const TIERS = array( 'bronze', 'silver', 'gold', 'diamond' );

	public static function range( $r ): string {
		return in_array( $r, self::RANGES, true ) ? (string) $r : '30d';
	}

	/**
	 * The window of a range and the equally long window just before it. Days are UTC "Y-m-d", inclusive.
	 * `month` = the 1st … today; its previous window = the same number of days of the month before (capped at that month's end),
	 * so "7 ngày đầu tháng" is never compared with a whole month.
	 *
	 * @return array{from:string,to:string,prev_from:string,prev_to:string,days:int}
	 */
	public static function range_bounds( string $range, string $today ): array {
		$range = self::range( $range );
		$t     = (int) strtotime( $today . ' 00:00:00 UTC' );
		if ( 'month' === $range ) {
			$from      = gmdate( 'Y-m-01', $t );
			$days      = (int) gmdate( 'j', $t );
			$prev_from = gmdate( 'Y-m-01', (int) strtotime( $from . ' 00:00:00 UTC' ) - 86400 );
			$prev_last = gmdate( 'Y-m-t', (int) strtotime( $prev_from . ' 00:00:00 UTC' ) );
			$prev_to   = gmdate( 'Y-m-d', min( (int) strtotime( $prev_last . ' 00:00:00 UTC' ), (int) strtotime( $prev_from . ' 00:00:00 UTC' ) + ( $days - 1 ) * 86400 ) );
			return array( 'from' => $from, 'to' => $today, 'prev_from' => $prev_from, 'prev_to' => $prev_to, 'days' => $days );
		}
		$days = '7d' === $range ? 7 : 30;
		return array(
			'from'      => gmdate( 'Y-m-d', $t - ( $days - 1 ) * 86400 ),
			'to'        => $today,
			'prev_from' => gmdate( 'Y-m-d', $t - ( 2 * $days - 1 ) * 86400 ),
			'prev_to'   => gmdate( 'Y-m-d', $t - $days * 86400 ),
			'days'      => $days,
		);
	}

	/** First and last day (UTC) of "YYYY-MM"; null when the string is not a month. */
	public static function month_bounds( string $month ): ?array {
		if ( ! preg_match( '/^(\d{4})-(0[1-9]|1[0-2])$/', $month, $m ) ) {
			return null;
		}
		$from = $m[1] . '-' . $m[2] . '-01';
		return array( 'from' => $from, 'to' => gmdate( 'Y-m-t', (int) strtotime( $from . ' 00:00:00 UTC' ) ) );
	}

	/* ================================================================
	 *  Flags
	 * ================================================================ */

	/** Median of the numbers; null when there are none. */
	public static function median( array $values ): ?float {
		$v = array_values( array_map( 'floatval', $values ) );
		if ( ! $v ) {
			return null;
		}
		sort( $v );
		$n = count( $v );
		return $n % 2 ? $v[ intdiv( $n, 2 ) ] : ( $v[ $n / 2 - 1 ] + $v[ $n / 2 ] ) / 2;
	}

	/**
	 * 💤: whole days of silence when the AI is ON and the number has been quiet at least SLEEP_DAYS; null otherwise.
	 * A number that never had a customer counts from the day it was born, so a brand-new zero-reply number can still be flagged (G-4).
	 * An AI-off number is never flagged, however long it has been idle (T108-10).
	 */
	public static function sleeping_days( bool $ai_enabled, ?string $last_customer_at, ?string $born_at, int $now ): ?int {
		if ( ! $ai_enabled ) {
			return null;
		}
		$ref = ( null !== $last_customer_at && '' !== $last_customer_at ) ? $last_customer_at : $born_at;
		if ( null === $ref || '' === $ref ) {
			return null;
		}
		$ts = (int) strtotime( $ref . ' UTC' );
		if ( $ts <= 0 ) {
			return null;
		}
		$days = (int) floor( ( $now - $ts ) / 86400 );
		return $days >= self::SLEEP_DAYS ? $days : null;
	}

	/** 🔥: cost per customer reply of the 7-day window above 2 × the site median. Needs a median and at least one reply. */
	public static function is_expensive( float $chi_usd_7d, int $replies_7d, ?float $site_median_per_reply ): bool {
		if ( $replies_7d <= 0 || null === $site_median_per_reply || $site_median_per_reply <= 0 ) {
			return false;
		}
		return ( $chi_usd_7d / $replies_7d ) > self::EXPENSIVE_X * $site_median_per_reply;
	}

	/**
	 * ⏳: the Hub-side plan is about to run out. Returns the words to show, or null.
	 * Trial with ≤ 2 days left · paid plan with ≤ 3 days left · token allowance ≥ 90 % used. Only when the plan gate is on.
	 *
	 * @param array{state?:string,plan_mode?:string,valid_until_ts?:int,allowance?:int,used?:int} $plan
	 */
	public static function plan_flag( array $plan, int $now ): ?string {
		if ( 'on' !== ( $plan['plan_mode'] ?? 'off' ) ) {
			return null;
		}
		$state = (string) ( $plan['state'] ?? 'none' );
		$until = (int) ( $plan['valid_until_ts'] ?? 0 );
		if ( in_array( $state, array( 'trial', 'active' ), true ) && $until > $now ) {
			$left = (int) ceil( ( $until - $now ) / 86400 );
			if ( 'trial' === $state && $left <= self::TRIAL_DAYS ) {
				return 'trial còn ' . $left . ' ngày';
			}
			if ( 'active' === $state && $left <= self::PLAN_DAYS ) {
				return 'gói còn ' . $left . ' ngày';
			}
		}
		$allow = (int) ( $plan['allowance'] ?? 0 );
		if ( 'expired' !== $state && $allow > 0 ) {
			$pct = (int) floor( 100 * (int) ( $plan['used'] ?? 0 ) / $allow );
			if ( $pct >= self::ALLOWANCE_PCT ) {
				return 'hạn mức ' . $pct . '%';
			}
		}
		return null;
	}

	/**
	 * @param array{sleeping_days:?int,expensive:bool,plan:?string} $in
	 * @return array<int,array{code:string,text:string}>
	 */
	public static function flags( array $in ): array {
		$out = array();
		if ( null !== ( $in['sleeping_days'] ?? null ) ) {
			$out[] = array( 'code' => 'sleeping', 'text' => 'ngủ ' . (int) $in['sleeping_days'] . ' ngày' );
		}
		if ( ! empty( $in['expensive'] ) ) {
			$out[] = array( 'code' => 'expensive', 'text' => 'tốn bất thường' );
		}
		if ( ! empty( $in['plan'] ) ) {
			$out[] = array( 'code' => 'plan', 'text' => (string) $in['plan'] );
		}
		return $out;
	}

	/* ================================================================
	 *  Ranking (the four tabs)
	 * ================================================================ */

	/**
	 * Cost per distinct customer of ONE window; null when nobody was served (never 0 — "rẻ nhất" must not go to an idle number).
	 */
	public static function cost_per_customer( float $chi_usd, int $customers ): ?float {
		return $customers > 0 ? $chi_usd / $customers : null;
	}

	/**
	 * Rank rows for one tab. Each row needs: account_id, replies, customers, chi_usd, thu_usd, has_revenue, trained.
	 * Returns the ordered account ids with `rank` (1-based) for rows that take part; rows left out of the tab are listed in `excluded`.
	 *  - hardworking: replies ↓, customers ↓
	 *  - efficient:   chi / customers ↑ (no customers ⇒ last), replies ↓
	 *  - earning:     lãi ↓, thu ↓ — numbers WITHOUT a revenue source are left out, never ranked at 0 (T108-12)
	 *  - trained:     trained ↓, replies ↓
	 *
	 * @param array<int,array<string,mixed>> $rows
	 * @return array{order:array<int,string>,excluded:array<int,string>}
	 */
	public static function rank( array $rows, string $sort ): array {
		$sort     = in_array( $sort, self::SORTS, true ) ? $sort : 'hardworking';
		$excluded = array();
		$items    = array();
		foreach ( $rows as $r ) {
			if ( 'earning' === $sort && empty( $r['has_revenue'] ) ) {
				$excluded[] = (string) $r['account_id'];
				continue;
			}
			$items[] = $r;
		}
		usort( $items, static function ( $a, $b ) use ( $sort ) {
			switch ( $sort ) {
				case 'efficient':
					$ca = self::cost_per_customer( (float) $a['chi_usd'], (int) $a['customers'] );
					$cb = self::cost_per_customer( (float) $b['chi_usd'], (int) $b['customers'] );
					if ( null === $ca && null === $cb ) {
						$d = 0;
					} elseif ( null === $ca ) {
						return 1;
					} elseif ( null === $cb ) {
						return -1;
					} else {
						$d = $ca <=> $cb;
					}
					return $d ?: ( (int) $b['replies'] <=> (int) $a['replies'] ) ?: strcmp( (string) $a['account_id'], (string) $b['account_id'] );
				case 'earning':
					$la = (float) $a['thu_usd'] - (float) $a['chi_usd'];
					$lb = (float) $b['thu_usd'] - (float) $b['chi_usd'];
					return ( $lb <=> $la ) ?: ( (float) $b['thu_usd'] <=> (float) $a['thu_usd'] ) ?: strcmp( (string) $a['account_id'], (string) $b['account_id'] );
				case 'trained':
					return ( (int) $b['trained'] <=> (int) $a['trained'] ) ?: ( (int) $b['replies'] <=> (int) $a['replies'] ) ?: strcmp( (string) $a['account_id'], (string) $b['account_id'] );
				default:
					return ( (int) $b['replies'] <=> (int) $a['replies'] ) ?: ( (int) $b['customers'] <=> (int) $a['customers'] ) ?: strcmp( (string) $a['account_id'], (string) $b['account_id'] );
			}
		} );
		return array( 'order' => array_map( static fn( $r ) => (string) $r['account_id'], $items ), 'excluded' => $excluded );
	}

	/** ▲ positive = climbed. null when the number was not ranked in the previous window (no comparison to show). */
	public static function rank_delta( ?int $rank, ?int $rank_prev ): ?int {
		return ( null === $rank || null === $rank_prev ) ? null : $rank_prev - $rank;
	}

	/* ================================================================
	 *  Milestones: next 3 + badge cabinet
	 * ================================================================ */

	/** Value of a catalog metric ("born" = 1 once born). */
	private static function metric_value( array $metrics, string $metric ): int {
		if ( 'born' === $metric ) {
			return empty( $metrics['born_at'] ) ? 0 : 1;
		}
		return (int) ( $metrics[ $metric ] ?? 0 );
	}

	/**
	 * The unreached tiers closest to being reached, one per group (the lowest unreached tier), most progress first.
	 *
	 * @param array<string,mixed>            $metrics a bizcity_brain_metrics row
	 * @param array<int,array<string,mixed>> $groups  catalog groups (active ones; a disabled group has no progress to show)
	 * @return array<int,array{group:string,icon:string,label:string,tier:string,value:int,target:int,pct:int}>
	 */
	public static function next_milestones( array $metrics, array $groups, int $limit = 3 ): array {
		$out = array();
		foreach ( $groups as $g ) {
			if ( isset( $g['enabled'] ) && ! $g['enabled'] ) {
				continue;
			}
			$metric = (string) ( $g['metric'] ?? '' );
			if ( 'born' === $metric ) {
				continue; // "ra đời" is never a progress bar — it is a moment.
			}
			$value = self::metric_value( $metrics, $metric );
			foreach ( (array) ( $g['tiers'] ?? array() ) as $t ) {
				$target = (int) ( $t['threshold'] ?? 0 );
				if ( $target > $value ) {
					$out[] = array(
						'group' => (string) $g['code'], 'icon' => (string) ( $g['icon'] ?? '' ), 'label' => (string) ( $g['label'] ?? $g['code'] ),
						'tier' => (string) $t['tier'], 'value' => $value, 'target' => $target, 'pct' => (int) min( 99, floor( 100 * $value / max( 1, $target ) ) ),
					);
					break;
				}
			}
		}
		usort( $out, static fn( $a, $b ) => ( $b['pct'] <=> $a['pct'] ) ?: strcmp( $a['group'], $b['group'] ) );
		return array_slice( $out, 0, max( 1, $limit ) );
	}

	/**
	 * The cabinet: one cell per catalog group (disabled groups stay as "chờ duyệt"), the highest tier reached, the value now and the next threshold.
	 *
	 * @return array<int,array{group:string,icon:string,label:string,tier:?string,value:int,next_threshold:?int,pending:bool}>
	 */
	public static function badges( array $metrics, array $groups ): array {
		$out = array();
		foreach ( $groups as $g ) {
			$pending = isset( $g['enabled'] ) && ! $g['enabled'];
			$metric  = (string) ( $g['metric'] ?? '' );
			$value   = $pending ? 0 : self::metric_value( $metrics, $metric );
			$tier    = null;
			$next    = null;
			foreach ( (array) ( $g['tiers'] ?? array() ) as $t ) {
				$thr = (int) ( $t['threshold'] ?? PHP_INT_MAX );
				if ( ! $pending && $value >= $thr ) {
					$tier = (string) $t['tier'];
				} elseif ( null === $next ) {
					$next = $thr;
				}
			}
			// the two "born" tiers share threshold 1 (QR, first reply): the first reply is a separate event, shown by the caller
			$out[] = array(
				'group' => (string) $g['code'], 'icon' => (string) ( $g['icon'] ?? '' ), 'label' => (string) ( $g['label'] ?? $g['code'] ),
				'tier' => $tier, 'value' => $value, 'next_threshold' => 'born' === $metric ? null : $next, 'pending' => $pending,
			);
		}
		return $out;
	}

	/** Highest tier of a list of codes like "replies.gold" — for the "Mốc gần nhất" chip it is the LATEST row that counts, this is only a helper for tests/UI ordering. */
	public static function tier_rank( string $tier ): int {
		$i = array_search( $tier, self::TIERS, true );
		return false === $i ? -1 : (int) $i;
	}

	/** USD → ₫ with the Hub rate; null when the rate is unknown (the screen then shows USD, G3). */
	public static function vnd( ?float $usd, ?float $rate ): ?int {
		return ( null === $usd || null === $rate || $rate <= 0 ) ? null : (int) round( $usd * $rate );
	}
}
