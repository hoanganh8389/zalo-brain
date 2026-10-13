<?php
/**
 * Revenue ledger of plan sales — one row per PAID plan order of THIS site (PHASE-0.108 S108-L18.2/L18.4/L20.2, 24-TECH-DESIGN-REVENUE.md §2.1, §4.2).
 *
 * Table `{prefix}bizcity_plan_sales` (own schema version, installed on admin_init — no edit of the shared usage schema). A row is written by the
 * action `bizcity_usage_plan_applied` that BizCity_Usage_Plan_Woo::apply() fires AFTER the plan was extended; `UNIQUE(order_id)` makes writing idempotent.
 * The double-extend guard itself stays in BizCity_Usage_Plan_Woo (option lock). This table is the CACHE that revenue screens read (SUM … GROUP BY) and is
 * rebuildable from the paid Woo orders (`rebuild_from_orders`, S108-L18.5). Only applied sales are here, so every SUM is revenue by definition.
 * A refunded order keeps its row with status `refunded` (the plan is NOT shortened — the owner decides) and is excluded from revenue.
 *
 * Money is whole VND. Times are UTC. The switch option `bizcity_plan_sales_enabled` (default yes) is the way back.
 *
 * Test seams ($readers): rows(sql, args): array · exec(sql, args): int|false · order_details(order_id): ?array · now(): int · enabled(): bool · installed(): bool
 *
 * // [2026-10-13 09:16 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.108 S108-L18.2 / L18.4 / L20.2.
 *
 * @package BizCity_Twin_AI
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_Usage_Plan_Sales {

	const SCHEMA_VERSION = '1.0.0';
	const OPTION_VERSION = 'bizcity_plan_sales_schema';
	const OPT_ENABLED    = 'bizcity_plan_sales_enabled';

	const ST_PAID     = 'paid';
	const ST_REFUNDED = 'refunded';

	/** @var array<string,callable> */
	public static $readers = array();

	private static $initialized = false;

	public static function init(): void {
		if ( self::$initialized ) {
			return;
		}
		self::$initialized = true;
		add_action( 'admin_init', array( __CLASS__, 'maybe_install' ) );
		add_action( 'bizcity_usage_plan_applied', array( __CLASS__, 'on_applied' ), 10, 3 );
		add_action( 'woocommerce_order_status_refunded', array( __CLASS__, 'on_refunded' ), 20, 1 );
	}

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'bizcity_plan_sales';
	}

	public static function installed(): bool {
		if ( isset( self::$readers['installed'] ) ) {
			return (bool) call_user_func( self::$readers['installed'] );
		}
		return function_exists( 'get_option' ) && (string) get_option( self::OPTION_VERSION, '' ) === self::SCHEMA_VERSION;
	}

	/** Idempotent; cheap when the option already matches. */
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
		$t       = self::table();
		dbDelta( "CREATE TABLE {$t} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			order_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			platform VARCHAR(24) NOT NULL DEFAULT 'zalo_personal',
			account_id VARCHAR(64) NOT NULL DEFAULT '',
			package_code VARCHAR(16) NOT NULL DEFAULT '',
			months TINYINT UNSIGNED NOT NULL DEFAULT 1,
			allowance BIGINT UNSIGNED NOT NULL DEFAULT 0,
			amount_vnd BIGINT UNSIGNED NOT NULL DEFAULT 0,
			floor_vnd BIGINT UNSIGNED NOT NULL DEFAULT 0,
			floor_version VARCHAR(40) NOT NULL DEFAULT '',
			channel VARCHAR(8) NOT NULL DEFAULT '',
			created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
			payer_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			status VARCHAR(12) NOT NULL DEFAULT 'paid',
			valid_until DATETIME NULL,
			paid_at DATETIME NOT NULL,
			applied_at DATETIME NOT NULL,
			refunded_at DATETIME NULL,
			method VARCHAR(24) NOT NULL DEFAULT '',
			bank_amount_vnd BIGINT UNSIGNED NOT NULL DEFAULT 0,
			bank_ref VARCHAR(64) NOT NULL DEFAULT '',
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uniq_order (order_id),
			KEY idx_account_paid (account_id, paid_at),
			KEY idx_paid (paid_at),
			KEY idx_status (status)
		) {$charset};" );
		update_option( self::OPTION_VERSION, self::SCHEMA_VERSION, false );
	}

	public static function enabled(): bool {
		if ( isset( self::$readers['enabled'] ) ) {
			return (bool) call_user_func( self::$readers['enabled'] );
		}
		return ! function_exists( 'get_option' ) || 'no' !== (string) get_option( self::OPT_ENABLED, 'yes' );
	}

	/* ================================================================
	 *  Writers
	 * ================================================================ */

	/** Hook: BizCity_Usage_Plan_Woo applied an order. Never throws, never undoes the extension. */
	public static function on_applied( $order_id, $order, $plan_row ): void {
		if ( ! self::enabled() || ! self::installed() ) {
			return;
		}
		try {
			$d = self::details( (int) $order_id );
			if ( ! $d ) {
				return;
			}
			$row = self::shape_row( (int) $order_id, (array) $order, is_array( $plan_row ) ? $plan_row : array(), $d, self::now() );
			self::record( $row );
		} catch ( \Throwable $e ) {
			if ( function_exists( 'error_log' ) ) {
				error_log( '[BizCity][PLAN-SALES] record failed for order ' . (int) $order_id . ': ' . $e->getMessage() );
			}
		}
	}

	/**
	 * Pure: the row to store from an applied order. `$o` is what Plan_Woo::order() returned (account_id, user_id, months, allowance),
	 * `$plan` the plan row after the extension, `$d` the Woo-side details (see details()).
	 *
	 * @return array<string,mixed>
	 */
	public static function shape_row( int $order_id, array $o, array $plan, array $d, int $now ): array {
		$months = max( 1, (int) ( $o['months'] ?? 0 ) ?: (int) ( $d['months'] ?? 0 ) );
		$package = (string) ( $d['package'] ?? '' );
		return array(
			'order_id'        => $order_id,
			'platform'        => (string) ( $plan['platform'] ?? 'zalo_personal' ),
			'account_id'      => (string) ( $o['account_id'] ?? '' ),
			'package_code'    => '' !== $package ? substr( $package, 0, 16 ) : 'legacy', // an order from before the package flow
			'months'          => $months,
			'allowance'       => (int) ( $plan['monthly_token_allowance'] ?? ( $o['allowance'] ?? 0 ) ),
			'amount_vnd'      => max( 0, (int) round( (float) ( $d['total'] ?? 0 ) ) ),
			'floor_vnd'       => max( 0, (int) ( $d['floor_vnd'] ?? 0 ) ),
			'floor_version'   => substr( (string) ( $d['floor_version'] ?? '' ), 0, 40 ),
			'channel'         => in_array( ( $d['channel'] ?? '' ), array( 'admin', 'self' ), true ) ? $d['channel'] : '',
			'created_by'      => (int) ( $d['created_by'] ?? 0 ),
			'payer_user_id'   => (int) ( $o['user_id'] ?? 0 ),
			'status'          => self::ST_PAID,
			'valid_until'     => isset( $plan['valid_until'] ) && '' !== (string) $plan['valid_until'] ? (string) $plan['valid_until'] : null,
			'paid_at'         => self::fmt( (int) ( $d['paid_ts'] ?? 0 ) ?: $now ),
			'applied_at'      => self::fmt( $now ),
			'method'          => substr( (string) ( $d['method'] ?? '' ), 0, 24 ),
			'bank_amount_vnd' => max( 0, (int) ( $d['bank_amount_vnd'] ?? 0 ) ),
			'bank_ref'        => substr( (string) ( $d['bank_ref'] ?? '' ), 0, 64 ),
			'created_at'      => self::fmt( $now ),
		);
	}

	/** Idempotent on order_id (re-running the same order updates the same row). */
	public static function record( array $row ): bool {
		$cols = array( 'order_id', 'platform', 'account_id', 'package_code', 'months', 'allowance', 'amount_vnd', 'floor_vnd', 'floor_version', 'channel', 'created_by', 'payer_user_id', 'status', 'valid_until', 'paid_at', 'applied_at', 'method', 'bank_amount_vnd', 'bank_ref', 'created_at' );
		$ph   = array();
		$args = array();
		foreach ( $cols as $c ) {
			$v = $row[ $c ] ?? null;
			if ( null === $v ) {
				$ph[] = 'NULL';
			} elseif ( is_int( $v ) ) {
				$ph[]   = '%d';
				$args[] = $v;
			} else {
				$ph[]   = '%s';
				$args[] = (string) $v;
			}
		}
		$t   = self::table();
		$upd = 'account_id=VALUES(account_id), package_code=VALUES(package_code), months=VALUES(months), allowance=VALUES(allowance), amount_vnd=VALUES(amount_vnd), valid_until=VALUES(valid_until), applied_at=VALUES(applied_at)';
		$sql = "INSERT INTO {$t} (" . implode( ',', $cols ) . ') VALUES (' . implode( ',', $ph ) . ") ON DUPLICATE KEY UPDATE {$upd}";
		return false !== self::exec( $sql, $args );
	}

	/** The plan is NOT shortened (24 §4.2): only the flag, so the owner sees it and decides. */
	public static function on_refunded( $order_id ): void {
		if ( ! self::installed() ) {
			return;
		}
		self::mark_refunded( (int) $order_id, self::now() );
	}

	public static function mark_refunded( int $order_id, int $now ): bool {
		$t = self::table();
		return false !== self::exec( "UPDATE {$t} SET status = %s, refunded_at = %s WHERE order_id = %d AND status = %s", array( self::ST_REFUNDED, self::fmt( $now ), $order_id, self::ST_PAID ) );
	}

	/* ================================================================
	 *  Readers (revenue screens)
	 * ================================================================ */

	/**
	 * Pure: [from, to) of a range key in UTC strings plus the previous period of the same length.
	 *
	 * @return array{from:string,to:string,prev_from:string,prev_to:string,key:string}
	 */
	public static function period( string $range, int $now, DateTimeZone $tz ): array {
		$local = ( new DateTime( '@' . $now ) )->setTimezone( $tz );
		if ( 'month' === $range || 'prev_month' === $range ) {
			$start = ( clone $local )->modify( 'first day of this month' )->setTime( 0, 0, 0 );
			if ( 'prev_month' === $range ) {
				$start = ( clone $start )->modify( '-1 month' );
			}
			$end        = ( clone $start )->modify( '+1 month' );
			$prev_start = ( clone $start )->modify( '-1 month' );
			$key        = $range;
		} else {
			$days       = '7d' === $range ? 7 : 30;
			$key        = '7d' === $range ? '7d' : '30d';
			$end        = ( clone $local )->setTime( 0, 0, 0 )->modify( '+1 day' ); // today included
			$start      = ( clone $end )->modify( "-{$days} days" );
			$prev_start = ( clone $start )->modify( "-{$days} days" );
		}
		$utc = new DateTimeZone( 'UTC' );
		$f   = static function ( DateTime $d ) use ( $utc ) { return ( clone $d )->setTimezone( $utc )->format( 'Y-m-d H:i:s' ); };
		$day = static function ( DateTime $d ) use ( $tz ) { return ( clone $d )->setTimezone( $tz )->format( 'Y-m-d' ); }; // usage_daily.day / ledger.occurred_day are SITE-local dates
		return array(
			'from' => $f( $start ), 'to' => $f( $end ), 'prev_from' => $f( $prev_start ), 'prev_to' => $f( $start ), 'key' => $key,
			'from_day' => $day( $start ), 'to_day' => $day( $end ), 'prev_from_day' => $day( $prev_start ), 'prev_to_day' => $day( $start ),
		);
	}

	/** Pure: +18.0 for a rise, null when there is no previous revenue to compare with. */
	public static function delta_pct( int $current, int $previous ): ?float {
		if ( $previous <= 0 ) {
			return null;
		}
		return round( ( $current - $previous ) / $previous * 100, 1 );
	}

	/** @return array{paid_vnd:int,paid_orders:int} */
	public static function summary( string $from_utc, string $to_utc ): array {
		if ( ! self::installed() ) {
			return array( 'paid_vnd' => 0, 'paid_orders' => 0 );
		}
		$t = self::table();
		$r = self::rows( "SELECT COALESCE(SUM(amount_vnd),0) AS paid_vnd, COUNT(*) AS paid_orders FROM {$t} WHERE status <> %s AND paid_at >= %s AND paid_at < %s", array( self::ST_REFUNDED, $from_utc, $to_utc ) );
		return array( 'paid_vnd' => (int) ( $r[0]['paid_vnd'] ?? 0 ), 'paid_orders' => (int) ( $r[0]['paid_orders'] ?? 0 ) );
	}

	/**
	 * Per number: revenue in the range, revenue ever, and the latest payment.
	 *
	 * @return array<string,array{paid_vnd:int,paid_total_vnd:int,last_paid_at:?string,last_order_id:int,last_method:string,refunded:int}>
	 */
	public static function by_account( string $from_utc, string $to_utc ): array {
		if ( ! self::installed() ) {
			return array();
		}
		$t   = self::table();
		$out = array();
		$agg = self::rows(
			"SELECT account_id,
				SUM(CASE WHEN status <> %s AND paid_at >= %s AND paid_at < %s THEN amount_vnd ELSE 0 END) AS paid_vnd,
				SUM(CASE WHEN status <> %s THEN amount_vnd ELSE 0 END) AS paid_total_vnd,
				SUM(CASE WHEN status = %s THEN 1 ELSE 0 END) AS refunded,
				MAX(paid_at) AS last_paid_at
			 FROM {$t} GROUP BY account_id",
			array( self::ST_REFUNDED, $from_utc, $to_utc, self::ST_REFUNDED, self::ST_REFUNDED )
		);
		foreach ( $agg as $r ) {
			$out[ (string) $r['account_id'] ] = array(
				'paid_vnd'       => (int) $r['paid_vnd'],
				'paid_total_vnd' => (int) $r['paid_total_vnd'],
				'last_paid_at'   => null === $r['last_paid_at'] ? null : (string) $r['last_paid_at'],
				'last_order_id'  => 0,
				'last_method'    => '',
				'refunded'       => (int) $r['refunded'],
			);
		}
		if ( $out ) {
			$last = self::rows( "SELECT s.account_id, s.order_id, s.method FROM {$t} s JOIN (SELECT account_id, MAX(paid_at) AS m FROM {$t} GROUP BY account_id) x ON x.account_id = s.account_id AND x.m = s.paid_at", array() );
			foreach ( $last as $r ) {
				if ( isset( $out[ (string) $r['account_id'] ] ) ) {
					$out[ (string) $r['account_id'] ]['last_order_id'] = (int) $r['order_id'];
					$out[ (string) $r['account_id'] ]['last_method']   = (string) $r['method'];
				}
			}
		}
		return $out;
	}

	/**
	 * Paid orders, newest first.
	 *
	 * @param array{account_id?:string,status?:string,from?:string,to?:string} $f
	 * @return array{rows:array<int,array<string,mixed>>,total:int}
	 */
	public static function list_rows( array $f, int $page, int $per_page ): array {
		if ( ! self::installed() ) {
			return array( 'rows' => array(), 'total' => 0 );
		}
		$t     = self::table();
		$where = '1=1';
		$args  = array();
		if ( ! empty( $f['account_id'] ) ) {
			$where .= ' AND account_id = %s';
			$args[] = (string) $f['account_id'];
		}
		if ( ! empty( $f['status'] ) ) {
			$where .= ' AND status = %s';
			$args[] = (string) $f['status'];
		}
		if ( ! empty( $f['from'] ) ) {
			$where .= ' AND paid_at >= %s';
			$args[] = (string) $f['from'];
		}
		if ( ! empty( $f['to'] ) ) {
			$where .= ' AND paid_at < %s';
			$args[] = (string) $f['to'];
		}
		$per    = min( 50, max( 1, $per_page ) );
		$offset = max( 0, ( $page - 1 ) * $per );
		$total  = self::rows( "SELECT COUNT(*) AS n FROM {$t} WHERE {$where}", $args );
		$rows   = self::rows( "SELECT * FROM {$t} WHERE {$where} ORDER BY paid_at DESC, id DESC LIMIT %d OFFSET %d", array_merge( $args, array( $per, $offset ) ) );
		return array( 'rows' => $rows, 'total' => (int) ( $total[0]['n'] ?? 0 ) );
	}

	/* ================================================================
	 *  Rebuild (S108-L18.5)
	 * ================================================================ */

	/**
	 * Fill missing rows from paid Woo orders that were already applied (before this table existed). Safe to repeat. Returns [scanned, inserted].
	 *
	 * @return array{0:int,1:int}
	 */
	public static function rebuild_from_orders( int $limit = 200 ): array {
		if ( ! self::installed() || ! function_exists( 'wc_get_orders' ) || ! class_exists( 'BizCity_Usage_Plan_Woo' ) ) {
			return array( 0, 0 );
		}
		$ids = wc_get_orders( array(
			'limit'      => max( 1, min( 500, $limit ) ),
			'status'     => array( 'wc-processing', 'wc-completed' ),
			'return'     => 'ids',
			'meta_query' => array( array( 'key' => BizCity_Usage_Plan_Woo::META_APPLIED, 'compare' => 'EXISTS' ) ),
		) );
		$ins = 0;
		foreach ( (array) $ids as $oid ) {
			$d = self::details( (int) $oid );
			if ( ! $d ) {
				continue;
			}
			$o = array( 'account_id' => (string) ( $d['account_id'] ?? '' ), 'user_id' => (int) ( $d['payer_user_id'] ?? 0 ), 'months' => (int) ( $d['months'] ?? 0 ) );
			if ( '' === $o['account_id'] ) {
				continue;
			}
			$plan = class_exists( 'BizCity_Usage_Plans' ) ? (array) BizCity_Usage_Plans::get( BizCity_Usage_Plans::PLATFORM, $o['account_id'] ) : array();
			if ( self::record( self::shape_row( (int) $oid, $o, $plan, $d, self::now() ) ) ) {
				++$ins;
			}
		}
		return array( count( (array) $ids ), $ins );
	}

	/* ================================================================
	 *  Woo side (seam)
	 * ================================================================ */

	/**
	 * What the revenue row needs from the Woo order. Test seam: order_details(order_id).
	 *
	 * @return array{total:float,package:string,months:int,floor_vnd:int,floor_version:string,channel:string,created_by:int,paid_ts:int,method:string,bank_amount_vnd:int,bank_ref:string,account_id:string,payer_user_id:int}|null
	 */
	public static function details( int $order_id ): ?array {
		if ( isset( self::$readers['order_details'] ) ) {
			$d = call_user_func( self::$readers['order_details'], $order_id );
			return is_array( $d ) ? $d : null;
		}
		if ( ! function_exists( 'wc_get_order' ) ) {
			return null;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return null;
		}
		$paid = method_exists( $order, 'get_date_paid' ) ? $order->get_date_paid() : null;
		$ndck = (string) $order->get_meta( 'ttck_ndck' );
		return array(
			'total'           => (float) $order->get_total(),
			'package'         => (string) $order->get_meta( '_bizcity_plan_package' ),
			'months'          => (int) $order->get_meta( '_bizcity_plan_months' ),
			'floor_vnd'       => (int) $order->get_meta( '_bizcity_plan_floor_vnd' ),
			'floor_version'   => (string) $order->get_meta( '_bizcity_plan_floor_version' ),
			'channel'         => (string) $order->get_meta( '_bizcity_plan_channel' ),
			'created_by'      => (int) $order->get_meta( '_bizcity_plan_created_by' ),
			'paid_ts'         => $paid ? (int) $paid->getTimestamp() : 0,
			'method'          => (string) $order->get_payment_method(),
			'bank_amount_vnd' => (int) $order->get_meta( 'ttck_bank_amount' ),
			'bank_ref'        => $ndck,
			'account_id'      => (string) $order->get_meta( '_bizcity_plan_account_id' ),
			'payer_user_id'   => (int) $order->get_user_id(),
		);
	}

	/* ── plumbing ──────────────────────────────────────────────────── */

	private static function rows( string $sql, array $args ): array {
		if ( isset( self::$readers['rows'] ) ) {
			return (array) call_user_func( self::$readers['rows'], $sql, $args );
		}
		global $wpdb;
		$q = $args ? $wpdb->prepare( $sql, $args ) : $sql; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$r = $wpdb->get_results( $q, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return is_array( $r ) ? $r : array();
	}

	/** @return int|false */
	private static function exec( string $sql, array $args ) {
		if ( isset( self::$readers['exec'] ) ) {
			return call_user_func( self::$readers['exec'], $sql, $args );
		}
		global $wpdb;
		return $wpdb->query( $args ? $wpdb->prepare( $sql, $args ) : $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	private static function now(): int {
		return isset( self::$readers['now'] ) ? (int) call_user_func( self::$readers['now'] ) : time();
	}

	private static function fmt( int $ts ): string {
		return gmdate( 'Y-m-d H:i:s', $ts );
	}
}
