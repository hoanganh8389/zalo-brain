<?php
/**
 * The counting rule `cap20_customer_only@1` as two PURE functions (PHASE-0.108 S108-B3 / T108-33, 20-REVENUE-PER-NUMBER.md §6.5).
 *
 * The same rule counts customer turns in two places: the CELL (zalo-hub brain-standing.ts, which feeds the public domain titles) and this SITE
 * (BizCity_Brain_Journey_Evaluator::compute_metrics, which feeds the per-number milestones). Both must give the same number for the same turns, so both run the SAME cases from
 * `zalo-hub/contracts/fixtures/s108/standing-rules.cases.json`. This file is the site's reference implementation of that rule; the evaluator's SQL path is meant to call it
 * (S108-L14 note) rather than re-implement the cap — until then the fixture test below keeps the two honest.
 *
 *   • only customer LLM turns reach here (owner, staff and tool rows are filtered upstream — they can never be a case);
 *   • every (customer, day) group counts at most `cap` (20) turns, then the groups are summed;
 *   • "customers" = distinct non-empty contact keys with at least one counted turn.
 *
 * // [2026-10-13 Johnny Chu - Chu Hoàng Anh] PHASE-0.108 S108-B3 / T108-33.
 *
 * @package BizCity_Twin_AI
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_Brain_Standing_Rules {

	const RULE = 'cap20_customer_only@1';
	const CAP  = 20;

	/**
	 * @param array<int,array{contact:string,day:string,turns:int}> $groups customer LLM turns grouped by (contact, day)
	 * @return array{turns:int,customers:int}
	 */
	public static function capped_turns( array $groups, int $cap = self::CAP ): array {
		$turns = 0;
		$who   = array();
		foreach ( $groups as $g ) {
			$contact = (string) ( $g['contact'] ?? '' );
			$n       = (int) ( $g['turns'] ?? 0 );
			if ( '' === $contact || $n <= 0 ) {
				continue;
			}
			$turns         += min( $cap, $n );
			$who[ $contact ] = true;
		}
		return array( 'turns' => $turns, 'customers' => count( $who ) );
	}

	/**
	 * Consecutive days with a customer, counted back from `$today` (a still-empty today does not break the streak; an empty yesterday too means 0).
	 *
	 * @param array<int,string> $days "Y-m-d"
	 */
	public static function streak_days( array $days, string $today ): int {
		$set = array_flip( $days );
		$d   = isset( $set[ $today ] ) ? $today : self::add_days( $today, -1 );
		$n   = 0;
		while ( isset( $set[ $d ] ) ) {
			++$n;
			$d = self::add_days( $d, -1 );
		}
		return $n;
	}

	public static function add_days( string $day, int $n ): string {
		return gmdate( 'Y-m-d', (int) strtotime( $day . ' 00:00:00 UTC' ) + $n * 86400 );
	}
}
