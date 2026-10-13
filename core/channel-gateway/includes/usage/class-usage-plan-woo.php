<?php
/**
 * Pro plan sold through the CLIENT site's own WooCommerce (PHASE-0.107 S107-L16, doc 66 §12).
 *
 * A product flagged `_bizcity_plan_pro = yes` + an order carrying `_bizcity_plan_account_id` ⇒ when the order is paid (processing /
 * completed / payment_complete) the account's plan is extended: state active, plan_code pro, plan_mode on, valid_until =
 * max(now, current valid_until) + 1 month, then the plan is enforced right away (BizCity_Usage_Plan_Enforce: AI back on if we had switched it off). Idempotent per order (order meta `_bizcity_plan_applied`);
 * who sold is recorded on the plan row (`last_order_id`, `last_sold_by` = the order's customer user id).
 *
 * Everything is guarded by class_exists('WooCommerce'); a site without Woo loads nothing. No checkout UI: the account id gets onto the
 * order with `BizCity_Usage_Plan_Woo::attach_account( $order_id, $account_id )` (or the filter `bizcity_usage_plan_order_account`),
 * and a checkbox on the product edit screen flags the product.
 *
 * // [2026-10-13 02:28 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.107 S107-L16 — Woo of the client site extends the Pro plan of an account.
 *
 * @package BizCity_Twin_AI
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_Usage_Plan_Woo {

	const META_PRODUCT = '_bizcity_plan_pro';
	const META_ACCOUNT = '_bizcity_plan_account_id';
	const META_APPLIED = '_bizcity_plan_applied';
	// [2026-10-13 Johnny Chu - Chu Hoàng Anh] PHASE-0.108 S108-L18 (20-REVENUE-PER-NUMBER.md §3.3) — real bug fix:
	// TTCK fires payment_complete() (which itself bumps status to processing) AND a separate update_status(completed)
	// within the SAME webhook request, so on_paid() can run 2-3 times before the order meta write from the first
	// run is visible to a freshly re-fetched wc_get_order() — the account could get extended twice from one order.
	// `_bizcity_plan_months` / `_bizcity_plan_allowance` let a specific order request a duration/allowance other
	// than the hardcoded 1-month default (needed once L17/L19 start writing real packages onto orders).
	const META_MONTHS    = '_bizcity_plan_months';
	const META_ALLOWANCE = '_bizcity_plan_allowance';
	const LOCK_OPTION_PREFIX = 'bizcity_plan_applied_';

	/** Test seams: order(order_id): ?array{id,user_id,account_id,applied,has_pro} · mark_applied(order_id) · extend(platform, account, order_id, user) · enforce(platform, account) */
	public static $readers = array();

	private static $initialized = false;

	public static function init(): void {
		if ( self::$initialized || ! class_exists( 'WooCommerce' ) ) {
			return;
		}
		self::$initialized = true;
		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'on_paid' ), 20, 1 );
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'on_paid' ), 20, 1 );
		add_action( 'woocommerce_payment_complete', array( __CLASS__, 'on_paid' ), 20, 1 );
		add_action( 'woocommerce_product_options_general_product_data', array( __CLASS__, 'product_field' ) );
		add_action( 'woocommerce_process_product_meta', array( __CLASS__, 'save_product_field' ) );
	}

	/** Hook handler: apply a paid order. Safe to run more than once. */
	public static function on_paid( $order_id ): void {
		self::apply( (int) $order_id );
	}

	/**
	 * @return string applied | skipped:<reason> | error:<code>
	 */
	public static function apply( int $order_id ): string {
		$o = self::order( $order_id );
		if ( ! $o ) {
			return 'skipped:no_order';
		}
		if ( ! $o['has_pro'] ) {
			return 'skipped:not_pro_order';
		}
		if ( '' === $o['account_id'] ) {
			return 'skipped:no_account';
		}
		if ( ! empty( $o['applied'] ) ) {
			return 'skipped:already_applied';
		}
		// S108-L18: the authoritative dedupe guard. `wp_options.option_name` is UNIQUE, so the second of two
		// `add_option()` calls for the same order_id — even fired within the same request, even if the first
		// call's order-meta write isn't visible yet to a freshly re-fetched order object — loses this race and
		// returns false. The order meta (`applied` above) stays only for display; this option is what prevents
		// a double extend.
		if ( ! self::acquire_lock( $order_id ) ) {
			return 'skipped:already_applied';
		}
		$r = self::extend( BizCity_Usage_Plans::PLATFORM, $o['account_id'], $order_id, (int) $o['user_id'], $o['months'], $o['allowance'] );
		if ( is_wp_error( $r ) ) {
			self::release_lock( $order_id ); // the sale didn't actually get applied — let a retry through
			return 'error:' . $r->get_error_code();
		}
		self::mark_applied( $order_id ); // display-only now; the lock above already prevented a double extend
		self::enforce( BizCity_Usage_Plans::PLATFORM, $o['account_id'] );
		// [2026-10-13 09:08 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.108 S108-L18.2 — one-line extension point: BizCity_Usage_Plan_Sales records the sale
		// (revenue ledger, 24 §4.2) from here; a failure there must never undo the plan extension above.
		if ( function_exists( 'do_action' ) ) {
			do_action( 'bizcity_usage_plan_applied', $order_id, $o, $r );
		}
		return 'applied';
	}

	private static function acquire_lock( int $order_id ): bool {
		if ( isset( self::$readers['acquire_lock'] ) ) {
			return (bool) call_user_func( self::$readers['acquire_lock'], $order_id );
		}
		return (bool) add_option( self::LOCK_OPTION_PREFIX . $order_id, time(), '', 'no' );
	}

	private static function release_lock( int $order_id ): void {
		if ( isset( self::$readers['release_lock'] ) ) {
			call_user_func( self::$readers['release_lock'], $order_id );
			return;
		}
		delete_option( self::LOCK_OPTION_PREFIX . $order_id );
	}

	/** Put the account id on an order (admin helper / import / custom checkout). */
	public static function attach_account( int $order_id, string $account_id ): bool {
		$id = BizCity_Usage_Ingest::clean_id( $account_id );
		if ( '' === $id || ! function_exists( 'wc_get_order' ) ) {
			return false;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return false;
		}
		$order->update_meta_data( self::META_ACCOUNT, $id );
		$order->save();
		return true;
	}

	public static function product_field(): void {
		if ( function_exists( 'woocommerce_wp_checkbox' ) ) {
			woocommerce_wp_checkbox( array(
				'id'          => self::META_PRODUCT,
				'label'       => 'Gói Pro (số Zalo)',
				'description' => 'Đơn đã thanh toán có mã số (meta _bizcity_plan_account_id) sẽ gia hạn gói Pro +1 tháng cho số đó.',
			) );
		}
	}

	public static function save_product_field( $post_id ): void {
		$yes = ( isset( $_POST[ self::META_PRODUCT ] ) && '' !== $_POST[ self::META_PRODUCT ] ) ? 'yes' : 'no'; // phpcs:ignore WordPress.Security.NonceVerification
		update_post_meta( (int) $post_id, self::META_PRODUCT, $yes );
	}

	/* ── seams ─────────────────────────────────────────────────────── */

	/** @return array{id:int,user_id:int,account_id:string,applied:bool,has_pro:bool}|null */
	private static function order( int $order_id ): ?array {
		if ( isset( self::$readers['order'] ) ) {
			$o = call_user_func( self::$readers['order'], $order_id );
			return is_array( $o ) ? $o : null;
		}
		if ( ! function_exists( 'wc_get_order' ) ) {
			return null;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return null;
		}
		$has_pro = false;
		foreach ( $order->get_items() as $item ) {
			$pid = (int) ( method_exists( $item, 'get_product_id' ) ? $item->get_product_id() : 0 );
			if ( $pid > 0 && 'yes' === get_post_meta( $pid, self::META_PRODUCT, true ) ) {
				$has_pro = true;
				break;
			}
		}
		$account = (string) $order->get_meta( self::META_ACCOUNT );
		$account = (string) apply_filters( 'bizcity_usage_plan_order_account', $account, $order );
		$months_meta    = (string) $order->get_meta( self::META_MONTHS );
		$allowance_meta = (string) $order->get_meta( self::META_ALLOWANCE );
		return array(
			'id'         => $order_id,
			'user_id'    => (int) $order->get_user_id(),
			'account_id' => BizCity_Usage_Ingest::clean_id( $account ),
			'applied'    => '' !== (string) $order->get_meta( self::META_APPLIED ),
			'has_pro'    => $has_pro,
			// S108-L18/20-REVENUE §3.3 point 1 — present only once a real package-order flow (L17/L19) writes
			// these; 0 means "not specified", so extend() keeps today's hardcoded-1-month / formula-allowance behavior.
			'months'     => max( 0, (int) $months_meta ),
			'allowance'  => '' === $allowance_meta ? null : max( 0, (int) $allowance_meta ),
		);
	}

	private static function mark_applied( int $order_id ): void {
		if ( isset( self::$readers['mark_applied'] ) ) {
			call_user_func( self::$readers['mark_applied'], $order_id );
			return;
		}
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
		if ( $order ) {
			$order->update_meta_data( self::META_APPLIED, gmdate( 'c' ) );
			$order->save();
		}
	}

	private static function extend( string $platform, string $account, int $order_id, int $user, int $months = 0, ?int $allowance = null ) {
		if ( isset( self::$readers['extend'] ) ) {
			return call_user_func( self::$readers['extend'], $platform, $account, $order_id, $user, $months, $allowance );
		}
		return BizCity_Usage_Plans::extend_pro( $platform, $account, $order_id, $user, $months, $allowance );
	}

	private static function enforce( string $platform, string $account ): void {
		if ( isset( self::$readers['enforce'] ) ) {
			call_user_func( self::$readers['enforce'], $platform, $account );
			return;
		}
		if ( class_exists( 'BizCity_Usage_Plan_Enforce' ) ) {
			BizCity_Usage_Plan_Enforce::apply( $platform, $account );
		}
	}
}
