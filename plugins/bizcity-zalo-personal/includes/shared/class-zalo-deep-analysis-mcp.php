<?php
/**
 * BizCity_Zalo_Deep_Analysis_MCP — MCP tool `analysis.request`: the cell queues a TwinBrain vertical / deep analysis job
 * for the number's OWNER over core/mcp (owner decision Q-4, 2026-10-07: "cell gọi vertical đường MCP").
 *
 * The verticals (astro, products, woo_bizops, web research …) live in bizcity-twin-brain-addon and call an LLM, so they
 * never run inside an MCP request (R-PF-11, D-MCP-2 5 s / 8 s budget): this handler only validates and queues
 * deep-analysis-job@1 through BizCity_Zalo_Deep_Analysis::enqueue(); WP-cron runs TwinBrain and posts the one result back
 * on the existing /zalo-hub/deep-analysis/result path. system:true — the cell model reaches it only through its native
 * request_deep_analysis tool.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Plugins\Zalo_Personal
 * @since      2026-10-07 (TWINBRAIN-SPLIT-CORE-ADDON Q-4)
 */

defined( 'ABSPATH' ) || exit;

// [2026-10-07 10:05 Johnny Chu - Chu Hoàng Anh] TWINBRAIN-SPLIT-CORE-ADDON Q-4 — new file, MCP door for deep-analysis-job@1.
final class BizCity_Zalo_Deep_Analysis_MCP {

	const TOOL        = 'analysis.request';
	const ETA_SECONDS = 120;

	public static function init(): void {
		add_action( 'bizcity_mcp_register_tools', array( __CLASS__, 'register_tools' ) );
	}

	public static function register_tools(): void {
		if ( ! class_exists( 'BizCity_MCP_Tool_Registry' ) || ! class_exists( 'BizCity_Zalo_Deep_Analysis' ) ) {
			return;
		}
		// @mcp bizcity-mcp-standard@1 tool analysis.request
		BizCity_MCP_Tool_Registry::register( self::TOOL, array(
			'title'          => 'Yêu cầu phân tích sâu (chạy nền)',
			'description'    => 'Xếp một việc phân tích sâu TwinBrain cho chủ số (notebook, CRM, Astro, Woo BizOps, sản phẩm, nghiên cứu web theo lĩnh vực). Chạy nền, kết quả gửi lại sau; không trả lời ngay trong lượt này.',
			'input_schema'   => array(
				'type'       => 'object',
				'required'   => array( 'question' ),
				'properties' => array(
					'question' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => BizCity_Zalo_Deep_Analysis::QUESTION_MAX ),
					'vertical' => array( 'type' => 'string', 'enum' => BizCity_Zalo_Deep_Analysis::verticals() ),
					'job_id'   => array( 'type' => 'string', 'pattern' => '^da_[A-Za-z0-9_-]{6,64}$' ),
					'trace_id' => array( 'type' => 'string', 'maxLength' => 128 ),
				),
			),
			'output_schema'  => BizCity_MCP_Tool_Registry::envelope_schema( array(
				'status'      => array( 'type' => 'string', 'enum' => array( 'queued', 'duplicate' ) ),
				'job_id'      => array( 'type' => 'string' ),
				'vertical'    => array( 'type' => 'string' ),
				'eta_seconds' => array( 'type' => 'integer' ),
				'as_of'       => array( 'type' => 'string' ),
			), array( 'status', 'job_id' ) ),
			'read_only'      => false,
			'destructive'    => false,
			'idempotent'     => true,
			'required_scope' => 'analysis.run',
			'handler'        => array( __CLASS__, 'request' ),
			'mode'           => 'deep_analysis',
			'scopes'         => array( 'analysis.run' ),
			'confirm'        => 'never',
			'llm_alias'      => 'analysis_request',
			'fallback_pack'  => null,
			'open_world'     => true,
			'roles'          => array( 'owner' ),
			'scope'          => 'tenant',
			'tier'           => 'site',
			'capability'     => 'mcp.analysis.request',
			'since'          => '0.92.0',
		) );
	}

	/**
	 * @param array $args {question, vertical?, job_id?, trace_id?}
	 * @param array $ctx  delegated context (BizCity_MCP_Delegation::context): role, user_id, user_hash, account_id …
	 * @return array|WP_Error
	 */
	public static function request( array $args, array $ctx ) {
		// Owner-only (R-TAA-9): the principal was resolved by the bridge from the number's CURRENT owner, never from args.
		if ( 'owner' !== (string) ( $ctx['role'] ?? '' ) || (int) ( $ctx['user_id'] ?? 0 ) <= 0 || '' === (string) ( $ctx['account_id'] ?? '' ) ) {
			return new WP_Error( BizCity_MCP_Error::SCOPE_DENIED, 'Chỉ chủ số mới dùng được phân tích sâu.', array( 'reason' => 'owner_only' ) );
		}
		if ( BizCity_Zalo_Deep_Analysis::cli_isolated() ) {
			return new WP_Error( BizCity_MCP_Error::RATE_LIMITED, 'Không chạy phân tích sâu trong Diagnostics CLI.', array( 'reason' => 'cli_isolated' ) );
		}
		$question = trim( function_exists( 'sanitize_textarea_field' ) ? sanitize_textarea_field( (string) ( $args['question'] ?? '' ) ) : strip_tags( (string) ( $args['question'] ?? '' ) ) );
		if ( '' === $question ) {
			return new WP_Error( BizCity_MCP_Error::QUERY_INVALID, 'Thiếu câu hỏi cần phân tích.', array( 'field' => 'question' ) );
		}
		$job_id = (string) ( $args['job_id'] ?? '' );
		if ( ! preg_match( '/^da_[A-Za-z0-9_-]{6,64}$/', $job_id ) ) {
			// One job per idempotency key (D-MCP-2): a retried call maps to the same job, then enqueue() reports duplicate.
			$seed   = (string) ( $ctx['idempotency_key'] ?? '' );
			$job_id = 'da_' . substr( md5( '' !== $seed ? $seed : (string) wp_generate_uuid4() ), 0, 24 );
		}
		$res = BizCity_Zalo_Deep_Analysis::enqueue( array(
			'account_id' => (string) $ctx['account_id'],
			'job_id'     => $job_id,
			'trace_id'   => (string) ( $args['trace_id'] ?? $ctx['turn_id'] ?? '' ),
			'started_on' => 'mcp',
			'user_id'    => (int) $ctx['user_id'],
			'user_hash'  => (string) ( $ctx['user_hash'] ?? '' ),
			'vertical'   => (string) ( $args['vertical'] ?? '' ),
			'question'   => $question,
		) );
		if ( 'failed' === $res['status'] ) {
			return new WP_Error( BizCity_MCP_Error::INTERNAL_ERROR, 'Site chưa xếp được việc phân tích sâu.', array( 'reason' => 'schedule_failed' ) );
		}
		return array(
			'status'      => $res['status'],
			'job_id'      => $res['job_id'],
			'vertical'    => $res['vertical'],
			'eta_seconds' => self::ETA_SECONDS,
			'as_of'       => gmdate( 'Y-m-d\TH:i:s\Z' ),
		);
	}
}
