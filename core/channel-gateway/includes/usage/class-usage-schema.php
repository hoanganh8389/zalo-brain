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
	// [2026-10-25 09:40 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.139 R126-TB (owner "gộp 3 bảng làm 1 sổ cái") — 1.6.0: bizcity_activity_day is no longer a table — its rows live in bizcity_usage_ledger as kind='day' (payload JSON); activity_day_table() is a derived table and ledger_table() hides the day rows.
	// [2026-10-25 09:47 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.140 S140-L5 — 1.7.0: bizcity_usage_ledger += subject_ref VARCHAR(64) NULL (the file a row served: TL-<id> / file:<msgId>, usage-ledger@1 optional key) + KEY idx_bound_subject (bound_user_id, subject_ref).
	const SCHEMA_VERSION = '1.7.0';
	const OPTION_VERSION = 'bizcity_usage_ledger_schema';

	/** The physical ledger table: DDL, ingest inserts and the activity-day rows only. Token readers use ledger_table(). */
	public static function ledger_physical_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'bizcity_usage_ledger';
	}

	/**
	 * // [2026-10-25 09:40 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.139 R126-TB (owner "gộp 3 bảng làm 1 sổ cái") — the table every TOKEN reader queries: a derived table over the physical ledger WITHOUT the kind='day' activity rows,
	 * so counts, MAX(account_seq) continuity, MAX(occurred_at) and SUMs keep meaning "tokens / tool calls" exactly as before the merge.
	 */
	public static function ledger_table(): string {
		return '(SELECT * FROM ' . self::ledger_physical_table() . " WHERE kind <> 'day') AS bizcity_usage_ledger_v";
	}

	/**
	 * [2026-10-25 Johnny Chu - Chu Hoàng Anh] PHASE-0.126 R126-TB (owner: "usage_daily gộp vào ledger cho chuẩn unify token ledger từ cell => hub => client") —
	 * the daily roll-up is no longer a table: this returns a DERIVED TABLE over bizcity_usage_ledger with the exact columns of the old
	 * bizcity_usage_daily (same GROUP BY as the old refresh_daily()), so every reader keeps its `FROM … WHERE …` unchanged and the ledger
	 * is the one source of billed tokens. The physical table is quarantined (rows stay; never written again) — see daily_physical_table().
	 */
	public static function daily_table(): string {
		$l = self::ledger_table();
		return "(SELECT occurred_day AS day, platform, account_id, bound_user_id, actor_role, actor_user_id, kind,
			SUM(billed_tokens) AS billed_tokens, COALESCE(SUM(billed_usd),0) AS billed_usd,
			COUNT(DISTINCT NULLIF(turn_ref,'')) AS turns, COUNT(DISTINCT NULLIF(thread_ref,'')) AS threads,
			SUM(CASE WHEN kind = 'tool' THEN 1 ELSE 0 END) AS tool_calls, MAX(received_at) AS updated_at
			FROM {$l} GROUP BY occurred_day, platform, account_id, bound_user_id, actor_role, actor_user_id, kind) AS bizcity_usage_daily_v";
	}

	/** [2026-10-25] PHASE-0.126 R126-TB — the quarantined physical table name (catalog / diagnostics only; never written). */
	public static function daily_physical_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'bizcity_usage_daily';
	}

	public static function plans_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'bizcity_account_plans';
	}

	/** The quarantined physical table (rows are copied into the ledger once by merge_legacy_activity(); never written again). */
	public static function activity_day_physical_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'bizcity_activity_day';
	}

	/** Activity-day columns: name => kind (i int, t JSON text, f decimal, p plain text). */
	const ACTIVITY_COLS = array(
		'measured' => 'i', 'customers' => 'i', 'replies' => 'i', 'customers_new' => 'i', 'replies_failed' => 'i', 'groups_active' => 'i', 'tasks' => 'i',
		'tasks_by_label' => 't', 'by_channel' => 't', 'groups_joined' => 'i', 'connections_active' => 'i', 'streak_days' => 'i', 'ledger_to_seq' => 'i',
		'points' => 'i', 'jobs_done' => 'i', 'jobs_failed' => 'i', 'jobs_by_kind' => 't', 'efficiency_score' => 'f', 'points_rev' => 'p',
	);

	/**
	 * // [2026-10-25 09:40 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.139 R126-TB (owner "gộp 3 bảng làm 1 sổ cái") — PHASE-0.112 S112-L1 — one row per (account, day): the counts the CELL measured (activity-day@1), now a DERIVED TABLE over the
	 * ledger rows kind='day' with the exact columns of the old bizcity_activity_day (JSON payload => columns), so every reader keeps its FROM ... WHERE.
	 * A row the cell overwrites whole; never a token ledger.
	 */
	public static function activity_day_table(): string {
		$sel = array( 'account_id', 'occurred_day AS day' );
		foreach ( self::ACTIVITY_COLS as $col => $k ) {
			$j = "JSON_EXTRACT(payload, '$.{$col}')";
			if ( 'i' === $k ) {
				$sel[] = "COALESCE(CAST({$j} AS UNSIGNED),0) AS {$col}";
			} elseif ( 'f' === $k ) {
				$sel[] = "CAST({$j} AS DECIMAL(4,1)) AS {$col}";
			} elseif ( 'p' === $k ) {
				$sel[] = "COALESCE(JSON_UNQUOTE({$j}),'') AS {$col}";
			} else {
				$sel[] = "{$j} AS {$col}";
			}
		}
		$sel[] = 'received_at';
		return '(SELECT ' . implode( ', ', $sel ) . ' FROM ' . self::ledger_physical_table() . " WHERE kind = 'day') AS bizcity_activity_day_v";
	}

	/** account_seq of a day row: far above any real cell sequence, one per calendar day, so UNIQUE(account_id, account_seq) makes REPLACE an upsert. */
	public static function day_seq( string $day ): int {
		return 4000000000000000000 + (int) preg_replace( '/\D/', '', $day );
	}

	/** One activity-day row (old table shape) => one physical ledger row (kind='day'). */
	public static function activity_to_ledger_row( array $row ): array {
		$day     = (string) $row['day'];
		$payload = array();
		foreach ( self::ACTIVITY_COLS as $col => $k ) {
			if ( ! array_key_exists( $col, $row ) || null === $row[ $col ] ) {
				continue; // a missing key reads back as SQL NULL (efficiency_score stays NULL)
			}
			$v = $row[ $col ];
			if ( 't' === $k ) {
				$v = is_string( $v ) ? ( json_decode( $v, true ) ?? array() ) : $v;
			} elseif ( 'i' === $k ) {
				$v = (int) $v;
			} elseif ( 'f' === $k ) {
				$v = (float) $v;
			} else {
				$v = (string) $v;
			}
			$payload[ $col ] = $v;
		}
		return array(
			'account_id'    => (string) $row['account_id'],
			'account_seq'   => self::day_seq( $day ),
			'row_ref'       => 'day',
			'kind'          => 'day',
			'occurred_at'   => $day . ' 00:00:00',
			'occurred_day'  => $day,
			'resolve_state' => 'ok',
			'received_at'   => (string) ( $row['received_at'] ?? gmdate( 'Y-m-d H:i:s' ) ),
			'payload'       => function_exists( 'wp_json_encode' ) ? wp_json_encode( $payload ) : json_encode( $payload ),
		);
	}

	/** One-time, idempotent copy of the old bizcity_activity_day rows into the ledger (kind='day'); the old table is then quarantined. */
	public static function merge_legacy_activity(): void {
		if ( ! function_exists( 'get_option' ) || '1' === (string) get_option( 'bizcity_activity_day_merged', '' ) ) {
			return;
		}
		global $wpdb;
		$old = self::activity_day_physical_table();
		if ( class_exists( 'BizCity_Table_Metadata' ) && BizCity_Table_Metadata::table_exists( $old ) ) {
			$done = true;
			foreach ( (array) $wpdb->get_results( "SELECT * FROM {$old} ORDER BY day LIMIT 20000", ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$row = self::activity_to_ledger_row( $r );
				$ok  = $wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
					'INSERT IGNORE INTO ' . self::ledger_physical_table() . ' (account_id, account_seq, row_ref, kind, occurred_at, occurred_day, resolve_state, received_at, payload) VALUES (%s,%d,%s,%s,%s,%s,%s,%s,%s)',
					$row['account_id'], $row['account_seq'], $row['row_ref'], $row['kind'], $row['occurred_at'], $row['occurred_day'], $row['resolve_state'], $row['received_at'], $row['payload']
				) );
				$done = $done && false !== $ok;
			}
			if ( ! $done ) {
				return; // retry on the next admin_init
			}
		}
		update_option( 'bizcity_activity_day_merged', '1', false );
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
		$ledger  = self::ledger_physical_table();

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
			payload LONGTEXT NULL,
			subject_ref VARCHAR(64) NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uniq_account_seq (account_id, account_seq),
			KEY idx_account_time (account_id, occurred_at),
			KEY idx_bound_time (bound_user_id, occurred_at),
			KEY idx_actor_time (actor_user_id, occurred_at),
			KEY idx_thread (thread_ref),
			KEY idx_day_account (occurred_day, account_id),
			KEY idx_kind_account_day (kind, account_id, occurred_day),
			KEY idx_bound_subject (bound_user_id, subject_ref)
		) {$charset};" ); // [2026-10-25 09:47 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.140 S140-L5 — + subject_ref / idx_bound_subject (1.7.0)

		// [2026-10-25 Johnny Chu - Chu Hoàng Anh] PHASE-0.126 R126-TB — bizcity_usage_daily is no longer created: daily_table() derives it from the ledger (the table is quarantined).

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

		// [2026-10-25 09:40 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.139 R126-TB (owner "gộp 3 bảng làm 1 sổ cái") — bizcity_activity_day is no longer created: its rows are ledger rows kind='day' (see activity_day_table()).

		update_option( self::OPTION_VERSION, self::SCHEMA_VERSION, false );
		self::merge_legacy_activity();
	}
}
