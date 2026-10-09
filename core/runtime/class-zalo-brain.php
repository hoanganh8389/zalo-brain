<?php
/**
 * Zalo Brain — framework registry (contract zalo-brain-extension@1).
 *
 * [2026-10-09 Johnny Chu - Chu Hoàng Anh] PHASE-0.96 S96-6.2 (D96-16) — one class answers "what does this Zalo Brain
 * site have": the 7 surfaces (/gpt/ /gateway/ /crm/ /twinchat/ /scheduler/ /flow/ /setting/), the extension plugins
 * installed on top (Zalo Brain CRM, Automation, …), the features they bring, and the standard error when a feature
 * is missing. Extension plugins describe themselves with a `zalo-brain.json` manifest and register during
 * plugins_loaded (any priority before `zalo_brain_loaded`, which fires at plugins_loaded@20).
 * Contract: docs/contracts/ZALO-BRAIN-EXTENSION-CONTRACT-v1.md · design: PHASE-0.96 doc 50 §4.
 *
 * This class only records and reports. It never loads plugin code and never changes routing; the existing
 * registries (TwinShell default plugins, Setting Panel, Twin Plugin SDK) keep working and will read from here in W7.
 *
 * @package BizCity_Twin_AI
 * @subpackage Core\Runtime
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Zalo_Brain', false ) ) {
	return;
}

final class BizCity_Zalo_Brain {

	const CONTRACT = 'zalo-brain-extension@1';
	const VERSION  = '1.0.0';

	/** The seven surfaces of Zalo Brain (doc 50 §3). Owner = code that renders it; requires = feature or plugin. */
	const CORE_SURFACES = array(
		'gpt'       => array( 'slug' => '/gpt/',       'label' => 'Trợ lý',           'owner' => 'modules/twinweb',        'position' => 10 ),
		'gateway'   => array( 'slug' => '/gateway/',   'label' => 'Cấu hình Zalo',    'owner' => 'core/channel-gateway',   'position' => 20 ),
		'crm'       => array( 'slug' => '/crm/',       'label' => 'Đội Zalo',         'owner' => 'core/crm',               'position' => 30 ),
		'twinchat'  => array( 'slug' => '/twinchat/',  'label' => 'Sổ tay',           'owner' => 'modules/twinchat',       'position' => 40 ),
		'scheduler' => array( 'slug' => '/scheduler/', 'label' => 'Lịch & nhiệm vụ',  'owner' => 'core/scheduler',         'position' => 50 ),
		'flow'      => array( 'slug' => '/flow/',      'label' => 'Kịch bản',         'owner' => 'bizcity-automation',     'position' => 60, 'requires' => 'automation' ),
		'setting'   => array( 'slug' => '/setting/',   'label' => 'Cài đặt',          'owner' => 'modules/twinshell',      'position' => 70 ),
	);

	/** @var array<string,array> extension manifests by id */
	private static $extensions = array();

	/** @var array<string,array{version:string,owner:string}> */
	private static $features = array();

	/** @var array<string,array<string,array>> pages registered under a surface: [surface][page_id] => def */
	private static $pages = array();

	/** @var bool */
	private static $loaded = false;

	/** Wire the two lifecycle actions. Called once from bizcity-twin-ai.php. */
	public static function boot(): void {
		static $booted = false;
		if ( $booted ) {
			return;
		}
		$booted = true;
		add_action( 'plugins_loaded', static function () {
			/**
			 * Extension plugins register here (or anywhere before zalo_brain_loaded).
			 */
			do_action( 'zalo_brain_register' );
		}, 5 );
		add_action( 'plugins_loaded', static function () {
			self::$loaded = true;
			/**
			 * Registration is closed; surfaces, features and extensions are final for this request.
			 */
			do_action( 'zalo_brain_loaded' );
		}, 20 );
	}

	private static function closed( string $what ): bool {
		if ( ! self::$loaded ) {
			return false;
		}
		if ( function_exists( '_doing_it_wrong' ) ) {
			_doing_it_wrong( __CLASS__ . '::' . $what, 'Register before zalo_brain_loaded (plugins_loaded@20).', '1.4.0' );
		}
		return true;
	}

	/**
	 * Register an extension plugin from its zalo-brain.json manifest (decoded).
	 * Required keys: id. Optional: name, version, features[], surfaces[], tables_changelog, mcp_tools[], licence.
	 */
	public static function register_extension( array $manifest ): bool {
		if ( self::closed( 'register_extension' ) ) {
			return false;
		}
		$id = isset( $manifest['id'] ) ? sanitize_key( (string) $manifest['id'] ) : '';
		if ( '' === $id ) {
			return false;
		}
		self::$extensions[ $id ] = $manifest;
		$version = isset( $manifest['version'] ) ? (string) $manifest['version'] : '1.0.0';
		foreach ( (array) ( $manifest['features'] ?? array() ) as $feature ) {
			self::register_feature( (string) $feature, $version, $id );
		}
		foreach ( (array) ( $manifest['surfaces'] ?? array() ) as $s ) {
			$surface = sanitize_key( (string) ( $s['surface'] ?? '' ) );
			foreach ( (array) ( $s['pages'] ?? array() ) as $page ) {
				self::register_surface( $surface, array( 'id' => (string) $page, 'owner' => $id ) );
			}
		}
		return true;
	}

	/** Register one feature id (e.g. 'pipeline'). Mirrored by BizCity_CRM_Spine for CRM features. */
	public static function register_feature( string $feature, string $version = '1.0.0', string $owner = '' ): void {
		if ( self::closed( 'register_feature' ) ) {
			return;
		}
		$feature = sanitize_key( $feature );
		if ( '' !== $feature ) {
			self::$features[ $feature ] = array( 'version' => $version, 'owner' => $owner );
		}
	}

	/**
	 * Register a page under one of the 7 surfaces (W7 renders it inside the shell).
	 *
	 * @param string $surface One of CORE_SURFACES keys.
	 * @param array  $def     id, label, cap, position, feature, render (array: spa|callback), owner.
	 */
	public static function register_surface( string $surface, array $def ): bool {
		if ( self::closed( 'register_surface' ) ) {
			return false;
		}
		$surface = sanitize_key( $surface );
		$id      = sanitize_key( (string) ( $def['id'] ?? '' ) );
		if ( ! isset( self::CORE_SURFACES[ $surface ] ) || '' === $id ) {
			return false;
		}
		self::$pages[ $surface ][ $id ] = array_merge( array( 'id' => $id, 'position' => 100 ), $def );
		return true;
	}

	public static function has( string $feature ): bool {
		$feature = sanitize_key( $feature );
		if ( isset( self::$features[ $feature ] ) ) {
			return true;
		}
		return (bool) apply_filters( 'zalo_brain_has_feature', false, $feature );
	}

	/** @return array<string,array{version:string,owner:string}> */
	public static function features(): array {
		return self::$features;
	}

	/** @return array<string,array> */
	public static function extensions(): array {
		return self::$extensions;
	}

	/** The 7 surfaces with availability and the pages registered under each. Filterable (`zalo_brain_surfaces`). */
	public static function surfaces(): array {
		$out = array();
		foreach ( self::CORE_SURFACES as $key => $def ) {
			$requires  = isset( $def['requires'] ) ? (string) $def['requires'] : '';
			$available = '' === $requires || self::has( $requires )
				|| ( 'automation' === $requires && class_exists( 'BizCity_Addon_Locator' ) && '' !== BizCity_Addon_Locator::file( 'automation/bootstrap.php' ) );
			$pages     = self::$pages[ $key ] ?? array();
			uasort( $pages, static function ( $a, $b ) { return (int) $a['position'] <=> (int) $b['position']; } );
			$out[ $key ] = array_merge( $def, array( 'id' => $key, 'available' => $available, 'pages' => array_values( $pages ) ) );
		}
		return (array) apply_filters( 'zalo_brain_surfaces', $out );
	}

	/** Standard error for a missing feature (R-ERROR-UX: code/message/hint/help_code). */
	public static function unavailable_error( string $feature, string $plugin_name = 'Zalo Brain CRM', int $status = 404 ): WP_Error {
		$feature = sanitize_key( $feature );
		return new WP_Error(
			'feature_unavailable',
			sprintf( 'Tính năng "%s" cần plugin %s. Site này chưa cài hoặc chưa kích hoạt plugin đó.', $feature, $plugin_name ),
			array(
				'status'    => $status,
				'hint'      => 'Cách sửa: vào Cài đặt › Mở rộng, cài và kích hoạt ' . $plugin_name . ', rồi thử lại.',
				'help_code' => 'ZB-' . strtoupper( str_replace( '_', '-', $feature ) ) . '-MISSING',
				'feature'   => $feature,
			)
		);
	}

	/** DTO for front-ends (`zalo_brain_boot_dto` filter lets a host add its own keys). */
	public static function describe(): array {
		$dto = array(
			'brand'      => 'Zalo Brain',
			'contract'   => self::CONTRACT,
			'version'    => defined( 'BIZCITY_TWIN_AI_VERSION' ) ? BIZCITY_TWIN_AI_VERSION : '',
			'surfaces'   => self::surfaces(),
			'features'   => self::$features,
			'extensions' => array_map(
				static function ( array $m ): array {
					return array( 'id' => (string) ( $m['id'] ?? '' ), 'name' => (string) ( $m['name'] ?? '' ), 'version' => (string) ( $m['version'] ?? '' ) );
				},
				array_values( self::$extensions )
			),
		);
		return (array) apply_filters( 'zalo_brain_boot_dto', $dto );
	}

	/** REST: GET bizcity/v1/zalo-brain — logged-in users; no secrets inside. */
	public static function register_rest(): void {
		register_rest_route( 'bizcity/v1', '/zalo-brain', array(
			'methods'             => 'GET',
			'callback'            => static function () {
				return rest_ensure_response( self::describe() );
			},
			'permission_callback' => static function () {
				return is_user_logged_in();
			},
		) );
	}
}
