<?php
/**
 * Zalo Brain — /crm/ "Đội Zalo" served by core when the Zalo Brain CRM plugin is not active.
 *
 * [2026-10-09 Johnny Chu - Chu Hoàng Anh] PHASE-0.96 S96-7.4 (doc 70 §1, mockup PHASE-0.96-crm-core-mockup view A).
 * With the plugin active, the plugin's own template_redirect (priority 10) renders its SPA and exits first, so this
 * renderer (priority 20) never runs. Without it, /crm/ mounts the Bot Studio SPA (core/channel-gateway bundle) on the
 * hash route #/crm with BOOT.surface = 'crm': Hộp thư · Liên hệ · Đội, read through the spine REST (bizcity-crm/v1)
 * with the same inbox scope as everywhere else (R-USER-INBOX-SPINE). No React is added to core (D96-7).
 *
 * @package BizCity_Twin_AI
 * @subpackage Core\CRM
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_CRM_Core_Page', false ) ) {
	return;
}

final class BizCity_CRM_Core_Page {

	const QUERY_VAR = 'bizcity_agent_page';

	public static function register(): void {
		add_action( 'init', array( __CLASS__, 'add_rewrite_rule' ), 11 );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_render' ), 20 );
		if ( class_exists( 'BizCity_Rewrite_Flush_Registry' ) ) {
			BizCity_Rewrite_Flush_Registry::register( 'crm-core-public', '1.0.0' );
		}
	}

	/** Same rule the plugin registers; WordPress keeps one. Flushing is owned by BizCity_Rewrite_Flush_Registry. */
	public static function add_rewrite_rule(): void {
		add_rewrite_rule( '^crm/?$', 'index.php?' . self::QUERY_VAR . '=crm', 'top' );
		add_rewrite_tag( '%' . self::QUERY_VAR . '%', '([^&]+)' );
	}

	public static function maybe_render(): void {
		if ( 'crm' !== get_query_var( self::QUERY_VAR ) ) {
			return;
		}
		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( wp_login_url( home_url( '/crm/' ) ) );
			exit;
		}
		$allowed = current_user_can( 'manage_options' );
		if ( ! $allowed && class_exists( 'BizCity_CRM_Authority' ) && class_exists( 'BizCity_CRM_Actor' ) ) {
			$check   = BizCity_CRM_Authority::can( 'crm.inbox.open', array(), BizCity_CRM_Actor::current( 'be' ) );
			$allowed = is_array( $check ) ? ! empty( $check['ok'] ) : (bool) $check;
		}
		if ( ! $allowed ) {
			wp_die(
				esc_html__( 'Tài khoản này chưa được giao số Zalo hay hộp thư nào. Cách sửa: nhờ quản trị viên thêm bạn vào Đội Zalo.', 'bizcity-twin-ai' ),
				esc_html__( 'Đội Zalo', 'bizcity-twin-ai' ),
				array( 'response' => 403 )
			);
		}

		nocache_headers();
		add_filter( 'show_admin_bar', '__return_false', 100 );
		header( 'Content-Type: text/html; charset=utf-8' );

		$cg_dir  = dirname( __DIR__, 2 ) . '/channel-gateway/assets/dist/';
		$root    = defined( 'BIZCITY_TWIN_AI_URL' )
			? trailingslashit( BIZCITY_TWIN_AI_URL ) . 'core/channel-gateway/assets/dist/'
			: plugins_url( '../../channel-gateway/assets/dist/', __FILE__ );
		$js_ver  = is_readable( $cg_dir . 'channel-gateway-app.js' ) ? (string) filemtime( $cg_dir . 'channel-gateway-app.js' ) : '1';
		$css_ver = is_readable( $cg_dir . 'channel-gateway-app.css' ) ? (string) filemtime( $cg_dir . 'channel-gateway-app.css' ) : $js_ver;
		$manage  = current_user_can( 'manage_options' );
		$boot    = array(
			'restUrl'       => '/wp-json/bizcity-channel/v1/',
			'restNonce'     => wp_create_nonce( 'wp_rest' ),
			'adminUrl'      => admin_url( 'admin.php?page=bizchat-gateway-spa' ),
			'siteUrl'       => home_url( '/' ),
			'version'       => defined( 'BIZCITY_TWIN_AI_VERSION' ) ? BIZCITY_TWIN_AI_VERSION : '1.0',
			'publicGateway' => true,
			'surface'       => 'crm',
			'caps'          => array( 'manage' => $manage, 'send' => true ),
			'i18n'          => array( 'plugin_title' => __( 'Đội Zalo', 'bizcity-twin-ai' ) ),
			'platforms'     => array(),
			'modules'       => array( 'crm' => class_exists( 'BizCity_CRM_Spine' ) && BizCity_CRM_Spine::has( 'admin_ui' ) ),
			'crmSpine'      => class_exists( 'BizCity_CRM_Spine' ) ? BizCity_CRM_Spine::describe() : null,
		);
		?>
<!doctype html>
<html <?php language_attributes(); ?>><head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?php esc_html_e( 'Đội Zalo — Zalo Brain', 'bizcity-twin-ai' ); ?></title>
<link rel="stylesheet" href="<?php echo esc_url( $root . 'channel-gateway-app.css?ver=' . rawurlencode( $css_ver ) ); ?>">
</head><body>
<div id="bizcity-channel-gateway-root" translate="no" style="min-height:100vh;"></div>
<script>window.BIZCITY_CG_BOOT=<?php echo wp_json_encode( $boot ); ?>;if(!location.hash||location.hash==='#/'||location.hash.indexOf('#/crm')!==0){location.replace('#/crm');}</script>
<script src="<?php echo esc_url( $root . 'channel-gateway-app.js?ver=' . rawurlencode( $js_ver ) ); ?>"></script>
</body></html>
		<?php
		exit;
	}
}
