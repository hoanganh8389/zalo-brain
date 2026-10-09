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

// [2026-07-19 Johnny Chu] PHASE-TBR-NB-MULTIMODAL — default attachment/vision/file intake layer before Notebook retrieval.
require_once BIZCITY_TWINBRAIN_DIR . 'includes/class-twinbrain-multimodal-intake-layer.php';
// [2026-10-07 10:05 Johnny Chu - Chu Hoàng Anh] TWINBRAIN-SPLIT-CORE-ADDON Q-2 (owner: "lớp intent PHP bỏ") — the PHP intent /
// orchestrator layer is archived to core/_archived/twinbrain-intent-20261007/: Pre_MPR_Triage, Conversation_Router,
// Conversation_Confirmation, Intent_Compat_Adapter, Workflow_Pipeline (/skill) and Builtin_Skills. The cell agent owns intent.
// [2026-08-16 Johnny Chu] MPR-V5-TEMPORAL — load the deterministic temporal context resolver before Runtime hooks.
require_once BIZCITY_TWINBRAIN_DIR . 'includes/class-twinbrain-temporal-context-resolver.php';
require_once BIZCITY_TWINBRAIN_DIR . 'includes/class-twinbrain-runtime.php';
require_once BIZCITY_TWINBRAIN_DIR . 'includes/class-twinbrain-notebook-selector.php';
// [2026-10-06 23:24 Johnny Chu - Chu Hoàng Anh] TWINBRAIN-SPLIT-CORE-ADDON W-1 — Goal Loop (State, Repository, Contract
// Store, Delta, Parser, Alignment, Reflector, Question Engine, REST, Scheduler, Runtime) and Agent_Runner archived to
// core/_archived/twinbrain-goal-loop-20261006/: the cell owns goal/orchestration for reply turns. Every Runtime call was
// already behind class_exists(); result keys goal_loop_state / goal_contract stay, empty.
// [2026-09-25 Claude Opus 5.5] CORE-REDUCTION WP-11 C3b — Goal_Loop_Intent_Adapter retired with the Intent_Conversation read (R-ORPHAN-FILE stage 1).
// [2026-10-06 23:24 Johnny Chu - Chu Hoàng Anh] TWINBRAIN-SPLIT-CORE-ADDON Q-1 — HIL (spec, compiler, state, extractor,
// runtime, repository, media candidate resolver, /hil/compile REST) moved to bizcity-twin-brain-addon/hil/. Only the
// bizcity-automation plugin uses it; without the add-on its HIL workflows answer 503 module_not_loaded.
// [2026-10-08 04:50 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.94-HIL-OFF — the add-on HIL module (hil/, /hil/compile) is no longer loaded: Automation has no HIL; the cell gathers every input.
// [2026-10-08 Johnny Chu - Chu Hoàng Anh] CELL-FIRST E-1 R-12 — and it is archived: bizcity-twin-brain-addon/_archived/hil-20261008/.
require_once BIZCITY_TWINBRAIN_DIR . 'includes/class-twinbrain-tool-intent-matcher.php';
require_once BIZCITY_TWINBRAIN_DIR . 'includes/class-twinbrain-perspective-runner.php';
// [2026-07-18 Johnny Chu] PHASE-TBR-NB-MOAT — load Source File Deep Layer before Notebook Source Layer builds file briefs.
require_once BIZCITY_TWINBRAIN_DIR . 'includes/class-twinbrain-source-file-deep-layer.php';
// [2026-07-18 Johnny Chu] PHASE-TBR-NB-MOAT — load Notebook Source Layer before runtime turn compose uses source maps.
require_once BIZCITY_TWINBRAIN_DIR . 'includes/class-twinbrain-notebook-source-layer.php';
// [2026-09-16 Johnny Chu - Chu Hoàng Anh] PHASE-1.33C-C2 — read-only trace calculator; no
// runtime dependency on it, loaded eagerly like every other side-effect-free TwinBrain class
// so `wp bizcity brain trace` and any future diagnostics probe can reach it without a lazy gate.
require_once BIZCITY_TWINBRAIN_DIR . 'includes/class-twinbrain-trace-calculator.php';
// [2026-07-19 Johnny Chu] PHASE-TWIN-GPT-PROFILE-GROUNDING — subject-first customer profile layer for Notebook/vertical personalization.
require_once BIZCITY_TWINBRAIN_DIR . 'includes/class-twinbrain-subject-profile-layer.php';
require_once BIZCITY_TWINBRAIN_DIR . 'includes/class-twinbrain-synthesizer.php';
require_once BIZCITY_TWINBRAIN_DIR . 'includes/class-twinbrain-rest.php';
require_once BIZCITY_TWINBRAIN_DIR . 'includes/class-twinbrain-schema.php';
// [2026-09-16 Johnny Chu - Chu Hoàng Anh] PHASE-0.41D-D4 — one Brain retrieval facade shared by Twin GPT and MCP.
require_once BIZCITY_TWINBRAIN_DIR . 'includes/class-brain-retrieval-facade.php';

// [2026-06-03 Johnny Chu] BRAIN-SESSIONS BS-2 — Conversation thread manager
// + REST surface (/sessions CRUD). Spec:
// core/twinbrain/docs/sessions/TWINBRAIN-FEATURE-BRAIN-SESSIONS.md §11.
// Reorg 2026-06-03: BS group consolidated under includes/sessions/ for
// discoverability (manager + REST + future companion-context controller).
require_once BIZCITY_TWINBRAIN_DIR . 'includes/sessions/class-twinbrain-sessions-manager.php';
require_once BIZCITY_TWINBRAIN_DIR . 'includes/sessions/class-twinbrain-sessions-rest.php';
// [2026-08-01 Johnny Chu] PHASE-TWIN-GOAL-LOOP-G3 — expose the shared channel boundary and dual-owner session resolver.
require_once BIZCITY_TWINBRAIN_DIR . 'includes/class-twinbrain-brain-session-resolver.php';
require_once BIZCITY_TWINBRAIN_DIR . 'includes/class-twinbrain-channel-adapter.php';
// [2026-08-02 Johnny Chu] PHASE-TWIN-GOAL-LOOP-G10 — load concrete channel identity policy adapters.
require_once BIZCITY_TWINBRAIN_DIR . 'includes/class-twinbrain-channel-adapters.php';
require_once BIZCITY_TWINBRAIN_DIR . 'includes/class-twinbrain-progress-notice-policy.php';
require_once BIZCITY_TWINBRAIN_DIR . 'includes/class-twinbrain-progress-notice-projector.php';
BizCity_TwinBrain_Progress_Notice_Projector::init();
require_once BIZCITY_TWINBRAIN_DIR . 'includes/class-twinbrain-vertical-bridge-registry.php';
require_once BIZCITY_TWINBRAIN_DIR . 'includes/class-twinbrain-guru-focus-validator.php';
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
require_once BIZCITY_TWINBRAIN_DIR . 'includes/class-twinbrain-guru-policy.php';

// [2026-10-07 10:05 Johnny Chu - Chu Hoàng Anh] TWINBRAIN-SPLIT-CORE-ADDON Q-2 — Workflow-Driven Brain Pipeline (/skill)
// and Builtin_Skills archived with the PHP intent layer; `#workflow` commands (Automation_Command_Resolver) are unaffected.

// Phase 0.36-UNIFIED TBR.W11 (2026-05-21) — Guru `allow_web_fallback` flag:
// schema migration + filter `bizcity_twinbrain_web_mode_effective` gate +
// REST GET/POST `/guru/{id}/web-fallback` (manage_options only).
require_once BIZCITY_TWINBRAIN_DIR . 'includes/class-twinbrain-guru-web-flag.php';

// Phase 0.36-UNIFIED TBR.W10 (2026-05-21) — Citation Resolver baseline
// (R-BRAIN-2). Single source of truth cho citation token → resolved record.
// Cover 6 namespaces: mem|faq|nb|src|ent|web. REST GET /citations/resolve.
require_once BIZCITY_TWINBRAIN_DIR . 'includes/class-twinbrain-citation-resolver.php';


// Phase 0.36-UNIFIED TBR.W16 (2026-05-21) — Final Composer (Layer 4.5).
// Streams câu trả lời cuối cùng cho user qua SSE (`final_token` events) sau
// khi Synthesizer (Layer 4) trả về structured output. Dùng
// BizCity_LLM_Client::chat_stream() → gateway /llm/router/v1/chat/stream.
// Degrade gracefully về synthesizer.answer_md khi gateway down.
require_once BIZCITY_TWINBRAIN_DIR . 'includes/class-twinbrain-final-composer.php';

// [2026-10-06 23:24 Johnny Chu - Chu Hoàng Anh] TWINBRAIN-SPLIT-CORE-ADDON W-1 — Agent ReAct Runner (`mode=agent`) archived
// to core/_archived/twinbrain-goal-loop-20261006/; the cell agent replaces it. The Runtime branch already degrades when
// the class is missing.

// Phase 0.36-UNIFIED Wave 2.8 (2026-05-22) — Memory Layer.
// TBR.MEM-2: Memory_Recall (Layer 0.5) — pulls 4 tiers of user memory and
// renders a Memory_Block injected into Final_Composer system prompt.
// TBR.MEM-4: Memory_Writer (Layer 4.7) — Mode 1 regex extracts explicit
// "hãy nhớ ..." phrases after final_done and persists to memory_users.
require_once BIZCITY_TWINBRAIN_DIR . 'includes/class-twinbrain-memory-recall.php';
require_once BIZCITY_TWINBRAIN_DIR . 'includes/class-twinbrain-memory-writer.php';

// [2026-06-04 Johnny Chu] PHASE-A C.3c — Mode Context Memory (Layer 4.8).
// Reusable standard: bất kỳ mode nào (astro/web/law…) persist 1 context
// summary của lượt hỏi vào memory tier gắn session_id + provenance source_url.
// Spec: core/docs/CORE-PHASE-A-MODE-MEMORY.md. Reuse bizcity_memory_users
// (tier=extracted) → KHÔNG đụng schema → R-DCL không phát sinh.
require_once BIZCITY_TWINBRAIN_DIR . 'includes/class-twinbrain-mode-memory.php';

// Phase 0.36-UNIFIED Wave 2.8c (2026-05-24) TBR.MEM-C1 — Owner-self Memory
// Hub REST endpoints (/memory/me) cho FE BrainMemoryButton + MemoryHubDrawer.
// Permission: is_user_logged_in + force user_id = current.
require_once BIZCITY_TWINBRAIN_DIR . 'includes/class-twinbrain-rest-memory-me.php';

// Phase 0.36-UNIFIED Wave 2.8 (2026-05-24) TBR.MEM-6 — Memory Tool Dispatcher
// (Mode 3 MemGPT-style function-call). 3 tool: memory_remember / memory_forget
// / memory_recall đăng ký qua filter `bizcity_twin_register_tool`. Final
// Composer inject prompt section khi flag `bizcity_twinbrain_memory_tools_enabled`
// ON. Runtime gọi dispatcher sau final_done → parse text → execute → emit
// memory_tool_call / memory_tool_result / memory_tool_error events.
// Tools (reorganized 2026-05-24): `core/twinbrain/tools/<domain>/<file>.php`.
// Domains hiện có: memory, sheet. Plan thêm: producer, distributor, canvas.
// Memory dispatcher (runtime infra) vẫn nằm ở `includes/`.
require_once BIZCITY_TWINBRAIN_DIR . 'tools/memory/class-twinbrain-memory-tool-remember.php';
require_once BIZCITY_TWINBRAIN_DIR . 'tools/memory/class-twinbrain-memory-tool-forget.php';
require_once BIZCITY_TWINBRAIN_DIR . 'tools/memory/class-twinbrain-memory-tool-recall.php';
require_once BIZCITY_TWINBRAIN_DIR . 'includes/class-twinbrain-memory-tool-dispatcher.php';

add_filter( 'bizcity_twin_register_tool', static function ( $registry ) {
	if ( ! is_array( $registry ) ) $registry = [];
	$registry['memory_remember'] = new BizCity_TwinBrain_Memory_Tool_Remember();
	$registry['memory_forget']   = new BizCity_TwinBrain_Memory_Tool_Forget();
	$registry['memory_recall']   = new BizCity_TwinBrain_Memory_Tool_Recall();
	return $registry;
}, 10 );

// Phase 0.36-UNIFIED Wave 2.8e (2026-05-24) TBR.TOOL-S1..S3 — TwinBrain
// Sheets producer tool. Installer dbDelta 2 bảng (`bizcity_sheets`,
// `bizcity_sheet_cells`) gated bởi option `bizcity_twinbrain_sheets_db_ver`.
// Enricher port LangGraph 3-stage Tavily Sheets pipeline (search → extract
// → store) qua gateway `BizCity_Research_Tool_Router` (R-GW-1). Tool
// `sheet_enrich` đăng ký vào registry để LLM emit text-tool block hoặc FE
// gọi qua REST. Citation token format `[sheet:S#<id>/r<row>c<col>]`.
// [2026-06-04 Johnny Chu] PHASE-A A.0 — canonical paths after sheets→tools/sheet move.
require_once BIZCITY_TWINBRAIN_DIR . 'tools/sheet/class-twinbrain-sheet-installer.php';
// [2026-09-30 Claude Opus 5.5] CORE-REDUCTION WP-16 B-3c (R-LEAN-4, Q-W16-3) — the Tavily gateway wrapper moved here with modules/twinsearch archived.
require_once BIZCITY_TWINBRAIN_DIR . 'tools/sheet/class-research-tool-router.php';
require_once BIZCITY_TWINBRAIN_DIR . 'tools/sheet/class-twinbrain-sheet-enricher.php';
require_once BIZCITY_TWINBRAIN_DIR . 'tools/sheet/class-twinbrain-sheet-tool-enrich.php';
BizCity_TwinBrain_Sheets_Installer::instance();

add_filter( 'bizcity_twin_register_tool', static function ( $registry ) {
	if ( ! is_array( $registry ) ) $registry = [];
	$registry['sheet_enrich'] = new BizCity_TwinBrain_Sheet_Tool_Enrich();
	return $registry;
}, 11 );

// [2026-06-03 Johnny Chu] SCH-NC W6 — Scheduler HIL tool. LLM master xuất
// `<tool name="scheduler_set_reminder">{...}</tool>` → tạo event status='draft'
// → gửi confirm envelope qua Gateway Sender → user reply OK/Hủy/Sửa được match
// bởi `BizCity_Scheduler_HIL_Router`. Reminder thật fire qua cron sau khi
// status flip 'active'.
require_once BIZCITY_TWINBRAIN_DIR . 'tools/scheduler/class-twinbrain-scheduler-tool-set-reminder.php';

add_filter( 'bizcity_twin_register_tool', static function ( $registry ) {
	if ( ! is_array( $registry ) ) $registry = [];
	$registry['scheduler_set_reminder'] = new BizCity_TwinBrain_Scheduler_Tool_Set_Reminder();
	return $registry;
}, 12 );

// [2026-06-13 Johnny Chu] PHASE-0.40 G3 P6 — ingest_document producer tool.
// Allows admin/staff to ingest text or URL into the guru's attached notebook via chat.
// tool_class = 'P' (Producer) — requires allow_producer in admin-chat grant.
// [2026-06-21 Johnny Chu] HOTFIX — guard file_exists to prevent fatal cascade when file
// is missing on server (caused twinweb routes to 404 via PHP crash on REST requests).
if ( file_exists( BIZCITY_TWINBRAIN_DIR . 'tools/knowledge/class-twinbrain-tool-ingest-document.php' ) ) {
	require_once BIZCITY_TWINBRAIN_DIR . 'tools/knowledge/class-twinbrain-tool-ingest-document.php';

	add_filter( 'bizcity_twin_register_tool', static function ( $registry ) {
		if ( ! is_array( $registry ) ) $registry = [];
		$registry['ingest_document'] = new BizCity_TwinBrain_Tool_Ingest_Document();
		return $registry;
	}, 13 );
}

add_action( 'rest_api_init', static function () {
	BizCity_TwinBrain_REST::instance()->register_routes();
	// [2026-10-06 23:24 Johnny Chu - Chu Hoàng Anh] TWINBRAIN-SPLIT-CORE-ADDON W-1 — /goals/*, /goal/* routes archived with Goal Loop.
	BizCity_TwinBrain_REST_Memory_Me::instance()->register_routes();
	// [2026-06-03 Johnny Chu] BRAIN-SESSIONS BS-2 — /sessions CRUD routes.
	BizCity_TwinBrain_Sessions_REST::instance()->register_routes();
} );

// Ensure the bizcity_brain_turns VIEW + perspective columns exist per-blog
// (both idempotent, version-gated).
add_action( 'init', static function () {
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
