<?php
/**
 * Zalo Brain — CRM spine registry (crm-spine@1).
 *
 * [2026-10-09 Johnny Chu - Chu Hoàng Anh] PHASE-0.96 S96-2.2 — core/crm is the conversation ledger + identity + scope
 * layer every channel of Zalo Brain writes into and the cell reads from. Extension plugins (Zalo Brain CRM) register
 * the features they bring; core code asks `BizCity_CRM_Spine::has( 'pipeline' )` before touching anything that only
 * exists in the plugin, and answers `crm_feature_unavailable` (R-ERROR-UX 4 fields) when it is missing.
 * Design: core/channel-gateway/docs/PHASE-0.96-ZALO-BRAIN-CRM-SPLIT/20-CORE-CRM-SPINE.md §4.
 *
 * @package BizCity_Twin_AI
 * @subpackage Core\CRM
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_CRM_Spine', false ) ) {
	return;
}

final class BizCity_CRM_Spine {

	const VERSION  = '1.0.0';
	const CONTRACT = 'crm-spine@1';

	/** Feature ids an extension may register (free-form ids are accepted too; these are the documented set). */
	const KNOWN_FEATURES = array(
		'workspace', 'pipeline', 'tasks', 'sla', 'reports', 'campaigns', 'broadcast', 'invoices', 'b2b',
		'submissions', 'calendar', 'care', 'admin_ui', 'staff_admin', 'email_automation', 'print_ads', 'ai_usage', 'service',
	);

	/** @var array<string,array{version:string,owner:string}> */
	private static $features = array();

	/** @var array<string,array> extension manifests keyed by id */
	private static $extensions = array();

	/**
	 * Register one feature the calling extension provides. Call during plugins_loaded (priority 6) boot.
	 *
	 * @param string $feature Feature id, e.g. 'pipeline'.
	 * @param string $version Extension version that provides it.
	 * @param string $owner   Plugin slug providing it, e.g. 'bizcity-twin-crm'.
	 */
	public static function register_feature( string $feature, string $version = '1.0.0', string $owner = 'bizcity-twin-crm' ): void {
		$feature = sanitize_key( $feature );
		if ( '' === $feature ) {
			return;
		}
		self::$features[ $feature ] = array( 'version' => $version, 'owner' => $owner );
		// PHASE-0.96 S96-6.2 — one capability map for the whole framework.
		if ( class_exists( 'BizCity_Zalo_Brain', false ) ) {
			BizCity_Zalo_Brain::register_feature( $feature, $version, $owner );
		}
	}

	/**
	 * Register an extension plugin (its zalo-brain.json manifest, decoded). Idempotent per id.
	 *
	 * @param array $manifest Must carry 'id'; may carry 'features' (string[]), 'version', 'name'.
	 */
	public static function register_extension( array $manifest ): bool {
		$id = isset( $manifest['id'] ) ? sanitize_key( (string) $manifest['id'] ) : '';
		if ( '' === $id ) {
			return false;
		}
		self::$extensions[ $id ] = $manifest;
		if ( class_exists( 'BizCity_Zalo_Brain', false ) ) {
			$copy = $manifest;
			unset( $copy['features'] ); // features are mirrored one by one below
			BizCity_Zalo_Brain::register_extension( $copy );
		}
		$version = isset( $manifest['version'] ) ? (string) $manifest['version'] : '1.0.0';
		foreach ( (array) ( $manifest['features'] ?? array() ) as $feature ) {
			self::register_feature( (string) $feature, $version, $id );
		}
		return true;
	}

	/** True when an extension registered the feature. Core callers gate plugin-only classes behind this. */
	public static function has( string $feature ): bool {
		$feature = sanitize_key( $feature );
		if ( isset( self::$features[ $feature ] ) ) {
			return true;
		}
		if ( class_exists( 'BizCity_Zalo_Brain', false ) && BizCity_Zalo_Brain::has( $feature ) ) {
			return true;
		}
		/**
		 * Last-resort override (tests, hosts that load the plugin outside plugins_loaded).
		 *
		 * @param bool   $has     false by default.
		 * @param string $feature Feature id.
		 */
		return (bool) apply_filters( 'bizcity_crm_spine_has_feature', false, $feature );
	}

	/** @return array<string,array{version:string,owner:string}> */
	public static function features(): array {
		return self::$features;
	}

	/** @return array<string,array> */
	public static function extensions(): array {
		return self::$extensions;
	}

	/**
	 * Standard error for a feature that only the extension plugin provides (R-ERROR-UX: code/message/hint/help_code).
	 */
	public static function unavailable_error( string $feature, int $status = 404 ): WP_Error {
		$feature = sanitize_key( $feature );
		return new WP_Error(
			'crm_feature_unavailable',
			sprintf(
				/* translators: %s feature id */
				__( 'Tính năng "%s" cần plugin Zalo Brain CRM. Site này chưa cài hoặc chưa kích hoạt plugin đó.', 'bizcity-twin-ai' ),
				$feature
			),
			array(
				'status'    => $status,
				'hint'      => __( 'Cách sửa: vào Cài đặt › Mở rộng, cài và kích hoạt Zalo Brain CRM, rồi thử lại.', 'bizcity-twin-ai' ),
				'help_code' => 'CRM-SPINE-' . strtoupper( str_replace( '_', '-', $feature ) ) . '-MISSING',
				'feature'   => $feature,
			)
		);
	}

	/** DTO for `GET bizcity-crm/v1/spine` and the TwinShell boot payload. */
	public static function describe(): array {
		return array(
			'contract'   => self::CONTRACT,
			'version'    => self::VERSION,
			'db_version' => defined( 'BIZCITY_CRM_DB_VERSION' ) ? BIZCITY_CRM_DB_VERSION : '',
			'features'   => self::$features,
			'extensions' => array_map(
				static function ( array $m ): array {
					return array(
						'id'      => (string) ( $m['id'] ?? '' ),
						'name'    => (string) ( $m['name'] ?? '' ),
						'version' => (string) ( $m['version'] ?? '' ),
					);
				},
				array_values( self::$extensions )
			),
		);
	}

	/** REST: GET bizcity-crm/v1/spine — any logged-in user may read the capability map (no secrets inside). */
	public static function register_rest(): void {
		register_rest_route(
			defined( 'BIZCITY_CRM_REST_NS' ) ? BIZCITY_CRM_REST_NS : 'bizcity-crm/v1',
			'/spine',
			array(
				'methods'             => 'GET',
				'callback'            => static function () {
					return rest_ensure_response( self::describe() );
				},
				'permission_callback' => static function () {
					return is_user_logged_in();
				},
			)
		);
	}
}
