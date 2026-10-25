<?php
/**
 * Webhook Handler
 * Processes incoming webhooks from Zalo Bot
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BizCity_Zalo_Bot_Webhook_Handler {
	
	private static $instance = null;

	// [2026-10-25 Johnny Chu - Chu Hoàng Anh] PHASE-0.126 R126-SEC C1 — admin flag: active bots without a stored webhook secret (their deliveries are refused).
	const SECRET_MISSING_FLAG = 'bizcity_zalobot_webhook_secret_missing';

	/** @var object|null [2026-10-25 Johnny Chu - Chu Hoàng Anh] PHASE-0.126 R126-SEC C1 — the bot whose stored secret authenticated this request. */
	private $auth_bot = null;
	/** @var int blog that owns $auth_bot */
	private $auth_blog_id = 0;

	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function __construct() {
		add_action( 'init', array( $this, 'add_rewrite_rules' ) );
		add_action( 'template_redirect', array( $this, 'handle_webhook' ) );
		// [2026-10-25 Johnny Chu - Chu Hoàng Anh] PHASE-0.126 R126-SEC C1 — tell the admin which bots must re-run "Set webhook" (no secret ⇒ refused).
		add_action( 'admin_notices', array( $this, 'admin_notice_missing_secret' ) );
	}
	
	/**
	 * Add rewrite rules for webhook endpoint
	 */
	public function add_rewrite_rules() {
		// Zalo Bot webhook endpoint: /zalohook/
		add_rewrite_rule(
			'^zalohook/?$',
			'index.php?zalohook=1',
			'top'
		);
		
		add_filter( 'query_vars', function( $vars ) {
			$vars[] = 'zalohook';
			$vars[] = 'zalohook_test';
			return $vars;
		} );
	}
	
	/**
	 * Handle incoming webhook
	 */
	public function handle_webhook() {
		// Handle test endpoint  
		if ( get_query_var( 'zalohook_test' ) ) {
			// [2026-10-25 Johnny Chu - Chu Hoàng Anh] PHASE-0.126 R126-SEC C1 — test endpoint is admin-only and never echoes the body back.
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( array( 'message' => 'Forbidden' ), 403 );
				exit;
			}
			wp_send_json_success( array(
				'message' => 'Test endpoint working',
				'method' => isset( $_SERVER['REQUEST_METHOD'] ) ? (string) $_SERVER['REQUEST_METHOD'] : '',
				'timestamp' => current_time( 'mysql' ),
				'input_length' => strlen( $this->get_cached_raw_input() ),
			) );
			exit;
		}
		
		// Handle zalohook endpoint
		if ( get_query_var( 'zalohook' ) ) {
			$this->handle_zalohook();
			return;
		}
	}
	

	
	/**
	 * Handle new Zalo Bot webhook endpoint
	 */
	private function handle_zalohook() {
		$raw_data = $this->get_cached_raw_input();

		// [2026-10-25 Johnny Chu - Chu Hoàng Anh] PHASE-0.126 R126-SEC C1 — fail closed: a delivery is processed (or logged) only when its
		// X-Bot-Api-Secret-Token equals a stored bot secret (hash_equals); no "first active bot" fallback. Empty pings carry nothing and still get 200.
		$secret_token = isset( $_SERVER['HTTP_X_BOT_API_SECRET_TOKEN'] ) ? (string) $_SERVER['HTTP_X_BOT_API_SECRET_TOKEN'] : '';
		if ( $raw_data !== '' || ! empty( $_POST ) ) {
			if ( ! $this->authenticate_zalohook( $secret_token ) ) {
				$this->flag_bots_without_secret();
				$this->log_zalohook_error( 'Rejected unauthenticated webhook', array(
					'reason'     => $secret_token === '' ? 'secret_header_missing' : 'secret_unknown',
					'raw_length' => strlen( $raw_data ),
				) );
				wp_send_json_error( array( 'message' => 'Unauthorized' ), 401 );
				exit;
			}
		}

		$data = array();

		if ( $raw_data !== '' ) {
			$decoded = json_decode( $raw_data, true );
			if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $decoded ) ) {
				$this->log_zalohook_error(
					'JSON decode error: ' . json_last_error_msg() . '. Raw data length: ' . strlen( $raw_data ),
					array(
						'method'       => isset( $_SERVER['REQUEST_METHOD'] ) ? (string) $_SERVER['REQUEST_METHOD'] : '',
						'content_type' => isset( $_SERVER['CONTENT_TYPE'] ) ? (string) $_SERVER['CONTENT_TYPE'] : '',
					)
				);
				wp_send_json_error( array( 'message' => 'JSON decode error: ' . json_last_error_msg() ), 400 );
				exit;
			}
			$data = $decoded;
		} elseif ( isset( $_POST['data'] ) && is_string( $_POST['data'] ) && $_POST['data'] !== '' ) {
			$decoded = json_decode( wp_unslash( $_POST['data'] ), true );
			if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $decoded ) ) {
				$this->log_zalohook_error( 'Invalid form payload in $_POST[data]' );
				wp_send_json_error( array( 'message' => 'Invalid form payload' ), 400 );
				exit;
			}
			$data = $decoded;
		} elseif ( isset( $_POST['update'] ) && is_string( $_POST['update'] ) && $_POST['update'] !== '' ) {
			$decoded = json_decode( wp_unslash( $_POST['update'] ), true );
			if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $decoded ) ) {
				$this->log_zalohook_error( 'Invalid form payload in $_POST[update]' );
				wp_send_json_error( array( 'message' => 'Invalid form payload' ), 400 );
				exit;
			}
			$data = $decoded;
		} elseif ( ! empty( $_POST ) && ( isset( $_POST['event_name'] ) || isset( $_POST['message'] ) || isset( $_POST['event'] ) ) ) {
			$data = wp_unslash( $_POST );
		}

		if ( ! is_array( $data ) || empty( $data ) ) {
			// [2026-07-08 Johnny Chu] HOTFIX — do not treat empty-body webhook ping as a hard error.
			$this->log_zalohook_info( 'Webhook ping/empty payload', array(
				'method'       => isset( $_SERVER['REQUEST_METHOD'] ) ? (string) $_SERVER['REQUEST_METHOD'] : '',
				'content_type' => isset( $_SERVER['CONTENT_TYPE'] ) ? (string) $_SERVER['CONTENT_TYPE'] : '',
				'raw_length'   => strlen( $raw_data ),
				'post_keys'    => array_keys( (array) $_POST ),
			) );
			wp_send_json_success( array(
				'message'       => 'Webhook ping received',
				'empty_payload' => true,
			) );
			exit;
		}

		$this->log_zalohook_request( $data );

		// [2026-10-25 Johnny Chu - Chu Hoàng Anh] PHASE-0.126 R126-SEC C1 — the intake bot is the authenticated one (id + name only, never the token row).
		$intake_bot = null;
		if ( $this->auth_bot && (int) $this->auth_blog_id === (int) get_current_blog_id() ) {
			$intake_bot = (object) array(
				'id'       => $this->auth_bot->id,
				'bot_name' => isset( $this->auth_bot->bot_name ) ? $this->auth_bot->bot_name : '',
			);
		}

		// Fire intake hook (CG Debug Logger taps this for visibility before processing).
		do_action( 'bizcity_zalo_webhook_intake', $data, $secret_token, $intake_bot );

		// Check if any bot is listening
		$this->check_and_store_listener_data( $data, $secret_token );
		
		// Process the webhook data
		$this->process_zalohook_data( $data, $secret_token );

		// [2026-10-25 Johnny Chu - Chu Hoàng Anh] PHASE-0.126 R126-SEC H4 — keep the 30-day retention running (it only ran from the disabled legacy path).
		if ( wp_rand( 1, 100 ) === 1 ) {
			$this->cleanup_old_logs();
		}

		wp_send_json_success( array( 'message' => 'Webhook received' ) );
		exit;
	}
	
	/**
	 * Handle old encrypted zalohook endpoint (legacy)
	 *
	 * [2026-10-25 Johnny Chu - Chu Hoàng Anh] PHASE-0.126 R126-SEC C1 — disabled: it authenticated nothing (the secret check was commented out and
	 * the XOR key is derived from the public blog_id) and nothing routes to it. Kept as a 410 stub so a stray caller cannot inject events.
	 */
	private function handle_zalohook_legacy() {
		wp_send_json_error( array( 'message' => 'Legacy zalohook endpoint disabled' ), 410 );
		exit;
	}
	
	/**
	 * Process zalohook data (New Zalo Bot API format)
	 */
	private function process_zalohook_data( $data ) {
		global $wpdb;
		
		// Check if this is new Zalo Bot format
		$event_name = isset( $data['event_name'] ) ? $data['event_name'] : '';
		
		if ( ! empty( $event_name ) && isset( $data['message'] ) ) {
			// New Zalo Bot format (message.text.received, message.image.received, etc.)
			return $this->process_new_zalo_format( $data );
		}
		
		// Legacy encrypted format
		$platform_type = $data['platform_type'] ?? '';
		$event = $data['event'] ?? '';
		$client_id = $data['client_id'] ?? '';
		$page_id = $data['page_id'] ?? '';
		$conversation = $data['conversation'] ?? array();
		$message = $data['message'] ?? array();
		
		$this->log_zalohook_info( "Processing event: $event, platform: $platform_type, client: $client_id, page: $page_id" );
		
		// Only process message create events
		if ( $event !== 'message.create' ) {
			$this->log_zalohook_info( "Skipping non-message event: $event" );
			return false;
		}
		
		$message_type = $conversation['last_message_type'] ?? '';
		if ( $message_type !== 'client' ) {
			$this->log_zalohook_info( "Skipping non-client message type: $message_type" );
			return false;
		}
		
		$message_id = $message['message_id'] ?? '';
		if ( empty( $message_id ) ) {
			$this->log_zalohook_error( 'Empty message_id in webhook data' );
			return false;
		}
		
		// Prevent duplicate processing
		$lock_key = 'zalohook_lock_' . md5( $message_id . $client_id );
		if ( get_transient( $lock_key ) ) {
			$this->log_zalohook_info( "Duplicate message detected, skipping: $message_id" );
			return false;
		}
		set_transient( $lock_key, true, 300 ); // 5 minute lock
		
		// [2026-10-25 Johnny Chu - Chu Hoàng Anh] PHASE-0.126 R126-SEC C1 — the bot is the one whose secret authenticated the request;
		// payload page_id / "first active bot" can no longer pick it.
		$db  = BizCity_Zalo_Bot_Database::instance();
		$bot = $this->auth_bot;

		if ( ! $bot ) {
			$this->log_zalohook_error( "No authenticated bot for page_id: $page_id" );
			return false;
		}
		
		// Log the event (client_id, message_id, display_name = '', text = '')
		$db->log_event( $bot->id, $event, $data, $client_id, $message_id, '', '' );
		
		// Build message data for bizcity_zalo_message_received action
		$message_data = array(
			// [2026-06-07 Johnny Chu] PHASE-0.40 G0.3 R-ZONE-2 — explicit platform discriminator
			// Universal Listener bails on ZALO_BOT so admin commands stay in Zone 2.
			'platform'        => 'ZALO_BOT',
			'code'            => 'zalo_bot',
			'bot_id'          => $bot->id,
			'bot_name'        => $bot->bot_name,
			'account_id'      => $bot->id, // For compatibility with wu_zalo_message_received trigger
			'account_name'    => $bot->bot_name,
			'event_name'      => $event,
			'from_user_id'    => $client_id,
			'from_user_name'  => $data['client_name'] ?? '',
			'message_id'      => $message_id,
			'conversation_id' => $page_id,
			'message_type'    => $this->determine_message_type( $data ),
			'message_text'    => sanitize_text_field( $conversation['last_message'] ?? '' ),
			'message_time'    => current_time( 'mysql' ),
			'image_url'       => $this->extract_image_url( $data ),
			'file_url'        => $this->extract_file_url( $data ),
			'file_name'       => $this->extract_file_name( $data ),
			'raw'             => $data,
		);
		
		$this->log_zalohook_data( 'Built message data for action', $message_data );
		
		// Fire the action that wu_zalo_message_received trigger listens to
		$this->log_zalohook_info( 'Firing bizcity_zalo_message_received action' );
		do_action( 'bizcity_zalo_message_received', $message_data );
		
		return true;
	}
	
	/**
	 * Helper methods for logging
	 */
	
	/**
	 * Process new Zalo Bot API webhook format
	 */
	private function process_new_zalo_format( $data ) {
		global $wpdb;
		
		$event_name = isset( $data['event_name'] ) ? $data['event_name'] : '';
		$message = isset( $data['message'] ) ? $data['message'] : array();
		
		if ( empty( $event_name ) || empty( $message ) ) {
			$this->log_zalohook_error( 'Missing event_name or message', $data );
			return false;
		}
		
		// Extract common fields
		$user_id = isset( $message['from']['id'] ) ? $message['from']['id'] : '';
		$chat_id = isset( $message['chat']['id'] ) ? $message['chat']['id'] : '';
		$message_id = isset( $message['message_id'] ) ? $message['message_id'] : '';
		$display_name = isset( $message['from']['display_name'] ) ? $message['from']['display_name'] : '';

		// [2026-07-17 Johnny Chu] PHASE-TWINWEB F4 — fail closed: inbound without sender identity must not continue into automation owner chain.
		if ( $user_id === '' ) {
			$this->log_zalohook_error( 'Missing message.from.id in webhook payload', $data );
			return false;
		}
		
		// [2026-10-25 Johnny Chu - Chu Hoàng Anh] PHASE-0.126 R126-SEC C1 — bot + source blog come from authenticate_zalohook() (stored secret,
		// hash_equals, multisite scan); the "first active bot in current blog" fallback is removed.
		$bot            = $this->auth_bot;
		$source_blog_id = $this->auth_blog_id > 0 ? (int) $this->auth_blog_id : get_current_blog_id();

		if ( ! $bot ) {
			$this->log_zalohook_error( 'No authenticated bot' );
			return false;
		}
		
		// Prevent duplicate processing
		$lock_key = 'zalobot_lock_' . md5( $message_id . $user_id );
		if ( get_transient( $lock_key ) ) {
			$this->log_zalohook_info( "Duplicate message detected, skipping: $message_id" );
			return false;
		}
		set_transient( $lock_key, true, 300 ); // 5 minute lock
		
		// Extract text from message
		$text = isset( $message['text'] ) ? $message['text'] : '';
		
		// Use user_id as client_id (canonical identifier)
		$client_id = $user_id;
		// [2026-09-01 Johnny Chu] PHASE-0.45-W3 — capture group intake before any database call, with hashes only.
		$provider_chat_id     = $chat_id !== '' ? (string) $chat_id : (string) $user_id;
		$provider_chat_type   = isset( $message['chat']['chat_type'] ) ? strtoupper( (string) $message['chat']['chat_type'] ) : 'PRIVATE';
		$chat_kind            = $provider_chat_type === 'GROUP' ? 'group' : 'private';
		$conversation_chat_id = 'zalobot_' . $bot->id . '_' . ( $chat_kind === 'group' ? 'group_' : 'private_' ) . $provider_chat_id;
		if ( $chat_kind === 'group' ) {
			$this->log_group_channel_event( 'group_message_received', $bot->id, $provider_chat_id, $conversation_chat_id, $message_id, $event_name );
		}
		
		// Log the event with all fields
		$db = BizCity_Zalo_Bot_Database::instance();
		$db->log_event( $bot->id, $event_name, $data, $client_id, $message_id, $display_name, $text );
		
		// Check if listener is active and store webhook data
		$listening = get_transient( 'zalobot_listening_' . $bot->id );
		if ( $listening ) {
			set_transient( 'zalobot_webhook_data_' . $bot->id, $data, 300 );
			$this->log_zalohook_info( 'Stored webhook data for listener' );
		}
		
		// Store source_blog_id for this bot (used by gateway when sending messages)
		set_transient( 'zalobot_source_blog_' . $bot->id, $source_blog_id, 3600 ); // Cache for 1 hour
		$this->log_zalohook_info( "Cached source_blog_id={$source_blog_id} for bot #{$bot->id}" );
		
		// Prepare trigger data for workflow automation
		// [2026-07-21 Johnny Chu] PHASE-ZALOBOT-GROUP W6 — route replies by conversation target, not sender identity.
		$identity_user_id     = (string) $user_id;
		$bot_chat_id          = $conversation_chat_id;
		$client_id            = $conversation_chat_id;
		$platform             = 'zalo_bot';
		
		// ── Resolve WordPress user_id: per-user link (Linker) → bot assignment fallback ──
		// PHASE-0-RULE-CHANNEL-UNIFY (2026-05-30) — adapter KHÔNG được auto-send
		// reply / login link trước khi fire envelope. CTA login (nếu cần) phải
		// đặt vào MỘT workflow chuyên biệt với keyword `login`/`đăng nhập`/`bind`.
		$wp_user_id = 0;
		if ( class_exists( 'BizCity_Zalobot_User_Linker' ) && $bot && ! empty( $user_id ) ) {
			$wp_user_id = BizCity_Zalobot_User_Linker::resolve_wp_user( $user_id, (int) $bot->id );
			// (đã bỏ) maybe_send_login_link — vi phạm R-CH-UNI 1.1.
		} elseif ( function_exists( 'bizcity_zalobot_resolve_wp_user' ) && $bot ) {
			// Legacy fallback: resolve via bot assignment (bot owner)
			$wp_user_id = bizcity_zalobot_resolve_wp_user( $bot->id );
		}
		
		// Process based on event type
		switch ( $event_name ) {
			case 'message.text.received':
				$text = isset( $message['text'] ) ? $message['text'] : '';
				$mention_detected     = $this->zalobot_text_mentions_bot( $text, $bot );
				$reply_to_bot_message = $this->zalobot_message_replies_to_bot( $message );
				$message_text_clean   = $mention_detected ? $this->zalobot_strip_bot_mention( $text, $bot ) : $text;
				if ( $chat_kind === 'group' && $mention_detected ) {
					$this->log_group_channel_event( 'group_mention_matched', $bot->id, $provider_chat_id, $conversation_chat_id, $message_id, $event_name, true );
				}
				
				$trigger = array(
					'platform'        => $platform,
					'client_id'       => $client_id,
					'chat_id'         => $bot_chat_id,
					'conversation_chat_id' => $conversation_chat_id,
					'provider_chat_id' => $provider_chat_id,
					'provider_chat_type' => $provider_chat_type,
					'chat_kind'       => $chat_kind,
					'sender_user_id'  => $identity_user_id,
					'user_id'         => $user_id,
					'wp_user_id'      => $wp_user_id,
					'message_id'      => $message_id,
					'text'            => $message_text_clean,
					'raw_text'        => $text,
					'message_text_clean' => $message_text_clean,
					'mention_detected' => $mention_detected,
					'reply_to_bot_message' => $reply_to_bot_message,
					'display_name'    => $display_name,
					'attachment_type'  => 'text',
					'attachment_url'   => '',
					'bot_id'          => $bot ? $bot->id : '',
					'bot_name'        => $bot ? $bot->bot_name : '',
					'source_blog_id'  => $source_blog_id,
					'raw'             => $data,
					// Backward-compat: twf_ prefix fields required by workflow actions
					'twf_platform'    => $platform,
					'twf_client_id'   => $client_id,
					'twf_chat_id'     => $bot_chat_id,
					'twf_text'        => $message_text_clean,
					'twf_image_url'   => '',
					'twf_file_url'    => '',
				);
				
				// [2026-07-21 Johnny Chu] PHASE-TWINWEB W3 — new Bot Platform payloads must also fire the canonical ZaloBot action so command router /link can bind users without duplicating the workflow path.
				do_action( 'bizcity_zalo_message_received', array_merge( $trigger, array(
					'platform'       => 'ZALO_BOT',
					'code'           => 'zalo_bot',
					'account_id'     => (string) ( $bot ? $bot->id : '' ),
					'account_name'   => $bot ? (string) $bot->bot_name : '',
					'from_user_id'   => $identity_user_id,
					'from_user_name' => $display_name,
					'conversation_id'=> (string) ( $bot ? $bot->id : '' ),
					'message_text'   => $message_text_clean,
				) ) );
				
				// Fire workflow trigger (prefer gateway if available)
				// [2026-08-09 Johnny Chu] R-CH-UNI — raw fallback dispatch is no longer needed; the canonical Zone 2 envelope and automation intake already handled this event.
				if ( function_exists( 'bizcity_gateway_fire_trigger' ) ) {
					bizcity_gateway_fire_trigger( $trigger, $data );
				}
				
				$this->log_zalohook_info( 'Text message processed', array(
					'user_id'  => $user_id,
					'chat_id'  => $bot_chat_id,
					'conversation_chat_id' => $conversation_chat_id,
					'provider_chat_id' => $provider_chat_id,
					'chat_kind' => $chat_kind,
					'text'     => $text,
				) );
				break;
				
			case 'message.image.received':
				$photo_url = isset( $message['photo_url'] ) ? $message['photo_url'] : '';
				$caption = isset( $message['caption'] ) ? $message['caption'] : '';
				$mention_detected     = $this->zalobot_text_mentions_bot( $caption, $bot );
				$reply_to_bot_message = $this->zalobot_message_replies_to_bot( $message );
				$message_text_clean   = $mention_detected ? $this->zalobot_strip_bot_mention( $caption, $bot ) : $caption;
				if ( $chat_kind === 'group' && $mention_detected ) {
					$this->log_group_channel_event( 'group_mention_matched', $bot->id, $provider_chat_id, $conversation_chat_id, $message_id, $event_name, true );
				}
				
				$trigger = array(
					'platform'        => $platform,
					'client_id'       => $client_id,
					'chat_id'         => $bot_chat_id,
					'conversation_chat_id' => $conversation_chat_id,
					'provider_chat_id' => $provider_chat_id,
					'provider_chat_type' => $provider_chat_type,
					'chat_kind'       => $chat_kind,
					'sender_user_id'  => $identity_user_id,
					'user_id'         => $user_id,
					'wp_user_id'      => $wp_user_id,
					'message_id'      => $message_id,
					'text'            => $message_text_clean,
					'raw_text'        => $caption,
					'message_text_clean' => $message_text_clean,
					'mention_detected' => $mention_detected,
					'reply_to_bot_message' => $reply_to_bot_message,
					'display_name'    => $display_name,
					'attachment_type'  => 'image',
					'attachment_url'   => $photo_url,
					'image_url'        => $photo_url,
					'bot_id'          => $bot ? $bot->id : '',
					'bot_name'        => $bot ? $bot->bot_name : '',
					'source_blog_id'  => $source_blog_id,
					'raw'             => $data,
					// Backward-compat: twf_ prefix fields required by workflow actions
					'twf_platform'    => $platform,
					'twf_client_id'   => $client_id,
					'twf_chat_id'     => $bot_chat_id,
					'twf_text'        => $message_text_clean,
					'twf_image_url'   => $photo_url,
					'twf_file_url'    => '',
				);
				
				// [2026-07-21 Johnny Chu] PHASE-TWINWEB W3 — keep image/caption payloads on the same canonical ZaloBot listener bus as text messages without duplicating the workflow path.
				do_action( 'bizcity_zalo_message_received', array_merge( $trigger, array(
					'platform'       => 'ZALO_BOT',
					'code'           => 'zalo_bot',
					'account_id'     => (string) ( $bot ? $bot->id : '' ),
					'account_name'   => $bot ? (string) $bot->bot_name : '',
					'from_user_id'   => $identity_user_id,
					'from_user_name' => $display_name,
					'conversation_id'=> (string) ( $bot ? $bot->id : '' ),
					'message_text'   => $message_text_clean,
				) ) );
				
				// Fire workflow trigger (prefer gateway if available)
				// [2026-08-19 Johnny Chu] R-CH-UNI - canonical gateway dispatch is the only active workflow path.
				if ( function_exists( 'bizcity_gateway_fire_trigger' ) ) {
					bizcity_gateway_fire_trigger( $trigger, $data );
				}
				
				$this->log_zalohook_info( 'Image message processed', array(
					'user_id'   => $user_id,
					'chat_id'   => $bot_chat_id,
					'conversation_chat_id' => $conversation_chat_id,
					'provider_chat_id' => $provider_chat_id,
					'chat_kind' => $chat_kind,
					'photo_url' => $photo_url,
				) );
				break;
				
			// [2026-07-24 Johnny Chu] PHASE-0.46 W1 — HOTFIX: message.file.received
			// previously fell through to `default:` ("Unknown event type") and
			// produced ZERO downstream action — uploaded PDF/Word/Excel/MD files
			// never reached automation, command router, or Guru AI. Mirrors the
			// message.image.received case above. Field names (file_url/file_name)
			// are inferred from the existing (previously-unused) extract_file_url()/
			// extract_file_name() helper naming and the photo_url convention used
			// by message.image.received; confirm against a live payload sample and
			// adjust the isset() fallbacks below if the real Bot API uses different
			// keys (e.g. document_url/document_name).
			case 'message.file.received':
				$file_url  = isset( $message['file_url'] ) ? $message['file_url'] : ( isset( $message['document_url'] ) ? $message['document_url'] : '' );
				$file_name = isset( $message['file_name'] ) ? $message['file_name'] : ( isset( $message['document_name'] ) ? $message['document_name'] : '' );
				$caption   = isset( $message['caption'] ) ? $message['caption'] : '';
				$mention_detected     = $this->zalobot_text_mentions_bot( $caption, $bot );
				$reply_to_bot_message = $this->zalobot_message_replies_to_bot( $message );
				$message_text_clean   = $mention_detected ? $this->zalobot_strip_bot_mention( $caption, $bot ) : $caption;
				if ( $chat_kind === 'group' && $mention_detected ) {
					$this->log_group_channel_event( 'group_mention_matched', $bot->id, $provider_chat_id, $conversation_chat_id, $message_id, $event_name, true );
				}
				
				$trigger = array(
					'platform'        => $platform,
					'client_id'       => $client_id,
					'chat_id'         => $bot_chat_id,
					'conversation_chat_id' => $conversation_chat_id,
					'provider_chat_id' => $provider_chat_id,
					'provider_chat_type' => $provider_chat_type,
					'chat_kind'       => $chat_kind,
					'sender_user_id'  => $identity_user_id,
					'user_id'         => $user_id,
					'wp_user_id'      => $wp_user_id,
					'message_id'      => $message_id,
					'text'            => $message_text_clean,
					'raw_text'        => $caption,
					'message_text_clean' => $message_text_clean,
					'mention_detected' => $mention_detected,
					'reply_to_bot_message' => $reply_to_bot_message,
					'display_name'    => $display_name,
					'attachment_type'  => 'file',
					'attachment_url'   => $file_url,
					'file_url'         => $file_url,
					'file_name'        => $file_name,
					'bot_id'          => $bot ? $bot->id : '',
					'bot_name'        => $bot ? $bot->bot_name : '',
					'source_blog_id'  => $source_blog_id,
					'raw'             => $data,
					// Backward-compat: twf_ prefix fields required by workflow actions
					'twf_platform'    => $platform,
					'twf_client_id'   => $client_id,
					'twf_chat_id'     => $bot_chat_id,
					'twf_text'        => $message_text_clean,
					'twf_image_url'   => '',
					'twf_file_url'    => $file_url,
				);
				
				do_action( 'bizcity_zalo_message_received', array_merge( $trigger, array(
					'platform'       => 'ZALO_BOT',
					'code'           => 'zalo_bot',
					'account_id'     => (string) ( $bot ? $bot->id : '' ),
					'account_name'   => $bot ? (string) $bot->bot_name : '',
					'from_user_id'   => $identity_user_id,
					'from_user_name' => $display_name,
					'conversation_id'=> (string) ( $bot ? $bot->id : '' ),
					'message_text'   => $message_text_clean,
				) ) );
				
				// Fire workflow trigger (prefer gateway if available)
				if ( function_exists( 'bizcity_gateway_fire_trigger' ) ) {
					bizcity_gateway_fire_trigger( $trigger, $data );
				}
				
				$this->log_zalohook_info( 'File message processed', array(
					'user_id'   => $user_id,
					'chat_id'   => $bot_chat_id,
					'conversation_chat_id' => $conversation_chat_id,
					'provider_chat_id' => $provider_chat_id,
					'chat_kind' => $chat_kind,
					'file_url'  => $file_url,
					'file_name' => $file_name,
				) );
				break;
				
			// [2026-07-24 Johnny Chu] PHASE-0.46 W4 S4.1 — real payload confirmed
			// from live bizcity-channel-logs/zalo_bot/2026-07-24.jsonl (log_row_id=24):
			//   { event_name:"message.voice.received", message:{ voice_url, chat:{chat_type,id},
			//     message_id, message_type:"CHAT_VOICE", from:{id,is_bot,display_name} } }
			// NOTE: unlike message.image.received, there is NO caption field on voice
			// messages — confirmed absent in the live payload. Do not invent one.
			case 'message.voice.received':
				$voice_url = isset( $message['voice_url'] ) ? $message['voice_url'] : '';
				
				$trigger = array(
					'platform'        => $platform,
					'client_id'       => $client_id,
					'chat_id'         => $bot_chat_id,
					'conversation_chat_id' => $conversation_chat_id,
					'provider_chat_id' => $provider_chat_id,
					'provider_chat_type' => $provider_chat_type,
					'chat_kind'       => $chat_kind,
					'sender_user_id'  => $identity_user_id,
					'user_id'         => $user_id,
					'wp_user_id'      => $wp_user_id,
					'message_id'      => $message_id,
					'text'            => '',
					'raw_text'        => '',
					'message_text_clean' => '',
					'mention_detected' => false,
					'reply_to_bot_message' => false,
					'display_name'    => $display_name,
					'attachment_type'  => 'audio',
					'attachment_url'   => $voice_url,
					'voice_url'        => $voice_url,
					'bot_id'          => $bot ? $bot->id : '',
					'bot_name'        => $bot ? $bot->bot_name : '',
					'source_blog_id'  => $source_blog_id,
					'raw'             => $data,
					// Backward-compat: twf_ prefix fields required by workflow actions
					'twf_platform'    => $platform,
					'twf_client_id'   => $client_id,
					'twf_chat_id'     => $bot_chat_id,
					'twf_text'        => '',
					'twf_image_url'   => '',
					'twf_file_url'    => '',
				);
				
				do_action( 'bizcity_zalo_message_received', array_merge( $trigger, array(
					'platform'       => 'ZALO_BOT',
					'code'           => 'zalo_bot',
					'account_id'     => (string) ( $bot ? $bot->id : '' ),
					'account_name'   => $bot ? (string) $bot->bot_name : '',
					'from_user_id'   => $identity_user_id,
					'from_user_name' => $display_name,
					'conversation_id'=> (string) ( $bot ? $bot->id : '' ),
					'message_text'   => '',
				) ) );
				
				// Fire workflow trigger (prefer gateway if available)
				if ( function_exists( 'bizcity_gateway_fire_trigger' ) ) {
					bizcity_gateway_fire_trigger( $trigger, $data );
				}
				
				$this->log_zalohook_info( 'Voice message processed', array(
					'user_id'   => $user_id,
					'chat_id'   => $bot_chat_id,
					'conversation_chat_id' => $conversation_chat_id,
					'provider_chat_id' => $provider_chat_id,
					'chat_kind' => $chat_kind,
					'voice_url' => $voice_url,
				) );
				break;
				
			default:
				// [2026-07-25 Johnny Chu] PHASE-0.46 W4.6 — Bot Platform can emit
				// `message.unsupported.received` for new/rolling attachment shapes.
				// Fail-open by mapping any detectable media URL into the canonical
				// trigger envelope so pending media queues + @ghichu capture still work.
				$fallback = $this->build_unknown_message_trigger_payload(
					$message,
					$event_name,
					$platform,
					$client_id,
					$bot_chat_id,
					$conversation_chat_id,
					$provider_chat_id,
					$provider_chat_type,
					$chat_kind,
					$identity_user_id,
					$user_id,
					$wp_user_id,
					$message_id,
					$display_name,
					$bot,
					$source_blog_id,
					$data
				);
				if ( is_array( $fallback ) && ! empty( $fallback['attachment_url'] ) ) {
					do_action( 'bizcity_zalo_message_received', array_merge( $fallback, array(
						'platform'       => 'ZALO_BOT',
						'code'           => 'zalo_bot',
						'account_id'     => (string) ( $bot ? $bot->id : '' ),
						'account_name'   => $bot ? (string) $bot->bot_name : '',
						'from_user_id'   => $identity_user_id,
						'from_user_name' => $display_name,
						'conversation_id'=> (string) ( $bot ? $bot->id : '' ),
						'message_text'   => (string) ( $fallback['message_text_clean'] ?? '' ),
					) ) );

					if ( function_exists( 'bizcity_gateway_fire_trigger' ) ) {
						bizcity_gateway_fire_trigger( $fallback, $data );
					}

					$this->log_zalohook_info( 'Unknown message event fallback processed', array(
						'event_name'      => $event_name,
						'attachment_type' => (string) ( $fallback['attachment_type'] ?? '' ),
						'attachment_url'  => (string) ( $fallback['attachment_url'] ?? '' ),
						'chat_id'         => $bot_chat_id,
						'provider_chat_id'=> $provider_chat_id,
						'message_id'      => $message_id,
					) );
					break;
				}

				// [2026-07-26 Johnny Chu] PHASE-0.46 W5 HOTFIX — when Zalo emits
				// `message.unsupported.received` WITHOUT any media URL (common for
				// desktop/rolling file payloads), still emit a canonical internal
				// event so the notebook listener can proactively reply "chưa lấy
				// được file" instead of failing silently.
				if ( $event_name === 'message.unsupported.received' ) {
					$unsupported = array(
						'platform'        => $platform,
						'client_id'       => $client_id,
						'chat_id'         => $bot_chat_id,
						'conversation_chat_id' => $conversation_chat_id,
						'provider_chat_id' => $provider_chat_id,
						'provider_chat_type' => $provider_chat_type,
						'chat_kind'       => $chat_kind,
						'sender_user_id'  => $identity_user_id,
						'user_id'         => $user_id,
						'wp_user_id'      => $wp_user_id,
						'message_id'      => $message_id,
						'text'            => '',
						'raw_text'        => '',
						'message_text_clean' => '',
						'mention_detected' => false,
						'reply_to_bot_message' => false,
						'display_name'    => $display_name,
						'attachment_type'  => 'unsupported',
						'attachment_url'   => '',
						'unsupported_event' => $event_name,
						'bot_id'          => $bot ? $bot->id : '',
						'bot_name'        => $bot ? $bot->bot_name : '',
						'source_blog_id'  => $source_blog_id,
						'raw'             => $data,
						'twf_platform'    => $platform,
						'twf_client_id'   => $client_id,
						'twf_chat_id'     => $bot_chat_id,
						'twf_text'        => '',
						'twf_image_url'   => '',
						'twf_file_url'    => '',
					);

					do_action( 'bizcity_zalo_message_received', array_merge( $unsupported, array(
						'platform'       => 'ZALO_BOT',
						'code'           => 'zalo_bot',
						'account_id'     => (string) ( $bot ? $bot->id : '' ),
						'account_name'   => $bot ? (string) $bot->bot_name : '',
						'from_user_id'   => $identity_user_id,
						'from_user_name' => $display_name,
						'conversation_id'=> (string) ( $bot ? $bot->id : '' ),
						'message_text'   => '',
					) ) );

					// [2026-07-26 Johnny Chu] PHASE-0.46 W5 HOTFIX — fail-open UX:
					// if listener chain is skipped for any reason, still send one
					// direct guidance reply for unsupported file payloads.
					$this->maybe_send_unsupported_file_guidance( $bot, $provider_chat_id, $message_id );

					$this->log_zalohook_info( 'Unsupported message emitted to internal listener bus', array(
						'event_name'       => $event_name,
						'chat_id'          => $bot_chat_id,
						'provider_chat_id' => $provider_chat_id,
						'message_id'       => $message_id,
					) );
					break;
				}

				$this->log_zalohook_info( 'Unknown event type: ' . $event_name, $data );
				break;
		}
		
		// Fire generic action
		do_action( 'bizcity_zalo_bot_webhook_event', $bot, $event_name, $data );
		
		return true;
	}

	/**
	 * [2026-07-25 Johnny Chu] PHASE-0.46 W4.6 — normalize unknown Bot Platform
	 * message events that still carry media URLs (e.g. message.unsupported.received)
	 * into the canonical trigger envelope so pending-media and notebook capture
	 * pipelines keep working.
	 */
	private function build_unknown_message_trigger_payload(
		array $message,
		$event_name,
		$platform,
		$client_id,
		$bot_chat_id,
		$conversation_chat_id,
		$provider_chat_id,
		$provider_chat_type,
		$chat_kind,
		$identity_user_id,
		$user_id,
		$wp_user_id,
		$message_id,
		$display_name,
		$bot,
		$source_blog_id,
		array $raw_data
	) {
		$attachment_type = '';
		$attachment_url  = '';
		$file_name       = '';
		$voice_url       = '';

		if ( ! empty( $message['photo_url'] ) ) {
			$attachment_type = 'image';
			$attachment_url  = (string) $message['photo_url'];
		} elseif ( ! empty( $message['file_url'] ) || ! empty( $message['document_url'] ) ) {
			$attachment_type = 'file';
			$attachment_url  = (string) ( $message['file_url'] ?? $message['document_url'] );
			$file_name       = sanitize_file_name( (string) ( $message['file_name'] ?? $message['document_name'] ?? '' ) );
		} elseif ( ! empty( $message['voice_url'] ) ) {
			$attachment_type = 'audio';
			$attachment_url  = (string) $message['voice_url'];
			$voice_url       = (string) $message['voice_url'];
		} else {
			// Legacy OA-style payload fallback (`message_attachments[].payload.url`).
			$image_fallback = (string) $this->extract_image_url( $raw_data );
			$file_fallback  = (string) $this->extract_file_url( $raw_data );
			if ( $image_fallback !== '' ) {
				$attachment_type = 'image';
				$attachment_url  = $image_fallback;
			} elseif ( $file_fallback !== '' ) {
				$attachment_type = 'file';
				$attachment_url  = $file_fallback;
				$file_name       = sanitize_file_name( (string) $this->extract_file_name( $raw_data ) );
			}
		}

		$attachment_url = trim( $attachment_url );
		if ( $attachment_url === '' ) {
			return array();
		}

		$text                = (string) ( $message['caption'] ?? $message['text'] ?? '' );
		$mention_detected    = $this->zalobot_text_mentions_bot( $text, $bot );
		$reply_to_bot_message = $this->zalobot_message_replies_to_bot( $message );
		$message_text_clean  = $mention_detected ? $this->zalobot_strip_bot_mention( $text, $bot ) : $text;

		$payload = array(
			'platform'            => $platform,
			'client_id'           => $client_id,
			'chat_id'             => $bot_chat_id,
			'conversation_chat_id'=> $conversation_chat_id,
			'provider_chat_id'    => $provider_chat_id,
			'provider_chat_type'  => $provider_chat_type,
			'chat_kind'           => $chat_kind,
			'sender_user_id'      => $identity_user_id,
			'user_id'             => $user_id,
			'wp_user_id'          => $wp_user_id,
			'message_id'          => $message_id,
			'text'                => $message_text_clean,
			'raw_text'            => $text,
			'message_text_clean'  => $message_text_clean,
			'mention_detected'    => $mention_detected,
			'reply_to_bot_message'=> $reply_to_bot_message,
			'display_name'        => $display_name,
			'attachment_type'     => $attachment_type,
			'attachment_url'      => $attachment_url,
			'bot_id'              => $bot ? $bot->id : '',
			'bot_name'            => $bot ? $bot->bot_name : '',
			'source_blog_id'      => $source_blog_id,
			'raw'                 => $raw_data,
			'twf_platform'        => $platform,
			'twf_client_id'       => $client_id,
			'twf_chat_id'         => $bot_chat_id,
			'twf_text'            => $message_text_clean,
			'twf_image_url'       => $attachment_type === 'image' ? $attachment_url : '',
			'twf_file_url'        => $attachment_type === 'file' ? $attachment_url : '',
			'event_name'          => (string) $event_name,
		);

		if ( $attachment_type === 'image' ) {
			$payload['image_url'] = $attachment_url;
		}
		if ( $attachment_type === 'file' ) {
			$payload['file_url']  = $attachment_url;
			$payload['file_name'] = $file_name;
		}
		if ( $attachment_type === 'audio' ) {
			$payload['voice_url'] = $voice_url !== '' ? $voice_url : $attachment_url;
		}

		return $payload;
	}
	
	private function log_zalohook_request( $data ) {
		// [2026-10-25 Johnny Chu - Chu Hoàng Anh] PHASE-0.126 R126-SEC H4 — metadata only (event, ids, sizes); never the body / text / attachment URLs.
		$message = is_array( $data ) && isset( $data['message'] ) && is_array( $data['message'] ) ? $data['message'] : array();
		$this->write_zalohook_log( 'request', array(
			'bot_id'      => $this->auth_bot ? (int) $this->auth_bot->id : 0,
			'event_name'  => is_array( $data ) && isset( $data['event_name'] ) ? (string) $data['event_name'] : '',
			'message_id'  => isset( $message['message_id'] ) ? (string) $message['message_id'] : '',
			'from_id'     => isset( $message['from']['id'] ) ? (string) $message['from']['id'] : '',
			'chat_id'     => isset( $message['chat']['id'] ) ? (string) $message['chat']['id'] : '',
			'chat_type'   => isset( $message['chat']['chat_type'] ) ? (string) $message['chat']['chat_type'] : '',
			'top_keys'    => is_array( $data ) ? array_slice( array_map( 'strval', array_keys( $data ) ), 0, 20 ) : array(),
			'message_len' => isset( $message['text'] ) ? strlen( (string) $message['text'] ) : 0,
			'raw_length'  => strlen( $this->get_cached_raw_input() ),
		) );
	}
	
	private function log_zalohook_error( $message, $data = null ) {
		$log_data = array( 'message' => $message );
		if ( $data !== null ) {
			$log_data['data'] = $data;
		}
		$this->write_zalohook_log( 'error', $log_data );
	}
	
	private function log_zalohook_info( $message, $data = null ) {
		$log_data = array( 'message' => $message );
		if ( $data !== null ) {
			$log_data['data'] = $data;
		}
		$this->write_zalohook_log( 'info', $log_data );
	}
	
	private function log_zalohook_data( $message, $data ) {
		$log_data = array( 
			'message' => $message,
			'data' => $data 
		);
		$this->write_zalohook_log( 'data', $log_data );
	}

	private function log_group_channel_event( $event_name, $bot_id, $provider_chat_id, $conversation_chat_id, $message_id, $source_event = '', $mention_detected = null ) {
		// [2026-09-01 Johnny Chu] PHASE-0.45-W3 — use the canonical channel logger and never persist raw group/user identifiers.
		if ( ! class_exists( 'BizCity_Channel_File_Logger' ) || (string) $provider_chat_id === '' ) {
			return;
		}
		$context = array(
			'bot_id'                    => (int) $bot_id,
			'chat_kind'                 => 'group',
			'provider_chat_id_hash'     => substr( hash( 'sha256', (string) $provider_chat_id ), 0, 16 ),
			'conversation_chat_id_hash' => substr( hash( 'sha256', (string) $conversation_chat_id ), 0, 16 ),
			'message_id_hash'           => (string) $message_id !== '' ? substr( hash( 'sha256', (string) $message_id ), 0, 16 ) : '',
		);
		if ( (string) $source_event !== '' ) {
			$context['source_event'] = sanitize_key( (string) $source_event );
		}
		if ( $mention_detected !== null ) {
			$context['mention_detected'] = (bool) $mention_detected;
		}
		BizCity_Channel_File_Logger::write(
			BizCity_Channel_File_Logger::CH_ZALO_BOT,
			BizCity_Channel_File_Logger::LEVEL_INFO,
			(string) $event_name,
			'Zalo Bot group channel lifecycle event',
			$context
		);
	}

	/**
	 * [2026-07-26 Johnny Chu] PHASE-0.46 W5 HOTFIX — direct webhook-level
	 * fallback reply for unsupported payloads with no media URL.
	 */
	private function maybe_send_unsupported_file_guidance( $bot, $provider_chat_id, $message_id ) {
		$bot_id   = is_object( $bot ) && isset( $bot->id ) ? (int) $bot->id : 0;
		$chat_id  = (string) $provider_chat_id;
		$msg_id   = (string) $message_id;

		if ( $bot_id <= 0 || $chat_id === '' || $msg_id === '' ) {
			return;
		}

		// Shared key with notebook listener to prevent duplicate notices.
		$key = 'bizcity_nb_unsupported_notice_' . md5( $bot_id . '|' . $msg_id );
		if ( get_transient( $key ) ) {
			return;
		}
		set_transient( $key, 1, DAY_IN_SECONDS );

		if ( ! function_exists( 'bizcity_get_zalo_bot_api' ) ) {
			$this->log_zalohook_info( 'Unsupported guidance skipped: API factory missing', array(
				'bot_id'      => $bot_id,
				'chat_id'     => $chat_id,
				'message_id'  => $msg_id,
			) );
			return;
		}

		$api = bizcity_get_zalo_bot_api( $bot_id );
		if ( ! $api || ! method_exists( $api, 'send_message' ) ) {
			$this->log_zalohook_info( 'Unsupported guidance skipped: API unavailable', array(
				'bot_id'      => $bot_id,
				'chat_id'     => $chat_id,
				'message_id'  => $msg_id,
			) );
			return;
		}

		$result = $api->send_message(
			$chat_id,
			"⚠️ Em nhận được file nhưng Zalo chưa cung cấp link tải hợp lệ (message.unsupported.received), nên chưa thể lưu vào hàng đợi học.\nSếp thử gửi lại dưới dạng ảnh/PDF/ghi âm hoặc gửi lại file trực tiếp từ điện thoại, rồi nhắn @ghichu để em lưu tiếp nhé."
		);
		if ( is_wp_error( $result ) ) {
			$this->log_zalohook_error( 'Unsupported guidance send failed', array(
				'bot_id'      => $bot_id,
				'chat_id'     => $chat_id,
				'message_id'  => $msg_id,
				'error_code'  => $result->get_error_code(),
				'error_msg'   => $result->get_error_message(),
			) );
			if ( class_exists( 'BizCity_Zalo_Bot_Database' ) ) {
				$db = BizCity_Zalo_Bot_Database::instance();
				$db->log_event( $bot_id, 'unsupported.guidance.failed', array(
					'chat_id'    => $chat_id,
					'message_id' => $msg_id,
					'error_code' => $result->get_error_code(),
					'error_msg'  => $result->get_error_message(),
				), $chat_id, $msg_id, '', '' );
			}
			return;
		}

		$this->log_zalohook_info( 'Unsupported guidance sent to user', array(
			'bot_id'      => $bot_id,
			'chat_id'     => $chat_id,
			'message_id'  => $msg_id,
		) );
		if ( class_exists( 'BizCity_Zalo_Bot_Database' ) ) {
			$db = BizCity_Zalo_Bot_Database::instance();
			$db->log_event( $bot_id, 'unsupported.guidance.sent', array(
				'chat_id'    => $chat_id,
				'message_id' => $msg_id,
			), $chat_id, $msg_id, '', '' );
		}
	}
	
	private function log_zalohook_response( $response_data, $http_status = 200 ) {
		$log_data = array(
			'status' => $http_status,
			'response' => $response_data,
			'timestamp' => gmdate( 'c' )
		);
		$this->write_zalohook_log( 'response', $log_data );
	}
	
	private function write_zalohook_log( $type, $data ) {
		// [2026-10-25 Johnny Chu - Chu Hoàng Anh] PHASE-0.126 R126-SEC H4 — protected dir (deny .htaccess + index.php), unguessable
		// file name (salted suffix) and redacted rows: no message text, names, attachment URLs, tokens or raw payloads.
		$log_dir = self::protected_log_dir();
		if ( $log_dir === '' ) {
			return;
		}

		$date_str = gmdate( 'Y-m-d' );
		$time_str = gmdate( 'H:i:s' );
		$blog_id = get_current_blog_id();

		// Single log file for all zalohook events
		$log_file = $log_dir . '/zalohook-' . $date_str . '-' . self::log_file_suffix() . '.log';

		$safe = self::redact_log_value( $data );
		if ( is_array( $data ) && isset( $data['message'] ) && is_string( $data['message'] ) && is_array( $safe ) ) {
			$safe['message'] = $data['message']; // top-level 'message' is our own log label, not user content
		}
		$log_entry = array(
			'time' => $time_str,
			'blog_id' => $blog_id,
			'type' => $type,
			'data' => $safe,
		);

		@file_put_contents(
			$log_file,
			json_encode( $log_entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "\n",
			FILE_APPEND | LOCK_EX
		);
	}

	/**
	 * [2026-10-25 Johnny Chu - Chu Hoàng Anh] PHASE-0.126 R126-SEC H4 — webhook log dir with web access denied ('' when unusable).
	 */
	private static function protected_log_dir() {
		$dir = WP_CONTENT_DIR . '/mu-plugins/logs';
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return '';
		}
		if ( ! file_exists( $dir . '/.htaccess' ) ) {
			@file_put_contents( $dir . '/.htaccess', "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n" );
		}
		if ( ! file_exists( $dir . '/index.php' ) ) {
			@file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" );
		}
		return $dir;
	}

	/** [2026-10-25 Johnny Chu - Chu Hoàng Anh] PHASE-0.126 R126-SEC H4 — salted, stable per install: log URLs can't be guessed (nginx ignores .htaccess). */
	private static function log_file_suffix() {
		$seed = 'bizcity-webhook-logs';
		$hash = function_exists( 'wp_hash' ) ? wp_hash( $seed ) : md5( $seed . ( defined( 'AUTH_SALT' ) ? AUTH_SALT : ABSPATH ) );
		return substr( preg_replace( '/[^a-z0-9]/i', '', (string) $hash ), 0, 12 );
	}

	/**
	 * [2026-10-25 Johnny Chu - Chu Hoàng Anh] PHASE-0.126 R126-SEC H4 — recursive redaction for webhook logs: ids/sizes/event types stay,
	 * user content (text, captions, names, refs), attachment URLs, tokens/secrets and raw payload copies are replaced by a length marker.
	 */
	private static function redact_log_value( $value, $depth = 0 ) {
		if ( is_object( $value ) ) {
			$value = get_object_vars( $value );
		}
		if ( ! is_array( $value ) ) {
			return $value;
		}
		if ( $depth > 6 ) {
			return '[depth]';
		}
		$content = array( 'text', 'raw_text', 'message_text', 'message_text_clean', 'twf_text', 'caption', 'display_name', 'from_name', 'from_user_name', 'client_name', 'name', 'last_message', 'ref', 'decrypted', 'email', 'phone', 'message' );
		$drop    = array( 'raw', 'raw_json', 'headers', 'payload', 'attachments', 'message_attachments' );
		$out     = array();
		foreach ( $value as $k => $v ) {
			$key = strtolower( (string) $k );
			if ( in_array( $key, $drop, true ) ) {
				$out[ $k ] = '[omitted]';
				continue;
			}
			$sensitive = in_array( $key, $content, true ) || preg_match( '/token|secret|password|signature|(^|_)url$/', $key );
			if ( $sensitive && ( is_scalar( $v ) || $v === null ) ) {
				$out[ $k ] = '[redacted len=' . strlen( (string) $v ) . ']';
				continue;
			}
			if ( $sensitive && $key !== 'message' ) {
				$out[ $k ] = '[redacted]';
				continue;
			}
			$out[ $k ] = self::redact_log_value( $v, $depth + 1 );
		}
		return $out;
	}

	private function zalobot_text_mentions_bot( $text, $bot ) {
		// [2026-07-21 Johnny Chu] PHASE-ZALOBOT-GROUP W6 — detect group mention before selector matching.
		$text = (string) $text;
		if ( strpos( $text, '@' ) === false ) { return false; }
		$bot_name = is_object( $bot ) && isset( $bot->bot_name ) ? trim( (string) $bot->bot_name ) : '';
		if ( $bot_name !== '' && mb_stripos( $text, '@' . $bot_name, 0, 'UTF-8' ) !== false ) { return true; }
		return preg_match( '/@\s*bot\b/iu', $text ) === 1;
	}

	private function zalobot_strip_bot_mention( $text, $bot ) {
		// [2026-07-21 Johnny Chu] PHASE-ZALOBOT-GROUP W6 — remove bot mention from prompt text but keep raw_text for audit.
		$text = (string) $text;
		$bot_name = is_object( $bot ) && isset( $bot->bot_name ) ? trim( (string) $bot->bot_name ) : '';
		if ( $bot_name !== '' ) {
			$text = preg_replace( '/@\s*' . preg_quote( $bot_name, '/' ) . '\b\s*/iu', '', $text );
		}
		$text = preg_replace( '/@\s*bot\s+[^\s]+\s*/iu', '', $text );
		$text = preg_replace( '/\s+/u', ' ', (string) $text );
		return trim( (string) $text );
	}

	private function zalobot_message_replies_to_bot( $message ) {
		// [2026-07-21 Johnny Chu] PHASE-ZALOBOT-GROUP W6 — Bot Platform only delivers group quote/reply events to the bot.
		if ( ! is_array( $message ) ) { return false; }
		return ! empty( $message['reply_to_message'] ) || ! empty( $message['quoted_message'] ) || ! empty( $message['quote'] );
	}

	/**
	 * Clean old log files (keep last 30 days)
	 */
	private function cleanup_old_logs() {
		$log_dir = WP_CONTENT_DIR . '/mu-plugins/logs';
		if ( ! file_exists( $log_dir ) ) {
			return;
		}
		
		$cutoff_date = gmdate( 'Y-m-d', strtotime( '-30 days' ) );
		$files = glob( $log_dir . '/zalohook-*.log' );

		foreach ( (array) $files as $file ) {
			$filename = basename( $file );
			// [2026-10-25 Johnny Chu - Chu Hoàng Anh] PHASE-0.126 R126-SEC H4 — also match the salted-suffix file names.
			if ( preg_match( '/zalohook-(\d{4}-\d{2}-\d{2})(-[a-z0-9]+)?\.log$/i', $filename, $matches ) ) {
				if ( $matches[1] < $cutoff_date ) {
					unlink( $file );
				}
			}
		}
	}
	private function determine_message_type( $data ) {
		$message = $data['message'] ?? array();
		$attachments = $message['message_attachments'] ?? array();
		
		if ( ! empty( $attachments ) ) {
			$first_attachment = $attachments[0] ?? array();
			$payload = $first_attachment['payload'] ?? array();
			$url = $payload['url'] ?? '';
			
			if ( ! empty( $url ) ) {
				// Use enhanced classification (matches twf_classify_attachment logic)
				if ( function_exists( 'twf_classify_attachment' ) ) {
					return twf_classify_attachment( $url );
				}
				
				// Fallback to basic detection
				$extension = strtolower( pathinfo( parse_url( $url, PHP_URL_PATH ), PATHINFO_EXTENSION ) );
				
				$image_exts = array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg' );
				if ( in_array( $extension, $image_exts ) ) {
					return 'image';
				}
				
				$audio_exts = array( 'aac', 'm4a', 'mp3', 'wav', 'ogg', 'oga' );
				if ( in_array( $extension, $audio_exts ) ) {
					return 'audio';
				}
				
				// Additional image checks for URLs without clear extension
				$url_lower = strtolower( $url );
				if ( strpos( $url_lower, '/gif/' ) !== false 
					|| strpos( $url_lower, '/images/' ) !== false
					|| strpos( $url_lower, '/sticker/' ) !== false
					|| strpos( $url_lower, 'stc-' ) !== false ) {
					return 'image';
				}
				
				// Check for image extension with query params: .gif?v=123
				if ( preg_match( '#\.(jpg|jpeg|png|gif|webp|bmp)(\?|$)#i', $url ) ) {
					return 'image';
				}
				
				return 'file';
			}
		}
		
		return 'text';
	}
	
	/**
	 * Extract image URL from webhook data
	 */
	private function extract_image_url( $data ) {
		$message = $data['message'] ?? array();
		$attachments = $message['message_attachments'] ?? array();
		
		if ( ! empty( $attachments ) ) {
			$first_attachment = $attachments[0] ?? array();
			$payload = $first_attachment['payload'] ?? array();
			$url = $payload['url'] ?? '';
			
			if ( ! empty( $url ) ) {
				// Use enhanced classification
				if ( function_exists( 'twf_classify_attachment' ) ) {
					$type = twf_classify_attachment( $url );
					if ( $type === 'image' ) {
						return esc_url( $url );
					}
					return '';
				}
				
				// Fallback detection
				$extension = strtolower( pathinfo( parse_url( $url, PHP_URL_PATH ), PATHINFO_EXTENSION ) );
				$image_exts = array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg' );
				if ( in_array( $extension, $image_exts ) ) {
					return esc_url( $url );
				}
				
				// Additional checks for images without clear extension
				$url_lower = strtolower( $url );
				if ( strpos( $url_lower, '/gif/' ) !== false 
					|| strpos( $url_lower, '/images/' ) !== false
					|| strpos( $url_lower, '/sticker/' ) !== false
					|| strpos( $url_lower, 'stc-' ) !== false ) {
					return esc_url( $url );
				}
				
				// Check for image extension with query params
				if ( preg_match( '#\.(jpg|jpeg|png|gif|webp|bmp)(\?|$)#i', $url ) ) {
					return esc_url( $url );
				}
			}
		}
		
		return '';
	}
	
	/**
	 * Extract file URL from webhook data
	 */
	private function extract_file_url( $data ) {
		$message = $data['message'] ?? array();
		$attachments = $message['message_attachments'] ?? array();
		
		if ( ! empty( $attachments ) ) {
			$first_attachment = $attachments[0] ?? array();
			$payload = $first_attachment['payload'] ?? array();
			$url = $payload['url'] ?? '';
			
			if ( ! empty( $url ) ) {
				// Use enhanced classification - only return if NOT an image
				if ( function_exists( 'twf_classify_attachment' ) ) {
					$type = twf_classify_attachment( $url );
					if ( $type !== 'image' && $type !== 'unknown' ) {
						return esc_url( $url );
					}
					return '';
				}
				
				// Fallback detection
				$extension = strtolower( pathinfo( parse_url( $url, PHP_URL_PATH ), PATHINFO_EXTENSION ) );
				$image_exts = array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg' );
				if ( ! in_array( $extension, $image_exts ) ) {
					// Additional check: make sure it's really not an image
					$url_lower = strtolower( $url );
					if ( strpos( $url_lower, '/gif/' ) === false 
						&& strpos( $url_lower, '/images/' ) === false
						&& strpos( $url_lower, '/sticker/' ) === false
						&& strpos( $url_lower, 'stc-' ) === false
						&& ! preg_match( '#\.(jpg|jpeg|png|gif|webp|bmp)(\?|$)#i', $url ) ) {
						return esc_url( $url );
					}
				}
			}
		}
		
		return '';
	}
	
	/**
	 * Extract file name from webhook data
	 */
	private function extract_file_name( $data ) {
		$message = $data['message'] ?? array();
		$attachments = $message['message_attachments'] ?? array();
		
		if ( ! empty( $attachments ) ) {
			$first_attachment = $attachments[0] ?? array();
			$payload = $first_attachment['payload'] ?? array();
			$url = $payload['url'] ?? '';
			
			if ( ! empty( $url ) ) {
				return sanitize_file_name( basename( parse_url( $url, PHP_URL_PATH ) ) );
			}
		}
		
		return '';
	}
	
	/**
	 * Decrypt webhook data using blog_id as key
	 */
	private function decrypt_webhook_data( $encrypted_payload, $blog_id ) {
		// Generate encryption key from blog_id
		$key = $this->generate_encryption_key( $blog_id );
		
		// Try to base64 decode the payload
		// [2026-08-20 Johnny Chu] CODEC-CORE — preserve Zalo webhook XOR/Base64 decoding through shared primitives.
		$encrypted_data = BizCity_Codec::base64_decode( $encrypted_payload, false );
		if ( $encrypted_data === false ) {
			error_log( '[ZaloHook] Base64 decode failed' );
			return false;
		}
		
		// Simple XOR decryption (you can implement AES here if needed)
		$decrypted = BizCity_Codec::xor_bytes( $encrypted_data, $key );
		
		// Try to decode JSON
		$result = json_decode( $decrypted, true );
		if ( json_last_error() !== JSON_ERROR_NONE ) {
			error_log( '[ZaloHook] JSON decode failed after decryption: ' . json_last_error_msg() );
			return false;
		}
		
		return $result;
	}
	
	/**
	 * Generate encryption key from blog_id
	 */
	private function generate_encryption_key( $blog_id ) {
		// Create a consistent key based on blog_id and a secret salt
		$salt = 'bizcity_zalo_secret_2026'; // You can make this configurable
		return hash( 'sha256', $blog_id . '_' . $salt, true );
	}
	
	/**
	 * Simple XOR encryption/decryption
	 */
	private function xor_decrypt( $data, $key ) {
		$result = '';
		$key_length = strlen( $key );
		
		for ( $i = 0; $i < strlen( $data ); $i++ ) {
			$result .= $data[$i] ^ $key[$i % $key_length];
		}
		
		return $result;
	}
	
	/**
	 * [2026-10-25 Johnny Chu - Chu Hoàng Anh] PHASE-0.126 R126-SEC C1 — authenticate a /zalohook/ delivery.
	 *
	 * The X-Bot-Api-Secret-Token header (sent by Zalo because setWebhook registers `secret_token`) must equal the stored
	 * webhook_secret of an ACTIVE bot (hash_equals). Current blog first, then (multisite) the other blogs. Bots without a
	 * stored secret can never match: they must re-run "Set webhook". Replaces the unused verify_webhook_secret(), which also
	 * accepted a secret derived from the public blog_id.
	 *
	 * @param string $secret_token Header value.
	 * @return bool
	 */
	private function authenticate_zalohook( $secret_token ) {
		global $wpdb;
		$this->auth_bot     = null;
		$this->auth_blog_id = 0;
		$secret_token       = (string) $secret_token;
		if ( $secret_token === '' ) {
			return false;
		}

		$current = (int) get_current_blog_id();
		$blogs   = array( $current );
		if ( is_multisite() ) {
			$others = $wpdb->get_col( "SELECT blog_id FROM {$wpdb->blogs} WHERE archived = 0 AND deleted = 0 ORDER BY blog_id DESC LIMIT 100" );
			foreach ( (array) $others as $blog_id ) {
				if ( (int) $blog_id !== $current ) {
					$blogs[] = (int) $blog_id;
				}
			}
		}

		foreach ( $blogs as $blog_id ) {
			$table = ( $blog_id === $current ? $wpdb->prefix : $wpdb->get_blog_prefix( $blog_id ) ) . 'bizcity_zalo_bots';
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
				continue;
			}
			$bots = $wpdb->get_results( "SELECT * FROM {$table} WHERE status = 'active' AND webhook_secret IS NOT NULL AND webhook_secret != ''" );
			foreach ( (array) $bots as $b ) {
				if ( hash_equals( (string) $b->webhook_secret, $secret_token ) ) {
					$this->auth_bot     = $b;
					$this->auth_blog_id = (int) $blog_id;
					$this->log_zalohook_info( "Matched bot by secret in blog #{$blog_id}: {$b->bot_name} (ID: {$b->id})" );
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * [2026-10-25 Johnny Chu - Chu Hoàng Anh] PHASE-0.126 R126-SEC C1 — active bots of this blog that have no stored webhook secret.
	 *
	 * @return array<int,string> bot id => bot name
	 */
	private function bots_without_secret() {
		global $wpdb;
		$table = $wpdb->prefix . 'bizcity_zalo_bots';
		$wpdb->suppress_errors( true );
		$rows = $wpdb->get_results( "SELECT id, bot_name FROM {$table} WHERE status = 'active' AND ( webhook_secret IS NULL OR webhook_secret = '' ) LIMIT 50" );
		$wpdb->suppress_errors( false );
		$out = array();
		foreach ( (array) $rows as $r ) {
			$out[ (int) $r->id ] = (string) $r->bot_name;
		}
		return $out;
	}

	/**
	 * [2026-10-25 Johnny Chu - Chu Hoàng Anh] PHASE-0.126 R126-SEC C1 — a refused delivery while secret-less bots exist ⇒ raise the admin flag
	 * (written at most once a minute so unauthenticated traffic cannot hammer the options table).
	 */
	private function flag_bots_without_secret() {
		$flag = get_transient( self::SECRET_MISSING_FLAG );
		if ( is_array( $flag ) && isset( $flag['at'] ) && ( time() - (int) $flag['at'] ) < MINUTE_IN_SECONDS ) {
			return;
		}
		$bots = $this->bots_without_secret();
		if ( empty( $bots ) ) {
			return;
		}
		set_transient( self::SECRET_MISSING_FLAG, array(
			'bots'     => $bots,
			'rejected' => ( is_array( $flag ) && isset( $flag['rejected'] ) ? (int) $flag['rejected'] : 0 ) + 1,
			'at'       => time(),
		), WEEK_IN_SECONDS );
	}

	/**
	 * [2026-10-25 Johnny Chu - Chu Hoàng Anh] PHASE-0.126 R126-SEC C1 — admin notice: bots without a webhook secret have their Zalo
	 * deliveries refused (401) until the admin re-runs "Set webhook" (which registers a secret_token with Zalo).
	 */
	public function admin_notice_missing_secret() {
		if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$flag = get_transient( self::SECRET_MISSING_FLAG );
		$bots = $this->bots_without_secret();
		if ( empty( $bots ) ) {
			if ( false !== $flag ) {
				delete_transient( self::SECRET_MISSING_FLAG );
			}
			return;
		}
		$rejected = is_array( $flag ) && isset( $flag['rejected'] ) ? (int) $flag['rejected'] : 0;
		$names    = array();
		foreach ( $bots as $id => $name ) {
			$names[] = sprintf( '%s (#%d)', $name !== '' ? $name : 'Zalo Bot', (int) $id );
		}
		echo '<div class="notice notice-error"><p><strong>Zalo Bot:</strong> ';
		echo esc_html( sprintf(
			'Webhook /zalohook/ chỉ nhận tin có X-Bot-Api-Secret-Token khớp secret đã lưu. Bot chưa có webhook secret: %s — tin nhắn tới các bot này đang bị từ chối%s. Mở bot → đặt Webhook Secret (≥ 8 ký tự) hoặc dùng tab Setup → chạy lại "Set webhook" để đăng ký secret với Zalo.',
			implode( ', ', $names ),
			$rejected > 0 ? ' (đã từ chối ' . $rejected . ' lần)' : ''
		) );
		echo '</p></div>';
	}
	
	/**
	 * Check if any bot is listening and store webhook data
	 */
	private function check_and_store_listener_data( $data, $secret_token ) {
		// [2026-10-25 Johnny Chu - Chu Hoàng Anh] PHASE-0.126 R126-SEC C1 — only the authenticated bot (same blog) can receive listener data;
		// bots without a secret no longer match every delivery, and the secret header is not copied into the transient.
		$bot = $this->auth_bot;
		if ( ! $bot || (int) $this->auth_blog_id !== (int) get_current_blog_id() ) {
			return;
		}
		if ( ! get_transient( 'zalobot_listening_' . $bot->id ) ) {
			return;
		}
		$webhook_data = array(
			'event_name' => $data['event_name'] ?? '',
			'message' => $data['message'] ?? array(),
			'raw_json' => json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ),
			'received_at' => current_time( 'mysql' ),
			'headers' => array(
				'X-Bot-Api-Secret-Token' => $secret_token !== '' ? '(verified)' : '',
				'Content-Type' => isset( $_SERVER['CONTENT_TYPE'] ) ? $_SERVER['CONTENT_TYPE'] : '',
				'User-Agent' => isset( $_SERVER['HTTP_USER_AGENT'] ) ? $_SERVER['HTTP_USER_AGENT'] : '',
			),
		);
		set_transient( 'zalobot_webhook_data_' . $bot->id, $webhook_data, 300 );
		$this->log_zalohook_info( "Stored webhook data for listening bot #{$bot->id}" );
	}

	/**
	 * Read raw request body from shared cache when available.
	 */
	private function get_cached_raw_input() {
		// [2026-07-08 Johnny Chu] HOTFIX — reuse router-cached raw body to avoid
		// empty payload when php://input has already been consumed upstream.
		if ( isset( $GLOBALS['BIZCITY_WEBHOOK_RAW_INPUT'] ) && is_string( $GLOBALS['BIZCITY_WEBHOOK_RAW_INPUT'] ) ) {
			return $GLOBALS['BIZCITY_WEBHOOK_RAW_INPUT'];
		}

		if ( class_exists( 'BizCity_Webhook_Router' ) && method_exists( 'BizCity_Webhook_Router', 'raw_body' ) ) {
			$cached = BizCity_Webhook_Router::raw_body();
			if ( is_string( $cached ) && $cached !== '' ) {
				$GLOBALS['BIZCITY_WEBHOOK_RAW_INPUT'] = $cached;
				return $cached;
			}
		}

		$raw = file_get_contents( 'php://input' );
		if ( ! is_string( $raw ) ) {
			$raw = '';
		}
		$GLOBALS['BIZCITY_WEBHOOK_RAW_INPUT'] = $raw;
		return $raw;
	}
}
