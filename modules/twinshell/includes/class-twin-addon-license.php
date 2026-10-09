<?php
/**
 * BizCity_Twin_Addon_License — may this site use a licensed add-on (Automation = plugin bizcity-automation)?
 * (PHASE-0.91 AX-LIC, research doc core/channel-gateway/docs/PHASE-0.91-SCHEDULER-ACTION-MCP/120-AUTOMATION-PREMIUM-LICENSE.md)
 *
 * Two gates, in this order — the first that fails decides what the owner sees:
 *   1. `premium`  — the site's 1API master account (Hub tier bucket, synced into option bizcity_hub_master_tier by
 *                   BizCity_LLM_Client) must be Premium or Enterprise. Otherwise ⇒ "Tài khoản cần là Premium" + button
 *                   to https://bizcity.vn/product/premium/.
 *   2. `plugin`   — the add-on's files must be present (BizCity_Addon_Locator / its constant). Otherwise ⇒ on the bizcity.vn
 *                   any site: "Kích hoạt plugin" (per-site activation, activate_plugins); folder missing: download link on GitHub.
 * When the Hub also lists services per plan (`bizcity_hub_plugins_enabled`) and that list names the add-on's service slug,
 * a plan WITHOUT it fails gate 1 as well (Hub-side switch, see doc 120 §4).
 *
 * Biz Central Brain — Johnny Chu (Chu Hoàng Anh). Bizcity Central Brain, Giấy chứng nhận đăng ký quyền tác giả
 * số 8877/2026/QTG (Cục Bản quyền tác giả, 14/09/2026).
 *
 * // [2026-10-05 10:10 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.91-AX-LIC — new file.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Modules\TwinShell
 * @author     Johnny Chu (Chu Hoàng Anh)
 * @copyright  2026 Johnny Chu (Chu Hoàng Anh) — Bizcity Central Brain
 * @since      2026-10-05
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Twin_Addon_License', false ) ) {
	return;
}

final class BizCity_Twin_Addon_License {

	const PREMIUM_URL     = 'https://bizcity.vn/product/premium/';
	const GITHUB_URL      = 'https://github.com/hoanganh8389/bizcity-automation';
	const PLUGIN_FILE     = 'bizcity-automation/bizcity-automation.php';
	const SERVICE_SLUG    = 'bizcity-automation';  // Hub "Nhóm 1 — Plugins / Services" slug (doc 120 §4)
	const PREMIUM_BUCKETS = array( 'premium', 'enterprise' );

	/** @var array<string,callable> test seams: tier():string, services():array, network_domain():string */
	public static $readers = array();

	/**
	 * Tier bucket of the site's 1API master account: free | pro | premium | enterprise.
	 */
	public static function hub_tier(): string {
		if ( isset( self::$readers['tier'] ) ) {
			return (string) call_user_func( self::$readers['tier'] );
		}
		$tier = function_exists( 'get_option' ) ? sanitize_key( (string) get_option( 'bizcity_hub_master_tier', '' ) ) : '';
		if ( '' === $tier ) {
			$level = function_exists( 'get_option' ) ? (string) get_option( 'bizcity_hub_master_level', 'free' ) : 'free';
			$tier  = class_exists( 'BizCity_LLM_Client' ) && method_exists( 'BizCity_LLM_Client', 'tier_bucket_from_master_level' )
				? BizCity_LLM_Client::tier_bucket_from_master_level( $level )
				: ( in_array( $level, array( 'master_premium', 'premium', 'master_enterprise', 'enterprise' ), true ) ? 'premium' : 'free' );
		}
		return '' !== $tier ? $tier : 'free';
	}

	/** Premium or higher (gate 1). Filter `bizcity_addon_license_premium` (bool, $tier) for ops overrides. */
	public static function is_premium(): bool {
		// [2026-10-05 10:10 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.91-AX-LIC — only the 1API master tier counts, never the local membership plan.
		$tier = self::hub_tier();
		$ok   = in_array( $tier, self::PREMIUM_BUCKETS, true );
		if ( $ok ) {
			$services = self::hub_services();
			// Hub per-plan switch (doc 120 §4): once the Hub catalog names the service, a plan without it is not licensed.
			if ( $services && ! empty( $services['__catalog_has_automation'] ) && ! in_array( self::SERVICE_SLUG, $services, true ) ) {
				$ok = false;
			}
		}
		return function_exists( 'apply_filters' ) ? (bool) apply_filters( 'bizcity_addon_license_premium', $ok, $tier ) : $ok;
	}

	/**
	 * Why the entry is locked for this site: 'premium' | 'plugin' | '' (usable).
	 *
	 * @param array $p registry entry (license, requires)
	 */
	public static function lock_kind( array $p ): string {
		// [2026-10-05 10:10 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.91-AX-LIC — premium first, then the plugin itself.
		if ( 'premium' === (string) ( $p['license'] ?? '' ) && ! self::is_premium() ) {
			return 'premium';
		}
		if ( ! empty( $p['requires'] ) && class_exists( 'BizCity_Twin_Shell_Registry' ) && ! BizCity_Twin_Shell_Registry::requirement_met( $p['requires'] ) ) {
			return 'plugin';
		}
		return '';
	}

	/** On the bizcity.vn WordPress network (where the add-on is installed centrally and only needs activating). */
	public static function on_bizcity_network(): bool {
		$domain = '';
		if ( isset( self::$readers['network_domain'] ) ) {
			$domain = (string) call_user_func( self::$readers['network_domain'] );
		} elseif ( function_exists( 'is_multisite' ) && is_multisite() && function_exists( 'get_network' ) ) {
			$net    = get_network();
			$domain = $net ? (string) $net->domain : '';
		}
		$ok = '' !== $domain && (bool) preg_match( '/(^|\.)bizcity\.vn$/i', $domain );
		return function_exists( 'apply_filters' ) ? (bool) apply_filters( 'bizcity_addon_on_bizcity_network', $ok, $domain ) : $ok;
	}

	/**
	 * [2026-10-09 11:05 PM Johnny Chu - Chu Hoàng Anh] Which add-on a registry entry locks on: display name, plugin file, GitHub URL.
	 * An entry without `addon_*` keys is Automation (the first licensed add-on), so existing callers keep their exact words.
	 *
	 * @param array $p registry entry
	 * @return array{name:string,file:string,github:string}
	 */
	public static function addon_of( array $p = array() ): array {
		$file = (string) ( $p['addon_file'] ?? '' );
		return array(
			'name'   => '' !== (string) ( $p['addon_name'] ?? '' ) ? (string) $p['addon_name'] : 'BizCity Automation',
			'file'   => '' !== $file ? $file : self::PLUGIN_FILE,
			'github' => '' !== (string) ( $p['addon_github'] ?? '' ) ? (string) $p['addon_github'] : self::GITHUB_URL,
		);
	}

	/** The add-on's plugin folder exists on this install (activation possible without upload). */
	public static function plugin_on_disk( string $file = self::PLUGIN_FILE ): bool {
		if ( isset( self::$readers['on_disk'] ) ) {
			return (bool) call_user_func( self::$readers['on_disk'], $file );
		}
		$base = defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR : '';
		return '' !== $base && is_file( rtrim( $base, '/\\' ) . '/' . $file );
	}

	/**
	 * Call to action of a `plugin` lock: { kind: activate|ask_admin|download, label, url }.
	 * Activate = per-site activation link with nonce (the plugin header says `Network: false`).
	 *
	 * @param array $p registry entry (optional `addon_name` / `addon_file` / `addon_github`; none ⇒ Automation)
	 */
	public static function plugin_action( array $p = array() ): array {
		// [2026-10-05 10:50 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.91-AX-LIC — owner rule: plugin folder found ⇒ an ACTIVATE button (any
		// site, not only bizcity.vn); not found ⇒ download from GitHub.
		$a = self::addon_of( $p );
		if ( self::plugin_on_disk( $a['file'] ) ) {
			// [2026-10-05 11:40 PM Johnny Chu - Chu Hoàng Anh] Plugin header is `Network: false` — a normal client site activates it on its
			// own plugins.php; never send the viewer to the network admin.
			$can_one = function_exists( 'current_user_can' ) && current_user_can( 'activate_plugins' );
			if ( $can_one && function_exists( 'admin_url' ) ) {
				$url = admin_url( 'plugins.php?action=activate&plugin=' . rawurlencode( $a['file'] ) );
			} else {
				return array( 'kind' => 'ask_admin', 'label' => 'Nhờ quản trị viên kích hoạt plugin', 'url' => '' );
			}
			return array(
				'kind'  => 'activate',
				'label' => 'Kích hoạt plugin ' . $a['name'],
				'url'   => function_exists( 'wp_nonce_url' ) ? wp_nonce_url( $url, 'activate-plugin_' . $a['file'] ) : $url,
			);
		}
		return array( 'kind' => 'download', 'label' => 'Tải ' . $a['name'] . ' (GitHub)', 'url' => $a['github'] );
	}

	/** Service slugs of the Hub plan (option bizcity_hub_plugins_enabled), [] when not synced. */
	private static function hub_services(): array {
		if ( isset( self::$readers['services'] ) ) {
			return (array) call_user_func( self::$readers['services'] );
		}
		$raw = function_exists( 'get_option' ) ? get_option( 'bizcity_hub_plugins_enabled', '' ) : '';
		$arr = is_string( $raw ) ? json_decode( $raw, true ) : $raw;
		if ( ! is_array( $arr ) ) {
			return array();
		}
		// The Hub ships the catalog marker once doc 120 §4 lands; until then the list cannot say "automation off".
		$catalog = function_exists( 'get_option' ) ? get_option( 'bizcity_hub_services_catalog', '' ) : '';
		$cat     = is_string( $catalog ) ? json_decode( $catalog, true ) : $catalog;
		if ( is_array( $cat ) && in_array( self::SERVICE_SLUG, $cat, true ) ) {
			$arr['__catalog_has_automation'] = true;
		}
		return $arr;
	}

	/**
	 * CL-LIC-4 — may the site expose / run Automation outside the shell (MCP `automation.*`, the Automation admin page, `/flow/`)?
	 * Gate 1 only: the callers already know whether the plugin is there. Filter `bizcity_addon_license_automation` (bool) for ops.
	 * Running workflows (cron, channels) are NOT stopped by this — Q-LIC-2, owner's call (doc 120 §7).
	 *
	 * // [2026-10-05 11:15 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.91-AX-LIC CL-LIC-4 — new.
	 */
	public static function automation_allowed(): bool {
		$ok = self::is_premium();
		return function_exists( 'apply_filters' ) ? (bool) apply_filters( 'bizcity_addon_license_automation', $ok ) : $ok;
	}

	/**
	 * The PREMIUM card for a surface that is not the shell (Automation admin page, `/flow/`): same words and button as the shell's
	 * in-iframe notice (class-twin-shell-page.php render_addon_notice), as a self-contained fragment.
	 *
	 * // [2026-10-05 11:15 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.91-AX-LIC CL-LIC-4 — new.
	 *
	 * @param string $label feature label, e.g. "Automation"
	 */
	public static function premium_notice_html( string $label = 'Automation' ): string {
		$tiers = array( 'free' => 'Free', 'pro' => 'Pro', 'premium' => 'Premium', 'enterprise' => 'Enterprise' );
		$tier  = self::hub_tier();
		$esc   = static function ( string $s ): string { return function_exists( 'esc_html' ) ? esc_html( $s ) : htmlspecialchars( $s, ENT_QUOTES, 'UTF-8' ); };
		$url   = function_exists( 'esc_url' ) ? esc_url( self::PREMIUM_URL ) : self::PREMIUM_URL;
		$lines = array(
			sprintf( '%s chỉ dùng được khi tài khoản 1API master của website ở gói Premium trở lên.', $label ),
			sprintf( 'Tài khoản hiện tại: gói %s.', isset( $tiers[ $tier ] ) ? $tiers[ $tier ] : ucfirst( $tier ) ),
		);
		$html  = '<div class="bizcity-addon-notice" data-lock="premium" style="max-width:520px;margin:48px auto;background:#171a21;border:1px solid #262b36;border-radius:16px;padding:32px;text-align:center;color:#e6e6e6;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;">';
		$html .= '<div style="font-size:48px;line-height:1;margin-bottom:12px;">🔒</div>';
		$html .= '<div style="display:inline-block;padding:4px 12px;border-radius:999px;font-size:11px;font-weight:700;letter-spacing:.8px;color:#fff;margin-bottom:16px;background:linear-gradient(135deg,#8b5cf6,#6d28d9);">PREMIUM</div>';
		$html .= '<h1 style="font-size:22px;font-weight:600;margin:0 0 8px;color:#fff;">' . $esc( $label ) . '</h1>';
		foreach ( $lines as $line ) {
			$html .= '<p style="font-size:14px;line-height:1.6;color:#a1a8b7;margin:0 0 8px;">' . $esc( $line ) . '</p>';
		}
		$html .= '<div style="margin-top:24px;"><a href="' . $url . '" target="_blank" rel="noopener" style="display:inline-flex;align-items:center;justify-content:center;padding:10px 18px;border-radius:10px;font-size:14px;font-weight:500;text-decoration:none;color:#fff;background:linear-gradient(135deg,#8b5cf6,#6d28d9);">Nâng cấp lên Premium</a></div>';
		$html .= '</div>';
		return $html;
	}
}
