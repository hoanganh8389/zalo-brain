<?php
/**
 * TwinWeb axis — core bootstrap (REST `bizcity-twinweb/v1`, Ask Brain document + work tools, artifact jobs, access policy).
 *
 * [2026-10-12 11:36 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.102 ST102-L24 — what modules/twinweb used to carry that the CORE needs even when the
 * /gpt/ add-on (bizcity-twinweb-addon) is not installed (owner round 1+2, 2026-10-12): class-twinweb-rest.php (whole, 705 KB) with the
 * twelve TwinWeb classes it calls, the Ask Brain tool adapters and catalog, the work_* CRM tools, durable artifact jobs, the Guru binding
 * bootstrap, the thread registry, the two tables and the profile templates. The /gpt/ page, its SPA, projects / CRM-tasks / CRM-pipeline
 * REST, profile-grounding admin and the page installer stay in modules/twinweb (the add-on from W3).
 *
 * Load order = the old modules/twinweb/bootstrap.php order for these parts. Same request gate as before (bizcity-twin-ai.php:
 * everything except the TwinChat admin-shell request). The REST slice (BizCity_TwinWeb_Rest_Slice) decides per request whether the 705 KB
 * controller loads; the add-on registers its own REST classes with it.
 *
 * Names are unchanged on purpose (classes, tables bizcity_twinweb_*, options, hooks, REST namespace): no caller changes.
 *
 * PHP 7.4 compatible.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\TwinWebAxis
 */
defined( 'ABSPATH' ) || exit;

if ( defined( 'BIZCITY_TWINWEB_AXIS_DIR' ) ) {
	return;
}

define( 'BIZCITY_TWINWEB_AXIS_DIR', __DIR__ . '/' );

// ── Includes (old order) ──────────────────────────────────────────────────────
require_once BIZCITY_TWINWEB_AXIS_DIR . 'includes/class-twinweb-installer.php';
require_once BIZCITY_TWINWEB_AXIS_DIR . 'includes/class-twinweb-identity.php';
// [2026-10-11 11:00 AM Johnny Chu - Chu Hoàng Anh] CORE-REDUCTION WP-20 Z-6 P0-2/P2 — the Twin GPT access policy, always loaded:
// /gpt/, the Twin Shell module-access delegate and its REST answer from here, never from class_exists( REST ).
require_once BIZCITY_TWINWEB_AXIS_DIR . 'includes/class-twinweb-access-policy.php';
// [2026-07-19 Johnny Chu] PHASE-TWIN-GPT-AGENT-TOOLS — load contract-only tool catalog for intent/artifact planning.
require_once BIZCITY_TWINWEB_AXIS_DIR . 'includes/class-twinweb-agent-tool-catalog.php';
// [2026-07-20 Johnny Chu] PHASE-TWIN-GPT-AGENT-TOOLS — register real Doc Studio handoff adapters in the canonical tool registry.
require_once BIZCITY_TWINWEB_AXIS_DIR . 'includes/class-twinweb-agent-tool-adapters.php';
// [2026-07-20 Johnny Chu] PHASE-TWIN-GPT-AGENT-TOOLS — durable artifact job state store for AT-7 polling/replay.
require_once BIZCITY_TWINWEB_AXIS_DIR . 'includes/class-twinweb-artifact-jobs.php';
// [2026-07-19 Johnny Chu] PHASE-TWINWEB-THREADS — unified thread registry foundation for TwinWeb/TwinChat convergence.
require_once BIZCITY_TWINWEB_AXIS_DIR . 'includes/class-twinweb-thread-registry.php';
// [2026-07-19 Johnny Chu] PHASE-TWIN-GPT-PROFILE-GROUNDING — load core subject profile layer before TwinWeb REST/profile surfaces.
// [2026-10-03 Claude Sonnet 5] CORE-REDUCTION REVERSAL — TwinBrain (incl. this layer) lives in core/twinbrain/.
$_bizcity_twinweb_subject_profile_layer = BIZCITY_TWIN_AI_DIR . 'core/twinbrain/includes/class-twinbrain-subject-profile-layer.php';
if ( is_readable( $_bizcity_twinweb_subject_profile_layer ) ) {
	require_once $_bizcity_twinweb_subject_profile_layer;
}
unset( $_bizcity_twinweb_subject_profile_layer );
// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-MY-CONTENT-TRACE — load lightweight My Content artifact service for /gpt/ even when full Automation bootstrap is gated.
// [2026-10-01 Claude Opus 5.5] CORE-REDUCTION WP-16 B-4 S2 (R-LEAN-4, Q-W16-1) — the service lives with Automation (add-on plugin bizcity-automation).
$_bizcity_twinweb_content_artifact_service = class_exists( 'BizCity_Addon_Locator', false )
	? BizCity_Addon_Locator::file( 'automation/includes/class-content-artifact-service.php' )
	: '';
if ( '' !== $_bizcity_twinweb_content_artifact_service && is_readable( $_bizcity_twinweb_content_artifact_service ) ) {
	require_once $_bizcity_twinweb_content_artifact_service;
	if ( class_exists( 'BizCity_Content_Artifact_Service', false ) ) {
		BizCity_Content_Artifact_Service::init();
	}
}
unset( $_bizcity_twinweb_content_artifact_service );
// [2026-07-31 Johnny Chu] PHASE-TWINWEB-UNIFIED-SOURCES — persist MPR web citations into the canonical notebook source ledger through a deferred queue.
require_once BIZCITY_TWINWEB_AXIS_DIR . 'includes/class-twinweb-citation-source-persistence.php';
BizCity_TwinWeb_Citation_Source_Persistence::init();
// [2026-09-19] PHASE-0.55 A2 — Brain Chat "work.*" retriever tools (member's own tasks/pipeline/space, no colleague data).
require_once BIZCITY_TWINWEB_AXIS_DIR . 'includes/class-twinweb-crm-work-tools.php';
// [2026-07-14 Johnny Chu] PHASE-TWINWEB-SEARCH W1 — channel binding bootstrap (TWINWEB -> Guru)
require_once BIZCITY_TWINWEB_AXIS_DIR . 'includes/class-twinweb-binding-bootstrap.php';
// [2026-10-11 11:00 AM Johnny Chu - Chu Hoàng Anh] CORE-REDUCTION WP-20 Z-6 P0-2/P2 — REST slice (class-twinweb-rest.php 705 KB + REST-only
// helpers): loaded for bizcity-twinweb REST, the REST index, admin/ajax, CLI and the TwinWeb cron hooks; any other request
// gets a slice class lazily (autoloader / bizcity_twinweb_require_rest_slice()). Way back: bizcity_axis_rest_profile = off.
require_once BIZCITY_TWINWEB_AXIS_DIR . 'includes/class-twinweb-rest-slice.php';
BizCity_TwinWeb_Rest_Slice::boot();

// ── DB installer (deferred — avoid file-scope DB calls, R-PERF.2) ─────────────
add_action( 'plugins_loaded', function () {
	// [2026-07-31 Johnny Chu] R-PERF/R-MSDB — do not run TwinWeb tenant DDL on ordinary frontend HTML requests.
	$schema_context = is_admin()
		|| ( defined( 'REST_REQUEST' ) && REST_REQUEST )
		|| ( ! empty( $_SERVER['REQUEST_URI'] ) && false !== strpos( (string) $_SERVER['REQUEST_URI'], '/wp-json/' ) )
		|| ( defined( 'DOING_CRON' ) && DOING_CRON )
		|| ( defined( 'WP_CLI' ) && WP_CLI );
	if ( ! $schema_context ) {
		return;
	}
	// [2026-06-17 Johnny Chu] PHASE-TWINWEB — run installer only when needed
	if ( class_exists( 'BizCity_TwinWeb_Installer' ) ) {
		// [2026-06-22 Johnny Chu] PHASE-TWINWEB — also adds project_id column to threads (R-NO-NEW-TABLE)
		BizCity_TwinWeb_Installer::maybe_install();
	}
	// [2026-07-20 Johnny Chu] PHASE-TWIN-GPT-AGENT-TOOLS — schedule AT-7.6 durable artifact job poller with R-CRON-META evidence.
	if ( class_exists( 'BizCity_TwinWeb_Artifact_Jobs' ) ) {
		BizCity_TwinWeb_Artifact_Jobs::init_cron();
	}
}, 20 );

// Delegated owner of the `gpt` icon: the Twin GPT access policy answers, never a copy of it.
// [2026-10-11 11:00 AM Johnny Chu - Chu Hoàng Anh] CORE-REDUCTION WP-20 Z-6 P0-2/P2 — ask the always-loaded policy class; class_exists( REST )
// is false on every request that skips the REST slice and would silently answer the default.
add_filter( 'bizcity_module_access_delegate', static function ( $allowed, $module_id, $user_id ) {
	if ( 'gpt' !== $module_id || ! class_exists( 'BizCity_TwinWeb_Access_Policy', false ) ) {
		return $allowed;
	}
	return BizCity_TwinWeb_Access_Policy::module_access_allows_user( (int) $user_id );
}, 10, 3 );

// ── REST routes (the axis controller; the add-on registers its own classes on the same hook) ──
add_action( 'rest_api_init', function () {
	// [2026-06-17 Johnny Chu] PHASE-TWINWEB — register bizcity-twinweb/v1 routes
	// [2026-10-11 11:00 AM Johnny Chu - Chu Hoàng Anh] CORE-REDUCTION WP-20 Z-6 P0-2/P2 — class_exists( …, false ): never autoload the slice on another namespace.
	if ( class_exists( 'BizCity_TwinWeb_REST', false ) ) {
		BizCity_TwinWeb_REST::instance()->register_routes();
	}
} );

// ── No /gpt/ add-on: a page still holding [bizcity_twin] must never print the raw shortcode ──────────────────────────
// [2026-10-12 11:47 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.102 ST102-L8 — owner round 1: Channel Gateway is the home. A signed-in user who opens the old /gpt/ page is sent to the Twin Shell (opens 'Zalo Setup'); a guest, or an admin who wants to know why, gets one short card. The add-on registers the real [bizcity_twin] at init priority 10; this placeholder (priority 99) only fills the gap.
add_action( 'init', function () {
	if ( shortcode_exists( 'bizcity_twin' ) ) {
		return;
	}
	// The redirect has to happen before any output, so it lives on template_redirect; the shortcode below is only the card.
	// Never when the /gpt/ part IS available (bizcity_twinweb_url() would then point back at /gpt/ — a loop).
	add_action( 'template_redirect', function () {
		if ( ! is_user_logged_in() || ! is_singular() || ( function_exists( 'bizcity_twinweb_available' ) && bizcity_twinweb_available() ) ) {
			return;
		}
		$post = get_post();
		if ( $post && has_shortcode( (string) $post->post_content, 'bizcity_twin' ) ) {
			wp_safe_redirect( bizcity_twinweb_url() );
			exit;
		}
	}, 5 );
	add_shortcode( 'bizcity_twin', function () {
		$admin = current_user_can( 'manage_options' );
		return '<div class="bizcity-twinweb-missing" data-bizcity-addon-missing="twinweb" style="max-width:560px;margin:48px auto;padding:20px 24px;border:1px solid #e3e5ea;border-radius:12px;font:15px/1.5 system-ui,sans-serif">'
			. '<strong>' . esc_html__( 'The web assistant is not open on this site yet.', 'bizcity-twin-ai' ) . '</strong><br>'
			. esc_html( $admin
				? __( 'This page runs on the BizCity TwinWeb add-on (bizcity-twinweb-addon), which is not active here. Install and activate it to bring the /gpt/ assistant back; everything else keeps working.', 'bizcity-twin-ai' )
				: __( 'You can keep chatting through Zalo, or go back to the home page.', 'bizcity-twin-ai' ) )
			. ' <a href="' . esc_url( home_url( '/' ) ) . '">' . esc_html__( 'Home', 'bizcity-twin-ai' ) . '</a></div>';
	} );
}, 99 );

// [2026-07-15 Johnny Chu] PHASE-TWIN-GPT-CP TG-0 — canonical Twin GPT brand; keep technical code twinweb/TWINWEB.
// [2026-10-12 11:47 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.102 ST102-L22/U3 — the Twin GPT platform (its tabs configure /gpt/) is listed only when the /gpt/ part is available — by file presence.
add_filter( 'bizcity_channel_platform_catalog', function ( array $catalog ) {
	if ( function_exists( 'bizcity_twinweb_available' ) && ! bizcity_twinweb_available() ) {
		return $catalog;
	}
	foreach ( $catalog as $item ) {
		if ( isset( $item['code'] ) && (string) $item['code'] === 'twinweb' ) {
			return $catalog;
		}
	}

	$catalog[] = array(
		'code'     => 'twinweb',
		'label'    => 'Twin GPT',
		'platform' => 'TWINWEB',
		'icon'     => 'globe',
		'group'    => 'admin',
		'zone'     => 'admin',
		'ready'    => true,
		'desc'     => 'Twin GPT - khong gian AI public cho user/guest lam viec voi Guru va tai lieu.',
	);

	return $catalog;
}, 20 );
