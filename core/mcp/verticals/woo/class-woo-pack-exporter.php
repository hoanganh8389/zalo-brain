<?php
/**
 * Biz Central Brain — Johnny Chu (Chu Hoàng Anh). Bizcity Central Brain, Giấy chứng nhận đăng ký quyền tác giả số 8877/2026/QTG.
 *
 * BizCity_Woo_Pack_Exporter — the five WooCommerce projection packs of the cell, in core (CORE-REDUCTION WP-20 W20-L2, D-W20-1):
 * `sales` · `orders` · `stock` (audience owner_agent, PHASE-0.87 CL-2) · `catalog` (PHASE-0.88 L2-6) · `catalog_map`
 * (PHASE-0.95 S95-F1, projection-pack@1.2). Moved from bizcity-twin-crm/includes/woo/class-business-pack-exporter.php so a site
 * without the Zalo Brain CRM plugin still gives the cell its product-group map and shop numbers (F-Z1). The item builders are
 * copied unchanged; kind names, constants, the option `bizcity_pack_catalog_map`, the cache prefix `bizcity_pack_biz_` and the
 * test seams keep their old values, so a running site keeps its cache and the cell sees no difference.
 *
 * Read-only, no LLM, no write (R-TAA-6). Money rule = BizCity_Woo_Reports_Bridge (paid statuses processing/completed/on-hold,
 * net = gross − refunds, aov = net / orders). The `customers` kind lives in core/crm (BizCity_CRM_Customers_Pack, W20-L3); an
 * order change flushes it too, in the same `bizcity_twin_agent_packs_changed` call as before.
 *
 * // @axis twin-agent-axis@1 pack sales,orders,stock,catalog,catalog_map
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\MCP\Verticals\Woo
 */

// [2026-10-09 10:39 PM Johnny Chu - Chu Hoàng Anh] CORE-REDUCTION WP-20 W20-L2 — new file: 5 Woo kinds move from the CRM plugin to core (D-W20-1).

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Woo_Pack_Exporter', false ) ) {
	return;
}

final class BizCity_Woo_Pack_Exporter {

	const PACK_FILTER    = 'bizcity_twin_agent_pack_exporters';
	const KINDS          = array( 'sales', 'orders', 'stock' );
	const CACHE_PREFIX   = 'bizcity_pack_biz_';
	const CACHE_TTL      = 600;
	const PAID_STATUSES  = array( 'processing', 'completed', 'on-hold' );
	const DAYS           = 90;
	const MONTHS         = 12;
	const TOP_PRODUCTS   = 20;
	const ORDERS_LAST    = 50;
	const STOCK_SCAN     = 500;
	const STOCK_MAX      = 200;
	const LOW_STOCK      = 5;
	const ORDER_PAGE     = 500;
	const ORDER_PAGES    = 40;
	const CATALOG_KIND   = 'catalog';
	const CATALOG_MAX    = 300;
	const CATALOG_DESC   = 200;
	const CATALOG_MAP_KIND    = 'catalog_map';
	const CATALOG_MAP_MAX     = 200;
	const CATALOG_MAP_BYTES   = 32768;
	const CATALOG_MAP_FACETS  = 6;
	const CATALOG_MAP_VALUES  = 8;
	const CATALOG_MAP_OPTION  = 'bizcity_pack_catalog_map'; // auto | off
	const ADVICE_META_KEY     = '_bizcity_advice';
	/** Order statuses that are not a sale signal for a contact (R-PIPE-2 v1.1, same rule as the CRM pipeline facts loader). */
	const CONTACT_ORDER_SKIP  = array( 'cancelled', 'failed', 'trash' );

	/**
	 * Test seams (same names as the old plugin exporter): paid_orders(from_ts): list<{ts, gross, refunds, paid, lines:[{product_id,
	 * name,qty,gross}]}> · recent_orders(n): list<{ref, ts, total, currency, status, first, last, phone, items}> · products(n):
	 * list<{id, name, managing, qty, status}> · catalog_products(n) · catalog_map_rows() · contact_orders(int[]): cid => facts ·
	 * currency(): string · now(): int · cache: false to bypass the transient.
	 *
	 * @var array<string,mixed>
	 */
	public static $readers = array();

	/** @var array<string,array> per-request memo */
	private static $memo = array();

	/** @var bool per-request guard of on_catalog_map_change() (bulk edits fire many hooks). */
	private static $catalog_map_flushed = false;


	public static function register(): void {
		add_filter( self::PACK_FILTER, array( __CLASS__, 'exporters' ), 10 );
		foreach ( array( 'woocommerce_order_status_changed', 'woocommerce_new_order', 'woocommerce_order_refunded' ) as $h ) {
			add_action( $h, array( __CLASS__, 'on_order_change' ), 30, 0 );
		}
		foreach ( array( 'woocommerce_product_set_stock', 'woocommerce_variation_set_stock', 'woocommerce_product_set_stock_status' ) as $h ) {
			add_action( $h, array( __CLASS__, 'on_stock_change' ), 30, 0 );
		}
		foreach ( array( 'woocommerce_update_product', 'woocommerce_new_product' ) as $h ) {
			add_action( $h, array( __CLASS__, 'on_product_change' ), 30, 0 );
		}
		// The category map moves with categories, product edits / stock and any `_bizcity_advice` (product post meta or
		// product_cat term meta). Own hooks: the `catalog` refresh above stays unchanged.
		foreach ( array( 'edited_product_cat', 'created_product_cat', 'delete_product_cat', 'woocommerce_update_product', 'woocommerce_new_product', 'woocommerce_product_set_stock', 'woocommerce_variation_set_stock', 'woocommerce_product_set_stock_status' ) as $h ) {
			add_action( $h, array( __CLASS__, 'on_catalog_map_change' ), 31, 0 );
		}
		foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta', 'added_term_meta', 'updated_term_meta', 'deleted_term_meta' ) as $h ) {
			add_action( $h, array( __CLASS__, 'on_advice_meta' ), 30, 3 );
		}
	}

	/** Once per request: drop the cached map and tell the cells (BizCity_Zalo_Pack_Invalidate debounces the push). */
	public static function on_catalog_map_change(): void {
		if ( self::$catalog_map_flushed ) {
			return;
		}
		self::$catalog_map_flushed = true;
		self::flush( array( self::CATALOG_MAP_KIND ) );
	}

	/** (added|updated|deleted)_(post|term)_meta: only `_bizcity_advice` moves the map. */
	public static function on_advice_meta( $meta_id = 0, $object_id = 0, $meta_key = '' ): void {
		if ( self::ADVICE_META_KEY === (string) $meta_key ) {
			self::on_catalog_map_change();
		}
	}

	public static function exporters( $exporters ): array {
		$exporters = is_array( $exporters ) ? $exporters : array();
		foreach ( self::KINDS as $kind ) {
			$exporters[ $kind ] = array(
				'mode'      => $kind,
				'audience'  => 'owner_agent',
				'available' => array( __CLASS__, 'woo_ready' ),
				'stats'     => static function ( array $ctx ) use ( $kind ) { return BizCity_Woo_Pack_Exporter::stats( $kind ); },
				'page'      => static function ( array $ctx, int $after, int $limit ) use ( $kind ) { return BizCity_Woo_Pack_Exporter::page( $kind, $after, $limit ); },
			);
		}
		// `catalog` (mode stock, audience base: public facts a customer turn may read from the cell cache later).
		$exporters[ self::CATALOG_KIND ] = array(
			'mode'      => 'stock',
			'audience'  => 'base',
			'available' => array( __CLASS__, 'catalog_ready' ),
			'stats'     => static function ( array $ctx ) { return BizCity_Woo_Pack_Exporter::stats( BizCity_Woo_Pack_Exporter::CATALOG_KIND ); },
			'page'      => static function ( array $ctx, int $after, int $limit ) { return BizCity_Woo_Pack_Exporter::page( BizCity_Woo_Pack_Exporter::CATALOG_KIND, $after, $limit ); },
		);
		// `catalog_map` (mode stock, audience base, available catalog_ready + option ≠ off).
		$exporters[ self::CATALOG_MAP_KIND ] = array(
			'mode'      => 'stock',
			'audience'  => 'base',
			'available' => array( __CLASS__, 'catalog_map_ready' ),
			'stats'     => static function ( array $ctx ) { return BizCity_Woo_Pack_Exporter::stats( BizCity_Woo_Pack_Exporter::CATALOG_MAP_KIND ); },
			'page'      => static function ( array $ctx, int $after, int $limit ) { return BizCity_Woo_Pack_Exporter::page( BizCity_Woo_Pack_Exporter::CATALOG_MAP_KIND, $after, $limit ); },
		);
		return $exporters;
	}

	/** catalog_ready + the site option `bizcity_pack_catalog_map` is not `off` (default auto). */
	public static function catalog_map_ready(): bool {
		$opt = function_exists( 'get_option' ) ? (string) get_option( self::CATALOG_MAP_OPTION, 'auto' ) : 'auto';
		return 'off' !== $opt && ( isset( self::$readers['catalog_map_rows'] ) || self::catalog_ready() );
	}

	public static function catalog_ready(): bool {
		return isset( self::$readers['catalog_products'] ) || function_exists( 'wc_get_products' );
	}

	public static function woo_ready(): bool {
		return isset( self::$readers['paid_orders'] ) || function_exists( 'wc_get_orders' );
	}

	/* ── invalidation (CL-2) ──────────────────────────────────────── */

	public static function on_order_change(): void {
		self::flush( array( 'sales', 'orders', 'customers' ) );
	}

	public static function on_stock_change(): void {
		self::flush( array( 'stock', self::CATALOG_KIND ) );
	}

	public static function on_product_change(): void {
		self::flush( array( self::CATALOG_KIND ) );
	}

	/**
	 * Drop the cached items and tell the cells (debounced by BizCity_Zalo_Pack_Invalidate). `customers` belongs to
	 * BizCity_CRM_Customers_Pack (core/crm): its cache is dropped there, and this one `packs_changed` call still names it.
	 */
	public static function flush( array $kinds ): void {
		if ( in_array( 'customers', $kinds, true ) && class_exists( 'BizCity_CRM_Customers_Pack' ) ) {
			BizCity_CRM_Customers_Pack::flush( false );
		}
		foreach ( $kinds as $k ) {
			if ( 'customers' === $k ) {
				continue;
			}
			unset( self::$memo[ $k ] );
			if ( function_exists( 'delete_transient' ) ) {
				delete_transient( self::cache_key( $k ) );
			}
		}
		do_action( 'bizcity_twin_agent_packs_changed', array_values( $kinds ) );
	}

	/* ── stats / page ─────────────────────────────────────────────── */

	/** `$person` stays in the version hash (always 0 here) so an unchanged pack keeps the version it had before the move. */
	public static function stats( string $kind, int $person = 0 ): array {
		$c    = self::computed( $kind );
		$json = (string) wp_json_encode( $c['items'] );
		return array( 'version' => 'v-' . substr( sha1( $kind . '|' . $person . '|' . $json ), 0, 8 ), 'as_of' => $c['as_of'], 'bytes' => strlen( $json ), 'items' => count( $c['items'] ) );
	}

	/** Offset paging (`after_id` = number of items already sent). */
	public static function page( string $kind, int $after, int $limit ): array {
		$items = self::computed( $kind )['items'];
		$after = max( 0, $after );
		$slice = array_slice( $items, $after, $limit );
		$last  = $after + count( $slice );
		return array( 'items' => $slice, 'last_id' => $last, 'more' => $last < count( $items ) );
	}

	/** @return array{as_of:string,items:array} */
	private static function computed( string $kind ): array {
		if ( isset( self::$memo[ $kind ] ) ) {
			return self::$memo[ $kind ];
		}
		$cache = ! isset( self::$readers['cache'] ) || false !== self::$readers['cache'];
		if ( $cache && function_exists( 'get_transient' ) ) {
			$hit = get_transient( self::cache_key( $kind ) );
			if ( is_array( $hit ) && isset( $hit['items'] ) ) {
				return self::$memo[ $kind ] = $hit;
			}
		}
		switch ( $kind ) {
			case 'sales':
				$items = self::sales_items();
				break;
			case 'orders':
				$items = self::order_items();
				break;
			case 'stock':
				$items = self::stock_items();
				break;
			case self::CATALOG_KIND:
				$items = self::catalog_items();
				break;
			case self::CATALOG_MAP_KIND:
				$items = self::catalog_map_items();
				break;
			default:
				$items = array();
		}
		$out = array( 'as_of' => gmdate( 'Y-m-d\TH:i:s\Z', self::now() ), 'items' => $items );
		if ( $cache && function_exists( 'set_transient' ) ) {
			set_transient( self::cache_key( $kind ), $out, self::CACHE_TTL );
		}
		return self::$memo[ $kind ] = $out;
	}

	/** Test seam reset. */
	public static function reset(): void {
		self::$memo                = array();
		self::$catalog_map_flushed = false;
	}

	/** Paid statuses of the shared money rule (BizCity_Woo_Reports_Bridge), in wc_get_orders form. */
	private static function paid_statuses(): array {
		return class_exists( 'BizCity_Woo_Reports_Bridge' ) ? BizCity_Woo_Reports_Bridge::paid_statuses() : self::PAID_STATUSES;
	}

	/* ── sales ────────────────────────────────────────────────────── */

	public static function sales_items(): array {
		$now      = self::now();
		$currency = self::currency();
		$days     = array();
		for ( $i = self::DAYS - 1; $i >= 0; $i-- ) {
			$days[ self::local_date( $now - $i * DAY_IN_SECONDS, 'Y-m-d' ) ] = self::zero();
		}
		$months = array();
		$first  = (int) strtotime( self::local_date( $now, 'Y-m-01' ) . ' 00:00:00' );
		for ( $i = self::MONTHS - 1; $i >= 0; $i-- ) {
			$months[ self::local_date( (int) strtotime( "-{$i} month", $first ), 'Y-m' ) ] = self::zero();
		}
		$products  = array();
		$day_floor = array_key_first( $days );
		foreach ( self::paid_orders( (int) strtotime( array_key_first( $months ) . '-01 00:00:00' ) ) as $o ) {
			$day = self::local_date( (int) $o['ts'], 'Y-m-d' );
			$mon = substr( $day, 0, 7 );
			if ( isset( $days[ $day ] ) ) {
				self::add_order( $days[ $day ], $o );
			}
			if ( isset( $months[ $mon ] ) ) {
				self::add_order( $months[ $mon ], $o );
			}
			if ( $day >= $day_floor ) {
				foreach ( (array) $o['lines'] as $l ) {
					$pid = (int) ( $l['product_id'] ?? 0 );
					if ( $pid <= 0 ) {
						continue;
					}
					if ( ! isset( $products[ $pid ] ) ) {
						$products[ $pid ] = array( 'row' => 'top_product', 'product_id' => $pid, 'name' => self::text( (string) ( $l['name'] ?? '' ), 200 ), 'qty' => 0, 'gross' => 0.0, 'currency' => $currency );
					}
					$products[ $pid ]['qty']   += (int) ( $l['qty'] ?? 0 );
					$products[ $pid ]['gross'] += (float) ( $l['gross'] ?? 0 );
				}
			}
		}
		$items = array();
		foreach ( array( 'day' => $days, 'month' => $months ) as $grain => $rows ) {
			foreach ( $rows as $date => $r ) {
				$net     = max( 0.0, $r['gross'] - $r['refunds'] );
				$items[] = array(
					'row' => 'period', 'grain' => $grain, 'date' => $date,
					'order_count' => $r['order_count'], 'paid_count' => $r['paid_count'],
					'gross' => self::money( $r['gross'] ), 'net' => self::money( $net ), 'refunds' => self::money( $r['refunds'] ),
					'aov' => $r['order_count'] > 0 ? self::money( $net / $r['order_count'] ) : 0, 'currency' => $currency,
				);
			}
		}
		usort( $products, static function ( $a, $b ) { return $b['gross'] <=> $a['gross']; } );
		foreach ( array_slice( $products, 0, self::TOP_PRODUCTS ) as $p ) {
			$p['gross'] = self::money( $p['gross'] );
			$items[]    = $p;
		}
		return $items;
	}

	private static function zero(): array {
		return array( 'order_count' => 0, 'paid_count' => 0, 'gross' => 0.0, 'refunds' => 0.0 );
	}

	private static function add_order( array &$bucket, array $o ): void {
		$bucket['order_count']++;
		$bucket['paid_count'] += ! empty( $o['paid'] ) ? 1 : 0;
		$bucket['gross']      += (float) $o['gross'];
		$bucket['refunds']    += (float) $o['refunds'];
	}

	/** @return list<array{ts:int,gross:float,refunds:float,paid:bool,lines:array}> */
	private static function paid_orders( int $from_ts ): array {
		if ( isset( self::$readers['paid_orders'] ) ) {
			return (array) call_user_func( self::$readers['paid_orders'], $from_ts );
		}
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return array();
		}
		$out = array();
		for ( $page = 1; $page <= self::ORDER_PAGES; $page++ ) {
			$res    = wc_get_orders( array( 'limit' => self::ORDER_PAGE, 'page' => $page, 'paginate' => true, 'status' => self::paid_statuses(), 'type' => 'shop_order', 'date_created' => '>=' . $from_ts, 'return' => 'objects' ) );
			$orders = is_object( $res ) && isset( $res->orders ) ? (array) $res->orders : (array) $res;
			foreach ( $orders as $order ) {
				if ( ! is_object( $order ) || ! method_exists( $order, 'get_total' ) ) {
					continue;
				}
				$dt    = $order->get_date_created();
				$lines = array();
				foreach ( $order->get_items() as $item ) {
					$lines[] = array( 'product_id' => (int) $item->get_product_id(), 'name' => (string) $item->get_name(), 'qty' => (int) $item->get_quantity(), 'gross' => (float) $item->get_total() );
				}
				$out[] = array( 'ts' => $dt ? (int) $dt->getTimestamp() : 0, 'gross' => (float) $order->get_total(), 'refunds' => (float) $order->get_total_refunded(), 'paid' => $order->is_paid() || 'completed' === $order->get_status(), 'lines' => $lines );
			}
			if ( count( $orders ) < self::ORDER_PAGE ) {
				break;
			}
		}
		return $out;
	}

	/* ── orders ───────────────────────────────────────────────────── */

	public static function order_items(): array {
		$items = array();
		foreach ( self::recent_orders( self::ORDERS_LAST ) as $o ) {
			$label   = trim( trim( (string) $o['first'] . ' ' . (string) $o['last'] ) . ' ' . self::mask_phone( (string) $o['phone'] ) );
			$items[] = array(
				'order_ref'             => self::text( (string) $o['ref'], 40 ),
				'date'                  => $o['ts'] > 0 ? gmdate( 'Y-m-d\TH:i:s\Z', (int) $o['ts'] ) : '',
				'total'                 => self::money( (float) $o['total'] ),
				'currency'              => (string) ( $o['currency'] ?: self::currency() ),
				'status'                => sanitize_key( (string) $o['status'] ),
				'customer_label_masked' => self::text( '' !== $label ? $label : 'Khách', 120 ),
				'items_count'           => (int) $o['items'],
			);
		}
		return $items;
	}

	private static function recent_orders( int $n ): array {
		if ( isset( self::$readers['recent_orders'] ) ) {
			return (array) call_user_func( self::$readers['recent_orders'], $n );
		}
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return array();
		}
		$out = array();
		foreach ( (array) wc_get_orders( array( 'limit' => $n, 'orderby' => 'date', 'order' => 'DESC', 'type' => 'shop_order', 'return' => 'objects' ) ) as $order ) {
			if ( ! is_object( $order ) || ! method_exists( $order, 'get_id' ) ) {
				continue;
			}
			$dt    = $order->get_date_created();
			$out[] = array( 'ref' => (string) $order->get_order_number(), 'ts' => $dt ? (int) $dt->getTimestamp() : 0, 'total' => (float) $order->get_total(), 'currency' => (string) $order->get_currency(), 'status' => (string) $order->get_status(), 'first' => (string) $order->get_billing_first_name(), 'last' => (string) $order->get_billing_last_name(), 'phone' => (string) $order->get_billing_phone(), 'items' => (int) $order->get_item_count() );
		}
		return $out;
	}

	/* ── stock ────────────────────────────────────────────────────── */

	public static function stock_items(): array {
		$items = array();
		foreach ( self::products( self::STOCK_SCAN ) as $p ) {
			$qty = null === $p['qty'] ? null : (float) $p['qty'];
			$out = 'outofstock' === $p['status'] || ( $p['managing'] && null !== $qty && $qty <= 0 );
			$low = ! $out && $p['managing'] && null !== $qty && $qty <= self::LOW_STOCK;
			if ( $out || $low ) {
				$items[] = array( 'product_id' => (int) $p['id'], 'name' => self::text( (string) $p['name'], 200 ), 'stock_qty' => null === $qty ? 0 : (int) $qty, 'status' => $out ? 'out' : 'low' );
			}
		}
		// Out of stock first, then the lowest quantity.
		usort( $items, static function ( $a, $b ) {
			if ( $a['status'] !== $b['status'] ) {
				return 'out' === $a['status'] ? -1 : 1;
			}
			return $a['stock_qty'] <=> $b['stock_qty'];
		} );
		return array_slice( $items, 0, self::STOCK_MAX );
	}

	private static function products( int $n ): array {
		if ( isset( self::$readers['products'] ) ) {
			return (array) call_user_func( self::$readers['products'], $n );
		}
		if ( ! function_exists( 'wc_get_products' ) ) {
			return array();
		}
		$out = array();
		foreach ( (array) wc_get_products( array( 'limit' => $n, 'status' => 'publish', 'return' => 'objects' ) ) as $p ) {
			if ( is_object( $p ) && method_exists( $p, 'managing_stock' ) ) {
				$out[] = array( 'id' => (int) $p->get_id(), 'name' => (string) $p->get_name(), 'managing' => (bool) $p->managing_stock(), 'qty' => $p->get_stock_quantity(), 'status' => (string) $p->get_stock_status() );
			}
		}
		return $out;
	}

	/* ── catalog (PHASE-0.88 L2-6) ────────────────────────────────── */

	/**
	 * Public product facts, ≤ CATALOG_MAX items, by id: id, name, price, currency, stock label (instock / low / out — never the
	 * exact quantity), short description (≤ CATALOG_DESC chars, tags stripped), permalink.
	 */
	public static function catalog_items(): array {
		$currency = self::currency();
		$items    = array();
		foreach ( self::catalog_products( self::CATALOG_MAX ) as $p ) {
			$id = (int) ( $p['id'] ?? 0 );
			if ( $id <= 0 ) {
				continue;
			}
			$qty    = isset( $p['qty'] ) && null !== $p['qty'] ? (float) $p['qty'] : null;
			$manage = ! empty( $p['managing'] );
			$status = (string) ( $p['status'] ?? 'instock' );
			$label  = 'instock';
			if ( 'outofstock' === $status || ( $manage && null !== $qty && $qty <= 0 ) ) {
				$label = 'out';
			} elseif ( $manage && null !== $qty && $qty <= self::LOW_STOCK ) {
				$label = 'low';
			}
			$price   = isset( $p['price'] ) && '' !== (string) $p['price'] ? self::money( (float) $p['price'] ) : null;
			$items[] = array(
				'product_id'        => $id,
				'name'              => self::text( (string) ( $p['name'] ?? '' ), 200 ),
				'price'             => $price,
				'currency'          => $currency,
				'stock'             => $label,
				'short_description' => self::text( (string) ( $p['short'] ?? '' ), self::CATALOG_DESC ),
				'permalink'         => function_exists( 'esc_url_raw' ) ? (string) esc_url_raw( (string) ( $p['permalink'] ?? '' ) ) : (string) ( $p['permalink'] ?? '' ),
			);
		}
		usort( $items, static function ( $a, $b ) { return $a['product_id'] <=> $b['product_id']; } );
		return array_slice( $items, 0, self::CATALOG_MAX );
	}

	/** @return list<array{id:int,name:string,price:mixed,managing:bool,qty:mixed,status:string,short:string,permalink:string}> */
	private static function catalog_products( int $n ): array {
		if ( isset( self::$readers['catalog_products'] ) ) {
			return (array) call_user_func( self::$readers['catalog_products'], $n );
		}
		if ( ! function_exists( 'wc_get_products' ) ) {
			return array();
		}
		$out = array();
		foreach ( (array) wc_get_products( array( 'limit' => $n, 'status' => 'publish', 'visibility' => 'visible', 'orderby' => 'ID', 'order' => 'ASC', 'return' => 'objects' ) ) as $p ) {
			if ( ! is_object( $p ) || ! method_exists( $p, 'get_price' ) ) {
				continue;
			}
			$short = (string) $p->get_short_description();
			if ( '' === trim( $short ) ) {
				$short = (string) $p->get_description();
			}
			$out[] = array(
				'id'        => (int) $p->get_id(),
				'name'      => (string) $p->get_name(),
				'price'     => $p->get_price(),
				'managing'  => (bool) $p->managing_stock(),
				'qty'       => $p->get_stock_quantity(),
				'status'    => (string) $p->get_stock_status(),
				'short'     => $short,
				'permalink' => (string) $p->get_permalink(),
			);
		}
		return $out;
	}

	/* ── catalog_map (PHASE-0.95 S95-F1) ──────────────────────────── */

	/**
	 * [2026-10-09 03:36 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.95-S95-F1 — one item per non-empty product category:
	 * {id, name, parent, count, in_stock, price_min, price_max, facets{label: [≤ 8 values]} (≤ 6), ask_first[≤ 3], strategic_count}.
	 * Prices and stock come from ONE query grouped by category (no wc_get_product loop); no product id or name is ever in it.
	 * ≤ 200 groups (largest first, then by id), ≤ 32 KB (facets shrink first, then the smallest groups go).
	 */
	public static function catalog_map_items(): array {
		$src   = self::catalog_map_rows();
		$agg   = (array) ( $src['agg'] ?? array() );
		$facet = (array) ( $src['facets'] ?? array() );
		$ask   = (array) ( $src['ask_first'] ?? array() );
		$strat = (array) ( $src['strategic'] ?? array() );
		$items = array();
		foreach ( (array) ( $src['groups'] ?? array() ) as $g ) {
			$g   = (array) $g;
			$id  = (int) ( $g['id'] ?? 0 );
			$a   = (array) ( $agg[ $id ] ?? array() );
			$cnt = (int) ( $a['count'] ?? 0 );
			if ( $id <= 0 || $cnt <= 0 ) {
				continue; // empty groups are left out
			}
			$facets = array();
			foreach ( (array) ( $facet[ $id ] ?? array() ) as $label => $values ) {
				$vals = array();
				foreach ( (array) $values as $v ) {
					$v = self::text( (string) $v, 40 );
					if ( '' !== $v && ! in_array( $v, $vals, true ) ) {
						$vals[] = $v;
					}
					if ( count( $vals ) >= self::CATALOG_MAP_VALUES ) {
						break;
					}
				}
				if ( $vals ) {
					$facets[ self::text( (string) $label, 40 ) ] = $vals;
				}
				if ( count( $facets ) >= self::CATALOG_MAP_FACETS ) {
					break;
				}
			}
			$min = isset( $a['price_min'] ) && is_numeric( $a['price_min'] ) ? self::money( (float) $a['price_min'] ) : null;
			$max = isset( $a['price_max'] ) && is_numeric( $a['price_max'] ) ? self::money( (float) $a['price_max'] ) : null;
			$items[] = array(
				'id'              => $id,
				'name'            => self::text( (string) ( $g['name'] ?? '' ), 120 ),
				'parent'          => (int) ( $g['parent'] ?? 0 ),
				'count'           => $cnt,
				'in_stock'        => min( $cnt, max( 0, (int) ( $a['in_stock'] ?? 0 ) ) ),
				'price_min'       => $min,
				'price_max'       => $max,
				'facets'          => $facets ? $facets : new stdClass(),
				'ask_first'       => array_slice( array_values( array_filter( array_map( static function ( $q ) { return self::text( (string) $q, 120 ); }, (array) ( $ask[ $id ] ?? array() ) ), 'strlen' ) ), 0, 3 ),
				'strategic_count' => max( 0, (int) ( $strat[ $id ] ?? 0 ) ),
			);
		}
		usort( $items, static function ( $a, $b ) { return array( $b['count'], $a['id'] ) <=> array( $a['count'], $b['id'] ); } );
		$items = array_slice( $items, 0, self::CATALOG_MAP_MAX );
		$size  = static function ( array $list ): int { return strlen( (string) wp_json_encode( $list ) ); };
		if ( $size( $items ) > self::CATALOG_MAP_BYTES ) {
			foreach ( $items as &$it ) {
				$it['facets'] = is_array( $it['facets'] ) ? array_map( static function ( $v ) { return array_slice( $v, 0, 3 ); }, array_slice( $it['facets'], 0, 3, true ) ) : $it['facets'];
			}
			unset( $it );
		}
		while ( count( $items ) > 1 && $size( $items ) > self::CATALOG_MAP_BYTES ) {
			array_pop( $items ); // smallest group last after the sort
		}
		usort( $items, static function ( $a, $b ) { return $a['id'] <=> $b['id']; } );
		return $items;
	}

	/**
	 * @return array{groups:list<array{id:int,name:string,parent:int}>,agg:array<int,array{count:int,in_stock:int,price_min:mixed,price_max:mixed}>,facets:array<int,array<string,string[]>>,ask_first:array<int,string[]>,strategic:array<int,int>}
	 */
	private static function catalog_map_rows(): array {
		if ( isset( self::$readers['catalog_map_rows'] ) ) {
			return (array) call_user_func( self::$readers['catalog_map_rows'] );
		}
		global $wpdb;
		if ( ! isset( $wpdb ) || ! function_exists( 'get_terms' ) || ( function_exists( 'taxonomy_exists' ) && ! taxonomy_exists( 'product_cat' ) ) ) {
			return array();
		}
		$groups = array();
		$terms  = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false, 'number' => 1000 ) );
		foreach ( is_array( $terms ) ? $terms : array() as $t ) {
			$groups[] = array( 'id' => (int) $t->term_id, 'name' => (string) $t->name, 'parent' => (int) $t->parent );
		}
		if ( ! $groups ) {
			return array();
		}
		$tr     = $wpdb->term_relationships;
		$tt     = $wpdb->term_taxonomy;
		$posts  = $wpdb->posts;
		$lookup = $wpdb->prefix . 'wc_product_meta_lookup';
		$base   = "FROM {$tr} tr INNER JOIN {$tt} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'product_cat' INNER JOIN {$posts} p ON p.ID = tr.object_id AND p.post_type = 'product' AND p.post_status = 'publish' AND p.post_password = ''";
		$has_lookup = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $lookup ) ) === $lookup;
		$agg = array();
		$sql = $has_lookup
			? "SELECT tt.term_id AS id, COUNT(*) AS cnt, SUM(CASE WHEN l.stock_status = 'instock' THEN 1 ELSE 0 END) AS in_stock, MIN(l.min_price) AS pmin, MAX(l.max_price) AS pmax {$base} LEFT JOIN {$lookup} l ON l.product_id = p.ID GROUP BY tt.term_id"
			: "SELECT tt.term_id AS id, COUNT(*) AS cnt, COUNT(*) AS in_stock, NULL AS pmin, NULL AS pmax {$base} GROUP BY tt.term_id";
		foreach ( (array) $wpdb->get_results( $sql, ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- only table names interpolated
			$agg[ (int) $r['id'] ] = array( 'count' => (int) $r['cnt'], 'in_stock' => (int) $r['in_stock'], 'price_min' => $r['pmin'], 'price_max' => $r['pmax'] );
		}
		$facets = array();
		$sql = "SELECT tt.term_id AS cat, att.taxonomy AS tax, t.name AS val, COUNT(*) AS n {$base} INNER JOIN {$tr} tr2 ON tr2.object_id = p.ID INNER JOIN {$tt} att ON att.term_taxonomy_id = tr2.term_taxonomy_id AND att.taxonomy LIKE 'pa\\_%' INNER JOIN {$wpdb->terms} t ON t.term_id = att.term_id GROUP BY tt.term_id, att.taxonomy, t.term_id ORDER BY tt.term_id, n DESC LIMIT 20000";
		foreach ( (array) $wpdb->get_results( $sql, ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$label = function_exists( 'wc_attribute_label' ) ? (string) wc_attribute_label( (string) $r['tax'] ) : (string) $r['tax'];
			$facets[ (int) $r['cat'] ][ $label ][] = (string) $r['val'];
		}
		$strategic = array();
		$like      = '%' . $wpdb->esc_like( 's:4:"tier";s:9:"strategic";' ) . '%';
		$sql       = $wpdb->prepare( "SELECT tt.term_id AS cat, COUNT(DISTINCT p.ID) AS n {$base} INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = %s AND pm.meta_value LIKE %s GROUP BY tt.term_id", self::ADVICE_META_KEY, $like ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( (array) $wpdb->get_results( $sql, ARRAY_A ) as $r ) {
			$strategic[ (int) $r['cat'] ] = (int) $r['n'];
		}
		$ask = array();
		foreach ( $groups as $g ) {
			if ( empty( $agg[ $g['id'] ] ) ) {
				continue;
			}
			$advice = class_exists( 'BizCity_Product_Advice' ) ? BizCity_Product_Advice::for_category( (int) $g['id'] ) : null;
			if ( $advice && ! empty( $advice['ask_first'] ) ) {
				$ask[ (int) $g['id'] ] = (array) $advice['ask_first'];
			}
		}
		return array( 'groups' => $groups, 'agg' => $agg, 'facets' => $facets, 'ask_first' => $ask, 'strategic' => $strategic );
	}


	/* ── order facts per CRM contact (read by the core `customers` pack) ── */

	/**
	 * Woo orders linked to CRM contacts by the order meta `_bizcity_crm_contact_id` (written by the CRM order adapter and by the
	 * MCP order tools). Same rule as the CRM pipeline facts loader (R-PIPE-2 v1.1): a cancelled / failed / binned order is not
	 * counted; revenue = total of the paid orders. Contacts without an order are left out.
	 *
	 * @param int[] $ids
	 * @return array<int,array{ordered:int,revenue:float,last_order_ts:int}>
	 */
	public static function contact_order_facts( array $ids ): array {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		if ( ! $ids ) {
			return array();
		}
		if ( isset( self::$readers['contact_orders'] ) ) {
			return (array) call_user_func( self::$readers['contact_orders'], $ids );
		}
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return array();
		}
		$out = array();
		foreach ( array_chunk( $ids, 200 ) as $chunk ) {
			$orders = wc_get_orders( array(
				'limit' => 2000, 'return' => 'objects', 'orderby' => 'date', 'order' => 'ASC',
				'meta_query' => array( array( 'key' => '_bizcity_crm_contact_id', 'value' => array_map( 'strval', $chunk ), 'compare' => 'IN' ) ),
			) );
			foreach ( (array) $orders as $order ) {
				if ( ! is_object( $order ) || ! method_exists( $order, 'get_meta' ) ) {
					continue;
				}
				$cid = (int) $order->get_meta( '_bizcity_crm_contact_id' );
				if ( ! in_array( $cid, $chunk, true ) ) {
					continue;
				}
				$status = method_exists( $order, 'get_status' ) ? (string) $order->get_status() : '';
				if ( in_array( $status, self::CONTACT_ORDER_SKIP, true ) ) {
					continue;
				}
				if ( ! isset( $out[ $cid ] ) ) {
					$out[ $cid ] = array( 'ordered' => 0, 'revenue' => 0.0, 'last_order_ts' => 0 );
				}
				$created = $order->get_date_created();
				$ts      = $created ? (int) $created->getTimestamp() : 0;
				$out[ $cid ]['ordered']++;
				$out[ $cid ]['last_order_ts'] = max( $out[ $cid ]['last_order_ts'], $ts );
				if ( method_exists( $order, 'is_paid' ) && $order->is_paid() ) {
					$out[ $cid ]['revenue'] += (float) $order->get_total();
				}
			}
		}
		return $out;
	}

	/* ── helpers ──────────────────────────────────────────────────── */

	/** "…" + last 3 digits; fewer than 4 digits is not a phone ⇒ ''. Same rule as the cell (pack-items.ts maskPhone). */
	public static function mask_phone( string $phone ): string {
		$d = preg_replace( '/\D+/', '', $phone );
		return strlen( (string) $d ) >= 4 ? '…' . substr( (string) $d, -3 ) : '';
	}

	private static function text( string $s, int $max ): string {
		$s = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $s ) ) );
		return function_exists( 'mb_substr' ) ? mb_substr( $s, 0, $max ) : substr( $s, 0, $max );
	}

	/** VND has no minor unit; other currencies keep 2 decimals. */
	private static function money( float $v ) {
		return 'VND' === self::currency() ? (int) round( $v ) : round( $v, 2 );
	}

	private static function currency(): string {
		if ( isset( self::$readers['currency'] ) ) {
			return (string) call_user_func( self::$readers['currency'] );
		}
		return function_exists( 'get_woocommerce_currency' ) ? (string) get_woocommerce_currency() : 'VND';
	}

	private static function local_date( int $ts, string $format ): string {
		return function_exists( 'wp_date' ) ? (string) wp_date( $format, $ts ) : gmdate( $format, $ts );
	}

	private static function now(): int {
		return isset( self::$readers['now'] ) ? (int) call_user_func( self::$readers['now'] ) : time();
	}

	/** Same key as the old plugin exporter (`bizcity_pack_biz_<kind>_<blog>`): a running site keeps its cache. */
	private static function cache_key( string $kind ): string {
		$blog = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
		return self::CACHE_PREFIX . $kind . '_' . $blog;
	}
}
