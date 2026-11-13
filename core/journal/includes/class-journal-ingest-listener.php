<?php
/**
 * BizCity_Journal_Ingest_Listener — every notebook source gets its journal row (R-JOURNAL v1.1 R-JN-13, PHASE-0.121 S121-J4).
 * Copyright (c) 2026 Chu Hoàng Anh (Johnny Chu), registered No. 8877/2026/QTG. See NOTICE.md for authorship scope; LICENSE for terms.
 *
 * Before this listener only four writers produced journal rows (Zalo capture, journal.derive, track.set, context producers): a
 * document the owner uploaded on the web (TwinChat, upload-link, KG-Hub admin) never reached the register, so the cell could
 * not count it, the Sheet mirror never saw it and the web register was blind to it (doc 72 §5). One hook, one row:
 *   - `bizcity_twinchat_after_ingest` (modules/twinchat/includes/class-twinchat-sources-service.php) fires for EVERY source
 *     ingested into a notebook ⇒ a row keyed `src:<blog>:<webchat source id>` unless the source already has one (Zalo capture
 *     writes `oc:…` first and stamps `inbound.origin = owner_capture`; a duplicate ingest never adds a row);
 *   - `bizcity_twinchat_after_source_delete` ⇒ the row turns `void` (kept, R-JN-14).
 * No model call, no file read: kind comes from the payload type / MIME; the summary and card arrive later from the cell
 * (`journal_note`) exactly as for Zalo captures.
 *
 * @package Bizcity_Twin_AI
 * @since   2026-10-17
 */

// [2026-10-17 07:44 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.121 S121-J4 (R-JN-13) — new file.

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Journal_Ingest_Listener', false ) ) {
	return;
}

final class BizCity_Journal_Ingest_Listener {

	private static $hooked = false;

	public static function register(): void {
		if ( self::$hooked || ! function_exists( 'add_action' ) ) {
			return;
		}
		self::$hooked = true;
		// after TwinChat's own listeners (learning queue @10): the row must exist before anyone reports on it
		add_action( 'bizcity_twinchat_after_ingest', array( __CLASS__, 'on_after_ingest' ), 20, 4 );
		add_action( 'bizcity_twinchat_after_source_delete', array( __CLASS__, 'on_source_delete' ), 20, 2 );
	}

	// ── pure ─────────────────────────────────────────────────────────────

	/** `src:<blog>:<source_id>` — the idempotency key of a web-ingested source. */
	public static function key_for( int $blog_id, int $source_id ): string {
		return 'src:' . max( 0, $blog_id ) . ':' . max( 0, $source_id );
	}

	/**
	 * Should this ingest produce a row? false when: no source id · duplicate ingest · the Zalo capture path already wrote one
	 * (`metadata.inbound.origin = owner_capture`) · chat-memory / system sources (type `memory`, `chat`).
	 */
	public static function should_record( array $result, array $payload ): bool {
		if ( (int) ( $result['source_id'] ?? 0 ) <= 0 || ! empty( $result['duplicate'] ) ) {
			return false;
		}
		$meta = is_array( $payload['metadata'] ?? null ) ? $payload['metadata'] : array();
		if ( 'owner_capture' === (string) ( $meta['inbound']['origin'] ?? '' ) ) {
			return false;
		}
		// [2026-10-25 01:18 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.137 S137-B6 (D137-7) — a source ingested FROM a journal row's Markdown ("Nghiên cứu sâu") already has its row: never open a second one
		if ( (int) ( $meta['journal_entry_id'] ?? 0 ) > 0 ) {
			return false;
		}
		return ! in_array( (string) ( $payload['type'] ?? '' ), array( 'memory', 'chat', 'session' ), true );
	}

	/** journal kind from the ingest payload: file by MIME / extension, url ⇒ common.link, youtube ⇒ common.link, text ⇒ common.note. */
	public static function kind_for( array $payload ): string {
		$type = (string) ( $payload['type'] ?? 'file' );
		if ( in_array( $type, array( 'url', 'youtube', 'link' ), true ) ) {
			return 'common.link';
		}
		if ( 'text' === $type ) {
			return 'common.note';
		}
		$meta = is_array( $payload['metadata'] ?? null ) ? $payload['metadata'] : array();
		$mime = strtolower( (string) ( $meta['mime'] ?? $payload['file']['type'] ?? '' ) );
		$name = strtolower( (string) ( $meta['file_name'] ?? $payload['file']['name'] ?? $payload['title'] ?? '' ) );
		$ext  = (string) pathinfo( $name, PATHINFO_EXTENSION );
		if ( 0 === strpos( $mime, 'image/' ) || in_array( $ext, array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'heic' ), true ) ) {
			return 'common.photo';
		}
		if ( 0 === strpos( $mime, 'audio/' ) || in_array( $ext, array( 'mp3', 'm4a', 'wav', 'ogg', 'aac', 'amr' ), true ) ) {
			return 'common.voice_note';
		}
		return 'common.document';
	}

	/** The journal-entry@1.1 input for one ingested source. Pure. */
	public static function entry_for( int $blog_id, int $notebook_id, int $user_id, array $result, array $payload ): array {
		$meta      = is_array( $payload['metadata'] ?? null ) ? $payload['metadata'] : array();
		$attach_id = (int) ( $payload['attachment_id'] ?? $meta['evidence_attachment_id'] ?? 0 );
		$file_name = (string) ( $meta['file_name'] ?? $payload['file']['name'] ?? '' );
		$title     = trim( (string) ( $payload['title'] ?? '' ) );
		if ( '' === $title ) {
			$title = '' !== $file_name ? $file_name : (string) ( $payload['url'] ?? 'Tài liệu' );
		}
		$surface = (string) ( $meta['inbound']['origin'] ?? '' );
		return array(
			'owner_user_id'   => $user_id,
			'kind'            => self::kind_for( $payload ),
			'title'           => mb_substr( $title, 0, 255 ),
			'idempotency_key' => self::key_for( $blog_id, (int) $result['source_id'] ),
			'source_type'     => 'web_upload',
			'origin'          => array( 'surface' => '' !== $surface ? $surface : 'web_upload', 'ref' => 'webchat_source:' . (int) $result['source_id'] ),
			'notebook_id'     => $notebook_id,
			'kg_source_id'    => (int) ( $result['kg_source_id'] ?? 0 ),
			'original'        => array(
				'attachment_id' => $attach_id,
				'doc_code'      => (string) ( $meta['sb_doc_code'] ?? '' ),
				'file_name'     => $file_name,
				'mime'          => (string) ( $meta['mime'] ?? $payload['file']['type'] ?? '' ),
				'size'          => (int) ( $meta['sb_size'] ?? $payload['file']['size'] ?? 0 ),
				'sha256'        => (string) ( $meta['sb_sha256'] ?? '' ),
			),
			'learning'        => array( 'level' => 'L1', 'text_ready' => true, 'passages' => (int) ( $result['chunk_count'] ?? 0 ), 'embedded_at' => gmdate( 'c' ) ),
			'by'              => array( 'role' => 'owner' ),
			'confidence'      => 1.0, // no card yet: the row is a pointer, not a classification — never flagged review for that
		);
	}

	// ── hooks ────────────────────────────────────────────────────────────

	/** @param int $scope_id notebook id · @param array $result {source_id, kg_source_id, chunk_count, duplicate} · @param array $payload ingest payload */
	public static function on_after_ingest( $scope_id, $user_id, $result, $payload ): void {
		if ( ! is_array( $result ) || ! is_array( $payload ) || ! class_exists( 'BizCity_Journal_Index' ) || ! self::should_record( $result, $payload ) ) {
			return;
		}
		$user_id = (int) $user_id;
		if ( $user_id <= 0 ) {
			return;
		}
		$blog = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
		// a row that already points at this KG source (e.g. the Zalo capture wrote it under oc:…) is enough
		$kg = (int) ( $result['kg_source_id'] ?? 0 );
		if ( $kg > 0 && class_exists( 'BizCity_Journal_Database' ) && BizCity_Journal_Database::instance()->get_by_kg_source_id( $kg ) ) {
			return;
		}
		$res = BizCity_Journal_Index::add( self::entry_for( $blog, (int) $scope_id, $user_id, $result, $payload ) );
		if ( is_wp_error( $res ) && function_exists( 'error_log' ) ) {
			error_log( '[journal-ingest] ' . $res->get_error_code() . ' source=' . (int) $result['source_id'] );
		}
	}

	/** Source removed from the notebook ⇒ its row turns `void` (kept for history). */
	public static function on_source_delete( $source_id, $project_id ): void {
		if ( ! class_exists( 'BizCity_Journal_Index' ) || ! class_exists( 'BizCity_Journal_Database' ) ) {
			return;
		}
		$blog = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
		$row  = BizCity_Journal_Database::instance()->get_by_idempotency_key( self::key_for( $blog, (int) $source_id ) );
		if ( ! $row || 'void' === (string) ( $row['status'] ?? '' ) ) {
			return;
		}
		BizCity_Journal_Index::set_state( (int) $row['id'], (int) $row['owner_user_id'], 'void' );
	}
}
