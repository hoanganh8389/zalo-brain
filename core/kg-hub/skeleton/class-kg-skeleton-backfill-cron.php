<?php
/**
 * Bizcity Twin AI — Skeleton Backfill Cron (R-CRON-META compliant)
 *
 * Periodic backfill that re-queues notebooks stuck in `failed`, `pending`
 * (job lost), or frozen `building` (updated_at stale) states so they
 * eventually get a skeleton even when the ingest-event trigger was missed
 * (fresh install, backup restore, migration).
 *
 * ─── CONTRACT ────────────────────────────────────────────────────────────────
 *
 *   Hook     : bizcity_kg_skeleton_backfill_cron
 *   Interval : hourly
 *   Batch    : up to BATCH_LIMIT notebooks per tick
 *   Action   : BizCity_KG_Skeleton_Service::schedule_rebuild() for each
 *              (idempotent — Action Scheduler de-dupes in-flight jobs)
 *
 * ─── R-CRON-META ─────────────────────────────────────────────────────────────
 *   Registered via BizCity_Cron_Manager::instance()->register().
 *   Every tick records note() counters + note_event() per exception.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Knowledge\KG_Hub
 * @since      2026-05-28
 * @see        PHASE-0-RULE-CRON-META.md
 * @see        class-notebook-skeleton-service.php  schedule_rebuild()
 * @see        class-kg-skeleton-diagnostic.php     STUCK_SECONDS / repair_stuck()
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_KG_Skeleton_Backfill_Cron {

	/** WP cron / Action Scheduler hook name. */
	const CRON_HOOK = 'bizcity_kg_skeleton_backfill_cron';

	/** Cron Manager job id. */
	const JOB_ID = 'kg.skeleton.backfill';

	/**
	 * Max notebooks re-queued per tick.
	 * Keeps each tick well under PHP execution limits even on slow hosts.
	 */
	const BATCH_LIMIT = 10;

	/**
	 * Seconds after which a `pending` or `building` notebook whose
	 * `updated_at` has not advanced is considered stuck and eligible for
	 * re-scheduling.  Mirrors BizCity_KG_Skeleton_Diagnostic::STUCK_SECONDS.
	 */
	const STUCK_SECONDS = 900;

	/** [2026-10-11 11:52 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.99 S99-P33 — never-built Zalo daily notebooks queued per hourly run. */
	const NEVER_BUILT_LIMIT = 5;

	// ─── Boot ────────────────────────────────────────────────────────────────

	public static function boot(): void {
		add_action( self::CRON_HOOK, [ __CLASS__, 'run' ] );

		if ( class_exists( 'BizCity_Cron_Manager' ) ) {
			// R-CRON-META: register so the manager auto-wraps trace + meta.
			BizCity_Cron_Manager::instance()->register( [
				'id'          => self::JOB_ID,
				'hook'        => self::CRON_HOOK,
				'interval'    => 'hourly',
				'owner'       => 'core/kg-hub',
				'description' => 'Re-queue pending/failed/frozen notebooks for skeleton build',
			] );
		} elseif ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			// Cron Manager not yet loaded — schedule manually as fallback.
			wp_schedule_event( time() + 300, 'hourly', self::CRON_HOOK );
		}
	}

	// ─── Cron tick ───────────────────────────────────────────────────────────

	public static function run(): void {
		global $wpdb;

		if ( ! class_exists( 'BizCity_KG_Database' ) || ! class_exists( 'BizCity_KG_Skeleton_Service' ) ) {
			return;
		}

		$tbl       = BizCity_KG_Database::instance()->tbl_notebooks();
		$threshold = gmdate( 'Y-m-d H:i:s', time() - self::STUCK_SECONDS );
		$counters  = [ 'queued' => 0, 'errors' => 0, 'scanned' => 0 ];

		/*
		 * Eligible notebooks (same logic as BizCity_KG_Skeleton_Diagnostic::repair_stuck):
		 *   1. status = 'failed'        → always re-try
		 *   2. status = 'pending'       → job was lost; updated_at stale
		 *   3. status = 'building'      → job crashed; updated_at stale
		 */
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$tbl}
				  WHERE ( skeleton_status = 'failed' )
				     OR ( skeleton_status IN ('pending','building')
				          AND ( updated_at IS NULL OR updated_at < %s ) )
				  ORDER BY id ASC
				  LIMIT %d",
				$threshold,
				self::BATCH_LIMIT
			)
		);

		$ids = array_map( 'intval', (array) $ids );

		// [2026-10-11 11:52 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.99 S99-P33 (doc 70 §3.3) — a Zalo number's daily notebook that NEVER got
		// a skeleton (status '' — created before the ingest hook, or its build was never queued) has no card, so the cell can
		// only score it by date. Pick a few per run (newest first, only notebooks that hold passages); each build is one
		// cost-guarded model call. Filter `bizcity_kg_skeleton_backfill_never_built` (int, 0 = off, max 10).
		$never_cap = (int) apply_filters( 'bizcity_kg_skeleton_backfill_never_built', self::NEVER_BUILT_LIMIT );
		$never_cap = max( 0, min( 10, $never_cap ) );
		if ( $never_cap > 0 ) {
			$tbl_pas = BizCity_KG_Database::instance()->tbl_passages();
			$never   = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT n.id FROM {$tbl} n
					  WHERE ( n.skeleton_status = '' OR n.skeleton_status IS NULL )
					    AND n.settings LIKE %s
					    AND EXISTS ( SELECT 1 FROM {$tbl_pas} p WHERE p.notebook_id = n.id )
					  ORDER BY n.id DESC
					  LIMIT %d",
					'%' . $wpdb->esc_like( '"scope_id":"zalo_account:' ) . '%',
					$never_cap
				)
			);
			$ids = array_values( array_unique( array_merge( $ids, array_map( 'intval', (array) $never ) ) ) );
		}
		$counters['scanned'] = count( $ids );

		foreach ( $ids as $nb_id ) {
			if ( $nb_id <= 0 ) {
				continue;
			}
			try {
				// schedule_rebuild() is idempotent — AS de-dupes in-flight jobs.
				BizCity_KG_Skeleton_Service::schedule_rebuild( $nb_id, 'cron_backfill' );
				$counters['queued']++;
			} catch ( \Throwable $e ) {
				$counters['errors']++;
				if ( class_exists( 'BizCity_Cron_Manager' ) ) {
					BizCity_Cron_Manager::instance()->note_event(
						'skeleton_backfill_error',
						[
							'notebook_id' => $nb_id,
							'reason'      => 'exception',
							'error'       => $e->getMessage(),
						]
					);
				}
				error_log( '[KG Skeleton Backfill] nb=' . $nb_id . ' error: ' . $e->getMessage() );
			}
		}

		// R-CRON-META: record run counters in bizcity_cron_runs.meta.
		if ( class_exists( 'BizCity_Cron_Manager' ) ) {
			BizCity_Cron_Manager::instance()->note( [
				'counters' => [
					'scanned' => $counters['scanned'],
					'queued'  => $counters['queued'],
					'errors'  => $counters['errors'],
				],
			] );
		}
	}
}
