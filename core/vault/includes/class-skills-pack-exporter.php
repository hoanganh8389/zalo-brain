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
	// [2026-11-23 06:17 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.153 S153-K2 — skills are not limited any more (doc 22): the pack is labels (+ the text of the owner's always-skills), ordered always-first
	const MAX_ITEMS = 1000;
	const MAX_BYTES = 2097152; // [2026-11-23 10:50 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.153 S153-K12 — no 8 KB skill cap any more: the pack ceiling grows with it
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
			// [2026-11-23 06:17 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.153 S153-K2 — the owner's "always" skills first (their text rides here, never on the Guru profile that reaches customers), then the rest
			$owner_text = array();
			if ( method_exists( 'BizCity_Skills_Service', 'standing' ) ) {
				foreach ( BizCity_Skills_Service::standing( 'owner' ) as $st ) {
					$owner_text[ $st['name'] ] = (string) $st['text'];
				}
			}
			$approved = BizCity_Skills_Service::list( array( 'status' => 'approved' ), self::MAX_ITEMS );
			usort( $approved, static function ( $a, $b ) {
				$x = ( 'always' === ( $a['apply'] ?? '' ) ? 0 : 1 ) <=> ( 'always' === ( $b['apply'] ?? '' ) ? 0 : 1 );
				return $x ?: ( (int) ( $a['priority'] ?? 100 ) <=> (int) ( $b['priority'] ?? 100 ) ?: strcmp( $a['name'], $b['name'] ) );
			} );
			foreach ( $approved as $s ) {
				$item = array(
					'name'        => (string) $s['name'],
					'description' => (string) $s['description'],
					'tools'       => array_values( (array) $s['tools'] ),
					'scope'       => (string) $s['scope'],
					'version'     => (int) $s['version'],
					'updated_at'  => (string) $s['updated_at'],
					'hash'        => (string) ( $s['hash'] ?? '' ), // [2026-10-11 Johnny Chu - Chu Hoàng Anh] PHASE-0.101 S102-L2/L3 — cell biết kế hoạch đã dịch còn đúng thân không
					// [2026-11-23 06:17 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.153 S153-K2 — targeting (an older cell ignores unknown keys)
					'apply'       => (string) ( $s['apply'] ?? 'on_match' ),
					'audience'    => (string) ( $s['audience'] ?? 'owner' ),
					'channels'    => array_values( (array) ( $s['channels'] ?? array() ) ),
					'roles'       => array_values( (array) ( $s['roles'] ?? array() ) ),
					'priority'    => (int) ( $s['priority'] ?? 100 ),
				);
				if ( isset( $owner_text[ $s['name'] ] ) ) {
					$item['text'] = $owner_text[ $s['name'] ];
				}
				// [2026-10-21 10:37 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.126 S126-K1 (BS-9) — the KN- code of the skill's definition row in the client's journal (the cell may cite it; it pins `version` from this pack)
				if ( class_exists( 'BizCity_Journal_Index' ) && class_exists( 'BizCity_Journal_Skill_Link' ) && function_exists( 'get_current_blog_id' ) ) {
					$def = BizCity_Journal_Database::instance()->get_by_idempotency_key( BizCity_Journal_Skill_Link::define_key( (int) get_current_blog_id(), (string) $s['name'] ) );
					if ( is_array( $def ) ) {
						$item['ref'] = BizCity_Journal_Index::ref_code( (string) $def['kind'], (int) $def['id'] );
					}
				}
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
