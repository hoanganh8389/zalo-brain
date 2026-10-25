<?php
/**
 * BizCity_Journal_MCP_Tools — `journal.*` + `track.*` (PHASE-0.100 S100-L19, lanes/INTERFACES.md I-5, doc 92 §6).
 *
 * | Tool | Kind | Who | Confirm |
 * |---|---|---|---|
 * | journal.find / journal.get / journal.summarize | read | owner, staff | never |
 * | journal.file | write, idempotent | owner, staff | never |
 * | journal.derive | write | owner, staff | always + preview |
 * | journal.record / journal.mirror / journal.void | write, idempotent — CELL-system tools (the model never sees them), PHASE-0.117 S117-K1a / K2b | owner, staff | never |
 * | track.get | read | owner, staff | never |
 * | track.set | write, idempotent (upsert) | owner, staff | never |
 *
 * Mode `notebook`. No model, no cell call here: handlers delegate to `BizCity_Journal_Index` /
 * `BizCity_Journal_Filer` and translate WP_Error into the MCP catalog. `journal.summarize` is deterministic
 * (sum/avg/count over a numeric card field, grouped by day/week/month) — never a model call (R-JOURNAL R-JN-5).
 *
 * Biz Central Brain — Johnny Chu (Chu Hoàng Anh).
 *
 * [2026-10-12 11:50 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.100 S100-L19 — new file.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\MCP
 */

defined( 'ABSPATH' ) || exit;

// [2026-10-24 12:30 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.128 — ROOT CAUSE of tranghana.vn "MCP_TOOL_NOT_FOUND" (group.files.save, journal.record): PHP binds this
// non-inheriting class EARLY (at compile time of this very file), so a bare class_exists() here was already true ⇒ return ⇒ init() at the
// bottom never ran ⇒ none of this file's tools were ever registered (wp eval: class loaded, hooked=false). Guard DROPPED (same fix as context-producers
// 2026-10-12): the loader's require_once already prevents a second load. Pinned by tests/unit/McpToolsFreshLoadS128Test.php.

final class BizCity_Journal_MCP_Tools {

	const SINCE = '0.100.0';

	private static $hooked = false;

	// [2026-10-24 12:33 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.131 S131-L1 — the door state of the journal.* family (brain-api@1 doc 20 §6): null until register_tools() ran.
	/** @var array|null {journal: ready|missing, reason?, count?, as_of} */
	private static $door = null;

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

	/**
	 * [2026-10-24 12:33 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.131 S131-L1 (doc 20 §6) — the flag `tools/list` (_meta.bizcity.brain) and `wp bizcity health` (journal_tools) read:
	 * {journal: "ready"|"missing", reason?, count?, since, as_of}. `missing` + `register_not_run` when nobody fired the register action yet (pure read, no DB).
	 */
	public static function door_state(): array {
		$state = is_array( self::$door ) ? self::$door : array( 'journal' => 'missing', 'reason' => 'register_not_run', 'as_of' => gmdate( 'c' ) );
		$state['since'] = self::SINCE;
		return $state;
	}

	/** S131-L1 — an early return of register_tools() must be heard: door state + ONE JSONL warn line (R-CH-FILE-LOG; reason only, no PII). */
	private static function door_missing( string $reason ): void {
		self::$door = array( 'journal' => 'missing', 'reason' => $reason, 'as_of' => gmdate( 'c' ) );
		if ( ! class_exists( 'BizCity_Channel_File_Logger' ) ) {
			return;
		}
		try {
			// the generic gateway channel: `mcp` has no channel contract of its own (contract_id() would refuse the line) and this channel needs no account scope
			BizCity_Channel_File_Logger::write( BizCity_Channel_File_Logger::CH_CHANNEL_GATEWAY, BizCity_Channel_File_Logger::LEVEL_WARN, 'journal_tools_skipped', 'journal.* not registered', array( 'reason' => $reason, 'module' => 'core/mcp' ) );
		} catch ( Throwable $t ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// a logger failure never breaks tool registration
		}
	}

	public static function register_tools(): void {
		if ( ! class_exists( 'BizCity_MCP_Tool_Registry' ) || ! class_exists( 'BizCity_Journal_Index', false ) ) {
			// [2026-10-24 12:33 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.131 S131-L1 — record WHY (registry_absent | journal_index_absent) for tools/list + health, and one JSONL warn line (case 2026-10-23, site 100001 stayed silent).
			self::door_missing( class_exists( 'BizCity_MCP_Tool_Registry' ) ? 'journal_index_absent' : 'registry_absent' );
			// [2026-10-24 12:28 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.128 — the owner class was not loaded yet when the registry booted ⇒ one retry at init (late), and a log line so the case is visible.
			if ( function_exists( 'add_action' ) && function_exists( 'did_action' ) && ! did_action( 'init' ) && ! has_action( 'init', array( __CLASS__, 'register_tools' ) ) ) {
				add_action( 'init', array( __CLASS__, 'register_tools' ), 99 );
			} else {
				error_log( '[bizcity-mcp] BizCity_Journal_MCP_Tools skipped: ' . ( class_exists( 'BizCity_MCP_Tool_Registry' ) ? 'BizCity_Journal_Index not loaded' : 'registry not loaded' ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
			return;
		}
		$R = 'BizCity_MCP_Tool_Registry';

		$entry_props = array(
			'entry_id'   => array( 'type' => 'integer' ),
			'kind'       => array( 'type' => 'string' ),
			'title'      => array( 'type' => 'string' ),
			'doc_date'   => array( 'type' => array( 'string', 'null' ) ),
			'subject'    => array( 'type' => 'object' ),
			'tags'       => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
			'card'       => array( 'type' => 'object' ),
			'confidence' => array( 'type' => 'number' ),
			'flags'      => array( 'type' => 'object' ),
		);

		// @mcp bizcity-mcp-standard@1 tool journal.find
		$R::register( 'journal.find', array(
			'title'          => 'Tìm trong sổ ghi chép',
			// [2026-10-21 11:58 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.126 ACI (doc 96 F-1/F-2/F-3) — written like API docs for the model: purpose, when NOT to use it, every
			// parameter with an example, the edge cases; `ref` reads one row by the code a person says; `q` is word by word over title + summary.
			'description'    => 'Tìm các DÒNG trong sổ của chủ (tài liệu đã lưu, hợp đồng, thu chi, công nợ, việc JB-, notebook NB-, kỹ năng KN-, sản phẩm đã làm…). '
				. 'Trả danh sách dòng: mã (ref_code như TL-47, JB-31), loại, tiêu đề, ngày, trạng thái, thẻ tóm tắt. '
				. 'Dùng khi: hỏi "có gì về…", "hợp đồng thuê tới đâu", "đã gửi gì cho…", hoặc khi có một mã (dùng `ref`). '
				. 'KHÔNG dùng để đọc NỘI DUNG bên trong tài liệu (dùng notebook_search / knowledge.search) và không tính tổng tiền (dùng journal.aggregate). '
				. 'Mẹo: bắt đầu rộng (chỉ q hoặc layer), rồi thu hẹp; không biết loại chính xác thì bỏ trống `kind`. Không có kết quả ⇒ total=0, thử bỏ bớt bộ lọc trước khi kết luận "không có".',
			'input_schema'   => array( 'type' => 'object', 'properties' => array(
				'ref'          => array( 'type' => 'string', 'maxLength' => 16, 'pattern' => '^\\s*[A-Za-z]{2}-[0-9]{1,12}\\s*$', 'description' => 'Một mã người dùng nói, vd "TL-47" (file), "JB-31" (việc), "NB-12" (notebook), "TC-8" (thu chi). Có ref thì các bộ lọc khác bị bỏ qua; trả 0 hoặc 1 dòng.' ),
				'q'            => array( 'type' => 'string', 'maxLength' => 120, 'description' => 'Chữ tìm, từng từ phải có trong tiêu đề hoặc tóm tắt (không phân biệt thứ tự). Vd "hợp đồng thuê Cty A". Không phải tìm theo nghĩa.' ),
				'layer'        => array( 'type' => 'string', 'enum' => array( 'document', 'notebook', 'twin_sheet', 'skill', 'job', 'output' ), 'description' => 'Lớp: document = tài liệu/file; notebook = notebook (NB-); twin_sheet = các sổ (thu chi, công nợ, tiến độ, việc cần làm, sổ tự đặt SO-); skill = kỹ năng (KN-); job = việc nhiều bước (JB-); output = sản phẩm trợ lý đã làm/gửi.' ),
				'kind'         => array( 'type' => 'string', 'maxLength' => 48, 'description' => 'Một loại chính xác dạng "họ.loại", vd "biz.contract" (hợp đồng), "fin.cash" (thu chi), "common.document". Không chắc thì bỏ trống, dùng layer hoặc q.' ),
				'kinds'        => array( 'type' => 'array', 'items' => array( 'type' => 'string', 'maxLength' => 48 ), 'maxItems' => 10, 'description' => 'Nhiều loại cùng lúc, vd ["fin.cash","fin.debt"].' ),
				'kind_prefix'  => array( 'type' => 'string', 'maxLength' => 32, 'description' => 'Cả một họ loại, vd "fin." (mọi sổ tài chính), "biz.".' ),
				'job'          => array( 'type' => 'string', 'maxLength' => 48, 'pattern' => '^(JB-[0-9]{1,12}|job_[0-9A-Za-z]{10,40})$', 'description' => 'Mọi dòng của MỘT việc: mã "JB-31" (hoặc mã job_… của cell). Gồm dòng đầu việc + tài liệu đầu vào + sản phẩm + bằng chứng.' ),
				'tags'         => array( 'type' => 'array', 'items' => array( 'type' => 'string', 'maxLength' => 40 ), 'maxItems' => 5, 'description' => 'Nhãn chữ thường không dấu, vd ["thue-kho"]; mọi nhãn phải khớp.' ),
				'subject_hash' => array( 'type' => 'string', 'maxLength' => 18, 'description' => 'Mã người/đối tượng lấy từ một dòng đã có (trường subject) hoặc gói chỉ mục. KHÔNG tự tạo.' ),
				'from'         => array( 'type' => 'string', 'maxLength' => 10, 'description' => 'Từ ngày tài liệu, dạng YYYY-MM-DD (vd 2026-10-01).' ),
				'to'           => array( 'type' => 'string', 'maxLength' => 10, 'description' => 'Đến ngày tài liệu, dạng YYYY-MM-DD.' ),
				'limit'        => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 20, 'description' => 'Số dòng mỗi trang (mặc định 20). Còn nữa khi total > offset + số dòng.' ),
				'offset'       => array( 'type' => 'integer', 'minimum' => 0, 'description' => 'Bỏ qua bao nhiêu dòng (trang sau = offset + limit).' ),
				// [2026-10-20 03:30 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.125 Q1 — the row of ONE cell output (a background job proves its product by it, D125-7)
				'output_id'    => array( 'type' => 'string', 'pattern' => '^co_[a-f0-9]{24}$', 'description' => 'Hệ thống: dòng của một sản phẩm cell (co_…). Mô hình không cần dùng.' ),
				// [2026-10-24 05:47 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.133 S133-Ma (journal.find v2, doc 81 §4) — phrases + person + has; results then carry `why` and come by score
				'terms'        => array( 'type' => 'array', 'items' => array( 'type' => 'string', 'maxLength' => 60 ), 'maxItems' => 12, 'description' => 'Các cụm cần tìm (đã mở rộng đồng nghĩa), vd ["nha truong","truong hoc","thong bao"]. Tìm cả trong chữ đọc được trên ảnh, mô tả, từ khoá, người. Có dấu hay không dấu đều được. Kết quả xếp theo điểm, mỗi dòng có `why` (khớp ở đâu).' ),
				'match'        => array( 'type' => 'string', 'enum' => array( 'all', 'any' ), 'description' => 'all (mặc định) = dòng phải khớp mọi cụm; any = khớp một cụm là đủ (dùng khi đã liệt kê đồng nghĩa).' ),
				'person'       => array( 'type' => 'object', 'properties' => array( 'name' => array( 'type' => 'string', 'maxLength' => 80 ), 'contact_ref' => array( 'type' => 'string', 'maxLength' => 40 ) ), 'description' => 'Dòng có nhắc tới người này, vd {"name":"anh Minh"} (bỏ anh/chị/cô… khi so).' ),
				'has'          => array( 'type' => 'array', 'items' => array( 'type' => 'string', 'enum' => array( 'ocr', 'about', 'people', 'keywords' ) ), 'maxItems' => 4, 'description' => 'Chỉ dòng đã có phần này, vd ["ocr"] = ảnh đã đọc được chữ.' ),
				'source_uuid'  => array( 'type' => 'string', 'pattern' => '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$', 'description' => 'Hệ thống: mọi dòng (mọi bản) của MỘT nguồn (resource.source_uuid). Mô hình không cần dùng.' ),
				// [2026-10-24 09:48 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.133 S133-Mb — the cell's re-read sweep
				'meta_status'  => array( 'type' => 'string', 'enum' => array( 'pending', 'failed', 'none', 'done' ), 'description' => 'Hệ thống: dòng theo trạng thái phần đọc được (pending = chờ đọc / đọc lại, none = chưa từng đọc). Mô hình không cần dùng.' ),
				'has_file'     => array( 'type' => 'boolean', 'description' => 'Hệ thống: chỉ dòng có tệp gốc.' ),
				'folder'       => array( 'type' => 'string', 'maxLength' => 40, 'pattern' => '^[0-9]{2}(/[a-z0-9-]{1,40})?$', 'description' => 'Thư mục Kho tri thức (vd "30" hoặc "30/lop-tieng-trung"); chỉ thu hẹp.' ),
				'fuse'         => array( 'type' => 'boolean', 'description' => 'Hệ thống: kèm `terms` ⇒ gộp thêm ghi chú thư viện của Kho tri thức (vault) vào một danh sách xếp hạng chung (`fused`, `coverage`); `items` giữ nguyên.' ), // [2026-10-25 12:39 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.135 S-3
				// [2026-10-24 12:33 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.131 S131-L3 — context-scope@1: layers / scope job / scope group narrow the page; absent ⇒ unchanged
				'context_scope' => self::context_scope_schema(),
			) ),
			'output_schema'  => $R::envelope_schema( array( 'items' => array( 'type' => 'array', 'items' => array( 'type' => 'object', 'properties' => $entry_props ) ), 'total' => array( 'type' => 'integer' ) ), array( 'items', 'total' ) ),
			'read_only'      => true,
			'idempotent'     => true,
			'required_scope' => 'brain.read',
			'mode'           => 'notebook', // PHASE-0.135: kept inside the validator's 45-line marker window (the schema grew by `fuse`)
			'handler'        => array( __CLASS__, 'find_mcp' ),
			'roles'          => array( 'owner', 'staff' ),
			'scopes'         => array( 'brain.read' ),
			'confirm'        => 'never',
			'llm_alias'      => 'journal_find',
			// [2026-10-22 01:07 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.126 S126-M1 (knowledge-tools@1) — L1 journal tool: the permission it needs from brain-access@1
			'brain'          => array( 'tier' => 'L1', 'permission' => 'meta.read', 'crud' => 'read', 'answer_hint' => 'instant' ),
			'fallback_pack'  => 'doc_index',
			'since'          => self::SINCE,
		) );

		// @mcp bizcity-mcp-standard@1 tool journal.get
		$R::register( 'journal.get', array(
			'title'          => 'Đọc một mục nhật ký',
			// [2026-10-21 11:58 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.126 ACI (doc 96 F-2) — read by the code the person says (`ref`), not only by the database id
			'description'    => 'Đọc đủ MỘT dòng trong sổ: thẻ đầy đủ (mọi trường đã trích), nguồn gốc (loại file, kích thước), thuộc notebook / nhóm / liên hệ / việc nào, bản thứ mấy. '
				. 'Gọi bằng mã người dùng nói, dạng 2 chữ cái + số: ref="TL-47" (file), "JB-31" (việc), "NB-12", "KN-9", "TC-8"…; hoặc entry_id lấy từ kết quả journal.find. '
				. 'Không trả link tải và không trả toàn văn tài liệu (toàn văn: notebook_search / read_file). Không thấy hoặc không có quyền ⇒ journal_not_found (dùng journal.find q=… để tìm mã đúng).',
			'input_schema'   => array( 'type' => 'object', 'properties' => array(
				'ref'      => array( 'type' => 'string', 'maxLength' => 16, 'pattern' => '^\\s*[A-Za-z]{2}-[0-9]{1,12}\\s*$', 'description' => 'Mã dòng, vd "TL-47", "JB-31". Ưu tiên dùng cái này.' ),
				'entry_id' => array( 'type' => 'integer', 'minimum' => 1, 'description' => 'Số entry_id lấy từ kết quả journal.find (khi không có mã).' ),
				// [2026-10-24 05:47 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.133 S133-Ma (doc 81 §7) — view=card: the read shape every UI renders (labels in Vietnamese, internal keys hidden)
				'view'     => array( 'type' => 'string', 'enum' => array( 'row', 'card' ), 'description' => 'row (mặc định) = dòng đầy đủ; card = thẻ đọc sẵn (mô tả, chữ trên ảnh, trường có nhãn tiếng Việt, người, nguồn, việc liên quan).' ),
				// [2026-10-24 12:33 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.131 S131-L3 — context-scope@1: version "<root_ref>@<n>" picks that version; layers / group only narrow
				'context_scope' => self::context_scope_schema(),
			) ),
			'output_schema'  => $R::envelope_schema( $entry_props, array( 'entry_id', 'kind' ) ),
			'read_only'      => true,
			'idempotent'     => true,
			'required_scope' => 'brain.read',
			'handler'        => array( __CLASS__, 'get_mcp' ),
			'mode'           => 'notebook',
			'roles'          => array( 'owner', 'staff' ),
			'scopes'         => array( 'brain.read' ),
			'confirm'        => 'never',
			'llm_alias'      => 'journal_get',
			'brain'          => array( 'tier' => 'L1', 'permission' => 'content.read', 'crud' => 'read', 'answer_hint' => 'instant' ),
			'fallback_pack'  => null,
			'since'          => self::SINCE,
		) );

		self::register_133_tools( $R, $entry_props );

		// @mcp bizcity-mcp-standard@1 tool journal.file
		$R::register( 'journal.file', array(
			'title'          => 'Sửa loại / tag / người của một mục',
			// [2026-10-21 12:59 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.126 ACI (doc 96 P-7) — API-style doc; a code (`ref`) works like on journal.find / journal.get
			'description'    => 'Sắp xếp lại MỘT dòng đã có trong sổ của chủ: đổi loại, nhãn, ngày tài liệu, ghim, đánh dấu đã xem, gắn với một liên hệ CRM. Dữ liệu của chính chủ, không cần xác nhận. '
				. 'Gọi bằng ref (vd "TL-47") hoặc entry_id; chỉ gửi trường cần đổi. KHÔNG dùng để sửa nội dung / số tiền của tài liệu (bản gốc không bao giờ bị sửa) và không dùng để xoá.',
			'input_schema'   => array( 'type' => 'object', 'properties' => array(
				'ref'          => array( 'type' => 'string', 'maxLength' => 16, 'pattern' => '^\\s*[A-Za-z]{2}-[0-9]{1,12}\\s*$', 'description' => 'Mã dòng người dùng nói, vd "TL-47", "JE-12". Ưu tiên dùng cái này.' ),
				'entry_id'     => array( 'type' => 'integer', 'minimum' => 1, 'description' => 'Số entry_id từ kết quả journal.find (khi không có mã).' ),
				'kind'         => array( 'type' => 'string', 'maxLength' => 48, 'description' => 'Loại mới dạng "họ.loại", vd "biz.contract", "common.receipt".' ),
				'tags'         => array( 'type' => 'array', 'items' => array( 'type' => 'string', 'maxLength' => 40 ), 'maxItems' => 12, 'description' => 'Bộ nhãn MỚI (thay bộ cũ), chữ thường không dấu, vd ["thue-kho","cty-a"]. Nhãn nối nhóm / việc được giữ tự động.' ),
				'subject_hash' => array( 'type' => 'string', 'maxLength' => 18, 'description' => 'Mã người/đối tượng lấy từ một dòng khác. KHÔNG tự tạo.' ),
				'doc_date'     => array( 'type' => 'string', 'maxLength' => 10, 'description' => 'Ngày của tài liệu, YYYY-MM-DD (vd 2026-10-21).' ),
				'pinned'       => array( 'type' => 'boolean', 'description' => 'true = ghim lên đầu sổ.' ),
				'review'       => array( 'type' => 'boolean', 'description' => 'true = đánh dấu cần chủ xem lại; false = đã xem.' ),
				// [2026-10-19 11:40 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.121 R-CONTACT-ID — gắn tài liệu cho một liên hệ CRM (0 = bỏ liên kết). Site kiểm liên hệ có tồn tại và người gọi được xem; id do model đưa không được tin.
				'contact_id'   => array( 'type' => 'integer', 'minimum' => 0, 'description' => 'Id liên hệ CRM để gắn tài liệu (0 = bỏ gắn). Lấy từ công cụ tra khách, không đoán.' ),
			) ),
			'output_schema'  => $R::envelope_schema( $entry_props, array( 'entry_id', 'kind' ) ),
			'read_only'      => false,
			'destructive'    => false,
			'idempotent'     => true,
			'required_scope' => 'memory.write',
			'required_cap'   => 'edit_posts',
			'handler'        => array( __CLASS__, 'file' ),
			'mode'           => 'notebook',
			'roles'          => array( 'owner', 'staff' ),
			'scopes'         => array( 'memory.write' ),
			'confirm'        => 'never',
			'llm_alias'      => 'journal_file',
			'brain'          => array( 'tier' => 'L1', 'permission' => 'write', 'crud' => 'write', 'answer_hint' => 'instant' ),
			'fallback_pack'  => null,
			'since'          => self::SINCE,
		) );

		// [2026-10-17 08:13 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.117 S117-K1a (R-JN-15, doc 66 §4) — the door the CELL uses to put a Job's business result in the journal BEFORE it writes the Google Sheet mirror.
		// @mcp bizcity-mcp-standard@1 tool journal.record
		$R::register( 'journal.record', array(
			'title'          => 'Ghi kết quả việc vào sổ (journal-first)',
			'description'    => 'Việc của trợ lý (vd ghi sổ thu chi) ghi MỖI khoản một dòng vào sổ TRƯỚC khi ghi bảng tính Google (bảng tính chỉ là gương). Idempotent theo khoá job:<mã>; loại phải được khai báo; luôn state captured, không bao giờ verified. Công cụ hệ thống của cell — mô hình không gọi.',
			'input_schema'   => array( 'type' => 'object', 'required' => array( 'entries' ), 'properties' => array(
				'entries' => array( 'type' => 'array', 'minItems' => 1, 'maxItems' => 20, 'items' => array( 'type' => 'object', 'required' => array( 'kind', 'idempotency_key' ), 'properties' => array(
					'kind'            => array( 'type' => 'string', 'maxLength' => 48 ),
					'title'           => array( 'type' => 'string', 'maxLength' => 255 ),
					'summary'         => array( 'type' => 'string', 'maxLength' => 600 ),
					'doc_date'        => array( 'type' => 'string', 'maxLength' => 10 ),
					'card'            => array( 'type' => 'object' ),
					'source_ref'      => array( 'type' => 'string', 'maxLength' => 120 ),
					// [2026-10-24 12:33 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.131 S131-L4 — ":" allowed inside: a step key job:job_<id>:<step> (never leading / trailing)
					'idempotency_key' => array( 'type' => 'string', 'maxLength' => 110, 'pattern' => '^job:[A-Za-z0-9_.-](?:[A-Za-z0-9_.:-]{1,98}[A-Za-z0-9_.-])?$' ),
					// [2026-10-18 02:15 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.121 S121-M (doc 80 §3) — meeting cover / action items
					'tags'            => array( 'type' => 'array', 'maxItems' => 10, 'items' => array( 'type' => 'string', 'maxLength' => 60 ) ),
					'derived_from'    => array( 'type' => 'array', 'maxItems' => 30, 'items' => array( 'type' => 'integer', 'minimum' => 1 ) ),
					'origin'          => array( 'type' => 'object' ),
					// [2026-10-21 10:37 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.126 W4 (BS-7a, doc 90 R120-02/05) — the people a product row was DELIVERED to (h:/p: + 16 hex, never a phone): they, and nobody else by delivery, may fetch it
					'delivered_to'    => array( 'type' => 'array', 'maxItems' => 20, 'items' => array( 'type' => 'string', 'pattern' => '^[hp]:[0-9a-f]{16}$' ) ),
					// [2026-10-20 10:53 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.126 S126-J3 (job-link@1, BS-6) — the job this row is written inside: the site opens / finds the JB- header, stamps job_ref + tag, appends the row's code to the header's output_refs
					'job'             => array( 'type' => 'object', 'required' => array( 'job_id' ), 'properties' => array(
						'job_id'      => array( 'type' => 'string', 'pattern' => '^job_[0-9A-Za-z]{10,40}$' ),
						'role'        => array( 'type' => 'string', 'enum' => array( 'input', 'output', 'evidence', 'lesson' ) ),
						'step_ref'    => array( 'type' => 'string', 'maxLength' => 40 ),
						'version_ref' => array( 'type' => 'string', 'maxLength' => 24 ),
						'actor'       => array( 'type' => 'object' ),
						// [2026-10-20 11:33 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.126 S126-J4 — only for a job that sends no job-event (a saved meeting): closes its own header
						'status'      => array( 'type' => 'string', 'enum' => array( 'running', 'done', 'failed' ) ),
						'goal'        => array( 'type' => 'string', 'maxLength' => 200 ),
					) ),
				) ) ),
			) ),
			'output_schema'  => $R::envelope_schema( array(
				'entries' => array( 'type' => 'array' ),
			), array( 'entries' ) ),
			'read_only'      => false,
			'destructive'    => false,
			'idempotent'     => true,
			'required_scope' => 'memory.write',
			'required_cap'   => 'edit_posts',
			'handler'        => array( __CLASS__, 'record' ),
			'mode'           => 'notebook',
			'roles'          => array( 'owner', 'staff' ),
			'scopes'         => array( 'memory.write' ),
			'confirm'        => 'never',
			'llm_alias'      => 'journal_record',
			'brain'          => array( 'tier' => 'L1', 'permission' => 'write', 'crud' => 'write', 'answer_hint' => 'instant' ),
			'fallback_pack'  => null,
			'since'          => self::SINCE,
		) );

		// @mcp bizcity-mcp-standard@1 tool journal.mirror
		$R::register( 'journal.mirror', array(
			'title'          => 'Báo gương bảng tính của dòng sổ',
			'description'    => 'Sau khi cell ghi bảng tính Google và đọc lại: báo các dòng sổ (khoá job:<mã>) đã có gương ok (kèm dải ô) hay failed (kèm mã lỗi). Chỉ đổi metadata.mirror, không đổi trạng thái, không bao giờ verified. Công cụ hệ thống của cell.',
			// [2026-10-18 01:35 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.121 A2-3 / B2-3 (I-8) — the document register's mirror reports by entry_ids (its rows were made by the site, they carry no job key)
			'input_schema'   => array( 'type' => 'object', 'required' => array( 'sheet' ), 'properties' => array(
				'idempotency_keys' => array( 'type' => 'array', 'minItems' => 1, 'maxItems' => 20, 'items' => array( 'type' => 'string', 'maxLength' => 110 ) ),
				'entry_ids'        => array( 'type' => 'array', 'minItems' => 1, 'maxItems' => 20, 'items' => array( 'type' => 'integer', 'minimum' => 1 ) ),
				'sheet'            => array( 'type' => 'string', 'enum' => array( 'ok', 'failed' ) ),
				'row_ref'          => array( 'type' => 'string', 'maxLength' => 120 ),
				'error_code'       => array( 'type' => 'string', 'maxLength' => 40 ),
			) ),
			'output_schema'  => $R::envelope_schema( array(
				'updated' => array( 'type' => 'integer' ),
			), array( 'updated' ) ),
			'read_only'      => false,
			'destructive'    => false,
			'idempotent'     => true,
			'required_scope' => 'memory.write',
			'required_cap'   => 'edit_posts',
			'handler'        => array( __CLASS__, 'mirror' ),
			'mode'           => 'notebook',
			'roles'          => array( 'owner', 'staff' ),
			'scopes'         => array( 'memory.write' ),
			'confirm'        => 'never',
			'llm_alias'      => 'journal_mirror',
			'brain'          => array( 'tier' => 'L1', 'permission' => 'write', 'crud' => 'write', 'answer_hint' => 'instant' ),
			'fallback_pack'  => null,
			'since'          => self::SINCE,
		) );

		// [2026-10-18 03:59 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.117 S117-K2b (R-JOURNAL v1.4 R-JN-25, Q117-K2-3) — "hủy TC-1234": the row stays, state void. The writer voids own rows; the number owner voids any row of the number.
		// @mcp bizcity-mcp-standard@1 tool journal.void
		$R::register( 'journal.void', array(
			'title'          => 'Hủy một dòng sổ (giữ lịch sử)',
			'description'    => 'Hủy một dòng sổ theo mã gọi lại (TC-… thu chi, CN-… công nợ, TD-… tiến độ, VC-… việc, KH-… kế hoạch) hoặc khoá job:<mã>: dòng GIỮ LẠI, trạng thái void (không xoá, không sửa số). Công cụ hệ thống của cell — mô hình không gọi.',
			'input_schema'   => array( 'type' => 'object', 'properties' => array(
				'ref_code'        => array( 'type' => 'string', 'maxLength' => 24, 'pattern' => '^(TC|CN|TD|VC|KH)-[0-9]{1,12}$' ),
				'idempotency_key' => array( 'type' => 'string', 'maxLength' => 110 ),
				'reason'          => array( 'type' => 'string', 'maxLength' => 200 ),
			) ),
			'output_schema'  => $R::envelope_schema( array(
				'ref_code' => array( 'type' => 'string' ),
				'state'    => array( 'type' => 'string' ),
				'already'  => array( 'type' => 'boolean' ),
			), array( 'state' ) ),
			'read_only'      => false,
			'destructive'    => false,
			'idempotent'     => true,
			'required_scope' => 'memory.write',
			'required_cap'   => 'edit_posts',
			'handler'        => array( __CLASS__, 'void_row' ),
			'mode'           => 'notebook',
			'roles'          => array( 'owner', 'staff' ),
			'scopes'         => array( 'memory.write' ),
			'confirm'        => 'never',
			'llm_alias'      => 'journal_void',
			'brain'          => array( 'tier' => 'L1', 'permission' => 'write', 'crud' => 'state', 'answer_hint' => 'instant' ),
			'fallback_pack'  => null,
			'since'          => self::SINCE,
		) );

		// [2026-10-20 11:52 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.126 W4 (BS-7a, brain-access@1 file.send, doc 90 R112-04 / R120-02) — the cell reports "this row was delivered to that person":
		// the SITE decides file.send for the caller on that row, then records the recipient in access.delivered_to (that person, and nobody else by delivery, may fetch it later).
		// @mcp bizcity-mcp-standard@1 tool journal.deliver
		$R::register( 'journal.deliver', array(
			'title'          => 'Ghi người nhận của một tài liệu / sản phẩm đã gửi',
			'description'    => 'Sau khi gửi một tài liệu hay sản phẩm (mã TL-… / JE-…) cho một người khác: site kiểm quyền gửi của người yêu cầu trên dòng đó rồi ghi người nhận (h:/p: + 16 hex, không bao giờ số điện thoại). Công cụ hệ thống của cell — mô hình không gọi.',
			'input_schema'   => array( 'type' => 'object', 'required' => array( 'ref_code', 'recipient_ref' ), 'properties' => array(
				'ref_code'      => array( 'type' => 'string', 'maxLength' => 24, 'pattern' => '^(TL|JE)-[0-9]{1,12}$' ),
				'recipient_ref' => array( 'type' => 'string', 'pattern' => '^[hp]:[0-9a-f]{16}$' ),
				'job'           => array( 'type' => 'object', 'required' => array( 'job_id' ), 'properties' => array( 'job_id' => array( 'type' => 'string', 'pattern' => '^job_[0-9A-Za-z]{10,40}$' ) ) ),
			) ),
			'output_schema'  => $R::envelope_schema( array(
				'ref_code'     => array( 'type' => 'string' ),
				'delivered_to' => array( 'type' => 'integer' ),
				'already'      => array( 'type' => 'boolean' ),
			), array( 'ref_code', 'delivered_to' ) ),
			'read_only'      => false,
			'destructive'    => false,
			'idempotent'     => true,
			'required_scope' => 'memory.write',
			'required_cap'   => 'edit_posts',
			'handler'        => array( __CLASS__, 'deliver' ),
			'mode'           => 'notebook',
			'roles'          => array( 'owner', 'staff' ),
			'scopes'         => array( 'memory.write' ),
			'confirm'        => 'never',
			'llm_alias'      => 'journal_deliver',
			'brain'          => array( 'tier' => 'L1', 'permission' => 'file.send', 'crud' => 'write', 'answer_hint' => 'instant' ),
			'fallback_pack'  => null,
			'since'          => '0.126.0',
		) );

		// @mcp bizcity-mcp-standard@1 tool journal.summarize
		$R::register( 'journal.summarize', array(
			'title'          => 'Gộp số liệu nhật ký',
			// [2026-10-21 12:59 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.126 ACI (doc 96 P-7)
			'description'    => 'Tính tổng / trung bình / số dòng của MỘT trường số trong thẻ các dòng sổ, gộp theo ngày / tuần / tháng — vd tổng kcal theo ngày (kind nutri.meal, field kcal), tổng giá trị hợp đồng theo tháng (kind biz.contract, field total). '
				. 'Tính bằng mã, không đoán. Dùng khi hỏi "bao nhiêu", "tổng", "trung bình". KHÔNG dùng để liệt kê dòng (journal.find) và không dùng cho sổ thu chi / công nợ đã có công cụ sổ riêng.',
			'input_schema'   => array( 'type' => 'object', 'required' => array( 'field' ), 'properties' => array(
				'field'        => array( 'type' => 'string', 'maxLength' => 80, 'description' => 'Tên trường số trong thẻ, vd "kcal", "total". Xem tên ở dòng "thẻ:" của kết quả journal.find.' ),
				'kind'         => array( 'type' => 'string', 'maxLength' => 48, 'description' => 'Một loại dòng, vd "nutri.meal". Nên luôn có kind hoặc kinds.' ),
				'kinds'        => array( 'type' => 'array', 'items' => array( 'type' => 'string', 'maxLength' => 48 ), 'maxItems' => 10, 'description' => 'Nhiều loại cùng lúc.' ),
				'subject_hash' => array( 'type' => 'string', 'maxLength' => 18, 'description' => 'Chỉ dòng của một người/đối tượng (mã lấy từ dòng khác, không tự tạo).' ),
				'from'         => array( 'type' => 'string', 'maxLength' => 10, 'description' => 'Từ ngày tài liệu, YYYY-MM-DD.' ),
				'to'           => array( 'type' => 'string', 'maxLength' => 10, 'description' => 'Đến ngày tài liệu, YYYY-MM-DD.' ),
				'group_by'     => array( 'type' => 'string', 'enum' => array( 'day', 'week', 'month' ), 'description' => 'Gộp theo day (mặc định), week hoặc month.' ),
			) ),
			'output_schema'  => $R::envelope_schema( array( 'groups' => array( 'type' => 'object' ), 'total' => array( 'type' => 'object' ) ), array( 'groups', 'total' ) ),
			'read_only'      => true,
			'idempotent'     => true,
			'required_scope' => 'brain.read',
			'handler'        => array( __CLASS__, 'summarize' ),
			'mode'           => 'notebook',
			'roles'          => array( 'owner', 'staff' ),
			'scopes'         => array( 'brain.read' ),
			'confirm'        => 'never',
			'llm_alias'      => 'journal_summarize',
			'brain'          => array( 'tier' => 'L1', 'permission' => 'meta.read', 'crud' => 'read', 'answer_hint' => 'instant' ),
			'fallback_pack'  => null,
			'since'          => self::SINCE,
		) );

		// @mcp bizcity-mcp-standard@1 tool journal.derive
		$R::register( 'journal.derive', array(
			'title'          => 'Lưu sản phẩm làm từ tài liệu',
			// [2026-10-21 12:59 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.126 ACI (doc 96 P-7)
			'description'    => 'Lưu một SẢN PHẨM trợ lý làm ra TỪ các dòng đã có (bài đăng nháp, thực đơn, tóm tắt, hợp đồng nháp…) thành một dòng mới trỏ về các dòng gốc; dòng gốc không bao giờ bị sửa. '
				. 'Hai lần gọi: lần 1 trả bản xem trước + confirm_token, đọc lại cho chủ; chủ đồng ý thì gọi lại đúng tham số + confirm_token. KHÔNG dùng để lưu file chủ vừa gửi (đã tự lưu) hay ghi chú tự do (notebook_remember).',
			'input_schema'   => array( 'type' => 'object', 'required' => array( 'kind', 'title', 'derived_from' ), 'properties' => array(
				'kind'         => array( 'type' => 'string', 'maxLength' => 48, 'pattern' => '\\.output$', 'description' => 'Loại sản phẩm, luôn kết thúc bằng ".output", vd "mkt.post.output", "media.document.output".' ),
				'title'        => array( 'type' => 'string', 'maxLength' => 255, 'description' => 'Tên ngắn của sản phẩm, vd "Bài đăng giới thiệu kho Bình Dương".' ),
				'card'         => array( 'type' => 'object', 'description' => 'Vài thông tin chính dạng khoá: giá trị phẳng, vd {"channel":"facebook","words":180}. Không dán toàn văn.' ),
				'subject_hash' => array( 'type' => 'string', 'maxLength' => 18, 'description' => 'Mã người/đối tượng lấy từ dòng gốc. KHÔNG tự tạo.' ),
				'tags'         => array( 'type' => 'array', 'items' => array( 'type' => 'string', 'maxLength' => 40 ), 'maxItems' => 12, 'description' => 'Nhãn chữ thường không dấu.' ),
				'derived_from' => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ), 'minItems' => 1, 'maxItems' => 20, 'description' => 'entry_id của các dòng gốc (từ journal.find / journal.get), vd [45, 52].' ),
				// [2026-10-20 11:52 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.126 doc 90 R100-06 (job-link@1) — a product made inside a job sits under its JB- header (the cell passes its job id; the model never invents one)
				'job_id'       => array( 'type' => 'string', 'pattern' => '^job_[0-9A-Za-z]{10,40}$', 'description' => 'Hệ thống: mã việc của cell. Mô hình KHÔNG tự đặt.' ),
				'confirm_token'=> array( 'type' => 'string', 'description' => 'Chỉ ở lần gọi thứ hai: chép đúng từ bản xem trước, sau khi chủ đồng ý.' ),
			) ),
			'output_schema'  => $R::envelope_schema( array(
				'status'        => array( 'type' => 'string', 'enum' => array( 'needs_confirmation', 'done' ) ),
				'preview'       => array( 'type' => 'object' ),
				'confirm_token' => array( 'type' => 'string' ),
				'entry_id'      => array( 'type' => 'integer' ),
			) ),
			'read_only'      => false,
			'destructive'    => false,
			'idempotent'     => false,
			'required_scope' => 'memory.write',
			'required_cap'   => 'edit_posts',
			'handler'        => array( __CLASS__, 'derive' ),
			'preview'        => array( __CLASS__, 'derive_preview' ),
			'mode'           => 'notebook',
			'roles'          => array( 'owner', 'staff' ),
			'scopes'         => array( 'memory.write' ),
			'confirm'        => 'always',
			'llm_alias'      => 'journal_derive',
			'brain'          => array( 'tier' => 'L1', 'permission' => 'write', 'crud' => 'write', 'answer_hint' => 'instant' ),
			'fallback_pack'  => null,
			'since'          => self::SINCE,
		) );

		// @mcp bizcity-mcp-standard@1 tool track.get
		$R::register( 'track.get', array(
			'title'          => 'Đọc chuỗi theo dõi',
			// [2026-10-21 12:59 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.126 ACI (doc 96 P-7)
			'description'    => 'Đọc các điểm theo dõi theo ngày của CHÍNH CHỦ (cân nặng, số bước, giờ ngủ… do track.set ghi) trong tối đa 90 ngày — cho bước Tổng kết của chương trình coach. '
				. 'KHÔNG phải tiến độ của khách hay nhân sự (chỉ có của chủ) và không phải tiến độ một việc JB- (dùng journal.find job=…).',
			'input_schema'   => array( 'type' => 'object', 'properties' => array(
				'unit_id'      => array( 'type' => 'string', 'maxLength' => 40, 'description' => 'Mã bài / đơn vị của chương trình (từ program.next). Bỏ trống = mọi đơn vị.' ),
				'days'         => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 90, 'description' => 'Số ngày gần nhất cần đọc (1–90).' ),
			) ),
			'output_schema'  => $R::envelope_schema( array( 'items' => array( 'type' => 'array' ), 'total' => array( 'type' => 'integer' ) ), array( 'items', 'total' ) ),
			'read_only'      => true,
			'idempotent'     => true,
			'required_scope' => 'brain.read',
			'handler'        => array( __CLASS__, 'track_get' ),
			'mode'           => 'notebook',
			'roles'          => array( 'owner', 'staff' ),
			'scopes'         => array( 'brain.read' ),
			'confirm'        => 'never',
			'llm_alias'      => 'track_get',
			'fallback_pack'  => null,
			'since'          => self::SINCE,
		) );

		// @mcp bizcity-mcp-standard@1 tool track.set
		$R::register( 'track.set', array(
			'title'          => 'Ghi điểm theo dõi',
			// [2026-10-21 12:59 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.126 ACI (doc 96 P-7)
			'description'    => 'Ghi các chỉ số theo dõi của MỘT ngày cho chính chủ, vd {"can_nang":68.5,"buoc":8200}. Gọi lại cùng ngày + cùng unit_id thì GỘP thêm chỉ số, không tạo dòng mới. '
				. 'Dùng khi chủ báo số đo hằng ngày trong chương trình coach. KHÔNG dùng cho thu chi / công nợ (công cụ sổ riêng) và không ghi số đo của người khác.',
			'input_schema'   => array( 'type' => 'object', 'required' => array( 'metrics' ), 'properties' => array(
				'metrics'      => array( 'type' => 'object', 'description' => 'Tên chỉ số ⇒ số, vd {"can_nang":68.5,"ngu_gio":7}. Chỉ số cùng tên trong ngày được thay.' ),
				'unit_id'      => array( 'type' => 'string', 'maxLength' => 40, 'description' => 'Mã bài / đơn vị của chương trình (từ program.next), có thể bỏ trống.' ),
				'doc_date'     => array( 'type' => 'string', 'maxLength' => 10, 'description' => 'Ngày đo, YYYY-MM-DD; bỏ trống = hôm nay.' ),
			) ),
			'output_schema'  => $R::envelope_schema( array( 'entry_id' => array( 'type' => 'integer' ), 'metrics' => array( 'type' => 'object' ) ), array( 'entry_id' ) ),
			'read_only'      => false,
			'destructive'    => false,
			'idempotent'     => true,
			'required_scope' => 'memory.write',
			'required_cap'   => 'edit_posts',
			'handler'        => array( __CLASS__, 'track_set' ),
			'mode'           => 'notebook',
			'roles'          => array( 'owner', 'staff' ),
			'scopes'         => array( 'memory.write' ),
			'confirm'        => 'never',
			'llm_alias'      => 'track_set',
			'fallback_pack'  => null,
			'since'          => self::SINCE,
		) );

		// [2026-10-24 12:33 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.131 S131-L1 — the door is open: ready + how many journal.*/track.* names the registry holds (count omitted when the registry cannot say).
		self::$door = array( 'journal' => 'ready', 'as_of' => gmdate( 'c' ) );
		if ( method_exists( $R, 'all_registered_tool_names' ) ) {
			$n = 0;
			foreach ( (array) $R::all_registered_tool_names() as $name ) {
				if ( 0 === strpos( (string) $name, 'journal.' ) || 0 === strpos( (string) $name, 'track.' ) ) {
					$n++;
				}
			}
			self::$door['count'] = $n;
		}
	}

	/* ── context-scope@1 (PHASE-0.131 S131-L3, doc 20 §2) ─────────────────── */

	/** [2026-10-24 12:33 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.131 S131-L3 — the optional `context_scope` object of journal.find / journal.get (one schema, two tools). */
	private static function context_scope_schema(): array {
		return array(
			'type'        => 'object',
			'description' => 'Hệ thống (context-scope@1): lúc này cần đọc gì — chỉ THU HẸP, không mở rộng quyền. Thiếu ⇒ như cũ (own + current + mọi lớp).',
			'properties'  => array(
				'principal' => array( 'type' => 'object', 'description' => 'Bỏ qua phía site: người gọi lấy từ phiên MCP, không từ tham số.' ),
				'job_ref'   => array( 'type' => 'string', 'maxLength' => 48, 'pattern' => '^(JB-[0-9]{1,12}|job_[0-9A-Za-z]{10,40})$', 'description' => 'Việc đang chạy; dùng với scope="job".' ),
				'layers'    => array( 'type' => 'array', 'maxItems' => 6, 'items' => array( 'type' => 'string', 'enum' => array( 'document', 'notebook', 'twin_sheet', 'skill', 'job', 'output' ) ), 'description' => 'Chỉ các lớp này.' ),
				'version'   => array( 'type' => 'string', 'maxLength' => 32, 'pattern' => '^(current|[A-Za-z]{2}-[0-9]{1,12}@[0-9]{1,6})$', 'description' => '"current" (mặc định) hoặc "<root_ref>@<n>" (vd "TL-47@2") — chỉ journal.get.' ),
				'folder'    => array( 'type' => 'string', 'maxLength' => 40, 'pattern' => '^[0-9]{2}(/[a-z0-9-]{1,40})?$', 'description' => 'Thư mục Kho tri thức đang đứng (D134-13); chỉ thu hẹp.' ),
				// [2026-10-25 Johnny Chu - Chu Hoàng Anh] PHASE-0.136 L6 (0.135 SR-8) — vai · nhiều thư mục · xem như khách: đầu vào THU HẸP, không thêm nguồn
				'folders'   => array( 'type' => 'array', 'maxItems' => 4, 'items' => array( 'type' => 'string', 'pattern' => '^[0-9]{2}(/[a-z0-9-]{1,40})?$' ), 'description' => 'Các thư mục của vai (vd ["50","60"]); chỉ thu hẹp.' ),
				'role'      => array( 'type' => 'string', 'enum' => array( 'personal', 'care', 'sales', 'teach', 'legal', 'content', 'none' ), 'description' => 'Vai đang đứng; không kèm thư mục ⇒ thư mục mặc định của vai (personal = Quản gia: không thu hẹp).' ),
				'view'      => array( 'type' => 'string', 'enum' => array( 'owner', 'as_customer' ), 'description' => 'as_customer = chỉ thư mục Công khai (điều khách sẽ thấy).' ),
				'scope'     => array( 'type' => 'string', 'maxLength' => 48, 'pattern' => '^(own|job|group:[0-9]{3,30})$', 'description' => 'own (mặc định) · job (chỉ dòng của job_ref) · group:<gid> (sổ nhóm Zalo).' ),
			),
		);
	}

	/** Pure (D134-13): the folder a find is narrowed to — the explicit one and the scope's agree (one a prefix of the other, the longer wins). '' = none, null = they disagree ⇒ nothing. */
	public static function scope_folder( string $explicit, array $cs ): ?string {
		$ok = static function ( string $f ): string { return 1 === preg_match( '#^[0-9]{2}(/[a-z0-9-]{1,40})?$#', $f ) ? $f : ''; };
		$a  = $ok( trim( $explicit ) );
		$b  = $ok( trim( (string) ( $cs['folder'] ?? '' ) ) );
		if ( '' === $a || '' === $b ) {
			return '' !== $a ? $a : $b;
		}
		if ( 0 === strpos( $a, $b ) ) {
			return $a;
		}
		return 0 === strpos( $b, $a ) ? $b : null;
	}

	/** PHASE-0.136 (20 §3) — default folders of a role when the scope names a role but no folder. */
	const ROLE_FOLDERS = array( 'care' => array( '50', '60' ), 'sales' => array( '60', '65', '50' ), 'teach' => array( '10', '60' ), 'legal' => array( '55' ), 'content' => array( '40', '60' ) );

	/**
	 * Pure (PHASE-0.136 L6, 0.135 SR-8): every folder a find is narrowed to. explicit `folder` ∩ scope folder (scope_folder) ⇒ else scope `folders[]`
	 * ⇒ else the role's defaults; `view: as_customer` keeps only folders whose access is public ($public = list of public folder numbers; null = unknown ⇒ none).
	 * array() = no narrowing · null = narrowed to nothing (disagreeing / no public folder) ⇒ the find returns nothing.
	 */
	public static function scope_folders( string $explicit, array $cs, ?array $public = null ): ?array {
		$one = self::scope_folder( $explicit, $cs );
		if ( null === $one ) {
			return null;
		}
		$ok   = static function ( $f ): string { $f = trim( (string) $f ); return 1 === preg_match( '#^[0-9]{2}(/[a-z0-9-]{1,40})?$#', $f ) ? $f : ''; };
		$list = '' !== $one ? array( $one ) : array_values( array_unique( array_filter( array_map( $ok, array_slice( (array) ( $cs['folders'] ?? array() ), 0, 4 ) ) ) ) );
		$role = (string) ( $cs['role'] ?? '' );
		if ( ! $list && isset( self::ROLE_FOLDERS[ $role ] ) ) {
			$list = self::ROLE_FOLDERS[ $role ];
		}
		if ( 'as_customer' === (string) ( $cs['view'] ?? '' ) ) {
			$pub  = array_map( 'strval', (array) $public );
			$list = $list ? array_values( array_filter( $list, static function ( $f ) use ( $pub ) { return in_array( substr( $f, 0, 2 ), $pub, true ); } ) ) : $pub;
			return $list ? $list : null;
		}
		return $list;
	}

	/** The public folder numbers of this site (Kho tri thức folder access). */
	private static function public_folders(): array {
		if ( ! class_exists( 'BizCity_Twin_Vault' ) || ! method_exists( 'BizCity_Twin_Vault', 'access_map' ) ) {
			return array();
		}
		return array_values( array_map( 'strval', array_keys( array_filter( BizCity_Twin_Vault::access_map(), static function ( $a ) { return 'public' === $a; } ) ) ) );
	}

	/** Pure: a well-formed context_scope or array() when absent / not an object. */
	public static function context_scope( array $args ): array {
		return is_array( $args['context_scope'] ?? null ) ? $args['context_scope'] : array();
	}

	/** Pure: the layers requested (validated against the six), array() = no narrowing. */
	public static function scope_layers( array $cs ): array {
		$out = array();
		foreach ( (array) ( $cs['layers'] ?? array() ) as $l ) {
			if ( in_array( (string) $l, array( 'document', 'notebook', 'twin_sheet', 'skill', 'job', 'output' ), true ) && ! in_array( (string) $l, $out, true ) ) {
				$out[] = (string) $l;
			}
		}
		return $out;
	}

	/** Pure: does ONE row survive the scope (layers · group)? Used where a single row comes back by code / output id, and on journal.get. */
	public static function scope_keeps( array $row, array $cs ): bool {
		$layers = self::scope_layers( $cs );
		if ( $layers ) {
			$layer = (string) ( $row['layer'] ?? ( method_exists( 'BizCity_Journal_Kinds', 'layer_of' ) ? BizCity_Journal_Kinds::layer_of( (string) ( $row['kind'] ?? '' ) ) : 'document' ) );
			if ( ! in_array( $layer, $layers, true ) ) {
				return false;
			}
		}
		$scope = (string) ( $cs['scope'] ?? '' );
		if ( 0 === strpos( $scope, 'group:' ) ) {
			$gid = substr( $scope, 6 );
			if ( $gid !== (string) ( $row['belongs']['group']['gid'] ?? $row['group']['gid'] ?? '' ) ) {
				return false;
			}
		}
		$folders = self::scope_folders( '', $cs, 'as_customer' === (string) ( $cs['view'] ?? '' ) ? self::public_folders() : null ); // D134-13 / PHASE-0.136: outside the folders ⇒ not in this scope
		if ( null === $folders ) {
			return false;
		}
		if ( $folders ) {
			$have = (string) ( $row['meta']['folder'] ?? '' );
			$in   = false;
			foreach ( $folders as $f ) {
				$in = $in || ( '' !== $have && 0 === strpos( $have, $f ) );
			}
			if ( ! $in ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Pure: "<root_ref>@<n>" ⇒ the entry of that version from a page of entries (rows whose version.root_ref or own ref_code equals root_ref), null when absent.
	 * n = 1 and no version block ⇒ the root row itself (a file never versioned is its own v1).
	 */
	public static function pick_version( array $entries, string $root_ref, int $n ) {
		$root = strtoupper( trim( $root_ref ) );
		foreach ( $entries as $e ) {
			if ( ! is_array( $e ) || 'void' === (string) ( $e['state'] ?? '' ) ) {
				continue;
			}
			$own = strtoupper( (string) ( $e['ref_code'] ?? '' ) );
			$vr  = strtoupper( (string) ( $e['version']['root_ref'] ?? '' ) );
			if ( $root !== $own && ( '' === $vr || $root !== $vr ) ) {
				continue;
			}
			if ( $n === (int) ( $e['version']['n'] ?? 1 ) ) {
				return $e;
			}
		}
		return null;
	}

	/* ── journal.record job gate (PHASE-0.131 S131-L4, doc 20 §5) ───────────── */

	/** Pure: a key of a background-job STEP (`job:job_<id>:…`); a `chat:` key or a plain `job:<record id>` never asks for a job block. */
	public static function is_job_step_key( string $key ): bool {
		return 1 === preg_match( '/^job:job_[0-9A-Za-z]{10,40}:/', $key );
	}

	/** Pure: stage 2 is on when option bizcity_journal_require_job == '1' (stage 1 = log and still write). */
	public static function require_job(): bool {
		return function_exists( 'get_option' ) && '1' === (string) get_option( 'bizcity_journal_require_job', '0' );
	}

	/* ── journal.* handlers ──────────────────────────────────────────── */

	public static function find( array $args, array $ctx ) {
		// [2026-10-24 12:33 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.131 S131-L3 (context-scope@1, doc 20 §2) — the scope only NARROWS; decide() below runs exactly as before.
		$cs    = self::context_scope( $args );
		$scope = (string) ( $cs['scope'] ?? '' );
		unset( $args['context_scope'] );
		if ( 'job' === $scope ) {
			// scope job ⇒ the same `job` filter; the job of the scope and an explicit `job` must agree, else nothing (narrowing, never widening)
			$jr = (string) ( $cs['job_ref'] ?? '' );
			if ( '' === $jr || ( '' !== (string) ( $args['job'] ?? '' ) && (string) $args['job'] !== $jr ) ) {
				return array( 'items' => array(), 'total' => 0 );
			}
			$args['job'] = $jr;
		}
		// [2026-10-21 11:58 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.126 ACI (doc 96 F-2) — one code ⇒ that row (0 or 1), through the same access decision
		if ( '' !== trim( (string) ( $args['ref'] ?? '' ) ) ) {
			$row = BizCity_Journal_Index::find_by_ref( (string) $args['ref'], self::owner( $ctx ) );
			if ( $row && class_exists( 'BizCity_Brain_Access' ) ) {
				$row = BizCity_Brain_Access::filter( array( $row ), BizCity_Brain_Access::principal( $ctx ), 'meta.read' )[0] ?? null;
			}
			if ( $row && ! self::scope_keeps( $row, $cs ) ) {
				$row = null; // S131-L3: outside the requested layers / group ⇒ not in this scope
			}
			return $row ? array( 'items' => array( $row ), 'total' => 1 ) : array( 'items' => array(), 'total' => 0 );
		}
		$oid = (string) ( $args['output_id'] ?? '' );
		if ( '' !== $oid ) {
			if ( 1 !== preg_match( '/^co_[a-f0-9]{24}$/', $oid ) ) {
				return array( 'items' => array(), 'total' => 0 );
			}
			$row = BizCity_Journal_Index::get_by_idempotency_key( self::output_key( $oid ), self::owner( $ctx ) );
			if ( $row && ! self::scope_keeps( $row, $cs ) ) {
				$row = null;
			}
			return $row ? array( 'items' => array( $row ), 'total' => 1 ) : array( 'items' => array(), 'total' => 0 );
		}
		$filter = $args;
		$filter['owner_user_id'] = self::owner( $ctx );
		// [2026-10-25 PHASE-0.134 D134-13] folder narrows only: the explicit `folder` and context_scope.folder must agree (one a prefix of the other), else nothing; the longer wins
		// PHASE-0.136 L6: + folders[] · role defaults · view as_customer (public folders only)
		$ff = self::scope_folders( (string) ( $args['folder'] ?? '' ), $cs, 'as_customer' === (string) ( $cs['view'] ?? '' ) ? self::public_folders() : null );
		if ( null === $ff ) {
			return array( 'items' => array(), 'total' => 0 );
		}
		unset( $filter['folder'], $filter['folders'] );
		if ( 1 === count( $ff ) ) {
			$filter['folder'] = $ff[0];
		} elseif ( $ff ) {
			$filter['folders'] = $ff;
		}
		// S131-L3: scope group:<gid> ⇒ the group's journal tag (`grp-<gid>`, PHASE-0.124) joins the tag filter — the index already filters by tags; a non-group id ⇒ nothing
		if ( 0 === strpos( $scope, 'group:' ) ) {
			$gtag = method_exists( 'BizCity_Journal_Index', 'group_tag' ) ? BizCity_Journal_Index::group_tag( substr( $scope, 6 ) ) : '';
			if ( '' === $gtag ) {
				return array( 'items' => array(), 'total' => 0 );
			}
			$filter['tags'] = array_values( array_unique( array_merge( array_map( 'strval', (array) ( $filter['tags'] ?? array() ) ), array( $gtag ) ) ) );
		}
		// S131-L3: scope layers ⇒ the kinds of those layers (intersected with an explicit kind / kinds, never widened); an explicit `layer` outside them ⇒ nothing
		$layers = self::scope_layers( $cs );
		if ( $layers && method_exists( 'BizCity_Journal_Kinds', 'layer_of' ) ) {
			if ( '' !== (string) ( $args['layer'] ?? '' ) ) {
				if ( ! in_array( (string) $args['layer'], $layers, true ) ) {
					return array( 'items' => array(), 'total' => 0 );
				}
				$layers = array( (string) $args['layer'] ); // both given ⇒ the one layer they share
			}
			$wanted = ! empty( $filter['kinds'] ) ? array_map( 'strval', (array) $filter['kinds'] ) : ( ! empty( $filter['kind'] ) ? array( (string) $filter['kind'] ) : array_map( 'strval', array_keys( (array) BizCity_Journal_Kinds::all()['kinds'] ) ) );
			$kinds  = array();
			foreach ( $wanted as $k ) {
				if ( in_array( BizCity_Journal_Kinds::layer_of( $k ), $layers, true ) ) {
					$kinds[] = $k;
				}
			}
			$filter['kinds'] = $kinds ?: array( '__none__' );
			unset( $filter['kind'], $filter['kind_prefix'] );
			if ( '' === (string) ( $args['layer'] ?? '' ) ) {
				unset( $filter['layer'] );
			}
		}
		// [2026-10-20 10:53 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.126 S126-J5 (job-link@1) — `job` ⇒ the header's tag; an unknown / foreign job ⇒ an empty page (never 404: no existence leak).
		if ( '' !== (string) ( $args['job'] ?? '' ) ) {
			$tag = class_exists( 'BizCity_Journal_Job_Link' ) ? BizCity_Journal_Job_Link::tag_for_query( $filter['owner_user_id'], (string) $args['job'] ) : '';
			if ( '' === $tag ) {
				return array( 'items' => array(), 'total' => 0 );
			}
			$filter['tags']   = array_values( array_unique( array_merge( array_map( 'strval', (array) ( $filter['tags'] ?? array() ) ), array( $tag ) ) ) );
			unset( $filter['job'] );
		}
		// `layer` (journal-entry@1.2) is derived from the kind: the SQL filter takes the kinds of that layer.
		if ( '' !== (string) ( $args['layer'] ?? '' ) && empty( $filter['kind'] ) && empty( $filter['kinds'] ) && method_exists( 'BizCity_Journal_Kinds', 'layer_of' ) ) {
			$kinds = array();
			foreach ( array_keys( (array) BizCity_Journal_Kinds::all()['kinds'] ) as $k ) {
				if ( (string) $args['layer'] === BizCity_Journal_Kinds::layer_of( (string) $k ) ) {
					$kinds[] = (string) $k;
				}
			}
			$filter['kinds'] = $kinds ?: array( '__none__' );
			unset( $filter['kind_prefix'], $filter['layer'] );
		}
		$res = BizCity_Journal_Index::find( $filter );
		// [2026-10-20 10:53 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.126 S126-P2 (brain-access@1) — rows leave only through decide(): the principal of the turn, meta.read.
		if ( class_exists( 'BizCity_Brain_Access' ) ) {
			$p   = BizCity_Brain_Access::principal( $ctx );
			$res['items'] = BizCity_Brain_Access::filter( $res['items'], $p, 'meta.read' );
		}
		// [2026-10-25 12:39 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.135 S-3 — `fuse` + terms ⇒ library notes of the Kho tri thức join ONE ranked list (reciprocal rank fusion, by
		// reference: TL-… rows and lib:… notes are never merged into one another). Gates kept (web-76 2026-10-25): the folder / role / view narrowing above runs first; a narrowed
		// page (folder set) or an as_customer view fuses nothing; vault.search applies its own access gate. The KG (knowledge.search: embeddings + strict citations) stays its own tool.
		if ( ! empty( $args['fuse'] ) && ! empty( $args['terms'] ) && empty( $ff ) && 'as_customer' !== (string) ( $cs['view'] ?? '' ) && class_exists( 'BizCity_Twin_Vault' ) && method_exists( 'BizCity_Twin_Vault', 'search' ) ) {
			$lib = array();
			foreach ( array_slice( array_map( 'strval', (array) $args['terms'] ), 0, 3 ) as $t ) {
				$found = BizCity_Twin_Vault::search( array( 'q' => $t, 'types' => array( 'note', 'skill' ), 'limit' => 10 ), $ctx );
				foreach ( (array) ( $found['items'] ?? array() ) as $h ) {
					$id = (string) ( $h['id'] ?? '' );
					if ( 0 !== strpos( $id, 'lib:' ) ) {
						continue; // journal rows already come from the find above
					}
					if ( ! isset( $lib[ $id ] ) || (int) ( $h['score'] ?? 0 ) > (int) ( $lib[ $id ]['score'] ?? 0 ) ) {
						$lib[ $id ] = $h;
					}
				}
			}
			$res = array_merge( $res, self::fuse_ranked( (array) $res['items'], array_values( $lib ) ) );
		}
		return $res;
	}

	/**
	 * [2026-10-25 12:39 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.135 S-3 — reciprocal rank fusion of the Journal page (already in score order) and the library hits (ranked here by their
	 * own score): fused score = Σ 1 / (k + rank). One entry per REFERENCE (TL-… code or lib:… id). `coverage` = which sources answered: both ⇒ full, one ⇒ partial, none ⇒ none.
	 * Pure. @return array{fused: array<int,array{ref:string,source:string,score:float,why:array}>, library: array, coverage: string, sources: string[]}
	 */
	public static function fuse_ranked( array $journal, array $library, int $k = 60 ): array {
		usort( $library, static function ( $a, $b ) { return (int) ( $b['score'] ?? 0 ) <=> (int) ( $a['score'] ?? 0 ); } );
		$out = array();
		foreach ( array_values( $journal ) as $i => $e ) {
			$ref = (string) ( $e['ref_code'] ?? ( isset( $e['entry_id'] ) ? 'entry:' . (int) $e['entry_id'] : '' ) );
			if ( '' === $ref || isset( $out[ $ref ] ) ) {
				continue; // the same row twice (two versions on one page) keeps its best rank
			}
			$out[ $ref ] = array( 'ref' => $ref, 'source' => 'journal', 'score' => 1 / ( $k + $i + 1 ), 'why' => array_slice( array_map( 'strval', (array) ( $e['why'] ?? array() ) ), 0, 5 ) );
		}
		foreach ( array_values( $library ) as $i => $h ) {
			$ref = (string) ( $h['id'] ?? '' );
			if ( '' === $ref ) {
				continue;
			}
			$s = 1 / ( $k + $i + 1 );
			if ( isset( $out[ $ref ] ) ) {
				$out[ $ref ]['score'] += $s;
				$out[ $ref ]['source'] = 'both';
			} else {
				$out[ $ref ] = array( 'ref' => $ref, 'source' => 'library', 'score' => $s, 'why' => array_slice( array_map( 'strval', (array) ( $h['why'] ?? array() ) ), 0, 5 ) );
			}
		}
		$fused = array_values( $out );
		usort( $fused, static function ( $a, $b ) { return $b['score'] <=> $a['score'] ?: strcmp( $a['ref'], $b['ref'] ); } );
		foreach ( $fused as &$f ) {
			$f['score'] = round( $f['score'], 5 );
		}
		unset( $f );
		$sources = array_values( array_filter( array( $journal ? 'journal' : '', $library ? 'library' : '' ) ) );
		$fused   = array_slice( $fused, 0, 20 );
		// [2026-10-25 12:45 PM Johnny Chu - Chu Hoàng Anh] web-76 review — one library card for EVERY lib: ref that survives in fused (≤ 20), so no fused ref is left without its title / path
		$kept = array_flip( array_column( $fused, 'ref' ) );
		$lib  = array();
		foreach ( array_values( $library ) as $h ) {
			$id = (string) ( $h['id'] ?? '' );
			if ( isset( $kept[ $id ] ) && ! isset( $lib[ $id ] ) ) {
				$lib[ $id ] = array_intersect_key( $h, array_flip( array( 'id', 'type', 'title', 'path', 'source_ref', 'why', 'score' ) ) );
			}
		}
		$lib = array_values( $lib );
		return array( 'fused' => $fused, 'library' => $lib, 'coverage' => 2 === count( $sources ) ? 'full' : ( 1 === count( $sources ) ? 'partial' : 'none' ), 'sources' => $sources );
	}

	/**
	 * [2026-10-22 02:10 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.126 S126-M1 (doc 20 §4 envelope) — a row is "deeper-able" when it is a document / notebook source already embedded (L2) or graphed (L3):
	 * the register line is L1, the content (or the relations) sits one tier down. Pure; tier null (sheet, job, skill…) has no next tier.
	 */
	public static function has_deeper_tier( array $items ): bool {
		foreach ( $items as $e ) {
			if ( is_array( $e ) && in_array( $e['tier'] ?? null, array( 'L2', 'L3' ), true ) ) {
				return true;
			}
		}
		return false;
	}

	/** MCP door of journal.find: the rows as before + `next_tier_available` (any returned row has a deeper tier). */
	public static function find_mcp( array $args, array $ctx ) {
		$res = self::find( $args, $ctx );
		if ( is_array( $res ) ) {
			$res['next_tier_available'] = self::has_deeper_tier( (array) ( $res['items'] ?? array() ) );
		}
		return $res;
	}

	/** MCP door of journal.get: the row as before + `next_tier_available`. The row keeps ITS OWN `tier` (maturity of the document); the tool's tier is not stamped over it. */
	public static function get_mcp( array $args, array $ctx ) {
		$row = self::get( $args, $ctx );
		if ( is_array( $row ) && 'card' === (string) ( $args['view'] ?? '' ) ) {
			return self::card_of( $row ); // PHASE-0.133 view=card
		}
		if ( is_array( $row ) ) {
			$row['next_tier_available'] = self::has_deeper_tier( array( $row ) );
		}
		return $row;
	}

	/** [2026-10-24 05:47 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.133 S133-Ma (doc 81 §7) — entry-card@1 of a row the principal may read (no download link: the REST door signs one). */
	public static function card_of( array $row, string $preview_url = '' ): array {
		if ( ! class_exists( 'BizCity_Journal_Entry_Meta' ) ) {
			return $row;
		}
		$def  = class_exists( 'BizCity_Journal_Kinds' ) ? (array) ( BizCity_Journal_Kinds::get( (string) ( $row['kind'] ?? '' ) ) ?? array() ) : array();
		$card = BizCity_Journal_Entry_Meta::card( $row, $def, $preview_url );
		// [2026-10-25 PHASE-0.134 D134-18] the owner's folder rules ⇒ "Xếp vào 30 Dự án?" on the card (null when filed / no rule matches)
		if ( is_array( $card ) && class_exists( 'BizCity_Twin_Vault' ) && method_exists( 'BizCity_Twin_Vault', 'suggest_for_row' ) ) {
			$card['folder']         = (string) ( $row['meta']['folder'] ?? '' );
			$card['contact_id']     = (int) ( $row['contact_id'] ?? 0 ); // PHASE-0.136 U2 — the panel opens "Hồ sơ khách" for this person
			$card['folder_suggest'] = BizCity_Twin_Vault::suggest_for_row( $row, function_exists( 'current_user_can' ) && ! current_user_can( 'manage_options' ) ? array( 'vrole' => 'staff' ) : array() ); // a staff viewer never sees an owner-only folder's name
		}
		return $card;
	}

	public static function get( array $args, array $ctx ) {
		// [2026-10-21 11:58 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.126 ACI (doc 96 F-2/F-5) — by code or by id; a miss says what to do next
		$ref = trim( (string) ( $args['ref'] ?? '' ) );
		$row = '' !== $ref ? BizCity_Journal_Index::find_by_ref( $ref, self::owner( $ctx ) ) : BizCity_Journal_Index::get( (int) ( $args['entry_id'] ?? 0 ), self::owner( $ctx ) );
		// [2026-10-24 12:33 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.131 S131-L3 (context-scope@1) — version "<root_ref>@<n>" ⇒ THAT version of the chain (R-FILE-VERSION version{n, root_ref}); layers / group only narrow.
		$cs  = self::context_scope( $args );
		$ver = (string) ( $cs['version'] ?? '' );
		if ( '' !== $ver && 'current' !== $ver ) {
			$row = null;
			if ( 1 === preg_match( '/^([A-Za-z]{2}-[0-9]{1,12})@([0-9]{1,6})$/', $ver, $vm ) ) {
				$owner = self::owner( $ctx );
				$pool  = array();
				if ( class_exists( 'BizCity_Journal_Database' ) && method_exists( 'BizCity_Journal_Database', 'instance' ) && method_exists( BizCity_Journal_Database::instance(), 'register_rows' ) && method_exists( 'BizCity_Journal_Index', 'to_journal_entry' ) ) {
					$pool = array_map( array( 'BizCity_Journal_Index', 'to_journal_entry' ), BizCity_Journal_Database::instance()->register_rows( $owner, 500 ) );
				} elseif ( method_exists( 'BizCity_Journal_Index', 'find_by_ref' ) ) {
					$root = BizCity_Journal_Index::find_by_ref( $vm[1], $owner );
					$pool = $root ? array( $root ) : array();
				}
				$row = self::pick_version( $pool, $vm[1], (int) $vm[2] );
			}
		}
		if ( $row && ! self::scope_keeps( $row, $cs ) ) {
			$row = null;
		}
		if ( ! $row ) {
			return self::err( new WP_Error( 'journal_not_found', '' !== $ref ? 'Không có dòng ' . strtoupper( $ref ) . ' trong sổ của chủ. Tìm lại bằng journal.find (q = vài từ trong tên) để lấy mã đúng.' : 'Không tìm thấy mục nhật ký. Cần ref (vd "TL-47") hoặc entry_id từ kết quả journal.find.' ) );
		}
		// [2026-10-20 10:53 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.126 S126-P2 (brain-access@1) — content.read decided at the source; denied reads as not found (404).
		if ( class_exists( 'BizCity_Brain_Access' ) ) {
			$d = BizCity_Brain_Access::decide( BizCity_Brain_Access::principal( $ctx ), $row, 'content.read' );
			if ( ! $d['allow'] ) {
				return self::err( new WP_Error( 'journal_not_found', 'Không tìm thấy mục nhật ký.' ) );
			}
		}
		return $row;
	}

	public static function file( array $args, array $ctx ) {
		$patch = array_intersect_key( $args, array_flip( array( 'kind', 'tags', 'subject_hash', 'doc_date', 'pinned', 'review', 'contact_id' ) ) );
		if ( isset( $patch['contact_id'] ) ) {
			$patch['contact_id'] = max( 0, (int) $patch['contact_id'] );
			if ( $patch['contact_id'] > 0 && ! BizCity_Journal_Index::contact_visible( $patch['contact_id'], self::owner( $ctx ) ) ) {
				return self::err( new WP_Error( 'contact_not_found', 'Không tìm thấy liên hệ này (hoặc không có quyền xem).' ) );
			}
		}
		// [2026-10-21 12:59 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.126 ACI (doc 96 P-7) — a code works like on journal.get
		$id = (int) ( $args['entry_id'] ?? 0 );
		if ( '' !== trim( (string) ( $args['ref'] ?? '' ) ) ) {
			$hit = BizCity_Journal_Index::find_by_ref( (string) $args['ref'], self::owner( $ctx ) );
			if ( ! $hit ) {
				return self::err( new WP_Error( 'journal_not_found', 'Không có dòng ' . strtoupper( trim( (string) $args['ref'] ) ) . ' trong sổ của chủ. Tìm lại bằng journal.find để lấy mã đúng.' ) );
			}
			$id = (int) $hit['entry_id'];
		}
		// [2026-10-22 01:07 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.126 S126-M4 (C5, brain-access@1 `write`) — changing a row (kind, tags, pin, the contact it belongs to) is decided AT THE ROW:
		// the principal of the turn must hold `write` on it, else it answers "not found" (no existence leak). contact_visible() above stays: it guards the CRM CONTACT (inbox access), not the row.
		if ( class_exists( 'BizCity_Brain_Access' ) ) {
			$row = BizCity_Journal_Index::get( $id, self::owner( $ctx ) );
			if ( $row && ! BizCity_Brain_Access::decide( BizCity_Brain_Access::principal( $ctx ), $row, 'write' )['allow'] ) {
				return self::err( new WP_Error( 'journal_not_found', 'Không tìm thấy mục nhật ký.' ) );
			}
		}
		$r = BizCity_Journal_Index::file( $id, self::owner( $ctx ), $patch );
		return is_wp_error( $r ) ? self::err( $r ) : $r;
	}

	/**
	 * journal.deliver — `TL-47` (the file code) or `JE-31` (the row id) ⇒ the row; brain-access file.send for the caller; then delivered_to += recipient.
	 * A row the caller may not send answers 404 (no existence leak). Idempotent: the same recipient twice ⇒ already.
	 */
	public static function deliver( array $args, array $ctx ) {
		if ( ! class_exists( 'BizCity_Journal_Database' ) || ! class_exists( 'BizCity_Journal_Index' ) ) {
			return self::err( new WP_Error( 'journal_unavailable', 'Sổ ghi chép Zalo Brain chưa sẵn sàng trên site này.' ) );
		}
		$code = strtoupper( trim( (string) ( $args['ref_code'] ?? '' ) ) );
		$to   = strtolower( trim( (string) ( $args['recipient_ref'] ?? '' ) ) );
		if ( 1 !== preg_match( '/^(TL|JE)-([0-9]{1,12})$/', $code, $m ) || 1 !== preg_match( '/^[hp]:[0-9a-f]{16}$/', $to ) ) {
			return self::err( new WP_Error( 'journal_invalid_entry', 'Cần mã TL-… hoặc JE-… và người nhận dạng h:/p: + 16 hex.' ) );
		}
		$db  = BizCity_Journal_Database::instance();
		$row = 'TL' === $m[1] ? $db->get_by_source_ref( $code ) : $db->get( (int) $m[2], 0, true );
		if ( ! $row ) {
			return self::err( new WP_Error( 'journal_not_found', 'Không tìm thấy dòng sổ này.' ) );
		}
		$entry = BizCity_Journal_Index::to_journal_entry( $row );
		if ( class_exists( 'BizCity_Brain_Access' ) && ! BizCity_Brain_Access::decide( BizCity_Brain_Access::principal( $ctx ), $entry, 'file.send' )['allow'] ) {
			return self::err( new WP_Error( 'journal_not_found', 'Không tìm thấy dòng sổ này.' ) );
		}
		$owner = (int) $row['owner_user_id'];
		$had   = in_array( $to, (array) ( $entry['access']['delivered_to'] ?? array() ), true );
		if ( ! $had ) {
			$r = BizCity_Journal_Index::file( (int) $row['id'], $owner, array( 'delivered_to' => array( $to ) ) );
			if ( is_wp_error( $r ) ) {
				return self::err( $r );
			}
			$entry = $r;
		}
		if ( is_array( $args['job'] ?? null ) && class_exists( 'BizCity_Journal_Job_Link' ) && BizCity_Journal_Job_Link::is_job_id( (string) ( $args['job']['job_id'] ?? '' ) ) ) {
			BizCity_Journal_Job_Link::attach( $entry, $owner, array( 'job_id' => (string) $args['job']['job_id'], 'role' => 'output' ) );
		}
		return array( 'ref_code' => (string) ( $entry['ref_code'] ?? $code ), 'delivered_to' => count( (array) ( $entry['access']['delivered_to'] ?? array() ) ), 'already' => $had );
	}

	public static function summarize( array $args, array $ctx ) {
		$filter = array( 'owner_user_id' => self::owner( $ctx ) );
		foreach ( array( 'kind', 'kinds', 'subject_hash', 'from', 'to' ) as $k ) {
			if ( isset( $args[ $k ] ) ) {
				$filter[ $k ] = $args[ $k ];
			}
		}
		return BizCity_Journal_Index::summarize( $filter, (string) ( $args['field'] ?? '' ), (string) ( $args['group_by'] ?? 'day' ) );
	}

	public static function derive_preview( array $args, array $ctx ) {
		$owner = self::owner( $ctx );
		$ids   = array_values( array_unique( array_map( 'intval', (array) ( $args['derived_from'] ?? array() ) ) ) );
		$missing = array();
		foreach ( $ids as $id ) {
			if ( ! BizCity_Journal_Index::get( $id, $owner ) ) {
				$missing[] = $id;
			}
		}
		if ( ! empty( $missing ) ) {
			return self::err( new WP_Error( 'journal_derive_source_missing', 'Không tìm thấy mục gốc: ' . implode( ', ', $missing ) . '.' ) );
		}
		return array(
			'kind'         => (string) ( $args['kind'] ?? '' ),
			'title'        => sanitize_text_field( (string) ( $args['title'] ?? '' ) ),
			'derived_from' => $ids,
			'tags'         => array_values( (array) ( $args['tags'] ?? array() ) ),
		);
	}

	public static function derive( array $args, array $ctx ) {
		$owner = self::owner( $ctx );
		$ids   = array_values( array_unique( array_map( 'intval', (array) ( $args['derived_from'] ?? array() ) ) ) );
		$row   = BizCity_Journal_Index::add( array(
			'owner_user_id'   => $owner,
			'kind'            => (string) ( $args['kind'] ?? '' ),
			'title'           => (string) ( $args['title'] ?? '' ),
			'subject_hash'    => (string) ( $args['subject_hash'] ?? '' ),
			'tags'            => (array) ( $args['tags'] ?? array() ),
			'card'            => is_array( $args['card'] ?? null ) ? $args['card'] : array(),
			'derived_from'    => $ids,
			'source_type'     => 'derived',
			'idempotency_key' => 'derive:' . get_current_blog_id() . ':' . $owner . ':' . md5( wp_json_encode( array( $args['kind'] ?? '', $args['title'] ?? '', $ids ) ) ),
			'by'              => array( 'role' => (string) ( $ctx['role'] ?? 'owner' ) ),
		) );
		if ( is_wp_error( $row ) ) {
			return self::err( $row );
		}
		// [2026-10-20 11:52 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.126 R100-06 — the job id of the TURN wins over an argument (a cell job's turn id is the job id)
		$jid = (string) ( $ctx['turn_id'] ?? '' );
		if ( ! class_exists( 'BizCity_Journal_Job_Link' ) || ! BizCity_Journal_Job_Link::is_job_id( $jid ) ) {
			$jid = (string) ( $args['job_id'] ?? '' );
		}
		if ( class_exists( 'BizCity_Journal_Job_Link' ) && BizCity_Journal_Job_Link::is_job_id( $jid ) ) {
			BizCity_Journal_Job_Link::attach( $row, $owner, array( 'job_id' => $jid, 'role' => 'output' ) );
		}
		return array( 'status' => 'done', 'entry_id' => (int) $row['entry_id'], 'ref_code' => (string) ( $row['ref_code'] ?? '' ) );
	}

	/* ── journal.record / journal.mirror (PHASE-0.117 S117-K1a, doc 66 §4) ───────────── */

	/** the cell's `job:<record id>` → the row key the site really stores: `job:<blog>:<record id>` ('' when the shape is wrong) */
	/** [2026-10-18 02:15 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.121 S121-M (doc 80 §3) — tags as the journal stores them: lowercase a-z 0-9 -, ≤ 10. Pure. */
	public static function clean_tags( $raw ): array {
		$out = array();
		foreach ( array_slice( (array) $raw, 0, 10 ) as $t ) {
			$t = strtolower( (string) preg_replace( '/[^a-z0-9\-]/', '', strtolower( trim( (string) $t ) ) ) );
			if ( '' !== $t && ! in_array( $t, $out, true ) ) {
				$out[] = substr( $t, 0, 60 );
			}
		}
		return $out;
	}

	/** [2026-10-18 02:15 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.121 S121-M (doc 80 §3) — keep only entry ids of THIS owner's book (a cover never points at someone else's row), ≤ 30. */
	public static function own_entry_ids( $raw, int $owner ): array {
		$out = array();
		if ( $owner <= 0 || ! class_exists( 'BizCity_Journal_Database' ) ) {
			return $out;
		}
		$db = BizCity_Journal_Database::instance();
		foreach ( array_slice( array_values( array_unique( array_map( 'intval', (array) $raw ) ) ), 0, 30 ) as $id ) {
			if ( $id > 0 && $db->get( $id, $owner ) ) {
				$out[] = $id;
			}
		}
		return $out;
	}

	/** [2026-10-18 03:59 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.117 S117-K2b (R-JN-25, Q117-K2-2) — the code a person reads and types back: prefix by kind + entry id ('' for other kinds) */
	// [2026-10-20 10:53 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.126 S126-B1 — the table now lives in journal-kinds@1.3 `ref_codes` (BizCity_Journal_Kinds::ref_prefixes); this constant stays for older readers.
	const REF_PREFIX = array( 'fin.cash' => 'TC', 'fin.debt' => 'CN', 'task.progress' => 'TD', 'task.todo' => 'VC', 'fin.plan' => 'KH' );

	/** kind ⇒ prefix, from the contract when present (JB · EV · NB · SO · KN added by 1.3), else the 0.117 five. */
	private static function prefixes(): array {
		$p = method_exists( 'BizCity_Journal_Kinds', 'ref_prefixes' ) ? BizCity_Journal_Kinds::ref_prefixes() : array();
		return $p ?: self::REF_PREFIX;
	}

	public static function ref_code( string $kind, int $entry_id ): string {
		$p = self::prefixes();
		return isset( $p[ $kind ] ) && $entry_id > 0 ? $p[ $kind ] . '-' . $entry_id : '';
	}

	/** "TC-1234" → array( kind, entry_id ); null when the shape is wrong. A prefix shared by several kinds (KN) returns the first declared kind. */
	public static function parse_ref_code( string $code ): ?array {
		if ( 1 !== preg_match( '/^([A-Z]{2})-([0-9]{1,12})$/', strtoupper( trim( $code ) ), $m ) ) {
			return null;
		}
		$kind = (string) array_search( $m[1], self::prefixes(), true );
		return '' !== $kind ? array( $kind, (int) $m[2] ) : null;
	}

	/**
	 * journal.void — the writer voids own rows; the NUMBER OWNER (ctx role owner) may void a row of the same blog written by a person of that number (Q117-K2-3):
	 * the row's metadata.by.role is staff and the caller is the owner. Never deletes, never changes the amount; voiding twice ⇒ already.
	 */
	public static function void_row( array $args, array $ctx ) {
		if ( ! class_exists( 'BizCity_Journal_Database' ) || ! class_exists( 'BizCity_Journal_Index' ) ) {
			return self::err( new WP_Error( 'journal_unavailable', 'Sổ ghi chép Zalo Brain chưa sẵn sàng trên site này.' ) );
		}
		$caller = self::owner( $ctx );
		if ( $caller <= 0 ) {
			return self::err( new WP_Error( 'journal_invalid_entry', 'Thiếu người gọi.' ) );
		}
		$db  = BizCity_Journal_Database::instance();
		$row = null;
		if ( '' !== (string) ( $args['ref_code'] ?? '' ) ) {
			$p = self::parse_ref_code( (string) $args['ref_code'] );
			if ( ! $p ) {
				return self::err( new WP_Error( 'journal_invalid_entry', 'Mã không đúng dạng (vd TC-1234).' ) );
			}
			$row = $db->get( $p[1], 0, true );
			if ( $row && (string) ( $row['kind'] ?? '' ) !== $p[0] ) {
				$row = null; // a TC- code never voids a debt row
			}
		} elseif ( '' !== (string) ( $args['idempotency_key'] ?? '' ) ) {
			$key = self::job_key( (string) $args['idempotency_key'] );
			$row = '' !== $key ? $db->get_by_idempotency_key( $key ) : null;
		}
		if ( ! $row ) {
			return self::err( new WP_Error( 'journal_not_found', 'Không tìm thấy dòng sổ này.' ) );
		}
		$row_owner = (int) $row['owner_user_id'];
		$meta      = is_array( json_decode( (string) ( $row['metadata'] ?? '' ), true ) ) ? json_decode( (string) $row['metadata'], true ) : array();
		$is_owner_of_number = 'owner' === (string) ( $ctx['role'] ?? '' ) && 'staff' === (string) ( $meta['by']['role'] ?? '' ) && (int) ( $row['blog_id'] ?? get_current_blog_id() ) === (int) get_current_blog_id();
		if ( $row_owner !== $caller && ! $is_owner_of_number ) {
			return self::err( new WP_Error( 'journal_not_found', 'Không tìm thấy dòng sổ này.' ) ); // no existence leak
		}
		$code = self::ref_code( (string) ( $row['kind'] ?? '' ), (int) $row['id'] );
		if ( 'void' === (string) ( $row['status'] ?? '' ) ) {
			return array( 'ref_code' => $code, 'state' => 'void', 'already' => true );
		}
		$r = BizCity_Journal_Index::set_state( (int) $row['id'], $row_owner, 'void' );
		if ( is_wp_error( $r ) ) {
			return self::err( $r );
		}
		$reason = sanitize_text_field( substr( (string) ( $args['reason'] ?? '' ), 0, 200 ) );
		$fresh  = $db->get( (int) $row['id'], $row_owner );
		$meta2  = is_array( $fresh ) && is_array( json_decode( (string) ( $fresh['metadata'] ?? '' ), true ) ) ? json_decode( (string) $fresh['metadata'], true ) : array();
		$meta2['void'] = array( 'by_role' => (string) ( $ctx['role'] ?? 'owner' ), 'at' => gmdate( 'c' ), 'reason' => $reason );
		$db->update( (int) $row['id'], $row_owner, array( 'metadata' => $meta2 ) );
		return array( 'ref_code' => $code, 'state' => 'void', 'already' => false );
	}

	/** Pure ([2026-10-20 03:30 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.125 Q1): the idempotency key BizCity_Journal_Output_Listener gives a cell output's row. */
	public static function output_key( string $output_id ): string {
		return 'out:' . max( 0, function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0 ) . ':' . $output_id;
	}

	public static function job_key( string $raw ): string {
		// [2026-10-24 12:33 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.131 S131-L4 — a step key `job:job_<id>:<step>:<n>` carries ":" segments (job-link@1); the stored key stays job:<blog>:<rest>
		return 1 === preg_match( '/^job:([A-Za-z0-9_.-](?:[A-Za-z0-9_.:-]{1,98}[A-Za-z0-9_.-])?)$/', $raw, $m ) && strlen( $m[1] ) >= 3 ? 'job:' . get_current_blog_id() . ':' . $m[1] : '';
	}

	public static function record( array $args, array $ctx ) {
		if ( ! function_exists( 'bizcity_journal_record' ) ) {
			return self::err( new WP_Error( 'journal_unavailable', 'Sổ ghi chép Zalo Brain chưa sẵn sàng trên site này.' ) );
		}
		$owner   = self::owner( $ctx );
		$entries = array_slice( array_values( (array) ( $args['entries'] ?? array() ) ), 0, 20 );
		if ( $owner <= 0 || ! $entries ) {
			return self::err( new WP_Error( 'journal_invalid_entry', 'Thiếu người chủ hoặc danh sách entries.' ) );
		}
		$out = array();
		foreach ( $entries as $e ) {
			$e    = is_array( $e ) ? $e : array();
			$sent = (string) ( $e['idempotency_key'] ?? '' );
			$key  = self::job_key( $sent );
			if ( '' === $key ) {
				return self::err( new WP_Error( 'journal_invalid_entry', 'idempotency_key phải dạng job:<mã>.' ) );
			}
			// [2026-10-24 12:33 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.131 S131-L4 (doc 20 §5, G3 evidence) — a background-job STEP key (`job:job_<id>:…`) without a job block:
			// stage 1 (default) one JSONL warn + still written; stage 2 (option bizcity_journal_require_job=1) refused 400 query_invalid "thiếu job". `chat:` keys never ask.
			$has_job = is_array( $e['job'] ?? null ) && class_exists( 'BizCity_Journal_Job_Link' ) && BizCity_Journal_Job_Link::is_job_id( (string) ( $e['job']['job_id'] ?? '' ) );
			if ( self::is_job_step_key( $sent ) && ! $has_job ) {
				if ( self::require_job() ) {
					return new WP_Error( 'query_invalid', 'Dòng ghi trong bước việc nền thiếu job (job_id, step_ref, role).', array( 'status' => 400, 'reason' => 'journal_job_missing', 'hint' => 'Gửi lại kèm job{job_id, step_ref, role} của bước đang chạy.', 'help_code' => 'journal_job_missing' ) );
				}
				if ( class_exists( 'BizCity_Channel_File_Logger' ) ) {
					try {
						BizCity_Channel_File_Logger::write( BizCity_Channel_File_Logger::CH_CHANNEL_GATEWAY, BizCity_Channel_File_Logger::LEVEL_WARN, 'journal_record_job_missing', 'journal.record step key without job block (stage 1: written)', array( 'module' => 'core/mcp', 'kind' => (string) ( $e['kind'] ?? '' ) ) );
					} catch ( Throwable $t ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
						// never blocks the write
					}
				}
			}
			$had = BizCity_Journal_Index::get_by_idempotency_key( $key, $owner );
			$row = bizcity_journal_record( array(
				'owner_user_id'   => $owner,
				'kind'            => (string) ( $e['kind'] ?? '' ),
				'title'           => (string) ( $e['title'] ?? '' ),
				'summary'         => (string) ( $e['summary'] ?? '' ),
				'doc_date'        => (string) ( $e['doc_date'] ?? '' ),
				'card'            => is_array( $e['card'] ?? null ) ? $e['card'] : array(),
				'tags'            => self::clean_tags( $e['tags'] ?? array() ), // [2026-10-18 02:15 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.121 S121-M (doc 80 §3)
				'derived_from'    => self::own_entry_ids( $e['derived_from'] ?? array(), $owner ), // only rows of the caller's own book
				'state'           => 'captured', // R-JN-14: a job never verifies
				'source_type'     => 'job',
				'source_ref'      => substr( (string) ( $e['source_ref'] ?? '' ), 0, 120 ),
				'idempotency_key' => $key,
				'origin'          => array( 'surface' => 'job', 'ref' => substr( (string) ( $e['origin']['ref'] ?? '' ), 0, 80 ) ),
				// [2026-10-18 03:59 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.117 S117-K2b (R-JN-23) — a Sheet is an export: off unless the cell says a standing mirror / an export is running
				'mirror'          => array( 'sheet' => 'pending' === (string) ( $e['mirror']['sheet'] ?? '' ) ? 'pending' : 'off' ),
				// [2026-10-21 10:37 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.126 W4 — recipients of a delivered product (normalized again by BizCity_Journal_Index::normalize_access)
				'access'          => array( 'customer' => 'off', 'delivered_to' => array_values( array_filter( array_map( 'strval', (array) ( $e['delivered_to'] ?? array() ) ), static function ( $r ) { return 1 === preg_match( '/^[hp]:[0-9a-f]{16}$/', $r ); } ) ) ),
				'by'              => array( 'role' => (string) ( $ctx['role'] ?? 'owner' ) ),
			) );
			if ( is_wp_error( $row ) ) {
				return self::err( $row );
			}
			// [2026-10-20 10:53 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.126 S126-J3 (job-link@1) — inside a job: header (JB-) opened if absent, job_ref + tag on the row, code appended to output_refs / evidence_refs.
			$job_ref = '';
			if ( is_array( $e['job'] ?? null ) && class_exists( 'BizCity_Journal_Job_Link' ) && BizCity_Journal_Job_Link::is_job_id( (string) ( $e['job']['job_id'] ?? '' ) ) ) {
				$linked = BizCity_Journal_Job_Link::attach( $row, $owner, $e['job'] + array( 'actor' => array( 'kind' => 'cell', 'ref' => (string) ( $ctx['user_hash'] ?? '' ) ) ) );
				if ( ! is_wp_error( $linked ) ) {
					$row     = $linked;
					$job_ref = (string) ( $row['job']['job_ref'] ?? '' );
				}
			}
			$out[] = array( 'idempotency_key' => $sent, 'entry_id' => (int) $row['entry_id'], 'ref_code' => (string) ( $row['ref_code'] ?? self::ref_code( (string) ( $e['kind'] ?? '' ), (int) $row['entry_id'] ) ), 'status' => $had ? 'exists' : 'created', 'job_ref' => $job_ref );
		}
		return array( 'entries' => $out );
	}

	public static function mirror( array $args, array $ctx ) {
		if ( ! class_exists( 'BizCity_Journal_Database' ) ) {
			return self::err( new WP_Error( 'journal_unavailable', 'Sổ ghi chép Zalo Brain chưa sẵn sàng trên site này.' ) );
		}
		$owner = self::owner( $ctx );
		$sheet = (string) ( $args['sheet'] ?? '' );
		if ( $owner <= 0 || ! in_array( $sheet, array( 'ok', 'failed' ), true ) ) {
			return self::err( new WP_Error( 'journal_invalid_entry', 'Thiếu người chủ hoặc sheet phải là ok / failed.' ) );
		}
		$mirror = array( 'sheet' => $sheet );
		if ( is_string( $args['row_ref'] ?? null ) ) {
			$mirror['row_ref'] = sanitize_text_field( substr( $args['row_ref'], 0, 120 ) );
		}
		if ( 'failed' === $sheet ) {
			$code = (string) ( $args['error_code'] ?? '' );
			$mirror['error_code'] = 1 === preg_match( '/^[a-z0-9_]{1,40}$/', $code ) ? $code : 'write_failed';
		}
		$db      = BizCity_Journal_Database::instance();
		$updated = 0;
		$rows    = array();
		foreach ( array_slice( array_values( (array) ( $args['idempotency_keys'] ?? array() ) ), 0, 20 ) as $raw ) {
			$key    = self::job_key( (string) $raw );
			$rows[] = '' !== $key ? $db->get_by_idempotency_key( $key ) : null;
		}
		// [2026-10-18 01:35 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.121 A2-3 / B2-3 (I-8) — by id, owner-checked by get() itself
		foreach ( array_slice( array_values( array_unique( array_map( 'intval', (array) ( $args['entry_ids'] ?? array() ) ) ) ), 0, 20 ) as $id ) {
			$rows[] = $id > 0 ? $db->get( $id, $owner ) : null;
		}
		foreach ( $rows as $row ) {
			if ( ! $row || (int) $row['owner_user_id'] !== $owner ) {
				continue; // never touches another person's row; an unknown key is not an error (the cell may report twice)
			}
			$meta = is_array( json_decode( (string) ( $row['metadata'] ?? '' ), true ) ) ? json_decode( (string) $row['metadata'], true ) : array();
			$meta['mirror'] = array_merge( is_array( $meta['mirror'] ?? null ) ? $meta['mirror'] : array(), $mirror );
			if ( 'ok' === $sheet ) {
				unset( $meta['mirror']['error_code'] );
			}
			$r = $db->update( (int) $row['id'], $owner, array( 'metadata' => $meta ) );
			if ( ! is_wp_error( $r ) ) {
				$updated++;
			}
		}
		return array( 'updated' => $updated );
	}

	/* ── track.* handlers (I-4: kind=track.point on the same index) ─── */

	/** [2026-10-12 11:00 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.100 D100-25 owner-only — the one owner-level progress subject (replaces per-student subject_hash / progress:<thread_hash>). */
	private static function owner_subject(): string {
		return BizCity_Journal_Database::subject_hash( 'self', '' );
	}

	public static function track_get( array $args, array $ctx ) {
		$days = min( 90, max( 1, (int) ( $args['days'] ?? 90 ) ) );
		$filter = array(
			'owner_user_id' => self::owner( $ctx ),
			'kind'          => 'track.point',
			'subject_hash'  => self::owner_subject(),
			'from'          => gmdate( 'Y-m-d', time() - $days * DAY_IN_SECONDS ),
			'limit'         => 50,
		);
		$res = BizCity_Journal_Index::find( $filter );
		if ( '' !== (string) ( $args['unit_id'] ?? '' ) ) {
			$unit = (string) $args['unit_id'];
			$res['items'] = array_values( array_filter( $res['items'], static function ( $it ) use ( $unit ) {
				return $unit === (string) ( $it['card']['unit_id'] ?? '' );
			} ) );
			$res['total'] = count( $res['items'] );
		}
		return $res;
	}

	public static function track_set( array $args, array $ctx ) {
		$owner        = self::owner( $ctx );
		$subject_hash = self::owner_subject();
		$unit_id  = (string) ( $args['unit_id'] ?? '' );
		$doc_date = BizCity_Journal_Database::sanitize_doc_date( $args['doc_date'] ?? '' ) ?: gmdate( 'Y-m-d' );
		$key      = 'track:' . get_current_blog_id() . ':' . $subject_hash . ':' . ( '' !== $unit_id ? $unit_id : '-' ) . ':' . $doc_date;
		$metrics  = is_array( $args['metrics'] ?? null ) ? $args['metrics'] : array();

		$existing = BizCity_Journal_Index::get_by_idempotency_key( $key, $owner );
		if ( $existing ) {
			$merged = array_merge( is_array( $existing['card']['metrics'] ?? null ) ? $existing['card']['metrics'] : array(), $metrics );
			$r = BizCity_Journal_Index::file_by_idempotency_key( $key, $owner, array( 'card' => array( 'unit_id' => $unit_id ?: null, 'metrics' => $merged ) ) );
			return is_wp_error( $r ) ? self::err( $r ) : array( 'entry_id' => (int) $r['entry_id'], 'metrics' => $merged );
		}
		$row = BizCity_Journal_Index::add( array(
			'owner_user_id'   => $owner,
			'kind'            => 'track.point',
			'title'           => 'Theo dõi ' . $doc_date,
			'doc_date'        => $doc_date,
			'subject_hash'    => $subject_hash,
			'card'            => array( 'unit_id' => $unit_id ?: null, 'metrics' => $metrics ),
			'source_type'     => 'track',
			'idempotency_key' => $key,
			'by'              => array( 'role' => (string) ( $ctx['role'] ?? 'owner' ) ),
		) );
		return is_wp_error( $row ) ? self::err( $row ) : array( 'entry_id' => (int) $row['entry_id'], 'metrics' => $metrics );
	}

	/* ── PHASE-0.133: entry-meta · evidence · vault ──────────────────── */

	/**
	 * [2026-10-24 05:47 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.133 (doc 20 §5, doc 81 §5, doc 70 §4) — the five 0.133 doors: journal.meta.set (EM-4, the one
	 * write door of metadata.meta), journal.evidence.record (EV- rows), vault.get / vault.materialize / vault.revision (the Readable Brain, by logical ref).
	 * System tools of the cell: the model reads through journal.get view=card / vault.get; it never writes meta or evidence itself.
	 */
	private static function register_133_tools( string $R, array $entry_props ): void {
		$ref_any = array( 'type' => 'string', 'maxLength' => 40, 'description' => 'Mã dòng "TL-47", mã bản "TL-47@v2" hoặc bản đọc "vault://TL-47/v2/r1".' );

		// @mcp bizcity-mcp-standard@1 tool journal.meta.set
		$R::register( 'journal.meta.set', array(
			'title'          => 'Ghi thông tin đọc được của một dòng',
			'description'    => 'Cell ghi phần ĐỌC ĐƯỢC của MỘT dòng (entry-meta@1): mô tả ngắn (about), chú thích, chữ trên ảnh (ocr), từ khoá, chủ đề, người, số tiền nhìn thấy. '
				. 'Trường lạ hoặc quá dài ⇒ entry_meta_invalid (không cắt ngầm). Trường chủ đã sửa tay (locked) được giữ nguyên ở lượt tự động. Bản gốc không bao giờ bị sửa. '
				. 'Kèm `candidates` = số liệu đề xuất (chưa ghi sổ; người duyệt mới thành dòng thật). Công cụ hệ thống của cell.',
			'input_schema'   => array( 'type' => 'object', 'required' => array( 'meta' ), 'properties' => array(
				'ref'          => array( 'type' => 'string', 'maxLength' => 16, 'pattern' => '^\\s*[A-Za-z]{2}-[0-9]{1,12}\\s*$' ),
				'entry_id'     => array( 'type' => 'integer', 'minimum' => 1 ),
				'meta'         => array( 'type' => 'object', 'description' => 'entry-meta@1: {title?, about?, caption?, ocr?{text, lang?, confidence?}, keywords?[], topics?[], entities?[], people?[], amounts?[], status?, error?}' ),
				'candidates'   => array( 'type' => 'array', 'maxItems' => 30, 'items' => array( 'type' => 'object' ) ),
				'merge'        => array( 'type' => 'boolean', 'description' => 'true (mặc định) = gộp vào phần đã có; false = thay toàn bộ (trừ trường đã khoá).' ),
				'expected_rev' => array( 'type' => 'integer', 'minimum' => 0 ),
				'source'       => array( 'type' => 'string', 'enum' => array( 'cell_auto', 'cell_tool' ) ),
			) ),
			'output_schema'  => $R::envelope_schema( $entry_props, array( 'entry_id', 'kind' ) ),
			'read_only'      => false,
			'destructive'    => false,
			'idempotent'     => true,
			'required_scope' => 'memory.write',
			'required_cap'   => 'edit_posts',
			'handler'        => array( __CLASS__, 'meta_set' ),
			'mode'           => 'notebook',
			'roles'          => array( 'owner', 'staff' ),
			'scopes'         => array( 'memory.write' ),
			'confirm'        => 'never',
			'llm_alias'      => 'journal_meta_set',
			'brain'          => array( 'tier' => 'L1', 'permission' => 'write', 'crud' => 'write', 'answer_hint' => 'instant' ),
			'fallback_pack'  => null,
			'since'          => '0.133.0',
		) );

		// @mcp bizcity-mcp-standard@1 tool journal.evidence.record
		$R::register( 'journal.evidence.record', array(
			'title'          => 'Ghi bằng chứng đăng / gửi',
			'description'    => 'Ghi MỘT bằng chứng (dòng EV-) sau khi đăng bài hoặc gửi tài liệu: nền tảng, tài khoản, mã gốc của nền tảng (giữ nguyên, không tự tạo), liên kết, cách xác minh. '
				. 'Trùng (cùng tài khoản + mã gốc) ⇒ trả lại dòng cũ. "verified" cần mã gốc + cách xác minh; thiếu ⇒ hạ xuống unknown / pending. Công cụ hệ thống của cell.',
			'input_schema'   => array( 'type' => 'object', 'required' => array( 'evidence' ), 'properties' => array(
				'evidence' => array( 'type' => 'object', 'description' => 'external-publication-evidence@1 hoặc delivery-evidence@1 (PHASE-0.133 doc 70 §4).' ),
				'job'      => array( 'type' => 'object', 'properties' => array(
					'job_id'   => array( 'type' => 'string', 'pattern' => '^job_[0-9A-Za-z]{10,40}$' ),
					'step_ref' => array( 'type' => 'string', 'maxLength' => 40 ),
					'actor'    => array( 'type' => 'object' ),
				) ),
			) ),
			'output_schema'  => $R::envelope_schema( $entry_props, array( 'entry_id', 'kind' ) ),
			'read_only'      => false,
			'destructive'    => false,
			'idempotent'     => true,
			'required_scope' => 'memory.write',
			'required_cap'   => 'edit_posts',
			'handler'        => array( __CLASS__, 'evidence_record' ),
			'mode'           => 'notebook',
			'roles'          => array( 'owner', 'staff' ),
			'scopes'         => array( 'memory.write' ),
			'confirm'        => 'never',
			'llm_alias'      => 'journal_evidence_record',
			'brain'          => array( 'tier' => 'L1', 'permission' => 'write', 'crud' => 'write', 'answer_hint' => 'instant' ),
			'fallback_pack'  => null,
			'since'          => '0.133.0',
		) );

		// @mcp bizcity-mcp-standard@1 tool vault.get
		$R::register( 'vault.get', array(
			'title'          => 'Đọc bản Markdown của một nguồn',
			'description'    => 'Đọc MỘT ghi chú của Kho tri thức (Twin Vault): bản đọc Markdown của một tài liệu / ảnh (ref TL-47) hoặc một ghi chú / kỹ năng (id lib:/note/x.md), kèm frontmatter (mã nguồn, bản, cách trích). '
				. 'Dài thì đọc theo trang: offset + limit (≤ 6000 ký tự), next_offset = null là hết. Chưa có bản đọc ⇒ vault_not_found (dùng journal.get view=card để xem mô tả). Không trả link tải.',
			'input_schema'   => array( 'type' => 'object', 'properties' => array(
				'ref'    => $ref_any,
				'id'     => array( 'type' => 'string', 'maxLength' => 200, 'description' => 'Id của Kho tri thức: lib:/note/x.md (ghi chú / kỹ năng) hoặc src:TL-47. Dùng ref hoặc id.' ), // [2026-10-24 09:04 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.134 S134-L4
				'rev'    => array( 'type' => 'integer', 'minimum' => 0, 'description' => '0 = bản đọc mới nhất.' ),
				'offset' => array( 'type' => 'integer', 'minimum' => 0, 'description' => 'Đọc tiếp từ ký tự này (lấy next_offset của lần trước).' ),
				'limit'  => array( 'type' => 'integer', 'minimum' => 500, 'maximum' => 6000, 'description' => 'Số ký tự mỗi trang (500–6000).' ),
			) ),
			'output_schema'  => $R::envelope_schema( array(
				'ref'         => array( 'type' => 'string' ),
				'rev'         => array( 'type' => 'integer' ),
				'markdown'    => array( 'type' => 'string' ),
				'frontmatter' => array( 'type' => 'object' ),
				'next_offset' => array( 'type' => array( 'integer', 'null' ) ),
			), array( 'ref', 'markdown' ) ),
			'read_only'      => true,
			'idempotent'     => true,
			'required_scope' => 'brain.read',
			'handler'        => array( __CLASS__, 'vault_get' ),
			'mode'           => 'notebook',
			'roles'          => array( 'owner', 'staff' ),
			'scopes'         => array( 'brain.read' ),
			'confirm'        => 'never',
			'llm_alias'      => 'vault_get',
			'brain'          => array( 'tier' => 'L2', 'permission' => 'content.read', 'crud' => 'read', 'answer_hint' => 'instant' ),
			'fallback_pack'  => null,
			'since'          => '0.133.0',
		) );

		// @mcp bizcity-mcp-standard@1 tool vault.materialize
		$R::register( 'vault.materialize', array(
			'title'          => 'Lưu bản Markdown của một nguồn',
			'description'    => 'Cell lưu bản đọc (Markdown) nó trích từ MỘT nguồn: body_md = lời của nguồn (giữ nguyên), ai_notes = ghi chú của AI (để riêng, không lẫn vào bản gốc). '
				. 'Site tự dựng frontmatter từ dòng sổ (mã, bản, quyền thừa hưởng từ nguồn) — mọi khoá quyền trong body bị bỏ. Cùng nội dung ⇒ không tạo bản mới. Công cụ hệ thống của cell.',
			'input_schema'   => array( 'type' => 'object', 'required' => array( 'ref', 'body_md' ), 'properties' => array(
				'ref'        => $ref_any,
				'body_md'    => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 400000 ),
				'ai_notes'   => array( 'type' => 'string', 'maxLength' => 8000 ),
				'extraction' => array( 'type' => 'object', 'properties' => array(
					'kind'     => array( 'type' => 'string', 'maxLength' => 32 ),
					'engine'   => array( 'type' => 'string', 'maxLength' => 32 ),
					'status'   => array( 'type' => 'string', 'enum' => array( 'pending', 'completed', 'failed', 'skipped' ) ),
					'reviewed' => array( 'type' => 'boolean' ),
					'run_id'   => array( 'type' => 'string', 'maxLength' => 64 ),
				) ),
				'sidecar'    => array( 'type' => 'object', 'description' => 'Bản đồ trang / vùng (page_anchor) của bản đọc, lưu kèm .map.json.' ),
			) ),
			'output_schema'  => $R::envelope_schema( array(
				'ref'            => array( 'type' => 'string' ),
				'rev'            => array( 'type' => 'integer' ),
				'content_sha256' => array( 'type' => 'string' ),
				'unchanged'      => array( 'type' => 'boolean' ),
			), array( 'ref', 'rev' ) ),
			'read_only'      => false,
			'destructive'    => false,
			'idempotent'     => true,
			'required_scope' => 'memory.write',
			'required_cap'   => 'edit_posts',
			'handler'        => array( __CLASS__, 'vault_materialize' ),
			'mode'           => 'notebook',
			'roles'          => array( 'owner', 'staff' ),
			'scopes'         => array( 'memory.write' ),
			'confirm'        => 'never',
			'llm_alias'      => 'vault_materialize',
			'brain'          => array( 'layer' => 'document', 'permission' => 'write', 'crud' => 'write', 'answer_hint' => 'instant' ),
			'fallback_pack'  => null,
			'since'          => '0.133.0',
		) );

		// @mcp bizcity-mcp-standard@1 tool vault.revision
		$R::register( 'vault.revision', array(
			'title'          => 'Các lần trích của một nguồn',
			'description'    => 'Liệt kê các bản đọc (rev) đã lưu của MỘT nguồn, mới nhất trước: mã vault://…, cách trích, đã duyệt chưa, lúc nào; và trạng thái chỉ mục tri thức (ready / stale). KHÔNG trả nội dung bản đọc (dùng vault.get).',
			'input_schema'   => array( 'type' => 'object', 'required' => array( 'ref' ), 'properties' => array( 'ref' => $ref_any ) ),
			'output_schema'  => $R::envelope_schema( array(
				'source_ref' => array( 'type' => 'string' ),
				'revisions'  => array( 'type' => 'array' ),
				'knowledge'  => array( 'type' => 'object' ),
			), array( 'revisions' ) ),
			'read_only'      => true,
			'idempotent'     => true,
			'required_scope' => 'brain.read',
			'handler'        => array( __CLASS__, 'vault_revision' ),
			'mode'           => 'notebook',
			'roles'          => array( 'owner', 'staff' ),
			'scopes'         => array( 'brain.read' ),
			'confirm'        => 'never',
			'llm_alias'      => 'vault_revision',
			'brain'          => array( 'tier' => 'L1', 'permission' => 'meta.read', 'crud' => 'read', 'answer_hint' => 'instant' ),
			'fallback_pack'  => null,
			'since'          => '0.133.0',
		) );
	}

	/** "TL-47" · "TL-47@v2" · "vault://TL-47/v2/r1" ⇒ {ref, rev}; null when none of them. Pure. */
	public static function parse_any_ref( string $s ): ?array {
		$s = trim( $s );
		if ( class_exists( 'BizCity_Brain_Resource_Link' ) ) {
			$v = BizCity_Brain_Resource_Link::parse_vault_ref( $s );
			if ( $v ) {
				return array( 'ref' => $v['ref'], 'rev' => $v['rev'] );
			}
			$sr = BizCity_Brain_Resource_Link::parse_source_ref( $s );
			if ( $sr ) {
				return array( 'ref' => $sr['ref'], 'rev' => 0 );
			}
		}
		return 1 === preg_match( '/^[A-Za-z]{2}-\d{1,12}$/', $s ) ? array( 'ref' => strtoupper( $s ), 'rev' => 0 ) : null;
	}

	/**
	 * [2026-10-24 07:02 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.133 (selfcheck T133 #6) — the target of a vault.* call: `entry_id` (the REST door already holds the row) wins over a code, because
	 * TL-<n> resolves through the source_ref column and older file rows carry another source_ref. null when neither is usable. Pure.
	 */
	public static function vault_target( array $args ): ?array {
		if ( (int) ( $args['entry_id'] ?? 0 ) > 0 ) {
			return array( 'target' => array( 'entry_id' => (int) $args['entry_id'] ), 'rev' => 0 );
		}
		$p = self::parse_any_ref( (string) ( $args['ref'] ?? '' ) );
		return null === $p ? null : array( 'target' => array( 'ref' => $p['ref'] ), 'rev' => $p['rev'] );
	}

	/** A row the principal may `$permission` (decided at the source); WP_Error 404 otherwise. */
	private static function row_for( array $target, array $ctx, string $permission ) {
		$owner = self::owner( $ctx );
		$row   = '' !== (string) ( $target['ref'] ?? '' ) ? BizCity_Journal_Index::find_by_ref( (string) $target['ref'], $owner ) : BizCity_Journal_Index::get( (int) ( $target['entry_id'] ?? 0 ), $owner );
		if ( ! $row ) {
			return self::err( new WP_Error( 'journal_not_found', 'Không có dòng này trong sổ của chủ. Tìm lại bằng journal.find để lấy mã đúng.' ) );
		}
		if ( class_exists( 'BizCity_Brain_Access' ) ) {
			$d = BizCity_Brain_Access::decide( BizCity_Brain_Access::principal( $ctx ), $row, $permission );
			if ( ! $d['allow'] ) {
				return self::err( new WP_Error( 'journal_not_found', 'Không tìm thấy mục nhật ký.' ) );
			}
		}
		return $row;
	}

	public static function meta_set( array $args, array $ctx ) {
		if ( ! class_exists( 'BizCity_Journal_Entry_Meta' ) ) {
			return self::err( new WP_Error( 'journal_unavailable', 'Sổ chưa sẵn sàng trên site này.' ) );
		}
		$row = self::row_for( $args, $ctx, 'write' );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		$owner = self::owner( $ctx );
		$out   = BizCity_Journal_Entry_Meta::set( $owner, array( 'entry_id' => (int) $row['entry_id'] ), is_array( $args['meta'] ?? null ) ? $args['meta'] : array(), array(
			'merge'        => false !== ( $args['merge'] ?? true ),
			'expected_rev' => (int) ( $args['expected_rev'] ?? 0 ),
			'source'       => 'cell_auto' === (string) ( $args['source'] ?? '' ) ? 'cell_auto' : 'cell_tool', // owner_edit only through the REST door (a person's hand)
		) );
		if ( is_wp_error( $out ) ) {
			return self::err( $out );
		}
		if ( is_array( $args['candidates'] ?? null ) ) {
			$c = BizCity_Journal_Index::file( (int) $row['entry_id'], $owner, array( 'candidates' => $args['candidates'] ) );
			if ( ! is_wp_error( $c ) ) {
				$out = $c;
			}
		}
		return $out;
	}

	public static function evidence_record( array $args, array $ctx ) {
		if ( ! class_exists( 'BizCity_Journal_Evidence' ) ) {
			return self::err( new WP_Error( 'journal_unavailable', 'Sổ chưa sẵn sàng trên site này.' ) );
		}
		$row = BizCity_Journal_Evidence::record( self::owner( $ctx ), is_array( $args['evidence'] ?? null ) ? $args['evidence'] : array(), is_array( $args['job'] ?? null ) ? $args['job'] : array() );
		return is_wp_error( $row ) ? self::err( $row ) : $row;
	}

	public static function vault_get( array $args, array $ctx ) {
		// [2026-10-24 09:04 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.134 S134-L4 — a Twin Vault library note (lib:/…) is read by BizCity_Twin_Vault::note(); src:TL-47 reads like ref TL-47
		if ( 0 === strpos( (string) ( $args['id'] ?? '' ), 'lib:' ) && class_exists( 'BizCity_Twin_Vault' ) ) {
			$note = BizCity_Twin_Vault::note( (string) $args['id'], $ctx, (int) ( $args['offset'] ?? 0 ), (int) ( $args['limit'] ?? 6000 ) );
			return is_wp_error( $note ) ? self::err( new WP_Error( 'journal_not_found', $note->get_error_message() ) ) : $note;
		}
		if ( '' === (string) ( $args['ref'] ?? '' ) && 0 === strpos( (string) ( $args['id'] ?? '' ), 'src:' ) ) {
			$args['ref'] = substr( (string) $args['id'], 4 );
		}
		$p = self::vault_target( $args );
		if ( null === $p || ! class_exists( 'BizCity_Brain_Vault_Store' ) ) {
			return self::err( new WP_Error( 'journal_not_found', 'Cần mã dạng "TL-47", "TL-47@v2" hoặc "vault://TL-47/v2/r1".' ) );
		}
		$row = self::row_for( $p['target'], $ctx, 'content.read' );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		$rev = isset( $args['rev'] ) ? (int) $args['rev'] : $p['rev'];
		$out = BizCity_Brain_Vault_Store::read( $row, max( 0, $rev ), (int) ( $args['offset'] ?? 0 ), (int) ( $args['limit'] ?? 6000 ) );
		if ( is_wp_error( $out ) ) {
			$data = (array) $out->get_error_data();
			return new WP_Error( class_exists( 'BizCity_MCP_Error' ) && defined( 'BizCity_MCP_Error::NOT_FOUND' ) ? BizCity_MCP_Error::NOT_FOUND : 'not_found', $out->get_error_message(), array( 'status' => 404, 'reason' => $out->get_error_code(), 'hint' => (string) ( $data['hint'] ?? '' ) ) );
		}
		$out['source_ref'] = (string) ( $row['resource']['source_ref'] ?? '' );
		return $out;
	}

	public static function vault_materialize( array $args, array $ctx ) {
		$p = self::vault_target( $args );
		if ( null === $p || ! class_exists( 'BizCity_Brain_Vault_Store' ) || ! class_exists( 'BizCity_Brain_Resource_Link' ) ) {
			return self::err( new WP_Error( 'journal_not_found', 'Cần mã dạng "TL-47" hoặc "TL-47@v2".' ) );
		}
		$row = self::row_for( $p['target'], $ctx, 'write' );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		$res    = is_array( $row['resource'] ?? null ) ? $row['resource'] : array();
		$latest = BizCity_Brain_Resource_Link::latest_representation( $res );
		$parts  = array(
			'body_md'    => (string) ( $args['body_md'] ?? '' ),
			'ai_notes'   => (string) ( $args['ai_notes'] ?? '' ),
			'extraction' => is_array( $args['extraction'] ?? null ) ? $args['extraction'] : array(),
			'sidecar'    => is_array( $args['sidecar'] ?? null ) ? $args['sidecar'] : null,
		);
		if ( $latest && hash( 'sha256', BizCity_Brain_Vault_Store::compose( $row, (int) $latest['rev'], $parts ) ) === (string) $latest['content_sha256'] ) {
			return array( 'ref' => (string) $latest['ref'], 'rev' => (int) $latest['rev'], 'content_sha256' => (string) $latest['content_sha256'], 'unchanged' => true );
		}
		$rev     = $latest ? (int) $latest['rev'] + 1 : 1;
		$written = BizCity_Brain_Vault_Store::write( $row, $rev, $parts );
		if ( is_wp_error( $written ) ) {
			return self::err( $written );
		}
		$saved = BizCity_Journal_Index::file( (int) $row['entry_id'], self::owner( $ctx ), array( 'representation' => array(
			'ref'            => $written['ref'],
			'content_sha256' => $written['content_sha256'],
			'extract'        => $parts['extraction'],
			'at'             => gmdate( 'Y-m-d\TH:i:s\Z' ),
		) ) );
		if ( is_wp_error( $saved ) ) {
			return self::err( $saved );
		}
		return array( 'ref' => $written['ref'], 'rev' => $rev, 'content_sha256' => $written['content_sha256'], 'unchanged' => false, 'source_ref' => (string) ( $saved['resource']['source_ref'] ?? '' ) );
	}

	public static function vault_revision( array $args, array $ctx ) {
		$p = self::vault_target( $args );
		if ( null === $p || ! class_exists( 'BizCity_Brain_Vault_Store' ) ) {
			return self::err( new WP_Error( 'journal_not_found', 'Cần mã dạng "TL-47" hoặc "TL-47@v2".' ) );
		}
		$row = self::row_for( $p['target'], $ctx, 'meta.read' );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		return array(
			'source_ref' => (string) ( $row['resource']['source_ref'] ?? '' ),
			'revisions'  => BizCity_Brain_Vault_Store::revisions( $row ),
			'knowledge'  => (array) ( $row['resource']['knowledge'] ?? array() ),
		);
	}

	/* ── shared ──────────────────────────────────────────────────────── */

	private static function owner( array $ctx ): int {
		return (int) ( $ctx['owner_user_id'] ?? $ctx['user_id'] ?? 0 );
	}

	/** Service error → MCP catalog error (code + HTTP status), message kept, no path/SQL inside. */
	private static function err( WP_Error $e ): WP_Error {
		$code   = (string) $e->get_error_code();
		$data   = $e->get_error_data();
		$status = 'journal_not_found' === $code || 'journal_derive_source_missing' === $code || 'contact_not_found' === $code || 'access_denied' === $code ? 404 : ( is_array( $data ) && ! empty( $data['status'] ) ? (int) $data['status'] : 400 );
		$mcp    = class_exists( 'BizCity_MCP_Error' ) && defined( 'BizCity_MCP_Error::QUERY_INVALID' ) ? BizCity_MCP_Error::QUERY_INVALID : 'query_invalid';
		return new WP_Error( $mcp, $e->get_error_message(), array( 'status' => $status, 'reason' => $code ) );
	}
}

// [2026-10-12 11:50 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.100 S100-L19 — self-registers on load, same pattern
// as class-memory-mcp-service.php (file bottom `::init()`), so the mcp/bootstrap.php require is enough.
BizCity_Journal_MCP_Tools::init();
