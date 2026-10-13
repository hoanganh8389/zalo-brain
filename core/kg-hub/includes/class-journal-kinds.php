<?php
/**
 * BizCity_Journal_Kinds — reads `journal-kinds@1.0.0` (core/kg-hub/contracts/JOURNAL-KINDS-v1.json) and the
 * `bizcity_journal_kinds` filter (profiles / plugins add kinds by declaration, never by code — R-JOURNAL R-JN-6).
 *
 * Biz Central Brain — Johnny Chu (Chu Hoàng Anh).
 *
 * [2026-10-12 10:05 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.100 S100-L16 — new file.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\KG_Hub
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Journal_Kinds', false ) ) {
	return;
}

final class BizCity_Journal_Kinds {

	const FALLBACK_KIND = 'common.document';

	/** @var array|null memoized merged {families, kinds} */
	private static $memo = null;

	/** @return array{families:array,kinds:array} */
	public static function all(): array {
		if ( is_array( self::$memo ) ) {
			return self::$memo;
		}
		$path = self::contract_path();
		$base = array( 'families' => array(), 'kinds' => array() );
		if ( is_readable( $path ) ) {
			$decoded = json_decode( (string) file_get_contents( $path ), true );
			if ( is_array( $decoded ) ) {
				$base['families'] = is_array( $decoded['families'] ?? null ) ? $decoded['families'] : array();
				$base['kinds']    = is_array( $decoded['kinds'] ?? null ) ? $decoded['kinds'] : array();
			}
		}
		/**
		 * A `brain-profile@1.1` (`journal.json`) or a plugin's `zalo-brain.json` `journal_kinds` key adds kinds
		 * declaratively. Filter receives/returns the FULL {families, kinds} shape, merged with the defaults above.
		 *
		 * @param array $out {families, kinds}
		 */
		$out = (array) apply_filters( 'bizcity_journal_kinds', $base );
		if ( ! isset( $out['families'] ) || ! is_array( $out['families'] ) ) {
			$out['families'] = $base['families'];
		}
		if ( ! isset( $out['kinds'] ) || ! is_array( $out['kinds'] ) ) {
			$out['kinds'] = $base['kinds'];
		}
		self::$memo = $out;
		return $out;
	}

	/** One kind's declaration, or null when unknown. */
	public static function get( string $kind ): ?array {
		$kind = BizCity_Journal_Database::sanitize_kind( $kind );
		$all  = self::all();
		return isset( $all['kinds'][ $kind ] ) && is_array( $all['kinds'][ $kind ] ) ? $all['kinds'][ $kind ] : null;
	}

	/** Known kind ⇒ itself; unknown ⇒ the `common.*` fallback by rough item type (doc 92 §3 "không khớp loại nào"). */
	public static function resolve( string $kind, string $fallback_hint = '' ): string {
		$kind = BizCity_Journal_Database::sanitize_kind( $kind );
		if ( '' !== $kind && null !== self::get( $kind ) ) {
			return $kind;
		}
		$map = array(
			'image' => 'common.photo',
			'photo' => 'common.photo',
			'file'  => 'common.document',
			'audio' => 'common.voice_note',
			'voice' => 'common.voice_note',
			'text'  => 'common.note',
		);
		return $map[ strtolower( $fallback_hint ) ] ?? self::FALLBACK_KIND;
	}

	/** `''|health|child|finance` — never leaves to the cell / evidence (R-JN-11). */
	public static function sensitive( string $kind ): string {
		$k = self::get( $kind );
		return $k ? (string) ( $k['sensitive'] ?? '' ) : '';
	}

	/** Vietnamese shelf label for a kind, else the kind itself. */
	public static function label( string $kind ): string {
		$k = self::get( $kind );
		return $k ? (string) ( $k['label'] ?? $kind ) : $kind;
	}

	public static function family( string $kind ): string {
		$k = self::get( $kind );
		if ( $k && ! empty( $k['family'] ) ) {
			return (string) $k['family'];
		}
		$parts = explode( '.', $kind, 2 );
		return $parts[0] ?? '';
	}

	/** R-JN-7: nutrition/nanny default ON; everything else OFF unless the profile turns it on. // [2026-10-12 11:00 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.100 D100-25 owner-only: education is OFF (owner-only, no per-student notebooks). */
	public static function per_subject_notebook_default( string $family ): bool {
		$all = self::all();
		$f   = $all['families'][ $family ] ?? null;
		return is_array( $f ) ? ! empty( $f['per_subject_notebook_default'] ) : false;
	}

	/**
	 * [2026-10-12 Johnny Chu - Chu Hoàng Anh] PHASE-0.104 S104-L3 — the machine form lives in core/kg-hub/contracts/ (ships). It used to be read from
	 * docs/contracts/, which R-PROD-REPO never uploads: production silently had NO built-in kinds (only filter-added ones).
	 */
	private static function contract_path(): string {
		return rtrim( BIZCITY_KG_HUB_DIR, '/\\' ) . '/contracts/JOURNAL-KINDS-v1.json';
	}
}
