<?php
/**
 * BizCity_SelfWP_Page_Compiler — `page-spec@1` → Flatsome (UX Builder) shortcodes or Gutenberg blocks, and `form-spec@1` → a
 * Contact Form 7 template. PURE: no WordPress call, so the unit tests run without WordPress (PHASE-0.118 doc 30).
 *
 * The cell never sends HTML or shortcodes: it sends a small closed JSON (`page-spec@1`). Everything a person typed is
 * stripped of tags, length-capped, and has `[` `]` turned into entities BEFORE it is placed in a shortcode, so a title like
 * "[ux_html]…" can never open a shortcode. Link targets are whitelisted (#anchor, tel:, https?://). Section ids are
 * [a-z0-9_-] only because Flatsome prints `_id` into the HTML unescaped (themes/flatsome/inc/shortcodes/sections.php:141).
 * There is no `html` / `script` / `iframe` section and the compiler never emits `[ux_html]`.
 *
 * Shortcode attributes were read from themes/flatsome/inc/shortcodes/{sections,row,buttons,titles_dividers,ux_banner,text_box,ux_text}.php.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\MCP
 * @since      2026-10-16 (PHASE-0.118 S118-L2/L3)
 */

defined( 'ABSPATH' ) || exit;

// [2026-10-16 10:40 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.118 S118-L2/L3 — new file, page-spec@1 compiler (Flatsome + Gutenberg) and form-spec@1 → CF7 template.
final class BizCity_SelfWP_Page_Compiler {

	const CONTRACT      = 'page-spec@1';
	const FORM_CONTRACT = 'form-spec@1';

	const MAX_SECTIONS = 12;
	const MAX_ITEMS    = 8;
	const MAX_FIELDS   = 8;
	const TYPES        = array( 'hero', 'benefits', 'text', 'form', 'cta' );
	const FIELD_TYPES  = array( 'text', 'email', 'tel', 'textarea' );

	/**
	 * [2026-10-18 Johnny Chu - Chu Hoàng Anh] PHASE-0.118 (client MCP trước) — the JSON Schema of a page-spec@1, built from the SAME constants and limits as validate(), so a client such as
	 * Claude Code sees the real shape (section types, required fields, lengths) in tools/list instead of a bare "object" and has to guess. Advertising only: validate() stays the judge
	 * (it normalises and reports every error with its path); nothing here rejects a call. A limit changed in validate() must be changed here - the unit test builds a spec from this
	 * schema's own examples and refuses to drift.
	 */
	public static function json_schema(): array {
		$s = static function ( int $max, int $min = 0 ): array {
			return array( 'type' => 'string', 'maxLength' => $max ) + ( $min > 0 ? array( 'minLength' => $min ) : array() );
		};
		$cta = array( 'type' => 'object', 'description' => 'Nút: label tối đa 30 ký tự; target là #anchor, tel:+số hoặc https://… (khác ⇒ "#").', 'properties' => array( 'label' => $s( 30 ), 'target' => array( 'type' => 'string', 'maxLength' => 300 ) ) );
		return array(
			'type'        => 'object',
			'description' => 'page-spec@1: một trang landing. Chỉ dùng các loại phần đã liệt kê; chữ thường, không HTML (thẻ bị bỏ). Tối đa ' . self::MAX_SECTIONS . ' phần, tối đa một phần "form". Muốn có form: tạo trước bằng cf7.form.create rồi dùng form_ref "cf7:<form_id>". Ảnh: chỉ "media:<id>" của thư viện.',
			'required'    => array( 'title', 'sections' ),
			'properties'  => array(
				'title'    => $s( 120, 1 ) + array( 'description' => 'Tiêu đề trang (bắt buộc).' ),
				'slug'     => array( 'type' => 'string', 'maxLength' => 60, 'pattern' => '^[a-z0-9]+(?:-[a-z0-9]+)*$', 'description' => 'Đường dẫn: chữ thường, số, gạch ngang.' ),
				'kind'     => array( 'type' => 'string', 'enum' => array( 'landing' ) ),
				'seo'      => array( 'type' => 'object', 'properties' => array( 'meta_description' => $s( 160 ) ) ),
				'sections' => array(
					'type'     => 'array',
					'minItems' => 1,
					'maxItems' => self::MAX_SECTIONS,
					'items'    => array( 'oneOf' => array(
						array( 'type' => 'object', 'required' => array( 'type', 'headline' ), 'properties' => array(
							'type' => array( 'const' => 'hero' ), 'headline' => $s( 120, 1 ), 'sub' => $s( 240 ),
							'image_ref' => array( 'type' => 'string', 'pattern' => '^media:[1-9][0-9]{0,9}$' ), 'cta' => $cta ) ),
						array( 'type' => 'object', 'required' => array( 'type', 'items' ), 'properties' => array(
							'type' => array( 'const' => 'benefits' ),
							'items' => array( 'type' => 'array', 'minItems' => 1, 'maxItems' => self::MAX_ITEMS, 'items' => array( 'type' => 'object', 'required' => array( 'title' ), 'properties' => array( 'title' => $s( 60, 1 ), 'text' => $s( 200 ) ) ) ) ) ),
						array( 'type' => 'object', 'required' => array( 'type', 'body' ), 'properties' => array(
							'type' => array( 'const' => 'text' ), 'title' => $s( 80 ), 'body' => $s( 1500, 1 ) ) ),
						array( 'type' => 'object', 'required' => array( 'type', 'form_ref' ), 'properties' => array(
							'type' => array( 'const' => 'form' ), 'form_ref' => array( 'type' => 'string', 'pattern' => '^cf7:[1-9][0-9]{0,9}$' ), 'title' => $s( 80 ), 'anchor' => array( 'type' => 'string', 'pattern' => '^[a-z0-9_-]{1,30}$', 'default' => 'form' ) ) ),
						array( 'type' => 'object', 'required' => array( 'type', 'headline' ), 'properties' => array(
							'type' => array( 'const' => 'cta' ), 'headline' => $s( 120, 1 ),
							'button' => array( 'type' => 'object', 'properties' => array( 'label' => $s( 30, 1 ), 'tel' => array( 'type' => 'string', 'description' => 'Số điện thoại 8–15 chữ số, có thể có +.' ), 'target' => array( 'type' => 'string', 'maxLength' => 300 ) ) ) ) ),
					) ),
				),
			),
		);
	}

	/**
	 * Validate and normalise a page-spec. Never throws.
	 *
	 * @param mixed $spec
	 * @return array{ok:bool,spec:array,errors:array<int,array{path:string,code:string,message:string}>}
	 */
	public static function validate( $spec ): array {
		$errors = array();
		$out    = array( 'kind' => 'landing', 'sections' => array() );
		if ( ! is_array( $spec ) ) {
			return self::fail( array( self::err( 'spec', 'spec_invalid', 'Bản mô tả trang (spec) phải là một đối tượng.' ) ) );
		}
		$out['title'] = self::clean( $spec['title'] ?? '', 120, 'title', true, $errors );
		if ( isset( $spec['slug'] ) && '' !== (string) $spec['slug'] ) {
			if ( ! preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', (string) $spec['slug'] ) || strlen( (string) $spec['slug'] ) > 60 ) {
				$errors[] = self::err( 'slug', 'slug_invalid', 'Đường dẫn (slug) chỉ gồm chữ thường, số và dấu gạch ngang, tối đa 60 ký tự.' );
			} else {
				$out['slug'] = (string) $spec['slug'];
			}
		}
		if ( isset( $spec['kind'] ) && 'landing' !== $spec['kind'] ) {
			$errors[] = self::err( 'kind', 'kind_unsupported', 'Chỉ hỗ trợ kind "landing".' );
		}
		if ( isset( $spec['seo']['meta_description'] ) ) {
			$out['seo'] = array( 'meta_description' => self::clean( $spec['seo']['meta_description'], 160, 'seo.meta_description', false, $errors ) );
		}
		$sections = $spec['sections'] ?? null;
		if ( ! is_array( $sections ) || array() === $sections || array_keys( $sections ) !== range( 0, count( $sections ) - 1 ) ) {
			$errors[] = self::err( 'sections', 'sections_invalid', 'Cần ít nhất một phần (sections) dạng danh sách.' );
			return self::result( $out, $errors );
		}
		if ( count( $sections ) > self::MAX_SECTIONS ) {
			$errors[] = self::err( 'sections', 'too_many_sections', 'Tối đa ' . self::MAX_SECTIONS . ' phần.' );
			return self::result( $out, $errors );
		}
		$seen_form = false;
		foreach ( $sections as $i => $s ) {
			$p = 'sections[' . $i . ']';
			if ( ! is_array( $s ) ) {
				$errors[] = self::err( $p, 'section_invalid', 'Mỗi phần phải là một đối tượng.' );
				continue;
			}
			$type = (string) ( $s['type'] ?? '' );
			if ( ! in_array( $type, self::TYPES, true ) ) {
				$errors[] = self::err( $p . '.type', 'unknown_section', 'Loại phần "' . self::safe_echo( $type ) . '" chưa được hỗ trợ.' );
				continue;
			}
			$n = array( 'type' => $type );
			switch ( $type ) {
				case 'hero':
					$n['headline'] = self::clean( $s['headline'] ?? '', 120, $p . '.headline', true, $errors );
					$n['sub']      = self::clean( $s['sub'] ?? '', 240, $p . '.sub', false, $errors );
					if ( isset( $s['image_ref'] ) && '' !== (string) $s['image_ref'] ) {
						if ( ! preg_match( '/^media:([1-9][0-9]{0,9})$/', (string) $s['image_ref'] ) ) {
							$errors[] = self::err( $p . '.image_ref', 'image_ref_invalid', 'Ảnh phải ở dạng media:<id> của thư viện.' );
						} else {
							$n['image_ref'] = (string) $s['image_ref'];
						}
					}
					$n['cta'] = self::cta( $s['cta'] ?? null, $p . '.cta', $errors );
					break;
				case 'benefits':
					$items = $s['items'] ?? null;
					if ( ! is_array( $items ) || array() === $items || count( $items ) > self::MAX_ITEMS ) {
						$errors[] = self::err( $p . '.items', 'items_invalid', 'Cần từ 1 đến ' . self::MAX_ITEMS . ' ý lợi ích.' );
						break;
					}
					$n['items'] = array();
					foreach ( array_values( $items ) as $j => $it ) {
						$n['items'][] = array(
							'title' => self::clean( is_array( $it ) ? ( $it['title'] ?? '' ) : '', 60, $p . '.items[' . $j . '].title', true, $errors ),
							'text'  => self::clean( is_array( $it ) ? ( $it['text'] ?? '' ) : '', 200, $p . '.items[' . $j . '].text', false, $errors ),
						);
					}
					break;
				case 'text':
					$n['title'] = self::clean( $s['title'] ?? '', 80, $p . '.title', false, $errors );
					$n['body']  = self::clean( $s['body'] ?? '', 1500, $p . '.body', true, $errors, true );
					break;
				case 'form':
					if ( ! preg_match( '/^cf7:([1-9][0-9]{0,9})$/', (string) ( $s['form_ref'] ?? '' ) ) ) {
						$errors[] = self::err( $p . '.form_ref', 'form_ref_invalid', 'Form phải ở dạng cf7:<id> của một form đã có.' );
						break;
					}
					if ( $seen_form ) {
						$errors[] = self::err( $p, 'duplicate_form', 'Mỗi trang chỉ có một phần form.' );
						break;
					}
					$seen_form       = true;
					$n['form_ref']   = (string) $s['form_ref'];
					$n['title']      = self::clean( $s['title'] ?? '', 80, $p . '.title', false, $errors );
					$anchor          = (string) ( $s['anchor'] ?? 'form' );
					$n['anchor']     = preg_match( '/^[a-z0-9_-]{1,30}$/', $anchor ) ? $anchor : 'form';
					break;
				case 'cta':
					$n['headline'] = self::clean( $s['headline'] ?? '', 120, $p . '.headline', true, $errors );
					$b             = is_array( $s['button'] ?? null ) ? $s['button'] : array();
					$n['button']   = array(
						'label' => self::clean( $b['label'] ?? 'Liên hệ ngay', 30, $p . '.button.label', true, $errors ),
						'tel'   => self::phone( $b['tel'] ?? '' ),
						'target' => self::link( $b['target'] ?? '' ),
					);
					break;
			}
			$out['sections'][] = $n;
		}
		return self::result( $out, $errors );
	}

	const MAX_OPS = 10;
	/** trường được phép sửa của từng loại section (loại section KHÔNG đổi được: muốn đổi loại thì xoá rồi thêm) */
	const EDITABLE = array(
		'hero'     => array( 'headline', 'sub', 'image_ref', 'cta' ),
		'benefits' => array( 'items' ),
		'text'     => array( 'title', 'body' ),
		'form'     => array( 'title', 'form_ref' ),
		'cta'      => array( 'headline', 'button' ),
	);

	/**
	 * [2026-10-17 02:15 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.118 S118-L5 — sửa MỘT bản mô tả trang đã chuẩn hoá bằng danh sách thao tác theo từng phần; thuần hàm (không gọi WordPress). Chỉ trả spec MỚI chưa kiểm hợp lệ: người gọi chạy
	 * {@see self::validate()} lên kết quả để chuẩn hoá và chặn nội dung xấu. Chỉ số trong `ops` tính trên danh sách ở thời điểm thao tác đó (các thao tác chạy lần lượt).
	 *
	 * @param array $ops [{op:'set_title',value}|{op:'update',index,fields}|{op:'add',section,after?}|{op:'remove',index}]
	 * @return array{ok:bool,spec:array,errors:array,changes:string[]}
	 */
	public static function apply_ops( array $spec, array $ops ): array {
		$errors   = array();
		$changes  = array();
		$title    = (string) ( $spec['title'] ?? '' );
		$sections = array_values( (array) ( $spec['sections'] ?? array() ) );
		if ( array() === $ops || count( $ops ) > self::MAX_OPS ) {
			$errors[] = self::err( 'ops', 'ops_invalid', 'Cần từ 1 đến ' . self::MAX_OPS . ' thao tác.' );
			return array( 'ok' => false, 'spec' => $spec, 'errors' => $errors, 'changes' => array() );
		}
		foreach ( array_values( $ops ) as $i => $op ) {
			$p    = 'ops[' . $i . ']';
			$op   = is_array( $op ) ? $op : array();
			$kind = (string) ( $op['op'] ?? '' );
			if ( 'set_title' === $kind ) {
				if ( ! is_string( $op['value'] ?? null ) || '' === trim( (string) $op['value'] ) ) {
					$errors[] = self::err( $p . '.value', 'required', 'Thiếu tiêu đề mới.' );
					continue;
				}
				$title     = (string) $op['value'];
				$changes[] = 'Tên trang đổi';
			} elseif ( 'update' === $kind ) {
				$idx = isset( $op['index'] ) && is_int( $op['index'] ) ? $op['index'] : -1;
				if ( ! isset( $sections[ $idx ] ) ) {
					$errors[] = self::err( $p . '.index', 'index_invalid', 'Không có phần số ' . ( is_int( $op['index'] ?? null ) ? $op['index'] : '?' ) . ' (đang có ' . count( $sections ) . ' phần, đánh số từ 0).' );
					continue;
				}
				$fields = $op['fields'] ?? null;
				if ( ! is_array( $fields ) || array() === $fields ) {
					$errors[] = self::err( $p . '.fields', 'fields_invalid', 'Cần ít nhất một trường để sửa.' );
					continue;
				}
				$type = (string) $sections[ $idx ]['type'];
				foreach ( $fields as $k => $v ) {
					if ( ! in_array( (string) $k, self::EDITABLE[ $type ] ?? array(), true ) ) {
						$errors[] = self::err( $p . '.fields.' . self::safe_echo( (string) $k ), 'field_not_editable', 'Phần "' . $type . '" không sửa được trường "' . self::safe_echo( (string) $k ) . '" (sửa được: ' . implode( ', ', self::EDITABLE[ $type ] ?? array() ) . ').' );
						continue;
					}
					$sections[ $idx ][ (string) $k ] = $v;
					$changes[]                      = 'Phần ' . $idx . ' (' . $type . '): ' . $k;
				}
			} elseif ( 'add' === $kind ) {
				$section = $op['section'] ?? null;
				if ( ! is_array( $section ) || ! in_array( (string) ( $section['type'] ?? '' ), self::TYPES, true ) ) {
					$errors[] = self::err( $p . '.section', 'section_invalid', 'Phần thêm phải có type thuộc: ' . implode( ', ', self::TYPES ) . '.' );
					continue;
				}
				if ( count( $sections ) >= self::MAX_SECTIONS ) {
					$errors[] = self::err( $p, 'too_many_sections', 'Trang đã đủ ' . self::MAX_SECTIONS . ' phần.' );
					continue;
				}
				$after = isset( $op['after'] ) && is_int( $op['after'] ) ? max( -1, min( count( $sections ) - 1, $op['after'] ) ) : count( $sections ) - 1;
				array_splice( $sections, $after + 1, 0, array( $section ) );
				$changes[] = 'Thêm phần ' . $section['type'] . ' ở vị trí ' . ( $after + 1 );
			} elseif ( 'remove' === $kind ) {
				$idx = isset( $op['index'] ) && is_int( $op['index'] ) ? $op['index'] : -1;
				if ( ! isset( $sections[ $idx ] ) ) {
					$errors[] = self::err( $p . '.index', 'index_invalid', 'Không có phần số ' . ( is_int( $op['index'] ?? null ) ? $op['index'] : '?' ) . '.' );
					continue;
				}
				if ( count( $sections ) <= 1 ) {
					$errors[] = self::err( $p, 'last_section', 'Trang phải còn ít nhất một phần.' );
					continue;
				}
				$changes[] = 'Xoá phần ' . $idx . ' (' . $sections[ $idx ]['type'] . ')';
				array_splice( $sections, $idx, 1 );
			} else {
				$errors[] = self::err( $p . '.op', 'unknown_op', 'Thao tác "' . self::safe_echo( $kind ) . '" chưa hỗ trợ (set_title, update, add, remove).' );
			}
		}
		return array( 'ok' => array() === $errors, 'spec' => array_merge( $spec, array( 'title' => $title, 'sections' => $sections ) ), 'errors' => $errors, 'changes' => $changes );
	}

	const DIFF_MAX_LINES = 12;

	/**
	 * [2026-10-17 03:40 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.118 S118-L7 — diff theo phần giữa hai bản mô tả ĐÃ chuẩn hoá, để người duyệt thấy mình sắp đổi gì (doc 50 §3). Thuần hàm,
	 * không in nội dung dài (cắt 60 ký tự), tối đa {@see self::DIFF_MAX_LINES} dòng. Rỗng ⇒ hai bản giống nhau.
	 *
	 * @return string[]
	 */
	public static function diff( array $old, array $new ): array {
		$cut = static function ( $v ): string {
			$s = is_scalar( $v ) ? (string) $v : (string) json_encode( $v, JSON_UNESCAPED_UNICODE );
			return function_exists( 'mb_substr' ) ? mb_substr( $s, 0, 60, 'UTF-8' ) : substr( $s, 0, 60 );
		};
		$lines = array();
		if ( (string) ( $old['title'] ?? '' ) !== (string) ( $new['title'] ?? '' ) ) {
			$lines[] = 'Tên trang: "' . $cut( $old['title'] ?? '' ) . '" → "' . $cut( $new['title'] ?? '' ) . '"';
		}
		$a = array_values( (array) ( $old['sections'] ?? array() ) );
		$b = array_values( (array) ( $new['sections'] ?? array() ) );
		for ( $i = 0, $n = max( count( $a ), count( $b ) ); $i < $n; $i++ ) {
			if ( ! isset( $a[ $i ] ) ) {
				$lines[] = 'Thêm phần ' . $i . ' (' . $cut( $b[ $i ]['type'] ?? '' ) . ')';
			} elseif ( ! isset( $b[ $i ] ) ) {
				$lines[] = 'Bỏ phần ' . $i . ' (' . $cut( $a[ $i ]['type'] ?? '' ) . ')';
			} elseif ( $a[ $i ] !== $b[ $i ] ) {
				if ( ( $a[ $i ]['type'] ?? '' ) !== ( $b[ $i ]['type'] ?? '' ) ) {
					$lines[] = 'Phần ' . $i . ': ' . $cut( $a[ $i ]['type'] ?? '' ) . ' → ' . $cut( $b[ $i ]['type'] ?? '' );
					continue;
				}
				$changed = array();
				foreach ( array_unique( array_merge( array_keys( $a[ $i ] ), array_keys( $b[ $i ] ) ) ) as $k ) {
					if ( ( $a[ $i ][ $k ] ?? null ) !== ( $b[ $i ][ $k ] ?? null ) ) {
						$changed[] = $k . ' ("' . $cut( $a[ $i ][ $k ] ?? '' ) . '" → "' . $cut( $b[ $i ][ $k ] ?? '' ) . '")';
					}
				}
				$lines[] = 'Phần ' . $i . ' (' . $cut( $a[ $i ]['type'] ?? '' ) . '): ' . implode( ', ', $changed );
			}
		}
		if ( count( $lines ) > self::DIFF_MAX_LINES ) {
			$extra   = count( $lines ) - ( self::DIFF_MAX_LINES - 1 );
			$lines   = array_slice( $lines, 0, self::DIFF_MAX_LINES - 1 );
			$lines[] = '… và ' . $extra . ' thay đổi nữa';
		}
		return $lines;
	}

	/** sha256 of the canonical (key-sorted) normalised spec — stored on the page so the log can trace it. */
	public static function spec_hash( array $normalized ): string {
		return hash( 'sha256', json_encode( self::ksort_deep( $normalized ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
	}

	/**
	 * @param array  $spec      output of validate()['spec']
	 * @param string $transport 'flatsome' | 'gutenberg'
	 * @param array  $media     test/service seam: media:<id> → attachment id already verified by the caller
	 */
	public static function compile( array $spec, string $transport ): string {
		$parts = array();
		foreach ( $spec['sections'] as $s ) {
			$parts[] = 'gutenberg' === $transport ? self::gutenberg_section( $s ) : self::flatsome_section( $s );
		}
		return implode( "\n\n", array_filter( $parts, 'strlen' ) ) . "\n";
	}

	/** ids of every `media:<id>` / `cf7:<id>` the spec points at, so the service can check they exist before writing. */
	public static function refs( array $spec ): array {
		$media = array();
		$forms = array();
		foreach ( $spec['sections'] as $s ) {
			if ( isset( $s['image_ref'] ) && preg_match( '/^media:(\d+)$/', $s['image_ref'], $m ) ) {
				$media[] = (int) $m[1];
			}
			if ( isset( $s['form_ref'] ) && preg_match( '/^cf7:(\d+)$/', $s['form_ref'], $m ) ) {
				$forms[] = (int) $m[1];
			}
		}
		return array( 'media' => array_values( array_unique( $media ) ), 'forms' => array_values( array_unique( $forms ) ) );
	}

	/* ───────────────────────────── Flatsome ───────────────────────────── */

	private static function flatsome_section( array $s ): string {
		switch ( $s['type'] ) {
			case 'hero':
				$cta = $s['cta']['label'] ? '[button text="' . self::attr( $s['cta']['label'] ) . '" link="' . self::attr( $s['cta']['target'] ) . '" color="primary" size="larger" radius="10"]' : '';
				$sub = $s['sub'] ? '[ux_text text_align="center"]<p>' . self::e( $s['sub'] ) . '</p>[/ux_text]' : '';
				$h1  = '[title text="' . self::attr( $s['headline'] ) . '" tag_name="h1" size="190" style="center"]';
				if ( isset( $s['image_ref'] ) ) {
					$id = (int) substr( $s['image_ref'], 6 );
					return '[ux_banner height="520px" bg="' . $id . '" bg_overlay="rgba(0,0,0,.42)"]' . "\n"
						. '[text_box width="70" width__sm="90" text_color="light" text_align="center"]' . $h1 . $sub . $cta . '[/text_box]' . "\n"
						. '[/ux_banner]';
				}
				return '[section _id="hero" dark="true" bg_color="rgb(34,34,34)" padding="80px"]' . "\n"
					. '[row][col span="12" span__sm="12" align="center"]' . $h1 . $sub . $cta . '[/col][/row]' . "\n"
					. '[/section]';
			case 'benefits':
				$rows = array();
				foreach ( array_chunk( $s['items'], 4 ) as $chunk ) {
					$span = (int) ( 12 / max( 1, min( 4, count( $s['items'] ) ) ) );
					$cols = '';
					foreach ( $chunk as $it ) {
						$cols .= '[col span="' . $span . '" span__sm="12" align="center"]'
							. '[title text="' . self::attr( $it['title'] ) . '" tag_name="h3" size="120" style="center"]'
							. ( $it['text'] ? '[ux_text text_align="center"]<p>' . self::e( $it['text'] ) . '</p>[/ux_text]' : '' )
							. '[/col]';
					}
					$rows[] = '[row]' . $cols . '[/row]';
				}
				return '[section _id="benefits" padding="50px"]' . "\n" . implode( "\n", $rows ) . "\n" . '[/section]';
			case 'text':
				return '[section _id="intro" padding="40px"]' . "\n" . '[row][col span="12" span__sm="12"]'
					. ( $s['title'] ? '[title text="' . self::attr( $s['title'] ) . '" tag_name="h2" style="center"]' : '' )
					. '[ux_text]' . self::paragraphs( $s['body'] ) . '[/ux_text]'
					. '[/col][/row]' . "\n" . '[/section]';
			case 'form':
				$id = (int) substr( $s['form_ref'], 4 );
				return '[section _id="' . $s['anchor'] . '" padding="50px" bg_color="rgb(245,245,245)"]' . "\n" . '[row][col span="12" span__sm="12"]'
					. ( $s['title'] ? '[title text="' . self::attr( $s['title'] ) . '" tag_name="h2" style="center"]' : '' )
					. '[contact-form-7 id="' . $id . '"]'
					. '[/col][/row]' . "\n" . '[/section]';
			case 'cta':
				$link = $s['button']['tel'] ? 'tel:' . $s['button']['tel'] : ( $s['button']['target'] ?: '#' );
				return '[section _id="cta" dark="true" bg_color="rgb(34,34,34)" padding="50px"]' . "\n" . '[row][col span="12" span__sm="12" align="center"]'
					. '[title text="' . self::attr( $s['headline'] ) . '" tag_name="h2" style="center"]'
					. '[button text="' . self::attr( $s['button']['label'] ) . '" link="' . self::attr( $link ) . '" color="primary" size="larger" radius="10"]'
					. '[/col][/row]' . "\n" . '[/section]';
		}
		return '';
	}

	/* ──────────────────────────── Gutenberg ───────────────────────────── */

	private static function gutenberg_section( array $s ): string {
		$btn = static function ( string $label, string $url ): string {
			return '<!-- wp:buttons {"layout":{"type":"flex","justifyContent":"center"}} --><div class="wp-block-buttons"><!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="' . self::e( $url ) . '">' . self::e( $label ) . '</a></div><!-- /wp:button --></div><!-- /wp:buttons -->';
		};
		switch ( $s['type'] ) {
			case 'hero':
				$img = '';
				if ( isset( $s['image_ref'] ) ) {
					$img = '<!-- wp:image {"id":' . (int) substr( $s['image_ref'], 6 ) . ',"sizeSlug":"large","align":"center"} --><figure class="wp-block-image aligncenter size-large"></figure><!-- /wp:image -->';
				}
				return '<!-- wp:group {"layout":{"type":"constrained"}} --><div class="wp-block-group">'
					. '<!-- wp:heading {"textAlign":"center","level":1} --><h1 class="has-text-align-center">' . self::e( $s['headline'] ) . '</h1><!-- /wp:heading -->'
					. ( $s['sub'] ? '<!-- wp:paragraph {"align":"center"} --><p class="has-text-align-center">' . self::e( $s['sub'] ) . '</p><!-- /wp:paragraph -->' : '' )
					. $img . ( $s['cta']['label'] ? $btn( $s['cta']['label'], $s['cta']['target'] ) : '' )
					. '</div><!-- /wp:group -->';
			case 'benefits':
				$cols = '';
				foreach ( $s['items'] as $it ) {
					$cols .= '<!-- wp:column --><div class="wp-block-column"><!-- wp:heading {"textAlign":"center","level":3} --><h3 class="has-text-align-center">' . self::e( $it['title'] ) . '</h3><!-- /wp:heading -->'
						. ( $it['text'] ? '<!-- wp:paragraph {"align":"center"} --><p class="has-text-align-center">' . self::e( $it['text'] ) . '</p><!-- /wp:paragraph -->' : '' ) . '</div><!-- /wp:column -->';
				}
				return '<!-- wp:columns --><div class="wp-block-columns">' . $cols . '</div><!-- /wp:columns -->';
			case 'text':
				$paras = '';
				foreach ( preg_split( '/\n{1,}/', $s['body'] ) as $p ) {
					if ( '' !== trim( $p ) ) {
						$paras .= '<!-- wp:paragraph --><p>' . self::e( trim( $p ) ) . '</p><!-- /wp:paragraph -->';
					}
				}
				return ( $s['title'] ? '<!-- wp:heading {"textAlign":"center"} --><h2 class="has-text-align-center">' . self::e( $s['title'] ) . '</h2><!-- /wp:heading -->' : '' ) . $paras;
			case 'form':
				return '<!-- wp:html --><div id="' . $s['anchor'] . '"></div><!-- /wp:html -->'
					. ( $s['title'] ? '<!-- wp:heading {"textAlign":"center"} --><h2 class="has-text-align-center">' . self::e( $s['title'] ) . '</h2><!-- /wp:heading -->' : '' )
					. '<!-- wp:shortcode -->[contact-form-7 id="' . (int) substr( $s['form_ref'], 4 ) . '"]<!-- /wp:shortcode -->';
			case 'cta':
				$link = $s['button']['tel'] ? 'tel:' . $s['button']['tel'] : ( $s['button']['target'] ?: '#' );
				return '<!-- wp:heading {"textAlign":"center"} --><h2 class="has-text-align-center">' . self::e( $s['headline'] ) . '</h2><!-- /wp:heading -->' . $btn( $s['button']['label'], $link );
		}
		return '';
	}

	/* ───────────────────────────── form-spec@1 ────────────────────────── */

	/**
	 * Validate a form-spec and build the CF7 `form` template and the mail `body`.
	 *
	 * @param mixed $spec {title, fields[{type,name,label,required}], submit_label, success_message}
	 * @return array{ok:bool,form:array,template:string,mail_body:string,errors:array}
	 */
	public static function form_template( $spec ): array {
		$errors = array();
		$f      = array( 'title' => '', 'fields' => array(), 'submit_label' => 'Gửi', 'success_message' => '' );
		if ( ! is_array( $spec ) ) {
			return array( 'ok' => false, 'form' => $f, 'template' => '', 'mail_body' => '', 'errors' => array( self::err( 'form', 'form_invalid', 'Bản mô tả form phải là một đối tượng.' ) ) );
		}
		$f['title']           = self::clean( $spec['title'] ?? '', 120, 'title', true, $errors );
		$f['submit_label']    = self::clean( $spec['submit_label'] ?? 'Gửi', 30, 'submit_label', false, $errors ) ?: 'Gửi';
		$f['success_message'] = self::clean( $spec['success_message'] ?? 'Cảm ơn bạn! Chúng tôi sẽ liên hệ sớm.', 200, 'success_message', false, $errors );
		$fields               = $spec['fields'] ?? null;
		if ( ! is_array( $fields ) || array() === $fields || count( $fields ) > self::MAX_FIELDS ) {
			$errors[] = self::err( 'fields', 'fields_invalid', 'Cần từ 1 đến ' . self::MAX_FIELDS . ' trường.' );
			return array( 'ok' => false, 'form' => $f, 'template' => '', 'mail_body' => '', 'errors' => $errors );
		}
		$names = array();
		$tpl   = '';
		$mail  = '';
		foreach ( array_values( $fields ) as $i => $fl ) {
			$p    = 'fields[' . $i . ']';
			$type = is_array( $fl ) ? (string) ( $fl['type'] ?? '' ) : '';
			$name = is_array( $fl ) ? (string) ( $fl['name'] ?? '' ) : '';
			if ( ! in_array( $type, self::FIELD_TYPES, true ) ) {
				$errors[] = self::err( $p . '.type', 'field_type_invalid', 'Loại trường không hợp lệ.' );
				continue;
			}
			if ( ! preg_match( '/^[a-z0-9_-]{1,30}$/', $name ) || isset( $names[ $name ] ) ) {
				$errors[] = self::err( $p . '.name', 'field_name_invalid', 'Tên trường chỉ gồm a-z, 0-9, _ , - (tối đa 30) và không trùng nhau.' );
				continue;
			}
			$names[ $name ] = true;
			$label          = self::clean( $fl['label'] ?? '', 60, $p . '.label', true, $errors );
			$req            = ! empty( $fl['required'] ) ? '*' : '';
			$tag            = 'tel' === $type ? 'tel' : $type;
			$tpl           .= '<label>' . self::cf7_text( $label ) . '[' . $tag . $req . ' ' . $name . ']</label>' . "\n";
			$mail          .= self::cf7_text( $label ) . ': [' . $name . "]\n";
			$f['fields'][]  = array( 'type' => $type, 'name' => $name, 'label' => $label, 'required' => '*' === $req );
		}
		$tpl .= '[submit "' . str_replace( '"', '', self::cf7_text( $f['submit_label'] ) ) . '"]';
		return array( 'ok' => array() === $errors, 'form' => $f, 'template' => $tpl, 'mail_body' => $mail, 'errors' => $errors );
	}

	/* ───────────────────────────── helpers ────────────────────────────── */

	/** text for HTML content between tags: entities + [ ] neutralised */
	public static function e( string $s ): string {
		return str_replace( array( '[', ']' ), array( '&#91;', '&#93;' ), htmlspecialchars( $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) );
	}

	/** value inside a shortcode attribute (double-quoted): same neutralisation, no line breaks */
	public static function attr( string $s ): string {
		return self::e( preg_replace( '/\s+/u', ' ', $s ) );
	}

	/** CF7 label/mail text: no brackets at all (CF7 reads [tag] everywhere), no angle brackets */
	private static function cf7_text( string $s ): string {
		return str_replace( array( '[', ']', '<', '>' ), '', $s );
	}

	private static function paragraphs( string $body ): string {
		$out = '';
		foreach ( preg_split( '/\n+/', $body ) as $p ) {
			if ( '' !== trim( $p ) ) {
				$out .= '<p>' . self::e( trim( $p ) ) . '</p>';
			}
		}
		return $out;
	}

	private static function cta( $c, string $path, array &$errors ): array {
		$c = is_array( $c ) ? $c : array();
		$label = self::clean( $c['label'] ?? '', 30, $path . '.label', false, $errors );
		return array( 'label' => $label, 'target' => self::link( $c['target'] ?? '' ) );
	}

	/** whitelist: #anchor · tel:+digits · https?://… ; anything else ⇒ '#' */
	public static function link( $t ): string {
		$t = trim( (string) $t );
		if ( preg_match( '/^#[A-Za-z0-9_-]{1,40}$/', $t ) ) {
			return $t;
		}
		if ( preg_match( '/^tel:\+?[0-9]{6,15}$/', $t ) ) {
			return $t;
		}
		if ( preg_match( '#^https?://[^\s"\'<>\[\]]{1,300}$#i', $t ) && false !== filter_var( $t, FILTER_VALIDATE_URL ) ) {
			return $t;
		}
		return '#';
	}

	/** digits with optional leading +, 8–15 digits, spaces/dots/dashes dropped; otherwise '' */
	public static function phone( $p ): string {
		$p = preg_replace( '/[\s.\-()]/', '', (string) $p );
		return preg_match( '/^\+?[0-9]{8,15}$/', $p ) ? $p : '';
	}

	/** tags stripped, whitespace collapsed (newlines kept when $multiline), control chars removed, length-capped by ERROR (never silently cut) */
	private static function clean( $v, int $max, string $path, bool $required, array &$errors, bool $multiline = false ): string {
		if ( ! is_string( $v ) && ! is_numeric( $v ) ) {
			$v = '';
		}
		$v = (string) $v;
		$v = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $v );
		$v = strip_tags( $v );
		$v = $multiline ? trim( preg_replace( "/[ \t]+/u", ' ', preg_replace( "/\r\n?/", "\n", $v ) ) ) : trim( preg_replace( '/\s+/u', ' ', $v ) );
		if ( '' === $v && $required ) {
			$errors[] = self::err( $path, 'required', 'Thiếu nội dung bắt buộc.' );
		}
		if ( mb_strlen( $v, 'UTF-8' ) > $max ) {
			$errors[] = self::err( $path, 'too_long', 'Dài quá ' . $max . ' ký tự.' );
			$v        = mb_substr( $v, 0, $max, 'UTF-8' );
		}
		return $v;
	}

	/** an unknown value echoed back in a message: letters/digits only so an error text cannot carry markup */
	private static function safe_echo( string $s ): string {
		return substr( preg_replace( '/[^A-Za-z0-9_.-]/', '', $s ), 0, 24 );
	}

	private static function err( string $path, string $code, string $message ): array {
		return array( 'path' => $path, 'code' => $code, 'message' => $message );
	}

	private static function result( array $spec, array $errors ): array {
		return array( 'ok' => array() === $errors, 'spec' => $spec, 'errors' => $errors );
	}

	private static function fail( array $errors ): array {
		return array( 'ok' => false, 'spec' => array(), 'errors' => $errors );
	}

	private static function ksort_deep( $v ) {
		if ( is_array( $v ) ) {
			$is_list = array_keys( $v ) === range( 0, count( $v ) - 1 );
			$v       = array_map( array( __CLASS__, 'ksort_deep' ), $v );
			if ( ! $is_list ) {
				ksort( $v );
			}
		}
		return $v;
	}
}
