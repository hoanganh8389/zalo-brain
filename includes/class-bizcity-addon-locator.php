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
 * Load mode (wp-config.php, optional):
 *   define( 'BIZCITY_BRAIN_ADDON_LOAD', 'auto' | 'active' | 'off' );  // bizcity-twin-brain-addon (TwinBrain, memory, knowledge-legacy)
 *   define( 'BIZCITY_AUTOMATION_LOAD',  'auto' | 'active' | 'off' );  // bizcity-automation (Automation only)
 *   auto   (default) — load from the plugin folder when it is present, activated or not, so a deploy that uploads
 *                      the folder changes nothing for users;
 *   active — load only when that plugin is activated (site or network);
 *   off    — never load that plugin's parts.
 * WordPress loads bizcity-twin-ai before its sibling plugins (alphabetical), so the main plugin cannot wait for an
 * add-on constant; it checks the folder directly.
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Addon_Locator', false ) ) {
	return;
}

final class BizCity_Addon_Locator {

	const SLUG = 'bizcity-twin-brain-addon';

	/** [2026-10-05 08:23 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.91-AX-0 — Automation's own plugin slug, independent of SLUG above. */
	const AUTOMATION_SLUG = 'bizcity-automation';

	/** Parts resolved against AUTOMATION_SLUG instead of SLUG. One top-level folder name per entry. */
	const AUTOMATION_PARTS = array( 'automation' );

	/** @var array<string,string> */
	private static $cache = array();

	public static function mode(): string {
		$mode = defined( 'BIZCITY_BRAIN_ADDON_LOAD' ) ? strtolower( (string) BIZCITY_BRAIN_ADDON_LOAD ) : 'auto';
		return in_array( $mode, array( 'auto', 'active', 'off' ), true ) ? $mode : 'auto';
	}

	/** Same tri-state as mode(), but Automation's own constant — independent on/off switch (Pro/Add-on model). */
	private static function automation_mode(): string {
		$mode = defined( 'BIZCITY_AUTOMATION_LOAD' ) ? strtolower( (string) BIZCITY_AUTOMATION_LOAD ) : 'auto';
		return in_array( $mode, array( 'auto', 'active', 'off' ), true ) ? $mode : 'auto';
	}

	private static function plugin_dir( string $slug ): string {
		$base = defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR : dirname( __DIR__, 2 );
		return rtrim( str_replace( '\\', '/', $base ), '/' ) . '/' . $slug . '/';
	}

	public static function dir(): string {
		return self::plugin_dir( self::SLUG );
	}

	private static function plugin_is_active( string $slug ): bool {
		$main = $slug . '/' . $slug . '.php';
		if ( function_exists( 'get_option' ) && in_array( $main, (array) get_option( 'active_plugins', array() ), true ) ) {
			return true;
		}
		if ( function_exists( 'is_multisite' ) && is_multisite() && function_exists( 'get_site_option' ) ) {
			return isset( ( (array) get_site_option( 'active_sitewide_plugins', array() ) )[ $main ] );
		}
		return false;
	}

	public static function is_active(): bool {
		return self::plugin_is_active( self::SLUG );
	}

	/** Availability of bizcity-twin-brain-addon (TwinBrain, memory, knowledge-legacy) — unrelated to Automation. */
	public static function available(): bool {
		$mode = self::mode();
		if ( 'off' === $mode || ! is_dir( self::dir() ) ) {
			return false;
		}
		return 'auto' === $mode || self::is_active();
	}

	/** Availability of bizcity-automation specifically. */
	private static function automation_available(): bool {
		$mode = self::automation_mode();
		$dir  = self::plugin_dir( self::AUTOMATION_SLUG );
		if ( 'off' === $mode || ! is_dir( $dir ) ) {
			return false;
		}
		return 'auto' === $mode || self::plugin_is_active( self::AUTOMATION_SLUG );
	}

	/**
	 * Absolute path of a file inside the right sibling plugin ('' when unavailable or the file is missing).
	 *
	 * @param string $relative e.g. 'automation/bootstrap.php', 'memory/bootstrap.php', 'knowledge-legacy/bootstrap.php'
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
				$candidate = self::plugin_dir( self::AUTOMATION_SLUG ) . ltrim( $inside, '/' );
				$path      = ( '' !== trim( $inside, '/' ) && is_file( $candidate ) ) ? $candidate : '';
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
