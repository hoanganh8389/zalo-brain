<?php
/**
 * BizCity_CRM_Group_Notebook_REST — tab "Sổ nhóm" của hội thoại nhóm Zalo trong CRM (PHASE-0.124 B13, mockup S124-A4 chủ duyệt).
 *
 *   GET  bizcity-crm/v1/conversations/{id}/group-notebook          sổ ghi chép · tài liệu · việc · sản phẩm của nhóm (quyền xem hội thoại)
 *   POST bizcity-crm/v1/conversations/{id}/group-notebook/track    "Ghi vào sổ" một việc của brief (Q124-7: người có quyền xác nhận)
 *
 * Dạng trả như các REST CRM khác: {ok, data} · lỗi {ok:false, error{code, message, hint}}.
 *
 * Biz Central Brain — Johnny Chu (Chu Hoàng Anh).
 *
 * [2026-10-19 11:22 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.124 B13 — new file.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\CRM
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_CRM_Group_Notebook_REST', false ) ) {
	return;
}

final class BizCity_CRM_Group_Notebook_REST {

	const NS = 'bizcity-crm/v1';

	/** Test seams: can_read(request) · current_user() */
	public static $readers = array();

	private static $hooked = false;

	public static function init(): void {
		if ( self::$hooked ) {
			return;
		}
		self::$hooked = true;
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes(): void {
		$perm = array( __CLASS__, 'can_read' );
		register_rest_route( self::NS, '/conversations/(?P<id>\d+)/group-notebook', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'handle_get' ), 'permission_callback' => $perm ) );
		register_rest_route( self::NS, '/conversations/(?P<id>\d+)/group-notebook/track', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'handle_track' ), 'permission_callback' => $perm ) );
	}

	/** Cùng quyền với GET conversations/{id} (BizCity_CRM_Spine_REST::can_read_inbox_scope) */
	public static function can_read( $request = null ) {
		if ( isset( self::$readers['can_read'] ) ) {
			return (bool) call_user_func( self::$readers['can_read'], $request );
		}
		return class_exists( 'BizCity_CRM_Spine_REST' ) ? BizCity_CRM_Spine_REST::can_read_inbox_scope_response( $request ) : false;
	}

	public static function handle_get( WP_REST_Request $req ): WP_REST_Response {
		$id = (int) $req->get_param( 'id' );
		$r  = BizCity_CRM_Group_Secretary::notebook( $id );
		if ( is_wp_error( $r ) ) {
			return self::error( $r );
		}
		$r['can_confirm'] = BizCity_CRM_Group_Secretary::can_confirm( $id, self::current_user() );
		return new WP_REST_Response( array( 'ok' => true, 'data' => $r ), 200 );
	}

	public static function handle_track( WP_REST_Request $req ): WP_REST_Response {
		$id   = (int) $req->get_param( 'id' );
		$user = self::current_user();
		if ( ! BizCity_CRM_Group_Secretary::can_confirm( $id, $user ) ) {
			return self::error( new WP_Error( 'group_confirm_forbidden', 'Chỉ chủ số (quản trị / supervisor CRM) hoặc người được giao của nhóm mới ghi việc vào sổ.', array( 'status' => 403 ) ) );
		}
		$body = $req->get_json_params();
		$r    = BizCity_CRM_Group_Secretary::track_task( $id, is_array( $body ) ? $body : array(), $user );
		if ( is_wp_error( $r ) ) {
			return self::error( $r );
		}
		return new WP_REST_Response( array( 'ok' => true, 'data' => $r ), 200 );
	}

	private static function current_user(): int {
		return isset( self::$readers['current_user'] ) ? (int) call_user_func( self::$readers['current_user'] ) : ( function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0 );
	}

	private static function error( WP_Error $e ): WP_REST_Response {
		$data   = $e->get_error_data();
		$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 400;
		return new WP_REST_Response( array( 'ok' => false, 'error' => array( 'code' => $e->get_error_code(), 'message' => $e->get_error_message(), 'hint' => 403 === $status ? 'Nhờ chủ số giao nhóm cho bạn, hoặc quản trị ghi giúp.' : 'Tải lại tab Sổ nhóm rồi thử lại.' ) ), $status );
	}
}
