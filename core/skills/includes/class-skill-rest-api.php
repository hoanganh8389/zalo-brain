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

	// [2026-10-11 Johnny Chu - Chu Hoàng Anh] PHASE-0.101 S102-L10 — "Trợ lý hiểu thế này": site gửi thân skill vừa lưu qua Hub → cell dịch (doc 102 §6.9).
	const HUB_UNDERSTAND = '/zalo-hub/skills/understand';
	const HUB_PLAN       = '/zalo-hub/skills/plan';
	/** @var callable|null test seam: fn(string $method, string $path, array $body): array (thân Hub trả về) */
	public static $hub = null;
	/** @var callable|null test seam: fn(): string[] (bridge account ids của site) */
	public static $accounts = null;
	// [2026-10-12 10:05 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.105 S105-K1 — trạng thái đồng bộ skill với cell (doc 20): 4 bậc + failed + not_applicable.
	const HUB_CC_SKILLS = '/zalo-hub/cell-chat/skills';
	const NOTIFY_OPTION = 'bizcity_zalo_pack_invalidate_state';
	const SYNC_STATES    = array( 'saved', 'notified', 'received', 'understood', 'failed', 'not_applicable' );
	const SYNC_MAX_CELLS = 3;
	/** @var callable|null test seam: fn(): string (web-home account id `web-<key_id>` hoặc '') */
	public static $web = null;
	/** @var callable|null test seam: fn(): ?array (option bizcity_zalo_pack_invalidate_state) */
	public static $notified = null;

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
		// [2026-10-11 Johnny Chu - Chu Hoàng Anh] PHASE-0.101 S102-L9 — create from a Vietnamese title; the server picks the unique slug.
		register_rest_route( self::API_NAMESPACE, '/create', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'create_skill' ), 'permission_callback' => $write ) );
		// [2026-10-11 Johnny Chu - Chu Hoàng Anh] PHASE-0.101 S102-L10 — POST {path} ⇒ cell dịch lời dặn; GET ?name= ⇒ cell đã hiểu thế nào
		register_rest_route( self::API_NAMESPACE, '/understand', array(
			array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'understand' ), 'permission_callback' => $write ),
			array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'understood' ), 'permission_callback' => $read ),
		) );
		// [2026-10-12 10:05 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.105 S105-K1 — GET ?name= ⇒ saved|notified|received|understood|failed|not_applicable
		register_rest_route( self::API_NAMESPACE, '/sync-status', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'sync_status' ), 'permission_callback' => $read ) );
		// [2026-10-17 04:00 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.117 D117-24 — the steps (twin-skill@1) of a skill: read (logged in) / save after the cell's check (edit_posts)
		register_rest_route( self::API_NAMESPACE, '/twin', array(
			array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'get_twin' ), 'permission_callback' => $read ),
			array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'post_twin' ), 'permission_callback' => $write ),
		) );
		// [2026-10-17 09:42 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.117 S117-W2 — ready-made daily-assistant skills: list (logged in) / install = the owner's click (edit_posts)
		register_rest_route( self::API_NAMESPACE, '/samples', array(
			array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'get_samples' ), 'permission_callback' => $read ),
			array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'install_samples' ), 'permission_callback' => $write ),
		) );
		// [2026-10-17 10:45 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.117 S117-W2 — one sample's text for the preview behind a card (read only)
		register_rest_route( self::API_NAMESPACE, '/samples/(?P<name>[a-z0-9-]{2,63})', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'get_sample' ), 'permission_callback' => $read ) );
		// [2026-10-17 04:13 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.117 S117-K6 — Editor ④ Xuất bản / Tắt: through set_status, so the check gate applies
		register_rest_route( self::API_NAMESPACE, '/status', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'post_status' ), 'permission_callback' => $write ) );
		register_rest_route( self::API_NAMESPACE, '/import-md',array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'import_md' ), 'permission_callback' => $write ) );
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
		// [2026-10-17 04:00 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.117 D117-24 — a raw edit cannot publish around the check: a skill that has steps goes live only with steps checked green for that version and body
		$next = class_exists( 'BizCity_Skills_Service', false ) ? BizCity_Skills_Service::from_raw( $raw, $path ) : null;
		if ( $next && 'approved' === $next['status'] ) {
			$twin = BizCity_Skills_Service::get_twin( $next['name'] );
			if ( $twin && ! BizCity_Skills_Service::twin_publishable( $twin, (int) $next['version'], (string) $next['body_md'] ) ) {
				return self::error( new WP_Error( 'skill_not_checked', 'Các bước của skill chưa được kiểm xanh cho bản này.' ), 409 );
			}
		}
		$r   = BizCity_Skill_Manager::instance()->write_file( $path, $raw );
		if ( is_wp_error( $r ) ) {
			return self::error( $r, 400 );
		}
		self::changed();
		// [2026-10-22 04:41 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.128 S127-SK10 — a file inside a skill folder is a resource: one journal row per file
		if ( class_exists( 'BizCity_Skills_Service', false ) ) {
			BizCity_Skills_Service::notify_resource( $path, 'write', $raw );
		}
		$skill = class_exists( 'BizCity_Skills_Service', false ) ? BizCity_Skills_Service::from_raw( $raw, $path ) : null;
		return new WP_REST_Response( array( 'saved' => true, 'path' => $path, 'skill' => $skill ? $skill['name'] : null, 'status' => $skill ? $skill['status'] : null ), 200 );
	}

	public static function delete_file( WP_REST_Request $req ): WP_REST_Response {
		$path = sanitize_text_field( (string) $req->get_param( 'path' ) );
		$r    = BizCity_Skill_Manager::instance()->delete_file( $path );
		if ( is_wp_error( $r ) ) {
			return self::error( $r, 400 );
		}
		self::changed();
		if ( class_exists( 'BizCity_Skills_Service', false ) ) {
			BizCity_Skills_Service::notify_resource( $path, 'delete' ); // S127-SK10: the resource row says it left the folder
		}
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

	/** POST /create {title, raw} — `raw` is a template with `{{name}}` / `{{title}}`; answers {path, name, title, raw}. */
	public static function create_skill( WP_REST_Request $req ): WP_REST_Response {
		if ( ! class_exists( 'BizCity_Skills_Service', false ) ) {
			return self::error( new WP_Error( 'unavailable', 'Thư viện skill chưa sẵn sàng.' ), 503 );
		}
		$body = (array) $req->get_json_params();
		$r    = BizCity_Skills_Service::create_from_title( array( 'title' => (string) ( $body['title'] ?? '' ), 'raw' => (string) ( $body['raw'] ?? '' ) ) );
		if ( is_wp_error( $r ) ) {
			return self::error( $r, 400 );
		}
		self::changed();
		return new WP_REST_Response( $r, 201 );
	}

	/**
	 * POST /understand {path} — gửi lời dặn của file skill cho cell dịch (Hub → cell, không chờ model). Không có số Zalo / khoá 1API / Hub lỗi ⇒
	 * 200 + success:false + `_degraded` (R-GW-8): skill vẫn lưu và chạy như cũ, chỉ thiếu khung "Trợ lý hiểu thế này".
	 */
	public static function understand( WP_REST_Request $req ): WP_REST_Response {
		$body = (array) $req->get_json_params();
		$data = BizCity_Skill_Manager::instance()->read_file( sanitize_text_field( (string) ( $body['path'] ?? '' ) ) );
		if ( is_wp_error( $data ) ) {
			return self::error( $data, 404 );
		}
		$skill = class_exists( 'BizCity_Skills_Service', false ) ? BizCity_Skills_Service::from_raw( (string) $data['raw'], (string) $data['path'] ) : null;
		if ( ! $skill ) {
			return self::error( new WP_Error( 'skill_invalid', 'File chưa có `name:` hợp lệ trong frontmatter (chữ thường, số, gạch nối).' ), 400 );
		}
		if ( '' === trim( (string) $skill['body_md'] ) ) {
			return self::degraded( 'skill_empty', 'Skill chưa có nội dung để trợ lý đọc.', 'Viết lời dặn kiểu "Nếu tôi yêu cầu …, thì hãy …" rồi lưu lại.' );
		}
		$account = self::account_id();
		if ( '' === $account ) {
			return self::degraded( 'no_account', 'Website chưa có số Zalo nối với trợ lý.', 'Nối một số Zalo ở Kênh › Zalo cá nhân; skill vẫn được lưu.' );
		}
		$r = self::hub( 'POST', self::HUB_UNDERSTAND, array(
			'account_id'  => $account,
			'name'        => (string) $skill['name'],
			'version'     => (int) $skill['version'],
			'description' => (string) $skill['description'],
			'body'        => (string) $skill['body_md'],
		) );
		if ( ! $r['ok'] ) {
			return self::degraded( (string) $r['code'], (string) $r['error'], 'Skill đã lưu; thử bấm "Trợ lý hiểu thế nào?" lại sau ít phút.' );
		}
		return new WP_REST_Response( array( 'ok' => true, 'success' => true, 'name' => (string) $skill['name'], 'status' => (string) ( $r['body']['status'] ?? 'queued' ), 'hash' => (string) $skill['hash'] ), 200 );
	}

	/** GET /understand?name= — kế hoạch cell đã dịch + lời thường (đọc qua Hub, không lưu ở site). */
	public static function understood( WP_REST_Request $req ): WP_REST_Response {
		$name = class_exists( 'BizCity_Skills_Service', false ) ? BizCity_Skills_Service::clean_name( (string) $req->get_param( 'name' ) ) : '';
		if ( '' === $name ) {
			return self::error( new WP_Error( 'skill_invalid', 'Thiếu tên skill.' ), 400 );
		}
		$account = self::account_id();
		if ( '' === $account ) {
			return self::degraded( 'no_account', 'Website chưa có số Zalo nối với trợ lý.', 'Nối một số Zalo ở Kênh › Zalo cá nhân.' );
		}
		$r = self::hub( 'GET', self::HUB_PLAN . '?account_id=' . rawurlencode( $account ) . '&name=' . rawurlencode( $name ) );
		if ( ! $r['ok'] ) {
			return self::degraded( (string) $r['code'], (string) $r['error'], 'Thử lại sau ít phút.' );
		}
		$b = $r['body'];
		return new WP_REST_Response( array(
			'ok'          => true,
			'success'     => true,
			'name'        => $name,
			'status'      => (string) ( $b['status'] ?? 'none' ),
			'by'          => $b['by'] ?? null,
			'body_hash'   => (string) ( $b['body_hash'] ?? '' ),
			'compiled_at' => $b['compiled_at'] ?? null,
			'summary'     => array_values( array_map( 'strval', (array) ( $b['summary'] ?? array() ) ) ),
			'plan'        => is_array( $b['plan'] ?? null ) ? $b['plan'] : null,
		), 200 );
	}

	/* ── twin-skill@1 steps of a skill (PHASE-0.117 D117-24, Skill Editor ②③④) ── */

	const HUB_TWIN_CHECK = '/zalo-hub/composio/skills/check';

	/** [2026-10-17 04:00 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.117 D117-24 — GET /twin?name= ⇒ the stored steps, the last check and whether this version may be published */
	public static function get_twin( WP_REST_Request $req ): WP_REST_Response {
		$name  = BizCity_Skills_Service::clean_name( (string) $req->get_param( 'name' ) );
		$skill = '' !== $name ? BizCity_Skills_Service::get( $name ) : null;
		if ( ! $skill ) {
			return self::error( new WP_Error( 'skill_not_found', 'Không có skill này.' ), 404 );
		}
		$twin = BizCity_Skills_Service::get_twin( $name );
		return new WP_REST_Response( array(
			'ok'          => true,
			'name'        => $name,
			'version'     => (int) $skill['version'],
			'status'      => (string) $skill['status'],
			'twin'        => $twin ? $twin['skill'] : null,
			'check'       => $twin ? $twin['check'] : null,
			'publishable' => $twin ? BizCity_Skills_Service::twin_publishable( $twin, (int) $skill['version'] ) : true,
		), 200 );
	}

	/** [2026-10-17 09:42 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.117 S117-W2 — GET /samples ⇒ the shipped samples + whether this blog already has each */
	public static function get_samples(): WP_REST_Response {
		$all = class_exists( 'BizCity_Skills_Samples', false ) ? BizCity_Skills_Samples::all() : array();
		return new WP_REST_Response( array(
			'ok'        => true,
			'samples'   => $all,
			'total'     => count( $all ),
			'installed' => count( array_filter( array_column( $all, 'installed' ) ) ),
			// [2026-10-17 10:10 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.117 S117-W2 — seeds and updates by itself; this is the one switch + what the last run did
			'auto'      => class_exists( 'BizCity_Skills_Samples', false ) && BizCity_Skills_Samples::auto_enabled(),
			'last'      => class_exists( 'BizCity_Skills_Samples', false ) ? BizCity_Skills_Samples::last_report() : null,
		), 200 );
	}

	/** GET /samples/<name> — the shipped text of one sample */
	public static function get_sample( WP_REST_Request $req ): WP_REST_Response {
		$p = class_exists( 'BizCity_Skills_Samples', false ) ? BizCity_Skills_Samples::preview( (string) $req->get_param( 'name' ) ) : null;
		return $p ? new WP_REST_Response( array( 'ok' => true ) + $p, 200 ) : self::error( new WP_Error( 'skill_not_found', 'Không có skill mẫu này.' ), 404 );
	}

	/** POST /samples {names: string[] | ["*"], approve?: bool (default true)} — never overwrites an existing skill */
	public static function install_samples( WP_REST_Request $req ): WP_REST_Response {
		if ( ! class_exists( 'BizCity_Skills_Samples', false ) ) {
			return self::error( new WP_Error( 'unavailable', 'Thư viện skill mẫu chưa sẵn sàng.' ), 503 );
		}
		$p     = (array) $req->get_json_params();
		// the one switch (auto seed + update); sending it alone does not install anything by hand
		if ( array_key_exists( 'auto', $p ) ) {
			BizCity_Skills_Samples::set_auto( (bool) $p['auto'] );
			if ( ! array_key_exists( 'names', $p ) ) {
				return new WP_REST_Response( array( 'ok' => true, 'auto' => BizCity_Skills_Samples::auto_enabled() ), 200 );
			}
		}
		$names = array_values( array_filter( array_map( 'sanitize_text_field', array_map( 'strval', (array) ( $p['names'] ?? array() ) ) ), 'strlen' ) );
		if ( ! $names ) {
			return self::error( new WP_Error( 'skill_invalid', 'Chọn ít nhất một skill mẫu để cài.' ), 400 );
		}
		$res = BizCity_Skills_Samples::install( $names, ! array_key_exists( 'approve', $p ) || (bool) $p['approve'] );
		if ( $res['installed'] ) {
			self::changed();
		}
		return new WP_REST_Response( array( 'ok' => true ) + $res, 200 );
	}

	/** [2026-10-17 04:13 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.117 S117-K6 — POST /status {name, status}; someone else's skill needs manage_options */
	public static function post_status( WP_REST_Request $req ): WP_REST_Response {
		$p    = (array) $req->get_json_params();
		$name = BizCity_Skills_Service::clean_name( (string) ( $p['name'] ?? '' ) );
		$twin = '' !== $name ? BizCity_Skills_Service::get_twin( $name ) : null;
		$ref  = class_exists( 'BizCity_Agent_Mode_Access' ) ? BizCity_Agent_Mode_Access::user_ref( get_current_user_id() ) : '';
		$own  = $twin ? (string) ( $twin['skill']['owner']['user_ref'] ?? '' ) : '';
		if ( '' !== $own && $own !== $ref && ! current_user_can( 'manage_options' ) ) {
			return self::error( new WP_Error( 'skill_not_owner', 'Skill này của người khác.' ), 403 );
		}
		$r = BizCity_Skills_Service::set_status( array( 'name' => $name, 'status' => (string) ( $p['status'] ?? '' ) ) );
		if ( is_wp_error( $r ) ) {
			$code = $r->get_error_code();
			return self::error( $r, 'skill_not_found' === $code ? 404 : ( 'skill_not_checked' === $code ? 409 : 400 ) );
		}
		self::changed();
		return new WP_REST_Response( array( 'ok' => true ) + $r, 200 );
	}

	/**
	 * [2026-10-17 04:00 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.117 D117-24 — POST /twin {name, twin}: the cell checks (no model), the site stores
	 * what the cell answered (approvals completed) with a new version. Cell away ⇒ still saved as unchecked (a draft may wait), never publishable.
	 * The owner is the person saving; someone else's skill needs manage_options.
	 */
	public static function post_twin( WP_REST_Request $req ): WP_REST_Response {
		$p     = (array) $req->get_json_params();
		$name  = BizCity_Skills_Service::clean_name( (string) ( $p['name'] ?? '' ) );
		$twin  = $p['twin'] ?? null;
		$skill = '' !== $name ? BizCity_Skills_Service::get( $name ) : null;
		if ( ! $skill ) {
			return self::error( new WP_Error( 'skill_not_found', 'Tạo skill trước (tên + mô tả), rồi mới lưu các bước.' ), 404 );
		}
		if ( ! is_array( $twin ) ) {
			return self::error( new WP_Error( 'skill_invalid', 'Thiếu các bước (twin-skill@1).' ), 400 );
		}
		$uid = get_current_user_id();
		$ref = class_exists( 'BizCity_Agent_Mode_Access' ) ? BizCity_Agent_Mode_Access::user_ref( $uid ) : '';
		$old = BizCity_Skills_Service::get_twin( $name );
		$was = $old ? (string) ( $old['skill']['owner']['user_ref'] ?? '' ) : '';
		if ( '' !== $was && $was !== $ref && ! current_user_can( 'manage_options' ) ) {
			return self::error( new WP_Error( 'skill_not_owner', 'Skill này của người khác.' ), 403 );
		}
		$twin['owner'] = array(
			'user_ref'   => '' !== $was ? $was : $ref,
			'visibility' => ( 'shop' === ( $twin['owner']['visibility'] ?? '' ) && current_user_can( 'manage_options' ) ) ? 'shop' : 'private',
		);
		// [2026-10-22 12:11 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.127 S127-C6 (twin-skill@1.1) — the SKILL.md body goes with it: its ```delegation block is checked by the
		// cell's own code (same parser it compiles with); a broken block is a check error ⇒ the version is not publishable
		$r     = self::hub( 'POST', self::HUB_TWIN_CHECK, array( 'skill' => $twin, 'body' => mb_substr( (string) ( $skill['body_md'] ?? '' ), 0, 8192 ) ) );
		$valid = $r['ok'] && ! empty( $r['body']['valid'] );
		$errs  = $r['ok'] && is_array( $r['body']['errors'] ?? null ) ? $r['body']['errors'] : array();
		if ( $r['ok'] && is_array( $r['body']['skill'] ?? null ) ) {
			$twin = $r['body']['skill']; // approvals completed by the checker — store THAT, never the unchecked input
		}
		$saved = BizCity_Skills_Service::save( array( 'name' => $name, 'twin' => $twin, 'twin_check' => array( 'valid' => $valid, 'degraded' => ! $r['ok'], 'errors' => count( $errs ) ) ) );
		if ( is_wp_error( $saved ) ) {
			return self::error( $saved, 'skill_check_failed' === $saved->get_error_code() ? 409 : 400 );
		}
		self::changed();
		return new WP_REST_Response( array(
			'ok'        => true,
			'name'      => $name,
			'version'   => (int) $saved['version'],
			'changed'   => $saved['changed'],
			'valid'     => $valid,
			'errors'    => array_slice( $errs, 0, 30 ),
			'warnings'  => $r['ok'] && is_array( $r['body']['warnings'] ?? null ) ? array_slice( $r['body']['warnings'], 0, 30 ) : array(),
			// S127-C6: the pieces the cell read from the body (piece · goal · group · output · rubric) — the Editor lists them; never written here
			'delegation' => $r['ok'] && is_array( $r['body']['delegation']['pieces'] ?? null ) ? array_slice( $r['body']['delegation']['pieces'], 0, 8 ) : array(),
			'_degraded' => ! $r['ok'],
			'hint'      => $r['ok'] ? '' : 'Đã lưu nháp; chưa kiểm được vì trợ lý đang vắng — bấm Lưu lại sau ít phút để kiểm trước khi xuất bản.',
		), 200 );
	}

	/**
	 * [2026-10-12 10:05 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.105 S105-K1 — GET /sync-status?name= (doc 20 §2). Read-only. Hub unreachable never
	 * errors: the state just stops at what the site knows (saved / notified) and the cell rows carry `error`.
	 * ① saved (the file) → ② notified (invalidate option) → ③ received (cell pack version = site pack version) → ④ understood (plan hash = file hash).
	 */
	public static function sync_status( WP_REST_Request $req ): WP_REST_Response {
		$name = class_exists( 'BizCity_Skills_Service', false ) ? self::resolve_sync_name( (string) $req->get_param( 'name' ) ) : ''; // [2026-10-12 10:05 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.105 S105-K1 - accepts a skill name OR a file path
		if ( '' === $name ) {
			return self::error( new WP_Error( 'skill_invalid', 'Thiếu tên skill.' ), 400 );
		}
		$skill = BizCity_Skills_Service::get( $name );
		if ( ! $skill ) {
			return self::error( new WP_Error( 'not_found', 'Không thấy skill này.' ), 404 );
		}
		$site_version = class_exists( 'BizCity_Skills_Pack_Exporter', false ) ? (string) ( BizCity_Skills_Pack_Exporter::stats()['version'] ?? '' ) : '';
		$in_pack      = 'approved' === (string) $skill['status'];
		$notified     = is_callable( self::$notified ) ? call_user_func( self::$notified ) : get_option( self::NOTIFY_OPTION, null );
		$notified     = is_array( $notified ) ? array( 'at' => (int) ( $notified['at'] ?? 0 ), 'ok' => ! empty( $notified['ok'] ), 'code' => sanitize_key( (string) ( $notified['code'] ?? '' ) ) ) : null;
		$cells        = array();
		$targets      = self::sync_targets();
		foreach ( $targets as $account ) {
			$r  = self::hub( 'GET', self::HUB_CC_SKILLS . '?account_id=' . rawurlencode( $account ) . '&names=' . rawurlencode( $name ) );
			$b  = $r['body'];
			$pk = is_array( $b['pack'] ?? null ) ? $b['pack'] : null;
			$row = null;
			foreach ( (array) ( $b['plans'] ?? array() ) as $p ) {
				if ( is_array( $p ) && (string) ( $p['name'] ?? '' ) === $name ) {
					$row = $p;
					break;
				}
			}
			$cell = array(
				'account'      => self::mask_account( $account ),
				'pack_version' => $pk ? (string) ( $pk['version'] ?? '' ) : null,
				'synced_at'    => $pk ? (string) ( $pk['synced_at'] ?? '' ) : null,
				'stale'        => $pk ? ! empty( $pk['stale'] ) : null,
				'plan'         => $row ? array(
					'body_hash'   => (string) ( $row['body_hash'] ?? '' ),
					'compiled_at' => $row['compiled_at'] ?? null,
					'status'      => (string) ( $row['status'] ?? '' ),
					'matches'     => '' !== (string) ( $row['body_hash'] ?? '' ) && hash_equals( (string) $skill['hash'], (string) $row['body_hash'] ) && ! in_array( (string) ( $row['status'] ?? '' ), array( 'failed', 'error' ), true ),
				) : null,
			);
			if ( ! $r['ok'] ) {
				$cell['error'] = (string) $r['code'];
			}
			$cells[] = $cell;
		}
		return new WP_REST_Response( array(
			'ok'               => true,
			'name'             => $name,
			'saved_at'         => (string) $skill['updated_at'],
			'site_pack_version' => $site_version,
			'item_hash'        => (string) $skill['hash'],
			'in_pack'          => $in_pack,
			'notified'         => $notified ? array( 'at' => gmdate( 'Y-m-d\TH:i:s\Z', $notified['at'] ), 'ok' => $notified['ok'], 'code' => $notified['code'] ) : null,
			'cells'            => $cells,
			'state'            => self::sync_state( $in_pack, $site_version, $notified, (string) $skill['updated_at'], $targets ? $cells : null ),
		), 200 );
	}

	/** A value ending in .md or containing "/" is a file path (SPA: "skills/x.md"): frontmatter `name`, else the basename; otherwise it is the skill name. */
	public static function resolve_sync_name( string $value ): string {
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}
		if ( ! preg_match( '/\.md$/i', $value ) && false === strpos( $value, '/' ) ) {
			return BizCity_Skills_Service::clean_name( $value );
		}
		$path = '/' . ltrim( sanitize_text_field( $value ), '/' );
		$data = BizCity_Skill_Manager::instance()->read_file( $path );
		if ( ! is_wp_error( $data ) ) {
			$sk = BizCity_Skills_Service::from_raw( (string) $data['raw'], $path );
			if ( $sk ) {
				return (string) $sk['name'];
			}
		}
		return BizCity_Skills_Service::clean_name( basename( $value, '.md' ) );
	}

	/**
	 * Pure state machine. understood > received > failed > notified > saved; no cell target at all ⇒ not_applicable (the caller passes
	 * `$cells` = null for "site has no number and no web home"). A skill that is not approved is not in the pack, so it stops at
	 * notified/saved. A notice older than the file is not "this save" (still saved).
	 *
	 * @param array|null $notified {at:int, ok:bool, code}
	 * @param array|null $cells    rows of sync_status(); null = no target
	 */
	public static function sync_state( bool $in_pack, string $site_version, ?array $notified, string $saved_at, ?array $cells ): string {
		if ( null === $cells ) {
			return 'not_applicable';
		}
		if ( $in_pack && '' !== $site_version ) {
			$received = false;
			foreach ( $cells as $c ) {
				if ( isset( $c['pack_version'] ) && (string) $c['pack_version'] === $site_version ) {
					$received = true;
					if ( ! empty( $c['plan']['matches'] ) ) {
						return 'understood';
					}
				}
			}
			if ( $received ) {
				return 'received';
			}
		}
		$saved_ts = '' !== $saved_at ? (int) strtotime( $saved_at ) : 0;
		if ( $notified && $notified['at'] >= $saved_ts ) {
			return $notified['ok'] ? 'notified' : 'failed';
		}
		return 'saved';
	}

	/** @return string[] ≤ SYNC_MAX_CELLS account ids to ask: the site's numbers, then its web home `web-<key_id>`. */
	private static function sync_targets(): array {
		$ids = array();
		if ( is_callable( self::$accounts ) ) {
			$ids = array_map( 'strval', (array) call_user_func( self::$accounts ) );
		} elseif ( class_exists( 'BizCity_Zalo_Hub_Config_Sync' ) && method_exists( 'BizCity_Zalo_Hub_Config_Sync', 'accounts' ) ) {
			foreach ( (array) BizCity_Zalo_Hub_Config_Sync::accounts() as $a ) {
				$id = preg_replace( '/[^0-9]/', '', (string) ( $a['bridge_id'] ?? '' ) );
				if ( '' !== $id ) {
					$ids[] = $id;
				}
			}
		}
		$web = is_callable( self::$web ) ? (string) call_user_func( self::$web ) : ( class_exists( 'BizCity_Twin_Web_Turn', false ) && method_exists( 'BizCity_Twin_Web_Turn', 'web_account_id' ) ? BizCity_Twin_Web_Turn::web_account_id() : '' );
		if ( '' !== $web ) {
			$ids[] = $web;
		}
		return array_slice( array_values( array_unique( $ids ) ), 0, self::SYNC_MAX_CELLS );
	}

	/** "…8899" for a number, "web" for the web home - never a full id. */
	private static function mask_account( string $account ): string {
		if ( 0 === strpos( $account, 'web-' ) ) {
			return 'web';
		}
		$d = preg_replace( '/\D/', '', $account );
		return '…' . substr( (string) $d, -4 );
	}

	/** Số Zalo đầu tiên của site (kế hoạch dịch theo tenant, số nào cũng dẫn tới cùng cell/tenant). */
	private static function account_id(): string {
		if ( is_callable( self::$accounts ) ) {
			$ids = (array) call_user_func( self::$accounts );
			return isset( $ids[0] ) ? (string) $ids[0] : '';
		}
		if ( ! class_exists( 'BizCity_Zalo_Hub_Config_Sync' ) || ! method_exists( 'BizCity_Zalo_Hub_Config_Sync', 'accounts' ) ) {
			return '';
		}
		foreach ( (array) BizCity_Zalo_Hub_Config_Sync::accounts() as $a ) {
			$id = preg_replace( '/[^0-9]/', '', (string) ( $a['bridge_id'] ?? '' ) );
			if ( '' !== $id ) {
				return $id;
			}
		}
		return '';
	}

	/** @return array{ok:bool, body:array, error:string, code:string} */
	private static function hub( string $method, string $path, array $body = array() ): array {
		if ( is_callable( self::$hub ) ) {
			$r = (array) call_user_func( self::$hub, $method, $path, $body );
		} elseif ( class_exists( 'BizCity_Zalo_Personal_Hub_Client' ) && BizCity_Zalo_Personal_Hub_Client::instance()->is_ready_fast() ) {
			$c = BizCity_Zalo_Personal_Hub_Client::instance();
			$r = 'GET' === $method ? (array) $c->get_managed_path( $path ) : (array) $c->post_managed_path( $path, $body );
		} else {
			return array( 'ok' => false, 'body' => array(), 'error' => 'Website chưa có khoá kết nối BizCity (1API).', 'code' => 'api_key_missing' );
		}
		if ( array_key_exists( 'ok', $r ) && true === $r['ok'] ) {
			return array( 'ok' => true, 'body' => $r, 'error' => '', 'code' => '' );
		}
		$code = (string) ( $r['code'] ?? 'hub_error' );
		$msg  = (string) ( $r['message'] ?? '' );
		return array( 'ok' => false, 'body' => $r, 'error' => '' !== $msg ? $msg : 'Hub không trả lời (' . $code . ').', 'code' => $code );
	}

	/** R-GW-8: 200 + success:false + _degraded, bốn trường lỗi — SPA hiện câu nhẹ, không làm hỏng việc lưu. */
	private static function degraded( string $code, string $message, string $hint ): WP_REST_Response {
		return new WP_REST_Response( array( 'ok' => false, 'success' => false, '_degraded' => true, 'code' => $code, 'error' => $message, 'message' => $message, 'hint' => $hint, 'help_code' => 'skills_' . $code ), 200 );
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
			// [2026-10-12 10:05 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.105 S105-K4 — rebuild the pack transient NOW: the cell's 10-minute reconcile
			// (or its pull after the notice) must never read an empty/old cache and see the previous version (doc 20 G4).
			BizCity_Skills_Pack_Exporter::stats();
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
			'title_required' => 'Gõ tên skill, ví dụ "Nghiên cứu Facebook".',
			'skill_limit'   => 'Tắt hoặc xoá bớt skill cũ rồi tạo lại.',
			// [2026-10-17 04:00 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.117 D117-24
			'skill_not_found'    => 'Tải lại danh sách skill rồi chọn lại.',
			'skill_not_checked'  => 'Mở Editor, bấm Lưu để trợ lý kiểm các bước, sửa lỗi đỏ rồi xuất bản.',
			'skill_check_failed' => 'Chuyển skill về nháp, sửa các bước cho xanh rồi xuất bản lại.',
			'skill_not_owner'    => 'Nhờ người tạo skill hoặc quản trị viên sửa.',
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
