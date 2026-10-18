<?php
/**
 * BizCity_Journal_MCP_Tools — `journal.*` + `track.*` (PHASE-0.100 S100-L19, lanes/INTERFACES.md I-5, doc 92 §6).
 *
 * | Tool | Kind | Who | Confirm |
 * |---|---|---|---|
 * | journal.find / journal.get / journal.summarize | read | owner, staff | never |
 * | journal.file | write, idempotent | owner, staff | never |
 * | journal.derive | write | owner, staff | always + preview |
 * | journal.record / journal.mirror | write, idempotent — CELL-system tools (the model never sees them), PHASE-0.117 S117-K1a | owner, staff | never |
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
			'description'    => 'Tìm mục nhật ký đã lưu (bài tập, bữa ăn, đơn hàng, hợp đồng…) theo loại, tag, người, khoảng ngày; lọc có cấu trúc trên chỉ mục trước, chữ tìm (q) chỉ lọc thêm tiêu đề.',
			'input_schema'   => array( 'type' => 'object', 'properties' => array(
				'kind'         => array( 'type' => 'string', 'maxLength' => 48 ),
				'kinds'        => array( 'type' => 'array', 'items' => array( 'type' => 'string', 'maxLength' => 48 ), 'maxItems' => 10 ),
				'kind_prefix'  => array( 'type' => 'string', 'maxLength' => 32 ),
				'tags'         => array( 'type' => 'array', 'items' => array( 'type' => 'string', 'maxLength' => 40 ), 'maxItems' => 5 ),
				'subject_hash' => array( 'type' => 'string', 'maxLength' => 18 ),
				'from'         => array( 'type' => 'string', 'maxLength' => 10 ),
				'to'           => array( 'type' => 'string', 'maxLength' => 10 ),
				'q'            => array( 'type' => 'string', 'maxLength' => 120 ),
				'limit'        => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 20 ),
				'offset'       => array( 'type' => 'integer', 'minimum' => 0 ),
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
			'description'    => 'Trả toàn bộ một mục: thẻ đầy đủ, nguồn gốc (loại file, kích thước), liên kết sổ. Không trả link file gốc ký hạn ở đây (dùng để lấy nội dung, gửi lại qua đường file có sẵn).',
			'input_schema'   => array( 'type' => 'object', 'required' => array( 'entry_id' ), 'properties' => array( 'entry_id' => array( 'type' => 'integer', 'minimum' => 1 ) ) ),
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
			'description'    => 'Đổi kind, tag, subject_hash, ngày tài liệu, ghim hoặc đánh dấu đã xem của một mục đã có — dữ liệu của chính chủ, rủi ro thấp, không cần xác nhận (như skills.propose).',
			'input_schema'   => array( 'type' => 'object', 'required' => array( 'entry_id' ), 'properties' => array(
				'entry_id'     => array( 'type' => 'integer', 'minimum' => 1 ),
				'kind'         => array( 'type' => 'string', 'maxLength' => 48 ),
				'tags'         => array( 'type' => 'array', 'items' => array( 'type' => 'string', 'maxLength' => 40 ), 'maxItems' => 12 ),
				'subject_hash' => array( 'type' => 'string', 'maxLength' => 18 ),
				'doc_date'     => array( 'type' => 'string', 'maxLength' => 10 ),
				'pinned'       => array( 'type' => 'boolean' ),
				'review'       => array( 'type' => 'boolean' ),
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

		// @mcp bizcity-mcp-standard@1 tool journal.summarize
		$R::register( 'journal.summarize', array(
			'title'          => 'Gộp số liệu nhật ký',
			'description'    => 'Gộp tất định (tổng/trung bình/đếm) một trường số trong thẻ, theo ngày/tuần/tháng — ví dụ tổng kcal theo ngày, tổng giá trị đơn theo tháng. Không dùng model.',
			'input_schema'   => array( 'type' => 'object', 'required' => array( 'field' ), 'properties' => array(
				'kind'         => array( 'type' => 'string', 'maxLength' => 48 ),
				'kinds'        => array( 'type' => 'array', 'items' => array( 'type' => 'string', 'maxLength' => 48 ), 'maxItems' => 10 ),
				'subject_hash' => array( 'type' => 'string', 'maxLength' => 18 ),
				'from'         => array( 'type' => 'string', 'maxLength' => 10 ),
				'to'           => array( 'type' => 'string', 'maxLength' => 10 ),
				'field'        => array( 'type' => 'string', 'maxLength' => 80 ),
				'group_by'     => array( 'type' => 'string', 'enum' => array( 'day', 'week', 'month' ) ),
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
			'description'    => 'Lưu một sản phẩm làm từ tài liệu gốc (bài đăng nháp, thực đơn, hợp đồng nháp…) thành mục mới, luôn trỏ về mục gốc (derived_from) — không ghi đè gốc (R-JOURNAL R-JN-10). Lần gọi đầu chỉ trả bản xem trước + confirm_token.',
			'input_schema'   => array( 'type' => 'object', 'required' => array( 'kind', 'title', 'derived_from' ), 'properties' => array(
				'kind'         => array( 'type' => 'string', 'maxLength' => 48, 'pattern' => '\\.output$' ),
				'title'        => array( 'type' => 'string', 'maxLength' => 255 ),
				'card'         => array( 'type' => 'object' ),
				'subject_hash' => array( 'type' => 'string', 'maxLength' => 18 ),
				'tags'         => array( 'type' => 'array', 'items' => array( 'type' => 'string', 'maxLength' => 40 ), 'maxItems' => 12 ),
				'derived_from' => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ), 'minItems' => 1, 'maxItems' => 20 ),
				'confirm_token'=> array( 'type' => 'string' ),
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
			'description'    => 'Đọc chuỗi theo dõi (track.point) của chính chủ trong tối đa 90 ngày — dùng cho bước Tổng kết của chương trình coach (doc 90 §5). Chỉ có một tiến độ của chủ, không theo từng người.',
			'input_schema'   => array( 'type' => 'object', 'properties' => array(
				'unit_id'      => array( 'type' => 'string', 'maxLength' => 40 ),
				'days'         => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 90 ),
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
			'description'    => 'Ghi (hoặc gộp thêm) các chỉ số theo dõi của một ngày cho chính chủ — idempotent theo (unit_id, ngày), gọi lại cùng ngày sẽ gộp thêm chỉ số, không tạo dòng mới.',
			'input_schema'   => array( 'type' => 'object', 'required' => array( 'metrics' ), 'properties' => array(
				'unit_id'      => array( 'type' => 'string', 'maxLength' => 40 ),
				'metrics'      => array( 'type' => 'object' ),
				'doc_date'     => array( 'type' => 'string', 'maxLength' => 10 ),
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
		$filter = $args;
		$filter['owner_user_id'] = self::owner( $ctx );
		return BizCity_Journal_Index::find( $filter );
	}

	public static function get( array $args, array $ctx ) {
		$row = BizCity_Journal_Index::get( (int) ( $args['entry_id'] ?? 0 ), self::owner( $ctx ) );
		if ( ! $row ) {
			return self::err( new WP_Error( 'journal_not_found', 'Không tìm thấy mục nhật ký.' ) );
		}
		return $row;
	}

	public static function file( array $args, array $ctx ) {
		$patch = array_intersect_key( $args, array_flip( array( 'kind', 'tags', 'subject_hash', 'doc_date', 'pinned', 'review' ) ) );
		$r = BizCity_Journal_Index::file( (int) ( $args['entry_id'] ?? 0 ), self::owner( $ctx ), $patch );
		return is_wp_error( $r ) ? self::err( $r ) : $r;
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
		return is_wp_error( $row ) ? self::err( $row ) : array( 'status' => 'done', 'entry_id' => (int) $row['entry_id'] );
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
				'mirror'          => array( 'sheet' => 'pending' ),
				'by'              => array( 'role' => (string) ( $ctx['role'] ?? 'owner' ) ),
			) );
			if ( is_wp_error( $row ) ) {
				return self::err( $row );
			}
			$out[] = array( 'idempotency_key' => $sent, 'entry_id' => (int) $row['entry_id'], 'status' => $had ? 'exists' : 'created' );
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
		$status = 'journal_not_found' === $code || 'journal_derive_source_missing' === $code ? 404 : 400;
		$mcp    = class_exists( 'BizCity_MCP_Error' ) && defined( 'BizCity_MCP_Error::QUERY_INVALID' ) ? BizCity_MCP_Error::QUERY_INVALID : 'query_invalid';
		return new WP_Error( $mcp, $e->get_error_message(), array( 'status' => $status, 'reason' => $code ) );
	}
}

// [2026-10-12 11:50 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.100 S100-L19 — self-registers on load, same pattern
// as class-memory-mcp-service.php (file bottom `::init()`), so the mcp/bootstrap.php require is enough.
BizCity_Journal_MCP_Tools::init();
