<?php
/**
 * Brain Profile bootstrap (PHASE-0.100 S100-L3/L5/L6) — loads the profile store/service/installer, the REST
 * namespace `bizcity/v1/brain-profiles` and the `profile.*`/`program.*` MCP tools. Admin/REST/cron context only
 * (same load profile as core/skills and core/mcp — R-PERF): no DB, no option and no filesystem work at file
 * scope, the uploads directory is created on first use (BizCity_Brain_Profile_Store).
 *
 * Wired from bizcity-twin-ai.php next to core/skills/bootstrap.php.
 *
 * Biz Central Brain — Johnny Chu (Chu Hoàng Anh).
 *
 * // [2026-10-12 09:48 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.100 S100-L3 — new bootstrap.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Channel_Gateway\Profile
 */

defined( 'ABSPATH' ) || exit;

$_bizcity_brain_profile_files = array(
	'class-brain-profile-store.php'     => 'brain_profile.store',
	'class-brain-profile-service.php'   => 'brain_profile.service',
	'class-brain-profile-installer.php' => 'brain_profile.installer',
	'class-brain-profile-rest.php'      => 'brain_profile.rest',
	'class-brain-profile-mcp-tools.php' => 'brain_profile.mcp_tools',
);
foreach ( $_bizcity_brain_profile_files as $_bizcity_brain_profile_rel => $_bizcity_brain_profile_label ) {
	$_bizcity_brain_profile_path = __DIR__ . '/' . $_bizcity_brain_profile_rel;
	if ( class_exists( 'BizCity_Safe_Loader', false ) ) {
		BizCity_Safe_Loader::require_file( $_bizcity_brain_profile_path, $_bizcity_brain_profile_label );
	} elseif ( is_file( $_bizcity_brain_profile_path ) && is_readable( $_bizcity_brain_profile_path ) ) {
		require_once $_bizcity_brain_profile_path;
	}
}
unset( $_bizcity_brain_profile_files, $_bizcity_brain_profile_rel, $_bizcity_brain_profile_label, $_bizcity_brain_profile_path );

if ( class_exists( 'BizCity_Brain_Profile_REST', false ) ) {
	BizCity_Brain_Profile_REST::init();
}
if ( class_exists( 'BizCity_Brain_Profile_MCP_Tools', false ) ) {
	BizCity_Brain_Profile_MCP_Tools::init();
}
