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
	const MAX_RECENT   = 20;  // v1 cap, kept for the constant's readers
	const MAX_DOCS     = 120; // [2026-10-17 08:40 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.121 B1-2 (S121-A2, I-1) — doc rows until the byte budget
	// [2026-10-20 10:53 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.126 S126-J5/M2 (doc 20 §3, doc 00 §5c, BS-10) — doc-index@3: + jobs[] (≤ 10 latest JB-), layer / tier / audience on every doc row,
	// one pack PER PRINCIPAL (owner · staff · customer) built through brain-access@1 — the site filters before packing, the cell never filters.
	const CONTRACT     = 'doc-index@3';
	const MAX_JOBS     = 10;
	const PRINCIPALS   = array( 'owner', 'staff', 'customer' );
	const SUMMARY_MAX  = 160;
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

	/**
	 * @param string $principal owner (default) · staff · customer — the pack is built FOR that principal: rows it may not meta.read never enter it.
	 * @return array{items:array,truncated:bool}
	 */
	public static function build( int $owner_user_id, string $principal = 'owner' ): array {
		if ( $owner_user_id <= 0 || ! self::available() ) {
			return array( 'items' => array(), 'truncated' => false );
		}
		$principal = in_array( $principal, self::PRINCIPALS, true ) ? $principal : 'owner';
		$cached = function_exists( 'get_transient' ) ? get_transient( self::cache_key( $owner_user_id, $principal ) ) : false;
		if ( is_array( $cached ) && isset( $cached['items'] ) ) {
			return $cached;
		}
		$db        = BizCity_Journal_Database::instance();
		$items     = array( array( 'row' => 'meta', 'contract' => self::CONTRACT, 'principal' => $principal, 'as_of' => gmdate( 'Y-m-d\TH:i:s\Z' ) ) ); // [2026-10-17 08:40 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.121 B1-2 (S121-A2, I-1)
		$bytes     = 2 + strlen( (string) wp_json_encode( $items[0] ) ) + 1;
		$truncated = false;
		$who       = class_exists( 'BizCity_Brain_Access' ) ? BizCity_Brain_Access::principal( array( 'role' => $principal, 'user_id' => 'customer' === $principal ? 0 : $owner_user_id ) ) : null;
		$may       = static function ( array $entry ) use ( $who ): bool {
			return null === $who || BizCity_Brain_Access::decide( $who, $entry, 'meta.read' )['allow'];
		};

		// the shelf (counts per kind) is the owner's view of their own book; a customer pack carries no shelf (counts of rows it may not see)
		foreach ( 'customer' === $principal ? array() : $db->shelf_rows( $owner_user_id, self::MAX_SHELF ) as $r ) {
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
		// [2026-10-17 08:40 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.121 B1-2 (S121-A2, I-1) — one `doc` row per document: code, kind, title, date, one-line summary, 2-4 key card
		// fields, tags, learning level, state, notebook. Pinned first, then contracts ending within 30 days, then newest.
		if ( ! $truncated && class_exists( 'BizCity_Journal_Index' ) ) {
			$docs = array();
			$jobs = array();
			foreach ( $db->register_rows( $owner_user_id, self::MAX_DOCS ) as $r ) {
				$entry = BizCity_Journal_Index::to_journal_entry( $r );
				if ( ! $may( $entry ) ) {
					continue; // brain-access@1: filtered at the source, before packing
				}
				// [2026-10-20 10:53 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.126 S126-J5 — the latest JB- headers go to their own shelf (jobs[]), never to the doc rows
				if ( 'job' === (string) ( $entry['layer'] ?? '' ) ) {
					if ( count( $jobs ) < self::MAX_JOBS && 'owner' === $principal ) {
						$jobs[] = self::job_item( $entry );
					}
					continue;
				}
				$docs[]  = self::doc_item( $entry ) + array(
					'_pinned' => ! empty( $entry['flags']['pinned'] ),
					'_end'    => is_scalar( $entry['card']['end_date'] ?? null ) ? (string) $entry['card']['end_date'] : '',
				);
			}
			foreach ( $jobs as $item ) {
				if ( ! self::push( $items, $bytes, $item, $truncated ) ) {
					break;
				}
			}
			foreach ( self::order_docs( $docs, gmdate( 'Y-m-d' ) ) as $item ) {
				if ( ! self::push( $items, $bytes, $item, $truncated ) ) {
					break;
				}
			}
		}
		if ( ! $truncated && 'customer' !== $principal ) {
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

		$out = array( 'items' => $items, 'truncated' => $truncated );
		if ( function_exists( 'set_transient' ) ) {
			set_transient( self::cache_key( $owner_user_id, $principal ), $out, self::CACHE_TTL );
		}
		return $out;
	}

	/**
	 * [2026-10-20 10:53 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.126 S126-J5 (doc 15 §6) — one `job` row from a job.run entry: code, goal, status, the product codes. No figures,
	 * no evidence content, no URL. Pure.
	 */
	public static function job_item( array $e ): array {
		$card = is_array( $e['card'] ?? null ) ? $e['card'] : array();
		return array(
			'row'         => 'job',
			'ref'         => (string) ( $e['ref_code'] ?? '' ),
			'entry_id'    => (int) ( $e['entry_id'] ?? 0 ),
			'goal'        => mb_substr( (string) ( $e['title'] ?? '' ), 0, 80 ),
			'status'      => (string) ( $card['status'] ?? '' ),
			'skill'       => (string) ( $card['skill'] ?? '' ),
			'steps'       => array( (int) ( $card['steps_done'] ?? 0 ), (int) ( $card['steps_total'] ?? 0 ) ),
			'output_refs' => array_slice( array_map( 'strval', (array) ( $card['output_refs'] ?? array() ) ), 0, 8 ),
			'updated_at'  => (string) ( $e['updated_at'] ?? '' ),
		);
	}

	/**
	 * [2026-10-17 08:40 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.121 B1-2 (S121-A2, I-1) — one `doc` row (I-1) from a journal-entry@1.1.0. Sensitive kinds (finance · health · child ·
	 * personal) carry only code, kind, title, date, state — never a summary or card values (R-JN-11). Never a URL / hash. Pure.
	 */
	public static function doc_item( array $e ): array {
		$orig      = is_array( $e['original'] ?? null ) ? $e['original'] : array();
		$code      = '' !== (string) ( $orig['doc_code'] ?? '' ) ? (string) $orig['doc_code'] : 'JE-' . (int) ( $e['entry_id'] ?? 0 );
		$sensitive = '' !== (string) ( $e['flags']['sensitive'] ?? '' );
		$item      = array(
			'row'      => 'doc',
			'code'     => $code,
			'entry_id' => (int) ( $e['entry_id'] ?? 0 ),
			'kind'     => (string) ( $e['kind'] ?? '' ),
			'title'    => mb_substr( (string) ( $e['title'] ?? '' ), 0, 120 ),
			'doc_date' => $e['doc_date'] ?? null,
			'state'    => (string) ( $e['state'] ?? 'captured' ),
		);
		if ( $sensitive ) {
			return $item;
		}
		$key  = array();
		$card = is_array( $e['card'] ?? null ) ? $e['card'] : array();
		$decl = class_exists( 'BizCity_Journal_Kinds' ) ? BizCity_Journal_Kinds::get( (string) $item['kind'] ) : null;
		foreach ( (array) ( $decl['card_fields'] ?? array() ) as $f ) {
			if ( count( $key ) >= 4 ) {
				break;
			}
			if ( in_array( $f, array( 'title', 'summary', 'doc_date' ), true ) || ! isset( $card[ $f ] ) || ! is_scalar( $card[ $f ] ) || '' === (string) $card[ $f ] ) {
				continue;
			}
			$key[ $f ] = mb_substr( (string) $card[ $f ], 0, 60 );
		}
		$item['summary']    = mb_substr( (string) ( $e['summary'] ?? '' ), 0, self::SUMMARY_MAX );
		$item['key']        = (object) $key;
		$item['tags']       = array_slice( array_map( 'strval', (array) ( $e['tags'] ?? array() ) ), 0, 6 );
		$item['level']      = (string) ( $e['learning']['level'] ?? 'L0' );
		$item['nb']         = (int) ( $e['kg']['notebook_ids'][0] ?? 0 );
		$item['updated_at'] = (string) ( $e['updated_at'] ?? '' );
		// [2026-10-20 10:53 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.126 S126-M2 (doc 20 §3) — doc-index@3: layer · tier (null outside documents) · audience · belongs · version{n} · job_ref
		$item['layer']      = (string) ( $e['layer'] ?? 'document' );
		$item['tier']       = $e['tier'] ?? null;
		$item['aud']        = (string) ( $e['audience'] ?? 'owner' );
		$item['belongs']    = array( 'nb' => (int) ( $e['belongs']['notebook_id'] ?? 0 ), 'grp' => (string) ( $e['belongs']['group']['gid'] ?? '' ), 'contact' => (int) ( $e['belongs']['contact_id'] ?? 0 ) );
		$item['ver']        = (int) ( $e['version']['n'] ?? 1 );
		if ( '' !== (string) ( $e['job']['job_ref'] ?? '' ) ) {
			$item['job_ref'] = (string) $e['job']['job_ref'];
		}
		return $item;
	}

	/**
	 * Registry order: pinned → contracts whose `end_date` (card) falls within 30 days → everything else in the given (newest
	 * first) order. Stable. Pure. `$docs` items may carry `_pinned` / `_end` hints; they are stripped from the output.
	 */
	public static function order_docs( array $docs, string $today ): array {
		$soon = gmdate( 'Y-m-d', (int) strtotime( $today . ' +30 days UTC' ) );
		$rank = static function ( array $d ) use ( $today, $soon ): int {
			if ( ! empty( $d['_pinned'] ) ) {
				return 0;
			}
			$end = (string) ( $d['_end'] ?? '' );
			return '' !== $end && $end >= $today && $end <= $soon ? 1 : 2;
		};
		$keyed = array();
		foreach ( array_values( $docs ) as $i => $d ) {
			$keyed[] = array( $rank( $d ), $i, $d );
		}
		usort( $keyed, static function ( $a, $b ) { return $a[0] <=> $b[0] ?: $a[1] <=> $b[1]; } );
		return array_map( static function ( $k ) { unset( $k[2]['_pinned'], $k[2]['_end'] ); return $k[2]; }, $keyed );
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

	/** Drop the cached packs (every principal) for one owner (called after every journal write). */
	public static function flush( int $owner_user_id ): void {
		if ( $owner_user_id > 0 && function_exists( 'delete_transient' ) ) {
			foreach ( self::PRINCIPALS as $p ) {
				delete_transient( self::cache_key( $owner_user_id, $p ) );
			}
		}
	}

	private static function cache_key( int $owner_user_id, string $principal = 'owner' ): string {
		return 'bizcity_journal_doc_index_v3_' . (int) get_current_blog_id() . '_' . $owner_user_id . '_' . $principal; // v3: doc-index@3 (PHASE-0.126), one per principal
	}

	private static function iso( string $mysql ): string {
		return '' === $mysql ? '' : gmdate( 'Y-m-d\TH:i:s\Z', (int) strtotime( $mysql . ' UTC' ) );
	}
}
