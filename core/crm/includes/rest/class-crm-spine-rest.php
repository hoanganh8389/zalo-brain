<?php
/**
 * BizCity CRM — REST Controller (read-only M1).
 *
 * [2026-11-20 08:06 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.156 S156-3.2 — core keeps only the 11 routes Channel Gateway / Bot Studio / MCP call
 * (census evidence/crm-spine-routes.md) and the helpers they need. The other 29 routes live in the CRM plugin
 * (bizcity-twin-crm/includes/rest/class-crm-spine-rest-ext.php, BizCity_CRM_Spine_REST_Ext extends this class).
 *
 * Namespace: bizcity-crm/v1
 *
 * Endpoints:
 *   GET /channels                            — adapters registered
 *   GET /inboxes                             — list inboxes
 *   GET /conversations?inbox_id&status&...   — list conversations
 *   GET /conversations/(?P<id>\d+)           — one conversation
 *   GET /conversations/(?P<id>\d+)/messages  — list messages
 *
 * Response shape (R-CRM-5):
 *   { ok:true, data:..., next_cursor:?string, ts: epoch_ms }
 *
 * @package BizCity_Twin_CRM
 */

defined( 'ABSPATH' ) || exit;

class BizCity_CRM_Spine_REST {

	// [2026-10-09 Johnny Chu - Chu Hoàng Anh] PHASE-0.96 S96-2.2 — constants shared with the plugin subclass BizCity_CRM_REST_Controller.
	const CONTACT_ACTIVITY_TYPES  = array( 'note', 'call', 'meeting', 'email', 'task' );

	public static function register_routes(): void {
		$ns = BIZCITY_CRM_REST_NS;

		// [2026-10-16 03:23 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.116 CS1.3 (doc 87) — route /channels had no caller (SPAs, plugin, cell); registration removed, handler kept until CS6 (twin-crm subclasses this class).

		// M7.W1 — wizard: per-channel form schema + verify endpoint.
		// [2026-10-16 03:23 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.116 CS1.3 (doc 87) — route /channels/(?P<code>[a-z0-9_]+) had no caller (SPAs, plugin, cell); registration removed, handler kept until CS6 (twin-crm subclasses this class).

		// [2026-10-16 03:23 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.116 CS1.3 (doc 87) — route /channels/(?P<code>[a-z0-9_]+)/verify had no caller (SPAs, plugin, cell); registration removed, handler kept until CS6 (twin-crm subclasses this class).

		register_rest_route( $ns, '/inboxes', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_inboxes' ),
				'permission_callback' => array( __CLASS__, 'can_read_inbox_scope' ),
			),
			// M7.W1 — wizard create inbox.
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'post_inbox_create' ),
				// [2026-09-17 Johnny Chu - Chu Hoàng Anh] PHASE-0.49-E1 — creating an Inbox is admin-only; employee scope never grants it.
				// [2026-11-20 08:06 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.156 S156-3.2 — HOTFIX: can_read only exists on the plugin subclass, so array( BizCity_CRM_Spine_REST, can_read ) was never callable; admin-only as the line above says.
				'permission_callback' => array( __CLASS__, 'can_manage_rules' ),
				'args'                => array(
					'channel_type' => array( 'type' => 'string', 'required' => true ),
					'config'       => array( 'type' => 'object', 'required' => true ),
				),
			),
		) );

		register_rest_route( $ns, '/conversations', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'get_conversations' ),
			'permission_callback' => array( __CLASS__, 'can_read_inbox_scope' ),
			'args'                => array(
				'inbox_id'    => array( 'type' => 'integer' ),
				'status'      => array( 'type' => 'string', 'enum' => array( 'open', 'pending', 'resolved', 'snoozed' ) ),
				'priority'    => array( 'type' => 'integer', 'minimum' => 0, 'maximum' => 3 ),
				'assignee_id' => array( 'type' => 'integer', 'minimum' => 1 ),
				'scope_user_id' => array( 'type' => 'integer', 'minimum' => 1 ),
				'unassigned'  => array( 'type' => 'boolean' ),
				'label_id'    => array( 'type' => 'integer' ),
				'thread_kind' => array( 'type' => 'string', 'enum' => array( 'group', 'personal', 'user' ) ), // [2026-10-28 06:05 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.146 S146-L2 — + user (= personal)
				'q'           => array( 'type' => 'string' ),
				'limit'       => array( 'type' => 'integer', 'default' => 50 ),
				'before_id'   => array( 'type' => 'integer' ),
				// [2026-09-18 Johnny Chu - Chu Hoàng Anh] PHASE-0.51 A3 — browser cache fingerprint; mirrors modules/twinweb's `/crm/inbox`.
				'sync_token'  => array( 'type' => 'string' ),
				// [PHASE-0.54 R-INBOX-PIPE-8] filter by resolved pipeline stage; 'stuck' is a pseudo-stage.
				'stage'       => array( 'type' => 'string' ),
				// [2026-09-25 PHASE-0.63C GC-21] contact role (catalog key or 'none' = unassigned role) and open pipeline kind.
				'role'        => array( 'type' => 'string' ),
				'pipeline_kind' => array( 'type' => 'string' ),
			),
		) );

		register_rest_route( $ns, '/conversations/(?P<id>\d+)', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'get_conversation' ),
			'permission_callback' => array( __CLASS__, 'can_read_inbox_scope_response' ),
		) );

		register_rest_route( $ns, '/conversations/(?P<id>\d+)/messages', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				// [2026-09-22 12:00 PM OpenAI GPT-5.6 Luna] HOTFIX — restore the missing GET callback; without it WordPress reports rest_invalid_handler before dispatch.
				'callback'            => array( __CLASS__, 'get_messages' ),
				'permission_callback' => array( __CLASS__, 'can_read_inbox_scope' ),
				'args'                => array(
					'after_id' => array( 'type' => 'integer', 'default' => 0 ),
					'limit'    => array( 'type' => 'integer', 'default' => 100 ),
					// [2026-09-18 Johnny Chu - Chu Hoàng Anh] PHASE-0.51 A3 — older-message paging + delivery-state recheck, same shape as modules/twinweb's `/crm/inbox`.
					'recheck_ids' => array( 'type' => 'string', 'default' => '' ),
					'before_id'   => array( 'type' => 'integer', 'default' => 0 ),
				),
			),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'post_message' ),
				'permission_callback' => array( __CLASS__, 'can_write_inbox_scope' ),
			),
		) );

		register_rest_route( $ns, '/conversations/(?P<id>\d+)/notes', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'post_note' ),
			'permission_callback' => array( __CLASS__, 'can_write_inbox_scope' ),
		) );

		register_rest_route( $ns, '/conversations/(?P<id>\d+)/resolve', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'post_resolve' ),
			'permission_callback' => array( __CLASS__, 'can_write_inbox_scope' ),
		) );

		// [2026-10-16 03:23 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.116 CS1.3 (doc 87) — route /teams/(?P<id>\d+)/members had no caller (SPAs, plugin, cell); registration removed, handler kept until CS6 (twin-crm subclasses this class).

		// [2026-10-16 03:23 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.116 CS1.3 (doc 87) — route /conversations/(?P<id>\d+)/team had no caller (SPAs, plugin, cell); registration removed, handler kept until CS6 (twin-crm subclasses this class).

		register_rest_route( $ns, '/contacts/(?P<id>\d+)', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'get_contact' ),
			// [2026-09-21 11:05 PM OpenAI GPT-5.6 Luna] HOTFIX — CRM staff may read an in-scope contact; the admin-only gate caused REST 403s in the Inbox.
			'permission_callback' => array( __CLASS__, 'can_read_contact_scope' ),
			'args'                => array(
				'context_inbox_id' => array( 'type' => 'integer', 'default' => 0 ),
			),
		) );

		// [2026-09-23 04:30 PM Claude Fable 5.1] PHASE-0.60B §7 / 0.60A B-07 — one RailSection's data: enriched profile with
		// source labels, refresh-from-Zalo (throttled), staff birthday edit, customer withdrawal. Same scope gates as /contacts/{id}.
		register_rest_route( $ns, '/contacts/(?P<id>\d+)/bot-context', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'get_contact_bot_context' ),
			'permission_callback' => array( __CLASS__, 'can_read_contact_scope' ),
		) );

		// [2026-09-24 Claude Sonnet 5] PHASE-0.60J BG-6 — staff JSON metadata under additional_attributes.custom_meta.
		register_rest_route( $ns, '/contacts/(?P<id>\d+)/metadata', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'post_contact_metadata' ),
			'permission_callback' => array( __CLASS__, 'can_write_contact_scope' ),
		) );

		register_rest_route( $ns, '/crm-contacts', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_crm_contacts' ),
				// [2026-08-23 Johnny Chu] PHASE-0.39D — contact list now uses owner/inbox scope.
				'permission_callback' => array( __CLASS__, 'can_read_contact_scope' ),
				'args'                => array(
					'account_id' => array( 'type' => 'integer' ),
					'q'          => array( 'type' => 'string'  ),
					'limit'      => array( 'type' => 'integer', 'default' => 100 ),
					'offset'     => array( 'type' => 'integer', 'default' => 0 ),
				),
			),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'post_crm_contact' ),
				'permission_callback' => array( __CLASS__, 'can_write' ),
			),
		) );

		register_rest_route( $ns, '/crm-contacts/(?P<id>\d+)', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_crm_contact' ),
				'permission_callback' => array( __CLASS__, 'can_read_contact_scope' ),
			),
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( __CLASS__, 'put_crm_contact' ),
				'permission_callback' => array( __CLASS__, 'can_write_contact_scope' ),
			),
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( __CLASS__, 'delete_crm_contact' ),
				'permission_callback' => array( __CLASS__, 'can_write_contact_scope' ),
			),
		) );

		// [2026-10-20 10:24 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.126 G-C — /admin-chat-grants* moved to bizcity-twin-crm (class-admin-chat-grants-rest.php).

	}

	public static function can_read_inbox_scope_response( $request = null ) {
		return self::can_read_inbox_scope( $request ) ? true : self::permission_denied_response( 'Bạn chưa được cấp quyền xem Inbox hoặc hội thoại này.' );
	}

	/** Return a structured permission response instead of WordPress' generic 403. */
	public static function permission_denied_response( $message = 'Bạn không có quyền xem dữ liệu CRM.' ) {
		$payload = class_exists( 'BizCity_Error_Payload' )
			? BizCity_Error_Payload::make( 'permission_denied', $message, 'Đăng nhập bằng tài khoản CRM được cấp quyền hoặc liên hệ quản trị viên.', 'crm_capability_required' )
			: array( 'success' => false, 'code' => 'permission_denied', 'message' => $message, 'hint' => 'Đăng nhập bằng tài khoản CRM được cấp quyền hoặc liên hệ quản trị viên.', 'help_code' => 'crm_capability_required' );
		return new WP_Error( 'permission_denied', (string) $payload['message'], array( 'status' => 403, 'response' => $payload ) );
	}

	public static function can_read_contact_scope( $request = null ): bool {
		// [2026-08-23 Johnny Chu] PHASE-0.39D — Contacts reads follow the same owner/inbox scope as Inbox reads.
		$user_id = (int) get_current_user_id();
		if ( $user_id <= 0 ) { return false; }
		if ( class_exists( 'BizCity_CRM_Inbox_Access' ) && BizCity_CRM_Inbox_Access::is_admin( $user_id ) ) {
			return true;
		}
		if ( ! class_exists( 'BizCity_CRM_Inbox_Access' ) ) { return false; }
		$contact_id = $request instanceof WP_REST_Request ? (int) $request->get_param( 'id' ) : 0;
		if ( $contact_id > 0 ) { return self::contact_is_in_scope( $contact_id, $user_id ); }
		return ! empty( BizCity_CRM_Inbox_Access::allowed_inbox_ids( $user_id ) );
	}

	public static function can_write_contact_scope( $request = null ): bool {
		if ( current_user_can( 'manage_options' ) ) { return true; }
		// [2026-09-17 Johnny Chu - Chu Hoàng Anh] PHASE-0.49-E2 — contact mutations are Inbox side effects; edit_posts alone is not enough.
		if ( ! self::can_handle_inbox() ) { return false; }
		$contact_id = $request instanceof WP_REST_Request ? (int) $request->get_param( 'id' ) : 0;
		return $contact_id > 0 && self::contact_is_in_scope( $contact_id, (int) get_current_user_id() );
	}

	protected static function contact_is_in_scope( int $contact_id, int $user_id ): bool {
		if ( $contact_id <= 0 || $user_id <= 0 ) { return false; }
		if ( ! class_exists( 'BizCity_CRM_Inbox_Access' ) ) { return false; }
		$allowed = BizCity_CRM_Inbox_Access::allowed_inbox_ids( $user_id );
		if ( null === $allowed || empty( $allowed ) ) { return null === $allowed; }
		global $wpdb;
		$ci_tbl = BizCity_CRM_DB_Installer_V2::tbl_contact_inboxes();
		$placeholders = implode( ',', array_fill( 0, count( $allowed ), '%d' ) );
		$params = array_merge( array( $contact_id ), array_map( 'absint', $allowed ) );
		$sql = $wpdb->prepare( "SELECT ci.contact_id FROM `{$ci_tbl}` ci WHERE ci.contact_id = %d AND ci.inbox_id IN ({$placeholders}) LIMIT 1", $params );
		return (bool) $wpdb->get_var( $sql );
	}

	protected static function contact_scope_sql( string $contact_alias = 'id' ): string {
		$user_id = (int) get_current_user_id();
		if ( class_exists( 'BizCity_CRM_Inbox_Access' ) && BizCity_CRM_Inbox_Access::is_admin( $user_id ) ) {
			return '1=1';
		}
		$allowed = class_exists( 'BizCity_CRM_Inbox_Access' )
			? BizCity_CRM_Inbox_Access::allowed_inbox_ids( $user_id )
			: array();
		if ( empty( $allowed ) ) { return '0=1'; }
		global $wpdb;
		$ci_tbl = BizCity_CRM_DB_Installer_V2::tbl_contact_inboxes();
		$placeholders = implode( ',', array_fill( 0, count( $allowed ), '%d' ) );
		return $wpdb->prepare( "{$contact_alias} IN (SELECT contact_id FROM `{$ci_tbl}` WHERE inbox_id IN ({$placeholders}))", array_map( 'absint', $allowed ) );
	}

	public static function can_write(): bool {
		/** @param string $cap default cap for CRM composer write actions. */
		$cap = (string) apply_filters( 'bizcity_crm_write_cap', 'edit_posts' );
		return current_user_can( $cap );
	}

	public static function can_manage_rules(): bool {
		$cap = class_exists( 'BizCity_CRM_Capabilities' )
			? BizCity_CRM_Capabilities::CAP_MANAGE_RULES
			: 'manage_options';
		// [2026-09-21 06:15 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.60-C02 — network Super Admins must receive tenant-wide CRM roster scope even when the mapped blog does not grant local manage_options.
		return ( function_exists( 'is_super_admin' ) && is_super_admin() )
			|| current_user_can( $cap )
			|| current_user_can( 'manage_options' )
			|| current_user_can( 'manage_network' );
	}

	/**
	 * Read permission for account-scoped customer-care resources.
	 *
	 * @param WP_REST_Request|null $request
	 * @return bool
	 */
	public static function can_read_inbox_scope( $request = null ): bool {
		// [2026-08-21 Johnny Chu] PHASE-0.39B — request-aware customer-care read scope.
		$user_id = (int) get_current_user_id();
		if ( ! $user_id ) {
			return false;
		}
		if ( class_exists( 'BizCity_CRM_Inbox_Access' ) && BizCity_CRM_Inbox_Access::is_admin( $user_id ) ) {
			return true;
		}
		if ( ! class_exists( 'BizCity_CRM_Inbox_Access' ) ) {
			return false;
		}
		if ( $request instanceof WP_REST_Request ) {
			$conversation_id = (int) $request->get_param( 'id' );
			if ( $conversation_id > 0 ) {
				return BizCity_CRM_Inbox_Access::can_view_conversation( $conversation_id, $user_id );
			}
			$inbox_id = (int) $request->get_param( 'inbox_id' );
			if ( $inbox_id > 0 ) {
				return BizCity_CRM_Inbox_Access::can_view_inbox( $inbox_id, $user_id );
			}
		}
		return ! empty( BizCity_CRM_Inbox_Access::allowed_inbox_ids( $user_id ) );
	}

	/**
	 * Write permission for account-scoped customer-care resources.
	 *
	 * @param WP_REST_Request|null $request
	 * @return bool
	 */
	public static function can_write_inbox_scope( $request = null ): bool {
		// [2026-08-21 Johnny Chu] PHASE-0.39B — request-aware customer-care write scope.
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}
		// [2026-09-17 Johnny Chu - Chu Hoàng Anh] PHASE-0.49-E2 — capability gate before resource scope; a read-only viewer must not send/resolve/assign.
		if ( ! self::can_handle_inbox() ) {
			return false;
		}
		return self::can_read_inbox_scope( $request );
	}

	/**
	 * Employee Inbox handle capability (send/resolve/assign/notes).
	 */
	protected static function can_handle_inbox(): bool {
		return class_exists( 'BizCity_CRM_Capabilities' ) && method_exists( 'BizCity_CRM_Capabilities', 'user_can_handle_inbox' )
			? BizCity_CRM_Capabilities::user_can_handle_inbox()
			: current_user_can( 'manage_options' );
	}

	/** M7.W1 — create inbox row from wizard. Re-runs verify for safety. */
	public static function post_inbox_create( WP_REST_Request $req ) {
		$blocked = self::channel_setup_error( (string) $req->get_param( 'channel_type' ) );
		if ( $blocked ) { return $blocked; }
		return self::wrap( static function () use ( $req ) {
			$code   = (string) $req->get_param( 'channel_type' );
			$config = $req->get_param( 'config' );
			if ( ! is_array( $config ) ) { $config = array(); }

			$a = BizCity_CRM_Channel_Registry::get( $code );
			if ( ! $a ) {
				throw new \RuntimeException( 'channel_not_found' );
			}
			$verify = method_exists( $a, 'verify' )
				? $a->verify( $config )
				: array( 'ok' => true, 'channel_ref_id' => '', 'name' => $a->label() );

			if ( empty( $verify['ok'] ) ) {
				throw new \RuntimeException( 'verify_failed: ' . (string) ( $verify['error'] ?? 'unknown' ) );
			}
			$ref = (string) ( $verify['channel_ref_id'] ?? '' );
			if ( $ref === '' ) {
				throw new \RuntimeException( 'verify_returned_no_channel_ref_id' );
			}

			$inbox_id = BizCity_CRM_Repository::upsert_inbox( $code, $ref, array(
				'name'     => (string) ( $verify['name'] ?? $a->label() ),
				'settings' => $config,
			) );
			if ( ! $inbox_id ) {
				throw new \RuntimeException( 'inbox_insert_failed' );
			}
			return array(
				'inbox_id'      => $inbox_id,
				'channel_type'  => $code,
				'channel_ref_id'=> $ref,
				'name'          => (string) ( $verify['name'] ?? $a->label() ),
				'verify_hints'  => isset( $verify['hints'] ) ? $verify['hints'] : array(),
			);
		} );
	}

	public static function get_inboxes( WP_REST_Request $req ) {
		return self::wrap( static function () {
			// [2026-09-22 12:15 AM OpenAI GPT-5.6 Luna] HOTFIX — degrade cleanly when the tenant CRM inbox schema is unavailable.
			if ( ! BizCity_CRM_DB_Installer_V2::table_exists( BizCity_CRM_DB_Installer_V2::tbl_inboxes() ) ) {
				return array();
			}
			$rows = BizCity_CRM_Repository::list_inboxes();
			if ( class_exists( 'BizCity_CRM_Inbox_Access' ) ) {
				$allowed = BizCity_CRM_Inbox_Access::allowed_inbox_ids();
				if ( is_array( $allowed ) ) {
					$rows = array_values( array_filter( $rows, static function ( $row ) use ( $allowed ) {
						return in_array( (int) ( $row['id'] ?? 0 ), $allowed, true );
					} ) );
				}
			}
			return array_map( array( __CLASS__, 'shape_inbox' ), $rows );
		} );
	}

	/**
	 * [2026-09-25 PHASE-0.63C GC-21] The role / pipeline-kind list filters, sanitised once for the list and the CSV export.
	 * Unknown values become '' (= no filter) rather than an error: a stale chip must not blank the whole inbox.
	 *
	 * @return array{role:string,pipeline_kind:string}
	 */
	protected static function role_kind_filters( WP_REST_Request $req ): array {
		$roles = class_exists( 'BizCity_CRM_Contact_Roles' ) ? array_keys( BizCity_CRM_Contact_Roles::CATALOG ) : array();
		$roles[] = 'none';
		$role = sanitize_key( (string) $req->get_param( 'role' ) );
		$kind = sanitize_key( (string) $req->get_param( 'pipeline_kind' ) );
		return array(
			'role'          => in_array( $role, $roles, true ) ? $role : '',
			'pipeline_kind' => preg_match( '/^[a-z][a-z0-9_-]{0,31}$/', $kind ) ? $kind : '',
		);
	}

	/**
	 * [2026-10-28 06:05 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.146 S146-L2 — `thread_kind=user` (doc 21 §7 URL word) is the same filter as `personal`.
	 */
	protected static function thread_kind_param( WP_REST_Request $req ): string {
		$k = (string) $req->get_param( 'thread_kind' );
		if ( 'user' === $k ) {
			return 'personal';
		}
		return in_array( $k, array( 'group', 'personal' ), true ) ? $k : '';
	}

	/**
	 * [2026-10-28 06:05 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.146 S146-L2 — the scoped list filters, shared by GET conversations and
	 * GET conversations/ids so "chọn tất cả khớp lọc" can never select outside what the list shows.
	 *
	 * @return array|WP_Error
	 */
	protected static function conversation_list_args( WP_REST_Request $req ) {
		$args = array(
			'inbox_id'    => (int) $req->get_param( 'inbox_id' ),
			'status'      => (string) $req->get_param( 'status' ),
			'priority'    => $req->get_param( 'priority' ),
			'assignee_id' => (int) $req->get_param( 'assignee_id' ),
			'label_id'    => (int) $req->get_param( 'label_id' ),
			'thread_kind' => self::thread_kind_param( $req ), // [2026-08-25 Johnny Chu] PHASE-0.39F-GROUP-INBOX — preserve list filter semantics.
			'q'           => (string) $req->get_param( 'q' ),
			'limit'       => (int) ( $req->get_param( 'limit' ) ?: 50 ),
			'before_id'   => (int) $req->get_param( 'before_id' ),
		);
		$args = array_merge( $args, self::role_kind_filters( $req ) ); // [2026-09-25 PHASE-0.63C GC-21]
		// [2026-10-09 03:47 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.95-S95-C9 — ?signal=nong|am|lanh|khong_hai_long (crm_signal tags, filtered in SQL).
		if ( class_exists( 'BizCity_CRM_Contact_Signals' ) ) { $args['signal'] = BizCity_CRM_Contact_Signals::filter_key( $req->get_param( 'signal' ) ); }
		$scope_user_id = (int) $req->get_param( 'scope_user_id' );
		if ( $scope_user_id > 0 ) {
			// [2026-09-08 02:09 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.41-CX1 — enforce the selected B2 principal's server-resolved account scope before listing conversations.
			if ( ! self::can_manage_rules() || ! self::is_crm_assignable_user( $scope_user_id ) || ! class_exists( 'BizCity_CRM_Inbox_Access' ) ) {
				return new WP_Error( 'permission_denied', 'Không thể dùng phạm vi nhân viên đã chọn.', array( 'status' => 403, 'hint' => 'Chọn lại nhân viên từ danh sách CRM.', 'help_code' => 'permission_denied' ) );
			}
			$selected_scope = BizCity_CRM_Inbox_Access::resolve_scope( $scope_user_id, 'b2', true );
			if ( is_array( $selected_scope['inbox_ids'] ?? null ) ) {
				$args['inbox_ids'] = $selected_scope['inbox_ids'];
			}
			// [2026-09-18] PHASE-0.48F F-UID-05 — reading an employee's queue is audited (1 row/actor/subject/day).
			if ( class_exists( 'BizCity_CRM_Staff_REST' ) ) {
				BizCity_CRM_Staff_REST::record_workspace_read( get_current_user_id(), $scope_user_id, 'scope_user_list' );
			}
		}
		if ( class_exists( 'BizCity_CRM_Inbox_Access' ) ) {
			$allowed = BizCity_CRM_Inbox_Access::allowed_inbox_ids();
			if ( is_array( $allowed ) ) {
				$args['inbox_ids'] = isset( $args['inbox_ids'] ) && is_array( $args['inbox_ids'] )
					? array_values( array_intersect( $args['inbox_ids'], $allowed ) )
					: $allowed;
			}
		}
		$snoozed_raw = $req->get_param( 'snoozed' );
		if ( $snoozed_raw !== null && $snoozed_raw !== '' ) {
			$args['snoozed'] = $snoozed_raw;
		}
		$unassigned_raw = $req->get_param( 'unassigned' );
		if ( $unassigned_raw !== null && $unassigned_raw !== '' ) {
			$args['unassigned'] = $unassigned_raw;
		}
		return $args;
	}

	public static function get_conversations( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			// [2026-09-22 12:15 AM OpenAI GPT-5.6 Luna] HOTFIX — avoid repeated SQL exceptions during partial CRM schema/bootstrap states.
			if ( ! BizCity_CRM_DB_Installer_V2::table_exists( BizCity_CRM_DB_Installer_V2::tbl_conversations() ) ) {
				return array();
			}
			$args = self::conversation_list_args( $req );
			if ( is_wp_error( $args ) ) {
				return rest_convert_error_to_response( $args );
			}
			// [PHASE-0.54 R-INBOX-PIPE-8] ?stage= filters the list by resolved pipeline stage ("stuck" is a
			// second pseudo-value). Stage isn't a stored column (it's computed on read — R-PIPE-2), so this
			// over-fetches a wider window from the repository, resolves it, filters, then trims back to the
			// page size the caller asked for. Known limit: on a very filtered inbox this can under-fill a
			// page rather than reaching further back; acceptable for now (R-PIPE tôn chỉ linh hoạt).
			$stage_filter = sanitize_key( (string) $req->get_param( 'stage' ) );
			if ( '' !== $stage_filter && 'stuck' !== $stage_filter && ( ! class_exists( 'BizCity_CRM_Customer_Pipeline' ) || ! in_array( $stage_filter, BizCity_CRM_Customer_Pipeline::ALL_STAGES, true ) ) ) {
				$stage_filter = '';
			}
			$requested_limit = (int) $args['limit'];
			if ( '' !== $stage_filter ) {
				$args['limit'] = min( 300, max( $requested_limit * 6, 120 ) );
			}
			// [2026-09-18 Johnny Chu - Chu Hoàng Anh] PHASE-0.51 A3 — an unchanged list fingerprint answers
			// `not_modified` before the list query runs, same idea as modules/twinweb's `/crm/inbox`. `$args`
			// at this point already carries every filter list_conversations() itself will use (inbox_ids
			// resolved, scope_user_id applied, snoozed/unassigned normalized), so the token is exactly as
			// scoped as the query. The token travels in a response header, not the JSON body — `data` stays
			// the same plain array `transformResponse` already expects, so no existing caller needs to change.
			if ( method_exists( 'BizCity_CRM_Repository', 'get_inbox_sync_token' ) ) {
				$sync_token = BizCity_CRM_Repository::get_inbox_sync_token( $args );
				$client_token = sanitize_key( (string) $req->get_param( 'sync_token' ) );
				$not_modified = '' !== $client_token && hash_equals( $sync_token, $client_token );
				$items = $not_modified ? array() : array_map( array( __CLASS__, 'shape_conversation' ), BizCity_CRM_Repository::list_conversations( $args ) );
				$items = self::merge_pipeline_into_conversations( $items, $stage_filter, $requested_limit );
				$body = array(
					'ok'   => true,
					'data' => $items,
					'ts'   => (int) round( microtime( true ) * 1000 ),
				);
				// [2026-10-28 06:05 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.146 S146-L2 — the true total behind the filters (first page only, no
				// computed `stage` filter), so the Inbox can offer "Chọn tất cả N hội thoại khớp lọc" (D146-16).
				if ( ! $not_modified && '' === $stage_filter && (int) $args['before_id'] <= 0 ) {
					$body['total'] = BizCity_CRM_Repository::count_conversations( $args );
				}
				$response = new WP_REST_Response( $body, 200 );
				$response->header( 'X-BizCity-Sync-Token', $sync_token );
				if ( $not_modified ) { $response->header( 'X-BizCity-Not-Modified', '1' ); }
				return $response;
			}
			$rows = BizCity_CRM_Repository::list_conversations( $args );
			$items = array_map( array( __CLASS__, 'shape_conversation' ), $rows );
			$items = self::merge_pipeline_into_conversations( $items, $stage_filter, $requested_limit );
			if ( '' !== $stage_filter || (int) $args['before_id'] > 0 ) {
				return $items;
			}
			return new WP_REST_Response( array( 'ok' => true, 'data' => $items, 'total' => BizCity_CRM_Repository::count_conversations( $args ), 'ts' => (int) round( microtime( true ) * 1000 ) ), 200 );
		} );
	}

	/**
	 * [PHASE-0.54 R-INBOX-PIPE-8] Batch-resolve pipeline stage for a page of shaped conversations (one
	 * `Customer_Pipeline::rows()` call, no N+1), stamp `pipeline_stage/pipeline_days/pipeline_stuck/
	 * pipeline_payment_pending`, then apply `$stage_filter` ('' = no filter, 'stuck' = pseudo-stage) and
	 * trim back to `$limit`.
	 *
	 * @param array  $items  shaped conversations (each with `contact.id`)
	 * @param string $stage_filter '' | a BizCity_CRM_Customer_Pipeline::ALL_STAGES value | 'stuck'
	 */
	protected static function merge_pipeline_into_conversations( array $items, string $stage_filter, int $limit ): array {
		if ( empty( $items ) || ! class_exists( 'BizCity_CRM_Customer_Pipeline' ) ) { return $items; }
		$contact_ids = array_values( array_unique( array_filter( array_map( static function ( $item ) {
			return (int) ( $item['contact']['id'] ?? 0 );
		}, $items ) ) ) );
		if ( empty( $contact_ids ) ) { return $items; }
		$rows = BizCity_CRM_Customer_Pipeline::rows( $contact_ids );
		$out = array();
		foreach ( $items as $item ) {
			$cid = (int) ( $item['contact']['id'] ?? 0 );
			$row = $rows[ $cid ] ?? null;
			$item['pipeline_stage'] = $row ? (string) $row['stage'] : null;
			$item['pipeline_days'] = $row ? (int) $row['days'] : null;
			$item['pipeline_stuck'] = $row ? (bool) $row['stuck'] : false;
			$item['pipeline_payment_pending'] = $row ? (bool) $row['payment_pending'] : false;
			if ( '' !== $stage_filter ) {
				if ( 'stuck' === $stage_filter ) {
					if ( empty( $item['pipeline_stuck'] ) ) { continue; }
				} elseif ( $item['pipeline_stage'] !== $stage_filter ) {
					continue;
				}
			}
			$out[] = $item;
			if ( $limit > 0 && count( $out ) >= $limit ) { break; }
		}
		return $out;
	}

	public static function get_conversation( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			$id = (int) $req['id'];
			if ( ! BizCity_CRM_Repository::get_conversation( $id ) ) {
				throw new \RuntimeException( 'conversation_not_found' );
			}
			$rows = BizCity_CRM_Repository::list_conversations( array( 'id' => $id, 'limit' => 1 ) );
			if ( empty( $rows ) ) {
				throw new \RuntimeException( 'conversation_not_found' );
			}
			return self::shape_conversation( $rows[0] );
		} );
	}

	public static function get_messages( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			$id        = (int) $req['id'];
			// [2026-09-22 02:35 AM OpenAI GPT-5.6 Luna] HOTFIX — message polling
			// must degrade to an empty bounded result while a tenant is missing the
			// CRM message table; do not turn a partial schema into repeated HTTP 500s.
			if ( ! BizCity_CRM_DB_Installer_V2::table_exists( BizCity_CRM_DB_Installer_V2::tbl_messages() ) ) {
				return array();
			}
			$after_id  = (int) $req->get_param( 'after_id' );
			$limit     = (int) ( $req->get_param( 'limit' ) ?: 100 );
			// [2026-09-18 Johnny Chu - Chu Hoàng Anh] PHASE-0.51 A3 — older-message paging (scroll-up) and a
			// bounded delivery-state recheck for a browser cache, reusing the repository methods PHASE-0.48C
			// added for modules/twinweb's `/crm/inbox` (same `bizcity_crm_messages` table, no schema change).
			// `data` stays a plain array (same shape `transformResponse` already expects) — a rechecked row
			// simply replaces its stale copy by id; a row missing from the merge is one the caller can infer
			// as gone by diffing the `recheck_ids` it sent against the ids that came back.
			$before_id = (int) $req->get_param( 'before_id' );
			$rows = ( $before_id > 0 && method_exists( 'BizCity_CRM_Repository', 'list_messages_before' ) )
				? BizCity_CRM_Repository::list_messages_before( $id, $before_id, $limit )
				: BizCity_CRM_Repository::list_messages( $id, $limit, $after_id );
			$by_id = array();
			foreach ( $rows as $row ) { $by_id[ (int) $row['id'] ] = $row; }
			$recheck_raw = (string) $req->get_param( 'recheck_ids' );
			if ( '' !== $recheck_raw && method_exists( 'BizCity_CRM_Repository', 'get_messages_by_ids' ) ) {
				$recheck_ids = array_slice( array_values( array_unique( array_filter( array_map( 'absint', explode( ',', $recheck_raw ) ) ) ) ), 0, 50 );
				if ( $recheck_ids ) {
					foreach ( BizCity_CRM_Repository::get_messages_by_ids( $id, $recheck_ids ) as $row ) {
						$by_id[ (int) $row['id'] ] = $row; // overwrite: the freshly-read row wins over the delta-window copy, if any.
					}
				}
			}
			ksort( $by_id, SORT_NUMERIC );
			return array_map( array( __CLASS__, 'shape_message' ), array_values( $by_id ) );
		} );
	}

	public static function shape_inbox( array $r ): array {
		return array(
			'id'                  => (int) $r['id'],
			'name'                => (string) $r['name'],
			'channel_type'        => (string) $r['channel_type'],
			'channel_ref_id'      => (string) $r['channel_ref_id'],
			'is_active'           => (int) $r['is_active'] === 1,
			'default_notebook_id' => isset( $r['default_notebook_id'] ) ? (int) $r['default_notebook_id'] : null,
			'created_at'          => (string) $r['created_at'],
			'settings'            => $r['settings_json'] ? json_decode( $r['settings_json'], true ) : null,
		);
	}

	public static function shape_conversation( array $r ): array {
		$now           = time();
		$snoozed_until = isset( $r['snoozed_until'] ) && $r['snoozed_until'] !== null ? (int) $r['snoozed_until'] : null;
		$labels_raw    = (string) ( $r['cached_label_list'] ?? '' );
		$labels        = $labels_raw !== '' ? array_values( array_filter( array_map( 'trim', explode( ',', $labels_raw ) ) ) ) : array();
		// [2026-10-09 03:47 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.95-S95-C9 — signal_tags (nhiet:/cam_xuc:/y_dinh: only) + heat_score for the list chips.
		$signal = class_exists( 'BizCity_CRM_Contact_Signals' )
			? BizCity_CRM_Contact_Signals::project( $r['contact_tags_json'] ?? '', $r['contact_attributes'] ?? '' )
			: array( 'signal_tags' => array(), 'heat_score' => null );
		return array(
			'signal_tags'         => $signal['signal_tags'],
			'heat_score'          => $signal['heat_score'],
			'id'                  => (int) $r['id'],
			'inbox_id'            => (int) $r['inbox_id'],
			'contact_inbox_id'    => (int) $r['contact_inbox_id'],
			'status'              => (string) $r['status'],
			'priority'            => (int) ( $r['priority'] ?? 0 ),
			'snoozed_until'       => $snoozed_until,
			'is_snoozed'          => $snoozed_until !== null && $snoozed_until > $now,
			'waiting_since'       => isset( $r['waiting_since'] ) && $r['waiting_since'] !== null ? (int) $r['waiting_since'] : null,
			'first_reply_at'      => isset( $r['first_reply_at'] ) && $r['first_reply_at'] !== null ? (int) $r['first_reply_at'] : null,
			'labels'              => $labels,
			'sla_policy_id'       => isset( $r['sla_policy_id'] ) && $r['sla_policy_id'] !== null ? (int) $r['sla_policy_id'] : null,
			'team_id'             => isset( $r['team_id'] ) && $r['team_id'] !== null ? (int) $r['team_id'] : null,
			'unread_count'        => (int) ( $r['unread_count'] ?? 0 ),
			'notebook_id'         => isset( $r['notebook_id'] ) && $r['notebook_id'] !== null ? (int) $r['notebook_id'] : null,
			'last_activity_at'    => $r['last_activity_at'] ?? null,
			'last_message'        => isset( $r['last_message_content'] ) ? array(
				'content'      => (string) ( $r['last_message_content'] ?? '' ),
				'message_type' => (string) ( $r['last_message_type']    ?? '' ),
				'sender_type'  => (string) ( $r['last_sender_type']     ?? '' ),
				'created_at'   => $r['last_message_at'] ?? null,
			) : null,
			'contact'             => array(
				'id'         => (int) ( $r['contact_id']     ?? 0 ),
				'source_id'  => (string) ( $r['source_id']    ?? '' ),
				'name'       => (string) ( $r['contact_name'] ?? '' ),
				'avatar_url' => $r['contact_avatar'] ?? null,
				'is_group'   => 0 === strpos( (string) ( $r['source_id'] ?? '' ), 'group:' ), // [2026-08-25 Johnny Chu] PHASE-0.39F-GROUP-INBOX — expose canonical group identity to Inbox clients.
				'thread_kind'=> 0 === strpos( (string) ( $r['source_id'] ?? '' ), 'group:' ) ? 'group' : 'personal', // [2026-08-25 Johnny Chu] PHASE-0.39F-GROUP-INBOX — expose canonical thread kind.
				'group_id'   => 0 === strpos( (string) ( $r['source_id'] ?? '' ), 'group:' ) ? substr( (string) $r['source_id'], 6 ) : null, // [2026-08-25 Johnny Chu] PHASE-0.39F-GROUP-INBOX — expose group ID without changing the source key.
			),
		);
	}

	public static function shape_message( array $r ): array {
		$ai = null;
		if ( ! empty( $r['ai_metadata_json'] ) ) {
			$decoded = json_decode( (string) $r['ai_metadata_json'], true );
			if ( is_array( $decoded ) ) {
				// [2026-09-06 12:35 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.39C-C3 — keep native provider quote fields server-side; the browser receives only the safe reply preview.
				unset( $decoded['quote_src'] );
				if ( is_array( $decoded['reply_to'] ?? null ) ) {
					unset( $decoded['reply_to']['quote_src'] );
				}
				$ai = $decoded;
			}
		}
		return array(
			'id'                 => (int) $r['id'],
			'conversation_id'    => (int) $r['conversation_id'],
			'inbox_id'           => (int) $r['inbox_id'],
			'content'            => (string) $r['content'],
			'content_type'       => (string) $r['content_type'],
			'message_type'       => (string) $r['message_type'],
			'sender_type'        => (string) $r['sender_type'],
			'sender_id'          => $r['sender_id'] !== null ? (int) $r['sender_id'] : null,
			'status'             => (string) $r['status'],
			'external_source_id' => $r['external_source_id'] ?? null,
			'event_uuid'         => $r['event_uuid'] ?? null,
			'responder_kind'     => $r['responder_kind']    ?? null,
			'responder_user_id'  => isset( $r['responder_user_id'] ) ? (int) $r['responder_user_id'] : null,
			'character_id'       => isset( $r['character_id'] )      ? (int) $r['character_id']      : null,
			'character_edit_url' => isset( $r['character_id'] ) && (int) $r['character_id'] > 0
				? admin_url( 'admin.php?page=bizcity-knowledge-character-edit&id=' . (int) $r['character_id'] )
				: null,
			'responder_user_edit_url' => isset( $r['responder_user_id'] ) && (int) $r['responder_user_id'] > 0
				? admin_url( 'user-edit.php?user_id=' . (int) $r['responder_user_id'] )
				: null,
			'ai_metadata'        => $ai,
			'delivery'           => ( function () use ( $r ) {
				$payload = ! empty( $r['payload_json'] ) ? json_decode( (string) $r['payload_json'], true ) : array();
				return is_array( $payload ) && isset( $payload['delivery'] ) && is_array( $payload['delivery'] )
					? $payload['delivery']
					: null;
			} )(),
			'attachments'        => array_map( static function ( $a ) {
				return array(
					'id'        => (int) $a['id'],
					'file_type' => (string) $a['file_type'],
					'data_url'  => (string) $a['data_url'],
					'thumb_url' => $a['thumb_url'] ?? null,
				);
			}, $r['attachments'] ?? array() ),
			'created_at'         => (string) $r['created_at'],
		);
	}

	protected static function wrap( callable $fn ) {
		try {
			$data = $fn();
			if ( $data instanceof WP_REST_Response ) {
				return $data;
			}
			return new WP_REST_Response( array(
				'ok'   => true,
				'data' => $data,
				'ts'   => (int) round( microtime( true ) * 1000 ),
			), 200 );
		} catch ( \Throwable $e ) {
			return new WP_REST_Response( array(
				'ok'    => false,
				'error' => array(
					'code'    => 'crm_exception',
					'message' => $e->getMessage(),
				),
				'ts'    => (int) round( microtime( true ) * 1000 ),
			), 500 );
		}
	}

	/** Return a catalogued REST error when a channel has no CRM runtime owner. */
	protected static function channel_setup_error( string $code ) {
		if ( ! class_exists( 'BizCity_CRM_Channel_Contract' ) ) {
			return null;
		}
		$descriptor = BizCity_CRM_Channel_Contract::describe( $code );
		if ( ! empty( $descriptor['crm_enabled'] ) ) {
			return null;
		}
		// [2026-09-01 Johnny Chu] R-CRM-CHANNEL-REST-UX — fail closed before wizard/webhook work and expose the standard help envelope.
		$label = $code === 'telegram' ? 'Telegram' : 'Channel này';
		$payload = class_exists( 'BizCity_Error_Payload' )
			? BizCity_Error_Payload::make(
				'channel_not_configured',
				$label . ' chưa được bật cho CRM Inbox.',
				'Chọn channel có adapter CRM hoặc triển khai adapter trước khi tiếp tục.',
				'channel_setup',
				array( 'channel' => sanitize_key( $code ), 'reason' => 'crm_disabled' )
			)
			: array(
				'success' => false,
				'_degraded' => true,
				'code' => 'channel_not_configured',
				'message' => $label . ' chưa được bật cho CRM Inbox.',
				'hint' => 'Chọn channel có adapter CRM hoặc triển khai adapter trước khi tiếp tục.',
				'help_code' => 'channel_setup',
			);
		return new WP_REST_Response( $payload, 400 );
	}

	/**
	 * Dispatch one CRM outbound payload through the inbox's CRM adapter (or the Gateway sender fallback).
	 *
	 * Caller owns Responder_Stamper push/pop and the CRM message row lifecycle.
	 *
	 * @param array $payload { content, content_type, attachments, mentions, reply_to }
	 * @return array Normalized dispatch result for update_message_delivery().
	 */
	protected static function dispatch_crm_outbound( array $conv, $inbox_row, array $resolved, array $payload ): array {
		$content     = (string) ( $payload['content'] ?? '' );
		$ctype       = (string) ( $payload['content_type'] ?? 'text' );
		$attachments = is_array( $payload['attachments'] ?? null ) ? $payload['attachments'] : array();
		$mentions    = is_array( $payload['mentions'] ?? null ) ? $payload['mentions'] : array();
		$reply_to    = (int) ( $payload['reply_to'] ?? 0 );
		$result = array( 'sent' => false, 'outcome' => 'failed', 'error' => 'no-sender', 'platform' => $resolved['platform'] );

		// Prefer the CRM channel adapter when one is registered for this inbox's channel
		// (`facebook`, `zalo`, …). The CRM adapter knows per-page/per-OA tokens, branches
		// for comment-replies, and never falls through Channel Gateway's UNKNOWN bucket.
		$adapter_code  = $inbox_row ? (string) $inbox_row['channel_type'] : '';
		$crm_adapter   = $adapter_code ? BizCity_CRM_Channel_Registry::get( $adapter_code ) : null;
		if ( $crm_adapter ) {
			// Tap to detect whether the adapter dispatched through a path
			// that already emits `bizcity_channel_outbound_logged` itself
			// (e.g. Zalo Bot via BizCity_Gateway_Sender). When it does, we
			// MUST NOT mirror again \u2014 otherwise the Responder_Stamper hook
			// would write a second row to wp_bizcity_channel_messages.
			$gw_emitted = 0;
			$gw_tap     = static function () use ( &$gw_emitted ) { $gw_emitted++; };
			if ( class_exists( 'BizCity_CRM_Facebook_Ingestor' ) ) {
				BizCity_CRM_Facebook_Ingestor::set_crm_outbound_in_flight( true );
			}
			add_action( 'bizcity_channel_outbound_logged', $gw_tap, 1 );
			try {
				$adapter_res = $crm_adapter->send(
					$conv,
					array(
						'content'      => $content,
						'content_type' => $ctype,
						'attachments'  => $attachments,
						'mentions'     => $mentions,
						'reply_to'     => $reply_to,
					)
				);
				// [2026-08-24 Johnny Chu] PHASE-0.39F-FRAMEWORK — normalize every channel outcome before CRM status and ledger updates.
				if ( ! class_exists( 'BizCity_CRM_Channel_Contract' ) ) {
					throw new \RuntimeException( 'channel_contract_not_loaded' );
				}
				$adapter_res = BizCity_CRM_Channel_Contract::normalize_send_result( $adapter_code, $adapter_res );
			} finally {
				remove_action( 'bizcity_channel_outbound_logged', $gw_tap, 1 );
				if ( class_exists( 'BizCity_CRM_Facebook_Ingestor' ) ) {
					BizCity_CRM_Facebook_Ingestor::set_crm_outbound_in_flight( false );
				}
			}
			$result = array(
				'sent'              => in_array( (string) ( $adapter_res['outcome'] ?? '' ), array( 'sent', 'delivered' ), true ),
				'outcome'           => (string) ( $adapter_res['outcome'] ?? ( ! empty( $adapter_res['success'] ) ? 'accepted' : 'failed' ) ),
				'code'              => (string) ( $adapter_res['code'] ?? '' ),
				'retryable'         => ! empty( $adapter_res['retryable'] ),
				'contract_version'  => (string) ( $adapter_res['contract_version'] ?? '' ),
				'error'             => (string) ( $adapter_res['error'] ?? '' ),
				'platform'          => $resolved['platform'],
				'mid'               => (string) ( $adapter_res['external_source_id'] ?? '' ),
			);
			// Mirror to Channel Gateway ledger only when the adapter did
			// not already emit (e.g. Facebook Bridge sends via Graph API
			// without firing the gateway hook).
			if ( $gw_emitted === 0 ) {
				do_action( 'bizcity_channel_outbound_logged', array(
					'chat_id'  => $resolved['chat_id'],
					'platform' => $resolved['platform'],
					'message'  => $content,
					'type'     => $ctype,
					'extra'    => array( 'mid' => $result['mid'], 'source' => 'crm-adapter' ),
					'sent'     => (bool) $result['sent'],
					'error'    => (string) $result['error'],
				) );
			}
		} elseif ( class_exists( 'BizCity_Gateway_Sender' ) ) {
			$first_attachment = $attachments[0] ?? array();
			$result = BizCity_Gateway_Sender::instance()->send(
				$resolved['chat_id'],
				$content,
				$ctype,
				$first_attachment ? array( 'image_url' => $first_attachment['data_url'], 'file_url' => $first_attachment['data_url'] ) : array()
			);
		}

		return $result;
	}

	/**
	 * POST /conversations/{id}/messages — manual outbound.
	 * Body: { content, content_type?, responder_kind?='manual', character_id? }
	 *
	 * Pipeline:
	 *   1. Resolve chat_id+platform from conversation.
	 *   2. Push Stamper context (kind=manual, user_id=current).
	 *   3. Insert CRM message row immediately (UI feedback).
	 *   4. Dispatch via BizCity_Gateway_Sender::send() → Stamper hook also writes _bizcity_channel_messages.
	 */
	public static function post_message( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			$conv_id = (int) $req['id'];
			$body    = $req->get_json_params() ?: array();
			$content = (string) ( $body['content'] ?? '' );
			$reply_to = absint( $body['reply_to'] ?? 0 );
			// [2026-09-05 Johnny Chu - Chu Hoàng Anh] PHASE-0.39H — validate native group mentions before CRM insert or provider dispatch.
			$mentions = array();
			if ( is_array( $body['mentions'] ?? null ) ) {
				foreach ( array_slice( $body['mentions'], 0, 50 ) as $mention ) {
					if ( ! is_array( $mention ) ) { continue; }
					$pos = isset( $mention['pos'] ) ? (int) $mention['pos'] : -1;
					$len = isset( $mention['len'] ) ? (int) $mention['len'] : 0;
					$uid = sanitize_text_field( (string) ( $mention['uid'] ?? '' ) );
					if ( $pos < 0 || $len < 1 || $uid === '' ) { throw new \RuntimeException( 'mentions_invalid' ); }
					$mentions[] = array( 'pos' => $pos, 'len' => $len, 'uid' => $uid );
				}
			}
			$ctype   = sanitize_key( (string) ( $body['content_type'] ?? 'text' ) );
			$kind    = (string) ( $body['responder_kind'] ?? 'manual' );
			$cid     = isset( $body['character_id'] ) ? (int) $body['character_id'] : 0;
			$attachments = array();
			$raw_attachments = is_array( $body['attachments'] ?? null ) ? $body['attachments'] : array();
			// [2026-08-04 Johnny Chu] PHASE-0.48-COMPOSER-ATTACH — accept one public Media Library URL per outbound message.
			foreach ( array_slice( $raw_attachments, 0, 1 ) as $raw_attachment ) {
				if ( ! is_array( $raw_attachment ) ) { continue; }
				$url = esc_url_raw( (string) ( $raw_attachment['data_url'] ?? $raw_attachment['url'] ?? '' ) );
				if ( $url === '' || ! wp_http_validate_url( $url ) ) {
					throw new \RuntimeException( 'attachment_url_invalid' );
				}
				$file_type = sanitize_key( (string) ( $raw_attachment['file_type'] ?? $raw_attachment['type'] ?? 'file' ) );
				$file_type = $file_type === 'image' ? 'image' : 'file';
				$attachments[] = array(
					'file_type' => $file_type,
					'data_url'  => $url,
					'thumb_url' => esc_url_raw( (string) ( $raw_attachment['thumb_url'] ?? '' ) ),
					'meta'      => array(
						'name' => sanitize_text_field( (string) ( $raw_attachment['name'] ?? '' ) ),
						'mime' => sanitize_mime_type( (string) ( $raw_attachment['mime'] ?? '' ) ),
					),
				);
			}
			if ( $attachments ) {
				$ctype = $attachments[0]['file_type'];
			}

			if ( $content === '' && ! $attachments ) {
				throw new \RuntimeException( 'content_required' );
			}
			if ( ! in_array( $ctype, array( 'text', 'image', 'file' ), true ) ) {
				throw new \RuntimeException( 'content_type_invalid' );
			}
			if ( ! in_array( $kind, array( 'manual', 'hybrid', 'auto', 'system' ), true ) ) {
				$kind = 'manual';
			}

			$conv = BizCity_CRM_Repository::get_conversation( $conv_id );
			if ( ! $conv ) { throw new \RuntimeException( 'conversation_not_found' ); }
			$reply_meta = array();
			if ( $reply_to > 0 ) {
				$reply_row = BizCity_CRM_Repository::get_message( $reply_to );
				if ( ! is_array( $reply_row ) || (int) ( $reply_row['conversation_id'] ?? 0 ) !== $conv_id ) {
					throw new \RuntimeException( 'reply_message_not_found' );
				}
				$reply_meta = array(
					'id'          => $reply_to,
					'sender_name' => (string) ( $reply_row['sender_type'] ?? 'contact' ) === 'agent' ? 'bạn' : (string) ( $conv['contact_name'] ?? 'khách' ),
					'content'     => wp_trim_words( (string) ( $reply_row['content'] ?? '' ), 32, '…' ),
				);
				$reply_source_meta = ! empty( $reply_row['ai_metadata_json'] ) ? json_decode( (string) $reply_row['ai_metadata_json'], true ) : array();
				if ( is_array( $reply_source_meta ) && is_array( $reply_source_meta['quote_src'] ?? null ) && ! empty( $reply_source_meta['quote_src']['msgId'] ) ) {
					$reply_meta['quote_src'] = $reply_source_meta['quote_src'];
				}
			}
			$inbox_row = BizCity_CRM_Repository::get_inbox( (int) $conv['inbox_id'] );
			$conv_view_rows = BizCity_CRM_Repository::list_conversations( array( 'id' => $conv_id, 'limit' => 1 ) );
			$conv_view = ! empty( $conv_view_rows ) ? self::shape_conversation( $conv_view_rows[0] ) : array();
			$is_group_conversation = ! empty( $conv_view['contact']['is_group'] );
			if ( ! empty( $mentions ) && ( ! $is_group_conversation || (string) ( $inbox_row['channel_type'] ?? '' ) !== 'zalo_personal' ) ) {
				throw new \RuntimeException( 'mentions_group_personal_only' );
			}
			if ( ! empty( $mentions ) ) {
				$group_id = (string) ( $conv_view['contact']['group_id'] ?? '' );
				$bridge_account_id = (string) ( $inbox_row['channel_ref_id'] ?? '' );
				$roster_result = ( $bridge_account_id !== '' && $group_id !== '' && class_exists( 'BizCity_Zalo_Bridge_Client' ) )
					? BizCity_Zalo_Bridge_Client::instance()->get_group_members( $bridge_account_id, $group_id )
					: array( '_degraded' => true );
				$roster = is_array( $roster_result['members'] ?? null ) ? $roster_result['members'] : array();
				$roster_ids = array_values( array_filter( array_map( static function ( $member ) {
					return is_array( $member ) ? (string) ( $member['id'] ?? $member['globalId'] ?? '' ) : '';
				}, $roster ) ) );
				if ( ! empty( $roster_result['_degraded'] ) || empty( $roster_ids ) ) {
					throw new \RuntimeException( 'mentions_roster_unavailable' );
				}
				foreach ( $mentions as $mention ) {
					if ( ! in_array( (string) $mention['uid'], $roster_ids, true ) ) {
						throw new \RuntimeException( 'mention_member_not_in_roster' );
					}
				}
			}
			$channel_descriptor = class_exists( 'BizCity_CRM_Channel_Contract' )
				? BizCity_CRM_Channel_Contract::require_crm_enabled( (string) ( $inbox_row['channel_type'] ?? '' ) )
				: new WP_Error( 'channel_contract_not_loaded', 'CRM channel contract chưa sẵn sàng.', array( 'status' => 503 ) );
			if ( is_wp_error( $channel_descriptor ) ) {
				// [2026-09-01 Johnny Chu] R-CRM-CHANNEL-CONTRACT - refuse provider dispatch before any CRM insert or external side effect.
				$disabled_response = self::channel_setup_error( (string) ( $inbox_row['channel_type'] ?? '' ) );
				if ( $disabled_response ) { return $disabled_response; }
				throw new \RuntimeException( $channel_descriptor->get_error_code() );
			}

			// [2026-08-04 Johnny Chu] PHASE-0.48-ATTACHMENT-POLICY — reject unsupported media before message insert/adapter dispatch.
			if ( $attachments ) {
				$channel_type = $inbox_row ? strtolower( (string) $inbox_row['channel_type'] ) : '';
				$attachment = $attachments[0];
				$mime = strtolower( (string) ( $attachment['meta']['mime'] ?? '' ) );
				$size = (int) ( $raw_attachments[0]['size'] ?? 0 );
				$max_bytes = $attachment['file_type'] === 'image' ? 10 * 1024 * 1024 : 25 * 1024 * 1024;
				$allowed_mimes = $attachment['file_type'] === 'image'
					? array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' )
					: array( 'application/pdf', 'text/plain', 'application/zip', 'application/msword', 'application/vnd.ms-excel', 'application/vnd.ms-powerpoint', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/vnd.openxmlformats-officedocument.presentationml.presentation' );
				if ( $mime === '' || ! in_array( $mime, $allowed_mimes, true ) ) {
					throw new \RuntimeException( 'attachment_mime_not_allowed' );
				}
				if ( $size > $max_bytes ) {
					throw new \RuntimeException( $attachment['file_type'] === 'image' ? 'attachment_image_too_large' : 'attachment_file_too_large' );
				}
				if ( in_array( $channel_type, array( 'zalo_oa', 'webchat' ), true ) && $attachment['file_type'] !== 'image' ) {
					throw new \RuntimeException( 'attachment_file_not_supported_by_channel' );
				}
			}

			$resolved = BizCity_CRM_Repository::resolve_chat_id( $conv_id );
			if ( ! $resolved ) { throw new \RuntimeException( 'chat_id_unresolved' ); }

			$user_id = (int) get_current_user_id();
			// [2026-09-17 Johnny Chu - Chu Hoàng Anh] PHASE-0.48-H2.1 — the same client_message_id (double click, network retry) never dispatches twice.
			$client_message_id = preg_replace( '/[^A-Za-z0-9_-]/', '', (string) ( $body['client_message_id'] ?? '' ) );
			$send_idem_key = ( is_string( $client_message_id ) && strlen( $client_message_id ) >= 8 )
				? 'bzc_send_' . md5( get_current_blog_id() . '|' . $user_id . '|' . $conv_id . '|' . $client_message_id )
				: '';
			if ( $send_idem_key !== '' ) {
				$previous_id = (int) get_transient( $send_idem_key );
				$previous_row = $previous_id > 0 ? BizCity_CRM_Repository::get_message( $previous_id ) : null;
				if ( is_array( $previous_row ) && (int) $previous_row['conversation_id'] === $conv_id ) {
					return array( 'message' => self::shape_message( $previous_row ), 'dispatch' => array( 'sent' => 'sent' === (string) $previous_row['status'], 'outcome' => 'duplicate', 'platform' => (string) $resolved['platform'], 'chat_id' => (string) $resolved['chat_id'], 'error' => '' ), 'duplicate' => true );
				}
			}
			if ( class_exists( 'BizCity_Responder_Stamper' ) ) {
				BizCity_Responder_Stamper::push( array(
					'kind'         => $kind,
					'character_id' => $cid ?: null,
					'user_id'      => $user_id,
					'source'       => 'crm-rest',
				) );
			}

			$message_meta = $reply_meta ? array( 'reply_to' => $reply_meta ) : array();
			$msg_id = BizCity_CRM_Repository::insert_message( array(
				'conversation_id'   => $conv_id,
				'inbox_id'          => (int) $conv['inbox_id'],
				'content'           => $content,
				'content_type'      => $ctype,
				'attachments'       => $attachments,
				'message_type'      => 'outgoing',
				'sender_type'       => $kind === 'manual' ? 'agent' : ( $kind === 'auto' ? 'bot' : $kind ),
				'sender_id'         => $user_id ?: null,
				'status'            => 'pending',
				'responder_kind'    => $kind,
				'responder_user_id' => $user_id ?: null,
				'character_id'      => $cid ?: null,
				'ai_metadata'       => $message_meta,
			) );
			if ( $msg_id && $send_idem_key !== '' ) { set_transient( $send_idem_key, (int) $msg_id, 10 * MINUTE_IN_SECONDS ); }

			// [2026-09-17 Johnny Chu - Chu Hoàng Anh] PHASE-0.48-H2.1 — one dispatch path shared by send and failed-message retry.
			$result = self::dispatch_crm_outbound( $conv, $inbox_row, $resolved, array( 'content' => $content, 'content_type' => $ctype, 'attachments' => $attachments, 'mentions' => $mentions, 'reply_to' => $reply_to ) );

			if ( class_exists( 'BizCity_Responder_Stamper' ) ) {
				BizCity_Responder_Stamper::pop();
			}

			// Update CRM message status from dispatch result.
			if ( $msg_id ) {
				BizCity_CRM_Repository::update_message_delivery( $msg_id, array_merge( $result, array( 'platform' => $resolved['platform'] ) ) );
			}

			$row = $msg_id ? BizCity_CRM_Repository::get_message( $msg_id ) : null;
			return array(
				'message'  => $row ? self::shape_message( $row ) : null,
				'dispatch' => array(
					'sent'     => (bool) $result['sent'],
					'outcome'  => (string) ( $result['outcome'] ?? '' ),
					'platform' => (string) $resolved['platform'],
					'chat_id'  => (string) $resolved['chat_id'],
					'error'    => (string) $result['error'],
				),
			);
		} );
	}

	/**
	 * POST /conversations/{id}/notes — internal private note (no outbound dispatch).
	 * Body: { content }
	 */
	public static function post_note( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			$conv_id = (int) $req['id'];
			// [2026-09-10 09:55 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.48C-CARE — use the canonical JSON/form extractor so nested C care forwarding keeps note content intact.
			$body    = self::extract_json_body( $req );
			$content = (string) ( $body['content'] ?? '' );
			if ( $content === '' ) {
				throw new \RuntimeException( 'content_required' );
			}
			$conv = BizCity_CRM_Repository::get_conversation( $conv_id );
			if ( ! $conv ) { throw new \RuntimeException( 'conversation_not_found' ); }

			$user_id = (int) get_current_user_id();
			$msg_id  = BizCity_CRM_Repository::insert_message( array(
				'conversation_id'   => $conv_id,
				'inbox_id'          => (int) $conv['inbox_id'],
				'content'           => $content,
				'content_type'      => 'text',
				'message_type'      => 'private_note',
				'sender_type'       => 'agent',
				'sender_id'         => $user_id ?: null,
				'status'            => 'note',
				'responder_kind'    => 'manual',
				'responder_user_id' => $user_id ?: null,
			) );
			$row = $msg_id ? BizCity_CRM_Repository::get_message( $msg_id ) : null;
			if ( $row ) { $row['attachments'] = array(); }
			return $row ? self::shape_message( $row ) : null;
		} );
	}

	/**
	 * POST /conversations/{id}/resolve — flip status to resolved.
	 */
	public static function post_resolve( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			$conv_id = (int) $req['id'];
			$conv    = BizCity_CRM_Repository::get_conversation( $conv_id );
			if ( ! $conv ) { throw new \RuntimeException( 'conversation_not_found' ); }
			$ok = BizCity_CRM_Repository::set_conversation_status( $conv_id, 'resolved', (int) get_current_user_id() );
			return array( 'resolved' => (bool) $ok );
		} );
	}

	/**
	 * GET /contacts/{id}
	 *
	 * Aggregated payload for the right-side ContactDrawer:
	 *   { contact, inboxes:[...], conversations:[recent×10], gurus:[...] }
	 */
	public static function get_contact( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			$id = (int) $req['id'];
			$contact = BizCity_CRM_Repository::get_contact( $id );
			if ( ! $contact ) { throw new \RuntimeException( 'contact_not_found' ); }
			$context_inbox_id = (int) $req->get_param( 'context_inbox_id' );
			$context_inbox = $context_inbox_id > 0 ? BizCity_CRM_Repository::get_inbox( $context_inbox_id ) : null;
			$is_operations_context = is_array( $context_inbox ) && 'zalo_bot' === strtolower( (string) ( $context_inbox['channel_type'] ?? '' ) );
			if ( $is_operations_context ) {
				// [2026-08-30 Johnny Chu] R-CRM-ZALOBOT-ADMIN-ZONE - redact Customer Care PII and Guru projection in Bot Operations context.
				$contact['email'] = null;
				$contact['phone'] = null;
				$contact['wp_user_id'] = null;
				$contact['additional_attributes'] = null;
			}

			$inboxes = BizCity_CRM_Repository::list_inboxes_for_contact( $id );
			$convs   = BizCity_CRM_Repository::list_conversations_for_contact( $id, 10 );
			$gurus   = $is_operations_context ? array() : BizCity_CRM_Repository::list_gurus_for_contact( $id );

			return array(
				'contact'       => self::shape_contact( $contact ),
				'inboxes'       => array_map( array( __CLASS__, 'shape_inbox' ), $inboxes ),
				'conversations' => array_map( array( __CLASS__, 'shape_conversation' ), $convs ),
				'gurus'         => $gurus,
			);
		} );
	}

	public static function shape_contact( array $r ): array {
		$attrs = array();
		if ( ! empty( $r['additional_attributes'] ) ) {
			$decoded = json_decode( (string) $r['additional_attributes'], true );
			if ( is_array( $decoded ) ) { $attrs = $decoded; }
		}
		return array(
			'id'           => (int) $r['id'],
			'name'         => (string) ( $r['name'] ?? '' ),
			'email'        => $r['email'] ?? null,
			'phone'        => $r['phone'] ?? null,
			'avatar_url'   => $r['avatar_url'] ?? null,
			'wp_user_id'   => isset( $r['wp_user_id'] ) ? (int) $r['wp_user_id'] : null,
			'attributes'   => $attrs,
			'created_at'   => (string) ( $r['created_at'] ?? '' ),
			'updated_at'   => (string) ( $r['updated_at'] ?? '' ),
		);
	}

	/** Four-field error helper for the 0.60B routes (R-ERR: code · message · hint · help_code). */
	protected static function enrichment_error( string $code, string $message, string $hint, string $help_code, int $status = 400 ) {
		return new WP_REST_Response( array( 'ok' => false, 'code' => $code, 'message' => $message, 'hint' => $hint, 'help_code' => $help_code ), $status );
	}

	public static function get_contact_bot_context( WP_REST_Request $req ) {
		// [2026-09-23 04:30 PM Claude Fable 5.1] PHASE-0.60B C5/C6 — projection with source labels; empty slots are stated.
		return self::wrap( static function () use ( $req ) {
			$id      = (int) $req['id'];
			$contact = BizCity_CRM_Repository::get_contact( $id );
			if ( ! $contact ) { throw new \RuntimeException( 'contact_not_found' ); }
			$attrs   = is_array( json_decode( (string) ( $contact['additional_attributes'] ?? '' ), true ) ) ? json_decode( (string) $contact['additional_attributes'], true ) : array();
			$zalo    = isset( $attrs['zalo_profile'] ) && is_array( $attrs['zalo_profile'] ) ? $attrs['zalo_profile'] : array();
			$bmeta   = isset( $attrs['birthday_meta'] ) && is_array( $attrs['birthday_meta'] ) ? $attrs['birthday_meta'] : array();
			$birthday = (string) ( $contact['birthday'] ?? '' );
			$md       = (string) ( $contact['birthday_md'] ?? '' );
			$has_year = $birthday !== '' && $birthday !== '0000-00-00';
			$name     = (string) ( $contact['name'] ?? '' );
			$name_src = $name === '' ? '' : ( isset( $zalo['display_name'] ) && $zalo['display_name'] === $name ? 'zalo' : 'staff' );
			$event_id = class_exists( 'BizCity_CRM_Contact_Enrichment' ) ? BizCity_CRM_Contact_Enrichment::find_active_event( $id ) : 0;
			$opt_out  = (string) ( $attrs['enrichment_opt_out_until'] ?? '' );
			return array(
				'contact_id' => $id,
				'profile'    => array(
					'name'        => array( 'value' => $name, 'source' => $name_src ),
					'gender'      => array( 'value' => (string) ( $zalo['gender'] ?? '' ), 'source' => ! empty( $zalo['gender'] ) ? 'zalo' : '' ),
					'birthday'    => array( 'value' => $has_year ? $birthday : '', 'md' => $md !== '' ? $md : ( $has_year ? substr( $birthday, 5 ) : '' ), 'has_year' => $has_year, 'source' => (string) ( $bmeta['source'] ?? ( $has_year || $md !== '' ? 'crm' : '' ) ) ),
					'birth_time'  => array( 'value' => (string) ( $attrs['birth_time'] ?? '' ), 'source' => ! empty( $attrs['birth_time'] ) ? (string) ( $bmeta['source'] ?? 'crm' ) : '' ),
					'avatar_url'  => array( 'value' => (string) ( $contact['avatar_url'] ?? '' ), 'source' => ! empty( $zalo['avatar_url'] ) && $zalo['avatar_url'] === (string) ( $contact['avatar_url'] ?? '' ) ? 'zalo' : ( ! empty( $contact['avatar_url'] ) ? 'crm' : '' ) ),
				),
				'enrichment' => array(
					'last_at'          => (string) ( $zalo['_at'] ?? '' ),
					'keys_seen'        => isset( $zalo['_keys'] ) && is_array( $zalo['_keys'] ) ? array_values( $zalo['_keys'] ) : array(),
					'throttled'        => class_exists( 'BizCity_CRM_Contact_Enrichment' ) ? BizCity_CRM_Contact_Enrichment::is_throttled( $id ) : false,
					'opt_out_until'    => $opt_out,
					'birthday_event_id'=> $event_id,
				),
				'context_block' => class_exists( 'BizCity_CRM_Contact_Enrichment' ) ? BizCity_CRM_Contact_Enrichment::render_context_block( $contact ) : '',
				'conversations' => count( BizCity_CRM_Repository::list_conversations_for_contact( $id, 50 ) ),
			);
		} );
	}

	/** POST /contacts/{id}/metadata — body {metadata: <json object|string>}. `null` values delete keys. */
	public static function post_contact_metadata( WP_REST_Request $req ) {
		$id   = (int) $req['id'];
		$body = self::extract_json_body( $req );
		if ( ! class_exists( 'BizCity_CRM_Contact_Custom_Meta' ) ) {
			return self::enrichment_error( 'module_not_loaded', 'Metadata liên hệ chưa sẵn sàng.', 'Bật lại module CRM rồi thử lại.', 'contact_meta_module_not_loaded', 503 );
		}
		$parsed = BizCity_CRM_Contact_Custom_Meta::parse_input( $body['metadata'] ?? '' );
		if ( empty( $parsed['ok'] ) ) {
			return self::enrichment_error( $parsed['code'], $parsed['message'], $parsed['hint'], 'contact_meta_' . $parsed['code'], 422 );
		}
		$result = BizCity_CRM_Repository::set_custom_meta( $id, $parsed['patch'] );
		if ( empty( $result['ok'] ) ) {
			$status = 'contact_not_found' === $result['code'] ? 404 : ( 'write_failed' === $result['code'] ? 500 : 422 );
			return self::enrichment_error( $result['code'], $result['message'], $result['hint'], 'contact_meta_' . $result['code'], $status );
		}
		if ( class_exists( 'BizCity_CRM_Audit_Log' ) ) {
			BizCity_CRM_Audit_Log::log( 'crm_contact', $id, 'updated', array(), array( 'custom_meta_keys' => array_keys( $result['meta'] ) ) );
		}
		return new WP_REST_Response( array( 'ok' => true, 'data' => array( 'contact_id' => $id, 'custom_meta' => $result['meta'] ) ), 200 );
	}

	/**
	 * Shared JSON body extractor (handles JSON + form-encoded fallbacks).
	 */
	protected static function extract_json_body( WP_REST_Request $req ): array {
		$json = $req->get_json_params();
		if ( ! is_array( $json ) ) { $json = array(); }
		$body = wp_parse_args( $json, $req->get_body_params() ?: array() );
		return is_array( $body ) ? $body : array();
	}

	protected static function resolve_canonical_contact_id( int $id ): int {
		if ( $id <= 0 ) { return 0; }
		global $wpdb;
		$tbl = BizCity_CRM_DB_Installer_V2::tbl_contacts();
		$exists = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM `{$tbl}` WHERE id=%d AND (deleted_at IS NULL)", $id ) );
		if ( $exists ) { return $exists; }
		// Try mapping table (legacy id → canonical).
		$map = BizCity_CRM_DB_Installer_V2::tbl_contact_id_map();
		$mapped = (int) $wpdb->get_var( $wpdb->prepare( "SELECT new_contact_id FROM `{$map}` WHERE old_biz_id=%d", $id ) );
		return $mapped;
	}

	public static function get_crm_contacts( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			global $wpdb;
			$tbl   = BizCity_CRM_DB_Installer_V2::tbl_contacts();
			$view  = sanitize_key( (string) ( $req->get_param( 'view' ) ?: 'active' ) );
			// [2026-07-03 Johnny Chu] PHASE-0.46 FIX — keep contacts list on single-table scan (no wp_users JOIN) for multishard safety.
			// [2026-08-23 Johnny Chu] PHASE-0.39D — filter list by permitted contact_inboxes.
			$where = array( $view === 'archived' ? '(deleted_at IS NOT NULL)' : '(deleted_at IS NULL)', self::contact_scope_sql( 'id' ) );
			$include_empty = (int) ( $req->get_param( 'include_empty' ) ?: 0 );
			// [2026-07-03 Johnny Chu] PHASE-0.46 FIX — hide ghost contacts (all identity fields empty) by default.
			if ( ! $include_empty ) {
				$where[] = "(TRIM(COALESCE(name,'')) <> '' OR TRIM(COALESCE(first_name,'')) <> '' OR TRIM(COALESCE(last_name,'')) <> '' OR TRIM(COALESCE(email,'')) <> '' OR TRIM(COALESCE(phone,'')) <> '')";
			}
			$aid   = $req->get_param( 'account_id' );
			if ( $aid !== null ) { $where[] = $wpdb->prepare( 'account_id = %d', (int) $aid ); }
			$source = sanitize_text_field( (string) ( $req->get_param( 'source' ) ?: '' ) );
			$cf7_form_id = (int) ( $req->get_param( 'cf7_form_id' ) ?: 0 );
			// [2026-07-03 Johnny Chu] PHASE-0.46 FIX — support FE source/form filters without any cross-table JOIN.
			if ( $cf7_form_id > 0 ) {
				$where[] = $wpdb->prepare( 'acquisition_source = %s', 'cf7:' . $cf7_form_id );
			} elseif ( $source !== '' ) {
				if ( $source === 'cf7' ) {
					$where[] = "(acquisition_source = 'cf7' OR acquisition_source LIKE 'cf7:%')";
				} elseif ( $source === 'inbox' ) {
					$where[] = "acquisition_source LIKE 'inbox:%'";
				} else {
					$where[] = $wpdb->prepare( 'acquisition_source = %s', $source );
				}
			}
			$q = (string) ( $req->get_param( 'q' ) ?: '' );
			if ( $q !== '' ) {
				$like = '%' . $wpdb->esc_like( $q ) . '%';
				$where[] = $wpdb->prepare( '(name LIKE %s OR first_name LIKE %s OR last_name LIKE %s OR email LIKE %s OR phone LIKE %s)', $like, $like, $like, $like, $like );
			}
			// PHASE-0.50 W1 — `owner` is a selector checked by Staff_Policy on every request (never an ACL by itself).
			$owner_id = (int) ( $req->get_param( 'owner' ) ?: 0 );
			$stage    = sanitize_key( (string) ( $req->get_param( 'stage' ) ?: '' ) );
			if ( $owner_id > 0 ) {
				if ( ! class_exists( 'BizCity_CRM_Staff_Policy' ) || ! class_exists( 'BizCity_CRM_Staff_REST' ) ) {
					return new WP_Error( 'module_not_loaded', 'Chưa bật quản lý nhân viên.', array( 'status' => 503 ) );
				}
				$actor_id = (int) get_current_user_id();
				if ( $owner_id !== $actor_id ) {
					$decision = BizCity_CRM_Staff_Policy::can( $actor_id, 'contact.view_by_owner', $owner_id );
					if ( ! $decision['ok'] ) {
						return new WP_Error( 'member_not_manageable', (string) $decision['why'], array( 'status' => 403, 'hint' => 'Chọn "Cả đội" hoặc một nhân viên trong team bạn quản lý.' ) );
					}
				}
				// PHASE-0.50 W1 — `inbox` = one phone/inbox of the owner (selector only); must sit in the owner's own scope.
				$inbox_id = (int) ( $req->get_param( 'inbox' ) ?: 0 );
				if ( $inbox_id > 0 && ! BizCity_CRM_Staff_REST::subject_has_inbox( $owner_id, $inbox_id ) ) {
					return new WP_Error( 'inbox_not_in_owner_scope', 'SĐT/inbox này không thuộc nhân viên đang chọn.', array( 'status' => 403, 'hint' => 'Chọn lại SĐT trong hàng "SĐT Zalo" của nhân viên.' ) );
				}
				// PHASE-0.52 P52-C-04 — 'consulting' added so the W3 portfolio bucket's drill-down (stage=consulting) works.
				$owner_ids = BizCity_CRM_Staff_REST::owner_contact_ids( $owner_id, in_array( $stage, array( 'new', 'touched', 'buyer', 'repeat', 'dormant', 'consulting' ), true ) ? $stage : '', 30, $inbox_id );
				$where[] = empty( $owner_ids ) ? '0=1' : 'id IN (' . implode( ',', array_map( 'intval', $owner_ids ) ) . ')';
			}
			$limit  = max( 1, min( 500, (int) ( $req->get_param( 'limit' ) ?: 100 ) ) );
			$offset = max( 0, (int) ( $req->get_param( 'offset' ) ?: 0 ) );
			// [2026-07-03 Johnny Chu] PHASE-0.46 FIX — prioritize meaningful/recent contacts; avoid blank-name rows dominating first page.
			$sql    = "SELECT * FROM `{$tbl}` WHERE " . implode( ' AND ', $where ) . " ORDER BY updated_at DESC, created_at DESC, id DESC LIMIT {$limit} OFFSET {$offset}";
			$rows   = $wpdb->get_results( $sql, ARRAY_A );
			$contacts = array_map( array( __CLASS__, 'shape_crm_contact' ), (array) $rows );
			if ( 'team' === sanitize_key( (string) ( $req->get_param( 'with' ) ?: '' ) ) ) {
				$contacts = self::enrich_contacts_team_columns( $contacts );
			}
			return array(
				'contacts' => $contacts,
				'count'    => count( (array) $rows ),
			);
		} );
	}

	public static function get_crm_contact( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			global $wpdb;
			$tbl = BizCity_CRM_DB_Installer_V2::tbl_contacts();
			$id  = self::resolve_canonical_contact_id( (int) $req['id'] );
			if ( ! $id ) { return new WP_Error( 'not_found', 'Contact not found', array( 'status' => 404 ) ); }
			if ( ! self::contact_is_in_scope( $id, (int) get_current_user_id() ) ) { return new WP_Error( 'not_found', 'Contact not found', array( 'status' => 404 ) ); }
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$tbl}` WHERE id=%d AND (deleted_at IS NULL)", $id ), ARRAY_A );
			if ( ! $row ) { return new WP_Error( 'not_found', 'Contact not found', array( 'status' => 404 ) ); }
			return self::shape_crm_contact( $row );
		} );
	}

	public static function post_crm_contact( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			global $wpdb;
			$body = self::extract_json_body( $req );
			$now  = current_time( 'mysql' );
			$tags = isset( $body['tags'] ) && is_array( $body['tags'] ) ? wp_json_encode( $body['tags'] ) : null;
			$first = (string) ( $body['first_name'] ?? '' );
			$last  = (string) ( $body['last_name'] ?? '' );
			$name  = trim( $first . ' ' . $last );
			if ( $name === '' ) { $name = (string) ( $body['name'] ?? '' ); }

			// Dedupe: if email or phone matches an existing canonical contact, return it.
			$tbl   = BizCity_CRM_DB_Installer_V2::tbl_contacts();
			$email = (string) ( $body['email'] ?? '' );
			$phone = (string) ( $body['phone'] ?? '' );
			$existing = 0;
			if ( $email !== '' ) {
				$existing = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM `{$tbl}` WHERE email=%s AND (deleted_at IS NULL) LIMIT 1", $email ) );
			}
			if ( ! $existing && $phone !== '' ) {
				$existing = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM `{$tbl}` WHERE phone=%s AND (deleted_at IS NULL) LIMIT 1", $phone ) );
			}
			if ( $existing ) {
				$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$tbl}` WHERE id=%d", $existing ), ARRAY_A );
				return self::shape_crm_contact( $row );
			}

			$wpdb->insert( $tbl, array(
				'name'                  => $name,
				'first_name'            => $first ?: null,
				'last_name'             => $last  ?: null,
				'email'                 => $email ?: null,
				'phone'                 => $phone ?: null,
				'title'                 => (string) ( $body['title'] ?? '' ) ?: null,
				'account_id'            => ( $body['account_id'] ?? null ) ? (int) $body['account_id'] : null,
				'owner_id'              => ( $body['owner_id'] ?? null )   ? (int) $body['owner_id']   : null,
				'tags_json'             => $tags,
				'additional_attributes' => isset( $body['additional_attributes'] ) ? wp_json_encode( $body['additional_attributes'] ) : null,
				'acquisition_source'    => (string) ( $body['acquisition_source'] ?? 'crm_manual' ),
				'created_at'            => $now,
				'updated_at'            => $now,
			) );
			$id = (int) $wpdb->insert_id;
			if ( ! $id ) { return new WP_Error( 'insert_failed', 'Could not create contact', array( 'status' => 500 ) ); }
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$tbl}` WHERE id=%d", $id ), ARRAY_A );
			if ( class_exists( 'BizCity_CRM_Repository' ) && method_exists( 'BizCity_CRM_Repository', 'invalidate_read_models' ) ) { BizCity_CRM_Repository::invalidate_read_models(); }
			do_action( 'bizcity_crm_contact_saved', $id, $row );
			return self::shape_crm_contact( $row );
		} );
	}

	public static function put_crm_contact( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			global $wpdb;
			$tbl = BizCity_CRM_DB_Installer_V2::tbl_contacts();
			$id  = self::resolve_canonical_contact_id( (int) $req['id'] );
			if ( ! $id ) { return new WP_Error( 'not_found', 'Contact not found', array( 'status' => 404 ) ); }
			if ( ! self::contact_is_in_scope( $id, (int) get_current_user_id() ) ) { return new WP_Error( 'not_found', 'Contact not found', array( 'status' => 404 ) ); }
			$body   = self::extract_json_body( $req );
			$fields = array( 'updated_at' => current_time( 'mysql' ) );
			foreach ( array( 'first_name', 'last_name', 'email', 'phone', 'title' ) as $f ) {
				if ( isset( $body[ $f ] ) ) { $fields[ $f ] = (string) $body[ $f ]; }
			}
			if ( isset( $body['account_id'] ) ) { $fields['account_id'] = $body['account_id'] ? (int) $body['account_id'] : null; }
			if ( isset( $body['owner_id'] ) )   { $fields['owner_id']   = $body['owner_id']   ? (int) $body['owner_id']   : null; }
			if ( isset( $body['tags'] ) && is_array( $body['tags'] ) ) { $fields['tags_json'] = wp_json_encode( $body['tags'] ); }
			// Keep `name` denormalized for legacy readers / search.
			if ( isset( $fields['first_name'] ) || isset( $fields['last_name'] ) ) {
				$current = $wpdb->get_row( $wpdb->prepare( "SELECT first_name, last_name, name FROM `{$tbl}` WHERE id=%d", $id ), ARRAY_A );
				$fn = $fields['first_name'] ?? (string) ( $current['first_name'] ?? '' );
				$ln = $fields['last_name']  ?? (string) ( $current['last_name']  ?? '' );
				$composed = trim( $fn . ' ' . $ln );
				if ( $composed !== '' ) { $fields['name'] = $composed; }
			} elseif ( isset( $body['name'] ) ) {
				$fields['name'] = (string) $body['name'];
			}
			$wpdb->update( $tbl, $fields, array( 'id' => $id ) );
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$tbl}` WHERE id=%d", $id ), ARRAY_A );
			if ( class_exists( 'BizCity_CRM_Repository' ) && method_exists( 'BizCity_CRM_Repository', 'invalidate_read_models' ) ) { BizCity_CRM_Repository::invalidate_read_models(); }
			do_action( 'bizcity_crm_contact_saved', $id, $row );
			return self::shape_crm_contact( $row );
		} );
	}

	public static function delete_crm_contact( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			global $wpdb;
			$tbl = BizCity_CRM_DB_Installer_V2::tbl_contacts();
			$id  = self::resolve_canonical_contact_id( (int) $req['id'] );
			if ( ! $id ) { return array( 'deleted' => false, 'id' => (int) $req['id'] ); }
			if ( ! self::contact_is_in_scope( $id, (int) get_current_user_id() ) ) { return new WP_Error( 'not_found', 'Contact not found', array( 'status' => 404 ) ); }
			$ok  = $wpdb->update( $tbl, array( 'deleted_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ) ), array( 'id' => $id ) );
			if ( $ok && class_exists( 'BizCity_CRM_Repository' ) && method_exists( 'BizCity_CRM_Repository', 'invalidate_read_models' ) ) { BizCity_CRM_Repository::invalidate_read_models(); }
			do_action( 'bizcity_crm_contact_deleted', $id );
			return array( 'deleted' => (bool) $ok, 'id' => $id );
		} );
	}

	/**
	 * PHASE-0.50 W1 columns for one page of contacts (≤500): current owner (assignee of the most
	 * recent conversation), last human touch (time + who), open task count. Three grouped queries.
	 */
	protected static function enrich_contacts_team_columns( array $contacts ): array {
		global $wpdb;
		$ids = array_values( array_filter( array_map( static function ( $c ) { return (int) ( $c['id'] ?? 0 ); }, $contacts ) ) );
		if ( empty( $ids ) ) { return $contacts; }
		$ph     = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$ci_t   = BizCity_CRM_DB_Installer_V2::tbl_contact_inboxes();
		$conv_t = BizCity_CRM_DB_Installer_V2::tbl_conversations();
		$msg_t  = BizCity_CRM_DB_Installer_V2::tbl_messages();
		$task_t = BizCity_CRM_DB_Installer_V2::tbl_crm_tasks();

		$owner = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT ci.contact_id, c.assignee_id, c.inbox_id FROM `{$conv_t}` c INNER JOIN `{$ci_t}` ci ON ci.id = c.contact_inbox_id
			 WHERE ci.contact_id IN ({$ph}) ORDER BY c.last_activity_at DESC", $ids ), ARRAY_A ) as $r ) {
			$cid = (int) $r['contact_id'];
			if ( ! isset( $owner[ $cid ] ) && (int) $r['assignee_id'] > 0 ) { $owner[ $cid ] = (int) $r['assignee_id']; }
		}

		$touch = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT ci.contact_id, m.responder_user_id, m.created_at FROM `{$msg_t}` m
			 INNER JOIN `{$conv_t}` c ON c.id = m.conversation_id INNER JOIN `{$ci_t}` ci ON ci.id = c.contact_inbox_id
			 INNER JOIN ( SELECT ci2.contact_id AS cid, MAX(m2.id) AS max_id FROM `{$msg_t}` m2
			              INNER JOIN `{$conv_t}` c2 ON c2.id = m2.conversation_id INNER JOIN `{$ci_t}` ci2 ON ci2.id = c2.contact_inbox_id
			              WHERE m2.message_type = 'outgoing' AND ci2.contact_id IN ({$ph}) GROUP BY ci2.contact_id ) last ON last.max_id = m.id
			", $ids ), ARRAY_A ) as $r ) {
			$touch[ (int) $r['contact_id'] ] = array( 'at' => (string) $r['created_at'], 'user_id' => (int) $r['responder_user_id'] );
		}

		$tasks = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT related_entity_id AS cid, COUNT(*) AS n FROM `{$task_t}` WHERE deleted_at IS NULL AND completed = 0 AND related_entity_type = 'contact' AND related_entity_id IN ({$ph}) GROUP BY related_entity_id", $ids ), ARRAY_A ) as $r ) {
			$tasks[ (int) $r['cid'] ] = (int) $r['n'];
		}

		$names = array();
		$name_of = static function ( int $uid ) use ( &$names ) {
			if ( $uid <= 0 ) { return ''; }
			if ( ! isset( $names[ $uid ] ) ) { $u = get_userdata( $uid ); $names[ $uid ] = $u ? (string) $u->display_name : ''; }
			return $names[ $uid ];
		};
		foreach ( $contacts as &$c ) {
			$cid = (int) $c['id'];
			$oid = $owner[ $cid ] ?? 0;
			$c['team'] = array(
				'owner'      => $oid ? array( 'user_id' => $oid, 'display_name' => $name_of( $oid ) ) : null,
				'last_touch' => isset( $touch[ $cid ] ) ? array( 'at' => $touch[ $cid ]['at'], 'user_id' => $touch[ $cid ]['user_id'], 'display_name' => $touch[ $cid ]['user_id'] > 0 ? $name_of( $touch[ $cid ]['user_id'] ) : '', 'kind' => $touch[ $cid ]['user_id'] > 0 ? 'human' : 'automated' ) : null,
				'open_tasks' => $tasks[ $cid ] ?? 0,
			);
		}
		unset( $c );
		return $contacts;
	}

	protected static function shape_crm_contact( ?array $r ): ?array {
		if ( ! $r ) { return null; }
		$tags = json_decode( (string) ( $r['tags_json'] ?? '' ), true );
		$attrs = json_decode( (string) ( $r['additional_attributes'] ?? '' ), true );
		$first = (string) ( $r['first_name'] ?? '' );
		$last  = (string) ( $r['last_name']  ?? '' );
		$name  = trim( $first . ' ' . $last );
		if ( $name === '' ) { $name = (string) ( $r['name'] ?? '' ); }
		// [2026-07-03 Johnny Chu] PHASE-0.46 FIX — fallback for legacy/ingestor data stored in additional_attributes.
		if ( $name === '' && is_array( $attrs ) ) {
			$name = trim( (string) ( $attrs['display_name'] ?? $attrs['name'] ?? $attrs['full_name'] ?? $attrs['from_user_name'] ?? '' ) );
		}
		$email = (string) ( $r['email'] ?? '' );
		$phone = (string) ( $r['phone'] ?? '' );
		// [2026-07-03 Johnny Chu] PHASE-0.46 FIX — avoid empty columns on Contacts UI when old records keep phone/email in attrs.
		if ( $email === '' && is_array( $attrs ) ) {
			$email = trim( (string) ( $attrs['email'] ?? '' ) );
		}
		if ( $phone === '' && is_array( $attrs ) ) {
			$phone = trim( (string) ( $attrs['phone'] ?? $attrs['phone_number'] ?? $attrs['mobile'] ?? '' ) );
		}
		return array(
			'id'         => (int) $r['id'],
			'first_name' => $first,
			'last_name'  => $last,
			'name'       => $name,
			'email'      => $email !== '' ? $email : null,
			'phone'      => $phone !== '' ? $phone : null,
			'title'      => $r['title'] ?? null,
			'account_id' => isset( $r['account_id'] ) && $r['account_id'] ? (int) $r['account_id'] : null,
			'owner_id'   => isset( $r['owner_id']   ) && $r['owner_id']   ? (int) $r['owner_id']   : null,
			'wp_user_id'         => isset( $r['wp_user_id'] ) && $r['wp_user_id'] ? (int) $r['wp_user_id'] : null,
			'acquisition_source' => (string) ( $r['acquisition_source'] ?? '' ),
			'acquisition_meta'   => json_decode( (string) ( $r['acquisition_meta_json'] ?? '' ), true ) ?: array(),
			'tags'       => is_array( $tags ) ? $tags : array(),
			'additional_attributes' => is_array( $attrs ) ? $attrs : array(),
			'created_at' => $r['created_at'] ?? null,
			'updated_at' => $r['updated_at'] ?? null,
		);
	}

	protected static function is_crm_assignable_user( int $user_id ): bool {
		// [2026-09-08 02:09 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.41-CX1 — mirror assignable-users eligibility before resolving another principal.
		if ( $user_id <= 0 || ! function_exists( 'get_userdata' ) ) { return false; }
		$user = get_userdata( $user_id );
		if ( ! $user || in_array( 'subscriber', (array) $user->roles, true ) ) { return false; }
		// [2026-09-25 10:31 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.60H — network/site admin capability never substitutes for membership in this tenant blog.
		if ( class_exists( 'BizCity_CRM_Staff_Policy' ) ) {
			return BizCity_CRM_Staff_Policy::is_assignable_user( $user_id );
		}
		if ( function_exists( 'is_multisite' ) && is_multisite() ) {
			return function_exists( 'is_user_member_of_blog' ) && is_user_member_of_blog( $user_id, get_current_blog_id() );
		}
		return ! function_exists( 'is_user_member_of_blog' ) || is_user_member_of_blog( $user_id, get_current_blog_id() );
	}

}
