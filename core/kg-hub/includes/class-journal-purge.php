<?php
/**
 * Xoá thật MỘT mục của Bộ não thứ hai (PHASE-0.122 D122-14, doc 20 §7 — owner 2026-10-18: "Xoá tài liệu trong bảng nhanh là xoá thật").
 *
 * Two steps, the second only with the code of the first (the cell checks the OWNER typed "XOÁ <mã>" in their own message):
 *   preview( id, owner )            ⇒ {code JE-n, title, has_file, derived_count, confirm_code}  (code kept 10 min, bound to owner + entry)
 *   purge( id, owner, confirm_code ) ⇒ {steps[]}  each step: id + done (true / false / 'skip') + note
 *
 * What goes, in this order (each step independent; one failing never stops the next):
 *   file     the private original (attachment + its bytes on disk)
 *   kg       the notebook source: passages (+ their entity / relation / identity / provenance / triplet links, chunk files), the source
 *            row (its content_text), its notebook links, its body file
 *   journal  the row becomes a TOMBSTONE: id, JE code, kind, owner, dates stay; title / body / summary / card / tags / file refs are wiped
 *            (R-JOURNAL: the book keeps its numbering — Q122-11)
 *   cell     the cell's copy (projection pack `doc_index`) is dropped and re-pushed (`bizcity_twin_agent_packs_changed`), so the assistant forgets it
 *   sheet    the Google Sheet mirror row (written by the cell, docs_v1): no executor deletes a Sheet row yet ⇒ NOT done, the owner is told which row
 *            (`row_ref`) to delete by hand; `bizcity_journal_purged` fires for any listener (entry before wipe, owner)
 * [2026-10-18 08:50 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.122 W3 — mirror split into cell (done) + sheet (honest).
 * Graph entities / relations other passages also feed are NOT deleted (they are not this document's text).
 *
 * Only the owner's OWN rows (BizCity_Journal_Index::get with the owner id); staff-sent rows: not in this step.
 *
 * [2026-10-18 07:30 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.122 S122-K1 — new file. Loaded by the kg-hub module map.
 *
 * @package BizCity_Twin_AI
 * @subpackage KG_Hub
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_Journal_Purge {

	const CODE_TTL = 600;
	const T_CODE   = 'bzc_jp_';
	const T_TRY    = 'bzc_jt_';
	const MAX_TRY  = 5; // wrong codes per preview (10 000 possible codes: a guesser gets 5 tries, then the code is gone)
	const HOOK     = 'bizcity_journal_purged';
	const MCP_TOOL = 'journal.purge';

	/** @var array|null tests: tget / tset / tdel / rand4 / entry(id, owner) / steps(entry, owner) */
	public static $seam = null;

	public static function init(): void {
		add_action( 'bizcity_mcp_register_tools', array( __CLASS__, 'register_mcp_tool' ) );
	}

	/** Pure: "JE-15" / "je 15" / "15" ⇒ 15; "TL-…" is a file code (resolved by the caller); else 0. */
	public static function id_of( string $ref ): int {
		return 1 === preg_match( '/^\s*(?:je[\s\-_]*)?(\d{1,12})\s*$/i', $ref, $m ) ? (int) $m[1] : 0;
	}

	/**
	 * Step 1: what would go, and a fresh 4-digit code.
	 *
	 * @return array|WP_Error
	 */
	public static function preview( int $entry_id, int $owner ) {
		$e = self::entry( $entry_id, $owner );
		if ( ! $e ) {
			return new WP_Error( 'not_found', 'Không có mục này trong sổ của anh/chị.', array( 'status' => 404 ) );
		}
		if ( self::is_tombstone( $e ) ) {
			return new WP_Error( 'already_purged', 'Mục này đã xoá hẳn rồi.', array( 'status' => 410 ) );
		}
		$code = self::rand4();
		self::tset( self::T_CODE . $owner . '_' . $entry_id, $code, self::CODE_TTL );
		return array(
			'entry_id'      => $entry_id,
			'code'          => 'JE-' . $entry_id,
			'title'         => mb_substr( (string) ( $e['title'] ?? '' ), 0, 120 ),
			'has_file'      => (int) ( $e['original']['attachment_id'] ?? 0 ) > 0,
			'derived_count' => count( (array) ( $e['derived_from'] ?? array() ) ),
			'confirm_code'  => $code,
			'expires_in'    => self::CODE_TTL,
		);
	}

	/**
	 * Step 2: delete for real. The code is used once.
	 *
	 * @return array|WP_Error {entry_id, code, steps[]}
	 */
	public static function purge( int $entry_id, int $owner, string $confirm_code ) {
		$key  = self::T_CODE . $owner . '_' . $entry_id;
		$want = (string) self::tget( $key );
		if ( '' === $want || ! hash_equals( $want, trim( $confirm_code ) ) ) {
			// [2026-10-18 11:10 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.122 review fix — five wrong codes burn the preview
			if ( '' !== $want ) {
				$tries = (int) self::tget( self::T_TRY . $owner . '_' . $entry_id ) + 1;
				if ( $tries >= self::MAX_TRY ) {
					self::tdel( $key );
					self::tdel( self::T_TRY . $owner . '_' . $entry_id );
				} else {
					self::tset( self::T_TRY . $owner . '_' . $entry_id, $tries, self::CODE_TTL );
				}
			}
			return new WP_Error( 'confirm_mismatch', 'Mã xác nhận không đúng hoặc đã hết hạn (10 phút). Nhờ trợ lý hỏi lại.', array( 'status' => 409 ) );
		}
		self::tdel( $key );
		self::tdel( self::T_TRY . $owner . '_' . $entry_id );
		$e = self::entry( $entry_id, $owner );
		if ( ! $e ) {
			return new WP_Error( 'not_found', 'Không có mục này trong sổ của anh/chị.', array( 'status' => 404 ) );
		}
		$steps = isset( self::$seam['steps'] ) ? (array) call_user_func( self::$seam['steps'], $e, $owner ) : self::run_steps( $e, $owner );
		return array( 'entry_id' => $entry_id, 'code' => 'JE-' . $entry_id, 'steps' => $steps );
	}

	/** Pure: a row already wiped by purge(). */
	public static function is_tombstone( array $e ): bool {
		return ! empty( $e['card']['purged_at'] ) || ! empty( $e['purged_at'] );
	}

	/** Pure: file + kg steps both done / skipped. */
	public static function may_tombstone( array $steps ): bool {
		foreach ( $steps as $s ) {
			if ( in_array( $s['id'], array( 'file', 'kg' ), true ) && true !== $s['done'] && 'skip' !== $s['done'] ) {
				return false;
			}
		}
		return true;
	}

	/** Pure: the Sheet mirror step — nothing mirrored ⇒ skip; a mirrored row ⇒ not done, the note names the row to delete by hand. @return true|'skip'|string */
	public static function sheet_step( array $mirror ) {
		$ref = (string) ( $mirror['row_ref'] ?? '' );
		if ( 'ok' !== (string) ( $mirror['sheet'] ?? 'off' ) || '' === $ref ) {
			return 'skip';
		}
		return 'row:' . substr( preg_replace( '/[^A-Za-z0-9!:_.-]/', '', $ref ), 0, 60 );
	}

	/** Pure: one sentence for the owner from the steps. */
	public static function summary( array $steps ): string {
		$label = array( 'file' => 'file gốc', 'kg' => 'bản tách chữ và chỉ mục', 'journal' => 'nội dung trong sổ', 'cell' => 'bản trợ lý nhớ', 'sheet' => 'dòng Google Sheet', 'mirror' => 'dòng Google Sheet / bản ở trợ lý' );
		$done  = array();
		$left  = array();
		foreach ( $steps as $s ) {
			$name = $label[ $s['id'] ] ?? $s['id'];
			if ( true === $s['done'] ) {
				$done[] = $name;
			} elseif ( false === $s['done'] ) {
				$left[] = $name;
			}
		}
		$out = $done ? 'Đã xoá hẳn: ' . implode( ', ', $done ) . '.' : 'Chưa xoá được gì.';
		foreach ( $steps as $s ) {
			if ( 'sheet' === $s['id'] && false === $s['done'] && 0 === strpos( (string) $s['note'], 'row:' ) ) {
				$out .= ' Dòng trong Google Sheet sổ tài liệu (' . substr( (string) $s['note'], 4 ) . ') anh/chị tự xoá giúp — trợ lý chưa xoá dòng Sheet được.';
				$left = array_values( array_diff( $left, array( $label['sheet'] ) ) );
			}
		}
		return $left ? $out . ' Chưa xoá được: ' . implode( ', ', $left ) . ' — báo quản trị.' : $out;
	}

	// ── the steps (WordPress) ────────────────────────────────────────────

	private static function run_steps( array $e, int $owner ): array {
		$steps = array();
		$steps[] = self::step( 'file', static function () use ( $e, $owner ) { return self::delete_file( (int) ( $e['original']['attachment_id'] ?? 0 ), $owner, (int) $e['entry_id'] ); } );
		$steps[] = self::step( 'kg', static function () use ( $e, $owner ) { return self::delete_kg_source( (int) ( $e['kg']['source_id'] ?? 0 ), $owner, (int) $e['entry_id'] ); } );
		// [2026-10-18 11:10 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.122 review fix — the row becomes a tombstone only when the file and the KG source are really gone (or were never there); else it stays, so the owner can try again
		$steps[] = self::step( 'journal', static function () use ( $e, $owner, $steps ) {
			return self::may_tombstone( $steps ) ? self::tombstone( (int) $e['entry_id'], $owner ) : 'kept_for_retry';
		} );
		$steps[] = self::step( 'cell', static function () use ( $owner ) {
			if ( class_exists( 'BizCity_Journal_Doc_Index_Pack_Exporter' ) ) {
				BizCity_Journal_Doc_Index_Pack_Exporter::flush( $owner );
			}
			do_action( 'bizcity_twin_agent_packs_changed', array( 'doc_index' ) );
			return true;
		} );
		$steps[] = self::step( 'sheet', static function () use ( $e, $owner ) {
			do_action( self::HOOK, $e, $owner );
			return self::sheet_step( (array) ( $e['mirror'] ?? array() ) );
		} );
		return $steps;
	}

	/** @param callable $fn returns true | 'skip' | string note (= failed) */
	private static function step( string $id, callable $fn ): array {
		try {
			$r = $fn();
		} catch ( \Throwable $t ) {
			$r = 'error';
		}
		if ( true === $r || 'skip' === $r ) {
			return array( 'id' => $id, 'done' => $r, 'note' => '' );
		}
		return array( 'id' => $id, 'done' => false, 'note' => substr( (string) $r, 0, 80 ) );
	}

	/** @return true|'skip'|string */
	private static function delete_file( int $attachment_id, int $owner = 0, int $entry_id = 0 ) {
		if ( $attachment_id <= 0 ) {
			return 'skip';
		}
		// [2026-10-18 11:10 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.122 review fix — only the owner's own PRIVATE original, and never one another row still points at
		if ( ! class_exists( 'BizCity_KG_Private_File_Store' ) || ! BizCity_KG_Private_File_Store::is_private( $attachment_id ) || (int) get_post_meta( $attachment_id, BizCity_KG_Private_File_Store::META_OWNER, true ) !== $owner ) {
			return 'not_owner';
		}
		if ( self::shared_elsewhere( $entry_id, 0, $attachment_id ) ) {
			return 'skip';
		}
		$path = get_attached_file( $attachment_id );
		$ok   = wp_delete_attachment( $attachment_id, true );
		if ( $path && is_file( $path ) ) {
			@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- the private store may sit outside the media sizes WP removes
		}
		return ( $ok || ! get_post( $attachment_id ) ) && ! ( $path && is_file( $path ) ) ? true : 'file_left';
	}

	/** @return true|'skip'|string */
	private static function delete_kg_source( int $source_id, int $owner, int $entry_id = 0 ) {
		if ( $source_id <= 0 || ! class_exists( 'BizCity_KG_Database' ) ) {
			return 'skip';
		}
		global $wpdb;
		$db  = BizCity_KG_Database::instance();
		$src = $wpdb->get_row( $wpdb->prepare( "SELECT id, user_id FROM {$db->tbl_sources()} WHERE id = %d", $source_id ), ARRAY_A );
		if ( ! $src ) {
			return 'skip';
		}
		if ( (int) $src['user_id'] !== $owner ) {
			return 'not_owner'; // never another person's source — and never an unowned / shared one (user_id NULL or 0)
		}
		if ( self::shared_elsewhere( $entry_id, $source_id, 0 ) ) {
			return 'skip'; // another journal row still uses this source
		}
		$notebooks = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT notebook_id FROM {$db->tbl_notebook_sources()} WHERE source_id = %d", $source_id ) ) );
		$passages  = (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, notebook_id FROM {$db->tbl_passages()} WHERE source_id = %d", $source_id ), ARRAY_A );
		$ids       = array_map( static function ( $p ) { return (int) $p['id']; }, $passages );
		foreach ( array_chunk( $ids, 200 ) as $chunk ) {
			$in = implode( ',', array_map( 'intval', $chunk ) );
			foreach ( array( $db->tbl_passage_entities(), $db->tbl_passage_relations(), $db->tbl_provenance(), $db->tbl_triplet_queue(), $db->tbl_passage_identities() ) as $t ) {
				$wpdb->query( "DELETE FROM {$t} WHERE passage_id IN ({$in})" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- ints only
			}
			$wpdb->query( "DELETE FROM {$db->tbl_passages()} WHERE id IN ({$in})" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- ints only
		}
		if ( class_exists( 'BizCity_KG_Source_Body_File_Store' ) ) {
			foreach ( $passages as $p ) {
				BizCity_KG_Source_Body_File_Store::delete_chunk( (int) $p['notebook_id'], (int) $p['id'] );
			}
			foreach ( $notebooks as $nb ) {
				BizCity_KG_Source_Body_File_Store::delete_source( $nb, $source_id );
			}
		}
		$wpdb->delete( $db->tbl_notebook_sources(), array( 'source_id' => $source_id ) );
		$wpdb->delete( $db->tbl_sources(), array( 'id' => $source_id ) );
		$left = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$db->tbl_passages()} WHERE source_id = %d", $source_id ) );
		return 0 === $left ? true : 'passages_left';
	}

	/** @return true|string */
	private static function tombstone( int $entry_id, int $owner ) {
		if ( ! class_exists( 'BizCity_Journal_Database' ) ) {
			return 'journal_missing';
		}
		$r = BizCity_Journal_Database::instance()->update( $entry_id, $owner, array(
			'title'    => '[đã xoá]',
			'body'     => '',
			'status'   => 'void',
			'tags'     => array(),
			'metadata' => array( 'card' => array( 'purged_at' => gmdate( 'c' ), 'purged_by' => $owner ) ),
		) );
		return is_wp_error( $r ) ? $r->get_error_code() : true;
	}

	/** Does any OTHER journal row point at this KG source or attachment? */
	private static function shared_elsewhere( int $entry_id, int $source_id, int $attachment_id ): bool {
		if ( isset( self::$seam['shared'] ) ) {
			return (bool) call_user_func( self::$seam['shared'], $entry_id, $source_id, $attachment_id );
		}
		if ( ! class_exists( 'BizCity_Journal_Database' ) ) {
			return false;
		}
		global $wpdb;
		$t = BizCity_Journal_Database::instance()->get_table();
		if ( $source_id > 0 && (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE kg_source_id = %d AND id <> %d", $source_id, $entry_id ) ) > 0 ) {
			return true;
		}
		if ( $attachment_id > 0 ) {
			$like = array( '%' . $wpdb->esc_like( '"attachment_id":' . $attachment_id . ',' ) . '%', '%' . $wpdb->esc_like( '"attachment_id":' . $attachment_id . '}' ) . '%' );
			return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE id <> %d AND ( metadata LIKE %s OR metadata LIKE %s )", $entry_id, $like[0], $like[1] ) ) > 0;
		}
		return false;
	}

	// ── MCP ──────────────────────────────────────────────────────────────

	public static function register_mcp_tool(): void {
		if ( ! class_exists( 'BizCity_MCP_Tool_Registry' ) ) {
			return;
		}
		$R = 'BizCity_MCP_Tool_Registry';
		// @mcp bizcity-mcp-standard@1 tool journal.purge
		$R::register( self::MCP_TOOL, array(
			'executor'       => 'journal.entry.purge',
			'title'          => 'Xoá hẳn một mục Bộ não thứ hai',
			'description'    => 'Hai bước: không có confirm_code ⇒ xem trước + mã 4 số; có mã (chủ tự gõ "XOÁ <mã>") ⇒ xoá thật file gốc, bản tách chữ, chỉ mục; dòng sổ thành bia không nội dung. Chỉ chủ, chỉ mục của chính mình.',
			'input_schema'   => array( 'type' => 'object', 'required' => array( 'ref' ), 'properties' => array(
				'ref'          => array( 'type' => 'string', 'maxLength' => 24 ),
				'confirm_code' => array( 'type' => 'string', 'pattern' => '^[0-9]{4}$' ),
			) ),
			'output_schema'  => $R::envelope_schema( array(
				'entry_id'     => array( 'type' => 'integer' ),
				'code'         => array( 'type' => 'string' ),
				'title'        => array( 'type' => 'string' ),
				'confirm_code' => array( 'type' => 'string' ),
				'steps'        => array( 'type' => 'array' ),
				'summary'      => array( 'type' => 'string' ),
			), array( 'code' ) ),
			'read_only'      => false,
			'destructive'    => true,
			'idempotent'     => false,
			'required_scope' => 'memory.write',
			'handler'        => array( __CLASS__, 'mcp' ),
			'mode'           => 'notebook',
			'roles'          => array( 'owner' ),
			'scopes'         => array( 'memory.write' ),
			'confirm'        => 'never', // the two steps live inside the tool (code bound to owner + entry, typed by the owner)
			'llm_alias'      => 'delete_document',
			'fallback_pack'  => null,
			'since'          => '0.122.0',
		) );
	}

	/** @return array|WP_Error */
	public static function mcp( array $args, array $ctx ) {
		$owner = (int) ( $ctx['user_id'] ?? 0 );
		if ( 'owner' !== (string) ( $ctx['role'] ?? '' ) || $owner <= 0 ) {
			return new WP_Error( 'not_owner', 'Chỉ chủ số mới xoá hẳn được tài liệu.', array( 'status' => 403 ) );
		}
		$ref = trim( (string) ( $args['ref'] ?? '' ) );
		$id  = self::id_of( $ref );
		if ( 0 === $id && 1 === preg_match( '/^\s*tl[\s\-_]*\d{1,12}\s*$/i', $ref ) && class_exists( 'BizCity_Journal_Database' ) ) {
			$row = BizCity_Journal_Database::instance()->get_by_source_ref( 'TL-' . (int) preg_replace( '/\D/', '', $ref ) );
			$id  = $row && (int) $row['owner_user_id'] === $owner ? (int) $row['id'] : 0;
		}
		if ( $id <= 0 ) {
			return new WP_Error( 'not_found', 'Không có mục này trong sổ của anh/chị (mã dạng JE-15 hoặc TL-123).', array( 'status' => 404 ) );
		}
		$code = (string) ( $args['confirm_code'] ?? '' );
		if ( '' === $code ) {
			return self::preview( $id, $owner );
		}
		$r = self::purge( $id, $owner, $code );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		$r['summary'] = self::summary( $r['steps'] );
		return $r;
	}

	// ── storage (seam) ───────────────────────────────────────────────────

	private static function entry( int $id, int $owner ): ?array {
		if ( isset( self::$seam['entry'] ) ) {
			$e = call_user_func( self::$seam['entry'], $id, $owner );
			return is_array( $e ) ? $e : null;
		}
		if ( ! class_exists( 'BizCity_Journal_Index' ) ) {
			return null;
		}
		$e = BizCity_Journal_Index::get( $id, $owner );
		return is_array( $e ) && ! isset( $e['code'] ) ? $e : null;
	}

	private static function rand4(): string {
		return isset( self::$seam['rand4'] ) ? (string) call_user_func( self::$seam['rand4'] ) : str_pad( (string) random_int( 0, 9999 ), 4, '0', STR_PAD_LEFT );
	}

	private static function tget( string $k ) {
		return isset( self::$seam['tget'] ) ? call_user_func( self::$seam['tget'], $k ) : get_transient( $k );
	}

	private static function tset( string $k, $v, int $ttl ): void {
		isset( self::$seam['tset'] ) ? call_user_func( self::$seam['tset'], $k, $v, $ttl ) : set_transient( $k, $v, $ttl );
	}

	private static function tdel( string $k ): void {
		isset( self::$seam['tdel'] ) ? call_user_func( self::$seam['tdel'], $k ) : delete_transient( $k );
	}
}
