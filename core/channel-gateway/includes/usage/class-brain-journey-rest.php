<?php
/**
 * Menu "Sử dụng" → tab Thành tích REST — contract brain-journey@1 (PHASE-0.108 S108-L6, master plan §3.2, §3.4).
 * Namespace bizcity-channel/v1, same visibility rule as usage-view@1 (0.107/62 §5.4): manage rights see
 * everything; otherwise only accounts this WP user owns (`owner_user_id = viewer`). Reads only
 * `bizcity_brain_metrics` / `bizcity_brain_journey` (CL-side cache + ledger, R-CELL-METER-ONLY D108-2) —
 * never calls the cell or the Hub.
 *
 *   GET brain/leaderboard?sort=hardworking|efficient|earning|trained&page=&per_page=   (3 flags per row)
 *   GET brain/{id}/journey?limit=                              (timeline, newest first)
 *   GET brain/{id}/month?month=YYYY-MM                         (ROI card — §3.1 ①, D108-5's 3 separate lines)
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
		register_rest_route( self::NS, '/brain/(?P<id>[A-Za-z0-9._:-]{1,64})/journey', array(
			'methods' => 'GET', 'callback' => array( __CLASS__, 'route_journey' ), 'permission_callback' => $perm,
		) );
		register_rest_route( self::NS, '/brain/(?P<id>[A-Za-z0-9._:-]{1,64})/month', array(
			'methods' => 'GET', 'callback' => array( __CLASS__, 'route_month' ), 'permission_callback' => $perm,
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
			return new WP_REST_Response( array( 'contract' => self::CONTRACT, 'rows' => array(), 'total' => 0 ) );
		}
		global $wpdb;
		$table = BizCity_Brain_Journey_Schema::metrics_table();
		$sort  = (string) $request->get_param( 'sort' );
		$sort  = in_array( $sort, array( 'hardworking', 'efficient', 'earning', 'trained' ), true ) ? $sort : 'hardworking';
		$page  = max( 1, (int) $request->get_param( 'page' ) );
		$per   = min( self::PER_PAGE, max( 1, (int) ( $request->get_param( 'per_page' ) ?: self::PER_PAGE ) ) );
		$offset = ( $page - 1 ) * $per;

		list( $where, $args ) = self::scope();

		$order_by = array(
			'hardworking' => 'customer_replies DESC',
			'earning'     => '(thu_usd_month - chi_usd_month) DESC',
			'trained'     => 'trained_items DESC',
			// "rẻ nhất" = chi/khách thấp nhất, CÙNG KỲ (10-SPEC G-3 — chi là của tháng, khách phải là của tháng, không phải suốt đời).
			'efficient'   => 'CASE WHEN distinct_customers_month > 0 THEN chi_usd_month / distinct_customers_month ELSE NULL END ASC',
		)[ $sort ];

		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where}", $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows  = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$table} WHERE {$where} ORDER BY {$order_by} LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			array_merge( $args, array( $per, $offset ) )
		), ARRAY_A );

		$out = array();
		foreach ( (array) $rows as $r ) {
			$out[] = self::shape_row( $r );
		}
		return new WP_REST_Response( array(
			'contract' => self::CONTRACT, 'sort' => $sort, 'page' => $page, 'per_page' => $per, 'total' => $total, 'rows' => $out,
		) );
	}

	private static function shape_row( array $r ): array {
		$replies = (int) $r['customer_replies'];
		$chi     = (float) $r['chi_usd_month'];
		$thu     = (float) $r['thu_usd_month'];
		$customers_month = (int) $r['distinct_customers_month'];
		$flags = self::compute_flags( $r );
		return array(
			'account_id'          => $r['account_id'],
			'label_masked'        => self::account_label_masked( $r['account_id'] ),
			'owner_user_id'       => (int) $r['owner_user_id'],
			'customer_replies'    => $replies,
			'distinct_customers'  => (int) $r['distinct_customers'],
			'tasks_done'          => (int) $r['tasks_done'],
			'trained_items'       => (int) $r['trained_items'],
			'billed_tokens_month' => (int) $r['billed_tokens_month'],
			'chi_usd_month'       => round( $chi, 4 ),
			'thu_usd_month'       => round( $thu, 4 ),
			'lai_usd_month'       => round( $thu - $chi, 4 ),
			// 10-SPEC G-3: same-month numerator and denominator (lifetime distinct_customers would make an old brain look falsely cheap).
			'chi_per_customer_usd'=> $customers_month > 0 ? round( $chi / $customers_month, 4 ) : null,
			'streak_days'         => (int) $r['streak_days'],
			'active'              => (bool) $r['active'],
			'flags'               => $flags,
		);
	}

	/**
	 * 10-SPEC §9 G-4: 💤 = AI is on AND no customer row in the last 3 days (using last_customer_reply_at, or
	 * born_at when there has never been one — a brand-new, zero-reply number should still be able to flag once
	 * idle long enough). An AI-off number must never flag, regardless of how long it's been idle.
	 *
	 * @return array<int,string>
	 */
	private static function compute_flags( array $r ): array {
		$flags = array();
		if ( empty( $r['ai_enabled'] ) ) {
			return $flags; // AI off — never 💤, whatever the idle window.
		}
		$ref = $r['last_customer_reply_at'] ?: $r['born_at'];
		if ( null !== $ref && '' !== $ref ) {
			$days_idle = (int) floor( ( time() - strtotime( $ref . ' UTC' ) ) / DAY_IN_SECONDS );
			if ( $days_idle >= 3 ) {
				$flags[] = 'sleeping'; // 💤
			}
		}
		// 🔥 "tốn bất thường" (chi/lượt > 2x trung vị site) is a site-wide comparison — left to the leaderboard
		// endpoint's caller (needs the full page's distribution); not computed per-row here to avoid an N+1 query.
		return $flags;
	}

	/* ================================================================
	 *  One brain's journey timeline
	 * ================================================================ */

	public static function route_journey( WP_REST_Request $request ): WP_REST_Response {
		$id = (string) $request->get_param( 'id' );
		if ( ! self::may_open_account( $id ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'forbidden' ), 403 );
		}
		if ( ! class_exists( 'BizCity_Brain_Journey_Schema' ) || ! BizCity_Brain_Journey_Schema::installed() ) {
			return new WP_REST_Response( array( 'contract' => self::CONTRACT, 'account_id' => $id, 'events' => array() ) );
		}
		global $wpdb;
		$table = BizCity_Brain_Journey_Schema::journey_table();
		$limit = min( 200, max( 1, (int) ( $request->get_param( 'limit' ) ?: 50 ) ) );
		$rows  = $wpdb->get_results( $wpdb->prepare(
			"SELECT seq, occurred_at, event, code, tier, value, catalog_version, source FROM {$table}
			 WHERE subject_kind = 'brain' AND subject_id = %s ORDER BY seq DESC LIMIT %d", $id, $limit
		), ARRAY_A );
		return new WP_REST_Response( array( 'contract' => self::CONTRACT, 'account_id' => $id, 'events' => $rows ?: array() ) );
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
		$table = BizCity_Brain_Journey_Schema::metrics_table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE account_id = %s", $id ), ARRAY_A );
		if ( ! $row ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'no_data' ) );
		}
		$chi  = (float) $row['chi_usd_month'];
		$thu  = (float) $row['thu_usd_month'];
		$replies_month   = (int) $row['customer_replies_month'];
		$customers_month = (int) $row['distinct_customers_month'];
		// 10-SPEC §9 G-2: "làm thay" must use THIS MONTH's replies, not the lifetime total — otherwise the
		// estimate keeps growing every month even with the same daily volume, which is exactly what luật 4 bans.
		$time = class_exists( 'BizCity_Brain_ROI' ) ? BizCity_Brain_ROI::time_saved_estimate( $replies_month ) : array( 'hours' => 0, 'vnd_estimate' => 0 );
		return new WP_REST_Response( array(
			'contract'            => self::CONTRACT,
			'account_id'          => $id,
			'label_masked'        => self::account_label_masked( $id ),
			// D108-5: three separate lines, never summed into one number beyond thu - chi.
			'thu_usd_month'       => round( $thu, 4 ),
			'chi_usd_month'       => round( $chi, 4 ),
			'lai_usd_month'       => round( $thu - $chi, 4 ),
			'has_revenue'         => $thu > 0,
			// 10-SPEC G-3: same-month numerator and denominator.
			'chi_per_customer_usd'=> $customers_month > 0 ? round( $chi / $customers_month, 4 ) : null,
			'time_saved_estimate' => $time,
			'customer_replies'    => $replies_month,
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

		$tier = self::owner_tier( $brains_born, $brains_active, $max_replies, $total_replies, $max_trained );

		$journey_table = BizCity_Brain_Journey_Schema::journey_table();
		$badges = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$journey_table} WHERE subject_kind = 'brain' AND event = 'milestone' AND owner_user_id = %d", $owner_id
		) );

		return new WP_REST_Response( array(
			'contract'        => self::CONTRACT,
			'owner_user_id'   => $owner_id,
			'tier'            => $tier,
			'brains_total'    => $brains_born,
			'brains_active'   => $brains_active,
			'total_replies'   => $total_replies,
			'max_brain_replies' => $max_replies,
			'badge_count'     => $badges,
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
			 WHERE event IN ('born','first_reply','milestone','payback') AND occurred_at > %s AND {$where}
			 ORDER BY occurred_at DESC LIMIT 20",
			array_merge( array( $since ), $args )
		), ARRAY_A );
		$out = array();
		foreach ( (array) $rows as $r ) {
			$r['label_masked'] = self::account_label_masked( (string) $r['subject_id'] );
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

	/** @return array{level:int,code:string,label:string} */
	private static function owner_tier( int $brains_born, int $brains_active, int $max_replies, int $total_replies, int $max_trained ): array {
		// Highest matching tier wins (checked from the top down) — payback_3mo_brains / diamond_tier_brains
		// are not tracked yet this pass, so Master/Grandmaster can't be reached until that lands (fails closed).
		if ( $brains_active >= 2 && $max_trained >= 50 ) {
			$tier = array( 3, 'trainer', 'Huấn luyện viên' );
		}
		if ( $brains_active >= 10 && $total_replies >= 10000 ) {
			$tier = array( 4, 'supervisor', 'Quản đốc' );
		}
		if ( isset( $tier ) ) {
			return array( 'level' => $tier[0], 'code' => $tier[1], 'label' => $tier[2] );
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

	private static function viewer(): int {
		return isset( self::$readers['viewer'] ) ? (int) call_user_func( self::$readers['viewer'] ) : get_current_user_id();
	}

	private static function is_admin(): bool {
		return isset( self::$readers['is_admin'] ) ? (bool) call_user_func( self::$readers['is_admin'] ) : current_user_can( 'manage_options' );
	}
}
