<?php
/**
 * [2026-10-11 11:08 AM Johnny Chu - Chu Hoàng Anh] CORE-REDUCTION WP-20 Z-6 P0-4 — Woo hooks whose owners load only on
 * admin/REST/cron requests (core/mcp Woo packs, Channel Gateway ZNS) never fired on a classic `?wc-ajax=checkout`, a gateway
 * IPN or a front-end stock change. Always loaded, ~0 cost: it only subscribes; the owner is required when a hook fires and
 * its own handlers run in the same do_action (WP_Hook picks up callbacks added at a later priority). Owner already loaded on
 * this request ⇒ no-op. Census: core/knowledge/docs/CORE-REDUCTION-WP-20/evidence/W20-Z6/p0-3-5-p1.md §P0-4.
 *
 * @package BizCity_Twin_AI
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Woo_Lazy_Listeners', false ) ) {
	return;
}

final class BizCity_Woo_Lazy_Listeners {

	/** = BizCity_Woo_Pack_Exporter::register() hooks (owner handlers @30/@31). Meta hooks stay with the owner (admin metabox). */
	const PACK_HOOKS = array( 'woocommerce_order_status_changed', 'woocommerce_new_order', 'woocommerce_order_refunded', 'woocommerce_product_set_stock', 'woocommerce_variation_set_stock', 'woocommerce_product_set_stock_status', 'woocommerce_update_product', 'woocommerce_new_product', 'edited_product_cat', 'created_product_cat', 'delete_product_cat' );

	/** = Woo hooks of BizCity_ZNS_Event_Registry::get_all_events() (dispatcher closures @10). */
	const ZNS_HOOKS = array( 'woocommerce_checkout_order_created', 'woocommerce_payment_complete', 'woocommerce_order_status_processing', 'woocommerce_order_status_completed', 'woocommerce_order_status_cancelled', 'woocommerce_order_status_refunded', 'woocommerce_order_status_on-hold' );

	const PACK_FILES = array( 'mcp/verticals/woo/class-woo-reports-bridge.php', 'mcp/verticals/woo/class-woo-pack-exporter.php' );
	const ZNS_FILES  = array( 'channel-gateway/includes/cf7/class-cf7-zns-config.php', 'channel-gateway/includes/zns/class-zns-event-registry.php', 'channel-gateway/includes/zns/class-zns-rules-repo.php', 'channel-gateway/includes/zns/class-zns-general-sender.php', 'channel-gateway/includes/zns/class-zns-send-tracker.php', 'channel-gateway/includes/zns/class-zns-dispatcher.php' );

	/** Cron hook that re-fires `bizcity_twin_agent_packs_changed` where its listeners (MCP resources, cell invalidation) load. */
	const REPLAY = 'bizcity_woo_packs_changed_replay';

	/** @var bool */
	private static $booted = false;

	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;
		foreach ( self::PACK_HOOKS as $h ) {
			add_action( $h, array( __CLASS__, 'packs' ), 29, 0 );
		}
		foreach ( self::ZNS_HOOKS as $h ) {
			add_action( $h, array( __CLASS__, 'zns' ), 9, 0 );
		}
		add_action( self::REPLAY, array( __CLASS__, 'replay' ), 10, 1 );
	}

	public static function packs(): void {
		if ( class_exists( 'BizCity_Woo_Pack_Exporter', false ) && BizCity_Woo_Pack_Exporter::registered() ) {
			return;
		}
		self::load( self::PACK_FILES );
		if ( ! class_exists( 'BizCity_Woo_Pack_Exporter', false ) ) {
			return;
		}
		BizCity_Woo_Pack_Exporter::register();
		if ( ! class_exists( 'BizCity_Zalo_Pack_Invalidate', false ) || ! class_exists( 'BizCity_MCP_Resource_Service', false ) ) {
			add_action( 'bizcity_twin_agent_packs_changed', array( __CLASS__, 'defer' ), 99, 1 );
		}
	}

	/** Listeners of packs_changed are not loaded here: hand the kinds to the next cron tick (WP drops a duplicate within 10 min). */
	public static function defer( $kinds = array() ): void {
		if ( function_exists( 'wp_schedule_single_event' ) && $kinds ) {
			wp_schedule_single_event( time(), self::REPLAY, array( array_values( array_map( 'strval', (array) $kinds ) ) ) );
		}
	}

	public static function replay( $kinds = array() ): void {
		do_action( 'bizcity_twin_agent_packs_changed', array_values( array_map( 'strval', (array) $kinds ) ) );
	}

	public static function zns(): void {
		if ( class_exists( 'BizCity_ZNS_Dispatcher', false ) ) {
			return; // Channel Gateway loaded: its init@20 owns these hooks
		}
		self::load( self::ZNS_FILES );
		if ( class_exists( 'BizCity_ZNS_Dispatcher', false ) ) {
			BizCity_ZNS_Dispatcher::init();
		}
	}

	private static function load( array $files ): void {
		$core = dirname( __DIR__ ) . '/';
		foreach ( $files as $f ) {
			if ( is_file( $core . $f ) ) {
				require_once $core . $f;
			}
		}
	}
}
