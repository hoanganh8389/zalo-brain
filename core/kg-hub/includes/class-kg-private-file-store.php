<?php
/**
 * Bizcity Twin AI — originals of documents the owner sends the bot ("Bộ não thứ hai", PHASE-0.121 W1).
 * Copyright (c) 2026 Chu Hoàng Anh (Johnny Chu), registered No. 8877/2026/QTG. See NOTICE.md for authorship scope; LICENSE for terms.
 *
 * Before W1 a document sent in the owner's Zalo 1-1 (`owner-capture@1`) went through `media_handle_sideload()` under its own
 * readable name, and every WP API (media library, REST, notebook evidence) handed out its URL — anyone holding it could read
 * a contract. This store keeps the ORIGINAL of such documents instead:
 *   - checked first (allow-listed extension + sniffed content, ≤ 20 MB) — a renamed executable is refused;
 *   - sha256 + size + MIME recorded; the same bytes in the same workspace are stored once (D121-5);
 *   - written where WordPress puts any media (`wp_upload_dir()`: `uploads[/sites/<id>]/<yyyy>/<mm>/`), so a site that syncs
 *     media to R2 (`mu-plugins/bizcity-r2.php`) keeps it on R2 like every other file, and a plain VPS keeps it on disk —
 *     owner decision 2026-10-17 (works for the public repo without R2);
 *   - the stored name carries a 96-bit random token (`<token>-<ascii name>.<ext>`): the real storage URL is not guessable and
 *     is NEVER handed out — `wp_get_attachment_url()` of such a document is the owner-only REST route below;
 *   - a WP attachment (`post_status=private`), so the notebook ingest reuses its attachment_id path unchanged.
 * The cell reads / re-sends a document through a short signed link (`signed_url()`); the route streams the local file, or
 * fetches the R2 copy server-side when the local one was offloaded — the R2 URL never leaves the site.
 *
 * Scope W1: kind `file` from `owner_capture` only (images / voice keep the old path — GAP-121-13). Way back: filter
 * `bizcity_second_brain_private_store` = false ⇒ the old sideload, no receipts.
 *
 * @package Bizcity_Twin_AI
 * @since   2026-10-17
 */

// [2026-10-17 04:22 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.121 S121-L1/L2 — private original + sha256 + dedupe (doc 30 §2-§3).
// [2026-10-17 05:35 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.121 Q121-1 (chủ chốt) — lưu theo đường media chuẩn của WP (R2 nếu site có, đĩa nếu không); bỏ thư mục bizcity-private.

defined( 'ABSPATH' ) or die( 'OOPS...' );

if ( class_exists( 'BizCity_KG_Private_File_Store', false ) ) {
	return;
}

final class BizCity_KG_Private_File_Store {

	const FILTER_ENABLED = 'bizcity_second_brain_private_store';
	const MAX_BYTES      = 20 * 1024 * 1024; // the cell never forwards a bigger file (MAX_DOCUMENT_BYTES)
	const SIGNED_TTL     = 900;              // 15 min (doc 30 §2)
	const REST_NS        = 'bizcity-knowledge/v2';
	const REST_ROUTE     = '/second-brain/file/(?P<id>\d+)';

	const META_PRIVATE   = '_bizcity_sb_private';
	const META_SHA256    = '_bizcity_sb_sha256';
	const META_SIZE      = '_bizcity_sb_size';
	const META_MIME      = '_bizcity_sb_mime';
	const META_WORKSPACE = '_bizcity_sb_workspace';
	const META_OWNER     = '_bizcity_sb_owner_user_id';
	/** `mu-plugins/bizcity-r2.php` META_OFFLOADED: the copy lives on R2 (the local one may be gone). */
	const META_R2        = '_bizcity_r2_offloaded_v1';

	/**
	 * ext ⇒ sniffed MIME prefixes accepted for it. Office "x" formats are zip containers; legacy Office files are OLE (CDF).
	 * Text formats only need to be text. Anything sniffed as an executable/script is refused whatever its name (EXEC_MIMES).
	 */
	const ALLOWED = array(
		'pdf'  => array( 'application/pdf' ),
		'docx' => array( 'application/vnd.openxmlformats-officedocument', 'application/zip', 'application/octet-stream' ),
		'xlsx' => array( 'application/vnd.openxmlformats-officedocument', 'application/zip', 'application/octet-stream' ),
		'pptx' => array( 'application/vnd.openxmlformats-officedocument', 'application/zip', 'application/octet-stream' ),
		'doc'  => array( 'application/msword', 'application/x-ole-storage', 'application/cdfv2', 'application/vnd.ms-office', 'application/octet-stream' ),
		'xls'  => array( 'application/vnd.ms-excel', 'application/x-ole-storage', 'application/cdfv2', 'application/vnd.ms-office', 'application/octet-stream' ),
		'ppt'  => array( 'application/vnd.ms-powerpoint', 'application/x-ole-storage', 'application/cdfv2', 'application/vnd.ms-office', 'application/octet-stream' ),
		'txt'  => array( 'text/' ),
		'csv'  => array( 'text/', 'application/csv' ),
		'md'   => array( 'text/' ),
		// [2026-10-18 02:15 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.121 S121-M (doc 80 §3) — voice / meeting recordings (owner_capture kind audio). A refused audio file falls back to the old media path in the bridge.
		'm4a'  => array( 'audio/', 'video/mp4', 'application/octet-stream' ),
		'aac'  => array( 'audio/', 'application/octet-stream' ),
		'mp3'  => array( 'audio/' ),
		'wav'  => array( 'audio/' ),
		'ogg'  => array( 'audio/', 'application/ogg' ),
		'opus' => array( 'audio/', 'application/ogg' ),
		'amr'  => array( 'audio/', 'application/octet-stream' ),
	);
	const EXEC_MIMES = array( 'application/x-dosexec', 'application/x-msdownload', 'application/x-executable', 'application/x-sharedlib', 'application/x-mach-binary', 'application/x-elf', 'text/x-php', 'application/x-httpd-php', 'text/x-shellscript', 'application/x-sh', 'application/javascript', 'text/javascript' );

	/** @var array<string,callable> test seams: sniff(path):string · now():int · secret():string · token():string */
	public static $readers = array();

	private static $hooked = false;

	public static function register(): void {
		if ( self::$hooked || ! function_exists( 'add_action' ) ) {
			return;
		}
		self::$hooked = true;
		// After bizcity-r2-enhanced (999): the URL of a private original is the owner-only REST route, never uploads/CDN.
		add_filter( 'wp_get_attachment_url', array( __CLASS__, 'private_url' ), 1000, 2 );
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_action( 'bizcity_mcp_register_tools', array( __CLASS__, 'register_mcp_tool' ) ); // PHASE-0.121 S121-L5
	}

	// ── PHASE-0.121 S121-L5 — re-send an original by its doc code ─────────

	const MCP_TOOL      = 'document.file.link';
	const MCP_TEXT_TOOL = 'document.text.read'; // [2026-10-17 08:40 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.121 B1-4 (S121-A4, I-3)
	const MCP_LEARN_TOOL = 'document.learn.set'; // [2026-10-17 09:20 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.121 B3-2 (S121-K7, I-7)
	const TEXT_PAGE_MAX = 6000;

	/**
	 * [2026-10-17 06:05 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.121 S121-L5 — `document.file.link`: a 15-minute signed link to ONE
	 * original by its doc code, for the cell tool `send_document` (which downloads it and sends the file into the asker's own chat).
	 * `llm_alias` = the cell key `send_document` ⇒ the cell never offers this site tool to the model directly (collision rule in
	 * site-tool-definitions.ts), so the link never reaches a model or a chat. Q121-8: the owner of the number reads every document
	 * of that number; staff only their own; anything else = not found (no oracle).
	 */
	public static function register_mcp_tool(): void {
		if ( ! class_exists( 'BizCity_MCP_Tool_Registry' ) ) {
			return;
		}
		$R = 'BizCity_MCP_Tool_Registry';
		// @mcp bizcity-mcp-standard@1 tool document.file.link
		$R::register( self::MCP_TOOL, array(
			'executor'       => 'document.file.send', // executor-registry@1 (zalo-hub/contracts/executors/document.file.send.json)
			'title'          => 'Lấy link tải tài liệu đã lưu',
			'description'    => 'Link tải có hạn 15 phút của MỘT tài liệu gốc đã lưu trong Bộ não thứ hai, theo mã TL-… (mã nằm trong tiêu đề nguồn notebook). Chủ số đọc mọi tài liệu của số; nhân sự chỉ tài liệu do mình gửi.',
			'input_schema'   => array( 'type' => 'object', 'required' => array( 'doc_code' ), 'properties' => array(
				'doc_code' => array( 'type' => 'string', 'maxLength' => 24 ),
			) ),
			'output_schema'  => $R::envelope_schema( array(
				'doc_code'   => array( 'type' => 'string' ),
				'file_name'  => array( 'type' => 'string' ),
				'mime'       => array( 'type' => 'string' ),
				'size'       => array( 'type' => 'integer' ),
				'url'        => array( 'type' => 'string' ),
				'expires_in' => array( 'type' => 'integer' ),
			), array( 'doc_code', 'url' ) ),
			'read_only'      => true,
			'idempotent'     => false, // a new signature per call
			'required_scope' => 'brain.read',
			'handler'        => array( __CLASS__, 'mcp_link' ),
			'mode'           => 'notebook',
			'roles'          => array( 'owner', 'staff' ),
			'scopes'         => array( 'brain.read' ),
			'confirm'        => 'never',
			'llm_alias'      => 'send_document',
			'fallback_pack'  => null,
			'since'          => '0.121.0',
		) );

		// @mcp bizcity-mcp-standard@1 tool document.learn.set
		$R::register( self::MCP_LEARN_TOOL, array(
			'executor'       => 'document.learn.set',
			'title'          => 'Đặt mức học của một tài liệu',
			'description'    => 'Đổi mức học của MỘT tài liệu đã lưu theo mã TL-… hoặc entry_id: L2 = "học kỹ" (dựng đồ thị kiến thức), stop = "ngừng học sâu" (giữ tìm theo nội dung). Cho Guru dùng trả lời khách (L3) chỉ bật ở Bot Studio › Guru › Kiến thức. Chủ: mọi tài liệu của số; nhân sự: tài liệu của mình.',
			'input_schema'   => array( 'type' => 'object', 'required' => array( 'level' ), 'properties' => array(
				'doc_code' => array( 'type' => 'string', 'maxLength' => 24 ),
				'entry_id' => array( 'type' => 'integer', 'minimum' => 1 ),
				'level'    => array( 'type' => 'string', 'enum' => array( 'L2', 'stop', 'L3' ) ),
			) ),
			'output_schema'  => $R::envelope_schema( array(
				'doc_code'    => array( 'type' => 'string' ),
				'entry_id'    => array( 'type' => 'integer' ),
				'level'       => array( 'type' => 'string' ),
				'graph_state' => array( 'type' => 'string' ),
				'requeued'    => array( 'type' => 'integer' ),
			), array( 'level' ) ),
			'read_only'      => false,
			'destructive'    => false,
			'idempotent'     => true,
			'required_scope' => 'memory.write',
			'handler'        => array( __CLASS__, 'mcp_learn_set' ),
			'mode'           => 'notebook',
			'roles'          => array( 'owner', 'staff' ),
			'scopes'         => array( 'memory.write' ),
			'confirm'        => 'never',
			'llm_alias'      => 'learn_document',
			'fallback_pack'  => null,
			'since'          => '0.121.0',
		) );

		// @mcp bizcity-mcp-standard@1 tool document.text.read
		$R::register( self::MCP_TEXT_TOOL, array(
			'executor'       => 'document.text.read',
			'title'          => 'Đọc chữ của một tài liệu đã lưu',
			'description'    => 'Đọc từng trang chữ (đã trích lúc lưu) của MỘT tài liệu theo mã TL-… hoặc entry_id. Chưa có chữ ⇒ text_ready=false + link ký 15 phút để cell tự trích. Quyền như document.file.link.',
			'input_schema'   => array( 'type' => 'object', 'properties' => array(
				'doc_code' => array( 'type' => 'string', 'maxLength' => 24 ),
				'entry_id' => array( 'type' => 'integer', 'minimum' => 1 ),
				'offset'   => array( 'type' => 'integer', 'minimum' => 0 ),
				'limit'    => array( 'type' => 'integer', 'minimum' => 200, 'maximum' => self::TEXT_PAGE_MAX ),
				'want_file' => array( 'type' => 'boolean' ), // [2026-10-18 Johnny Chu - Chu Hoàng Anh] PHASE-0.120 K2 — kèm link ký 15 phút của file gốc dù chữ đã sẵn (cell lấy file mẫu hợp đồng)
			) ),
			'output_schema'  => $R::envelope_schema( array(
				'doc_code'    => array( 'type' => 'string' ),
				'title'       => array( 'type' => 'string' ),
				'text_ready'  => array( 'type' => 'boolean' ),
				'text'        => array( 'type' => 'string' ),
				'offset'      => array( 'type' => 'integer' ),
				'next_offset' => array( 'type' => array( 'integer', 'null' ) ),
				'total_chars' => array( 'type' => 'integer' ),
				'url'         => array( 'type' => 'string' ),
			), array( 'doc_code', 'text_ready' ) ),
			'read_only'      => true,
			'idempotent'     => true,
			'required_scope' => 'brain.read',
			'handler'        => array( __CLASS__, 'mcp_text_read' ),
			'mode'           => 'notebook',
			'roles'          => array( 'owner', 'staff' ),
			'scopes'         => array( 'brain.read' ),
			'confirm'        => 'never',
			'llm_alias'      => 'read_document',
			'fallback_pack'  => null,
			'since'          => '0.121.0',
		) );
	}

	/**
	 * [2026-10-17 08:40 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.121 B1-4 (S121-A4, I-3) — one page of text, cut at a sentence / line boundary near $limit; next_offset null at the end. Pure.
	 *
	 * @return array{text:string,offset:int,next_offset:int|null,total_chars:int}
	 */
	public static function slice_text( string $text, int $offset, int $limit ): array {
		$total  = mb_strlen( $text );
		$offset = max( 0, min( $offset, $total ) );
		$limit  = max( 200, min( self::TEXT_PAGE_MAX, $limit ) );
		$chunk  = mb_substr( $text, $offset, $limit );
		if ( $offset + mb_strlen( $chunk ) < $total ) {
			$cut = max( mb_strrpos( $chunk, "\n" ) ?: 0, mb_strrpos( $chunk, '. ' ) ?: 0 );
			if ( $cut > (int) ( $limit * 0.6 ) ) {
				$chunk = mb_substr( $chunk, 0, $cut + 1 );
			}
		}
		$next = $offset + mb_strlen( $chunk );
		return array( 'text' => $chunk, 'offset' => $offset, 'next_offset' => $next < $total ? $next : null, 'total_chars' => $total );
	}

	/** MCP handler of `document.text.read` (I-3). */
	public static function mcp_text_read( array $args, array $ctx ) {
		$not_found = new WP_Error( class_exists( 'BizCity_MCP_Error' ) ? BizCity_MCP_Error::NOT_FOUND : 'not_found', 'Không tìm thấy tài liệu có mã này (hoặc anh/chị không có quyền xem).', array( 'status' => 404 ) );
		if ( ! class_exists( 'BizCity_Journal_Database' ) ) {
			return $not_found;
		}
		$db      = BizCity_Journal_Database::instance();
		$user_id = (int) ( $ctx['user_id'] ?? 0 );
		$role    = (string) ( $ctx['role'] ?? '' );
		$code    = '';
		$row     = null;
		$attach  = 0;
		if ( ! empty( $args['doc_code'] ) ) {
			$attach = self::id_of_code( (string) $args['doc_code'] );
			if ( $attach <= 0 ) {
				return new WP_Error( class_exists( 'BizCity_MCP_Error' ) ? BizCity_MCP_Error::QUERY_INVALID : 'query_invalid', 'Mã tài liệu phải có dạng TL-123.', array( 'status' => 400 ) );
			}
			if ( ! self::is_private( $attach ) || ! self::may_read( (int) get_post_meta( $attach, self::META_OWNER, true ), (string) get_post_meta( $attach, self::META_WORKSPACE, true ), $user_id, $role, (string) ( $ctx['account_id'] ?? '' ) ) ) {
				return $not_found;
			}
			$code = self::doc_code( $attach );
			$row  = $db->get_by_source_ref( $code );
		} elseif ( ! empty( $args['entry_id'] ) ) {
			$row = $db->get( (int) $args['entry_id'], 0, true );
			$nbs = array_map( 'intval', (array) ( $ctx['allowed_notebook_ids'] ?? array() ) );
			if ( ! $row || ! in_array( $role, array( 'owner', 'staff' ), true ) || ( (int) $row['owner_user_id'] !== $user_id && ! ( 'owner' === $role && in_array( (int) $row['notebook_id'], $nbs, true ) ) ) ) {
				return $not_found;
			}
			$meta   = json_decode( (string) ( $row['metadata'] ?? '' ), true );
			$attach = is_array( $meta ) ? (int) ( $meta['original']['attachment_id'] ?? 0 ) : 0;
			$code   = is_array( $meta ) && '' !== (string) ( $meta['original']['doc_code'] ?? '' ) ? (string) $meta['original']['doc_code'] : 'JE-' . (int) $row['id'];
		} else {
			return new WP_Error( class_exists( 'BizCity_MCP_Error' ) ? BizCity_MCP_Error::QUERY_INVALID : 'query_invalid', 'Cần doc_code hoặc entry_id.', array( 'status' => 400 ) );
		}
		$title = $row ? (string) $row['title'] : ( $attach > 0 ? (string) self::raw_title( $attach ) : '' );
		$text  = self::kg_text( $row ? (int) $row['kg_source_id'] : 0, $attach );
		if ( '' === trim( $text ) ) {
			$url = $attach > 0 ? self::signed_url( $attach ) : '';
			if ( '' === $url ) {
				return $not_found;
			}
			return array( 'doc_code' => $code, 'title' => $title, 'text_ready' => false, 'text' => '', 'offset' => 0, 'next_offset' => null, 'total_chars' => 0, 'url' => $url );
		}
		$page = self::slice_text( $text, (int) ( $args['offset'] ?? 0 ), (int) ( $args['limit'] ?? self::TEXT_PAGE_MAX ) );
		$out = array( 'doc_code' => $code, 'title' => $title, 'text_ready' => true ) + $page;
		if ( ! empty( $args['want_file'] ) && $attach > 0 ) {
			$link = self::signed_url( $attach );
			if ( '' !== $link ) {
				$out['url'] = $link;
			}
		}
		return $out;
	}

	/**
	 * [2026-10-17 09:20 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.121 B3-2 (S121-K7, I-7) — level transition, pure: L2 (owner asked to learn deeply) · stop (back to search-by-content, L1 when
	 * passages exist else L0) · L3 refused here (Guru knowledge is configured only in Bot Studio — R-GURU-CONTEXT-SOURCE).
	 *
	 * @return array{ok:bool,level?:string,code?:string}
	 */
	public static function next_level( string $requested, array $learning ): array {
		$requested = strtoupper( trim( $requested ) );
		if ( 'L3' === $requested ) {
			return array( 'ok' => false, 'code' => 'l3_in_bot_studio' );
		}
		if ( 'L2' === $requested ) {
			return array( 'ok' => true, 'level' => 'L2' );
		}
		if ( 'STOP' === $requested ) {
			return array( 'ok' => true, 'level' => (int) ( $learning['passages'] ?? 0 ) > 0 || ! empty( $learning['text_ready'] ) ? 'L1' : 'L0' );
		}
		return array( 'ok' => false, 'code' => 'invalid_level' );
	}

	/** MCP handler of `document.learn.set` (I-7). */
	public static function mcp_learn_set( array $args, array $ctx ) {
		$invalid   = class_exists( 'BizCity_MCP_Error' ) ? BizCity_MCP_Error::QUERY_INVALID : 'query_invalid';
		$not_found = new WP_Error( class_exists( 'BizCity_MCP_Error' ) ? BizCity_MCP_Error::NOT_FOUND : 'not_found', 'Không tìm thấy tài liệu có mã này (hoặc anh/chị không có quyền).', array( 'status' => 404 ) );
		if ( ! class_exists( 'BizCity_Journal_Database' ) || ! class_exists( 'BizCity_Journal_Index' ) ) {
			return $not_found;
		}
		$db      = BizCity_Journal_Database::instance();
		$user_id = (int) ( $ctx['user_id'] ?? 0 );
		$role    = (string) ( $ctx['role'] ?? '' );
		$row     = null;
		if ( ! empty( $args['doc_code'] ) ) {
			$attach = self::id_of_code( (string) $args['doc_code'] );
			if ( $attach <= 0 || ! self::is_private( $attach ) || ! self::may_read( (int) get_post_meta( $attach, self::META_OWNER, true ), (string) get_post_meta( $attach, self::META_WORKSPACE, true ), $user_id, $role, (string) ( $ctx['account_id'] ?? '' ) ) ) {
				return $not_found;
			}
			$row = $db->get_by_source_ref( self::doc_code( $attach ) );
		} elseif ( ! empty( $args['entry_id'] ) ) {
			$row = $db->get( (int) $args['entry_id'], 0, true );
			$nbs = array_map( 'intval', (array) ( $ctx['allowed_notebook_ids'] ?? array() ) );
			if ( $row && ( (int) $row['owner_user_id'] !== $user_id && ! ( 'owner' === $role && in_array( (int) $row['notebook_id'], $nbs, true ) ) ) ) {
				$row = null;
			}
		}
		if ( ! $row || ! in_array( $role, array( 'owner', 'staff' ), true ) ) {
			return $not_found;
		}
		$entry = BizCity_Journal_Index::to_journal_entry( $row );
		$next  = self::next_level( (string) ( $args['level'] ?? '' ), (array) $entry['learning'] );
		if ( ! $next['ok'] ) {
			return new WP_Error( $invalid, 'l3_in_bot_studio' === $next['code'] ? 'Cho Guru dùng tài liệu để trả lời khách chỉ bật ở Bot Studio › Guru › Kiến thức (chủ xác nhận ở đó).' : 'Mức học phải là L2 hoặc stop.', array( 'status' => 400, 'reason' => $next['code'] ) );
		}
		if ( 'L2' === $next['level'] && '' !== (string) ( $entry['flags']['sensitive'] ?? '' ) ) {
			return new WP_Error( $invalid, 'Tài liệu nhạy cảm không dựng đồ thị kiến thức (chỉ tìm theo nội dung).', array( 'status' => 400, 'reason' => 'sensitive' ) );
		}
		$graph = 'L2' === $next['level'] ? 'pending' : (string) ( $entry['learning']['graph_state'] ?? 'none' );
		BizCity_Journal_Index::file( (int) $row['id'], (int) $row['owner_user_id'], array( 'learning' => array( 'level' => $next['level'], 'graph_state' => $graph ) ) );
		$requeued = 'L2' === $next['level'] ? self::requeue_graph( (int) $row['kg_source_id'], (int) $row['notebook_id'], (int) $row['owner_user_id'] ) : 0;
		return array( 'doc_code' => (string) ( $entry['original']['doc_code'] ?? '' ), 'entry_id' => (int) $row['id'], 'level' => $next['level'], 'graph_state' => $graph, 'requeued' => $requeued );
	}

	/** L2: passages the gate skipped become pending again and the notebook's learning job is queued. Returns passages re-opened. */
	private static function requeue_graph( int $kg_source_id, int $notebook_id, int $user_id ): int {
		global $wpdb;
		if ( $kg_source_id <= 0 || ! isset( $wpdb ) || ! class_exists( 'BizCity_KG_Database' ) ) {
			return 0;
		}
		$tbl = BizCity_KG_Database::instance()->tbl_passages();
		$n   = (int) $wpdb->query( $wpdb->prepare( "UPDATE {$tbl} SET extraction_status = 'pending', extraction_error = '' WHERE source_id = %d AND extraction_status = 'skipped' AND extraction_error LIKE %s", $kg_source_id, 'l2\\_gate:%' ) );
		if ( $notebook_id > 0 && class_exists( 'BizCity_TwinChat_Learning_Job_Queue' ) ) {
			BizCity_TwinChat_Learning_Job_Queue::instance()->enqueue( array( 'notebook_id' => $notebook_id, 'user_id' => $user_id, 'source_title' => 'learn_document' ) );
		}
		return $n;
	}

	/** Extracted text of a document: kg_sources.content_text by kg source, else by attachment. '' when none. */
	private static function kg_text( int $kg_source_id, int $attachment_id ): string {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! class_exists( 'BizCity_KG_Database' ) ) {
			return '';
		}
		$tbl = BizCity_KG_Database::instance()->tbl_sources();
		if ( $kg_source_id > 0 ) {
			$t = (string) $wpdb->get_var( $wpdb->prepare( "SELECT content_text FROM {$tbl} WHERE id = %d", $kg_source_id ) );
			if ( '' !== trim( $t ) ) {
				return $t;
			}
		}
		if ( $attachment_id > 0 ) {
			return (string) $wpdb->get_var( $wpdb->prepare( "SELECT content_text FROM {$tbl} WHERE attachment_id = %d AND content_text IS NOT NULL ORDER BY id DESC LIMIT 1", $attachment_id ) );
		}
		return '';
	}

	/**
	 * Q121-8, pure: may this principal read a document?
	 *
	 * @param int    $file_owner WP user who sent the document (`_bizcity_sb_owner_user_id`)
	 * @param string $workspace  `u<id>|<scope>` of the document
	 * @param int    $user_id    principal of the turn
	 * @param string $role       owner|staff (anything else ⇒ false)
	 * @param string $account_id the Zalo number of the turn
	 */
	public static function may_read( int $file_owner, string $workspace, int $user_id, string $role, string $account_id ): bool {
		// [2026-10-20 10:53 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.126 S126-P2 (brain-access@1, BS-7) — one decision point for file.get: BizCity_Brain_Access::decide_file (same rule, same tests).
		if ( class_exists( 'BizCity_Brain_Access' ) ) {
			return BizCity_Brain_Access::decide_file( $file_owner, $workspace, BizCity_Brain_Access::principal( array( 'role' => $role, 'user_id' => $user_id, 'account_id' => $account_id ) ), $account_id );
		}
		if ( $user_id <= 0 || ! in_array( $role, array( 'owner', 'staff' ), true ) ) {
			return false;
		}
		if ( $file_owner > 0 && $file_owner === $user_id ) {
			return true;
		}
		// `u<id>|<scope>`; a workspace without "|" yields a cut-off scope that never equals "zalo_account:…".
		$scope = (string) substr( $workspace, (int) strpos( $workspace, '|' ) + 1 );
		return 'owner' === $role && '' !== $account_id && 'zalo_account:' . $account_id === $scope;
	}

	/** MCP handler of `document.file.link`. */
	public static function mcp_link( array $args, array $ctx ) {
		$id        = self::id_of_code( (string) ( $args['doc_code'] ?? '' ) );
		$not_found = new WP_Error( class_exists( 'BizCity_MCP_Error' ) ? BizCity_MCP_Error::NOT_FOUND : 'not_found', 'Không tìm thấy tài liệu có mã này (hoặc anh/chị không có quyền xem).', array( 'status' => 404 ) );
		if ( $id <= 0 ) {
			return new WP_Error( class_exists( 'BizCity_MCP_Error' ) ? BizCity_MCP_Error::QUERY_INVALID : 'query_invalid', 'Mã tài liệu phải có dạng TL-123.', array( 'status' => 400 ) );
		}
		if ( ! self::is_private( $id ) || ! self::has_bytes( $id ) ) {
			return $not_found;
		}
		$ok = self::may_read(
			(int) get_post_meta( $id, self::META_OWNER, true ),
			(string) get_post_meta( $id, self::META_WORKSPACE, true ),
			(int) ( $ctx['user_id'] ?? 0 ),
			(string) ( $ctx['role'] ?? '' ),
			(string) ( $ctx['account_id'] ?? '' )
		);
		if ( ! $ok ) {
			return $not_found;
		}
		$ext = self::ext_of( (string) get_post_meta( $id, '_wp_attached_file', true ) );
		return array(
			'doc_code'   => self::doc_code( $id ),
			'file_name'  => sanitize_file_name( self::raw_title( $id ) ) . ( '' !== $ext ? '.' . $ext : '' ),
			'mime'       => (string) get_post_meta( $id, self::META_MIME, true ),
			'size'       => (int) get_post_meta( $id, self::META_SIZE, true ),
			'url'        => self::signed_url( $id ),
			'expires_in' => self::SIGNED_TTL,
		);
	}

	/** The one switch (rollback): false ⇒ owner-capture documents go back to the old sideload, no receipts. */
	public static function enabled(): bool {
		return (bool) apply_filters( self::FILTER_ENABLED, true );
	}

	// ── pure ─────────────────────────────────────────────────────────────

	/** Workspace of a dedupe: the person the file belongs to + the capture scope (one Zalo number). Pure. */
	public static function workspace_key( int $user_id, string $scope_id ): string {
		return 'u' . max( 0, $user_id ) . '|' . substr( preg_replace( '/[^A-Za-z0-9_:.\-]/', '', $scope_id ), 0, 120 );
	}

	/** Lower-case extension of a file name, '' when none. Pure. */
	public static function ext_of( string $name ): string {
		$base = basename( str_replace( '\\', '/', $name ) );
		$dot  = strrpos( $base, '.' );
		return false === $dot ? '' : strtolower( substr( $base, $dot + 1 ) );
	}

	/**
	 * Is this file something we keep? Pure (the sniffed MIME comes in).
	 *
	 * @return string '' = ok, else a reason code: unsupported_type · type_mismatch · too_large · empty_file
	 */
	public static function verdict( string $ext, string $sniffed_mime, int $size ): string {
		if ( $size <= 0 ) {
			return 'empty_file';
		}
		if ( $size > self::MAX_BYTES ) {
			return 'too_large';
		}
		$ext = strtolower( $ext );
		if ( ! isset( self::ALLOWED[ $ext ] ) ) {
			return 'unsupported_type';
		}
		$mime = strtolower( trim( $sniffed_mime ) );
		if ( in_array( $mime, self::EXEC_MIMES, true ) ) {
			return 'type_mismatch';
		}
		// An empty sniff (nothing could read the content) matches no prefix below: refused, never kept as a blind blob.
		foreach ( self::ALLOWED[ $ext ] as $prefix ) {
			if ( 0 === strpos( $mime, $prefix ) ) {
				return '';
			}
		}
		return 'type_mismatch';
	}

	/** `<token>-<safe ascii name>.<ext>` — the token makes the storage URL unguessable; the raw name never becomes a path. Pure. */
	public static function stored_name( string $token, string $name, string $ext ): string {
		$stem = pathinfo( basename( str_replace( '\\', '/', $name ) ), PATHINFO_FILENAME );
		$stem = strtolower( (string) preg_replace( '/[^A-Za-z0-9_-]+/', '-', self::ascii( $stem ) ) );
		$stem = trim( substr( $stem, 0, 60 ), '-' );
		return strtolower( preg_replace( '/[^a-f0-9]/i', '', $token ) ) . ( '' !== $stem ? '-' . $stem : '' ) . ( '' !== $ext ? '.' . strtolower( $ext ) : '' );
	}

	/** HMAC of (attachment, expiry). Pure. */
	public static function signature( int $attachment_id, int $expires, string $secret ): string {
		return hash_hmac( 'sha256', 'sb-file|' . $attachment_id . '|' . $expires, $secret );
	}

	/** Pure check of a signed link. */
	public static function signature_ok( int $attachment_id, int $expires, string $sig, string $secret, int $now ): bool {
		if ( $attachment_id <= 0 || $expires < $now || $expires > $now + self::SIGNED_TTL + 60 || '' === $sig || '' === $secret ) {
			return false;
		}
		return hash_equals( self::signature( $attachment_id, $expires, $secret ), $sig );
	}

	/** Short code the owner sees and can quote back ("TL-<attachment id>"). Pure. */
	public static function doc_code( int $attachment_id ): string {
		return 'TL-' . max( 0, $attachment_id );
	}

	/** "TL-123" / "tl 123" / "123" ⇒ 123, else 0. Pure. */
	public static function id_of_code( string $code ): int {
		return preg_match( '/^\s*(?:tl[\s\-_]*)?(\d{1,12})\s*$/i', $code, $m ) ? (int) $m[1] : 0;
	}

	// ── WP ───────────────────────────────────────────────────────────────

	/**
	 * Inspect a downloaded temp file: sha256, size, sniffed MIME, verdict.
	 *
	 * @return array{sha256:string,size:int,mime:string,ext:string,reason:string}
	 */
	public static function inspect( string $tmp, string $name ): array {
		$size = is_readable( $tmp ) ? (int) filesize( $tmp ) : 0;
		$ext  = self::ext_of( $name );
		$mime = $size > 0 ? self::sniff( $tmp ) : '';
		return array(
			'sha256' => $size > 0 ? (string) hash_file( 'sha256', $tmp ) : '',
			'size'   => $size,
			'mime'   => $mime,
			'ext'    => $ext,
			'reason' => self::verdict( $ext, $mime, $size ),
		);
	}

	/** The attachment already holding these bytes in this workspace (still on disk or on R2), else 0. */
	public static function find_duplicate( string $workspace, string $sha256 ): int {
		global $wpdb;
		if ( '' === $sha256 || ! isset( $wpdb ) ) {
			return 0;
		}
		$id = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT a.post_id FROM {$wpdb->postmeta} a
			 INNER JOIN {$wpdb->postmeta} b ON b.post_id = a.post_id AND b.meta_key = %s AND b.meta_value = %s
			 INNER JOIN {$wpdb->posts} p ON p.ID = a.post_id AND p.post_type = 'attachment'
			 WHERE a.meta_key = %s AND a.meta_value = %s ORDER BY a.post_id ASC LIMIT 1",
			self::META_WORKSPACE, $workspace, self::META_SHA256, $sha256
		) );
		return $id > 0 && self::has_bytes( $id ) ? $id : 0;
	}

	/**
	 * Download a remote document (Zalo CDN link from the cell) and keep it.
	 *
	 * @param array $ctx { user_id:int, scope_id:string }
	 * @return array{attachment_id:int,duplicate:bool,sha256:string,size:int,mime:string}|WP_Error
	 */
	public static function capture_remote( string $url, string $name, array $ctx ) {
		if ( '' === $url ) {
			return new WP_Error( 'download_failed', 'URL tệp trống.' );
		}
		if ( ! function_exists( 'download_url' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$tmp = download_url( $url, 60 );
		if ( is_wp_error( $tmp ) ) {
			return new WP_Error( 'download_failed', 'Không tải được file từ kênh gửi lên.' );
		}
		try {
			return self::store_file( (string) $tmp, $name, $ctx );
		} finally {
			if ( is_string( $tmp ) && file_exists( $tmp ) ) {
				@unlink( $tmp );
			}
		}
	}

	/**
	 * Keep a local temp file (the caller removes $tmp). Same bytes in the same workspace ⇒ the existing attachment.
	 *
	 * @param array $ctx { user_id:int, scope_id:string }
	 * @return array{attachment_id:int,duplicate:bool,sha256:string,size:int,mime:string}|WP_Error
	 */
	public static function store_file( string $tmp, string $name, array $ctx ) {
		$info = self::inspect( $tmp, $name );
		if ( '' !== $info['reason'] ) {
			return new WP_Error( $info['reason'], self::reason_text( $info['reason'] ), array( 'size' => $info['size'], 'ext' => $info['ext'] ) );
		}
		$user_id   = (int) ( $ctx['user_id'] ?? 0 );
		$workspace = self::workspace_key( $user_id, (string) ( $ctx['scope_id'] ?? '' ) );
		$dup       = self::find_duplicate( $workspace, $info['sha256'] );
		if ( $dup > 0 ) {
			return array( 'attachment_id' => $dup, 'duplicate' => true, 'sha256' => $info['sha256'], 'size' => $info['size'], 'mime' => $info['mime'] );
		}

		// Where WordPress puts any upload this month (uploads[/sites/<id>]/yyyy/mm) — R2 sync, backups and the public repo all agree.
		$up  = wp_upload_dir( null, true, false );
		$dir = isset( $up['path'] ) ? (string) $up['path'] : '';
		if ( '' === $dir || ! empty( $up['error'] ) || ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) || ! is_writable( $dir ) ) {
			return new WP_Error( 'store_unwritable', 'Không ghi được thư mục media.' );
		}
		// The token only protects a name nobody can LIST: a host with directory listing on (no R2, Apache `Options Indexes`)
		// would show it. Same guard WordPress ships in wp-content/: an empty index.php in the month folder.
		if ( ! file_exists( trailingslashit( $dir ) . 'index.php' ) ) {
			@file_put_contents( trailingslashit( $dir ) . 'index.php', "<?php\n// Silence is golden.\n" );
		}
		$file_name = wp_unique_filename( $dir, self::stored_name( self::token(), $name, $info['ext'] ) );
		$dest      = trailingslashit( $dir ) . $file_name;
		if ( ! @copy( $tmp, $dest ) || ! is_readable( $dest ) ) {
			return new WP_Error( 'store_unwritable', 'Không ghi được file vào thư mục media.' );
		}
		@chmod( $dest, 0644 & ~umask() );

		$title     = sanitize_text_field( pathinfo( basename( str_replace( '\\', '/', $name ) ), PATHINFO_FILENAME ) );
		$attach_id = wp_insert_attachment( array(
			'post_title'     => '' !== $title ? $title : $file_name,
			'post_mime_type' => $info['mime'],
			'post_status'    => 'private',
			'post_author'    => $user_id,
			'post_content'   => '',
		), $dest, 0, true );
		if ( is_wp_error( $attach_id ) || (int) $attach_id <= 0 ) {
			@unlink( $dest );
			// [2026-10-18 Johnny Chu - Chu Hoàng Anh] PHASE-0.120 — nói LÝ DO THẬT của WordPress (biên nhận + log), không chỉ "không tạo được"
			$why = is_wp_error( $attach_id ) ? $attach_id->get_error_code() . ': ' . $attach_id->get_error_message() : 'wp_insert_attachment trả 0 (có hook chặn hoặc DB từ chối)';
			error_log( '[kg-private-file] store_failed ext=' . $info['ext'] . ' mime=' . $info['mime'] . ' ' . $why );
			return new WP_Error( 'store_failed', 'Không tạo được bản ghi tài liệu: ' . $why );
		}
		$attach_id = (int) $attach_id;
		update_post_meta( $attach_id, self::META_PRIVATE, 1 );
		update_post_meta( $attach_id, self::META_SHA256, $info['sha256'] );
		update_post_meta( $attach_id, self::META_SIZE, $info['size'] );
		update_post_meta( $attach_id, self::META_MIME, $info['mime'] );
		update_post_meta( $attach_id, self::META_WORKSPACE, $workspace );
		update_post_meta( $attach_id, self::META_OWNER, $user_id );
		// Same hook every upload fires: a site syncing media to R2 copies the file there (and may drop the local copy).
		// Documents need no image sizes, so no wp_generate_attachment_metadata() (no PDF preview renders).
		wp_update_attachment_metadata( $attach_id, array( 'filesize' => $info['size'] ) );
		return array( 'attachment_id' => $attach_id, 'duplicate' => false, 'sha256' => $info['sha256'], 'size' => $info['size'], 'mime' => $info['mime'] );
	}

	public static function is_private( int $attachment_id ): bool {
		return $attachment_id > 0 && function_exists( 'get_post_meta' ) && '1' === (string) get_post_meta( $attachment_id, self::META_PRIVATE, true );
	}

	/** Bytes still reachable: the local file, or the R2 copy of an offloaded one. */
	public static function has_bytes( int $attachment_id ): bool {
		$path = get_attached_file( $attachment_id );
		if ( is_string( $path ) && '' !== $path && is_readable( $path ) ) {
			return true;
		}
		return '' !== (string) get_post_meta( $attachment_id, self::META_R2, true );
	}

	/**
	 * The REAL storage URL (uploads or R2) — server-side only (ingest fallback, route proxy). Never returned to a client.
	 */
	public static function storage_url( int $attachment_id ): string {
		remove_filter( 'wp_get_attachment_url', array( __CLASS__, 'private_url' ), 1000 );
		try {
			return (string) wp_get_attachment_url( $attachment_id );
		} finally {
			add_filter( 'wp_get_attachment_url', array( __CLASS__, 'private_url' ), 1000, 2 );
		}
	}

	/** A link the cell (or the owner) can fetch for SIGNED_TTL seconds. '' when not a private original. */
	public static function signed_url( int $attachment_id ): string {
		if ( ! self::is_private( $attachment_id ) || ! function_exists( 'rest_url' ) ) {
			return '';
		}
		$exp = self::now() + self::SIGNED_TTL;
		$url = rest_url( self::REST_NS . '/second-brain/file/' . $attachment_id );
		return add_query_arg( array( 'exp' => $exp, 'sig' => self::signature( $attachment_id, $exp, self::secret() ) ), $url );
	}

	/** wp_get_attachment_url filter (priority 1000): the owner-only REST route, never the uploads/CDN path. */
	public static function private_url( $url, $attachment_id ) {
		if ( ! self::is_private( (int) $attachment_id ) || ! function_exists( 'rest_url' ) ) {
			return $url;
		}
		return rest_url( self::REST_NS . '/second-brain/file/' . (int) $attachment_id );
	}

	public static function register_routes(): void {
		register_rest_route( self::REST_NS, self::REST_ROUTE, array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'rest_download' ),
			'permission_callback' => array( __CLASS__, 'rest_can_read' ),
			'args'                => array( 'id' => array( 'sanitize_callback' => 'absint' ) ),
		) );
	}

	/** Signed link (cell / short share) OR logged-in owner of the file OR site admin. 404 for anything else (no oracle). */
	public static function rest_can_read( $request ) {
		$id = (int) $request['id'];
		if ( ! self::is_private( $id ) ) {
			return new WP_Error( 'not_found', 'Không tìm thấy tài liệu.', array( 'status' => 404 ) );
		}
		$exp = (int) $request->get_param( 'exp' );
		$sig = (string) $request->get_param( 'sig' );
		if ( '' !== $sig && self::signature_ok( $id, $exp, $sig, self::secret(), self::now() ) ) {
			return true;
		}
		$uid = (int) get_current_user_id();
		if ( $uid > 0 && ( $uid === (int) get_post_meta( $id, self::META_OWNER, true ) || current_user_can( 'manage_options' ) ) ) {
			return true;
		}
		// [2026-10-17 11:10 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.121 Q121-8 web — the number's current owner may open what staff sent into it (same rule as may_read for role owner).
		if ( $uid > 0 && $uid === self::number_owner_of( (string) get_post_meta( $id, self::META_WORKSPACE, true ) ) ) {
			return true;
		}
		return new WP_Error( 'not_found', 'Không tìm thấy tài liệu.', array( 'status' => 404 ) );
	}

	/** Current owner of the number in a `u<id>|zalo_account:<bridge>` workspace; 0 when none. */
	public static function number_owner_of( string $workspace ): int {
		$pos = strpos( $workspace, '|zalo_account:' );
		if ( false === $pos || ! class_exists( 'BizCity_KG_Zalo_Number_Notebooks' ) ) {
			return 0;
		}
		return BizCity_KG_Zalo_Number_Notebooks::current_owner( (string) substr( $workspace, $pos + strlen( '|zalo_account:' ) ) );
	}

	/** Streams the original — the local file, or the R2 copy fetched server-side. Never returns a path or the storage URL. */
	public static function rest_download( $request ) {
		$id   = (int) $request['id'];
		$path = get_attached_file( $id );
		$tmp  = '';
		if ( ! is_string( $path ) || '' === $path || ! is_readable( $path ) ) {
			$remote = self::storage_url( $id );
			if ( '' === $remote ) {
				return new WP_Error( 'not_found', 'Không tìm thấy tài liệu.', array( 'status' => 404 ) );
			}
			if ( ! function_exists( 'download_url' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
			}
			$tmp = download_url( $remote, 30 );
			if ( is_wp_error( $tmp ) ) {
				return new WP_Error( 'not_found', 'Không tìm thấy tài liệu.', array( 'status' => 404 ) );
			}
			$path = (string) $tmp;
		}
		$mime = (string) get_post_meta( $id, self::META_MIME, true );
		$name = sanitize_file_name( self::raw_title( $id ) . '.' . self::ext_of( (string) get_post_meta( $id, '_wp_attached_file', true ) ) );
		nocache_headers();
		header( 'Content-Type: ' . ( '' !== $mime ? $mime : 'application/octet-stream' ) );
		header( 'Content-Length: ' . (string) filesize( $path ) );
		header( 'Content-Disposition: attachment; filename="' . rawurlencode( $name ) . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		readfile( $path );
		if ( '' !== $tmp ) {
			@unlink( $tmp );
		}
		exit;
	}

	/**
	 * [2026-10-18 07:16 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.120 — tiêu đề THÔ của tệp: `get_the_title()` của bài `private` tự thêm "Riêng tư: " ⇒ tên tải về
	 * thành "Rieng-tu-hop-dong-….docx" và lan sang tên phiên bản (ca thật 18/10 19:10, TL-7215). Đọc thẳng `post_title`.
	 */
	private static function raw_title( int $id ): string {
		if ( isset( self::$readers['title'] ) ) {
			return (string) call_user_func( self::$readers['title'], $id );
		}
		return function_exists( 'get_post_field' ) ? (string) get_post_field( 'post_title', $id, 'raw' ) : '';
	}

	/** Vietnamese line for a reason code (receipt + WP_Error message). Pure. */
	public static function reason_text( string $code ): string {
		switch ( $code ) {
			case 'unsupported_type':
				return 'định dạng chưa hỗ trợ (nhận PDF, Word, Excel, PowerPoint, TXT, CSV)';
			case 'type_mismatch':
				return 'nội dung file không khớp với đuôi file';
			case 'too_large':
				return 'file lớn hơn 20 MB';
			case 'empty_file':
				return 'file rỗng';
			case 'download_failed':
				return 'không tải được file từ Zalo (link có thể đã hết hạn) — anh gửi lại giúp em';
			// [2026-10-18 Johnny Chu - Chu Hoàng Anh] PHASE-0.120 — hai mã lưu của website nói đúng nguyên nhân (trước đây rơi vào câu chung "lỗi khi lưu trên trang")
			case 'store_unwritable':
				return 'website chưa ghi được file vào thư mục media (quyền thư mục hoặc ổ đĩa) — báo kỹ thuật kiểm tra thư mục uploads';
			case 'store_failed':
				return 'website không tạo được bản ghi tài liệu — báo kỹ thuật xem log PHP';
			default:
				return 'lỗi khi lưu trên trang';
		}
	}

	// ── internals ────────────────────────────────────────────────────────

	private static function sniff( string $path ): string {
		if ( isset( self::$readers['sniff'] ) ) {
			return (string) call_user_func( self::$readers['sniff'], $path );
		}
		if ( function_exists( 'finfo_open' ) ) {
			$f = finfo_open( FILEINFO_MIME_TYPE );
			if ( $f ) {
				$m = finfo_file( $f, $path );
				finfo_close( $f );
				return is_string( $m ) ? self::clean_mime( $m ) : '';
			}
		}
		return function_exists( 'mime_content_type' ) ? self::clean_mime( (string) @mime_content_type( $path ) ) : '';
	}

	/**
	 * [2026-10-18 04:20 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.120 — libmagic (PHP finfo) trả loại file của .docx/.xlsx bị LẶP ĐÔI
	 * ("…wordprocessingml.documentapplication/vnd…document", 142 ký tự) ⇒ vượt cột `post_mime_type` varchar(100) ⇒ wp_insert_attachment
	 * báo db_insert_error ("Không thể chèn đính kèm vào cơ sở dữ liệu") ⇒ store_failed. Ca thật tranghana.vn 18/10 15:58. Lấy nửa đầu khi lặp; còn thừa ⇒ chỉ giữ
	 * `type/subtype` đầu tiên; cắt ≤ 100. Thuần hàm.
	 */
	public static function clean_mime( string $mime ): string {
		$m   = strtolower( trim( $mime ) );
		$len = strlen( $m );
		if ( $len > 0 && 0 === $len % 2 && substr( $m, 0, $len / 2 ) === substr( $m, $len / 2 ) ) {
			$m = substr( $m, 0, $len / 2 );
		}
		if ( preg_match( '#^([a-z0-9][a-z0-9!\#$&^_.+-]*/[a-z0-9][a-z0-9!\#$&^_.+-]*?)(?:application|text|image|audio|video|font|model|multipart|message)/#', $m, $mm ) ) {
			$m = $mm[1];
		}
		return substr( $m, 0, 100 );
	}

	/** 24 hex = 96 random bits in the stored name. */
	private static function token(): string {
		if ( isset( self::$readers['token'] ) ) {
			return (string) call_user_func( self::$readers['token'] );
		}
		return bin2hex( random_bytes( 12 ) );
	}

	private static function secret(): string {
		if ( isset( self::$readers['secret'] ) ) {
			return (string) call_user_func( self::$readers['secret'] );
		}
		return function_exists( 'wp_salt' ) ? wp_salt( 'auth' ) . '|sb-file' : '';
	}

	private static function now(): int {
		return isset( self::$readers['now'] ) ? (int) call_user_func( self::$readers['now'] ) : time();
	}

	private static function ascii( string $s ): string {
		if ( function_exists( 'remove_accents' ) ) {
			return remove_accents( $s );
		}
		$t = function_exists( 'iconv' ) ? @iconv( 'UTF-8', 'ASCII//TRANSLIT//IGNORE', $s ) : false;
		return is_string( $t ) ? $t : $s;
	}
}
