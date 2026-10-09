<?php
/**
 * Zalo Brain — CRM spine DB installer (crm-spine@1): the 15 tables core/crm owns.
 *
 * [2026-10-09 Johnny Chu - Chu Hoàng Anh] PHASE-0.96 S96-2.2 — split from plugins/bizcity-twin-crm/includes/class-db-installer.php.
 * Core owns: inboxes, contacts, contact_inboxes, conversations, messages, attachments, labels, conversation_labels,
 * teams, team_members, inbox_members, archive_receipts, chat_magic_links, admin_chat_grants (+ gmail_smtp_accounts for
 * the Email channel). The extension plugin (Zalo Brain CRM) appends its own CREATE statements through the
 * `bizcity_crm_install_tables` filter and runs its migrations on `bizcity_crm_install_migrate`, so there is still
 * exactly one dbDelta pass. Every tbl_*() accessor stays here on purpose: callers compile whether or not the plugin
 * is present (D96-5). DB version option keeps its name so running sites do not reinstall.
 *
 * @package BizCity_Twin_AI
 * @subpackage Core\CRM
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'BizCity_CRM_DB_Installer_V2' ) ) :

class BizCity_CRM_DB_Installer_V2 {

	const DB_VERSION_OPTION = 'bizcity_crm_db_ver';

	/* ----- Table-name helpers ----- */
	public static function tbl_inboxes(): string         { global $wpdb; return $wpdb->prefix . 'bizcity_crm_inboxes'; }
	public static function tbl_contacts(): string        { global $wpdb; return $wpdb->prefix . 'bizcity_crm_contacts'; }
	public static function tbl_contact_inboxes(): string { global $wpdb; return $wpdb->prefix . 'bizcity_crm_contact_inboxes'; }
	public static function tbl_conversations(): string   { global $wpdb; return $wpdb->prefix . 'bizcity_crm_conversations'; }
	public static function tbl_messages(): string        { global $wpdb; return $wpdb->prefix . 'bizcity_crm_messages'; }
	public static function tbl_attachments(): string     { global $wpdb; return $wpdb->prefix . 'bizcity_crm_attachments'; }
	public static function tbl_automation_rules(): string { global $wpdb; return $wpdb->prefix . 'bizcity_crm_automation_rules'; }
	public static function tbl_labels(): string           { global $wpdb; return $wpdb->prefix . 'bizcity_crm_labels'; }
	public static function tbl_conversation_labels(): string { global $wpdb; return $wpdb->prefix . 'bizcity_crm_conversation_labels'; }
	public static function tbl_custom_attribute_definitions(): string { global $wpdb; return $wpdb->prefix . 'bizcity_crm_custom_attribute_definitions'; }
	public static function tbl_macros(): string           { global $wpdb; return $wpdb->prefix . 'bizcity_crm_macros'; }
	public static function tbl_working_hours(): string    { global $wpdb; return $wpdb->prefix . 'bizcity_crm_working_hours'; }
	public static function tbl_sla_policies(): string     { global $wpdb; return $wpdb->prefix . 'bizcity_crm_sla_policies'; }
	public static function tbl_applied_slas(): string     { global $wpdb; return $wpdb->prefix . 'bizcity_crm_applied_slas'; }

	/* ── PHASE 0.35 M-FE.W17 — CRM module tables ── */
	public static function tbl_accounts(): string        { global $wpdb; return $wpdb->prefix . 'bizcity_crm_accounts'; }
	public static function tbl_biz_contacts(): string    { global $wpdb; return $wpdb->prefix . 'bizcity_crm_biz_contacts'; }
	public static function tbl_crm_tasks(): string       { global $wpdb; return $wpdb->prefix . 'bizcity_crm_tasks'; }
	public static function tbl_crm_events(): string      { global $wpdb; return $wpdb->prefix . 'bizcity_crm_events'; }
	public static function tbl_crm_documents(): string   { global $wpdb; return $wpdb->prefix . 'bizcity_crm_documents'; }

	/* ── PHASE 0.35 M-CRM.M1 — Sales Pipeline tables ── */
	public static function tbl_crm_leads(): string            { global $wpdb; return $wpdb->prefix . 'bizcity_crm_leads'; }
	public static function tbl_crm_opportunities(): string    { global $wpdb; return $wpdb->prefix . 'bizcity_crm_opportunities'; }
	public static function tbl_crm_opportunity_lines(): string{ global $wpdb; return $wpdb->prefix . 'bizcity_crm_opportunity_lines'; }
	public static function tbl_crm_contracts(): string        { global $wpdb; return $wpdb->prefix . 'bizcity_crm_contracts'; }
	public static function tbl_crm_contract_lines(): string   { global $wpdb; return $wpdb->prefix . 'bizcity_crm_contract_lines'; }

	/* ── PHASE 0.35 M-CRM.M1.W2 — Product catalog tables ── */
	public static function tbl_crm_product_categories(): string { global $wpdb; return $wpdb->prefix . 'bizcity_crm_product_categories'; }
	public static function tbl_crm_products(): string           { global $wpdb; return $wpdb->prefix . 'bizcity_crm_products'; }

	/* ── PHASE 0.35 M6.W1 — Campaigns + Visits ── */
	public static function tbl_campaigns(): string        { global $wpdb; return $wpdb->prefix . 'bizcity_crm_campaigns'; }
	public static function tbl_campaign_visits(): string  { global $wpdb; return $wpdb->prefix . 'bizcity_crm_campaign_visits'; }

	/* ── PHASE 0.35 M-CRM.M2 — Invoicing tables ── */
	public static function tbl_crm_invoices(): string         { global $wpdb; return $wpdb->prefix . 'bizcity_crm_invoices'; }
	public static function tbl_crm_invoice_lines(): string    { global $wpdb; return $wpdb->prefix . 'bizcity_crm_invoice_lines'; }
	public static function tbl_crm_invoice_payments(): string { global $wpdb; return $wpdb->prefix . 'bizcity_crm_invoice_payments'; }

	/* ── PHASE 0.35 M-CRM.M3 — Email Client tables ── */
	public static function tbl_crm_email_accounts(): string { global $wpdb; return $wpdb->prefix . 'bizcity_crm_email_accounts'; }
	public static function tbl_crm_email_threads(): string  { global $wpdb; return $wpdb->prefix . 'bizcity_crm_email_threads'; }
	public static function tbl_crm_email_messages(): string { global $wpdb; return $wpdb->prefix . 'bizcity_crm_email_messages'; }

	/* ── PHASE 0.37.1 — Gmail SMTP + Email automation rules ── */
	public static function tbl_gmail_smtp_accounts(): string { global $wpdb; return $wpdb->prefix . 'bizcity_crm_gmail_smtp_accounts'; }
	public static function tbl_email_event_rules(): string   { global $wpdb; return $wpdb->prefix . 'bizcity_crm_email_event_rules'; }

	/* ── PHASE 0.35 M-CRM.M8.W2 — Contact unification (legacy biz_contacts → contacts) ── */
	public static function tbl_contact_id_map(): string     { global $wpdb; return $wpdb->prefix . 'bizcity_crm_contact_id_map'; }
	// [2026-08-11 Johnny Chu] PHASE-CRM-CONTACTS-UNIFY-V2 — durable identity conflict queue table helper.
	public static function tbl_identity_conflicts(): string { global $wpdb; return $wpdb->prefix . 'bizcity_crm_identity_conflicts'; }
	// [2026-08-12 Johnny Chu] PHASE-CRM-CONTACTS-UNIFY-V2 — append-only conflict audit table helper.
	public static function tbl_identity_conflict_audit(): string { global $wpdb; return $wpdb->prefix . 'bizcity_crm_identity_conflict_audit'; }

	/* ── PHASE 3.5 Wave A — Admin Chat magic links ── */
	public static function tbl_chat_magic_links(): string   { global $wpdb; return $wpdb->prefix . 'bizcity_crm_chat_magic_links'; }

	/* ── PHASE 3.5 Wave B — Admin Chat grants (3-axis delegation) ── */
	public static function tbl_admin_chat_grants(): string  { global $wpdb; return $wpdb->prefix . 'bizcity_crm_admin_chat_grants'; }

	/* ── PHASE 0.35 M-CRM.M1.W3 — Audit log ── */
	public static function tbl_crm_audit_log(): string { global $wpdb; return $wpdb->prefix . 'bizcity_crm_audit_log'; }

	/* ── M-CRM.M4.Inbox v1.18.0 — Broadcasts ── */
	public static function tbl_broadcasts(): string { global $wpdb; return $wpdb->prefix . 'bizcity_crm_broadcasts'; }
	public static function tbl_broadcast_recipients(): string { global $wpdb; return $wpdb->prefix . 'bizcity_crm_broadcast_recipients'; }

	/* ── PHASE 0.38 v1.20.0 — Order Fulfillment Hub tables ── */
	// [2026-06-07 Johnny Chu] PHASE-0.38.W1.3 — declare 3 new recap/csat/shipment tables
	public static function tbl_order_recap_log(): string      { global $wpdb; return $wpdb->prefix . 'bizcity_crm_order_recap_log'; }
	public static function tbl_order_csat(): string           { global $wpdb; return $wpdb->prefix . 'bizcity_crm_order_csat'; }
	public static function tbl_shipment_status_log(): string  { global $wpdb; return $wpdb->prefix . 'bizcity_crm_shipment_status_log'; }

	/* ── PHASE 0.40 v1.21.0 — Deplao Parity: notes_doc ── */
	// [2026-06-07 Johnny Chu] PHASE-0.40.G1 — internal doc notes table
	public static function tbl_notes_doc(): string { global $wpdb; return $wpdb->prefix . 'bizcity_crm_notes_doc'; }

	/* ── PHASE 3.5 v1.22.0 — Wave C: admin_chat_audit ── */
	// [2026-06-07 Johnny Chu] PHASE-3.5-WC — admin chat audit log table
	public static function tbl_admin_chat_audit(): string { global $wpdb; return $wpdb->prefix . 'bizcity_crm_admin_chat_audit'; }

	/* ── PHASE-0.46 M1 — unified submissions pipeline ── */
	// [2026-07-05 Johnny Chu] PHASE-0.46 M1 — unified submissions table helper
	public static function tbl_crm_submissions(): string  { global $wpdb; return $wpdb->prefix . 'bizcity_crm_submissions'; }
	// [2026-07-05 Johnny Chu] PHASE-0.46 M1 — activities table helper (entity_type + entity_id based)
	public static function tbl_crm_activities(): string   { global $wpdb; return $wpdb->prefix . 'bizcity_crm_activities'; }
	public static function tbl_archive_receipts(): string { global $wpdb; return $wpdb->prefix . 'bizcity_crm_archive_receipts'; }
	public static function tbl_reporting_events(): string { global $wpdb; return $wpdb->prefix . 'bizcity_crm_reporting_events'; }
	public static function tbl_reporting_rollups(): string { global $wpdb; return $wpdb->prefix . 'bizcity_crm_reporting_event_rollups'; }
	public static function tbl_teams(): string { global $wpdb; return $wpdb->prefix . 'bizcity_crm_teams'; }
	public static function tbl_team_members(): string { global $wpdb; return $wpdb->prefix . 'bizcity_crm_team_members'; }
	public static function tbl_inbox_members(): string { global $wpdb; return $wpdb->prefix . 'bizcity_crm_inbox_members'; }
	public static function tbl_assignment_policies(): string { global $wpdb; return $wpdb->prefix . 'bizcity_crm_assignment_policies'; }
	public static function tbl_inbox_assignment_policies(): string { global $wpdb; return $wpdb->prefix . 'bizcity_crm_inbox_assignment_policies'; }
	// [2026-09-21 PHASE-0.63A WP-0.3] The single new table of the whole pipeline platform — the SLA deadline queue.
	public static function tbl_pipeline_deadlines(): string { global $wpdb; return $wpdb->prefix . 'bizcity_crm_pipeline_deadlines'; }

	/**
	 * Spine tables only (14 + gmail_smtp_accounts). Extension tables are listed by BizCity_CRM_DB_Installer_Ext.
	 * [2026-10-04] 'crm_events' is owned by core/scheduler, not created here.
	 */
	public static function all_tables(): array {
		return array(
			'inboxes'             => self::tbl_inboxes(),
			'contacts'            => self::tbl_contacts(),
			'contact_inboxes'     => self::tbl_contact_inboxes(),
			'conversations'       => self::tbl_conversations(),
			'messages'            => self::tbl_messages(),
			'attachments'         => self::tbl_attachments(),
			'labels'              => self::tbl_labels(),
			'conversation_labels' => self::tbl_conversation_labels(),
			'crm_teams'           => self::tbl_teams(),
			'crm_team_members'    => self::tbl_team_members(),
			'crm_inbox_members'   => self::tbl_inbox_members(),
			'archive_receipts'    => self::tbl_archive_receipts(),
			'chat_magic_links'    => self::tbl_chat_magic_links(),
			'admin_chat_grants'   => self::tbl_admin_chat_grants(),
			'gmail_smtp_accounts' => self::tbl_gmail_smtp_accounts(),
		);
	}

	public static function maybe_upgrade(): void {
		// [2026-08-21 Johnny Chu] DIAGNOSTICS-SCHEMA-SELF-HEAL — repair when the version stamp survived but one or more CRM tables are absent.
		$diagnostics_context = defined( 'BIZCITY_DIAGNOSTICS_CLI' ) && BIZCITY_DIAGNOSTICS_CLI;
		if ( ! $diagnostics_context && get_option( self::DB_VERSION_OPTION ) === BIZCITY_CRM_DB_VERSION && empty( self::missing_tables() ) ) {
			return;
		}
		self::install();
		// [2026-08-26 Johnny Chu] R-DCL — reconcile CRM additive column/index drift from the canonical changelog during headless Diagnostics only.
		if ( $diagnostics_context
			&& class_exists( 'BizCity_Diagnostics_Auto_Create' )
			&& class_exists( 'BizCity_Diagnostics_Changelog_Loader' ) ) {
			foreach ( BizCity_Diagnostics_Changelog_Loader::tables() as $suffix => $definition ) {
				if ( (string) ( $definition['module_id'] ?? '' ) !== 'modules.twin-crm' ) {
					continue;
				}
				BizCity_Diagnostics_Auto_Create::run( (string) $suffix );
			}
		}
		unset( $diagnostics_context );
	}

	/**
	 * Lightweight existence check used to guard cron/REST against subsites
	 * where CRM schema hasn't been installed yet (R-CRM safety).
	 *
	 * [2026-07-05 Johnny Chu] R-SHOW-TABLES — replaced SHOW TABLES LIKE with
	 * information_schema SELECT + dual-cache (static + wp_cache 1h) per rule.
	 */
	public static function table_exists( string $table ): bool {
		// [2026-08-21 Johnny Chu] R-METADATA-CACHE — use the shared generation-aware helper so CRM DDL invalidates in-request false results too.
		if ( function_exists( 'bizcity_tbl_exists' ) ) {
			return (bool) bizcity_tbl_exists( $table );
		}
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare(
			'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s LIMIT 1',
			$table
		) );
	}

	/**
	 * Flush wp_cache entries for all known CRM tables.
	 * Call after dbDelta installs/creates a table.
	 *
	 * [2026-07-05 Johnny Chu] R-SHOW-TABLES — cache invalidation helper.
	 */
	public static function invalidate_tables_cache(): void {
		$blog_id = (int) get_current_blog_id();
		foreach ( self::all_tables() as $tbl ) {
			wp_cache_delete( 'bz_tbl_' . $blog_id . '_' . crc32( $tbl ), 'bizcity_tbl' );
			if ( function_exists( 'bizcity_tbl_invalidate' ) ) {
				bizcity_tbl_invalidate( $tbl );
			}
		}
	}

	/**
	 * Helper — check column existence (case-insensitive).
	 */
	public static function column_exists( string $table, string $column ): bool {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SHOW COLUMNS FROM `{$table}` LIKE %s", $column ) );
		return (bool) $row;
	}

	/**
	 * Helper — check index existence on a table.
	 */
	public static function index_exists( string $table, string $index ): bool {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SHOW INDEX FROM `{$table}` WHERE Key_name = %s", $index ) );
		return (bool) $row;
	}

	/**
	 * Diagnostic helper — return missing tables (empty array == all good).
	 *
	 * [2026-07-05 Johnny Chu] R-SHOW-TABLES — reuse table_exists() (information_schema + dual-cache).
	 */
	public static function missing_tables(): array {
		$missing = array();
		foreach ( self::all_tables() as $key => $tbl ) {
			if ( ! self::table_exists( $tbl ) ) {
				$missing[ $key ] = $tbl;
			}
		}
		return $missing;
	}

	/**
	 * One dbDelta pass for core + extension DDL (D96-5), then the spine migrations, then the extension migrations.
	 */
	public static function install(): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		global $wpdb;
		$charset = $wpdb->get_charset_collate();

		$inboxes = self::tbl_inboxes();
		$contacts = self::tbl_contacts();
		$contact_inboxes = self::tbl_contact_inboxes();
		$conversations = self::tbl_conversations();
		$messages = self::tbl_messages();
		$attachments = self::tbl_attachments();
		$labels = self::tbl_labels();
		$cl = self::tbl_conversation_labels();
		$chat_magic_links = self::tbl_chat_magic_links();
		$admin_chat_grants = self::tbl_admin_chat_grants();
		$gmail_smtp = self::tbl_gmail_smtp_accounts();

		$sql = array();
		$sql[] = "CREATE TABLE `{$inboxes}` (
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

		$sql[] = "CREATE TABLE `{$contacts}` (
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

		$sql[] = "CREATE TABLE `{$contact_inboxes}` (
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

		$sql[] = "CREATE TABLE `{$conversations}` (
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
			KEY idx_contact_inbox (contact_inbox_id),
			KEY idx_priority_status (priority, status),
			KEY idx_waiting (waiting_since),
			KEY idx_snoozed (snoozed_until),
			KEY idx_platform_thread (platform, channel_thread_id),
			KEY idx_conv_contact (contact_id),
			KEY idx_blog (blog_id)
		) {$charset};";

		$sql[] = "CREATE TABLE `{$messages}` (
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
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uniq_inbox_external (inbox_id, external_source_id),
			KEY idx_conv_created (conversation_id, created_at),
			KEY idx_sender (sender_type, message_type),
			KEY idx_event_uuid (event_uuid),
			KEY idx_responder_user (responder_user_id),
			KEY idx_responder_kind (responder_kind),
			KEY idx_rule (automation_rule_id),
			KEY idx_macro (macro_id)
		) {$charset};";

		$sql[] = "CREATE TABLE `{$attachments}` (
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

		$sql[] = "CREATE TABLE `{$labels}` (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			title VARCHAR(190) NOT NULL,
			description TEXT NULL,
			color VARCHAR(16) NOT NULL DEFAULT '#1f93ff',
			show_on_sidebar TINYINT(1) NOT NULL DEFAULT 1,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uniq_title (title)
		) {$charset};";

		$sql[] = "CREATE TABLE `{$cl}` (
			conversation_id BIGINT UNSIGNED NOT NULL,
			label_id BIGINT UNSIGNED NOT NULL,
			assigned_by BIGINT UNSIGNED NULL,
			assigned_at DATETIME NOT NULL,
			PRIMARY KEY  (conversation_id, label_id),
			KEY idx_label (label_id)
		) {$charset};";

		$sql[] = "CREATE TABLE `{$chat_magic_links}` (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			token_hash CHAR(64) NOT NULL,
			platform VARCHAR(32) NOT NULL,
			chat_id VARCHAR(190) NOT NULL,
			bot_id VARCHAR(64) NULL,
			blog_id BIGINT UNSIGNED NOT NULL,
			intent VARCHAR(32) NOT NULL DEFAULT 'login',
			character_id BIGINT UNSIGNED NULL,
			user_id BIGINT UNSIGNED NULL,
			issued_ip VARCHAR(64) NULL,
			consumed_at DATETIME NULL,
			consumed_ip VARCHAR(64) NULL,
			consumed_ua VARCHAR(255) NULL,
			expires_at DATETIME NOT NULL,
			meta_json LONGTEXT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uniq_token (token_hash),
			KEY idx_chat_lookup (platform, chat_id, expires_at),
			KEY idx_blog_consumed (blog_id, consumed_at)
		) {$charset};";

		$sql[] = "CREATE TABLE `{$admin_chat_grants}` (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL,
			character_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			channel_binding_id BIGINT UNSIGNED NULL,
			blog_id BIGINT UNSIGNED NOT NULL,
			platform VARCHAR(32) NOT NULL DEFAULT '',
			chat_id VARCHAR(190) NOT NULL DEFAULT '',
			status VARCHAR(16) NOT NULL DEFAULT 'active',
			allow_producer TINYINT(1) NOT NULL DEFAULT 1,
			allow_retriever TINYINT(1) NOT NULL DEFAULT 0,
			allow_distributor TINYINT(1) NOT NULL DEFAULT 0,
			tool_overrides_json LONGTEXT NULL,
			inbox_notebook_id BIGINT UNSIGNED NULL,
			quota_per_day INT NOT NULL DEFAULT 50,
			quota_used_today INT NOT NULL DEFAULT 0,
			quota_reset_at DATETIME NULL,
			granted_by_user_id BIGINT UNSIGNED NULL,
			granted_at DATETIME NOT NULL,
			revoked_at DATETIME NULL,
			revoked_by BIGINT UNSIGNED NULL,
			expires_at DATETIME NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uniq_user_guru_binding (user_id, character_id, channel_binding_id),
			KEY idx_chat (platform, chat_id, status),
			KEY idx_status (status),
			KEY idx_blog (blog_id, status)
		) {$charset};";

		$sql[] = "CREATE TABLE `{$gmail_smtp}` (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			label VARCHAR(190) NOT NULL DEFAULT '',
			from_email VARCHAR(190) NOT NULL DEFAULT '',
			from_name VARCHAR(190) NOT NULL DEFAULT '',
			smtp_host VARCHAR(190) NOT NULL DEFAULT 'smtp.gmail.com',
			smtp_port SMALLINT UNSIGNED NOT NULL DEFAULT 587,
			smtp_secure VARCHAR(8) NOT NULL DEFAULT 'tls',
			smtp_user VARCHAR(190) NOT NULL DEFAULT '',
			smtp_pass_enc TEXT NULL,
			is_default TINYINT(1) NOT NULL DEFAULT 0,
			is_active TINYINT(1) NOT NULL DEFAULT 1,
			last_test_at DATETIME NULL,
			last_test_ok TINYINT(1) NULL,
			last_test_msg TEXT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			deleted_at DATETIME NULL,
			PRIMARY KEY  (id),
			KEY idx_default (is_default, is_active),
			KEY idx_user (smtp_user)
		) {$charset};";

		/**
		 * Extension plugins append their CREATE TABLE statements (same dbDelta format).
		 *
		 * @param string[] $sql     CREATE TABLE statements.
		 * @param string   $charset Charset/collation clause.
		 */
		$sql = (array) apply_filters( 'bizcity_crm_install_tables', $sql, $charset );

		foreach ( $sql as $stmt ) {
			dbDelta( $stmt );
		}

		// Spine migrations (idempotent; order matters only for AFTER clauses).
		self::migrate_phase_035();
		self::migrate_phase_039();
		self::migrate_phase_040();
		self::migrate_phase_044();
		self::migrate_phase_053();
		self::migrate_phase_054();
		self::migrate_phase_056();
		self::migrate_phase_057();
		self::migrate_phase_060b();

		/**
		 * Extension plugins run their own migrations after the spine is in place.
		 */
		do_action( 'bizcity_crm_install_migrate' );

		update_option( self::DB_VERSION_OPTION, BIZCITY_CRM_DB_VERSION );
		self::invalidate_tables_cache();
	}

	/**
	 * Idempotent column + index migration for PHASE 0.35 M1.W1.
	 *
	 * Safe to run repeatedly. Each ALTER is guarded by SHOW COLUMNS / SHOW INDEX
	 * so re-running on a fully migrated DB is a no-op.
	 *
	 * @return array<string,string> Map of operation => result ('skip'|'ok'|'fail:<msg>').
	 */
	public static function migrate_phase_035(): array {
		global $wpdb;
		$results = array();

		$add_column = static function ( string $table, string $column, string $definition ) use ( $wpdb, &$results ): void {
			$key = "col:{$table}.{$column}";
			if ( self::column_exists( $table, $column ) ) {
				$results[ $key ] = 'skip';
				return;
			}
			$ok = $wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN {$column} {$definition}" );
			$results[ $key ] = ( false === $ok ) ? ( 'fail:' . $wpdb->last_error ) : 'ok';
		};

		$add_index = static function ( string $table, string $index, string $definition ) use ( $wpdb, &$results ): void {
			$key = "idx:{$table}.{$index}";
			if ( self::index_exists( $table, $index ) ) {
				$results[ $key ] = 'skip';
				return;
			}
			$ok = $wpdb->query( "ALTER TABLE `{$table}` ADD KEY {$index} {$definition}" );
			$results[ $key ] = ( false === $ok ) ? ( 'fail:' . $wpdb->last_error ) : 'ok';
		};

		$conv = self::tbl_conversations();
		$add_column( $conv, 'snoozed_until',     'BIGINT NULL AFTER priority' );
		$add_column( $conv, 'waiting_since',     'BIGINT NULL AFTER snoozed_until' );
		$add_column( $conv, 'first_reply_at',    'BIGINT NULL AFTER waiting_since' );
		$add_column( $conv, 'cached_label_list', 'TEXT NULL AFTER first_reply_at' );
		$add_column( $conv, 'sla_policy_id',     'BIGINT UNSIGNED NULL AFTER cached_label_list' );
		$add_column( $conv, 'team_id',           'BIGINT UNSIGNED NULL AFTER sla_policy_id' );
		$add_index(  $conv, 'idx_priority_status', '(priority, status)' );
		$add_index(  $conv, 'idx_waiting',         '(waiting_since)' );
		$add_index(  $conv, 'idx_snoozed',         '(snoozed_until)' );

		$msg = self::tbl_messages();
		$add_column( $msg, 'macro_id',           'BIGINT UNSIGNED NULL AFTER character_id' );
		$add_column( $msg, 'automation_rule_id', 'BIGINT UNSIGNED NULL AFTER macro_id' );
		$add_index(  $msg, 'idx_rule',  '(automation_rule_id)' );
		$add_index(  $msg, 'idx_macro', '(macro_id)' );

		$ct = self::tbl_contacts();
		$add_column( $ct, 'acquisition_source',     "VARCHAR(64) NULL AFTER wp_user_id" );
		$add_column( $ct, 'acquisition_meta_json',  'LONGTEXT NULL AFTER acquisition_source' );
		$add_column( $ct, 'points_balance_cache',   'INT NOT NULL DEFAULT 0 AFTER acquisition_meta_json' );
		$add_index(  $ct, 'idx_acquisition', '(acquisition_source)' );

		return $results;
	}

	/** PHASE 0.35 M6.W9 (spine part) — conversations.character_id so the bridge can switch the active character. */
	public static function migrate_phase_039(): array {
		global $wpdb;
		$results = array();
		$add_column = static function ( string $table, string $column, string $definition ) use ( $wpdb ): void {
			if ( ! self::column_exists( $table, $column ) ) {
				$wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN {$column} {$definition}" );
			}
		};
		$add_index = static function ( string $table, string $index, string $definition ) use ( $wpdb ): void {
			if ( ! self::index_exists( $table, $index ) ) {
				$wpdb->query( "ALTER TABLE `{$table}` ADD KEY {$index} {$definition}" );
			}
		};
		$conv = self::tbl_conversations();
		$add_column( $conv, 'character_id', 'BIGINT UNSIGNED NULL AFTER notebook_id' );
		return $results;
	}

	/** PHASE 0.35 M-CRM.M8.W2 (spine part) — contact unification columns on contacts (canonical SoT). */
	public static function migrate_phase_040(): array {
		global $wpdb;
		$results = array();
		$add_column = static function ( string $table, string $column, string $definition ) use ( $wpdb ): void {
			if ( ! self::column_exists( $table, $column ) ) {
				$wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN {$column} {$definition}" );
			}
		};
		$add_index = static function ( string $table, string $index, string $definition ) use ( $wpdb ): void {
			if ( ! self::index_exists( $table, $index ) ) {
				$wpdb->query( "ALTER TABLE `{$table}` ADD KEY {$index} {$definition}" );
			}
		};
		$contacts = self::tbl_contacts();
		$add_column( $contacts, 'first_name', 'VARCHAR(95) NULL AFTER name' );
		$add_column( $contacts, 'last_name',  'VARCHAR(95) NULL AFTER first_name' );
		$add_column( $contacts, 'title',      'VARCHAR(120) NULL AFTER last_name' );
		$add_column( $contacts, 'account_id', 'BIGINT UNSIGNED NULL AFTER title' );
		$add_column( $contacts, 'owner_id',   'BIGINT UNSIGNED NULL AFTER wp_user_id' );
		$add_column( $contacts, 'tags_json',  'LONGTEXT NULL AFTER owner_id' );
		$add_column( $contacts, 'deleted_at', 'DATETIME NULL AFTER updated_at' );
		$add_index(  $contacts, 'idx_account', '(account_id)' );
		$add_index(  $contacts, 'idx_owner',   '(owner_id)' );
		$add_index(  $contacts, 'idx_deleted', '(deleted_at)' );
		return $results;
	}

	/** v1.18.0 (spine part) — lead_score + segment on contacts. */
	public static function migrate_phase_044(): void {
		global $wpdb;
		$contacts = self::tbl_contacts();
		if ( self::table_exists( $contacts ) ) {
			if ( ! self::column_exists( $contacts, 'lead_score' ) ) {
				$wpdb->query( "ALTER TABLE `{$contacts}` ADD COLUMN lead_score TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER points_balance_cache" );
			}
			if ( ! self::column_exists( $contacts, 'segment' ) ) {
				$wpdb->query( "ALTER TABLE `{$contacts}` ADD COLUMN segment VARCHAR(32) NOT NULL DEFAULT '' AFTER lead_score" );
			}
		}
	}

	/** PHASE-0.39F-F2-F5 (spine part) — message storage lifecycle columns, archive receipts, teams, team/inbox members. */
	public static function migrate_phase_053(): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		global $wpdb;
		$charset  = $wpdb->get_charset_collate();
		$messages = self::tbl_messages();
		$add_column = static function ( string $table, string $column, string $definition ) use ( $wpdb ): void {
			if ( ! self::column_exists( $table, $column ) ) {
				$wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN {$column} {$definition}" );
			}
		};
		$add_index = static function ( string $table, string $index, string $definition ) use ( $wpdb ): void {
			if ( ! self::index_exists( $table, $index ) ) {
				$wpdb->query( "ALTER TABLE `{$table}` ADD KEY {$index} {$definition}" );
			}
		};
		$add_column( $messages, 'content_storage_state', "VARCHAR(20) NOT NULL DEFAULT 'hot' AFTER payload_json" );
		$add_column( $messages, 'content_preview', 'VARCHAR(255) NULL AFTER content_storage_state' );
		$add_column( $messages, 'archive_channel', 'VARCHAR(32) NULL AFTER content_preview' );
		$add_column( $messages, 'archive_account_key', 'VARCHAR(128) NULL AFTER archive_channel' );
		$add_column( $messages, 'archive_peer_key', 'VARCHAR(128) NULL AFTER archive_account_key' );
		$add_column( $messages, 'archive_month', 'CHAR(7) NULL AFTER archive_peer_key' );
		$add_column( $messages, 'archive_receipt_hash', 'CHAR(64) NULL AFTER archive_month' );
		$add_column( $messages, 'archived_at', 'DATETIME NULL AFTER archive_receipt_hash' );
		$add_column( $messages, 'offloaded_at', 'DATETIME NULL AFTER archived_at' );
		$add_column( $messages, 'storage_error_code', 'VARCHAR(64) NULL AFTER offloaded_at' );
		$add_column( $messages, 'storage_attempts', 'INT UNSIGNED NOT NULL DEFAULT 0 AFTER storage_error_code' );
		$add_index( $messages, 'idx_storage_state', '(content_storage_state, created_at, id)' );
		$add_index( $messages, 'idx_storage_conversation', '(conversation_id, content_storage_state, created_at)' );
		$add_index( $messages, 'idx_archive_receipt', '(archive_receipt_hash)' );

		$receipts = self::tbl_archive_receipts();
		dbDelta( "CREATE TABLE `{$receipts}` (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			crm_message_id BIGINT UNSIGNED NOT NULL,
			conversation_id BIGINT UNSIGNED NOT NULL,
			inbox_id BIGINT UNSIGNED NOT NULL,
			channel_type VARCHAR(32) NOT NULL,
			account_key VARCHAR(128) NOT NULL,
			peer_key VARCHAR(128) NOT NULL,
			archive_month CHAR(7) NOT NULL,
			archive_schema_version SMALLINT UNSIGNED NOT NULL DEFAULT 1,
			archive_key_version VARCHAR(32) NOT NULL DEFAULT 'v1',
			line_hash CHAR(64) NOT NULL,
			byte_offset BIGINT NULL,
			line_bytes INT NULL,
			archive_status VARCHAR(16) NOT NULL DEFAULT 'written',
			written_at DATETIME NULL,
			verified_at DATETIME NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY uniq_message_schema (crm_message_id, archive_schema_version),
			KEY idx_partition (channel_type, account_key, peer_key, archive_month),
			KEY idx_status_created (archive_status, created_at)
		) {$charset};" );

		$teams = self::tbl_teams();
		dbDelta( "CREATE TABLE `{$teams}` (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(190) NOT NULL,
			description TEXT NULL,
			allow_auto_assign TINYINT(1) NOT NULL DEFAULT 1,
			is_active TINYINT(1) NOT NULL DEFAULT 1,
			created_by BIGINT UNSIGNED NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY uniq_name (name),
			KEY idx_active (is_active, allow_auto_assign)
		) {$charset};" );

		$team_members = self::tbl_team_members();
		dbDelta( "CREATE TABLE `{$team_members}` (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			team_id BIGINT UNSIGNED NOT NULL,
			user_id BIGINT UNSIGNED NOT NULL,
			member_role VARCHAR(20) NOT NULL DEFAULT 'agent',
			is_active TINYINT(1) NOT NULL DEFAULT 1,
			last_assigned_at DATETIME NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY uniq_team_user (team_id, user_id),
			KEY idx_user_active (user_id, is_active)
		) {$charset};" );

		$inbox_members = self::tbl_inbox_members();
		dbDelta( "CREATE TABLE `{$inbox_members}` (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			inbox_id BIGINT UNSIGNED NOT NULL,
			user_id BIGINT UNSIGNED NOT NULL,
			member_role VARCHAR(20) NOT NULL DEFAULT 'agent',
			can_assign TINYINT(1) NOT NULL DEFAULT 0,
			is_active TINYINT(1) NOT NULL DEFAULT 1,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY uniq_inbox_user (inbox_id, user_id),
			KEY idx_user_inbox (user_id, inbox_id, is_active)
		) {$charset};" );
		self::invalidate_tables_cache();
	}

	/**
	 * [2026-09-17 Johnny Chu - Chu Hoàng Anh] PHASE-0.48C-CACHE — the browser Inbox cache (§11.6.2 of
	 * PHASE-0.48C-CRM-INBOX-SHEET-DIALOG-UNIFY.md) reads messages by id window: `WHERE conversation_id = %d
	 * AND id > / < %d ORDER BY id LIMIT %d` (list_messages, list_messages_before) and `SELECT MAX(id) WHERE
	 * conversation_id = %d` (get_conversation_newest_message_id). The only existing composite index,
	 * idx_conv_created (conversation_id, created_at), does not cover an id-ordered range, so a busy
	 * conversation forces a filesort. idx_conv_id (conversation_id, id) makes every one of those an
	 * index range scan; id is the primary key, so this is a small, append-mostly secondary index.
	 */
	public static function migrate_phase_054(): void {
		global $wpdb;
		$messages = self::tbl_messages();
		if ( ! self::index_exists( $messages, 'idx_conv_id' ) ) {
			$wpdb->query( "ALTER TABLE `{$messages}` ADD KEY idx_conv_id (conversation_id, id)" );
		}
		if ( function_exists( 'bizcity_tbl_invalidate' ) ) { bizcity_tbl_invalidate( $messages ); }
	}

	/**
	 * PHASE-0.56 D-6 — composite index for `conversations(assignee_id, status, waiting_since)`, the
	 * exact three columns `GET /reports/team-inbox` needs once it stops joining `messages` for "Mở"
	 * and "Chờ > N′" (D-7) and reads the D-1 denormalized counters directly instead.
	 */
	public static function migrate_phase_056(): void {
		global $wpdb;
		$conversations = self::tbl_conversations();
		if ( ! self::index_exists( $conversations, 'idx_assignee_status_wait' ) ) {
			$wpdb->query( "ALTER TABLE `{$conversations}` ADD KEY idx_assignee_status_wait (assignee_id, status, waiting_since)" );
		}
		if ( function_exists( 'bizcity_tbl_invalidate' ) ) { bizcity_tbl_invalidate( $conversations ); }
	}

	/**
	 * PHASE-0.56 H-03 — add-only receipt pointer columns for seekable JSONL reads.
	 */
	public static function migrate_phase_057(): void {
		global $wpdb;
		$receipts = self::tbl_archive_receipts();
		if ( ! self::column_exists( $receipts, 'byte_offset' ) ) {
			$wpdb->query( "ALTER TABLE `{$receipts}` ADD COLUMN byte_offset BIGINT NULL AFTER line_hash" );
		}
		if ( ! self::column_exists( $receipts, 'line_bytes' ) ) {
			$wpdb->query( "ALTER TABLE `{$receipts}` ADD COLUMN line_bytes INT NULL AFTER byte_offset" );
		}
		if ( function_exists( 'bizcity_tbl_invalidate' ) ) { bizcity_tbl_invalidate( $receipts ); }
	}

	/**
	 * PHASE-0.60B — contact birthday storage (v1.36.0).
	 *
	 * `birthday DATE NULL` is a real column because the daily "who has a birthday today"
	 * read must hit an index, not json_decode every contact (doc 0.60B §3). Q-B1 answered
	 * conservatively: no expression index (MySQL 8 only); instead `birthday_md CHAR(5)`
	 * (MM-DD) written together with `birthday` and indexed plainly, so 5.7 and MariaDB work.
	 * ADD-only, idempotent; declared in core/diagnostics/changelog/modules.twin-crm.json (R-DCL).
	 */
	public static function migrate_phase_060b(): void {
		// [2026-09-23 04:20 PM Claude Fable 5.1] PHASE-0.60B C2.4/C2.5.
		global $wpdb;
		$contacts = self::tbl_contacts();
		if ( ! self::column_exists( $contacts, 'birthday' ) ) {
			$wpdb->query( "ALTER TABLE `{$contacts}` ADD COLUMN birthday DATE NULL AFTER phone" );
		}
		if ( ! self::column_exists( $contacts, 'birthday_md' ) ) {
			$wpdb->query( "ALTER TABLE `{$contacts}` ADD COLUMN birthday_md CHAR(5) NULL AFTER birthday" );
		}
		if ( ! self::index_exists( $contacts, 'idx_birthday_md' ) ) {
			$wpdb->query( "ALTER TABLE `{$contacts}` ADD KEY idx_birthday_md (birthday_md)" );
		}
		if ( function_exists( 'bizcity_tbl_invalidate' ) ) {
			bizcity_tbl_invalidate( $contacts );
		}
		if ( function_exists( 'bizcity_column_invalidate' ) ) {
			bizcity_column_invalidate( $contacts, 'birthday' );
			bizcity_column_invalidate( $contacts, 'birthday_md' );
		}
	}
}

endif;
