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

// [2026-10-25 Johnny Chu - Chu Hoàng Anh] PHASE-0.126 R126-RT — BizCity_Execution_Logger, BizCity_Trace_Store and BizCity_Job_Trace (53 KB) had no writer left: nothing called
// pipeline_start / tool_invoke, begin_trace / record_step or Job_Trace::start since the PHP agent loop went (Q126-19) — the cell
// traces its own turns. Archived at core/_archived/runtime-trace-r126rt-20261025/; bizcity_traces / bizcity_trace_tasks are quarantined.

// [2026-10-22 06:40 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.126 Q126-19 — the PHP agent loop is gone (cell is the brain): BizCity_Twin_Runner, Twin_Run_State,
// Twin_Rolling_Session, Twin_Session, TwinShell_Event_Bus, Twin_DB_Installer and the REST POST bizcity-twin/v1/run + GET /events/{run_id} live in
// core/_archived/runtime-agent-loop-20261022/. Tables bizcity_twin_runs / bizcity_twin_hil are retired and dropped once per site (PHASE-0.126 Q126-36, BizCity_Legacy_Table_Policy::drop_owner_retired).
