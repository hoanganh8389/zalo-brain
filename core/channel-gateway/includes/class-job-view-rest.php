<?php
/**
 * BizCity Channel Gateway — "Việc đã làm": the person's assistant Jobs, read from the twin event stream (PHASE-0.117 S117-J5).
 *
 *   GET bizcity-channel/v1/jobs              the current person's latest Jobs (one row per job_id, newest first)
 *   GET bizcity-channel/v1/jobs/<job_id>     one Job folded from its events (R-JOB-9): by `seq`, duplicates ignored, gaps reported
 *
 * Each person sees ONLY their own Jobs (even an admin). Nothing here is content: steps, statuses, evidence references, a failure CODE.
 * The fold is a pure function (`fold()`), tested without WordPress.
 *
 * @package BizCity_Channel_Gateway
 */
// [2026-10-17 02:40 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.117 S117-J5

defined( 'ABSPATH' ) || exit;

final class BizCity_Job_View_REST {

	const NS    = 'bizcity-channel/v1';
	const TYPES = array( 'job_progressed', 'job_completed' );

	/** @var callable|null tests: fn(int $user_id, ?string $job_id): array of rows {event_type, payload, created_epoch_ms} */
	public static $rows_seam = null;

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes(): void {
		$perm = static function () {
			return is_user_logged_in() ? true : new WP_Error( 'auth_required', 'Cần đăng nhập.', array( 'status' => 401 ) );
		};
		register_rest_route( self::NS, '/jobs', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'list_jobs' ), 'permission_callback' => $perm ) );
		register_rest_route( self::NS, '/jobs/(?P<id>job_[0-9A-Za-z]{10,40})', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'get_job' ), 'permission_callback' => $perm ) );
	}

	/**
	 * R-JOB-9: fold the events of ONE Job. Sort by seq, keep the first of a duplicated seq, the final state is the event with the
	 * highest seq; a missing seq between 1 and the highest is reported in `gaps` (the screen says "đang cập nhật", never guesses).
	 *
	 * @param array<int,array{event_type:string,payload:array,created_epoch_ms?:int}> $rows
	 */
	public static function fold( array $rows ): array {
		$by_seq = array();
		foreach ( $rows as $r ) {
			$p   = is_array( $r['payload'] ?? null ) ? $r['payload'] : array();
			$seq = (int) ( $p['seq'] ?? 0 );
			if ( $seq <= 0 || isset( $by_seq[ $seq ] ) ) {
				continue;
			}
			$by_seq[ $seq ] = array( 'type' => (string) ( $r['event_type'] ?? '' ), 'p' => $p, 'at' => (int) ( $r['created_epoch_ms'] ?? 0 ) );
		}
		if ( ! $by_seq ) {
			return array();
		}
		ksort( $by_seq );
		$max  = (int) max( array_keys( $by_seq ) );
		$gaps = array();
		for ( $i = 1; $i <= $max; $i++ ) {
			if ( ! isset( $by_seq[ $i ] ) ) {
				$gaps[] = $i;
			}
		}
		$last   = $by_seq[ $max ];
		$trail  = array();
		$result = null;
		foreach ( $by_seq as $seq => $e ) {
			$trail[] = array( 'seq' => $seq, 'kind' => (string) ( $e['p']['kind'] ?? '' ), 'status' => (string) ( $e['p']['status'] ?? '' ), 'step_id' => (string) ( $e['p']['step_id'] ?? '' ), 'at' => $e['at'] );
			if ( 'job_completed' === $e['type'] && isset( $e['p']['result'] ) && is_array( $e['p']['result'] ) ) {
				$result = $e['p']['result'];
			}
		}
		// [2026-10-17 05:54 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.117 S117-J6: the skill rides on every event; the finished result (when there is one) wins
		$skill = is_array( $result ) ? (string) ( $result['skill']['id'] ?? '' ) : '';
		foreach ( array_reverse( $by_seq, true ) as $e ) {
			if ( '' === $skill && ! empty( $e['p']['skill'] ) ) {
				$skill = (string) $e['p']['skill'];
			}
		}
		$failure = '';
		foreach ( array_reverse( $by_seq, true ) as $e ) {
			if ( ! empty( $e['p']['failure_code'] ) ) {
				$failure = (string) $e['p']['failure_code'];
				break;
			}
		}
		return array(
			'job_id'       => (string) ( $last['p']['job_id'] ?? '' ),
			'status'       => (string) ( $last['p']['status'] ?? '' ),
			'last_seq'     => $max,
			'gaps'         => $gaps,
			'failure_code' => $failure,
			'skill'        => $skill,
			'trail'        => $trail,
			'result'       => $result,
			'result_omitted' => ! empty( $last['p']['result_omitted'] ),
			'updated_at'   => $last['at'],
		);
	}

	private static function rows( int $user_id, ?string $job_id ): array {
		if ( is_callable( self::$rows_seam ) ) {
			return (array) call_user_func( self::$rows_seam, $user_id, $job_id );
		}
		if ( ! class_exists( 'BizCity_Twin_Event_Stream_Schema' ) ) {
			return array();
		}
		global $wpdb;
		$table = BizCity_Twin_Event_Stream_Schema::table();
		$blog  = (int) get_current_blog_id();
		$in    = "'" . implode( "','", self::TYPES ) . "'";
		$sql   = null === $job_id
			? $wpdb->prepare( "SELECT event_type, trace_id, payload_json, created_epoch_ms FROM {$table} WHERE user_id = %d AND blog_id = %d AND event_type IN ({$in}) ORDER BY id DESC LIMIT 600", $user_id, $blog )
			: $wpdb->prepare( "SELECT event_type, trace_id, payload_json, created_epoch_ms FROM {$table} WHERE user_id = %d AND blog_id = %d AND trace_id = %s AND event_type IN ({$in}) ORDER BY id ASC LIMIT 500", $user_id, $blog, $job_id );
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		$out  = array();
		foreach ( is_array( $rows ) ? $rows : array() as $r ) {
			$out[] = array( 'event_type' => (string) $r['event_type'], 'trace_id' => (string) $r['trace_id'], 'payload' => json_decode( (string) $r['payload_json'], true ) ?: array(), 'created_epoch_ms' => (int) $r['created_epoch_ms'] );
		}
		return $out;
	}

	public static function list_jobs( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );
		$groups = array();
		foreach ( self::rows( get_current_user_id(), null ) as $r ) {
			$groups[ (string) ( $r['trace_id'] ?? ( $r['payload']['job_id'] ?? '' ) ) ][] = $r;
		}
		$jobs = array();
		foreach ( $groups as $id => $rows ) {
			$f = self::fold( $rows );
			if ( ! $f ) {
				continue;
			}
			$jobs[] = array(
				'job_id'       => $f['job_id'] ?: $id,
				'status'       => $f['status'],
				'skill'        => (string) ( $f['skill'] ?? '' ),
				'failure_code' => $f['failure_code'],
				'updated_at'   => $f['updated_at'],
			);
		}
		usort( $jobs, static function ( $a, $b ) { return $b['updated_at'] <=> $a['updated_at']; } );
		return new WP_REST_Response( array( 'ok' => true, 'jobs' => array_slice( $jobs, 0, 50 ) ), 200 );
	}

	public static function get_job( WP_REST_Request $request ): WP_REST_Response {
		$id = (string) $request->get_param( 'id' );
		$f  = self::fold( self::rows( get_current_user_id(), $id ) );
		if ( ! $f ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'job_not_found', 'message' => 'Không có việc này.' ), 404 );
		}
		return new WP_REST_Response( array( 'ok' => true, 'job' => $f ), 200 );
	}
}
