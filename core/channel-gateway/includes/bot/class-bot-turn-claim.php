<?php
/**
 * Bot Studio — per-contact helpers kept after the PHP turn engine left the core.
 *
 * Until PHASE-0.126 this class claimed Zalo Cá nhân turns for BizCity_Bot_Turn_Runner (PHASE-0.60A W3: hook on
 * `bizcity_channel_normalized` @0, claim context, yield to workflows). That engine is gone — every channel message is
 * answered by the cell (owner 2026-10-24: "tách channel-gateway/includes/bot/ php hoàn toàn, để 100% xử lý qua cell").
 * What other code still reads stays here, same keys and same behaviour:
 *   CODE / PLATFORM                       — Zalo Personal bridge-rest, binding code
 *   today_count / increment_today_count   — CRM contact enrichment (daily message cap, transient bzbot_cap_*)
 *   is_paused / set_paused                — pause window after a manual reply (transient bzbot_pause_*)
 *   decode_office_hours                   — core routes + /gpt/ axis (binding office_hours_json)
 *   account_gate                          — the `bizcity_bot_studio_account_gate` seam answered by the Zalo plugin
 * The original engine version is archived at core/_archived/bot-engine-20261024/kept-originals/.
 *
 * // [2026-10-24 Johnny Chu - Chu Hoàng Anh] PHASE-0.126 R126-BOT — claim / skip / workflow-yield / active registry removed with the runner.
 *
 * @package BizCity_Twin_AI
 * @subpackage Channel_Gateway\Bot
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_Bot_Turn_Claim {

	const PLATFORM = 'ZALO_PERSONAL';
	const CODE     = 'zalo_personal';

	/** No hook any more: the cell takes every turn. Kept so an older caller's guarded init() stays harmless. */
	public static function init(): void {}

	/**
	 * Why no assistant should answer this account ('' = no account-level objection). Answered by the channel plugin
	 * through `bizcity_bot_studio_account_gate` (Zalo Personal: BizCity_Zalo_Account_Flags::filter_bot_gate).
	 */
	public static function account_gate( string $account_id ): string {
		if ( '' === $account_id || ! function_exists( 'apply_filters' ) ) {
			return '';
		}
		$gate = apply_filters( 'bizcity_bot_studio_account_gate', '', self::PLATFORM, $account_id );
		return is_string( $gate ) ? sanitize_key( $gate ) : '';
	}

	/* ── pause / cap — keys are blog-scoped (B10.3) ── */

	public static function is_paused( int $contact_id ): bool {
		return (bool) get_transient( self::pause_key( $contact_id ) );
	}

	public static function set_paused( int $contact_id, int $minutes ): void {
		set_transient( self::pause_key( $contact_id ), 1, max( 60, $minutes * 60 ) );
	}

	public static function today_count( int $contact_id ): int {
		$val = get_transient( self::cap_key( $contact_id ) );
		return $val ? (int) $val : 0;
	}

	public static function increment_today_count( int $contact_id ): void {
		$key = self::cap_key( $contact_id );
		$val = self::today_count( $contact_id ) + 1;
		// Expire at local midnight so the cap resets once per calendar day.
		$seconds_to_midnight = 86400 - ( (int) current_time( 'timestamp' ) % 86400 );
		set_transient( $key, $val, max( 60, $seconds_to_midnight ) );
	}

	public static function decode_office_hours( $raw ): array {
		return self::decode_json_map( $raw );
	}

	/** Shared decode for any binding *_json column: array passthrough, or a JSON string. */
	private static function decode_json_map( $raw ): array {
		if ( is_array( $raw ) ) {
			return $raw;
		}
		if ( is_string( $raw ) && $raw !== '' ) {
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				return $decoded;
			}
		}
		return array();
	}

	private static function blog(): string {
		return (string) ( function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0 );
	}

	private static function pause_key( int $contact_id ): string {
		return 'bzbot_pause_' . self::blog() . '_' . $contact_id;
	}

	private static function cap_key( int $contact_id ): string {
		return 'bzbot_cap_' . self::blog() . '_' . $contact_id . '_' . ( function_exists( 'wp_date' ) ? wp_date( 'Y-m-d' ) : gmdate( 'Y-m-d' ) );
	}
}
