<?php
/**
 * BizCity_MCP_Tool_Policy — per-blog admin allowlist for MCP tools.
 *
 * This is a SECOND, independent gate layered on top of the existing
 * wp-config.php wave rollback constants (BIZCITY_MCP_*_TOOLS_ENABLED):
 *
 *   wp-config constant  → "is this wave even loaded on this deploy?"
 *                         (devops/rollback decision, PHASE-0.54-MCP §11)
 *   BizCity_MCP_Tool_Policy → "which of the loaded tools may THIS site's
 *                         admin actually expose to MCP clients?"
 *                         (site admin decision, Channel Gateway → MCP Access
 *                         → "Cấu hình tools" checkbox UI)
 *
 * A tool is effectively callable only when BOTH gates allow it. Policy is
 * stored per-blog in wp_options (single JSON map keyed by tool name) —
 * no new DB table, no R-DCL schema entry needed.
 *
 * Fail-closed default: any tool not explicitly present in the stored map
 * (including tools added by a later plugin update, before the admin has
 * ever saved the settings screen) falls back to `default_enabled_for()`,
 * which only allow-lists `brain.*` reads plus the simple content authoring
 * actions (create/update draft, list/get posts, list templates). Every
 * other domain (page.*, business.*, report.*, commerce.*, document.*) is
 * OFF until an admin explicitly checks the box.
 *
 * [2026-10-01 Claude Opus 5.5] PHASE-0.88 R-MCP-OAUTH-ID §0b.5 — the same store also holds the per-role groups
 * (`knowledge` / `action`, option bizcity_mcp_role_groups, absent = auto defaults). A tool is callable only when the
 * caller's WordPress role(s) allow the tool's group, on every MCP path (external clients AND the delegated cell).
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\MCP
 * @since      2026-07-30 (PHASE-0.54-MCP Wave Q)
 */

defined( 'ABSPATH' ) || exit;

// [2026-07-30 Johnny Chu] PHASE-0.54-MCP Wave Q — new file, admin tool allowlist on top of wave rollback flags.
final class BizCity_MCP_Tool_Policy {

	const OPTION = 'bizcity_mcp_tool_policy';

	/**
	 * Tool name prefixes that are ON by default (read-only Brain tools).
	 * @var string[]
	 */
	const DEFAULT_ENABLED_PREFIXES = array( 'brain.' );

	/**
	 * Individual tool names that are ON by default even though their
	 * domain prefix (`content.`) is otherwise opt-in. These are exactly
	 * the "đăng bài viết, quản lý bài viết đơn giản" actions requested by
	 * the site admin default policy.
	 * @var string[]
	 */
	const DEFAULT_ENABLED_TOOLS = array(
		'content.list_posts',
		'content.get_post',
		'content.get_templates',
		'content.create_draft',
		'content.update_draft',
	);

	/**
	 * Diagnostics probe sentinel — bypasses the admin policy gate so
	 * dispatch-reachability tests exercise the real handler regardless of
	 * the site's current checkbox state. Scope checks still apply the
	 * normal way; this only bypasses the *policy* layer, matching the
	 * existing `scopes => ['*']` bypass already used by core.mcp.gateway.
	 */
	const DIAGNOSTICS_CLIENT_ID = '__diagnostics__';

	/**
	 * @return bool True when the tool is enabled for MCP clients on this site.
	 */
	public static function is_enabled( $tool_name, array $ctx = array() ) {
		if ( isset( $ctx['client_id'] ) && (string) $ctx['client_id'] === self::DIAGNOSTICS_CLIENT_ID ) {
			// [2026-07-30 Johnny Chu] PHASE-0.54-MCP Wave Q — diagnostics dispatch-proof steps bypass the admin policy gate on purpose.
			return true;
		}
		$name = self::normalize_name( $tool_name );
		$map  = self::get_enabled_map();
		if ( array_key_exists( $name, $map ) ) {
			return (bool) $map[ $name ];
		}
		return self::default_enabled_for( $name );
	}

	/**
	 * @return bool True when a tool is ON by the built-in default rule
	 * (used both as the fallback for unknown tools and to pre-check the
	 * settings UI the first time it renders, before anything is saved).
	 */
	public static function default_enabled_for( $tool_name ) {
		$name = self::normalize_name( $tool_name );
		// [2026-09-16 Johnny Chu - Chu Hoàng Anh] PHASE-0.41D-D4 — the new
		// Context Bank/One Brain tools stay OFF until the parity probe and release
		// canary have passed. Existing brain.* tools retain their historical default.
		if ( in_array( $name, array( 'brain.context.search', 'brain.context.evidence', 'brain.order.summary' ), true ) ) {
			return false;
		}
		foreach ( self::DEFAULT_ENABLED_PREFIXES as $prefix ) {
			if ( strpos( $name, $prefix ) === 0 ) {
				return true;
			}
		}
		return in_array( $name, self::DEFAULT_ENABLED_TOOLS, true );
	}

	/**
	 * @return array<string,bool> Stored policy map (tool_name => enabled). Empty when never saved.
	 */
	public static function get_enabled_map() {
		$stored = get_option( self::OPTION, null );
		if ( ! is_array( $stored ) ) {
			return array();
		}
		$out = array();
		foreach ( $stored as $name => $enabled ) {
			$out[ self::normalize_name( $name ) ] = (bool) $enabled;
		}
		return $out;
	}

	/**
	 * Persist a full policy map derived from the admin's checkbox selection.
	 *
	 * @param string[] $enabled_tool_names   Tool names the admin left checked.
	 * @param string[] $all_known_tool_names Every tool name currently registered (from the tool registry), so unchecked tools are explicitly recorded as false rather than silently falling back to defaults forever.
	 * @return array<string,bool> The saved map.
	 */
	public static function save( array $enabled_tool_names, array $all_known_tool_names ) {
		$enabled = array();
		foreach ( $enabled_tool_names as $name ) {
			$enabled[ self::normalize_name( $name ) ] = true;
		}
		$map = array();
		foreach ( $all_known_tool_names as $name ) {
			$normalized = self::normalize_name( $name );
			if ( $normalized === '' ) {
				continue;
			}
			$map[ $normalized ] = isset( $enabled[ $normalized ] );
		}
		update_option( self::OPTION, $map, false );
		return $map;
	}

	/* ================================================================
	 *  [2026-10-01 Claude Opus 5.5] PHASE-0.88 R-MCP-OAUTH-ID §0b.5 — role groups.
	 *
	 *  Each WordPress role has two switchable MCP groups: `knowledge` (knowledge tools/resources/prompts) and `action`
	 *  (business read + write tools). Same policy store as the tool allowlist (AMA-1: one store, one option, no table):
	 *  option `bizcity_mcp_role_groups` = { role_slug: {knowledge: bool, action: bool} }; a role absent from it = `auto`.
	 *  Auto: subscriber profile = none · editor profile = knowledge only (own notebooks first) · administrator profile = both.
	 *  Custom roles take the profile of their nearest capability (manage_options ⇒ administrator, edit_others_posts ⇒
	 *  editor, else subscriber). A user with several roles gets the union. Applies to EVERY MCP path (OAuth / API key /
	 *  delegated cell); only the diagnostics probe client bypasses it, like the allowlist.
	 * ================================================================ */

	const ROLE_GROUPS_OPTION = 'bizcity_mcp_role_groups';
	const GROUP_KNOWLEDGE    = 'knowledge';
	const GROUP_ACTION       = 'action';
	const GROUPS             = array( 'knowledge', 'action' );

	/** Auto groups per profile. */
	const PROFILE_DEFAULTS = array(
		'administrator' => array( 'knowledge' => true, 'action' => true ),
		'editor'        => array( 'knowledge' => true, 'action' => false ),
		'subscriber'    => array( 'knowledge' => false, 'action' => false ),
	);

	/** Explicit tool → group exceptions to the prefix rule (brain.order.summary is order data, not knowledge). */
	const TOOL_GROUP_MAP = array(
		'brain.order.summary' => 'action',
	);

	/** Tool name prefixes of the knowledge group (plus every tool whose mode is `notebook`). */
	const KNOWLEDGE_PREFIXES = array( 'knowledge.', 'brain.' );

	/** Pack kinds that are knowledge (every other pack kind is action). */
	const KNOWLEDGE_PACK_KINDS = array( 'owner_knowledge', 'notebook_meta' );

	/**
	 * Test seams (optional): roles(user_id): string[] · role_caps(role): array<string,bool> · all_roles(): array<slug,name>
	 * · is_super_admin(user_id): bool.
	 *
	 * @var array<string,callable>
	 */
	public static $readers = array();

	/**
	 * The ONE classification of a tool into a role group.
	 *
	 * @param string      $tool_name
	 * @param string|null $mode `_meta.bizcity.mode` of the tool ('notebook' ⇒ knowledge)
	 * @return string 'knowledge'|'action'
	 */
	public static function tool_group( $tool_name, $mode = null ) {
		$name = self::normalize_name( $tool_name );
		if ( isset( self::TOOL_GROUP_MAP[ $name ] ) ) {
			return self::TOOL_GROUP_MAP[ $name ];
		}
		if ( 'notebook' === (string) $mode ) {
			return self::GROUP_KNOWLEDGE;
		}
		foreach ( self::KNOWLEDGE_PREFIXES as $prefix ) {
			if ( strpos( $name, $prefix ) === 0 ) {
				return self::GROUP_KNOWLEDGE;
			}
		}
		return self::GROUP_ACTION;
	}

	/** Group of a pack kind (bizcity://pack/{kind}). */
	public static function pack_group( $kind ) {
		return in_array( (string) $kind, self::KNOWLEDGE_PACK_KINDS, true ) ? self::GROUP_KNOWLEDGE : self::GROUP_ACTION;
	}

	/**
	 * Profile of a role: the three built-in names map to themselves; any other role takes the profile of its nearest
	 * capability (manage_options ⇒ administrator, edit_others_posts ⇒ editor, else subscriber).
	 */
	public static function role_profile( $role ) {
		$role = sanitize_key( (string) $role );
		if ( isset( self::PROFILE_DEFAULTS[ $role ] ) ) {
			return $role;
		}
		$caps = self::role_caps( $role );
		if ( ! empty( $caps['manage_options'] ) ) {
			return 'administrator';
		}
		if ( ! empty( $caps['edit_others_posts'] ) ) {
			return 'editor';
		}
		return 'subscriber';
	}

	/** @return array{knowledge:bool,action:bool} the auto default of a role. */
	public static function auto_groups_for_role( $role ) {
		return self::PROFILE_DEFAULTS[ self::role_profile( $role ) ];
	}

	/** @return array<string,array{knowledge:bool,action:bool}> stored custom entries (role ⇒ groups). Empty = all auto. */
	public static function custom_role_groups() {
		$stored = get_option( self::ROLE_GROUPS_OPTION, null );
		if ( ! is_array( $stored ) ) {
			return array();
		}
		$out = array();
		foreach ( $stored as $role => $groups ) {
			$role = sanitize_key( (string) $role );
			if ( '' === $role || ! is_array( $groups ) ) {
				continue;
			}
			$out[ $role ] = array(
				'knowledge' => ! empty( $groups['knowledge'] ),
				'action'    => ! empty( $groups['action'] ),
			);
		}
		return $out;
	}

	/** @return array{knowledge:bool,action:bool,source:string} effective groups of one role. */
	public static function groups_for_role( $role ) {
		$role   = sanitize_key( (string) $role );
		$custom = self::custom_role_groups();
		if ( isset( $custom[ $role ] ) ) {
			return $custom[ $role ] + array( 'source' => 'custom' );
		}
		return self::auto_groups_for_role( $role ) + array( 'source' => 'auto' );
	}

	/**
	 * Effective groups of a WordPress user = union over its roles. No role on this blog: a super admin counts as the
	 * administrator role, anyone else gets nothing (fail closed).
	 *
	 * @return array{knowledge:bool,action:bool}
	 */
	public static function groups_for_user( $user_id ) {
		$out   = array( 'knowledge' => false, 'action' => false );
		$roles = self::user_roles( (int) $user_id );
		if ( ! $roles && self::is_super_admin( (int) $user_id ) ) {
			$roles = array( 'administrator' );
		}
		foreach ( $roles as $role ) {
			$g = self::groups_for_role( $role );
			$out['knowledge'] = $out['knowledge'] || ! empty( $g['knowledge'] );
			$out['action']    = $out['action'] || ! empty( $g['action'] );
		}
		return $out;
	}

	/** True when the user holds a role whose PROFILE is administrator (shop-wide knowledge scope; else own notebooks). */
	public static function user_has_admin_profile( $user_id ) {
		$roles = self::user_roles( (int) $user_id );
		if ( ! $roles ) {
			return self::is_super_admin( (int) $user_id );
		}
		foreach ( $roles as $role ) {
			if ( 'administrator' === self::role_profile( $role ) ) {
				return true;
			}
		}
		return false;
	}

	/** Role-group gate for an auth context (diagnostics probe bypasses, like the allowlist). */
	public static function ctx_allows_group( $group, array $ctx ) {
		if ( isset( $ctx['client_id'] ) && (string) $ctx['client_id'] === self::DIAGNOSTICS_CLIENT_ID ) {
			return true;
		}
		// [2026-10-05 09:41 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.91-R-AGENT-PRINCIPALS R-AP-6 — a customer turn has no WordPress role: the Guru of
		// the channel decided its tools (BizCity_MCP_Delegation::tool_allowed); only the knowledge group is ever reachable.
		if ( 'guru_public' === (string) ( $ctx['principal_kind'] ?? '' ) ) {
			return 'knowledge' === (string) $group;
		}
		$groups = self::groups_for_user( (int) ( $ctx['user_id'] ?? 0 ) );
		return ! empty( $groups[ (string) $group ] );
	}

	/** Role-group gate for one tool. */
	public static function ctx_allows_tool( $tool_name, $mode, array $ctx ) {
		// [2026-10-06 12:30 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.91-AX-PERM — a customer turn reaches the two automation tools only when
		// the Guru opened them (allowed_tools); the site then serves audience-guest scenarios only (R-AUTOMATION-PERMISSION).
		if ( 'guru_public' === (string) ( $ctx['principal_kind'] ?? '' ) && class_exists( 'BizCity_MCP_Delegation' )
			&& in_array( (string) $tool_name, BizCity_MCP_Delegation::GUEST_AUTOMATION_TOOLS, true ) ) {
			return in_array( (string) $tool_name, array_map( 'strval', (array) ( $ctx['allowed_tools'] ?? array() ) ), true );
		}
		return self::ctx_allows_group( self::tool_group( $tool_name, $mode ), $ctx );
	}

	/**
	 * Admin matrix: every role of the site × {knowledge, action}, with source and auto default.
	 *
	 * @return array<int,array{role:string,name:string,profile:string,knowledge:bool,action:bool,source:string,auto:array}>
	 */
	public static function role_groups_matrix() {
		$out = array();
		foreach ( self::all_roles() as $slug => $label ) {
			$g     = self::groups_for_role( $slug );
			$out[] = array(
				'role'      => (string) $slug,
				'name'      => (string) $label,
				'profile'   => self::role_profile( $slug ),
				'knowledge' => (bool) $g['knowledge'],
				'action'    => (bool) $g['action'],
				'source'    => (string) $g['source'],
				'auto'      => self::auto_groups_for_role( $slug ),
			);
		}
		return $out;
	}

	/**
	 * Save role groups. `$changes` = { role: {knowledge, action} | 'auto' | null }. Unknown roles are dropped; an entry
	 * equal to the role's auto default or set to 'auto'/null goes back to auto (so a later default change still applies).
	 * Roles not named in `$changes` keep their current entry.
	 *
	 * @return array<string,array{knowledge:bool,action:bool}> stored custom entries after the save
	 */
	public static function save_role_groups( array $changes ) {
		$known  = self::all_roles();
		$custom = self::custom_role_groups();
		foreach ( $changes as $role => $groups ) {
			$role = sanitize_key( (string) $role );
			if ( '' === $role || ! isset( $known[ $role ] ) ) {
				continue;
			}
			if ( ! is_array( $groups ) ) {
				unset( $custom[ $role ] );
				continue;
			}
			$auto  = self::auto_groups_for_role( $role );
			$entry = array(
				'knowledge' => array_key_exists( 'knowledge', $groups ) ? self::to_bool( $groups['knowledge'] ) : $auto['knowledge'],
				'action'    => array_key_exists( 'action', $groups ) ? self::to_bool( $groups['action'] ) : $auto['action'],
			);
			if ( $entry === $auto ) {
				unset( $custom[ $role ] );
			} else {
				$custom[ $role ] = $entry;
			}
		}
		if ( $custom ) {
			update_option( self::ROLE_GROUPS_OPTION, $custom, false );
		} else {
			delete_option( self::ROLE_GROUPS_OPTION );
		}
		return $custom;
	}

	private static function to_bool( $v ) {
		if ( is_string( $v ) ) {
			return in_array( strtolower( $v ), array( '1', 'true', 'yes', 'on' ), true );
		}
		return (bool) $v;
	}

	/** @return string[] role slugs of the user on this blog */
	private static function user_roles( $user_id ) {
		if ( $user_id <= 0 ) {
			return array();
		}
		if ( isset( self::$readers['roles'] ) ) {
			$roles = (array) call_user_func( self::$readers['roles'], $user_id );
		} elseif ( function_exists( 'get_userdata' ) ) {
			$user  = get_userdata( $user_id );
			$roles = $user && isset( $user->roles ) ? (array) $user->roles : array();
		} else {
			$roles = array();
		}
		return array_values( array_unique( array_filter( array_map( 'sanitize_key', array_map( 'strval', $roles ) ) ) ) );
	}

	/** @return array<string,bool> capabilities of a role */
	private static function role_caps( $role ) {
		if ( isset( self::$readers['role_caps'] ) ) {
			return (array) call_user_func( self::$readers['role_caps'], $role );
		}
		if ( function_exists( 'get_role' ) ) {
			$obj = get_role( $role );
			return $obj && isset( $obj->capabilities ) ? (array) $obj->capabilities : array();
		}
		return array();
	}

	/** @return array<string,string> slug ⇒ display name of every role on this blog */
	private static function all_roles() {
		if ( isset( self::$readers['all_roles'] ) ) {
			$roles = (array) call_user_func( self::$readers['all_roles'] );
		} elseif ( function_exists( 'wp_roles' ) ) {
			$roles = (array) wp_roles()->get_names();
		} else {
			$roles = array();
		}
		$out = array();
		foreach ( $roles as $slug => $name ) {
			$slug = sanitize_key( (string) $slug );
			if ( '' !== $slug ) {
				$out[ $slug ] = function_exists( 'translate_user_role' ) ? translate_user_role( (string) $name ) : (string) $name;
			}
		}
		return $out;
	}

	private static function is_super_admin( $user_id ) {
		if ( $user_id <= 0 ) {
			return false;
		}
		if ( isset( self::$readers['is_super_admin'] ) ) {
			return (bool) call_user_func( self::$readers['is_super_admin'], $user_id );
		}
		return function_exists( 'is_multisite' ) && is_multisite() && function_exists( 'is_super_admin' ) && is_super_admin( $user_id );
	}

	/**
	 * Tool names use `[a-z0-9_]+(\.[a-z0-9_]+)+` (e.g. `brain.search`,
	 * `commerce.list_orders`) — sanitize_key() would strip the dot, so a
	 * dedicated normalizer is used instead.
	 */
	private static function normalize_name( $name ) {
		return preg_replace( '/[^a-z0-9_.]/', '', strtolower( (string) $name ) );
	}
}
