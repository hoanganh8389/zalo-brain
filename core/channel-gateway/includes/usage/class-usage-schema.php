<?php
/**
 * Usage ledger — per-blog tables of the site's COPY of its own client ledger (PHASE-0.107 S107-L9, docs 62 §5.3 + 64 L12).
 *
 * `{prefix}bizcity_usage_ledger` = one row per cell ledger row of this client (contract usage-ledger@1), keyed by
 * (account_id, account_seq). Billed columns only: no cost, no real tokens, no markup (R-USAGE-ONE-LEDGER §5).
 * `{prefix}bizcity_usage_daily`  = aggregate per (day, platform, account_id, bound_user_id, actor_role, actor_user_id, kind),
 * rebuilt from the ledger for every (day, account) an ingest touched.
 *
 * Per blog (`$wpdb->prefix`, never base_prefix). Version option `bizcity_usage_ledger_schema`.
 *
 * // [2026-10-13 01:08 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.107 S107-L9 — new module core/channel-gateway/includes/usage/.
 *
 * @package BizCity_Twin_AI
 */

defined( 'ABSPATH' ) || exit;

// Loaded with require_once only (no class_exists self-guard: an early-bound declaration makes that guard always true).
final class BizCity_Usage_Schema {

	// [2026-10-13 02:28 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.107 S107-L14 — 1.1.0 adds {prefix}bizcity_account_plans (client plan per account id).
	// [2026-10-13 03:30 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.107 round 5 — 1.2.0: the SITE enforces plans (no push to the cell): ai_off_by_plan / ai_off_at / enforce_error / last_notice_*.
	// [2026-10-13 06:14 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.107 S107-L42 — 1.3.0: surface VARCHAR(24) to VARCHAR(96) (cell writes gateway:<caller>).
	// [2026-10-15 05:50 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.112 S112-L1 — 1.4.0 adds {prefix}bizcity_activity_day (the cell's daily ACTIVITY COUNTS per number, activity-day@1; no money).
	// [2026-10-15 10:00 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.112 S112-W9 — 1.5.0: activity_day += points / jobs_done / jobs_failed / jobs_by_kind / efficiency_score / points_rev (activity-day@1.1, no tokens).
	const SCHEMA_VERSION = '1.5.0';
	const OPTION_VERSION = 'bizcity_usage_ledger_schema';

	public static function ledger_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'bizcity_usage_ledger';
	}

	public static function daily_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'bizcity_usage_daily';
	}

	public static function plans_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'bizcity_account_plans';
	}

	/** PHASE-0.112 S112-L1 — one row per (account, day): the counts the CELL measured (activity-day@1). A cache the cell overwrites, never a ledger. */
	public static function activity_day_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'bizcity_activity_day';
	}

	public static function installed(): bool {
		return function_exists( 'get_option' ) && (string) get_option( self::OPTION_VERSION, '' ) === self::SCHEMA_VERSION;
	}

	/** Idempotent; cheap when the option already matches (called on admin_init and before every ingest). */
	public static function maybe_install(): void {
		if ( self::installed() ) {
			return;
		}
		if ( ! defined( 'ABSPATH' ) || ! is_file( ABSPATH . 'wp-admin/includes/upgrade.php' ) ) {
			return;
		}
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		global $wpdb;
		$charset = function_exists( 'bizcity_get_charset_collate' ) ? bizcity_get_charset_collate() : $wpdb->get_charset_collate();
		$ledger  = self::ledger_table();
		$daily   = self::daily_table();

		// occurred_at / received_at are UTC; occurred_day is the SITE-local date (what the daily table and charts use).
		// contact_key = first 12 hex of the cell contact_ref (never the 64-hex), billed_usd NULL = tool not priced by the Hub table.
		dbDelta( "CREATE TABLE {$ledger} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			account_id VARCHAR(64) NOT NULL DEFAULT '',
			account_seq BIGINT UNSIGNED NOT NULL DEFAULT 0,
			row_ref VARCHAR(16) NOT NULL DEFAULT '',
			kind VARCHAR(12) NOT NULL DEFAULT 'llm',
			occurred_at DATETIME NOT NULL,
			occurred_day DATE NOT NULL,
			platform VARCHAR(24) NOT NULL DEFAULT '',
			channel_ref VARCHAR(96) NOT NULL DEFAULT '',
			bound_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			actor_role VARCHAR(12) NOT NULL DEFAULT 'customer',
			actor_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			contact_key VARCHAR(16) NOT NULL DEFAULT '',
			thread_ref VARCHAR(96) NOT NULL DEFAULT '',
			turn_ref VARCHAR(96) NOT NULL DEFAULT '',
			surface VARCHAR(96) NOT NULL DEFAULT '',
			model VARCHAR(120) NOT NULL DEFAULT '',
			tool VARCHAR(80) NOT NULL DEFAULT '',
			tool_kind VARCHAR(12) NOT NULL DEFAULT '',
			units DECIMAL(14,4) NOT NULL DEFAULT 0,
			billed_tokens BIGINT NOT NULL DEFAULT 0,
			billed_usd DECIMAL(18,8) NULL,
			price_source VARCHAR(24) NOT NULL DEFAULT '',
			price_version VARCHAR(40) NOT NULL DEFAULT '',
			is_error TINYINT(1) NOT NULL DEFAULT 0,
			resolve_state VARCHAR(16) NOT NULL DEFAULT 'ok',
			received_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uniq_account_seq (account_id, account_seq),
			KEY idx_account_time (account_id, occurred_at),
			KEY idx_bound_time (bound_user_id, occurred_at),
			KEY idx_actor_time (actor_user_id, occurred_at),
			KEY idx_thread (thread_ref),
			KEY idx_day_account (occurred_day, account_id)
		) {$charset};" );

		dbDelta( "CREATE TABLE {$daily} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			day DATE NOT NULL,
			platform VARCHAR(24) NOT NULL DEFAULT '',
			account_id VARCHAR(64) NOT NULL DEFAULT '',
			bound_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			actor_role VARCHAR(12) NOT NULL DEFAULT '',
			actor_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			kind VARCHAR(12) NOT NULL DEFAULT '',
			billed_tokens BIGINT NOT NULL DEFAULT 0,
			billed_usd DECIMAL(18,8) NOT NULL DEFAULT 0,
			turns INT UNSIGNED NOT NULL DEFAULT 0,
			threads INT UNSIGNED NOT NULL DEFAULT 0,
			tool_calls INT UNSIGNED NOT NULL DEFAULT 0,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uniq_group (day, platform, account_id, bound_user_id, actor_role, actor_user_id, kind),
			KEY idx_account_day (account_id, day),
			KEY idx_bound_day (bound_user_id, day),
			KEY idx_actor_day (actor_user_id, day)
		) {$charset};" );

		// [2026-10-13 02:28 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.107 S107-L14 (66 §8.1) — the client's commercial plan per ACCOUNT ID.
		// Own table: the mirror {prefix}bizcity_zalo_accounts is written by the Hub sync and gets no commercial column.
		// Times are UTC. plan_mode defaults to 'off' (nothing existing is ever blocked). Used tokens are NOT stored: they are
		// SUM(billed_tokens) of this site's own bizcity_usage_ledger (round 5). ai_off_by_plan = the reason WE switched the number's AI off.
		$plans = self::plans_table();
		dbDelta( "CREATE TABLE {$plans} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			platform VARCHAR(24) NOT NULL DEFAULT 'zalo_personal',
			account_id VARCHAR(64) NOT NULL DEFAULT '',
			plan_code VARCHAR(32) NOT NULL DEFAULT '',
			state VARCHAR(12) NOT NULL DEFAULT 'none',
			plan_mode VARCHAR(3) NOT NULL DEFAULT 'off',
			plan_version INT UNSIGNED NOT NULL DEFAULT 0,
			started_at DATETIME NULL,
			valid_until DATETIME NULL,
			monthly_token_allowance BIGINT UNSIGNED NOT NULL DEFAULT 0,
			allowance_period VARCHAR(12) NOT NULL DEFAULT 'month',
			notice_text VARCHAR(500) NOT NULL DEFAULT '',
			ai_off_by_plan VARCHAR(32) NOT NULL DEFAULT '',
			ai_off_at DATETIME NULL,
			enforce_error VARCHAR(80) NOT NULL DEFAULT '',
			last_notice_at DATETIME NULL,
			last_notice_reason VARCHAR(32) NOT NULL DEFAULT '',
			last_order_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			last_sold_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
			updated_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uniq_account (platform, account_id),
			KEY idx_state (state, valid_until)
		) {$charset};" );

		// [2026-10-15 05:50 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.112 S112-L1 — activity-day@1 store. `measured` 0 = the cell was not measuring that day (counts are zeros that must not be shown as "0 customers").
		// tasks_by_label / by_channel are the cell's own JSON (small, capped at ingest); nothing here is money, tokens or a contact. Overwritten whole by (account_id, day).
		$activity = self::activity_day_table();
		dbDelta( "CREATE TABLE {$activity} (
			account_id VARCHAR(64) NOT NULL DEFAULT '',
			day DATE NOT NULL,
			measured TINYINT(1) NOT NULL DEFAULT 1,
			customers INT UNSIGNED NOT NULL DEFAULT 0,
			replies INT UNSIGNED NOT NULL DEFAULT 0,
			customers_new INT UNSIGNED NOT NULL DEFAULT 0,
			replies_failed INT UNSIGNED NOT NULL DEFAULT 0,
			groups_active INT UNSIGNED NOT NULL DEFAULT 0,
			tasks INT UNSIGNED NOT NULL DEFAULT 0,
			tasks_by_label TEXT NULL,
			by_channel TEXT NULL,
			groups_joined INT UNSIGNED NOT NULL DEFAULT 0,
			connections_active INT UNSIGNED NOT NULL DEFAULT 0,
			streak_days INT UNSIGNED NOT NULL DEFAULT 0,
			ledger_to_seq BIGINT UNSIGNED NOT NULL DEFAULT 0,
			points INT UNSIGNED NOT NULL DEFAULT 0,
			jobs_done INT UNSIGNED NOT NULL DEFAULT 0,
			jobs_failed INT UNSIGNED NOT NULL DEFAULT 0,
			jobs_by_kind TEXT NULL,
			efficiency_score DECIMAL(4,1) NULL,
			points_rev VARCHAR(40) NOT NULL DEFAULT '',
			received_at DATETIME NOT NULL,
			PRIMARY KEY  (account_id, day),
			KEY idx_day (day)
		) {$charset};" );

		update_option( self::OPTION_VERSION, self::SCHEMA_VERSION, false );
	}
}
