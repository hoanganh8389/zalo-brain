<?php
/**
 * BizCity_Twin_Sheet_Builder — turns what a person asked for ("Sổ học tiếng Anh: ngày, bài học, điểm") into a Twin Sheet definition. Pure.
 *
 * The person (or the assistant on their behalf) gives only labels and simple types; the builder owns everything technical: stable ASCII
 * keys, which columns can be filtered / sorted / grouped / summed, the view, the headline number, the edit policy. Money anywhere ⇒ the
 * sheet is a money book (finance-sensitive, `void_reenter`: R-JN-11, R-JN-25).
 *
 * Biz Central Brain — Johnny Chu (Chu Hoàng Anh).
 *
 * [2026-10-19 11:45 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.123 W3 (B11) — new file.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\KG_Hub\Twin_Sheet
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Twin_Sheet_Builder', false ) ) {
	return;
}

final class BizCity_Twin_Sheet_Builder {

	/** types a person may choose (decimal / ref_code / datetime stay out of the first release: nothing sums them exactly) */
	const TYPES = array( 'text', 'long_text', 'integer', 'money', 'boolean', 'date', 'select', 'percent' );

	const MAX_USER_FIELDS = 28; // + the date column and the code = 30
	const MAX_SHEETS      = 50;
	const MAX_OPTIONS     = 20;
	const MONEY_MAX       = 100000000000; // 10^11 smallest units for a self-made money column

	/** keys the system owns (index columns / computed) — a person's column of that name gets a suffix */
	const RESERVED = array( 'doc_date', 'ref_code', 'title', 'summary', 'state', 'tags', 'created_at', 'updated_at', 'status', 'version', 'reverses' );

	const ICONS = array( 'sheet', 'cash', 'debt', 'plan', 'progress', 'todo', 'contract', 'book', 'people', 'box' );

	/** Vietnamese letters → ASCII (the key is for machines; the label keeps the accents) */
	private static function ascii( string $s ): string {
		$s = function_exists( 'mb_strtolower' ) ? mb_strtolower( $s, 'UTF-8' ) : strtolower( $s );
		$map = array(
			'à' => 'a', 'á' => 'a', 'ả' => 'a', 'ã' => 'a', 'ạ' => 'a', 'ă' => 'a', 'ằ' => 'a', 'ắ' => 'a', 'ẳ' => 'a', 'ẵ' => 'a', 'ặ' => 'a', 'â' => 'a', 'ầ' => 'a', 'ấ' => 'a', 'ẩ' => 'a', 'ẫ' => 'a', 'ậ' => 'a',
			'è' => 'e', 'é' => 'e', 'ẻ' => 'e', 'ẽ' => 'e', 'ẹ' => 'e', 'ê' => 'e', 'ề' => 'e', 'ế' => 'e', 'ể' => 'e', 'ễ' => 'e', 'ệ' => 'e',
			'ì' => 'i', 'í' => 'i', 'ỉ' => 'i', 'ĩ' => 'i', 'ị' => 'i',
			'ò' => 'o', 'ó' => 'o', 'ỏ' => 'o', 'õ' => 'o', 'ọ' => 'o', 'ô' => 'o', 'ồ' => 'o', 'ố' => 'o', 'ổ' => 'o', 'ỗ' => 'o', 'ộ' => 'o', 'ơ' => 'o', 'ờ' => 'o', 'ớ' => 'o', 'ở' => 'o', 'ỡ' => 'o', 'ợ' => 'o',
			'ù' => 'u', 'ú' => 'u', 'ủ' => 'u', 'ũ' => 'u', 'ụ' => 'u', 'ư' => 'u', 'ừ' => 'u', 'ứ' => 'u', 'ử' => 'u', 'ữ' => 'u', 'ự' => 'u',
			'ỳ' => 'y', 'ý' => 'y', 'ỷ' => 'y', 'ỹ' => 'y', 'ỵ' => 'y', 'đ' => 'd',
		);
		return strtr( $s, $map );
	}

	/** "Điểm số bài học" → "diem_so_bai_hoc" (a-z0-9_, starts with a letter, ≤ 28) */
	public static function slug( string $label ): string {
		$s = (string) preg_replace( '/[^a-z0-9]+/', '_', self::ascii( $label ) );
		$s = trim( $s, '_' );
		if ( '' === $s ) {
			$s = 'cot';
		}
		if ( ! preg_match( '/^[a-z]/', $s ) ) {
			$s = 'c_' . $s;
		}
		return substr( $s, 0, 28 );
	}

	public static function unique_key( string $base, array &$used ): string { // [2026-10-20 06:00 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.123 W3 — public: adding a column later needs the same key rule
		$key = $base;
		$n   = 2;
		while ( isset( $used[ $key ] ) || in_array( $key, self::RESERVED, true ) ) {
			$key = substr( $base, 0, 26 ) . '_' . $n;
			$n++;
		}
		$used[ $key ] = true;
		return $key;
	}

	/**
	 * @param array $input {display_name, fields:[{label, type, options?:string[]|[{label}], required?}], icon?, folder?}
	 * @return array|WP_Error definition ready for the twin_sheet post: {display_name, icon, folder, edit_policy, sensitive, fields, views, headline, title_rule, schema_version}
	 */
	public static function build( array $input ) {
		$name = BizCity_Twin_Sheet_Schema::clean_text( (string) ( $input['display_name'] ?? '' ), 80 );
		if ( '' === $name ) {
			return BizCity_Twin_Sheet_Schema::error( 'sheet_invalid', 'Sổ cần có tên.', 'Đặt một cái tên, ví dụ “Sổ học tiếng Anh”.', 422 );
		}
		$raw = array_values( array_filter( (array) ( $input['fields'] ?? array() ), 'is_array' ) );
		if ( array() === $raw ) {
			return BizCity_Twin_Sheet_Schema::error( 'sheet_invalid', 'Sổ cần ít nhất một cột.', 'Thêm cột, ví dụ “Bài học” (chữ) và “Điểm” (số).', 422 );
		}
		if ( count( $raw ) > self::MAX_USER_FIELDS ) {
			return BizCity_Twin_Sheet_Schema::error( 'sheet_invalid', 'Một sổ tối đa ' . self::MAX_USER_FIELDS . ' cột.', 'Bớt cột hoặc tách thành hai sổ.', 422 );
		}
		$used   = array();
		$fields = array( array( 'key' => 'doc_date', 'label' => 'Ngày', 'type' => 'date', 'source' => 'column', 'filter' => true, 'sort' => true ) );
		$used['doc_date'] = true;
		$has_money = false;
		$first_text = '';
		$money_key  = '';
		foreach ( $raw as $i => $f ) {
			$label = BizCity_Twin_Sheet_Schema::clean_text( (string) ( $f['label'] ?? '' ), 60 );
			$type  = (string) ( $f['type'] ?? 'text' );
			if ( '' === $label ) {
				return BizCity_Twin_Sheet_Schema::error( 'sheet_invalid', 'Cột số ' . ( $i + 1 ) . ' chưa có tên.', 'Đặt tên cho cột hoặc bỏ cột này.', 422 );
			}
			if ( ! in_array( $type, self::TYPES, true ) ) {
				return BizCity_Twin_Sheet_Schema::error( 'sheet_invalid', 'Cột “' . $label . '” có kiểu không hỗ trợ.', 'Chọn chữ, số, tiền, ngày, chọn một, có/không hoặc phần trăm.', 422 );
			}
			$key   = self::unique_key( self::slug( $label ), $used );
			$field = array( 'key' => $key, 'label' => $label, 'type' => $type, 'required' => ! empty( $f['required'] ) );
			if ( 'text' === $type || 'long_text' === $type ) {
				$field['search'] = true;
				if ( 'text' === $type && '' === $first_text ) {
					$first_text = $key;
				}
			} elseif ( 'money' === $type ) {
				$has_money = true;
				$field += array( 'min' => 0, 'max' => self::MONEY_MAX, 'sort' => true, 'aggregate' => array( 'sum', 'avg', 'min', 'max' ) );
				$money_key = '' === $money_key ? $key : $money_key;
			} elseif ( 'integer' === $type ) {
				$field += array( 'sort' => true, 'aggregate' => array( 'sum', 'avg', 'min', 'max' ) );
			} elseif ( 'percent' === $type ) {
				$field += array( 'sort' => true, 'aggregate' => array( 'avg', 'min', 'max' ) );
			} elseif ( 'date' === $type ) {
				$field += array( 'filter' => true, 'sort' => true );
			} elseif ( 'boolean' === $type ) {
				$field += array( 'filter' => true, 'group' => true );
			} elseif ( 'select' === $type ) {
				$opts = array();
				$seen = array();
				foreach ( array_slice( (array) ( $f['options'] ?? array() ), 0, self::MAX_OPTIONS + 1 ) as $o ) {
					$ol = BizCity_Twin_Sheet_Schema::clean_text( is_array( $o ) ? (string) ( $o['label'] ?? '' ) : (string) $o, 60 );
					if ( '' === $ol ) {
						continue;
					}
					$ov = self::slug( $ol );
					$n  = 2;
					while ( isset( $seen[ $ov ] ) ) {
						$ov = substr( self::slug( $ol ), 0, 26 ) . '_' . $n++;
					}
					$seen[ $ov ] = true;
					$opts[]      = array( 'v' => $ov, 'label' => $ol );
				}
				if ( count( $opts ) < 2 || count( $opts ) > self::MAX_OPTIONS ) {
					return BizCity_Twin_Sheet_Schema::error( 'sheet_invalid', 'Cột “' . $label . '” (chọn một) cần từ 2 đến ' . self::MAX_OPTIONS . ' lựa chọn.', 'Liệt kê các lựa chọn, ví dụ: Mới, Đang làm, Xong.', 422 );
				}
				$field += array( 'options' => $opts, 'filter' => true, 'group' => true );
			}
			$fields[] = $field;
		}
		$fields[] = array( 'key' => 'ref_code', 'label' => 'Mã', 'type' => 'ref_code', 'source' => 'computed' );

		$icon = (string) ( $input['icon'] ?? '' );
		$def  = array(
			'display_name'   => $name,
			'icon'           => in_array( $icon, self::ICONS, true ) ? $icon : ( $has_money ? 'cash' : 'sheet' ),
			'folder'         => BizCity_Twin_Sheet_Schema::clean_text( (string) ( $input['folder'] ?? '' ), 60 ),
			'edit_policy'    => $has_money ? 'void_reenter' : 'rev',
			'sensitive'      => $has_money ? 'finance' : '',
			'schema_version' => 1,
			'fields'         => $fields,
			'title_rule'     => ! $has_money && '' !== $first_text ? 'card:' . $first_text : '',
			'views'          => array( array(
				'key'    => 'all',
				'label'  => 'Tất cả',
				'period' => 'all',
				'sort'   => array( array( 'field' => 'doc_date', 'dir' => 'desc' ) ),
				'totals' => '' !== $money_key ? array( array( 'field' => $money_key, 'fn' => 'sum' ) ) : array(),
			) ),
			'headline'       => '' !== $money_key
				? array( 'field' => $money_key, 'fn' => 'sum', 'period' => 'all', 'label' => 'Tổng ' . mb_strtolower( $fields[ array_search( $money_key, array_column( $fields, 'key' ), true ) ]['label'], 'UTF-8' ) )
				: array( 'field' => '', 'fn' => 'count', 'period' => 'all', 'label' => 'Số dòng' ),
		);
		return $def;
	}
}
