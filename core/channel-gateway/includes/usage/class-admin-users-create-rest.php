<?php
/**
 * Biz Central Brain — Johnny Chu (Chu Hoàng Anh). Bizcity Central Brain, Giấy chứng nhận đăng ký quyền tác giả số 8877/2026/QTG.
 */
// [2026-10-18 12:48 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.117 S117-V2 (doc 81 §4.2, R-ADMIN-12, D117-V2-3) — new file: "Thêm người dùng" on the admin page.
/**
 * POST bizcity-channel/v1/admin/users   (contract admin-user-create@1.0.0)
 *
 * A thin wrapper over WordPress core user creation, so the admin "Người dùng" page can add a person in a sheet:
 *   - the e-mail already belongs to a user of THIS blog  ⇒ `user_exists_on_site` (the id is returned, nothing written);
 *   - the e-mail belongs to a network user (multisite)     ⇒ add_user_to_blog() with the chosen role;
 *   - otherwise                                             ⇒ wp_insert_user() / wpmu_create_user() + add_user_to_blog().
 * Why not wp/v2/users: on multisite it is super-admin only, it never adds an existing network user to the blog, and it does not
 * return the four R-ERROR-UX fields the sheet shows.
 *
 * Rules: the role must be one this site has AND in the allow list — never `administrator` (R-ADMIN-3 / R-ADMIN-12); on multisite a
 * blog admin may create a NEW network user only when the network setting `add_new_users` is on (same as wp-admin). One audit line per
 * write (who, new id, role — never the e-mail). The response never carries the e-mail or a password.
 *
 * Cache contract (R-CACHE): no read cache of its own; a successful write flushes `cg_admin_users` (the overview list).
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\ChannelGateway\Usage
 */

defined( 'ABSPATH' ) || exit;

// Loaded with require_once only (same as the overview class).
final class BizCity_Admin_Users_Create_REST {

	const NS            = 'bizcity-channel/v1';
	const CONTRACT      = 'admin-user-create@1.0.0';
	const ALLOWED_ROLES = array( 'subscriber', 'customer', 'contributor', 'author', 'editor' );

	private static $initialized = false;

	public static function init(): void {
		if ( self::$initialized ) {
			return;
		}
		self::$initialized = true;
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes(): void {
		register_rest_route( self::NS, '/admin/users', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'route' ),
			'permission_callback' => array( 'BizCity_Admin_Users_Overview_REST', 'can_manage' ), // R-ADMIN-2: one admin gate
		) );
	}

	/**
	 * Validate the body. Pure (the role list of the site is passed in).
	 *
	 * @param array    $body       raw body
	 * @param string[] $site_roles role slugs that exist on this site
	 * @return array{ok:bool, code?:string, message?:string, hint?:string, name?:string, email?:string, role?:string, invite?:bool}
	 */
	public static function validate_create( array $body, array $site_roles ): array {
		$name  = trim( wp_strip_all_tags( (string) ( $body['display_name'] ?? '' ) ) );
		$email = trim( (string) ( $body['email'] ?? '' ) );
		$role  = strtolower( trim( (string) ( $body['role'] ?? 'subscriber' ) ) );
		$len   = function_exists( 'mb_strlen' ) ? mb_strlen( $name ) : strlen( $name );
		if ( $len < 2 || $len > 80 ) {
			return array( 'ok' => false, 'code' => 'invalid_param', 'message' => 'Họ tên cần từ 2 đến 80 ký tự.', 'hint' => 'Nhập họ tên rồi bấm lại.' );
		}
		if ( ! is_email( $email ) ) {
			return array( 'ok' => false, 'code' => 'invalid_param', 'message' => 'Email chưa đúng.', 'hint' => 'Nhập email dạng an@congty.vn rồi bấm lại.' );
		}
		if ( ! in_array( $role, self::ALLOWED_ROLES, true ) || ! in_array( $role, $site_roles, true ) ) {
			return array( 'ok' => false, 'code' => 'role_not_allowed', 'message' => 'Vai trò này không được tạo từ trang Người dùng.', 'hint' => 'Chọn Thành viên, Khách hàng hoặc Biên tập; đổi vai trò khác ở hồ sơ WordPress.' );
		}
		return array( 'ok' => true, 'name' => $name, 'email' => $email, 'role' => $role, 'invite' => ! isset( $body['invite'] ) || ! empty( $body['invite'] ) );
	}

	/** A login from the e-mail's local part, made unique (wp_insert_user needs one). Pure apart from username_exists(). */
	public static function login_from( string $email ): string {
		$base = sanitize_user( strtolower( (string) strstr( $email, '@', true ) ), true );
		$base = '' === $base ? 'user' : substr( $base, 0, 50 );
		$login = $base;
		for ( $i = 2; username_exists( $login ) && $i < 1000; $i++ ) {
			$login = $base . $i;
		}
		return $login;
	}

	private static function fail( string $code, string $message, string $hint, int $status = 200, array $extra = array() ): WP_REST_Response {
		return new WP_REST_Response( array_merge( array( 'success' => false, 'contract' => self::CONTRACT, 'code' => $code, 'message' => $message, 'hint' => $hint, 'help_code' => 'admin_users_create' ), $extra ), $status );
	}

	public static function route( WP_REST_Request $request ) {
		$blog  = (int) get_current_blog_id();
		$roles = function_exists( 'wp_roles' ) ? array_keys( (array) wp_roles()->roles ) : array();
		$v     = self::validate_create( (array) $request->get_json_params() + (array) $request->get_body_params(), $roles );
		if ( empty( $v['ok'] ) ) {
			return self::fail( (string) $v['code'], (string) $v['message'], (string) $v['hint'] );
		}

		$created = false;
		$added   = false;
		try {
			$existing = get_user_by( 'email', $v['email'] );
			if ( $existing ) {
				$uid = (int) $existing->ID;
				if ( ! is_multisite() || is_user_member_of_blog( $uid, $blog ) ) {
					return self::fail( 'user_exists_on_site', 'Email này đã là người dùng của website.', 'Tìm người đó trong danh sách rồi bấm Gắn số.', 200, array( 'user' => array( 'id' => $uid, 'name' => (string) $existing->display_name ) ) );
				}
				$res = add_user_to_blog( $blog, $uid, $v['role'] );
				if ( is_wp_error( $res ) ) {
					return self::fail( 'user_create_failed', 'Chưa thêm được người này vào website.', 'Thử lại sau ít phút.' );
				}
				$added = true;
			} else {
				if ( is_multisite() && ! is_super_admin() && ! get_site_option( 'add_new_users' ) ) {
					return self::fail( 'network_signup_closed', 'Mạng website chưa cho quản trị viên tạo người dùng mới.', 'Nhờ quản trị mạng bật "Thêm người dùng mới" hoặc tạo giúp tài khoản này.' );
				}
				$login = self::login_from( $v['email'] );
				$pass  = wp_generate_password( 24, true, true );
				if ( is_multisite() ) {
					$uid = (int) wpmu_create_user( $login, $pass, $v['email'] );
					if ( $uid <= 0 ) {
						return self::fail( 'user_create_failed', 'Chưa tạo được người dùng.', 'Thử lại sau ít phút.' );
					}
					wp_update_user( array( 'ID' => $uid, 'display_name' => $v['name'], 'first_name' => $v['name'] ) );
					$res = add_user_to_blog( $blog, $uid, $v['role'] );
					if ( is_wp_error( $res ) ) {
						return self::fail( 'user_create_failed', 'Đã tạo tài khoản nhưng chưa thêm được vào website.', 'Thử lại; email đã có sẽ được thêm vào website.' );
					}
				} else {
					$uid = wp_insert_user( array( 'user_login' => $login, 'user_pass' => $pass, 'user_email' => $v['email'], 'display_name' => $v['name'], 'first_name' => $v['name'], 'role' => $v['role'] ) );
					if ( is_wp_error( $uid ) ) {
						return self::fail( 'user_create_failed', 'Chưa tạo được người dùng.', 'Kiểm tra email rồi thử lại.' );
					}
					$uid = (int) $uid;
				}
				$created = true;
				if ( $v['invite'] && function_exists( 'wp_new_user_notification' ) ) {
					wp_new_user_notification( $uid, null, 'user' );
				}
			}
		} catch ( Throwable $e ) {
			if ( class_exists( 'BizCity_Channel_File_Logger' ) ) {
				BizCity_Channel_File_Logger::write( 'channel_gateway', 'error', 'admin_user_create_failed', 'Admin user create failed', array( 'error' => substr( $e->getMessage(), 0, 120 ) ) );
			}
			return self::fail( 'user_create_failed', 'Chưa tạo được người dùng.', 'Thử lại sau ít phút.' );
		}

		if ( class_exists( 'BizCity_Channel_File_Logger' ) ) {
			BizCity_Channel_File_Logger::write( 'channel_gateway', 'info', 'admin_user_created', 'Admin added a user from the Người dùng page', array( 'actor' => (int) get_current_user_id(), 'target' => $uid, 'role' => $v['role'], 'created' => $created, 'added' => $added, 'blog' => $blog ) );
		}
		if ( class_exists( 'BizCity_Cache' ) && class_exists( 'BizCity_Admin_Users_Overview_REST' ) ) {
			BizCity_Cache::flush_group( BizCity_Admin_Users_Overview_REST::CACHE_GROUP );
		}
		return new WP_REST_Response( array(
			'success'       => true,
			'contract'      => self::CONTRACT,
			'user'          => array( 'id' => $uid, 'name' => $v['name'] ),
			'created'       => $created,
			'added_to_blog' => $added,
		), 200 );
	}
}
