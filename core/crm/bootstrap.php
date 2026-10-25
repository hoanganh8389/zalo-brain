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
// [2026-10-17 10:59 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.116 S116-X4 (C1, doc 97) — class-conversation-identity-resolver, class-contact-transfer, class-personal-quota-rest, class-google-tool-bridge,
// class-inbox-to-crm-bridge, class-crm-ingest-notif, admin-chat/functions.php, admin-chat/class-admin-chat-grants-admin, class-guru-resolver,
// class-guru-roles-admin and class-crm-order-identity-metabox moved to plugins/bizcity-twin-crm (nothing in core calls them; the add-on loads them).
require_once $bizcity_crm_inc . 'class-crm-contact-identity.php';
require_once $bizcity_crm_inc . 'class-contact-roles.php';
require_once $bizcity_crm_inc . 'class-contact-custom-meta.php';
require_once $bizcity_crm_inc . 'class-contact-signals.php'; // [2026-10-09 03:47 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.95-S95-C9 — crm_signal read projection
require_once $bizcity_crm_inc . 'class-contact-enrichment.php';
// [2026-10-24 Johnny Chu - Chu Hoàng Anh] PHASE-0.126 R126-C2 — BizCity_CRM_Lead_Score moved to bizcity-twin-crm (its only reader, class_exists-guarded).
require_once $bizcity_crm_inc . 'class-system-owner.php';
// [2026-10-17 11:55 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.120 S120-L1 — danh bạ Zalo (cell) → CRM contacts + trang trace gửi thay (guarded: new file).
if ( is_readable( $bizcity_crm_inc . 'class-crm-zalo-directory.php' ) ) {
	require_once $bizcity_crm_inc . 'class-crm-zalo-directory.php';
	BizCity_CRM_Zalo_Directory::init();
}
// [2026-10-19 09:40 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.124 W1 — thư ký nhóm Zalo: cài đặt nhóm một chỗ + bậc quyền + group-transcript@1 (guarded: new file).
if ( is_readable( $bizcity_crm_inc . 'class-crm-group-secretary.php' ) ) {
	require_once $bizcity_crm_inc . 'class-crm-group-secretary.php';
	BizCity_CRM_Group_Secretary::init(); // W2: tự lưu tệp nhóm đang theo dõi (hook bizcity_crm_message_persisted)
	if ( is_readable( $bizcity_crm_inc . 'class-crm-group-index-pack.php' ) ) { // [2026-10-20 06:01 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.124 G0 — gói group_index cho cell
		require_once $bizcity_crm_inc . 'class-crm-group-index-pack.php';
		BizCity_CRM_Group_Index_Pack::register();
	}
	// [2026-10-19 11:22 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.124 B13 — tab Sổ nhóm (bizcity-crm/v1/conversations/{id}/group-notebook)
	if ( is_readable( $bizcity_crm_inc . 'rest/class-crm-group-notebook-rest.php' ) ) {
		require_once $bizcity_crm_inc . 'rest/class-crm-group-notebook-rest.php';
		BizCity_CRM_Group_Notebook_REST::init();
	}
}
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
// [2026-10-16 03:23 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.116 CS1.4 (doc 87, owner Q-CS-2) — instagram / whatsapp-cloud / email-imap adapters archived to core/_archived/crm-20261016/ (no site uses them).
// [2026-10-25 Johnny Chu - Chu Hoàng Anh] PHASE-0.126 R126-RTR — web-widget adapter (public JS-snippet visitor chat) archived at core/_archived/admin-routers-r126rt2-20261025/;
// existing web_widget inboxes/conversations still read (adapter-less: generic label, sending refused with channel_adapter_unavailable).
foreach ( array( 'facebook', 'zalo', 'zalo-bot', 'zalo-oa', 'zalo-personal', 'mabel-wheel' ) as $bizcity_crm_adapter ) { // R126-WC — webchat adapter archived
	require_once $bizcity_crm_inc . 'inbox/adapters/class-adapter-' . $bizcity_crm_adapter . '.php';
}
unset( $bizcity_crm_adapter );
require_once $bizcity_crm_inc . 'inbox/class-fb-ingestor.php';
require_once $bizcity_crm_inc . 'class-crm-inbox-bridge.php';
// [2026-10-20 10:37 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.126 G-C — BizCity_CRM_Gmail_SMTP_Repo retired (Gmail goes through the cell + Composio); table bizcity_crm_gmail_smtp_accounts is quarantined, rows stay.
// [2026-10-24 Johnny Chu - Chu Hoàng Anh] PHASE-0.126 R126-C2 — BizCity_CRM_Email_Send_Log and BizCity_CRM_Lead_Score moved to bizcity-twin-crm (their only
// readers); the email log table stays declared here. Deploy-order guard: while a site still has the old copies (an older twin-crm calls the log without
// a class_exists), load them; once they are deleted from the site, twin-crm loads its own.
foreach ( array( 'class-email-send-log.php', 'class-crm-lead-score.php' ) as $bizcity_crm_moved ) {
	if ( is_readable( $bizcity_crm_inc . $bizcity_crm_moved ) ) {
		require_once $bizcity_crm_inc . $bizcity_crm_moved;
	}
}
unset( $bizcity_crm_moved );

// ── Staff ↔ Zalo Bot magic links + admin-chat grants ──────────────────────────────────────────────────────────
require_once $bizcity_crm_inc . 'admin-chat/class-magic-link.php';
require_once $bizcity_crm_inc . 'admin-chat/class-magic-link-handler.php';
// [2026-10-20 10:24 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.126 G-C — Admin_Chat_Grants / Policy / Confirm + the /admin-chat-grants REST moved to bizcity-twin-crm.

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

		// Zone 1 channel → CRM conversations (was core/scheduler).
		if ( class_exists( 'BizCity_CRM_Inbox_Bridge' ) && method_exists( 'BizCity_CRM_Inbox_Bridge', 'init' ) ) {
			BizCity_CRM_Inbox_Bridge::init();
		}
		// [2026-10-17 10:59 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.116 S116-X4 (C1, doc 97): BizCity_CRM_Inbox_To_CRM_Bridge is registered by bizcity-twin-crm now

		// REST.
		add_action( 'rest_api_init', array( 'BizCity_CRM_Spine_REST', 'register_routes' ), 9 );
		add_action( 'rest_api_init', array( 'BizCity_CRM_Spine', 'register_rest' ), 9 );
		// [2026-10-17 10:59 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.116 S116-X4 (C1, doc 97): /crm-settings/personal-phone-quota is registered by bizcity-twin-crm now

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
		// [2026-10-17 10:59 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.116 S116-X4 (C1, doc 97): BizCity_CRM_Guru_Roles_Admin is registered by bizcity-twin-crm now

		// PHASE 0.35 M1.W2 — ensure capabilities exist on roles (idempotent, signature-guarded inside).
		BizCity_CRM_Capabilities::ensure();

		// Magic links + grants.
		if ( class_exists( 'BizCity_CRM_Magic_Link_Handler' ) ) {
			BizCity_CRM_Magic_Link_Handler::register();
		}
		// [2026-10-17 10:59 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.116 S116-X4 (C1, doc 97): the grants admin screen is registered by bizcity-twin-crm now

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
				// [2026-10-25 Johnny Chu - Chu Hoàng Anh] PHASE-0.126 R126-RTR — 'web_widget' => BizCity_CRM_Adapter_Web_Widget removed (adapter archived).
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

	// [2026-10-25 Johnny Chu - Chu Hoàng Anh] PHASE-0.126 R126-WC — on_channel_normalized() (WEBCHAT envelopes → CRM through BizCity_CRM_Adapter_WebChat) removed with the public widget:
	// the Universal Channel Listener emits no WEBCHAT envelope any more. Archived at core/_archived/webchat-public-r126wc-20261025/.

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
