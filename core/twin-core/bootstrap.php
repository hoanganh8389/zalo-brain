<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Twin_Core
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 */

/**
 * BizCity Twin Core — Phase 0–2: Context Cleanup + Twin State Backbone
 *
 * mu-plugin entry point.
 * Loads Data Contract, State Schema, Event Bus, Prompt Parser,
 * Focus Router, Focus Gate, Twin Context Resolver, Twin Snapshot Builder.
 *
 * @package  BizCity_Twin_Core
 * @version  2.0.0
 * @since    2026-03-22
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

/* ── Feature Flags ─────────────────────────────────────────────── */
if ( ! defined( 'BIZCITY_TWIN_FOCUS_ENABLED' ) ) {
    define( 'BIZCITY_TWIN_FOCUS_ENABLED', true );     // Sprint 0A — focus gate
}
if ( ! defined( 'BIZCITY_TWIN_RESOLVER_ENABLED' ) ) {
    define( 'BIZCITY_TWIN_RESOLVER_ENABLED', true );   // Sprint 0B — resolver ★ ENABLED
}
if ( ! defined( 'BIZCITY_TWIN_SNAPSHOT_ENABLED' ) ) {
    define( 'BIZCITY_TWIN_SNAPSHOT_ENABLED', false );   // Sprint 0C — snapshot
}
if ( ! defined( 'BIZCITY_TWINCHAT_USE_TWIN_AGENT' ) ) {
    define( 'BIZCITY_TWINCHAT_USE_TWIN_AGENT', true );  // Sprint 4.7e — Twin Agent path (function-calling loop) ★ DEFAULT ON
}
if ( ! defined( 'BIZCITY_TWIN_DEBUG' ) ) {
    define( 'BIZCITY_TWIN_DEBUG', true );               // Sprint 4.10.5 — Twin Debug tracer (BE error_log + FE console). Set FALSE in production.
}

/* ── Constants ─────────────────────────────────────────────────── */
if ( ! defined( 'BIZCITY_TWIN_CORE_DIR' ) ) {
    define( 'BIZCITY_TWIN_CORE_DIR', __DIR__ );
}
if ( ! defined( 'BIZCITY_TWIN_CORE_VERSION' ) ) {
    define( 'BIZCITY_TWIN_CORE_VERSION', '2.0.2' );
}

/* ── Autoload Classes ──────────────────────────────────────────── */
$twin_includes = BIZCITY_TWIN_CORE_DIR . '/includes';

// Twin Trace — always loaded so trace calls are safe even when gates are off
if ( ! class_exists( 'BizCity_Twin_Trace' ) ) {
    require_once $twin_includes . '/class-twin-trace.php';
    BizCity_Twin_Trace::init();
} else {
    // [2026-08-16 Johnny Chu] R-DDV/R-EVT — legacy Trace ownership must not short-circuit TwinCore Event Taxonomy/Event Bus loading.
    $legacy_twin_includes = __DIR__ . '/includes';
    require_once $legacy_twin_includes . '/class-twin-runtime-audit.php';
    require_once $legacy_twin_includes . '/class-twin-secret-provider.php';
    require_once $legacy_twin_includes . '/class-twin-slo-store.php';
    require_once $legacy_twin_includes . '/class-twin-runtime-reliability.php';
    require_once $legacy_twin_includes . '/class-twin-reliable-http.php';
    require_once $legacy_twin_includes . '/class-twin-mutation-guard.php';
}

// [2026-09-30 Claude Opus 5.5] CORE-REDUCTION R15 (core-wide sweep) — BizCity_Source_HTML_Sanitizer was loaded on every request but no code
// calls it; archived in core/_archived/sweep-20260930/.

// Twin Debug — single on/off switch for verbose pipeline tracing (BE+FE).
// Loaded before everything else so any module can call BizCity_Twin_Debug::trace().
require_once $twin_includes . '/class-twin-debug.php';

// Phase 2 Priority 1 — Data Contract (source registry, event taxonomy, ID contract)
require_once $twin_includes . '/class-twin-data-contract.php';

// Phase 2 Priority 3–5 — State Schema (7 state tables DDL + migration)
require_once $twin_includes . '/class-twin-state-schema.php';

// [2026-07-30 Johnny Chu] PHASE-1.22-RETENTION — register bounded trace cleanup through the central cron manager.
add_action( 'init', [ 'BizCity_Twin_State_Schema', 'register_retention_cron' ], 20 );

// [2026-06-19 Johnny Chu] PHASE-TWB-WORKFLOW W1 — Artifact Normalizer.
// [2026-10-07 10:05 Johnny Chu - Chu Hoàng Anh] TWINBRAIN-SPLIT-CORE-ADDON Q-2 — archived to core/_archived/twinbrain-intent-20261007/
// with its only consumer, the TwinBrain Workflow Pipeline (/skill).

// Phase 0.12 Wave A — Twin Event Stream foundation
//   Spec:  PHASE-0.12-TWIN-EVENT-STREAM-UNIFICATION.md
//   Rule:  PHASE-0-RULE-EVENT-STREAM.md (R-EVT-1..7)
//   Folder: core/twin-core/event-stream/  — SINGLE BACKBONE (do NOT scatter event-related files outside this folder).
$twin_event_stream = dirname( __FILE__ ) . '/event-stream';
require_once $twin_event_stream . '/class-bizcity-uuid.php';
require_once $twin_event_stream . '/class-twin-event-taxonomy.php';
// [2026-08-29 Johnny Chu] PHASE-VIBE-SDK — load the taxonomy-gated event registry before typed extension declarations.
if ( class_exists( 'BizCity_Safe_Loader', false ) && is_file( $twin_event_stream . '/class-twin-event-registry.php' ) && is_readable( $twin_event_stream . '/class-twin-event-registry.php' ) ) {
    BizCity_Safe_Loader::require_file( $twin_event_stream . '/class-twin-event-registry.php', 'twin_core.event_registry' );
}
require_once $twin_event_stream . '/class-twin-event-stream-schema.php';
require_once $twin_event_stream . '/class-twin-event-store.php';

// Phase 2 Priority 5 — Event Bus (milestone + context log recording + Phase 0.12 dispatch_v2)
require_once $twin_event_stream . '/class-twin-event-bus.php';
if ( class_exists( 'BizCity_Twin_Event_Bus' ) ) {
    BizCity_Twin_Event_Bus::boot();
}

// [2026-09-01 Johnny Chu] PHASE-CB4.1 — register the gated Event Stream projection after the canonical bus is booted.
$_context_bank_event_adapter = dirname( __DIR__ ) . '/context-bank/includes/class-context-bank-event-stream-adapter.php';
if ( class_exists( 'BizCity_Safe_Loader', false )
    && is_file( $_context_bank_event_adapter )
    && is_readable( $_context_bank_event_adapter ) ) {
    try {
        BizCity_Safe_Loader::require_file( $_context_bank_event_adapter, 'context_bank.event_stream_adapter' );
    } catch ( \Throwable $e ) {
        error_log( '[BizCity Twin Core] Context Bank event adapter unavailable.' );
    }
}
if ( class_exists( 'BizCity_Context_Bank_Event_Stream_Adapter', false ) ) {
    BizCity_Context_Bank_Event_Stream_Adapter::boot();
}
unset( $_context_bank_event_adapter );

// Phase 0.12 Wave B+ PR-B+1 — Trace projector (registered, NO-OP until Wave B+3 flip)
require_once $twin_event_stream . '/class-twin-event-trace-projector.php';
if ( class_exists( 'BizCity_Twin_Event_Trace_Projector' ) ) {
    BizCity_Twin_Event_Trace_Projector::boot();
}

// Phase 0.12 Wave C — Router event ingester (parses _twin_events from
// bizcity-llm-router HTTP responses + ingest_remote into local stream).
require_once $twin_event_stream . '/class-router-event-ingester.php';

// Phase 0.12 Wave F — Read-only REST for the Inspector drawer.
require_once $twin_event_stream . '/class-twin-event-stream-rest.php';
if ( class_exists( 'BizCity_Twin_Event_Stream_REST' ) ) {
    BizCity_Twin_Event_Stream_REST::boot();
}

// [2026-09-30 Claude Opus 5.5] PHASE-0.87 CL-5 — POST bizcity-twin/v1/turn-complete (twin record of a Twin Agent turn).
if ( class_exists( 'BizCity_Safe_Loader' ) ) {
	BizCity_Safe_Loader::require_file( $twin_event_stream . '/class-twin-agent-turn-complete-rest.php', 'twin_core.turn_complete_rest' );
}
if ( class_exists( 'BizCity_Twin_Agent_Turn_Complete_REST' ) ) {
	BizCity_Twin_Agent_Turn_Complete_REST::init();
}
// [2026-10-14 08:50 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.110 S110-L1 — `POST bizcity-twin/v1/cell-output-ready` (a cell file made outside a chat turn).
if ( class_exists( 'BizCity_Safe_Loader' ) ) {
	BizCity_Safe_Loader::require_file( $twin_event_stream . '/class-twin-cell-output-ready-rest.php', 'twin_core.cell_output_ready_rest' );
}
if ( class_exists( 'BizCity_Twin_Cell_Output_Ready_REST' ) ) {
	BizCity_Twin_Cell_Output_Ready_REST::init();
}
// [2026-10-17 02:25 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.117 S117-J3 — `POST bizcity-twin/v1/job-event` (progress of an assistant Job, relayed from the cell).
if ( class_exists( 'BizCity_Safe_Loader' ) ) {
	BizCity_Safe_Loader::require_file( $twin_event_stream . '/class-twin-job-event-rest.php', 'twin_core.job_event_rest' );
}
if ( class_exists( 'BizCity_Twin_Job_Event_REST' ) ) {
	BizCity_Twin_Job_Event_REST::init();
}

// Phase 0.12 Wave F — Admin page (Twin Event Inspector).
if ( is_admin() ) {
	require_once $twin_event_stream . '/class-twin-event-inspector-page.php';
	if ( class_exists( 'BizCity_Twin_Event_Inspector_Page' ) ) {
		BizCity_Twin_Event_Inspector_Page::boot();
	}
}

// Phase 2 Priority 4 — Prompt Parser (write-only prompt specs)
// [2026-10-10 11:43 PM Johnny Chu - Chu Hoàng Anh] CORE-REDUCTION WP-20 Z-8b — no live caller (census OWNER-DECISION O-2),
// kept loaded because the Z-7 tables harness (T5 prompt_specs writer-stop) requires this path; archive after that rebase.
require_once $twin_includes . '/class-twin-prompt-parser.php';

// Memory Table Migration — rename scattered tables to unified bizcity_memory_* prefix
require_once $twin_includes . '/class-memory-table-migration.php';

// [2026-10-24 Johnny Chu - Chu Hoàng Anh] PHASE-0.126 R126-B1 (owner: "Cell là trục não chính, ko dùng php để suy luận") — the PHP chat pipeline left the core for
// bizcity-twin-brain-addon/twinbrain-mpr/agent/: Focus_Router / Focus_Gate, Snapshot_Builder (+ its invalidation hooks), Context_Resolver,
// Context_Layers_Capture + Context_Builder (+ their bizcity_chat_system_prompt hooks — fired only by that resolver). Without the brain add-on
// none of it exists and no PHP turn can run; the cell answers.

/* ── Sprint 4.7 — TWIN AGENT CORE (RULE CAO NHẤT) ─────────────── */
// See PHASE-0-RULE-AGENTIC-CORE.md. Mọi main LLM call PHẢI qua BizCity_Twin_Agent::run().
require_once $twin_includes . '/interface-twin-tool.php';
// [2026-07-30 Johnny Chu] PHASE-1.22-SEC — load shared tool security boundary.
require_once $twin_includes . '/class-twin-runtime-audit.php';
// [2026-07-30 Johnny Chu] PHASE-1.22-SEC — load the central secret resolution boundary before channel integrations.
require_once $twin_includes . '/class-twin-secret-provider.php';
// [2026-07-30 Johnny Chu] PHASE-1.22-RUNTIME — load the persistent metadata-only SLO evidence sink.
require_once $twin_includes . '/class-twin-slo-store.php';
require_once $twin_includes . '/class-twin-capability-guard.php';
// [2026-07-30 Johnny Chu] PHASE-1.22-SEC — load persistent extension consent before authorization.
require_once $twin_includes . '/class-twin-capability-consent.php';
if ( class_exists( 'BizCity_Twin_Capability_Consent' ) ) {
    BizCity_Twin_Capability_Consent::boot();
}
// [2026-07-30 Johnny Chu] PHASE-1.22-SEC — load shared SSRF and upload policy enforcement.
require_once $twin_includes . '/class-twin-security-policy.php';
require_once $twin_includes . '/class-twin-mutation-guard.php';
// [2026-09-13 01:00 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.41-W8.6 — load metadata-only before/after evidence before governed mutation consumers.
require_once $twin_includes . '/class-twin-action-evidence.php';
// [2026-08-10 Johnny Chu] PHASE-1.24-RUNTIME — load bounded mutation replay store before framework mutation consumers.
require_once $twin_includes . '/class-twin-mutation-store.php';
// [2026-09-13 10:10 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.41-W8.4 — load the generic user-scoped one-time confirmation boundary before governed C mutations.
require_once $twin_includes . '/class-twin-action-confirmation.php';
// [2026-07-30 Johnny Chu] PHASE-1.22-RUNTIME — load the shared reliability policy before guarded execution.
require_once $twin_includes . '/class-twin-runtime-reliability.php';
// [2026-07-30 Johnny Chu] PHASE-1.22-RUNTIME — expose the shared outbound HTTP reliability adapter.
require_once $twin_includes . '/class-twin-reliable-http.php';
require_once $twin_includes . '/class-twin-content-registry.php';
// [2026-08-29 Johnny Chu] PHASE-VIBE-SDK — consume typed skill/source declarations through the single content registry.
if ( class_exists( 'BizCity_Twin_Content_Registry' ) ) {
    BizCity_Twin_Content_Registry::boot();
}
// [2026-10-24 Johnny Chu - Chu Hoàng Anh] PHASE-0.126 R126-B1 — Tool_Registry (+ Typed_Tool_Adapter), Citation_Id_Generator / Validator, the agent loop (BizCity_Twin_Agent) and the four
// PHP agent tools (search_kg, list_sources, fetch_url, query_entity) → bizcity-twin-brain-addon/twinbrain-mpr/agent/. The SSE writer stays
// (core/twinbrain's forwarder and TwinChat stream through it).
require_once $twin_includes . '/class-twin-sse-writer.php';

/* ── BizChat Menu — Unified Admin Menu Registry ───────────────── */
// [2026-09-23 R-SAFE-LOADER] guard the call — this exact unguarded require_once +
// immediate ::boot() is what produced "Class 'BizChat_Menu' not found" fatals in
// bps_php_error.log when a deploy briefly left this file missing/truncated.
require_once $twin_includes . '/class-bizchat-menu.php';
if ( class_exists( 'BizChat_Menu' ) ) {
    BizChat_Menu::boot();
}

// DB table + cron + AJAX
// Memory migration is heavy (SHOW TABLES + RENAME) — run only on admin requests,
// never on frontend AJAX/SSE which would block the chat stream for seconds.
if ( ( is_admin() || ( defined( 'WP_CLI' ) && WP_CLI ) ) && class_exists( 'BizCity_Memory_Table_Migration' ) ) {
    BizCity_Memory_Table_Migration::maybe_migrate();
}
// [2026-07-31 Johnny Chu] R-PERF/R-MSDB — defer tenant DDL to an explicit repair-capable context.
$bizcity_twin_schema_context = is_admin()
    || ( defined( 'REST_REQUEST' ) && REST_REQUEST )
    || ( ! empty( $_SERVER['REQUEST_URI'] ) && false !== strpos( (string) $_SERVER['REQUEST_URI'], '/wp-json/' ) )
    || ( defined( 'DOING_CRON' ) && DOING_CRON )
    || ( defined( 'WP_CLI' ) && WP_CLI );
if ( $bizcity_twin_schema_context ) {
    if ( class_exists( 'BizCity_Twin_State_Schema' ) ) { BizCity_Twin_State_Schema::ensure_tables(); }  // Phase 2 — active state tables
    if ( class_exists( 'BizCity_Twin_Event_Stream_Schema' ) ) { BizCity_Twin_Event_Stream_Schema::ensure_table(); }  // Phase 0.12 Wave A — canonical event stream
}

// NOTE 2026-05-06: Maturity Dashboard + Calculator subsystem removed entirely
// (admin menu, /maturity/ frontend route, daily/hourly cron, 11 AJAX endpoints,
// snapshot table). Cron events bizcity_maturity_daily_snapshot &
// bizcity_maturity_aggregate_refresh become no-op (no callback registered).
// Stale wp_options 'cron' entries will self-clean on next reschedule cycle.
