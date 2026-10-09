<?php
/**
 * BizCity_Schedule_Sync_MCP_Service — `booking.sync_job`: the zalo-hub cell records its own scheduled jobs
 * (tool `schedule_task`) into the site calendar `bizcity_crm_events`, so the owner sees them in Lịch / CRM.
 *
 * Owner decision 2026-10-03 (CORE-REDUCTION scheduler wave): the cell OWNS chat reminders (it stores, fires and delivers
 * them); the site calendar is only the business ledger. So the row written here is a MIRROR:
 *   - event_type `cell_job`, source `cell`, one row per cell job (upsert by metadata.cell_job.id + the principal);
 *   - `reminder_sent = 1` right after insert ⇒ the PHP reminder cron never fires it (no second message);
 *   - `metadata.notify = false` ⇒ the Completion Notifier never replies "đã xong" on done/failed;
 *   - Google push skips `cell_job` (BizCity_Scheduler_Google::on_event_created).
 * Called by the cell only (system sync, never offered to the model — cell `CELL_SYSTEM_MCP_TOOLS`), with the principal
 * that created the job, through the same Hub pipe and delegation as every other cell MCP call.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\MCP
 * @since      2026-10-03 (CORE-REDUCTION scheduler, D-SCH-CELL-1)
 */

defined( 'ABSPATH' ) || exit;

// [2026-10-03 Claude Opus 5.5] CORE-REDUCTION scheduler D-SCH-CELL-1 — new file, cell job mirror into the site calendar.
final class BizCity_Schedule_Sync_MCP_Service {

	const EVENT_TYPE = 'cell_job';
	const SOURCE     = 'cell';

	/** cell job state → scheduler status */
	const STATUS = array(
		'active'    => 'active',
		'paused'    => 'draft',
		'done'      => 'done',
		'cancelled' => 'cancelled',
		'failed'    => 'failed',
	);

	/** @var object|null test seam: stands in for BizCity_Scheduler_Manager::instance() */
	public static $manager = null;

	public static function init() {
		add_action( 'bizcity_mcp_register_tools', array( __CLASS__, 'register_tools' ) );
	}

	public static function register_tools() {
		if ( ! class_exists( 'BizCity_Scheduler_Manager' ) ) {
			return;
		}
		// @mcp bizcity-mcp-standard@1 tool booking.sync_job
		BizCity_MCP_Tool_Registry::register( 'booking.sync_job', array(
			'title'          => 'Ghi lịch nhắc của bot vào lịch website',
			'description'    => 'Đồng bộ hệ thống (chỉ zalo-hub gọi): ghi/cập nhật một lịch nhắc mà bot Zalo đã đặt (schedule_task) vào lịch của người dùng để xem trên Lịch/CRM. Bot vẫn là nơi nhắc; website không gửi lại tin.',
			'input_schema'   => array( 'type' => 'object', 'required' => array( 'job_id', 'state', 'name' ), 'properties' => array(
				'job_id'      => array( 'type' => 'string', 'pattern' => '^[A-Za-z0-9_-]{1,64}$' ),
				'state'       => array( 'type' => 'string', 'enum' => array_keys( self::STATUS ) ),
				'name'        => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 180 ),
				'kind'        => array( 'type' => 'string', 'enum' => array( 'message', 'agent' ), 'default' => 'message' ),
				'schedule'    => array( 'type' => 'string', 'maxLength' => 200 ),
				'next_run_at' => array( 'type' => 'string' ),
				'last_run_at' => array( 'type' => 'string' ),
				'last_status' => array( 'type' => 'string', 'maxLength' => 32 ),
				'run_count'   => array( 'type' => 'integer', 'minimum' => 0 ),
				'number_ref'  => array( 'type' => 'string', 'maxLength' => 64 ),
				// [2026-10-06 11:58 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.92-S92-SC-7 — job handed over by the site (job_upsert): update THAT row.
				'site_event_id' => array( 'type' => 'integer', 'minimum' => 1 ),
			) ),
			'output_schema'  => BizCity_MCP_Tool_Registry::envelope_schema( array(
				'event_id' => array( 'type' => 'integer' ),
				'synced'   => array( 'type' => 'boolean' ),
				'status'   => array( 'type' => 'string' ),
			), array( 'synced' ) ),
			'read_only'      => false,
			'destructive'    => false,
			'idempotent'     => true,
			'required_scope' => 'booking.write',
			'handler'        => array( __CLASS__, 'sync' ),
			'mode'           => 'booking',
			'scopes'         => array( 'booking.write' ),
			'confirm'        => 'never',
			'llm_alias'      => 'booking_sync_job',
			'fallback_pack'  => null,
			'since'          => '0.88.6',
		) );
	}

	public static function sync( array $args, array $ctx ) {
		return BizCity_MCP_Action_Support::run_as( $ctx, static function ( $uid ) use ( $args ) {
			$job_id = (string) ( $args['job_id'] ?? '' );
			$state  = (string) ( $args['state'] ?? '' );
			$name   = trim( sanitize_text_field( (string) ( $args['name'] ?? '' ) ) );
			if ( ! preg_match( '/^[A-Za-z0-9_-]{1,64}$/', $job_id ) || ! isset( self::STATUS[ $state ] ) || '' === $name ) {
				return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::QUERY_INVALID, 'Thiếu job_id / state / name hợp lệ.', 422 );
			}
			$mgr      = self::$manager ? self::$manager : BizCity_Scheduler_Manager::instance();
			// [2026-10-06 11:58 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.92-S92-SC-7 — a job the site handed over updates the site's own row (D92-14), never a second row.
			$site_event_id = max( 0, (int) ( $args['site_event_id'] ?? 0 ) );
			$existing      = $site_event_id > 0 ? self::find_site_row( $mgr, (int) $uid, $site_event_id ) : null;
			if ( ! $existing ) {
				$existing = self::find( $mgr, (int) $uid, $job_id );
			}
			$site_row = $existing && self::EVENT_TYPE !== (string) ( $existing->event_type ?? '' );
			$status   = self::STATUS[ $state ];

			// A job cancelled before it was ever mirrored leaves nothing to record.
			if ( ! $existing && 'cancelled' === $state ) {
				return array( 'synced' => false, 'status' => $status );
			}

			$when = self::local( (string) ( $args['next_run_at'] ?? '' ) );
			if ( '' === $when ) {
				$when = self::local( (string) ( $args['last_run_at'] ?? '' ) );
			}
			if ( '' === $when ) {
				$when = $existing ? (string) $existing->start_at : current_time( 'mysql' );
			}

			$meta = array(
				'notify'   => false,
				'cell_job' => array(
					'id'          => $job_id,
					'kind'        => 'agent' === ( $args['kind'] ?? '' ) ? 'agent' : 'message',
					'schedule'    => sanitize_text_field( (string) ( $args['schedule'] ?? '' ) ),
					'state'       => $state,
					'last_run_at' => sanitize_text_field( (string) ( $args['last_run_at'] ?? '' ) ),
					'last_status' => sanitize_key( (string) ( $args['last_status'] ?? '' ) ),
					'run_count'   => max( 0, (int) ( $args['run_count'] ?? 0 ) ),
					'number_ref'  => sanitize_text_field( (string) ( $args['number_ref'] ?? '' ) ),
				),
				'inbound'  => array( 'platform' => 'ZALO', 'intent_tag' => 'reminder' ),
			);

			if ( $existing ) {
				// [2026-10-06 11:58 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.92-S92-SC-7 — decode → merge → encode: the site's own keys (cell_push,
				// recurrence, cell_kind, notes…) survive; a site row keeps its event_type/source/inbound.
				$old    = json_decode( (string) ( $existing->metadata ?? '' ), true );
				$merged = is_array( $old ) ? $old : array();
				$merged['notify']   = false;
				$merged['cell_job'] = $meta['cell_job'];
				$update = array(
					'title'       => $name,
					'status'      => $status,
					'cell_job_id' => $job_id,
					'cell_state'  => 'synced',
				);
				if ( $site_row ) {
					// The cell fires at start − reminder_min: put the reminder back to get the event time (cell wins on a move).
					$next = self::local( (string) ( $args['next_run_at'] ?? '' ) );
					if ( 'active' === $state && '' !== $next ) {
						$update['start_at'] = gmdate( 'Y-m-d H:i:s', strtotime( $next . ' UTC' ) + 60 * max( 0, (int) ( $existing->reminder_min ?? 0 ) ) );
					}
				} else {
					$merged['inbound']  = $meta['inbound'];
					$update['start_at'] = $when;
				}
				$update['metadata'] = $merged;
				$res = $mgr->update_event( (int) $existing->id, $update, (int) $uid );
				if ( is_wp_error( $res ) ) {
					return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::QUERY_INVALID, 'Không cập nhật được lịch: ' . $res->get_error_message(), 422 );
				}
				return array( 'event_id' => (int) $existing->id, 'synced' => true, 'status' => $status );
			}

			$id = $mgr->create_event( array(
				'user_id'      => (int) $uid,
				'title'        => $name,
				'start_at'     => $when,
				'reminder_min' => 0,
				'source'       => self::SOURCE,
				'event_type'   => self::EVENT_TYPE,
				'status'       => $status,
				'metadata'     => $meta,
				// [2026-10-06 11:58 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.92-S92-SC-7 — v5 columns: the cell is this row's clock.
				'dispatcher'   => 'cell',
				'cell_job_id'  => $job_id,
				'cell_state'   => 'synced',
			) );
			if ( is_wp_error( $id ) ) {
				return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::QUERY_INVALID, 'Không ghi được lịch: ' . $id->get_error_message(), 422 );
			}
			// The cell fires this job, never the PHP reminder cron.
			$mgr->mark_reminder_sent( (int) $id );
			return array( 'event_id' => (int) $id, 'synced' => true, 'status' => $status );
		} );
	}

	/**
	 * The row of one cell job in this principal's calendar (null when none): by the `cell_job_id` column (schema v5), or a 0.91
	 * mirror row found by its metadata.
	 */
	private static function find( $mgr, $uid, $job_id ) {
		global $wpdb;
		$table = $mgr->get_table();
		$like  = '%' . $wpdb->esc_like( '"cell_job":{"id":"' . $job_id . '"' ) . '%';
		// [2026-10-06 11:58 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.92-S92-SC-7 — column first; LIKE only for rows written before v5.
		$row   = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$table} WHERE user_id = %d AND ( cell_job_id = %s OR ( event_type = %s AND metadata LIKE %s ) ) ORDER BY id DESC LIMIT 1",
			$uid,
			$job_id,
			self::EVENT_TYPE,
			$like
		) );
		return $row ? $row : null;
	}

	/** The site row handed to the cell (job_upsert), owned by this principal and still on the cell's clock. */
	private static function find_site_row( $mgr, $uid, $event_id ) {
		global $wpdb;
		$table = $mgr->get_table();
		$row   = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$table} WHERE id = %d AND user_id = %d AND dispatcher = 'cell' LIMIT 1",
			$event_id,
			$uid
		) );
		return $row ? $row : null;
	}

	/** Cell ISO-8601 UTC → the scheduler's site-local wall clock ('' when unparsable). */
	private static function local( $iso ) {
		$iso = trim( (string) $iso );
		if ( '' === $iso ) {
			return '';
		}
		$ts = strtotime( $iso );
		return false === $ts ? '' : wp_date( 'Y-m-d H:i:s', $ts );
	}
}

BizCity_Schedule_Sync_MCP_Service::init();
