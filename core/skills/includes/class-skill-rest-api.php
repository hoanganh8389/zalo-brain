<?php
/**
 * BizCity_Skill_REST_API — file routes of the skill editor SPA (PHASE-0.101 S101-L6, lean revival).
 *
 * Namespace `bizcity/skill/v1` (kept so the existing SPA and the Guru editor link keep working):
 *   GET    /tree        — file tree                       (logged in)
 *   GET    /file        — read a .md   (?path=/skills/x.md)  (logged in)
 *   POST   /file        — write a .md  {path, raw}        (edit_posts)
 *   DELETE /file        — delete a .md (?path=)           (edit_posts)
 *   POST   /folder      — create a folder {name, parentPath}  (edit_posts)
 *   DELETE /folder      — delete an empty folder (?path=) (edit_posts)
 *   GET    /catalog     — skills the service recognises (name, description, tools, status, version, path)
 *   POST   /import-md   — save an uploaded .md {raw, filename} as /skills/<name>.md (edit_posts)
 *
 * Gone with the archive (never served again): /test, /debug, /generate (PHP model call), /tool-map, /bulk-sync,
 * /skills (SQL registry), /skill/{id}, /character/{id}/skills, /journal. Errors carry R-ERROR-UX fields plus the
 * `error` key the SPA reads.
 *
 * Biz Central Brain — Johnny Chu (Chu Hoàng Anh).
 *
 * // [2026-10-11 Johnny Chu - Chu Hoàng Anh] PHASE-0.101 S101-L6 — slim rewrite of the archived REST API.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Skills
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Skill_REST_API', false ) ) {
	return;
}

final class BizCity_Skill_REST_API {

	const API_NAMESPACE = 'bizcity/skill/v1';
	const WRITE_CAP     = 'edit_posts';

	private static $hooked = false;

	public static function init(): void {
		if ( self::$hooked ) {
			return;
		}
		self::$hooked = true;
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes(): void {
		$read  = static function () { return is_user_logged_in(); };
		$write = array( __CLASS__, 'can_write' );
		register_rest_route( self::API_NAMESPACE, '/tree', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'get_tree' ), 'permission_callback' => $read ) );
		register_rest_route( self::API_NAMESPACE, '/file', array(
			array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'read_file' ), 'permission_callback' => $read ),
			array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'write_file' ), 'permission_callback' => $write ),
			array( 'methods' => 'DELETE', 'callback' => array( __CLASS__, 'delete_file' ), 'permission_callback' => $write ),
		) );
		register_rest_route( self::API_NAMESPACE, '/folder', array(
			array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'create_folder' ), 'permission_callback' => $write ),
			array( 'methods' => 'DELETE', 'callback' => array( __CLASS__, 'delete_folder' ), 'permission_callback' => $write ),
		) );
		register_rest_route( self::API_NAMESPACE, '/catalog', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'get_catalog' ), 'permission_callback' => $read ) );
		register_rest_route( self::API_NAMESPACE, '/import-md', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'import_md' ), 'permission_callback' => $write ) );
	}

	public static function can_write(): bool {
		return current_user_can( self::WRITE_CAP );
	}

	/* ── Handlers ─────────────────────────────────────────────────────── */

	public static function get_tree(): WP_REST_Response {
		return new WP_REST_Response( BizCity_Skill_Manager::instance()->get_file_tree(), 200 );
	}

	public static function read_file( WP_REST_Request $req ): WP_REST_Response {
		$data = BizCity_Skill_Manager::instance()->read_file( sanitize_text_field( (string) $req->get_param( 'path' ) ) );
		if ( is_wp_error( $data ) ) {
			return self::error( $data, 404 );
		}
		$skill = class_exists( 'BizCity_Skills_Service', false ) ? BizCity_Skills_Service::from_raw( (string) $data['raw'], (string) $data['path'], (int) $data['lastModified'] ) : null;
		if ( $skill ) {
			unset( $skill['body_md'] );
			$data['skill'] = $skill;
		}
		return new WP_REST_Response( $data, 200 );
	}

	public static function write_file( WP_REST_Request $req ): WP_REST_Response {
		$body = (array) $req->get_json_params();
		$path = sanitize_text_field( (string) ( $body['path'] ?? '' ) );
		$raw  = (string) ( $body['raw'] ?? '' );
		if ( '' === $path ) {
			return self::error( new WP_Error( 'path_required', 'Thiếu đường dẫn file.' ), 400 );
		}
		$raw = (string) preg_replace( '/<script\b[^>]*>.*?<\/script>/is', '', $raw );
		$r   = BizCity_Skill_Manager::instance()->write_file( $path, $raw );
		if ( is_wp_error( $r ) ) {
			return self::error( $r, 400 );
		}
		self::changed();
		$skill = class_exists( 'BizCity_Skills_Service', false ) ? BizCity_Skills_Service::from_raw( $raw, $path ) : null;
		return new WP_REST_Response( array( 'saved' => true, 'path' => $path, 'skill' => $skill ? $skill['name'] : null, 'status' => $skill ? $skill['status'] : null ), 200 );
	}

	public static function delete_file( WP_REST_Request $req ): WP_REST_Response {
		$r = BizCity_Skill_Manager::instance()->delete_file( sanitize_text_field( (string) $req->get_param( 'path' ) ) );
		if ( is_wp_error( $r ) ) {
			return self::error( $r, 400 );
		}
		self::changed();
		return new WP_REST_Response( array( 'deleted' => true ), 200 );
	}

	public static function create_folder( WP_REST_Request $req ): WP_REST_Response {
		$body = (array) $req->get_json_params();
		$name = sanitize_file_name( (string) ( $body['name'] ?? '' ) );
		if ( '' === $name ) {
			return self::error( new WP_Error( 'name_required', 'Thiếu tên thư mục.' ), 400 );
		}
		$parent = sanitize_text_field( (string) ( $body['parentPath'] ?? '' ) );
		$full   = '' !== $parent && '/' !== $parent ? rtrim( $parent, '/' ) . '/' . $name : '/' . $name;
		$r      = BizCity_Skill_Manager::instance()->create_folder( $full );
		if ( is_wp_error( $r ) ) {
			return self::error( $r, 400 );
		}
		return new WP_REST_Response( array( 'created' => true, 'path' => $full ), 201 );
	}

	public static function delete_folder( WP_REST_Request $req ): WP_REST_Response {
		$r = BizCity_Skill_Manager::instance()->delete_file( sanitize_text_field( (string) $req->get_param( 'path' ) ) );
		if ( is_wp_error( $r ) ) {
			return self::error( $r, 400 );
		}
		return new WP_REST_Response( array( 'deleted' => true ), 200 );
	}

	public static function get_catalog(): WP_REST_Response {
		$skills = class_exists( 'BizCity_Skills_Service', false ) ? BizCity_Skills_Service::list() : array();
		return new WP_REST_Response( array( 'skills' => $skills, 'total' => count( $skills ) ), 200 );
	}

	public static function import_md( WP_REST_Request $req ): WP_REST_Response {
		$body     = (array) $req->get_json_params();
		$raw      = (string) ( $body['raw'] ?? '' );
		$filename = sanitize_file_name( (string) ( $body['filename'] ?? '' ) );
		if ( '' === trim( $raw ) ) {
			return self::error( new WP_Error( 'raw_required', 'Nội dung file đang trống.' ), 400 );
		}
		$raw   = (string) preg_replace( '/<script\b[^>]*>.*?<\/script>/is', '', $raw );
		$skill = class_exists( 'BizCity_Skills_Service', false ) ? BizCity_Skills_Service::from_raw( $raw ) : null;
		$name  = $skill ? $skill['name'] : ( class_exists( 'BizCity_Skills_Service', false ) ? BizCity_Skills_Service::clean_name( sanitize_title( basename( $filename, '.md' ) ) ) : '' );
		if ( '' === $name ) {
			return self::error( new WP_Error( 'skill_invalid', 'File chưa có `name:` hợp lệ trong frontmatter (chữ thường, số, gạch nối).' ), 400 );
		}
		$path = '/' . BizCity_Skills_Service::FOLDER . '/' . $name . '.md';
		$r    = BizCity_Skill_Manager::instance()->write_file( $path, $raw );
		if ( is_wp_error( $r ) ) {
			return self::error( $r, 400 );
		}
		self::changed();
		return new WP_REST_Response( array( 'saved' => true, 'path' => $path, 'name' => $name, 'title' => $skill ? $skill['description'] : $name ), 200 );
	}

	/* ── Helpers ──────────────────────────────────────────────────────── */

	/** A direct file edit may have changed an approved skill: drop the pack cache and tell the cells. */
	private static function changed(): void {
		if ( class_exists( 'BizCity_Skills_Pack_Exporter', false ) ) {
			BizCity_Skills_Pack_Exporter::flush();
		}
		if ( class_exists( 'BizCity_Zalo_Pack_Invalidate', false ) && method_exists( 'BizCity_Zalo_Pack_Invalidate', 'on_packs_changed' ) ) {
			BizCity_Zalo_Pack_Invalidate::on_packs_changed( array( 'skills' ) );
		}
	}

	/** R-ERROR-UX four fields + `error` for the SPA. Messages never contain a path. */
	private static function error( WP_Error $e, int $status ): WP_REST_Response {
		$hints = array(
			'not_found'     => 'Tải lại cây file rồi chọn lại.',
			'traversal'     => 'Dùng đường dẫn tương đối trong thư viện, không có "..".',
			'invalid_chars' => 'Đặt tên bằng chữ, số, gạch nối và gạch dưới.',
			'invalid_ext'   => 'Đặt tên file có đuôi .md.',
			'not_empty'     => 'Xoá các file bên trong trước.',
			'exists'        => 'Chọn tên khác hoặc mở thư mục có sẵn.',
			'skill_invalid' => 'Thêm dòng name: <ten-skill> vào đầu file.',
		);
		$code = (string) $e->get_error_code();
		return new WP_REST_Response( array(
			'error'     => $e->get_error_message(),
			'code'      => $code,
			'message'   => $e->get_error_message(),
			'hint'      => $hints[ $code ] ?? 'Thử lại; nếu vẫn lỗi, báo quản trị viên.',
			'help_code' => 'skills_' . $code,
		), $status );
	}
}
