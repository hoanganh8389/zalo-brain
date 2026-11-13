<?php
/**
 * Menu "Sử dụng" → tab Thành tích REST — contract brain-journey@1 (PHASE-0.108 S108-L6, master plan §3.2, §3.4).
 * Namespace bizcity-channel/v1, same visibility rule as usage-view@1 (0.107/62 §5.4): manage rights see
 * everything; otherwise only accounts this WP user owns (`owner_user_id = viewer`). Reads only
 * `bizcity_brain_metrics` / `bizcity_brain_journey` (CL-side cache + ledger, R-CELL-METER-ONLY D108-2) —
 * never calls the cell or the Hub.
 *
 *   GET brain/leaderboard?sort=hardworking|efficient|earning|trained&range=7d|30d|month&page=&per_page=   (v2: 13 columns, flags[], rank_delta)
 *   GET brain/{id}/journey?limit=&before=                      (timeline newest first + next[] + badges[] + hash12; v2)
 *   GET brain/{id}/verify                                      (managers: re-walk the hash chain, first_bad_seq)
 *   GET brain/{id}/month?month=YYYY-MM                         (ROI card, 3 separate lines, D108-5; v2 real month)
 *   GET owner/{id}/profile                                     (nấc thang — §3.3 C; id = WP user_id, self or admin)
 *
 * // [2026-10-13 Johnny Chu - Chu Hoàng Anh] PHASE-0.108 S108-L6.
 *
 * @package BizCity_Twin_AI
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_Brain_Journey_REST {

	const NS       = 'bizcity-channel/v1';
	const CONTRACT = 'brain-journey@1';
	const PER_PAGE = 50; // master plan §4 risk 9 — paginate the leaderboard.
	const ACTIVITY_SORTS = array( 'hardworking', 'trained' ); // PHASE-0.112 S112-L4b — money tabs (efficient, earning) retired from Thành tích.

	/**
	 * Test seams: viewer(): int · is_admin(): bool · now(): int
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
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes(): void {
		$perm = array( __CLASS__, 'can_read' );
		register_rest_route( self::NS, '/brain/leaderboard', array(
			'methods' => 'GET', 'callback' => array( __CLASS__, 'route_leaderboard' ), 'permission_callback' => $perm,
		) );
		// [2026-10-15 10:40 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.112 S112-L7 — Thành tích › Điểm: the cell's completion points per number, from activity-day@1.1. No money, no tokens.
		register_rest_route( self::NS, '/brain/points', array(
			'methods' => 'GET', 'callback' => array( __CLASS__, 'route_points' ), 'permission_callback' => $perm,
		) );
		register_rest_route( self::NS, '/brain/(?P<id>[A-Za-z0-9._:-]{1,64})/journey', array(
			'methods' => 'GET', 'callback' => array( __CLASS__, 'route_journey' ), 'permission_callback' => $perm,
		) );
		register_rest_route( self::NS, '/brain/(?P<id>[A-Za-z0-9._:-]{1,64})/month', array(
			'methods' => 'GET', 'callback' => array( __CLASS__, 'route_month' ), 'permission_callback' => $perm,
		) );
		register_rest_route( self::NS, '/brain/(?P<id>[A-Za-z0-9._:-]{1,64})/verify', array(
			'methods' => 'GET', 'callback' => array( __CLASS__, 'route_verify' ), 'permission_callback' => $perm,
		) );
		register_rest_route( self::NS, '/owner/(?P<id>\d+)/profile', array(
			'methods' => 'GET', 'callback' => array( __CLASS__, 'route_owner_profile' ), 'permission_callback' => $perm,
		) );
		// [2026-10-13 Johnny Chu - Chu Hoàng Anh] PHASE-0.108 S108-U5 — "khoảnh khắc chúc mừng" (00-MASTER-PLAN.md §3.4):
		// one notice per new milestone the NEXT time this admin opens the app, never a push/toast mid-session.
		register_rest_route( self::NS, '/brain/notifications', array(
			'methods' => 'GET', 'callback' => array( __CLASS__, 'route_notifications' ), 'permission_callback' => $perm,
		) );
		register_rest_route( self::NS, '/brain/notifications/ack', array(
			'methods' => 'POST', 'callback' => array( __CLASS__, 'route_notifications_ack' ), 'permission_callback' => $perm,
		) );
		// [2026-10-14 Johnny Chu - Chu Hoàng Anh] PHASE-0.108 S108-L16 — the names 10-SPEC §9 G-10 gives the same queue: GET brain/notices, POST brain/notices/seen.
		register_rest_route( self::NS, '/brain/notices', array(
			'methods' => 'GET', 'callback' => array( __CLASS__, 'route_notifications' ), 'permission_callback' => $perm,
		) );
		register_rest_route( self::NS, '/brain/notices/seen', array(
			'methods' => 'POST', 'callback' => array( __CLASS__, 'route_notifications_ack' ), 'permission_callback' => $perm,
		) );
	}

	public static function can_read() {
		if ( self::viewer() <= 0 ) {
			return new WP_Error( 'rest_forbidden', 'Login required.', array( 'status' => 401 ) );
		}
		return true;
	}

	/* ================================================================
	 *  Leaderboard
	 * ================================================================ */

	public static function route_leaderboard( WP_REST_Request $request ): WP_REST_Response {
		if ( ! class_exists( 'BizCity_Brain_Journey_Schema' ) || ! BizCity_Brain_Journey_Schema::installed() ) {
			return new WP_REST_Response( array( 'contract' => self::CONTRACT, 'ready' => false, 'rows' => array(), 'total' => 0 ) );
		}
		// [2026-10-14 04:05 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.108 S108-L11b — leaderboard v2 (10-SPEC §3): a period (7d / 30d / month), the 13 columns, the three flags,
		// ▲▼ against the equally long window before, "Mốc gần nhất". Ranked in PHP over the viewer's own numbers (≤ a few hundred rows) from the cached
		// per-window table — the request never scans the ledger (T108-7). Money comes back in USD and, when the Hub rate is known, in ₫ (G3).
		global $wpdb;
		$range = BizCity_Brain_Board::range( (string) $request->get_param( 'range' ) );
		// [2026-10-15 02:05 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.112 S112-L4b (D112-3) — Thành tích carries no money: only the two activity tabs,
		// no Token/Chi/Thu/Lãi fields, no 🔥 cost flag. Money stays on Sử dụng / Doanh thu (their own routes, untouched).
		$sort  = (string) $request->get_param( 'sort' );
		$sort  = in_array( $sort, self::ACTIVITY_SORTS, true ) ? $sort : 'hardworking';
		$page  = max( 1, (int) $request->get_param( 'page' ) );
		$per   = min( self::PER_PAGE, max( 1, (int) ( $request->get_param( 'per_page' ) ?: self::PER_PAGE ) ) );

		list( $where, $args ) = self::scope();
		$mt   = BizCity_Brain_Journey_Schema::metrics_table();
		$sql  = "SELECT * FROM {$mt} WHERE {$where}";
		$base = (array) $wpdb->get_results( $args ? $wpdb->prepare( $sql, $args ) : $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$base = array_column( $base, null, 'account_id' );
		$per_rows = BizCity_Brain_Period_Metrics::load( $range );
		$bounds   = BizCity_Brain_Board::range_bounds( $range, gmdate( 'Y-m-d' ) );
		$now      = time();

		$items = array();
		$prev  = array();
		$latest_computed = '';
		foreach ( $base as $id => $m ) {
			$p = $per_rows[ $id ] ?? array();
			if ( ! empty( $p['computed_at'] ) && $p['computed_at'] > $latest_computed ) {
				$latest_computed = (string) $p['computed_at'];
			}
			$items[] = array(
				'account_id' => (string) $id, 'replies' => (int) ( $p['replies'] ?? 0 ), 'customers' => (int) ( $p['customers'] ?? 0 ), 'trained' => (int) $m['trained_items'],
			);
			$prev[] = array(
				'account_id' => (string) $id, 'replies' => (int) ( $p['replies_prev'] ?? 0 ), 'customers' => (int) ( $p['customers_prev'] ?? 0 ), 'trained' => (int) $m['trained_items'],
			);
		}
		$ranked   = BizCity_Brain_Board::rank( $items, $sort );
		$ranked_p = BizCity_Brain_Board::rank( $prev, $sort );
		$pos_prev = array();
		foreach ( $ranked_p['order'] as $i => $aid ) {
			$pos_prev[ $aid ] = $i + 1;
		}

		$total   = count( $ranked['order'] );
		$page_ids = array_slice( $ranked['order'], ( $page - 1 ) * $per, $per );
		$plans    = self::plan_state_for( $page_ids, $now );
		$last     = self::last_milestones( $page_ids );
		$groups   = class_exists( 'BizCity_Usage_Activity_Day' ) ? BizCity_Usage_Activity_Day::latest_groups_joined( $page_ids ) : array();
		$admin    = self::is_admin();
		$names    = array();

		$out = array();
		foreach ( $page_ids as $i => $id ) {
			$m = $base[ $id ];
			$p = $per_rows[ $id ] ?? array();
			$rank = ( $page - 1 ) * $per + $i + 1;
			$cust = (int) ( $p['customers'] ?? 0 );
			$flags = BizCity_Brain_Board::flags( array(
				'sleeping_days' => BizCity_Brain_Board::sleeping_days( (bool) $m['ai_enabled'], $m['last_customer_reply_at'] ?: null, $m['born_at'] ?: null, $now ),
				'expensive'     => false, // S112-L4b — a cost flag is money; not on Thành tích.
				'plan'          => $plans[ $id ] ?? null,
			) );
			$owner_id = (int) $m['owner_user_id'];
			if ( $admin && $owner_id > 0 && ! isset( $names[ $owner_id ] ) ) {
				$u = get_userdata( $owner_id );
				$names[ $owner_id ] = $u ? (string) $u->display_name : '';
			}
			$info = self::account_info( $id );
			$row  = array(
				'account_id'               => $id,
				'label_masked'             => $info['label_masked'],
				'name'                     => $info['name'],
				'rank'                     => $rank,
				'rank_delta'               => BizCity_Brain_Board::rank_delta( $rank, $pos_prev[ $id ] ?? null ),
				'owner_user_id'            => $owner_id,
				'customer_replies_range'   => (int) ( $p['replies'] ?? 0 ),
				'distinct_customers_range' => $cust,
				// PHASE-0.112 S112-L3 — groups the number is in, as the CELL last reported (activity-day@1 `groups_joined`); null = the cell has not reported ⇒ the screen shows "—", never 0.
				'distinct_groups_range'    => $groups[ $id ] ?? null,
				'tasks_done_range'         => (int) ( $p['tasks'] ?? 0 ),
				'trained_items'            => (int) $m['trained_items'],
				'streak_days'              => (int) $m['streak_days'],
				'active'                   => (bool) $m['active'],
				'ai_enabled'               => (bool) $m['ai_enabled'],
				'flags'                    => $flags,
				'last_milestone'           => $last[ $id ] ?? null,
				// lifetime figures kept for the overview card and older callers
				'customer_replies'         => (int) $m['customer_replies'],
				'distinct_customers'       => (int) $m['distinct_customers'],
			);
			if ( $admin ) {
				$row['owner_name'] = $names[ $owner_id ] ?? '';
			}
			$out[] = $row;
		}
		return new WP_REST_Response( array(
			'contract'         => self::CONTRACT,
			'ready'            => (bool) $per_rows,
			'range'            => $range,
			'sort'             => $sort,
			'page'             => $page,
			'per_page'         => $per,
			'total'            => $total,
			'period'           => array( 'from' => $bounds['from'], 'to' => $bounds['to'], 'prev_from' => $bounds['prev_from'], 'prev_to' => $bounds['prev_to'] ),
			'computed_at'      => '' === $latest_computed ? null : $latest_computed,
			'rows'             => $out,
		) );
	}

	/**
	 * GET brain/points?range=7d|30d|all — the points scoreboard (doc 20 §7). One row per number the viewer may see (an admin also sees this site's web / Messenger accounts),
	 * ranked by the points of the chosen window. A number the cell has not reported yet is absent (the screen says so), never listed with 0.
	 */
	public static function route_points( WP_REST_Request $request ): WP_REST_Response {
		if ( ! class_exists( 'BizCity_Usage_Activity_Day' ) || ! class_exists( 'BizCity_Usage_Schema' ) ) {
			return new WP_REST_Response( array( 'contract' => self::CONTRACT, 'ready' => false, 'rows' => array() ) );
		}
		global $wpdb;
		$range = (string) $request->get_param( 'range' );
		$range = in_array( $range, array( '7d', '30d', 'all' ), true ) ? $range : '30d';
		$ids   = array();
		if ( class_exists( 'BizCity_Brain_Journey_Schema' ) && BizCity_Brain_Journey_Schema::installed() ) {
			list( $where, $args ) = self::scope();
			$mt  = BizCity_Brain_Journey_Schema::metrics_table();
			$sql = "SELECT account_id FROM {$mt} WHERE {$where}";
			$ids = array_map( 'strval', (array) $wpdb->get_col( $args ? $wpdb->prepare( $sql, $args ) : $sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		if ( self::is_admin() ) {
			$t   = BizCity_Usage_Schema::activity_day_table();
			$ids = array_values( array_unique( array_merge( $ids, array_map( 'strval', (array) $wpdb->get_col( "SELECT DISTINCT account_id FROM {$t} WHERE measured = 1 LIMIT 500" ) ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		$today = function_exists( 'wp_date' ) ? wp_date( 'Y-m-d' ) : gmdate( 'Y-m-d' );
		$board = BizCity_Usage_Activity_Day::scoreboard( $ids, $today );
		// PHASE-0.112 S112-L5 — maturity 0–100 per number (the cell's activity × efficiency multiplier); `trained` = the site's own taught-items count.
		$trained = array();
		if ( class_exists( 'BizCity_Brain_Journey_Schema' ) && BizCity_Brain_Journey_Schema::installed() && $ids ) {
			$mt  = BizCity_Brain_Journey_Schema::metrics_table();
			$ph  = implode( ',', array_fill( 0, count( $ids ), '%s' ) );
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT account_id, trained_items FROM {$mt} WHERE account_id IN ({$ph})", $ids ), ARRAY_A ) as $tr ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
				$trained[ (string) $tr['account_id'] ] = (int) $tr['trained_items'];
			}
		}
		$mat = class_exists( 'BizCity_Brain_Maturity' ) ? BizCity_Brain_Maturity::for_accounts( $ids, $trained, $today ) : array( 'accounts' => array(), 'owner' => 0 );
		$key   = 'all' === $range ? 'points_total' : ( '7d' === $range ? 'points_7d' : 'points_30d' );
		$rows  = array();
		foreach ( $board as $id => $f ) {
			$stage = BizCity_Usage_Activity_Day::stage_of( (int) $f['points_total'] );
			$info  = 0 === strpos( $id, 'web-' ) ? array( 'label_masked' => 'Web & kênh khác', 'name' => '' ) : self::account_info( $id );
			$rows[] = array(
				'account_id'      => $id,
				'label_masked'    => $info['label_masked'],
				'name'            => $info['name'],
				'points'          => (int) $f[ $key ],
				'points_today'    => (int) $f['points_today'],
				'points_total'    => (int) $f['points_total'],
				'jobs_done_30d'   => (int) $f['jobs_done_30d'],
				'jobs_failed_30d' => (int) $f['jobs_failed_30d'],
				'jobs_by_kind'    => array_slice( $f['jobs_by_kind_30d'], 0, 3, true ),
				'efficiency'      => $f['efficiency'],
				'last_day'        => $f['last_day'],
				'stage'           => $stage['reached'] ? $stage['reached']['name'] : null,
				'next_stage'      => $stage['next'] ? $stage['next']['name'] : null,
				'remaining'       => (int) $stage['remaining'],
				'stage_pct'       => (int) $stage['pct'],
				'maturity'        => isset( $mat['accounts'][ $id ] ) ? (int) $mat['accounts'][ $id ]['maturity'] : null,
			);
		}
		usort( $rows, static fn( $a, $b ) => ( $b['points'] <=> $a['points'] ) ?: ( $b['points_total'] <=> $a['points_total'] ) ?: strcmp( $a['account_id'], $b['account_id'] ) );
		$sum = array( 'points_today' => 0, 'points_30d' => 0, 'points_total' => 0, 'jobs_done_30d' => 0, 'jobs_failed_30d' => 0 );
		foreach ( $board as $f ) {
			$sum['points_today']    += (int) $f['points_today'];
			$sum['points_30d']      += (int) $f['points_30d'];
			$sum['points_total']    += (int) $f['points_total'];
			$sum['jobs_done_30d']   += (int) $f['jobs_done_30d'];
			$sum['jobs_failed_30d'] += (int) $f['jobs_failed_30d'];
		}
		foreach ( $rows as $i => $r ) {
			$rows[ $i ]['rank'] = $i + 1;
		}
		return new WP_REST_Response( array(
			'contract'  => self::CONTRACT,
			'ready'     => ! empty( $rows ),
			'range'     => $range,
			'today'     => $today,
			'summary'   => $sum + array( 'stage' => BizCity_Usage_Activity_Day::stage_of( $sum['points_total'] ), 'maturity' => (int) $mat['owner'] ),
			'ladder'    => array_map( static fn( $need, $name ) => array( 'need' => $need, 'name' => $name ), array_keys( BizCity_Usage_Activity_Day::STAGES ), array_values( BizCity_Usage_Activity_Day::STAGES ) ),
			'rows'      => $rows,
		) );
	}

	/**
	 * ⏳ words per number of ONE page (trial ≤ 2 days · plan ≤ 3 days · allowance ≥ 90 %), from the Hub-side plan table. One batched read.
	 *
	 * @param array<int,string> $ids
	 * @return array<string,string>
	 */
	private static function plan_state_for( array $ids, int $now ): array {
		if ( ! $ids || ! class_exists( 'BizCity_Usage_Plans' ) ) {
			return array();
		}
		$keys = array();
		foreach ( $ids as $id ) {
			$keys[] = array( BizCity_Usage_Plans::PLATFORM, $id );
		}
		$rows = (array) BizCity_Usage_Plans::get_many( $keys );
		$used = BizCity_Usage_Plans::used_for( $rows, $now );
		$out  = array();
		foreach ( $rows as $r ) {
			$id   = (string) $r['account_id'];
			$flag = BizCity_Brain_Board::plan_flag( array(
				'state' => BizCity_Usage_Plans::effective_state( $r, $now ), 'plan_mode' => (string) ( $r['plan_mode'] ?? 'off' ),
				'valid_until_ts' => BizCity_Usage_Plans::ts( $r['valid_until'] ?? '' ), 'allowance' => (int) ( $r['monthly_token_allowance'] ?? 0 ), 'used' => (int) ( $used[ $id ] ?? 0 ),
			), $now );
			if ( null !== $flag ) {
				$out[ $id ] = $flag;
			}
		}
		return $out;
	}

	/**
	 * The latest milestone of each number — one query for the page. A "Ra đời" event is not a milestone chip.
	 *
	 * @param array<int,string> $ids
	 * @return array<string,array{group:string,tier:string,at:string}>
	 */
	private static function last_milestones( array $ids ): array {
		if ( ! $ids ) {
			return array();
		}
		global $wpdb;
		$jt = BizCity_Brain_Journey_Schema::journey_table();
		$ph = implode( ',', array_fill( 0, count( $ids ), '%s' ) );
		$rows = (array) $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $ph is only %s placeholders.
			"SELECT subject_id, code, tier, occurred_at FROM {$jt} WHERE subject_kind = 'brain' AND event = 'milestone' AND code NOT LIKE 'payback.%%' AND code NOT LIKE 'savings.%%' AND subject_id IN ({$ph}) ORDER BY occurred_at DESC, seq DESC",
			$ids
		), ARRAY_A );
		$out = array();
		foreach ( $rows as $r ) {
			$id = (string) $r['subject_id'];
			if ( isset( $out[ $id ] ) ) {
				continue;
			}
			$sp = self::split_code( (string) $r['code'] );
			$out[ $id ] = array( 'group' => $sp[0], 'tier' => (string) $r['tier'], 'at' => (string) $r['occurred_at'] );
		}
		return $out;
	}

	/** "replies.gold" ⇒ array( 'replies', 'gold' ). */
	private static function split_code( string $code ): array {
		$i = strrpos( $code, '.' );
		return false === $i ? array( $code, '' ) : array( substr( $code, 0, $i ), substr( $code, $i + 1 ) );
	}

	/* ================================================================
	 *  One brain's journey timeline (③)
	 * ================================================================ */

	public static function route_journey( WP_REST_Request $request ): WP_REST_Response {
		$id = (string) $request->get_param( 'id' );
		if ( ! self::may_open_account( $id ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'forbidden' ), 403 );
		}
		if ( ! class_exists( 'BizCity_Brain_Journey_Schema' ) || ! BizCity_Brain_Journey_Schema::installed() ) {
			return new WP_REST_Response( array( 'contract' => self::CONTRACT, 'account_id' => $id, 'events' => array(), 'next' => array(), 'badges' => array() ) );
		}
		// [2026-10-14 04:10 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.108 S108-L12 — journey v2 (10-SPEC §4): the 3 next milestones, the 9-cell badge cabinet,
		// where the date of birth comes from (G-12), the 12-char hash printed on every row, who owned the number when a row was earned (luật 2), paging by `before`.
		global $wpdb;
		$jt    = BizCity_Brain_Journey_Schema::journey_table();
		$mt    = BizCity_Brain_Journey_Schema::metrics_table();
		$limit = min( 200, max( 1, (int) ( $request->get_param( 'limit' ) ?: 50 ) ) );
		$before = (int) $request->get_param( 'before' );
		$sql  = "SELECT seq, occurred_at, event, code, tier, value, catalog_version, source, owner_user_id, row_hash FROM {$jt} WHERE subject_kind = 'brain' AND subject_id = %s";
		$args = array( $id );
		if ( $before > 0 ) {
			$sql .= ' AND seq < %d';
			$args[] = $before;
		}
		$sql  .= ' ORDER BY seq DESC LIMIT %d';
		$args[] = $limit + 1;
		$rows = (array) $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$has_more = count( $rows ) > $limit;
		$rows     = array_slice( $rows, 0, $limit );

		$metrics = (array) $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$mt} WHERE account_id = %s", $id ), ARRAY_A );
		$owner   = (int) ( $metrics['owner_user_id'] ?? 0 );
		if ( $owner <= 0 && class_exists( 'BizCity_Zalo_Mapping_Repo' ) ) {
			$acc   = (array) BizCity_Zalo_Mapping_Repo::find_account_by_bridge_id( 'personal', $id );
			$owner = (int) ( $acc['owner_user_id'] ?? 0 );
		}
		$names  = array();
		$name_of = static function ( int $uid ) use ( &$names ): string {
			if ( $uid <= 0 ) {
				return '';
			}
			if ( ! isset( $names[ $uid ] ) ) {
				$u = get_userdata( $uid );
				$names[ $uid ] = $u ? (string) $u->display_name : '';
			}
			return $names[ $uid ];
		};
		$events = array();
		foreach ( $rows as $r ) {
			$e = array(
				'seq' => (int) $r['seq'], 'occurred_at' => $r['occurred_at'], 'event' => $r['event'], 'code' => $r['code'], 'tier' => $r['tier'],
				'value' => (int) $r['value'], 'catalog_version' => $r['catalog_version'], 'source' => $r['source'], 'hash12' => substr( (string) $r['row_hash'], 0, 12 ),
			);
			$row_owner = (int) $r['owner_user_id'];
			if ( $row_owner > 0 && $owner > 0 && $row_owner !== $owner ) {
				$e['earned_under'] = $name_of( $row_owner ); // T108-6: an old milestone keeps the owner it was earned under.
			}
			$events[] = $e;
		}

		$born = $wpdb->get_row( $wpdb->prepare(
			"SELECT occurred_at, source FROM {$jt} WHERE subject_kind = 'brain' AND subject_id = %s AND event = 'born' ORDER BY seq ASC LIMIT 1", $id
		), ARRAY_A );
		$groups_all = (array) ( BizCity_Brain_Milestones_Catalog::get()['groups'] ?? array() );
		$info = self::account_info( $id );
		return new WP_REST_Response( array(
			'contract'     => self::CONTRACT,
			'account_id'   => $id,
			'label_masked' => $info['label_masked'],
			'name'         => $info['name'],
			'owner_user_id' => $owner,
			'owner_name'   => self::is_admin() || $owner === self::viewer() ? $name_of( $owner ) : '',
			'born_at'      => $born['occurred_at'] ?? null,
			// G-12: 'qr' = the exact scan time (connect hook); 'backfill' = a number that already existed, dated by the first sweep ⇒ shown as "ngày ghi sổ".
			'born_source'  => $born ? ( 'backfill' === $born['source'] ? 'backfill' : 'qr' ) : null,
			'next'         => BizCity_Brain_Board::next_milestones( $metrics, BizCity_Brain_Milestones_Catalog::active_groups(), 3 ),
			'badges'       => BizCity_Brain_Board::badges( $metrics, $groups_all ),
			'events'       => $events,
			'has_more'     => $has_more,
			'can_verify'   => self::is_admin(),
		) );
	}

	/** GET brain/{id}/verify — "Kiểm tra sổ" (managers only): re-walks the hash chain and says where it breaks. */
	public static function route_verify( WP_REST_Request $request ): WP_REST_Response {
		if ( ! self::is_admin() ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'forbidden' ), 403 );
		}
		$id = (string) $request->get_param( 'id' );
		if ( ! class_exists( 'BizCity_Brain_Journey_Evaluator' ) || ! class_exists( 'BizCity_Brain_Journey_Schema' ) || ! BizCity_Brain_Journey_Schema::installed() ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'not_ready' ) );
		}
		$v = BizCity_Brain_Journey_Evaluator::verify_chain_detail( 'brain', $id );
		return new WP_REST_Response( array( 'contract' => self::CONTRACT, 'account_id' => $id, 'chain_ok' => $v['ok'], 'rows' => $v['rows'], 'first_bad_seq' => $v['first_bad_seq'] ) );
	}

	/* ================================================================
	 *  One brain's month ROI card (§3.1 ①)
	 * ================================================================ */

	public static function route_month( WP_REST_Request $request ): WP_REST_Response {
		$id = (string) $request->get_param( 'id' );
		if ( ! self::may_open_account( $id ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'forbidden' ), 403 );
		}
		if ( ! class_exists( 'BizCity_Brain_Journey_Schema' ) || ! BizCity_Brain_Journey_Schema::installed() ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'not_ready' ), 200 );
		}
		global $wpdb;
		$mt  = BizCity_Brain_Journey_Schema::metrics_table();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$mt} WHERE account_id = %s", $id ), ARRAY_A );
		if ( ! $row ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'no_data' ) );
		}
		// [2026-10-14 04:15 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.108 S108-L12 — month v2: a real `month=YYYY-MM` (the cache row only knows the running month), tokens of the month,
		// the paid orders (ids only, never a customer), the day the number first paid for itself that month, ₫ next to USD, the 3 next milestones.
		$this_month = gmdate( 'Y-m' );
		$month      = (string) $request->get_param( 'month' );
		$bounds     = BizCity_Brain_Board::month_bounds( '' === $month ? $this_month : $month );
		if ( null === $bounds ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'bad_month' ), 400 );
		}
		$month = substr( $bounds['from'], 0, 7 );
		$to    = min( $bounds['to'], gmdate( 'Y-m-d' ) );
		if ( $month === $this_month ) {
			$chi = (float) $row['chi_usd_month']; $thu = (float) $row['thu_usd_month'];
			$replies = (int) $row['customer_replies_month']; $customers = (int) $row['distinct_customers_month']; $tokens = (int) $row['billed_tokens_month'];
		} elseif ( $bounds['from'] > gmdate( 'Y-m-d' ) ) {
			$chi = $thu = 0.0; $replies = $customers = $tokens = 0; // a month that has not started
		} else {
			$w = BizCity_Brain_Period_Metrics::window( $id, $bounds['from'], $to );
			$chi = (float) $w['chi_usd']; $thu = (float) $w['thu_usd']; $replies = (int) $w['replies']; $customers = (int) $w['customers']; $tokens = (int) $w['billed_tokens'];
		}
		// 10-SPEC G-2: "làm thay" uses THIS month's replies, never the lifetime total (luật 4).
		$time = class_exists( 'BizCity_Brain_ROI' ) ? BizCity_Brain_ROI::time_saved_estimate( $replies ) : array( 'hours' => 0, 'vnd_estimate' => 0 );
		$rate = class_exists( 'BizCity_Brain_ROI' ) ? BizCity_Brain_ROI::usd_vnd_rate() : null;
		$orders = class_exists( 'BizCity_Brain_ROI' ) ? BizCity_Brain_ROI::order_refs_between( $id, $bounds['from'] . ' 00:00:00', $bounds['to'] . ' 23:59:59' ) : array();
		$jt = BizCity_Brain_Journey_Schema::journey_table();
		$payback_since = $wpdb->get_var( $wpdb->prepare(
			"SELECT MIN(occurred_at) FROM {$jt} WHERE subject_kind = 'brain' AND subject_id = %s AND event = 'payback' AND occurred_at BETWEEN %s AND %s",
			$id, $bounds['from'] . ' 00:00:00', $bounds['to'] . ' 23:59:59'
		) );
		$has_rev = $thu > 0;
		$cpc     = BizCity_Brain_Board::cost_per_customer( $chi, $customers );
		$info    = self::account_info( $id );
		return new WP_REST_Response( array(
			'contract'            => self::CONTRACT,
			'account_id'          => $id,
			'label_masked'        => $info['label_masked'],
			'month'               => $month,
			'usd_vnd_rate'        => $rate,
			// D108-5: three separate lines, never summed into one number beyond thu - chi.
			'thu_usd_month'       => round( $thu, 4 ),
			'chi_usd_month'       => round( $chi, 4 ),
			'lai_usd_month'       => round( $thu - $chi, 4 ),
			'thu_vnd_month'       => BizCity_Brain_Board::vnd( $thu, $rate ),
			'chi_vnd_month'       => BizCity_Brain_Board::vnd( $chi, $rate ),
			'lai_vnd_month'       => BizCity_Brain_Board::vnd( $thu - $chi, $rate ),
			'has_revenue'         => $has_rev,
			'billed_tokens_month' => $tokens,
			'customers_served'    => $customers,
			'chi_per_customer_usd'=> null === $cpc ? null : round( $cpc, 4 ),
			'chi_per_customer_vnd'=> null === $cpc ? null : BizCity_Brain_Board::vnd( $cpc, $rate ),
			'orders'              => $orders,
			'payback_since'       => $payback_since ?: null,
			'time_saved_estimate' => array_merge( $time, array( 'estimate' => true ) ),
			'customer_replies'    => $replies,
			'next'                => BizCity_Brain_Board::next_milestones( $row, BizCity_Brain_Milestones_Catalog::active_groups(), 3 ),
			'computed_at'         => $row['computed_at'],
		) );
	}

	/* ================================================================
	 *  Owner profile / tier (§3.3 C) — hardcoded tier rules, NOT a parsed formula (the catalog's `condition`
	 *  strings are documentation only; see 00-MASTER-PLAN.md owner_tiers and Q108-5, not yet owner-locked).
	 * ================================================================ */

	public static function route_owner_profile( WP_REST_Request $request ): WP_REST_Response {
		$owner_id = (int) $request->get_param( 'id' );
		if ( $owner_id <= 0 || ( $owner_id !== self::viewer() && ! self::is_admin() ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'forbidden' ), 403 );
		}
		if ( ! class_exists( 'BizCity_Brain_Journey_Schema' ) || ! BizCity_Brain_Journey_Schema::installed() ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'not_ready' ) );
		}
		global $wpdb;
		$table = BizCity_Brain_Journey_Schema::metrics_table();
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE owner_user_id = %d", $owner_id ), ARRAY_A );
		$rows  = (array) $rows;

		$brains_born    = count( $rows );
		$brains_active  = count( array_filter( $rows, static fn( $r ) => (int) $r['active'] === 1 ) );
		$max_replies    = 0;
		$total_replies  = 0;
		$max_trained    = 0;
		foreach ( $rows as $r ) {
			$max_replies   = max( $max_replies, (int) $r['customer_replies'] );
			$total_replies += (int) $r['customer_replies'];
			$max_trained   = max( $max_trained, (int) $r['trained_items'] );
		}

		// [2026-10-14 12:50 AM Johnny Chu - Chu Hoàng Anh] S108-L14 — the two counts the top ladder steps need (zero when the helper is absent).
		// PHASE-0.112 S112-L5 — Master reads the owner's MATURITY (the cell's activity, five axes × efficiency), not money: no payback count any more.
		$ids      = array_map( static fn( $r ) => (string) $r['account_id'], $rows );
		$trained  = array();
		foreach ( $rows as $r ) {
			$trained[ (string) $r['account_id'] ] = (int) $r['trained_items'];
		}
		$today    = function_exists( 'wp_date' ) ? wp_date( 'Y-m-d' ) : gmdate( 'Y-m-d' );
		$maturity = class_exists( 'BizCity_Brain_Maturity' ) ? BizCity_Brain_Maturity::for_accounts( $ids, $trained, $today ) : array( 'accounts' => array(), 'owner' => 0 );
		$maturity_avg = (int) $maturity['owner'];
		$diamond     = class_exists( 'BizCity_Brain_Payback_History' ) ? BizCity_Brain_Payback_History::diamond_tier_brains( $owner_id ) : 0;
		$tier = self::owner_tier( $brains_born, $brains_active, $max_replies, $total_replies, $max_trained, $maturity_avg, $diamond );

		$journey_table = BizCity_Brain_Journey_Schema::journey_table();
		$badges = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$journey_table} WHERE subject_kind = 'brain' AND event = 'milestone' AND code NOT LIKE 'payback.%%' AND code NOT LIKE 'savings.%%' AND owner_user_id = %d", $owner_id
		) );

		// [2026-10-14 01:20 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.108 S108-L13 — profile v2: the 6-step ladder with what is still missing,
		// real profit this month (Woo revenue − token cost; never mixed with the estimate), the time-saved ESTIMATE (always labelled), top 3 badges, name.
		$have = array(
			'brains_born' => $brains_born, 'brains_active' => $brains_active, 'max_brain_replies' => $max_replies, 'max_brain_trained' => $max_trained,
			'total_replies' => $total_replies, 'maturity_avg' => $maturity_avg, 'diamond_tier_brains' => $diamond,
		);
		$ladder = class_exists( 'BizCity_Brain_Owner_Ladder' ) ? BizCity_Brain_Owner_Ladder::build( $have ) : array( 'ladder' => array(), 'next' => null, 'missing' => array() );
		$top = (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT subject_id, code, tier, occurred_at FROM {$journey_table} WHERE subject_kind = 'brain' AND event = 'milestone' AND code NOT LIKE 'payback.%%' AND code NOT LIKE 'savings.%%' AND owner_user_id = %d
			 ORDER BY FIELD(tier,'diamond','gold','silver','bronze'), occurred_at DESC LIMIT 3", $owner_id
		), ARRAY_A );
		foreach ( $top as $i => $b ) {
			$top[ $i ]['label_masked'] = self::account_label_masked( (string) $b['subject_id'] );
			unset( $top[ $i ]['subject_id'] );
		}
		$user = get_userdata( $owner_id );

		return new WP_REST_Response( array(
			'contract'        => self::CONTRACT,
			'owner_user_id'   => $owner_id,
			'tier'            => $tier,
			'brains_total'    => $brains_born,
			'brains_active'   => $brains_active,
			'total_replies'   => $total_replies,
			'max_brain_replies' => $max_replies,
			'badge_count'     => $badges,
			'maturity_avg'        => $maturity_avg,
			'maturity'            => array_map( static fn( $a ) => array( 'maturity' => $a['maturity'], 'active' => $a['active'] ), $maturity['accounts'] ),
			'diamond_tier_brains' => $diamond,
			'name'                => $user ? (string) $user->display_name : '',
			'ladder'              => $ladder['ladder'],
			'next'                => $ladder['next'],
			'missing'             => $ladder['missing'],
			// PHASE-0.112 S112-L4b — no real_profit, no ₫ estimate / wage on Thành tích: hours only.
			'time_saved'          => self::hours_only( $total_replies ),
			'top_badges'          => $top,
		) );
	}

	/* ================================================================
	 *  "Khoảnh khắc chúc mừng" (§3.4, S108-U5) — admin notice next open, never mid-session push.
	 * ================================================================ */

	const OPT_SEEN_PREFIX = 'bizcity_brain_notif_seen_at'; // user meta key, per viewer.

	public static function route_notifications( WP_REST_Request $request ): WP_REST_Response {
		if ( ! class_exists( 'BizCity_Brain_Journey_Schema' ) || ! BizCity_Brain_Journey_Schema::installed() ) {
			return new WP_REST_Response( array( 'contract' => self::CONTRACT, 'events' => array(), 'since' => null ) );
		}
		$viewer = self::viewer();
		$since  = (string) get_user_meta( $viewer, self::OPT_SEEN_PREFIX, true );
		if ( '' === $since ) {
			// First time ever: don't dump the whole history as "new" — start the clock from now.
			self::touch_seen( $viewer );
			return new WP_REST_Response( array( 'contract' => self::CONTRACT, 'events' => array(), 'since' => null ) );
		}
		global $wpdb;
		$table = BizCity_Brain_Journey_Schema::journey_table();
		list( $where, $args ) = self::is_admin()
			? array( '1=1', array() )
			: array( 'owner_user_id = %d', array( $viewer ) );
		$rows = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $where is one of two hardcoded literals above, never request input.
			"SELECT seq, subject_id, occurred_at, event, code, tier, value FROM {$table}
			 WHERE event IN ('born','first_reply','milestone','payback') AND source <> 'backfill' AND occurred_at > %s AND {$where}
			 ORDER BY occurred_at DESC LIMIT 20",
			array_merge( array( $since ), $args )
		), ARRAY_A );
		$out = array();
		foreach ( (array) $rows as $r ) {
			$r['label_masked'] = self::account_label_masked( (string) $r['subject_id'] );
			// S108-L16: the notice opens the right screen (deep link), never a generic list.
			$r['link'] = '#/achievements?view=journey&id=' . rawurlencode( (string) $r['subject_id'] );
			$out[] = $r;
		}
		return new WP_REST_Response( array( 'contract' => self::CONTRACT, 'events' => $out, 'since' => $since ) );
	}

	public static function route_notifications_ack( WP_REST_Request $request ): WP_REST_Response {
		self::touch_seen( self::viewer() );
		return new WP_REST_Response( array( 'ok' => true ) );
	}

	private static function touch_seen( int $viewer ): void {
		update_user_meta( $viewer, self::OPT_SEEN_PREFIX, current_time( 'mysql', true ) );
	}

	/**
	 * Highest matching ladder step wins (10-SPEC §5, catalog `owner_tiers`). Pure.
	 * [2026-10-14 12:50 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.108 S108-L14 (G-8) — Master / Grandmaster are reachable now:
	 * `$payback_3mo_brains` = brains holding payback.silver/gold, `$diamond_tier_brains` = brains holding any *.diamond milestone.
	 *
	 * @return array{level:int,code:string,label:string}
	 */
	public static function owner_tier( int $brains_born, int $brains_active, int $max_replies, int $total_replies, int $max_trained, int $maturity_avg = 0, int $diamond_tier_brains = 0 ): array {
		if ( $brains_active >= 100 && $diamond_tier_brains >= 10 ) {
			return array( 'level' => 6, 'code' => 'grandmaster', 'label' => 'Grandmaster' );
		}
		if ( $brains_active >= 30 && $maturity_avg >= BizCity_Brain_Owner_Ladder::MASTER_MATURITY ) {
			return array( 'level' => 5, 'code' => 'master', 'label' => 'Master Zalo Brain' );
		}
		if ( $brains_active >= 10 && $total_replies >= 10000 ) {
			return array( 'level' => 4, 'code' => 'supervisor', 'label' => 'Quản đốc' );
		}
		if ( $brains_active >= 2 && $max_trained >= 50 ) {
			return array( 'level' => 3, 'code' => 'trainer', 'label' => 'Huấn luyện viên' );
		}
		if ( $max_replies >= 1000 ) {
			return array( 'level' => 2, 'code' => 'nurturer', 'label' => 'Người nuôi' );
		}
		if ( $brains_born >= 1 ) {
			return array( 'level' => 1, 'code' => 'sower', 'label' => 'Người gieo' );
		}
		return array( 'level' => 0, 'code' => 'none', 'label' => 'Chưa có trợ lý' );
	}

	/* ================================================================
	 *  Visibility (same shape as BizCity_Usage_View_REST::scope / may_open_account, 0.107/62 §5.4)
	 * ================================================================ */

	/** @return array{0:string,1:array<int,int>} */
	private static function scope(): array {
		$viewer = self::viewer();
		if ( self::is_admin() ) {
			return array( '1=1', array() );
		}
		return array( 'owner_user_id = %d', array( $viewer ) );
	}

	private static function may_open_account( string $id ): bool {
		if ( self::is_admin() ) {
			return true;
		}
		$owner = 0;
		if ( class_exists( 'BizCity_Zalo_Mapping_Repo' ) ) {
			$row   = (array) BizCity_Zalo_Mapping_Repo::find_account_by_bridge_id( 'personal', $id );
			$owner = (int) ( $row['owner_user_id'] ?? 0 );
		}
		return $owner > 0 && $owner === self::viewer();
	}

	/** PHASE-0.112 S112-L4b — the "làm thay" estimate without money: hours + minutes per reply, always labelled as an estimate. */
	private static function hours_only( int $replies ): array {
		$t = class_exists( 'BizCity_Brain_ROI' ) ? BizCity_Brain_ROI::time_saved_estimate( $replies ) : array();
		return array( 'hours' => $t['hours'] ?? null, 'minutes_per_reply' => $t['minutes_per_reply'] ?? null, 'estimate' => true );
	}

	private static function account_label_masked( string $id ): string {
		if ( ! class_exists( 'BizCity_Zalo_Mapping_Repo' ) ) {
			return 'Số …' . substr( $id, -3 );
		}
		$row   = (array) BizCity_Zalo_Mapping_Repo::find_account_by_bridge_id( 'personal', $id );
		$label = trim( (string) ( $row['label'] ?? $row['account_name'] ?? '' ) );
		if ( '' === $label ) {
			return 'Số …' . substr( $id, -3 );
		}
		// Same simple mask as usage-view-rest: keep head + tail digits, hide the middle.
		$digits = preg_replace( '/\D/', '', $label );
		if ( strlen( $digits ) >= 7 ) {
			return substr( $digits, 0, 4 ) . '•••' . substr( $digits, -3 );
		}
		return $label;
	}

	/** Masked label + the account's own name (when it is not just the phone number). */
	private static function account_info( string $id ): array {
		$name = '';
		if ( class_exists( 'BizCity_Zalo_Mapping_Repo' ) ) {
			$row  = (array) BizCity_Zalo_Mapping_Repo::find_account_by_bridge_id( 'personal', $id );
			$name = trim( (string) ( $row['account_name'] ?? '' ) );
			if ( '' !== $name && strlen( preg_replace( '/\D/', '', $name ) ) >= 7 ) {
				$name = ''; // a bare phone number is the label, not a name: never print it unmasked (G1).
			}
		}
		return array( 'label_masked' => self::account_label_masked( $id ), 'name' => $name );
	}

	private static function viewer(): int {
		return isset( self::$readers['viewer'] ) ? (int) call_user_func( self::$readers['viewer'] ) : get_current_user_id();
	}

	private static function is_admin(): bool {
		return isset( self::$readers['is_admin'] ) ? (bool) call_user_func( self::$readers['is_admin'] ) : current_user_can( 'manage_options' );
	}
}
