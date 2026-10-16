<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Cron
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 *
 * BizCity_Cron_MCP — ops tools of the cron registry on the one MCP door.
 *
 *   cron.list_jobs()      → jobs (registry + health)                    read
 *   cron.list_retries()   → pending + dead retry rows                    read
 *   cron.run_one(job_id)  → run a job now (confirm always + preview)     write, administrators
 *
 * These are OPS tools: `mode` stays null, so the cell / brain never lists them (MCP-DELEGATION gate); an administrator's
 * MCP client (OAuth / API key, scope `ops.read` / `ops.write`) does. R-GW-8: client-side only, never calls the router.
 *
 * // [2026-10-11 Johnny Chu - Chu Hoàng Anh] PHASE-0.101 S101-L3 (doc 30 lệch D / §4 #… `cron.*`) — the old `add_filter( 'bizcity_mcp_register_tools' )`
 * //   never ran (the hook is a parameterless do_action) ⇒ the three tools were dead since PHASE-0.54. Now registered through
 * //   BizCity_MCP_Tool_Registry::register() on the action, with the registry's own confirm flow for run_one.
 */

defined( 'ABSPATH' ) || exit;

class BizCity_Cron_MCP {

	public static function register(): void {
		add_action( 'bizcity_mcp_register_tools', [ __CLASS__, 'register_tools' ] );
	}

	/** Descriptors into the registry (tool-registry@1 keys; ops tier, administrators, no agent mode). */
	public static function register_tools(): void {
		if ( ! class_exists( 'BizCity_MCP_Tool_Registry' ) || ! class_exists( 'BizCity_Cron_Manager' ) ) {
			return;
		}
		$S     = 'BizCity_MCP_Tool_Registry';
		$empty = [ 'type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false ];
		$ops   = [ 'tier' => 'site', 'roles' => [ 'owner' ], 'scope' => 'tenant', 'platform' => [ '*' ], 'mode' => null, 'since' => '0.101.0', 'fallback_pack' => null ];

		$S::register( 'cron.list_jobs', $ops + [
			'title'          => 'Danh sách cron job',
			'description'    => 'Liệt kê các cron job đã đăng ký với BizCity_Cron_Manager (job_id, hook, lần chạy kế/trước, trạng thái gần nhất) để chẩn đoán lịch.',
			'input_schema'   => $empty,
			'output_schema'  => $S::envelope_schema( [ 'jobs' => [ 'type' => 'array' ] ], [ 'jobs' ] ),
			'read_only'      => true,
			'idempotent'     => true,
			'required_scope' => 'ops.read',
			'scopes'         => [ 'ops.read' ],
			'confirm'        => 'never',
			'llm_alias'      => 'cron_list_jobs',
			'handler'        => [ __CLASS__, 'tool_list_jobs' ],
		] );

		$S::register( 'cron.list_retries', $ops + [
			'title'          => 'Hàng chờ thử lại của cron',
			'description'    => 'Các dòng thử lại đang chờ hoặc đã chết (job_id, lần thử, trạng thái, giờ chạy kế, lỗi cuối).',
			'input_schema'   => $empty,
			'output_schema'  => $S::envelope_schema( [ 'retries' => [ 'type' => 'array' ] ], [ 'retries' ] ),
			'read_only'      => true,
			'idempotent'     => true,
			'required_scope' => 'ops.read',
			'scopes'         => [ 'ops.read' ],
			'confirm'        => 'never',
			'llm_alias'      => 'cron_list_retries',
			'handler'        => [ __CLASS__, 'tool_list_retries' ],
		] );

		$S::register( 'cron.run_one', $ops + [
			'title'          => 'Chạy một cron job ngay',
			'description'    => 'Chạy đồng bộ một job đã đăng ký (job_id từ cron.list_jobs). Thất bại ⇒ job vào hàng chờ thử lại. Lần gọi đầu chỉ trả bản xem trước + confirm_token; gọi lại kèm confirm_token.',
			'input_schema'   => [ 'type' => 'object', 'required' => [ 'job_id' ], 'properties' => [ 'job_id' => [ 'type' => 'string', 'maxLength' => 120 ], 'confirm_token' => [ 'type' => 'string' ] ], 'additionalProperties' => false ],
			'output_schema'  => $S::envelope_schema( [ 'status' => [ 'type' => 'string' ], 'preview' => [ 'type' => 'object' ], 'confirm_token' => [ 'type' => 'string' ], 'result' => [ 'type' => 'object' ] ] ),
			'read_only'      => false,
			'destructive'    => false,
			'idempotent'     => false,
			'required_scope' => 'ops.write',
			'required_cap'   => 'manage_options',
			'scopes'         => [ 'ops.write' ],
			'confirm'        => 'always',
			'llm_alias'      => 'cron_run_one',
			'handler'        => [ __CLASS__, 'tool_run_one' ],
			'preview'        => [ __CLASS__, 'preview_run_one' ],
		] );
	}

	public static function tool_list_jobs( array $args = [], array $ctx = [] ): array {
		return [ 'jobs' => BizCity_Cron_Manager::instance()->all() ];
	}

	/** Side-effect-free: names the job and its last status before the administrator confirms. */
	public static function preview_run_one( array $args = [], array $ctx = [] ) {
		$job_id = sanitize_text_field( (string) ( $args['job_id'] ?? '' ) );
		if ( '' === $job_id ) {
			return new WP_Error( 'MCP_QUERY_INVALID', 'Cần job_id (xem cron.list_jobs).', [ 'status' => 422 ] );
		}
		foreach ( (array) BizCity_Cron_Manager::instance()->all() as $job ) {
			if ( (string) ( $job['job_id'] ?? $job['id'] ?? '' ) === $job_id ) {
				return [ 'summary' => 'Chạy ngay job ' . $job_id . ' (trạng thái gần nhất: ' . (string) ( $job['last_status'] ?? 'chưa chạy' ) . ').', 'job' => $job ];
			}
		}
		return new WP_Error( 'MCP_TOOL_NOT_FOUND', 'Không có job "' . $job_id . '".', [ 'status' => 404 ] );
	}

	public static function tool_run_one( array $args = [], array $ctx = [] ) {
		$job_id = sanitize_text_field( (string) ( $args['job_id'] ?? '' ) );
		if ( '' === $job_id ) {
			return new WP_Error( 'MCP_QUERY_INVALID', 'Cần job_id (xem cron.list_jobs).', [ 'status' => 422 ] );
		}
		$r = BizCity_Cron_Manager::instance()->run_now( $job_id );
		return [ 'status' => 'done', 'result' => is_array( $r ) ? $r : [ 'ok' => (bool) $r ] ];
	}

	public static function tool_list_retries( array $args = [], array $ctx = [] ): array {
		global $wpdb;
		$t = $wpdb->prefix . BizCity_Cron_Manager::TABLE_RETRIES;
		$wpdb->suppress_errors( true );
		$rows = (array) $wpdb->get_results(
			"SELECT job_id, attempt, status, next_run_at, last_error FROM {$t} WHERE status IN ('pending','dead') ORDER BY next_run_at ASC LIMIT 100", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
		$wpdb->suppress_errors( false );
		return [ 'retries' => $rows ];
	}
}
