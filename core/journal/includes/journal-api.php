<?php
/**
 * Zalo Brain — the public journal API every business flow, entry point and extension plugin writes through (R-JOURNAL v1.1).
 * Copyright (c) 2026 Chu Hoàng Anh (Johnny Chu), registered No. 8877/2026/QTG. See NOTICE.md for authorship scope; LICENSE for terms.
 *
 * One function in, one action out:
 *   $entry = bizcity_journal_record( array( 'owner_user_id' => $uid, 'kind' => 'booking.table', 'idempotency_key' => 'plg:booking:123', 'title' => '…' ) );
 *   add_action( 'bizcity_journal_entry_added', function ( array $entry ) { … } );
 * A plugin declares its kinds first (manifest `journal_kinds` or filter `bizcity_journal_kinds`); an undeclared kind is refused
 * (R-JN-17), never silently filed as common.document. `source_type` outside the closed set becomes `plugin` (R-JN-19), and a
 * plugin caller gets `origin.surface = plugin:<slug>` so the register always says where a row came from.
 *
 * @package Bizcity_Twin_AI
 * @since   2026-10-17
 */

// [2026-10-17 07:44 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.121 S121-J5 (R-JN-12/13/17/19) — new file: bizcity_journal_record() + friends.

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'bizcity_journal_prepare_entry' ) ) {
	/**
	 * Pure half of bizcity_journal_record(): validates and normalizes the caller's entry (declared kind, idempotency key, plugin
	 * origin + key prefix). Returns the entry ready for BizCity_Journal_Index::add(), or WP_Error. No database.
	 *
	 * @return array|WP_Error
	 */
	function bizcity_journal_prepare_entry( array $entry ) {
		if ( ! class_exists( 'BizCity_Journal_Kinds' ) || ! class_exists( 'BizCity_Journal_Database' ) ) {
			return new WP_Error( 'journal_unavailable', 'Sổ ghi chép Zalo Brain chưa sẵn sàng trên site này.' );
		}
		$plugin = sanitize_key( (string) ( $entry['plugin'] ?? '' ) );
		$kind   = BizCity_Journal_Database::sanitize_kind( (string) ( $entry['kind'] ?? '' ) );
		if ( '' === $kind || ! BizCity_Journal_Kinds::is_declared( $kind ) ) {
			return new WP_Error( 'journal_kind_undeclared', sprintf( 'Loại thẻ "%s" chưa được khai báo (journal-kinds@1: manifest journal_kinds hoặc filter bizcity_journal_kinds).', $kind ) );
		}
		$key = trim( (string) ( $entry['idempotency_key'] ?? '' ) );
		if ( '' === $key ) {
			return new WP_Error( 'journal_invalid_entry', 'Thiếu idempotency_key (mỗi thứ đáng nhớ đúng một dòng).' );
		}
		if ( (int) ( $entry['owner_user_id'] ?? 0 ) <= 0 ) {
			return new WP_Error( 'journal_invalid_entry', 'Thiếu owner_user_id (mọi dòng có một chủ là WP user — ZBC-3).' );
		}
		if ( '' !== $plugin ) {
			$entry['source_type'] = 'plugin';
			$origin               = is_array( $entry['origin'] ?? null ) ? $entry['origin'] : array();
			$entry['origin']      = array_merge( $origin, array( 'surface' => 'plugin:' . $plugin ) );
			if ( 0 !== strpos( $key, 'plg:' . $plugin . ':' ) ) {
				$key = 'plg:' . $plugin . ':' . $key;
			}
		}
		$entry['kind']            = $kind;
		$entry['idempotency_key'] = substr( $key, 0, 191 );
		unset( $entry['plugin'] );
		return $entry;
	}
}

if ( ! function_exists( 'bizcity_journal_record' ) ) {
	/**
	 * Record ONE row in the Zalo Brain journal (`journal-entry@1.1.0`). Idempotent on `idempotency_key`.
	 *
	 * @param array $entry {owner_user_id:int, kind:string, idempotency_key:string, title?, summary?, doc_date?, tags?[], card?{},
	 *                      state?, original?{attachment_id, file_name, mime, size, sha256}, origin?{surface, ref}, source_type?,
	 *                      source_ref?, notebook_id?, kg_source_id?, subject?{type,hash}, by?{role,user_hash}, error?{code,message},
	 *                      plugin?: string (slug — sets source_type plugin + origin.surface plugin:<slug> + key prefix plg:<slug>:)}
	 * @return array|WP_Error the row as journal-entry@1.1.0, or WP_Error (journal_unavailable · journal_kind_undeclared · journal_invalid_entry …)
	 */
	function bizcity_journal_record( array $entry ) {
		if ( ! class_exists( 'BizCity_Journal_Index' ) ) {
			return new WP_Error( 'journal_unavailable', 'Sổ ghi chép Zalo Brain chưa sẵn sàng trên site này.' );
		}
		$prepared = bizcity_journal_prepare_entry( $entry );
		return is_wp_error( $prepared ) ? $prepared : BizCity_Journal_Index::add( $prepared );
	}
}

if ( ! function_exists( 'bizcity_journal_set_state' ) ) {
	/**
	 * Move a row along its life cycle (captured · review · verified · failed · void). `verified` needs the person (`$by_user_id`).
	 *
	 * @return array|WP_Error journal-entry@1.1.0
	 */
	function bizcity_journal_set_state( int $entry_id, int $owner_user_id, string $state, array $extra = array(), int $by_user_id = 0 ) {
		if ( ! class_exists( 'BizCity_Journal_Index' ) ) {
			return new WP_Error( 'journal_unavailable', 'Sổ ghi chép Zalo Brain chưa sẵn sàng trên site này.' );
		}
		return BizCity_Journal_Index::set_state( $entry_id, $owner_user_id, $state, $extra, $by_user_id );
	}
}

if ( ! function_exists( 'bizcity_journal_find' ) ) {
	/**
	 * Structured search over the register (R-JN-5): kind / kinds / kind_prefix / tags / subject_hash / from / to / q / limit ≤ 50 / offset.
	 *
	 * @return array{items:array,total:int}
	 */
	function bizcity_journal_find( int $owner_user_id, array $filter = array() ): array {
		if ( ! class_exists( 'BizCity_Journal_Index' ) ) {
			return array( 'items' => array(), 'total' => 0 );
		}
		return BizCity_Journal_Index::find( array_merge( $filter, array( 'owner_user_id' => $owner_user_id ) ) );
	}
}

if ( ! function_exists( 'bizcity_journal_register_row' ) ) {
	/** `doc-register@1` projection of one entry (Sheet / CSV / web). Pure. */
	function bizcity_journal_register_row( array $entry ): array {
		return class_exists( 'BizCity_Journal_Index' ) ? BizCity_Journal_Index::register_row( $entry ) : array();
	}
}
