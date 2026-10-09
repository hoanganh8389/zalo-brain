<?php
/**
 * BizCity_Product_Advice — `product-advice@1` (PHASE-0.95 S95-F4): how the shop wants a product (post meta) or a product
 * category (term meta) to be advised. Both live under the key `_bizcity_advice`; every field is optional (Q95-10/11).
 *
 *   product  { v:1, tier, priority, audience[], age_min, age_max, age_unit, goals[], avoid_for[], key_questions[], pitch,
 *              pair_with[], next_tool, compliance }
 *   category { v:1, ask_first[], facets_to_ask[], synonyms[], compliance }
 *
 * sanitize() is frozen by the shared fixture zalo-hub/contracts/fixtures/consult/advice.sanitize.json: strings trimmed,
 * empty dropped, duplicates dropped (first wins), caps applied, unknown keys dropped, output always has v:1 and every key.
 * Values are DATA for the cell (wrapped as untrusted content before a prompt), never instructions.
 *
 * Storage: the sanitized array (WordPress serializes it). No new table (lean ratchet). show_in_rest false.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\MCP\Brain
 * @since      PHASE-0.95 (2026-10-09)
 */

defined( 'ABSPATH' ) || exit;

// [2026-10-09 03:12 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.95-S95-F4 — new file, product-advice@1 meta + sanitize.
final class BizCity_Product_Advice {

	const CONTRACT  = 'product-advice@1.0.0';
	const META_KEY  = '_bizcity_advice';
	const TIERS     = array( 'strategic', 'core', 'clearance', 'normal' );
	const NEXT      = array( 'order', 'booking', 'brief', 'contact_staff' );
	const AGE_UNITS = array( 'year', 'month' );

	/** @var array<string,callable> test seams: post_meta(id): mixed · term_meta(id): mixed */
	public static $readers = array();

	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'register_meta' ), 20 );
	}

	public static function register_meta(): void {
		if ( ! function_exists( 'register_post_meta' ) ) {
			return;
		}
		register_post_meta( 'product', self::META_KEY, array(
			'type'              => 'object',
			'single'            => true,
			'show_in_rest'      => false,
			'sanitize_callback' => array( __CLASS__, 'sanitize_meta_value' ),
			'auth_callback'     => static function () { return current_user_can( 'edit_products' ) || current_user_can( 'manage_options' ); },
		) );
		if ( function_exists( 'register_term_meta' ) ) {
			register_term_meta( 'product_cat', self::META_KEY, array(
				'type'              => 'object',
				'single'            => true,
				'show_in_rest'      => false,
				'sanitize_callback' => array( __CLASS__, 'sanitize_category_meta_value' ),
				'auth_callback'     => static function () { return current_user_can( 'manage_product_terms' ) || current_user_can( 'manage_options' ); },
			) );
		}
	}

	/** register_post_meta callback: an array, or a JSON string of one. */
	public static function sanitize_meta_value( $value ) {
		return self::sanitize( self::decode( $value ) );
	}

	public static function sanitize_category_meta_value( $value ) {
		return self::sanitize_category( self::decode( $value ) );
	}

	/**
	 * product-advice@1 (product). Frozen by advice.sanitize.json.
	 */
	public static function sanitize( array $in ): array {
		$tier = (string) ( $in['tier'] ?? '' );
		$next = (string) ( $in['next_tool'] ?? '' );
		$unit = (string) ( $in['age_unit'] ?? '' );
		return array(
			'v'             => 1,
			'tier'          => in_array( $tier, self::TIERS, true ) ? $tier : 'normal',
			'priority'      => isset( $in['priority'] ) && is_numeric( $in['priority'] ) ? max( 0, min( 100, (int) $in['priority'] ) ) : 0,
			'audience'      => self::list_of( $in['audience'] ?? array(), 5, 40 ),
			'age_min'       => self::age( $in['age_min'] ?? null ),
			'age_max'       => self::age( $in['age_max'] ?? null ),
			'age_unit'      => in_array( $unit, self::AGE_UNITS, true ) ? $unit : 'year',
			'goals'         => self::list_of( $in['goals'] ?? array(), 6, 40 ),
			'avoid_for'     => self::list_of( $in['avoid_for'] ?? array(), 6, 40 ),
			'key_questions' => self::list_of( $in['key_questions'] ?? array(), 3, 120 ),
			'pitch'         => self::cut( $in['pitch'] ?? '', 300 ),
			'pair_with'     => self::ids( $in['pair_with'] ?? array(), 3 ),
			'next_tool'     => in_array( $next, self::NEXT, true ) ? $next : 'order',
			'compliance'    => self::cut( $in['compliance'] ?? '', 200 ),
		);
	}

	/** product-advice@1 (product_cat term). */
	public static function sanitize_category( array $in ): array {
		return array(
			'v'             => 1,
			'ask_first'     => self::list_of( $in['ask_first'] ?? array(), 3, 120 ),
			'facets_to_ask' => self::list_of( $in['facets_to_ask'] ?? array(), 6, 40 ),
			'synonyms'      => self::list_of( $in['synonyms'] ?? array(), 20, 60 ),
			'compliance'    => self::cut( $in['compliance'] ?? '', 200 ),
		);
	}

	/** Advice of a product, sanitized, or null when the product has none (score basis `attrs`). */
	public static function for_product( int $product_id ): ?array {
		if ( $product_id <= 0 ) {
			return null;
		}
		$raw = isset( self::$readers['post_meta'] ) ? call_user_func( self::$readers['post_meta'], $product_id )
			: ( function_exists( 'get_post_meta' ) ? get_post_meta( $product_id, self::META_KEY, true ) : null );
		$raw = self::decode( $raw );
		return $raw ? self::sanitize( $raw ) : null;
	}

	/** Advice of a product category, sanitized, or null. */
	public static function for_category( int $term_id ): ?array {
		if ( $term_id <= 0 ) {
			return null;
		}
		$raw = isset( self::$readers['term_meta'] ) ? call_user_func( self::$readers['term_meta'], $term_id )
			: ( function_exists( 'get_term_meta' ) ? get_term_meta( $term_id, self::META_KEY, true ) : null );
		$raw = self::decode( $raw );
		return $raw ? self::sanitize_category( $raw ) : null;
	}

	/* ── helpers ──────────────────────────────────────────────────── */

	private static function decode( $value ): array {
		if ( is_string( $value ) && '' !== trim( $value ) ) {
			$d = json_decode( $value, true );
			return is_array( $d ) ? $d : array();
		}
		if ( is_object( $value ) ) {
			$value = (array) json_decode( (string) wp_json_encode( $value ), true );
		}
		return is_array( $value ) ? $value : array();
	}

	/** Trimmed non-empty strings, each ≤ $len chars, duplicates dropped (first wins), ≤ $max items. */
	private static function list_of( $raw, int $max, int $len ): array {
		$out = array();
		foreach ( is_array( $raw ) ? $raw : array() as $v ) {
			if ( ! is_scalar( $v ) ) {
				continue;
			}
			$s = self::cut( $v, $len );
			if ( '' === $s || in_array( $s, $out, true ) ) {
				continue;
			}
			$out[] = $s;
			if ( count( $out ) >= $max ) {
				break;
			}
		}
		return $out;
	}

	private static function cut( $v, int $len ): string {
		$s = is_scalar( $v ) ? trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( (string) $v ) ) ) : '';
		return function_exists( 'mb_substr' ) ? mb_substr( $s, 0, $len, 'UTF-8' ) : substr( $s, 0, $len );
	}

	/** Positive ints only (no strings, no negatives), first ≤ $max, unique. */
	private static function ids( $raw, int $max ): array {
		$out = array();
		foreach ( is_array( $raw ) ? $raw : array() as $v ) {
			if ( ! is_int( $v ) && ! ( is_string( $v ) && ctype_digit( $v ) ) ) {
				continue;
			}
			$v = (int) $v;
			if ( $v > 0 && ! in_array( $v, $out, true ) ) {
				$out[] = $v;
			}
			if ( count( $out ) >= $max ) {
				break;
			}
		}
		return $out;
	}

	private static function age( $v ) {
		if ( null === $v || '' === $v || ! is_numeric( $v ) ) {
			return null;
		}
		$n = 0 + $v;
		if ( $n < 0 ) {
			return null;
		}
		return is_float( $n ) && floor( $n ) !== $n ? round( $n, 1 ) : (int) $n;
	}
}
