<?php
/**
 * The gate of panel-link@1 (PHASE-0.122 doc 20 §4.2–4.3) on the site's own domain:
 *
 *   GET  /?bzpanel=<token>   ⇒ gate page ("Mở bảng nhanh"); NEVER uses the ticket — Zalo's link preview fetches every URL (R-PL-4)
 *   POST /?bzpanel=<token>   ⇒ uses the ticket, sets the panel cookie, 303 to the clean URL (no token left in the address bar)
 *   GET  /?bzpanel=open      ⇒ the panel app (owner-panel.js) when the cookie holds a live session, else "Link đã dùng hoặc hết hạn"
 *
 * Inside Zalo's in-app browser a Google panel shows "Mở bằng Chrome" (Android intent) / "••• › Mở bằng trình duyệt" (iPhone) — owner 2026-10-18.
 * The ticket is used only where the person taps "Mở", so moving to another browser keeps the link alive.
 *
 * [2026-10-18 05:10 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.122 S122-P2 — new file. Loaded once by require_once in bootstrap.php.
 *
 * @package BizCity_Twin_AI
 * @subpackage Channel_Gateway
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_Panel_Gate {

	const QV = 'bzpanel';

	public static function init(): void {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_handle' ), 0 );
	}

	/** Panel page URL (clean, no token); `$panel` lands the app on that panel; `$composio` marks the return from the provider. */
	public static function open_url( string $panel = '', bool $composio = false ): string {
		$q = array( self::QV => 'open' );
		if ( '' !== $panel ) {
			$q['p'] = $panel;
		}
		if ( $composio ) {
			$q['composio_return'] = '1';
		}
		return add_query_arg( $q, home_url( '/' ) );
	}

	/** Link the assistant sends. */
	public static function ticket_url( string $token ): string {
		return add_query_arg( self::QV, rawurlencode( $token ), home_url( '/' ) );
	}

	public static function maybe_handle(): void {
		if ( ! isset( $_GET[ self::QV ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$v = sanitize_text_field( wp_unslash( (string) $_GET[ self::QV ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		// [2026-10-18 11:00 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.122 review fix — the gate form posts to a CLEAN url (`?bzpanel=go`) with the token in a hidden field: the token no longer sits in a POST request line (server / proxy logs)
		$posted = 'go' === $v && 'POST' === strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) && isset( $_POST['t'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['t'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( '' !== $posted ) {
			$v = $posted;
		}
		self::no_cache_headers();
		if ( 'open' === $v ) {
			self::render_app();
			exit;
		}
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( 'POST' === strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) ) {
			$nonce = isset( $_POST['_bzp'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['_bzp'] ) ) : '';
			if ( ! wp_verify_nonce( $nonce, 'bzpanel_open' ) ) {
				self::render_dead( 'invalid' );
				exit;
			}
			// [2026-10-23 08:11 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.128 S128-CL4 — a TwinZalo ticket of the owner of a number (or a site admin) also remembers this device (doc 67 A)
			$pk = BizCity_Panel_Link::peek( $v, false );
			$r  = BizCity_Panel_Link::consume( $v, $ua, self::may_remember( (int) ( $pk['ticket']['user_id'] ?? 0 ) ) );
			if ( empty( $r['ok'] ) ) {
				self::render_dead( (string) $r['code'] );
				exit;
			}
			self::set_cookie( (string) $r['cookie'], (int) $r['max_age'] );
			if ( ! empty( $r['device'] ) ) {
				self::set_cookie( (string) $r['device'], (int) $r['device_max_age'], BizCity_Panel_Link::DEV_COOKIE );
			}
			wp_safe_redirect( self::open_url( (string) $r['panel']['id'] ), 303 );
			exit;
		}
		// [2026-10-18 11:00 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.122 review fix — a GET (Zalo preview crawler, a second tap, anyone holding the used URL) never counts toward key rotation: it must not be able to kill the owner's open page
		$p = BizCity_Panel_Link::peek( $v, false );
		if ( empty( $p['ok'] ) ) {
			self::render_dead( (string) $p['code'] );
			exit;
		}
		self::render_gate( $v, $p['panel'], (array) $p['ticket'], $ua );
		exit;
	}

	/** The panel cookie: HttpOnly, Secure on https, SameSite=Lax (sent on the top-level return from Composio / Google, never on a cross-site POST). */
	public static function set_cookie( string $value, int $max_age, string $name = '' ): void {
		if ( headers_sent() ) {
			return;
		}
		setcookie( '' !== $name ? $name : BizCity_Panel_Link::COOKIE, $value, array(
			'expires'  => '' === $value ? time() - 3600 : time() + $max_age,
			'path'     => '/',
			'secure'   => is_ssl() || 0 === strpos( home_url( '/' ), 'https://' ), // behind a proxy that hides https, the site url still says it
			'httponly' => true,
			'samesite' => 'Lax',
		) );
	}

	/** [2026-10-23 08:11 PM Johnny Chu - Chu Hoàng Anh] S128-CL4 — the device cookie of this request ('' when none). */
	public static function current_device_cookie(): string {
		return isset( $_COOKIE[ BizCity_Panel_Link::DEV_COOKIE ] ) ? sanitize_text_field( wp_unslash( (string) $_COOKIE[ BizCity_Panel_Link::DEV_COOKIE ] ) ) : '';
	}

	/** S128-CL4 — only the owner of a number or a site admin gets a remembered device (staff never: doc 67 A). */
	public static function may_remember( int $uid ): bool {
		if ( $uid <= 0 ) {
			return false;
		}
		$rows = class_exists( 'BizCity_Twin_Web_Turn' ) && method_exists( 'BizCity_Twin_Web_Turn', 'owned_rows' ) ? BizCity_Twin_Web_Turn::owned_rows( $uid ) : array();
		return BizCity_Panel_Link::device_allowed( $rows, function_exists( 'user_can' ) && user_can( $uid, 'manage_options' ) );
	}

	/** Live session of this request's cookie, or null. */
	public static function current_session(): ?array {
		$c = isset( $_COOKIE[ BizCity_Panel_Link::COOKIE ] ) ? sanitize_text_field( wp_unslash( (string) $_COOKIE[ BizCity_Panel_Link::COOKIE ] ) ) : '';
		return '' === $c ? null : BizCity_Panel_Link::session( $c );
	}

	/**
	 * Pure: the "open in a real browser" help for a Zalo in-app browser on a Google panel; '' when not needed.
	 * Android: an intent URL that opens the SAME gate URL in Chrome; iPhone: the Zalo menu path.
	 */
	public static function webview_help( string $ua, bool $google, string $gate_url ): array {
		if ( ! $google || ! BizCity_Panel_Link::is_zalo_webview( $ua ) ) {
			return array();
		}
		if ( 1 === preg_match( '/Android/i', $ua ) ) {
			$no_scheme = preg_replace( '#^https?://#', '', $gate_url );
			return array( 'os' => 'android', 'intent' => 'intent://' . $no_scheme . '#Intent;scheme=https;package=com.android.chrome;end' );
		}
		return array( 'os' => 'ios' );
	}

	// ── pages ────────────────────────────────────────────────────────────

	private static function render_gate( string $token, array $panel, array $ticket, string $ua ): void {
		$uid   = (int) ( $ticket['user_id'] ?? 0 );
		$who   = self::masked_name( $uid );
		$left  = max( 1, (int) ceil( ( (int) $ticket['exp'] - time() ) / 60 ) );
		$help  = self::webview_help( $ua, ! empty( $panel['google'] ), self::ticket_url( $token ) );
		$body  = '<div class="big">🔐</div><h1>' . esc_html( (string) $panel['label'] ) . '</h1>';
		$body .= '<p class="mute">Link của ' . esc_html( $who ) . ' · còn ' . (int) $left . ' phút · dùng một lần</p>';
		$body .= '<form method="post" action="' . esc_url( add_query_arg( self::QV, 'go', home_url( '/' ) ) ) . '"><input type="hidden" name="t" value="' . esc_attr( $token ) . '">' . wp_nonce_field( 'bzpanel_open', '_bzp', false, false ) . '<button type="submit" class="btn">Mở bảng nhanh</button></form>';
		if ( $help ) {
			$body .= '<div class="warn">⚠️ Bạn đang mở trong Zalo. Google không cho đăng nhập ở đây.<br>';
			$body .= 'android' === $help['os']
				? '<a class="lnk" href="' . esc_attr( $help['intent'] ) . '">Mở bằng Chrome</a> rồi bấm "Mở bảng nhanh".'
				: 'Bấm <b>•••</b> ở góc trên › <b>Mở bằng trình duyệt</b>, rồi bấm "Mở bảng nhanh".';
			$body .= '<br>Link vẫn dùng được ở trình duyệt mới.</div>';
		}
		$body .= '<p class="small">Không phải bạn xin link này? Nhắn trợ lý "đổi khoá".</p>';
		self::page( (string) $panel['label'], $body );
	}

	private static function render_dead( string $code ): void {
		status_header( 410 );
		$title = 'key_reset' === $code ? 'Link đã bị khoá' : 'Link đã dùng hoặc hết hạn';
		$say   = 'key_reset' === $code ? 'Khoá bảo mật vừa được đổi nên mọi link cũ đều khoá lại.' : 'Để an toàn, mỗi link chỉ mở được một lần và có hạn.';
		$body  = '<div class="big">⌛</div><h1>' . esc_html( $title ) . '</h1><p class="mute">' . esc_html( $say ) . '</p>';
		$body .= '<div class="card">Nhắn trợ lý trên Zalo: <b>"mở lại bảng nhanh"</b></div>';
		self::page( $title, $body );
	}

	private static function render_app(): void {
		$s = self::current_session();
		if ( ! $s ) {
			// [2026-10-23 08:11 PM Johnny Chu - Chu Hoàng Anh] S128-CL4 — no live session but a remembered device ⇒ a new READ-ONLY TwinZalo session, then the same page again
			$dev = self::current_device_cookie();
			$try = ! isset( $_GET['dv'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- one try only: a browser that drops cookies must not loop
			if ( '' !== $dev && $try ) {
				$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				$r  = BizCity_Panel_Link::reopen( $dev, $ua );
				if ( ! empty( $r['ok'] ) ) {
					self::set_cookie( (string) $r['cookie'], (int) $r['max_age'] );
					wp_safe_redirect( add_query_arg( 'dv', '1', self::open_url( BizCity_Panel_Link::DEVICE_PANEL ) ), 303 );
					return;
				}
				self::set_cookie( '', 0, BizCity_Panel_Link::DEV_COOKIE ); // a forgotten / renewed-key device: drop the stale cookie
			}
			self::render_dead( 'gone' );
			return;
		}
		$dir  = dirname( __DIR__, 2 ) . '/assets/dist/';
		$url  = plugins_url( 'assets/dist/', dirname( __DIR__, 2 ) . '/bootstrap.php' );
		$ver  = is_readable( $dir . 'owner-panel.js' ) ? (string) filemtime( $dir . 'owner-panel.js' ) : '0';
		$boot = array(
			'restUrl' => esc_url_raw( rest_url( 'bizcity-panel/v1/' ) ),
			'homeUrl' => esc_url_raw( home_url( '/' ) ),
		);
		$head = '<link rel="stylesheet" href="' . esc_url( $url . 'owner-panel.css?ver=' . $ver ) . '">';
		$body = '<div id="bizcity-owner-panel-root"></div><script>window.BIZCITY_PANEL_BOOT=' . wp_json_encode( $boot ) . ';</script><script src="' . esc_url( $url . 'owner-panel.js?ver=' . $ver ) . '"></script>';
		self::page( 'Bảng nhanh', $body, $head, true );
	}

	private static function page( string $title, string $body, string $head = '', bool $app = false ): void {
		header( 'Content-Type: text/html; charset=utf-8' );
		$css = 'body{margin:0;background:#f6f7fb;color:#0f172a;font:15px/1.5 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif}'
			. '.wrap{max-width:420px;margin:0 auto;padding:40px 16px;text-align:center;display:flex;flex-direction:column;gap:14px}'
			. 'h1{font-size:19px;margin:0}.big{font-size:42px}.mute{color:#64748b;margin:0}.small{font-size:12px;color:#64748b}'
			. '.btn{width:100%;border:0;border-radius:10px;padding:13px;font-size:16px;font-weight:600;background:#4f46e5;color:#fff;cursor:pointer}'
			. '.warn{background:#fef3c7;color:#78350f;border-radius:10px;padding:10px;font-size:13.5px;text-align:left}'
			. '.card{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:12px}.lnk{color:#0068ff;font-weight:600}';
		echo '<!doctype html><html lang="vi"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
			. '<meta name="robots" content="noindex,nofollow"><meta name="referrer" content="no-referrer"><title>' . esc_html( $title ) . '</title>'
			. '<style>' . $css . '</style>' . $head . '</head><body>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			. ( $app ? $body : '<div class="wrap">' . $body . '</div>' ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			. '</body></html>';
	}

	private static function no_cache_headers(): void {
		if ( headers_sent() ) {
			return;
		}
		nocache_headers();
		header( 'Referrer-Policy: no-referrer' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'X-Frame-Options: DENY' );
	}

	/** "Anh H***" style: first letter of the display name only. */
	public static function masked_name( int $uid ): string {
		$u    = $uid > 0 ? get_userdata( $uid ) : null;
		$name = $u ? trim( (string) $u->display_name ) : '';
		if ( '' === $name ) {
			return 'chủ cửa hàng';
		}
		return mb_substr( $name, 0, 1 ) . '***';
	}
}
