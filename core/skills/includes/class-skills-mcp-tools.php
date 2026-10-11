<?php
/**
 * BizCity_Skills_MCP_Tools — the five `skills.*` tools of the one MCP door (PHASE-0.101 S101-L6, doc 90 §4).
 *
 * | Tool | Kind | Who | Confirm |
 * |---|---|---|---|
 * | skills.list | read | owner, staff | never |
 * | skills.get | read | owner, staff | never |
 * | skills.propose | write (draft only) | owner (the cell after a harness job) | never |
 * | skills.save | write | owner | always + preview |
 * | skills.set_status | write | owner | always + preview |
 *
 * Mode `notebook` (skills are the number's knowledge). No model, no cell call: handlers only delegate to
 * BizCity_Skills_Service and translate its WP_Error codes to the MCP catalog.
 *
 * Biz Central Brain — Johnny Chu (Chu Hoàng Anh).
 *
 * // [2026-10-11 Johnny Chu - Chu Hoàng Anh] PHASE-0.101 S101-L6 — new file.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Skills
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Skills_MCP_Tools', false ) ) {
	return;
}

final class BizCity_Skills_MCP_Tools {

	const CAP   = 'edit_posts';
	const SINCE = '0.101.0';

	private static $hooked = false;

	public static function init(): void {
		if ( self::$hooked ) {
			return;
		}
		self::$hooked = true;
		add_action( 'bizcity_mcp_register_tools', array( __CLASS__, 'register_tools' ) );
	}

	public static function register_tools(): void {
		if ( ! class_exists( 'BizCity_MCP_Tool_Registry' ) || ! class_exists( 'BizCity_Skills_Service', false ) ) {
			return;
		}
		$R          = 'BizCity_MCP_Tool_Registry';
		$skill_props = array(
			'name'        => array( 'type' => 'string' ),
			'description' => array( 'type' => 'string' ),
			'tools'       => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
			'scope'       => array( 'type' => 'string', 'enum' => BizCity_Skills_Service::SCOPES ),
			'source'      => array( 'type' => 'string', 'enum' => BizCity_Skills_Service::SOURCES ),
			'status'      => array( 'type' => 'string', 'enum' => BizCity_Skills_Service::STATUSES ),
			'version'     => array( 'type' => 'integer' ),
			'updated_at'  => array( 'type' => 'string' ),
		);

		// @mcp bizcity-mcp-standard@1 tool skills.list
		$R::register( 'skills.list', array(
			'title'          => 'Danh sách skill',
			'description'    => 'Liệt kê skill (SKILL.md) của website: tên, mô tả một câu, tools dùng, trạng thái draft/approved/disabled, bản. Không trả thân skill (dùng skills.get).',
			'input_schema'   => array( 'type' => 'object', 'properties' => array(
				'status' => array( 'type' => 'string', 'enum' => BizCity_Skills_Service::STATUSES ),
				'q'      => array( 'type' => 'string', 'maxLength' => 80 ),
				'limit'  => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => BizCity_Skills_Service::MAX_SKILLS ),
			) ),
			'output_schema'  => $R::envelope_schema( array( 'skills' => array( 'type' => 'array', 'items' => array( 'type' => 'object', 'properties' => $skill_props ) ), 'total' => array( 'type' => 'integer' ) ), array( 'skills', 'total' ) ),
			'read_only'      => true,
			'idempotent'     => true,
			'required_scope' => 'brain.read',
			'handler'        => array( __CLASS__, 'list' ),
			'mode'           => 'notebook',
			'roles'          => array( 'owner', 'staff' ),
			'scopes'         => array( 'brain.read' ),
			'confirm'        => 'never',
			'llm_alias'      => 'skills_list',
			'fallback_pack'  => 'skills',
			'since'          => self::SINCE,
		) );

		// @mcp bizcity-mcp-standard@1 tool skills.get
		$R::register( 'skills.get', array(
			'title'          => 'Đọc một skill',
			'description'    => 'Trả toàn bộ một skill theo tên: mô tả, tools, trạng thái, bản và thân Markdown (≤ 8 KB) để làm theo.',
			'input_schema'   => array( 'type' => 'object', 'required' => array( 'name' ), 'properties' => array( 'name' => array( 'type' => 'string', 'pattern' => '^[a-z0-9][a-z0-9-]{1,62}$' ) ) ),
			'output_schema'  => $R::envelope_schema( array_merge( $skill_props, array( 'body_md' => array( 'type' => 'string' ), 'has_previous' => array( 'type' => 'boolean' ) ) ), array( 'name', 'body_md' ) ),
			'read_only'      => true,
			'idempotent'     => true,
			'required_scope' => 'brain.read',
			'handler'        => array( __CLASS__, 'get' ),
			'mode'           => 'notebook',
			'roles'          => array( 'owner', 'staff' ),
			'scopes'         => array( 'brain.read' ),
			'confirm'        => 'never',
			'llm_alias'      => 'skills_get',
			'fallback_pack'  => null,
			'since'          => self::SINCE,
		) );

		// @mcp bizcity-mcp-standard@1 tool skills.propose
		$R::register( 'skills.propose', array(
			'title'          => 'Đề xuất skill (nháp)',
			'description'    => 'Tạo hoặc cập nhật một skill ở trạng thái NHÁP từ kết quả nghiên cứu (harness) — chủ duyệt sau bằng skills.set_status. Không bao giờ ghi đè skill đã duyệt.',
			'input_schema'   => array( 'type' => 'object', 'required' => array( 'name', 'description', 'body_md' ), 'properties' => array(
				'name'        => array( 'type' => 'string', 'pattern' => '^[a-z0-9][a-z0-9-]{1,62}$' ),
				'description' => array( 'type' => 'string', 'maxLength' => BizCity_Skills_Service::MAX_DESC ),
				'body_md'     => array( 'type' => 'string', 'maxLength' => BizCity_Skills_Service::MAX_BODY ),
				'tools'       => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'maxItems' => BizCity_Skills_Service::MAX_TOOLS ),
				'scope'       => array( 'type' => 'string', 'enum' => BizCity_Skills_Service::SCOPES ),
				'from_job'    => array( 'type' => 'string', 'maxLength' => 40 ),
			) ),
			'output_schema'  => $R::envelope_schema( array( 'name' => array( 'type' => 'string' ), 'status' => array( 'type' => 'string' ), 'version' => array( 'type' => 'integer' ), 'created' => array( 'type' => 'boolean' ) ), array( 'name', 'status' ) ),
			'read_only'      => false,
			'destructive'    => false,
			'idempotent'     => true,
			'required_scope' => 'memory.write',
			'required_cap'   => 'edit_posts', // = self::CAP (literal for the R19 marker check)
			'handler'        => array( __CLASS__, 'propose' ),
			'mode'           => 'notebook',
			'roles'          => array( 'owner' ),
			'scopes'         => array( 'memory.write' ),
			'confirm'        => 'never',
			'llm_alias'      => 'skills_propose',
			'fallback_pack'  => null,
			'since'          => self::SINCE,
		) );

		// @mcp bizcity-mcp-standard@1 tool skills.save
		$R::register( 'skills.save', array(
			'title'          => 'Lưu skill',
			'description'    => 'Tạo mới hoặc sửa mô tả / thân / tools / phạm vi của một skill (bản +1, giữ bản trước để hoàn tác). Lần gọi đầu chỉ trả bản xem trước + confirm_token; gọi lại cùng tham số kèm confirm_token sau khi chủ đồng ý.',
			'input_schema'   => array( 'type' => 'object', 'required' => array( 'name' ), 'properties' => array(
				'name'          => array( 'type' => 'string', 'pattern' => '^[a-z0-9][a-z0-9-]{1,62}$' ),
				'description'   => array( 'type' => 'string', 'maxLength' => BizCity_Skills_Service::MAX_DESC ),
				'body_md'       => array( 'type' => 'string', 'maxLength' => BizCity_Skills_Service::MAX_BODY ),
				'tools'         => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'maxItems' => BizCity_Skills_Service::MAX_TOOLS ),
				'scope'         => array( 'type' => 'string', 'enum' => BizCity_Skills_Service::SCOPES ),
				'confirm_token' => array( 'type' => 'string' ),
			) ),
			'output_schema'  => $R::envelope_schema( array(
				'status'        => array( 'type' => 'string', 'enum' => array( 'needs_confirmation', 'done' ) ),
				'preview'       => array( 'type' => 'object' ),
				'confirm_token' => array( 'type' => 'string' ),
				'name'          => array( 'type' => 'string' ),
				'version'       => array( 'type' => 'integer' ),
				'changed'       => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
			) ),
			'read_only'      => false,
			'destructive'    => false,
			'idempotent'     => true,
			'required_scope' => 'memory.write',
			'required_cap'   => 'edit_posts', // = self::CAP (literal for the R19 marker check)
			'handler'        => array( __CLASS__, 'save' ),
			'preview'        => array( __CLASS__, 'save_preview' ),
			'mode'           => 'notebook',
			'roles'          => array( 'owner' ),
			'scopes'         => array( 'memory.write' ),
			'confirm'        => 'always',
			'llm_alias'      => 'skills_save',
			'fallback_pack'  => null,
			'since'          => self::SINCE,
		) );

		// @mcp bizcity-mcp-standard@1 tool skills.set_status
		$R::register( 'skills.set_status', array(
			'title'          => 'Duyệt / vô hiệu skill',
			'description'    => 'Đổi trạng thái một skill: approved (vào gói, não và harness dùng), disabled (rút khỏi gói, giữ file) hoặc draft. Lần gọi đầu chỉ trả bản xem trước + confirm_token; gọi lại kèm confirm_token sau khi chủ đồng ý.',
			'input_schema'   => array( 'type' => 'object', 'required' => array( 'name', 'status' ), 'properties' => array(
				'name'          => array( 'type' => 'string', 'pattern' => '^[a-z0-9][a-z0-9-]{1,62}$' ),
				'status'        => array( 'type' => 'string', 'enum' => BizCity_Skills_Service::STATUSES ),
				'confirm_token' => array( 'type' => 'string' ),
			) ),
			'output_schema'  => $R::envelope_schema( array(
				'status'          => array( 'type' => 'string' ),
				'preview'         => array( 'type' => 'object' ),
				'confirm_token'   => array( 'type' => 'string' ),
				'name'            => array( 'type' => 'string' ),
				'previous_status' => array( 'type' => 'string' ),
			) ),
			'read_only'      => false,
			'destructive'    => false,
			'idempotent'     => true,
			'required_scope' => 'memory.write',
			'required_cap'   => 'edit_posts', // = self::CAP (literal for the R19 marker check)
			'handler'        => array( __CLASS__, 'set_status' ),
			'preview'        => array( __CLASS__, 'set_status_preview' ),
			'mode'           => 'notebook',
			'roles'          => array( 'owner' ),
			'scopes'         => array( 'memory.write' ),
			'confirm'        => 'always',
			'llm_alias'      => 'skills_set_status',
			'fallback_pack'  => null,
			'since'          => self::SINCE,
		) );
	}

	/* ── Handlers ─────────────────────────────────────────────────────── */

	public static function list( array $args, array $ctx ) {
		$filter = array();
		if ( ! empty( $args['status'] ) ) {
			$filter['status'] = (string) $args['status'];
		}
		if ( ! empty( $args['q'] ) ) {
			$filter['q'] = (string) $args['q'];
		}
		$skills = BizCity_Skills_Service::list( $filter, isset( $args['limit'] ) ? (int) $args['limit'] : BizCity_Skills_Service::MAX_SKILLS );
		return array( 'skills' => $skills, 'total' => count( $skills ) );
	}

	public static function get( array $args, array $ctx ) {
		$s = BizCity_Skills_Service::get( (string) ( $args['name'] ?? '' ) );
		if ( ! $s ) {
			return self::err( new WP_Error( 'skill_not_found', 'Không có skill "' . (string) ( $args['name'] ?? '' ) . '".' ) );
		}
		unset( $s['path'] );
		return $s;
	}

	public static function propose( array $args, array $ctx ) {
		$r = BizCity_Skills_Service::propose( $args );
		return is_wp_error( $r ) ? self::err( $r ) : $r;
	}

	public static function save( array $args, array $ctx ) {
		$r = BizCity_Skills_Service::save( $args );
		return is_wp_error( $r ) ? self::err( $r ) : array_merge( array( 'status' => 'done' ), $r );
	}

	public static function save_preview( array $args, array $ctx ) {
		$r = BizCity_Skills_Service::save_preview( $args );
		return is_wp_error( $r ) ? self::err( $r ) : $r;
	}

	public static function set_status( array $args, array $ctx ) {
		$r = BizCity_Skills_Service::set_status( $args );
		return is_wp_error( $r ) ? self::err( $r ) : $r;
	}

	public static function set_status_preview( array $args, array $ctx ) {
		$r = BizCity_Skills_Service::set_status_preview( $args );
		return is_wp_error( $r ) ? self::err( $r ) : $r;
	}

	/** Service error → MCP catalog error (code + HTTP status), message kept, no path/SQL inside. */
	private static function err( WP_Error $e ): WP_Error {
		$code   = (string) $e->get_error_code();
		$status = 'skill_not_found' === $code ? 404 : ( 'skill_exists' === $code || 'skill_unchanged' === $code ? 409 : 400 );
		$mcp    = class_exists( 'BizCity_MCP_Error' ) && defined( 'BizCity_MCP_Error::QUERY_INVALID' ) ? BizCity_MCP_Error::QUERY_INVALID : 'query_invalid';
		if ( 404 === $status && class_exists( 'BizCity_MCP_Error' ) && defined( 'BizCity_MCP_Error::NOT_FOUND' ) ) {
			$mcp = BizCity_MCP_Error::NOT_FOUND;
		}
		return new WP_Error( $mcp, $e->get_error_message(), array( 'status' => $status, 'reason' => $code ) );
	}
}
