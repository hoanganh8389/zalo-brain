<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Channel_Gateway
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 */

/**
 * Legacy outbound send primitives — the four functions the rest of the stack
 * still calls after core/helper-legacy was archived (CORE-REDUCTION WP-12 R4).
 *
 *   biz_send_message()          — alias of twf_telegram_send_message()
 *   twf_telegram_send_message() — universal "send text to chat_id" entry; Zalo Bot,
 *                                  WEBCHAT and ADMINCHAT are routed by the
 *                                  `twf_send_message_override` filter (bootstrap.php
 *                                  priority 7, bizcity-zalo-bot priority 7)
 *   twf_telegram_send_photo()   — same for images
 *   bizgpt_zalo_format()        — strip Markdown/LaTeX for Zalo plain text
 *
 * Callers: Gateway Sender, knowledge notifications, bundled bizcity-zalo-bot /
 * bizcity-facebook-bot, the bizcity-web mu-plugin (unguarded call on site creation).
 * Bodies came from core/helper-legacy/flows/legacy_functions.php. Since WP-12 R8/R9
 * (2026-09-27) there is no Hotline branch and no Telegram admin-bot delivery: the
 * shims deliver only through the override filters and return false otherwise.
 *
 * Loaded by bizcity-twin-ai.php under the same gate helper-legacy had.
 */

defined( 'ABSPATH' ) || exit;

// [2026-09-26 Claude Opus 5.5] CORE-REDUCTION WP-12 R4 — per-request batch of "sent" messages; bizcity-zalo-bizcity resets and reads it.
if ( ! isset( $GLOBALS['twf_chat_msg_batch'] ) || ! is_array( $GLOBALS['twf_chat_msg_batch'] ) ) {
	$GLOBALS['twf_chat_msg_batch'] = [];
}

if ( ! function_exists( 'biz_send_message' ) ) {
	function biz_send_message( $chat_id, $text, $parse_mode = 'HTML', $reply_markup = null ) {
		return twf_telegram_send_message( $chat_id, $text, $parse_mode, $reply_markup );
	}
}

if ( ! function_exists( 'twf_telegram_send_message' ) ) {
	function twf_telegram_send_message( $chat_id, $text, $parse_mode = 'HTML', $reply_markup = null ) {
		if ( function_exists( 'bizgpt_log_chat_message' ) ) {
			bizgpt_log_chat_message( $chat_id, $text, 'bot', '', 'telegram' );
		}
		// [2026-09-27 Claude Opus 5.5] CORE-REDUCTION WP-12 R8/R9 — the Zalo Hotline branch (twf_check_client_use_zalo) and the
		// Telegram admin-bot delivery (api.telegram.org with the global twf_bot_token) are retired (R-ONE-AXIS D-29, D-30).
		// Delivery now happens only through the override filter (Channel Gateway: WEBCHAT / ADMINCHAT / ZALO_BOT;
		// bizcity-zalo-bot). A chat_id nobody routes returns false instead of reaching Telegram.
		$override = apply_filters( 'twf_send_message_override', false, $chat_id, $text, $parse_mode, $reply_markup );
		if ( $override !== false ) {
			return $override;
		}

		$GLOBALS['twf_chat_msg_batch'][] = [
			'chat_id' => $chat_id,
			'msg'     => $text,
		];

		// Lets other flows read the reply text.
		apply_filters( 'twf_telegram_send_message_response', $text, $chat_id );

		return false;
	}
}

if ( ! function_exists( 'bizgpt_zalo_format' ) ) {
	function bizgpt_zalo_format( $text ) {
		// 1. Remove LaTeX \[ \] and \( \)
		$text = preg_replace( [ '/\\\\\[/', '/\\\\\]/', '/\\\\\(/', '/\\\\\)/' ], '', $text );
		// 2. Remove Markdown headings ## ###
		$text = preg_replace( '/^#{1,6}\s*/m', '', $text );
		// 3. Drop **bold** and __bold__
		$text = str_replace( [ '**', '__' ], '', $text );
		// 4. \text{...} => ...
		$text = preg_replace( '/\\\\text\{(.*?)\}/', '$1', $text );
		// 5. \left( and \right) => ( )
		$text = str_replace( [ '\\left', '\\right' ], '', $text );
		// 6. \times => *
		$text = str_replace( '\\times', '*', $text );
		// 7. \sqrt{X} => √(X)
		$text = preg_replace( '/\\\\sqrt\{(.*?)\}/', '√($1)', $text );
		// 8. Remove stray `\\`
		$text = str_replace( '\\\\', '', $text );
		// 9. Collapse blank lines
		$text = preg_replace( "/\n{2,}/", "\n", $text );

		return trim( $text );
	}
}

if ( ! function_exists( 'twf_telegram_send_photo' ) ) {
	function twf_telegram_send_photo( $chat_id, $photo_url, $caption = '', $extra = array() ) {
		if ( function_exists( 'bizgpt_log_chat_message' ) ) {
			$msg = '<img src="' . $photo_url . '" class="bizgpt-photo-msg" style="max-width:220px;max-height:160px;border-radius:8px;display:block;margin:6px auto;">';
			if ( $caption ) {
				$msg .= '<div style="margin-top:4px">' . esc_html( $caption ) . '</div>';
			}
			$GLOBALS['twf_chat_msg_batch'][] = [
				'chat_id' => $chat_id,
				'msg'     => $msg,
			];
			bizgpt_log_chat_message( $chat_id, $msg, 'bot', '', 'telegram' );
		}
		// [2026-09-27 Claude Opus 5.5] CORE-REDUCTION WP-12 R8/R9 — Hotline branch and Telegram admin-bot sendPhoto retired
		// (R-ONE-AXIS D-29, D-30). Only the override filter delivers (bizcity-zalo-bot); otherwise false.
		$override = apply_filters( 'twf_telegram_send_photo_override', false, $chat_id, $photo_url, $caption, $extra );
		if ( $override !== false ) {
			return $override;
		}

		// Lets web chat pick up the image.
		do_action( 'twf_telegram_send_photo_response', $photo_url, $caption, $chat_id );

		return false;
	}
}
