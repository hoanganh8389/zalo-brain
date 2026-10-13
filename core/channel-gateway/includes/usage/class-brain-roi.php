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

	/** Real revenue (USD-equivalent VND total) for this account_id, current calendar month, UTC. */
	public static function revenue_this_month( string $account_id ): float {
		$from = gmdate( 'Y-m-01 00:00:00' );
		$to   = gmdate( 'Y-m-d H:i:s' );
		return self::revenue_between( $account_id, $from, $to );
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
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$table} WHERE workspace_id = %s AND status <> 'archived'",
			'ws_zalo_' . $account_id
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
