<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Runtime
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 *
 * Bootstrap for core/runtime — Phase 0.13 runtime layer.
 *
 * Loads all runtime classes and registers the REST endpoint.
 * Agents bootstrap must be loaded first (agents/ bootstrap.php).
 *
 * @since 1.13.0
 */

defined( 'ABSPATH' ) || exit;
if ( defined( 'BIZCITY_TWIN_RUNTIME_LOADED' ) ) return;
define( 'BIZCITY_TWIN_RUNTIME_LOADED', true );

// [2026-06-09 Johnny Chu] R-CR — Central registries: load FIRST so all classes below
// (and all satellite plugins loaded after) can call ::register() at file-load time.
// [2026-08-10 Johnny Chu] PHASE-1.23-CANONICAL-W2 - load the observe-only
// feature ownership registry before runtime classes; it performs no DB/cache work.
require_once __DIR__ . '/class-loader-ownership-registry.php';
require_once __DIR__ . '/class-rewrite-flush-registry.php';
require_once __DIR__ . '/class-schema-registry.php';

// [2026-06-11 Johnny Chu] R-PERF — Unified in-request user_meta cache (avoids WP meta prime on large blobs).
// file_exists guard: file may not exist on older deploys — degrade gracefully until deployed.
if ( file_exists( __DIR__ . '/class-user-meta-cache.php' ) ) {
	require_once __DIR__ . '/class-user-meta-cache.php';
}

// Boot flush registry: wires plugins_loaded:5 version check + admin_init:1 flush.
BizCity_Rewrite_Flush_Registry::boot();

// [2026-09-25 Claude Opus 5.5] CORE-REDUCTION WP-11 C4b (R-IM-5) — execution / pipeline trace owners moved here from
// core/intent. Same class names and tables; the file-scope side effects (Execution_Logger shutdown flush, Job_Trace
// cleanup cron hook) now run when core/runtime loads. Without the safe loader the classes stay absent and callers
// (all class_exists-guarded or run inside the same request as the loader) degrade instead of fataling.
if ( class_exists( 'BizCity_Safe_Loader', false ) ) {
	BizCity_Safe_Loader::require_file( __DIR__ . '/class-execution-logger.php', 'runtime.execution_logger' );
	BizCity_Safe_Loader::require_file( __DIR__ . '/class-trace-store.php', 'runtime.trace_store' );
	BizCity_Safe_Loader::require_file( __DIR__ . '/class-job-trace.php', 'runtime.job_trace' );
} else {
	error_log( '[bizcity] runtime_trace_classes_skipped: BizCity_Safe_Loader unavailable' );
}

// [2026-10-22 06:40 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.126 Q126-19 — the PHP agent loop is gone (cell is the brain): BizCity_Twin_Runner, Twin_Run_State,
// Twin_Rolling_Session, Twin_Session, TwinShell_Event_Bus, Twin_DB_Installer and the REST POST bizcity-twin/v1/run + GET /events/{run_id} live in
// core/_archived/runtime-agent-loop-20261022/. Tables bizcity_twin_runs / bizcity_twin_hil are retired and dropped once per site (PHASE-0.126 Q126-36, BizCity_Legacy_Table_Policy::drop_owner_retired).
