<?php
/**
 * Client plans per ACCOUNT ID — trial / pro (PHASE-0.107 S107-L14 + L17, doc 66 §8, §10; round 5: the SITE does everything about plans).
 *
 * Table `{prefix}bizcity_account_plans` (BizCity_Usage_Schema). The cell only MEASURES tokens (it holds no plan, gate or notice). Used tokens
 * are SUM(billed_tokens) of THIS site's own bizcity_usage_ledger (llm rows) over the plan window (`used_for`). A plan only GATES access, and
 * BizCity_Usage_Plan_Enforce does that by flipping the number's existing AI switch. `plan_mode` is `off` for every number until someone turns
 * it on: no row, no plan, no block. Not BizCity_Plans, not master_level, no column added to the Hub mirror `bizcity_zalo_accounts`.
 *
 * State machine (66 §8.1):  none ──set plan──▶ trial ──(valid_until passes | allowance reached)──▶ expired ──pro paid──▶ active(+1 month)
 *                                                                    active ──(valid_until passes)──▶ expired
 * Every change bumps `plan_version` (kept as a cheap monotonic revision counter) and the caller runs the enforcement.
 *
 * Trial (66 §8.2): the clock starts when the number FIRST logs in (status connected + zalo_uid), NOT when the row is created. Source of
 * the start: the action `bizcity_zalo_account_connected` fired by BizCity_Zalo_Mapping_Repo (update_account_status → connected, and
 * sync_zalo_uids when the uid arrives); the daily cron and the plans list also start any pending trial lazily (idempotent).
 * 300.000 billed tokens for the whole trial (allowance_period `since_start`), 3 days; at most `bizcity_plan_trial_cap` (default 5)
 * RUNNING trials per site — beyond it a trial is refused with error `trial_cap`.
 *
 * Default Pro allowance (66 §10), BILLED tokens:  real tokens R cost the wallet  P × R/1e6 × 1.3 USD  and bill  R × 1.3  tokens, so
 *   allowance = (price_vnd × (1 − margin) / rate) / (P × 1.3) × 1.3 × 1e6  =  price_vnd × (1 − margin) / rate / P × 1e6
 * with P = option `bizcity_plan_ref_price_per_mtok` (default 1.20 USD per 1M real tokens, hand-editable), margin = option
 * `bizcity_plan_margin_pct` (default 50), price = option `bizcity_plan_pro_price_vnd` (default 200000), rate = the Hub rate of
 * wallet-view (usd_vnd_rate) or, when null, option `bizcity_plan_manual_usd_vnd_rate`. No rate ⇒ 0 (the dialog then asks for a number).
 *
 * // [2026-10-13 02:28 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.107 S107-L14 / L17 — plan class, formula, trial rules, daily expiry cron.
 *
 * @package BizCity_Twin_AI
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_Usage_Plans {

	const PLATFORM         = 'zalo_personal';
	const TRIAL_DAYS       = 3;
	const TRIAL_ALLOWANCE  = 300000;
	const OPT_REF_PRICE    = 'bizcity_plan_ref_price_per_mtok';
	const OPT_MARGIN       = 'bizcity_plan_margin_pct';
	const OPT_PRO_PRICE    = 'bizcity_plan_pro_price_vnd';
	const OPT_MANUAL_RATE  = 'bizcity_plan_manual_usd_vnd_rate';
	const OPT_TRIAL_CAP    = 'bizcity_plan_trial_cap';
	const CRON_HOOK        = 'bizcity_usage_plans_daily';
	const ACTION_CONNECTED = 'bizcity_zalo_account_connected';
	const STATES           = array( 'none', 'trial', 'active', 'expired' );

	/**
	 * Test seams: option(name, default) · now() · rate() · get(platform, id) · save(row) · count_trials(now_s) · pending_trials() ·
	 * due(now_s) · connected(id) · query(sql, args): rows (used-token SUMs) · tz() · enforce(platform, id) · enforceable() · list_enforce(rows)
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
		add_action( self::CRON_HOOK, array( __CLASS__, 'daily' ) );
		add_action( self::ACTION_CONNECTED, array( __CLASS__, 'on_connected' ), 10, 1 );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
	}

	public static function schedule(): void {
		if ( function_exists( 'wp_next_scheduled' ) && ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 600, 'daily', self::CRON_HOOK );
		}
	}

	/* ── pure rules (unit-tested) ──────────────────────────────────── */

	/**
	 * Default allowance in BILLED tokens (see the class comment for the derivation). 0 when the rate or price is unusable.
	 */
	public static function allowance_formula( float $price_vnd, float $margin_pct, ?float $rate, float $price_per_mtok ): int {
		if ( null === $rate || $rate <= 0 || $price_per_mtok <= 0 || $price_vnd <= 0 ) {
			return 0;
		}
		$margin = min( 0.99, max( 0.0, $margin_pct / 100 ) );
		return (int) floor( ( $price_vnd * ( 1 - $margin ) / $rate ) / $price_per_mtok * 1000000 );
	}

	public static function default_pro_allowance(): int {
		return self::allowance_formula(
			(float) self::option( self::OPT_PRO_PRICE, 200000 ),
			(float) self::option( self::OPT_MARGIN, 50 ),
			self::rate(),
			(float) self::option( self::OPT_REF_PRICE, 1.20 )
		);
	}

	/** The Hub rate of wallet-view, else the hand-entered fallback option, else null. */
	public static function rate(): ?float {
		if ( isset( self::$readers['rate'] ) ) {
			$r = call_user_func( self::$readers['rate'] );
			return ( is_numeric( $r ) && (float) $r > 0 ) ? (float) $r : null;
		}
		$r = class_exists( 'BizCity_Usage_Wallet' ) ? BizCity_Usage_Wallet::rate() : null;
		if ( null !== $r ) {
			return $r;
		}
		$m = (float) self::option( self::OPT_MANUAL_RATE, 0 );
		return $m > 0 ? $m : null;
	}

	public static function trial_cap(): int {
		return max(0, (int) self::option( self::OPT_TRIAL_CAP, 5 ));
	}

	/** Blank row for a number that has no plan yet (plan_mode off ⇒ nothing is ever blocked). */
	public static function blank( string $platform, string $account ): array {
		return array(
			'platform' => $platform, 'account_id' => $account, 'plan_code' => '', 'state' => 'none', 'plan_mode' => 'off', 'plan_version' => 0,
			'started_at' => null, 'valid_until' => null, 'monthly_token_allowance' => 0, 'allowance_period' => 'month',
			'notice_text' => '', 'ai_off_by_plan' => '', 'ai_off_at' => null, 'enforce_error' => '', 'last_notice_at' => null, 'last_notice_reason' => '',
			'last_order_id' => 0, 'last_sold_by' => 0, 'updated_by' => 0,
		);
	}

	/** Expiry is a pure function of (row, now): an active/trial plan whose valid_until has passed is `expired`. */
	public static function effective_state( array $row, int $now ): string {
		$state = (string) ( $row['state'] ?? 'none' );
		if ( in_array( $state, array( 'trial', 'active' ), true ) && ! empty( $row['valid_until'] ) && self::ts( $row['valid_until'] ) <= $now ) {
			return 'expired';
		}
		return in_array( $state, self::STATES, true ) ? $state : 'none';
	}

	/* ── commands ──────────────────────────────────────────────────── */

	/**
	 * Set a plan. args: plan_code, months (1|3|12 — added to max(now, current valid_until)) | valid_until (UTC timestamp),
	 * allowance (int; omitted ⇒ default of the plan), notice_text (extra line of the owner notice), plan_mode (default 'on').
	 *
	 * @return array|WP_Error the saved row
	 */
	public static function set_plan( string $platform, string $account, array $args, int $actor = 0 ) {
		$code = sanitize_key( (string) ( $args['plan_code'] ?? '' ) );
		if ( '' === $code || strlen( $code ) > 32 ) {
			return new WP_Error( 'invalid_plan', 'plan_code is required (trial, pro or another short code).' );
		}
		$now = self::now();
		$row = self::get( $platform, $account ) ?? self::blank( $platform, $account );

		if ( 'trial' === $code ) {
			if ( 'trial' !== $row['state'] || self::effective_state( $row, $now ) !== 'trial' ) {
				$cap = self::trial_cap();
				if ( self::count_trials( $now ) >= $cap ) {
					return new WP_Error( 'trial_cap', sprintf( 'Đã có %d số đang dùng thử (tối đa %d). Hãy chờ một số hết hạn hoặc nâng lên Pro.', self::count_trials( $now ), $cap ) );
				}
				$row['started_at'] = null; // a (re)started trial counts from the first login
				$row['valid_until'] = null;
			}
			$row['state']            = 'trial';
			$row['allowance_period'] = 'since_start';
			$row['monthly_token_allowance'] = isset( $args['allowance'] ) ? max( 0, (int) $args['allowance'] ) : ( $row['monthly_token_allowance'] > 0 && 'trial' === $row['plan_code'] ? (int) $row['monthly_token_allowance'] : self::TRIAL_ALLOWANCE );
			if ( empty( $row['started_at'] ) && self::connected( $account ) ) {
				$row['started_at'] = self::fmt( $now );
			}
			if ( isset( $args['valid_until'] ) && (int) $args['valid_until'] > 0 ) {
				$row['valid_until'] = self::fmt( (int) $args['valid_until'] );
			} elseif ( ! empty( $row['started_at'] ) && empty( $row['valid_until'] ) ) {
				$row['valid_until'] = self::fmt( self::ts( $row['started_at'] ) + self::TRIAL_DAYS * 86400 );
			}
		} else {
			$was_trial = 'trial' === $row['plan_code'];
			$row['state']            = 'active';
			$row['allowance_period'] = 'month';
			if ( isset( $args['allowance'] ) ) {
				$row['monthly_token_allowance'] = max( 0, (int) $args['allowance'] );
			} elseif ( $was_trial || $row['monthly_token_allowance'] <= 0 || $code !== $row['plan_code'] ) {
				$row['monthly_token_allowance'] = 'pro' === $code ? self::default_pro_allowance() : (int) $row['monthly_token_allowance'];
			}
			// [2026-10-13 02:43 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.107 S107-L14 — the cell reads allowance 0 as UNLIMITED. A Pro plan must never become
			// unlimited by accident (Hub rate not entered ⇒ formula 0): refuse unless the manager explicitly passes `unlimited`.
			if ( 'pro' === $code && (int) $row['monthly_token_allowance'] <= 0 && empty( $args['unlimited'] ) ) {
				return new WP_Error( 'allowance_required', 'Chưa tính được hạn mức token mặc định (thiếu tỷ giá Hub / giá tham chiếu). Hãy nhập hạn mức token, hoặc chọn "Không giới hạn".' );
			}
			if ( empty( $row['started_at'] ) || $was_trial ) {
				$row['started_at'] = self::fmt( $now );
			}
			if ( isset( $args['valid_until'] ) && (int) $args['valid_until'] > 0 ) {
				$row['valid_until'] = self::fmt( (int) $args['valid_until'] );
			} elseif ( isset( $args['months'] ) && (int) $args['months'] > 0 ) {
				$row['valid_until'] = self::add_months( max( $now, self::ts( $row['valid_until'] ) ), (int) $args['months'] );
			} elseif ( empty( $row['valid_until'] ) ) {
				return new WP_Error( 'invalid_validity', 'Chọn thời hạn (+1 / +3 / +12 tháng hoặc một ngày cụ thể).' );
			}
		}
		$row['plan_code'] = $code;
		$row['plan_mode'] = ( isset( $args['plan_mode'] ) && 'off' === $args['plan_mode'] ) ? 'off' : 'on';
		if ( isset( $args['notice_text'] ) ) {
			$row['notice_text'] = self::clean_text( (string) $args['notice_text'], 500 );
		}
		if ( ! empty( $row['valid_until'] ) && self::ts( $row['valid_until'] ) <= $now ) {
			$row['state'] = 'expired';
		}
		return self::commit( $row, $actor );
	}

	/** Turn gating on/off without touching dates or allowance. Off = the cell blocks nothing for this number. */
	public static function set_mode( string $platform, string $account, bool $on, int $actor = 0 ) {
		$row = self::get( $platform, $account ) ?? self::blank( $platform, $account );
		$new = $on ? 'on' : 'off';
		if ( $row['plan_mode'] === $new && $row['plan_version'] > 0 ) {
			return $row;
		}
		$row['plan_mode'] = $new;
		return self::commit( $row, $actor );
	}

	/** Bump the version, stamp who, save. */
	public static function commit( array $row, int $actor = 0 ) {
		$row['plan_version'] = (int) $row['plan_version'] + 1;
		$row['updated_by']   = $actor;
		return self::save( $row );
	}

	/**
	 * Woo helper (L16): a paid Pro order extends the plan one month from max(now, valid_until); state active, plan_mode on.
	 *
	 * @return array|WP_Error
	 */
	/**
	 * @param int      $months    0 = use the hardcoded 1-month default (kept for backward compatibility with
	 *                            orders that carry no package meta — S108-L18, 20-REVENUE-PER-NUMBER.md §3.3 point 1).
	 * @param int|null $allowance null = use the usual formula-derived default; a real value overrides it.
	 */
	public static function extend_pro( string $platform, string $account, int $order_id, int $sold_by, int $months = 0, ?int $allowance = null ) {
		$months = $months > 0 ? $months : 1;
		$plan_args = array( 'plan_code' => 'pro', 'months' => $months, 'plan_mode' => 'on' );
		if ( null !== $allowance ) {
			$plan_args['allowance'] = $allowance;
		}
		$r = self::set_plan( $platform, $account, $plan_args, $sold_by );
		if ( is_wp_error( $r ) && 'allowance_required' === $r->get_error_code() ) {
			// [2026-10-13 02:43 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.107 S107-L16 — the customer already PAID: never drop the order. Apply Pro, but say loudly that
			// the allowance is missing (allowance 0 = unlimited for the enforcer until a manager sets one).
			do_action( 'bizcity_usage_plan_allowance_missing', $platform, $account, $order_id );
			if ( function_exists( 'error_log' ) ) { error_log( '[BizCity][USAGE-PLAN] Pro applied WITHOUT a token allowance (order ' . $order_id . ', account ' . $account . ') - set bizcity_plan_manual_usd_vnd_rate or the allowance.' ); }
			$r = self::set_plan( $platform, $account, array( 'plan_code' => 'pro', 'months' => $months, 'plan_mode' => 'on', 'unlimited' => true ), $sold_by );
		}
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		$r['last_order_id'] = $order_id;
		$r['last_sold_by']  = $sold_by;
		return self::save( $r ); // same version: the sale record is bookkeeping, not a plan change
	}

	/* ── trial start (L17) ─────────────────────────────────────────── */

	/** Start a pending trial the moment the number is logged in. Idempotent. @return array|null the started row */
	public static function maybe_start( string $platform, string $account ): ?array {
		$row = self::get( $platform, $account );
		if ( ! $row || 'trial' !== $row['state'] || ! empty( $row['started_at'] ) || ! self::connected( $account ) ) {
			return null;
		}
		$now = self::now();
		$row['started_at']  = self::fmt( $now );
		$row['valid_until'] = self::fmt( $now + self::TRIAL_DAYS * 86400 );
		$saved = self::commit( $row, 0 );
		return is_array( $saved ) ? $saved : null;
	}

	/** Action `bizcity_zalo_account_connected( $bridge_account_id )`. */
	public static function on_connected( $bridge_id ): void {
		$id = sanitize_text_field( (string) $bridge_id );
		if ( '' === $id ) {
			return;
		}
		if ( self::maybe_start( self::PLATFORM, $id ) ) {
			self::enforce( self::PLATFORM, $id );
		}
	}

	/**
	 * Cron: start pending trials, mark what is past valid_until `expired`, then enforce every plan_mode=on number (and any number we
	 * switched off, so a renewal / mode-off brings it back). Idempotent. @return array{started:int,expired:int}
	 */
	public static function daily(): array {
		$now     = self::now();
		$started = 0;
		$expired = 0;
		foreach ( self::pending_trials() as $r ) {
			if ( self::maybe_start( (string) $r['platform'], (string) $r['account_id'] ) ) {
				++$started;
			}
		}
		foreach ( self::due( $now ) as $r ) {
			$r['state'] = 'expired';
			if ( is_array( self::commit( $r, 0 ) ) ) {
				++$expired;
			}
		}
		foreach ( self::enforceable() as $r ) {
			self::enforce( (string) $r['platform'], (string) $r['account_id'] );
		}
		return array( 'started' => $started, 'expired' => $expired );
	}

	/* ── used tokens (round 5: from THIS site's own ledger) ────────── */

	/**
	 * Start of the counting window as a UTC 'Y-m-d H:i:s': `month` = 00:00 of the first day of the CURRENT calendar month in the site
	 * timezone; `since_start` = started_at (null while a trial has not started: nothing counts yet).
	 */
	public static function window_start( array $row, int $now, DateTimeZone $tz ): ?string {
		if ( 'since_start' === ( $row['allowance_period'] ?? 'month' ) ) {
			return empty( $row['started_at'] ) ? null : (string) $row['started_at'];
		}
		$local = new DateTime( '@' . $now );
		$local->setTimezone( $tz );
		$first = new DateTime( $local->format( 'Y-m-01' ) . ' 00:00:00', $tz );
		return gmdate( 'Y-m-d H:i:s', $first->getTimestamp() );
	}

	/**
	 * Used billed tokens of many accounts in ONE grouped, indexed query per 100 accounts:
	 * SUM(billed_tokens) of this site's bizcity_usage_ledger, kind llm, per account from its own window start (index account_id + occurred_at).
	 *
	 * @param array<int,array> $rows plan rows (account_id, allowance_period, started_at)
	 * @return array<string,int> account_id ⇒ tokens (0 when nothing counted)
	 */
	public static function used_for( array $rows, ?int $now = null ): array {
		$now = $now ?? self::now();
		$tz  = self::tz();
		$out = array();
		$terms = array();
		foreach ( $rows as $r ) {
			$id = (string) ( $r['account_id'] ?? '' );
			if ( '' === $id ) {
				continue;
			}
			$out[ $id ] = 0;
			$from = self::window_start( $r, $now, $tz );
			if ( null !== $from ) {
				$terms[ $id ] = $from;
			}
		}
		foreach ( array_chunk( $terms, 100, true ) as $chunk ) {
			$where = array();
			$args  = array();
			foreach ( $chunk as $id => $from ) {
				$where[] = '(account_id = %s AND occurred_at >= %s)';
				$args[]  = (string) $id;
				$args[]  = $from;
			}
			$sql = 'SELECT account_id, SUM(billed_tokens) AS used FROM ' . self::ledger_table() . " WHERE kind = 'llm' AND (" . implode( ' OR ', $where ) . ') GROUP BY account_id';
			foreach ( self::query( $sql, $args ) as $q ) {
				$out[ (string) $q['account_id'] ] = max( 0, (int) $q['used'] );
			}
		}
		return $out;
	}

	/** Used billed tokens of one plan row. */
	public static function used( array $row, ?int $now = null ): int {
		$m = self::used_for( array( $row ), $now );
		return (int) ( $m[ (string) $row['account_id'] ] ?? 0 );
	}

	/** Plan rows the enforcer must look at: plan_mode on, or a number we switched off (it may need switching back on). */
	public static function enforceable(): array {
		if ( isset( self::$readers['enforceable'] ) ) {
			return (array) call_user_func( self::$readers['enforceable'] );
		}
		global $wpdb;
		$t = self::table();
		return (array) $wpdb->get_results( "SELECT * FROM {$t} WHERE plan_mode = 'on' OR ai_off_by_plan <> '' LIMIT 2000", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/* ── store (seamed) ────────────────────────────────────────────── */

	public static function get( string $platform, string $account ): ?array {
		if ( isset( self::$readers['get'] ) ) {
			$r = call_user_func( self::$readers['get'], $platform, $account );
			return is_array( $r ) ? $r : null;
		}
		global $wpdb;
		$t = self::table();
		$r = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE platform = %s AND account_id = %s", $platform, $account ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		return is_array( $r ) ? $r : null;
	}

	/** @param array<int,array{0:string,1:string}> $keys @return array<int,array> rows (blank rows for unknown keys) */
	public static function get_many( array $keys ): array {
		$out = array();
		foreach ( $keys as $k ) {
			$out[] = self::get( $k[0], $k[1] ) ?? self::blank( $k[0], $k[1] );
		}
		return $out;
	}

	/** @return array|WP_Error */
	public static function save( array $row ) {
		if ( isset( self::$readers['save'] ) ) {
			return call_user_func( self::$readers['save'], $row );
		}
		global $wpdb;
		$t   = self::table();
		$now = self::fmt( self::now() );
		$row['updated_at'] = $now;
		$row['created_at'] = $row['created_at'] ?? $now;
		unset( $row['id'] );
		$cols = array_keys( $row );
		$vals = array();
		$mark = array();
		foreach ( $row as $v ) {
			if ( null === $v ) {
				$mark[] = 'NULL';
				continue;
			}
			$mark[] = is_int( $v ) ? '%d' : '%s';
			$vals[] = $v;
		}
		$upd = array();
		foreach ( $cols as $c ) {
			if ( ! in_array( $c, array( 'platform', 'account_id', 'created_at' ), true ) ) {
				$upd[] = "`{$c}` = VALUES(`{$c}`)";
			}
		}
		$sql = "INSERT INTO {$t} (`" . implode( '`,`', $cols ) . '`) VALUES (' . implode( ',', $mark ) . ') ON DUPLICATE KEY UPDATE ' . implode( ',', $upd );
		if ( false === $wpdb->query( $wpdb->prepare( $sql, $vals ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL
			return new WP_Error( 'db_error', 'Không lưu được gói.' );
		}
		return self::get( $row['platform'], $row['account_id'] ) ?? $row;
	}

	/** Running trials: state trial and not past valid_until (valid_until NULL = waiting for the first login, still counts). */
	public static function count_trials( int $now ): int {
		if ( isset( self::$readers['count_trials'] ) ) {
			return (int) call_user_func( self::$readers['count_trials'], self::fmt( $now ) );
		}
		global $wpdb;
		$t = self::table();
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE state = 'trial' AND (valid_until IS NULL OR valid_until > %s)", self::fmt( $now ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	private static function pending_trials(): array {
		if ( isset( self::$readers['pending_trials'] ) ) {
			return (array) call_user_func( self::$readers['pending_trials'] );
		}
		global $wpdb;
		$t = self::table();
		return (array) $wpdb->get_results( "SELECT * FROM {$t} WHERE state = 'trial' AND started_at IS NULL LIMIT 500", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	private static function due( int $now ): array {
		if ( isset( self::$readers['due'] ) ) {
			return (array) call_user_func( self::$readers['due'], self::fmt( $now ) );
		}
		global $wpdb;
		$t = self::table();
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE state IN ('trial','active') AND valid_until IS NOT NULL AND valid_until <= %s LIMIT 500", self::fmt( $now ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/** The plans table name; makes sure the schema exists first (REST / cron / hooks do not pass through admin_init). */
	private static function table(): string {
		BizCity_Usage_Schema::maybe_install();
		return BizCity_Usage_Schema::plans_table();
	}

	/** Logged in = Hub mirror says connected and the Zalo uid is known. */
	public static function connected( string $account ): bool {
		if ( isset( self::$readers['connected'] ) ) {
			return (bool) call_user_func( self::$readers['connected'], $account );
		}
		$acc = class_exists( 'BizCity_Zalo_Mapping_Repo' ) ? BizCity_Zalo_Mapping_Repo::find_account_by_bridge_id( 'personal', $account ) : null;
		return is_array( $acc ) && 'connected' === (string) ( $acc['status'] ?? '' ) && '' !== trim( (string) ( $acc['zalo_uid'] ?? '' ) );
	}

	/** Enforce one number now (see BizCity_Usage_Plan_Enforce). */
	private static function enforce( string $platform, string $account ): void {
		if ( isset( self::$readers['enforce'] ) ) {
			call_user_func( self::$readers['enforce'], $platform, $account );
			return;
		}
		if ( class_exists( 'BizCity_Usage_Plan_Enforce' ) ) {
			BizCity_Usage_Plan_Enforce::apply( $platform, $account );
		}
	}

	private static function ledger_table(): string {
		return BizCity_Usage_Schema::ledger_table();
	}

	/** @return array<int,array<string,mixed>> */
	private static function query( string $sql, array $args ): array {
		if ( isset( self::$readers['query'] ) ) {
			return (array) call_user_func( self::$readers['query'], $sql, $args );
		}
		global $wpdb;
		BizCity_Usage_Schema::maybe_install();
		$r = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		return is_array( $r ) ? $r : array();
	}

	public static function tz(): DateTimeZone {
		if ( isset( self::$readers['tz'] ) ) {
			return call_user_func( self::$readers['tz'] );
		}
		return function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
	}

	/* ── helpers ───────────────────────────────────────────────────── */

	public static function now(): int {
		return isset( self::$readers['now'] ) ? (int) call_user_func( self::$readers['now'] ) : time();
	}

	public static function fmt( int $ts ): string {
		return gmdate( 'Y-m-d H:i:s', $ts );
	}

	/** 'Y-m-d H:i:s' UTC ⇒ timestamp (0 for empty / NULL). */
	public static function ts( $s ): int {
		if ( empty( $s ) ) {
			return 0;
		}
		$t = strtotime( (string) $s . ' UTC' );
		return false === $t ? 0 : (int) $t;
	}

	/** +N calendar months (clamped like WooCommerce: Jan 31 + 1 month = Feb 28/29), time of day kept. */
	public static function add_months( int $ts, int $months ): string {
		$d   = new DateTime( '@' . $ts );
		$day = (int) $d->format( 'j' );
		$d->modify( 'first day of this month' );
		$d->modify( '+' . $months . ' months' );
		$d->setDate( (int) $d->format( 'Y' ), (int) $d->format( 'n' ), min( $day, (int) $d->format( 't' ) ) );
		return $d->format( 'Y-m-d H:i:s' );
	}

	private static function clean_text( string $s, int $max ): string {
		$s = function_exists( 'sanitize_textarea_field' ) ? sanitize_textarea_field( $s ) : trim( strip_tags( $s ) );
		return function_exists( 'mb_substr' ) ? mb_substr( $s, 0, $max ) : substr( $s, 0, $max );
	}

	public static function option( string $name, $default ) {
		if ( isset( self::$readers['option'] ) ) {
			return call_user_func( self::$readers['option'], $name, $default );
		}
		return function_exists( 'get_option' ) ? get_option( $name, $default ) : $default;
	}
}
