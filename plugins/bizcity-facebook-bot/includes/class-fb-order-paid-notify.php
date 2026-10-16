<?php
/**
 * BizCity_FB_Order_Paid_Notify — tell a Messenger customer their order was paid (PHASE-0.115 S115-W5, owner: "khách thanh toán xong ⇒ tự báo đơn đã thanh toán").
 *
 * The SITE watches (TTCK confirms the money and calls WooCommerce `payment_complete()`); the cell never polls. On that event, for an order the bot created from a chat channel
 * (`_bizcity_guest_order`, identity meta from order.place) whose page the cell answers, the site pushes `channel-notify@1` through the Hub; the cell sends the message
 * within Messenger's 24 h window. Once per order (`_bizcity_paid_notified`) and by an idempotency key, so a hook that fires twice, or a retry, never double-sends.
 * A closed window / no thread is not an error: the order gets a note so staff follow up. A failed push is retried up to 3 times (2 min apart).
 *
 * @package BizCity_Twin_AI
 */

defined( 'ABSPATH' ) || exit;

// [2026-10-15 Johnny Chu - Chu Hoàng Anh] PHASE-0.115 S115-W5 — no early-bind guard (see r-early-bind-guard-bug).
final class BizCity_FB_Order_Paid_Notify {

	const META_NOTIFIED = '_bizcity_paid_notified';
	const META_TRIES    = '_bizcity_paid_notify_tries';
	const RETRY_HOOK    = 'bizcity_fb_order_paid_retry';
	const MAX_TRIES     = 3;
	const RETRY_AFTER   = 120;

	/** Test seams: send(body): array{ok,status,data?} */
	public static $seams = array();

	public static function init(): void {
		add_action( 'woocommerce_payment_complete', array( __CLASS__, 'on_payment_complete' ), 30, 1 );
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'on_status_changed' ), 30, 4 );
		add_action( self::RETRY_HOOK, array( __CLASS__, 'handle' ), 10, 1 );
	}

	public static function on_payment_complete( $order_id ): void {
		self::handle( (int) $order_id );
	}

	/** Fallback when payment_complete did not run: the order reached the status TTCK uses for "paid" and WooCommerce recorded a paid date */
	public static function on_status_changed( $order_id, $from, $to, $order = null ): void {
		$paid = 'completed';
		if ( class_exists( 'TTCKPayment', false ) ) {
			$s    = TTCKPayment::get_settings();
			$cfg  = (string) ( $s['order_status']['order_status_after_paid'] ?? '' );
			$paid = '' !== $cfg ? ( 0 === strpos( $cfg, 'wc-' ) ? substr( $cfg, 3 ) : $cfg ) : 'completed';
		}
		if ( (string) $to !== $paid ) {
			return;
		}
		$o = is_object( $order ) ? $order : ( function_exists( 'wc_get_order' ) ? wc_get_order( (int) $order_id ) : null );
		if ( is_object( $o ) && method_exists( $o, 'get_date_paid' ) && $o->get_date_paid() ) {
			self::handle( (int) $order_id );
		}
	}

	/**
	 * @return string the outcome (tests read it): skipped_* | sent | duplicate | window_closed | no_thread | retry | gave_up
	 */
	public static function handle( int $order_id ): string {
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : false;
		if ( ! is_object( $order ) ) {
			return 'skipped_no_order';
		}
		if ( ! $order->get_meta( '_bizcity_guest_order' ) ) {
			return 'skipped_not_chat_order'; // only the orders the bot made from a chat channel
		}
		if ( $order->get_meta( self::META_NOTIFIED ) ) {
			return 'skipped_already';
		}
		$platform = strtolower( (string) $order->get_meta( '_bizcity_platform' ) );
		$page     = (string) $order->get_meta( '_bizcity_channel_ref' );
		$uid      = (string) $order->get_meta( '_bizcity_platform_uid' );
		if ( 'messenger' !== $platform || 1 !== preg_match( '/^[0-9]{5,30}$/', $page ) || 1 !== preg_match( '/^[0-9]{5,30}$/', $uid ) ) {
			return 'skipped_no_identity';
		}
		if ( ! class_exists( 'BizCity_FB_Cell_Direct', false ) || ! BizCity_FB_Cell_Direct::is_cell_direct( $page ) ) {
			return 'skipped_not_cell_page'; // a page the old bot answers: the old bot has no such message, staff handle it
		}
		$total = (int) round( (float) $order->get_total() );
		$body  = array(
			'contract'        => 'channel-notify@1.0.0',
			'idempotency_key' => 'paid:' . ( function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0 ) . ':' . $order_id,
			'kind'            => 'order_paid',
			'platform'        => 'messenger',
			'channel_ref'     => $page,
			'platform_uid'    => $uid,
			'order_id'        => $order_id,
			'number'          => (string) $order->get_order_number(),
			'total'           => $total,
			'paid'            => $total,
			'currency'        => (string) $order->get_currency(),
		);
		$r      = isset( self::$seams['send'] ) ? call_user_func( self::$seams['send'], $body ) : BizCity_FB_Hub_Client::notify( $body );
		$status = is_array( $r['data'] ?? null ) ? (string) ( $r['data']['status'] ?? '' ) : '';
		if ( ! empty( $r['ok'] ) && in_array( $status, array( 'sent', 'duplicate' ), true ) ) {
			$order->update_meta_data( self::META_NOTIFIED, time() );
			$order->save();
			return $status;
		}
		if ( ! empty( $r['ok'] ) && in_array( $status, array( 'window_closed', 'no_thread' ), true ) ) {
			$order->update_meta_data( self::META_NOTIFIED, time() );
			$order->add_order_note( 'Khách đã thanh toán nhưng bot chưa nhắn lại được trên Messenger (' . ( 'window_closed' === $status ? 'quá 24 giờ kể từ tin cuối của khách' : 'chưa có cuộc chat' ) . '): nhân viên nhắn xác nhận cho khách.' );
			$order->save();
			return $status;
		}
		$tries = (int) $order->get_meta( self::META_TRIES ) + 1;
		$order->update_meta_data( self::META_TRIES, $tries );
		if ( $tries >= self::MAX_TRIES ) {
			$order->add_order_note( 'Khách đã thanh toán nhưng chưa gửi được tin báo qua Messenger sau ' . self::MAX_TRIES . ' lần thử: nhân viên nhắn xác nhận cho khách.' );
			$order->save();
			return 'gave_up';
		}
		$order->save();
		if ( function_exists( 'wp_schedule_single_event' ) ) {
			wp_schedule_single_event( time() + self::RETRY_AFTER, self::RETRY_HOOK, array( $order_id ) );
		}
		return 'retry';
	}
}

BizCity_FB_Order_Paid_Notify::init();
