<?php
/**
 * Channel Gateway — Debug Logger
 *
 * Compatibility facade để truy vết pipeline:
 *   Webhook in → waic_twf_process_flow → Campaign_Tracker (visit) →
 *   Scenario_Dispatcher (dispatch) → Outbound message → FB Publisher.
 *
 * New operational rows delegate to BizCity_Channel_File_Logger. The historical
 * bizcity-cg-logs tree and debug routes remain migration-only until parity and
 * retirement gates pass.
 *
 * (Đa site: wp_upload_dir() đã tự chèn /sites/{blog_id}/ nên multisite hoạt
 * động không cần code thêm.)
 *
 * UI/routes:
 *   - Legacy Tools/REST debug surfaces remain compatibility-only.
 *   - Canonical target: shared BizCity Log Explorer via /bizcity-channel/v1/logs.
 *
 * Bảo mật:
 *   - Folder log đẻ kèm `.htaccess deny from all` + `index.html` empty.
 *   - REST yêu cầu `manage_options`.
 *   - Body payload có thể chứa token / PII → mask qua mask_sensitive().
 *
 * @package BizCity_Twin_AI
 * @subpackage Channel_Gateway
 * @since 2026-05-25
 */

defined( 'ABSPATH' ) || exit;

class BizCity_CG_Debug_Logger {

	const NAMESPACE_V1 = 'bizcity-channel/v1';
	const LOG_DIRNAME  = 'bizcity-cg-logs';
	const OPTION_FLAG  = 'bizcity_cg_debug_logger_enabled';
	const RETENTION_HOOK = 'bizcity_channel_jsonl_retention';
	const RETENTION_DAYS = 7; // [2026-08-01 Johnny Chu] PHASE-1.28-RETENTION-7D — keep channel evidence for one week.
	// [2026-09-05 Johnny Chu - Chu Hoàng Anh] PHASE-0.39C — retain Zalo Personal operational JSONL under the canonical bounded retention sweep.
	const RETENTION_CHANNELS = array( 'email', 'facebook', 'messenger', 'zalo_oa', 'zalo_personal', 'zalo_bot', 'zalo_zns', 'telegram', 'webchat', 'cf7', 'mabel_wheel', 'profile', 'channel_gateway', 'astro', 'broadcast' );

	/** @var string Cached log dir for current request. */
	private static $cached_dir = '';

	/* ---------- Boot ---------- */

	public static function init(): void {
		// REST routes.
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_action( 'init', array( __CLASS__, 'register_retention_cron' ), 20 );
		add_action( self::RETENTION_HOOK, array( __CLASS__, 'gc_channel_logs' ), 10, 0 );

		// Admin page — [2026-10-25 Johnny Chu - Chu Hoàng Anh] PHASE-0.126 R126-DIAG (owner: "Công cụ chẩn đoán chuyển sang dev-only"): dev-only file, loaded only when present.
		if ( is_admin() && is_readable( __DIR__ . '/class-cg-debug-logger-admin.php' ) ) {
			require_once __DIR__ . '/class-cg-debug-logger-admin.php';
			if ( class_exists( 'BizCity_CG_Debug_Logger_Admin', false ) ) {
				add_action( 'admin_menu', array( 'BizCity_CG_Debug_Logger_Admin', 'register_admin_page' ) );
			}
		}

		// Skip auto-hook if disabled (set bizcity_cg_debug_logger_enabled = '0' via wp option).
		if ( get_option( self::OPTION_FLAG, '1' ) !== '1' ) {
			return;
		}

		// Tap pipeline hooks. Priority 1 → run before consumers / catch even nếu consumer fatal.
		add_action( 'waic_twf_process_flow', array( __CLASS__, 'on_twf_flow' ), 1, 2 );

		// FB Messenger referral-only events (handle_referral() fires this, NOT waic_twf_process_flow).
		add_action( 'bizcity_facebook_referral_received', array( __CLASS__, 'on_fb_referral_received' ), 1, 1 );

		// Zalo Hotline / BizCity ZNS outbound (bizhook) — channel = 'bizhook'.
		add_action( 'bizcity_zalo_hotline_sent',   array( __CLASS__, 'on_bizhook_sent' ),  1, 1 );
		add_action( 'bizcity_zalo_zns_sent',       array( __CLASS__, 'on_bizhook_sent' ),  1, 1 );
		add_action( 'bizcity_bizhook_intake',      array( __CLASS__, 'on_bizhook_intake' ), 1, 2 );

		// Webhook Router intake — logs raw FB/Zalo/Webchat POST before REST (catches ?fbhook=1, /bizfbhook/, etc.).
		add_action( 'bizcity_webhook_router_intake', array( __CLASS__, 'on_webhook_router_intake' ), 1, 3 );

		// Zalo Bot — raw webhook intake (fired by class-webhook-handler::handle_zalohook),
		// inbound message normalized event, generic webhook event router.
		add_action( 'bizcity_zalo_webhook_intake',    array( __CLASS__, 'on_zalo_webhook_intake' ),  1, 3 );
		add_action( 'bizcity_zalo_message_received',  array( __CLASS__, 'on_zalo_message_received' ), 1, 1 );
		add_action( 'bizcity_zalo_bot_webhook_event', array( __CLASS__, 'on_zalo_bot_event' ),       1, 3 );

		// [2026-06-19 Johnny Chu] PHASE-CG-CF7-LOG — CF7 form submission → cf7 channel file log
		add_action( 'wpcf7_mail_sent',   array( __CLASS__, 'on_cf7_mail_sent' ),   1, 1 );
		add_action( 'wpcf7_mail_failed', array( __CLASS__, 'on_cf7_mail_failed' ), 1, 1 );

		// CRM event emitter dispatches actions: bizcity_crm_event_{name}.
		add_action( 'bizcity_crm_event_crm_campaign_visit_recorded',     array( __CLASS__, 'on_campaign_visit' ),    1 );
		add_action( 'bizcity_crm_event_crm_campaign_scenario_dispatched',array( __CLASS__, 'on_scenario_dispatch' ), 1 );
		add_action( 'bizcity_crm_event_crm_campaign_reminder_sent',      array( __CLASS__, 'on_reminder_sent' ),     1 );
		add_action( 'bizcity_crm_event_crm_message_received',            array( __CLASS__, 'on_message_received' ),  1 );
		add_action( 'bizcity_crm_event_crm_message_sent',                array( __CLASS__, 'on_message_sent' ),      1 );

		// Scheduler reminder (FB Publisher trigger).
		add_action( 'bizcity_scheduler_reminder_fire', array( __CLASS__, 'on_reminder_fire' ), 1, 1 );

		// REST-level capture of inbound webhook (FB / Zalo) via rest_pre_dispatch.
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'on_rest_dispatch' ), 1, 3 );
	}

	public static function register_retention_cron(): void {
		if ( ! class_exists( 'BizCity_Cron_Manager' ) ) {
			return;
		}
		BizCity_Cron_Manager::instance()->register( array(
			'id'          => 'core.channel_gateway.jsonl_retention',
			'hook'        => self::RETENTION_HOOK,
			'interval'    => 'daily',
			'owner'       => 'core/channel-gateway',
			'description' => 'Bounded retention sweep for per-channel JSONL evidence.',
			'retention'   => self::RETENTION_DAYS,
		) );
	}

	public static function gc_channel_logs(): void {
		$deleted = 0;
		$deleted += self::purge_aggregate_logs( self::RETENTION_DAYS );
		foreach ( self::RETENTION_CHANNELS as $channel ) {
			$deleted += class_exists( 'BizCity_Channel_File_Logger' )
				? BizCity_Channel_File_Logger::purge_older_than( $channel, self::RETENTION_DAYS )
				: 0;
		}
		if ( class_exists( 'BizCity_Cron_Manager' ) ) {
			$cron = BizCity_Cron_Manager::instance();
			$cron->note( array( 'counters' => array( 'channel_jsonl_retention_deleted' => $deleted ) ) );
			$cron->note_event( 'channel_jsonl_retention', array(
				'deleted_files' => $deleted,
				'retention_days' => self::RETENTION_DAYS,
				'channels' => self::RETENTION_CHANNELS,
			) );
		}
	}

	private static function purge_aggregate_logs( $days ): int {
		// [2026-08-01 Johnny Chu] PHASE-1.28-RETENTION-7D — keep the aggregate
		// CG JSONL folder aligned with the per-channel retention window.
		$dir = self::ensure_dir();
		if ( $dir === '' ) {
			return 0;
		}
		$cutoff_ts = time() - ( max( 1, (int) $days ) * DAY_IN_SECONDS );
		$files = glob( rtrim( $dir, '/\\' ) . DIRECTORY_SEPARATOR . '*.jsonl' );
		if ( ! is_array( $files ) ) {
			return 0;
		}
		$deleted = 0;
		foreach ( $files as $file ) {
			$date = basename( $file, '.jsonl' );
			$file_ts = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ? strtotime( $date . ' 00:00:00 UTC' ) : false;
			if ( false !== $file_ts && $file_ts < $cutoff_ts && @unlink( $file ) ) {
				$deleted++;
			}
		}
		return $deleted;
	}

	/* ---------- Public log API ---------- */

	/**
	 * Ghi 1 row JSON.
	 *
	 * @param string $channel  Bucket: 'webhook'|'twf_flow'|'campaign_visit'|'scenario_dispatch'|...
	 * @param string $event    Event subtype (free text).
	 * @param array  $data     Arbitrary structured data (sẽ mask token).
	 * @param string $level    'debug'|'info'|'warn'|'error'.
	 */
	public static function log( string $channel, string $event, array $data = array(), string $level = 'info' ): void {
		// [2026-09-01 Johnny Chu] R-CH-10 — normalize legacy debug calls into the single channel diagnostics writer.
		if ( class_exists( 'BizCity_Chat_Correlation' ) ) {
			$data = BizCity_Chat_Correlation::ensure( $data, $event );
			if ( BizCity_Chat_Correlation::is_inbound_event( $event ) ) {
				$data = BizCity_Chat_Correlation::bind_pending_root( $data );
			}
		}
		if ( ! class_exists( 'BizCity_Channel_File_Logger', false ) ) {
			return;
		}
		$physical_channel = self::map_to_physical_channel( $channel, $data );
		BizCity_Channel_File_Logger::write( $physical_channel, $level, $event, '', self::mask_sensitive( $data ) );
	}

	/**
	 * Map a CG logger internal channel bucket to a canonical physical channel name.
	 *
	 * CG logger channel = an event-category string ('twf_flow.facebook', 'zalo_message_in', …).
	 * Physical channel  = the platform folder name ('facebook', 'zalo_bot', 'zalo_oa', …).
	 *
	 * [2026-06-19 Johnny Chu] PHASE-CG-CF7-LOG — R-CH-FILE-LOG
	 *
	 * @param string $channel  CG logger internal channel string.
	 * @param array  $data     Masked event data — may contain 'platform' hint.
	 * @return string          One of BizCity_Channel_File_Logger::CH_* values.
	 */
	private static function map_to_physical_channel( string $channel, array $data ): string {
		// [2026-09-01 Johnny Chu] R-CH-10 — explicit normalized platform/code wins over legacy substring mapping.
		$explicit_code = sanitize_key( (string) ( $data['code'] ?? $data['channel'] ?? '' ) );
		if ( in_array( $explicit_code, array( 'facebook', 'messenger', 'zalo_oa', 'zalo_bot', 'zalo_personal', 'zalo_zns', 'telegram', 'webchat', 'email', 'cf7' ), true ) ) {
			return $explicit_code;
		}
		$explicit_platform = strtoupper( (string) ( $data['platform'] ?? '' ) );
		$platform_map = array(
			'ZALO_BOT'      => 'zalo_bot',
			'ZALO_OA'       => 'zalo_oa',
			'ZALO_PERSONAL' => 'zalo_personal',
			'ZALO_ZNS'      => 'zalo_zns',
			'FB_MESS'       => 'messenger',
			'FACEBOOK'      => 'facebook',
			'MESSENGER'     => 'messenger',
			'TELEGRAM'      => 'telegram',
			'WEBCHAT'       => 'webchat',
			'EMAIL'         => 'email',
			'CF7'           => 'cf7',
		);
		if ( isset( $platform_map[ $explicit_platform ] ) ) {
			return $platform_map[ $explicit_platform ];
		}

		// Exact / prefix matches (fast path).
		$exact_map = array(
			'fb_webhook_raw'   => 'facebook',
			'fb_referral'      => 'facebook',
			'zalo_webhook_raw' => 'zalo_bot',
			'zalo_message_in'  => 'zalo_bot',
			'zalo_event'       => 'zalo_bot',
			'bizhook'          => 'zalo_oa',
			// [2026-06-25 Johnny Chu] PHASE-CG-CF7-ZNS — ZNS dedicated channel
			'zalo_zns'         => 'zalo_zns',
			'email'            => 'email',
			'cf7'              => 'cf7',
			'telegram'         => 'telegram',
		);
		if ( isset( $exact_map[ $channel ] ) ) {
			return $exact_map[ $channel ];
		}

		// Substring matches for compound channel strings (twf_flow.*, twinchat.*).
		if ( strpos( $channel, 'messenger' ) !== false )   { return 'messenger'; }
		if ( strpos( $channel, 'twinchat' ) !== false )    { return 'webchat'; }
		// [2026-08-01 Johnny Chu] HOTFIX — 'twinweb' channel string does not contain
		// 'webchat'/'twinchat' as a substring, so it was silently falling through to
		// the generic channel_gateway bucket instead of webchat/ as documented in
		// PHASE-0-TWINWEB-SEARCH-CITATION-CHANNEL.md §Logging.
		if ( strpos( $channel, 'twinweb' ) !== false )     { return 'webchat'; }
		if ( strpos( $channel, 'webchat' ) !== false )     { return 'webchat'; }
		if ( strpos( $channel, 'telegram' ) !== false )    { return 'telegram'; }
		if ( strpos( $channel, 'facebook' ) !== false )    { return 'facebook'; }
		if ( strpos( $channel, 'fb_' ) !== false )         { return 'facebook'; }
		if ( strpos( $channel, 'zalo_oa' ) !== false )     { return 'zalo_oa'; }
		// [2026-06-25 Johnny Chu] PHASE-CG-CF7-ZNS — match zalo_zns BEFORE generic zalo
		if ( strpos( $channel, 'zalo_zns' ) !== false )    { return 'zalo_zns'; }
		if ( strpos( $channel, 'zalo' ) !== false )        { return 'zalo_bot'; }
		if ( strpos( $channel, 'email' ) !== false )       { return 'email'; }
		if ( strpos( $channel, 'cf7' ) !== false )         { return 'cf7'; }

		// Fallback: try platform hint in data
		$platform = strtolower( (string) (
			$data['platform']
			?? ( is_array( $data['envelope'] ?? null ) ? ( $data['envelope']['platform'] ?? '' ) : '' )
			?? ( is_array( $data['payload'] ?? null )  ? ( $data['payload']['platform']  ?? '' ) : '' )
			?? ''
		) );
		if ( strpos( $platform, 'messenger' ) !== false )  { return 'messenger'; }
		if ( strpos( $platform, 'facebook' ) !== false || strpos( $platform, 'fb_' ) !== false ) {
			return 'facebook';
		}
		if ( strpos( $platform, 'zalo_oa' ) !== false )    { return 'zalo_oa'; }
		if ( strpos( $platform, 'zalo' ) !== false )       { return 'zalo_bot'; }
		if ( strpos( $platform, 'telegram' ) !== false )   { return 'telegram'; }
		if ( strpos( $platform, 'webchat' ) !== false )    { return 'webchat'; }

		return BizCity_Channel_File_Logger::CH_CHANNEL_GATEWAY;
	}

	/* ---------- Hook handlers ---------- */

	public static function on_twf_flow( $key, $payload = array() ): void {
		// Derive source channel from trigger key for per-channel filtering.
		$key_lc  = strtolower( (string) $key );
		if ( strpos( $key_lc, 'webchat' ) !== false || strpos( $key_lc, 'twinchat' ) !== false ) {
			$src = 'twinchat';
		} elseif ( strpos( $key_lc, 'facebook' ) !== false || strpos( $key_lc, '_fb_' ) !== false ) {
			$src = 'twf_flow.facebook';
		} elseif ( strpos( $key_lc, 'zalo' ) !== false ) {
			$src = 'twf_flow.zalo';
		} else {
			$src = 'twf_flow';
		}
		self::log( $src, (string) $key, array(
			'payload_keys' => is_array( $payload ) ? array_keys( $payload ) : array(),
			'payload'      => self::trim_for_log( $payload ),
		) );
	}

	/**
	 * Zalo Hotline / ZNS outbound (bizhook channel).
	 */
	public static function on_bizhook_sent( $payload ): void {
		self::log( 'bizhook', 'zalo_hotline_sent', is_array( $payload ) ? self::trim_for_log( $payload ) : array( 'raw' => $payload ) );
	}

	/**
	 * Zalo Hotline / bizhook raw intake.
	 */
	public static function on_bizhook_intake( $data, $meta = array() ): void {
		$arr = is_array( $data ) ? self::trim_for_log( $data ) : array( 'raw' => $data );
		self::log( 'bizhook', 'bizhook_intake', array_merge( $arr, is_array( $meta ) ? $meta : array() ) );
	}

	public static function on_campaign_visit( $payload ): void {
		self::log( 'campaign_visit', 'crm_campaign_visit_recorded', is_array( $payload ) ? $payload : array( 'raw' => $payload ) );
	}

	public static function on_scenario_dispatch( $payload ): void {
		$arr   = is_array( $payload ) ? $payload : array();
		$level = ! empty( $arr['ok'] ) ? 'info' : 'warn';
		self::log( 'scenario_dispatch', 'crm_campaign_scenario_dispatched', $arr, $level );
	}

	public static function on_reminder_sent( $payload ): void {
		self::log( 'reminder', 'crm_campaign_reminder_sent', is_array( $payload ) ? $payload : array() );
	}

	public static function on_message_received( $payload ): void {
		self::log( 'message_in', 'crm_message_received', is_array( $payload ) ? self::trim_for_log( $payload ) : array() );
	}

	public static function on_message_sent( $payload ): void {
		self::log( 'message_out', 'crm_message_sent', is_array( $payload ) ? self::trim_for_log( $payload ) : array() );
	}

	public static function on_reminder_fire( $event ): void {
		self::log( 'scheduler', 'bizcity_scheduler_reminder_fire', is_array( $event ) ? $event : array( 'raw' => $event ) );
	}

	/**
	 * FB Messenger referral-only event (fired by bizcity-facebook-bot handle_referral()).
	 * Payload: { page_id, client_id (PSID), ref, ref_decrypted, input_data }.
	 */
	public static function on_fb_referral_received( $payload ): void {
		$arr = is_array( $payload ) ? $payload : array( 'raw' => $payload );
		self::log( 'fb_referral', 'bizcity_facebook_referral_received', $arr );
	}

	/**
	 * Raw webhook intake — fired by BizCity_Webhook_Router before adapter processes.
	 * $log_meta = { date, id }; $platform = 'FB_MESS'|...; $body = raw string.
	 */
	public static function on_webhook_router_intake( $log_meta, $platform, $body ): void {
		// [2026-06-19 Johnny Chu] R-CH-FILE-LOG — map Router platform key to the correct
		// internal channel bucket so map_to_physical_channel() routes JSONL to the right
		// per-channel folder (facebook/, zalo_bot/, zalo_oa/, webchat/, telegram/).
		// Before this fix every platform was logged as 'fb_webhook_raw' → all went to facebook/.
		$platform_to_channel = array(
			'FB_MESS'      => 'fb_webhook_raw',   // → facebook/
			'ZALO_BOT'     => 'zalo_webhook_raw', // → zalo_bot/
			'ZALO_HOTLINE' => 'bizhook',          // → zalo_oa/
			'WEBCHAT'      => 'twinchat',         // → webchat/
			'TELEGRAM'     => 'telegram',         // → telegram/
		);
		$channel = isset( $platform_to_channel[ $platform ] ) ? $platform_to_channel[ $platform ] : 'fb_webhook_raw';

		$parsed = array();
		if ( is_string( $body ) && $body !== '' ) {
			$decoded = json_decode( $body, true );
			$parsed  = is_array( $decoded ) ? self::trim_for_log( $decoded ) : array( '_raw_snippet' => substr( $body, 0, 500 ) );
		}
		self::log( $channel, 'webhook_router_intake', array(
			'platform'    => $platform,
			'log_row_id'  => is_array( $log_meta ) ? ( $log_meta['id'] ?? null ) : null,
			// [2026-07-08 Johnny Chu] HOTFIX — keep request telemetry so we can
			// distinguish empty-body webhook pings from real inbound payloads.
			'body_len'    => is_string( $body ) ? strlen( $body ) : 0,
			'method'      => isset( $_SERVER['REQUEST_METHOD'] ) ? (string) $_SERVER['REQUEST_METHOD'] : '',
			'content_len' => isset( $_SERVER['CONTENT_LENGTH'] ) ? (string) $_SERVER['CONTENT_LENGTH'] : '',
			'content_type'=> isset( $_SERVER['CONTENT_TYPE'] ) ? (string) $_SERVER['CONTENT_TYPE'] : '',
			'user_agent'  => isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( (string) $_SERVER['HTTP_USER_AGENT'], 0, 255 ) : '',
			'body_parsed' => $parsed,
		) );
	}

	/**
	 * Zalo Bot — raw `/zalohook/` POST intake.
	 *
	 * @param array       $data         Decoded JSON payload from Zalo.
	 * @param string|null $secret_token Value of `X-Bot-Api-Secret-Token` header (may be empty).
	 */
	public static function on_zalo_webhook_intake( $data, $secret_token = '', $bot = null ): void {
		$arr     = is_array( $data ) ? self::trim_for_log( $data ) : array( 'raw' => $data );
		$from    = is_array( $data ) && isset( $data['message']['from'] ) && is_array( $data['message']['from'] )
			? $data['message']['from'] : array();
		$header_present = ( $secret_token !== '' && $secret_token !== null );
		$bot_resolved   = is_object( $bot );
		self::log( 'zalo_webhook_raw', 'zalohook_intake', array(
			'bot_id'         => is_object( $bot ) ? (int) ( $bot->id ?? 0 ) : 0,
			'bot_name'       => is_object( $bot ) ? ( $bot->bot_name ?? null ) : null,
			'has_secret'     => $secret_token !== '' && $secret_token !== null,
			'secret_matched' => is_object( $bot ),
			// [2026-07-08 Johnny Chu] HOTFIX — keep non-sensitive boolean flags that
			// survive mask_sensitive() so diagnostics don't show "***" for both cases.
			'xbot_header_present' => $header_present,
			'bot_resolved'        => $bot_resolved,
			'event_name'     => is_array( $data ) ? ( $data['event_name'] ?? null ) : null,
			'from_user_id'   => isset( $from['id'] ) ? (string) $from['id'] : null,
			'from_name'      => isset( $from['display_name'] ) ? (string) $from['display_name'] : null,
			'payload'        => $arr,
		) );
	}

	/**
	 * Zalo inbound message (normalized) — fired by webhook handler after dispatch.
	 */
	public static function on_zalo_message_received( $message_data ): void {
		$arr = is_array( $message_data ) ? self::trim_for_log( $message_data ) : array( 'raw' => $message_data );
		self::log( 'zalo_message_in', 'bizcity_zalo_message_received', $arr );
	}

	/**
	 * CF7 form submission sent successfully.
	 * [2026-06-19 Johnny Chu] PHASE-CG-CF7-LOG — cf7 channel
	 */
	public static function on_cf7_mail_sent( $cf7 ): void {
		$form_id    = is_object( $cf7 ) && method_exists( $cf7, 'id' )    ? (int)    $cf7->id()    : 0;
		$form_title = is_object( $cf7 ) && method_exists( $cf7, 'title' ) ? (string) $cf7->title() : '';
		$sub        = class_exists( 'WPCF7_Submission' ) ? WPCF7_Submission::get_instance() : null;
		$posted     = $sub ? self::trim_for_log( (array) $sub->get_posted_data() ) : array();
		self::log( 'cf7', 'wpcf7_mail_sent', array(
			'form_id'    => $form_id,
			'form_title' => $form_title,
			'posted'     => $posted,
		), 'info' );
	}

	/**
	 * CF7 form submission failed (mail send error).
	 * [2026-06-19 Johnny Chu] PHASE-CG-CF7-LOG — cf7 channel
	 */
	public static function on_cf7_mail_failed( $cf7 ): void {
		$form_id    = is_object( $cf7 ) && method_exists( $cf7, 'id' )    ? (int)    $cf7->id()    : 0;
		$form_title = is_object( $cf7 ) && method_exists( $cf7, 'title' ) ? (string) $cf7->title() : '';
		self::log( 'cf7', 'wpcf7_mail_failed', array(
			'form_id'    => $form_id,
			'form_title' => $form_title,
		), 'error' );
	}

	/**
	 * Generic Zalo webhook event router (follow / unfollow / submit_action ...).
	 */
	public static function on_zalo_bot_event( $bot, $event_name, $data ): void {
		self::log( 'zalo_event', (string) $event_name, array(
			'bot_id'    => is_object( $bot ) ? ( $bot->id ?? null ) : null,
			'bot_name'  => is_object( $bot ) ? ( $bot->bot_name ?? null ) : null,
			'data'      => is_array( $data ) ? self::trim_for_log( $data ) : array( 'raw' => $data ),
		) );
	}

	/**
	 * Capture REST inbound — chỉ ghi route quan tâm để tránh log toàn bộ wp-json.
	 */
	public static function on_rest_dispatch( $result, $server, $request ) {
		$route = is_object( $request ) && method_exists( $request, 'get_route' ) ? (string) $request->get_route() : '';
		if ( $route === '' ) { return $result; }

		// Chỉ log webhook channels + bizcity-crm/v1 + bizcity-channel/v1 (loại trừ debug-logs endpoint để khỏi vòng lặp).
		$is_interesting = (
			strpos( $route, '/bizcity-channel/' ) === 0 ||
			strpos( $route, '/bizcity-crm/' ) === 0 ||
			strpos( $route, '/bizcity-webhook/' ) === 0
		);
		if ( ! $is_interesting ) { return $result; }

		// [2026-06-19 Johnny Chu] R-CH-FILE-LOG — map REST webhook route to correct
		// physical channel so JSONL goes to the right folder.
		// Also write BizCity_Webhook_Log for inbound webhook POSTs so admin SPA shows them.
		$method      = $request->get_method();
		$is_inbound  = ( $method === 'POST' ) && ( strpos( $route, '/webhook/' ) !== false );

		// Derive channel from route segments.
		if ( strpos( $route, '/webhook/zalo_oa' ) !== false ) {
			$channel  = 'zalo_oa_webhook';
			$platform = 'ZALO_OA';
		} elseif ( strpos( $route, '/webhook/telegram' ) !== false ) {
			$channel  = 'telegram';
			$platform = 'TELEGRAM';
		} elseif ( strpos( $route, '/webhook/facebook' ) !== false || strpos( $route, '/webhook/fb_' ) !== false ) {
			$channel  = 'fb_webhook_raw';
			$platform = 'FB_MESS';
		} elseif ( strpos( $route, '/webhook/webchat' ) !== false || strpos( $route, '/webhook/twinchat' ) !== false ) {
			$channel  = 'twinchat';
			$platform = 'WEBCHAT';
		} elseif ( strpos( $route, '/webhook/zalo_bot' ) !== false ) {
			$channel  = 'zalo_webhook_raw';
			$platform = 'ZALO_BOT';
		} else {
			$channel  = 'webhook';
			$platform = '';
		}

		self::log( $channel, $method . ' ' . $route, array(
			'method'   => $method,
			'route'    => $route,
			'platform' => $platform,
			'params'   => self::trim_for_log( $request->get_params() ),
			'headers'  => self::pick_headers( $request->get_headers() ),
		) );

		// For genuine inbound webhook POSTs (not admin SPA calls), also write
		// BizCity_Webhook_Log so the admin SPA (GET /logs?platform=...) shows them.
		if ( $is_inbound && $platform !== '' && class_exists( 'BizCity_Webhook_Log', false ) ) {
			BizCity_Webhook_Log::log( array(
				'platform'      => $platform,
				'endpoint'      => $route,
				'method'        => 'POST',
				'http_status'   => 200,
				'verify_status' => 'pending',
				'remote_ip'     => $_SERVER['REMOTE_ADDR'] ?? '',
				'user_agent'    => substr( (string) ( $_SERVER['HTTP_USER_AGENT'] ?? '' ), 0, 200 ),
				'body_raw'      => wp_json_encode( $request->get_params() ),
			) );
		}

		return $result;
	}

	/* ---------- REST routes ---------- */

	public static function register_routes(): void {
		register_rest_route( self::NAMESPACE_V1, '/debug-logs', array(
			'methods'             => 'GET',
			// [2026-09-23 Claude Sonnet 5] Core-wide super-admin capability audit — bare manage_options wrongly rejected Network Super Admins with no local blog role.
			'permission_callback' => function () {
				return class_exists( 'BizCity_Network_Admin_Capability' )
					? BizCity_Network_Admin_Capability::can_manage()
					: current_user_can( 'manage_options' );
			},
			'callback'            => array( __CLASS__, 'rest_list' ),
			'args'                => array(
				'date'    => array( 'type' => 'string', 'required' => false ),
				'channel'     => array( 'type' => 'string',  'required' => false ),
				'level'       => array( 'type' => 'string',  'required' => false ),
				'q'           => array( 'type' => 'string',  'required' => false ),
				'campaign_id' => array( 'type' => 'integer', 'required' => false, 'default' => 0,
					'description' => 'Filter rows where data.campaign_id equals this OR webhook route contains /campaigns/{id}/.' ),
				'account_id' => array( 'type' => 'string', 'required' => false, 'default' => '' ),
				'event'   => array( 'type' => 'string', 'required' => false, 'default' => '' ),
				'stage'   => array( 'type' => 'string', 'required' => false, 'default' => '' ),
				'trace_id' => array( 'type' => 'string', 'required' => false, 'default' => '' ),
				'operational_logged' => array( 'type' => 'string', 'required' => false, 'default' => '' ),
				'context_captured' => array( 'type' => 'string', 'required' => false, 'default' => '' ),
				'ledger_indexed' => array( 'type' => 'string', 'required' => false, 'default' => '' ),
				'kg_candidate' => array( 'type' => 'string', 'required' => false, 'default' => '' ),
				'limit'       => array( 'type' => 'integer', 'default' => 500 ),
				'tail'        => array( 'type' => 'boolean', 'default' => true, 'description' => 'true = lấy N row cuối, false = N row đầu' ),
			),
		) );

		register_rest_route( self::NAMESPACE_V1, '/debug-logs/dates', array(
			'methods'             => 'GET',
			// [2026-09-23 Claude Sonnet 5] Core-wide super-admin capability audit — bare manage_options wrongly rejected Network Super Admins with no local blog role.
			'permission_callback' => function () {
				return class_exists( 'BizCity_Network_Admin_Capability' )
					? BizCity_Network_Admin_Capability::can_manage()
					: current_user_can( 'manage_options' );
			},
			'callback'            => array( __CLASS__, 'rest_list_dates' ),
		) );

		register_rest_route( self::NAMESPACE_V1, '/debug-logs/clear', array(
			'methods'             => 'POST',
			// [2026-09-23 Claude Sonnet 5] Core-wide super-admin capability audit — bare manage_options wrongly rejected Network Super Admins with no local blog role.
			'permission_callback' => function () {
				return class_exists( 'BizCity_Network_Admin_Capability' )
					? BizCity_Network_Admin_Capability::can_manage()
					: current_user_can( 'manage_options' );
			},
			'callback'            => array( __CLASS__, 'rest_clear' ),
			'args'                => array(
				'date' => array( 'type' => 'string', 'required' => true ),
			),
		) );

		register_rest_route( self::NAMESPACE_V1, '/debug-logs/test-emit', array(
			'methods'             => 'POST',
			// [2026-09-23 Claude Sonnet 5] Core-wide super-admin capability audit — bare manage_options wrongly rejected Network Super Admins with no local blog role.
			'permission_callback' => function () {
				return class_exists( 'BizCity_Network_Admin_Capability' )
					? BizCity_Network_Admin_Capability::can_manage()
					: current_user_can( 'manage_options' );
			},
			'callback'            => function () {
				self::log( 'manual', 'test_emit', array( 'now' => time(), 'note' => 'admin clicked Test Emit' ) );
				return array( 'ok' => true );
			},
		) );

		// P1-Q3 (2026-05-26) — Threads view: same file, grouped by best-effort
		// thread key so each row in the UI = one inbound→outbound message turn.
		register_rest_route( self::NAMESPACE_V1, '/debug-logs/threads', array(
			'methods'             => 'GET',
			// [2026-09-23 Claude Sonnet 5] Core-wide super-admin capability audit — bare manage_options wrongly rejected Network Super Admins with no local blog role.
			'permission_callback' => function () {
				return class_exists( 'BizCity_Network_Admin_Capability' )
					? BizCity_Network_Admin_Capability::can_manage()
					: current_user_can( 'manage_options' );
			},
			'callback'            => array( __CLASS__, 'rest_list_threads' ),
			'args'                => array(
				'date'        => array( 'type' => 'string',  'required' => false ),
				'channel'     => array( 'type' => 'string',  'required' => false ),
				'level'       => array( 'type' => 'string',  'required' => false ),
				'q'           => array( 'type' => 'string',  'required' => false ),
				'campaign_id' => array( 'type' => 'integer', 'required' => false, 'default' => 0 ),
				'limit'       => array( 'type' => 'integer', 'default' => 5000 ),
			),
		) );
	}

	public static function rest_list( WP_REST_Request $req ) {
		// [2026-09-01 Johnny Chu] R-CH-10 — legacy debug route reads the canonical structured channel records instead of a second aggregate file tree.
		$date    = (string) ( $req->get_param( 'date' ) ?: gmdate( 'Y-m-d' ) );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return new WP_Error( 'bad_date', 'date must be YYYY-MM-DD', array( 'status' => 400 ) );
		}
		$channel     = (string) $req->get_param( 'channel' );
		$level       = (string) $req->get_param( 'level' );
		$q           = (string) $req->get_param( 'q' );
		$campaign_id = (int) $req->get_param( 'campaign_id' );
		$account_id  = (string) $req->get_param( 'account_id' );
		$event       = (string) $req->get_param( 'event' );
		$stage       = (string) $req->get_param( 'stage' );
		$trace_id    = (string) $req->get_param( 'trace_id' );
		$statuses    = array( 'operational_logged', 'context_captured', 'ledger_indexed', 'kg_candidate' );
		$limit       = max( 1, min( 5000, (int) $req->get_param( 'limit' ) ) );
		$tail        = (bool) $req->get_param( 'tail' );

		// Pre-build campaign_id match strings for OR-logic:
		//   (a) data.campaign_id field in campaign_visit / scenario_dispatch events
		//   (b) REST route pattern  /campaigns/{id}/ in webhook events
		$cid_data_str  = $campaign_id > 0 ? ('"campaign_id":' . $campaign_id) : '';
		$cid_route_str = $campaign_id > 0 ? ('/campaigns/' . $campaign_id . '/') : '';

		$filter_args = array(
			'channel' => '',
			'days' => 31,
			'limit' => 5000,
			'level' => $level,
			'account_id' => $account_id,
			'event' => '',
			'stage' => $stage,
			'trace_id' => $trace_id,
			'q' => $q,
			'date' => $date,
		);
		$channel_list = array_filter( array_map( 'trim', explode( ',', $channel ) ) );
		$legacy_channels = array(
			'twf_flow.zalo' => 'zalo_bot',
			'zalo_webhook_raw' => 'zalo_bot',
			'zalo_bot_event' => 'zalo_bot',
			'fb_webhook_raw' => 'facebook',
			'fb_referral' => 'facebook',
			'twf_flow.facebook' => 'facebook',
			'bizhook' => 'zalo_oa',
			'webhook' => 'channel_gateway',
			'twf_flow' => 'channel_gateway',
			'message_in' => 'channel_gateway',
			'message_out' => 'channel_gateway',
			'campaign_visit' => 'channel_gateway',
			'scenario_dispatch' => 'channel_gateway',
			'scheduler' => 'channel_gateway',
			'reminder' => 'channel_gateway',
			'twinchat' => 'webchat',
		);
		$channel_list = array_values( array_unique( array_map( function ( $value ) use ( $legacy_channels ) {
			return isset( $legacy_channels[ $value ] ) ? $legacy_channels[ $value ] : sanitize_key( $value );
		}, $channel_list ) ) );
		$rows = array();
		if ( class_exists( 'BizCity_Channel_File_Logger' ) ) {
			foreach ( $channel_list ? $channel_list : array( '' ) as $requested_channel ) {
				$filter_args['channel'] = $requested_channel;
				$filter_args['event'] = $event;
				foreach ( $statuses as $status_key ) {
					$filter_args[ $status_key ] = (string) $req->get_param( $status_key );
				}
				$rows = array_merge( $rows, BizCity_Channel_File_Logger::query_records( $filter_args ) );
			}
		}
		$unique = array();
		$rows = array_values( array_filter( $rows, function ( $row ) use ( &$unique, $campaign_id, $cid_data_str, $cid_route_str ) {
			$key = (string) ( $row['event_uuid'] ?? '' );
			if ( $key !== '' && isset( $unique[ $key ] ) ) { return false; }
			if ( $key !== '' ) { $unique[ $key ] = true; }
			if ( $campaign_id <= 0 ) { return true; }
			$encoded = wp_json_encode( $row );
			return ( is_string( $encoded ) && ( stripos( $encoded, $cid_data_str ) !== false || stripos( $encoded, $cid_route_str ) !== false ) );
		} ) );

		$total = count( $rows );
		if ( $tail ) {
			$rows = array_slice( $rows, -$limit );
		} else {
			$rows = array_slice( $rows, 0, $limit );
		}

		return array(
			'date'   => $date,
			'count'  => count( $rows ),
			'total'  => $total,
			'tail'   => $tail,
			'rows'   => array_values( $rows ),
			'file'   => 'bizcity-channel-logs/{channel}/YYYY-MM-DD.jsonl',
			'exists' => ! empty( $rows ),
		);
	}

	public static function rest_list_dates() {
		$dir = self::ensure_dir();
		$out = array();
		if ( $dir && is_dir( $dir ) ) {
			foreach ( (array) glob( $dir . '/*.jsonl' ) as $f ) {
				$name = basename( $f, '.jsonl' );
				if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $name ) ) {
					$out[] = array(
						'date'  => $name,
						'bytes' => (int) @filesize( $f ),
					);
				}
			}
			usort( $out, function ( $a, $b ) { return strcmp( $b['date'], $a['date'] ); } );
		}
		return array( 'dir' => self::relative_path( $dir ), 'dates' => $out );
	}

	public static function rest_clear( WP_REST_Request $req ) {
		$date = (string) $req->get_param( 'date' );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return new WP_Error( 'bad_date', 'date required', array( 'status' => 400 ) );
		}
		$file = self::ensure_dir() . '/' . $date . '.jsonl';
		$ok   = file_exists( $file ) ? @unlink( $file ) : true;
		return array( 'ok' => (bool) $ok, 'date' => $date );
	}

	/**
	 * P1-Q3 — Group rows by best-effort "thread key" so the UI can render
	 * one row per message turn (instead of a flat console stream).
	 *
	 * Thread key resolution priority (first non-empty wins):
	 *   1. data.trace_id                     (preferred — set by replier/dispatcher when emitting)
	 *   2. data.conversation_id              (CRM message events)
	 *   3. data.payload.conversation_id      (nested in some wrappers)
	 *   4. data.client_id                    (FB PSID / Zalo user id)
	 *   5. data.payload.client_id
	 *   6. data.log_row_id                   (webhook intake)
	 *   7. data.body_parsed.entry[0].messaging[0].sender.id  (FB raw webhook)
	 *   8. '_misc' bucket
	 *
	 * Returns: { date, file, exists, total, group_count, threads:[
	 *     { key, kind, started_at, ended_at, channels, count, summary, rows[] }
	 * ] } sorted by started_at DESC (latest thread first).
	 */
	public static function rest_list_threads( WP_REST_Request $req ) {
		$date    = (string) ( $req->get_param( 'date' ) ?: gmdate( 'Y-m-d' ) );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return new WP_Error( 'bad_date', 'date must be YYYY-MM-DD', array( 'status' => 400 ) );
		}
		$channel     = (string) $req->get_param( 'channel' );
		$level       = (string) $req->get_param( 'level' );
		$q           = (string) $req->get_param( 'q' );
		$campaign_id = (int) $req->get_param( 'campaign_id' );
		$limit       = max( 100, min( 20000, (int) $req->get_param( 'limit' ) ) );

		$file = self::ensure_dir() . '/' . $date . '.jsonl';
		if ( ! file_exists( $file ) ) {
			return array( 'date' => $date, 'file' => self::relative_path( $file ), 'exists' => false, 'total' => 0, 'group_count' => 0, 'threads' => array() );
		}

		$cid_data_str  = $campaign_id > 0 ? ('"campaign_id":' . $campaign_id) : '';
		$cid_route_str = $campaign_id > 0 ? ('/campaigns/' . $campaign_id . '/') : '';

		$fp = @fopen( $file, 'rb' );
		if ( ! $fp ) {
			return new WP_Error( 'io_error', 'cannot open log file', array( 'status' => 500 ) );
		}
		$threads = array();
		$total   = 0;
		while ( ( $line = fgets( $fp ) ) !== false ) {
			$line = trim( $line );
			if ( $line === '' ) { continue; }
			$dec = json_decode( $line, true );
			if ( ! is_array( $dec ) ) { continue; }
			if ( $channel !== '' && ( $dec['channel'] ?? '' ) !== $channel ) { continue; }
			if ( $level   !== '' && ( $dec['level']   ?? '' ) !== $level   ) { continue; }
			if ( $q !== '' && stripos( $line, $q ) === false ) { continue; }
			if ( $campaign_id > 0 ) {
				if ( stripos( $line, $cid_data_str ) === false && stripos( $line, $cid_route_str ) === false ) { continue; }
			}
			$total++;
			$key  = self::resolve_thread_key( $dec );
			$kind = self::resolve_thread_kind( $key, $dec );
			if ( ! isset( $threads[ $key ] ) ) {
				$threads[ $key ] = array(
					'key'        => $key,
					'kind'       => $kind,
					'started_at' => $dec['ts'] ?? null,
					'ended_at'   => $dec['ts'] ?? null,
					'channels'   => array(),
					'count'      => 0,
					'summary'    => '',
					'rows'       => array(),
				);
			}
			$threads[ $key ]['ended_at'] = $dec['ts'] ?? $threads[ $key ]['ended_at'];
			$threads[ $key ]['count']++;
			$ch = (string) ( $dec['channel'] ?? '' );
			if ( $ch !== '' && ! in_array( $ch, $threads[ $key ]['channels'], true ) ) {
				$threads[ $key ]['channels'][] = $ch;
			}
			$threads[ $key ]['rows'][] = $dec;
		}
		fclose( $fp );

		// Build summary + sort.
		foreach ( $threads as $k => &$t ) {
			$t['summary'] = self::summarize_thread( $t );
		}
		unset( $t );
		usort( $threads, function ( $a, $b ) {
			return strcmp( (string) $b['started_at'], (string) $a['started_at'] );
		} );
		if ( count( $threads ) > $limit ) {
			$threads = array_slice( $threads, 0, $limit );
		}
		return array(
			'date'        => $date,
			'file'        => self::relative_path( $file ),
			'exists'      => true,
			'total'       => $total,
			'group_count' => count( $threads ),
			'threads'     => array_values( $threads ),
		);
	}

	/** @internal */
	private static function resolve_thread_key( array $row ): string {
		$d = $row['data'] ?? array();
		if ( ! is_array( $d ) ) { return '_misc'; }
		$candidates = array(
			$d['trace_id']                      ?? null,
			$d['conversation_id']               ?? null,
			$d['payload']['conversation_id']    ?? null,
			$d['envelope']['conversation_id']   ?? null,
			$d['client_id']                     ?? null,
			$d['payload']['client_id']          ?? null,
			$d['from_user_id']                  ?? null,
			$d['log_row_id']                    ?? null,
		);
		foreach ( $candidates as $c ) {
			if ( $c !== null && $c !== '' && $c !== 0 && $c !== '0' ) {
				return (string) $c;
			}
		}
		// FB raw nested.
		$fb = $d['body_parsed']['entry'][0]['messaging'][0]['sender']['id'] ?? null;
		if ( $fb ) { return 'fb_' . (string) $fb; }
		return '_misc';
	}

	/** @internal */
	private static function resolve_thread_kind( string $key, array $row ): string {
		if ( $key === '_misc' ) { return 'misc'; }
		if ( strpos( $key, 'fb_' ) === 0 ) { return 'messenger_raw'; }
		$d = $row['data'] ?? array();
		if ( is_array( $d ) ) {
			if ( isset( $d['conversation_id'] ) || isset( $d['payload']['conversation_id'] ) ) {
				return 'conversation';
			}
			if ( isset( $d['trace_id'] ) ) { return 'trace'; }
			if ( isset( $d['client_id'] ) || isset( $d['payload']['client_id'] ) ) {
				return 'client';
			}
		}
		return 'other';
	}

	/** @internal — one-line digest for the threads list. */
	private static function summarize_thread( array $t ): string {
		$last = end( $t['rows'] );
		$ev   = is_array( $last ) ? (string) ( $last['event'] ?? '' ) : '';
		$lvl  = is_array( $last ) ? (string) ( $last['level'] ?? '' ) : '';
		return trim( sprintf(
			'%dx · %s · last=%s%s',
			(int) $t['count'],
			implode( '+', (array) $t['channels'] ),
			$ev,
			$lvl && $lvl !== 'info' ? "[$lvl]" : ''
		) );
	}

	/* ---------- Admin page ----------
	 * [2026-10-25 Johnny Chu - Chu Hoàng Anh] PHASE-0.126 R126-DIAG (owner: "Công cụ chẩn đoán chuyển sang dev-only") — the inline "BizCity Hook" log page
	 * (Tools › bizcity-cg-debug-logs) moved to class-cg-debug-logger-admin.php, which is dev-only (bin/dev-only-paths.txt) and loaded
	 * only when present. The REST routes above stay: the SPA ZaloLogsSheet reads /debug-logs.
	 */

	public static function ensure_dir(): string { // public since R126-DIAG (the dev-only admin page calls it)
		if ( self::$cached_dir !== '' ) { return self::$cached_dir; }

		$up = wp_upload_dir( null, false );
		if ( empty( $up['basedir'] ) ) { return ''; }
		$dir = trailingslashit( $up['basedir'] ) . self::LOG_DIRNAME;

		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		// Protect.
		if ( is_dir( $dir ) ) {
			$ht = $dir . '/.htaccess';
			if ( ! file_exists( $ht ) ) {
				@file_put_contents( $ht, "Order allow,deny\nDeny from all\n" );
			}
			$idx = $dir . '/index.html';
			if ( ! file_exists( $idx ) ) {
				@file_put_contents( $idx, '' );
			}
		}
		self::$cached_dir = $dir;
		return $dir;
	}

	public static function relative_path( string $abs ): string { // public since R126-DIAG
		if ( $abs === '' ) { return ''; }
		$rel = str_replace( ABSPATH, '', $abs );
		return '/' . ltrim( str_replace( '\\', '/', $rel ), '/' );
	}

	/**
	 * Recursive cap depth + length so 1 log row không nuốt 200 KB.
	 */
	private static function trim_for_log( $v, int $depth = 0 ) {
		if ( $depth > 6 ) { return '[depth-cap]'; }
		if ( is_array( $v ) ) {
			$out = array();
			$i   = 0;
			foreach ( $v as $k => $vv ) {
				if ( $i++ >= 50 ) { $out['__truncated_keys'] = count( $v ) - 50; break; }
				$out[ $k ] = self::trim_for_log( $vv, $depth + 1 );
			}
			return $out;
		}
		if ( is_object( $v ) ) {
			return self::trim_for_log( get_object_vars( $v ), $depth + 1 );
		}
		if ( is_string( $v ) && strlen( $v ) > 1024 ) {
			return substr( $v, 0, 1024 ) . '…[+' . ( strlen( $v ) - 1024 ) . ' bytes]';
		}
		return $v;
	}

	/**
	 * Mask token / access_token / secret / authorization fields.
	 */
	private static function mask_sensitive( array $data ): array {
		$re_keys = '/(token|access_token|secret|password|authorization|api_key|app_secret|page_token)/i';
		array_walk_recursive( $data, function ( &$v, $k ) use ( $re_keys ) {
			if ( is_string( $k ) && preg_match( $re_keys, $k ) && is_scalar( $v ) ) {
				$s = (string) $v;
				$v = strlen( $s ) > 8 ? substr( $s, 0, 4 ) . '…' . substr( $s, -4 ) : '***';
			}
		} );
		return $data;
	}

	/**
	 * Chỉ giữ headers hữu ích — bỏ cookie / auth.
	 */
	private static function pick_headers( array $headers ): array {
		$keep = array( 'content_type', 'content_length', 'user_agent', 'x_hub_signature', 'x_hub_signature_256', 'x_forwarded_for' );
		$out  = array();
		foreach ( $keep as $k ) {
			if ( isset( $headers[ $k ] ) ) {
				$out[ $k ] = is_array( $headers[ $k ] ) ? implode( ',', $headers[ $k ] ) : $headers[ $k ];
			}
		}
		return $out;
	}
}
