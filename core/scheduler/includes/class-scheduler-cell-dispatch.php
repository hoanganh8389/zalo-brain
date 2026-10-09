<?php
/**
 * BizCity_Scheduler_Cell_Dispatch — hands a calendar row to the cell, which becomes its ONLY clock (PHASE-0.92 W7, D92-11/12,
 * doc core/channel-gateway/docs/PHASE-0.92-AUTOMATION-CELL-AXIS/71-SITE-TO-CELL-SCHEDULER.md §3–§4.2).
 *
 *   REST create/edit (user action) ⇒ prepare(user)  ── no owner agent ⇒ WP_Error 409 owner_agent_missing (row NOT saved)
 *                                   ⇒ row saved with dispatcher=cell, reminder_sent=1, owner columns, cell_state=pending
 *                                   ⇒ push(id) ⇒ letter `channel-inbound@1.2.0` kind `job_upsert` (fixture
 *                                      zalo-hub/contracts/fixtures/cid/channel-inbound.job-upsert.json) ⇒ Hub ⇒ cell
 *         ack accepted|duplicate ⇒ cell_job_id + cell_state=synced
 *         409 owner_agent_not_found ⇒ pending_owner · 409 stale_rev ⇒ ignored · other 4xx ⇒ rejected
 *         0 / 403 / 429 / 5xx ⇒ retrying, back-off through wp-cron (≤ 6 attempts) — the site NEVER fires the row itself.
 *
 * Called only from places where a person acts (REST, chat tools), never from `bizcity_scheduler_event_updated`: the cell's
 * own changes come back through booking.sync_job and must not bounce (doc 71 B-2). Bookkeeping writes go straight to the
 * table (no hook), like Report_Back's metadata.report.
 *
 * Biz Central Brain — Johnny Chu (Chu Hoàng Anh). Bizcity Central Brain, Giấy chứng nhận đăng ký quyền tác giả
 * số 8877/2026/QTG (Cục Bản quyền tác giả, 14/09/2026). R-BIZ-CENTRAL-BRAIN R-BCB-8.
 *
 * // [2026-10-06 11:40 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.92-S92-SC-4 — new file.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Scheduler
 * @since      2026-10-06
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

// Before the guard: PHP binds this file's class early, so class_exists() is already true below and would skip these.
require_once __DIR__ . '/class-scheduler-hub-pipe.php';
require_once __DIR__ . '/class-scheduler-owner-agent.php';

if ( class_exists( 'BizCity_Scheduler_Cell_Dispatch' ) ) {
	return;
}

final class BizCity_Scheduler_Cell_Dispatch {

	const CONTRACT     = 'channel-inbound@1.2.0';
	/** PHASE-0.95 S95-W1 — dòng neo workflow (thêm workflow_id, publish_scenario). */
	const CONTRACT_WORKFLOW = 'channel-inbound@1.3.0';
	/** PHASE-0.95 S95-W4 — fired after the cell accepted a row (status accepted|duplicate): ($event_id, $op, $status). */
	const SETTLED_HOOK = 'bizcity_scheduler_cell_push_settled';
	const KIND         = 'job_upsert';
	const RETRY_HOOK   = 'bizcity_scheduler_cell_push_retry';
	const MAX_ATTEMPTS = 6;
	const FORBIDDEN_KEYS = array( 'role', 'tenant_id', 'person_id', 'user_hash', 'wp_user_id', 'principal' );

	/**
	 * Reminder-type rows the cell fires (D92-15). Rows that make the SITE do something at a time (fb_post, web_post, woo_*,
	 * automation_*, lead_report …) keep the PHP cron until 0.93. `reminder_personal` / `reminder_zalo` stay too: they deliver
	 * through the user's notify channel (Zalo Bot OA…) and move with PHASE-0.89, not here.
	 */
	const CELL_EVENT_TYPES = array( 'meeting', 'workshop', 'training', 'internal', 'personal', 'task', 'reminder' );

	/** Form "Lặp lại" ⇒ cron day-of-week field. */
	const RECURRENCES = array( 'daily', 'weekdays', 'weekly' );

	/**
	 * Test seams (merged with Hub_Pipe's): target · key_id · client_instance · http · now · timezone():string ·
	 * can_manage():bool · setup_url():string · fix_url():string · schedule_retry(ts,args)
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
		add_action( self::RETRY_HOOK, array( __CLASS__, 'on_retry' ), 10, 4 );
	}

	/** Does this row (or payload) belong to the cell's clock? */
	public static function is_cell_type( string $event_type, array $meta = array() ): bool {
		if ( ! in_array( $event_type, self::CELL_EVENT_TYPES, true ) ) {
			return false;
		}
		// An explicit target on another channel (Zalo Bot OA, Telegram…) is not the owner agent's 1-1: the site keeps it.
		$inbound = isset( $meta['inbound'] ) && is_array( $meta['inbound'] ) ? $meta['inbound'] : array();
		$target  = isset( $meta['notify']['target'] ) && is_array( $meta['notify']['target'] ) ? $meta['notify']['target'] : array();
		foreach ( array( $inbound, $target ) as $t ) {
			if ( '' !== (string) ( $t['chat_id'] ?? '' ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Is there anything for the cell to do? A row with "Không nhắc" (reminder 0), no repeat and no task for the assistant is a
	 * plain calendar entry and stays as it is today.
	 */
	public static function wants_cell( array $data, array $meta ): bool {
		// [2026-10-07 12:45 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.92-S92-SC-5
		return (int) ( $data['reminder_min'] ?? 15 ) > 0
			|| in_array( (string) ( $meta['recurrence'] ?? '' ), self::RECURRENCES, true )
			|| 'agent' === (string) ( $meta['cell_kind'] ?? '' );
	}

	/**
	 * The owner agent of $user_id, or a 409 WP_Error whose data says what is missing and where to fix it.
	 *
	 * @return array{hash:string,number_ref:string,person_id:string,role:string}|WP_Error
	 */
	public static function prepare( int $user_id ) {
		$agent = BizCity_Scheduler_Owner_Agent::resolve( $user_id );
		if ( null !== $agent ) {
			return $agent;
		}
		$reason = self::missing_reason( $user_id );
		$texts  = array(
			'no_number'     => 'Website chưa có số Zalo nào kết nối trợ lý, nên trợ lý chưa thể nhắc lịch này cho bạn.',
			// [2026-10-07 03:00 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.92-S92-SC-26 — fixed by "Nhận Zalo của bạn" (one code message), not by typing a UID.
			'no_owner_uid'  => 'Số Zalo trợ lý chưa biết Zalo cá nhân của bạn, nên trợ lý chưa biết gửi nhắc lịch vào đâu. Nhắn một mã từ Zalo của bạn tới số trợ lý để nhận.',
			'not_principal' => 'Tài khoản của bạn chưa được gắn với trợ lý Zalo nào, nên trợ lý chưa thể nhắc lịch này cho bạn.',
		);
		return new \WP_Error( 'owner_agent_missing', $texts[ $reason ], array(
			'status' => 409,
			'reason' => $reason,
			'setup'  => array(
				'scope'         => self::can_manage() ? 'full' : 'self',
				// R-SETUP-4 v1.4: a member starts at ③ (own number) — ①② are the admin's.
				'first_step'    => 'no_number' === $reason && self::can_manage() ? 1 : 3,
				'owner_user_id' => $user_id,
				'setup_url'     => self::setup_url(),
				'fix_url'       => 'no_owner_uid' === $reason ? self::fix_url() : '',
			),
		) );
	}

	/** Row fields that hand a new/edited row to the cell. */
	public static function row_fields( array $agent ): array {
		return array(
			'dispatcher'       => BizCity_Scheduler_Manager::DISPATCHER_CELL,
			'reminder_sent'    => 1,
			'owner_agent_hash' => $agent['hash'],
			'owner_number_ref' => $agent['number_ref'],
			'owner_person_id'  => $agent['person_id'],
			'cell_state'       => 'pending',
		);
	}

	/**
	 * Send one row to the cell. Never throws. $snapshot = the row as it was (cancel of a deleted row).
	 *
	 * @return array{status:string,cell_state?:string,job_id?:string,http?:int,letter?:array}
	 */
	public static function push( int $event_id, string $op = 'upsert', int $attempt = 0, ?array $snapshot = null ): array {
		try {
			return self::handle( $event_id, 'cancel' === $op ? 'cancel' : 'upsert', $attempt, $snapshot );
		} catch ( \Throwable $e ) {
			error_log( '[scheduler][cell-dispatch] event ' . $event_id . ' swallowed ' . get_class( $e ) . ': ' . $e->getMessage() );
			return array( 'status' => 'error' );
		}
	}

	/**
	 * [2026-10-09 11:42 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.95-S95-W7 — "Làm mới": read the cell's truth for one row handed over
	 * (`job_get`, channel-inbound@1.3.0, fixture cid/channel-inbound.job-get.json). Read only, never writes the row. Never throws.
	 *
	 * @return array{ok:bool,found?:bool,job?:array,http?:int,reason?:string}
	 */
	public static function fetch( int $event_id ): array {
		try {
			$obj = BizCity_Scheduler_Manager::instance()->get_event( $event_id );
			$row = $obj ? (array) $obj : null;
			if ( ! $row || BizCity_Scheduler_Manager::DISPATCHER_CELL !== (string) ( $row['dispatcher'] ?? '' ) ) {
				return array( 'ok' => false, 'reason' => 'not_cell' );
			}
			$readers  = self::readers();
			$target   = BizCity_Scheduler_Hub_Pipe::target( $readers );
			$key_id   = BizCity_Scheduler_Hub_Pipe::key_id( $readers );
			$instance = BizCity_Scheduler_Hub_Pipe::client_instance( $readers );
			if ( null === $target || $key_id <= 0 || '' === $instance ) {
				return array( 'ok' => false, 'reason' => 'hub_not_ready' );
			}
			$channel = array( 'platform' => BizCity_Scheduler_Hub_Pipe::PLATFORM, 'channel_ref' => $instance );
			$letter  = array(
				'contract' => self::CONTRACT_WORKFLOW,
				'kind'     => 'job_get',
				'trace_id' => 'schg.' . ( function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 1 ) . '.' . $event_id,
				'thread_key' => 'sch:' . $event_id, // [2026-10-10 12:37 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.95-S95-W7 — envelope carries thread_key for every kind (doc 30 §3), same as job_upsert
				'tenant'   => array( 'key_id' => $key_id ),
				'channel'  => $channel,
				'contact'  => $channel + array( 'platform_uid' => strtolower( (string) ( $row['owner_agent_hash'] ?? '' ) ) ),
				'job'      => array( 'site_event_id' => $event_id ),
			);
			BizCity_Scheduler_Hub_Pipe::ensure_registered( $target, $instance, $readers );
			$res  = BizCity_Scheduler_Hub_Pipe::post_letter( $target, $letter, $readers );
			$code = (int) $res['status'];
			$body = is_array( $res['body'] ) ? $res['body'] : array();
			if ( $code < 200 || $code >= 300 || 'job_get' !== (string) ( $body['kind'] ?? '' ) ) {
				return array( 'ok' => false, 'http' => $code, 'reason' => substr( sanitize_key( (string) ( $body['code'] ?? 'http_' . $code ) ), 0, 60 ) );
			}
			$job = isset( $body['job'] ) && is_array( $body['job'] ) ? self::scrub( $body['job'] ) : array();
			return array( 'ok' => true, 'http' => $code, 'found' => ! empty( $body['found'] ), 'job' => $job );
		} catch ( \Throwable $e ) {
			error_log( '[scheduler][cell-dispatch] fetch ' . $event_id . ' swallowed ' . get_class( $e ) . ': ' . $e->getMessage() );
			return array( 'ok' => false, 'reason' => 'error' );
		}
	}

	/** wp-cron retry. */
	public static function on_retry( $event_id, $op = 'upsert', $attempt = 1, $snapshot = null ): void {
		self::push( (int) $event_id, (string) $op, max( 1, (int) $attempt ), is_array( $snapshot ) ? $snapshot : null );
	}

	private static function handle( int $event_id, string $op, int $attempt, ?array $snapshot ): array {
		$mgr = BizCity_Scheduler_Manager::instance();
		$row = $snapshot;
		if ( null === $row ) {
			$obj = $mgr->get_event( $event_id );
			$row = $obj ? (array) $obj : null;
		}
		if ( ! is_array( $row ) ) {
			return array( 'status' => 'skipped', 'skip_reason' => 'not_found' );
		}
		if ( BizCity_Scheduler_Manager::DISPATCHER_CELL !== (string) ( $row['dispatcher'] ?? '' ) ) {
			return array( 'status' => 'skipped', 'skip_reason' => 'not_cell' );
		}
		$meta = self::meta( $row );
		$live = null === $snapshot;
		if ( 'upsert' === $op && ! preg_match( '/^[a-f0-9]{64}$/', (string) ( $row['owner_agent_hash'] ?? '' ) ) ) {
			return self::settle( $event_id, $live, array( 'cell_state' => 'pending_owner' ), array( 'status' => 'pending_owner', 'skip_reason' => 'no_owner_agent' ) );
		}
		if ( 'cancel' === $op && '' === (string) ( $row['cell_job_id'] ?? '' ) && ! isset( $meta['cell_push']['rev'] ) ) {
			return array( 'status' => 'skipped', 'skip_reason' => 'never_sent' );
		}

		// One rev per change; a retry re-sends the SAME rev (same idem ⇒ the cell answers duplicate, never a second job).
		$rev = (int) ( $meta['cell_push']['rev'] ?? 0 );
		if ( 0 === $attempt ) {
			$rev++;
			if ( null !== $snapshot ) {
				$snapshot['metadata'] = self::with_push( $meta, array( 'rev' => $rev ) );
			}
		}
		$rev = max( 1, $rev );

		$readers  = self::readers();
		$target   = BizCity_Scheduler_Hub_Pipe::target( $readers );
		$key_id   = BizCity_Scheduler_Hub_Pipe::key_id( $readers );
		$instance = BizCity_Scheduler_Hub_Pipe::client_instance( $readers );
		if ( null === $target || $key_id <= 0 || '' === $instance ) {
			return self::retry_or_give_up( $event_id, $live, $op, $attempt, $rev, $snapshot, 0, 'hub_not_ready' );
		}

		$letter = self::letter( $row, $meta, $key_id, $instance, $op, $rev );
		BizCity_Scheduler_Hub_Pipe::ensure_registered( $target, $instance, $readers );
		$res  = BizCity_Scheduler_Hub_Pipe::post_letter( $target, $letter, $readers );
		$code = (int) $res['status'];
		$ack  = is_array( $res['body'] ) ? $res['body'] : array();
		$ack_status = (string) ( $ack['status'] ?? '' );

		if ( $code >= 200 && $code < 300 && in_array( $ack_status, array( 'accepted', 'duplicate' ), true ) ) {
			$job_id = substr( preg_replace( '/[^A-Za-z0-9_-]/', '', (string) ( $ack['job_id'] ?? '' ) ), 0, 64 );
			$cols   = array( 'cell_state' => 'synced' );
			if ( '' !== $job_id ) {
				$cols['cell_job_id'] = $job_id;
			}
			$out = self::settle( $event_id, $live, $cols, array( 'status' => $ack_status, 'rev' => $rev, 'http' => $code, 'next_run_at' => (string) ( $ack['next_run_at'] ?? '' ) ) )
				+ array( 'job_id' => $job_id, 'letter' => $letter );
			// [2026-10-09 09:20 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.95-S95-W4 — the cell now owns this row (also after a wp-cron retry):
			// Automation cancels the old site-clock rows of a workflow anchor only from here, never before the ack.
			if ( function_exists( 'do_action' ) ) {
				do_action( self::SETTLED_HOOK, $event_id, $op, $ack_status );
			}
			return $out;
		}
		$err = substr( sanitize_key( (string) ( $ack['code'] ?? $ack['error'] ?? 'http_' . $code ) ), 0, 60 );
		if ( 409 === $code && 'stale_rev' === $err ) {
			return array( 'status' => 'stale_rev', 'http' => $code, 'letter' => $letter );
		}
		if ( 409 === $code && 'owner_agent_not_found' === $err ) {
			return self::settle( $event_id, $live, array( 'cell_state' => 'pending_owner' ), array( 'status' => 'pending_owner', 'rev' => $rev, 'http' => $code, 'skip_reason' => $err ) ) + array( 'letter' => $letter );
		}
		if ( 403 === $code ) {
			BizCity_Scheduler_Hub_Pipe::forget_registration(); // key rotated / channel not registered: register again on the retry
		}
		// 409 in_progress = the cell is still processing this same letter: send it again later (same rev ⇒ duplicate at worst).
		if ( 0 === $code || 403 === $code || 429 === $code || $code >= 500 || ( 409 === $code && 'in_progress' === $err ) ) {
			return self::retry_or_give_up( $event_id, $live, $op, $attempt, $rev, $snapshot, $code, 0 === $code ? 'hub_unavailable' : $err ) + array( 'letter' => $letter );
		}
		return self::settle( $event_id, $live, array( 'cell_state' => 'rejected' ), array( 'status' => 'rejected', 'rev' => $rev, 'http' => $code, 'skip_reason' => $err ) ) + array( 'letter' => $letter );
	}

	/**
	 * The frozen `job_upsert` envelope (fixture channel-inbound.job-upsert.json): no identity key at any depth.
	 */
	public static function letter( array $row, array $meta, int $key_id, string $instance, string $op, int $rev ): array {
		$event_id = (int) ( $row['id'] ?? 0 );
		$blog     = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 1;
		$channel  = array( 'platform' => BizCity_Scheduler_Hub_Pipe::PLATFORM, 'channel_ref' => $instance );
		$job      = array( 'op' => $op, 'site_event_id' => $event_id, 'rev' => $rev );
		if ( 'upsert' === $op ) {
			$number = (string) ( $row['owner_number_ref'] ?? '' );
			if ( '' !== $number ) {
				$job['number_ref'] = $number;
			}
			$kind = 'agent' === (string) ( $meta['cell_kind'] ?? '' ) ? 'agent' : 'message';
			$job += array(
				'name'            => self::cut( (string) ( $row['title'] ?? '' ), 180 ),
				'kind'            => $kind,
				'text'            => self::cut( self::text( $row, $kind ), 2000 ),
				'schedule'        => self::schedule( $row, $meta ),
				'event_start_utc' => self::utc( (string) ( $row['start_at'] ?? '' ) ),
			);
		}
		// [2026-10-09 09:20 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.95-S95-W1 — channel-inbound@1.3.0 (fixture job-upsert.workflow): dòng neo
		// workflow mang workflow_id + kịch bản đăng duy nhất; cell chạy lượt chủ như job_due khi tới giờ.
		$workflow_id = 'cron' === (string) ( $meta['recurrence'] ?? '' ) && 'cell' === (string) ( $meta['clock'] ?? '' ) ? (int) ( $meta['workflow_id'] ?? 0 ) : 0;
		if ( 'upsert' === $op && $workflow_id > 0 ) {
			$job['kind']        = 'agent';
			$job['workflow_id'] = $workflow_id;
			$pub = strtolower( (string) ( $meta['publish_scenario'] ?? '' ) );
			if ( preg_match( '/^[a-z0-9][a-z0-9_-]{0,79}$/', $pub ) ) {
				$job['publish_scenario'] = $pub;
			}
		}
		$letter = array(
			'contract'   => $workflow_id > 0 ? self::CONTRACT_WORKFLOW : self::CONTRACT,
			'kind'       => self::KIND,
			'trace_id'   => 'schj.' . $blog . '.' . $event_id . '.' . $rev,
			'idem'       => 'schj_' . $blog . '_' . $event_id . '_' . $rev,
			'thread_key' => 'sch:' . $event_id,
			'tenant'     => array( 'key_id' => $key_id ),
			'channel'    => $channel,
			'contact'    => $channel + array( 'platform_uid' => strtolower( (string) ( $row['owner_agent_hash'] ?? '' ) ) ),
			'job'        => self::scrub( $job ),
		);
		return $letter;
	}

	/**
	 * When the cell fires: start_at − reminder_min, in UTC; with "Lặp lại" a cron in the site's timezone.
	 *
	 * @return array{kind:string,run_at_utc?:string,expr?:string,timezone?:string}
	 */
	public static function schedule( array $row, array $meta ): array {
		$tz    = self::timezone();
		// [2026-10-09 09:20 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.95-S95-W4 — dòng neo workflow (D95-21): cron của chính workflow, giờ site.
		$expr = trim( (string) ( $meta['cron_expr'] ?? '' ) );
		if ( 'cron' === (string) ( $meta['recurrence'] ?? '' ) && preg_match( '/^[0-9*\/,\-]+(\s+[0-9*\/,\-]+){4}$/', $expr ) ) {
			return array( 'kind' => 'cron', 'expr' => $expr, 'timezone' => $tz->getName() );
		}
		$start = self::local_dt( (string) ( $row['start_at'] ?? '' ), $tz );
		$fire  = $start ? $start->modify( '-' . max( 0, (int) ( $row['reminder_min'] ?? 0 ) ) . ' minutes' ) : null;
		$rec   = (string) ( $meta['recurrence'] ?? '' );
		if ( $fire && in_array( $rec, self::RECURRENCES, true ) ) {
			$dow = 'daily' === $rec ? '*' : ( 'weekdays' === $rec ? '1-5' : $fire->format( 'w' ) );
			return array( 'kind' => 'cron', 'expr' => (int) $fire->format( 'i' ) . ' ' . (int) $fire->format( 'G' ) . ' * * ' . $dow, 'timezone' => $tz->getName() );
		}
		$utc = $fire ? $fire->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d\TH:i:s\Z' ) : '';
		return array( 'kind' => 'once', 'run_at_utc' => $utc );
	}

	/** kind message ⇒ a ready-to-send reminder line; kind agent ⇒ the note is the instruction (no LLM on the site). */
	public static function text( array $row, string $kind ): string {
		$title = trim( (string) ( $row['title'] ?? '' ) );
		$note  = trim( wp_strip_all_tags( (string) ( $row['description'] ?? '' ) ) );
		if ( 'agent' === $kind ) {
			return '' !== $note ? $note : $title;
		}
		$start = self::local_dt( (string) ( $row['start_at'] ?? '' ), self::timezone() );
		$when  = $start ? ( ! empty( $row['all_day'] ) ? ' ngày ' . $start->format( 'd/m' ) : ' lúc ' . $start->format( 'H:i d/m' ) ) : '';
		return '⏰ Nhắc lịch: ' . $title . $when . '.' . ( '' !== $note ? "\nGhi chú: " . $note : '' );
	}

	// ─── Helpers ─────────────────────────────────────────────────────────

	/** Why there is no owner agent: no zalo-hub number · the user owns a number whose owner UID is unknown · not on any number. */
	private static function missing_reason( int $user_id ): string {
		$accounts = BizCity_Scheduler_Owner_Agent::accounts();
		if ( array() === $accounts ) {
			return 'no_number';
		}
		foreach ( $accounts as $acc ) {
			if ( is_array( $acc ) && $user_id > 0 && (int) ( $acc['owner_user_id'] ?? 0 ) === $user_id ) {
				return 'no_owner_uid';
			}
		}
		return 'not_principal';
	}

	private static function retry_or_give_up( int $event_id, bool $live, string $op, int $attempt, int $rev, ?array $snapshot, int $http, string $reason ): array {
		$next = $attempt + 1;
		if ( $next >= self::MAX_ATTEMPTS ) {
			return self::settle( $event_id, $live, array( 'cell_state' => 'rejected' ), array( 'status' => 'rejected', 'rev' => $rev, 'http' => $http, 'skip_reason' => 'gave_up_' . $reason, 'attempts' => $next ) );
		}
		$delay = min( 3600, 60 * ( 2 ** $attempt ) ); // 1, 2, 4, 8, 16 min
		$args  = array( $event_id, $op, $next, $snapshot );
		if ( isset( self::$readers['schedule_retry'] ) ) {
			call_user_func( self::$readers['schedule_retry'], BizCity_Scheduler_Hub_Pipe::now( self::readers() ) + $delay, $args );
		} elseif ( function_exists( 'wp_schedule_single_event' ) ) {
			wp_schedule_single_event( BizCity_Scheduler_Hub_Pipe::now( self::readers() ) + $delay, self::RETRY_HOOK, $args );
		}
		return self::settle( $event_id, $live, array( 'cell_state' => 'retrying' ), array( 'status' => 'retrying', 'rev' => $rev, 'http' => $http, 'skip_reason' => $reason, 'attempts' => $next ) );
	}

	/**
	 * Write the outcome on the row (no hook): columns + metadata.cell_push merged. A deleted row (cancel snapshot) has nowhere
	 * to write — the outcome is only returned.
	 */
	private static function settle( int $event_id, bool $live, array $cols, array $push ): array {
		$push['at'] = gmdate( 'c', BizCity_Scheduler_Hub_Pipe::now( self::readers() ) );
		if ( ! $live || $event_id <= 0 ) {
			return $push + ( isset( $cols['cell_state'] ) ? array( 'cell_state' => $cols['cell_state'] ) : array() );
		}
		global $wpdb;
		$mgr  = BizCity_Scheduler_Manager::instance();
		$obj  = $mgr->get_event( $event_id );
		$meta = $obj ? self::meta( (array) $obj ) : array();
		$data = BizCity_Scheduler_Manager::clean_dispatch_fields( $cols );
		$data['metadata'] = wp_json_encode( self::with_push( $meta, $push ) );
		$wpdb->update( $mgr->get_table(), $data, array( 'id' => $event_id ) );
		return $push + ( isset( $cols['cell_state'] ) ? array( 'cell_state' => $cols['cell_state'] ) : array() );
	}

	private static function with_push( array $meta, array $push ): array {
		$prev = isset( $meta['cell_push'] ) && is_array( $meta['cell_push'] ) ? $meta['cell_push'] : array();
		$meta['cell_push'] = array_merge( $prev, $push );
		return $meta;
	}

	private static function meta( array $row ): array {
		$m = $row['metadata'] ?? null;
		if ( is_array( $m ) ) {
			return $m;
		}
		$d = is_string( $m ) && '' !== $m ? json_decode( $m, true ) : null;
		return is_array( $d ) ? $d : array();
	}

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

	private static function cut( string $s, int $max ): string {
		return function_exists( 'mb_substr' ) ? mb_substr( $s, 0, $max ) : substr( $s, 0, $max );
	}

	private static function timezone(): \DateTimeZone {
		if ( isset( self::$readers['timezone'] ) ) {
			return new \DateTimeZone( (string) call_user_func( self::$readers['timezone'] ) );
		}
		if ( function_exists( 'wp_timezone' ) ) {
			return wp_timezone();
		}
		return new \DateTimeZone( 'Asia/Ho_Chi_Minh' );
	}

	/** The scheduler stores site wall-clock (`current_time('mysql')`). */
	private static function local_dt( string $mysql, \DateTimeZone $tz ): ?\DateTimeImmutable {
		if ( '' === trim( $mysql ) ) {
			return null;
		}
		try {
			return new \DateTimeImmutable( $mysql, $tz );
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	private static function utc( string $mysql ): string {
		$dt = self::local_dt( $mysql, self::timezone() );
		return $dt ? $dt->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d\TH:i:s\Z' ) : '';
	}

	private static function readers(): array {
		return self::$readers;
	}

	private static function can_manage(): bool {
		if ( isset( self::$readers['can_manage'] ) ) {
			return (bool) call_user_func( self::$readers['can_manage'] );
		}
		if ( class_exists( 'BizCity_Network_Admin_Capability' ) ) {
			return (bool) BizCity_Network_Admin_Capability::can_manage();
		}
		return function_exists( 'current_user_can' ) && current_user_can( 'manage_options' );
	}

	/** The Channel Gateway page that hosts the four-step setup (R-SETUP-4). */
	private static function setup_url(): string {
		if ( isset( self::$readers['setup_url'] ) ) {
			return (string) call_user_func( self::$readers['setup_url'] );
		}
		$url = function_exists( 'admin_url' ) ? admin_url( 'admin.php?page=bizchat-gateway-spa' ) : '';
		return (string) apply_filters( 'bizcity_scheduler_four_step_url', $url );
	}

	/** Bot Studio › Tài khoản: where "UID chủ tài khoản" is filled in / verified. */
	private static function fix_url(): string {
		if ( isset( self::$readers['fix_url'] ) ) {
			return (string) call_user_func( self::$readers['fix_url'] );
		}
		$url = function_exists( 'admin_url' ) ? admin_url( 'admin.php?page=bizchat-gateway-spa#/gateway/accounts' ) : '';
		return (string) apply_filters( 'bizcity_scheduler_owner_uid_fix_url', $url );
	}
}
