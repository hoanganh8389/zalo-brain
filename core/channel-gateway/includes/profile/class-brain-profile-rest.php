<?php
/**
 * BizCity_Brain_Profile_REST — namespace `bizcity/v1/brain-profiles` (PHASE-0.100 S100-L6, INTERFACES I-6).
 * The UI lane codes against this shape as a fixed target — routes and response shapes here MUST match I-6
 * exactly; a deviation is called out in the hand-off report, not made silently.
 *
 * Read = `edit_posts` (templates/get/numbers/drafts), write/install/apply = `manage_options` (grant mode,
 * doc 30 §3 step ④ needs admin). Errors are always the 4-field shape {code,message,hint,help_code}.
 *
 * Biz Central Brain — Johnny Chu (Chu Hoàng Anh).
 *
 * // [2026-10-12 09:48 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.100 S100-L6 — new file.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Channel_Gateway\Profile
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Brain_Profile_REST', false ) ) {
	return;
}

final class BizCity_Brain_Profile_REST {

	const NS   = 'bizcity/v1';
	const BASE = '/brain-profiles';

	private static $hooked = false;

	public static function init(): void {
		if ( self::$hooked ) {
			return;
		}
		self::$hooked = true;
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function can_read() {
		return current_user_can( 'edit_posts' );
	}

	public static function can_write() {
		return current_user_can( 'manage_options' );
	}

	public static function register_routes(): void {
		register_rest_route( self::NS, self::BASE . '/templates', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'templates' ), 'permission_callback' => array( __CLASS__, 'can_read' ) ) );
		register_rest_route( self::NS, self::BASE . '/drafts', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'drafts' ), 'permission_callback' => array( __CLASS__, 'can_read' ) ) );
		register_rest_route( self::NS, self::BASE . '/upload', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'upload' ), 'permission_callback' => array( __CLASS__, 'can_write' ) ) );
		register_rest_route( self::NS, self::BASE . '/guru/(?P<guru_id>\d+)', array(
			array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'get_guru' ), 'permission_callback' => array( __CLASS__, 'can_read' ) ),
			array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'save_guru' ), 'permission_callback' => array( __CLASS__, 'can_write' ) ),
		) );
		register_rest_route( self::NS, self::BASE . '/guru/(?P<guru_id>\d+)/plan', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'plan' ), 'permission_callback' => array( __CLASS__, 'can_write' ) ) );
		register_rest_route( self::NS, self::BASE . '/guru/(?P<guru_id>\d+)/install', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'install' ), 'permission_callback' => array( __CLASS__, 'can_write' ) ) );
		register_rest_route( self::NS, self::BASE . '/guru/(?P<guru_id>\d+)/export', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'export' ), 'permission_callback' => array( __CLASS__, 'can_read' ) ) );
		register_rest_route( self::NS, self::BASE . '/guru/(?P<guru_id>\d+)/numbers', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'numbers' ), 'permission_callback' => array( __CLASS__, 'can_read' ) ) );
		register_rest_route( self::NS, self::BASE . '/guru/(?P<guru_id>\d+)/numbers/(?P<account_id>[a-zA-Z0-9_-]+)/apply', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'apply_numbers' ), 'permission_callback' => array( __CLASS__, 'can_write' ) ) );
	}

	private static function err( string $code, string $message, string $hint, int $status = 400, array $extra = array() ) {
		return new WP_REST_Response( array_merge( array( 'code' => $code, 'message' => $message, 'hint' => $hint, 'help_code' => $code ), $extra ), $status );
	}

	private static function not_loaded() {
		return self::err( 'module_not_loaded', 'Profile service chưa sẵn sàng.', 'Kiểm tra module core/channel-gateway/includes/profile.', 503 );
	}

	/* ── GET /templates ─────────────────────────────────────────── */
	public static function templates( WP_REST_Request $req ): WP_REST_Response {
		if ( ! class_exists( 'BizCity_Brain_Profile_Service' ) ) {
			return self::not_loaded();
		}
		$r = BizCity_Brain_Profile_Service::list_templates();
		$items = array_map( static function ( $i ) { unset( $i['_dir'] ); return $i; }, $r['items'] );
		return new WP_REST_Response( array( 'items' => $items ), 200 );
	}

	/* ── GET /drafts ────────────────────────────────────────────── */
	public static function drafts( WP_REST_Request $req ): WP_REST_Response {
		$items = array();
		if ( class_exists( 'BizCity_Brain_Profile_Store' ) ) {
			$base = BizCity_Brain_Profile_Store::base_dir() . '_incoming/';
			foreach ( is_dir( $base ) ? ( glob( $base . '*', GLOB_ONLYDIR ) ?: array() ) : array() as $d ) {
				$draft_id = basename( $d );
				$profile  = BizCity_Brain_Profile_Store::read_json( rtrim( $d, '/\\' ) . '/profile.json' );
				$draft    = BizCity_Brain_Profile_Store::read_json( rtrim( $d, '/\\' ) . '/DRAFT.json' );
				if ( ! $profile ) {
					continue;
				}
				$items[] = array(
					'draft_id'   => $draft_id,
					'name'       => (string) ( $profile['name'] ?? '' ),
					'family'     => (string) ( $profile['family'] ?? '' ),
					'created_at' => (string) ( $draft['at'] ?? '' ),
					'from'       => (string) ( $draft['from_tool'] ?? '' ),
				);
			}
		}
		return new WP_REST_Response( array( 'items' => $items ), 200 );
	}

	/* ── POST /upload (zip or folder path already extracted) ────── */
	public static function upload( WP_REST_Request $req ) {
		if ( ! class_exists( 'BizCity_Brain_Profile_Service' ) || ! class_exists( 'BizCity_Brain_Profile_Store' ) ) {
			return self::not_loaded();
		}
		$files = $req->get_file_params();
		if ( empty( $files['file']['tmp_name'] ) ) {
			return self::err( 'profile_invalid', 'Thiếu file .zip.', 'Chọn một file .zip để tải lên.', 422 );
		}
		if ( ! class_exists( 'ZipArchive' ) ) {
			return self::err( 'module_not_loaded', 'Máy chủ không hỗ trợ giải nén .zip.', 'Liên hệ quản trị kỹ thuật.', 503 );
		}
		$upload_id = wp_generate_uuid4();
		$dest      = BizCity_Brain_Profile_Store::draft_dir( 'upload-' . $upload_id );
		$zip       = new ZipArchive();
		if ( true !== $zip->open( $files['file']['tmp_name'] ) ) {
			return self::err( 'profile_invalid', 'Không mở được file .zip.', 'Kiểm tra file có bị hỏng không.', 422 );
		}
		$zip->extractTo( $dest );
		$zip->close();
		// a zip with one top-level folder: flatten it so profile.json is at $dest root.
		$inner = glob( $dest . '*', GLOB_ONLYDIR );
		if ( is_array( $inner ) && 1 === count( $inner ) && ! is_readable( $dest . 'profile.json' ) && is_readable( $inner[0] . '/profile.json' ) ) {
			$dest = rtrim( $inner[0], '/\\' ) . '/';
		}
		$v = BizCity_Brain_Profile_Service::validate( $dest );
		return new WP_REST_Response( array( 'upload_id' => $upload_id, 'validation' => array( 'ok' => $v['ok'], 'errors' => $v['findings'] ) ), 200 );
	}

	private static function resolve_dir_from_body( array $body ) {
		if ( ! empty( $body['template_id'] ) && class_exists( 'BizCity_Brain_Profile_Service' ) ) {
			foreach ( BizCity_Brain_Profile_Service::list_templates()['items'] as $t ) {
				if ( (string) $t['id'] === (string) $body['template_id'] ) {
					return $t['_dir'];
				}
			}
			return null;
		}
		if ( ! empty( $body['upload_id'] ) && class_exists( 'BizCity_Brain_Profile_Store' ) ) {
			$d = BizCity_Brain_Profile_Store::draft_dir( 'upload-' . sanitize_text_field( (string) $body['upload_id'] ) );
			return is_readable( $d . 'profile.json' ) ? $d : null;
		}
		return null;
	}

	/* ── GET/POST /guru/{id} ─────────────────────────────────────── */
	public static function get_guru( WP_REST_Request $req ) {
		$guru_id = (int) $req['guru_id'];
		if ( ! class_exists( 'BizCity_Brain_Profile_Store' ) ) {
			return self::not_loaded();
		}
		$installed = BizCity_Brain_Profile_Store::installed_of( $guru_id );
		$gd        = BizCity_Brain_Profile_Store::guru_dir( $guru_id );
		$program   = BizCity_Brain_Profile_Store::read_json( $gd . 'program.json' );
		$schedule  = BizCity_Brain_Profile_Store::read_json( $gd . 'schedule.json' );
		$policy    = BizCity_Brain_Profile_Store::read_json( $gd . 'policy.json' );
		$profile_j = BizCity_Brain_Profile_Store::read_json( $gd . 'profile.json' );
		$safety    = is_readable( $gd . 'safety.md' ) ? BizCity_Brain_Profile_Store::read_text( $gd . 'safety.md' ) : '';
		$scope     = class_exists( 'BizCity_Guru_Context_Resolver' ) ? BizCity_Guru_Context_Resolver::scope( $guru_id ) : array();
		return new WP_REST_Response( array(
			'guru_id'          => $guru_id,
			'profile'          => $installed ? array( 'id' => $installed['id'] ?? '', 'version' => $installed['version'] ?? '', 'family' => $installed['family'] ?? '' ) : null,
			'program'          => $program,
			'track'            => null,
			'safety_md'        => $safety,
			'journal_kinds'    => (array) ( $profile_j['journal_kinds'] ?? array() ),
			'schedule'         => $schedule,
			'tool_packs'       => (array) ( $profile_j['tool_packs'] ?? array() ),
			'policy'           => $policy,
			'guru_tools_off'   => (array) ( $policy['guru_tools_off'] ?? array() ),
			'number_defaults'  => array(),
		), 200 );
	}

	public static function save_guru( WP_REST_Request $req ) {
		$guru_id = (int) $req['guru_id'];
		if ( ! class_exists( 'BizCity_Brain_Profile_Store' ) ) {
			return self::not_loaded();
		}
		$body = (array) ( $req->get_json_params() ?: array() );
		$gd   = BizCity_Brain_Profile_Store::guru_dir( $guru_id );
		foreach ( array( 'program' => 'program.json', 'schedule' => 'schedule.json', 'policy' => 'policy.json' ) as $key => $file ) {
			if ( array_key_exists( $key, $body ) && is_array( $body[ $key ] ) ) {
				BizCity_Brain_Profile_Store::write_json( $gd . $file, $body[ $key ] );
			}
		}
		return self::get_guru( $req );
	}

	/* ── POST /guru/{id}/plan ────────────────────────────────────── */
	public static function plan( WP_REST_Request $req ) {
		if ( ! class_exists( 'BizCity_Brain_Profile_Service' ) ) {
			return self::not_loaded();
		}
		$body = (array) ( $req->get_json_params() ?: array() );
		$dir  = self::resolve_dir_from_body( $body );
		if ( null === $dir ) {
			return self::err( 'profile_file_missing', 'Không tìm thấy gói.', 'Chọn lại mẫu hoặc tải lên lại file.', 404 );
		}
		return new WP_REST_Response( BizCity_Brain_Profile_Service::plan( $dir, (int) $req['guru_id'] ), 200 );
	}

	/* ── POST /guru/{id}/install ─────────────────────────────────── */
	public static function install( WP_REST_Request $req ) {
		if ( ! class_exists( 'BizCity_Brain_Profile_Service' ) ) {
			return self::not_loaded();
		}
		$body = (array) ( $req->get_json_params() ?: array() );
		$dir  = self::resolve_dir_from_body( $body );
		if ( null === $dir ) {
			return self::err( 'profile_file_missing', 'Không tìm thấy gói.', 'Chọn lại mẫu hoặc tải lên lại file.', 404 );
		}
		$result = BizCity_Brain_Profile_Service::install( $dir, array( 'guru_id' => (int) $req['guru_id'], 'take_fields' => (array) ( $body['take_fields'] ?? array() ) ) );
		if ( is_wp_error( $result ) ) {
			$data = $result->get_error_data() ?: array();
			return self::err( $result->get_error_code(), $result->get_error_message(), (string) ( $data['hint'] ?? '' ), (int) ( $data['status'] ?? 400 ), array_diff_key( $data, array( 'status' => 0, 'hint' => 0 ) ) );
		}
		return new WP_REST_Response( $result, 200 );
	}

	/* ── GET /guru/{id}/export ───────────────────────────────────── */
	public static function export( WP_REST_Request $req ) {
		if ( ! class_exists( 'BizCity_Brain_Profile_Service' ) ) {
			return self::not_loaded();
		}
		$include = '1' === (string) $req->get_param( 'include_knowledge' );
		$result  = BizCity_Brain_Profile_Service::export( (int) $req['guru_id'], $include );
		if ( is_wp_error( $result ) ) {
			$data = $result->get_error_data() ?: array();
			return self::err( $result->get_error_code(), $result->get_error_message(), (string) ( $data['hint'] ?? '' ), (int) ( $data['status'] ?? 400 ) );
		}
		if ( is_readable( $result['file_path'] ) && is_file( $result['file_path'] ) ) {
			// Stream the zip. A folder export (no ZipArchive) returns its path instead of a binary body.
			header( 'Content-Type: application/zip' );
			header( 'Content-Disposition: attachment; filename="' . basename( $result['file_path'] ) . '"' );
			readfile( $result['file_path'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_readfile
			exit;
		}
		return new WP_REST_Response( array( 'folder' => $result['file_path'] ), 200 );
	}

	/* ── GET /guru/{id}/numbers, POST .../apply ──────────────────── */
	/**
	 * [2026-10-12 10:31 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.100 S100-L13 — the binding lookup flagged as
	 * missing in the hand-off report IS `BizCity_Channel_Binding` (`core/channel-gateway/includes/class-channel-binding.php`,
	 * table `wp_bizcity_channel_bindings`, column `character_id`, indexed). Wiring the READ side here (list of
	 * numbers bound to this Guru) — the WRITE side (`apply_numbers`) still can't map `number_defaults` keys to
	 * real `settings.bot.scope.*` fields: grep for `reply_only_mention`/`typing_indicator`/`auto_react` across
	 * the plugin found no existing storage key by those names, so those were INTERFACES.md's own invented names
	 * from the mockup's Vietnamese labels, not verified field names. Left `apply_numbers` untouched rather than
	 * guess a write path for a permission-sensitive per-number setting.
	 */
	public static function numbers( WP_REST_Request $req ): WP_REST_Response {
		$guru_id = (int) $req['guru_id'];
		if ( $guru_id <= 0 || ! class_exists( 'BizCity_Channel_Binding' ) ) {
			return new WP_REST_Response( array( 'items' => array() ), 200 );
		}
		$items = array();
		foreach ( BizCity_Channel_Binding::all() as $row ) {
			if ( (int) ( $row['character_id'] ?? 0 ) !== $guru_id ) {
				continue;
			}
			$account_id = (string) ( $row['account_id'] ?? '' );
			if ( '' === $account_id || '*' === $account_id ) {
				continue; // wildcard fallback binding, not a real number
			}
			$items[] = array(
				'account_id'   => $account_id,
				'platform'     => (string) ( $row['platform'] ?? '' ),
				'label_masked' => self::mask_account_label( $account_id ),
				'mode'         => (string) ( $row['mode'] ?? '' ),
				// [2026-10-12 10:31 AM] owner_name / per-number settings diff / schedule counts
				// are NOT wired (no verified source for them this pass — see class comment above); the UI lane's
				// own report already treats an absent/empty `suggested`/`diff`/`schedule` as "no suggestion yet".
			);
		}
		return new WP_REST_Response( array( 'items' => $items ), 200 );
	}

	/** R-CID-11: never the raw account id — last 3 chars visible, rest dotted, grouped like the mockup's "0562 ••• 899". */
	private static function mask_account_label( string $account_id ): string {
		$digits = preg_replace( '/\D+/', '', $account_id );
		$tail   = $digits !== '' ? $digits : $account_id;
		$len    = strlen( $tail );
		if ( $len <= 3 ) {
			return str_repeat( '•', max( 0, $len - 1 ) ) . substr( $tail, -1 );
		}
		return substr( $tail, 0, min( 4, $len - 3 ) ) . ' ••• ' . substr( $tail, -3 );
	}

	public static function apply_numbers( WP_REST_Request $req ) {
		// [2026-10-12 10:31 AM Johnny Chu - Chu Hoàng Anh] a real 4-field error, NOT a fake 200 — the UI's
		// onApplyNumber() shows "Đã áp gợi ý vào số." on any 2xx; a silent no-op 200 would lie to the chủ that a
		// setting was written when `settings.bot.scope.*` field names for these keys are still unverified (see
		// numbers() class comment). Remove this guard the moment a real write path lands.
		return self::err( 'number_defaults_not_wired', 'Chưa áp được gợi ý: chưa nối tới cài đặt thật của số.', 'Mở "Cài đặt bot" của số để sửa tay; phần tự động áp sẽ có ở bản sau.', 501 );
	}
}
