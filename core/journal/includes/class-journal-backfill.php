<?php
/**
 * BizCity_Journal_Backfill — notebook sources ingested BEFORE the ingest listener existed get their journal row
 * (PHASE-0.121 B2-6 / S121-J8, R-JOURNAL R-JN-13). Copyright (c) 2026 Chu Hoàng Anh (Johnny Chu), No. 8877/2026/QTG.
 *
 * One admin-only REST route, one batch per call (no WP-CLI on production — memory rule):
 *   GET  bizcity-knowledge/v2/journal/backfill?after=0&limit=200            ⇒ DRY RUN: counts by kind, nothing written
 *   POST bizcity-knowledge/v2/journal/backfill  {after, limit, confirm:true} ⇒ writes the rows of that batch
 * Both return `next_after` (null at the end). Same row shape as BizCity_Journal_Ingest_Listener::entry_for(): key
 * `src:<blog>:<source_id>`, so a later live ingest of the same source is a replay, never a second row. Sources that already
 * have a row (by key or by KG mirror) and chat-memory sources are skipped.
 *
 * @package Bizcity_Twin_AI
 * @since   2026-10-17
 */

// [2026-10-17 09:05 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.121 B2-6 (S121-J8) — new file.

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Journal_Backfill', false ) ) {
	return;
}

final class BizCity_Journal_Backfill {

	const NS    = 'bizcity-knowledge/v2';
	const ROUTE = '/journal/backfill';
	const MAX   = 500;

	private static $hooked = false;

	public static function register(): void {
		if ( self::$hooked || ! function_exists( 'add_action' ) ) {
			return;
		}
		self::$hooked = true;
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes(): void {
		$perm = static function () {
			return current_user_can( 'manage_options' );
		};
		register_rest_route( self::NS, self::ROUTE, array(
			array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'rest_dry_run' ), 'permission_callback' => $perm ),
			array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'rest_run' ), 'permission_callback' => $perm ),
		) );
	}

	// ── pure ─────────────────────────────────────────────────────────────

	/**
	 * The journal-entry input for one legacy webchat source row, or null to skip. Pure.
	 *
	 * @param array    $src   bizcity_webchat_sources row {id, user_id, project_id, source_type, title, attachment_id, chunk_count, metadata}
	 * @param int|null $kg_id kg_sources mirror id (null/0 when none)
	 */
	public static function plan_row( array $src, int $blog_id, ?int $kg_id ): ?array {
		$sid  = (int) ( $src['id'] ?? 0 );
		$user = (int) ( $src['user_id'] ?? 0 );
		$nb   = (int) ( $src['project_id'] ?? $src['notebook_id'] ?? 0 );
		$type = (string) ( $src['source_type'] ?? 'file' );
		if ( $sid <= 0 || $user <= 0 || in_array( $type, array( 'memory', 'chat', 'session' ), true ) ) {
			return null;
		}
		$meta = json_decode( (string) ( $src['metadata'] ?? '' ), true );
		$meta = is_array( $meta ) ? $meta : array();
		if ( 'owner_capture' === (string) ( $meta['inbound']['origin'] ?? '' ) && (int) $kg_id <= 0 ) {
			return null; // a Zalo capture without a KG mirror cannot be matched to its oc:… row safely — leave it
		}
		$payload = array(
			'type'          => $type,
			'title'         => (string) ( $src['title'] ?? '' ),
			'attachment_id' => (int) ( $src['attachment_id'] ?? 0 ),
			'metadata'      => $meta,
		);
		unset( $payload['metadata']['inbound'] ); // the listener's owner_capture skip must not apply to a backfill
		$result = array( 'source_id' => $sid, 'kg_source_id' => (int) $kg_id, 'chunk_count' => (int) ( $src['chunk_count'] ?? 0 ), 'duplicate' => false );
		$entry  = BizCity_Journal_Ingest_Listener::entry_for( $blog_id, $nb, $user, $result, $payload );
		if ( (int) ( $src['chunk_count'] ?? 0 ) <= 0 ) {
			$entry['learning'] = array( 'level' => 'L0' );
		}
		$entry['origin'] = array( 'surface' => 'backfill', 'ref' => 'webchat_source:' . $sid );
		return $entry;
	}

	/** Count planned rows by kind. Pure. @param array<int,array|null> $plans */
	public static function summarize( array $plans ): array {
		$by = array();
		$n  = 0;
		foreach ( $plans as $p ) {
			if ( is_array( $p ) ) {
				$k        = (string) ( $p['kind'] ?? '' );
				$by[ $k ] = ( $by[ $k ] ?? 0 ) + 1;
				$n++;
			}
		}
		ksort( $by );
		return array( 'would_write' => $n, 'by_kind' => $by );
	}

	// ── batch ────────────────────────────────────────────────────────────

	/**
	 * @return array{plans:array<int,array>,scanned:int,next_after:int|null,skipped:int}
	 */
	public static function batch( int $after, int $limit ): array {
		global $wpdb;
		$limit = max( 1, min( self::MAX, $limit ) );
		$out   = array( 'plans' => array(), 'scanned' => 0, 'next_after' => null, 'skipped' => 0 );
		if ( ! isset( $wpdb ) || ! class_exists( 'BizCity_TwinChat_Sources_Database' ) || ! class_exists( 'BizCity_Journal_Database' ) || ! class_exists( 'BizCity_Journal_Ingest_Listener' ) ) {
			return $out;
		}
		$tbl  = BizCity_TwinChat_Sources_Database::instance()->table_sources();
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$tbl} WHERE id > %d ORDER BY id ASC LIMIT %d", $after, $limit ), ARRAY_A ) ?: array();
		$db   = BizCity_Journal_Database::instance();
		$blog = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
		$kgt  = class_exists( 'BizCity_KG_Database' ) ? BizCity_KG_Database::instance()->tbl_sources() : '';
		foreach ( $rows as $src ) {
			$out['scanned']++;
			$sid = (int) $src['id'];
			$kg  = '' !== $kgt ? (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$kgt} WHERE origin_plugin = 'twinchat' AND origin_id = %d ORDER BY id DESC LIMIT 1", $sid ) ) : 0;
			if ( $db->get_by_idempotency_key( BizCity_Journal_Ingest_Listener::key_for( $blog, $sid ) ) || ( $kg > 0 && $db->get_by_kg_source_id( $kg ) ) ) {
				$out['skipped']++;
				continue;
			}
			$plan = self::plan_row( $src, $blog, $kg );
			if ( null === $plan ) {
				$out['skipped']++;
				continue;
			}
			$out['plans'][] = $plan;
		}
		$out['next_after'] = count( $rows ) >= $limit ? (int) end( $rows )['id'] : null;
		return $out;
	}

	public static function rest_dry_run( $request ) {
		$b = self::batch( (int) $request->get_param( 'after' ), (int) ( $request->get_param( 'limit' ) ?: 200 ) );
		return array( 'dry_run' => true, 'scanned' => $b['scanned'], 'skipped' => $b['skipped'], 'next_after' => $b['next_after'] ) + self::summarize( $b['plans'] );
	}

	public static function rest_run( $request ) {
		if ( true !== (bool) $request->get_param( 'confirm' ) ) {
			return new WP_Error( 'confirm_required', 'Chạy thử khô trước (GET), rồi gửi confirm=true.', array( 'status' => 400 ) );
		}
		$b       = self::batch( (int) $request->get_param( 'after' ), (int) ( $request->get_param( 'limit' ) ?: 200 ) );
		$written = 0;
		$errors  = 0;
		foreach ( $b['plans'] as $plan ) {
			$r = BizCity_Journal_Index::add( $plan );
			is_wp_error( $r ) ? $errors++ : $written++;
		}
		return array( 'dry_run' => false, 'scanned' => $b['scanned'], 'skipped' => $b['skipped'], 'written' => $written, 'errors' => $errors, 'next_after' => $b['next_after'] );
	}
}
