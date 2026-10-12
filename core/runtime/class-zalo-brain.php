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
 * [2026-10-09 11:43 PM Johnny Chu - Chu Hoàng Anh] CORE-REDUCTION WP-20 Z-2b — doc 50 §4.2 completed: register_tool(),
 * register_tables(), the "Apps" slot 8 (register_surface('apps') → TwinShell `zalo_brain_apps`@20) and mirror(), the
 * record-only entry the old registries (Twin Plugin SDK, Setting Panel) call — D96-16 "old classes become wrappers".
 *
 * @package BizCity_Twin_AI
 * @subpackage Core\Runtime
 */

defined( 'ABSPATH' ) || exit;

// [2026-10-11 10:58 AM Johnny Chu - Chu Hoàng Anh] CORE-REDUCTION WP-20 Z-6 P0-3 — request-independent feature declarations.
if ( is_file( $bizcity_zb_declared = __DIR__ . '/class-zalo-brain-declared.php' ) ) { require_once $bizcity_zb_declared; }
unset( $bizcity_zb_declared );

if ( class_exists( 'BizCity_Zalo_Brain', false ) ) {
	return;
}

final class BizCity_Zalo_Brain {

	const CONTRACT = 'zalo-brain-extension@1';
	// [2026-10-11 Johnny Chu - Chu Hoàng Anh] PHASE-0.98 S98-L1 — 1.1.0 (additive): register_from_manifest(), register_block(), register_scenarios(),
	// manifest keys mcp_library / scenarios / automation_blocks read, tools_by_owner in describe(). 1.0 callers unchanged.
	const VERSION  = '1.1.0';
	/** Manifest keys 1.1 reads beyond 1.0 (all optional). */
	const MANIFEST_KEYS_11 = array( 'mcp_library', 'scenarios', 'automation_blocks', 'skills' );

	/** The seven surfaces of Zalo Brain (doc 50 §3). Owner = code that renders it; requires = feature or plugin. */
	const CORE_SURFACES = array(
		'gpt'       => array( 'slug' => '/gpt/',       'label' => 'Trợ lý',           'owner' => 'bizcity-twinweb-addon',  'position' => 10, 'requires' => 'twinweb' ), // [2026-10-12 11:47 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.102 ST102-L10 — available only when the /gpt/ part is (see surfaces())
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

	/** Slot 8: the "Apps" launcher (TwinShell `zalo_brain_apps`), not one of the 7 surfaces. */
	const APPS_SURFACE = 'apps';

	/** @var array<string,array> app id => def · @var array<string,string> tool name / changelog id => owner extension */
	private static $apps = array(), $tools = array(), $tables = array();

	/**
	 * [2026-10-11 Johnny Chu - Chu Hoàng Anh] PHASE-0.98 S98-L1 — 1.1: Automation block id => owner · scenario JSON file => owner ·
	 * owner => manifest `mcp_tools[]` (declared, checked against the real registrations by the validator and `wp bizcity health`).
	 */
	private static $blocks = array(), $scenarios = array(), $declared_tools = array();

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
			do_action( 'zalo_brain_register' ); // Extension plugins register here (or anywhere before zalo_brain_loaded).
		}, 5 );
		add_action( 'plugins_loaded', static function () {
			self::$loaded = true;
			do_action( 'zalo_brain_loaded' ); // Registration is closed; surfaces, features, extensions are final.
		}, 20 );
		add_filter( 'zalo_brain_apps', array( __CLASS__, 'bridge_apps' ), 20, 1 );
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
			if ( self::APPS_SURFACE === $surface && ! empty( $s['app_id'] ) ) {
				$slug = (string) ( $s['public_slug'] ?? '' );
				self::register_surface( self::APPS_SURFACE, array( 'id' => (string) $s['app_id'], 'label' => (string) ( $s['label'] ?? $s['app_id'] ), 'url' => ( '' !== $slug && function_exists( 'home_url' ) ) ? home_url( $slug ) : $slug, 'owner' => $id ) );
			}
		}
		if ( ! empty( $manifest['tables_changelog'] ) ) {
			self::register_tables( (string) $manifest['tables_changelog'], $id );
		}
		// [2026-10-11 Johnny Chu - Chu Hoàng Anh] PHASE-0.98 S98-L1 (0.101 doc 30 lệch 2) — 1.1 reads what 1.0 only stored: the declared tool names
		// (validator + health compare them with register_tool()) and, when the caller gave the plugin dir (register_from_manifest), its scenario file.
		$declared = array();
		foreach ( (array) ( $manifest['mcp_tools'] ?? array() ) as $t ) {
			$t = trim( (string) $t );
			if ( '' !== $t && preg_match( '/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*){1,2}$/', $t ) && ! in_array( $t, $declared, true ) ) {
				$declared[] = $t;
			}
		}
		self::$declared_tools[ $id ] = $declared;
		$dir = isset( $manifest['_dir'] ) ? rtrim( (string) $manifest['_dir'], '/\\' ) : '';
		if ( '' !== $dir && ! empty( $manifest['scenarios'] ) && is_string( $manifest['scenarios'] ) ) {
			self::register_scenarios( $dir . '/' . ltrim( str_replace( '\\', '/', $manifest['scenarios'] ), '/' ), $id );
		}
		return true;
	}

	/**
	 * [2026-10-11 Johnny Chu - Chu Hoàng Anh] PHASE-0.98 S98-L1 — the one-line way in for an extension:
	 *   add_action( 'zalo_brain_register', fn() => BizCity_Zalo_Brain::register_from_manifest( __DIR__ . '/zalo-brain.json' ) );
	 * Reads the manifest, remembers the plugin dir (so `scenarios` resolves) and registers. Returns the decoded manifest or null.
	 */
	public static function register_from_manifest( string $file ): ?array {
		if ( '' === $file || ! is_readable( $file ) ) {
			return null;
		}
		$raw = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$m   = is_string( $raw ) ? json_decode( $raw, true ) : null;
		if ( ! is_array( $m ) || empty( $m['id'] ) ) {
			return null;
		}
		$m['_dir'] = dirname( $file );
		if ( ! isset( $m['id'] ) || sanitize_key( (string) $m['id'] ) !== basename( $m['_dir'] ) ) {
			// the validator refuses this too; at runtime we keep going (the id decides ownership, not the folder)
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[zalo-brain] manifest id "' . (string) ( $m['id'] ?? '' ) . '" differs from folder "' . basename( $m['_dir'] ) . '"' );
			}
		}
		return self::register_extension( $m ) ? $m : null;
	}

	/**
	 * [2026-10-11 Johnny Chu - Chu Hoàng Anh] PHASE-0.98 S98-L2 — an Automation block from an extension. Queued onto
	 * `bizcity_automation_register_blocks` (or registered at once when the registry already booted). Without the Automation plugin the
	 * block is only recorded (describe()['blocks']) — nothing breaks, the extension needs no `class_exists` dance.
	 * The block must be a BizCity_Automation_Block (id(), kind(), meta(), execute()); its meta() declares effect, guest_safe, required_cap.
	 */
	public static function register_block( $block, string $owner = '' ): bool {
		if ( ! is_object( $block ) || ! method_exists( $block, 'id' ) || ! method_exists( $block, 'execute' ) || ! method_exists( $block, 'meta' ) ) {
			return false;
		}
		$id = (string) $block->id();
		if ( ! preg_match( '/^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$/', $id ) ) {
			return false;
		}
		self::$blocks[ $id ] = $owner;
		$add = static function ( $registry = null ) use ( $block ): void {
			if ( ! is_object( $registry ) && class_exists( 'BizCity_Automation_Block_Registry' ) ) {
				$registry = BizCity_Automation_Block_Registry::instance();
			}
			if ( is_object( $registry ) && method_exists( $registry, 'register' ) && method_exists( $registry, 'has' ) && ! $registry->has( $block->id() ) ) {
				$registry->register( $block );
			}
		};
		if ( ( function_exists( 'did_action' ) && did_action( 'bizcity_automation_register_blocks' ) ) || ! function_exists( 'add_action' ) ) {
			$add(); // registry already booted (or no WordPress: unit tests) ⇒ now
			return true;
		}
		add_action( 'bizcity_automation_register_blocks', $add, 10, 1 );
		return true;
	}

	/**
	 * [2026-10-11 Johnny Chu - Chu Hoàng Anh] PHASE-0.98 S98-L3 — a scenario/template JSON file (same shape as Automation's
	 * templates/*.json: an array of blueprints with slug + graph) the extension contributes. Automation's seeder reads
	 * `BizCity_Zalo_Brain::scenario_files()` (filter `bizcity_automation_blueprint_files`); the blueprints hash includes it ⇒ reseed.
	 */
	public static function register_scenarios( string $file, string $owner = '' ): bool {
		$file = str_replace( '\\', '/', $file );
		if ( '' === $file || '.json' !== substr( $file, -5 ) || ! is_readable( $file ) ) {
			return false;
		}
		self::$scenarios[ $file ] = $owner;
		return true;
	}

	/** @return array<string,string> block id => owner (extensions only) */
	public static function blocks(): array { return self::$blocks; }
	/** @return string[] absolute paths of extension scenario files */
	public static function scenario_files(): array { return array_keys( self::$scenarios ); }
	/** @return array<string,string[]> owner => tool names the manifest declared */
	public static function declared_tools(): array { return self::$declared_tools; }
	/** @return array<string,string[]> owner => tool names really registered through register_tool() */
	public static function tools_by_owner(): array {
		$out = array();
		foreach ( self::$tools as $name => $owner ) {
			$out[ '' !== $owner ? $owner : 'core' ][] = $name;
		}
		return $out;
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
	 * Register a page under one of the 7 surfaces (W7 renders it inside the shell), or an app under 'apps' (slot 8).
	 * $def: id, label, cap, position, feature, render (array: spa|callback), owner; apps: + url|plugin, icon, requires.
	 */
	public static function register_surface( string $surface, array $def ): bool {
		if ( self::closed( 'register_surface' ) ) {
			return false;
		}
		$surface = sanitize_key( $surface );
		$id      = sanitize_key( (string) ( $def['id'] ?? '' ) );
		if ( self::APPS_SURFACE === $surface && '' !== $id && self::APPS_SURFACE !== $id ) {
			self::$apps[ $id ] = array_merge( $def, array( 'id' => $id ) ); // launcher picks the position when absent
			return true;
		}
		if ( ! isset( self::CORE_SURFACES[ $surface ] ) || '' === $id ) {
			return false;
		}
		self::$pages[ $surface ][ $id ] = array_merge( array( 'id' => $id, 'position' => 100 ), $def );
		return true;
	}

	/**
	 * MCP tool (tool-registry@1) → BizCity_MCP_Tool_Registry::register() on `bizcity_mcp_register_tools` (at once if it
	 * already ran). Keys: name, handler + descriptor keys; optional `feature` (skipped when absent), `owner`.
	 */
	public static function register_tool( array $tool ): bool {
		$name = (string) ( $tool['name'] ?? '' );
		if ( '' === $name || ! isset( $tool['handler'] ) ) {
			return false;
		}
		$feature = sanitize_key( (string) ( $tool['feature'] ?? '' ) );
		self::$tools[ $name ] = (string) ( $tool['owner'] ?? '' );
		// [2026-10-11 Johnny Chu - Chu Hoàng Anh] PHASE-0.98 S98-L1 (R-NB-10, validator R19 for the catalog) — a write tool says which WP cap the acting
		// user needs, or says explicitly that no standard cap fits (`no_standard_cap`). 1.1 warns; 1.2 refuses (0.101 Q101-8).
		$is_write = array_key_exists( 'read_only', $tool ) && false === $tool['read_only'];
		if ( $is_write && '' === trim( (string) ( $tool['required_cap'] ?? '' ) ) && empty( $tool['no_standard_cap'] ) && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[zalo-brain] tool ' . $name . ' is a write without required_cap / no_standard_cap (zalo-brain-extension@1.1 §6).' );
		}
		unset( $tool['feature'], $tool['owner'], $tool['no_standard_cap'], $tool['budget_ms'], $tool['effects'] );
		$add = static function () use ( $name, $tool, $feature ): bool {
			return ( '' === $feature || self::has( $feature ) ) && class_exists( 'BizCity_MCP_Tool_Registry' )
				&& false !== BizCity_MCP_Tool_Registry::register( $name, $tool );
		};
		if ( ( function_exists( 'did_action' ) && did_action( 'bizcity_mcp_register_tools' ) ) || ! function_exists( 'add_action' ) ) {
			return $add(); // S98-L1: also the no-WordPress (unit) path
		}
		add_action( 'bizcity_mcp_register_tools', static function () use ( $add ) { $add(); } );
		return true;
	}

	/** Extension-owned R-DCL changelog id → describe()['tables'] (bin/lean-scoreboard.mjs is static, scans the dir instead). */
	public static function register_tables( string $changelog_id, string $owner = '' ): bool {
		$ok = (bool) preg_match( '/^[a-z0-9][a-z0-9._-]*$/i', $changelog_id = trim( $changelog_id ) );
		if ( $ok ) {
			self::$tables[ $changelog_id ] = $owner;
		}
		return $ok;
	}

	/** Record-only entry for the legacy registries (SDK, Setting Panel): never closed, never warns, never overrides a
	 * zalo-brain-extension@1 registration. $surface '' = extension/module; else a page (or app). */
	public static function mirror( string $source, array $def, string $surface = '' ): bool {
		$id = sanitize_key( str_replace( '.', '-', (string) ( $def['id'] ?? $def['slug'] ?? '' ) ) );
		if ( '' === $id ) {
			return false;
		}
		$def = array_merge( $def, array( 'id' => $id, 'source' => $source ) );
		if ( '' === $surface ) {
			self::$extensions += array( $id => $def );
		} elseif ( self::APPS_SURFACE === $surface ) {
			self::$apps += array( $id => $def );
		} elseif ( isset( self::CORE_SURFACES[ $surface ] ) ) {
			self::$pages[ $surface ] = ( self::$pages[ $surface ] ?? array() ) + array( $id => array_merge( array( 'position' => 100 ), $def ) );
		} else {
			return false;
		}
		return true;
	}

	/** `zalo_brain_apps`@20 — append registered apps whose id no tile already uses (plugins' own entries win). */
	public static function bridge_apps( $apps ): array {
		$apps  = is_array( $apps ) ? $apps : array();
		$taken = array_flip( array_map( static function ( $a ) { return is_array( $a ) ? (string) ( $a['id'] ?? '' ) : ''; }, $apps ) );
		return array_merge( $apps, array_values( array_diff_key( self::$apps, $taken ) ) );
	}

	/** Getters: apps (id => def), tables (changelog id => owner), tools (MCP name => owner). */
	public static function apps(): array { return self::$apps; }
	public static function tables(): array { return self::$tables; }
	public static function tools(): array { return self::$tools; }

	/** [2026-10-11 10:58 AM Johnny Chu - Chu Hoàng Anh] CORE-REDUCTION WP-20 Z-6 P0-3 — registered here OR declared on disk (BizCity_Zalo_Brain_Declared): a request that skipped an extension boot keeps the answer; callers keep class_exists(). */
	public static function has( string $feature ): bool {
		$feature = sanitize_key( $feature );
		return isset( self::$features[ $feature ] ) || isset( self::declared()[ $feature ] )
			|| (bool) apply_filters( 'zalo_brain_has_feature', false, $feature );
	}

	/** @return array<string,array{version:string,owner:string}> registered on this request first, then declared-only (Z-6 P0-3). */
	public static function features(): array { return self::$features + self::declared(); }
	private static function declared(): array { return class_exists( 'BizCity_Zalo_Brain_Declared', false ) ? BizCity_Zalo_Brain_Declared::features( self::CONTRACT ) : array(); }
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
				|| ( 'automation' === $requires && class_exists( 'BizCity_Addon_Locator' ) && '' !== BizCity_Addon_Locator::file( 'automation/bootstrap.php' ) )
				|| ( 'twinweb' === $requires && function_exists( 'bizcity_twinweb_available' ) && bizcity_twinweb_available() ); // [2026-10-12 11:47 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.102 ST102-L10 — file presence, not class_exists
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
			'features'   => self::features(), // Z-6 P0-3 — registered + declared
			'extensions' => array_map(
				static function ( array $m ): array {
					return array( 'id' => (string) ( $m['id'] ?? '' ), 'name' => (string) ( $m['name'] ?? '' ), 'version' => (string) ( $m['version'] ?? '' ) );
				},
				array_values( self::$extensions )
			),
			'apps'       => array_values( self::$apps ),
			'tables'     => self::$tables,
			'tools'      => array_keys( self::$tools ),
			// [2026-10-11 Johnny Chu - Chu Hoàng Anh] PHASE-0.98 S98-L1 — 1.1: who owns what (additive keys; old FE ignores them)
			'contract_version' => self::VERSION,
			'tools_by_owner'   => self::tools_by_owner(),
			'declared_tools'   => self::$declared_tools,
			'blocks'           => self::$blocks,
			'scenarios'        => array_map( 'basename', array_keys( self::$scenarios ) ),
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
