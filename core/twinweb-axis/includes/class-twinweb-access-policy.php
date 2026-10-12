<?php
/**
 * TwinWeb — Twin GPT access policy (always loaded)
 *
 * [2026-10-11 10:58 AM Johnny Chu - Chu Hoàng Anh] CORE-REDUCTION WP-20 Z-6 P0-2/P2 — moved verbatim out of
 * BizCity_TwinWeb_REST (708 KB) so the /gpt/ access answer never depends on whether the REST slice
 * was loaded on this request (W20-Z6 loader-map P0 row 2). Same option, same filters, same rules;
 * every method keeps its old name, now static. BizCity_TwinWeb_REST keeps thin wrappers.
 *
 * Option: bizcity_twinweb_access_policy_<blog_id>. Filters: bizcity_twinweb_default_access_policy,
 * bizcity_twinweb_guest_quota, bizcity_twinweb_access_bypass, bizcity_twinweb_user_tier.
 *
 * PHP 7.4 compatible.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Modules\TwinWeb
 */
defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_TwinWeb_Access_Policy', false ) ) { return; }

final class BizCity_TwinWeb_Access_Policy {

	/**
	 * Baseline access policy for Wave 2.
	 *
	 * @return array
	 */
	public static function default_access_policy() {
		// [2026-07-15 Johnny Chu] PHASE-TWIN-GPT-CP W2 — seed plan_matrix from Core Membership plan registry.
		$plan_matrix = array(
			'free' => array( 'daily_quota' => 30,  'max_output_tokens' => 1200 ),
			'plus' => array( 'daily_quota' => 100, 'max_output_tokens' => 2400 ),
			'pro'  => array( 'daily_quota' => -1,  'max_output_tokens' => 4096 ),
		);
		// [2026-10-09 21:10 Johnny Chu - Chu Hoàng Anh] PHASE-0.96 D96-23 — membership retired: plan_matrix is the static seed above (no plan registry to merge).

		$policy = array(
			'guest'  => array(
				'enabled'                  => true,
				'daily_quota'              => (int) apply_filters( 'bizcity_twinweb_guest_quota', 10 ),
				'require_login_after_quota' => true,
			),
			'member' => array(
				'minimum_role'  => 'subscriber',
				'minimum_tier'  => 'free',
				'allowed_roles' => array( 'subscriber', 'contributor', 'author', 'editor', 'administrator' ),
				'default_plan'  => 'free',
			),
			'users'  => array(
				'allow_user_ids'     => array(),
				'deny_user_ids'      => array(),
				'suspended_user_ids' => array(),
			),
			'plan_matrix' => $plan_matrix,
			'updated_at' => '',
			'updated_by' => 0,
		);

		return (array) apply_filters( 'bizcity_twinweb_default_access_policy', $policy );
	}

	/**
	 * Return normalized access policy for the current blog.
	 *
	 * @param int $blog_id Blog ID.
	 * @return array
	 */
	public static function get_access_policy( $blog_id ) {
		$raw = get_option( 'bizcity_twinweb_access_policy_' . (int) $blog_id, array() );
		if ( ! is_array( $raw ) || empty( $raw ) ) {
			return self::default_access_policy();
		}
		return self::normalize_access_policy( $raw );
	}

	/**
	 * Normalize admin-provided access policy payload.
	 *
	 * @param array $raw Raw payload.
	 * @return array
	 */
	public static function normalize_access_policy( $raw ) {
		$defaults = self::default_access_policy();
		$policy   = $defaults;

		$guest = isset( $raw['guest'] ) && is_array( $raw['guest'] ) ? $raw['guest'] : array();
		$policy['guest']['enabled'] = ! empty( $guest['enabled'] );
		$policy['guest']['daily_quota'] = max( 0, (int) ( $guest['daily_quota'] ?? $defaults['guest']['daily_quota'] ) );
		$policy['guest']['require_login_after_quota'] = ! empty( $guest['require_login_after_quota'] );

		$member        = isset( $raw['member'] ) && is_array( $raw['member'] ) ? $raw['member'] : array();
		$allowed_roles = array( 'subscriber', 'contributor', 'author', 'editor', 'administrator' );
		$min_role      = sanitize_key( (string) ( $member['minimum_role'] ?? $defaults['member']['minimum_role'] ) );
		$min_tier      = sanitize_key( (string) ( $member['minimum_tier'] ?? $defaults['member']['minimum_tier'] ) );
		$default_plan  = sanitize_key( (string) ( $member['default_plan'] ?? $defaults['member']['default_plan'] ) );

		$member_roles  = isset( $member['allowed_roles'] ) ? (array) $member['allowed_roles'] : $defaults['member']['allowed_roles'];
		$member_roles  = array_values( array_unique( array_filter( array_map( 'sanitize_key', $member_roles ), function ( $r ) use ( $allowed_roles ) {
			return in_array( $r, $allowed_roles, true );
		} ) ) );

		if ( ! in_array( $min_role, $allowed_roles, true ) ) {
			$min_role = $defaults['member']['minimum_role'];
		}
		if ( ! in_array( $min_tier, array( 'free', 'plus', 'pro' ), true ) ) {
			$min_tier = $defaults['member']['minimum_tier'];
		}
		if ( ! in_array( $default_plan, array( 'free', 'plus', 'pro' ), true ) ) {
			$default_plan = $defaults['member']['default_plan'];
		}
		if ( empty( $member_roles ) ) {
			$member_roles = $defaults['member']['allowed_roles'];
		}

		$policy['member']['minimum_role']  = $min_role;
		$policy['member']['minimum_tier']  = $min_tier;
		$policy['member']['allowed_roles'] = $member_roles;
		$policy['member']['default_plan']  = $default_plan;

		$users = isset( $raw['users'] ) && is_array( $raw['users'] ) ? $raw['users'] : array();
		$policy['users']['allow_user_ids']     = self::normalize_user_id_list( $users['allow_user_ids'] ?? array() );
		$policy['users']['deny_user_ids']      = self::normalize_user_id_list( $users['deny_user_ids'] ?? array() );
		$policy['users']['suspended_user_ids'] = self::normalize_user_id_list( $users['suspended_user_ids'] ?? array() );

		// [2026-07-15 Johnny Chu] PHASE-TWIN-GPT-CP W2 — normalize plan_matrix fields from admin payload.
		$matrix_raw = isset( $raw['plan_matrix'] ) && is_array( $raw['plan_matrix'] ) ? $raw['plan_matrix'] : array();
		$matrix     = array();
		foreach ( $defaults['plan_matrix'] as $slug => $row ) {
			$src = isset( $matrix_raw[ $slug ] ) && is_array( $matrix_raw[ $slug ] ) ? $matrix_raw[ $slug ] : array();
			$matrix[ $slug ] = array(
				'daily_quota'       => isset( $src['daily_quota'] ) ? (int) $src['daily_quota'] : (int) $row['daily_quota'],
				'max_output_tokens' => isset( $src['max_output_tokens'] ) ? max( 0, (int) $src['max_output_tokens'] ) : (int) $row['max_output_tokens'],
			);
		}
		$policy['plan_matrix'] = $matrix;

		$policy['updated_at'] = isset( $raw['updated_at'] ) ? sanitize_text_field( (string) $raw['updated_at'] ) : '';
		$policy['updated_by'] = isset( $raw['updated_by'] ) ? (int) $raw['updated_by'] : 0;

		return $policy;
	}

	/**
	 * Normalize integer user-id list from array or CSV string.
	 *
	 * @param mixed $value User-id list value.
	 * @return array
	 */
	public static function normalize_user_id_list( $value ) {
		$items = array();
		if ( is_string( $value ) ) {
			$items = preg_split( '/[\s,]+/', $value );
		} elseif ( is_array( $value ) ) {
			$items = $value;
		}

		$ids = array();
		foreach ( $items as $item ) {
			$id = (int) $item;
			if ( $id > 0 ) {
				$ids[] = $id;
			}
		}

		$ids = array_values( array_unique( $ids ) );
		sort( $ids );
		return $ids;
	}

	// [2026-10-09 21:10 Johnny Chu - Chu Hoàng Anh] PHASE-0.96 D96-23 — membership retired: get_membership_plan_catalog() removed (both callers now use the static/empty value).

	/**
	 * Evaluate access matrix for the current identity.
	 * Precedence: deny > suspended > explicit allow > tier/role > guest toggle.
	 *
	 * @param array  $identity Identity tuple from BizCity_TwinWeb_Identity::current().
	 * @param string $tier     Current user tier.
	 * @return array
	 */
	public static function resolve_access_for_identity( $identity, $tier ) {
		$blog_id = (int) get_current_blog_id();
		$policy  = self::get_access_policy( $blog_id );
		$is_guest = ! empty( $identity['is_guest'] );
		$user_id  = isset( $identity['user_id'] ) ? (int) $identity['user_id'] : 0;

		$resp = array(
			'allowed'     => true,
			'is_guest'    => $is_guest,
			'tier'        => (string) $tier,
			'reason_code' => '',
			'message'     => '',
			'hint'        => '',
			'help_code'   => '',
			'policy'      => $policy,
		);

		// [2026-09-30 Claude Opus 5.5] PHASE-0.84 W-13 — evaluate the identity's own user, so the same
		// rules answer for another user (Twin Shell module access); identical for the current identity.
		if ( ! $is_guest && $user_id > 0 && user_can( $user_id, 'manage_options' ) ) {
			return $resp;
		}

		// [2026-09-20 Johnny Chu] HOTFIX — another module that embeds this SPA (e.g.
		// CRM Inbox's "AI Chat" panel) may need to vouch for a user whose access
		// doesn't hinge on a specific WordPress role string — e.g. a CRM team member
		// could be a WooCommerce `customer`, `bizcity_crm_staff`, or something else
		// entirely; what actually grants them the embedded chat is a capability, not
		// a role. Runs before the role allow-list below so a vouched-for member is
		// never blocked by a list that was never meant to model their access.
		if ( ! $is_guest && $user_id > 0 && apply_filters( 'bizcity_twinweb_access_bypass', false, $user_id, $identity ) ) {
			return $resp;
		}

		if ( $is_guest ) {
			if ( empty( $policy['guest']['enabled'] ) ) {
				$resp['allowed']     = false;
				$resp['reason_code'] = 'permission_denied';
				$resp['message']     = 'Twin GPT hiện chưa mở cho khách vãng lai.';
				$resp['hint']        = 'Đăng nhập để tiếp tục hoặc liên hệ quản trị viên bật Guest access.';
				$resp['help_code']   = 'permission_denied';
			}
			return $resp;
		}

		$allow_ids     = (array) ( $policy['users']['allow_user_ids'] ?? array() );
		$deny_ids      = (array) ( $policy['users']['deny_user_ids'] ?? array() );
		$suspended_ids = (array) ( $policy['users']['suspended_user_ids'] ?? array() );

		if ( in_array( $user_id, $deny_ids, true ) ) {
			$resp['allowed']     = false;
			$resp['reason_code'] = 'permission_denied';
			$resp['message']     = 'Tài khoản của bạn không được phép dùng Twin GPT.';
			$resp['hint']        = 'Liên hệ quản trị viên để mở quyền truy cập.';
			$resp['help_code']   = 'permission_denied';
			return $resp;
		}

		if ( in_array( $user_id, $suspended_ids, true ) ) {
			$resp['allowed']     = false;
			$resp['reason_code'] = 'permission_denied';
			$resp['message']     = 'Tài khoản của bạn đang tạm ngưng truy cập Twin GPT.';
			$resp['hint']        = 'Liên hệ quản trị viên để được mở lại quyền.';
			$resp['help_code']   = 'permission_denied';
			return $resp;
		}

		if ( in_array( $user_id, $allow_ids, true ) ) {
			return $resp;
		}

		$tier_rank     = array( 'free' => 0, 'plus' => 1, 'pro' => 2 );
		$min_tier      = (string) ( $policy['member']['minimum_tier'] ?? 'free' );
		$current_rank  = isset( $tier_rank[ $tier ] ) ? $tier_rank[ $tier ] : 0;
		$required_rank = isset( $tier_rank[ $min_tier ] ) ? $tier_rank[ $min_tier ] : 0;
		if ( $current_rank < $required_rank ) {
			$resp['allowed']     = false;
			$resp['reason_code'] = 'permission_denied';
			$resp['message']     = 'Gói hiện tại chưa đủ quyền truy cập Twin GPT.';
			$resp['hint']        = 'Nâng cấp gói hoặc liên hệ quản trị viên để được cấp quyền.';
			$resp['help_code']   = 'permission_denied';
			return $resp;
		}

		$user_obj      = get_userdata( $user_id );
		$user_roles    = $user_obj ? array_map( 'sanitize_key', (array) $user_obj->roles ) : array();
		$allowed_roles = (array) ( $policy['member']['allowed_roles'] ?? array() );
		$matched_roles = array_intersect( $user_roles, $allowed_roles );
		if ( empty( $matched_roles ) ) {
			$resp['allowed']     = false;
			$resp['reason_code'] = 'permission_denied';
			$resp['message']     = 'Vai trò WordPress hiện tại chưa được bật cho Twin GPT.';
			$resp['hint']        = 'Yêu cầu quản trị viên thêm vai trò của bạn vào danh sách cho phép.';
			$resp['help_code']   = 'permission_denied';
			return $resp;
		}

		$role_rank = array(
			'subscriber'   => 0,
			'contributor'  => 1,
			'author'       => 2,
			'editor'       => 3,
			'administrator'=> 4,
		);
		$min_role = sanitize_key( (string) ( $policy['member']['minimum_role'] ?? 'subscriber' ) );
		$min_rank = isset( $role_rank[ $min_role ] ) ? $role_rank[ $min_role ] : 0;
		$best_rank = -1;
		// [2026-09-20 Johnny Chu] HOTFIX — a role outside this core WP ladder (e.g. a
		// plugin-defined role like `bizcity_crm_staff`) already had to be explicitly
		// present in $allowed_roles to reach this point; that admin approval stands
		// on its own and isn't additionally subject to the core-role minimum-rank
		// floor below, which has no rank to compare it against.
		$has_unranked_allowed_role = false;
		foreach ( $matched_roles as $role ) {
			if ( isset( $role_rank[ $role ] ) ) {
				$best_rank = max( $best_rank, $role_rank[ $role ] );
			} else {
				$has_unranked_allowed_role = true;
			}
		}
		if ( ! $has_unranked_allowed_role && $best_rank < $min_rank ) {
			$resp['allowed']     = false;
			$resp['reason_code'] = 'permission_denied';
			$resp['message']     = 'Bạn chưa đạt vai trò tối thiểu để dùng Twin GPT.';
			$resp['hint']        = 'Liên hệ quản trị viên để nâng quyền hoặc thêm override người dùng.';
			$resp['help_code']   = 'permission_denied';
			return $resp;
		}

		return $resp;
	}

	/* ── Twin Shell module access (module-access@1.0.0, delegated owner of `gpt`) ── */

	/**
	 * Whether a signed-in user may use Twin GPT under this blog's access policy.
	 *
	 * [2026-09-30 Claude Opus 5.5] PHASE-0.84 W-13 — the Twin Shell resolver asks here instead of
	 * keeping its own copy of the rule (R-SETTINGS-4L-6).
	 *
	 * @param int $user_id WordPress user id on the current blog.
	 * @return bool
	 */
	public static function module_access_allows_user( $user_id ) {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 ) {
			return false;
		}
		$tier     = (string) apply_filters( 'bizcity_twinweb_user_tier', 'free', $user_id );
		$identity = array( 'is_guest' => false, 'user_id' => $user_id );
		$eval     = self::resolve_access_for_identity( $identity, $tier );
		return ! empty( $eval['allowed'] );
	}

	/**
	 * Role cells of the Twin GPT row: which roles this policy lets in (core WordPress roles only —
	 * the policy cannot name other roles; they reach Twin GPT through `bizcity_twinweb_access_bypass`).
	 *
	 * @return array{editable_roles:string[],allowed_roles:string[],minimum_role:string}
	 */
	public static function module_access_role_state() {
		$policy = self::get_access_policy( (int) get_current_blog_id() );
		$min    = (string) ( $policy['member']['minimum_role'] ?? 'subscriber' );
		$ladder = array( 'subscriber', 'contributor', 'author', 'editor', 'administrator' );
		$floor  = array_search( $min, $ladder, true );
		$floor  = false === $floor ? 0 : (int) $floor;
		$on     = array();
		foreach ( (array) ( $policy['member']['allowed_roles'] ?? array() ) as $role ) {
			$rank = array_search( $role, $ladder, true );
			if ( false !== $rank && $rank >= $floor ) {
				$on[] = (string) $role;
			}
		}
		return array(
			'editable_roles' => $ladder,
			'allowed_roles'  => $on,
			'minimum_role'   => $min,
		);
	}

	/**
	 * Turn one core role on/off for Twin GPT. Turning a role on below the current minimum role
	 * lowers the minimum to that role, so the switch means what it shows.
	 *
	 * @param string $role    Core WordPress role.
	 * @param bool   $allowed New state.
	 * @return true|WP_Error
	 */
	public static function module_access_set_role( $role, $allowed ) {
		$ladder = array( 'subscriber', 'contributor', 'author', 'editor', 'administrator' );
		$role   = sanitize_key( (string) $role );
		if ( ! in_array( $role, $ladder, true ) || 'administrator' === $role ) {
			return new WP_Error( 'module_access_write_failed', 'Vai trò này không đổi được ở Twin GPT.', array( 'status' => 409, 'reason' => 'not_grantable' ) );
		}
		$blog_id = (int) get_current_blog_id();
		$policy  = self::get_access_policy( $blog_id );
		$roles   = (array) ( $policy['member']['allowed_roles'] ?? array() );
		if ( $allowed ) {
			$roles[] = $role;
			$min     = array_search( (string) ( $policy['member']['minimum_role'] ?? 'subscriber' ), $ladder, true );
			if ( false === $min || array_search( $role, $ladder, true ) < $min ) {
				$policy['member']['minimum_role'] = $role;
			}
		} else {
			$roles = array_diff( $roles, array( $role ) );
			if ( empty( $roles ) ) {
				$roles = array( 'administrator' );
			}
		}
		$policy['member']['allowed_roles'] = array_values( array_unique( $roles ) );
		self::module_access_save_policy( $blog_id, $policy );
		return true;
	}

	/**
	 * Per-user override for Twin GPT: 'inherit' | 'allow' | 'deny' (policy allow/deny lists).
	 *
	 * @param int    $user_id User id on the current blog.
	 * @param string $state   New override state.
	 * @return true|WP_Error
	 */
	public static function module_access_set_user( $user_id, $state ) {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 || ! in_array( $state, array( 'inherit', 'allow', 'deny' ), true ) ) {
			return new WP_Error( 'module_access_write_failed', 'Yêu cầu không hợp lệ.', array( 'status' => 400 ) );
		}
		$blog_id = (int) get_current_blog_id();
		$policy  = self::get_access_policy( $blog_id );
		$allow   = array_diff( (array) ( $policy['users']['allow_user_ids'] ?? array() ), array( $user_id ) );
		$deny    = array_diff( (array) ( $policy['users']['deny_user_ids'] ?? array() ), array( $user_id ) );
		if ( 'allow' === $state ) {
			$allow[] = $user_id;
		} elseif ( 'deny' === $state ) {
			$deny[] = $user_id;
		}
		$policy['users']['allow_user_ids'] = array_values( $allow );
		$policy['users']['deny_user_ids']  = array_values( $deny );
		self::module_access_save_policy( $blog_id, $policy );
		return true;
	}

	/**
	 * Current per-user override for Twin GPT.
	 *
	 * @param int $user_id User id.
	 * @return string 'inherit' | 'allow' | 'deny'
	 */
	public static function module_access_user_state( $user_id ) {
		$policy = self::get_access_policy( (int) get_current_blog_id() );
		if ( in_array( (int) $user_id, (array) ( $policy['users']['deny_user_ids'] ?? array() ), true ) ) {
			return 'deny';
		}
		if ( in_array( (int) $user_id, (array) ( $policy['users']['allow_user_ids'] ?? array() ), true ) ) {
			return 'allow';
		}
		return 'inherit';
	}

	public static function module_access_save_policy( $blog_id, $policy ) {
		$policy               = self::normalize_access_policy( $policy );
		$policy['updated_at'] = current_time( 'mysql', true );
		$policy['updated_by'] = (int) get_current_user_id();
		update_option( 'bizcity_twinweb_access_policy_' . (int) $blog_id, $policy, false );
		self::bump_control_plane_version( $blog_id );
		self::flush_effective_config_cache( $blog_id );
	}

	/**
	 * Bump control-plane policy version used by effective-config cache key.
	 *
	 * @param int $blog_id Blog ID.
	 * @return void
	 */
	public static function bump_control_plane_version( $blog_id ) {
		update_option( 'bizcity_twinweb_cp_ver_' . (int) $blog_id, (string) time(), false );
	}

	/**
	 * Flush effective-config cache group safely.
	 *
	 * @param int $blog_id Blog ID.
	 * @return void
	 */
	public static function flush_effective_config_cache( $blog_id ) {
		do_action( 'bizcity_twinweb_flush_effective_config', (int) $blog_id );
		if ( function_exists( 'wp_cache_flush_group' ) ) {
			wp_cache_flush_group( 'bizcity_twinweb' );
		}
	}
}
