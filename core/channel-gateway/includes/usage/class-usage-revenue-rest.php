<?php
/**
 * REST of the Doanh thu (revenue) page — read only, managers only (PHASE-0.108 S108-L20.3 / L20.4 / L20.5, 22-SPEC-REVENUE-THEO-MOCKUP.md §2-§5, 24 §5, §8).
 *
 *   GET usage/revenue/summary?range=7d|30d|month|prev_month         six tiles + the "Cần xử lý" list
 *   GET usage/revenue/numbers?range=&filter=&search=&page=[&format=csv]   one row per number: plan, tokens, tools, money in/out, last payment, expected end
 *   GET usage/revenue/orders?status=&page=[&format=csv]              plan orders of this site with the bank note and whether the plan was applied
 *
 * Where each figure comes from (22 §10): money IN = bizcity_plan_sales (paid, applied orders only) + Woo for what is not paid yet; money OUT = this site's own ledger copy
 * (bizcity_usage_daily) × the Hub USD→VND rate (null rate ⇒ every *_vnd cost is null, never a guessed number); plan/limit = bizcity_account_plans. "Lãi gộp" is revenue minus
 * what the number cost to run (tokens + tools) and NEVER includes the estimated "làm thay" of the achievements page (20 §1 G4). One grouped query per figure, never per row.
 *
 * Test seams ($readers): is_admin() · logged_in() · rows(sql, args) · now() · tz() · rate() · wallet() · woo_orders(args) · underpaid_status() · name(uid) · csv(name, rows, first) · ai_enabled(id) · labels(ids)
 *
 * // [2026-10-13 09:26 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.108 S108-L20.3 / L20.4 / L20.5.
 *
 * @package BizCity_Twin_AI
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_Usage_Revenue_REST {

	const NS             = 'bizcity-channel/v1';
	const CONTRACT       = 'plan-revenue@1';
	const PER_PAGE       = 50;
	const TODO_MAX       = 10;
	const TRIAL_WARN_D   = 1;
	const PRO_WARN_D     = 3;
	const TOKEN_WARN_D   = 7;
	const WALLET_LOW_D   = 7;
	const IDS_MAX        = 1000;
	const RANGES         = array( '7d', '30d', 'month', 'prev_month' );

	/** @var array<string,callable> */
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
		$p = array( __CLASS__, 'can_manage' );
		register_rest_route( self::NS, '/usage/revenue/summary', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'route_summary' ), 'permission_callback' => $p ) );
		register_rest_route( self::NS, '/usage/revenue/numbers', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'route_numbers' ), 'permission_callback' => $p ) );
		register_rest_route( self::NS, '/usage/revenue/orders', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'route_orders' ), 'permission_callback' => $p ) );
	}

	public static function can_manage() {
		$logged = isset( self::$readers['logged_in'] ) ? (bool) call_user_func( self::$readers['logged_in'] ) : (bool) is_user_logged_in();
		if ( ! $logged ) {
			return new WP_Error( 'rest_forbidden', 'Login required.', array( 'status' => 401 ) );
		}
		if ( ! self::is_admin() ) {
			return new WP_Error( 'rest_forbidden', 'Chỉ quản trị website được xem doanh thu.', array( 'status' => 403 ) );
		}
		if ( ! isset( self::$readers['rows'] ) ) {
			if ( class_exists( 'BizCity_Usage_Schema' ) ) {
				BizCity_Usage_Schema::maybe_install();
			}
			BizCity_Usage_Plan_Sales::maybe_install(); // REST does not pass through admin_init
		}
		return true;
	}

	/* ================================================================
	 *  Pure helpers (unit-tested)
	 * ================================================================ */

	public static function range_of( $raw ): string {
		return in_array( (string) $raw, self::RANGES, true ) ? (string) $raw : 'month';
	}

	/** USD → whole VND, null when the Hub rate is unknown. */
	public static function vnd( float $usd, ?float $rate ): ?int {
		return ( null !== $rate && $rate > 0 ) ? (int) round( $usd * $rate ) : null;
	}

	/**
	 * @param array<int,array<string,mixed>> $rows shaped numbers (BizCity_Usage_Plans_REST::shape) — `state` is already the effective state
	 * @return array{pro:int,trial:int,expired:int,none:int}
	 */
	public static function number_counts( array $rows ): array {
		$c = array( 'pro' => 0, 'trial' => 0, 'expired' => 0, 'none' => 0 );
		foreach ( $rows as $r ) {
			$st = (string) ( $r['state'] ?? 'none' );
			if ( 'active' === $st ) {
				++$c['pro'];
			} elseif ( 'trial' === $st ) {
				++$c['trial'];
			} elseif ( 'expired' === $st ) {
				++$c['expired'];
			} else {
				++$c['none'];
			}
		}
		return $c;
	}

	/**
	 * The "Cần xử lý hôm nay" list (22 §2): seven kinds, soonest first, at most TODO_MAX.
	 *
	 * @param array<int,array<string,mixed>> $numbers   shaped numbers + `expected_end` ({ts,date,reason}|null)
	 * @param array<int,array<string,mixed>> $underpaid [{order_id, label_masked, owner_name, bank_amount_vnd, amount_vnd, when}]
	 * @param array<int,array<string,mixed>> $refunded  [{order_id, label_masked, owner_name, when}]
	 * @param array<string,mixed>|null       $wallet    {runway_days, low_balance} or null
	 * @return array<int,array<string,mixed>>
	 */
	public static function build_todo( array $numbers, array $underpaid, array $refunded, ?array $wallet, int $now ): array {
		$out = array();
		foreach ( $numbers as $n ) {
			$st   = (string) ( $n['state'] ?? '' );
			$days = $n['days_left'] ?? null;
			$base = array( 'account_id' => (string) $n['account_id'], 'label_masked' => (string) $n['label_masked'], 'owner_name' => (string) ( $n['owner_name'] ?? '' ) );
			if ( 'trial' === $st && null !== $days && $days <= self::TRIAL_WARN_D ) {
				$out[] = $base + array( 'kind' => 'trial_ending', 'detail' => sprintf( 'Dùng thử còn %d ngày', $days ), 'when' => (string) $n['valid_until'], 'action' => 'send_link' );
			} elseif ( 'active' === $st && null !== $days && $days <= self::PRO_WARN_D ) {
				$out[] = $base + array( 'kind' => 'pro_ending', 'detail' => sprintf( 'Gói Pro còn %d ngày', $days ), 'when' => (string) $n['valid_until'], 'action' => 'renew' );
			} elseif ( 'expired' === $st && 'off_plan' === (string) ( $n['ai'] ?? '' ) ) {
				$out[] = $base + array( 'kind' => 'expired_ai_off', 'detail' => 'Hết hạn, AI đã tắt', 'when' => (string) $n['valid_until'], 'action' => 'create_order' );
			}
			$e = $n['expected_end'] ?? null;
			if ( in_array( $st, array( 'trial', 'active' ), true ) && is_array( $e ) && 'token' === $e['reason'] && (int) $e['ts'] <= $now + self::TOKEN_WARN_D * 86400 ) {
				$out[] = $base + array( 'kind' => 'token_before_expiry', 'detail' => sprintf( 'Hết token ≈ %s, trước hạn gói', self::dmy( (int) $e['ts'] ) ), 'when' => (string) $e['date'], 'action' => 'renew' );
			}
		}
		foreach ( $underpaid as $u ) {
			$out[] = array( 'kind' => 'underpaid', 'order_id' => (int) $u['order_id'], 'label_masked' => (string) $u['label_masked'], 'owner_name' => (string) ( $u['owner_name'] ?? '' ),
				'detail' => sprintf( 'Trả thiếu %s ₫', number_format( max( 0, (int) $u['amount_vnd'] - (int) $u['bank_amount_vnd'] ), 0, ',', '.' ) ), 'when' => (string) $u['when'], 'action' => 'open_order' );
		}
		foreach ( $refunded as $r ) {
			$out[] = array( 'kind' => 'refunded_after_apply', 'order_id' => (int) $r['order_id'], 'label_masked' => (string) $r['label_masked'], 'owner_name' => (string) ( $r['owner_name'] ?? '' ),
				'detail' => 'Đơn đã hoàn sau khi gia hạn — xem lại hạn gói', 'when' => (string) $r['when'], 'action' => 'open_order' );
		}
		if ( $wallet && ( ! empty( $wallet['low_balance'] ) || ( isset( $wallet['runway_days'] ) && null !== $wallet['runway_days'] && (int) $wallet['runway_days'] < self::WALLET_LOW_D ) ) ) {
			$out[] = array( 'kind' => 'wallet_low', 'label_masked' => '', 'owner_name' => '', 'detail' => isset( $wallet['runway_days'] ) && null !== $wallet['runway_days'] ? sprintf( 'Ví domain còn khoảng %d ngày', (int) $wallet['runway_days'] ) : 'Ví domain sắp hết', 'when' => '', 'action' => 'buy_tokens' );
		}
		usort( $out, static function ( $a, $b ) {
			$ta = '' === ( $a['when'] ?? '' ) ? PHP_INT_MAX : (int) strtotime( (string) $a['when'] );
			$tb = '' === ( $b['when'] ?? '' ) ? PHP_INT_MAX : (int) strtotime( (string) $b['when'] );
			return $ta <=> $tb;
		} );
		return array_slice( $out, 0, self::TODO_MAX );
	}

	/**
	 * One Woo order (+ its revenue-ledger row) ⇒ the line of the orders table (22 §5). `needs_apply` = paid at Woo but the plan was never extended.
	 *
	 * @param array<string,mixed>      $w woo_orders() item
	 * @param array<string,mixed>|null $sale plan_sales row for that order
	 * @return array<string,mixed>
	 */
	public static function shape_order( array $w, ?array $sale, string $underpaid_status ): array {
		$ws = (string) $w['status'];
		if ( '' !== $underpaid_status && $ws === $underpaid_status ) {
			$status = 'underpaid';
		} elseif ( in_array( $ws, array( 'pending', 'on-hold' ), true ) ) {
			$status = 'pending';
		} elseif ( in_array( $ws, array( 'processing', 'completed' ), true ) ) {
			$status = $sale && 'refunded' === (string) $sale['status'] ? 'refunded' : 'paid';
		} elseif ( 'refunded' === $ws ) {
			$status = 'refunded';
		} else {
			$status = 'cancelled';
		}
		$applied = $sale && '' !== (string) ( $sale['applied_at'] ?? '' );
		return array(
			'order_id'         => (int) $w['order_id'],
			'created_at'       => gmdate( 'Y-m-d\TH:i:s\Z', (int) $w['created_ts'] ),
			'account_id'       => (string) $w['account_id'],
			'package_code'     => '' !== (string) ( $w['package'] ?? '' ) ? (string) $w['package'] : 'legacy',
			'months'           => (int) ( $w['months'] ?? 0 ) ?: 1,
			'amount_vnd'       => (int) $w['total_vnd'],
			'channel'          => (string) ( $w['channel'] ?? '' ),
			'created_by'       => (int) ( $w['created_by'] ?? 0 ),
			'payer_user_id'    => (int) ( $w['payer_user_id'] ?? 0 ),
			'status'           => $status,
			'bank_amount_vnd'  => (int) ( $w['bank_amount_vnd'] ?? 0 ),
			'bank_time'        => isset( $w['bank_ts'] ) && (int) $w['bank_ts'] > 0 ? gmdate( 'Y-m-d\TH:i:s\Z', (int) $w['bank_ts'] ) : null,
			'bank_ref'         => self::mask_ref( (string) ( $w['bank_ref'] ?? '' ) ),
			'applied'          => $applied,
			'applied_at'       => $applied ? self::iso( (string) $sale['applied_at'] ) : null,
			'valid_until_after' => $sale && ! empty( $sale['valid_until'] ) ? self::iso( (string) $sale['valid_until'] ) : null,
			'needs_apply'      => 'paid' === $status && ! $applied,
			'edit_url'        => (string) ( $w['edit_url'] ?? '' ), // WooCommerce's own link (HPOS-safe); managers only
			'refunded_after_apply' => 'refunded' === $status && $applied,
		);
	}

	/** Bank transfer text can hold a person's name or account number: keep the order code only (BZ8852) and a short tail. */
	public static function mask_ref( string $s ): string {
		$s = trim( $s );
		if ( '' === $s ) {
			return '';
		}
		if ( 1 === preg_match( '/[A-Za-z]{1,6}\d{1,10}/', $s, $m ) ) {
			return $m[0];
		}
		return '…' . substr( preg_replace( '/\D/', '', $s ), -4 );
	}

	/** Flat CSV text of rows (Excel-safe BOM, one header line) — pure so the unit test can read it. */
	public static function csv_text( array $rows ): string {
		$fh = fopen( 'php://temp', 'w+' );
		foreach ( $rows as $i => $r ) {
			$flat = array();
			foreach ( $r as $k => $v ) {
				$flat[ $k ] = is_array( $v ) ? implode( ' ', array_map( static function ( $x ) { return is_array( $x ) ? implode( ':', $x ) : (string) $x; }, $v ) ) : ( is_bool( $v ) ? ( $v ? '1' : '0' ) : $v );
			}
			if ( 0 === $i ) {
				fputcsv( $fh, array_keys( $flat ) );
			}
			fputcsv( $fh, array_values( $flat ) );
		}
		rewind( $fh );
		$t = (string) stream_get_contents( $fh );
		fclose( $fh );
		return "\xEF\xBB\xBF" . $t;
	}

	/* ================================================================
	 *  summary
	 * ================================================================ */

	public static function route_summary( WP_REST_Request $request ): WP_REST_Response {
		$now   = self::now();
		$tz    = self::tz();
		$range = self::range_of( $request->get_param( 'range' ) );
		$p     = BizCity_Usage_Plan_Sales::period( $range, $now, $tz );
		$cur   = BizCity_Usage_Plan_Sales::summary( $p['from'], $p['to'] );
		$prev  = BizCity_Usage_Plan_Sales::summary( $p['prev_from'], $p['prev_to'] );
		$rate  = self::rate();

		$pend = self::pending( $now );
		$cost = self::cost( $p['from_day'], $p['to_day'], null );
		$token_vnd = self::vnd( $cost['usd_tokens'], $rate );
		$tool_vnd  = self::vnd( $cost['usd_tools'], $rate );
		$cost_vnd  = null === $token_vnd ? null : $token_vnd + (int) $tool_vnd;

		$numbers = self::numbers_with_expect( self::plan_rows( '', array(), 2000, 0 ), $now, $tz );
		$wallet  = self::wallet();
		$todo    = self::build_todo( $numbers, $pend['underpaid_items'], self::refunded_items( $now ), $wallet, $now );

		return self::out( array(
			'contract'            => self::CONTRACT,
			'range'               => $range,
			'paid_vnd'            => $cur['paid_vnd'],
			'paid_orders'         => $cur['paid_orders'],
			'paid_delta_pct'      => BizCity_Usage_Plan_Sales::delta_pct( $cur['paid_vnd'], $prev['paid_vnd'] ),
			'pending_vnd'         => $pend['vnd'],
			'pending_orders'      => $pend['count'],
			'pending_oldest_days' => $pend['oldest_days'],
			'token_cost_vnd'      => $token_vnd,
			'tool_cost_vnd'       => $tool_vnd,
			'cost_vnd'            => $cost_vnd,
			'billed_tokens'       => $cost['tokens'],
			'gross_vnd'           => null === $cost_vnd ? null : $cur['paid_vnd'] - $cost_vnd,
			'wallet'              => array( 'balance_usd' => $wallet['balance_usd'] ?? null, 'days_left' => $wallet['runway_days'] ?? null, 'state' => $wallet['state'] ?? 'unknown' ),
			'numbers'             => self::number_counts( $numbers ),
			'todo'                => $todo,
			'usd_vnd_rate'        => $rate,
		) );
	}

	/* ================================================================
	 *  numbers
	 * ================================================================ */

	public static function route_numbers( WP_REST_Request $request ) {
		$now   = self::now();
		$tz    = self::tz();
		$range = self::range_of( $request->get_param( 'range' ) );
		$p     = BizCity_Usage_Plan_Sales::period( $range, $now, $tz );
		$rate  = self::rate();
		$csv   = 'csv' === (string) $request->get_param( 'format' );
		$page  = max( 1, (int) $request->get_param( 'page' ) );
		$per   = $csv ? 500 : self::PER_PAGE;

		list( $where, $args ) = self::chip_where( sanitize_key( (string) $request->get_param( 'filter' ) ), trim( (string) $request->get_param( 'search' ) ), $now );
		$total = self::rows( 'SELECT COUNT(*) AS n ' . self::from_sql() . $where, $args );
		$base  = self::plan_rows( $where, $args, $per, ( $page - 1 ) * $per );
		$ids   = array_map( static function ( $r ) { return (string) $r['account_id']; }, $base );

		$numbers = self::numbers_with_expect( $base, $now, $tz );
		$costs   = self::cost_by_account( $p['from_day'], $p['to_day'], $ids );
		$tools   = self::tools_by_account( $p['from_day'], $p['to_day'], $ids );
		$paid    = BizCity_Usage_Plan_Sales::by_account( $p['from'], $p['to'] );

		$out = array();
		foreach ( $numbers as $n ) {
			$id = (string) $n['account_id'];
			$c  = $costs[ $id ] ?? array( 'usd' => 0.0, 'tool_calls' => 0 );
			$s  = $paid[ $id ] ?? array( 'paid_vnd' => 0, 'paid_total_vnd' => 0, 'last_paid_at' => null, 'last_order_id' => 0, 'last_method' => '', 'refunded' => 0 );
			$e  = $n['expected_end'] ?? null;
			$out[] = $n + array(
				'token_cost_vnd'   => self::vnd( (float) $c['usd'], $rate ),
				'tool_calls'       => (int) $c['tool_calls'],
				'tools_top'        => $tools[ $id ] ?? array(),
				'paid_vnd'         => $s['paid_vnd'],
				'paid_total_vnd'   => $s['paid_total_vnd'],
				'last_paid_at'     => null === $s['last_paid_at'] ? null : self::iso( (string) $s['last_paid_at'] ),
				'last_order_id'    => $s['last_order_id'],
				'last_method'      => $s['last_method'],
				'refunded_orders'  => $s['refunded'],
				'token_before_plan' => BizCity_Usage_Plan_Expect::token_before_plan( $e ),
			);
		}
		if ( $csv ) {
			self::send_csv( 'revenue-numbers', array_map( static function ( $r ) {
				return array(
					'so' => $r['label_masked'], 'chu_so' => $r['owner_name'], 'goi' => $r['plan_code'], 'trang_thai' => $r['state'], 'han_goi' => $r['valid_until'],
					'token_da_dung' => $r['used_tokens'], 'han_muc' => $r['monthly_token_allowance'], 'cong_cu' => $r['tool_calls'], 'chi_vnd' => $r['token_cost_vnd'],
					'da_thu_ky_vnd' => $r['paid_vnd'], 'da_thu_tong_vnd' => $r['paid_total_vnd'], 'tra_lan_cuoi' => $r['last_paid_at'], 'het_du_kien' => $r['expected_end']['date'] ?? '',
				);
			}, $out ) );
			return self::out( array( 'ok' => true, 'csv' => true ) );
		}
		return self::out( array(
			'contract' => self::CONTRACT, 'range' => $range, 'rows' => $out, 'total' => $total ? (int) $total[0]['n'] : count( $out ), 'page' => $page, 'per_page' => $per, 'usd_vnd_rate' => $rate,
		) );
	}

	/* ================================================================
	 *  orders
	 * ================================================================ */

	public static function route_orders( WP_REST_Request $request ) {
		$status = sanitize_key( (string) $request->get_param( 'status' ) );
		$page   = max( 1, (int) $request->get_param( 'page' ) );
		$csv    = 'csv' === (string) $request->get_param( 'format' );
		$per    = $csv ? 500 : self::PER_PAGE;
		$under  = self::underpaid_status();

		$woo = self::woo_orders( array( 'statuses' => self::woo_statuses( $status, $under ), 'page' => $page, 'per_page' => $per ) );
		$ids = array_map( static function ( $w ) { return (int) $w['order_id']; }, (array) $woo['rows'] );
		$sales = self::sales_by_order( $ids );
		$labels = self::labels( array_values( array_unique( array_map( static function ( $w ) { return (string) $w['account_id']; }, (array) $woo['rows'] ) ) ) );

		$rows = array();
		foreach ( (array) $woo['rows'] as $w ) {
			$r = self::shape_order( $w, $sales[ (int) $w['order_id'] ] ?? null, $under );
			$r['label_masked']    = self::label_masked( (string) $r['account_id'], $labels );
			$r['created_by_name'] = $r['created_by'] > 0 ? self::name( (int) $r['created_by'] ) : '';
			$r['payer_name']      = $r['payer_user_id'] > 0 ? self::name( (int) $r['payer_user_id'] ) : '';
			$rows[] = $r;
		}
		if ( $csv ) {
			self::send_csv( 'revenue-orders', array_map( static function ( $r ) {
				return array( 'don' => $r['order_id'], 'tao_luc' => $r['created_at'], 'so' => $r['label_masked'], 'goi' => $r['package_code'], 'gia_vnd' => $r['amount_vnd'], 'nguoi_tao' => $r['created_by_name'],
					'nguoi_tra' => $r['payer_name'], 'kenh' => $r['channel'], 'trang_thai' => $r['status'], 'ngan_hang_bao_vnd' => $r['bank_amount_vnd'], 'da_gia_han' => $r['applied'] ? 1 : 0, 'han_moi' => $r['valid_until_after'] );
			}, $rows ) );
			return self::out( array( 'ok' => true, 'csv' => true ) );
		}
		return self::out( array( 'contract' => self::CONTRACT, 'status' => $status ?: 'all', 'rows' => $rows, 'total' => (int) $woo['total'], 'page' => $page, 'per_page' => $per ) );
	}

	/** Chip ⇒ Woo statuses. paid ⇒ processing/completed (a refunded sale is told apart in shape_order). */
	public static function woo_statuses( string $chip, string $underpaid ): array {
		$map = array(
			'pending'   => array( 'pending', 'on-hold' ),
			'paid'      => array( 'processing', 'completed' ),
			'cancelled' => array( 'cancelled', 'failed' ),
			'refunded'  => array( 'refunded', 'processing', 'completed' ),
		);
		if ( 'underpaid' === $chip ) {
			return '' !== $underpaid ? array( $underpaid ) : array( '__none__' );
		}
		if ( isset( $map[ $chip ] ) ) {
			return $map[ $chip ];
		}
		$all = array( 'pending', 'on-hold', 'processing', 'completed', 'cancelled', 'failed', 'refunded' );
		return '' !== $underpaid ? array_merge( $all, array( $underpaid ) ) : $all;
	}

	/* ================================================================
	 *  data access (seams)
	 * ================================================================ */

	private static function from_sql(): string {
		$acc   = self::accounts_table();
		$plans = BizCity_Usage_Schema::plans_table();
		return "FROM {$acc} a LEFT JOIN {$plans} p ON p.platform = 'zalo_personal' AND p.account_id = a.bridge_account_id WHERE a.kind = 'personal'";
	}

	/** @return array{0:string,1:array} extra WHERE (starts with AND) + args */
	public static function chip_where( string $chip, string $search, int $now ): array {
		$w = '';
		$a = array();
		$nows = BizCity_Usage_Plans::fmt( $now );
		switch ( $chip ) {
			case 'pro':
				$w = " AND p.state = 'active' AND p.valid_until > %s";
				$a[] = $nows;
				break;
			case 'trial':
				$w = " AND p.state = 'trial' AND (p.valid_until IS NULL OR p.valid_until > %s)";
				$a[] = $nows;
				break;
			case 'expired':
				$w = " AND (p.state = 'expired' OR (p.state IN ('trial','active') AND p.valid_until IS NOT NULL AND p.valid_until <= %s))";
				$a[] = $nows;
				break;
			case 'none':
				$w = " AND (p.state IS NULL OR p.state = 'none')";
				break;
			case 'ending':
				$w = " AND p.state IN ('trial','active') AND p.valid_until > %s AND p.valid_until <= %s";
				array_push( $a, $nows, BizCity_Usage_Plans::fmt( $now + self::PRO_WARN_D * 86400 ) );
				break;
			case 'refunded':
				$w = ' AND a.bridge_account_id IN (SELECT account_id FROM ' . BizCity_Usage_Plan_Sales::table() . " WHERE status = 'refunded')";
				break;
		}
		if ( '' !== $search ) {
			$like = '%' . self::like( substr( $search, 0, 64 ) ) . '%';
			$w   .= ' AND (a.label LIKE %s OR a.account_name LIKE %s OR a.bridge_account_id LIKE %s)';
			array_push( $a, $like, $like, $like );
		}
		return array( $w, $a );
	}

	/** @return array<int,array<string,mixed>> raw account+plan rows */
	private static function plan_rows( string $where, array $args, int $limit, int $offset ): array {
		return self::rows(
			"SELECT a.bridge_account_id AS account_id, a.label, a.account_name, a.owner_user_id, a.status AS acc_status, a.zalo_uid,
				p.plan_code, p.state, p.plan_mode, p.plan_version, p.started_at, p.valid_until, p.monthly_token_allowance, p.allowance_period,
				p.notice_text, p.ai_off_by_plan, p.ai_off_at, p.enforce_error
			 " . self::from_sql() . $where . ' ORDER BY a.id DESC LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset,
			$args
		);
	}

	/**
	 * Shape every row (plan state as of now) and attach `expected_end`. Used tokens come from ONE grouped query, the 7-day average from ONE more.
	 *
	 * @param array<int,array<string,mixed>> $raw
	 * @return array<int,array<string,mixed>>
	 */
	private static function numbers_with_expect( array $raw, int $now, DateTimeZone $tz ): array {
		$used = BizCity_Usage_Plans::used_for( $raw, $now );
		$ids  = array();
		foreach ( $raw as $r ) {
			if ( in_array( (string) ( $r['state'] ?? '' ), array( 'trial', 'active' ), true ) && (int) ( $r['monthly_token_allowance'] ?? 0 ) > 0 ) {
				$ids[] = (string) $r['account_id'];
			}
		}
		$avg = $ids ? self::avg_by_account( $now, $tz, $ids ) : array();
		$cycle_end = self::cycle_end( $now, $tz );
		$out = array();
		foreach ( $raw as $r ) {
			$id = (string) $r['account_id'];
			$n  = BizCity_Usage_Plans_REST::shape( $r, $now, $tz, (int) ( $used[ $id ] ?? 0 ), self::ai_enabled( $id ) );
			$a  = $avg[ $id ] ?? array( 'daily' => 0.0, 'days' => 0 );
			$n['expected_end'] = BizCity_Usage_Plan_Expect::expected_end(
				array( 'state' => $n['state'], 'valid_until' => (string) ( $r['valid_until'] ?? '' ), 'monthly_token_allowance' => (int) $n['monthly_token_allowance'] ),
				(int) $n['used_tokens'], (float) $a['daily'], (int) $a['days'], $now, $cycle_end
			);
			$out[] = $n;
		}
		return $out;
	}

	/** First moment of next month in the site timezone (the monthly allowance resets then). */
	public static function cycle_end( int $now, DateTimeZone $tz ): int {
		$d = ( new DateTime( '@' . $now ) )->setTimezone( $tz )->modify( 'first day of next month' )->setTime( 0, 0, 0 );
		return (int) $d->getTimestamp();
	}

	/** @return array<string,array{daily:float,days:int}> last 7 site-local days including today, tokens only (tools are not allowance) */
	private static function avg_by_account( int $now, DateTimeZone $tz, array $ids ): array {
		$ids   = array_slice( $ids, 0, self::IDS_MAX );
		$today = ( new DateTime( '@' . $now ) )->setTimezone( $tz );
		$from  = ( clone $today )->modify( '-6 days' )->format( 'Y-m-d' );
		$to    = ( clone $today )->modify( '+1 day' )->format( 'Y-m-d' );
		$ph    = implode( ',', array_fill( 0, count( $ids ), '%s' ) );
		$rows  = self::rows(
			"SELECT account_id, SUM(billed_tokens) AS t, COUNT(DISTINCT day) AS d FROM " . BizCity_Usage_Schema::daily_table() . " WHERE day >= %s AND day < %s AND kind <> 'tool' AND account_id IN ({$ph}) GROUP BY account_id",
			array_merge( array( $from, $to ), $ids )
		);
		$out = array();
		foreach ( $rows as $r ) {
			$out[ (string) $r['account_id'] ] = array( 'daily' => (float) $r['t'] / 7, 'days' => (int) $r['d'] );
		}
		return $out;
	}

	/** @return array{usd_tokens:float,usd_tools:float,tokens:int} whole site, [from_day, to_day) */
	private static function cost( string $from_day, string $to_day, ?array $ids ): array {
		$r = self::rows(
			"SELECT COALESCE(SUM(CASE WHEN kind <> 'tool' THEN billed_usd ELSE 0 END),0) AS usd_tokens, COALESCE(SUM(CASE WHEN kind = 'tool' THEN billed_usd ELSE 0 END),0) AS usd_tools,
				COALESCE(SUM(CASE WHEN kind <> 'tool' THEN billed_tokens ELSE 0 END),0) AS tokens FROM " . BizCity_Usage_Schema::daily_table() . ' WHERE day >= %s AND day < %s',
			array( $from_day, $to_day )
		);
		$r = $r ? $r[0] : array();
		return array( 'usd_tokens' => (float) ( $r['usd_tokens'] ?? 0 ), 'usd_tools' => (float) ( $r['usd_tools'] ?? 0 ), 'tokens' => (int) ( $r['tokens'] ?? 0 ) );
	}

	/** @return array<string,array{usd:float,tool_calls:int}> */
	private static function cost_by_account( string $from_day, string $to_day, array $ids ): array {
		if ( ! $ids ) {
			return array();
		}
		$ph   = implode( ',', array_fill( 0, count( $ids ), '%s' ) );
		$rows = self::rows(
			"SELECT account_id, COALESCE(SUM(billed_usd),0) AS usd, COALESCE(SUM(tool_calls),0) AS tool_calls FROM " . BizCity_Usage_Schema::daily_table() . " WHERE day >= %s AND day < %s AND account_id IN ({$ph}) GROUP BY account_id",
			array_merge( array( $from_day, $to_day ), $ids )
		);
		$out = array();
		foreach ( $rows as $r ) {
			$out[ (string) $r['account_id'] ] = array( 'usd' => (float) $r['usd'], 'tool_calls' => (int) $r['tool_calls'] );
		}
		return $out;
	}

	/** @return array<string,array<int,array{tool:string,count:int}>> two most used tools per number */
	private static function tools_by_account( string $from_day, string $to_day, array $ids ): array {
		if ( ! $ids ) {
			return array();
		}
		$ph   = implode( ',', array_fill( 0, count( $ids ), '%s' ) );
		$rows = self::rows(
			"SELECT account_id, tool, COUNT(*) AS c FROM " . BizCity_Usage_Schema::ledger_table() . " WHERE kind = 'tool' AND occurred_day >= %s AND occurred_day < %s AND account_id IN ({$ph}) AND tool <> '' GROUP BY account_id, tool",
			array_merge( array( $from_day, $to_day ), $ids )
		);
		$by = array();
		foreach ( $rows as $r ) {
			$by[ (string) $r['account_id'] ][] = array( 'tool' => (string) $r['tool'], 'count' => (int) $r['c'] );
		}
		foreach ( $by as $id => $list ) {
			usort( $list, static function ( $a, $b ) { return $b['count'] <=> $a['count'] ?: strcmp( $a['tool'], $b['tool'] ); } );
			$by[ $id ] = array_slice( $list, 0, 2 );
		}
		return $by;
	}

	/** @return array{vnd:int,count:int,oldest_days:?int,underpaid_items:array} */
	private static function pending( int $now ): array {
		$under = self::underpaid_status();
		$woo   = self::woo_orders( array( 'statuses' => array_merge( array( 'pending', 'on-hold' ), '' !== $under ? array( $under ) : array() ), 'page' => 1, 'per_page' => 200 ) );
		$sum = 0; $n = 0; $oldest = null; $items = array();
		$labels = self::labels( array_values( array_unique( array_map( static function ( $w ) { return (string) $w['account_id']; }, (array) $woo['rows'] ) ) ) );
		foreach ( (array) $woo['rows'] as $w ) {
			$sum += (int) $w['total_vnd'];
			++$n;
			$age = (int) floor( ( $now - (int) $w['created_ts'] ) / 86400 );
			$oldest = null === $oldest ? $age : max( $oldest, $age );
			if ( '' !== $under && (string) $w['status'] === $under ) {
				$items[] = array( 'order_id' => (int) $w['order_id'], 'label_masked' => self::label_masked( (string) $w['account_id'], $labels ), 'owner_name' => '',
					'amount_vnd' => (int) $w['total_vnd'], 'bank_amount_vnd' => (int) ( $w['bank_amount_vnd'] ?? 0 ), 'when' => gmdate( 'Y-m-d\TH:i:s\Z', (int) ( $w['bank_ts'] ?? 0 ) ?: (int) $w['created_ts'] ) );
			}
		}
		return array( 'vnd' => $sum, 'count' => $n, 'oldest_days' => $oldest, 'underpaid_items' => $items );
	}

	/** @return array<int,array<string,mixed>> sales refunded in the last 30 days */
	private static function refunded_items( int $now ): array {
		$list = BizCity_Usage_Plan_Sales::list_rows( array( 'status' => 'refunded', 'from' => gmdate( 'Y-m-d H:i:s', $now - 30 * 86400 ) ), 1, 20 );
		$ids  = array_values( array_unique( array_map( static function ( $r ) { return (string) $r['account_id']; }, $list['rows'] ) ) );
		$labels = self::labels( $ids );
		$out = array();
		foreach ( $list['rows'] as $r ) {
			$out[] = array( 'order_id' => (int) $r['order_id'], 'label_masked' => self::label_masked( (string) $r['account_id'], $labels ), 'owner_name' => '', 'when' => self::iso( (string) ( $r['refunded_at'] ?? $r['paid_at'] ) ) );
		}
		return $out;
	}

	/** @return array<int,array<string,mixed>> sale rows keyed by order id */
	private static function sales_by_order( array $ids ): array {
		if ( ! $ids || ! BizCity_Usage_Plan_Sales::installed() ) {
			return array();
		}
		$ph   = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$rows = self::rows( 'SELECT order_id, status, applied_at, valid_until FROM ' . BizCity_Usage_Plan_Sales::table() . " WHERE order_id IN ({$ph})", array_map( 'intval', $ids ) );
		$out  = array();
		foreach ( $rows as $r ) {
			$out[ (int) $r['order_id'] ] = $r;
		}
		return $out;
	}

	/** @return array<string,string> account_id ⇒ raw label */
	private static function labels( array $ids ): array {
		$ids = array_values( array_filter( $ids, static function ( $i ) { return '' !== $i; } ) );
		if ( isset( self::$readers['labels'] ) ) {
			return (array) call_user_func( self::$readers['labels'], $ids );
		}
		if ( ! $ids ) {
			return array();
		}
		$ph   = implode( ',', array_fill( 0, count( $ids ), '%s' ) );
		$rows = self::rows( "SELECT bridge_account_id AS id, label, account_name FROM " . self::accounts_table() . " WHERE kind = 'personal' AND bridge_account_id IN ({$ph})", $ids );
		$out  = array();
		foreach ( $rows as $r ) {
			$out[ (string) $r['id'] ] = '' !== trim( (string) $r['label'] ) ? (string) $r['label'] : (string) $r['account_name'];
		}
		return $out;
	}

	private static function label_masked( string $id, array $labels ): string {
		$raw = trim( (string) ( $labels[ $id ] ?? '' ) );
		return '' !== $raw ? BizCity_Usage_View_REST::mask( $raw ) : 'Số …' . substr( $id, -3 );
	}

	/**
	 * Plan orders of this site from Woo (HPOS-safe API, paginated). Seam woo_orders(args{statuses,page,per_page}): array{rows,total}.
	 *
	 * @return array{rows:array<int,array<string,mixed>>,total:int}
	 */
	private static function woo_orders( array $args ): array {
		if ( isset( self::$readers['woo_orders'] ) ) {
			return (array) call_user_func( self::$readers['woo_orders'], $args );
		}
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return array( 'rows' => array(), 'total' => 0 );
		}
		$q = wc_get_orders( array(
			'status' => array_map( static function ( $s ) { return 'wc-' === substr( $s, 0, 3 ) ? $s : 'wc-' . $s; }, (array) $args['statuses'] ),
			'limit' => (int) $args['per_page'], 'paged' => (int) $args['page'], 'paginate' => true, 'orderby' => 'date', 'order' => 'DESC',
			'meta_query' => array( array( 'key' => BizCity_Usage_Plan_Woo::META_ACCOUNT, 'compare' => 'EXISTS' ) ),
		) );
		$rows = array();
		foreach ( (array) $q->orders as $o ) {
			$paid = method_exists( $o, 'get_date_paid' ) ? $o->get_date_paid() : null;
			$rows[] = array(
				'order_id' => (int) $o->get_id(), 'status' => (string) $o->get_status(), 'created_ts' => (int) $o->get_date_created()->getTimestamp(),
				'total_vnd' => (int) round( (float) $o->get_total() ), 'account_id' => (string) $o->get_meta( BizCity_Usage_Plan_Woo::META_ACCOUNT ),
				'package' => (string) $o->get_meta( '_bizcity_plan_package' ), 'months' => (int) $o->get_meta( '_bizcity_plan_months' ),
				'channel' => (string) $o->get_meta( '_bizcity_plan_channel' ), 'created_by' => (int) $o->get_meta( '_bizcity_plan_created_by' ), 'payer_user_id' => (int) $o->get_user_id(),
				'bank_amount_vnd' => (int) $o->get_meta( 'ttck_bank_amount' ), 'bank_ts' => $paid ? (int) $paid->getTimestamp() : 0, 'bank_ref' => (string) $o->get_meta( 'ttck_ndck' ),
				'edit_url' => method_exists( $o, 'get_edit_order_url' ) ? (string) $o->get_edit_order_url() : '',
			);
		}
		return array( 'rows' => $rows, 'total' => (int) $q->total );
	}

	/** TTCK's "đơn trả thiếu" order status (a custom Woo status chosen in its settings), without the `wc-` prefix; '' when none is configured. */
	private static function underpaid_status(): string {
		if ( isset( self::$readers['underpaid_status'] ) ) {
			return (string) call_user_func( self::$readers['underpaid_status'] );
		}
		if ( ! class_exists( 'TTCKPayment' ) || ! method_exists( 'TTCKPayment', 'get_settings' ) ) {
			return '';
		}
		$s = TTCKPayment::get_settings();
		$v = is_array( $s ) ? (string) ( $s['order_status']['order_status_after_underpaid'] ?? '' ) : '';
		return ( '' === $v || 'wc-default' === $v ) ? '' : ( 'wc-' === substr( $v, 0, 3 ) ? substr( $v, 3 ) : $v );
	}

	private static function wallet(): ?array {
		if ( isset( self::$readers['wallet'] ) ) {
			$w = call_user_func( self::$readers['wallet'] );
			return is_array( $w ) ? $w : null;
		}
		return class_exists( 'BizCity_Usage_Wallet' ) ? BizCity_Usage_Wallet::view() : null;
	}

	private static function rate(): ?float {
		if ( isset( self::$readers['rate'] ) ) {
			$r = call_user_func( self::$readers['rate'] );
			return null === $r ? null : (float) $r;
		}
		return BizCity_Usage_Wallet::rate();
	}

	private static function ai_enabled( string $id ): ?bool {
		if ( isset( self::$readers['ai_enabled'] ) ) {
			return call_user_func( self::$readers['ai_enabled'], $id );
		}
		return class_exists( 'BizCity_Zalo_Account_Flags' ) ? BizCity_Zalo_Account_Flags::ai_enabled( $id ) : null;
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

	private static function send_csv( string $name, array $rows ): void {
		$rows = BizCity_Usage_View_REST::clean( $rows );
		if ( isset( self::$readers['csv'] ) ) {
			call_user_func( self::$readers['csv'], $name, $rows );
			return;
		}
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $name ) . '.csv"' );
		echo self::csv_text( $rows ); // phpcs:ignore WordPress.Security.EscapeOutput
		exit;
	}

	private static function out( array $data ): WP_REST_Response {
		return new WP_REST_Response( BizCity_Usage_View_REST::clean( $data ), 200 );
	}

	private static function iso( string $utc ): string {
		$t = '' === $utc ? false : strtotime( $utc . ' UTC' );
		return false === $t ? '' : gmdate( 'Y-m-d\TH:i:s\Z', $t );
	}

	private static function dmy( int $ts ): string {
		return ( new DateTime( '@' . $ts ) )->setTimezone( self::tz() )->format( 'd/m' );
	}

	private static function is_admin(): bool {
		if ( isset( self::$readers['is_admin'] ) ) {
			return (bool) call_user_func( self::$readers['is_admin'] );
		}
		return class_exists( 'BizCity_Network_Admin_Capability' ) ? BizCity_Network_Admin_Capability::can_manage() : current_user_can( 'manage_options' );
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
}
