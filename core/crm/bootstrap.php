<?php
/**
 * Zalo Brain — core/crm bootstrap (crm-spine@1).
 *
 * [2026-10-09 Johnny Chu - Chu Hoàng Anh] PHASE-0.96 S96-2.2 — the CRM spine moves out of plugins/bizcity-twin-crm into
 * the main plugin: the conversation ledger (contacts · inboxes · conversations · messages · attachments · labels),
 * team/inbox scope, the channel ingestor + outbound dispatcher, magic links for staff, the spine REST and the spine
 * DB installer. Loaded unconditionally right after core/channel-gateway (D96-2); the extension plugin Open CRM
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
// [2026-10-28 Johnny Chu - Chu Hoàng Anh] PHASE-0.144 S144-CB0 — Context Bank channel-archive capture adapter is no longer booted from CRM (module OFF; capture entries are gated in core/context-bank/bootstrap.php).
unset( $bizcity_crm_cg );

// ── Registry + data layer ─────────────────────────────────────────────────────────────────────────────────────
require_once $bizcity_crm_inc . 'class-crm-spine.php';
require_once $bizcity_crm_inc . 'class-db-installer.php';
require_once $bizcity_crm_inc . 'class-capabilities.php';
require_once $bizcity_crm_inc . 'class-event-emitter.php';
require_once $bizcity_crm_inc . 'class-repository.php';

// ── Identity / scope / policy ─────────────────────────────────────────────────────────────────────────────────
require_once $bizcity_crm_inc . 'class-inbox-access.php';
// [2026-11-20 08:06 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.156 D156-5 — class-team-manager.php moved to bizcity-twin-crm (with its tables and REST routes); Staff_Policy reads it behind class_exists.
require_once $bizcity_crm_inc . 'class-staff-policy.php';
// [2026-10-17 10:59 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.116 S116-X4 (C1, doc 97) — class-conversation-identity-resolver, class-contact-transfer, class-personal-quota-rest, class-google-tool-bridge,
// class-inbox-to-crm-bridge, class-crm-ingest-notif, admin-chat/functions.php, admin-chat/class-admin-chat-grants-admin, class-guru-resolver,
// class-guru-roles-admin and class-crm-order-identity-metabox moved to plugins/bizcity-twin-crm (nothing in core calls them; the add-on loads them).
require_once $bizcity_crm_inc . 'class-crm-contact-identity.php';
require_once $bizcity_crm_inc . 'class-contact-roles.php';
require_once $bizcity_crm_inc . 'class-contact-custom-meta.php';
// [2026-11-19 10:25 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.156 S156-3.1 — class-contact-signals.php (crm_signal read projection) moved to bizcity-twin-crm; every core reader is class_exists-guarded.
require_once $bizcity_crm_inc . 'class-contact-enrichment.php';
// [2026-10-24 Johnny Chu - Chu Hoàng Anh] PHASE-0.126 R126-C2 — BizCity_CRM_Lead_Score moved to bizcity-twin-crm (its only reader, class_exists-guarded).
require_once $bizcity_crm_inc . 'class-system-owner.php';
// [2026-11-21 01:32 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.156 W6 (D) — Zalo directory, group secretary, group_index pack and the group-notebook REST
// (PHASE-0.120 / 0.124) moved to core/channel-gateway/includes/zalo-groups/: they are Zalo features of the cell, not the CRM ledger. Channel Gateway
// loads them at plugins_loaded@0, only when this spine is loaded (same condition as before: not on the TwinChat admin page). Class names unchanged.
// [2026-11-17 10:04 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.153 V4b — khách 1-1 nói "lưu giúp" ⇒ ngăn khách (guarded: new file; dùng files_from_rows của thư ký nhóm, class_exists ở chỗ gọi)
if ( is_readable( $bizcity_crm_inc . 'class-crm-contact-space.php' ) ) {
	require_once $bizcity_crm_inc . 'class-crm-contact-space.php';
	BizCity_CRM_Contact_Space::init();
}
// [2026-11-21 01:59 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.156 W6 HOTFIX — uploading core/crm alone left NOTHING loading the group secretary ⇒ the config bundle
// lost owner_agent.groups ⇒ the cell announced "dừng nhiệm vụ thư ký" in every followed group. The spine loads the Zalo groups itself now: the new folder
// when it is on the server, else the old core/crm/includes files a partial upload leaves behind. The loader and the old files are class_exists-guarded.
$bizcity_zalo_groups_loader = dirname( __DIR__ ) . '/channel-gateway/includes/zalo-groups/loader.php';
if ( is_readable( $bizcity_zalo_groups_loader ) ) {
	require_once $bizcity_zalo_groups_loader;
} else {
	foreach ( array(
		'class-crm-zalo-directory.php'          => 'BizCity_CRM_Zalo_Directory',
		'class-crm-group-secretary.php'         => 'BizCity_CRM_Group_Secretary',
		'class-crm-group-index-pack.php'        => 'BizCity_CRM_Group_Index_Pack',
		'rest/class-crm-group-notebook-rest.php' => 'BizCity_CRM_Group_Notebook_REST',
	) as $bizcity_zg_file => $bizcity_zg_class ) {
		if ( ! class_exists( $bizcity_zg_class, false ) && is_readable( $bizcity_crm_inc . $bizcity_zg_file ) ) {
			require_once $bizcity_crm_inc . $bizcity_zg_file;
			if ( 'BizCity_CRM_Group_Index_Pack' === $bizcity_zg_class ) {
				BizCity_CRM_Group_Index_Pack::register();
			} else {
				call_user_func( array( $bizcity_zg_class, 'init' ) );
			}
		}
	}
	unset( $bizcity_zg_file, $bizcity_zg_class );
}
unset( $bizcity_zalo_groups_loader );
// [2026-11-19 10:25 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.156 S156-3.1 — crm-surfaces.php moved to bizcity-twin-crm (its only reader); core copy is crm-surfaces_deleted.php.
foreach ( array( 'class-crm-actor.php', 'class-crm-authority.php', 'class-crm-zone-registry.php', 'class-crm-agent-mode-delegate.php' ) as $bizcity_crm_contract ) {
	require_once $bizcity_crm_inc . 'contracts/' . $bizcity_crm_contract;
}
unset( $bizcity_crm_contract );

// ── Channel ingest + outbound ─────────────────────────────────────────────────────────────────────────────────
require_once $bizcity_crm_inc . 'inbox/interface-channel-adapter.php';
require_once $bizcity_crm_inc . 'inbox/class-adapter-base.php';
require_once $bizcity_crm_inc . 'inbox/class-channel-contract.php';
require_once $bizcity_crm_inc . 'inbox/class-channel-registry.php';
require_once $bizcity_crm_inc . 'inbox/class-outbound-dispatcher.php';
// [2026-11-21 12:43 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.156 W5 — the Facebook bridge + adapter moved to plugins/bizcity-facebook-bot/includes/crm/ (registered by that plugin),
// the Mabel Wheel adapter moved to bizcity-twin-crm, the Zalo Bot bridge + adapter are cut (Zalo Bot archived, PHASE-0.152). Their old inboxes stay readable
// (adapter-less: generic label, sending refused with channel_adapter_unavailable), like web_widget below.
// [2026-10-16 03:23 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.116 CS1.4 (doc 87, owner Q-CS-2) — instagram / whatsapp-cloud / email-imap adapters archived to core/_archived/crm-20261016/ (no site uses them).
// [2026-10-25 Johnny Chu - Chu Hoàng Anh] PHASE-0.126 R126-RTR — web-widget adapter (public JS-snippet visitor chat) archived at core/_archived/admin-routers-r126rt2-20261025/;
// existing web_widget inboxes/conversations still read (adapter-less: generic label, sending refused with channel_adapter_unavailable).
// [2026-11-26 Johnny Chu - Chu Hoàng Anh] gộp PHASE-0.156 (bỏ adapter cũ) với PHASE-017 017.10: 'facebook-personal' (kế thừa zalo-personal) phải nạp SAU zalo-personal.
foreach ( array( 'zalo', 'zalo-oa', 'zalo-personal', 'facebook-personal' ) as $bizcity_crm_adapter ) { // R126-WC — webchat adapter archived
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

// [2026-11-19 10:25 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.156 S156-3.1 (Q156-2) — the core /crm/ "Đội Zalo" fallback page is removed: the inbox belongs to the
// CRM plugin (it registers its own /crm/). Without the plugin, conversations stay readable in Channel Gateway (Bot Studio · Sessions · Contacts).

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
		 * The spine is loaded; extension plugins (Open CRM) boot after this on plugins_loaded@6.
		 */
		do_action( 'bizcity_crm_spine_loaded' );
	}

	/** Built-in channel adapters (eager; priority 5 so third parties override at 10). */
	private static function register_built_in_adapters(): void {
		add_filter( 'bizcity_crm_register_adapters', static function ( array $adapters ): array {
			$map = array(
				// [2026-11-21 12:43 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.156 W5 — 'facebook' is registered by bizcity-facebook-bot, 'mabel_wheel' by bizcity-twin-crm; 'zalo_bot' is cut.
				'zalo'           => 'BizCity_CRM_Adapter_Zalo',
				'zalo_oa'        => 'BizCity_CRM_Adapter_ZaloOA',
				'zalo_personal'  => 'BizCity_CRM_Adapter_ZaloPersonal',
				'facebook_personal' => 'BizCity_CRM_Adapter_FacebookPersonal', // [2026-11-24 Johnny Chu - Chu Hoàng Anh] PHASE-017 017.10
				// [2026-10-25 Johnny Chu - Chu Hoàng Anh] PHASE-0.126 R126-RTR — 'web_widget' => BizCity_CRM_Adapter_Web_Widget removed (adapter archived).
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
