<?php
/**
 * "Tài khoản" + "Sổ cái token" REST for the site admin (PHASE-0.109 — account desk + ledger tabs, mirrors the cell console zalo.bizcity.vn/ledger
 * with what a SITE may see: BILLED tokens and ₫ only — never provider cost, real tokens or markup, R-TOKEN-LEDGER-CELL / R-USAGE-ONE-LEDGER §5).
 *
 *   GET usage/accounts?range=24h|7d|30d&search=&page=       one row per Zalo number: owner (WP user), owner-confirmation state, billed tokens,
 *                                                           chi (billed ₫), thu (paid plan sales ₫), lãi, replies, sessions, tools, last activity + totals
 *   GET usage/ledger/rows?range=&account=&kind=&page=       the site's copy of the ledger, newest first (50 / page)
 *   GET usage/ledger/integrity                              per number: rows, head seq, holes, unresolved / unpriced rows, ledger-vs-daily check
 *
 * Managers only (same gate as the Doanh thu tab). "Đổi user" and "Xác nhận chủ" are NOT re-implemented here: the UI calls the existing
 * zalo-bridge/members/{user}/accounts/{id}/transfer and zalo-personal/{bridge}/owner-uid[/claim] routes.
 *
 * // [2026-10-14 Johnny Chu - Chu Hoàng Anh] PHASE-0.109 — new file; reads only, writes nothing.
 *
 * @package BizCity_Twin_AI
 */

defined( 'ABSPATH' ) || exit;

// Loaded with require_once only (no class_exists self-guard: an early-bound declaration makes that guard always true).
final class BizCity_Usage_Accounts_REST {

	const NS       = 'bizcity-channel/v1';
	const CONTRACT = 'usage-accounts@1';
	const PER_PAGE = 50;
	const RANGES   = array( '24h', '7d', '30d' );
	const KINDS    = array( 'llm', 'tool', 'adjust', 'reversal' );

	private static $initialized = false;

	public static function init(): void {
		if ( self::$initialized ) {
			return;
		}
		self::$initialized = true;
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes(): void {
		$p = array( __CLASS__, 'can_manage' );
		register_rest_route( self::NS, '/usage/accounts', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'route_accounts' ), 'permission_callback' => $p ) );
		register_rest_route( self::NS, '/usage/ledger/rows', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'route_rows' ), 'permission_callback' => $p ) );
		register_rest_route( self::NS, '/usage/ledger/integrity', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'route_integrity' ), 'permission_callback' => $p ) );
	}

	public static function can_manage() {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'rest_forbidden', 'Login required.', array( 'status' => 401 ) );
		}
		$ok = class_exists( 'BizCity_Network_Admin_Capability' ) ? BizCity_Network_Admin_Capability::can_manage() : current_user_can( 'manage_options' );
		if ( ! $ok ) {
			return new WP_Error( 'rest_forbidden', 'Chỉ quản trị website được xem màn hình này.', array( 'status' => 403 ) );
		}
		BizCity_Usage_Schema::maybe_install(); // REST does not pass through admin_init
		return true;
	}

	/* ── pure helpers ─────────────────────────────────────────────── */

	public static function range_of( $raw ): string {
		return in_array( (string) $raw, self::RANGES, true ) ? (string) $raw : '7d';
	}

	/** USD → whole VND, null when the Hub rate is unknown. */
	public static function vnd( float $usd, ?float $rate ): ?int {
		return ( null !== $rate && $rate > 0 ) ? (int) round( $usd * $rate ) : null;
	}

	/**
	 * Owner confirmation of one number from its binding policy.
	 *
	 * @param array<string,mixed> $policy decoded policy_json
	 * @return string verified | unverified | none
	 */
	public static function owner_state( array $policy, bool $verified ): string {
		if ( '' === trim( (string) ( $policy['owner_uid'] ?? '' ) ) ) {
			return 'none';
		}
		return $verified ? 'verified' : 'unverified';
	}

	/** Ledger integrity of one number: holes = rows missing between the lowest and the head sequence. */
	public static function integrity_status( int $holes, int $unresolved, int $unpriced, bool $daily_match ): string {
		if ( $holes > 0 || ! $daily_match ) {
			return 'broken';
		}
		return ( $unresolved > 0 || $unpriced > 0 ) ? 'warn' : 'ok';
	}

	/* ── GET usage/accounts ───────────────────────────────────────── */

	public static function route_accounts( WP_REST_Request $request ) {
		global $wpdb;
		$range  = self::range_of( $request->get_param( 'range' ) );
		$r      = BizCity_Usage_View_REST::range( $range, time(), wp_timezone() );
		$rate   = self::rate();
		$page   = max( 1, (int) $request->get_param( 'page' ) );
		$search = trim( (string) $request->get_param( 'search' ) );
		$acc    = $wpdb->prefix . 'bizcity_zalo_accounts';

		$where = "WHERE kind = 'personal'";
		$args  = array();
		if ( '' !== $search ) {
			$like   = '%' . $wpdb->esc_like( $search ) . '%';
			$where .= ' AND (label LIKE %s OR account_name LIKE %s OR bridge_account_id LIKE %s)';
			array_push( $args, $like, $like, $like );
		}
		$total = (int) $wpdb->get_var( $args ? $wpdb->prepare( "SELECT COUNT(*) FROM {$acc} {$where}", $args ) : "SELECT COUNT(*) FROM {$acc} {$where}" ); // phpcs:ignore WordPress.DB.PreparedSQL
		$sql   = "SELECT bridge_account_id AS account_id, label, account_name, owner_user_id, status FROM {$acc} {$where} ORDER BY id DESC LIMIT %d OFFSET %d";
		$base  = (array) $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $args, array( self::PER_PAGE, ( $page - 1 ) * self::PER_PAGE ) ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		$ids   = array_map( static function ( $b ) { return (string) $b['account_id']; }, $base );

		$use  = self::usage_by_account( $r['day_from'], $r['day_to'], $ids );
		$last = self::last_activity( $ids );
		$paid = class_exists( 'BizCity_Usage_Plan_Sales' ) ? BizCity_Usage_Plan_Sales::by_account( $r['from'], $r['to'] ) : array();

		$rows = array();
		foreach ( $base as $b ) {
			$id    = (string) $b['account_id'];
			$u     = $use[ $id ] ?? array( 'tokens' => 0, 'usd' => 0.0, 'turns' => 0, 'threads' => 0, 'tool_calls' => 0 );
			$chi   = self::vnd( (float) $u['usd'], $rate );
			$thu   = (int) ( $paid[ $id ]['paid_vnd'] ?? 0 );
			$owner = self::owner_of( $id, (int) $b['owner_user_id'] );
			$label = '' !== trim( (string) $b['label'] ) ? (string) $b['label'] : (string) $b['account_name'];
			$rows[] = array(
				'account_id'   => $id,
				'label_masked' => '' !== trim( $label ) ? BizCity_Usage_View_REST::mask( $label ) : 'Số …' . substr( $id, -3 ),
				'owner'        => $owner,
				'billed_tokens' => (int) $u['tokens'],
				'chi_vnd'      => $chi,
				'thu_vnd'      => $thu,
				'lai_vnd'      => null === $chi ? null : $thu - $chi,
				'replies'      => (int) $u['turns'],
				'sessions'     => (int) $u['threads'],
				'tool_calls'   => (int) $u['tool_calls'],
				'last_at'      => $last[ $id ] ?? null,
				'stale'        => isset( $last[ $id ] ) && ( time() - strtotime( $last[ $id ] ) ) > 3600 * 24 * 3,
			);
		}

		// Totals over ALL numbers (not just this page): one grouped query + the sales table.
		$t   = (array) $wpdb->get_row( $wpdb->prepare( 'SELECT COALESCE(SUM(billed_tokens),0) AS tokens, COALESCE(SUM(billed_usd),0) AS usd, COALESCE(SUM(turns),0) AS turns, COALESCE(SUM(tool_calls),0) AS tool_calls FROM ' . BizCity_Usage_Schema::daily_table() . ' WHERE day >= %s AND day <= %s', $r['day_from'], $r['day_to'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		$thu = 0;
		foreach ( $paid as $p ) {
			$thu += (int) $p['paid_vnd'];
		}
		$chi = self::vnd( (float) ( $t['usd'] ?? 0 ), $rate );

		return self::out( array(
			'contract'     => self::CONTRACT,
			'range'        => $range,
			'rows'         => $rows,
			'total'        => $total,
			'page'         => $page,
			'per_page'     => self::PER_PAGE,
			'usd_vnd_rate' => $rate,
			'totals'       => array(
				'billed_tokens' => (int) ( $t['tokens'] ?? 0 ),
				'chi_vnd'       => $chi,
				'thu_vnd'       => $thu,
				'lai_vnd'       => null === $chi ? null : $thu - $chi,
				'replies'       => (int) ( $t['turns'] ?? 0 ),
				'tool_calls'    => (int) ( $t['tool_calls'] ?? 0 ),
			),
		) );
	}

	/* ── GET usage/ledger/rows ────────────────────────────────────── */

	public static function route_rows( WP_REST_Request $request ) {
		global $wpdb;
		$range = self::range_of( $request->get_param( 'range' ) );
		$r     = BizCity_Usage_View_REST::range( $range, time(), wp_timezone() );
		$rate  = self::rate();
		$page  = max( 1, (int) $request->get_param( 'page' ) );
		$kind  = (string) $request->get_param( 'kind' );
		$acct  = (string) $request->get_param( 'account' );
		$t     = BizCity_Usage_Schema::ledger_table();

		$where = 'WHERE occurred_at >= %s AND occurred_at < %s';
		$args  = array( $r['from'], $r['to'] );
		if ( in_array( $kind, self::KINDS, true ) ) {
			$where .= ' AND kind = %s';
			$args[] = $kind;
		}
		if ( '' !== $acct && preg_match( '/^[A-Za-z0-9._:-]{1,64}$/', $acct ) ) {
			$where .= ' AND account_id = %s';
			$args[] = $acct;
		}
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} {$where}", $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		$list  = (array) $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL
			"SELECT account_id, account_seq, kind, occurred_at, platform, surface, model, tool, actor_role, billed_tokens, billed_usd, is_error, resolve_state FROM {$t} {$where} ORDER BY occurred_at DESC, id DESC LIMIT %d OFFSET %d",
			array_merge( $args, array( self::PER_PAGE, ( $page - 1 ) * self::PER_PAGE ) )
		), ARRAY_A );
		$labels = self::labels( array_values( array_unique( array_map( static function ( $x ) { return (string) $x['account_id']; }, $list ) ) ) );
		$out    = array();
		foreach ( $list as $x ) {
			$id    = (string) $x['account_id'];
			$out[] = array(
				'account_id'    => $id,
				'label_masked'  => $labels[ $id ] ?? 'Số …' . substr( $id, -3 ),
				'seq'           => (int) $x['account_seq'],
				'kind'          => (string) $x['kind'],
				'at'            => gmdate( 'Y-m-d\TH:i:s\Z', strtotime( $x['occurred_at'] . ' UTC' ) ),
				'platform'      => (string) $x['platform'],
				'what'          => '' !== (string) $x['tool'] ? (string) $x['tool'] : (string) $x['model'],
				'surface'       => (string) $x['surface'],
				'actor_role'    => (string) $x['actor_role'],
				'billed_tokens' => (int) $x['billed_tokens'],
				'billed_vnd'    => null === $x['billed_usd'] ? null : self::vnd( (float) $x['billed_usd'], $rate ),
				'priced'        => null !== $x['billed_usd'],
				'is_error'      => (bool) $x['is_error'],
				'resolve_state' => (string) $x['resolve_state'],
			);
		}
		return self::out( array( 'contract' => self::CONTRACT, 'range' => $range, 'rows' => $out, 'total' => $total, 'page' => $page, 'per_page' => self::PER_PAGE, 'usd_vnd_rate' => $rate ) );
	}

	/* ── GET usage/ledger/integrity ───────────────────────────────── */

	public static function route_integrity() {
		global $wpdb;
		$lt   = BizCity_Usage_Schema::ledger_table();
		$dt   = BizCity_Usage_Schema::daily_table();
		$agg  = (array) $wpdb->get_results( "SELECT account_id, COUNT(*) AS n, MIN(account_seq) AS low, MAX(account_seq) AS head, MAX(received_at) AS last_rx,
				SUM(resolve_state <> 'ok') AS unresolved, SUM(kind = 'tool' AND billed_usd IS NULL) AS unpriced, SUM(is_error) AS errs, COALESCE(SUM(billed_tokens),0) AS tokens
			FROM {$lt} GROUP BY account_id", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		$day  = array();
		foreach ( (array) $wpdb->get_results( "SELECT account_id, COALESCE(SUM(billed_tokens),0) AS tokens FROM {$dt} GROUP BY account_id", ARRAY_A ) as $d ) { // phpcs:ignore WordPress.DB.PreparedSQL
			$day[ (string) $d['account_id'] ] = (int) $d['tokens'];
		}
		$labels = self::labels( array_map( static function ( $a ) { return (string) $a['account_id']; }, $agg ) );
		$rows   = array();
		$sum    = array( 'ok' => 0, 'warn' => 0, 'broken' => 0 );
		foreach ( $agg as $a ) {
			$id    = (string) $a['account_id'];
			$holes = max( 0, (int) $a['head'] - (int) $a['low'] + 1 - (int) $a['n'] );
			$match = (int) $a['tokens'] === ( $day[ $id ] ?? 0 );
			$st    = self::integrity_status( $holes, (int) $a['unresolved'], (int) $a['unpriced'], $match );
			++$sum[ $st ];
			$rows[] = array(
				'account_id'   => $id,
				'label_masked' => $labels[ $id ] ?? 'Số …' . substr( $id, -3 ),
				'rows'         => (int) $a['n'],
				'head_seq'     => (int) $a['head'],
				'holes'        => $holes,
				'unresolved'   => (int) $a['unresolved'],
				'unpriced'     => (int) $a['unpriced'],
				'errors'       => (int) $a['errs'],
				'daily_match'  => $match,
				'last_rx'      => gmdate( 'Y-m-d\TH:i:s\Z', strtotime( $a['last_rx'] . ' UTC' ) ),
				'status'       => $st,
			);
		}
		usort( $rows, static function ( $x, $y ) {
			$o = array( 'broken' => 0, 'warn' => 1, 'ok' => 2 );
			return $o[ $x['status'] ] <=> $o[ $y['status'] ];
		} );
		return self::out( array( 'contract' => self::CONTRACT, 'rows' => $rows, 'summary' => $sum ) );
	}

	/* ── queries ──────────────────────────────────────────────────── */

	/** @return array<string,array{tokens:int,usd:float,turns:int,threads:int,tool_calls:int}> */
	private static function usage_by_account( string $day_from, string $day_to, array $ids ): array {
		global $wpdb;
		if ( ! $ids ) {
			return array();
		}
		$ph  = implode( ',', array_fill( 0, count( $ids ), '%s' ) );
		$res = (array) $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL
			'SELECT account_id, COALESCE(SUM(billed_tokens),0) AS tokens, COALESCE(SUM(billed_usd),0) AS usd, COALESCE(SUM(turns),0) AS turns, COALESCE(SUM(threads),0) AS threads, COALESCE(SUM(tool_calls),0) AS tool_calls FROM '
			. BizCity_Usage_Schema::daily_table() . " WHERE day >= %s AND day <= %s AND account_id IN ({$ph}) GROUP BY account_id",
			array_merge( array( $day_from, $day_to ), $ids )
		), ARRAY_A );
		$out = array();
		foreach ( $res as $x ) {
			$out[ (string) $x['account_id'] ] = array( 'tokens' => (int) $x['tokens'], 'usd' => (float) $x['usd'], 'turns' => (int) $x['turns'], 'threads' => (int) $x['threads'], 'tool_calls' => (int) $x['tool_calls'] );
		}
		return $out;
	}

	/** @return array<string,string> account_id → ISO UTC of the newest ledger row */
	private static function last_activity( array $ids ): array {
		global $wpdb;
		if ( ! $ids ) {
			return array();
		}
		$ph  = implode( ',', array_fill( 0, count( $ids ), '%s' ) );
		$res = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT account_id, MAX(occurred_at) AS m FROM ' . BizCity_Usage_Schema::ledger_table() . " WHERE account_id IN ({$ph}) GROUP BY account_id", $ids ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		$out = array();
		foreach ( $res as $x ) {
			$out[ (string) $x['account_id'] ] = gmdate( 'Y-m-d\TH:i:s\Z', strtotime( $x['m'] . ' UTC' ) );
		}
		return $out;
	}

	/** @return array{user_id:int,name:string,state:string,uid_masked:string} state ∈ verified | unverified | none */
	// [2026-10-17 11:28 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.117 S117-M1 — public so the admin "Người dùng" overview reads owner confirmation from THIS one reader (no second copy).
	public static function owner_of( string $bridge_id, int $user_id ): array {
		$u      = $user_id > 0 ? get_userdata( $user_id ) : false;
		$policy = array();
		if ( class_exists( 'BizCity_Channel_Binding' ) ) {
			$b      = BizCity_Channel_Binding::resolve( 'ZALO_PERSONAL', $bridge_id );
			$policy = $b && ! empty( $b['policy_json'] ) ? (array) json_decode( (string) $b['policy_json'], true ) : array();
		}
		$verified = class_exists( 'BizCity_Zalo_Uid_Verify' ) && $policy && null !== BizCity_Zalo_Uid_Verify::owner_verified_at( $policy );
		$uid      = trim( (string) ( $policy['owner_uid'] ?? '' ) );
		return array(
			'user_id'    => $user_id,
			'name'       => $u ? BizCity_Usage_View_REST::mask( (string) ( $u->display_name ?: $u->user_login ) ) : '',
			'state'      => self::owner_state( $policy, $verified ),
			'uid_masked' => strlen( $uid ) > 6 ? substr( $uid, 0, 3 ) . '•••' . substr( $uid, -3 ) : ( '' === $uid ? '' : '•••' ),
		);
	}

	/** @return array<string,string> */
	private static function labels( array $ids ): array {
		global $wpdb;
		$ids = array_values( array_filter( $ids, static function ( $i ) { return '' !== $i; } ) );
		if ( ! $ids ) {
			return array();
		}
		$ph  = implode( ',', array_fill( 0, count( $ids ), '%s' ) );
		$res = (array) $wpdb->get_results( $wpdb->prepare( "SELECT bridge_account_id AS id, label, account_name FROM {$wpdb->prefix}bizcity_zalo_accounts WHERE kind = 'personal' AND bridge_account_id IN ({$ph})", $ids ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		$out = array();
		foreach ( $res as $x ) {
			$raw = '' !== trim( (string) $x['label'] ) ? (string) $x['label'] : (string) $x['account_name'];
			$out[ (string) $x['id'] ] = '' !== trim( $raw ) ? BizCity_Usage_View_REST::mask( $raw ) : '';
		}
		return $out;
	}

	private static function rate(): ?float {
		return class_exists( 'BizCity_Usage_Wallet' ) ? BizCity_Usage_Wallet::rate() : null;
	}

	private static function out( array $data ): WP_REST_Response {
		return new WP_REST_Response( BizCity_Usage_View_REST::clean( $data ), 200 );
	}
}
