<?php
/**
 * crm-ledger@1 — the DDL of the conversation ledger, ONE copy for two plugins.
 *
 * [2026-11-19 09:37 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.156 S156-2.1 (doc 00 §4) — the seven ledger tables Zalo Brain (core/crm) and the CRM
 * plugin both need: contacts · contact_inboxes · inboxes · conversations · messages · attachments · inbox_members. The source is this file in
 * core/crm/contracts/; the CRM plugin carries a byte-identical copy in contracts/ (node bin/validate-crm-ledger-contract.mjs, --sync copies).
 * Whoever loads first declares the class; the other copy is skipped by the class_exists() guard, so the DDL is the same either way.
 *
 * The statements are the FINAL schema: the installer CREATE plus every additive migration of these tables (core migrate_phase_035 / 039 / 040 /
 * 044 / 053 / 054 / 056 / 060b / 095) folded in with the same definitions. On a site already migrated dbDelta finds nothing to add; on a new
 * site (with or without Zalo Brain) the tables are created complete. Never DROP / MODIFY here (R-DCL: hand-written migration only).
 * A change = bump VERSION + a row in core/helper/schema/changelog/core.crm.json + run the validator with --sync.
 *
 * @package BizCity_Twin_AI
 * @subpackage Core\CRM
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'BizCity_CRM_Ledger_Schema', false ) ) :

final class BizCity_CRM_Ledger_Schema {

	const CONTRACT = 'crm-ledger@1';
	const VERSION  = '1.0.0';

	/** Table suffixes after `{$prefix}bizcity_crm_`, in creation order. */
	public static function tables(): array {
		return array( 'inboxes', 'contacts', 'contact_inboxes', 'conversations', 'messages', 'attachments', 'inbox_members' );
	}

	/**
	 * dbDelta statements for the seven ledger tables.
	 *
	 * @param string $prefix  The blog table prefix ($wpdb->prefix, computed after switch_to_blog — R-MSDB).
	 * @param string $charset $wpdb->get_charset_collate().
	 * @return string[] suffix => CREATE TABLE statement.
	 */
	public static function ddl( string $prefix, string $charset ): array {
		$t = static function ( string $suffix ) use ( $prefix ): string {
			return $prefix . 'bizcity_crm_' . $suffix;
		};
		$sql = array();

		$sql['inboxes'] = "CREATE TABLE `{$t( 'inboxes' )}` (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(190) NOT NULL DEFAULT '',
			channel_type VARCHAR(32) NOT NULL,
			channel_ref_id VARCHAR(190) NOT NULL,
			default_notebook_id BIGINT UNSIGNED NULL,
			default_assignee_id BIGINT UNSIGNED NULL,
			settings_json LONGTEXT NULL,
			is_active TINYINT(1) NOT NULL DEFAULT 1,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uniq_channel_ref (channel_type, channel_ref_id),
			KEY idx_active (is_active)
		) {$charset};";

		$sql['contacts'] = "CREATE TABLE `{$t( 'contacts' )}` (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(190) NOT NULL DEFAULT '',
			first_name VARCHAR(95) NULL,
			last_name VARCHAR(95) NULL,
			title VARCHAR(120) NULL,
			account_id BIGINT UNSIGNED NULL,
			email VARCHAR(190) NULL,
			phone VARCHAR(32) NULL,
			birthday DATE NULL,
			birthday_md CHAR(5) NULL,
			avatar_url TEXT NULL,
			additional_attributes LONGTEXT NULL,
			wp_user_id BIGINT UNSIGNED NULL,
			owner_id BIGINT UNSIGNED NULL,
			tags_json LONGTEXT NULL,
			acquisition_source VARCHAR(64) NULL,
			acquisition_meta_json LONGTEXT NULL,
			points_balance_cache INT NOT NULL DEFAULT 0,
			lead_score TINYINT UNSIGNED NOT NULL DEFAULT 0,
			lead_score_cell TINYINT UNSIGNED NULL DEFAULT NULL,
			segment VARCHAR(32) NOT NULL DEFAULT '',
			platform VARCHAR(32) NULL,
			platform_uid VARCHAR(190) NULL,
			source VARCHAR(64) NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			deleted_at DATETIME NULL,
			PRIMARY KEY  (id),
			KEY idx_email (email),
			KEY idx_phone (phone),
			KEY idx_birthday_md (birthday_md),
			KEY idx_wp_user (wp_user_id),
			KEY idx_account (account_id),
			KEY idx_owner (owner_id),
			KEY idx_acquisition (acquisition_source),
			KEY idx_deleted (deleted_at),
			KEY idx_platform_uid (platform, platform_uid)
		) {$charset};";

		$sql['contact_inboxes'] = "CREATE TABLE `{$t( 'contact_inboxes' )}` (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			contact_id BIGINT UNSIGNED NOT NULL,
			inbox_id BIGINT UNSIGNED NOT NULL,
			source_id VARCHAR(190) NOT NULL,
			last_seen_at DATETIME NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uniq_inbox_source (inbox_id, source_id),
			KEY idx_contact (contact_id)
		) {$charset};";

		$sql['conversations'] = "CREATE TABLE `{$t( 'conversations' )}` (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			inbox_id BIGINT UNSIGNED NOT NULL,
			contact_inbox_id BIGINT UNSIGNED NOT NULL,
			status VARCHAR(16) NOT NULL DEFAULT 'open',
			assignee_id BIGINT UNSIGNED NULL,
			notebook_id BIGINT UNSIGNED NULL,
			character_id BIGINT UNSIGNED NULL,
			priority TINYINT NOT NULL DEFAULT 0,
			snoozed_until BIGINT NULL,
			waiting_since BIGINT NULL,
			first_reply_at BIGINT NULL,
			cached_label_list TEXT NULL,
			sla_policy_id BIGINT UNSIGNED NULL,
			team_id BIGINT UNSIGNED NULL,
			last_message_id BIGINT UNSIGNED NULL,
			last_activity_at DATETIME NULL,
			unread_count INT UNSIGNED NOT NULL DEFAULT 0,
			platform VARCHAR(32) NULL,
			channel_thread_id VARCHAR(190) NULL,
			chat_id VARCHAR(190) NULL,
			contact_id BIGINT UNSIGNED NULL,
			account_id VARCHAR(190) NULL,
			blog_id BIGINT UNSIGNED NOT NULL DEFAULT 1,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY idx_inbox_status_act (inbox_id, status, last_activity_at),
			KEY idx_assignee_status (assignee_id, status),
			KEY idx_assignee_status_wait (assignee_id, status, waiting_since),
			KEY idx_contact_inbox (contact_inbox_id),
			KEY idx_priority_status (priority, status),
			KEY idx_waiting (waiting_since),
			KEY idx_snoozed (snoozed_until),
			KEY idx_platform_thread (platform, channel_thread_id),
			KEY idx_conv_contact (contact_id),
			KEY idx_blog (blog_id)
		) {$charset};";

		$sql['messages'] = "CREATE TABLE `{$t( 'messages' )}` (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			conversation_id BIGINT UNSIGNED NOT NULL,
			inbox_id BIGINT UNSIGNED NOT NULL,
			external_source_id VARCHAR(190) NULL,
			content LONGTEXT NULL,
			content_type VARCHAR(16) NOT NULL DEFAULT 'text',
			message_type VARCHAR(16) NOT NULL DEFAULT 'incoming',
			sender_type VARCHAR(16) NOT NULL DEFAULT 'contact',
			sender_id BIGINT UNSIGNED NULL,
			status VARCHAR(16) NOT NULL DEFAULT 'sent',
			ai_metadata_json LONGTEXT NULL,
			event_uuid CHAR(36) NULL,
			responder_kind VARCHAR(10) NULL,
			responder_user_id BIGINT UNSIGNED NULL,
			character_id BIGINT UNSIGNED NULL,
			macro_id BIGINT UNSIGNED NULL,
			automation_rule_id BIGINT UNSIGNED NULL,
			platform VARCHAR(32) NULL,
			platform_msg_id VARCHAR(190) NULL,
			body LONGTEXT NULL,
			payload_json LONGTEXT NULL,
			content_storage_state VARCHAR(20) NOT NULL DEFAULT 'hot',
			content_preview VARCHAR(255) NULL,
			archive_channel VARCHAR(32) NULL,
			archive_account_key VARCHAR(128) NULL,
			archive_peer_key VARCHAR(128) NULL,
			archive_month CHAR(7) NULL,
			archive_receipt_hash CHAR(64) NULL,
			archived_at DATETIME NULL,
			offloaded_at DATETIME NULL,
			storage_error_code VARCHAR(64) NULL,
			storage_attempts INT UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uniq_inbox_external (inbox_id, external_source_id),
			KEY idx_conv_created (conversation_id, created_at),
			KEY idx_conv_id (conversation_id, id),
			KEY idx_sender (sender_type, message_type),
			KEY idx_event_uuid (event_uuid),
			KEY idx_responder_user (responder_user_id),
			KEY idx_responder_kind (responder_kind),
			KEY idx_rule (automation_rule_id),
			KEY idx_macro (macro_id),
			KEY idx_storage_state (content_storage_state, created_at, id),
			KEY idx_storage_conversation (conversation_id, content_storage_state, created_at),
			KEY idx_archive_receipt (archive_receipt_hash)
		) {$charset};";

		$sql['attachments'] = "CREATE TABLE `{$t( 'attachments' )}` (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			message_id BIGINT UNSIGNED NOT NULL,
			file_type VARCHAR(16) NOT NULL DEFAULT 'file',
			data_url TEXT NULL,
			thumb_url TEXT NULL,
			meta_json LONGTEXT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY idx_message (message_id)
		) {$charset};";

		$sql['inbox_members'] = "CREATE TABLE `{$t( 'inbox_members' )}` (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			inbox_id BIGINT UNSIGNED NOT NULL,
			user_id BIGINT UNSIGNED NOT NULL,
			member_role VARCHAR(20) NOT NULL DEFAULT 'agent',
			can_assign TINYINT(1) NOT NULL DEFAULT 0,
			is_active TINYINT(1) NOT NULL DEFAULT 1,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uniq_inbox_user (inbox_id, user_id),
			KEY idx_user_inbox (user_id, inbox_id, is_active)
		) {$charset};";

		return $sql;
	}
}

endif;
