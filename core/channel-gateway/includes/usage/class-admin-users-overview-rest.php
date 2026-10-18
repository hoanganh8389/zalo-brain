<?php
/**
 * Biz Central Brain — Johnny Chu (Chu Hoàng Anh). Bizcity Central Brain, Giấy chứng nhận đăng ký quyền tác giả số 8877/2026/QTG.
 */
// [2026-10-17 11:28 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.117 S117-M1 (doc 80 §6) — new file: admin "Người dùng" overview, READ ONLY.
/**
 * GET bizcity-channel/v1/admin/users-overview?range=24h|7d|30d&q=&f=&page=&per_page=&fresh=1   (contract admin-users-overview@1.0.0)
 *
 * One row per WordPress user of THIS blog: the Zalo numbers they own or operate (with owner confirmation), their highest
 * level, billed tokens, replies, tool calls and errors in the range, last activity. It only READS the existing owners:
 *   numbers ............ {prefix}bizcity_zalo_accounts (kind personal, live statuses)  — same rows as zalo-bridge/members
 *   owner confirmation .. BizCity_Usage_Accounts_REST::owner_of()                         — same as Sử dụng → Tài khoản
 *   people × level ...... BizCity_Channel_User_Grant::users_for_account('zalo_personal')  — same as GrantMatrix
 *   usage .............. BizCity_Usage_Schema daily (SUM by bound_user_id) + ledger (errors, last activity)
 * Nothing here writes. Every action button in the UI calls the route of the owner above (R-ADMIN-1, R-BOTSTUDIO).
 *
 * Not in M1 (null on purpose, the UI shows "—", never 0): connections (Composio is per person on the cell), four-step ③ ④,
 * Job results / pending approvals. They arrive with the cell rollup (S117-M6).
 *
 * Privacy (R-ADMIN-4 / R-ADMIN-10): no e-mail, no phone (labels masked), no raw Zalo UID, no money (₫ stays on Sử dụng),
 * no message / sheet / notebook content. Second guard: BizCity_Usage_View_REST::clean() + self::assert_clean().
 *
 * Gate (R-ADMIN-2, M0 2026-10-17): BizCity_Network_Admin_Capability::can_manage() = manage_options on this blog, or a Network
 * Super Admin with manage_network — the plugin family's one admin gate (PHASE-0.63 403 incident). Data is always THIS blog.
 *
 * Cache contract (R-CACHE): group `cg_admin_users`, key = blog | range | q | f | page | per_page, TTL 30 s, object cache only.
 * The writers (grants, owner transfer) live in other modules and do not know this group, so the UI sends `fresh=1` right after
 * its own write to bypass the read and repopulate; the short TTL bounds staleness for writes made elsewhere.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\ChannelGateway\Usage
 */

defined( 'ABSPATH' ) || exit;

// Loaded with require_once only (no class_exists self-guard: an early-bound declaration makes that guard always true).
final class BizCity_Admin_Users_Overview_REST {

	const NS          = 'bizcity-channel/v1';
	// [2026-10-18 12:48 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.117 S117-V2 (doc 81 §4.1) — 1.1.0: sort (num|name), filter no_numbers, activity route.
	const CONTRACT    = 'admin-users-overview@1.1.0';
	const CACHE_GROUP = 'cg_admin_users';
	const CACHE_TTL   = 30;
	const RANGES      = array( '24h', '7d', '30d' );
	const FILTERS     = array( 'all', 'attention', 'has_numbers', 'no_numbers', 'unverified_owner', 'auto_user', 'stale' );
	// [2026-10-18 02:23 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.117 S117-V2 — last (newest ledger row first) + tok (most billed tokens in the range first).
	const SORTS       = array( 'num', 'name', 'last', 'tok' ); // R-ADMIN-11: num (people with an assistant number first) is the default
	const ORDER_CAP   = 5000;                   // most user IDs ordered in one request (IDs only)
	const PER_PAGE    = 25;
	const MAX_PAGE    = 50;
	const STALE_SECS  = 259200; // 3 days, same as Sử dụng → Tài khoản
	const DEAD        = array( 'revoked', 'orphaned', 'deleted' );
	const LEVELS      = array( 'R0', 'R1', 'R2', 'R3' );
	const CHANNEL     = 'zalo_personal';
	/** Keys that must never be in the response (R-ADMIN-4 / R-ADMIN-10), on top of BizCity_Usage_View_REST::FORBIDDEN. */
	const FORBIDDEN   = array( 'email', 'user_email', 'phone', 'zalo_uid', 'owner_uid', 'chi_vnd', 'thu_vnd', 'lai_vnd', 'billed_usd', 'billed_vnd', 'usd', 'body', 'content', 'preview' );

	private static $initialized = false;

	public static function init(): void {
		if ( self::$initialized ) {
			return;
		}
		self::$initialized = true;
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes(): void {
		register_rest_route( self::NS, '/admin/users-overview', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'route' ),
			'permission_callback' => array( __CLASS__, 'can_manage' ),
		) );
		// [2026-10-17 12:05 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.117 S117-M5 (R-ADMIN-6) — "Xem như": what ONE person may do, read only, audited.
		register_rest_route( self::NS, '/admin/users-overview/(?P<id>\d+)/workspace', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'route_view_as' ),
			'permission_callback' => array( __CLASS__, 'can_manage' ),
		) );
		// [2026-10-18 12:48 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.117 S117-V2 (R-ADMIN-14) — "Hoạt động": per-day counts, top tools, last ledger rows (no content).
		register_rest_route( self::NS, '/admin/users-overview/(?P<id>\d+)/activity', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'route_activity' ),
			'permission_callback' => array( __CLASS__, 'can_manage' ),
		) );
	}

	/**
	 * [2026-10-18 12:48 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.117 S117-V2 — GET admin/users-overview/{id}/activity (admin-user-activity@1.0.0).
	 * Read only, bounded: one person, one range, three indexed queries (daily by bound_user_id + day, ledger by bound_user_id + time).
	 */
	public static function route_activity( WP_REST_Request $request ) {
		$target = (int) $request->get_param( 'id' );
		$blog   = (int) get_current_blog_id();
		if ( $target <= 0 || ! get_userdata( $target ) || ( function_exists( 'is_user_member_of_blog' ) && ! is_user_member_of_blog( $target, $blog ) ) ) {
			return new WP_REST_Response( array( 'success' => false, 'code' => 'user_not_found', 'message' => 'Không tìm thấy người dùng này trên website.', 'hint' => 'Tải lại danh sách rồi chọn lại.', 'help_code' => 'admin_users_not_found' ), 404 );
		}
		$range = self::range_of( $request->get_param( 'range' ) );
		$r     = class_exists( 'BizCity_Usage_View_REST' ) ? BizCity_Usage_View_REST::range( $range, time(), wp_timezone() ) : null;
		if ( ! $r || ! class_exists( 'BizCity_Usage_Schema' ) ) {
			return new WP_REST_Response( array( 'success' => false, '_degraded' => true, 'code' => 'activity_unavailable', 'message' => 'Chưa đọc được hoạt động của người này.', 'hint' => 'Cập nhật plugin rồi thử lại.', 'help_code' => 'admin_users_activity' ), 200 );
		}
		global $wpdb;
		try {
			$daily = (array) $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL
				'SELECT day, COALESCE(SUM(turns),0) AS replies, COALESCE(SUM(tool_calls),0) AS tool_calls, COALESCE(SUM(billed_tokens),0) AS billed_tokens FROM '
				. BizCity_Usage_Schema::daily_table() . ' WHERE bound_user_id = %d AND day >= %s AND day <= %s GROUP BY day',
				$target, $r['day_from'], $r['day_to']
			), ARRAY_A );
			$errs  = (array) $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL
				'SELECT occurred_day AS day, COALESCE(SUM(is_error),0) AS errors FROM ' . BizCity_Usage_Schema::ledger_table()
				. ' WHERE bound_user_id = %d AND occurred_at >= %s AND occurred_at < %s GROUP BY occurred_day',
				$target, $r['from'], $r['to']
			), ARRAY_A );
			$tools = (array) $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL
				'SELECT tool, COUNT(*) AS calls, COALESCE(SUM(is_error),0) AS errors FROM ' . BizCity_Usage_Schema::ledger_table()
				. " WHERE bound_user_id = %d AND occurred_at >= %s AND occurred_at < %s AND tool <> '' GROUP BY tool ORDER BY calls DESC LIMIT 8",
				$target, $r['from'], $r['to']
			), ARRAY_A );
			$recent = (array) $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL
				'SELECT occurred_at, kind, tool, is_error FROM ' . BizCity_Usage_Schema::ledger_table()
				. ' WHERE bound_user_id = %d ORDER BY occurred_at DESC LIMIT 10',
				$target
			), ARRAY_A );
		} catch ( Throwable $e ) {
			return new WP_REST_Response( array( 'success' => false, '_degraded' => true, 'code' => 'activity_unavailable', 'message' => 'Chưa đọc được hoạt động của người này.', 'hint' => 'Thử lại sau ít phút.', 'help_code' => 'admin_users_activity' ), 200 );
		}
		$out = array(
			'contract' => 'admin-user-activity@1.0.0',
			'range'    => $range,
			'as_of'    => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'series'   => self::activity_series( (string) $r['day_from'], (string) $r['day_to'], $daily, $errs ),
			'tools'    => array_map( static function ( $t ) {
				return array( 'tool' => substr( sanitize_key( (string) $t['tool'] ), 0, 80 ), 'calls' => (int) $t['calls'], 'errors' => (int) $t['errors'] );
			}, $tools ),
			'recent'   => array_map( static function ( $x ) {
				return array(
					'at'       => gmdate( 'Y-m-d\TH:i:s\Z', (int) strtotime( (string) $x['occurred_at'] . ' UTC' ) ),
					'kind'     => sanitize_key( (string) $x['kind'] ),
					'tool'     => substr( sanitize_key( (string) $x['tool'] ), 0, 80 ),
					'is_error' => ! empty( $x['is_error'] ),
				);
			}, $recent ),
		);
		$bad = self::assert_clean( $out );
		if ( '' !== $bad ) {
			return new WP_REST_Response( array( 'success' => false, 'code' => 'admin_users_leak_blocked', 'message' => 'Máy chủ chặn một trường có thể lộ thông tin cá nhân.', 'hint' => 'Báo đội kỹ thuật.', 'help_code' => 'admin_users_leak_blocked', 'blocked_field' => $bad ), 200 );
		}
		return new WP_REST_Response( $out, 200 );
	}

	/**
	 * [2026-10-18 12:48 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.117 S117-V2 — one row per day from $day_from to $day_to (inclusive, site dates);
	 * a day without rows is a TRUE zero (the site ledger answered). Capped at 31 days. Pure.
	 *
	 * @param array $daily [{day, replies, tool_calls, billed_tokens}]
	 * @param array $errs  [{day, errors}]
	 */
	public static function activity_series( string $day_from, string $day_to, array $daily, array $errs ): array {
		$by = array();
		foreach ( $daily as $d ) {
			$by[ (string) $d['day'] ] = array( 'replies' => (int) $d['replies'], 'tool_calls' => (int) $d['tool_calls'], 'billed_tokens' => (int) $d['billed_tokens'] );
		}
		$er = array();
		foreach ( $errs as $e ) {
			$er[ (string) $e['day'] ] = (int) $e['errors'];
		}
		$out = array();
		$t   = strtotime( $day_from . ' 00:00:00 UTC' );
		$end = strtotime( $day_to . ' 00:00:00 UTC' );
		if ( false === $t || false === $end ) {
			return $out;
		}
		for ( $i = 0; $t <= $end && $i < 31; $i++, $t += 86400 ) {
			$day   = gmdate( 'Y-m-d', $t );
			$row   = $by[ $day ] ?? array( 'replies' => 0, 'tool_calls' => 0, 'billed_tokens' => 0 );
			$out[] = array( 'day' => $day ) + $row + array( 'errors' => $er[ $day ] ?? 0 );
		}
		return $out;
	}

	/**
	 * [2026-10-18 12:48 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.117 S117-V2 (R-ADMIN-11) — people with an assistant number first, each group keeps
	 * the incoming order (display_name). Applied to ALL matching IDs before paging, so page 2 never mixes the groups. Pure.
	 *
	 * @param int[] $ids_in_name_order
	 * @param int[] $holder_ids users that own or operate a number
	 */
	public static function order_ids( array $ids_in_name_order, array $holder_ids ): array {
		$hold  = array_flip( array_map( 'intval', $holder_ids ) );
		$first = array();
		$rest  = array();
		foreach ( $ids_in_name_order as $id ) {
			$id = (int) $id;
			if ( isset( $hold[ $id ] ) ) {
				$first[] = $id;
			} else {
				$rest[] = $id;
			}
		}
		return array_merge( $first, $rest );
	}

	/**
	 * [2026-10-18 02:23 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.117 S117-V2 — order ids by a metric, biggest first; ties and ids without a metric keep
	 * the incoming (display_name) order, so people with no activity sink to the end in name order. Pure, stable.
	 *
	 * @param int[]              $ids_in_name_order
	 * @param array<int,int|float> $metric user_id => number (missing ⇒ 0)
	 */
	public static function order_by_metric( array $ids_in_name_order, array $metric ): array {
		$pos = array();
		foreach ( array_values( $ids_in_name_order ) as $i => $id ) {
			$pos[ (int) $id ] = $i;
		}
		$ids = array_keys( $pos );
		usort( $ids, static function ( $a, $b ) use ( $metric, $pos ) {
			$ma = (float) ( $metric[ $a ] ?? 0 );
			$mb = (float) ( $metric[ $b ] ?? 0 );
			if ( $ma === $mb ) {
				return $pos[ $a ] <=> $pos[ $b ];
			}
			return $mb <=> $ma;
		} );
		return $ids;
	}

	public static function sort_of( $raw ): string {
		return in_array( (string) $raw, self::SORTS, true ) ? (string) $raw : 'num';
	}

	/**
	 * [2026-10-17 12:05 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.117 S117-M5 — project the person's `user-workspace@1` packet into what an admin may
	 * see: role, plan ceiling, modules, modes, level per assistant (label masked, never the raw account id), count of bound sheets. No user_ref,
	 * no contact ids, no spreadsheet ids, no display name (the row already has it). Pure.
	 *
	 * @param array $ws     the packet from BizCity_User_Workspace_Builder::build()
	 * @param array $labels account_id => masked label
	 */
	public static function view_as_projection( array $ws, array $labels ): array {
		$assist = array();
		foreach ( (array) ( $ws['assistants'] ?? array() ) as $a ) {
			$id       = (string) ( $a['account_id'] ?? '' );
			$assist[] = array(
				'kind'         => (string) ( $a['kind'] ?? '' ),
				'label_masked' => $labels[ $id ] ?? ( 'Số …' . substr( $id, -3 ) ),
				'own'          => ! empty( $a['own'] ),
				'level'        => (string) ( $a['level'] ?? '' ),
				'status'       => (string) ( $a['status'] ?? '' ),
			);
		}
		$plan = (array) ( $ws['plan'] ?? array() );
		return array(
			'contract'      => 'admin-view-as@1.0.0',
			'read_only'     => true,
			'expires_in_s'  => 900,
			'rev'           => (int) ( $ws['rev'] ?? 0 ),
			'role'          => (string) ( $ws['profile']['role'] ?? '' ),
			'placeholder'   => ! empty( $ws['profile']['placeholder'] ),
			'plan'          => array( 'tier' => (string) ( $plan['tier'] ?? '' ), 'max_risk' => (string) ( $plan['max_risk'] ?? '' ) ),
			'modules'       => array_values( array_map( 'strval', (array) ( $ws['modules'] ?? array() ) ) ),
			'modes'         => array_values( array_map( 'strval', (array) ( $ws['modes'] ?? array() ) ) ),
			'assistants'    => $assist,
			'sheets_bound'  => count( (array) ( $ws['bindings']['sheets'] ?? array() ) ),
			'connections'   => null, // per person on the cell (049); arrives with S117-M6
		);
	}

	/**
	 * GET admin/users-overview/{id}/workspace — R-ADMIN-6. Builds the SAME packet the cell receives (so the admin sees what the person really
	 * may do), then projects it. Calling build() may raise the packet's `rev` counter when the content changed since the last build: that is
	 * bookkeeping of the packet, not business data, and the pusher compares against the last rev the cell ACCEPTED, so no push is lost.
	 * Every view writes one audit line (who looked at whom, never what).
	 */
	public static function route_view_as( WP_REST_Request $request ) {
		$target = (int) $request->get_param( 'id' );
		$blog   = (int) get_current_blog_id();
		if ( $target <= 0 || ! get_userdata( $target ) || ( function_exists( 'is_user_member_of_blog' ) && ! is_user_member_of_blog( $target, $blog ) ) ) {
			return new WP_REST_Response( array( 'success' => false, 'code' => 'user_not_found', 'message' => 'Không tìm thấy người dùng này trên website.', 'hint' => 'Tải lại danh sách rồi chọn lại.', 'help_code' => 'admin_users_not_found' ), 404 );
		}
		if ( ! class_exists( 'BizCity_User_Workspace_Builder' ) ) {
			return new WP_REST_Response( array( 'success' => false, '_degraded' => true, 'code' => 'workspace_unavailable', 'message' => 'Chưa xem được không gian của người này.', 'hint' => 'Cập nhật plugin rồi thử lại.', 'help_code' => 'admin_users_view_as' ), 200 );
		}
		$ws = BizCity_User_Workspace_Builder::build( $target );
		if ( ! is_array( $ws ) ) {
			return new WP_REST_Response( array( 'success' => false, 'code' => 'user_not_found', 'message' => 'Không tìm thấy người dùng này trên website.', 'hint' => 'Tải lại danh sách rồi chọn lại.', 'help_code' => 'admin_users_not_found' ), 404 );
		}
		$labels = array();
		foreach ( self::accounts() as $a ) {
			$labels[ (string) $a['account_id'] ] = (string) $a['label_masked'];
		}
		$out = self::view_as_projection( $ws, $labels );
		// [2026-10-17 12:40 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.117 S117-M6 — this person's logins from the cell (masked); stays null when unmeasured.
		$rollup = ( class_exists( 'BizCity_Connect_Composio_REST' ) && method_exists( 'BizCity_Connect_Composio_REST', 'admin_rollup' ) ) ? BizCity_Connect_Composio_REST::admin_rollup() : null;
		$part   = self::cell_part( $rollup, class_exists( 'BizCity_Agent_Mode_Access' ) ? (string) BizCity_Agent_Mode_Access::user_ref( $target ) : '' );
		if ( null !== $part ) {
			$out['connections']      = $part['connections'];
			$out['pending_approval'] = $part['pending_approval'];
		}
		if ( class_exists( 'BizCity_Channel_File_Logger' ) ) {
			BizCity_Channel_File_Logger::write( 'channel_gateway', 'info', 'admin_view_as', 'Admin viewed a person workspace (read only)', array( 'viewer' => (int) get_current_user_id(), 'target' => $target, 'blog' => $blog ) );
		}
		$bad = self::assert_clean( $out );
		if ( '' !== $bad ) {
			return new WP_REST_Response( array( 'success' => false, 'code' => 'admin_users_leak_blocked', 'message' => 'Máy chủ chặn một trường có thể lộ thông tin cá nhân.', 'hint' => 'Báo đội kỹ thuật.', 'help_code' => 'admin_users_leak_blocked', 'blocked_field' => $bad ), 200 );
		}
		return new WP_REST_Response( $out, 200 );
	}

	/** R-ADMIN-2. A WP_Error carries the four R-ERROR-UX fields in its data so the SPA shows the same sheet as everywhere. */
	public static function can_manage() {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'rest_not_logged_in', 'Bạn cần đăng nhập.', array( 'status' => 401, 'hint' => 'Đăng nhập lại rồi mở trang này.', 'help_code' => 'login_required' ) );
		}
		$ok = class_exists( 'BizCity_Network_Admin_Capability' ) ? BizCity_Network_Admin_Capability::can_manage() : current_user_can( 'manage_options' );
		if ( ! $ok ) {
			return new WP_Error( 'admin_only', 'Chỉ quản trị website được xem màn hình này.', array( 'status' => 403, 'hint' => 'Nhờ quản trị website mở trang Người dùng.', 'help_code' => 'admin_users_forbidden' ) );
		}
		return true;
	}

	/* ── pure helpers (tests: tests/harness/admin-users-overview-harness.php) ───────────────────────────────── */

	public static function range_of( $raw ): string {
		return in_array( (string) $raw, self::RANGES, true ) ? (string) $raw : '7d';
	}

	public static function filter_of( $raw ): string {
		return in_array( (string) $raw, self::FILTERS, true ) ? (string) $raw : 'all';
	}

	public static function per_page_of( $raw ): int {
		$n = (int) $raw;
		return $n <= 0 ? self::PER_PAGE : min( self::MAX_PAGE, $n );
	}

	/** Highest of a list of R-levels; '' when none. */
	public static function max_level( array $levels ): string {
		$best = -1;
		foreach ( $levels as $l ) {
			$i = array_search( strtoupper( (string) $l ), self::LEVELS, true );
			if ( false !== $i && $i > $best ) {
				$best = $i;
			}
		}
		return $best < 0 ? '' : self::LEVELS[ $best ];
	}

	/** A display name can be an e-mail or carry a phone (WordPress allows it): mask both, keep the rest readable. */
	public static function mask_name( string $name ): string {
		$name = (string) preg_replace_callback( '/([A-Za-z0-9._%+-])[A-Za-z0-9._%+-]*@([A-Za-z0-9.-]+\.[A-Za-z]{2,})/', static function ( $m ) {
			return $m[1] . '••@' . $m[2];
		}, $name );
		return class_exists( 'BizCity_Usage_View_REST' ) ? BizCity_Usage_View_REST::mask( $name ) : (string) preg_replace( '/\d{9,}/', '•••', $name );
	}

	/** real | number_work (made by BizCity_Number_Work_User) | placeholder (S116-A2 meta). */
	public static function kind_of( bool $is_auto, bool $is_placeholder ): string {
		if ( $is_placeholder ) {
			return 'placeholder';
		}
		return $is_auto ? 'number_work' : 'real';
	}

	/**
	 * Group number rows + roster by WP user.
	 *
	 * @param array $accounts [{account_id, label_masked, owner_user_id, status, owner:{state,uid_masked}}]
	 * @param array $roster   account_id => [{user_id, relation, level}]
	 * @return array user_id => [{account, label_masked, relation, level, owner_state, status}]
	 */
	public static function numbers_by_user( array $accounts, array $roster ): array {
		$out = array();
		foreach ( $accounts as $a ) {
			$id     = (string) $a['account_id'];
			$owner  = (int) $a['owner_user_id'];
			$seen   = array();
			$people = isset( $roster[ $id ] ) ? (array) $roster[ $id ] : array();
			foreach ( $people as $p ) {
				$uid = (int) ( $p['user_id'] ?? 0 );
				if ( $uid <= 0 || isset( $seen[ $uid ] ) ) {
					continue;
				}
				$seen[ $uid ] = true;
				$primary      = 'primary' === (string) ( $p['relation'] ?? '' ) || $uid === $owner;
				$out[ $uid ][] = self::number_cell( $a, $primary ? 'primary' : 'operator', (string) ( $p['level'] ?? '' ) );
			}
			// The account table names an owner the grant layer has not bound yet (accounts made before R116-17): still show it as theirs.
			if ( $owner > 0 && ! isset( $seen[ $owner ] ) ) {
				$out[ $owner ][] = self::number_cell( $a, 'primary', '' );
			}
		}
		return $out;
	}

	private static function number_cell( array $a, string $relation, string $level ): array {
		return array(
			'account'      => (string) $a['account_id'],
			'label_masked' => (string) $a['label_masked'],
			'relation'     => $relation,
			'level'        => '' === $level ? null : strtoupper( $level ),
			'owner_state'  => (string) ( $a['owner']['state'] ?? 'none' ),
			'status'       => (string) $a['status'],
			'has_guru'     => ! empty( $a['has_guru'] ), // [2026-10-17 02:10 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.117 S117-M6 — step ④ (binding character_id > 0)
		);
	}

	/**
	 * [2026-10-17 02:10 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.117 S117-M6 (doc 80 §3, Q117-10) — R-SETUP-4 steps ③ ④ of ONE person, from the same
	 * facts the member's own four-step screen reads (MemberFourStep.jsx): ③ = a number THEY OWN is `connected`; ④ = every connected number they
	 * own has an Agent Guru bound. Values = the stepper states: ok | active (their next step) | fail (owns numbers, none logged in) | locked.
	 * ① ② are the site's (gate): when the gate is closed, ③ ④ are locked for everyone. Pure.
	 */
	public static function steps_of( array $numbers, bool $gate_open ): array {
		if ( ! $gate_open ) {
			return array( 's3' => 'locked', 's4' => 'locked' );
		}
		$own = array_values( array_filter( $numbers, static function ( $n ) { return 'primary' === ( $n['relation'] ?? '' ); } ) );
		$on  = array_values( array_filter( $own, static function ( $n ) { return 'connected' === ( $n['status'] ?? '' ); } ) );
		if ( ! $on ) {
			return array( 's3' => $own ? 'fail' : 'active', 's4' => 'locked' );
		}
		foreach ( $on as $n ) {
			if ( empty( $n['has_guru'] ) ) {
				return array( 's3' => 'ok', 's4' => 'active' );
			}
		}
		return array( 's3' => 'ok', 's4' => 'ok' );
	}

	/**
	 * One row of the contract. $usage / $ledger may be absent for a user (no activity ⇒ zeros are TRUE zeros: the site ledger
	 * is the source and it answered). Cell-only fields are null (S117-M6).
	 */
	/**
	 * [2026-10-17 12:40 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.117 S117-M6 — this person's part of the cell rollup. null rollup ⇒ null (not
	 * measured); a rollup without this person ⇒ zero logins / zero drafts (TRUE zeros: the cell answered). Labels arrive masked from the cell;
	 * only the fields below pass (never user_ref, never an account id).
	 */
	public static function cell_part( ?array $rollup, string $user_ref ): ?array {
		if ( null === $rollup ) {
			return null;
		}
		foreach ( (array) ( $rollup['people'] ?? array() ) as $p ) {
			if ( '' !== $user_ref && (string) ( $p['user_ref'] ?? '' ) === $user_ref ) {
				$conns = array();
				foreach ( (array) ( $p['connections'] ?? array() ) as $c ) {
					$conns[] = array(
						'toolkit' => substr( sanitize_key( (string) ( $c['toolkit'] ?? '' ) ), 0, 32 ),
						'status'  => substr( strtoupper( preg_replace( '/[^A-Za-z_]/', '', (string) ( $c['status'] ?? '' ) ) ), 0, 20 ),
						'label'   => substr( (string) ( $c['label'] ?? '' ), 0, 80 ),
						'default' => ! empty( $c['default'] ),
					);
				}
				return array( 'connections' => $conns, 'pending_approval' => (int) ( $p['pending_approval'] ?? 0 ) );
			}
		}
		return array( 'connections' => array(), 'pending_approval' => 0 );
	}

	public static function build_row( array $u, array $numbers, array $usage, array $ledger, int $now, ?array $cell = null, ?bool $gate_open = null ): array {
		$levels = array();
		foreach ( $numbers as $n ) {
			if ( 'primary' === $n['relation'] ) {
				$levels[] = 'R3'; // the owner of an account holds every level on it (grant layer: primary = all permissions)
			} elseif ( null !== $n['level'] ) {
				$levels[] = $n['level'];
			}
		}
		$last  = isset( $ledger['last_at'] ) && '' !== (string) $ledger['last_at'] ? (string) $ledger['last_at'] : null; // UTC 'Y-m-d H:i:s'
		$stale = null !== $last && ( $now - (int) strtotime( $last . ' UTC' ) ) > self::STALE_SECS;
		return array(
			'user'        => array(
				'id'      => (int) $u['id'],
				'name'    => (string) $u['name'],
				'wp_role' => (string) $u['wp_role'],
				'kind'    => (string) $u['kind'],
			),
			'numbers'     => array_values( $numbers ),
			'connections' => null === $cell ? null : $cell['connections'], // S117-M6
			'level'       => '' === self::max_level( $levels ) ? null : self::max_level( $levels ),
			'steps'       => null === $gate_open ? array( 's3' => null, 's4' => null ) : self::steps_of( $numbers, $gate_open ), // S117-M6 (null gate = unknown ⇒ "—")
			'usage'       => array( 'billed_tokens' => (int) ( $usage['tokens'] ?? 0 ) ),
			'results'     => array(
				'replies'          => (int) ( $usage['turns'] ?? 0 ),
				'tool_calls'       => (int) ( $usage['tool_calls'] ?? 0 ),
				'errors'           => (int) ( $ledger['errors'] ?? 0 ),
				'jobs_ok'          => null,
				'jobs_failed'      => null,
				'pending_approval' => null === $cell ? null : (int) $cell['pending_approval'], // S117-M6
			),
			'last_active' => null === $last ? null : gmdate( 'Y-m-d\TH:i:s\Z', (int) strtotime( $last . ' UTC' ) ),
			'stale'       => $stale,
		);
	}

	/** Which users a quick filter keeps (null = no restriction). Pure: works on the grouped numbers + usage. */
	public static function filter_ids( string $f, array $numbers_by_user, array $auto_ids, array $last_by_user, int $now ): ?array {
		if ( 'all' === $f ) {
			return null;
		}
		$keep = array();
		foreach ( $numbers_by_user as $uid => $nums ) {
			$unverified = false;
			foreach ( $nums as $n ) {
				if ( 'primary' === $n['relation'] && 'verified' !== $n['owner_state'] ) {
					$unverified = true;
				}
			}
			$last  = $last_by_user[ $uid ] ?? '';
			$stale = '' === $last || ( $now - (int) strtotime( $last . ' UTC' ) ) > self::STALE_SECS;
			if ( 'has_numbers' === $f
				|| ( 'unverified_owner' === $f && $unverified )
				|| ( 'stale' === $f && $stale )
				|| ( 'attention' === $f && ( $unverified || $stale ) ) ) {
				$keep[] = (int) $uid;
			}
		}
		if ( 'auto_user' === $f ) {
			$keep = array_map( 'intval', $auto_ids );
		}
		return array_values( array_unique( $keep ) );
	}

	/**
	 * Second guard: fail closed if a forbidden key, an e-mail, a 9+ digit phone or a 64-hex hash reached the payload.
	 *
	 * @return string '' when clean, else the first offending path
	 */
	public static function assert_clean( $data, string $path = '' ): string {
		if ( is_array( $data ) ) {
			foreach ( $data as $k => $v ) {
				$p = '' === $path ? (string) $k : $path . '.' . $k;
				if ( is_string( $k ) && in_array( $k, self::FORBIDDEN, true ) ) {
					return $p;
				}
				$bad = self::assert_clean( $v, $p );
				if ( '' !== $bad ) {
					return $bad;
				}
			}
			return '';
		}
		if ( is_string( $data ) ) {
			if ( preg_match( '/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', $data )
				|| preg_match( '/(?<![\d•])\d{9,}(?![\d•])/', $data )
				|| preg_match( '/\b[a-f0-9]{64}\b/', $data ) ) {
				return $path;
			}
		}
		return '';
	}

	/* ── route ──────────────────────────────────────────────────────────────────────────────────────────── */

	public static function route( WP_REST_Request $request ) {
		$range    = self::range_of( $request->get_param( 'range' ) );
		$f        = self::filter_of( $request->get_param( 'f' ) );
		$q        = trim( sanitize_text_field( (string) $request->get_param( 'q' ) ) );
		$page     = max( 1, (int) $request->get_param( 'page' ) );
		$per_page = self::per_page_of( $request->get_param( 'per_page' ) );
		$sort     = self::sort_of( $request->get_param( 'sort' ) ); // [2026-10-18 12:48 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.117 S117-V2 — R-ADMIN-11
		$blog     = (int) get_current_blog_id();
		$key      = md5( implode( '|', array( $blog, $range, $q, $f, $page, $per_page, $sort ) ) );

		if ( ! $request->get_param( 'fresh' ) && class_exists( 'BizCity_Cache' ) ) {
			$hit = BizCity_Cache::get( self::CACHE_GROUP, $key );
			if ( is_array( $hit ) ) {
				return new WP_REST_Response( $hit, 200 );
			}
		}

		try {
			$data = self::build( $range, $f, $q, $page, $per_page, $blog, $sort );
		} catch ( Throwable $e ) {
			if ( class_exists( 'BizCity_Channel_File_Logger' ) ) {
				BizCity_Channel_File_Logger::write( 'channel_gateway', 'error', 'admin_users_overview_failed', 'Admin users overview failed', array( 'error' => substr( $e->getMessage(), 0, 160 ) ) );
			}
			$err = class_exists( 'BizCity_Error_Payload' )
				? BizCity_Error_Payload::make( 'admin_users_failed', 'Không đọc được danh sách người dùng.', 'Thử lại sau ít phút; nếu vẫn lỗi, mở Sử dụng → Tài khoản.', 'admin_users_failed' )
				: array( 'success' => false, 'code' => 'admin_users_failed', 'message' => 'Không đọc được danh sách người dùng.', 'hint' => 'Thử lại sau ít phút.', 'help_code' => 'admin_users_failed' );
			return new WP_REST_Response( $err, 200 ); // fail open for the SPA (no retry loop), R-GW-8 style
		}

		$bad = self::assert_clean( $data );
		if ( '' !== $bad ) {
			// Never ship a leak: drop the rows, say so. The path (not the value) goes to the log.
			if ( class_exists( 'BizCity_Channel_File_Logger' ) ) {
				BizCity_Channel_File_Logger::write( 'channel_gateway', 'error', 'admin_users_overview_leak_blocked', 'Payload guard blocked a field', array( 'path' => $bad ) );
			}
			$data['rows']          = array();
			$data['blocked_field'] = $bad;
		}
		if ( class_exists( 'BizCity_Cache' ) ) {
			BizCity_Cache::set( self::CACHE_GROUP, $key, $data, self::CACHE_TTL );
		}
		return new WP_REST_Response( $data, 200 );
	}

	/** Gathers the page. All SQL is bounded: accounts of this blog (tens), users of one page, usage of those users only. */
	public static function build( string $range, string $f, string $q, int $page, int $per_page, int $blog, string $sort = 'num' ): array {
		$now      = time();
		$r        = class_exists( 'BizCity_Usage_View_REST' ) ? BizCity_Usage_View_REST::range( $range, $now, wp_timezone() ) : null;
		$accounts = self::accounts();
		$roster   = self::roster( $accounts );
		$by_user  = self::numbers_by_user( $accounts, $roster );
		$auto_ids = self::auto_user_ids();
		$all_last = in_array( $f, array( 'all', 'no_numbers' ), true ) ? array() : self::last_activity( array_keys( $by_user ) );
		$include  = 'no_numbers' === $f ? null : self::filter_ids( $f, $by_user, $auto_ids, $all_last, $now );

		$args = array(
			'blog_id'     => $blog,
			'number'      => $per_page,
			'paged'       => $page,
			'orderby'     => 'display_name',
			'order'       => 'ASC',
			'count_total' => true,
			'fields'      => array( 'ID', 'display_name', 'user_login' ),
		);
		if ( '' !== $q ) {
			$args['search']         = '*' . $q . '*';
			$args['search_columns'] = array( 'user_login', 'user_email', 'display_name' );
		}
		if ( null !== $include ) {
			$args['include'] = $include ? $include : array( 0 ); // empty filter ⇒ no row, not "everyone"
		}
		// [2026-10-18 12:48 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.117 S117-V2 — filter "Chưa có số" = everyone minus number holders.
		if ( 'no_numbers' === $f && $by_user ) {
			$args['exclude'] = array_map( 'intval', array_keys( $by_user ) );
		}
		// [2026-10-18 12:48 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.117 S117-V2 (R-ADMIN-11) — sort=num: order ALL matching IDs (holders first) and
		// page that list, so the groups never mix across pages. IDs only, capped; the page itself is then read in that exact order.
		$by_num    = 'num' === $sort && $by_user && 'no_numbers' !== $f && 'has_numbers' !== $f;
		$by_metric = in_array( $sort, array( 'last', 'tok' ), true ); // [2026-10-18 02:23 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.117 S117-V2
		if ( $by_num || $by_metric ) {
			$id_args = $args;
			unset( $id_args['paged'] );
			$id_args['number']      = self::ORDER_CAP;
			$id_args['fields']      = 'ID';
			$id_args['count_total'] = false;
			$all_ids = array_map( 'intval', (array) ( new WP_User_Query( $id_args ) )->get_results() );
			if ( $by_metric ) {
				// One grouped read for ALL matching ids (indexed: bound_user_id + day / ledger bound_user_id), then page the ordered list.
				if ( 'tok' === $sort ) {
					$metric = array();
					foreach ( $r ? self::usage_by_user( $r['day_from'], $r['day_to'], $all_ids ) : array() as $uid => $u2 ) {
						$metric[ (int) $uid ] = (int) $u2['tokens'];
					}
				} else {
					$metric = array();
					foreach ( self::last_activity( $all_ids ) as $uid => $at ) {
						$metric[ (int) $uid ] = (int) strtotime( (string) $at . ' UTC' );
					}
				}
				$ordered = self::order_by_metric( $all_ids, $metric );
			} else {
				$ordered = self::order_ids( $all_ids, array_keys( $by_user ) );
			}
			$total   = count( $ordered );
			$slice   = array_slice( $ordered, ( $page - 1 ) * $per_page, $per_page );
			$users   = $slice ? (array) ( new WP_User_Query( array( 'blog_id' => $blog, 'include' => $slice, 'orderby' => 'include', 'number' => count( $slice ), 'count_total' => false, 'fields' => array( 'ID', 'display_name', 'user_login' ) ) ) )->get_results() : array();
		} else {
			$query = new WP_User_Query( $args );
			$users = (array) $query->get_results();
			$total = (int) $query->get_total();
		}
		$ids   = array_map( static function ( $u ) { return (int) $u->ID; }, $users );

		// [2026-10-17 12:40 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.117 S117-M6 — one cell call per page (not per row); null when unreachable.
		$rollup = ( class_exists( 'BizCity_Connect_Composio_REST' ) && method_exists( 'BizCity_Connect_Composio_REST', 'admin_rollup' ) ) ? BizCity_Connect_Composio_REST::admin_rollup() : null;
		$usage  = $r ? self::usage_by_user( $r['day_from'], $r['day_to'], $ids ) : array();
		$ledger = $r ? self::ledger_by_user( $r['from'], $r['to'], $ids ) : array();
		$last   = self::last_activity( $ids );
		// [2026-10-17 02:10 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.117 S117-M6 — ① is the site's gate for everyone's ③ ④ (② = Zalo Hub, checked live by the
		// four-step screen; not re-probed here). Unknown ① (no LLM client) ⇒ steps stay null ("—"), never guessed.
		$step1 = null;
		if ( class_exists( 'BizCity_LLM_Client' ) ) {
			$step1 = BizCity_LLM_Client::instance()->is_ready() ? 'pass' : 'fail';
		}
		$gate = null === $step1 ? null : ( 'pass' === $step1 );

		$rows = array();
		foreach ( $users as $u ) {
			$uid  = (int) $u->ID;
			$wpu  = get_userdata( $uid );
			$role = $wpu && ! empty( $wpu->roles ) ? (string) reset( $wpu->roles ) : '';
			$name = (string) ( $u->display_name ?: $u->user_login );
			$l    = isset( $ledger[ $uid ] ) ? $ledger[ $uid ] : array();
			if ( isset( $last[ $uid ] ) ) {
				$l['last_at'] = $last[ $uid ];
			}
			$rows[] = self::build_row(
				array(
					'id'      => $uid,
					'name'    => self::mask_name( $name ),
					'wp_role' => $role,
					'kind'    => self::kind_of( in_array( $uid, $auto_ids, true ), '' !== (string) get_user_meta( $uid, 'bizcity_placeholder', true ) ),
				),
				$by_user[ $uid ] ?? array(),
				$usage[ $uid ] ?? array(),
				$l,
				$now,
				self::cell_part( $rollup, ( null !== $rollup && class_exists( 'BizCity_Agent_Mode_Access' ) ) ? (string) BizCity_Agent_Mode_Access::user_ref( $uid ) : '' ), // S117-M6
				$gate
			);
		}

		$no_owner = 0;
		foreach ( $accounts as $a ) {
			if ( (int) $a['owner_user_id'] <= 0 ) {
				++$no_owner;
			}
		}
		$counts = function_exists( 'count_users' ) ? count_users() : array();

		$out = array(
			'contract' => self::CONTRACT,
			'as_of'    => gmdate( 'Y-m-d\TH:i:s\Z', $now ),
			'range'    => $range,
			'filter'   => $f,
			'sort'     => $sort, // [2026-10-18 12:48 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.117 S117-V2
			'sources'  => array( 'site' => true, 'cell' => null !== $rollup ), // S117-M6: false ⇒ cell fields are null ("—"), never 0
			'system'   => array(
				'step1'                 => $step1,
				'step2'                 => null,
				'users_total'           => (int) ( $counts['total_users'] ?? 0 ),
				'numbers_total'         => count( $accounts ),
				'users_with_numbers'    => count( $by_user ), // [2026-10-18 12:48 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.117 S117-V2 — KPI "Có số trợ lý"
				'numbers_without_owner' => $no_owner,
				'connections_failing'   => null === $rollup ? null : (int) ( $rollup['connections_failing'] ?? 0 ), // S117-M6
				'shop_connections'      => null === $rollup ? null : count( (array) ( $rollup['shop']['connections'] ?? array() ) ),
				'shop_pending'          => null === $rollup ? null : (int) ( $rollup['shop']['pending_approval'] ?? 0 ),
				'unassigned_logins'     => null === $rollup ? null : (int) ( $rollup['shop']['unassigned'] ?? 0 ),
			),
			'rows'     => $rows,
			'paging'   => array( 'page' => $page, 'per_page' => $per_page, 'total' => (int) $total ), // [2026-10-18 12:48 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.117 S117-V2 — total from either path
		);
		return class_exists( 'BizCity_Usage_View_REST' ) ? (array) BizCity_Usage_View_REST::clean( $out ) : $out;
	}

	/* ── queries ────────────────────────────────────────────────────────────────────────────────────────── */

	/** Live personal numbers of this blog with their owner confirmation (same reader as Sử dụng → Tài khoản). */
	private static function accounts(): array {
		global $wpdb;
		$t   = $wpdb->prefix . 'bizcity_zalo_accounts';
		$ph  = implode( ',', array_fill( 0, count( self::DEAD ), '%s' ) );
		$res = (array) $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL
			"SELECT bridge_account_id AS account_id, label, account_name, owner_user_id, status FROM {$t} WHERE kind = 'personal' AND status NOT IN ({$ph}) ORDER BY id ASC LIMIT 500",
			self::DEAD
		), ARRAY_A );
		$out = array();
		foreach ( $res as $b ) {
			$id    = (string) $b['account_id'];
			$label = '' !== trim( (string) $b['label'] ) ? (string) $b['label'] : (string) $b['account_name'];
			$owner = is_callable( array( 'BizCity_Usage_Accounts_REST', 'owner_of' ) )
				? BizCity_Usage_Accounts_REST::owner_of( $id, (int) $b['owner_user_id'] )
				: array( 'state' => 'none', 'uid_masked' => '' );
			$out[] = array(
				'account_id'    => $id,
				'label_masked'  => '' !== trim( $label ) && class_exists( 'BizCity_Usage_View_REST' ) ? BizCity_Usage_View_REST::mask( $label ) : 'Số …' . substr( $id, -3 ),
				'owner_user_id' => (int) $b['owner_user_id'],
				'status'        => sanitize_key( (string) $b['status'] ),
				'owner'         => array( 'state' => (string) ( $owner['state'] ?? 'none' ) ),
				// [2026-10-17 02:10 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.117 S117-M6 — step ④: the number's Agent Guru from the ONE binding owner (R-BOTSTUDIO), cached reader
				'has_guru'      => self::has_guru( $id ),
			);
		}
		return $out;
	}

	/** [2026-10-17 02:10 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.117 S117-M6 — is an Agent Guru bound to this Zalo number? (BizCity_Channel_Binding::resolve is cached per blog) */
	private static function has_guru( string $bridge_id ): bool {
		if ( '' === $bridge_id || ! class_exists( 'BizCity_Channel_Binding' ) ) {
			return false;
		}
		$b = BizCity_Channel_Binding::resolve( 'ZALO_PERSONAL', $bridge_id );
		return is_array( $b ) && (int) ( $b['character_id'] ?? 0 ) > 0;
	}

	/** account_id => [{user_id, relation, level}] from the grant layer (bounded roster per account). */
	private static function roster( array $accounts ): array {
		if ( ! class_exists( 'BizCity_Channel_User_Grant' ) ) {
			return array();
		}
		$out = array();
		foreach ( $accounts as $a ) {
			$id = (string) $a['account_id'];
			foreach ( (array) BizCity_Channel_User_Grant::users_for_account( self::CHANNEL, $id ) as $g ) {
				$out[ $id ][] = array(
					'user_id'  => (int) ( $g['user_id'] ?? 0 ),
					'relation' => (string) ( $g['relation'] ?? '' ),
					'level'    => (string) ( $g['level'] ?? '' ),
				);
			}
		}
		return $out;
	}

	/** Users created by BizCity_Number_Work_User (meta on the user). */
	private static function auto_user_ids(): array {
		if ( ! class_exists( 'BizCity_Number_Work_User' ) || ! defined( 'BizCity_Number_Work_User::META_PHONE' ) ) {
			return array();
		}
		$ids = get_users( array( 'blog_id' => (int) get_current_blog_id(), 'meta_key' => BizCity_Number_Work_User::META_PHONE, 'meta_compare' => 'EXISTS', 'fields' => 'ID', 'number' => 500 ) );
		return array_map( 'intval', (array) $ids );
	}

	/** user_id => {tokens, turns, tool_calls} from the site's daily table, by the user bound to the number when the row was written. */
	private static function usage_by_user( string $day_from, string $day_to, array $ids ): array {
		global $wpdb;
		if ( ! $ids || ! class_exists( 'BizCity_Usage_Schema' ) ) {
			return array();
		}
		$ph  = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$res = (array) $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL
			'SELECT bound_user_id AS uid, COALESCE(SUM(billed_tokens),0) AS tokens, COALESCE(SUM(turns),0) AS turns, COALESCE(SUM(tool_calls),0) AS tool_calls FROM '
			. BizCity_Usage_Schema::daily_table() . " WHERE day >= %s AND day <= %s AND bound_user_id IN ({$ph}) GROUP BY bound_user_id",
			array_merge( array( $day_from, $day_to ), $ids )
		), ARRAY_A );
		$out = array();
		foreach ( $res as $x ) {
			$out[ (int) $x['uid'] ] = array( 'tokens' => (int) $x['tokens'], 'turns' => (int) $x['turns'], 'tool_calls' => (int) $x['tool_calls'] );
		}
		return $out;
	}

	/** user_id => {errors} in the range, from the site's copy of the ledger. */
	private static function ledger_by_user( string $from, string $to, array $ids ): array {
		global $wpdb;
		if ( ! $ids || ! class_exists( 'BizCity_Usage_Schema' ) ) {
			return array();
		}
		$ph  = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$res = (array) $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL
			'SELECT bound_user_id AS uid, COALESCE(SUM(is_error),0) AS errors FROM ' . BizCity_Usage_Schema::ledger_table()
			. " WHERE occurred_at >= %s AND occurred_at < %s AND bound_user_id IN ({$ph}) GROUP BY bound_user_id",
			array_merge( array( $from, $to ), $ids )
		), ARRAY_A );
		$out = array();
		foreach ( $res as $x ) {
			$out[ (int) $x['uid'] ] = array( 'errors' => (int) $x['errors'] );
		}
		return $out;
	}

	/** user_id => newest ledger time (UTC 'Y-m-d H:i:s'), any range. */
	private static function last_activity( array $ids ): array {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( ! $ids || ! class_exists( 'BizCity_Usage_Schema' ) ) {
			return array();
		}
		$ph  = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$res = (array) $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL
			'SELECT bound_user_id AS uid, MAX(occurred_at) AS m FROM ' . BizCity_Usage_Schema::ledger_table() . " WHERE bound_user_id IN ({$ph}) GROUP BY bound_user_id",
			$ids
		), ARRAY_A );
		$out = array();
		foreach ( $res as $x ) {
			$out[ (int) $x['uid'] ] = (string) $x['m'];
		}
		return $out;
	}
}

// R-CACHE: one registration per group, at file scope.
if ( class_exists( 'BizCity_Cache_Registry' ) ) {
	BizCity_Cache_Registry::register( BizCity_Admin_Users_Overview_REST::CACHE_GROUP, 'core.channel-gateway', array( 'overview' ) );
}
