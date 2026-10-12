<?php
/**
 * BizCity_Journal_REST — `bizcity-knowledge/v2/journal` (PHASE-0.100 S100-L22, doc 92 §9, R-JOURNAL R-JN-5).
 *
 * Same handlers as the MCP tools (`BizCity_Journal_MCP_Tools::find/get/summarize`) — one logic, two doors — read
 * only, `is_user_logged_in()` gate, owner = the logged-in WordPress user (REST never acts as a delegated principal).
 *
 * Biz Central Brain — Johnny Chu (Chu Hoàng Anh).
 *
 * [2026-10-12 12:05 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.100 S100-L22 — new file.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\KG_Hub
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Journal_REST', false ) ) {
	return;
}

final class BizCity_Journal_REST {

	const NS = 'bizcity-knowledge/v2';

	private static $hooked = false;

	public static function init(): void {
		if ( self::$hooked ) {
			return;
		}
		self::$hooked = true;
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes(): void {
		register_rest_route( self::NS, '/journal/find', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'find' ),
			'permission_callback' => array( __CLASS__, 'permission' ),
		) );
		register_rest_route( self::NS, '/journal/(?P<id>\d+)', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'get' ),
			'permission_callback' => array( __CLASS__, 'permission' ),
		) );
		register_rest_route( self::NS, '/journal/summarize', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'summarize' ),
			'permission_callback' => array( __CLASS__, 'permission' ),
		) );
	}

	public static function permission() {
		return is_user_logged_in();
	}

	public static function find( WP_REST_Request $request ): WP_REST_Response {
		if ( ! class_exists( 'BizCity_Journal_MCP_Tools' ) ) {
			return new WP_REST_Response( array( 'code' => 'journal_unavailable', 'message' => 'Chỉ mục nhật ký chưa sẵn sàng trên site này.', 'hint' => '', 'help_code' => 'S100-JN-REST-1' ), 503 );
		}
		$args = self::read_params( $request, array( 'kind', 'kinds', 'kind_prefix', 'tags', 'subject_hash', 'from', 'to', 'q', 'limit', 'offset' ) );
		$res  = BizCity_Journal_MCP_Tools::find( $args, self::ctx() );
		return self::respond( $res );
	}

	public static function get( WP_REST_Request $request ): WP_REST_Response {
		if ( ! class_exists( 'BizCity_Journal_MCP_Tools' ) ) {
			return new WP_REST_Response( array( 'code' => 'journal_unavailable', 'message' => 'Chỉ mục nhật ký chưa sẵn sàng trên site này.', 'hint' => '', 'help_code' => 'S100-JN-REST-1' ), 503 );
		}
		$res = BizCity_Journal_MCP_Tools::get( array( 'entry_id' => (int) $request->get_param( 'id' ) ), self::ctx() );
		return self::respond( $res );
	}

	public static function summarize( WP_REST_Request $request ): WP_REST_Response {
		if ( ! class_exists( 'BizCity_Journal_MCP_Tools' ) ) {
			return new WP_REST_Response( array( 'code' => 'journal_unavailable', 'message' => 'Chỉ mục nhật ký chưa sẵn sàng trên site này.', 'hint' => '', 'help_code' => 'S100-JN-REST-1' ), 503 );
		}
		$args = self::read_params( $request, array( 'kind', 'kinds', 'subject_hash', 'from', 'to', 'field', 'group_by' ) );
		$res  = BizCity_Journal_MCP_Tools::summarize( $args, self::ctx() );
		return self::respond( $res );
	}

	private static function ctx(): array {
		return array( 'owner_user_id' => get_current_user_id(), 'role' => current_user_can( 'manage_options' ) ? 'owner' : 'staff' );
	}

	private static function read_params( WP_REST_Request $request, array $keys ): array {
		$out = array();
		foreach ( $keys as $k ) {
			$v = $request->get_param( $k );
			if ( null !== $v ) {
				$out[ $k ] = $v;
			}
		}
		return $out;
	}

	/** @param array|WP_Error $res */
	private static function respond( $res ): WP_REST_Response {
		if ( is_wp_error( $res ) ) {
			$data = $res->get_error_data();
			$status = is_array( $data ) && ! empty( $data['status'] ) ? (int) $data['status'] : 400;
			return new WP_REST_Response( array(
				'code'      => $res->get_error_code(),
				'message'   => $res->get_error_message(),
				'hint'      => '',
				'help_code' => 'S100-JN-REST-' . $status,
			), $status );
		}
		return new WP_REST_Response( $res, 200 );
	}
}
