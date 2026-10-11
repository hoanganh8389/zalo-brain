<?php
/**
 * BizCity Skills — the site's SKILL.md library (PHASE-0.101 S101-L6, D101-16 v2).
 *
 * Revived from core/_archived/skills (WP-12 R3) in its lean form: ONLY the file library (Markdown skills per blog under
 * uploads/), the REST file routes the editor needs, the wp-admin editor SPA (assets/dist, source app/), the
 * `BizCity_Skills_Service` reader/writer, five MCP tools `skills.*` and the projection pack `skills`.
 *
 * What stays archived and is NEVER loaded again (R-BCB-1/2, R-VERTICAL-AXIS): the PHP prompt router (skill context
 * injection), the slash matcher, the pipeline bridge, the tool map, the SQL skill registry and the journal — a skill
 * is client knowledge the brain READS through the pack / `skills.get`; the site never builds a prompt from it.
 *
 * Load profile (R-PERF): admin/REST/cron context only (wired in bizcity-twin-ai.php next to core/mcp). No DB, no
 * option and no filesystem work at file scope; the library directory is created on first use.
 *
 * Biz Central Brain — Johnny Chu (Chu Hoàng Anh). Bizcity Central Brain, Giấy chứng nhận đăng ký quyền tác giả
 * số 8877/2026/QTG (Cục Bản quyền tác giả, 14/09/2026).
 *
 * // [2026-10-11 Johnny Chu - Chu Hoàng Anh] PHASE-0.101 S101-L6 — new bootstrap (lean revival of core/skills).
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Skills
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'BIZCITY_SKILLS_DIR' ) ) {
	define( 'BIZCITY_SKILLS_DIR', __DIR__ . '/' );
}
if ( ! defined( 'BIZCITY_SKILLS_VERSION' ) ) {
	define( 'BIZCITY_SKILLS_VERSION', '2.0.0' );
}

// [2026-10-11 Johnny Chu - Chu Hoàng Anh] PHASE-0.101 S101-L6 — R-SAFE-LOADER: every artifact through the safe loader, missing ⇒ degrade.
$_bizcity_skills_files = array(
	'includes/class-skill-manager.php'        => 'skills.manager',
	'includes/class-skills-service.php'       => 'skills.service',
	'includes/class-skill-rest-api.php'       => 'skills.rest',
	'includes/class-skills-mcp-tools.php'     => 'skills.mcp_tools',
	'includes/class-skills-pack-exporter.php' => 'skills.pack_exporter',
	'includes/class-admin-page.php'           => 'skills.admin_page',
);
foreach ( $_bizcity_skills_files as $_bizcity_skills_rel => $_bizcity_skills_label ) {
	$_bizcity_skills_path = BIZCITY_SKILLS_DIR . $_bizcity_skills_rel;
	if ( class_exists( 'BizCity_Safe_Loader', false ) ) {
		BizCity_Safe_Loader::require_file( $_bizcity_skills_path, $_bizcity_skills_label );
	} elseif ( is_file( $_bizcity_skills_path ) && is_readable( $_bizcity_skills_path ) ) {
		require_once $_bizcity_skills_path;
	}
}
unset( $_bizcity_skills_files, $_bizcity_skills_rel, $_bizcity_skills_label, $_bizcity_skills_path );

if ( class_exists( 'BizCity_Skill_REST_API', false ) ) {
	BizCity_Skill_REST_API::init();
}
if ( class_exists( 'BizCity_Skills_MCP_Tools', false ) ) {
	BizCity_Skills_MCP_Tools::init();
}
if ( class_exists( 'BizCity_Skills_Pack_Exporter', false ) ) {
	BizCity_Skills_Pack_Exporter::init();
}
if ( is_admin() && class_exists( 'BizCity_Skill_Admin_Page', false ) ) {
	BizCity_Skill_Admin_Page::init();
}
