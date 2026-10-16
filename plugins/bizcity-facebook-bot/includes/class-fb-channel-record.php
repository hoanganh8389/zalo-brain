<?php
/**
 * The site's half of `channel-record@1` (PHASE-0.109 S109-L12): the cell answers Messenger by itself, so the CRM learns about every
 * message from the cell instead of from the webhook. Path: cell outbox → Hub `POST zalo-hub/channel-record` → this site
 * `POST bizcity-channel/v1/twin-web-bridge/channel-record` (Bearer = the web-bridge token, the same credential as the cell's other calls).
 *
 *   direction in      customer message  → Identity Hub contact + CRM inbound + the old bot's inbox table (so the way back keeps its history)
 *   direction out     the cell's answer → CRM outbound + inbox table (sender bot)
 *   direction handoff the cell did NOT answer (24 h window closed, token invalid, bot error, a person took over) → CRM task for the page owner
 * The CRM ingestors are the ones the old adapter already used (never a second writer). Idempotent by `idempotency_key` for 72 h. Nothing here
 * logs a message text. A record for a page this site does not own is refused (409), so a cell mix-up cannot write into another page's CRM.
 *
 * @package BizCity_Facebook_Bot
 * @since   PHASE-0.109 S109-L12
 */
// [2026-10-14 08:04 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.109 S109-L12

defined( 'ABSPATH' ) || exit;

// [2026-10-14 11:53 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.109 — the `class_exists(self, false) → return` guard that stood here ALWAYS returned: a top-level class without a parent is hoisted at compile time, so it already
// exists when the guard runs, and every statement after the class (the add_action below) was skipped. Production proof (bizcity.vn): class loaded, has_action(rest_api_init) false, /fb/setup 404.
// The file is loaded with require_once only, so no guard is needed.

final class BizCity_FB_Channel_Record {

	const NS         = 'bizcity-channel/v1';
	const ROUTE      = '/twin-web-bridge/channel-record';
	const CONTRACT   = 'channel-record@1.';
	const DEDUP_TTL  = 259200; // 72 h, the cell outbox's retry window + margin
	const DIRECTIONS = array( 'in', 'out', 'handoff' );
	const REASONS    = array( 'window_closed', 'token_invalid', 'no_token', 'bot_error', 'bot_off', 'send_failed' );

	/** @var array<string,callable> Tests: authorized(request): bool · crm_in(rec) · crm_out(rec) · inbox(rec) · handoff(rec): array · bind(rec) */
	public static $seams = array();

	public static function register_routes(): void {
		register_rest_route( self::NS, self::ROUTE, array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'handle' ),
			'permission_callback' => '__return_true', // Bearer verified in handler, same boundary as the rest of the web bridge
		) );
	}

	private static function authorized( $request ): bool {
		if ( isset( self::$seams['authorized'] ) ) {
			return (bool) call_user_func( self::$seams['authorized'], $request );
		}
		$token  = class_exists( 'BizCity_Twin_Web_Home' ) ? BizCity_Twin_Web_Home::bridge_token() : '';
		$header = (string) $request->get_header( 'authorization' );
		$bearer = 0 === stripos( $header, 'Bearer ' ) ? trim( substr( $header, 7 ) ) : '';
		return '' !== $token && '' !== $bearer && hash_equals( $token, $bearer );
	}

	private static function refuse( int $status, string $code, string $message, string $hint, string $help ): WP_REST_Response {
		return new WP_REST_Response( array( 'ok' => false, 'code' => $code, 'message' => $message, 'hint' => $hint, 'help_code' => $help ), $status );
	}

	public static function handle( $request ) {
		if ( ! self::authorized( $request ) ) {
			return self::refuse( 401, 'unauthorized', 'Unauthorized.', 'Gửi Authorization: Bearer <token cầu nối web của site>.', 'S109-L12-401' );
		}
		$rec = $request->get_json_params();
		$rec = is_array( $rec ) ? $rec : array();
		$key = (string) ( $rec['idempotency_key'] ?? '' );
		$dir = (string) ( $rec['direction'] ?? '' );
		$page = (string) ( $rec['channel_ref'] ?? '' );
		$uid  = (string) ( $rec['platform_uid'] ?? '' );
		if ( 0 !== strpos( (string) ( $rec['contract'] ?? '' ), self::CONTRACT ) || '' === $key || strlen( $key ) > 190 || ! in_array( $dir, self::DIRECTIONS, true )
			|| 'messenger' !== (string) ( $rec['platform'] ?? '' ) || ! preg_match( '/^[0-9]{5,30}$/', $page ) || '' === $uid || strlen( $uid ) > 190 ) {
			return self::refuse( 400, 'invalid_param', 'Body không đúng channel-record@1.', 'Xem fixture cid/channel-record.json.', 'S109-L12-400' );
		}
		if ( ! self::owns_page( $page ) ) {
			return self::refuse( 409, 'channel_not_owned', 'Page này không thuộc site.', 'Chọn và kết nối page ở trang Facebook của site trước.', 'S109-L12-409' );
		}
		$dedup = 'bizcity_fb_rec_' . md5( $key );
		if ( false !== get_transient( $dedup ) ) {
			return new WP_REST_Response( array( 'ok' => true, 'duplicate' => true ), 200 );
		}
		$rec['text'] = mb_substr( (string) ( $rec['text'] ?? '' ), 0, 8000 );
		$rec['mid']  = substr( (string) ( $rec['mid'] ?? '' ), 0, 190 );
		$result = array( 'ok' => true, 'direction' => $dir );
		try {
			if ( 'in' === $dir ) {
				self::apply_in( $rec );
			} elseif ( 'out' === $dir ) {
				self::apply_out( $rec );
			} else {
				$reason = in_array( (string) ( $rec['reason'] ?? '' ), self::REASONS, true ) ? (string) $rec['reason'] : 'bot_error';
				$h      = (array) self::apply_handoff( $rec + array(), $reason );
				$result['task_created'] = ! empty( $h['task_created'] );
			}
		} catch ( \Throwable $e ) {
			return self::refuse( 500, 'record_failed', 'Chưa ghi được vào CRM.', 'Máy chủ sẽ thử lại.', 'S109-L12-500' );
		}
		set_transient( $dedup, 1, self::DEDUP_TTL );
		return new WP_REST_Response( $result, 200 );
	}

	/** only pages the site connected (setup step ③) may write into its CRM */
	private static function owns_page( string $page ): bool {
		if ( isset( self::$seams['owns_page'] ) ) {
			return (bool) call_user_func( self::$seams['owns_page'], $page );
		}
		return class_exists( 'BizCity_FB_Channel_Adapter', false ) && isset( BizCity_FB_Channel_Adapter::pages()[ $page ] );
	}

	private static function apply_in( array $rec ): void {
		if ( isset( self::$seams['crm_in'] ) ) {
			call_user_func( self::$seams['crm_in'], $rec );
			return;
		}
		$contact = array( 'platform' => 'messenger', 'channel_ref' => (string) $rec['channel_ref'], 'platform_uid' => (string) $rec['platform_uid'] ) + ( ! empty( $rec['display_name'] ) ? array( 'display_name' => (string) $rec['display_name'] ) : array() );
		if ( class_exists( 'BizCity_FB_Channel_Adapter', false ) ) {
			BizCity_FB_Channel_Adapter::bind_identity( array( 'contact' => $contact ) ); // never attached to a WP user here (R-CID-6)
		}
		if ( class_exists( 'BizCity_CRM_Facebook_Ingestor' ) ) {
			$raw = array( 'page_id' => $contact['channel_ref'], 'user_id' => $contact['platform_uid'], 'message' => $rec['text'], 'user_name' => $contact['display_name'] ?? '', 'event' => array( 'message' => array( 'mid' => $rec['mid'] ) ), 'timestamp' => (int) strtotime( (string) ( $rec['at'] ?? '' ) ) );
			BizCity_CRM_Facebook_Ingestor::instance()->on_normalized( array( 'platform' => 'FB_MESS', 'account_id' => $contact['channel_ref'], 'user_id' => $contact['platform_uid'], 'message' => $rec['text'], 'raw' => $raw ) );
		}
		self::inbox( $rec, 'client' );
	}

	private static function apply_out( array $rec ): void {
		if ( isset( self::$seams['crm_out'] ) ) {
			call_user_func( self::$seams['crm_out'], $rec );
			return;
		}
		if ( class_exists( 'BizCity_FB_Channel_Adapter', false ) ) {
			BizCity_FB_Channel_Adapter::crm_outbound( (string) $rec['channel_ref'], (string) $rec['platform_uid'], $rec['text'], $rec['mid'] );
		}
		self::inbox( $rec, 'bot' );
	}

	/** keep the old bot's inbox table in step, so switching back to `site_legacy` leaves it with the whole conversation */
	private static function inbox( array $rec, string $sender ): void {
		if ( isset( self::$seams['inbox'] ) ) {
			call_user_func( self::$seams['inbox'], $rec, $sender );
			return;
		}
		if ( ! class_exists( 'BizCity_Facebook_Bot_Database' ) ) {
			return;
		}
		BizCity_Facebook_Bot_Database::instance()->save_inbox_message( array(
			'bot_id' => 0, 'client_id' => (string) $rec['platform_uid'], 'client_name' => (string) ( $rec['display_name'] ?? '' ), 'page_id' => (string) $rec['channel_ref'],
			'message_id' => 'client' === $sender ? (string) $rec['mid'] : '', 'message_text' => (string) $rec['text'], 'message_type' => 'text', 'sender_type' => $sender,
		) );
	}

	private static function apply_handoff( array $rec, string $reason ) {
		if ( isset( self::$seams['handoff'] ) ) {
			return call_user_func( self::$seams['handoff'], $rec, $reason );
		}
		return class_exists( 'BizCity_FB_Channel_Adapter', false ) ? BizCity_FB_Channel_Adapter::record_handoff( (string) $rec['channel_ref'], (string) $rec['platform_uid'], $reason ) : array();
	}
}

add_action( 'rest_api_init', array( 'BizCity_FB_Channel_Record', 'register_routes' ) );
