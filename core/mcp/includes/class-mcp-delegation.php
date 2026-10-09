<?php
/**
 * BizCity_MCP_Delegation — delegated identity for the zalo-hub cell (R-MCP-OAUTH-ID.6, D-MCP-7, Q88-4, Q88-6).
 *
 * The cell (central brain) calls this site's ONE MCP server on behalf of an owner/staff principal of a zalo-hub number.
 * First version (PHASE-0.88 waves 1–5): the Hub forwards the JSON-RPC body to the site route `zalo-bridge/mcp` with the
 * number's callback Bearer token; the site maps `X-BizCity-Principal` (user_hash) back to a WordPress user through its
 * OWN binding (BizCity_Zalo_Agent_Principals::by_hash) — never trusting a user id from the cell or the Hub.
 *
 * This class only builds the context and gates tools by mode:
 *  - scopes = scopes_of(modes) ∩ supported scopes (mirror of docs/contracts/BIZCITY-MCP-STANDARD-v1.json, see SCOPES_BY_MODE);
 *  - a delegated principal reaches a tool only when the tool's `_meta.bizcity.mode` is one of its modes ('*business' = any
 *    business mode); tools without a mode are never offered to delegated principals; the admin allowlist for external
 *    clients (BizCity_MCP_Tool_Policy) does NOT apply here (Q88-6);
 *  - notebook reads are limited to notebooks the user OWNS (R-TAA-14: notebooks first by user_id); an empty list means
 *    "none", never "all".
 *
 * Evidence: one JSONL line `tool_name=mcp.delegation` per delegated request with a reason bucket; no token, no raw UID,
 * no body — only hash prefixes.
 *
 * // @mcp bizcity-mcp-standard@1 delegation L3-1
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\MCP
 * @since      PHASE-0.88 (2026-10-01)
 */

defined( 'ABSPATH' ) || exit;

// [2026-10-01 Claude Opus 5.5] PHASE-0.88 L3-1 — new file, delegated (cell) identity + mode gate for core/mcp.
final class BizCity_MCP_Delegation {

	const AUTH_METHOD  = 'delegated';
	const CLIENT_ID    = 'zalo-cell';
	const CLIENT_NAME  = 'Zalo Agent';
	const ANY_BUSINESS = '*business';

	/**
	 * Mirror of BIZCITY-MCP-STANDARD-v1.json → modes.<mode>.scopes (bin/validate-mcp-standard.mjs compares them).
	 */
	const SCOPES_BY_MODE = array(
		'notebook'      => array( 'brain.read', 'memory.read', 'memory.write' ), // [2026-10-08 01:37 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.94-MEM-H1 — memory.* (long-term person memory, Q-MEM-1)
		'sales'         => array( 'business.read', 'report.read' ),
		'orders'        => array( 'order.read', 'commerce.read', 'order.write', 'inventory.write' ),
		'customers'     => array( 'crm.read', 'commerce.read', 'crm.write', 'staff.write' ),
		'stock'         => array( 'business.read', 'commerce.read' ),
		// [2026-10-09 11:52 PM Johnny Chu - Chu Hoàng Anh] CORE-REDUCTION WP-20 Q-W20-12 — bizcoach-pro's read-only astro.* / coach.* tools
		// need brain.read; with no scope an astro_self-only principal saw them in tools/list but every call got SCOPE_DENIED.
		// Still mode-gated: astro_self alone does not reach knowledge.* (mode notebook) nor any notebook id.
		'astro_self'    => array( 'brain.read' ),
		// [2026-10-07 10:05 Johnny Chu - Chu Hoàng Anh] TWINBRAIN-SPLIT-CORE-ADDON Q-4 — the cell queues vertical / deep analysis over MCP (analysis.request).
		'deep_analysis' => array( 'analysis.run' ),
		'booking'       => array( 'booking.read', 'booking.write' ),
		'automation'    => array( 'automation.run' ),
		'channel'       => array( 'channel.reply', 'channel.post', 'channel.mail' ), // [2026-10-07 10:20 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.93 gap B — gmail.send
	);

	/** Mirror of BIZCITY-MCP-STANDARD-v1.json → any_business_mode_scopes. */
	const ANY_BUSINESS_MODE_SCOPES = array( 'staff.notify' );

	/** Mirror of BIZCITY-MCP-STANDARD-v1.json → business_modes. */
	const BUSINESS_MODES = array( 'sales', 'orders', 'customers', 'stock', 'booking', 'automation' );

	/**
	 * Scopes introduced by PHASE-0.88 (write tools of CL-B and the CRM/booking reads). They are made known to the OAuth /
	 * admin key scope lists so external clients CAN be granted them; nothing grants them by default.
	 */
	const STANDARD_SCOPES = array( 'order.read', 'commerce.read', 'crm.read', 'crm.write', 'order.write', 'inventory.write', 'staff.write', 'staff.notify', 'booking.read', 'booking.write', 'automation.run', 'channel.reply', 'channel.post', 'channel.mail', 'analysis.run', 'memory.read', 'memory.write' ); // [2026-10-08 01:37 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.94-MEM-H1

	/** Reasons of R-MCP-OAUTH-ID.6 §7 (first version). */
	const REASONS = array( 'delegation_ok', 'delegation_principal_unbound', 'delegation_no_scope', 'delegation_bad_token' );

	/**
	 * Test seams: notebooks(user_id): list<{id, owner_id}> · supported(): string[]|null (null = no intersection) ·
	 * log(entry): void.
	 *
	 * @var array<string,callable>
	 */
	public static $readers = array();

	/**
	 * scopes_of(modes): union of every known mode's scopes (+ staff.notify when any business mode is present).
	 *
	 * @param string[] $modes
	 * @return string[]
	 */
	public static function scopes_for( array $modes ) {
		$out      = array();
		$business = false;
		foreach ( $modes as $mode ) {
			$mode = (string) $mode;
			if ( isset( self::SCOPES_BY_MODE[ $mode ] ) ) {
				$out = array_merge( $out, self::SCOPES_BY_MODE[ $mode ] );
			}
			if ( in_array( $mode, self::BUSINESS_MODES, true ) ) {
				$business = true;
			}
		}
		if ( $business ) {
			$out = array_merge( $out, self::ANY_BUSINESS_MODE_SCOPES );
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * effective_scopes = scopes_of(modes) ∩ supported scopes (R-MCP-OAUTH-ID.6 §3). Supported = what this deploy's MCP
	 * waves expose (OAuth list) plus the PHASE-0.88 standard scopes.
	 *
	 * @param string[] $modes
	 * @return string[]
	 */
	public static function effective_scopes( array $modes ) {
		$want      = self::scopes_for( $modes );
		$supported = self::supported_scopes();
		return null === $supported ? $want : array_values( array_intersect( $want, $supported ) );
	}

	/** @return string[]|null */
	private static function supported_scopes() {
		if ( isset( self::$readers['supported'] ) ) {
			$s = call_user_func( self::$readers['supported'] );
			return null === $s ? null : array_values( array_map( 'strval', (array) $s ) );
		}
		if ( class_exists( 'BizCity_MCP_OAuth' ) && method_exists( 'BizCity_MCP_OAuth', 'supported_scope_list' ) ) {
			return BizCity_MCP_OAuth::supported_scope_list();
		}
		return null;
	}

	/**
	 * Auth context for one delegated request. `$principal` comes from BizCity_Zalo_Agent_Principals::by_hash() (active
	 * owner or staff of THIS number) — never from the request.
	 *
	 * @param array  $principal {role, user_id, user_hash, modes[]}
	 * @param string $account_id zalo-hub number (bridge id)
	 * @param string $turn_id    sanitized X-BizCity-Turn-Id
	 * @return array
	 */
	public static function context( array $principal, $account_id, $turn_id ) {
		$user_id = (int) ( $principal['user_id'] ?? 0 );
		$modes   = array_values( array_unique( array_filter( array_map( 'strval', (array) ( $principal['modes'] ?? array() ) ) ) ) );
		return array(
			'auth_method'          => self::AUTH_METHOD,
			'user_id'              => $user_id,
			'client_id'            => self::CLIENT_ID,
			'client_name'          => self::CLIENT_NAME,
			'key_id'               => 0,
			'scopes'               => self::effective_scopes( $modes ),
			'modes'                => $modes,
			'role'                 => (string) ( $principal['role'] ?? '' ),
			'user_hash'            => strtolower( (string) ( $principal['user_hash'] ?? '' ) ),
			'account_id'           => (string) $account_id,
			'turn_id'              => (string) $turn_id,
			'allowed_notebook_ids' => in_array( 'notebook', $modes, true ) ? self::owned_notebook_ids( $user_id ) : array(),
		);
	}

	/** PHASE-0.91 R-AGENT-PRINCIPALS R-AP-6 — principal kind of a CUSTOMER turn (the Guru of the channel, no WP user). */
	const GURU_PUBLIC = 'guru_public';

	/**
	 * [2026-10-05 11:40 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.91-AX-PERM — the two automation tools a Guru may open to customers (khách vãng lai). The site still
	 * answers only audience-guest scenarios to a customer (automation.run_scenario role check); a Guru without them in
	 * customer_tools gives customers no automation at all.
	 */
	const GUEST_AUTOMATION_TOOLS = array( 'automation.list_scenarios', 'automation.run_scenario' );

	/**
	 * [2026-10-05 09:40 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.91-R-AP-6 (Q91-24, doc 92 G-B1) — context of a customer turn: read-only knowledge
	 * over the notebooks the Guru answering THIS channel shares, limited to the tools that Guru allows (`customer_tools`).
	 * The caller resolves the Guru from ITS OWN channel binding (never from the request) and passes that Guru's profile
	 * scope. No WordPress user (user_id 0); the client id is per Guru so retrieval snapshots are never shared between
	 * Gurus of the same site.
	 *
	 * @param array $scope BizCity_Guru_Context_Resolver::profile($cid)['scope'] (notebook_ids, customer_tools)
	 */
	public static function context_guru_public( int $character_id, string $account_id, string $turn_id, array $scope, string $user_hash = '' ): array {
		$tools = array_values( array_map( 'strval', (array) ( $scope['customer_tools'] ?? array() ) ) );
		$ids   = $tools ? array_values( array_filter( array_map( 'intval', (array) ( $scope['notebook_ids'] ?? array() ) ) ) ) : array();
		// [2026-10-05 11:40 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.91-AX-PERM — automation for guests: mode + scope only when the Guru opened the tools, and the sender's
		// hash (value from the cell's own binding) so the result goes back to THAT person and the per-person rate holds.
		$auto  = (bool) array_intersect( $tools, self::GUEST_AUTOMATION_TOOLS );
		// [2026-10-09 03:27 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.95-S95-F13/F14 — commerce.search_products reads public catalog data: it brings
		// only `commerce.read` (never the whole `stock` mode: no business.read for a customer) and no notebook mode by itself.
		$shop  = in_array( self::GUEST_COMMERCE_TOOL, $tools, true );
		$modes = array_merge( $tools && array_diff( $tools, self::GUEST_AUTOMATION_TOOLS, array( self::GUEST_COMMERCE_TOOL ) ) ? array( 'notebook' ) : array(), $auto ? array( 'automation' ) : array() );
		$scopes = $modes ? self::effective_scopes( $modes ) : array();
		if ( $shop ) {
			$scopes = array_values( array_unique( array_merge( $scopes, array_intersect( array( 'commerce.read' ), self::effective_scopes( array( 'stock' ) ) ) ) ) );
		}
		return array(
			'auth_method'          => self::AUTH_METHOD,
			'principal_kind'       => self::GURU_PUBLIC,
			'user_id'              => 0,
			'client_id'            => self::CLIENT_ID . ':guru:' . $character_id,
			'client_name'          => self::CLIENT_NAME,
			'key_id'               => 0,
			'scopes'               => $scopes,
			'modes'                => $modes,
			'role'                 => 'customer',
			'user_hash'            => preg_match( '/^[a-f0-9]{64}$/i', $user_hash ) ? strtolower( $user_hash ) : '', // a hash VALUE or nothing
			'account_id'           => $account_id,
			'turn_id'              => $turn_id,
			'guru_character_id'    => $character_id,
			'allowed_tools'        => $tools,
			'allowed_notebook_ids' => $ids,
			// [2026-10-09 03:27 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.95-S95-F14 (D95-20) — the Guru's product categories + every descendant;
			// [] = no limit (a Guru that chose nothing advises on the whole catalog).
			'allowed_product_cat_ids' => self::allowed_product_cat_ids( (array) ( $scope['product_cat_ids'] ?? array() ) ),
		);
	}

	/** PHASE-0.95 S95-F14 — the one customer-facing commerce tool (public catalog read). */
	const GUEST_COMMERCE_TOOL = 'commerce.search_products';

	/**
	 * [2026-10-09 03:27 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.95-S95-F14 — chosen product_cat ids ⇒ the ids plus all descendants, in the
	 * site's term order; [] stays [] (no limit). When the category tree cannot be read, the chosen ids are kept as they are
	 * (never widened to "everything").
	 *
	 * @param int[] $ids
	 * @return int[]
	 */
	public static function allowed_product_cat_ids( array $ids ): array {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ), static function ( $v ) { return $v > 0; } ) ) );
		if ( ! $ids ) {
			return array();
		}
		$groups = isset( self::$readers['product_cats'] ) ? (array) call_user_func( self::$readers['product_cats'] )
			: ( class_exists( 'BizCity_Commerce_Brain_MCP_Service' ) ? array_values( BizCity_Commerce_Brain_MCP_Service::product_groups() ) : array() );
		if ( ! $groups ) {
			return $ids;
		}
		return self::expand_product_cats( $ids, $groups );
	}

	/**
	 * Pure: chosen ids + descendants by `parent`, output in the order of `$groups` (fixture consult/catalog_map.scope.json);
	 * an empty choice keeps every group. Ids not present in `$groups` are dropped.
	 *
	 * @param int[]                          $ids
	 * @param array<int,array{id:int,parent:int}> $groups
	 * @return int[]
	 */
	public static function expand_product_cats( array $ids, array $groups ): array {
		$set = array();
		foreach ( $ids as $id ) {
			$set[ (int) $id ] = true;
		}
		$all = ! $set;
		do {
			$grew = false;
			foreach ( $groups as $g ) {
				$g = (array) $g;
				$id = (int) ( $g['id'] ?? 0 );
				if ( $id > 0 && ! isset( $set[ $id ] ) && isset( $set[ (int) ( $g['parent'] ?? 0 ) ] ) ) {
					$set[ $id ] = true;
					$grew       = true;
				}
			}
		} while ( $grew );
		$out = array();
		foreach ( $groups as $g ) {
			$id = (int) ( ( (array) $g )['id'] ?? 0 );
			if ( $id > 0 && ( $all || isset( $set[ $id ] ) ) ) {
				$out[] = $id;
			}
		}
		return $out;
	}

	/** True for a context built by context() or context_guru_public(). */
	public static function is_delegated( array $ctx ) {
		return self::AUTH_METHOD === (string) ( $ctx['auth_method'] ?? '' );
	}

	/**
	 * Q88-6 gate for tools/call: the tool's mode must be one of the principal's modes ('*business' = any business mode).
	 * A tool without a mode is never available to a delegated principal.
	 *
	 * @param array $tool registry descriptor (internal shape: mode, alias_of …)
	 */
	public static function tool_allowed( array $tool, array $ctx ) {
		$mode = isset( $tool['mode'] ) ? (string) $tool['mode'] : '';
		if ( '' === $mode ) {
			return false;
		}
		// [2026-10-05 09:40 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.91-R-AP-6 — a customer turn reaches a tool only when the registry marks it usable
		// by customers (roles ∋ customer: read-only knowledge tools) AND the Guru of the channel allows it (customer_tools).
		if ( self::GURU_PUBLIC === (string) ( $ctx['principal_kind'] ?? '' ) ) {
			$name = (string) ( $tool['name'] ?? '' );
			// [2026-10-05 11:40 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.91-AX-PERM — automation.* for guests: opened by the Guru, narrowed per scenario by the site (audience guest).
			if ( in_array( $name, self::GUEST_AUTOMATION_TOOLS, true ) ) {
				return empty( $tool['alias_of'] ) && in_array( $name, array_map( 'strval', (array) ( $ctx['allowed_tools'] ?? array() ) ), true );
			}
			return in_array( 'customer', (array) ( $tool['roles'] ?? array() ), true )
				&& empty( $tool['alias_of'] )
				&& ! empty( $tool['read_only'] )
				&& in_array( $name, array_map( 'strval', (array) ( $ctx['allowed_tools'] ?? array() ) ), true );
		}
		$modes = array_map( 'strval', (array) ( $ctx['modes'] ?? array() ) );
		if ( self::ANY_BUSINESS === $mode ) {
			return count( array_intersect( $modes, self::BUSINESS_MODES ) ) > 0;
		}
		return in_array( $mode, $modes, true );
	}

	/** tools/list for a delegated principal: allowed by mode AND not a deprecated alias (the cell uses canonical names). */
	public static function tool_listed( array $tool, array $ctx ) {
		return empty( $tool['alias_of'] ) && self::tool_allowed( $tool, $ctx );
	}

	/**
	 * Notebooks the user OWNS (owner_id = user_id). Shared/admin notebooks are not included: the cell reads the shop's
	 * Guru knowledge from its own cache (C-4).
	 *
	 * @return int[]
	 */
	public static function owned_notebook_ids( $user_id ) {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 ) {
			return array();
		}
		if ( isset( self::$readers['notebooks'] ) ) {
			$rows = (array) call_user_func( self::$readers['notebooks'], $user_id );
		} elseif ( class_exists( 'BizCity_KG_Notebook_Service' ) ) {
			$rows = (array) BizCity_KG_Notebook_Service::instance()->list_for_user( $user_id, array( 'limit' => 500 ) );
		} else {
			$rows = array();
		}
		$ids = array();
		foreach ( $rows as $row ) {
			$row   = (array) $row; // rows may be arrays or stdClass
			$id    = (int) ( $row['id'] ?? 0 );
			$owner = (int) ( $row['owner_id'] ?? 0 );
			if ( $id > 0 && $owner === $user_id ) {
				$ids[] = $id;
			}
		}
		return array_values( array_unique( $ids ) );
	}

	/**
	 * One JSONL evidence line per delegated request (R-MCP-OAUTH-ID.6 §7). Only hash prefixes, never a token, raw UID
	 * or body.
	 *
	 * @param string $reason one of REASONS
	 * @param array  $info   {account_id, user_hash, user_id, role, modes, scopes, turn_id, method}
	 */
	public static function log( $reason, array $info = array() ) {
		$reason = in_array( (string) $reason, self::REASONS, true ) ? (string) $reason : 'delegation_bad_token';
		$prefix = static function ( $value, $len ) {
			$value = (string) $value;
			return '' === $value ? '' : substr( hash( 'sha256', $value ), 0, $len );
		};
		$entry = array(
			'trace_id'    => class_exists( 'BizCity_MCP_Error' ) ? BizCity_MCP_Error::trace_id() : '',
			'blog_id'     => function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0,
			'user_id'     => (int) ( $info['user_id'] ?? 0 ),
			'key_id'      => 0,
			'client_id'   => self::CLIENT_ID,
			'client_name' => self::CLIENT_NAME,
			'tool_name'   => 'mcp.delegation',
			'status'      => 'delegation_ok' === $reason ? 'success' : 'error',
			'error_code'  => 'delegation_ok' === $reason ? '' : $reason,
			'duration_ms' => 0,
			'evaluation'  => array(
				'reason'           => $reason,
				'account_hash'     => $prefix( $info['account_id'] ?? '', 12 ),
				'principal_prefix' => substr( strtolower( (string) ( $info['user_hash'] ?? '' ) ), 0, 8 ),
				'role'             => (string) ( $info['role'] ?? '' ),
				'modes'            => array_values( array_map( 'strval', (array) ( $info['modes'] ?? array() ) ) ),
				'scope_count'      => count( (array) ( $info['scopes'] ?? array() ) ),
				'turn_hash'        => $prefix( $info['turn_id'] ?? '', 12 ),
				'method'           => preg_replace( '/[^a-z\/_]/', '', strtolower( (string) ( $info['method'] ?? '' ) ) ),
			),
		);
		if ( isset( self::$readers['log'] ) ) {
			call_user_func( self::$readers['log'], $entry );
			return;
		}
		if ( class_exists( 'BizCity_MCP_File_Logger' ) ) {
			BizCity_MCP_File_Logger::write( $entry );
		}
	}
}
