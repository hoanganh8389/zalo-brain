<?php
/**
 * BizCity_Journal_Doc_Index_Pack_Exporter — projection-pack@1.3 kind `doc_index` (PHASE-0.100 S100-L18, doc 92 §5,
 * lanes/INTERFACES.md I-2, R-JOURNAL R-JN-8). Labels only — shelf / subject / recent rows — so the cell knows
 * what the client's journal index holds without ever receiving a card body (R-MEMORY-5-95).
 *
 * One copy per owner (mode `notebook`, audience `owner_agent`, like `notebook_meta`). Invalidated on every
 * `BizCity_Journal_Index::add()`/`file()` write with the same 60 s trailing debounce every pack kind uses
 * (`bizcity_twin_agent_packs_changed`).
 *
 * Biz Central Brain — Johnny Chu (Chu Hoàng Anh).
 *
 * // [2026-10-12 11:35 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.100 S100-L18 — new file.
 * // @axis twin-agent-axis@1 pack doc_index
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\KG_Hub
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Journal_Doc_Index_Pack_Exporter', false ) ) {
	return;
}

final class BizCity_Journal_Doc_Index_Pack_Exporter {

	const KIND        = 'doc_index';
	const MAX_SHELF    = 40;
	const MAX_SUBJECT  = 30;
	const MAX_RECENT   = 20;
	const MAX_BYTES    = 24576;
	const CACHE_TTL    = 60; // [2026-10-12 11:35 AM Johnny Chu - Chu Hoàng Anh] doc 92 §5 — ≤ 60 s after a write.

	public static function register(): void {
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
		return class_exists( 'BizCity_Journal_Database', false ) && class_exists( 'BizCity_Journal_Index', false );
	}

	/** @param array $ctx {owner_user_id, account_id, ...} */
	public static function stats( array $ctx ): array {
		$b    = self::build( (int) ( $ctx['owner_user_id'] ?? 0 ) );
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
		$b     = self::build( (int) ( $ctx['owner_user_id'] ?? 0 ) );
		$items = array_slice( $b['items'], max( 0, $after ), max( 1, $limit ) );
		$last  = max( 0, $after ) + count( $items );
		return array( 'items' => $items, 'last_id' => $last, 'more' => $last < count( $b['items'] ) );
	}

	/** @return array{items:array,truncated:bool} */
	public static function build( int $owner_user_id ): array {
		if ( $owner_user_id <= 0 || ! self::available() ) {
			return array( 'items' => array(), 'truncated' => false );
		}
		$cached = function_exists( 'get_transient' ) ? get_transient( self::cache_key( $owner_user_id ) ) : false;
		if ( is_array( $cached ) && isset( $cached['items'] ) ) {
			return $cached;
		}
		$db        = BizCity_Journal_Database::instance();
		$items     = array();
		$bytes     = 2;
		$truncated = false;

		foreach ( $db->shelf_rows( $owner_user_id, self::MAX_SHELF ) as $r ) {
			$kind = (string) $r['kind'];
			$item = array(
				'row'         => 'shelf',
				'notebook_id' => 0, // notebook linkage is many-to-many per entry; the shelf row itself is a kind aggregate, not one notebook (I-2 keeps the field for parity, 0 = "see journal.find").
				'title'       => mb_substr( class_exists( 'BizCity_Journal_Kinds' ) ? BizCity_Journal_Kinds::label( $kind ) : $kind, 0, 120 ),
				'kind'        => $kind,
				'count'       => (int) $r['c'],
				'from'        => (string) ( $r['d_from'] ?? '' ),
				'to'          => (string) ( $r['d_to'] ?? '' ),
				'top_tags'    => array(),
				'review'      => 0,
				'updated_at'  => self::iso( (string) ( $r['u'] ?? '' ) ),
			);
			if ( ! self::push( $items, $bytes, $item, $truncated ) ) {
				break;
			}
		}
		if ( ! $truncated ) {
			foreach ( $db->subject_rows( $owner_user_id, self::MAX_SUBJECT ) as $r ) {
				$item = array(
					'row'          => 'subject',
					'subject_hash' => (string) $r['subject_hash'],
					'label'        => '',
					'kinds'        => $r['kinds'],
					'last'         => (string) $r['last'],
				);
				if ( ! self::push( $items, $bytes, $item, $truncated ) ) {
					break;
				}
			}
		}
		if ( ! $truncated ) {
			foreach ( $db->recent_rows( $owner_user_id, self::MAX_RECENT ) as $r ) {
				$item = array(
					'row'      => 'recent',
					'entry_id' => (int) $r['id'],
					'kind'     => (string) $r['kind'],
					'title'    => mb_substr( (string) $r['title'], 0, 120 ),
					'doc_date' => $r['doc_date'] ?? null,
					'tags'     => array_slice( BizCity_Journal_Database::decode_tags( (string) $r['tags'] ), 0, 10 ),
				);
				if ( ! self::push( $items, $bytes, $item, $truncated ) ) {
					break;
				}
			}
		}

		$out = array( 'items' => $items, 'truncated' => $truncated );
		if ( function_exists( 'set_transient' ) ) {
			set_transient( self::cache_key( $owner_user_id ), $out, self::CACHE_TTL );
		}
		return $out;
	}

	/** Append `$item` unless it would push the pack past MAX_BYTES; returns false when the pack is full (stop paging). */
	private static function push( array &$items, int &$bytes, array $item, bool &$truncated ): bool {
		$len = strlen( (string) wp_json_encode( $item ) ) + 1;
		if ( $bytes + $len > self::MAX_BYTES ) {
			$truncated = true;
			return false;
		}
		$items[] = $item;
		$bytes  += $len;
		return true;
	}

	/** Drop the cached pack for one owner (called after every journal write). */
	public static function flush( int $owner_user_id ): void {
		if ( $owner_user_id > 0 && function_exists( 'delete_transient' ) ) {
			delete_transient( self::cache_key( $owner_user_id ) );
		}
	}

	private static function cache_key( int $owner_user_id ): string {
		return 'bizcity_journal_doc_index_v1_' . (int) get_current_blog_id() . '_' . $owner_user_id;
	}

	private static function iso( string $mysql ): string {
		return '' === $mysql ? '' : gmdate( 'Y-m-d\TH:i:s\Z', (int) strtotime( $mysql . ' UTC' ) );
	}
}
