<?php
/**
 * BizCity_Business_MCP_Service — read-only business metrics for MCP Brain.
 *
 * Metrics are delegated to the active CRM/WooCommerce report services. This
 * class never writes business data and degrades explicitly when an optional
 * source plugin is not loaded.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\MCP\Brain
 * @since      2026-07-28 (PHASE-0.54-MCP Wave L)
 */

defined( 'ABSPATH' ) || exit;

// [2026-07-28 Johnny Chu] PHASE-0.54-MCP — read-only business metrics bridge.
final class BizCity_Business_MCP_Service {

	private static $instance = null;

	public static function instance() {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function get_sales_metrics( array $args, array $ctx ) {
		// [2026-07-28 Johnny Chu] PHASE-0.54-MCP — delegate revenue aggregation to the canonical CRM Woo bridge.
		$from = $this->date_arg( $args, 'from', -30 );
		$to   = $this->date_arg( $args, 'to', 0 );
		// [2026-10-09 10:51 PM Johnny Chu - Chu Hoàng Anh] CORE-REDUCTION WP-20 W20-L5 — sales.summary is a read the cell needs: the
		// money rules live in core (core/mcp/verticals/woo, BizCity_Woo_Reports_Bridge), no CRM plugin needed. The plugin name stays an
		// alias (fallback for an older layout). The second, unreachable "plugin missing" guard (PHASE-0.96) is gone. `source` keeps
		// its old label: it is part of the MCP contract fixture (bridge.tools_call.sales_summary.json).
		$bridge = class_exists( 'BizCity_Woo_Reports_Bridge' ) ? 'BizCity_Woo_Reports_Bridge' : ( class_exists( 'BizCity_CRM_Woo_Reports_Bridge' ) ? 'BizCity_CRM_Woo_Reports_Bridge' : '' );
		if ( '' === $bridge ) {
			return array( '_degraded' => true, 'reason' => 'crm_woo_reports_unavailable', 'from' => $from, 'to' => $to, 'summary' => $this->zero_sales( $from, $to ) );
		}
		$out = array(
			'from'    => $from,
			'to'      => $to,
			'source'  => 'BizCity_CRM_Woo_Reports_Bridge::get_revenue_summary',
			'summary' => call_user_func( array( $bridge, 'get_revenue_summary' ), $from, $to ),
		);
		// [2026-10-11 Johnny Chu - Chu Hoàng Anh] PHASE-0.101 S101-L7 — `compare_to` (previous_period | previous_year | none): kỳ kề trước cùng
		// độ dài hoặc cùng ngày năm trước; trả `compare {from,to,summary}` + `delta` theo khoá số (abs, pct) để cell/harness so "tuần này vs
		// tuần trước" trong một lời gọi. Không gọi model, không ghi (R-BCB-2). Ca thử HT-W5 đo khoá này.
		$cmp = $this->compare_range( $from, $to, isset( $args['compare_to'] ) ? (string) $args['compare_to'] : 'none' );
		if ( $cmp ) {
			$prev           = (array) call_user_func( array( $bridge, 'get_revenue_summary' ), $cmp[0], $cmp[1] );
			$out['compare'] = array( 'from' => $cmp[0], 'to' => $cmp[1], 'summary' => $prev );
			$out['delta']   = self::delta( (array) $out['summary'], $prev );
		}
		return $out;
	}

	/**
	 * [2026-10-11 Johnny Chu - Chu Hoàng Anh] PHASE-0.101 S101-L7 — khoảng so sánh. `previous_period` = cùng số ngày ngay trước `from`;
	 * `previous_year` = cùng ngày năm trước; khác ⇒ null.
	 *
	 * @return array{0:string,1:string}|null
	 */
	public function compare_range( $from, $to, $mode ) {
		$f = strtotime( $from . ' 00:00:00' );
		$t = strtotime( $to . ' 00:00:00' );
		if ( false === $f || false === $t || $t < $f ) {
			return null;
		}
		if ( 'previous_period' === $mode ) {
			$days = (int) round( ( $t - $f ) / DAY_IN_SECONDS ) + 1;
			return array( date( 'Y-m-d', $f - $days * DAY_IN_SECONDS ), date( 'Y-m-d', $f - DAY_IN_SECONDS ) );
		}
		if ( 'previous_year' === $mode ) {
			return array( date( 'Y-m-d', strtotime( '-1 year', $f ) ), date( 'Y-m-d', strtotime( '-1 year', $t ) ) );
		}
		return null;
	}

	/** Chênh lệch theo khoá số của hai summary: { key: { abs, pct|null } }; khoá không phải số bị bỏ. */
	public static function delta( array $now, array $prev ) {
		$out = array();
		foreach ( $now as $k => $v ) {
			if ( ! is_numeric( $v ) || ! isset( $prev[ $k ] ) || ! is_numeric( $prev[ $k ] ) ) {
				continue;
			}
			$a         = (float) $v - (float) $prev[ $k ];
			$out[ $k ] = array( 'abs' => round( $a, 2 ), 'pct' => 0.0 === (float) $prev[ $k ] ? null : round( $a / (float) $prev[ $k ] * 100, 1 ) );
		}
		return $out;
	}

	public function get_customer_metrics( array $args, array $ctx ) {
		// [2026-07-28 Johnny Chu] PHASE-0.54-MCP — aggregate selected CRM KPIs through the canonical report builder.
		if ( ! class_exists( 'BizCity_CRM_Report_Builder' ) ) {
			return array( '_degraded' => true, 'reason' => 'crm_report_builder_unavailable', 'metrics' => array() );
		}
		$from    = $this->date_arg( $args, 'from', -30 );
		$to      = $this->date_arg( $args, 'to', 0 );
		$metrics = isset( $args['metrics'] ) ? array_values( array_unique( array_map( 'sanitize_key', (array) $args['metrics'] ) ) ) : array( 'conversations_count', 'incoming_messages_count', 'outgoing_messages_count', 'resolutions_count', 'csat_avg', 'sla_breach_count' );
		$metrics = array_slice( $metrics, 0, 12 );
		$group_by = isset( $args['group_by'] ) ? sanitize_key( (string) $args['group_by'] ) : 'none';
		$out      = array();
		// [2026-10-09 10:51 PM Johnny Chu - Chu Hoàng Anh] CORE-REDUCTION WP-20 W20-L5 — the second "plugin missing" guard (PHASE-0.96)
		// was unreachable (the first one above already answers `_degraded`, a read never errors); removed.
		foreach ( $metrics as $metric ) {
			$result = BizCity_CRM_Report_Builder::aggregate( array(
				'metric'   => $metric,
				'group_by' => $group_by,
				'from'     => $from,
				'to'       => $to,
				'inbox_id' => isset( $args['inbox_id'] ) ? (int) $args['inbox_id'] : 0,
				'agent_id' => isset( $args['agent_id'] ) ? (int) $args['agent_id'] : 0,
			) );
			if ( isset( $result['error'] ) ) {
				return new WP_Error( BizCity_MCP_Error::QUERY_INVALID, 'Metric CRM không hợp lệ: ' . $metric . '.', array( 'status' => 400 ) );
			}
			$out[ $metric ] = $result;
		}
		return array( 'from' => $from, 'to' => $to, 'group_by' => $group_by, 'source' => 'BizCity_CRM_Report_Builder::aggregate', 'metrics' => $out );
	}

	public function get_inventory_metrics( array $args, array $ctx ) {
		// [2026-07-28 Johnny Chu] PHASE-0.54-MCP — bounded WooCommerce read-only inventory summary.
		if ( ! function_exists( 'wc_get_products' ) ) {
			return array( '_degraded' => true, 'reason' => 'woocommerce_unavailable', 'total_products' => 0, 'scanned_products' => 0, 'low_stock_count' => 0, 'out_of_stock_count' => 0 );
		}
		$limit = isset( $args['limit'] ) ? max( 1, min( 500, (int) $args['limit'] ) ) : 100;
		$query = wc_get_products( array( 'limit' => $limit, 'status' => array( 'publish', 'draft' ), 'paginate' => true, 'return' => 'objects' ) );
		$products = is_object( $query ) && isset( $query->products ) ? (array) $query->products : (array) $query;
		$total = is_object( $query ) && isset( $query->total ) ? (int) $query->total : count( $products );
		$low_threshold = isset( $args['low_stock_threshold'] ) ? max( 0, (int) $args['low_stock_threshold'] ) : 5;
		$low = 0;
		$out = 0;
		$stock_units = 0;
		foreach ( $products as $product ) {
			if ( ! is_object( $product ) || ! method_exists( $product, 'managing_stock' ) ) {
				continue;
			}
			$quantity = $product->get_stock_quantity();
			if ( $product->get_stock_status() === 'outofstock' || ( $quantity !== null && (float) $quantity <= 0 ) ) {
				$out++;
			} elseif ( $product->managing_stock() && $quantity !== null && (float) $quantity <= $low_threshold ) {
				$low++;
			}
			if ( $quantity !== null ) {
				$stock_units += max( 0, (int) $quantity );
			}
		}
		return array(
			'source'             => 'WooCommerce CRUD API',
			'total_products'     => $total,
			'scanned_products'   => count( $products ),
			'scan_limit'         => $limit,
			'partial'            => $total > count( $products ),
			'low_stock_threshold' => $low_threshold,
			'low_stock_count'    => $low,
			'out_of_stock_count' => $out,
			'stock_units_scanned'=> $stock_units,
		);
	}

	private function date_arg( array $args, $key, $offset_days ) {
		if ( ! empty( $args[ $key ] ) ) {
			$value = sanitize_text_field( (string) $args[ $key ] );
			if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
				return $value;
			}
		}
		return date( 'Y-m-d', current_time( 'timestamp' ) + ( (int) $offset_days * DAY_IN_SECONDS ) );
	}

	private function zero_sales( $from, $to ) {
		return array( 'gross' => 0.0, 'net' => 0.0, 'refunds' => 0.0, 'order_count' => 0, 'paid_count' => 0, 'aov' => 0.0, 'currency' => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'VND', 'from' => $from, 'to' => $to );
	}
}
