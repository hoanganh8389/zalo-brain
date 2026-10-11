<?php
/**
 * BizCity_CRM_Tasks_MCP_Service — crm.task.create · crm.followup.list · crm.staff.metrics (PHASE-0.101 S101-L13, doc 80 §3, doc 30 §5).
 *
 * The three tools the harness test cases HT-C2/C4/C5, HT-A3 and HT-T1…T4 need, on the one MCP door:
 *
 * | Tool | Kind | Source | Who |
 * |---|---|---|---|
 * | crm.task.create | write, confirm always + preview | BizCity_CRM_Task_Handoff::create() of the CRM plugin (its Staff_Policy decides who may assign whom; self by default) | owner, staff |
 * | crm.followup.list | read | core table `bizcity_crm_tasks` (open tasks of the acting user; leaders may ask for everyone) | owner, staff |
 * | crm.staff.metrics | read | BizCity_Business_MCP_Service::get_customer_metrics() grouped by agent_id (CRM report builder) | owner (admin / supervisor) |
 *
 * Every tool exists only when its source class is loaded (no fake capability, R-GW-8); nothing here calls a model.
 *
 * Biz Central Brain — Johnny Chu (Chu Hoàng Anh).
 *
 * // [2026-10-11 Johnny Chu - Chu Hoàng Anh] PHASE-0.101 S101-L13 — new file.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\MCP\Actions
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_CRM_Tasks_MCP_Service', false ) ) {
	return;
}

final class BizCity_CRM_Tasks_MCP_Service {

	const LIST_MAX     = 50;
	const OPEN_STATUSES_EXCLUDED = array( 'done', 'completed', 'cancelled', 'canceled', 'closed' );

	public static function init(): void {
		add_action( 'bizcity_mcp_register_tools', array( __CLASS__, 'register_tools' ) );
	}

	/** Which sources are present (test seam friendly: plain class_exists). */
	public static function has_tasks(): bool {
		return class_exists( 'BizCity_CRM_DB_Installer_V2' ) && method_exists( 'BizCity_CRM_DB_Installer_V2', 'tbl_crm_tasks' )
			&& ( ! class_exists( 'BizCity_CRM_Spine' ) || BizCity_CRM_Spine::has( 'tasks' ) );
	}

	public static function register_tools(): void {
		if ( ! class_exists( 'BizCity_MCP_Tool_Registry' ) ) {
			return;
		}
		$S = 'BizCity_MCP_Tool_Registry';

		if ( self::has_tasks() && class_exists( 'BizCity_CRM_Task_Handoff' ) ) {
			// @mcp bizcity-mcp-standard@1 tool crm.task.create
			$S::register( 'crm.task.create', array(
				'title'          => 'Tạo việc chăm sóc khách',
				'description'    => 'Tạo một việc (follow-up) có tiêu đề, hạn và ghi chú cho một khách (contact_ref) hoặc hội thoại; mặc định giao cho chính người gọi, quản lý có thể giao cho nhân viên khác (assignee). Lần gọi đầu chỉ trả bản xem trước + confirm_token; gọi lại cùng tham số kèm confirm_token sau khi người dùng đồng ý.',
				'input_schema'   => array( 'type' => 'object', 'required' => array( 'title' ), 'properties' => array(
					'title'            => array( 'type' => 'string', 'minLength' => 2, 'maxLength' => 180 ),
					'contact_ref'      => array( 'type' => 'string' ),
					'conversation_id'  => array( 'type' => 'integer', 'minimum' => 1 ),
					'note'             => array( 'type' => 'string', 'maxLength' => 2000 ),
					'due_date'         => array( 'type' => 'string', 'pattern' => '^\\d{4}-\\d{2}-\\d{2}$' ),
					'priority'         => array( 'type' => 'string', 'enum' => array( 'low', 'medium', 'high' ) ),
					'assignee_user_id' => array( 'type' => 'integer', 'minimum' => 1 ),
					'assignee_name'    => array( 'type' => 'string', 'maxLength' => 120 ),
					'confirm_token'    => array( 'type' => 'string' ),
				) ),
				'output_schema'  => $S::envelope_schema( array(
					'status'        => array( 'type' => 'string', 'enum' => array( 'needs_confirmation', 'done' ) ),
					'preview'       => array( 'type' => 'object' ),
					'confirm_token' => array( 'type' => 'string' ),
					'task_ids'      => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ) ),
					'assignee'      => array( 'type' => 'object' ),
					'due_date'      => array( 'type' => array( 'string', 'null' ) ),
				) ),
				'read_only'      => false,
				'destructive'    => false,
				'idempotent'     => true,
				'required_scope' => 'crm.write',
				'required_cap'   => 'bizcity_crm_handle_inbox',
				'handler'        => array( __CLASS__, 'task_create' ),
				'preview'        => array( __CLASS__, 'task_preview' ),
				'mode'           => 'customers',
				'roles'          => array( 'owner', 'staff' ),
				'scopes'         => array( 'crm.write' ),
				'confirm'        => 'always',
				'llm_alias'      => 'crm_task_create',
				'fallback_pack'  => null,
				'since'          => '0.101.0',
			) );
		}

		if ( self::has_tasks() ) {
			// @mcp bizcity-mcp-standard@1 tool crm.followup.list
			$S::register( 'crm.followup.list', array(
				'title'          => 'Việc chăm sóc đang mở',
				'description'    => 'Liệt kê các việc chăm sóc khách (follow-up) chưa xong của người gọi, sắp theo hạn (quá hạn trước). Quản lý có thể xem của mọi người (all=true) hoặc của một nhân viên (assignee_user_id).',
				'input_schema'   => array( 'type' => 'object', 'properties' => array(
					'all'              => array( 'type' => 'boolean' ),
					'assignee_user_id' => array( 'type' => 'integer', 'minimum' => 1 ),
					'overdue_only'     => array( 'type' => 'boolean' ),
					'limit'            => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => self::LIST_MAX ),
				) ),
				'output_schema'  => $S::envelope_schema( array( 'tasks' => array( 'type' => 'array' ), 'total' => array( 'type' => 'integer' ), 'overdue' => array( 'type' => 'integer' ) ), array( 'tasks', 'total' ) ),
				'read_only'      => true,
				'idempotent'     => true,
				'required_scope' => 'crm.read',
				'handler'        => array( __CLASS__, 'followup_list' ),
				'mode'           => 'customers',
				'roles'          => array( 'owner', 'staff' ),
				'scopes'         => array( 'crm.read' ),
				'confirm'        => 'never',
				'llm_alias'      => 'crm_followup_list',
				'fallback_pack'  => null,
				'since'          => '0.101.0',
			) );
		}

		if ( class_exists( 'BizCity_CRM_Report_Builder' ) && class_exists( 'BizCity_Business_MCP_Service' ) ) {
			// @mcp bizcity-mcp-standard@1 tool crm.staff.metrics
			$S::register( 'crm.staff.metrics', array(
				'title'          => 'Chỉ số từng nhân viên',
				'description'    => 'Số hội thoại, tin gửi/nhận, thời gian phản hồi đầu, thời gian xử lý, số ca giải quyết, CSAT và vi phạm SLA của TỪNG nhân viên trong một khoảng ngày (mặc định 30 ngày). Chỉ quản lý / quản trị viên.',
				'input_schema'   => array( 'type' => 'object', 'properties' => array(
					'from'    => array( 'type' => 'string', 'pattern' => '^\\d{4}-\\d{2}-\\d{2}$' ),
					'to'      => array( 'type' => 'string', 'pattern' => '^\\d{4}-\\d{2}-\\d{2}$' ),
					'metrics' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'maxItems' => 8 ),
				) ),
				'output_schema'  => $S::envelope_schema( array( 'from' => array( 'type' => 'string' ), 'to' => array( 'type' => 'string' ), 'staff' => array( 'type' => 'array' ), 'metrics' => array( 'type' => 'object' ) ), array( 'staff' ) ),
				'read_only'      => true,
				'idempotent'     => true,
				'required_scope' => 'crm.read',
				'handler'        => array( __CLASS__, 'staff_metrics' ),
				'mode'           => 'customers',
				'roles'          => array( 'owner' ),
				'scopes'         => array( 'crm.read' ),
				'confirm'        => 'never',
				'llm_alias'      => 'crm_staff_metrics',
				'fallback_pack'  => null,
				'since'          => '0.101.0',
			) );
		}
	}

	/* ── crm.task.create ──────────────────────────────────────────────── */

	public static function task_preview( array $args, array $ctx ) {
		return BizCity_MCP_Action_Support::run_as( $ctx, static function ( $uid ) use ( $args ) {
			$plan = self::task_plan( $args, $uid );
			if ( is_wp_error( $plan ) ) {
				return $plan;
			}
			$what = '' !== $plan['contact_name'] ? 'khách ' . $plan['contact_name'] : ( $plan['conversation_id'] > 0 ? 'hội thoại #' . $plan['conversation_id'] : 'không gắn khách' );
			return array(
				'summary'  => 'Tạo việc "' . $plan['title'] . '" cho ' . $plan['assignee']['name'] . ' (' . $what . ')' . ( $plan['due_date'] ? ', hạn ' . $plan['due_date'] : '' ) . '.',
				'title'    => $plan['title'],
				'assignee' => $plan['assignee'],
				'subject'  => $plan['contact_id'] > 0 ? array( 'contact_ref' => 'crm:' . $plan['contact_id'], 'name' => $plan['contact_name'] ) : ( $plan['conversation_id'] > 0 ? array( 'conversation_id' => $plan['conversation_id'] ) : null ),
				'due_date' => $plan['due_date'],
				'priority' => $plan['priority'],
			);
		} );
	}

	public static function task_create( array $args, array $ctx ) {
		return BizCity_MCP_Action_Support::run_as( $ctx, static function ( $uid ) use ( $args ) {
			$plan = self::task_plan( $args, $uid );
			if ( is_wp_error( $plan ) ) {
				return $plan;
			}
			$payload = array(
				'assignee_user_id'  => $plan['assignee']['user_id'],
				'title'             => $plan['title'],
				'instructions'      => $plan['note'],
				'due_date'          => $plan['due_date'],
				'priority'          => $plan['priority'],
				'client_request_id' => 'mcp-' . substr( md5( wp_json_encode( array( $uid, $plan['title'], $plan['contact_id'], $plan['conversation_id'], $plan['due_date'] ) ) ), 0, 24 ),
			);
			if ( $plan['contact_id'] > 0 ) {
				$payload['contact_ids'] = array( $plan['contact_id'] );
			} elseif ( $plan['conversation_id'] > 0 ) {
				$payload['conversation_id'] = $plan['conversation_id'];
			}
			$res = BizCity_CRM_Task_Handoff::create( $uid, $payload );
			if ( is_wp_error( $res ) ) {
				return BizCity_MCP_Action_Support::from_business( $res );
			}
			return array(
				'status'    => 'done',
				'task_ids'  => array_values( array_map( 'intval', (array) ( $res['created'] ?? array() ) ) ),
				'assignee'  => $plan['assignee'],
				'due_date'  => $plan['due_date'],
				'duplicate' => ! empty( $res['duplicate'] ),
			);
		} );
	}

	/** Shared by preview and commit. Default assignee = the acting user; the CRM Staff_Policy (inside Task_Handoff) decides the rest. */
	private static function task_plan( array $args, $uid ) {
		if ( ! class_exists( 'BizCity_CRM_Task_Handoff' ) ) {
			return BizCity_MCP_Action_Support::crm_unavailable( 'tasks' );
		}
		$title = trim( sanitize_text_field( (string) ( $args['title'] ?? '' ) ) );
		if ( function_exists( 'mb_strlen' ) ? mb_strlen( $title ) < 2 : strlen( $title ) < 2 ) {
			return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::QUERY_INVALID, 'Cần tiêu đề việc (≥ 2 ký tự).', 422 );
		}
		$contact_id = BizCity_MCP_Action_Support::contact_id( $args['contact_ref'] ?? '' );
		$conv_id    = max( 0, (int) ( $args['conversation_id'] ?? 0 ) );
		$name       = '';
		if ( $contact_id > 0 ) {
			$c = BizCity_MCP_Action_Support::contact_access( $contact_id, true, $uid );
			if ( is_wp_error( $c ) ) {
				return $c;
			}
			$name    = (string) $c['name'];
			$conv_id = 0;
		}
		$assignee_id = (int) ( $args['assignee_user_id'] ?? 0 );
		$assignee_nm = trim( (string) ( $args['assignee_name'] ?? '' ) );
		if ( $assignee_id > 0 || '' !== $assignee_nm ) {
			$assignee = BizCity_MCP_Action_Support::staff( $assignee_id, $assignee_nm );
			if ( is_wp_error( $assignee ) ) {
				return $assignee;
			}
		} else {
			$u        = function_exists( 'get_userdata' ) ? get_userdata( (int) $uid ) : null;
			$assignee = array( 'user_id' => (int) $uid, 'name' => is_object( $u ) && ! empty( $u->display_name ) ? (string) $u->display_name : 'tôi' );
		}
		$due = trim( (string) ( $args['due_date'] ?? '' ) );
		if ( '' !== $due && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $due ) ) {
			return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::QUERY_INVALID, 'Hạn phải dạng YYYY-MM-DD.', 422 );
		}
		$priority = (string) ( $args['priority'] ?? 'medium' );
		return array(
			'assignee'        => array( 'user_id' => (int) $assignee['user_id'], 'name' => (string) $assignee['name'] ),
			'contact_id'      => $contact_id,
			'contact_name'    => $name,
			'conversation_id' => $conv_id,
			'title'           => function_exists( 'mb_substr' ) ? mb_substr( $title, 0, 180 ) : substr( $title, 0, 180 ),
			'note'            => trim( sanitize_textarea_field( (string) ( $args['note'] ?? '' ) ) ),
			'due_date'        => '' !== $due ? $due : null,
			'priority'        => in_array( $priority, array( 'low', 'medium', 'high' ), true ) ? $priority : 'medium',
		);
	}

	/* ── crm.followup.list ────────────────────────────────────────────── */

	public static function followup_list( array $args, array $ctx ) {
		return BizCity_MCP_Action_Support::run_as( $ctx, static function ( $uid ) use ( $args ) {
			global $wpdb;
			$leader   = BizCity_MCP_Action_Support::is_admin( $uid ) || in_array( BizCity_MCP_Action_Support::crm_role( $uid ), array( 'admin', 'supervisor' ), true );
			$assignee = (int) $uid;
			if ( ! empty( $args['assignee_user_id'] ) || ! empty( $args['all'] ) ) {
				if ( ! $leader ) {
					return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::SCOPE_DENIED, 'Chỉ quản lý mới xem việc của người khác.', 403 );
				}
				$assignee = ! empty( $args['all'] ) ? 0 : (int) $args['assignee_user_id'];
			}
			$limit = max( 1, min( self::LIST_MAX, (int) ( $args['limit'] ?? 20 ) ) );
			$tbl   = BizCity_CRM_DB_Installer_V2::tbl_crm_tasks();
			$today = function_exists( 'current_time' ) ? current_time( 'Y-m-d' ) : gmdate( 'Y-m-d' );
			$where = "deleted_at IS NULL AND completed = 0 AND status NOT IN ('" . implode( "','", self::OPEN_STATUSES_EXCLUDED ) . "')";
			$vals  = array();
			if ( $assignee > 0 ) {
				$where .= ' AND assignee_id = %d';
				$vals[] = $assignee;
			}
			if ( ! empty( $args['overdue_only'] ) ) {
				$where .= ' AND due_date IS NOT NULL AND due_date < %s';
				$vals[] = $today;
			}
			$sql = "SELECT id, title, status, priority, due_date, assignee_id, related_entity_type, related_entity_id, created_at FROM {$tbl} WHERE {$where} ORDER BY (due_date IS NULL), due_date ASC, id DESC LIMIT %d";
			$vals[] = $limit;
			$rows  = (array) $wpdb->get_results( $wpdb->prepare( $sql, $vals ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$out   = array();
			$over  = 0;
			$names = array();
			foreach ( $rows as $r ) {
				$due     = (string) ( $r['due_date'] ?? '' );
				$overdue = '' !== $due && $due < $today;
				$over   += $overdue ? 1 : 0;
				$aid     = (int) ( $r['assignee_id'] ?? 0 );
				if ( $aid > 0 && ! isset( $names[ $aid ] ) ) {
					$u             = function_exists( 'get_userdata' ) ? get_userdata( $aid ) : null;
					$names[ $aid ] = is_object( $u ) && ! empty( $u->display_name ) ? (string) $u->display_name : '#' . $aid;
				}
				$item = array(
					'task_id'  => (int) $r['id'],
					'title'    => (string) $r['title'],
					'status'   => (string) $r['status'],
					'priority' => (string) $r['priority'],
					'due_date' => '' !== $due ? $due : null,
					'overdue'  => $overdue,
					'assignee' => $aid > 0 ? array( 'user_id' => $aid, 'name' => $names[ $aid ] ) : null,
				);
				if ( 'contact' === (string) ( $r['related_entity_type'] ?? '' ) && (int) $r['related_entity_id'] > 0 ) {
					$c = BizCity_MCP_Action_Support::contact( (int) $r['related_entity_id'] );
					$item['contact_ref'] = 'crm:' . (int) $r['related_entity_id'];
					$item['contact']     = $c ? (string) $c['name'] : '';
				} elseif ( 'conversation' === (string) ( $r['related_entity_type'] ?? '' ) ) {
					$item['conversation_id'] = (int) $r['related_entity_id'];
				}
				$out[] = $item;
			}
			return array( 'tasks' => $out, 'total' => count( $out ), 'overdue' => $over, 'scope' => $assignee > 0 ? 'one' : 'all' );
		} );
	}

	/* ── crm.staff.metrics ────────────────────────────────────────────── */

	public static function staff_metrics( array $args, array $ctx ) {
		return BizCity_MCP_Action_Support::run_as( $ctx, static function ( $uid ) use ( $args, $ctx ) {
			if ( ! BizCity_MCP_Action_Support::is_admin( $uid ) && ! in_array( BizCity_MCP_Action_Support::crm_role( $uid ), array( 'admin', 'supervisor' ), true ) ) {
				return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::SCOPE_DENIED, 'Chỉ quản lý (supervisor) hoặc quản trị viên mới xem chỉ số từng nhân viên.', 403 );
			}
			$metrics = isset( $args['metrics'] ) && is_array( $args['metrics'] ) && $args['metrics']
				? array_slice( array_values( array_unique( array_map( 'sanitize_key', $args['metrics'] ) ) ), 0, 8 )
				: array( 'conversations_count', 'outgoing_messages_count', 'avg_first_response_time', 'avg_resolution_time', 'resolutions_count', 'csat_avg', 'sla_breach_count' );
			$r = BizCity_Business_MCP_Service::instance()->get_customer_metrics( array(
				'from'     => (string) ( $args['from'] ?? '' ),
				'to'       => (string) ( $args['to'] ?? '' ),
				'metrics'  => $metrics,
				'group_by' => 'agent_id',
			), $ctx );
			if ( is_wp_error( $r ) ) {
				return $r;
			}
			if ( ! empty( $r['_degraded'] ) ) {
				return $r;
			}
			// pivot {metric: {agent_id: value}} ⇒ staff[]{user_id, name, metric...} so the brain compares people in one glance
			$staff = array();
			foreach ( (array) ( $r['metrics'] ?? array() ) as $metric => $res ) {
				$groups = is_array( $res ) ? ( $res['groups'] ?? $res['rows'] ?? $res ) : array();
				foreach ( (array) $groups as $k => $v ) {
					$aid = is_array( $v ) ? (int) ( $v['agent_id'] ?? $v['group'] ?? $v['key'] ?? $k ) : (int) $k;
					$val = is_array( $v ) ? ( $v['value'] ?? $v['total'] ?? $v['avg'] ?? null ) : $v;
					if ( $aid <= 0 ) {
						continue;
					}
					if ( ! isset( $staff[ $aid ] ) ) {
						$u             = function_exists( 'get_userdata' ) ? get_userdata( $aid ) : null;
						$staff[ $aid ] = array( 'user_id' => $aid, 'name' => is_object( $u ) && ! empty( $u->display_name ) ? (string) $u->display_name : '#' . $aid );
					}
					$staff[ $aid ][ (string) $metric ] = is_numeric( $val ) ? $val + 0 : $val;
				}
			}
			return array( 'from' => $r['from'] ?? '', 'to' => $r['to'] ?? '', 'staff' => array_values( $staff ), 'metrics' => (array) ( $r['metrics'] ?? array() ), 'source' => (string) ( $r['source'] ?? '' ) );
		} );
	}
}
