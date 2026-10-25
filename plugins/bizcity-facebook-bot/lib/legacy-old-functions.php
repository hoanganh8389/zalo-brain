<?php
/**
 * Legacy Old Functions - Migrated from fb/ and messenger/ folders
 * 
 * This file contains functions from the old fb/ and messenger/ directories
 * that are still needed by the bizcity-facebook-bot plugin.
 * 
 * All functions are wrapped with if(!function_exists()) to prevent conflicts.
 * 
 * Source files (can be archived after migration):
 * - mu-plugins/fb/facebook_scheduler.php (commented out - not migrated)
 * - mu-plugins/fb/facebook_sendpost.php
 * - mu-plugins/messenger/functions.php
 * - mu-plugins/messenger/inbox.php
 * - mu-plugins/messenger/comment.php
 * - mu-plugins/messenger/comment_config.php
 * 
 * @package BizCity_Facebook_Bot
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ============================================================================
 * FROM: messenger/functions.php
 * ============================================================================ */

/**
 * Get FB Customer profile
 */
if ( ! function_exists( 'messenger_get_fb_customer' ) ) {
	function messenger_get_fb_customer( $page_id, $client_id ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'bizcity_facebook_customers';
		if ( empty( $client_id ) ) return false;

		// Check cache first
		$cache_key = 'inbox_customer_' . $client_id;
		$cached = wp_cache_get( $cache_key, 'fb_customer' );
		if ( $cached !== false && is_array( $cached ) ) return $cached;

		$pages = get_option( 'fb_pages_connected' );
		$target_id = $page_id;
		$access_token = null;

		if ( is_array( $pages ) ) {
			foreach ( $pages as $page ) {
				if ( $page['id'] === $target_id ) {
					$access_token = $page['access_token'];
					break;
				}
			}
		}

		// PHASE 0.34 — prefer per-page token from `wp_*_bizcity_facebook_bots` (canonical, post-migration).
		if ( empty( $access_token ) && class_exists( 'BizCity_Facebook_Bot_Database' ) ) {
			$bot = BizCity_Facebook_Bot_Database::instance()->get_bot_by_page_id( (string) $page_id );
			if ( $bot ) {
				$access_token = is_object( $bot ) ? ( $bot->page_access_token ?? '' ) : ( $bot['page_access_token'] ?? '' );
			}
		}

		// Get profile from Facebook
		$page_access_token = get_option( 'messenger_page_token' );
		$token = $access_token ?: $page_access_token;
		if ( empty( $token ) ) {
			error_log( "[fb] messenger_get_fb_customer: no access token for page {$page_id}" );
			return false;
		}
		$profile_url = "https://graph.facebook.com/v18.0/{$client_id}?fields=first_name,last_name,profile_pic&access_token=" . rawurlencode( $token );
		$response = wp_remote_get( $profile_url, array( 'timeout' => 8 ) );
		$profile = array();
		if ( ! is_wp_error( $response ) ) {
			$body = wp_remote_retrieve_body( $response );
			$profile = json_decode( $body, true );
			if ( ! is_array( $profile ) ) { $profile = array(); }
			if ( isset( $profile['error'] ) ) {
				error_log( '[fb] messenger_get_fb_customer Graph error: ' . wp_json_encode( $profile['error'] ) );
				$profile = array();
			}
		}

		// Get info from Facebook profile
		$name = trim( ( $profile['first_name'] ?? '' ) . ' ' . ( $profile['last_name'] ?? '' ) );
		$profile['name'] = $name ?? '';
		$email = $profile['email'] ?? '';
		$fb_link = ! empty( $client_id ) ? 'https://facebook.com/' . $client_id : '';
		$profile_pic = $profile['profile_pic'] ?? '';

		// Check if exists
		$wpdb->suppress_errors( true );
		$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table_name WHERE client_id = %s", $client_id ) );
		$wpdb->suppress_errors( false );

		if ( ! $exists ) {
			// Insert
			$wpdb->insert( $table_name, array(
				'client_id'   => $client_id,
				'page_id'     => $page_id,
				'name'        => $name,
				'email'       => $email,
				'fb_link'     => $fb_link,
				'profile_pic' => $profile_pic,
			) );
		}

		// Set cache
		wp_cache_set( $cache_key, $profile, 'fb_customer', 12 * HOUR_IN_SECONDS );

		return $profile;
	}
}

/**
 * Send notice to Zalo admin
 */
if ( ! function_exists( 'send_notice_to_zalo_admin' ) ) {
	function send_notice_to_zalo_admin( $msg, $client_id, $client_name, $blog_domain = '', $platform = 'FB Hook' ) {
		$msg = "🧠 Có tin mới từ khách \n" // R126-LG — no PHP auto-reply precedes this notice any more
			. "🗨️ <b>Nội dung:</b> $msg \n\n"
			. "🌐 <b>Website:</b> <code> $blog_domain</code>\n"
			. "🔑 <b>Nền tảng:</b> <code>$platform</code>\n"
			. "👤<b>Tên khách:</b> <code>$client_name</code>\n"
			. "👤 <b>Mã định danh của khách:</b> <code>$client_id</code>\n"
			. "Xem và trả lời khách trong CRM Inbox: <code>https://$blog_domain/crm/</code>"; // [2026-10-24] PHASE-0.126 R126-TWF — the old messenger-inbox-page is gone

		$reply_markup = '';
		if ( function_exists( 'twf_list_client_ids_by_blog_id' ) ) {
			$chat_ids = twf_list_client_ids_by_blog_id( get_current_blog_id(), true );
			foreach ( $chat_ids as $chat_id ) {
				if ( function_exists( 'twf_telegram_send_message' ) ) {
					twf_telegram_send_message( $chat_id, $msg, 'HTML', $reply_markup );
				}
			}
		}
	}
}

// [2026-10-25 Johnny Chu - Chu Hoàng Anh] PHASE-0.126 R126-LG — get_profile_by_client_id(), fb_messenger_take_thread_control(), fb_messenger_reply() and get_or_update_page_access_token()
// removed: the PHP Messenger reply (their only user) is gone since R126-FB — the cell answers Messenger (cell_direct). Archived at
// core/_archived/legacy-messenger-r126lg-20261025/.


/* ============================================================================
 * FROM: messenger/inbox.php
 * ============================================================================ */

// Register AJAX actions


/* ============================================================================
 * FROM: messenger/comment.php
 * ============================================================================ */

// [2026-10-11 12:23 AM Johnny Chu - Chu Hoàng Anh] CORE-REDUCTION WP-20 Z-8c — bizgpt_comment_log_page() and bizgpt_comment_detail_page() moved to
// core/_archived/z8c-20261011/plugins/bizcity-facebook-bot/lib/legacy-old-functions.php: no admin menu registers them (the add_submenu_page lines live only in
// archived mu-plugins/backup copies).


/* ============================================================================
 * FROM: messenger/comment_config.php
 * ============================================================================ */

// [2026-10-24 Johnny Chu - Chu Hoàng Anh] PHASE-0.126 R126-GR — bizcity_admin_style_fallback_css() removed (no caller; old PHP bot comment-flow / admin CSS fallback).

// [2026-10-24 Johnny Chu - Chu Hoàng Anh] PHASE-0.126 R126-GR — bizgpt_parse_comment_flow_dynamic() removed (no caller; old PHP bot comment-flow / admin CSS fallback).


/* ============================================================================
 * FROM: fb/facebook_sendpost.php
 * ============================================================================ */

// [2026-09-27 Claude Opus 5.5] CORE-REDUCTION WP-12 R8 — bizgpt_suggest_facebook_posts() removed: no caller left, it read the
// retired CPT biz_facebook and called OpenAI directly with twf_openai_api_key (R-ONE-AXIS R-AX-3, R-GW).

// [2026-10-11 12:23 AM Johnny Chu - Chu Hoàng Anh] CORE-REDUCTION WP-20 Z-8c — fb_send_post() moved to core/_archived/z8c-20261011/plugins/bizcity-facebook-bot/lib/legacy-old-functions.php: no live caller (only
// archived mu-plugins/backup copies called it).

// [2026-10-24 Johnny Chu - Chu Hoàng Anh] PHASE-0.126 R126-TWF (owner: "di sản luồng bot twf cũ cần gỡ hết" + gỡ hộp thư Messenger cũ) — removed: messenger_inbox_page() (the old wp-admin
// "Messenger inbox" of the pre-CRM bot; the CRM Inbox replaced it) with its AJAX messenger_send_admin_msg / messenger_pull_inbox_fn, and the old
// bizgpt_inbox / bizgpt_inbox_comment writers bizgpt_log_to_inbox_when_send() / bizgpt_log_comment_ai(). Archived at core/_archived/twf-legacy-20261024/.
