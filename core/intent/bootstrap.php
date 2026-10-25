<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Intent
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 */

/**
 * BizCity Intent — 2-Tier Mode Router + Conversation State Machine
 *
 * Unified conversation management layer that sits between all chat channels
 * (Webchat, Zalo, Telegram, FB) and the AI brain (class-chat-gateway.php).
 *
 * Core responsibilities:
 *   0. Meta Mode Classifier — classify each message into 4 modes
 *   1. Mode Pipelines        — emotion, reflection, knowledge, execution
 *   2. Conversation Manager  — track goal + slots + status per conversation_id
 *   3. Intent Router          — classify execution messages (new_goal / continue / end)
 *   4. Flow Planner           — step-by-step execution (ask, call_tool, compose, complete)
 *   5. Tool Registry          — declarative tool schemas with missing-field detection
 *   6. Stream Adapter         — SSE for webchat, batch for hooks
 *
 * 2-Tier Architecture:
 *   Tier 1: Meta Mode Classifier → emotion | reflection | knowledge | execution
 *   Tier 2: Intent Extractor     → only runs when mode = execution
 *
 * @package BizCity_Intent
 * @version 3.0.0
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

// Constants — guarded to allow coexistence with legacy mu-plugin during migration
if ( ! defined( 'BIZCITY_INTENT_VERSION' ) ) {
    define( 'BIZCITY_INTENT_VERSION', '4.0.0' );
}
if ( ! defined( 'BIZCITY_INTENT_DIR' ) ) {
    define( 'BIZCITY_INTENT_DIR', __DIR__ );
}
if ( ! defined( 'BIZCITY_INTENT_URL' ) ) {
    define( 'BIZCITY_INTENT_URL', plugin_dir_url( __FILE__ ) );
}

/* ── Load sub-classes (skip if already loaded by legacy mu-plugin) ── */
// [2026-10-24 Johnny Chu - Chu Hoàng Anh] PHASE-0.126 R126-I1 — sentinel is the tool registry now (Intent_Database left for the brain add-on).
if ( class_exists( 'BizCity_Intent_Tools' ) ) {
    // Legacy mu-plugin loaded core classes.
    // [2026-09-25 Claude Opus 5.5] WP-11 C4b — Trace_Store / Execution_Logger are owned and loaded by core/runtime now.
    return;
}

/* ── Data layer + Infrastructure (always loaded) ── */

/* -- infrastructure/ -- */
// [2026-10-24 Johnny Chu - Chu Hoàng Anh] PHASE-0.126 R126-I1 — BizCity_Intent_Database, BizCity_Intent_Conversation and BizCity_Intent_Router
// (legacy PHP conversation state + 2-tier router; tables quarantined / retired, no writer) → bizcity-twin-brain-addon/twinbrain-mpr/intent/,
// with their table / cron hooks and bizcity_intent_get_conversation(). Core keeps the tool registry (automation runs tools through it).
// [2026-10-10 12:36 AM Johnny Chu - Chu Hoàng Anh] CORE-REDUCTION WP-20 Z-3 — BizCity_Intent_Logger → core/_archived/z3-20261010/intent/:
// nothing in any installed plugin calls it (its writers went with WP-11) and its retention job was already a no-op.
// [2026-09-25 Claude Opus 5.5] WP-11 C5 — Prompt_Context_Logger retired (written only by the old classifier).
// [2026-09-25 Claude Opus 5.5] WP-11 C4b — Execution_Logger, Trace_Store, Job_Trace → core/runtime.

/* -- conversation/ -- (Intent_Conversation → brain add-on, R126-I1) */
// [2026-09-25 Claude Opus 5.5] WP-11 C4b — Rolling_Memory, Episodic_Memory → core/memory; Context_Builder → core/twin-core.

/* -- providers/ -- */
require_once BIZCITY_INTENT_DIR . '/includes/providers/class-intent-provider.php';
require_once BIZCITY_INTENT_DIR . '/includes/providers/class-intent-simple-provider.php';
require_once BIZCITY_INTENT_DIR . '/includes/providers/class-intent-provider-registry.php';

/* -- routing/ -- (Intent_Router → brain add-on, R126-I1) */
// [2026-09-24 Claude Opus 5] CORE-REDUCTION-WP-02 I-04 — class-knowledge-router.php retired
// under R-ORPHAN-FILE. It declared BizCity_Knowledge_Provider_Registry and
// BizCity_Knowledge_Router_Pipeline, neither referenced anywhere, and was parsed on every
// intent request. Knowledge routing is owned by the KG-Hub retriever and TwinBrain.

/* -- classification/ -- */
// [2026-09-25 Claude Opus 5.5] WP-11 C5 — Mode_Classifier is a thin alias over Intent_Router (sunset); Classify_Cache retired.
// [2026-10-10 10:33 PM Johnny Chu - Chu Hoàng Anh] CORE-REDUCTION WP-20 Z-3b — BizCity_Mode_Classifier → core/_archived/z3b-20261010/intent/:
// its SUNSET condition is met — the last caller (bizcity-gemini-knowledge pipeline) is archived; no installed plugin names it.

/* -- tools/ -- */
require_once BIZCITY_INTENT_DIR . '/includes/tools/class-intent-tools.php';
// [2026-10-25 Johnny Chu - Chu Hoàng Anh] PHASE-0.126 R126-TC — BizCity_Intent_Tool_Index (53 KB: a DB mirror bizcity_tool_registry of the in-memory tool registry, written on plugin
// (de)activation and by the CLI, read by nothing live — Provider_Registry's sync calls were already commented out, Intent_Tools runs tools
// from memory) archived at core/_archived/twin-core-r126tc-20261025/; the table is quarantined (rows stay).
// [2026-09-26 Claude Opus 5.5] CORE-REDUCTION WP-12 R5 — BizCity_Tool_Run retired: its callers (intent engine,
// it_call_tool) went with WP-11, and its skill / resource branches pointed at archived core/skills + core/tools.
// File renamed *_deleted.php (R-ORPHAN-FILE).

// [2026-09-25 Claude Opus 5.5] WP-11 C5 — Intent_Planner retired (only the old Router used it).

/* -- Phase 1 — Unified Pipeline (Evidence, IO Mapper, Core Planner, Scenario) -- */
// [2026-10-25 Johnny Chu - Chu Hoàng Anh] PHASE-0.126 R126-TC — BizCity_Tool_Evidence (CPT bizcity-auto-tool: one post per successful tool run, read by nothing) archived at core/_archived/twin-core-r126tc-20261025/.

/* -- Phase 1 Addendum — Objective Understanding, Execution Planner, Variant, One-Shot, Step Executor -- */

/* -- Phase 1.1 — Pipeline Middleware (HIL, Evidence, ToDos, Schema Adapter, Messenger) -- */

// [2026-09-25 Claude Opus 5.5] WP-11 C4b — Context_Layers_Capture → core/twin-core (with the prompt-capture hooks).

/* ── Init CPT registrations ── */

// [2026-09-25 Claude Opus 5.5] CORE-REDUCTION WP-11 C3a — Step Executor, Pipeline SSE and the waic middleware retired (renamed *_deleted.php).

// [2026-09-25 Claude Opus 5.5] CORE-REDUCTION WP-11 C3a — Memory Spec (pipeline working brief) retired with the skill pipeline (D-25).

// [2026-09-25 Claude Opus 5.5] WP-11 C4b — the Phase 1.6 Context Layers Capture hooks moved to core/twin-core/bootstrap.php.

/* ══════════════════════════════════════════════════════════════
 *  TEMPLATE PAGE — Tools Map (universal AI tools panel)
 *  Touch Bar clicks → /tools-map/?bizcity_iframe=1 → tools overview
 * ══════════════════════════════════════════════════════════════ */
// [2026-09-25 Claude Opus 5.5] CORE-REDUCTION WP-11 C3a — public intent pages (/tools-map, /tool-*, /tasks, /chat-sessions) and their views retired.

/* ── Boot ── */
add_action( 'plugins_loaded', function () {
    // [2026-10-24 Johnny Chu - Chu Hoàng Anh] PHASE-0.126 R126-I1 — the intent table check / creation, the stale-conversation cron and the
    // prompt-log retention cron moved with BizCity_Intent_Database to bizcity-twin-brain-addon/twinbrain-mpr/intent/bootstrap.php.

    // [2026-09-25 Claude Opus 5.5] WP-11 C4b — Context_Builder boots from core/twin-core; Rolling + Episodic memory boot from core/memory.

    // [2026-09-25 Claude Opus 5.5] CORE-REDUCTION WP-11 C3a — Intent Engine retired (path C); get_ai_response never used it.

    // [2026-09-25 Claude Opus 5.5] WP-11 C3a — seed composite tools (Tool_Registry_Map) retired with the class.

    // [2026-09-25 Claude Opus 5.5] WP-11 C4b — the Episodic Memory daily aggregation cron is scheduled from core/memory.

    // [2026-09-25 Claude Opus 5.5] WP-11 C5 — the Prompt Context Logger (written only by the retired classifier) is retired:
    // drop its daily cleanup event so no scheduled hook is left without a handler.
    if ( wp_next_scheduled( 'bizcity_prompt_log_cleanup' ) ) {
        wp_clear_scheduled_hook( 'bizcity_prompt_log_cleanup' );
    }

    // [2026-08-01 Johnny Chu] PHASE-1.24-LOG-RETENTION — bounded cleanup for the two
    // unbounded SQL log tables (bizcity_intent_logs, bizcity_intent_prompt_logs).
    // [2026-10-10 12:36 AM Johnny Chu - Chu Hoàng Anh] CORE-REDUCTION WP-20 Z-3 — Intent_Logger archived: drop its (no-op)
    // daily retention event so no scheduled hook is left without a handler.
    if ( wp_next_scheduled( 'bizcity_intent_logs_retention' ) ) {
        wp_clear_scheduled_hook( 'bizcity_intent_logs_retention' );
    }

    // Monitor dashboard (admin only) — defer to current_screen to avoid
    // [2026-06-09 Johnny Chu] PERF-1 — instantiating these on EVERY admin page.
    // Intent Monitor / Data Browser / Tool Control Panel only needed on their own pages.
    // [2026-09-25 Claude Opus 5.5] CORE-REDUCTION WP-11 C3a — Intent Monitor / Data Browser / Tool Control Panel retired (group C).

    // [2026-09-25 Claude Opus 5.5] CORE-REDUCTION WP-11 C3a — REST bizcity/v1 and bizcity-intent/v1 retired (D-28).

    // Fire action so other plugins can register tools
    do_action( 'bizcity_intent_register_tools', BizCity_Intent_Tools::instance() );

    // Provider Registry: let plugins register their skill providers
    $registry = BizCity_Intent_Provider_Registry::instance();
    do_action( 'bizcity_intent_register_providers', $registry );
    $registry->boot();

    // [2026-10-25 Johnny Chu - Chu Hoàng Anh] PHASE-0.126 R126-TC — the one-time bizcity_tool_registry row retirements (WP-12 R5, WP-20 Z-3b, Kling) and the activated_plugin /
    // deactivated_plugin sync hooks only wrote that mirror table; they left with BizCity_Intent_Tool_Index.

    // [2026-10-10 12:36 AM Johnny Chu - Chu Hoàng Anh] CORE-REDUCTION WP-20 Z-3 — the prompt-log listener on
    // bizcity_intent_processed → core/_archived/z3-20261010/intent/bootstrap-prompt-log-listener.php (no emitter left).
}, 5 );

/* ======================================================================
 * PUBLIC HELPER FUNCTIONS
 * Each function is individually guarded — PHP function declarations cannot be
 * conditionally skipped with a single `return`, so each one needs its own
 * if(!function_exists()) wrapper to survive coexistence with the legacy mu-plugin.
 * ====================================================================== */

if ( ! function_exists( 'bizcity_intent_register_tool' ) ) {
    /**
     * Register a tool with the Intent Engine.
     *
     * @param string   $name
     * @param array    $schema
     * @param callable $callback
     */
    function bizcity_intent_register_tool( $name, array $schema, $callback ) {
        // [2026-10-10 12:36 AM Johnny Chu - Chu Hoàng Anh] CORE-REDUCTION WP-20 Z-3 — no caller in any installed plugin; legacy, dev notice only.
        if ( function_exists( 'bizcity_z3_legacy_api_notice' ) ) {
            bizcity_z3_legacy_api_notice( 'bizcity_intent_register_tool()', 'BizCity_Zalo_Brain::register_tool()' );
        }
        BizCity_Intent_Tools::instance()->register( $name, $schema, $callback );
    }
}

// [2026-10-24 Johnny Chu - Chu Hoàng Anh] PHASE-0.126 R126-I1 — bizcity_intent_get_conversation() (no caller) moved with Intent_Conversation to the brain add-on.
