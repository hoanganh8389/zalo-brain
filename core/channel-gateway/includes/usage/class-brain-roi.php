<?php
/**
 * ROI for one Zalo Brain account — real revenue, token cost, estimated time saved (PHASE-0.108 S108-L5,
 * master plan §3.1, D108-5: three separate lines, never blended into one "lãi").
 *
 * **Thu thật (real revenue):** sum of PAID Woo orders carrying `_bizcity_plan_account_id` (the existing Pro-plan
 * purchase, class-usage-plan-woo.php) OR `_bizcity_brain_account_id` (Q108-4 — an order an Automation flow
 * created on the customer's behalf; only counted when the order actually carries this meta, never guessed).
 * **Chi token:** read by the caller from `bizcity_usage_daily.billed_usd` — this class does not duplicate it.
 * **Làm thay (time saved), always an ESTIMATE:** customer_replies × minutes_per_reply × hourly_wage, both
 * owner-editable options, always labelled "ước tính" wherever shown and NEVER added into "lãi thật" (luật 4).
 *
 * // [2026-10-13 Johnny Chu - Chu Hoàng Anh] PHASE-0.108 S108-L5.
 *
 * @package BizCity_Twin_AI
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_Brain_ROI {

	const META_BRAIN_ORDER = '_bizcity_brain_account_id'; // Q108-4 — proposal, not yet owner-locked.
	const OPT_MINUTES       = 'bizcity_brain_roi_minutes_per_reply'; // default 2 (Q108-9 proposal)
	const OPT_WAGE_VND      = 'bizcity_brain_roi_wage_vnd_per_hour'; // default 30000 (Q108-9 proposal)
	const PAID_STATUSES     = array( 'wc-completed', 'wc-processing' );

	/**
	 * Test seam: orders(account_id, from, to): array<int,array{total:float,id:int}> — real revenue lookup
	 * without touching WooCommerce directly (unit tests supply fixture rows here).
	 *
	 * @var array<string,callable>
	 */
	public static $readers = array();

	/**
	 * Real revenue of this account_id, current calendar month, UTC, in USD.
	 * [2026-10-14 03:50 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.108 — revenue_between() sums Woo order totals in the SHOP currency (₫) while token cost (chi) is USD:
	 * comparing them raw made any order look like a payback. Convert once, here, with the Hub rate; no rate ⇒ 0 (never guess a rate, G3).
	 */
	public static function revenue_this_month( string $account_id ): float {
		return self::revenue_usd_between( $account_id, gmdate( 'Y-m-01 00:00:00' ), gmdate( 'Y-m-d H:i:s' ) );
	}

	/** Shop-currency (₫) revenue converted to USD by the Hub rate; 0.0 when the rate is unknown. */
	public static function revenue_usd_between( string $account_id, string $from_gmt, string $to_gmt ): float {
		$rate = self::usd_vnd_rate();
		return ( null === $rate || $rate <= 0 ) ? 0.0 : self::revenue_between( $account_id, $from_gmt, $to_gmt ) / $rate;
	}

	/** The Hub USD→₫ rate (test seam `rate`); null when unknown. */
	public static function usd_vnd_rate(): ?float {
		if ( isset( self::$readers['rate'] ) ) {
			$r = call_user_func( self::$readers['rate'] );
			return ( is_numeric( $r ) && (float) $r > 0 ) ? (float) $r : null;
		}
		return class_exists( 'BizCity_Usage_Plans' ) ? BizCity_Usage_Plans::rate() : null;
	}

	/**
	 * Paid orders of this account in a window — id + total (shop currency) only, never a customer name (card ① shows "đơn #8812").
	 *
	 * @return array<int,array{id:int,total:float}>
	 */
	public static function order_refs_between( string $account_id, string $from_gmt, string $to_gmt ): array {
		$out = array();
		if ( isset( self::$readers['orders'] ) ) {
			foreach ( (array) call_user_func( self::$readers['orders'], $account_id, $from_gmt, $to_gmt ) as $r ) {
				$out[] = array( 'id' => (int) ( $r['id'] ?? 0 ), 'total' => (float) ( $r['total'] ?? 0 ) );
			}
			return $out;
		}
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return array();
		}
		foreach ( array( BizCity_Usage_Plan_Woo_Meta_Key(), self::META_BRAIN_ORDER ) as $meta_key ) {
			if ( '' === $meta_key ) {
				continue;
			}
			foreach ( (array) wc_get_orders( array(
				'status' => self::PAID_STATUSES, 'meta_key' => $meta_key, 'meta_value' => $account_id, // phpcs:ignore WordPress.DB.SlowDBQuery
				'date_created' => gmdate( 'Y-m-d', strtotime( $from_gmt ) ) . '...' . gmdate( 'Y-m-d', strtotime( $to_gmt ) ), 'limit' => 50, 'return' => 'objects',
			) ) as $order ) {
				if ( is_object( $order ) && method_exists( $order, 'get_total' ) ) {
					$out[ (int) $order->get_id() ] = array( 'id' => (int) $order->get_id(), 'total' => (float) $order->get_total() );
				}
			}
		}
		return array_values( $out );
	}

	public static function revenue_between( string $account_id, string $from_gmt, string $to_gmt ): float {
		if ( isset( self::$readers['orders'] ) ) {
			$rows = (array) call_user_func( self::$readers['orders'], $account_id, $from_gmt, $to_gmt );
			$sum = 0.0;
			foreach ( $rows as $r ) {
				$sum += (float) ( $r['total'] ?? 0 );
			}
			return $sum;
		}
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return 0.0;
		}
		$sum = 0.0;
		foreach ( array( BizCity_Usage_Plan_Woo_Meta_Key(), self::META_BRAIN_ORDER ) as $meta_key ) {
			if ( '' === $meta_key ) {
				continue;
			}
			$orders = wc_get_orders( array(
				'status'       => self::PAID_STATUSES,
				'meta_key'     => $meta_key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'   => $account_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'date_created' => gmdate( 'Y-m-d', strtotime( $from_gmt ) ) . '...' . gmdate( 'Y-m-d', strtotime( $to_gmt ) ),
				'limit'        => -1,
				'return'       => 'objects',
			) );
			foreach ( (array) $orders as $order ) {
				if ( is_object( $order ) && method_exists( $order, 'get_total' ) ) {
					$sum += (float) $order->get_total();
				}
			}
		}
		return $sum;
	}

	/** Estimated hours and VND of "làm thay" — always labelled an estimate, never added to real profit. */
	public static function time_saved_estimate( int $customer_replies ): array {
		$minutes = (float) get_option( self::OPT_MINUTES, 2 );
		$wage    = (float) get_option( self::OPT_WAGE_VND, 30000 );
		$hours   = ( $customer_replies * $minutes ) / 60;
		return array(
			'hours'           => round( $hours, 1 ),
			'vnd_estimate'    => round( $hours * $wage ),
			'minutes_per_reply' => $minutes,
			'wage_vnd_per_hour' => $wage,
		);
	}

	/**
	 * Best-effort "được dạy" count for one brain: entries in the per-number notebook
	 * (`ws_zalo_<account_id>`, memory: kg-notebook-per-zalo-number) that are not archived.
	 */
	public static function trained_items_count( string $account_id ): int {
		if ( ! class_exists( 'BizCity_Journal_Database' ) ) {
			return 0;
		}
		global $wpdb;
		$table = BizCity_Journal_Database::instance()->get_table();
		// [2026-10-20 11:33 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.126 R108-03 (BS-2/BS-5) — "được dạy" = what a person TAUGHT (documents, notebook sources, skills, instructions):
		// job headers, products of the assistant and ledger rows (twin_sheet) are machine work, never teaching; void rows are gone.
		$skip = array();
		if ( class_exists( 'BizCity_Journal_Kinds' ) ) {
			foreach ( array_keys( (array) ( BizCity_Journal_Kinds::all()['kinds'] ?? array() ) ) as $k ) {
				if ( in_array( BizCity_Journal_Kinds::layer_of( (string) $k ), array( 'job', 'output', 'twin_sheet' ), true ) ) {
					$skip[] = (string) $k;
				}
			}
		}
		$not_in = array() === $skip ? '' : ' AND kind NOT IN (' . implode( ',', array_fill( 0, count( $skip ), '%s' ) ) . ')';
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$table} WHERE workspace_id = %s AND status NOT IN ('archived','void'){$not_in}",
			array_merge( array( 'ws_zalo_' . $account_id ), $skip )
		) );
	}

	/**
	 * "Tự trả lương" bronze tier (master plan §3.1 ②, §3.3 B): the FIRST calendar month ever where
	 * thu_usd_month >= chi_usd_month AND chi_usd_month > 0 (an idle account with 0/0 never trivially "pays
	 * back"). `code` has no month in it on purpose — dedupe_key makes this a once-ever lifetime event, not a
	 * monthly notice (the doc calls it "the moment", singular). Silver/gold (3/12 CONSECUTIVE months) need a
	 * month-by-month payback history this pass does not keep yet — deferred, not implemented.
	 */
	public static function maybe_write_payback_event( string $account_id, int $owner_user_id, array $metrics ): void {
		if ( ! class_exists( 'BizCity_Brain_Journey_Evaluator' ) ) {
			return;
		}
		$chi = (float) ( $metrics['chi_usd_month'] ?? 0 );
		$thu = (float) ( $metrics['thu_usd_month'] ?? 0 );
		if ( $chi <= 0 || $thu < $chi ) {
			return;
		}
		BizCity_Brain_Journey_Evaluator::write_event( array(
			'subject_kind'  => 'brain',
			'subject_id'    => $account_id,
			'owner_user_id' => $owner_user_id,
			'event'         => 'payback',
			'code'          => 'payback.bronze',
			'tier'          => 'bronze',
			'value'         => (int) round( $thu * 100 ),
			'occurred_at'   => current_time( 'mysql', true ),
			'source'        => 'woo_order',
			'source_ref'    => $account_id,
		) );
	}
}

if ( ! function_exists( 'BizCity_Usage_Plan_Woo_Meta_Key' ) ) {
	/** Decoupling shim: read the Pro-plan order meta key without a hard class dependency at parse time. */
	function BizCity_Usage_Plan_Woo_Meta_Key(): string {
		return class_exists( 'BizCity_Usage_Plan_Woo' ) ? (string) BizCity_Usage_Plan_Woo::META_ACCOUNT : '';
	}
}
