<?php
/**
 * BizCity Journal — the ledger spine of Zalo Brain (journal-entry@1.2 · journal-kinds@1.3).
 *
 * One table `bizcity_journal_entries`; one write door `bizcity_journal_record()`; one read door `BizCity_Journal_Index`
 * (+ MCP `journal.*` registered by core/mcp). Also here: brain-access@1 (who may do what), brain-resource-link,
 * entry-meta, evidence, job-link, the context producers, and the Brain map API (`journal/map`, `journal/maturity`).
 *
 * Dependency direction (PHASE-0.148 D148-19): core/journal ⇒ core/sheet ⇒ core/vault ⇒ core/mcp. Journal never calls a
 * Sheet / Vault class — they listen to journal hooks (`bizcity_journal_entry_added`, `bizcity_journal_pre_add` …).
 *
 * Load profile: exactly where the journal loaded before (inside core/kg-hub/bootstrap.php, same position, same order,
 * same Safe Loader labels), plus a guarded fallback in bizcity-twin-ai.php for a site without the notebook (KG). Idempotent.
 *
 * // [2026-11-12 11:22 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.148 W1b (doc 60 D148-16) — new module; files moved from
 * // core/kg-hub/includes/ unchanged (class names, tables, routes, tool names kept — D148-3). Before WP-12 the journal lived in
 * // core/skills; 0.101 said "journal NEVER loaded again" there — it is now its own core module instead.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Journal
 */

defined( 'ABSPATH' ) || exit;

if ( defined( 'BIZCITY_JOURNAL_LOADED' ) ) {
	return;
}
define( 'BIZCITY_JOURNAL_LOADED', true );
if ( ! defined( 'BIZCITY_JOURNAL_DIR' ) ) {
	define( 'BIZCITY_JOURNAL_DIR', __DIR__ . '/' );
}
if ( ! defined( 'BIZCITY_JOURNAL_INCLUDES' ) ) {
	define( 'BIZCITY_JOURNAL_INCLUDES', BIZCITY_JOURNAL_DIR . 'includes/' );
}

// Order kept from core/kg-hub/bootstrap.php (PHASE-0.100 … 0.145): database first; kinds before index/filer (both read it);
// index before the pack exporter; brain-map after index + exporter; pipeline after brain-map (reuses doc_level).
foreach ( array(
	'class-journal-database.php'                 => 'kg_hub.journal_database', // WP-12 R3 — the Zalo Bot capture refuses to learn without it
	'class-journal-kinds.php'                    => 'kg_hub.journal_kinds',
	'class-brain-resource-link.php'              => 'kg_hub.brain_resource_link', // PHASE-0.133
	'class-journal-entry-meta.php'               => 'kg_hub.journal_entry_meta',
	'class-journal-evidence.php'                 => 'kg_hub.journal_evidence',
	'class-journal-index.php'                    => 'kg_hub.journal_index',
	'class-journal-contact-profile.php'          => 'kg_hub.journal_contact_profile', // PHASE-0.136 S136-L4
	'class-journal-filer.php'                    => 'kg_hub.journal_filer',
	'class-journal-doc-index-pack-exporter.php'  => 'kg_hub.journal_doc_index_pack_exporter',
	'class-journal-rest.php'                     => 'kg_hub.journal_rest',
	'class-journal-purge.php'                    => 'kg_hub.journal_purge', // PHASE-0.122 S122-K1
	'class-context-producers.php'                => 'kg_hub.context_producers', // PHASE-0.104 S104-C1 (self-init at file end)
	'journal-api.php'                            => 'kg_hub.journal_api', // PHASE-0.121 S121-J5 (R-JN-17) — the ONE write door for plugins
	'class-journal-ingest-listener.php'          => 'kg_hub.journal_ingest_listener',
	'class-journal-output-listener.php'          => 'kg_hub.journal_output_listener',
	'class-journal-job-link.php'                 => 'kg_hub.journal_job_link', // PHASE-0.126 S126-J1…J3
	'class-brain-access.php'                     => 'kg_hub.brain_access', // requires class-journal-doc-meta.php itself
	'class-journal-tool-axis.php'                => 'kg_hub.journal_tool_axis',
	'class-journal-skill-link.php'               => 'kg_hub.journal_skill_link',
	'class-journal-layer-rows.php'               => 'kg_hub.journal_layer_rows',
	'class-journal-brain-map.php'                => 'kg_hub.journal_brain_map', // PHASE-0.129 W2 — brain-map@1 (the screen is in 📚 Kho tri thức)
	'class-journal-pipeline.php'                 => 'kg_hub.journal_pipeline', // PHASE-0.137 S137-B1
	'class-journal-turn-uses.php'                => 'kg_hub.journal_turn_uses', // PHASE-0.137 S137-B8
	'class-journal-send-zalo.php'                => 'kg_hub.journal_send_zalo', // PHASE-0.145 S145-U6
	'class-journal-backfill.php'                 => 'kg_hub.journal_backfill', // PHASE-0.121 B2-6
	'class-journal-meta-backfill.php'            => 'kg_hub.journal_meta_backfill', // PHASE-0.133 S133-Mc
	'class-context-producer-sources.php'         => 'kg_hub.context_producer_sources', // PHASE-0.104 S104-C7 (self-init at file end)
) as $_bizcity_journal_rel => $_bizcity_journal_label ) {
	$_bizcity_journal_path = BIZCITY_JOURNAL_INCLUDES . $_bizcity_journal_rel;
	if ( class_exists( 'BizCity_Safe_Loader' ) ) {
		BizCity_Safe_Loader::require_file( $_bizcity_journal_path, $_bizcity_journal_label );
	} elseif ( is_file( $_bizcity_journal_path ) && is_readable( $_bizcity_journal_path ) ) {
		require_once $_bizcity_journal_path;
	}
}
unset( $_bizcity_journal_rel, $_bizcity_journal_label, $_bizcity_journal_path );

// Registrations — same order as before (minus the Sheet / Vault / KG ones, now in their own modules).
if ( class_exists( 'BizCity_Journal_Doc_Index_Pack_Exporter', false ) ) {
	BizCity_Journal_Doc_Index_Pack_Exporter::register();
}
if ( class_exists( 'BizCity_Journal_REST', false ) ) {
	BizCity_Journal_REST::init();
}
if ( class_exists( 'BizCity_Journal_Brain_Map', false ) ) {
	BizCity_Journal_Brain_Map::init(); // GET bizcity-knowledge/v2/journal/map
}
if ( class_exists( 'BizCity_Journal_Purge', false ) ) {
	BizCity_Journal_Purge::init(); // MCP journal.purge
}
if ( class_exists( 'BizCity_Journal_Ingest_Listener', false ) ) {
	BizCity_Journal_Ingest_Listener::register(); // R-JN-13 — web / notebook uploads write their row
}
if ( class_exists( 'BizCity_Journal_Output_Listener', false ) ) {
	BizCity_Journal_Output_Listener::register(); // R-JN-19
}
if ( class_exists( 'BizCity_Journal_Backfill', false ) ) {
	BizCity_Journal_Backfill::register();
}
if ( class_exists( 'BizCity_Journal_Contact_Profile', false ) ) {
	BizCity_Journal_Contact_Profile::init();
}
if ( class_exists( 'BizCity_Journal_Send_Zalo', false ) ) {
	BizCity_Journal_Send_Zalo::register();
}
if ( class_exists( 'BizCity_Journal_Meta_Backfill', false ) ) {
	BizCity_Journal_Meta_Backfill::register();
}
if ( class_exists( 'BizCity_Journal_Pipeline', false ) ) {
	BizCity_Journal_Pipeline::register(); // GET journal/pipeline + POST journal/rebuild-read
}
if ( class_exists( 'BizCity_Journal_Job_Link', false ) ) {
	BizCity_Journal_Job_Link::register(); // job-link@1 (after BizCity_Sheet_Bindings at 10)
}
if ( class_exists( 'BizCity_Journal_Skill_Link', false ) ) {
	BizCity_Journal_Skill_Link::register();
}
if ( class_exists( 'BizCity_Journal_Tool_Axis', false ) ) {
	BizCity_Journal_Tool_Axis::register();
}
if ( class_exists( 'BizCity_Journal_Layer_Rows', false ) ) {
	BizCity_Journal_Layer_Rows::register();
}
