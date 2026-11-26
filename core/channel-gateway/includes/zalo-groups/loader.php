<?php
/**
 * Zalo groups of the cell (moved from core/crm). Required by core/crm/bootstrap.php (after the installer); nothing without the spine.
 *
 * // [2026-11-21 01:32 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.156 W6 (D) — same load order and init calls as the old block in core/crm/bootstrap.php.
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'BizCity_CRM_DB_Installer_V2', false ) ) {
	return; // the CRM spine is not loaded on this request
}

$bizcity_zalo_groups_dir = __DIR__ . '/';

// [2026-10-17 11:55 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.120 S120-L1 — danh bạ Zalo (cell) → CRM contacts + trang trace gửi thay.
if ( ! class_exists( 'BizCity_CRM_Zalo_Directory', false ) && is_readable( $bizcity_zalo_groups_dir . 'class-crm-zalo-directory.php' ) ) {
	require_once $bizcity_zalo_groups_dir . 'class-crm-zalo-directory.php';
	BizCity_CRM_Zalo_Directory::init();
}
// [2026-10-19 09:40 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.124 W1 — thư ký nhóm Zalo: cài đặt nhóm một chỗ + bậc quyền + group-transcript@1.
if ( ! class_exists( 'BizCity_CRM_Group_Secretary', false ) && is_readable( $bizcity_zalo_groups_dir . 'class-crm-group-secretary.php' ) ) {
	require_once $bizcity_zalo_groups_dir . 'class-crm-group-secretary.php';
	BizCity_CRM_Group_Secretary::init(); // W2: tự lưu tệp nhóm đang theo dõi (hook bizcity_crm_message_persisted)
	// [2026-10-20 06:01 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.124 G0 — gói group_index cho cell
	if ( is_readable( $bizcity_zalo_groups_dir . 'class-crm-group-index-pack.php' ) ) {
		require_once $bizcity_zalo_groups_dir . 'class-crm-group-index-pack.php';
		BizCity_CRM_Group_Index_Pack::register();
	}
	// [2026-10-19 11:22 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.124 B13 — tab Sổ nhóm (bizcity-crm/v1/conversations/{id}/group-notebook)
	if ( is_readable( $bizcity_zalo_groups_dir . 'class-crm-group-notebook-rest.php' ) ) {
		require_once $bizcity_zalo_groups_dir . 'class-crm-group-notebook-rest.php';
		BizCity_CRM_Group_Notebook_REST::init();
	}
}
unset( $bizcity_zalo_groups_dir );
