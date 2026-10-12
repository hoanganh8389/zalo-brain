<?php
/**
 * TwinWeb — REST slice loader (always loaded, small)
 *
 * [2026-10-11 11:02 AM Johnny Chu - Chu Hoàng Anh] CORE-REDUCTION WP-20 Z-6 P0-2/P2 — the 708 KB REST controller and
 * its REST-only helpers (≈ 0.8 MB) load only where they are served, not on every request (W20-Z6 loader-map P2):
 *
 *   - REST for `bizcity-twinweb/*` (pretty `/<prefix>/bizcity-twinweb/…` or plain `?rest_route=/bizcity-twinweb/…`),
 *     the REST index (`/<prefix>/` or `?rest_route=/`, so the namespace list stays equal) and the dev-only
 *     `bizcity-diagnostics/*` REST (its probes inspect this class);
 *   - wp-admin, admin-ajax and admin-post (`is_admin()`), WP-CLI and the diagnostics CLI;
 *   - the TwinWeb cron hooks (artifact-jobs poll, citation persistence), loaded on the hook itself;
 *   - safety net: `rest_api_init` priority 0 reads the dispatched route, for URL shapes the early check missed.
 *
 * Everything else (public HTML, `/gpt/`, other REST namespaces, webhooks, other cron hooks) skips it. A caller that
 * still needs a slice class gets it on first use: the class autoloader below requires just that file, and
 * bizcity_twinweb_require_rest_slice() loads the whole slice. Access answers never depend on this: they live in
 * BizCity_TwinWeb_Access_Policy (always loaded).
 *
 * Kill switch (one switch for every W20-Z6 gate): `bizcity_axis_rest_profile` = `auto` (default, gated) | `off`
 * (load everything as before). Read from the wp-config constant BIZCITY_AXIS_REST_PROFILE first, then the option,
 * then the filter of the same name (a mu-plugin can set it).
 *
 * PHP 7.4 compatible.
 *
 * [2026-10-12 11:36 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.102 ST102-L24 — moved from modules/twinweb to core/twinweb-axis. Files are absolute paths now: the axis classes (prompt-automation bridge, REST, CRM projection, member customer 360) are always here;
 * the /gpt/ add-on registers its own REST classes (projects, CRM tasks, CRM pipeline) with register_addon_classes() and they load/autoload exactly as before.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\TwinWebAxis
 */
defined( 'ABSPATH' ) || exit;

/**
 * Load the whole TwinWeb REST slice (BizCity_TwinWeb_REST and its REST-only helpers) on first use.
 * For code outside TwinWeb that needs a slice class on a request that skipped it.
 *
 * @param string $reason Why.
 * @return bool
 */
if ( ! function_exists( 'bizcity_twinweb_require_rest_slice' ) ) {
	function bizcity_twinweb_require_rest_slice( $reason = 'lazy' ) {
		return BizCity_TwinWeb_Rest_Slice::load( $reason );
	}
}

if ( class_exists( 'BizCity_TwinWeb_Rest_Slice', false ) ) { return; }

final class BizCity_TwinWeb_Rest_Slice {

	const FLAG = 'bizcity_axis_rest_profile';

	/** REST namespaces (first path segment) served by the slice. */
	const NAMESPACES = array( 'bizcity-twinweb', 'bizcity-diagnostics' );

	/** Cron hooks owned by TwinWeb; the slice loads on the hook (priority 0), never on a plain tick. */
	const CRON_HOOKS = array( 'bizcity_twinweb_artifact_jobs_poll', 'bizcity_twinweb_persist_web_citations' );

	/** @var bool */
	private static $loaded = false;
	/** @var string */
	private static $reason = '';
	/** @var bool */
	private static $booted = false;
	/** @var array<string,string> REST classes registered by the /gpt/ add-on: class => absolute file. */
	private static $addon_files = array();

	/**
	 * Slice files, in the old bootstrap order, keyed by the one class each declares. Absolute paths.
	 * Axis classes first, then whatever the add-on registered (projects, CRM tasks, CRM pipeline).
	 *
	 * @return array<string,string>
	 */
	public static function class_files() {
		$dir   = ( defined( 'BIZCITY_TWINWEB_AXIS_DIR' ) ? BIZCITY_TWINWEB_AXIS_DIR : dirname( __DIR__ ) . '/' ) . 'includes/';
		$files = array(
			'BizCity_TwinWeb_Prompt_Automation_Bridge' => $dir . 'class-twinweb-prompt-automation-bridge.php',
			'BizCity_TwinWeb_REST'                     => $dir . 'class-twinweb-rest.php',
			'BizCity_TwinWeb_CRM_Projection'           => $dir . 'class-twinweb-crm-projection.php',
			'BizCity_TwinWeb_Member_Customer_360'      => $dir . 'class-twinweb-member-customer-360.php',
		);
		return array_merge( $files, self::$addon_files );
	}

	/**
	 * The /gpt/ add-on (modules/twinweb today, bizcity-twinweb-addon later) hands over its own REST classes. They follow the
	 * slice's rules: required with the slice, or on first use by the autoloader. Already-loaded slice ⇒ required right away.
	 *
	 * @param array<string,string> $map Class name => absolute file.
	 * @return void
	 */
	public static function register_addon_classes( array $map ) {
		foreach ( $map as $class => $file ) {
			self::$addon_files[ (string) $class ] = (string) $file;
			if ( self::$loaded && is_readable( (string) $file ) ) {
				require_once (string) $file;
			}
		}
	}

	/**
	 * `auto` (default) or `off`.
	 *
	 * @return string
	 */
	public static function mode() {
		$value = '';
		if ( defined( 'BIZCITY_AXIS_REST_PROFILE' ) ) {
			$value = (string) BIZCITY_AXIS_REST_PROFILE;
		} elseif ( function_exists( 'get_option' ) ) {
			$value = (string) get_option( self::FLAG, '' );
		}
		if ( function_exists( 'apply_filters' ) ) {
			$value = (string) apply_filters( self::FLAG, $value );
		}
		return 'off' === strtolower( trim( $value ) ) ? 'off' : 'auto';
	}

	/**
	 * The request facts the decision reads, from the PHP globals.
	 *
	 * @return array
	 */
	public static function context_from_globals() {
		return array(
			'is_admin'    => function_exists( 'is_admin' ) && is_admin(),
			'cli'         => ( defined( 'WP_CLI' ) && WP_CLI ) || ( defined( 'BIZCITY_DIAGNOSTICS_CLI' ) && BIZCITY_DIAGNOSTICS_CLI ),
			'request_uri' => isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '',
			'rest_route'  => isset( $_GET['rest_route'] ) ? (string) $_GET['rest_route'] : '', // phpcs:ignore WordPress.Security.NonceVerification
			'rest_prefix' => function_exists( 'rest_get_url_prefix' ) ? (string) rest_get_url_prefix() : 'wp-json',
		);
	}

	/**
	 * Why this request needs the slice, or '' when it does not. Pure: same input, same answer.
	 *
	 * @param array  $ctx  See context_from_globals().
	 * @param string $mode `auto` | `off`.
	 * @return string
	 */
	public static function decide( array $ctx, $mode ) {
		if ( 'off' === $mode ) {
			return 'flag_off';
		}
		if ( ! empty( $ctx['is_admin'] ) ) {
			return 'admin';
		}
		if ( ! empty( $ctx['cli'] ) ) {
			return 'cli';
		}
		$route = isset( $ctx['rest_route'] ) ? (string) $ctx['rest_route'] : '';
		if ( '' !== $route ) {
			$hit = self::route_reason( $route );
			if ( '' !== $hit ) {
				return $hit;
			}
		}
		$uri = isset( $ctx['request_uri'] ) ? (string) $ctx['request_uri'] : '';
		if ( '' === $uri ) {
			return '';
		}
		$path   = (string) parse_url( $uri, PHP_URL_PATH );
		$prefix = '/' . trim( isset( $ctx['rest_prefix'] ) && '' !== $ctx['rest_prefix'] ? (string) $ctx['rest_prefix'] : 'wp-json', '/' );
		$at     = strpos( $path, $prefix . '/' );
		if ( false === $at ) {
			// `/wp-json` with no trailing slash is the REST index too.
			return ( strlen( $path ) >= strlen( $prefix ) && substr( $path, -strlen( $prefix ) ) === $prefix ) ? 'rest_index' : '';
		}
		return self::route_reason( substr( $path, $at + strlen( $prefix ) ) );
	}

	/**
	 * @param string $route REST route such as `/bizcity-twinweb/v1/me`.
	 * @return string
	 */
	public static function route_reason( $route ) {
		$route = '/' . ltrim( rawurldecode( (string) $route ), '/' );
		if ( '/' === $route ) {
			return 'rest_index';
		}
		$first = strtolower( (string) strtok( ltrim( $route, '/' ), '/' ) );
		return in_array( $first, self::NAMESPACES, true ) ? 'rest_' . $first : '';
	}

	/**
	 * Require the whole slice once.
	 *
	 * @param string $reason Why (kept for the Loader panel / harness).
	 * @return bool
	 */
	public static function load( $reason = 'lazy' ) {
		if ( self::$loaded ) {
			return true;
		}
		self::$loaded = true;
		self::$reason = (string) $reason;
		foreach ( self::class_files() as $file ) {
			if ( is_readable( $file ) ) {
				require_once $file;
			}
		}
		return true;
	}

	/** @return bool */
	public static function is_loaded() {
		return self::$loaded;
	}

	/** @return string */
	public static function reason() {
		return self::$reason;
	}

	/**
	 * Class autoloader: a slice class asked for on a request that skipped the slice loads just its own file.
	 *
	 * @param string $class Class name.
	 * @return void
	 */
	public static function autoload( $class ) {
		$map = self::class_files();
		if ( isset( $map[ $class ] ) && is_readable( $map[ $class ] ) ) {
			require_once $map[ $class ];
		}
	}

	/**
	 * Decide for this request and wire the lazy paths. Called once from core/twinweb-axis/bootstrap.php.
	 *
	 * @return string The load reason, or '' when the slice was skipped.
	 */
	public static function boot() {
		if ( self::$booted ) {
			return self::$reason;
		}
		self::$booted = true;
		spl_autoload_register( array( __CLASS__, 'autoload' ) );

		$reason = self::decide( self::context_from_globals(), self::mode() );
		if ( '' !== $reason ) {
			self::load( $reason );
			return $reason;
		}

		// Safety net: the dispatched REST route is known by rest_api_init (REST_REQUEST is defined by then).
		add_action( 'rest_api_init', array( __CLASS__, 'maybe_load_for_dispatched_route' ), 0 );
		foreach ( self::CRON_HOOKS as $hook ) {
			add_action( $hook, array( __CLASS__, 'load_for_cron' ), 0 );
		}
		return '';
	}

	/** @return void */
	public static function maybe_load_for_dispatched_route() {
		if ( self::$loaded || ! ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}
		$route = '';
		if ( isset( $GLOBALS['wp'] ) && is_object( $GLOBALS['wp'] ) && isset( $GLOBALS['wp']->query_vars['rest_route'] ) ) {
			$route = (string) $GLOBALS['wp']->query_vars['rest_route'];
		}
		$hit = '' !== $route ? self::route_reason( $route ) : '';
		if ( '' !== $hit ) {
			self::load( $hit . '_late' );
		}
	}

	/** @return void */
	public static function load_for_cron() {
		self::load( 'cron' );
	}
}

