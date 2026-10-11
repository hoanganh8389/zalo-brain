<?php
/**
 * BizCity_Skill_Admin_Page — wp-admin page of the skill editor SPA (PHASE-0.101 S101-L6, lean revival).
 *
 * Submenu "📘 Skill" under Knowledge (`admin.php?page=bizcity-skills`), enqueues the Vite build in assets/dist and
 * passes `window.skillAppConfig = {restBase, nonce, toolsCatalog}`. The tools catalog is the one MCP tool registry
 * (names the skill body may mention with `@tool`), grouped by prefix — no second catalog (R-AFW-1).
 *
 * Biz Central Brain — Johnny Chu (Chu Hoàng Anh).
 *
 * // [2026-10-11 Johnny Chu - Chu Hoàng Anh] PHASE-0.101 S101-L6 — menu hooked here again; catalog from core/mcp instead of the
 * //   retired intent tool index; Context Bank / journal config removed.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Skills
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Skill_Admin_Page', false ) ) {
	return;
}

final class BizCity_Skill_Admin_Page {

	const SLUG   = 'bizcity-skills';
	const PARENT = 'bizcity-knowledge';

	private static $hooked = false;

	public static function init(): void {
		if ( self::$hooked ) {
			return;
		}
		self::$hooked = true;
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ), 30 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	public static function add_menu(): void {
		$cap = class_exists( 'BizCity_Network_Admin_Capability' ) ? BizCity_Network_Admin_Capability::menu_cap() : 'edit_posts';
		add_submenu_page( self::PARENT, __( 'Skill', 'bizcity-twin-ai' ), '📘 ' . __( 'Skill', 'bizcity-twin-ai' ), $cap, self::SLUG, array( __CLASS__, 'render_page' ) );
	}

	public static function render_page(): void {
		echo '<div class="wrap" style="margin:0;padding:0;"><div id="skill-app" style="min-height:calc(100vh - 32px);"></div></div>';
	}

	public static function enqueue_assets( string $hook ): void {
		if ( false === strpos( $hook, self::SLUG ) ) {
			return;
		}
		$dist = BIZCITY_SKILLS_DIR . 'assets/dist/';
		$url  = plugins_url( 'assets/dist/', BIZCITY_SKILLS_DIR . 'bootstrap.php' );
		if ( is_file( $dist . 'skill-app.css' ) ) {
			wp_enqueue_style( 'bizcity-skill-app', $url . 'skill-app.css', array(), (string) filemtime( $dist . 'skill-app.css' ) );
		}
		if ( ! is_file( $dist . 'skill-app.js' ) ) {
			return;
		}
		wp_enqueue_script( 'bizcity-skill-app', $url . 'skill-app.js', array(), (string) filemtime( $dist . 'skill-app.js' ), true );
		$rest_path = wp_parse_url( rest_url( BizCity_Skill_REST_API::API_NAMESPACE ), PHP_URL_PATH );
		$config    = array(
			'restBase'     => $rest_path ? $rest_path : '/wp-json/' . BizCity_Skill_REST_API::API_NAMESPACE,
			'nonce'        => wp_create_nonce( 'wp_rest' ),
			'toolsCatalog' => self::tools_catalog(),
		);
		wp_add_inline_script( 'bizcity-skill-app', 'window.skillAppConfig = ' . wp_json_encode( $config ) . ';', 'before' );
		wp_dequeue_script( 'wc-settings' );
		wp_dequeue_script( 'wc-entities' );
		wp_dequeue_style( 'woocommerce_admin_styles' );
	}

	/** @return array{totalTools:int,groups:array} the MCP registry grouped by tool prefix, for the `@tool` picker. */
	public static function tools_catalog(): array {
		if ( ! class_exists( 'BizCity_MCP_Tool_Registry' ) ) {
			return array( 'totalTools' => 0, 'groups' => array() );
		}
		$palette = array( array( '#059669', '#34D399' ), array( '#4F46E5', '#818CF8' ), array( '#2563EB', '#60A5FA' ), array( '#7C3AED', '#A78BFA' ), array( '#DB2777', '#F472B6' ), array( '#D97706', '#FBBF24' ), array( '#DC2626', '#F87171' ), array( '#0891B2', '#22D3EE' ) );
		$grouped = array();
		$total   = 0;
		foreach ( (array) BizCity_MCP_Tool_Registry::catalog_for_settings() as $t ) {
			if ( empty( $t['name'] ) || ( isset( $t['enabled'] ) && ! $t['enabled'] ) ) {
				continue;
			}
			$group = (string) ( $t['group'] ?? strstr( (string) $t['name'], '.', true ) );
			$grouped[ $group ][] = array( 'toolName' => (string) $t['name'], 'label' => (string) ( $t['title'] ?? $t['name'] ), 'desc' => (string) ( $t['description'] ?? '' ), 'slots' => array() );
			$total++;
		}
		ksort( $grouped );
		$groups = array();
		foreach ( $grouped as $id => $tools ) {
			$c        = $palette[ abs( crc32( (string) $id ) ) % count( $palette ) ];
			$groups[] = array( 'plugin' => (string) $id, 'name' => ucfirst( str_replace( array( '-', '_' ), ' ', (string) $id ) ), 'gradient' => 'linear-gradient(135deg, ' . $c[0] . ', ' . $c[1] . ')', 'toolCount' => count( $tools ), 'tools' => $tools );
		}
		return array( 'totalTools' => $total, 'groups' => $groups );
	}
}
