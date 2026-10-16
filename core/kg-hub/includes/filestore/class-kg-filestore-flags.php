<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\KG_Hub
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 *
 * BizCity_KG_Filestore_Flags — rollout flags of the KG file store (v06 read switch, v07 file-primary write, v08 graph embedding
 * migration, v09 triplet raw migration).
 *
 * [2026-10-16 04:07 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.116 CS5.1 (doc 87) — moved here from core/cron/includes/class-cron-tier-settings.php
 * (the cron helper must not know about KG). Same option names, same defaults, same network-aware read; only the owner moved.
 *
 *   - bizcity_kg_v06_dual_write_enabled  (legacy central dual-write, default OFF)
 *   - bizcity_kg_v06_read_switch_enabled (default ON)
 *   - bizcity_kg_v07_file_primary_write_enabled (default ON)
 *   - bizcity_kg_v08_graph_embedding_migration_enabled (default ON)
 *   - bizcity_kg_v09_triplet_raw_migration_enabled (default ON)
 *
 * PHP 7.4 compat: no match(), union types, nullsafe.
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_KG_Filestore_Flags', false ) ) {
	return;
}

class BizCity_KG_Filestore_Flags {

	const OPT_FILE_FIRST_DUAL           = 'bizcity_kg_v06_dual_write_enabled';
	const OPT_FILE_FIRST_READ           = 'bizcity_kg_v06_read_switch_enabled';
	const OPT_FILE_PRIMARY              = 'bizcity_kg_v07_file_primary_write_enabled';
	const OPT_GRAPH_EMBEDDING_MIGRATION = 'bizcity_kg_v08_graph_embedding_migration_enabled';
	const OPT_TRIPLET_RAW_MIGRATION     = 'bizcity_kg_v09_triplet_raw_migration_enabled';

	public static function is_file_first_dual_write(): bool {
		return (bool) self::get_network_option( self::OPT_FILE_FIRST_DUAL, false );
	}

	public static function is_file_first_read_switch(): bool {
		return (bool) self::get_network_option( self::OPT_FILE_FIRST_READ, true );
	}

	public static function is_file_primary_write(): bool {
		return (bool) self::get_network_option( self::OPT_FILE_PRIMARY, true );
	}

	public static function is_graph_embedding_migration_enabled(): bool {
		return (bool) self::get_network_option( self::OPT_GRAPH_EMBEDDING_MIGRATION, true );
	}

	public static function is_triplet_raw_migration_enabled(): bool {
		return (bool) self::get_network_option( self::OPT_TRIPLET_RAW_MIGRATION, true );
	}

	public static function set_file_first( bool $dual_write, bool $read_switch ): void {
		self::update_network_option( self::OPT_FILE_FIRST_DUAL, $dual_write ? 1 : 0 );
		self::update_network_option( self::OPT_FILE_FIRST_READ, $read_switch ? 1 : 0 );
		// Mirror to the site option so the v06 filters (which read get_option) still see it.
		update_option( self::OPT_FILE_FIRST_DUAL, $dual_write ? 1 : 0 );
		update_option( self::OPT_FILE_FIRST_READ, $read_switch ? 1 : 0 );
	}

	public static function set_file_primary_write( bool $enabled ): void {
		self::update_network_option( self::OPT_FILE_PRIMARY, $enabled ? 1 : 0 );
		update_option( self::OPT_FILE_PRIMARY, $enabled ? 1 : 0 );
	}

	public static function set_graph_embedding_migration( bool $enabled ): void {
		self::update_network_option( self::OPT_GRAPH_EMBEDDING_MIGRATION, $enabled ? 1 : 0 );
		update_option( self::OPT_GRAPH_EMBEDDING_MIGRATION, $enabled ? 1 : 0 );
	}

	public static function set_triplet_raw_migration( bool $enabled ): void {
		self::update_network_option( self::OPT_TRIPLET_RAW_MIGRATION, $enabled ? 1 : 0 );
		update_option( self::OPT_TRIPLET_RAW_MIGRATION, $enabled ? 1 : 0 );
	}

	private static function get_network_option( string $key, $default ) {
		if ( is_multisite() ) {
			$val = get_site_option( $key, null );
			if ( null !== $val ) {
				return $val;
			}
		}
		return get_option( $key, $default );
	}

	private static function update_network_option( string $key, $value ): void {
		if ( is_multisite() ) {
			update_site_option( $key, $value );
		} else {
			update_option( $key, $value );
		}
	}
}
