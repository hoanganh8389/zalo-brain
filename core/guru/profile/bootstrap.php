<?php
/**
 * Bizcity Twin AI — Nền tảng AI Companion cá nhân hóa
 * Bizcity Twin AI — Personalized AI Companion Platform
 *
 * Knowledge Module — AI Assistants & Knowledge Management
 * Module Kiến thức — Các trợ lý AI & Quản lý Kiến thức
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Knowledge
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 * @version    2.0.0
 * @since      2026-02-08
 */

defined('ABSPATH') or die('OOPS...');

// Constants — guarded to allow coexistence with legacy mu-plugin during migration
if ( ! defined( 'BIZCITY_KNOWLEDGE_DIR' ) ) {
    define('BIZCITY_KNOWLEDGE_DIR', __DIR__ . '/');
}
if ( ! defined( 'BIZCITY_KNOWLEDGE_VERSION' ) ) {
    define('BIZCITY_KNOWLEDGE_VERSION', '2.0.7');
}
if ( ! defined( 'BIZCITY_KNOWLEDGE_INCLUDES' ) ) {
    define('BIZCITY_KNOWLEDGE_INCLUDES', BIZCITY_KNOWLEDGE_DIR . 'includes/');
}
if ( ! defined( 'BIZCITY_KNOWLEDGE_LIB' ) ) {
    define('BIZCITY_KNOWLEDGE_LIB', BIZCITY_KNOWLEDGE_DIR . 'lib/');
}
// [2026-10-16 03:35 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.116 CS3 (doc 87) — core/knowledge + core/persona + core/memory merged into core/guru/{profile,admin,runtime,memory}; the admin UI (menu, views, assets, templates) lives in core/guru/admin.
if ( ! defined( 'BIZCITY_GURU_ADMIN_DIR' ) ) {
    define('BIZCITY_GURU_ADMIN_DIR', dirname( BIZCITY_KNOWLEDGE_DIR ) . '/admin/');
}
if ( ! defined( 'BIZCITY_KNOWLEDGE_ASSETS' ) ) {
    define('BIZCITY_KNOWLEDGE_ASSETS', BIZCITY_GURU_ADMIN_DIR . 'assets/');
}
if ( ! defined( 'BIZCITY_KNOWLEDGE_VIEWS' ) ) {
    define('BIZCITY_KNOWLEDGE_VIEWS', BIZCITY_GURU_ADMIN_DIR . 'views/');
}
if ( ! defined( 'BIZCITY_KNOWLEDGE_TEMPLATES' ) ) {
    define('BIZCITY_KNOWLEDGE_TEMPLATES', BIZCITY_GURU_ADMIN_DIR . 'templates/');
}

// Load shared services (used by both admin-ajax and REST API)
// Skip if already loaded by legacy mu-plugin
if ( class_exists( 'BizCity_Knowledge_Database' ) ) {
    return;
}

// [2026-08-29 Johnny Chu] R-METADATA-CACHE — use the shared helper for early knowledge loads instead of a module-local polyfill.
if ( ! class_exists( 'BizCity_Table_Metadata', false ) ) {
    $_kg_safe_loader = dirname( __DIR__, 2 ) . '/helper/class-bizcity-safe-loader.php';
    if ( ! class_exists( 'BizCity_Safe_Loader', false ) && is_file( $_kg_safe_loader ) && is_readable( $_kg_safe_loader ) ) {
        require_once $_kg_safe_loader;
    }
    unset( $_kg_safe_loader );
}
if ( class_exists( 'BizCity_Safe_Loader', false ) && ! class_exists( 'BizCity_Table_Metadata', false ) ) {
    BizCity_Safe_Loader::require_file( dirname( __DIR__, 2 ) . '/helper/class-bizcity-table-metadata.php', 'helper.table_metadata.early' );
}

// [2026-06-11 Johnny Chu] R-PERF — Admin/REST/AJAX context gate (same pattern as bizcity-twin-ai.php)
// Frontend HTML renders (GET /chat/, GET /app/) do NOT need admin menus, REST controllers,
// or chat gateway — those only fire during wp_ajax_* or /wp-json/ requests.
// [2026-06-29 Johnny Chu] HOTFIX — fallback gate extended to cover ALL webhook URL patterns.
// Facebook webhook is /?fbhook=1 or /bizfbhook/ — NOT /wp-json/. Without these patterns,
// $_kg_admin_ctx=false → class-chat-gateway.php NOT loaded → system_prompt + quick_faq
// never injected. This fix also covers the case when mu-plugin loads this file before
// $_bizcity_admin_ctx is set in bizcity-twin-ai.php.
$_kg_admin_ctx = isset( $_bizcity_admin_ctx )
    ? $_bizcity_admin_ctx
    : (
        is_admin()
        || ( defined( 'DOING_CRON' ) && DOING_CRON )
        || ( defined( 'WP_CLI' ) && WP_CLI )
        || (
            ! empty( $_SERVER['REQUEST_URI'] )
            && (
                false !== strpos( $_SERVER['REQUEST_URI'], '/wp-json/' )
                // [2026-09-27 Claude Opus 5.5] CORE-REDUCTION WP-12 R10 — /bizhook/ (retired Zalo Hotline webhook) removed.
                || false !== strpos( $_SERVER['REQUEST_URI'], '/zalohook/' )
                || false !== strpos( $_SERVER['REQUEST_URI'], '/bizfbhook' )
                || false !== strpos( $_SERVER['REQUEST_URI'], '/tool-' )
                || preg_match( '#^/doc/?(\?|$)#', $_SERVER['REQUEST_URI'] )
            )
        )
        || (
            ! empty( $_SERVER['QUERY_STRING'] )
            && (
                false !== strpos( (string) $_SERVER['QUERY_STRING'], 'fbhook=1' )
                || false !== strpos( (string) $_SERVER['QUERY_STRING'], 'biz_fb_oauth' )
                || false !== strpos( (string) $_SERVER['QUERY_STRING'], 'fb_callback=1' )
            )
        )
    );

// [2026-09-30 Claude Opus 5.5] CORE-REDUCTION R14c / R-VERTICAL-AXIS R-VA-6 (CUT) — the five services/ classes (auth, chat history,
// session, project, chat send; 55 KB loaded on every request) served only the path C REST APIs below, which were off
// unless BIZCITY_LEGACY_PATH_C. All seven files, plus the hook-less admin chat, are in core/_archived/knowledge-path-c/.

// Load includes
require_once BIZCITY_KNOWLEDGE_INCLUDES . 'class-database.php';

// Force migration early for metadata column fix
if (is_admin()) {
    add_action('admin_init', function() {
        BizCity_Knowledge_Database::maybe_create_tables();
    }, 1);
}

require_once BIZCITY_KNOWLEDGE_INCLUDES . 'class-character.php';
require_once BIZCITY_KNOWLEDGE_INCLUDES . 'class-knowledge-source.php';
// [2026-10-01 Claude Sonnet 5] CORE-REDUCTION WP-17 K-1 (R-LEAN-4) — class-user-memory.php moved to its real owner,
// core/guru/memory/includes/ (same class name, same tables); core/guru/memory/bootstrap.php now requires + instantiates it.
// [2026-10-01 Claude Sonnet 5] CORE-REDUCTION WP-17 K-2 (R-LEAN-4, R-GURU-SOURCE) — class-profile-context.php,
// class-agent-binding.php, class-intent-provider.php, lib/class-intent-parser.php, lib/class-context-api.php and
// lib/class-chat-api.php moved to the add-on (bizcity-twin-brain-addon/knowledge-legacy/); loaded + instantiated
// below through BizCity_Addon_Locator, at the same points in this file as before.
// [2026-10-10 10:33 PM Johnny Chu - Chu Hoàng Anh] CORE-REDUCTION WP-20 Z-3b — knowledge-legacy retired (owner 2026-10-10):
// the add-on folder moved to bizcity-twin-brain-addon/_archived/knowledge-legacy-20261010/ and is no longer loaded. Every
// caller of its classes (Chat_Gateway, Profile_Context, Knowledge_Context_API, Intent_Parser) is class_exists-guarded.

// [2026-06-11 Johnny Chu] R-PERF — Admin-only (~277 KB): admin menu + admin chat
if ( is_admin() ) {
    require_once BIZCITY_GURU_ADMIN_DIR . 'includes/class-admin-menu.php';
}

// [2026-06-11 Johnny Chu] R-PERF — REST/AJAX-only (~247 KB): chat gateway + REST controllers
// All wp_ajax_nopriv_* handlers go through admin-ajax.php (is_admin()=true) → gating is safe.
if ( $_kg_admin_ctx ) {
    // [2026-10-16 03:22 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.116 CS1 (doc 87) — class-api.php (v1 GET /characters, /query, /parse-intent, /knowledge, /search; no caller) archived to core/_archived/knowledge-20261016/.
    require_once BIZCITY_KNOWLEDGE_INCLUDES . 'class-character-quick-edit-rest.php';
    // [2026-09-24 Claude Opus 5] CORE-REDUCTION-WP-09 T5a–T5c — Guru editor REST (R1–R9) for /twinkg/.
    require_once BIZCITY_KNOWLEDGE_INCLUDES . 'class-guru-service.php';
    // [2026-10-16 03:22 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.116 CS1 (doc 87) — class-guru-admin-rest.php (v2 /gurus/*; its UI /twinkg/ is archived) archived likewise.
    // [2026-10-10 10:33 PM Johnny Chu - Chu Hoàng Anh] CORE-REDUCTION WP-20 Z-3b — the PHP fallback reply
    // (BizCity_Chat_Gateway, WP-17 K-2 add-on part) is archived with knowledge-legacy; nothing loads it any more.
}
// [2026-09-30 Claude Opus 5.5] CORE-REDUCTION R14d / R-VERTICAL-AXIS R-VA-6 (CUT, owner 2026-09-30) — Phase 4.5 "Companion Intelligence" is off: emotional memory,
// thread tracker, companion context and response texture are in core/_archived/knowledge-companion/. Their writer hooks
// (bizcity_chat_after_response, bizcity_session_start/end) had no emitter left, so they only replayed frozen signals
// into TwinBrain prompts. Existing rows stay in the memory store; BizCity_User_Memory hides their types from prompts.
require_once BIZCITY_KNOWLEDGE_INCLUDES . 'functions.php';

// Load lib
require_once BIZCITY_KNOWLEDGE_LIB . 'class-content-importer.php';
require_once BIZCITY_KNOWLEDGE_LIB . 'class-file-processor.php';
// [2026-10-01 Claude Sonnet 5] CORE-REDUCTION WP-17 K-1 (R-LEAN-4) — class-embedding.php and class-file-parser.php
// moved to core/kg-hub/includes/ (same class names); core/kg-hub/bootstrap.php now requires them directly instead
// of relying on core/knowledge having loaded them first.
require_once BIZCITY_KNOWLEDGE_LIB . 'class-web-crawler.php';
require_once BIZCITY_KNOWLEDGE_LIB . 'class-knowledge-fabric.php'; // Knowledge Fabric v3.0 — unified multi-scope pipeline

// Phase 0.3 — Knowledge Graph Hub (KG-Hub)
// 2026-05-01 — restored after OneDrive merge conflict dropped this require.
// Without this, BizCity_KG_Rest_Controller never registers and the REST
// routes `bizcity-knowledge/v2/notebooks` / `/graph` return 404.
// [2026-09-24 Claude Opus 5.5] CORE-REDUCTION WP-11 Part A — KG-Hub now lives at core/kg-hub/ (a peer of
// core/knowledge). Same gate as before: it still loads here, only the path changed. The legacy location is
// tried second so a partially deployed tree keeps working; a missing KG-Hub is logged, never silent.
$_bizcity_kg_hub_bootstrap = '';
foreach ( array( dirname( BIZCITY_KNOWLEDGE_DIR, 2 ) . '/kg-hub/bootstrap.php', BIZCITY_KNOWLEDGE_DIR . 'kg-hub/bootstrap.php' ) as $_bizcity_kg_hub_candidate ) {
    if ( is_file( $_bizcity_kg_hub_candidate ) ) {
        $_bizcity_kg_hub_bootstrap = $_bizcity_kg_hub_candidate;
        break;
    }
}
if ( '' === $_bizcity_kg_hub_bootstrap ) {
    error_log( '[bizcity] kg_hub_bootstrap_missing: core/kg-hub/bootstrap.php not found — KG-Hub routes will 404' );
} elseif ( class_exists( 'BizCity_Safe_Loader', false ) ) {
    // R-SAFE-LOADER: no unguarded fallback — the Safe Loader is loaded by core/helper before any module bootstrap.
    BizCity_Safe_Loader::require_file( $_bizcity_kg_hub_bootstrap, 'knowledge.kg_hub.bootstrap' );
} else {
    error_log( '[bizcity] kg_hub_bootstrap_skipped: BizCity_Safe_Loader unavailable' );
}
unset( $_bizcity_kg_hub_bootstrap, $_bizcity_kg_hub_candidate );

// [2026-10-01 Claude Sonnet 5] CORE-REDUCTION WP-17 K-1 — User Memory instance() moved to core/guru/memory/bootstrap.php.
// [2026-10-01 Claude Sonnet 5] CORE-REDUCTION WP-17 K-2 — Context API / Agent Binding instance() calls and the
// Knowledge Fabric Intent Provider registration moved into bizcity-twin-brain-addon/knowledge-legacy/bootstrap.php
// (called earlier in this file, right after class-character.php/class-knowledge-source.php); their constructors
// only register WordPress hooks (no synchronous KG-Hub use), so running them before KG-Hub loads is safe.

// WP-Cron: Cleanup expired session knowledge (every 6 hours)
add_action( 'bizcity_knowledge_fabric_cleanup', function() {
    if ( class_exists( 'BizCity_Knowledge_Fabric' ) ) {
        BizCity_Knowledge_Fabric::instance()->cleanup_expired_sessions( 24 );
    }
} );
if ( ! wp_next_scheduled( 'bizcity_knowledge_fabric_cleanup' ) ) {
    wp_schedule_event( time(), 'twicedaily', 'bizcity_knowledge_fabric_cleanup' );
}

// Initialize Chat Gateway, REST controllers (gated — only loaded in admin/REST/AJAX context)
// [2026-06-11 Johnny Chu] R-PERF — class_exists guards match the require_once gates above
// [2026-10-10 10:33 PM Johnny Chu - Chu Hoàng Anh] CORE-REDUCTION WP-20 Z-3b — BizCity_Chat_Gateway::instance() removed:
// the class is archived with knowledge-legacy (no loader left).
// [2026-09-30 Claude Opus 5.5] CORE-REDUCTION R14c / R-VERTICAL-AXIS R-VA-6 (CUT) — REST bizcity-chat/v1 and bizcity-agent/v1 (path C,
// flag-only since WP-11 C2) are archived; BIZCITY_LEGACY_PATH_C no longer brings them back.

// Initialize Admin Menu (admin context only)
if ( is_admin() ) {
    if ( class_exists( 'BizCity_Knowledge_Admin_Menu' ) ) BizCity_Knowledge_Admin_Menu::instance();
}

// ── Phase 5.2 — Legal AI Module ───────────────────────────────────────────
// 2026-05-21 — REMOVED. Module `core/knowledge/legal/` đã bị xoá khỏi codebase
// (crawler/chunker/graph-builder/REST/character/...). Cron events cũ vẫn được
// unschedule idempotent dưới đây để không còn tick rỗng trên các site đã từng
// bật `BIZCITY_LEGAL_ENABLED`.
add_action( 'init', function() {
    foreach ( [ 'bizcity_legal_crawl_batch', 'bizcity_legal_discover', 'bizcity_legal_graph_batch' ] as $hook ) {
        $ts = wp_next_scheduled( $hook );
        while ( $ts ) {
            wp_unschedule_event( $ts, $hook );
            $ts = wp_next_scheduled( $hook );
        }
    }
}, 4 );

// [2026-10-16 03:22 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.116 CS1 (doc 87) — class BizCity_Knowledge (filters bizcity_webchat_process_message / bizcity_automation_triggers / bizcity_automation_actions / bcam_get_agent_data had no applier anywhere; bizcity_knowledge() had no caller) removed; kept in _archived/knowledge-20261016/bootstrap.before-CS1.php.
