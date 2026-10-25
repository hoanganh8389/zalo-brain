<?php
/**
 * Helper Functions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Get Zalo Bot API instance
 */
function bizcity_get_zalo_bot_api( $bot_id ) {
	$db = BizCity_Zalo_Bot_Database::instance();
	$bot = $db->get_bot( $bot_id );
	
	if ( ! $bot ) {
		return false;
	}
	
	return new BizCity_Zalo_Bot_API( $bot->bot_token );
}

// [2026-10-25 Johnny Chu - Chu Hoàng Anh] PHASE-0.126 R126-LG — bizcity_send_zalo_message(), bizcity_get_zalo_user_profile(), bizcity_get_zalo_webhook_url(), bizcity_format_zalo_timestamp(),
// bizcity_encrypt_webhook_data() and bizcity_get_zalohook_url() removed: no caller in any plugin, mu-plugin or theme. Archived at
// core/_archived/legacy-messenger-r126lg-20261025/.

/**
 * Generate secret token for Zalo Bot Creator
 */
function bizcity_generate_zalo_secret_token( $blog_id ) {
	$salt = 'bizcity_zalo_secret_2026';
	return substr( hash( 'sha256', $blog_id . '_' . $salt ), 0, 16 );
}
