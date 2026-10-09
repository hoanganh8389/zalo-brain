<?php
/**
 * Zalo Brain — core/crm bootstrap (crm-spine@1).
 *
 * [2026-10-09 Johnny Chu - Chu Hoàng Anh] PHASE-0.96 S96-2.2 — the CRM spine moves out of plugins/bizcity-twin-crm into
 * the main plugin: the conversation ledger (contacts · inboxes · conversations · messages · attachments · labels),
 * team/inbox scope, the channel ingestor + outbound dispatcher, magic links for staff, the spine REST and the spine
 * DB installer. Loaded unconditionally right after core/channel-gateway (D96-2); the extension plugin Zalo Brain CRM
 * boots on top at plugins_loaded@6 and registers what it adds through BizCity_CRM_Spine (doc 20 §4, doc 70 §2).
 *
 * Load order inside this file follows the old plugin bootstrap so nothing changes for the ledger.
 *
 * @package BizCity_Twin_AI
 * @subpackage Core\CRM
 */

defined( 'ABSPATH' ) || exit;

if ( defined( 'BIZCITY_CRM_SPINE_LOADED' ) ) {
	return;
}
define( 'BIZCITY_CRM_SPINE_LOADED', true );

if ( ! defined( 'BIZCITY_CRM_REST_NS' ) ) {
	define( 'BIZCITY_CRM_REST_NS', 'bizcity-crm/v1' );
}
// Spine schema version. Same DDL as plugin 1.38.0 for the spine tables; 2.0.0 marks the split (no ALTER on upgrade).
// [2026-10-10 12:33 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.95 D95-6 — 2.1.0: contacts.lead_score_cell (migrate_phase_095).
if ( ! defined( 'BIZCITY_CRM_DB_VERSION' ) ) {
	define( 'BIZCITY_CRM_DB_VERSION', '2.1.0' );
}
if ( ! defined( 'BIZCITY_CRM_SPINE_DIR' ) ) {
	define( 'BIZCITY_CRM_SPINE_DIR', __DIR__ );
}

$bizcity_crm_inc = __DIR__ . '/includes/';

// ── Core dependencies that may not be loaded on this request (channel-gateway is gated by request type) ─────
// R-CH-FILE-LOG: the ingestor logs through the canonical channel file logger; PHASE-0.39B-W8: CRM events must reach
// the encrypted conversation archive; PHASE-CB4.2: the receipt-only Context Bank adapter. All three are core files.
$bizcity_crm_cg = dirname( __DIR__ ) . '/channel-gateway/includes/';
if ( ! class_exists( 'BizCity_Channel_File_Logger', false ) && is_readable( $bizcity_crm_cg . 'class-channel-file-logger.php' ) ) {
	require_once $bizcity_crm_cg . 'class-channel-file-logger.php';
}
if ( ! class_exists( 'BizCity_Channel_Conversation_Archive', false ) && is_readable( $bizcity_crm_cg . 'class-channel-conversation-archive.php' ) ) {
	require_once $bizcity_crm_cg . 'class-channel-conversation-archive.php';
}
if ( class_exists( 'BizCity_Channel_Conversation_Archive' ) && method_exists( 'BizCity_Channel_Conversation_Archive', 'register' ) ) {
	BizCity_Channel_Conversation_Archive::register();
}
$bizcity_crm_cb_adapter = dirname( __DIR__ ) . '/context-bank/includes/class-context-bank-channel-archive-adapter.php';
if ( class_exists( 'BizCity_Safe_Loader', false ) && is_file( $bizcity_crm_cb_adapter ) && is_readable( $bizcity_crm_cb_adapter ) ) {
	BizCity_Safe_Loader::require_file( $bizcity_crm_cb_adapter, 'context_bank.channel_archive_adapter' );
}
if ( class_exists( 'BizCity_Context_Bank_Channel_Archive_Adapter' ) ) {
	BizCity_Context_Bank_Channel_Archive_Adapter::boot();
}
unset( $bizcity_crm_cg, $bizcity_crm_cb_adapter );

// ── Registry + data layer ─────────────────────────────────────────────────────────────────────────────────────
require_once $bizcity_crm_inc . 'class-crm-spine.php';
require_once $bizcity_crm_inc . 'class-db-installer.php';
require_once $bizcity_crm_inc . 'class-capabilities.php';
require_once $bizcity_crm_inc . 'class-event-emitter.php';
require_once $bizcity_crm_inc . 'class-repository.php';

// ── Identity / scope / policy ─────────────────────────────────────────────────────────────────────────────────
require_once $bizcity_crm_inc . 'class-inbox-access.php';
require_once $bizcity_crm_inc . 'class-team-manager.php';
require_once $bizcity_crm_inc . 'class-staff-policy.php';
require_once $bizcity_crm_inc . 'class-conversation-identity-resolver.php';
require_once $bizcity_crm_inc . 'class-crm-contact-identity.php';
require_once $bizcity_crm_inc . 'class-contact-roles.php';
require_once $bizcity_crm_inc . 'class-contact-custom-meta.php';
require_once $bizcity_crm_inc . 'class-contact-signals.php'; // [2026-10-09 03:47 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.95-S95-C9 — crm_signal read projection
require_once $bizcity_crm_inc . 'class-contact-enrichment.php';
// [2026-10-10 12:33 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.95 D95-6 — person score vs cell score, one effective rule (guarded: new file).
if ( is_readable( $bizcity_crm_inc . 'class-crm-lead-score.php' ) ) {
	require_once $bizcity_crm_inc . 'class-crm-lead-score.php';
}
require_once $bizcity_crm_inc . 'class-contact-transfer.php';
require_once $bizcity_crm_inc . 'class-personal-quota-rest.php';
require_once $bizcity_crm_inc . 'class-system-owner.php';
foreach ( array( 'class-crm-actor.php', 'class-crm-authority.php', 'class-crm-zone-registry.php', 'crm-surfaces.php', 'class-crm-agent-mode-delegate.php' ) as $bizcity_crm_contract ) {
	require_once $bizcity_crm_inc . 'contracts/' . $bizcity_crm_contract;
}
unset( $bizcity_crm_contract );

// ── Channel ingest + outbound ─────────────────────────────────────────────────────────────────────────────────
require_once $bizcity_crm_inc . 'inbox/interface-channel-adapter.php';
require_once $bizcity_crm_inc . 'inbox/class-adapter-base.php';
require_once $bizcity_crm_inc . 'inbox/class-channel-contract.php';
require_once $bizcity_crm_inc . 'inbox/class-channel-registry.php';
require_once $bizcity_crm_inc . 'inbox/class-outbound-dispatcher.php';
require_once $bizcity_crm_inc . 'inbox/bridges/class-fb-bot-bridge.php';
require_once $bizcity_crm_inc . 'inbox/bridges/class-zalo-bot-bridge.php';
require_once $bizcity_crm_inc . 'class-google-tool-bridge.php';
foreach ( array( 'facebook', 'zalo', 'zalo-bot', 'zalo-oa', 'zalo-personal', 'instagram', 'whatsapp-cloud', 'email-imap', 'web-widget', 'webchat', 'mabel-wheel' ) as $bizcity_crm_adapter ) {
	require_once $bizcity_crm_inc . 'inbox/adapters/class-adapter-' . $bizcity_crm_adapter . '.php';
}
unset( $bizcity_crm_adapter );
require_once $bizcity_crm_inc . 'inbox/class-fb-ingestor.php';
require_once $bizcity_crm_inc . 'class-crm-inbox-bridge.php';
require_once $bizcity_crm_inc . 'class-inbox-to-crm-bridge.php';
require_once $bizcity_crm_inc . 'class-crm-ingest-notif.php';
require_once $bizcity_crm_inc . 'class-gmail-smtp-repo.php';
require_once $bizcity_crm_inc . 'class-email-send-log.php';

// ── Staff ↔ Zalo Bot magic links + admin-chat grants ──────────────────────────────────────────────────────────
require_once $bizcity_crm_inc . 'admin-chat/class-magic-link.php';
require_once $bizcity_crm_inc . 'admin-chat/class-magic-link-handler.php';
require_once $bizcity_crm_inc . 'admin-chat/functions.php';
require_once $bizcity_crm_inc . 'admin-chat/class-admin-chat-grants.php';
require_once $bizcity_crm_inc . 'admin-chat/class-admin-chat-policy.php';
require_once $bizcity_crm_inc . 'admin-chat/class-admin-chat-confirm.php';
if ( is_admin() ) {
	require_once $bizcity_crm_inc . 'admin-chat/class-admin-chat-grants-admin.php';
}

// ── Guru per inbox ────────────────────────────────────────────────────────────────────────────────────────────
require_once $bizcity_crm_inc . 'class-guru-resolver.php';
require_once $bizcity_crm_inc . 'class-guru-roles-admin.php';

// ── /crm/ "Đội Zalo" when the Zalo Brain CRM plugin is not active (PHASE-0.96 S96-7.4) ───────────────────────
require_once $bizcity_crm_inc . 'class-crm-core-page.php';
BizCity_CRM_Core_Page::register();

// ── REST (spine routes; the plugin's BizCity_CRM_REST_Controller extends this class) ─────────────────────────
require_once $bizcity_crm_inc . 'rest/class-crm-spine-rest.php';

// ── `customers` projection pack, base version: the cell answers about customers without the plugin ──────────
// [2026-10-09 10:45 PM Johnny Chu - Chu Hoàng Anh] CORE-REDUCTION WP-20 W20-L3 — D-W20-1: the plugin only decorates rows
// (stage) through `bizcity_crm_pack_customer_row`; it no longer registers the kind.
require_once $bizcity_crm_inc . 'class-crm-customers-pack.php';
BizCity_CRM_Customers_Pack::register();

unset( $bizcity_crm_inc );

/**
 * Hooks — moved verbatim from BizCity_CRM_Plugin::boot() where they concern the spine.
 */
final class BizCity_CRM_Spine_Boot {

	public static function init(): void {
		// Install / upgrade on admin pages and REST (webhook context has no admin_init).
		add_action( 'admin_init', array( 'BizCity_CRM_DB_Installer_V2', 'maybe_upgrade' ) );
		// [2026-10-10 12:28 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.95 D95-13 — canonical identities, once, after the schema upgrade (no backup, owner 2026-10-10).
		if ( is_readable( __DIR__ . '/includes/class-crm-identity-canon-migration.php' ) ) {
			require_once __DIR__ . '/includes/class-crm-identity-canon-migration.php';
			add_action( 'admin_init', array( 'BizCity_CRM_Identity_Canon_Migration', 'maybe_run' ), 20 );
		}
		add_action( 'rest_api_init', array( 'BizCity_CRM_DB_Installer_V2', 'maybe_upgrade' ), 1 );

		// Channel adapter registry — eager (FB webhook exits at init@0).
		self::register_built_in_adapters();

		// Ingestor + outbound mirrors.
		BizCity_CRM_Facebook_Ingestor::instance();
		add_action( 'bizcity_facebook_message_sent', array( 'BizCity_CRM_Facebook_Ingestor', 'on_outbound_sent' ), 10, 1 );
		add_action( 'bizcity_facebook_message_sent', array( __CLASS__, 'mirror_fb_outbound' ), 11, 1 );
		add_action( 'bizcity_channel_outbound_logged', array( 'BizCity_CRM_Facebook_Ingestor', 'on_gateway_outbound' ), 10, 1 );
		add_action( 'bizcity_channel_normalized', array( __CLASS__, 'on_channel_normalized' ), 10, 2 );

		// Zone 1 channel → CRM conversations (was core/scheduler).
		if ( class_exists( 'BizCity_CRM_Inbox_Bridge' ) && method_exists( 'BizCity_CRM_Inbox_Bridge', 'init' ) ) {
			BizCity_CRM_Inbox_Bridge::init();
		}
		if ( class_exists( 'BizCity_CRM_Inbox_To_CRM_Bridge' ) ) {
			BizCity_CRM_Inbox_To_CRM_Bridge::register();
		}

		// REST.
		add_action( 'rest_api_init', array( 'BizCity_CRM_Spine_REST', 'register_routes' ), 9 );
		add_action( 'rest_api_init', array( 'BizCity_CRM_Spine', 'register_rest' ), 9 );
		add_action( 'rest_api_init', array( 'BizCity_CRM_Personal_Quota_REST', 'register_routes' ) );

		// Grants version bump on mutation.
		$bump = array( 'BizCity_CRM_Spine_REST', 'bump_grants_version' );
		add_action( 'bizcity_crm_admin_chat_grant_issued', $bump );
		add_action( 'bizcity_crm_admin_chat_grant_approved', $bump );
		add_action( 'bizcity_crm_admin_chat_grant_revoked', $bump );

		// Identity, contracts, enrichment, owner.
		if ( class_exists( 'BizCity_CRM_Agent_Mode_Delegate' ) ) {
			BizCity_CRM_Agent_Mode_Delegate::register();
		}
		if ( class_exists( 'BizCity_CRM_Contact_Enrichment' ) ) {
			BizCity_CRM_Contact_Enrichment::init();
		}
		if ( class_exists( 'BizCity_CRM_System_Owner', false ) ) {
			BizCity_CRM_System_Owner::init();
		}
		if ( class_exists( 'BizCity_CRM_Guru_Roles_Admin' ) && method_exists( 'BizCity_CRM_Guru_Roles_Admin', 'register' ) ) {
			BizCity_CRM_Guru_Roles_Admin::register();
		}

		// PHASE 0.35 M1.W2 — ensure capabilities exist on roles (idempotent, signature-guarded inside).
		BizCity_CRM_Capabilities::ensure();

		// Magic links + grants.
		if ( class_exists( 'BizCity_CRM_Magic_Link_Handler' ) ) {
			BizCity_CRM_Magic_Link_Handler::register();
		}
		BizCity_CRM_Admin_Chat_Grants::register();
		if ( is_admin() && class_exists( 'BizCity_CRM_Admin_Chat_Grants_Admin' ) ) {
			BizCity_CRM_Admin_Chat_Grants_Admin::register();
		}

		// Module access: the CRM authority answers the 'crm' module for TwinShell.
		add_filter( 'bizcity_module_access_delegate', array( __CLASS__, 'module_access_delegate' ), 10, 3 );
		add_filter( 'bizcity_zalo_connection_can_view', array( __CLASS__, 'zalo_connection_can_view' ) );

		/**
		 * The spine is loaded; extension plugins (Zalo Brain CRM) boot after this on plugins_loaded@6.
		 */
		do_action( 'bizcity_crm_spine_loaded' );
	}

	/** Built-in channel adapters (eager; priority 5 so third parties override at 10). */
	private static function register_built_in_adapters(): void {
		add_filter( 'bizcity_crm_register_adapters', static function ( array $adapters ): array {
			$map = array(
				'facebook'       => 'BizCity_CRM_Adapter_Facebook',
				'zalo'           => 'BizCity_CRM_Adapter_Zalo',
				'zalo_bot'       => 'BizCity_CRM_Adapter_ZaloBot',
				'zalo_oa'        => 'BizCity_CRM_Adapter_ZaloOA',
				'zalo_personal'  => 'BizCity_CRM_Adapter_ZaloPersonal',
				'instagram'      => 'BizCity_CRM_Adapter_Instagram',
				'whatsapp_cloud' => 'BizCity_CRM_Adapter_WhatsApp_Cloud',
				'email_imap'     => 'BizCity_CRM_Adapter_Email_IMAP',
				'web_widget'     => 'BizCity_CRM_Adapter_Web_Widget',
				'webchat'        => 'BizCity_CRM_Adapter_WebChat',
				'mabel_wheel'    => 'BizCity_CRM_Adapter_Mabel_Wheel',
			);
			foreach ( $map as $code => $class ) {
				if ( ! isset( $adapters[ $code ] ) && class_exists( $class ) ) {
					$adapters[ $code ] = new $class();
				}
			}
			return $adapters;
		}, 5 );
	}

	/** Mirror legacy FB AI replies into the Channel Gateway ledger (PHASE 0.34.1). */
	public static function mirror_fb_outbound( $payload ): void {
		if ( ! is_array( $payload ) || empty( $payload['sent_ok'] ) || ! class_exists( 'BizCity_Channel_Messages' ) ) {
			return;
		}
		$page_id = (string) ( $payload['page_id'] ?? '' );
		$psid    = (string) ( $payload['user_id'] ?? '' );
		if ( '' === $page_id || '' === $psid ) {
			return;
		}
		$ctx  = ( class_exists( 'BizCity_Responder_Stamper' ) ? BizCity_Responder_Stamper::current() : null ) ?: array();
		$kind = $ctx['kind'] ?? 'auto';
		$cid  = $ctx['character_id'] ?? ( isset( $payload['character_id'] ) ? (int) $payload['character_id'] : null );
		$uid  = $ctx['user_id'] ?? null;
		BizCity_Channel_Messages::log_outbound( array(
			'platform'          => 'FB_MESS',
			'chat_id'           => 'fb_' . $page_id . '_' . $psid,
			'user_psid'         => $psid,
			'message_id'        => (string) ( $payload['message_id'] ?? '' ),
			'event_type'        => 'message',
			'body'              => (string) ( $payload['message'] ?? '' ),
			'payload'           => $payload,
			'character_id'      => $cid,
			'responder_kind'    => $kind,
			'responder_user_id' => $uid,
			'status'            => 'sent',
			'error'             => (string) ( $payload['error'] ?? '' ),
		) );
	}

	/** PHASE 0.37 unified inbound bridge — WEBCHAT envelopes from the Universal Channel Listener. */
	public static function on_channel_normalized( $envelope, $trigger_key = '' ): void {
		if ( ! is_array( $envelope ) ) {
			return;
		}
		$platform = isset( $envelope['platform'] ) ? (string) $envelope['platform'] : '';
		if ( 'WEBCHAT' !== $platform ) {
			return;
		}
		$adapter = BizCity_CRM_Channel_Registry::get( 'webchat' );
		if ( ! $adapter ) {
			return;
		}
		try {
			$norm = $adapter->normalize_inbound( $envelope );
			if ( $norm ) {
				BizCity_CRM_Facebook_Ingestor::instance()->ingest( $adapter, $norm );
			}
		} catch ( \Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[bizcity-crm] channel_normalized ingest failed (' . $platform . '): ' . $e->getMessage() );
			}
		}
	}

	/** TwinShell module-access delegate for the 'crm' module (PHASE-0.84 module-access@1). */
	public static function module_access_delegate( $allowed, $module_id, $user_id ) {
		if ( 'crm' !== $module_id || ! class_exists( 'BizCity_CRM_Authority' ) || ! class_exists( 'BizCity_CRM_Actor' ) ) {
			return $allowed;
		}
		$actor = BizCity_CRM_Actor::for_user( (int) $user_id, 'be' );
		$check = BizCity_CRM_Authority::can( 'crm.inbox.open', array(), $actor );
		return is_array( $check ) ? ! empty( $check['ok'] ) : (bool) $check;
	}

	/** Zalo connection panel visibility follows the inbox-open gate. */
	public static function zalo_connection_can_view( $can ) {
		if ( $can || ! class_exists( 'BizCity_CRM_Authority' ) || ! class_exists( 'BizCity_CRM_Actor' ) ) {
			return $can;
		}
		$check = BizCity_CRM_Authority::can( 'crm.inbox.open', array(), BizCity_CRM_Actor::current( 'be' ) );
		return is_array( $check ) ? ! empty( $check['ok'] ) : (bool) $check;
	}
}

BizCity_CRM_Spine_Boot::init();
