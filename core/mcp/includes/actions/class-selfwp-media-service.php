<?php
/**
 * BizCity_SelfWP_Media_Service — `wp.media.upload` (R1): the cell puts ONE picture from the chat into the website's media library so a page can use it
 * (`media:<id>` in a page-spec) (PHASE-0.118 S118-L5, doc 20).
 *
 * The bytes travel INSIDE the call (base64, ≤ 1.5 MB, inside the Hub's 8 s write budget). The site never fetches anything: no URL is accepted anywhere, so there is
 * nothing to point at an internal address (no SSRF by construction, doc 20 "chỉ nhận ref từ cell").
 *
 *  - the kind of file comes from its CONTENT (image header), never from the name or a claimed MIME: only JPEG, PNG, GIF, WebP; no SVG, no HTML, no PHP;
 *  - a picture that carries PHP / script markers is refused even when its header is valid (polyglot); size and dimensions are capped;
 *  - the stored name is made here (`cell-<hash>.<ext from the detected type>`), the extension never comes from the caller;
 *  - the same picture twice (same sha256) answers the existing media id instead of storing a second copy;
 *  - needs `upload_files` (re-checked in the handler); one site-change-log@1 audit line per stored picture (no bytes, no file name from the caller).
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\MCP
 * @since      2026-10-18 (PHASE-0.118 S118-L5)
 */

defined( 'ABSPATH' ) || exit;

// [2026-10-18 12:45 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.118 S118-L5 — new file: wp.media.upload.
final class BizCity_SelfWP_Media_Service {

	const MAX_BYTES   = 1500000;
	const MAX_B64     = 2100000; // 1.5 MB * 4/3 + slack
	const MAX_SIDE    = 8000;
	const META        = '_bizcity_selfwp_media';
	/** IMAGETYPE_* → [mime, extension] */
	const TYPES       = array(
		IMAGETYPE_JPEG => array( 'image/jpeg', 'jpg' ),
		IMAGETYPE_PNG  => array( 'image/png', 'png' ),
		IMAGETYPE_GIF  => array( 'image/gif', 'gif' ),
		IMAGETYPE_WEBP => array( 'image/webp', 'webp' ),
	);

	/**
	 * Test seams, name → callable. Defaults call WordPress.
	 *   user_can(string $cap): bool · find_media(string $sha256): int (0 = none) · store(array $file): int|WP_Error
	 *   media_url(int $id): string
	 *   $file = {bytes, name, mime, alt, hash, uid}
	 *
	 * @var array<string,callable>
	 */
	public static $seams = array();

	public static function init() {
		add_action( 'bizcity_mcp_register_tools', array( __CLASS__, 'register_tools' ) );
	}

	public static function register_tools() {
		$S = 'BizCity_MCP_Tool_Registry';
		// @mcp bizcity-mcp-standard@1 tool wp.media.upload
		BizCity_MCP_Tool_Registry::register( 'wp.media.upload', array(
			'executor'       => 'wp.media.upload',
			'title'          => 'Đưa một ảnh vào thư viện media',
			'description'    => 'Đồng bộ hệ thống (chỉ zalo-hub gọi): lưu MỘT ảnh (JPEG, PNG, GIF hoặc WebP, ≤ 1,5 MB) vào thư viện media của website và trả mã media_id / ref "media:<id>" để dùng trong page-spec. Ảnh gửi kèm ngay trong lời gọi (data_base64); KHÔNG nhận địa chỉ web. Loại file lấy từ nội dung, không từ tên. Cùng một ảnh gửi lại thì trả mã cũ.',
			'input_schema'   => array( 'type' => 'object', 'required' => array( 'data_base64' ), 'properties' => array(
				'data_base64' => array( 'type' => 'string', 'minLength' => 8, 'maxLength' => self::MAX_B64 ),
				'alt'         => array( 'type' => 'string', 'maxLength' => 125 ),
			) ),
			'output_schema'  => $S::envelope_schema( array( 'media_id' => array( 'type' => 'integer' ), 'ref' => array( 'type' => 'string' ), 'url' => array( 'type' => 'string' ), 'mime' => array( 'type' => 'string' ), 'width' => array( 'type' => 'integer' ), 'height' => array( 'type' => 'integer' ), 'bytes' => array( 'type' => 'integer' ), 'duplicate' => array( 'type' => 'boolean' ) ), array( 'media_id' ) ),
			'read_only'      => false,
			'destructive'    => false,
			'idempotent'     => true,
			'required_scope' => 'content.write',
			'required_cap'   => 'upload_files',
			'handler'        => array( __CLASS__, 'media_upload' ),
			'mode'           => 'content',
			'scopes'         => array( 'content.write' ),
			'confirm'        => 'never',
			'llm_alias'      => 'wp_media_upload',
			'fallback_pack'  => null,
			'since'          => '0.118.0',
		) );
	}

	/** @return array|WP_Error */
	public static function media_upload( array $args, array $ctx ) {
		return BizCity_MCP_Action_Support::run_as( $ctx, static function ( $uid ) use ( $args ) {
			if ( ! self::can( 'upload_files' ) ) {
				return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::PERMISSION_DENIED, 'Tài khoản của bạn chưa có quyền tải ảnh lên (cần quyền WordPress "upload_files").', 403, array( 'hint' => 'Nhờ quản trị viên cấp quyền hoặc dùng tài khoản có quyền này.', 'required_cap' => 'upload_files' ) );
			}
			$b64 = $args['data_base64'] ?? null;
			if ( ! is_string( $b64 ) || strlen( $b64 ) > self::MAX_B64 ) {
				return self::bad( 'selfwp_media_too_big', 'Ảnh quá lớn hoặc thiếu dữ liệu (tối đa ' . round( self::MAX_BYTES / 1000000, 1 ) . ' MB).' );
			}
			$bytes = base64_decode( preg_replace( '/\s+/', '', $b64 ), true ); // strict: anything that is not base64 is refused
			if ( false === $bytes || '' === $bytes ) { // no minimum size: a real 1×1 picture is legitimate, the content check below is what filters junk
				return self::bad( 'selfwp_media_not_base64', 'data_base64 không phải base64 hợp lệ của một ảnh.' );
			}
			if ( strlen( $bytes ) > self::MAX_BYTES ) {
				return self::bad( 'selfwp_media_too_big', 'Ảnh quá lớn (tối đa ' . round( self::MAX_BYTES / 1000000, 1 ) . ' MB). Nén nhỏ hơn rồi gửi lại.' );
			}
			$info = @getimagesizefromstring( $bytes ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- a non-image makes it warn; we only need false
			if ( ! is_array( $info ) || ! isset( self::TYPES[ (int) $info[2] ] ) ) {
				return self::bad( 'selfwp_media_type', 'Chỉ nhận ảnh JPEG, PNG, GIF hoặc WebP (loại file được nhận diện từ nội dung, không theo tên). SVG, HTML, PDF… không được.' );
			}
			if ( (int) $info[0] < 1 || (int) $info[1] < 1 || (int) $info[0] > self::MAX_SIDE || (int) $info[1] > self::MAX_SIDE ) {
				return self::bad( 'selfwp_media_dimensions', 'Kích thước ảnh ngoài giới hạn (mỗi cạnh tối đa ' . self::MAX_SIDE . ' px).' );
			}
			if ( false !== stripos( $bytes, '<?php' ) || false !== strpos( $bytes, '<?=' ) || false !== stripos( $bytes, '<script' ) || false !== stripos( $bytes, '<html' ) ) {
				return self::bad( 'selfwp_media_suspicious', 'Ảnh chứa mã (PHP / script) nên bị từ chối.' );
			}
			[ $mime, $ext ] = self::TYPES[ (int) $info[2] ];
			$hash           = hash( 'sha256', $bytes );
			$alt            = self::clean_alt( (string) ( $args['alt'] ?? '' ) );
			$old            = (int) self::seam( 'find_media', array( $hash ), array( __CLASS__, 'default_find_media' ) );
			if ( $old > 0 ) {
				return self::shape( $old, $mime, (int) $info[0], (int) $info[1], strlen( $bytes ), true );
			}
			$id = self::seam( 'store', array( array( 'bytes' => $bytes, 'name' => 'cell-' . substr( $hash, 0, 12 ) . '.' . $ext, 'mime' => $mime, 'alt' => $alt, 'hash' => $hash, 'uid' => $uid ) ), array( __CLASS__, 'default_store' ) );
			if ( is_wp_error( $id ) ) {
				return BizCity_MCP_Action_Support::from_business( $id );
			}
			$id = (int) $id;
			if ( $id <= 0 ) {
				return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::INTERNAL_ERROR, 'Không lưu được ảnh vào thư viện media.', 500 );
			}
			$entry                 = BizCity_SelfWP_Action_MCP_Service::change_entry( 'wp.media.upload', 'R1', $uid, array(), $id, '', substr( $hash, 0, 12 ), array( 'Tải ảnh ' . $mime . ' ' . (int) $info[0] . '×' . (int) $info[1] . ' (' . strlen( $bytes ) . ' byte)' ), 'media' );
			$entry['command_line'] = 'wp media import <ảnh từ chat>  # file content is not logged';
			BizCity_SelfWP_Action_MCP_Service::audit_change( $entry );
			return self::shape( $id, $mime, (int) $info[0], (int) $info[1], strlen( $bytes ), false );
		} );
	}

	private static function shape( int $id, string $mime, int $w, int $h, int $bytes, bool $dup ): array {
		return array(
			'media_id'  => $id,
			'ref'       => 'media:' . $id,
			'url'       => (string) self::seam( 'media_url', array( $id ), static function ( $id ) {
				return function_exists( 'wp_get_attachment_url' ) ? (string) wp_get_attachment_url( (int) $id ) : '';
			} ),
			'mime'      => $mime,
			'width'     => $w,
			'height'    => $h,
			'bytes'     => $bytes,
			'duplicate' => $dup,
		);
	}

	/** one line of plain text for the alt attribute: no tags, no control characters, ≤ 125 chars */
	private static function clean_alt( string $s ): string {
		$s = strip_tags( $s );
		$s = (string) preg_replace( '/[\x00-\x1F\x7F<>]+/u', ' ', $s );
		$s = trim( (string) preg_replace( '/\s+/u', ' ', $s ) );
		return function_exists( 'mb_substr' ) ? mb_substr( $s, 0, 125, 'UTF-8' ) : substr( $s, 0, 125 );
	}

	/** @return int attachment id of a picture the assistant stored before with the same bytes, or 0 */
	public static function default_find_media( $hash ): int {
		if ( ! function_exists( 'get_posts' ) ) {
			return 0;
		}
		$rows = get_posts( array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_query'     => array( array( 'key' => self::META, 'value' => (string) $hash, 'compare' => 'LIKE' ) ),
		) );
		return $rows ? (int) $rows[0] : 0;
	}

	/** @return int|WP_Error */
	public static function default_store( $file ) {
		if ( ! function_exists( 'wp_upload_bits' ) || ! function_exists( 'wp_insert_attachment' ) ) {
			return new WP_Error( 'wp_missing', 'WordPress chưa sẵn sàng.' );
		}
		$up = wp_upload_bits( $file['name'], null, $file['bytes'] );
		if ( ! empty( $up['error'] ) ) {
			return new WP_Error( 'upload_failed', 'Không ghi được file ảnh: ' . $up['error'] );
		}
		$id = wp_insert_attachment( array(
			'post_mime_type' => $file['mime'],
			'post_title'     => '' !== $file['alt'] ? $file['alt'] : pathinfo( $file['name'], PATHINFO_FILENAME ),
			'post_status'    => 'inherit',
			'post_author'    => (int) $file['uid'],
		), $up['file'], 0, true );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		if ( file_exists( ABSPATH . 'wp-admin/includes/image.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
			wp_update_attachment_metadata( (int) $id, wp_generate_attachment_metadata( (int) $id, $up['file'] ) );
		}
		update_post_meta( (int) $id, '_wp_attachment_image_alt', $file['alt'] );
		update_post_meta( (int) $id, self::META, array( 'hash' => $file['hash'], 'by' => (int) $file['uid'], 'at' => gmdate( 'c' ) ) );
		return (int) $id;
	}

	private static function can( string $cap ): bool {
		if ( isset( self::$seams['user_can'] ) ) {
			return (bool) call_user_func( self::$seams['user_can'], $cap );
		}
		return function_exists( 'current_user_can' ) && current_user_can( $cap );
	}

	private static function seam( string $name, array $args, $default ) {
		$fn = self::$seams[ $name ] ?? $default;
		return call_user_func_array( $fn, $args );
	}

	private static function bad( string $help, string $message ) {
		return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::QUERY_INVALID, $message, 422, array( 'help_code' => $help ) );
	}
}

BizCity_SelfWP_Media_Service::init();
