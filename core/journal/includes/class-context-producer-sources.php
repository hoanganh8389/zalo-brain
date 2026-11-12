<?php
/**
 * BizCity_Context_Producer_Sources — the first data sources of `context-producer@1` (PHASE-0.104 S104-C7): WooCommerce, Contact Form 7 and the
 * Zalo Brain CRM spine. Each one registers ONLY when its system is active, listens to that system's own hooks and emits small records:
 *
 *   woo  woocommerce_payment_complete ⇒ {revenue, orders} · woocommerce_order_refunded ⇒ {revenue: −amount, refunds}
 *   cf7  wpcf7_mail_sent              ⇒ {leads}            (form title only — never the fields people typed)
 *   crm  bizcity_crm_contact_saved    ⇒ {contacts} once per contact per day · bizcity_crm_message_inserted ⇒ {messages}
 *
 * Auto by default (owner direction: small win without setup); option `bizcity_context_producers` = 'off' switches all three off.
 * Every handler is wrapped: an error here never reaches a checkout or a form submit.
 * BizCoach Pro is its own plugin and registers its source itself (manifest `context_producers` or register_context_producer()).
 *
 * Biz Central Brain — Johnny Chu (Chu Hoàng Anh).
 * // [2026-10-12 Johnny Chu - Chu Hoàng Anh] PHASE-0.104 S104-C7 — new file.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\KG_Hub
 */

defined( 'ABSPATH' ) || exit;

// [2026-10-12 Johnny Chu - Chu Hoàng Anh] PHASE-0.104 — same fix as class-context-producers.php: no compile-time-early-bound class_exists() guard
// here (it is always true on the very first load and skips the trailing ::init() call — PHASE-0.102 S102-L31 bug, repeated here, caught by
// live-selfcheck S104-T2). `require_once` already prevents a double load.

final class BizCity_Context_Producer_Sources {

	const OPTION = 'bizcity_context_producers';

	public static function init(): void {
		add_action( 'zalo_brain_register', array( __CLASS__, 'register' ), 20 );
	}

	public static function enabled(): bool {
		return 'off' !== (string) ( function_exists( 'get_option' ) ? get_option( self::OPTION, 'auto' ) : 'auto' );
	}

	/** @return string[] ids registered */
	public static function register(): array {
		if ( ! self::enabled() || ! class_exists( 'BizCity_Zalo_Brain' ) ) {
			return array();
		}
		$done = array();
		if ( class_exists( 'WooCommerce' ) && BizCity_Zalo_Brain::register_context_producer( 'woo', array(
			'label'   => 'WooCommerce', 'icon' => 'cart', 'kind' => 'biz.order',
			'metrics' => array( 'revenue' => 'Doanh thu', 'orders' => 'Số đơn', 'refunds' => 'Hoàn tiền' ),
			'roles'   => array( 'owner', 'staff' ), 'owner' => 'core/kg-hub',
		) ) ) {
			add_action( 'woocommerce_payment_complete', array( __CLASS__, 'woo_paid' ), 20, 1 );
			add_action( 'woocommerce_order_refunded', array( __CLASS__, 'woo_refunded' ), 20, 2 );
			$done[] = 'woo';
		}
		// [2026-10-24 05:43 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.133 S133-Ma (D133-M5, doc 82 B6) — CF7 and the CRM digest get their own kinds (were common.document).
		if ( defined( 'WPCF7_VERSION' ) && BizCity_Zalo_Brain::register_context_producer( 'cf7', array(
			'label'   => 'Contact Form 7', 'icon' => 'form', 'kind' => 'crm.lead',
			'metrics' => array( 'leads' => 'Liên hệ' ),
			'roles'   => array( 'owner', 'staff' ), 'owner' => 'core/kg-hub',
		) ) ) {
			add_action( 'wpcf7_mail_sent', array( __CLASS__, 'cf7_sent' ), 20, 1 );
			$done[] = 'cf7';
		}
		if ( ( class_exists( 'BizCity_CRM_Spine' ) || class_exists( 'BizCity_CRM_Repository' ) ) && BizCity_Zalo_Brain::register_context_producer( 'crm', array(
			'label'   => 'Đội Zalo (CRM)', 'icon' => 'users', 'kind' => 'crm.digest',
			'metrics' => array( 'contacts' => 'Khách được cập nhật', 'messages' => 'Tin nhắn' ),
			'roles'   => array( 'owner', 'staff' ), 'owner' => 'core/crm', 'text_cap' => 0,
		) ) ) {
			add_action( 'bizcity_crm_contact_saved', array( __CLASS__, 'crm_contact' ), 20, 2 );
			add_action( 'bizcity_crm_message_inserted', array( __CLASS__, 'crm_message' ), 20, 2 );
			$done[] = 'crm';
		}
		return $done;
	}

	/* ── WooCommerce ── */

	public static function woo_paid( $order_id ): void {
		self::safely( static function () use ( $order_id ) {
			$o = function_exists( 'wc_get_order' ) ? wc_get_order( (int) $order_id ) : null;
			if ( ! $o ) {
				return;
			}
			$paid  = $o->get_date_paid();
			$items = method_exists( $o, 'get_item_count' ) ? (int) $o->get_item_count() : 0;
			$pay   = method_exists( $o, 'get_payment_method_title' ) ? (string) $o->get_payment_method_title() : '';
			BizCity_Context_Producers::emit( 'woo', array(
				'occurred_at' => $paid ? $paid->getTimestamp() : time(),
				'title'       => 'Đơn #' . ( method_exists( $o, 'get_order_number' ) ? $o->get_order_number() : $order_id ),
				'text'        => sprintf( 'Đơn %d sản phẩm, %s, thanh toán %s.', $items, self::money( (float) $o->get_total() ), '' !== $pay ? $pay : 'không rõ' ),
				'card'        => array( 'revenue' => (float) $o->get_total(), 'orders' => 1 ),
				'entity'      => array( 'type' => 'order', 'key' => (string) $order_id ),
			) );
		} );
	}

	public static function woo_refunded( $order_id, $refund_id = 0 ): void {
		self::safely( static function () use ( $order_id, $refund_id ) {
			$r      = $refund_id && function_exists( 'wc_get_order' ) ? wc_get_order( (int) $refund_id ) : null;
			$amount = $r && method_exists( $r, 'get_amount' ) ? abs( (float) $r->get_amount() ) : 0.0;
			BizCity_Context_Producers::emit( 'woo', array(
				'occurred_at' => time(),
				'title'       => 'Hoàn tiền đơn #' . (int) $order_id,
				'text'        => 'Hoàn ' . self::money( $amount ) . ' cho đơn #' . (int) $order_id . '.',
				'card'        => array( 'revenue' => - $amount, 'refunds' => 1 ),
				'entity'      => array( 'type' => 'refund', 'key' => (string) ( $refund_id ? $refund_id : $order_id ) ),
			) );
		} );
	}

	/* ── Contact Form 7 ── */

	public static function cf7_sent( $form ): void {
		self::safely( static function () use ( $form ) {
			$title = is_object( $form ) && method_exists( $form, 'title' ) ? (string) $form->title() : 'Form liên hệ';
			BizCity_Context_Producers::emit( 'cf7', array(
				'occurred_at' => time(),
				'title'       => 'Liên hệ qua «' . $title . '»',
				'text'        => 'Có một liên hệ mới qua form «' . $title . '».', // never the submitted fields (R-ZBF-8)
				'card'        => array( 'leads' => 1 ),
			) );
		} );
	}

	/* ── CRM spine ── */

	public static function crm_contact( $contact_id, $row = array() ): void {
		self::safely( static function () use ( $contact_id ) {
			BizCity_Context_Producers::emit( 'crm', array(
				'occurred_at' => time(),
				'title'       => 'Khách #' . (int) $contact_id,
				'card'        => array( 'contacts' => 1 ),
				'entity'      => array( 'type' => 'contact', 'key' => (string) (int) $contact_id ), // once per contact per day
			) );
		} );
	}

	public static function crm_message( $msg_id, $row = array() ): void {
		self::safely( static function () use ( $msg_id ) {
			BizCity_Context_Producers::emit( 'crm', array(
				'occurred_at' => time(),
				'title'       => 'Tin nhắn #' . (int) $msg_id,
				'card'        => array( 'messages' => 1 ),
				'entity'      => array( 'type' => 'message', 'key' => (string) (int) $msg_id ),
			) );
		} );
	}

	/* ── helpers ── */

	private static function safely( callable $fn ): void {
		if ( ! class_exists( 'BizCity_Context_Producers' ) ) {
			return;
		}
		try {
			$fn();
		} catch ( \Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[context-producer] source failed: ' . $e->getMessage() );
			}
		}
	}

	private static function money( float $v ): string {
		return number_format( $v, 0, ',', '.' ) . 'đ';
	}
}

BizCity_Context_Producer_Sources::init();
