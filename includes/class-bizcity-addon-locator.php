<?php
/**
 * Add-on locator — where code that left the main plugin now lives (R-LEAN-4, Q-W16-1).
 *
 * [2026-10-01 Claude Opus 5.5] CORE-REDUCTION WP-16 B-4 S1 — TwinBrain / Automation leave bizcity-twin-ai for the
 * sibling plugin bizcity-twin-brain-addon. The main plugin keeps its request gates (it decides WHEN a part loads);
 * this class only answers WHERE the part's files are.
 *
 * [2026-10-05 08:23 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.91-AX-0 — Automation is its own plugin now, `bizcity-automation`,
 * separate from bizcity-twin-brain-addon and from core/ (both tried earlier today, both reverted — owner decision:
 * Automation is sold as a standalone Pro/Add-on, so it needs its own install/activate/deactivate toggle independent
 * of TwinBrain). `automation/…` resolves against bizcity-automation; every other relative path (memory/…,
 * knowledge-legacy/…) still resolves against bizcity-twin-brain-addon exactly as before — zero change for those callers.
 *
 * [2026-10-12 11:24 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.102 ST102-L31 (R-ADDON-HOST v1, standards/ADDON-HOST-STANDARD.md AH-6/AH-9) —
 * an add-on is found by its MAIN FILE NAME in ANY plugin folder, not by `<slug>/<slug>.php`, so a fork or a renamed folder
 * (`bizcity-automation-main/`) still resolves. Because a bare file name is not proof (third-party
 * `bizcity-automation-legacy/bizcity-automation.php` is a different plugin), a non-canonical folder is accepted only
 * when its main file carries the header `Zalo Brain Addon: <part>`. The canonical folder name is accepted without
 * the header so that deploying this class BEFORE the add-ons carry the header changes nothing. Adds the `twinweb/…` part
 * (bizcity-twinweb-addon). Public API unchanged: mode(), dir(), is_active(), available(), file().
 *
 * Load mode (wp-config.php, optional):
 *   define( 'BIZCITY_BRAIN_ADDON_LOAD', 'auto' | 'active' | 'off' );  // bizcity-twin-brain-addon (TwinBrain, memory, knowledge-legacy)
 *   define( 'BIZCITY_AUTOMATION_LOAD',  'auto' | 'active' | 'off' );  // bizcity-automation (Automation only)
 *   define( 'BIZCITY_TWINWEB_LOAD',     'auto' | 'active' | 'off' );  // bizcity-twinweb-addon (/gpt/) — default 'active'
 *   auto   — load from the plugin folder when it is present, activated or not, so a deploy that uploads
 *            the folder changes nothing for users (default for brain-addon and Automation);
 *   active — load only when that plugin is activated (site or network) (default for twinweb: users install it themselves);
 *   off    — never load that plugin's parts.
 * WordPress loads plugins in the alphabetical order of their path, so the main plugin cannot wait for an add-on
 * constant; it checks the files directly.
 */

defined( 'ABSPATH' ) || exit;

// [2026-10-12 11:48 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.102 ST102-L31 — NOT class_exists(): the class below is declared at compile time (early binding), so that test is
// always true here and the early return skipped everything after the class (the plugin-change hooks and the bizcity_twinweb_* helpers).
if ( defined( 'BIZCITY_ADDON_LOCATOR_LOADED' ) ) {
	return;
}
define( 'BIZCITY_ADDON_LOCATOR_LOADED', true );

final class BizCity_Addon_Locator {

	const SLUG = 'bizcity-twin-brain-addon';

	/** [2026-10-05 08:23 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.91-AX-0 — Automation's own plugin slug, independent of SLUG above. */
	const AUTOMATION_SLUG = 'bizcity-automation';

	/** Parts resolved against AUTOMATION_SLUG instead of SLUG. One top-level folder name per entry. */
	const AUTOMATION_PARTS = array( 'automation' );

	/** [2026-10-12 11:24 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.102 ST102-L31 — bizcity-twinweb-addon (/gpt/). */
	const TWINWEB_SLUG = 'bizcity-twinweb-addon';

	/** Parts resolved against TWINWEB_SLUG. */
	const TWINWEB_PARTS = array( 'twinweb' );

	/** Option that remembers where a renamed add-on folder was found (and, briefly, that none was). */
	const PATHS_OPTION = 'bizcity_addon_paths';

	/** Seconds a "not found anywhere" answer is trusted before the plugins folder is scanned again. */
	const NEGATIVE_TTL = 300;

	/** @var array<string,string> */
	private static $cache = array();

	/** @var array<string,string> tag => absolute dir with trailing slash ('' = not found), per request */
	private static $dirs = array();

	/**
	 * One row per add-on: the `Zalo Brain Addon:` header tag is the key.
	 * flat = the leading part name of a relative path is only routing (file lives at the plugin root); non-flat = the path is used as is.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private static function registry(): array {
		return array(
			'twin-brain' => array( 'slug' => self::SLUG,           'const' => 'BIZCITY_BRAIN_ADDON_LOAD', 'default' => 'auto' ),
			'automation' => array( 'slug' => self::AUTOMATION_SLUG, 'const' => 'BIZCITY_AUTOMATION_LOAD',  'default' => 'auto' ),
			'twinweb'    => array( 'slug' => self::TWINWEB_SLUG,    'const' => 'BIZCITY_TWINWEB_LOAD',     'default' => 'active' ),
		);
	}

	private static function mode_of( string $tag ): string {
		$row  = self::registry()[ $tag ];
		$mode = defined( $row['const'] ) ? strtolower( (string) constant( $row['const'] ) ) : $row['default'];
		return in_array( $mode, array( 'auto', 'active', 'off' ), true ) ? $mode : $row['default'];
	}

	public static function mode(): string {
		return self::mode_of( 'twin-brain' );
	}

	private static function plugins_base(): string {
		$base = defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR : dirname( __DIR__, 2 );
		return rtrim( str_replace( '\\', '/', $base ), '/' ) . '/';
	}

	private static function plugin_dir( string $slug ): string {
		return self::plugins_base() . $slug . '/';
	}

	/** True when the first 8 KB of $file carry `Zalo Brain Addon: <tag>` (R-ADDON-HOST AH-9). */
	private static function has_header( string $file, string $tag ): bool {
		$fh = @fopen( $file, 'rb' );
		if ( ! $fh ) {
			return false;
		}
		$head = (string) fread( $fh, 8192 );
		fclose( $fh );
		if ( ! preg_match( '/^[ \t\/*#@]*Zalo Brain Addon:[ \t]*([^\r\n]+)/mi', $head, $m ) ) {
			return false;
		}
		return strtolower( trim( preg_replace( '/\*\/.*$/', '', $m[1] ) ) ) === $tag;
	}

	private static function plugin_basename_active( string $basename ): bool {
		if ( function_exists( 'get_option' ) && in_array( $basename, (array) get_option( 'active_plugins', array() ), true ) ) {
			return true;
		}
		if ( function_exists( 'is_multisite' ) && is_multisite() && function_exists( 'get_site_option' ) ) {
			return isset( ( (array) get_site_option( 'active_sitewide_plugins', array() ) )[ $basename ] );
		}
		return false;
	}

	/** @return array<string,array{dir:string,t:int}> */
	private static function remembered(): array {
		$v = function_exists( 'get_option' ) ? get_option( self::PATHS_OPTION, array() ) : array();
		return is_array( $v ) ? $v : array();
	}

	private static function remember( string $tag, string $dirname ): void {
		if ( ! function_exists( 'update_option' ) ) {
			return;
		}
		$all = self::remembered();
		if ( isset( $all[ $tag ]['dir'] ) && $all[ $tag ]['dir'] === $dirname && '' !== $dirname ) {
			return; // unchanged — no write on the hot path
		}
		$all[ $tag ] = array( 'dir' => $dirname, 't' => time() );
		update_option( self::PATHS_OPTION, $all, false );
	}

	/** Drop the remembered paths (hooked to plugin activation / deactivation / upgrade). */
	public static function forget(): void {
		self::$dirs  = array();
		self::$cache = array();
		if ( function_exists( 'delete_option' ) ) {
			delete_option( self::PATHS_OPTION );
		}
	}

	/**
	 * Absolute folder (trailing slash) of the add-on carrying header tag $tag, '' when it is on no disk.
	 * Order: canonical folder name (no header needed) → remembered folder → scan of every plugin folder for the main file name.
	 */
	public static function addon_dir( string $tag ): string {
		if ( isset( self::$dirs[ $tag ] ) ) {
			return self::$dirs[ $tag ];
		}
		$reg = self::registry();
		if ( ! isset( $reg[ $tag ] ) ) {
			return self::$dirs[ $tag ] = '';
		}
		$slug = $reg[ $tag ]['slug'];
		$main = $slug . '.php';

		// Canonical folder name: accepted as before (a folder is enough, no header needed) so this class can ship before the add-ons carry the header.
		$canonical = self::plugin_dir( $slug );
		if ( is_dir( $canonical ) ) {
			return self::$dirs[ $tag ] = $canonical;
		}

		$mem = self::remembered();
		if ( isset( $mem[ $tag ] ) ) {
			$name = (string) $mem[ $tag ]['dir'];
			if ( '' !== $name ) {
				$cand = self::plugins_base() . $name . '/';
				if ( is_file( $cand . $main ) && self::has_header( $cand . $main, $tag ) ) {
					return self::$dirs[ $tag ] = $cand;
				}
			} elseif ( ( time() - (int) $mem[ $tag ]['t'] ) < self::NEGATIVE_TTL ) {
				return self::$dirs[ $tag ] = ''; // scanned a moment ago and found nothing
			}
		}

		$found = array();
		foreach ( (array) glob( self::plugins_base() . '*/' . $main ) as $path ) {
			if ( self::has_header( $path, $tag ) ) {
				$found[] = basename( dirname( $path ) );
			}
		}
		sort( $found );
		$pick = '';
		foreach ( $found as $name ) { // an activated copy wins over a dormant one; never load two (same class names = fatal)
			if ( self::plugin_basename_active( $name . '/' . $main ) ) {
				$pick = $name;
				break;
			}
		}
		if ( '' === $pick && $found ) {
			$pick = $found[0];
		}
		self::remember( $tag, $pick );
		return self::$dirs[ $tag ] = ( '' === $pick ) ? '' : self::plugins_base() . $pick . '/';
	}

	/** Brain add-on folder; the canonical path when the add-on is on no disk (callers test is_dir() on it). */
	public static function dir(): string {
		$found = self::addon_dir( 'twin-brain' );
		return '' !== $found ? $found : self::plugin_dir( self::SLUG );
	}

	private static function addon_is_active( string $tag ): bool {
		$found = self::addon_dir( $tag );
		if ( '' === $found ) {
			return false;
		}
		$slug = self::registry()[ $tag ]['slug'];
		return self::plugin_basename_active( basename( rtrim( $found, '/' ) ) . '/' . $slug . '.php' );
	}

	public static function is_active(): bool {
		return self::addon_is_active( 'twin-brain' );
	}

	/** Whether the add-on $tag may load under its load mode (found on disk, and activated when the mode says so). */
	private static function addon_available( string $tag ): bool {
		$mode = self::mode_of( $tag );
		if ( 'off' === $mode || '' === self::addon_dir( $tag ) ) {
			return false;
		}
		return 'auto' === $mode || self::addon_is_active( $tag );
	}

	/** Availability of bizcity-twin-brain-addon (TwinBrain, memory, knowledge-legacy) — unrelated to Automation. */
	public static function available(): bool {
		return self::addon_available( 'twin-brain' );
	}

	/** Availability of bizcity-automation specifically. */
	private static function automation_available(): bool {
		return self::addon_available( 'automation' );
	}

	/**
	 * Absolute path of a file inside the right sibling plugin ('' when unavailable or the file is missing).
	 *
	 * @param string $relative e.g. 'automation/bootstrap.php', 'twinweb/bootstrap.php', 'memory/bootstrap.php', 'knowledge-legacy/bootstrap.php'
	 */
	public static function file( string $relative ): string {
		if ( isset( self::$cache[ $relative ] ) ) {
			return self::$cache[ $relative ];
		}
		$part = strtok( ltrim( $relative, '/' ), '/' );

		if ( in_array( $part, self::AUTOMATION_PARTS, true ) ) {
			$path = '';
			if ( self::automation_available() ) {
				// [2026-10-06 00:10 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.91-AX-0 — the plugin is flat: `automation/` is only the routing
				// part name, so 'automation/bootstrap.php' is <plugin>/bootstrap.php (no automation/ subfolder on disk any more).
				$inside    = substr( ltrim( $relative, '/' ), strlen( $part ) );
				$candidate = self::addon_dir( 'automation' ) . ltrim( $inside, '/' );
				$path      = ( '' !== trim( $inside, '/' ) && is_file( $candidate ) ) ? $candidate : '';
			}
			return self::$cache[ $relative ] = $path;
		}

		if ( in_array( $part, self::TWINWEB_PARTS, true ) ) {
			// [2026-10-12 12:19 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.102 ST102-L16 — no fallback to a bundled modules/twinweb any more: the bundle does not ship it, and a stale folder left on a server by an upload (uploads never delete) must never be loaded on top of core/twinweb-axis — that would redeclare classes. The /gpt/ part is bizcity-twinweb-addon or nothing.
			$path   = '';
			$inside = ltrim( substr( ltrim( $relative, '/' ), strlen( $part ) ), '/' );
			if ( '' !== $inside && self::addon_available( 'twinweb' ) ) {
				$candidate = self::addon_dir( 'twinweb' ) . $inside;
				$path      = is_file( $candidate ) ? $candidate : '';
			}
			return self::$cache[ $relative ] = $path;
		}

		$path = '';
		if ( self::available() ) {
			$candidate = self::dir() . ltrim( $relative, '/' );
			$path      = is_file( $candidate ) ? $candidate : '';
		}
		return self::$cache[ $relative ] = $path;
	}
}

// [2026-10-12 11:47 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.102 ST102-L17/L12 — helpers every caller uses instead of class_exists( 'BizCity_TwinWeb_*' ): availability by FILE presence (the request that builds a page may be one that skips loading the class on purpose).
if ( ! function_exists( 'bizcity_twinweb_available' ) ) {
	/** True when the /gpt/ part (the add-on bizcity-twinweb-addon, found and allowed by its load mode) can load. */
	function bizcity_twinweb_available(): bool {
		return '' !== BizCity_Addon_Locator::file( 'twinweb/bootstrap.php' );
	}
}
if ( ! function_exists( 'bizcity_twinweb_url' ) ) {
	/**
	 * Public URL of a /gpt/ page ('' ⇒ /gpt/). Without the add-on there is no /gpt/: the Channel Gateway home answers instead
	 * (the Twin Shell, which opens "Zalo Setup" first; CRM-like pages open the CRM entry).
	 */
	function bizcity_twinweb_url( string $sub = '' ): string {
		$sub = trim( $sub, '/' );
		if ( bizcity_twinweb_available() ) {
			return home_url( '/gpt/' . ( '' !== $sub ? $sub . '/' : '' ) );
		}
		if ( class_exists( 'BizCity_Twin_Shell_Page', false ) && method_exists( 'BizCity_Twin_Shell_Page', 'shell_url' ) ) {
			return in_array( $sub, array( 'crm', 'mytasks', 'mycustomers', 'myspace' ), true )
				? BizCity_Twin_Shell_Page::shell_url( array( 'plugin' => 'crm' ) )
				: BizCity_Twin_Shell_Page::shell_url();
		}
		return home_url( '/twin/' );
	}
}

// The remembered folder names are only a cache of "where did the scan find it" — any plugin change invalidates them.
if ( function_exists( 'add_action' ) ) {
	foreach ( array( 'activated_plugin', 'deactivated_plugin', 'upgrader_process_complete' ) as $_bizcity_addon_hook ) {
		add_action( $_bizcity_addon_hook, array( 'BizCity_Addon_Locator', 'forget' ) );
	}
	unset( $_bizcity_addon_hook );
}

// [2026-10-24 Johnny Chu - Chu Hoàng Anh] PHASE-0.126 R126-T5 — moved here from core/twinweb-axis/bootstrap.php when the whole axis went to the add-on:
// the placeholder is exactly the "add-on absent" case, so it belongs to the core (always loaded, cheap: one init hook).
if ( function_exists( 'add_action' ) ) {
	// ── No /gpt/ add-on: a page still holding [bizcity_twin] must never print the raw shortcode ──────────────────────────
	// [2026-10-12 11:47 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.102 ST102-L8 — owner round 1: Channel Gateway is the home. A signed-in user who opens the old /gpt/ page is sent to the Twin Shell (opens 'Zalo Setup'); a guest, or an admin who wants to know why, gets one short card. The add-on registers the real [bizcity_twin] at init priority 10; this placeholder (priority 99) only fills the gap.
	add_action( 'init', function () {
		if ( shortcode_exists( 'bizcity_twin' ) ) {
			return;
		}
		// The redirect has to happen before any output, so it lives on template_redirect; the shortcode below is only the card.
		// Never when the /gpt/ part IS available (bizcity_twinweb_url() would then point back at /gpt/ — a loop).
		add_action( 'template_redirect', function () {
			if ( ! is_user_logged_in() || ! is_singular() || ( function_exists( 'bizcity_twinweb_available' ) && bizcity_twinweb_available() ) ) {
				return;
			}
			$post = get_post();
			if ( $post && has_shortcode( (string) $post->post_content, 'bizcity_twin' ) ) {
				wp_safe_redirect( bizcity_twinweb_url() );
				exit;
			}
		}, 5 );
		add_shortcode( 'bizcity_twin', function () {
			$admin = current_user_can( 'manage_options' );
			return '<div class="bizcity-twinweb-missing" data-bizcity-addon-missing="twinweb" style="max-width:560px;margin:48px auto;padding:20px 24px;border:1px solid #e3e5ea;border-radius:12px;font:15px/1.5 system-ui,sans-serif">'
				. '<strong>' . esc_html__( 'The web assistant is not open on this site yet.', 'bizcity-twin-ai' ) . '</strong><br>'
				. esc_html( $admin
					? __( 'This page runs on the BizCity TwinWeb add-on (bizcity-twinweb-addon), which is not active here. Install and activate it to bring the /gpt/ assistant back; everything else keeps working.', 'bizcity-twin-ai' )
					: __( 'You can keep chatting through Zalo, or go back to the home page.', 'bizcity-twin-ai' ) )
				. ' <a href="' . esc_url( home_url( '/' ) ) . '">' . esc_html__( 'Home', 'bizcity-twin-ai' ) . '</a></div>';
		} );
	}, 99 );
}
