<?php
/**
 * Legacy Functions from fb-messenger-hook.php
 * 
 * These functions are kept for backward compatibility with existing code.
 * They handle Messenger messages, images, referrals, and Facebook comments.
 * 
 * @package BizCity_Facebook_Bot
 * @since 1.0.0
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

/**
 * Format input data for BizGPT
 */
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

/**
 * Handle Messenger Message
 */
if ( ! function_exists( 'handle_messenger_message' ) ) {
	function handle_messenger_message( $page_id, $messaging ) {
		$client_id    = $messaging['sender']['id'] ?? '';
		$message      = $messaging['message'] ?? array();
		$message_text = $message['text'] ?? '';
		$attachments  = $message['attachments'] ?? array();
		$timestamp    = $messaging['timestamp'] ?? '';
		$referral     = $messaging['referral'] ?? array();

		$client_profile = function_exists( 'messenger_get_fb_customer' ) 
			? messenger_get_fb_customer( $page_id, $client_id ) 
			: array( 'name' => '' );

		$message_id      = $message['mid'] ?? '';
		if ( function_exists( 'bizcity_fb_claim_inbound_event' ) && ! bizcity_fb_claim_inbound_event( $page_id, $messaging ) ) {
			return;
		}
		$attachment_urls = array();
		foreach ( $attachments as $att ) {
			if ( ! empty( $att['payload']['url'] ) ) {
				$attachment_urls[] = $att['payload']['url'];
			}
		}

		$input_data = bizgpt_format_input_data( $page_id, $client_id, $client_profile, $message_text, $message_id, $timestamp, $attachment_urls );

		set_transient( 'hook_data', array(
			'user_id'     => 0,
			'client_id'   => $client_id,
			'session_id'  => '',
			'page_id'     => $page_id,
			'platform'    => 'FB_MESS',
			'client_name' => $client_profile['name'] ?? '',
		), 10 * MINUTE_IN_SECONDS );

		// Handle referral only
		if ( ! empty( $referral ) && empty( $message ) ) {
			if ( function_exists( 'back_trace' ) ) {
				back_trace( 'NOTICE', '📌 Nhận referral không kèm tin nhắn' );
			}
			handle_referral( $messaging, $client_id, $page_id, '', array() );
			return;
		}

		// Prevent duplicate
		if ( $message_id && get_transient( "fb_msg_{$client_id}_{$message_id}" ) ) {
			return;
		}
		set_transient( "fb_msg_{$client_id}_{$message_id}", 1, 2 * MINUTE_IN_SECONDS );

		// Log inbox message
        /*
		if ( function_exists( 'bizgpt_log_inbox_msg' ) ) {
			bizgpt_log_inbox_msg( $input_data );
		}
        */
		// Handle image
		if ( ! empty( $attachment_urls ) ) {
			foreach ( $attachment_urls as $img_url ) {
				handle_messenger_image( $page_id, $client_id, $img_url );
			}
			return;
		}

		// Handle text
		if ( ! empty( $message_text ) ) {
			handle_messenger_text( $page_id, $client_id, $message_text, $input_data );
		}

		// Handle referral with message
		handle_referral( $messaging, $client_id, $page_id, $client_profile['name'] ?? '', $input_data );
	}
}

/**
 * Handle Messenger Text - Call bizgpt_chatbot_run_guest_flows
 */
if ( ! function_exists( 'handle_messenger_text' ) ) {
	function handle_messenger_text( $page_id, $client_id, $message_text, $input_data ) {
		if ( ! function_exists( 'bizgpt_chatbot_run_guest_flows' ) || ! function_exists( 'fb_messenger_reply' ) ) {
			return;
		}

		$client_context = '';
		$arr = bizgpt_chatbot_run_guest_flows( $message_text, 'FB_MESS', $input_data, $client_context );

		if ( is_array( $arr ) ) {
			foreach ( $arr as $item ) {
				if ( ! empty( $item['msg'] ) ) {
					$msg_text = html_entity_decode( wp_strip_all_tags( str_replace( '<br>', "\n", $item['msg'] ) ), ENT_QUOTES, 'UTF-8' );
					fb_messenger_reply( $page_id, $client_id, 'AI: ' . $msg_text );
				}
			}
		}
	}
}

/**
 * Handle Messenger Image
 */
if ( ! function_exists( 'handle_messenger_image' ) ) {
	function handle_messenger_image( $page_id, $client_id, $img_url ) {
		if ( ! function_exists( 'fb_messenger_reply' ) ) {
			return;
		}

		$img_hash      = md5( $img_url );
		$transient_key = 'fb_img_' . $client_id . '_' . $img_hash;

		if ( get_transient( $transient_key ) ) {
			$reply_text = 'Bạn vừa gửi 1 hình ảnh trùng lặp trong vòng dưới 2 phút. Tôi chưa được cho phép để giải thích về ảnh liên tục.!';
			fb_messenger_reply( $page_id, $client_id, 'AI: ' . $reply_text );
			return;
		}
		set_transient( $transient_key, 1, 2 * MINUTE_IN_SECONDS );

		$reply_text = 'Dạ. Bạn vừa gửi 1 hình ảnh. ';
		fb_messenger_reply( $page_id, $client_id, 'AI: ' . $reply_text );

		// [2026-08-09 Johnny Chu] R-GW-8 — legacy image helper keeps only the
		// acknowledgment; canonical UCL/CRM owns image understanding and reply.
	}
}

/**
 * Handle Referral - Call bizgpt_run_flow_steps
 */
if ( ! function_exists( 'handle_referral' ) ) {
	function handle_referral( $messaging, $client_id, $page_id, $client_name = '', $input_data = array() ) {
		$ref = $messaging['referral']['ref'] ?? $messaging['postback']['referral']['ref'] ?? '';
		if ( ! $ref ) {
			return;
		}

		$ref_decrypted = class_exists( 'BizCity_CG_Flow_Ref_Codec' )
			? BizCity_CG_Flow_Ref_Codec::decode( $ref, 'vietqr' )
			: 0;
		if ( ! $ref_decrypted ) {
			return;
		}

		// Spam lock (3 minutes)
		$lock_key = 'bizgpt_ref_lock_' . md5( $page_id . '|' . $client_id . '|' . $ref_decrypted );
		if ( get_transient( $lock_key ) && get_current_blog_id() === '418' ) {
			return;
		}
		set_transient( $lock_key, 1, 3 * MINUTE_IN_SECONDS );

		if ( function_exists( 'bizgpt_run_flow_steps' ) && function_exists( 'fb_messenger_reply' ) ) {
			// Guard: bizgpt_run_flow_steps requires int flow_id. Skip for
			// Ref_Codec tokens like `camp_<token>` (handled by CampaignTracker).
			$flow_id_int = is_numeric( $ref_decrypted ) ? (int) $ref_decrypted : 0;
			if ( $flow_id_int <= 0 ) {
				return;
			}
			$arr = bizgpt_run_flow_steps( $flow_id_int, $input_data );
			if ( is_array( $arr ) ) {
				foreach ( $arr as $item ) {
					if ( ! empty( $item['msg'] ) ) {
						fb_messenger_reply( $page_id, $client_id, 'AI: ' . $item['msg'] );
					}
				}
			}
		}
	}
}

/**
 * Handle Facebook Feed Event (Comments)
 */
if ( ! function_exists( 'handle_facebook_feed_event' ) ) {
	function handle_facebook_feed_event( $page_id, $change ) {
		$value = $change['value'] ?? array();

		if ( ( $value['item'] ?? '' ) !== 'comment' || empty( $value['message'] ) ) {
			return;
		}

		$comment_id_parts = explode( '_', $value['comment_id'] ?? '' );
		$comment_id       = end( $comment_id_parts );
		$message          = $value['message'];
		$from_name        = $value['from']['name'] ?? 'Người dùng';
		$from_id          = $value['from']['id'] ?? '';
		$post_id          = $value['post_id'] ?? '';

		// Prevent duplicate
		$transient_key = 'fb_comment_' . $comment_id;
		if ( get_transient( $transient_key ) ) {
			return;
		}
		set_transient( $transient_key, 1, 3 * MINUTE_IN_SECONDS );

		// Skip if comment from page itself
		if ( $from_id === $page_id ) {
			error_log( "[FB] Bỏ qua comment do chính page tạo ra (ID: $from_id)" );
			return;
		}

		// Get access token
		$pages        = get_option( 'fb_pages_connected' );
		$access_token = null;

		if ( is_array( $pages ) ) {
			foreach ( $pages as $page ) {
				if ( ( $page['id'] ?? '' ) === $page_id ) {
					$access_token = $page['access_token'] ?? null;
					break;
				}
			}
		}

		if ( ! $access_token ) {
			return;
		}

		// Check post type
		$post_type     = 'feed';
		$check_url     = "https://graph.facebook.com/v18.0/{$post_id}?fields=id,permalink_url,type,message,description,story&access_token={$access_token}";
		$check_response = wp_remote_get( $check_url );
		$check_data    = json_decode( wp_remote_retrieve_body( $check_response ), true );

		if ( ! empty( $check_data['type'] ) && $check_data['type'] === 'video' ) {
			$post_type = 'live_video';
		}

		$post_caption = $check_data['message'] ?? $check_data['description'] ?? $check_data['story'] ?? '';

		// Generate AI reply
		$ai_reply = '';
		if ( function_exists( 'bizgpt_router_comment_flow' ) ) {
			$ai_reply = bizgpt_router_comment_flow( $message, $post_caption, $page_id, $from_id, $from_name );
		}

		if ( empty( $ai_reply ) ) {
			$ai_reply = 'Cảm ơn bạn đã để lại bình luận! Chúng tôi sẽ hỗ trợ bạn ngay.';
		}

		// Send comment reply
		if ( function_exists( 'fb_messenger_reply_comment' ) ) {
			fb_messenger_reply_comment( $comment_id, $ai_reply, $access_token );
		}

		// Send notification to admin
		$notification = 'Khách đã nhắn bình luận: ' . $message . "\n\n" . 'AI đã trả lời: ' . $ai_reply;
		$blog_domain  = $_SERVER['HTTP_HOST'] ?? '';

		if ( function_exists( 'send_notice_to_zalo_admin' ) ) {
			send_notice_to_zalo_admin( $notification, $from_id, $from_name, $blog_domain, $post_type . ' Comment' );
		}

		// Log comment
		if ( function_exists( 'bizgpt_log_comment_ai' ) ) {
			bizgpt_log_comment_ai( array(
				'page_id'     => $page_id,
				'post_id'     => $post_id,
				'post_type'   => $post_type,
				'comment_id'  => $value['comment_id'] ?? '',
				'parent_id'   => null,
				'sender_id'   => $from_id,
				'sender_name' => $from_name,
				'message'     => $message,
				'ai_reply'    => $ai_reply,
				'client_id'   => $_SERVER['HTTP_HOST'] ?? null,
			) );
		}
	}
}

/**
 * Replace placeholders in text
 */
if ( ! function_exists( 'bizgpt_replace_placeholders' ) ) {
	function bizgpt_replace_placeholders( $text, $placeholders = array() ) {
		foreach ( $placeholders as $key => $value ) {
			$text = str_replace( "{{$key}}", $value, $text );
		}
		return $text;
	}
}

/**
 * Router for comment flow
 */
if ( ! function_exists( 'bizgpt_router_comment_flow' ) ) {
	function bizgpt_router_comment_flow( $message_text, $post_caption, $page_id, $client_id = '', $from_name = '' ) {
		$router       = bizgpt_parse_comment_flow( $message_text );
		$placeholders = array(
			'customer_name' => $from_name ?: 'bạn',
		);

		$prompt = "Khách hàng tên $from_name vừa bình luận trên bài viết: \"$message_text\". Chủ đề được bình luận là: \"$post_caption\". Hãy phản hồi lịch sự, chuyên nghiệp và hữu ích.";

		$router_key = is_array( $router ) ? ( $router['router'] ?? '' ) : $router;

		switch ( $router_key ) {
			case 'price_flow':
				$reply = get_option( 'bizgpt_reply_price_flow' ) ?: 'Chúng tôi có nhiều mức giá khác nhau tùy theo sản phẩm. Bạn có thể cho tôi biết bạn quan tâm đến sản phẩm nào không?';
				$reply = bizgpt_replace_placeholders( $reply, $placeholders );
				if ( function_exists( 'fb_messenger_reply' ) ) {
					fb_messenger_reply( $page_id, $client_id, $reply );
				}
				break;

			case 'shipping_flow':
				$reply = get_option( 'bizgpt_reply_shipping_flow' ) ?: 'Chúng tôi giao hàng nội thành miễn phí.';
				$reply = bizgpt_replace_placeholders( $reply, $placeholders );
				break;

			case 'contact_flow':
				$reply = get_option( 'bizgpt_reply_contact_flow' ) ?: 'Bạn có thể liên hệ với chúng tôi qua hotline nhé.';
				$reply = bizgpt_replace_placeholders( $reply, $placeholders );
				break;

			case 'demo_flow':
				$reply = get_option( 'bizgpt_reply_demo_flow' ) ?: 'Chúng tôi có bản dùng thử miễn phí nhé ạ.';
				$reply = bizgpt_replace_placeholders( $reply, $placeholders );
				break;

			case 'praise_flow':
				$reply = get_option( 'bizgpt_reply_praise_flow' ) ?: 'Hihi. Cảm ơn bạn đã khen!';
				$reply = bizgpt_replace_placeholders( $reply, $placeholders );
				break;

			default:
				$reply = '';
				// [2026-08-09 Johnny Chu] R-1API-AUTH — use the canonical client for comment fallback replies.
				if ( class_exists( 'BizCity_LLM_Client' ) && BizCity_LLM_Client::instance()->is_ready() ) {
					$response = BizCity_LLM_Client::instance()->chat(
						array( array( 'role' => 'user', 'content' => $prompt ) ),
						array( 'purpose' => 'comment_reply', 'temperature' => 0.4, 'max_tokens' => 300 )
					);
					$reply = (string) ( $response['message'] ?? '' );
				}
				break;
		}

		return $reply;
	}
}

/**
 * Parse comment flow
 */
if ( ! function_exists( 'bizgpt_parse_comment_flow' ) ) {
	function bizgpt_parse_comment_flow( $message_text ) {
		$text   = strtolower( (string) $message_text );
		$router = '';
		$reply  = '';

		if ( preg_match( '/\b(giá|bn tiền|nhiêu tiền|cost|ib|inbox|price)\b/u', $text ) ) {
			$router = 'price_flow';
		} elseif ( preg_match( '/\b(ship|vận chuyển|giao hàng|free ship|giao hang)\b/u', $text ) ) {
			$router = 'shipping_flow';
		} elseif ( preg_match( '/\b(sđt|số điện thoại|liên hệ|call|zalo)\b/u', $text ) ) {
			$router = 'contact_flow';
		} elseif ( preg_match( '/\b(dùng thử|test|demo)\b/u', $text ) ) {
			$router = 'demo_flow';
		}

		if ( empty( $router ) && ! empty( $message_text ) ) {
			$router = 'default_flow';
			if ( function_exists( 'bizgpt_parse_comment_flow_dynamic' ) ) {
				$reply = (string) bizgpt_parse_comment_flow_dynamic( $message_text );
			}
		}

		return array(
			'router' => $router ?: 'default_flow',
			'reply'  => $reply,
		);
	}
}

/**
 * Get reply by router
 */
if ( ! function_exists( 'bizgpt_comment_flow_reply_by_router' ) ) {
	function bizgpt_comment_flow_reply_by_router( $router, $data_hook = array() ) {
		$router = trim( $router );

		switch ( $router ) {
			case 'price_flow':
				return 'Dạ bạn cần hỏi giá sản phẩm nào ạ? Bạn gửi tên sản phẩm hoặc ảnh giúp mình nhé.';

			case 'shipping_flow':
				return 'Dạ shop có giao hàng toàn quốc. Bạn cho mình xin tỉnh/thành + quận/huyện để mình báo phí và thời gian giao dự kiến nhé.';

			case 'contact_flow':
				return 'Dạ bạn cần liên hệ nhanh, bạn để lại SĐT hoặc nhắn Zalo giúp mình nhé. CSKH sẽ hỗ trợ ngay.';

			case 'demo_flow':
				return 'Dạ bạn muốn dùng thử/ demo, bạn cho mình biết nhu cầu cụ thể để mình hướng dẫn gói phù hợp nhé.';

			default:
				return '';
		}
	}
}

/**
 * Reply to Facebook comment
 */
if ( ! function_exists( 'fb_messenger_reply_comment' ) ) {
	function fb_messenger_reply_comment( $comment_id, $message, $access_token = '' ) {
		// Try public comment first
		$url      = "https://graph.facebook.com/v23.0/{$comment_id}/comments";
		$response = wp_remote_post( $url, array(
			'body' => array(
				'message'      => $message,
				'access_token' => $access_token,
			),
		) );

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		// Fallback to private reply
		if ( is_wp_error( $response ) || isset( $data['error'] ) ) {
			$url_private      = "https://graph.facebook.com/v18.0/{$comment_id}/private_replies";
			$response_private = wp_remote_post( $url_private, array(
				'body'    => array(
					'message'      => $message,
					'access_token' => $access_token,
				),
				'timeout' => 20,
			) );
		}
	}
}

/**
 * Simple prompt for comment — routes through BizCity LLM Router
 */
if ( ! function_exists( 'chatbot_chatgpt_simple_prompt_for_comment' ) ) {
	function chatbot_chatgpt_simple_prompt_for_comment( $api_key, $prompt, $model = 'gpt-4.1-nano' ) {
		if ( ! $prompt ) {
			return '';
		}

		if ( function_exists( 'bizcity_llm_chat' ) ) {
			$messages = array(
				array(
					'role'    => 'system',
					'content' => 'Bạn là một trợ lý AI thân thiện, giúp doanh nghiệp trả lời bình luận của khách trên Facebook một cách chuyên nghiệp, ngắn gọn và lịch sự.',
				),
				array(
					'role'    => 'user',
					'content' => $prompt,
				),
			);
			$result = bizcity_llm_chat( $messages, array( 'purpose' => 'executor', 'timeout' => 20 ) );
			return $result['message'] ?? '';
		}

		// Fallback: không có LLM Router thì trả về rỗng, tránh gọi trực tiếp OpenAI
		return '';
	}
}

/* ══════════════════════════════════════════════════════════════
 * [2026-10-01 Claude Sonnet 5] CORE-REDUCTION WP-17 K-1 (R-LEAN-4, R-GURU-SOURCE) — moved from
 * core/knowledge/includes/functions.php. bizgpt_chatbot_run_guest_flows() is only called by this plugin
 * (class-webhook-handler.php, this file's own fb_messenger_reply path); its three helpers
 * (bizgpt_chatbot_fallback_ai_response, bizgpt_inbox_assign_flow_id, bizgpt_return_or_ajax) are only called
 * from inside it. bizgpt_inbox_assign_flow_id() writes bizcity_facebook_inbox — this plugin's own table.
 * The one call that stays cross-plugin, bizcity_query_character() (core/knowledge), is now behind an explicit
 * function_exists() guard since this file no longer lives in the same plugin as its definition.
 * ══════════════════════════════════════════════════════════════ */
/**
 * bizgpt_chatbot_fallback_ai_response() - Fallback AI response
 * 
 * Gọi OpenAI để lấy câu trả lời fallback
 * Migrate từ bizgpt-agent.php
 * 
 * @param string $api_key OpenAI API key (nếu trống sẽ dùng option)
 * @param string $question Câu hỏi
 * @return string Reply từ AI
 */
if (!function_exists('bizgpt_chatbot_fallback_ai_response')) :
function bizgpt_chatbot_fallback_ai_response($api_key, $question) {
    // Ưu tiên sử dụng BizCity_WebChat_AI nếu có
    if (class_exists('BizCity_WebChat_AI')) {
        $ai = BizCity_WebChat_AI::instance();
        return $ai->get_fallback_response($question, $api_key);
    }
    
    // PHASE-0-RULE-SMART-GATEWAY-MIGRATION: phải đi qua BizCity LLM Router.
    // Tham số $api_key giữ lại để giữ chữ ký hàm cũ; bị bỏ qua.
    unset( $api_key );

    if ( ! class_exists( 'BizCity_LLM_Client' ) ) {
        return 'Xin lỗi, BizCity LLM Router chưa được cài.';
    }
    $client = BizCity_LLM_Client::instance();
    if ( ! $client->is_ready() ) {
        return 'Xin lỗi, BizCity LLM Router chưa được cấu hình API key.';
    }
    $resp = $client->chat( [
        [ 'role' => 'system', 'content' => 'Bạn là trợ lý AI hữu ích, trả lời ngắn gọn và thân thiện.' ],
        [ 'role' => 'user',   'content' => $question ],
    ], [
        'purpose'     => 'fast',
        'temperature' => 0.7,
        'max_tokens'  => 500,
        'timeout'     => 30,
    ] );
    if ( empty( $resp['success'] ) ) {
        return 'Xin lỗi, có lỗi kết nối đến AI.';
    }
    return $resp['message'] ?? 'Xin lỗi, không thể xử lý câu hỏi của bạn.';
}
endif;

/**
 * bizgpt_chatbot_run_guest_flows() - Full implementation
 * 
 * Migrate từ bizgpt-agent/float_chatbot_front.php
 * Logic flow: Tùy biến theo xem sản phẩm, tìm kiếm, đặt hàng ...
 * 
 * @param string $question Câu hỏi từ khách
 * @param string $platform Platform (webchat, zalo, FB_MESS, etc)
 * @param mixed $data_hook Data từ hook (client_id, page_id, conversation)
 * @param string $context Context bổ sung
 * @return array Messages array
 */
if (!function_exists('bizgpt_chatbot_run_guest_flows')) :
function bizgpt_chatbot_run_guest_flows($question, $platform = 'webchat', $data_hook = '', $context = '') {
    global $bot_setup;  // Setup bot từ file bizgpt/setup_bot.php
    
    $api_key    = get_option('twf_openai_api_key');
    $identity   = function_exists('bizgpt_get_webchat_identity') 
                    ? bizgpt_get_webchat_identity() 
                    : ['user_id' => get_current_user_id(), 'session_id' => session_id()];
    $user_id    = $identity['user_id'];
    $session_id = $identity['session_id'];
    
    // From zalo và facebook
    $client_id   = @$data_hook['client_id'];
    $page_id     = @$data_hook['page_id'];
    $client_name = @$data_hook['conversation']['client_name'];
    
    // Lưu vào transient để có thể dùng lại sau này
    set_transient('hook_data', [
        'user_id'     => $user_id,
        'client_id'   => $client_id,
        'session_id'  => $session_id,
        'page_id'     => $page_id,
        'platform'    => $platform,
        'client_name' => $client_name,
    ], 10 * MINUTE_IN_SECONDS);
    
    // 1) Log lại tin nhắn user
    if (function_exists('bizgpt_log_chat_message')) {
        bizgpt_log_chat_message($user_id, $question, 'user', $session_id);
    }
    
    $current_blog_id = get_current_blog_id();
    $blog_name = get_bloginfo('name');
    $blog_detail = function_exists('get_blog_details') ? get_blog_details($current_blog_id) : null;
    $blog_domain = is_object($blog_detail) ? $blog_detail->domain : '';
    
    // 2) Gửi admin Telegram (nếu cần)
    if ($platform && function_exists('twf_telegram_send_message') && function_exists('twf_list_client_ids_by_blog_id')) {
        $msg = "💬 <b>Khách từ $platform: </b><code>$blog_name - $blog_domain</code> \n"
             . "🗨️ <b>Khách nhắn:</b> $question\n\n"
             . "👤 <b>user_id:</b> <code>$user_id</code>\n"
             . "👤 <b>Tên khách:</b> <code>$client_name</code>\n"
             . "👤 <b>Mã định danh:</b> <code>$client_id</code>\n"
             . "🔑 <b>Mã id phiên:</b> <code>$session_id</code>\n"
             . "📩 <b>Hướng dẫn:</b>\n"
             . "Nhắn: <code>Trả lời tới khách session_id: $session_id nội dung là: ...</code>\n"
             . "Em sẽ gửi tin nhắn cho khách giúp sếp 🧠";
        
        $chat_ids = twf_list_client_ids_by_blog_id(get_current_blog_id());
        foreach ($chat_ids as $chat_id) {
            twf_telegram_send_message($chat_id, $msg, 'HTML', null);
        }
    }
    
    // 3) Ưu tiên logic theo setup: shortcode -> text -> ai
    $using_flow_shortcode    = !empty($bot_setup['using_flow_shortcode']);
    $using_flow_text         = !empty($bot_setup['using_flow_text']);
    $using_ai                = !empty($bot_setup['using_ai']);
    
    $using_fb_flow_shortcode = !empty($bot_setup['using_fb_flow_shortcode']);
    $using_fb_flow_text      = !empty($bot_setup['using_fb_flow_text']);
    $using_fb_ai             = !empty($bot_setup['using_fb_ai']);
    
    // Xác định kênh Facebook
    $is_fb = in_array((string)$platform, ['FB_MESS','FB_COMMENT','FB','facebook'], true);
    
    // Cờ hiệu lực theo kênh (WEB vs FB)
    $allow_shortcode = $is_fb ? $using_fb_flow_shortcode : $using_flow_shortcode;
    $allow_text      = $is_fb ? $using_fb_flow_text      : $using_flow_text;
    $allow_ai        = $is_fb ? $using_fb_ai             : $using_ai;
    
    // (1) using_flow_shortcode: ưu tiên custom flow + return luôn
    // [2026-09-27 Claude Opus 5.5] CORE-REDUCTION WP-12 R8 — the legacy bizgpt-custom-flows handler calls
    // bizgpt_get_webchat_identity() unguarded; that helper lived only in the archived Hotline plugin. Enter the
    // legacy guest flow only when it exists, otherwise a still-active bizgpt-custom-flows would fatal here.
    if (function_exists('bizgpt_handle_guest_flow') && function_exists('bizgpt_match_custom_flow') && function_exists('bizgpt_get_webchat_identity')) {
        $key = "bizgpt_flow_ctx_$session_id";
        $ctx = get_transient($key) ?: ['flow_id' => 0, 'params' => []];
        
        // Luôn cố gắng match intent & trích params mới từ câu hỏi
        $cf = bizgpt_match_custom_flow($question);
        
        if (!empty($cf['flow_id'])) {
            $ctx['flow_id'] = intval($cf['flow_id']);
            $ctx['params']  = array_merge($ctx['params'], $cf['output']['params'] ?? []);
            set_transient($key, $ctx, 10 * MINUTE_IN_SECONDS);
            
            // Gán flow_id vào inbox (zalo/fb) để cron dùng
            if (function_exists('bizgpt_inbox_assign_flow_id')) {
                bizgpt_inbox_assign_flow_id($cf['flow_id'], $client_id, $platform, $page_id);
            }
        }
        
        // Nếu đã có flow_id => handle và return luôn
        if (!empty($ctx['flow_id'])) {
            $msgs = bizgpt_handle_guest_flow($question);
            if (!empty($msgs)) {
                return bizgpt_return_or_ajax($msgs, $platform);
            }
        }
    }
    
    // (2) TEXT FLOW: ưu tiên theo kênh
    if ($allow_text && function_exists('bizgpt_parse_comment_flow') && function_exists('bizgpt_comment_flow_reply_by_router')) {
        $allow_dynamic_ai_in_text = $is_fb ? $allow_ai : false;
        
        $parsed = bizgpt_parse_comment_flow($question, $allow_dynamic_ai_in_text);
        $router = $parsed['router'] ?? 'default_flow';
        $reply  = $parsed['reply']  ?? '';
        
        $msg = bizgpt_comment_flow_reply_by_router($router, (array)$data_hook);
        
        if (empty($msg) && !empty($reply)) {
            $msg = $reply;
        }
        
        // Match được kịch bản => trả luôn
        if (!empty($msg)) {
            $rec = function_exists('bizgpt_log_chat_message') 
                    ? bizgpt_log_chat_message($user_id, $msg, 'bot', $session_id)
                    : ['msg_text' => $msg, 'msg_from' => 'bot'];
            return bizgpt_return_or_ajax([$rec], $platform);
        }
        
        // Không match text flow
        if (!(!$is_fb && $allow_ai)) {
            $text = $is_fb
                ? ''
                : 'Xin lỗi, mình chưa tìm thấy nội dung phù hợp. Bạn thử nhập từ khóa rõ hơn giúp mình nhé.';
            $rec = function_exists('bizgpt_log_chat_message')
                    ? bizgpt_log_chat_message($user_id, $text, 'bot', $session_id)
                    : ['msg_text' => $text, 'msg_from' => 'bot'];
            return bizgpt_return_or_ajax([$rec], $platform);
        }
    }
    
    // (3) AI: chỉ chạy nếu cờ theo kênh cho phép
    if ($allow_ai) {
        $ctxText = '';
        if ($client_name) {
            $ctxText .= 'Tôi tên là ' . $client_name . '. ';
        }
        if ($context) {
            $ctxText .= $context;
        }
        
        // Resolve character_id:
        //   1) Per-page binding (BizCity_Channel_Binding) — Facebook page_id, Zalo OA, etc.
        //   2) Global option fallback (bizcity_knowledge_default_character).
        // (PHASE 0.36 — Bug #7 fix: webhook reply must honor the Guru bound to
        //  this specific Page via Inspector bindings, not the site-wide default.)
        $character_id = 0;
        if (class_exists('BizCity_Channel_Binding')) {
            $bind_platform = '';
            $bind_account  = '';
            if ($is_fb && !empty($page_id)) {
                // FE Inspector binds Facebook pages under 'FB_MESS'.
                $bind_platform = 'FB_MESS';
                $bind_account  = (string) $page_id;
            } elseif (in_array((string) $platform, ['ZALO','ZALO_OA','zalo','zalo_oa'], true) && !empty($page_id)) {
                $bind_platform = 'ZALO_OA';
                $bind_account  = (string) $page_id;
            }
            if ($bind_platform !== '' && $bind_account !== '') {
                $bind = BizCity_Channel_Binding::resolve($bind_platform, $bind_account);
                if (is_array($bind) && !empty($bind['character_id'])) {
                    $character_id = (int) $bind['character_id'];
                }
            }
        }
        if ($character_id <= 0) {
            $character_id = (int) get_option('bizcity_knowledge_default_character');
        }
        if ($character_id && class_exists('BizCity_Character') && function_exists('bizcity_query_character')) {
            $result = bizcity_query_character($character_id, $question, [
                'platform' => $platform,
                'data_hook' => $data_hook,
                'context' => $ctxText,
            ]);
            $text = $result['response'] ?? '';
        }
        
        // Fallback vào AI nếu không có character hoặc không có kết quả
        if (empty($text)) {
            if (function_exists('chatbot_chatgpt_call_omni')) {
                $text = chatbot_chatgpt_call_omni($api_key, $ctxText . $question);
            } else {
                $text = bizgpt_chatbot_fallback_ai_response($api_key, $ctxText . $question);
            }
        }
        
        $rec = function_exists('bizgpt_log_chat_message')
                ? bizgpt_log_chat_message($user_id, $text, 'bot', $session_id)
                : ['msg_text' => $text, 'msg_from' => 'bot'];
        $msgs = [$rec];
        
    } else {
        // (4) Không cho trả lời theo kênh này
        $text = $is_fb
            ? 'Xin lỗi, hiện tại fanpage chưa bật chế độ trả lời tự động.'
            : 'Xin lỗi, hiện tại bot chưa được thiết lập để trả lời (AI đang tắt).';
        
        $rec = function_exists('bizgpt_log_chat_message')
                ? bizgpt_log_chat_message($user_id, $text, 'bot', $session_id)
                : ['msg_text' => $text, 'msg_from' => 'bot'];
        $msgs = [$rec];
    }
    
    // 5) Gửi thông báo đến admin Zalo
    if ($platform === 'webchat' && function_exists('send_notice_to_zalo_admin')) {
        $current_user = wp_get_current_user();
        $user_name = $current_user ? $current_user->user_login : '';
        foreach ($msgs as $m) {
            send_notice_to_zalo_admin($text ?? '', $session_id, $user_name, $blog_domain, 'Web Chat');
        }
    }
    
    // 6) Trả về
    // Phase 0.99.3: canonical filter is `bizcity_after_handle_guest_flows`.
    // Legacy `bizgpt_after_handle_guest_flows` is still applied (back-compat)
    // and emits a deprecation notice via BizCity_Deprecation when listeners
    // are attached. Will be removed in 2.0.0.
    $msgs = apply_filters( 'bizcity_after_handle_guest_flows', $msgs, $platform, $question );
    if ( has_filter( 'bizgpt_after_handle_guest_flows' ) ) {
        if ( class_exists( 'BizCity_Deprecation' ) ) {
            BizCity_Deprecation::notify_filter(
                'bizgpt_after_handle_guest_flows',
                'bizcity_after_handle_guest_flows',
                '1.0.0',
                'Legacy bizgpt_* prefix replaced by bizcity_* namespace.'
            );
        }
        $msgs = apply_filters( 'bizgpt_after_handle_guest_flows', $msgs, $platform, $question );
    }

    foreach ($msgs as &$m) {
        $m['transcript'] = $question;
    }
    
    return bizgpt_return_or_ajax($msgs, $platform);
}
endif;

//Cập nhật khi đang xử lý chatbot và có flow_id
if ( ! function_exists( 'bizgpt_inbox_assign_flow_id' ) ) :
function bizgpt_inbox_assign_flow_id($flow_id, $client_id, $platform='FB_MESS', $page_id='') {
    global $wpdb;
    // Lấy cấu hình page_id nếu cần lọc
    $bot_setup = get_option('pmfacebook_options'); // Hoặc chỗ bạn lưu page_id
   # $page_id = $bot_setup['pageid'] ?? '';

    // Nếu không đủ dữ liệu thì bỏ qua
   # if (!$client_id || !$platform || !$page_id) return;

    $table = $wpdb->prefix . 'bizcity_facebook_inbox'; // bizcity-facebook-bot đã mirgrate từ bizgpt_inbox sang bizcity_facebook_inbox

    // Update message gần nhất của client trên platform đó<br />
	$sql = $wpdb->prepare("
        UPDATE $table
        SET flow_id = %d
        WHERE client_id = %s AND message_type = 'client'  AND platform_type = %s AND page_id = %s
        ORDER BY id DESC
        LIMIT 1
    ", $flow_id, $client_id, $platform, $page_id);
	back_trace('NOTICE', 'bizgpt_inbox_assign_flow_id: '.$sql);
    $wpdb->query($sql);
	
}
endif;
/**
 * bizgpt_return_or_ajax() - Helper function
 * 
 * Nếu platform = webchat VÀ đang là AJAX request thì gửi JSON response
 * Ngược lại trả về mảng để caller xử lý tiếp
 * 
 * @param array $msgs Messages
 * @param string $platform Platform
 * @return array|void
 */
if (!function_exists('bizgpt_return_or_ajax')) :
function bizgpt_return_or_ajax(array $msgs, string $platform) {
    // Chỉ send JSON và exit nếu là AJAX request từ frontend
    // Nếu được gọi từ internal code (như webchat trigger), return array thay vì exit
    if ($platform === 'webchat' && wp_doing_ajax() && !defined('BIZCITY_WEBCHAT_INTERNAL_CALL')) {
        wp_send_json_success($msgs);
        exit;
    }
    return $msgs;
}
endif;
