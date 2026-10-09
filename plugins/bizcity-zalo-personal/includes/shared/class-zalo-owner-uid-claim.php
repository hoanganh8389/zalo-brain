<?php
/**
 * Learn the Zalo UID of the person in charge of a number by ONE message (PHASE-0.92 S92-SC-21, contract `owner-uid-claim@1.0.0`,
 * fixture zalo-hub/contracts/fixtures/taa/owner-uid-claim.json, doc core/channel-gateway/docs/PHASE-0.92-AUTOMATION-CELL-AXIS/71 §4.7).
 *
 *   GET    bizcity-channel/v1/zalo-personal/{bridge}/owner-uid        ⇒ who is in charge, UID known / verified, can I claim, my pending code
 *   POST   bizcity-channel/v1/zalo-personal/{bridge}/owner-uid/claim  ⇒ open a one-time code `BIZ ######` (10 min) for ME
 *   GET    …/owner-uid/claim                                          ⇒ poll (site → Hub → cell); `claimed` ⇒ policy written here
 *   DELETE …/owner-uid/claim                                          ⇒ cancel my code
 *
 * The person sends the code 1-1 from their personal Zalo to the number; the cell keeps the sender UID. A right code proves the person
 * holding that Zalo is the person logged in here, so the UID is stored as VERIFIED (same keys as uid-verify@1: owner ⇒
 * policy.owner_uid + owner_uid_verified; staff ⇒ staff_principals[].zalo_uid + verified_at).
 *
 * Owner rules: D92-SC-12 — a code opens only for the person in charge themself (owner_user_id of the number, or a staff member for
 * their own row). An admin never claims for somebody else; for that case the state carries `invite_url` (their own setup page).
 * D92-SC-11 — the cell's confirmation names only the website (`site_name`). D92-SC-13 — the UID lives in the number's binding, which
 * exists once step ④ picked an Agent Guru, so the claim comes right after ④. The code is kept only here (transient); the Hub and
 * the cell see its sha256. No LLM, no table.
 *
 * Biz Central Brain — Johnny Chu (Chu Hoàng Anh). Bizcity Central Brain, Giấy chứng nhận đăng ký quyền tác giả số 8877/2026/QTG.
 * // [2026-10-07 02:05 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.92-S92-SC-21 — new file.
 *
 * // @axis twin-agent-axis@1 block owner_agent
 *
 * @package BizCity_Zalo_Personal
 * @since   PHASE-0.92 (2026-10-07)
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Zalo_Owner_Uid_Claim', false ) ) {
	return;
}

final class BizCity_Zalo_Owner_Uid_Claim {

	const CONTRACT    = 'owner-uid-claim@1.0.0';
	const HUB_PATH    = '/zalo-hub/owner-claim';
	const NS          = 'bizcity-channel/v1';
	const TTL         = 600;
	const KEY_PREFIX  = 'bizcity_ocl_';
	const OWNER_KEY   = 'owner_uid_verified';

	const MESSAGES = array(
		'claim_binding_missing' => array( 'Số này chưa chọn Agent Guru.', 'Làm xong bước ④ (chọn Agent Guru) rồi nhận Zalo của bạn.' ),
		'claim_not_yours'       => array( 'Chỉ người phụ trách số mới nhận được Zalo của mình cho số này.', 'Gửi cho người phụ trách đường vào trang thiết lập của họ để họ tự nhắn mã.' ),
		'claim_already'         => array( 'Số này đã có Zalo của chủ đã xác minh.', 'Bấm "Đổi Zalo chủ" nếu bạn muốn nhận một Zalo khác.' ),
		'claim_none'            => array( 'Chưa có mã nào đang chờ.', 'Bấm "Lấy mã" để tạo mã mới.' ),
		'claim_uid_taken'       => array( 'Zalo vừa nhắn đã là của một người khác trên số này.', 'Nhắn mã từ đúng Zalo cá nhân của bạn.' ),
		'claim_unsupported'     => array( 'Máy chủ Zalo Hub chưa hỗ trợ nhận Zalo bằng mã.', 'Báo quản trị nền tảng cập nhật Hub và máy chủ Zalo Hub.' ),
		'claim_send_failed'     => array( 'Chưa tạo được mã.', 'Thử lại sau ít giây; nếu vẫn lỗi, xem trạng thái kết nối Zalo Hub.' ),
		'claim_save_failed'     => array( 'Đã nhận Zalo nhưng chưa lưu được.', 'Bấm Kiểm tra lại sau ít giây.' ),
		'rate_limited'          => array( 'Số này đã tạo nhiều mã trong 1 giờ qua.', 'Đợi ít phút rồi tạo mã mới.' ),
		'account_not_ready'     => array( 'Số Zalo đang không chạy.', 'Kết nối lại số rồi tạo mã mới.' ),
		'account_not_found'     => array( 'Hub không thấy số này là số Zalo Hub đang hoạt động của site.', 'Kiểm tra số đã kết nối qua Zalo Hub.' ),
		'claim_open'            => array( 'Số này đang có một mã khác chờ nhắn.', 'Đợi mã cũ hết hạn (10 phút) hoặc huỷ nó rồi tạo mã mới.' ),
	);

	/**
	 * Test seams: binding(bridge):?array{binding_id,bridge_id,policy} · save(binding_id,policy):bool · owner_user_id(bridge):int ·
	 * user_hash(user_id):string · current_user():int · can_manage():bool · send(body):array · now():int · code():string · claim_id():string ·
	 * site_name():string · invite_url():string
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
		$logged_in = static function () { return function_exists( 'is_user_logged_in' ) && is_user_logged_in(); };
		$base      = '/zalo-personal/(?P<bridge>[A-Za-z0-9_-]{1,64})/owner-uid';
		register_rest_route( self::NS, $base, array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'rest_state' ),
			'permission_callback' => $logged_in,
		) );
		register_rest_route( self::NS, $base . '/claim', array(
			array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'rest_open' ), 'permission_callback' => $logged_in ),
			array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'rest_poll' ), 'permission_callback' => $logged_in ),
			array( 'methods' => 'DELETE', 'callback' => array( __CLASS__, 'rest_cancel' ), 'permission_callback' => $logged_in ),
		) );
	}

	public static function rest_state( WP_REST_Request $req ): WP_REST_Response {
		return new WP_REST_Response( self::state( (string) $req->get_param( 'bridge' ), self::current_user() ), 200 );
	}

	public static function rest_open( WP_REST_Request $req ): WP_REST_Response {
		$body = $req->get_json_params();
		$body = is_array( $body ) ? $body : array();
		return self::open( (string) $req->get_param( 'bridge' ), self::current_user(), ! empty( $body['replace'] ) );
	}

	public static function rest_poll( WP_REST_Request $req ): WP_REST_Response {
		return self::poll( (string) $req->get_param( 'bridge' ), self::current_user() );
	}

	public static function rest_cancel( WP_REST_Request $req ): WP_REST_Response {
		return self::cancel( (string) $req->get_param( 'bridge' ), self::current_user() );
	}

	/* ── state ────────────────────────────────────────────────────── */

	/**
	 * What the setup screen needs for one number. Never returns another person's code or a full UID.
	 */
	public static function state( string $bridge, int $user_id ): array {
		$b = self::binding( $bridge );
		$owner_user = self::owner_user_id( $bridge );
		$out = array(
			'ok'          => true,
			'bridge'      => $bridge,
			'binding'     => null !== $b,
			'role'        => null,
			'uid_set'     => false,
			'verified'    => false,
			'uid_masked'  => '',
			'can_claim'   => false,
			'reason'      => '',
			'invite_url'  => '',
			'pending'     => null,
		);
		if ( null === $b ) {
			$out['reason'] = 'claim_binding_missing';
			return $out;
		}
		$policy = $b['policy'];
		$who    = self::role_of( $b, $owner_user, $user_id );
		$out['role'] = $who['role'];
		if ( 'staff' === $who['role'] ) {
			$out['uid_set']    = true; // a staff row always carries a UID
			$out['verified']   = ! empty( $who['row']['verified_at'] );
			$out['uid_masked'] = self::mask( (string) $who['row']['zalo_uid'] );
		} else {
			$uid = self::clean_uid( (string) ( $policy['owner_uid'] ?? '' ) );
			$out['uid_set']    = '' !== $uid;
			$out['verified']   = null !== self::owner_verified_at( $policy );
			$out['uid_masked'] = self::mask( $uid );
		}
		if ( null === $who['role'] ) {
			$out['reason'] = 'claim_not_yours';
			// D92-SC-12: an admin sends the person in charge the way in; they claim it themself.
			if ( self::can_manage() && $owner_user > 0 && $owner_user !== $user_id && ! $out['verified'] ) {
				$out['invite_url'] = self::invite_url();
			}
			return $out;
		}
		$out['can_claim'] = ! $out['verified'];
		$out['reason']    = $out['verified'] ? 'claim_already' : '';
		$p = self::pending( $bridge, $user_id );
		if ( is_array( $p ) ) {
			$out['pending'] = array( 'code' => self::display_code( (string) $p['code'] ), 'expires_at' => gmdate( 'Y-m-d\TH:i:s\Z', (int) $p['exp'] ) );
		}
		return $out;
	}

	/* ── open ─────────────────────────────────────────────────────── */

	public static function open( string $bridge, int $user_id, bool $replace = false ): WP_REST_Response {
		$b = self::binding( $bridge );
		if ( null === $b ) {
			return self::error( 'claim_binding_missing', 409 );
		}
		$who = self::role_of( $b, self::owner_user_id( $bridge ), $user_id );
		if ( null === $who['role'] ) {
			return self::error( 'claim_not_yours', 403 );
		}
		$verified = 'staff' === $who['role'] ? ! empty( $who['row']['verified_at'] ) : null !== self::owner_verified_at( $b['policy'] );
		if ( $verified && ! $replace ) {
			return self::error( 'claim_already', 409 );
		}
		$old = self::pending( $bridge, $user_id );
		if ( is_array( $old ) ) {
			self::send( array( 'contract' => self::CONTRACT, 'op' => 'cancel', 'account_id' => $bridge, 'claim_id' => (string) $old['claim_id'] ) );
			self::forget( $bridge, $user_id );
		}

		$code     = self::new_code();
		$claim_id = self::new_claim_id();
		$exp      = self::now() + self::TTL;
		$body     = array(
			'contract'    => self::CONTRACT,
			'op'          => 'open',
			'account_id'  => $bridge,
			'claim_id'    => $claim_id,
			'role'        => $who['role'],
			'code_sha256' => hash( 'sha256', $code ),
			'site_name'   => self::site_name(),
			'expires_at'  => gmdate( 'Y-m-d\TH:i:s\Z', $exp ),
		);
		if ( 'staff' === $who['role'] ) {
			$body['staff_user_hash'] = self::user_hash( $user_id );
		}
		$res = self::send( $body );
		if ( ! self::ok( $res ) ) {
			return self::error( self::failure_code( $res, 'claim_send_failed' ), 502 );
		}
		self::remember( $bridge, $user_id, array( 'claim_id' => $claim_id, 'code' => $code, 'role' => $who['role'], 'exp' => $exp, 'binding_id' => (int) $b['binding_id'] ) );
		return new WP_REST_Response( array( 'ok' => true, 'code' => self::display_code( $code ), 'expires_at' => $body['expires_at'], 'number' => $bridge ), 200 );
	}

	/* ── poll ─────────────────────────────────────────────────────── */

	public static function poll( string $bridge, int $user_id ): WP_REST_Response {
		$p = self::pending( $bridge, $user_id );
		if ( ! is_array( $p ) ) {
			return new WP_REST_Response( array( 'ok' => true, 'status' => 'none' ), 200 );
		}
		$res = self::send( array( 'contract' => self::CONTRACT, 'op' => 'status', 'account_id' => $bridge, 'claim_id' => (string) $p['claim_id'] ) );
		if ( ! self::ok( $res ) ) {
			return self::error( self::failure_code( $res, 'claim_send_failed' ), 502 );
		}
		$status = sanitize_key( (string) ( $res['status'] ?? 'unknown' ) );
		if ( 'pending' === $status ) {
			return new WP_REST_Response( array( 'ok' => true, 'status' => 'pending', 'expires_at' => gmdate( 'Y-m-d\TH:i:s\Z', (int) $p['exp'] ) ), 200 );
		}
		if ( 'claimed' !== $status ) {
			self::forget( $bridge, $user_id );
			return new WP_REST_Response( array( 'ok' => true, 'status' => in_array( $status, array( 'expired', 'burned', 'cancelled' ), true ) ? $status : 'expired' ), 200 );
		}
		$uid = self::clean_uid( (string) ( $res['uid'] ?? '' ) );
		if ( '' === $uid ) {
			self::forget( $bridge, $user_id );
			return self::error( 'claim_send_failed', 502 );
		}
		$saved = self::apply( (int) $p['binding_id'], $bridge, (string) $p['role'], $user_id, $uid );
		if ( true !== $saved ) {
			if ( 'claim_uid_taken' === $saved ) {
				self::forget( $bridge, $user_id );
			}
			return self::error( (string) $saved, 'claim_uid_taken' === $saved ? 409 : 500 );
		}
		self::forget( $bridge, $user_id );
		return new WP_REST_Response( array( 'ok' => true, 'status' => 'claimed', 'uid_masked' => self::mask( $uid ) ), 200 );
	}

	/* ── cancel ───────────────────────────────────────────────────── */

	public static function cancel( string $bridge, int $user_id ): WP_REST_Response {
		$p = self::pending( $bridge, $user_id );
		if ( ! is_array( $p ) ) {
			return self::error( 'claim_none', 404 );
		}
		self::send( array( 'contract' => self::CONTRACT, 'op' => 'cancel', 'account_id' => $bridge, 'claim_id' => (string) $p['claim_id'] ) );
		self::forget( $bridge, $user_id );
		return new WP_REST_Response( array( 'ok' => true, 'status' => 'cancelled' ), 200 );
	}

	/**
	 * Write the claimed UID on the number's policy (decode → merge → encode through the Bot Studio owner of the policy).
	 *
	 * @return true|string true or an error code
	 */
	public static function apply( int $binding_id, string $bridge, string $role, int $user_id, string $uid ) {
		$b = self::binding( $bridge );
		if ( null === $b || (int) $b['binding_id'] !== $binding_id ) {
			return 'claim_binding_missing';
		}
		$policy = $b['policy'];
		$staff  = self::staff( $policy );
		$at     = gmdate( 'Y-m-d\TH:i:s\Z', self::now() );
		if ( 'owner' === $role ) {
			if ( self::owner_user_id( $bridge ) !== $user_id ) {
				return 'claim_not_yours'; // the person in charge changed while the code was open
			}
			foreach ( $staff as $row ) {
				if ( hash_equals( (string) $row['zalo_uid'], $uid ) ) {
					return 'claim_uid_taken';
				}
			}
			$policy['owner_uid']     = $uid;
			$policy[ self::OWNER_KEY ] = array( 'uid' => $uid, 'at' => $at );
		} else {
			$owner_uid = self::clean_uid( (string) ( $policy['owner_uid'] ?? '' ) );
			if ( '' !== $owner_uid && hash_equals( $owner_uid, $uid ) ) {
				return 'claim_uid_taken';
			}
			$found = false;
			foreach ( $staff as $i => $row ) {
				if ( (int) $row['user_id'] === $user_id ) {
					$staff[ $i ]['zalo_uid']    = $uid;
					$staff[ $i ]['verified_at'] = $at;
					$found = true;
				} elseif ( hash_equals( (string) $row['zalo_uid'], $uid ) ) {
					return 'claim_uid_taken';
				}
			}
			if ( ! $found ) {
				return 'claim_not_yours';
			}
			$policy['staff_principals'] = $staff;
		}
		if ( ! self::save( $binding_id, $policy ) ) {
			return 'claim_save_failed';
		}
		if ( function_exists( 'do_action' ) ) {
			do_action( 'bizcity_bot_config_changed', 'binding', $binding_id ); // the next bundle carries the principal (≤ 60 s)
		}
		return true;
	}

	/* ── helpers ──────────────────────────────────────────────────── */

	/**
	 * owner = the WP user in charge of the number; staff = a staff row of this user; null = neither (admins included, D92-SC-12).
	 *
	 * @return array{role:?string,row:?array}
	 */
	private static function role_of( array $b, int $owner_user, int $user_id ): array {
		if ( $user_id <= 0 ) {
			return array( 'role' => null, 'row' => null );
		}
		if ( $owner_user > 0 && $owner_user === $user_id ) {
			return array( 'role' => 'owner', 'row' => null );
		}
		foreach ( self::staff( $b['policy'] ) as $row ) {
			if ( (int) $row['user_id'] === $user_id ) {
				return array( 'role' => 'staff', 'row' => $row );
			}
		}
		return array( 'role' => null, 'row' => null );
	}

	/** The EXACT binding of this number (never the '*' wildcard: a UID is per number). */
	private static function binding( string $bridge ): ?array {
		if ( isset( self::$readers['binding'] ) ) {
			$r = call_user_func( self::$readers['binding'], $bridge );
			return is_array( $r ) ? $r : null;
		}
		if ( '' === $bridge || ! class_exists( 'BizCity_Channel_Binding' ) ) {
			return null;
		}
		$row = BizCity_Channel_Binding::resolve( 'ZALO_PERSONAL', $bridge );
		if ( ! is_array( $row ) || (string) ( $row['account_id'] ?? '' ) !== $bridge ) {
			return null;
		}
		$raw    = $row['policy_json'] ?? '';
		$policy = is_array( $raw ) ? $raw : json_decode( (string) $raw, true );
		return array( 'binding_id' => (int) $row['id'], 'bridge_id' => $bridge, 'policy' => is_array( $policy ) ? $policy : array() );
	}

	private static function save( int $binding_id, array $policy ): bool {
		if ( class_exists( 'BizCity_Bot_REST' ) && method_exists( 'BizCity_Bot_REST', 'policy_defaults_merged' ) ) {
			$policy = BizCity_Bot_REST::policy_defaults_merged( $policy ); // keeps owner_uid_verified + staff_principals
		}
		if ( isset( self::$readers['save'] ) ) {
			return (bool) call_user_func( self::$readers['save'], $binding_id, $policy );
		}
		return class_exists( 'BizCity_Channel_Binding' ) && BizCity_Channel_Binding::save_policy( $binding_id, $policy );
	}

	private static function owner_verified_at( array $policy ): ?string {
		if ( class_exists( 'BizCity_Zalo_Uid_Verify' ) && method_exists( 'BizCity_Zalo_Uid_Verify', 'owner_verified_at' ) ) {
			return BizCity_Zalo_Uid_Verify::owner_verified_at( $policy );
		}
		$v   = $policy[ self::OWNER_KEY ] ?? null;
		$uid = self::clean_uid( (string) ( $policy['owner_uid'] ?? '' ) );
		return is_array( $v ) && '' !== $uid && (string) ( $v['uid'] ?? '' ) === $uid && '' !== (string) ( $v['at'] ?? '' ) ? (string) $v['at'] : null;
	}

	private static function staff( array $policy ): array {
		return class_exists( 'BizCity_Zalo_Agent_Principals' ) ? BizCity_Zalo_Agent_Principals::from_policy( $policy ) : array();
	}

	private static function pending( string $bridge, int $user_id ) {
		$p = function_exists( 'get_transient' ) ? get_transient( self::key( $bridge, $user_id ) ) : false;
		if ( ! is_array( $p ) || (int) ( $p['exp'] ?? 0 ) < self::now() ) {
			return null;
		}
		return $p;
	}

	private static function remember( string $bridge, int $user_id, array $row ): void {
		if ( function_exists( 'set_transient' ) ) {
			set_transient( self::key( $bridge, $user_id ), $row, self::TTL );
		}
	}

	private static function forget( string $bridge, int $user_id ): void {
		if ( function_exists( 'delete_transient' ) ) {
			delete_transient( self::key( $bridge, $user_id ) );
		}
	}

	private static function key( string $bridge, int $user_id ): string {
		return self::KEY_PREFIX . md5( $bridge . '|' . $user_id );
	}

	private static function send( array $body ): array {
		if ( isset( self::$readers['send'] ) ) {
			return (array) call_user_func( self::$readers['send'], $body );
		}
		if ( ! class_exists( 'BizCity_Zalo_Personal_Hub_Client' ) ) {
			return array( 'success' => false, 'code' => 'claim_send_failed' );
		}
		return BizCity_Zalo_Personal_Hub_Client::instance()->post_managed_path( self::HUB_PATH, $body );
	}

	private static function ok( array $res ): bool {
		return ! empty( $res['ok'] ) && empty( $res['_degraded'] );
	}

	private static function failure_code( array $res, string $fallback ): string {
		$code = (string) ( $res['code'] ?? $res['data']['code'] ?? '' );
		$http = (int) ( $res['http_code'] ?? 0 );
		if ( 404 === $http && in_array( $code, array( 'rest_no_route', 'cell_route_missing', '' ), true ) ) {
			return 'claim_unsupported';
		}
		return isset( self::MESSAGES[ $code ] ) ? $code : $fallback;
	}

	private static function new_code(): string {
		if ( isset( self::$readers['code'] ) ) {
			return (string) call_user_func( self::$readers['code'] );
		}
		return 'BIZ' . str_pad( (string) random_int( 0, 999999 ), 6, '0', STR_PAD_LEFT );
	}

	private static function new_claim_id(): string {
		return isset( self::$readers['claim_id'] ) ? (string) call_user_func( self::$readers['claim_id'] ) : 'oc_' . bin2hex( random_bytes( 8 ) );
	}

	/** "BIZ482915" ⇒ "BIZ 482915" (what the person types; the cell ignores spaces). */
	private static function display_code( string $code ): string {
		return substr( $code, 0, 3 ) . ' ' . substr( $code, 3 );
	}

	private static function site_name(): string {
		$name = isset( self::$readers['site_name'] ) ? (string) call_user_func( self::$readers['site_name'] ) : ( function_exists( 'get_bloginfo' ) ? wp_strip_all_tags( (string) get_bloginfo( 'name' ) ) : '' );
		$name = trim( preg_replace( '/\s+/u', ' ', $name ) );
		if ( '' === $name && function_exists( 'home_url' ) ) {
			$name = (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		}
		return function_exists( 'mb_substr' ) ? mb_substr( $name, 0, 80 ) : substr( $name, 0, 80 );
	}

	/** The member's own four-step host (Twin GPT home, R-S4-4) — where the person in charge claims for themself. */
	private static function invite_url(): string {
		if ( isset( self::$readers['invite_url'] ) ) {
			return (string) call_user_func( self::$readers['invite_url'] );
		}
		return function_exists( 'home_url' ) ? (string) home_url( '/gpt/' ) : '';
	}

	private static function owner_user_id( string $bridge ): int {
		if ( isset( self::$readers['owner_user_id'] ) ) {
			return (int) call_user_func( self::$readers['owner_user_id'], $bridge );
		}
		return class_exists( 'BizCity_Zalo_Agent_Principals' ) ? BizCity_Zalo_Agent_Principals::owner_user_id( $bridge ) : 0;
	}

	private static function user_hash( int $user_id ): string {
		if ( isset( self::$readers['user_hash'] ) ) {
			return (string) call_user_func( self::$readers['user_hash'], $user_id );
		}
		return class_exists( 'BizCity_Zalo_Agent_Principals' ) ? BizCity_Zalo_Agent_Principals::user_hash( $user_id ) : '';
	}

	private static function can_manage(): bool {
		if ( isset( self::$readers['can_manage'] ) ) {
			return (bool) call_user_func( self::$readers['can_manage'] );
		}
		return class_exists( 'BizCity_Zalo_Staff_Principals_REST' ) && BizCity_Zalo_Staff_Principals_REST::can_manage();
	}

	private static function current_user(): int {
		return isset( self::$readers['current_user'] ) ? (int) call_user_func( self::$readers['current_user'] ) : ( function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0 );
	}

	private static function now(): int {
		return isset( self::$readers['now'] ) ? (int) call_user_func( self::$readers['now'] ) : time();
	}

	private static function clean_uid( string $uid ): string {
		$uid = trim( $uid );
		return preg_match( '/^\d{5,32}$/', $uid ) ? $uid : '';
	}

	private static function mask( string $uid ): string {
		return '' === $uid ? '' : '…' . substr( $uid, -4 );
	}

	private static function error( string $code, int $status ): WP_REST_Response {
		$m = self::MESSAGES[ $code ] ?? self::MESSAGES['claim_send_failed'];
		return new WP_REST_Response( array( 'ok' => false, 'code' => $code, 'message' => $m[0], 'hint' => $m[1], 'help_code' => 'S92-OC-' . $status ), $status );
	}
}
