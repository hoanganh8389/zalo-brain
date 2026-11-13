<?php
/**
 * R-CELL-SERVICE-BOUNDARY CSB-8 — REST root shim for subfolder installs and "Plain" permalinks (PHASE-0.138 S138-CL11, F32).
 *
 * Several shipped bundles call the site's own REST with a fixed '/wp-json/…' path. That path is wrong when WordPress lives in a
 * subfolder (http://localhost/wordpress/ ⇒ /wordpress/wp-json/…) or when Settings → Permalinks is "Plain" (⇒ ?rest_route=/…).
 * The real fix is in the bundle sources (use the rest_url() the PHP boot config passes) and ships with their next build; until then
 * this shim — printed only when it is needed — rewrites SAME-ORIGIN requests whose path starts with '/wp-json/' to rest_url(),
 * for window.fetch (string, URL, Request) and XMLHttpRequest.prototype.open. Cross-origin URLs (the Hub at bizcity.vn) are never
 * touched. When the gateway (Hub) shares this site's origin (a subsite of the Hub's own network) the shim stays off: a '/wp-json/'
 * there may be the Hub on purpose.
 *
 * JS_REWRITE is the pure rewrite function; core/channel-gateway/docs/PHASE-0.138-COMMUNITY-RELEASE-CELL-SERVICES/tools/
 * rest-root-shim-selfcheck.mjs keeps a copy, unit-tests it and fails when the two drift.
 *
 * Way out: filter `bizcity_rest_root_shim_enabled` ⇒ false.
 *
 * [2026-10-25 Johnny Chu - Chu Hoàng Anh] PHASE-0.138 S138-CL11 — new file.
 *
 * @package BizCity_Twin_AI
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_REST_Root_Shim', false ) ) {
	return;
}

final class BizCity_REST_Root_Shim {

	/** Pure: (input URL, page href, rest root) ⇒ rewritten absolute URL, or null when the request must stay as it is. Keep in sync with the selfcheck copy. */
	const JS_REWRITE = <<<'JS'
function bizcityRestRewrite(input, pageHref, restRoot) {
	var u, root;
	try { u = new URL(String(input), pageHref); root = new URL(String(restRoot), pageHref); } catch (e) { return null; }
	if (u.origin !== root.origin) { return null; }
	var p = u.pathname;
	if (p !== '/wp-json' && p.indexOf('/wp-json/') !== 0) { return null; }
	var route = p.slice(8) || '/';
	var keep = function (q) {
		return q.replace(/^\?/, '').split('&').filter(function (kv) { return kv !== '' && kv.split('=')[0] !== 'rest_route'; });
	};
	if (/(^|[?&])rest_route=/.test(root.search)) {
		return root.origin + root.pathname + '?' + ['rest_route=' + route].concat(keep(root.search), keep(u.search)).join('&') + u.hash;
	}
	var base = root.pathname.replace(/\/+$/, '');
	if (base === '/wp-json') { return null; }
	return root.origin + base + route + u.search + u.hash;
}
JS;

	/** @var bool */
	private static $printed = false;

	public static function init(): void {
		// admin: before the head scripts (print_head_scripts runs at admin_print_scripts 20); admin_head is the belt for screens that skip it.
		add_action( 'admin_print_scripts', array( __CLASS__, 'print_shim' ), 0 );
		add_action( 'admin_head', array( __CLASS__, 'print_shim' ), 0 );
		// front: wp_head 0 runs before wp_print_head_scripts (9)
		add_action( 'wp_head', array( __CLASS__, 'print_shim' ), 0 );
	}

	/** True when '/wp-json/' is not this site's REST root: WordPress in a subfolder, or Plain permalinks. */
	public static function needed(): bool {
		$path    = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		$subdir  = '' !== $path && '/' !== $path;
		$plain   = '' === (string) get_option( 'permalink_structure', '' );
		$needed  = $subdir || $plain;
		if ( $needed ) {
			// the Hub on this very origin (a subsite of the gateway's own network): '/wp-json/' may mean the Hub — stay off
			$gateway = (string) get_option( 'bizcity_llm_gateway_url', 'https://bizcity.vn' );
			if ( '' !== $gateway && self::origin( $gateway ) === self::origin( home_url( '/' ) ) ) {
				$needed = false;
			}
		}
		return (bool) apply_filters( 'bizcity_rest_root_shim_enabled', $needed );
	}

	/** scheme://host[:port], lower-case; '' when unparsable. */
	public static function origin( string $url ): string {
		$p = wp_parse_url( $url );
		if ( empty( $p['host'] ) ) {
			return '';
		}
		$scheme = strtolower( (string) ( $p['scheme'] ?? 'https' ) );
		return $scheme . '://' . strtolower( (string) $p['host'] ) . ( isset( $p['port'] ) ? ':' . (int) $p['port'] : '' );
	}

	public static function print_shim(): void {
		if ( self::$printed || ! self::needed() ) {
			return;
		}
		self::$printed = true;
		$cfg = wp_json_encode( array( 'root' => esc_url_raw( rest_url() ) ), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES );
		// phpcs:disable WordPress.Security.EscapeOutput -- constant JS + wp_json_encode (JSON_HEX_TAG) config
		echo "<script id=\"bizcity-rest-root-shim\">\n";
		echo "/* BizCity Twin Brain — PHASE-0.138 S138-CL11: same-origin '/wp-json/' ⇒ rest_url() (subfolder install / Plain permalinks) */\n";
		echo '(function (cfg) {' . "\n";
		echo "\tif (!cfg || !cfg.root || window.__bizcityRestShim) { return; }\n";
		echo "\twindow.__bizcityRestShim = true;\n";
		echo "\tvar rewrite = " . self::JS_REWRITE . ";\n";
		echo <<<'JS'
	var fix = function (url) {
		try { var r = rewrite(url, window.location.href, cfg.root); return r === null ? url : r; } catch (e) { return url; }
	};
	var nativeFetch = window.fetch;
	if (typeof nativeFetch === 'function') {
		window.fetch = function (input, init) {
			var args = Array.prototype.slice.call(arguments);
			try {
				if (typeof input === 'string') {
					args[0] = fix(input);
				} else if (typeof URL !== 'undefined' && input instanceof URL) {
					var s = fix(input.href);
					if (s !== input.href) { args[0] = s; }
				} else if (typeof Request !== 'undefined' && input instanceof Request) {
					var n = fix(input.url);
					if (n !== input.url) { args[0] = new Request(n, input); }
				}
			} catch (e) { args[0] = input; }
			return nativeFetch.apply(window, args);
		};
	}
	if (window.XMLHttpRequest && XMLHttpRequest.prototype && XMLHttpRequest.prototype.open) {
		var nativeOpen = XMLHttpRequest.prototype.open;
		XMLHttpRequest.prototype.open = function (method, url) {
			var args = Array.prototype.slice.call(arguments);
			if (url !== undefined && url !== null) {
				args[1] = fix(typeof url === 'string' ? url : String(url));
			}
			return nativeOpen.apply(this, args);
		};
	}
JS;
		echo "\n})(" . $cfg . ");\n</script>\n";
		// phpcs:enable
	}
}
