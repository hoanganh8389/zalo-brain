<?php
/**
 * REST of selling plans (PHASE-0.108 S108-L17.1 / L19.2 / L19.3, 24-TECH-DESIGN-REVENUE.md §5). Namespace bizcity-channel/v1.
 *
 *   managers only
 *     GET  usage/revenue/packages              price list + floor state + per-package check + TTCK/currency state
 *     PUT  usage/revenue/packages              save the list (all-or-nothing, 422 with the offending package)
 *     POST usage/revenue/floor/refresh         re-read the floor from the Hub now
 *     GET  usage/plan-orders/preview           what buying package X does to number Y
 *     POST usage/plan-orders                   make an order for ONE number (optionally send the pay link on Zalo)
 *     POST usage/plan-orders/{id}/apply        retry applying a PAID order (safe: the sale lock lives in Plan_Woo)
 *     POST usage/plan-orders/{id}/cancel       cancel a PENDING plan order
 *   any logged-in user
 *     GET  usage/my-plans                      the numbers THEY own + the packages on sale
 *     POST usage/my-plans/order                buy a package for a number they own (anyone else's number ⇒ the same 403 as a missing one)
 *
 * Money is whole VND, dates ISO-8601 UTC, numbers always masked. Errors: { ok:false, code, message, fields?:{ <package>:{code,message,gap_vnd?} } }.
 *
 * Test seams ($readers): is_admin() · logged_in() · actor() · now() · tz() · woo_apply(order_id): string · order_info(order_id): ?array · cancel_order(order_id): bool · mine(user): array · plan(account): ?array
 *
 * // [2026-10-13 09:15 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.108 S108-L17.1 / L19.2 / L19.3.
 *
 * @package BizCity_Twin_AI
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_Usage_Plan_Orders_REST {

	const NS       = 'bizcity-channel/v1';
	const CONTRACT = 'plan-sales@1';

	/** @var array<string,callable> */
	public static $readers = array();

	private static $initialized = false;

	public static function init(): void {
		if ( self::$initialized ) {
			return;
		}
		self::$initialized = true;
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes(): void {
		$mgr = array( __CLASS__, 'can_manage' );
		$usr = array( __CLASS__, 'can_use' );
		register_rest_route( self::NS, '/usage/revenue/packages', array(
			array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'route_packages' ), 'permission_callback' => $mgr ),
			array( 'methods' => 'PUT,POST', 'callback' => array( __CLASS__, 'route_packages_save' ), 'permission_callback' => $mgr ),
		) );
		register_rest_route( self::NS, '/usage/revenue/floor/refresh', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'route_floor_refresh' ), 'permission_callback' => $mgr ) );
		register_rest_route( self::NS, '/usage/plan-orders/preview', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'route_preview' ), 'permission_callback' => $mgr ) );
		register_rest_route( self::NS, '/usage/plan-orders', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'route_create' ), 'permission_callback' => $mgr ) );
		register_rest_route( self::NS, '/usage/plan-orders/(?P<id>\d+)/apply', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'route_apply' ), 'permission_callback' => $mgr ) );
		register_rest_route( self::NS, '/usage/plan-orders/(?P<id>\d+)/cancel', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'route_cancel' ), 'permission_callback' => $mgr ) );
		register_rest_route( self::NS, '/usage/my-plans', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'route_my_plans' ), 'permission_callback' => $usr ) );
		register_rest_route( self::NS, '/usage/my-plans/order', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'route_my_order' ), 'permission_callback' => $usr ) );
	}

	/* ── permissions ────────────────────────────────────────────────── */

	public static function can_use() {
		if ( ! self::logged_in() ) {
			return new WP_Error( 'rest_forbidden', 'Login required.', array( 'status' => 401 ) );
		}
		return true;
	}

	public static function can_manage() {
		$u = self::can_use();
		if ( true !== $u ) {
			return $u;
		}
		if ( ! self::is_admin() ) {
			return new WP_Error( 'rest_forbidden', 'Chỉ quản trị website được bán và quản lý gói.', array( 'status' => 403 ) );
		}
		return true;
	}

	/* ── price list ─────────────────────────────────────────────────── */

	public static function route_packages( WP_REST_Request $request ): WP_REST_Response {
		return new WP_REST_Response( self::packages_body( BizCity_Usage_Plan_Floor::read( ! empty( $request->get_param( 'refresh' ) ) ) ), 200 );
	}

	public static function route_packages_save( WP_REST_Request $request ): WP_REST_Response {
		$body  = (array) $request->get_json_params();
		$items = isset( $body['items'] ) && is_array( $body['items'] ) ? array_values( array_filter( $body['items'], 'is_array' ) ) : array();
		if ( ! $items ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'invalid_param', 'message' => 'Thiếu danh sách gói.' ), 400 );
		}
		$floor = BizCity_Usage_Plan_Floor::read();
		$r     = BizCity_Usage_Plan_Packages::save( $items, $floor );
		if ( ! $r['ok'] ) {
			$first = (string) key( $r['errors'] );
			return new WP_REST_Response( array( 'ok' => false, 'code' => (string) ( $r['errors'][ $first ]['code'] ?? 'invalid' ), 'message' => (string) ( $r['errors'][ $first ]['message'] ?? '' ), 'fields' => $r['errors'] ), 422 );
		}
		return new WP_REST_Response( self::packages_body( $floor ) + array( 'saved' => true ), 200 );
	}

	public static function route_floor_refresh( WP_REST_Request $request ): WP_REST_Response {
		return new WP_REST_Response( self::packages_body( BizCity_Usage_Plan_Floor::read( true ) ), 200 );
	}

	/**
	 * Pure-ish: the body of GET packages (public for the unit test).
	 *
	 * @param array{state:string,floor:?array,version:string,fetched_at:?int} $floor
	 * @return array<string,mixed>
	 */
	public static function packages_body( array $floor ): array {
		$g     = BizCity_Usage_Plan_Packages::get();
		$one   = (int) ( $g['items'][0]['price_vnd'] ?? 0 );
		$items = array();
		foreach ( $g['items'] as $it ) {
			$c       = BizCity_Usage_Plan_Floor::check( $it, $floor );
			$items[] = array(
				'code' => $it['code'], 'months' => $it['months'], 'enabled' => (bool) $it['enabled'], 'price_vnd' => (int) $it['price_vnd'], 'allowance' => (int) $it['allowance'],
				'label' => $it['label'], 'product_id' => (int) $it['product_id'], 'discount_pct' => BizCity_Usage_Plan_Packages::discount_pct( (int) $it['months'], (int) $it['price_vnd'], $one ),
				'floor_vnd' => $c['floor_vnd'], 'ok' => $c['ok'], 'gap_vnd' => $c['gap_vnd'], 'margin_vnd' => $c['margin_vnd'], 'warn' => $c['warn'],
			);
		}
		$f = is_array( $floor['floor'] ?? null ) ? $floor['floor'] : array();
		return array(
			'contract' => self::CONTRACT,
			'version'  => (int) $g['version'],
			'items'    => $items,
			'floor'    => array(
				'state' => (string) $floor['state'], 'version' => (string) ( $floor['version'] ?? '' ), 'fetched_at' => $floor['fetched_at'] ?? null,
				'fee_per_number_month' => $f['fee_per_number_month'] ?? null, 'vnd_per_mtok_billed' => $f['vnd_per_mtok_billed'] ?? null,
				'unlimited_reference_mtok' => $f['unlimited_reference_mtok'] ?? null, 'effective_from' => $f['effective_from'] ?? null,
			),
			'trial'    => array( 'days' => BizCity_Usage_Plans::TRIAL_DAYS, 'allowance' => BizCity_Usage_Plans::TRIAL_ALLOWANCE, 'cap' => BizCity_Usage_Plans::trial_cap() ),
			'ttck_enabled' => BizCity_Usage_Plan_Orders::ttck_enabled(),
			'currency' => function_exists( 'get_woocommerce_currency' ) ? (string) get_woocommerce_currency() : '',
		);
	}

	/* ── orders (managers) ──────────────────────────────────────────── */

	public static function route_preview( WP_REST_Request $request ): WP_REST_Response {
		$p = BizCity_Usage_Plan_Orders::preview( self::account_param( $request ), sanitize_key( (string) $request->get_param( 'package' ) ) );
		return $p ? new WP_REST_Response( array( 'ok' => true ) + $p, 200 ) : new WP_REST_Response( array( 'ok' => false, 'code' => 'not_found', 'message' => 'Không tìm thấy số hoặc gói.' ), 404 );
	}

	public static function route_create( WP_REST_Request $request ): WP_REST_Response {
		$b       = (array) $request->get_json_params();
		$account = self::clean_account( (string) ( $b['account_id'] ?? '' ) );
		$code    = sanitize_key( (string) ( $b['package'] ?? '' ) );
		$r       = BizCity_Usage_Plan_Orders::create( $account, $code, self::actor(), 'admin', (int) ( $b['payer_user_id'] ?? 0 ) );
		if ( empty( $r['ok'] ) ) {
			return self::error( $r );
		}
		$out = array( 'ok' => true, 'order_id' => $r['order_id'], 'pay_url' => $r['pay_url'], 'amount_vnd' => $r['amount_vnd'], 'reused' => $r['reused'], 'package' => $r['package'], 'months' => $r['months'] );
		$out['sent'] = array( 'ok' => false, 'code' => 'not_requested' );
		if ( 'zalo' === (string) ( $b['send'] ?? '' ) && class_exists( 'BizCity_Usage_Plan_Notices' ) ) {
			$out['sent'] = BizCity_Usage_Plan_Notices::send_link( $account, (int) $r['order_id'], (int) $r['months'], (int) $r['amount_vnd'], (string) $r['pay_url'] );
		}
		return new WP_REST_Response( $out, 200 );
	}

	/** Retry applying a PAID order. Only ever calls the same guarded path the payment hook uses, so it cannot extend twice. */
	public static function route_apply( WP_REST_Request $request ): WP_REST_Response {
		$id   = (int) $request->get_param( 'id' );
		$info = self::order_info( $id );
		if ( ! $info || '' === (string) ( $info['account_id'] ?? '' ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'not_found', 'message' => 'Không tìm thấy đơn gói này.' ), 404 );
		}
		if ( ! in_array( (string) $info['status'], array( 'processing', 'completed' ), true ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'not_paid', 'message' => 'Đơn chưa được thanh toán nên chưa thể áp dụng gói.' ), 409 );
		}
		$res = self::woo_apply( $id );
		if ( 0 === strpos( $res, 'error:' ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => substr( $res, 6 ), 'message' => 'Không áp dụng được gói. Xem nhật ký rồi thử lại.' ), 422 );
		}
		return new WP_REST_Response( array( 'ok' => true, 'status' => $res, 'applied' => 'applied' === $res ), 200 );
	}

	public static function route_cancel( WP_REST_Request $request ): WP_REST_Response {
		$id   = (int) $request->get_param( 'id' );
		$info = self::order_info( $id );
		if ( ! $info || '' === (string) ( $info['account_id'] ?? '' ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'not_found', 'message' => 'Không tìm thấy đơn gói này.' ), 404 );
		}
		if ( 'pending' !== (string) $info['status'] ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'not_pending', 'message' => 'Chỉ huỷ được đơn đang chờ thanh toán.' ), 409 );
		}
		return new WP_REST_Response( array( 'ok' => self::cancel_order( $id ) ), 200 );
	}

	/* ── end user ───────────────────────────────────────────────────── */

	public static function route_my_plans( WP_REST_Request $request ): WP_REST_Response {
		return new WP_REST_Response( self::my_plans_body( self::actor(), self::now(), self::tz() ), 200 );
	}

	public static function route_my_order( WP_REST_Request $request ): WP_REST_Response {
		$b = (array) $request->get_json_params(); // a `payer_user_id`, a price, anything else the client sends is never read
		$r = BizCity_Usage_Plan_Orders::create( self::clean_account( (string) ( $b['account_id'] ?? '' ) ), sanitize_key( (string) ( $b['package'] ?? '' ) ), self::actor(), 'self' );
		if ( empty( $r['ok'] ) ) {
			return self::error( $r );
		}
		return new WP_REST_Response( array( 'ok' => true, 'order_id' => $r['order_id'], 'pay_url' => $r['pay_url'], 'amount_vnd' => $r['amount_vnd'], 'reused' => $r['reused'] ), 200 );
	}

	/**
	 * The numbers a user owns (masked) with their plan state, and the packages on sale. Public: the My Account endpoint renders the same body.
	 *
	 * @return array<string,mixed>
	 */
	public static function my_plans_body( int $user, int $now, DateTimeZone $tz ): array {
		$numbers = array();
		foreach ( self::mine( $user ) as $a ) {
			$id   = (string) $a['account_id'];
			$p    = self::plan( $id ) ?: BizCity_Usage_Plans::blank( BizCity_Usage_Plans::PLATFORM, $id );
			$st   = BizCity_Usage_Plans::effective_state( $p, $now );
			$end  = BizCity_Usage_Plans::ts( $p['valid_until'] ?? null );
			$days = $end > 0 ? max( 0, (int) ceil( ( $end - $now ) / 86400 ) ) : null;
			$raw  = trim( (string) ( $a['label'] ?? '' ) );
			$numbers[] = array(
				'account_id'   => $id,
				'label_masked' => '' !== $raw ? BizCity_Usage_View_REST::mask( $raw ) : 'Số …' . substr( $id, -3 ),
				'plan_code'    => (string) $p['plan_code'],
				'state'        => $st,
				'valid_until'  => $end > 0 ? gmdate( 'Y-m-d\TH:i:s\Z', $end ) : '',
				'days_left'    => $days,
				'warn'         => in_array( $st, array( 'trial', 'active' ), true ) && null !== $days && $days <= 3,
				'expired'      => 'expired' === $st,
			);
		}
		$g   = BizCity_Usage_Plan_Packages::get();
		$one = (int) ( $g['items'][0]['price_vnd'] ?? 0 );
		$pk  = array();
		foreach ( $g['items'] as $it ) {
			if ( ! empty( $it['enabled'] ) && (int) $it['price_vnd'] > 0 ) {
				$pk[] = array( 'code' => $it['code'], 'months' => (int) $it['months'], 'price_vnd' => (int) $it['price_vnd'], 'allowance' => (int) $it['allowance'],
					'discount_pct' => BizCity_Usage_Plan_Packages::discount_pct( (int) $it['months'], (int) $it['price_vnd'], $one ), 'label' => (string) $it['label'] );
			}
		}
		return array( 'contract' => self::CONTRACT, 'numbers' => $numbers, 'packages' => $pk, 'ttck_enabled' => BizCity_Usage_Plan_Orders::ttck_enabled() );
	}

	/* ── helpers ────────────────────────────────────────────────────── */

	private static function error( array $r ): WP_REST_Response {
		$out = array( 'ok' => false, 'code' => (string) $r['code'], 'message' => (string) $r['message'] );
		if ( isset( $r['gap_vnd'] ) ) {
			$out['gap_vnd'] = (int) $r['gap_vnd'];
		}
		return new WP_REST_Response( $out, (int) ( $r['http'] ?? 400 ) );
	}

	private static function account_param( WP_REST_Request $request ): string {
		return self::clean_account( (string) $request->get_param( 'account_id' ) );
	}

	private static function clean_account( string $raw ): string {
		return class_exists( 'BizCity_Usage_Ingest' ) ? BizCity_Usage_Ingest::clean_id( $raw ) : preg_replace( '/[^A-Za-z0-9._:-]/', '', $raw );
	}

	/** @return array<int,array{account_id:string,label:string}> numbers whose owner is this user */
	private static function mine( int $user ): array {
		if ( isset( self::$readers['mine'] ) ) {
			return (array) call_user_func( self::$readers['mine'], $user );
		}
		global $wpdb;
		if ( $user <= 0 || ! isset( $wpdb ) ) {
			return array();
		}
		$t = $wpdb->prefix . 'bizcity_zalo_accounts';
		$r = $wpdb->get_results( $wpdb->prepare( "SELECT bridge_account_id AS account_id, label, account_name FROM {$t} WHERE kind = 'personal' AND owner_user_id = %d ORDER BY id DESC LIMIT 100", $user ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		$out = array();
		foreach ( (array) $r as $row ) {
			$out[] = array( 'account_id' => (string) $row['account_id'], 'label' => '' !== trim( (string) $row['label'] ) ? (string) $row['label'] : (string) $row['account_name'] );
		}
		return $out;
	}

	private static function plan( string $account ): ?array {
		if ( isset( self::$readers['plan'] ) ) {
			$p = call_user_func( self::$readers['plan'], $account );
			return is_array( $p ) ? $p : null;
		}
		return BizCity_Usage_Plans::get( BizCity_Usage_Plans::PLATFORM, $account );
	}

	/** @return array{status:string,account_id:string}|null */
	private static function order_info( int $id ): ?array {
		if ( isset( self::$readers['order_info'] ) ) {
			$o = call_user_func( self::$readers['order_info'], $id );
			return is_array( $o ) ? $o : null;
		}
		$o = function_exists( 'wc_get_order' ) ? wc_get_order( $id ) : null;
		return $o ? array( 'status' => (string) $o->get_status(), 'account_id' => (string) $o->get_meta( BizCity_Usage_Plan_Woo::META_ACCOUNT ) ) : null;
	}

	private static function woo_apply( int $id ): string {
		if ( isset( self::$readers['woo_apply'] ) ) {
			return (string) call_user_func( self::$readers['woo_apply'], $id );
		}
		return BizCity_Usage_Plan_Woo::apply( $id );
	}

	private static function cancel_order( int $id ): bool {
		if ( isset( self::$readers['cancel_order'] ) ) {
			return (bool) call_user_func( self::$readers['cancel_order'], $id );
		}
		$o = function_exists( 'wc_get_order' ) ? wc_get_order( $id ) : null;
		if ( ! $o ) {
			return false;
		}
		$o->update_status( 'cancelled', 'Huỷ bởi quản trị (gói số Zalo).' );
		return true;
	}

	private static function logged_in(): bool {
		return isset( self::$readers['logged_in'] ) ? (bool) call_user_func( self::$readers['logged_in'] ) : (bool) is_user_logged_in();
	}

	private static function is_admin(): bool {
		if ( isset( self::$readers['is_admin'] ) ) {
			return (bool) call_user_func( self::$readers['is_admin'] );
		}
		return class_exists( 'BizCity_Network_Admin_Capability' ) ? BizCity_Network_Admin_Capability::can_manage() : current_user_can( 'manage_options' );
	}

	private static function actor(): int {
		return isset( self::$readers['actor'] ) ? (int) call_user_func( self::$readers['actor'] ) : (int) get_current_user_id();
	}

	private static function now(): int {
		return isset( self::$readers['now'] ) ? (int) call_user_func( self::$readers['now'] ) : time();
	}

	private static function tz(): DateTimeZone {
		if ( isset( self::$readers['tz'] ) ) {
			return call_user_func( self::$readers['tz'] );
		}
		return function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
	}
}
