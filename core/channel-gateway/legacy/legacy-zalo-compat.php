<?php
/**
 * Channel Gateway — Legacy Zalo / Inbox Compat Functions
 *
 * Ported from mu-plugins/backup/zalo/functions.php.
 * All functions wrapped in function_exists() guards to prevent fatal conflicts
 * when the legacy mu-plugin is still active on older installs.
 *
 * [2026-06-11 Johnny Chu] HOTFIX — moved into channel-gateway/legacy/ so these
 * helpers are available to channel-gateway classes without depending on mu-plugin.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\ChannelGateway\Legacy
 * @since      2026-06-11
 */

defined( 'ABSPATH' ) || exit;

// [2026-10-11 12:23 AM Johnny Chu - Chu Hoàng Anh] CORE-REDUCTION WP-20 Z-8c — 13 global shims moved to
// core/_archived/z8c-20261011/core/channel-gateway/legacy/legacy-zalo-compat.php (no live caller in bizcity-twin-ai, any
// sibling plugin, mu-plugin or theme; comment-stripped census in core/knowledge/docs/CORE-REDUCTION-WP-20/evidence/W20-Z8c/):
// bizgpt_log_inbox_admin_msg, bizgpt_log_inbox_msg, bizgpt_check_zalo_admin_permission, bizgpt_prompt_select_site_to_admin, bizgpt_find_blog_id_by_domain,
// bizgpt_get_admin_sites_by_client, twf_update_client_login_time, bizgpt_generate_zalo_admin_login_url, twf_list_sites_by_client_id,
// twf_check_client_use_zalo, fbm_search_product, sanitize_text_with_line_breaks, has_image_extension.
// Only twf_list_client_ids_by_blog_id() stays: legacy-messenger-compat.php, bizcity-facebook-bot, bizcity-video-kling and
// bizcity-automation-legacy still call it. global_inbox_admin keeps its live writer in BizCity_Gateway_Sender.

// ── twf_list_client_ids_by_blog_id ────────────────────────────────────────────

if ( ! function_exists( 'twf_list_client_ids_by_blog_id' ) ) {
	/**
	 * Lấy danh sách client_id theo blog_id (dùng để gửi thông báo).
	 *
	 * @param int  $blog_id
	 * @param bool $force_refresh
	 * @return array
	 */
	function twf_list_client_ids_by_blog_id( $blog_id, $force_refresh = false ) {
		if ( empty( $blog_id ) ) {
			$blog_id = get_current_blog_id();
		}

		$cache_key   = 'zalo_list_client_ids_blog_' . (int) $blog_id;
		$cache_group = 'zalo_list_client_ids_blog';

		if ( ! $force_refresh ) {
			$cached = wp_cache_get( $cache_key, $cache_group );
			if ( false !== $cached && is_array( $cached ) ) {
				return $cached;
			}
			$transient = get_transient( $cache_key );
			if ( false !== $transient && is_array( $transient ) ) {
				wp_cache_set( $cache_key, $transient, $cache_group, 10 * MINUTE_IN_SECONDS );
				return $transient;
			}
		}

		global $globaldb;
		$results = isset( $globaldb ) ? $globaldb->get_col( $globaldb->prepare(
			'SELECT DISTINCT client_id FROM global_user_admin WHERE blog_id = %d ORDER BY updated_at DESC',
			(int) $blog_id
		) ) : array();

		$client_ids = is_array( $results ) ? $results : array();
		set_transient( $cache_key, $client_ids, 12 * HOUR_IN_SECONDS );
		wp_cache_set( $cache_key, $client_ids, $cache_group, 10 * MINUTE_IN_SECONDS );

		return $client_ids;
	}
}
