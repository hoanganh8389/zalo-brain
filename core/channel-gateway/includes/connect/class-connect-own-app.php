<?php
/**
 * "⚙ App của bạn" — the site's own Facebook / Google / Zalo app (PHASE-0.93 S93-M4, S93-C2…C5).
 *
 * One place per provider, the SAME place the older screens already read, so nothing drifts (P93-7):
 *   facebook  options bztfb_app_id / bztfb_app_secret (legacy plaintext format, read by class-facebook-oauth.php)
 *   google    options bzgoogle_byo_client_id / bzgoogle_byo_client_secret (encrypted, read by BZGoogle_Token_Store byo_app)
 *   zalo_oa   option  bizcity_connect_zalo_own_app {app_id, app_secret_enc}; copied onto each OA account after login
 * "Kiểm tra app" is a real call to the provider (P93-4):
 *   facebook  app token debug_token ⇒ valid / secret wrong
 *   google    token endpoint with a dummy code ⇒ invalid_client (secret wrong) · redirect_uri_mismatch (line 1 missing) · invalid_grant (OK)
 *   zalo_oa   access_token endpoint with a dummy code ⇒ app/secret error, otherwise "đạt — xác nhận lần cuối khi đăng nhập"
 * The own-app login runs on the site itself with the same popup + one-time exchange contract as the Hub broker, so the UI
 * has ONE flow for both modes.
 *
 * [2026-10-06 10:20 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.93 S93-M4 — new file.
 *
 * @package BizCity_Twin_AI
 * @subpackage Channel_Gateway
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Connect_Own_App', false ) ) {
	return;
}

final class BizCity_Connect_Own_App {

	const OPT_CHECK     = 'bizcity_connect_own_app_check';
	const OPT_ZALO      = 'bizcity_connect_zalo_own_app';
	const STATE_TTL     = 600;
	const EXCHANGE_TTL  = 120;
	const FB_GRAPH      = 'https://graph.facebook.com/v18.0';
	const FB_DIALOG     = 'https://www.facebook.com/v18.0/dialog/oauth';
	const GOOGLE_AUTH   = 'https://accounts.google.com/o/oauth2/v2/auth';
	const GOOGLE_TOKEN  = 'https://oauth2.googleapis.com/token';
	const GOOGLE_INFO   = 'https://www.googleapis.com/oauth2/v2/userinfo';
	const ZALO_OAUTH    = 'https://oauth.zaloapp.com';
	const ZALO_API      = 'https://openapi.zalo.me';
	const FB_SCOPES     = array( 'pages_show_list', 'pages_messaging', 'pages_manage_metadata', 'pages_read_engagement', 'pages_manage_posts', 'pages_manage_engagement', 'pages_read_user_content', 'business_management' );
	const FB_REQUIRED   = array( 'pages_show_list', 'pages_messaging', 'pages_manage_metadata' );
	const GOOGLE_SCOPES = array(
		'send'     => 'https://www.googleapis.com/auth/gmail.send',
		'calendar' => 'https://www.googleapis.com/auth/calendar',
		'read'     => 'https://www.googleapis.com/auth/gmail.readonly',
	);

	/* ---------------- credentials ---------------- */

	/** @return array{app_id:string,app_secret:string} */
	public static function creds( string $provider ): array {
		if ( 'facebook' === $provider ) {
			$id     = (string) get_option( 'bztfb_app_id', '' );
			$secret = (string) get_option( 'bztfb_app_secret', '' );
			if ( '' === $id ) {
				$id     = (string) get_option( 'fb_app_id', '' );
				$secret = (string) get_option( 'fb_app_secret', '' );
			}
			return array( 'app_id' => $id, 'app_secret' => $secret );
		}
		if ( 'google' === $provider ) {
			$enc = (string) get_option( 'bzgoogle_byo_client_secret', '' );
			return array( 'app_id' => (string) get_option( 'bzgoogle_byo_client_id', '' ), 'app_secret' => '' !== $enc && class_exists( 'BZGoogle_Token_Store' ) ? (string) BZGoogle_Token_Store::decrypt( $enc ) : '' );
		}
		$z = get_option( self::OPT_ZALO, array() );
		$z = is_array( $z ) ? $z : array();
		return array( 'app_id' => (string) ( $z['app_id'] ?? '' ), 'app_secret' => self::decrypt( (string) ( $z['app_secret_enc'] ?? '' ) ) );
	}

	public static function save( string $provider, string $app_id, string $secret ): void {
		$app_id = preg_replace( '/[^0-9A-Za-z._\-]/', '', $app_id );
		$keep   = '' === $secret ? self::creds( $provider )['app_secret'] : $secret;
		if ( 'facebook' === $provider ) {
			update_option( 'bztfb_app_id', $app_id );
			update_option( 'bztfb_app_secret', $keep );
		} elseif ( 'google' === $provider ) {
			update_option( 'bzgoogle_byo_client_id', $app_id );
			update_option( 'bzgoogle_byo_client_secret', class_exists( 'BZGoogle_Token_Store' ) ? BZGoogle_Token_Store::encrypt( $keep ) : '' );
		} else {
			update_option( self::OPT_ZALO, array( 'app_id' => $app_id, 'app_secret_enc' => self::encrypt( $keep ) ), false );
		}
	}

	const DEFAULT_VERIFY_TOKEN = 'bizgpt';

	/**
	 * Facebook Verify Token.
	 * [2026-10-08 02:52 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.94-S94-M11 (D94-19 v2, chủ chốt 2026-10-08) — mặc định `bizgpt`, chủ ĐỔI ĐƯỢC ở bảng "app của bạn".
	 * Thứ tự: token chủ đặt cho site (`bztfb_verify_token`) › token mạng › `bizgpt`. Không còn tự sinh token ngẫu nhiên (S93-C5 bỏ).
	 * Bộ verify của integration Facebook chấp nhận token của integration, token site và token mạng nên đổi ở đây không làm hỏng app cũ.
	 */
	public static function fb_verify_token(): string {
		$t = trim( (string) get_option( 'bztfb_verify_token', '' ) );
		if ( '' !== $t ) {
			return $t;
		}
		$network = function_exists( 'get_site_option' ) ? trim( (string) get_site_option( 'bizcity_fb_verify_token', '' ) ) : '';
		return '' !== $network ? $network : self::DEFAULT_VERIFY_TOKEN;
	}

	/** Chủ đổi Verify Token (3–64 ký tự chữ, số, . _ -); rỗng ⇒ về `bizgpt`. Trả token đã lưu, hoặc '' khi sai dạng. */
	public static function set_fb_verify_token( string $token ): string {
		$token = trim( $token );
		if ( '' === $token ) {
			$token = self::DEFAULT_VERIFY_TOKEN;
		}
		if ( ! preg_match( '/^[A-Za-z0-9._-]{3,64}$/', $token ) ) {
			return '';
		}
		update_option( 'bztfb_verify_token', $token );
		return $token;
	}

	public static function callback_url( string $provider ): string {
		return rest_url( 'bizcity-channel/v1/connect/' . $provider . '/callback' );
	}

	/** The 1–3 copy rows of the sheet (doc 50 §2 màn 4). */
	public static function copy_rows( string $provider ): array {
		$rows = array( array( 'id' => 'redirect', 'label' => 'facebook' === $provider ? 'Valid OAuth Redirect URI' : ( 'google' === $provider ? 'Authorized redirect URI' : 'Callback URL' ), 'value' => self::callback_url( $provider ) ) );
		if ( 'facebook' === $provider ) {
			// [2026-10-08 12:10 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.94-S94-M11 (D94-18, Q94-5 chủ chốt) — ?fbhook=1 là URL chính thức (R-CH-NS), không phụ thuộc rewrite/cache; /bizfbhook/ vẫn nhận cho app cũ
			$rows[] = array( 'id' => 'webhook', 'label' => 'Webhook Callback URL', 'value' => home_url( '/?fbhook=1' ) );
			$rows[] = array( 'id' => 'verify', 'label' => 'Verify Token', 'value' => self::fb_verify_token() );
		} elseif ( 'zalo_oa' === $provider ) {
			$rows[] = array( 'id' => 'webhook', 'label' => 'Webhook URL', 'value' => rest_url( 'bizcity-channel/v1/webhook/zalo_oa/' . self::zalo_uid() ) );
		}
		return $rows;
	}

	/** The account uid the next own-app OA gets — reserved now so the Webhook URL can be pasted BEFORE login (one app = one URL). */
	public static function zalo_uid(): string {
		$uid = (string) get_option( 'bizcity_connect_zalo_uid', '' );
		if ( '' === $uid ) {
			$uid = 'zalo_oa_' . bin2hex( random_bytes( 4 ) );
			update_option( 'bizcity_connect_zalo_uid', $uid, false );
		}
		return $uid;
	}

	public static function view( string $provider ): array {
		$c     = self::creds( $provider );
		$check = get_option( self::OPT_CHECK, array() );
		$last  = is_array( $check[ $provider ] ?? null ) ? $check[ $provider ] : array();
		return array(
			'saved'      => '' !== $c['app_id'] && '' !== $c['app_secret'],
			'app_id'     => $c['app_id'],
			'has_secret' => '' !== $c['app_secret'],
			'verified'   => ! empty( $last['ok'] ) && ( $last['app_id'] ?? '' ) === $c['app_id'],
			'status'     => (string) ( $last['status'] ?? '' ),
			'code'       => (string) ( $last['code'] ?? '' ),
			'name'       => (string) ( $last['name'] ?? '' ),
			'checked_at' => (int) ( $last['at'] ?? 0 ),
			'copy'       => self::copy_rows( $provider ),
		);
	}

	/* ---------------- "Kiểm tra app" (real provider call) ---------------- */

	/** @return array{ok:bool,status:string,code:string,name:string} */
	public static function check( string $provider ): array {
		$c = self::creds( $provider );
		if ( '' === $c['app_id'] || '' === $c['app_secret'] ) {
			$r = array( 'ok' => false, 'status' => 'missing', 'code' => 'own_app_secret_invalid', 'name' => '' );
		} elseif ( 'facebook' === $provider ) {
			$r = self::check_facebook( $c );
		} elseif ( 'google' === $provider ) {
			$r = self::check_google( $c );
		} else {
			$r = self::check_zalo( $c );
		}
		$all              = get_option( self::OPT_CHECK, array() );
		$all              = is_array( $all ) ? $all : array();
		$all[ $provider ] = $r + array( 'app_id' => $c['app_id'], 'at' => time() );
		update_option( self::OPT_CHECK, $all, false );
		return $r;
	}

	public static function check_facebook( array $c ): array {
		$token = $c['app_id'] . '|' . $c['app_secret'];
		$d     = self::json( wp_remote_get( self::FB_GRAPH . '/debug_token?' . http_build_query( array( 'input_token' => $token, 'access_token' => $token ) ), array( 'timeout' => 15 ) ) );
		if ( null === $d ) {
			return array( 'ok' => false, 'status' => 'unreachable', 'code' => 'provider_unreachable', 'name' => '' );
		}
		$data = is_array( $d['data'] ?? null ) ? $d['data'] : array();
		if ( empty( $data['is_valid'] ) || (string) ( $data['app_id'] ?? '' ) !== $c['app_id'] ) {
			return array( 'ok' => false, 'status' => 'invalid', 'code' => 'own_app_secret_invalid', 'name' => '' );
		}
		return array( 'ok' => true, 'status' => 'valid', 'code' => '', 'name' => sanitize_text_field( (string) ( $data['application'] ?? '' ) ) );
	}

	public static function check_google( array $c ): array {
		if ( ! preg_match( '/\.apps\.googleusercontent\.com$/', $c['app_id'] ) ) {
			return array( 'ok' => false, 'status' => 'invalid', 'code' => 'own_app_secret_invalid', 'name' => '' );
		}
		$d = self::json( wp_remote_post( self::GOOGLE_TOKEN, array( 'timeout' => 15, 'body' => array( 'code' => 'bizcity-check', 'client_id' => $c['app_id'], 'client_secret' => $c['app_secret'], 'redirect_uri' => self::callback_url( 'google' ), 'grant_type' => 'authorization_code' ) ) ) );
		if ( null === $d ) {
			return array( 'ok' => false, 'status' => 'unreachable', 'code' => 'provider_unreachable', 'name' => '' );
		}
		$e = (string) ( $d['error'] ?? '' );
		if ( 'invalid_client' === $e || 'unauthorized_client' === $e ) {
			return array( 'ok' => false, 'status' => 'invalid', 'code' => 'own_app_secret_invalid', 'name' => '' );
		}
		if ( 'redirect_uri_mismatch' === $e ) {
			return array( 'ok' => false, 'status' => 'redirect_missing', 'code' => 'own_app_redirect_missing', 'name' => '' );
		}
		return array( 'ok' => true, 'status' => 'valid', 'code' => '', 'name' => '' ); // invalid_grant = the client and secret are right
	}

	public static function check_zalo( array $c ): array {
		$d = self::json( wp_remote_post( self::ZALO_OAUTH . '/v4/oa/access_token', array( 'timeout' => 15, 'headers' => array( 'secret_key' => $c['app_secret'] ), 'body' => array( 'code' => 'bizcity-check', 'app_id' => $c['app_id'], 'grant_type' => 'authorization_code' ) ) ) );
		if ( null === $d ) {
			return array( 'ok' => false, 'status' => 'unreachable', 'code' => 'provider_unreachable', 'name' => '' );
		}
		$text = strtolower( (string) ( $d['error_name'] ?? '' ) . ' ' . (string) ( $d['error_description'] ?? '' ) . ' ' . (string) ( $d['message'] ?? '' ) );
		if ( false !== strpos( $text, 'app_id' ) || false !== strpos( $text, 'secret' ) || false !== strpos( $text, 'app id' ) ) {
			return array( 'ok' => false, 'status' => 'invalid', 'code' => 'own_app_secret_invalid', 'name' => '' );
		}
		// Zalo has no "check my app" call: a dummy code that fails for the CODE (not the app) is as far as we can prove before login
		return array( 'ok' => true, 'status' => 'pending_login', 'code' => '', 'name' => '' );
	}

	/* ---------------- own-app login (same popup + exchange contract as the Hub) ---------------- */

	public static function authorize_url( string $provider, array $scope_keys ): array {
		$c = self::creds( $provider );
		if ( '' === $c['app_id'] || '' === $c['app_secret'] ) {
			return array( 'ok' => false, 'code' => 'own_app_secret_invalid' );
		}
		$nonce    = bin2hex( random_bytes( 16 ) );
		$verifier = rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' );
		set_transient( 'bzc_own_st_' . $nonce, array( 'provider' => $provider, 'user_id' => get_current_user_id(), 'scopes' => $scope_keys, 'verifier' => $verifier ), self::STATE_TTL );
		$redirect = self::callback_url( $provider );
		if ( 'facebook' === $provider ) {
			$url = self::FB_DIALOG . '?' . http_build_query( array( 'client_id' => $c['app_id'], 'redirect_uri' => $redirect, 'response_type' => 'code', 'state' => $nonce, 'auth_type' => 'rerequest', 'scope' => implode( ',', self::FB_SCOPES ) ) );
		} elseif ( 'google' === $provider ) {
			$scopes = array( 'openid', 'https://www.googleapis.com/auth/userinfo.email' );
			foreach ( $scope_keys as $k ) {
				if ( isset( self::GOOGLE_SCOPES[ $k ] ) ) {
					$scopes[] = self::GOOGLE_SCOPES[ $k ];
				}
			}
			$url = self::GOOGLE_AUTH . '?' . http_build_query( array( 'client_id' => $c['app_id'], 'redirect_uri' => $redirect, 'response_type' => 'code', 'scope' => implode( ' ', $scopes ), 'access_type' => 'offline', 'prompt' => 'consent', 'include_granted_scopes' => 'true', 'state' => $nonce ) );
		} else {
			$challenge = rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' );
			$url       = self::ZALO_OAUTH . '/v4/oa/permission?' . http_build_query( array( 'app_id' => $c['app_id'], 'redirect_uri' => $redirect, 'code_challenge' => $challenge, 'state' => $nonce ) );
		}
		return array( 'ok' => true, 'authorize_url' => $url, 'popup_origin' => self::origin( home_url( '/' ) ), 'state' => $nonce );
	}

	/** Public callback: provider ⇒ site; renders the popup page that posts {exchange} to this site's own origin. */
	public static function callback( string $provider, array $q ): string {
		$nonce  = preg_replace( '/[^a-f0-9]/', '', (string) ( $q['state'] ?? '' ) );
		$st     = '' !== $nonce ? get_transient( 'bzc_own_st_' . $nonce ) : false;
		$origin = self::origin( home_url( '/' ) );
		if ( ! is_array( $st ) || ( $st['provider'] ?? '' ) !== $provider ) {
			return self::popup_html( '', array( 'ok' => false, 'code' => 'state_invalid' ) );
		}
		delete_transient( 'bzc_own_st_' . $nonce );
		$error = sanitize_key( (string) ( $q['error'] ?? '' ) );
		$code  = (string) ( $q['code'] ?? '' );
		// audit R-5: the "done" record is what the page polls when the browser cut window.opener (COOP) and postMessage cannot arrive
		$done = static function ( array $rec ) use ( $nonce, $provider, $st ) {
			set_transient( 'bzc_own_done_' . $nonce, $rec + array( 'provider' => $provider, 'user_id' => (int) $st['user_id'] ), self::EXCHANGE_TTL );
		};
		if ( '' !== $error || '' === $code ) {
			$why = 'access_denied' === $error ? 'user_cancelled' : 'provider_error';
			$done( array( 'ok' => false, 'code' => $why ) );
			return self::popup_html( $origin, array( 'ok' => false, 'provider' => $provider, 'code' => $why ) );
		}
		$payload = self::exchange_code( $provider, $code, $st );
		if ( is_wp_error( $payload ) ) {
			$done( array( 'ok' => false, 'code' => $payload->get_error_code() ) );
			return self::popup_html( $origin, array( 'ok' => false, 'provider' => $provider, 'code' => $payload->get_error_code() ) );
		}
		$exchange = bin2hex( random_bytes( 16 ) );
		set_transient( 'bzc_own_ex_' . hash( 'sha256', $exchange ), array( 'provider' => $provider, 'user_id' => (int) $st['user_id'], 'payload' => $payload ), self::EXCHANGE_TTL );
		$done( array( 'ok' => true, 'exchange' => $exchange ) );
		return self::popup_html( $origin, array( 'ok' => true, 'provider' => $provider, 'exchange' => $exchange, 'mode' => 'own' ) );
	}

	/**
	 * Poll of the login by its state (audit R-5). @return array{pending:bool}|array{error:string}|array{payload:array}
	 * Same user that started it; the record and the exchange it points at are single use.
	 */
	public static function take_by_state( string $provider, string $state ): array {
		$nonce = preg_replace( '/[^a-f0-9]/', '', $state );
		$rec   = '' !== $nonce ? get_transient( 'bzc_own_done_' . $nonce ) : false;
		if ( ! is_array( $rec ) ) {
			return array( 'pending' => true );
		}
		if ( $rec['provider'] !== $provider || (int) $rec['user_id'] !== get_current_user_id() ) {
			return array( 'error' => 'exchange_invalid' );
		}
		delete_transient( 'bzc_own_done_' . $nonce );
		if ( empty( $rec['ok'] ) ) {
			return array( 'error' => (string) ( $rec['code'] ?? 'provider_error' ) );
		}
		$payload = self::take_exchange( $provider, (string) $rec['exchange'] );
		return null === $payload ? array( 'error' => 'exchange_invalid' ) : array( 'payload' => $payload );
	}

	/** One-time read of an own-app exchange code (same user that started the login). @return array|null payload */
	public static function take_exchange( string $provider, string $exchange ): ?array {
		$k   = 'bzc_own_ex_' . hash( 'sha256', preg_replace( '/[^a-f0-9]/', '', $exchange ) );
		$row = get_transient( $k );
		if ( ! is_array( $row ) || $row['provider'] !== $provider || (int) $row['user_id'] !== get_current_user_id() ) {
			return null;
		}
		delete_transient( $k );
		return (array) $row['payload'];
	}

	/** @return array|WP_Error same payload shape as the Hub broker */
	private static function exchange_code( string $provider, string $code, array $st ) {
		$c        = self::creds( $provider );
		$redirect = self::callback_url( $provider );
		if ( 'facebook' === $provider ) {
			$t = self::json( wp_remote_get( self::FB_GRAPH . '/oauth/access_token?' . http_build_query( array( 'client_id' => $c['app_id'], 'client_secret' => $c['app_secret'], 'redirect_uri' => $redirect, 'code' => $code ) ), array( 'timeout' => 20 ) ) );
			if ( empty( $t['access_token'] ) ) {
				return new WP_Error( 'provider_error', 'token' );
			}
			$user_token = (string) $t['access_token'];
			$ll = self::json( wp_remote_get( self::FB_GRAPH . '/oauth/access_token?' . http_build_query( array( 'grant_type' => 'fb_exchange_token', 'client_id' => $c['app_id'], 'client_secret' => $c['app_secret'], 'fb_exchange_token' => $user_token ) ), array( 'timeout' => 20 ) ) );
			if ( ! empty( $ll['access_token'] ) ) {
				$user_token = (string) $ll['access_token'];
			}
			return self::fb_assets( $user_token );
		}
		if ( 'google' === $provider ) {
			$t = self::json( wp_remote_post( self::GOOGLE_TOKEN, array( 'timeout' => 20, 'body' => array( 'code' => $code, 'client_id' => $c['app_id'], 'client_secret' => $c['app_secret'], 'redirect_uri' => $redirect, 'grant_type' => 'authorization_code' ) ) ) );
			if ( empty( $t['access_token'] ) ) {
				return new WP_Error( 'provider_error', 'token' );
			}
			$me      = self::json( wp_remote_get( self::GOOGLE_INFO, array( 'timeout' => 15, 'headers' => array( 'Authorization' => 'Bearer ' . $t['access_token'] ) ) ) );
			$granted = array();
			foreach ( self::GOOGLE_SCOPES as $k => $uri ) {
				if ( false !== strpos( ' ' . (string) ( $t['scope'] ?? '' ) . ' ', ' ' . $uri . ' ' ) ) {
					$granted[] = $k;
				}
			}
			return array(
				'account'       => array( 'id' => (string) ( $me['id'] ?? '' ), 'email' => (string) ( $me['email'] ?? '' ) ),
				'granted'       => $granted,
				'declined'      => array_values( array_diff( (array) $st['scopes'], $granted ) ),
				'access_token'  => (string) $t['access_token'],
				'refresh_token' => (string) ( $t['refresh_token'] ?? '' ),
				'expires_in'    => (int) ( $t['expires_in'] ?? 3600 ),
				'scope'         => (string) ( $t['scope'] ?? '' ),
			);
		}
		$t = self::json( wp_remote_post( self::ZALO_OAUTH . '/v4/oa/access_token', array( 'timeout' => 20, 'headers' => array( 'secret_key' => $c['app_secret'] ), 'body' => array( 'code' => $code, 'app_id' => $c['app_id'], 'grant_type' => 'authorization_code', 'code_verifier' => (string) $st['verifier'] ) ) ) );
		if ( empty( $t['access_token'] ) ) {
			return new WP_Error( 'provider_error', 'token' );
		}
		$oa   = self::json( wp_remote_get( self::ZALO_API . '/v2.0/oa/getoa', array( 'timeout' => 15, 'headers' => array( 'access_token' => (string) $t['access_token'] ) ) ) );
		$data = is_array( $oa['data'] ?? null ) ? $oa['data'] : array();
		$id   = preg_replace( '/[^0-9]/', '', (string) ( $data['oa_id'] ?? $data['oaid'] ?? '' ) );
		if ( '' === $id ) {
			return new WP_Error( 'provider_error', 'oa' );
		}
		return array(
			'account'       => array( 'id' => $id, 'name' => sanitize_text_field( (string) ( $data['name'] ?? '' ) ) ),
			'access_token'  => (string) $t['access_token'],
			'refresh_token' => (string) ( $t['refresh_token'] ?? '' ),
			'expires_in'    => (int) ( $t['expires_in'] ?? 3600 ),
		);
	}

	private static function fb_get( string $path, int $timeout ) {
		return wp_remote_get( self::FB_GRAPH . $path, array( 'timeout' => $timeout ) );
	}

	/** Same shape as BizCity_Router_OAuth_Broker::fb_assets (Hub). */
	public static function fb_assets( string $user_token ) {
		$me    = self::json( wp_remote_get( self::FB_GRAPH . '/me?' . http_build_query( array( 'fields' => 'id,name', 'access_token' => $user_token ) ), array( 'timeout' => 15 ) ) );
		$perms = self::json( wp_remote_get( self::FB_GRAPH . '/me/permissions?' . http_build_query( array( 'access_token' => $user_token ) ), array( 'timeout' => 15 ) ) );
		$granted = array();
		$declined = array();
		foreach ( (array) ( $perms['data'] ?? array() ) as $p ) {
			if ( 'granted' === ( $p['status'] ?? '' ) ) {
				$granted[] = (string) $p['permission'];
			} elseif ( ! empty( $p['permission'] ) ) {
				$declined[] = (string) $p['permission'];
			}
		}
		// audit R-8: pages come from FOUR places, in this order, first non-empty wins — the same chain the old OAuth (class-facebook-oauth.php,
		// PHASE 0.31 S6) needed in production for Facebook Login for Business / Business-portfolio pages, which /me/accounts returns EMPTY.
		// The field list shrinks on error (a field a declined permission hides must not fail the whole login).
		$raw = array();
		$err = false;
		foreach ( array( 'id,name,access_token,category,tasks,followers_count', 'id,name,access_token,category,tasks', 'id,name,access_token,category' ) as $fields ) {
			$acc = (array) self::json( self::fb_get( '/me/accounts?' . http_build_query( array( 'fields' => $fields, 'limit' => 100, 'access_token' => $user_token ) ), 20 ) );
			if ( isset( $acc['data'] ) && is_array( $acc['data'] ) ) {
				$raw = $acc['data'];
				$err = false;
				break;
			}
			$err = true;
		}
		if ( ! $raw ) {
			$biz = (array) self::json( self::fb_get( '/me/businesses?' . http_build_query( array( 'fields' => 'id,name,owned_pages.limit(100){id,name,access_token,category},client_pages.limit(100){id,name,access_token,category}', 'access_token' => $user_token ) ), 25 ) );
			foreach ( (array) ( $biz['data'] ?? array() ) as $b ) {
				foreach ( array( 'owned_pages', 'client_pages' ) as $bucket ) {
					foreach ( (array) ( $b[ $bucket ]['data'] ?? array() ) as $pg ) {
						if ( ! empty( $pg['id'] ) && ! empty( $pg['access_token'] ) ) {
							$raw[] = $pg;
						}
					}
				}
			}
		}
		if ( ! $raw ) {
			$asg = (array) self::json( self::fb_get( '/me/assigned_pages?' . http_build_query( array( 'fields' => 'id,name,access_token,category', 'limit' => 100, 'access_token' => $user_token ) ), 25 ) );
			foreach ( (array) ( $asg['data'] ?? array() ) as $pg ) {
				if ( ! empty( $pg['id'] ) && ! empty( $pg['access_token'] ) ) {
					$raw[] = $pg;
				}
			}
		}
		if ( ! $raw && $err ) {
			return new WP_Error( 'provider_error', 'accounts' );
		}
		$seen = array();
		$acc  = array( 'data' => array() );
		foreach ( $raw as $pg ) {
			if ( ! empty( $pg['id'] ) && empty( $seen[ $pg['id'] ] ) ) {
				$seen[ $pg['id'] ] = true;
				$acc['data'][]     = $pg;
			}
		}
		$pages = array();
		foreach ( (array) ( $acc['data'] ?? array() ) as $pg ) {
			if ( empty( $pg['id'] ) ) {
				continue;
			}
			$tasks   = array_values( array_map( 'strval', (array) ( $pg['tasks'] ?? array() ) ) );
			$pages[] = array(
				'id'           => (string) $pg['id'],
				'name'         => sanitize_text_field( (string) ( $pg['name'] ?? '' ) ),
				'category'     => sanitize_text_field( (string) ( $pg['category'] ?? '' ) ),
				'followers'    => (int) ( $pg['followers_count'] ?? 0 ),
				'tasks'        => $tasks,
				'role_ok'      => ! $tasks || in_array( 'MANAGE', $tasks, true ) || in_array( 'MODERATE', $tasks, true ),
				'access_token' => (string) ( $pg['access_token'] ?? '' ),
			);
		}
		return array(
			'account'  => array( 'id' => (string) ( $me['id'] ?? '' ), 'name' => sanitize_text_field( (string) ( $me['name'] ?? '' ) ) ),
			'granted'  => $granted,
			'declined' => array_values( array_intersect( $declined, self::FB_SCOPES ) ),
			'missing'  => $granted ? array_values( array_diff( self::FB_REQUIRED, $granted ) ) : array(),
			'pages'    => $pages,
		);
	}

	/* ---------------- shared helpers ---------------- */

	public static function popup_html( string $origin, array $msg ): string {
		$msg  = array( 'source' => 'bizcity-oauth-broker', 'v' => 1 ) + $msg;
		$json = wp_json_encode( $msg );
		$text = ! empty( $msg['ok'] ) ? 'Đã kết nối. Cửa sổ này sẽ tự đóng.' : 'Chưa kết nối được. Đóng cửa sổ này và thử lại trên trang kết nối.';
		$post = '' !== $origin ? 'try{if(window.opener){window.opener.postMessage(' . $json . ',' . wp_json_encode( $origin ) . ');}}catch(e){}' : '';
		return '<!doctype html><html lang="vi"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>BizCity</title></head>'
			. '<body style="font:15px system-ui,sans-serif;padding:32px;color:#0f172a" data-error-code="' . esc_attr( (string) ( $msg['code'] ?? '' ) ) . '"><p>' . esc_html( $text ) . '</p>'
			. '<script>' . $post . 'setTimeout(function(){window.close();},' . ( ! empty( $msg['ok'] ) ? 400 : 4000 ) . ');</script></body></html>';
	}

	public static function origin( string $url ): string {
		$p = wp_parse_url( trim( $url ) );
		if ( ! is_array( $p ) || empty( $p['host'] ) || ! in_array( $p['scheme'] ?? '', array( 'https', 'http' ), true ) ) {
			return '';
		}
		return $p['scheme'] . '://' . strtolower( $p['host'] ) . ( ! empty( $p['port'] ) ? ':' . (int) $p['port'] : '' );
	}

	private static function json( $response ): ?array {
		if ( is_wp_error( $response ) ) {
			return null;
		}
		$d = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		return is_array( $d ) ? $d : array();
	}

	/* ---------------- secrets at rest (audit D-3) ---------------- */

	const SEAL_PREFIX  = 'bzs1:';
	const OPT_ENCRYPT  = 'bizcity_connect_encrypt_secrets'; // 'off' = stop sealing NEW writes (way back); sealed values are always opened
	const SEALED_FLAG  = 'bizcity_connect_secrets_sealed';
	/** Options owned by the older Facebook module that keep their name and their get_option() readers; only the stored form changes. */
	const SEALED_OPTIONS = array( 'bztfb_app_secret' );

	/**
	 * The Facebook App Secret lives in an option the older Facebook module reads with get_option() (class-facebook-oauth.php, the
	 * settings screen, the signature check). Those readers cannot be changed, so the value is sealed at the option layer: written as
	 * "bzs1:<aes-256-cbc>" and opened again by the option_ filter — every reader still sees the plain secret, a database dump does not.
	 * A value without the prefix is a legacy plaintext one and is returned unchanged until the next save (or the one-time admin migration).
	 */
	public static function boot_secret_filters(): void {
		foreach ( self::SEALED_OPTIONS as $name ) {
			add_filter( 'option_' . $name, array( __CLASS__, 'open_secret' ), 1 );
			add_filter( 'sanitize_option_' . $name, array( __CLASS__, 'seal_secret' ), 99 );
		}
		add_action( 'admin_init', array( __CLASS__, 'seal_existing' ) );
	}

	public static function sealing_enabled(): bool {
		return 'off' !== (string) get_option( self::OPT_ENCRYPT, 'auto' );
	}

	/** @param mixed $value */
	public static function seal_secret( $value ) {
		if ( ! is_string( $value ) || '' === $value || 0 === strpos( $value, self::SEAL_PREFIX ) || ! self::sealing_enabled() || ! function_exists( 'openssl_encrypt' ) ) {
			return $value;
		}
		return self::SEAL_PREFIX . self::encrypt( $value );
	}

	/** @param mixed $value */
	public static function open_secret( $value ) {
		if ( ! is_string( $value ) || 0 !== strpos( $value, self::SEAL_PREFIX ) ) {
			return $value; // legacy plaintext, empty or not a string: untouched
		}
		return self::decrypt( substr( $value, strlen( self::SEAL_PREFIX ) ) ); // a secret that cannot be opened (AUTH_KEY changed) reads as "not set": the owner types it again
	}

	/** One-time, admin only: re-save each plaintext secret so it is sealed. A single pass; flag option records it. */
	public static function seal_existing(): void {
		if ( ! self::sealing_enabled() || get_option( self::SEALED_FLAG ) ) {
			return;
		}
		foreach ( self::SEALED_OPTIONS as $name ) {
			$plain = get_option( $name, '' );
			if ( is_string( $plain ) && '' !== $plain ) {
				update_option( $name, $plain ); // sanitize_option_ seals it; the stored form changes, the value every reader sees does not
			}
		}
		update_option( self::SEALED_FLAG, '1', false );
	}

	/** Facebook pages waiting for the pick carry Page access tokens: they sit sealed in the transient, never as readable JSON. */
	public static function seal_array( array $data ): array {
		return self::sealing_enabled() && function_exists( 'openssl_encrypt' ) ? array( 'bzs' => self::encrypt( (string) wp_json_encode( $data ) ) ) : $data;
	}

	/** @param mixed $stored */
	public static function open_array( $stored ): array {
		if ( ! is_array( $stored ) ) {
			return array();
		}
		if ( isset( $stored['bzs'] ) ) {
			$out = json_decode( self::decrypt( (string) $stored['bzs'] ), true );
			return is_array( $out ) ? $out : array();
		}
		return $stored;
	}

	private static function key(): string {
		return hash( 'sha256', ( defined( 'AUTH_KEY' ) ? AUTH_KEY : 'bizcity-connect' ) . '|connect-own-app', true );
	}

	public static function encrypt( string $plain ): string {
		if ( '' === $plain ) {
			return '';
		}
		$iv = random_bytes( 16 );
		return base64_encode( $iv . openssl_encrypt( $plain, 'aes-256-cbc', self::key(), OPENSSL_RAW_DATA, $iv ) );
	}

	public static function decrypt( string $enc ): string {
		$raw = '' !== $enc ? base64_decode( $enc, true ) : false;
		if ( false === $raw || strlen( $raw ) < 17 ) {
			return '';
		}
		$out = openssl_decrypt( substr( $raw, 16 ), 'aes-256-cbc', self::key(), OPENSSL_RAW_DATA, substr( $raw, 0, 16 ) );
		return false === $out ? '' : $out;
	}
}

BizCity_Connect_Own_App::boot_secret_filters();
