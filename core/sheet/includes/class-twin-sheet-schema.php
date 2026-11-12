<?php
/**
 * BizCity_Twin_Sheet_Schema — `twin-sheet@1.0.0`: typed columns, value checks and the query allow-list of a Twin Sheet ("Sổ").
 *
 * A Twin Sheet is a DEFINITION over journal rows (PHASE-0.123 D123-1/D123-2): its rows are `bizcity_journal_entries` rows of
 * one kind, its column values are `metadata.card.<key>` (or an index column / a computed value). Pure: no database, no model.
 *
 * Money is an integer in the smallest unit of the currency (VND: đồng) — the cell already writes `amount` as an integer
 * (zalo-hub/src/sheets/journal-map.ts); a digit string is accepted too. Never a float (D123-10).
 *
 * Biz Central Brain — Johnny Chu (Chu Hoàng Anh).
 *
 * [2026-10-19 09:12 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.123 W1 (B2) — new file.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\KG_Hub\Twin_Sheet
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Twin_Sheet_Schema', false ) ) {
	return;
}

final class BizCity_Twin_Sheet_Schema {

	const CONTRACT = 'twin-sheet@1.0.0';

	const TYPES = array( 'text', 'long_text', 'integer', 'money', 'decimal', 'boolean', 'date', 'datetime', 'select', 'percent', 'ref_code' );

	/** index columns of the journal row a field may read (`source: column`) */
	const COLUMNS = array( 'doc_date', 'title', 'state', 'tags', 'summary', 'created_at', 'updated_at' );

	const OPS = array( 'eq', 'in', 'contains', 'between', 'empty', 'not_empty' );

	const MAX_FIELDS    = 30;
	const MAX_QUERYABLE = 8;
	const PAGE_MAX      = 100;
	const SCAN_MAX      = 50000;
	const MONEY_MAX     = 1000000000000000; // 10^15 smallest units — far above any book; bounds parsing, not business rules
	const TEXT_MAX      = 200;
	const LONG_TEXT_MAX = 2000;

	// ── sheet definition ──────────────────────────────────────────────

	/**
	 * Normalize one sheet definition (built-in JSON, generic, or — W3 — a twin_sheet post). Unknown field types, bad keys and
	 * duplicates are dropped; a sensitive finance kind is always `void_reenter` (R-JN-25), whatever the definition says.
	 *
	 * @return array|WP_Error twin-sheet@1 definition
	 */
	public static function normalize_sheet( string $sheet_key, array $def, string $kind, string $origin, string $sensitive = '' ) {
		$fields = array();
		$seen   = array();
		$queryable = 0;
		foreach ( (array) ( $def['fields'] ?? array() ) as $f ) {
			if ( ! is_array( $f ) ) {
				continue;
			}
			$key  = (string) ( $f['key'] ?? '' );
			$type = (string) ( $f['type'] ?? 'text' );
			if ( ! self::valid_key( $key ) || isset( $seen[ $key ] ) || ! in_array( $type, self::TYPES, true ) ) {
				continue;
			}
			$source = in_array( (string) ( $f['source'] ?? 'card' ), array( 'card', 'column', 'computed' ), true ) ? (string) ( $f['source'] ?? 'card' ) : 'card';
			if ( 'column' === $source && ! in_array( $key, self::COLUMNS, true ) ) {
				continue;
			}
			if ( 'ref_code' === $type ) {
				$source = 'computed';
			}
			$field = array(
				'key'      => $key,
				'label'    => self::clean_text( (string) ( $f['label'] ?? $key ), 80 ),
				'type'     => $type,
				'source'   => $source,
				'readonly' => 'computed' === $source || ! empty( $f['readonly'] ),
				'hidden'   => ! empty( $f['hidden'] ),
				// [2026-10-19 10:30 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.123 W2 (B9) — a person edits only visible, non-computed, non-readonly columns; `min` / `max` bound numbers (the cell's own 500 triệu ceiling for one entry).
				'editable' => ! ( 'computed' === $source || ! empty( $f['readonly'] ) || ! empty( $f['hidden'] ) ),
				'required' => ! empty( $f['required'] ),
				'filter'   => ! empty( $f['filter'] ),
				'sort'     => ! empty( $f['sort'] ),
				'group'    => ! empty( $f['group'] ),
				'search'   => ! empty( $f['search'] ),
				'aggregate'=> array_values( array_intersect( (array) ( $f['aggregate'] ?? array() ), array( 'sum', 'count', 'min', 'max', 'avg' ) ) ),
			);
			if ( in_array( $type, array( 'money', 'integer', 'percent' ), true ) ) {
				foreach ( array( 'min', 'max' ) as $bound ) {
					if ( isset( $f[ $bound ] ) && null !== self::money_to_int( $f[ $bound ] ) ) {
						$field[ $bound ] = self::money_to_int( $f[ $bound ] );
					}
				}
			}
			if ( 'select' === $type ) {
				$opts = array();
				foreach ( (array) ( $f['options'] ?? array() ) as $o ) {
					if ( is_array( $o ) && is_scalar( $o['v'] ?? null ) && '' !== (string) $o['v'] ) {
						$opts[] = array( 'v' => (string) $o['v'], 'label' => self::clean_text( (string) ( $o['label'] ?? $o['v'] ), 60 ) );
					}
				}
				$field['options'] = $opts;
			}
			if ( '' !== (string) ( $f['currency_field'] ?? '' ) && self::valid_key( (string) $f['currency_field'] ) ) {
				$field['currency_field'] = (string) $f['currency_field'];
			}
			if ( $field['filter'] || $field['sort'] || $field['group'] || array() !== $field['aggregate'] ) {
				if ( $queryable >= self::MAX_QUERYABLE && 'doc_date' !== $key ) {
					$field['filter'] = $field['sort'] = $field['group'] = false; // over the allow-list budget: shown, not queryable
					$field['aggregate'] = array();
				} else {
					$queryable++;
				}
			}
			$seen[ $key ] = true;
			$fields[]     = $field;
			if ( count( $fields ) >= self::MAX_FIELDS ) {
				break;
			}
		}
		if ( array() === $fields ) {
			return self::error( 'sheet_invalid', 'Sổ này chưa có cột nào hợp lệ.', 'Thêm ít nhất một cột có tên và kiểu.', 422 );
		}
		$policy = 'void_reenter' === (string) ( $def['edit_policy'] ?? '' ) || 'finance' === $sensitive ? 'void_reenter' : 'rev';
		$views  = array();
		foreach ( (array) ( $def['views'] ?? array() ) as $v ) {
			if ( is_array( $v ) && self::valid_key( (string) ( $v['key'] ?? '' ) ) ) {
				$views[] = array(
					'key'    => (string) $v['key'],
					'label'  => self::clean_text( (string) ( $v['label'] ?? $v['key'] ), 60 ),
					'period' => self::valid_period( (string) ( $v['period'] ?? 'all' ) ) ? (string) ( $v['period'] ?? 'all' ) : 'all',
					'sort'   => array_values( array_filter( (array) ( $v['sort'] ?? array() ), 'is_array' ) ),
					'totals' => array_values( array_filter( (array) ( $v['totals'] ?? array() ), 'is_array' ) ),
				);
			}
		}
		if ( array() === $views ) {
			$views[] = array( 'key' => 'all', 'label' => 'Tất cả', 'period' => 'all', 'sort' => array(), 'totals' => array() );
		}
		return array(
			'contract'       => self::CONTRACT,
			'sheet_key'      => $sheet_key,
			'kind'           => $kind,
			'origin'         => $origin,
			'display_name'   => self::clean_text( (string) ( $def['label'] ?? $sheet_key ), 120 ),
			'icon'           => self::valid_key( (string) ( $def['icon'] ?? '' ) ) ? (string) $def['icon'] : 'sheet',
			'folder'         => self::clean_text( (string) ( $def['folder'] ?? '' ), 60 ),
			'edit_policy'    => $policy,
			// W2 (B9): the typed built-in books take edits through BizCity_Twin_Sheet_Service; a generic sheet stays read-only (D123-14).
			// [2026-10-19 11:45 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.123 W3 — the person's own sheets take edits too (custom).
			'editable'       => 'builtin' === $origin || 'custom' === $origin,
			'create_defaults'=> self::scalar_map( $def['create_defaults'] ?? array() ),
			'title_rule'     => 1 === preg_match( '/^(todo|card:[a-z][a-z0-9_]{1,31})$/', (string) ( $def['title_rule'] ?? '' ) ) ? (string) $def['title_rule'] : '',
			'sensitive'      => $sensitive,
			'schema_version' => max( 1, (int) ( $def['schema_version'] ?? 1 ) ),
			'fields'         => $fields,
			'views'          => $views,
			'headline'       => is_array( $def['headline'] ?? null ) ? $def['headline'] : null,
			'latest_by'      => is_array( $def['latest_by'] ?? null ) ? array( 'key' => 'source_ref', 'order_field' => self::valid_key( (string) ( $def['latest_by']['order_field'] ?? '' ) ) ? (string) $def['latest_by']['order_field'] : 'version' ) : null,
			'pair_cancel'    => is_array( $def['pair_cancel'] ?? null ) ? $def['pair_cancel'] : null,
		);
	}

	/** {key => scalar}: only string keys of the key shape, scalar values (create defaults of a sheet). */
	private static function scalar_map( $m ): array {
		$out = array();
		foreach ( is_array( $m ) ? $m : array() as $k => $v ) {
			if ( self::valid_key( (string) $k ) && is_scalar( $v ) ) {
				$out[ (string) $k ] = $v;
			}
		}
		return $out;
	}

	/** One field of a normalized sheet, or null. */
	public static function field( array $sheet, string $key ): ?array {
		foreach ( (array) ( $sheet['fields'] ?? array() ) as $f ) {
			if ( $key === ( $f['key'] ?? '' ) ) {
				return $f;
			}
		}
		return null;
	}

	/** `[a-z][a-z0-9_]{1,31}` — a stable ASCII key (labels carry the Vietnamese). */
	public static function valid_key( string $key ): bool {
		return 1 === preg_match( '/^[a-z][a-z0-9_]{1,31}$/', $key );
	}

	// ── values ────────────────────────────────────────────────────────

	/**
	 * Integer money in the smallest unit, or null. Accepts an int, an integral float (JSON numbers) or a digit string with an
	 * optional sign; rejects "250k", "1.5", separators and anything over MONEY_MAX — the cell converts words before sending.
	 */
	public static function money_to_int( $v ): ?int {
		if ( is_int( $v ) ) {
			return abs( $v ) <= self::MONEY_MAX ? $v : null;
		}
		if ( is_float( $v ) ) {
			return floor( $v ) === $v && abs( $v ) <= self::MONEY_MAX ? (int) $v : null;
		}
		if ( is_string( $v ) && 1 === preg_match( '/^-?[0-9]{1,16}$/', trim( $v ) ) ) {
			$n = (int) trim( $v );
			return abs( $n ) <= self::MONEY_MAX ? $n : null;
		}
		return null;
	}

	/**
	 * Check one value against its field. Empty (null / '') is allowed unless required.
	 *
	 * @return array{ok:bool,value:mixed,code:string}
	 */
	public static function validate_value( array $field, $value ): array {
		$ok  = static function ( $v ) { return array( 'ok' => true, 'value' => $v, 'code' => '' ); };
		$bad = static function ( string $code ) { return array( 'ok' => false, 'value' => null, 'code' => $code ); };
		if ( ! empty( $field['readonly'] ) ) {
			return $bad( 'field_readonly' );
		}
		if ( null === $value || ( is_string( $value ) && '' === trim( $value ) ) ) {
			return ! empty( $field['required'] ) ? $bad( 'field_required' ) : $ok( '' );
		}
		switch ( (string) ( $field['type'] ?? 'text' ) ) {
			case 'text':
			case 'long_text':
				if ( ! is_scalar( $value ) ) {
					return $bad( 'field_type_invalid' );
				}
				$max = 'long_text' === $field['type'] ? self::LONG_TEXT_MAX : self::TEXT_MAX;
				$s   = self::clean_text( (string) $value, $max + 1 );
				return mb_strlen( $s ) > $max ? $bad( 'field_too_long' ) : $ok( $s );
			case 'integer':
			case 'money':
				$n = self::money_to_int( $value );
				if ( null === $n ) {
					return $bad( 'field_type_invalid' );
				}
				if ( ( isset( $field['min'] ) && $n < (int) $field['min'] ) || ( isset( $field['max'] ) && $n > (int) $field['max'] ) ) {
					return $bad( 'field_out_of_range' );
				}
				return $ok( $n );
			case 'percent':
				$n = self::money_to_int( $value );
				return null === $n || $n < 0 || $n > 100 ? $bad( 'field_type_invalid' ) : $ok( $n );
			case 'decimal':
				$s = is_int( $value ) ? (string) $value : ( is_string( $value ) ? trim( $value ) : '' );
				return 1 === preg_match( '/^-?[0-9]{1,13}(\.[0-9]{1,4})?$/', $s ) ? $ok( $s ) : $bad( 'field_type_invalid' );
			case 'boolean':
				$b = self::to_bool( $value );
				return null === $b ? $bad( 'field_type_invalid' ) : $ok( $b );
			case 'date':
				return self::valid_date( is_string( $value ) ? trim( $value ) : '' ) ? $ok( trim( $value ) ) : $bad( 'field_type_invalid' );
			case 'datetime':
				$s = is_string( $value ) ? trim( $value ) : '';
				return 1 === preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2})?(\.\d+)?(Z|[+-]\d{2}:\d{2})$/', $s ) && self::valid_date( substr( $s, 0, 10 ) ) ? $ok( $s ) : $bad( 'field_type_invalid' );
			case 'select':
				$v = is_scalar( $value ) ? (string) $value : '';
				return in_array( $v, array_column( (array) ( $field['options'] ?? array() ), 'v' ), true ) ? $ok( $v ) : $bad( 'field_type_invalid' );
		}
		return $bad( 'field_type_invalid' );
	}

	/** true / false / null (not a boolean). Accepts 0/1, "0"/"1", "true"/"false" — the cell writes `done` as 0/1. */
	public static function to_bool( $v ): ?bool {
		if ( is_bool( $v ) ) {
			return $v;
		}
		if ( 1 === $v || '1' === $v || 'true' === $v ) {
			return true;
		}
		if ( 0 === $v || '0' === $v || 'false' === $v ) {
			return false;
		}
		return null;
	}

	public static function valid_date( string $d ): bool {
		return 1 === preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m ) && checkdate( (int) $m[2], (int) $m[3], (int) $m[1] );
	}

	// ── query (allow-list) ────────────────────────────────────────────

	public static function valid_period( string $p ): bool {
		return in_array( $p, array( 'all', 'today', 'week', 'month', 'year' ), true ) || 1 === preg_match( '/^\d{4}-\d{2}$/', $p );
	}

	/** [from, to] (Y-m-d, '' = open) of a period relative to $today (Y-m-d, site time). */
	public static function period_range( string $period, string $today ): array {
		$ts = strtotime( $today . ' 00:00:00 UTC' );
		if ( false === $ts || ! self::valid_period( $period ) || 'all' === $period ) {
			return array( '', '' );
		}
		if ( 'today' === $period ) {
			return array( $today, $today );
		}
		if ( 'week' === $period ) {
			$dow  = (int) gmdate( 'N', $ts );
			$from = gmdate( 'Y-m-d', $ts - ( $dow - 1 ) * 86400 );
			return array( $from, gmdate( 'Y-m-d', strtotime( $from . ' +6 days UTC' ) ) );
		}
		if ( 'year' === $period ) {
			return array( gmdate( 'Y', $ts ) . '-01-01', gmdate( 'Y', $ts ) . '-12-31' );
		}
		$ym   = 'month' === $period ? gmdate( 'Y-m', $ts ) : $period;
		$from = $ym . '-01';
		return array( $from, gmdate( 'Y-m-t', (int) strtotime( $from . ' UTC' ) ) );
	}

	/**
	 * Turn a caller's query into an allow-listed one. Only fields with `filter`/`group` filter, only `sort` fields sort; ops from
	 * OPS; values are checked by type where the op needs one. Never builds SQL — the store applies it.
	 *
	 * @param array $q {view?, period?, from?, to?, where?: [{field, op?, value?}] | {field: value}, sort?: [{field, dir}], q?, limit?, offset?}
	 * @return array|WP_Error {view, from, to, where[], sort[], q, limit, offset}
	 */
	public static function normalize_query( array $sheet, array $q, string $today ) {
		$view = null;
		foreach ( (array) ( $sheet['views'] ?? array() ) as $v ) {
			if ( (string) ( $q['view'] ?? '' ) === $v['key'] ) {
				$view = $v;
			}
		}
		if ( null === $view && '' !== (string) ( $q['view'] ?? '' ) ) {
			return self::error( 'view_unknown', 'Không có cách xem này trong sổ.', 'Chọn một cách xem có sẵn của sổ.', 400 );
		}
		$view   = $view ?? ( $sheet['views'][0] ?? array( 'key' => 'all', 'period' => 'all', 'sort' => array() ) );
		$period = (string) ( $q['period'] ?? $view['period'] ?? 'all' );
		if ( ! self::valid_period( $period ) ) {
			return self::error( 'period_invalid', 'Kỳ không hợp lệ.', 'Dùng all, today, week, month, year hoặc YYYY-MM.', 400 );
		}
		list( $from, $to ) = self::period_range( $period, $today );
		foreach ( array( 'from', 'to' ) as $edge ) {
			if ( isset( $q[ $edge ] ) && '' !== (string) $q[ $edge ] && ! self::valid_date( (string) $q[ $edge ] ) ) {
				return self::error( 'period_invalid', 'Ngày lọc không đúng dạng.', 'Nhập ngày dạng YYYY-MM-DD.', 400 );
			}
		}
		if ( '' !== (string) ( $q['from'] ?? '' ) ) {
			$from = (string) $q['from'];
		}
		if ( '' !== (string) ( $q['to'] ?? '' ) ) {
			$to = (string) $q['to'];
		}
		$where = array();
		$raw   = $q['where'] ?? array();
		if ( is_array( $raw ) && array() !== $raw && array_keys( $raw ) !== range( 0, count( $raw ) - 1 ) ) {
			$list = array();
			foreach ( $raw as $k => $v ) {
				$list[] = array( 'field' => (string) $k, 'op' => is_array( $v ) ? 'in' : 'eq', 'value' => $v );
			}
			$raw = $list;
		}
		foreach ( array_slice( (array) $raw, 0, 10 ) as $w ) {
			if ( ! is_array( $w ) ) {
				continue;
			}
			$f = self::field( $sheet, (string) ( $w['field'] ?? '' ) );
			if ( ! $f || ( empty( $f['filter'] ) && empty( $f['group'] ) ) ) {
				return self::error( 'field_not_queryable', 'Không lọc được theo cột này.', 'Chỉ lọc theo các cột có biểu tượng lọc trên bảng.', 400 );
			}
			$op = (string) ( $w['op'] ?? 'eq' );
			if ( ! in_array( $op, self::OPS, true ) ) {
				return self::error( 'op_invalid', 'Kiểu lọc không hỗ trợ.', 'Dùng bằng, một trong, chứa, khoảng, trống hoặc có giá trị.', 400 );
			}
			$value = $w['value'] ?? null;
			if ( 'in' === $op ) {
				$value = array_slice( array_values( array_filter( (array) $value, 'is_scalar' ) ), 0, 20 );
			} elseif ( 'between' === $op ) {
				$pair = array_values( (array) $value );
				$value = array( $pair[0] ?? '', $pair[1] ?? '' );
			} elseif ( in_array( $op, array( 'eq', 'contains' ), true ) ) {
				if ( ! is_scalar( $value ) && ! is_bool( $value ) ) {
					return self::error( 'field_type_invalid', 'Giá trị lọc không hợp lệ.', 'Nhập một giá trị cho cột lọc.', 400 );
				}
				if ( 'contains' === $op ) {
					$value = self::clean_text( (string) $value, 100 );
				}
			} else {
				$value = null;
			}
			$where[] = array( 'field' => $f['key'], 'op' => $op, 'value' => $value );
		}
		// [2026-10-20 05:48 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.124 S124-X1 — lọc sổ theo nhóm Zalo: dòng mang tag grp-<gid> (API đổi NH-<conv> ra gid trước)
		$group = trim( (string) ( $q['group'] ?? '' ) );
		if ( '' !== $group && ! preg_match( '/^[0-9]{3,30}$/', $group ) ) {
			return self::error( 'group_invalid', 'Nhóm lọc không hợp lệ.', 'Dùng mã nhóm dạng NH-… trong Sổ nhóm.', 400 );
		}
		$sort = array();
		foreach ( array_slice( (array) ( $q['sort'] ?? $view['sort'] ?? array() ), 0, 3 ) as $s ) {
			if ( ! is_array( $s ) ) {
				continue;
			}
			$f = self::field( $sheet, (string) ( $s['field'] ?? '' ) );
			if ( ! $f || empty( $f['sort'] ) ) {
				return self::error( 'field_not_queryable', 'Không sắp xếp được theo cột này.', 'Chỉ sắp xếp theo các cột có mũi tên trên bảng.', 400 );
			}
			$sort[] = array( 'field' => $f['key'], 'dir' => 'asc' === strtolower( (string) ( $s['dir'] ?? 'desc' ) ) ? 'asc' : 'desc' );
		}
		return array(
			'view'   => (string) ( $view['key'] ?? 'all' ),
			'period' => $period,
			'from'   => $from,
			'to'     => $to,
			'where'  => $where,
			'sort'   => $sort,
			'q'      => self::clean_text( (string) ( $q['q'] ?? '' ), 200 ),
			'limit'  => min( self::PAGE_MAX, max( 1, (int) ( $q['limit'] ?? 50 ) ) ),
			'offset' => max( 0, (int) ( $q['offset'] ?? 0 ) ),
			'include_void' => ! empty( $q['include_void'] ),
			'group'        => $group,
		);
	}

	// ── helpers ───────────────────────────────────────────────────────

	/** Trim, drop control characters and tags, cap length (multibyte-safe). */
	public static function clean_text( string $s, int $max ): string {
		$s = (string) preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', strip_tags( $s ) );
		$s = trim( $s );
		return function_exists( 'mb_substr' ) ? mb_substr( $s, 0, $max ) : substr( $s, 0, $max );
	}

	/** R-ERROR-UX error: code + message + hint + help_code, HTTP status in data. */
	public static function error( string $code, string $message, string $hint, int $status = 400 ): WP_Error {
		return new WP_Error( $code, $message, array( 'status' => $status, 'hint' => $hint, 'help_code' => 'S123-TS-' . $code ) );
	}
}
