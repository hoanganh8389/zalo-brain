<?php
/**
 * BizCity_CRM_Order_Identity_Metabox — read-only box on the Woo order screen (classic + HPOS): which channel, which person,
 * which CRM contact an order made by the Agent / a cell scenario is for (PHASE-0.95 S95-F9, doc 25 §3 order meta).
 *
 * Shows the platform, the channel ref, the display name, the CRM contact and the UID MASKED (last 4 characters). Never prints the
 * full UID (R-CID-11). Nothing is editable; no save handler.
 *
 * Biz Central Brain — Johnny Chu (Chu Hoàng Anh). Bizcity Central Brain, GCN quyền tác giả số 8877/2026/QTG.
 * // [2026-10-09 03:14 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.95-S95-F9 — new file.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\CRM
 * @since      2026-10-09
 */

defined( 'ABSPATH' ) || exit;

// No class_exists() guard: PHP hoists the class, so such a guard would return before init() below (loaded with require_once).
final class BizCity_CRM_Order_Identity_Metabox {

	const BOX_ID = 'bizcity_order_identity';

	private static $booted = false;

	public static function init(): void {
		if ( self::$booted || ! function_exists( 'add_action' ) ) {
			return;
		}
		self::$booted = true;
		add_action( 'add_meta_boxes', array( __CLASS__, 'register' ), 30 );
	}

	public static function register(): void {
		if ( ! function_exists( 'add_meta_box' ) ) {
			return;
		}
		foreach ( array( 'shop_order', 'woocommerce_page_wc-orders' ) as $screen ) {
			add_meta_box( self::BOX_ID, 'Khách đặt qua kênh', array( __CLASS__, 'render' ), $screen, 'side', 'default' );
		}
	}

	/** "…" + last 4 characters; '' when empty. */
	public static function mask_uid( string $uid ): string {
		$uid = trim( $uid );
		if ( '' === $uid ) {
			return '';
		}
		return '…' . substr( $uid, -4 );
	}

	/**
	 * Rows to show for an order (pure enough to test): label => value. Empty when the order carries no identity meta.
	 *
	 * @param object $order WC_Order (get_meta)
	 * @return array<string,string>
	 */
	public static function rows( $order ): array {
		if ( ! is_object( $order ) || ! method_exists( $order, 'get_meta' ) ) {
			return array();
		}
		$platform = (string) $order->get_meta( '_bizcity_platform' );
		if ( '' === $platform ) {
			return array();
		}
		$cid = (int) $order->get_meta( '_bizcity_contact_id' );
		return array(
			'Kênh'         => $platform . ( '' !== (string) $order->get_meta( '_bizcity_channel_ref' ) ? ' · ' . (string) $order->get_meta( '_bizcity_channel_ref' ) : '' ),
			'Tên hiển thị' => (string) $order->get_meta( '_bizcity_display_name' ),
			'Khách CRM'    => $cid > 0 ? '#' . $cid : '',
			'UID'          => self::mask_uid( (string) $order->get_meta( '_bizcity_platform_uid' ) ),
			'Nguồn'        => (string) $order->get_meta( '_bizcity_source' ),
		);
	}

	/** @param WP_Post|WC_Order $post_or_order */
	public static function render( $post_or_order ): void {
		$order = is_object( $post_or_order ) && method_exists( $post_or_order, 'get_meta' ) ? $post_or_order
			: ( function_exists( 'wc_get_order' ) && is_object( $post_or_order ) && isset( $post_or_order->ID ) ? wc_get_order( (int) $post_or_order->ID ) : null );
		$rows  = self::rows( $order );
		if ( ! $rows ) {
			echo '<p>' . esc_html( 'Đơn này không tạo qua kênh chat.' ) . '</p>';
			return;
		}
		echo '<table class="widefat striped"><tbody>';
		foreach ( $rows as $label => $value ) {
			if ( '' === $value ) {
				continue;
			}
			echo '<tr><th>' . esc_html( $label ) . '</th><td>' . esc_html( $value ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}
}

BizCity_CRM_Order_Identity_Metabox::init();
