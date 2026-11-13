<?php
/**
 * Daily Zalo bulletins of the "Thành tích" tab (PHASE-0.108 S108-L7, 10-SPEC §6).
 *
 *  • Bản tin ngày → each number OWNER, over Zalo 1-1 (the number's own transport, to the owner uid — same path as the plan notices):
 *    ONE merged message per owner however many numbers they have. Sent once a day at the site's `digest_time` (default 07:30).
 *  • Bản tin tổng → ONE message to the site admin who owns a number: top 3, how many numbers are asleep, this month's revenue / token cost.
 *
 * Rules (10-SPEC §6): no activity yesterday AND no new milestone ⇒ NOTHING is sent (no "hôm qua 0 khách" spam) — except the admin
 * bulletin when a number has been asleep ≥ 3 days. New milestones are folded into the next bulletin, never sent alone. Each message carries an
 * idempotency key (`brain-digest:<uid>:<Ymd>`), so a retried tick never sends twice. The owner's own switch (user meta) and the site switch
 * (option) are both honoured. "Làm thay" is always worded "khoảng" and never added to money.
 *
 * Composition is PURE (unit-tested); the DB reads sit behind `$readers` seams; sending reuses BizCity_Usage_Plan_Notices::send_text().
 *
 * // [2026-10-14 03:00 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.108 S108-L7.
 *
 * @package BizCity_Twin_AI
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_Brain_Digest {

	const CRON_HOOK   = 'bizcity_brain_digest_tick';
	const META_LAST   = 'bizcity_brain_digest_last';   // user meta: Ymd (site tz) of the last bulletin handled
	const META_SINCE  = 'bizcity_brain_digest_since';  // user meta: UTC datetime — milestones after this are "new"
	const OPT_ADMIN_LAST = 'bizcity_brain_digest_admin_last';
	const SLEEP_DAYS  = 3;

	const TIER_LABEL = array( 'bronze' => 'Đồng', 'silver' => 'Bạc', 'gold' => 'Vàng', 'diamond' => 'Kim cương' );

	private static $initialized = false;

	/**
	 * Test seams: now() → int · tz() → DateTimeZone · owners() · accounts_of(uid) · facts(account, ymd) · metrics(account) · new_milestones(uid, since) ·
	 * all_facts(ymd) · day_summary(ymd) · brains() · points(accounts, ymd) · sleeping() · send(uid, text, idem): bool · admin_uid() · log(msg, ctx)
	 *
	 * @var array<string,callable>
	 */
	public static $readers = array();

	public static function init(): void {
		if ( self::$initialized ) {
			return;
		}
		self::$initialized = true;
		add_action( self::CRON_HOOK, array( __CLASS__, 'tick' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
	}

	public static function schedule(): void {
		if ( function_exists( 'wp_next_scheduled' ) && ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 600, 'hourly', self::CRON_HOOK );
		}
	}

	/* ================================================================
	 *  Pure
	 * ================================================================ */

	/** True when the send time has passed today and this bulletin has not been handled today. */
	public static function due( string $now_hm, string $send_hm, string $last_ymd, string $today_ymd ): bool {
		return $now_hm >= $send_hm && $last_ymd !== $today_ymd;
	}

	/** "Trả lời · Bạc" from a milestone code like replies.silver using the catalog's group labels. */
	public static function milestone_label( string $code ): string {
		$parts = explode( '.', $code, 2 );
		$group = $parts[0];
		$tier  = $parts[1] ?? '';
		$label = $group;
		if ( class_exists( 'BizCity_Brain_Milestones_Catalog' ) ) {
			foreach ( BizCity_Brain_Milestones_Catalog::active_groups() as $g ) {
				if ( ( $g['code'] ?? '' ) === $group ) {
					$label = (string) ( $g['label'] ?? $group );
					foreach ( (array) ( $g['tiers'] ?? array() ) as $t ) {
						if ( ( $t['tier'] ?? '' ) === $tier && ! empty( $t['label'] ) ) {
							return $label . ' · ' . $t['label'];
						}
					}
				}
			}
		}
		return isset( self::TIER_LABEL[ $tier ] ) ? $label . ' · ' . self::TIER_LABEL[ $tier ] : $label;
	}

	/**
	 * The closest milestone not reached yet (smallest share still missing) across the groups, or null.
	 *
	 * @param array<string,int|float> $metrics keys = catalog `metric` names
	 * @param array<int,array<string,mixed>> $groups catalog groups
	 * @return array{label:string,remaining:int}|null
	 */
	public static function next_milestone( array $metrics, array $groups ): ?array {
		$best = null;
		foreach ( $groups as $g ) {
			$metric = (string) ( $g['metric'] ?? '' );
			if ( '' === $metric || ! isset( $metrics[ $metric ] ) || in_array( $metric, array( 'payback_streak_months', 'cost_per_customer_drop_pct' ), true ) ) {
				continue;
			}
			$value = (int) $metrics[ $metric ];
			foreach ( (array) ( $g['tiers'] ?? array() ) as $t ) {
				$need = (int) ( $t['threshold'] ?? 0 );
				if ( $need > $value ) {
					$share = ( $need - $value ) / $need;
					if ( null === $best || $share < $best['share'] ) {
						$best = array( 'share' => $share, 'remaining' => $need - $value, 'label' => ( $g['label'] ?? $g['code'] ) . ' · ' . ( $t['label'] ?? self::TIER_LABEL[ $t['tier'] ] ?? $t['tier'] ), 'unit' => $metric );
					}
					break; // only the first unreached tier of this group
				}
			}
		}
		return null === $best ? null : array( 'label' => $best['label'], 'remaining' => $best['remaining'] );
	}

	/**
	 * One merged owner message, or null when there is nothing to say (no activity yesterday on any number and no new milestone).
	 *
	 * [2026-10-15 06:20 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.112 S112-L2 — activity only, from the CELL's counts (activity-day@1): customers · replies · new customers · groups · tasks by label · hours.
	 * A figure the cell did not send (null) is left OUT of the line, never printed as 0 (R-ACTIVITY-7). No money, no tokens (R-ACTIVITY-2).
	 *
	 * @param string $dmy          "dd/mm" of the bulletin day
	 * @param array<int,array{label:string,customers:int,replies:int,tasks:int,hours:float,customers_new?:?int,groups_active?:?int,tasks_by_label?:array<string,int>}> $numbers
	 * @param string[] $new_codes  milestone codes earned since the last bulletin
	 * @param array{label:string,remaining:int}|null $next
	 */
	public static function owner_text( string $dmy, array $numbers, array $new_codes, ?array $next, ?array $points = null ): ?string {
		$active = array_values( array_filter( $numbers, static function ( $n ) {
			return (int) $n['customers'] > 0 || (int) $n['tasks'] > 0;
		} ) );
		if ( empty( $active ) && empty( $new_codes ) ) {
			return null;
		}
		$lines = array( '☀️ Bản tin ' . $dmy );
		foreach ( $active as $n ) {
			$parts = array( 'trả lời ' . (int) $n['customers'] . ' khách' );
			if ( (int) $n['replies'] > (int) $n['customers'] ) {
				$parts[0] .= ' (' . (int) $n['replies'] . ' lượt)';
			}
			if ( isset( $n['customers_new'] ) && (int) $n['customers_new'] > 0 ) {
				$parts[] = (int) $n['customers_new'] . ' khách mới';
			}
			if ( isset( $n['groups_active'] ) && (int) $n['groups_active'] > 0 ) {
				$parts[] = (int) $n['groups_active'] . ' nhóm có hoạt động';
			}
			if ( (int) $n['tasks'] > 0 ) {
				$parts[] = 'làm ' . (int) $n['tasks'] . ' việc' . self::labels_text( (array) ( $n['tasks_by_label'] ?? array() ) );
			}
			if ( (float) $n['hours'] > 0 ) {
				$parts[] = 'làm thay khoảng ' . rtrim( rtrim( number_format( (float) $n['hours'], 1, ',', '' ), '0' ), ',' ) . ' giờ';
			}
			$lines[] = '• ' . $n['label'] . ': hôm qua ' . implode( ', ', $parts ) . '.';
		}
		$pl = self::points_line( $points );
		if ( null !== $pl ) {
			$lines[] = $pl;
		}
		if ( ! empty( $new_codes ) ) {
			$lines[] = '🎉 Vừa đạt: ' . implode( '; ', array_map( array( __CLASS__, 'milestone_label' ), array_slice( $new_codes, 0, 5 ) ) ) . '.';
		}
		if ( null !== $next && $next['remaining'] > 0 ) {
			$lines[] = 'Còn ' . (int) $next['remaining'] . ' nữa là đạt ' . $next['label'] . '.';
		}
		return implode( "\n", $lines );
	}

	/** Completion-point stages of one number (doc 20 §8, D112-10): lifetime points ⇒ stage name. */
	const POINT_STAGES = array( 100 => 'Khởi động', 1000 => 'Vào guồng', 10000 => 'Thành thạo', 100000 => 'Chuyên gia' );

	/**
	 * "⭐ +38 điểm · tổng 1.230 · còn 8.770 điểm tới Thành thạo" and, when yesterday carried the total over a stage, "🎉 Vừa qua chặng Vào guồng".
	 * null when the cell sent no points (no line, never "+0"). Points are the cell's completion score, not tokens or money (R-ACTIVITY-2).
	 *
	 * [2026-10-15 10:15 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.112 S112-W9 / S112-L8.
	 *
	 * @param array{day:int,total:int}|null $points
	 */
	public static function points_line( ?array $points ): ?string {
		if ( null === $points || (int) $points['day'] <= 0 ) {
			return null;
		}
		$day    = (int) $points['day'];
		$total  = max( $day, (int) $points['total'] );
		$before = $total - $day;
		$line   = '⭐ +' . number_format( $day, 0, ',', '.' ) . ' điểm · tổng ' . number_format( $total, 0, ',', '.' );
		$next   = null;
		$passed = null;
		foreach ( self::POINT_STAGES as $need => $name ) {
			if ( $before < $need && $total >= $need ) {
				$passed = $name; // the loop ascends: the highest stage crossed wins
			}
			if ( null === $next && $total < $need ) {
				$next = array( $need, $name );
			}
		}
		if ( null !== $next ) {
			$line .= ' · còn ' . number_format( $next[0] - $total, 0, ',', '.' ) . ' điểm tới ' . $next[1];
		}
		if ( null !== $passed ) {
			$line .= "\n🎉 Vừa qua chặng " . $passed;
		}
		return $line;
	}

	/** The cell's completion points of this owner's numbers: yesterday + running total; null when the cell sent none. */
	private static function points( array $accounts, string $ymd ): ?array {
		if ( isset( self::$readers['points'] ) ) {
			$r = call_user_func( self::$readers['points'], $accounts, $ymd );
			return is_array( $r ) ? $r : null;
		}
		return class_exists( 'BizCity_Usage_Activity_Day' ) ? BizCity_Usage_Activity_Day::points_for( $accounts, $ymd ) : null;
	}

	/** " (1 nhắc việc, 2 ảnh)" from {label: n}, biggest first, at most 3, zeros dropped; "" when nothing is left. */
	public static function labels_text( array $labels ): string {
		$labels = array_filter( array_map( 'intval', $labels ), static fn( $n ) => $n > 0 );
		if ( empty( $labels ) ) {
			return '';
		}
		arsort( $labels );
		$bits = array();
		foreach ( array_slice( $labels, 0, 3, true ) as $label => $n ) {
			$bits[] = $n . ' ' . $label;
		}
		return ' (' . implode( ', ', $bits ) . ')';
	}

	/**
	 * The admin bulletin, or null (no activity AND nobody asleep).
	 *
	 * [2026-10-15 06:20 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.112 S112-L2 — yesterday's activity of the whole site (customers, new, groups, tasks), one line per page / channel other than the
	 * Zalo number itself (D112-6: max 5 lines), the top 3, the sleeping count. NO money line.
	 *
	 * @param array<int,array{label:string,customers:int}> $top3
	 * @param array{brains:int,pages?:int,customers?:int,customers_new?:?int,groups_active?:?int,tasks?:?int,channels?:array<int,array{label:string,customers:int,comments:int}>} $day
	 */
	public static function admin_text( string $dmy, array $top3, int $sleeping, array $day ): ?string {
		$has_top = ! empty( array_filter( $top3, static fn( $t ) => (int) $t['customers'] > 0 ) );
		if ( ! $has_top && $sleeping < 1 ) {
			return null;
		}
		$head = '📊 Bản tin tổng ' . $dmy . ' · ' . (int) $day['brains'] . ' trợ lý';
		if ( (int) ( $day['pages'] ?? 0 ) > 0 ) {
			$head .= ' · ' . (int) $day['pages'] . ' page';
		}
		$lines = array( $head );
		if ( $has_top ) {
			$sum = array( (int) ( $day['customers'] ?? 0 ) . ' khách' );
			if ( (int) ( $day['customers_new'] ?? 0 ) > 0 ) {
				$sum[0] .= ' (' . (int) $day['customers_new'] . ' mới)';
			}
			if ( (int) ( $day['groups_active'] ?? 0 ) > 0 ) {
				$sum[] = (int) $day['groups_active'] . ' nhóm có hoạt động';
			}
			if ( (int) ( $day['tasks'] ?? 0 ) > 0 ) {
				$sum[] = (int) $day['tasks'] . ' việc';
			}
			if ( (int) ( $day['customers'] ?? 0 ) > 0 ) {
				$lines[] = 'Hôm qua: ' . implode( ' · ', $sum );
			}
			foreach ( array_slice( (array) ( $day['channels'] ?? array() ), 0, 5 ) as $c ) {
				$bits = array();
				if ( (int) $c['customers'] > 0 ) {
					$bits[] = (int) $c['customers'] . ' khách';
				}
				if ( (int) ( $c['comments'] ?? 0 ) > 0 ) {
					$bits[] = (int) $c['comments'] . ' bình luận';
				}
				if ( $bits ) {
					$lines[] = '• ' . $c['label'] . ': ' . implode( ' · ', $bits );
				}
			}
			$i = 0;
			$top = array();
			foreach ( $top3 as $t ) {
				if ( (int) $t['customers'] > 0 && $i < 3 ) {
					$top[] = ( ++$i ) . '. ' . $t['label'] . ' (' . (int) $t['customers'] . ' khách)';
				}
			}
			$lines[] = 'Top hôm qua: ' . implode( ' · ', $top );
		}
		if ( $sleeping > 0 ) {
			$lines[] = '💤 ' . $sleeping . ' trợ lý đang ngủ (≥ ' . self::SLEEP_DAYS . ' ngày không có khách).';
		}
		return implode( "\n", $lines );
	}

	/* ================================================================
	 *  Tick (hourly): decides, gathers, sends
	 * ================================================================ */

	public static function tick(): array {
		$sent = array( 'owners' => 0, 'admin' => 0, 'skipped' => 0 );
		if ( ! (bool) get_option( BizCity_Brain_Settings::OPT_DIGEST_ON, true ) ) {
			return $sent;
		}
		$tz     = self::tz();
		$now    = ( new DateTimeImmutable( '@' . self::now() ) )->setTimezone( $tz );
		$today  = $now->format( 'Ymd' );
		$ymd_y  = $now->modify( '-1 day' )->format( 'Y-m-d' );
		$dmy    = $now->format( 'd/m' );
		$time   = BizCity_Brain_Settings::clean_time( get_option( BizCity_Brain_Settings::OPT_DIGEST_TIME, '07:30' ) );
		if ( ! self::due( $now->format( 'H:i' ), $time, '', $today ) ) {
			return $sent; // before the send time today
		}

		foreach ( self::owners() as $uid ) {
			$uid = (int) $uid;
			if ( $uid <= 0 || ! BizCity_Brain_Settings::owner_digest_on( $uid ) ) {
				continue;
			}
			if ( ! self::due( $now->format( 'H:i' ), $time, (string) get_user_meta( $uid, self::META_LAST, true ), $today ) ) {
				continue;
			}
			$since   = (string) get_user_meta( $uid, self::META_SINCE, true );
			$numbers = array();
			$next    = null;
			foreach ( self::accounts_of( $uid ) as $acc ) {
				$numbers[] = self::facts( $acc, $ymd_y );
				$m = self::metrics( $acc );
				$cand = self::next_milestone( $m, class_exists( 'BizCity_Brain_Milestones_Catalog' ) ? BizCity_Brain_Milestones_Catalog::active_groups() : array() );
				if ( null !== $cand && ( null === $next || $cand['remaining'] < $next['remaining'] ) ) {
					$next = $cand;
				}
			}
			$new   = '' === $since ? array() : self::new_milestones( $uid, $since );
			$text  = self::owner_text( $dmy, $numbers, $new, $next, self::points( self::accounts_of( $uid ), $ymd_y ) );
			if ( null === $text ) {
				update_user_meta( $uid, self::META_LAST, $today ); // nothing to say today — handled, do not recompute every hour
				update_user_meta( $uid, self::META_SINCE, gmdate( 'Y-m-d H:i:s', self::now() ) );
				$sent['skipped']++;
				continue;
			}
			if ( self::send( $uid, $text, 'brain-digest:' . $uid . ':' . $today ) ) {
				update_user_meta( $uid, self::META_LAST, $today );
				update_user_meta( $uid, self::META_SINCE, gmdate( 'Y-m-d H:i:s', self::now() ) );
				$sent['owners']++;
			}
		}

		if ( (string) get_option( self::OPT_ADMIN_LAST, '' ) !== $today ) {
			$all      = self::all_facts( $ymd_y );
			usort( $all, static fn( $a, $b ) => (int) $b['customers'] <=> (int) $a['customers'] );
			$day      = self::day_summary( $ymd_y );
			$text     = self::admin_text( $dmy, array_slice( $all, 0, 3 ), self::sleeping(), $day );
			$admin    = self::admin_uid();
			if ( null === $text || $admin <= 0 ) {
				update_option( self::OPT_ADMIN_LAST, $today, false );
			} elseif ( self::send( $admin, $text, 'brain-digest-admin:' . $today ) ) {
				update_option( self::OPT_ADMIN_LAST, $today, false );
				$sent['admin']++;
			}
		}
		return $sent;
	}

	/* ================================================================
	 *  Seams + real readers
	 * ================================================================ */

	private static function now(): int {
		return isset( self::$readers['now'] ) ? (int) call_user_func( self::$readers['now'] ) : time();
	}

	private static function tz(): DateTimeZone {
		if ( isset( self::$readers['tz'] ) ) {
			return call_user_func( self::$readers['tz'] );
		}
		return function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
	}

	/** @return int[] owner user ids that have a metrics row. */
	private static function owners(): array {
		if ( isset( self::$readers['owners'] ) ) {
			return (array) call_user_func( self::$readers['owners'] );
		}
		global $wpdb;
		$t = BizCity_Brain_Journey_Schema::metrics_table();
		return array_map( 'intval', (array) $wpdb->get_col( "SELECT DISTINCT owner_user_id FROM {$t} WHERE owner_user_id > 0" ) );
	}

	/** @return string[] */
	private static function accounts_of( int $uid ): array {
		if ( isset( self::$readers['accounts_of'] ) ) {
			return (array) call_user_func( self::$readers['accounts_of'], $uid );
		}
		global $wpdb;
		$t = BizCity_Brain_Journey_Schema::metrics_table();
		return array_map( 'strval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT account_id FROM {$t} WHERE owner_user_id = %d ORDER BY account_id", $uid ) ) );
	}

	/** @return array<string,int|float> metric_name → value for one account (the catalog's `metric` keys). */
	private static function metrics( string $account ): array {
		if ( isset( self::$readers['metrics'] ) ) {
			return (array) call_user_func( self::$readers['metrics'], $account );
		}
		global $wpdb;
		$t   = BizCity_Brain_Journey_Schema::metrics_table();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE account_id = %s", $account ), ARRAY_A );
		return is_array( $row ) ? $row : array();
	}

	/** @return string[] milestone codes earned after `$since` (UTC) by this owner's brains. */
	private static function new_milestones( int $uid, string $since ): array {
		if ( isset( self::$readers['new_milestones'] ) ) {
			return (array) call_user_func( self::$readers['new_milestones'], $uid, $since );
		}
		global $wpdb;
		$j = BizCity_Brain_Journey_Schema::journey_table();
		return array_map( 'strval', (array) $wpdb->get_col( $wpdb->prepare(
			"SELECT DISTINCT code FROM {$j} WHERE subject_kind = 'brain' AND owner_user_id = %d AND event IN ('milestone','payback') AND occurred_at > %s ORDER BY occurred_at",
			$uid, $since
		) ) );
	}

	/**
	 * Yesterday's figures for one number. S112-L2 / D112-2: the CELL's counts (activity-day@1) win; only when the cell has not sent that day yet (not deployed / Hub route missing /
	 * day not measured) does this fall back to the site's own copy of the ledger for the three figures it can still count (customers, replies, tasks). The fallback has no groups / new
	 * customers (unknown ⇒ null ⇒ not printed) and goes away with S112-L6 once the cell figures flow. Never any money.
	 *
	 * @return array{label:string,customers:int,replies:int,tasks:int,hours:float,customers_new:?int,groups_active:?int,tasks_by_label:array<string,int>,source:string}
	 */
	private static function facts( string $account, string $ymd ): array {
		if ( isset( self::$readers['facts'] ) ) {
			return (array) call_user_func( self::$readers['facts'], $account, $ymd );
		}
		$cell = class_exists( 'BizCity_Usage_Activity_Day' ) ? BizCity_Usage_Activity_Day::for_day( $account, $ymd ) : null;
		if ( null !== $cell ) {
			$est = BizCity_Brain_ROI::time_saved_estimate( (int) $cell['replies'] );
			return array(
				'label' => self::label( $account ), 'customers' => (int) $cell['customers'], 'replies' => (int) $cell['replies'], 'tasks' => (int) $cell['tasks'],
				'hours' => (float) $est['hours'], 'customers_new' => (int) $cell['customers_new'], 'groups_active' => (int) $cell['groups_active'],
				'tasks_by_label' => (array) $cell['tasks_by_label'], 'source' => 'cell',
			);
		}
		global $wpdb;
		$ledger = BizCity_Usage_Schema::ledger_table();
		$daily  = BizCity_Usage_Schema::daily_table();
		$cust   = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(DISTINCT contact_key) FROM {$ledger} WHERE account_id = %s AND kind = 'llm' AND actor_role = 'customer' AND contact_key <> '' AND occurred_day = %s", $account, $ymd
		) );
		$turns  = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COALESCE(SUM(turns),0) FROM {$daily} WHERE account_id = %s AND day = %s AND actor_role = 'customer' AND kind = 'llm'", $account, $ymd
		) );
		$tools  = BizCity_Brain_Settings::clean_tools( (array) get_option( BizCity_Brain_Journey_Evaluator::OPT_ACTION_TOOLS, array() ) );
		$tasks  = 0;
		if ( ! empty( $tools ) ) {
			$ph    = implode( ',', array_fill( 0, count( $tools ), '%s' ) );
			$tasks = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$ledger} WHERE account_id = %s AND kind = 'tool' AND is_error = 0 AND occurred_day = %s AND tool IN ({$ph})",
				array_merge( array( $account, $ymd ), $tools )
			) );
		}
		$est = BizCity_Brain_ROI::time_saved_estimate( $turns );
		return array(
			'label' => self::label( $account ), 'customers' => $cust, 'replies' => $turns, 'tasks' => $tasks, 'hours' => (float) $est['hours'],
			'customers_new' => null, 'groups_active' => null, 'tasks_by_label' => array(), 'source' => 'site_copy',
		);
	}

	/** @return array<int,array{label:string,customers:int}> every number's customers yesterday (admin top 3). */
	private static function all_facts( string $ymd ): array {
		if ( isset( self::$readers['all_facts'] ) ) {
			return (array) call_user_func( self::$readers['all_facts'], $ymd );
		}
		$out = array();
		foreach ( self::owners() as $uid ) {
			foreach ( self::accounts_of( (int) $uid ) as $acc ) {
				$f     = self::facts( $acc, $ymd );
				$out[] = array( 'label' => $f['label'], 'customers' => (int) $f['customers'] );
			}
		}
		return $out;
	}

	/**
	 * The site-wide figures of one day for the admin bulletin, from the CELL's rows (every number AND the web / Messenger / OA channels of this site, since the table is per site).
	 * Without any cell row for the day only the brain count is known: the activity figures stay at 0 and admin_text prints just the top 3 it already has.
	 *
	 * @return array{brains:int,pages:int,customers:int,customers_new:int,groups_active:int,tasks:int,channels:array<int,array{label:string,customers:int,comments:int}>}
	 */
	private static function day_summary( string $ymd ): array {
		if ( isset( self::$readers['day_summary'] ) ) {
			return (array) call_user_func( self::$readers['day_summary'], $ymd );
		}
		global $wpdb;
		if ( isset( self::$readers['brains'] ) ) {
			$brains = (int) call_user_func( self::$readers['brains'] );
		} else {
			$t      = BizCity_Brain_Journey_Schema::metrics_table();
			$brains = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t}" );
		}
		$out = array( 'brains' => $brains, 'pages' => 0, 'customers' => 0, 'customers_new' => 0, 'groups_active' => 0, 'tasks' => 0, 'channels' => array() );
		if ( ! class_exists( 'BizCity_Usage_Activity_Day' ) ) {
			return $out;
		}
		$by = array();
		foreach ( BizCity_Usage_Activity_Day::rows_for_day( $ymd ) as $r ) {
			$out['customers']     += (int) $r['customers'];
			$out['customers_new'] += (int) $r['customers_new'];
			$out['groups_active'] += (int) $r['groups_active'];
			$out['tasks']         += (int) $r['tasks'];
			$out['pages']          = max( $out['pages'], (int) $r['connections_active'] ); // tenant-level gauge repeated on every number: the max, never the sum
			foreach ( (array) $r['by_channel'] as $c ) {
				if ( 'zalo_personal' === ( $c['platform'] ?? '' ) ) {
					continue; // the Zalo number has its own line in the owner bulletin
				}
				$k = ( $c['platform'] ?? '' ) . '|' . ( $c['channel_ref'] ?? '' );
				$by[ $k ] = array(
					'label'     => (string) ( $c['label'] ?? $c['platform'] ?? '' ),
					'customers' => (int) ( $by[ $k ]['customers'] ?? 0 ) + (int) ( $c['customers'] ?? 0 ),
					'comments'  => (int) ( $by[ $k ]['comments'] ?? 0 ) + (int) ( $c['comments'] ?? 0 ),
				);
			}
		}
		usort( $by, static fn( $a, $b ) => $b['customers'] <=> $a['customers'] );
		$out['channels'] = array_values( $by );
		return $out;
	}

	/** Numbers whose AI is on but had no customer turn for SLEEP_DAYS days. */
	private static function sleeping(): int {
		if ( isset( self::$readers['sleeping'] ) ) {
			return (int) call_user_func( self::$readers['sleeping'] );
		}
		global $wpdb;
		$t = BizCity_Brain_Journey_Schema::metrics_table();
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$t} WHERE ai_enabled = 1 AND (last_customer_reply_at IS NULL OR last_customer_reply_at < %s)",
			gmdate( 'Y-m-d H:i:s', self::now() - self::SLEEP_DAYS * DAY_IN_SECONDS )
		) );
	}

	/** The site admin who owns a number — the single recipient of the site bulletin. */
	private static function admin_uid(): int {
		if ( isset( self::$readers['admin_uid'] ) ) {
			return (int) call_user_func( self::$readers['admin_uid'] );
		}
		foreach ( self::owners() as $uid ) {
			if ( user_can( (int) $uid, 'manage_options' ) ) {
				return (int) $uid;
			}
		}
		return 0;
	}

	private static function label( string $account ): string {
		return class_exists( 'BizCity_Usage_Plan_Notices' ) ? BizCity_Usage_Plan_Notices::label_for( $account ) : 'Số …' . substr( $account, -3 );
	}

	/** Send over the owner's own number (first of their accounts that has a transport). */
	private static function send( int $uid, string $text, string $idem ): bool {
		if ( isset( self::$readers['send'] ) ) {
			return (bool) call_user_func( self::$readers['send'], $uid, $text, $idem );
		}
		if ( ! class_exists( 'BizCity_Usage_Plan_Notices' ) ) {
			return false;
		}
		foreach ( self::accounts_of( $uid ) as $acc ) {
			if ( BizCity_Usage_Plan_Notices::send_text( $acc, $text, $idem ) ) {
				return true;
			}
		}
		return false;
	}
}
