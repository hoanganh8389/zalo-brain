<?php
/**
 * Plugin Name: BizCity Facebook Bot Integration
 * Plugin URI:  https://bizcity.vn
 * Description: Facebook Messenger + Page integration with webhook support for BizCity Automation. Bundled với bizcity-twin-ai (must-load).
 * Version:     1.0.0
 * Author:      BizCity
 * Author URI:  https://bizcity.vn
 * License:     GPL v2 or later
 * Text Domain: bizcity-facebook-bot
 *
 * BizCity PHASE 0.31 Sprint 6 — moved from mu-plugins/bizcity-facebook-bot/
 * to plugins/bizcity-twin-ai/plugins/bizcity-facebook-bot/. Loaded as a
 * bundled must-load by bizcity-twin-ai.php so it activates whenever the
 * main plugin runs (no separate WP activation needed).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// [2026-08-09 Johnny Chu] R-PERF-LOADER-BUNDLE — default TwinChat admin
// shell renders an iframe and does not need the Facebook runtime graph. Keep
// Facebook webhook, OAuth, REST, cron and dedicated admin surfaces enabled.
$_bizcity_facebook_shell_plugin = isset( $_GET['plugin'] )
	? sanitize_key( (string) $_GET['plugin'] )
	: '';
if ( is_admin()
	&& isset( $_GET['page'] )
	&& 'bizcity-twinchat' === sanitize_key( (string) $_GET['page'] )
	&& ( $_bizcity_facebook_shell_plugin === '' || $_bizcity_facebook_shell_plugin === 'twinchat' ) ) {
	return;
}
unset( $_bizcity_facebook_shell_plugin );

// Delegate to the original bootstrap (defines BIZCITY_FACEBOOK_BOT_* constants
// and wires up hooks). Guard prevents double-load when activated as a regular plugin.
if ( ! defined( 'BIZCITY_FACEBOOK_BOT_VERSION' ) ) {
	require_once __DIR__ . '/bootstrap.php';
}

// [2026-11-21 12:43 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.156 W5 (C) — the CRM Facebook adapter + bridge moved here from bizcity-twin-ai/core/crm
// (they only work while this plugin is loaded). Registered lazily: the adapter extends core's BizCity_CRM_Adapter_Base, which exists only
// once the CRM spine is loaded. An old core that still ships its copies registers them first; ours is skipped (class_exists, false / isset).
if ( ! function_exists( 'bizcity_facebook_bot_register_crm_adapter' ) ) {
	function bizcity_facebook_bot_register_crm_adapter( $adapters ) {
		if ( ! is_array( $adapters ) || isset( $adapters['facebook'] ) || ! class_exists( 'BizCity_CRM_Adapter_Base' ) ) {
			return $adapters;
		}
		foreach ( array( 'class-crm-fb-bot-bridge.php', 'class-crm-adapter-facebook.php' ) as $file ) {
			$path = __DIR__ . '/includes/crm/' . $file;
			if ( ! is_readable( $path ) ) {
				return $adapters;
			}
			require_once $path;
		}
		if ( class_exists( 'BizCity_CRM_Adapter_Facebook', false ) ) {
			$adapters['facebook'] = new BizCity_CRM_Adapter_Facebook();
		}
		return $adapters;
	}
	add_filter( 'bizcity_crm_register_adapters', 'bizcity_facebook_bot_register_crm_adapter', 10 );
}
