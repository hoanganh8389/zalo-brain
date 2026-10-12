<?php
/**
 * BizCity_Brain_Profile_Store — file I/O for installed/drafted brain profiles (PHASE-0.100 S100-L3, INTERFACES I-7).
 *
 * `uploads/bizcity-brain-profiles/{blog}/guru-<guru_id>/` holds `profile.json`, `program.json`, `track.json`,
 * `safety.md`, `journal.json`, `schedule.json`, `policy.json`, `INSTALL.json`. Drafts (from `profile.propose`)
 * live under `uploads/bizcity-brain-profiles/{blog}/_incoming/<draft_id>/`. Sample packs ship at
 * `bizcity-twin-ai/examples/brain-profiles/<id>/` and are never written to (read-only templates).
 *
 * File only — no DB, no option, no new table (R-LEAN-4). Every write goes through WP_Filesystem-style
 * plain fopen like `BizCity_Skill_Manager` (same uploads-dir pattern) so this works on LocalWP and the VPS.
 *
 * Biz Central Brain — Johnny Chu (Chu Hoàng Anh).
 *
 * // [2026-10-12 09:48 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.100 S100-L3 — new file.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Channel_Gateway\Profile
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Brain_Profile_Store', false ) ) {
	return;
}

final class BizCity_Brain_Profile_Store {

	/** @return string trailing-slash base dir for this blog, created on first use */
	public static function base_dir(): string {
		$upload = function_exists( 'wp_upload_dir' ) ? wp_upload_dir( null, false ) : array();
		$base   = is_array( $upload ) && ! empty( $upload['basedir'] ) ? (string) $upload['basedir'] : WP_CONTENT_DIR . '/uploads';
		$dir    = rtrim( $base, '/\\' ) . '/bizcity-brain-profiles/' . (int) ( function_exists( 'get_current_blog_id' ) ? get_current_blog_id() : 1 ) . '/';
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		return $dir;
	}

	/** @return string e.g. .../guru-12/ — created on first use */
	public static function guru_dir( int $guru_id ): string {
		$dir = self::base_dir() . 'guru-' . max( 0, $guru_id ) . '/';
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		return $dir;
	}

	public static function draft_dir( string $draft_id ): string {
		$draft_id = preg_replace( '/[^a-z0-9-]/i', '', $draft_id );
		$dir      = self::base_dir() . '_incoming/' . $draft_id . '/';
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		return $dir;
	}

	public static function exports_dir(): string {
		$dir = self::base_dir() . '_exports/';
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		return $dir;
	}

	/** @return array|null decoded JSON, null when the file is missing or unreadable */
	public static function read_json( string $path ) {
		if ( '' === $path || ! is_readable( $path ) ) {
			return null;
		}
		$raw = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$v   = is_string( $raw ) ? json_decode( $raw, true ) : null;
		return is_array( $v ) ? $v : null;
	}

	/** @return bool */
	public static function write_json( string $path, array $data ): bool {
		$dir = dirname( $path );
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return false;
		}
		$json = wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		return false !== $json && false !== file_put_contents( $path, $json ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}

	public static function read_text( string $path ): string {
		return is_readable( $path ) ? (string) file_get_contents( $path ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	}

	public static function write_text( string $path, string $text ): bool {
		$dir = dirname( $path );
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return false;
		}
		return false !== file_put_contents( $path, $text ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}

	/** @return array{id:int,version:string,family:string,installed_at:string}|null from Guru settings.brain_profile */
	public static function installed_of( int $guru_id ): ?array {
		if ( ! class_exists( 'BizCity_Knowledge_Database' ) ) {
			return null;
		}
		$db  = BizCity_Knowledge_Database::instance();
		$row = method_exists( $db, 'get_character' ) ? $db->get_character( $guru_id ) : null;
		if ( ! $row ) {
			return null;
		}
		$settings = json_decode( (string) ( $row->settings ?? '{}' ), true );
		$bp       = is_array( $settings ) ? ( $settings['brain_profile'] ?? null ) : null;
		return is_array( $bp ) ? $bp : null;
	}

	/**
	 * Every installed profile on this site, one row per `guru-<id>/INSTALL.json`.
	 * @return array<int,array> guru_id => INSTALL.json contents
	 */
	public static function all_installed(): array {
		$out  = array();
		$base = self::base_dir();
		if ( ! is_dir( $base ) ) {
			return $out;
		}
		foreach ( glob( $base . 'guru-*', GLOB_ONLYDIR ) ?: array() as $d ) {
			if ( ! preg_match( '/guru-(\d+)$/', $d, $m ) ) {
				continue;
			}
			$install = self::read_json( rtrim( $d, '/\\' ) . '/INSTALL.json' );
			if ( $install ) {
				$out[ (int) $m[1] ] = $install;
			}
		}
		return $out;
	}
}
