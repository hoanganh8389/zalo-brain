<?php
/**
 * BizCity_Setup_Gate — the one place a host asks for the shared "four steps first" dialog (R-SETUP-4, PHASE-0.83 S83-G1, doc 20).
 *
 * The dialog itself is assets/setup-gate.js (plain JS, no build). It reads ONE state source — GET bizcity-channel/v1/zalo-connection/status?scope=mine —
 * and mounts the quicksetup bundle inside itself. This class only hands a host the script tags and the page config (REST base, nonce, who may configure,
 * the quicksetup bundle URLs). A host then calls, only when the person presses an action that needs the cell:
 *
 *     BizSetupGate.ensure({ reason: 'học cả website' }).then(ok => { if (ok) … });
 *
 *   enqueue()     wp-admin / any page that runs wp_enqueue_scripts (Kho tri thức, Cài đặt)
 *   print_tags()  standalone pages with their own HTML (the Scheduler page)
 *
 * No table, no option, no route, no model call. Nothing here reads the key, the domain or a secret.
 *
 * // [2026-11-23 05:43 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.83 S83-G1 — new file.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\ChannelGateway
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Setup_Gate', false ) ) {
	return;
}

final class BizCity_Setup_Gate {

	const HANDLE = 'bizcity-setup-gate';

	private static function base_dir(): string {
		return defined( 'BIZCITY_TWIN_AI_DIR' ) ? BIZCITY_TWIN_AI_DIR . 'core/channel-gateway/assets/' : '';
	}

	private static function base_url(): string {
		return defined( 'BIZCITY_TWIN_AI_URL' ) ? BIZCITY_TWIN_AI_URL . 'core/channel-gateway/assets/' : '';
	}

	private static function ver( string $file ): string {
		$p = self::base_dir() . $file;
		return '' !== self::base_dir() && is_readable( $p ) ? (string) filemtime( $p ) : ( defined( 'BIZCITY_TWIN_CORE_VERSION' ) ? (string) BIZCITY_TWIN_CORE_VERSION : '1' );
	}

	/** true when the script exists (a partial upload must not break the host page) */
	public static function available(): bool {
		return '' !== self::base_dir() && is_readable( self::base_dir() . 'setup-gate.js' );
	}

	/** window.BIZCITY_SETUP_GATE — the same boot shape every quicksetup host uses (core/scheduler/views/page-scheduler.php). */
	public static function config(): array {
		$manage = class_exists( 'BizCity_Network_Admin_Capability' ) ? (bool) BizCity_Network_Admin_Capability::can_manage() : current_user_can( 'manage_options' );
		$nonce  = wp_create_nonce( 'wp_rest' );
		$admin  = admin_url( 'admin.php?page=bizchat-gateway-spa' );
		$base   = self::base_url();
		return array(
			'rest'        => esc_url_raw( rest_url( 'bizcity-channel/v1/' ) ),
			'nonce'       => $nonce,
			'canManage'   => $manage,
			'adminUrl'    => $admin,
			'quicksetup'  => array(
				'jsUrl'  => '' !== $base ? esc_url_raw( $base . 'dist/quicksetup-app.js?ver=' . rawurlencode( self::ver( 'dist/quicksetup-app.js' ) ) ) : '',
				'cssUrl' => '' !== $base ? esc_url_raw( $base . 'dist/quicksetup-app.css?ver=' . rawurlencode( self::ver( 'dist/quicksetup-app.css' ) ) ) : '',
				'boot'   => array(
					'restUrl'   => '/wp-json/bizcity-channel/v1/',
					'restNonce' => $nonce,
					'adminUrl'  => $admin,
					'siteUrl'   => '/',
					'caps'      => array( 'manage' => $manage, 'send' => false ),
					'platforms' => array(),
				),
			),
		);
	}

	/** For pages that run the WordPress enqueue system. Safe to call twice. */
	public static function enqueue(): void {
		if ( ! self::available() || ! function_exists( 'wp_enqueue_script' ) || ! is_user_logged_in() ) {
			return;
		}
		wp_enqueue_style( self::HANDLE, self::base_url() . 'setup-gate.css', array(), self::ver( 'setup-gate.css' ) );
		wp_enqueue_script( self::HANDLE, self::base_url() . 'setup-gate.js', array(), self::ver( 'setup-gate.js' ), true );
		wp_add_inline_script( self::HANDLE, 'window.BIZCITY_SETUP_GATE = ' . wp_json_encode( self::config(), JSON_HEX_TAG | JSON_HEX_AMP ) . ';', 'before' );
	}

	/** For a standalone page that prints its own HTML: call once, before the host's own script runs an action. */
	public static function print_tags(): void {
		if ( ! self::available() || ! is_user_logged_in() ) {
			return;
		}
		echo '<link rel="stylesheet" href="' . esc_url( self::base_url() . 'setup-gate.css?ver=' . rawurlencode( self::ver( 'setup-gate.css' ) ) ) . '">' . "\n";
		echo '<script>window.BIZCITY_SETUP_GATE = ' . wp_json_encode( self::config(), JSON_HEX_TAG | JSON_HEX_AMP ) . ';</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<script src="' . esc_url( self::base_url() . 'setup-gate.js?ver=' . rawurlencode( self::ver( 'setup-gate.js' ) ) ) . '"></script>' . "\n";
	}
}
