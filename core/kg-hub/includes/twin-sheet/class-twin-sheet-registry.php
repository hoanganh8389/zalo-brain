<?php
/**
 * BizCity_Twin_Sheet_Registry — which Twin Sheets ("Sổ") exist and what their columns are.
 *
 *   built-in  — core/kg-hub/contracts/TWIN-SHEET-BUILTIN-v1.json (typed columns of fin.cash, fin.debt, fin.plan, task.*, biz.contract)
 *   generic   — every other DECLARED journal kind opens as a read-only sheet with the common columns (D123-14)
 *   custom    — W3: a `twin_sheet` post (data.s<ID>) the person made; only its author sees or uses it (Defs seam: BizCity_Twin_Sheet_Cpt_Defs)
 *
 * No post, no table for built-in or generic sheets (D123-2: "sổ ảo"). Cache contract: the merged definitions are memoized per
 * request (static, read-only JSON + the kinds filter — nothing to invalidate across requests); the per-owner sheet list reads
 * `BizCity_Journal_Database::shelf_rows()` (indexed GROUP BY on owner + kind), wrapped in BizCity_Cache group `journal`, which
 * every journal write already flushes (R-CACHE).
 *
 * Biz Central Brain — Johnny Chu (Chu Hoàng Anh).
 *
 * [2026-10-19 09:12 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.123 W1 (B3) — new file.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\KG_Hub\Twin_Sheet
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Twin_Sheet_Registry', false ) ) {
	return;
}

final class BizCity_Twin_Sheet_Registry {

	const BUILTIN_CONTRACT = 'twin-sheet-builtin@1.0.0';

	/** @var array|null raw built-in definitions keyed by kind */
	private static $builtin = null;

	/** @var array normalized built-in / generic sheets keyed by sheet_key (request memo) */
	private static $memo = array();

	/** @var BizCity_Twin_Sheet_Defs|null */
	private static $defs = null;

	/** [2026-10-19 11:45 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.123 W3 — the store of the person's own sheet definitions (a twin_sheet post per sheet). */
	public static function defs(): BizCity_Twin_Sheet_Defs {
		if ( null === self::$defs ) {
			self::$defs = new BizCity_Twin_Sheet_Cpt_Defs();
		}
		return self::$defs;
	}

	/** Test seam. */
	public static function set_defs( ?BizCity_Twin_Sheet_Defs $defs ): void {
		self::$defs = $defs;
	}

	/** `data.s<ID>` — a sheet a person made */
	public static function is_custom_key( string $key ): bool {
		return 1 === preg_match( '/^data.s[0-9]{1,12}$/', $key );
	}

	/** `<family>.<kind>` exactly as journal kinds are spelled. */
	public static function valid_sheet_key( string $key ): bool {
		return 1 === preg_match( '/^[a-z][a-z0-9_]{0,23}\.[a-z0-9_]{1,23}$/', $key );
	}

	/** @return array raw built-in definitions keyed by kind (empty when the contract file is missing — never fatal). */
	public static function builtin_defs(): array {
		if ( is_array( self::$builtin ) ) {
			return self::$builtin;
		}
		self::$builtin = array();
		$path = defined( 'BIZCITY_KG_HUB_DIR' ) ? rtrim( BIZCITY_KG_HUB_DIR, '/\\' ) . '/contracts/TWIN-SHEET-BUILTIN-v1.json' : '';
		if ( '' !== $path && is_readable( $path ) ) {
			$decoded = json_decode( (string) file_get_contents( $path ), true );
			if ( is_array( $decoded ) && self::BUILTIN_CONTRACT === ( $decoded['contract'] ?? '' ) && is_array( $decoded['sheets'] ?? null ) ) {
				self::$builtin = $decoded['sheets'];
			}
		}
		return self::$builtin;
	}

	/**
	 * One sheet, normalized (twin-sheet@1). Unknown / undeclared ⇒ 404 `sheet_not_found` (never reveals whether a kind exists
	 * for someone else — sheets are definitions, rows are always owner-scoped by the store).
	 *
	 * @return array|WP_Error
	 */
	public static function get( string $sheet_key, int $owner_user_id = 0 ) {
		$sheet_key = strtolower( trim( $sheet_key ) );
		if ( self::is_custom_key( $sheet_key ) ) {
			return self::get_custom( $sheet_key, $owner_user_id );
		}
		if ( isset( self::$memo[ $sheet_key ] ) ) {
			return self::$memo[ $sheet_key ];
		}
		if ( ! self::valid_sheet_key( $sheet_key ) || ! class_exists( 'BizCity_Journal_Kinds' ) || ! BizCity_Journal_Kinds::is_declared( $sheet_key ) ) {
			return BizCity_Twin_Sheet_Schema::error( 'sheet_not_found', 'Không tìm thấy sổ này.', 'Mở danh sách sổ và chọn lại.', 404 );
		}
		$sensitive = BizCity_Journal_Kinds::sensitive( $sheet_key );
		$defs      = self::builtin_defs();
		if ( isset( $defs[ $sheet_key ] ) && is_array( $defs[ $sheet_key ] ) ) {
			$sheet = BizCity_Twin_Sheet_Schema::normalize_sheet( $sheet_key, $defs[ $sheet_key ], $sheet_key, 'builtin', $sensitive );
		} else {
			$sheet = BizCity_Twin_Sheet_Schema::normalize_sheet( $sheet_key, self::generic_def( $sheet_key ), $sheet_key, 'generic', $sensitive );
		}
		if ( ! is_wp_error( $sheet ) ) {
			self::$memo[ $sheet_key ] = $sheet;
		}
		return $sheet;
	}

	/**
	 * A sheet the person made. Another person's sheet answers exactly like a missing one (404, no hint that it exists). The definition is
	 * normalized every time from the stored JSON, so a column rule fixed in code applies to old sheets too.
	 *
	 * @return array|WP_Error
	 */
	private static function get_custom( string $key, int $owner ) {
		$rec = self::defs()->get_by_key( $key );
		if ( ! $rec || $owner <= 0 || (int) $rec['owner'] !== $owner ) {
			return BizCity_Twin_Sheet_Schema::error( 'sheet_not_found', 'Không tìm thấy sổ này.', 'Mở danh sách sổ và chọn lại.', 404 );
		}
		return self::normalize_record( $rec );
	}

	/** @return array|WP_Error twin-sheet@1 of a stored record */
	public static function normalize_record( array $rec ) {
		$def = (array) $rec['def'];
		$def['label']  = (string) $rec['title'];
		$def['icon']   = '' !== (string) $rec['icon'] ? (string) $rec['icon'] : (string) ( $def['icon'] ?? 'sheet' );
		$def['folder'] = (string) $rec['folder'];
		$sheet = BizCity_Twin_Sheet_Schema::normalize_sheet( (string) $rec['key'], $def, (string) $rec['key'], 'custom', (string) ( $def['sensitive'] ?? '' ) );
		if ( ! is_wp_error( $sheet ) ) {
			$sheet['sheet_id'] = (int) $rec['id'];
		}
		return $sheet;
	}

	/**
	 * Filter on `bizcity_journal_kinds`: every custom sheet is a journal kind of family `data`, so the one write door
	 * (bizcity_journal_record) and the one reader accept its rows. Lazy: runs when the catalog is first read in a request; the list of
	 * definitions comes from the cached Defs.
	 */
	public static function filter_kinds( $base ) {
		$base = is_array( $base ) ? $base : array();
		if ( ! isset( $base['families']['data'] ) ) {
			$base['families']['data'] = array( 'label' => 'Sổ tự tạo', 'per_subject_notebook_default' => false, 'sensitive' => '' );
		}
		// [2026-10-21 10:40 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.123 W4 (R-BRAIN-SKELETON "no binding, no merge") — `sheet.snapshot` moved into JOURNAL-KINDS-v1.json (layer twin_sheet, ref code CS-, learn none); only the person's own sheets are registered here
		foreach ( self::defs()->all() as $rec ) {
			$keys = array();
			foreach ( (array) ( $rec['def']['fields'] ?? array() ) as $f ) {
				if ( is_array( $f ) && 'card' === ( $f['source'] ?? 'card' ) && 'computed' !== ( $f['source'] ?? '' ) && BizCity_Twin_Sheet_Schema::valid_key( (string) ( $f['key'] ?? '' ) ) && 'ref_code' !== ( $f['type'] ?? '' ) ) {
					$keys[] = (string) $f['key'];
				}
			}
			$base['kinds'][ $rec['key'] ] = array(
				'family'      => 'data',
				'label'       => (string) $rec['title'],
				'icon'        => 'sheet',
				'sensitive'   => (string) ( $rec['def']['sensitive'] ?? '' ),
				'learn'       => 'index',
				'card_fields' => $keys,
			);
		}
		return $base;
	}

	/**
	 * Common columns for a kind with no typed definition: date · title · summary · state · tags · up to 6 card fields (text,
	 * shown, not queryable) · code. Read-only.
	 */
	public static function generic_def( string $kind ): array {
		$decl   = BizCity_Journal_Kinds::get( $kind ) ?: array();
		$fields = array(
			array( 'key' => 'doc_date', 'label' => 'Ngày', 'type' => 'date', 'source' => 'column', 'filter' => true, 'sort' => true ),
			array( 'key' => 'title', 'label' => 'Tiêu đề', 'type' => 'text', 'source' => 'column', 'search' => true, 'sort' => true ),
			array( 'key' => 'summary', 'label' => 'Tóm tắt', 'type' => 'long_text', 'source' => 'column', 'search' => true ),
		);
		$n = 0;
		foreach ( (array) ( $decl['card_fields'] ?? array() ) as $cf ) {
			$cf = (string) $cf;
			if ( 'summary' === $cf || ! BizCity_Twin_Sheet_Schema::valid_key( $cf ) || $n >= 6 ) {
				continue;
			}
			$fields[] = array( 'key' => $cf, 'label' => $cf, 'type' => 'text', 'source' => 'card' );
			$n++;
		}
		$fields[] = array( 'key' => 'state', 'label' => 'Trạng thái', 'type' => 'select', 'source' => 'column', 'filter' => true, 'group' => true, 'options' => array(
			array( 'v' => 'captured', 'label' => 'Đã ghi' ),
			array( 'v' => 'review', 'label' => 'Cần xem lại' ),
			array( 'v' => 'verified', 'label' => 'Đã xác nhận' ),
			array( 'v' => 'failed', 'label' => 'Lỗi' ),
			array( 'v' => 'void', 'label' => 'Đã hủy' ),
		) );
		$fields[] = array( 'key' => 'tags', 'label' => 'Thẻ', 'type' => 'text', 'source' => 'column', 'hidden' => true );
		$fields[] = array( 'key' => 'ref_code', 'label' => 'Mã', 'type' => 'ref_code', 'source' => 'computed' );
		return array(
			'label'  => (string) ( $decl['label'] ?? $kind ),
			'icon'   => (string) ( $decl['icon'] ?? 'sheet' ),
			'folder' => '',
			'fields' => $fields,
			'views'  => array( array( 'key' => 'all', 'label' => 'Tất cả', 'period' => 'all', 'sort' => array( array( 'field' => 'doc_date', 'dir' => 'desc' ) ) ) ),
		);
	}

	/**
	 * Pure: the sheet list from shelf rows (kind, c, d_to, u). Built-ins first (even with 0 rows — a person sees "Sổ thu chi"
	 * before the first entry), then every other declared kind that has rows, most rows first.
	 *
	 * @param array $shelf rows of BizCity_Journal_Database::shelf_rows()
	 * @return array[] {sheet_key, kind, display_name, icon, folder, origin, rows, last_date, updated_at}
	 */
	public static function list_from_shelf( array $shelf, array $custom = array() ): array {
		$by_kind = array();
		foreach ( $shelf as $r ) {
			$by_kind[ (string) ( $r['kind'] ?? '' ) ] = $r;
		}
		$out  = array();
		$seen = array();
		$push = static function ( string $kind ) use ( &$out, &$seen, $by_kind ) {
			$sheet = self::get( $kind );
			if ( is_wp_error( $sheet ) || isset( $seen[ $kind ] ) ) {
				return;
			}
			$seen[ $kind ] = true;
			$r     = $by_kind[ $kind ] ?? array();
			$out[] = array(
				'sheet_key'    => $sheet['sheet_key'],
				'kind'         => $kind,
				'display_name' => $sheet['display_name'],
				'icon'         => $sheet['icon'],
				'folder'       => $sheet['folder'],
				'origin'       => $sheet['origin'],
				'rows'         => (int) ( $r['c'] ?? 0 ),
				'last_date'    => (string) ( $r['d_to'] ?? '' ),
				'updated_at'   => (string) ( $r['u'] ?? '' ),
			);
		};
		foreach ( array_keys( self::builtin_defs() ) as $kind ) {
			$push( (string) $kind );
		}
		// the person's own sheets: shown even before the first row (a new sheet must be visible at once)
		foreach ( $custom as $sheet ) {
			if ( isset( $seen[ $sheet['sheet_key'] ] ) ) {
				continue;
			}
			$seen[ $sheet['sheet_key'] ] = true;
			$r     = $by_kind[ $sheet['sheet_key'] ] ?? array();
			$out[] = array(
				'sheet_key'    => $sheet['sheet_key'],
				'kind'         => $sheet['sheet_key'],
				'display_name' => $sheet['display_name'],
				'icon'         => $sheet['icon'],
				'folder'       => $sheet['folder'],
				'origin'       => 'custom',
				'rows'         => (int) ( $r['c'] ?? 0 ),
				'last_date'    => (string) ( $r['d_to'] ?? '' ),
				'updated_at'   => (string) ( $r['u'] ?? '' ),
			);
		}
		$rest = $shelf;
		usort( $rest, static function ( $a, $b ) { return (int) ( $b['c'] ?? 0 ) <=> (int) ( $a['c'] ?? 0 ); } );
		foreach ( $rest as $r ) {
			$push( (string) ( $r['kind'] ?? '' ) );
		}
		return $out;
	}

	/** The person's sheets (owner-scoped). */
	public static function list_for_owner( int $owner_user_id ): array {
		if ( $owner_user_id <= 0 || ! class_exists( 'BizCity_Journal_Database' ) ) {
			return array();
		}
		$key    = 'twinsheets_' . (int) get_current_blog_id() . '_' . $owner_user_id;
		$cached = class_exists( 'BizCity_Cache' ) ? BizCity_Cache::get( BizCity_Journal_Database::CACHE_GROUP, $key ) : false;
		if ( is_array( $cached ) ) {
			return $cached;
		}
		BizCity_Journal_Database::maybe_install();
		$custom = array();
		foreach ( self::defs()->all() as $rec ) {
			if ( (int) $rec['owner'] === $owner_user_id ) {
				$sheet = self::normalize_record( $rec );
				if ( ! is_wp_error( $sheet ) ) {
					$custom[] = $sheet;
				}
			}
		}
		$out = self::list_from_shelf( BizCity_Journal_Database::instance()->shelf_rows( $owner_user_id, 60 ), $custom );
		if ( class_exists( 'BizCity_Cache' ) ) {
			BizCity_Cache::set( BizCity_Journal_Database::CACHE_GROUP, $key, $out, 300 );
		}
		return $out;
	}

	/** Test seam. */
	public static function reset(): void {
		self::$builtin = null;
		self::$memo    = array();
		self::$defs    = null;
	}
}
