<?php
/**
 * Brain journey ledger — per-blog, append-only achievement log + its rebuildable rollup (PHASE-0.108 S108-L1,
 * docs PHASE-0.108-ZALO-BRAIN-ACHIEVEMENTS-ROI/00-MASTER-PLAN.md §3.4, D108-4).
 *
 * `{prefix}bizcity_brain_journey` = one row per achieved event (`born`, `first_reply`, `trained`, `milestone`,
 * `level_up`, `plan`, `payback`, `custom`). Never UPDATEd or DELETEd once inserted (R-BRAIN-JOURNEY luật 2):
 * a reversal is a new row, not an edit. `dedupe_key` UNIQUE makes the evaluator's INSERT IGNORE safe to run
 * any number of times. `prev_hash`/`row_hash` form a hash chain per subject (see class-brain-journey-evaluator.php)
 * so the ledger can be verified — the point of this table is to be *trusted* ("được công nhận", §3.4).
 *
 * `{prefix}bizcity_brain_journey_heads` holds the chain head (last_seq, last_hash) per subject so concurrent
 * writers can't interleave; it is NOT part of the public story, only chain bookkeeping.
 *
 * `{prefix}bizcity_brain_metrics` is a CACHE, always rebuildable from `bizcity_usage_ledger` / `bizcity_usage_daily`
 * (D108-4): the evaluator recomputes a row from scratch and REPLACEs it, it never increments in place. Losing this
 * table loses nothing except a cache warm-up.
 *
 * Per blog (`$wpdb->prefix`, never base_prefix), same as BizCity_Usage_Schema. Version option `bizcity_brain_journey_schema`.
 *
 * // [2026-10-13 Johnny Chu - Chu Hoàng Anh] PHASE-0.108 S108-L1 — new module, sibling of core/channel-gateway/includes/usage/.
 *
 * @package BizCity_Twin_AI
 */

defined( 'ABSPATH' ) || exit;

// Loaded with require_once only (no class_exists self-guard: an early-bound declaration makes that guard always true).
final class BizCity_Brain_Journey_Schema {

	// 1.1.0 (10-SPEC §9 G-2/G-3/G-4): + customer_replies_month, distinct_customers_month, ai_enabled.
	const SCHEMA_VERSION = '1.1.0';
	const OPTION_VERSION = 'bizcity_brain_journey_schema';

	public static function journey_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'bizcity_brain_journey';
	}

	public static function heads_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'bizcity_brain_journey_heads';
	}

	public static function metrics_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'bizcity_brain_metrics';
	}

	public static function installed(): bool {
		return function_exists( 'get_option' ) && (string) get_option( self::OPTION_VERSION, '' ) === self::SCHEMA_VERSION;
	}

	/** Idempotent; cheap when the option already matches (called on admin_init and before every evaluator run). */
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
		$journey = self::journey_table();
		$heads   = self::heads_table();
		$metrics = self::metrics_table();

		// subject_kind 'brain' = one Zalo/web account_id, 'owner' = one WP user (nấc thang), 'site' = site-wide (bản tin tổng).
		// occurred_at is UTC. owner_user_id = whoever owned the subject AT THE MOMENT this row was written (luật 2: never rewritten).
		dbDelta( "CREATE TABLE {$journey} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			seq BIGINT UNSIGNED NOT NULL DEFAULT 0,
			occurred_at DATETIME NOT NULL,
			subject_kind VARCHAR(12) NOT NULL DEFAULT 'brain',
			subject_id VARCHAR(64) NOT NULL DEFAULT '',
			owner_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			event VARCHAR(16) NOT NULL DEFAULT '',
			code VARCHAR(64) NOT NULL DEFAULT '',
			tier VARCHAR(12) NOT NULL DEFAULT '',
			value BIGINT NOT NULL DEFAULT 0,
			catalog_version VARCHAR(32) NOT NULL DEFAULT '',
			source VARCHAR(24) NOT NULL DEFAULT '',
			source_ref VARCHAR(64) NOT NULL DEFAULT '',
			dedupe_key VARCHAR(160) NOT NULL DEFAULT '',
			prev_hash VARCHAR(64) NOT NULL DEFAULT '',
			row_hash VARCHAR(64) NOT NULL DEFAULT '',
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uniq_dedupe (dedupe_key),
			UNIQUE KEY uniq_subject_seq (subject_kind, subject_id, seq),
			KEY idx_subject_time (subject_kind, subject_id, occurred_at),
			KEY idx_owner_time (owner_user_id, occurred_at),
			KEY idx_event (event, occurred_at)
		) {$charset};" );

		dbDelta( "CREATE TABLE {$heads} (
			subject_kind VARCHAR(12) NOT NULL DEFAULT 'brain',
			subject_id VARCHAR(64) NOT NULL DEFAULT '',
			last_seq BIGINT UNSIGNED NOT NULL DEFAULT 0,
			last_hash VARCHAR(64) NOT NULL DEFAULT '',
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (subject_kind, subject_id)
		) {$charset};" );

		// Cache only (D108-4): always rebuilt whole-row from bizcity_usage_ledger/_daily + Woo, never incremented.
		// [2026-10-13 Johnny Chu - Chu Hoàng Anh] PHASE-0.108 10-SPEC §9 G-2/G-3/G-4 — `customer_replies`/
		// `distinct_customers` are LIFETIME totals (correct for milestone thresholds); "làm thay" and the
		// "Hiệu quả" ₫/khách ratio must use THIS MONTH's figures instead, hence the separate `_month` columns.
		// `ai_enabled` lets the 💤 flag tell "idle" apart from "AI is off, don't flag" (an AI-off number must
		// never show 💤; a brand-new number with AI on and zero replies yet SHOULD, once past the idle window).
		dbDelta( "CREATE TABLE {$metrics} (
			account_id VARCHAR(64) NOT NULL DEFAULT '',
			platform VARCHAR(24) NOT NULL DEFAULT 'zalo_personal',
			owner_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			customer_replies BIGINT UNSIGNED NOT NULL DEFAULT 0,
			distinct_customers BIGINT UNSIGNED NOT NULL DEFAULT 0,
			distinct_groups BIGINT UNSIGNED NOT NULL DEFAULT 0,
			tasks_done BIGINT UNSIGNED NOT NULL DEFAULT 0,
			trained_items BIGINT UNSIGNED NOT NULL DEFAULT 0,
			streak_days INT UNSIGNED NOT NULL DEFAULT 0,
			billed_tokens_month BIGINT UNSIGNED NOT NULL DEFAULT 0,
			chi_usd_month DECIMAL(18,8) NOT NULL DEFAULT 0,
			thu_usd_month DECIMAL(18,8) NOT NULL DEFAULT 0,
			customer_replies_month BIGINT UNSIGNED NOT NULL DEFAULT 0,
			distinct_customers_month BIGINT UNSIGNED NOT NULL DEFAULT 0,
			ai_enabled TINYINT(1) NOT NULL DEFAULT 1,
			last_customer_reply_at DATETIME NULL,
			born_at DATETIME NULL,
			active TINYINT(1) NOT NULL DEFAULT 0,
			computed_at DATETIME NOT NULL,
			PRIMARY KEY  (account_id),
			KEY idx_owner (owner_user_id),
			KEY idx_active (active, customer_replies)
		) {$charset};" );

		update_option( self::OPTION_VERSION, self::SCHEMA_VERSION, false );
	}
}
