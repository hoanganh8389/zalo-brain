<?php
/**
 * BizCity Sheet — Twin Sheet ("Sổ", twin-sheet@1): definitions + read/write doors over journal rows (no table, D123-1).
 * BRAIN-LEDGER (sheet_key brain.ledger) is one sheet built on the journal.
 *
 * Dependency direction (PHASE-0.148 D148-19): core/journal ⇒ core/sheet ⇒ core/vault. A sheet row IS a journal row, so this
 * module needs core/journal loaded first; it must never call a Vault class.
 *
 * // [2026-11-12 11:22 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.148 W1c (doc 60 D148-16) — new module; the nine files moved from
 * // core/kg-hub/includes/twin-sheet/ unchanged (now flat in includes/). Same load point, order and Safe Loader labels as before.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Sheet
 */

defined( 'ABSPATH' ) || exit;

if ( defined( 'BIZCITY_SHEET_LOADED' ) ) {
	return;
}
define( 'BIZCITY_SHEET_LOADED', true );
if ( ! defined( 'BIZCITY_SHEET_DIR' ) ) {
	define( 'BIZCITY_SHEET_DIR', __DIR__ . '/' );
}

// PHASE-0.123 W1…W3 — schema · registry · store · builder · CPT defs · service (before the API that calls it) · snapshot · structure · API.
foreach ( array(
	'class-twin-sheet-schema.php'    => 'kg_hub.twin_sheet_schema',
	'class-twin-sheet-registry.php'  => 'kg_hub.twin_sheet_registry',
	'class-twin-sheet-store.php'     => 'kg_hub.twin_sheet_store',
	'class-twin-sheet-builder.php'   => 'kg_hub.twin_sheet_builder',
	'class-twin-sheet-defs.php'      => 'kg_hub.twin_sheet_defs',
	'class-twin-sheet-service.php'   => 'kg_hub.twin_sheet_service',
	'class-twin-sheet-snapshot.php'  => 'kg_hub.twin_sheet_snapshot',
	'class-twin-sheet-structure.php' => 'kg_hub.twin_sheet_structure',
	'class-twin-sheet-api.php'       => 'kg_hub.twin_sheet_api',
) as $_bizcity_sheet_rel => $_bizcity_sheet_label ) {
	$_bizcity_sheet_path = BIZCITY_SHEET_DIR . 'includes/' . $_bizcity_sheet_rel;
	if ( class_exists( 'BizCity_Safe_Loader' ) ) {
		BizCity_Safe_Loader::require_file( $_bizcity_sheet_path, $_bizcity_sheet_label );
	} elseif ( is_file( $_bizcity_sheet_path ) && is_readable( $_bizcity_sheet_path ) ) {
		require_once $_bizcity_sheet_path;
	}
}
unset( $_bizcity_sheet_rel, $_bizcity_sheet_label, $_bizcity_sheet_path );

// REST bizcity-knowledge/v2/twinsheets* + MCP twinsheet.*
if ( class_exists( 'BizCity_Twin_Sheet_API', false ) ) {
	BizCity_Twin_Sheet_API::init();
}
