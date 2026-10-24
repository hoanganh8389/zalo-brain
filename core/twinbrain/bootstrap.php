<?php
/**
 * Bizcity Twin AI — TwinBrain Core Bootstrap (PHASE 0.36 v3)
 *
 * Não tổng / Central Brain Orchestrator — **BE-only** runtime.
 *
 * As of PHASE-0.36 v3 (2026-05-10) TwinBrain has NO standalone SPA. The entire
 * UX (Ask Brain composer, KG workspace resize, History tab, BrainTimeline)
 * lives inside `modules/twinchat/ui/` and is toggled via `chatMode='brain'`.
 * This module ships only:
 *   1. REST endpoints (`bizcity-twinbrain/v1/*`)
 *   2. MPR runtime classes (Selector / Matcher / Runner / Synthesizer)
 *   3. Schema view (`bizcity_brain_turns`)
 *   4. 3 new event_type registrations on `bizcity_twin_event_stream`
 *   5. A redirect from the legacy admin page to TwinChat (mode=brain)
 *
 * Loaded from `bizcity-twin-ai.php` via `core/twinbrain/bootstrap.php`.
 *
 * Spec: PHASE-0.36-TWINBRAIN-CENTRAL-BRAIN.md
 *
 * Hard rules respected:
 *   - R-EVT-1/2/4 — uses bizcity_twin_event_stream + 1 SSE channel only.
 *     3 new event_types: brain_perspective_selected, brain_perspective_answer,
 *     brain_tool_intent. NO new log/audit/trace tables.
 *   - R-GW         — every LLM call goes through bizcity-llm-router.
 *   - R-VFS        — retrieval via BizCity_KG_Vector_File_Store::search().
 *   - R-TG-*       — does NOT bypass Guru persona resolution.
 *
 * Wave 0 (this commit): bootstrap + runtime stub + REST shell + REST registration.
 * Wave 1+ (TODO):       NotebookSelector, ToolIntentMatcher, PerspectiveRunner,
 *                       Synthesizer, React UI. See PHASE-0.36 §8 sprints.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Modules\TwinBrain
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @since      2026-05-10
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

if ( defined( 'BIZCITY_TWINBRAIN_LOADED' ) ) {
	// [2026-10-07 10:05 Johnny Chu - Chu Hoàng Anh] TWINBRAIN-SPLIT-CORE-ADDON Q-2 — the Intent_Compat_Adapter recovery that
	// lived here was removed with the PHP intent layer.
	return;
}
define( 'BIZCITY_TWINBRAIN_LOADED', true );

if ( ! defined( 'BIZCITY_TWINBRAIN_DIR' ) ) {
	define( 'BIZCITY_TWINBRAIN_DIR', __DIR__ . '/' );
}
if ( ! defined( 'BIZCITY_TWINBRAIN_URL' ) ) {
	define( 'BIZCITY_TWINBRAIN_URL', plugin_dir_url( __FILE__ ) );
}
if ( ! defined( 'BIZCITY_TWINBRAIN_VERSION' ) ) {
	define( 'BIZCITY_TWINBRAIN_VERSION', '0.36.0-w0' );
}
if ( ! defined( 'BIZCITY_TWINBRAIN_REST_NS' ) ) {
	define( 'BIZCITY_TWINBRAIN_REST_NS', 'bizcity-twinbrain/v1' );
}
if ( ! defined( 'BIZCITY_TWINBRAIN_K_DEFAULT' ) ) {
	define( 'BIZCITY_TWINBRAIN_K_DEFAULT', 5 );
}
if ( ! defined( 'BIZCITY_TWINBRAIN_K_MAX' ) ) {
	define( 'BIZCITY_TWINBRAIN_K_MAX', 7 );
}
if ( ! defined( 'BIZCITY_TWINBRAIN_TOOL_INTENT_THRESHOLD' ) ) {
	define( 'BIZCITY_TWINBRAIN_TOOL_INTENT_THRESHOLD', 0.55 );
}
if ( ! defined( 'BIZCITY_TWINBRAIN_TOOL_AUTOSUGGEST_THRESHOLD' ) ) {
	define( 'BIZCITY_TWINBRAIN_TOOL_AUTOSUGGEST_THRESHOLD', 0.7 );
}

// [2026-10-24 Johnny Chu - Chu Hoàng Anh] PHASE-0.126 R126-B2 (owner Q126-34: MPR → bizcity-twin-brain-addon, with /gpt/ and the vertical modes; "Cell là trục não chính, ko dùng php để suy luận") —
// the PHP reasoning pipeline (Runtime, Perspective_Runner, Synthesizer, Final_Composer, Notebook / Source / Subject-profile / Multimodal /
// Temporal layers, Memory recall + writer + tools, Mode memory, Channel adapters, Progress notices, Guru web flag, Sheets enricher,
// scheduler / ingest tools, profile templates) lives in bizcity-twin-brain-addon/twinbrain-mpr/. The core keeps the cell forwarder below.
// Without the add-on no PHP turn can run: the emergency constant BIZCITY_TWIN_WEB_TURN_FORCE_PHP answers 503 instead of an MPR answer.
// Loaded HERE (where the MPR classes always loaded) so the vertical modes further down still find them.
$_bizcity_twinbrain_mpr = class_exists( 'BizCity_Addon_Locator', false ) ? BizCity_Addon_Locator::file( 'twinbrain-mpr/bootstrap.php' ) : '';
if ( '' !== $_bizcity_twinbrain_mpr ) {
	require_once $_bizcity_twinbrain_mpr;
}
unset( $_bizcity_twinbrain_mpr );

// [2026-10-24 Johnny Chu - Chu Hoàng Anh] PHASE-0.126 R126-B2 — INCIDENT 2026-10-24: after a partial upload the site fataled in wp-cron / every page ("Failed opening required …/class-twinbrain-schema.php").
// A core file that is missing on disk is logged and skipped from now on; the code that uses its class is guarded below.
$_bizcity_tb_require = static function ( $relative ) {
	$file = BIZCITY_TWINBRAIN_DIR . $relative;
	if ( is_readable( $file ) ) {
		require_once $file;
		return true;
	}
	error_log( '[twinbrain] missing core file, skipped: core/twinbrain/' . $relative . ' — re-upload it (see 95-DEPLOY-LIST: files that must stay in core/twinbrain/includes)' );
	return false;
};

// ── Cell forwarder (core) ─────────────────────────────────────────────────────
$_bizcity_tb_require( 'includes/class-twinbrain-rest.php' );
$_bizcity_tb_require( 'includes/class-twinbrain-schema.php' );
// [2026-09-16 Johnny Chu - Chu Hoàng Anh] PHASE-0.41D-D4 — one Brain retrieval facade shared by Twin GPT and MCP (Context Bank pack, not MPR).
$_bizcity_tb_require( 'includes/class-brain-retrieval-facade.php' );

// [2026-06-03 Johnny Chu] BRAIN-SESSIONS BS-2 — Conversation thread manager
// + REST surface (/sessions CRUD). Spec:
// core/twinbrain/docs/sessions/TWINBRAIN-FEATURE-BRAIN-SESSIONS.md §11.
// Reorg 2026-06-03: BS group consolidated under includes/sessions/ for
// discoverability (manager + REST + future companion-context controller).
$_bizcity_tb_require( 'includes/sessions/class-twinbrain-sessions-manager.php' );
$_bizcity_tb_require( 'includes/sessions/class-twinbrain-sessions-rest.php' );
// [2026-10-24 Johnny Chu - Chu Hoàng Anh] PHASE-0.126 R126-B2 — channel adapters, dual-owner session resolver and progress notices are MPR plumbing: bizcity-twin-brain-addon/twinbrain-mpr/.
$_bizcity_tb_require( 'includes/class-twinbrain-vertical-bridge-registry.php' );
$_bizcity_tb_require( 'includes/class-twinbrain-guru-focus-validator.php' );
// [2026-09-21 PHASE-0.63A] Goal Loop stale scanning is disabled pending the replacement workflow.
// [2026-10-06 23:24 Johnny Chu - Chu Hoàng Anh] TWINBRAIN-SPLIT-CORE-ADDON W-1 — scheduler class archived; keep the
// one-shot cron cleanup with the literal hook name so sites that never ran it still drop the event.
add_action( 'init', static function () {
	if ( ! function_exists( 'wp_clear_scheduled_hook' ) || get_option( 'bizcity_twinbrain_goal_loop_scheduler_disabled', false ) ) {
		return;
	}
	wp_clear_scheduled_hook( 'bizcity_twinbrain_goal_loop_stale_scan' );
	update_option( 'bizcity_twinbrain_goal_loop_scheduler_disabled', 1, false );
}, 20 );

// [2026-10-07 10:05 Johnny Chu - Chu Hoàng Anh] TWINBRAIN-SPLIT-CORE-ADDON Q-3 (owner approval 2026-10-07: "có DROP bảng
// bizcity_twin_goal_contracts") — one shot per site. The table is a rebuildable projection of bizcity_twin_event_stream whose
// writer is archived, so rows are purged, then the table is dropped, both through BizCity_Legacy_Table_Policy (approval
// ref + zero-row gate). A failed step leaves the flag unset and retries on a later request.
add_action( 'init', static function () {
	if ( get_option( 'bizcity_twinbrain_goal_contracts_dropped', false ) || ! class_exists( 'BizCity_Legacy_Table_Policy', false ) ) {
		return;
	}
	$table = 'bizcity_twin_goal_contracts';
	if ( BizCity_Legacy_Table_Policy::get_state( $table ) !== BizCity_Legacy_Table_Policy::STATE_DROPPED ) {
		BizCity_Legacy_Table_Policy::mark_ready_to_drop( $table, 'owner-2026-10-07-twinbrain-split-q3' );
		if ( ! BizCity_Legacy_Table_Policy::purge_approved_migrated( $table ) || ! BizCity_Legacy_Table_Policy::drop_approved_empty( $table ) ) {
			return;
		}
	}
	delete_option( 'bizcity_twinbrain_goal_contracts_db_version' );
	update_option( 'bizcity_twinbrain_goal_contracts_dropped', 1, true );
}, 21 );

// [2026-10-07 10:05 Johnny Chu - Chu Hoàng Anh] TWINBRAIN-SPLIT-CORE-ADDON W-3 — vertical brain modes moved to bizcity-twin-brain-addon/twinbrain-verticals/:
// web research engines (quick, deep, social, company, med, scholar, nutri, law, tax, gov) + skills seeder, Web_Astro +
// Astro recall/subject/relation/transit services + the Astro mode handler, Products stack, Woo BizOps. Same class names
// (bizcity-automation calls them directly). Core reaches them only through bizcity_twinbrain_* filters; without the
// add-on, vertical modes are not offered (owner Q-0, 2026-10-06) and turns run plain MPR.
$_bizcity_twinbrain_verticals = class_exists( 'BizCity_Addon_Locator', false ) ? BizCity_Addon_Locator::file( 'twinbrain-verticals/bootstrap.php' ) : '';
if ( '' !== $_bizcity_twinbrain_verticals ) {
	require_once $_bizcity_twinbrain_verticals;
}
unset( $_bizcity_twinbrain_verticals );
// [2026-08-14 Johnny Chu] PHASE-TWB-GURU-POLICY — load the shared sensitive-capability decision boundary before vertical engines.
$_bizcity_tb_require( 'includes/class-twinbrain-guru-policy.php' );

// [2026-10-07 10:05 Johnny Chu - Chu Hoàng Anh] TWINBRAIN-SPLIT-CORE-ADDON Q-2 — Workflow-Driven Brain Pipeline (/skill)
// and Builtin_Skills archived with the PHP intent layer. [2026-10-12 Johnny Chu - Chu Hoàng Anh] PHASE-0.101 S102-L6 — `#workflow` commands are removed too (Q102-6).

// [2026-10-24 Johnny Chu - Chu Hoàng Anh] PHASE-0.126 R126-B2 — Guru web-fallback flag → bizcity-twin-brain-addon/twinbrain-mpr/ (it gates the MPR web mode only).

// Phase 0.36-UNIFIED TBR.W10 (2026-05-21) — Citation Resolver baseline
// (R-BRAIN-2). Single source of truth cho citation token → resolved record.
// Cover 6 namespaces: mem|faq|nb|src|ent|web. REST GET /citations/resolve.
$_bizcity_tb_require( 'includes/class-twinbrain-citation-resolver.php' );


// [2026-10-24 Johnny Chu - Chu Hoàng Anh] PHASE-0.126 R126-B2 — Final Composer, Memory recall / writer and Mode memory → bizcity-twin-brain-addon/twinbrain-mpr/.

// Phase 0.36-UNIFIED Wave 2.8c (2026-05-24) TBR.MEM-C1 — Owner-self Memory
// Hub REST endpoints (/memory/me) cho FE BrainMemoryButton + MemoryHubDrawer.
// Permission: is_user_logged_in + force user_id = current.
$_bizcity_tb_require( 'includes/class-twinbrain-rest-memory-me.php' );

// [2026-10-24 Johnny Chu - Chu Hoàng Anh] PHASE-0.126 R126-B2 — the PHP agent tools (memory_* , sheet_enrich + Sheets installer, scheduler_set_reminder, ingest_document) →
// bizcity-twin-brain-addon/twinbrain-mpr/ (they only ever served the PHP agent loop / MPR).

add_action( 'rest_api_init', static function () {
	if ( class_exists( 'BizCity_TwinBrain_REST' ) ) {
		BizCity_TwinBrain_REST::instance()->register_routes();
	}
	// [2026-10-06 23:24 Johnny Chu - Chu Hoàng Anh] TWINBRAIN-SPLIT-CORE-ADDON W-1 — /goals/*, /goal/* routes archived with Goal Loop.
	if ( class_exists( 'BizCity_TwinBrain_REST_Memory_Me' ) ) {
		BizCity_TwinBrain_REST_Memory_Me::instance()->register_routes();
	}
	// [2026-06-03 Johnny Chu] BRAIN-SESSIONS BS-2 — /sessions CRUD routes.
	if ( class_exists( 'BizCity_TwinBrain_Sessions_REST' ) ) {
		BizCity_TwinBrain_Sessions_REST::instance()->register_routes();
	}
} );

// Ensure the bizcity_brain_turns VIEW + perspective columns exist per-blog
// (both idempotent, version-gated).
add_action( 'init', static function () {
	if ( ! class_exists( 'BizCity_TwinBrain_Schema' ) ) {
		return;
	}
	BizCity_TwinBrain_Schema::ensure_view();
	BizCity_TwinBrain_Schema::ensure_notebook_perspective_columns();
	// [2026-06-03 Johnny Chu] BRAIN-SESSIONS BS-1 — sessions VIEW projection.
	BizCity_TwinBrain_Schema::ensure_sessions_view();
}, 20 );

// PHASE 0.36 v3 (2026-05-10) — TwinBrain has NO standalone SPA.
// All UI lives inside TwinChat (mode='brain'). The legacy admin page
// `bizcity-twinbrain` redirects to TwinChat with the brain mode flag so any
// bookmarks / external links keep working.
//
// [2026-09-16 Johnny Chu - Chu Hoàng Anh] PHASE-0-SETTING-PANEL-G6-HOTFIX3 — menu registration MOVED to
// a lightweight owner loaded on every wp-admin request (this bootstrap is gated behind
// `$_bizcity_admin_ctx && !$_bizcity_twinchat_admin_shell_request` in `bizcity-twin-ai.php`, so
// registering the Twin Brain parent here made the entire menu vanish whenever the operator was
// inside the TwinChat admin shell, `?page=bizcity-twinchat`).
// [2026-10-01 Claude Sonnet 5] Owner directive — that menu owner now lives in CORE,
// `bizcity-twin-ai/includes/class-twinbrain-admin-menu.php`, required directly (not through
// `BizCity_Addon_Locator`), so the top-level "Twin CRM" entry renders even on a server where this
// add-on plugin hasn't been deployed yet. Only the REST/schema/runtime wiring below stays here,
// behind the `$_bizcity_admin_ctx` gate.
