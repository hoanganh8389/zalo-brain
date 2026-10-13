<?php
/**
 * Create a plan order for ONE number (PHASE-0.108 S108-L19.1, 20-REVENUE-PER-NUMBER.md §3.2, 24-TECH-DESIGN-REVENUE.md §4.1).
 *
 * Two doors, one function: `admin` (a manager sells to any number of the site) and `self` (a logged-in end user buys for a number THEY own).
 * Everything that matters comes from the SERVER — the number is looked up in the account mirror, the price/months/allowance come from the package,
 * the floor is re-read; whatever the request says about price is ignored. Order of guards (first failure wins):
 *   package exists+enabled → number exists → (self) viewer owns it → payer valid → currency VND → TTCK on → price ≥ floor → (self) rate limit → reuse a pending order → create.
 * A wrong owner answers `forbidden` exactly like a missing number, so a customer cannot probe which numbers exist.
 *
 * Money: the order line carries its own total (the package price at creation) — a later price edit never changes an order that already exists.
 * The paid path is BizCity_Usage_Plan_Woo (TTCK ⇒ payment_complete ⇒ extend by `_bizcity_plan_months`), unchanged.
 *
 * Test seams ($readers): account(id): ?array{account_id,owner_user_id,label} · package(code): ?array · floor(): array · currency(): string · ttck_enabled(): bool ·
 *   user_exists(id): bool · find_pending(account, code): ?array{order_id,pay_url} · rate(user): int (calls this hour; increments) · create_order(spec): array{order_id,pay_url}|WP_Error-like · now(): int · plan(account): ?array
 *
 * // [2026-10-13 09:12 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.108 S108-L19.1.
 *
 * @package BizCity_Twin_AI
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_Usage_Plan_Orders {

	const CHANNELS      = array( 'admin', 'self' );
	const RATE_PER_HOUR = 30;
	const META_ACCOUNT  = '_bizcity_plan_account_id';

	/** @var array<string,callable> */
	public static $readers = array();

	/**
	 * @return array{ok:true,order_id:int,pay_url:string,amount_vnd:int,reused:bool,package:string,months:int,floor_vnd:?int}|array{ok:false,code:string,message:string,http:int,gap_vnd?:int}
	 */
	public static function create( string $account_id, string $package_code, int $creator_id, string $channel, int $payer_user_id = 0 ): array {
		if ( ! in_array( $channel, self::CHANNELS, true ) ) {
			return self::fail( 'bad_channel', 'Kênh tạo đơn không hợp lệ.', 400 );
		}
		$pkg = self::package( $package_code );
		if ( ! $pkg || empty( $pkg['enabled'] ) || (int) $pkg['price_vnd'] <= 0 ) {
			return self::fail( 'no_package', 'Gói này hiện không bán.', 422 );
		}
		$acc = self::account( $account_id );
		if ( ! $acc ) {
			return 'self' === $channel ? self::forbidden() : self::fail( 'not_found', 'Không tìm thấy số này.', 404 );
		}
		$owner = (int) $acc['owner_user_id'];
		if ( 'self' === $channel ) {
			if ( $creator_id <= 0 || $owner <= 0 || $owner !== $creator_id ) {
				return self::forbidden(); // same answer for "not yours" and "does not exist"
			}
			$payer_user_id = $creator_id;
		} else {
			$payer_user_id = $payer_user_id > 0 ? $payer_user_id : $owner;
			if ( $payer_user_id > 0 && ! self::user_exists( $payer_user_id ) ) {
				return self::fail( 'invalid_payer', 'Người trả không tồn tại trên site này.', 422 );
			}
		}
		if ( 'VND' !== strtoupper( self::currency() ) ) {
			return self::fail( 'currency', 'Cửa hàng không dùng đồng VND nên không thể tạo đơn gói.', 422 );
		}
		if ( ! self::ttck_enabled() ) {
			return self::fail( 'ttck_off', 'Chưa bật thu tiền tự động (chuyển khoản TTCK). Xem hướng dẫn bật rồi thử lại.', 422 );
		}
		$floor_state = self::floor();
		$chk         = class_exists( 'BizCity_Usage_Plan_Floor' ) ? BizCity_Usage_Plan_Floor::check( $pkg, $floor_state ) : array( 'ok' => true, 'floor_vnd' => null, 'gap_vnd' => 0 );
		if ( ! $chk['ok'] ) {
			$r = self::fail( 'below_floor', sprintf( 'Giá gói %d tháng thấp hơn giá sàn %s ₫. Sửa ở Bảng giá.', (int) $pkg['months'], number_format( (int) $chk['floor_vnd'], 0, ',', '.' ) ), 422 );
			$r['gap_vnd'] = (int) $chk['gap_vnd'];
			return $r;
		}
		$pending = self::find_pending( $account_id, $package_code );
		if ( $pending ) {
			return array( 'ok' => true, 'order_id' => (int) $pending['order_id'], 'pay_url' => (string) $pending['pay_url'], 'amount_vnd' => (int) $pkg['price_vnd'],
				'reused' => true, 'package' => $package_code, 'months' => (int) $pkg['months'], 'floor_vnd' => $chk['floor_vnd'] );
		}
		if ( 'self' === $channel && self::rate( $creator_id ) > self::RATE_PER_HOUR ) {
			return self::fail( 'rate_limited', 'Bạn thao tác quá nhanh. Thử lại sau ít phút.', 429 );
		}
		$spec = array(
			'account_id' => $account_id, 'package' => $pkg, 'price_vnd' => (int) $pkg['price_vnd'], 'payer_user_id' => $payer_user_id, 'created_by' => $creator_id,
			'channel' => $channel, 'floor_vnd' => (int) ( $chk['floor_vnd'] ?? 0 ), 'floor_version' => (string) ( $floor_state['version'] ?? '' ),
		);
		$o = self::create_order( $spec );
		if ( ! is_array( $o ) || empty( $o['order_id'] ) || '' === (string) ( $o['pay_url'] ?? '' ) ) {
			return self::fail( 'gateway_degraded', 'Không tạo được đơn. Thử lại sau.', 502 );
		}
		return array( 'ok' => true, 'order_id' => (int) $o['order_id'], 'pay_url' => (string) $o['pay_url'], 'amount_vnd' => (int) $pkg['price_vnd'], 'reused' => false,
			'package' => $package_code, 'months' => (int) $pkg['months'], 'floor_vnd' => $chk['floor_vnd'] );
	}

	/**
	 * What buying this package would do to this number's plan (screen ③ step 3, ⑥ step 2). Dates are what extend_pro will compute: from the LATER of now and the current end.
	 *
	 * @return array{valid_from:string,valid_until_after:string,allowance:int,amount_vnd:int,months:int}|null null when the package or the number is unknown
	 */
	public static function preview( string $account_id, string $package_code ): ?array {
		$pkg = self::package( $package_code );
		if ( ! $pkg || ! self::account( $account_id ) ) {
			return null;
		}
		$now  = self::now();
		$plan = self::plan( $account_id );
		$cur  = $plan && ! empty( $plan['valid_until'] ) ? BizCity_Usage_Plans::ts( $plan['valid_until'] ) : 0;
		$from = max( $now, $cur );
		return array(
			'valid_from'        => gmdate( 'Y-m-d\TH:i:s\Z', $from ),
			'valid_until_after' => gmdate( 'Y-m-d\TH:i:s\Z', BizCity_Usage_Plans::ts( BizCity_Usage_Plans::add_months( $from, (int) $pkg['months'] ) ) ),
			'allowance'         => (int) $pkg['allowance'],
			'amount_vnd'        => (int) $pkg['price_vnd'],
			'months'            => (int) $pkg['months'],
		);
	}

	/* ── failures ──────────────────────────────────────────────────── */

	private static function fail( string $code, string $message, int $http ): array {
		return array( 'ok' => false, 'code' => $code, 'message' => $message, 'http' => $http );
	}

	private static function forbidden(): array {
		return self::fail( 'forbidden', 'Bạn không có quyền với số này.', 403 );
	}

	/* ── seams (production implementation) ─────────────────────────── */

	private static function package( string $code ): ?array {
		if ( isset( self::$readers['package'] ) ) {
			$p = call_user_func( self::$readers['package'], $code );
			return is_array( $p ) ? $p : null;
		}
		return class_exists( 'BizCity_Usage_Plan_Packages' ) ? BizCity_Usage_Plan_Packages::item( $code ) : null;
	}

	/** @return array{account_id:string,owner_user_id:int,label:string}|null */
	private static function account( string $id ): ?array {
		if ( isset( self::$readers['account'] ) ) {
			$a = call_user_func( self::$readers['account'], $id );
			return is_array( $a ) ? $a : null;
		}
		$clean = class_exists( 'BizCity_Usage_Ingest' ) ? BizCity_Usage_Ingest::clean_id( $id ) : $id;
		if ( '' === $clean || ! class_exists( 'BizCity_Zalo_Mapping_Repo' ) ) {
			return null;
		}
		$row = BizCity_Zalo_Mapping_Repo::find_account_by_bridge_id( 'personal', $clean );
		if ( ! is_array( $row ) || empty( $row ) ) {
			return null;
		}
		return array( 'account_id' => $clean, 'owner_user_id' => (int) ( $row['owner_user_id'] ?? 0 ), 'label' => trim( (string) ( $row['label'] ?? $row['account_name'] ?? '' ) ) );
	}

	private static function plan( string $account_id ): ?array {
		if ( isset( self::$readers['plan'] ) ) {
			$p = call_user_func( self::$readers['plan'], $account_id );
			return is_array( $p ) ? $p : null;
		}
		return BizCity_Usage_Plans::get( BizCity_Usage_Plans::PLATFORM, $account_id );
	}

	private static function floor(): array {
		if ( isset( self::$readers['floor'] ) ) {
			return (array) call_user_func( self::$readers['floor'] );
		}
		return class_exists( 'BizCity_Usage_Plan_Floor' ) ? BizCity_Usage_Plan_Floor::read() : array( 'state' => 'none', 'floor' => null, 'version' => '' );
	}

	private static function currency(): string {
		if ( isset( self::$readers['currency'] ) ) {
			return (string) call_user_func( self::$readers['currency'] );
		}
		return function_exists( 'get_woocommerce_currency' ) ? (string) get_woocommerce_currency() : '';
	}

	/** TTCK (plugin thanh-toan-chuyen-khoan) has its bank-transfer gateway switched on. */
	public static function ttck_enabled(): bool {
		if ( isset( self::$readers['ttck_enabled'] ) ) {
			return (bool) call_user_func( self::$readers['ttck_enabled'] );
		}
		if ( ! class_exists( 'TTCKPayment' ) || ! method_exists( 'TTCKPayment', 'get_settings' ) ) {
			return false;
		}
		$s = TTCKPayment::get_settings();
		return is_array( $s ) && 'yes' === ( $s['bank_transfer']['enabled'] ?? '' );
	}

	private static function user_exists( int $id ): bool {
		if ( isset( self::$readers['user_exists'] ) ) {
			return (bool) call_user_func( self::$readers['user_exists'], $id );
		}
		return function_exists( 'get_userdata' ) && false !== get_userdata( $id );
	}

	/** @return array{order_id:int,pay_url:string}|null */
	private static function find_pending( string $account_id, string $code ): ?array {
		if ( isset( self::$readers['find_pending'] ) ) {
			$p = call_user_func( self::$readers['find_pending'], $account_id, $code );
			return is_array( $p ) ? $p : null;
		}
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return null;
		}
		$orders = wc_get_orders( array(
			'limit' => 1, 'status' => array( 'pending' ), 'orderby' => 'date', 'order' => 'DESC', 'date_created' => '>' . ( self::now() - DAY_IN_SECONDS ),
			'meta_query' => array( 'relation' => 'AND', array( 'key' => self::META_ACCOUNT, 'value' => $account_id ), array( 'key' => '_bizcity_plan_package', 'value' => $code ) ),
		) );
		if ( empty( $orders ) ) {
			return null;
		}
		return array( 'order_id' => (int) $orders[0]->get_id(), 'pay_url' => (string) $orders[0]->get_checkout_payment_url() );
	}

	/** Counts this call and returns how many the user made in the last hour. */
	private static function rate( int $user ): int {
		if ( isset( self::$readers['rate'] ) ) {
			return (int) call_user_func( self::$readers['rate'], $user );
		}
		if ( ! function_exists( 'get_transient' ) ) {
			return 0;
		}
		$k = 'bizcity_plan_order_rate_' . $user;
		$n = (int) get_transient( $k ) + 1;
		set_transient( $k, $n, HOUR_IN_SECONDS );
		return $n;
	}

	/** @return array{order_id:int,pay_url:string}|null */
	private static function create_order( array $s ): ?array {
		if ( isset( self::$readers['create_order'] ) ) {
			$o = call_user_func( self::$readers['create_order'], $s );
			return is_array( $o ) ? $o : null;
		}
		if ( ! function_exists( 'wc_create_order' ) || ! function_exists( 'wc_get_product' ) ) {
			return null;
		}
		$product = wc_get_product( (int) ( $s['package']['product_id'] ?? 0 ) );
		if ( ! $product ) {
			return null; // the package was never synced to a product (Woo was off when the price list was saved)
		}
		$order = wc_create_order( array( 'customer_id' => (int) $s['payer_user_id'], 'created_via' => 'bizcity_plan' ) );
		if ( ! $order || is_wp_error( $order ) ) {
			return null;
		}
		$price = (int) $s['price_vnd'];
		$order->add_product( $product, 1, array( 'subtotal' => $price, 'total' => $price ) ); // own total: a later price edit never changes this order
		$order->update_meta_data( self::META_ACCOUNT, (string) $s['account_id'] );
		$order->update_meta_data( '_bizcity_plan_package', (string) $s['package']['code'] );
		$order->update_meta_data( '_bizcity_plan_months', (int) $s['package']['months'] );
		$order->update_meta_data( '_bizcity_plan_allowance', (int) $s['package']['allowance'] );
		$order->update_meta_data( '_bizcity_plan_price_vnd', $price );
		$order->update_meta_data( '_bizcity_plan_floor_vnd', (int) $s['floor_vnd'] );
		$order->update_meta_data( '_bizcity_plan_floor_version', (string) $s['floor_version'] );
		$order->update_meta_data( '_bizcity_plan_created_by', (int) $s['created_by'] );
		$order->update_meta_data( '_bizcity_plan_channel', (string) $s['channel'] );
		$user = (int) $s['payer_user_id'] > 0 && function_exists( 'get_userdata' ) ? get_userdata( (int) $s['payer_user_id'] ) : false;
		if ( $user ) {
			$order->set_billing_email( (string) $user->user_email );
			$order->set_billing_first_name( (string) $user->display_name );
		}
		$order->calculate_totals();
		$order->set_status( 'pending' );
		$order->save();
		return array( 'order_id' => (int) $order->get_id(), 'pay_url' => (string) $order->get_checkout_payment_url() );
	}

	private static function now(): int {
		return isset( self::$readers['now'] ) ? (int) call_user_func( self::$readers['now'] ) : time();
	}
}
