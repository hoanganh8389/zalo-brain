<?php
/**
 * "Kết nối" — Facebook · Google · Zalo OA in the four steps of R-SETUP-4 (PHASE-0.93, doc 50 §2).
 *
 * bizcity-channel/v1 (admin = BizCity_Network_Admin_Capability::can_manage, except overview/relay/callback):
 *   GET    connect/overview                    tiles of the "Kết nối" page + Automation (any editor reads it, can_manage tells the UI)
 *   GET    connect/{p}/setup[?force=1]         connect-setup@1: 4 steps × 5 states, step ② mode + shared/own status, assets, small wins
 *   POST   connect/{p}/mode     {mode}         shared | own (switching ⇒ step ③ must log in again: the old token belongs to the old app)
 *   GET    connect/{p}/own-app                 copy rows (Redirect URI · Webhook URL · Verify Token) + saved state, never the secret
 *   POST   connect/{p}/own-app  {app_id, app_secret?}  save + real provider check ("Kiểm tra app")
 *   POST   connect/{p}/start    {scopes[], origin}     ⇒ {authorize_url, popup_origin}: Hub broker (shared) or the site itself (own)
 *   GET    connect/{p}/callback                own-app provider callback (public, state-checked) ⇒ popup page
 *   POST   connect/{p}/exchange {exchange}     one-time code from the popup ⇒ tokens saved on the site; FB returns the page list
 *   POST   connect/{p}/assets   {asset_ids[], owner_user_id, default_page_id?}   choose pages / owner (FB registers + subscribes)
 *   DELETE connect/{p}/assets   {asset_ids[]}  disconnect (provider revoke + Hub unregister)
 *   POST   connect/{p}/guru     {asset_id?, character_id, auto_reply}
 *   POST   connect/{p}/test/{kind}             small win: message_link · draft_post · email_self · event_15m · oa_message
 *   POST   connect/facebook/relay              shared-app webhook relayed by the Hub, signed hmac_sha256(body, sha256(site key))
 *   POST   connect/zalo_oa/relay               same for the shared Zalo app (D93-6) ⇒ handed to webhook/zalo_oa/{uid}
 * Facebook steps ③/④ reuse BizCity_FB_Setup_REST (S90-F1) so "Đạt" stays a server fact: Hub registration + a webhook that
 * really reached this site. Error codes are the table of doc 50 §3 (same strings in frontend/src/routes/connect/errors.js).
 *
 * [2026-10-06 10:20 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.93 S93-M1…M8, S93-C2…C7 — new file.
 *
 * @package BizCity_Twin_AI
 * @subpackage Channel_Gateway
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Connect_REST', false ) ) {
	return;
}

final class BizCity_Connect_REST {

	const NS          = 'bizcity-channel/v1';
	const CONTRACT    = 'connect-setup@1';
	const PROVIDERS   = array( 'facebook', 'google', 'zalo_oa' );
	const OPT_MODES   = 'bizcity_connect_modes';
	const OPT_OWNERS  = 'bizcity_connect_owners';
	const OPT_GURU    = 'bizcity_connect_google_guru';
	const PENDING_TTL = 1800;
	const TEST_KINDS  = array(
		'facebook' => array( 'message_link', 'draft_post' ),
		'google'   => array( 'email_self', 'event_15m' ),
		'zalo_oa'  => array( 'oa_message' ),
	);

	/** @var array<string,callable> Tests: can_manage(), api_key(), fb_handler(page, kind, item), graph(method, path, params, token). */
	public static $seams = array();

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes(): void {
		$admin = array( __CLASS__, 'perm_admin' );
		$p     = '(?P<provider>facebook|google|zalo_oa)';
		register_rest_route( self::NS, '/connect/overview', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'get_overview' ), 'permission_callback' => array( __CLASS__, 'perm_read' ) ) );
		register_rest_route( self::NS, '/connect/' . $p . '/setup', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'get_setup' ), 'permission_callback' => $admin ) );
		register_rest_route( self::NS, '/connect/' . $p . '/mode', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'post_mode' ), 'permission_callback' => $admin ) );
		register_rest_route( self::NS, '/connect/' . $p . '/own-app', array(
			array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'get_own_app' ), 'permission_callback' => $admin ),
			array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'post_own_app' ), 'permission_callback' => $admin ),
		) );
		register_rest_route( self::NS, '/connect/' . $p . '/start', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'post_start' ), 'permission_callback' => $admin ) );
		register_rest_route( self::NS, '/connect/' . $p . '/callback', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'get_callback' ), 'permission_callback' => '__return_true' ) );
		register_rest_route( self::NS, '/connect/' . $p . '/exchange', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'post_exchange' ), 'permission_callback' => $admin ) );
		register_rest_route( self::NS, '/connect/' . $p . '/assets', array(
			array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'post_assets' ), 'permission_callback' => $admin ),
			array( 'methods' => 'DELETE', 'callback' => array( __CLASS__, 'delete_assets' ), 'permission_callback' => $admin ),
		) );
		register_rest_route( self::NS, '/connect/' . $p . '/guru', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'post_guru' ), 'permission_callback' => $admin ) );
		register_rest_route( self::NS, '/connect/' . $p . '/test/(?P<kind>[a-z_0-9]+)', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'post_test' ), 'permission_callback' => $admin ) );
		register_rest_route( self::NS, '/connect/facebook/relay', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'post_fb_relay' ), 'permission_callback' => '__return_true' ) );
		register_rest_route( self::NS, '/connect/zalo_oa/relay', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'post_zalo_relay' ), 'permission_callback' => '__return_true' ) ); // D93-6
	}

	/* ================================================================
	 *  permissions + errors
	 * ================================================================ */

	public static function can_manage(): bool {
		if ( isset( self::$seams['can_manage'] ) ) {
			return (bool) call_user_func( self::$seams['can_manage'] );
		}
		return class_exists( 'BizCity_Network_Admin_Capability' ) ? BizCity_Network_Admin_Capability::can_manage() : current_user_can( 'manage_options' );
	}

	public static function perm_admin() {
		return self::can_manage() ? true : self::err( 'not_admin', 'Chỉ quản trị viên website kết nối kênh.', 'Nhờ chủ cửa hàng mở trang này.', 403 );
	}

	public static function perm_read(): bool {
		return is_user_logged_in() && ( self::can_manage() || current_user_can( 'edit_posts' ) );
	}

	/** R-ERROR-UX + the shared code of doc 50 §3 (the UI picks the sentence + button from `code`). */
	public static function err( string $code, string $message, string $hint = '', int $status = 400 ): WP_Error {
		return new WP_Error( $code, $message, array( 'status' => $status, 'hint' => $hint, 'help_code' => 'S93-' . strtoupper( str_replace( '_', '-', $code ) ) ) );
	}

	private static function provider( $request ): string {
		$p = (string) $request->get_param( 'provider' );
		return in_array( $p, self::PROVIDERS, true ) ? $p : 'facebook';
	}

	/* ================================================================
	 *  mode
	 * ================================================================ */

	/** shared | own. Without an explicit choice: a site that already runs on its own app keeps it, everyone else gets ⚡. */
	public static function mode( string $provider ): string {
		$m = get_option( self::OPT_MODES, array() );
		if ( is_array( $m ) && in_array( $m[ $provider ] ?? '', array( 'shared', 'own' ), true ) ) {
			return (string) $m[ $provider ];
		}
		return BizCity_Connect_Own_App::view( $provider )['saved'] && self::assets( $provider ) ? 'own' : 'shared';
	}

	public static function post_mode( $request ) {
		$provider = self::provider( $request );
		$mode     = (string) $request->get_param( 'mode' );
		if ( ! in_array( $mode, array( 'shared', 'own' ), true ) ) {
			return self::err( 'invalid_param', 'Cách kết nối không hợp lệ.', 'Chọn shared hoặc own.' );
		}
		$m              = get_option( self::OPT_MODES, array() );
		$m              = is_array( $m ) ? $m : array();
		$m[ $provider ] = $mode;
		update_option( self::OPT_MODES, $m, false );
		return rest_ensure_response( array( 'ok' => true, 'setup' => self::build( $provider ) ) );
	}

	/* ================================================================
	 *  assets per provider (what step ③ shows as connected)
	 * ================================================================ */

	/** @return list<array{id:string,name:string,mode:string,owner_user_id:int,guru_id:int,registered?:bool}> */
	public static function assets( string $provider ): array {
		$owners = get_option( self::OPT_OWNERS, array() );
		$owners = is_array( $owners[ $provider ] ?? null ) ? $owners[ $provider ] : array();
		if ( 'facebook' === $provider ) {
			if ( ! class_exists( 'BizCity_FB_Setup_REST' ) ) {
				return array();
			}
			$out = array();
			foreach ( (array) ( self::fb_setup()['pages'] ?? array() ) as $pg ) {
				if ( empty( $pg['selected'] ) ) {
					continue;
				}
				$out[] = array( 'id' => (string) $pg['page_id'], 'name' => (string) $pg['name'], 'mode' => '', 'owner_user_id' => (int) $pg['owner_user_id'], 'guru_id' => (int) $pg['guru_id'], 'registered' => ! empty( $pg['registered']['messenger'] ) && ! empty( $pg['registered']['fb'] ) );
			}
			return $out;
		}
		if ( 'google' === $provider ) {
			global $wpdb;
			if ( ! class_exists( 'BZGoogle_Installer' ) ) {
				return array();
			}
			$guru = get_option( self::OPT_GURU, array() );
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, user_id, google_email, scope, connection_mode FROM ' . BZGoogle_Installer::table_accounts() . " WHERE blog_id = %d AND status = 'active' ORDER BY updated_at DESC LIMIT 20", get_current_blog_id() ), ARRAY_A );
			$out  = array();
			foreach ( (array) $rows as $r ) {
				$email = (string) $r['google_email'];
				$out[] = array( 'id' => $email, 'name' => $email, 'mode' => 'hub_broker' === $r['connection_mode'] ? 'shared' : ( 'byo_app' === $r['connection_mode'] ? 'own' : 'shared' ), 'owner_user_id' => (int) $r['user_id'], 'guru_id' => (int) ( $guru[ $email ] ?? 0 ), 'scopes' => self::google_keys( (string) $r['scope'] ) );
			}
			return $out;
		}
		if ( ! class_exists( 'BizCity_Integration_Registry' ) ) {
			return array();
		}
		$out = array();
		foreach ( (array) BizCity_Integration_Registry::instance()->get_accounts( 'zalo_oa', true ) as $a ) {
			// audit R-9: an OA the Hub manages the OLD way (connection_mode managed_1api, tokens only at the Hub) is connected too — show it,
			// as 'managed' (read-only here: it is disconnected where it was connected, and it is answered by the old bridge path).
			$managed = 'managed_1api' === (string) ( $a['connection_mode'] ?? '' ) && 'active' === (string) ( $a['managed_status'] ?? '' );
			$oa      = (string) ( $managed ? ( $a['managed_oa_id'] ?? '' ) : ( $a['oa_id'] ?? '' ) );
			if ( '' === $oa || ( ! $managed && '' === (string) ( $a['access_token'] ?? '' ) ) ) {
				continue;
			}
			$b     = class_exists( 'BizCity_Channel_Binding' ) ? BizCity_Channel_Binding::resolve( 'ZALO_OA', $oa ) : null;
			$name  = (string) ( $managed ? ( $a['managed_oa_name'] ?? $oa ) : ( $a['oa_name'] ?? $oa ) );
			$mode  = $managed ? 'managed' : ( 'hub_broker' === ( $a['connection_mode'] ?? '' ) ? 'shared' : 'own' );
			$out[] = array( 'id' => $oa, 'name' => $name, 'uid' => (string) ( $a['_uid'] ?? '' ), 'mode' => $mode, 'owner_user_id' => (int) ( $owners[ $oa ] ?? 0 ), 'guru_id' => (int) ( $b['character_id'] ?? 0 ), 'auto_reply' => (int) ( $b['auto_reply'] ?? 0 ) );
		}
		return $out;
	}

	private static function google_keys( string $scope ): array {
		$keys = array();
		foreach ( BizCity_Connect_Own_App::GOOGLE_SCOPES as $k => $uri ) {
			if ( false !== strpos( ' ' . $scope . ' ', ' ' . $uri . ' ' ) ) {
				$keys[] = $k;
			}
		}
		return $keys;
	}

	private static function fb_setup(): array {
		return class_exists( 'BizCity_FB_Setup_REST' ) ? BizCity_FB_Setup_REST::build() : array();
	}

	/* ================================================================
	 *  overview + setup
	 * ================================================================ */

	public static function api_key(): string {
		if ( isset( self::$seams['api_key'] ) ) {
			return (string) call_user_func( self::$seams['api_key'] );
		}
		return class_exists( 'BizCity_LLM_Client' ) ? (string) BizCity_LLM_Client::instance()->get_api_key( false ) : '';
	}

	public static function get_overview( $request = null ) {
		$key      = self::api_key();
		$channels = array();
		$labels   = array( 'facebook' => 'Facebook', 'google' => 'Google', 'zalo_oa' => 'Zalo OA' );
		foreach ( self::PROVIDERS as $p ) {
			$assets     = self::assets( $p );
			$channels[] = array(
				'provider' => $p,
				'label'    => $labels[ $p ],
				'status'   => $assets ? 'running' : 'none',
				'count'    => count( $assets ),
				// audit R-4: the list carries page / OA ids and Google e-mails — only for people who may manage the site (an editor sees status + count)
				'assets'   => self::can_manage() ? array_map( static function ( $a ) { return array( 'id' => $a['id'], 'name' => $a['name'] ); }, $assets ) : array(),
				'mode'     => self::mode( $p ),
			);
		}
		return rest_ensure_response( array(
			'contract'   => 'connect-overview@1',
			'can_manage' => self::can_manage(),
			'key'        => array( 'ok' => '' !== $key, 'preview' => '' !== $key ? '…' . substr( $key, -4 ) : '' ),
			'channels'   => $channels,
		) );
	}

	public static function get_setup( $request ) {
		$provider = self::provider( $request );
		if ( $request->get_param( 'force' ) ) {
			delete_transient( 'bizcity_connect_broker_status_' . $provider );
		}
		return rest_ensure_response( self::build( $provider ) );
	}

	private static function step( int $n, string $id, string $state, string $code = '', string $message = '', array $extra = array() ): array {
		return array( 'n' => $n, 'id' => $id, 'state' => $state, 'code' => $code, 'message' => $message ) + $extra;
	}

	public static function build( string $provider ): array {
		$key  = self::api_key();
		$mode = self::mode( $provider );
		$own  = BizCity_Connect_Own_App::view( $provider );
		if ( 'own' === $mode && $own['saved'] && 0 === $own['checked_at'] && '' !== $key ) {
			BizCity_Connect_Own_App::check( $provider ); // a site already running on its own app gets its first real check once, not a false "Chưa đạt"
			$own = BizCity_Connect_Own_App::view( $provider );
		}
		unset( $own['copy'] );

		// ① 1API key
		$s1 = '' !== $key ? self::step( 1, 'account', 'ok', '', 'Khoá 1API đã kiểm.', array( 'preview' => '…' . substr( $key, -4 ) ) ) : self::step( 1, 'account', 'fail', 'key_missing', 'Chưa có khoá 1API của website.' );

		// ② connection mode
		$shared = array( 'ok' => false, 'code' => '', 'review_pending' => false, 'plan_allows' => true, 'tier' => '', 'scopes_locked' => array() );
		if ( '' !== $key && class_exists( 'BizCity_OAuth_Broker_Client' ) ) {
			$st = BizCity_OAuth_Broker_Client::status( $provider );
			if ( $st['ok'] ) {
				$d      = $st['data'];
				$shared = array( 'ok' => ! empty( $d['ok'] ), 'code' => (string) ( $d['code'] ?? '' ), 'review_pending' => ! empty( $d['review_pending'] ), 'plan_allows' => ! empty( $d['plan_allows'] ), 'tier' => (string) ( $d['tier'] ?? '' ), 'scopes_locked' => (array) ( $d['scopes_locked'] ?? array() ) );
			} else {
				$shared['code'] = (string) $st['error']['code'];
			}
		}
		$s2extra = array( 'mode' => $mode, 'shared' => $shared, 'own' => $own );
		if ( 'ok' !== $s1['state'] ) {
			$s2 = self::step( 2, 'mode', 'locked', '', '', $s2extra );
		} elseif ( 'shared' === $mode ) {
			$s2 = $shared['ok'] ? self::step( 2, 'mode', 'ok', '', '', $s2extra ) : self::step( 2, 'mode', 'fail', '' !== $shared['code'] ? $shared['code'] : 'shared_not_configured', '', $s2extra );
		} else {
			$s2 = $own['verified'] ? self::step( 2, 'mode', 'ok', '', '', $s2extra ) : self::step( 2, 'mode', $own['saved'] ? 'fail' : 'active', $own['saved'] ? ( '' !== $own['code'] ? $own['code'] : 'own_app_unchecked' ) : '', '', $s2extra );
		}

		// ③ login + choose
		$assets  = self::assets( $provider );
		$pending = self::pending( $provider );
		if ( 'ok' !== $s2['state'] ) {
			$s3 = self::step( 3, 'assets', 'locked' );
		} elseif ( 'facebook' === $provider ) {
			$s3 = self::fb_step3( $assets, $pending );
		} elseif ( $assets ) {
			// D93-6: an OA on the shared app that the Hub did not register would never receive a message — not "Đạt"
			$unreg = 'zalo_oa' === $provider ? array_values( array_filter( array_map( static function ( $a ) { return 'shared' === $a['mode'] ? self::registration( 'zalo_oa', $a['id'] ) : ''; }, $assets ) ) ) : array();
			$s3    = $unreg ? self::step( 3, 'assets', 'fail', $unreg[0], 'Máy chủ BizCity chưa nhận OA này nên tin nhắn chưa về được.', array( 'account' => $assets[0]['name'] ) ) : self::step( 3, 'assets', 'ok', '', '', array( 'account' => $assets[0]['name'] ) );
		} else {
			$s3 = self::step( 3, 'assets', 'active' );
		}

		// ④ Agent Guru
		if ( ! in_array( $s3['state'], array( 'ok', 'checking' ), true ) ) {
			$s4 = self::step( 4, 'guru', 'locked' );
		} else {
			$unbound = array_filter( $assets, static function ( $a ) { return (int) $a['guru_id'] <= 0; } );
			$s4      = ! $unbound ? self::step( 4, 'guru', 'ok' ) : self::step( 4, 'guru', 'active', '', 'google' === $provider ? '' : 'Còn ' . count( $unbound ) . ' mục chưa có Agent Guru.' );
		}

		$steps = array( $s1, $s2, $s3, $s4 );
		$done  = 4 === count( array_filter( $steps, static function ( $s ) { return 'ok' === $s['state']; } ) );
		return array(
			'contract' => self::CONTRACT,
			'provider' => $provider,
			'mode'     => $mode,
			'steps'    => $steps,
			'assets'   => $assets,
			'pending'  => $pending,
			'small_win' => $done || ( 'checking' === $s3['state'] && 'ok' === $s4['state'] ) ? self::small_wins( $provider, $assets ) : array(),
			'done'     => $done,
		);
	}

	private static function fb_step3( array $assets, array $pending ): array {
		$fb = self::fb_setup();
		$s3 = array();
		foreach ( (array) ( $fb['steps'] ?? array() ) as $s ) {
			if ( 'pages' === ( $s['id'] ?? '' ) ) {
				$s3 = $s;
			}
		}
		if ( ! $assets ) {
			$missing = (array) ( $pending['missing'] ?? array() );
			return self::step( 3, 'assets', 'active', $missing ? 'scope_declined' : '', '', array( 'missing' => $missing ) );
		}
		$code = '';
		if ( 'denied' === ( $s3['state'] ?? '' ) ) {
			$code = 'scope_declined';
		} elseif ( 'fail' === ( $s3['state'] ?? '' ) ) {
			$code = false !== strpos( (string) ( $s3['message'] ?? '' ), 'tài khoản khác' ) ? 'channel_taken' : 'hub_register_failed';
		}
		$state = in_array( $s3['state'] ?? '', array( 'ok', 'checking', 'fail' ), true ) ? (string) $s3['state'] : ( 'denied' === ( $s3['state'] ?? '' ) ? 'fail' : 'ok' );
		return self::step( 3, 'assets', $state, $code, (string) ( $s3['message'] ?? '' ), array( 'hint' => (string) ( $s3['hint'] ?? '' ), 'account' => implode( ', ', array_column( $assets, 'name' ) ) ) );
	}

	private static function small_wins( string $provider, array $assets ): array {
		$first = $assets[0] ?? null;
		if ( ! $first ) {
			return array();
		}
		if ( 'facebook' === $provider ) {
			return array(
				array( 'kind' => 'message_link', 'href' => 'https://m.me/' . rawurlencode( $first['id'] ), 'asset' => $first['name'] ),
				array( 'kind' => 'draft_post', 'asset' => $first['name'] ),
			);
		}
		if ( 'google' === $provider ) {
			return array( array( 'kind' => 'email_self', 'asset' => $first['name'] ), array( 'kind' => 'event_15m', 'asset' => $first['name'] ) );
		}
		return array( array( 'kind' => 'oa_message', 'href' => 'https://zalo.me/' . rawurlencode( $first['id'] ), 'asset' => $first['name'] ) );
	}

	/* ================================================================
	 *  own app
	 * ================================================================ */

	public static function get_own_app( $request ) {
		return rest_ensure_response( BizCity_Connect_Own_App::view( self::provider( $request ) ) );
	}

	public static function post_own_app( $request ) {
		$provider = self::provider( $request );
		$app_id   = trim( (string) $request->get_param( 'app_id' ) );
		$secret   = trim( (string) $request->get_param( 'app_secret' ) );
		if ( '' === $app_id ) {
			return self::err( 'own_app_secret_invalid', 'Thiếu App ID.', 'Dán App ID của app bạn tạo.' );
		}
		// [2026-10-08 02:52 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.94-S94-M11 — Verify Token đổi được (mặc định bizgpt)
		if ( 'facebook' === $provider && null !== $request->get_param( 'verify_token' ) ) {
			if ( '' === BizCity_Connect_Own_App::set_fb_verify_token( (string) $request->get_param( 'verify_token' ) ) ) {
				return self::err( 'own_app_verify_token_invalid', 'Verify Token chỉ gồm chữ, số, dấu . _ - (3–64 ký tự).', 'Ví dụ: bizgpt hoặc shop-hana-2026.' );
			}
		}
		$before = BizCity_Connect_Own_App::creds( $provider );
		BizCity_Connect_Own_App::save( $provider, $app_id, $secret );
		if ( '' === BizCity_Connect_Own_App::creds( $provider )['app_secret'] ) {
			return self::err( 'own_app_secret_invalid', 'Thiếu khoá bí mật của app.', 'Dán App Secret.' );
		}
		$check = BizCity_Connect_Own_App::check( $provider );
		if ( $check['ok'] ) {
			$m              = get_option( self::OPT_MODES, array() );
			$m              = is_array( $m ) ? $m : array();
			$m[ $provider ] = 'own';
			update_option( self::OPT_MODES, $m, false );
		}
		return rest_ensure_response( array(
			'ok'          => $check['ok'],
			'check'       => $check,
			'app_changed' => $before['app_id'] !== $app_id && '' !== $before['app_id'],
			'own'         => BizCity_Connect_Own_App::view( $provider ),
			'setup'       => self::build( $provider ),
		) );
	}

	/* ================================================================
	 *  login: start ⇒ popup ⇒ exchange
	 * ================================================================ */

	public static function post_start( $request ) {
		$provider = self::provider( $request );
		$scopes   = array_values( array_filter( array_map( 'sanitize_key', (array) $request->get_param( 'scopes' ) ) ) );
		if ( 'own' === self::mode( $provider ) ) {
			$r = BizCity_Connect_Own_App::authorize_url( $provider, $scopes );
			if ( empty( $r['ok'] ) ) {
				return self::err( (string) $r['code'], 'App của bạn chưa lưu đủ App ID và khoá bí mật.', 'Mở "App của bạn" ở bước ② và lưu lại.' );
			}
			return rest_ensure_response( $r + array( 'mode' => 'own' ) ); // includes 'state' (audit R-5)
		}
		$origin = BizCity_Connect_Own_App::origin( (string) $request->get_param( 'origin' ) );
		if ( '' === $origin ) {
			$origin = BizCity_Connect_Own_App::origin( home_url( '/' ) );
		}
		$r = BizCity_OAuth_Broker_Client::start( $provider, $scopes, $origin );
		if ( ! $r['ok'] ) {
			return self::err( (string) $r['error']['code'], (string) $r['error']['message'], (string) $r['error']['hint'], 409 );
		}
		return rest_ensure_response( array(
			'ok'            => true,
			'mode'          => 'shared',
			'authorize_url' => (string) ( $r['data']['authorize_url'] ?? '' ),
			'popup_origin'  => (string) ( $r['data']['popup_origin'] ?? '' ),
			'state'         => (string) ( $r['data']['state'] ?? '' ),
		) );
	}

	public static function get_callback( $request ) {
		$html = BizCity_Connect_Own_App::callback( self::provider( $request ), (array) $request->get_params() );
		if ( isset( self::$seams['emit'] ) ) {
			return call_user_func( self::$seams['emit'], $html );
		}
		if ( ! headers_sent() ) {
			header( 'Content-Type: text/html; charset=utf-8' );
			header( 'Cache-Control: no-store' );
			header( 'Referrer-Policy: no-referrer' );
		}
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts
		exit;
	}

	public static function post_exchange( $request ) {
		$provider = self::provider( $request );
		$exchange = (string) $request->get_param( 'exchange' );
		$state    = (string) $request->get_param( 'state' ); // audit R-5: the page polls by state when the browser cut window.opener (COOP)
		$mode     = 'own' === (string) $request->get_param( 'mode' ) ? 'own' : 'shared';
		if ( '' !== $state && '' === $exchange ) {
			$mode = 'own' === self::mode( $provider ) ? 'own' : 'shared'; // a poll never trusts the client for the mode: the server started this login
		}
		if ( 'own' === $mode ) {
			if ( '' !== $state && '' === $exchange ) {
				$t = BizCity_Connect_Own_App::take_by_state( $provider, $state );
				if ( ! empty( $t['pending'] ) ) {
					return rest_ensure_response( array( 'ok' => true, 'pending' => true ) );
				}
				if ( isset( $t['error'] ) ) {
					return self::err( (string) $t['error'], 'Đăng nhập chưa xong.', 'Bấm đăng nhập lại.', 409 );
				}
				$payload = (array) $t['payload'];
			} else {
				$payload = BizCity_Connect_Own_App::take_exchange( $provider, $exchange );
				if ( null === $payload ) {
					return self::err( 'exchange_invalid', 'Phiên đăng nhập đã hết hạn.', 'Bấm đăng nhập lại.', 404 );
				}
			}
		} else {
			$r = BizCity_OAuth_Broker_Client::exchange( $provider, $exchange, '' === $exchange ? $state : '' );
			if ( ! $r['ok'] ) {
				return self::err( (string) $r['error']['code'], (string) $r['error']['message'], (string) $r['error']['hint'], 404 );
			}
			if ( ! empty( $r['data']['pending'] ) ) {
				return rest_ensure_response( array( 'ok' => true, 'pending' => true ) );
			}
			$payload = $r['data'];
		}
		return rest_ensure_response( self::save_login( $provider, $mode, $payload ) );
	}

	/** Save what the login returned. Facebook: page list waits for the pick (tokens stay server-side); Google / Zalo OA: saved now. */
	public static function save_login( string $provider, string $mode, array $payload ): array {
		$uid = get_current_user_id();
		if ( 'facebook' === $provider ) {
			set_transient( 'bzc_fb_pending_' . $uid, BizCity_Connect_Own_App::seal_array( array( 'mode' => $mode ) + $payload ), self::PENDING_TTL ); // D-3: page tokens sealed
			if ( ! empty( $payload['granted'] ) ) {
				update_option( 'bizcity_fb_granted_scopes', array_values( array_map( 'strval', (array) $payload['granted'] ) ), false );
			}
			return array( 'ok' => true, 'pending' => self::pending( 'facebook' ), 'setup' => self::build( 'facebook' ) );
		}
		if ( 'google' === $provider ) {
			if ( ! class_exists( 'BZGoogle_Token_Store' ) ) {
				return array( 'ok' => false, 'code' => 'module_missing' );
			}
			BZGoogle_Token_Store::save( array(
				'blog_id'         => get_current_blog_id(),
				'user_id'         => $uid,
				'google_email'    => (string) ( $payload['account']['email'] ?? '' ),
				'google_sub'      => (string) ( $payload['account']['id'] ?? '' ),
				'access_token'    => (string) ( $payload['access_token'] ?? '' ),
				'refresh_token'   => (string) ( $payload['refresh_token'] ?? '' ),
				'scope'           => (string) ( $payload['scope'] ?? '' ),
				'expires_at'      => gmdate( 'Y-m-d H:i:s', time() + (int) ( $payload['expires_in'] ?? 3600 ) ),
				'connection_mode' => 'own' === $mode ? 'byo_app' : 'hub_broker',
			) );
			self::set_owner( 'google', (string) ( $payload['account']['email'] ?? '' ), $uid );
			return array( 'ok' => true, 'declined' => (array) ( $payload['declined'] ?? array() ), 'setup' => self::build( 'google' ) );
		}
		// zalo_oa
		if ( ! class_exists( 'BizCity_Integration_Registry' ) ) {
			return array( 'ok' => false, 'code' => 'module_missing' );
		}
		$oa       = (string) ( $payload['account']['id'] ?? '' );
		$registry = BizCity_Integration_Registry::instance();
		$uid_acc  = '';
		foreach ( (array) $registry->get_accounts( 'zalo_oa' ) as $a ) {
			if ( (string) ( $a['oa_id'] ?? '' ) === $oa ) {
				$uid_acc = (string) ( $a['_uid'] ?? '' );
			}
		}
		if ( '' === $uid_acc && 'own' === $mode ) {
			$uid_acc = BizCity_Connect_Own_App::zalo_uid(); // the Webhook URL pasted before login points here
		}
		$own  = 'own' === $mode ? BizCity_Connect_Own_App::creds( 'zalo_oa' ) : array( 'app_id' => '', 'app_secret' => '' );
		$data = array(
			'connection_mode'  => 'own' === $mode ? 'self_managed' : 'hub_broker',
			'oa_id'            => $oa,
			'oa_name'          => (string) ( $payload['account']['name'] ?? '' ),
			'app_id'           => $own['app_id'],
			'app_secret'       => $own['app_secret'],
			'access_token'     => (string) ( $payload['access_token'] ?? '' ),
			'refresh_token'    => (string) ( $payload['refresh_token'] ?? '' ),
			'token_expires_at' => gmdate( 'Y-m-d H:i:s', time() + (int) ( $payload['expires_in'] ?? 3600 ) ),
			'_status'          => 1,
		);
		if ( '' !== $uid_acc ) {
			$data['_uid'] = $uid_acc;
		}
		$saved = $registry->save_channel_account( 'zalo_oa', $data, false );
		if ( is_wp_error( $saved ) ) {
			return array( 'ok' => false, 'code' => 'save_failed' );
		}
		self::set_owner( 'zalo_oa', $oa, $uid );
		// [2026-10-07 12:45 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.93 D93-6 — the shared Zalo app has ONE webhook (at the Hub):
		// claim the OA in the Hub channel registry so its events are relayed here. Own app: Zalo posts to this site directly.
		// Gap A: the cell answers every registered OA, own app too — so own-app OAs are registered as well (their failure does not
		// block step ③: Zalo posts straight to this site and the old Guru path answers while the OA is not registered).
		$reg = self::hub_channel( 'PUT', 'zalo_oa', $oa );
		self::set_registration( 'zalo_oa', $oa, $reg['ok'] ? 'ok' : (string) ( $reg['code'] ?? 'hub_register_failed' ) );
		return array( 'ok' => true, 'registered' => $reg['ok'], 'setup' => self::build( 'zalo_oa' ) );
	}

	/** PUT|DELETE zalo-hub/channel/register — the Hub registry row (platform, channel_ref) ⇒ this site's key. */
	public static function hub_channel( string $method, string $platform, string $ref ): array {
		if ( isset( self::$seams['hub_channel'] ) ) {
			return (array) call_user_func( self::$seams['hub_channel'], $method, $platform, $ref );
		}
		if ( ! class_exists( 'BizCity_LLM_Client' ) || '' === self::api_key() ) {
			return array( 'ok' => false, 'code' => 'key_missing' );
		}
		$llm = BizCity_LLM_Client::instance();
		$raw = 'DELETE' === $method
			? $llm->gateway_get( '/zalo-hub/channel/register', array( 'platform' => $platform, 'channel_ref' => $ref ), 'DELETE', 10, false )
			: $llm->gateway_post( '/zalo-hub/channel/register?_method=PUT', array( 'platform' => $platform, 'channel_ref' => $ref ), 15, false ); // WP REST honours ?_method=PUT
		$ok = is_array( $raw ) && empty( $raw['_degraded'] ) && ! empty( $raw['ok'] );
		return array( 'ok' => $ok, 'code' => $ok ? '' : ( 'channel_taken' === ( $raw['code'] ?? '' ) || false !== strpos( (string) ( $raw['code'] ?? '' ), 'taken' ) ? 'channel_taken' : 'hub_register_failed' ) );
	}

	private static function set_registration( string $provider, string $asset, string $state ): void {
		$o                        = get_option( 'bizcity_connect_hub_channels', array() );
		$o                        = is_array( $o ) ? $o : array();
		$o[ $provider ][ $asset ] = $state;
		update_option( 'bizcity_connect_hub_channels', $o, false );
	}

	/** '' = registered (or not needed), else the error code of the last registration. */
	/** Strictly registered: the Hub accepted this asset (no record = not registered; used by the cell adapters). */
	public static function is_registered( string $provider, string $asset ): bool {
		$o = get_option( 'bizcity_connect_hub_channels', array() );
		return 'ok' === (string) ( $o[ $provider ][ $asset ] ?? '' );
	}

	public static function registration( string $provider, string $asset ): string {
		$o = get_option( 'bizcity_connect_hub_channels', array() );
		$s = (string) ( $o[ $provider ][ $asset ] ?? 'ok' );
		return 'ok' === $s ? '' : $s;
	}

	private static function fb_module_loaded(): bool {
		return isset( self::$seams['fb_module'] ) ? (bool) call_user_func( self::$seams['fb_module'] ) : class_exists( 'BizCity_FB_Setup_REST' );
	}

	private static function fb_module_missing(): WP_Error {
		return self::err( 'module_missing', 'Chưa bật plugin Facebook Bot trên website này.', 'Bật plugin Facebook Bot (hoặc module Facebook của Channel Gateway) rồi thử lại.', 503 );
	}

	/** Facebook pages waiting for the pick (no token in the output). */
	public static function pending( string $provider ): array {
		if ( 'facebook' !== $provider ) {
			return array();
		}
		$p = BizCity_Connect_Own_App::open_array( get_transient( 'bzc_fb_pending_' . get_current_user_id() ) );
		if ( ! $p ) {
			return array();
		}
		$pages = array();
		foreach ( (array) ( $p['pages'] ?? array() ) as $pg ) {
			$pages[] = array( 'id' => (string) $pg['id'], 'name' => (string) $pg['name'], 'category' => (string) ( $pg['category'] ?? '' ), 'followers' => (int) ( $pg['followers'] ?? 0 ), 'role_ok' => ! empty( $pg['role_ok'] ) );
		}
		return array( 'mode' => (string) ( $p['mode'] ?? 'shared' ), 'account' => (array) ( $p['account'] ?? array() ), 'missing' => array_values( (array) ( $p['missing'] ?? array() ) ), 'pages' => $pages );
	}

	private static function set_owner( string $provider, string $asset, int $user_id ): void {
		if ( '' === $asset ) {
			return;
		}
		$o                        = get_option( self::OPT_OWNERS, array() );
		$o                        = is_array( $o ) ? $o : array();
		$o[ $provider ][ $asset ] = $user_id;
		update_option( self::OPT_OWNERS, $o, false );
	}

	/* ================================================================
	 *  ③ choose + ④ guru
	 * ================================================================ */

	public static function post_assets( $request ) {
		$provider = self::provider( $request );
		$ids      = array_values( array_unique( array_filter( array_map( static function ( $v ) { return preg_replace( '/[^0-9A-Za-z_@.\-]/', '', (string) $v ); }, (array) $request->get_param( 'asset_ids' ) ) ) ) );
		$owner    = (int) $request->get_param( 'owner_user_id' );
		if ( $owner <= 0 ) {
			$owner = get_current_user_id();
		}
		if ( 'facebook' !== $provider ) {
			$by = array();
			foreach ( self::assets( $provider ) as $a ) {
				$by[ $a['id'] ] = $a;
			}
			foreach ( $ids as $id ) {
				self::set_owner( $provider, $id, $owner );
				if ( 'zalo_oa' === $provider && isset( $by[ $id ] ) && 'managed' !== $by[ $id ]['mode'] ) {
					$reg = self::hub_channel( 'PUT', 'zalo_oa', $id ); // "Thử lại" of a failed Hub registration (D93-6)
					self::set_registration( 'zalo_oa', $id, $reg['ok'] ? 'ok' : (string) ( $reg['code'] ?? 'hub_register_failed' ) );
				}
			}
			return rest_ensure_response( array( 'ok' => true, 'setup' => self::build( $provider ) ) );
		}
		if ( ! self::fb_module_loaded() ) {
			return self::fb_module_missing(); // audit R-3: plugin Facebook Bot inactive ⇒ a clear refusal, not a fatal error
		}
		$p = BizCity_Connect_Own_App::open_array( get_transient( 'bzc_fb_pending_' . get_current_user_id() ) );
		if ( ! $p ) {
			return self::err( 'exchange_invalid', 'Phiên chọn Fanpage đã hết hạn.', 'Bấm Đăng nhập Facebook lại.', 409 );
		}
		$by_id = array();
		foreach ( (array) ( $p['pages'] ?? array() ) as $pg ) {
			$by_id[ (string) $pg['id'] ] = $pg;
		}
		$picked = array();
		foreach ( $ids as $id ) {
			if ( ! isset( $by_id[ $id ] ) ) {
				return self::err( 'invalid_param', 'Có page không nằm trong lần đăng nhập vừa rồi.', 'Đăng nhập lại và chọn lại.' );
			}
			if ( empty( $by_id[ $id ]['role_ok'] ) ) {
				return self::err( 'asset_role_missing', 'Bạn cần là quản trị viên của ' . $by_id[ $id ]['name'] . '.', 'Nhờ quản trị viên page cấp quyền, rồi đăng nhập lại.' );
			}
			$picked[] = $by_id[ $id ];
		}
		if ( ! $picked ) {
			return self::err( 'invalid_param', 'Chưa chọn Fanpage.', 'Tick ít nhất một page.' );
		}
		$errors = array();
		foreach ( $picked as $pg ) {
			self::fb_store_page( $pg, (string) ( $p['mode'] ?? 'shared' ) );
			$sub = self::graph( 'POST', '/' . rawurlencode( $pg['id'] ) . '/subscribed_apps', array( 'subscribed_fields' => 'messages,messaging_postbacks,feed' ), (string) $pg['access_token'] );
			if ( empty( $sub['ok'] ) ) {
				$errors[] = array( 'page_id' => $pg['id'], 'code' => 'subscribe_failed' );
			}
		}
		$r = new WP_REST_Request( 'POST', '/' . self::NS . '/fb/setup/pages' );
		$r->set_param( 'page_ids', array_column( $picked, 'id' ) );
		$r->set_param( 'owner_user_id', $owner );
		if ( null !== $request->get_param( 'default_page_id' ) ) {
			$r->set_param( 'default_page_id', (string) $request->get_param( 'default_page_id' ) );
		}
		$reg = BizCity_FB_Setup_REST::post_pages( $r );
		if ( is_wp_error( $reg ) ) {
			return $reg;
		}
		delete_transient( 'bzc_fb_pending_' . get_current_user_id() );
		$reg_data = $reg instanceof WP_REST_Response ? $reg->get_data() : (array) $reg;
		return rest_ensure_response( array( 'ok' => ! $errors && ! empty( $reg_data['ok'] ), 'errors' => array_merge( $errors, (array) ( $reg_data['errors'] ?? array() ) ), 'setup' => self::build( 'facebook' ) ) );
	}

	/** Upsert the page into bizcity_facebook_bots — the table every Facebook reader already uses (adapter, setup, publisher). */
	private static function fb_store_page( array $pg, string $mode ): void {
		if ( isset( self::$seams['fb_store'] ) ) {
			call_user_func( self::$seams['fb_store'], $pg, $mode );
			return;
		}
		if ( ! class_exists( 'BizCity_Facebook_Bot_Database' ) ) {
			return;
		}
		$db   = BizCity_Facebook_Bot_Database::instance();
		$own  = 'own' === $mode ? BizCity_Connect_Own_App::creds( 'facebook' ) : array( 'app_id' => '', 'app_secret' => '' );
		$data = array( 'bot_name' => (string) $pg['name'], 'page_id' => (string) $pg['id'], 'page_access_token' => (string) $pg['access_token'], 'user_id' => get_current_user_id(), 'app_id' => $own['app_id'], 'app_secret' => $own['app_secret'], 'status' => 'active' );
		$row  = $db->get_bot_by_page_id( (string) $pg['id'] );
		if ( $row ) {
			$db->update_bot( $row->id, $data );
		} else {
			$db->insert_bot( $data );
		}
	}

	public static function delete_assets( $request ) {
		$provider = self::provider( $request );
		$ids      = array_values( array_filter( array_map( static function ( $v ) { return preg_replace( '/[^0-9A-Za-z_@.\-]/', '', (string) $v ); }, (array) $request->get_param( 'asset_ids' ) ) ) );
		if ( ! $ids ) {
			return self::err( 'invalid_param', 'Chưa chọn mục cần ngắt.', 'Truyền asset_ids.' );
		}
		if ( 'facebook' === $provider ) {
			if ( ! self::fb_module_loaded() ) {
				return self::fb_module_missing();
			}
			foreach ( $ids as $id ) {
				$token = class_exists( 'BizCity_FB_Channel_Adapter' ) ? BizCity_FB_Channel_Adapter::page_token( $id ) : '';
				if ( '' !== $token ) {
					self::graph( 'DELETE', '/' . rawurlencode( $id ) . '/subscribed_apps', array(), $token );
				}
			}
			$r = new WP_REST_Request( 'DELETE', '/' . self::NS . '/fb/setup/pages' );
			$r->set_param( 'page_ids', $ids );
			BizCity_FB_Setup_REST::delete_pages( $r );
		} elseif ( 'google' === $provider && class_exists( 'BZGoogle_Installer' ) ) {
			global $wpdb;
			foreach ( $ids as $email ) {
				$row = $wpdb->get_row( $wpdb->prepare( 'SELECT id, user_id, connection_mode FROM ' . BZGoogle_Installer::table_accounts() . " WHERE blog_id = %d AND google_email = %s AND status = 'active' LIMIT 1", get_current_blog_id(), $email ) );
				if ( ! $row ) {
					continue;
				}
				$tok = BZGoogle_Token_Store::get_token( get_current_blog_id(), (int) $row->user_id, $email );
				if ( $tok && 'hub_broker' === $row->connection_mode ) {
					BizCity_OAuth_Broker_Client::revoke( 'google', array( 'token' => (string) $tok['access_token'] ) );
				}
				BZGoogle_Token_Store::disconnect( (int) $row->id, (int) $row->user_id );
			}
		} elseif ( 'zalo_oa' === $provider && class_exists( 'BizCity_Integration_Registry' ) ) {
			foreach ( self::assets( 'zalo_oa' ) as $a ) {
				if ( in_array( $a['id'], $ids, true ) && '' !== (string) ( $a['uid'] ?? '' ) && 'managed' !== $a['mode'] ) {
					// audit R-10: own-app OAs are registered at the Hub too (gap A) — release the claim, or the OA stays "taken" by this key
					self::hub_channel( 'DELETE', 'zalo_oa', $a['id'] );
					self::set_registration( 'zalo_oa', $a['id'], '' );
					BizCity_Integration_Registry::instance()->delete_channel_account( 'zalo_oa', (string) $a['uid'] );
				}
			}
		}
		return rest_ensure_response( array( 'ok' => true, 'setup' => self::build( $provider ) ) );
	}

	public static function post_guru( $request ) {
		$provider = self::provider( $request );
		$cid      = (int) $request->get_param( 'character_id' );
		$auto     = null === $request->get_param( 'auto_reply' ) ? true : (bool) $request->get_param( 'auto_reply' );
		if ( $cid <= 0 ) {
			return self::err( 'invalid_param', 'Chọn một Agent Guru.', 'Truyền character_id.' );
		}
		if ( 'facebook' === $provider && ! self::fb_module_loaded() ) {
			return self::fb_module_missing();
		}
		$assets = self::assets( $provider );
		$want   = (string) $request->get_param( 'asset_id' );
		$target = '' !== $want ? array_values( array_filter( $assets, static function ( $a ) use ( $want ) { return $a['id'] === $want; } ) ) : $assets;
		if ( ! $target ) {
			return self::err( 'invalid_param', 'Chưa có mục nào đã kết nối ở bước ③.', 'Làm bước ③ trước.', 409 );
		}
		foreach ( $target as $a ) {
			if ( 'facebook' === $provider ) {
				$r = new WP_REST_Request( 'POST', '/' . self::NS . '/fb/setup/guru' );
				$r->set_param( 'page_id', $a['id'] );
				$r->set_param( 'character_id', $cid );
				$r->set_param( 'mode', $auto ? 'auto' : 'manual' );
				$res = BizCity_FB_Setup_REST::post_guru( $r );
				if ( is_wp_error( $res ) ) {
					return $res;
				}
			} elseif ( 'google' === $provider ) {
				$g             = get_option( self::OPT_GURU, array() );
				$g             = is_array( $g ) ? $g : array();
				$g[ $a['id'] ] = $cid;
				update_option( self::OPT_GURU, $g, false );
			} elseif ( class_exists( 'BizCity_Channel_Binding' ) ) {
				BizCity_Channel_Binding::upsert( array( 'platform' => 'ZALO_OA', 'account_id' => $a['id'], 'character_id' => $cid, 'mode' => $auto ? 'auto' : 'manual' ) );
			}
		}
		return rest_ensure_response( array( 'ok' => true, 'setup' => self::build( $provider ) ) );
	}

	/* ================================================================
	 *  small win (S93-C7): a real result in ≤ 10 s
	 * ================================================================ */

	public static function post_test( $request ) {
		$provider = self::provider( $request );
		$kind     = (string) $request->get_param( 'kind' );
		if ( ! in_array( $kind, self::TEST_KINDS[ $provider ], true ) ) {
			return self::err( 'invalid_param', 'Không có bài thử này.', 'Chọn một nút thử trên màn hình.' );
		}
		$assets = self::assets( $provider );
		$want   = (string) $request->get_param( 'asset_id' );
		$a      = null;
		foreach ( $assets as $x ) {
			if ( '' === $want || $x['id'] === $want ) {
				$a = $x;
				break;
			}
		}
		if ( ! $a ) {
			return self::err( 'invalid_param', 'Chưa có mục nào đã kết nối.', 'Làm xong bước ③.', 409 );
		}
		if ( 'message_link' === $kind ) {
			return rest_ensure_response( array( 'ok' => true, 'href' => 'https://m.me/' . rawurlencode( $a['id'] ), 'message' => 'Mở Messenger của page và nhắn một câu — trợ lý trả lời trong vài giây, tin hiện ở CRM Inbox.' ) );
		}
		if ( 'oa_message' === $kind ) {
			return rest_ensure_response( array( 'ok' => true, 'href' => 'https://zalo.me/' . rawurlencode( $a['id'] ), 'message' => 'Mở OA trên Zalo và nhắn một câu — trợ lý trả lời trong vài giây.' ) );
		}
		if ( 'draft_post' === $kind ) {
			$token = class_exists( 'BizCity_FB_Channel_Adapter' ) ? BizCity_FB_Channel_Adapter::page_token( $a['id'] ) : '';
			$r     = self::graph( 'POST', '/' . rawurlencode( $a['id'] ) . '/feed', array( 'message' => 'Bài thử từ BizCity — bài nháp, không ai thấy.', 'published' => 'false' ), $token );
			if ( empty( $r['ok'] ) ) {
				return self::err( 'test_failed', 'Facebook chưa cho tạo bài nháp.', 'Kiểm tra quyền "đăng bài" ở bước ③ (đăng nhập lại và cho phép).', 502 );
			}
			return rest_ensure_response( array( 'ok' => true, 'message' => 'Đã tạo bài nháp trên ' . $a['name'] . '.', 'href' => 'https://business.facebook.com/latest/posts/drafts?asset_id=' . rawurlencode( $a['id'] ) ) );
		}
		if ( ! class_exists( 'BZGoogle_Google_Service' ) ) {
			return self::err( 'module_missing', 'Thiếu module Google.', 'Báo quản trị.', 503 );
		}
		$user = (int) $a['owner_user_id'];
		if ( 'email_self' === $kind ) {
			$r = BZGoogle_Google_Service::gmail_send( get_current_blog_id(), $user, array( 'to' => $a['id'], 'subject' => 'BizCity thử', 'body' => '<p>Email thử từ BizCity — kết nối Gmail đã chạy.</p>' ) );
			if ( is_wp_error( $r ) ) {
				return self::err( 'test_failed', 'Chưa gửi được email thử.', 'Kiểm tra quyền "Gửi email" ở bước ③.', 502 );
			}
			return rest_ensure_response( array( 'ok' => true, 'message' => 'Đã gửi tới ' . $a['id'] . '. Mở hộp thư để xem.' ) );
		}
		$tz    = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'Asia/Ho_Chi_Minh' );
		$start = new DateTime( '+10 minutes', $tz );
		$end   = ( clone $start )->modify( '+15 minutes' );
		$r     = BZGoogle_Google_Service::calendar_create( get_current_blog_id(), $user, array( 'title' => 'BizCity thử — có thể xoá', 'start_time' => $start->format( 'Y-m-d H:i:s' ), 'end_time' => $end->format( 'Y-m-d H:i:s' ) ) );
		if ( is_wp_error( $r ) ) {
			return self::err( 'test_failed', 'Chưa tạo được lịch thử.', 'Kiểm tra quyền "Lịch" ở bước ③.', 502 );
		}
		return rest_ensure_response( array( 'ok' => true, 'message' => 'Đã tạo lịch thử lúc ' . $start->format( 'H:i' ) . '.', 'href' => (string) ( $r['htmlLink'] ?? '' ) ) );
	}

	/* ================================================================
	 *  shared Facebook app: webhook relayed by the Hub (S93-H2 site half)
	 * ================================================================ */

	/**
	 * relay@2: sig = sha256=hmac("<ts>.<body>", sha256(key)) and |now − ts| ≤ 300 s (replay window). relay@1 (no timestamp, body-only signature)
	 * is still accepted until option bizcity_connect_relay_v1 = off — set it once the Hub runs relay@2 (audit R-6).
	 */
	public static function verify_relay( string $body, string $sig, string $api_key, string $ts = '' ): bool {
		if ( '' === $api_key || '' === $sig ) {
			return false;
		}
		$secret = hash( 'sha256', $api_key );
		if ( '' !== $ts ) {
			$now = isset( self::$seams['now'] ) ? (int) call_user_func( self::$seams['now'] ) : time();
			return ctype_digit( $ts ) && abs( $now - (int) $ts ) <= 300 && hash_equals( 'sha256=' . hash_hmac( 'sha256', $ts . '.' . $body, $secret ), $sig );
		}
		return 'off' !== (string) get_option( 'bizcity_connect_relay_v1', 'on' ) && hash_equals( 'sha256=' . hash_hmac( 'sha256', $body, $secret ), $sig );
	}

	public static function post_fb_relay( $request ) {
		$body = (string) $request->get_body();
		if ( ! self::verify_relay( $body, (string) $request->get_header( 'X-BizCity-Relay-Signature' ), self::api_key(), (string) $request->get_header( 'X-BizCity-Relay-Timestamp' ) ) ) {
			return new WP_REST_Response( array( 'ok' => false ), 403 );
		}
		$data    = json_decode( $body, true );
		$handled = 0;
		foreach ( (array) ( $data['entry'] ?? array() ) as $entry ) {
			$page = (string) ( $entry['id'] ?? '' );
			foreach ( (array) ( $entry['messaging'] ?? array() ) as $m ) {
				self::fb_handle( $page, 'messaging', (array) $m );
				$handled++;
			}
			foreach ( (array) ( $entry['changes'] ?? array() ) as $c ) {
				self::fb_handle( $page, 'change', (array) $c );
				$handled++;
			}
		}
		return rest_ensure_response( array( 'ok' => true, 'handled' => $handled ) );
	}

	/* ================================================================
	 *  shared Zalo app: webhook relayed by the Hub (D93-6 site half)
	 * ================================================================ */

	/** True only while a Hub-signed Zalo event is being handed to the channel webhook (see class-channel-rest-api.php). */
	public static $relaying = false;

	public static function post_zalo_relay( $request ) {
		$body = (string) $request->get_body();
		if ( ! self::verify_relay( $body, (string) $request->get_header( 'X-BizCity-Relay-Signature' ), self::api_key(), (string) $request->get_header( 'X-BizCity-Relay-Timestamp' ) ) ) {
			return new WP_REST_Response( array( 'ok' => false ), 403 );
		}
		$data  = (array) json_decode( $body, true );
		$event = (string) ( $data['event_name'] ?? '' );
		$oa    = '';
		foreach ( array( $data['oa_id'] ?? '', 0 === strpos( $event, 'user_' ) ? ( $data['recipient']['id'] ?? '' ) : ( $data['sender']['id'] ?? '' ), $data['recipient']['id'] ?? '' ) as $c ) {
			$c = preg_replace( '/[^0-9]/', '', (string) $c );
			if ( '' !== $c ) {
				$oa = $c;
				break;
			}
		}
		$uid = '';
		foreach ( self::assets( 'zalo_oa' ) as $a ) {
			if ( $a['id'] === $oa ) {
				$uid = (string) ( $a['uid'] ?? '' );
			}
		}
		if ( '' === $uid ) {
			return rest_ensure_response( array( 'ok' => true, 'handled' => 0 ) ); // an OA this site no longer has
		}
		if ( isset( self::$seams['zalo_handler'] ) ) {
			call_user_func( self::$seams['zalo_handler'], $uid, $body );
			return rest_ensure_response( array( 'ok' => true, 'handled' => 1 ) );
		}
		// hand the event to the ONE Zalo OA inbound path (webhook/zalo_oa/{uid}): CRM, binding, Agent Guru — nothing duplicated here
		$inner = new WP_REST_Request( 'POST', '/' . self::NS . '/webhook/zalo_oa/' . $uid );
		$inner->set_header( 'Content-Type', 'application/json' );
		$inner->set_body( $body );
		self::$relaying = true;
		try {
			$res = rest_do_request( $inner );
		} finally {
			self::$relaying = false;
		}
		return rest_ensure_response( array( 'ok' => ! $res->is_error(), 'handled' => 1 ) );
	}

	/**
	 * The public webhook/zalo_oa/{uid} route checks Zalo's mac with the account's app secret. An OA on the shared app has no
	 * secret on the site, so its events are accepted ONLY when relayed by the Hub (signed with this site's key).
	 */
	public static function zalo_inbound_allowed( array $account ): bool {
		return 'hub_broker' !== (string) ( $account['connection_mode'] ?? '' ) || self::$relaying;
	}

	private static function fb_handle( string $page, string $kind, array $item ): void {
		if ( isset( self::$seams['fb_handler'] ) ) {
			call_user_func( self::$seams['fb_handler'], $page, $kind, $item );
			return;
		}
		if ( ! class_exists( 'BizCity_Facebook_Bot_Webhook_Handler' ) ) {
			return;
		}
		$h = BizCity_Facebook_Bot_Webhook_Handler::instance();
		if ( 'messaging' === $kind ) {
			$h->handle_webhook_entry_messaging( $page, $item );
		} else {
			$h->handle_webhook_entry_change( $page, $item );
		}
	}

	/** @return array{ok:bool,data:array} */
	private static function graph( string $method, string $path, array $params, string $token ): array {
		if ( isset( self::$seams['graph'] ) ) {
			return (array) call_user_func( self::$seams['graph'], $method, $path, $params, $token );
		}
		if ( '' === $token ) {
			return array( 'ok' => false, 'data' => array() );
		}
		$url  = BizCity_Connect_Own_App::FB_GRAPH . $path;
		$args = array( 'method' => $method, 'timeout' => 10 );
		if ( 'GET' === $method || 'DELETE' === $method ) {
			$url .= '?' . http_build_query( $params + array( 'access_token' => $token ) );
		} else {
			$args['body'] = $params + array( 'access_token' => $token );
		}
		$res = wp_remote_request( $url, $args );
		if ( is_wp_error( $res ) ) {
			return array( 'ok' => false, 'data' => array() );
		}
		$d = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		$d = is_array( $d ) ? $d : array();
		return array( 'ok' => (int) wp_remote_retrieve_response_code( $res ) < 300 && ! isset( $d['error'] ), 'data' => $d );
	}
}
