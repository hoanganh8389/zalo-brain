<?php
/**
 * BizCity_Journal_Meta_Backfill — rows written BEFORE PHASE-0.133 get what the site derives today: `find_text` (journal.find v2) and
 * `metadata.resource` (brain-resource-link@1: source_ref@v, source_uuid). Nothing is extracted here (no model in PHP): rows that still need
 * the cell to read them (a photo / file without entry-meta) are only COUNTED, so the owner can decide to queue them (doc 81 §9).
 *
 *   GET  bizcity-knowledge/v2/journal/meta-backfill?after=0&limit=200              ⇒ DRY RUN: how many rows would change, nothing written
 *   POST bizcity-knowledge/v2/journal/meta-backfill  {after, limit, confirm:true}   ⇒ derives that batch (no revision bump)
 *   wp bizcity journal meta-backfill [--after=0] [--limit=200] [--run]               ⇒ same, local / staging only (WP-CLI hits the live DB)
 * Every call returns `next_after` (null at the end): one batch per call, never a web request that walks the whole table (R-PERF).
 *
 * Biz Central Brain — Johnny Chu (Chu Hoàng Anh).
 *
 * [2026-10-24 05:56 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.133 S133-Mc — new file.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\KG_Hub
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Journal_Meta_Backfill', false ) ) {
	return;
}

final class BizCity_Journal_Meta_Backfill {

	const NS    = 'bizcity-knowledge/v2';
	const ROUTE = '/journal/meta-backfill';
	const MAX   = 200;

	private static $hooked = false;

	public static function register(): void {
		if ( self::$hooked || ! function_exists( 'add_action' ) ) {
			return;
		}
		self::$hooked = true;
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( 'WP_CLI' ) ) {
			WP_CLI::add_command( 'bizcity journal meta-backfill', array( __CLASS__, 'cli' ) );
		}
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
	 * What a stored row lacks: find_text (any row with a title / body), resource (a row the resource link applies to), and whether the CELL
	 * still has to read it (a file row with no meta, or meta pending / failed). Pure.
	 *
	 * @param array $entry journal-entry@1 (to_journal_entry of the raw row)
	 * @param array $raw   the raw row (find_text column)
	 * @return array{derive:bool,reasons:string[],needs_read:bool}
	 */
	public static function plan( array $entry, array $raw ): array {
		$reasons = array();
		if ( '' === (string) ( $raw['find_text'] ?? '' ) && ( '' !== trim( (string) ( $entry['title'] ?? '' ) ) || '' !== trim( (string) ( $entry['summary'] ?? '' ) ) ) ) {
			$reasons[] = 'find_text';
		}
		if ( class_exists( 'BizCity_Brain_Resource_Link' ) && 'failed' !== (string) ( $entry['state'] ?? '' ) && BizCity_Brain_Resource_Link::applies( (string) ( $entry['layer'] ?? '' ), (array) ( $entry['original'] ?? array() ) ) && '' === (string) ( $entry['resource']['source_uuid'] ?? '' ) ) {
			$reasons[] = 'resource';
		}
		$status     = (string) ( $entry['meta']['status'] ?? '' );
		$has_file   = (int) ( $entry['original']['attachment_id'] ?? 0 ) > 0;
		$needs_read = $has_file && ( '' === $status || in_array( $status, array( 'pending', 'failed' ), true ) ) && '' === (string) ( $entry['meta']['about'] ?? '' );
		return array( 'derive' => (bool) $reasons, 'reasons' => $reasons, 'needs_read' => $needs_read );
	}

	// ── batch (site) ─────────────────────────────────────────────────────

	/** One batch by id, every owner of this blog. @return array{scanned:int, derive:int, by_reason:array, needs_read:int, ids:int[], next_after:?int} */
	public static function batch( int $after, int $limit ): array {
		global $wpdb;
		$limit = max( 1, min( self::MAX, $limit ) );
		$out   = array( 'scanned' => 0, 'derive' => 0, 'by_reason' => array( 'find_text' => 0, 'resource' => 0 ), 'needs_read' => 0, 'rows' => array(), 'next_after' => null );
		if ( ! isset( $wpdb ) || ! class_exists( 'BizCity_Journal_Database' ) || ! class_exists( 'BizCity_Journal_Index' ) ) {
			return $out;
		}
		$tbl  = BizCity_Journal_Database::instance()->get_table();
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$tbl} WHERE id > %d AND status NOT IN ('archived','void') ORDER BY id ASC LIMIT %d", $after, $limit ), ARRAY_A ) ?: array();
		foreach ( $rows as $raw ) {
			$out['scanned']++;
			$p = self::plan( BizCity_Journal_Index::to_journal_entry( $raw ), $raw );
			foreach ( $p['reasons'] as $r ) {
				$out['by_reason'][ $r ]++;
			}
			$out['needs_read'] += $p['needs_read'] ? 1 : 0;
			if ( $p['derive'] ) {
				$out['derive']++;
				$out['rows'][] = $raw;
			}
		}
		$out['next_after'] = count( $rows ) >= $limit ? (int) end( $rows )['id'] : null;
		return $out;
	}

	public static function run( int $after, int $limit, bool $write ): array {
		$b    = self::batch( $after, $limit );
		$done = 0;
		if ( $write ) {
			foreach ( $b['rows'] as $raw ) {
				BizCity_Journal_Index::derive( $raw );
				$done++;
			}
		}
		unset( $b['rows'] );
		return array( 'dry_run' => ! $write, 'written' => $done ) + $b;
	}

	public static function rest_dry_run( $request ) {
		return self::run( (int) $request->get_param( 'after' ), (int) ( $request->get_param( 'limit' ) ?: self::MAX ), false );
	}

	public static function rest_run( $request ) {
		if ( true !== (bool) $request->get_param( 'confirm' ) ) {
			return new WP_Error( 'confirm_required', 'Chạy thử khô trước (GET), rồi gửi confirm=true.', array( 'status' => 400 ) );
		}
		return self::run( (int) $request->get_param( 'after' ), (int) ( $request->get_param( 'limit' ) ?: self::MAX ), true );
	}

	/** `wp bizcity journal meta-backfill [--after=<id>] [--limit=<n>] [--run]` — walks every batch; dry run unless --run. */
	public static function cli( $args, $assoc ): void {
		$after = (int) ( $assoc['after'] ?? 0 );
		$limit = (int) ( $assoc['limit'] ?? self::MAX );
		$write = isset( $assoc['run'] );
		do {
			$r = self::run( $after, $limit, $write );
			WP_CLI::log( sprintf( 'after=%d scanned=%d derive=%d (find_text %d, resource %d) needs_read=%d written=%d', $after, $r['scanned'], $r['derive'], $r['by_reason']['find_text'], $r['by_reason']['resource'], $r['needs_read'], $r['written'] ) );
			$after = (int) $r['next_after'];
		} while ( null !== $r['next_after'] );
		WP_CLI::success( $write ? 'Xong.' : 'Chạy thử xong (thêm --run để ghi).' );
	}
}

