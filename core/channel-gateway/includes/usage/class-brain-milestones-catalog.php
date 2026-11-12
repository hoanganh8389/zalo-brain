<?php
/**
 * Loader for the `brain-milestones@1` contract (PHASE-0.108 S108-L2, D108-3).
 *
 * Reads `core/channel-gateway/contracts/brain-milestones.v1.json` once per request (never from `docs/` —
 * R-RUNTIME-NO-DOCS). The evaluator always stamps a reached row with `catalog_version` from this file at the
 * time it was reached; raising a threshold later is a NEW contract file (or a bumped `catalog_version` inside
 * it), never an edit that silently reinterprets rows already written (D108-3).
 *
 * // [2026-10-13 Johnny Chu - Chu Hoàng Anh] PHASE-0.108 S108-L2.
 *
 * @package BizCity_Twin_AI
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_Brain_Milestones_Catalog {

	private static $cache = null;

	/**
	 * Test seam: set a decoded catalog array directly (unit tests avoid touching the filesystem).
	 *
	 * @var array<string,mixed>|null
	 */
	public static $override = null;

	public static function file_path(): string {
		// [2026-10-14 01:00 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.108 — this file sits in includes/usage/, the contract in core/channel-gateway/contracts/ (TWO levels up). One level up
		// pointed at the non-existent includes/contracts/, so the catalog was always empty on a real site: no milestone/badge was ever written ("Huy hiệu 0" with 21 customer turns).
		return dirname( __DIR__, 2 ) . '/contracts/brain-milestones.v1.json';
	}

	/** @return array<string,mixed> decoded contract, or a safe empty shape if the file is missing/invalid. */
	public static function get(): array {
		if ( null !== self::$override ) {
			return self::$override;
		}
		if ( null !== self::$cache ) {
			return self::$cache;
		}
		$path = self::file_path();
		$raw  = is_file( $path ) ? file_get_contents( $path ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$data = is_string( $raw ) ? json_decode( $raw, true ) : null;
		if ( ! is_array( $data ) || empty( $data['groups'] ) ) {
			return self::$cache = array( 'contract' => 'brain-milestones@1', 'catalog_version' => '', 'rules' => array(), 'groups' => array(), 'owner_tiers' => array() );
		}
		return self::$cache = $data;
	}

	public static function catalog_version(): string {
		return (string) ( self::get()['catalog_version'] ?? '' );
	}

	public static function rules(): array {
		return (array) ( self::get()['rules'] ?? array() );
	}

	/** @return array<int,array<string,mixed>> only groups not explicitly disabled. */
	public static function active_groups(): array {
		$groups = (array) ( self::get()['groups'] ?? array() );
		return array_values( array_filter( $groups, static function ( $g ) {
			return ! isset( $g['enabled'] ) || (bool) $g['enabled'];
		} ) );
	}

	/** @return array<int,array<string,mixed>> */
	public static function owner_tiers(): array {
		return (array) ( self::get()['owner_tiers'] ?? array() );
	}

	/**
	 * Tiers of one group the subject has reached, given the current metric value — ordered, highest last.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function reached_tiers( array $group, int $value ): array {
		$out = array();
		foreach ( (array) ( $group['tiers'] ?? array() ) as $tier ) {
			if ( $value >= (int) ( $tier['threshold'] ?? PHP_INT_MAX ) ) {
				$out[] = $tier;
			}
		}
		return $out;
	}

	/** Reset the in-process cache (unit tests only). */
	public static function reset(): void {
		self::$cache    = null;
		self::$override = null;
	}
}
