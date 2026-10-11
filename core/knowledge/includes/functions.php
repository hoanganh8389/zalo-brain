<?php
/**
 * Bizcity Twin AI — Nền tảng AI Companion cá nhân hóa
 * Bizcity Twin AI — Personalized AI Companion Platform
 *
 * Helper Functions for Knowledge Module
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Knowledge
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 */

defined('ABSPATH') or die('OOPS...');

/**
 * [2026-10-10 11:39 PM Johnny Chu - Chu Hoàng Anh] CORE-REDUCTION WP-20 Z-8b — bizcity_get_character(),
 * bizcity_get_default_character(), bizcity_add_knowledge(), bizcity_search_knowledge(), bizcity_get_quick_faq_posts(),
 * bizcity_parse_intent(), bizcity_knowledge_process_webchat() and the commented-out bizcity_knowledge_chat() moved to
 * core/_archived/z8b-20261010/knowledge/includes/functions.dead-helpers.php: no caller in any plugin, mu-plugin or
 * theme (comment-stripped census), and bizcity_parse_intent() always returned empty since Z-3b retired the parser.
 * bizcity_query_character() stays (plugins/bizcity-facebook-bot/lib/legacy-functions.php calls it).
 */
/**
 * Query a character with a message
 */
function bizcity_query_character($character_id, $query, $context = []) {
    return BizCity_Character::query($character_id, $query, $context);
}

/**
 * [2026-10-01 Claude Sonnet 5] CORE-REDUCTION WP-17 K-1 (R-LEAN-4) — bizgpt_chatbot_fallback_ai_response(),
 * bizgpt_chatbot_run_guest_flows(), bizgpt_inbox_assign_flow_id() and bizgpt_return_or_ajax() moved to their
 * real owner, plugins/bizcity-facebook-bot/lib/legacy-functions.php (only that plugin called
 * bizgpt_chatbot_run_guest_flows; the other three were only called from inside it; one of them wrote the
 * Facebook bot's own bizcity_facebook_inbox table).
 */

/**
 * [2026-10-11 12:23 AM Johnny Chu - Chu Hoàng Anh] CORE-REDUCTION WP-20 Z-8c — the [bizcity_character_chat] shortcode body (already rendering nothing since
 * WP-10 A3) moved to core/_archived/z8c-20261011/core/knowledge/includes/functions.character-chat.php. The tag stays
 * registered as retired ('' output) in core/channel-gateway/legacy/class-retired-shortcodes.php so old pages do not print it.
 */
