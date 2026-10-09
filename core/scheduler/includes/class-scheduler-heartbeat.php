<?php
/**
 * BizCity_Scheduler_Heartbeat — the "Nhịp chạy" numbers: is the one clock really ticking? (PHASE-0.91 AS-8, doc 103 §4.2)
 *
 * Read-only, no new table, no new cron. ONE function (snapshot()) for every surface that shows it: the site Lịch card
 * (UI pending its approved mockup, R-SETTINGS-4L-8) and cell ③ "Đang chạy" of the AX-4 page (0.92).
 *
 *   last_scan          BizCity_Cron_Manager last run of `scheduler.reminder`        red > 10 min
 *   last_dispatch      option bizcity_automation_cron_last_tick                      red > 3 min
 *   overdue            crm_events active AND start_at < now − 10 min                 red > 0
 *   stuck_queue        automation_runs queued AND created_at < now − 5 min           red > 0
 *   failed_24h         crm_events failed (24 h) + automation_runs failed (24 h)      red > 0 (with the row ids)
 *
 * REST: GET bizcity-scheduler/v1/heartbeat (administrators).
 *
 * Biz Central Brain — Johnny Chu (Chu Hoàng Anh). Bizcity Central Brain, Giấy chứng nhận đăng ký quyền tác giả
 * số 8877/2026/QTG (Cục Bản quyền tác giả, 14/09/2026).
 *
 * // [2026-10-05 09:15 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.91-AS-8 — new file.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Scheduler
 * @author     Johnny Chu (Chu Hoàng Anh)
 * @copyright  2026 Johnny Chu (Chu Hoàng Anh) — Bizcity Central Brain
 * @since      2026-10-05
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

if ( class_exists( 'BizCity_Scheduler_Heartbeat' ) ) {
	return;
}

final class BizCity_Scheduler_Heartbeat {

	const SCAN_JOB_ID      = 'scheduler.reminder';
	const DISPATCH_OPTION  = 'bizcity_automation_cron_last_tick';
	const RED_SCAN_SEC     = 600;
	const RED_DISPATCH_SEC = 180;
	const OVERDUE_SEC      = 600;
	const STUCK_SEC        = 300;

	/** @var array<string,callable> test seams: now():int, last_scan():int, last_dispatch():int, counts():array */
	public static $readers = array();

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes(): void {
		register_rest_route( 'bizcity-scheduler/v1', '/heartbeat', array(
			'methods'             => 'GET',
			'callback'            => static function () {
				return new WP_REST_Response( array( 'ok' => true, 'data' => self::snapshot() ), 200 );
			},
			'permission_callback' => static function () {
				return class_exists( 'BizCity_Network_Admin_Capability' ) ? BizCity_Network_Admin_Capability::can_manage() : current_user_can( 'manage_options' );
			},
		) );
	}

	/**
	 * @return array{ok:bool, checked_at:string, items:array<string,array{value:mixed,red:bool,label:string}>}
	 */
	public static function snapshot(): array {
		// [2026-10-05 09:15 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.91-AS-8 — five numbers that already exist, one red/green each.
		$now      = self::now();
		$scan     = self::last_scan();
		$dispatch = self::last_dispatch();
		$c        = self::counts( $now );

		$items = array(
			'last_scan'     => array( 'label' => 'Lần quét Lịch cuối', 'value' => $scan, 'age_sec' => $scan > 0 ? $now - $scan : null, 'red' => $scan <= 0 || $now - $scan > self::RED_SCAN_SEC ),
			'last_dispatch' => array( 'label' => 'Lần phát việc Automation cuối', 'value' => $dispatch, 'age_sec' => $dispatch > 0 ? $now - $dispatch : null, 'red' => $dispatch <= 0 || $now - $dispatch > self::RED_DISPATCH_SEC ),
			'overdue'       => array( 'label' => 'Việc quá hạn chưa bắn', 'value' => (int) $c['overdue'], 'red' => (int) $c['overdue'] > 0 ),
			'stuck_queue'   => array( 'label' => 'Hàng đợi kẹt', 'value' => (int) $c['stuck_queue'], 'red' => (int) $c['stuck_queue'] > 0 ),
			'failed_24h'    => array( 'label' => 'Lỗi 24 giờ', 'value' => (int) $c['failed_events'] + (int) $c['failed_runs'], 'red' => ( (int) $c['failed_events'] + (int) $c['failed_runs'] ) > 0, 'event_ids' => array_values( array_map( 'intval', (array) $c['failed_event_ids'] ) ) ),
		);
		$ok = true;
		foreach ( $items as $i ) {
			$ok = $ok && ! $i['red'];
		}
		return array( 'ok' => $ok, 'checked_at' => gmdate( 'c', $now ), 'items' => $items );
	}

	// ─── Readers ─────────────────────────────────────────────────────────

	private static function now(): int {
		return isset( self::$readers['now'] ) ? (int) call_user_func( self::$readers['now'] ) : time();
	}

	/** Unix time of the last reminder scan (0 = never seen). */
	private static function last_scan(): int {
		if ( isset( self::$readers['last_scan'] ) ) {
			return (int) call_user_func( self::$readers['last_scan'] );
		}
		if ( ! class_exists( 'BizCity_Cron_Manager' ) || ! method_exists( 'BizCity_Cron_Manager', 'instance' ) ) {
			return 0;
		}
		$last = (array) BizCity_Cron_Manager::instance()->last_run( self::SCAN_JOB_ID );
		if ( ! empty( $last['started_at_ts'] ) ) {
			return (int) $last['started_at_ts'];
		}
		$ts = isset( $last['started_at'] ) ? strtotime( (string) $last['started_at'] ) : false;
		return false === $ts ? 0 : (int) $ts;
	}

	private static function last_dispatch(): int {
		if ( isset( self::$readers['last_dispatch'] ) ) {
			return (int) call_user_func( self::$readers['last_dispatch'] );
		}
		return function_exists( 'get_option' ) ? (int) get_option( self::DISPATCH_OPTION, 0 ) : 0;
	}

	/** overdue · stuck_queue · failed_events · failed_runs · failed_event_ids (≤ 20) */
	private static function counts( int $now ): array {
		if ( isset( self::$readers['counts'] ) ) {
			return (array) call_user_func( self::$readers['counts'] ) + array( 'overdue' => 0, 'stuck_queue' => 0, 'failed_events' => 0, 'failed_runs' => 0, 'failed_event_ids' => array() );
		}
		$out = array( 'overdue' => 0, 'stuck_queue' => 0, 'failed_events' => 0, 'failed_runs' => 0, 'failed_event_ids' => array() );
		global $wpdb;
		$mgr = class_exists( 'BizCity_Scheduler_Manager' ) ? BizCity_Scheduler_Manager::instance() : null;
		if ( ! $mgr || ! is_object( $wpdb ) || ! $mgr->is_ready() ) {
			return $out;
		}
		// start_at / created_at are site wall-clock strings: compare with a site-local "now" (gmdate of the shifted stamp).
		$local    = (int) current_time( 'timestamp' );
		$overdue  = gmdate( 'Y-m-d H:i:s', $local - self::OVERDUE_SEC );
		$day_ago  = gmdate( 'Y-m-d H:i:s', $local - 86400 );
		$utc_day  = gmdate( 'Y-m-d H:i:s', time() - 86400 ); // updated_at is MySQL CURRENT_TIMESTAMP (server clock), not site-local
		$table    = $mgr->get_table();
		$out['overdue'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = 'active' AND reminder_min = 0 AND start_at < %s AND start_at >= %s", $overdue, gmdate( 'Y-m-d H:i:s', $local - 7 * 86400 ) ) );
		$ids = (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE status = 'failed' AND updated_at >= %s ORDER BY id DESC LIMIT 20", $utc_day ) );
		$out['failed_event_ids'] = $ids;
		$out['failed_events']    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = 'failed' AND updated_at >= %s", $utc_day ) );
		if ( class_exists( 'BizCity_Automation_Repo_Runs' ) ) {
			$runs = BizCity_Automation_Repo_Runs::table_runs();
			$out['stuck_queue'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$runs} WHERE status = %d AND created_at < %s", BizCity_Automation_Repo_Runs::STATUS_QUEUED, gmdate( 'Y-m-d H:i:s', $local - self::STUCK_SEC ) ) );
			$out['failed_runs'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$runs} WHERE status = %d AND created_at >= %s", BizCity_Automation_Repo_Runs::STATUS_FAIL, $day_ago ) );
		}
		return $out;
	}
}
