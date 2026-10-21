<?php
/**
 * Biz Central Brain — Johnny Chu (Chu Hoàng Anh). Bizcity Central Brain, Giấy chứng nhận đăng ký quyền tác giả số 8877/2026/QTG.
 */
// [2026-10-18 08:30 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.117 S117-MX / Q117-MX-1 (R-CONFIG-MATRIX) — new file: the ONE owner of "which executor is switched off for a role / a person".
/**
 * Executor policy — switches of the Connector matrix.
 *
 *   option `bizcity_executor_policy` = { roles: { owner: [executor id…], staff: [executor id…] }, people: { "<user_id>": [executor id…] } }
 *
 * These lists only SUBTRACT (permissions never add). The workspace builder folds role + person into each person's `off[]`
 * (user-workspace@1); the cell turns `off[]` into the tools of that person's reply turn (bizcityToolsOffFor). Customers are not here:
 * the executors a customer may use are decided by the cell's audience rule + the Guru, never by a switch on this page.
 *
 * REST (admin gate = the same as the Users page):
 *   GET  bizcity-channel/v1/admin/executor-policy
 *   POST bizcity-channel/v1/admin/executor-policy   { scope: "role"|"person", role?: "owner"|"staff", user_id?: int, off: string[] }  (replaces that list)
 *
 * Cache contract (R-CACHE): reads are one WordPress option (object cache); a write updates it and flushes group `cg_executor_policy`.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\ChannelGateway
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_Executor_Policy {

	const NS         = 'bizcity-channel/v1';
	const OPTION     = 'bizcity_executor_policy';
	const CACHE_GROUP = 'cg_executor_policy';
	const ROLES      = array( 'owner', 'staff' );
	const MAX_IDS    = 200;
	const MAX_PEOPLE = 500;

	private static $initialized = false;

	public static function init(): void {
		if ( self::$initialized ) {
			return;
		}
		self::$initialized = true;
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes(): void {
		$gate = array( 'BizCity_Admin_Users_Overview_REST', 'can_manage' ); // R-ADMIN-2: one admin gate
		register_rest_route( self::NS, '/admin/executor-policy', array(
			array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'route_get' ), 'permission_callback' => $gate ),
			array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'route_save' ), 'permission_callback' => $gate ),
		) );
	}

	/** @return array{roles:array<string,string[]>,people:array<string,string[]>} */
	public static function read(): array {
		$raw = get_option( self::OPTION, array() );
		$raw = is_array( $raw ) ? $raw : array();
		$out = array( 'roles' => array( 'owner' => array(), 'staff' => array() ), 'people' => array() );
		foreach ( self::ROLES as $r ) {
			$out['roles'][ $r ] = self::clean_ids( $raw['roles'][ $r ] ?? array() );
		}
		foreach ( (array) ( $raw['people'] ?? array() ) as $uid => $ids ) {
			$uid = (int) $uid;
			$ids = self::clean_ids( $ids );
			if ( $uid > 0 && $ids ) {
				$out['people'][ (string) $uid ] = $ids;
			}
		}
		return $out;
	}

	/** executor ids look like `gmail.get` / `sheets.append_row`; anything else is dropped, not guessed */
	public static function clean_ids( $ids ): array {
		$ids = is_array( $ids ) ? $ids : array();
		$out = array();
		foreach ( $ids as $id ) {
			$id = strtolower( trim( (string) $id ) );
			if ( strlen( $id ) <= 80 && preg_match( '/^[a-z][a-z0-9_]*(\.[a-z0-9_]+)+$/', $id ) ) {
				$out[ $id ] = true;
			}
		}
		return array_slice( array_keys( $out ), 0, self::MAX_IDS );
	}

	/** the role a person plays on this site, as the Connector matrix names it (owner = shop owner; everyone else who works = staff) */
	public static function role_of( $user_id ): string {
		$user_id = (int) $user_id;
		$shop    = class_exists( 'BizCity_Account_Owner' ) ? (int) BizCity_Account_Owner::shop_owner_id() : 0;
		return $user_id > 0 && $user_id === $shop ? 'owner' : 'staff';
	}

	/** the `off[]` of a person's workspace: role switches ∪ the person's own (only subtracts) */
	public static function off_for( $user_id ): array {
		$p = self::read();
		return self::clean_ids( array_merge( $p['roles'][ self::role_of( $user_id ) ], $p['people'][ (string) (int) $user_id ] ?? array() ) );
	}

	public static function route_get( WP_REST_Request $request ) {
		unset( $request );
		return new WP_REST_Response( array_merge( array( 'success' => true, 'contract' => 'executor-policy@1.0.0' ), self::read() ), 200 );
	}

	public static function route_save( WP_REST_Request $request ) {
		$body  = (array) $request->get_json_params();
		$scope = (string) ( $body['scope'] ?? '' );
		$ids   = self::clean_ids( $body['off'] ?? array() );
		$p     = self::read();
		$touch = array();
		if ( 'role' === $scope ) {
			$role = (string) ( $body['role'] ?? '' );
			if ( ! in_array( $role, self::ROLES, true ) ) {
				return self::err( 'invalid_role', 'Vai trò không hợp lệ.', 'Chọn "Chủ số" hoặc "Nhân viên & quản lý".', 'executor_policy_invalid', 400 );
			}
			$p['roles'][ $role ] = $ids;
			// every person of that role gets a fresh workspace (capped; the rest catch up at their next login)
			foreach ( get_users( array( 'fields' => 'ID', 'number' => 200 ) ) as $uid ) {
				if ( self::role_of( $uid ) === $role ) {
					$touch[] = (int) $uid;
				}
			}
		} elseif ( 'person' === $scope ) {
			$uid = (int) ( $body['user_id'] ?? 0 );
			if ( $uid <= 0 || ! get_userdata( $uid ) ) {
				return self::err( 'user_not_found', 'Không tìm thấy người dùng này trên website.', 'Tải lại danh sách rồi chọn lại.', 'admin_users_not_found', 404 );
			}
			if ( $ids ) {
				if ( count( $p['people'] ) >= self::MAX_PEOPLE && ! isset( $p['people'][ (string) $uid ] ) ) {
					return self::err( 'too_many_people', 'Đã có quá nhiều người được chỉnh riêng.', 'Bỏ chỉnh riêng cho người không còn cần rồi lưu lại.', 'executor_policy_invalid', 400 );
				}
				$p['people'][ (string) $uid ] = $ids;
			} else {
				unset( $p['people'][ (string) $uid ] );
			}
			$touch[] = $uid;
		} else {
			return self::err( 'invalid_scope', 'Thiếu phạm vi cần lưu.', 'Gửi scope là "role" hoặc "person".', 'executor_policy_invalid', 400 );
		}
		update_option( self::OPTION, $p, false );
		if ( class_exists( 'BizCity_Cache' ) ) {
			BizCity_Cache::flush_group( self::CACHE_GROUP );
		}
		$pushed = 0;
		if ( class_exists( 'BizCity_User_Workspace_Pusher' ) ) {
			foreach ( $touch as $uid ) {
				BizCity_User_Workspace_Pusher::on_user_forced( $uid );
				$pushed++;
			}
		}
		return new WP_REST_Response( array_merge( array( 'success' => true, 'pushed' => $pushed ), $p ), 200 );
	}

	private static function err( $code, $message, $hint, $help, $status ) {
		return new WP_REST_Response( array( 'success' => false, 'code' => $code, 'message' => $message, 'hint' => $hint, 'help_code' => $help ), $status );
	}
}

BizCity_Executor_Policy::init();
if ( class_exists( 'BizCity_Cache_Registry' ) ) {
	BizCity_Cache_Registry::register( BizCity_Executor_Policy::CACHE_GROUP, 'core.channel-gateway', array( 'policy' ) );
}
