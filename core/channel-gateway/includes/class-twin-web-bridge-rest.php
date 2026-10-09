<?php
/**
 * MCP bridge of the site's OWN web channel (PHASE-0.90 / 0.89 "data path for a tenant with no Zalo number", R-CONTACT-ID,
 * seam B of bizcity-mcp-bridge@1.0.0 for the `wp` channel).
 *
 *   POST bizcity-channel/v1/twin-web-bridge/mcp   body = one JSON-RPC object (initialize · tools/list · tools/call)
 *   GET  bizcity-channel/v1/twin-web-bridge/packs[/{kind}]?principal=&cursor=&limit=   projection packs of the web home
 *        (same exporters, same gates and shapes as zalo-bridge/packs; the person comes from BizCity_Twin_Web_Home, never the request)
 *   GET  bizcity-channel/v1/twin-web-bridge/guru-profile?ref=      Guru Context API profile of the Guru that answers the web home
 *   GET  bizcity-channel/v1/twin-web-bridge/guru/{ref}/knowledge[/{nb}]   C-4 notebook list / passages (BizCity_Zalo_Guru_Knowledge_REST)
 *   POST bizcity-channel/v1/twin-web-bridge/guru-context {ref, thread_id, query, include_instruction, max_blocks, max_chars}
 *        (bizcity-guru-context/1.x, same shapes as zalo-bridge/guru-*; the Guru is the site's default Guru, no customer block)
 *
 * Same pipe as the Zalo number's `zalo-bridge/mcp` (cell → Hub → site), different credential and different principal source:
 *   - Bearer = the site's web-bridge token (option `bizcity_twin_web_bridge_token`, generated here, pushed to the Hub together
 *     with the web-home block over the site's 1API key, never shown, never logged);
 *   - X-BizCity-Principal = user_hash; the site maps it through BizCity_Twin_Web_Home (the site's own owner/staff list), runs as
 *     that WordPress user and dispatches to core/mcp with a delegated context (BizCity_MCP_Delegation). A hash that is not an
 *     active owner/staff of the web home is refused - the request never decides who the person is.
 *
 * Refusals use the same shape as the Zalo bridge (HTTP 401 + JSON-RPC -32000, data.mcp_code). Never logs the token, the body
 * or a raw user id.
 *
 * // @mcp bizcity-mcp-standard@1 seam site-bridge
 * // @axis twin-agent-axis@1 seam SEAM-2
 *
 * @package BizCity_Twin_AI
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Twin_Web_Bridge_REST', false ) ) {
	return;
}

final class BizCity_Twin_Web_Bridge_REST {

	const NS            = 'bizcity-channel/v1';
	const ROUTE         = '/twin-web-bridge/mcp';
	const PACKS_ROUTE   = '/twin-web-bridge/packs';
	const PACKS_ACCOUNT = 'web';
	const GURU_PROFILE  = '/twin-web-bridge/guru-profile';
	const GURU_CONTEXT  = '/twin-web-bridge/guru-context';
	const PRINCIPAL_HDR = 'x-bizcity-principal';
	const TURN_HDR      = 'x-bizcity-turn-id';
	const TURN_MAX      = 120;

	/** Test seams: dispatch(body, ctx): WP_REST_Response · mcp_ready(): bool */
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
			'permission_callback' => '__return_true', // Bearer verified in handler, same boundary as the Zalo bridge.
		) );
		register_rest_route( self::NS, self::GURU_PROFILE, array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'handle_guru_profile' ),
			'permission_callback' => '__return_true',
		) );
		register_rest_route( self::NS, '/twin-web-bridge/guru/(?P<ref>[a-z0-9:]+)/knowledge', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'handle_guru_knowledge' ),
			'permission_callback' => '__return_true',
		) );
		register_rest_route( self::NS, '/twin-web-bridge/guru/(?P<ref>[a-z0-9:]+)/knowledge/(?P<nb>\d+)', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'handle_guru_knowledge' ),
			'permission_callback' => '__return_true',
		) );
		register_rest_route( self::NS, self::GURU_CONTEXT, array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'handle_guru_context' ),
			'permission_callback' => '__return_true',
		) );
		register_rest_route( self::NS, self::PACKS_ROUTE, array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'handle_packs' ),
			'permission_callback' => '__return_true',
		) );
		register_rest_route( self::NS, self::PACKS_ROUTE . '/(?P<kind>[a-z_]{2,40})', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'handle_packs' ),
			'permission_callback' => '__return_true',
		) );
	}

	/**
	 * Same gate as the Zalo bridge's guru routes, for the web home: the web-bridge token, then the ONE Guru that answers the web
	 * (the site's default Guru, `guru:0` or `guru:<id>` of it, or `auto`); any other ref is 404 so a cell cannot enumerate Gurus.
	 *
	 * @return array{ok:bool,response?:WP_REST_Response,character_id?:int}
	 */
	private static function guru_gate( WP_REST_Request $request, string $ref ): array {
		$fail = static function ( int $status, string $code, string $message ): array {
			return array( 'ok' => false, 'response' => new WP_REST_Response( array( 'ok' => false, 'code' => $code, 'message' => $message, 'help_code' => $code ), $status ) );
		};
		if ( ! self::authorized( $request ) ) {
			return $fail( 401, 'unauthorized', 'Unauthorized.' );
		}
		$seam = isset( self::$readers['guru_answering'] );
		if ( ! $seam && ! class_exists( 'BizCity_Guru_Context_Resolver' ) ) {
			return $fail( 503, 'site_guru_unsupported', 'Guru context resolver is not loaded on this site.' );
		}
		$answering = $seam ? (int) call_user_func( self::$readers['guru_answering'] ) : (int) BizCity_Guru_Context_Resolver::answering_character_id( 0 );
		$allowed   = $answering > 0 && ( 'auto' === $ref || 'guru:' . $answering === $ref || 'guru:0' === $ref );
		return $allowed ? array( 'ok' => true, 'character_id' => $answering ) : $fail( 404, 'guru_not_found', 'This Guru does not answer the web.' );
	}

	public static function handle_guru_profile( WP_REST_Request $request ): WP_REST_Response {
		$gate = self::guru_gate( $request, sanitize_text_field( (string) $request->get_param( 'ref' ) ) );
		if ( ! $gate['ok'] ) {
			return $gate['response'];
		}
		$profile = isset( self::$readers['guru_profile'] ) ? (array) call_user_func( self::$readers['guru_profile'], (int) $gate['character_id'] ) : (array) BizCity_Guru_Context_Resolver::profile( (int) $gate['character_id'] );
		unset( $profile['_character_id'] );
		$etag = (string) ( $profile['guru']['etag'] ?? '' );
		if ( '' !== $etag && trim( (string) $request->get_header( 'if_none_match' ), ' "' ) === $etag ) {
			$r = new WP_REST_Response( null, 304 );
			$r->header( 'ETag', '"' . $etag . '"' );
			return $r;
		}
		$r = new WP_REST_Response( $profile, 200 );
		$r->header( 'ETag', '"' . $etag . '"' );
		return $r;
	}

	/** C-4 notebook pack of the Guru that answers the web: same gate as guru-profile, same shapes as the number's route. */
	public static function handle_guru_knowledge( WP_REST_Request $request ): WP_REST_Response {
		$gate = self::guru_gate( $request, sanitize_text_field( (string) $request->get_param( 'ref' ) ) );
		if ( ! $gate['ok'] ) {
			return $gate['response'];
		}
		if ( ! class_exists( 'BizCity_Zalo_Guru_Knowledge_REST' ) || ! method_exists( 'BizCity_Zalo_Guru_Knowledge_REST', 'serve_list' ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'site_guru_unsupported', 'message' => 'Notebook pack is not available on this site.' ), 503 );
		}
		$nb = (int) $request->get_param( 'nb' );
		return $nb > 0 ? BizCity_Zalo_Guru_Knowledge_REST::serve_chunks( $request, (int) $gate['character_id'] ) : BizCity_Zalo_Guru_Knowledge_REST::serve_list( $request, (int) $gate['character_id'] );
	}

	public static function handle_guru_context( WP_REST_Request $request ): WP_REST_Response {
		$body = $request->get_json_params();
		$body = is_array( $body ) ? $body : array();
		$gate = self::guru_gate( $request, sanitize_text_field( (string) ( $body['ref'] ?? '' ) ) );
		if ( ! $gate['ok'] ) {
			return $gate['response'];
		}
		$cid = (int) $gate['character_id'];
		if ( isset( self::$readers['guru_context'] ) ) {
			return call_user_func( self::$readers['guru_context'], $cid, $body );
		}
		$prompt = BizCity_Guru_Context_Resolver::context( $cid, array(
			'contact_id' => 0, // the person is the owner/staff of the site, not a customer: no customer block
			'query'      => mb_substr( (string) ( $body['query'] ?? '' ), 0, 2000 ),
			'max_blocks' => (int) ( $body['max_blocks'] ?? 0 ) ?: null,
			'max_chars'  => (int) ( $body['max_chars'] ?? 0 ) ?: null,
			'notebooks'  => false, // the cell retrieves from its own copy of the notebooks
		) );
		$profile = (array) BizCity_Guru_Context_Resolver::profile( $cid );
		unset( $profile['_character_id'] );
		$out = array(
			'contract'     => $profile['contract'],
			'guru'         => $profile['guru'],
			'prompt'       => $prompt,
			'scope'        => $profile['scope'],
			'compose'      => $profile['compose'],
			'generated_at' => gmdate( 'c' ),
		);
		if ( ! empty( $body['include_instruction'] ) ) {
			$out['instruction'] = $profile['instruction'];
		}
		return new WP_REST_Response( $out, 200 );
	}

	/**
	 * Packs of the web home: Bearer = the web-bridge token; the person = BizCity_Twin_Web_Home::principal_by_hash(principal).
	 * Everything else (exporters, mode / role-group gates, paging, ETag) is BizCity_Zalo_Pack_REST's, called with this context.
	 */
	public static function handle_packs( WP_REST_Request $request ): WP_REST_Response {
		if ( ! class_exists( 'BizCity_Zalo_Pack_REST' ) || ! method_exists( 'BizCity_Zalo_Pack_REST', 'serve_list' ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'packs_unavailable' ), 503 );
		}
		if ( ! self::authorized( $request ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'unauthorized' ), 401 );
		}
		$hash      = strtolower( trim( (string) $request->get_param( 'principal' ) ) );
		$principal = 1 === preg_match( '/^[0-9a-f]{64}$/', $hash ) ? BizCity_Twin_Web_Home::principal_by_hash( $hash ) : null;
		if ( null === $principal ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'not_own_notebook', 'message' => 'Người này không thuộc danh sách dùng Agent trên web của site.', 'hint' => 'Mở lại một lượt chat web của chủ để đồng bộ danh sách.', 'help_code' => 'S89-WEBPACK-403' ), 403 );
		}
		$ctx = array(
			'account_id'    => self::PACKS_ACCOUNT,
			'owner_user_id' => (int) $principal['user_id'],
			'owner_uid_set' => true,  // the web home has no Zalo UID: the site's own list is the proof
			'enabled'       => true,
			'user_hash'     => $hash,
			'role'          => (string) $principal['role'],
		);
		$kind = sanitize_key( (string) $request->get_param( 'kind' ) );
		if ( '' === $kind ) {
			return BizCity_Zalo_Pack_REST::serve_list( $request, self::PACKS_ACCOUNT, $ctx );
		}
		$spec = BizCity_Zalo_Pack_REST::exporters()[ $kind ] ?? null;
		if ( null === $spec ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'pack_kind_unknown' ), 404 );
		}
		return BizCity_Zalo_Pack_REST::serve_page( $request, $kind, $spec, $ctx );
	}

	/** The public URL the Hub calls (pushed with the web-home block). */
	public static function url(): string {
		return function_exists( 'rest_url' ) ? (string) rest_url( self::NS . self::ROUTE ) : '';
	}

	public static function handle( WP_REST_Request $request ): WP_REST_Response {
		$turn = substr( (string) preg_replace( '/[^A-Za-z0-9_.:\-]/', '', (string) $request->get_header( self::TURN_HDR ) ), 0, self::TURN_MAX );
		$ready = isset( self::$readers['mcp_ready'] )
			? (bool) call_user_func( self::$readers['mcp_ready'] )
			: ( class_exists( 'BizCity_MCP_HTTP_Controller' ) && class_exists( 'BizCity_MCP_Delegation' ) && class_exists( 'BizCity_MCP_Error' ) );
		if ( ! $ready ) {
			return new WP_REST_Response( array( 'jsonrpc' => '2.0', 'error' => array( 'code' => -32000, 'message' => 'MCP chưa bật trên site này.' ), 'id' => null ), 503 );
		}
		if ( ! self::authorized( $request ) ) {
			BizCity_MCP_Delegation::log( 'delegation_bad_token', array( 'account_id' => 'web', 'turn_id' => $turn ) );
			return self::refuse( BizCity_MCP_Error::AUTH_INVALID, 'Token cầu nối web của site không hợp lệ.' );
		}
		$hash      = strtolower( trim( (string) $request->get_header( self::PRINCIPAL_HDR ) ) );
		$principal = preg_match( '/^[0-9a-f]{64}$/', $hash ) ? BizCity_Twin_Web_Home::principal_by_hash( $hash ) : null;
		if ( null === $principal ) {
			BizCity_MCP_Delegation::log( 'delegation_principal_unbound', array( 'account_id' => 'web', 'user_hash' => $hash, 'turn_id' => $turn ) );
			return self::refuse( BizCity_MCP_Error::DELEGATION_PRINCIPAL_UNBOUND, 'Người này không thuộc danh sách dùng Agent trên web của site.' );
		}
		$ctx = BizCity_MCP_Delegation::context( $principal, 'web', $turn );
		if ( empty( $ctx['scopes'] ) ) {
			BizCity_MCP_Delegation::log( 'delegation_no_scope', array( 'account_id' => 'web', 'user_hash' => $hash, 'role' => $ctx['role'], 'turn_id' => $turn ) );
			return self::refuse( BizCity_MCP_Error::SCOPE_DENIED, 'Người này chưa có mục nào được dùng qua Agent.' );
		}
		$body = BizCity_MCP_HTTP_Controller::decode_body( (string) $request->get_body() );
		if ( null === $body ) {
			return BizCity_MCP_HTTP_Controller::parse_error_response();
		}
		BizCity_MCP_Delegation::log( 'delegation_ok', array( 'account_id' => 'web', 'user_hash' => $hash, 'user_id' => $ctx['user_id'], 'role' => $ctx['role'], 'modes' => $ctx['modes'], 'scopes' => $ctx['scopes'], 'turn_id' => $turn, 'method' => (string) ( $body['method'] ?? '' ) ) );
		if ( isset( self::$readers['dispatch'] ) ) {
			return call_user_func( self::$readers['dispatch'], $body, $ctx );
		}
		// Run as the bound WordPress user so CRM permissions apply naturally; restore whoever was current before.
		$previous = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		if ( function_exists( 'wp_set_current_user' ) ) {
			wp_set_current_user( (int) $ctx['user_id'] );
		}
		try {
			return BizCity_MCP_HTTP_Controller::dispatch( $body, $ctx, '' );
		} finally {
			if ( function_exists( 'wp_set_current_user' ) ) {
				wp_set_current_user( $previous );
			}
		}
	}

	private static function authorized( WP_REST_Request $request ): bool {
		$token  = class_exists( 'BizCity_Twin_Web_Home' ) ? BizCity_Twin_Web_Home::bridge_token() : '';
		$header = (string) $request->get_header( 'authorization' );
		$bearer = stripos( $header, 'Bearer ' ) === 0 ? trim( substr( $header, 7 ) ) : '';
		return '' !== $token && '' !== $bearer && hash_equals( $token, $bearer );
	}

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
}

if ( function_exists( 'add_action' ) ) {
	BizCity_Twin_Web_Bridge_REST::init();
}
