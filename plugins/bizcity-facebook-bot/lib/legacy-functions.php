<?php
/**
 * Facebook Messenger — the few helpers the webhook handler still needs (claim an inbound event once, the inbound
 * envelope).
 *
 * [2026-10-24 Johnny Chu - Chu Hoàng Anh] PHASE-0.126 R126-FB (owner: "ép cell_direct, bỏ PHP") — every PHP reply path that lived here is gone:
 * bizgpt_chatbot_run_guest_flows / bizgpt_chatbot_fallback_ai_response (Messenger auto-reply + LLM fallback), bizgpt_router_comment_flow /
 * chatbot_chatgpt_simple_prompt_for_comment (AI comment reply), the global handle_messenger_* duplicates of the handler class, and their
 * helpers. The cell answers Messenger (cell_direct); comment auto-reply stops until the cell handles comments. Archived at
 * core/_archived/fb-legacy-ai-20261024/legacy-functions.php.
 *
 * @package BizCity_Facebook_Bot
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Claim one Facebook Messenger inbound event across the current and legacy handlers.
 *
 * @param string $page_id
 * @param array  $messaging
 * @return bool
 */
if ( ! function_exists( 'bizcity_fb_claim_inbound_event' ) ) {
	function bizcity_fb_claim_inbound_event( $page_id, array $messaging ) {
		// [2026-08-01 Johnny Chu] HOTFIX — prevent one Facebook mid from reaching CRM and legacy AI twice.
		$page_id  = (string) $page_id;
		$sender   = (string) ( $messaging['sender']['id'] ?? '' );
		$message  = is_array( $messaging['message'] ?? null ) ? $messaging['message'] : array();
		$message_id = (string) ( $message['mid'] ?? '' );
		$identity = $message_id !== ''
			? $page_id . '|' . $message_id
			: $page_id . '|' . $sender . '|' . (string) ( $messaging['timestamp'] ?? '' ) . '|' . (string) ( $message['text'] ?? '' );
		$key = 'bz_fb_inbound_claim_' . md5( $identity );

		// [2026-08-01 Johnny Chu] HOTFIX — use network scope because the same
		// Facebook event can arrive through Central Webhook and a tenant endpoint.
		if ( get_site_transient( $key ) ) {
			return false;
		}
		if ( function_exists( 'wp_cache_add' ) && ! wp_cache_add( $key, 1, 'site-transient', 10 * MINUTE_IN_SECONDS ) ) {
			return false;
		}
		set_site_transient( $key, 1, 10 * MINUTE_IN_SECONDS );
		return true;
	}
}

if ( ! function_exists( 'bizgpt_format_input_data' ) ) {
	function bizgpt_format_input_data( $page_id, $client_id, $client_profile, $message_text, $message_id, $timestamp, $attachment_urls = array() ) {
		return array(
			'event'         => 'message.create',
			'page_id'       => $page_id,
			'client_id'     => $client_id,
			'platform_type' => 'FB_MESS',
			'conversation'  => array(
				'conversation_id'   => "{$page_id}_{$client_id}",
				'client_name'       => $client_profile['name'] ?? '',
				'client_phone'      => $client_profile['phone'] ?? '',
				'last_message'      => $message_text ?: ( $attachment_urls[0] ?? '' ),
				'img_url'           => $attachment_urls[0] ?? '',
				'last_message_id'   => $message_id,
				'last_message_time' => $timestamp,
				'last_message_type' => 'client',
			),
			'message'       => array(
				'message_id'   => $message_id,
				'message_type' => 'client',
				'created_at'   => current_time( 'mysql' ),
			),
		);
	}
}

// [2026-10-25 Johnny Chu - Chu Hoàng Anh] PHASE-0.126 R126-LG — fb_messenger_reply_comment() removed: the AI comment reply that called it left in R126-FB (comments only notify the
// admin now). Archived at core/_archived/legacy-messenger-r126lg-20261025/.
