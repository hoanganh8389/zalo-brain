<?php
/**
 * Biz Central Brain — Johnny Chu (Chu Hoàng Anh). Bizcity Central Brain, Giấy chứng nhận đăng ký quyền tác giả số 8877/2026/QTG.
 */
// [2026-10-16 08:00 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.116 S116-U1/U2 — GET bizcity-channel/v1/connectors: manifest (connector-framework@1) cho SPA, một nguồn.
/**
 * The SPA draws the Công cụ page (3 zones) and every ConnectorShell from this response instead of its own tables.
 * Read-only; no secrets exist in a manifest (R-CONNECTOR-8), but `plan_gate` and `module_access` are kept: the UI needs them.
 * `status` is NOT guessed here: a connector's live state (R-CONNECTOR-5) comes from real checks, never from this file (R-CONNECTOR-5: no hand-set status).
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\ChannelGateway
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Connector_REST', false ) ) {
	return;
}

final class BizCity_Connector_REST {

	const NS = 'bizcity-channel/v1';

	public static function register_routes() {
		register_rest_route(
			self::NS,
			'/connectors',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'list_connectors' ),
				'permission_callback' => static function () {
					return current_user_can( 'manage_options' );
				},
			)
		);
	}

	/**
	 * [2026-10-17 00:10 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.116 S116-U8 — the Quyền tab: who has which LEVEL (R0 Xem · R1 Soạn · R2 Gửi · R3 Tiền & tài khoản) on ONE channel account.
	 * GET  grants?channel=&account_id=            ⇒ people + their level + candidates + the limit
	 * POST grants {channel, account_id, user_id, level}   level R0–R3 ⇒ set; level "" / null ⇒ revoke
	 * Site admins only here; the grant layer re-checks (BizCity_Channel_User_Grant) and refuses the primary, an unknown level, the limit.
	 */
	public static function register_grant_routes() {
		$admin = static function () {
			return current_user_can( 'manage_options' );
		};
		register_rest_route( self::NS, '/grants', array(
			array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'get_grants' ), 'permission_callback' => $admin ),
			array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'set_grant' ), 'permission_callback' => $admin ),
		) );
		// [2026-10-17 01:00 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.116 S116-A1: give an account that has no owner yet its owner (the number's own owner, else the shop owner)
		register_rest_route( self::NS, '/grants/ensure-owner', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'ensure_owner' ), 'permission_callback' => $admin ) );
	}

	/** @return array{channel:string,account_id:string}|null */
	private static function scope_of( $request ) {
		$channel = sanitize_key( (string) $request->get_param( 'channel' ) );
		$account = trim( (string) $request->get_param( 'account_id' ) );
		if ( '' === $channel || '' === $account || strlen( $account ) > 191 || ! in_array( $channel, BizCity_Channel_User_Grant::CHANNELS, true ) ) {
			return null;
		}
		return array( 'channel' => $channel, 'account_id' => $account );
	}

	private static function bad( $code, $message, $status = 400 ) {
		return new WP_REST_Response( array( 'ok' => false, 'code' => $code, 'message' => $message, 'hint' => '' ), $status );
	}

	public static function get_grants( $request ) {
		$sc = self::scope_of( $request );
		if ( ! $sc ) {
			return self::bad( 'invalid_param', 'Cần channel và account_id hợp lệ.' );
		}
		$people = array();
		$have   = array();
		foreach ( BizCity_Channel_User_Grant::users_for_account( $sc['channel'], $sc['account_id'] ) as $g ) {
			$u        = get_userdata( (int) $g['user_id'] );
			$have[]   = (int) $g['user_id'];
			$people[] = array(
				'user_id'  => (int) $g['user_id'],
				'name'     => $u ? (string) $u->display_name : '#' . (int) $g['user_id'],
				'relation' => (string) ( $g['relation'] ?? 'agent' ),
				'level'    => (string) $g['level'],
				'locked'   => 'primary' === ( $g['relation'] ?? '' ), // the owner's level is not editable here (transfer is its own act)
			);
		}
		$candidates = array();
		foreach ( (array) get_users( array( 'number' => 50, 'fields' => array( 'ID', 'display_name' ), 'exclude' => $have ) ) as $u ) {
			$candidates[] = array( 'user_id' => (int) ( is_object( $u ) ? $u->ID : $u['ID'] ), 'name' => (string) ( is_object( $u ) ? $u->display_name : $u['display_name'] ) );
		}
		return new WP_REST_Response( array(
			'ok'         => true,
			'channel'    => $sc['channel'],
			'account_id' => $sc['account_id'],
			'levels'     => BizCity_Channel_User_Grant::LEVELS,
			'max_users'  => BizCity_Channel_User_Grant::max_users(),
			'people'     => $people,
			'candidates' => $candidates,
		), 200 );
	}

	public static function ensure_owner( $request ) {
		$sc = self::scope_of( $request );
		if ( ! $sc ) {
			return self::bad( 'invalid_param', 'Cần channel và account_id hợp lệ.' );
		}
		$r = BizCity_Account_Owner::ensure( $sc['channel'], $sc['account_id'] );
		if ( empty( $r['ok'] ) ) {
			$say = array( 'no_usable_owner' => 'Website chưa có người dùng nào làm chủ được (cần ít nhất một quản trị viên).', 'personal_account_quota_reached' => 'Chủ dự kiến đã đủ số tài khoản Zalo theo gói.' );
			return self::bad( (string) $r['reason'], $say[ $r['reason'] ] ?? 'Không gán được chủ cho tài khoản này.', 422 );
		}
		return new WP_REST_Response( array( 'ok' => true, 'user_id' => (int) $r['user_id'], 'source' => $r['source'], 'created' => (bool) $r['created'] ), 200 );
	}

	public static function set_grant( $request ) {
		$sc = self::scope_of( $request );
		$uid = (int) $request->get_param( 'user_id' );
		if ( ! $sc || $uid <= 0 ) {
			return self::bad( 'invalid_param', 'Cần channel, account_id và user_id hợp lệ.' );
		}
		$level = $request->get_param( 'level' );
		$level = ( null === $level || '' === $level ) ? '' : strtoupper( (string) $level );
		$actor = get_current_user_id();
		$res   = '' === $level
			? BizCity_Channel_User_Grant::revoke( $sc['channel'], $sc['account_id'], $uid, $actor )
			: BizCity_Channel_User_Grant::grant_level( $sc['channel'], $sc['account_id'], $uid, $level, $actor );
		if ( empty( $res['ok'] ) ) {
			$reason = (string) ( $res['reason'] ?? 'grant_failed' );
			$say    = array(
				'level_unknown'            => 'Bậc không hợp lệ.',
				'delegate_limit_reached'   => 'Đã đủ số người tối đa trên tài khoản này.',
				'primary_is_not_delegate'  => 'Chủ tài khoản không đổi bậc ở đây.',
				'primary_transfer_required' => 'Muốn đổi chủ tài khoản, dùng chức năng chuyển chủ.',
				'grant_manage_denied'      => 'Bạn không có quyền cấp quyền trên tài khoản này.',
				'channel_primary_missing'  => 'Tài khoản này chưa có chủ.',
				'delegate_not_member'      => 'Người này không thuộc website.',
				'grant_not_found'          => 'Người này chưa có quyền trên tài khoản.',
			);
			return self::bad( $reason, $say[ $reason ] ?? 'Không lưu được quyền.', 'grant_manage_denied' === $reason ? 403 : 422 );
		}
		return new WP_REST_Response( array( 'ok' => true, 'user_id' => $uid, 'level' => '' === $level ? null : $level ), 200 );
	}

	/** @return array */
	public static function payload() {
		$reg = BizCity_Connector_Registry::instance();
		return array(
			'contract'   => BizCity_Connector_Registry::CONTRACT,
			'sections'   => BizCity_Connector_Registry::SECTIONS,
			'connectors' => array_values( $reg->all() ),
			'rows'       => array_values( $reg->module_access_rows() ),
			'errors'     => $reg->errors(),
		);
	}

	public static function list_connectors() {
		return new WP_REST_Response( self::payload(), 200 );
	}
}
