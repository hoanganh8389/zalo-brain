<?php
/**
 * REST of the wallet block and the "Gói" tab (PHASE-0.107 S107-L18, doc 66 §7, §8.4). Namespace bizcity-channel/v1, managers only
 * (`manage_options` / network admin): everyone else gets 401 (logged out) or 403.
 *
 *   GET  usage/wallet[?refresh=1]      wallet-view@1 proxy (BizCity_Usage_Wallet) + `plan_limits` summary
 *   GET  usage/plans                   50 per page; filters state, plan_code, owner, platform, search; warning flags
 *   GET  usage/plans/{account_id}      one number (the plan card of the account page)
 *   POST usage/plans/bulk              ≤ 200 ids ⇒ set plan / validity / allowance / note; per-account result incl. the enforcement outcome
 *   POST usage/plans/import            CSV rows parsed by the browser: [{account, plan, valid_until}], validated per row
 *
 * Numbers are masked (0562•••899); nothing here ever carries cost, real tokens or markup. Writes bump plan_version and run BizCity_Usage_Plan_Enforce (round 5: the site flips the AI switch; nothing is pushed to the cell). Used tokens come from the site ledger.
 *
 * // [2026-10-13 02:28 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.107 S107-L18 — usage/wallet + usage/plans (+ bulk, import).
 *
 * @package BizCity_Twin_AI
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_Usage_Plans_REST {

	const NS          = 'bizcity-channel/v1';
	const PER_PAGE    = 50;
	const BULK_MAX    = 200;
	const IMPORT_MAX  = 500;
	const MONTHS      = array( 1, 3, 12 );
	const WARN_PCT    = 0.8;
	const WARN_DAYS   = 3;

	/** Test seams: is_admin() · logged_in() · actor() · rows(sql, args) · account(id) · find_phone(digits) · name(uid) · now() · tz() · enforce(platform, id): array · ai_enabled(id): bool · limits_cache */
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
		$perm = array( __CLASS__, 'can_manage' );
		register_rest_route( self::NS, '/usage/wallet', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'route_wallet' ), 'permission_callback' => $perm ) );
		// [2026-10-13 Johnny Chu - Chu Hoàng Anh] PHASE-0.107 S107-H30/U10 — "Mua thêm token": a pay link for THIS domain (managers only; the domain comes from the site's key at the Hub).
		register_rest_route( self::NS, '/usage/wallet/order', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'route_wallet_order' ), 'permission_callback' => $perm ) );
		register_rest_route( self::NS, '/usage/wallet/order/status', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'route_wallet_order_status' ), 'permission_callback' => $perm ) );
		register_rest_route( self::NS, '/usage/plans', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'route_list' ), 'permission_callback' => $perm ) );
		register_rest_route( self::NS, '/usage/plans/bulk', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'route_bulk' ), 'permission_callback' => $perm ) );
		register_rest_route( self::NS, '/usage/plans/import', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'route_import' ), 'permission_callback' => $perm ) );
		register_rest_route( self::NS, '/usage/plans/(?P<id>[A-Za-z0-9._:-]{1,64})', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'route_one' ), 'permission_callback' => $perm ) );
	}

	public static function can_manage() {
		$logged = isset( self::$readers['logged_in'] ) ? (bool) call_user_func( self::$readers['logged_in'] ) : (bool) is_user_logged_in();
		if ( ! $logged ) {
			return new WP_Error( 'rest_forbidden', 'Login required.', array( 'status' => 401 ) );
		}
		if ( ! self::is_admin() ) {
			return new WP_Error( 'rest_forbidden', 'Chỉ quản trị website được xem ví và quản lý gói.', array( 'status' => 403 ) );
		}
		if ( ! isset( self::$readers['rows'] ) && class_exists( 'BizCity_Usage_Schema' ) ) {
			BizCity_Usage_Schema::maybe_install(); // REST does not pass through admin_init
		}
		return true;
	}

	/* ── wallet ────────────────────────────────────────────────────── */

	public static function route_wallet( WP_REST_Request $request ): WP_REST_Response {
		$v = BizCity_Usage_Wallet::view( ! empty( $request->get_param( 'refresh' ) ) );
		if ( 'ok' === ( $v['state'] ?? '' ) ) {
			$v['plan_limits'] = self::plan_limits();
		}
		return new WP_REST_Response( BizCity_Usage_View_REST::clean( $v ), 200 );
	}

	public static function route_wallet_order( WP_REST_Request $request ): WP_REST_Response {
		$r = BizCity_Usage_Wallet::create_order( (int) $request->get_param( 'product_id' ), (string) $request->get_param( 'email' ) );
		if ( ! empty( $r['ok'] ) && function_exists( 'delete_transient' ) ) {
			delete_transient( BizCity_Usage_Wallet::TRANSIENT ); // the next read of the wallet is fresh (the buyer comes back from the pay page)
		}
		return new WP_REST_Response( $r, ! empty( $r['ok'] ) ? 200 : ( 'no_key' === ( $r['code'] ?? '' ) ? 401 : 422 ) );
	}

	public static function route_wallet_order_status( WP_REST_Request $request ): WP_REST_Response {
		$r = BizCity_Usage_Wallet::order_status( (int) $request->get_param( 'order_id' ) );
		if ( 'credited' === $r['state'] && function_exists( 'delete_transient' ) ) {
			delete_transient( BizCity_Usage_Wallet::TRANSIENT ); // the wallet card shows the new balance on its next read
		}
		return new WP_REST_Response( $r, 200 );
	}

	/** Small summary for the wallet block: trial cap, running trials, plans that need attention. Cached 60 s (the wallet block polls). */
	public static function plan_limits(): array {
		$cacheable = ! isset( self::$readers['rows'] ) && function_exists( 'get_transient' );
		if ( $cacheable ) {
			$c = get_transient( 'bizcity_usage_plan_limits' );
			if ( is_array( $c ) ) {
				return $c;
			}
		}
		$now = self::now();
		$t   = BizCity_Usage_Schema::plans_table();
		$r   = self::rows(
			"SELECT SUM(state = 'trial' AND (valid_until IS NULL OR valid_until > %s)) AS trials,
				SUM(state IN ('trial','active') AND valid_until IS NOT NULL AND valid_until > %s AND valid_until <= %s) AS expiring
			FROM {$t}",
			array( BizCity_Usage_Plans::fmt( $now ), BizCity_Usage_Plans::fmt( $now ), BizCity_Usage_Plans::fmt( $now + self::WARN_DAYS * 86400 ) )
		);
		$r = $r ? $r[0] : array();
		// numbers above 80 % of their allowance: the used sums come from the site ledger in grouped, indexed queries (never per row)
		$capped = self::rows( "SELECT account_id, allowance_period, started_at, monthly_token_allowance FROM {$t} WHERE plan_mode = 'on' AND monthly_token_allowance > 0 LIMIT 1000", array() );
		$used   = $capped ? BizCity_Usage_Plans::used_for( $capped, $now ) : array();
		$over   = 0;
		foreach ( $capped as $c ) {
			if ( (int) ( $used[ (string) $c['account_id'] ] ?? 0 ) > (int) $c['monthly_token_allowance'] * self::WARN_PCT ) {
				++$over;
			}
		}
		$out = array(
			'trial_cap'      => BizCity_Usage_Plans::trial_cap(),
			'running_trials' => (int) ( $r['trials'] ?? 0 ),
			'expiring_3d'    => (int) ( $r['expiring'] ?? 0 ),
			'over_80pct'     => $over,
		);
		if ( $cacheable ) {
			set_transient( 'bizcity_usage_plan_limits', $out, 60 );
		}
		return $out;
	}

	/* ── list ──────────────────────────────────────────────────────── */

	public static function route_list( WP_REST_Request $request ): WP_REST_Response {
		$page  = max( 1, (int) $request->get_param( 'page' ) );
		$per   = self::PER_PAGE;
		$now   = self::now();
		$tz    = self::tz();
		list( $where, $args ) = self::filters( $request );
		$acc   = self::accounts_table();
		$plans = BizCity_Usage_Schema::plans_table();
		$from  = "FROM {$acc} a LEFT JOIN {$plans} p ON p.platform = 'zalo_personal' AND p.account_id = a.bridge_account_id WHERE a.kind = 'personal' {$where}";
		$total = self::rows( "SELECT COUNT(*) AS n {$from}", $args );
		$offset = ( $page - 1 ) * $per;
		$rows  = self::rows(
			"SELECT a.bridge_account_id AS account_id, a.label, a.account_name, a.owner_user_id, a.status AS acc_status, a.zalo_uid,
				p.plan_code, p.state, p.plan_mode, p.plan_version, p.started_at, p.valid_until, p.monthly_token_allowance, p.allowance_period,
				p.notice_text, p.ai_off_by_plan, p.ai_off_at, p.enforce_error
			{$from} ORDER BY a.id DESC LIMIT {$per} OFFSET {$offset}",
			$args
		);
		// used tokens of the visible accounts: ONE grouped, indexed SUM over this site's ledger (not a query per row)
		$used = BizCity_Usage_Plans::used_for( $rows, $now );
		$out  = array();
		foreach ( $rows as $r ) {
			$out[] = self::shape( $r, $now, $tz, (int) ( $used[ (string) $r['account_id'] ] ?? 0 ), self::ai_enabled( (string) $r['account_id'] ) );
		}
		// start any pending trial of the numbers on this page (lazy fallback of L17; idempotent)
		foreach ( $out as $o ) {
			if ( 'trial' === $o['state'] && '' === $o['started_at'] && $o['connected'] ) {
				BizCity_Usage_Plans::maybe_start( BizCity_Usage_Plans::PLATFORM, $o['account_id'] );
			}
		}
		return new WP_REST_Response( BizCity_Usage_View_REST::clean( array(
			'contract' => 'usage-plans@1',
			'rows'     => $out,
			'total'    => $total ? (int) $total[0]['n'] : count( $out ),
			'page'     => $page,
			'per_page' => $per,
			'defaults' => self::defaults(),
			'limits'   => self::plan_limits(),
		) ), 200 );
	}

	public static function route_one( WP_REST_Request $request ) {
		$id = BizCity_Usage_Ingest::clean_id( (string) $request->get_param( 'id' ) );
		if ( '' === $id ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'invalid_param' ), 400 );
		}
		$acc   = self::accounts_table();
		$plans = BizCity_Usage_Schema::plans_table();
		$rows  = self::rows(
			"SELECT a.bridge_account_id AS account_id, a.label, a.account_name, a.owner_user_id, a.status AS acc_status, a.zalo_uid,
				p.plan_code, p.state, p.plan_mode, p.plan_version, p.started_at, p.valid_until, p.monthly_token_allowance, p.allowance_period,
				p.notice_text, p.ai_off_by_plan, p.ai_off_at, p.enforce_error
			FROM {$acc} a LEFT JOIN {$plans} p ON p.platform = 'zalo_personal' AND p.account_id = a.bridge_account_id
			WHERE a.kind = 'personal' AND a.bridge_account_id = %s LIMIT 1",
			array( $id )
		);
		if ( ! $rows ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'not_found' ), 404 );
		}
		$used = BizCity_Usage_Plans::used( $rows[0], self::now() );
		return new WP_REST_Response( BizCity_Usage_View_REST::clean( array( 'contract' => 'usage-plans@1', 'row' => self::shape( $rows[0], self::now(), self::tz(), $used, self::ai_enabled( $id ) ), 'defaults' => self::defaults() ) ), 200 );
	}

	/** Values the "Đổi gói" dialog proposes. `needs_rate`: pro allowance is 0 until a Hub/manual rate exists. */
	public static function defaults(): array {
		return array(
			'pro_allowance'   => BizCity_Usage_Plans::default_pro_allowance(),
			'trial_allowance' => BizCity_Usage_Plans::TRIAL_ALLOWANCE,
			'trial_days'      => BizCity_Usage_Plans::TRIAL_DAYS,
			'needs_rate'      => null === BizCity_Usage_Plans::rate(),
			'months'          => self::MONTHS,
		);
	}

	/**
	 * One SQL row ⇒ the list item (pure; unit-tested): masked label, state as of NOW, days left, used/allowance, warning flags.
	 *
	 * @param array<string,mixed> $r
	 * @return array<string,mixed>
	 */
	public static function shape( array $r, int $now, DateTimeZone $tz, int $used = 0, ?bool $ai_enabled = null ): array {
		$blank = BizCity_Usage_Plans::blank( BizCity_Usage_Plans::PLATFORM, (string) $r['account_id'] );
		$p     = array_merge( $blank, array_filter( $r, static function ( $v ) { return null !== $v; } ) );
		$state = BizCity_Usage_Plans::effective_state( $p, $now );
		$until = BizCity_Usage_Plans::ts( $p['valid_until'] );
		$days  = $until > 0 ? (int) ceil( ( $until - $now ) / 86400 ) : null;
		$allow = (int) $p['monthly_token_allowance'];
		$label = trim( (string) ( $r['label'] ?? '' ) ) ?: trim( (string) ( $r['account_name'] ?? '' ) );
		$id    = (string) $r['account_id'];
		$owner = (int) ( $r['owner_user_id'] ?? 0 );
		$mode  = 'on' === $p['plan_mode'] ? 'on' : 'off';
		return array(
			'platform'         => BizCity_Usage_Plans::PLATFORM,
			'account_id'       => $id,
			'label_masked'     => '' !== $label ? BizCity_Usage_View_REST::mask( $label ) : 'Số …' . substr( $id, -3 ),
			'owner_user_id'    => $owner,
			'owner_name'       => $owner > 0 ? self::name( $owner ) : '',
			'connected'        => 'connected' === (string) ( $r['acc_status'] ?? '' ) && '' !== trim( (string) ( $r['zalo_uid'] ?? '' ) ),
			'plan_code'        => (string) $p['plan_code'],
			'state'            => $state,
			'plan_mode'        => $mode,
			'plan_version'     => (int) $p['plan_version'],
			'started_at'       => self::iso( $p['started_at'] ),
			'valid_until'      => self::iso( $p['valid_until'] ),
			'days_left'        => null === $days ? null : max( 0, $days ),
			'monthly_token_allowance' => $allow,
			'allowance_period' => (string) $p['allowance_period'],
			'used_tokens'      => $used,
			'used_pct'         => $allow > 0 ? round( $used / $allow * 100, 1 ) : null,
			'warn_allowance'   => 'on' === $mode && $allow > 0 && $used > $allow * self::WARN_PCT,
			'warn_expiring'    => in_array( $state, array( 'trial', 'active' ), true ) && null !== $days && $days >= 0 && $until - $now <= self::WARN_DAYS * 86400,
			'notice_text'      => (string) $p['notice_text'],
			// AI switch: 'off_plan' = WE switched it off (ai_off_by_plan), 'off_other' = off for another reason (never touched), 'unknown' = not read
			'ai'               => null === $ai_enabled ? 'unknown' : ( $ai_enabled ? 'on' : ( '' !== (string) $p['ai_off_by_plan'] ? 'off_plan' : 'off_other' ) ),
			'ai_off_by_plan'   => (string) $p['ai_off_by_plan'],
			'ai_off_at'        => self::iso( $p['ai_off_at'] ),
			'enforce_error'    => (string) $p['enforce_error'],
		);
	}

	/** @return array{0:string,1:array} extra WHERE (starts with AND) + args */
	private static function filters( WP_REST_Request $request ): array {
		$w = '';
		$a = array();
		$platform = sanitize_key( (string) $request->get_param( 'platform' ) );
		if ( '' !== $platform && BizCity_Usage_Plans::PLATFORM !== $platform ) {
			return array( ' AND 1=0', array() );
		}
		$state = sanitize_key( (string) $request->get_param( 'state' ) );
		if ( in_array( $state, BizCity_Usage_Plans::STATES, true ) ) {
			if ( 'none' === $state ) {
				$w .= " AND (p.state IS NULL OR p.state = 'none')";
			} else {
				$w  .= ' AND p.state = %s';
				$a[] = $state;
			}
		}
		$code = sanitize_key( (string) $request->get_param( 'plan_code' ) );
		if ( '' !== $code ) {
			$w  .= ' AND p.plan_code = %s';
			$a[] = $code;
		}
		$owner = (int) $request->get_param( 'owner' );
		if ( $owner > 0 ) {
			$w  .= ' AND a.owner_user_id = %d';
			$a[] = $owner;
		}
		$q = trim( (string) $request->get_param( 'search' ) );
		if ( '' !== $q ) {
			$like = '%' . self::like( substr( $q, 0, 64 ) ) . '%';
			$w   .= ' AND (a.label LIKE %s OR a.account_name LIKE %s OR a.bridge_account_id LIKE %s)';
			array_push( $a, $like, $like, $like );
		}
		return array( $w, $a );
	}

	/* ── bulk ──────────────────────────────────────────────────────── */

	public static function route_bulk( WP_REST_Request $request ): WP_REST_Response {
		$b   = $request->get_json_params();
		$b   = is_array( $b ) ? $b : array();
		$ids = array();
		foreach ( (array) ( $b['ids'] ?? array() ) as $raw ) {
			$id = BizCity_Usage_Ingest::clean_id( (string) $raw );
			if ( '' !== $id ) {
				$ids[ $id ] = true;
			}
		}
		$ids = array_keys( $ids );
		if ( ! $ids ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'invalid_param', 'message' => 'Chọn ít nhất một số.' ), 400 );
		}
		if ( count( (array) ( $b['ids'] ?? array() ) ) > self::BULK_MAX ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'too_many', 'max' => self::BULK_MAX ), 400 );
		}
		$args = self::plan_args( $b );
		if ( is_wp_error( $args ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => $args->get_error_code(), 'message' => $args->get_error_message() ), 400 );
		}
		$out     = array();
		$applied = 0;
		foreach ( $ids as $id ) {
			$r = self::apply_one( $id, $args );
			if ( $r['ok'] ) {
				++$applied;
				$r['enforce'] = self::enforce_label( self::enforce( $id ) ); // the AI switch is evaluated right away
			} else {
				$r['enforce'] = '';
			}
			$out[] = $r;
		}
		return new WP_REST_Response( array( 'ok' => true, 'contract' => 'usage-plans@1', 'results' => $out, 'applied' => $applied, 'failed' => count( $ids ) - $applied ), 200 );
	}

	/**
	 * Validate the dialog payload (pure; unit-tested). Months ∈ {1,3,12} or a date; allowance ≥ 0; code a short slug.
	 *
	 * @return array|WP_Error args for BizCity_Usage_Plans::set_plan
	 */
	public static function plan_args( array $b ) {
		$code = sanitize_key( (string) ( $b['plan_code'] ?? '' ) );
		if ( '' === $code || strlen( $code ) > 32 ) {
			return new WP_Error( 'invalid_plan', 'Gói không hợp lệ.' );
		}
		$args = array( 'plan_code' => $code );
		if ( isset( $b['months'] ) && '' !== $b['months'] ) {
			if ( ! in_array( (int) $b['months'], self::MONTHS, true ) ) {
				return new WP_Error( 'invalid_validity', 'Thời hạn chỉ nhận +1, +3 hoặc +12 tháng.' );
			}
			$args['months'] = (int) $b['months'];
		} elseif ( ! empty( $b['valid_until'] ) ) {
			$ts = self::parse_date( (string) $b['valid_until'] );
			if ( null === $ts ) {
				return new WP_Error( 'invalid_validity', 'Ngày hết hạn không đọc được (dùng YYYY-MM-DD hoặc DD/MM/YYYY).' );
			}
			$args['valid_until'] = $ts;
		} elseif ( 'trial' !== $code ) {
			return new WP_Error( 'invalid_validity', 'Chọn thời hạn (+1 / +3 / +12 tháng hoặc một ngày cụ thể).' );
		}
		if ( isset( $b['allowance'] ) && '' !== $b['allowance'] && null !== $b['allowance'] ) {
			if ( ! is_numeric( $b['allowance'] ) || (float) $b['allowance'] < 0 || (float) $b['allowance'] > 1.0e12 ) {
				return new WP_Error( 'invalid_allowance', 'Hạn mức token phải là số ≥ 0.' );
			}
			$args['allowance'] = (int) $b['allowance'];
		}
		// [2026-10-13 02:43 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.107 S107-L14 — allowance 0 = unlimited at the cell: only on an explicit `unlimited` flag.
		if ( ! empty( $b['unlimited'] ) ) {
			$args['unlimited'] = true;
			$args['allowance'] = 0;
		}
		if ( isset( $b['note'] ) ) {
			$args['notice_text'] = (string) $b['note'];
		}
		if ( isset( $b['plan_mode'] ) && 'off' === $b['plan_mode'] ) {
			$args['plan_mode'] = 'off';
		}
		return $args;
	}

	private static function apply_one( string $id, array $args ): array {
		$base = array( 'account_id' => $id, 'ok' => false, 'error' => '', 'state' => '', 'valid_until' => '' );
		if ( null === self::account( $id ) ) {
			return array_merge( $base, array( 'error' => 'not_found' ) );
		}
		$r = BizCity_Usage_Plans::set_plan( BizCity_Usage_Plans::PLATFORM, $id, $args, self::actor() );
		if ( is_wp_error( $r ) ) {
			return array_merge( $base, array( 'error' => $r->get_error_code(), 'message' => $r->get_error_message() ) );
		}
		return array_merge( $base, array( 'ok' => true, 'state' => (string) $r['state'], 'valid_until' => self::iso( $r['valid_until'] ) ) );
	}

	/* ── import ────────────────────────────────────────────────────── */

	public static function route_import( WP_REST_Request $request ): WP_REST_Response {
		$b    = $request->get_json_params();
		$rows = is_array( $b ) && isset( $b['rows'] ) && is_array( $b['rows'] ) ? array_values( $b['rows'] ) : array();
		if ( ! $rows ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'invalid_param', 'message' => 'Không có dòng nào.' ), 400 );
		}
		if ( count( $rows ) > self::IMPORT_MAX ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'too_many', 'max' => self::IMPORT_MAX ), 400 );
		}
		$errors = array();
		$ok     = array();
		foreach ( $rows as $i => $r ) {
			$n = $i + 1;
			$r = is_array( $r ) ? $r : array();
			$acc_raw = trim( (string) ( $r['account'] ?? '' ) );
			$id      = self::resolve_account( $acc_raw );
			if ( '' === $id ) {
				$errors[] = array( 'row' => $n, 'account' => BizCity_Usage_View_REST::mask( $acc_raw ), 'error' => 'not_found' );
				continue;
			}
			$args = self::plan_args( array( 'plan_code' => (string) ( $r['plan'] ?? '' ), 'valid_until' => (string) ( $r['valid_until'] ?? '' ), 'allowance' => $r['allowance'] ?? null ) );
			if ( is_wp_error( $args ) ) {
				$errors[] = array( 'row' => $n, 'account' => BizCity_Usage_View_REST::mask( $acc_raw ), 'error' => $args->get_error_code(), 'message' => $args->get_error_message() );
				continue;
			}
			$res = self::apply_one( $id, $args );
			if ( ! $res['ok'] ) {
				$errors[] = array( 'row' => $n, 'account' => BizCity_Usage_View_REST::mask( $acc_raw ), 'error' => $res['error'], 'message' => $res['message'] ?? '' );
				continue;
			}
			$ok[] = $id;
		}
		$done = array();
		foreach ( $ok as $id ) {
			$done[] = array( 'account_id' => $id, 'enforce' => self::enforce_label( self::enforce( $id ) ) );
		}
		return new WP_REST_Response( array( 'ok' => true, 'contract' => 'usage-plans@1', 'applied' => count( $ok ), 'results' => $done, 'errors' => $errors ), 200 );
	}

	/** Bridge id first; else a phone (digits, 0xxxxxxxxx or +84…) matched against the number labels. */
	private static function resolve_account( string $raw ): string {
		$id = BizCity_Usage_Ingest::clean_id( $raw );
		if ( '' !== $id && null !== self::account( $id ) ) {
			return $id;
		}
		$digits = preg_replace( '/\D/', '', $raw );
		if ( strlen( $digits ) >= 9 ) {
			if ( 0 === strpos( $digits, '84' ) ) {
				$digits = '0' . substr( $digits, 2 );
			}
			return self::find_phone( $digits );
		}
		return '';
	}

	/** 'Y-m-d' or 'd/m/Y' ⇒ UTC timestamp of the END of that day in the site timezone; null when unreadable. */
	public static function parse_date( string $s ): ?int {
		$s = trim( $s );
		if ( 1 === preg_match( '#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $s, $m ) ) {
			$s = sprintf( '%04d-%02d-%02d', $m[3], $m[2], $m[1] );
		}
		if ( 1 !== preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m ) || ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
			return null;
		}
		$d = new DateTime( $s . ' 23:59:59', self::tz() );
		return $d->getTimestamp();
	}

	/* ── seams ─────────────────────────────────────────────────────── */

	/** Enforce one number now; returns BizCity_Usage_Plan_Enforce::apply()'s answer. */
	private static function enforce( string $id ): array {
		if ( isset( self::$readers['enforce'] ) ) {
			return (array) call_user_func( self::$readers['enforce'], BizCity_Usage_Plans::PLATFORM, $id );
		}
		return class_exists( 'BizCity_Usage_Plan_Enforce' ) ? BizCity_Usage_Plan_Enforce::apply( BizCity_Usage_Plans::PLATFORM, $id ) : array( 'action' => 'none', 'reason' => '', 'error' => '' );
	}

	/** Short machine label of an enforcement outcome for the dialog: ai_off:<reason> | ai_on | kept_off:<reason> | human_off | error:<code> | ok. */
	public static function enforce_label( array $e ): string {
		switch ( $e['action'] ?? 'none' ) {
			case 'ai_off':
			case 'kept_off':
				return $e['action'] . ':' . ( $e['reason'] ?? '' );
			case 'ai_on':
				return 'ai_on';
			case 'human_off':
				return 'human_off';
			case 'error':
				return 'error:' . ( $e['error'] ?? 'unknown' );
		}
		return 'ok';
	}

	private static function ai_enabled( string $id ): ?bool {
		if ( isset( self::$readers['ai_enabled'] ) ) {
			return (bool) call_user_func( self::$readers['ai_enabled'], $id );
		}
		return class_exists( 'BizCity_Zalo_Account_Flags' ) ? BizCity_Zalo_Account_Flags::ai_enabled( $id ) : null;
	}

	private static function account( string $id ): ?array {
		if ( isset( self::$readers['account'] ) ) {
			$a = call_user_func( self::$readers['account'], $id );
			return is_array( $a ) ? $a : null;
		}
		$a = class_exists( 'BizCity_Zalo_Mapping_Repo' ) ? BizCity_Zalo_Mapping_Repo::find_account_by_bridge_id( 'personal', $id ) : null;
		return is_array( $a ) ? $a : null;
	}

	private static function find_phone( string $digits ): string {
		if ( isset( self::$readers['find_phone'] ) ) {
			return (string) call_user_func( self::$readers['find_phone'], $digits );
		}
		$acc  = self::accounts_table();
		$rows = self::rows( "SELECT bridge_account_id FROM {$acc} WHERE kind = 'personal' AND (label LIKE %s OR account_name LIKE %s) LIMIT 2", array( '%' . self::like( $digits ) . '%', '%' . self::like( $digits ) . '%' ) );
		return 1 === count( $rows ) ? (string) $rows[0]['bridge_account_id'] : ''; // ambiguous ⇒ not matched
	}

	private static function name( int $uid ): string {
		if ( isset( self::$readers['name'] ) ) {
			return BizCity_Usage_View_REST::mask( (string) call_user_func( self::$readers['name'], $uid ) );
		}
		$u = function_exists( 'get_userdata' ) ? get_userdata( $uid ) : null;
		return BizCity_Usage_View_REST::mask( $u ? (string) ( $u->display_name ?: $u->user_login ) : '#' . $uid );
	}

	private static function rows( string $sql, array $args ): array {
		if ( isset( self::$readers['rows'] ) ) {
			return (array) call_user_func( self::$readers['rows'], $sql, $args );
		}
		global $wpdb;
		if ( ! class_exists( 'BizCity_Usage_Schema' ) || ! BizCity_Usage_Schema::installed() ) {
			return array();
		}
		$q = empty( $args ) ? $sql : $wpdb->prepare( $sql, $args ); // phpcs:ignore WordPress.DB.PreparedSQL
		$r = $wpdb->get_results( $q, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		return is_array( $r ) ? $r : array();
	}

	private static function accounts_table(): string {
		global $wpdb;
		return ( isset( $wpdb ) ? $wpdb->prefix : 'wp_' ) . 'bizcity_zalo_accounts';
	}

	private static function like( string $s ): string {
		global $wpdb;
		return ( isset( $wpdb ) && is_object( $wpdb ) && method_exists( $wpdb, 'esc_like' ) ) ? $wpdb->esc_like( $s ) : addcslashes( $s, '_%\\' );
	}

	private static function is_admin(): bool {
		if ( isset( self::$readers['is_admin'] ) ) {
			return (bool) call_user_func( self::$readers['is_admin'] );
		}
		return class_exists( 'BizCity_Network_Admin_Capability' ) ? BizCity_Network_Admin_Capability::can_manage() : current_user_can( 'manage_options' );
	}

	private static function actor(): int {
		return isset( self::$readers['actor'] ) ? (int) call_user_func( self::$readers['actor'] ) : (int) get_current_user_id();
	}

	private static function now(): int {
		return isset( self::$readers['now'] ) ? (int) call_user_func( self::$readers['now'] ) : time();
	}

	private static function tz(): DateTimeZone {
		if ( isset( self::$readers['tz'] ) ) {
			return call_user_func( self::$readers['tz'] );
		}
		return function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
	}

	private static function iso( $utc ): string {
		$t = BizCity_Usage_Plans::ts( $utc );
		return $t > 0 ? gmdate( 'c', $t ) : '';
	}
}
