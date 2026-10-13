<?php
/**
 * Hub tool unit prices for the site's ledger copy (PHASE-0.107 S107-L12, D107-19, fixture zalo-hub/contracts/fixtures/s107/unit-prices.read.json).
 *
 * The cell counts tool units (source of truth) and sends tool rows with billed_usd = null; the site prices them once, at receive
 * time, with the Hub table: billed_usd = units × usd_per_unit of the most specific row (exact provider before '*', latest
 * effective_from ≤ row time), price_source = 'hub_unit_price', price_version = the table version. No matching row ⇒
 * billed_usd null, price_source 'hub_other'. Prices are a snapshot: a later Hub change never re-prices stored rows.
 *
 * Read: GET {gateway}/wp-json/bizcity/v1/zalo-hub/unit-prices with the site's OWN key (main-site fallback OFF), cached 1 h
 * (a failed read is cached 5 min so an ingest burst does not hammer the Hub).
 *
 * // [2026-10-13 01:08 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.107 S107-L12 — tool rows priced with the Hub table.
 *
 * @package BizCity_Twin_AI
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_Usage_Prices {

	const TRANSIENT   = 'bizcity_usage_unit_prices';
	const TTL_OK      = 3600;
	const TTL_FAIL    = 300;
	const SOURCE_HUB  = 'hub_unit_price';
	const SOURCE_NONE = 'hub_other';

	/** Test seam: fetch(): array (the decoded Hub body, or [] on failure). */
	public static $readers = array();

	/** @var array|null memo for this request */
	private static $memo = null;

	public static function reset(): void {
		self::$memo = null;
	}

	/** @return array{version:string,rows:array<int,array<string,mixed>>,usd_vnd_rate:float} */
	public static function table(): array {
		if ( null !== self::$memo ) {
			return self::$memo;
		}
		$cached = function_exists( 'get_transient' ) && ! isset( self::$readers['fetch'] ) ? get_transient( self::TRANSIENT ) : false;
		if ( is_array( $cached ) && isset( $cached['rows'] ) ) {
			return self::$memo = $cached;
		}
		$body  = self::fetch();
		$table = self::normalize( $body );
		if ( function_exists( 'set_transient' ) && ! isset( self::$readers['fetch'] ) ) {
			set_transient( self::TRANSIENT, $table, empty( $table['rows'] ) ? self::TTL_FAIL : self::TTL_OK );
		}
		return self::$memo = $table;
	}

	/** @param mixed $body @return array{version:string,rows:array<int,array<string,mixed>>,usd_vnd_rate:float} */
	public static function normalize( $body ): array {
		$rows = array();
		if ( is_array( $body ) && isset( $body['rows'] ) && is_array( $body['rows'] ) ) {
			foreach ( $body['rows'] as $r ) {
				if ( ! is_array( $r ) || ! isset( $r['tool'], $r['usd_per_unit'] ) || ! is_numeric( $r['usd_per_unit'] ) ) {
					continue;
				}
				$from   = isset( $r['effective_from'] ) ? strtotime( (string) $r['effective_from'] ) : 0;
				$rows[] = array(
					'service'      => (string) ( $r['service'] ?? '' ),
					'tool'         => (string) $r['tool'],
					'provider'     => (string) ( $r['provider'] ?? '*' ),
					'usd_per_unit' => (float) $r['usd_per_unit'],
					'from_ts'      => false === $from ? 0 : (int) $from,
				);
			}
		}
		return array(
			'version'      => is_array( $body ) ? substr( (string) ( $body['version'] ?? '' ), 0, 40 ) : '',
			'rows'         => $rows,
			// The Hub may publish its display rate (S107-H12) with the table; read when present, never required.
			'usd_vnd_rate' => is_array( $body ) && isset( $body['usd_vnd_rate'] ) && is_numeric( $body['usd_vnd_rate'] ) ? (float) $body['usd_vnd_rate'] : 0.0,
		);
	}

	/**
	 * Price one tool row. `$provider` is the vendor part of the row's model (`google/…` ⇒ `google`), '' when unknown.
	 *
	 * @return array{billed_usd:?string,price_source:string,price_version:string}
	 */
	public static function price( array $table, string $tool, string $provider, float $units, int $at_ts ): array {
		$best = null;
		foreach ( (array) ( $table['rows'] ?? array() ) as $r ) {
			if ( $r['tool'] !== $tool || ( '' !== $r['service'] && 'zalo_hub' !== $r['service'] ) || $r['from_ts'] > $at_ts ) {
				continue;
			}
			$exact = '' !== $provider && $r['provider'] === $provider;
			if ( ! $exact && '*' !== $r['provider'] ) {
				continue;
			}
			$rank = array( $exact ? 1 : 0, $r['from_ts'] );
			if ( null === $best || $rank > $best['rank'] ) {
				$best = array( 'rank' => $rank, 'row' => $r );
			}
		}
		if ( null === $best ) {
			return array( 'billed_usd' => null, 'price_source' => self::SOURCE_NONE, 'price_version' => (string) ( $table['version'] ?? '' ) );
		}
		return array(
			'billed_usd'    => number_format( round( $units * (float) $best['row']['usd_per_unit'], 8 ), 8, '.', '' ),
			'price_source'  => self::SOURCE_HUB,
			'price_version' => (string) ( $table['version'] ?? '' ),
		);
	}

	private static function fetch(): array {
		if ( isset( self::$readers['fetch'] ) ) {
			return (array) call_user_func( self::$readers['fetch'] );
		}
		if ( ! class_exists( 'BizCity_LLM_Client' ) ) {
			return array();
		}
		// main-site key fallback OFF: a sub-site must never price with (or reveal) the main site's account.
		$r = BizCity_LLM_Client::instance()->gateway_get( '/zalo-hub/unit-prices', array(), 'GET', 8, false );
		return is_array( $r ) && empty( $r['_degraded'] ) ? $r : array();
	}
}
