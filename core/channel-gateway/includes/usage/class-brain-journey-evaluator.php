<?php
/**
 * `BizCity_Brain_Journey_Evaluator` — the "bộ chấm" of PHASE-0.108 (S108-L3, master plan §2 luật 1–3, §3.4).
 *
 * Runs after every ledger-ingest batch that actually inserted rows (same hook point as
 * BizCity_Usage_Plan_Enforce::on_ingest, class-usage-ingest.php) and once an hour for every known account
 * (cron, catches accounts nobody is actively messaging right now plus owner/site-level tiers). Recomputes
 * `bizcity_brain_metrics` for the touched account FROM SCRATCH from `bizcity_usage_ledger` / `_daily` (D108-4 —
 * the cache is always rebuildable, never incremented), then checks the `brain-milestones@1` catalog and writes
 * any newly-reached tier as one more append-only row in `bizcity_brain_journey` (dedupe_key makes re-running
 * this any number of times safe — no duplicate rows, see T108-5).
 *
 * R-BRAIN-JOURNEY luật 3 (master plan §2): only `actor_role = customer` turns count toward volume milestones,
 * capped at `rules.customer_reply_daily_cap_per_contact` (20) per (contact_key, day) — a customer sending 100
 * messages in a day, or the owner chatting with their own number, cannot farm a milestone or burn real tokens
 * "for credit". Luật 4: ROI (real revenue vs. estimated time saved) is computed by BizCity_Brain_ROI and kept on
 * separate columns — this class never blends them into one "value".
 *
 * // [2026-10-13 Johnny Chu - Chu Hoàng Anh] PHASE-0.108 S108-L3/L4 — evaluator + born/first_reply backfill.
 *
 * @package BizCity_Twin_AI
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_Brain_Journey_Evaluator {

	const CRON_HOOK   = 'bizcity_brain_journey_hourly';
	const PLATFORM    = 'zalo_personal';
	const OPT_ACTION_TOOLS = 'bizcity_brain_action_tool_names';

	/**
	 * Test seams: now() · tz() · accounts(): array<string> (every known account_id, for the hourly sweep) ·
	 * owner_of(account_id): array{owner_user_id:int,status:string} · log(message): void
	 *
	 * @var array<string,callable>
	 */
	public static $readers = array();

	private static $initialized = false;

	public static function init(): void {
		if ( self::$initialized ) {
			return;
		}
		self::$initialized = true;
		add_action( 'bizcity_usage_ledger_ingested', array( __CLASS__, 'on_ingest' ), 10, 1 );
		add_action( self::CRON_HOOK, array( __CLASS__, 'hourly_sweep' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
		// Trial-start hook doubles as "born" signal (same action BizCity_Usage_Plans listens to) — see class-usage-plans.php ACTION_CONNECTED.
		add_action( 'bizcity_zalo_account_connected', array( __CLASS__, 'on_connected' ), 20, 1 );
	}

	public static function schedule(): void {
		if ( function_exists( 'wp_next_scheduled' ) && ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 900, 'hourly', self::CRON_HOOK );
		}
	}

	public static function on_ingest( string $account_id ): void {
		if ( 0 === strpos( $account_id, 'web-' ) ) {
			return; // §1.6 of master plan: "trợ lý" = a Zalo Brain account; anonymous /gpt/ web is out of scope.
		}
		self::evaluate_account( $account_id );
	}

	public static function on_connected( $account_id ): void {
		self::evaluate_account( (string) $account_id );
	}

	/** Hourly: every account that has ever logged a ledger row (so a "sleeping" account's streak can still lapse / tiers still get checked against ROI). */
	public static function hourly_sweep(): void {
		foreach ( self::accounts() as $account_id ) {
			self::evaluate_account( $account_id );
		}
	}

	/* ================================================================
	 *  Core: recompute metrics + check milestones for one account
	 * ================================================================ */

	/** @return array{metrics:array<string,mixed>,new_events:array<int,string>} */
	public static function evaluate_account( string $account_id ): array {
		if ( class_exists( 'BizCity_Brain_Journey_Schema' ) ) {
			BizCity_Brain_Journey_Schema::maybe_install();
		}
		if ( ! class_exists( 'BizCity_Usage_Schema' ) || ! BizCity_Usage_Schema::installed() ) {
			return array( 'metrics' => array(), 'new_events' => array() );
		}

		$owner = self::owner_of( $account_id );
		$owner_user_id = (int) ( $owner['owner_user_id'] ?? 0 );

		$metrics = self::compute_metrics( $account_id, $owner_user_id );
		self::save_metrics( $account_id, $metrics );

		$new_events = array();
		$existing   = self::existing_event_codes( $account_id );

		if ( null !== $metrics['born_at'] && empty( $existing['born.bronze'] ) ) {
			$new_events[] = self::write_event( array(
				'subject_kind' => 'brain', 'subject_id' => $account_id, 'owner_user_id' => $owner_user_id,
				'event' => 'born', 'code' => 'born.bronze', 'tier' => 'bronze', 'value' => 1,
				'occurred_at' => $metrics['born_at'], 'source' => 'qr', 'source_ref' => $account_id,
			) ) ? 'born.bronze' : null;
		}
		if ( $metrics['customer_replies'] > 0 && null !== $metrics['first_customer_reply_at'] && empty( $existing['born.silver'] ) ) {
			$new_events[] = self::write_event( array(
				'subject_kind' => 'brain', 'subject_id' => $account_id, 'owner_user_id' => $owner_user_id,
				'event' => 'first_reply', 'code' => 'born.silver', 'tier' => 'silver', 'value' => 1,
				'occurred_at' => $metrics['first_customer_reply_at'], 'source' => 'usage_ledger', 'source_ref' => $account_id,
			) ) ? 'born.silver' : null;
		}

		foreach ( BizCity_Brain_Milestones_Catalog::active_groups() as $group ) {
			$metric = (string) ( $group['metric'] ?? '' );
			if ( ! isset( $metrics[ $metric ] ) ) {
				continue;
			}
			foreach ( BizCity_Brain_Milestones_Catalog::reached_tiers( $group, (int) $metrics[ $metric ] ) as $tier ) {
				$code = $group['code'] . '.' . $tier['tier'];
				if ( ! empty( $existing[ $code ] ) ) {
					continue;
				}
				$ok = self::write_event( array(
					'subject_kind' => 'brain', 'subject_id' => $account_id, 'owner_user_id' => $owner_user_id,
					'event' => 'milestone', 'code' => $code, 'tier' => $tier['tier'], 'value' => (int) $metrics[ $metric ],
					'occurred_at' => self::now_mysql(), 'source' => 'usage_ledger', 'source_ref' => $account_id,
				) );
				if ( $ok ) {
					$new_events[] = $code;
				}
			}
		}

		if ( class_exists( 'BizCity_Brain_ROI' ) ) {
			BizCity_Brain_ROI::maybe_write_payback_event( $account_id, $owner_user_id, $metrics );
		}

		return array( 'metrics' => $metrics, 'new_events' => array_values( array_filter( $new_events ) ) );
	}

	/* ================================================================
	 *  Metrics (always recomputed from the ledger — D108-4)
	 * ================================================================ */

	/** @return array<string,mixed> */
	public static function compute_metrics( string $account_id, int $owner_user_id ): array {
		global $wpdb;
		$rules   = BizCity_Brain_Milestones_Catalog::rules();
		$cap     = max( 1, (int) ( $rules['customer_reply_daily_cap_per_contact'] ?? 20 ) );
		$role    = (string) ( $rules['actor_role_for_value_metrics'] ?? 'customer' );
		$window  = max( 1, (int) ( $rules['active_window_days'] ?? 7 ) );
		$ledger  = BizCity_Usage_Schema::ledger_table();
		$daily   = BizCity_Usage_Schema::daily_table();

		// Luật 3: group customer turns by (contact_key, day), cap each group at $cap, then sum — a customer
		// spamming 100 turns in a day, or the owner chatting with their own number (actor_role != customer),
		// cannot inflate this number or burn real tokens "for credit".
		$day_groups = $wpdb->get_results( $wpdb->prepare(
			"SELECT contact_key, occurred_day, COUNT(DISTINCT turn_ref) AS day_turns, MIN(occurred_at) AS first_at
			 FROM {$ledger} WHERE account_id = %s AND kind = 'llm' AND actor_role = %s AND contact_key <> ''
			 GROUP BY contact_key, occurred_day",
			$account_id, $role
		), ARRAY_A );
		// [2026-10-13 Johnny Chu - Chu Hoàng Anh] PHASE-0.108 S108-L14 note / 20-REVENUE-PER-NUMBER.md §6.5 —
		// the cap+distinct-customer math is now the ONE shared rule (`cap20_customer_only@1`) also used by the
		// cell's brain-standing.ts, so both sides agree on the same turns for the same fixture (T108-33) instead
		// of two hand-rolled loops silently drifting apart.
		$first_customer_reply_at = null;
		foreach ( (array) $day_groups as $g ) {
			if ( null === $first_customer_reply_at || $g['first_at'] < $first_customer_reply_at ) {
				$first_customer_reply_at = $g['first_at'];
			}
		}
		$lifetime = BizCity_Brain_Standing_Rules::capped_turns( self::to_rule_groups( $day_groups ), $cap );
		$customer_replies   = $lifetime['turns'];
		$distinct_customers = $lifetime['customers'];

		// 10-SPEC §9 G-2/G-3: "làm thay" and the "Hiệu quả" ₫/khách ratio read THIS MONTH's figures — the
		// lifetime totals above are right for milestone thresholds but wrong for either of those.
		$month_day    = gmdate( 'Y-m-01' );
		$month_groups = array_values( array_filter( (array) $day_groups, static function ( $g ) use ( $month_day ) {
			return $g['occurred_day'] >= $month_day;
		} ) );
		$this_month = BizCity_Brain_Standing_Rules::capped_turns( self::to_rule_groups( $month_groups ), $cap );
		$customer_replies_month   = $this_month['turns'];
		$distinct_customers_month = $this_month['customers'];

		$action_tools = (array) get_option( self::OPT_ACTION_TOOLS, array() );
		$action_tools = (array) apply_filters( 'bizcity_brain_action_tool_names', $action_tools, $account_id );
		$tasks_done = 0;
		if ( ! empty( $action_tools ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $action_tools ), '%s' ) );
			$tasks_done = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$ledger} WHERE account_id = %s AND kind = 'tool' AND is_error = 0 AND tool IN ({$placeholders})",
				array_merge( array( $account_id ), array_values( $action_tools ) )
			) );
		}

		$trained_items = class_exists( 'BizCity_Brain_ROI' ) ? BizCity_Brain_ROI::trained_items_count( $account_id ) : 0;

		// Streak: consecutive days up to and including today with >=1 customer-served row in the daily rollup.
		$streak_days = self::consecutive_streak( $account_id, $role, $daily );

		$month_from = gmdate( 'Y-m-01 00:00:00' );
		$billed_tokens_month = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COALESCE(SUM(billed_tokens),0) FROM {$daily} WHERE account_id = %s AND day >= %s", $account_id, substr( $month_from, 0, 10 )
		) );
		$chi_usd_month = (float) $wpdb->get_var( $wpdb->prepare(
			"SELECT COALESCE(SUM(billed_usd),0) FROM {$daily} WHERE account_id = %s AND day >= %s AND kind <> 'tool'", $account_id, substr( $month_from, 0, 10 )
		) );
		$thu_usd_month = class_exists( 'BizCity_Brain_ROI' ) ? BizCity_Brain_ROI::revenue_this_month( $account_id ) : 0.0;

		$has_recent = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT 1 FROM {$ledger} WHERE account_id = %s AND actor_role = %s AND occurred_at >= %s LIMIT 1",
			$account_id, $role, gmdate( 'Y-m-d H:i:s', time() - $window * DAY_IN_SECONDS )
		) );

		// 10-SPEC §9 G-4: the 💤 flag (computed by the REST layer from these two fields) must never fire for
		// a number whose AI is off, and SHOULD fire for a brand-new, zero-reply number once idle long enough —
		// `active` above (7-day window) is a different, broader concept used for owner-tier gating, not this flag.
		$ai_enabled = class_exists( 'BizCity_Zalo_Account_Flags' ) ? (bool) BizCity_Zalo_Account_Flags::ai_enabled( $account_id ) : true;

		$existing_born = self::existing_born_at( $account_id );
		$born_at = $existing_born; // never move once set (luật 2: a milestone/date, once recorded, stays)
		if ( null === $born_at ) {
			$acc_status = (string) ( self::owner_of( $account_id )['status'] ?? '' );
			if ( 'connected' === $acc_status ) {
				$born_at = self::now_mysql();
			}
		}

		return array(
			'account_id'               => $account_id,
			'platform'                 => self::PLATFORM,
			'owner_user_id'            => $owner_user_id,
			'customer_replies'         => $customer_replies,
			'distinct_customers'       => $distinct_customers,
			'customer_replies_month'   => $customer_replies_month,
			'distinct_customers_month' => $distinct_customers_month,
			'distinct_groups'          => 0, // Q108-2 not yet approved — thread_kind not sent by the cell.
			'tasks_done'               => $tasks_done,
			'trained_items'            => $trained_items,
			'streak_days'              => $streak_days,
			'billed_tokens_month'      => $billed_tokens_month,
			'chi_usd_month'            => $chi_usd_month,
			'thu_usd_month'            => $thu_usd_month,
			'ai_enabled'               => $ai_enabled ? 1 : 0,
			'last_customer_reply_at'   => self::last_activity_at( $account_id, $role ),
			'born_at'                  => $born_at,
			'first_customer_reply_at'  => $first_customer_reply_at,
			'active'                   => $has_recent ? 1 : 0,
		);
	}

	/** @return array<int,array{contact:string,day:string,turns:int}> */
	private static function to_rule_groups( array $day_groups ): array {
		$out = array();
		foreach ( $day_groups as $g ) {
			$out[] = array( 'contact' => (string) ( $g['contact_key'] ?? '' ), 'day' => (string) ( $g['occurred_day'] ?? '' ), 'turns' => (int) ( $g['day_turns'] ?? 0 ) );
		}
		return $out;
	}

	private static function consecutive_streak( string $account_id, string $role, string $daily ): int {
		global $wpdb;
		$rows = $wpdb->get_col( $wpdb->prepare(
			"SELECT day FROM {$daily} WHERE account_id = %s AND actor_role = %s GROUP BY day HAVING SUM(turns) > 0 ORDER BY day DESC LIMIT 400",
			$account_id, $role
		) );
		if ( class_exists( 'BizCity_Brain_Standing_Rules' ) ) {
			return BizCity_Brain_Standing_Rules::streak_days( (array) $rows, gmdate( 'Y-m-d' ) );
		}
		$streak = 0;
		$cursor = new DateTime( 'today', new DateTimeZone( 'UTC' ) );
		foreach ( (array) $rows as $day ) {
			$d = new DateTime( $day, new DateTimeZone( 'UTC' ) );
			if ( $d->format( 'Y-m-d' ) === $cursor->format( 'Y-m-d' ) ) {
				++$streak;
				$cursor->modify( '-1 day' );
			} elseif ( $streak === 0 && $d < $cursor ) {
				// no activity today yet — allow the streak to still start from yesterday
				$cursor->modify( '-1 day' );
				if ( $d->format( 'Y-m-d' ) === $cursor->format( 'Y-m-d' ) ) {
					++$streak;
					$cursor->modify( '-1 day' );
				} else {
					break;
				}
			} else {
				break;
			}
		}
		return $streak;
	}

	private static function last_activity_at( string $account_id, string $role ) {
		global $wpdb;
		$ledger = BizCity_Usage_Schema::ledger_table();
		return $wpdb->get_var( $wpdb->prepare(
			"SELECT MAX(occurred_at) FROM {$ledger} WHERE account_id = %s AND actor_role = %s", $account_id, $role
		) );
	}

	private static function existing_born_at( string $account_id ) {
		global $wpdb;
		$metrics = BizCity_Brain_Journey_Schema::metrics_table();
		$val = $wpdb->get_var( $wpdb->prepare( "SELECT born_at FROM {$metrics} WHERE account_id = %s", $account_id ) );
		return ( null === $val || '' === $val ) ? null : $val;
	}

	/** @return array<string,bool> code => true, for every event already written for this subject. */
	private static function existing_event_codes( string $account_id ): array {
		global $wpdb;
		$journey = BizCity_Brain_Journey_Schema::journey_table();
		$codes = $wpdb->get_col( $wpdb->prepare(
			"SELECT code FROM {$journey} WHERE subject_kind = 'brain' AND subject_id = %s", $account_id
		) );
		return array_fill_keys( (array) $codes, true );
	}

	private static function save_metrics( string $account_id, array $m ): void {
		global $wpdb;
		$table = BizCity_Brain_Journey_Schema::metrics_table();
		$wpdb->replace( $table, array(
			'account_id'             => $account_id,
			'platform'               => $m['platform'],
			'owner_user_id'          => $m['owner_user_id'],
			'customer_replies'       => $m['customer_replies'],
			'distinct_customers'     => $m['distinct_customers'],
			'customer_replies_month'   => $m['customer_replies_month'],
			'distinct_customers_month' => $m['distinct_customers_month'],
			'distinct_groups'        => $m['distinct_groups'],
			'tasks_done'             => $m['tasks_done'],
			'trained_items'          => $m['trained_items'],
			'streak_days'            => $m['streak_days'],
			'billed_tokens_month'    => $m['billed_tokens_month'],
			'chi_usd_month'          => $m['chi_usd_month'],
			'thu_usd_month'          => $m['thu_usd_month'],
			'ai_enabled'             => $m['ai_enabled'],
			'last_customer_reply_at' => $m['last_customer_reply_at'],
			'born_at'                => $m['born_at'],
			'active'                 => $m['active'],
			'computed_at'            => self::now_mysql(),
		) );
	}

	/* ================================================================
	 *  Append-only write with hash chain (master plan §3.4 — same shape as the cell's token_ledger, 0.107/60 §3)
	 * ================================================================ */

	/**
	 * @param array{subject_kind:string,subject_id:string,owner_user_id:int,event:string,code:string,tier:string,value:int,occurred_at:string,source:string,source_ref:string} $row
	 */
	public static function write_event( array $row ): bool {
		global $wpdb;
		$journey = BizCity_Brain_Journey_Schema::journey_table();
		$heads   = BizCity_Brain_Journey_Schema::heads_table();
		$dedupe  = $row['subject_kind'] . ':' . $row['subject_id'] . ':' . $row['event'] . ':' . $row['code'];

		if ( (int) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$journey} WHERE dedupe_key = %s", $dedupe ) ) === 1 ) {
			return false; // already recorded — luật 2, never re-written, and T108-5 needs this to be a safe no-op.
		}

		// Guarantee a lockable heads row exists before the transaction (first writer for a brand-new subject).
		$wpdb->query( $wpdb->prepare(
			"INSERT IGNORE INTO {$heads} (subject_kind, subject_id, last_seq, last_hash, updated_at) VALUES (%s, %s, 0, '', %s)",
			$row['subject_kind'], $row['subject_id'], self::now_mysql()
		) );

		$wpdb->query( 'START TRANSACTION' );
		$dup = $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$journey} WHERE dedupe_key = %s FOR UPDATE", $dedupe ) );
		if ( $dup ) {
			$wpdb->query( 'COMMIT' );
			return false;
		}
		$head = $wpdb->get_row( $wpdb->prepare(
			"SELECT last_seq, last_hash FROM {$heads} WHERE subject_kind = %s AND subject_id = %s FOR UPDATE",
			$row['subject_kind'], $row['subject_id']
		), ARRAY_A );
		$prev_seq  = $head ? (int) $head['last_seq'] : 0;
		$prev_hash = $head ? (string) $head['last_hash'] : '';
		$seq       = $prev_seq + 1;

		$canonical = wp_json_encode( array(
			'seq' => $seq, 'subject_kind' => $row['subject_kind'], 'subject_id' => $row['subject_id'],
			'event' => $row['event'], 'code' => $row['code'], 'tier' => $row['tier'], 'value' => (int) $row['value'],
			'occurred_at' => $row['occurred_at'],
		) );
		$row_hash = hash( 'sha256', $prev_hash . $canonical );

		$ok = $wpdb->insert( $journey, array(
			'seq'             => $seq,
			'occurred_at'     => $row['occurred_at'],
			'subject_kind'    => $row['subject_kind'],
			'subject_id'      => $row['subject_id'],
			'owner_user_id'   => $row['owner_user_id'],
			'event'           => $row['event'],
			'code'            => $row['code'],
			'tier'            => $row['tier'],
			'value'           => $row['value'],
			'catalog_version' => BizCity_Brain_Milestones_Catalog::catalog_version(),
			'source'          => $row['source'],
			'source_ref'      => $row['source_ref'],
			'dedupe_key'      => $dedupe,
			'prev_hash'       => $prev_hash,
			'row_hash'        => $row_hash,
			'created_at'      => self::now_mysql(),
		) );
		if ( false === $ok ) {
			$wpdb->query( 'ROLLBACK' );
			return false;
		}
		if ( $head ) {
			$wpdb->update( $heads, array( 'last_seq' => $seq, 'last_hash' => $row_hash, 'updated_at' => self::now_mysql() ),
				array( 'subject_kind' => $row['subject_kind'], 'subject_id' => $row['subject_id'] ) );
		} else {
			$wpdb->insert( $heads, array( 'subject_kind' => $row['subject_kind'], 'subject_id' => $row['subject_id'], 'last_seq' => $seq, 'last_hash' => $row_hash, 'updated_at' => self::now_mysql() ) );
		}
		$wpdb->query( 'COMMIT' );
		return true;
	}

	/** Re-walk one subject's chain and confirm every row_hash matches (T108-5). */
	public static function verify_chain( string $subject_kind, string $subject_id ): bool {
		global $wpdb;
		$journey = BizCity_Brain_Journey_Schema::journey_table();
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT seq, event, code, tier, value, occurred_at, prev_hash, row_hash FROM {$journey}
			 WHERE subject_kind = %s AND subject_id = %s ORDER BY seq ASC", $subject_kind, $subject_id
		), ARRAY_A );
		$prev = '';
		foreach ( (array) $rows as $r ) {
			if ( (string) $r['prev_hash'] !== $prev ) {
				return false;
			}
			$canonical = wp_json_encode( array(
				'seq' => (int) $r['seq'], 'subject_kind' => $subject_kind, 'subject_id' => $subject_id,
				'event' => $r['event'], 'code' => $r['code'], 'tier' => $r['tier'], 'value' => (int) $r['value'],
				'occurred_at' => $r['occurred_at'],
			) );
			if ( hash( 'sha256', $prev . $canonical ) !== (string) $r['row_hash'] ) {
				return false;
			}
			$prev = (string) $r['row_hash'];
		}
		return true;
	}

	/* ================================================================
	 *  Small helpers
	 * ================================================================ */

	private static function owner_of( string $account_id ): array {
		if ( isset( self::$readers['owner_of'] ) ) {
			return (array) call_user_func( self::$readers['owner_of'], $account_id );
		}
		if ( class_exists( 'BizCity_Zalo_Mapping_Repo' ) ) {
			$row = (array) BizCity_Zalo_Mapping_Repo::find_account_by_bridge_id( 'personal', $account_id );
			return array( 'owner_user_id' => (int) ( $row['owner_user_id'] ?? 0 ), 'status' => (string) ( $row['status'] ?? '' ) );
		}
		return array( 'owner_user_id' => 0, 'status' => '' );
	}

	/** @return array<int,string> every account_id this blog has a ledger or mirror row for (hourly sweep). */
	private static function accounts(): array {
		if ( isset( self::$readers['accounts'] ) ) {
			return (array) call_user_func( self::$readers['accounts'] );
		}
		global $wpdb;
		$ledger = BizCity_Usage_Schema::ledger_table();
		return (array) $wpdb->get_col( "SELECT DISTINCT account_id FROM {$ledger} WHERE account_id NOT LIKE 'web-%'" );
	}

	private static function now_mysql(): string {
		return isset( self::$readers['now'] ) ? call_user_func( self::$readers['now'] ) : current_time( 'mysql', true );
	}
}
