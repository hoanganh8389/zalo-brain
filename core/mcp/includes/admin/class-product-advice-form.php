<?php
/**
 * BizCity_Product_Advice_Form — pure helpers behind the "Tư vấn AI" admin screens (PHASE-0.95 S95-F5):
 * form → raw advice, cap check per field (so the screen can point at the exact field), CSV template + CSV import parse.
 *
 * No WordPress calls here except through BizCity_Product_Advice::sanitize(); the admin class does I/O. Values are the
 * shop's DATA for the cell, never instructions.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\MCP\Admin
 * @since      PHASE-0.95 (2026-10-09)
 */

defined( 'ABSPATH' ) || exit;

// [2026-10-09 03:56 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.95-S95-F5 — new file, form/CSV logic for the product-advice@1 metaboxes.
final class BizCity_Product_Advice_Form {

	/** field => [max items, max chars per item] for lists; [0, max chars] for single strings. product-advice@1 caps (25-CORE-SPEC §1.3). */
	const PRODUCT_CAPS = array(
		'audience'      => array( 5, 40 ),
		'goals'         => array( 6, 40 ),
		'avoid_for'     => array( 6, 40 ),
		'key_questions' => array( 3, 120 ),
		'pitch'         => array( 0, 300 ),
		'compliance'    => array( 0, 200 ),
	);
	const CATEGORY_CAPS = array(
		'ask_first'     => array( 3, 120 ),
		'facets_to_ask' => array( 6, 40 ),
		'synonyms'      => array( 20, 60 ),
		'compliance'    => array( 0, 200 ),
	);

	/** Vietnamese label of each field (the notice names the field the owner sees on screen). */
	const LABELS = array(
		'tier'          => 'Hạng',
		'priority'      => 'Ưu tiên trong hạng',
		'audience'      => 'Dành cho',
		'age_min'       => 'Độ tuổi (từ)',
		'age_max'       => 'Độ tuổi (đến)',
		'goals'         => 'Giải quyết nhu cầu',
		'avoid_for'     => 'Không dùng cho',
		'key_questions' => 'Câu nên hỏi trước khi gợi ý',
		'pitch'         => 'Lời giới thiệu cho khách',
		'pair_with'     => 'Mua kèm',
		'next_tool'     => 'Việc tiếp theo',
		'compliance'    => 'Câu lưu ý',
		'ask_first'     => 'Câu nên hỏi khi khách nói tới nhóm này',
		'facets_to_ask' => 'Thuộc tính nên hỏi',
		'synonyms'      => 'Từ đồng nghĩa',
	);

	/** CSV advice columns (mockup ①) => product-advice@1 field. */
	const CSV_COLUMNS = array(
		'advice_audience'  => 'audience',
		'advice_goals'     => 'goals',
		'advice_avoid'     => 'avoid_for',
		'advice_questions' => 'key_questions',
		'advice_pitch'     => 'pitch',
		'advice_tier'      => 'tier',
		'advice_next'      => 'next_tool',
	);

	/** Columns of templates/product-catalog-sample.csv (bizcity-automation), kept so the template is the same file + advice. */
	const SAMPLE_COLUMNS = array( 'category', 'vendor', 'product_name', 'price_vnd', 'usp', 'comparison_product', 'comparison_angle', 'sales_channel', 'payment_terms', 'content_notes' );

	const TIER_WORDS = array(
		'strategic' => 'strategic', 'chien luoc' => 'strategic',
		'core'      => 'core', 'chu luc' => 'core',
		'clearance' => 'clearance', 'xa hang' => 'clearance',
		'normal'    => 'normal', 'thuong' => 'normal',
	);
	const NEXT_WORDS = array(
		'order' => 'order', 'dat hang' => 'order',
		'booking' => 'booking', 'dat lich' => 'booking',
		'brief' => 'brief', 'gui ban so sanh' => 'brief', 'so sanh' => 'brief',
		'contact staff' => 'contact_staff', 'chuyen nhan vien' => 'contact_staff', 'nhan vien' => 'contact_staff',
	);

	/* ── form ─────────────────────────────────────────────────────── */

	/**
	 * $_POST['bizcity_advice'] (product) → raw advice array (not yet sanitized). List fields arrive as one string with
	 * one item per line (the chip widget writes "\n"-joined values); question inputs arrive as an array.
	 */
	public static function from_product_form( array $p ): array {
		return array(
			'tier'          => self::str( $p['tier'] ?? '' ),
			'priority'      => self::str( $p['priority'] ?? '' ),
			'audience'      => self::lines( $p['audience'] ?? '' ),
			'age_min'       => self::str( $p['age_min'] ?? '' ),
			'age_max'       => self::str( $p['age_max'] ?? '' ),
			'age_unit'      => self::str( $p['age_unit'] ?? '' ),
			'goals'         => self::lines( $p['goals'] ?? '' ),
			'avoid_for'     => self::lines( $p['avoid_for'] ?? '' ),
			'key_questions' => self::lines( $p['key_questions'] ?? array() ),
			'pitch'         => self::str( $p['pitch'] ?? '' ),
			'pair_with'     => self::id_list( $p['pair_with'] ?? '' ),
			'next_tool'     => self::str( $p['next_tool'] ?? '' ),
			'compliance'    => self::str( $p['compliance'] ?? '' ),
		);
	}

	public static function from_category_form( array $p ): array {
		return array(
			'ask_first'     => self::lines( $p['ask_first'] ?? array() ),
			'facets_to_ask' => self::lines( $p['facets_to_ask'] ?? '' ),
			'synonyms'      => self::lines( $p['synonyms'] ?? '' ),
			'compliance'    => self::str( $p['compliance'] ?? '' ),
		);
	}

	/**
	 * Fields over a cap or out of range ⇒ [field => Vietnamese message]. Empty = OK. sanitize() would silently cut; the
	 * screen refuses instead so the owner never loses text without seeing which field.
	 */
	public static function cap_errors( array $raw, bool $category = false ): array {
		$errors = array();
		foreach ( $category ? self::CATEGORY_CAPS : self::PRODUCT_CAPS as $field => $cap ) {
			if ( ! array_key_exists( $field, $raw ) ) {
				continue;
			}
			list( $max_items, $max_len ) = $cap;
			if ( 0 === $max_items ) {
				if ( self::len( (string) $raw[ $field ] ) > $max_len ) {
					$errors[ $field ] = sprintf( 'tối đa %d ký tự (đang có %d).', $max_len, self::len( (string) $raw[ $field ] ) );
				}
				continue;
			}
			$items = is_array( $raw[ $field ] ) ? $raw[ $field ] : array();
			if ( count( $items ) > $max_items ) {
				$errors[ $field ] = sprintf( 'tối đa %d mục (đang có %d).', $max_items, count( $items ) );
				continue;
			}
			foreach ( $items as $item ) {
				if ( self::len( (string) $item ) > $max_len ) {
					$errors[ $field ] = sprintf( 'mỗi mục tối đa %d ký tự ("%s…" dài %d).', $max_len, self::cut( (string) $item, 20 ), self::len( (string) $item ) );
					break;
				}
			}
		}
		if ( $category ) {
			return $errors;
		}
		if ( isset( $raw['priority'] ) && '' !== (string) $raw['priority'] && ( ! is_numeric( $raw['priority'] ) || $raw['priority'] < 0 || $raw['priority'] > 100 ) ) {
			$errors['priority'] = 'phải là số từ 0 đến 100.';
		}
		foreach ( array( 'age_min', 'age_max' ) as $f ) {
			if ( isset( $raw[ $f ] ) && '' !== (string) $raw[ $f ] && ( ! is_numeric( $raw[ $f ] ) || $raw[ $f ] < 0 ) ) {
				$errors[ $f ] = 'phải là số không âm.';
			}
		}
		if ( ! isset( $errors['age_min'], $errors['age_max'] ) && isset( $raw['age_min'], $raw['age_max'] )
			&& is_numeric( $raw['age_min'] ) && is_numeric( $raw['age_max'] ) && (float) $raw['age_min'] > (float) $raw['age_max'] ) {
			$errors['age_max'] = 'phải lớn hơn hoặc bằng tuổi "từ".';
		}
		if ( isset( $raw['pair_with'] ) && is_array( $raw['pair_with'] ) && count( $raw['pair_with'] ) > 3 ) {
			$errors['pair_with'] = sprintf( 'tối đa 3 sản phẩm (đang có %d).', count( $raw['pair_with'] ) );
		}
		if ( isset( $raw['tier'] ) && '' !== $raw['tier'] && ! in_array( $raw['tier'], BizCity_Product_Advice::TIERS, true ) ) {
			$errors['tier'] = 'giá trị không hợp lệ.';
		}
		if ( isset( $raw['next_tool'] ) && '' !== $raw['next_tool'] && ! in_array( $raw['next_tool'], BizCity_Product_Advice::NEXT, true ) ) {
			$errors['next_tool'] = 'giá trị không hợp lệ.';
		}
		return $errors;
	}

	/** True when the sanitized advice carries nothing the owner typed (⇒ delete the meta instead of storing defaults). */
	public static function is_blank( array $clean, bool $category = false ): bool {
		$blank = $category ? BizCity_Product_Advice::sanitize_category( array() ) : BizCity_Product_Advice::sanitize( array() );
		return $clean === $blank;
	}

	/* ── CSV ──────────────────────────────────────────────────────── */

	/** Header of the downloadable template: matching keys + the sample's columns + the advice columns. */
	public static function template_header(): array {
		return array_merge( array( 'product_id', 'sku' ), self::SAMPLE_COLUMNS, array_keys( self::CSV_COLUMNS ) );
	}

	/** One template row from a product + its current advice (lists joined by " | "). */
	public static function template_row( array $product, ?array $advice ): array {
		$row = array_fill_keys( self::template_header(), '' );
		$row['product_id']   = (string) ( $product['id'] ?? '' );
		$row['sku']          = (string) ( $product['sku'] ?? '' );
		$row['product_name'] = (string) ( $product['name'] ?? '' );
		$row['category']     = (string) ( $product['category'] ?? '' );
		$row['price_vnd']    = (string) ( $product['price'] ?? '' );
		if ( $advice ) {
			foreach ( self::CSV_COLUMNS as $col => $field ) {
				$v            = $advice[ $field ] ?? '';
				$row[ $col ]  = is_array( $v ) ? implode( ' | ', $v ) : (string) $v;
			}
		}
		return array_values( $row );
	}

	/**
	 * Parse CSV text ⇒ { rows: [ {line, match:{product_id?, sku?, product_name?}, advice:{field: value}} ], errors: [ {line, column, message} ] }.
	 * Only non-empty advice cells are returned (empty = keep what the product has). A row with any bad advice cell is
	 * reported (per column) and skipped; good rows still import. Rows without any advice cell are ignored silently.
	 */
	public static function parse_csv( string $csv ): array {
		$csv = preg_replace( '/^\xEF\xBB\xBF/', '', $csv );
		$out = array( 'rows' => array(), 'errors' => array() );
		$fh  = fopen( 'php://temp', 'r+' );
		fwrite( $fh, (string) $csv );
		rewind( $fh );
		$first = fgets( $fh );
		if ( false === $first ) {
			fclose( $fh );
			$out['errors'][] = array( 'line' => 1, 'column' => '', 'message' => 'Tệp CSV trống.' );
			return $out;
		}
		$delim = substr_count( $first, ';' ) > substr_count( $first, ',' ) ? ';' : ',';
		rewind( $fh );
		$header = fgetcsv( $fh, 0, $delim );
		$header = array_map( static function ( $h ) { return strtolower( trim( (string) $h ) ); }, is_array( $header ) ? $header : array() );
		$has_key    = array_intersect( array( 'product_id', 'sku', 'product_name' ), $header );
		$has_advice = array_intersect( array_keys( self::CSV_COLUMNS ), $header );
		if ( ! $has_key || ! $has_advice ) {
			fclose( $fh );
			$out['errors'][] = array( 'line' => 1, 'column' => '', 'message' => 'Thiếu cột: cần product_id, sku hoặc product_name và ít nhất một cột advice_*.' );
			return $out;
		}
		$line = 1;
		while ( ( $cells = fgetcsv( $fh, 0, $delim ) ) !== false ) {
			$line++;
			if ( array( null ) === $cells || ( 1 === count( $cells ) && '' === trim( (string) $cells[0] ) ) ) {
				continue;
			}
			$r = array();
			foreach ( $header as $i => $h ) {
				$r[ $h ] = trim( (string) ( $cells[ $i ] ?? '' ) );
			}
			$match = array();
			foreach ( array( 'product_id', 'sku', 'product_name' ) as $k ) {
				if ( '' !== ( $r[ $k ] ?? '' ) ) {
					$match[ $k ] = $r[ $k ];
				}
			}
			$advice = array();
			$errs   = array();
			foreach ( self::CSV_COLUMNS as $col => $field ) {
				$cell = $r[ $col ] ?? '';
				if ( '' === $cell ) {
					continue;
				}
				if ( 'tier' === $field || 'next_tool' === $field ) {
					$map = 'tier' === $field ? self::TIER_WORDS : self::NEXT_WORDS;
					$key = self::fold( $cell );
					if ( ! isset( $map[ $key ] ) ) {
						$errs[] = array( 'line' => $line, 'column' => $col, 'message' => sprintf( 'giá trị "%s" không hợp lệ (dùng: %s).', self::cut( $cell, 30 ), implode( ', ', array_keys( array_flip( $map ) ) ) ) );
						continue;
					}
					$advice[ $field ] = $map[ $key ];
				} elseif ( isset( self::PRODUCT_CAPS[ $field ] ) && self::PRODUCT_CAPS[ $field ][0] > 0 ) {
					$advice[ $field ] = self::split_list( $cell );
				} else {
					$advice[ $field ] = $cell;
				}
			}
			if ( ! $advice && ! $errs ) {
				continue;
			}
			foreach ( self::cap_errors( $advice ) as $field => $msg ) {
				$errs[] = array( 'line' => $line, 'column' => (string) array_search( $field, self::CSV_COLUMNS, true ), 'message' => $msg );
			}
			if ( ! $match ) {
				$errs[] = array( 'line' => $line, 'column' => 'product_id', 'message' => 'không có product_id, sku hay product_name để tìm sản phẩm.' );
			}
			if ( $errs ) {
				$out['errors'] = array_merge( $out['errors'], $errs );
				continue;
			}
			$out['rows'][] = array( 'line' => $line, 'match' => $match, 'advice' => $advice );
		}
		fclose( $fh );
		return $out;
	}

	/** Existing advice (or null) + CSV cells ⇒ the value to sanitize and store (cells override, empty cells kept out). */
	public static function merge( ?array $existing, array $cells ): array {
		return array_merge( $existing ? $existing : array(), $cells );
	}

	/* ── helpers ──────────────────────────────────────────────────── */

	private static function str( $v ): string {
		return is_scalar( $v ) ? trim( (string) $v ) : '';
	}

	/** One item per line (or an array of inputs); blanks dropped. Not de-duplicated: sanitize() does that. */
	public static function lines( $v ): array {
		$parts = is_array( $v ) ? $v : preg_split( '/\r\n|\r|\n/', (string) $v );
		$out   = array();
		foreach ( $parts as $p ) {
			$p = is_scalar( $p ) ? trim( (string) $p ) : '';
			if ( '' !== $p ) {
				$out[] = $p;
			}
		}
		return $out;
	}

	/** "#587 Bình pha sữa, 612" ⇒ [587, 612]: every number that starts an item. */
	public static function id_list( $v ): array {
		$out = array();
		foreach ( preg_split( '/[,;\n|]+/', is_array( $v ) ? implode( ',', $v ) : (string) $v ) as $part ) {
			if ( preg_match( '/^\s*#?(\d+)/', $part, $m ) && (int) $m[1] > 0 ) {
				$out[] = (int) $m[1];
			}
		}
		return array_values( array_unique( $out ) );
	}

	/** CSV list cell: items separated by "|" (or ";" / new line). */
	public static function split_list( string $cell ): array {
		return self::lines( preg_split( '/\s*[|;\n]\s*/', $cell ) );
	}

	/** lower-case, accents removed, single spaces — for tier/next words typed in Vietnamese. */
	private static function fold( string $s ): string {
		$s = function_exists( 'remove_accents' ) ? remove_accents( $s ) : self::strip_vi( $s );
		return trim( preg_replace( '/\s+/', ' ', strtolower( str_replace( '_', ' ', $s ) ) ) );
	}

	private static function strip_vi( string $s ): string {
		$map = array(
			'a' => 'àáạảãâầấậẩẫăằắặẳẵ', 'e' => 'èéẹẻẽêềếệểễ', 'i' => 'ìíịỉĩ', 'o' => 'òóọỏõôồốộổỗơờớợởỡ',
			'u' => 'ùúụủũưừứựửữ', 'y' => 'ỳýỵỷỹ', 'd' => 'đ',
			'A' => 'ÀÁẠẢÃÂẦẤẬẨẪĂẰẮẶẲẴ', 'E' => 'ÈÉẸẺẼÊỀẾỆỂỄ', 'I' => 'ÌÍỊỈĨ', 'O' => 'ÒÓỌỎÕÔỒỐỘỔỖƠỜỚỢỞỠ',
			'U' => 'ÙÚỤỦŨƯỪỨỰỬỮ', 'Y' => 'ỲÝỴỶỸ', 'D' => 'Đ',
		);
		foreach ( $map as $plain => $chars ) {
			$s = str_replace( preg_split( '//u', $chars, -1, PREG_SPLIT_NO_EMPTY ), $plain, $s );
		}
		return $s;
	}

	private static function len( string $s ): int {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $s, 'UTF-8' ) : strlen( $s );
	}

	private static function cut( string $s, int $n ): string {
		return function_exists( 'mb_substr' ) ? mb_substr( $s, 0, $n, 'UTF-8' ) : substr( $s, 0, $n );
	}
}
