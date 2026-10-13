<?php
/**
 * Price floor of a plan — set on the CELL, relayed by the Hub, read by the site (PHASE-0.108 S108-L21, 20-REVENUE-PER-NUMBER.md §5, 24 §3.1).
 *
 *   floor(package) = months × ( fee_per_number_month + allowance_in_million_tokens × vnd_per_mtok_billed ),   rounded UP to 1.000 ₫
 *   allowance 0 (unlimited) ⇒ `unlimited_reference_mtok` stands in for the allowance.
 *
 * Contract `plan-floor@1`: GET <hub>/wp-json/bizcity/v1/zalo-hub/plan-floor with the site's OWN key (main-site fallback OFF). Reading never blocks selling
 * (D108-R2): cached 1 h; the last good copy is kept without expiry (`state = stale` when the Hub fails); never read ⇒ `state = none` and a package is allowed
 * (with a warning). The check at the site is a SOFT guard (20 §5.4): the cell/Hub cannot see the sale price.
 *
 * Test seams ($readers): fetch(refresh): array · last(): ?array · save_last(array) · cache_get(): ?array · cache_set(array)
 *
 * // [2026-10-13 09:07 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.108 S108-L21.
 *
 * @package BizCity_Twin_AI
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_Usage_Plan_Floor {

	const OPT_LAST   = 'bizcity_plan_floor_last';
	const TRANSIENT  = 'bizcity_plan_floor_cache';
	const TTL        = 3600;
	const MAX_VND    = 100000000;
	const ROUND_VND  = 1000;
	const PATH       = '/zalo-hub/plan-floor';

	/** @var array<string,callable> */
	public static $readers = array();

	/** @var array|null one Hub read per request */
	private static $memo = null;

	/* ── pure ─────────────────────────────────────────────────────── */

	/**
	 * @param array{fee_per_number_month:int|float,vnd_per_mtok_billed:int|float,unlimited_reference_mtok?:int|float} $floor
	 */
	public static function floor_for( int $months, int $allowance, array $floor ): int {
		$fee  = (float) ( $floor['fee_per_number_month'] ?? 0 );
		$rate = (float) ( $floor['vnd_per_mtok_billed'] ?? 0 );
		$mtok = $allowance > 0 ? $allowance / 1000000 : (float) ( $floor['unlimited_reference_mtok'] ?? 0 );
		if ( $months < 1 ) {
			return 0;
		}
		$raw = $months * ( $fee + $mtok * $rate );
		return (int) ( ceil( round( $raw, 2 ) / self::ROUND_VND ) * self::ROUND_VND );
	}

	/**
	 * @param array{months:int,allowance:int,price_vnd:int} $package
	 * @param array{state:string,floor:?array}               $state  as returned by read()
	 * @return array{ok:bool,warn:bool,floor_vnd:?int,gap_vnd:int,margin_vnd:?int}
	 */
	public static function check( array $package, array $state ): array {
		$floor = $state['floor'] ?? null;
		if ( 'none' === ( $state['state'] ?? 'none' ) || ! is_array( $floor ) ) {
			return array( 'ok' => true, 'warn' => true, 'floor_vnd' => null, 'gap_vnd' => 0, 'margin_vnd' => null );
		}
		$need  = self::floor_for( (int) $package['months'], (int) $package['allowance'], $floor );
		$price = (int) $package['price_vnd'];
		$ok    = $price >= $need;
		return array(
			'ok'         => $ok,
			'warn'       => 'stale' === $state['state'],
			'floor_vnd'  => $need,
			'gap_vnd'    => $ok ? 0 : $need - $price,
			'margin_vnd' => $ok ? $price - $need : null,
		);
	}

	/** A floor body from the Hub is usable only when every number is a sane positive amount (24 §6: a hostile/garbled Hub must not change prices). */
	public static function valid( $f ): bool {
		if ( ! is_array( $f ) ) {
			return false;
		}
		foreach ( array( 'fee_per_number_month', 'vnd_per_mtok_billed' ) as $k ) {
			if ( ! isset( $f[ $k ] ) || ! is_numeric( $f[ $k ] ) || $f[ $k ] < 0 || $f[ $k ] > self::MAX_VND ) {
				return false;
			}
		}
		if ( (float) $f['fee_per_number_month'] + (float) $f['vnd_per_mtok_billed'] <= 0 ) {
			return false;
		}
		if ( isset( $f['unlimited_reference_mtok'] ) && ( ! is_numeric( $f['unlimited_reference_mtok'] ) || $f['unlimited_reference_mtok'] < 0 || $f['unlimited_reference_mtok'] > 100000 ) ) {
			return false;
		}
		return true;
	}

	/* ── read (Hub) ───────────────────────────────────────────────── */

	/**
	 * @return array{state:string,floor:?array,version:string,fetched_at:?int}  state: ok | stale | none
	 */
	public static function read( bool $refresh = false ): array {
		if ( ! $refresh && null !== self::$memo ) {
			return self::$memo;
		}
		$out = null;
		if ( ! $refresh ) {
			$c = self::cache_get();
			if ( is_array( $c ) && ! empty( $c['floor'] ) ) {
				$out = array( 'state' => 'ok', 'floor' => $c['floor'], 'version' => (string) ( $c['version'] ?? '' ), 'fetched_at' => (int) ( $c['fetched_at'] ?? 0 ) );
			}
		}
		if ( null === $out ) {
			$r = self::fetch( $refresh );
			$f = is_array( $r ) && isset( $r['floor'] ) ? $r['floor'] : null;
			if ( self::valid( $f ) ) {
				$rec = array( 'floor' => self::normalise( $f ), 'version' => (string) ( $r['version'] ?? ( $f['version'] ?? '' ) ), 'fetched_at' => self::now() );
				self::cache_set( $rec );
				self::save_last( $rec );
				$out = array( 'state' => 'ok', 'floor' => $rec['floor'], 'version' => $rec['version'], 'fetched_at' => $rec['fetched_at'] );
			} else {
				$last = self::last();
				$out  = is_array( $last ) && ! empty( $last['floor'] )
					? array( 'state' => 'stale', 'floor' => $last['floor'], 'version' => (string) ( $last['version'] ?? '' ), 'fetched_at' => (int) ( $last['fetched_at'] ?? 0 ) )
					: array( 'state' => 'none', 'floor' => null, 'version' => '', 'fetched_at' => null );
			}
		}
		self::$memo = $out;
		return $out;
	}

	public static function reset_memo(): void {
		self::$memo = null;
	}

	/** @return array{fee_per_number_month:int,vnd_per_mtok_billed:int,unlimited_reference_mtok:float,effective_from:string} */
	private static function normalise( array $f ): array {
		return array(
			'fee_per_number_month'     => (int) round( (float) $f['fee_per_number_month'] ),
			'vnd_per_mtok_billed'      => (int) round( (float) $f['vnd_per_mtok_billed'] ),
			'unlimited_reference_mtok' => (float) ( $f['unlimited_reference_mtok'] ?? 0 ),
			'effective_from'           => substr( (string) ( $f['effective_from'] ?? '' ), 0, 32 ),
		);
	}

	/* ── seams ────────────────────────────────────────────────────── */

	private static function fetch( bool $refresh ) {
		if ( isset( self::$readers['fetch'] ) ) {
			return call_user_func( self::$readers['fetch'], $refresh );
		}
		if ( ! class_exists( 'BizCity_LLM_Client' ) ) {
			return null;
		}
		$r = BizCity_LLM_Client::instance()->gateway_get( self::PATH, array(), 'GET', 8, false ); // the site's OWN key only
		return is_array( $r ) && empty( $r['_degraded'] ) ? $r : null;
	}

	private static function last() {
		if ( isset( self::$readers['last'] ) ) {
			return call_user_func( self::$readers['last'] );
		}
		return function_exists( 'get_option' ) ? get_option( self::OPT_LAST, null ) : null;
	}

	private static function save_last( array $rec ): void {
		if ( isset( self::$readers['save_last'] ) ) {
			call_user_func( self::$readers['save_last'], $rec );
			return;
		}
		if ( function_exists( 'update_option' ) ) {
			update_option( self::OPT_LAST, $rec, false );
		}
	}

	private static function cache_get() {
		if ( isset( self::$readers['cache_get'] ) ) {
			return call_user_func( self::$readers['cache_get'] );
		}
		return function_exists( 'get_transient' ) ? get_transient( self::TRANSIENT ) : false;
	}

	private static function cache_set( array $rec ): void {
		if ( isset( self::$readers['cache_set'] ) ) {
			call_user_func( self::$readers['cache_set'], $rec );
			return;
		}
		if ( function_exists( 'set_transient' ) ) {
			set_transient( self::TRANSIENT, $rec, self::TTL );
		}
	}

	private static function now(): int {
		return isset( self::$readers['now'] ) ? (int) call_user_func( self::$readers['now'] ) : time();
	}
}
