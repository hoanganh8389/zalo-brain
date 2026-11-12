<?php
/**
 * BizCity_Twin_Sheet_API — the two read doors of Twin Sheet ("Sổ"), one set of handlers (PHASE-0.123 W1, doc 20 §4):
 *
 * | Door | Name | Who |
 * |---|---|---|
 * | MCP (cell) | twinsheet.list · twinsheet.describe · twinsheet.rows · twinsheet.aggregate | owner, staff — mode notebook, brain.read, confirm never |
 * | REST (web grid) | GET bizcity-knowledge/v2/twinsheets · /twinsheets/<key> · /twinsheets/<key>/rows · /twinsheets/<key>/aggregate | logged-in person |
 *
 * The MCP tools are read only. The owner is the authenticated principal (MCP ctx / get_current_user_id), never an argument. The
 * cell writes through the one journal door (`journal.record`, R-JN-13); the web grid saves through the REST `POST …/changes`
 * (BizCity_Twin_Sheet_Service::apply_changes, W2). No model, no SQL from callers: every
 * filter / sort / group goes through the sheet's allow-list (BizCity_Twin_Sheet_Schema::normalize_query).
 *
 * Biz Central Brain — Johnny Chu (Chu Hoàng Anh).
 *
 * [2026-10-19 09:12 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.123 W1 (B4/B5) — new file.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\KG_Hub\Twin_Sheet
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Twin_Sheet_API', false ) ) {
	return;
}

final class BizCity_Twin_Sheet_API {

	const NS    = 'bizcity-knowledge/v2';
	const SINCE = '0.123.0';
	const KEY_RE = '(?P<key>[a-z][a-z0-9_]{0,23}\.[a-z0-9_]{1,23})';

	private static $hooked = false;

	/** @var BizCity_Twin_Sheet_Store|null test seam */
	private static $store = null;

	public static function init(): void {
		if ( self::$hooked ) {
			return;
		}
		self::$hooked = true;
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_action( 'bizcity_mcp_register_tools', array( __CLASS__, 'register_tools' ) );
		add_action( 'bizcity_mcp_register_tools', array( __CLASS__, 'register_write_tools' ) );
		// [2026-10-19 11:45 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.123 W3 — the CPT that holds the person's own sheet definitions + their kinds in the journal catalog.
		add_action( 'init', array( 'BizCity_Twin_Sheet_Cpt_Defs', 'register_post_type' ) );
		add_filter( 'bizcity_journal_kinds', array( 'BizCity_Twin_Sheet_Registry', 'filter_kinds' ) );
		// [2026-10-21 11:35 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.123 W3 — "Khóa kỳ" also holds for the assistant / automations that write through the journal index
		add_filter( 'bizcity_journal_pre_add', array( 'BizCity_Twin_Sheet_Snapshot', 'guard_journal' ), 10, 5 );
		// [2026-10-22 02:35 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.123 W7 (B2) — after the period lock: the card of a row the assistant writes into a sheet a person made is checked against that sheet
		add_filter( 'bizcity_journal_pre_add', array( __CLASS__, 'check_journal_card' ), 11, 6 );
		// [2026-10-22 02:35 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.123 W7 (B10) — the encrypted JSONL store that keeps row edits older than the last 20 (registration is an in-memory array, no DB)
		if ( class_exists( 'BizCity_File_Contract_Registry' ) && class_exists( 'BizCity_Twin_Sheet_Journal_Writer', false ) && ! BizCity_File_Contract_Registry::has( BizCity_Twin_Sheet_Journal_Writer::HISTORY_CONTRACT ) ) {
			BizCity_File_Contract_Registry::register( BizCity_Twin_Sheet_Journal_Writer::HISTORY_CONTRACT, array( 'owner_module' => 'core/kg-hub', 'label' => 'Twin Sheet row edit history (older than the last 20)', 'folder' => 'bizcity-kg-hub-data', 'module' => 'twinsheet-history', 'related_sql_tables' => array( 'bizcity_journal_entries' ), 'retention_days' => 3650, 'storage_scope' => 'blog' ) );
		}
		add_filter( 'bizcity_journal_pre_state', array( 'BizCity_Twin_Sheet_Snapshot', 'guard_state' ), 10, 4 );
		add_filter( 'wp_revisions_to_keep', array( 'BizCity_Twin_Sheet_Cpt_Defs', 'revisions_to_keep' ), 10, 2 ); // [2026-10-21 08:40 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.123 W3 — WP revisions of a sheet post: 20
	}

	public static function store(): BizCity_Twin_Sheet_Store {
		if ( null === self::$store ) {
			self::$store = new BizCity_Twin_Sheet_Journal_Store();
		}
		return self::$store;
	}

	/** Test seam: swap the adapter (null = back to the journal store). */
	public static function set_store( ?BizCity_Twin_Sheet_Store $store ): void {
		self::$store = $store;
	}

	// ── handlers (shared by MCP + REST) ──────────────────────────────

	/** @return array{items:array,total:int} */
	public static function list_sheets( array $args, array $ctx ) {
		$items = BizCity_Twin_Sheet_Registry::list_for_owner( self::owner( $ctx ) );
		// [2026-10-22 02:35 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.123 W7 (B12) — the starting columns of "Tạo sổ" travel with the list (no extra route)
		return array( 'items' => $items, 'total' => count( $items ), 'templates' => BizCity_Twin_Sheet_Registry::templates() );
	}

	/** @return array|WP_Error twin-sheet@1 definition */
	public static function describe( array $args, array $ctx ) {
		$sheet = BizCity_Twin_Sheet_Registry::get( (string) ( $args['sheet'] ?? '' ), self::owner( $ctx ) );
		if ( is_wp_error( $sheet ) ) {
			return $sheet;
		}
		unset( $sheet['pair_cancel'] );
		return $sheet;
	}

	/** @return array|WP_Error rows page + totals of the view */
	public static function rows( array $args, array $ctx ) {
		$sheet = BizCity_Twin_Sheet_Registry::get( (string) ( $args['sheet'] ?? '' ), self::owner( $ctx ) );
		if ( is_wp_error( $sheet ) ) {
			return $sheet;
		}
		$args = self::with_group( $args );
		if ( is_wp_error( $args ) ) {
			return $args;
		}
		$query = BizCity_Twin_Sheet_Schema::normalize_query( $sheet, $args, self::today() );
		if ( is_wp_error( $query ) ) {
			return $query;
		}
		$out          = self::store()->rows( $sheet, $query, self::owner( $ctx ) );
		$out['sheet'] = $sheet['sheet_key'];
		$out['view']  = $query['view'];
		$out['from']  = $query['from'];
		$out['to']    = $query['to'];
		if ( '' !== $query['group'] ) {
			$out['group'] = (string) $args['group_ref'];
		}
		return $out;
	}

	/**
	 * [2026-10-20 05:48 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.124 S124-X1 — `group` = "NH-<conv>" (mã người thấy trong Sổ nhóm) hoặc gid nhóm Zalo ⇒ args['group'] = gid,
	 * args['group_ref'] = cái người gửi (trả lại nguyên, không lộ gid). Chủ vẫn lấy từ principal: lọc nhóm chỉ thu hẹp dòng
	 * của chính người hỏi, không mở dòng của ai khác.
	 *
	 * @return array|WP_Error
	 */
	public static function with_group( array $args ) {
		$raw = trim( (string) ( $args['group'] ?? '' ) );
		unset( $args['group'], $args['group_ref'] );
		if ( '' === $raw ) {
			return $args;
		}
		$gid = '';
		if ( preg_match( '/^NH-\d{1,12}$/i', $raw ) ) {
			$gid = class_exists( 'BizCity_CRM_Group_Secretary' ) ? BizCity_CRM_Group_Secretary::gid_for_ref( $raw ) : '';
		} elseif ( preg_match( '/^[0-9]{3,30}$/', $raw ) ) {
			$gid = $raw;
		}
		if ( '' === $gid ) {
			return BizCity_Twin_Sheet_Schema::error( 'group_unknown', 'Không thấy nhóm này.', 'Dùng mã nhóm dạng NH-… trong Sổ nhóm.', 404 );
		}
		$args['group']     = $gid;
		$args['group_ref'] = $raw;
		return $args;
	}

	/**
	 * `headline: true` ⇒ the sheet's own headline (the number on the sheet card). Otherwise {fn, field, group_by?, split_by?}.
	 *
	 * @return array|WP_Error
	 */
	public static function aggregate( array $args, array $ctx ) {
		$sheet = BizCity_Twin_Sheet_Registry::get( (string) ( $args['sheet'] ?? '' ), self::owner( $ctx ) );
		if ( is_wp_error( $sheet ) ) {
			return $sheet;
		}
		$args = self::with_group( $args );
		if ( is_wp_error( $args ) ) {
			return $args;
		}
		$spec = array_intersect_key( $args, array_flip( array( 'fn', 'field', 'group_by', 'split_by' ) ) );
		if ( ! empty( $args['headline'] ) ) {
			$h = is_array( $sheet['headline'] ?? null ) ? $sheet['headline'] : array( 'fn' => 'count', 'period' => 'all' );
			$spec = array( 'fn' => (string) ( $h['fn'] ?? 'count' ), 'field' => (string) ( $h['field'] ?? '' ) );
			$args['where']  = is_array( $h['where'] ?? null ) ? $h['where'] : array();
			$args['period'] = (string) ( $h['period'] ?? 'all' );
		}
		$query = BizCity_Twin_Sheet_Schema::normalize_query( $sheet, $args, self::today() );
		if ( is_wp_error( $query ) ) {
			return $query;
		}
		$out = self::store()->aggregate( $sheet, $query, $spec, self::owner( $ctx ) );
		if ( is_array( $out ) ) {
			$out['sheet'] = $sheet['sheet_key'];
			if ( '' !== $query['group'] ) {
				$out['group'] = (string) $args['group_ref'];
			}
			if ( ! empty( $args['headline'] ) ) {
				$out['label'] = (string) ( $sheet['headline']['label'] ?? '' );
			}
		}
		return $out;
	}

	// ── MCP ──────────────────────────────────────────────────────────

	public static function register_tools(): void {
		if ( ! class_exists( 'BizCity_MCP_Tool_Registry' ) ) {
			return;
		}
		$R      = 'BizCity_MCP_Tool_Registry';
		$sheet  = array( 'type' => 'string', 'maxLength' => 48, 'pattern' => '^[a-z][a-z0-9_]{0,23}\\.[a-z0-9_]{1,23}$' );
		$filter = array(
			'sheet'  => $sheet,
			'view'   => array( 'type' => 'string', 'maxLength' => 32 ),
			'period' => array( 'type' => 'string', 'maxLength' => 10 ),
			'from'   => array( 'type' => 'string', 'maxLength' => 10 ),
			'to'     => array( 'type' => 'string', 'maxLength' => 10 ),
			'where'  => array( 'type' => array( 'array', 'object' ), 'maxItems' => 10 ),
			'q'      => array( 'type' => 'string', 'maxLength' => 200 ),
			'group'  => array( 'type' => 'string', 'maxLength' => 32, 'pattern' => '^([Nn][Hh]-[0-9]{1,12}|[0-9]{3,30})$', 'description' => 'Chỉ dòng của một nhóm Zalo: mã NH-… (Sổ nhóm) hoặc mã nhóm.' ),
		);
		$common = array(
			'read_only'      => true,
			'idempotent'     => true,
			'required_scope' => 'brain.read',
			// [2026-10-25 Johnny Chu - Chu Hoàng Anh] PHASE-0.136 D136-28 / Q136-16 (chủ "ok"): Twin Sheet = sổ của CHỦ — chỉ chủ ra lệnh (đọc và ghi); nhân sự chỉ khi site mở lại qua filter
			'roles'          => self::roles(),
			'scopes'         => array( 'brain.read' ),
			'confirm'        => 'never',
			'fallback_pack'  => null,
			'since'          => self::SINCE,
		);

		// @mcp bizcity-mcp-standard@1 tool twinsheet.list
		$R::register( 'twinsheet.list', array_merge( $common, array(
			'title'         => 'Danh sách sổ',
			'description'   => 'Các sổ của chính người hỏi (thu chi, công nợ, kế hoạch, tiến độ, việc cần làm, hợp đồng và mọi loại sổ đã có dòng), kèm số dòng và ngày gần nhất. Không có số liệu.',
			'input_schema'  => array( 'type' => 'object' ),
			'output_schema' => $R::envelope_schema( array( 'items' => array( 'type' => 'array' ), 'total' => array( 'type' => 'integer' ) ), array( 'items', 'total' ) ),
			'handler'       => array( __CLASS__, 'mcp_list' ),
			'llm_alias'     => 'twinsheet_list',
			// [2026-10-22 01:07 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.126 S126-M1 (doc 20 §4b) — Twin Sheet has no tier (tier is document / notebook-source maturity): it declares its layer + the permission it needs
			'brain'         => array( 'layer' => 'twin_sheet', 'permission' => 'meta.read', 'crud' => 'read', 'answer_hint' => 'instant' ),
			'mode'          => 'notebook',
		) ) );

		// @mcp bizcity-mcp-standard@1 tool twinsheet.describe
		$R::register( 'twinsheet.describe', array_merge( $common, array(
			'title'         => 'Cột của một sổ',
			'description'   => 'Cấu trúc một sổ: các cột (tên, kiểu, lọc / sắp / gom / tính được không), cách xem, luật sửa (sổ tiền: hủy + ghi mới). Dùng trước khi lọc hoặc tính.',
			'input_schema'  => array( 'type' => 'object', 'required' => array( 'sheet' ), 'properties' => array( 'sheet' => $sheet ) ),
			'output_schema' => $R::envelope_schema( array( 'sheet_key' => array( 'type' => 'string' ), 'fields' => array( 'type' => 'array' ), 'views' => array( 'type' => 'array' ) ), array( 'sheet_key', 'fields' ) ),
			'handler'       => array( __CLASS__, 'mcp_describe' ),
			'llm_alias'     => 'twinsheet_describe',
			'brain'         => array( 'layer' => 'twin_sheet', 'permission' => 'meta.read', 'crud' => 'read', 'answer_hint' => 'instant' ),
			'mode'          => 'notebook',
		) ) );

		// @mcp bizcity-mcp-standard@1 tool twinsheet.rows
		$R::register( 'twinsheet.rows', array_merge( $common, array(
			'title'         => 'Đọc dòng trong sổ',
			'description'   => 'Đọc các dòng của một sổ theo kỳ (all · today · week · month · year · YYYY-MM), lọc theo cột cho phép, sắp xếp, tìm chữ; tối đa 100 dòng một trang, kèm dòng tổng của cách xem. `group` = NH-… ⇒ chỉ dòng của một nhóm Zalo. Chỉ dòng của chính người hỏi.',
			'input_schema'  => array( 'type' => 'object', 'required' => array( 'sheet' ), 'properties' => array_merge( $filter, array(
				'sort'   => array( 'type' => 'array', 'maxItems' => 3 ),
				'limit'  => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 100 ),
				'offset' => array( 'type' => 'integer', 'minimum' => 0 ),
			) ) ),
			'output_schema' => $R::envelope_schema( array( 'rows' => array( 'type' => 'array' ), 'total' => array( 'type' => 'integer' ), 'totals' => array( 'type' => 'array' ) ), array( 'rows', 'total' ) ),
			'handler'       => array( __CLASS__, 'mcp_rows' ),
			'llm_alias'     => 'twinsheet_rows',
			'brain'         => array( 'layer' => 'twin_sheet', 'permission' => 'content.read', 'crud' => 'read', 'answer_hint' => 'instant' ),
			'mode'          => 'notebook',
		) ) );

		// @mcp bizcity-mcp-standard@1 tool twinsheet.aggregate
		$R::register( 'twinsheet.aggregate', array_merge( $common, array(
			'title'         => 'Tính tổng trong sổ',
			'description'   => 'Tính chính xác (số nguyên, không ước lượng) tổng / đếm / nhỏ nhất / lớn nhất / trung bình một cột số của sổ theo kỳ và bộ lọc, gom theo ngày / tuần / tháng / năm hoặc một cột (vd hạng mục), tách theo một cột (vd thu / chi); `group` = NH-… ⇒ chỉ dòng của một nhóm Zalo. Dòng đã hủy không tính. Dùng cho mọi câu hỏi "bao nhiêu".',
			'input_schema'  => array( 'type' => 'object', 'required' => array( 'sheet' ), 'properties' => array_merge( $filter, array(
				'fn'       => array( 'type' => 'string', 'enum' => array( 'sum', 'count', 'min', 'max', 'avg' ) ),
				'field'    => array( 'type' => 'string', 'maxLength' => 32 ),
				'group_by' => array( 'type' => 'string', 'maxLength' => 32 ),
				'split_by' => array( 'type' => 'string', 'maxLength' => 32 ),
				'headline' => array( 'type' => 'boolean' ),
			) ) ),
			'output_schema' => $R::envelope_schema( array( 'value' => array( 'type' => 'integer' ), 'count' => array( 'type' => 'integer' ), 'groups' => array( 'type' => 'array' ) ), array( 'value', 'count' ) ),
			'handler'       => array( __CLASS__, 'mcp_aggregate' ),
			'llm_alias'     => 'twinsheet_aggregate',
			'brain'         => array( 'layer' => 'twin_sheet', 'permission' => 'content.read', 'crud' => 'read', 'answer_hint' => 'instant' ),
			'mode'          => 'notebook',
		) ) );
	}

	/** PHASE-0.136 D136-28 — who may call twinsheet.* (default the owner only; `bizcity_twinsheet_roles` may add 'staff' when the owner shares a sheet). Never customers. */
	public static function roles(): array {
		$r = function_exists( 'apply_filters' ) ? (array) apply_filters( 'bizcity_twinsheet_roles', array( 'owner' ) ) : array( 'owner' );
		$r = array_values( array_intersect( array( 'owner', 'staff' ), array_map( 'strval', $r ) ) );
		return $r ? $r : array( 'owner' );
	}

	/** [2026-10-19 11:45 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.123 W3 — the two write tools (the person's own sheets only). */
	public static function register_write_tools(): void {
		if ( ! class_exists( 'BizCity_MCP_Tool_Registry' ) ) {
			return;
		}
		$R     = 'BizCity_MCP_Tool_Registry';
		$write = array(
			'read_only'      => false,
			'destructive'    => false,
			'required_scope' => 'memory.write',
			'required_cap'   => 'edit_posts',
			'roles'          => self::roles(), // PHASE-0.136 D136-28 — owner only
			'scopes'         => array( 'memory.write' ),
			'confirm'        => 'never',
			'fallback_pack'  => null,
			'since'          => self::SINCE,
		);

		// @mcp bizcity-mcp-standard@1 tool twinsheet.create
		$R::register( 'twinsheet.create', array_merge( $write, array(
			'title'         => 'Tạo sổ mới',
			'description'   => 'Tạo một sổ mới có các cột do người dùng tự đặt (vd "Sổ học tiếng Anh: ngày, bài học, điểm"). TRƯỚC KHI gọi: đọc lại các cột (tên + kiểu: text · long_text · integer · money · boolean · date · select · percent) kèm đề xuất của em, mục 1 = "Tạo như vậy", mục 0 = huỷ; chỉ gọi khi người dùng đồng ý. Cột select cần options (2–20 lựa chọn). Gọi lại cùng tên + cùng cột trả sổ cũ, không tạo bản sao. Cột tiền ⇒ sổ tiền (không sửa đè: sửa = huỷ + ghi mới). KHÔNG dùng để ghi dòng vào sổ (dùng journal.record với kind = sheet_key trả về) và KHÔNG dùng để chốt kỳ (dùng twinsheet.snapshot).',
			'mode'          => 'notebook',
			'input_schema'  => array( 'type' => 'object', 'required' => array( 'display_name', 'fields' ), 'properties' => array(
				'display_name' => array( 'type' => 'string', 'maxLength' => 80, 'description' => 'Tên sổ người dùng thấy, ví dụ "Sổ học tiếng Anh".' ),
				'fields'       => array( 'type' => 'array', 'minItems' => 1, 'maxItems' => 28, 'description' => 'Các cột, theo thứ tự hiển thị.', 'items' => array( 'type' => 'object', 'required' => array( 'label', 'type' ), 'properties' => array(
					'label'    => array( 'type' => 'string', 'maxLength' => 60, 'description' => 'Tên cột, ví dụ "Điểm".' ),
					'type'     => array( 'type' => 'string', 'enum' => BizCity_Twin_Sheet_Builder::TYPES, 'description' => 'Kiểu dữ liệu của cột.' ),
					'options'  => array( 'type' => 'array', 'maxItems' => 20, 'items' => array( 'type' => 'string', 'maxLength' => 60 ), 'description' => 'Chỉ cho cột select: 2–20 lựa chọn.' ),
					'required' => array( 'type' => 'boolean', 'description' => 'true = dòng nào cũng phải có giá trị ở cột này.' ),
				) ) ),
				'icon'         => array( 'type' => 'string', 'maxLength' => 20, 'description' => 'Tên biểu tượng (tuỳ chọn).' ),
				'folder'       => array( 'type' => 'string', 'maxLength' => 60, 'description' => 'Thư mục chứa sổ (tuỳ chọn).' ),
			) ),
			'output_schema' => $R::envelope_schema( array( 'sheet_key' => array( 'type' => 'string' ), 'already' => array( 'type' => 'boolean' ), 'fields' => array( 'type' => 'array' ) ), array( 'sheet_key' ) ),
			'idempotent'    => true,
			'handler'       => array( __CLASS__, 'mcp_create' ),
			'llm_alias'     => 'twinsheet_create',
			'brain'         => array( 'layer' => 'twin_sheet', 'permission' => 'write', 'crud' => 'write' ),
			'read_only'     => false,
			'required_cap'  => 'edit_posts',
		) ) );

		// @mcp bizcity-mcp-standard@1 tool twinsheet.update
		$R::register( 'twinsheet.update', array_merge( $write, array(
			'title'         => 'Đổi tên / lưu trữ sổ',
			'description'   => 'Đổi tên, biểu tượng, thư mục hoặc lưu trữ (archived=true: ẩn khỏi danh sách, dòng vẫn còn trong sổ ghi chép) một sổ do chính người hỏi tạo. Không đổi cột (cột đổi trên màn Sổ). Các sổ có sẵn không đổi tên được.',
			'mode'          => 'notebook',
			'input_schema'  => array( 'type' => 'object', 'required' => array( 'sheet' ), 'properties' => array(
				'sheet'        => array( 'type' => 'string', 'maxLength' => 24, 'pattern' => '^data\\.s[0-9]{1,12}$' ),
				'display_name' => array( 'type' => 'string', 'maxLength' => 80 ),
				'icon'         => array( 'type' => 'string', 'maxLength' => 20 ),
				'folder'       => array( 'type' => 'string', 'maxLength' => 60 ),
				'archived'     => array( 'type' => 'boolean' ),
			) ),
			'output_schema' => $R::envelope_schema( array( 'sheet_key' => array( 'type' => 'string' ), 'display_name' => array( 'type' => 'string' ), 'archived' => array( 'type' => 'boolean' ) ), array( 'sheet_key' ) ),
			'idempotent'    => true,
			'handler'       => array( __CLASS__, 'mcp_update' ),
			'llm_alias'     => 'twinsheet_update',
			'brain'         => array( 'layer' => 'twin_sheet', 'permission' => 'write', 'crud' => 'write' ),
			'read_only'     => false,
			'required_cap'  => 'edit_posts',
		) ) );

		// @mcp bizcity-mcp-standard@1 tool twinsheet.snapshot
		$R::register( 'twinsheet.snapshot', array_merge( $write, array(
			'title'         => 'Chốt sổ / đánh dấu điểm lưu (checkpoint)',
			'description'   => 'Chốt một kỳ của sổ thành một điểm lưu (checkpoint) có số v1, v2… : ghi số dòng + tổng các cột tiền/số tại thời điểm chốt để so với hiện tại về sau. lock=true KHÓA kỳ: dòng trong kỳ không sửa/huỷ được cho tới khi người dùng mở khóa trên màn Sổ. TRƯỚC KHI gọi hỏi một câu kèm đề xuất: mục 1 = "Chốt kỳ vừa rồi, không khóa", mục 2 = khóa luôn, mục 0 = huỷ. Chốt lại cùng nội dung trả bản cũ. KHÔNG dùng để khôi phục điểm lưu (chỉ làm trên màn Sổ của web, không có công cụ cho trợ lý) và KHÔNG dùng để sửa dòng.',
			'mode'          => 'notebook',
			'input_schema'  => array( 'type' => 'object', 'required' => array( 'sheet', 'period' ), 'properties' => array(
				'sheet'  => array( 'type' => 'string', 'maxLength' => 48, 'description' => 'sheet_key của sổ cần chốt (lấy từ twinsheet.list).' ),
				'period' => array( 'type' => 'string', 'maxLength' => 7, 'description' => 'Kỳ cần chốt: tháng "2026-10", năm "2026" hoặc "all".' ),
				'lock'   => array( 'type' => 'boolean', 'description' => 'true = khóa kỳ sau khi chốt; mặc định false.' ),
				'name'   => array( 'type' => 'string', 'maxLength' => 80, 'description' => 'Tên điểm lưu (tuỳ chọn), ví dụ "Trước khi quyết toán".' ),
			) ),
			'output_schema' => $R::envelope_schema( array( 'version' => array( 'type' => 'integer' ), 'restorable' => array( 'type' => 'boolean' ), 'entry_id' => array( 'type' => 'integer' ), 'ref_code' => array( 'type' => 'string' ), 'period' => array( 'type' => 'string' ), 'row_count' => array( 'type' => 'integer' ), 'totals' => array( 'type' => 'array' ), 'lock' => array( 'type' => 'boolean' ), 'already' => array( 'type' => 'boolean' ) ), array( 'entry_id' ) ),
			'idempotent'    => true,
			'handler'       => array( __CLASS__, 'mcp_snapshot' ),
			'llm_alias'     => 'twinsheet_snapshot',
			'brain'         => array( 'layer' => 'twin_sheet', 'permission' => 'write', 'crud' => 'write' ),
			'read_only'     => false,
			'required_cap'  => 'edit_posts',
		) ) );
	}

	public static function mcp_create( array $args, array $ctx ) {
		// [2026-10-21 11:55 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.123 W4 (126 brain-access) — a book is the owner's / staff's own: the principal comes from the signed ctx, never from an argument
		$denied = self::gate( $ctx );
		if ( null !== $denied ) {
			return $denied;
		}
		return self::mcp_out( BizCity_Twin_Sheet_Service::create_sheet( self::owner( $ctx ), $args ) );
	}

	public static function mcp_update( array $args, array $ctx ) {
		// [2026-10-21 11:55 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.123 W4 (126 brain-access) — a book is the owner's / staff's own: the principal comes from the signed ctx, never from an argument
		$denied = self::gate( $ctx );
		if ( null !== $denied ) {
			return $denied;
		}
		return self::mcp_out( BizCity_Twin_Sheet_Service::update_sheet( self::owner( $ctx ), (string) ( $args['sheet'] ?? '' ), array_diff_key( $args, array( 'sheet' => 1 ) ) ) );
	}

	public static function mcp_list( array $args, array $ctx ) {
		// [2026-10-21 11:55 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.123 W4 (126 brain-access) — a book is the owner's / staff's own: the principal comes from the signed ctx, never from an argument
		$denied = self::gate( $ctx );
		if ( null !== $denied ) {
			return $denied;
		}
		return self::list_sheets( $args, $ctx );
	}

	public static function mcp_describe( array $args, array $ctx ) {
		// [2026-10-21 11:55 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.123 W4 (126 brain-access) — a book is the owner's / staff's own: the principal comes from the signed ctx, never from an argument
		$denied = self::gate( $ctx );
		if ( null !== $denied ) {
			return $denied;
		}
		return self::mcp_out( self::describe( $args, $ctx ) );
	}

	public static function mcp_rows( array $args, array $ctx ) {
		// [2026-10-21 11:55 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.123 W4 (126 brain-access) — a book is the owner's / staff's own: the principal comes from the signed ctx, never from an argument
		$denied = self::gate( $ctx );
		if ( null !== $denied ) {
			return $denied;
		}
		return self::mcp_out( self::rows( $args, $ctx ) );
	}

	public static function mcp_aggregate( array $args, array $ctx ) {
		// [2026-10-21 11:55 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.123 W4 (126 brain-access) — a book is the owner's / staff's own: the principal comes from the signed ctx, never from an argument
		$denied = self::gate( $ctx );
		if ( null !== $denied ) {
			return $denied;
		}
		return self::mcp_out( self::aggregate( $args, $ctx ) );
	}

	/**
	 * [2026-10-22 02:35 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.123 W7 (B2) — filter `bizcity_journal_pre_add` (…, $idem, $entry): a row for a sheet a person made must fit that sheet.
	 * A `data.s<ID>` kind that is not the writer's own sheet ⇒ "not found" (custom sheets belong to their author). Built-in sheets keep
	 * the cell's own checks (unchanged behaviour). An earlier refusal (period locked) passes through untouched.
	 *
	 * @return mixed WP_Error to refuse, else $pass
	 */
	public static function check_journal_card( $pass, $owner, $kind, $date = '', $idem = '', $entry = array() ) {
		if ( is_wp_error( $pass ) || ! is_array( $entry ) || ! BizCity_Twin_Sheet_Registry::is_custom_key( (string) $kind ) ) {
			return $pass;
		}
		$sheet = BizCity_Twin_Sheet_Registry::get( (string) $kind, (int) $owner );
		if ( is_wp_error( $sheet ) ) {
			return $sheet;
		}
		$bad = BizCity_Twin_Sheet_Service::check_card( $sheet, is_array( $entry['card'] ?? null ) ? $entry['card'] : array() );
		return null !== $bad ? $bad : $pass;
	}

	/**
	 * brain-access@1: Twin Sheet tools belong to the person whose book it is (owner) and the staff of that number — a customer, a group member, an automation
	 * or an unknown contact gets "not found", exactly like a row they may not see. No ctx role = a logged-in WordPress user acting on their own book.
	 *
	 * @return WP_Error|null
	 */
	public static function gate( array $ctx ) {
		$type = 'owner';
		if ( class_exists( 'BizCity_Brain_Access', false ) ) {
			$type = (string) BizCity_Brain_Access::principal( $ctx )['type'];
		} elseif ( '' !== (string) ( $ctx['role'] ?? '' ) ) {
			$type = sanitize_key( (string) $ctx['role'] );
		}
		if ( in_array( $type, array( 'owner', 'staff' ), true ) && self::owner( $ctx ) > 0 ) {
			return null;
		}
		return new WP_Error( 'not_found', 'Không tìm thấy sổ này.', array( 'status' => 404, 'reason' => 'access_denied', 'hint' => 'Nhờ chủ sổ thực hiện việc này.', 'help_code' => 'S123-TS-access_denied' ) );
	}

	/** Service error → MCP catalog error (code + HTTP status), message + hint kept, no path / SQL. */
	private static function mcp_out( $res ) {
		if ( ! is_wp_error( $res ) ) {
			return $res;
		}
		$data   = $res->get_error_data();
		$status = is_array( $data ) && ! empty( $data['status'] ) ? (int) $data['status'] : 400;
		$mcp    = class_exists( 'BizCity_MCP_Error' ) && defined( 'BizCity_MCP_Error::QUERY_INVALID' ) ? BizCity_MCP_Error::QUERY_INVALID : 'query_invalid';
		return new WP_Error( $mcp, $res->get_error_message(), array( 'status' => $status, 'reason' => $res->get_error_code(), 'hint' => is_array( $data ) ? (string) ( $data['hint'] ?? '' ) : '' ) );
	}

	// ── REST ─────────────────────────────────────────────────────────

	public static function register_routes(): void {
		register_rest_route( self::NS, '/twinsheets', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'rest_list' ),
			'permission_callback' => array( __CLASS__, 'permission' ),
		) );
		register_rest_route( self::NS, '/twinsheets/' . self::KEY_RE, array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'rest_describe' ),
			'permission_callback' => array( __CLASS__, 'permission' ),
		) );
		register_rest_route( self::NS, '/twinsheets/' . self::KEY_RE . '/rows', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'rest_rows' ),
			'permission_callback' => array( __CLASS__, 'permission' ),
		) );
		register_rest_route( self::NS, '/twinsheets/' . self::KEY_RE . '/aggregate', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'rest_aggregate' ),
			'permission_callback' => array( __CLASS__, 'permission' ),
		) );
		register_rest_route( self::NS, '/twinsheets', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'rest_create' ),
			'permission_callback' => array( __CLASS__, 'permission' ),
		) );
		register_rest_route( self::NS, '/twinsheets/' . self::KEY_RE . '/settings', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'rest_settings' ),
			'permission_callback' => array( __CLASS__, 'permission' ),
		) );
		// [2026-10-19 10:30 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.123 W2 (B9) — the ONE change door: the grid saves its draft buffer here (≤ 100 changes). Web only; the cell keeps writing through journal.record.
		register_rest_route( self::NS, '/twinsheets/' . self::KEY_RE . '/changes', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'rest_changes' ),
			'permission_callback' => array( __CLASS__, 'permission' ),
		) );
		// [2026-10-20 02:05 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.123 W3 (A13) — Chốt sổ: close a period (optionally lock it), list, compare with now, lock / unlock
		register_rest_route( self::NS, '/twinsheets/' . self::KEY_RE . '/snapshot', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'rest_snapshot' ),
			'permission_callback' => array( __CLASS__, 'permission' ),
		) );
		register_rest_route( self::NS, '/twinsheets/' . self::KEY_RE . '/snapshots', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'rest_snapshots' ),
			'permission_callback' => array( __CLASS__, 'permission' ),
		) );
		register_rest_route( self::NS, '/twinsheets/' . self::KEY_RE . '/snapshots/(?P<id>[0-9]{1,12})', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'rest_snapshot_compare' ),
			'permission_callback' => array( __CLASS__, 'permission' ),
		) );
		register_rest_route( self::NS, '/twinsheets/' . self::KEY_RE . '/snapshots/(?P<id>[0-9]{1,12})/lock', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'rest_snapshot_lock' ),
			'permission_callback' => array( __CLASS__, 'permission' ),
		) );
		// [2026-10-21 08:35 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.123 W3 — STRUCTURE of my own sheet: edit columns, list versions, restore one (web only)
		register_rest_route( self::NS, '/twinsheets/' . self::KEY_RE . '/structure', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'rest_structure' ),
			'permission_callback' => array( __CLASS__, 'permission' ),
		) );
		register_rest_route( self::NS, '/twinsheets/' . self::KEY_RE . '/structure/versions', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'rest_structure_versions' ),
			'permission_callback' => array( __CLASS__, 'permission' ),
		) );
		register_rest_route( self::NS, '/twinsheets/' . self::KEY_RE . '/structure/restore', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'rest_structure_restore' ),
			'permission_callback' => array( __CLASS__, 'permission' ),
		) );
		// [2026-10-20 03:45 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.123 W3 — restore a checkpoint (web only: never an assistant tool)
		register_rest_route( self::NS, '/twinsheets/' . self::KEY_RE . '/snapshots/(?P<id>[0-9]{1,12})/restore', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'rest_snapshot_restore' ),
			'permission_callback' => array( __CLASS__, 'permission' ),
		) );
	}

	/** [2026-10-19 11:45 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.123 W3 — make / rename / archive one of the person's own sheets (web). */
	public static function rest_create( WP_REST_Request $request ): WP_REST_Response {
		$body = $request->get_json_params();
		return self::respond( BizCity_Twin_Sheet_Service::create_sheet( (int) get_current_user_id(), is_array( $body ) ? $body : array() ) );
	}

	public static function rest_settings( WP_REST_Request $request ): WP_REST_Response {
		$body = $request->get_json_params();
		return self::respond( BizCity_Twin_Sheet_Service::update_sheet( (int) get_current_user_id(), (string) $request->get_param( 'key' ), is_array( $body ) ? $body : array() ) );
	}

	public static function rest_changes( WP_REST_Request $request ): WP_REST_Response {
		$sheet = BizCity_Twin_Sheet_Registry::get( (string) $request->get_param( 'key' ), (int) get_current_user_id() );
		if ( is_wp_error( $sheet ) ) {
			return self::respond( $sheet );
		}
		$body = $request->get_json_params();
		return self::respond( BizCity_Twin_Sheet_Service::apply_changes( $sheet, is_array( $body ) ? $body : array(), self::rest_ctx() ) );
	}

	// ── Chốt sổ (W3) ──────────────────────────────────────────────

	/** fn(sheet, from, to, owner) ⇒ {entries, truncated} — the journal slice the snapshot reads. */
	private static function scanner(): callable {
		return static function ( array $sheet, string $from, string $to, int $owner ): array {
			$st = self::store();
			return $st instanceof BizCity_Twin_Sheet_Journal_Store ? $st->scan( $sheet, array( 'from' => $from, 'to' => $to ), $owner ) : array( 'entries' => array(), 'truncated' => false );
		};
	}

	/** @param array $args {sheet, period, lock?} */
	public static function snapshot( array $args, array $ctx, ?BizCity_Twin_Sheet_Writer $w = null ) {
		$owner = (int) ( $ctx['owner_user_id'] ?? 0 );
		$sheet = BizCity_Twin_Sheet_Registry::get( (string) ( $args['sheet'] ?? '' ), $owner );
		if ( is_wp_error( $sheet ) ) {
			return $sheet;
		}
		return BizCity_Twin_Sheet_Snapshot::create( $sheet, $args, $ctx, self::scanner(), $w ?? new BizCity_Twin_Sheet_Journal_Writer(), gmdate( 'c' ) );
	}

	public static function snapshots( string $key, array $ctx, ?BizCity_Twin_Sheet_Writer $w = null ) {
		$owner = (int) ( $ctx['owner_user_id'] ?? 0 );
		$sheet = BizCity_Twin_Sheet_Registry::get( $key, $owner );
		return is_wp_error( $sheet ) ? $sheet : array( 'items' => BizCity_Twin_Sheet_Snapshot::listing( $sheet, $owner, $w ?? new BizCity_Twin_Sheet_Journal_Writer() ) );
	}

	public static function rest_snapshot( WP_REST_Request $request ): WP_REST_Response {
		$body = $request->get_json_params();
		return self::respond( self::snapshot( array_merge( is_array( $body ) ? $body : array(), array( 'sheet' => (string) $request->get_param( 'key' ) ) ), self::rest_ctx() ) );
	}

	public static function rest_snapshots( WP_REST_Request $request ): WP_REST_Response {
		return self::respond( self::snapshots( (string) $request->get_param( 'key' ), self::rest_ctx() ) );
	}

	public static function rest_snapshot_compare( WP_REST_Request $request ): WP_REST_Response {
		$ctx   = self::rest_ctx();
		$sheet = BizCity_Twin_Sheet_Registry::get( (string) $request->get_param( 'key' ), (int) $ctx['owner_user_id'] );
		return self::respond( is_wp_error( $sheet ) ? $sheet : BizCity_Twin_Sheet_Snapshot::compare( $sheet, (int) $request->get_param( 'id' ), (int) $ctx['owner_user_id'], self::scanner(), new BizCity_Twin_Sheet_Journal_Writer() ) );
	}

	public static function rest_snapshot_lock( WP_REST_Request $request ): WP_REST_Response {
		$ctx   = self::rest_ctx();
		$sheet = BizCity_Twin_Sheet_Registry::get( (string) $request->get_param( 'key' ), (int) $ctx['owner_user_id'] );
		$body  = $request->get_json_params();
		$body  = is_array( $body ) ? $body : array();
		return self::respond( is_wp_error( $sheet ) ? $sheet : BizCity_Twin_Sheet_Snapshot::set_lock( $sheet, (int) $request->get_param( 'id' ), ! empty( $body['lock'] ), $ctx, new BizCity_Twin_Sheet_Journal_Writer(), (int) ( $body['expected_rev'] ?? 0 ) ) );
	}

	public static function rest_structure( WP_REST_Request $request ): WP_REST_Response {
		$body = $request->get_json_params();
		return self::respond( BizCity_Twin_Sheet_Structure::edit( (int) get_current_user_id(), (string) $request->get_param( 'key' ), is_array( $body ) ? $body : array() ) );
	}

	public static function rest_structure_versions( WP_REST_Request $request ): WP_REST_Response {
		// [2026-10-22 02:35 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.123 W7 (A13) — ?a=<v>&b=<v> compares ANY two stored versions (notes computed by code, FV-6); without them: the list
		$a = (int) $request->get_param( 'a' );
		$b = (int) $request->get_param( 'b' );
		if ( $a > 0 && $b > 0 ) {
			return self::respond( BizCity_Twin_Sheet_Structure::compare( (int) get_current_user_id(), (string) $request->get_param( 'key' ), $a, $b ) );
		}
		return self::respond( BizCity_Twin_Sheet_Structure::versions( (int) get_current_user_id(), (string) $request->get_param( 'key' ) ) );
	}

	public static function rest_structure_restore( WP_REST_Request $request ): WP_REST_Response {
		$body = $request->get_json_params();
		return self::respond( BizCity_Twin_Sheet_Structure::restore_version( (int) get_current_user_id(), (string) $request->get_param( 'key' ), (int) ( is_array( $body ) ? ( $body['v'] ?? 0 ) : 0 ), null, '', (int) ( is_array( $body ) ? ( $body['expected_version'] ?? 0 ) : 0 ) ) );
	}

	/** Body {dry_run?: bool}. Without dry_run it applies the plan, after an automatic checkpoint of the current state. */
	public static function rest_snapshot_restore( WP_REST_Request $request ): WP_REST_Response {
		$ctx   = self::rest_ctx();
		$sheet = BizCity_Twin_Sheet_Registry::get( (string) $request->get_param( 'key' ), (int) $ctx['owner_user_id'] );
		$body  = $request->get_json_params();
		return self::respond( is_wp_error( $sheet ) ? $sheet : BizCity_Twin_Sheet_Snapshot::restore( $sheet, (int) $request->get_param( 'id' ), is_array( $body ) ? $body : array(), $ctx, self::scanner(), new BizCity_Twin_Sheet_Journal_Writer(), gmdate( 'c' ) ) );
	}

	public static function mcp_snapshot( array $args, array $ctx ) {
		// [2026-10-21 11:55 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.123 W4 (126 brain-access) — a book is the owner's / staff's own: the principal comes from the signed ctx, never from an argument
		$denied = self::gate( $ctx );
		if ( null !== $denied ) {
			return $denied;
		}
		return self::mcp_out( self::snapshot( $args, array( 'owner_user_id' => self::owner( $ctx ), 'role' => (string) ( $ctx['role'] ?? 'owner' ) ) ) );
	}

	public static function permission() {
		return is_user_logged_in();
	}

	public static function rest_list( WP_REST_Request $request ): WP_REST_Response {
		return self::respond( self::list_sheets( array(), self::rest_ctx() ) );
	}

	public static function rest_describe( WP_REST_Request $request ): WP_REST_Response {
		return self::respond( self::describe( array( 'sheet' => (string) $request->get_param( 'key' ) ), self::rest_ctx() ) );
	}

	public static function rest_rows( WP_REST_Request $request ): WP_REST_Response {
		return self::respond( self::rows( self::rest_args( $request ), self::rest_ctx() ) );
	}

	public static function rest_aggregate( WP_REST_Request $request ): WP_REST_Response {
		return self::respond( self::aggregate( self::rest_args( $request ), self::rest_ctx() ) );
	}

	/**
	 * Pure: REST query params ⇒ handler args. `f_<field>=v` (PHP turns `f.x` into `f_x`, so the grid sends `f_x`) ⇒ where eq,
	 * `f_<field>=a,b` ⇒ in; `sort=amount:desc,doc_date:asc`; `where` may also arrive as a JSON string (the MCP shape).
	 */
	public static function args_from_params( string $key, array $params ): array {
		$args = array( 'sheet' => $key );
		foreach ( array( 'view', 'period', 'from', 'to', 'q', 'limit', 'offset', 'fn', 'field', 'group_by', 'split_by', 'group' ) as $k ) {
			if ( isset( $params[ $k ] ) && is_scalar( $params[ $k ] ) && '' !== (string) $params[ $k ] ) {
				$args[ $k ] = (string) $params[ $k ];
			}
		}
		if ( ! empty( $params['headline'] ) ) {
			$args['headline'] = true;
		}
		if ( ! empty( $params['include_void'] ) ) {
			$args['include_void'] = true;
		}
		$where = array();
		if ( is_string( $params['where'] ?? null ) && '' !== $params['where'] ) {
			$decoded = json_decode( $params['where'], true );
			if ( is_array( $decoded ) ) {
				$where = $decoded;
			}
		}
		foreach ( $params as $k => $v ) {
			if ( 0 === strpos( (string) $k, 'f_' ) && is_scalar( $v ) && '' !== (string) $v ) {
				$field = substr( (string) $k, 2 );
				// [2026-10-22 03:10 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.123 W8 (A3) — `f_x=~chữ` ⇒ contains, `f_x=a..b` ⇒ between (either side may be empty); the schema still allows only declared filter columns
				if ( 0 === strpos( (string) $v, '~' ) ) {
					$where[] = array( 'field' => $field, 'op' => 'contains', 'value' => substr( (string) $v, 1 ) );
					continue;
				}
				if ( false !== strpos( (string) $v, '..' ) ) {
					$pair    = explode( '..', (string) $v, 2 );
					$where[] = array( 'field' => $field, 'op' => 'between', 'value' => array( trim( $pair[0] ), trim( $pair[1] ) ) );
					continue;
				}
				$vals  = array_values( array_filter( array_map( 'trim', explode( ',', (string) $v ) ), 'strlen' ) );
				$where[] = count( $vals ) > 1 ? array( 'field' => $field, 'op' => 'in', 'value' => $vals ) : array( 'field' => $field, 'op' => 'eq', 'value' => $vals[0] ?? '' );
			}
		}
		if ( array() !== $where ) {
			$args['where'] = $where;
		}
		if ( is_string( $params['sort'] ?? null ) && '' !== $params['sort'] ) {
			$sort = array();
			foreach ( array_slice( explode( ',', $params['sort'] ), 0, 3 ) as $part ) {
				$bits = explode( ':', trim( $part ), 2 );
				if ( '' !== $bits[0] ) {
					$sort[] = array( 'field' => $bits[0], 'dir' => $bits[1] ?? 'desc' );
				}
			}
			$args['sort'] = $sort;
		}
		return $args;
	}

	private static function rest_args( WP_REST_Request $request ): array {
		return self::args_from_params( (string) $request->get_param( 'key' ), (array) $request->get_query_params() );
	}

	private static function rest_ctx(): array {
		return array( 'owner_user_id' => get_current_user_id(), 'role' => current_user_can( 'manage_options' ) ? 'owner' : 'staff' );
	}

	/** @param array|WP_Error $res */
	private static function respond( $res ): WP_REST_Response {
		if ( is_wp_error( $res ) ) {
			$data   = $res->get_error_data();
			$status = is_array( $data ) && ! empty( $data['status'] ) ? (int) $data['status'] : 400;
			return new WP_REST_Response( array(
				'code'      => $res->get_error_code(),
				'message'   => $res->get_error_message(),
				'hint'      => is_array( $data ) ? (string) ( $data['hint'] ?? '' ) : '',
				'help_code' => is_array( $data ) && ! empty( $data['help_code'] ) ? (string) $data['help_code'] : 'S123-TS-' . $status,
			), $status );
		}
		return new WP_REST_Response( $res, 200 );
	}

	// ── shared ───────────────────────────────────────────────────────

	private static function owner( array $ctx ): int {
		return (int) ( $ctx['owner_user_id'] ?? $ctx['user_id'] ?? 0 );
	}

	/** Site-local today (a "tháng này" question means the site's month, not UTC's). */
	public static function today(): string {
		return function_exists( 'current_time' ) ? (string) current_time( 'Y-m-d' ) : gmdate( 'Y-m-d' );
	}
}
