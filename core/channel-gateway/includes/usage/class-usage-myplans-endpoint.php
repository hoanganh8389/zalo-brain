<?php
/**
 * "Gói Zalo của tôi" — the END USER's screen ⑥, as a WooCommerce My Account endpoint `zalo-plans` (PHASE-0.108 S108-U14.1, 22-SPEC §7, 24 K1).
 *
 * Why not the Channel Gateway SPA: the SPA is a wp-admin page gated by manage_options, so a customer cannot open it. My Account is where customers already log in,
 * and BizCity_Usage_Plan_Enforce::renew_url() sends the "gia hạn" link of the Zalo reminder here.
 *
 * Server-rendered, no JavaScript needed to buy:
 *   GET  /my-account/zalo-plans/             lists ONLY the numbers this user owns (+ the packages on sale)
 *   POST (same URL, nonce)                   {account_id, package} ⇒ BizCity_Usage_Plan_Orders::create(..., 'self') ⇒ redirect to the Woo pay page (TTCK QR)
 *   GET  ?paid=<order>                       where the shop sends the customer back after paying (filter on the order-received URL)
 * The number is chosen by a button on a row that THIS user owns; there is no field to type a number into. Anything the browser sends besides account_id + package is not read.
 * Pay, QR, bank matching and the plan extension are the existing TTCK → Woo → BizCity_Usage_Plan_Woo path, untouched.
 *
 * Test seams ($readers): body(): array · logged_in(): bool · user(): int · nonce_ok(nonce): bool · create(account, package, user): array · account_url(): string
 *
 * // [2026-10-13 11:00 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.108 S108-U14.1.
 *
 * @package BizCity_Twin_AI
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_Usage_MyPlans_Endpoint {

	const ENDPOINT   = 'zalo-plans';
	const NONCE      = 'bizcity_plan_buy';
	const OPT_FLUSH  = 'bizcity_plan_endpoint_flushed';
	const FLUSH_VER  = '1';

	/** @var array<string,callable> */
	public static $readers = array();

	private static $initialized = false;

	public static function init(): void {
		if ( self::$initialized || ! class_exists( 'WooCommerce' ) ) {
			return;
		}
		self::$initialized = true;
		add_action( 'init', array( __CLASS__, 'register_endpoint' ), 10 );
		add_action( 'init', array( __CLASS__, 'flush_once' ), 99 );
		add_filter( 'woocommerce_account_menu_items', array( __CLASS__, 'menu_items' ), 20 );
		add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', array( __CLASS__, 'render' ) );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_handle_post' ), 5 );
		add_filter( 'woocommerce_get_checkout_order_received_url', array( __CLASS__, 'return_url' ), 10, 2 );
		add_filter( 'the_title', array( __CLASS__, 'title' ), 10, 2 );
	}

	public static function register_endpoint(): void {
		add_rewrite_endpoint( self::ENDPOINT, EP_ROOT | EP_PAGES );
	}

	/** Rewrite rules are flushed ONCE per version (never per request — R8 of 24 §11). */
	public static function flush_once(): void {
		if ( self::FLUSH_VER !== (string) get_option( self::OPT_FLUSH, '' ) ) {
			flush_rewrite_rules( false );
			update_option( self::OPT_FLUSH, self::FLUSH_VER, false );
		}
	}

	public static function menu_items( $items ) {
		$items = (array) $items;
		$out   = array();
		foreach ( $items as $k => $v ) {
			if ( 'customer-logout' === $k ) {
				$out[ self::ENDPOINT ] = 'Gói Zalo của tôi';
			}
			$out[ $k ] = $v;
		}
		if ( ! isset( $out[ self::ENDPOINT ] ) ) {
			$out[ self::ENDPOINT ] = 'Gói Zalo của tôi';
		}
		return $out;
	}

	public static function title( $title, $id = 0 ) {
		if ( is_main_query() && in_the_loop() && is_account_page() && function_exists( 'WC' ) && isset( WC()->query ) && WC()->query->get_current_endpoint() === self::ENDPOINT ) {
			return 'Gói Zalo của tôi';
		}
		return $title;
	}

	/** Full URL of the page — also what the reminder link should open (Enforce::renew_url). '' when Woo has no account page. */
	public static function url(): string {
		if ( isset( self::$readers['account_url'] ) ) {
			return (string) call_user_func( self::$readers['account_url'] );
		}
		return function_exists( 'wc_get_account_endpoint_url' ) && class_exists( 'WooCommerce' ) ? (string) wc_get_account_endpoint_url( self::ENDPOINT ) : '';
	}

	/** After paying, send a plan order's customer back here instead of the generic "order received" page. */
	public static function return_url( $url, $order ) {
		if ( is_object( $order ) && method_exists( $order, 'get_meta' ) && '' !== (string) $order->get_meta( BizCity_Usage_Plan_Woo::META_ACCOUNT ) ) {
			$back = self::url();
			if ( '' !== $back ) {
				return add_query_arg( 'paid', (int) $order->get_id(), $back );
			}
		}
		return $url;
	}

	/* ================================================================
	 *  POST: buy
	 * ================================================================ */

	public static function maybe_handle_post(): void {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || empty( $_POST['bizcity_plan_buy'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$res = self::handle_post( (array) wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! empty( $res['redirect'] ) ) {
			wp_safe_redirect( $res['redirect'] );
			exit;
		}
		if ( ! empty( $res['error'] ) && function_exists( 'wc_add_notice' ) ) {
			wc_add_notice( $res['error'], 'error' );
		}
	}

	/**
	 * Pure-ish (seams): what a POST does. Only `account_id`, `package` and the nonce are read from the request.
	 *
	 * @param array<string,mixed> $post
	 * @return array{redirect?:string,error?:string}
	 */
	public static function handle_post( array $post ): array {
		if ( ! self::logged_in() ) {
			return array( 'error' => 'Vui lòng đăng nhập để mua gói.' );
		}
		if ( ! self::nonce_ok( (string) ( $post['_wpnonce'] ?? '' ) ) ) {
			return array( 'error' => 'Phiên làm việc đã hết hạn. Tải lại trang rồi thử lại.' );
		}
		$account = class_exists( 'BizCity_Usage_Ingest' ) ? BizCity_Usage_Ingest::clean_id( (string) ( $post['account_id'] ?? '' ) ) : (string) ( $post['account_id'] ?? '' );
		$package = preg_replace( '/[^a-z0-9_]/', '', strtolower( (string) ( $post['package'] ?? '' ) ) );
		if ( '' === $account || '' === $package ) {
			return array( 'error' => 'Hãy chọn số và gói cần mua.' );
		}
		$r = self::create( $account, (string) $package, self::user() );
		if ( empty( $r['ok'] ) ) {
			return array( 'error' => self::friendly( $r ) );
		}
		return array( 'redirect' => (string) $r['pay_url'] );
	}

	/** Customer wording for the named errors (never the internal code). */
	public static function friendly( array $r ): string {
		switch ( (string) ( $r['code'] ?? '' ) ) {
			case 'forbidden':
				return 'Bạn không có quyền mua gói cho số này.';
			case 'no_package':
				return 'Gói này hiện không bán. Hãy chọn gói khác.';
			case 'ttck_off':
			case 'currency':
			case 'below_floor':
			case 'gateway_degraded':
				return 'Hiện chưa thể tạo đơn thanh toán. Vui lòng liên hệ cửa hàng.';
			case 'rate_limited':
				return 'Bạn thao tác quá nhanh. Thử lại sau ít phút.';
			default:
				return 'Không tạo được đơn. Vui lòng thử lại.';
		}
	}

	/* ================================================================
	 *  GET: render
	 * ================================================================ */

	public static function render(): void {
		$paid = isset( $_GET['paid'] ) ? (int) $_GET['paid'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
		echo self::html( self::body(), $paid, self::nonce_field(), self::url() ); // phpcs:ignore WordPress.Security.EscapeOutput -- every dynamic value is escaped inside html()
	}

	/**
	 * Pure: the page. `$paid` > 0 shows the "đang xác nhận" notice (the customer just came back from paying).
	 *
	 * @param array{numbers:array,packages:array,ttck_enabled:bool} $body BizCity_Usage_Plan_Orders_REST::my_plans_body()
	 */
	public static function html( array $body, int $paid, string $nonce_field, string $action_url ): string {
		$e   = array( __CLASS__, 'e' );
		$out = '<div class="bizcity-zalo-plans">';
		if ( $paid > 0 ) {
			$out .= '<div class="woocommerce-message" role="status">Đã nhận yêu cầu thanh toán đơn #' . (int) $paid . '. Gói sẽ tự gia hạn ngay khi ngân hàng báo về — thường trong vài giây. Trang này tự cập nhật.</div>';
		}
		$numbers = (array) ( $body['numbers'] ?? array() );
		if ( ! $numbers ) {
			return $out . '<p>Bạn chưa có số Zalo nào. Hãy liên hệ cửa hàng để được cấp số trợ lý.</p></div>';
		}
		$packages = (array) ( $body['packages'] ?? array() );
		if ( empty( $body['ttck_enabled'] ) || ! $packages ) {
			$out .= '<p class="woocommerce-info">Hiện chưa mở bán gói trực tuyến. Vui lòng liên hệ cửa hàng để gia hạn.</p>';
		}
		$out .= '<h3>Số của tôi</h3>';
		foreach ( $numbers as $n ) {
			$out .= '<form method="post" action="' . $e( $action_url ) . '" class="bizcity-zalo-plan-row" style="border:1px solid #ddd;border-radius:8px;padding:12px;margin:0 0 12px">';
			$out .= '<p style="margin:0 0 6px"><strong>' . $e( (string) $n['label_masked'] ) . '</strong> · ' . $e( self::state_text( $n ) ) . '</p>';
			if ( ! empty( $n['warn'] ) ) {
				$out .= '<p style="margin:0 0 6px"><span role="img" aria-label="sắp hết">⏳</span> Sắp hết hạn — gia hạn để trợ lý không bị gián đoạn.</p>';
			}
			if ( ! empty( $body['ttck_enabled'] ) && $packages ) {
				$out .= '<fieldset style="border:0;padding:0;margin:0 0 8px"><legend class="screen-reader-text">Chọn gói cho số ' . $e( (string) $n['label_masked'] ) . '</legend>';
				foreach ( $packages as $i => $p ) {
					$id   = 'pk-' . $e( (string) $n['account_id'] ) . '-' . $e( (string) $p['code'] );
					$disc = (int) $p['discount_pct'] > 0 ? ' · giảm ' . (int) $p['discount_pct'] . '%' : '';
					$out .= '<label for="' . $id . '" style="display:block;margin:2px 0"><input type="radio" id="' . $id . '" name="package" value="' . $e( (string) $p['code'] ) . '"' . ( 0 === $i ? ' checked' : '' ) . '> '
						. (int) $p['months'] . ' tháng — <strong>' . $e( number_format( (int) $p['price_vnd'], 0, ',', '.' ) ) . ' ₫</strong>' . $e( $disc ) . '</label>';
				}
				$out .= '</fieldset>';
				$out .= '<input type="hidden" name="account_id" value="' . $e( (string) $n['account_id'] ) . '">' . $nonce_field . '<input type="hidden" name="bizcity_plan_buy" value="1">';
				$out .= '<button type="submit" class="button">Thanh toán chuyển khoản</button>';
			}
			$out .= '</form>';
		}
		return $out . '</div>';
	}

	/** "Pro · còn 12 ngày (đến 04/10/2027)" / "Dùng thử · còn 2 ngày" / "Hết hạn" / "Chưa có gói". */
	public static function state_text( array $n ): string {
		$days = $n['days_left'] ?? null;
		$until = '' !== (string) ( $n['valid_until'] ?? '' ) ? gmdate( 'd/m/Y', (int) strtotime( (string) $n['valid_until'] ) ) : '';
		switch ( (string) ( $n['state'] ?? '' ) ) {
			case 'active':
				return 'Pro' . ( null !== $days ? ' · còn ' . (int) $days . ' ngày' : '' ) . ( '' !== $until ? ' (đến ' . $until . ')' : '' );
			case 'trial':
				return 'Dùng thử' . ( null !== $days ? ' · còn ' . (int) $days . ' ngày' : '' );
			case 'expired':
				return 'Hết hạn' . ( '' !== $until ? ' từ ' . $until : '' );
			default:
				return 'Chưa có gói';
		}
	}

	public static function e( string $s ): string {
		return function_exists( 'esc_attr' ) ? esc_attr( $s ) : htmlspecialchars( $s, ENT_QUOTES, 'UTF-8' );
	}

	/* ── seams ──────────────────────────────────────────────────────── */

	private static function body(): array {
		if ( isset( self::$readers['body'] ) ) {
			return (array) call_user_func( self::$readers['body'] );
		}
		return BizCity_Usage_Plan_Orders_REST::my_plans_body( (int) get_current_user_id(), time(), wp_timezone() );
	}

	private static function create( string $account, string $package, int $user ): array {
		if ( isset( self::$readers['create'] ) ) {
			return (array) call_user_func( self::$readers['create'], $account, $package, $user );
		}
		return BizCity_Usage_Plan_Orders::create( $account, $package, $user, 'self' );
	}

	private static function logged_in(): bool {
		return isset( self::$readers['logged_in'] ) ? (bool) call_user_func( self::$readers['logged_in'] ) : (bool) is_user_logged_in();
	}

	private static function user(): int {
		return isset( self::$readers['user'] ) ? (int) call_user_func( self::$readers['user'] ) : (int) get_current_user_id();
	}

	private static function nonce_ok( string $nonce ): bool {
		if ( isset( self::$readers['nonce_ok'] ) ) {
			return (bool) call_user_func( self::$readers['nonce_ok'], $nonce );
		}
		return false !== wp_verify_nonce( $nonce, self::NONCE );
	}

	private static function nonce_field(): string {
		if ( isset( self::$readers['nonce_field'] ) ) {
			return (string) call_user_func( self::$readers['nonce_field'] );
		}
		return function_exists( 'wp_nonce_field' ) ? (string) wp_nonce_field( self::NONCE, '_wpnonce', true, false ) : '';
	}
}
