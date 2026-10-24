<?php
/**
 * BizCity TwinBrain REST Controller.
 *
 * Routes (namespace bizcity-twinbrain/v1):
 *   POST /turn            — start a brain turn (Stage 1 + 2 + 4 sync wave 0)
 *   POST /tool/confirm    — Stage 3 user confirmation for tool suggestion
 *   GET  /turn/(?P<trace_id>[\w\-]+) — read replay (delegates to view)
 *
 * SSE re-uses the canonical /wp-json/bizcity-twin/v1/stream channel (R-EVT-4).
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Modules\TwinBrain
 * @since      2026-05-10
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

// PHASE-0.41 L3 — trait is required for the `use` below; load defensively so
// this file works even if core/diagnostics bootstrap hasn't fired yet.
if ( ! trait_exists( 'BizCity_REST_Error' ) ) {
	// [2026-10-12 10:47 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.102 ST102-L32 — plugin root from this file's location, not the folder name (fork / renamed folder safe).
	$__trait = ( defined( 'BIZCITY_TWIN_AI_DIR' ) ? BIZCITY_TWIN_AI_DIR : dirname( __DIR__, 3 ) . '/' ) . 'core/helper/includes/trait-rest-error.php';
	if ( file_exists( $__trait ) ) {
		require_once $__trait;
	}
}

class BizCity_TwinBrain_REST {

	// Unified WP_Error builder (status/fix/ctx payload + telemetry recording).
	use BizCity_REST_Error;

	private static $instance = null;

	/** Why the last Ask Brain turn stayed on the legacy pipeline (twin-stream@1 `path` frame, S89-S3). */
	private $legacy_reason = 'flag_php';

	public static function instance(): self {
		if ( null === self::$instance ) self::$instance = new self();
		return self::$instance;
	}

	protected function rest_error_module(): string {
		return 'twinbrain.rest';
	}

	public function list_verticals( WP_REST_Request $req ) {
		// [2026-08-16 Johnny Chu] CCG-4 — registry is catalog metadata; policy/entitlement remains enforced at runtime.
		$items = class_exists( 'BizCity_TwinBrain_Vertical_Bridge_Registry' )
			? BizCity_TwinBrain_Vertical_Bridge_Registry::all()
			: array();
		return rest_ensure_response( array( 'ok' => true, 'scope' => 'vertical_plugin', 'items' => array_values( $items ) ) );
	}

	public function register_routes(): void {
		// [2026-08-16 Johnny Chu] CCG-4 — shared / Vertical Plugin catalog for TwinChat/TwinWeb.
		register_rest_route( BIZCITY_TWINBRAIN_REST_NS, '/verticals', [
			'methods'             => 'GET',
			'permission_callback' => [ $this, 'perm_logged_in' ],
			'callback'            => [ $this, 'list_verticals' ],
		] );
		register_rest_route( BIZCITY_TWINBRAIN_REST_NS, '/turn', [
			'methods'             => 'POST',
			'permission_callback' => [ $this, 'perm_logged_in' ],
			'args'                => [
				'prompt'           => [ 'type' => 'string', 'required' => true ],
				'k'                => [ 'type' => 'integer', 'required' => false ],
				'force_notebooks'  => [ 'type' => 'array',   'required' => false ],
				'focus_notebook_id' => [ 'type' => 'integer', 'required' => false, 'default' => 0 ],
				'force_tools'      => [ 'type' => 'array',   'required' => false ],
				'skip_tool_intent' => [ 'type' => 'boolean', 'required' => false ],
				'auto_complete'    => [ 'type' => 'boolean', 'required' => false, 'default' => true ],
				// [2026-08-07 Johnny Chu] V4-DEPTH — expose the MPR reasoning tier; absent means canonical high.
				'answer_depth'     => [ 'type' => 'string', 'required' => false, 'enum' => [ 'fast', 'balanced', 'high', 'deep' ], 'default' => 'high' ],
				// [2026-08-11 Johnny Chu] PHASE-TWB-WOO-BIZOPS — keep synchronous REST mode parity with stream.
				'web_mode'         => [ 'type' => 'string', 'required' => false ], // [2026-10-07 10:05 Johnny Chu - Chu Hoàng Anh] W-2 — no static enum: sanitize_web_mode() accepts what the loaded verticals register.
				// [2026-06-03 Johnny Chu] BRAIN-SESSIONS BS-2 — thread session_id.
				'session_id'       => [ 'type' => 'string',  'required' => false ],
			],
			'callback'            => [ $this, 'handle_turn' ],
		] );

		register_rest_route( BIZCITY_TWINBRAIN_REST_NS, '/turn/stream', [
			'methods'             => 'POST',
			'permission_callback' => [ $this, 'perm_logged_in' ],
			'args'                => [
				'prompt'           => [ 'type' => 'string', 'required' => true ],
				'k'                => [ 'type' => 'integer', 'required' => false ],
				'force_notebooks'  => [ 'type' => 'array',   'required' => false ],
				'focus_notebook_id' => [ 'type' => 'integer', 'required' => false, 'default' => 0 ],
				'force_tools'      => [ 'type' => 'array',   'required' => false ],
				'skip_tool_intent' => [ 'type' => 'boolean', 'required' => false ],
				// TBR.W9 (2026-05-21) + TBR.W14/W15 (2026-05-22) + TBR.W17 (2026-05-27/28)
				// — Web Research toggle. Values: 'off' (default), 'quick' (W6),
				// 'deep' (W7), 'social' (W14), 'company' (W15), 'med' (W17), 'scholar'
				// (W17), 'nutri' (W17), 'law' (W17), 'tax' (W17), 'gov' (W17),
				// 'products' (PHASE-TWB-PRODUCTS).
				// [2026-08-03 Johnny Chu] R-TGL-CS — public Brain Chat uses `off`
				// and full MPR; internal casual optimization uses companion_mode.
				// [2026-06-04 Johnny Chu] PHASE-A C.3b — 'astro' mode: bypass MPR,
				// inject transit passages qua CAP filter, compose final với astro context.
				// [2026-07-15 Johnny Chu] PHASE-TWB-PRODUCTS — add products mode enum.
				'web_mode'         => [ 'type' => 'string',  'required' => false ], // [2026-10-07 10:05 Johnny Chu - Chu Hoàng Anh] W-2 — no static enum: sanitize_web_mode() accepts what the loaded verticals register.
				// TBR.W20 (2026-05-28) — Agent mode toggle. 'brain' (default) =
				// full MPR pipeline (perspectives + synthesis); 'agent' = bypass
				// perspectives, run ReAct loop over Tool_Registry instead.
				'mode'             => [ 'type' => 'string',  'required' => false, 'enum' => [ 'brain', 'agent' ] ],
				// [2026-08-07 Johnny Chu] V4-DEPTH — same contract for streaming turns.
				'answer_depth'     => [ 'type' => 'string', 'required' => false, 'enum' => [ 'fast', 'balanced', 'high', 'deep' ], 'default' => 'high' ],
				// [2026-06-03 Johnny Chu] BRAIN-SESSIONS BS-2 — thread session_id.
				// Auto-mint on first turn when omitted; FE picks up session_id from
				// the streamed `started` SSE frame.
				'session_id'       => [ 'type' => 'string',  'required' => false ],
				// [2026-06-19 Johnny Chu] PHASE-TWB-WORKFLOW W1 — /skill param routes to Workflow Pipeline.
				'skill'            => [ 'type' => 'string',  'required' => false, 'default' => '' ],
			],
			'callback'            => [ $this, 'handle_turn_stream' ],
		] );

		// [2026-10-12 10:05 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.105 S105-C1/C9 — Cell Chat: the numbers this user owns / staffs, and the shared thread.
		register_rest_route( BIZCITY_TWINBRAIN_REST_NS, '/cell-chat/numbers', [
			'methods'             => 'GET',
			'permission_callback' => [ $this, 'perm_logged_in' ],
			'callback'            => [ $this, 'handle_cell_chat_numbers' ],
		] );
		register_rest_route( BIZCITY_TWINBRAIN_REST_NS, '/cell-chat/history', [
			'methods'             => 'GET',
			'permission_callback' => [ $this, 'perm_logged_in' ],
			'args'                => [
				'account_id' => [ 'type' => 'string', 'required' => false ],
				'limit'      => [ 'type' => 'integer', 'required' => false, 'default' => 30 ],
			],
			'callback'            => [ $this, 'handle_cell_chat_history' ],
		] );
		// [2026-10-13 01:10 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.105 v2 S105-M3 — file by link. Used in <img>/<audio>/<a href>: the cookie nonce rides as `_wpnonce`
		// in the query, which WP core's rest_cookie_check_errors() reads from $_REQUEST (auth is NOT weakened: no nonce ⇒ the user is anonymous ⇒ perm_logged_in fails).
		register_rest_route( BIZCITY_TWINBRAIN_REST_NS, '/cell-chat/media/(?P<media_id>cm_[a-f0-9]{24})', [
			'methods'             => 'GET',
			'permission_callback' => [ $this, 'perm_logged_in' ],
			'args'                => [
				'account_id' => [ 'type' => 'string', 'required' => false ],
			],
			'callback'            => [ $this, 'handle_cell_chat_media' ],
		] );
		// [2026-10-13 01:20 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.106 S106-C6 — "Tệp đã tạo": files cell turns made for this user (Canvas + Files tabs)
		register_rest_route( BIZCITY_TWINBRAIN_REST_NS, '/outputs', [
			'methods'             => 'GET',
			'permission_callback' => [ $this, 'perm_logged_in' ],
			'args'                => [
				'session_id' => [ 'type' => 'string', 'required' => false ],
				'kind'       => [ 'type' => 'string', 'required' => false ],
				'page'       => [ 'type' => 'integer', 'required' => false, 'default' => 1 ],
			],
			'callback'            => [ $this, 'handle_cell_outputs' ],
		] );

		register_rest_route( BIZCITY_TWINBRAIN_REST_NS, '/tool/confirm', [
			'methods'             => 'POST',
			'permission_callback' => [ $this, 'perm_logged_in' ],
			'args'                => [
				'trace_id'   => [ 'type' => 'string', 'required' => true ],
				'skill_slug' => [ 'type' => 'string', 'required' => true ],
				'args'       => [ 'type' => 'object', 'required' => false ],
			],
			'callback'            => [ $this, 'handle_tool_confirm' ],
		] );

		// [2026-10-06 23:24 Johnny Chu - Chu Hoàng Anh] TWINBRAIN-SPLIT-CORE-ADDON Q-1 — /hil/compile moved with the HIL classes to
		// bizcity-twin-brain-addon/hil/ (BizCity_TwinBrain_HIL_REST); same namespace and route.

		register_rest_route( BIZCITY_TWINBRAIN_REST_NS, '/turn/(?P<trace_id>[\w\-]+)', [
			'methods'             => 'GET',
			'permission_callback' => [ $this, 'perm_logged_in' ],
			'callback'            => [ $this, 'handle_replay' ],
		] );
	}

	public function perm_logged_in() {
		return is_user_logged_in();
	}

	/**
	 * TBR.W9 — Normalize `web_mode` request param. Returns 'off' for any
	 * value not in the allowed enum so the Stage 2.5 dispatcher can fall
	 * back to a no-op when the FE composer is in default state.
	 */
	private function sanitize_web_mode( $raw ): string {
		$v = strtolower( trim( (string) $raw ) );
		// [2026-08-03 Johnny Chu] R-TGL-CS — legacy 'chat' requests normalize to
		// off so Brain Chat uses the canonical full-MPR path.
		// [2026-06-04 Johnny Chu] PHASE-A C.3b — accept 'astro' (transit mode).
		// [2026-07-15 Johnny Chu] PHASE-TWB-PRODUCTS — accept 'products' vertical mode.
		// MISSING from whitelist trước đây → astro request bị fallback 'off' →
		// chạy full MPR pipeline (notebook perspectives) thay vì stream_astro_mode.
		// [2026-08-11 Johnny Chu] PHASE-TWB-WOO-BIZOPS — accept mode; resolver enforces admin capability before data access.
		// [2026-10-07 10:05 Johnny Chu - Chu Hoàng Anh] TWINBRAIN-SPLIT-CORE-ADDON W-2 — modes come from the add-on; without it every vertical mode is 'off'.
		// [2026-10-24 Johnny Chu - Chu Hoàng Anh] PHASE-0.126 R126-B2 — Runtime is the brain add-on's (twinbrain-mpr/): without it no vertical mode exists.
		$modes = class_exists( 'BizCity_TwinBrain_Runtime' ) ? BizCity_TwinBrain_Runtime::vertical_modes() : array();
		return in_array( $v, $modes, true ) ? $v : 'off';
	}

	/**
	 * TBR.W20 — Normalize `mode` request param. Default 'brain' (full MPR
	 * pipeline). 'agent' = bypass perspectives, run ReAct loop over
	 * Tool_Registry. Unknown value → 'brain'.
	 */
	private function sanitize_mode( $raw ): string {
		$v = strtolower( trim( (string) $raw ) );
		return $v === 'agent' ? 'agent' : 'brain';
	}

	/**
	 * [2026-08-07 Johnny Chu] V4-DEPTH — normalize the user-selected MPR tier
	 * at the REST boundary; unknown values fail closed to canonical high.
	 */
	private function sanitize_answer_depth( $raw ): string {
		$value = strtolower( trim( (string) $raw ) );
		return in_array( $value, array( 'fast', 'balanced', 'high', 'deep' ), true ) ? $value : 'high';
	}

	/**
	 * PHASE-0.57A/T5 — apply the site default notebook only to /twin/ Brain
	 * requests. The explicit focus/force list always wins and /gpt/ does not
	 * call this helper.
	 */
	private function apply_default_notebook( array &$opts, int $user_id, string $surface ): void {
		if ( $surface !== 'twin' || ! empty( $opts['force_notebooks'] ) || ! class_exists( 'BizCity_KG_Access' ) ) {
			return;
		}
		$default_id = (int) get_option( BizCity_KG_Access::OPTION_DEFAULT_NOTEBOOK, 0 );
		if ( $default_id > 0 && BizCity_KG_Access::can_read_notebook( $default_id, $user_id, $surface ) ) {
			$opts['force_notebooks'] = array( $default_id );
		}
	}

	/**
	 * [2026-06-03 Johnny Chu] BRAIN-SESSIONS BS-2 — Resolve session_id from
	 * the request, validating ownership when supplied. Mints a fresh session
	 * (with brain_session_created event) when missing or invalid. Returns
	 * the canonical session_id to thread into runtime opts.
	 */
	private function resolve_session_id( WP_REST_Request $req, int $user_id ): string {
		if ( ! class_exists( 'BizCity_TwinBrain_Sessions_Manager' ) ) {
			return '';
		}
		$mgr = BizCity_TwinBrain_Sessions_Manager::instance();
		$raw = trim( (string) $req->get_param( 'session_id' ) );
		if ( $raw !== '' && BizCity_TwinBrain_Sessions_Manager::is_valid_session_id( $raw ) ) {
			$existing = $mgr->get( $raw, $user_id );
			if ( ! empty( $existing ) ) {
				return $raw;
			}
			// Caller passed a fresh id we haven't seen — honour it and mint.
			$res = $mgr->create( [ 'user_id' => $user_id, 'session_id' => $raw, 'source' => 'user' ] );
			return (string) ( $res['session_id'] ?? $raw );
		}
		$res = $mgr->create( [ 'user_id' => $user_id, 'source' => 'user' ] );
		return (string) ( $res['session_id'] ?? '' );
	}

	/**
	 * [2026-10-03 Claude Opus 5.5] PHASE-0.89 S89-B7 (R-BIZ-CENTRAL-BRAIN, doc 43) — Ask Brain "Brain Chat" as an ADAPTER of the
	 * cell's runAgentTurn through BizCity_Twin_Web_Turn, under its OWN cut-over flag `bizcity_twin_web_turn_path_brain`
	 * (auto|node|php, default php until the owner turns it on). The notebook-search MPR pipeline below is the LEGACY path (the
	 * documented way back) and stays untouched.
	 *
	 * Only a plain Brain Chat turn goes to the cell: no vertical (`web_mode` ≠ off, nor a literal /slug), no Skill, no #workflow
	 * command. The user is get_current_user_id() (never a body field). The session transcript is written from the cell's
	 * `final_done` as the same user_message / assistant_message events the legacy runtime writes, so the Brain Sessions list and
	 * replay keep working. Returns 'php' | 'done' | WP_REST_Response.
	 *
	 * @return string|WP_REST_Response
	 */
	private function maybe_agent_turn( WP_REST_Request $req, string $prompt, int $focus_notebook_id ) {
		// [2026-10-22 07:20 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.126 Q126-19 (owner: "giữ MPR cho verticals + phân tích sâu, bỏ rơi về của Brain Chat") — a plain Brain Chat
		// turn is answered by the cell or by an R-ERROR-UX error, never by the MPR pipeline. MPR stays only for the vertical modes below and for the emergency
		// constant BIZCITY_TWIN_WEB_TURN_FORCE_PHP (flag_php); Zalo deep analysis and the vertical add-on call the runtime directly, not through here.
		if ( ! class_exists( 'BizCity_Twin_Web_Turn' ) ) {
			return new WP_REST_Response( [ 'ok' => false, 'code' => 'adapter_missing', 'message' => 'Bộ não chưa sẵn sàng trên website này.', 'hint' => 'Cập nhật plugin Zalo Brain rồi thử lại.', 'help_code' => 'S126-BRAIN-ADAPTER-MISSING' ], 503 );
		}
		// [2026-10-22 09:45 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.126 Q126-19 (owner: "đóng cả 4, để qua cell hết") — a vertical (web_mode ≠ off) and a literal /slug of one go to the cell as `vertical` (its own recipes); an unknown mode is plain chat.
		// verticals_enabled() is false only under the emergency constant: then the old vertical MPR answers.
		$vertical = $this->sanitize_web_mode( $req->get_param( 'web_mode' ) );
		if ( 'off' !== $vertical && ! BizCity_Twin_Web_Turn::verticals_enabled() ) {
			$this->legacy_reason = 'vertical';
			return 'php';
		}
		$vertical = 'off' !== $vertical && BizCity_Twin_Web_Turn::is_vertical( $vertical ) ? $vertical : 'off';
		// [2026-10-07 10:05 Johnny Chu - Chu Hoàng Anh] TWINBRAIN-SPLIT-CORE-ADDON Q-2 — `skill` no longer pins the turn to PHP: the
		// /skill Workflow Pipeline is archived, so such requests follow the normal cell / PHP decision.
		if ( 'off' === $vertical && class_exists( 'BizCity_TwinBrain_Vertical_Bridge_Registry' ) ) {
			$cmd  = BizCity_TwinBrain_Vertical_Bridge_Registry::extract( $prompt );
			$slug = is_array( $cmd ) ? sanitize_key( (string) ( $cmd['slug'] ?? '' ) ) : '';
			if ( '' !== $slug && BizCity_Twin_Web_Turn::is_vertical( $slug ) ) {
				$vertical = $slug;
			}
		}
		$user_id  = get_current_user_id();
		$decision = BizCity_Twin_Web_Turn::decide( 'twinchat', $user_id, 'brain' );
		if ( 'php' === $decision['path'] && 'flag_php' === (string) $decision['reason'] ) {
			$this->legacy_reason = 'flag_php';
			return 'php'; // the emergency constant only (wp-config): the documented way back
		}
		if ( 'php' === $decision['path'] ) {
			return BizCity_Twin_Web_Turn::error( 'guest' === (string) $decision['reason'] ? 'login_required' : 'hub_unreachable' );
		}
		// owner/staff only (others keep MPR: on the cell they would have no notebook, no memory); focus needs the notebook mode
		$stay = method_exists( 'BizCity_Twin_Web_Turn', 'brain_stay_reason' ) ? BizCity_Twin_Web_Turn::brain_stay_reason( $decision, $focus_notebook_id ) : '';
		// [2026-10-08 Johnny Chu - Chu Hoàng Anh] CELL-FIRST E-3 L1-e — a focus without the notebook mode is answered with its error, not MPR.
		if ( '' !== $stay && method_exists( 'BizCity_Twin_Web_Turn', 'stay_is_error' ) && BizCity_Twin_Web_Turn::stay_is_error( $stay ) ) {
			return BizCity_Twin_Web_Turn::error( $stay );
		}
		if ( '' !== $stay ) {
			// not an owner / staff of a number: the cell would answer as a customer (no notebook, no memory) and MPR is gone for Brain Chat
			return BizCity_Twin_Web_Turn::error( 'not_owner' );
		}
		// decided node, but the Hub/cell could not take the turn before its first frame (auto ⇒ falls back below)
		$this->legacy_reason = 'cell_fallback';
		$session_id = $this->resolve_session_id( $req, $user_id );
		$history    = $this->agent_turn_history( $session_id, $user_id );
		$depth      = $this->sanitize_answer_depth( $req->get_param( 'answer_depth' ) );
		$persist    = static function ( array $done, string $trace_id ) use ( $session_id, $user_id, $prompt ): int {
			if ( '' === $session_id || ! class_exists( 'BizCity_Twin_Event_Bus' ) || ! method_exists( 'BizCity_Twin_Event_Bus', 'dispatch_v2' ) ) {
				return 0;
			}
			$tid    = (string) ( $done['trace_id'] ?? $trace_id );
			$ctx    = array( 'session_id' => $session_id, 'user_id' => $user_id, 'trace_id' => $tid, 'event_source' => 'system' );
			$answer = (string) ( $done['answer_md'] ?? '' );
			BizCity_Twin_Event_Bus::dispatch_v2( 'user_message', array( 'trace_id' => $tid, 'user_id' => $user_id, 'surface' => 'twinbrain', 'text' => $prompt, 'content' => $prompt, 'guru_id' => '' ), $ctx );
			BizCity_Twin_Event_Bus::dispatch_v2( 'assistant_message', array(
				'trace_id'           => $tid,
				'user_id'            => $user_id,
				'surface'            => 'twinbrain',
				'text'               => $answer,
				'content'            => $answer,
				'synthesis_metadata' => array(
					'model'       => (string) ( $done['model'] ?? '' ),
					'citations'   => array(),
					'path'        => 'cell',
					// PHASE-0.89 S89-Q6: the cell's "tra sâu" buttons stay with the answer in the Brain session
					'suggestions' => BizCity_Twin_Web_Turn::clean_suggestions( $done['suggestions'] ?? array() ),
				),
			), $ctx );
			return 0; // no assistant_persisted frame: Ask Brain does not pin rows
		};
		return BizCity_Twin_Web_Turn::run( 'twinchat', array(
			'user_id'     => $user_id,
			'flag_key'    => 'brain',
			'session_id'  => $session_id,
			'text'        => $prompt,
			'history'     => $history,
			'notebook_id' => $focus_notebook_id,
			'thread_key'  => 'brain:' . $user_id . ':' . $focus_notebook_id,
			'effort'      => $depth,
			'vertical'    => 'off' !== $vertical ? $vertical : '',
			'persist'     => $persist,
			'no_php'      => true, // PHASE-0.126 Q126-19: a cell that cannot take the turn answers with its error, never with MPR
			// the panel takes its session id from `started` and ends a turn on `complete` (the legacy runtime's own frames)
			'prelude'     => static function () use ( $session_id, $prompt ): array {
				return array( array( 'started', array( 'prompt' => $prompt, 'session_id' => $session_id, 'path' => 'cell' ) ) );
			},
			'after_done'  => static function ( array $done ): array {
				return array( array( 'complete', array( 'trace_id' => (string) ( $done['trace_id'] ?? '' ), 'path' => 'cell' ) ) );
			},
		) );
	}

	/**
	 * [2026-10-12 10:05 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.105 S105-C2/C10 (doc 70 §5) — `cell_chat=1`: the turn goes to the cell as a
	 * `mirror:"zalo_owner"` envelope (twin-agent-turn@1.5.0) and NEVER to the MPR pipeline (owner decision): any failure is an SSE `error`
	 * frame with the contract code. The cell owns the thread (memory) and sends no `history`.
	 * [2026-10-13 01:20 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.106 S106-C9 (D106-3) — the turn now goes THROUGH the event stream: `cell_chat_run`
	 * writes the question (`user_message`, the TwinChat session) before sending it; the answer arrives as the cell's turn-complete.
	 *
	 * @return bool true when the request was Cell Chat (the stream is closed; the caller must exit)
	 */
	private function maybe_cell_chat_turn( WP_REST_Request $req ): bool {
		$raw = $req->get_param( 'cell_chat' );
		if ( ! ( 1 === (int) $raw || true === $raw || 'true' === $raw ) ) {
			return false;
		}
		if ( ! class_exists( 'BizCity_Twin_Web_Turn' ) || ! method_exists( 'BizCity_Twin_Web_Turn', 'cell_chat_run' ) ) {
			echo "event: error\ndata: " . wp_json_encode( [ 'code' => 'hub_route_missing', 'message' => 'Cell Chat chưa sẵn sàng trên website này.', 'hint' => 'Cập nhật plugin rồi thử lại.', 'help_code' => 'S105-CC-SITE-ADAPTER', 'retryable' => false ] ) . "\n\nevent: end\ndata: {}\n\n"; // phpcs:ignore WordPress.Security.EscapeOutput
			return true;
		}
		$sid = trim( (string) $req->get_param( 'session_id' ) );
		BizCity_Twin_Web_Turn::cell_chat_run( [
			'user_id'         => get_current_user_id(), // never a body field
			'session_id'      => $sid,
			'text'            => trim( (string) $req->get_param( 'prompt' ) ),
			'account_id'      => self::cell_chat_account( $req->get_param( 'account_id' ) ),
			'mirror_simulate' => 1 === (int) $req->get_param( 'mirror_simulate' ) || true === $req->get_param( 'mirror_simulate' ),
			// [2026-10-13 01:20 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.106 S106-U4 — the quick-create button (validated again in the envelope)
			'prefer_tool'     => (string) $req->get_param( 'prefer_tool' ),
			'prefer_label'    => (string) $req->get_param( 'prefer_label' ),
		] );
		return true;
	}

	/** Digits of a number's account id; '' for the web home (`web-<key>` or empty) - the site derives its own web account. */
	private static function cell_chat_account( $raw ): string {
		$raw = trim( (string) $raw );
		return 0 === strpos( $raw, 'web-' ) ? '' : (string) preg_replace( '/[^0-9]/', '', $raw );
	}

	/** GET /cell-chat/numbers → {items:[{account_id,label,role,linked,cell_chat}], web_home, setup:{step1..step4}} (no phone, hash or UID). */
	public function handle_cell_chat_numbers( WP_REST_Request $req ) {
		unset( $req );
		if ( ! class_exists( 'BizCity_Twin_Web_Turn' ) || ! method_exists( 'BizCity_Twin_Web_Turn', 'owned_numbers' ) ) {
			return $this->err( 'twinbrain_cell_chat_unavailable', 'Cell Chat chưa sẵn sàng trên website này.', 503, [], null, true );
		}
		$uid = get_current_user_id();
		return rest_ensure_response( [
			'items'    => BizCity_Twin_Web_Turn::owned_numbers( $uid ),
			'web_home' => null !== BizCity_Twin_Web_Turn::web_home_member( $uid ),
			'setup'    => BizCity_Twin_Web_Turn::setup_flags(),
		] );
	}

	/** GET /cell-chat/history?account_id=&limit= → the caller's own shared thread via the Hub (user_hash is server-side only). */
	public function handle_cell_chat_history( WP_REST_Request $req ) {
		if ( ! class_exists( 'BizCity_Twin_Web_Turn' ) || ! method_exists( 'BizCity_Twin_Web_Turn', 'cell_chat_history' ) ) {
			return $this->err( 'twinbrain_cell_chat_unavailable', 'Cell Chat chưa sẵn sàng trên website này.', 503, [], null, true );
		}
		$r = BizCity_Twin_Web_Turn::cell_chat_history(
			get_current_user_id(),
			self::cell_chat_account( $req->get_param( 'account_id' ) ),
			(int) ( $req->get_param( 'limit' ) ?: 30 ),
			(string) $req->get_param( 'session_id' ) // PHASE-0.106 S106-U2
		);
		return new WP_REST_Response( $r['body'], $r['status'] );
	}

	/** GET /outputs?session_id=&kind=&page= → {items, total, page} of the caller's own cell outputs (PHASE-0.106 S106-C6). */
	public function handle_cell_outputs( WP_REST_Request $req ) {
		// [2026-10-13 02:55 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.106 — say WHICH half is missing (a partial deploy is the usual cause): the sessions manager file is older than this REST file
		if ( ! class_exists( 'BizCity_TwinBrain_Sessions_Manager' ) ) {
			return $this->err( 'twinbrain_outputs_unavailable', 'Danh sách tệp chưa sẵn sàng trên website này (thiếu class Sessions_Manager).', 503, [], null, true );
		}
		if ( ! method_exists( 'BizCity_TwinBrain_Sessions_Manager', 'list_cell_outputs' ) ) {
			return $this->err( 'twinbrain_outputs_unavailable', 'Danh sách tệp chưa sẵn sàng trên website này (class-twinbrain-sessions-manager.php trên website là bản cũ, chưa có list_cell_outputs).', 503, [], null, true );
		}
		$page = max( 1, (int) ( $req->get_param( 'page' ) ?: 1 ) );
		$r    = BizCity_TwinBrain_Sessions_Manager::instance()->list_cell_outputs(
			get_current_user_id(),
			(string) $req->get_param( 'session_id' ),
			(string) $req->get_param( 'kind' ),
			$page
		);
		// [2026-10-13 03:10 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.106 S106-C5 — administrators only: why a file is still `pending` (class loaded? WP-Cron off? pulls waiting? last error?)
		// and `pull_now=1` runs the waiting pulls right away (a host without a working WP-Cron never does).
		$extra = [];
		// self-heal: media rows still `pending` are queued for the copy again (does not rely on the turn-complete hook having fired)
		if ( class_exists( 'BizCity_Twin_Cell_Output_Store' ) ) {
			BizCity_Twin_Cell_Output_Store::reconcile( (array) ( $r['items'] ?? [] ), get_current_user_id() );
		}
		if ( class_exists( 'BizCity_Twin_Cell_Output_Store' ) && current_user_can( 'manage_options' ) ) {
			if ( 1 === (int) $req->get_param( 'pull_now' ) ) {
				$extra['pulled'] = BizCity_Twin_Cell_Output_Store::run_pending();
				$r = BizCity_TwinBrain_Sessions_Manager::instance()->list_cell_outputs( get_current_user_id(), (string) $req->get_param( 'session_id' ), (string) $req->get_param( 'kind' ), $page );
			}
			$extra['diag'] = BizCity_Twin_Cell_Output_Store::diagnostics();
		} elseif ( current_user_can( 'manage_options' ) ) {
			$extra['diag'] = [ 'loaded' => false ];
		}
		return rest_ensure_response( $r + [ 'page' => $page ] + $extra );
	}

	/** GET /cell-chat/media/<id>?account_id= → the bytes of one cell file (Range ok), streamed via the Hub; user_hash is server-side only. */
	public function handle_cell_chat_media( WP_REST_Request $req ) {
		if ( ! class_exists( 'BizCity_Twin_Web_Turn' ) || ! method_exists( 'BizCity_Twin_Web_Turn', 'cell_chat_media' ) ) {
			return $this->err( 'twinbrain_cell_chat_unavailable', 'Cell Chat chưa sẵn sàng trên website này.', 503, [], null, true );
		}
		$r = BizCity_Twin_Web_Turn::cell_chat_media(
			get_current_user_id(),
			self::cell_chat_account( $req->get_param( 'account_id' ) ),
			(string) $req->get_param( 'media_id' ),
			(string) $req->get_header( 'range' )
		);
		if ( ! empty( $r['streamed'] ) ) {
			exit; // bytes already written; WP must not append a JSON response
		}
		return new WP_REST_Response( $r['body'], $r['status'] );
	}

	/**
	 * Last turns of an Ask Brain session as chat history (oldest first); [] when the session is new or unreadable. The cell keeps
	 * its own window; this only seeds it for sessions that started on the legacy path.
	 *
	 * @return array<int,array{role:string,content:string}>
	 */
	private function agent_turn_history( string $session_id, int $user_id ): array {
		if ( '' === $session_id || ! class_exists( 'BizCity_TwinBrain_Sessions_Manager' ) ) {
			return array();
		}
		$out = array();
		try {
			$res = BizCity_TwinBrain_Sessions_Manager::instance()->list_turns( $session_id, $user_id, 20 );
			foreach ( (array) ( $res['items'] ?? array() ) as $t ) {
				$q = trim( (string) ( $t['prompt'] ?? '' ) );
				$a = trim( (string) ( $t['answer'] ?? '' ) );
				if ( '' !== $q && '' !== $a ) {
					$out[] = array( 'role' => 'user', 'content' => $q );
					$out[] = array( 'role' => 'assistant', 'content' => $a );
				}
			}
		} catch ( \Throwable $e ) {
			return array();
		}
		return $out;
	}

	public function handle_turn( WP_REST_Request $req ) {
		$prompt = trim( (string) $req->get_param( 'prompt' ) );
		if ( $prompt === '' ) {
			return $this->err_validation( 'twinbrain_empty_prompt', 'Prompt bắt buộc không được để trống.' );
		}
		$focus_notebook_id = absint( $req->get_param( 'focus_notebook_id' ) );
		$focus_policy = '';
		if ( $focus_notebook_id > 0 ) {
			// [2026-08-16 Johnny Chu] P2 — keep sync and SSE focus validation identical.
			$focus_check = $this->validate_focus_notebook( $prompt, get_current_user_id(), $focus_notebook_id );
			if ( is_wp_error( $focus_check ) ) {
				return $focus_check;
			}
			$focus_policy = (string) ( $focus_check['policy'] ?? '' );
		}
		// [2026-06-03 Johnny Chu] BRAIN-SESSIONS BS-2 — resolve / mint session.
		$session_id = $this->resolve_session_id( $req, get_current_user_id() );
		$notebook_upload_url = admin_url( 'admin.php?page=bizcity-twinchat' );
		if ( $focus_notebook_id > 0 ) {
			$notebook_upload_url = add_query_arg( 'notebook_id', $focus_notebook_id, $notebook_upload_url );
		}
		$opts = [
			'user_id'          => get_current_user_id(),
			'k'                => $req->get_param( 'k' ) ?: BIZCITY_TWINBRAIN_K_DEFAULT,
			'force_notebooks'  => (array) $req->get_param( 'force_notebooks' ),
			'focus_notebook_id' => $focus_notebook_id,
			'force_tools'      => (array) $req->get_param( 'force_tools' ),
			'skip_tool_intent' => (bool)  $req->get_param( 'skip_tool_intent' ),
			'answer_depth'     => $this->sanitize_answer_depth( $req->get_param( 'answer_depth' ) ),
			// [2026-08-11 Johnny Chu] PHASE-TWB-WOO-BIZOPS — propagate synchronous vertical mode to runtime.
			'web_mode'         => $this->sanitize_web_mode( $req->get_param( 'web_mode' ) ),
			'session_id'       => $session_id,
			'notebook_upload_url' => esc_url_raw( $notebook_upload_url ),
			'surface'          => 'twin',
		];
		$this->apply_default_notebook( $opts, get_current_user_id(), 'twin' );
		if ( $focus_notebook_id > 0 ) {
			$opts['force_notebooks'] = array( $focus_notebook_id );
		}
		// [2026-08-16 Johnny Chu] CCG-2 — literal /vertical_slug must resolve deterministically when the client never set web_mode.
		if ( 'off' === $opts['web_mode'] && class_exists( 'BizCity_TwinBrain_Vertical_Bridge_Registry' ) ) {
			$vertical_command = BizCity_TwinBrain_Vertical_Bridge_Registry::extract( $prompt );
			if ( is_array( $vertical_command ) ) {
				$opts['web_mode'] = (string) $vertical_command['slug'];
			}
		}
		// [2026-10-22 07:20 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.126 Q126-19 — the synchronous turn is MPR-only (no cell path here): it now answers vertical modes only.
		// A plain Brain Chat question goes through /turn/stream (cell). The emergency constant keeps the old behaviour.
		// 2026-10-22 09:45 PM (owner: "để qua cell hết") — vertical modes too: the cell has its own recipes; the emergency constant is the only way in.
		if ( ! ( class_exists( 'BizCity_Twin_Web_Turn' ) && BizCity_Twin_Web_Turn::force_php() ) ) {
			return new WP_REST_Response( [ 'ok' => false, 'code' => 'brain_chat_on_cell', 'message' => 'Câu hỏi thường của bộ não do trợ lý (cell) trả lời qua luồng chat.', 'hint' => 'Gọi /turn/stream (chế độ chuyên môn gửi kèm web_mode).', 'help_code' => 'S126-BRAIN-TURN-SYNC' ], 409 );
		}

		// [2026-10-24 Johnny Chu - Chu Hoàng Anh] PHASE-0.126 R126-B2 — the emergency PHP turn needs the MPR from bizcity-twin-brain-addon/twinbrain-mpr/.
		if ( ! class_exists( 'BizCity_TwinBrain_Runtime' ) ) {
			return new WP_REST_Response( [ 'ok' => false, 'code' => 'brain_addon_missing', 'message' => 'Đường dự phòng PHP cần add-on BizCity Twin Brain.', 'hint' => 'Cài bizcity-twin-brain-addon, hoặc bỏ hằng BIZCITY_TWIN_WEB_TURN_FORCE_PHP để bộ não (cell) trả lời.', 'help_code' => 'S126-BRAIN-ADDON-MISSING' ], 503 );
		}
		$runtime = BizCity_TwinBrain_Runtime::instance();
		$start   = $runtime->start_turn( $prompt, $opts );

		if ( $req->get_param( 'auto_complete' ) ) {
			$done = $runtime->complete_turn(
				$start['trace_id'],
				$prompt,
				$start['candidates'],
				$start['tool_candidates'],
				array(
					'guru_id'                   => (int)    ( $start['guru_id']    ?? 0 ),
					'tool_force'                => (string) ( $start['tool_force'] ?? '' ),
					// [2026-07-27 Johnny Chu] PHASE-0.52 W3 — reuse start-stage profile context in auto-complete.
					'subject_context_md'        => (string) ( $start['subject_context_md'] ?? '' ),
					'subject_context_label'     => (string) ( $start['subject_context_label'] ?? '' ),
					'subject_id'                => (int)    ( $start['subject_id'] ?? 0 ),
					'_subject_profile_resolved' => ! empty( $start['_subject_profile_resolved'] ),
					'user_id'                   => (int)    ( $opts['user_id'] ?? 0 ),
					'session_id'                => (string) ( $opts['session_id'] ?? '' ),
					'identity_uuid'             => (string) ( $start['identity_uuid'] ?? '' ),
					'identity_state'            => (string) ( $start['identity_state'] ?? 'unknown' ),
					'subject_contract'          => (array)  ( $start['subject_contract'] ?? array() ),
					'goal_loop_state'            => (array)  ( $start['goal_loop_state'] ?? array() ),
					'goal_loop'                 => (array)  ( $start['goal_loop_state'] ?? array() ),
					'goal_contract'              => (array)  ( $start['goal_contract'] ?? array() ),
					// [2026-08-01 Johnny Chu] PHASE-TWIN-GOAL-LOOP-G2 — preserve the active goal brief for Final Composer.
					'goal_loop_brief'           => (string) ( $start['goal_loop_brief'] ?? '' ),
					'answer_depth'               => (string) ( $start['answer_depth'] ?? $opts['answer_depth'] ?? 'high' ), // [2026-08-07 Johnny Chu] V4-DEPTH — preserve resolved tier for synchronous completion.
					'goal_loop_pre_turn_completed' => ! empty( $start['goal_loop_pre_turn_completed'] ), // [2026-08-07 Johnny Chu] V4-DEPTH — preserve parser lifecycle marker.
					'pre_mpr_triage'             => (array) ( $start['pre_mpr_triage'] ?? array() ), // [2026-08-07 Johnny Chu] V4-TRIAGE — preserve ambiguous/MPR branch.
					'ambiguous_no_goal'          => ! empty( $start['ambiguous_no_goal'] ),
					// [2026-08-11 Johnny Chu] PHASE-TWB-WOO-BIZOPS — preserve vertical mode during synchronous completion.
					'web_mode'                   => (string) ( $opts['web_mode'] ?? 'off' ),
					'notebook_upload_url'        => (string) ( $opts['notebook_upload_url'] ?? '' ),
				)
			);
			return rest_ensure_response( array_merge( $start, $done, [ 'session_id' => $session_id ] ) );
		}
		return rest_ensure_response( array_merge( $start, [ 'session_id' => $session_id ] ) );
	}

	/**
	 * TBR.F6-sse — progressive turn over Server-Sent Events.
	 * Mirrors `handle_turn` (auto_complete=true) but pushes phase events as
	 * they happen. Final synthesis still arrives in the SSE `complete` frame
	 * so a client that reconnects mid-turn can recover state from the BE
	 * event-stream replay (`GET /turn/{trace_id}`).
	 */
	public function handle_turn_stream( WP_REST_Request $req ) {
		// [2026-10-12 10:05 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.105 S105-C2/C10 — Cell Chat never reaches the MPR code below.
		if ( $this->maybe_cell_chat_turn( $req ) ) {
			exit; // SSE (answer or error frame) already written
		}
		$prompt = trim( (string) $req->get_param( 'prompt' ) );
		if ( $prompt === '' ) {
			return $this->err_validation( 'twinbrain_empty_prompt', 'Prompt bắt buộc không được để trống.' );
		}
		if ( ! class_exists( 'BizCity_Twin_SSE_Writer' ) ) {
			return $this->err( 'twinbrain_sse_unavailable', 'SSE writer chưa được nạp — plugin core twin-core có thể bị tắt.', 503, [], null, true );
		}
		$focus_notebook_id = absint( $req->get_param( 'focus_notebook_id' ) );
		$focus_policy = '';
		if ( $focus_notebook_id > 0 ) {
			// [2026-08-16 Johnny Chu] P2 — reject focused notebook requests before MPR can widen scope.
			$focus_check = $this->validate_focus_notebook( $prompt, get_current_user_id(), $focus_notebook_id );
			if ( is_wp_error( $focus_check ) ) {
				return $focus_check;
			}
			$focus_policy = (string) ( $focus_check['policy'] ?? '' );
		}

		// [2026-10-03 Claude Opus 5.5] PHASE-0.89 S89-B7 — Brain Chat through the cell when the `brain` flag says so; 'php' = legacy below.
		$agent = $this->maybe_agent_turn( $req, $prompt, $focus_notebook_id );
		if ( 'done' === $agent ) {
			exit; // SSE already written by the transition bridge
		}
		if ( $agent instanceof WP_REST_Response ) {
			return $agent;
		}

		$notebook_upload_url = admin_url( 'admin.php?page=bizcity-twinchat' );
		if ( $focus_notebook_id > 0 ) {
			$notebook_upload_url = add_query_arg( 'notebook_id', $focus_notebook_id, $notebook_upload_url );
		}
		$opts = [
			'user_id'          => get_current_user_id(),
			'k'                => $req->get_param( 'k' ) ?: BIZCITY_TWINBRAIN_K_DEFAULT,
			'force_notebooks'  => (array) $req->get_param( 'force_notebooks' ),
			'focus_notebook_id' => $focus_notebook_id,
			'force_tools'      => (array) $req->get_param( 'force_tools' ),
			'skip_tool_intent' => (bool)  $req->get_param( 'skip_tool_intent' ),
			'answer_depth'     => $this->sanitize_answer_depth( $req->get_param( 'answer_depth' ) ),
			// TBR.W9 (2026-05-21) — propagate web_mode to runtime so Stage 2.5
			// engines (Web_Quick / Web_Deep) fire after notebook perspectives.
			'web_mode'         => $this->sanitize_web_mode( $req->get_param( 'web_mode' ) ),
			// TBR.W20 (2026-05-28) — propagate agent-mode toggle.
			'mode'             => $this->sanitize_mode( $req->get_param( 'mode' ) ),
			// [2026-06-03 Johnny Chu] BRAIN-SESSIONS BS-2 — resolve / mint session.
			'session_id'       => $this->resolve_session_id( $req, get_current_user_id() ),
			'notebook_upload_url' => esc_url_raw( $notebook_upload_url ),
			'surface'          => 'twin',
		];
		$this->apply_default_notebook( $opts, get_current_user_id(), 'twin' );
		if ( $focus_notebook_id > 0 ) {
			$opts['force_notebooks'] = array( $focus_notebook_id );
		}
		// [2026-08-16 Johnny Chu] CCG-2 — literal /vertical_slug must resolve deterministically before the fuzzy conversation router runs.
		if ( 'off' === $opts['web_mode'] && class_exists( 'BizCity_TwinBrain_Vertical_Bridge_Registry' ) ) {
			$vertical_command = BizCity_TwinBrain_Vertical_Bridge_Registry::extract( $prompt );
			if ( is_array( $vertical_command ) ) {
				$opts['web_mode'] = (string) $vertical_command['slug'];
			}
		}
		// [2026-10-07 10:05 Johnny Chu - Chu Hoàng Anh] TWINBRAIN-SPLIT-CORE-ADDON Q-2 — Conversation_Router / Conversation_Confirmation (pending Có/Không replies) removed: the PHP intent layer is gone.

		// [2026-10-24 Johnny Chu - Chu Hoàng Anh] PHASE-0.126 R126-B2 — this legacy stream is the emergency PHP path; it needs the MPR from the brain add-on.
		if ( ! class_exists( 'BizCity_TwinBrain_Runtime' ) ) {
			return new WP_REST_Response( [ 'ok' => false, 'code' => 'brain_addon_missing', 'message' => 'Đường dự phòng PHP cần add-on BizCity Twin Brain.', 'hint' => 'Cài bizcity-twin-brain-addon, hoặc bỏ hằng BIZCITY_TWIN_WEB_TURN_FORCE_PHP để bộ não (cell) trả lời.', 'help_code' => 'S126-BRAIN-ADDON-MISSING' ], 503 );
		}

		$sse = new BizCity_Twin_SSE_Writer( true );
		// PHASE-0.89 S89-S3 (twin-stream@1 rule 5): the legacy pipeline says so first; its own events follow unchanged
		if ( class_exists( 'BizCity_Twin_Web_Turn' ) && method_exists( 'BizCity_Twin_Web_Turn', 'path_event' ) ) {
			$sse->emit( 'twin_event', BizCity_Twin_Web_Turn::path_event( 'legacy', $this->legacy_reason, BizCity_Twin_Web_Turn::flag( 'brain' ), '', 'cell_fallback' === $this->legacy_reason ? BizCity_Twin_Web_Turn::fallback_detail() : '' ) );
		}

		// [2026-10-12 Johnny Chu - Chu Hoàng Anh] PHASE-0.101 S102-L6 — #workflow_slug interception removed (owner Q102-6).

		// [2026-10-07 10:05 Johnny Chu - Chu Hoàng Anh] TWINBRAIN-SPLIT-CORE-ADDON Q-2 — confirm prompt + /skill Workflow Pipeline removed; a request with a `skill` param now runs the normal turn.

		try {
			$runtime = BizCity_TwinBrain_Runtime::instance();

			$sse->emit( 'started', [ 'prompt' => $prompt, 'session_id' => (string) ( $opts['session_id'] ?? '' ) ] );
			$start = $runtime->start_turn( $prompt, $opts );
			$trace_id = (string) ( $start['trace_id'] ?? '' );
			if ( $focus_notebook_id > 0 ) {
				$sse->emit( 'notebook_focus_resolved', array(
					'trace_id' => $trace_id,
					'notebook_id' => $focus_notebook_id,
					'guru_id' => (int) ( $start['guru_id'] ?? 0 ),
					'policy' => $focus_policy,
				) );
			}
			// [2026-10-07 10:05 Johnny Chu - Chu Hoàng Anh] TWINBRAIN-SPLIT-CORE-ADDON Q-2 — triage / conversation_route SSE removed with Pre_MPR_Triage and Conversation_Router.

			/* PHASE-0.35 / F7.C4.1 — Re-emit Layer 0/1 events as native SSE so
			 * the FE BrainThinkingTimeline reducer can render guru search +
			 * instruction layer steps (event_bus echo only fires on debug). */
			if ( ! empty( $start['pre_rules'] ) ) {
				$sse->emit( 'pre_rules_done', (array) $start['pre_rules'] );
			}
			if ( ! empty( $start['guru_lookup'] ) ) {
				$sse->emit( 'guru_lookup', (array) $start['guru_lookup'] );
			}
			if ( ! empty( $start['guru_layer'] ) ) {
				$sse->emit( 'guru_layer', (array) $start['guru_layer'] );
			}

			/* Wave 2.8 — Layer 0.5 Memory Recall (TBR.MEM-3 echo). */
			if ( ! empty( $start['memory_recall'] ) ) {
				$sse->emit( 'memory_recall', (array) $start['memory_recall'] );
			}

			// [2026-09-15 Johnny Chu - Chu Hoàng Anh] PHASE-1.33C — forward real start-stage timings so the timeline can attribute pre-MPR cost.
			$stage_timings = (array) ( $start['stage_timings'] ?? array() );
			if ( ! empty( $stage_timings ) ) {
				$sse->emit( 'stage_timings', array(
					'trace_id' => $trace_id,
					'timings'  => $stage_timings,
				) );
			}

			$sse->emit( 'candidates_selected', [
				'trace_id'        => $trace_id,
				'candidates'      => (array) ( $start['candidates']      ?? [] ),
				'tool_candidates' => (array) ( $start['tool_candidates'] ?? [] ),
				'keyword_tokens'  => (array) ( $start['keyword_tokens']  ?? [] ),
				// [2026-09-15 Johnny Chu - Chu Hoàng Anh] PHASE-1.33C — carry the measured selector duration on the event that owns the step.
				'duration_ms'     => (int) ( $stage_timings['candidate_selection'] ?? 0 ),
			] );

			$done = $runtime->complete_turn_stream(
				$trace_id,
				$prompt,
				(array) ( $start['candidates'] ?? [] ),
				(array) ( $start['tool_candidates'] ?? [] ),
				$sse,
				array(
					'guru_id'                   => (int)    ( $start['guru_id']    ?? 0 ),
					'tool_force'                => (string) ( $start['tool_force'] ?? '' ),
					// [2026-07-27 Johnny Chu] PHASE-0.52 W3 — reuse start-stage profile context in SSE completion.
					'subject_context_md'        => (string) ( $start['subject_context_md'] ?? '' ),
					'subject_context_label'     => (string) ( $start['subject_context_label'] ?? '' ),
					'subject_id'                => (int)    ( $start['subject_id'] ?? 0 ),
					'_subject_profile_resolved' => ! empty( $start['_subject_profile_resolved'] ),
					'identity_uuid'             => (string) ( $start['identity_uuid'] ?? '' ),
					'identity_state'            => (string) ( $start['identity_state'] ?? 'unknown' ),
					'subject_contract'          => (array)  ( $start['subject_contract'] ?? array() ),
					'goal_loop_state'            => (array)  ( $start['goal_loop_state'] ?? array() ),
					'goal_loop'                 => (array)  ( $start['goal_loop_state'] ?? array() ),
					// [2026-08-01 Johnny Chu] PHASE-TWIN-GOAL-LOOP-G2 — preserve the active goal brief for Final Composer.
					'goal_loop_brief'           => (string) ( $start['goal_loop_brief'] ?? '' ),
					'goal_contract'              => (array)  ( $start['goal_contract'] ?? array() ),
					'answer_depth'               => (string) ( $start['answer_depth'] ?? $opts['answer_depth'] ?? 'high' ), // [2026-08-07 Johnny Chu] V4-DEPTH — preserve resolved tier for streaming completion.
					'pre_mpr_triage'             => (array) ( $start['pre_mpr_triage'] ?? array() ), // [2026-08-07 Johnny Chu] V4-TRIAGE — preserve ambiguous/MPR branch.
					'ambiguous_no_goal'          => ! empty( $start['ambiguous_no_goal'] ),
					// TBR.W9 — Stage 2.5 toggle propagated from REST opts.
					'web_mode'       => (string) ( $opts['web_mode']    ?? 'off' ),
					// TBR.W20 — Agent mode toggle (brain | agent).
					'mode'           => (string) ( $opts['mode']        ?? 'brain' ),
					// TBR.SEL-LEX — tokenized prompt shared with downstream
					// (perspective passage rerank + FE highlight).
					'keyword_tokens' => (array)  ( $start['keyword_tokens'] ?? [] ),
					// Wave 2.8 TBR.MEM — recall block + user/session ctx for Writer.
					'memory_block'   => (string) ( $start['memory_block']   ?? '' ),
					'user_id'        => (int)    ( $opts['user_id']        ?? 0 ),
					'session_id'     => (string) ( $opts['session_id']     ?? '' ),
					'notebook_upload_url' => (string) ( $opts['notebook_upload_url'] ?? '' ),
				)
			);
			// [2026-10-07 10:05 Johnny Chu - Chu Hoàng Anh] TWINBRAIN-SPLIT-CORE-ADDON Q-2 — Deep Research offer/confirm loop removed with Conversation_Confirmation.

			$sse->close( array_merge(
				[ 'trace_id' => $trace_id ],
				$start,
				$done
			) );
		} catch ( \Throwable $e ) {
			$sse->error( $e->getMessage(), 'twinbrain_turn_stream_error' );
		}

		// Headers + body already streamed; return null so WP REST doesn't try
		// to serialize a JSON response over the closed event-stream.
		exit;
	}
	private function validate_focus_notebook( $prompt, $user_id, $notebook_id ) {
		// [2026-08-16 Johnny Chu] P2 — focused notebook must belong to the resolved Guru attachment set.
		if ( ! class_exists( 'BizCity_Guru_Token_Parser' ) || ! class_exists( 'BizCity_KG_Database' ) ) {
			return new WP_Error( 'guru_notebook_focus_unavailable', 'Không thể xác thực phạm vi notebook của Guru.', array( 'status' => 503 ) );
		}
		$parsed = BizCity_Guru_Token_Parser::parse( (string) $prompt );
		$guru_id = (int) ( $parsed['guru_id'] ?? 0 );
		global $wpdb;
		$db = BizCity_KG_Database::instance();
		if ( $guru_id <= 0 ) {
			// [2026-10-24 Johnny Chu - Chu Hoàng Anh] PHASE-0.126 — INCIDENT: a notebook picked in the Brain Chat + a plain message ("ao") answered 400 "Notebook focus cần Guru
			// Workspace hợp lệ". The Guru-token rule protected the PHP MPR from widening its scope; the cell answers now and needs no Guru for a focused notebook. The
			// ownership rule stays: the notebook must be the user's own, or a business / guru knowledge base.
			$nb = $wpdb->get_row( $wpdb->prepare( 'SELECT owner_id, notebook_scope FROM ' . $db->tbl_notebooks() . ' WHERE id = %d LIMIT 1', (int) $notebook_id ), ARRAY_A );
			if ( ! is_array( $nb ) ) {
				return new WP_Error( 'guru_notebook_focus_invalid', 'Không tìm thấy notebook đang chọn.', array( 'status' => 400 ) );
			}
			$nb_owner = (int) ( $nb['owner_id'] ?? 0 );
			$nb_scope = sanitize_key( (string) ( $nb['notebook_scope'] ?? 'personal' ) );
			if ( ! ( ( $nb_owner > 0 && $nb_owner === (int) $user_id ) || ( 0 === $nb_owner && in_array( $nb_scope, array( 'business_kb', 'guru_kb' ), true ) ) ) ) {
				return new WP_Error( 'guru_notebook_focus_invalid', 'Notebook focus không thuộc phạm vi được phép.', array( 'status' => 403 ) );
			}
			return array( 'valid' => true, 'policy' => 'augment' );
		}
		$guru_uuid = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT guru_uuid FROM ' . $wpdb->prefix . 'bizcity_characters WHERE id = %d LIMIT 1', $guru_id ) );
		if ( $guru_uuid === '' ) {
			return new WP_Error( 'guru_notebook_focus_invalid', 'Guru Workspace không thuộc site hiện tại.', array( 'status' => 400 ) );
		}
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT n.owner_id, n.notebook_scope FROM ' . $db->tbl_notebooks() . ' n INNER JOIN ' . $db->tbl_notebook_character_attachments() . ' a ON a.notebook_id = n.id WHERE a.guru_uuid = %s AND n.id = %d LIMIT 1', $guru_uuid, (int) $notebook_id ), ARRAY_A );
		if ( ! is_array( $row ) ) {
			return new WP_Error( 'guru_notebook_focus_invalid', 'Notebook này chưa được gắn vào Guru Workspace.', array( 'status' => 400 ) );
		}
		$owner_id = (int) ( $row['owner_id'] ?? 0 );
		$scope = sanitize_key( (string) ( $row['notebook_scope'] ?? 'personal' ) );
		$visible = ( $owner_id > 0 && $owner_id === (int) $user_id ) || ( $owner_id === 0 && in_array( $scope, array( 'business_kb', 'guru_kb' ), true ) );
		if ( ! $visible ) {
			return new WP_Error( 'guru_notebook_focus_invalid', 'Notebook focus không thuộc phạm vi được phép.', array( 'status' => 403 ) );
		}
		$guru = $wpdb->get_row( $wpdb->prepare( 'SELECT notebook_policy FROM ' . $wpdb->prefix . 'bizcity_characters WHERE id = %d LIMIT 1', $guru_id ), ARRAY_A );
		return array(
			'valid'  => true,
			'policy' => in_array( (string) ( $guru['notebook_policy'] ?? 'augment' ), array( 'augment', 'restrict' ), true ) ? (string) $guru['notebook_policy'] : 'augment',
		);
	}

	public function handle_tool_confirm( WP_REST_Request $req ) {
		// TODO PHASE-0.36 sprint .9 — invoke Shell Engine to run the skill,
		// then re-trigger synthesizer with tool result included.
		return rest_ensure_response( [
			'ok'      => true,
			'pending' => 'sprint_0.36.9',
			'trace_id'=> (string) $req->get_param( 'trace_id' ),
			'skill'   => (string) $req->get_param( 'skill_slug' ),
		] );
	}

	public function handle_replay( WP_REST_Request $req ) {
		global $wpdb;
		$trace_id = (string) $req['trace_id'];
		$tbl      = $wpdb->prefix . 'bizcity_twin_event_stream';
		$prev     = $wpdb->suppress_errors( true );
		$exists_row = bizcity_tbl_exists( $tbl ) ? $tbl : null; // [2026-06-21 Johnny Chu] R-SHOW-TABLES
		if ( $exists_row !== $tbl ) {
			$wpdb->suppress_errors( $prev );
			return $this->err_table_missing( $tbl, 'twinbrain' );
		}
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT event_type, payload_json, created_epoch_ms
			 FROM {$tbl}
			 WHERE trace_id = %s
			 ORDER BY created_epoch_ms ASC
			 LIMIT 500",
			$trace_id
		), ARRAY_A );
		$wpdb->suppress_errors( $prev );
		return rest_ensure_response( [ 'ok' => true, 'trace_id' => $trace_id, 'events' => (array) $rows ] );
	}
}
