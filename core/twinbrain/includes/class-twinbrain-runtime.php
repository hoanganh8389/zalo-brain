<?php
/**
 * BizCity TwinBrain Runtime — Não tổng orchestrator entry.
 *
 * Implements the 4-stage pipeline defined in PHASE-0.36-TWINBRAIN-CENTRAL-BRAIN.md §5:
 *   1. Dual selector (notebook + tool intent, parallel)
 *   2. Parallel sub-agents (curl_multi)
 *   3. Optional tool confirmation (sync if user accepts)
 *   4. Synthesizer with locked anti-averaging prompt + citation extract
 *
 * Wave 0 (this commit): synchronous skeleton — selectors & runner are present
 * but return mock empty results until Wave 2-5 lands. Event emission contracts
 * are LIVE so FE/E2E can wire against the final shape today.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Modules\TwinBrain
 * @since      2026-05-10
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_TwinBrain_Runtime {

	const SURFACE = 'twinbrain';
	const MPR_GATE_STAGE_VERSION = 'mpr_gate.v1'; // [2026-08-04 Johnny Chu] V3.5 — canonical version for decision.stage checkpoint payloads.
	// [2026-07-05 Johnny Chu] PHASE-FAA2-TWINBRAIN — G5 checklist freshness threshold before compose.
	const ASTRO_CHECKLIST_FRESH_HOURS = 36;
	// [2026-08-06 Johnny Chu] V4-DEPTH — default answer_depth tier when caller omits it; MUST stay 'high' (current V3 canonical behaviour) so existing callers see zero behaviour change.
	const ANSWER_DEPTH_DEFAULT = 'high';
	// [2026-08-06 Johnny Chu] V4-DEPTH — 'deep' tier bounded Retrieve round ceiling (chuyên sâu).
	const DEEP_RETRIEVE_ROUNDS = 3;

	/** @var self|null */
	private static $instance = null;

	/**
	 * [2026-06-04 Johnny Chu] BS-12 — session_id of the turn currently being
	 * processed. Set by the entry methods (start_turn / complete_turn /
	 * complete_turn_stream) from $opts['session_id'] so emit_event() can
	 * durably persist conversational turns (user_message / assistant_message)
	 * to the event_stream tagged with the right session for replay.
	 * @var string
	 */
	private $current_session_id = '';

	/**
	 * [2026-08-01 Johnny Chu] PHASE-1.25-TWINBRAIN-LOG — persist runtime failures
	 * in per-site JSONL while retaining the existing PHP error log for server ops.
	 */
	public static function write_runtime_log( $level, $event, $message, array $context = array() ): void {
		if ( ! class_exists( 'BizCity_JSONL_File_Logger' ) || ! method_exists( 'BizCity_JSONL_File_Logger', 'write_contract' ) ) {
			return;
		}
		// [2026-08-27 Johnny Chu] R-LOG-HYBRID — resolve TwinBrain runtime logging from the canonical contract registry.
		BizCity_JSONL_File_Logger::write_contract(
			'core.twinbrain.runtime_trace',
			(string) $level,
			(string) $event,
			(string) $message,
			$context
		);
	}

	// [2026-10-07 10:05 Johnny Chu - Chu Hoàng Anh] TWINBRAIN-SPLIT-CORE-ADDON Q-2 — emit_pre_mpr_triage_event, build_ambiguous_opening
	// and stream_ambiguous_response removed with Pre_MPR_Triage (archived). Greetings now run the normal turn.

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/* =================================================================
	 *  [2026-10-07 10:05 Johnny Chu - Chu Hoàng Anh] TWINBRAIN-SPLIT-CORE-ADDON W-2 — vertical seams.
	 *  Vertical brain modes (astro, products, woo_bizops, web research) live in bizcity-twin-brain-addon/twinbrain-verticals/.
	 *  Core only asks these filters; with no add-on every list is empty and turns run plain MPR.
	 * ================================================================ */

	/** Every web_mode value a loaded vertical accepts (REST sanitizer, mode allowlists). */
	public static function vertical_modes(): array {
		return array_values( array_unique( array_map( 'strval', (array) apply_filters( 'bizcity_twinbrain_vertical_modes', array() ) ) ) );
	}

	/** web_mode values answered by a Stage 2.5 engine row (`bizcity_twinbrain_web_engine_run`). */
	public static function web_engine_modes(): array {
		return array_values( array_unique( array_map( 'strval', (array) apply_filters( 'bizcity_twinbrain_web_engine_modes', array() ) ) ) );
	}

	/** web_mode values that own the whole turn (`bizcity_twinbrain_vertical_takeover`). */
	public static function takeover_modes(): array {
		return array_values( array_unique( array_map( 'strval', (array) apply_filters( 'bizcity_twinbrain_takeover_modes', array() ) ) ) );
	}

	/** web_mode values that answer from their own data: skip notebook selection and tool intent (Woo BizOps). */
	public static function owns_data_query( string $mode ): bool {
		return $mode !== '' && in_array( $mode, (array) apply_filters( 'bizcity_twinbrain_data_query_modes', array() ), true );
	}

	/** Hand a whole turn to the vertical that owns $opts['web_mode']; null when none does. */
	private function vertical_takeover( string $trace_id, string $prompt, BizCity_Twin_SSE_Writer $sse, array $opts, float $wall_t0 ) {
		$mode = strtolower( (string) ( $opts['web_mode'] ?? 'off' ) );
		$res  = apply_filters( 'bizcity_twinbrain_vertical_takeover', null, $mode, $this, $trace_id, $prompt, $sse, $opts, $wall_t0 );
		return is_array( $res ) ? $res : null;
	}

	/**
	 * Resolve the memory/profile subject without granting implicit cross-user access.
	 *
	 * @param int   $actor_user_id User who initiated the turn.
	 * @param array $opts Runtime options.
	 * @return int
	 */
	private function resolve_subject_id( $actor_user_id, array $opts ) {
		$actor_user_id = (int) $actor_user_id;
		$requested     = (int) ( $opts['subject_id'] ?? 0 );
		if ( $requested <= 0 || $requested === $actor_user_id ) {
			return $actor_user_id;
		}
		// [2026-07-27 Johnny Chu] PHASE-0.52 W3 — cross-user subject access requires an explicit server-side authorization decision.
		$allowed = (bool) apply_filters(
			'bizcity_twinbrain_can_access_subject',
			false,
			$requested,
			$actor_user_id,
			$opts
		);
		return $allowed ? $requested : $actor_user_id;
	}

	/**
	 * Resolve the durable customer identity before subject, memory, KG, or tools.
	 */
	private function resolve_identity_context( array $opts, int $actor_user_id ): array {
		// [2026-08-01 Johnny Chu] PHASE-TWIN-MPR-SUBJECT-G0 — use the shared
		// memory identity boundary so MPR, recall, profile and writer agree on
		// guest-channel versus WP-user ownership.
		if ( class_exists( 'BizCity_Memory_Identity_Scope' ) ) {
			$scope = BizCity_Memory_Identity_Scope::resolve( array_merge( $opts, array(
				'user_id'    => $actor_user_id,
				'wp_user_id' => $actor_user_id,
			) ) );
			if ( ! empty( $scope['identity_uuid'] ) ) {
				$opts['identity_uuid']      = (string) $scope['identity_uuid'];
				$opts['identity_is_stable'] = ! empty( $scope['identity_is_stable'] );
				$opts['identity_state']     = ! empty( $scope['identity_verified'] ) ? 'stable' : 'unknown';
			}
			return $opts;
		}
		if ( ! class_exists( 'BizCity_Identity_Hub' ) ) {
			return $opts;
		}
		$identity = BizCity_Identity_Hub::resolve_from_opts( $opts, (int) get_current_blog_id() );
		if ( ! $identity && $actor_user_id > 0 ) {
			$identity = BizCity_Identity_Hub::bind(
				BizCity_Identity_Hub::PLATFORM_WP,
				(string) get_current_blog_id(),
				(string) $actor_user_id,
				$actor_user_id,
				(int) get_current_blog_id(),
				true
			);
		}
		if ( is_wp_error( $identity ) || ! is_array( $identity ) || empty( $identity['identity_uuid'] ) ) {
			return $opts;
		}
		$opts['identity_uuid']      = (string) $identity['identity_uuid'];
		$opts['contact_id']         = (int) ( $identity['contact_id'] ?? 0 );
		$opts['identity_is_stable'] = true;
		$opts['identity_state']     = 'stable';
		return $opts;
	}

	/**
	 * Resolve customer subject profile context for Notebook/vertical turns.
	 *
	 * @param string      $trace_id Trace ID.
	 * @param string      $prompt User prompt.
	 * @param array       $opts Runtime options.
	 * @param object|null $sse Optional SSE writer.
	 * @return array
	 */
	private function collect_subject_profile_context( $trace_id, $prompt, array $opts, $sse = null ) {
		if ( ! empty( $opts['_subject_profile_resolved'] ) ) {
			return $opts;
		}
		// [2026-07-19 Johnny Chu] PHASE-TWIN-GPT-PROFILE-GROUNDING — generic profile layer must not override Astro's natal/transit subject contract.
		// [2026-10-07 10:05 Johnny Chu - Chu Hoàng Anh] TWINBRAIN-SPLIT-CORE-ADDON W-3 — a vertical that owns subject resolution
		// (astro, in the add-on) says so through this filter; core no longer names Astro classes.
		$web_mode = sanitize_key( (string) ( $opts['web_mode'] ?? '' ) );
		if ( apply_filters( 'bizcity_twinbrain_subject_owned', false, $web_mode, (string) $prompt, $opts ) ) {
			$opts['_subject_profile_resolved'] = true;
			return $opts;
		}

		$user_id = isset( $opts['subject_id'] ) ? (int) $opts['subject_id'] : ( isset( $opts['user_id'] ) ? (int) $opts['user_id'] : (int) get_current_user_id() );
		if ( $user_id <= 0 || ! class_exists( 'BizCity_TwinBrain_Subject_Profile_Layer' ) ) {
			// [2026-07-27 Johnny Chu] PHASE-0.52 W3 — record the intentional skip so later stages do not retry profile lookup.
			$opts['_subject_profile_resolved'] = true;
			return $opts;
		}

		$event_base = array(
			'trace_id' => (string) $trace_id,
			'surface'  => self::SURFACE,
			'user_id'  => $user_id,
			'blog_id'  => (int) get_current_blog_id(),
		);
		$resolving = array_merge( $event_base, array(
			'template_slug' => sanitize_key( (string) ( $opts['profile_template_slug'] ?? '' ) ),
		) );
		if ( is_object( $sse ) && method_exists( $sse, 'emit' ) ) {
			$sse->emit( 'subject_profile_resolving', $resolving );
		}
		$this->emit_event( 'subject_profile_resolving', $resolving );

		try {
			$ctx = BizCity_TwinBrain_Subject_Profile_Layer::instance()->collect_for_user( $user_id, $prompt, $opts );
			if ( ! empty( $ctx['active'] ) && ! empty( $ctx['context_md'] ) ) {
				$opts['subject_context_md']    = (string) $ctx['context_md'];
				$opts['subject_context_label'] = 'HỒ SƠ CUSTOMER — xác định chủ thể trước khi trả lời';
				$opts['subject_profile_meta']  = $ctx;
			} elseif ( 'profile_missing_required' === (string) ( $ctx['degraded_reason'] ?? '' ) && '' !== trim( (string) ( $ctx['context_md'] ?? '' ) ) ) {
				// [2026-07-19 Johnny Chu] PHASE-TWIN-GPT-PROFILE-GROUNDING — tell composer that the subject is not resolved; never let WP display name stand in for a baby/customer casefile.
				$opts['subject_context_md'] = "## CHƯA XÁC ĐỊNH ĐƯỢC CHỦ THỂ CỤ THỂ\n"
					. "- Hồ sơ AI của template đang chọn chưa có câu trả lời thực tế.\n"
					. "- Không dùng tên tài khoản WordPress hoặc display_name làm chủ thể thay cho hồ sơ em bé/customer.\n"
					. "- Nếu câu hỏi cần cá nhân hóa, nói rõ chưa đủ hồ sơ và gợi ý user hoàn tất Hồ sơ AI.\n\n"
					. (string) $ctx['context_md'];
				$opts['subject_context_label'] = 'HỒ SƠ CUSTOMER — chưa xác định được chủ thể';
				$opts['subject_profile_meta']  = $ctx;
			}

			$event_name = ! empty( $ctx['active'] ) ? 'subject_profile_resolved' : 'subject_profile_degraded';
			$event = array_merge( $event_base, array(
				'active'           => ! empty( $ctx['active'] ),
				'template_slug'    => (string) ( $ctx['template_slug'] ?? '' ),
				'facts_count'      => (int) ( $ctx['facts_count'] ?? 0 ),
				'missing_required' => (array) ( $ctx['missing_required'] ?? array() ),
				'degraded_reason'  => (string) ( $ctx['degraded_reason'] ?? '' ),
				'latency_ms'       => (int) ( $ctx['latency_ms'] ?? 0 ),
			) );
			if ( is_object( $sse ) && method_exists( $sse, 'emit' ) ) {
				$sse->emit( $event_name, $event );
			}
			$this->emit_event( $event_name, $event );
		} catch ( \Throwable $e ) {
			$event = array_merge( $event_base, array(
				'active'          => false,
				'template_slug'   => '',
				'facts_count'     => 0,
				'degraded_reason' => 'profile_layer_exception',
				'error'           => $e->getMessage(),
			) );
			if ( is_object( $sse ) && method_exists( $sse, 'emit' ) ) {
				$sse->emit( 'subject_profile_degraded', $event );
			}
			$this->emit_event( 'subject_profile_degraded', $event );
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[TwinBrain][subject_profile_layer] trace=' . $trace_id . ' ' . $e->getMessage() );
			}
			self::write_runtime_log( 'error', 'subject_profile_exception', $e->getMessage(), array(
				'trace_id' => $trace_id,
				'surface'  => self::SURFACE,
				'channel'  => (string) ( $opts['channel'] ?? '' ),
				'exception_class' => get_class( $e ),
			) );
		}

		// [2026-07-27 Johnny Chu] PHASE-0.52 W3 — mark profile resolution complete so later stages do not re-read user meta.
		$opts['_subject_profile_resolved'] = true;
		return $opts;
	}

	/**
	 * Run the default multimodal intake layer before Notebook/RAG/final compose.
	 *
	 * @param string                 $trace_id Trace ID.
	 * @param string                 $prompt User prompt.
	 * @param array                  $opts Runtime options.
	 * @param BizCity_Twin_SSE_Writer|null $sse SSE writer.
	 * @return array
	 */
	private function collect_multimodal_intake_context( $trace_id, $prompt, array $opts, $sse = null ) {
		// [2026-07-19 Johnny Chu] PHASE-TBR-NB-MULTIMODAL — subject -> attachment/vision/file -> downstream core flow.
		if ( ! class_exists( 'BizCity_TwinBrain_Multimodal_Intake_Layer' ) ) {
			// [2026-07-19 Johnny Chu] PHASE-TBR-NB-MULTIMODAL — runtime may be included directly; self-load intake instead of silently skipping Vision.
			$intake_file = __DIR__ . '/class-twinbrain-multimodal-intake-layer.php';
			if ( is_readable( $intake_file ) ) {
				require_once $intake_file;
			}
		}
		if ( ! class_exists( 'BizCity_TwinBrain_Multimodal_Intake_Layer' ) ) {
			$has_attachment_context = ! empty( $opts['attachments'] ) || false !== strpos( (string) $prompt, '[TWIN_GPT_ATTACHMENTS]' );
			if ( $has_attachment_context ) {
				$payload = array(
					'trace_id'      => (string) $trace_id,
					'degraded'      => true,
					'reason_bucket' => 'multimodal_intake_class_missing',
				);
				if ( is_object( $sse ) && method_exists( $sse, 'emit' ) ) {
					$sse->emit( 'multimodal_ingest_degraded', $payload );
				}
				$this->emit_event( 'multimodal_ingest_degraded', array_merge( array( 'surface' => self::SURFACE ), $payload ) );
			}
			return $opts;
		}

		$emit = function ( $event_key, array $payload ) use ( $sse ) {
			if ( is_object( $sse ) && method_exists( $sse, 'emit' ) ) {
				$sse->emit( (string) $event_key, $payload );
			}
			$this->emit_event( (string) $event_key, array_merge( array( 'surface' => self::SURFACE ), $payload ) );
		};

		try {
			return BizCity_TwinBrain_Multimodal_Intake_Layer::instance()->collect( $trace_id, $prompt, $opts, $emit );
		} catch ( \Throwable $e ) {
			$payload = array(
				'trace_id'      => (string) $trace_id,
				'degraded'      => true,
				'reason_bucket' => 'multimodal_intake_exception',
				'error'         => $e->getMessage(),
			);
			if ( is_object( $sse ) && method_exists( $sse, 'emit' ) ) {
				$sse->emit( 'multimodal_ingest_degraded', $payload );
			}
			$this->emit_event( 'multimodal_ingest_degraded', array_merge( array( 'surface' => self::SURFACE ), $payload ) );
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[TwinBrain][multimodal_intake] trace=' . $trace_id . ' ' . $e->getMessage() );
			}
			self::write_runtime_log( 'error', 'multimodal_intake_exception', $e->getMessage(), array(
				'trace_id' => $trace_id,
				'surface'  => self::SURFACE,
				'channel'  => (string) ( $opts['channel'] ?? '' ),
				'attachment_count' => count( (array) ( $opts['attachments'] ?? array() ) ),
				'exception_class' => get_class( $e ),
			) );
			return $opts;
		}
	}

	/**
	 * Begin a brain turn. Returns a trace_id the caller can subscribe to via SSE.
	 *
	 * @param string $prompt
	 * @param array  $opts { user_id, k, force_notebooks[], force_tools[], skip_tool_intent? }
	 * @return array { ok, trace_id, sse_url, candidates, tool_candidates }
	 */
	public function start_turn( string $prompt, array $opts = [] ): array {
		// [2026-09-15 Johnny Chu - Chu Hoàng Anh] PHASE-1.33C — collect real server stage timings so the MPR timeline can attribute pre-MPR cost instead of showing an empty row.
		$stage_t0 = microtime( true );
		$stage_timings = array();
		// [2026-08-01 Johnny Chu] PHASE-1.26-CORRELATION — continue the inbound
		// channel trace when the current request already has a pending root;
		// standalone TwinBrain turns still get a fresh trace as before.
		$trace_id = trim( (string) ( $opts['trace_id'] ?? '' ) );
		if ( $trace_id === '' && class_exists( 'BizCity_Chat_Correlation' ) ) {
			$trace_id = BizCity_Chat_Correlation::pending_trace_id();
		}
		if ( $trace_id === '' ) {
			$trace_id = $this->new_trace_id();
		}
		$actor_user_id = isset( $opts['user_id'] ) ? (int) $opts['user_id'] : get_current_user_id();
		$subject_id    = $this->resolve_subject_id( $actor_user_id, $opts );
		$opts['subject_id'] = $subject_id;
		$user_id       = $actor_user_id;
		$opts = $this->resolve_identity_context( $opts, $actor_user_id );
		// [2026-08-01 Johnny Chu] PHASE-TWIN-MPR-SUBJECT-G0 — resolve channel
		// subject before profile, memory, notebook, or MPR perspective work.
		$subject_contract = class_exists( 'BizCity_Memory_Identity_Scope' )
			? BizCity_Memory_Identity_Scope::resolve_subject( $opts )
			: array();
		$opts['subject_contract'] = $subject_contract;
		if ( ! empty( $subject_contract['identity_uuid'] ) ) {
			$opts['identity_uuid'] = (string) $subject_contract['identity_uuid'];
		}
		if ( ! empty( $subject_contract['wp_user_id'] ) ) {
			$opts['subject_id'] = (int) $subject_contract['wp_user_id'];
			$subject_id = (int) $subject_contract['wp_user_id'];
		}
		// [2026-08-01 Johnny Chu] PHASE-TWIN-MPR-SUBJECT-G0 — session context
		// must be attached before the subject milestone enters the timeline.
		$this->current_session_id = (string) ( $opts['session_id'] ?? '' );
		$this->emit_event( 'decision', array(
			'trace_id'  => $trace_id,
			'stage'     => 'subject_resolved',
			'subject'   => $subject_contract,
			'channel'   => (string) ( $subject_contract['channel'] ?? '' ),
			'identity'  => (string) ( $subject_contract['identity_uuid'] ?? '' ),
		) );
		// [2026-08-07 Johnny Chu] V4-TRIAGE — resolve the global depth config before the lightweight route decision so every branch carries one normalized tier.
		$answer_depth_cfg = $this->resolve_answer_depth_config( $opts );
		$opts['_answer_depth_cfg'] = $answer_depth_cfg;
		if ( class_exists( 'BizCity_TwinBrain_Temporal_Context_Resolver' ) ) {
			// [2026-08-16 Johnny Chu] MPR-V5-TEMPORAL — resolve prompt time scope before triage/Goal stages and keep it deterministic.
			$opts['temporal_context'] = BizCity_TwinBrain_Temporal_Context_Resolver::resolve( $prompt, $opts );
			$this->emit_event( 'decision', array(
				'trace_id'    => $trace_id,
				'stage'       => 'temporal_context_resolved',
				'stage_version' => self::MPR_GATE_STAGE_VERSION,
				'timezone'    => (string) ( $opts['temporal_context']['timezone'] ?? 'UTC' ),
				'range_start' => (string) ( $opts['temporal_context']['range_start'] ?? '' ),
				'range_end'   => (string) ( $opts['temporal_context']['range_end'] ?? '' ),
				'granularity' => (string) ( $opts['temporal_context']['granularity'] ?? 'none' ),
				'reason_code' => (string) ( $opts['temporal_context']['reason_code'] ?? 'not_requested' ),
			) );
		}
		$this->emit_event( 'decision', array(
			'trace_id'            => $trace_id,
			'stage'               => 'answer_depth_resolved',
			'stage_version'       => self::MPR_GATE_STAGE_VERSION,
			'depth'               => $answer_depth_cfg['depth'],
			'skip_goal_parser'    => $answer_depth_cfg['skip_goal_parser'],
			'skip_reflection'     => $answer_depth_cfg['skip_reflection'],
			'max_retrieve_rounds' => $answer_depth_cfg['max_retrieve_rounds'],
		) );
		// [2026-10-07 10:05 Johnny Chu - Chu Hoàng Anh] TWINBRAIN-SPLIT-CORE-ADDON Q-2 — Pre_MPR_Triage (one LLM call per turn, "ambiguous"
		// canned opening) archived; every turn runs MPR. prompt_intent stays an empty array for the Tool Intent Matcher and bridges.
		$opts['prompt_intent'] = array();
		// [2026-10-06 23:24 Johnny Chu - Chu Hoàng Anh] TWINBRAIN-SPLIT-CORE-ADDON W-1 — Goal Loop pre_turn removed (archived); the
		// goal_loop* blocks below only fire when a caller still passes opts.goal_loop and stay inert otherwise.
		if ( ! empty( $opts['goal_loop_blocked'] ) && is_array( $opts['goal_loop_blocked'] ) ) {
			// [2026-08-16 Johnny Chu] MPR-V5-GOAL-SESSION — expose blocked cross-session choice without opening a duplicate Goal.
			$blocked_goal = (array) $opts['goal_loop_blocked'];
			$this->emit_event( 'decision', array(
				'trace_id'      => $trace_id,
				'stage'         => 'goal_session_decided',
				'stage_version' => self::MPR_GATE_STAGE_VERSION,
				'goal_id'       => (string) ( $blocked_goal['goal_id'] ?? '' ),
				'action'        => 'blocked',
				'reason_code'   => (string) ( $blocked_goal['reason_code'] ?? 'active_goal_in_another_session' ),
			) );
			$this->emit_event( 'decision', array(
				'trace_id'      => $trace_id,
				'stage'         => 'goal_session_blocked',
				'stage_version' => self::MPR_GATE_STAGE_VERSION,
				'goal_id'       => (string) ( $blocked_goal['goal_id'] ?? '' ),
				'status'        => 'blocked',
				'reason_code'   => (string) ( $blocked_goal['reason_code'] ?? 'active_goal_in_another_session' ),
			) );
		}
		if ( ! empty( $opts['goal_loop'] ) && is_array( $opts['goal_loop'] ) ) {
			// [2026-08-15 Johnny Chu] MPR-V5-GOAL-TRACE — expose Goal Draft and Goal Session lifecycle before notebook/tool stages.
			$goal_state = (array) $opts['goal_loop'];
			$this->emit_event( 'decision', array(
				'trace_id'       => $trace_id,
				'stage'          => 'goal_draft_ready',
				'stage_version'  => self::MPR_GATE_STAGE_VERSION,
				'goal_id'        => (string) ( $goal_state['goal_id'] ?? '' ),
				'goal_title'     => (string) ( $goal_state['goal_title'] ?? 'Mục tiêu đang xử lý' ),
				'primary_goal'   => (string) ( $goal_state['goal_title'] ?? '' ),
				'required_input_count' => count( (array) ( $goal_state['required_inputs'] ?? array() ) ),
				'obligation_count' => count( (array) ( $goal_state['answer_obligations'] ?? array() ) ),
				'status'         => (string) ( $goal_state['status'] ?? 'clarifying' ),
			) );
			$session_action = sanitize_key( (string) ( $opts['goal_loop_session_action'] ?? '' ) );
			if ( in_array( $session_action, array( 'opened', 'resumed' ), true ) ) {
				// [2026-08-16 Johnny Chu] MPR-V5-GOAL-SESSION — persist the lifecycle decision before the opened/resumed milestone.
				$this->emit_event( 'decision', array(
					'trace_id'      => $trace_id,
					'stage'         => 'goal_session_decided',
					'stage_version' => self::MPR_GATE_STAGE_VERSION,
					'goal_id'       => (string) ( $goal_state['goal_id'] ?? '' ),
					'action'        => $session_action === 'opened' ? 'open' : 'resume',
					'reason_code'   => $session_action === 'opened' ? 'new_goal' : 'active_goal_resumed',
				) );
				$this->emit_event( 'decision', array(
					'trace_id'      => $trace_id,
					'stage'         => 'goal_session_' . $session_action,
					'stage_version' => self::MPR_GATE_STAGE_VERSION,
					'goal_id'       => (string) ( $goal_state['goal_id'] ?? '' ),
					'goal_title'    => (string) ( $goal_state['goal_title'] ?? 'Mục tiêu đang xử lý' ),
					'current_session_id' => (string) ( $goal_state['session_id'] ?? $opts['session_id'] ?? '' ),
					'previous_session_id' => (string) ( $opts['goal_loop_resume_from_session_id'] ?? '' ),
					'status'        => (string) ( $goal_state['status'] ?? 'clarifying' ),
				) );
			}
		}
		// [2026-08-01 Johnny Chu] PHASE-TWIN-GOAL-LOOP-G1 — expose one normalized
		// goal state to every downstream TwinBrain layer and memory writer.
		if ( ! empty( $opts['goal_loop'] ) && is_array( $opts['goal_loop'] ) ) {
			// [2026-08-03 Johnny Chu] R-MPR-GOALBOARD — expose the frozen contract to every MPR layer without creating a second state store.
			$opts['goal_contract'] = array(
				'conversation_goal' => $opts['goal_loop']['conversation_goal'] ?? null,
				'answer_obligations' => (array) ( $opts['goal_loop']['answer_obligations'] ?? array() ),
				'resolution_scoreboard' => $opts['goal_loop']['resolution_scoreboard'] ?? null,
			);
			$this->emit_event( 'decision', array(
				'trace_id'            => $trace_id,
				'stage'               => 'goal_contract_ready',
				'goal_id'             => (string) ( $opts['goal_loop']['goal_id'] ?? '' ),
				'scoreboard_version'  => (string) ( $opts['goal_loop']['resolution_scoreboard']['scoreboard_version'] ?? 'v1' ),
				'obligation_count'    => count( (array) ( $opts['goal_loop']['answer_obligations'] ?? array() ) ),
				'conversation_mode'   => (string) ( $opts['goal_loop']['conversation_goal']['conversation_mode'] ?? '' ),
				'contract'            => $opts['goal_contract'],
			) );
		}
		// [2026-06-04 Johnny Chu] BS-12 — bind session for durable turn persist.
		$this->current_session_id = (string) ( $opts['session_id'] ?? '' );
		// [2026-07-27 Johnny Chu] PHASE-0.52 W3 — resolve subject profile before notebook selection and memory recall.
		$opts = $this->collect_subject_profile_context( $trace_id, $prompt, $opts );
		$k        = max( 3, min( BIZCITY_TWINBRAIN_K_MAX, (int) ( $opts['k'] ?? BIZCITY_TWINBRAIN_K_DEFAULT ) ) );

		/* ============================================================
		 * PHASE-0.35 / Phase C.1 — Layer 0 token parse (R-MPRT-2).
		 * Peel leading `@guru` + `#tool` tokens before any selector runs.
		 * Caller may also pre-fill opts.guru_id / opts.tool_force.
		 * ========================================================== */
		$pre = array( 'guru_id' => 0, 'guru_label' => '', 'tool_force' => '', 'message_clean' => $prompt, 'tokens' => array() );
		if ( class_exists( 'BizCity_Guru_Token_Parser' ) ) {
			$pre = BizCity_Guru_Token_Parser::parse( $prompt );
		}
		$guru_id    = isset( $opts['guru_id'] )    ? (int) $opts['guru_id']        : (int) $pre['guru_id'];
		$tool_force = isset( $opts['tool_force'] ) ? (string) $opts['tool_force'] : (string) $pre['tool_force'];
		$prompt_eff = ( $pre['message_clean'] !== '' ) ? (string) $pre['message_clean'] : $prompt;

		/* R-MPRT-5 anti-jailbreak (AC #12): #tool with @guru must be in scope. */
		$guru_tools_whitelist = array();
		$reject_reason        = '';
		if ( $guru_id > 0 && class_exists( 'BizCity_Guru_Skill_Bridge' ) ) {
			$guru_tools_whitelist = BizCity_Guru_Skill_Bridge::tools_for_guru( $guru_id );
			if ( $tool_force !== '' && ! empty( $guru_tools_whitelist ) && ! in_array( $tool_force, $guru_tools_whitelist, true ) ) {
				$reject_reason = sprintf(
					"Tool `%s` không thuộc scope của guru `%s`. Các tool khả dụng: %s",
					$tool_force,
					$pre['guru_label'] !== '' ? $pre['guru_label'] : ( '#' . $guru_id ),
					implode( ', ', array_slice( $guru_tools_whitelist, 0, 8 ) )
				);
			}
		}

		$pre_rules_payload = array(
			'trace_id'    => $trace_id,
			'user_id'     => $user_id,
			'surface'     => self::SURFACE,
			'guru_id'     => $guru_id,
			'guru_label'  => (string) $pre['guru_label'],
			'tool_force'  => $tool_force,
			'tokens'      => (array) $pre['tokens'],
			'reject'      => $reject_reason,
		);
		$this->emit_event( 'pre_rules_done', $pre_rules_payload );

		/* PHASE-0.35 / F7.C4.1 — Devlog parity: build `guru_lookup` payload so the
		 * FE console adapter + Timeline UI can render the resolved guru context
		 * (parity with twinchat `[TwinChat][guru_lookup]`). */
		$guru_lookup_payload = array();
		if ( $guru_id > 0 ) {
			$lookup_t0 = microtime( true );
			$guru_lookup_payload = array(
				'trace_id'           => $trace_id,
				'surface'            => self::SURFACE,
				'character_id'       => $guru_id,
				'character_slug'     => (string) ( $pre['guru_slug']  ?? '' ),
				'character_name'     => (string) $pre['guru_label'],
				'l2_sources_count'   => 0, // wired when twinsearch ingest live (W4 RAG enrich)
				'l3_artifacts_count' => 0, // wired when notebook_id ctx forwarded
				'sticky_source'      => 'mention',
				'guru_tools_count'   => count( $guru_tools_whitelist ),
				'guru_tools'         => $guru_tools_whitelist,
				'tool_force'         => $tool_force,
				'latency_ms'         => (int) ( ( microtime( true ) - $lookup_t0 ) * 1000 ),
			);
			$this->emit_event( 'guru_lookup', $guru_lookup_payload );
		}

		if ( $reject_reason !== '' ) {
			return array(
				'ok'              => false,
				'trace_id'        => $trace_id,
				'error'           => 'guru_tool_out_of_scope',
				'message'         => $reject_reason,
				'guru_id'         => $guru_id,
				'tool_force'      => $tool_force,
				'guru_tools'      => $guru_tools_whitelist,
			);
		}

		$this->emit_event( 'user_message', [
			'trace_id' => $trace_id,
			'user_id'  => $user_id,
			'surface'  => self::SURFACE,
			'text'     => $prompt_eff,
			'guru_id'  => $guru_id,
		] );

		/* TBR.SEL-LEX (2026-05-22) — Tokenize prompt ONCE here, share across
		 * all downstream stages regardless of web_mode (off / quick / deep /
		 * social / company). Used by: selector keyword tier, perspective
		 * runner passage rerank, FE highlight (matched_tokens). */
		$keyword_tokens = BizCity_TwinBrain_Notebook_Selector::tokenize_for_search( $prompt_eff );
		$this->emit_event( 'brain_keywords', [
			'trace_id' => $trace_id,
			'tokens'   => $keyword_tokens,
			'count'    => count( $keyword_tokens ),
		] );

		/* [2026-07-24 Johnny Chu] PHASE-0.46 W5 S5.2 — "trong ghi chú hôm nay"
		 * scoped querying. If the caller didn't already force specific
		 * notebooks (explicit @notebook UI pin, automation, etc.) AND the
		 * prompt itself asks about "today's notebook/note" in plain Vietnamese,
		 * force-scope retrieval to exactly what the channel-capture bridge
		 * (PHASE-0.46 Wave 1-4) auto-created for this user today — no new
		 * retrieval mechanism, just an explicit override already supported by
		 * Notebook_Selector::select()'s `force_ids` opt (§2.5 Wave 5 design).
		 * Falls through to normal cosine/keyword/recency selection when the
		 * phrase isn't detected or nothing was captured today. */
		$force_notebooks = (array) ( $opts['force_notebooks'] ?? [] );
		if ( empty( $force_notebooks ) && class_exists( 'BizCity_KG_Channel_Notebook_Bridge' ) ) {
			if ( $this->is_day_notebook_summary_query( $prompt_eff ) ) {
				$today_ids = BizCity_KG_Channel_Notebook_Bridge::find_day_notebooks( $user_id );
				if ( ! empty( $today_ids ) ) {
					$force_notebooks = $today_ids;
				}
			}
		}

		// [2026-08-16 Johnny Chu] PHASE-TWB-WOO-BIZOPS — Vertical Plugin owns its data query; do not select unrelated notebook lenses first.
		$direct_vertical_mode = strtolower( (string) ( $opts['web_mode'] ?? 'off' ) );
		$owns_data = self::owns_data_query( $direct_vertical_mode ); // [2026-10-07 10:05 Johnny Chu - Chu Hoàng Anh] W-2 — was === 'woo_bizops'.
		if ( $owns_data ) {
			$candidates = array();
		} else {
			// [2026-09-15 Johnny Chu - Chu Hoàng Anh] PHASE-1.33C — measure Stage 1A alone; it may issue prompt embeddings for cosine then density tiers.
			$selector_t0 = microtime( true );
			// Stage 1A — notebook selector (parallel-conceptually; sequential in PHP).
			$selector   = BizCity_TwinBrain_Notebook_Selector::instance();
			$candidates = $selector->select( $prompt_eff, $user_id, $k, [
				'force_ids' => $force_notebooks,
				// PHASE-0.35 / F7.D2 — forward guru context so selector can
				// prioritise notebooks bound to the active guru (character_id =
				// guru_id) before falling through cosine → density → recency.
				// Without this, Ask Brain (whole KG) ignores `@tarot` and
				// returns 5 unrelated notebooks → synthesizer says "no notebook
				// can interpret Tarot".
				'guru_id'   => $guru_id,
			] );
			$stage_timings['candidate_selection'] = (int) round( ( microtime( true ) - $selector_t0 ) * 1000 );
		}
		$this->emit_event( 'brain_perspective_selected', [
			'trace_id'              => $trace_id,
			'k'                     => count( $candidates ),
			'candidates'            => $candidates,
			'keyword_tokens'        => $keyword_tokens,
			'prompt_embedding_hash' => substr( hash( 'sha256', $prompt_eff ), 0, 12 ),
		] );

		/* PHASE-0.35 / F7.C4.1 — Devlog parity: emit `guru_layer` summarising
		 * which knowledge layers fed this turn (mirror twinchat `[TwinChat][guru_layer]`).
		 * L1 = guru system_prompt length, L2 = guru-scoped sources (TODO when
		 * RAG enrich live), L3 = candidate notebooks selected as perspective. */
		$guru_layer_payload = array();
		if ( $guru_id > 0 ) {
			$l1_len     = 0;
			$l1_preview = '';
			global $wpdb;
			$tbl    = $wpdb->prefix . 'bizcity_characters';
			$prev   = $wpdb->suppress_errors( true );
			$prompt_text = $wpdb->get_var( $wpdb->prepare(
				"SELECT system_prompt FROM {$tbl} WHERE id = %d LIMIT 1",
				$guru_id
			) );
			$wpdb->suppress_errors( $prev );
			if ( is_string( $prompt_text ) ) {
				$l1_len     = strlen( $prompt_text );
				$l1_preview = mb_substr( $prompt_text, 0, 240 );
			}
			$guru_layer_payload = array(
				'trace_id'         => $trace_id,
				'surface'          => self::SURFACE,
				'character_id'     => $guru_id,
				'character_name'   => (string) $pre['guru_label'],
				'l1_preview_len'   => $l1_len,
				'l1_preview'       => $l1_preview,
				'l2_count'         => 0,
				'l3_count'         => count( $candidates ),
				'via'              => 'notebook_selector(scope=twin_brain)',
			);
			$this->emit_event( 'guru_layer', $guru_layer_payload );
		}

		// Stage 1B — tool intent matcher (skip if explicit @@mention or opt out).
		// Phase C: forward guru_id + merge tool_force into force_tools.
		$tool_candidates = [];
		$force_tools     = (array) ( $opts['force_tools'] ?? [] );
		if ( $tool_force !== '' ) {
			$force_tools[] = $tool_force;
		}
		$force_tools = array_values( array_unique( array_filter( array_map( 'strval', $force_tools ) ) ) );

		if ( ! $owns_data && empty( $opts['skip_tool_intent'] ) && ! $this->has_explicit_skill_mention( $prompt_eff ) ) {
			$matcher         = BizCity_TwinBrain_Tool_Intent_Matcher::instance();
			$tool_candidates = $matcher->match( $prompt_eff, $user_id, [
				'force_slugs'   => $force_tools,
				'guru_id'       => $guru_id,
				'prompt_intent' => (array) ( $opts['prompt_intent'] ?? $opts['pre_mpr_triage'] ?? array() ),
			] );
			$this->emit_event( 'brain_tool_intent', [
				'trace_id'        => $trace_id,
				'k'               => count( $tool_candidates ),
				'candidates'      => $tool_candidates,
				'threshold'       => BIZCITY_TWINBRAIN_TOOL_INTENT_THRESHOLD,
				'guru_id'         => $guru_id,
				'guru_tool_count' => count( $guru_tools_whitelist ),
			] );
		}

		/* PHASE 0.36-UNIFIED Wave 2.8 (TBR.MEM-3) — Layer 0.5 Memory Recall.
		 * Runs after Pre-rules / Guru lookup so the recall can later be
		 * scoped to the bound guru (deferred to MEM-5). Block + counts
		 * are returned in `$start['memory_*']` for REST to re-emit + for
		 * the Final_Composer (Layer 4.5) to inject into its system prompt.
		 *
		 * Failures are swallowed — never block a turn for a recall miss. */
		$memory_block      = '';
		$memory_recall_evt = [];
		$memory_citations  = [];
		// [2026-08-03 Johnny Chu] R-TGL-CS — carry the active Goal/Case boundary into Layer 0.5; legacy goals without case_id remain observable but are not silently relabeled as scoped.
		$goal_loop_state = is_array( $opts['goal_loop_state'] ?? null ) ? $opts['goal_loop_state'] : ( is_array( $opts['goal_loop'] ?? null ) ? $opts['goal_loop'] : array() );
		$memory_scope    = sanitize_key( (string) ( $opts['memory_scope'] ?? $goal_loop_state['memory_scope'] ?? '' ) );
		$case_id         = sanitize_text_field( (string) ( $opts['case_id'] ?? $goal_loop_state['case_id'] ?? '' ) );
		$subject_key     = sanitize_text_field( (string) ( $opts['subject_key'] ?? $goal_loop_state['subject_key'] ?? '' ) );
		$goal_id         = sanitize_text_field( (string) ( $opts['goal_id'] ?? $goal_loop_state['goal_id'] ?? '' ) );
		if ( class_exists( 'BizCity_TwinBrain_Memory_Recall' ) ) {
			// [2026-09-15 Johnny Chu - Chu Hoàng Anh] PHASE-1.33C — measure Layer 0.5 so the timeline reports its own cost.
			$memory_t0 = microtime( true );
			try {
				$mem_res = BizCity_TwinBrain_Memory_Recall::instance()->collect( $subject_id, $prompt_eff, [
					'keyword_tokens' => $keyword_tokens,
					// [2026-07-28 Johnny Chu] R-CH-IDMEM — preserve the active turn session through Layer 0.5 recall.
					'session_id'     => (string) ( $opts['session_id'] ?? '' ),
					'identity_uuid'  => (string) ( $opts['identity_uuid'] ?? '' ),
					'identity_guest_bind' => ! empty( $opts['identity_guest_bind'] ),
					// [2026-07-28 Johnny Chu] PHASE-0.52 W2 — preserve channel identity for no-owner link UX on agent turns.
					'platform'          => (string) ( $opts['platform'] ?? $opts['channel'] ?? '' ),
					'channel'           => (string) ( $opts['channel'] ?? '' ),
					'account_id'        => (string) ( $opts['account_id'] ?? '' ),
					'external_user_id'  => (string) ( $opts['external_user_id'] ?? '' ),
					'chat_id'           => (string) ( $opts['chat_id'] ?? '' ),
					'memory_scope'      => $memory_scope,
					'case_id'           => $case_id,
					'subject_key'       => $subject_key,
					'goal_id'           => $goal_id,
				] );
				$memory_block     = (string) ( $mem_res['block']     ?? '' );
				$memory_citations = (array)  ( $mem_res['citations'] ?? [] );
				$memory_recall_evt = [
					'trace_id'   => $trace_id,
					'surface'    => self::SURFACE,
					'counts'     => (array) ( $mem_res['counts'] ?? [ 'A' => 0, 'B' => 0, 'C' => 0, 'D' => 0 ] ),
					'citations'  => $memory_citations,
					'block_len'  => mb_strlen( $memory_block ),
					'latency_ms' => (int) ( $mem_res['latency_ms'] ?? 0 ),
					'memory_scope' => (string) ( $mem_res['memory_scope'] ?? $memory_scope ),
					'case_id'      => (string) ( $mem_res['case_id'] ?? $case_id ),
					'goal_id'      => (string) ( $mem_res['goal_id'] ?? $goal_id ),
					'scope_state'  => (string) ( $mem_res['scope_state'] ?? ( $case_id !== '' ? 'resolved' : 'unresolved' ) ),
				];
				// [2026-08-03 Johnny Chu] P2 — emit the taxonomy-registered memory recall audit event.
				$this->emit_event( BizCity_Twin_Event_Taxonomy::MEMORY_RECALL, $memory_recall_evt );
			} catch ( \Throwable $e ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( '[TwinBrain][memory_recall][error] ' . $e->getMessage() );
				}
				self::write_runtime_log( 'error', 'memory_recall_exception', $e->getMessage(), array(
					'trace_id' => $trace_id,
					'surface'  => self::SURFACE,
					'channel'  => (string) ( $opts['channel'] ?? '' ),
					'notebook_id' => (int) ( $opts['notebook_id'] ?? 0 ),
					'exception_class' => get_class( $e ),
				) );
			}
			$stage_timings['memory_recall'] = (int) round( ( microtime( true ) - $memory_t0 ) * 1000 );
		}

		// [2026-10-07 10:05 Johnny Chu - Chu Hoàng Anh] TWINBRAIN-SPLIT-CORE-ADDON Q-2 — Intent_Compat_Adapter envelope removed;
		// result keys intent_compat / pre_mpr_triage / ambiguous_no_goal stay (empty) for callers that echo them.

		return [
			'ok'              => true,
			'trace_id'        => $trace_id,
			'sse_url'         => rest_url( 'bizcity-twin/v1/stream' ) . '?trace_id=' . rawurlencode( $trace_id ),
			'candidates'      => $candidates,
			'tool_candidates' => $tool_candidates,
			'keyword_tokens'  => $keyword_tokens,
			'guru_id'         => $guru_id,
			'guru_label'      => (string) $pre['guru_label'],
			'tool_force'      => $tool_force,
			'guru_tools'      => $guru_tools_whitelist,
			/* PHASE-0.35 / F7.C4.1 — payloads for REST to re-emit as native SSE. */
			'pre_rules'       => $pre_rules_payload,
			'guru_lookup'     => $guru_lookup_payload,
			'guru_layer'      => $guru_layer_payload,
			/* PHASE 0.36-UNIFIED Wave 2.8 — memory recall block + telemetry. */
			'memory_block'    => $memory_block,
			'memory_recall'   => $memory_recall_evt,
			// [2026-07-27 Johnny Chu] PHASE-0.52 W3 — carry resolved profile context into stage 2 without another meta read.
			'subject_context_md'    => (string) ( $opts['subject_context_md'] ?? '' ),
			'subject_context_label' => (string) ( $opts['subject_context_label'] ?? '' ),
			'subject_id'            => $subject_id,
			'subject_contract'      => (array) ( $opts['subject_contract'] ?? array() ),
			'identity_uuid'         => (string) ( $opts['identity_uuid'] ?? '' ),
			'identity_state'        => (string) ( $opts['identity_state'] ?? 'unknown' ),
			'goal_loop_state'       => (array) ( $opts['goal_loop_state'] ?? array() ),
			'goal_contract'         => (array) ( $opts['goal_contract'] ?? array() ), // [2026-08-05 Johnny Chu] V3.1 — preserve frozen Goal Contract for streaming callers.
			'goal_loop_brief'       => (string) ( $opts['goal_loop_brief'] ?? '' ),
			'goal_loop_blocked'     => (array) ( $opts['goal_loop_blocked'] ?? array() ),
			'goal_alignment'        => (array) ( $opts['goal_alignment'] ?? array() ),
			'prompt_intent'         => (array) ( $opts['prompt_intent'] ?? array() ),
			'intent_compat'         => (array) ( $opts['intent_compat'] ?? array() ),
			'temporal_context'      => (array) ( $opts['temporal_context'] ?? array() ),
			'answer_depth'          => (string) ( $answer_depth_cfg['depth'] ?? self::ANSWER_DEPTH_DEFAULT ), // [2026-08-06 Johnny Chu] V4-DEPTH — echo resolved depth tier so REST/stream callers forward the same value into complete_turn_stream().
			'pre_mpr_triage'        => (array) ( $opts['pre_mpr_triage'] ?? array() ), // [2026-08-07 Johnny Chu] V4-TRIAGE — preserve the pre-MPR branch decision for completion callers.
			// [2026-09-15 Johnny Chu - Chu Hoàng Anh] PHASE-1.33C — expose start-stage timings and total so REST/live timeline can attribute pre-MPR cost.
			'stage_timings'         => array_merge( $stage_timings, array( 'start_turn_total' => (int) round( ( microtime( true ) - $stage_t0 ) * 1000 ) ) ),
			'ambiguous_no_goal'     => ! empty( $opts['ambiguous_no_goal'] ),
			'goal_loop_pre_turn_completed' => ! $answer_depth_cfg['skip_goal_parser'], // [2026-08-07 Johnny Chu] V4-DEPTH — prevent synchronous completion from parsing the same turn twice.
			'memory_scope'          => $memory_scope,
			'case_id'               => $case_id,
			'subject_key'           => $subject_key,
			'_subject_profile_resolved' => ! empty( $opts['_subject_profile_resolved'] ),
		];
	}

	/**
	 * Continue a turn after Stage 1 by running parallel sub-agents and synthesis.
	 * Wave 0: synchronous, no actual fan-out yet — emits skeleton events so the
	 * FE timeline reducer can be developed against the final shape.
	 */
	public function complete_turn( string $trace_id, string $prompt, array $candidates, array $tool_candidates = [], array $opts = [] ): array {
		// [2026-06-04 Johnny Chu] BS-12 — bind session for durable turn persist.
		$this->current_session_id = (string) ( $opts['session_id'] ?? '' );
		$opts = $this->resolve_identity_context( $opts, (int) ( $opts['user_id'] ?? get_current_user_id() ) );
		// [2026-08-03 Johnny Chu] R-TGL-CS — Brain Chat is the canonical full-MPR
		// path. Legacy web_mode=chat requests normalize to off; only an internal
		// casual_fast_path may set companion_mode explicitly.
		if ( strtolower( (string) ( $opts['web_mode'] ?? 'off' ) ) === 'chat' ) {
			$opts['web_mode'] = 'off';
		}
		// [2026-10-01 Claude Opus 5.5] PHASE-0.87 CL-8 — the non-stream path never routed `web_mode=astro`: a headless caller
		// (deep analysis, channel adapter) silently ran the generic MPR without the Astro engine. Reuse the stream pipeline
		// with a capture-only writer inside our own buffer (same pattern as dispatch_web_research below).
		// [2026-10-07 10:05 Johnny Chu - Chu Hoàng Anh] TWINBRAIN-SPLIT-CORE-ADDON W-3 — whole-turn verticals (astro) live in the
		// add-on; generic takeover instead of a hard stream_astro_mode() call.
		if ( in_array( strtolower( (string) ( $opts['web_mode'] ?? 'off' ) ), self::takeover_modes(), true )
			&& class_exists( 'BizCity_Twin_SSE_Writer' ) && method_exists( 'BizCity_Twin_SSE_Writer', 'capture' ) ) {
			$takeover_sse = BizCity_Twin_SSE_Writer::capture();
			ob_start();
			try {
				$takeover = $this->vertical_takeover( $trace_id, $prompt, $takeover_sse, $opts, microtime( true ) );
			} finally {
				ob_end_clean();
			}
			if ( is_array( $takeover ) ) {
				$takeover['trace_id'] = $trace_id;
				return $takeover;
			}
		}
		// [2026-10-06 23:24 Johnny Chu - Chu Hoàng Anh] TWINBRAIN-SPLIT-CORE-ADDON W-1 — Goal Loop pre_turn removed (archived).
		$runner      = BizCity_TwinBrain_Perspective_Runner::instance();
		$answers      = $runner->run( $trace_id, $prompt, $candidates, $opts );

		// [2026-07-19 Johnny Chu] PHASE-TWIN-GPT-PROFILE-GROUNDING — resolve customer subject before Notebook Source Layer.
		$opts = $this->collect_subject_profile_context( $trace_id, $prompt, $opts );

		$notebook_source_payload = array(
			'notebook_source_map'      => array(),
			'notebook_source_block_md' => '',
			'notebook_source_counts'   => array( 'notebook_count' => 0, 'passage_count' => 0, 'strong_count' => 0, 'weak_count' => 0 ),
			'source_file_briefs'       => array(),
			'source_file_counts'       => array( 'source_file_count' => 0, 'with_relations_count' => 0, 'weak_count' => 0 ),
			'search_context'           => array( 'query' => '', 'scope' => '', 'total' => 0, 'top_n' => 0, 'tokens' => array(), 'results' => array() ),
		);
		// [2026-07-18 Johnny Chu] PHASE-TBR-NB-MOAT — non-stream parity for Notebook Source Layer.
		if ( class_exists( 'BizCity_TwinBrain_Notebook_Source_Layer' ) ) {
			try {
				// [2026-07-18 Johnny Chu] PHASE-TBR-NB-MOAT W0.9 — pass user prompt into Notebook Source Layer so Search Core can enrich LLM context.
				$opts['notebook_search_context_query'] = $prompt;
				// [2026-09-16 Johnny Chu - Chu Hoàng Anh] PHASE-1.33D-D3 — resolve the vertical binding first so a bound vertical can request hybrid before the mode default is applied.
				$opts = $this->resolve_vertical_binding( $opts );
				// [2026-09-15 Johnny Chu - Chu Hoàng Anh] PHASE-1.33C — resolve the canonical Context Bank MPR flag before the source layer runs; no caller previously set this option, so Context Bank never executed.
				$opts = $this->resolve_context_bank_opts( $opts );
				$notebook_source_payload = BizCity_TwinBrain_Notebook_Source_Layer::instance()->build_from_turn( $candidates, $answers, $opts );
				$opts['notebook_source_map']      = (array) ( $notebook_source_payload['notebook_source_map'] ?? array() );
				$opts['context_bank_source_refs'] = (array) ( $notebook_source_payload['graph_vector_rerank_pack']['context_bank_source_refs'] ?? array() );
				// [2026-09-13 11:29 AM Johnny Chu - Chu Hoàng Anh] PHASE-1.33B — forward bounded nested Context Bank metadata to non-stream MPR consumers.
				$opts['context_bank']             = (array) ( $notebook_source_payload['context_bank'] ?? array() );
				// [2026-09-02 11:29 AM Johnny Chu - Chu Hoàng Anh] PHASE-CB4.5 — keep authorized Skill owner records on the internal Synthesizer path.
				$opts['context_bank_owner_records'] = (array) ( $notebook_source_payload['context_bank_owner_records'] ?? array() );
				$opts['context_bank_retrieval']   = (array) ( $notebook_source_payload['graph_vector_rerank_pack']['context_bank_retrieval'] ?? array() );
				$opts['notebook_source_block_md'] = (string) ( $notebook_source_payload['notebook_source_block_md'] ?? '' );
				$opts['notebook_source_counts']   = (array) ( $notebook_source_payload['notebook_source_counts'] ?? array() );
				// [2026-07-18 Johnny Chu] PHASE-TBR-NB-MOAT — non-stream parity for W0.7 source-file briefs.
				$opts['source_file_briefs']       = (array) ( $notebook_source_payload['source_file_briefs'] ?? array() );
				$opts['source_file_counts']       = (array) ( $notebook_source_payload['source_file_counts'] ?? array() );
				// [2026-07-19 Johnny Chu] PHASE-TBR-NB-MOAT W0.10 — non-stream parity for top TwinSearch context hits.
				$opts['search_context']           = (array) ( $notebook_source_payload['search_context'] ?? array() );
				$opts['search_context_results']   = (array) ( $notebook_source_payload['search_context_results'] ?? array() );
				$opts['search_context_total']     = (int) ( $notebook_source_payload['search_context_total'] ?? 0 );
				// [2026-09-01 Johnny Chu] PHASE-0.45-W5 — preserve the canonical graph/retrieval pack for automation consumers.
				$opts['graph_vector_rerank_pack'] = (array) ( $notebook_source_payload['graph_vector_rerank_pack'] ?? array() );
				$opts['graph_entities']            = (array) ( $notebook_source_payload['graph_entities'] ?? array() );
				$opts['retrieval_candidates']      = (array) ( $notebook_source_payload['retrieval_candidates'] ?? array() );
				$opts['retrieval_candidate_count'] = (int) ( $notebook_source_payload['retrieval_candidate_count'] ?? count( $opts['retrieval_candidates'] ) );
				$opts['final_context_chunks']     = (array) ( $notebook_source_payload['final_context_chunks'] ?? array() );
				$opts['final_context_count']      = (int) ( $notebook_source_payload['final_context_count'] ?? count( $opts['final_context_chunks'] ) );
				$opts['product_entities']         = (array) ( $notebook_source_payload['product_entities'] ?? array() );
				$opts['product_entity_count']     = (int) ( $notebook_source_payload['product_entity_count'] ?? count( $opts['product_entities'] ) );
				$opts['product_name_entity_count'] = (int) ( $notebook_source_payload['product_name_entity_count'] ?? 0 );
				// [2026-07-19 Johnny Chu] PHASE-TBR-NB-MOAT W0.12 — non-stream parity for N4 cross-notebook links.
				$opts['cross_notebook_links']     = (array) ( $notebook_source_payload['cross_notebook_links'] ?? array() );
				// [2026-07-19 Johnny Chu] PHASE-TBR-NB-MOAT W0.13 — non-stream parity for N5 training gap report.
				$opts['training_gap_report']      = (array) ( $notebook_source_payload['training_gap_report'] ?? array() );
				$source_counts = (array) $opts['notebook_source_counts'];
				$source_file_counts = (array) $opts['source_file_counts'];
				$search_context = (array) $opts['search_context'];
				if ( (int) ( $source_counts['notebook_count'] ?? 0 ) > 0 || ! empty( $opts['context_bank'] ) ) {
					$this->emit_event( 'notebook_source_layer_ready', array(
						'trace_id'                 => $trace_id,
						'surface'                  => self::SURFACE,
						'notebook_count'           => (int) ( $source_counts['notebook_count'] ?? 0 ),
						'passage_count'            => (int) ( $source_counts['passage_count'] ?? 0 ),
						'strong_count'             => (int) ( $source_counts['strong_count'] ?? 0 ),
						'weak_count'               => (int) ( $source_counts['weak_count'] ?? 0 ),
						'source_file_count'        => (int) ( $source_file_counts['source_file_count'] ?? 0 ),
						'source_file_with_relations_count' => (int) ( $source_file_counts['with_relations_count'] ?? 0 ),
						'search_context_total'     => (int) ( $search_context['total'] ?? $opts['search_context_total'] ?? 0 ),
						'search_context_top_n'     => (int) ( $search_context['top_n'] ?? 0 ),
						'search_context_query'     => (string) ( $search_context['query'] ?? '' ),
						'search_context_scope'     => (string) ( $search_context['scope'] ?? '' ),
						'search_context_tokens'    => (array) ( $search_context['tokens'] ?? array() ),
						'search_context_results'   => (array) ( $search_context['results'] ?? $opts['search_context_results'] ?? array() ),
						// [2026-09-15 Johnny Chu - Chu Hoàng Anh] PHASE-1.33C — emit the bounded Context Bank status on the non-stream path so the timeline can show a footlog.
						'context_bank'             => (array) ( $opts['context_bank'] ?? array() ),
						'notebook_source_map'      => (array) $opts['notebook_source_map'],
						'source_file_briefs'       => (array) $opts['source_file_briefs'],
						'notebook_source_block_md' => (string) $opts['notebook_source_block_md'],
						// [2026-07-19 Johnny Chu] PHASE-TBR-NB-MOAT W0.12 — N4 cross-notebook links in non-stream event.
						'cross_notebook_links'     => (array) $opts['cross_notebook_links'],
						// [2026-07-19 Johnny Chu] PHASE-TBR-NB-MOAT W0.13 — N5 training gap report in non-stream event.
						'training_gap_report'      => (array) $opts['training_gap_report'],
					) );
				}
			} catch ( \Throwable $e ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( '[TwinBrain][notebook_source_layer][nonstream][error] trace=' . $trace_id . ' ' . $e->getMessage() );
				}
				self::write_runtime_log( 'error', 'notebook_source_layer_exception', $e->getMessage(), array(
					'trace_id' => $trace_id,
					'surface'  => self::SURFACE,
					'channel'  => (string) ( $opts['channel'] ?? '' ),
					'notebook_id' => (int) ( $opts['notebook_id'] ?? 0 ),
					'exception_class' => get_class( $e ),
				) );
			}
			// [2026-09-16 Johnny Chu - Chu Hoàng Anh] PHASE-1.33D-D2 — same phase boundary on the non-stream path so both surfaces agree.
			$this->emit_context_bank_phase_events( $trace_id, (array) ( $opts['context_bank'] ?? array() ) );
		}
		// [2026-09-01 Johnny Chu] PHASE-0.45-W5 — keep non-stream automation on the same deep-research engine as the stream path.
		$web_row  = array();
		$web_mode = strtolower( (string) ( $opts['web_mode'] ?? 'off' ) );
		$guru_id_eff = (int) ( $opts['guru_id'] ?? 0 );
		$web_mode = (string) apply_filters( 'bizcity_twinbrain_web_mode_effective', $web_mode, $guru_id_eff, array(
			'trace_id' => $trace_id,
			'prompt' => $prompt,
			'surface' => (string) ( $opts['surface'] ?? '' ),
			'requested_mode' => $web_mode,
		) );
		if ( in_array( $web_mode, self::web_engine_modes(), true ) && class_exists( 'BizCity_Twin_SSE_Writer' ) ) {
			// [2026-09-18 10:51 AM Johnny Chu - Chu Hoàng Anh] HOTFIX — non-stream path uses a capture-only writer: no global debug listener outliving this call, no ob_flush() into the caller's buffers (leaked SSE into REST/webhook bodies and diagnostics stdout).
			$web_sse = method_exists( 'BizCity_Twin_SSE_Writer', 'capture' )
				? BizCity_Twin_SSE_Writer::capture()
				: new BizCity_Twin_SSE_Writer( false );
			ob_start();
			try {
				$web_row = $this->dispatch_web_research( $trace_id, $prompt, $web_mode, $web_sse, $opts );
			} finally {
				ob_end_clean();
			}
			if ( ! empty( $web_row ) ) {
				$answers[] = $web_row;
			}
		}
		/* PHASE-0.35 / F7.C4 — Layer 5 Tool_Decision (no dispatch yet; that's F7.C5). */
		$direct_vertical_mode = strtolower( (string) ( $opts['web_mode'] ?? 'off' ) );
		$owns_data = self::owns_data_query( $direct_vertical_mode ); // [2026-10-07 10:05 Johnny Chu - Chu Hoàng Anh] W-2 — was === 'woo_bizops'.
		$tool_decision = $owns_data
			? array( 'decision' => 'vertical_plugin', 'reason' => 'direct_vertical_mode', 'tool' => null )
			: $this->decide_tool(
				$trace_id,
				(array) $tool_candidates,
				(string) ( $opts['tool_force'] ?? '' ),
				(int)    ( $opts['guru_id']    ?? 0 )
			);
		if ( $owns_data ) {
			$this->emit_event( 'vertical_plugin_decided', array( 'trace_id' => $trace_id, 'surface' => self::SURFACE, 'plugin' => $direct_vertical_mode, 'reason' => 'direct_vertical_mode' ) );
		} else {
			$this->emit_event( 'tool_decided', array_merge( array( 'trace_id' => $trace_id, 'surface' => self::SURFACE ), $tool_decision ) );
		}

		/* PHASE-0.35 / F7.C5 — Layer 6 Tool_Dispatch (non-stream). */
		// [2026-07-20 Johnny Chu] PHASE-TWIN-GPT-AGENT-TOOLS — pass the user prompt into tool args builder before dispatch.
		$opts['tool_prompt'] = $prompt;
		$dispatch     = $owns_data ? array() : $this->dispatch_tool( $trace_id, $tool_decision, $opts );
		if ( ! empty( $dispatch['artifact_created'] ) && is_array( $dispatch['artifact_created'] ) ) {
			$this->emit_event( 'artifact_created', array_merge( array( 'trace_id' => $trace_id, 'surface' => self::SURFACE ), $dispatch['artifact_created'] ) );
		}
		if ( ! empty( $dispatch['artifact_ready'] ) && is_array( $dispatch['artifact_ready'] ) ) {
			$this->emit_event( 'artifact_ready', array_merge( array( 'trace_id' => $trace_id, 'surface' => self::SURFACE ), $dispatch['artifact_ready'] ) );
		}
		if ( ! $owns_data ) {
			$this->emit_event( 'tool_done', $this->tool_done_payload( $trace_id, $dispatch, $tool_decision ) );
		}
		$tool_results = $owns_data
			? array()
			: ( ! empty( $dispatch['skipped'] ) ? $this->planned_tool_results( $tool_decision ) : $this->dispatched_tool_results( $dispatch ) );
		// [2026-08-24 Johnny Chu] TBR-EVIDENCE-FALLBACK — expose completed tool evidence to the shared fallback resolver.
		$opts['tool_dispatch'] = $dispatch;
		$opts['tool_results'] = $tool_results;

		$synth     = BizCity_TwinBrain_Synthesizer::instance();
		$synth_t0  = microtime( true );
		// [2026-07-24 Johnny Chu] PHASE-0.46 W5 S5.3 — reuse the S5.2 day-notebook
		// phrase detector to flag "meeting/day summary" framing for the
		// Synthesizer, instead of building a parallel summarizer.
		if ( ! isset( $opts['day_scope_summary'] ) ) {
			$opts['day_scope_summary'] = $this->is_day_notebook_summary_query( $prompt );
		}
		$synthesis = $synth->synthesize( $trace_id, $prompt, $answers, $tool_results, $opts );
		// [2026-10-01 Claude Opus 5.5] PHASE-0.87 CL-8 — the non-stream path read $synth_ms without setting it (undefined-variable
		// warning on every headless turn, `ms` null in brain_synthesize/assistant_message); same measure as the stream path.
		$synth_ms  = (int) ( ( microtime( true ) - $synth_t0 ) * 1000 );
		// [2026-07-31 Johnny Chu] PHASE-TWINWEB-UNIFIED-SOURCES — queue MPR web citations for canonical notebook persistence without blocking the answer.
		do_action( 'bizcity_twinbrain_citations_ready', $trace_id, (string) ( $opts['session_id'] ?? '' ), (array) ( $synthesis['citations'] ?? array() ), $opts );
		if ( class_exists( 'BizCity_TwinBrain_Notebook_Source_Layer' ) && ! empty( $opts['notebook_source_map'] ) && ! empty( $opts['notebook_source_block_md'] ) ) {
			$nonstream_answer = trim( (string) ( $synthesis['answer_md'] ?? '' ) );
			if ( $nonstream_answer !== '' && strpos( $nonstream_answer, '### Nguồn từ Notebook' ) === false ) {
				$nonstream_answer .= "\n\n" . (string) $opts['notebook_source_block_md'];
			}
			$clean = BizCity_TwinBrain_Notebook_Source_Layer::instance()->strip_invalid_citations( $nonstream_answer, (array) $opts['notebook_source_map'] );
			$synthesis['answer_md'] = (string) ( $clean['answer_md'] ?? $nonstream_answer );
			$synthesis['invalid_notebook_citations_stripped'] = (int) ( $clean['invalid_count'] ?? 0 );
		}

		// Resolve cited passages → KG entity ids so the visual panel can light
		// the matching nodes orange (R-BRAIN-1 + parity with notebook editor).
		$cited_entity_ids = $this->resolve_cited_entity_ids( $synthesis['citations'] ?? [] );

		// TBR.F5b (2026-05-13) — also resolve passage_id → source_id + snippet
		// so the FE can flip right-panel to Source tab and load the document
		// inline (no full-page redirect to notebook studio).
		// Combine `synthesis.citations[]` + every `[nb:X/pY]` token mentioned
		// inline in `answer_md` (LLM often references passages parenthetically
		// that aren't echoed in the citations array).
		$inline_pids = $this->extract_inline_passage_refs( (string) ( $synthesis['answer_md'] ?? '' ) );
		$cited_passages = $this->resolve_cited_passages( $synthesis['citations'] ?? [], $inline_pids );
		// [2026-08-04 Johnny Chu] R-MPR-GOALBOARD — non-stream channel adapters must receive the same pre-final gate evidence as SSE callers.
		$final_gate = $this->finalize_with_gate( $trace_id, $prompt, array(
			'answer_md'      => (string) ( $synthesis['answer_md'] ?? '' ),
			'synthesis'      => $synthesis,
			'citations'      => (array) ( $synthesis['citations'] ?? array() ),
			'cited_passages' => $cited_passages,
			'tool_dispatch'  => $dispatch,
		), $opts, null );
		// [2026-08-06 Johnny Chu] V4-DEPTH — bounded loop instead of a single `if`; 'high' (default) still runs exactly one round (unchanged), 'deep' can run up to DEEP_RETRIEVE_ROUNDS.
		$answer_depth_cfg_ct = isset( $opts['_answer_depth_cfg'] ) && is_array( $opts['_answer_depth_cfg'] ) ? $opts['_answer_depth_cfg'] : $this->resolve_answer_depth_config( $opts );
		$max_retrieve_rounds_ct = (int) ( $answer_depth_cfg_ct['max_retrieve_rounds'] ?? 0 );
		$retrieve_round_num_ct = 0;
		while ( empty( $final_gate['ready_for_final'] ) && $this->has_retrieve_route( $final_gate ) && $retrieve_round_num_ct < $max_retrieve_rounds_ct ) {
			$retrieve_round_num_ct++;
			$opts['retrieve_exclude_ids'] = $candidates;
			$retrieve = $this->run_bounded_retrieve_round( $trace_id, $prompt, $final_gate, $opts, $tool_results, null, $retrieve_round_num_ct );
			if ( ! empty( $retrieve['ok'] ) ) {
				$candidates = array_merge( $candidates, (array) $retrieve['candidates'] );
				$answers = array_merge( $answers, (array) $retrieve['answers'] );
				$opts = (array) $retrieve['opts'];
				$synthesis = (array) $retrieve['synthesis'];
				$inline_pids = $this->extract_inline_passage_refs( (string) ( $synthesis['answer_md'] ?? '' ) );
				$cited_entity_ids = $this->resolve_cited_entity_ids( $synthesis['citations'] ?? array() );
				$cited_passages = $this->resolve_cited_passages( $synthesis['citations'] ?? array(), $inline_pids );
				$opts['retrieve_round'] = $retrieve_round_num_ct;
				$final_gate = $this->finalize_with_gate( $trace_id, $prompt, array(
					'answer_md'      => (string) ( $synthesis['answer_md'] ?? '' ),
					'synthesis'      => $synthesis,
					'citations'      => (array) ( $synthesis['citations'] ?? array() ),
					'cited_passages' => $cited_passages,
					'tool_dispatch'  => $dispatch,
				), $opts, null );
			} else {
				$final_gate['retrieve_round'] = $retrieve_round_num_ct;
				$final_gate['gate_reason'] = (string) ( $retrieve['reason'] ?? 'retrieve_round_failed' );
				break;
			}
		}
		if ( class_exists( 'BizCity_TwinBrain_Final_Composer' ) ) {
			// [2026-08-23 Johnny Chu] TBR-EVIDENCE-FALLBACK — share the stream/non-stream evidence decision before final composition.
			$opts['evidence_fallback_state'] = BizCity_TwinBrain_Final_Composer::instance()->resolve_evidence_fallback_state( $opts, $prompt );
		}
		$final = array();
		if ( class_exists( 'BizCity_TwinBrain_Final_Composer' ) && ! empty( $opts['evidence_fallback_state']['fallback'] ) ) {
			// [2026-08-23 Johnny Chu] TBR-EVIDENCE-FALLBACK — keep non-stream channel replies on the canonical Final Composer contract.
			$final_opts = $opts;
			$final_opts['final_gate'] = $final_gate;
			try {
				$final = BizCity_TwinBrain_Final_Composer::instance()->compose_stream(
					$trace_id,
					$prompt,
					$synthesis,
					$answers,
					$final_opts,
					null
				);
				if ( ! empty( $final['answer_md'] ) ) {
					$synthesis['answer_md_synth'] = (string) ( $synthesis['answer_md'] ?? '' );
					$synthesis['answer_md'] = (string) $final['answer_md'];
					$synthesis['final_compose'] = $final;
				}
			} catch ( \Throwable $e ) {
				self::write_runtime_log( 'error', 'final_composer_nonstream_exception', $e->getMessage(), array(
					'trace_id' => $trace_id,
					'surface' => self::SURFACE,
					'exception_class' => get_class( $e ),
				) );
			}
		}
		// [2026-08-23 Johnny Chu] TBR-EVIDENCE-FALLBACK — resolve citations from the final answer, not the pre-composer Synthesizer payload.
		if ( ! empty( $final['evidence_fallback'] ) ) {
			$synthesis['citations'] = array();
		}
		$final_inline_pids = $this->extract_inline_passage_refs( (string) ( $synthesis['answer_md'] ?? '' ) );
		$cited_entity_ids = $this->resolve_cited_entity_ids( (array) ( $synthesis['citations'] ?? array() ) );
		$cited_passages   = $this->resolve_cited_passages( (array) ( $synthesis['citations'] ?? array() ), $final_inline_pids );

		// Stage 4 telemetry — emit granular brain_synthesize before the
		// final assistant_message so admin replay can show timing & model.
		$this->emit_event( 'brain_synthesize', [
			'trace_id'           => $trace_id,
			'surface'            => self::SURFACE,
			'model'              => (string) ( $synthesis['model']  ?? '' ),
			'tokens'             => (int)    ( $synthesis['tokens'] ?? 0 ),
			'ms'                 => $synth_ms,
			'consensus_count'    => isset( $synthesis['consensus'] ) ? count( $synthesis['consensus'] ) : 0,
			'tensions_count'     => isset( $synthesis['tensions']  ) ? count( $synthesis['tensions']  ) : 0,
			'citation_count'     => isset( $synthesis['citations'] ) ? count( $synthesis['citations'] ) : 0,
			'notebook_source_counts' => (array) ( $opts['notebook_source_counts'] ?? array() ),
			'source_file_counts' => (array) ( $opts['source_file_counts'] ?? array() ),
			'cited_entity_count' => count( $cited_entity_ids ),
			'cited_passage_count'=> count( $cited_passages ),
			'fallback'           => (string) ( $synthesis['fallback'] ?? '' ),
			// [2026-09-15 Johnny Chu - Chu Hoàng Anh] PHASE-1.33C — carry the gateway route/reason footlog so the MPR timeline can explain a degraded synthesis.
			'synthesis_reason_bucket' => (string) ( $synthesis['reason_bucket'] ?? '' ),
			'synthesis_route_path'    => (string) ( $synthesis['route_path'] ?? '' ),
			'synthesis_http_code'     => (int) ( $synthesis['http_code'] ?? 0 ),
			'answers_in'         => count( $answers ),
		] );

		$this->emit_event( 'assistant_message', [
			'trace_id'             => $trace_id,
			'surface'              => self::SURFACE,
			'text'                 => $synthesis['answer_md'] ?? '',
			'synthesis_metadata'   => [
				'consensus'         => $synthesis['consensus']      ?? [],
				'tensions'          => $synthesis['tensions']       ?? [],
				'recommendation'    => $synthesis['recommendation'] ?? '',
				'citations'         => $synthesis['citations']      ?? [],
				'citation_count'    => isset( $synthesis['citations'] ) ? count( $synthesis['citations'] ) : 0,
				'notebook_source_map'      => (array) ( $opts['notebook_source_map'] ?? array() ),
				'notebook_source_block_md' => (string) ( $opts['notebook_source_block_md'] ?? '' ),
				'notebook_source_counts'   => (array) ( $opts['notebook_source_counts'] ?? array() ),
				'source_file_briefs'       => (array) ( $opts['source_file_briefs'] ?? array() ),
				'source_file_counts'       => (array) ( $opts['source_file_counts'] ?? array() ),
				'search_context'           => (array) ( $opts['search_context'] ?? array() ),
				'search_context_results'   => (array) ( $opts['search_context_results'] ?? array() ),
				'search_context_total'     => (int) ( $opts['search_context_total'] ?? 0 ),
				'cited_entity_ids'  => $cited_entity_ids,
				'cited_passages'    => $cited_passages,
				'model'             => (string) ( $synthesis['model'] ?? '' ),
				'tokens'            => (int)    ( $synthesis['tokens'] ?? 0 ),
				'ms'                => $synth_ms,
				'fallback'          => (string) ( $synthesis['fallback'] ?? '' ),
				'final_gate'        => $final_gate,
				'evidence_fallback' => ! empty( $final['evidence_fallback'] ),
				'evidence_fallback_trigger' => (string) ( $final['evidence_fallback_trigger'] ?? '' ),
				'deep_research_offer' => ! empty( $final['deep_research_offer'] ),
			],
		] );

		$result = [
			'ok'                => true,
			'trace_id'          => $trace_id,
			'synthesis'         => $synthesis,
			'answers'           => $answers,
			// [2026-09-01 Johnny Chu] PHASE-0.45-W5 — expose web citations to non-stream automation consumers.
			'web_research'      => $web_row,
			'notebook_source'   => array(
				'map'      => (array) ( $opts['notebook_source_map'] ?? array() ),
				'block_md' => (string) ( $opts['notebook_source_block_md'] ?? '' ),
				'counts'   => (array) ( $opts['notebook_source_counts'] ?? array() ),
					// [2026-09-01 Johnny Chu] PHASE-0.45-W5 — expose the same graph/retrieval/final context pack to automation surfaces; no surface-specific rerank is introduced.
					'graph_vector_rerank_pack' => (array) ( $opts['graph_vector_rerank_pack'] ?? array() ),
					'graph_entities' => (array) ( $opts['graph_entities'] ?? array() ),
					'retrieval_candidates' => (array) ( $opts['retrieval_candidates'] ?? array() ),
					'final_context_chunks' => (array) ( $opts['final_context_chunks'] ?? array() ),
					'retrieval_candidate_count' => (int) ( $opts['retrieval_candidate_count'] ?? 0 ),
					'final_context_count' => (int) ( $opts['final_context_count'] ?? 0 ),
					'rerank_method' => (string) ( $opts['rerank_method'] ?? '' ),
					'rerank_degraded' => ! empty( $opts['rerank_degraded'] ),
				'source_file_briefs' => (array) ( $opts['source_file_briefs'] ?? array() ),
				'source_file_counts' => (array) ( $opts['source_file_counts'] ?? array() ),
				'search_context'     => (array) ( $opts['search_context'] ?? array() ),
				'search_context_results' => (array) ( $opts['search_context_results'] ?? array() ),
				'search_context_total' => (int) ( $opts['search_context_total'] ?? 0 ),
			),
			'cited_entity_ids'  => $cited_entity_ids,
			'cited_passages'    => $cited_passages,
			'final_gate'        => $final_gate,
			'evidence_fallback' => ! empty( $final['evidence_fallback'] ),
			'evidence_fallback_trigger' => (string) ( $final['evidence_fallback_trigger'] ?? '' ),
			'deep_research_offer' => ! empty( $final['deep_research_offer'] ),
		];
		// [2026-10-06 23:24 Johnny Chu - Chu Hoàng Anh] TWINBRAIN-SPLIT-CORE-ADDON W-1 — Goal Loop post_turn removed (archived).
		return $result;
	}

	/**
	 * TBR.F6-sse — streaming variant of `complete_turn()`.
	 *
	 * Same wave-0 pipeline (perspectives → synthesis → cite resolve), but
	 * progressively pushes phase events into the provided SSE writer so the
	 * FE can paint each step as soon as it lands. Returns the same shape as
	 * `complete_turn()` for callers that want the final aggregate.
	 *
	 * Phases emitted:
	 *   - perspectives_started   { count }
	 *   - perspective_done       { notebook_id, label, stance, confidence, ms, tokens }
	 *   - perspectives_done      { count, ms }
	 *   - synthesis_started      { }
	 *   - synthesis_done         { answer_md, consensus[], tensions[], citations[], tokens, ms, fallback }
	 *   - cite_resolved          { cited_entity_ids[], cited_passages[] }
	 *   - completed              { duration_ms, trace_id }
	 *
	 * NOTE: token-level streaming inside the synthesizer is deferred until the
	 * gateway adapter exposes a stream channel (PHASE-0.36 sprint .9 / TBR.F6.2).
	 */
	public function complete_turn_stream(
		string $trace_id,
		string $prompt,
		array $candidates,
		array $tool_candidates,
		BizCity_Twin_SSE_Writer $sse,
		array $opts = []
	): array {
		$wall_t0 = microtime( true );
		// [2026-06-04 Johnny Chu] BS-12 — bind session for durable turn persist
		// (covers agent / astro / degrade sub-stream modes delegated below).
		$this->current_session_id = (string) ( $opts['session_id'] ?? '' );
		$opts = $this->resolve_identity_context( $opts, (int) ( $opts['user_id'] ?? get_current_user_id() ) );
		if ( ! empty( $opts['goal_contract']['answer_obligations'] ) ) {
			// [2026-08-03 Johnny Chu] R-MPR-GOALBOARD — expose the frozen contract on the live MPR timeline before any perspective/final phase.
			$contract = (array) $opts['goal_contract'];
			$scoreboard = is_array( $contract['resolution_scoreboard'] ?? null ) ? $contract['resolution_scoreboard'] : array();
			$sse->emit( 'goal_contract_ready', array(
				'trace_id'           => $trace_id,
				'goal_id'            => (string) ( $opts['goal_loop']['goal_id'] ?? '' ),
				'scoreboard_version' => (string) ( $scoreboard['scoreboard_version'] ?? 'v1' ),
				'obligation_count'   => count( (array) $contract['answer_obligations'] ),
				'conversation_mode'  => (string) ( $contract['conversation_goal']['conversation_mode'] ?? '' ),
				'answer_obligations' => (array) $contract['answer_obligations'],
			) );
		}

		// [2026-07-18 Johnny Chu] PHASE-TWIN-GPT-C-ENDUSER — apply server-owned TwinWeb runtime preset caps before any mode dispatch.
		if ( isset( $opts['twinweb_runtime_budget'] ) && is_array( $opts['twinweb_runtime_budget'] ) ) {
			$_budget = $opts['twinweb_runtime_budget'];
			if ( isset( $_budget['max_iterations'] ) ) {
				$opts['max_iterations'] = max( 1, min( 8, (int) $_budget['max_iterations'] ) );
			}
			if ( isset( $_budget['search_result_budget'] ) ) {
				$opts['search_result_budget'] = max( 1, min( 20, (int) $_budget['search_result_budget'] ) );
			}
		}

		// [2026-10-07 10:05 Johnny Chu - Chu Hoàng Anh] TWINBRAIN-SPLIT-CORE-ADDON W-3 — `mode=agent` (Agent ReAct) retired with
		// the archived Agent_Runner; such requests now run the normal MPR turn.

		/* [2026-08-03 Johnny Chu] R-TGL-CS — companion is an internal
		 * casual_fast_path optimization; Brain Chat never bypasses full MPR. */
		if ( ! empty( $opts['companion_mode'] ) ) {
			$opts['companion_mode'] = true;
			return $this->stream_auto_degrade_chat( $trace_id, $prompt, $sse, $opts, $wall_t0 );
		}

		// [2026-06-04 Johnny Chu] PHASE-A C.3b — Astro mode: bypass MPR pipeline,
		// resolve transit via CAP filter, compose final answer with transit context.
		// [2026-10-07 10:05 Johnny Chu - Chu Hoàng Anh] TWINBRAIN-SPLIT-CORE-ADDON W-3 — served by the add-on through
		// `bizcity_twinbrain_vertical_takeover`; without the add-on the mode is not offered and the turn runs plain MPR.
		if ( in_array( strtolower( (string) ( $opts['web_mode'] ?? 'off' ) ), self::takeover_modes(), true ) ) {
			$takeover = $this->vertical_takeover( $trace_id, $prompt, $sse, $opts, $wall_t0 );
			if ( is_array( $takeover ) ) {
				return $takeover;
			}
		}

		// [2026-07-19 Johnny Chu] PHASE-TBR-NB-MULTIMODAL — resolve subject and attachments before any core Notebook/RAG/final flow.
		$opts = $this->collect_subject_profile_context( $trace_id, $prompt, $opts, $sse );
		$opts = $this->collect_multimodal_intake_context( $trace_id, $prompt, $opts, $sse );
		$prompt_for_reasoning = trim( (string) ( $opts['multimodal_enriched_query'] ?? '' ) );
		if ( '' === $prompt_for_reasoning ) {
			$prompt_for_reasoning = $prompt;
		}

		/* PHASE 0.36-UNIFIED TBR.W18 (2026-05-28) — Brain auto-degrade.
		 * When the turn has ZERO local notebook candidates AND zero tool
		 * candidates AND no web_mode override, but Layer 0.5 Memory_Recall
		 * surfaced a non-empty block (≥ MIN bytes), we don't have anything
		 * to "synthesize" — running Perspective + Synthesizer + Final
		 * Compose on empty input just burns 2 LLM round-trips to produce
		 * an awkward "không có nguồn tham khảo" answer. Instead, bypass
		 * directly to a chat-tone composer that uses ONLY memory_block +
		 * prompt. Skip perspectives / tool / synthesis layers entirely;
		 * Memory_Writer (L4.7) still runs at the end so user dặn còn được
		 * persist.
		 *
		 * Eligibility filter (`bizcity_twinbrain_auto_degrade_eligible`)
		 * lets 3rd-party (guru policy, A/B) opt out by returning false.
		 * MIN bytes filter (`bizcity_twinbrain_auto_degrade_min_memory_bytes`,
		 * default 120) avoids degrading on near-empty recall blocks that
		 * would produce hollow chat answers. */
		$auto_degrade_web_mode = strtolower( (string) ( $opts['web_mode'] ?? 'off' ) );
		$auto_degrade_min      = (int) apply_filters(
			'bizcity_twinbrain_auto_degrade_min_memory_bytes',
			120,
			$opts
		);
		$auto_degrade_block    = trim( (string) ( $opts['memory_block'] ?? '' ) );
		$auto_degrade_eligible = (
			empty( $candidates )
			&& empty( $tool_candidates )
			&& $auto_degrade_web_mode === 'off'
			&& empty( $opts['disable_auto_degrade'] )
			&& strlen( $auto_degrade_block ) >= $auto_degrade_min
		);
		$auto_degrade_eligible = (bool) apply_filters(
			'bizcity_twinbrain_auto_degrade_eligible',
			$auto_degrade_eligible,
			$trace_id,
			$prompt,
			$opts
		);
		if ( $auto_degrade_eligible ) {
			return $this->stream_auto_degrade_chat( $trace_id, $prompt, $sse, $opts, $wall_t0 );
		}

		$sse->emit( 'perspectives_started', [
			'trace_id' => $trace_id,
			'count'    => count( $candidates ),
		] );

		$persp_t0  = microtime( true );
		$runner    = BizCity_TwinBrain_Perspective_Runner::instance();
		$answers   = $runner->run( $trace_id, $prompt_for_reasoning, $candidates, $opts );
		$persp_ms  = (int) ( ( microtime( true ) - $persp_t0 ) * 1000 );

		// Re-emit each answer individually so FE can fill timeline rows even
		// though curl_multi finishes them in a batch.
		foreach ( $answers as $a ) {
			$sse->emit( 'perspective_done', [
				'trace_id'    => $trace_id,
				'notebook_id' => (int)    ( $a['notebook_id'] ?? 0 ),
				'label'       => (string) ( $a['label']       ?? '' ),
				'stance'      => (string) ( $a['stance']      ?? '' ),
				'confidence'  => (float)  ( $a['confidence']  ?? 0 ),
				'ms'          => (int)    ( $a['ms']          ?? 0 ),
				'tokens'      => (int)    ( $a['tokens']      ?? 0 ),
				'answer_md'   => (string) ( $a['answer_md']   ?? '' ),
				'reason'      => (string) ( $a['reason']      ?? '' ),
				// [2026-07-18 Johnny Chu] PHASE-TBR-NB-MOAT — expose evidence budget for Notebook depth diagnostics.
				'notebook_evidence_budget' => (array) ( $a['notebook_evidence_budget'] ?? array() ),
				'neighborhood_expanded_count' => (int) ( $a['neighborhood_expanded_count'] ?? 0 ),
				'source_sibling_expanded_count' => (int) ( $a['source_sibling_expanded_count'] ?? 0 ),
				'diversity_rerank_applied' => ! empty( $a['diversity_rerank_applied'] ),
			] );
			$sse->maybe_heartbeat();
		}

		$sse->emit( 'perspectives_done', [
			'trace_id' => $trace_id,
			'count'    => count( $answers ),
			'ms'       => $persp_ms,
		] );

		// [2026-06-04 Johnny Chu] BS-12 — snapshot the notebook-only perspective
		// rows BEFORE the optional web_row append, so session replay can render
		// the same per-notebook cards as a live turn. $web_row pre-init so it is
		// always defined (function scope) at the assistant_message snapshot site.
		$persp_snapshot = $answers;
		$web_row        = array();

		$notebook_source_payload = array(
			'notebook_source_map'      => array(),
			'notebook_source_block_md' => '',
			'notebook_source_counts'   => array( 'notebook_count' => 0, 'passage_count' => 0, 'strong_count' => 0, 'weak_count' => 0 ),
			'search_context'           => array( 'query' => '', 'scope' => '', 'total' => 0, 'top_n' => 0, 'tokens' => array(), 'results' => array() ),
		);
		// [2026-07-18 Johnny Chu] PHASE-TBR-NB-MOAT — build first-class notebook source map before web/tool rows are appended.
		if ( class_exists( 'BizCity_TwinBrain_Notebook_Source_Layer' ) ) {
			try {
				// [2026-07-18 Johnny Chu] PHASE-TBR-NB-MOAT W0.9 — pass user prompt into Notebook Source Layer so Search Core can enrich LLM context.
				$opts['notebook_search_context_query'] = $prompt_for_reasoning;
				// [2026-09-16 Johnny Chu - Chu Hoàng Anh] PHASE-1.33D-D3 — resolve the vertical binding first so a bound vertical can request hybrid before the mode default is applied.
				$opts = $this->resolve_vertical_binding( $opts );
				// [2026-09-15 Johnny Chu - Chu Hoàng Anh] PHASE-1.33C — resolve the canonical Context Bank MPR flag before the streamed source layer runs.
				$opts = $this->resolve_context_bank_opts( $opts );
				$notebook_source_payload = BizCity_TwinBrain_Notebook_Source_Layer::instance()->build_from_turn( $candidates, $persp_snapshot, $opts );
				$opts['notebook_source_map']      = (array) ( $notebook_source_payload['notebook_source_map'] ?? array() );
				$opts['context_bank_source_refs'] = (array) ( $notebook_source_payload['graph_vector_rerank_pack']['context_bank_source_refs'] ?? array() );
				// [2026-09-13 11:29 AM Johnny Chu - Chu Hoàng Anh] PHASE-1.33B — keep streamed source-layer payload shape-compatible.
				$opts['context_bank']             = (array) ( $notebook_source_payload['context_bank'] ?? array() );
				// [2026-09-02 11:29 AM Johnny Chu - Chu Hoàng Anh] PHASE-CB4.5 — preserve stream parity for internal canonical Skill owner records.
				$opts['context_bank_owner_records'] = (array) ( $notebook_source_payload['context_bank_owner_records'] ?? array() );
				$opts['context_bank_retrieval']   = (array) ( $notebook_source_payload['graph_vector_rerank_pack']['context_bank_retrieval'] ?? array() );
				$opts['notebook_source_block_md'] = (string) ( $notebook_source_payload['notebook_source_block_md'] ?? '' );
				$opts['notebook_source_counts']   = (array) ( $notebook_source_payload['notebook_source_counts'] ?? array() );
				// [2026-07-18 Johnny Chu] PHASE-TBR-NB-MOAT — pass W0.7 source-file briefs to Final Composer and replay snapshots.
				$opts['source_file_briefs']       = (array) ( $notebook_source_payload['source_file_briefs'] ?? array() );
				$opts['source_file_counts']       = (array) ( $notebook_source_payload['source_file_counts'] ?? array() );
				// [2026-07-19 Johnny Chu] PHASE-TBR-NB-MOAT W0.10 — pass top TwinSearch context hits to Final Composer, SSE and replay snapshots.
				$opts['search_context']           = (array) ( $notebook_source_payload['search_context'] ?? array() );
				$opts['search_context_results']   = (array) ( $notebook_source_payload['search_context_results'] ?? array() );
				$opts['search_context_total']     = (int) ( $notebook_source_payload['search_context_total'] ?? 0 );
				// [2026-07-19 Johnny Chu] PHASE-TBR-NB-MOAT W0.12 — pass N4 cross-notebook links to Final Composer, SSE and replay snapshots.
				$opts['cross_notebook_links']     = (array) ( $notebook_source_payload['cross_notebook_links'] ?? array() );
				// [2026-07-19 Johnny Chu] PHASE-TBR-NB-MOAT W0.20 — pass canonical Graph/vector/rerank evidence pack across all surfaces.
				$opts['graph_vector_rerank_pack'] = (array) ( $notebook_source_payload['graph_vector_rerank_pack'] ?? array() );
				$opts['graph_entities']           = (array) ( $notebook_source_payload['graph_entities'] ?? array() );
				$opts['retrieval_candidates']      = (array) ( $notebook_source_payload['retrieval_candidates'] ?? array() );
				$opts['final_context_chunks']      = (array) ( $notebook_source_payload['final_context_chunks'] ?? array() );
				// [2026-07-19 Johnny Chu] PHASE-TBR-NB-MOAT W0.20.2/W0.20.3 — expose vector/rerank degraded state for FE trace and DDV.
				$opts['retrieval_candidate_count'] = (int) ( $notebook_source_payload['retrieval_candidate_count'] ?? count( $opts['retrieval_candidates'] ) );
				$opts['final_context_count']       = (int) ( $notebook_source_payload['final_context_count'] ?? count( $opts['final_context_chunks'] ) );
				$opts['rerank_method']             = (string) ( $notebook_source_payload['rerank_method'] ?? '' );
				$opts['rerank_degraded']           = ! empty( $notebook_source_payload['rerank_degraded'] );
				$opts['rerank_error']              = (string) ( $notebook_source_payload['rerank_error'] ?? '' );
				$opts['vector_status']             = (string) ( $notebook_source_payload['vector_status'] ?? '' );
				$opts['vector_candidate_count']    = (int) ( $notebook_source_payload['vector_candidate_count'] ?? 0 );
				$opts['vector_degraded_reason']    = (string) ( $notebook_source_payload['vector_degraded_reason'] ?? '' );
				// [2026-07-19 Johnny Chu] PHASE-TBR-NB-MOAT W0.21 — forward graph/selector hardening evidence to SSE/replay.
				$opts['graph_candidate_count']     = (int) ( $notebook_source_payload['graph_candidate_count'] ?? 0 );
				$opts['selector_hardening_applied'] = ! empty( $notebook_source_payload['selector_hardening_applied'] );
				$opts['selector_hardening_reason'] = (string) ( $notebook_source_payload['selector_hardening_reason'] ?? '' );
				$opts['selector_hardening_count']  = (int) ( $notebook_source_payload['selector_hardening_count'] ?? 0 );
				$opts['selector_hardening_scope']  = (string) ( $notebook_source_payload['selector_hardening_scope'] ?? '' );
				// [2026-07-19 Johnny Chu] PHASE-TBR-NB-MOAT W0.13 — pass N5 training gap report to Final Composer, SSE and replay snapshots.
				$opts['training_gap_report']      = (array) ( $notebook_source_payload['training_gap_report'] ?? array() );
				// [2026-07-19 Johnny Chu] PHASE-TBR-NB-MOAT W0.15 — pass concrete product entities to Final Composer, SSE and replay snapshots.
				$opts['product_entities']         = (array) ( $notebook_source_payload['product_entities'] ?? array() );
				$opts['product_entity_count']     = (int) ( $notebook_source_payload['product_entity_count'] ?? count( $opts['product_entities'] ) );
				// [2026-07-19 Johnny Chu] PHASE-TBR-NB-MOAT W0.16 — distinguish true product-name rows from category/claim rows.
				$opts['product_name_entity_count'] = (int) ( $notebook_source_payload['product_name_entity_count'] ?? 0 );

				$source_counts = (array) $opts['notebook_source_counts'];
				$source_file_counts = (array) $opts['source_file_counts'];
				$search_context = (array) $opts['search_context'];
				$source_event  = array(
					'trace_id'                 => $trace_id,
					'notebook_count'           => (int) ( $source_counts['notebook_count'] ?? 0 ),
					'passage_count'            => (int) ( $source_counts['passage_count'] ?? 0 ),
					'strong_count'             => (int) ( $source_counts['strong_count'] ?? 0 ),
					'weak_count'               => (int) ( $source_counts['weak_count'] ?? 0 ),
					'source_file_count'        => (int) ( $source_file_counts['source_file_count'] ?? 0 ),
					'source_file_with_relations_count' => (int) ( $source_file_counts['with_relations_count'] ?? 0 ),
					'search_context_total'     => (int) ( $search_context['total'] ?? $opts['search_context_total'] ?? 0 ),
					'search_context_top_n'     => (int) ( $search_context['top_n'] ?? 0 ),
					'search_context_query'     => (string) ( $search_context['query'] ?? '' ),
					'search_context_scope'     => (string) ( $search_context['scope'] ?? '' ),
					'search_context_tokens'    => (array) ( $search_context['tokens'] ?? array() ),
					'search_context_results'   => (array) ( $search_context['results'] ?? $opts['search_context_results'] ?? array() ),
					'product_entity_count'     => (int) $opts['product_entity_count'],
					'product_name_entity_count' => (int) $opts['product_name_entity_count'],
					'context_bank'             => (array) ( $opts['context_bank'] ?? array() ),
					'product_entities'         => (array) $opts['product_entities'],
					'notebook_source_map'      => (array) $opts['notebook_source_map'],
					'source_file_briefs'       => (array) $opts['source_file_briefs'],
					'notebook_source_block_md' => (string) $opts['notebook_source_block_md'],
					// [2026-07-19 Johnny Chu] PHASE-TBR-NB-MOAT W0.12 — N4 cross-notebook links in stream event.
					'cross_notebook_links'     => (array) $opts['cross_notebook_links'],
					// [2026-07-19 Johnny Chu] PHASE-TBR-NB-MOAT W0.20 — Graph/vector/rerank canonical pack in stream event.
					'graph_vector_rerank_pack' => (array) $opts['graph_vector_rerank_pack'],
					'graph_entities'           => (array) $opts['graph_entities'],
					'retrieval_candidates'      => (array) $opts['retrieval_candidates'],
					'final_context_chunks'      => (array) $opts['final_context_chunks'],
					'retrieval_candidate_count' => (int) $opts['retrieval_candidate_count'],
					'final_context_count'       => (int) $opts['final_context_count'],
					'rerank_method'             => (string) $opts['rerank_method'],
					'rerank_degraded'           => ! empty( $opts['rerank_degraded'] ),
					'rerank_error'              => (string) $opts['rerank_error'],
					'vector_status'             => (string) $opts['vector_status'],
					'vector_candidate_count'    => (int) $opts['vector_candidate_count'],
					'vector_degraded_reason'    => (string) $opts['vector_degraded_reason'],
					// [2026-07-19 Johnny Chu] PHASE-TBR-NB-MOAT W0.21 — stream selector hardening and graph expansion counters.
					'graph_candidate_count'     => (int) $opts['graph_candidate_count'],
					'selector_hardening_applied' => ! empty( $opts['selector_hardening_applied'] ),
					'selector_hardening_reason' => (string) $opts['selector_hardening_reason'],
					'selector_hardening_count'  => (int) $opts['selector_hardening_count'],
					'selector_hardening_scope'  => (string) $opts['selector_hardening_scope'],
					// [2026-07-19 Johnny Chu] PHASE-TBR-NB-MOAT W0.13 — N5 training gap report in stream event.
					'training_gap_report'      => (array) $opts['training_gap_report'],
				);
				// [2026-07-19 Johnny Chu] PHASE-TBR-NB-MOAT W0.17 — always stream TwinSearch/source-layer evidence, including zero-result searches, before final conclusion.
				$sse->emit( 'notebook_source_layer_ready', $source_event );
				$this->emit_event( 'notebook_source_layer_ready', array_merge( array( 'surface' => self::SURFACE ), $source_event ) );
				// [2026-09-16 Johnny Chu - Chu Hoàng Anh] PHASE-1.33D-D2 — emit the Context Bank phase boundary pair on the existing twin_event channel only. Without it the phase cannot be subtracted from unattributed_ms and a slow Context Bank turn is indistinguishable from a slow Source Layer turn.
				$this->emit_context_bank_phase_events( $trace_id, (array) $opts['context_bank'] );
				// [2026-07-19 Johnny Chu] PHASE-TBR-NB-MULTIMODAL — expose Graph/retrieval rerank checkpoint after multimodal query enrichment.
				$rerank_event = array(
					'trace_id'        => $trace_id,
					'candidate_count' => count( (array) $opts['retrieval_candidates'] ),
					'top_n'           => count( (array) $opts['final_context_chunks'] ),
					'query'           => $prompt_for_reasoning,
				);
				$sse->emit( 'rerank_done', $rerank_event );
				$this->emit_event( 'rerank_done', array_merge( array( 'surface' => self::SURFACE ), $rerank_event ) );
			} catch ( \Throwable $e ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( '[TwinBrain][notebook_source_layer][error] trace=' . $trace_id . ' ' . $e->getMessage() );
				}
				self::write_runtime_log( 'error', 'notebook_source_layer_stream_exception', $e->getMessage(), array(
					'trace_id' => $trace_id,
					'surface'  => self::SURFACE,
					'channel'  => (string) ( $opts['channel'] ?? '' ),
					'notebook_id' => (int) ( $opts['notebook_id'] ?? 0 ),
					'exception_class' => get_class( $e ),
				) );
			}
		}

		/* PHASE 0.36-UNIFIED / TBR.W9 (2026-05-21) — Stage 2.5 Web Research
		 * Fallback Layer. Dispatched only when FE composer toggle is
		 * non-default ('quick' or 'deep'). The web perspective is appended
		 * to $answers so the Synthesizer (downstream) can run CONSENSUS /
		 * TENSIONS analysis between local notebooks and web sources. SSE
		 * events (web_research_started / web_search_done / web_extract_done /
		 * web_react_step / web_synthesize_done) are emitted by the engine
		 * itself via BizCity_Twin_Event_Bus — re-emit here as native SSE so
		 * BrainThinkingTimeline reducer doesn't have to round-trip the bus. */
		$web_mode = strtolower( (string) ( $opts['web_mode'] ?? 'off' ) );

		/* TBR.W11 — Apply guru policy gate. When a guru is bound, the
		 * `allow_web_fallback` flag on `wp_bizcity_characters` decides whether
		 * the user's requested mode is honored. Default = OFF (R-TG closed
		 * scope). Filter is also exposed so 3rd-party can wire additional
		 * policy (e.g. plan tier, rate limit). */
		$guru_id_eff = (int) ( $opts['guru_id'] ?? 0 );
		$web_mode    = (string) apply_filters(
			'bizcity_twinbrain_web_mode_effective',
			$web_mode,
			$guru_id_eff,
			// [2026-07-17 Johnny Chu] PHASE-TWINWEB — pass surface/requested mode context so policy filters can apply surface-specific gates.
			[ 'trace_id' => $trace_id, 'prompt' => $prompt, 'surface' => (string) ( $opts['surface'] ?? '' ), 'requested_mode' => $web_mode ]
		);

		// [2026-07-15 Johnny Chu] PHASE-TWB-PRODUCTS - include products in Stage 2.5 web dispatch list.
		if ( in_array( $web_mode, self::web_engine_modes(), true ) ) {
			$web_row = $this->dispatch_web_research( $trace_id, $prompt_for_reasoning, $web_mode, $sse, $opts );
			if ( ! empty( $web_row ) ) {
				// Append as an extra perspective row so synthesizer sees it
				// alongside notebook perspectives. The synthesizer treats
				// any row with stance!='unknown' as a real perspective.
				$answers[] = $web_row;
			}
		}

		/* PHASE-0.35 / F7.C4 — Layer 5 Tool_Decision (no dispatch yet). */
		$direct_vertical_mode = strtolower( (string) ( $opts['web_mode'] ?? 'off' ) );
		$owns_data = self::owns_data_query( $direct_vertical_mode ); // [2026-10-07 10:05 Johnny Chu - Chu Hoàng Anh] W-2 — was === 'woo_bizops'.
		$tool_decision = $owns_data
			? array( 'decision' => 'vertical_plugin', 'reason' => 'direct_vertical_mode', 'tool' => null )
			: $this->decide_tool(
				$trace_id,
				(array) $tool_candidates,
				(string) ( $opts['tool_force'] ?? '' ),
				(int)    ( $opts['guru_id']    ?? 0 )
			);
		if ( $owns_data ) {
			$sse->emit( 'vertical_plugin_decided', array(
				'trace_id' => $trace_id,
				'plugin'   => $direct_vertical_mode,
				'reason'   => 'direct_vertical_mode',
			) );
		} else {
			$sse->emit( 'tool_decided', array_merge( array( 'trace_id' => $trace_id ), $tool_decision ) );
			$this->emit_event( 'tool_decided', array_merge( array( 'trace_id' => $trace_id, 'surface' => self::SURFACE ), $tool_decision ) );
		}

		/* PHASE-0.35 / F7.C5 — Layer 6 Tool_Dispatch (stream).
		 * Non-artifact tools still dispatch before synthesis. Producer artifacts
		 * are deferred until after Layer 4.5 final_done so BZDoc receives the
		 * trusted AskBrain final answer as priority context. */
		// [2026-07-20 Johnny Chu] PHASE-TWIN-GPT-AGENT-TOOLS — use multimodal-enriched prompt as canonical tool args source.
		$opts['tool_prompt'] = $prompt_for_reasoning;
		// [2026-07-20 Johnny Chu] PHASE-1-TWIN-GPT-AGENT-TOOLS — defer producer artifacts until final answer is available for BZDoc handoff.
		$defer_artifact_dispatch = ! $owns_data && $this->should_defer_stream_artifact_dispatch( $tool_decision );
		$dispatch = array();
		if ( $owns_data ) {
			$tool_results = array();
		} elseif ( $defer_artifact_dispatch ) {
			$deferred_payload = array(
				'trace_id' => $trace_id,
				'surface'  => self::SURFACE,
				'tool_slug'=> (string) ( $tool_decision['tool']['slug'] ?? '' ),
				'reason'   => 'await_final_answer',
				'priority' => array( 'request.user_prompt', 'request.final_answer', 'spec_trace' ),
			);
			$sse->emit( 'tool_dispatch_deferred', $deferred_payload );
			$this->emit_event( 'tool_dispatch_deferred', $deferred_payload );
			$tool_results = $this->planned_tool_results( $tool_decision );
		} else {
			$dispatch = $this->dispatch_tool( $trace_id, $tool_decision, $opts, $sse );
			$this->emit_dispatch_artifacts_and_done( $trace_id, $dispatch, $tool_decision, $sse );
			$tool_results = ! empty( $dispatch['skipped'] )
				? $this->planned_tool_results( $tool_decision )
				: $this->dispatched_tool_results( $dispatch );
		}
		// [2026-08-24 Johnny Chu] TBR-EVIDENCE-FALLBACK — expose completed stream tool evidence before fallback notice emission.
		$opts['tool_dispatch'] = $dispatch;
		$opts['tool_results'] = $tool_results;

		if ( class_exists( 'BizCity_TwinBrain_Final_Composer' ) ) {
			// [2026-08-24 Johnny Chu] TBR-EVIDENCE-FALLBACK — publish Part 1 after Notebook and Tool evidence decisions are complete.
			$evidence_fallback_state = BizCity_TwinBrain_Final_Composer::instance()->resolve_evidence_fallback_state( $opts, $prompt );
			$opts['evidence_fallback_state'] = $evidence_fallback_state;
			if ( ! empty( $evidence_fallback_state['fallback'] ) ) {
				$notice_payload = array(
					'trace_id' => $trace_id,
					'trigger' => (string) ( $evidence_fallback_state['trigger'] ?? '' ),
					'reason' => (string) ( $evidence_fallback_state['reason'] ?? '' ),
					'notice' => BizCity_TwinBrain_Final_Composer::instance()->render_evidence_fallback_notice( array_merge( $opts, array( 'evidence_fallback_state' => $evidence_fallback_state ) ) ),
				);
				$sse->emit( 'evidence_fallback_notice', $notice_payload );
				// [2026-08-24 Johnny Chu] TBR-EVIDENCE-FALLBACK — persist the notice under its canonical Event Taxonomy type, while SSE remains the transport event.
				$this->emit_event( 'evidence_fallback_notice', array_merge( array( 'surface' => self::SURFACE ), $notice_payload ) );
			}
		}

		$sse->emit( 'synthesis_started', [ 'trace_id' => $trace_id ] );

		$synth     = BizCity_TwinBrain_Synthesizer::instance();
		$synth_t0  = microtime( true );
		// [2026-07-24 Johnny Chu] PHASE-0.46 W5 S5.3 — same day-notebook framing
		// flag as complete_turn(), keyed off the reasoning-enriched prompt.
		if ( ! isset( $opts['day_scope_summary'] ) ) {
			$opts['day_scope_summary'] = $this->is_day_notebook_summary_query( $prompt_for_reasoning );
		}
		$synthesis = $synth->synthesize( $trace_id, $prompt_for_reasoning, $answers, $tool_results, $opts );
		$synth_ms  = (int) ( ( microtime( true ) - $synth_t0 ) * 1000 );
		// [2026-07-31 Johnny Chu] PHASE-TWINWEB-UNIFIED-SOURCES — queue streaming-turn MPR citations for the same notebook source ledger.
		do_action( 'bizcity_twinbrain_citations_ready', $trace_id, (string) ( $opts['session_id'] ?? '' ), (array) ( $synthesis['citations'] ?? array() ), $opts );

		$sse->emit( 'synthesis_done', [
			'trace_id'        => $trace_id,
			'answer_md'       => (string) ( $synthesis['answer_md']      ?? '' ),
			'consensus'       => (array)  ( $synthesis['consensus']      ?? [] ),
			'tensions'        => (array)  ( $synthesis['tensions']       ?? [] ),
			'recommendation'  => (string) ( $synthesis['recommendation'] ?? '' ),
			'citations'       => (array)  ( $synthesis['citations']      ?? [] ),
			'notebook_source_counts' => (array) ( $opts['notebook_source_counts'] ?? array() ),
			'tokens'          => (int)    ( $synthesis['tokens']         ?? 0 ),
			'ms'              => $synth_ms,
			'fallback'        => (string) ( $synthesis['fallback']       ?? '' ),
			// [2026-09-15 Johnny Chu - Chu Hoàng Anh] PHASE-1.33C — stream the gateway route/reason footlog for the synthesis row.
			'reason_bucket'   => (string) ( $synthesis['reason_bucket'] ?? '' ),
			'route_path'      => (string) ( $synthesis['route_path'] ?? '' ),
			'http_code'       => (int) ( $synthesis['http_code'] ?? 0 ),
		] );

		// [2026-08-04 Johnny Chu] R-MPR-GOALBOARD — create and reflect on the
		// internal Draft before opening the user-visible Final Composer stream.
		$draft_result = array(
			'answer_md'      => (string) ( $synthesis['answer_md'] ?? '' ),
			'synthesis'      => $synthesis,
			'citations'      => (array) ( $synthesis['citations'] ?? array() ),
			'cited_passages' => (array) ( $opts['cited_passages'] ?? array() ),
			'tool_dispatch'  => $dispatch,
		);
		$final_gate = $this->finalize_with_gate( $trace_id, $prompt, $draft_result, $opts, $sse );
		// [2026-08-06 Johnny Chu] V4-DEPTH — bounded loop instead of a single `if`; 'high' (default) still runs exactly one round (unchanged), 'deep' can run up to DEEP_RETRIEVE_ROUNDS.
		$answer_depth_cfg_stream = isset( $opts['_answer_depth_cfg'] ) && is_array( $opts['_answer_depth_cfg'] ) ? $opts['_answer_depth_cfg'] : $this->resolve_answer_depth_config( $opts );
		$max_retrieve_rounds_stream = (int) ( $answer_depth_cfg_stream['max_retrieve_rounds'] ?? 0 );
		$retrieve_round_num_stream = 0;
		while ( empty( $final_gate['ready_for_final'] ) && $this->has_retrieve_route( $final_gate ) && $retrieve_round_num_stream < $max_retrieve_rounds_stream ) {
			$retrieve_round_num_stream++;
			$opts['retrieve_exclude_ids'] = $candidates;
			$retrieve = $this->run_bounded_retrieve_round( $trace_id, $prompt, $final_gate, $opts, $tool_results, $sse, $retrieve_round_num_stream );
			if ( ! empty( $retrieve['ok'] ) ) {
				$candidates = array_merge( $candidates, (array) $retrieve['candidates'] );
				$answers = array_merge( $answers, (array) $retrieve['answers'] );
				$persp_snapshot = $answers;
				$opts = (array) $retrieve['opts'];
				$synthesis = (array) $retrieve['synthesis'];
				$opts['retrieve_round'] = $retrieve_round_num_stream;
				$final_gate = $this->finalize_with_gate( $trace_id, $prompt, array(
					'answer_md'      => (string) ( $synthesis['answer_md'] ?? '' ),
					'synthesis'      => $synthesis,
					'citations'      => (array) ( $synthesis['citations'] ?? array() ),
					'cited_passages' => (array) ( $opts['cited_passages'] ?? array() ),
					'tool_dispatch'  => $dispatch,
				), $opts, $sse );
			} else {
				$final_gate['retrieve_round'] = $retrieve_round_num_stream;
				$final_gate['gate_reason'] = (string) ( $retrieve['reason'] ?? 'retrieve_round_failed' );
				break;
			}
		}

		/* PHASE 0.36-UNIFIED TBR.W17 (2026-05-21) — Layer 4.5 Final Compose.
		 * Stream final user-facing answer AFTER synthesis_done, BEFORE
		 * cite_resolved. The synthesizer answer_md remains in the timeline
		 * as the analysis card; this composer's output is what the user
		 * actually reads. Each SSE delta is bridged from chat_stream() →
		 * `final_token` event. Falls back to synthesizer.answer_md on any
		 * stream error so the UI still gets a non-blank message.
		 *
		 * R-EVT-4: piggyback on the existing single SSE channel — only
		 * NEW event types are `final_started` / `final_token` / `final_done`. */

		// [2026-07-05 Johnny Chu] PHASE-FAA2-TWINBRAIN A3 — Astro context injection.
		// When prompt contains astro keywords AND we are NOT already in astro mode,
		// quietly enrich $opts with astro context_md so Final Composer can cite
		// natal/report/transit facts. Emits `astro_recall_done` SSE so FE timeline
		// renders an "Astro recall" step BEFORE the synthesis/final rows.
		// Fail-open: any error is swallowed.
		// [2026-10-07 10:05 Johnny Chu - Chu Hoàng Anh] TWINBRAIN-SPLIT-CORE-ADDON W-2 — silent Astro Recall moved to the add-on
		// (BizCity_TwinBrain_Verticals_Hooks::astro_recall). Any vertical may enrich opts (extra_context_md) before Final Composer.
		$opts = (array) apply_filters( 'bizcity_twinbrain_pre_compose_context', $opts, $prompt, $trace_id, $sse );

		$final_t0   = microtime( true );
		$final_seq  = 0;
		$final_keepalive_seq = 0;
		$sse->emit( 'final_started', array(
			'trace_id'  => $trace_id,
			'final_gate' => $final_gate,
		) );

		$composer = BizCity_TwinBrain_Final_Composer::instance();
		// [2026-07-07 Johnny Chu] HOTFIX — emit periodic keepalive evidence so
		// FE knows BE is still composing even when no token has arrived yet.
		$final_opts = array_merge( $opts, array(
			'on_keepalive' => static function () use ( $sse, $trace_id, &$final_keepalive_seq ) {
				$final_keepalive_seq++;
				$sse->emit( 'final_keepalive', array(
					'trace_id' => $trace_id,
					'seq'      => $final_keepalive_seq,
					'status'   => 'still_running',
				) );
				$sse->maybe_heartbeat();
			},
		) );
		// [2026-08-04 Johnny Chu] R-MPR-GOALBOARD — Final Composer receives the resolved scoreboard so PASS/PATCH obligations affect the final prompt.
		$final_opts['final_gate'] = $final_gate;
		$final_opts['goal_contract'] = array_merge(
			(array) ( $opts['goal_contract'] ?? array() ),
			array( 'resolution_scoreboard' => (array) ( $final_gate['scoreboard'] ?? array() ) )
		);
		// [2026-07-19 Johnny Chu] PHASE-TBR-NB-MULTIMODAL — visible prompt compiler checkpoint before final model/tool call.
		$prompt_compiler_tool_slug = '';
		if ( isset( $tool_decision['tool'] ) && is_array( $tool_decision['tool'] ) ) {
			$prompt_compiler_tool_slug = (string) ( $tool_decision['tool']['slug'] ?? '' );
		} elseif ( isset( $tool_decision['tool'] ) && is_string( $tool_decision['tool'] ) ) {
			$prompt_compiler_tool_slug = (string) $tool_decision['tool'];
		} elseif ( isset( $tool_decision['tool_slug'] ) ) {
			$prompt_compiler_tool_slug = (string) $tool_decision['tool_slug'];
		}
		$prompt_compiler_event = array(
			'trace_id'         => $trace_id,
			'intent'           => (string) ( $opts['multimodal_intent'] ?? $final_opts['answer_intent'] ?? 'answer' ),
			'model_alias'      => (string) ( $final_opts['model'] ?? '' ),
			'evidence_chunks'  => count( (array) ( $opts['final_context_chunks'] ?? array() ) ),
			'attachment_facts' => ! empty( $opts['multimodal_ingest_pack'] ) ? count( (array) ( $opts['multimodal_ingest_pack']['query_expansion'] ?? array() ) ) : 0,
			'tool_slug'        => $prompt_compiler_tool_slug,
		);
		$sse->emit( 'prompt_compiler_ready', $prompt_compiler_event );
		$this->emit_event( 'prompt_compiler_ready', array_merge( array( 'surface' => self::SURFACE ), $prompt_compiler_event ) );
		$final    = $composer->compose_stream(
			$trace_id,
			$prompt,
			$synthesis,
			$answers,
			$final_opts,
			static function ( string $delta, string $accumulated ) use ( $sse, $trace_id, &$final_seq ) {
				$final_seq++;
				$sse->emit( 'final_token', [
					'trace_id' => $trace_id,
					'seq'      => $final_seq,
					'delta'    => $delta,
					'len'      => mb_strlen( $accumulated ),
				] );
			}
		);
		$final_ms = (int) ( ( microtime( true ) - $final_t0 ) * 1000 );

		$final_text = (string) ( $final['answer_md'] ?? '' );
		if ( ! empty( $final['evidence_fallback'] ) ) {
			// [2026-08-23 Johnny Chu] TBR-EVIDENCE-FALLBACK — final general-knowledge text owns citation metadata after the stream is cleaned.
			$synthesis['citations'] = array();
		}

		// [2026-06-10 Johnny Chu] R-QUOTA-KEY — Brain mode quota check (same pattern as astro mode).
		// Emit SSE error BEFORE final_done so FE renders QuotaErrorBanner.
		if ( ! empty( $final['quota_exhausted'] ) ) {
			$hub_tier    = isset( $final['tier'] )          ? (string) $final['tier']         : 'free';
			$period      = isset( $final['quota_period'] )  ? (string) $final['quota_period']  : 'day';
			$period_vi   = $period === 'month' ? 'tháng' : 'ngày';
			$used_req    = isset( $final['used_requests'] )    ? (int)   $final['used_requests']    : 0;
			$cap_req     = isset( $final['cap_requests_day'] ) ? (int)   $final['cap_requests_day'] : 0;
			$used_usd    = isset( $final['used_usd'] )         ? (float) $final['used_usd']         : 0.0;
			$cap_usd     = isset( $final['cap_usd'] )          ? (float) $final['cap_usd']          : 0.0;
			$reset_at    = isset( $final['reset_at'] )         ? (string)$final['reset_at']         : '';
			$master_lvl  = isset( $final['master_level'] )     ? (string)$final['master_level']     : $hub_tier;
			$sse->emit( 'error', array(
				'code'              => 'quota_exhausted',
				'message'           => 'Hệ thống AI đã vượt giới hạn ' . $period_vi . ' (Layer 1 Hub). Liên hệ admin để nâng cấp gói.',
				'quota_exceeded'    => true,
				'hub_quota'         => true,
				'plan'              => $master_lvl,
				'plan_label'        => strtoupper( $master_lvl ) . ' (Hub)',
				'feature'           => 'llm_chat',
				'used_requests'     => $used_req,
				'cap_requests_day'  => $cap_req,
				'used_usd'          => round( $used_usd, 4 ),
				'cap_usd'           => round( $cap_usd, 4 ),
				'limit'             => $cap_usd > 0 ? $cap_usd : $cap_req,
				'used'              => $cap_usd > 0 ? $used_usd : $used_req,
				'resets_at'         => $reset_at,
				'period'            => $period,
				'hint'              => 'Liên hệ admin site để nâng cấp gói BizCity Hub.',
				'help_code'         => 'hub_quota_exhausted',
			) );
		}

		$sse->emit( 'final_done', [
			'trace_id'  => $trace_id,
			'answer_md' => $final_text,
			'tokens'    => (int)    ( $final['tokens']   ?? 0 ),
			'model'     => (string) ( $final['model']    ?? '' ),
			'ms'        => $final_ms,
			'chunks'    => $final_seq,
			'fallback'  => (string) ( $final['fallback'] ?? '' ),
			'success'   => ! empty( $final['success'] ),
			// [2026-09-17 Johnny Chu - Chu Hoàng Anh] PHASE-1.33C-C3 — forward the Layer
			// 4.5 duration breakdown computed in BizCity_TwinBrain_Final_Composer::
			// compose_stream(). Bounded durations only, no prompt/payload — same rule as
			// every other timeline field. This closes the exact "computed then discarded"
			// anti-pattern already found and fixed for Context Bank's duration_ms.
			'prompt_prep_ms'     => $final['prompt_prep_ms']     ?? null,
			'provider_ttfb_ms'   => $final['provider_ttfb_ms']   ?? null,
			'provider_stream_ms' => $final['provider_stream_ms'] ?? null,
			'post_process_ms'    => $final['post_process_ms']    ?? null,
			'client_paint_ms'    => null,
			// [2026-07-18 Johnny Chu] PHASE-TBR-NB-MOAT — expose Notebook answer-depth profile to timeline/FE diagnostics.
			'notebook_depth_profile' => (string) ( $final['notebook_depth_profile'] ?? '' ),
			'notebook_depth_budget'  => (array) ( $final['notebook_depth_budget'] ?? array() ),
			'llm_purpose'     => (string) ( $final['llm_purpose'] ?? '' ),
			// [2026-07-19 Johnny Chu] PHASE-TBR-NB-MOAT W0.14 — expose answer-intent/list-products contract activation.
			'answer_intent'   => (string) ( $final['answer_intent'] ?? '' ),
			'named_evidence_count' => (int) ( $final['named_evidence_count'] ?? 0 ),
			'product_entity_count' => (int) ( $final['product_entity_count'] ?? $opts['product_entity_count'] ?? 0 ),
			'product_name_entity_count' => (int) ( $final['product_name_entity_count'] ?? $opts['product_name_entity_count'] ?? 0 ),
			'notebook_source_counts'   => (array) ( $opts['notebook_source_counts'] ?? array() ),
			// [2026-07-18 Johnny Chu] PHASE-TBR-NB-MOAT — expose W0.7 source-file brief counts in final timeline event.
			'source_file_counts'       => (array) ( $opts['source_file_counts'] ?? array() ),
			'notebook_source_block_md' => (string) ( $opts['notebook_source_block_md'] ?? '' ),
			'invalid_notebook_citations_stripped' => (int) ( $final['invalid_notebook_citations_stripped'] ?? 0 ),
			'evidence_fallback' => ! empty( $final['evidence_fallback'] ),
			'evidence_fallback_trigger' => (string) ( $final['evidence_fallback_trigger'] ?? '' ),
			'deep_research_offer' => ! empty( $final['deep_research_offer'] ),
			'final_gate' => $final_gate,
			'skeleton_id' => (string) ( $final['skeleton_id'] ?? '' ),
			'required_sections' => (array) ( $final['required_sections'] ?? array() ),
			'skeleton_quality' => (string) ( $final['skeleton_quality'] ?? '' ),
			'skeleton_violations' => (array) ( $final['skeleton_violations'] ?? array() ),
		] );

		if ( $defer_artifact_dispatch ) {
			// [2026-07-20 Johnny Chu] PHASE-1-TWIN-GPT-AGENT-TOOLS — dispatch BZDoc/image/slide artifacts only after final answer stream is complete.
			$deferred_opts = $opts;
			$deferred_opts['tool_prompt'] = $prompt_for_reasoning;
			$deferred_opts['final_answer_md'] = $final_text;
			$deferred_opts['artifact_dispatch_priority'] = array( 'request.user_prompt', 'request.final_answer', 'spec_trace' );
			$start_payload = array(
				'trace_id' => $trace_id,
				'surface'  => self::SURFACE,
				'tool_slug'=> (string) ( $tool_decision['tool']['slug'] ?? '' ),
				'priority' => $deferred_opts['artifact_dispatch_priority'],
			);
			$sse->emit( 'tool_dispatch_deferred_start', $start_payload );
			$this->emit_event( 'tool_dispatch_deferred_start', $start_payload );
			$deferred_dispatch = $this->dispatch_tool( $trace_id, $tool_decision, $deferred_opts, $sse );
			$this->emit_dispatch_artifacts_and_done( $trace_id, $deferred_dispatch, $tool_decision, $sse );
		}

		/* PHASE 0.36-UNIFIED Wave 2.8 TBR.MEM-6 (2026-05-24) — Mode 3 function-
		 * call tools. After final_done, scan $final_text for inline
		 * `<tool name="memory_*">{...}</tool>` blocks emitted by LLM, dispatch
		 * each, rewrite the answer (strip block + insert `[mem:U#<id>]` chip
		 * for memory_remember). Caps: 3 write + 5 recall per turn. Emits 3 new
		 * event types via SSE: memory_tool_call / memory_tool_result /
		 * memory_tool_error. Opt-in via filter
		 * `bizcity_twinbrain_memory_tools_enabled` (default FALSE → Final
		 * Composer omits tool schema → LLM never emits blocks → dispatcher
		 * NO-OPs in find_all_blocks scan). */
		if ( class_exists( 'BizCity_TwinBrain_Memory_Tool_Dispatcher' ) && $final_text !== '' ) {
			try {
				$tool_disp = BizCity_TwinBrain_Memory_Tool_Dispatcher::instance()->dispatch_from_text(
					$final_text,
					[
						'trace_id'   => $trace_id,
						'user_id'    => (int)    ( $opts['subject_id'] ?? $opts['user_id'] ?? get_current_user_id() ),
						'session_id' => (string) ( $opts['session_id'] ?? '' ),
						'identity_uuid' => (string) ( $opts['identity_uuid'] ?? '' ),
						'identity_is_stable' => ! empty( $opts['identity_is_stable'] ),
						'surface'    => self::SURFACE,
						'on_event'   => function ( string $event_key, array $payload ) use ( $sse ) {
							$sse->emit( $event_key, $payload );
							$this->emit_event( $event_key, $payload );
						},
					]
				);
				if ( ! empty( $tool_disp['ops'] ) ) {
					$sse->emit( 'memory_tool_summary', [
						'trace_id'   => $trace_id,
						'tool_calls' => (int) $tool_disp['tool_calls'],
						'persisted'  => (int) $tool_disp['persisted'],
						'forgotten'  => (int) $tool_disp['forgotten'],
						'recalled'   => (int) $tool_disp['recalled'],
						'skipped'    => (int) $tool_disp['skipped'],
						'errors'     => (array) $tool_disp['errors'],
						'latency_ms' => (int) $tool_disp['latency_ms'],
						'goal_loop'         => (array) ( $opts['goal_loop'] ?? array() ),
						'memory_scope'      => (string) ( $opts['memory_scope'] ?? '' ),
						'case_id'           => (string) ( $opts['case_id'] ?? '' ),
						'subject_key'       => (string) ( $opts['subject_key'] ?? '' ),
					] );
					// Promote rewritten text as canonical final answer.
					$rewritten = (string) $tool_disp['rewritten_text'];
					if ( $rewritten !== '' ) {
						$final_text = $rewritten;
						$final['answer_md'] = $rewritten;
					}
				}
			} catch ( \Throwable $e ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( '[TwinBrain][memory_tool_dispatcher][error] trace=' . $trace_id . ' ' . $e->getMessage() );
				}
				self::write_runtime_log( 'error', 'memory_tool_dispatcher_exception', $e->getMessage(), array(
					'trace_id' => $trace_id,
					'surface'  => self::SURFACE,
					'channel'  => (string) ( $opts['channel'] ?? '' ),
					'exception_class' => get_class( $e ),
				) );
			}
		}

		/* PHASE 0.36-UNIFIED Wave 2.8 (TBR.MEM-7) — Layer 4.7 Memory Writer.
		 * Detect explicit "hãy nhớ ..." phrases in the *prompt* and persist
		 * them to memory_users. Idempotent per trace_id. Mode 1 only — no
		 * LLM extractor yet (deferred to MEM-5). Failures swallowed. */
		if ( class_exists( 'BizCity_TwinBrain_Memory_Writer' ) ) {
			try {
				$mw = BizCity_TwinBrain_Memory_Writer::instance()->extract_and_persist(
					$trace_id,
					$prompt,
					$final_text,
					[
						'user_id'    => (int)    ( $opts['subject_id'] ?? $opts['user_id'] ?? get_current_user_id() ),
						'session_id' => (string) ( $opts['session_id'] ?? '' ),
						'identity_uuid' => (string) ( $opts['identity_uuid'] ?? '' ),
						// [2026-07-28 Johnny Chu] R-CH-IDMEM — preserve stable UUID ownership on the primary writer path.
						'identity_is_stable' => ! empty( $opts['identity_is_stable'] ),
						'goal_loop'         => (array) ( $opts['goal_loop'] ?? array() ),
						// [2026-07-27 Johnny Chu] PHASE-0.52 W2 — preserve channel context for no-owner UX only.
						'platform'   => (string) ( $opts['platform'] ?? $opts['channel'] ?? '' ),
						'channel'    => (string) ( $opts['channel'] ?? '' ),
						'account_id' => (string) ( $opts['account_id'] ?? '' ),
						'external_user_id' => (string) ( $opts['external_user_id'] ?? '' ),
						'chat_id'    => (string) ( $opts['chat_id'] ?? '' ),
					]
				);
				$write_payload = [
					'trace_id'   => $trace_id,
					'persisted'  => (int)    ( $mw['persisted']  ?? 0 ),
					'mode'       => (string) ( $mw['mode']       ?? '' ),
					'ops'        => (array)  ( $mw['ops']        ?? [] ),
					'latency_ms' => (int)    ( $mw['latency_ms'] ?? 0 ),
				];
				$sse->emit( 'memory_write', $write_payload );
				$this->emit_event( 'memory_write', $write_payload );
			} catch ( \Throwable $e ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( '[TwinBrain][memory_write][error] ' . $e->getMessage() );
				}
				self::write_runtime_log( 'error', 'memory_write_exception', $e->getMessage(), array(
					'trace_id' => $trace_id,
					'surface'  => self::SURFACE,
					'channel'  => (string) ( $opts['channel'] ?? '' ),
					'exception_class' => get_class( $e ),
				) );
			}

			// [2026-06-03 Johnny Chu] BRAIN-SESSIONS BS-4 — empathic mood
			// sampler. Emits brain_session_mood_sampled every Nth user turn
			// so Memory_Recall Tier F can surface the latest valence/label
			// to Final_Composer. Cheap heuristic — no LLM cost by default.
			$session_id = (string) ( $opts['session_id'] ?? '' );
			if ( $session_id !== '' && method_exists( 'BizCity_TwinBrain_Memory_Writer', 'sample_mood' ) ) {
				try {
					$turn_index = $this->count_user_turns_for_session( $session_id );
					$mood = BizCity_TwinBrain_Memory_Writer::instance()->sample_mood( [
						'trace_id'   => $trace_id,
						'session_id' => $session_id,
						'user_id'    => (int) ( $opts['subject_id'] ?? $opts['user_id'] ?? get_current_user_id() ),
						'turn_index' => $turn_index,
						'prompt'     => (string) $prompt,
						'answer'     => (string) $final_text,
					] );
					if ( ( $mood['status'] ?? '' ) === 'sampled' ) {
						$mood_evt = [
							'trace_id'   => $trace_id,
							'session_id' => $session_id,
							'turn_index' => (int)    ( $mood['turn_index'] ?? $turn_index ),
							'valence'    => (float)  ( $mood['valence']    ?? 0.0 ),
							'label'      => (string) ( $mood['label']      ?? '' ),
							'event_uuid' => (string) ( $mood['event_uuid'] ?? '' ),
						];
						$sse->emit( 'mood_sampled', $mood_evt );
					}
				} catch ( \Throwable $e ) {
					if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
						error_log( '[TwinBrain][mood_sampled][error] ' . $e->getMessage() );
					}
					self::write_runtime_log( 'error', 'mood_sampled_exception', $e->getMessage(), array(
						'trace_id' => $trace_id,
						'surface'  => self::SURFACE,
						'channel'  => (string) ( $opts['channel'] ?? '' ),
						'exception_class' => get_class( $e ),
					) );
				}
			}
		}

		// Promote final_text as the canonical answer_md downstream so
		// citation resolution + assistant_message use the user-visible
		// version. Synth.answer_md stays available via `synthesis_metadata`.
		if ( $final_text !== '' ) {
			$synthesis['answer_md_synth'] = (string) ( $synthesis['answer_md'] ?? '' );
			$synthesis['answer_md']       = $final_text;
		}

		$cited_entity_ids = $this->resolve_cited_entity_ids( $synthesis['citations'] ?? [] );
		$inline_pids      = $this->extract_inline_passage_refs( (string) ( $synthesis['answer_md'] ?? '' ) );
		$cited_passages   = $this->resolve_cited_passages( $synthesis['citations'] ?? [], $inline_pids );

		$sse->emit( 'cite_resolved', [
			'trace_id'         => $trace_id,
			'cited_entity_ids' => $cited_entity_ids,
			'cited_passages'   => $cited_passages,
		] );

		// Mirror complete_turn()'s telemetry so admin replay parity is preserved.
		$this->emit_event( 'brain_synthesize', [
			'trace_id'           => $trace_id,
			'surface'            => self::SURFACE,
			'model'              => (string) ( $synthesis['model']  ?? '' ),
			'tokens'             => (int)    ( $synthesis['tokens'] ?? 0 ),
			'ms'                 => $synth_ms,
			'consensus_count'    => isset( $synthesis['consensus'] ) ? count( $synthesis['consensus'] ) : 0,
			'tensions_count'     => isset( $synthesis['tensions']  ) ? count( $synthesis['tensions']  ) : 0,
			'citation_count'     => isset( $synthesis['citations'] ) ? count( $synthesis['citations'] ) : 0,
			'cited_entity_count' => count( $cited_entity_ids ),
			'cited_passage_count'=> count( $cited_passages ),
			'fallback'           => (string) ( $synthesis['fallback'] ?? '' ),
			'answers_in'         => count( $answers ),
			'streaming'          => true,
		] );
		$this->emit_event( 'assistant_message', [
			'trace_id'             => $trace_id,
			'surface'              => self::SURFACE,
			'text'                 => $synthesis['answer_md'] ?? '',
			'synthesis_metadata'   => [
				'consensus'        => $synthesis['consensus']      ?? [],
				'tensions'         => $synthesis['tensions']       ?? [],
				'recommendation'   => $synthesis['recommendation'] ?? '',
				'citations'        => $synthesis['citations']      ?? [],
				'citation_count'   => isset( $synthesis['citations'] ) ? count( $synthesis['citations'] ) : 0,
				'notebook_source_map'      => (array) ( $opts['notebook_source_map'] ?? array() ),
				'notebook_source_block_md' => (string) ( $opts['notebook_source_block_md'] ?? '' ),
				'notebook_source_counts'   => (array) ( $opts['notebook_source_counts'] ?? array() ),
				// [2026-07-18 Johnny Chu] PHASE-TBR-NB-MOAT — persist W0.7 source-file briefs for admin replay.
				'source_file_briefs'       => (array) ( $opts['source_file_briefs'] ?? array() ),
				'source_file_counts'       => (array) ( $opts['source_file_counts'] ?? array() ),
				'search_context'           => (array) ( $opts['search_context'] ?? array() ),
				'search_context_results'   => (array) ( $opts['search_context_results'] ?? array() ),
				'search_context_total'     => (int) ( $opts['search_context_total'] ?? 0 ),
				'cited_entity_ids' => $cited_entity_ids,
				'cited_passages'   => $cited_passages,
				'model'            => (string) ( $synthesis['model'] ?? '' ),
				'tokens'           => (int)    ( $synthesis['tokens'] ?? 0 ),
				'ms'               => $synth_ms,
				'fallback'         => (string) ( $synthesis['fallback'] ?? '' ),
				'streaming'        => true,
				// TBR.W17 — preserve the structural synthesizer output even
				// after we've promoted final-compose text into answer_md.
				'answer_md_synth'  => (string) ( $synthesis['answer_md_synth'] ?? '' ),
				'final_compose'    => [
					'tokens'   => (int)    ( $final['tokens']   ?? 0 ),
					'model'    => (string) ( $final['model']    ?? '' ),
					'ms'       => $final_ms,
					'chunks'   => $final_seq,
					'fallback' => (string) ( $final['fallback'] ?? '' ),
					'success'  => ! empty( $final['success'] ),
					'notebook_depth_profile' => (string) ( $final['notebook_depth_profile'] ?? '' ),
					'notebook_depth_budget'  => (array) ( $final['notebook_depth_budget'] ?? array() ),
					'llm_purpose' => (string) ( $final['llm_purpose'] ?? '' ),
					// [2026-07-19 Johnny Chu] PHASE-TBR-NB-MOAT W0.14 — preserve list-products contract metadata.
					'answer_intent' => (string) ( $final['answer_intent'] ?? '' ),
					'named_evidence_count' => (int) ( $final['named_evidence_count'] ?? 0 ),
					'product_entity_count' => (int) ( $final['product_entity_count'] ?? $opts['product_entity_count'] ?? 0 ),
					'product_name_entity_count' => (int) ( $final['product_name_entity_count'] ?? $opts['product_name_entity_count'] ?? 0 ),
					'evidence_fallback' => ! empty( $final['evidence_fallback'] ),
					'evidence_fallback_trigger' => (string) ( $final['evidence_fallback_trigger'] ?? '' ),
					'deep_research_offer' => ! empty( $final['deep_research_offer'] ),
					// [2026-08-10 Johnny Chu] GOAL-FOLLOWUP-1 — persist continuity question metadata for session replay.
					'followup_gate' => (string) ( $final['followup_gate'] ?? 'not_required' ),
					'followup_contract' => (array) ( $final['followup_contract'] ?? array() ),
				],
			],
			// [2026-06-04 Johnny Chu] BS-12 — persist full turn result_snapshot
			// (FE BrainTurnResponse shape) so session replay renders identically
			// to a live turn: perspective cards, consensus/tensions, fuchsia final
			// answer with citation chips, web pill, trace id and timing.
			'result_snapshot'      => $this->build_turn_snapshot( $trace_id, $opts, array(
				'candidates'      => $candidates,
				'tool_candidates' => $tool_candidates,
				'web_mode'        => $web_mode,
				'mode'            => 'brain',
				'perspectives'    => $persp_snapshot,
				'notebook_source' => array(
					'map'       => (array) ( $opts['notebook_source_map'] ?? array() ),
					'block_md'  => (string) ( $opts['notebook_source_block_md'] ?? '' ),
					'counts'    => (array) ( $opts['notebook_source_counts'] ?? array() ),
					'source_file_briefs' => (array) ( $opts['source_file_briefs'] ?? array() ),
					'source_file_counts' => (array) ( $opts['source_file_counts'] ?? array() ),
					'search_context'     => (array) ( $opts['search_context'] ?? array() ),
					'search_context_results' => (array) ( $opts['search_context_results'] ?? array() ),
					'search_context_total' => (int) ( $opts['search_context_total'] ?? 0 ),
					'product_entities' => (array) ( $opts['product_entities'] ?? array() ),
					'product_entity_count' => (int) ( $opts['product_entity_count'] ?? 0 ),
					'product_name_entity_count' => (int) ( $opts['product_name_entity_count'] ?? 0 ),
				),
				'synthesis'       => array(
					'answer_md'      => (string) ( $synthesis['answer_md_synth'] ?? ( $synthesis['answer_md'] ?? '' ) ),
					'consensus'      => $synthesis['consensus']      ?? array(),
					'tensions'       => $synthesis['tensions']       ?? array(),
					'recommendation' => $synthesis['recommendation'] ?? '',
					'citations'      => $synthesis['citations']      ?? array(),
					'tokens'         => (int) ( $synthesis['tokens'] ?? 0 ),
					'ms'             => $synth_ms,
					'fallback'       => (string) ( $synthesis['fallback'] ?? '' ),
				),
				'final'           => array(
					'answer_md' => $final_text,
					'chunks'    => $final_seq,
					'tokens'    => (int) ( $final['tokens'] ?? 0 ),
					'model'     => (string) ( $final['model'] ?? '' ),
					'ms'        => $final_ms,
					'fallback'  => (string) ( $final['fallback'] ?? '' ),
					'success'   => ! empty( $final['success'] ),
					'notebook_depth_profile' => (string) ( $final['notebook_depth_profile'] ?? '' ),
					'notebook_depth_budget'  => (array) ( $final['notebook_depth_budget'] ?? array() ),
					'llm_purpose' => (string) ( $final['llm_purpose'] ?? '' ),
					// [2026-07-19 Johnny Chu] PHASE-TBR-NB-MOAT W0.14 — replay W0.14 intent evidence metadata.
					'answer_intent' => (string) ( $final['answer_intent'] ?? '' ),
					'named_evidence_count' => (int) ( $final['named_evidence_count'] ?? 0 ),
					'product_entity_count' => (int) ( $final['product_entity_count'] ?? $opts['product_entity_count'] ?? 0 ),
					'product_name_entity_count' => (int) ( $final['product_name_entity_count'] ?? $opts['product_name_entity_count'] ?? 0 ),
					'followup_gate' => (string) ( $final['followup_gate'] ?? 'not_required' ),
					'followup_contract' => (array) ( $final['followup_contract'] ?? array() ),
				),
				'web_research'    => $web_row,
				'tool_dispatch'   => $tool_done_payload,
				'final_gate'      => $final_gate,
				'cited_entity_ids'=> $cited_entity_ids,
				'cited_passages'  => $cited_passages,
				'duration_ms'     => (int) ( ( microtime( true ) - $wall_t0 ) * 1000 ),
			) ),
		] );

		$wall_ms = (int) ( ( microtime( true ) - $wall_t0 ) * 1000 );

		$result = [
			'ok'                => true,
			'trace_id'          => $trace_id,
			'synthesis'         => $synthesis,
			'answers'           => $answers,
			'cited_entity_ids'  => $cited_entity_ids,
			'cited_passages'    => $cited_passages,
			'final_gate'        => $final_gate,
			'evidence_fallback' => ! empty( $final['evidence_fallback'] ),
			'evidence_fallback_trigger' => (string) ( $final['evidence_fallback_trigger'] ?? '' ),
			'deep_research_offer' => ! empty( $final['deep_research_offer'] ),
			'duration_ms'       => $wall_ms,
		];
		// [2026-10-06 23:24 Johnny Chu - Chu Hoàng Anh] TWINBRAIN-SPLIT-CORE-ADDON W-1 — Goal Loop post_turn removed (archived).
		return $result;
	}

	/**
	 * BS-12 — Build a normalized full-turn snapshot persisted inside the
	 * assistant_message payload. Shape mirrors the FE `BrainTurnResponse`
	 * so session replay (list_turns → tc-brain-load-session) can render a
	 * historical turn identically to a live one without re-running the
	 * (ephemeral, SSE-only) pipeline.
	 *
	 * [2026-06-04 Johnny Chu] BS-12 — session replay full-fidelity snapshot.
	 *
	 * @param string $trace_id
	 * @param array  $opts
	 * @param array  $parts
	 * @return array
	 */
	/**
	 * V4-DEPTH — resolve the 4-tier answer-depth knob onto concrete MPR
	 * pipeline gates. This is the single source of truth for how
	 * `answer_depth` (fast|balanced|high|deep) changes Goal Parser,
	 * Reflection, and the bounded Retrieve loop.
	 *
	 * - fast:     skip Goal Parser + Reflection entirely (single-pass answer).
	 * - balanced: run Goal Parser + Reflection, but never loop a Retrieve round
	 *             (bounded_fallback is accepted as final when evidence is short).
	 * - high:     current V3 canonical default — Goal Parser + Reflection +
	 *             up to 1 bounded Retrieve round when the scoreboard has an
	 *             open RETRIEVE route.
	 * - deep:     Goal Parser + Reflection + up to self::DEEP_RETRIEVE_ROUNDS
	 *             bounded Retrieve rounds, re-checking the scoreboard between
	 *             each round so it stops as soon as obligations are resolved.
	 *
	 * @return array{depth:string,skip_goal_parser:bool,skip_reflection:bool,max_retrieve_rounds:int}
	 */
	private function resolve_answer_depth_config( array $opts ): array {
		$depth = sanitize_key( (string) ( $opts['answer_depth'] ?? self::ANSWER_DEPTH_DEFAULT ) );
		$map = array(
			'fast'     => array( 'skip_goal_parser' => true,  'skip_reflection' => true,  'max_retrieve_rounds' => 0 ),
			'balanced' => array( 'skip_goal_parser' => false, 'skip_reflection' => false, 'max_retrieve_rounds' => 0 ),
			'high'     => array( 'skip_goal_parser' => false, 'skip_reflection' => false, 'max_retrieve_rounds' => 1 ),
			'deep'     => array( 'skip_goal_parser' => false, 'skip_reflection' => false, 'max_retrieve_rounds' => self::DEEP_RETRIEVE_ROUNDS ),
		);
		if ( ! isset( $map[ $depth ] ) ) {
			$depth = self::ANSWER_DEPTH_DEFAULT;
		}
		$cfg = $map[ $depth ];
		$cfg['depth'] = $depth;
		return (array) apply_filters( 'bizcity_twinbrain_answer_depth_config', $cfg, $depth, $opts );
	}

	/**
	 * Emit the Context Bank phase boundary pair on the existing Event Stream.
	 *
	 * [2026-09-16 Johnny Chu - Chu Hoàng Anh] PHASE-1.33D-D2 — contract
	 * CONTEXT-BANK-ASYNC-TIMELINE-CONTRACT-v1 §3. The pair carries counts,
	 * durations and stable reason buckets only. Query text, prompt, ledger rows,
	 * owner bodies, file paths, offsets, hashes, account keys, bearer tokens and
	 * raw provider IDs are never included.
	 *
	 * The taxonomy declares both types (TAXONOMY_VERSION 14); dispatching an
	 * undeclared type throws and the event is lost silently, which is the exact
	 * defect that made `memory_recall` unattributable.
	 *
	 * @param string              $trace_id
	 * @param array<string,mixed> $context_bank Bounded timeline payload.
	 * @return void
	 */
	private function emit_context_bank_phase_events( string $trace_id, array $context_bank ): void {
		if ( empty( $context_bank ) || ! class_exists( 'BizCity_Twin_Event_Taxonomy' ) ) {
			return;
		}
		if ( ! defined( 'BizCity_Twin_Event_Taxonomy::CONTEXT_BANK_STARTED' ) ) {
			return;
		}
		$completed_epoch_ms = (int) round( microtime( true ) * 1000 );
		$duration_ms        = (int) ( $context_bank['duration_ms'] ?? 0 );
		$started_epoch_ms   = max( 0, $completed_epoch_ms - $duration_ms );
		$status             = (string) ( $context_bank['status'] ?? ( ! empty( $context_bank['enabled'] ) ? 'ran' : 'skipped' ) );
		$mode               = (string) ( $context_bank['mode'] ?? 'context_bank' );
		$vertical_id        = (string) ( $context_bank['vertical_id'] ?? '' );
		// [2026-09-16 Johnny Chu - Chu Hoàng Anh] PHASE-1.33C-C2 — mint the `started`
		// event UUID once so `done` can carry it as `parent_event_uuid`. The JSON schema
		// already declared this optional field (event-stream/schemas/events/context_bank_done.json)
		// but no caller ever populated it, so the pair had no linkage a trace calculator
		// could follow — event ordering was implicit (call order) rather than provable.
		$started_event_uuid = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'cb_', true );
		try {
			// The pair is emitted together at the measured boundary. `started` is derived from the
			// measured duration so both events describe the same server-measured, monotonic span:
			// started_epoch_ms <= completed_epoch_ms always holds because duration_ms >= 0.
			$this->emit_event( BizCity_Twin_Event_Taxonomy::CONTEXT_BANK_STARTED, array(
				'trace_id'         => $trace_id,
				'event_uuid'       => $started_event_uuid,
				'phase'            => 'context_bank',
				'started_epoch_ms' => $started_epoch_ms,
				'mode'             => $mode,
				'vertical_id'      => $vertical_id,
			) );
			$this->emit_event( BizCity_Twin_Event_Taxonomy::CONTEXT_BANK_DONE, array(
				'trace_id'           => $trace_id,
				'event_uuid'         => function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'cb_', true ),
				'parent_event_uuid'  => $started_event_uuid,
				'phase'              => 'context_bank',
				'started_epoch_ms'   => $started_epoch_ms,
				'completed_epoch_ms' => $completed_epoch_ms,
				'duration_ms'        => $duration_ms,
				'status'             => $status,
				// §4 — empty when status=ran; otherwise a stable token the UI may translate but logs must match.
				'reason_bucket'      => $status === 'ran' ? '' : (string) ( $context_bank['reason_bucket'] ?? '' ),
				'mode'               => $mode,
				'vertical_id'        => $vertical_id,
				'contract_count'     => (int) ( $context_bank['contract_count'] ?? 0 ),
				'source_ref_count'   => (int) ( $context_bank['source_ref_count'] ?? 0 ),
				'pointer_follows'    => (int) ( $context_bank['pointer_follows'] ?? 0 ),
			) );
		} catch ( \Throwable $e ) {
			self::write_runtime_log( 'error', 'context_bank_phase_event_exception', $e->getMessage(), array(
				'trace_id'        => $trace_id,
				'surface'         => self::SURFACE,
				'exception_class' => get_class( $e ),
			) );
		}
	}

	/**
	 * Resolve the vertical binding for this turn and let it set the effective
	 * Context Bank mode, before resolve_context_bank_opts() applies its default.
	 *
	 * [2026-09-16 Johnny Chu - Chu Hoàng Anh] PHASE-1.33D-D3 — MVP closure for two
	 * documented fail conditions:
	 *   FAIL 2 - `context_bank_mode` was hardcoded to `context_bank` before any
	 *            vertical had a chance to request `hybrid`, so the resolver's
	 *            `hybrid` branch was dead code on real runtime.
	 *   FAIL 1 - `vertical_id` had readers but no writer, so the vertical axis had
	 *            zero influence on Context Bank retrieval.
	 *
	 * The browser still never selects a Context Bank mode: `web_mode` is resolved
	 * through the canonical Vertical Bridge Registry server-side, and an
	 * unregistered mode leaves every key untouched (today's behavior).
	 *
	 * @param array<string,mixed> $opts
	 * @return array<string,mixed>
	 */
	private function resolve_vertical_binding( array $opts ): array {
		// [2026-09-16 Johnny Chu - Chu Hoàng Anh] PHASE-1.33D-D3 — derive vertical_id and the mode hint from the canonical registry only.
		$web_mode = strtolower( (string) ( $opts['web_mode'] ?? 'off' ) );
		if ( $web_mode === '' || $web_mode === 'off' || ! class_exists( 'BizCity_TwinBrain_Vertical_Bridge_Registry' ) ) {
			return $opts;
		}
		$vertical = BizCity_TwinBrain_Vertical_Bridge_Registry::get( $web_mode );
		if ( ! is_array( $vertical ) ) {
			// Unregistered vertical: leave opts untouched so behavior is identical to today.
			return $opts;
		}
		// [2026-09-17] WP7 §mode/plan/guest policy — the registry's own min_plan/
		// guest_allowed were declared but never re-checked at the point a vertical
		// actually runs; only modules/twinweb's mode-LISTING endpoint
		// (get_effective_config()) enforced them, which just hides the menu entry
		// and does not stop a client sending web_mode directly. Reset to 'off' on
		// denial (same as an unregistered mode) so this single resolution point
		// also gates the Stage 2.5 web-research dispatch further down this file,
		// which reads $opts['web_mode'] independently.
		if ( ! $this->vertical_policy_allows( $vertical, $opts ) ) {
			$opts['web_mode'] = 'off';
			return $opts;
		}
		$opts['vertical_id'] = (string) ( $vertical['id'] ?? $web_mode );
		$binding = isset( $vertical['context_bank'] ) && is_array( $vertical['context_bank'] ) ? $vertical['context_bank'] : array();
		$mode_hint = (string) ( $binding['mode_hint'] ?? 'inherit' );
		if ( $mode_hint === 'hybrid' ) {
			$opts['context_bank_mode'] = 'hybrid';
		} elseif ( $mode_hint === 'skip' ) {
			$opts['context_bank_enabled'] = false;
		}
		// 'inherit' (the default for every unbound row): leave both keys untouched.
		$opts['_vertical_binding']      = $binding; // Internal only; consumed by the source layer, never sent to the client.
		$opts['_vertical_binding_hint'] = $mode_hint;
		$opts['_vertical_binding_source'] = 'web_mode';
		// [2026-09-17] WP7 §declared Brain mode, allowed tools/actions — a
		// vertical may declare `allowed_tools[]` (manifest.schema.json
		// `verticalMode.allowed_tools`); when present, thread it into
		// `$opts['allowed_tools']` for `mode=agent`'s ReAct tool whitelist
		// (`BizCity_TwinBrain_Agent_Runner::run()` already honours
		// `$opts['allowed_tools']` when it is a non-empty array — see the
		// `$agent_opts` build further down this file). Undeclared (the
		// default for every current row) leaves the key unset, preserving
		// today's `self::DEFAULT_ALLOWED` fallback exactly.
		if ( isset( $vertical['allowed_tools'] ) && is_array( $vertical['allowed_tools'] ) && ! empty( $vertical['allowed_tools'] ) ) {
			$opts['allowed_tools'] = array_values( array_unique( array_map( 'strval', $vertical['allowed_tools'] ) ) );
		}
		return $opts;
	}

	/**
	 * Server-authorized mode/plan/guest check for a resolved vertical row.
	 *
	 * Mirrors the exact tier resolver and rank table
	 * `modules/twinweb/includes/class-twinweb-rest.php::get_effective_config()`
	 * already uses for its mode-LISTING check (`bizcity_twinweb_user_tier`
	 * filter, `free < plus < pro`) and the identity resolver
	 * `core/twinbrain/includes/class-twinbrain-goal-loop-rest.php::identity()`
	 * already uses to read `BizCity_TwinWeb_Identity::current()` from
	 * `core/twinbrain` without a hard dependency on `modules/twinweb` (guarded
	 * by `class_exists()`, degrading to `user_id <= 0` when TwinWeb is not
	 * loaded). Does not replace `woo_bizops`'s own independent capability
	 * check in `class-twinbrain-woo-bizops-resolver-service.php`.
	 *
	 * @param array<string,mixed> $vertical Registry row (id, min_plan, guest_allowed, ...).
	 * @param array<string,mixed> $opts
	 */
	private function vertical_policy_allows( array $vertical, array $opts ): bool {
		$user_id  = (int) ( $opts['user_id'] ?? get_current_user_id() );
		$is_guest = $user_id <= 0;
		if ( class_exists( 'BizCity_TwinWeb_Identity' ) ) {
			$identity = BizCity_TwinWeb_Identity::current();
			if ( isset( $identity['is_guest'] ) ) {
				$is_guest = (bool) $identity['is_guest'];
			}
			if ( $user_id <= 0 && ! empty( $identity['user_id'] ) ) {
				$user_id = (int) $identity['user_id'];
			}
		}
		if ( $is_guest && empty( $vertical['guest_allowed'] ) ) {
			return false;
		}
		$plan_rank = array( 'free' => 0, 'plus' => 1, 'pro' => 2 );
		$min_plan  = isset( $vertical['min_plan'] ) ? sanitize_key( (string) $vertical['min_plan'] ) : 'free';
		$tier      = function_exists( 'apply_filters' )
			? sanitize_key( (string) apply_filters( 'bizcity_twinweb_user_tier', 'free', $user_id ) )
			: 'free';
		$user_rank     = $plan_rank[ $tier ] ?? 0;
		$required_rank = $plan_rank[ $min_plan ] ?? 0;
		return $user_rank >= $required_rank;
	}

	/**
	 * Resolve the canonical Context Bank MPR options before the Notebook Source Layer runs.
	 *
	 * [2026-09-15 Johnny Chu - Chu Hoàng Anh] PHASE-1.33C — the source layer only
	 * queries Context Bank when `context_bank_enabled` is set or the site option
	 * `bizcity_context_bank_mpr_enabled` is true. No TwinBrain caller previously
	 * set either, so Context Bank never executed even when rollups existed.
	 * The canonical default now lives in `BizCity_Context_Bank_Mode_Policy` and is
	 * ON by default; only an explicit stored `0` disables the layer.
	 * This resolver keeps the mode/account scope server-owned and never accepts
	 * browser-supplied tenant, owner or contract hints.
	 *
	 * @param array<string,mixed> $opts
	 * @return array<string,mixed>
	 */
	private function resolve_context_bank_opts( array $opts ): array {
		if ( array_key_exists( 'context_bank_enabled', $opts ) ) {
			return $opts;
		}
		// [2026-09-15 Johnny Chu - Chu Hoàng Anh] PHASE-1.33C — read the flag from its canonical owner; fall back to the same default when the owner is not loaded.
		if ( class_exists( 'BizCity_Context_Bank_Mode_Policy' ) && method_exists( 'BizCity_Context_Bank_Mode_Policy', 'is_enabled' ) ) {
			$enabled = (bool) BizCity_Context_Bank_Mode_Policy::is_enabled();
		} elseif ( function_exists( 'get_option' ) ) {
			$stored  = get_option( 'bizcity_context_bank_mpr_enabled', null );
			$enabled = ( null === $stored || '' === $stored ) ? true : (bool) $stored;
		} else {
			$enabled = true;
		}
		/**
		 * Filter: bizcity_twinbrain_context_bank_enabled
		 *
		 * Allows an approved canary to enable or disable Context Bank for a
		 * bounded surface without changing the site-wide option.
		 *
		 * @param bool                $enabled
		 * @param array<string,mixed> $opts
		 */
		$enabled = (bool) apply_filters( 'bizcity_twinbrain_context_bank_enabled', $enabled, $opts );
		$opts['context_bank_enabled'] = $enabled;
		if ( ! $enabled ) {
			return $opts;
		}
		if ( empty( $opts['context_bank_mode'] ) ) {
			$opts['context_bank_mode'] = 'context_bank';
		}
		return $opts;
	}

	private function has_retrieve_route( array $final_gate ): bool {
		foreach ( (array) ( $final_gate['scoreboard']['rows'] ?? array() ) as $row ) {
			if ( is_array( $row ) && strtoupper( (string) ( $row['route'] ?? '' ) ) === 'RETRIEVE' ) {
				return true;
			}
		}
		return false;
	}

	private function build_retrieve_prompt( string $prompt, array $final_gate, array $opts, int $round_number = 1 ): string {
		$obligations = array();
		foreach ( (array) ( $opts['goal_contract']['answer_obligations'] ?? $opts['goal_loop']['answer_obligations'] ?? array() ) as $item ) {
			if ( ! is_array( $item ) || empty( $item['id'] ) ) {
				continue;
			}
			foreach ( (array) ( $final_gate['scoreboard']['rows'] ?? array() ) as $row ) {
				if ( is_array( $row ) && (string) ( $row['obligation_id'] ?? '' ) === (string) $item['id'] && strtoupper( (string) ( $row['route'] ?? '' ) ) === 'RETRIEVE' ) {
					$obligations[] = (string) ( $item['question'] ?? $row['gap'] ?? '' );
					break;
				}
			}
		}
		$obligations = array_values( array_filter( array_unique( $obligations ) ) );
		$gap_text = ! empty( $obligations ) ? implode( '; ', array_slice( $obligations, 0, 5 ) ) : 'bổ sung bằng chứng cho các nghĩa vụ trả lời còn thiếu';
		return trim( $prompt . "\n\n[Retrieve round {$round_number}]\nTìm thêm evidence cụ thể để giải quyết: " . $gap_text );
	}

	/**
	 * [2026-08-06 Johnny Chu] V4-DEPTH — accepts an explicit $round_number so
	 * this method can be called multiple times in a bounded loop for the
	 * 'deep' answer_depth tier, while 'high' (default) still calls it exactly
	 * once with round_number=1 — unchanged behaviour.
	 */
	private function run_bounded_retrieve_round( string $trace_id, string $prompt, array $final_gate, array $opts, array $tool_results, $sse, int $round_number = 1 ): array {
		// [2026-08-04 Johnny Chu] R-MPR-GOALBOARD — run one bounded evidence round through the existing selector, perspective, source, and synthesizer layers.
		$finish_failure = function ( string $reason ) use ( $trace_id, $sse, $round_number ) {
			$payload = array(
				'trace_id' => $trace_id,
				'round'    => $round_number,
				'status'   => 'failed',
				'reason'   => $reason,
			);
			if ( is_object( $sse ) && method_exists( $sse, 'emit' ) ) {
				$sse->emit( 'retrieve_round_done', $payload );
			}
			$this->emit_event( 'decision', array_merge( array( 'surface' => self::SURFACE, 'stage' => 'retrieve_round_done' ), $payload ) );
			return array( 'ok' => false, 'reason' => $reason );
		};
		$retrieve_prompt = $this->build_retrieve_prompt( $prompt, $final_gate, $opts, $round_number );
		$round_payload = array(
			'trace_id' => $trace_id,
			'round'    => $round_number,
			'query'    => $retrieve_prompt,
			'reason'   => 'resolution_scoreboard_retrieve',
		);
		if ( is_object( $sse ) && method_exists( $sse, 'emit' ) ) {
			$sse->emit( 'retrieve_round_started', $round_payload );
		}
		$this->emit_event( 'decision', array_merge( array( 'surface' => self::SURFACE, 'stage' => 'retrieve_round_started', 'stage_version' => self::MPR_GATE_STAGE_VERSION ), $round_payload ) );

		if ( ! class_exists( 'BizCity_TwinBrain_Notebook_Selector' ) || ! class_exists( 'BizCity_TwinBrain_Perspective_Runner' ) || ! class_exists( 'BizCity_TwinBrain_Synthesizer' ) ) {
			return $finish_failure( 'retrieve_runtime_unavailable' );
		}
		$user_id = (int) ( $opts['user_id'] ?? get_current_user_id() );
		$k = max( 3, min( BIZCITY_TWINBRAIN_K_MAX, (int) ( $opts['k'] ?? BIZCITY_TWINBRAIN_K_DEFAULT ) ) );
		$selector = BizCity_TwinBrain_Notebook_Selector::instance();
		$round_candidates = $selector->select( $retrieve_prompt, $user_id, $k, array(
			'guru_id' => (int) ( $opts['guru_id'] ?? 0 ),
		) );
		if ( empty( $round_candidates ) ) {
			return $finish_failure( 'retrieve_no_candidates' );
		}
		$existing_ids = array();
		foreach ( (array) ( $opts['retrieve_exclude_ids'] ?? $opts['candidates'] ?? array() ) as $candidate ) {
			if ( is_array( $candidate ) ) {
				$existing_ids[] = (int) ( $candidate['notebook_id'] ?? 0 );
			}
		}
		$round_candidates = array_values( array_filter( $round_candidates, static function ( $candidate ) use ( $existing_ids ) {
			return is_array( $candidate ) && ! in_array( (int) ( $candidate['notebook_id'] ?? 0 ), $existing_ids, true );
		} ) );
		if ( empty( $round_candidates ) ) {
			return $finish_failure( 'retrieve_candidates_already_used' );
		}

		$round_opts = $opts;
		$round_opts['retrieve_round'] = $round_number;
		// [2026-09-16 Johnny Chu - Chu Hoàng Anh] PHASE-1.33D-C11 — expose the round number so the memoized Context Bank phase reports how many rounds reused it instead of silently re-querying per round.
		$round_opts['_context_bank_round'] = $round_number;
		$round_opts['notebook_search_context_query'] = $retrieve_prompt;
		$round_answers = BizCity_TwinBrain_Perspective_Runner::instance()->run( $trace_id, $retrieve_prompt, $round_candidates, $round_opts );
		if ( class_exists( 'BizCity_TwinBrain_Notebook_Source_Layer' ) ) {
			try {
				$source_payload = BizCity_TwinBrain_Notebook_Source_Layer::instance()->build_from_turn( $round_candidates, $round_answers, $round_opts );
				// [2026-09-02 11:29 AM Johnny Chu - Chu Hoàng Anh] PHASE-CB4.5 — preserve internal Skill owner records across bounded retrieval rounds.
				// [2026-09-15 Johnny Chu - Chu Hoàng Anh] PHASE-1.33C — also preserve the bounded Context Bank timeline payload across retrieve rounds.
				foreach ( array( 'notebook_source_map', 'notebook_source_block_md', 'notebook_source_counts', 'source_file_briefs', 'source_file_counts', 'search_context', 'search_context_results', 'search_context_total', 'cross_notebook_links', 'graph_vector_rerank_pack', 'graph_entities', 'retrieval_candidates', 'final_context_chunks', 'retrieval_candidate_count', 'final_context_count', 'rerank_method', 'rerank_degraded', 'rerank_error', 'vector_status', 'vector_candidate_count', 'vector_degraded_reason', 'graph_candidate_count', 'selector_hardening_applied', 'selector_hardening_reason', 'selector_hardening_count', 'selector_hardening_scope', 'training_gap_report', 'product_entities', 'product_entity_count', 'product_name_entity_count', 'context_bank', 'context_bank_source_refs', 'context_bank_source_count', 'context_bank_owner_records', 'context_bank_retrieval' ) as $key ) {
					if ( array_key_exists( $key, $source_payload ) ) {
						$round_opts[ $key ] = $source_payload[ $key ];
					}
				}
			} catch ( \Throwable $e ) {
				return $finish_failure( 'retrieve_source_layer_exception' );
			}
		}
		$round_synthesis = BizCity_TwinBrain_Synthesizer::instance()->synthesize( $trace_id, $retrieve_prompt, $round_answers, $tool_results, $round_opts );
		$retrieve_synthesis_payload = array_merge( $round_payload, array(
			'answer_chars'  => mb_strlen( (string) ( $round_synthesis['answer_md'] ?? '' ) ),
			'citation_count' => count( (array) ( $round_synthesis['citations'] ?? array() ) ),
		) );
		if ( is_object( $sse ) && method_exists( $sse, 'emit' ) ) {
			$sse->emit( 'retrieve_synthesis_done', $retrieve_synthesis_payload );
		}
		$this->emit_event( 'decision', array_merge( array( 'surface' => self::SURFACE, 'stage' => 'retrieve_synthesis_done', 'stage_version' => self::MPR_GATE_STAGE_VERSION ), $retrieve_synthesis_payload ) );
		$round_done = array_merge( $round_payload, array(
			'candidate_count' => count( $round_candidates ),
			'answer_count'    => count( $round_answers ),
			'citation_count'  => count( (array) ( $round_synthesis['citations'] ?? array() ) ),
		) );
		if ( is_object( $sse ) && method_exists( $sse, 'emit' ) ) {
			$sse->emit( 'retrieve_round_done', $round_done );
		}
		$this->emit_event( 'decision', array_merge( array( 'surface' => self::SURFACE, 'stage' => 'retrieve_round_done', 'stage_version' => self::MPR_GATE_STAGE_VERSION ), $round_done ) );
		return array(
			'ok'         => true,
			'prompt'     => $retrieve_prompt,
			'candidates' => $round_candidates,
			'answers'    => $round_answers,
			'opts'       => $round_opts,
			'synthesis'  => $round_synthesis,
		);
	}

	public function finalize_with_gate( string $trace_id, string $prompt, array $draft, array $opts, $sse ): array {
		// [2026-08-04 Johnny Chu] V3.2 — centralize Draft/Reflection/Retrieve gate decisions before every final branch.
		// [2026-08-06 Johnny Chu] V4-DEPTH — 'fast' tier skips Reflection entirely regardless of obligations.
		$answer_depth_cfg = isset( $opts['_answer_depth_cfg'] ) && is_array( $opts['_answer_depth_cfg'] )
			? $opts['_answer_depth_cfg']
			: $this->resolve_answer_depth_config( $opts );
		$contract = is_array( $opts['goal_contract'] ?? null ) ? $opts['goal_contract'] : array();
		$obligations = (array) ( $contract['answer_obligations'] ?? $opts['goal_loop']['answer_obligations'] ?? array() );
		$emit_checkpoint = function ( string $event, array $payload ) use ( $sse ) {
			if ( is_object( $sse ) && method_exists( $sse, 'emit' ) ) {
				$sse->emit( $event, $payload );
			}
		};

		$draft_payload = array(
			'trace_id'         => $trace_id,
			'answer_chars'     => mb_strlen( (string) ( $draft['answer_md'] ?? '' ) ),
			'obligation_count' => count( $obligations ),
			'citation_count'   => count( (array) ( $draft['citations'] ?? array() ) ),
			'evidence_count'   => count( (array) ( $draft['cited_passages'] ?? array() ) ),
		);
		$emit_checkpoint( 'draft_ready', $draft_payload );
		$this->emit_event( 'decision', array_merge( array( 'surface' => self::SURFACE, 'stage' => 'draft_ready', 'stage_version' => self::MPR_GATE_STAGE_VERSION ), $draft_payload ) );

		$reflection = array();
		$reflection_error = '';
		if ( $answer_depth_cfg['skip_reflection'] ) {
			// [2026-08-06 Johnny Chu] V4-DEPTH — 'fast' mode: single-pass answer, no Reflector call, no bounded_fallback.
			$reflection_error = '';
		} elseif ( ! empty( $obligations ) ) {
			// [2026-10-06 23:24 Johnny Chu - Chu Hoàng Anh] TWINBRAIN-SPLIT-CORE-ADDON W-1 — Goal Loop Reflector archived.
			$reflection_error = 'reflector_unavailable';
		}

		$scoreboard = is_array( $reflection['resolution_scoreboard'] ?? null )
			? $reflection['resolution_scoreboard']
			: array();
		$ready = empty( $obligations ) || $answer_depth_cfg['skip_reflection'] || ( ! empty( $scoreboard ) && ! empty( $scoreboard['overall_ready_for_final'] ) );
		$retrieve_round = max( 0, (int) ( $scoreboard['retrieve_round'] ?? 0 ), (int) ( $opts['retrieve_round'] ?? 0 ) );
		$scoreboard['retrieve_round'] = $retrieve_round;
		$gate_reason = $reflection_error;
		if ( ! $ready && $gate_reason === '' ) {
			$gate_reason = $retrieve_round > 0 ? 'retrieve_round_exhausted' : 'retrieve_required';
		}
		$final_gate = array(
			'status'            => empty( $obligations ) ? 'skipped_no_contract' : ( $answer_depth_cfg['skip_reflection'] ? 'skipped_fast_mode' : ( $ready ? 'open' : 'bounded_fallback' ) ),
			'terminal'          => true,
			'ready_for_final'   => $ready,
			'fallback_applied'  => ! $ready,
			'gate_reason'       => $gate_reason,
			'fallback_policy'   => ! $ready ? 'answer_with_limit_notice' : 'normal_answer',
			'requires_review'   => ! $ready,
			'scoreboard_version' => (string) ( $scoreboard['scoreboard_version'] ?? 'v1' ),
			'scoreboard'        => $scoreboard,
			'retrieve_round'    => $retrieve_round,
			'reflection_method' => (string) ( $reflection['reflection']['method'] ?? 'none' ),
			'completion_score'  => (float) ( $reflection['completion_score'] ?? 0 ),
			'gaps'              => (array) ( $reflection['gaps'] ?? array() ),
			'answer_depth'      => (string) $answer_depth_cfg['depth'], // [2026-08-06 Johnny Chu] V4-DEPTH — expose resolved depth tier in gate output for replay/CRM trace.
			'max_retrieve_rounds' => (int) $answer_depth_cfg['max_retrieve_rounds'],
		);
		$reflection_payload = array(
			'trace_id'          => $trace_id,
			'status'            => $final_gate['status'],
			'skipped'           => ! empty( $answer_depth_cfg['skip_reflection'] ), // [2026-08-07 Johnny Chu] V4-DEPTH — keep the timeline truthful when fast mode omits Reflection.
			'skip_reason'       => ! empty( $answer_depth_cfg['skip_reflection'] ) ? 'answer_depth_fast' : '',
			'ready_for_final'   => $ready,
			'scoreboard_version' => $final_gate['scoreboard_version'],
			'scoreboard'        => $scoreboard,
			'retrieve_round'   => $retrieve_round,
			'method'            => $final_gate['reflection_method'],
			'completion_score'  => $final_gate['completion_score'],
			'gaps'             => $final_gate['gaps'],
			'error'            => $reflection_error,
		);
		$emit_checkpoint( 'reflection_done', $reflection_payload );
		$this->emit_event( 'decision', array_merge( array( 'surface' => self::SURFACE, 'stage' => 'reflection_done', 'stage_version' => self::MPR_GATE_STAGE_VERSION ), $reflection_payload ) );
		$decision_payload = array(
			'trace_id'         => $trace_id,
			'status'           => $final_gate['status'],
			'answer_depth'     => (string) ( $final_gate['answer_depth'] ?? self::ANSWER_DEPTH_DEFAULT ), // [2026-08-07 Johnny Chu] V4-DEPTH — expose global prompt tier directly on the terminal gate event.
			'max_retrieve_rounds' => (int) ( $final_gate['max_retrieve_rounds'] ?? 0 ),
			'terminal'         => true,
			'ready_for_final'  => $ready,
			'fallback_applied' => ! $ready,
			'gate_reason'      => $gate_reason,
			'fallback_policy'  => $final_gate['fallback_policy'],
			'requires_review'  => $final_gate['requires_review'],
			'retrieve_round'   => $retrieve_round,
			'scoreboard_version' => $final_gate['scoreboard_version'],
		);
		$emit_checkpoint( 'final_gate_decision', $decision_payload );
		$this->emit_event( 'decision', array_merge( array( 'surface' => self::SURFACE, 'stage' => 'final_gate_decision', 'stage_version' => self::MPR_GATE_STAGE_VERSION ), $decision_payload ) );

		return $final_gate;
	}

	public function build_turn_snapshot( string $trace_id, array $opts, array $parts ): array {
		$snap = array(
			'trace_id'        => $trace_id,
			'answer_depth'    => (string) ( $parts['answer_depth'] ?? $opts['answer_depth'] ?? ( $parts['final_gate']['answer_depth'] ?? self::ANSWER_DEPTH_DEFAULT ) ), // [2026-08-07 Johnny Chu] V4-DEPTH — make the global prompt tier first-class in replay snapshots.
			'candidates'      => array_values( (array) ( $parts['candidates'] ?? array() ) ),
			'tool_candidates' => array_values( (array) ( $parts['tool_candidates'] ?? array() ) ),
			'keyword_tokens'  => array_values( (array) ( $opts['keyword_tokens'] ?? array() ) ),
			'web_mode'        => (string) ( $parts['web_mode'] ?? strtolower( (string) ( $opts['web_mode'] ?? 'off' ) ) ),
			'mode'            => (string) ( $parts['mode'] ?? 'brain' ),
			'perspectives'    => array(),
			'notebook_source' => array(),
			'synthesis'       => array(),
			'final'           => array(),
			'web_research'    => array(),
			'tool_dispatch'   => array(),
			'final_gate'      => (array) ( $parts['final_gate'] ?? array() ),
			'agent'           => array(),
			'cited_entity_ids'=> array_values( (array) ( $parts['cited_entity_ids'] ?? array() ) ),
			'cited_passages'  => array_values( (array) ( $parts['cited_passages'] ?? array() ) ),
			'duration_ms'     => (int) ( $parts['duration_ms'] ?? 0 ),
		);

		if ( ! empty( $parts['notebook_source'] ) && is_array( $parts['notebook_source'] ) ) {
			// [2026-07-18 Johnny Chu] PHASE-TBR-NB-MOAT — preserve source map and W0.7 source-file briefs for session replay.
			$snap['notebook_source'] = array(
				'map'                => array_values( (array) ( $parts['notebook_source']['map'] ?? array() ) ),
				'block_md'           => (string) ( $parts['notebook_source']['block_md'] ?? '' ),
				'counts'             => (array) ( $parts['notebook_source']['counts'] ?? array() ),
				'source_file_briefs' => array_values( (array) ( $parts['notebook_source']['source_file_briefs'] ?? array() ) ),
				'source_file_counts' => (array) ( $parts['notebook_source']['source_file_counts'] ?? array() ),
				// [2026-07-19 Johnny Chu] PHASE-TBR-NB-MOAT W0.10 — preserve top TwinSearch hits for session replay.
				'search_context'     => (array) ( $parts['notebook_source']['search_context'] ?? array() ),
				'search_context_results' => array_values( (array) ( $parts['notebook_source']['search_context_results'] ?? array() ) ),
				'search_context_total' => (int) ( $parts['notebook_source']['search_context_total'] ?? 0 ),
			);
		}

		foreach ( (array) ( $parts['perspectives'] ?? array() ) as $a ) {
			if ( ! is_array( $a ) ) {
				continue;
			}
			$snap['perspectives'][] = array(
				'notebook_id' => (int)    ( $a['notebook_id'] ?? 0 ),
				'label'       => (string) ( $a['label']       ?? '' ),
				'stance'      => (string) ( $a['stance']      ?? '' ),
				'confidence'  => (float)  ( $a['confidence']  ?? 0 ),
				'answer_md'   => (string) ( $a['answer_md']   ?? '' ),
				'reason'      => (string) ( $a['reason']      ?? '' ),
				'ms'          => (int)    ( $a['ms']          ?? 0 ),
				'tokens'      => (int)    ( $a['tokens']      ?? 0 ),
				'citations'   => array_values( (array) ( $a['citations'] ?? array() ) ),
			);
		}

		if ( isset( $parts['synthesis'] ) && is_array( $parts['synthesis'] ) ) {
			$s = $parts['synthesis'];
			$snap['synthesis'] = array(
				'answer_md'      => (string) ( $s['answer_md']      ?? '' ),
				'consensus'      => array_values( (array) ( $s['consensus'] ?? array() ) ),
				'tensions'       => array_values( (array) ( $s['tensions']  ?? array() ) ),
				'recommendation' => (string) ( $s['recommendation'] ?? '' ),
				'citations'      => array_values( (array) ( $s['citations'] ?? array() ) ),
				'tokens'         => (int)    ( $s['tokens'] ?? 0 ),
				'ms'             => (int)    ( $s['ms']     ?? 0 ),
				'fallback'       => (string) ( $s['fallback'] ?? '' ),
			);
		}

		if ( isset( $parts['final'] ) && is_array( $parts['final'] ) ) {
			$f = $parts['final'];
			$snap['final'] = array(
				'answer_md' => (string) ( $f['answer_md'] ?? '' ),
				'streaming' => false,
				'chunks'    => (int)    ( $f['chunks'] ?? 0 ),
				'tokens'    => (int)    ( $f['tokens'] ?? 0 ),
				'model'     => (string) ( $f['model']  ?? '' ),
				'ms'        => (int)    ( $f['ms']     ?? 0 ),
				'fallback'  => (string) ( $f['fallback'] ?? '' ),
				'success'   => ! empty( $f['success'] ),
				'evidence_fallback' => ! empty( $f['evidence_fallback'] ),
				'evidence_fallback_trigger' => (string) ( $f['evidence_fallback_trigger'] ?? '' ),
				'deep_research_offer' => ! empty( $f['deep_research_offer'] ),
			);
		}

		if ( ! empty( $parts['web_research'] ) && is_array( $parts['web_research'] ) ) {
			$snap['web_research'] = $parts['web_research'];
		}
		if ( ! empty( $parts['tool_dispatch'] ) && is_array( $parts['tool_dispatch'] ) ) {
			$snap['tool_dispatch'] = $parts['tool_dispatch'];
		}
		if ( ! empty( $parts['agent'] ) && is_array( $parts['agent'] ) ) {
			$snap['agent'] = $parts['agent'];
		}

		return $snap;
	}

	// [2026-10-07 10:05 Johnny Chu - Chu Hoàng Anh] TWINBRAIN-SPLIT-CORE-ADDON W-3 — stream_agent_react (mode=agent) removed with the archived
	// Agent_Runner; the Astro mode pipeline (stream_astro_mode, stream_astro_relation_mode, build_astro_*) moved to
	// bizcity-twin-brain-addon/twinbrain-verticals/includes/class-twinbrain-astro-mode-handler.php and is reached
	// through the `bizcity_twinbrain_vertical_takeover` filter.

	/**
	 * [2026-07-24 Johnny Chu] PHASE-0.46 W5 S5.2/S5.3 — shared phrase heuristic:
	 * does this prompt ask about "today's notebook/note/meeting"? Extracted out
	 * of start_turn() (was inline) so complete_turn()/complete_turn_stream() can
	 * reuse the exact same detection to flag `opts['day_scope_summary']` for
	 * the Synthesizer's meeting-summary framing hint (S5.3) — one detector,
	 * two consumers, no drift between retrieval scoping and prompt framing.
	 */
	private function is_day_notebook_summary_query( string $prompt ): bool {
		$prompt_lc = mb_strtolower( $prompt );
		return ( strpos( $prompt_lc, 'hôm nay' ) !== false || strpos( $prompt_lc, 'hom nay' ) !== false )
			&& ( strpos( $prompt_lc, 'ghi chú' ) !== false || strpos( $prompt_lc, 'ghi chu' ) !== false
				|| strpos( $prompt_lc, 'notebook' ) !== false || strpos( $prompt_lc, 'note' ) !== false
				|| strpos( $prompt_lc, 'cuộc họp' ) !== false || strpos( $prompt_lc, 'cuoc hop' ) !== false );
	}

	/* =================================================================
	 *  TBR.W18 — Brain auto-degrade chat path (2026-05-28)
	 *  Short-circuit complete_turn_stream when K=0 candidates && K=0
	 *  tool candidates && web_mode=off && memory_block non-empty.
	 *  Skips Perspective / Tool / Synthesizer layers; runs only:
	 *    1) pipeline_auto_degraded event (FE timeline marker)
	 *    2) Final_Composer::compose_chat_stream (memory-only narrative)
	 *    3) Memory_Tool_Dispatcher (function-call cleanup)
	 *    4) Memory_Writer (L4.7 persist)
	 *    5) cite_resolved + brain_synthesize + assistant_message
	 * ================================================================ */
	private function stream_auto_degrade_chat(
		string $trace_id,
		string $prompt,
		BizCity_Twin_SSE_Writer $sse,
		array $opts,
		float $wall_t0
	): array {
		$memory_block = trim( (string) ( $opts['memory_block'] ?? '' ) );

		$auto_payload = [
			'trace_id'          => $trace_id,
			'surface'           => self::SURFACE,
			'reason'            => 'no_candidates_memory_present',
			'memory_block_len'  => mb_strlen( $memory_block ),
		];
		$sse->emit( 'pipeline_auto_degraded', $auto_payload );
		$this->emit_event( 'pipeline_auto_degraded', $auto_payload );

		/* Emit skeleton phase events so the FE timeline reducer (which
		 * subscribes to perspectives_started / perspectives_done /
		 * synthesis_started / synthesis_done) doesn't stall waiting for
		 * frames that will never arrive. Count=0 + degraded=true lets
		 * the FE collapse these rows into a single "Auto chat" badge. */
		$sse->emit( 'perspectives_started', [
			'trace_id' => $trace_id,
			'count'    => 0,
			'degraded' => true,
		] );
		$sse->emit( 'perspectives_done', [
			'trace_id' => $trace_id,
			'count'    => 0,
			'ms'       => 0,
			'degraded' => true,
		] );
		$tool_decision = [
			'decision' => 'no_op',
			'reason'   => 'auto_degraded',
			'tool'     => '',
		];
		$sse->emit( 'tool_decided', array_merge( [ 'trace_id' => $trace_id ], $tool_decision ) );
		$this->emit_event( 'tool_decided', array_merge(
			[ 'trace_id' => $trace_id, 'surface' => self::SURFACE ],
			$tool_decision
		) );
		$sse->emit( 'synthesis_started', [ 'trace_id' => $trace_id, 'degraded' => true ] );
		$synthesis = [
			'answer_md'       => '',
			'consensus'       => [],
			'tensions'        => [],
			'recommendation'  => '',
			'citations'       => [],
			'tokens'          => 0,
			'model'           => '',
			'fallback'        => 'auto_degraded',
		];
		$sse->emit( 'synthesis_done', [
			'trace_id'       => $trace_id,
			'answer_md'      => '',
			'consensus'      => [],
			'tensions'       => [],
			'recommendation' => '',
			'citations'      => [],
			'tokens'         => 0,
			'ms'             => 0,
			'fallback'       => 'auto_degraded',
		] );
		$final_gate = $this->finalize_with_gate( $trace_id, $prompt, array(
			'answer_md' => '',
			'citations' => array(),
		), $opts, $sse );

		/* Layer 4.5 — Final Composer (chat variant). */
		$final_t0  = microtime( true );
		$final_seq = 0;
		$final_keepalive_seq = 0;
		$sse->emit( 'final_started', [ 'trace_id' => $trace_id, 'degraded' => true, 'final_gate' => $final_gate ] );

		$composer = BizCity_TwinBrain_Final_Composer::instance();
		// [2026-07-07 Johnny Chu] HOTFIX — keepalive evidence while chat composer waits.
		$chat_opts = array_merge( $opts, array(
			'on_keepalive' => static function () use ( $sse, $trace_id, &$final_keepalive_seq ) {
				$final_keepalive_seq++;
				$sse->emit( 'final_keepalive', array(
					'trace_id' => $trace_id,
					'seq'      => $final_keepalive_seq,
					'mode'     => 'chat_auto_degrade',
					'status'   => 'still_running',
				) );
				$sse->maybe_heartbeat();
			},
		) );
		$final    = $composer->compose_chat_stream(
			$trace_id,
			$prompt,
			$chat_opts,
			function ( $delta, $accumulated ) use ( $sse, $trace_id, &$final_seq ) {
				$final_seq++;
				$sse->emit( 'final_token', [
					'trace_id' => $trace_id,
					'seq'      => $final_seq,
					'delta'    => (string) $delta,
					'len'      => mb_strlen( (string) $accumulated ),
				] );
			}
		);
		$final_ms   = (int) ( ( microtime( true ) - $final_t0 ) * 1000 );
		$final_text = (string) ( $final['answer_md'] ?? '' );

		$sse->emit( 'final_done', [
			'trace_id'  => $trace_id,
			'answer_md' => $final_text,
			'tokens'    => (int)    ( $final['tokens']   ?? 0 ),
			'model'     => (string) ( $final['model']    ?? '' ),
			'ms'        => $final_ms,
			'chunks'    => $final_seq,
			'fallback'  => (string) ( $final['fallback'] ?? '' ),
			'success'   => ! empty( $final['success'] ),
			'degraded'  => true,
			'final_gate' => $final_gate,
		] );

		/* Memory tool dispatcher — same as main path. */
		if ( class_exists( 'BizCity_TwinBrain_Memory_Tool_Dispatcher' ) && $final_text !== '' ) {
			try {
				$tool_disp = BizCity_TwinBrain_Memory_Tool_Dispatcher::instance()->dispatch_from_text(
					$final_text,
					[
						'trace_id'   => $trace_id,
						'user_id'    => (int)    ( $opts['subject_id'] ?? $opts['user_id'] ?? get_current_user_id() ),
						'session_id' => (string) ( $opts['session_id'] ?? '' ),
						'identity_uuid' => (string) ( $opts['identity_uuid'] ?? '' ),
						'identity_is_stable' => ! empty( $opts['identity_is_stable'] ),
						'surface'    => self::SURFACE,
						'on_event'   => function ( string $event_key, array $payload ) use ( $sse ) {
							$sse->emit( $event_key, $payload );
							$this->emit_event( $event_key, $payload );
						},
					]
				);
				if ( ! empty( $tool_disp['ops'] ) ) {
					$sse->emit( 'memory_tool_summary', [
						'trace_id'   => $trace_id,
						'tool_calls' => (int) $tool_disp['tool_calls'],
						'persisted'  => (int) $tool_disp['persisted'],
						'forgotten'  => (int) $tool_disp['forgotten'],
						'recalled'   => (int) $tool_disp['recalled'],
						'skipped'    => (int) $tool_disp['skipped'],
						'errors'     => (array) $tool_disp['errors'],
						'latency_ms' => (int) $tool_disp['latency_ms'],
					] );
					$rewritten = (string) $tool_disp['rewritten_text'];
					if ( $rewritten !== '' ) {
						$final_text = $rewritten;
						$final['answer_md'] = $rewritten;
					}
				}
			} catch ( \Throwable $e ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( '[TwinBrain][auto-degrade][memory_tool_dispatcher][error] trace=' . $trace_id . ' ' . $e->getMessage() );
				}
				self::write_runtime_log( 'error', 'auto_degrade_memory_tool_dispatcher_exception', $e->getMessage(), array(
					'trace_id' => $trace_id,
					'surface' => self::SURFACE,
					'mode' => 'auto_degrade',
					'exception_class' => get_class( $e ),
				) );
			}
		}

		/* Layer 4.7 — Memory_Writer (persist 'hãy nhớ ...'). */
		if ( class_exists( 'BizCity_TwinBrain_Memory_Writer' ) ) {
			try {
				$mw = BizCity_TwinBrain_Memory_Writer::instance()->extract_and_persist(
					$trace_id,
					$prompt,
					$final_text,
					[
						'user_id'    => (int)    ( $opts['subject_id'] ?? $opts['user_id'] ?? get_current_user_id() ),
						'session_id' => (string) ( $opts['session_id'] ?? '' ),
						// [2026-07-28 Johnny Chu] R-CH-IDMEM — keep UUID ownership on the auto-degrade memory-writer path.
						'identity_uuid' => (string) ( $opts['identity_uuid'] ?? '' ),
						'identity_is_stable' => ! empty( $opts['identity_is_stable'] ),
						'goal_loop'    => (array) ( $opts['goal_loop'] ?? array() ),
						'memory_scope' => (string) ( $opts['memory_scope'] ?? '' ),
						'case_id'      => (string) ( $opts['case_id'] ?? '' ),
						'subject_key'  => (string) ( $opts['subject_key'] ?? '' ),
					]
				);
				$write_payload = [
					'trace_id'   => $trace_id,
					'persisted'  => (int)    ( $mw['persisted']  ?? 0 ),
					'mode'       => (string) ( $mw['mode']       ?? '' ),
					'ops'        => (array)  ( $mw['ops']        ?? [] ),
					'latency_ms' => (int)    ( $mw['latency_ms'] ?? 0 ),
				];
				$sse->emit( 'memory_write', $write_payload );
				$this->emit_event( 'memory_write', $write_payload );
			} catch ( \Throwable $e ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( '[TwinBrain][auto-degrade][memory_write][error] ' . $e->getMessage() );
				}
				self::write_runtime_log( 'error', 'auto_degrade_memory_write_exception', $e->getMessage(), array(
					'trace_id' => $trace_id,
					'surface' => self::SURFACE,
					'mode' => 'auto_degrade',
					'exception_class' => get_class( $e ),
				) );
			}

			// [2026-06-03 Johnny Chu] BRAIN-SESSIONS BS-4 — mood sampler (auto-degrade path).
			$session_id = (string) ( $opts['session_id'] ?? '' );
			if ( $session_id !== '' && method_exists( 'BizCity_TwinBrain_Memory_Writer', 'sample_mood' ) ) {
				try {
					$turn_index = $this->count_user_turns_for_session( $session_id );
					$mood = BizCity_TwinBrain_Memory_Writer::instance()->sample_mood( [
						'trace_id'   => $trace_id,
						'session_id' => $session_id,
						'user_id'    => (int) ( $opts['subject_id'] ?? $opts['user_id'] ?? get_current_user_id() ),
						'turn_index' => $turn_index,
						'prompt'     => (string) $prompt,
						'answer'     => (string) $final_text,
					] );
					if ( ( $mood['status'] ?? '' ) === 'sampled' ) {
						$sse->emit( 'mood_sampled', [
							'trace_id'   => $trace_id,
							'session_id' => $session_id,
							'turn_index' => (int)    ( $mood['turn_index'] ?? $turn_index ),
							'valence'    => (float)  ( $mood['valence']    ?? 0.0 ),
							'label'      => (string) ( $mood['label']      ?? '' ),
							'event_uuid' => (string) ( $mood['event_uuid'] ?? '' ),
						] );
					}
				} catch ( \Throwable $e ) {
					if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
						error_log( '[TwinBrain][auto-degrade][mood_sampled][error] ' . $e->getMessage() );
					}
					self::write_runtime_log( 'error', 'auto_degrade_mood_sampled_exception', $e->getMessage(), array(
						'trace_id' => $trace_id,
						'surface' => self::SURFACE,
						'mode' => 'auto_degrade',
						'exception_class' => get_class( $e ),
					) );
				}
			}
		}

		// Promote final text into synthesis.answer_md slot for assistant_message.
		$synthesis['answer_md'] = $final_text;

		/* Cite resolve — empty in auto-degrade path (no notebook citations). */
		$sse->emit( 'cite_resolved', [
			'trace_id'         => $trace_id,
			'cited_entity_ids' => [],
			'cited_passages'   => [],
		] );

		$this->emit_event( 'brain_synthesize', [
			'trace_id'           => $trace_id,
			'surface'            => self::SURFACE,
			'model'              => (string) ( $final['model']  ?? '' ),
			'tokens'             => (int)    ( $final['tokens'] ?? 0 ),
			'ms'                 => $final_ms,
			'consensus_count'    => 0,
			'tensions_count'     => 0,
			'citation_count'     => 0,
			'cited_entity_count' => 0,
			'cited_passage_count'=> 0,
			'fallback'           => 'auto_degraded',
			'answers_in'         => 0,
			'streaming'          => true,
			'auto_degraded'      => true,
		] );
		$this->emit_event( 'assistant_message', [
			'trace_id'             => $trace_id,
			'surface'              => self::SURFACE,
			'text'                 => $final_text,
			'synthesis_metadata'   => [
				'consensus'        => [],
				'tensions'         => [],
				'recommendation'   => '',
				'citations'        => [],
				'citation_count'   => 0,
				'cited_entity_ids' => [],
				'cited_passages'   => [],
				'model'            => (string) ( $final['model']  ?? '' ),
				'tokens'           => (int)    ( $final['tokens'] ?? 0 ),
				'ms'               => $final_ms,
				'fallback'         => 'auto_degraded',
				'streaming'        => true,
				'auto_degraded'    => true,
				'answer_md_synth'  => '',
				'final_compose'    => [
					'tokens'   => (int)    ( $final['tokens']   ?? 0 ),
					'model'    => (string) ( $final['model']    ?? '' ),
					'ms'       => $final_ms,
					'chunks'   => $final_seq,
					'fallback' => (string) ( $final['fallback'] ?? '' ),
					'success'  => ! empty( $final['success'] ),
				],
			],
			// [2026-06-04 Johnny Chu] BS-12 — auto-degrade chat turn replay snapshot.
			'result_snapshot'      => array_merge(
				$this->build_turn_snapshot( $trace_id, $opts, array(
					'web_mode' => 'off',
					'mode'     => 'brain',
					'final_gate' => $final_gate,
					'final'    => array(
						'answer_md' => $final_text,
						'chunks'    => $final_seq,
						'tokens'    => (int) ( $final['tokens'] ?? 0 ),
						'model'     => (string) ( $final['model'] ?? '' ),
						'ms'        => $final_ms,
						'fallback'  => (string) ( $final['fallback'] ?? '' ),
						'success'   => ! empty( $final['success'] ),
						'followup_gate' => (string) ( $final['followup_gate'] ?? 'not_required' ),
						'followup_contract' => (array) ( $final['followup_contract'] ?? array() ),
					),
					'duration_ms' => (int) ( ( microtime( true ) - $wall_t0 ) * 1000 ),
				) ),
				array( 'auto_degraded' => true )
			),
		] );

		$wall_ms = (int) ( ( microtime( true ) - $wall_t0 ) * 1000 );

		return [
			'ok'                => true,
			'synthesis'         => $synthesis,
			'answers'           => [],
			'cited_entity_ids'  => [],
			'cited_passages'    => [],
			'duration_ms'       => $wall_ms,
			'final_gate'        => $final_gate,
			'auto_degraded'     => true,
		];
	}

	/* =================================================================
	 *  Stage 2.5 — Web Research Fallback Layer (TBR.W9)
	 *  PHASE 0.36-UNIFIED §3.5 — dispatcher routes to Quick (W6) or Deep
	 *  (W7) engine based on user-selected web_mode. Emits 5 SSE events
	 *  (web_research_started / web_search_done / web_extract_done /
	 *  web_react_step / web_synthesize_done) so BrainThinkingTimeline can
	 *  render a dedicated Web Research section between perspectives and
	 *  tool_decided rows.
	 * ================================================================ */

	/**
	 * Dispatch Web Research engine + bridge its events to SSE.
	 *
	 * The engines emit via `BizCity_Twin_Event_Bus`. To avoid a double-hop
	 * (bus → wp_options → poll) we register a temporary one-shot listener
	 * here that re-emits each web_* event over the live SSE writer.
	 *
	 * @param string                  $trace_id Brain turn trace id.
	 * @param string                  $prompt   User prompt.
	 * @param string                  $mode     'quick' | 'deep' | 'social' | 'company' | 'med' | 'scholar' | 'nutri' | 'law' | 'tax' | 'gov' | 'products'.
	 * @param BizCity_Twin_SSE_Writer $sse      Live SSE writer.
	 * @param array                   $opts     Runtime opts (guru_id, web_mode, …).
	 * @return array Web perspective row (or empty array on engine missing).
	 */
	private function dispatch_web_research( string $trace_id, string $prompt, string $mode, BizCity_Twin_SSE_Writer $sse, array $opts ): array {
		$web_events = [
			'web_research_started',
			'web_search_done',
			'web_extract_done',
			'web_react_step',
			'web_synthesize_done',
			// [2026-07-15 Johnny Chu] PHASE-TWB-PRODUCTS - optional product-specific timeline taxonomy events.
			'product_research_started',
			'product_intent_detected',
			'product_needs_decomposed',
			'product_react_step',
			'product_synthesize_done',
			'woo_bizops_domain_gate',
			'woo_bizops_intent_detected',
			'woo_bizops_query_executed',
			'woo_bizops_composed',
		];
		$web_events_lookup = array_flip( $web_events );

		// Bridge bus → SSE for the duration of this call. Listener filters
		// by trace_id to stay safe when multiple turns share a request lifecycle.
		// The Twin Event Bus fires `do_action('bizcity_twin_event', $key, $payload)`
		// so we attach to the primary hook with 2 args (R-EVT-1: single channel).
		$bridge = static function ( $event_key, $payload ) use ( $sse, $trace_id, $web_events_lookup ) {
			if ( ! is_string( $event_key ) || ! isset( $web_events_lookup[ $event_key ] ) ) return;
			if ( ! is_array( $payload ) ) return;
			if ( isset( $payload['trace_id'] ) && (string) $payload['trace_id'] !== $trace_id ) return;
			$sse->emit( $event_key, $payload );
			$sse->maybe_heartbeat();
		};
		add_action( 'bizcity_twin_event', $bridge, 10, 2 );
		// [2026-08-11 Johnny Chu] PHASE-TWB-WOO-BIZOPS — bridge canonical TwinCore v2 envelopes to the live MPR SSE timeline.
		$bridge_v2 = static function ( $event ) use ( $bridge ) {
			if ( ! is_array( $event ) ) { return; }
			$event_key = (string) ( $event['event_type'] ?? '' );
			$payload   = isset( $event['payload'] ) && is_array( $event['payload'] ) ? $event['payload'] : $event;
			$bridge( $event_key, $payload );
		};
		add_action( 'bizcity_twin_event_v2', $bridge_v2, 10, 1 );

		$row = [];
		try {
			// [2026-10-07 10:05 Johnny Chu - Chu Hoàng Anh] TWINBRAIN-SPLIT-CORE-ADDON W-2 — the per-mode engine chain (quick … woo_bizops) moved to
			// BizCity_TwinBrain_Vertical_Engines::run() in bizcity-twin-brain-addon/twinbrain-verticals/; core keeps the SSE bridge.
			$row = apply_filters( 'bizcity_twinbrain_web_engine_run', null, $mode, $trace_id, $prompt, $opts, $sse );
			if ( ! is_array( $row ) ) {
				$sse->emit( 'web_research_error', array( 'trace_id' => $trace_id, 'mode' => $mode, 'error' => 'engine_missing' ) );
				$row = array();
			}
		} catch ( \Throwable $e ) {
			$sse->emit( 'web_research_error', [
				'trace_id' => $trace_id,
				'mode'     => $mode,
				'error'    => 'engine_throw:' . $e->getMessage(),
			] );
		} finally {
			remove_action( 'bizcity_twin_event', $bridge, 10 );
			remove_action( 'bizcity_twin_event_v2', $bridge_v2, 10 );
		}

		return is_array( $row ) ? $row : [];
	}

	/**
	 * [2026-06-03 Johnny Chu] BRAIN-SESSIONS BS-4 — count user_message events
	 * already emitted for the given session_id (current turn included via
	 * `dispatch_v2('user_message', ...)` which runs before final compose).
	 * Used by the mood sampler to honour the configured cadence.
	 */
	private function count_user_turns_for_session( string $session_id ): int {
		if ( $session_id === '' ) return 0;
		global $wpdb;
		$tbl = $wpdb->prefix . 'bizcity_twin_event_stream';
		$prev = $wpdb->suppress_errors( true );
		$n = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$tbl}
			   WHERE session_id = %s AND event_type = 'user_message'",
			$session_id
		) );
		$wpdb->suppress_errors( $prev );
		return $n;
	}

	/**
	 * Resolve cited passage_ids → KG entity_ids via `kg_passage_entities`.
	 * Mirrors the notebook editor pattern (orange cited nodes in graph view).
	 * Capped at 200 entities total; returns sorted unique ints.
	 */
	private function resolve_cited_entity_ids( array $citations ): array {
		if ( empty( $citations ) ) return [];
		$pids = [];
		foreach ( $citations as $c ) {
			$p = (int) ( $c['passage_id'] ?? 0 );
			if ( $p > 0 ) $pids[ $p ] = true;
		}
		if ( empty( $pids ) ) return [];
		if ( ! class_exists( 'BizCity_KG_Database' ) ) return [];

		global $wpdb;
		$tbl = BizCity_KG_Database::instance()->tbl_passage_entities();
		$ids = array_keys( $pids );
		$ph  = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		$prev = $wpdb->suppress_errors( true );
		$rows = $wpdb->get_col( $wpdb->prepare(
			"SELECT DISTINCT entity_id FROM {$tbl} WHERE passage_id IN ({$ph}) LIMIT 200",
			$ids
		) );
		$wpdb->suppress_errors( $prev );

		$out = array_map( 'intval', (array) $rows );
		sort( $out, SORT_NUMERIC );
		return $out;
	}

	/**
	 * Extract all `[nb:X/pY]` (or comma-grouped `[nb:X/pY, nb:X/pY]`) passage
	 * ids from a markdown string. Used to expand the BE-resolved
	 * `cited_passages` map beyond `synthesis.citations[]` so every inline
	 * citation in `answer_md` is clickable in the FE inline Source viewer.
	 */
	private function extract_inline_passage_refs( string $md ): array {
		if ( $md === '' ) return [];
		$pids = [];
		// Match all `nb:DIGITS/pDIGITS` occurrences regardless of brackets.
		if ( preg_match_all( '/nb:\d+\/p(\d+)/i', $md, $mm ) && ! empty( $mm[1] ) ) {
			foreach ( $mm[1] as $p ) {
				$p = (int) $p;
				if ( $p > 0 ) $pids[ $p ] = true;
			}
		}
		return array_keys( $pids );
	}

	/**
	 * Resolve cited passage_ids → metadata for the FE Source viewer.
	 * Returns: `[{notebook_id, passage_id, source_id, source_title,
	 *            heading_path:[], page_no, content_snippet}]` (max 80).
	 * Mirrors `BizCity_KG_REST_Controller::get_entity_passages()` SQL shape.
	 */
	private function resolve_cited_passages( array $citations, array $extra_pids = [] ): array {
		$pids = [];
		foreach ( $citations as $c ) {
			$p = (int) ( $c['passage_id'] ?? 0 );
			if ( $p > 0 ) $pids[ $p ] = true;
		}
		foreach ( $extra_pids as $p ) {
			$p = (int) $p;
			if ( $p > 0 ) $pids[ $p ] = true;
		}
		if ( empty( $pids ) ) return [];
		if ( ! class_exists( 'BizCity_KG_Database' ) ) return [];

		global $wpdb;
		$db  = BizCity_KG_Database::instance();
		$tp  = $db->tbl_passages();
		$ts  = method_exists( $db, 'tbl_sources' ) ? $db->tbl_sources() : '';
		$ids = array_slice( array_keys( $pids ), 0, 80 );
		$ph  = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		$prev = $wpdb->suppress_errors( true );
		if ( $ts ) {
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT p.id AS passage_id, p.notebook_id, p.source_id, p.content, p.metadata,
				        s.title AS source_title
				 FROM {$tp} p
				 LEFT JOIN {$ts} s ON s.id = p.source_id
				 WHERE p.id IN ({$ph})",
				$ids
			), ARRAY_A );
		} else {
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT id AS passage_id, notebook_id, source_id, content, metadata
				 FROM {$tp} WHERE id IN ({$ph})",
				$ids
			), ARRAY_A );
		}
		$wpdb->suppress_errors( $prev );

		$out = [];
		foreach ( (array) $rows as $r ) {
			$content = (string) ( $r['content'] ?? '' );
			$snippet = mb_substr( $content, 0, 240 );
			if ( mb_strlen( $content ) > 240 ) $snippet .= '…';
			$heading_path = [];
			$page_no = null;
			if ( ! empty( $r['metadata'] ) ) {
				$meta = json_decode( (string) $r['metadata'], true );
				if ( is_array( $meta ) ) {
					if ( isset( $meta['heading_path'] ) && is_array( $meta['heading_path'] ) ) {
						$heading_path = array_values( array_map( 'strval', $meta['heading_path'] ) );
					}
					if ( isset( $meta['page_no'] ) ) {
						$page_no = (int) $meta['page_no'];
					}
				}
			}
			$out[] = [
				'notebook_id'     => (int) ( $r['notebook_id'] ?? 0 ),
				'passage_id'      => (int) ( $r['passage_id']  ?? 0 ),
				'source_id'       => (int) ( $r['source_id']   ?? 0 ),
				'source_title'    => (string) ( $r['source_title'] ?? '' ),
				'heading_path'    => $heading_path,
				'page_no'         => $page_no,
				'content_snippet' => $snippet,
			];
		}

		// Backfill: passages with source_id=0 (orphans / pre-Wave-0.6.C ingest)
		// have no clickable source in the FE Source viewer. Look up the first
		// kg_sources row scoped to that notebook and reuse it so the user can
		// at least open the notebook's primary source for context.
		if ( $ts && ! empty( $out ) ) {
			$missing_nb = [];
			foreach ( $out as $row ) {
				if ( (int) $row['source_id'] <= 0 && (int) $row['notebook_id'] > 0 ) {
					$missing_nb[ (int) $row['notebook_id'] ] = true;
				}
			}
			if ( ! empty( $missing_nb ) ) {
				$nb_ids   = array_keys( $missing_nb );
				$nb_ph    = implode( ',', array_fill( 0, count( $nb_ids ), '%s' ) );
				$prev2    = $wpdb->suppress_errors( true );
				$nb_rows  = $wpdb->get_results( $wpdb->prepare(
					"SELECT scope_id, MIN(id) AS source_id, MIN(title) AS title
					 FROM {$ts}
					 WHERE scope_type = 'notebook' AND scope_id IN ({$nb_ph})
					 GROUP BY scope_id",
					array_map( 'strval', $nb_ids )
				), ARRAY_A );
				$wpdb->suppress_errors( $prev2 );
				$nb_map = [];
				foreach ( (array) $nb_rows as $nr ) {
					$nb_map[ (int) $nr['scope_id'] ] = [
						'source_id'    => (int) $nr['source_id'],
						'source_title' => (string) $nr['title'],
					];
				}
				foreach ( $out as &$row ) {
					if ( (int) $row['source_id'] > 0 ) continue;
					$nb = (int) $row['notebook_id'];
					if ( isset( $nb_map[ $nb ] ) ) {
						$row['source_id'] = $nb_map[ $nb ]['source_id'];
						if ( $row['source_title'] === '' ) {
							$row['source_title'] = $nb_map[ $nb ]['source_title'];
						}
					}
				}
				unset( $row );
			}
		}

		return $out;
	}

	/* ============================================================
	 * Helpers
	 * ============================================================ */

	private function new_trace_id(): string {
		// UUID v4 is enough for now; v7 upgrade tracked in PHASE-0.36 sprint .1.
		if ( function_exists( 'wp_generate_uuid4' ) ) {
			return 'tbr_' . wp_generate_uuid4();
		}
		return uniqid( 'tbr_', true );
	}

	private function has_explicit_skill_mention( string $prompt ): bool {
		return (bool) preg_match( '/(^|\s)@@[a-z0-9_\-]+/i', $prompt );
	}

	/**
	 * PHASE-0.35 / F7.C4 — Layer 5 Tool_Decision.
	 *
	 * Pure decision step (no execution): pick the top tool candidate above
	 * threshold (or the forced one) and resolve its schema for hand-off to
	 * the synthesizer prompt. Dispatch is the responsibility of F7.C5.
	 *
	 * Decisions:
	 *   - 'no_tool'        : no candidate or top score below threshold (and not forced).
	 *   - 'await_dispatch' : a tool was picked; F7.C5 / FE confirm flow will execute.
	 *
	 * @return array{
	 *   decision:string, reason:string, threshold:float,
	 *   tool: array{slug:string,score:float,reason:string,forced:bool,schema:?array,needs_approval:bool,tool_class:string,guru_id:int}|null
	 * }
	 */
	private function decide_tool( string $trace_id, array $tool_candidates, string $tool_force, int $guru_id ): array {
		$threshold = (float) BIZCITY_TWINBRAIN_TOOL_INTENT_THRESHOLD;

		if ( empty( $tool_candidates ) ) {
			return array(
				'decision'  => 'no_tool',
				'reason'    => 'no_candidates',
				'threshold' => $threshold,
				'tool'      => null,
			);
		}

		// Forced candidates win regardless of score.
		$top = null;
		foreach ( $tool_candidates as $c ) {
			$r = (string) ( $c['reason'] ?? '' );
			if ( strpos( $r, 'forced' ) === 0 ) { $top = $c; break; }
		}
		if ( $top === null ) {
			$ranked = $tool_candidates;
			usort( $ranked, function ( $a, $b ) {
				$as = (float) ( $a['score'] ?? 0 );
				$bs = (float) ( $b['score'] ?? 0 );
				if ( $as === $bs ) return 0;
				return ( $bs > $as ) ? 1 : -1;
			} );
			$top = $ranked[0];
		}

		$slug      = (string) ( $top['skill_slug'] ?? '' );
		$score     = (float)  ( $top['score']      ?? 0 );
		$reason    = (string) ( $top['reason']     ?? '' );
		$is_forced = ( strpos( $reason, 'forced' ) === 0 );

		if ( $slug === '' ) {
			return array(
				'decision'  => 'no_tool',
				'reason'    => 'empty_slug',
				'threshold' => $threshold,
				'tool'      => null,
			);
		}

		if ( ! $is_forced && $score < $threshold ) {
			return array(
				'decision'  => 'no_tool',
				'reason'    => 'below_threshold',
				'threshold' => $threshold,
				'tool'      => array(
					'slug'    => $slug,
					'score'   => $score,
					'reason'  => $reason,
					'forced'  => false,
				),
			);
		}

		// Best-effort schema + approval lookup. Tool may not be in twin tool
		// registry (some skills live only as bizcity_skills rows for matching).
		// [2026-07-19 Johnny Chu] PHASE-TWIN-GPT-AGENT-TOOLS — preserve contract-only catalog metadata for visible tool evaluation even before real executors exist.
		$schema        = isset( $top['parameters_schema'] ) && is_array( $top['parameters_schema'] ) ? (array) $top['parameters_schema'] : null;
		$needs_ap      = ! empty( $top['needs_approval'] );
		$tool_class    = (string) ( $top['tool_class'] ?? '' );
		$artifact_type = (string) ( $top['artifact_type'] ?? '' );
		$execution     = (string) ( $top['execution'] ?? '' );
		$plan_min      = (string) ( $top['plan_min'] ?? '' );
		$capability    = (string) ( $top['capability'] ?? '' );
		if ( class_exists( 'BizCity_Twin_Tool_Registry' ) ) {
			$tool = BizCity_Twin_Tool_Registry::instance()->get( $slug );
			if ( $tool ) {
				if ( method_exists( $tool, 'parameters_schema' ) ) {
					$schema = (array) $tool->parameters_schema();
				}
				if ( method_exists( $tool, 'needs_approval' ) ) {
					try { $needs_ap = (bool) $tool->needs_approval( array(), array() ); }
					catch ( \Throwable $e ) { $needs_ap = false; }
				}
				if ( method_exists( $tool, 'tool_class' ) ) {
					try { $tool_class = (string) $tool->tool_class(); }
					catch ( \Throwable $e ) { $tool_class = ''; }
				}
			}
		}

		return array(
			'decision'  => 'await_dispatch',
			'reason'    => $reason,
			'threshold' => $threshold,
			'tool'      => array(
				'slug'           => $slug,
				'score'          => $score,
				'reason'         => $reason,
				'forced'         => $is_forced,
				'schema'         => $schema,
				'needs_approval' => $needs_ap,
				'tool_class'     => $tool_class,
				'artifact_type'  => $artifact_type,
				'execution'      => $execution,
				'plan_min'       => $plan_min,
				'capability'     => $capability,
				'guru_id'        => $guru_id,
			),
		);
	}

	/**
	 * Build a synthetic `tool_results` array hinting the Synthesizer that a
	 * tool is planned but has not been executed yet (F7.C5 will replace this
	 * with real results). Keeps the synth prompt aware so it doesn't fabricate
	 * tool output.
	 */
	private function planned_tool_results( array $decision ): array {
		if ( empty( $decision['tool'] ) || ( $decision['decision'] ?? '' ) !== 'await_dispatch' ) {
			return array();
		}
		$slug = (string) ( $decision['tool']['slug'] ?? '' );
		if ( $slug === '' ) return array();

		return array(
			array(
				'skill'  => 'PLANNED:' . $slug,
				'result' => 'AWAITING DISPATCH (Layer 6 / F7.C5). Do not invent the result; reference the planned tool by slug only.',
			),
		);
	}

	private function should_defer_stream_artifact_dispatch( array $decision ): bool {
		// [2026-07-20 Johnny Chu] PHASE-1-TWIN-GPT-AGENT-TOOLS — producer artifacts need final_answer before owner-service handoff.
		if ( empty( $decision['tool'] ) || ( $decision['decision'] ?? '' ) !== 'await_dispatch' ) {
			return false;
		}
		$tool = (array) $decision['tool'];
		$slug = sanitize_key( (string) ( $tool['slug'] ?? '' ) );
		$artifact_type = (string) ( $tool['artifact_type'] ?? '' );
		if ( '' === $slug || '' === $artifact_type ) {
			return false;
		}
		$producer_slugs = array( 'create_doc', 'create_xlsx', 'create_pptx', 'create_mindmap', 'generate_image' );
		return in_array( $slug, $producer_slugs, true ) || (string) ( $tool['tool_class'] ?? '' ) === 'producer';
	}

	private function emit_dispatch_artifacts_and_done( string $trace_id, array $dispatch, array $tool_decision, $sse = null ): void {
		// [2026-07-20 Johnny Chu] PHASE-1-TWIN-GPT-AGENT-TOOLS — shared artifact/tool_done emission for eager and post-final dispatch.
		if ( ! empty( $dispatch['artifact_created'] ) && is_array( $dispatch['artifact_created'] ) ) {
			$artifact_payload = array_merge( array( 'trace_id' => $trace_id, 'surface' => self::SURFACE ), $dispatch['artifact_created'] );
			if ( is_object( $sse ) && method_exists( $sse, 'emit' ) ) {
				$sse->emit( 'artifact_created', $artifact_payload );
			}
			$this->emit_event( 'artifact_created', $artifact_payload );
		}
		if ( ! empty( $dispatch['artifact_ready'] ) && is_array( $dispatch['artifact_ready'] ) ) {
			$artifact_ready_payload = array_merge( array( 'trace_id' => $trace_id, 'surface' => self::SURFACE ), $dispatch['artifact_ready'] );
			if ( is_object( $sse ) && method_exists( $sse, 'emit' ) ) {
				$sse->emit( 'artifact_ready', $artifact_ready_payload );
			}
			$this->emit_event( 'artifact_ready', $artifact_ready_payload );
		}
		$tool_done_payload = $this->tool_done_payload( $trace_id, $dispatch, $tool_decision );
		if ( is_object( $sse ) && method_exists( $sse, 'emit' ) ) {
			$sse->emit( 'tool_done', $tool_done_payload );
		}
		$this->emit_event( 'tool_done', $tool_done_payload );
	}

	/**
	 * PHASE-0.35 / F7.C5 — Layer 6 Tool_Dispatch.
	 *
	 * Pure execution step: invoke the decided tool via Twin_Tool_Registry and
	 * normalise its return shape into a `tool_done` payload. Skips when:
	 *   - no decision made (decision != 'await_dispatch')
	 *   - tool requires HIL approval (Wave 0 — defer to F7.C5.1)
	 *   - tool not registered in Twin_Tool_Registry
	 *
	 * Best-effort: any \Throwable from the tool is caught and surfaced as
	 * `ok=false`. NEVER aborts the synthesis pipeline.
	 *
	 * @param string $trace_id
	 * @param array  $decision  Output of decide_tool().
	 * @param array  $opts      Forwarded ctx (user_id, scope, guru_id…).
	 * @return array{
	 *   ok: bool, skipped: bool, skip_reason: string,
	 *   tool_slug: string, tool_class: string,
	 *   ms: int, summary: string, error: string,
	 *   result: mixed, sources: array, citation_ids: array,
	 *   canvas_open: array|null
	 * }
	 */
	private function dispatch_tool( string $trace_id, array $decision, array $opts = [], $sse = null ): array {
		$base = array(
			'ok'           => false,
			'skipped'      => true,
			'skip_reason'  => '',
			'tool_slug'    => '',
			'tool_class'   => '',
			'artifact_type'=> '',
			'execution'    => '',
			'plan_min'     => '',
			'capability'   => '',
			'ms'           => 0,
			'summary'      => '',
			'error'        => '',
			'result'       => null,
			'sources'      => array(),
			'citation_ids' => array(),
			'canvas_open'  => null,
			'args'         => array(),
			'args_status'  => '',
			'job'          => null,
			'artifact_created' => null,
			'artifact_ready'   => null,
			'artifacts'        => array(), // [2026-07-31 Johnny Chu] PHASE-TWIN-GPT-AGENT-TOOLS — preserve producer collections for Session Workspace replay.
		);

		if ( empty( $decision['tool'] ) || ( $decision['decision'] ?? '' ) !== 'await_dispatch' ) {
			$base['skip_reason'] = 'no_decision';
			return $base;
		}

		$tool_meta            = (array) $decision['tool'];
		$slug                 = (string) ( $tool_meta['slug'] ?? '' );
		$base['tool_slug']    = $slug;
		$base['tool_class']   = (string) ( $tool_meta['tool_class'] ?? '' );
		$base['artifact_type']= (string) ( $tool_meta['artifact_type'] ?? '' );
		$base['execution']    = (string) ( $tool_meta['execution'] ?? '' );
		$base['plan_min']     = (string) ( $tool_meta['plan_min'] ?? '' );
		$base['capability']   = (string) ( $tool_meta['capability'] ?? '' );

		if ( $slug === '' ) {
			$base['skip_reason'] = 'empty_slug';
			return $base;
		}
		if ( ! empty( $tool_meta['needs_approval'] ) ) {
			// HIL flow lives in F7.C5.1 — emit await_user with tool_slug instead
			// of dispatching. For now we just skip and let synthesizer note it.
			$base['skip_reason'] = 'needs_approval';
			return $base;
		}

		// [2026-06-13 Johnny Chu] PHASE-0.40 G3 Wave-B — bizcity_twinbrain_tool_dispatch_gate
		// Allows external plugins (e.g. CRM admin-chat grants) to intercept BEFORE execution.
		// Filter receives ($veto, $tool_meta, $ctx) and may return:
		//   false             → allow (default)
		//   'confirm_pending' → block + confirm token stored by filter handler
		//   'denied'          → block silently (caller logged reason separately)
		$ctx_for_gate = array(
			'trace_id'        => $trace_id,
			'user_id'         => (int) ( $opts['user_id']         ?? get_current_user_id() ),
			'session_id'      => (string) ( $opts['session_id']   ?? '' ),
			'guru_id'         => (int) ( $opts['guru_id']         ?? 0 ),
			'inbound_chat_id' => (string) ( $opts['inbound_chat_id'] ?? '' ),
			'surface'         => self::SURFACE,
		);
		$gate_result = apply_filters( 'bizcity_twinbrain_tool_dispatch_gate', false, $tool_meta, $ctx_for_gate );
		if ( $gate_result === 'confirm_pending' ) {
			$base['skip_reason'] = 'policy_confirm';
			return $base;
		}
		if ( $gate_result === 'denied' ) {
			$base['skip_reason'] = 'policy_deny';
			return $base;
		}

		if ( ! class_exists( 'BizCity_Twin_Tool_Registry' ) ) {
			$base['skip_reason'] = 'registry_missing';
			return $base;
		}

		$tool = BizCity_Twin_Tool_Registry::instance()->get( $slug );
		if ( ! $tool || ! method_exists( $tool, 'execute' ) ) {
			$base['skip_reason'] = 'tool_not_in_registry';
			return $base;
		}

		// [2026-07-20 Johnny Chu] PHASE-TWIN-GPT-AGENT-TOOLS — build owner/thread context; deterministic args are built below before registry execute.
		$ctx = array(
			'trace_id'   => $trace_id,
			'user_id'    => (int) ( $opts['user_id']    ?? get_current_user_id() ),
			'guest_sid'  => (string) ( $opts['guest_sid'] ?? '' ),
			'session_id' => (string) ( $opts['session_id'] ?? '' ),
			'scope'      => (array) ( $opts['scope']    ?? array() ),
			'surface'    => self::SURFACE,
			'request_surface' => (string) ( $opts['surface'] ?? '' ),
			'guru_id'    => (int) ( $tool_meta['guru_id'] ?? ( $opts['guru_id'] ?? 0 ) ),
			'project_id' => (string) ( $opts['project_id'] ?? '' ),
			'attachments'=> (array) ( $opts['attachments'] ?? array() ),
			'attachment_ids' => (array) ( $opts['attachment_ids'] ?? array() ),
		);

		// [2026-07-20 Johnny Chu] PHASE-TWIN-GPT-AGENT-TOOLS — build structured tool args before registry execute; no extra LLM roundtrip yet.
		$this->emit_tool_event( 'tool_args_started', array(
			'trace_id'      => $trace_id,
			'surface'       => self::SURFACE,
			'tool_slug'     => $slug,
			'artifact_type' => (string) $base['artifact_type'],
		), $sse );
		$args = $this->build_tool_args( $slug, $tool_meta, $opts );
		$base['args']        = $args;
		$base['args_status'] = empty( $args['missing_required'] ) ? 'ready' : 'missing_required';
		$this->emit_tool_event( 'tool_args_ready', array(
			'trace_id'         => $trace_id,
			'surface'          => self::SURFACE,
			'tool_slug'        => $slug,
			'artifact_type'    => (string) $base['artifact_type'],
			'status'           => (string) $base['args_status'],
			'attachment_count' => isset( $args['attachment_ids'] ) && is_array( $args['attachment_ids'] ) ? count( $args['attachment_ids'] ) : 0,
			'title'            => (string) ( $args['title'] ?? '' ),
			'missing_required' => (array) ( $args['missing_required'] ?? array() ),
		), $sse );
		if ( ! empty( $args['missing_required'] ) ) {
			$base['skip_reason'] = 'tool_args_missing';
			return $base;
		}
		// [2026-07-20 Johnny Chu] PHASE-TWIN-GPT-AGENT-TOOLS — make dispatch execution visible before the adapter runs.
		$this->emit_tool_event( 'tool_dispatch_started', array(
			'trace_id'      => $trace_id,
			'surface'       => self::SURFACE,
			'tool_slug'     => $slug,
			'artifact_type' => (string) $base['artifact_type'],
			'execution'     => (string) $base['execution'],
		), $sse );

		$base['skipped'] = false;
		$t0  = microtime( true );
		$ret = array();
		try {
			$ret = (array) $tool->execute( $args, $ctx );
		} catch ( \Throwable $e ) {
			$base['ms']    = (int) round( ( microtime( true ) - $t0 ) * 1000 );
			$base['ok']    = false;
			$base['error'] = $e->getMessage();
			return $base;
		}
		$base['ms']           = (int) round( ( microtime( true ) - $t0 ) * 1000 );
		$base['ok']           = (bool) ( $ret['ok'] ?? false );
		$base['summary']      = (string) ( $ret['summary'] ?? '' );
		$base['error']        = (string) ( $ret['error']   ?? '' );
		// [2026-09-19 Johnny Chu] PHASE-0.55-A3 — forward confirmation-only CRM action cards to the chat surface.
		$base['result']       = isset( $ret['result'] ) ? $ret['result'] : null;
		$base['action_card']  = isset( $ret['action_card'] ) && is_array( $ret['action_card'] ) ? $ret['action_card'] : null;
		$base['sources']      = isset( $ret['sources'] )      && is_array( $ret['sources'] )      ? $ret['sources']      : array();
		$base['citation_ids'] = isset( $ret['citation_ids'] ) && is_array( $ret['citation_ids'] ) ? $ret['citation_ids'] : array();
		if ( isset( $ret['job'] ) && is_array( $ret['job'] ) ) {
			$base['job'] = $ret['job'];
		}
		if ( isset( $ret['artifact_created'] ) && is_array( $ret['artifact_created'] ) ) {
			$base['artifact_created'] = $ret['artifact_created'];
		}
		if ( isset( $ret['artifact_ready'] ) && is_array( $ret['artifact_ready'] ) ) {
			$base['artifact_ready'] = $ret['artifact_ready'];
		}
		if ( isset( $ret['artifacts'] ) && is_array( $ret['artifacts'] ) ) {
			// [2026-07-31 Johnny Chu] PHASE-TWIN-GPT-AGENT-TOOLS — forward typed artifact collection without replacing legacy artifact_created/ready.
			$base['artifacts'] = array_values( array_filter( $ret['artifacts'], 'is_array' ) );
		}
		// [2026-07-20 Johnny Chu] PHASE-TWIN-GPT-AGENT-TOOLS — persist AT-7 durable job and attach job_id to Canvas events.
		$base = $this->attach_artifact_job_state( $trace_id, $base, $ret, $args, $ctx );

		// canvas_open passthrough (R-MPRT §11): tool may attach
		// `{url, type, output_id}` for FE inline panel flip. Also derive a
		// minimal canvas hint when tool returned a notebook_id artifact.
		if ( isset( $ret['canvas_open'] ) && is_array( $ret['canvas_open'] ) ) {
			$base['canvas_open'] = $ret['canvas_open'];
		} elseif ( isset( $ret['artifact'] ) && is_array( $ret['artifact'] ) ) {
			$art = $ret['artifact'];
			$nb  = (int) ( $art['notebook_id'] ?? 0 );
			if ( $nb > 0 ) {
				$base['canvas_open'] = array(
					'type'        => (string) ( $art['type'] ?? 'notebook_artifact' ),
					'notebook_id' => $nb,
					'output_id'   => (int) ( $art['source_id'] ?? 0 ),
				);
			}
		}
		return $base;
	}

	/**
	 * Persist durable TwinWeb artifact job state when the public tool store is loaded.
	 *
	 * @param string $trace_id Runtime trace ID.
	 * @param array  $dispatch Normalized dispatch payload.
	 * @param array  $raw_return Raw tool adapter return.
	 * @param array  $args Tool args.
	 * @param array  $ctx Tool context.
	 * @return array Dispatch payload with job metadata attached.
	 */
	private function attach_artifact_job_state( $trace_id, array $dispatch, array $raw_return, array $args, array $ctx ) {
		// [2026-07-20 Johnny Chu] PHASE-TWIN-GPT-AGENT-TOOLS — only TwinWeb loads this store; other surfaces keep old behavior.
		if ( ! class_exists( 'BizCity_TwinWeb_Artifact_Jobs' ) || (string) ( $ctx['request_surface'] ?? '' ) !== 'twinweb' ) {
			return $dispatch;
		}
		if ( empty( $dispatch['artifact_created'] ) && empty( $dispatch['artifact_ready'] ) && empty( $dispatch['artifact_type'] ) ) {
			return $dispatch;
		}

		$artifact = is_array( $dispatch['artifact_ready'] ) ? $dispatch['artifact_ready'] : ( is_array( $dispatch['artifact_created'] ) ? $dispatch['artifact_created'] : array() );
		$status = ! empty( $dispatch['ok'] ) ? 'ready' : 'failed';
		$progress = ! empty( $dispatch['ok'] ) ? 100 : 0;
		if ( is_array( $dispatch['artifact_created'] ) && empty( $dispatch['artifact_ready'] ) && ! empty( $dispatch['ok'] ) ) {
			$status = 'waiting_owner';
			$progress = isset( $dispatch['artifact_created']['progress'] ) ? absint( $dispatch['artifact_created']['progress'] ) : 10;
		}

		$job = BizCity_TwinWeb_Artifact_Jobs::create( array(
			'identity'      => array(
				'user_id'   => (int) ( $ctx['user_id'] ?? 0 ),
				'guest_sid' => (string) ( $ctx['guest_sid'] ?? '' ),
			),
			'run_id'        => $trace_id,
			'thread_id'     => (string) ( $ctx['session_id'] ?? '' ),
			'tool_slug'     => (string) ( $dispatch['tool_slug'] ?? '' ),
			'artifact_type' => (string) ( $dispatch['artifact_type'] ?: ( $artifact['artifact_type'] ?? $artifact['type'] ?? '' ) ),
			'status'        => $status,
			'progress'      => $progress,
			'reason_bucket' => ! empty( $dispatch['ok'] ) ? '' : 'tool_dispatch_error',
			'owner_job_id'  => (string) ( $raw_return['job']['id'] ?? $raw_return['job']['job_id'] ?? $artifact['owner_job_id'] ?? '' ),
			'artifact_id'   => (string) ( $artifact['artifact_id'] ?? $artifact['studio_id'] ?? $artifact['output_id'] ?? '' ),
			'status_url'    => (string) ( $artifact['status_url'] ?? $artifact['statusUrl'] ?? '' ),
			'preview_url'   => (string) ( $artifact['preview_url'] ?? $artifact['url'] ?? $artifact['edit_url'] ?? '' ),
			'download_url'  => (string) ( $artifact['download_url'] ?? $artifact['downloadUrl'] ?? $artifact['preview_url'] ?? $artifact['url'] ?? '' ),
			'input'         => array( 'args' => $args ),
			'result'        => array(
				'summary'          => (string) ( $dispatch['summary'] ?? '' ),
				'result'           => isset( $dispatch['result'] ) ? $dispatch['result'] : null,
				'artifact_created' => $dispatch['artifact_created'],
				'artifact_ready'   => $dispatch['artifact_ready'],
			),
			'error_payload' => ! empty( $dispatch['ok'] ) ? null : array(
				'code'      => 'tool_dispatch_error',
				'message'   => (string) ( $dispatch['error'] ?: 'Tool artifact failed.' ),
				'hint'      => 'Thử tạo lại artifact hoặc kiểm tra owner service.',
				'help_code' => 'automation_run_failed',
			),
		) );

		if ( is_wp_error( $job ) || ! is_array( $job ) || empty( $job['job_id'] ) ) {
			return $dispatch;
		}

		$job_id = (string) $job['job_id'];
		$status_url = function_exists( 'rest_url' ) ? rest_url( 'bizcity-twinweb/v1/artifacts/jobs/' . rawurlencode( $job_id ) ) : '';
		$dispatch['job'] = array(
			'job_id'      => $job_id,
			'status'      => (string) $job['status'],
			'status_url'  => $status_url,
			'progress'    => (int) $job['progress'],
			'is_terminal' => ! empty( $job['is_terminal'] ),
		);
		foreach ( array( 'artifact_created', 'artifact_ready' ) as $key ) {
			if ( isset( $dispatch[ $key ] ) && is_array( $dispatch[ $key ] ) ) {
				$dispatch[ $key ]['job_id'] = $job_id;
				if ( $status_url !== '' && empty( $dispatch[ $key ]['status_url'] ) ) {
					$dispatch[ $key ]['status_url'] = $status_url;
				}
			}
		}

		return $dispatch;
	}

	private function emit_tool_event( string $event_key, array $payload, $sse = null ): void {
		if ( is_object( $sse ) && method_exists( $sse, 'emit' ) ) {
			$sse->emit( $event_key, $payload );
		}
		$this->emit_event( $event_key, $payload );
	}

	private function build_tool_args( string $slug, array $tool_meta, array $opts ): array {
		// [2026-07-20 Johnny Chu] PHASE-TWIN-GPT-AGENT-TOOLS — preserve visible user command as priority; keep enriched reasoning as secondary context.
		$tool_prompt = trim( (string) ( $opts['tool_prompt'] ?? $opts['multimodal_enriched_query'] ?? $opts['prompt'] ?? '' ) );
		$user_prompt = trim( (string) ( $opts['visible_prompt'] ?? $opts['user_prompt'] ?? $opts['prompt'] ?? '' ) );
		$prompt = '' !== $user_prompt ? $user_prompt : $tool_prompt;
		$attachment_ids = array();
		foreach ( (array) ( $opts['attachment_ids'] ?? array() ) as $attachment_id ) {
			$attachment_id = absint( $attachment_id );
			if ( $attachment_id > 0 ) {
				$attachment_ids[] = $attachment_id;
			}
		}

		$args = array(
			'prompt'         => $prompt,
			'title'          => $this->derive_tool_title( $slug, $prompt ),
			'attachment_ids' => $attachment_ids,
			'thread_id'      => (string) ( $opts['session_id'] ?? '' ),
			'project_id'     => (string) ( $opts['project_id'] ?? '' ),
			'artifact_type'  => (string) ( $tool_meta['artifact_type'] ?? '' ),
			'tool_spec'      => $this->build_tool_spec_pack( $slug, $tool_meta, $prompt, $tool_prompt, $opts, $attachment_ids ),
		);

		if ( 'create_xlsx' === $slug ) {
			$args['sheets'] = array();
		} elseif ( 'create_pptx' === $slug ) {
			$args['slides'] = array();
		} elseif ( 'generate_image' === $slug ) {
			$args['input_images'] = $attachment_ids;
			$args['aspect_ratio'] = '1:1';
		}

		if ( '' === $prompt && '' === $tool_prompt ) {
			$args['missing_required'] = array( 'prompt' );
		}

		return $args;
	}

	private function build_tool_spec_pack( string $slug, array $tool_meta, string $user_prompt, string $tool_prompt, array $opts, array $attachment_ids ): array {
		// [2026-07-20 Johnny Chu] PHASE-TWIN-GPT-AGENT-TOOLS — canonical layered spec: request first, thread context second, file context third.
		$thread_history = $this->summarize_tool_history( (array) ( $opts['history'] ?? array() ) );
		// [2026-07-20 Johnny Chu] PHASE-TWIN-GPT-AGENT-TOOLS — carry real multimodal intake evidence into owner tools, not only attachment filenames.
		$raw_attachments = (array) ( $opts['attachments'] ?? array() );
		if ( empty( $raw_attachments ) && isset( $opts['multimodal_ingest_pack']['attachments'] ) && is_array( $opts['multimodal_ingest_pack']['attachments'] ) ) {
			$raw_attachments = (array) $opts['multimodal_ingest_pack']['attachments'];
		}
		$attachments = $this->summarize_tool_attachments( $raw_attachments, $attachment_ids, $opts );
		$files_ingest = $this->build_tool_files_ingest_summary( $opts );

		return array(
			'schema'       => 'bizcity.twinweb.tool_spec.v1',
			'surface'      => (string) ( $opts['surface'] ?? '' ),
			'tool_slug'    => $slug,
			'artifact_type'=> (string) ( $tool_meta['artifact_type'] ?? '' ),
			'request'      => array(
				'user_prompt'     => $this->limit_tool_text( $user_prompt, 3000 ),
				// [2026-07-20 Johnny Chu] PHASE-1-TWIN-GPT-AGENT-TOOLS — completed AskBrain answer becomes BZDoc's priority downstream content packet.
				'final_answer'    => $this->limit_tool_text( (string) ( $opts['final_answer_md'] ?? '' ), 12000 ),
				'enriched_prompt' => $this->limit_tool_text( $tool_prompt, 8000 ),
				'title'           => $this->derive_tool_title( $slug, $user_prompt !== '' ? $user_prompt : $tool_prompt ),
			),
			'thread'       => array(
				'session_id' => (string) ( $opts['session_id'] ?? '' ),
				'summary'    => $this->build_history_summary_text( $thread_history ),
				'recent'     => $thread_history,
			),
			'files'        => array(
				'attachment_ids' => $attachment_ids,
				'items'          => $attachments,
				'summary'        => $this->build_attachment_summary_text( $attachments ),
				'ingest'         => $files_ingest,
				'context_md'     => $this->limit_tool_text( (string) ( $opts['multimodal_context_md'] ?? '' ), 7000 ),
			),
			// [2026-07-20 Johnny Chu] PHASE-1-TWIN-GPT-AGENT-TOOLS — explicit acceptance gates for doc/image/pdf/slide handoff parity.
			'quality_gates' => array(
				'user_prompt_priority' => 'The generated artifact must satisfy request.user_prompt before using thread/file context.',
				'thread_context'       => 'Use thread.summary and thread.recent only as supporting context, not as a replacement for the latest prompt.',
				'file_context'         => 'If files/media are present, use files.summary, files.ingest and files.items evidence in the artifact skeleton.',
				'output_contract'      => 'Respect artifact_type and owner app export path: document/docx/pdf, pptx/slide, xlsx, image or mindmap.',
				'traceability'         => 'Persist this tool_spec and downstream spec_trace so chat-side and bizcity-doc-side JSON can be compared.',
			),
			'priority'     => array( 'request.user_prompt', 'request.final_answer', 'spec_trace', 'thread.summary', 'files.summary', 'request.enriched_prompt' ),
		);
	}

	private function summarize_tool_history( array $history ): array {
		$out = array();
		$history = array_slice( $history, -10 );
		foreach ( $history as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$role = sanitize_key( (string) ( $item['role'] ?? $item['type'] ?? 'message' ) );
			$content = (string) ( $item['content'] ?? $item['text'] ?? $item['message'] ?? '' );
			$content = trim( wp_strip_all_tags( $content ) );
			if ( '' === $content ) {
				continue;
			}
			$out[] = array(
				'role'    => $role !== '' ? $role : 'message',
				'content' => $this->limit_tool_text( $content, 900 ),
			);
		}
		return $out;
	}

	private function summarize_tool_attachments( array $attachments, array $attachment_ids, array $opts ): array {
		$out = array();
		$idx = 0;
		$ingest_pack = isset( $opts['multimodal_ingest_pack'] ) && is_array( $opts['multimodal_ingest_pack'] ) ? $opts['multimodal_ingest_pack'] : array();
		foreach ( $attachments as $attachment ) {
			if ( ! is_array( $attachment ) ) {
				continue;
			}
			$mime = sanitize_text_field( (string) ( $attachment['mime_type'] ?? '' ) );
			$kind = 0 === strpos( strtolower( $mime ), 'image/' ) ? 'image' : ( 0 === strpos( strtolower( $mime ), 'audio/' ) ? 'audio' : 'file' );
			$attachment_id = isset( $attachment['id'] ) ? absint( $attachment['id'] ) : ( isset( $attachment_ids[ $idx ] ) ? absint( $attachment_ids[ $idx ] ) : 0 );
			$filename = sanitize_file_name( (string) ( $attachment['filename'] ?? '' ) );
			$intake = $this->build_tool_attachment_intake( array(
				'id'       => $attachment_id,
				'filename' => $filename,
				'kind'     => $kind,
			), $ingest_pack );
			$out[] = array(
				'id'       => $attachment_id,
				'filename' => $filename,
				'kind'     => $kind,
				'mime'     => $mime,
				'size'     => isset( $attachment['size'] ) ? absint( $attachment['size'] ) : 0,
				'url'      => isset( $attachment['url'] ) ? esc_url_raw( (string) $attachment['url'] ) : '',
				'summary'  => $this->limit_tool_text( (string) ( $attachment['summary'] ?? $attachment['analysis'] ?? $attachment['vision_summary'] ?? $intake['summary'] ?? '' ), 1600 ),
				'intake'   => $intake,
			);
			$idx++;
		}
		return $out;
	}

	private function build_tool_files_ingest_summary( array $opts ): array {
		// [2026-07-20 Johnny Chu] PHASE-TWIN-GPT-AGENT-TOOLS — compact multimodal pack audit for downstream artifact generators.
		$pack = isset( $opts['multimodal_ingest_pack'] ) && is_array( $opts['multimodal_ingest_pack'] ) ? $opts['multimodal_ingest_pack'] : array();
		if ( empty( $pack ) ) {
			return array();
		}

		$vision_summaries = array();
		foreach ( (array) ( $pack['vision'] ?? array() ) as $vision ) {
			if ( is_array( $vision ) && ! empty( $vision['summary'] ) ) {
				$vision_summaries[] = $this->limit_tool_text( (string) $vision['summary'], 1200 );
			}
		}

		return array(
			'schema'          => (string) ( $pack['schema'] ?? '' ),
			'intent'          => (string) ( $pack['intent'] ?? '' ),
			'confidence'      => (string) ( $pack['confidence'] ?? '' ),
			'degraded'        => ! empty( $pack['degraded'] ),
			'reason_bucket'   => (string) ( $pack['reason_bucket'] ?? '' ),
			'vision_summary'  => $this->limit_tool_text( implode( "\n", $vision_summaries ), 2500 ),
			'ocr_text'        => $this->list_tool_values( $pack['ocr'] ?? array(), 12 ),
			'entities'        => $this->list_tool_values( $pack['entities'] ?? array(), 16 ),
			'query_expansion' => $this->list_tool_values( $pack['query_expansion'] ?? array(), 20 ),
			'file_text_count' => count( (array) ( $pack['file_text'] ?? array() ) ),
			'audio_count'     => count( (array) ( $pack['audio_transcript'] ?? array() ) ),
		);
	}

	private function build_tool_attachment_intake( array $attachment, array $pack ): array {
		$id = absint( $attachment['id'] ?? 0 );
		$filename = sanitize_file_name( (string) ( $attachment['filename'] ?? '' ) );
		$kind = (string) ( $attachment['kind'] ?? 'file' );
		$intake = array(
			'status'          => empty( $pack ) ? 'not_run' : ( ! empty( $pack['degraded'] ) ? 'degraded' : 'ok' ),
			'reason_bucket'   => (string) ( $pack['reason_bucket'] ?? '' ),
			'summary'         => '',
			'text_excerpt'    => '',
			'transcript'      => '',
			'ocr_text'        => array(),
			'entities'        => array(),
			'confidence'      => (string) ( $pack['confidence'] ?? '' ),
		);

		if ( 'image' === $kind ) {
			$vision_summaries = array();
			foreach ( (array) ( $pack['vision'] ?? array() ) as $vision ) {
				if ( is_array( $vision ) && ! empty( $vision['summary'] ) ) {
					$vision_summaries[] = (string) $vision['summary'];
				}
			}
			$intake['summary'] = $this->limit_tool_text( implode( "\n", $vision_summaries ), 1800 );
			$intake['ocr_text'] = $this->list_tool_values( $pack['ocr'] ?? array(), 10 );
			$intake['entities'] = $this->list_tool_values( $pack['entities'] ?? array(), 12 );
			return $intake;
		}

		if ( 'audio' === $kind ) {
			$segment = $this->find_tool_multimodal_segment( (array) ( $pack['audio_transcript'] ?? array() ), $id, $filename );
			$intake['transcript'] = $this->limit_tool_text( (string) ( $segment['text'] ?? '' ), 2500 );
			$intake['summary'] = $intake['transcript'];
			return $intake;
		}

		$segment = $this->find_tool_multimodal_segment( (array) ( $pack['file_text'] ?? array() ), $id, $filename );
		$intake['text_excerpt'] = $this->limit_tool_text( (string) ( $segment['text'] ?? '' ), 3000 );
		$intake['summary'] = $intake['text_excerpt'];
		return $intake;
	}

	private function find_tool_multimodal_segment( array $segments, int $attachment_id, string $filename ): array {
		foreach ( $segments as $segment ) {
			if ( ! is_array( $segment ) ) {
				continue;
			}
			$segment_id = absint( $segment['attachment_id'] ?? $segment['id'] ?? 0 );
			$segment_filename = sanitize_file_name( (string) ( $segment['filename'] ?? '' ) );
			if ( $attachment_id > 0 && $segment_id === $attachment_id ) {
				return $segment;
			}
			if ( $filename !== '' && $segment_filename !== '' && strtolower( $filename ) === strtolower( $segment_filename ) ) {
				return $segment;
			}
		}
		return array();
	}

	private function list_tool_values( $value, int $limit ): array {
		$out = array();
		foreach ( (array) $value as $item ) {
			if ( is_array( $item ) || is_object( $item ) ) {
				$item = wp_json_encode( $item );
			}
			$item = trim( wp_strip_all_tags( (string) $item ) );
			if ( '' === $item ) {
				continue;
			}
			$out[] = $this->limit_tool_text( $item, 300 );
			if ( count( $out ) >= max( 1, $limit ) ) {
				break;
			}
		}
		return $out;
	}

	private function build_history_summary_text( array $history ): string {
		$lines = array();
		foreach ( $history as $item ) {
			$lines[] = strtoupper( (string) ( $item['role'] ?? 'message' ) ) . ': ' . (string) ( $item['content'] ?? '' );
		}
		return $this->limit_tool_text( implode( "\n", $lines ), 5000 );
	}

	private function build_attachment_summary_text( array $attachments ): string {
		$lines = array();
		foreach ( $attachments as $item ) {
			$line = '- ' . (string) ( $item['filename'] ?? '' ) . ' | ' . (string) ( $item['kind'] ?? 'file' ) . ' | ' . (string) ( $item['mime'] ?? '' );
			if ( ! empty( $item['summary'] ) ) {
				$line .= ' | ' . (string) $item['summary'];
			}
			$lines[] = $line;
		}
		return $this->limit_tool_text( implode( "\n", $lines ), 3000 );
	}

	private function limit_tool_text( $text, $max ): string {
		$text = trim( (string) $text );
		$max = max( 1, absint( $max ) );
		if ( function_exists( 'mb_strlen' ) && mb_strlen( $text, 'UTF-8' ) > $max ) {
			return mb_substr( $text, 0, $max - 1, 'UTF-8' ) . '...';
		}
		if ( ! function_exists( 'mb_strlen' ) && strlen( $text ) > $max ) {
			return substr( $text, 0, $max - 1 ) . '...';
		}
		return $text;
	}

	private function derive_tool_title( string $slug, string $prompt ): string {
		$title = trim( wp_strip_all_tags( $prompt ) );
		if ( function_exists( 'mb_strlen' ) && mb_strlen( $title, 'UTF-8' ) > 96 ) {
			$title = mb_substr( $title, 0, 96, 'UTF-8' );
		} elseif ( strlen( $title ) > 96 ) {
			$title = substr( $title, 0, 96 );
		}
		if ( '' !== $title ) {
			return $title;
		}
		$labels = array(
			'create_doc'     => 'Tài liệu Twin GPT',
			'create_xlsx'    => 'Bảng tính Twin GPT',
			'create_pptx'    => 'Slide Twin GPT',
			'create_mindmap' => 'Mindmap Twin GPT',
			'generate_image' => 'Ảnh Twin GPT',
		);
		return isset( $labels[ $slug ] ) ? $labels[ $slug ] : 'Twin GPT Artifact';
	}

	/**
	 * Convert a dispatch result into the `tool_results[]` shape the
	 * Synthesizer expects (so the LLM can cite real output instead of the
	 * `PLANNED:` placeholder).
	 */
	private function dispatched_tool_results( array $dispatch ): array {
		if ( empty( $dispatch['tool_slug'] ) || ! empty( $dispatch['skipped'] ) ) {
			return array();
		}
		$slug    = (string) $dispatch['tool_slug'];
		$summary = (string) $dispatch['summary'];
		$ok      = ! empty( $dispatch['ok'] );
		$payload = array(
			'skill'  => $slug,
			'result' => $ok
				? ( $summary !== '' ? $summary : 'Tool executed successfully (no summary).' )
				: ( 'Tool execution failed: ' . ( (string) ( $dispatch['error'] ?: 'unknown error' ) ) ),
		);
		if ( ! empty( $dispatch['sources'] ) ) {
			$payload['sources'] = $dispatch['sources'];
		}
		return array( $payload );
	}

	/**
	 * Build the `tool_done` SSE/event payload from a dispatch result.
	 * Kept lean — full result object stays server-side; FE gets a summary +
	 * canvas_open hint.
	 */
	private function tool_done_payload( string $trace_id, array $dispatch, array $decision ): array {
		$status = ! empty( $dispatch['skipped'] )
			? 'skipped'
			: ( ! empty( $dispatch['ok'] ) ? 'ok' : 'error' );
		return array(
			'trace_id'        => $trace_id,
			'surface'         => self::SURFACE,
			'tool_slug'       => (string) $dispatch['tool_slug'],
			'tool_class'      => (string) $dispatch['tool_class'],
			'artifact_type'   => (string) $dispatch['artifact_type'],
			'execution'       => (string) $dispatch['execution'],
			'plan_min'        => (string) $dispatch['plan_min'],
			'capability'      => (string) $dispatch['capability'],
			'status'          => $status,
			'skipped'         => (bool) $dispatch['skipped'],
			'skip_reason'     => (string) $dispatch['skip_reason'],
			'ms'              => (int) $dispatch['ms'],
			'summary'         => (string) $dispatch['summary'],
			'error'           => (string) $dispatch['error'],
			'sources_count'   => is_array( $dispatch['sources'] )      ? count( $dispatch['sources'] )      : 0,
			'citation_count'  => is_array( $dispatch['citation_ids'] ) ? count( $dispatch['citation_ids'] ) : 0,
			'canvas_open'     => $dispatch['canvas_open'],
			'job'             => $dispatch['job'],
			'artifact_created'=> $dispatch['artifact_created'],
			'artifact_ready'  => $dispatch['artifact_ready'],
			'artifacts'       => isset( $dispatch['artifacts'] ) && is_array( $dispatch['artifacts'] ) ? $dispatch['artifacts'] : array(), // [2026-07-31 Johnny Chu] PHASE-TWIN-GPT-AGENT-TOOLS — expose multi-output collection to SSE/history.
			'action_card'     => isset( $dispatch['action_card'] ) && is_array( $dispatch['action_card'] ) ? $dispatch['action_card'] : null,
			'args_status'     => (string) ( $dispatch['args_status'] ?? '' ),
			'decision_reason' => (string) ( $decision['reason'] ?? '' ),
		);
	}

	/**
	 * Emit through the canonical Event Bus (R-EVT-1).
	 * Falls back to a low-noise error_log if Event Bus is not booted (e.g. early CLI).
	 */
	public function emit_event( string $event_key, array $payload ): void {
		// [2026-06-04 Johnny Chu] BS-12 — durably persist conversational turns to
		// the event_stream (tagged with session_id) so Brain Session replay can
		// rebuild the full transcript. The V1 dispatch() below is telemetry-only
		// (do_action, no INSERT); user_message / assistant_message MUST survive as
		// rows so list_turns()/the sessions VIEW (turn_count) can read them back.
		if ( ( $event_key === 'user_message' || $event_key === 'assistant_message' )
			&& $this->current_session_id !== ''
			&& class_exists( 'BizCity_Twin_Event_Bus' )
			&& method_exists( 'BizCity_Twin_Event_Bus', 'dispatch_v2' ) ) {
			try {
				$persist_payload = $payload;
				// Taxonomy requires `content`; mirror the user-visible text.
				if ( ! isset( $persist_payload['content'] ) ) {
					$persist_payload['content'] = (string) ( $payload['text'] ?? '' );
				}
				BizCity_Twin_Event_Bus::dispatch_v2(
					$event_key,
					$persist_payload,
					[
						'session_id'   => $this->current_session_id,
						'user_id'      => (int) ( $payload['user_id'] ?? get_current_user_id() ),
						'trace_id'     => (string) ( $payload['trace_id'] ?? '' ),
						'event_source' => 'system',
					]
				);
			} catch ( \Throwable $e ) {
				error_log( '[TwinBrain] turn persist failed: ' . $event_key . ' — ' . $e->getMessage() );
				self::write_runtime_log( 'error', 'turn_persist_exception', $e->getMessage(), array(
					'trace_id' => (string) ( $payload['trace_id'] ?? '' ),
					'surface'  => self::SURFACE,
					'event_key' => (string) $event_key,
					'exception_class' => get_class( $e ),
				) );
			}
		}

		if ( class_exists( 'BizCity_Twin_Event_Bus' ) ) {
			if ( $event_key === 'evidence_fallback_notice'
				&& class_exists( 'BizCity_Twin_Event_Taxonomy' )
				&& method_exists( 'BizCity_Twin_Event_Bus', 'dispatch_v2' ) ) {
				// [2026-08-24 Johnny Chu] TBR-EVIDENCE-FALLBACK — persist the deterministic notice through the canonical append-only Event Stream.
				try {
					BizCity_Twin_Event_Bus::dispatch_v2(
						BizCity_Twin_Event_Taxonomy::EVIDENCE_FALLBACK_NOTICE,
						$payload,
						array(
							'event_source' => 'twinbrain',
							'session_id'   => $this->current_session_id,
							'user_id'      => (int) ( $payload['user_id'] ?? get_current_user_id() ),
							'trace_id'     => (string) ( $payload['trace_id'] ?? '' ),
						)
					);
					return;
				} catch ( \Throwable $e ) {
					error_log( '[TwinBrain] canonical event dispatch failed: ' . $event_key . ' — ' . $e->getMessage() );
				}
			}
			if ( ( $event_key === 'context_bank_started' || $event_key === 'context_bank_done' )
				&& class_exists( 'BizCity_Twin_Event_Taxonomy' )
				&& method_exists( 'BizCity_Twin_Event_Bus', 'dispatch_v2' ) ) {
				// [2026-09-16 Johnny Chu - Chu Hoàng Anh] PHASE-1.33C-C2 — the generic
				// fall-through below calls dispatch() (V1: do_action only, no INSERT), so
				// this pair previously reached the browser SSE stream but never became a
				// durable, trace-queryable row in `bizcity_twin_event_stream`. A read-only
				// trace calculator reading BizCity_Twin_Event_Store::fetch_for_trace()
				// would see every other durably-persisted event but not this phase pair.
				// dispatch_v2() also writes `created_epoch_ms` and `parent_event_uuid` as
				// native indexed columns (class-twin-event-stream-schema.php), which is a
				// stronger monotonic-ordering guarantee than a payload field alone.
				try {
					$type_const = $event_key === 'context_bank_started'
						? BizCity_Twin_Event_Taxonomy::CONTEXT_BANK_STARTED
						: BizCity_Twin_Event_Taxonomy::CONTEXT_BANK_DONE;
					BizCity_Twin_Event_Bus::dispatch_v2(
						$type_const,
						$payload,
						array(
							'event_source'      => 'twinbrain',
							'session_id'        => $this->current_session_id,
							'user_id'           => (int) ( $payload['user_id'] ?? get_current_user_id() ),
							'trace_id'          => (string) ( $payload['trace_id'] ?? '' ),
							'event_uuid'        => (string) ( $payload['event_uuid'] ?? '' ),
							'parent_event_uuid' => (string) ( $payload['parent_event_uuid'] ?? '' ),
							'created_epoch_ms'  => (int) ( $payload['completed_epoch_ms'] ?? $payload['started_epoch_ms'] ?? 0 ) ?: null,
						)
					);
					return;
				} catch ( \Throwable $e ) {
					error_log( '[TwinBrain] context bank phase event dispatch failed: ' . $event_key . ' — ' . $e->getMessage() );
					self::write_runtime_log( 'error', 'context_bank_phase_event_persist_exception', $e->getMessage(), array(
						'trace_id'        => (string) ( $payload['trace_id'] ?? '' ),
						'surface'         => self::SURFACE,
						'event_key'       => (string) $event_key,
						'exception_class' => get_class( $e ),
					) );
					// Fall through to the V1 telemetry-only path below so the browser SSE
					// stream still receives the event even if durable persistence failed.
				}
			}
			try {
				BizCity_Twin_Event_Bus::dispatch( $event_key, $payload );
				return;
			} catch ( \Throwable $e ) {
				// Schema validation may throw for new event_types until taxonomy is updated;
				// surface but don't block Wave 0.
				error_log( '[TwinBrain] event dispatch failed: ' . $event_key . ' — ' . $e->getMessage() );
				self::write_runtime_log( 'error', 'event_dispatch_exception', $e->getMessage(), array(
					'trace_id' => (string) ( $payload['trace_id'] ?? '' ),
					'surface'  => self::SURFACE,
					'event_key' => (string) $event_key,
					'exception_class' => get_class( $e ),
				) );
			}
		}
		self::write_runtime_log( 'warn', 'noop_bus', 'Twin Event Bus unavailable.', array(
			'trace_id' => (string) ( $payload['trace_id'] ?? '' ),
			'surface'  => self::SURFACE,
			'event_key' => (string) $event_key,
		) );
		error_log( '[TwinBrain][noop-bus] ' . $event_key . ' ' . wp_json_encode( $payload, JSON_UNESCAPED_UNICODE ) );
	}
}
