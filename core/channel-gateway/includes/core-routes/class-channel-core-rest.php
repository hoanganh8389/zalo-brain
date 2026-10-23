<?php
/**
 * BizCity_Channel_Core_REST — the CORE routes of Zalo Brain under `bizcity-channel/v1`: personal-Zalo / Zalo Bot channels (`mychannels/*`),
 * `me` + `me/agent-gurus`, `skills`, the admin Guru / connection / usage screens (`admin/*`) and the MCP key screens (`mcp/*`).
 *
 * The core serves them itself (owner Q126-30: a site without the /gpt/ add-on is complete). GENERATED from core/twinweb-axis/includes/class-twinweb-rest.php by
 * core/channel-gateway/docs/PHASE-0.126-JOURNAL-LEDGER-BRAIN-INDEX/tools/reduction-gen-core-rest.mjs — change the generator, not this file
 * (`--check` fails on drift). The old namespace `bizcity-twinweb/v1` keeps answering until the UIs are rebuilt (R126-T4 → T6).
 * No LLM here; identity = the logged-in WordPress user (BizCity_Channel_Core_Identity), never the /gpt/ guest cookie.
 *
 * [2026-10-23 Johnny Chu - Chu Hoàng Anh] PHASE-0.126 R126-T3 — new (generated).
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( class_exists( 'BizCity_Channel_Core_REST', false ) ) { return; }

class BizCity_Channel_Core_REST {

	const NS = 'bizcity-channel/v1';
	const AGENT_GURU_GLOBAL_SLUG    = '__global_memory__';
	const AGENT_GURU_PERSONAL_LIMIT = 20;

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public static function init() {
		add_action( 'rest_api_init', array( self::instance(), 'register_routes' ) );
	}

	/** Any signed-in member; each handler still checks the grant it needs (as before the move). */
	public function perm_logged_in() {
		return is_user_logged_in();
	}

	public function register_routes() {
		$ns = self::NS;

				register_rest_route( $ns, '/skills', array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'list_skills' ),
				'permission_callback' => array( $this, 'perm_logged_in' ),
			) );
				register_rest_route( $ns, '/me', array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_me' ),
				'permission_callback' => array( $this, 'perm_logged_in' ),
			) );
				register_rest_route( $ns, '/mychannels', array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_mychannels' ),
				'permission_callback' => array( $this, 'perm_logged_in' ),
			) );
				register_rest_route( $ns, '/mychannels/zalo-personal/accounts', array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_mychannels_zalo_personal_accounts' ),
					'permission_callback' => array( $this, 'perm_logged_in' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'create_mychannels_zalo_personal_account' ),
					'permission_callback' => array( $this, 'perm_logged_in' ),
					'args'                => array( 'label' => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ) ),
				),
			) );
				register_rest_route( $ns, '/mychannels/zalo-personal/accounts/(?P<id>[A-Za-z0-9_-]+)/qr', array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'start_mychannels_zalo_personal_qr' ),
				'permission_callback' => array( $this, 'perm_logged_in' ),
			) );
				register_rest_route( $ns, '/mychannels/zalo-personal/accounts/(?P<id>[A-Za-z0-9_-]+)/qr/reset', array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'reset_mychannels_zalo_personal_qr' ),
				'permission_callback' => array( $this, 'perm_logged_in' ),
			) );
				register_rest_route( $ns, '/mychannels/zalo-personal/accounts/(?P<id>[A-Za-z0-9_-]+)/status', array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_mychannels_zalo_personal_status' ),
				'permission_callback' => array( $this, 'perm_logged_in' ),
			) );
				register_rest_route( $ns, '/mychannels/zalo-personal/accounts/(?P<id>[A-Za-z0-9_-]+)', array(
				'methods'             => 'DELETE',
				'callback'            => array( $this, 'delete_mychannels_zalo_personal_account' ),
				'permission_callback' => array( $this, 'perm_logged_in' ),
			) );
				register_rest_route( $ns, '/mychannels/zalo-personal/accounts/(?P<id>[A-Za-z0-9_-]+)/bot', array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_mychannels_zalo_personal_bot' ),
					'permission_callback' => array( $this, 'perm_logged_in' ),
				),
				array(
					'methods'             => 'PUT',
					'callback'            => array( $this, 'put_mychannels_zalo_personal_bot' ),
					'permission_callback' => array( $this, 'perm_logged_in' ),
				),
			) );
				register_rest_route( $ns, '/me/agent-gurus', array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_me_agent_gurus' ),
					'permission_callback' => array( $this, 'perm_logged_in' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'create_me_agent_guru' ),
					'permission_callback' => array( $this, 'perm_logged_in' ),
				),
			) );
				register_rest_route( $ns, '/mychannels/zalo-personal/conversations', array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_mychannels_zalo_personal_conversations' ),
				'permission_callback' => array( $this, 'perm_logged_in' ),
			) );
				register_rest_route( $ns, '/mychannels/zalo-personal/conversations/(?P<id>\d+)', array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_mychannels_zalo_personal_conversation' ),
				'permission_callback' => array( $this, 'perm_logged_in' ),
			) );
				register_rest_route( $ns, '/mychannels/zalo-personal/conversations/(?P<id>\d+)/messages', array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_mychannels_zalo_personal_messages' ),
					'permission_callback' => array( $this, 'perm_logged_in' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'send_mychannels_zalo_personal_message' ),
					'permission_callback' => array( $this, 'perm_logged_in' ),
					'args'                => array(
						'idempotency_key' => array( 'type' => 'string', 'required' => true, 'minLength' => 16, 'maxLength' => 190, 'sanitize_callback' => 'sanitize_text_field' ),
					),
				),
			) );
				register_rest_route( $ns, '/mychannels/zalo-personal/conversations/(?P<id>\d+)/notes', array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'post_mychannels_zalo_personal_note' ),
				'permission_callback' => array( $this, 'perm_logged_in' ),
				'args'                => array(
					'idempotency_key' => array( 'type' => 'string', 'required' => true, 'minLength' => 16, 'maxLength' => 190, 'sanitize_callback' => 'sanitize_text_field' ),
				),
			) );
				register_rest_route( $ns, '/mychannels/zalo/bots', array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_mychannels_zalo_bots' ),
				'permission_callback' => array( $this, 'perm_logged_in' ),
			) );
				register_rest_route( $ns, '/mychannels/zalo/select', array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'select_mychannels_zalo_bot' ),
				'permission_callback' => array( $this, 'perm_logged_in' ),
				'args'                => array(
					'bot_id' => array( 'type' => 'integer', 'required' => true ),
				),
			) );
				register_rest_route( $ns, '/mychannels/zalo/link-status', array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_mychannels_zalo_link_status' ),
				'permission_callback' => array( $this, 'perm_logged_in' ),
			) );
				register_rest_route( $ns, '/mychannels/zalo/recent-chats', array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_mychannels_zalo_recent_chats' ),
				'permission_callback' => array( $this, 'perm_logged_in' ),
			) );
				register_rest_route( $ns, '/mychannels/zalo/conversation', array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_mychannels_zalo_conversation' ),
				'permission_callback' => array( $this, 'perm_logged_in' ),
				'args'                => array(
					'chat_id' => array( 'type' => 'string', 'required' => false, 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
					'limit'   => array( 'type' => 'integer', 'required' => false, 'default' => 50, 'sanitize_callback' => 'absint' ),
				),
			) );
				register_rest_route( $ns, '/mychannels/zalo/send', array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'send_mychannels_zalo_message' ),
				'permission_callback' => array( $this, 'perm_logged_in' ),
				'args'                => array(
					'chat_id' => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
					'message' => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_textarea_field' ),
				),
			) );
				register_rest_route( $ns, '/mychannels/zalo/link-command', array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'create_mychannels_zalo_link_command' ),
				'permission_callback' => array( $this, 'perm_logged_in' ),
				'args'                => array(
					'bot_id' => array( 'type' => 'integer', 'required' => false, 'default' => 0 ),
				),
			) );
				register_rest_route( $ns, '/mychannels/zalo/pin-chat', array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'pin_mychannels_zalo_chat' ),
				'permission_callback' => array( $this, 'perm_logged_in' ),
				'args'                => array(
					'chat_id' => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
					'label'   => array( 'type' => 'string', 'required' => false, 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
				),
			) );
				register_rest_route( $ns, '/admin/grounding', array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'admin_get_grounding' ),
					'permission_callback' => array( $this, 'admin_cap_check' ),
				),
				array(
					'methods'             => 'PUT',
					'callback'            => array( $this, 'admin_put_grounding' ),
					'permission_callback' => array( $this, 'admin_cap_check' ),
					'args'                => array(
						// [2026-07-19 Johnny Chu] PHASE-TWINWEB-W0.18 — policy is a JSON object; array schema rejects the auto-mode payload before handler.
						'policy' => array( 'type' => 'object', 'required' => true ),
					),
				),
			) );
				register_rest_route( $ns, '/admin/guru-catalog', array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'admin_get_guru_catalog' ),
				'permission_callback' => array( $this, 'admin_cap_check' ),
			) );
				register_rest_route( $ns, '/admin/guru-workspaces', array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'admin_get_guru_workspaces' ),
				'permission_callback' => array( $this, 'admin_cap_check' ),
			) );
				register_rest_route( $ns, '/admin/guru-workspaces/(?P<guru_id>\d+)', array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'admin_get_guru_workspace' ),
				'permission_callback' => array( $this, 'admin_cap_check' ),
			) );
				register_rest_route( $ns, '/admin/guru-policy/(?P<guru_id>\d+)', array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'admin_get_guru_policy' ),
					'permission_callback' => array( $this, 'admin_cap_check' ),
				),
				array(
					'methods'             => 'PUT',
					'callback'            => array( $this, 'admin_put_guru_policy' ),
					'permission_callback' => array( $this, 'admin_cap_check' ),
					'args'                => array(
						'policy' => array( 'type' => 'object', 'required' => true ),
					),
				),
			) );
				register_rest_route( $ns, '/admin/guru-policy/preview', array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'admin_preview_guru_policy' ),
				'permission_callback' => array( $this, 'admin_cap_check' ),
				'args'                => array(
					'guru_id'        => array( 'type' => 'integer', 'required' => true ),
					'actor_user_id'  => array( 'type' => 'integer', 'required' => false, 'default' => 0 ),
					'surface'       => array( 'type' => 'string', 'required' => false, 'default' => 'twinweb' ),
					'capability'    => array( 'type' => 'string', 'required' => false, 'default' => 'woo_bizops' ),
					'required_role' => array( 'type' => 'string', 'required' => false, 'default' => '' ),
					'required_plan' => array( 'type' => 'string', 'required' => false, 'default' => '' ),
					'target_resource' => array( 'type' => 'object', 'required' => false, 'default' => array() ),
				),
			) );
				register_rest_route( $ns, '/admin/usage', array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'admin_get_usage' ),
				'permission_callback' => array( $this, 'admin_cap_check' ),
				'args'                => array(
					'period' => array( 'type' => 'string', 'required' => false, 'default' => '7d' ),
					'limit'  => array( 'type' => 'integer', 'required' => false, 'default' => 20 ),
				),
			) );
				register_rest_route( $ns, '/admin/connections', array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'admin_get_connections' ),
				'permission_callback' => array( $this, 'admin_cap_check' ),
			) );
				register_rest_route( $ns, '/admin/astro', array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'admin_get_astro' ),
				'permission_callback' => array( $this, 'admin_cap_check' ),
			) );
				register_rest_route( $ns, '/admin/automation', array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'admin_get_automation' ),
				'permission_callback' => array( $this, 'admin_cap_check' ),
				'args'                => array(
					'limit' => array( 'type' => 'integer', 'required' => false, 'default' => 12 ),
				),
			) );
	}

	private function mychannels_error( $code, $message, $hint, $help_code, $extra = array() ) {
		$payload = array_merge( array(
			'success'   => false,
			'code'      => (string) $code,
			'message'   => (string) $message,
			'hint'      => (string) $hint,
			'help_code' => (string) $help_code,
		), is_array( $extra ) ? $extra : array() );

		return rest_ensure_response( $payload );
	}

	private function mychannels_identity() {
		$identity = BizCity_Channel_Core_Identity::current();
		if ( ! empty( $identity['is_guest'] ) || (int) ( $identity['user_id'] ?? 0 ) <= 0 ) {
			return new WP_Error( 'auth_required', 'Đăng nhập để quản lý kênh của bạn.' );
		}

		return $identity;
	}

	private function mychannels_user_meta_key() {
		return 'bizcity_twinweb_mychannels';
	}

	private function get_mychannels_settings_for_user( $user_id ) {
		$raw = get_user_meta( (int) $user_id, $this->mychannels_user_meta_key(), true );
		$raw = is_array( $raw ) ? $raw : array();

		return array(
			'selected_zalo_bot_id'    => isset( $raw['selected_zalo_bot_id'] ) ? (int) $raw['selected_zalo_bot_id'] : 0,
			'selected_zalo_chat_id'   => isset( $raw['selected_zalo_chat_id'] ) ? sanitize_text_field( (string) $raw['selected_zalo_chat_id'] ) : '',
			'selected_zalo_chat_label'=> isset( $raw['selected_zalo_chat_label'] ) ? sanitize_text_field( (string) $raw['selected_zalo_chat_label'] ) : '',
			'selected_fb_page_id'     => isset( $raw['selected_fb_page_id'] ) ? sanitize_text_field( (string) $raw['selected_fb_page_id'] ) : '',
			'selected_fb_page_name'   => isset( $raw['selected_fb_page_name'] ) ? sanitize_text_field( (string) $raw['selected_fb_page_name'] ) : '',
			'updated_at'              => isset( $raw['updated_at'] ) ? sanitize_text_field( (string) $raw['updated_at'] ) : '',
		);
	}

	private function save_mychannels_settings_for_user( $user_id, array $settings ) {
		$settings['updated_at'] = gmdate( 'c' );
		update_user_meta( (int) $user_id, $this->mychannels_user_meta_key(), $settings );
		return $settings;
	}

	private function db_table_exists( $table_name ) {
		static $cache = array();
		$table_name = (string) $table_name;
		if ( isset( $cache[ $table_name ] ) ) {
			return (bool) $cache[ $table_name ];
		}

		global $wpdb;
		$present = (bool) $wpdb->get_var( $wpdb->prepare(
			'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s LIMIT 1',
			$table_name
		) );
		$cache[ $table_name ] = $present;
		return $present;
	}

	private function db_column_exists( $table_name, $column_name ) {
		static $cache = array();
		$key = (string) $table_name . ':' . (string) $column_name;
		if ( isset( $cache[ $key ] ) ) {
			return (bool) $cache[ $key ];
		}

		global $wpdb;
		$present = (bool) $wpdb->get_var( $wpdb->prepare(
			'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s LIMIT 1',
			(string) $table_name,
			(string) $column_name
		) );
		$cache[ $key ] = $present;
		return $present;
	}

	private function get_customer_zalo_bot_policy( array $identity ) {
		$raw = get_option( 'bizcity_twinweb_customer_zalo_bots', array() );
		$raw = is_array( $raw ) ? $raw : array();
		$policy = array(
			'enabled' => array_key_exists( 'enabled', $raw ) ? (bool) $raw['enabled'] : true,
			'max_public_bots' => isset( $raw['max_public_bots'] ) ? max( 1, min( 3, (int) $raw['max_public_bots'] ) ) : 3,
			'bot_ids' => array(),
			'default_bot_id' => isset( $raw['default_bot_id'] ) ? (int) $raw['default_bot_id'] : 0,
			'allow_group_chat' => ! empty( $raw['allow_group_chat'] ),
			'require_link_before_private_context' => array_key_exists( 'require_link_before_private_context', $raw ) ? (bool) $raw['require_link_before_private_context'] : true,
		);
		if ( isset( $raw['bot_ids'] ) && is_array( $raw['bot_ids'] ) ) {
			foreach ( $raw['bot_ids'] as $bot_id ) {
				$bot_id = (int) $bot_id;
				if ( $bot_id > 0 ) {
					$policy['bot_ids'][] = $bot_id;
				}
			}
		}

		$policy['bot_ids'] = array_values( array_unique( $policy['bot_ids'] ) );
		return (array) apply_filters( 'bizcity_twinweb_customer_zalo_bot_policy', $policy, $identity );
	}

	private function list_customer_zalo_bots( array $identity ) {
		global $wpdb;
		$policy = $this->get_customer_zalo_bot_policy( $identity );
		$table  = $wpdb->prefix . 'bizcity_zalo_bots';

		if ( empty( $policy['enabled'] ) || ! $this->db_table_exists( $table ) ) {
			return array( 'policy' => $policy, 'items' => array(), '_degraded' => ! $this->db_table_exists( $table ) );
		}

		$limit = isset( $policy['max_public_bots'] ) ? max( 1, min( 3, (int) $policy['max_public_bots'] ) ) : 3;
		$allowed_ids = isset( $policy['bot_ids'] ) && is_array( $policy['bot_ids'] ) ? array_map( 'intval', $policy['bot_ids'] ) : array();
		$allowed_ids = array_values( array_filter( array_unique( $allowed_ids ) ) );

		if ( ! empty( $allowed_ids ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $allowed_ids ), '%d' ) );
			$sql = call_user_func_array( array( $wpdb, 'prepare' ), array_merge(
				array( "SELECT id, bot_name, oa_id, status FROM {$table} WHERE id IN ({$placeholders}) AND (status = 'active' OR status = 'enabled' OR status = '1' OR status = '') ORDER BY id DESC LIMIT %d" ),
				array_merge( $allowed_ids, array( $limit ) )
			) );
			$rows = $wpdb->get_results( $sql );
		} else {
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT id, bot_name, oa_id, status FROM {$table} WHERE status = 'active' OR status = 'enabled' OR status = '1' OR status = '' ORDER BY id DESC LIMIT %d",
				$limit
			) );
		}

		$items = array();
		foreach ( (array) $rows as $row ) {
			$oa_id = sanitize_text_field( (string) ( $row->oa_id ?? '' ) );
			// [2026-07-21 Johnny Chu] PHASE-TWINWEB W3 — fallback to identity cached by Zalo setWebhook/ping/getMe when legacy oa_id is still empty.
			if ( $oa_id === '' ) {
				$cached_identity = get_option( 'bizcity_zalo_bot_platform_identity_' . (int) $row->id, array() );
				if ( is_array( $cached_identity ) ) {
					$oa_id = trim( sanitize_text_field( (string) ( $cached_identity['bot_platform_id'] ?? '' ) ) . ' ' . sanitize_text_field( (string) ( $cached_identity['account_name'] ?? '' ) ) );
				}
			}
			// [2026-07-21 Johnny Chu] PHASE-TWINWEB W3 — expose official Zalo Bot Platform profile/invite URLs for QR and messaging.
			$links = $this->build_zalo_bot_public_links( $oa_id );
			$items[] = array(
				'id' => (int) $row->id,
				'bot_id' => (int) $row->id,
				'bot_name' => sanitize_text_field( (string) ( $row->bot_name ?? '' ) ),
				'oa_id' => $oa_id,
				'bot_platform_id' => $links['bot_platform_id'],
				'account_name' => $links['account_name'],
				'status' => sanitize_text_field( (string) ( $row->status ?? '' ) ),
				'chat_url' => $links['chat_url'],
				'bot_url' => $links['bot_url'],
				'invite_url' => $links['invite_url'],
				'qr_url' => $links['qr_url'],
			);
		}

		return array( 'policy' => $policy, 'items' => $items, '_degraded' => false );
	}

	private function build_zalo_bot_public_links( $public_id_or_url ) {
		// [2026-07-21 Johnny Chu] PHASE-TWINWEB W3 — getMe.id is /bots/{id}; getMe.account_name is /groups/invite/{account_name}.
		$value = trim( (string) $public_id_or_url );
		$out = array(
			'bot_platform_id' => '',
			'account_name'    => '',
			'bot_url'         => '',
			'invite_url'      => '',
			'legacy_zalo_url' => '',
			'chat_url'        => '',
			'qr_url'          => '',
		);
		if ( $value === '' ) {
			return $out;
		}
		if ( preg_match( '#(?:/bots/|^|[\s,;])([0-9]{8,})(?:$|[\s,;])#', $value, $m ) ) {
			$out['bot_platform_id'] = sanitize_text_field( (string) $m[1] );
			$out['bot_url'] = 'https://bot.zaloplatforms.com/bots/' . rawurlencode( $out['bot_platform_id'] );
		}
		if ( preg_match( '#(?:/groups/invite/|^|[\s,;])(bot\.[A-Za-z0-9_-]+)#', $value, $m ) ) {
			$out['account_name'] = sanitize_text_field( (string) $m[1] );
			$out['invite_url'] = 'https://bot.zaloplatforms.com/groups/invite/' . rawurlencode( $out['account_name'] );
		}
		if ( $out['bot_url'] !== '' || $out['invite_url'] !== '' ) {
			$out['chat_url'] = $out['bot_url'] !== '' ? $out['bot_url'] : $out['invite_url'];
			$out['qr_url'] = $this->build_zalo_bot_qr_url( $out['chat_url'] );
			return $out;
		}
		if ( preg_match( '#^https?://#i', $value ) ) {
			$url = esc_url_raw( $value );
			$out['chat_url'] = $url;
			if ( preg_match( '#/bots/([0-9]+)#', $url, $m ) ) {
				$out['bot_platform_id'] = sanitize_text_field( (string) $m[1] );
				$out['bot_url'] = 'https://bot.zaloplatforms.com/bots/' . rawurlencode( $out['bot_platform_id'] );
				$out['chat_url'] = $out['bot_url'];
			} elseif ( preg_match( '#/groups/invite/(bot\.[A-Za-z0-9_-]+)#', $url, $m ) ) {
				$out['account_name'] = sanitize_text_field( (string) $m[1] );
				$out['invite_url'] = 'https://bot.zaloplatforms.com/groups/invite/' . rawurlencode( $out['account_name'] );
				$out['chat_url'] = $out['invite_url'];
			} elseif ( false !== strpos( $url, 'zalo.me/' ) ) {
				$out['legacy_zalo_url'] = $url;
			}
			$out['qr_url'] = $this->build_zalo_bot_qr_url( $out['chat_url'] );
			return $out;
		}

		$clean = sanitize_text_field( $value );
		if ( preg_match( '/^[0-9]{8,}$/', $clean ) ) {
			$out['bot_platform_id'] = $clean;
			$out['bot_url'] = 'https://bot.zaloplatforms.com/bots/' . rawurlencode( $clean );
			$out['chat_url'] = $out['bot_url'];
		} elseif ( preg_match( '/^bot\.[A-Za-z0-9_-]+$/', $clean ) ) {
			$out['account_name'] = $clean;
			$out['invite_url'] = 'https://bot.zaloplatforms.com/groups/invite/' . rawurlencode( $clean );
			$out['chat_url'] = $out['invite_url'];
		} else {
			$out['legacy_zalo_url'] = 'https://zalo.me/' . rawurlencode( $clean );
			$out['chat_url'] = $out['legacy_zalo_url'];
		}
		$out['qr_url'] = $this->build_zalo_bot_qr_url( $out['chat_url'] );
		return $out;
	}

	private function build_zalo_bot_qr_url( $chat_url ) {
		// [2026-07-21 Johnny Chu] PHASE-TWINWEB W3 — centralize public QR generation for My Channels; returns empty when Zalo has no public bot URL.
		$chat_url = esc_url_raw( (string) $chat_url );
		if ( $chat_url === '' ) {
			return '';
		}
		return add_query_arg(
			array(
				'size'   => '220x220',
				'margin' => '8',
				'data'   => $chat_url,
			),
			'https://api.qrserver.com/v1/create-qr-code/'
		);
	}

	private function find_public_zalo_bot( array $identity, $bot_id ) {
		$list = $this->list_customer_zalo_bots( $identity );
		foreach ( (array) $list['items'] as $bot ) {
			if ( (int) $bot['bot_id'] === (int) $bot_id ) {
				return $bot;
			}
		}
		return null;
	}

	private function get_zalo_link_status_for_user( $user_id, $bot_id ) {
		$status = array(
			'linked' => false,
			'bot_id' => (int) $bot_id,
			'zalo_user_id' => '',
			'display_name' => '',
			'linked_at' => '',
			'notebook_id' => 0,
		);
		if ( (int) $user_id <= 0 || ! class_exists( 'BizCity_Zalobot_User_Linker' ) ) {
			return $status;
		}

		global $wpdb;
		$table = BizCity_Zalobot_User_Linker::table();
		// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-CHANNEL-AUTOMATION — if user has not selected a bot yet, detect latest linked bot for this WP user.
		if ( (int) $bot_id > 0 ) {
			$row = $wpdb->get_row( $wpdb->prepare(
				"SELECT bot_id, zalo_user_id, display_name, linked_at, notebook_id FROM {$table} WHERE bot_id = %d AND wp_user_id = %d AND status = 'linked' LIMIT 1",
				(int) $bot_id,
				(int) $user_id
			) );
		} else {
			$row = $wpdb->get_row( $wpdb->prepare(
				"SELECT bot_id, zalo_user_id, display_name, linked_at, notebook_id FROM {$table} WHERE wp_user_id = %d AND status = 'linked' ORDER BY linked_at DESC, id DESC LIMIT 1",
				(int) $user_id
			) );
		}
		if ( $row ) {
			$status['linked'] = true;
			$status['bot_id'] = isset( $row->bot_id ) ? (int) $row->bot_id : (int) $bot_id;
			$status['zalo_user_id'] = sanitize_text_field( (string) $row->zalo_user_id );
			$status['display_name'] = sanitize_text_field( (string) $row->display_name );
			$status['linked_at'] = sanitize_text_field( (string) $row->linked_at );
			$status['notebook_id'] = isset( $row->notebook_id ) ? (int) $row->notebook_id : 0;
		}

		return $status;
	}

	private function build_zalo_recent_chat_item( array $payload, $row, $bot_id, $linked_zalo_user_id ) {
		$message = isset( $payload['message'] ) && is_array( $payload['message'] ) ? $payload['message'] : array();
		$chat    = isset( $message['chat'] ) && is_array( $message['chat'] ) ? $message['chat'] : array();
		$from    = isset( $message['from'] ) && is_array( $message['from'] ) ? $message['from'] : array();
		$raw_chat_id = sanitize_text_field( (string) ( $chat['id'] ?? '' ) );
		$from_user_id = sanitize_text_field( (string) ( $from['id'] ?? ( $row->client_id ?? '' ) ) );
		if ( $raw_chat_id === '' || $from_user_id === '' || $from_user_id !== (string) $linked_zalo_user_id ) {
			return null;
		}

		$chat_type_raw = strtoupper( sanitize_text_field( (string) ( $chat['chat_type'] ?? $chat['type'] ?? '' ) ) );
		$is_private = $raw_chat_id === (string) $linked_zalo_user_id || $chat_type_raw === 'PRIVATE';
		// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-CHANNEL-AUTOMATION — use explicit private/group chat ids so recent/inbox/send resolve the same target.
		$target_chat_id = $is_private ? 'zalobot_' . (int) $bot_id . '_private_' . (string) $linked_zalo_user_id : 'zalobot_' . (int) $bot_id . '_group_' . $raw_chat_id;
		$display_name = sanitize_text_field( (string) ( $row->display_name ?? ( $from['display_name'] ?? '' ) ) );
		$chat_title = sanitize_text_field( (string) ( $chat['title'] ?? $chat['name'] ?? '' ) );
		$label = $is_private ? ( $display_name !== '' ? $display_name : 'Chat trực tiếp Zalo' ) : ( $chat_title !== '' ? $chat_title : 'Group Zalo ' . substr( $raw_chat_id, 0, 8 ) );

		return array(
			'chat_id' => $target_chat_id,
			'raw_chat_id' => $raw_chat_id,
			'chat_type' => $is_private ? 'private' : 'group',
			'label' => $label,
			'display_name' => $display_name,
			'message_id' => sanitize_text_field( (string) ( $row->message_id ?? '' ) ),
			'last_text' => wp_trim_words( sanitize_text_field( (string) ( $row->text ?? '' ) ), 18, '...' ),
			'last_seen_at' => sanitize_text_field( (string) ( $row->created_at ?? '' ) ),
		);
	}

	private function resolve_mychannels_zalo_chat_target( array $identity, $bot_id, array $link_status, $chat_id ) {
		// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-CHANNEL-AUTOMATION — owner-scoped Zalo target resolver for customer inbox/send.
		$chat_id = sanitize_text_field( (string) $chat_id );
		if ( (int) $bot_id <= 0 || empty( $link_status['linked'] ) || empty( $link_status['zalo_user_id'] ) ) {
			return new WP_Error( 'zalo_link_required', 'Bạn cần liên kết Zalo trước khi mở hội thoại.' );
		}
		if ( $chat_id === '' ) {
			$settings = $this->get_mychannels_settings_for_user( (int) ( $identity['user_id'] ?? 0 ) );
			$chat_id = sanitize_text_field( (string) ( $settings['selected_zalo_chat_id'] ?? '' ) );
		}
		if ( $chat_id === '' ) {
			$chat_id = 'zalobot_' . (int) $bot_id . '_private_' . (string) $link_status['zalo_user_id'];
		}

		$linked_uid = (string) $link_status['zalo_user_id'];
		$direct_ids = array(
			'zalobot_' . (int) $bot_id . '_' . $linked_uid,
			'zalobot_' . (int) $bot_id . '_private_' . $linked_uid,
			$linked_uid,
		);
		if ( in_array( $chat_id, $direct_ids, true ) ) {
			return array( 'chat_id' => 'zalobot_' . (int) $bot_id . '_private_' . $linked_uid, 'raw_chat_id' => $linked_uid, 'chat_type' => 'private', 'label' => (string) ( $link_status['display_name'] ?? 'Chat trực tiếp Zalo' ) );
		}

		$recent = $this->list_customer_zalo_recent_chats( $identity, $bot_id, $link_status );
		foreach ( (array) ( $recent['items'] ?? array() ) as $item ) {
			if ( (string) ( $item['chat_id'] ?? '' ) === $chat_id || (string) ( $item['raw_chat_id'] ?? '' ) === $chat_id ) {
				// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-CHANNEL-AUTOMATION — recent group chats are authorized by observed linked-user evidence.
				return $item;
			}
		}

		return new WP_Error( 'permission_denied', 'Chat Zalo này chưa thuộc danh sách bot đã thấy của bạn.' );
	}

	private function log_mychannels_zalo_outbound( $user_id, $bot_id, array $target, array $link_status, $message, $result ) {
		// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-CHANNEL-AUTOMATION — persist My Channels outbound messages so inbox refresh shows bot replies.
		if ( ! class_exists( 'BizCity_Zalo_Bot_Database' ) ) {
			$database_file = dirname( __DIR__, 3 ) . '/plugins/bizcity-zalo-bot/includes/class-database.php';
			if ( is_readable( $database_file ) ) {
				require_once $database_file;
			}
		}
		if ( ! class_exists( 'BizCity_Zalo_Bot_Database' ) ) {
			return array();
		}

		$raw_chat_id = sanitize_text_field( (string) ( $target['raw_chat_id'] ?? '' ) );
		$linked_uid  = sanitize_text_field( (string) ( $link_status['zalo_user_id'] ?? '' ) );
		if ( (int) $bot_id <= 0 || $raw_chat_id === '' || $linked_uid === '' ) {
			return array();
		}
		$message_id = '';
		if ( is_array( $result ) ) {
			$message_id = sanitize_text_field( (string) ( $result['message_id'] ?? $result['result']['message_id'] ?? '' ) );
		}
		if ( $message_id === '' ) {
			$message_id = 'mychannels_' . substr( sha1( (string) $user_id . '|' . (string) $bot_id . '|' . $raw_chat_id . '|' . microtime( true ) ), 0, 20 );
		}

		$payload = array(
			'source' => 'twinweb_mychannels',
			'direction' => 'outbound',
			'wp_user_id' => (int) $user_id,
			'message' => array(
				'chat' => array(
					'id' => $raw_chat_id,
					'chat_type' => ( (string) ( $target['chat_type'] ?? '' ) === 'group' ) ? 'GROUP' : 'PRIVATE',
				),
				'from' => array(
					'id' => $linked_uid,
					'display_name' => sanitize_text_field( (string) ( $link_status['display_name'] ?? '' ) ),
				),
				'text' => (string) $message,
			),
		);
		BizCity_Zalo_Bot_Database::instance()->log_event( (int) $bot_id, 'bot.reply', $payload, $linked_uid, $message_id, sanitize_text_field( (string) ( $link_status['display_name'] ?? '' ) ), (string) $message );

		return array(
			'id' => $message_id,
			'role' => 'bot',
			'text' => sanitize_textarea_field( (string) $message ),
			'created_at' => gmdate( 'Y-m-d H:i:s' ),
			'event_name' => 'bot.reply',
		);
	}

	private function list_customer_zalo_recent_chats( array $identity, $bot_id, array $link_status ) {
		global $wpdb;
		$table = $wpdb->prefix . 'bizcity_zalo_bot_logs';
		if ( (int) $bot_id <= 0 || empty( $link_status['linked'] ) || empty( $link_status['zalo_user_id'] ) ) {
			return array( 'items' => array(), '_degraded' => false, 'message' => 'Zalo chưa được liên kết.' );
		}
		// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-CHANNEL-AUTOMATION — fail-open on older Zalo log schema instead of querying absent columns.
		if ( ! $this->db_table_exists( $table ) || ! $this->db_column_exists( $table, 'event_data' ) || ! $this->db_column_exists( $table, 'client_id' ) || ! $this->db_column_exists( $table, 'bot_id' ) || ! $this->db_column_exists( $table, 'created_at' ) ) {
			return array( 'items' => array(), '_degraded' => true, 'message' => 'Chưa có bảng log Zalo Bot để gợi ý recent chats.' );
		}

		// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-CHANNEL-AUTOMATION — derive recent chat targets from existing Zalo webhook logs without adding schema.
		$since = gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS );
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT event_name, event_data, client_id, user_id, message_id, display_name, text, created_at FROM {$table} WHERE bot_id = %d AND client_id = %s AND created_at >= %s ORDER BY created_at DESC LIMIT 200",
			(int) $bot_id,
			(string) $link_status['zalo_user_id'],
			$since
		) );

		$items = array();
		foreach ( (array) $rows as $row ) {
			$payload = json_decode( (string) ( $row->event_data ?? '' ), true );
			if ( ! is_array( $payload ) ) {
				continue;
			}
			$item = $this->build_zalo_recent_chat_item( $payload, $row, $bot_id, (string) $link_status['zalo_user_id'] );
			if ( ! is_array( $item ) || empty( $item['chat_id'] ) ) {
				continue;
			}
			if ( isset( $items[ $item['chat_id'] ] ) ) {
				continue;
			}
			$items[ $item['chat_id'] ] = $item;
			if ( count( $items ) >= 20 ) {
				break;
			}
		}

		return array( 'items' => array_values( $items ), '_degraded' => false, 'message' => '' );
	}

	private function list_customer_facebook_pages( array $identity ) {
		global $wpdb;
		$user_id = (int) ( $identity['user_id'] ?? 0 );
		$table = $wpdb->prefix . 'bizcity_facebook_bots';
		if ( $user_id <= 0 || ! $this->db_table_exists( $table ) || ! $this->db_column_exists( $table, 'user_id' ) ) {
			return array( 'items' => array(), '_degraded' => ! $this->db_table_exists( $table ) );
		}

		// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-MY-CONTENT-TRACE — include site-shared legacy admin pages (user_id=0) for contributor/member automation.
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, bot_name, page_id, user_id, status, ai_enabled, created_at, updated_at FROM {$table} WHERE (user_id = %d OR user_id = 0) AND (status = 'active' OR status = 'enabled' OR status = '1' OR status = '') ORDER BY user_id DESC, id DESC LIMIT 100",
			$user_id
		) );

		$items = array();
		$stats_by_bot = $this->get_customer_facebook_page_stats( $rows );
		foreach ( (array) $rows as $row ) {
			$stats = isset( $stats_by_bot[ (int) $row->id ] ) ? $stats_by_bot[ (int) $row->id ] : array( 'messages_7d' => 0, 'comments_7d' => 0, 'posts_30d' => 0, 'automation_runs_7d' => 0 );
			$items[] = array(
				'id' => (int) $row->id,
				'page_id' => sanitize_text_field( (string) $row->page_id ),
				'page_name' => sanitize_text_field( (string) $row->bot_name ),
				'owner_user_id' => (int) ( $row->user_id ?? 0 ),
				'scope' => ( (int) ( $row->user_id ?? 0 ) === $user_id ) ? 'member_owned' : 'site_shared',
				'status' => sanitize_text_field( (string) $row->status ),
				'messenger_enabled' => true,
				'comment_enabled' => true,
				'publish_enabled' => true,
				'ai_enabled' => ! empty( $row->ai_enabled ),
				'last_webhook_at' => sanitize_text_field( (string) ( $row->updated_at ?? '' ) ),
				'stats' => $stats,
			);
		}

		return array( 'items' => $items, '_degraded' => false );
	}

	private function get_customer_facebook_page_stats( $rows ) {
		global $wpdb;
		$stats = array();
		$bot_ids = array();
		foreach ( (array) $rows as $row ) {
			$bot_id = (int) ( $row->id ?? 0 );
			if ( $bot_id > 0 ) {
				$bot_ids[] = $bot_id;
				$stats[ $bot_id ] = array( 'messages_7d' => 0, 'comments_7d' => 0, 'posts_30d' => 0, 'automation_runs_7d' => 0 );
			}
		}
		if ( empty( $bot_ids ) ) {
			return $stats;
		}

		// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-CHANNEL-AUTOMATION — owner-scoped page metrics from existing FB logs only.
		$logs_table = $wpdb->prefix . 'bizcity_facebook_bot_logs';
		if ( $this->db_table_exists( $logs_table ) && $this->db_column_exists( $logs_table, 'bot_id' ) && $this->db_column_exists( $logs_table, 'created_at' ) ) {
			$since7 = gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS );
			$ids_sql = implode( ',', array_map( 'absint', $bot_ids ) );
			$rows_count = $wpdb->get_results( $wpdb->prepare( "SELECT bot_id, COUNT(*) AS total FROM {$logs_table} WHERE bot_id IN ({$ids_sql}) AND created_at >= %s GROUP BY bot_id", $since7 ) );
			foreach ( (array) $rows_count as $r ) {
				$bid = (int) $r->bot_id;
				if ( isset( $stats[ $bid ] ) ) { $stats[ $bid ]['messages_7d'] = (int) $r->total; }
			}
			$comment_rows = $wpdb->get_results( $wpdb->prepare( "SELECT bot_id, COUNT(*) AS total FROM {$logs_table} WHERE bot_id IN ({$ids_sql}) AND created_at >= %s AND (event_name LIKE %s OR event_data LIKE %s) GROUP BY bot_id", $since7, '%comment%', '%comment%' ) );
			foreach ( (array) $comment_rows as $r ) {
				$bid = (int) $r->bot_id;
				if ( isset( $stats[ $bid ] ) ) { $stats[ $bid ]['comments_7d'] = (int) $r->total; }
			}
		}
		return $stats;
	}

	private function build_mychannels_dashboard_payload( array $identity ) {
		global $wpdb;
		$user_id = (int) ( $identity['user_id'] ?? 0 );
		$settings = $this->get_mychannels_settings_for_user( $user_id );
		$bot_id = (int) ( $settings['selected_zalo_bot_id'] ?? 0 );
		$link_status = $this->get_zalo_link_status_for_user( $user_id, $bot_id );
		$facebook = $this->list_customer_facebook_pages( $identity );
		$payload = array(
			'zalo_messages_7d' => 0,
			'facebook_messages_7d' => 0,
			'comments_7d' => 0,
			'posts_30d' => 0,
			'automation_runs_7d' => 0,
			'automation_failures_7d' => 0,
			'_degraded' => false,
		);
		$since7 = gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS );
		$since30 = gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS );

		// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-CHANNEL-AUTOMATION — scoped Zalo dashboard metric from selected bot + linked user only.
		$zalo_logs = $wpdb->prefix . 'bizcity_zalo_bot_logs';
		if ( $bot_id > 0 && ! empty( $link_status['zalo_user_id'] ) && $this->db_table_exists( $zalo_logs ) && $this->db_column_exists( $zalo_logs, 'client_id' ) && $this->db_column_exists( $zalo_logs, 'created_at' ) ) {
			$payload['zalo_messages_7d'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$zalo_logs} WHERE bot_id = %d AND client_id = %s AND created_at >= %s", $bot_id, (string) $link_status['zalo_user_id'], $since7 ) );
		}

		foreach ( (array) ( $facebook['items'] ?? array() ) as $page ) {
			$stats = is_array( $page['stats'] ?? null ) ? $page['stats'] : array();
			$payload['facebook_messages_7d'] += (int) ( $stats['messages_7d'] ?? 0 );
			$payload['comments_7d'] += (int) ( $stats['comments_7d'] ?? 0 );
		}

		$crm_events = $wpdb->prefix . 'bizcity_crm_events';
		if ( $user_id > 0 && $this->db_table_exists( $crm_events ) && $this->db_column_exists( $crm_events, 'user_id' ) && $this->db_column_exists( $crm_events, 'metadata' ) ) {
			$payload['posts_30d'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$crm_events} WHERE user_id = %d AND created_at >= %s AND (metadata LIKE %s OR metadata LIKE %s OR event_type LIKE %s)", $user_id, $since30, '%fb_page_id%', '%facebook%', '%fb%' ) );
		}

		$runs_table = $wpdb->prefix . 'bizcity_automation_runs';
		if ( $user_id > 0 && $this->db_table_exists( $runs_table ) && $this->db_column_exists( $runs_table, 'user_id' ) ) {
			$payload['automation_runs_7d'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$runs_table} WHERE user_id = %d AND created_at >= %s", $user_id, $since7 ) );
			$payload['automation_failures_7d'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$runs_table} WHERE user_id = %d AND created_at >= %s AND status IN ('failed','error','cancelled')", $user_id, $since7 ) );
		} else {
			$payload['_degraded'] = true;
		}
		return $payload;
	}

	public function get_mychannels( WP_REST_Request $request ) {
		unset( $request );
		$identity = $this->mychannels_identity();
		if ( is_wp_error( $identity ) ) {
			return $this->mychannels_error( 'auth_required', 'Bạn cần đăng nhập để quản lý kênh.', 'Đăng nhập vào Twin GPT rồi mở lại Kênh của tôi.', 'auth_required' );
		}

		$user_id = (int) $identity['user_id'];
		$settings = $this->get_mychannels_settings_for_user( $user_id );
		$zalo = $this->list_customer_zalo_bots( $identity );
		if ( empty( $settings['selected_zalo_bot_id'] ) && ! empty( $zalo['items'][0]['bot_id'] ) ) {
			$settings['selected_zalo_bot_id'] = (int) $zalo['items'][0]['bot_id'];
		}
		$link_status = $this->get_zalo_link_status_for_user( $user_id, (int) $settings['selected_zalo_bot_id'] );
		$facebook = $this->list_customer_facebook_pages( $identity );

		return rest_ensure_response( array(
			'success' => true,
			'user_id' => $user_id,
			'settings' => $settings,
			'channel_catalog' => $this->build_mychannels_channel_catalog( $identity ),
			'zalo' => array( 'policy' => $zalo['policy'], 'bots' => $zalo['items'], 'link_status' => $link_status, '_degraded' => ! empty( $zalo['_degraded'] ) ),
			'facebook' => array( 'pages' => $facebook['items'], '_degraded' => ! empty( $facebook['_degraded'] ) ),
			'dashboard' => $this->build_mychannels_dashboard_payload( $identity ),
		) );
	}

	private function build_mychannels_channel_catalog( array $identity ): array {
		// [2026-08-29 Johnny Chu] PHASE-0.45-TWINGPT-CHANNEL-CONNECT — server owns channel grouping and availability; React only renders the catalog.
		return array(
			array( 'code' => 'facebook', 'label' => 'Facebook', 'group' => 'customer', 'category' => 'social', 'availability' => 'available', 'connection_kind' => 'member_oauth', 'actions' => array( 'connect', 'select', 'revoke' ) ),
			array( 'code' => 'tiktok', 'label' => 'Tiktok', 'group' => 'customer', 'category' => 'social', 'availability' => 'planned', 'connection_kind' => 'member_oauth', 'actions' => array(), 'reason' => 'provider_contract_pending' ),
			array( 'code' => 'zalo_oa', 'label' => 'Zalo OA', 'group' => 'customer', 'category' => 'messaging', 'availability' => 'available', 'connection_kind' => 'member_owned', 'subchannels' => array( 'zalo_oa' ), 'actions' => array( 'connect', 'status', 'revoke' ) ),
			array( 'code' => 'zalo_personal', 'label' => 'Zalo Cá nhân', 'group' => 'customer', 'category' => 'messaging', 'availability' => 'available', 'connection_kind' => 'member_owned', 'subchannels' => array( 'zalo_personal' ), 'actions' => array( 'connect', 'status', 'revoke' ) ),
			array( 'code' => 'web', 'label' => 'Web', 'group' => 'customer', 'category' => 'website', 'availability' => 'planned', 'connection_kind' => 'site_binding', 'actions' => array(), 'reason' => 'provider_contract_pending' ),
			array( 'code' => 'internal', 'label' => 'Twin GPT + Zalo Bot', 'group' => 'internal', 'category' => 'operations', 'availability' => 'available', 'connection_kind' => 'workspace_admin', 'subchannels' => array( 'twinweb', 'zalo_bot' ), 'actions' => array( 'select', 'link', 'chat' ) ),
		);
	}

	private function mychannels_zalo_personal_account( WP_REST_Request $request, array $identity, bool $allow_claim = false ) {
		// [2026-08-21 Johnny Chu] PHASE-0.39B — resolve only the current user's Personal account.
		$account_id = sanitize_text_field( (string) $request->get_param( 'id' ) );
		// [2026-10-13 Johnny Chu - Chu Hoàng Anh] R-ZP-DUP v2 — QR start/reset/status also see a number this user typed and is
		// taking over by QR (pending claim); every other route (delete, bot, inbox) still sees only rows they own.
		if ( $allow_claim && class_exists( 'BizCity_Zalo_Bridge_REST' ) && method_exists( 'BizCity_Zalo_Bridge_REST', 'find_account_for_owner_or_claim' ) ) {
			$account = BizCity_Zalo_Bridge_REST::find_account_for_owner_or_claim( (int) $identity['user_id'], $account_id );
		} else {
			$account = class_exists( 'BizCity_Zalo_Mapping_Repo' )
				? BizCity_Zalo_Mapping_Repo::find_personal_account_for_owner( (int) $identity['user_id'], $account_id )
				: null;
		}
		if ( ! is_array( $account ) ) {
			return $this->mychannels_error( 'permission_denied', 'Tài khoản Zalo này không thuộc tài khoản của bạn.', 'Chọn tài khoản Zalo Personal trong Kênh của tôi.', 'permission_denied' );
		}
		return $account;
	}

	/** Require managed Branch 19 entitlement before any /gpt/ Personal account operation. */
	private function mychannels_zalo_personal_gate() {
		// [2026-08-22 Johnny Chu] R-B2B2C/R-TWEB-14 — /gpt/ may connect only through the managed exact-key capability projection.
		if ( ! class_exists( 'BizCity_Zalo_Bridge_Client' ) || ! class_exists( 'BizCity_Zalo_Personal_Hub_Client' ) ) {
			return $this->mychannels_error( 'module_not_loaded', 'Zalo Personal managed chưa sẵn sàng.', 'Bật module Zalo Personal rồi thử lại.', 'module_not_loaded' );
		}
		if ( BizCity_Zalo_Bridge_Client::instance()->get_mode() !== 'managed_1api' ) {
			return $this->mychannels_error( 'permission_denied', 'Kênh Zalo Personal này chưa dùng chế độ managed.', 'Yêu cầu quản trị viên bật managed 1API cho site trước khi kết nối.', 'permission_denied' );
		}
		$projection = BizCity_Zalo_Personal_Hub_Client::instance()->capability();
		if ( empty( $projection['success'] ) ) {
			return $this->mychannels_error( 'gateway_degraded', 'Chưa đọc được quyền Zalo Personal từ BizCity Hub.', 'Kiểm tra API key riêng trên blog hiện tại rồi thử lại.', 'api_key_missing' );
		}
		$capability = isset( $projection['capability'] ) && is_array( $projection['capability'] ) ? $projection['capability'] : array();
		if ( empty( $capability['allowed'] ) ) {
			return $this->mychannels_error( 'feature_not_enabled', 'Gói hiện tại chưa cho phép kết nối Zalo Personal.', 'Nâng cấp hoặc bật tính năng bizcity-zalo-personal cho API key của blog hiện tại.', 'feature_not_enabled', array( 'capability' => $capability ) );
		}
		return true;
	}

	public function get_mychannels_zalo_personal_accounts( WP_REST_Request $request ) {
		// [2026-08-21 Johnny Chu] PHASE-0.39B — list owner-scoped Personal accounts for /gpt/.
		unset( $request );
		$identity = $this->mychannels_identity();
		if ( is_wp_error( $identity ) ) { return $this->mychannels_error( 'auth_required', 'Bạn cần đăng nhập để xem Zalo Cá nhân.', 'Đăng nhập vào Twin GPT rồi thử lại.', 'auth_required' ); }
		$gate = $this->mychannels_zalo_personal_gate();
		if ( true !== $gate ) { return $gate; }
		$rows = class_exists( 'BizCity_Zalo_Mapping_Repo' )
			? BizCity_Zalo_Mapping_Repo::list_personal_accounts_for_owner( (int) $identity['user_id'] )
			: array();
		// [2026-09-07 04:10 PM Johnny Chu - Chu Hoàng Anh] R-1API-AUTH/R-TWEB-14 — local owner rows are only visible when the exact managed API key also owns the bridge account.
		if ( ! class_exists( 'BizCity_Zalo_Bridge_Client' ) ) {
			return $this->mychannels_error( 'module_not_loaded', 'Zalo Personal chưa sẵn sàng để xác định quyền tài khoản.', 'Tải lại Kênh của tôi sau khi managed bridge được bật.', 'module_not_loaded' );
		}
		$managed = BizCity_Zalo_Bridge_Client::instance()->list_accounts();
		if ( ! empty( $managed['_degraded'] ) || empty( $managed['success'] ) || ! isset( $managed['accounts'] ) || ! is_array( $managed['accounts'] ) ) {
			return $this->mychannels_error( 'gateway_degraded', 'Chưa xác định được quyền các tài khoản Zalo hiện tại.', 'Kiểm tra API key của blog hiện tại rồi tải lại Kênh của tôi.', 'api_key_missing', array( '_degraded' => true ) );
		}
		$local_row_count = count( $rows );
		$managed_ids = array();
		foreach ( $managed['accounts'] as $managed_account ) {
			if ( is_array( $managed_account ) && isset( $managed_account['id'] ) && (string) $managed_account['id'] !== '' ) {
				$managed_ids[ (string) $managed_account['id'] ] = true;
			}
		}
		$rows = array_values( array_filter( $rows, static function ( $row ) use ( $managed_ids ) {
			return is_array( $row ) && isset( $managed_ids[ (string) ( $row['bridge_account_id'] ?? '' ) ] );
		} ) );
		if ( $local_row_count > 0 && empty( $rows ) ) {
			return $this->mychannels_error( 'permission_denied', 'Tài khoản Zalo cũ không thuộc API key hiện tại.', 'Cấp lại quyền cho đúng API key của blog này hoặc tạo kết nối Zalo Personal mới.', 'permission_denied', array( '_degraded' => true ) );
		}
		$items = array_map( static function ( $row ) {
			return array(
				'id'            => (string) ( $row['bridge_account_id'] ?? '' ),
				'label'         => (string) ( $row['label'] ?? '' ),
				'status'        => (string) ( $row['status'] ?? 'pending_qr' ),
			);
		}, $rows );
		return rest_ensure_response( array( 'success' => true, 'items' => $items ) );
	}

	public function create_mychannels_zalo_personal_account( WP_REST_Request $request ) {
		// [2026-08-21 Johnny Chu] PHASE-0.39B — create Personal account through the canonical bridge bind flow.
		$identity = $this->mychannels_identity();
		if ( is_wp_error( $identity ) ) { return $this->mychannels_error( 'auth_required', 'Bạn cần đăng nhập để tạo Zalo Cá nhân.', 'Đăng nhập vào Twin GPT rồi thử lại.', 'auth_required' ); }
		$gate = $this->mychannels_zalo_personal_gate();
		if ( true !== $gate ) { return $gate; }
		if ( ! class_exists( 'BizCity_Zalo_Bridge_REST' ) ) { return $this->mychannels_error( 'module_not_loaded', 'Zalo Personal chưa sẵn sàng.', 'Bật plugin Zalo Personal rồi thử lại.', 'module_not_loaded' ); }
		return BizCity_Zalo_Bridge_REST::create_account_for_owner( $request, (int) $identity['user_id'], true );
	}

	public function start_mychannels_zalo_personal_qr( WP_REST_Request $request ) {
		// [2026-08-21 Johnny Chu] PHASE-0.39B — owner-scoped QR start.
		$identity = $this->mychannels_identity();
		if ( is_wp_error( $identity ) ) { return $this->mychannels_error( 'auth_required', 'Bạn cần đăng nhập để tạo mã QR.', 'Đăng nhập vào Twin GPT rồi thử lại.', 'auth_required' ); }
		$gate = $this->mychannels_zalo_personal_gate();
		if ( true !== $gate ) { return $gate; }
		$account = $this->mychannels_zalo_personal_account( $request, $identity, true );
		if ( is_wp_error( $account ) || $account instanceof WP_REST_Response ) { return $account; }
		if ( ! is_array( $account ) || ! class_exists( 'BizCity_Zalo_Bridge_REST' ) ) { return $this->mychannels_error( 'module_not_loaded', 'Tài khoản Zalo Personal chưa sẵn sàng.', 'Tải lại Kênh của tôi rồi thử lại.', 'module_not_loaded' ); }
		return BizCity_Zalo_Bridge_REST::start_qr_for_owner( $account, (int) $identity['user_id'] );
	}

	public function reset_mychannels_zalo_personal_qr( WP_REST_Request $request ) {
		// [2026-09-03 11:58 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.39E-D1C — owner-scoped re-login resets only the bridge session and preserves the account/mapping ID.
		$identity = $this->mychannels_identity();
		if ( is_wp_error( $identity ) ) { return $this->mychannels_error( 'auth_required', 'Bạn cần đăng nhập để tạo lại mã QR.', 'Đăng nhập vào Twin GPT rồi thử lại.', 'auth_required' ); }
		$gate = $this->mychannels_zalo_personal_gate();
		if ( true !== $gate ) { return $gate; }
		$account = $this->mychannels_zalo_personal_account( $request, $identity, true );
		if ( is_wp_error( $account ) || $account instanceof WP_REST_Response ) { return $account; }
		if ( ! is_array( $account ) || ! class_exists( 'BizCity_Zalo_Bridge_REST' ) ) { return $this->mychannels_error( 'module_not_loaded', 'Tài khoản Zalo Personal chưa sẵn sàng.', 'Tải lại Kênh của tôi rồi thử lại.', 'module_not_loaded' ); }
		return BizCity_Zalo_Bridge_REST::reset_qr_for_owner( $account, (int) $identity['user_id'] );
	}

	public function get_mychannels_zalo_personal_status( WP_REST_Request $request ) {
		// [2026-08-21 Johnny Chu] PHASE-0.39B — owner-scoped QR status polling.
		$identity = $this->mychannels_identity();
		if ( is_wp_error( $identity ) ) { return $this->mychannels_error( 'auth_required', 'Bạn cần đăng nhập để xem trạng thái QR.', 'Đăng nhập vào Twin GPT rồi thử lại.', 'auth_required' ); }
		$gate = $this->mychannels_zalo_personal_gate();
		if ( true !== $gate ) { return $gate; }
		$account = $this->mychannels_zalo_personal_account( $request, $identity, true );
		if ( is_wp_error( $account ) || $account instanceof WP_REST_Response ) { return $account; }
		if ( ! is_array( $account ) || ! class_exists( 'BizCity_Zalo_Bridge_REST' ) ) { return $this->mychannels_error( 'module_not_loaded', 'Tài khoản Zalo Personal chưa sẵn sàng.', 'Tải lại Kênh của tôi rồi thử lại.', 'module_not_loaded' ); }
		return BizCity_Zalo_Bridge_REST::qr_status_for_owner( $account, (int) $identity['user_id'] );
	}

	/** Shape one binding row for /gpt/ (no key, no character editing — A5.3). */
	private function mychannels_zalo_personal_bot_shape( array $account, $binding ): array {
		// [2026-09-23 05:20 PM Claude Fable 5.1] PHASE-0.60A B-12.
		$policy = is_array( $binding ) && class_exists( 'BizCity_Bot_Turn_Claim' ) ? BizCity_Bot_Turn_Claim::decode_office_hours( $binding['office_hours_json'] ?? '' ) : array();
		$first  = isset( $policy['days']['mon'][0] ) && is_array( $policy['days']['mon'][0] ) ? $policy['days']['mon'][0] : array();
		$mode   = is_array( $binding ) ? (string) ( $binding['mode'] ?? 'manual' ) : 'manual';
		$character_name = '';
		if ( is_array( $binding ) && (int) ( $binding['character_id'] ?? 0 ) > 0 && class_exists( 'BizCity_Knowledge_Database' ) ) {
			$character = BizCity_Knowledge_Database::instance()->get_character( (int) $binding['character_id'] );
			$character_name = $character ? (string) ( $character->name ?? '' ) : '';
		}
		return array(
			'account_id'     => (string) ( $account['bridge_account_id'] ?? $account['id'] ?? '' ),
			'configured'     => is_array( $binding ) && (int) ( $binding['character_id'] ?? 0 ) > 0,
			'enabled'        => in_array( $mode, array( 'auto', 'hybrid' ), true ),
			'mode'           => $mode,
			'character_name' => $character_name,
			// [2026-09-30 Claude Opus 5.5] PHASE-0.84 W-08 — step ④ reads which Agent Guru answers this number.
			'character_id'    => is_array( $binding ) ? (int) ( $binding['character_id'] ?? 0 ) : 0,
			'agent_guru_name' => $character_name,
			'office_hours'   => array(
				'enabled' => ! empty( $policy['enabled'] ) && ! empty( $policy['days']['mon'] ),
				'start'   => (string) ( $first['start'] ?? '08:00' ),
				'end'     => (string) ( $first['end'] ?? '17:30' ),
			),
			'pause_on_manual_reply' => ! isset( $policy['pause_on_manual_reply'] ) || ! empty( $policy['pause_on_manual_reply'] ),
		);
	}

	public function get_mychannels_zalo_personal_bot( WP_REST_Request $request ) {
		// [2026-09-23 05:20 PM Claude Fable 5.1] PHASE-0.60A B-12 — owner-scoped read of the bot binding.
		$identity = $this->mychannels_identity();
		if ( is_wp_error( $identity ) ) { return $this->mychannels_error( 'auth_required', 'Bạn cần đăng nhập để xem trợ lý.', 'Đăng nhập vào Twin GPT rồi thử lại.', 'auth_required' ); }
		$gate = $this->mychannels_zalo_personal_gate();
		if ( true !== $gate ) { return $gate; }
		$account = $this->mychannels_zalo_personal_account( $request, $identity );
		if ( is_wp_error( $account ) || $account instanceof WP_REST_Response ) { return $account; }
		if ( ! is_array( $account ) || ! class_exists( 'BizCity_Channel_Binding' ) ) { return $this->mychannels_error( 'module_not_loaded', 'Channel Gateway chưa sẵn sàng.', 'Tải lại Kênh của tôi rồi thử lại.', 'module_not_loaded' ); }
		$bridge_id = (string) ( $account['bridge_account_id'] ?? '' );
		$binding   = BizCity_Channel_Binding::resolve( 'ZALO_PERSONAL', $bridge_id );
		return rest_ensure_response( array( 'success' => true, 'bot' => $this->mychannels_zalo_personal_bot_shape( $account, $binding ) ) );
	}

	public function put_mychannels_zalo_personal_bot( WP_REST_Request $request ) {
		// [2026-09-23 05:20 PM Claude Fable 5.1] PHASE-0.60A B-12 — member may toggle + set hours ONLY on an already-bound account.
		$identity = $this->mychannels_identity();
		if ( is_wp_error( $identity ) ) { return $this->mychannels_error( 'auth_required', 'Bạn cần đăng nhập để bật/tắt trợ lý.', 'Đăng nhập vào Twin GPT rồi thử lại.', 'auth_required' ); }
		$gate = $this->mychannels_zalo_personal_gate();
		if ( true !== $gate ) { return $gate; }
		$account = $this->mychannels_zalo_personal_account( $request, $identity );
		if ( is_wp_error( $account ) || $account instanceof WP_REST_Response ) { return $account; }
		if ( ! is_array( $account ) || ! class_exists( 'BizCity_Channel_Binding' ) ) { return $this->mychannels_error( 'module_not_loaded', 'Channel Gateway chưa sẵn sàng.', 'Tải lại Kênh của tôi rồi thử lại.', 'module_not_loaded' ); }
		$bridge_id = (string) ( $account['bridge_account_id'] ?? '' );
		$binding   = BizCity_Channel_Binding::resolve( 'ZALO_PERSONAL', $bridge_id );
		$body = $request->get_json_params();
		$body = is_array( $body ) ? $body : array();
		// [2026-09-30 Claude Opus 5.5] PHASE-0.84 W-08 D-84-5 — the member may pick the site default or their OWN active Agent Guru; the id is only a selector.
		$requested_character = isset( $body['character_id'] ) ? absint( $body['character_id'] ) : 0;
		if ( isset( $body['character_id'] ) && ! $this->agent_guru_selectable( $requested_character, (int) $identity['user_id'] ) ) {
			return $this->agent_guru_error( 'agent_guru_not_yours', 'Bạn chỉ chọn được Agent Guru mặc định hoặc Agent Guru của chính bạn.', 'Chọn lại trong danh sách "Chọn Agent Guru", hoặc tạo Agent Guru của bạn.', 403 );
		}
		if ( $requested_character <= 0 && ( ! is_array( $binding ) || (int) ( $binding['character_id'] ?? 0 ) <= 0 ) ) {
			return $this->mychannels_error( 'bot_not_configured', 'Số Zalo này chưa được gắn trợ lý.', 'Nhờ quản trị viên gắn trợ lý trong Channel Gateway → Zalo Cá nhân; sau đó bạn bật/tắt ở đây.', 'bot_binding_missing' );
		}
		$exact_binding = is_array( $binding ) && (string) ( $binding['account_id'] ?? '' ) === $bridge_id;
		$policy = class_exists( 'BizCity_Bot_Turn_Claim' ) ? BizCity_Bot_Turn_Claim::decode_office_hours( is_array( $binding ) ? ( $binding['office_hours_json'] ?? '' ) : '' ) : array();
		if ( isset( $body['office_hours'] ) && is_array( $body['office_hours'] ) ) {
			$oh    = $body['office_hours'];
			$start = (string) ( $oh['start'] ?? '08:00' );
			$end   = (string) ( $oh['end'] ?? '17:30' );
			if ( ! preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $start ) || ! preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $end ) ) {
				return $this->mychannels_error( 'invalid_param', 'Giờ trực phải ở dạng HH:MM.', 'Ví dụ 08:00 và 17:30.', 'invalid_param_generic' );
			}
			$policy['enabled'] = ! empty( $oh['enabled'] );
			$policy['days']    = array();
			if ( $policy['enabled'] ) {
				foreach ( array( 'mon', 'tue', 'wed', 'thu', 'fri' ) as $day ) {
					$policy['days'][ $day ] = array( array( 'start' => $start, 'end' => $end ) );
				}
			}
		}
		if ( isset( $body['pause_on_manual_reply'] ) ) {
			$policy['pause_on_manual_reply'] = ! empty( $body['pause_on_manual_reply'] );
		}
		$mode = is_array( $binding ) ? (string) ( $binding['mode'] ?? 'manual' ) : 'auto';
		if ( isset( $body['enabled'] ) ) {
			$mode = ! empty( $body['enabled'] ) ? ( 'hybrid' === $mode ? 'hybrid' : 'auto' ) : 'manual';
		}
		$upsert = array(
			'platform'     => 'ZALO_PERSONAL',
			'account_id'   => $bridge_id,
			'character_id' => $requested_character > 0 ? $requested_character : (int) $binding['character_id'],
			'mode'         => $mode,
			'auto_reply'   => in_array( $mode, array( 'auto', 'hybrid' ), true ) ? 1 : 0,
			'office_hours' => $policy,
		);
		// [2026-09-30 Claude Opus 5.5] PHASE-0.84 W-08 — upsert() rewrites meta/pool/fallback when omitted; carry the exact row's values over.
		if ( $exact_binding ) {
			$meta = json_decode( (string) ( $binding['meta_json'] ?? '' ), true );
			if ( is_array( $meta ) ) {
				$upsert['meta'] = $meta;
			}
			$pool = json_decode( (string) ( $binding['responder_pool_json'] ?? '' ), true );
			if ( is_array( $pool ) && $pool ) {
				$upsert['responder_pool'] = $pool;
			}
			if ( isset( $binding['fallback_assignee'] ) && '' !== (string) $binding['fallback_assignee'] ) {
				$upsert['fallback_assignee'] = (int) $binding['fallback_assignee'];
			}
		}
		$id = BizCity_Channel_Binding::upsert( $upsert );
		if ( $id <= 0 ) {
			return $this->mychannels_error( 'write_failed', 'Không lưu được cấu hình trợ lý.', 'Thử lại; nếu vẫn lỗi hãy liên hệ quản trị viên.', 'bot_binding_write_failed' );
		}
		$binding = BizCity_Channel_Binding::resolve( 'ZALO_PERSONAL', $bridge_id );
		return rest_ensure_response( array( 'success' => true, 'bot' => $this->mychannels_zalo_personal_bot_shape( $account, $binding ) ) );
	}

	/* ── [2026-09-30 Claude Opus 5.5] PHASE-0.84 W-08 D-84-5 — member Agent Guru projection ──────────── */

	/** R-ERROR-UX envelope for the /me/* routes: Vietnamese message + hint, real HTTP status. */
	private function agent_guru_error( $code, $message, $hint, $status ) {
		return new WP_Error( (string) $code, (string) $message, array( 'status' => (int) $status, 'hint' => (string) $hint ) );
	}

	private function agent_guru_default_id(): int {
		if ( ! class_exists( 'BizCity_Guru_Context_Resolver' ) || ! method_exists( 'BizCity_Guru_Context_Resolver', 'default_character_id' ) ) {
			return 0;
		}
		return (int) BizCity_Guru_Context_Resolver::default_character_id( false );
	}

	/** Column whitelist (R-GP-3): id, name, a short description, two flags. Never the instruction, notebooks or other columns. */
	private function agent_guru_item( $character, int $default_id, int $user_id ): array {
		$id          = (int) ( $character->id ?? 0 );
		$description = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( (string) ( $character->description ?? '' ) ) ) );
		if ( function_exists( 'mb_strlen' ) && mb_strlen( $description ) > 140 ) {
			$description = rtrim( mb_substr( $description, 0, 139 ) ) . '…';
		} elseif ( ! function_exists( 'mb_strlen' ) && strlen( $description ) > 140 ) {
			$description = rtrim( substr( $description, 0, 139 ) ) . '…';
		}
		return array(
			'id'         => $id,
			'name'       => sanitize_text_field( (string) ( $character->name ?? '' ) ),
			'summary'    => $description,
			'is_default' => $default_id > 0 && $id === $default_id,
			'is_mine'    => $user_id > 0 && (int) ( $character->author_id ?? 0 ) === $user_id,
		);
	}

	/** Server-side ownership check: the site default, or an active Guru authored by this user. */
	private function agent_guru_selectable( int $character_id, int $user_id ): bool {
		if ( $character_id <= 0 || $user_id <= 0 || ! class_exists( 'BizCity_Knowledge_Database' ) ) {
			return false;
		}
		$default_id = $this->agent_guru_default_id();
		if ( $default_id > 0 && $character_id === $default_id ) {
			return true;
		}
		$character = BizCity_Knowledge_Database::instance()->get_character( $character_id );
		return $character
			&& (int) ( $character->author_id ?? 0 ) === $user_id
			&& 'active' === (string) ( $character->status ?? '' )
			&& self::AGENT_GURU_GLOBAL_SLUG !== (string) ( $character->slug ?? '' );
	}

	/** @return object[] Gurus authored by the user (any status when $status is ''), global memory excluded. */
	private function agent_guru_owned( int $user_id, string $status ): array {
		if ( $user_id <= 0 || ! class_exists( 'BizCity_Knowledge_Database' ) ) {
			return array();
		}
		$rows = BizCity_Knowledge_Database::instance()->get_characters( array(
			'author_id' => $user_id,
			'status'    => $status,
			'orderby'   => 'created_at',
			'order'     => 'DESC',
			'limit'     => 100,
		) );
		$out = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( is_object( $row ) && self::AGENT_GURU_GLOBAL_SLUG !== (string) ( $row->slug ?? '' ) ) {
				$out[] = $row;
			}
		}
		return $out;
	}

	/**
	 * GET /me/agent-gurus → { success, items:[{ id, name, summary, is_default, is_mine }] }
	 * Site default Agent Guru first, then the active Gurus the current user owns.
	 */
	public function get_me_agent_gurus( WP_REST_Request $request ) {
		unset( $request );
		$identity = $this->mychannels_identity();
		if ( is_wp_error( $identity ) ) {
			return $this->agent_guru_error( 'auth_required', 'Bạn cần đăng nhập để xem Agent Guru.', 'Đăng nhập vào Twin GPT rồi thử lại.', 401 );
		}
		if ( ! class_exists( 'BizCity_Knowledge_Database' ) ) {
			return $this->agent_guru_error( 'module_not_loaded', 'Agent Guru chưa sẵn sàng trên website này.', 'Tải lại trang; nếu vẫn lỗi hãy báo quản trị viên website.', 503 );
		}
		$user_id    = (int) $identity['user_id'];
		$default_id = $this->agent_guru_default_id();
		// R-S4-14: step ④ must always have the default Agent Guru; create/adopt it here when the site has none (idempotent, locked).
		if ( 0 === $default_id && class_exists( 'BizCity_Guru_Context_Resolver' ) && method_exists( 'BizCity_Guru_Context_Resolver', 'ensure_default' ) ) {
			$ensured    = BizCity_Guru_Context_Resolver::ensure_default();
			$default_id = is_int( $ensured ) ? $ensured : 0;
		}
		$items      = array();
		if ( $default_id > 0 ) {
			$default = BizCity_Knowledge_Database::instance()->get_character( $default_id );
			if ( $default ) {
				$items[] = $this->agent_guru_item( $default, $default_id, $user_id );
			}
		}
		foreach ( $this->agent_guru_owned( $user_id, 'active' ) as $row ) {
			if ( (int) $row->id === $default_id ) {
				continue;
			}
			$items[] = $this->agent_guru_item( $row, $default_id, $user_id );
		}
		return rest_ensure_response( array( 'success' => true, 'items' => $items ) );
	}

	/**
	 * POST /me/agent-gurus { name, role? } → { success, item }
	 * Quick-create a personal Agent Guru: author = current user, active (a draft cannot be bound — R-GCB-7), private.
	 */
	public function create_me_agent_guru( WP_REST_Request $request ) {
		$identity = $this->mychannels_identity();
		if ( is_wp_error( $identity ) ) {
			return $this->agent_guru_error( 'auth_required', 'Bạn cần đăng nhập để tạo Agent Guru.', 'Đăng nhập vào Twin GPT rồi thử lại.', 401 );
		}
		if ( ! class_exists( 'BizCity_Knowledge_Database' ) ) {
			return $this->agent_guru_error( 'module_not_loaded', 'Agent Guru chưa sẵn sàng trên website này.', 'Tải lại trang; nếu vẫn lỗi hãy báo quản trị viên website.', 503 );
		}
		$body = $request->get_json_params();
		$body = is_array( $body ) ? $body : array();
		$name = trim( sanitize_text_field( (string) ( $body['name'] ?? '' ) ) );
		$role = trim( sanitize_textarea_field( (string) ( $body['role'] ?? '' ) ) );
		$len  = function_exists( 'mb_strlen' ) ? 'mb_strlen' : 'strlen';
		if ( '' === $name ) {
			return $this->agent_guru_error( 'invalid_param', 'Tên Agent Guru không được để trống.', 'Nhập một tên ngắn, ví dụ "Trợ lý bán hàng của Hà".', 422 );
		}
		if ( $len( $name ) > 80 ) {
			return $this->agent_guru_error( 'invalid_param', 'Tên Agent Guru dài quá 80 ký tự.', 'Rút gọn tên rồi lưu lại.', 422 );
		}
		if ( $len( $role ) > 1000 ) {
			return $this->agent_guru_error( 'invalid_param', 'Mô tả vai trò dài quá 1000 ký tự.', 'Viết ngắn gọn vai trò trong vài câu rồi lưu lại.', 422 );
		}
		$user_id = (int) $identity['user_id'];
		if ( count( $this->agent_guru_owned( $user_id, '' ) ) >= self::AGENT_GURU_PERSONAL_LIMIT ) {
			return $this->agent_guru_error( 'agent_guru_limit', sprintf( 'Bạn đã có %d Agent Guru — mức tối đa cho mỗi người.', self::AGENT_GURU_PERSONAL_LIMIT ), 'Chọn một Agent Guru có sẵn, hoặc nhờ quản trị viên xoá bớt Agent Guru không dùng.', 409 );
		}
		$data = array(
			'name'       => $name,
			'slug'       => 'agent-guru-' . $user_id . '-' . strtolower( wp_generate_password( 8, false, false ) ),
			'status'     => 'active',
			'author_id'  => $user_id,
			'visibility' => 'private',
		);
		if ( '' !== $role ) {
			$data['system_prompt'] = $role;
		}
		$db     = BizCity_Knowledge_Database::instance();
		$result = $db->create_character( $data );
		if ( is_wp_error( $result ) || (int) $result <= 0 ) {
			return $this->agent_guru_error( 'agent_guru_create_failed', 'Chưa tạo được Agent Guru.', 'Thử lại sau ít phút; nếu vẫn lỗi hãy báo quản trị viên website.', 500 );
		}
		$character = $db->get_character( (int) $result );
		if ( ! $character ) {
			return $this->agent_guru_error( 'agent_guru_create_failed', 'Chưa tạo được Agent Guru.', 'Tải lại danh sách rồi thử lại.', 500 );
		}
		return new WP_REST_Response( array(
			'success' => true,
			'item'    => $this->agent_guru_item( $character, $this->agent_guru_default_id(), $user_id ),
		), 201 );
	}

	/* ── [2026-09-30 Claude Opus 5.5] PHASE-0.84 W-09 D-84-14 — "Nhờ quản trị viên" (R-LM-8: admin's bound Zalo Bot only) ── */

	public function delete_mychannels_zalo_personal_account( WP_REST_Request $request ) {
		// [2026-08-21 Johnny Chu] PHASE-0.39B — owner-scoped Personal disconnect.
		$identity = $this->mychannels_identity();
		if ( is_wp_error( $identity ) ) { return $this->mychannels_error( 'auth_required', 'Bạn cần đăng nhập để ngắt Zalo Cá nhân.', 'Đăng nhập vào Twin GPT rồi thử lại.', 'auth_required' ); }
		$gate = $this->mychannels_zalo_personal_gate();
		if ( true !== $gate ) { return $gate; }
		$account = $this->mychannels_zalo_personal_account( $request, $identity );
		if ( is_wp_error( $account ) || $account instanceof WP_REST_Response ) { return $account; }
		if ( ! is_array( $account ) || ! class_exists( 'BizCity_Zalo_Bridge_REST' ) ) { return $this->mychannels_error( 'module_not_loaded', 'Tài khoản Zalo Personal chưa sẵn sàng.', 'Tải lại Kênh của tôi rồi thử lại.', 'module_not_loaded' ); }
		return BizCity_Zalo_Bridge_REST::delete_account_for_owner( $account, (int) $identity['user_id'] );
	}

	/** Resolve Personal CRM inboxes inside the current tenant and user policy. */
	private function mychannels_zalo_personal_inbox_ids( int $user_id, bool $connected_only = false ): array {
		// [2026-08-22 Johnny Chu] R-TWEB-4 — derive scope from current tenant CRM rows, never from Hub or browser input.
		if ( $user_id <= 0 || ! class_exists( 'BizCity_CRM_Repository' ) || ! class_exists( 'BizCity_CRM_Inbox_Access' ) ) {
			return array();
		}
		// [2026-08-29 Johnny Chu] PHASE-0-RULE-TWIN-GPT-FIRST-USER-ID-PII-SURFACE — C /gpt/ never inherits tenant-wide admin inbox scope.
		$c_scope = BizCity_CRM_Inbox_Access::resolve_scope( $user_id, 'c' );
		$allowed = isset( $c_scope['inbox_ids'] ) && is_array( $c_scope['inbox_ids'] ) ? $c_scope['inbox_ids'] : array();
		$ids = array();
		foreach ( BizCity_CRM_Repository::list_inboxes() as $inbox ) {
			$inbox_id = (int) ( $inbox['id'] ?? 0 );
			if ( $inbox_id > 0 && (string) ( $inbox['channel_type'] ?? '' ) === 'zalo_personal' && ( null === $allowed || in_array( $inbox_id, $allowed, true ) ) ) {
				if ( $connected_only && ! $this->mychannels_zalo_personal_inbox_connected( $inbox_id, $user_id ) ) { continue; }
				$ids[] = $inbox_id;
			}
		}
		return array_values( array_unique( $ids ) );
	}

	private function shape_mychannels_zalo_personal_conversation( array $row, bool $resolve_group_name = false ): array {
		// [2026-08-29 Johnny Chu] PHASE-0-RULE-TWIN-GPT-FIRST-USER-ID-PII-SURFACE — return a C-safe conversation DTO, never the CRM admin serializer.
		// [2026-09-08 09:10 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.41-W7/V1 — expose only bounded priority/assignment/label cues for Inbox row parity; do not expose staff PII or widen ACL.
		$labels_raw = trim( (string) ( $row['cached_label_list'] ?? '' ) );
		$labels     = $labels_raw === '' ? array() : array_slice( array_values( array_filter( array_map( 'sanitize_text_field', explode( ',', $labels_raw ) ) ) ), 0, 3 );
		$source_id = (string) ( $row['source_id'] ?? '' );
		$is_group = 0 === strpos( $source_id, 'group:' );
		$contact_attributes = json_decode( (string) ( $row['contact_attributes'] ?? '' ), true );
		$group_name = $is_group ? sanitize_text_field( (string) ( $row['contact_name'] ?? '' ) ) : '';
		if ( $is_group && is_array( $contact_attributes ) ) {
			// [2026-09-08 04:15 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.48-CX2 — recover provider-supplied group label from canonical contact metadata without returning provider IDs.
			$group_name = sanitize_text_field( (string) ( $contact_attributes['group_name'] ?? $contact_attributes['display_name'] ?? $group_name ) );
		}
		$group_key = $is_group ? substr( hash_hmac( 'sha256', 'group|' . $source_id, wp_salt( 'auth' ) ), 0, 32 ) : '';
		// [2026-09-17 11:20 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.48C-CX2 — when the CRM contact has no stored group label, resolve it once from the provider and persist it so the C title matches /crm/.
		if ( $is_group && $group_name === '' && $resolve_group_name && class_exists( 'BizCity_Zalo_Bridge_Client' ) ) {
			$group_name = $this->resolve_mychannels_group_name( $row );
		}
		$avatar_url = ! empty( $row['contact_avatar'] ) ? esc_url_raw( (string) $row['contact_avatar'] ) : '';
		// [2026-09-17 Johnny Chu - Chu Hoàng Anh] PHASE-0.48C-AVATAR — lazily pull the Zalo avatar when a conversation is opened and persist it on the canonical contact.
		if ( $avatar_url === '' && $resolve_group_name && 'zalo_personal' === sanitize_key( (string) ( $row['channel_type'] ?? 'zalo_personal' ) ) && class_exists( 'BizCity_Zalo_Bridge_Client' ) ) {
			$avatar_url = $this->resolve_mychannels_contact_avatar( $row, $is_group );
		}
		// [2026-10-09 03:47 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.95-S95-C9 — crm_signal read projection (groups never carry signals).
		$signal = ! $is_group && class_exists( 'BizCity_CRM_Contact_Signals' )
			? BizCity_CRM_Contact_Signals::project( $row['contact_tags_json'] ?? '', is_array( $contact_attributes ) ? $contact_attributes : array(), true )
			: array( 'signal_tags' => array(), 'heat_score' => null, 'signals' => array() );
		return array(
			'id'               => (int) ( $row['id'] ?? 0 ),
			'inbox_id'         => (int) ( $row['inbox_id'] ?? 0 ),
			// [2026-09-15 Johnny Chu - Chu Hoàng Anh] PHASE-0.48C-CRM-CONTEXT — expose the canonical contact reference needed by the scoped order-draft owner; this is not an ACL input.
			'contact_id'       => (int) ( $row['contact_id'] ?? 0 ),
			'status'           => sanitize_key( (string) ( $row['status'] ?? '' ) ),
			// [PHASE-0.54 D54-3] the Hoãn control in the right column needs to know if it's already snoozed.
			'is_snoozed'       => isset( $row['snoozed_until'] ) && null !== $row['snoozed_until'] && (int) $row['snoozed_until'] > time(),
			'snoozed_until'    => isset( $row['snoozed_until'] ) && null !== $row['snoozed_until'] ? (int) $row['snoozed_until'] : null,
			'priority'         => (int) ( $row['priority'] ?? 0 ),
			'assignee_present' => ! empty( $row['assignee_id'] ),
			'team_present'     => ! empty( $row['team_id'] ),
			'labels'           => $labels,
			'unread_count'     => (int) ( $row['unread_count'] ?? 0 ),
			'last_activity_at' => $row['last_activity_at'] ?? null,
			'last_message'     => isset( $row['last_message_content'] ) ? array(
				'content'      => (string) ( $row['last_message_content'] ?? '' ),
				'message_type' => sanitize_key( (string) ( $row['last_message_type'] ?? '' ) ),
				'sender_type'  => sanitize_key( (string) ( $row['last_sender_type'] ?? '' ) ),
				'created_at'   => $row['last_message_at'] ?? null,
			) : null,
			'contact'          => array(
				'name'       => $group_name !== '' ? $group_name : sanitize_text_field( (string) ( $row['contact_name'] ?? '' ) ),
				'group_name' => $group_name !== '' ? $group_name : ( $is_group ? 'Nhóm Zalo' : null ),
				'group_key'  => $group_key !== '' ? $group_key : null,
				'is_group'   => $is_group,
				'thread_kind'=> $is_group ? 'group' : 'personal',
				'avatar_url' => $avatar_url !== '' ? $avatar_url : null,
			),
			'channel'          => sanitize_key( (string) ( $row['channel_type'] ?? 'zalo_personal' ) ),
			// [2026-10-09 03:47 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.95-S95-C9 — signal_tags (nhiet:/cam_xuc:/y_dinh: only), heat_score and the 5 newest signals (evidence masked at write) for chips + contact card.
			'signal_tags'      => $signal['signal_tags'],
			'heat_score'       => $signal['heat_score'],
			'signals'          => $signal['signals'],
			'c_surface_safe'   => true,
		);
	}

	/**
	 * Resolve the provider group label for one CRM source_id and persist it on the canonical contact.
	 *
	 * [2026-09-17 11:20 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.48C-CX2 — the provider label is read
	 * through the server-side bridge and stored via the canonical CRM contact writer; failures stay
	 * degraded and never widen the C scope.
	 */
	/**
	 * Fetch a Zalo avatar (user profile or group) through the bridge and store it on bizcity_crm_contacts.avatar_url.
	 * Throttled per contact so bridges without the profile route (or live session) are not hammered on every open.
	 * [2026-09-17 Johnny Chu - Chu Hoàng Anh] PHASE-0.48C-AVATAR.
	 */
	private function resolve_mychannels_contact_avatar( array $row, bool $is_group ): string {
		$contact_id = (int) ( $row['contact_id'] ?? 0 );
		$inbox_id   = (int) ( $row['inbox_id'] ?? 0 );
		$source_id  = (string) ( $row['source_id'] ?? '' );
		if ( $contact_id <= 0 || $inbox_id <= 0 || $source_id === '' || ! class_exists( 'BizCity_CRM_Repository' ) ) { return ''; }
		$throttle_key = 'bizcity_twinweb_avatar_probe_' . get_current_blog_id() . '_' . $contact_id;
		if ( get_transient( $throttle_key ) ) { return ''; }
		set_transient( $throttle_key, 1, 6 * HOUR_IN_SECONDS );
		$inbox = BizCity_CRM_Repository::get_inbox( $inbox_id );
		$account_id = is_array( $inbox ) ? (string) ( $inbox['channel_ref_id'] ?? '' ) : '';
		if ( $account_id === '' ) { return ''; }
		$client = BizCity_Zalo_Bridge_Client::instance();
		if ( $is_group ) {
			$result = $client->get_group_name( $account_id, substr( $source_id, 6 ) );
		} else {
			$user_id = preg_replace( '/^(user|zalo|zp):/', '', $source_id );
			if ( ! is_string( $user_id ) || ! preg_match( '/^\d{5,32}$/', $user_id ) || ! method_exists( $client, 'get_user_profile' ) ) { return ''; }
			$result = $client->get_user_profile( $account_id, $user_id );
		}
		if ( ! is_array( $result ) || empty( $result['success'] ) || ! empty( $result['_degraded'] ) ) { return ''; }
		$avatar = esc_url_raw( (string) ( $result['avatar_url'] ?? '' ) );
		if ( $avatar === '' || ! wp_http_validate_url( $avatar ) ) { return ''; }
		global $wpdb;
		$wpdb->update( BizCity_CRM_DB_Installer_V2::tbl_contacts(), array( 'avatar_url' => $avatar, 'updated_at' => current_time( 'mysql' ) ), array( 'id' => $contact_id ), array( '%s', '%s' ), array( '%d' ) );
		if ( method_exists( 'BizCity_CRM_Repository', 'invalidate_read_models' ) ) { BizCity_CRM_Repository::invalidate_read_models(); }
		return $avatar;
	}

	private function resolve_mychannels_group_name( array $row ): string {
		$source_id = (string) ( $row['source_id'] ?? '' );
		$group_id  = 0 === strpos( $source_id, 'group:' ) ? substr( $source_id, 6 ) : '';
		$inbox_id  = (int) ( $row['inbox_id'] ?? 0 );
		if ( $group_id === '' || $inbox_id <= 0 || ! class_exists( 'BizCity_CRM_Repository' ) ) { return ''; }
		$inbox = BizCity_CRM_Repository::get_inbox( $inbox_id );
		$account_id = is_array( $inbox ) ? (string) ( $inbox['channel_ref_id'] ?? '' ) : '';
		if ( $account_id === '' ) { return ''; }
		$result = BizCity_Zalo_Bridge_Client::instance()->get_group_name( $account_id, $group_id );
		if ( ! is_array( $result ) || ! empty( $result['_degraded'] ) || empty( $result['success'] ) ) { return ''; }
		$group_name = sanitize_text_field( (string) ( $result['group_name'] ?? '' ) );
		if ( $group_name === '' ) { return ''; }
		$contact_id = (int) ( $row['contact_id'] ?? 0 );
		if ( $contact_id <= 0 ) { return $group_name; }
		$contact = BizCity_CRM_Repository::get_contact( $contact_id );
		$attributes = is_array( $contact ) ? json_decode( (string) ( $contact['additional_attributes'] ?? '' ), true ) : array();
		$attributes = is_array( $attributes ) ? $attributes : array();
		if ( (string) ( $attributes['group_name'] ?? '' ) === $group_name ) { return $group_name; }
		$attributes['group_name'] = $group_name;
		global $wpdb;
		$wpdb->update(
			BizCity_CRM_DB_Installer_V2::tbl_contacts(),
			array( 'additional_attributes' => wp_json_encode( $attributes ) ),
			array( 'id' => $contact_id ),
			array( '%s' ),
			array( '%d' )
		);
		return $group_name;
	}

	private function shape_mychannels_zalo_personal_message( array $row ): array {
		// [2026-08-29 Johnny Chu] PHASE-0-RULE-TWIN-GPT-FIRST-USER-ID-PII-SURFACE — omit provider IDs, responder metadata, delivery payload and admin URLs from C messages.
		// [2026-09-17 10:20 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.48C-CRM-CONTEXT — C message parity with /crm/: surface the group sender name, real delivery state, reply preview and media attachments instead of a text-only DTO.
		$delivery = is_array( $row['delivery'] ?? null ) ? $row['delivery'] : array();
		if ( empty( $delivery ) && ! empty( $row['payload_json'] ) ) {
			$payload = json_decode( (string) $row['payload_json'], true );
			if ( is_array( $payload ) && is_array( $payload['delivery'] ?? null ) ) { $delivery = $payload['delivery']; }
		}
		$ai = array();
		if ( ! empty( $row['ai_metadata_json'] ) ) {
			$decoded = json_decode( (string) $row['ai_metadata_json'], true );
			if ( is_array( $decoded ) ) { $ai = $decoded; }
		}
		$thread_kind = sanitize_key( (string) ( $ai['thread_kind'] ?? '' ) );
		$sender_name = 'group' === $thread_kind ? sanitize_text_field( (string) ( $ai['sender_name'] ?? '' ) ) : '';
		$attachments = array();
		foreach ( (array) ( $row['attachments'] ?? array() ) as $attachment ) {
			if ( ! is_array( $attachment ) ) { continue; }
			$url = esc_url_raw( (string) ( $attachment['data_url'] ?? '' ) );
			if ( $url === '' ) { continue; }
			$meta = json_decode( (string) ( $attachment['meta_json'] ?? '' ), true );
			$attachments[] = array(
				'id'        => (int) ( $attachment['id'] ?? 0 ),
				'file_type' => sanitize_key( (string) ( $attachment['file_type'] ?? 'file' ) ),
				'url'       => $url,
				'thumb_url' => ! empty( $attachment['thumb_url'] ) ? esc_url_raw( (string) $attachment['thumb_url'] ) : null,
				'name'      => is_array( $meta ) ? sanitize_text_field( (string) ( $meta['file_name'] ?? $meta['name'] ?? '' ) ) : '',
			);
		}
		$reply_to = array();
		if ( is_array( $ai['reply_to'] ?? null ) ) {
			$reply_name    = sanitize_text_field( (string) ( $ai['reply_to']['sender_name'] ?? '' ) );
			$reply_content = sanitize_text_field( (string) ( $ai['reply_to']['content'] ?? '' ) );
			if ( $reply_name !== '' || $reply_content !== '' ) {
				$reply_to = array( 'sender_name' => $reply_name, 'content' => $reply_content );
			}
		}
		return array(
			'id'              => (int) ( $row['id'] ?? 0 ),
			'conversation_id' => (int) ( $row['conversation_id'] ?? 0 ),
			'content'         => (string) ( $row['content'] ?? '' ),
			'content_type'    => sanitize_key( (string) ( $row['content_type'] ?? 'text' ) ),
			'message_type'    => sanitize_key( (string) ( $row['message_type'] ?? '' ) ),
			'sender_type'     => sanitize_key( (string) ( $row['sender_type'] ?? '' ) ),
			'status'          => sanitize_key( (string) ( $row['status'] ?? '' ) ),
			'created_at'      => $row['created_at'] ?? null,
			'thread_kind'     => $thread_kind,
			'sender_name'     => $sender_name,
			'attachments'     => $attachments,
			'reply_to'        => $reply_to ? $reply_to : null,
			'delivery'        => array(
				'sent'        => ! empty( $delivery['sent'] ),
				'outcome'     => sanitize_key( (string) ( $delivery['outcome'] ?? ( ! empty( $row['status'] ) ? $row['status'] : 'unknown' ) ) ),
				'reason_code' => sanitize_key( (string) ( $delivery['reason_code'] ?? '' ) ),
			),
			'c_surface_safe'  => true,
		);
	}

	private function mychannels_zalo_personal_inbox_connected( int $inbox_id, int $user_id ): bool {
		// [2026-08-22 Johnny Chu] PHASE-0.39B-W11 — outbound may use only a connected Personal account owned by this user or tenant admin.
		if ( $inbox_id <= 0 || ! class_exists( 'BizCity_Zalo_Mapping_Repo' ) ) { return false; }
		$rows = BizCity_Zalo_Mapping_Repo::list_personal_accounts_for_owner( $user_id );
		foreach ( $rows as $row ) {
			if ( (int) ( $row['crm_inbox_id'] ?? 0 ) === $inbox_id && (string) ( $row['status'] ?? '' ) === 'connected' ) { return true; }
		}
		return false;
	}

	public function get_mychannels_zalo_personal_conversations( WP_REST_Request $request ) {
		// [2026-08-22 Johnny Chu] PHASE-0.39B-W11 — expose Personal CRM list through same-origin tenant SQL with owner/inbox scope.
		$identity = $this->mychannels_identity();
		if ( is_wp_error( $identity ) ) { return $this->mychannels_error( 'auth_required', 'Bạn cần đăng nhập để xem Inbox Zalo Personal.', 'Đăng nhập vào Twin GPT rồi thử lại.', 'auth_required' ); }
		$gate = $this->mychannels_zalo_personal_gate();
		if ( true !== $gate ) { return $gate; }
		if ( ! class_exists( 'BizCity_CRM_Repository' ) ) { return $this->mychannels_error( 'module_not_loaded', 'CRM Inbox chưa sẵn sàng.', 'Bật module BizCity Twin CRM rồi thử lại.', 'module_not_loaded' ); }
		$inbox_ids = $this->mychannels_zalo_personal_inbox_ids( (int) $identity['user_id'] );
		if ( empty( $inbox_ids ) ) { return rest_ensure_response( array( 'success' => true, 'items' => array(), 'inbox_ids' => array() ) ); }
		$status = sanitize_key( (string) $request->get_param( 'status' ) );
		$args = array(
			'inbox_ids' => $inbox_ids,
			'limit'     => max( 1, min( 100, (int) ( $request->get_param( 'limit' ) ?: 50 ) ) ),
			'before_id' => (int) $request->get_param( 'before_id' ),
		);
		if ( in_array( $status, array( 'open', 'pending', 'resolved', 'snoozed' ), true ) ) { $args['status'] = $status; }
		$rows = BizCity_CRM_Repository::list_conversations( $args );
		$items = array_map( array( $this, 'shape_mychannels_zalo_personal_conversation' ), $rows );
		return rest_ensure_response( array( 'success' => true, 'items' => $items, 'inbox_ids' => $inbox_ids ) );
	}

	public function get_mychannels_zalo_personal_conversation( WP_REST_Request $request ) {
		// [2026-08-22 Johnny Chu] PHASE-0.39B-W11 — detail lookup repeats the Personal inbox scope to prevent conversation ID guessing.
		$identity = $this->mychannels_identity();
		if ( is_wp_error( $identity ) ) { return $this->mychannels_error( 'auth_required', 'Bạn cần đăng nhập để xem hội thoại.', 'Đăng nhập vào Twin GPT rồi thử lại.', 'auth_required' ); }
		$gate = $this->mychannels_zalo_personal_gate();
		if ( true !== $gate ) { return $gate; }
		$id = (int) $request->get_param( 'id' );
		$inbox_ids = $this->mychannels_zalo_personal_inbox_ids( (int) $identity['user_id'] );
		$rows = class_exists( 'BizCity_CRM_Repository' ) && $id > 0 && ! empty( $inbox_ids ) ? BizCity_CRM_Repository::list_conversations( array( 'id' => $id, 'inbox_ids' => $inbox_ids, 'limit' => 1 ) ) : array();
		if ( empty( $rows ) ) { return $this->mychannels_error( 'not_found', 'Không tìm thấy hội thoại Zalo Personal.', 'Chọn hội thoại trong Inbox của bạn rồi thử lại.', 'not_found' ); }
		$item = $this->shape_mychannels_zalo_personal_conversation( $rows[0], true );
		return rest_ensure_response( array( 'success' => true, 'conversation' => $item ) );
	}

	public function get_mychannels_zalo_personal_messages( WP_REST_Request $request ) {
		// [2026-08-22 Johnny Chu] PHASE-0.39B-W11 — message lookup repeats conversation ownership before reading tenant messages.
		$identity = $this->mychannels_identity();
		if ( is_wp_error( $identity ) ) { return $this->mychannels_error( 'auth_required', 'Bạn cần đăng nhập để xem tin nhắn.', 'Đăng nhập vào Twin GPT rồi thử lại.', 'auth_required' ); }
		$gate = $this->mychannels_zalo_personal_gate();
		if ( true !== $gate ) { return $gate; }
		$id = (int) $request->get_param( 'id' );
		$inbox_ids = $this->mychannels_zalo_personal_inbox_ids( (int) $identity['user_id'] );
		$conversation = class_exists( 'BizCity_CRM_Repository' ) && $id > 0 ? BizCity_CRM_Repository::list_conversations( array( 'id' => $id, 'inbox_ids' => $inbox_ids, 'limit' => 1 ) ) : array();
		if ( empty( $conversation ) ) { return $this->mychannels_error( 'not_found', 'Không tìm thấy hội thoại Zalo Personal.', 'Chọn hội thoại trong Inbox của bạn rồi thử lại.', 'not_found' ); }
		$limit = max( 1, min( 100, (int) ( $request->get_param( 'limit' ) ?: 100 ) ) );
		$after_id = max( 0, (int) $request->get_param( 'after_id' ) );
		$rows = BizCity_CRM_Repository::list_messages( $id, $limit, $after_id );
		$items = array_map( array( $this, 'shape_mychannels_zalo_personal_message' ), $rows );
		return rest_ensure_response( array( 'success' => true, 'items' => $items, 'conversation_id' => $id ) );
	}

	public function send_mychannels_zalo_personal_message( WP_REST_Request $request ) {
		// [2026-08-22 Johnny Chu] PHASE-0.39B-W11 — delegate Personal outbound to canonical CRM adapter after owner/inbox scope validation.
		$identity = $this->mychannels_identity();
		if ( is_wp_error( $identity ) ) { return $this->mychannels_error( 'auth_required', 'Bạn cần đăng nhập để gửi tin nhắn.', 'Đăng nhập vào Twin GPT rồi thử lại.', 'auth_required' ); }
		$gate = $this->mychannels_zalo_personal_gate();
		if ( true !== $gate ) { return $gate; }
		$id = (int) $request->get_param( 'id' );
		$inbox_ids = $this->mychannels_zalo_personal_inbox_ids( (int) $identity['user_id'], true );
		$conversation = class_exists( 'BizCity_CRM_Repository' ) && $id > 0 ? BizCity_CRM_Repository::list_conversations( array( 'id' => $id, 'inbox_ids' => $inbox_ids, 'limit' => 1 ) ) : array();
		if ( empty( $conversation ) ) { return $this->mychannels_error( 'not_found', 'Không tìm thấy hội thoại Zalo Personal.', 'Chọn hội thoại trong Inbox của bạn rồi thử lại.', 'not_found' ); }
		$body = $request->get_json_params();
		$body = is_array( $body ) ? $body : array();
		$content = trim( (string) ( $body['content'] ?? '' ) );
		$reply_to = absint( $body['reply_to'] ?? 0 );
		$attachment_ids = array_slice( $this->sanitize_attachment_ids( (array) ( $body['attachment_ids'] ?? array() ) ), 0, 1 );
		$owned_attachments = $this->build_owned_attachment_payload( $attachment_ids, (int) $identity['user_id'] );
		if ( is_wp_error( $owned_attachments ) ) { return $this->mychannels_error( 'permission_denied', 'Tệp đính kèm không thuộc tài khoản của bạn.', 'Chọn lại tệp đã tải lên từ tài khoản hiện tại.', 'attachment_not_owned' ); }
		if ( $content === '' && empty( $owned_attachments ) ) { return $this->mychannels_error( 'invalid_param', 'Nội dung tin nhắn không được để trống.', 'Nhập nội dung hoặc chọn một tệp rồi thử lại.', 'invalid_param_generic' ); }
		if ( strlen( $content ) > 10000 ) { return $this->mychannels_error( 'invalid_param', 'Tin nhắn vượt quá độ dài cho phép.', 'Rút ngắn nội dung rồi gửi lại.', 'invalid_param_generic' ); }
		if ( ! class_exists( 'BizCity_CRM_REST_Controller' ) ) { return $this->mychannels_error( 'module_not_loaded', 'CRM Inbox chưa sẵn sàng để gửi tin.', 'Bật module BizCity Twin CRM rồi thử lại.', 'module_not_loaded' ); }
		// [2026-09-12 10:05 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.48C-OUTBOUND — claim one durable mutation key before CRM insert/provider dispatch so attachment retries cannot send twice.
		$idempotency_key = sanitize_text_field( (string) ( $request->get_header( 'x-bizcity-idempotency-key' ) ?: ( $body['idempotency_key'] ?? '' ) ) );
		if ( strlen( $idempotency_key ) < 16 || strlen( $idempotency_key ) > 190 ) {
			return $this->mychannels_error( 'invalid_param', 'Thiếu mã idempotency cho tin nhắn.', 'Tạo mã thao tác mới rồi thử lại.', 'invalid_param_generic' );
		}
		if ( ! class_exists( 'BizCity_Twin_Mutation_Store' ) ) {
			return $this->mychannels_error( 'module_not_loaded', 'Bộ chống gửi trùng chưa sẵn sàng.', 'Bật lớp an toàn gửi tin rồi thử lại.', 'module_not_loaded' );
		}
		$mutation = array(
			'action'          => 'crm_message.outbound',
			'idempotency_key' => $idempotency_key,
			'resource'        => array( 'scope' => 'conversation:' . $id ),
			'trace_id'        => (string) ( $request->get_header( 'x-bizcity-trace-id' ) ?: '' ),
		);
		$request_hash = md5( wp_json_encode( array( $id, $content, $reply_to, $attachment_ids ) ) );
		$mutation_state = BizCity_Twin_Mutation_Store::begin( $mutation, array( 'blog_id' => (int) get_current_blog_id(), 'user_id' => (int) $identity['user_id'] ), $request_hash );
		if ( 'conflict' === (string) ( $mutation_state['status'] ?? '' ) ) {
			return $this->mychannels_error( 'invalid_param', 'Mã idempotency đã dùng cho dữ liệu khác.', 'Tạo mã thao tác mới rồi thử lại.', 'invalid_param_generic' );
		}
		if ( 'pending' === (string) ( $mutation_state['status'] ?? '' ) ) {
			return $this->mychannels_error( 'invalid_param', 'Tin nhắn đang được xử lý.', 'Đợi kết quả gửi hiện tại rồi thử lại.', 'invalid_param_generic' );
		}
		if ( 'replay' === (string) ( $mutation_state['status'] ?? '' ) ) {
			$replayed = (array) ( $mutation_state['response'] ?? array() );
			$replayed['idempotency_replayed'] = true;
			return rest_ensure_response( $replayed );
		}
		$crm_request = new WP_REST_Request( 'POST', '/bizcity-crm/v1/conversations/' . $id . '/messages' );
		$crm_request->set_url_params( array( 'id' => $id ) );
		$crm_request->set_header( 'Content-Type', 'application/json' );
		// [2026-09-09 09:20 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.48-CX3 — forward only the selected message ID; CRM revalidates that it belongs to this exact conversation.
		$crm_attachments = array();
		foreach ( $owned_attachments as $attachment ) {
			$mime = strtolower( (string) ( $attachment['mime_type'] ?? '' ) );
			$crm_attachments[] = array(
				'file_type' => 0 === strpos( $mime, 'image/' ) ? 'image' : 'file',
				'data_url'  => esc_url_raw( (string) ( $attachment['url'] ?? '' ) ),
				'thumb_url' => esc_url_raw( (string) ( $attachment['url'] ?? '' ) ),
				'size'      => (int) ( $attachment['size'] ?? 0 ),
				// [2026-09-16 Johnny Chu - Chu Hoàng Anh] PHASE-0.48C-SHEET-UNIFY W4 — CRM post_message reads name/mime at top level; nested-only meta made every file fail as attachment_mime_not_allowed.
				'name'      => sanitize_file_name( (string) ( $attachment['filename'] ?? '' ) ),
				'mime'      => sanitize_mime_type( $mime ),
				'meta'      => array(
					'name' => sanitize_file_name( (string) ( $attachment['filename'] ?? '' ) ),
					'mime' => sanitize_mime_type( $mime ),
				),
			);
		}
		$crm_request->set_body( wp_json_encode( array( 'content' => $content, 'content_type' => empty( $crm_attachments ) ? 'text' : $crm_attachments[0]['file_type'], 'attachments' => $crm_attachments, 'responder_kind' => 'manual', 'reply_to' => $reply_to ) ) );
		$crm_response = BizCity_CRM_REST_Controller::post_message( $crm_request );
		if ( $crm_response instanceof WP_REST_Response ) {
			$wrapped = $crm_response->get_data();
			if ( $crm_response->get_status() >= 400 || ! is_array( $wrapped ) || false === ( $wrapped['ok'] ?? false ) ) {
				BizCity_Twin_Mutation_Store::release( (string) ( $mutation_state['key'] ?? '' ) );
				return $this->mychannels_error( 'crm_projection_failed', 'Chưa gửi được tin nhắn.', 'Kiểm tra kết nối kênh rồi thử lại.', 'gateway_degraded' );
			}
			$payload = is_array( $wrapped['data'] ?? null ) ? $wrapped['data'] : $wrapped;
			if ( isset( $payload['message'] ) && is_array( $payload['message'] ) ) {
				$payload['message'] = $this->shape_mychannels_zalo_personal_message( $payload['message'] );
			}
			$payload['success'] = true;
			$payload['idempotency_replayed'] = false;
			BizCity_Twin_Mutation_Store::complete( (string) $mutation_state['key'], $request_hash, $payload );
			return rest_ensure_response( $payload );
		}
		BizCity_Twin_Mutation_Store::release( (string) ( $mutation_state['key'] ?? '' ) );
		return $crm_response;
	}

	/** Resolve+own-check one Zalo Personal conversation for the current mychannels identity, or return an error response. */
	private function mychannels_zalo_personal_owned_conversation( WP_REST_Request $request, bool $connected_only = true ) {
		$identity = $this->mychannels_identity();
		if ( is_wp_error( $identity ) ) { return $this->mychannels_error( 'auth_required', 'Bạn cần đăng nhập để thao tác trên hội thoại.', 'Đăng nhập vào Twin GPT rồi thử lại.', 'auth_required' ); }
		$gate = $this->mychannels_zalo_personal_gate();
		if ( true !== $gate ) { return $gate; }
		$id = (int) $request->get_param( 'id' );
		// [2026-09-19 02:05 PM Johnny Chu] PHASE-0.59-CRM-AI-ASSISTANT-RELEVANCE-RELIABILITY — AI draft generation reads the CRM conversation even when the Zalo session is expired; only an outbound dispatch requires a live connected inbox.
		$inbox_ids = $this->mychannels_zalo_personal_inbox_ids( (int) $identity['user_id'], $connected_only );
		$conversation = class_exists( 'BizCity_CRM_Repository' ) && $id > 0 ? BizCity_CRM_Repository::list_conversations( array( 'id' => $id, 'inbox_ids' => $inbox_ids, 'limit' => 1 ) ) : array();
		if ( empty( $conversation ) && $connected_only ) {
			// Keep ownership errors distinct from a dead Zalo session: the C Inbox already proved that the conversation is visible in the user's scope.
			$visible_ids = $this->mychannels_zalo_personal_inbox_ids( (int) $identity['user_id'], false );
			$visible = class_exists( 'BizCity_CRM_Repository' ) && $id > 0 ? BizCity_CRM_Repository::list_conversations( array( 'id' => $id, 'inbox_ids' => $visible_ids, 'limit' => 1 ) ) : array();
			if ( ! empty( $visible ) ) {
				$visible_inbox_id = (int) ( $visible[0]['inbox_id'] ?? 0 );
				$accounts = class_exists( 'BizCity_Zalo_Mapping_Repo' ) ? BizCity_Zalo_Mapping_Repo::list_personal_accounts_for_owner( (int) $identity['user_id'] ) : array();
				foreach ( $accounts as $account ) {
					if ( (int) ( $account['crm_inbox_id'] ?? 0 ) !== $visible_inbox_id ) { continue; }
					$status = sanitize_key( (string) ( $account['status'] ?? '' ) );
					if ( in_array( $status, array( 'expired', 'logged_out', 'revoked' ), true ) ) {
						return $this->mychannels_error( 'zalo_session_expired', 'Phiên Zalo Personal đã hết.', 'Đăng nhập lại bằng QR rồi thử AI reply gửi cho khách.', 'zalo_session_expired' );
					}
					return $this->mychannels_error( 'zalo_bridge_offline', 'Kết nối Zalo Personal chưa sẵn sàng.', 'Kiểm tra bridge rồi thử lại sau.', 'zalo_bridge_offline' );
				}
			}
		}
		if ( empty( $conversation ) ) { return $this->mychannels_error( 'not_found', 'Không tìm thấy hội thoại Zalo Personal.', 'Chọn hội thoại trong Inbox của bạn rồi thử lại.', 'not_found' ); }
		if ( ! class_exists( 'BizCity_CRM_REST_Controller' ) ) { return $this->mychannels_error( 'module_not_loaded', 'CRM Inbox chưa sẵn sàng.', 'Bật module BizCity Twin CRM rồi thử lại.', 'module_not_loaded' ); }
		return array( 'identity' => $identity, 'conversation_id' => $id );
	}

	/**
	 * POST /mychannels/zalo-personal/conversations/{id}/notes — internal private note.
	 * [2026-09-16 Johnny Chu - Chu Hoàng Anh] PHASE-0.48C-COMPOSER-PARITY — mirror send_mychannels_zalo_personal_message's forward-to-canonical-CRM pattern for the Composer "Private note" tab.
	 */
	public function post_mychannels_zalo_personal_note( WP_REST_Request $request ) {
		$context = $this->mychannels_zalo_personal_owned_conversation( $request );
		if ( is_wp_error( $context ) || ! is_array( $context ) ) { return $context; }
		$id = (int) $context['conversation_id'];
		$body = $request->get_json_params();
		$body = is_array( $body ) ? $body : array();
		$content = trim( (string) ( $body['content'] ?? '' ) );
		if ( $content === '' ) { return $this->mychannels_error( 'invalid_param', 'Nội dung ghi chú không được để trống.', 'Nhập ghi chú rồi thử lại.', 'invalid_param_generic' ); }
		if ( strlen( $content ) > 10000 ) { return $this->mychannels_error( 'invalid_param', 'Ghi chú vượt quá độ dài cho phép.', 'Rút ngắn nội dung rồi lưu lại.', 'invalid_param_generic' ); }
		$idempotency_key = sanitize_text_field( (string) ( $request->get_header( 'x-bizcity-idempotency-key' ) ?: ( $body['idempotency_key'] ?? '' ) ) );
		if ( strlen( $idempotency_key ) < 16 || strlen( $idempotency_key ) > 190 ) {
			return $this->mychannels_error( 'invalid_param', 'Thiếu mã idempotency cho ghi chú.', 'Tạo mã thao tác mới rồi thử lại.', 'invalid_param_generic' );
		}
		if ( ! class_exists( 'BizCity_Twin_Mutation_Store' ) ) {
			return $this->mychannels_error( 'module_not_loaded', 'Bộ chống ghi trùng chưa sẵn sàng.', 'Bật lớp an toàn thao tác rồi thử lại.', 'module_not_loaded' );
		}
		$mutation = array(
			'action'          => 'crm_note.private',
			'idempotency_key' => $idempotency_key,
			'resource'        => array( 'scope' => 'conversation:' . $id ),
			'trace_id'        => (string) ( $request->get_header( 'x-bizcity-trace-id' ) ?: '' ),
		);
		$request_hash = md5( wp_json_encode( array( $id, $content ) ) );
		$mutation_state = BizCity_Twin_Mutation_Store::begin( $mutation, array( 'blog_id' => (int) get_current_blog_id(), 'user_id' => (int) $context['identity']['user_id'] ), $request_hash );
		if ( 'conflict' === (string) ( $mutation_state['status'] ?? '' ) ) {
			return $this->mychannels_error( 'invalid_param', 'Mã idempotency đã dùng cho dữ liệu khác.', 'Tạo mã thao tác mới rồi thử lại.', 'invalid_param_generic' );
		}
		if ( 'pending' === (string) ( $mutation_state['status'] ?? '' ) ) {
			return $this->mychannels_error( 'invalid_param', 'Ghi chú đang được xử lý.', 'Đợi thao tác hiện tại hoàn tất rồi thử lại.', 'invalid_param_generic' );
		}
		if ( 'replay' === (string) ( $mutation_state['status'] ?? '' ) ) {
			$replayed = (array) ( $mutation_state['response'] ?? array() );
			$replayed['idempotency_replayed'] = true;
			return rest_ensure_response( $replayed );
		}
		$crm_request = new WP_REST_Request( 'POST', '/bizcity-crm/v1/conversations/' . $id . '/notes' );
		$crm_request->set_url_params( array( 'id' => $id ) );
		$crm_request->set_header( 'Content-Type', 'application/json' );
		$crm_request->set_body( wp_json_encode( array( 'content' => $content ) ) );
		if ( ! class_exists( 'BizCity_CRM_REST_Controller' ) ) { return $this->mychannels_error( 'module_not_loaded', 'CRM Inbox chưa sẵn sàng.', 'Bật module BizCity Twin CRM rồi thử lại.', 'module_not_loaded' ); }
		$crm_response = BizCity_CRM_REST_Controller::post_note( $crm_request );
		if ( $crm_response instanceof WP_REST_Response ) {
			$wrapped = $crm_response->get_data();
			if ( $crm_response->get_status() >= 400 || ! is_array( $wrapped ) || false === ( $wrapped['ok'] ?? false ) ) {
				BizCity_Twin_Mutation_Store::release( (string) ( $mutation_state['key'] ?? '' ) );
				return $this->mychannels_error( 'crm_projection_failed', 'Chưa lưu được ghi chú.', 'Kiểm tra kết nối CRM rồi thử lại.', 'gateway_degraded' );
			}
			$payload = is_array( $wrapped['data'] ?? null ) ? $wrapped['data'] : $wrapped;
			if ( isset( $payload['message'] ) && is_array( $payload['message'] ) ) {
				$payload['message'] = $this->shape_mychannels_zalo_personal_message( $payload['message'] );
			} elseif ( is_array( $payload ) && isset( $payload['id'] ) ) {
				$payload = array( 'message' => $this->shape_mychannels_zalo_personal_message( $payload ) );
			}
			$payload['success'] = true;
			$payload['idempotency_replayed'] = false;
			BizCity_Twin_Mutation_Store::complete( (string) $mutation_state['key'], $request_hash, $payload );
			return rest_ensure_response( $payload );
		}
		BizCity_Twin_Mutation_Store::release( (string) ( $mutation_state['key'] ?? '' ) );
		return $crm_response;
	}

	public function get_mychannels_zalo_bots( WP_REST_Request $request ) {
		unset( $request );
		$identity = $this->mychannels_identity();
		if ( is_wp_error( $identity ) ) { return $this->mychannels_error( 'auth_required', 'Bạn cần đăng nhập để xem Zalo Bot.', 'Đăng nhập vào Twin GPT rồi thử lại.', 'auth_required' ); }
		$list = $this->list_customer_zalo_bots( $identity );
		return rest_ensure_response( array( 'success' => true, 'policy' => $list['policy'], 'items' => $list['items'], '_degraded' => ! empty( $list['_degraded'] ) ) );
	}

	public function select_mychannels_zalo_bot( WP_REST_Request $request ) {
		$identity = $this->mychannels_identity();
		if ( is_wp_error( $identity ) ) { return $this->mychannels_error( 'auth_required', 'Bạn cần đăng nhập để chọn Zalo Bot.', 'Đăng nhập vào Twin GPT rồi thử lại.', 'auth_required' ); }
		$bot_id = (int) $request->get_param( 'bot_id' );
		$bot = $this->find_public_zalo_bot( $identity, $bot_id );
		if ( ! $bot ) { return $this->mychannels_error( 'not_found', 'Zalo Bot này chưa được bật cho tài khoản của bạn.', 'Chọn một bot trong danh sách Kênh của tôi hoặc liên hệ quản trị viên.', 'zalo_bot_not_connected' ); }

		$user_id = (int) $identity['user_id'];
		$settings = $this->get_mychannels_settings_for_user( $user_id );
		$settings['selected_zalo_bot_id'] = $bot_id;
		$settings = $this->save_mychannels_settings_for_user( $user_id, $settings );

		return rest_ensure_response( array( 'success' => true, 'settings' => $settings, 'bot' => $bot, 'link_status' => $this->get_zalo_link_status_for_user( $user_id, $bot_id ) ) );
	}

	public function get_mychannels_zalo_link_status( WP_REST_Request $request ) {
		unset( $request );
		$identity = $this->mychannels_identity();
		if ( is_wp_error( $identity ) ) { return $this->mychannels_error( 'auth_required', 'Bạn cần đăng nhập để xem trạng thái Zalo.', 'Đăng nhập vào Twin GPT rồi thử lại.', 'auth_required' ); }
		$user_id = (int) $identity['user_id'];
		$settings = $this->get_mychannels_settings_for_user( $user_id );
		$link_status = $this->get_zalo_link_status_for_user( $user_id, (int) $settings['selected_zalo_bot_id'] );
		// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-CHANNEL-AUTOMATION — backfill My Channels selection after /link succeeds from Zalo side.
		if ( ! empty( $link_status['linked'] ) && (int) $settings['selected_zalo_bot_id'] <= 0 && (int) $link_status['bot_id'] > 0 ) {
			$settings['selected_zalo_bot_id'] = (int) $link_status['bot_id'];
			if ( empty( $settings['selected_zalo_chat_id'] ) && ! empty( $link_status['zalo_user_id'] ) ) {
				$settings['selected_zalo_chat_id'] = 'zalobot_' . (int) $link_status['bot_id'] . '_private_' . sanitize_text_field( (string) $link_status['zalo_user_id'] );
				$settings['selected_zalo_chat_label'] = $link_status['display_name'] !== '' ? (string) $link_status['display_name'] : 'Zalo cá nhân';
			}
			$settings = $this->save_mychannels_settings_for_user( $user_id, $settings );
		}
		return rest_ensure_response( array( 'success' => true, 'link_status' => $link_status, 'settings' => $settings ) );
	}

	public function get_mychannels_zalo_recent_chats( WP_REST_Request $request ) {
		unset( $request );
		$identity = $this->mychannels_identity();
		if ( is_wp_error( $identity ) ) { return $this->mychannels_error( 'auth_required', 'Bạn cần đăng nhập để xem chat Zalo gần đây.', 'Đăng nhập vào Twin GPT rồi thử lại.', 'auth_required' ); }
		$user_id = (int) $identity['user_id'];
		$settings = $this->get_mychannels_settings_for_user( $user_id );
		$bot_id = (int) ( $settings['selected_zalo_bot_id'] ?? 0 );
		$link_status = $this->get_zalo_link_status_for_user( $user_id, $bot_id );
		$recent = $this->list_customer_zalo_recent_chats( $identity, $bot_id, $link_status );
		return rest_ensure_response( array(
			'success' => true,
			'items' => $recent['items'],
			'_degraded' => ! empty( $recent['_degraded'] ),
			'message' => (string) ( $recent['message'] ?? '' ),
			'settings' => $settings,
			'link_status' => $link_status,
		) );
	}

	public function get_mychannels_zalo_conversation( WP_REST_Request $request ) {
		// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-CHANNEL-AUTOMATION — customer mini inbox reads only linked user's Zalo logs.
		$identity = $this->mychannels_identity();
		if ( is_wp_error( $identity ) ) { return $this->mychannels_error( 'auth_required', 'Bạn cần đăng nhập để xem hội thoại Zalo.', 'Đăng nhập vào Twin GPT rồi thử lại.', 'auth_required' ); }
		$user_id = (int) $identity['user_id'];
		$settings = $this->get_mychannels_settings_for_user( $user_id );
		$bot_id = (int) ( $settings['selected_zalo_bot_id'] ?? 0 );
		$link_status = $this->get_zalo_link_status_for_user( $user_id, $bot_id );
		$target = $this->resolve_mychannels_zalo_chat_target( $identity, $bot_id, $link_status, (string) $request->get_param( 'chat_id' ) );
		if ( is_wp_error( $target ) ) { return $this->mychannels_error( (string) $target->get_error_code(), $target->get_error_message(), 'Gửi /link trong Zalo Bot rồi thử lại.', 'invalid_param_generic' ); }

		global $wpdb;
		$table = $wpdb->prefix . 'bizcity_zalo_bot_logs';
		// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-CHANNEL-AUTOMATION — fail-open on older Zalo log schema instead of querying absent columns.
		if ( ! $this->db_table_exists( $table ) || ! $this->db_column_exists( $table, 'event_data' ) || ! $this->db_column_exists( $table, 'client_id' ) || ! $this->db_column_exists( $table, 'bot_id' ) || ! $this->db_column_exists( $table, 'created_at' ) ) {
			return rest_ensure_response( array( 'success' => true, 'items' => array(), '_degraded' => true, 'message' => 'Chưa có bảng log hội thoại Zalo Bot.', 'target' => $target ) );
		}

		$limit = max( 1, min( 100, (int) ( $request->get_param( 'limit' ) ?: 50 ) ) );
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT event_name, event_data, client_id, user_id, message_id, display_name, text, created_at FROM {$table} WHERE bot_id = %d AND client_id = %s ORDER BY created_at DESC LIMIT 250",
			$bot_id,
			(string) $link_status['zalo_user_id']
		) );

		$items = array();
		foreach ( array_reverse( (array) $rows ) as $row ) {
			$payload = json_decode( (string) ( $row->event_data ?? '' ), true );
			if ( ! is_array( $payload ) ) { continue; }
			$item = $this->build_zalo_recent_chat_item( $payload, $row, $bot_id, (string) $link_status['zalo_user_id'] );
			if ( ! is_array( $item ) || (string) ( $item['chat_id'] ?? '' ) !== (string) $target['chat_id'] ) { continue; }
			$event_name = (string) ( $row->event_name ?? '' );
			$items[] = array(
				'id' => sanitize_text_field( (string) ( $row->message_id ?? md5( wp_json_encode( $payload ) . (string) ( $row->created_at ?? '' ) ) ) ),
				'role' => ( false !== strpos( $event_name, 'bot.' ) || false !== strpos( $event_name, 'out' ) ) ? 'bot' : 'user',
				'text' => sanitize_textarea_field( (string) ( $row->text ?? $item['last_text'] ?? '' ) ),
				'created_at' => sanitize_text_field( (string) ( $row->created_at ?? '' ) ),
				'event_name' => sanitize_text_field( $event_name ),
			);
			if ( count( $items ) >= $limit ) { break; }
		}

		return rest_ensure_response( array( 'success' => true, 'items' => $items, 'target' => $target, 'settings' => $settings, 'link_status' => $link_status ) );
	}

	public function send_mychannels_zalo_message( WP_REST_Request $request ) {
		// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-CHANNEL-AUTOMATION — owner-scoped customer send from mini inbox.
		$identity = $this->mychannels_identity();
		if ( is_wp_error( $identity ) ) { return $this->mychannels_error( 'auth_required', 'Bạn cần đăng nhập để gửi Zalo.', 'Đăng nhập vào Twin GPT rồi thử lại.', 'auth_required' ); }
		$user_id = (int) $identity['user_id'];
		$settings = $this->get_mychannels_settings_for_user( $user_id );
		$bot_id = (int) ( $settings['selected_zalo_bot_id'] ?? 0 );
		$link_status = $this->get_zalo_link_status_for_user( $user_id, $bot_id );
		$target = $this->resolve_mychannels_zalo_chat_target( $identity, $bot_id, $link_status, (string) $request->get_param( 'chat_id' ) );
		if ( is_wp_error( $target ) ) { return $this->mychannels_error( (string) $target->get_error_code(), $target->get_error_message(), 'Chọn chat bot đã thấy hoặc ghim chat hợp lệ rồi thử lại.', 'invalid_param_generic' ); }
		$message = trim( sanitize_textarea_field( (string) $request->get_param( 'message' ) ) );
		if ( $message === '' ) { return $this->mychannels_error( 'invalid_param', 'Nội dung gửi không được để trống.', 'Nhập tin nhắn rồi bấm Gửi.', 'invalid_param_generic' ); }
		if ( ! function_exists( 'bizcity_get_zalo_bot_api' ) ) { return $this->mychannels_error( 'module_not_loaded', 'Zalo Bot API chưa sẵn sàng.', 'Tải lại trang hoặc kiểm tra module Zalo Bot.', 'module_not_loaded' ); }

		$api = bizcity_get_zalo_bot_api( $bot_id );
		if ( ! $api || ! method_exists( $api, 'send_message' ) ) { return $this->mychannels_error( 'not_found', 'Không tìm thấy Bot API để gửi tin.', 'Kiểm tra cấu hình Zalo Bot trong Channel Gateway.', 'invalid_param_generic' ); }
		$result = $api->send_message( (string) $target['raw_chat_id'], $message );
		if ( is_wp_error( $result ) ) { return $this->mychannels_error( (string) $result->get_error_code(), 'Không gửi được tin Zalo.', 'Kiểm tra log Zalo Bot hoặc gửi lại sau.', 'invalid_param_generic' ); }
		$sent_message = $this->log_mychannels_zalo_outbound( $user_id, $bot_id, $target, $link_status, $message, is_array( $result ) ? $result : array() );

		if ( class_exists( 'BizCity_Channel_File_Logger' ) ) {
			BizCity_Channel_File_Logger::write( BizCity_Channel_File_Logger::CH_ZALO_BOT, BizCity_Channel_File_Logger::LEVEL_INFO, 'mychannels_send_ok', 'Customer sent Zalo message from My Channels.', array( 'bot_id' => $bot_id, 'chat_type' => (string) $target['chat_type'], 'chat_hash' => substr( md5( (string) $target['raw_chat_id'] ), 0, 10 ) ) );
		}
		return rest_ensure_response( array( 'success' => true, 'target' => $target, 'result' => $result, 'sent_message' => $sent_message ) );
	}

	public function create_mychannels_zalo_link_command( WP_REST_Request $request ) {
		$identity = $this->mychannels_identity();
		if ( is_wp_error( $identity ) ) { return $this->mychannels_error( 'auth_required', 'Bạn cần đăng nhập để tạo mã liên kết Zalo.', 'Đăng nhập vào Twin GPT rồi thử lại.', 'auth_required' ); }
		if ( ! class_exists( 'BizCity_Zalobot_User_Linker' ) ) { return $this->mychannels_error( 'module_not_loaded', 'Zalo Bot linker chưa sẵn sàng.', 'Kiểm tra plugin Zalo Bot trong Channel Gateway.', 'zalo_bot_not_connected' ); }

		$user_id = (int) $identity['user_id'];
		$settings = $this->get_mychannels_settings_for_user( $user_id );
		$bot_id = (int) $request->get_param( 'bot_id' );
		if ( $bot_id <= 0 ) { $bot_id = (int) $settings['selected_zalo_bot_id']; }
		$bot = $this->find_public_zalo_bot( $identity, $bot_id );
		if ( ! $bot ) { return $this->mychannels_error( 'not_found', 'Chưa có Zalo Bot hợp lệ để liên kết.', 'Chọn một bot trong Kênh của tôi trước khi tạo mã liên kết.', 'zalo_bot_not_connected' ); }

		$issued = BizCity_Zalobot_User_Linker::issue_twin_gpt_link_nonce( $user_id, $bot_id );
		if ( is_wp_error( $issued ) ) { return $this->mychannels_error( (string) $issued->get_error_code(), 'Không tạo được mã liên kết Zalo.', 'Thử lại sau ít phút hoặc kiểm tra cấu hình Zalo Bot.', 'invalid_param_generic' ); }

		$nonce = (string) ( $issued['nonce'] ?? '' );
		$command = '/link ' . $nonce;
		// [2026-07-21 Johnny Chu] PHASE-TWINWEB W3 — return getMe.id/account_name based Bot Platform URLs with the one-time link command.
		$links = $this->build_zalo_bot_public_links( (string) ( $bot['oa_id'] ?? '' ) );
		$chat_url = ! empty( $links['chat_url'] ) ? (string) $links['chat_url'] : ( ! empty( $bot['chat_url'] ) ? (string) $bot['chat_url'] : '' );
		$deep_link = ! empty( $links['legacy_zalo_url'] ) ? add_query_arg( 'text', $command, $links['legacy_zalo_url'] ) : $chat_url;
		$qr_url = ! empty( $links['qr_url'] ) ? (string) $links['qr_url'] : ( ! empty( $bot['qr_url'] ) ? (string) $bot['qr_url'] : $this->build_zalo_bot_qr_url( $chat_url ) );

		return rest_ensure_response( array(
			'success' => true,
			'user_id' => $user_id,
			'bot_id' => $bot_id,
			'bot_name' => $bot['bot_name'],
			'oa_id' => $bot['oa_id'],
			'bot_platform_id' => $links['bot_platform_id'],
			'account_name' => $links['account_name'],
			'nonce' => $nonce,
			'command' => $command,
			'expires_at' => isset( $issued['expires_at'] ) ? (int) $issued['expires_at'] : 0,
			'expires_at_iso' => ! empty( $issued['expires_at'] ) ? gmdate( 'c', (int) $issued['expires_at'] ) : '',
			'chat_url' => $chat_url,
			'bot_url' => $links['bot_url'],
			'invite_url' => $links['invite_url'],
			'deep_link' => $deep_link,
			'qr_url' => $qr_url,
			'link_hint' => 'Mở Zalo Bot và gửi đúng lệnh /link <nonce> để liên kết danh tính.',
		) );
	}

	public function pin_mychannels_zalo_chat( WP_REST_Request $request ) {
		$identity = $this->mychannels_identity();
		if ( is_wp_error( $identity ) ) { return $this->mychannels_error( 'auth_required', 'Bạn cần đăng nhập để ghim chat Zalo.', 'Đăng nhập vào Twin GPT rồi thử lại.', 'auth_required' ); }
		$user_id = (int) $identity['user_id'];
		$settings = $this->get_mychannels_settings_for_user( $user_id );
		$bot_id = (int) ( $settings['selected_zalo_bot_id'] ?? 0 );
		$chat_id = sanitize_text_field( (string) $request->get_param( 'chat_id' ) );
		$label = sanitize_text_field( (string) $request->get_param( 'label' ) );
		if ( $chat_id === '' ) {
			return $this->mychannels_error( 'invalid_param', 'Chat ID Zalo không được để trống.', 'Nhập chat.id của group hoặc chat_id direct đã liên kết.', 'invalid_param_generic' );
		}
		if ( $bot_id <= 0 ) {
			return $this->mychannels_error( 'invalid_param', 'Bạn chưa chọn Zalo Bot.', 'Chọn một bot trước khi ghim chat.', 'zalo_bot_not_connected' );
		}

		// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-CHANNEL-AUTOMATION — only a linked Zalo identity can pin a direct/group chat target.
		$link_status = $this->get_zalo_link_status_for_user( $user_id, $bot_id );
		// [2026-08-21 Johnny Chu] PHASE-2-TWIN-GPT-CHANNEL-AUTOMATION — resolve and persist only an owner-authorized Zalo target.
		$target = $this->resolve_mychannels_zalo_chat_target( $identity, $bot_id, $link_status, $chat_id );
		if ( is_wp_error( $target ) ) {
			return $this->mychannels_error( (string) $target->get_error_code(), 'Chat Zalo này chưa được xác thực cho tài khoản của bạn.', 'Chọn chat bot vừa thấy hoặc gửi tin nhắn trong chat rồi thử lại.', 'permission_denied' );
		}
		$settings['selected_zalo_chat_id'] = sanitize_text_field( (string) ( $target['chat_id'] ?? $chat_id ) );
		$settings['selected_zalo_chat_label'] = $label !== '' ? $label : sanitize_text_field( (string) ( $target['label'] ?? '' ) );
		$settings = $this->save_mychannels_settings_for_user( $user_id, $settings );
		return rest_ensure_response( array( 'success' => true, 'settings' => $settings, 'target' => $target ) );
	}

	/**
	 * GET /me — identity + entitlement (fail-OPEN).
	 *
	 * [2026-10-09 11:07 PM Johnny Chu - Chu Hoàng Anh] CORE-REDUCTION WP-20 W20-U3b — is_video_kling_active() removed: Video Studio
	 * shows when the registry entry 'video' passes its `requires` (BIZCITY_VIDEO_KLING_VERSION) in apps_for_user().
	 */
	public function handle_me( WP_REST_Request $request ) {
		$identity = BizCity_Channel_Core_Identity::current();

		// Entitlement from gateway (fail-OPEN)
		$entitlement = array( 'tier' => 'free', 'bypass' => true, '_degraded' => true );
		if ( ! $identity['is_guest'] && class_exists( 'BizCity_LLM_Client' ) ) {
			$client = BizCity_LLM_Client::instance();
			if ( $client->is_ready() ) {
		$raw = $client->get_entitlement( (int) $identity['user_id'] );
				if ( is_array( $raw ) && empty( $raw['_degraded'] ) ) {
					$entitlement = $raw;
				}
			}
		}

		$guest_quota_remaining = null;
		if ( $identity['is_guest'] ) {
			$quota_key             = 'tw_guest_' . $identity['guest_sid'] . '_quota';
			$used                  = (int) get_transient( $quota_key );
			$limit                 = (int) apply_filters( 'bizcity_twinweb_guest_quota', 10 );
			$guest_quota_remaining = max( 0, $limit - $used );
		}

		// [2026-06-20 Johnny Chu] PHASE-TWINWEB — expose member quota info
		$member_quota = null;
		if ( ! $identity['is_guest'] ) {
			$uid         = $identity['user_id'];
			$tier        = (string) apply_filters( 'bizcity_twinweb_user_tier', $entitlement['tier'] ?? 'free', $uid );
			$tier_limits = (array)  apply_filters( 'bizcity_twinweb_tier_limits', array( 'free' => 30, 'plus' => 100, 'pro' => -1 ) );
			$day_limit   = isset( $tier_limits[ $tier ] ) ? (int) $tier_limits[ $tier ] : 30;
			$day_used    = (int) get_transient( 'tw_user_' . $uid . '_quota_' . gmdate( 'Y-m-d' ) );
			$member_quota = array(
				'tier'      => $tier,
				'day_limit' => $day_limit,
				'day_used'  => $day_used,
				'remaining' => $day_limit < 0 ? null : max( 0, $day_limit - $day_used ),
			);
		}

		// [2026-07-17 Johnny Chu] SPRINT-9 WC-1 — expose server-owned subscription/offer payload for UpgradeModal.
		$subscription   = $this->build_subscription_for_me( $identity );
		$eligible_offers = $this->build_eligible_offers_for_me( $identity, $subscription );
		$legacy_apps = array(
			array( 'id' => 'chat',    'label' => 'Chat',        'icon' => 'chat',    'enabled' => true ),
			// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-CHANNEL-AUTOMATION — expose My Workflows shortcut in legacy /me fallback app list.
			array( 'id' => 'myworkflows', 'label' => 'My Workflows', 'icon' => 'workflow', 'enabled' => true ),
			// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-MY-CONTENT-TRACE — expose My Plan artifact workspace in legacy /me fallback app list.
			// [2026-08-21 Johnny Chu] PHASE-PROFILE-QR — Wave 5 item 11: display label renamed to My Artifacts; id/route unchanged (R-TWEB-6).
			array( 'id' => 'mycontent', 'label' => 'My Artifacts', 'icon' => 'doc', 'enabled' => true ),
			// [2026-07-31 Johnny Chu] PHASE-TWINWEB-UNIFIED-SOURCES — expose canonical My Files workspace in the normal app catalog.
			array( 'id' => 'myfiles', 'label' => 'My Files', 'icon' => 'file', 'enabled' => true ),
			// [2026-07-30 Johnny Chu] PHASE-TWINWEB-UNIFIED-SOURCES — expose My MCP through the server-owned app catalog.
			array( 'id' => 'mymcp', 'label' => 'My MCP', 'icon' => 'mcp', 'enabled' => true ),
		);
		// [2026-10-09 11:07 PM Johnny Chu - Chu Hoàng Anh] CORE-REDUCTION WP-20 W20-U3b — the fallback list's apps (Astro, Creator,
		// Image, Video, Profile, card QR) come from the one launcher source too, instead of their own hardcoded rows.
		foreach ( $this->registry_apps_for_twinweb( (int) $identity['user_id'] ) as $registry_app ) {
			$legacy_apps[] = $registry_app + array( 'enabled' => true );
		}

		$response = rest_ensure_response( array(
			'user_id'               => $identity['user_id'],
			'is_guest'              => $identity['is_guest'],
			'display_name'          => $identity['display'],
			'avatar_url'            => $identity['user_id'] > 0 ? get_avatar_url( $identity['user_id'], array( 'size' => 40 ) ) : '',
			'entitlement'           => $entitlement,
			'guest_quota_remaining' => $guest_quota_remaining,
			'member_quota'          => $member_quota,
			// [2026-06-18 Johnny Chu] PHASE-TWINWEB — public page URL for FE redirects
			'twinweb_page_url'      => class_exists( 'BizCity_TwinWeb_Page' ) ? BizCity_TwinWeb_Page::get_page_url() : null,
			'apps'                  => $legacy_apps,
			// [2026-07-17 Johnny Chu] Q-1 R-BIZ-MODEL-11 — expose server-owned plan catalog so FE never hard-codes price/URL.
			'plan_catalog'          => $this->build_plan_catalog_for_me( $identity ),
			// [2026-07-17 Johnny Chu] Q-1 — current subscription info for FE UpgradeModal
			'subscription'          => $subscription,
			// [2026-07-17 Johnny Chu] SPRINT-9 WC-1 — plan-scoped Woo offers for plan/term picker UI.
			'eligible_offers'       => $eligible_offers,
		) );

		// [2026-07-27 Johnny Chu] HOTFIX — auth-sensitive endpoint must not be cached across users.
		$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
		$response->header( 'Pragma', 'no-cache' );
		$response->header( 'Expires', 'Wed, 11 Jan 1984 05:00:00 GMT' );
		$response->header( 'Vary', 'Cookie' );

		return $response;
	}

	/**
	 * Launcher apps for Twin GPT, read from the ONE source (D-W20-2): BizCity_Twin_Shell_Registry::apps_for_user(),
	 * i.e. registry entries with `group => 'apps'` + the `zalo_brain_apps` filter, already vetted for `requires`
	 * and capability (module-access@1). Mapped to the Twin GPT sidebar DTO (id/label/icon/href/iframe_href/…).
	 *
	 * Registry ids that already have a /gpt/{slug}/ page keep their Twin GPT id + deep link (so old bookmarks and
	 * GPT_IFRAME_APPS in the FE keep working); any other embed app opens its own public page inside the /gpt/
	 * iframe; a link app (admin page / url) opens in a new tab.
	 *
	 * [2026-10-09 11:07 PM Johnny Chu - Chu Hoàng Anh] CORE-REDUCTION WP-20 W20-U3b — replaces the hardcoded app rows.
	 *
	 * @param int $user_id Viewer (0 = guest ⇒ no app passes a capability check ⇒ empty list).
	 * @return array<int, array<string, mixed>>
	 */
	private function registry_apps_for_twinweb( $user_id ) {
		if ( ! class_exists( 'BizCity_Twin_Shell_Registry' ) || ! method_exists( 'BizCity_Twin_Shell_Registry', 'apps_for_user' ) ) {
			return array();
		}
		// registry id => [ Twin GPT id, /gpt/{slug}/ route (class-twinweb-page.php contract), FE icon key, iframe path override ].
		$routes = array(
			'astro'          => array( 'astro', 'astro', 'moon' ),
			'creator'        => array( 'creator', 'creator', 'pencil' ),
			'doc'            => array( 'doc', 'doc', 'doc' ),
			'image'          => array( 'image', 'image', 'image' ),
			'video'          => array( 'video', 'video', 'video' ),
			// Twin GPT's "My profile" keeps its /profile-care/ workspace (PHASE-PROFILE-QR-DDV 2026-09-01, probed by DDV).
			'personal'       => array( 'profile', 'profile-care', 'profile', '/profile-care/' ),
			'profile-public' => array( 'profile_card_qr', 'profile-public', 'qr' ),
			// Portrait Studio is registry id 'profile', which Twin GPT already uses for the personal page.
			'profile'        => array( 'portrait', '', 'profile' ),
		);
		$reserved = array( 'chat', 'mychannels', 'myworkflows', 'mycontent', 'myfiles', 'mymcp', 'workflow', 'twinchat', 'account' );

		$out  = array();
		$seen = array();
		foreach ( BizCity_Twin_Shell_Registry::instance()->apps_for_user( (int) $user_id ) as $a ) {
			$reg_id = isset( $a['id'] ) ? (string) $a['id'] : '';
			if ( '' === $reg_id ) {
				continue;
			}
			$map   = isset( $routes[ $reg_id ] ) ? $routes[ $reg_id ] : null;
			$tw_id = null !== $map ? $map[0] : str_replace( '-', '_', sanitize_key( $reg_id ) );
			if ( '' === $tw_id || in_array( $tw_id, $reserved, true ) || isset( $seen[ $tw_id ] ) ) {
				continue;
			}
			$seen[ $tw_id ] = true;

			$mode  = isset( $a['mode'] ) ? (string) $a['mode'] : 'embed';
			$slug  = isset( $a['public_slug'] ) ? (string) $a['public_slug'] : '';
			$open  = 'iframe';
			$href  = '';
			$inner = '';
			if ( 'link' === $mode || '' === $slug ) {
				$target = ! empty( $a['target_url'] ) ? (string) $a['target_url'] : ( ! empty( $a['url'] ) ? (string) $a['url'] : '' );
				if ( '' === $target ) {
					continue; // nothing Twin GPT can open
				}
				$open = 'link';
				$href = $target;
			} else {
				$page  = home_url( ( null !== $map && ! empty( $map[3] ) ) ? $map[3] : $slug );
				$inner = add_query_arg( array( 'ref' => 'twinweb', 'bizcity_iframe' => '1' ), $page );
				$href  = ( null !== $map && '' !== $map[1] ) ? home_url( '/gpt/' . $map[1] . '/' ) : $page;
			}

			$icon = null !== $map ? $map[2] : ( isset( $a['icon'] ) ? (string) $a['icon'] : 'app' );
			$out[] = array(
				'id'            => $tw_id,
				'label'         => isset( $a['label'] ) ? (string) $a['label'] : $tw_id,
				'icon'          => $icon,
				'href'          => $href,
				'iframe_href'   => $inner,
				'state'         => 'available',
				'auth_required' => true,
				'open'          => $open,
				// Plans are retired: only a premium add-on keeps a chip (FE shows "PRO" when pro_package is set).
				'pro_package'   => ( isset( $a['license'] ) && 'premium' === $a['license'] )
					? ( ! empty( $a['addon_name'] ) ? (string) $a['addon_name'] : ( ! empty( $a['pro_package'] ) ? (string) $a['pro_package'] : 'Premium' ) )
					: '',
				'usage'         => array( 'used' => 0, 'limit' => null, 'remaining' => null ),
			);
		}
		return $out;
	}

	/**
	 * Build plan catalog for /me — server-owned plan list with checkout URLs.
	 * FE must render from this, not from a hard-coded React constant.
	 *
	 * @param array $identity
	 * @return array
	 */
	private function build_plan_catalog_for_me( array $identity ) {
		// [2026-10-09 21:10 Johnny Chu - Chu Hoàng Anh] PHASE-0.96 D96-23 — membership retired: no plan catalog; key kept so /me keeps its shape.
		unset( $identity );
		return array();
	}

	/**
	 * Build current subscription summary for /me.
	 *
	 * @param array $identity
	 * @return array|null
	 */
	private function build_subscription_for_me( array $identity ) {
		// [2026-10-09 21:10 Johnny Chu - Chu Hoàng Anh] PHASE-0.96 D96-23 — membership retired: no subscriptions exist; same null the absent Manager class already produced.
		unset( $identity );
		return null;
	}

	/**
	 * Build Woo offers eligible for the current plan in /me payload.
	 *
	 * @param array      $identity
	 * @param array|null $subscription
	 * @return array
	 */
	private function build_eligible_offers_for_me( array $identity, $subscription = null ) {
		// [2026-10-09 21:10 Johnny Chu - Chu Hoàng Anh] PHASE-0.96 D96-23 — membership retired: no Woo plan offers (Woo mapper is gone).
		unset( $identity, $subscription );
		return array();
	}
	// [2026-07-17 Johnny Chu] HOTFIX — remove stray class-close brace that caused unexpected public parse error.

	/* ═════════════════════════════════════════════════════════════════════ */
	/* WAVE SEARCH — DOCUMENTS / CONVERSATIONS / PASSAGE SOURCE             */
	/* ═════════════════════════════════════════════════════════════════════ */

	private static function table_exists( $table ) {
		// [2026-06-21 Johnny Chu] R-SHOW-TABLES — dùng information_schema thay vì SHOW TABLES (dual cache)
		static $s = array();
		if ( isset( $s[ $table ] ) ) {
			return $s[ $table ];
		}
		// [2026-09-01 Johnny Chu] PHASE-1.30-DEAD-SQL-COHORT — admin health checks must not inspect retired legacy projections.
		if ( class_exists( 'BizCity_Legacy_Table_Policy' ) && ! BizCity_Legacy_Table_Policy::allow_sql( $table, 'read' ) ) {
			$s[ $table ] = false;
			return false;
		}
		global $wpdb;
		$ck      = 'bz_tbl_' . (int) get_current_blog_id() . '_' . crc32( $table );
		$present = wp_cache_get( $ck, 'bizcity_tbl' );
		if ( false === $present ) {
			$present = (int) (bool) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s LIMIT 1',
					$table
				)
			);
			wp_cache_set( $ck, $present, 'bizcity_tbl', HOUR_IN_SECONDS );
		}
		$s[ $table ] = (bool) $present;
		return $s[ $table ];
	}

	/**
	 * GET /skills — list /skills (automation workflow slugs/templates).
	 *
	 * [2026-06-18 Johnny Chu] PHASE-TWINWEB Wave 4 — returns automation workflow
	 * templates as {id, label, description, icon, category} for the / popover.
	 * Sources: bizcity_automation_workflows (user's own) + seeded templates.
	 *
	 * @return WP_REST_Response
	 */
	/**
	 * GET /skills — 3-tier skill catalog with new fields for workflow pipeline.
	 *
	 * [2026-06-19 Johnny Chu] PHASE-TWB-WORKFLOW W1 — full rewrite.
	 * Changes from previous version:
	 *  - Tier 1: filter enabled=1 (only "Đang chạy" workflows), parse graph_json
	 *    for node_count, add source/workflow_id/trigger_kind/enabled fields.
	 *  - Tier 2: builtin_blueprints now carry node_count (from graph_json) +
	 *    source='builtin', workflow_id=0.
	 *  - Filter changed from bizcity_twinweb_skills → bizcity_twinbrain_skill_catalog
	 *    (surface-agnostic, shared with twinchat AskBrainPanel).
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public function list_skills( WP_REST_Request $request ) {
		// [2026-06-19 Johnny Chu] PHASE-TWB-WORKFLOW W1
		$identity = BizCity_Channel_Core_Identity::current();
		$user_id  = (int) $identity['user_id'];
		$skills   = array();

		// ── Tier 1 — User's own ENABLED workflows (enabled=1 = "Đang chạy" only) ──
		// Only workflows the user has deliberately activated. KHÔNG trả về
		// workflow tạm dừng/draft — user chưa sẵn sàng dùng chúng qua /skill.
		if ( $user_id > 0 && class_exists( 'BizCity_Automation_Repo_Workflows' ) ) {
			$found = BizCity_Automation_Repo_Workflows::query( array(
				'created_by' => $user_id,
				'enabled'    => 1,
				'limit'      => 40,
			) );
			// query() may return { rows, total } or a flat array depending on version.
			$rows = array();
			if ( is_array( $found ) ) {
				$rows = isset( $found['rows'] ) && is_array( $found['rows'] ) ? $found['rows'] : $found;
			}
			foreach ( $rows as $wf ) {
				$graph      = json_decode( (string) ( $wf['graph_json'] ?? '' ), true );
				$node_count = is_array( $graph ) ? count( $graph['nodes'] ?? array() ) : 0;
				$slug       = (string) ( $wf['slug'] ?? 'wf_' . (int) $wf['id'] );
				$skills[]   = array(
					'id'           => $slug,
					'label'        => (string) ( $wf['name']         ?? 'Workflow' ),
					'description'  => $node_count . ' bước · ' . (string) ( $wf['trigger_type'] ?? 'manual' ),
					'icon'         => 'zap',
					'category'     => 'workflow',
					'source'       => 'user',
					'workflow_id'  => (int) ( $wf['id'] ?? 0 ),
					'node_count'   => $node_count,
					'trigger_kind' => (string) ( $wf['trigger_type'] ?? 'manual' ),
					'enabled'      => ! empty( $wf['enabled'] ),
				);
			}
		}

		// ── Tier 2 — Hub-imported workflows (source=hub_imported, enabled=1) ──
		// Same enabled=1 filter — user imported AND activated.
		if ( $user_id > 0 && class_exists( 'BizCity_Automation_Repo_Workflows' ) ) {
			global $wpdb;
			$t    = $wpdb->prefix . 'bizcity_automation_workflows';
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT id, name, slug, graph_json, trigger_type
				 FROM {$t}
				 WHERE created_by = %d AND enabled = 1
				   AND (
				       (trigger_config_json IS NOT NULL AND trigger_config_json LIKE %s)
				       OR slug LIKE %s
				   )
				 ORDER BY updated_at DESC LIMIT 20",
				$user_id,
				'%hub_imported%',
				'hub_%'
			), ARRAY_A );
			foreach ( (array) $rows as $wf ) {
				// Skip if already in Tier 1 (same slug).
				$slug = (string) ( $wf['slug'] ?? 'wf_' . (int) $wf['id'] );
				foreach ( $skills as $existing ) {
					if ( $existing['id'] === $slug ) {
						continue 2;
					}
				}
				$graph      = json_decode( (string) ( $wf['graph_json'] ?? '' ), true );
				$node_count = is_array( $graph ) ? count( $graph['nodes'] ?? array() ) : 0;
				$skills[]   = array(
					'id'           => $slug,
					'label'        => (string) ( $wf['name'] ?? 'Hub Workflow' ),
					'description'  => $node_count . ' bước · ' . (string) ( $wf['trigger_type'] ?? 'manual' ),
					'icon'         => 'globe',
					'category'     => 'workflow',
					'source'       => 'hub_imported',
					'workflow_id'  => (int) ( $wf['id'] ?? 0 ),
					'node_count'   => $node_count,
					'trigger_kind' => (string) ( $wf['trigger_type'] ?? 'manual' ),
					'enabled'      => true,
				);
			}
		}

		// ── Tier 3 — Built-in skills (always shown; no DB required) ──
		$builtin_skills = array(
			array(
				'id'           => 'remind',
				'label'        => 'Nhắc lịch',
				'description'  => '3 bước · manual',
				'icon'         => 'bell',
				'category'     => 'schedule',
				'source'       => 'builtin',
				'workflow_id'  => 0,
				'node_count'   => 3,
				'trigger_kind' => 'manual',
				'enabled'      => true,
			),
			array(
				'id'           => 'post_fb',
				'label'        => 'Đăng Facebook',
				'description'  => '4 bước · manual',
				'icon'         => 'facebook',
				'category'     => 'social',
				'source'       => 'builtin',
				'workflow_id'  => 0,
				'node_count'   => 4,
				'trigger_kind' => 'manual',
				'enabled'      => true,
			),
			array(
				'id'           => 'note',
				'label'        => 'Ghi chú',
				'description'  => '2 bước · manual',
				'icon'         => 'sticky-note',
				'category'     => 'knowledge',
				'source'       => 'builtin',
				'workflow_id'  => 0,
				'node_count'   => 2,
				'trigger_kind' => 'manual',
				'enabled'      => true,
			),
			array(
				'id'           => 'summary',
				'label'        => 'Tóm tắt',
				'description'  => '2 bước · manual',
				'icon'         => 'file-text',
				'category'     => 'content',
				'source'       => 'builtin',
				'workflow_id'  => 0,
				'node_count'   => 2,
				'trigger_kind' => 'manual',
				'enabled'      => true,
			),
			array(
				'id'           => 'search',
				'label'        => 'Tìm kiếm web',
				'description'  => '3 bước · manual',
				'icon'         => 'search',
				'category'     => 'research',
				'source'       => 'builtin',
				'workflow_id'  => 0,
				'node_count'   => 3,
				'trigger_kind' => 'manual',
				'enabled'      => true,
			),
		);
		foreach ( $builtin_skills as $s ) {
			$skills[] = $s;
		}

		// Extension hook — surface-agnostic, same filter as TwinBrain catalog.
		// Plugins can add/modify skills (e.g. bizcoach-pro adds astro_quick).
		$skills = (array) apply_filters( 'bizcity_twinbrain_skill_catalog', $skills, $user_id );
		// Legacy hook kept for backward compat.
		$skills = (array) apply_filters( 'bizcity_twinweb_skills', $skills, $user_id );

		return rest_ensure_response( array( 'items' => $skills ) );
	}

	/* ═════════════════════════════════════════════════════════════════════ */
	/* U5 — MODEL PICKER                                                      */
	/* [2026-07-15 Johnny Chu] PHASE-TWINWEB U5                               */
	/* ═════════════════════════════════════════════════════════════════════ */

	/**
	 * Capability check: manage_options (admin only).
	 */
	public function admin_cap_check() {
		return current_user_can( 'manage_options' );
	}

	/* ── Effective config ─────────────────────────────────────────────── */

	/**
	 * Default mode policy when no admin configuration exists.
	 *
	 * @return array
	 */
	private function default_mode_policy() {
		// [2026-08-28 Johnny Chu] PHASE-1.32 — derive Twin GPT modes from the canonical Vertical Bridge registry.
		$modes = array(
			array( 'id' => 'notebooks', 'label' => 'Notebooks', 'enabled' => true, 'is_default' => true,  'guest_allowed' => true,  'min_plan' => 'free',  'order' => 10 ),
			array( 'id' => 'chat',      'label' => 'Chat',       'enabled' => true, 'is_default' => false, 'guest_allowed' => true,  'min_plan' => 'free',  'order' => 30 ),
		);
		if ( class_exists( 'BizCity_TwinBrain_Vertical_Bridge_Registry' ) ) {
			$verticals = BizCity_TwinBrain_Vertical_Bridge_Registry::all();
			foreach ( $verticals as $index => $vertical ) {
				$modes[] = array(
					'id'           => (string) $vertical['id'],
					'label'        => (string) $vertical['label'],
					'enabled'      => ! array_key_exists( 'default_enabled', $vertical ) || ! empty( $vertical['default_enabled'] ),
					'is_default'   => false,
					'guest_allowed'=> ! empty( $vertical['guest_allowed'] ),
					'min_plan'     => (string) $vertical['min_plan'],
					'order'        => 40 + ( (int) $index * 10 ),
				);
			}
		}
		return (array) apply_filters( 'bizcity_twinweb_default_mode_policy', $modes );
	}

	/**
	 * Read effective mode policy for a blog by merging stored rows with
	 * canonical defaults from the Vertical Bridge registry.
	 *
	 * @param int $blog_id Blog ID.
	 * @return array
	 */
	private function get_mode_policy( $blog_id ) {
		// [2026-08-28 Johnny Chu] PHASE-1.32 — keep all free mode packages usable and auto-backfill newly introduced canonical modes.
		$defaults = $this->default_mode_policy();
		$allowed  = array();
		$plans    = array( 'free', 'plus', 'pro' );

		foreach ( $defaults as $mode ) {
			if ( ! is_array( $mode ) || empty( $mode['id'] ) ) {
				continue;
			}
			$id = sanitize_key( (string) $mode['id'] );
			if ( $id === '' ) {
				continue;
			}
			$min_plan = sanitize_key( (string) ( $mode['min_plan'] ?? 'free' ) );
			if ( ! in_array( $min_plan, $plans, true ) ) {
				$min_plan = 'free';
			}
			$allowed[ $id ] = array(
				'id'            => $id,
				'label'         => isset( $mode['label'] ) ? sanitize_text_field( (string) $mode['label'] ) : $id,
				'enabled'       => ! empty( $mode['enabled'] ),
				'is_default'    => ! empty( $mode['is_default'] ),
				'guest_allowed' => ! empty( $mode['guest_allowed'] ),
				'min_plan'      => $min_plan,
				'order'         => isset( $mode['order'] ) ? (int) $mode['order'] : 100,
			);
		}

		$stored = get_option( 'bizcity_twinweb_mode_policy_' . (int) $blog_id, array() );
		if ( is_array( $stored ) ) {
			foreach ( $stored as $mode ) {
				if ( ! is_array( $mode ) || empty( $mode['id'] ) ) {
					continue;
				}
				$id = sanitize_key( (string) $mode['id'] );
				if ( $id === '' || ! isset( $allowed[ $id ] ) ) {
					continue;
				}
				$base     = $allowed[ $id ];
				$min_plan = sanitize_key( (string) ( $mode['min_plan'] ?? $base['min_plan'] ) );
				if ( ! in_array( $min_plan, $plans, true ) ) {
					$min_plan = (string) $base['min_plan'];
				}
				$allowed[ $id ] = array(
					'id'            => $id,
					'label'         => isset( $mode['label'] ) ? sanitize_text_field( (string) $mode['label'] ) : (string) $base['label'],
					'enabled'       => array_key_exists( 'enabled', $mode ) ? ! empty( $mode['enabled'] ) : ! empty( $base['enabled'] ),
					'is_default'    => array_key_exists( 'is_default', $mode ) ? ! empty( $mode['is_default'] ) : ! empty( $base['is_default'] ),
					'guest_allowed' => array_key_exists( 'guest_allowed', $mode ) ? ! empty( $mode['guest_allowed'] ) : ! empty( $base['guest_allowed'] ),
					'min_plan'      => $min_plan,
					'order'         => isset( $mode['order'] ) ? (int) $mode['order'] : (int) $base['order'],
				);
			}
		}

		$modes = array_values( $allowed );

		// [2026-08-28 Johnny Chu] PHASE-1.32 — enforce free mode availability regardless of stale saved toggles.
		foreach ( $modes as &$mode ) {
			if ( ( $mode['min_plan'] ?? 'free' ) === 'free' ) {
				$mode['enabled'] = true;
			}
		}
		unset( $mode );

		$default_count = 0;
		foreach ( $modes as $mode ) {
			if ( ! empty( $mode['is_default'] ) && ! empty( $mode['enabled'] ) ) {
				$default_count++;
			}
		}

		if ( $default_count !== 1 ) {
			$default_set = false;
			foreach ( $modes as &$mode ) {
				if ( ! empty( $mode['enabled'] ) && ! $default_set ) {
					$mode['is_default'] = true;
					$default_set = true;
				} else {
					$mode['is_default'] = false;
				}
			}
			unset( $mode );
		}

		usort( $modes, static function ( $a, $b ) {
			return (int) ( $a['order'] ?? 100 ) - (int) ( $b['order'] ?? 100 );
		} );

		return $modes;
	}

	/**
	 * Default TwinWeb grounding policy. Default is AskBrain parity: no TwinWeb
	 * surface override unless admin explicitly enables strict Guru mode.
	 *
	 * @return array
	 */
	private function default_grounding_policy() {
		// [2026-07-19 Johnny Chu] PHASE-TWINWEB-W0.17 — AskBrain parity is the default; strict Guru override is opt-in.
		$policy = array(
			'enabled'              => false,
			'configured'           => false,
			'profile'              => 'strict',
			'citation_mode'        => 'every_claim',
			'override_guru'        => false,
			'brain_auto_mode'      => false,
			'guru_id'              => 0,
			'twinweb_system_prompt'=> '',
			'guru_system_prompt'   => '',
			'updated_at'           => '',
			'updated_by'           => 0,
			'mcp_allowed_notebook_ids' => array(),
			'mcp_excluded_notebook_ids' => array(),
			'mcp_exclusion_mode'   => false,
		);
		return (array) apply_filters( 'bizcity_twinweb_default_grounding_policy', $policy );
	}

	/**
	 * Read effective TwinWeb grounding policy for a blog.
	 *
	 * @param int $blog_id Blog ID.
	 * @return array
	 */
	private function get_twinweb_grounding_policy( $blog_id ) {
		$raw = get_option( 'bizcity_twinweb_grounding_policy_' . (int) $blog_id, array() );
		return $this->normalize_grounding_policy( is_array( $raw ) ? $raw : array(), ! empty( $raw ) );
	}

	/**
	 * Normalize admin-submitted grounding policy.
	 *
	 * @param array $raw Raw policy.
	 * @param bool  $configured Whether this came from a stored/admin config.
	 * @return array
	 */
	private function normalize_grounding_policy( array $raw, $configured = true ) {
		$base = $this->default_grounding_policy();
		$profile = isset( $raw['profile'] ) ? sanitize_key( (string) $raw['profile'] ) : (string) $base['profile'];
		if ( ! in_array( $profile, array( 'strict', 'balanced', 'loose' ), true ) ) {
			$profile = 'balanced';
		}

		$citation_mode = isset( $raw['citation_mode'] ) ? sanitize_key( (string) $raw['citation_mode'] ) : (string) $base['citation_mode'];
		if ( ! in_array( $citation_mode, array( 'every_claim', 'key_claims', 'minimal' ), true ) ) {
			$citation_mode = 'key_claims';
		}

		// [2026-07-28 Johnny Chu] PHASE-0.53-MCP-TWINWEB — normalize mode-derived scope and admin-selected MCP exclusions; legacy positive IDs remain read-compatible until the policy is saved again.
		$has_exclusions = array_key_exists( 'mcp_excluded_notebook_ids', $raw );
		return array(
			'enabled'              => array_key_exists( 'enabled', $raw ) ? ! empty( $raw['enabled'] ) : ! empty( $base['enabled'] ),
			'configured'           => (bool) $configured,
			'profile'              => $profile,
			'citation_mode'        => $citation_mode,
			'override_guru'        => array_key_exists( 'override_guru', $raw ) ? ! empty( $raw['override_guru'] ) : ! empty( $base['override_guru'] ),
			'brain_auto_mode'      => array_key_exists( 'brain_auto_mode', $raw ) ? ! empty( $raw['brain_auto_mode'] ) : ! empty( $base['brain_auto_mode'] ),
			'guru_id'              => isset( $raw['guru_id'] ) ? absint( $raw['guru_id'] ) : (int) $base['guru_id'],
			'twinweb_system_prompt'=> isset( $raw['twinweb_system_prompt'] ) ? wp_kses_post( (string) $raw['twinweb_system_prompt'] ) : (string) $base['twinweb_system_prompt'],
			'guru_system_prompt'   => isset( $raw['guru_system_prompt'] ) ? wp_kses_post( (string) $raw['guru_system_prompt'] ) : (string) $base['guru_system_prompt'],
			'updated_at'           => isset( $raw['updated_at'] ) ? sanitize_text_field( (string) $raw['updated_at'] ) : (string) $base['updated_at'],
			'updated_by'           => isset( $raw['updated_by'] ) ? (int) $raw['updated_by'] : (int) $base['updated_by'],
			'mcp_allowed_notebook_ids' => array_values( array_unique( array_filter( array_map( 'intval', (array) ( isset( $raw['mcp_allowed_notebook_ids'] ) ? $raw['mcp_allowed_notebook_ids'] : $base['mcp_allowed_notebook_ids'] ) ) ) ) ),
			'mcp_excluded_notebook_ids' => array_values( array_unique( array_filter( array_map( 'intval', (array) ( $has_exclusions ? $raw['mcp_excluded_notebook_ids'] : $base['mcp_excluded_notebook_ids'] ) ), function ( $id ) { return $id > 0; } ) ) ),
			'mcp_exclusion_mode'   => $has_exclusions || ! empty( $raw['mcp_exclusion_mode'] ),
		);
	}

	/* ── Admin modes allowlist ────────────────────────────────────────── */

	/**
	 * GET /admin/grounding — read TwinWeb grounding and prompt policy.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public function admin_get_grounding( WP_REST_Request $request ) {
		// [2026-07-18 Johnny Chu] PHASE-TWINWEB — expose surface-level prompt/grounding override for Channel Gateway UI.
		$blog_id = (int) get_current_blog_id();
		$policy  = $this->get_twinweb_grounding_policy( $blog_id );

		return rest_ensure_response( array(
			'success' => true,
			'blog_id' => $blog_id,
			'policy'  => $policy,
			'help'    => array(
				// [2026-07-19 Johnny Chu] PHASE-TWINWEB-W0.17 — expose AskBrain parity default to Channel Gateway UI.
				'priority' => 'Default is AskBrain parity: Twin GPT follows TwinBrain/TwinChat notebook grounding. Enable strict Guru override only after admin chooses a Guru and attaches the intended notebooks.',
				'profiles' => array(
					'strict'   => 'AskBrain parity: bam sat Notebook Source Layer nhu TwinChat, citation day du cho moi luan diem co nguon.',
					'balanced' => 'Chi dung khi admin chu dong noi Twin GPT sau khi da gan Guru/notebooks.',
					'loose'    => 'Noi long: uu tien cau tra loi huu ich, cite cac diem quan trong va noi ro khi ngoai RAG.',
				),
			),
		) );
	}

	/**
	 * GET /admin/guru-catalog — read-only Guru Studio catalog contract.
	 *
	 * This endpoint exposes existing data only. It does not grant Guru access,
	 * resolve per-user authorization, or persist vertical/notebook policy.
	 */
	public function admin_get_guru_catalog( WP_REST_Request $request ) {
		// [2026-08-13 Johnny Chu] PHASE-TWIN-GURU-UI — consolidate existing control-plane data for the UI-first catalog.
		$blog_id = (int) get_current_blog_id();
		$gurus   = array();
		$rows    = class_exists( 'BizCity_Knowledge_Database' )
			? BizCity_Knowledge_Database::instance()->get_characters( array( 'limit' => 200 ) )
			: array();

		foreach ( (array) $rows as $row ) {
			$gurus[] = array(
				'id'     => (int) ( $row->id ?? 0 ),
				'name'   => (string) ( $row->name ?? '' ),
				'slug'   => (string) ( $row->slug ?? '' ),
				'guru_uuid' => (string) ( $row->guru_uuid ?? '' ),
				'avatar' => (string) ( $row->avatar ?? '' ),
				'status' => (string) ( $row->status ?? '' ),
			);
		}

		$bindings = class_exists( 'BizCity_Channel_Binding' )
			? BizCity_Channel_Binding::all()
			: array();
		// [2026-08-28 Johnny Chu] PHASE-1.32 — expose merged mode policy to Guru control plane so free-mode availability is consistent across tabs.
		$modes    = $this->get_mode_policy( $blog_id );
		$access   = $this->get_access_policy( $blog_id );
		$grounding = $this->get_twinweb_grounding_policy( $blog_id );
		$cp_ver   = (string) get_option( 'bizcity_twinweb_cp_ver_' . $blog_id, '1' );
		$updated_at = (string) ( $grounding['updated_at'] ?? $access['updated_at'] ?? '' );

		return rest_ensure_response( array(
			'success' => true,
			'blog_id' => $blog_id,
			'cp_ver'  => $cp_ver,
			'updated_at' => $updated_at,
			'gurus'   => $gurus,
			'bindings' => is_array( $bindings ) ? $bindings : array(),
			'modes'   => array_values( $modes ),
			'access_summary' => array(
				'guest_enabled' => ! empty( $access['guest']['enabled'] ),
				'guest_daily_quota' => (int) ( $access['guest']['daily_quota'] ?? 0 ),
				'member_min_tier' => (string) ( $access['member']['min_tier'] ?? 'free' ),
				'member_allowed_roles_count' => is_array( $access['member']['allowed_roles'] ?? null ) ? count( $access['member']['allowed_roles'] ) : 0,
				'allow_user_count' => is_array( $access['users']['allow_user_ids'] ?? null ) ? count( $access['users']['allow_user_ids'] ) : 0,
				'deny_user_count' => is_array( $access['users']['deny_user_ids'] ?? null ) ? count( $access['users']['deny_user_ids'] ) : 0,
			),
			'grounding_summary' => array(
				'profile' => (string) ( $grounding['profile'] ?? 'strict' ),
				'enabled' => ! empty( $grounding['enabled'] ),
				'brain_auto_mode' => ! empty( $grounding['brain_auto_mode'] ),
				'guru_id' => (int) ( $grounding['guru_id'] ?? 0 ),
				'excluded_notebook_count' => is_array( $grounding['mcp_excluded_notebook_ids'] ?? null ) ? count( $grounding['mcp_excluded_notebook_ids'] ) : 0,
			),
			'policy_contract' => array(
				'guru_vertical_acl' => 'pending',
				'guru_audience_acl' => 'pending',
				'notebook_policy' => 'pending',
				'central_brain_policy' => 'pending',
			),
		) );
	}

	/**
	 * GET /admin/guru-workspaces — summary projection for TwinChat/TwinWeb.
	 */
	public function admin_get_guru_workspaces( WP_REST_Request $request ) {
		// [2026-08-16 Johnny Chu] GWU-1 — keep inventory summary bounded; hydrate one detail only on selection.
		$catalog_response = $this->admin_get_guru_catalog( $request );
		$catalog = $catalog_response instanceof WP_REST_Response ? $catalog_response->get_data() : array();
		$gurus = is_array( $catalog['gurus'] ?? null ) ? $catalog['gurus'] : array();
		$bindings = is_array( $catalog['bindings'] ?? null ) ? $catalog['bindings'] : array();
		$notebook_counts = $this->guru_workspace_notebook_counts();
		$items = array();
		foreach ( $gurus as $guru ) {
			$guru_id = (int) ( $guru['id'] ?? 0 );
			$bound = array_values( array_filter( $bindings, static function ( $binding ) use ( $guru_id ) {
				return is_array( $binding ) && (int) ( $binding['character_id'] ?? 0 ) === $guru_id && (int) ( $binding['status'] ?? 1 ) === 1;
			} ) );
			$items[] = array(
				'guru_id'        => $guru_id,
				'guru_uuid'      => (string) ( $guru['guru_uuid'] ?? '' ),
				'name'           => (string) ( $guru['name'] ?? '' ),
				'slug'           => (string) ( $guru['slug'] ?? '' ),
				'avatar'         => (string) ( $guru['avatar'] ?? '' ),
				'status'         => (string) ( $guru['status'] ?? '' ),
				'notebook_count' => (int) ( $notebook_counts[ $guru_id ] ?? 0 ),
				'channel_count'  => count( $bound ),
				'channels'       => array_values( array_map( static function ( $binding ) {
					return array(
						'platform'   => (string) ( $binding['platform'] ?? '' ),
						'account_id' => (string) ( $binding['account_id'] ?? '' ),
						'zone'       => (string) ( $binding['zone'] ?? '' ),
					);
				}, array_slice( $bound, 0, 12 ) ) ),
				'readiness'     => $this->guru_workspace_readiness( $guru ),
				'policy_contract' => (array) ( $catalog['policy_contract'] ?? array() ),
			);
		}
		return rest_ensure_response( array(
			'success' => true,
			'scope'   => 'guru_workspace',
			'blog_id' => (int) ( $catalog['blog_id'] ?? get_current_blog_id() ),
			'cp_ver'  => (string) ( $catalog['cp_ver'] ?? '1' ),
			'items'   => $items,
		) );
	}

	/**
	 * GET /admin/guru-workspaces/{guru_id} — detailed projection for one Guru Workspace.
	 */
	public function admin_get_guru_workspace( WP_REST_Request $request ) {
		// [2026-08-16 Johnny Chu] GWU-1 — hydrate existing Quick Edit/policy contracts without creating a second write owner.
		$guru_id = absint( $request->get_param( 'guru_id' ) );
		$guru = class_exists( 'BizCity_Knowledge_Database' )
			? BizCity_Knowledge_Database::instance()->get_character( $guru_id )
			: null;
		if ( ! $guru ) {
			return rest_ensure_response( BizCity_Error_Payload::make( 'not_found', 'Không tìm thấy Guru Workspace.', 'Chọn Guru thuộc site hiện tại rồi thử lại.', 'twinweb_guru_scope' ) );
		}

		$quick = array();
		if ( class_exists( 'BizCity_Character_Quick_Edit_REST' ) ) {
			$quick_request = new WP_REST_Request( 'GET' );
			$quick_request->set_param( 'id', $guru_id );
			$quick_payload = BizCity_Character_Quick_Edit_REST::get_payload( $quick_request );
			if ( $quick_payload instanceof WP_REST_Response ) {
				$quick = (array) $quick_payload->get_data();
			} elseif ( is_array( $quick_payload ) ) {
				$quick = $quick_payload;
			}
		}
		$bindings = class_exists( 'BizCity_Channel_Binding' ) ? BizCity_Channel_Binding::all() : array();
		$channels = array_values( array_filter( (array) $bindings, static function ( $binding ) use ( $guru_id ) {
			return is_array( $binding ) && (int) ( $binding['character_id'] ?? 0 ) === $guru_id && (int) ( $binding['status'] ?? 1 ) === 1;
		} ) );
		$allowed = class_exists( 'BizCity_TwinBrain_Guru_Policy' )
			? BizCity_TwinBrain_Guru_Policy::normalize_verticals( (string) ( $guru->allowed_verticals ?? '' ) )
			: array();
		$policy = array(
			'allowed_verticals' => $allowed,
			'notebook_policy'   => in_array( (string) ( $guru->notebook_policy ?? 'augment' ), array( 'augment', 'restrict' ), true ) ? (string) $guru->notebook_policy : 'augment',
			'min_role'          => sanitize_key( (string) ( $guru->min_role ?? '' ) ),
			'min_plan'          => sanitize_key( (string) ( $guru->min_plan ?? '' ) ),
			'status'            => class_exists( 'BizCity_TwinBrain_Guru_Policy' ) ? BizCity_TwinBrain_Guru_Policy::STATUS_ENFORCED : 'pending',
		);
		return rest_ensure_response( array(
			'success' => true,
			'scope'   => 'guru_workspace',
			'blog_id' => (int) get_current_blog_id(),
			'workspace' => array(
				'guru_id'   => $guru_id,
				'guru_uuid' => (string) ( $guru->guru_uuid ?? '' ),
				'name'      => (string) ( $guru->name ?? '' ),
				'slug'      => (string) ( $guru->slug ?? '' ),
				'avatar'    => (string) ( $guru->avatar ?? '' ),
				'status'    => (string) ( $guru->status ?? '' ),
				'readiness' => $this->guru_workspace_readiness( array( 'status' => (string) ( $guru->status ?? '' ) ) ),
				'character_edit_url' => admin_url( 'admin.php?page=bizcity-knowledge-character-edit&id=' . $guru_id . '&bizcity_iframe=1' ),
			),
			'identity' => (array) ( $quick['character'] ?? array() ),
			'runtime'  => (array) ( $quick['runtime'] ?? array() ),
			'notebooks' => array_values( (array) ( $quick['notebooks_attached'] ?? array() ) ),
			'quick_training' => (array) ( $quick['quick_training'] ?? array() ),
			'policy' => $policy,
			'channels' => array_values( array_map( static function ( $binding ) {
				return array(
					'platform'   => (string) ( $binding['platform'] ?? '' ),
					'account_id' => (string) ( $binding['account_id'] ?? '' ),
					'zone'       => (string) ( $binding['zone'] ?? '' ),
					'mode'       => (string) ( $binding['mode'] ?? '' ),
				);
			}, $channels ) ),
			'preview' => array( 'read_only' => true, 'execution_dispatched' => false ),
		) );
	}

	private function guru_workspace_notebook_counts() {
		global $wpdb;
		if ( ! class_exists( 'BizCity_KG_Database' ) ) {
			return array();
		}
		$db = BizCity_KG_Database::instance();
		$characters = $wpdb->prefix . 'bizcity_characters';
		$notebooks = $db->tbl_notebooks();
		$attachments = $db->tbl_notebook_character_attachments();
		$rows = $wpdb->get_results( "SELECT c.id AS guru_id, COUNT(DISTINCT a.notebook_id) AS notebook_count FROM {$characters} c INNER JOIN {$attachments} a ON a.guru_uuid = c.guru_uuid INNER JOIN {$notebooks} n ON n.id = a.notebook_id GROUP BY c.id", ARRAY_A );
		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[ (int) $row['guru_id'] ] = (int) $row['notebook_count'];
		}
		return $out;
	}

	private function guru_workspace_readiness( array $guru ) {
		$status = strtolower( (string) ( $guru['status'] ?? '' ) );
		if ( ! in_array( $status, array( 'active', 'published' ), true ) ) {
			return array( 'status' => 'draft', 'ready' => false, 'reason' => 'guru_not_publishable' );
		}
		return array( 'status' => 'ready', 'ready' => true, 'reason' => '' );
	}

	/**
	 * GET /admin/guru-policy/{guru_id} — read the persisted Guru vertical policy.
	 */
	public function admin_get_guru_policy( WP_REST_Request $request ) {
		// [2026-08-14 Johnny Chu] PHASE-TWB-GURU-POLICY — expose only current-blog Guru policy state.
		$guru_id = absint( $request->get_param( 'guru_id' ) );
		$guru = class_exists( 'BizCity_Knowledge_Database' )
			? BizCity_Knowledge_Database::instance()->get_character( $guru_id )
			: null;
		if ( ! $guru ) {
			return rest_ensure_response( BizCity_Error_Payload::make( 'not_found', 'Không tìm thấy Guru trong site hiện tại.', 'Chọn một Guru thuộc site này rồi thử lại.', 'twinweb_guru_scope' ) );
		}
		$allowed = class_exists( 'BizCity_TwinBrain_Guru_Policy' )
			? BizCity_TwinBrain_Guru_Policy::normalize_verticals( (string) ( $guru->allowed_verticals ?? '' ) )
			: array();
		return rest_ensure_response( array(
			'success' => true,
			'blog_id' => (int) get_current_blog_id(),
			'guru'    => array( 'id' => (int) $guru->id, 'name' => (string) $guru->name ),
			'policy'  => array(
				'allowed_verticals' => $allowed,
				'notebook_policy'   => in_array( (string) ( $guru->notebook_policy ?? 'augment' ), array( 'augment', 'restrict' ), true ) ? (string) $guru->notebook_policy : 'augment',
				'min_role'          => sanitize_key( (string) ( $guru->min_role ?? '' ) ),
				'min_plan'          => sanitize_key( (string) ( $guru->min_plan ?? '' ) ),
				'audience_policy'   => 'enforced',
				'status'            => class_exists( 'BizCity_TwinBrain_Guru_Policy' ) ? BizCity_TwinBrain_Guru_Policy::STATUS_ENFORCED : 'pending',
			),
		) );
	}

	/**
	 * PUT /admin/guru-policy/{guru_id} — persist the server-owned vertical allowlist.
	 */
	public function admin_put_guru_policy( WP_REST_Request $request ) {
		// [2026-08-14 Johnny Chu] PHASE-TWB-GURU-POLICY — write only the implemented allowlist field and keep future policy fields pending.
		$guru_id = absint( $request->get_param( 'guru_id' ) );
		$guru = class_exists( 'BizCity_Knowledge_Database' )
			? BizCity_Knowledge_Database::instance()->get_character( $guru_id )
			: null;
		if ( ! $guru ) {
			return rest_ensure_response( BizCity_Error_Payload::make( 'not_found', 'Không tìm thấy Guru trong site hiện tại.', 'Chọn một Guru thuộc site này rồi thử lại.', 'twinweb_guru_scope' ) );
		}
		$raw = (array) $request->get_param( 'policy' );
		$allowed = class_exists( 'BizCity_TwinBrain_Guru_Policy' )
			? BizCity_TwinBrain_Guru_Policy::normalize_verticals( $raw['allowed_verticals'] ?? array() )
			: array();
		$min_role = sanitize_key( (string) ( $raw['min_role'] ?? '' ) );
		$min_plan = sanitize_key( (string) ( $raw['min_plan'] ?? '' ) );
		if ( ! in_array( $min_role, array( '', 'subscriber', 'contributor', 'author', 'editor', 'administrator' ), true ) ) { $min_role = ''; }
		if ( ! in_array( $min_plan, array( '', 'free', 'plus', 'pro' ), true ) ) { $min_plan = ''; }
		$db = BizCity_Knowledge_Database::instance();
		$result = $db->update_character( $guru_id, array( 'allowed_verticals' => wp_json_encode( $allowed ), 'min_role' => $min_role, 'min_plan' => $min_plan ) );
		if ( is_wp_error( $result ) ) {
			return rest_ensure_response( BizCity_Error_Payload::from_wp_error( $result, 'Kiểm tra policy Guru rồi lưu lại.', 'invalid_param_generic' ) );
		}
		$this->bump_control_plane_version( (int) get_current_blog_id() );
		$this->flush_effective_config_cache( (int) get_current_blog_id() );
		return $this->admin_get_guru_policy( $request );
	}

	/**
	 * POST /admin/guru-policy/preview — evaluate policy metadata without executing a tool.
	 */
	public function admin_preview_guru_policy( WP_REST_Request $request ) {
		// [2026-08-14 Johnny Chu] PHASE-TWB-GURU-POLICY — preview only; never dispatches Woo, LLM, CRM or resource reads.
		$guru_id = absint( $request->get_param( 'guru_id' ) );
		$guru = class_exists( 'BizCity_Knowledge_Database' )
			? BizCity_Knowledge_Database::instance()->get_character( $guru_id )
			: null;
		if ( ! $guru ) {
			return rest_ensure_response( BizCity_Error_Payload::make( 'not_found', 'Không tìm thấy Guru trong site hiện tại.', 'Chọn một Guru thuộc site này rồi thử lại.', 'twinweb_guru_scope' ) );
		}
		$capability = sanitize_key( (string) $request->get_param( 'capability' ) );
		$allowed_capabilities = class_exists( 'BizCity_TwinBrain_Guru_Policy' )
			? BizCity_TwinBrain_Guru_Policy::SUPPORTED_VERTICALS
			: array( 'woo_bizops' );
		if ( ! in_array( $capability, $allowed_capabilities, true ) ) {
			$capability = 'woo_bizops';
		}
		$decision = class_exists( 'BizCity_TwinBrain_Guru_Policy' )
			? BizCity_TwinBrain_Guru_Policy::decide( array(
				'user_id'    => absint( $request->get_param( 'actor_user_id' ) ),
				'guru_id'    => $guru_id,
				'surface'    => sanitize_key( (string) $request->get_param( 'surface' ) ) ?: 'twinweb',
				'capability' => $capability,
				'required_role' => sanitize_key( (string) $request->get_param( 'required_role' ) ),
				'required_plan' => sanitize_key( (string) $request->get_param( 'required_plan' ) ),
				'target_resource' => (array) $request->get_param( 'target_resource' ),
			) )
			: array( 'allowed' => false, 'status' => 'pending', 'reason' => 'guru_policy_pending', 'evidence' => array() );
		return rest_ensure_response( array(
			'success' => true,
			'preview' => true,
			'guru'    => array( 'id' => (int) $guru->id, 'name' => (string) $guru->name ),
			'decision' => $decision,
			'execution' => array( 'dispatched' => false, 'side_effects' => false ),
		) );
	}

	/**
	 * PUT /admin/grounding — write TwinWeb grounding and prompt policy.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public function admin_put_grounding( WP_REST_Request $request ) {
		// [2026-07-18 Johnny Chu] PHASE-TWINWEB — persist admin-controlled softness/system-prompt policy.
		$blog_id = (int) get_current_blog_id();
		$raw     = (array) $request->get_param( 'policy' );
		$policy  = $this->normalize_grounding_policy( $raw, true );
		$policy['updated_at'] = current_time( 'mysql', true );
		$policy['updated_by'] = (int) get_current_user_id();

		update_option( 'bizcity_twinweb_grounding_policy_' . $blog_id, $policy, false );
		$this->bump_control_plane_version( $blog_id );
		$this->flush_effective_config_cache( $blog_id );

		return rest_ensure_response( array(
			'success' => true,
			'policy'  => $policy,
		) );
	}

	/**
	 * GET /admin/usage — usage dashboard payload (admin only).
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public function admin_get_usage( WP_REST_Request $request ) {
		// [2026-07-15 Johnny Chu] PHASE-TWIN-GPT-CP W2 — expose per-plan/member usage payload for Usage tab.
		$blog_id = (int) get_current_blog_id();
		$period  = sanitize_key( (string) $request->get_param( 'period' ) );
		$limit   = (int) $request->get_param( 'limit' );

		if ( ! in_array( $period, array( '24h', '7d', '30d' ), true ) ) {
			$period = '7d';
		}
		if ( $limit <= 0 ) {
			$limit = 20;
		}
		$limit = max( 5, min( 100, $limit ) );

		$cache_key = 'tw_usage_' . $blog_id . '_' . sanitize_key( $period ) . '_' . $limit;
		$cached    = wp_cache_get( $cache_key, 'bizcity_twinweb' );
		if ( false !== $cached && is_array( $cached ) ) {
			return rest_ensure_response( $cached );
		}

		$stats      = array(
			'total_calls'             => 0,
			'success_count'           => 0,
			'error_count'             => 0,
			'fallback_count'          => 0,
			'total_prompt_tokens'     => 0,
			'total_completion_tokens' => 0,
			'total_tokens'            => 0,
			'avg_latency_ms'          => 0,
			'max_latency_ms'          => 0,
		);
		$stats_by_service = array();
		$stats_by_user    = array();
		$top_models       = array();

		if ( class_exists( 'BizCity_LLM_Usage_File_Log' ) ) {
			// [2026-07-25 Johnny Chu] R-LLM-USAGE-FILELOG — TwinWeb Usage tab must reflect B2B2C flow (LLM Router => BizCity LLM => TwinWeb/TwinClient), not TwinChat B2B traffic.
			$usage_filters = array(
				'flow' => 'b2b2c',
				'surface' => 'twinweb',
				'channel' => 'twinclient',
			);
			$stats = (array) BizCity_LLM_Usage_File_Log::get_stats( $period, $usage_filters );
			$stats_by_service = (array) BizCity_LLM_Usage_File_Log::get_stats_by_service( $period, $usage_filters );
			$stats_by_user = (array) BizCity_LLM_Usage_File_Log::get_stats_by_user( $period, $usage_filters );
			$top_models = (array) BizCity_LLM_Usage_File_Log::get_top_models( 8, $period, $usage_filters );
		}

		$stats_by_user = array_slice( $stats_by_user, 0, $limit );

		$users = array();
		$plan_breakdown = array();
		foreach ( $stats_by_user as $row ) {
			$uid = isset( $row['user_id'] ) ? (int) $row['user_id'] : 0;
			if ( $uid <= 0 ) {
				continue;
			}

			$tier = (string) apply_filters( 'bizcity_twinweb_user_tier', 'free', $uid );
			$tier = sanitize_key( $tier );
			if ( $tier === '' ) {
				$tier = 'free';
			}

			if ( ! isset( $plan_breakdown[ $tier ] ) ) {
				$plan_breakdown[ $tier ] = 0;
			}
			$plan_breakdown[ $tier ]++;

			$chat = array(
				'used'      => 0,
				'limit'     => -1,
				'remaining' => -1,
			);
			// [2026-10-09 21:10 Johnny Chu - Chu Hoàng Anh] PHASE-0.96 D96-23 — membership retired: no Membership usage snapshot; the twinweb transient counter below is the only source.
			// [2026-07-15 Johnny Chu] PHASE-TWIN-GPT-CP W2 — fallback to twinweb transient counter if membership snapshot not available.
			if ( $chat['used'] === 0 && $chat['limit'] < 0 ) {
				$transient_used = (int) get_transient( 'tw_user_' . $uid . '_quota_' . gmdate( 'Y-m-d' ) );
				if ( $transient_used > 0 ) {
					$chat['used'] = $transient_used;
					$chat['remaining'] = -1;
				}
			}

			$users[] = array(
				'user_id'      => $uid,
				'display_name' => isset( $row['display_name'] ) ? sanitize_text_field( (string) $row['display_name'] ) : '#' . $uid,
				'tier'         => $tier,
				'total_calls'  => isset( $row['total_calls'] ) ? (int) $row['total_calls'] : 0,
				'success_count'=> isset( $row['success_count'] ) ? (int) $row['success_count'] : 0,
				'total_tokens' => isset( $row['total_tokens'] ) ? (int) $row['total_tokens'] : 0,
				'avg_latency'  => isset( $row['avg_latency'] ) ? (int) round( (float) $row['avg_latency'] ) : 0,
				'chat_quota'   => $chat,
			);
		}

		$service_rows = array();
		foreach ( $stats_by_service as $svc => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$service_rows[] = array(
				'service'      => sanitize_key( (string) ( $row['service'] ?? $svc ) ),
				'total_calls'  => (int) ( $row['total_calls'] ?? 0 ),
				'success_count'=> (int) ( $row['success_count'] ?? 0 ),
				'error_count'  => (int) ( $row['error_count'] ?? 0 ),
				'total_tokens' => (int) ( $row['total_tokens'] ?? 0 ),
				'avg_latency'  => (int) ( $row['avg_latency_ms'] ?? 0 ),
			);
		}
		usort( $service_rows, function ( $a, $b ) {
			return (int) $b['total_calls'] - (int) $a['total_calls'];
		} );

		$access_policy = $this->get_access_policy( $blog_id );
		$payload = array(
			'success' => true,
			'blog_id' => $blog_id,
			'period'  => $period,
			'generated_at' => gmdate( 'c' ),
			'summary' => array(
				'total_calls'    => (int) ( $stats['total_calls'] ?? 0 ),
				'success_count'  => (int) ( $stats['success_count'] ?? 0 ),
				'error_count'    => (int) ( $stats['error_count'] ?? 0 ),
				'total_tokens'   => (int) ( $stats['total_tokens'] ?? 0 ),
				'avg_latency_ms' => (int) ( $stats['avg_latency_ms'] ?? 0 ),
				'max_latency_ms' => (int) ( $stats['max_latency_ms'] ?? 0 ),
				'active_members' => count( $users ),
			),
			'quotas' => array(
				'guest_daily_quota' => isset( $access_policy['guest']['daily_quota'] ) ? (int) $access_policy['guest']['daily_quota'] : 0,
				'plan_matrix'       => isset( $access_policy['plan_matrix'] ) && is_array( $access_policy['plan_matrix'] ) ? $access_policy['plan_matrix'] : array(),
			),
			'plan_breakdown' => $plan_breakdown,
			'services'       => $service_rows,
			'top_users'      => $users,
			'top_models'     => is_array( $top_models ) ? array_values( $top_models ) : array(),
		);

		wp_cache_set( $cache_key, $payload, 'bizcity_twinweb', 60 );
		return rest_ensure_response( $payload );
	}

	/**
	 * GET /admin/astro — Astro readiness and R-COACHEE health.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public function admin_get_astro( WP_REST_Request $request ) {
		// [2026-07-18 Johnny Chu] SPRINT-18 CP-ASTRO-AUTO — read-only Astro readiness payload for Control Plane.
		// [2026-10-09 10:33 PM Johnny Chu - Chu Hoàng Anh] CORE-REDUCTION WP-20 W20-BZ10 — core no longer reads the bccm_* tables
		// (D-W20-8): the bizcoach-pro plugin fills the payload through `bizcity_twinweb_admin_astro_readiness`. Plugin off ⇒ the
		// same shape with module_loaded=false and no table query.
		$blog_id   = (int) get_current_blog_id();
		$cache_key = 'tw_admin_astro_' . $blog_id;
		$cached    = wp_cache_get( $cache_key, 'bizcity_twinweb' );
		if ( false !== $cached && is_array( $cached ) ) {
			return rest_ensure_response( $cached );
		}

		$payload = array(
			'success'          => true,
			'blog_id'          => $blog_id,
			'generated_at'     => gmdate( 'c' ),
			'module_loaded'    => false,
			'tables'           => array(),
			'summary'          => array(
				'total_subjects'         => 0,
				'owners_total'           => 0,
				'owners_with_self'       => 0,
				'duplicate_self_owners'  => 0,
				'subjects_without_owner' => 0,
				'astro_rows'             => 0,
				'relation_rows'          => 0,
			),
			'_degraded'        => true,
			'degraded_reasons' => array( 'bizcoach_astro_module_not_loaded' ),
		);
		$filtered = apply_filters( 'bizcity_twinweb_admin_astro_readiness', $payload, $blog_id );
		if ( is_array( $filtered ) && ! empty( $filtered['module_loaded'] ) ) {
			$payload = array_merge( $payload, $filtered );
		}

		wp_cache_set( $cache_key, $payload, 'bizcity_twinweb', 60 );
		return rest_ensure_response( $payload );
	}

	/**
	 * GET /admin/automation — Automation owner continuity and run health.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public function admin_get_automation( WP_REST_Request $request ) {
		// [2026-07-18 Johnny Chu] SPRINT-18 CP-ASTRO-AUTO — read-only Automation health payload for Control Plane.
		global $wpdb;
		$blog_id = (int) get_current_blog_id();
		$limit = (int) $request->get_param( 'limit' );
		$limit = max( 5, min( 50, $limit > 0 ? $limit : 12 ) );
		$cache_key = 'tw_admin_automation_' . $blog_id . '_' . $limit;
		$cached = wp_cache_get( $cache_key, 'bizcity_twinweb' );
		if ( false !== $cached && is_array( $cached ) ) {
			return rest_ensure_response( $cached );
		}

		$wf_table  = $wpdb->prefix . 'bizcity_automation_workflows';
		$run_table = $wpdb->prefix . 'bizcity_automation_runs';
		$log_table = $wpdb->prefix . 'bizcity_automation_logs';
		$degraded_reasons = array();

		$tables = array(
			'workflows' => array( 'exists' => self::table_exists( $wf_table ), 'table' => 'bizcity_automation_workflows' ),
			'runs'      => array( 'exists' => self::table_exists( $run_table ), 'table' => 'bizcity_automation_runs' ),
			'logs'      => array( 'exists' => self::table_exists( $log_table ), 'table' => 'bizcity_automation_logs' ),
		);

		$summary = array(
			'workflows_total'      => 0,
			'workflows_enabled'    => 0,
			'runs_total'           => 0,
			'runs_with_user_id'    => 0,
			'runs_legacy_user_zero'=> 0,
			'runs_missing_user_id' => 0,
		);
		$status_counts = array( 'queued' => 0, 'running' => 0, 'ok' => 0, 'fail' => 0, 'cancelled' => 0, 'unknown' => 0 );
		$recent_runs = array();

		if ( ! $tables['workflows']['exists'] || ! $tables['runs']['exists'] ) {
			$degraded_reasons[] = 'automation_table_missing';
		} else {
			$has_created_by = $this->table_has_column( $wf_table, 'created_by' );
			$has_run_user_id = $this->table_has_column( $run_table, 'user_id' );
			$tables['workflows']['has_created_by'] = $has_created_by;
			$tables['runs']['has_user_id'] = $has_run_user_id;

			if ( ! $has_created_by ) {
				$degraded_reasons[] = 'workflow_created_by_missing';
			}
			if ( ! $has_run_user_id ) {
				$degraded_reasons[] = 'run_user_id_missing';
			}

			$summary['workflows_total'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wf_table}" );
			$summary['workflows_enabled'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wf_table} WHERE enabled = 1" );
			$summary['runs_total'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$run_table}" );
			if ( $has_run_user_id ) {
				$summary['runs_with_user_id'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$run_table} WHERE user_id > 0" );
				$summary['runs_legacy_user_zero'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$run_table} WHERE user_id = 0" );
				$summary['runs_missing_user_id'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$run_table} WHERE user_id IS NULL" );
			}

			$status_rows = (array) $wpdb->get_results( "SELECT status, COUNT(*) AS total FROM {$run_table} GROUP BY status", ARRAY_A );
			foreach ( $status_rows as $row ) {
				$meta = $this->automation_status_meta( isset( $row['status'] ) ? (int) $row['status'] : -1 );
				$key = isset( $status_counts[ $meta['key'] ] ) ? $meta['key'] : 'unknown';
				$status_counts[ $key ] += isset( $row['total'] ) ? (int) $row['total'] : 0;
			}

			$user_select = $has_run_user_id ? 'r.user_id' : '0 AS user_id';
			$owner_select = $has_created_by ? 'w.created_by' : '0 AS created_by';
			$recent_rows = (array) $wpdb->get_results( $wpdb->prepare(
				"SELECT r.run_id, r.workflow_id, r.status, r.created_at, r.ended_at, {$user_select}, {$owner_select}, w.name AS workflow_name
				 FROM {$run_table} r
				 LEFT JOIN {$wf_table} w ON w.id = r.workflow_id
				 ORDER BY r.id DESC
				 LIMIT %d",
				$limit
			), ARRAY_A );
			foreach ( $recent_rows as $row ) {
				$status_meta = $this->automation_status_meta( isset( $row['status'] ) ? (int) $row['status'] : -1 );
				$recent_runs[] = array(
					'run_id'        => isset( $row['run_id'] ) ? sanitize_text_field( (string) $row['run_id'] ) : '',
					'workflow_id'   => isset( $row['workflow_id'] ) ? (int) $row['workflow_id'] : 0,
					'workflow_name' => isset( $row['workflow_name'] ) ? sanitize_text_field( (string) $row['workflow_name'] ) : '',
					'user_id'       => isset( $row['user_id'] ) ? (int) $row['user_id'] : 0,
					'created_by'    => isset( $row['created_by'] ) ? (int) $row['created_by'] : 0,
					'status'        => $status_meta['key'],
					'status_label'  => $status_meta['label'],
					'created_at'    => isset( $row['created_at'] ) ? (string) $row['created_at'] : '',
					'ended_at'      => isset( $row['ended_at'] ) ? (string) $row['ended_at'] : '',
				);
			}
		}

		$payload = array(
			'success'          => true,
			'blog_id'          => $blog_id,
			'generated_at'     => gmdate( 'c' ),
			'tables'           => $tables,
			'summary'          => $summary,
			'status_counts'    => $status_counts,
			'recent_runs'      => $recent_runs,
			'_degraded'        => ! empty( $degraded_reasons ),
			'degraded_reasons' => array_values( array_unique( $degraded_reasons ) ),
		);

		wp_cache_set( $cache_key, $payload, 'bizcity_twinweb', 60 );
		return rest_ensure_response( $payload );
	}

	/** @return array<int,int> */
	private function sanitize_attachment_ids( array $raw_ids ) {
		$ids = array();
		foreach ( $raw_ids as $raw_id ) {
			$id = absint( $raw_id );
			if ( $id > 0 ) {
				$ids[] = $id;
			}
		}
		return array_values( array_unique( $ids ) );
	}

	/** @return array<int,array<string,mixed>>|WP_Error */
	private function build_owned_attachment_payload( array $attachment_ids, $user_id ) {
		$payload = array();
		foreach ( $attachment_ids as $attachment_id ) {
			if ( ! $this->user_owns_attachment( (int) $attachment_id, (int) $user_id ) ) {
				return new WP_Error( 'attachment_not_found', 'Attachment is missing or not owned by this user.' );
			}
			$payload[] = $this->format_attachment_payload( (int) $attachment_id );
		}
		return $payload;
	}

	private function user_owns_attachment( $attachment_id, $user_id ) {
		$attachment_id = (int) $attachment_id;
		$user_id = (int) $user_id;
		if ( $attachment_id <= 0 || $user_id <= 0 ) {
			return false;
		}
		$post = get_post( $attachment_id );
		return $post && 'attachment' === $post->post_type && (int) $post->post_author === $user_id;
	}

	/** @return array<string,mixed> */
	private function format_attachment_payload( $attachment_id ) {
		$attachment_id = (int) $attachment_id;
		// [2026-07-19 Johnny Chu] PHASE-TWIN-GPT-AGENT-TOOLS — avoid filesystem warnings for offloaded or missing media files.
		$file_path = (string) get_attached_file( $attachment_id );
		$file_size = ( $file_path !== '' && is_readable( $file_path ) ) ? (int) filesize( $file_path ) : 0;
		if ( $file_size <= 0 ) {
			$file_size = (int) get_post_meta( $attachment_id, '_bizcity_twinweb_attachment_size', true );
		}
		if ( $file_size <= 0 ) {
			$metadata = wp_get_attachment_metadata( $attachment_id );
			if ( is_array( $metadata ) && ! empty( $metadata['filesize'] ) ) {
				$file_size = (int) $metadata['filesize'];
			}
		}
		$stored_filename = sanitize_file_name( (string) get_post_meta( $attachment_id, '_bizcity_twinweb_attachment_filename', true ) );
		return array(
			'id'         => $attachment_id,
			'title'      => sanitize_text_field( (string) get_the_title( $attachment_id ) ),
			'filename'   => sanitize_file_name( $stored_filename !== '' ? $stored_filename : ( $file_path !== '' ? (string) basename( $file_path ) : (string) get_the_title( $attachment_id ) ) ),
			'mime_type'  => sanitize_text_field( (string) get_post_mime_type( $attachment_id ) ),
			'size'       => $file_size,
			'created_at' => (string) get_post_field( 'post_date', $attachment_id ),
			'url'        => esc_url_raw( (string) wp_get_attachment_url( $attachment_id ) ),
		);
	}

	/**
	 * Map numeric automation run status to FE-friendly key + label.
	 *
	 * @param int $status Run status code.
	 * @return array
	 */
	private function automation_status_meta( $status ) {
		// [2026-07-16 Johnny Chu] PHASE-TWINWEB U10 — normalize run status for member FE summary panel.
		$status = (int) $status;
		switch ( $status ) {
			case 0:
				return array( 'key' => 'queued', 'label' => 'Cho xu ly' );
			case 1:
				return array( 'key' => 'running', 'label' => 'Dang chay' );
			case 2:
				return array( 'key' => 'ok', 'label' => 'Thanh cong' );
			case 3:
				return array( 'key' => 'fail', 'label' => 'That bai' );
			case 4:
				return array( 'key' => 'cancelled', 'label' => 'Da huy' );
			default:
				return array( 'key' => 'unknown', 'label' => 'Khong ro' );
		}
	}

	/**
	 * GET /admin/connections — connected identity health dashboard payload.
	 *
	 * Aggregates existing channel-gateway APIs (Facebook + Zalo Bot) and adds
	 * deterministic identity checks for owner-scoped mappings.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public function admin_get_connections( WP_REST_Request $request ) {
		// [2026-07-15 Johnny Chu] PHASE-TWIN-GPT-CP W3 — build Wave-3 connected identity dashboard data from canonical channel APIs.
		$blog_id         = (int) get_current_blog_id();
		$degraded_reasons = array();

		$fb_settings_payload = $this->call_internal_rest_route( 'GET', '/bizcity-channel/v1/facebook/settings', array(), $degraded_reasons, 'facebook_settings' );
		$fb_pages_payload    = $this->call_internal_rest_route( 'GET', '/bizcity-channel/v1/facebook/pages', array(), $degraded_reasons, 'facebook_pages' );
		$fb_bots_payload     = $this->call_internal_rest_route( 'GET', '/bizcity-channel/v1/facebook/bots', array(), $degraded_reasons, 'facebook_bots' );
		$zb_bots_payload     = $this->call_internal_rest_route( 'GET', '/bizcity-channel/v1/zalo-bot/bots', array(), $degraded_reasons, 'zalo_bots' );
		$zb_links_payload    = $this->call_internal_rest_route( 'GET', '/bizcity-channel/v1/zalo-bot/user-links', array(), $degraded_reasons, 'zalo_links' );

		$fb_settings = is_array( $fb_settings_payload ) ? $fb_settings_payload : array();
		$fb_pages    = isset( $fb_pages_payload['pages'] ) && is_array( $fb_pages_payload['pages'] ) ? $fb_pages_payload['pages'] : array();
		$fb_bots     = isset( $fb_bots_payload['bots'] ) && is_array( $fb_bots_payload['bots'] ) ? $fb_bots_payload['bots'] : array();
		$zalo_bots   = isset( $zb_bots_payload['bots'] ) && is_array( $zb_bots_payload['bots'] ) ? $zb_bots_payload['bots'] : array();
		$zalo_links  = isset( $zb_links_payload['links'] ) && is_array( $zb_links_payload['links'] ) ? $zb_links_payload['links'] : array();

		$facebook_pages_with_token = 0;
		$facebook_last_ok          = 0;
		$facebook_last_failed      = 0;
		$facebook_samples          = array();
		foreach ( $fb_pages as $page ) {
			$has_token = ! empty( $page['has_token'] );
			if ( $has_token ) {
				$facebook_pages_with_token++;
			}
			if ( array_key_exists( 'last_check_ok', (array) $page ) ) {
				if ( ! empty( $page['last_check_ok'] ) ) {
					$facebook_last_ok++;
				} elseif ( isset( $page['last_check_ok'] ) && $page['last_check_ok'] !== null ) {
					$facebook_last_failed++;
				}
			}

			if ( count( $facebook_samples ) < 12 ) {
				$facebook_samples[] = array(
					'page_id'       => isset( $page['page_id'] ) ? (string) $page['page_id'] : '',
					'page_name'     => isset( $page['page_name'] ) ? (string) $page['page_name'] : '',
					'source'        => isset( $page['source'] ) ? (string) $page['source'] : '',
					'has_token'     => $has_token,
					'last_check_ok' => isset( $page['last_check_ok'] ) ? (bool) $page['last_check_ok'] : null,
					'last_check_iso'=> isset( $page['last_check_iso'] ) ? (string) $page['last_check_iso'] : '',
				);
			}
		}

		$messenger_active_bots = 0;
		$messenger_ai_bots     = 0;
		foreach ( $fb_bots as $bot ) {
			// [2026-07-29 Johnny Chu] HOTFIX — normalize internal REST rows before array access.
			$bot    = is_object( $bot ) ? get_object_vars( $bot ) : (array) $bot;
			$status = isset( $bot['status'] ) ? sanitize_key( (string) $bot['status'] ) : '';
			if ( $status === 'active' ) {
				$messenger_active_bots++;
			}
			if ( ! empty( $bot['ai_enabled'] ) ) {
				$messenger_ai_bots++;
			}
		}

		$zalo_active_bots = 0;
		foreach ( $zalo_bots as $bot ) {
			// [2026-07-29 Johnny Chu] HOTFIX — normalize internal REST rows before array access.
			$bot    = is_object( $bot ) ? get_object_vars( $bot ) : (array) $bot;
			$status = isset( $bot['status'] ) ? sanitize_key( (string) $bot['status'] ) : '';
			if ( $status === 'active' || $status === '1' || $status === 'enabled' ) {
				$zalo_active_bots++;
			}
		}

		$zalo_links_linked = 0;
		$zalo_links_pending = 0;
		$zalo_samples = array();
		foreach ( $zalo_links as $link ) {
			// [2026-07-29 Johnny Chu] HOTFIX — normalize internal REST rows before array access.
			$link       = is_object( $link ) ? get_object_vars( $link ) : (array) $link;
			$wp_user_id = isset( $link['wp_user_id'] ) ? (int) $link['wp_user_id'] : 0;
			$status     = isset( $link['status'] ) ? sanitize_key( (string) $link['status'] ) : '';
			if ( $wp_user_id > 0 || $status === 'linked' ) {
				$zalo_links_linked++;
			} else {
				$zalo_links_pending++;
			}

			if ( count( $zalo_samples ) < 12 ) {
				$zalo_samples[] = array(
					'id'           => isset( $link['id'] ) ? (int) $link['id'] : 0,
					'bot_id'       => isset( $link['bot_id'] ) ? (int) $link['bot_id'] : 0,
					'zalo_user_id' => isset( $link['zalo_user_id'] ) ? (string) $link['zalo_user_id'] : '',
					'wp_user_id'   => $wp_user_id,
					'status'       => $status,
					'display_name' => isset( $link['display_name'] ) ? (string) $link['display_name'] : '',
				);
			}
		}

		$facebook_scope = $this->get_facebook_scope_stats();
		$zalo_identity  = $this->get_zalo_identity_stats();

		$check_facebook_app = ! empty( $fb_settings['has_app_id'] ) && ! empty( $fb_settings['has_app_secret'] );
		$check_messenger    = $facebook_pages_with_token > 0;
		$check_zalo_bot     = count( $zalo_bots ) > 0;
		$check_zalo_map     = (int) ( $zalo_identity['deterministic_conflicts'] ?? 0 ) === 0;

		$health_checks = array(
			array(
				'id'    => 'facebook_app_config',
				'label' => 'Facebook App cấu hình',
				'ok'    => $check_facebook_app,
				'hint'  => $check_facebook_app ? 'Đủ App ID + App Secret.' : 'Thiếu App ID hoặc App Secret.',
			),
			array(
				'id'    => 'messenger_page_tokens',
				'label' => 'Messenger Page tokens',
				'ok'    => $check_messenger,
				'hint'  => $check_messenger ? 'Đã có Page token sẵn sàng gửi/nhận.' : 'Chưa có page token hoạt động.',
			),
			array(
				'id'    => 'zalo_bot_registered',
				'label' => 'Zalo Bot đã đăng ký',
				'ok'    => $check_zalo_bot,
				'hint'  => $check_zalo_bot ? 'Có bot để nhận/gửi sự kiện.' : 'Chưa có bot active.',
			),
			array(
				'id'    => 'zalo_identity_deterministic',
				'label' => 'Zalo identity deterministic',
				'ok'    => $check_zalo_map,
				'hint'  => $check_zalo_map ? 'Không phát hiện xung đột mapping.' : 'Có xung đột mapping bot_id + zalo_user_id.',
			),
		);

		$health_status = 'ok';
		foreach ( $health_checks as $check ) {
			if ( empty( $check['ok'] ) ) {
				$health_status = 'warning';
				break;
			}
		}
		if ( ! empty( $degraded_reasons ) ) {
			$health_status = 'degraded';
		}

		$payload = array(
			'success'      => true,
			'blog_id'      => $blog_id,
			'generated_at' => gmdate( 'c' ),
			'facebook'     => array(
				'settings'              => array(
					'has_app_id'      => ! empty( $fb_settings['has_app_id'] ),
					'has_app_secret'  => ! empty( $fb_settings['has_app_secret'] ),
					'verify_token_set'=> ! empty( $fb_settings['verify_token'] ),
					'app_id_last4'    => isset( $fb_settings['app_id_last4'] ) ? (string) $fb_settings['app_id_last4'] : '',
				),
				'pages_total'            => count( $fb_pages ),
				'pages_with_token'       => $facebook_pages_with_token,
				'pages_last_check_ok'    => $facebook_last_ok,
				'pages_last_check_failed'=> $facebook_last_failed,
				'owner_scoped_pages'     => (int) ( $facebook_scope['owner_scoped_pages'] ?? 0 ),
				'owner_unscoped_pages'   => (int) ( $facebook_scope['owner_unscoped_pages'] ?? 0 ),
				'pages_sample'           => $facebook_samples,
			),
			'messenger'    => array(
				'bots_total'         => count( $fb_bots ),
				'active_bots'        => $messenger_active_bots,
				'ai_enabled_bots'    => $messenger_ai_bots,
				'capable_page_count' => $facebook_pages_with_token,
			),
			'zalo'         => array(
				'bots_total'                => count( $zalo_bots ),
				'active_bots'               => $zalo_active_bots,
				'links_total'               => count( $zalo_links ),
				'links_linked'              => $zalo_links_linked,
				'links_pending'             => $zalo_links_pending,
				'deterministic_conflicts'   => (int) ( $zalo_identity['deterministic_conflicts'] ?? 0 ),
				'identity_pairs_total'      => (int) ( $zalo_identity['identity_pairs_total'] ?? 0 ),
				'links_sample'              => $zalo_samples,
			),
			'health'       => array(
				'status' => $health_status,
				'checks' => $health_checks,
			),
			'_degraded'         => ! empty( $degraded_reasons ),
			'degraded_reasons'  => array_values( array_unique( $degraded_reasons ) ),
		);

		return rest_ensure_response( $payload );
	}

	/**
	 * Call an internal REST route and return normalized array payload.
	 *
	 * @param string $method HTTP method.
	 * @param string $route Route path.
	 * @param array  $params Query/body params.
	 * @param array  $degraded_reasons Mutable degraded reason collector.
	 * @param string $bucket Reason bucket key.
	 * @return array
	 */
	private function call_internal_rest_route( $method, $route, $params, &$degraded_reasons, $bucket ) {
		$method = strtoupper( (string) $method );
		$route  = (string) $route;
		$params = is_array( $params ) ? $params : array();

		$request = new WP_REST_Request( $method, $route );
		if ( $method === 'GET' ) {
			$request->set_query_params( $params );
		} else {
			$request->set_body_params( $params );
		}

		$response = rest_do_request( $request );
		if ( is_wp_error( $response ) ) {
			$degraded_reasons[] = sanitize_key( (string) $bucket ) . '_wp_error';
			return array();
		}

		$status = method_exists( $response, 'get_status' ) ? (int) $response->get_status() : 500;
		$data   = method_exists( $response, 'get_data' ) ? $response->get_data() : array();
		if ( ! is_array( $data ) ) {
			$data = array();
		}

		if ( $status >= 400 ) {
			$degraded_reasons[] = sanitize_key( (string) $bucket ) . '_http_' . $status;
		}

		if ( ! empty( $data['_degraded'] ) ) {
			$degraded_reasons[] = sanitize_key( (string) $bucket ) . '_degraded';
		}

		return $data;
	}

	/**
	 * Facebook owner-scope stats from bots table.
	 *
	 * @return array
	 */
	private function get_facebook_scope_stats() {
		global $wpdb;

		$table = $wpdb->prefix . 'bizcity_facebook_bots';
		if ( ! self::table_exists( $table ) ) {
			return array(
				'table_exists'         => false,
				'owner_scoped_pages'   => 0,
				'owner_unscoped_pages' => 0,
			);
		}

		if ( ! $this->table_has_column( $table, 'user_id' ) ) {
			return array(
				'table_exists'         => true,
				'owner_scoped_pages'   => 0,
				'owner_unscoped_pages' => 0,
			);
		}

		$scoped = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE user_id > 0" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$total  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return array(
			'table_exists'         => true,
			'owner_scoped_pages'   => $scoped,
			'owner_unscoped_pages' => max( 0, $total - $scoped ),
		);
	}

	/**
	 * Zalo deterministic identity stats from linker table.
	 *
	 * @return array
	 */
	private function get_zalo_identity_stats() {
		global $wpdb;

		$table = class_exists( 'BizCity_Zalobot_User_Linker' )
			? BizCity_Zalobot_User_Linker::table()
			: $wpdb->base_prefix . 'bizcity_zalobot_user_links';

		if ( ! self::table_exists( $table ) ) {
			return array(
				'table_exists'             => false,
				'identity_pairs_total'     => 0,
				'deterministic_conflicts'  => 0,
			);
		}

		$required = array( 'bot_id', 'zalo_user_id', 'wp_user_id' );
		foreach ( $required as $column ) {
			if ( ! $this->table_has_column( $table, $column ) ) {
				return array(
					'table_exists'             => true,
					'identity_pairs_total'     => 0,
					'deterministic_conflicts'  => 0,
				);
			}
		}

		$pair_total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$conflicts  = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM (
				SELECT bot_id, zalo_user_id, COUNT(DISTINCT wp_user_id) AS owner_count
				FROM {$table}
				WHERE wp_user_id > 0
				GROUP BY bot_id, zalo_user_id
				HAVING owner_count > 1
			) AS tw_conflicts" // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);

		return array(
			'table_exists'             => true,
			'identity_pairs_total'     => $pair_total,
			'deterministic_conflicts'  => $conflicts,
		);
	}

	/**
	 * Check whether a column exists in a table using information_schema.
	 *
	 * @param string $table  Full table name.
	 * @param string $column Column name.
	 * @return bool
	 */
	private function table_has_column( $table, $column ) {
		global $wpdb;
		$exists = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM information_schema.COLUMNS
			  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s",
			(string) $table,
			(string) $column
		) );
		return $exists > 0;
	}

	/* ── Appearance / skin policy helpers ──────────────────────────────── */

	private function get_access_policy( $blog_id ) {
		return BizCity_TwinWeb_Access_Policy::get_access_policy( $blog_id );
	}

	private function bump_control_plane_version( $blog_id ) {
		BizCity_TwinWeb_Access_Policy::bump_control_plane_version( $blog_id );
	}

	private function flush_effective_config_cache( $blog_id ) {
		BizCity_TwinWeb_Access_Policy::flush_effective_config_cache( $blog_id );
	}
}
