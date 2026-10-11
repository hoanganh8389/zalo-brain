<?php
/**
 * BizCity_Skills_Pack_Exporter — projection-pack@1 kind `skills` (PHASE-0.101 S101-L6, doc 90 §4).
 *
 * One pack per site (like `automation`): every APPROVED skill as labels only — `name`, `description`, `tools`,
 * `scope`, `version`, `updated_at`. The body is NOT in the pack (R-M595): the cell / harness read it on demand
 * through `skills.get`. ≤ 60 items, ≤ 24 KB ⇒ `truncated`. Invalidated by BizCity_Skills_Service on save/status.
 *
 * Biz Central Brain — Johnny Chu (Chu Hoàng Anh).
 *
 * // [2026-10-11 Johnny Chu - Chu Hoàng Anh] PHASE-0.101 S101-L6 — new file.
 * // @axis twin-agent-axis@1 pack skills
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Skills
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Skills_Pack_Exporter', false ) ) {
	return;
}

final class BizCity_Skills_Pack_Exporter {

	const KIND      = 'skills';
	const MAX_ITEMS = 60;
	const MAX_BYTES = 24576;
	const CACHE_KEY = 'bizcity_skills_pack_v1';
	const CACHE_TTL = 300;

	private static $hooked = false;
	private static $memo   = null;

	public static function init(): void {
		if ( self::$hooked ) {
			return;
		}
		self::$hooked = true;
		add_filter( 'bizcity_twin_agent_pack_exporters', array( __CLASS__, 'exporters' ) );
	}

	public static function exporters( $exporters ): array {
		$exporters = is_array( $exporters ) ? $exporters : array();
		$exporters[ self::KIND ] = array(
			'mode'      => 'notebook',
			'audience'  => 'owner_agent',
			'available' => array( __CLASS__, 'available' ),
			'stats'     => array( __CLASS__, 'stats' ),
			'page'      => array( __CLASS__, 'page' ),
		);
		return $exporters;
	}

	public static function available(): bool {
		return class_exists( 'BizCity_Skills_Service', false ) && class_exists( 'BizCity_Skill_Manager', false );
	}

	/** @return array{items:array,truncated:bool} */
	public static function build(): array {
		if ( is_array( self::$memo ) ) {
			return self::$memo;
		}
		$cached = function_exists( 'get_transient' ) ? get_transient( self::cache_key() ) : false;
		if ( is_array( $cached ) && isset( $cached['items'] ) ) {
			self::$memo = $cached;
			return $cached;
		}
		$items     = array();
		$bytes     = 2;
		$truncated = false;
		if ( self::available() ) {
			foreach ( BizCity_Skills_Service::list( array( 'status' => 'approved' ), self::MAX_ITEMS ) as $s ) {
				$item = array(
					'name'        => (string) $s['name'],
					'description' => (string) $s['description'],
					'tools'       => array_values( (array) $s['tools'] ),
					'scope'       => (string) $s['scope'],
					'version'     => (int) $s['version'],
					'updated_at'  => (string) $s['updated_at'],
				);
				$len = strlen( (string) wp_json_encode( $item ) ) + 1;
				if ( count( $items ) >= self::MAX_ITEMS || $bytes + $len > self::MAX_BYTES ) {
					$truncated = true;
					break;
				}
				$items[] = $item;
				$bytes  += $len;
			}
		}
		self::$memo = array( 'items' => $items, 'truncated' => $truncated );
		if ( function_exists( 'set_transient' ) ) {
			set_transient( self::cache_key(), self::$memo, self::CACHE_TTL );
		}
		return self::$memo;
	}

	/** Drop the cached pack (called by the service after every write). */
	public static function flush(): void {
		self::$memo = null;
		if ( function_exists( 'delete_transient' ) ) {
			delete_transient( self::cache_key() );
		}
	}

	/** @param array $ctx unused — the pack is the site's; the cell gates by role + mode. */
	public static function stats( array $ctx = array() ): array {
		$b    = self::build();
		$json = (string) wp_json_encode( $b['items'] );
		return array(
			'version'   => 'v-' . substr( sha1( self::KIND . '|' . $json ), 0, 8 ) . ( $b['truncated'] ? '-t' : '' ),
			'as_of'     => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'bytes'     => strlen( $json ),
			'items'     => count( $b['items'] ),
			'truncated' => $b['truncated'],
		);
	}

	public static function page( array $ctx, int $after, int $limit ): array {
		$b     = self::build();
		$items = array_slice( $b['items'], max( 0, $after ), max( 1, $limit ) );
		$last  = max( 0, $after ) + count( $items );
		return array( 'items' => $items, 'last_id' => $last, 'more' => $last < count( $b['items'] ) );
	}

	/** Cache key carries the blog id (R-MSDB). */
	private static function cache_key(): string {
		return self::CACHE_KEY . '_' . (int) get_current_blog_id();
	}
}
