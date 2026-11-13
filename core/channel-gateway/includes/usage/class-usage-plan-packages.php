<?php
/**
 * The site's own price list of Pro plans for one number: 1 / 3 / 6 / 12 months (PHASE-0.108 S108-L17, 20-REVENUE-PER-NUMBER.md §3.1, 24 §2.2, §2.4).
 *
 * Stored in option `bizcity_plan_packages` = { version, items:[{code,months,enabled,price_vnd,allowance,label,product_id}] } — always exactly the four codes
 * pro_1m, pro_3m, pro_6m, pro_12m. Saving validates every enabled package against the price floor (BizCity_Usage_Plan_Floor, soft guard) and then
 * syncs ONE hidden virtual Woo product per code (meta `_bizcity_plan_pro=yes`, `_bizcity_plan_package`, `_bizcity_plan_months`, `_bizcity_plan_allowance`) so the
 * existing paid-order path (BizCity_Usage_Plan_Woo) recognises the order. Products are never deleted (old orders point at them): a switched-off package
 * becomes `private`.
 *
 * Prices of orders already created are NOT touched (the order line carries its own total); editing a price only affects orders created afterwards.
 *
 * Test seams ($readers): option(name, default) · save_option(name, value) · now() · floor(): array · default_allowance(): int · product_sync(item): int
 *
 * // [2026-10-13 09:11 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.108 S108-L17.1 / L17.2.
 *
 * @package BizCity_Twin_AI
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_Usage_Plan_Packages {

	const OPT_PACKAGES = 'bizcity_plan_packages';
	const META_PACKAGE = '_bizcity_plan_package';
	const META_MONTHS  = '_bizcity_plan_months';
	const META_ALLOW   = '_bizcity_plan_allowance';
	const MAX_PRICE    = 100000000;
	const MAX_ALLOW    = 10000000000;
	const CODES        = array( 'pro_1m' => 1, 'pro_3m' => 3, 'pro_6m' => 6, 'pro_12m' => 12 );

	/** @var array<string,callable> */
	public static $readers = array();

	/* ── pure ─────────────────────────────────────────────────────── */

	public static function months_of( string $code ): int {
		return self::CODES[ $code ] ?? 0;
	}

	public static function label_of( string $code ): string {
		$m = self::months_of( $code );
		return $m > 0 ? sprintf( 'Gói Pro số Zalo — %d tháng', $m ) : '';
	}

	/**
	 * "giảm 5 %" of a package against buying month-by-month (price of 1 month × months). 0 when there is no discount or no 1-month price.
	 */
	public static function discount_pct( int $months, int $price_vnd, int $price_1m_vnd ): int {
		if ( $months <= 1 || $price_1m_vnd <= 0 || $price_vnd <= 0 ) {
			return 0;
		}
		$full = $price_1m_vnd * $months;
		return $price_vnd >= $full ? 0 : (int) round( ( $full - $price_vnd ) / $full * 100 );
	}

	/** Factory defaults: all four OFF, 1 month = the Pro price of 0.107, N months = N × that (no discount until the site types one). */
	public static function defaults( int $price_1m, int $allowance ): array {
		$items = array();
		foreach ( self::CODES as $code => $months ) {
			$items[] = array(
				'code' => $code, 'months' => $months, 'enabled' => false, 'price_vnd' => $price_1m * $months,
				'allowance' => $allowance, 'label' => self::label_of( $code ), 'product_id' => 0,
			);
		}
		return $items;
	}

	/**
	 * Pure: turn a submitted list into the full list of four, or per-code errors. `$floor_state` is BizCity_Usage_Plan_Floor::read(); `$default_allowance` fills an empty allowance.
	 *
	 * @param array<int,array<string,mixed>> $input   [{code, enabled?, price_vnd?, allowance?}] — codes not listed keep their current values
	 * @param array<int,array<string,mixed>> $current the four current items
	 * @return array{ok:bool,items:array,errors:array<string,array{code:string,message:string,gap_vnd?:int}>}
	 */
	public static function validate( array $input, array $current, array $floor_state, int $default_allowance ): array {
		$by_code = array();
		foreach ( $current as $it ) {
			$by_code[ $it['code'] ] = $it;
		}
		$errors = array();
		foreach ( $input as $in ) {
			$code = (string) ( $in['code'] ?? '' );
			if ( ! isset( self::CODES[ $code ] ) ) {
				$errors[ $code ?: '?' ] = array( 'code' => 'unknown_package', 'message' => 'Gói không có trong danh sách 1/3/6/12 tháng.' );
				continue;
			}
			$it = $by_code[ $code ];
			if ( array_key_exists( 'enabled', $in ) ) {
				$it['enabled'] = (bool) $in['enabled'];
			}
			if ( array_key_exists( 'price_vnd', $in ) ) {
				if ( ! is_numeric( $in['price_vnd'] ) || $in['price_vnd'] < 0 || $in['price_vnd'] > self::MAX_PRICE || (float) $in['price_vnd'] != (int) $in['price_vnd'] ) {
					$errors[ $code ] = array( 'code' => 'invalid_price', 'message' => 'Giá phải là số đồng nguyên từ 0 đến 100.000.000.' );
					continue;
				}
				$it['price_vnd'] = (int) $in['price_vnd'];
			}
			if ( array_key_exists( 'allowance', $in ) ) {
				if ( ! is_numeric( $in['allowance'] ) || $in['allowance'] < 0 || $in['allowance'] > self::MAX_ALLOW || (float) $in['allowance'] != (int) $in['allowance'] ) {
					$errors[ $code ] = array( 'code' => 'invalid_allowance', 'message' => 'Hạn mức token phải là số nguyên không âm.' );
					continue;
				}
				$it['allowance'] = (int) $in['allowance'];
			}
			if ( $it['allowance'] <= 0 ) {
				$it['allowance'] = $default_allowance; // empty ⇒ the 0.107 formula default
			}
			$by_code[ $code ] = $it;
		}
		// An enabled package needs a price, an allowance and a price at or above the floor (soft guard, D108-R2: no floor read ⇒ allowed).
		$out = array();
		foreach ( self::CODES as $code => $m ) {
			$it = $by_code[ $code ];
			if ( ! isset( $errors[ $code ] ) && $it['enabled'] ) {
				if ( $it['price_vnd'] <= 0 ) {
					$errors[ $code ] = array( 'code' => 'price_required', 'message' => 'Nhập giá bán cho gói đang bật.' );
				} elseif ( $it['allowance'] <= 0 ) {
					$errors[ $code ] = array( 'code' => 'allowance_required', 'message' => 'Chưa tính được hạn mức token mặc định (thiếu tỷ giá Hub). Hãy nhập hạn mức token.' );
				} elseif ( class_exists( 'BizCity_Usage_Plan_Floor' ) ) {
					$c = BizCity_Usage_Plan_Floor::check( $it, $floor_state );
					if ( ! $c['ok'] ) {
						$errors[ $code ] = array( 'code' => 'below_floor', 'message' => sprintf( 'Thấp hơn giá sàn %s ₫.', number_format( (int) $c['floor_vnd'], 0, ',', '.' ) ), 'gap_vnd' => (int) $c['gap_vnd'] );
					}
				}
			}
			$out[] = $it;
		}
		return array( 'ok' => ! $errors, 'items' => $out, 'errors' => $errors );
	}

	/* ── store ────────────────────────────────────────────────────── */

	/** @return array{version:int,items:array} always the four codes in order */
	public static function get(): array {
		$base  = (int) self::option( BizCity_Usage_Plans::OPT_PRO_PRICE, 200000 );
		$allow = self::default_allowance();
		$items = self::defaults( $base > 0 ? $base : 200000, $allow );
		$raw   = self::option( self::OPT_PACKAGES, array() );
		$ver   = is_array( $raw ) ? (int) ( $raw['version'] ?? 0 ) : 0;
		if ( is_array( $raw ) && ! empty( $raw['items'] ) && is_array( $raw['items'] ) ) {
			$saved = array();
			foreach ( $raw['items'] as $it ) {
				if ( is_array( $it ) && isset( $it['code'] ) ) {
					$saved[ (string) $it['code'] ] = $it;
				}
			}
			foreach ( $items as $i => $d ) {
				if ( isset( $saved[ $d['code'] ] ) ) {
					$s = $saved[ $d['code'] ];
					$items[ $i ] = array_merge( $d, array(
						'enabled'    => ! empty( $s['enabled'] ),
						'price_vnd'  => isset( $s['price_vnd'] ) ? (int) $s['price_vnd'] : $d['price_vnd'],
						'allowance'  => isset( $s['allowance'] ) && (int) $s['allowance'] > 0 ? (int) $s['allowance'] : $d['allowance'],
						'product_id' => isset( $s['product_id'] ) ? (int) $s['product_id'] : 0,
					) );
				}
			}
		}
		return array( 'version' => $ver, 'items' => $items );
	}

	public static function item( string $code ): ?array {
		foreach ( self::get()['items'] as $it ) {
			if ( $it['code'] === $code ) {
				return $it;
			}
		}
		return null;
	}

	/**
	 * Validate + store + sync Woo products. Returns the validate() shape plus `version` on success.
	 *
	 * @return array{ok:bool,items:array,errors:array,version?:int}
	 */
	public static function save( array $input, array $floor_state ): array {
		$cur = self::get();
		$v   = self::validate( $input, $cur['items'], $floor_state, self::default_allowance() );
		if ( ! $v['ok'] ) {
			return $v;
		}
		$items = array();
		foreach ( $v['items'] as $it ) {
			$it['product_id'] = self::product_sync( $it ); // 0 when Woo is absent: the price list still saves, selling needs Woo
			$items[]          = $it;
		}
		$ver = (int) $cur['version'] + 1;
		self::save_option( self::OPT_PACKAGES, array( 'version' => $ver, 'items' => $items ) );
		return array( 'ok' => true, 'items' => $items, 'errors' => array(), 'version' => $ver );
	}

	/* ── Woo product (idempotent) ─────────────────────────────────── */

	/** @return int the product id, 0 when it cannot be made */
	public static function product_sync( array $it ): int {
		if ( isset( self::$readers['product_sync'] ) ) {
			return (int) call_user_func( self::$readers['product_sync'], $it );
		}
		if ( ! class_exists( 'WC_Product_Simple' ) || ! function_exists( 'wc_get_product' ) ) {
			return 0;
		}
		$id = (int) ( $it['product_id'] ?? 0 );
		if ( $id <= 0 || ! wc_get_product( $id ) ) {
			$id = self::find_product( (string) $it['code'] ); // someone may have deleted the option but not the product
		}
		$p = $id > 0 ? wc_get_product( $id ) : new WC_Product_Simple();
		if ( ! $p ) {
			return 0;
		}
		$p->set_name( self::label_of( (string) $it['code'] ) );
		$p->set_regular_price( (string) (int) $it['price_vnd'] );
		$p->set_virtual( true );
		$p->set_sold_individually( true );
		$p->set_catalog_visibility( 'hidden' );
		$p->set_status( ! empty( $it['enabled'] ) ? 'publish' : 'private' ); // never delete: old orders reference it
		$p->update_meta_data( BizCity_Usage_Plan_Woo::META_PRODUCT, 'yes' );
		$p->update_meta_data( self::META_PACKAGE, (string) $it['code'] );
		$p->update_meta_data( self::META_MONTHS, (int) $it['months'] );
		$p->update_meta_data( self::META_ALLOW, (int) $it['allowance'] );
		return (int) $p->save();
	}

	private static function find_product( string $code ): int {
		if ( ! function_exists( 'wc_get_products' ) ) {
			return 0;
		}
		$ids = wc_get_products( array( 'limit' => 1, 'return' => 'ids', 'status' => array( 'publish', 'private', 'draft' ), 'meta_key' => self::META_PACKAGE, 'meta_value' => $code ) );
		return $ids ? (int) $ids[0] : 0;
	}

	/* ── plumbing ──────────────────────────────────────────────────── */

	private static function default_allowance(): int {
		if ( isset( self::$readers['default_allowance'] ) ) {
			return (int) call_user_func( self::$readers['default_allowance'] );
		}
		return class_exists( 'BizCity_Usage_Plans' ) ? (int) BizCity_Usage_Plans::default_pro_allowance() : 0;
	}

	private static function option( string $name, $default ) {
		if ( isset( self::$readers['option'] ) ) {
			return call_user_func( self::$readers['option'], $name, $default );
		}
		return function_exists( 'get_option' ) ? get_option( $name, $default ) : $default;
	}

	private static function save_option( string $name, $value ): void {
		if ( isset( self::$readers['save_option'] ) ) {
			call_user_func( self::$readers['save_option'], $name, $value );
			return;
		}
		if ( function_exists( 'update_option' ) ) {
			update_option( $name, $value, false );
		}
	}
}
