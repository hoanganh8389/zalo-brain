<?php
/**
 * Bizcity Twin AI — Knowledge Graph Hub
 *
 * Bootstrap loader for the KG-Hub module (Phase 0.3).
 * Mounts under core/knowledge as a sub-module so it inherits all existing
 * infrastructure (embedding, LLM router, character system, multisite shard).
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Knowledge\KG_Hub
 * @author     Johnny Chu (Chu Hoàng Anh)
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 * @since      2026-04-25
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

if ( ! defined( 'BIZCITY_KG_HUB_DIR' ) ) {
	define( 'BIZCITY_KG_HUB_DIR', __DIR__ . '/' );
}
if ( ! defined( 'BIZCITY_KG_HUB_URL' ) ) {
	// [2026-09-24 Claude Opus 5.5] CORE-REDUCTION WP-11 Part A — resolve from this file, not from the
	// knowledge module: KG-Hub is a peer folder (core/kg-hub/) now.
	define( 'BIZCITY_KG_HUB_URL', plugin_dir_url( __FILE__ ) );
}
if ( ! defined( 'BIZCITY_KG_HUB_VERSION' ) ) {
	define( 'BIZCITY_KG_HUB_VERSION', '0.21.2' );
}
if ( ! defined( 'BIZCITY_KG_HUB_INCLUDES' ) ) {
	define( 'BIZCITY_KG_HUB_INCLUDES', BIZCITY_KG_HUB_DIR . 'includes/' );
}
if ( ! defined( 'BIZCITY_KG_HUB_SKELETON' ) ) {
	// PHASE-6.6 — skeleton subsystem isolated under /skeleton/ for clarity.
	define( 'BIZCITY_KG_HUB_SKELETON', BIZCITY_KG_HUB_DIR . 'skeleton/' );
}
if ( ! defined( 'BIZCITY_KG_HUB_PROMPTS' ) ) {
	define( 'BIZCITY_KG_HUB_PROMPTS', BIZCITY_KG_HUB_DIR . 'prompts/' );
}
// [2026-09-24 Claude Opus 5] CORE-REDUCTION-WP-09 T3 — `BIZCITY_KG_HUB_UI_DIR` is gone
// with the React app it addressed. The KG UI now lives in `modules/twinkg/ui` and is
// served by that module (constant `BIZCITY_TWINKG_UI_DIR`); core/knowledge keeps only
// the REST controllers and the `BizCity_KG` facade (rule R-KG-UI).

// ─── Includes ──────────────────────────────────────────────────────────────
require_once BIZCITY_KG_HUB_INCLUDES . 'class-kg-cost-guard.php';
require_once BIZCITY_KG_HUB_INCLUDES . 'class-kg-database.php';
// PHASE-0.3 Identity Algorithm (transparency-first, 2026-05-08) — pure
// regex-based extractor used by tool wrapper + prompt resolver. No DB.
require_once BIZCITY_KG_HUB_INCLUDES . 'class-kg-identity-extractor.php';
// PHASE-0.3 Wave 2 — backfill engine + WP-CLI + REST. Loaded after database.
require_once BIZCITY_KG_HUB_INCLUDES . 'class-kg-identity-backfill.php';
// [2026-10-01 Claude Sonnet 5] CORE-REDUCTION WP-17 K-1 (R-LEAN-4) — moved here from core/guru/profile/lib/ (same
// class names). class-kg-vector-index.php and the bin diagnostic page call BizCity_Knowledge_Embedding behind a
// class_exists guard; KG-Hub is now its real owner instead of relying on core/knowledge having loaded it first.
require_once BIZCITY_KG_HUB_INCLUDES . 'class-embedding.php';
require_once BIZCITY_KG_HUB_INCLUDES . 'class-file-parser.php';
require_once BIZCITY_KG_HUB_INCLUDES . 'class-kg-vector-index.php';
require_once BIZCITY_KG_HUB_INCLUDES . 'class-kg-vector-file-store.php';
// PHASE-0.7-LEARN-VECTOR-FILE (Wave F0-F2, 2026-05-20) — content filestore companion.
// [2026-10-16 04:07 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.116 CS5.1 — file-store rollout flags moved here from core/cron (class-cron-tier-settings.php).
require_once BIZCITY_KG_HUB_INCLUDES . 'filestore/class-kg-filestore-flags.php';
require_once BIZCITY_KG_HUB_INCLUDES . 'filestore/class-kg-notebook-folder.php';
require_once BIZCITY_KG_HUB_INCLUDES . 'filestore/class-kg-md-parser.php';
// [2026-07-24 Johnny Chu] PHASE-0.46-FILE-BODY — file-first source/chunk body persistence.
require_once BIZCITY_KG_HUB_INCLUDES . 'filestore/class-kg-source-body-file-store.php';
require_once BIZCITY_KG_HUB_INCLUDES . 'filestore/class-kg-passage-file-store.php';
require_once BIZCITY_KG_HUB_INCLUDES . 'filestore/class-kg-jsonl-stream.php';
require_once BIZCITY_KG_HUB_INCLUDES . 'filestore/class-kg-entity-file-store.php';
require_once BIZCITY_KG_HUB_INCLUDES . 'filestore/class-kg-relation-file-store.php';
require_once BIZCITY_KG_HUB_INCLUDES . 'filestore/class-kg-filestore-dispatcher.php';
require_once BIZCITY_KG_HUB_INCLUDES . 'filestore/class-kg-content-router.php';
require_once BIZCITY_KG_HUB_INCLUDES . 'filestore/class-kg-filestore-backfill.php';
require_once BIZCITY_KG_HUB_INCLUDES . 'filestore/class-kg-filestore-diagnostic.php';
require_once BIZCITY_KG_HUB_INCLUDES . 'filestore/class-kg-graph-embedding-migration.php';
require_once BIZCITY_KG_HUB_INCLUDES . 'filestore/class-kg-triplet-raw-migration.php';

// [2026-08-25 Johnny Chu] PHASE-1.24 — register the filestore interval at the KG owner boundary so cron rescheduling works when the main plugin schedule filter is not loaded.
if ( ! function_exists( 'bizcity_kg_register_filestore_schedule' ) ) {
	function bizcity_kg_register_filestore_schedule( $schedules ) {
		// [2026-08-25 Johnny Chu] PHASE-1.24 — preserve the canonical schedule name used by all KG filestore migration hooks.
		if ( ! isset( $schedules['bizcity_kg_5min'] ) ) {
			$schedules['bizcity_kg_5min'] = array(
				'interval' => 5 * MINUTE_IN_SECONDS,
				'display'  => 'KG Filestore (5 min)',
			);
		}
		return $schedules;
	}
}
add_filter( 'cron_schedules', 'bizcity_kg_register_filestore_schedule', 1 );
BizCity_KG_Filestore_Backfill::instance()->bind();
// [2026-07-23 Johnny Chu] PHASE-0.45-KG-FILE-GRAPH — migrate legacy entity/relation embedding LONGTEXT to .embed.bin sidecars.
BizCity_KG_Graph_Embedding_Migration::instance()->bind();
// [2026-07-23 Johnny Chu] PHASE-0.45-KG-FILE-GRAPH — move kg_triplet_queue.raw_llm_output to JSONL and scrub SQL TEXT by default.
BizCity_KG_Triplet_Raw_Migration::instance()->bind();
// bind() must run outside is_admin() so the cron_schedules filter is always
// registered — cron context is not admin and needs bizcity_kg_weekly etc.
// AJAX/admin-UI hooks inside bind() are no-ops when not in admin context.
BizCity_KG_Filestore_Diagnostic::instance()->bind();
require_once BIZCITY_KG_HUB_INCLUDES . 'class-kg-embedding-writer.php';
require_once BIZCITY_KG_HUB_INCLUDES . 'class-kg-notebook-service.php';
require_once BIZCITY_KG_HUB_INCLUDES . 'class-kg-access.php';
require_once BIZCITY_KG_HUB_INCLUDES . 'class-kg-public-link-service.php';
require_once BIZCITY_KG_HUB_INCLUDES . 'class-kg-source-service.php';
// [2026-07-25 Johnny Chu] PHASE-0.46 W4.5 — dedicated JSONL logger for notebook bridge capture lifecycle.
require_once BIZCITY_KG_HUB_INCLUDES . 'class-kg-notebook-bridge-file-logger.php';
BizCity_KG_Notebook_Bridge_File_Logger::init();
// [2026-09-26 Claude Opus 5.5] CORE-REDUCTION WP-12 R3 — Journal Entry storage moved here from core/skills (archived);
// the Zalo Bot capture refuses to learn ("Không thể lưu nhật ký") without it.
if ( class_exists( 'BizCity_Safe_Loader' ) ) {
	BizCity_Safe_Loader::require_file( BIZCITY_KG_HUB_INCLUDES . 'class-journal-database.php', 'kg_hub.journal_database' );
}
// [2026-10-12 12:20 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.100 S100-L15/L16/L17/L18/L22 (R-JOURNAL) — the journal
// index/filer/kinds/pack/REST layer on top of `bizcity_journal_entries` (doc 92). Order matters: kinds before
// index/filer (both read it), index before the pack exporter (the exporter reads the index/database directly).
foreach ( array(
	'class-journal-kinds.php'                    => 'kg_hub.journal_kinds',
	// [2026-10-24 05:38 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.133 — resource link · entry-meta · evidence · vault store (pure / file helpers the index reads; before it).
	'class-brain-resource-link.php'              => 'kg_hub.brain_resource_link',
	'class-journal-entry-meta.php'               => 'kg_hub.journal_entry_meta',
	'class-journal-evidence.php'                 => 'kg_hub.journal_evidence',
	'class-brain-vault-store.php'                => 'kg_hub.brain_vault_store',
	'class-twin-vault.php'                       => 'kg_hub.twin_vault', // [2026-10-24 09:04 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.134 — Twin Vault · Kho tri thức (REST bizcity-vault/v1, MCP vault.list/search/write)
	'class-journal-index.php'                    => 'kg_hub.journal_index',
	'class-journal-contact-profile.php'            => 'kg_hub.journal_contact_profile', // [2026-10-25] PHASE-0.136 S136-L4 — Hồ sơ khách (view over the contact's rows)
	'class-scope-meter.php'                        => 'kg_hub.scope_meter', // [2026-10-25] PHASE-0.136 S136-M2 — Matrix "Phạm vi tri thức": every knowledge read measured at the site
	'class-journal-filer.php'                     => 'kg_hub.journal_filer',
	'class-journal-doc-index-pack-exporter.php'  => 'kg_hub.journal_doc_index_pack_exporter',
	'class-journal-rest.php'                      => 'kg_hub.journal_rest',
	'class-journal-purge.php'                     => 'kg_hub.journal_purge', // [2026-10-18 07:30 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.122 S122-K1 — xoá thật một mục (hai bước, mã chủ tự gõ)
	'class-context-producers.php'                 => 'kg_hub.context_producers', // [2026-10-12 Johnny Chu - Chu Hoàng Anh] PHASE-0.104 S104-C1 — context-producer@1 (after the journal index it writes to)
	'journal-api.php'                             => 'kg_hub.journal_api', // [2026-10-17 07:44 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.121 S121-J5 (R-JN-17) — bizcity_journal_record() + friends, the ONE write door for plugins
	'class-journal-ingest-listener.php'           => 'kg_hub.journal_ingest_listener',
	'class-journal-learning-sync.php'             => 'kg_hub.journal_learning_sync',
	'class-journal-output-listener.php'           => 'kg_hub.journal_output_listener',
	// [2026-10-20 10:53 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.126 S126-J1…J3 / P1…P2 (R-BRAIN-SKELETON) — job link (JB- header + join keys) and the one access decision point; after the index they read.
	'class-journal-job-link.php'                  => 'kg_hub.journal_job_link',
	'class-brain-access.php'                      => 'kg_hub.brain_access',
	'class-journal-tool-axis.php'                 => 'kg_hub.journal_tool_axis', // [2026-10-20 11:52 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.126 W3 — write tools that declare `journal` feed the spine
	'class-journal-skill-link.php'                => 'kg_hub.journal_skill_link', // [2026-10-20 11:52 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.126 S126-K1 (BS-9) — skill.define / revision / state rows (KN-)
	'class-journal-layer-rows.php'                => 'kg_hub.journal_layer_rows', // [2026-10-21 11:14 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.126 S126-B3 — notebook (NB-) and person-made sheet (SO-) rows
	'class-journal-brain-map.php'                 => 'kg_hub.journal_brain_map', // [2026-10-22 01:50 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.129 W2 — Bản đồ não (brain-map@1): a view over the person's rows, after the index + pack exporter it reads
	'class-journal-pipeline.php'                  => 'kg_hub.journal_pipeline', // [2026-10-25 12:30 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.137 S137-B1 — journal-pipeline@1: the four steps of a file derived from its row (after brain-map, whose doc_level it reuses)
	'class-kg-learn-gate.php'                     => 'kg_hub.learn_gate', // [2026-10-17 09:20 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.121 B3-1 (S121-K6) — the one L2 (graph) gate // [2026-10-17 09:05 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.121 B2-4/B2-5 — cell outputs + Automation results get one row (R-JN-19) // [2026-10-17 08:40 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.121 B1-1 (S121-A1) — learning progress onto the row // [2026-10-17 07:44 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.121 S121-J4 (R-JN-13) — every notebook source gets a row
	'class-journal-backfill.php'                  => 'kg_hub.journal_backfill', // [2026-10-17 09:05 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.121 B2-6 (S121-J8) — admin batch backfill, dry run first
	'class-journal-meta-backfill.php'             => 'kg_hub.journal_meta_backfill', // [2026-10-24 05:56 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.133 S133-Mc — find_text + resource for older rows, dry run first
	'class-context-producer-sources.php'          => 'kg_hub.context_producer_sources', // [2026-10-12 Johnny Chu - Chu Hoàng Anh] PHASE-0.104 S104-C7 — Woo · CF7 · CRM sources (auto; option bizcity_context_producers=off)
	// [2026-10-19 09:12 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.123 W1 — Twin Sheet ("Sổ"): definitions + read doors over journal rows (no table, D123-1). After the journal index they read.
	'twin-sheet/class-twin-sheet-schema.php'      => 'kg_hub.twin_sheet_schema',
	'twin-sheet/class-twin-sheet-registry.php'    => 'kg_hub.twin_sheet_registry',
	'twin-sheet/class-twin-sheet-store.php'       => 'kg_hub.twin_sheet_store',
	'twin-sheet/class-twin-sheet-builder.php'     => 'kg_hub.twin_sheet_builder', // [2026-10-19 11:45 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.123 W3 — the person's own sheets: builder (pure) + CPT definitions
	'twin-sheet/class-twin-sheet-defs.php'        => 'kg_hub.twin_sheet_defs',
	'twin-sheet/class-twin-sheet-service.php'     => 'kg_hub.twin_sheet_service', // [2026-10-19 10:30 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.123 W2 — apply_changes (before the API that calls it)
	'twin-sheet/class-twin-sheet-snapshot.php'    => 'kg_hub.twin_sheet_snapshot', // [2026-10-20 01:55 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.123 W3 — Chốt sổ + khóa kỳ
	'twin-sheet/class-twin-sheet-structure.php'   => 'kg_hub.twin_sheet_structure', // [2026-10-21 08:40 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.123 W3 — structure versions of my own sheet
	'twin-sheet/class-twin-sheet-api.php'         => 'kg_hub.twin_sheet_api',
) as $_bizcity_journal_rel => $_bizcity_journal_label ) {
	$_bizcity_journal_path = BIZCITY_KG_HUB_INCLUDES . $_bizcity_journal_rel;
	if ( class_exists( 'BizCity_Safe_Loader' ) ) {
		BizCity_Safe_Loader::require_file( $_bizcity_journal_path, $_bizcity_journal_label );
	} elseif ( is_file( $_bizcity_journal_path ) && is_readable( $_bizcity_journal_path ) ) {
		require_once $_bizcity_journal_path;
	}
}
unset( $_bizcity_journal_rel, $_bizcity_journal_label, $_bizcity_journal_path );
// [2026-10-12 Johnny Chu - Chu Hoàng Anh] PHASE-0.104 S104-T2 — TẠM THỜI, chỉ đọc, chỉ admin: lần chạy live-selfcheck đầu tiên trên production
// cho thấy feature `context_producer` và route `context-producers` vắng mặt dù 4 tệp đã đưa lên, số route bizcity-knowledge/v2 không đổi giữa
// hai lần kiểm — tức require ở trên đã im lặng thất bại (file thiếu/không đọc được/throw) hoặc bootstrap.php chạy không tới đây. Route này nói
// đúng cái nào mà không cần đọc error_log server. Gỡ route này sau khi chẩn đoán xong (ghi lại ở doc 40 S104-T2).
add_action( 'rest_api_init', static function () {
	register_rest_route( 'bizcity-knowledge/v2', '/context-producers-diag', array(
		'methods'             => 'GET',
		'permission_callback' => static function () { return current_user_can( 'manage_options' ); },
		'callback'            => static function () {
			$out = array(
				'php_version'       => PHP_VERSION,
				'kg_hub_includes'   => BIZCITY_KG_HUB_INCLUDES,
				'bootstrap_reached' => true, // phải thấy true — nếu route này 404 thì bootstrap.php chưa chạy tới dòng này
			);
			foreach ( array( 'class-context-producers.php', 'class-context-producer-sources.php' ) as $f ) {
				$p = BIZCITY_KG_HUB_INCLUDES . $f;
				$out['files'][ $f ] = array(
					'exists'   => is_file( $p ),
					'readable' => is_file( $p ) && is_readable( $p ),
					'bytes'    => is_file( $p ) ? filesize( $p ) : null,
					'mtime'    => is_file( $p ) ? gmdate( 'c', (int) filemtime( $p ) ) : null,
				);
			}
			$out['class_BizCity_Context_Producers_loaded']        = class_exists( 'BizCity_Context_Producers', false );
			$out['class_BizCity_Context_Producer_Sources_loaded'] = class_exists( 'BizCity_Context_Producer_Sources', false );
			$out['zalo_brain_has_register_context_producer']      = class_exists( 'BizCity_Zalo_Brain' ) && method_exists( 'BizCity_Zalo_Brain', 'register_context_producer' );
			$out['safe_loader_present']                            = class_exists( 'BizCity_Safe_Loader' );
			return rest_ensure_response( $out );
		},
	) );
}, 5 );
if ( class_exists( 'BizCity_Journal_Doc_Index_Pack_Exporter', false ) ) {
	BizCity_Journal_Doc_Index_Pack_Exporter::register();
}
if ( class_exists( 'BizCity_Journal_REST', false ) ) {
	BizCity_Journal_REST::init();
}
// [2026-10-22 01:50 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.129 W2 — GET bizcity-knowledge/v2/journal/map (TwinChat › Journal › Bản đồ)
if ( class_exists( 'BizCity_Journal_Brain_Map', false ) ) {
	BizCity_Journal_Brain_Map::init();
}
if ( class_exists( 'BizCity_Journal_Purge', false ) ) {
	BizCity_Journal_Purge::init(); // [2026-10-18 07:30 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.122 S122-K1 — MCP journal.purge
}
// [2026-10-19 09:12 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.123 W1 — REST bizcity-knowledge/v2/twinsheets* + MCP twinsheet.* (read only).
if ( class_exists( 'BizCity_Twin_Sheet_API', false ) ) {
	BizCity_Twin_Sheet_API::init();
}
// [2026-10-17 07:44 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.121 S121-J4 — web / notebook uploads write their journal row (R-JN-13).
if ( class_exists( 'BizCity_Journal_Ingest_Listener', false ) ) {
	BizCity_Journal_Ingest_Listener::register();
}
// [2026-10-17 08:40 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.121 B1-1 — embed / graph / error progress onto the journal row (R-JN-20/21).
if ( class_exists( 'BizCity_Journal_Learning_Sync', false ) ) {
	BizCity_Journal_Learning_Sync::register();
}
// [2026-10-17 09:05 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.121 B2-4/B2-5 — what the machine MAKES for the owner gets one row; machine history stays in its own ledgers (R-JN-19).
if ( class_exists( 'BizCity_Journal_Output_Listener', false ) ) {
	BizCity_Journal_Output_Listener::register();
}
if ( class_exists( 'BizCity_Journal_Backfill', false ) ) {
	BizCity_Journal_Backfill::register();
}
if ( class_exists( 'BizCity_Journal_Contact_Profile', false ) ) {
	BizCity_Journal_Contact_Profile::init(); // [2026-10-25] PHASE-0.136 S136-L4
}
if ( class_exists( 'BizCity_Scope_Meter', false ) ) {
	BizCity_Scope_Meter::init(); // [2026-10-25] PHASE-0.136 S136-M2 — REST bizcity-vault/v1/scope-matrix*
}
if ( class_exists( 'BizCity_Twin_Vault', false ) ) {
	BizCity_Twin_Vault::init(); // [2026-10-24 09:04 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.134
}
if ( class_exists( 'BizCity_Journal_Meta_Backfill', false ) ) {
	BizCity_Journal_Meta_Backfill::register(); // [2026-10-24 05:56 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.133 S133-Mc
}
// [2026-10-25 12:30 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.137 S137-B2/B4 — GET journal/pipeline (Bảng tệp) + POST journal/rebuild-read (≤ 20 old files per call)
if ( class_exists( 'BizCity_Journal_Pipeline', false ) ) {
	BizCity_Journal_Pipeline::register();
}
// [2026-10-20 10:53 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.126 S126-J2 (job-link@1, BS-5) — a job-event@1.1 that reached the site opens / folds the JB- header row (after BizCity_Sheet_Bindings at 10).
if ( class_exists( 'BizCity_Journal_Job_Link', false ) ) {
	BizCity_Journal_Job_Link::register();
}
// [2026-10-20 11:52 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.126 S126-K1 (BS-9) — every skill change (propose · save · on/off · undo) is a KN- row of the client's brain.
if ( class_exists( 'BizCity_Journal_Skill_Link', false ) ) {
	BizCity_Journal_Skill_Link::register();
}
if ( class_exists( 'BizCity_Journal_Tool_Axis', false ) ) {
	BizCity_Journal_Tool_Axis::register();
}
// [2026-10-21 11:14 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.126 S126-B3 (BS-2) — each notebook and each person-made sheet is one row of the ledger (NB- / SO-).
if ( class_exists( 'BizCity_Journal_Layer_Rows', false ) ) {
	BizCity_Journal_Layer_Rows::register();
}
// [2026-10-17 04:22 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.121 S121-L1 — private originals of owner-capture documents (before the bridge that uses them).
require_once BIZCITY_KG_HUB_INCLUDES . 'class-kg-private-file-store.php';
BizCity_KG_Private_File_Store::register();
// [2026-07-24 Johnny Chu] PHASE-0.46 W1 — channel -> notebook capture bridge shared by Zalo/Telegram/Messenger/WebChat/Twin surfaces.
require_once BIZCITY_KG_HUB_INCLUDES . 'class-kg-channel-notebook-bridge.php';
// [2026-10-09 Johnny Chu - Chu Hoàng Anh] PHASE-0.96 S96-7.5 — one notebook workspace per Zalo number (Q96-13 = a).
require_once BIZCITY_KG_HUB_INCLUDES . 'class-kg-zalo-number-notebooks.php';
BizCity_KG_Zalo_Number_Notebooks::register();
// [2026-07-25 Johnny Chu] PHASE-0.46 W4.5.3 — dispatch non-text notebook capture ingest via cron single events.
BizCity_KG_Channel_Notebook_Bridge::bind_async_dispatch();
// [2026-09-30 Claude Opus 5.5] PHASE-0.87 CL-1 / CL-D3 — owner_knowledge + notebook_meta packs (the owner's own daily notebooks of one number).
if ( class_exists( 'BizCity_Safe_Loader' ) ) {
	BizCity_Safe_Loader::require_file( BIZCITY_KG_HUB_INCLUDES . 'class-kg-owner-pack-exporter.php', 'kg_hub.owner_pack_exporter' );
}
if ( class_exists( 'BizCity_KG_Owner_Pack_Exporter' ) ) {
	BizCity_KG_Owner_Pack_Exporter::register();
}
// [2026-07-26 Johnny Chu] PHASE-0.46 W6 — channel-agnostic instant upload-link
// capability-URL service (fallback capture path for unsupported/no-URL events).
require_once BIZCITY_KG_HUB_INCLUDES . 'class-kg-channel-upload-link-service.php';
// [2026-07-24 Johnny Chu] PHASE-0.46 W1 PROGRESS — 3-step channel reply notifier (step2/3).
require_once BIZCITY_KG_HUB_INCLUDES . 'class-kg-channel-progress-notifier.php';
BizCity_KG_Channel_Progress_Notifier::bind();
// [2026-07-24 Johnny Chu] PHASE-0.46 W2 — channel-agnostic "@notebook" text listener (non-Zalo channels).
require_once BIZCITY_KG_HUB_INCLUDES . 'class-kg-channel-notebook-generic-listener.php';
BizCity_KG_Channel_Notebook_Generic_Listener::bind();
// Phase 0.5 — KG-Hub Contract registry + facade.
require_once BIZCITY_KG_HUB_INCLUDES . 'class-kg-source-registry.php';
// Phase 0.7 / Wave E1 — Source adapter framework (interface + registry).
// PDF/Office adapters auto-registered by the registry's defaults loader.
require_once BIZCITY_KG_HUB_INCLUDES . 'adapters/interface-source-adapter.php';
require_once BIZCITY_KG_HUB_INCLUDES . 'adapters/class-adapter-registry.php';
// Phase 0.7 / Wave E0 — OCR client (Vision LLM via /llm/router/v1/tools/ocr).
require_once BIZCITY_KG_HUB_INCLUDES . 'clients/class-ocr-client.php';
require_once BIZCITY_KG_HUB_INCLUDES . 'clients/class-youtube-transcriber.php'; // Phase 0.7 / Wave E0.YT
// Phase 0.7 / Wave E0.AV — audio/video transcribe client (multimodal LLM via gateway).
require_once BIZCITY_KG_HUB_INCLUDES . 'clients/class-av-transcribe-client.php';
// Phase 0.7 / Sprint D — temporal-aware passage chunker for AV transcripts.
require_once BIZCITY_KG_HUB_INCLUDES . 'class-kg-av-chunker.php';
require_once BIZCITY_KG_HUB_INCLUDES . 'class-kg-facade.php';
require_once BIZCITY_KG_HUB_INCLUDES . 'class-kg-scoped-rest-controller.php';
// [2026-07-23 Johnny Chu] PHASE-0.43 — bind async scoped file ingest worker outside REST to avoid upload 524.
BizCity_KG_Scoped_REST_Controller::bind_async_ingest();
require_once BIZCITY_KG_HUB_INCLUDES . 'class-kg-auto-promoter.php';
require_once BIZCITY_KG_HUB_INCLUDES . 'kg-helpers.php';
require_once BIZCITY_KG_HUB_INCLUDES . 'class-kg-graph-service.php';
require_once BIZCITY_KG_HUB_INCLUDES . 'class-kg-triplet-extractor.php';
require_once BIZCITY_KG_HUB_INCLUDES . 'class-kg-reranker.php';
require_once BIZCITY_KG_HUB_INCLUDES . 'class-kg-retriever.php';
// [2026-10-24 Johnny Chu - Chu Hoàng Anh] PHASE-0.126 Q126-19 — class-kg-source-adapter-studio.php archived (core/_archived/kg-studio-adapter-20261024/): its writer (TwinChat Studio) is gone.
require_once BIZCITY_KG_HUB_INCLUDES . 'class-kg-rest-controller.php';
// PHASE 0.31 T-S1.6 — public workflow REST surface (token-gated).
require_once BIZCITY_KG_HUB_INCLUDES . 'class-kg-public-api.php';
// PHASE 0.31 T-S2.4 — WaicChannelIntegration_notebook (Twin Second Brain bus).
require_once BIZCITY_KG_HUB_INCLUDES . 'integration-notebook.php';
// Phase 0.21 Wave 3.0 — Guru Builder (promote notebook → guru, clone mode).
require_once BIZCITY_KG_HUB_INCLUDES . 'class-kg-guru-builder.php';
// Phase 0.6.6 / Wave B — orphan cleanup (soft → reaper) + audit log.
require_once BIZCITY_KG_HUB_INCLUDES . 'class-kg-cleanup-service.php';
BizCity_KG_Cleanup_Service::bind();

// PHASE-0.13 Wave 10c — per-source learning evidence trail (diagnose 100%→0% loop).
require_once BIZCITY_KG_HUB_INCLUDES . 'class-kg-source-progress-log.php';
BizCity_KG_Source_Progress_Log::bind();

// PHASE-0-RULE-SKELETON Sprint 0★ — Notebook Skeleton-First foundation
// (Adapter = single point of truth, Service = debounced reflection cron,
//  Prompt = shared LLM contract, REST = bizcity/kg/v1 surface — see
//  PHASE-0-RULE-NAMESPACE).
// MUST load before any tool plugin so `BizCity_KG_Skeleton_Adapter` is available
// from `init` onwards (RULE-1 invariant).
require_once BIZCITY_KG_HUB_SKELETON . 'class-skeleton-prompt.php';
require_once BIZCITY_KG_HUB_SKELETON . 'class-notebook-skeleton-adapter.php';
require_once BIZCITY_KG_HUB_SKELETON . 'class-notebook-skeleton-service.php';
require_once BIZCITY_KG_HUB_SKELETON . 'class-skeleton-rest.php';
BizCity_KG_Skeleton_Service::bind();
BizCity_KG_Skeleton_REST::bind();

// PHASE-0-RULE-SKELETON Sprint 0★ — Skeleton diagnostics class (always loaded:
// needed from CLI, cron context, and admin alike).
require_once BIZCITY_KG_HUB_SKELETON . 'class-kg-skeleton-diagnostic.php';
if ( is_admin() ) {
	// Bind admin_menu hook (Tools → KG Skeleton).
	BizCity_KG_Skeleton_Diagnostic::instance();
}

// PHASE-0-RULE-SKELETON Sprint 0★ S0.7–S0.9 — shared FE web components
// (<bztwin-notebook-selector>, <bztwin-skeleton-preview>, useNotebookSkeleton helper).
// Loaded everywhere so any plugin (admin or front-end) can call
// BizCity_KG_Skeleton_Assets::enqueue() to wire RULE-3 / RULE-4 surfaces.
require_once BIZCITY_KG_HUB_SKELETON . 'class-kg-skeleton-assets.php';

// Phase 0.22 — Skeleton backfill cron: re-queues failed/stuck notebooks hourly (R-CRON-META).
// Runs outside admin context so it fires in WP-Cron / Action Scheduler passes.
require_once BIZCITY_KG_HUB_SKELETON . 'class-kg-skeleton-backfill-cron.php';
BizCity_KG_Skeleton_Backfill_Cron::boot();

if ( is_admin() ) {
	// [2026-09-24 Claude Opus 5] CORE-REDUCTION-WP-09 T3/T4 — `class-kg-admin-menu.php` and
	// `class-kg-settings-page.php` retired; the React mount moved to modules/twinkg (slug
	// `bizcity-twinkg`, page `/twinkg/`) and settings & cost became REST `cost/settings` + a
	// twinkg view.
	// [2026-10-10 11:30 PM Johnny Chu - Chu Hoàng Anh] CORE-REDUCTION WP-20 Z-8b — class-kg-bin-diagnostic.php (198 KB)
	// archived to core/_archived/z8b-20261010/kg-hub/includes/: BizCity_KG_Bin_Diagnostic::instance() was never called
	// by live code (only the dev-only diagnostics probe, guarded), so its page and bin-missing listener never registered.
}
// Phase 0.6 — Multisite cron backfill (runs in cron context, not admin-only).
require_once BIZCITY_KG_HUB_INCLUDES . 'class-kg-backfill.php';
BizCity_KG_Backfill::boot();

// Phase 0.6 Wave A — WP-CLI commands for brain-reflection observability.
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once BIZCITY_KG_HUB_INCLUDES . 'class-kg-cli.php';
}

// PHASE 0.31 T-S3.2 — [bizcity_notebook_notes notebook_id=N limit=20] shortcode
// renders the per-note "Tag note" + "Trigger workflow" panel that backs the
// REST endpoints /passages/{id}/tag and /passages/{id}/trigger-workflow.
add_shortcode( 'bizcity_notebook_notes', static function ( $atts ) {
	$atts = shortcode_atts( array(
		'notebook_id' => 0,
		'limit'       => 20,
	), $atts, 'bizcity_notebook_notes' );

	// [2026-10-12 10:47 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.102 ST102-L32 — was a hard-coded plugin folder name — shortcode [bizcity_notebook_notes] rendered empty when the plugin folder had another name.
	$view = ( defined( 'BIZCITY_TWIN_AI_DIR' ) ? BIZCITY_TWIN_AI_DIR : dirname( __DIR__, 2 ) . '/' ) . 'core/guru/admin/views/notebook-notes-panel.php';
	if ( ! is_readable( $view ) ) { return ''; }
	ob_start();
	include $view; // $atts is in scope
	return ob_get_clean();
} );

// Phase 0.6.5 — Wave C: real-time mirror of legacy *_sources INSERTs into kg_sources.
// Feature-flagged inside the handler (option `bizcity_kg_v06_unified_write`).
add_action( 'bizcity_kg_legacy_source_inserted',   [ 'BizCity_KG', 'on_legacy_source_inserted' ], 10, 1 );
add_action( 'bizcity_kg_legacy_chunks_persisted', [ 'BizCity_KG', 'on_legacy_chunks_persisted' ], 10, 1 );

// ─── Init ──────────────────────────────────────────────────────────────────
add_action( 'init', static function () {
	// [2026-07-30 Johnny Chu] PHASE-1.22-RUNTIME — do not run KG schema migration on unrelated web requests.
	$runtime_context = ( defined( 'REST_REQUEST' ) && REST_REQUEST )
		|| ( defined( 'DOING_CRON' ) && DOING_CRON )
		|| ( defined( 'WP_CLI' ) && WP_CLI );
	if ( $runtime_context ) {
		BizCity_KG_Database::instance();
	}
	// Phase 0.5 Sprint 4.5g — auto-promote chat messages to KG passages.
	BizCity_KG_Auto_Promoter::instance()->boot();
}, 5 );

// [2026-07-30 Johnny Chu] PHASE-1.22-RUNTIME — admin KG migration is deferred to relevant screens only.
add_action( 'current_screen', static function ( $screen ) {
	$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
	$screen_id = $screen && isset( $screen->id ) ? (string) $screen->id : '';
	if ( strpos( $page, 'bizcity-twinchat' ) === false && strpos( $screen_id, 'bizcity-kg' ) === false ) {
		return;
	}
	BizCity_KG_Database::instance();
}, 5 );

add_action( 'rest_api_init', static function () {
	BizCity_KG_Rest_Controller::instance()->register_routes();
	if ( class_exists( 'BizCity_KG_Scoped_REST_Controller' ) ) {
		BizCity_KG_Scoped_REST_Controller::instance()->register_routes();
	}
} );

// [2026-09-24 Claude Opus 5] CORE-REDUCTION-WP-09 T3/T4 — core/knowledge registers no KG-Hub
// admin page any more. All three old slugs are answered by
// BizCity_TwinKG_Admin_Menu::redirect_legacy_slugs():
//   `bizcity-kg-hub`          → modules/twinkg (`bizcity-twinkg` / `/twinkg/`)
//   `bizcity-twinchat-gurus`  → same module, view `gurus`
//   `bizcity-kg-hub-settings` → same module, view `settings` (REST `cost/settings`)

// ─── Phase 0.6 — Feature flags (WP option controlled) ──────────────────────
// [2026-07-23 Johnny Chu] PHASE-0.45-KG-FILE-GRAPH — hard-cut defaults.
// Legacy v06 central dual-write mặc định OFF; filestore read-switch + file-primary mặc định ON.
// Cấu hình qua Network Admin → Settings → BizCity Cron Tiers.
add_filter( 'bizcity_kg_v06_dual_write', static function ( $enabled ) {
	if ( class_exists( 'BizCity_KG_Filestore_Flags' ) ) {
		return $enabled || BizCity_KG_Filestore_Flags::is_file_first_dual_write();
	}
	return $enabled || (bool) get_option( 'bizcity_kg_v06_dual_write_enabled', false );
} );
add_filter( 'bizcity_kg_v06_read_switch', static function ( $enabled ) {
	if ( class_exists( 'BizCity_KG_Filestore_Flags' ) ) {
		return $enabled || BizCity_KG_Filestore_Flags::is_file_first_read_switch();
	}
	return $enabled || (bool) get_option( 'bizcity_kg_v06_read_switch_enabled', true );
} );

// [2026-07-23 Johnny Chu] PHASE-0.45-KG-FILE-GRAPH — file-primary write path defaults ON.
add_filter( 'bizcity_kg_v07_file_primary_write', static function ( $enabled ) {
	if ( class_exists( 'BizCity_KG_Filestore_Flags' ) ) {
		return $enabled || BizCity_KG_Filestore_Flags::is_file_primary_write();
	}
	return $enabled || (bool) get_option( 'bizcity_kg_v07_file_primary_write_enabled', true );
} );

// [2026-07-23 Johnny Chu] PHASE-0.45-KG-FILE-GRAPH — drain graph embedding LONGTEXT by default.
add_filter( 'bizcity_kg_v08_graph_embedding_migration', static function ( $enabled ) {
	if ( class_exists( 'BizCity_KG_Filestore_Flags' ) && method_exists( 'BizCity_KG_Filestore_Flags', 'is_graph_embedding_migration_enabled' ) ) {
		return $enabled || BizCity_KG_Filestore_Flags::is_graph_embedding_migration_enabled();
	}
	return $enabled || (bool) get_option( 'bizcity_kg_v08_graph_embedding_migration_enabled', true );
} );
