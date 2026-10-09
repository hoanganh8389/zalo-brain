<?php
/**
 * BizCity Zalo Personal — MCP bridge of a zalo-hub number (PHASE-0.88 L3-1, seam B of bizcity-mcp-bridge@1.0.0).
 *
 *   POST bizcity-channel/v1/zalo-bridge/mcp?account_id=   body = one JSON-RPC object (initialize · tools/list · tools/call)
 *
 * Hub → site only: the same per-number callback Bearer as /inbound, packs, owner-capture and turn-complete (Q88-4). The
 * Hub is a pipe; it forwards the cell's JSON-RPC body and two headers unchanged:
 *   X-BizCity-Principal  user_hash (64 hex) of the owner/staff who is talking to the Agent;
 *   X-BizCity-Turn-Id    the cell's turn id (evidence only).
 * The site maps the hash through ITS OWN binding (BizCity_Zalo_Agent_Principals::by_hash — active owner or staff of this
 * number only; R-MCP-OAUTH-ID.6 §1), runs as that WordPress user and dispatches to core/mcp with a delegated context
 * (BizCity_MCP_Delegation). No MCP session (stateless POST, Q88-6); tools are gated by mode, not by the admin allowlist.
 *
 * Refusals (fixture zalo-hub/contracts/fixtures/mcp/bridge.denied.json): HTTP 401 + JSON-RPC error -32000 with data.mcp_code
 * MCP_AUTH_INVALID (bad token) · MCP_DELEGATION_PRINCIPAL_UNBOUND · MCP_SCOPE_DENIED (no mode maps to a scope). Each request
 * writes one JSONL line tool_name=mcp.delegation. Never logs the token, the raw UID or the body.
 *
 * // @mcp bizcity-mcp-standard@1 seam site-bridge
 *
 * @package BizCity_Zalo_Personal
 * @since   PHASE-0.88 (2026-10-01)
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Zalo_MCP_Bridge_REST', false ) ) {
	return;
}

// [2026-10-01 Claude Opus 5.5] PHASE-0.88 L3-1 — new file, site end of the cell → Hub → site MCP pipe.
final class BizCity_Zalo_MCP_Bridge_REST {

	const NS              = 'bizcity-channel/v1';
	const ROUTE           = '/zalo-bridge/mcp';
	const PRINCIPAL_HDR   = 'x-bizcity-principal';
	/** PHASE-0.91 R-AP-6 — principal header value of a customer turn (the site picks the Guru, the cell sends no ref) */
	const GURU_PUBLIC_HDR = 'guru:public';
	// [2026-10-06 12:20 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.91-AX-PERM — on a customer turn the cell also names the sender (user_hash VALUE of its own
	// binding) so an audience-guest scenario answers THAT person and counts their own daily runs. Absent ⇒ no automation.
	const GUEST_HASH_HDR  = 'x-bizcity-guest-hash';
	const TURN_HDR        = 'x-bizcity-turn-id';
	const TURN_MAX        = 120;

	/**
	 * Test seams: token(account_id): string · principal(account_id, hash): ?array · guru(account_id): ?{character_id, scope}.
	 *
	 * @var array<string,callable>
	 */
	public static $readers = array();

	private static $initialized = false;

	public static function init(): void {
		if ( self::$initialized ) {
			return;
		}
		self::$initialized = true;
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes(): void {
		register_rest_route( self::NS, self::ROUTE, array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'handle' ),
			'permission_callback' => '__return_true', // Bearer verified in handler, same boundary as /inbound and packs.
		) );
	}

	public static function handle( WP_REST_Request $request ): WP_REST_Response {
		$account = sanitize_text_field( (string) $request->get_param( 'account_id' ) );
		$turn    = self::turn_id( (string) $request->get_header( self::TURN_HDR ) );
		if ( ! class_exists( 'BizCity_MCP_HTTP_Controller' ) || ! class_exists( 'BizCity_MCP_Delegation' ) || ! class_exists( 'BizCity_MCP_Error' ) ) {
			// core/mcp disabled or not loaded on this request: the Hub answers site_mcp_unsupported / the cell uses its packs.
			return new WP_REST_Response( array( 'jsonrpc' => '2.0', 'error' => array( 'code' => -32000, 'message' => 'MCP chưa bật trên site này.' ), 'id' => null ), 503 );
		}
		if ( ! self::authorized( $request, $account ) ) {
			BizCity_MCP_Delegation::log( 'delegation_bad_token', array( 'account_id' => $account, 'turn_id' => $turn ) );
			return self::refuse( BizCity_MCP_Error::AUTH_INVALID, 'Token callback của số không hợp lệ.' );
		}
		$hash      = strtolower( trim( (string) $request->get_header( self::PRINCIPAL_HDR ) ) );
		// [2026-10-05 Claude Opus 5.5] PHASE-0.91 R-AGENT-PRINCIPALS R-AP-6 (doc 92 G-B2) — a CUSTOMER turn: the site resolves the
		// Guru answering THIS number from its own binding (never a ref from the cell) and opens only that Guru's customer tools.
		if ( self::GURU_PUBLIC_HDR === $hash ) {
			return self::handle_guru_public( $request, $account, $turn );
		}
		$principal = preg_match( '/^[0-9a-f]{64}$/', $hash ) ? self::principal( $account, $hash ) : null;
		if ( null === $principal || ! in_array( (string) ( $principal['role'] ?? '' ), array( 'owner', 'staff' ), true ) || (int) ( $principal['user_id'] ?? 0 ) <= 0 ) {
			BizCity_MCP_Delegation::log( 'delegation_principal_unbound', array( 'account_id' => $account, 'user_hash' => $hash, 'turn_id' => $turn ) );
			return self::refuse( BizCity_MCP_Error::DELEGATION_PRINCIPAL_UNBOUND, 'Người này không thuộc danh sách dùng Agent của số.' );
		}
		$ctx  = BizCity_MCP_Delegation::context( $principal, $account, $turn );
		$info = array( 'account_id' => $account, 'user_hash' => $hash, 'user_id' => $ctx['user_id'], 'role' => $ctx['role'], 'modes' => $ctx['modes'], 'scopes' => $ctx['scopes'], 'turn_id' => $turn );
		if ( empty( $ctx['scopes'] ) ) {
			BizCity_MCP_Delegation::log( 'delegation_no_scope', $info );
			return self::refuse( BizCity_MCP_Error::SCOPE_DENIED, 'Người này chưa có mục nào được dùng qua Agent.' );
		}
		$body = BizCity_MCP_HTTP_Controller::decode_body( (string) $request->get_body() );
		if ( null === $body ) {
			return BizCity_MCP_HTTP_Controller::parse_error_response();
		}
		BizCity_MCP_Delegation::log( 'delegation_ok', $info + array( 'method' => (string) ( $body['method'] ?? '' ) ) );

		// Run as the bound WordPress user so CRM permissions apply naturally (AMA-1); restore whoever was current before.
		$previous = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		if ( function_exists( 'wp_set_current_user' ) ) {
			wp_set_current_user( (int) $ctx['user_id'] );
		}
		try {
			$response = BizCity_MCP_HTTP_Controller::dispatch( $body, $ctx, '' );
		} finally {
			if ( function_exists( 'wp_set_current_user' ) ) {
				wp_set_current_user( $previous );
			}
		}
		return $response;
	}

	/**
	 * PHASE-0.91 doc 92 G-B2 — customer turn over MCP: read-only knowledge of the Guru answering this number (R-AP-6).
	 * No WordPress user is set for the call; a Guru that shares no notebook or allows no tool is refused (scope denied).
	 */
	private static function handle_guru_public( WP_REST_Request $request, string $account, string $turn ): WP_REST_Response {
		$guru = self::guru_of_account( $account );
		if ( null === $guru || $guru['character_id'] <= 0 ) {
			BizCity_MCP_Delegation::log( 'delegation_principal_unbound', array( 'account_id' => $account, 'role' => 'customer', 'turn_id' => $turn ) );
			return self::refuse( BizCity_MCP_Error::DELEGATION_PRINCIPAL_UNBOUND, 'Số này chưa gắn Agent Guru để trả lời khách.' );
		}
		$guest = strtolower( trim( (string) $request->get_header( self::GUEST_HASH_HDR ) ) );
		$guest = preg_match( '/^[0-9a-f]{64}$/', $guest ) ? $guest : ''; // [2026-10-06 12:20 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.91-AX-PERM
		$ctx  = BizCity_MCP_Delegation::context_guru_public( (int) $guru['character_id'], $account, $turn, (array) $guru['scope'], $guest );
		// [2026-10-09 03:09 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.95-S95-A2 (G95-1, luật 2b) — the WordPress owner of THIS number (site binding, never
		// the request). Read only by BizCity_MCP_Action_Support::run_as for automation.list_scenarios / run_scenario; user_id stays 0, role customer.
		$ctx['acting_user_id'] = self::owner_of_account( $account );
		$info = array( 'account_id' => $account, 'user_id' => 0, 'role' => 'customer', 'modes' => $ctx['modes'], 'scopes' => $ctx['scopes'], 'turn_id' => $turn );
		if ( empty( $ctx['allowed_tools'] ) || empty( $ctx['scopes'] ) ) {
			BizCity_MCP_Delegation::log( 'delegation_no_scope', $info );
			return self::refuse( BizCity_MCP_Error::SCOPE_DENIED, 'Agent Guru của số này chưa cho khách đọc tài liệu nào.' );
		}
		$body = BizCity_MCP_HTTP_Controller::decode_body( (string) $request->get_body() );
		if ( null === $body ) {
			return BizCity_MCP_HTTP_Controller::parse_error_response();
		}
		BizCity_MCP_Delegation::log( 'delegation_ok', $info + array( 'method' => (string) ( $body['method'] ?? '' ) ) );
		$previous = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		if ( function_exists( 'wp_set_current_user' ) ) {
			wp_set_current_user( 0 );
		}
		try {
			return BizCity_MCP_HTTP_Controller::dispatch( $body, $ctx, '' );
		} finally {
			if ( function_exists( 'wp_set_current_user' ) ) {
				wp_set_current_user( $previous );
			}
		}
	}

	/** The Guru answering a number + its profile scope, from the site's own binding. @return array{character_id:int,scope:array}|null */
	private static function guru_of_account( string $account ): ?array {
		if ( isset( self::$readers['guru'] ) ) {
			$g = call_user_func( self::$readers['guru'], $account );
			return is_array( $g ) ? $g : null;
		}
		if ( ! class_exists( 'BizCity_Guru_Context_Resolver' ) ) {
			return null;
		}
		$binding = class_exists( 'BizCity_Channel_Binding' ) ? BizCity_Channel_Binding::resolve( 'ZALO_PERSONAL', $account ) : null;
		$bound   = is_array( $binding ) ? (int) ( $binding['character_id'] ?? 0 ) : 0;
		$cid     = BizCity_Guru_Context_Resolver::answering_character_id( $bound );
		if ( $cid <= 0 ) {
			return null;
		}
		$profile = BizCity_Guru_Context_Resolver::profile( $cid );
		return array( 'character_id' => $cid, 'scope' => (array) ( $profile['scope'] ?? array() ) );
	}

	/**
	 * [2026-10-09 03:09 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.95-S95-A2 — owner_user_id of the number: the Zalo mapping row (same source as
	 * BizCity_Zalo_Agent_Principals), else the channel binding's owner column when it has one. 0 = unknown (guest automation then stays 401).
	 * Seam `$readers['owner']`.
	 */
	private static function owner_of_account( string $account ): int {
		if ( isset( self::$readers['owner'] ) ) {
			return max( 0, (int) call_user_func( self::$readers['owner'], $account ) );
		}
		$uid = class_exists( 'BizCity_Zalo_Agent_Principals' ) ? (int) BizCity_Zalo_Agent_Principals::owner_user_id( $account ) : 0;
		if ( $uid <= 0 && class_exists( 'BizCity_Channel_Binding' ) ) {
			$b   = BizCity_Channel_Binding::resolve( 'ZALO_PERSONAL', $account );
			$uid = is_array( $b ) ? (int) ( $b['owner_user_id'] ?? 0 ) : 0;
		}
		return max( 0, $uid );
	}

	/** HTTP 401 + JSON-RPC error like core/mcp auth errors (bridge.denied.json). */
	private static function refuse( string $code, string $message ): WP_REST_Response {
		$env = BizCity_MCP_Error::fail( 'mcp.transport', $code, $message );
		return new WP_REST_Response( array(
			'jsonrpc' => '2.0',
			'error'   => array(
				'code'    => -32000,
				'message' => (string) $env['message'],
				'data'    => array( 'mcp_code' => (string) $env['code'], 'hint' => (string) $env['hint'], 'help_code' => (string) $env['help_code'] ),
			),
			'id'      => null,
		), 401 );
	}

	private static function authorized( WP_REST_Request $request, string $account ): bool {
		if ( '' === $account ) {
			return false;
		}
		$token  = isset( self::$readers['token'] ) ? (string) call_user_func( self::$readers['token'], $account ) : ( class_exists( 'BizCity_Zalo_Bridge_Client' ) ? BizCity_Zalo_Bridge_Client::instance()->expected_inbound_token( $account ) : '' );
		$header = (string) $request->get_header( 'authorization' );
		$bearer = stripos( $header, 'Bearer ' ) === 0 ? trim( substr( $header, 7 ) ) : '';
		return '' !== $token && '' !== $bearer && hash_equals( $token, $bearer );
	}

	/** Active owner/staff principal of THIS number with that hash, or null (never trusted from the request). */
	private static function principal( string $account, string $hash ): ?array {
		if ( isset( self::$readers['principal'] ) ) {
			$p = call_user_func( self::$readers['principal'], $account, $hash );
			return is_array( $p ) ? $p : null;
		}
		return class_exists( 'BizCity_Zalo_Agent_Principals' ) ? BizCity_Zalo_Agent_Principals::by_hash( $account, $hash ) : null;
	}

	private static function turn_id( string $raw ): string {
		return substr( (string) preg_replace( '/[^A-Za-z0-9_.:\-]/', '', $raw ), 0, self::TURN_MAX );
	}
}
