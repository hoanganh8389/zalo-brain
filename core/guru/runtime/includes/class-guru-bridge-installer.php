<?php
/**
 * BizCity Twin AI — Guru Bridge schema installer (Phase B / F7.B1).
 *
 * Idempotent dbDelta migration for two bridge tables wiring a Guru
 * (= row in `wp_bizcity_characters`) to (a) tool/skill ids registered by
 * Persona Tool Providers and (b) provider classes themselves.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Persona
 * @since      1.4.0
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Guru_Bridge_Installer', false ) ) {
	return;
}

class BizCity_Guru_Bridge_Installer {

	const SCHEMA_VERSION  = '1.0.0';
	const OPTION_VERSION  = 'bizcity_guru_bridge_schema_version';
	// ⛔ R-TABLE-FREEZE (docs/rules/PHASE-0-RULE-TABLE-FREEZE.md) — bizcity_guru_skills is FROZEN: never install, write or read it in new code; Guru = instruction + Vault; BizCity_Guru_Skill_Bridge answers [] without SQL.
	const TABLE_SKILLS    = 'bizcity_guru_skills';
	const TABLE_PROVIDERS = 'bizcity_guru_providers';

	/**
	 * Run on plugins_loaded — install/upgrade if version drifted.
	 * [2026-10-25 Johnny Chu - Chu Hoàng Anh] PHASE-0.126 R126-TB — both bridge tables are quarantined (guru_skills never had a writer;
	 * Guru = instruction + Vault): nothing is created any more. Kept as a no-op so an older bootstrap that still hooks it does not fatal.
	 */
	public static function maybe_install(): void {
	}

	/** [2026-10-25 Johnny Chu - Chu Hoàng Anh] PHASE-0.126 R126-TB — retired (see maybe_install()); the old DDL is in git history / core.persona.json. */
	public static function install(): void {
	}

	public static function table_skills(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_SKILLS;
	}

	public static function table_providers(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_PROVIDERS;
	}

	public static function table_exists( string $table ): bool {
		return bizcity_tbl_exists( $table ); // [2026-06-21 Johnny Chu] R-SHOW-TABLES
	}
}
