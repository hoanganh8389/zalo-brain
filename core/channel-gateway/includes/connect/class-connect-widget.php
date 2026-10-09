<?php
/**
 * Connect widget assets — `window.BizCityConnect` for hosts outside the Channel Gateway SPA (PHASE-0.93 D93-5 / S93-U5).
 *
 * A host (Automation /twin/?plugin=workflow, CRM, /gpt/) calls BizCity_Connect_Widget::enqueue() — or fires
 * do_action( 'bizcity_connect_widget_enqueue' ) so it needs no hard dependency on this plugin — and then
 * window.BizCityConnect.open( 'facebook' ) shows the SAME four-step sheet as the Channel Gateway "Kết nối" page.
 *
 * [2026-10-06 10:50 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.93 S93-U5 — new file.
 *
 * @package BizCity_Twin_AI
 * @subpackage Channel_Gateway
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Connect_Widget', false ) ) {
	return;
}

final class BizCity_Connect_Widget {

	const HANDLE = 'bizcity-connect-widget';

	public static function init(): void {
		add_action( 'bizcity_connect_widget_enqueue', array( __CLASS__, 'enqueue' ) );
	}

	public static function enqueue(): void {
		if ( ! is_user_logged_in() || wp_script_is( self::HANDLE, 'enqueued' ) ) {
			return;
		}
		$dir = dirname( __DIR__, 2 ) . '/assets/dist/';
		$url = plugins_url( 'assets/dist/', dirname( __DIR__, 2 ) . '/bootstrap.php' );
		if ( ! is_file( $dir . 'connect-widget.js' ) ) {
			return;
		}
		wp_enqueue_style( self::HANDLE, $url . 'connect-widget.css', array(), (string) filemtime( $dir . 'connect-widget.css' ) );
		wp_enqueue_script( self::HANDLE, $url . 'connect-widget.js', array(), (string) filemtime( $dir . 'connect-widget.js' ), true );
		$boot = array(
			'restUrl'   => esc_url_raw( rest_url( 'bizcity-channel/v1/' ) ),
			'restNonce' => wp_create_nonce( 'wp_rest' ),
			'siteUrl'   => esc_url_raw( home_url( '/' ) ),
			'adminUrl'  => esc_url_raw( admin_url() ),
			'caps'      => array( 'manage' => class_exists( 'BizCity_Connect_REST' ) && BizCity_Connect_REST::can_manage(), 'send' => false ),
			'platforms' => array(),
		);
		// only when the full Channel Gateway SPA is not on the page (it brings its own BIZCITY_CG_BOOT)
		wp_add_inline_script( self::HANDLE, 'window.BIZCITY_CG_BOOT = window.BIZCITY_CG_BOOT || ' . wp_json_encode( $boot ) . ';', 'before' );
	}
}
