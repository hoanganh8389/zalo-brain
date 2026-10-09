<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Scheduler
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 */

/**
 * BizCity Scheduler — Run Ledger (PHASE-0.91 AS-0, doc 102 §12.3 / doc 103 §3, §3a).
 *
 * The ONE place that writes the lifecycle of an automation row in `bizcity_crm_events`:
 *
 *   automation_workflow (cron occurrence, row made by the add-on Schedule_Manager)
 *   automation_run      (scenario run the cell asked for, row made by open())
 *
 *   active → running → done | failed        (done/failed go through update_event ⇒ the existing
 *   active → missed                          completed/failed hooks fire exactly once)
 *
 * `running` and `missed` fire NO status hook. `bizcity_scheduler_run_started` fires once per running().
 * Metadata is always decode → merge → encode (R-SCH-REPLY); the ledger owns the keys
 * run_id, progress, trace, reply_text, artifacts, log_url, error, done_at and never touches the others.
 * No new table.
 *
 * Shared contract (frozen 2026-10-05): RUN-LEDGER-API.md — the Automation add-on calls this class
 * behind class_exists( 'BizCity_Scheduler_Run_Ledger' ).
 *
 * // [2026-10-05 04:23 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.91-AS-0
 *
 * @since 2026-10-05
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

if ( class_exists( 'BizCity_Scheduler_Run_Ledger' ) ) {
	return;
}

final class BizCity_Scheduler_Run_Ledger {

	const TYPE_WORKFLOW = 'automation_workflow'; // cron scenario occurrence (row created by Schedule_Manager)
	const TYPE_RUN      = 'automation_run';      // scenario run requested by the cell (row created by open())
	const TRACE_MAX     = 40;

	/** Entries always kept at the head of a capped trace. */
	const TRACE_HEAD = 10;

	/** Statuses after which the ledger never writes again. */
	const TERMINAL = array( 'done', 'failed', 'cancelled', 'missed' );

	/** Seconds a writer waits for the row lock before writing best-effort (C-1.3). */
	const LOCK_WAIT = 5;

	/** @var array<int,int> event id => nesting depth of locked() in this request (GET_LOCK is per connection). */
	private static $lock_depth = array();

	/* ================================================================
	 *  Public API (RUN-LEDGER-API.md)
	 * ================================================================ */

	/**
	 * Create an automation_run row for a cell-requested run. Born `running` + reminder_sent = 1 ⇒
	 * claim_due_reminders can never pick it up. trace[0] = received(source).
	 *
	 * @param array $a user_id (required), title, workflow_id, scenario_ref, source, report_back (default true),
	 *                 notify (default false — the cell delivers), cell {account_id,user_hash,role,surface,turn_id}.
	 * @return int|WP_Error event id.
	 */
	public static function open( array $a ) {
		// [2026-10-05 04:23 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.91-AS-0 — S7: a cell-requested run gets its own Lịch row.
		$user_id = isset( $a['user_id'] ) ? (int) $a['user_id'] : 0;
		if ( $user_id <= 0 ) {
			return new WP_Error( 'run_ledger_user_required', 'Run Ledger: user_id is required.' );
		}
		$mgr = self::mgr();
		if ( ! $mgr ) {
			return new WP_Error( 'run_ledger_unavailable', 'Run Ledger: scheduler manager unavailable.' );
		}

		$source = isset( $a['source'] ) ? sanitize_key( (string) $a['source'] ) : 'cell';
		if ( '' === $source ) {
			$source = 'cell';
		}
		$title = isset( $a['title'] ) ? trim( (string) $a['title'] ) : '';
		if ( '' === $title ) {
			$title = 'Automation run';
		}

		$meta = array(
			'source'      => $source,
			'report_back' => array_key_exists( 'report_back', $a ) ? (bool) $a['report_back'] : true,
			'notify'      => self::normalize_notify( array_key_exists( 'notify', $a ) ? $a['notify'] : false ),
		);
		if ( ! empty( $a['workflow_id'] ) ) {
			$meta['workflow_id'] = (int) $a['workflow_id'];
		}
		if ( isset( $a['scenario_ref'] ) && '' !== (string) $a['scenario_ref'] ) {
			$meta['scenario_ref'] = substr( preg_replace( '/[^A-Za-z0-9_\-:.]/', '', (string) $a['scenario_ref'] ), 0, 191 );
		}
		if ( isset( $a['cell'] ) && is_array( $a['cell'] ) ) {
			$cell = array();
			foreach ( array( 'account_id', 'user_hash', 'role', 'surface', 'turn_id' ) as $k ) {
				if ( isset( $a['cell'][ $k ] ) && is_scalar( $a['cell'][ $k ] ) ) {
					$cell[ $k ] = substr( (string) $a['cell'][ $k ], 0, 191 );
				}
			}
			$meta['_cell'] = $cell;
		}
		$meta['trace'] = array( self::entry( 'received', array( 'source' => $source ) ) );

		$id = $mgr->create_event( array(
			'user_id'       => $user_id,
			'title'         => $title,
			'start_at'      => current_time( 'mysql' ),
			'reminder_min'  => 0,
			'reminder_sent' => 1,
			'status'        => 'running',
			'source'        => 'cell' === $source ? 'cell' : 'workflow',
			'event_type'    => self::TYPE_RUN,
			'metadata'      => $meta,
		) );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		if ( (int) $id <= 0 ) {
			return new WP_Error( 'run_ledger_insert', 'Run Ledger: row was not created.' );
		}
		return (int) $id;
	}

	/** Trace milestone "received" on an existing row (cron rows: called from on_scheduler_fire). */
	public static function received( int $event_id, string $source ): bool {
		// [2026-10-05 04:23 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.91-AS-0 — §3a tier 1 "Đã nhận".
		// [2026-10-05 05:45 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.91-C-1.3 — read → merge → write under the row lock.
		return (bool) self::locked( $event_id, static function () use ( $event_id, $source ) {
			$row = self::load_live( $event_id );
			if ( ! $row ) {
				return false;
			}
			$source = sanitize_key( $source );
			$meta   = self::decode( $row );
			$meta['trace'] = self::push_trace( $meta, self::entry( 'received', array( 'source' => '' !== $source ? $source : 'scheduler' ) ) );
			return self::write( $event_id, array( 'metadata' => $meta ) );
		} );
	}

	/**
	 * status = running, run_id, progress {ok:0,total,failed_step:null,failed_block:null}, trace += started.
	 * No scheduler status hook; fires bizcity_scheduler_run_started( $event_id, $row_array ) once written.
	 */
	public static function running( int $event_id, string $run_id, int $total ): bool {
		// [2026-10-05 04:23 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.91-AS-0 — S5 "Lịch nói thật": the row says it is running.
		$run_id = self::clean_run_id( $run_id );
		$total  = max( 0, $total );
		// [2026-10-05 05:45 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.91-C-1.3 — the write is locked; run_started fires after the lock is released.
		$written = self::locked( $event_id, static function () use ( $event_id, $run_id, $total ) {
			$row = self::load_live( $event_id );
			if ( ! $row || '' === $run_id ) {
				return false;
			}
			$meta = self::decode( $row );
			$meta['run_id']   = $run_id;
			$meta['progress'] = array(
				'ok'           => 0,
				'total'        => $total,
				'failed_step'  => null,
				'failed_block' => null,
			);
			$meta['trace'] = self::push_trace( $meta, self::entry( 'started', array( 'run_id' => $run_id, 'total' => $total ) ) );
			return self::write( $event_id, array( 'status' => 'running', 'metadata' => $meta ) );
		} );
		if ( ! $written ) {
			return false;
		}
		/**
		 * A ledger row started running (not a status hook — Completion Notifier sends message ① from it).
		 *
		 * @param int   $event_id
		 * @param array $row Decoded row (metadata as array).
		 */
		do_action( 'bizcity_scheduler_run_started', $event_id, self::get( $event_id ) );
		return true;
	}

	/**
	 * Append one finished step. $log: step (1-based), node_id, block_id, label?, status ok|fail|skip, error.
	 * Non-terminal step statuses are ignored (false).
	 */
	public static function step( int $event_id, array $log ): bool {
		// [2026-10-05 04:23 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.91-AS-0 — §3a tier 1 "Bước k/n".
		$status = isset( $log['status'] ) ? (string) $log['status'] : '';
		if ( ! in_array( $status, array( 'ok', 'fail', 'skip' ), true ) ) {
			return false;
		}
		// [2026-10-05 05:45 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.91-C-1.3 — two processes appending steps at once must both land in trace[].
		return (bool) self::locked( $event_id, static function () use ( $event_id, $log, $status ) {
			return self::step_locked( $event_id, $log, $status );
		} );
	}

	/** step() body, called with the row lock held. */
	private static function step_locked( int $event_id, array $log, string $status ): bool {
		$row = self::load_live( $event_id );
		if ( ! $row ) {
			return false;
		}
		$meta     = self::decode( $row );
		$progress = self::progress_of( $meta );
		$step     = isset( $log['step'] ) ? max( 0, (int) $log['step'] ) : 0;
		$block    = isset( $log['block_id'] ) ? self::short( $log['block_id'], 64 ) : '';

		$fields = array(
			'step'     => $step,
			'node_id'  => isset( $log['node_id'] ) ? self::short( $log['node_id'], 64 ) : '',
			'block_id' => $block,
			'status'   => $status,
		);
		if ( isset( $log['label'] ) && '' !== (string) $log['label'] ) {
			$fields['label'] = self::short( $log['label'], 120 );
		}
		if ( isset( $log['error'] ) && '' !== (string) $log['error'] ) {
			$fields['error'] = self::short( $log['error'], 300 );
		}

		if ( 'ok' === $status ) {
			$progress['ok'] = (int) $progress['ok'] + 1;
			if ( (int) $progress['total'] > 0 && $progress['ok'] > (int) $progress['total'] ) {
				$progress['ok'] = (int) $progress['total'];
			}
		} elseif ( 'fail' === $status ) {
			$progress['failed_step']  = $step;
			$progress['failed_block'] = '' !== $block ? $block : null;
		}
		$meta['progress'] = $progress;
		$meta['trace']    = self::push_trace( $meta, self::entry( 'step', $fields ) );
		return self::write( $event_id, array( 'metadata' => $meta ) );
	}

	/**
	 * done|failed through update_event ⇒ bizcity_scheduler_event_completed / _failed fire exactly once.
	 * $extra: progress (merged), reply_text, artifacts [{kind,title,url}], log_url, error.
	 * Idempotent: a done/failed/cancelled/missed row is not written again (false).
	 */
	public static function finish( int $event_id, string $status, array $extra = array() ): bool {
		// [2026-10-05 04:23 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.91-AS-0 — S5/S8: one terminal write, the existing hooks do the rest.
		if ( ! in_array( $status, array( 'done', 'failed' ), true ) ) {
			return false;
		}
		// [2026-10-05 05:45 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.91-C-1.3 — the terminal write is locked too (a late step() cannot overwrite it).
		return (bool) self::locked( $event_id, static function () use ( $event_id, $status, $extra ) {
			return self::finish_locked( $event_id, $status, $extra );
		} );
	}

	/** finish() body, called with the row lock held. */
	private static function finish_locked( int $event_id, string $status, array $extra ): bool {
		$row = self::load_live( $event_id );
		if ( ! $row ) {
			return false;
		}
		$meta     = self::decode( $row );
		$progress = self::progress_of( $meta );
		if ( isset( $extra['progress'] ) && is_array( $extra['progress'] ) ) {
			foreach ( $extra['progress'] as $k => $v ) {
				$k = (string) $k;
				if ( 'failed_block' === $k ) {
					$progress[ $k ] = ( null === $v || '' === (string) $v ) ? null : self::short( $v, 64 );
				} elseif ( 'failed_step' === $k ) {
					$progress[ $k ] = null === $v ? null : (int) $v;
				} elseif ( is_scalar( $v ) || null === $v ) {
					$progress[ $k ] = is_numeric( $v ) ? (int) $v : $v;
				}
			}
		}
		$meta['progress'] = $progress;

		if ( isset( $extra['reply_text'] ) && '' !== trim( (string) $extra['reply_text'] ) ) {
			$meta['reply_text'] = self::short( trim( (string) $extra['reply_text'] ), 4000 );
		}
		// [2026-10-06 11:24 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.92 S92-LLM-5 — a wake-up for the cell (bizcity-automation action.ask_cell): the instruction it will act on.
		if ( isset( $extra['instruction'] ) && '' !== trim( (string) $extra['instruction'] ) ) {
			$meta['instruction'] = self::short( trim( (string) $extra['instruction'] ), 2000 );
			$pub = strtolower( (string) ( $extra['publish_scenario'] ?? '' ) );
			if ( preg_match( '/^[a-z0-9_]{3,40}$/', $pub ) ) {
				$meta['publish_scenario'] = $pub;
			}
		}
		// [2026-10-06 10:44 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.92 D92-11 — optional recipient of the answer (WP user id); absent ⇒ the requester / the row owner.
		if ( isset( $extra['recipient_user_id'] ) && (int) $extra['recipient_user_id'] > 0 ) {
			$meta['recipient_user_id'] = (int) $extra['recipient_user_id'];
		}
		// [2026-10-06 09:57 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.92-S92-CL-9 — optional customer recipient of an event run (bizcity-automation notify_contact_via_cell); values only.
		if ( isset( $extra['deliver_to'] ) && is_array( $extra['deliver_to'] ) ) {
			$to = array();
			foreach ( array( 'platform' => 16, 'platform_uid' => 64, 'channel_ref' => 64 ) as $k => $max ) {
				$v = substr( (string) preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) ( $extra['deliver_to'][ $k ] ?? '' ) ), 0, $max );
				if ( '' !== $v ) {
					$to[ $k ] = $v;
				}
			}
			if ( isset( $to['platform'], $to['platform_uid'] ) ) {
				$meta['deliver_to'] = $to;
			}
		}
		if ( isset( $extra['artifacts'] ) && is_array( $extra['artifacts'] ) ) {
			$meta['artifacts'] = self::clean_artifacts( $extra['artifacts'] );
		}
		if ( isset( $extra['log_url'] ) && '' !== (string) $extra['log_url'] ) {
			$meta['log_url'] = self::clean_url( $extra['log_url'] );
		}
		if ( isset( $extra['error'] ) && '' !== (string) $extra['error'] ) {
			$meta['error'] = self::short( $extra['error'], 500 );
		}
		$meta['done_at'] = current_time( 'mysql' );

		$fin = array( 'status' => $status );
		if ( ! empty( $meta['error'] ) && 'failed' === $status ) {
			$fin['error'] = $meta['error'];
		}
		$meta['trace'] = self::push_trace( $meta, self::entry( 'finished', $fin ) );

		// update_event compares old/new status, so the completed/failed hook fires once for this transition.
		return self::write( $event_id, array( 'status' => $status, 'metadata' => $meta ) );
	}

	/** status = missed (no status hook). Only a row still waiting (`active`) can be missed. */
	public static function missed( int $event_id, string $reason = 'late' ): bool {
		// [2026-10-05 04:23 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.91-AS-0 — S6: too late ⇒ missed, never auto catch-up.
		// [2026-10-05 05:45 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.91-C-1.3 — locked like every other write.
		return (bool) self::locked( $event_id, static function () use ( $event_id, $reason ) {
			$row = self::load_live( $event_id );
			if ( ! $row || 'active' !== (string) ( $row['status'] ?? '' ) ) {
				return false;
			}
			$reason = sanitize_key( $reason );
			if ( '' === $reason ) {
				$reason = 'late';
			}
			$meta          = self::decode( $row );
			$meta['error'] = 'missed:' . $reason;
			$meta['trace'] = self::push_trace( $meta, self::entry( 'missed', array( 'reason' => $reason ) ) );
			return self::write( $event_id, array( 'status' => 'missed', 'metadata' => $meta ) );
		} );
	}

	/**
	 * Run $fn with the row's named MySQL lock held (C-1.3). Reentrant inside one request (depth counter; MySQL
	 * GET_LOCK is per connection anyway). A lock that times out is logged and the write goes ahead best-effort —
	 * the same as before this lock existed — rather than dropping a trace entry silently. Other writers of an
	 * automation row's metadata (Scheduler_Report_Back) use it too.
	 *
	 * @return mixed whatever $fn returns.
	 */
	public static function locked( int $event_id, callable $fn ) {
		// [2026-10-05 05:45 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.91-C-1.3 — per-row lock around read → merge → write.
		global $wpdb;
		$depth = self::$lock_depth[ $event_id ] ?? 0;
		$name  = '';
		$got   = false;
		if ( 0 === $depth && $event_id > 0 && is_object( $wpdb ) && method_exists( $wpdb, 'get_var' ) && method_exists( $wpdb, 'prepare' ) ) {
			$mgr  = self::mgr();
			$name = 'bzc_rl_' . md5( ( $mgr ? $mgr->get_table() : 'crm_events' ) . ':' . $event_id );
			$got  = '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $name, self::LOCK_WAIT ) );
			if ( ! $got ) {
				error_log( '[scheduler][run-ledger] row lock timeout on event ' . $event_id . ', writing best-effort' );
			}
		}
		self::$lock_depth[ $event_id ] = $depth + 1;
		try {
			return $fn();
		} finally {
			if ( 0 === $depth ) {
				unset( self::$lock_depth[ $event_id ] );
			} else {
				self::$lock_depth[ $event_id ] = $depth;
			}
			if ( $got ) {
				$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
			}
		}
	}

	/** event_id whose metadata.run_id = $run_id, 0 if none. */
	public static function event_for_run( string $run_id ): int {
		// [2026-10-05 04:23 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.91-AS-0 — reverse lookup for the add-on listeners.
		$run_id = self::clean_run_id( $run_id );
		$mgr    = self::mgr();
		if ( '' === $run_id || ! $mgr || ! $mgr->is_ready() ) {
			return 0;
		}
		global $wpdb;
		$needle = '"run_id":' . wp_json_encode( $run_id );
		$rows   = $wpdb->get_results( $wpdb->prepare(
			'SELECT id, metadata FROM ' . $mgr->get_table() . ' WHERE event_type IN (%s, %s) AND metadata LIKE %s ORDER BY id DESC LIMIT 5',
			self::TYPE_WORKFLOW,
			self::TYPE_RUN,
			'%' . $wpdb->esc_like( $needle ) . '%'
		), ARRAY_A );
		foreach ( (array) $rows as $r ) {
			$meta = self::decode( (array) $r );
			if ( isset( $meta['run_id'] ) && (string) $meta['run_id'] === $run_id ) {
				return (int) $r['id'];
			}
		}
		return 0;
	}

	/** Decoded row (metadata as array) or null. */
	public static function get( int $event_id ) {
		$mgr = self::mgr();
		if ( $event_id <= 0 || ! $mgr ) {
			return null;
		}
		$row = $mgr->get_event( $event_id );
		if ( ! $row ) {
			return null;
		}
		$row             = (array) $row;
		$row['metadata'] = self::decode( $row );
		return $row;
	}

	/* ================================================================
	 *  Helpers
	 * ================================================================ */

	private static function mgr() {
		return class_exists( 'BizCity_Scheduler_Manager' ) && method_exists( 'BizCity_Scheduler_Manager', 'instance' )
			? BizCity_Scheduler_Manager::instance()
			: null;
	}

	/** Row of an automation type that is not terminal yet, else null. */
	private static function load_live( int $event_id ) {
		$row = self::get( $event_id );
		if ( ! $row ) {
			return null;
		}
		if ( ! in_array( (string) ( $row['event_type'] ?? '' ), array( self::TYPE_WORKFLOW, self::TYPE_RUN ), true ) ) {
			return null; // the ledger only owns automation rows.
		}
		if ( in_array( (string) ( $row['status'] ?? '' ), self::TERMINAL, true ) ) {
			return null;
		}
		return $row;
	}

	private static function write( int $event_id, array $fields ): bool {
		$mgr = self::mgr();
		if ( ! $mgr ) {
			return false;
		}
		return true === $mgr->update_event( $event_id, $fields );
	}

	private static function decode( array $row ): array {
		$raw = $row['metadata'] ?? '';
		if ( is_array( $raw ) ) {
			return $raw;
		}
		if ( is_string( $raw ) && '' !== $raw ) {
			$d = json_decode( $raw, true );
			if ( is_array( $d ) ) {
				return $d;
			}
		}
		return array();
	}

	private static function progress_of( array $meta ): array {
		$p = isset( $meta['progress'] ) && is_array( $meta['progress'] ) ? $meta['progress'] : array();
		return array_merge( array( 'ok' => 0, 'total' => 0, 'failed_step' => null, 'failed_block' => null ), $p );
	}

	private static function entry( string $milestone, array $fields ): array {
		return array_merge( array( 'at' => current_time( 'mysql' ), 'm' => $milestone ), $fields );
	}

	/**
	 * Append + cap at TRACE_MAX: first TRACE_HEAD entries, one {"omitted":N} marker, then the most recent.
	 */
	private static function push_trace( array $meta, array $entry ): array {
		$entries = array();
		$omitted = 0;
		foreach ( ( isset( $meta['trace'] ) && is_array( $meta['trace'] ) ) ? $meta['trace'] : array() as $e ) {
			if ( is_array( $e ) && isset( $e['omitted'] ) && ! isset( $e['m'] ) ) {
				$omitted += (int) $e['omitted'];
				continue;
			}
			$entries[] = $e;
		}
		$entries[] = $entry;

		$room = self::TRACE_MAX - 1; // one slot for the marker once anything is dropped.
		if ( 0 === $omitted && count( $entries ) <= self::TRACE_MAX ) {
			return $entries;
		}
		if ( count( $entries ) > $room ) {
			$omitted += count( $entries ) - $room;
			$entries  = array_merge(
				array_slice( $entries, 0, self::TRACE_HEAD ),
				array_slice( $entries, -( $room - self::TRACE_HEAD ) )
			);
		}
		return array_merge(
			array_slice( $entries, 0, self::TRACE_HEAD ),
			array( array( 'omitted' => $omitted ) ),
			array_slice( $entries, self::TRACE_HEAD )
		);
	}

	/** Notify levels: false|'off' ⇒ false (legacy shape); true ⇒ full; array keeps target etc. */
	private static function normalize_notify( $notify ) {
		if ( false === $notify || null === $notify || 'off' === $notify ) {
			return false;
		}
		if ( true === $notify ) {
			return array( 'level' => 'full' );
		}
		if ( is_string( $notify ) ) {
			return in_array( $notify, array( 'full', 'result', 'failed' ), true ) ? array( 'level' => $notify ) : false;
		}
		if ( is_array( $notify ) ) {
			$level = isset( $notify['level'] ) ? (string) $notify['level'] : 'full';
			$notify['level'] = in_array( $level, array( 'full', 'result', 'failed', 'off' ), true ) ? $level : 'full';
			return $notify;
		}
		return false;
	}

	private static function clean_run_id( string $run_id ): string {
		return substr( preg_replace( '/[^A-Za-z0-9_\-:.]/', '', $run_id ), 0, 64 );
	}

	private static function short( $value, int $max ): string {
		$s = trim( preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string) $value ) );
		return function_exists( 'mb_substr' ) ? mb_substr( $s, 0, $max, 'UTF-8' ) : substr( $s, 0, $max );
	}

	private static function clean_url( $url ): string {
		$url = trim( (string) $url );
		if ( function_exists( 'esc_url_raw' ) ) {
			return (string) esc_url_raw( $url );
		}
		return preg_match( '#^https?://#i', $url ) ? $url : '';
	}

	private static function clean_artifacts( array $list ): array {
		$out = array();
		foreach ( $list as $a ) {
			if ( ! is_array( $a ) ) {
				continue;
			}
			$item = array(
				'kind'  => isset( $a['kind'] ) ? sanitize_key( (string) $a['kind'] ) : '',
				'title' => isset( $a['title'] ) ? self::short( $a['title'], 160 ) : '',
				'url'   => isset( $a['url'] ) ? self::clean_url( $a['url'] ) : '',
			);
			if ( '' === $item['url'] && '' === $item['title'] ) {
				continue;
			}
			$out[] = $item;
			if ( count( $out ) >= 10 ) {
				break;
			}
		}
		return $out;
	}
}
