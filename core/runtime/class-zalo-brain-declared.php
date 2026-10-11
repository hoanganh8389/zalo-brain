<?php
/**
 * Zalo Brain — features declared on disk (contract zalo-brain-extension@1), independent of what a request loaded.
 *
 * [2026-10-11 10:58 AM Johnny Chu - Chu Hoàng Anh] CORE-REDUCTION WP-20 Z-6 P0-3 — BizCity_Zalo_Brain::has() used to answer
 * only from features registered at load time: by core/crm's BizCity_CRM_Spine (mirrors the Zalo Brain CRM plugin, which
 * boots only when the spine loaded), by bizcity-automation's bootstrap (loaded only on Automation surfaces) and by
 * bizcoach-pro. Once loading is gated per request, the same site would answer has( 'pipeline' ) or has( 'automation' )
 * differently per URL. This class reads the same manifests statically:
 *   - CORE_FEATURES (none today), then
 *   - `features` of the `zalo-brain.json` of every ACTIVE plugin (site + network) whose contract is zalo-brain-extension@1,
 *   - bizcity-automation only when BizCity_Addon_Locator resolves it (this plugin loads it, WordPress does not; same rule
 *     the /flow/ surface availability uses).
 * Owner = manifest id, version = manifest version: what each extension's own register_extension() records, so in the
 * everything-loaded case BizCity_Zalo_Brain::features() is unchanged. Read once per request (memo); record-only, no hooks.
 *
 * @package BizCity_Twin_AI
 * @subpackage Core\Runtime
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Zalo_Brain_Declared', false ) ) {
	return;
}

final class BizCity_Zalo_Brain_Declared {

	/** Feature ids core itself provides. Empty today: every feature id comes from an extension manifest. */
	const CORE_FEATURES = array();

	/** Plugin folder loaded through BizCity_Addon_Locator, never by WordPress' active_plugins. */
	const LOCATOR_SLUG = 'bizcity-automation';

	/** @var array<string,array{version:string,owner:string}>|null */
	private static $memo = null;

	/**
	 * @param string $contract Manifest contract to accept (BizCity_Zalo_Brain::CONTRACT).
	 * @return array<string,array{version:string,owner:string}>
	 */
	public static function features( string $contract = 'zalo-brain-extension@1' ): array {
		if ( null !== self::$memo ) {
			return self::$memo;
		}
		$out = array();
		foreach ( self::CORE_FEATURES as $feature ) {
			$out[ sanitize_key( (string) $feature ) ] = array( 'version' => '1.0.0', 'owner' => 'bizcity-twin-ai' );
		}
		if ( ! function_exists( 'get_option' ) || ! defined( 'WP_PLUGIN_DIR' ) ) {
			return $out; // outside WordPress / too early: never memo a partial answer
		}
		foreach ( self::manifest_files() as $file ) {
			$m  = json_decode( (string) file_get_contents( $file ), true );
			$id = ( is_array( $m ) && $contract === ( $m['contract'] ?? '' ) ) ? sanitize_key( (string) ( $m['id'] ?? '' ) ) : '';
			if ( '' === $id ) {
				continue;
			}
			$version = isset( $m['version'] ) ? (string) $m['version'] : '1.0.0';
			foreach ( (array) ( $m['features'] ?? array() ) as $feature ) {
				$feature = sanitize_key( (string) $feature );
				if ( '' !== $feature && ! isset( $out[ $feature ] ) ) {
					$out[ $feature ] = array( 'version' => $version, 'owner' => $id );
				}
			}
		}
		return self::$memo = $out;
	}

	/** @return string[] absolute zalo-brain.json paths that exist (active plugins + the locator-resolved Automation). */
	public static function manifest_files(): array {
		$base   = rtrim( str_replace( '\\', '/', (string) WP_PLUGIN_DIR ), '/' );
		$active = (array) get_option( 'active_plugins', array() );
		if ( function_exists( 'is_multisite' ) && is_multisite() && function_exists( 'get_site_option' ) ) {
			$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}
		$files = array();
		foreach ( $active as $basename ) {
			$dir = dirname( str_replace( '\\', '/', (string) $basename ) );
			if ( '.' !== $dir && '' !== $dir && self::LOCATOR_SLUG !== $dir ) {
				$files[ $dir ] = $base . '/' . $dir . '/zalo-brain.json';
			}
		}
		$boot = class_exists( 'BizCity_Addon_Locator', false ) ? BizCity_Addon_Locator::file( 'automation/bootstrap.php' ) : '';
		if ( '' !== $boot ) {
			$files[ self::LOCATOR_SLUG ] = dirname( $boot ) . '/zalo-brain.json';
		}
		return array_values( array_filter( $files, 'is_file' ) );
	}

	/** Tests only: forget the per-request memo. */
	public static function reset(): void {
		self::$memo = null;
	}
}
