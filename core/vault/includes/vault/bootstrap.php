<?php
/**
 * BizCity Vault — server group (twin-vault@1 · vault-append@1): Markdown store, Kho tri thức REST `bizcity-vault/v1`,
 * MCP `vault.*`, upload, tags, scope meter, private original files.
 *
 * Loaded at the journal's load point (core/kg-hub/bootstrap.php today, the fallback in bizcity-twin-ai.php without the
 * notebook) — NOT from core/vault/bootstrap.php, whose gate is narrower (admin/REST/cron, never the TwinChat page): moving the
 * server group there would change where `BizCity_Twin_Vault` exists. The SPA + skill library stay in core/vault/bootstrap.php.
 *
 * Dependency direction (PHASE-0.148 D148-19): core/journal ⇒ core/sheet ⇒ core/vault — Vault may call Journal and Sheet.
 *
 * // [2026-11-12 11:22 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.148 W1d (doc 60 §2, D148-14) — files moved from core/kg-hub/includes/
 * // unchanged; class names kept (BizCity_KG_Private_File_Store too). Same Safe Loader labels.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Vault
 */

defined( 'ABSPATH' ) || exit;

if ( defined( 'BIZCITY_VAULT_SERVER_LOADED' ) ) {
	return;
}
define( 'BIZCITY_VAULT_SERVER_LOADED', true );
if ( ! defined( 'BIZCITY_VAULT_DIR' ) ) {
	define( 'BIZCITY_VAULT_DIR', dirname( __DIR__, 2 ) . '/' );
}

foreach ( array(
	'class-brain-vault-store.php'     => 'kg_hub.brain_vault_store', // PHASE-0.133
	'class-twin-vault.php'            => 'kg_hub.twin_vault', // PHASE-0.134 — REST bizcity-vault/v1, MCP vault.list/search/write
	'class-scope-meter.php'           => 'kg_hub.scope_meter', // PHASE-0.136 S136-M2
	'class-vault-upload.php'          => 'kg_hub.vault_upload', // PHASE-0.140 / 0.137 — "Tải tệp lên"
	'class-vault-tags.php'            => 'kg_hub.vault_tags', // PHASE-0.145 S145-L6 — vault-tag@1
	'class-kg-private-file-store.php' => 'vault.private_file_store', // PHASE-0.121 S121-L1 — private originals (was a plain require_once)
) as $_bizcity_vault_rel => $_bizcity_vault_label ) {
	$_bizcity_vault_path = __DIR__ . '/' . $_bizcity_vault_rel;
	if ( class_exists( 'BizCity_Safe_Loader' ) ) {
		BizCity_Safe_Loader::require_file( $_bizcity_vault_path, $_bizcity_vault_label );
	} elseif ( is_file( $_bizcity_vault_path ) && is_readable( $_bizcity_vault_path ) ) {
		require_once $_bizcity_vault_path;
	}
}
unset( $_bizcity_vault_rel, $_bizcity_vault_label, $_bizcity_vault_path );

if ( class_exists( 'BizCity_Scope_Meter', false ) ) {
	BizCity_Scope_Meter::init(); // REST bizcity-vault/v1/scope-matrix*
}
if ( class_exists( 'BizCity_Twin_Vault', false ) ) {
	BizCity_Twin_Vault::init();
}
if ( class_exists( 'BizCity_Vault_Upload', false ) ) {
	BizCity_Vault_Upload::register();
}
if ( class_exists( 'BizCity_Vault_Tags', false ) ) {
	BizCity_Vault_Tags::init();
}
if ( class_exists( 'BizCity_KG_Private_File_Store', false ) ) {
	BizCity_KG_Private_File_Store::register(); // before the KG channel bridge that uses it
}
