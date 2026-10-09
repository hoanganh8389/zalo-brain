<?php
/**
 * Contact signals read projection — PHASE-0.95 S95-C9 (CRM chips + "Tín hiệu gần nhất").
 *
 * `action.crm_signal` (S95-C2, plugin bizcity-automation) WRITES the facts:
 *   · `contacts.tags_json` entries `nhiet:<nong|am|lanh>`, `cam_xuc:<tich_cuc|trung_tinh|tieu_cuc|gian>`, `y_dinh:<value>`;
 *   · `contacts.additional_attributes.signals[]` (≤ 20, newest LAST) of `{kind, value, score, at, by, evidence}`,
 *     evidence already masked (phone/email) and ≤ 200 chars at write time.
 * This class only READS them into the bounded shape both Inbox surfaces render (R-INBOX-PIPE-4):
 *   `signal_tags: string[]` (signal prefixes only — never `role:*` or any other tag namespace),
 *   `heat_score: int|null` (score of the newest `nhiet` signal), `signals: [...]` (newest FIRST, ≤ 5),
 * plus the closed list-filter vocabulary (`?signal=`). PURE — no WordPress, no database — so it is unit-testable.
 *
 * @package BizCity_Twin_CRM
 * @since PHASE-0.95 (2026-10-09)
 */

// [2026-10-09 03:47 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.95-S95-C9 — read-only projection of crm_signal tags + signals[] for the Inbox list and contact card.
defined( 'ABSPATH' ) || exit;

final class BizCity_CRM_Contact_Signals {

	/** Tag namespaces written by action.crm_signal. */
	const PREFIXES = array( 'nhiet:', 'cam_xuc:', 'y_dinh:' );

	/** Closed `?signal=` vocabulary → the exact tags any one of which must be on the contact. */
	const FILTERS = array(
		'nong'           => array( 'nhiet:nong' ),
		'am'             => array( 'nhiet:am' ),
		'lanh'           => array( 'nhiet:lanh' ),
		'khong_hai_long' => array( 'cam_xuc:gian', 'cam_xuc:tieu_cuc' ),
	);

	const MAX_TAGS     = 8;
	const MAX_SIGNALS  = 5;
	const MAX_EVIDENCE = 200;

	/** A validated filter key, or '' (= no filter) — a stale/unknown chip must never blank the list. */
	public static function filter_key( $raw ): string {
		$key = strtolower( trim( (string) $raw ) );
		return isset( self::FILTERS[ $key ] ) ? $key : '';
	}

	/** The JSON-quoted tag needles (`"nhiet:nong"`) a `tags_json LIKE` must match for one filter key ([] = no filter). */
	public static function filter_needles( string $key ): array {
		if ( ! isset( self::FILTERS[ $key ] ) ) { return array(); }
		return array_map( static function ( $tag ) { return '"' . $tag . '"'; }, self::FILTERS[ $key ] );
	}

	/** Signal tags only (prefix allow-list), de-duplicated, ≤ MAX_TAGS. Accepts the raw `tags_json` string or a decoded array. */
	public static function tags( $tags_json ): array {
		$tags = is_array( $tags_json ) ? $tags_json : json_decode( (string) $tags_json, true );
		if ( ! is_array( $tags ) ) { return array(); }
		$out = array();
		foreach ( $tags as $tag ) {
			if ( ! is_string( $tag ) ) { continue; }
			$tag = trim( $tag );
			foreach ( self::PREFIXES as $prefix ) {
				if ( 0 === strpos( $tag, $prefix ) && strlen( $tag ) > strlen( $prefix ) && strlen( $tag ) <= 80 ) {
					$out[ $tag ] = true;
					break;
				}
			}
			if ( count( $out ) >= self::MAX_TAGS ) { break; }
		}
		return array_keys( $out );
	}

	/** Newest-first, ≤ $limit signals in a fixed shape. Accepts raw `additional_attributes` JSON or a decoded array. */
	public static function recent( $attributes, int $limit = self::MAX_SIGNALS ): array {
		$attrs = is_array( $attributes ) ? $attributes : json_decode( (string) $attributes, true );
		$list  = is_array( $attrs ) && isset( $attrs['signals'] ) && is_array( $attrs['signals'] ) ? $attrs['signals'] : array();
		$out   = array();
		foreach ( $list as $s ) {
			if ( ! is_array( $s ) ) { continue; }
			$kind  = self::str( $s['kind'] ?? '', 32 );
			$value = self::str( $s['value'] ?? '', 120 );
			if ( '' === $kind || '' === $value ) { continue; }
			$score = $s['score'] ?? null;
			$out[] = array(
				'kind'     => $kind,
				'value'    => $value,
				'score'    => is_numeric( $score ) ? max( 0, min( 100, (int) $score ) ) : null,
				'at'       => self::str( $s['at'] ?? '', 40 ),
				'by'       => self::str( $s['by'] ?? '', 32 ),
				'evidence' => self::str( $s['evidence'] ?? '', self::MAX_EVIDENCE ),
			);
		}
		// Stored newest LAST: sort by `at` desc, ties by write position desc (explicit — usort is not stable on PHP 7.4).
		$pos = array_keys( $out );
		array_multisort( array_column( $out, 'at' ), SORT_DESC, SORT_STRING, $pos, SORT_DESC, SORT_NUMERIC, $out );
		return array_slice( $out, 0, max( 0, $limit ) );
	}

	/** Score of the newest `nhiet` signal, or null when none carries a score. */
	public static function heat_score( $attributes ): ?int {
		foreach ( self::recent( $attributes, 20 ) as $s ) {
			if ( 'nhiet' === $s['kind'] ) { return $s['score']; }
		}
		return null;
	}

	/**
	 * The fields a conversation row adds for C9. `$with_signals` = also include the ≤ 5 newest signals (contact card).
	 *
	 * @return array{signal_tags:string[],heat_score:int|null,signals?:array}
	 */
	public static function project( $tags_json, $attributes, bool $with_signals = false ): array {
		$out = array(
			'signal_tags' => self::tags( $tags_json ),
			'heat_score'  => self::heat_score( $attributes ),
		);
		if ( $with_signals ) { $out['signals'] = self::recent( $attributes ); }
		return $out;
	}

	private static function str( $value, int $max ): string {
		if ( ! is_scalar( $value ) ) { return ''; }
		$s = trim( preg_replace( '/[\x00-\x1F\x7F]+/u', ' ', (string) $value ) );
		if ( function_exists( 'mb_substr' ) ) { return mb_substr( $s, 0, $max, 'UTF-8' ); }
		return substr( $s, 0, $max );
	}
}
