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
if ( class_exists( 'BizCity_Intent_Database' ) ) {
    // Legacy mu-plugin loaded core classes.
    // [2026-09-25 Claude Opus 5.5] WP-11 C4b — Trace_Store / Execution_Logger are owned and loaded by core/runtime now.
    return;
}

/* ── Data layer + Infrastructure (always loaded) ── */

/* -- infrastructure/ -- */
require_once BIZCITY_INTENT_DIR . '/includes/infrastructure/class-intent-database.php';
// [2026-10-10 12:36 AM Johnny Chu - Chu Hoàng Anh] CORE-REDUCTION WP-20 Z-3 — BizCity_Intent_Logger → core/_archived/z3-20261010/intent/:
// nothing in any installed plugin calls it (its writers went with WP-11) and its retention job was already a no-op.
// [2026-09-25 Claude Opus 5.5] WP-11 C5 — Prompt_Context_Logger retired (written only by the old classifier).
// [2026-09-25 Claude Opus 5.5] WP-11 C4b — Execution_Logger, Trace_Store, Job_Trace → core/runtime.

/* -- conversation/ -- */
require_once BIZCITY_INTENT_DIR . '/includes/conversation/class-intent-conversation.php';
// [2026-09-25 Claude Opus 5.5] WP-11 C4b — Rolling_Memory, Episodic_Memory → core/memory; Context_Builder → core/twin-core.

/* -- providers/ -- */
require_once BIZCITY_INTENT_DIR . '/includes/providers/class-intent-provider.php';
require_once BIZCITY_INTENT_DIR . '/includes/providers/class-intent-simple-provider.php';
require_once BIZCITY_INTENT_DIR . '/includes/providers/class-intent-provider-registry.php';

/* -- routing/ -- */
require_once BIZCITY_INTENT_DIR . '/includes/routing/class-intent-router.php';
// [2026-09-24 Claude Opus 5] CORE-REDUCTION-WP-02 I-04 — class-knowledge-router.php retired
// under R-ORPHAN-FILE. It declared BizCity_Knowledge_Provider_Registry and
// BizCity_Knowledge_Router_Pipeline, neither referenced anywhere, and was parsed on every
// intent request. Knowledge routing is owned by the KG-Hub retriever and TwinBrain.

/* -- classification/ -- */
// [2026-09-25 Claude Opus 5.5] WP-11 C5 — Mode_Classifier is a thin alias over Intent_Router (sunset); Classify_Cache retired.
require_once BIZCITY_INTENT_DIR . '/includes/classification/class-mode-classifier.php';

/* -- tools/ -- */
require_once BIZCITY_INTENT_DIR . '/includes/tools/class-intent-tools.php';
require_once BIZCITY_INTENT_DIR . '/includes/tools/class-intent-tool-index.php';
// [2026-09-26 Claude Opus 5.5] CORE-REDUCTION WP-12 R5 — BizCity_Tool_Run retired: its callers (intent engine,
// it_call_tool) went with WP-11, and its skill / resource branches pointed at archived core/skills + core/tools.
// File renamed *_deleted.php (R-ORPHAN-FILE).

// [2026-09-25 Claude Opus 5.5] WP-11 C5 — Intent_Planner retired (only the old Router used it).

/* -- Phase 1 — Unified Pipeline (Evidence, IO Mapper, Core Planner, Scenario) -- */
require_once BIZCITY_INTENT_DIR . '/includes/tools/class-tool-evidence.php';

/* -- Phase 1 Addendum — Objective Understanding, Execution Planner, Variant, One-Shot, Step Executor -- */

/* -- Phase 1.1 — Pipeline Middleware (HIL, Evidence, ToDos, Schema Adapter, Messenger) -- */

// [2026-09-25 Claude Opus 5.5] WP-11 C4b — Context_Layers_Capture → core/twin-core (with the prompt-capture hooks).

/* ── Init CPT registrations ── */
BizCity_Tool_Evidence::init();

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
    // Database table check / creation
    BizCity_Intent_Database::instance()->maybe_create_tables();

    // [2026-09-25 Claude Opus 5.5] WP-11 C4b — Context_Builder boots from core/twin-core; Rolling + Episodic memory boot from core/memory.

    // [2026-09-25 Claude Opus 5.5] CORE-REDUCTION WP-11 C3a — Intent Engine retired (path C); get_ai_response never used it.

    // [2026-09-25 Claude Opus 5.5] WP-11 C3a — seed composite tools (Tool_Registry_Map) retired with the class.

    // ── O10: WP-Cron for reliable stale conversation cleanup (v3.6.1) ──
    // [2026-09-25 Claude Opus 5.5] CORE-REDUCTION WP-11 C3b — intent_conversations is write-refused (draining cohort),
    // so the hourly expiry job is unscheduled instead of firing a no-op.
    global $wpdb;
    $bizcity_intent_conv_writable = class_exists( 'BizCity_Legacy_Table_Policy' )
        && BizCity_Legacy_Table_Policy::allow_sql( $wpdb->prefix . 'bizcity_intent_conversations', 'write' );
    if ( $bizcity_intent_conv_writable ) {
        add_action( 'bizcity_intent_stale_cleanup', function () {
            BizCity_Intent_Database::instance()->expire_stale();
        } );
        if ( ! wp_next_scheduled( 'bizcity_intent_stale_cleanup' ) ) {
            wp_schedule_event( time(), 'hourly', 'bizcity_intent_stale_cleanup' );
        }
    } elseif ( wp_next_scheduled( 'bizcity_intent_stale_cleanup' ) ) {
        wp_clear_scheduled_hook( 'bizcity_intent_stale_cleanup' );
    }

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
    add_action( 'init', array( 'BizCity_Intent_Database', 'register_retention_cron' ), 20 );
    add_action( BizCity_Intent_Database::PROMPT_LOGS_RETENTION_HOOK, array( 'BizCity_Intent_Database', 'gc_prompt_logs' ) );

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

    // [2026-09-26 Claude Opus 5.5] CORE-REDUCTION WP-12 R5 — the 15 legacy built-in tools are no longer registered, but
    // their bizcity_tool_registry rows stay active until a full sync (CLI / plugin activation only). Deactivate them
    // once per blog so the LLM tool manifest stops offering tools that answer "không được tìm thấy".
    add_action( 'init', static function () {
        if ( get_option( 'bizcity_wp12_r5_builtin_rows_retired' ) ) {
            return;
        }
        global $wpdb;
        $table = $wpdb->prefix . 'bizcity_tool_registry';
        if ( function_exists( 'bizcity_tbl_exists' ) && ! bizcity_tbl_exists( $table ) ) {
            update_option( 'bizcity_wp12_r5_builtin_rows_retired', 1, false );
            return;
        }
        $retired = array( 'create_product', 'generate_report', 'inventory_report', 'post_facebook', 'write_article',
            'set_reminder', 'edit_product', 'create_order', 'list_orders', 'find_customer', 'customer_stats',
            'product_stats', 'inventory_journal', 'warehouse_receipt', 'help_guide' );
        $keys         = array_map( static function ( $n ) { return 'builtin:' . $n; }, $retired );
        $placeholders = implode( ',', array_fill( 0, count( $retired ), '%s' ) );
        $prev         = $wpdb->suppress_errors( true );
        // Match by key, and by name for older rows written with plugin = 'builtin' but another key; provider rows are untouched.
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$table} SET active = 0 WHERE active = 1 AND ( tool_key IN ({$placeholders}) OR ( plugin = 'builtin' AND tool_name IN ({$placeholders}) ) )",
            ...array_merge( $keys, $retired )
        ) );
        $wpdb->suppress_errors( $prev );
        delete_transient( BizCity_Intent_Tool_Index::MANIFEST_CACHE_KEY );
        update_option( 'bizcity_wp12_r5_builtin_rows_retired', 1, false );
    }, 30 );

    // ── Tool Registry: event-driven sync on plugin activate/deactivate ──
    // WordPress fires `activated_plugin` after activate_plugin() succeeds.
    // The newly-activated plugin has already registered its provider above
    // (if it hooked `bizcity_intent_register_providers`), so we can sync it.
    add_action( 'activated_plugin', function ( $plugin_file ) use ( $registry ) {
        // Resolve plugin slug from plugin file (e.g. 'bizcity-tarot/bizcity-tarot.php' → 'bizcity-tarot')
        $slug = dirname( $plugin_file );
        if ( $slug === '.' ) {
            $slug = basename( $plugin_file, '.php' );
        }

        $tool_index = BizCity_Intent_Tool_Index::instance();

        // Check if this plugin registered a provider
        $provider = $registry->get( $slug );
        if ( $provider ) {
            $tool_index->sync_provider( $provider );
        } else {
            // Plugin may not have registered yet (late hook) — schedule re-sync
            // via a transient flag that sync_all() checks on next boot
            $tool_index->ensure_schema();
            // Re-activate any previously-deactivated rows for this plugin
            global $wpdb;
            $table = $wpdb->prefix . 'bizcity_tool_registry';
            $reactivated = $wpdb->query( $wpdb->prepare(
                "UPDATE {$table} SET active = 1 WHERE plugin = %s AND active = 0",
                $slug
            ) );
            if ( $reactivated > 0 ) {
                delete_transient( BizCity_Intent_Tool_Index::MANIFEST_CACHE_KEY );
                do_action( 'bizcity_tool_registry_changed', 'reactivate', $slug, [] );
            }
        }
    }, 20 );

    // WordPress fires `deactivated_plugin` after deactivate_plugins() succeeds.
    add_action( 'deactivated_plugin', function ( $plugin_file ) {
        $slug = dirname( $plugin_file );
        if ( $slug === '.' ) {
            $slug = basename( $plugin_file, '.php' );
        }
        BizCity_Intent_Tool_Index::instance()->unsync_plugin( $slug );
    }, 20 );

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

if ( ! function_exists( 'bizcity_intent_get_conversation' ) ) {
    /**
     * Get or create an active conversation for a user + channel.
     *
     * @param int    $user_id
     * @param string $channel
     * @param string $session_id
     * @return array|null
     */
    function bizcity_intent_get_conversation( $user_id, $channel = 'webchat', $session_id = '' ) {
        // [2026-10-10 12:36 AM Johnny Chu - Chu Hoàng Anh] CORE-REDUCTION WP-20 Z-3 — no caller in any installed plugin; legacy, dev notice only.
        if ( function_exists( 'bizcity_z3_legacy_api_notice' ) ) {
            bizcity_z3_legacy_api_notice( 'bizcity_intent_get_conversation()', 'core/conversation message store' );
        }
        return BizCity_Intent_Conversation::instance()->get_active( $user_id, $channel, $session_id );
    }
}
