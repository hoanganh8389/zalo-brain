<?php
/**
 * Assistant Job progress write-back — `POST bizcity-twin/v1/job-event` (PHASE-0.117 S117-J3, contract job-event@1).
 *
 * A Job on the cell changed state: cell outbox → Hub → here, with the number's per-account callback Bearer (same auth as
 * `cell-output-ready`). One twin event per (job, seq): `job_progressed`, or `job_completed` carrying the `job-result@1` snapshot
 * (references + hashes only — never mail text, sheet rows, raw e-mail / phone or tokens; R-JOB-6). The uuid comes from the
 * idempotency key, so a replay is a no-op; the site folds events by `seq` (R-JOB-9) and reads `GET /wp/jobs/:id` on a gap.
 * Phase 1: the Job's owner must be the number's owner (role `owner`), like cell-output-ready (D110-10).
 *
 * // @axis twin-agent-axis@1 seam SEAM-3
 *
 * // [2026-10-17 02:25 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.117 S117-J3 — new file.
 *
 * @package BizCity_Twin_AI
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Twin_Job_Event_REST', false ) ) {
	return;
}

final class BizCity_Twin_Job_Event_REST {

	const NS            = 'bizcity-twin/v1';
	const CONTRACT      = 'job-event@1';
	const MAX_RESULT_KB = 60;

	/** @var array<string,callable> test seams: user_ref(int $user_id): string */
	public static $readers = array();

	private static $initialized = false;

	public static function init(): void {
		if ( self::$initialized ) {
			return;
		}
		self::$initialized = true;
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes(): void {
		register_rest_route( self::NS, '/job-event', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'handle' ),
			'permission_callback' => '__return_true', // per-account Bearer verified in handler
		) );
	}

	public static function user_ref( int $user_id ): string {
		if ( isset( self::$readers['user_ref'] ) ) {
			return (string) call_user_func( self::$readers['user_ref'], $user_id );
		}
		return class_exists( 'BizCity_Agent_Mode_Access' ) ? (string) BizCity_Agent_Mode_Access::user_ref( $user_id ) : '';
	}

	public static function handle( WP_REST_Request $request ): WP_REST_Response {
		$tc = 'BizCity_Twin_Agent_Turn_Complete_REST';
		if ( ! class_exists( $tc ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'unavailable' ), 503 );
		}
		$body    = $request->get_json_params();
		$body    = is_array( $body ) ? $body : array();
		$account = sanitize_text_field( (string) ( $body['account_id'] ?? '' ) );
		$token   = '' !== $account ? $tc::token( $account ) : '';
		$header  = (string) $request->get_header( 'authorization' );
		$bearer  = stripos( $header, 'Bearer ' ) === 0 ? trim( substr( $header, 7 ) ) : '';
		if ( '' === $token || '' === $bearer || ! hash_equals( $token, $bearer ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'unauthorized' ), 401 );
		}
		$contract = (string) ( $body['contract'] ?? '' );
		$key      = sanitize_text_field( (string) ( $body['idempotency_key'] ?? '' ) );
		$job_id   = (string) ( $body['job_id'] ?? '' );
		$seq      = (int) ( $body['seq'] ?? 0 );
		$kind     = sanitize_key( (string) ( $body['kind'] ?? '' ) );
		$status   = sanitize_key( (string) ( $body['status'] ?? '' ) );
		if ( 0 !== strpos( $contract, self::CONTRACT ) || '' === $key || ! preg_match( '/^job_[0-9A-Za-z]{10,40}$/', $job_id ) || $seq <= 0 || ! in_array( $kind, array( 'requested', 'approval_requested', 'progressed', 'completed' ), true ) || '' === $status ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'invalid_param', 'message' => 'contract, idempotency_key, job_id, seq, kind and status are required.' ), 400 );
		}
		if ( 'owner' !== (string) ( $body['role'] ?? '' ) ) {
			return new WP_REST_Response( array( 'ok' => true, 'ignored' => true, 'reason' => 'role_not_supported' ), 200 );
		}
		$owner_id = (int) $tc::owner( $account );
		if ( $owner_id <= 0 ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'owner_unresolved' ), 422 );
		}
		// the Job belongs to a PERSON (user_ref); phase 1 records it only when that person is the number's owner
		$ref = strtolower( (string) ( $body['owner_user_ref'] ?? '' ) );
		if ( ! preg_match( '/^[0-9a-f]{64}$/', $ref ) || ! hash_equals( self::user_ref( $owner_id ), $ref ) ) {
			return new WP_REST_Response( array( 'ok' => true, 'ignored' => true, 'reason' => 'not_the_number_owner' ), 200 );
		}
		$uuid = $tc::uuid_from_key( $key );
		if ( $tc::exists( $uuid ) ) {
			return new WP_REST_Response( array( 'ok' => true, 'duplicate' => true ), 200 );
		}
		$payload = array( 'job_id' => $job_id, 'seq' => $seq, 'kind' => $kind, 'status' => $status, 'account_id' => $account );
		if ( ! empty( $body['step_id'] ) && preg_match( '/^[a-z][a-z0-9_]{0,39}$/', (string) $body['step_id'] ) ) {
			$payload['step_id'] = (string) $body['step_id'];
		}
		// [2026-10-17 02:40 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.117 S117-J5: the reason a Job stopped, as a code the Job page turns into a sentence (never provider text)
		if ( ! empty( $body['failure_code'] ) && preg_match( '/^[a-z0-9_]{1,40}$/', (string) $body['failure_code'] ) ) {
			$payload['failure_code'] = (string) $body['failure_code'];
		}
		// [2026-10-17 05:54 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.117 S117-J6: the skill id, so a Job that has not finished still has a name on the Job page
		if ( ! empty( $body['skill'] ) && preg_match( '/^[a-z]{2,20}\.[a-z]{2,20}$/', (string) $body['skill'] ) ) {
			$payload['skill'] = (string) $body['skill'];
		}
		$result_omitted = false;
		if ( 'completed' === $kind && isset( $body['result'] ) && is_array( $body['result'] ) ) {
			$json = wp_json_encode( $body['result'] );
			if ( is_string( $json ) && strlen( $json ) <= self::MAX_RESULT_KB * 1024 ) {
				$payload['result'] = $body['result'];
			} else {
				$result_omitted = true; // too big for one event row: the site reads it back from the cell
				$payload['result_omitted'] = true;
			}
		}
		try {
			$tc::ingest( array(
				'event_uuid'       => $uuid,
				'event_type'       => 'completed' === $kind ? 'job_completed' : 'job_progressed',
				'event_source'     => 'server',
				'trace_id'         => $job_id,
				'session_id'       => null,
				'user_id'          => $owner_id,
				'blog_id'          => function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : null,
				'account_id'       => $account,
				'actor_role'       => 'owner',
				'created_epoch_ms' => (int) ( microtime( true ) * 1000 ),
				'payload'          => $payload,
			) );
		} catch ( \Throwable $e ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'event_invalid', 'message' => substr( $e->getMessage(), 0, 300 ) ), 422 );
		}
		// [2026-10-17 03:10 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.117 S117-S7: the hook also carries the result snapshot (references + hashes) so the site can ADOPT a book the cell created for this person
		do_action( 'bizcity_twin_job_event', array( 'event_uuid' => $uuid, 'user_id' => $owner_id, 'job_id' => $job_id, 'seq' => $seq, 'kind' => $kind, 'status' => $status, 'result' => isset( $payload['result'] ) ? $payload['result'] : null ) );
		return new WP_REST_Response( array( 'ok' => true, 'duplicate' => false, 'result_omitted' => $result_omitted ), 200 );
	}
}
