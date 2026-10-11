<?php
/**
 * Retired shortcodes — one central registrar for tags whose feature is gone. Old post content may still contain them;
 * registering them with an empty renderer keeps the page from printing the raw "[tag]" text.
 *
 * [2026-10-11 12:23 AM Johnny Chu - Chu Hoàng Anh] CORE-REDUCTION WP-20 Z-8c — created for [zalo_login_form] (legacy AES
 * ?zid= login form, archived from legacy-zalo-shortcodes.php) and [bizcity_character_chat] (public Guru widget, archived
 * from core/knowledge/includes/functions.php). Add a tag here instead of keeping a dead implementation alive.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\ChannelGateway\Legacy
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_Retired_Shortcodes {
	const TAGS = array( 'zalo_login_form', 'bizcity_character_chat' );

	public static function register() {
		foreach ( self::TAGS as $tag ) {
			if ( ! shortcode_exists( $tag ) ) {
				add_shortcode( $tag, '__return_empty_string' );
			}
		}
	}
}

BizCity_Retired_Shortcodes::register(); // A plugin that registers the same tag later simply overrides this.
