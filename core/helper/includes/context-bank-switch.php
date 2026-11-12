<?php
/**
 * Context Bank kill switch + cron unschedule (PHASE-0.144 S144-CB0). Pure functions, no side effects except one init hook.
 */

defined( 'ABSPATH' ) || exit;

// [2026-10-28 Johnny Chu - Chu Hoàng Anh] PHASE-0.144 S144-CB0 — Context Bank kill switch. Owner decision (2026-10-28): Context Bank stays the standard for recording information, but its CRM / Commerce / channel-archive / event-stream / CF7 capture paths are TEMPORARILY OFF (memory comes from the cell only).
// OFF (default) = no capture adapters, no REST/admin page, no rollup/KG cron. The MEMORY READ path
// (bizcity_context_bank_load_memory_runtime + BizCity_Context_Bank_Memory_Adapter::query) is NOT governed by this switch.
// Tables/options are never touched. Override: define('BIZCITY_CONTEXT_BANK_ENABLED', true) or the filter below.
if ( ! function_exists( 'bizcity_context_bank_enabled' ) ) {
	function bizcity_context_bank_enabled() {
		if ( defined( 'BIZCITY_CONTEXT_BANK_ENABLED' ) ) {
			return (bool) BIZCITY_CONTEXT_BANK_ENABLED;
		}
		return function_exists( 'apply_filters' ) ? (bool) apply_filters( 'bizcity_context_bank_enabled', false ) : false;
	}
}
if ( ! function_exists( 'bizcity_context_bank_unschedule_cron' ) ) {
	// [2026-10-28 Johnny Chu - Chu Hoàng Anh] PHASE-0.144 S144-CB0 — unschedule leftover Context Bank cron events when the switch is off (idempotent, no data touched).
	function bizcity_context_bank_unschedule_cron() {
		if ( bizcity_context_bank_enabled() || ! function_exists( 'wp_clear_scheduled_hook' ) ) {
			return 0;
		}
		$cleared = 0;
		foreach ( array( 'bizcity_context_bank_rollup_run', 'bizcity_context_bank_kg_recheck' ) as $hook ) {
			if ( function_exists( 'wp_next_scheduled' ) && false !== wp_next_scheduled( $hook ) ) {
				wp_clear_scheduled_hook( $hook );
				$cleared++;
			}
		}
		return $cleared;
	}
	if ( function_exists( 'add_action' ) ) {
		add_action( 'init', 'bizcity_context_bank_unschedule_cron', 31 );
	}
}
