<?php
/**
 * Twin Shell — Public page /twin/.
 *
 * Renders the iframe-shell wrapper. Mirrors the conventions used by
 * BizCity_TwinChat_Public_Page (rewrite + theme isolation + minimal HTML5
 * shell), but keeps the front-end as plain JS — no Vite build needed for v1.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Modules\TwinShell
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_Twin_Shell_Page {

	const QUERY_VAR   = 'bizcity_twin_shell';
	const REWRITE_KEY = '^twin/?$';
	const OPTION_KEY  = 'bizcity_twin_shell_rewrite_flushed_v2';

	// [2026-09-15 Johnny Chu - Chu Hoàng Anh] PHASE-0-SETTING-PANEL-G5 — serve the built CoreUI Control Panel artifact from assets/dist.
	const PANEL_QUERY_VAR   = 'bizcity_twin_panel';
	const PANEL_REWRITE_KEY = '^twin/panel/?$';
	const PANEL_DIST_DIR     = 'assets/dist/';
	// Marks a /twin/ load that came from the Control Panel fail-open, so the shell renders instead of bouncing to the wp-admin wrapper. Also makes the cause visible in the URL and access logs.
	const PANEL_FALLBACK_ARG = 'bizcity_panel_unavailable';

	private static $instance = null;
	private $registered = false;

	/**
	 * Whether this /twin/ request is being rendered inside a host frame.
	 *
	 * [2026-09-16 02:30 PM Johnny Chu - Chu Hoàng Anh] PHASE-TWINSHELL-CHROME-HOTFIX
	 * Set by maybe_render() and consumed by render() so the embed marker can be
	 * carried into the shell config without re-deriving it from globals.
	 *
	 * @var bool
	 */
	private $is_embedded = false;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Canonical URL for the Twin Shell, safe on every permalink configuration.
	 *
	 * - Pretty permalinks ON  → `home_url('/twin/')`
	 * - Pretty permalinks OFF → `home_url('/?bizcity_twin_shell=1')` (fallback
	 *   that uses the registered query_var and avoids the 404 caused when the
	 *   `^twin/?$` rewrite rule cannot match on plain permalinks).
	 *
	 * @param array $args Extra query args to append (e.g. `['plugin' => 'crm']`).
	 * @return string
	 */
	public static function shell_url( array $args = [] ) {
		$pretty = (string) get_option( 'permalink_structure', '' ) !== '';
		$base   = $pretty
			? home_url( '/twin/' )
			: home_url( '/?' . self::QUERY_VAR . '=1' );
		if ( ! empty( $args ) ) {
			$base = add_query_arg( $args, $base );
		}
		return $base;
	}

	/**
	 * Query args that mark a shell URL as host-embedded and loop-safe.
	 *
	 * [2026-09-16 03:30 PM Johnny Chu - Chu Hoàng Anh] PHASE-TWINSHELL-CHROME-HOTFIX2
	 *
	 * `bizcity_embed` renders the standalone shell instead of redirecting, and
	 * `bizcity_admin_wrapper` is the structural loop-breaker: any /twin/ URL that
	 * originated inside the wp-admin wrapper can never trigger the admin hand-off
	 * again, so a redirect loop is impossible by construction.
	 *
	 * @param bool $embedded Whether the current request is host-embedded.
	 * @return array
	 */
	public static function embedded_args( $embedded ) {
		return $embedded
			? array( 'bizcity_embed' => '1', 'bizcity_admin_wrapper' => '1' )
			: array();
	}

	/**
	 * R-ROUTE-7 validator for the canonical `r` (plugin-relative route) parameter.
	 *
	 * [2026-09-18 Johnny Chu - Chu Hoàng Anh] PHASE-0-RULE-URL-ROUTE P1 — shared so every place
	 * that forwards `r` (this class's own `/twin/` -> wrapper redirect, and the wp-admin
	 * wrapper's `render_page()`) applies the SAME rule instead of drifting like the `_iurl`
	 * check already has across 3 separate copies. `r` is NEVER used as a full URL — the shell
	 * always joins it to the registry's own entry URL for the plugin — but it must still be a
	 * same-origin relative path so nothing can smuggle an absolute/protocol-relative URL through
	 * a parameter operators and integrations treat as inert route text.
	 *
	 * @param string $value Raw, still-unslashed value.
	 * @return bool
	 */
	public static function is_safe_route( $value ) {
		$value = (string) $value;
		if ( '' === $value || strlen( $value ) > 2048 ) {
			return false;
		}
		if ( '/' !== substr( $value, 0, 1 ) ) {
			return false;
		}
		if ( 0 === strpos( $value, '//' ) ) {
			return false;
		}
		if ( false !== strpos( $value, '://' ) || false !== strpos( $value, '\\' ) ) {
			return false;
		}
		if ( preg_match( '/[\x00-\x1F\x7F]/', $value ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Canonical URL for the built CoreUI Control Panel artifact.
	 *
	 * [2026-09-15 Johnny Chu - Chu Hoàng Anh] PHASE-0-SETTING-PANEL-G5 — the
	 * artifact is served by PHP so the REST config can be injected without
	 * editing compiled files.
	 *
	 * @param array $args Extra query args to append.
	 * @return string
	 */
	public static function panel_url( array $args = [] ) {
		$pretty = (string) get_option( 'permalink_structure', '' ) !== '';
		$base   = $pretty
			? home_url( '/twin/panel/' )
			: home_url( '/?' . self::PANEL_QUERY_VAR . '=1' );
		if ( ! empty( $args ) ) {
			$base = add_query_arg( $args, $base );
		}
		return $base;
	}

	/**
	 * Absolute path of the built Control Panel entry file, or '' when absent.
	 *
	 * @return string
	 */
	public static function panel_index_file() {
		$file = BIZCITY_TWIN_SHELL_DIR . self::PANEL_DIST_DIR . 'index.html';
		return is_readable( $file ) ? $file : '';
	}

	/**
	 * Return whether the current operator may open the Control Panel.
	 *
	 * [2026-09-21 05:00 PM Johnny Chu - Chu Hoàng Anh] PHASE-0-SETTING-PANEL-HOTFIX —
	 * Super Admin is a network identity and must not be denied by a filtered or
	 * blog-scoped `manage_options` check on a mapped tenant.
	 *
	 * @return bool
	 */
	public static function can_access_panel() {
		return ( function_exists( 'is_super_admin' ) && is_super_admin() )
			|| current_user_can( 'manage_options' )
			|| current_user_can( 'manage_network' );
	}

	/**
	 * Locale key the built Control Panel should render with.
	 *
	 * [2026-09-15 Johnny Chu - Chu Hoàng Anh] PHASE-0-SETTING-PANEL-G5 — map the
	 * WordPress document locale to a catalog key the bundle ships.
	 *
	 * @return string
	 */
	public static function panel_locale() {
		// [2026-09-16 Johnny Chu - Chu Hoàng Anh] PHASE-0-SETTING-PANEL-G6-03 — honour the operator's own
		// WordPress language (User Preferences) before the site language.
		$raw = function_exists( 'get_user_locale' ) ? (string) get_user_locale() : '';
		if ( '' === $raw ) {
			$raw = (string) get_bloginfo( 'language' );
		}
		$raw = str_replace( '-', '_', $raw );
		if ( '' === $raw ) {
			return 'en';
		}
		if ( 0 === strpos( $raw, 'vi' ) ) {
			return 'vi_VN';
		}
		return 'en';
	}

	public function register() {
		// [2026-07-09 Johnny Chu] PHASE-TWINSHELL-IMPL — idempotent register.
		if ( $this->registered ) {
			return;
		}
		$this->registered = true;

		add_action( 'init',              [ $this, 'add_rewrite_rule' ] );
		add_filter( 'query_vars',        [ $this, 'add_query_var' ] );
		add_action( 'template_redirect', [ $this, 'maybe_render' ] );
		// Disable Query Monitor on /twin/.
		add_filter( 'qm/dispatch/html',  [ $this, 'disable_qm' ] );
		add_filter( 'qm/process',        [ $this, 'disable_qm' ] );
	}

	public function add_rewrite_rule() {
		// [2026-06-09 Johnny Chu] HOTFIX — flush removed from init:10 (Transposh/WC loop).
		// One-time flush is handled by admin_init guard in modules/twinshell/bootstrap.php.
		add_rewrite_rule(
			self::REWRITE_KEY,
			'index.php?' . self::QUERY_VAR . '=1',
			'top'
		);
		// [2026-09-15 Johnny Chu - Chu Hoàng Anh] PHASE-0-SETTING-PANEL-G5 — built Control Panel route.
		add_rewrite_rule(
			self::PANEL_REWRITE_KEY,
			'index.php?' . self::PANEL_QUERY_VAR . '=1',
			'top'
		);
	}

	public function add_query_var( $vars ) {
		$vars[] = self::QUERY_VAR;
		$vars[] = self::PANEL_QUERY_VAR;
		return $vars;
	}

	public function maybe_render() {
		// [2026-09-15 Johnny Chu - Chu Hoàng Anh] PHASE-0-SETTING-PANEL-G5 — built Control Panel artifact route.
		if ( get_query_var( self::PANEL_QUERY_VAR ) ) {
			$this->maybe_render_panel();
			return;
		}

		// [2026-10-15 11:05 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.107 50 §6 — a fresh clone can carry stale
		// rewrite rules (flush only runs at admin_init); /twin/ is the home of a new site, so match the path too.
		if ( ! get_query_var( self::QUERY_VAR ) && ! self::request_is_shell_path() ) {
			return;
		}
		status_header( 200 );

		if ( ! is_user_logged_in() ) {
			// [2026-07-09 Johnny Chu] PHASE-TWINSHELL-ACTIVITY-LOG — audit unauthenticated shell access.
			$this->emit_activity_event( 'shell.guard.not_logged_in', array(
				'outcome' => 'login_screen',
				'route'   => home_url( add_query_arg( null, null ) ),
			) );
			// [2026-10-15 11:05 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.107 50 §6 — guests get the shell's own
			// login screen (Google · BizCity ID · email/password, same as TwinWeb AuthModal) instead of a
			// bounce to wp-login.php. Way back: add_filter( 'bizcity_twin_shell_guest_login_screen', '__return_false' ).
			if ( apply_filters( 'bizcity_twin_shell_guest_login_screen', true ) ) {
				nocache_headers();
				$this->render_guest_login();
				exit;
			}
			$redirect = home_url( add_query_arg( null, null ) );
			wp_safe_redirect( wp_login_url( $redirect ) );
			exit;
		}

		// [2026-10-15 11:40 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.107 50 §9 R-2 — `/twin/?next=<same-site URL>`:
		// the small-win success screen sends the owner here so the shell's login screen (Google) runs first,
		// then this hop lands on the QR deep link. Same-host only, never back into another `next` (no loop).
		$next = self::next_target();
		if ( '' !== $next ) {
			wp_safe_redirect( $next, 302 );
			exit;
		}

		// [2026-09-16 12:00 PM Johnny Chu - Chu Hoàng Anh] PHASE-TWINSHELL-CHROME — originally sent
		// operators to the native wp-admin wrapper so WordPress renders its own admin bar/sidebar,
		// with the embedded iframe carrying bizcity_embed=1 to keep rendering the standalone shell.
		// [2026-09-23] Disabled by request — `/twin/` must stay `/twin/` for every visitor, admin
		// or not; no hand-off to `admin.php?page=bizcity-twinchat`. `$is_embedded` is still computed
		// (consumed by render()/embedded_args() below), the admin-wrapper redirect itself is gone.
		$is_embedded = ! empty( $_GET['bizcity_embed'] )
			|| ! empty( $_GET['bizcity_iframe'] )
			|| ( isset( $_SERVER['HTTP_SEC_FETCH_DEST'] ) && 'iframe' === strtolower( (string) $_SERVER['HTTP_SEC_FETCH_DEST'] ) );
		$this->is_embedded = $is_embedded;

		$this->render();
		exit;
	}

	/**
	 * Serve the built CoreUI Control Panel artifact from `assets/dist/`.
	 *
	 * [2026-09-15 Johnny Chu - Chu Hoàng Anh] PHASE-0-SETTING-PANEL-G5 — the
	 * compiled bundle is never edited; PHP only injects the same-origin REST
	 * config and rewrites relative asset URLs to the plugin URL. Missing
	 * artifact fails open to the plain-JS shell instead of a blank page.
	 *
	 * @return void
	 */
	private function maybe_render_panel() {
		if ( ! is_user_logged_in() ) {
			$this->emit_activity_event( 'shell.guard.not_logged_in', array(
				'outcome' => 'blocked',
				'route'   => self::panel_url(),
			) );
			wp_safe_redirect( wp_login_url( self::panel_url() ) );
			exit;
		}

		// Fail closed on capability; the artifact is an admin surface. Super Admin
		// is explicitly accepted because network identity can outlive blog roles.
		if ( ! self::can_access_panel() ) {
			$this->emit_activity_event( 'shell.guard.capability_denied', array(
				'outcome' => 'blocked',
				'route'   => self::panel_url(),
			) );
			wp_die( esc_html__( 'You do not have permission to access the Control Panel.', 'bizcity-twin-ai' ) );
		}

		$index = self::panel_index_file();
		if ( '' === $index ) {
			// Fail open: artifact not deployed yet → keep the working shell.
			$this->emit_activity_event( 'shell.guard.panel_artifact_missing', array(
				'outcome' => 'degraded',
				'route'   => self::panel_url(),
			) );
			wp_safe_redirect( self::shell_url( array( self::PANEL_FALLBACK_ARG => '1' ) ) );
			exit;
		}

		$html = (string) file_get_contents( $index );
		if ( '' === $html ) {
			wp_safe_redirect( self::shell_url( array( self::PANEL_FALLBACK_ARG => '1' ) ) );
			exit;
		}

		$dist_url = BIZCITY_TWIN_SHELL_URL . self::PANEL_DIST_DIR;

		// Rewrite relative artifact references to absolute plugin URLs so the
		// browser never requests /twin/panel/assets/* (which WordPress cannot route).
		// [2026-09-16 Johnny Chu - Chu Hoàng Anh] PHASE-0-SETTING-PANEL-G5-HOTFIX — the bundle now ships under
		// fixed names (assets/twin-shell.js|css) and the web server sends them with a 30-day max-age, so
		// operators kept running the first combined build after every deploy. Version each asset by its
		// own mtime+size: a redeploy changes the URL, an unchanged file keeps its cache.
		$dist_dir = BIZCITY_TWIN_SHELL_DIR . self::PANEL_DIST_DIR;
		$html     = (string) preg_replace_callback(
			'#="\./assets/([A-Za-z0-9._/-]+)"#',
			static function ( $match ) use ( $dist_url, $dist_dir ) {
				$relative = (string) $match[1];
				$path     = $dist_dir . 'assets/' . $relative;
				$version  = is_readable( $path ) ? ( filemtime( $path ) . '-' . filesize( $path ) ) : BIZCITY_TWIN_SHELL_VERSION;
				return '="' . esc_url( $dist_url . 'assets/' . $relative . '?ver=' . rawurlencode( (string) $version ) ) . '"';
			},
			$html
		);
		$html = str_replace( '="./manifest.json"', '="' . esc_url( $dist_url . 'manifest.json' ) . '"', $html );
		$html = str_replace( '="./favicon.ico"', '="' . esc_url( $dist_url . 'favicon.ico' ) . '"', $html );

		// [2026-09-15 Johnny Chu - Chu Hoàng Anh] PHASE-0-SETTING-PANEL-G5 — keep the document language in sync with the injected locale.
		$html_lang = 'vi_VN' === self::panel_locale() ? 'vi' : 'en';
		$html = preg_replace( '/<html lang="[^"]*"/', '<html lang="' . esc_attr( $html_lang ) . '"', $html, 1 );

		$config = (string) wp_json_encode( [
			'restRoot' => esc_url_raw( rest_url( 'bizcity-twinchat/v1/' ) ),
			'nonce'    => wp_create_nonce( 'wp_rest' ),
			'panelUrl' => esc_url_raw( self::panel_url() ),
			'shellUrl' => esc_url_raw( self::shell_url() ),
			'userId'   => (int) get_current_user_id(),
			// [2026-09-28 11:42 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.83 UI-10 — allow the shared Channel Gateway quicksetup app to mount inside TwinShell.
			'quicksetupJsUrl' => esc_url_raw( BIZCITY_TWIN_AI_URL . 'core/channel-gateway/assets/dist/quicksetup-app.js?ver=' . rawurlencode( is_readable( BIZCITY_TWIN_AI_DIR . 'core/channel-gateway/assets/dist/quicksetup-app.js' ) ? (string) filemtime( BIZCITY_TWIN_AI_DIR . 'core/channel-gateway/assets/dist/quicksetup-app.js' ) : BIZCITY_TWIN_CORE_VERSION ) ),
			'quicksetupCssUrl' => esc_url_raw( BIZCITY_TWIN_AI_URL . 'core/channel-gateway/assets/dist/quicksetup-app.css?ver=' . rawurlencode( is_readable( BIZCITY_TWIN_AI_DIR . 'core/channel-gateway/assets/dist/quicksetup-app.css' ) ? (string) filemtime( BIZCITY_TWIN_AI_DIR . 'core/channel-gateway/assets/dist/quicksetup-app.css' ) : BIZCITY_TWIN_CORE_VERSION ) ),
			// [2026-09-15 Johnny Chu - Chu Hoàng Anh] PHASE-0-SETTING-PANEL-G5 — expose the WordPress document locale so the panel can pick vi_VN without a second request.
			'locale'   => self::panel_locale(),
			// [2026-09-16 Johnny Chu - Chu Hoàng Anh] PHASE-0-SETTING-PANEL-G6-03 — apply the saved theme/density/motion on first paint instead of one browser's localStorage.
			'appearance' => class_exists( 'BizCity_Twin_Shell_Appearance' )
				? BizCity_Twin_Shell_Appearance::effective( (int) get_current_user_id() )
				: null,
		] );

		$inject = '<script data-cfasync="false">window.BIZCITY_TWIN_SHELL = ' . $config . ';</script>' . "\n";
		if ( false !== strpos( $html, '</head>' ) ) {
			$html = str_replace( '</head>', $inject . '</head>', $html );
		} else {
			$html = $inject . $html;
		}

		$this->emit_activity_event( 'shell.nav.open_panel', array(
			'route'   => self::panel_url(),
			'target'  => 'control-panel',
			'outcome' => 'success',
		) );

		// The HTML carries a per-user nonce and the versioned asset URLs, so it must never be served from a
		// page cache: a stale copy would pin an old bundle and an expired nonce.
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'X-Robots-Tag: noindex, nofollow', true );
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- compiled artifact, config injected as JSON.
		exit;
	}

	public function disable_qm( $val ) {
		if ( get_query_var( self::QUERY_VAR ) || get_query_var( self::PANEL_QUERY_VAR ) ) {
			return false;
		}
		return $val;
	}

	/**
	 * Emit TwinShell activity into canonical event stream as milestone rows.
	 *
	 * Fail-open: shell rendering must continue even if telemetry fails.
	 *
	 * @param string $milestone_type Shell action code (e.g. shell.nav.open_plugin).
	 * @param array  $payload        Extra payload fields.
	 */
	public function emit_activity_event( $milestone_type, array $payload = array() ) {
		if ( ! class_exists( 'BizCity_Twin_Event_Bus' ) || ! class_exists( 'BizCity_Twin_Event_Taxonomy' ) ) {
			return;
		}
		if ( ! method_exists( 'BizCity_Twin_Event_Bus', 'dispatch_v2' ) ) {
			return;
		}

		$event_payload = array_merge(
			array(
				'milestone_type' => (string) $milestone_type,
				'surface'        => 'twinshell',
				'action'         => (string) $milestone_type,
				'outcome'        => isset( $payload['outcome'] ) ? (string) $payload['outcome'] : 'success',
			),
			$payload
		);

		// [2026-07-09 Johnny Chu] PHASE-TWINSHELL-ACTIVITY-LOG — stable session grouping for shell events.
		$session_id = 'shell_' . (int) get_current_blog_id() . '_' . (int) get_current_user_id();

		try {
			BizCity_Twin_Event_Bus::dispatch_v2(
				BizCity_Twin_Event_Taxonomy::MILESTONE,
				$event_payload,
				array(
					'event_source' => 'system',
					'user_id'      => (int) get_current_user_id(),
					'session_id'   => $session_id,
				)
			);
		} catch ( \Throwable $e ) {
			error_log( '[TwinShell] activity emit failed: ' . $e->getMessage() );
		}
	}

	private function render() {
		$registry = BizCity_Twin_Shell_Registry::instance();
		$plugins  = $registry->all();

		// Filter to plugins the current user can see AND that are unlocked
		// (core entries OR non-core whose `requires` is satisfied). Locked
		// non-core entries are kept in `$locked_map` so bookmarked URLs can
		// still render a friendly "Pro / not active" notice instead of a
		// silent fallback to TwinChat.
		$visible    = [];
		$locked_map = [];

		// [2026-06-08 Johnny Chu] PHASE-MEMBERSHIP — resolve current user's plan for gate check.
		// [2026-06-09 Johnny Chu] HOTFIX — also consider hub master_level (site API key tier).
		// Two independent money tiers:
		//   - master_level (site tier from hub API key: free|pro|master_pro|master_premium)
		//   - user_plan (local membership: free|pro|plus)
		// If the hub has set this site to pro/premium, admin users should not see features
		// gated behind "pro" as locked. Use whichever tier is higher.
		// [2026-10-09 21:10 Johnny Chu - Chu Hoàng Anh] PHASE-0.96 D96-23 — membership retired: user_plan is always 'free'; the hub master_level below is the only tier.
		$user_plan = 'free';
		// Map hub master_level → local plan slug for comparison.
		// [2026-06-10 Johnny Chu] HOTFIX — per-site option
		$hub_level        = (string) get_option( 'bizcity_hub_master_level', 'free' );
		$hub_level_map    = array(
			'free'             => 'free',
			'pro'              => 'pro',
			'master_pro'       => 'pro',
			'master_premium'   => 'plus',
			'premium'          => 'plus',
			'master_plus'      => 'plus',
			'plus'             => 'plus',
		);
		$hub_plan         = isset( $hub_level_map[ $hub_level ] ) ? $hub_level_map[ $hub_level ] : 'free';
		// Use whichever grants more access.
		if ( BizCity_Twin_Shell_Registry::plan_order( $hub_plan ) > BizCity_Twin_Shell_Registry::plan_order( $user_plan ) ) {
			$user_plan = $hub_plan;
		}

		$denied_map = [];
		$notice_map = [];
		$req_early  = isset( $_GET['plugin'] ) ? sanitize_key( wp_unslash( $_GET['plugin'] ) ) : '';
		foreach ( $plugins as $p ) {
			if ( ! empty( $p['capability'] ) && ! current_user_can( $p['capability'] ) ) {
				// [2026-09-30 Claude Opus 5.5] PHASE-0.84 D-84-12 — remembered so a bookmarked link says why.
				$denied_map[ $p['id'] ] = $p;
				continue;
			}

			// [2026-10-05 10:15 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.91-AX-LIC — licensed add-on (Automation): Premium 1API master first,
			// then the plugin itself. A locked entry KEEPS its ActivityBar icon (badge PREMIUM / PRO) and opens its notice INSIDE the
			// shell (iframe → /twin/?twin_locked=<id>), so the ActivityBar never disappears.
			if ( ! empty( $p['license'] ) || ! empty( $p['upsell'] ) ) {
				$kind = class_exists( 'BizCity_Twin_Addon_License' ) ? BizCity_Twin_Addon_License::lock_kind( $p ) : '';
				if ( '' !== $kind ) {
					$p['lock_kind']     = $kind;
					$p['plan_locked']   = false; // the shell navigates in place, no full-page reload to a standalone card
					$p['plugin_locked'] = false;
					// [2026-10-23 Johnny Chu - Chu Hoàng Anh] PHASE-0.126 — one lock badge: PRO (gold) for every locked add-on; the notice text still names the Premium plan.
					$p['plan_badge']    = 'premium' === $kind ? 'PRO' : ( '' !== (string) $p['badge'] ? (string) $p['badge'] : 'PRO' );
					$p['mode']          = 'link';
					$p['target_url']    = self::shell_url( array( 'twin_locked' => $p['id'] ) );
					$p['route_mode']    = 'legacy';
					$p['nav_plugin']    = '';
					$notice_map[ $p['id'] ] = $p;
					if ( ! empty( $p['upsell'] ) || $req_early === $p['id'] ) {
						$visible[] = $p;
					}
					continue;
				}
				$p['lock_kind']     = '';
				$p['plan_locked']   = false;
				$p['plugin_locked'] = false;
				$p['plan_badge']    = (string) $p['badge'];
				$visible[]          = $p;
				continue;
			}

			$has_plan_gate = ! empty( $p['plan'] ) && 'free' !== $p['plan'];

			if ( $has_plan_gate ) {
				// [2026-09-24 Johnny Chu] PHASE-TWINSHELL-NAV — a plan-gated entry only appears when its
				// plugin is actually loaded. No plugin ⇒ no icon (no more PRO upsell placeholders);
				// a bookmarked `?plugin=` URL still gets the "not active" notice via `$locked_map`.
				// Same absence rule as `plugin_locked` below, but evaluated regardless of plan tier.
				$hub_covers_gate = BizCity_Twin_Shell_Registry::plan_order( $hub_plan )
				                   >= BizCity_Twin_Shell_Registry::plan_order( $p['plan'] );
				if ( ! empty( $p['requires'] )
				     && ( ! empty( $p['pro_package'] ) || ! $hub_covers_gate )
				     && ! BizCity_Twin_Shell_Registry::requirement_met( $p['requires'] ) ) {
					$locked_map[ $p['id'] ] = $p;
					continue;
				}

				// Plan-gated entries whose plugin is present stay in ActivityBar (plan_locked ⇒ upgrade incentive).
				// plan_locked  = user tier is below required tier.
				// plugin_locked = plan ok but plugin (requires) not installed.
				$plan_locked = BizCity_Twin_Shell_Registry::plan_order( $user_plan )
				               < BizCity_Twin_Shell_Registry::plan_order( $p['plan'] );

				// [2026-06-09 Johnny Chu] HOTFIX — if hub_plan covers the plan requirement,
				// bypass plugin_locked (don't require the add-on class to be present).
				// Rationale: master_pro site = plugin is provisioned server-side; if the class
				// doesn't exist the /crm/ iframe will give its own error — not TwinShell's job.
				$hub_covers_plan = BizCity_Twin_Shell_Registry::plan_order( $hub_plan )
				                   >= BizCity_Twin_Shell_Registry::plan_order( $p['plan'] );

				// [2026-09-13 09:15 PM Johnny Chu - Chu Hoàng Anh] PHASE-1.29 — private Pro utilities stay locked when their package is absent, even if the Hub plan is sufficient.
				$plugin_locked = ( ! $plan_locked )
				                 && ( ! empty( $p['pro_package'] ) || ! $hub_covers_plan )
				                 && ( ! empty( $p['requires'] ) )
				                 && ( ! BizCity_Twin_Shell_Registry::requirement_met( $p['requires'] ) );

				$p['plan_locked']   = $plan_locked;
				$p['plan_badge']    = strtoupper( $p['plan'] ); // e.g. 'PRO', 'PLUS'
				$p['plugin_locked'] = $plugin_locked;
				$visible[] = $p;
				continue;
			}

			// Non-plan-gated: original requires gate.
			if ( ! empty( $p['locked'] ) ) {
				$locked_map[ $p['id'] ] = $p;
				continue;
			}
			$p['plan_locked']   = false;
			$p['plan_badge']    = '';
			$p['plugin_locked'] = false;
			$visible[] = $p;
		}

		// [2026-09-30 Claude Opus 5.5] PHASE-0.84 D-84-1 — the browser renders `plugins` in this order.
		$visible = BizCity_Twin_Shell_Registry::sort_for_activity_bar( $visible );
		foreach ( $visible as $i => $p_entry ) {
			$order = isset( BizCity_Twin_Shell_Registry::ACTIVITY_ORDER[ $p_entry['id'] ] ) ? BizCity_Twin_Shell_Registry::ACTIVITY_ORDER[ $p_entry['id'] ] : 1000;
			$visible[ $i ]['activity_primary'] = $order < 100;
		}

		// [2026-10-09 10:32 PM Johnny Chu - Chu Hoàng Anh] CORE-REDUCTION WP-20 W20-L7 (D-W20-2) — app entries (group 'apps') leave the
		// ActivityBar for `apps[]` (one "Apps" tile + square-tile launcher). `$visible` keeps them for ?plugin= resolution below.
		$split      = $registry->split_activity_bar( $visible, get_current_user_id() );
		$bar        = $split['bar'];
		$apps       = $split['apps'];
		$app_ids    = array_map( static function ( $a ) {
			return (string) $a['id'];
		}, $apps );
		$visible    = array_values( array_filter( $visible, static function ( $p ) use ( $app_ids ) {
			return ! BizCity_Twin_Shell_Registry::is_app_entry( $p ) || in_array( (string) $p['id'], $app_ids, true );
		} ) );

		// [2026-10-05 10:15 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.91-AX-LIC — the in-shell notice page of a locked add-on.
		$locked_req = isset( $_GET['twin_locked'] ) ? sanitize_key( wp_unslash( $_GET['twin_locked'] ) ) : '';
		if ( '' !== $locked_req ) {
			// [2026-10-05 10:50 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.91-AX-LIC — the notice is an iframe page only. A TOP-level load of
			// ?twin_locked= (the shell once copied it into the address bar) goes back to the clean shell URL, so the ActivityBar stays.
			if ( ! $this->is_embedded ) {
				wp_safe_redirect( self::shell_url( array( 'plugin' => $locked_req ) ) );
				exit;
			}
			// [2026-10-09 11:30 PM Johnny Chu - Chu Hoàng Anh] owner: a site without the add-on still sees its page (e.g. "Đội Zalo") and
			// ACTIVATES IN PLACE — the notice posts back here, the plugin is activated, the top window reopens the module. No plugins.php trip.
			if ( isset( $notice_map[ $locked_req ] ) && 'POST' === strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) && ! empty( $_POST['bizcity_addon_activate'] ) ) {
				$err = $this->activate_addon_in_place( $notice_map[ $locked_req ] );
				if ( '' === $err ) {
					$this->render_top_redirect( self::shell_url( array( 'plugin' => $locked_req ) ) );
					return;
				}
				$notice_map[ $locked_req ]['activate_error'] = $err;
			}
			if ( isset( $notice_map[ $locked_req ] ) ) {
				$this->emit_activity_event( 'shell.guard.addon_locked', array(
					'outcome'   => 'blocked',
					'plugin_id' => $locked_req,
					'reason'    => (string) $notice_map[ $locked_req ]['lock_kind'],
					'route'     => self::shell_url( array( 'plugin' => $locked_req ) ),
				) );
				$this->render_addon_notice( $notice_map[ $locked_req ] );
				return;
			}
			// Unlocked meanwhile (licence synced / plugin activated): reopen the real module in the top window.
			$this->render_top_redirect( self::shell_url( array( 'plugin' => $locked_req ) ) );
			return;
		}

		// Resolve initial plugin from ?plugin=, falling back to default.
		$req_plugin = isset( $_GET['plugin'] ) ? sanitize_key( wp_unslash( $_GET['plugin'] ) ) : '';

		// [2026-06-08 Johnny Chu] PHASE-MEMBERSHIP — bookmarked URL hitting a
		// plan-locked or plugin-locked entry → short-circuit with the appropriate notice.
		if ( '' !== $req_plugin ) {
			foreach ( $visible as $p_entry ) {
				if ( $p_entry['id'] !== $req_plugin ) {
					continue;
				}
				if ( ! empty( $p_entry['plan_locked'] ) ) {
					// [2026-07-09 Johnny Chu] PHASE-TWINSHELL-ACTIVITY-LOG — track plan gate blocks.
					$this->emit_activity_event( 'shell.guard.plan_locked', array(
						'outcome'       => 'blocked',
						'plugin_id'     => (string) $p_entry['id'],
						'route'         => self::shell_url( array( 'plugin' => (string) $p_entry['id'] ) ),
						'user_plan'     => (string) $user_plan,
						'required_plan' => isset( $p_entry['plan'] ) ? (string) $p_entry['plan'] : 'free',
					) );
					$this->render_plan_locked_notice( $p_entry );
					return;
				}
				if ( ! empty( $p_entry['plugin_locked'] ) ) {
					// [2026-07-09 Johnny Chu] PHASE-TWINSHELL-ACTIVITY-LOG — track unavailable plugin blocks.
					$this->emit_activity_event( 'shell.guard.plugin_locked', array(
						'outcome'   => 'blocked',
						'plugin_id' => (string) $p_entry['id'],
						'route'     => self::shell_url( array( 'plugin' => (string) $p_entry['id'] ) ),
					) );
					$this->render_locked_notice( $p_entry );
					return;
				}
				break;
			}
		}

		// Bookmarked URL hitting a (plugin not installed) locked entry.
		if ( '' !== $req_plugin && isset( $locked_map[ $req_plugin ] ) ) {
			// [2026-07-09 Johnny Chu] PHASE-TWINSHELL-ACTIVITY-LOG — locked plugin reached via deep-link.
			$this->emit_activity_event( 'shell.guard.plugin_locked', array(
				'outcome'   => 'blocked',
				'plugin_id' => (string) $req_plugin,
				'route'     => self::shell_url( array( 'plugin' => (string) $req_plugin ) ),
			) );
			$this->render_locked_notice( $locked_map[ $req_plugin ] );
			return;
		}

		// [2026-09-30 Claude Opus 5.5] PHASE-0.84 D-84-12 — no silent fallback when the user may not use
		// the requested module: say so, log it, and never hand out its iframe URL.
		if ( '' !== $req_plugin && isset( $denied_map[ $req_plugin ] ) && class_exists( 'BizCity_Twin_Module_Access' ) ) {
			$this->emit_activity_event( 'shell.guard.capability_denied', array(
				'outcome'   => 'blocked',
				'plugin_id' => (string) $req_plugin,
				'route'     => self::shell_url( array( 'plugin' => (string) $req_plugin ) ),
			) );
			BizCity_Twin_Module_Access::render_denied( $req_plugin, self::shell_url( self::embedded_args( $this->is_embedded ) ) );
			return;
		}

		$initial = '';
		foreach ( $visible as $p ) {
			// [2026-10-09 10:32 PM Johnny Chu - Chu Hoàng Anh] CORE-REDUCTION WP-20 W20-L7 — ?plugin=apps (the launcher tile) is no page.
			if ( 'launcher' === $p['mode'] ) {
				continue;
			}
			if ( $p['id'] === $req_plugin ) {
				$initial = $p['id'];
				break;
			}
		}
		if ( '' === $initial ) {
			// [2026-09-30 Claude Opus 5.5] PHASE-0.84 D-84-2 — first entry this user may open, in ActivityBar order.
			$initial = $registry->default_id( $visible );
		}

		$initial_url = $initial ? $registry->build_iframe_url( $initial, $_GET ) : '';
		if ( $initial && isset( $notice_map[ $initial ] ) ) {
			// [2026-10-05 10:15 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.91-AX-LIC — a locked add-on opens its notice, never its module URL.
			$initial_url = add_query_arg( 'bizcity_iframe', '1', (string) $notice_map[ $initial ]['target_url'] );
		}

		// [2026-07-09 Johnny Chu] PHASE-TWINSHELL-ACTIVITY-LOG — core shell navigation activity evidence.
		$open_route = '' !== $req_plugin
			? self::shell_url( array( 'plugin' => (string) $req_plugin ) )
			: self::shell_url();
		$this->emit_activity_event( 'shell.nav.open_shell', array(
			'route'   => $open_route,
			'target'  => 'shell',
			'outcome' => 'success',
		) );

		if ( '' !== $initial ) {
			$nav_action = '' !== $req_plugin ? 'shell.nav.open_deep_link' : 'shell.nav.open_plugin';
			$this->emit_activity_event( $nav_action, array(
				'plugin_id' => (string) $initial,
				'route'     => (string) $initial_url,
				'target'    => (string) $initial,
				'outcome'   => 'success',
			) );
		}

		$config = (string) wp_json_encode( [
			'restRoot'      => esc_url_raw( rest_url( 'bizcity-twinchat/v1/' ) ),
			'nonce'         => wp_create_nonce( 'wp_rest' ),
			// [2026-09-28 11:42 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.83 UI-10 — owner surfaces build same-origin admin links from the canonical WP admin base.
			'adminUrl'      => esc_url_raw( admin_url() ),
			'plugins'       => $bar,
			// [2026-10-09 10:32 PM Johnny Chu - Chu Hoàng Anh] CORE-REDUCTION WP-20 W20-L7/U1 — launcher tiles + the admin's "Thêm ứng dụng" target.
			'apps'          => $apps,
			'appsManage'    => BizCity_Twin_Shell_Registry::apps_manage_target( get_current_user_id() ),
			'defaultPlugin' => $initial,
			'initialUrl'    => $initial_url,
			// [2026-09-16 02:30 PM Johnny Chu - Chu Hoàng Anh] PHASE-TWINSHELL-CHROME-HOTFIX — carry the embed + loop-breaker markers so nested navigations resolve to the embedded shell instead of bouncing back into wp-admin.
			'shellUrl'      => esc_url_raw( self::shell_url( self::embedded_args( $this->is_embedded ) ) ),
			'pluginUrl'     => BIZCITY_TWIN_SHELL_URL,
			// [2026-10-15 11:05 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.107 50 §6 — account chip at the rail bottom (avatar · name · Đăng xuất).
			'user'          => self::current_user_card(),
		] );

		$ver       = BIZCITY_TWIN_SHELL_VERSION;
		$site_name = esc_html( get_bloginfo( 'name' ) );
		$lang      = esc_attr( get_bloginfo( 'language' ) );

		$js_file   = BIZCITY_TWIN_SHELL_DIR . 'assets/twin-shell.js';
		$css_file  = BIZCITY_TWIN_SHELL_DIR . 'assets/twin-shell.css';
		$js_ver    = file_exists( $js_file )  ? filemtime( $js_file )  : $ver;
		$css_ver   = file_exists( $css_file ) ? filemtime( $css_file ) : $ver;

		$shell_js  = BIZCITY_TWIN_SHELL_URL . 'assets/twin-shell.js?ver='  . $js_ver;
		$shell_css = BIZCITY_TWIN_SHELL_URL . 'assets/twin-shell.css?ver=' . $css_ver;

		// Phase 0.13 — primitives bundle (picker, source upload, monitor stub).
		$prim_js_file  = BIZCITY_TWIN_SHELL_DIR . 'assets/primitives/twin-primitives.js';
		$prim_css_file = BIZCITY_TWIN_SHELL_DIR . 'assets/primitives/twin-primitives.css';
		$upload_js_file = BIZCITY_TWIN_SHELL_DIR . 'assets/primitives/twin-source-upload.js';
		$prim_js  = BIZCITY_TWIN_SHELL_URL . 'assets/primitives/twin-primitives.js?ver='  . ( file_exists( $prim_js_file )  ? filemtime( $prim_js_file )  : $ver );
		$prim_css = BIZCITY_TWIN_SHELL_URL . 'assets/primitives/twin-primitives.css?ver=' . ( file_exists( $prim_css_file ) ? filemtime( $prim_css_file ) : $ver );
		$upload_js = BIZCITY_TWIN_SHELL_URL . 'assets/primitives/twin-source-upload.js?ver=' . ( file_exists( $upload_js_file ) ? filemtime( $upload_js_file ) : $ver );
		$prim_cfg = (string) wp_json_encode( [
			'restRoot' => esc_url_raw( rest_url( BizCity_Twin_Shell_Primitives::NS . '/' ) ),
			'nonce'    => wp_create_nonce( 'wp_rest' ),
			'userId'   => get_current_user_id(),
			'plugin'   => 'twinshell',
		] );

		header( 'Content-Type: text/html; charset=utf-8' );
		echo '<!DOCTYPE html>' . "\n";
		echo '<html lang="' . $lang . '">' . "\n";
		echo '<head>' . "\n";
		echo '<meta charset="utf-8">' . "\n";
		echo '<meta name="viewport" content="width=device-width, initial-scale=1">' . "\n";
		echo '<title>Twin — ' . $site_name . '</title>' . "\n";
		echo '<link rel="stylesheet" href="' . esc_url( $shell_css ) . '">' . "\n";
		echo '<link rel="stylesheet" href="' . esc_url( $prim_css ) . '">' . "\n";
		echo '<style>html,body{margin:0;padding:0;height:100%;overflow:hidden;background:#0f1115;color:#e6e6e6;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;}</style>' . "\n";
		echo '</head>' . "\n";
		echo '<body>' . "\n";
		echo '<div id="twin-shell"></div>' . "\n";
		echo '<script data-cfasync="false">window.BIZCITY_TWIN_SHELL = ' . $config . ';</script>' . "\n";
		echo '<script data-cfasync="false">window.BIZCITY_TWIN_PRIMITIVES_CFG = ' . $prim_cfg . ';</script>' . "\n";
		echo '<script data-cfasync="false" src="' . esc_url( $prim_js ) . '"></script>' . "\n";
		echo '<script data-cfasync="false" src="' . esc_url( $upload_js ) . '"></script>' . "\n";
		echo '<script data-cfasync="false" src="' . esc_url( $shell_js ) . '"></script>' . "\n";
		echo '</body></html>' . "\n";
	}

	/**
	 * True when the request path is exactly the shell path (/twin or /twin/), query string ignored.
	 *
	 * [2026-10-15 11:05 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.107 50 §6 — fallback for clones whose
	 * rewrite_rules option predates the /twin/ rule.
	 *
	 * @return bool
	 */
	public static function request_is_shell_path() {
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
		$base = rtrim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
		return (bool) preg_match( '#^' . preg_quote( $base, '#' ) . '/twin/?$#', $path );
	}

	/**
	 * Validated `next` query arg: a same-site absolute URL to hop to after login, or ''.
	 *
	 * [2026-10-15 11:40 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.107 50 §9 R-2 — wp_validate_redirect keeps it
	 * on allowed hosts (this site); a target that itself carries `next=` is refused so two hops can't loop.
	 *
	 * @return string
	 */
	public static function next_target() {
		$raw = isset( $_GET['next'] ) ? trim( (string) wp_unslash( $_GET['next'] ) ) : '';
		if ( '' === $raw || false !== strpos( $raw, 'next=' ) || ! preg_match( '#^https?://#i', $raw ) ) {
			return '';
		}
		return (string) wp_validate_redirect( $raw, '' );
	}

	/**
	 * Where the guest login screen returns to: this exact shell request (keeps `plugin`, `next`, …).
	 *
	 * @return string
	 */
	private static function guest_return_url() {
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		$home = wp_parse_url( home_url( '/' ) );
		$url  = ( $home['scheme'] ?? 'https' ) . '://' . ( $home['host'] ?? '' ) . ( isset( $home['port'] ) ? ':' . $home['port'] : '' ) . $uri;
		return (string) wp_validate_redirect( $url, self::shell_url() );
	}

	/**
	 * Logged-in user card for the rail's account chip; empty array for guests.
	 *
	 * @return array<string,mixed>
	 */
	private static function current_user_card() {
		$user = wp_get_current_user();
		if ( ! $user || ! $user->exists() ) {
			return array();
		}
		return array(
			'id'        => (int) $user->ID,
			'name'      => (string) $user->display_name,
			'email'     => (string) $user->user_email,
			'avatarUrl' => esc_url_raw( (string) get_avatar_url( $user->ID, array( 'size' => 64 ) ) ),
			'logoutUrl' => esc_url_raw( wp_logout_url( self::shell_url() ) ),
		);
	}

	/**
	 * Google + BizCity ID login URLs that come back to $return.
	 *
	 * [2026-10-15 11:05 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.107 50 §6 — same split as
	 * BizCity_TwinWeb_Page::get_page_html(): a client site (WPOSSO_Client loaded) logs in through
	 * bizgpt-oauth-client `?auth=sso`; the hub uses login-with-google `auto_google_login=1`.
	 *
	 * @param string $return Absolute URL to land on after login.
	 * @return array{google:string,bizcity:string}
	 */
	public static function sso_urls( $return ) {
		if ( class_exists( 'WPOSSO_Client' ) ) {
			$urls = array(
				'google'  => add_query_arg( array( 'auth' => 'sso', 'redirect_to' => rawurlencode( $return ) ), home_url( '/' ) ),
				'bizcity' => add_query_arg( array( 'auth' => 'sso', 'provider' => 'bizcity', 'redirect_to' => rawurlencode( $return ) ), home_url( '/' ) ),
			);
		} else {
			// [2026-10-15 11:40 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.107 50 §9 R-3 — `auto_google_login` is only
			// handled by login-with-google; without it the button would just open wp-login, so hide it ('').
			$has_google = function_exists( '\RtCamp\GoogleLogin\plugin' );
			$urls = array(
				'google'  => $has_google ? add_query_arg( 'auto_google_login', '1', wp_login_url( $return ) ) : '',
				'bizcity' => wp_login_url( $return ),
			);
		}
		return (array) apply_filters( 'bizcity_twin_shell_sso_urls', $urls, $return );
	}

	/**
	 * Guest screen for /twin/: a bare rail (brand + login icon) and the login card.
	 *
	 * [2026-10-15 11:05 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.107 50 §6 — mirrors TwinWeb AuthModal:
	 * Google · BizCity ID · "hoặc" · email/username + password. Password login posts JSON to
	 * bizcity-twinweb/v1/auth/login (wp_signon); if that route is missing (TwinWeb axis not loaded)
	 * the same form falls back to a native wp-login.php POST.
	 *
	 * @return void
	 */
	private function render_guest_login() {
		$return    = self::guest_return_url();
		$sso       = self::sso_urls( $return );
		$site_name = (string) get_bloginfo( 'name' );
		$lang      = esc_attr( get_bloginfo( 'language' ) );
		$logo_id   = (int) get_theme_mod( 'custom_logo' );
		$logo      = $logo_id ? (string) wp_get_attachment_image_url( $logo_id, 'thumbnail' ) : (string) get_site_icon_url( 64 );
		$cfg       = (string) wp_json_encode( array(
			'loginRest' => esc_url_raw( rest_url( 'bizcity-twinweb/v1/auth/login' ) ),
			'returnUrl' => esc_url_raw( $return ),
		) );
		$lost_url  = wp_lostpassword_url( $return );
		$reg_url   = get_option( 'users_can_register' ) ? wp_registration_url() : '';
		$initial   = function_exists( 'mb_substr' ) ? mb_strtoupper( mb_substr( $site_name, 0, 1 ) ) : strtoupper( substr( $site_name, 0, 1 ) );

		header( 'Content-Type: text/html; charset=utf-8' );
		?>
<!DOCTYPE html>
<html lang="<?php echo $lang; ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?php echo esc_html( 'Đăng nhập — ' . $site_name ); ?></title>
<style>
:root{--bg:#0f1115;--rail:#16181d;--card:#1b1e24;--line:#2a2e36;--fg:#e6e6e6;--mut:#9aa0aa;--pri:#ffffff;--pri-fg:#111;--err:#f87171}
*{box-sizing:border-box}html,body{margin:0;height:100%;background:var(--bg);color:var(--fg);font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif}
.tsg{display:flex;height:100%}
.tsg-rail{width:52px;flex:0 0 52px;background:var(--rail);border-right:1px solid var(--line);display:flex;flex-direction:column;align-items:center;justify-content:space-between;padding:10px 0}
.tsg-brand,.tsg-login{width:36px;height:36px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-weight:700}
.tsg-brand{background:var(--pri);color:var(--pri-fg);overflow:hidden}.tsg-brand img{width:100%;height:100%;object-fit:cover}
.tsg-login{color:var(--fg);border:1px solid var(--line);background:transparent;cursor:pointer}
.tsg-main{flex:1;display:flex;align-items:center;justify-content:center;padding:16px;overflow:auto}
.tsg-card{width:100%;max-width:360px;background:var(--card);border:1px solid var(--line);border-radius:16px;padding:28px 24px 24px;box-shadow:0 20px 50px rgba(0,0,0,.35)}
.tsg-head{text-align:center;margin-bottom:18px}.tsg-head .tsg-brand{margin:0 auto 10px;width:44px;height:44px}
.tsg-head h1{font-size:18px;margin:0 0 4px}.tsg-head p{margin:0;font-size:13px;color:var(--mut)}
.tsg-btn{width:100%;height:42px;border-radius:10px;border:1px solid var(--line);background:transparent;color:var(--fg);font-size:14px;font-weight:500;display:flex;align-items:center;justify-content:center;gap:10px;cursor:pointer;text-decoration:none;margin-bottom:8px}
.tsg-btn:hover{background:#23262d}.tsg-btn--pri{background:var(--pri);color:var(--pri-fg);border-color:var(--pri);font-weight:600}.tsg-btn--pri:hover{background:#e5e5e5}
.tsg-b{width:16px;height:16px;border-radius:4px;background:var(--pri);color:var(--pri-fg);font-size:10px;font-weight:700;display:flex;align-items:center;justify-content:center}
.tsg-or{display:flex;align-items:center;gap:10px;margin:12px 0;color:var(--mut);font-size:12px}.tsg-or:before,.tsg-or:after{content:"";flex:1;height:1px;background:var(--line)}
.tsg-f label{display:block;font-size:12px;color:var(--mut);margin:0 0 4px}
.tsg-f input{width:100%;height:42px;border-radius:10px;border:1px solid var(--line);background:var(--bg);color:var(--fg);padding:0 12px;font-size:14px;margin-bottom:10px}
.tsg-f input:focus{outline:2px solid #4b5563;outline-offset:0}
.tsg-row{display:flex;justify-content:space-between;font-size:12px;margin:-4px 0 12px}.tsg-row a{color:var(--mut);text-decoration:none}.tsg-row a:hover{color:var(--fg)}
.tsg-err{display:none;color:var(--err);font-size:13px;margin:0 0 10px}
@media (max-width:480px){.tsg-rail{display:none}}
</style>
</head>
<body>
<div class="tsg">
  <aside class="tsg-rail" aria-label="Activity bar">
    <div class="tsg-brand"><?php echo $logo ? '<img src="' . esc_url( $logo ) . '" alt="">' : esc_html( $initial ); ?></div>
    <button type="button" class="tsg-login" title="Đăng nhập" aria-label="Đăng nhập" onclick="document.getElementById('tsg-user').focus()">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/></svg>
    </button>
  </aside>
  <main class="tsg-main">
    <div class="tsg-card">
      <div class="tsg-head">
        <div class="tsg-brand"><?php echo $logo ? '<img src="' . esc_url( $logo ) . '" alt="">' : esc_html( $initial ); ?></div>
        <h1><?php echo esc_html( $site_name ); ?></h1>
        <p>Đăng nhập để dùng trợ lý của bạn</p>
      </div>
      <?php if ( '' !== (string) $sso['google'] ) : ?><a class="tsg-btn tsg-btn--pri" href="<?php echo esc_url( $sso['google'] ); ?>">
        <svg width="16" height="16" viewBox="0 0 24 24" aria-hidden="true"><path fill="#4285F4" d="M23.745 12.27c0-.79-.07-1.54-.19-2.27h-11.3v4.51h6.47c-.29 1.48-1.14 2.73-2.4 3.58v3h3.86c2.26-2.09 3.56-5.17 3.56-8.82z"/><path fill="#34A853" d="M12.255 24c3.24 0 5.95-1.08 7.93-2.91l-3.86-3c-1.08.72-2.45 1.16-4.07 1.16-3.13 0-5.78-2.11-6.73-4.96h-3.98v3.09C3.515 21.3 7.615 24 12.255 24z"/><path fill="#FBBC05" d="M5.525 14.29c-.25-.72-.38-1.49-.38-2.29s.14-1.57.38-2.29V6.62h-3.98a11.86 11.86 0 000 10.76l3.98-3.09z"/><path fill="#EA4335" d="M12.255 4.75c1.77 0 3.35.61 4.6 1.8l3.42-3.42C18.205 1.19 15.495 0 12.255 0c-4.64 0-8.74 2.7-10.71 6.62l3.98 3.09c.95-2.85 3.6-4.96 6.73-4.96z"/></svg>
        Đăng nhập bằng Google
      </a><?php endif; ?>
      <a class="tsg-btn" href="<?php echo esc_url( $sso['bizcity'] ); ?>"><span class="tsg-b">B</span>Đăng nhập bằng BizCity ID</a>
      <div class="tsg-or">hoặc</div>
      <form class="tsg-f" id="tsg-form" method="post" action="<?php echo esc_url( site_url( 'wp-login.php', 'login_post' ) ); ?>">
        <label for="tsg-user">Email hoặc tên đăng nhập</label>
        <input id="tsg-user" name="log" type="text" autocomplete="username" required>
        <label for="tsg-pass">Mật khẩu</label>
        <input id="tsg-pass" name="pwd" type="password" autocomplete="current-password" required>
        <input type="hidden" name="rememberme" value="forever">
        <input type="hidden" name="redirect_to" value="<?php echo esc_attr( $return ); ?>">
        <div class="tsg-row">
          <a href="<?php echo esc_url( $lost_url ); ?>">Quên mật khẩu?</a>
          <?php if ( '' !== $reg_url ) : ?><a href="<?php echo esc_url( $reg_url ); ?>">Tạo tài khoản</a><?php endif; ?>
        </div>
        <p class="tsg-err" id="tsg-err" role="alert"></p>
        <button class="tsg-btn tsg-btn--pri" id="tsg-submit" type="submit">Đăng nhập</button>
      </form>
    </div>
  </main>
</div>
<script data-cfasync="false">
(function(){
  var cfg = <?php echo $cfg; ?>;
  var form = document.getElementById('tsg-form'), err = document.getElementById('tsg-err'), btn = document.getElementById('tsg-submit');
  var native = false;
  form.addEventListener('submit', function(e){
    if (native || !window.fetch) return;
    e.preventDefault();
    err.style.display = 'none'; btn.disabled = true; btn.textContent = 'Đang đăng nhập…';
    fetch(cfg.loginRest, {method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/json'},
      body: JSON.stringify({username: form.log.value, password: form.pwd.value})})
    .then(function(r){ if (r.status === 404) { throw 'no_route'; } return r.json(); })
    .then(function(res){
      if (res && res.success) { window.location.href = cfg.returnUrl; return; }
      err.textContent = (res && res.message) || 'Đăng nhập thất bại.'; err.style.display = 'block';
      btn.disabled = false; btn.textContent = 'Đăng nhập';
    })
    .catch(function(){ native = true; form.submit(); });
  });
})();
</script>
</body>
</html>
		<?php
	}

	public static function on_activate() {
		delete_option( self::OPTION_KEY );
	}

	/**
	 * Render the "Pro / not active" notice for a locked plugin when reached
	 * via a bookmarked /twin/?plugin=<id> URL. Stand-alone HTML page (no
	 * shell JS / iframe) so the user sees the message instantly.
	 *
	 * @param array $p Locked plugin entry (from the registry).
	 */
	private function render_locked_notice( $p ) {
		$shell_url   = esc_url( self::shell_url( self::embedded_args( $this->is_embedded ) ) );
		$account_url = 'https://bizcity.vn/my-account/';
		$label       = isset( $p['label'] ) ? (string) $p['label'] : (string) $p['id'];
		$emoji       = isset( $p['emoji'] ) && $p['emoji'] !== '' ? (string) $p['emoji'] : '🔒';
		$desc        = isset( $p['desc'] ) ? (string) $p['desc'] : '';
		$pro_package = isset( $p['pro_package'] ) ? (string) $p['pro_package'] : '';
		$lang        = esc_attr( get_bloginfo( 'language' ) );
		$site_name   = esc_html( get_bloginfo( 'name' ) );

		header( 'Content-Type: text/html; charset=utf-8' );
		echo '<!DOCTYPE html>' . "\n";
		echo '<html lang="' . $lang . '">' . "\n";
		echo '<head>' . "\n";
		echo '<meta charset="utf-8">' . "\n";
		echo '<meta name="viewport" content="width=device-width, initial-scale=1">' . "\n";
		echo '<title>' . esc_html( $label ) . ' — ' . $site_name . '</title>' . "\n";
		echo '<style>'
			. 'html,body{margin:0;padding:0;height:100%;background:#0f1115;color:#e6e6e6;'
			. 'font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;}'
			. '.wrap{min-height:100%;display:flex;align-items:center;justify-content:center;padding:24px;}'
			. '.card{max-width:520px;width:100%;background:#171a21;border:1px solid #262b36;'
			. 'border-radius:16px;padding:32px;box-shadow:0 8px 32px rgba(0,0,0,.4);text-align:center;}'
			. '.emoji{font-size:48px;line-height:1;margin-bottom:12px;}'
			. 'h1{font-size:22px;font-weight:600;margin:0 0 8px;color:#fff;}'
			. '.badge{display:inline-block;background:#3b2f1a;color:#facc15;font-size:11px;'
			. 'font-weight:600;letter-spacing:.5px;text-transform:uppercase;padding:4px 10px;'
			. 'border-radius:999px;margin-bottom:16px;}'
			. 'p{font-size:14px;line-height:1.6;color:#a1a8b7;margin:0 0 8px;}'
			. '.actions{margin-top:24px;display:flex;gap:12px;justify-content:center;flex-wrap:wrap;}'
			. '.btn{display:inline-flex;align-items:center;justify-content:center;padding:10px 18px;'
			. 'border-radius:10px;font-size:14px;font-weight:500;text-decoration:none;transition:.15s;}'
			. '.btn-primary{background:#6366f1;color:#fff;}'
			. '.btn-primary:hover{background:#4f46e5;}'
			. '.btn-ghost{background:transparent;color:#a1a8b7;border:1px solid #2a3040;}'
			. '.btn-ghost:hover{color:#fff;border-color:#3a4257;}'
			. '</style>' . "\n";
		echo '</head><body>' . "\n";
		echo '<div class="wrap"><div class="card">' . "\n";
		echo '<div class="emoji">' . esc_html( $emoji ) . '</div>' . "\n";
		echo '<div class="badge">' . esc_html__( 'Pro / Add-on', 'bizcity-twin-ai' ) . '</div>' . "\n";
		echo '<h1>' . esc_html( $label ) . '</h1>' . "\n";
		$notice = $pro_package !== ''
			? sprintf( 'Tính năng này thuộc gói Pro của BizCity. Cài đặt và kích hoạt plugin %s để tiếp tục.', $pro_package )
			: 'Tính năng này thuộc gói Pro của BizCity. Cài đặt và kích hoạt plugin tương ứng để tiếp tục.';
		echo '<p>' . esc_html( $notice ) . '</p>' . "\n";
		if ( '' !== $desc ) {
			echo '<p style="margin-top:8px;color:#7b8294;">' . esc_html( $desc ) . '</p>' . "\n";
		}
		echo '<div class="actions">' . "\n";
		echo '<a class="btn btn-primary" href="' . esc_url( $account_url ) . '" target="_blank" rel="noopener">'
			. esc_html__( 'Nâng cấp / Quản lý gói', 'bizcity-twin-ai' ) . '</a>' . "\n";
		echo '<a class="btn btn-ghost" href="' . $shell_url . '">'
			. esc_html__( '← Quay lại Twin', 'bizcity-twin-ai' ) . '</a>' . "\n";
		echo '</div></div></div>' . "\n";
		echo '</body></html>' . "\n";
	}

	/**
	 * In-shell notice of a locked licensed add-on (rendered inside the shell iframe, the ActivityBar stays on screen).
	 *
	 * premium ⇒ "Tài khoản 1API master cần là Premium" + "Nâng cấp lên Premium" (bizcity.vn/product/premium/)
	 * plugin  ⇒ "Kích hoạt plugin" (per-site activation on plugins.php) or "nhờ quản trị viên";
	 *           elsewhere: "Tải BizCity Automation (GitHub)".
	 *
	 * @param array $p registry entry with lock_kind
	 */
	private function render_addon_notice( $p ) {
		// [2026-10-05 10:20 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.91-AX-LIC — one card, two reasons, never the plan/account page.
		$kind      = (string) ( $p['lock_kind'] ?? 'plugin' );
		$label     = isset( $p['label'] ) ? (string) $p['label'] : (string) $p['id'];
		$emoji     = isset( $p['emoji'] ) && '' !== $p['emoji'] ? (string) $p['emoji'] : '🔒';
		$lang      = esc_attr( get_bloginfo( 'language' ) );
		$site_name = esc_html( get_bloginfo( 'name' ) );
		$tier      = class_exists( 'BizCity_Twin_Addon_License' ) ? BizCity_Twin_Addon_License::hub_tier() : 'free';
		$tiers     = array( 'free' => 'Free', 'pro' => 'Pro', 'premium' => 'Premium', 'enterprise' => 'Enterprise' );
		$links     = array();

		if ( 'premium' === $kind ) {
			// [2026-10-23 Johnny Chu - Chu Hoàng Anh] PHASE-0.126 — one lock badge: PRO (gold) for every locked add-on; the notice text still names the Premium plan.
			$badge   = 'PRO';
			$badge_bg = 'linear-gradient(135deg,#f59e0b,#d97706)';
			$lines   = array(
				sprintf( '%s chỉ dùng được khi tài khoản 1API master của website ở gói Premium trở lên.', $label ),
				sprintf( 'Tài khoản hiện tại: gói %s.', isset( $tiers[ $tier ] ) ? $tiers[ $tier ] : ucfirst( $tier ) ),
			);
			$action  = array( 'label' => 'Nâng cấp lên Premium', 'url' => BizCity_Twin_Addon_License::PREMIUM_URL, 'target' => '_blank' );
		} else {
			$badge    = '' !== (string) ( $p['plan_badge'] ?? '' ) ? (string) $p['plan_badge'] : 'PRO';
			$badge_bg = 'linear-gradient(135deg,#f59e0b,#d97706)';
			// [2026-10-09 11:05 PM Johnny Chu - Chu Hoàng Anh] the notice names THIS entry's add-on (CRM, Automation…), never a hard-coded one.
			$addon    = class_exists( 'BizCity_Twin_Addon_License' ) ? BizCity_Twin_Addon_License::addon_of( $p ) : array( 'name' => 'BizCity Automation', 'github' => 'https://github.com/hoanganh8389/bizcity-automation' );
			$act      = class_exists( 'BizCity_Twin_Addon_License' ) ? BizCity_Twin_Addon_License::plugin_action( $p ) : array( 'kind' => 'download', 'label' => 'Tải ' . $addon['name'] . ' (GitHub)', 'url' => $addon['github'] );
			$lines    = array( sprintf( 'Tài khoản đủ điều kiện. Website cần cài và kích hoạt plugin %s để dùng %s.', ! empty( $p['pro_package'] ) ? (string) $p['pro_package'] : 'tương ứng', $label ) );
			if ( 'download' !== $act['kind'] ) {
				$lines = array( sprintf( 'Tài khoản đủ điều kiện. Plugin %s đã được cài trên website, chỉ cần kích hoạt để dùng %s.', ! empty( $p['pro_package'] ) ? (string) $p['pro_package'] : 'tương ứng', $label ) );
			}
			// [2026-10-09 11:30 PM Johnny Chu - Chu Hoàng Anh] `activate` = a POST back to this notice (activate in place, see activate_addon_in_place).
			$action = '' !== $act['url'] ? array( 'label' => $act['label'], 'url' => $act['url'], 'target' => 'activate' === $act['kind'] ? '_top' : '_blank', 'post' => 'activate' === $act['kind'] ) : null;
			if ( ! empty( $p['activate_error'] ) ) {
				array_unshift( $lines, (string) $p['activate_error'] );
			}

			// [2026-10-05 11:20 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.91-AX-LIC — concrete steps + plugins.php link even when the
			// viewer cannot one-click activate (no button was shown when the viewer lacked activate_plugins), and the twin-ai GitHub prerequisite.
			$plugins_url = admin_url( 'plugins.php' );
			$twin_url    = 'https://github.com/hoanganh8389/bizcity-twin-ai';
			$lines[]     = 'Lưu ý: ' . $addon['name'] . ' chỉ kích hoạt được khi website đã cài plugin BizCity Twin AI (bản tải từ GitHub: hoanganh8389/bizcity-twin-ai). Hãy tải và cài bản này trước.';
			if ( 'download' === $act['kind'] ) {
				$lines[] = 'Các bước: (1) tải BizCity Twin AI và ' . $addon['name'] . ' từ GitHub, (2) vào Plugins → Add New → Upload Plugin để cài, (3) bấm Kích hoạt ' . $addon['name'] . ' rồi tải lại trang này.';
			} else {
				$lines[] = 'Các bước: (1) mở trang Plugins, (2) tìm "' . $addon['name'] . '" và bấm Kích hoạt, (3) tải lại trang này.';
			}
			$links = array();
			if ( 'activate' !== $act['kind'] ) {
				$links[] = array( 'label' => 'Mở trang Plugins để kích hoạt', 'url' => $plugins_url, 'target' => '_top' );
			}
			$links[] = array( 'label' => 'Tải BizCity Twin AI (GitHub)', 'url' => $twin_url, 'target' => '_blank' );
			if ( 'download' === $act['kind'] && '' !== $act['url'] ) {
				$action = null;
				array_unshift( $links, array( 'label' => $act['label'], 'url' => $act['url'], 'target' => '_blank' ) );
			}
		}

		header( 'Content-Type: text/html; charset=utf-8' );
		echo '<!DOCTYPE html>' . "\n";
		echo '<html lang="' . $lang . '"><head><meta charset="utf-8">' . "\n";
		echo '<meta name="viewport" content="width=device-width, initial-scale=1">' . "\n";
		echo '<title>' . esc_html( $label ) . ' — ' . $site_name . '</title>' . "\n";
		echo '<style>'
			. 'html,body{margin:0;padding:0;height:100%;background:#0f1115;color:#e6e6e6;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;}'
			. '.wrap{min-height:100%;display:flex;align-items:center;justify-content:center;padding:24px;box-sizing:border-box;}'
			. '.card{max-width:520px;width:100%;background:#171a21;border:1px solid #262b36;border-radius:16px;padding:32px;box-shadow:0 8px 32px rgba(0,0,0,.4);text-align:center;}'
			. '.emoji{font-size:48px;line-height:1;margin-bottom:12px;}'
			. 'h1{font-size:22px;font-weight:600;margin:0 0 8px;color:#fff;}'
			. '.badge{display:inline-block;padding:4px 12px;border-radius:999px;font-size:11px;font-weight:700;letter-spacing:.8px;color:#fff;margin-bottom:16px;background:' . esc_attr( $badge_bg ) . ';}'
			. 'p{font-size:14px;line-height:1.6;color:#a1a8b7;margin:0 0 8px;}'
			. '.actions{margin-top:24px;display:flex;gap:12px;justify-content:center;flex-wrap:wrap;}'
			. '.btn{display:inline-flex;align-items:center;justify-content:center;padding:10px 18px;border-radius:10px;font-size:14px;font-weight:500;text-decoration:none;color:#fff;background:' . esc_attr( $badge_bg ) . ';}'
			. '</style></head><body>' . "\n";
		echo '<div class="wrap"><div class="card">' . "\n";
		echo '<div class="emoji">' . esc_html( $emoji ) . '</div>' . "\n";
		echo '<div class="badge">' . esc_html( $badge ) . '</div>' . "\n";
		echo '<h1>' . esc_html( $label ) . '</h1>' . "\n";
		foreach ( $lines as $line ) {
			echo '<p>' . esc_html( $line ) . '</p>' . "\n";
		}
		$buttons = array();
		if ( $action ) {
			$buttons[] = $action;
		}
		if ( ! empty( $links ) ) {
			$buttons = array_merge( $buttons, $links );
		}
		if ( $buttons ) {
			echo '<div class="actions">';
			foreach ( $buttons as $b ) {
				if ( ! empty( $b['post'] ) ) {
					echo '<form method="post" style="margin:0;">'
						. wp_nonce_field( 'bizcity_addon_activate_' . (string) $p['id'], '_wpnonce', false, false )
						. '<input type="hidden" name="bizcity_addon_activate" value="1">'
						. '<button class="btn" type="submit" style="border:0;cursor:pointer;">' . esc_html( $b['label'] ) . '</button></form>';
					continue;
				}
				echo '<a class="btn" href="' . esc_url( $b['url'] ) . '" target="' . esc_attr( $b['target'] ) . '"'
					. ( '_blank' === $b['target'] ? ' rel="noopener"' : '' ) . '>' . esc_html( $b['label'] ) . '</a>';
			}
			echo '</div>' . "\n";
		}
		echo '</div></div></body></html>' . "\n";
	}

	/**
	 * [2026-10-09 11:30 PM Johnny Chu - Chu Hoàng Anh] Activate a locked add-on from its in-shell notice. Only a `plugin` lock (never
	 * `premium`), only the entry's own plugin file, only when it is on disk, only for a user with activate_plugins, nonce per entry.
	 *
	 * @param array $p registry entry with lock_kind
	 * @return string '' on success, else a short reason shown on the notice
	 */
	private function activate_addon_in_place( array $p ): string {
		$id = (string) ( $p['id'] ?? '' );
		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'bizcity_addon_activate_' . $id ) ) {
			return 'Phiên đã hết hạn, hãy tải lại trang và bấm lại.';
		}
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return 'Tài khoản của bạn không có quyền kích hoạt plugin. Nhờ quản trị viên website.';
		}
		if ( 'plugin' !== (string) ( $p['lock_kind'] ?? '' ) || ! class_exists( 'BizCity_Twin_Addon_License' ) ) {
			return 'Mục này không thể kích hoạt tại chỗ.';
		}
		$addon = BizCity_Twin_Addon_License::addon_of( $p );
		if ( ! BizCity_Twin_Addon_License::plugin_on_disk( $addon['file'] ) ) {
			return 'Chưa thấy plugin ' . $addon['name'] . ' trên website. Hãy tải về và cài (Plugins → Add New → Upload Plugin) rồi bấm lại.';
		}
		if ( ! function_exists( 'activate_plugin' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$r = activate_plugin( $addon['file'] );
		if ( is_wp_error( $r ) ) {
			return 'Kích hoạt chưa được: ' . wp_strip_all_tags( $r->get_error_message() );
		}
		$this->emit_activity_event( 'shell.addon.activated', array( 'outcome' => 'ok', 'plugin_id' => $id ) );
		return '';
	}

	/** Tiny page that sends the TOP window (not the shell iframe) to $url. */
	private function render_top_redirect( $url ) {
		header( 'Content-Type: text/html; charset=utf-8' );
		echo '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body><script>(window.top||window).location.href='
			. wp_json_encode( esc_url_raw( $url ) ) . ';</script></body></html>';
	}

	/**
	 * Render the "Plan required" notice when a plan-gated plugin is accessed
	 * without the required membership tier.
	 *
	 * [2026-06-08 Johnny Chu] PHASE-MEMBERSHIP — plan gate notice.
	 *
	 * @param array $p Plugin entry (from registry, with plan_badge set).
	 */
	private function render_plan_locked_notice( $p ) {
		$shell_url   = esc_url( self::shell_url( self::embedded_args( $this->is_embedded ) ) );
		$account_url = 'https://bizcity.vn/my-account/';
		$label       = isset( $p['label'] ) ? (string) $p['label'] : (string) $p['id'];
		$emoji       = isset( $p['emoji'] ) && '' !== $p['emoji'] ? (string) $p['emoji'] : '⭐';
		$desc        = isset( $p['desc'] ) ? (string) $p['desc'] : '';
		$plan_badge  = isset( $p['plan_badge'] ) ? strtoupper( (string) $p['plan_badge'] ) : 'PRO';
		$pro_package = isset( $p['pro_package'] ) ? (string) $p['pro_package'] : '';
		$lang        = esc_attr( get_bloginfo( 'language' ) );
		$site_name   = esc_html( get_bloginfo( 'name' ) );

		// Badge gradient: PRO = gold, PLUS/PREMIUM = purple.
		$badge_bg = ( 'PRO' === $plan_badge )
			? 'linear-gradient(135deg,#f59e0b,#d97706)'
			: 'linear-gradient(135deg,#8b5cf6,#6d28d9)';

		header( 'Content-Type: text/html; charset=utf-8' );
		echo '<!DOCTYPE html>' . "\n";
		echo '<html lang="' . $lang . '">' . "\n";
		echo '<head>' . "\n";
		echo '<meta charset="utf-8">' . "\n";
		echo '<meta name="viewport" content="width=device-width, initial-scale=1">' . "\n";
		echo '<title>' . esc_html( $label ) . ' — ' . $site_name . '</title>' . "\n";
		echo '<style>'
			. 'html,body{margin:0;padding:0;height:100%;background:#0f1115;color:#e6e6e6;'
			. 'font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;}'
			. '.wrap{min-height:100%;display:flex;align-items:center;justify-content:center;padding:24px;}'
			. '.card{max-width:520px;width:100%;background:#171a21;border:1px solid #262b36;'
			. 'border-radius:16px;padding:32px;box-shadow:0 8px 32px rgba(0,0,0,.4);text-align:center;}'
			. '.emoji{font-size:48px;line-height:1;margin-bottom:12px;}'
			. 'h1{font-size:22px;font-weight:600;margin:0 0 8px;color:#fff;}'
			. '.badge{display:inline-flex;align-items:center;gap:4px;padding:4px 12px;border-radius:999px;'
			. 'font-size:11px;font-weight:700;letter-spacing:.8px;text-transform:uppercase;'
			. 'color:#fff;margin-bottom:16px;background:' . esc_attr( $badge_bg ) . ';}'
			. 'p{font-size:14px;line-height:1.6;color:#a1a8b7;margin:0 0 8px;}'
			. '.perks{margin:16px 0;padding:12px 16px;background:#0d1117;border-radius:10px;'
			. 'text-align:left;font-size:13px;color:#8a8f9b;line-height:1.8;}'
			. '.actions{margin-top:24px;display:flex;gap:12px;justify-content:center;flex-wrap:wrap;}'
			. '.btn{display:inline-flex;align-items:center;justify-content:center;padding:10px 18px;'
			. 'border-radius:10px;font-size:14px;font-weight:500;text-decoration:none;transition:.15s;}'
			. '.btn-primary{background:' . esc_attr( $badge_bg ) . ';color:#fff;}'
			. '.btn-ghost{background:transparent;color:#a1a8b7;border:1px solid #2a3040;}'
			. '.btn-ghost:hover{color:#fff;border-color:#3a4257;}'
			. '</style>' . "\n";
		echo '</head><body>' . "\n";
		echo '<div class="wrap"><div class="card">' . "\n";
		echo '<div class="emoji">' . esc_html( $emoji ) . '</div>' . "\n";
		echo '<div class="badge">⭐ ' . esc_html( $plan_badge ) . '</div>' . "\n";
		echo '<h1>' . esc_html( $label ) . '</h1>' . "\n";
		$notice = $pro_package !== ''
			? sprintf( 'Tính năng này thuộc gói Pro của BizCity. Cài đặt và kích hoạt plugin %s để tiếp tục.', $pro_package )
			: sprintf( 'Tính năng này yêu cầu gói %s. Nâng cấp để sử dụng ngay.', $plan_badge );
		echo '<p>' . esc_html( $notice ) . '</p>' . "\n";
		if ( '' !== $desc ) {
			echo '<p style="margin-top:8px;color:#7b8294;">' . esc_html( $desc ) . '</p>' . "\n";
		}
		echo '<div class="actions">' . "\n";
		echo '<a class="btn btn-primary" href="' . esc_url( $account_url ) . '" target="_blank" rel="noopener">'
			/* translators: %s: plan name e.g. PRO */
			. sprintf( esc_html__( 'Nâng cấp lên %s', 'bizcity-twin-ai' ), esc_html( $plan_badge ) )
			. '</a>' . "\n";
		echo '<a class="btn btn-ghost" href="' . $shell_url . '">'
			. esc_html__( '← Quay lại Twin', 'bizcity-twin-ai' ) . '</a>' . "\n";
		echo '</div></div></div>' . "\n";
		echo '</body></html>' . "\n";
	}
}
