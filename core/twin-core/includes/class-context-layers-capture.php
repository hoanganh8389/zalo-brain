<?php
/**
 * BizCity Context Layers Capture — Observability Snapshot
 *
 * Captures all context layers injected into a system prompt for
 * debug/trace purposes. In-request only (no longer persisted to a session or a table: S116-X9).
 *
 * @since   Phase 1.6 v1.0
 * @package BizCity_Twin_AI
 * @see     PHASE-1.6-MEMORY-SPEC-ARCHITECTURE.md §14
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BizCity_Context_Layers_Capture {

	const LOG = '[CtxCapture]';

	/** @var array Current request snapshot */
	private static $snapshot = array();

	/** @var bool Whether capture is active this request */
	private static $active = false;

	/* ──────────────────────────────────────────────
	 *  A. start / stop — bật/tắt capture
	 * ────────────────────────────────────────────── */

	/**
	 * Start capturing context layers for this request.
	 */
	public static function start() {
		self::$active   = true;
		self::$snapshot = array(
			'timestamp'  => current_time( 'mysql', true ),
			'layers'     => array(),
			'total_tokens_est' => 0,
		);
	}

	/**
	 * Stop capturing.
	 */
	public static function stop() {
		self::$active = false;
	}

	/**
	 * Check if capture is active.
	 *
	 * @return bool
	 */
	public static function is_active() {
		return self::$active;
	}

	/* ──────────────────────────────────────────────
	 *  B. record — ghi nhận 1 layer
	 * ────────────────────────────────────────────── */

	/**
	 * Record a context layer being injected.
	 *
	 * @param string $name     Layer name (e.g. 'profile', 'transit', 'session_spec', 'knowledge').
	 * @param string $content  The text content injected.
	 * @param array  $meta     Optional metadata { priority, source, gated_by }.
	 */
	public static function record( $name, $content, $meta = array() ) {
		if ( ! self::$active ) {
			return;
		}

		$char_count = mb_strlen( $content );
		// Rough token estimate: 1 token ≈ 4 chars for Vietnamese
		$token_est = (int) ceil( $char_count / 4 );

		self::$snapshot['layers'][] = array(
			'name'       => $name,
			'chars'      => $char_count,
			'tokens_est' => $token_est,
			'priority'   => isset( $meta['priority'] ) ? (int) $meta['priority'] : 0,
			'source'     => isset( $meta['source'] ) ? $meta['source'] : '',
			'gated'      => isset( $meta['gated_by'] ) ? $meta['gated_by'] : '',
			'preview'    => mb_substr( $content, 0, 200 ),
			'content'    => $content, // Full content for click-to-detail dialog
		);

		self::$snapshot['total_tokens_est'] += $token_est;
	}

	/* ──────────────────────────────────────────────
	 *  C. snapshot — lấy toàn bộ capture
	 * ────────────────────────────────────────────── */

	/**
	 * Get the current snapshot.
	 *
	 * @return array
	 */
	public static function snapshot() {
		return self::$snapshot;
	}

	/**
	 * Get latest snapshot (alias for snapshot()).
	 *
	 * @return array
	 */
	public static function get_latest() {
		return self::$snapshot;
	}

	/* ──────────────────────────────────────────────
	 *  D. persist — lưu vào encrypted session-state filestore
	 * ────────────────────────────────────────────── */

	// [2026-10-17 10:37 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.116 S116-X9 — persist_to_session() (WebChat session state) and persist_to_task() (writes bizcity_tasks of the old WAIC engine) are REMOVED: the framework
	// declares and connects, the cell processes; WebChat and both old engines are gone (doc 95 / 96). The snapshot now lives only for the current request.
	// If observability is needed again, write to the twin event stream instead — do not bring a table write back here.

	/* ──────────────────────────────────────────────
	 *  E. Filter hook — capture bizcity_system_prompt_built
	 * ────────────────────────────────────────────── */

	/**
	 * Hook handler for `bizcity_system_prompt_built`.
	 * Finalizes capture by persisting to session.
	 *
	 * @param string $prompt     Final system prompt.
	 * @param array  $args       Filter arguments.
	 * @param array  $bundle     Prompt bundle.
	 */
	public static function on_prompt_built( $prompt, $args, $bundle = array() ) {
		if ( ! self::$active ) {
			return;
		}

		// [2026-10-17 10:37 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.116 S116-X9: nothing is persisted any more; just end this request's capture
		self::stop();
	}

	/* ──────────────────────────────────────────────
	 *  F. 100% Prompt Capture — universal hooks
	 *     Covers ALL apply_filters paths, not just twin_resolver
	 * ────────────────────────────────────────────── */

	/**
	 * Ensure capture is started before any filter runs.
	 *
	 * Hooked to `bizcity_chat_system_prompt` at priority 0 (before all layers).
	 * If twin_resolver already started capture, this is a no-op.
	 *
	 * @param string $prompt Current system prompt.
	 * @param array  $args   Filter arguments.
	 * @return string Unchanged prompt.
	 */
	public static function ensure_started( $prompt, $args = array() ) {
		if ( ! class_exists( 'BizCity_Session_Memory_Spec' )
			|| ! BizCity_Session_Memory_Spec::is_enabled()
		) {
			return $prompt;
		}

		if ( ! self::$active ) {
			self::start();

			// Record the base prompt as 'system_base' layer
			if ( ! empty( $prompt ) ) {
				self::record( 'system_base', $prompt, array(
					'priority' => 0,
					'source'   => 'ensure_started',
				) );
			}
		}

		// Store the pre-filter prompt length for delta calculation
		// §20 L2 fix: Only set when WE started capture (not twin_resolver)
		if ( self::$pre_filter_len === 0 ) {
			self::$pre_filter_len = mb_strlen( $prompt );
		}

		return $prompt;
	}

	/** @var int Pre-filter prompt length for delta tracking */
	private static $pre_filter_len = 0;

	/**
	 * Capture final prompt after all filters have run.
	 *
	 * Hooked to `bizcity_chat_system_prompt` at priority 99 (after all layers).
	 * Records a 'final_prompt' snapshot with total stats.
	 *
	 * @param string $prompt Final system prompt.
	 * @param array  $args   Filter arguments.
	 * @return string Unchanged prompt.
	 */
	public static function capture_final_prompt( $prompt, $args = array() ) {
		if ( ! self::$active ) {
			return $prompt;
		}

		$final_len = mb_strlen( $prompt );
		$delta     = $final_len - self::$pre_filter_len;

		self::$snapshot['final_prompt_chars']      = $final_len;
		self::$snapshot['final_prompt_tokens_est'] = (int) ceil( $final_len / 4 );
		self::$snapshot['filter_delta_chars']       = $delta;
		self::$snapshot['session_id']               = isset( $args['session_id'] ) ? $args['session_id'] : '';

		// [2026-10-17 10:37 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.116 S116-X9: the safety-persist before the LLM call is removed with persist_to_session()
		return $prompt;
	}

	/**
	 * Persist capture snapshot when message is processed.
	 *
	 * Hooked to `bizcity_chat_message_processed` at priority 15.
	 * Covers ALL paths (chat-gateway, intent-stream, twin-resolver).
	 *
	 * @param array $data Message processed data.
	 */
	public static function persist_on_message( $data ) {
		unset( $data );
		// [2026-10-17 10:37 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.116 S116-X9: no persistence any more — only reset the per-request state for the next request
		self::stop();
		self::$snapshot       = array();
		self::$pre_filter_len = 0;
	}

	/**
	 * Reset for testing.
	 */
	public static function reset() {
		self::$snapshot       = array();
		self::$active         = false;
		self::$pre_filter_len = 0;
	}
}
