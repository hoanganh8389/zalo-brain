<?php
/**
 * Bot Studio — AI source resolution: BizCity 1API vs external API (PHASE-0.60C).
 *
 * [2026-10-14 12:28 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.107 S107-X2 R-ONE-VERTICAL-CELL — the `direct` branches were removed; the source is
 * always the Hub gateway. (Historic text below describes the pre-X2 four branches.)
 *
 * MVP (doc 0.60C §2.3): the choice lives at SITE level in the options
 * `core/bizcity-llm` already owns (`bizcity_llm_api_key`). Per-character override stays `inherit`, so this class
 * never stores a key and never opens an HTTP client — it only decides, and the
 * decision feeds `BizCity_LLM_Client`, which already branches on get_mode().
 *
 * Four branches (doc 0.60C §4), kept as a contract so the per-character
 * override can be switched on later without redesign:
 *   1. direct  + own key present → use override
 *   2. direct  + own key missing → IGNORE override, fall through to (4), badge "thiếu khóa"
 *   3. gateway                  → force 1API regardless of site mode
 *   4. inherit (DEFAULT)        → site mode (default gateway = 1API)
 *
 * Invariant pinned by tests: a gateway key is never sent to an external base
 * URL and vice versa — (2) is the branch that guarantees it.
 *
 * @package BizCity_Twin_AI
 * @subpackage Channel_Gateway\Bot
 * @since PHASE-0.60C (2026-09-23)
 */

// [2026-09-23 03:15 PM Claude Fable 5.1] PHASE-0.60C D1.3/D1.4/D1.6 — site-level dual choice, no new option, no new HTTP client.
defined( 'ABSPATH' ) || exit;

final class BizCity_Bot_Provider {

	const MODE_INHERIT = 'inherit';
	const MODE_GATEWAY = 'gateway';

	/**
	 * Services the bot may want that 1API does not offer today.
	 * [2026-10-14 12:28 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.107 S107-X2 — tts / stt / create_music now go through the Hub, so no gap remains.
	 */
	const GATEWAY_GAPS = array();

	/**
	 * Resolve the effective source for one turn.
	 *
	 * [2026-10-14 12:28 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.107 S107-X2 R-ONE-VERTICAL-CELL — there is no direct mode: the source is
	 * always the Hub gateway. A stored `direct` (site option bizcity_llm_mode or a per-character provider_mode) is ignored, never honoured.
	 *
	 * @param array $override  { provider_mode? } — kept for call compatibility; any value resolves to the gateway.
	 * @param string $site_mode ignored (kept for call compatibility).
	 * @return array{mode:string,source:string,branch:int,override_ignored:bool,badge:string}
	 */
	public static function effective( array $override = array(), string $site_mode = '' ): array {
		$mode    = isset( $override['provider_mode'] ) ? sanitize_key( (string) $override['provider_mode'] ) : self::MODE_INHERIT;
		$ignored = ! in_array( $mode, array( self::MODE_INHERIT, self::MODE_GATEWAY, '' ), true );
		if ( self::MODE_GATEWAY === $mode ) {
			return array( 'mode' => self::MODE_GATEWAY, 'source' => 'character', 'branch' => 3, 'override_ignored' => false, 'badge' => '' );
		}
		return array( 'mode' => self::MODE_GATEWAY, 'source' => 'site', 'branch' => 4, 'override_ignored' => $ignored, 'badge' => '' );
	}

	/**
	 * End-anchored host match (D1.6): "api.googleapis.com.evil.example" must NOT match "googleapis.com".
	 */
	public static function host_matches( string $url, string $allowed_host ): bool {
		$host = strtolower( (string) parse_url( $url, PHP_URL_HOST ) );
		$allowed_host = strtolower( trim( $allowed_host ) );
		if ( $host === '' || $allowed_host === '' ) {
			return false;
		}
		if ( $host === $allowed_host ) {
			return true;
		}
		$suffix = '.' . $allowed_host;
		return substr( $host, -strlen( $suffix ) ) === $suffix;
	}

	/** Which requested capabilities are not available on the gateway (1API) today. */
	public static function gateway_gaps( array $requested_tools ): array {
		return array_values( array_intersect( array_map( 'strval', $requested_tools ), self::GATEWAY_GAPS ) );
	}

	/**
	 * Read-only status for the UI cards (doc 0.60C §6). Never returns a key.
	 */
	public static function site_status(): array {
		$mode = self::effective();
		$has_key = false;
		$gateway_host = '';
		if ( class_exists( 'BizCity_LLM_Client' ) ) {
			try {
				$client  = BizCity_LLM_Client::instance();
				$has_key = trim( (string) $client->get_api_key() ) !== '';
				$gateway_host = (string) parse_url( (string) $client->get_gateway_url(), PHP_URL_HOST );
			} catch ( \Throwable $e ) {
				$has_key = false;
			}
		}
		return array(
			'site_mode'      => $mode['mode'],
			'branch'         => $mode['branch'],
			'has_key'        => $has_key,
			'gateway_host'   => $gateway_host,
			'gateway_gaps'   => self::GATEWAY_GAPS,
			'override_level' => 'site', // MVP — per-character override is post-MVP (doc 0.60C §2.3).
			'settings_hint'  => 'Đổi nguồn AI ở trang Cài đặt BizCity LLM của site (R-1API-9); Guru không giữ khóa riêng.',
		);
	}
}
