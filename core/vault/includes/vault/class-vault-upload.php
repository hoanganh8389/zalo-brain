<?php
/**
 * BizCity_Vault_Upload — the "Tải tệp lên" door of the Kho tri thức (owner 2026-10-25 21:5x, PHASE-0.140 / 0.137).
 * Copyright (c) 2026 Chu Hoàng Anh (Johnny Chu), registered No. 8877/2026/QTG. See NOTICE.md for authorship scope; LICENSE for terms.
 *
 * One request, four steps, no model call (R-JOURNAL: the site stores and indexes, the cell reads):
 *   1. keep the original   — BizCity_KG_Private_File_Store::store_file (a private WP attachment in the media path, R2-synced where the site has R2;
 *                            checked, sha256, same bytes in the same workspace = stored once);
 *   2. journal row         — BizCity_Journal_Index::add, source_type `web_upload`, surface `vault_upload`, idempotent on the attachment (key `vup:<blog>:<attachment>`);
 *                            a new row is born with meta.status `pending`;
 *   3. folder (optional)   — BizCity_Journal_Entry_Meta::set {folder} by entry id (source owner_edit = locked), only into a folder the person may file into; a refusal comes back as `folder_error`;
 *   4. wait for the cell   — the cell's re-read sweep (every 5 min, `pending` rows first, PHASE-0.133 S133-Mb / 0.137 B5) reads it (text, or the 0.140 read ladder
 *                            for a scan) and writes the card; the vault then shows it on the Bảng nhanh with its next step (Nghiên cứu sâu).
 * The site never reads the file here. The response says `status: pending` so the screen can say "đang chờ trợ lý đọc".
 *
 * @package Bizcity_Twin_AI
 * @since   2026-10-25
 */

// [2026-10-25 09:58 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.140 / 0.137 — new file (owner: "nút upload … đẩy lên wpmedia ⇒ ghi journal index ⇒ chờ cell extract ⇒ vào vault, học").

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Vault_Upload', false ) ) {
	return;
}

final class BizCity_Vault_Upload {

	const NS = 'bizcity-vault/v1';

	private static $hooked = false;

	public static function register(): void {
		if ( self::$hooked || ! function_exists( 'add_action' ) ) {
			return;
		}
		self::$hooked = true;
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes(): void {
		register_rest_route( self::NS, '/upload', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'rest_upload' ),
			'permission_callback' => static function () {
				return current_user_can( 'edit_posts' );
			},
		) );
	}

	/** The journal-entry input for one uploaded original. Pure. */
	public static function entry_for( int $blog_id, int $user_id, int $attachment_id, array $stored, string $file_name ): array {
		$title = sanitize_text_field( pathinfo( $file_name, PATHINFO_FILENAME ) );
		return array(
			'owner_user_id'   => $user_id,
			'kind'            => 'common.document',
			'title'           => mb_substr( '' !== $title ? $title : $file_name, 0, 255 ),
			'idempotency_key' => 'vup:' . max( 0, $blog_id ) . ':' . $attachment_id,
			'source_type'     => 'web_upload',
			'origin'          => array( 'surface' => 'vault_upload', 'ref' => 'attachment:' . $attachment_id ),
			'original'        => array(
				'attachment_id' => $attachment_id,
				'doc_code'      => 'TL-' . $attachment_id,
				'file_name'     => $file_name,
				'mime'          => (string) ( $stored['mime'] ?? '' ),
				'size'          => (int) ( $stored['size'] ?? 0 ),
				'sha256'        => (string) ( $stored['sha256'] ?? '' ),
			),
			'by'              => array( 'role' => 'owner' ),
			'confidence'      => 1.0, // a pointer, not a classification: the card arrives from the cell
		);
	}

	public static function rest_upload( WP_REST_Request $request ): WP_REST_Response {
		if ( ! class_exists( 'BizCity_KG_Private_File_Store' ) || ! class_exists( 'BizCity_Journal_Index' ) ) {
			return self::fail( 'upload_unavailable', 'Kho tài liệu chưa sẵn sàng trên site này.', 503 );
		}
		$files = $request->get_file_params();
		$f     = is_array( $files['file'] ?? null ) ? $files['file'] : array();
		if ( empty( $f['tmp_name'] ) || ! empty( $f['error'] ) || ! is_uploaded_file( (string) $f['tmp_name'] ) ) {
			return self::fail( 'upload_missing', 'Chưa nhận được tệp.', 400, 'Chọn lại tệp rồi tải lên (tối đa 20 MB).' );
		}
		$name    = sanitize_file_name( wp_unslash( (string) ( $f['name'] ?? 'tai-lieu' ) ) );
		$user_id = (int) get_current_user_id();
		$stored  = BizCity_KG_Private_File_Store::store_file( (string) $f['tmp_name'], $name, array( 'user_id' => $user_id, 'scope_id' => '' ) );
		if ( is_wp_error( $stored ) ) {
			return self::fail( $stored->get_error_code(), $stored->get_error_message(), 400 );
		}
		$attach = (int) $stored['attachment_id'];
		$row    = BizCity_Journal_Index::add( self::entry_for( (int) get_current_blog_id(), $user_id, $attach, $stored, $name ) );
		if ( is_wp_error( $row ) ) {
			return self::fail( $row->get_error_code(), $row->get_error_message(), 500 );
		}
		$ref    = BizCity_KG_Private_File_Store::doc_code( $attach );
		$folder = substr( preg_replace( '/\D/', '', (string) $request->get_param( 'folder' ) ), 0, 2 );
		$moved  = '';
		$ferr   = '';
		if ( '' !== $folder ) {
			// [2026-10-25 10:19 PM Johnny Chu - Chu Hoàng Anh] owner: two files sent from "20 Công việc" landed in Home — the old move errors were swallowed and went through a ref lookup.
			// Now: the folder must be one this person may file into (the same list the sheet shows), the write goes by entry id, and a refusal is SAID.
			$entry_id = (int) ( $row['entry_id'] ?? $row['id'] ?? 0 );
			$allowed  = class_exists( 'BizCity_Twin_Vault' ) && method_exists( 'BizCity_Twin_Vault', 'folders_for_current_user' ) ? array_column( BizCity_Twin_Vault::folders_for_current_user(), 'n' ) : array();
			if ( $entry_id <= 0 || ! class_exists( 'BizCity_Journal_Entry_Meta' ) ) {
				$ferr = 'Chưa xếp được vào thư mục (sổ chưa sẵn sàng).';
			} elseif ( ! in_array( $folder, $allowed, true ) ) {
				$ferr = 'Bạn không xếp được vào thư mục này.';
			} else {
				$mv = BizCity_Journal_Entry_Meta::set( $user_id, array( 'entry_id' => $entry_id ), array( 'folder' => $folder ), array( 'merge' => true, 'source' => 'owner_edit' ) ); // owner_edit locks the field: the cell's read never moves it back
				if ( is_wp_error( $mv ) ) {
					$ferr = $mv->get_error_message();
					error_log( '[vault-upload] folder ' . $folder . ' ref ' . $ref . ': ' . $mv->get_error_code() );
				} else {
					$moved = $folder;
				}
			}
		}
		return new WP_REST_Response( array(
			'ref'           => $ref,
			'entry_id'      => (int) ( $row['entry_id'] ?? $row['id'] ?? 0 ),
			'attachment_id' => $attach,
			'duplicate'     => ! empty( $stored['duplicate'] ),
			'folder'        => $moved,
			'folder_error'  => $ferr,
			'status'        => 'pending', // the cell reads it on its next sweep (≤ 5 min)
		), 200 );
	}

	/**
	 * [2026-11-13 Johnny Chu - Chu Hoàng Anh] PHASE-0.148 W2 S148-C10 (doc 20 §6, D148-6) — the channel door into the Vault WITHOUT the notebook.
	 * A channel capture (Zalo cá nhân / bot) hands its items here when the KG bridge is absent; the result has the bridge's shape
	 * (items[] · failed[] · succeeded · queued · total, notebook_id 0) so the caller's journal rows, filing and receipt run unchanged.
	 *   file / image / audio {attachment:{url, file_name}} ⇒ BizCity_KG_Private_File_Store::capture_remote (private original, sha256 dedupe per workspace)
	 *   text {content}                                      ⇒ a Kho tri thức note (BizCity_Twin_Vault::write, its own journal row ⇒ reported duplicate:true
	 *                                                         so the caller does not open a second row); path from the message id ⇒ a retry rewrites, never doubles
	 * No model call; the cell reads the files on its sweep, as for "Tải tệp lên".
	 *
	 * @param array $items capture items [{kind, title_hint?, message_id, attachment?:{url, file_name}, content?}]
	 * @return array{notebook_id:int,total:int,succeeded:int,queued:int,queued_jobs:array,items:array,failed:array}
	 */
	public static function keep_batch( int $user_id, string $scope_id, array $items ): array {
		$ok     = array();
		$failed = array();
		foreach ( array_values( $items ) as $idx => $it ) {
			$kind = (string) ( $it['kind'] ?? 'file' );
			$mid  = (string) ( $it['message_id'] ?? '' );
			$att  = is_array( $it['attachment'] ?? null ) ? $it['attachment'] : null;
			if ( null !== $att ) {
				$url  = (string) ( $att['url'] ?? $att['source_url'] ?? '' );
				$name = (string) ( $att['file_name'] ?? $att['name'] ?? '' );
				if ( '' === $name && '' !== $url ) {
					$name = basename( (string) ( function_exists( 'wp_parse_url' ) ? wp_parse_url( $url, PHP_URL_PATH ) : parse_url( $url, PHP_URL_PATH ) ) );
				}
				$kept = class_exists( 'BizCity_KG_Private_File_Store' ) ? BizCity_KG_Private_File_Store::capture_remote( $url, $name, array( 'user_id' => $user_id, 'scope_id' => $scope_id ) ) : new WP_Error( 'upload_unavailable', 'Kho tài liệu chưa sẵn sàng trên site này.' );
				if ( is_wp_error( $kept ) ) {
					$failed[] = array( 'index' => $idx, 'kind' => $kind, 'code' => $kept->get_error_code(), 'error' => $kept->get_error_message(), 'file_name' => $name );
					continue;
				}
				$ok[] = array( 'index' => $idx, 'kind' => $kind, 'message_id' => $mid, 'file_name' => $name, 'attachment_id' => (int) $kept['attachment_id'], 'duplicate' => ! empty( $kept['duplicate'] ), 'source_id' => 0, 'kg_source_id' => 0 );
				continue;
			}
			$text = trim( (string) ( $it['content'] ?? '' ) );
			if ( '' === $text ) {
				continue;
			}
			$title = trim( (string) ( $it['title_hint'] ?? '' ) );
			$first = trim( (string) strtok( $text, "\n" ) );
			$note  = class_exists( 'BizCity_Twin_Vault' ) ? BizCity_Twin_Vault::write( array(
				'title'    => mb_substr( '' !== $first ? $first : ( '' !== $title ? $title : 'Ghi chú từ Zalo' ), 0, 120 ),
				'markdown' => $text,
				'path'     => '/note/zalo-' . substr( md5( '' !== $mid ? $mid : $scope_id . ':' . $idx . ':' . $text ), 0, 12 ) . '.md',
			), array( 'owner_user_id' => $user_id, 'role' => 'owner', 'vrole' => 'owner' ) ) : new WP_Error( 'vault_unavailable', 'Kho tri thức chưa sẵn sàng trên site này.' );
			if ( is_wp_error( $note ) ) {
				$failed[] = array( 'index' => $idx, 'kind' => $kind, 'code' => $note->get_error_code(), 'error' => $note->get_error_message(), 'file_name' => '' );
				continue;
			}
			$ok[] = array( 'index' => $idx, 'kind' => $kind, 'message_id' => $mid, 'file_name' => '', 'attachment_id' => 0, 'duplicate' => true, 'vault_note' => (string) ( $note['id'] ?? '' ), 'entry_id' => (int) ( $note['entry_id'] ?? 0 ), 'source_id' => 0, 'kg_source_id' => 0 );
		}
		return array( 'notebook_id' => 0, 'notebook_created' => false, 'total' => count( $items ), 'succeeded' => count( $ok ), 'queued' => 0, 'queued_jobs' => array(), 'items' => $ok, 'failed' => $failed, 'via' => 'vault' );
	}

	private static function fail( string $code, string $message, int $status, string $hint = '' ): WP_REST_Response {
		return new WP_REST_Response( array( 'code' => $code, 'message' => $message, 'hint' => $hint, 'help_code' => 'vault_upload' ), $status );
	}
}
