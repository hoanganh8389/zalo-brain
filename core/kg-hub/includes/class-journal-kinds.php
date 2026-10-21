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

	/** `''|health|child|finance|personal` — never leaves to the cell / evidence (R-JN-11). */
	public static function sensitive( string $kind ): string {
		$k = self::get( $kind );
		return $k ? (string) ( $k['sensitive'] ?? '' ) : '';
	}

	/**
	 * [2026-10-17 07:44 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.121 S121-K5 (R-JN-20) — default learning level of a kind:
	 * `index` (L0 only) · `vector` (L1, the default) · `graph` (L2 allowed without an explicit command). A sensitive kind never
	 * goes past `vector` by declaration alone. Unknown kind ⇒ `vector`.
	 */
	public static function learn( string $kind ): string {
		$k     = self::get( $kind );
		$learn = $k ? strtolower( (string) ( $k['learn'] ?? '' ) ) : '';
		// [2026-10-20 10:53 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.126 S126-B3 (journal-kinds@1.3) — `none`: a ledger row (job, skill, notebook pointer, sheet definition) is never embedded or graphed.
		if ( ! in_array( $learn, array( 'none', 'index', 'vector', 'graph' ), true ) ) {
			$learn = 'vector';
		}
		if ( 'graph' === $learn && '' !== self::sensitive( $kind ) ) {
			$learn = 'vector';
		}
		return $learn;
	}

	/** Is this kind declared (core JSON, profile or plugin)? Plugins must declare before writing (R-JN-17). */
	public static function is_declared( string $kind ): bool {
		return null !== self::get( $kind );
	}

	/** Test seam: forget the merged catalog (a plugin declared kinds after the first read). */
	public static function reset(): void {
		self::$memo = null;
	}

	/**
	 * [2026-10-20 10:53 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.126 S126-B1 (doc 05 §3, R-BRAIN-SKELETON) — the layer of a kind
	 * (journal-entry@1.2 `layer`): document · notebook · twin_sheet · skill · job · output, from the contract's `layers` table
	 * (by_kind, then by_prefix, then by_suffix, then default). Pure once the contract is read.
	 */
	public static function layer_of( string $kind ): string {
		$kind = BizCity_Journal_Database::sanitize_kind( $kind );
		$l    = self::raw()['layers'] ?? array();
		if ( isset( $l['by_kind'][ $kind ] ) ) {
			return (string) $l['by_kind'][ $kind ];
		}
		foreach ( (array) ( $l['by_prefix'] ?? array() ) as $p => $layer ) {
			if ( 0 === strpos( $kind, (string) $p ) ) {
				return (string) $layer;
			}
		}
		foreach ( (array) ( $l['by_suffix'] ?? array() ) as $s => $layer ) {
			if ( '' !== (string) $s && substr( $kind, -strlen( (string) $s ) ) === (string) $s ) {
				return (string) $layer;
			}
		}
		return (string) ( $l['default'] ?? 'document' );
	}

	/** Layers that carry a tier (L1 · L2 · L3): documents and notebook sources only (doc 05 §4). */
	public static function layer_has_tier( string $layer ): bool {
		$l = self::raw()['layers'] ?? array();
		return in_array( $layer, (array) ( $l['tiers_only_for'] ?? array( 'document', 'notebook' ) ), true );
	}

	/** The two-letter ref prefix of a kind (`ref_codes` table), '' when the kind has none (documents use the TL file code). */
	public static function ref_prefix( string $kind ): string {
		$kind = BizCity_Journal_Database::sanitize_kind( $kind );
		$r    = self::raw()['ref_codes'] ?? array();
		return isset( $r[ $kind ] ) && is_string( $r[ $kind ] ) ? (string) $r[ $kind ] : '';
	}

	/** kind ⇒ prefix for every kind that has one (the MCP void / parse helpers read this). */
	public static function ref_prefixes(): array {
		$out = array();
		foreach ( (array) ( self::raw()['ref_codes'] ?? array() ) as $kind => $p ) {
			if ( is_string( $p ) && false !== strpos( (string) $kind, '.' ) ) {
				$out[ (string) $kind ] = $p;
			}
		}
		return $out;
	}

	/** @var array|null the raw contract (layers, ref_codes) — not merged with plugin kinds */
	private static $raw = null;

	private static function raw(): array {
		if ( null === self::$raw ) {
			$path      = self::contract_path();
			$decoded   = is_readable( $path ) ? json_decode( (string) file_get_contents( $path ), true ) : null;
			self::$raw = is_array( $decoded ) ? $decoded : array();
		}
		return self::$raw;
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
