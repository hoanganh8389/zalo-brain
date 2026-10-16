<?php
/**
 * Cell output write-back OUTSIDE a chat turn — `POST bizcity-twin/v1/cell-output-ready` (PHASE-0.110 S110-L1, contract cell-output-ready@1).
 *
 * A background job on the cell (e.g. the video MoneyPrinter built from the owner's Zalo photos) finished: cell outbox → Hub → here, with the
 * number's per-account callback Bearer (same auth as `turn-complete`). Per file: one `cell_output_created` twin event (uuid from
 * `idempotency_key` + output id, so a replay is a no-op) and `do_action( 'bizcity_cell_output_created' )`, which BizCity_Twin_Cell_Output_Store
 * turns into the media-library copy (and R2 via the bizcity-r2 mu-plugin). NO `assistant_message`, NO turn counting, NO
 * `bizcity_twin_agent_turn_completed`. Phase 1: role `owner` only (D110-10).
 *
 * Auth, owner lookup, dedupe and ingest are the turn-complete helpers (same `$readers` test seams), the file validation is its `files()`.
 *
 * // @axis twin-agent-axis@1 seam SEAM-3
 *
 * // [2026-10-14 08:50 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.110 S110-L1 — new file.
 *
 * @package BizCity_Twin_AI
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Twin_Cell_Output_Ready_REST', false ) ) {
	return;
}

final class BizCity_Twin_Cell_Output_Ready_REST {

	const NS       = 'bizcity-twin/v1';
	const CONTRACT = 'cell-output-ready@1';

	private static $initialized = false;

	public static function init(): void {
		if ( self::$initialized ) {
			return;
		}
		self::$initialized = true;
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes(): void {
		register_rest_route( self::NS, '/cell-output-ready', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'handle' ),
			'permission_callback' => '__return_true', // per-account Bearer verified in handler
		) );
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
		$trace    = substr( sanitize_text_field( (string) ( $body['trace_id'] ?? '' ) ), 0, 128 );
		if ( 0 !== strpos( $contract, self::CONTRACT ) || '' === $key || '' === $trace ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'invalid_param', 'message' => 'contract, idempotency_key and trace_id are required.' ), 400 );
		}
		// phase 1 (D110-10): only the number's owner; an acknowledged 200 so the Hub does not retry or dead-letter it
		if ( 'owner' !== (string) ( $body['role'] ?? '' ) ) {
			return new WP_REST_Response( array( 'ok' => true, 'ignored' => true, 'reason' => 'role_not_supported' ), 200 );
		}
		$files = $tc::files( $body['files'] ?? array() );
		if ( ! $files ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'invalid_param', 'message' => 'files must hold at least one valid cell-output@1 row.' ), 400 );
		}
		$owner_id = (int) $tc::owner( $account );
		if ( $owner_id <= 0 ) {
			// without a user the copy is never pulled and the list never shows it: refuse loudly instead of storing a ghost row
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'owner_unresolved' ), 422 );
		}

		$fresh = array();
		foreach ( $files as $i => $f ) {
			$uuid = $tc::uuid_from_key( $key . ':o:' . $f['output_id'] );
			if ( ! $tc::exists( $uuid ) ) {
				$fresh[] = array( 'uuid' => $uuid, 'i' => $i, 'file' => $f );
			}
		}
		if ( ! $fresh ) {
			return new WP_REST_Response( array( 'ok' => true, 'duplicate' => true, 'stored' => 0 ), 200 );
		}

		$blog   = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : null;
		$now_ms = (int) ( microtime( true ) * 1000 );
		$stored = 0;
		try {
			foreach ( $fresh as $row ) {
				$f = $row['file'];
				$tc::ingest( array(
					'event_uuid'       => $row['uuid'],
					'event_type'       => 'cell_output_created',
					'event_source'     => 'server',
					'trace_id'         => $trace,
					'session_id'       => null, // not a chat turn: no TwinChat session
					'user_id'          => $owner_id,
					'blog_id'          => $blog,
					'account_id'       => $account,
					'actor_role'       => 'owner',
					'created_epoch_ms' => $now_ms + $row['i'],
					'payload'          => $f + array( 'account_id' => $account, 'state' => 'pending', 'source' => sanitize_key( (string) ( $body['source'] ?? '' ) ), 'job_id' => substr( sanitize_text_field( (string) ( $body['job_id'] ?? '' ) ), 0, 64 ) ),
				) );
				++$stored;
				do_action( 'bizcity_cell_output_created', array( 'event_uuid' => $row['uuid'], 'user_id' => $owner_id, 'account_id' => $account, 'session_id' => '', 'trace_id' => $trace, 'output' => $f ) );
			}
		} catch ( \Throwable $e ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'event_invalid', 'message' => substr( $e->getMessage(), 0, 300 ) ), 422 );
		}
		return new WP_REST_Response( array( 'ok' => true, 'duplicate' => false, 'stored' => $stored ), 200 );
	}
}
