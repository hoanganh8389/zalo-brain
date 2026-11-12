<?php
/**
 * Maturity 0–100 of a number, and of its owner (PHASE-0.112 S112-L5, doc 20 §9; owner approved D112-4 five axes, D112-5 owner ladder reads this, D112-11 efficiency is a MULTIPLIER).
 *
 * Five axes of 0–20, each on a log scale so a small number still shows progress ("điểm tăng theo bậc log"):
 *   presence    channels that served customers in 30 days (cap 3) + groups the number is in (cap 10) + pages connected
 *   serving     customer-days in 30 days (cap 300) — the sum of the cell's daily customer counts, so one person returning on 3 days counts 3
 *   work        completion points of `task` + `automation` jobs in 30 days (cap 500) = total points − reply points (reply points = the capped replies, 1 each)
 *   regularity  days with a delivered reply in 30 (cap 30) blended with the current streak (cap 30)
 *   learning    knowledge items taught (cap 200)
 * base = Σ axes (0–100); maturity = min(100, round(base × (0.8 + 0.02 × efficiency))), efficiency null ⇒ factor 1.
 * Owner = average of the ACTIVE numbers (points in 30 days) + a small bonus for running several (+2 each beyond the first, max +10), capped at 100.
 * Everything is a count or a score from the cell: no money, no tokens (R-ACTIVITY-2). The caps are DATA of this class (change = new version of the doc 20 §9 table).
 *
 * // [2026-10-16 12:30 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.112 S112-L5.
 *
 * @package BizCity_Twin_AI
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_Brain_Maturity {

	const CAP_CHANNELS  = 3;
	const CAP_GROUPS    = 10;
	const CAP_CUSTOMERS = 300;
	const CAP_WORK      = 500;
	const CAP_DAYS      = 30;
	const CAP_STREAK    = 30;
	const CAP_LEARNING  = 200;

	/** Owner ladder: Master needs the average maturity of the active numbers to reach this (replaces the old money condition `payback_3mo_brains`, D112-5). */
	const MASTER_MATURITY = 60;

	/** @var array<string,callable> Test seams: rows(accounts, today): array of activity rows · trained(accounts): array<string,int> */
	public static $readers = array();

	/** 0 → 0, cap → 1, log curve in between. */
	public static function scale( float $x, float $cap ): float {
		if ( $x <= 0 || $cap <= 0 ) {
			return 0.0;
		}
		return min( 1.0, log( 1 + $x ) / log( 1 + $cap ) );
	}

	/**
	 * @param array{channels:int,groups:int,connections:int,customers_30d:int,work_points_30d:int,active_days_30d:int,streak_days:int,trained_items:int,efficiency:?float} $in
	 * @return array{axes:array<string,float>,base:float,factor:float,maturity:int}
	 */
	public static function score( array $in ): array {
		$presence = 10 * self::scale( (float) $in['channels'], self::CAP_CHANNELS ) + 10 * self::scale( (float) ( (int) $in['groups'] + (int) ( $in['connections'] ?? 0 ) ), self::CAP_GROUPS );
		$axes     = array(
			'presence'   => round( min( 20.0, $presence ), 1 ),
			'serving'    => round( 20 * self::scale( (float) $in['customers_30d'], self::CAP_CUSTOMERS ), 1 ),
			'work'       => round( 20 * self::scale( (float) $in['work_points_30d'], self::CAP_WORK ), 1 ),
			'regularity' => round( 14 * self::scale( (float) $in['active_days_30d'], self::CAP_DAYS ) + 6 * self::scale( (float) $in['streak_days'], self::CAP_STREAK ), 1 ),
			'learning'   => round( 20 * self::scale( (float) $in['trained_items'], self::CAP_LEARNING ), 1 ),
		);
		$base   = round( array_sum( $axes ), 1 );
		$eff    = isset( $in['efficiency'] ) && is_numeric( $in['efficiency'] ) ? max( 0.0, min( 20.0, (float) $in['efficiency'] ) ) : null;
		$factor = null === $eff ? 1.0 : round( 0.8 + 0.02 * $eff, 3 );
		return array( 'axes' => $axes, 'base' => $base, 'factor' => $factor, 'maturity' => (int) min( 100, round( $base * $factor ) ) );
	}

	/**
	 * Owner maturity from the per-number maturities (0–100 each). Numbers with no points in 30 days are ignored; none active ⇒ 0.
	 *
	 * @param int[] $active_maturities
	 */
	public static function owner_score( array $active_maturities ): int {
		$n = count( $active_maturities );
		if ( 0 === $n ) {
			return 0;
		}
		$avg   = array_sum( $active_maturities ) / $n;
		$bonus = min( 10, 2 * ( $n - 1 ) );
		return (int) min( 100, round( $avg + $bonus ) );
	}

	/**
	 * Per-number figures for the score from the cell's rows of the last 30 days (activity-day@1.1) and the taught-items count.
	 *
	 * @param array<int,array<string,mixed>> $rows newest first: account_id, day, points, replies, customers, groups_joined, connections_active, streak_days, by_channel(array), efficiency_score
	 * @param array<string,int> $trained account_id ⇒ items
	 * @return array<string,array<string,mixed>> account_id ⇒ score inputs + `active` (any points in the window)
	 */
	public static function inputs_from_rows( array $rows, array $trained, string $today ): array {
		$from = gmdate( 'Y-m-d', strtotime( $today . ' -29 days' ) );
		$by   = array();
		foreach ( $rows as $r ) {
			$day = (string) $r['day'];
			if ( $day < $from || $day > $today ) {
				continue;
			}
			$id = (string) $r['account_id'];
			$o  = $by[ $id ] ?? array( 'channels' => array(), 'groups' => 0, 'connections' => 0, 'customers_30d' => 0, 'work_points_30d' => 0, 'active_days_30d' => 0, 'streak_days' => 0, 'efficiency' => null, 'points_30d' => 0, 'seen' => false );
			$replies = (int) ( $r['replies'] ?? 0 );
			$o['customers_30d']   += (int) ( $r['customers'] ?? 0 );
			$o['work_points_30d'] += max( 0, (int) ( $r['points'] ?? 0 ) - $replies );
			$o['points_30d']      += (int) ( $r['points'] ?? 0 );
			if ( $replies > 0 ) {
				$o['active_days_30d']++;
			}
			foreach ( (array) ( $r['by_channel'] ?? array() ) as $c ) {
				if ( (int) ( $c['replies'] ?? 0 ) > 0 ) {
					$o['channels'][ ( $c['platform'] ?? '' ) . '|' . ( $c['channel_ref'] ?? '' ) ] = true;
				}
			}
			if ( ! $o['seen'] ) { // rows come newest first: the gauges / streak / efficiency of the latest row
				$o['groups']      = (int) ( $r['groups_joined'] ?? 0 );
				$o['connections'] = (int) ( $r['connections_active'] ?? 0 );
				$o['streak_days'] = (int) ( $r['streak_days'] ?? 0 );
				$o['seen']        = true;
			}
			if ( null === $o['efficiency'] && isset( $r['efficiency_score'] ) && is_numeric( $r['efficiency_score'] ) ) {
				$o['efficiency'] = (float) $r['efficiency_score'];
			}
			$by[ $id ] = $o;
		}
		$out = array();
		foreach ( $by as $id => $o ) {
			$out[ $id ] = array(
				'channels' => count( $o['channels'] ), 'groups' => $o['groups'], 'connections' => $o['connections'], 'customers_30d' => $o['customers_30d'],
				'work_points_30d' => $o['work_points_30d'], 'active_days_30d' => $o['active_days_30d'], 'streak_days' => $o['streak_days'],
				'trained_items' => (int) ( $trained[ $id ] ?? 0 ), 'efficiency' => $o['efficiency'], 'active' => $o['points_30d'] > 0,
			);
		}
		return $out;
	}

	/**
	 * Maturity of each given number (those the cell has not reported are absent) + the owner figure over the active ones.
	 *
	 * @param string[] $accounts
	 * @param array<string,int> $trained account_id ⇒ taught items (the site's own count; the cell does not know the knowledge base)
	 * @return array{accounts:array<string,array{maturity:int,axes:array<string,float>,factor:float,active:bool}>,owner:int}
	 */
	public static function for_accounts( array $accounts, array $trained, string $today ): array {
		$rows = isset( self::$readers['rows'] )
			? (array) call_user_func( self::$readers['rows'], $accounts, $today )
			: ( class_exists( 'BizCity_Usage_Activity_Day' ) ? BizCity_Usage_Activity_Day::rows_for_maturity( $accounts, $today ) : array() );
		$per    = array();
		$active = array();
		foreach ( self::inputs_from_rows( $rows, $trained, $today ) as $id => $in ) {
			$s          = self::score( $in );
			$per[ $id ] = array( 'maturity' => $s['maturity'], 'axes' => $s['axes'], 'factor' => $s['factor'], 'active' => (bool) $in['active'] );
			if ( $in['active'] ) {
				$active[] = $s['maturity'];
			}
		}
		return array( 'accounts' => $per, 'owner' => self::owner_score( $active ) );
	}
}
