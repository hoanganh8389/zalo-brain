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
	'class-vault-owner.php'           => 'vault.owner', // [2026-11-15 09:59 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.153 V1 — whose Kho a request acts on (user id)
	'class-vault-spaces.php'          => 'vault.spaces', // [2026-11-15 10:50 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.153 V2 — ngăn nhóm / ngăn khách (vault-space@1)
	'class-vault-policy.php'          => 'vault.policy', // [2026-11-15 11:05 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.153 V3 — ma trận lưu (vault-policy@1)
	'class-vault-assistant-page.php'  => 'vault.assistant_page', // PHASE-0.153 V3 — màn "Trợ lý của tôi"
	'class-brain-vault-store.php'     => 'kg_hub.brain_vault_store', // PHASE-0.133
	'class-twin-vault.php'            => 'kg_hub.twin_vault', // PHASE-0.134 — REST bizcity-vault/v1, MCP vault.list/search/write
	'class-scope-meter.php'           => 'kg_hub.scope_meter', // PHASE-0.136 S136-M2
	'class-vault-upload.php'          => 'kg_hub.vault_upload', // PHASE-0.140 / 0.137 — "Tải tệp lên"
	'class-vault-link.php'            => 'vault.link', // [2026-11-22 03:15 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.153 S153-L1 — "🔗 Dán link" (link-import@1)
	'class-vault-tags.php'            => 'kg_hub.vault_tags', // PHASE-0.145 S145-L6 — vault-tag@1
	'class-kg-private-file-store.php' => 'vault.private_file_store', // PHASE-0.121 S121-L1 — private originals (was a plain require_once)
	'class-notebook-port.php'           => 'vault.notebook_port', // [2026-11-13 Johnny Chu - Chu Hoàng Anh] PHASE-0.148 W2 S148-C7 — the one door to the optional notebook
	'class-vault-knowledge-search.php'  => 'vault.knowledge_search', // [2026-11-13 Johnny Chu - Chu Hoàng Anh] PHASE-0.148 W2 S148-C8 — knowledge.search from the Vault
	'class-memory-vault-store.php'      => 'vault.memory_store', // [2026-11-14 11:04 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.151 M1 — Guru memory as .md (memory-vault@1)
	'class-memory-vault-mirror.php'     => 'vault.memory_mirror', // [2026-11-15 12:41 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.151 M2 — memory writes / deletes also land as .md + 1 Journal row per file
) as $_bizcity_vault_rel => $_bizcity_vault_label ) {
	$_bizcity_vault_path = __DIR__ . '/' . $_bizcity_vault_rel;
	if ( class_exists( 'BizCity_Safe_Loader' ) ) {
		BizCity_Safe_Loader::require_file( $_bizcity_vault_path, $_bizcity_vault_label );
	} elseif ( is_file( $_bizcity_vault_path ) && is_readable( $_bizcity_vault_path ) ) {
		require_once $_bizcity_vault_path;
	}
}
unset( $_bizcity_vault_rel, $_bizcity_vault_label, $_bizcity_vault_path );

if ( class_exists( 'BizCity_Vault_Assistant_Page', false ) && function_exists( 'is_admin' ) && is_admin() ) {
	BizCity_Vault_Assistant_Page::init(); // PHASE-0.153 V3 — wp-admin "🤖 Trợ lý của tôi"
}
if ( class_exists( 'BizCity_Scope_Meter', false ) ) {
	BizCity_Scope_Meter::init(); // REST bizcity-vault/v1/scope-matrix*
}
if ( class_exists( 'BizCity_Twin_Vault', false ) ) {
	BizCity_Twin_Vault::init();
}
if ( class_exists( 'BizCity_Vault_Upload', false ) ) {
	BizCity_Vault_Upload::register();
}
if ( class_exists( 'BizCity_Vault_Link', false ) ) {
	BizCity_Vault_Link::register(); // [2026-11-22 03:15 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.153 S153-L1 — POST bizcity-vault/v1/link
}
if ( class_exists( 'BizCity_Vault_Tags', false ) ) {
	BizCity_Vault_Tags::init();
}
if ( class_exists( 'BizCity_Memory_Vault_Mirror', false ) && class_exists( 'BizCity_Memory_Vault_Store', false ) ) {
	BizCity_Memory_Vault_Mirror::register(); // PHASE-0.151 M2 — listens to bizcity_memory_mirror_write / _delete (off: BIZCITY_MEMORY_VAULT_MIRROR false)
}
if ( class_exists( 'BizCity_KG_Private_File_Store', false ) ) {
	BizCity_KG_Private_File_Store::register(); // before the KG channel bridge that uses it
}
