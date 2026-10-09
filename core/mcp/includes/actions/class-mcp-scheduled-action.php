<?php
/**
 * BizCity_MCP_Scheduled_Action — envelope `scheduled-action@1` shared by every site action tool that can run at a time
 * (PHASE-0.91 B2-1, doc 20 §1). The tool never does the work inside the MCP call: it writes ONE Lịch row and the
 * existing publisher (reminder_fire listeners: fb_post 20, web_post 25, …) does it, then the row reports back.
 *
 *   commit( tool, event_type, args, ctx, fields ) ⇒
 *     1. row in bizcity_crm_events: event_type of the publisher, start_at = run_at | now, reminder_min = 0,
 *        metadata.inbound FROM THE AUTH CONTEXT (never from args), report_back = true, notify = false (R-VA-7)
 *     2. run_at empty ⇒ single event `bizcity_scheduler_run_event` + spawn_cron (B2-3); the 5-minute scan is the net
 *     3. answer { status: scheduled | queued, event_id, run_at }
 *
 * Biz Central Brain — Johnny Chu (Chu Hoàng Anh). Bizcity Central Brain, Giấy chứng nhận đăng ký quyền tác giả
 * số 8877/2026/QTG (Cục Bản quyền tác giả, 14/09/2026).
 *
 * // [2026-10-05 07:40 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.91-B2-1 — new file.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\MCP
 * @author     Johnny Chu (Chu Hoàng Anh)
 * @copyright  2026 Johnny Chu (Chu Hoàng Anh) — Bizcity Central Brain
 * @since      2026-10-05
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_MCP_Scheduled_Action {

	const RUN_EVENT_HOOK = 'bizcity_scheduler_run_event';

	/** Furthest a run_at may be (days). */
	const MAX_AHEAD_DAYS = 90;

	/**
	 * `run_at` ("YYYY-MM-DD HH:MM", site wall clock) ⇒ "Y-m-d H:i:s", '' for "now", WP_Error when unreadable / past /
	 * too far.
	 *
	 * @return string|WP_Error
	 */
	public static function parse_run_at( $raw ) {
		// [2026-10-05 07:40 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.91-B2-1 — run_at is site wall time; cell converts in one place.
		$raw = trim( (string) $raw );
		if ( '' === $raw ) {
			return '';
		}
		if ( ! preg_match( '/^(\d{4}-\d{2}-\d{2})[ T](\d{2}):(\d{2})(?::\d{2})?$/', $raw, $m ) ) {
			return new WP_Error( 'MCP_QUERY_INVALID', 'Giờ hẹn phải có dạng YYYY-MM-DD HH:MM (giờ của website).', array( 'status' => 422 ) );
		}
		$at  = $m[1] . ' ' . $m[2] . ':' . $m[3] . ':00';
		$ts  = strtotime( $at );
		$now = (int) current_time( 'timestamp' );
		if ( false === $ts ) {
			return new WP_Error( 'MCP_QUERY_INVALID', 'Giờ hẹn không hợp lệ.', array( 'status' => 422 ) );
		}
		if ( $ts < $now - 60 ) {
			return new WP_Error( 'MCP_QUERY_INVALID', 'Giờ hẹn đã qua. Chọn một giờ trong tương lai.', array( 'status' => 422 ) );
		}
		if ( $ts > $now + self::MAX_AHEAD_DAYS * 86400 ) {
			return new WP_Error( 'MCP_QUERY_INVALID', 'Giờ hẹn quá xa (tối đa ' . self::MAX_AHEAD_DAYS . ' ngày).', array( 'status' => 422 ) );
		}
		return $ts <= $now ? '' : $at;
	}

	/** metadata.inbound from the auth context only. */
	public static function inbound( array $ctx ): array {
		$delegated = class_exists( 'BizCity_MCP_Delegation' ) && BizCity_MCP_Delegation::is_delegated( $ctx );
		return array(
			'platform'   => $delegated ? 'zalo_hub' : 'wp',
			'account_id' => substr( (string) ( $ctx['account_id'] ?? '' ), 0, 64 ),
			'user_hash'  => substr( strtolower( preg_replace( '/[^a-f0-9]/i', '', (string) ( $ctx['user_hash'] ?? '' ) ) ), 0, 64 ),
			'surface'    => $delegated ? 'zalo_1_1' : 'wp',
			'turn_id'    => substr( (string) ( $ctx['turn_id'] ?? '' ), 0, 64 ),
		);
	}

	/**
	 * Write the row (+ run-now event). Commit only — the caller has already passed its own validation and the confirm gate.
	 *
	 * @param string $tool       tool name (audit breadcrumb in metadata.source_tool)
	 * @param string $event_type publisher event type (fb_post, web_post, …)
	 * @param array  $args       tool args (only run_at is read here)
	 * @param array  $ctx        auth context (user_id, user_hash, account_id, turn_id)
	 * @param array  $fields     { title, metadata (publisher fields), description? }
	 * @return array|WP_Error { status, event_id, run_at }
	 */
	public static function commit( string $tool, string $event_type, array $args, array $ctx, array $fields ) {
		// [2026-10-05 07:40 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.91-B2-1 — the four steps of scheduled-action@1.
		$uid = (int) ( $ctx['user_id'] ?? 0 );
		if ( $uid <= 0 ) {
			return new WP_Error( 'MCP_AUTH_INVALID', 'Không xác định được người dùng của lượt này.', array( 'status' => 401 ) );
		}
		$run_at = self::parse_run_at( $args['run_at'] ?? '' );
		if ( is_wp_error( $run_at ) ) {
			return $run_at;
		}
		if ( ! class_exists( 'BizCity_Scheduler_Manager' ) ) {
			return new WP_Error( 'MCP_INTERNAL_ERROR', 'Lịch của website chưa sẵn sàng.', array( 'status' => 503 ) );
		}
		$meta = isset( $fields['metadata'] ) && is_array( $fields['metadata'] ) ? $fields['metadata'] : array();
		foreach ( array( 'inbound', 'report_back', 'notify', '_cell' ) as $k ) {
			unset( $meta[ $k ] ); // envelope keys are the server's
		}
		$meta = array_merge( $meta, array(
			'inbound'     => self::inbound( $ctx ),
			'report_back' => true,
			'notify'      => false,
			'source_tool' => substr( $tool, 0, 64 ),
		) );
		$start = '' !== $run_at ? $run_at : (string) current_time( 'mysql' );
		$id    = BizCity_Scheduler_Manager::instance()->create_event( array(
			'user_id'      => $uid,
			'title'        => substr( trim( (string) ( $fields['title'] ?? $tool ) ), 0, 190 ),
			'description'  => (string) ( $fields['description'] ?? '' ),
			'start_at'     => $start,
			'reminder_min' => 0,
			'status'       => 'active',
			'source'       => 'mcp',
			'event_type'   => $event_type,
			'metadata'     => $meta,
		) );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		if ( (int) $id <= 0 ) {
			return new WP_Error( 'MCP_INTERNAL_ERROR', 'Không ghi được dòng Lịch.', array( 'status' => 500 ) );
		}
		if ( '' === $run_at ) {
			if ( function_exists( 'wp_schedule_single_event' ) ) {
				wp_schedule_single_event( time(), self::RUN_EVENT_HOOK, array( (int) $id ) );
			}
			if ( function_exists( 'spawn_cron' ) ) {
				spawn_cron();
			}
		}
		return array(
			'status'   => '' !== $run_at ? 'scheduled' : 'queued',
			'event_id' => (int) $id,
			'run_at'   => $start,
		);
	}
}
