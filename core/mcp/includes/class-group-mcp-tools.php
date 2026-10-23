<?php
/**
 * BizCity_Group_MCP_Tools — `group.*` (PHASE-0.124 W1 B1/B2, doc 20 §3): cell đọc nhóm Zalo ở client.
 *
 * | Tool | Kind | Who | Confirm |
 * |---|---|---|---|
 * | group.list | read | owner, staff (principal) — lọc theo BẬC của `requester_uid` | never |
 * | group.messages | read, `group-transcript@1` | như trên | never |
 * | group.files | read — tài liệu nhóm đã giữ (W2) | như trên | never |
 * | group.files.save | write, idempotent — "nhắc lưu là lưu" (W2, R-JN-27), CELL-system | bậc 1 + 2 | never |
 * | group.settings.set | write, idempotent — CELL-system (model không thấy tên này; tool cell `group_secretary` gọi) | chỉ bậc 1 (UID chủ) | never |
 *
 * Bậc quyền KHÔNG lấy từ principal (lượt trong nhóm cell gọi bằng principal của chủ số): `requester_uid` = UID người đang ra lệnh,
 * site tự xếp bậc từ `policy.owner_uid` + danh sách người được giao của ĐÚNG nhóm (BizCity_CRM_Group_Secretary::tier). Thiếu
 * `requester_uid` ⇒ bậc 3 ⇒ không thấy gì (fail closed). Khách (`guru:public`) không bao giờ tới đây: tool không có trong
 * customer_tools của Guru và roles chỉ owner/staff.
 *
 * Biz Central Brain — Johnny Chu (Chu Hoàng Anh).
 *
 * [2026-10-19 09:40 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.124 W1 — new file.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\MCP
 */

defined( 'ABSPATH' ) || exit;

// [2026-10-24 12:30 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.128 — ROOT CAUSE of tranghana.vn "MCP_TOOL_NOT_FOUND" (group.files.save, journal.record): PHP binds this
// non-inheriting class EARLY (at compile time of this very file), so a bare class_exists() here was already true ⇒ return ⇒ init() at the
// bottom never ran ⇒ none of this file's tools were ever registered (wp eval: class loaded, hooked=false). Guard DROPPED (same fix as context-producers
// 2026-10-12): the loader's require_once already prevents a second load. Pinned by tests/unit/McpToolsFreshLoadS128Test.php.

final class BizCity_Group_MCP_Tools {

	const SINCE = '0.124.0';

	private static $hooked = false;

	public static function init(): void {
		if ( self::$hooked ) {
			return;
		}
		self::$hooked = true;
		add_action( 'bizcity_mcp_register_tools', array( __CLASS__, 'register_tools' ) );
		// [2026-10-24 12:28 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.128 — case tranghana.vn 23/10–24/10: the site catalog had every other tool but none of this file's ⇒ never depend on
		// load order: the registry already booted (hook fired) ⇒ register now; register() is idempotent (same handler = no clash log).
		if ( function_exists( 'did_action' ) && did_action( 'bizcity_mcp_register_tools' ) ) {
			self::register_tools();
		}
	}

	public static function register_tools(): void {
		if ( ! class_exists( 'BizCity_MCP_Tool_Registry' ) || ! class_exists( 'BizCity_CRM_Group_Secretary', false ) ) {
			// [2026-10-24 12:28 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.128 — the owner class was not loaded yet when the registry booted ⇒ one retry at init (late), and a log line so the case is visible.
			if ( function_exists( 'add_action' ) && function_exists( 'did_action' ) && ! did_action( 'init' ) && ! has_action( 'init', array( __CLASS__, 'register_tools' ) ) ) {
				add_action( 'init', array( __CLASS__, 'register_tools' ), 99 );
			} else {
				error_log( '[bizcity-mcp] BizCity_Group_MCP_Tools skipped: ' . ( class_exists( 'BizCity_MCP_Tool_Registry' ) ? 'BizCity_CRM_Group_Secretary not loaded' : 'registry not loaded' ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
			return;
		}
		$R   = 'BizCity_MCP_Tool_Registry';
		$uid = array( 'type' => 'string', 'pattern' => '^[0-9]{3,30}$', 'description' => 'UID Zalo của người đang ra lệnh (site tự xếp bậc).' );

		// @mcp bizcity-mcp-standard@1 tool group.list
		$R::register( 'group.list', array(
			'title'          => 'Các nhóm Zalo của số',
			'description'    => 'Nhóm Zalo của số mà người hỏi được xem (chủ: mọi nhóm; người được giao: nhóm của mình): mã NH-, tên, tin cuối, theo dõi, người được giao.',
			'input_schema'   => array( 'type' => 'object', 'required' => array( 'requester_uid' ), 'properties' => array(
				'requester_uid' => $uid,
				'q'             => array( 'type' => 'string', 'maxLength' => 80 ),
				'limit'         => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => BizCity_CRM_Group_Secretary::LIST_MAX ),
			) ),
			'output_schema'  => $R::envelope_schema( array( 'items' => array( 'type' => 'array', 'items' => array( 'type' => 'object' ) ) ), array( 'items' ) ),
			'read_only'      => true,
			'idempotent'     => true,
			'required_scope' => 'brain.read',
			'handler'        => array( __CLASS__, 'list_groups' ),
			'mode'           => 'notebook',
			'roles'          => array( 'owner', 'staff' ),
			'scopes'         => array( 'brain.read' ),
			'confirm'        => 'never',
			'llm_alias'      => 'group_list',
			'fallback_pack'  => null,
			'since'          => self::SINCE,
		) );

		// @mcp bizcity-mcp-standard@1 tool group.messages
		$R::register( 'group.messages', array(
			'title'          => 'Đọc tin một nhóm Zalo',
			'description'    => 'Tin của một nhóm (group-transcript@1): người gửi theo nhãn + vai trò, mã msg_ref để trích dẫn; trang tối đa 200 tin / 24 KB, đọc tiếp bằng after_msg_ref.',
			'input_schema'   => array( 'type' => 'object', 'required' => array( 'requester_uid' ), 'properties' => array(
				'requester_uid' => $uid,
				'group_ref'     => array( 'type' => 'string', 'pattern' => '^NH-[0-9]{1,12}$' ),
				'thread_id'     => array( 'type' => 'string', 'pattern' => '^[0-9]{3,30}$' ),
				'after_msg_ref' => array( 'type' => 'string', 'maxLength' => 32 ),
				'from'          => array( 'type' => 'string', 'pattern' => '^\d{4}-\d{2}-\d{2}$' ),
				'to'            => array( 'type' => 'string', 'pattern' => '^\d{4}-\d{2}-\d{2}$' ),
				'limit'         => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => BizCity_CRM_Group_Secretary::PAGE_MAX_MSGS ),
			) ),
			'output_schema'  => $R::envelope_schema( array( 'items' => array( 'type' => 'array', 'items' => array( 'type' => 'object' ) ), 'cursor' => array( 'type' => 'string' ), 'has_more' => array( 'type' => 'boolean' ) ), array( 'items', 'has_more' ) ),
			'read_only'      => true,
			'idempotent'     => true,
			'required_scope' => 'brain.read',
			'handler'        => array( __CLASS__, 'messages' ),
			'mode'           => 'notebook',
			'roles'          => array( 'owner', 'staff' ),
			'scopes'         => array( 'brain.read' ),
			'confirm'        => 'never',
			'llm_alias'      => 'group_messages',
			'fallback_pack'  => null,
			'since'          => self::SINCE,
		) );

		// [2026-10-19 10:12 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.124 W2 (B7/B9) — tài liệu nhóm + "nhắc lưu là lưu" (R-JN-27)
		// @mcp bizcity-mcp-standard@1 tool group.files
		$R::register( 'group.files', array(
			'title'          => 'Tài liệu đã lưu của một nhóm Zalo',
			'description'    => 'Tài liệu nhóm đã giữ bản gốc (mã TL-, tên, người gửi, ngày) — dòng sổ tag grp-<gid> trong sổ của chủ số.',
			'input_schema'   => array( 'type' => 'object', 'required' => array( 'requester_uid' ), 'properties' => array(
				'requester_uid' => $uid,
				'group_ref'     => array( 'type' => 'string', 'pattern' => '^NH-[0-9]{1,12}$' ),
				'thread_id'     => array( 'type' => 'string', 'pattern' => '^[0-9]{3,30}$' ),
				'from'          => array( 'type' => 'string', 'pattern' => '^\d{4}-\d{2}-\d{2}$' ),
				'to'            => array( 'type' => 'string', 'pattern' => '^\d{4}-\d{2}-\d{2}$' ),
				'limit'         => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => BizCity_CRM_Group_Secretary::FILES_LIST_MAX ),
				'offset'        => array( 'type' => 'integer', 'minimum' => 0 ),
			) ),
			'output_schema'  => $R::envelope_schema( array( 'items' => array( 'type' => 'array', 'items' => array( 'type' => 'object' ) ), 'total' => array( 'type' => 'integer' ) ), array( 'items', 'total' ) ),
			'read_only'      => true,
			'idempotent'     => true,
			'required_scope' => 'brain.read',
			'handler'        => array( __CLASS__, 'files' ),
			'mode'           => 'notebook',
			'roles'          => array( 'owner', 'staff' ),
			'scopes'         => array( 'brain.read' ),
			'confirm'        => 'never',
			'llm_alias'      => 'group_files',
			'fallback_pack'  => null,
			'since'          => self::SINCE,
		) );

		// @mcp bizcity-mcp-standard@1 tool group.files.save
		$R::register( 'group.files.save', array(
			'title'          => 'Lưu tệp của nhóm vào sổ',
			'description'    => 'Chủ hoặc người được giao nhắc lưu: tệp của tin được trích dẫn, hoặc tệp + ảnh gửi trong N phút gần nhất (≤ 10 tệp) vào sổ của chủ số; chạy nền, biên nhận TL- gửi chủ.',
			'input_schema'   => array( 'type' => 'object', 'required' => array( 'requester_uid' ), 'properties' => array(
				'requester_uid' => $uid,
				'group_ref'     => array( 'type' => 'string', 'pattern' => '^NH-[0-9]{1,12}$' ),
				'thread_id'     => array( 'type' => 'string', 'pattern' => '^[0-9]{3,30}$' ),
				'zalo_msg_id'   => array( 'type' => 'string', 'pattern' => '^[0-9]{1,30}$' ),
				'minutes'       => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => BizCity_CRM_Group_Secretary::SAVE_WINDOW_MAX ),
			) ),
			'output_schema'  => $R::envelope_schema( array( 'queued' => array( 'type' => 'integer' ), 'files' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ) ), array( 'queued' ) ),
			'read_only'      => false,
			'idempotent'     => true,
			'required_scope' => 'memory.write',
			'handler'        => array( __CLASS__, 'files_save' ),
			'mode'           => 'notebook',
			'roles'          => array( 'owner', 'staff' ),
			'scopes'         => array( 'memory.write' ),
			'confirm'        => 'never',
			'llm_alias'      => 'group_files_save',
			'fallback_pack'  => null,
			'since'          => self::SINCE,
		) );

		// [2026-10-19 10:41 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.124 W3 (B11/B12) — sổ ghi chép nhóm
		// @mcp bizcity-mcp-standard@1 tool group.briefs
		$R::register( 'group.briefs', array(
			'title'          => 'Sổ ghi chép của một nhóm Zalo',
			'description'    => 'Các brief của nhóm (chunk mỗi N tin · ngày · tuần): quyết định, việc, con số, câu hỏi treo — mỗi mục có msg_ref để mở tin gốc. Không phải nguồn số liệu.',
			'input_schema'   => array( 'type' => 'object', 'required' => array( 'requester_uid' ), 'properties' => array(
				'requester_uid' => $uid,
				'group_ref'     => array( 'type' => 'string', 'pattern' => '^NH-[0-9]{1,12}$' ),
				'thread_id'     => array( 'type' => 'string', 'pattern' => '^[0-9]{3,30}$' ),
				'level'         => array( 'type' => 'string', 'enum' => array( 'chunk', 'day', 'week' ) ),
				'from'          => array( 'type' => 'string', 'pattern' => '^\d{4}-\d{2}-\d{2}$' ),
				'to'            => array( 'type' => 'string', 'pattern' => '^\d{4}-\d{2}-\d{2}$' ),
				'latest'        => array( 'type' => 'boolean' ),
				'limit'         => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 50 ),
			) ),
			'output_schema'  => $R::envelope_schema( array( 'items' => array( 'type' => 'array', 'items' => array( 'type' => 'object' ) ) ), array( 'items' ) ),
			'read_only'      => true,
			'idempotent'     => true,
			'required_scope' => 'brain.read',
			'handler'        => array( __CLASS__, 'briefs' ),
			'mode'           => 'notebook',
			'roles'          => array( 'owner', 'staff' ),
			'scopes'         => array( 'brain.read' ),
			'confirm'        => 'never',
			'llm_alias'      => 'group_briefs',
			'fallback_pack'  => null,
			'since'          => self::SINCE,
		) );

		// @mcp bizcity-mcp-standard@1 tool group.brief.record
		$R::register( 'group.brief.record', array(
			'title'          => 'Ghi một brief vào sổ ghi chép nhóm',
			'description'    => 'CELL-system: job brief của cell (nhóm đang theo dõi) gửi thẻ; site bỏ mọi mục có msg_ref sai nhóm / ngoài khoảng, ghi một dòng grp.brief, cuộn ngày / tuần trước đó bằng mã.',
			'input_schema'   => array( 'type' => 'object', 'required' => array( 'requester_uid', 'from_msg_ref', 'to_msg_ref' ), 'properties' => array(
				'requester_uid'  => $uid,
				'group_ref'      => array( 'type' => 'string', 'pattern' => '^NH-[0-9]{1,12}$' ),
				'thread_id'      => array( 'type' => 'string', 'pattern' => '^[0-9]{3,30}$' ),
				'from_msg_ref'   => array( 'type' => 'string', 'maxLength' => 32 ),
				'to_msg_ref'     => array( 'type' => 'string', 'maxLength' => 32 ),
				'msg_count'      => array( 'type' => 'integer', 'minimum' => 0, 'maximum' => 1000 ),
				'summary'        => array( 'type' => 'string', 'maxLength' => 1200 ),
				'participants'   => array( 'type' => 'array', 'maxItems' => 30, 'items' => array( 'type' => 'string', 'maxLength' => 60 ) ),
				'decisions'      => array( 'type' => 'array', 'maxItems' => 40, 'items' => array( 'type' => 'object' ) ),
				'tasks'          => array( 'type' => 'array', 'maxItems' => 40, 'items' => array( 'type' => 'object' ) ),
				'numbers'        => array( 'type' => 'array', 'maxItems' => 40, 'items' => array( 'type' => 'object' ) ),
				'open_questions' => array( 'type' => 'array', 'maxItems' => 40, 'items' => array( 'type' => 'object' ) ),
				'files'          => array( 'type' => 'array', 'maxItems' => 40, 'items' => array( 'type' => 'string', 'maxLength' => 20 ) ),
			) ),
			'output_schema'  => $R::envelope_schema( array( 'entry_id' => array( 'type' => 'integer' ), 'dropped' => array( 'type' => 'integer' ) ), array( 'entry_id' ) ),
			'read_only'      => false,
			'idempotent'     => true,
			'required_scope' => 'memory.write',
			'handler'        => array( __CLASS__, 'brief_record' ),
			'mode'           => 'notebook',
			'roles'          => array( 'owner' ),
			'scopes'         => array( 'memory.write' ),
			'confirm'        => 'never',
			'llm_alias'      => 'group_brief_record',
			'fallback_pack'  => null,
			'since'          => self::SINCE,
		) );

		// [2026-10-19 11:45 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.124 W4 (B15/B16) — sản phẩm làm cho nhóm + ghi việc vào sổ từ chat
		$common = array(
			'read_only' => false, 'idempotent' => true, 'required_scope' => 'memory.write', 'mode' => 'notebook', 'roles' => array( 'owner', 'staff' ),
			'scopes' => array( 'memory.write' ), 'confirm' => 'never', 'fallback_pack' => null, 'since' => self::SINCE,
		);
		// @mcp bizcity-mcp-standard@1 tool group.product.record
		$R::register( 'group.product.record', array(
			'title'         => 'Ghi một phiên bản tài liệu làm cho nhóm',
			'description'   => 'CELL-system: phiên bản tài liệu (R-FILE-VERSION) làm từ dữ liệu nhóm ⇒ một dòng sổ của chủ số, tag nhóm, kèm nguyên liệu và người yêu cầu.',
			'input_schema'  => array( 'type' => 'object', 'required' => array( 'requester_uid', 'family', 'v' ), 'properties' => array(
				'requester_uid' => $uid,
				'group_ref'     => array( 'type' => 'string', 'pattern' => '^NH-[0-9]{1,12}$' ),
				'thread_id'     => array( 'type' => 'string', 'pattern' => '^[0-9]{3,30}$' ),
				'family'        => array( 'type' => 'string', 'pattern' => '^fv_[a-f0-9]{12}$' ),
				'v'             => array( 'type' => 'integer', 'minimum' => 1 ),
				'parent_v'      => array( 'type' => 'integer', 'minimum' => 0 ),
				'file_name'     => array( 'type' => 'string', 'maxLength' => 200 ),
				'mime'          => array( 'type' => 'string', 'maxLength' => 120 ),
				'note'          => array( 'type' => 'string', 'maxLength' => 800 ),
				'workpack_hash' => array( 'type' => 'string', 'maxLength' => 64 ),
				'coverage'      => array( 'type' => 'object' ),
				'requested_by'  => array( 'type' => 'string', 'maxLength' => 60 ),
			) ),
			'output_schema' => $R::envelope_schema( array( 'entry_id' => array( 'type' => 'integer' ) ), array( 'entry_id' ) ),
			'handler'       => array( __CLASS__, 'product_record' ),
			'llm_alias'     => 'group_product_record',
		) + $common );
		// @mcp bizcity-mcp-standard@1 tool group.track
		$R::register( 'group.track', array(
			'title'         => 'Ghi một việc của nhóm vào sổ',
			'description'   => 'CELL-system: sau khi chủ / người được giao XÁC NHẬN (Q124-7) ⇒ task.todo trong sổ của chủ số, tag nhóm, trỏ về tin gốc.',
			'input_schema'  => array( 'type' => 'object', 'required' => array( 'requester_uid', 'msg_ref', 'what' ), 'properties' => array(
				'requester_uid' => $uid,
				'group_ref'     => array( 'type' => 'string', 'pattern' => '^NH-[0-9]{1,12}$' ),
				'thread_id'     => array( 'type' => 'string', 'pattern' => '^[0-9]{3,30}$' ),
				'msg_ref'       => array( 'type' => 'string', 'maxLength' => 32 ),
				'what'          => array( 'type' => 'string', 'maxLength' => 200 ),
				'who'           => array( 'type' => 'string', 'maxLength' => 60 ),
				'due'           => array( 'type' => 'string', 'pattern' => '^(\d{4}-\d{2}-\d{2})?$' ),
			) ),
			'output_schema' => $R::envelope_schema( array( 'entry_id' => array( 'type' => 'integer' ), 'ref_code' => array( 'type' => 'string' ) ), array( 'entry_id' ) ),
			'handler'       => array( __CLASS__, 'track' ),
			'llm_alias'     => 'group_track',
		) + $common );

		// @mcp bizcity-mcp-standard@1 tool group.settings.set
		$R::register( 'group.settings.set', array(
			'title'          => 'Cài đặt thư ký một nhóm',
			'description'    => 'Theo dõi nhóm bật / tắt, giao / bỏ người được giao, brief mỗi N tin — chỉ chủ số (bậc 1). Cùng một chỗ với màn Channel Gateway.',
			'input_schema'   => array( 'type' => 'object', 'required' => array( 'requester_uid', 'action' ), 'properties' => array(
				'requester_uid' => $uid,
				'group_ref'     => array( 'type' => 'string', 'pattern' => '^NH-[0-9]{1,12}$' ),
				'thread_id'     => array( 'type' => 'string', 'pattern' => '^[0-9]{3,30}$' ),
				'action'        => array( 'type' => 'string', 'enum' => array( 'follow_on', 'follow_off', 'assign', 'unassign', 'brief_every', 'notice', 'save_files_on', 'save_files_off' ) ),
				'text'          => array( 'type' => 'string', 'maxLength' => 800, 'description' => 'notice: mẫu câu báo (giữ {nhóm}, {người được giao}, "tắt theo dõi"); rỗng = mẫu mặc định' ),
				'uid'           => array( 'type' => 'string', 'pattern' => '^[0-9]{3,30}$' ),
				'label'         => array( 'type' => 'string', 'maxLength' => 60 ),
				'n'             => array( 'type' => 'integer', 'minimum' => BizCity_CRM_Group_Secretary::BRIEF_MIN, 'maximum' => BizCity_CRM_Group_Secretary::BRIEF_MAX ),
			) ),
			'output_schema'  => $R::envelope_schema( array( 'follow' => array( 'type' => 'boolean' ), 'changed' => array( 'type' => 'boolean' ) ), array( 'follow', 'changed' ) ),
			'read_only'      => false,
			'idempotent'     => true,
			'required_scope' => 'memory.write',
			'handler'        => array( __CLASS__, 'settings_set' ),
			'mode'           => 'notebook',
			'roles'          => array( 'owner', 'staff' ),
			'scopes'         => array( 'memory.write' ),
			'confirm'        => 'never',
			'llm_alias'      => 'group_settings_set',
			'fallback_pack'  => null,
			'since'          => self::SINCE,
		) );
	}

	/* ── handlers ──────────────────────────────────────────────────────── */

	public static function list_groups( array $args, array $ctx ) {
		$bridge = self::bridge( $ctx );
		if ( '' === $bridge ) {
			return self::no_number();
		}
		$items = BizCity_CRM_Group_Secretary::list_for( $bridge, (string) ( $args['requester_uid'] ?? '' ), (string) ( $args['q'] ?? '' ), (int) ( $args['limit'] ?? 20 ) );
		return array( 'items' => $items, 'total' => count( $items ) );
	}

	public static function messages( array $args, array $ctx ) {
		$bridge = self::bridge( $ctx );
		if ( '' === $bridge ) {
			return self::no_number();
		}
		$r = BizCity_CRM_Group_Secretary::messages( $bridge, $args, (string) ( $args['requester_uid'] ?? '' ) );
		return is_wp_error( $r ) ? self::err( $r ) : $r;
	}

	public static function files( array $args, array $ctx ) {
		$bridge = self::bridge( $ctx );
		if ( '' === $bridge ) {
			return self::no_number();
		}
		$r = BizCity_CRM_Group_Secretary::files( $bridge, $args, (string) ( $args['requester_uid'] ?? '' ) );
		return is_wp_error( $r ) ? self::err( $r ) : $r;
	}

	public static function files_save( array $args, array $ctx ) {
		$bridge = self::bridge( $ctx );
		error_log( '[BIZCITY_GROUP_MCP] enter ' . wp_json_encode( array( 'tool' => 'group.files.save', 'bridge' => '…' . substr( $bridge, -4 ), 'quoted' => '' !== (string) ( $args['zalo_msg_id'] ?? '' ), 'role' => (string) ( $ctx['role'] ?? '' ), 'auth' => (string) ( $ctx['auth_method'] ?? '' ) ) ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		if ( '' === $bridge ) {
			self::trace( 'group.files.save', '', $args, self::no_number() );
			return self::no_number();
		}
		$r = BizCity_CRM_Group_Secretary::save_files( $bridge, $args, (string) ( $args['requester_uid'] ?? '' ) );
		self::trace( 'group.files.save', $bridge, $args, $r );
		return is_wp_error( $r ) ? self::err( $r ) : $r;
	}

	public static function briefs( array $args, array $ctx ) {
		$bridge = self::bridge( $ctx );
		if ( '' === $bridge ) {
			return self::no_number();
		}
		$r = BizCity_CRM_Group_Secretary::briefs( $bridge, $args, (string) ( $args['requester_uid'] ?? '' ) );
		return is_wp_error( $r ) ? self::err( $r ) : $r;
	}

	public static function brief_record( array $args, array $ctx ) {
		$bridge = self::bridge( $ctx );
		if ( '' === $bridge ) {
			return self::no_number();
		}
		$r = BizCity_CRM_Group_Secretary::record_brief( $bridge, $args, (string) ( $args['requester_uid'] ?? '' ) );
		return is_wp_error( $r ) ? self::err( $r ) : $r;
	}

	public static function product_record( array $args, array $ctx ) {
		$bridge = self::bridge( $ctx );
		if ( '' === $bridge ) {
			return self::no_number();
		}
		$r = BizCity_CRM_Group_Secretary::record_product( $bridge, $args, (string) ( $args['requester_uid'] ?? '' ) );
		return is_wp_error( $r ) ? self::err( $r ) : $r;
	}

	public static function track( array $args, array $ctx ) {
		$bridge = self::bridge( $ctx );
		if ( '' === $bridge ) {
			return self::no_number();
		}
		$r = BizCity_CRM_Group_Secretary::track_from_chat( $bridge, $args, (string) ( $args['requester_uid'] ?? '' ) );
		return is_wp_error( $r ) ? self::err( $r ) : $r;
	}

	public static function settings_set( array $args, array $ctx ) {
		$bridge = self::bridge( $ctx );
		if ( '' === $bridge ) {
			return self::no_number();
		}
		$requester = (string) ( $args['requester_uid'] ?? '' );
		$g = BizCity_CRM_Group_Secretary::resolve( $bridge, (string) ( $args['group_ref'] ?? '' ), (string) ( $args['thread_id'] ?? '' ), $requester );
		// Nhóm mới chưa có hội thoại CRM (chưa ai nhắn từ khi số vào nhóm): chủ vẫn cài được theo gid.
		if ( is_wp_error( $g ) && 'group_not_found' === $g->get_error_code() && '' !== BizCity_CRM_Group_Secretary::clean_gid( (string) ( $args['thread_id'] ?? '' ) )
			&& 1 === BizCity_CRM_Group_Secretary::tier( $bridge, (string) $args['thread_id'], $requester ) ) {
			$g = array( 'gid' => (string) $args['thread_id'], 'conv_id' => 0 );
		}
		if ( is_wp_error( $g ) ) {
			return self::err( $g );
		}
		$r = BizCity_CRM_Group_Secretary::apply_command( $bridge, $g['gid'], (string) ( $args['action'] ?? '' ), $args, $requester );
		if ( is_wp_error( $r ) ) {
			return self::err( $r );
		}
		return array(
			'group_ref'   => $g['conv_id'] > 0 ? BizCity_CRM_Group_Secretary::group_ref( (int) $g['conv_id'] ) : '',
			'name'        => (string) ( $g['name'] ?? '' ), // B10: tin báo trong nhóm cần tên nhóm (cell không biết)
			'follow'      => $r['follow'],
			'assignees'   => array_map( static function ( $a ) { return $a['label']; }, $r['assignees'] ),
			'brief_every' => $r['brief_every'],
			'notice'      => $r['notice'],
			'save_files'  => $r['save_files'],
			'changed'     => $r['changed'],
		);
	}

	/* ── shared ──────────────────────────────────────────────────────── */

	/** Số Zalo của lượt = account_id của delegation (cell → Hub → site); không có ⇒ không đọc nhóm nào. */
	private static function bridge( array $ctx ): string {
		return substr( preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) ( $ctx['account_id'] ?? '' ) ), 0, 64 );
	}

	/**
	 * [2026-10-20 11:40 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.124 — vết chẩn đoán MỘT dòng cho lệnh ghi (không UID đầy đủ, không nội dung): vì sao trợ lý báo "không lưu được"
	 * (mã lỗi site, số / người hỏi che còn 4 ký tự). Chỉ ghi khi LỖI hoặc khi bật WP_DEBUG; kênh `[BIZCITY_GROUP_MCP]` trong error_log.
	 */
	private static function trace( string $tool, string $bridge, array $args, $result ): void {
		// [2026-10-21 12:45 AM Johnny Chu - Chu Hoàng Anh] lệnh LƯU ghi vết cả khi thành công (hiếm, mỗi lần bạn gõ lệnh) để phân biệt "hàm chạy rồi" với "bị chặn trước khi tới hàm"
		if ( ! is_wp_error( $result ) && ! ( defined( 'WP_DEBUG' ) && WP_DEBUG ) && 'group.files.save' !== $tool ) {
			return;
		}
		$tail = static function ( string $v ): string { return '…' . substr( $v, -4 ); };
		$line = array(
			'tool'   => $tool,
			'bridge' => $tail( $bridge ),
			'req'    => $tail( (string) ( $args['requester_uid'] ?? '' ) ),
			'tier'   => class_exists( 'BizCity_CRM_Group_Secretary' ) ? BizCity_CRM_Group_Secretary::tier( $bridge, preg_replace( '/[^0-9]/', '', (string) ( $args['thread_id'] ?? '' ) ), (string) ( $args['requester_uid'] ?? '' ) ) : -1,
			'owner_set' => class_exists( 'BizCity_CRM_Group_Secretary' ) && '' !== BizCity_CRM_Group_Secretary::owner_uid( $bridge ),
			'quoted' => '' !== (string) ( $args['zalo_msg_id'] ?? '' ),
			'ok'     => ! is_wp_error( $result ),
			'code'   => is_wp_error( $result ) ? $result->get_error_code() : '',
			'msg'    => is_wp_error( $result ) ? substr( $result->get_error_message(), 0, 160 ) : '',
		);
		error_log( '[BIZCITY_GROUP_MCP] ' . wp_json_encode( $line ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}

	private static function no_number(): WP_Error {
		$mcp = class_exists( 'BizCity_MCP_Error' ) && defined( 'BizCity_MCP_Error::QUERY_INVALID' ) ? BizCity_MCP_Error::QUERY_INVALID : 'query_invalid';
		return new WP_Error( $mcp, 'Lượt này không gắn số Zalo nào — chỉ đọc nhóm qua trợ lý của số.', array( 'status' => 400, 'reason' => 'group_no_number' ) );
	}

	private static function err( WP_Error $e ): WP_Error {
		$code   = (string) $e->get_error_code();
		$data   = $e->get_error_data();
		$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 400;
		$mcp    = class_exists( 'BizCity_MCP_Error' ) && defined( 'BizCity_MCP_Error::QUERY_INVALID' ) ? BizCity_MCP_Error::QUERY_INVALID : 'query_invalid';
		return new WP_Error( $mcp, $e->get_error_message(), array( 'status' => $status, 'reason' => $code ) );
	}
}

BizCity_Group_MCP_Tools::init();
