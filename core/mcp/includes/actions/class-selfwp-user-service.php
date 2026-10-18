<?php
/**
 * BizCity_SelfWP_User_Service — `wp.user.create` and `wp.user.set_role` (R3): the cell opens website accounts for the owner's people
 * (PHASE-0.118 W4 S118-L9, doc 20, D118-7, D118-19, Q118-5). The most sensitive executors of the phase, so the rules are all "no":
 *  - only the OWNER principal (tool roles = owner), mode `site_admin`, and the WordPress capabilities `create_users` / `promote_users`
 *    (+ the per-user `promote_user`) which only administrators hold — re-checked in every handler;
 *  - R3: preview → confirm every single time; the token is bound to the arguments; never schedulable, never "standing";
 *  - the role must be one of subscriber, customer, contributor, author, editor — never administrator, shop_manager or anything else;
 *  - no password is ever created here, returned or logged: the default writer makes a random one INSIDE WordPress and WordPress mails the person its
 *    "set your password" link (wp_new_user_notification); if that mail fails the answer says so;
 *  - set_role never touches yourself, an administrator / super admin, or anyone whose current role is outside the allowed list; it is not a way to
 *    rescue or demote privileged accounts;
 *  - e-mail addresses are masked in every answer, preview and audit line; accounts are not auto-reverted (a wrong account is removed by a person, doc 50 §4).
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\MCP
 * @since      2026-10-17 (PHASE-0.118 W4 S118-L9)
 */

defined( 'ABSPATH' ) || exit;

// [2026-10-17 10:00 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.118 S118-L9 — new file: wp.user.create + wp.user.set_role (R3).
final class BizCity_SelfWP_User_Service {

	/** the ONLY roles the assistant may hand out or move people between (Q118-5) */
	const ALLOWED_ROLES = array( 'subscriber', 'customer', 'contributor', 'author', 'editor' );

	/**
	 * Test seams, name → callable. Defaults call WordPress.
	 *   user_can(string $cap, ...$args): bool · role_exists(string $role): bool · email_exists(string $email): bool · username_exists(string $login): bool
	 *   insert_user(array $args): int|WP_Error   (args carry NO password; the default writer makes one inside WordPress)
	 *   notify_new_user(int $id): bool           (WordPress's own "set your password" mail)
	 *   get_user(int $id): array|null {id, email, name, roles:string[], is_admin:bool, is_member:bool}
	 *   set_role(int $id, string $role): bool
	 *
	 * @var array<string,callable>
	 */
	public static $seams = array();

	public static function init() {
		add_action( 'bizcity_mcp_register_tools', array( __CLASS__, 'register_tools' ) );
	}

	public static function register_tools() {
		$S     = 'BizCity_MCP_Tool_Registry';
		$roles = self::ALLOWED_ROLES;

		// @mcp bizcity-mcp-standard@1 tool wp.user.create
		BizCity_MCP_Tool_Registry::register( 'wp.user.create', array(
			'executor'       => 'wp.user.create',
			'title'          => 'Tạo tài khoản website',
			'description'    => 'Đồng bộ hệ thống (chỉ zalo-hub gọi, chỉ cho chủ website): tạo MỘT tài khoản WordPress với vai trò thuộc {subscriber, customer, contributor, author, editor} (không bao giờ administrator / shop_manager). KHÔNG đặt hay trả mật khẩu: WordPress gửi email đặt mật khẩu tới địa chỉ đó. Lần đầu chỉ trả bản xem trước + confirm_token, CHƯA tạo; gọi lại ĐÚNG tham số kèm confirm_token sau khi chủ đồng ý. Mỗi lần tạo đều phải duyệt.',
			'input_schema'   => array( 'type' => 'object', 'required' => array( 'email', 'role' ), 'properties' => array(
				'email'         => array( 'type' => 'string', 'maxLength' => 100 ),
				'role'          => array( 'type' => 'string', 'enum' => $roles ),
				'display_name'  => array( 'type' => 'string', 'maxLength' => 60 ),
				'confirm_token' => array( 'type' => 'string' ),
			) ),
			'output_schema'  => $S::envelope_schema( array( 'status' => array( 'type' => 'string' ), 'preview' => array( 'type' => 'object' ), 'confirm_token' => array( 'type' => 'string' ), 'expires_at' => array( 'type' => 'string' ), 'user_id' => array( 'type' => 'integer' ), 'role' => array( 'type' => 'string' ), 'username' => array( 'type' => 'string' ), 'email_masked' => array( 'type' => 'string' ), 'notified' => array( 'type' => 'boolean' ) ), array( 'status' ) ),
			'read_only'      => false,
			'destructive'    => false,
			'idempotent'     => false,
			'required_scope' => 'site.admin',
			'required_cap'   => 'create_users',
			'handler'        => array( __CLASS__, 'user_create' ),
			'preview'        => array( __CLASS__, 'user_create_preview' ),
			'mode'           => 'site_admin',
			'scopes'         => array( 'site.admin' ),
			'roles'          => array( 'owner' ),
			'confirm'        => 'always',
			'llm_alias'      => 'wp_user_create',
			'fallback_pack'  => null,
			'since'          => '0.118.0',
		) );

		// @mcp bizcity-mcp-standard@1 tool wp.user.set_role
		BizCity_MCP_Tool_Registry::register( 'wp.user.set_role', array(
			'executor'       => 'wp.user.set_role',
			'title'          => 'Đổi vai trò tài khoản website',
			'description'    => 'Đồng bộ hệ thống (chỉ zalo-hub gọi, chỉ cho chủ website): đổi vai trò của MỘT tài khoản sang {subscriber, customer, contributor, author, editor}. Không đổi được chính người gọi, quản trị viên / super admin, hay tài khoản đang có vai trò ngoài danh sách đó. Lần đầu chỉ trả bản xem trước + confirm_token, CHƯA đổi; gọi lại ĐÚNG tham số kèm confirm_token sau khi chủ đồng ý.',
			'input_schema'   => array( 'type' => 'object', 'required' => array( 'user_id', 'role' ), 'properties' => array(
				'user_id'       => array( 'type' => 'integer', 'minimum' => 1 ),
				'role'          => array( 'type' => 'string', 'enum' => $roles ),
				'confirm_token' => array( 'type' => 'string' ),
			) ),
			'output_schema'  => $S::envelope_schema( array( 'status' => array( 'type' => 'string' ), 'preview' => array( 'type' => 'object' ), 'confirm_token' => array( 'type' => 'string' ), 'expires_at' => array( 'type' => 'string' ), 'user_id' => array( 'type' => 'integer' ), 'previous_role' => array( 'type' => 'string' ), 'role' => array( 'type' => 'string' ), 'unchanged' => array( 'type' => 'boolean' ) ), array( 'status' ) ),
			'read_only'      => false,
			'destructive'    => false,
			'idempotent'     => false,
			'required_scope' => 'site.admin',
			'required_cap'   => 'promote_users',
			'handler'        => array( __CLASS__, 'set_role' ),
			'preview'        => array( __CLASS__, 'set_role_preview' ),
			'mode'           => 'site_admin',
			'scopes'         => array( 'site.admin' ),
			'roles'          => array( 'owner' ),
			'confirm'        => 'always',
			'llm_alias'      => 'wp_user_set_role',
			'fallback_pack'  => null,
			'since'          => '0.118.0',
		) );
	}

	/**
	 * The registry's `roles` key is only metadata for owner / staff principals (it filters customers, nothing else), so "owner only" is enforced HERE:
	 * the delegated principal must be the owner. A staff principal that happens to be a WordPress administrator is still refused.
	 *
	 * @return WP_Error|null
	 */
	private static function owner_only( array $ctx ) {
		if ( 'owner' !== (string) ( $ctx['role'] ?? '' ) ) {
			return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::PERMISSION_DENIED, 'Chỉ chủ website mới tạo tài khoản hoặc đổi vai trò qua trợ lý được.', 403, array( 'help_code' => 'selfwp_owner_only' ) );
		}
		return null;
	}

	/* ───────────────────────────── wp.user.create ──────────────────── */

	/** @return array|WP_Error {email, role, name, login} — nothing is written */
	private static function plan_create( array $args ) {
		if ( ! self::can( 'create_users' ) ) {
			return self::denied( 'create_users', 'tạo tài khoản' );
		}
		$role = self::checked_role( $args['role'] ?? null );
		if ( is_wp_error( $role ) ) {
			return $role;
		}
		$email = strtolower( trim( (string) ( $args['email'] ?? '' ) ) );
		if ( ! is_string( $args['email'] ?? null ) || ! BizCity_SelfWP_Action_MCP_Service::single_email( $email ) ) {
			return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::QUERY_INVALID, 'Email tài khoản không hợp lệ: chỉ nhận MỘT địa chỉ, dạng ten@ten-mien.vn (không dấu phẩy, không khoảng trắng).', 422, array( 'help_code' => 'selfwp_user_email_invalid' ) );
		}
		if ( self::seam( 'email_exists', array( $email ), static function ( $e ) {
			return function_exists( 'email_exists' ) && false !== email_exists( $e );
		} ) ) {
			return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::QUERY_INVALID, 'Đã có tài khoản dùng email ' . BizCity_SelfWP_Action_MCP_Service::mask_email( $email ) . '. Không tạo trùng; muốn đổi vai trò của tài khoản đó thì dùng wp.user.set_role.', 409, array( 'help_code' => 'selfwp_user_exists' ) );
		}
		$name = self::clean_name( (string) ( $args['display_name'] ?? '' ) );
		$local = (string) strstr( $email, '@', true );
		if ( '' === $name ) {
			$name = self::clean_name( $local );
		}
		$base = strtolower( (string) preg_replace( '/[^a-z0-9._-]/', '', $local ) );
		$base = strlen( $base ) >= 3 ? substr( $base, 0, 40 ) : 'user' . $base;
		$login = $base;
		for ( $i = 2; self::seam( 'username_exists', array( $login ), static function ( $l ) {
			return function_exists( 'username_exists' ) && false !== username_exists( $l );
		} ); $i++ ) {
			if ( $i > 30 ) {
				return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::QUERY_INVALID, 'Không tìm được tên đăng nhập còn trống từ email này.', 422, array( 'help_code' => 'selfwp_user_login_taken' ) );
			}
			$login = substr( $base, 0, 36 ) . $i;
		}
		return array( 'email' => $email, 'role' => $role, 'name' => $name, 'login' => $login );
	}

	/** @return array|WP_Error */
	public static function user_create_preview( array $args, array $ctx ) {
		return BizCity_MCP_Action_Support::run_as( $ctx, static function ( $uid ) use ( $args, $ctx ) {
			$no = self::owner_only( $ctx );
			if ( $no ) {
				return $no;
			}
			$p = self::plan_create( $args );
			if ( is_wp_error( $p ) ) {
				return $p;
			}
			$m = BizCity_SelfWP_Action_MCP_Service::mask_email( $p['email'] );
			return array(
				'role'    => $p['role'],
				'changes' => array( 'Tạo tài khoản «' . $p['name'] . '» <' . $m . '> vai trò ' . $p['role'] ),
				'summary' => 'Tạo tài khoản website «' . $p['name'] . '» (' . $m . ') với vai trò ' . $p['role'] . '. WordPress sẽ gửi email đặt mật khẩu tới địa chỉ đó; trợ lý không biết và không đặt mật khẩu.',
			);
		} );
	}

	/** @return array|WP_Error */
	public static function user_create( array $args, array $ctx ) {
		return BizCity_MCP_Action_Support::run_as( $ctx, static function ( $uid ) use ( $args, $ctx ) {
			$no = self::owner_only( $ctx );
			if ( $no ) {
				return $no;
			}
			$p = self::plan_create( $args );
			if ( is_wp_error( $p ) ) {
				return $p;
			}
			$id = self::seam( 'insert_user', array( array( 'user_login' => $p['login'], 'user_email' => $p['email'], 'display_name' => $p['name'], 'role' => $p['role'] ) ), array( __CLASS__, 'default_insert_user' ) );
			if ( is_wp_error( $id ) ) {
				return BizCity_MCP_Action_Support::from_business( $id );
			}
			$id = (int) $id;
			if ( $id <= 0 ) {
				return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::INTERNAL_ERROR, 'Không tạo được tài khoản.', 500 );
			}
			$notified = false;
			try {
				$notified = (bool) self::seam( 'notify_new_user', array( $id ), static function ( $id ) {
					if ( ! function_exists( 'wp_new_user_notification' ) ) {
						return false;
					}
					wp_new_user_notification( (int) $id, null, 'user' ); // 'user' = send the person the set-password link, never the admin a password
					return true;
				} );
			} catch ( \Throwable $e ) {
				$notified = false;
			}
			$m                     = BizCity_SelfWP_Action_MCP_Service::mask_email( $p['email'] );
			$entry                 = BizCity_SelfWP_Action_MCP_Service::change_entry( 'wp.user.create', 'R3', $uid, $ctx, $id, '', $p['role'], array( 'Tạo tài khoản «' . $p['name'] . '» <' . $m . '> vai trò ' . $p['role'] ), 'user' );
			BizCity_SelfWP_Action_MCP_Service::audit_change( $entry );
			return array( 'status' => 'done', 'user_id' => $id, 'role' => $p['role'], 'username' => $p['login'], 'email_masked' => $m, 'notified' => $notified );
		} );
	}

	/* ───────────────────────────── wp.user.set_role ──────────────────── */

	/** @return array|WP_Error {id, user, from, to} — nothing is written */
	private static function plan_role( array $args, int $me ) {
		if ( ! self::can( 'promote_users' ) ) {
			return self::denied( 'promote_users', 'đổi vai trò tài khoản' );
		}
		$id = is_int( $args['user_id'] ?? null ) ? (int) $args['user_id'] : 0;
		if ( $id <= 0 ) {
			return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::QUERY_INVALID, 'Thiếu mã tài khoản (user_id).', 422 );
		}
		$to = self::checked_role( $args['role'] ?? null );
		if ( is_wp_error( $to ) ) {
			return $to;
		}
		if ( $id === $me ) {
			return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::PERMISSION_DENIED, 'Không đổi vai trò của chính mình qua trợ lý.', 403, array( 'help_code' => 'selfwp_no_self_change' ) );
		}
		$user = self::seam( 'get_user', array( $id ), array( __CLASS__, 'default_get_user' ) );
		if ( ! is_array( $user ) || empty( $user['is_member'] ) ) {
			return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::NOT_FOUND, "Không có tài khoản #{$id} trên website này.", 404 );
		}
		if ( ! self::can( 'promote_user', $id ) ) {
			return self::denied( 'promote_user', "đổi vai trò tài khoản #{$id}" );
		}
		$cur = array_values( array_map( 'strval', (array) ( $user['roles'] ?? array() ) ) );
		if ( ! empty( $user['is_admin'] ) || array() !== array_diff( $cur, self::ALLOWED_ROLES ) ) { // 'administrator' is not in ALLOWED_ROLES, so any account that holds it is covered here
			return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::PERMISSION_DENIED, "Tài khoản #{$id} là quản trị viên hoặc có vai trò đặc biệt nên trợ lý không đổi vai trò (hãy đổi trong WordPress).", 403, array( 'help_code' => 'selfwp_user_protected' ) );
		}
		return array( 'id' => $id, 'user' => $user, 'from' => $cur, 'to' => $to );
	}

	private static function role_label( array $roles ): string {
		return array() === $roles ? '(chưa có vai trò)' : implode( ', ', $roles );
	}

	/** @return array|WP_Error */
	public static function set_role_preview( array $args, array $ctx ) {
		return BizCity_MCP_Action_Support::run_as( $ctx, static function ( $uid ) use ( $args, $ctx ) {
			$no = self::owner_only( $ctx );
			if ( $no ) {
				return $no;
			}
			$p = self::plan_role( $args, $uid );
			if ( is_wp_error( $p ) ) {
				return $p;
			}
			if ( array( $p['to'] ) === $p['from'] ) {
				return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::QUERY_INVALID, 'Tài khoản này đã có đúng vai trò đó rồi, không có gì để đổi.', 422, array( 'help_code' => 'selfwp_no_change' ) );
			}
			$who  = '«' . self::clean_name( (string) ( $p['user']['name'] ?? '' ) ) . '» (' . BizCity_SelfWP_Action_MCP_Service::mask_email( (string) ( $p['user']['email'] ?? '' ) ) . ')';
			$line = 'Vai trò ' . $who . ': ' . self::role_label( $p['from'] ) . ' → ' . $p['to'];
			return array( 'user_id' => $p['id'], 'role' => $p['to'], 'changes' => array( $line ), 'summary' => 'Đổi vai trò tài khoản #' . $p['id'] . ' ' . $who . ': ' . self::role_label( $p['from'] ) . ' → ' . $p['to'] . '. Quyền của người đó trên website đổi ngay.' );
		} );
	}

	/** @return array|WP_Error */
	public static function set_role( array $args, array $ctx ) {
		return BizCity_MCP_Action_Support::run_as( $ctx, static function ( $uid ) use ( $args, $ctx ) {
			$no = self::owner_only( $ctx );
			if ( $no ) {
				return $no;
			}
			$p = self::plan_role( $args, $uid );
			if ( is_wp_error( $p ) ) {
				return $p;
			}
			$prev = self::role_label( $p['from'] );
			if ( array( $p['to'] ) === $p['from'] ) { // a retry / second tap
				return array( 'status' => 'done', 'user_id' => $p['id'], 'role' => $p['to'], 'previous_role' => $prev, 'unchanged' => true );
			}
			$ok = self::seam( 'set_role', array( $p['id'], $p['to'] ), static function ( $id, $role ) {
				$u = class_exists( 'WP_User' ) ? new WP_User( (int) $id ) : null;
				if ( ! $u || ! $u->exists() ) {
					return false;
				}
				$u->set_role( $role );
				return true;
			} );
			if ( true !== $ok ) {
				return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::INTERNAL_ERROR, 'Không đổi được vai trò tài khoản.', 500 );
			}
			$entry                 = BizCity_SelfWP_Action_MCP_Service::change_entry( 'wp.user.set_role', 'R3', $uid, $ctx, $p['id'], $prev, $p['to'], array( 'Vai trò tài khoản #' . $p['id'] . ': ' . $prev . ' → ' . $p['to'] ), 'user' );
			BizCity_SelfWP_Action_MCP_Service::audit_change( $entry );
			return array( 'status' => 'done', 'user_id' => $p['id'], 'role' => $p['to'], 'previous_role' => $prev, 'unchanged' => false );
		} );
	}

	/* ───────────────────────────── helpers ──────────────────── */

	/** @return string|WP_Error */
	private static function checked_role( $raw ) {
		$role = is_string( $raw ) ? strtolower( trim( $raw ) ) : '';
		if ( ! in_array( $role, self::ALLOWED_ROLES, true ) ) {
			return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::QUERY_INVALID, 'Vai trò không được phép: chỉ ' . implode(', ', self::ALLOWED_ROLES) . ' (không bao giờ administrator hay shop_manager).', 422, array( 'help_code' => 'selfwp_role_not_allowed' ) );
		}
		if ( ! self::seam( 'role_exists', array( $role ), static function ( $r ) {
			return function_exists( 'get_role' ) && null !== get_role( $r );
		} ) ) {
			return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::QUERY_INVALID, "Website này chưa có vai trò «{$role}» (ví dụ customer chỉ có khi cài WooCommerce).", 422, array( 'help_code' => 'selfwp_role_missing' ) );
		}
		return $role;
	}

	/** one line of plain text: no tags, no control characters, ≤ 60 chars */
	private static function clean_name( string $s ): string {
		$s = strip_tags( $s );
		$s = (string) preg_replace( '/[\x00-\x1F\x7F<>]+/u', ' ', $s );
		$s = trim( (string) preg_replace( '/\s+/u', ' ', $s ) );
		return function_exists( 'mb_substr' ) ? mb_substr( $s, 0, 60, 'UTF-8' ) : substr( $s, 0, 60 );
	}

	/** the password is made HERE, inside WordPress, and handed to nobody: the person sets their own through WordPress's mail */
	public static function default_insert_user( $args ) {
		if ( ! function_exists( 'wp_insert_user' ) ) {
			return new WP_Error( 'wp_missing', 'WordPress chưa sẵn sàng.' );
		}
		$id = wp_insert_user( $args + array( 'user_pass' => wp_generate_password( 24, true, true ) ) );
		if ( ! is_wp_error( $id ) && function_exists( 'is_multisite' ) && is_multisite() && function_exists( 'is_user_member_of_blog' ) && ! is_user_member_of_blog( (int) $id ) ) {
			add_user_to_blog( get_current_blog_id(), (int) $id, (string) $args['role'] );
		}
		return $id;
	}

	/** @return array|null */
	public static function default_get_user( $id ) {
		$u = function_exists( 'get_userdata' ) ? get_userdata( (int) $id ) : false;
		if ( ! $u ) {
			return null;
		}
		return array(
			'id'        => (int) $u->ID,
			'email'     => (string) $u->user_email,
			'name'      => (string) $u->display_name,
			'roles'     => array_values( (array) $u->roles ),
			'is_admin'  => ( function_exists( 'is_super_admin' ) && is_super_admin( (int) $id ) ) || user_can( $u, 'manage_options' ),
			'is_member' => ! function_exists( 'is_user_member_of_blog' ) || is_user_member_of_blog( (int) $id ),
		);
	}

	/** @param mixed ...$args */
	private static function can( string $cap, ...$args ): bool {
		if ( isset( self::$seams['user_can'] ) ) {
			return (bool) call_user_func( self::$seams['user_can'], $cap, ...$args );
		}
		return function_exists( 'current_user_can' ) && current_user_can( $cap, ...$args );
	}

	private static function seam( string $name, array $args, $default ) {
		$fn = self::$seams[ $name ] ?? $default;
		return call_user_func_array( $fn, $args );
	}

	private static function denied( string $cap, string $what ) {
		return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::PERMISSION_DENIED, "Tài khoản của bạn chưa có quyền {$what} (cần quyền WordPress \"{$cap}\").", 403, array( 'hint' => 'Chỉ quản trị viên tạo / đổi vai trò tài khoản được.', 'required_cap' => $cap ) );
	}
}

BizCity_SelfWP_User_Service::init();
