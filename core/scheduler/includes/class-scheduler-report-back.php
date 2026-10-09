<?php
/**
 * BizCity_Scheduler_Report_Back — the site posts the result of a cell-requested run back to the cell as ONE
 * `job_result` letter (PHASE-0.91 B1-3 / C-3, contract channel-inbound@1.1 + automation-scenario@1 §5, D91-34).
 *
 *   automation_run row done|failed (Run_Ledger::finish ⇒ bizcity_scheduler_event_completed / _failed)
 *     ⇒ requester has a Zalo chat?  no  ⇒ D91-18 fallback: TwinChat notice, the Lịch row keeps the result
 *                                   yes ⇒ letter = frozen fixture zalo-hub/contracts/fixtures/cid/channel-inbound.job-result.json
 *     ⇒ PUT  {hub}/bizcity/v1/zalo-hub/channel/register  (once per site key: platform `scheduler`, channel_ref = client_instance_id)
 *     ⇒ POST {hub}/bizcity/v1/zalo-hub/channel/inbound   Bearer = this blog's 1API key
 *         ack accepted|duplicate ⇒ metadata.report {at,status,delivered,skip_reason}
 *         5xx / 429 / network    ⇒ retry with back-off through wp-cron (≤ 6 attempts)
 *         other 4xx              ⇒ no retry, the error is written on the row
 *
 * Only rows `event_type = automation_run` with `metadata.report_back = true` are handled. The letter never carries
 * `role`, `user_hash`, `wp_user_id`, `principal`, `tenant_id`, `person_id` as KEYS at any depth (the Hub and the cell
 * answer 400): the requester's hash travels only as the VALUE of `contact.platform_uid`. The site never messages
 * anybody itself (R-VA-7). metadata.report is written decode → merge → encode under the ledger row lock.
 *
 * Biz Central Brain — Johnny Chu (Chu Hoàng Anh). Bizcity Central Brain, Giấy chứng nhận đăng ký quyền tác giả
 * số 8877/2026/QTG (Cục Bản quyền tác giả, 14/09/2026). R-BIZ-CENTRAL-BRAIN R-BCB-8.
 *
 * // [2026-10-05 07:00 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.91-B1-3 / C-3.1…C-3.4 — new file.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Scheduler
 * @author     Johnny Chu (Chu Hoàng Anh)
 * @copyright  2026 Johnny Chu (Chu Hoàng Anh) — Bizcity Central Brain
 * @since      2026-10-05
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

// [2026-10-06 11:15 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.92-S92-SC-3 — Hub pipe + owner agent are shared with Cell_Dispatch.
// Before the guard: PHP binds this file's class early, so class_exists() is already true below and would skip these.
require_once __DIR__ . '/class-scheduler-hub-pipe.php';
require_once __DIR__ . '/class-scheduler-owner-agent.php';

if ( class_exists( 'BizCity_Scheduler_Report_Back' ) ) {
	return;
}

final class BizCity_Scheduler_Report_Back {

	const CONTRACT       = 'channel-inbound@1.1.0';
	const PLATFORM       = 'scheduler';
	const PATH_INBOUND   = '/zalo-hub/channel/inbound';
	const PATH_REGISTER  = '/zalo-hub/channel/register';
	const RETRY_HOOK     = 'bizcity_scheduler_report_back_retry';
	const MAX_ATTEMPTS   = 6;
	const REG_OPTION     = 'bizcity_scheduler_report_channel';
	const KEY_ID_CACHE   = 'bizcity_scheduler_report_key_id';
	const NOTICE_META    = 'bizcity_scheduler_notices';
	const NOTICE_MAX     = 20;
	const CLIENT_OPTION  = 'bizcity_zalo_client_instance_id'; // BizCity_Zalo_Personal_Hub_Client::CLIENT_INSTANCE_OPTION
	const FORBIDDEN_KEYS = array( 'role', 'tenant_id', 'person_id', 'user_hash', 'wp_user_id', 'principal' );

	/** Terminal report statuses: never sent again. */
	const SETTLED = array( 'accepted', 'duplicate', 'fallback', 'rejected', 'skipped' );

	/**
	 * Test / ops seams: target():?{url,key,headers} · key_id():int · client_instance():string ·
	 * http(method,url,headers,body):{status,body} · reachable(cell,user_id):?{user_hash} · now():int · compose(slug):bool
	 *
	 * @var array<string,callable>
	 */
	public static $readers = array();

	private static $hooked = false;

	public static function init(): void {
		if ( self::$hooked ) {
			return;
		}
		self::$hooked = true;
		add_action( 'bizcity_scheduler_event_completed', array( __CLASS__, 'on_completed' ), 20, 2 );
		add_action( 'bizcity_scheduler_event_failed', array( __CLASS__, 'on_failed' ), 20, 3 );
		add_action( self::RETRY_HOOK, array( __CLASS__, 'on_retry' ), 10, 2 );
	}

	/** @param mixed $id */
	public static function on_completed( $id, $event = null ): void {
		self::safe( (int) $id, 0 );
	}

	/** @param mixed $id */
	public static function on_failed( $id, $event = null, $reason = '' ): void {
		self::safe( (int) $id, 0 );
	}

	/** wp-cron retry. */
	public static function on_retry( $id, $attempt = 1 ): void {
		self::safe( (int) $id, max( 1, (int) $attempt ) );
	}

	/**
	 * Report one row. Returns what happened (tests, ops): { status, delivered?, skip_reason?, http?, letter? }.
	 * Never throws.
	 */
	public static function handle( int $event_id, int $attempt = 0 ): array {
		// [2026-10-05 07:00 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.91-B1-3 — only automation_run rows that asked for a report.
		if ( ! class_exists( 'BizCity_Scheduler_Run_Ledger' ) ) {
			return array( 'status' => 'ignored', 'skip_reason' => 'no_ledger' );
		}
		$row = BizCity_Scheduler_Run_Ledger::get( $event_id );
		// [2026-10-06 12:05 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.91-AX-PERM — + cron occurrences (automation_workflow) that asked for a report: the owner who
		// set the schedule is the requester (row user_id), answered in their Zalo 1-1 through the cell.
		if ( ! is_array( $row ) || ! in_array( (string) ( $row['event_type'] ?? '' ), array( BizCity_Scheduler_Run_Ledger::TYPE_RUN, BizCity_Scheduler_Run_Ledger::TYPE_WORKFLOW ), true ) ) {
			return array( 'status' => 'ignored', 'skip_reason' => 'not_automation_run' );
		}
		$meta   = is_array( $row['metadata'] ?? null ) ? $row['metadata'] : array();
		$status = (string) ( $row['status'] ?? '' );
		if ( empty( $meta['report_back'] ) || ! in_array( $status, array( 'done', 'failed' ), true ) ) {
			return array( 'status' => 'ignored', 'skip_reason' => 'no_report_back' );
		}
		$prev = isset( $meta['report']['status'] ) ? (string) $meta['report']['status'] : '';
		if ( in_array( $prev, self::SETTLED, true ) ) {
			return array( 'status' => $prev, 'skip_reason' => 'already_reported' );
		}
		if ( '' === (string) ( $meta['run_id'] ?? '' ) ) {
			return self::settle( $event_id, array( 'status' => 'skipped', 'delivered' => false, 'skip_reason' => 'no_run' ) );
		}

		// C-3.4 / D91-18: no Zalo chat for the requester ⇒ TwinChat notice, never a silent end.
		$cell = isset( $meta['_cell'] ) && is_array( $meta['_cell'] ) ? $meta['_cell'] : array();
		// [2026-10-06 10:44 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.92 D92-11 — default recipient = the person who asked (`_cell`), else the row owner; a chosen WP user overrides both.
		$rcpt = (int) ( $meta['recipient_user_id'] ?? 0 );
		$who  = $rcpt > 0 ? self::requester( array(), $rcpt ) : self::requester( $cell, (int) ( $row['user_id'] ?? 0 ) );
		if ( null === $who ) {
			self::notice( $row, $meta );
			return self::settle( $event_id, array( 'status' => 'fallback', 'delivered' => false, 'skip_reason' => 'no_zalo_chat' ) );
		}

		$target   = self::target();
		$key_id   = self::key_id();
		$instance = self::client_instance();
		if ( null === $target || $key_id <= 0 || '' === $instance ) {
			return self::retry_or_give_up( $event_id, $attempt, 0, 'hub_not_ready' );
		}
		$letter = self::letter( $row, $meta, $who['user_hash'], $key_id, $instance, $attempt );

		// C-3.1: the Hub relays only a registered channel.
		self::ensure_registered( $target, $instance );

		// C-3.3
		$res  = self::http( 'POST', $target['url'] . self::PATH_INBOUND, $target, $letter );
		$code = (int) $res['status'];
		$ack  = is_array( $res['body'] ) ? $res['body'] : array();
		if ( $code >= 200 && $code < 300 && in_array( (string) ( $ack['status'] ?? '' ), array( 'accepted', 'duplicate' ), true ) ) {
			$out = array(
				'status'      => (string) $ack['status'],
				'delivered'   => ! empty( $ack['delivered'] ),
				'skip_reason' => isset( $ack['skip_reason'] ) ? substr( sanitize_key( (string) $ack['skip_reason'] ), 0, 40 ) : '',
				'http'        => $code,
			);
			return self::settle( $event_id, $out ) + array( 'letter' => $letter );
		}
		if ( 0 === $code || 429 === $code || $code >= 500 ) {
			return self::retry_or_give_up( $event_id, $attempt, $code, 'hub_unavailable' ) + array( 'letter' => $letter );
		}
		if ( 403 === $code ) {
			self::forget_registration(); // key rotated / channel not registered yet: register again next time
		}
		$err = substr( sanitize_key( (string) ( $ack['code'] ?? $ack['error'] ?? 'http_' . $code ) ), 0, 60 );
		return self::settle( $event_id, array( 'status' => 'rejected', 'delivered' => false, 'skip_reason' => $err, 'http' => $code ) ) + array( 'letter' => $letter );
	}

	/**
	 * The frozen `job_result` envelope (fixture channel-inbound.job-result.json).
	 *
	 * @param string $platform_uid requester hash — a VALUE only.
	 */
	public static function letter( array $row, array $meta, string $platform_uid, int $key_id, string $instance, int $attempt = 0 ): array {
		// [2026-10-05 07:00 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.91-C-3.2 — exactly the fixture shape, no identity keys.
		$event_id = (int) ( $row['id'] ?? 0 );
		$blog     = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 1;
		$status   = 'done' === (string) ( $row['status'] ?? '' ) ? 'done' : 'failed';
		$run_id   = (string) ( $meta['run_id'] ?? '' );
		$slug     = (string) ( $meta['scenario_ref'] ?? ( $meta['_cell']['scenario'] ?? '' ) );
		$surface  = (string) ( $meta['_cell']['surface'] ?? '' );
		$p        = isset( $meta['progress'] ) && is_array( $meta['progress'] ) ? $meta['progress'] : array();
		$channel  = array( 'platform' => self::PLATFORM, 'channel_ref' => $instance );

		$letter = array(
			'contract'   => self::CONTRACT,
			'kind'       => 'job_result',
			'trace_id'   => 'sch.' . $blog . '.' . $event_id . '.' . max( 1, $attempt + 1 ),
			'idem'       => 'sch_' . $blog . '_' . $run_id,
			'thread_key' => 'sch:' . $event_id,
			'tenant'     => array( 'key_id' => $key_id ),
			'channel'    => $channel,
			'contact'    => $channel + array( 'platform_uid' => $platform_uid ),
			'job'        => array(
				'event_type'     => BizCity_Scheduler_Run_Ledger::TYPE_RUN,
				'run_id'         => $run_id,
				'scenario'       => $slug,
				'workflow_id'    => (int) ( $meta['workflow_id'] ?? 0 ),
				'status'         => $status,
				'compose'        => 'done' === $status && self::compose( $slug ),
				'origin_surface' => 'wp' === $surface ? 'wp' : 'zalo_1_1',
				'data'           => self::scrub( array(
					'text'      => self::text( $row, $meta, $status ),
					'artifacts' => self::artifacts( $meta ),
				) ),
				'progress'       => array(
					'ok'           => (int) ( $p['ok'] ?? 0 ),
					'total'        => (int) ( $p['total'] ?? 0 ),
					'failed_step'  => isset( $p['failed_step'] ) && null !== $p['failed_step'] ? (int) $p['failed_step'] : null,
					'failed_block' => isset( $p['failed_block'] ) && '' !== (string) $p['failed_block'] ? (string) $p['failed_block'] : null,
				),
				'event_id'       => $event_id,
			),
		);
		// [2026-10-06 11:24 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.92 S92-LLM-5 — K4 (doc 70 §7): a scheduled run that only woke the cell (action.ask_cell) sends `kind: job_due`
		// (channel-inbound@1.1): the cell acts on the instruction with its own tools and answers the recipient itself.
		if ( 'done' === $status && '' !== trim( (string) ( $meta['instruction'] ?? '' ) ) ) {
			$letter['kind'] = 'job_due';
			$letter['idem'] = 'due_' . $blog . '_' . $run_id;
			$due = array(
				'event_type'     => (string) ( $row['event_type'] ?? BizCity_Scheduler_Run_Ledger::TYPE_RUN ),
				'status'         => 'due',
				'run_id'         => $run_id,
				'workflow_id'    => (int) ( $meta['workflow_id'] ?? 0 ),
				'event_id'       => $event_id,
				'instruction'    => (string) $meta['instruction'],
			);
			if ( ! empty( $meta['publish_scenario'] ) ) {
				$due['publish_scenario'] = (string) $meta['publish_scenario'];
			}
			$due['origin_surface'] = 'schedule'; // fixture key order (channel-inbound.job-due.json)
			$letter['job'] = $due;
			return $letter;
		}
		// [2026-10-06 09:57 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.92-S92-CL-9 — EV-3: the cell writes this text to the event's customer (existing 1-1 thread only), not to the requester.
		if ( isset( $meta['deliver_to'] ) && is_array( $meta['deliver_to'] ) && ! empty( $meta['deliver_to']['platform_uid'] ) ) {
			$letter['job']['deliver_to'] = array_intersect_key( $meta['deliver_to'], array( 'platform' => 1, 'platform_uid' => 1, 'channel_ref' => 1 ) );
		}
		return $letter;
	}

	/**
	 * C-3.1 — register the `scheduler` channel at the Hub once per site key (idempotent at the Hub; repeated on key
	 * change or after a 403). Never blocks the report: a failure is remembered and retried with the next letter.
	 */
	public static function ensure_registered( array $target, string $instance ): bool {
		// [2026-10-05 07:00 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.91-C-3.1 — PUT channel/register (needs HUB H-1 on the Hub side).
		// [2026-10-06 11:15 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.92-S92-SC-3 — body moved to Hub_Pipe.
		return BizCity_Scheduler_Hub_Pipe::ensure_registered( $target, $instance, self::$readers );
	}

	// ─── Helpers ─────────────────────────────────────────────────────────

	private static function safe( int $event_id, int $attempt ): void {
		try {
			self::handle( $event_id, $attempt );
		} catch ( \Throwable $e ) {
			error_log( '[scheduler][report-back] event ' . $event_id . ' swallowed ' . get_class( $e ) . ': ' . $e->getMessage() );
		}
	}

	/** {user_hash} of the requester when they have a Zalo chat with a zalo-hub number of this site, else null. */
	private static function requester( array $cell, int $user_id ) {
		if ( isset( self::$readers['reachable'] ) ) {
			$r = call_user_func( self::$readers['reachable'], $cell, $user_id );
			return is_array( $r ) && '' !== (string) ( $r['user_hash'] ?? '' ) ? array( 'user_hash' => (string) $r['user_hash'] ) : null;
		}
		$hash = strtolower( preg_replace( '/[^a-f0-9]/i', '', (string) ( $cell['user_hash'] ?? '' ) ) );
		// Run_Ledger::open() (frozen API) keeps account_id/user_hash/role/surface/turn_id of `_cell` — surface tells the origin.
		$from_zalo = 'zalo' === (string) ( $cell['platform'] ?? '' ) || 'zalo_1_1' === (string) ( $cell['surface'] ?? '' );
		if ( $from_zalo && '' !== $hash ) {
			return array( 'user_hash' => $hash ); // the order itself came from that Zalo chat
		}
		// Ordered from TwinWeb / TwinChat: still deliver into the person's own Zalo 1-1 when they are a principal.
		// [2026-10-06 11:15 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.92-S92-SC-2 — same lookup as the owner agent of a calendar row.
		if ( $user_id <= 0 ) {
			return null;
		}
		$agent = '' !== $hash ? BizCity_Scheduler_Owner_Agent::by_hash( $hash ) : BizCity_Scheduler_Owner_Agent::resolve( $user_id );
		return null !== $agent ? array( 'user_hash' => $agent['hash'] ) : null;
	}

	/** D91-18: a TwinChat notice for the requester (newest first, ≤ 20) + an action for any surface that shows it. */
	private static function notice( array $row, array $meta ): void {
		// [2026-10-05 07:00 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.91-C-3.4 — no Zalo chat ⇒ notice, the Lịch row keeps the result.
		$uid    = (int) ( $row['user_id'] ?? 0 );
		$status = 'done' === (string) ( $row['status'] ?? '' ) ? 'done' : 'failed';
		$notice = array(
			'event_id'  => (int) ( $row['id'] ?? 0 ),
			'title'     => (string) ( $row['title'] ?? '' ),
			'status'    => $status,
			'text'      => self::text( $row, $meta, $status ),
			'artifacts' => self::artifacts( $meta ),
			'at'        => gmdate( 'c', self::now() ),
		);
		if ( $uid > 0 && function_exists( 'get_user_meta' ) && function_exists( 'update_user_meta' ) ) {
			$list = get_user_meta( $uid, self::NOTICE_META, true );
			$list = is_array( $list ) ? $list : array();
			array_unshift( $list, $notice );
			update_user_meta( $uid, self::NOTICE_META, array_slice( $list, 0, self::NOTICE_MAX ) );
		}
		/**
		 * A cell-requested run finished for someone without a Zalo chat (D91-18). TwinChat shows it.
		 *
		 * @param int   $user_id
		 * @param array $notice  {event_id,title,status,text,artifacts[],at}
		 */
		do_action( 'bizcity_scheduler_report_back_fallback', $uid, $notice );
	}

	/**
	 * "Gửi lại ngay" of the trace sheet (PHASE-0.92 S92-RT-5): forget the last report outcome (kept as `previous`) and
	 * report the row again now. The letter keeps its `idem` (sch_<blog>_<run_id>), so a cell that already holds it answers
	 * `duplicate` instead of sending the person a second message.
	 */
	public static function resend( int $event_id ): array {
		// [2026-10-06 05:25 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.92-S92-RT-5
		$mgr = class_exists( 'BizCity_Scheduler_Manager' ) ? BizCity_Scheduler_Manager::instance() : null;
		if ( $mgr ) {
			BizCity_Scheduler_Run_Ledger::locked( $event_id, static function () use ( $mgr, $event_id ) {
				$row  = BizCity_Scheduler_Run_Ledger::get( $event_id );
				$meta = is_array( $row ) && is_array( $row['metadata'] ?? null ) ? $row['metadata'] : array();
				$prev = isset( $meta['report'] ) && is_array( $meta['report'] ) ? $meta['report'] : array();
				unset( $prev['previous'] );
				$meta['report'] = array( 'previous' => $prev, 'resent_at' => gmdate( 'c', self::now() ) );
				$mgr->update_event( $event_id, array( 'metadata' => $meta ) );
			} );
		}
		return self::handle( $event_id, 0 );
	}

	/** metadata.report written decode → merge → encode under the ledger's row lock; status untouched (no hook). */
	private static function settle( int $event_id, array $report ): array {
		$report['at'] = gmdate( 'c', self::now() );
		$mgr = class_exists( 'BizCity_Scheduler_Manager' ) ? BizCity_Scheduler_Manager::instance() : null;
		if ( $mgr ) {
			BizCity_Scheduler_Run_Ledger::locked( $event_id, static function () use ( $mgr, $event_id, $report ) {
				$row  = BizCity_Scheduler_Run_Ledger::get( $event_id );
				$meta = is_array( $row ) && is_array( $row['metadata'] ?? null ) ? $row['metadata'] : array();
				$prev = isset( $meta['report'] ) && is_array( $meta['report'] ) ? $meta['report'] : array();
				$meta['report'] = array_merge( $prev, $report );
				$mgr->update_event( $event_id, array( 'metadata' => $meta ) );
			} );
		}
		return $report;
	}

	private static function retry_or_give_up( int $event_id, int $attempt, int $http, string $reason ): array {
		$next = $attempt + 1;
		if ( $next >= self::MAX_ATTEMPTS ) {
			return self::settle( $event_id, array( 'status' => 'rejected', 'delivered' => false, 'skip_reason' => 'gave_up_' . $reason, 'http' => $http, 'attempts' => $next ) );
		}
		$delay = min( 3600, 60 * ( 2 ** $attempt ) ); // 1, 2, 4, 8, 16 min
		if ( function_exists( 'wp_schedule_single_event' ) ) {
			wp_schedule_single_event( self::now() + $delay, self::RETRY_HOOK, array( $event_id, $next ) );
		}
		return self::settle( $event_id, array( 'status' => 'retrying', 'delivered' => false, 'skip_reason' => $reason, 'http' => $http, 'attempts' => $next ) );
	}

	private static function forget_registration(): void {
		BizCity_Scheduler_Hub_Pipe::forget_registration();
	}

	private static function text( array $row, array $meta, string $status ): string {
		$text = trim( (string) ( $meta['reply_text'] ?? '' ) );
		if ( '' !== $text ) {
			return $text;
		}
		$title = trim( (string) ( $row['title'] ?? '' ) );
		$name  = preg_match( '/«(.+)»/u', $title, $m ) ? $m[1] : ( '' !== $title ? $title : (string) ( $meta['scenario_ref'] ?? '' ) );
		if ( 'done' === $status ) {
			return 'Đã chạy xong kịch bản «' . $name . '».';
		}
		$err = trim( (string) ( $meta['error'] ?? '' ) );
		return 'Kịch bản «' . $name . '» chưa chạy được' . ( '' !== $err ? ': ' . $err : '.' );
	}

	/** {kind,title,url} only (≤ 10) — never an identity key inside artifacts. */
	private static function artifacts( array $meta ): array {
		$out = array();
		foreach ( (array) ( $meta['artifacts'] ?? array() ) as $a ) {
			if ( ! is_array( $a ) || '' === (string) ( $a['url'] ?? '' ) ) {
				continue;
			}
			$out[] = array( 'kind' => (string) ( $a['kind'] ?? 'link' ), 'title' => (string) ( $a['title'] ?? '' ), 'url' => (string) $a['url'] );
			if ( count( $out ) >= 10 ) {
				break;
			}
		}
		return $out;
	}

	/** Drop forbidden identity keys at any depth (defence in depth: the Hub and the cell answer 400 on them). */
	private static function scrub( array $a ): array {
		foreach ( $a as $k => $v ) {
			if ( is_string( $k ) && in_array( $k, self::FORBIDDEN_KEYS, true ) ) {
				unset( $a[ $k ] );
			} elseif ( is_array( $v ) ) {
				$a[ $k ] = self::scrub( $v );
			}
		}
		return $a;
	}

	private static function compose( string $slug ): bool {
		if ( isset( self::$readers['compose'] ) ) {
			return (bool) call_user_func( self::$readers['compose'], $slug );
		}
		if ( '' === $slug || ! class_exists( 'BizCity_Automation_Cell_Catalog' ) ) {
			return false;
		}
		$e = BizCity_Automation_Cell_Catalog::find_by_slug( $slug );
		return is_array( $e ) && 'compose' === (string) ( $e['cell']['reply'] ?? '' );
	}

	// [2026-10-06 11:15 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.92-S92-SC-3 — pipe helpers live in Hub_Pipe; this class keeps its own $readers seam.

	/** @return array{url:string,key:string,headers:array}|null */
	private static function target() {
		return BizCity_Scheduler_Hub_Pipe::target( self::$readers );
	}

	private static function key_id(): int {
		return BizCity_Scheduler_Hub_Pipe::key_id( self::$readers );
	}

	private static function client_instance(): string {
		return BizCity_Scheduler_Hub_Pipe::client_instance( self::$readers );
	}

	/** @return array{status:int,body:mixed} status 0 = network error */
	private static function http( string $method, string $url, array $target, array $body ): array {
		return BizCity_Scheduler_Hub_Pipe::http( $method, $url, $target, $body, self::$readers );
	}

	private static function now(): int {
		return BizCity_Scheduler_Hub_Pipe::now( self::$readers );
	}
}
