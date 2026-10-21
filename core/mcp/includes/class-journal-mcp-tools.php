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

if ( class_exists( 'BizCity_Journal_MCP_Tools', false ) ) {
	return;
}

final class BizCity_Journal_MCP_Tools {

	const SINCE = '0.100.0';

	private static $hooked = false;

	public static function init(): void {
		if ( self::$hooked ) {
			return;
		}
		self::$hooked = true;
		add_action( 'bizcity_mcp_register_tools', array( __CLASS__, 'register_tools' ) );
	}

	public static function register_tools(): void {
		if ( ! class_exists( 'BizCity_MCP_Tool_Registry' ) || ! class_exists( 'BizCity_Journal_Index', false ) ) {
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
			) ),
			'output_schema'  => $R::envelope_schema( array( 'items' => array( 'type' => 'array', 'items' => array( 'type' => 'object', 'properties' => $entry_props ) ), 'total' => array( 'type' => 'integer' ) ), array( 'items', 'total' ) ),
			'read_only'      => true,
			'idempotent'     => true,
			'required_scope' => 'brain.read',
			'handler'        => array( __CLASS__, 'find' ),
			'mode'           => 'notebook',
			'roles'          => array( 'owner', 'staff' ),
			'scopes'         => array( 'brain.read' ),
			'confirm'        => 'never',
			'llm_alias'      => 'journal_find',
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
			) ),
			'output_schema'  => $R::envelope_schema( $entry_props, array( 'entry_id', 'kind' ) ),
			'read_only'      => true,
			'idempotent'     => true,
			'required_scope' => 'brain.read',
			'handler'        => array( __CLASS__, 'get' ),
			'mode'           => 'notebook',
			'roles'          => array( 'owner', 'staff' ),
			'scopes'         => array( 'brain.read' ),
			'confirm'        => 'never',
			'llm_alias'      => 'journal_get',
			'fallback_pack'  => null,
			'since'          => self::SINCE,
		) );

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
					'idempotency_key' => array( 'type' => 'string', 'maxLength' => 110, 'pattern' => '^job:[A-Za-z0-9_.-]{3,100}$' ),
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
	}

	/* ── journal.* handlers ──────────────────────────────────────────── */

	public static function find( array $args, array $ctx ) {
		// [2026-10-21 11:58 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.126 ACI (doc 96 F-2) — one code ⇒ that row (0 or 1), through the same access decision
		if ( '' !== trim( (string) ( $args['ref'] ?? '' ) ) ) {
			$row = BizCity_Journal_Index::find_by_ref( (string) $args['ref'], self::owner( $ctx ) );
			if ( $row && class_exists( 'BizCity_Brain_Access' ) ) {
				$row = BizCity_Brain_Access::filter( array( $row ), BizCity_Brain_Access::principal( $ctx ), 'meta.read' )[0] ?? null;
			}
			return $row ? array( 'items' => array( $row ), 'total' => 1 ) : array( 'items' => array(), 'total' => 0 );
		}
		$oid = (string) ( $args['output_id'] ?? '' );
		if ( '' !== $oid ) {
			if ( 1 !== preg_match( '/^co_[a-f0-9]{24}$/', $oid ) ) {
				return array( 'items' => array(), 'total' => 0 );
			}
			$row = BizCity_Journal_Index::get_by_idempotency_key( self::output_key( $oid ), self::owner( $ctx ) );
			return $row ? array( 'items' => array( $row ), 'total' => 1 ) : array( 'items' => array(), 'total' => 0 );
		}
		$filter = $args;
		$filter['owner_user_id'] = self::owner( $ctx );
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
		return $res;
	}

	public static function get( array $args, array $ctx ) {
		// [2026-10-21 11:58 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.126 ACI (doc 96 F-2/F-5) — by code or by id; a miss says what to do next
		$ref = trim( (string) ( $args['ref'] ?? '' ) );
		$row = '' !== $ref ? BizCity_Journal_Index::find_by_ref( $ref, self::owner( $ctx ) ) : BizCity_Journal_Index::get( (int) ( $args['entry_id'] ?? 0 ), self::owner( $ctx ) );
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
		return 1 === preg_match( '/^job:([A-Za-z0-9_.-]{3,100})$/', $raw, $m ) ? 'job:' . get_current_blog_id() . ':' . $m[1] : '';
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
