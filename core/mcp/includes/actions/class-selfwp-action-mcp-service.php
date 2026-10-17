<?php
/**
 * BizCity_SelfWP_Action_MCP_Service — `cf7.form.create` and `wp.landing.draft`: the cell builds a landing page on THIS website
 * (PHASE-0.118 small win). System tools: only the cell's builtin `create_landing_page` calls them (cell `CELL_SYSTEM_MCP_TOOLS`);
 * the model never sees them. Mode `content` (agent-mode-access@1: administrators by default, staff must be granted).
 *
 * `wp.page.publish` (R2, confirm ALWAYS) is the only door that makes such a page public: preview → the person agrees → publish.
 *
 * Rules (doc 20 / 30):
 *  - runs as the WordPress user of the delegated principal (`ctx.user_id`) — `BizCity_MCP_Action_Support::run_as` — and re-checks
 *    that user's capability in the handler (the registry already checked `required_cap`);
 *  - a page is ALWAYS created as `draft` (create ≠ publish; publishing is a later R2 executor with preview → confirm);
 *  - the cell sends `page-spec@1` / `form-spec@1`, never HTML; {@see BizCity_SelfWP_Page_Compiler} turns them into
 *    Flatsome shortcodes (UX Builder can reopen the page) or Gutenberg blocks when Flatsome is not the active theme;
 *  - no LLM, no HTTP, no cache purge; every referenced `media:<id>` / `cf7:<id>` must exist and be allowed for that user.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\MCP
 * @since      2026-10-16 (PHASE-0.118 S118-L5/L6)
 */

defined( 'ABSPATH' ) || exit;

// [2026-10-16 10:50 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.118 S118-L5/L6 — new file, self_wp executors cf7.form.create + wp.landing.draft.
final class BizCity_SelfWP_Action_MCP_Service {

	const TEMPLATE_LANDING = 'page-blank-landingpage.php';
	const META_GENERATED   = '_bizcity_generated';
	const DUP_WINDOW_S     = 900;
	const SNAP_MAX         = 10;
	const SNAP_TTL_S       = 7776000; // 90 ngày (doc 50 §4)

	/**
	 * Test seams, name → callable. Defaults call WordPress / Contact Form 7.
	 *   insert_post(array $postarr): int|WP_Error          · find_duplicate(int $uid, string $hash): int
	 *   active_template(): string (theme slug)             · template_exists(string $file): bool
	 *   user_can(string $cap, ...$args): bool              · media_ok(int $id): bool · form_ok(int $id): bool
	 *   preview_url(int $id): string · edit_url(int $id): string · update_meta(int $id, string $key, array $value)
	 *   user_email(int $uid): string · save_form(array $args): int|WP_Error
	 *   update_post(int $id, array $fields): int|WP_Error
	 *   site_info(): array · list_posts(array {type,status,search,limit,author}): array rows {id,title,status,type,modified,url,generated} · list_forms(int $limit): array rows {id,title}
	 *   get_page(int $id): array|null {id,type,status,title,slug,generated:array|null} · publish_post(int $id): int|WP_Error · public_url(int $id): string
	 *
	 * @var array<string,callable>
	 */
	public static $seams = array();

	public static function init() {
		add_action( 'bizcity_mcp_register_tools', array( __CLASS__, 'register_tools' ) );
	}

	public static function register_tools() {
		$S = 'BizCity_MCP_Tool_Registry';

		if ( self::cf7_available() ) {
			// @mcp bizcity-mcp-standard@1 tool cf7.form.create
			BizCity_MCP_Tool_Registry::register( 'cf7.form.create', array(
				'executor'       => 'cf7.form.create',
				'title'          => 'Tạo form liên hệ (Contact Form 7)',
				'description'    => 'Đồng bộ hệ thống (chỉ zalo-hub gọi): tạo một form Contact Form 7 từ form-spec (tiêu đề, tối đa 8 trường text/email/tel/textarea). Thư báo về email của người yêu cầu. Trả form_id để gắn vào trang. Không gửi email, không công khai gì.',
				'input_schema'   => array( 'type' => 'object', 'required' => array( 'title', 'fields' ), 'properties' => array(
					'title'           => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 120 ),
					'fields'          => array( 'type' => 'array', 'minItems' => 1, 'maxItems' => BizCity_SelfWP_Page_Compiler::MAX_FIELDS, 'items' => array( 'type' => 'object', 'required' => array( 'type', 'name', 'label' ), 'properties' => array(
						'type'     => array( 'type' => 'string', 'enum' => BizCity_SelfWP_Page_Compiler::FIELD_TYPES ),
						'name'     => array( 'type' => 'string', 'pattern' => '^[a-z0-9_-]{1,30}$' ),
						'label'    => array( 'type' => 'string', 'maxLength' => 60 ),
						'required' => array( 'type' => 'boolean' ),
					) ) ),
					'recipient_email' => array( 'type' => 'string', 'maxLength' => 100 ), // [2026-10-17 01:54 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.118 Q118-4: bỏ trống = email của người yêu cầu
					'submit_label'    => array( 'type' => 'string', 'maxLength' => 30 ),
					'success_message' => array( 'type' => 'string', 'maxLength' => 200 ),
				) ),
				'output_schema'  => $S::envelope_schema( array(
					'form_id'          => array( 'type' => 'integer' ),
					'shortcode'        => array( 'type' => 'string' ),
					'recipient_masked' => array( 'type' => 'string' ),
					'recipient_is_requester' => array( 'type' => 'boolean' ),
				), array( 'form_id' ) ),
				'read_only'      => false,
				'destructive'    => false,
				'idempotent'     => false,
				'required_scope' => 'content.write',
				'required_cap'   => 'wpcf7_edit_contact_forms',
				'handler'        => array( __CLASS__, 'form_create' ),
				'mode'           => 'content',
				'scopes'         => array( 'content.write' ),
				'confirm'        => 'never',
				'llm_alias'      => 'cf7_form_create',
				'fallback_pack'  => null,
				'since'          => '0.118.0',
			) );
		}

		// @mcp bizcity-mcp-standard@1 tool wp.landing.draft
		BizCity_MCP_Tool_Registry::register( 'wp.landing.draft', array(
			'executor'       => 'wp.landing.draft',
			'title'          => 'Dựng trang landing (bản nháp)',
			'description'    => 'Đồng bộ hệ thống (chỉ zalo-hub gọi): dựng MỘT trang landing ở trạng thái NHÁP từ page-spec@1 (hero, benefits, text, form, cta). Flatsome ⇒ shortcode UX Builder (mở lại chỉnh tay được), theme khác ⇒ Gutenberg. Không bao giờ công khai; trả preview_url và edit_url. Cùng spec gọi lại trong 15 phút trả lại đúng bản nháp cũ.',
			'input_schema'   => array( 'type' => 'object', 'required' => array( 'spec' ), 'properties' => array(
				'spec'      => array( 'type' => 'object' ),
				'transport' => array( 'type' => 'string', 'enum' => array( 'auto', 'flatsome', 'gutenberg' ), 'default' => 'auto' ),
			) ),
			'output_schema'  => $S::envelope_schema( array(
				'page_id'     => array( 'type' => 'integer' ),
				'title'       => array( 'type' => 'string' ),
				'status'      => array( 'type' => 'string' ),
				'transport'   => array( 'type' => 'string' ),
				'preview_url' => array( 'type' => 'string' ),
				'edit_url'    => array( 'type' => 'string' ),
				'sections'    => array( 'type' => 'integer' ),
				'spec_hash'   => array( 'type' => 'string' ),
				'duplicate'   => array( 'type' => 'boolean' ),
			), array( 'page_id', 'status' ) ),
			'read_only'      => false,
			'destructive'    => false,
			'idempotent'     => false,
			'required_scope' => 'content.write',
			'required_cap'   => 'edit_pages',
			'handler'        => array( __CLASS__, 'landing_draft' ),
			'mode'           => 'content',
			'scopes'         => array( 'content.write' ),
			'confirm'        => 'never',
			'llm_alias'      => 'wp_landing_draft',
			'fallback_pack'  => null,
			'since'          => '0.118.0',
		) );

		// [2026-10-16 11:36 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.118 S118-L7 — the ONLY door that makes a cell-built page public: R2, preview → confirm (token bound to the arguments).
		// @mcp bizcity-mcp-standard@1 tool wp.page.publish
		BizCity_MCP_Tool_Registry::register( 'wp.page.publish', array(
			'executor'       => 'wp.page.publish',
			'title'          => 'Đăng trang landing (công khai)',
			'description'    => 'Đồng bộ hệ thống (chỉ zalo-hub gọi): ĐĂNG công khai một trang NHÁP do trợ lý dựng (wp.landing.draft). Lần đầu chỉ trả bản xem trước + confirm_token, CHƯA đăng; gọi lại ĐÚNG tham số kèm confirm_token sau khi người yêu cầu đồng ý. Chỉ đăng được trang trợ lý đã dựng và đang ở trạng thái nháp.',
			'input_schema'   => array( 'type' => 'object', 'required' => array( 'page_id' ), 'properties' => array(
				'page_id'       => array( 'type' => 'integer', 'minimum' => 1 ),
				'confirm_token' => array( 'type' => 'string' ),
			) ),
			'output_schema'  => $S::envelope_schema( array(
				'status'        => array( 'type' => 'string' ),
				'preview'       => array( 'type' => 'object' ),
				'confirm_token' => array( 'type' => 'string' ),
				'expires_at'    => array( 'type' => 'string' ),
				'page_id'       => array( 'type' => 'integer' ),
				'url'           => array( 'type' => 'string' ),
				'duplicate'     => array( 'type' => 'boolean' ),
			), array( 'status' ) ),
			'read_only'      => false,
			'destructive'    => false,
			'idempotent'     => false,
			'required_scope' => 'content.write',
			'required_cap'   => 'publish_pages',
			'handler'        => array( __CLASS__, 'page_publish' ),
			'preview'        => array( __CLASS__, 'page_publish_preview' ),
			'mode'           => 'content',
			'scopes'         => array( 'content.write' ),
			'confirm'        => 'always',
			'llm_alias'      => 'wp_page_publish',
			'fallback_pack'  => null,
			'since'          => '0.118.0',
		) );

		// [2026-10-17 02:09 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.118 S118-L4 — ba executor chỉ ĐỌC (R0): model thấy trực tiếp (không phải tool hệ thống), mode `content`
		// @mcp bizcity-mcp-standard@1 tool wp.site.info
		BizCity_MCP_Tool_Registry::register( 'wp.site.info', array(
			'executor'       => 'wp.site.info',
			'title'          => 'Thông tin website',
			'description'    => 'Tên, địa chỉ, theme đang dùng, các cách dựng trang khả dụng (Flatsome / Gutenberg), có Contact Form 7 không, và người đang hỏi có những quyền dựng trang nào. Chỉ đọc. Gọi trước khi dựng trang để biết site làm được gì.',
			'input_schema'   => array( 'type' => 'object', 'properties' => new stdClass() ),
			'output_schema'  => $S::envelope_schema( array( 'name' => array( 'type' => 'string' ), 'page_transports' => array( 'type' => 'array' ), 'you_can' => array( 'type' => 'object' ) ), array( 'name' ) ),
			'read_only'      => true,
			'idempotent'     => true,
			'required_scope' => 'content.read',
			'required_cap'   => 'read',
			'handler'        => array( __CLASS__, 'site_info' ),
			'mode'           => 'content',
			'scopes'         => array( 'content.read' ),
			'confirm'        => 'never',
			'llm_alias'      => 'wp_site_info',
			'fallback_pack'  => null,
			'since'          => '0.118.0',
		) );

		// @mcp bizcity-mcp-standard@1 tool wp.content.list
		BizCity_MCP_Tool_Registry::register( 'wp.content.list', array(
			'executor'       => 'wp.content.list',
			'title'          => 'Danh sách trang / bài viết',
			'description'    => 'Liệt kê trang hoặc bài viết (id, tiêu đề, trạng thái, ngày sửa, địa chỉ nếu đã công khai, có phải do trợ lý dựng không). Quản trị thấy tất cả; người khác chỉ thấy của mình. Chỉ đọc, tối đa 20 mục.',
			'input_schema'   => array( 'type' => 'object', 'properties' => array(
				'type'   => array( 'type' => 'string', 'enum' => array( 'page', 'post' ), 'default' => 'page' ),
				'status' => array( 'type' => 'string', 'enum' => array( 'any', 'draft', 'publish', 'pending', 'private' ), 'default' => 'any' ),
				'search' => array( 'type' => 'string', 'maxLength' => 80 ),
				'limit'  => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 20, 'default' => 10 ),
			) ),
			'output_schema'  => $S::envelope_schema( array( 'count' => array( 'type' => 'integer' ), 'items' => array( 'type' => 'array' ) ), array( 'items' ) ),
			'read_only'      => true,
			'idempotent'     => true,
			'required_scope' => 'content.read',
			'required_cap'   => 'edit_pages',
			'handler'        => array( __CLASS__, 'content_list' ),
			'mode'           => 'content',
			'scopes'         => array( 'content.read' ),
			'confirm'        => 'never',
			'llm_alias'      => 'wp_content_list',
			'fallback_pack'  => null,
			'since'          => '0.118.0',
		) );

		if ( self::cf7_available() ) {
			// @mcp bizcity-mcp-standard@1 tool cf7.form.list
			BizCity_MCP_Tool_Registry::register( 'cf7.form.list', array(
				'executor'       => 'cf7.form.list',
				'title'          => 'Danh sách form liên hệ',
				'description'    => 'Liệt kê form Contact Form 7 đã có (id, tiêu đề, shortcode) để gắn vào trang. Chỉ đọc, tối đa 30 form.',
				'input_schema'   => array( 'type' => 'object', 'properties' => array( 'limit' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 30, 'default' => 20 ) ) ),
				'output_schema'  => $S::envelope_schema( array( 'count' => array( 'type' => 'integer' ), 'items' => array( 'type' => 'array' ) ), array( 'items' ) ),
				'read_only'      => true,
				'idempotent'     => true,
				'required_scope' => 'content.read',
				'required_cap'   => 'wpcf7_read_contact_forms',
				'handler'        => array( __CLASS__, 'form_list' ),
				'mode'           => 'content',
				'scopes'         => array( 'content.read' ),
				'confirm'        => 'never',
				'llm_alias'      => 'cf7_form_list',
				'fallback_pack'  => null,
				'since'          => '0.118.0',
			) );
		}

		// [2026-10-17 02:15 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.118 S118-L5 — đọc và sửa TỪNG PHẦN của trang do trợ lý dựng (chỉ bản nháp)
		// @mcp bizcity-mcp-standard@1 tool wp.page.read
		BizCity_MCP_Tool_Registry::register( 'wp.page.read', array(
			'executor'       => 'wp.page.read',
			'title'          => 'Đọc cấu trúc một trang',
			'description'    => 'Đọc các phần (hero, lợi ích, đoạn văn, form, nút gọi) của MỘT trang do trợ lý dựng: trả danh sách phần có số thứ tự (từ 0), bản mô tả đầy đủ và spec_hash. Trang không do trợ lý dựng thì báo needs_human kèm đường dẫn mở trình sửa của WordPress. Chỉ đọc. Gọi trước wp.page.edit.',
			'input_schema'   => array( 'type' => 'object', 'required' => array( 'page_id' ), 'properties' => array( 'page_id' => array( 'type' => 'integer', 'minimum' => 1 ) ) ),
			'output_schema'  => $S::envelope_schema( array( 'page_id' => array( 'type' => 'integer' ), 'sections' => array( 'type' => 'array' ), 'spec' => array( 'type' => 'object' ), 'spec_hash' => array( 'type' => 'string' ), 'editable' => array( 'type' => 'boolean' ) ), array( 'page_id' ) ),
			'read_only'      => true,
			'idempotent'     => true,
			'required_scope' => 'content.read',
			'required_cap'   => 'edit_pages',
			'handler'        => array( __CLASS__, 'page_read' ),
			'mode'           => 'content',
			'scopes'         => array( 'content.read' ),
			'confirm'        => 'never',
			'llm_alias'      => 'wp_page_read',
			'fallback_pack'  => null,
			'since'          => '0.118.0',
		) );

		// @mcp bizcity-mcp-standard@1 tool wp.page.edit
		BizCity_MCP_Tool_Registry::register( 'wp.page.edit', array(
			'executor'       => 'wp.page.edit',
			'title'          => 'Sửa từng phần của trang nháp',
			'description'    => 'Sửa MỘT trang NHÁP do trợ lý dựng bằng danh sách thao tác theo phần (tối đa 10): set_title {value}; update {index, fields} (hero: headline/sub/image_ref/cta · benefits: items · text: title/body · form: title/form_ref · cta: headline/button); add {section, after?}; remove {index}. Đọc trang bằng wp.page.read trước, truyền base_spec_hash để không ghi đè thay đổi của người khác. Trang đã công khai hoặc không do trợ lý dựng thì từ chối / báo needs_human. Không bao giờ công khai trang.',
			'input_schema'   => array( 'type' => 'object', 'required' => array( 'page_id', 'ops' ), 'properties' => array(
				'page_id'        => array( 'type' => 'integer', 'minimum' => 1 ),
				'base_spec_hash' => array( 'type' => 'string', 'maxLength' => 64 ),
				'ops'            => array( 'type' => 'array', 'minItems' => 1, 'maxItems' => BizCity_SelfWP_Page_Compiler::MAX_OPS, 'items' => array( 'type' => 'object', 'required' => array( 'op' ), 'properties' => array(
					'op'      => array( 'type' => 'string', 'enum' => array( 'set_title', 'update', 'add', 'remove' ) ),
					'index'   => array( 'type' => 'integer', 'minimum' => 0 ),
					'after'   => array( 'type' => 'integer', 'minimum' => -1 ),
					'value'   => array( 'type' => 'string', 'maxLength' => 120 ),
					'fields'  => array( 'type' => 'object' ),
					'section' => array( 'type' => 'object' ),
				) ) ),
			) ),
			'output_schema'  => $S::envelope_schema( array( 'page_id' => array( 'type' => 'integer' ), 'changes' => array( 'type' => 'array' ), 'spec_hash' => array( 'type' => 'string' ), 'preview_url' => array( 'type' => 'string' ), 'unchanged' => array( 'type' => 'boolean' ) ), array( 'page_id' ) ),
			'read_only'      => false,
			'destructive'    => false,
			'idempotent'     => true,
			'required_scope' => 'content.write',
			'required_cap'   => 'edit_pages',
			'handler'        => array( __CLASS__, 'page_edit' ),
			'mode'           => 'content',
			'scopes'         => array( 'content.write' ),
			'confirm'        => 'never',
			'llm_alias'      => 'wp_page_edit',
			'fallback_pack'  => null,
			'since'          => '0.118.0',
		) );

		// [2026-10-17 03:45 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.118 S118-L7 — sửa trang ĐANG CÔNG KHAI (R2: xem trước dạng diff → xác nhận) và hoàn tác về snapshot (R2). Doc 20 / 50 §4.

		// @mcp bizcity-mcp-standard@1 tool wp.page.update_live
		BizCity_MCP_Tool_Registry::register( 'wp.page.update_live', array(
			'executor'       => 'wp.page.update_live',
			'title'          => 'Sửa trang đang công khai',
			'description'    => 'Sửa MỘT trang ĐANG CÔNG KHAI do trợ lý dựng, bằng cùng danh sách thao tác như wp.page.edit. Bắt buộc base_spec_hash (lấy từ wp.page.read). Lần đầu chỉ trả bản xem trước (diff theo phần) + confirm_token, CHƯA ghi; gọi lại ĐÚNG tham số kèm confirm_token sau khi người yêu cầu đồng ý. Bản cũ được lưu thành snapshot để hoàn tác bằng wp.page.restore.',
			'input_schema'   => array( 'type' => 'object', 'required' => array( 'page_id', 'ops', 'base_spec_hash' ), 'properties' => array(
				'page_id'        => array( 'type' => 'integer', 'minimum' => 1 ),
				'base_spec_hash' => array( 'type' => 'string', 'maxLength' => 64 ),
				'ops'            => array( 'type' => 'array', 'minItems' => 1, 'maxItems' => BizCity_SelfWP_Page_Compiler::MAX_OPS, 'items' => array( 'type' => 'object', 'required' => array( 'op' ), 'properties' => array(
					'op'      => array( 'type' => 'string', 'enum' => array( 'set_title', 'update', 'add', 'remove' ) ),
					'index'   => array( 'type' => 'integer', 'minimum' => 0 ),
					'after'   => array( 'type' => 'integer', 'minimum' => -1 ),
					'value'   => array( 'type' => 'string', 'maxLength' => 120 ),
					'fields'  => array( 'type' => 'object' ),
					'section' => array( 'type' => 'object' ),
				) ) ),
				'confirm_token'  => array( 'type' => 'string' ),
			) ),
			'output_schema'  => $S::envelope_schema( array( 'status' => array( 'type' => 'string' ), 'preview' => array( 'type' => 'object' ), 'confirm_token' => array( 'type' => 'string' ), 'expires_at' => array( 'type' => 'string' ), 'page_id' => array( 'type' => 'integer' ), 'changes' => array( 'type' => 'array' ), 'snapshot_id' => array( 'type' => 'string' ), 'spec_hash' => array( 'type' => 'string' ), 'url' => array( 'type' => 'string' ) ), array( 'status' ) ),
			'read_only'      => false,
			'destructive'    => false,
			'idempotent'     => false,
			'required_scope' => 'content.write',
			'required_cap'   => 'publish_pages',
			'handler'        => array( __CLASS__, 'page_update_live' ),
			'preview'        => array( __CLASS__, 'page_update_live_preview' ),
			'mode'           => 'content',
			'scopes'         => array( 'content.write' ),
			'confirm'        => 'always',
			'llm_alias'      => 'wp_page_update_live',
			'fallback_pack'  => null,
			'since'          => '0.118.0',
		) );

		// @mcp bizcity-mcp-standard@1 tool wp.page.restore
		BizCity_MCP_Tool_Registry::register( 'wp.page.restore', array(
			'executor'       => 'wp.page.restore',
			'title'          => 'Hoàn tác trang về bản cũ',
			'description'    => 'Đưa MỘT trang do trợ lý dựng về một snapshot cũ (snapshot_id lấy từ wp.page.read → snapshots). Lần đầu chỉ trả bản xem trước (diff) + confirm_token, CHƯA ghi; gọi lại ĐÚNG tham số kèm confirm_token sau khi người yêu cầu đồng ý. Bản hiện tại cũng được lưu thành snapshot nên hoàn tác được tiếp. Không đổi trạng thái đăng của trang.',
			'input_schema'   => array( 'type' => 'object', 'required' => array( 'page_id', 'snapshot_id' ), 'properties' => array(
				'page_id'       => array( 'type' => 'integer', 'minimum' => 1 ),
				'snapshot_id'   => array( 'type' => 'string', 'minLength' => 6, 'maxLength' => 16 ),
				'confirm_token' => array( 'type' => 'string' ),
			) ),
			'output_schema'  => $S::envelope_schema( array( 'status' => array( 'type' => 'string' ), 'preview' => array( 'type' => 'object' ), 'confirm_token' => array( 'type' => 'string' ), 'expires_at' => array( 'type' => 'string' ), 'page_id' => array( 'type' => 'integer' ), 'changes' => array( 'type' => 'array' ), 'snapshot_id' => array( 'type' => 'string' ), 'spec_hash' => array( 'type' => 'string' ), 'url' => array( 'type' => 'string' ) ), array( 'status' ) ),
			'read_only'      => false,
			'destructive'    => false,
			'idempotent'     => false,
			'required_scope' => 'content.write',
			'required_cap'   => 'edit_pages',
			'handler'        => array( __CLASS__, 'page_restore' ),
			'preview'        => array( __CLASS__, 'page_restore_preview' ),
			'mode'           => 'content',
			'scopes'         => array( 'content.write' ),
			'confirm'        => 'always',
			'llm_alias'      => 'wp_page_restore',
			'fallback_pack'  => null,
			'since'          => '0.118.0',
		) );
	}

	/* ───────────────────────────── wp.page.read · wp.page.edit ─────────────────────── */

	/** [2026-10-17 02:15 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.118 S118-L5: trang + quyền sửa; null-safe. @return array|WP_Error */
	private static function editable_page( int $id, bool $need_unpublished ) {
		$p = self::seam( 'get_page', array( $id ), array( __CLASS__, 'default_get_page' ) );
		if ( ! is_array( $p ) || 'page' !== ( $p['type'] ?? '' ) ) {
			return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::NOT_FOUND, "Không có trang #{$id} trên website.", 404 );
		}
		if ( ! self::can( 'edit_pages' ) ) {
			return self::denied( 'edit_pages', 'sửa trang' );
		}
		if ( ! self::can( 'edit_post', $id ) ) {
			return self::denied( 'edit_post', "sửa trang #{$id}" );
		}
		if ( $need_unpublished && ! in_array( (string) $p['status'], array( 'draft', 'pending' ), true ) ) {
			return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::QUERY_INVALID, "Trang «{$p['title']}» đã công khai nên chưa sửa trực tiếp được (sửa trang đang chạy dùng wp.page.update_live: có bước xem trước và xác nhận). Hoặc sửa trong WordPress / dựng bản nháp mới.", 422, array( 'help_code' => 'selfwp_use_update_live' ) );
		}
		return $p;
	}

	/** Trang không phải do trợ lý dựng (hoặc dựng trước khi có bản mô tả lưu kèm): không sửa từng phần được — trả đường dẫn mở trình sửa của WordPress (Q118-8) */
	private static function needs_human( int $id, array $p ): array {
		$edit = (string) self::seam( 'edit_url', array( $id ), static function ( $id ) {
			return function_exists( 'get_edit_post_link' ) ? (string) get_edit_post_link( $id, 'raw' ) : '';
		} );
		return array( 'page_id' => $id, 'title' => $p['title'], 'status' => $p['status'], 'editable' => false, 'reason' => 'needs_human', 'edit_url' => $edit, 'message' => 'Trang này không do trợ lý dựng (hoặc dựng bản cũ) nên không sửa từng phần được; mở đường dẫn edit_url để chỉnh trong trình soạn của WordPress.' );
	}

	/** @return array|WP_Error */
	public static function page_read( array $args, array $ctx ) {
		return BizCity_MCP_Action_Support::run_as( $ctx, static function ( $uid ) use ( $args ) {
			$id = (int) ( $args['page_id'] ?? 0 );
			if ( $id <= 0 ) {
				return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::QUERY_INVALID, 'Thiếu mã trang (page_id).', 422 );
			}
			$p = self::editable_page( $id, false );
			if ( is_wp_error( $p ) ) {
				return $p;
			}
			$spec = is_array( $p['generated']['spec'] ?? null ) ? $p['generated']['spec'] : null;
			if ( null === $spec ) {
				return self::needs_human( $id, $p );
			}
			$sections = array();
			foreach ( array_values( (array) ( $spec['sections'] ?? array() ) ) as $i => $s ) {
				$sum = (string) ( $s['headline'] ?? $s['title'] ?? $s['form_ref'] ?? ( isset( $s['items'] ) ? count( (array) $s['items'] ) . ' ý' : '' ) );
				$sections[] = array( 'index' => $i, 'type' => (string) ( $s['type'] ?? '' ), 'summary' => function_exists( 'mb_substr' ) ? mb_substr( $sum, 0, 80, 'UTF-8' ) : substr( $sum, 0, 80 ) );
			}
			return array(
				'page_id'   => $id,
				'title'     => (string) $p['title'],
				'status'    => (string) $p['status'],
				'transport' => (string) ( $p['generated']['transport'] ?? '' ),
				'editable'  => in_array( (string) $p['status'], array( 'draft', 'pending' ), true ),
				'spec_hash' => BizCity_SelfWP_Page_Compiler::spec_hash( $spec ),
				'sections'  => $sections,
				'spec'      => $spec,
				'snapshots' => array_map( static function ( $n ) {
					return array( 'id' => $n['id'], 'at' => $n['at'], 'title' => $n['title'] );
				}, self::snapshot_list( (array) $p['generated'] ) ),
			);
		} );
	}

	/** @return array|WP_Error */
	public static function page_edit( array $args, array $ctx ) {
		return BizCity_MCP_Action_Support::run_as( $ctx, static function ( $uid ) use ( $args ) {
			$id = (int) ( $args['page_id'] ?? 0 );
			if ( $id <= 0 ) {
				return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::QUERY_INVALID, 'Thiếu mã trang (page_id).', 422 );
			}
			$p = self::editable_page( $id, true );
			if ( is_wp_error( $p ) ) {
				return $p;
			}
			$old = is_array( $p['generated']['spec'] ?? null ) ? $p['generated']['spec'] : null;
			if ( null === $old ) {
				return self::needs_human( $id, $p );
			}
			$old_hash = BizCity_SelfWP_Page_Compiler::spec_hash( $old );
			$base     = trim( (string) ( $args['base_spec_hash'] ?? '' ) );
			if ( '' !== $base && 0 !== strcasecmp( $base, $old_hash ) ) {
				return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::QUERY_INVALID, 'Trang đã được sửa sau lần đọc của bạn (bản mô tả đã khác). Đọc lại trang bằng wp.page.read rồi sửa lại.', 409, array( 'help_code' => 'selfwp_stale_spec', 'current_spec_hash' => $old_hash ) );
			}
			$applied = BizCity_SelfWP_Page_Compiler::apply_ops( $old, (array) ( $args['ops'] ?? array() ) );
			if ( ! $applied['ok'] ) {
				return self::invalid( $applied['errors'] );
			}
			$v = BizCity_SelfWP_Page_Compiler::validate( $applied['spec'] );
			if ( ! $v['ok'] ) {
				return self::invalid( $v['errors'] );
			}
			$spec = $v['spec'];
			$bad = self::refs_error( $spec );
				if ( $bad ) {
					return $bad;
				}
				$new_hash  = BizCity_SelfWP_Page_Compiler::spec_hash( $spec );
			$transport = in_array( (string) ( $p['generated']['transport'] ?? '' ), array( 'flatsome', 'gutenberg' ), true ) ? (string) $p['generated']['transport'] : 'gutenberg';
			if ( $new_hash === $old_hash ) {
				return array( 'page_id' => $id, 'title' => (string) $p['title'], 'status' => (string) $p['status'], 'unchanged' => true, 'spec_hash' => $new_hash, 'changes' => array() );
			}
			$r = self::seam( 'update_post', array( $id, array( 'post_title' => $spec['title'], 'post_content' => BizCity_SelfWP_Page_Compiler::compile( $spec, $transport ) ) ), static function ( $id, $fields ) {
				return wp_update_post( wp_slash( array_merge( array( 'ID' => (int) $id ), $fields ) ), true ); // WordPress lưu một revision của bản cũ (nếu site bật revision)
			} );
			if ( is_wp_error( $r ) ) {
				return BizCity_MCP_Action_Support::from_business( $r );
			}
			if ( (int) $r <= 0 ) {
				return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::INTERNAL_ERROR, 'Không lưu được thay đổi của trang.', 500 );
			}
			self::seam( 'update_meta', array( $id, self::META_GENERATED, array_merge( self::push_snapshot( (array) $p['generated'], $old ), array( 'spec_hash' => $new_hash, 'spec' => $spec, 'edited_by' => $uid, 'edited_at' => gmdate( 'c' ) ) ) ), static function ( $id, $key, $value ) {
				return update_post_meta( $id, $key, $value );
			} );
			return array(
				'page_id'     => $id,
				'title'       => $spec['title'],
				'status'      => (string) $p['status'],
				'transport'   => $transport,
				'spec_hash'   => $new_hash,
				'changes'     => $applied['changes'],
				'sections'    => count( $spec['sections'] ),
				'preview_url' => (string) self::seam( 'preview_url', array( $id ), static function ( $id ) {
					return function_exists( 'get_preview_post_link' ) ? (string) get_preview_post_link( $id ) : '';
				} ),
			);
		} );
	}

	/* ───────────────────────────── R2: wp.page.update_live · wp.page.restore (snapshot, doc 50 §4) ─────────────────────── */

	/** @return array<int,array{id:string,at:string,by:int,title:string,spec:array}> newest first; entries older than 90 days or malformed are dropped */
	private static function snapshot_list( array $generated ): array {
		$out = array();
		foreach ( (array) ( $generated['snapshots'] ?? array() ) as $n ) {
			$at = is_array( $n ) ? strtotime( (string) ( $n['at'] ?? '' ) ) : false;
			if ( ! is_array( $n ) || ! is_string( $n['id'] ?? null ) || ! is_array( $n['spec'] ?? null ) || false === $at || ( time() - $at ) > self::SNAP_TTL_S ) {
				continue;
			}
			$out[] = array( 'id' => $n['id'], 'at' => (string) $n['at'], 'by' => (int) ( $n['by'] ?? 0 ), 'title' => (string) ( $n['title'] ?? '' ), 'spec' => $n['spec'] );
		}
		return $out;
	}

	/** the OLD spec becomes the newest snapshot (same content twice = one entry); at most SNAP_MAX kept. Returns the updated `generated` meta */
	private static function push_snapshot( array $generated, array $old_spec ): array {
		$id    = substr( BizCity_SelfWP_Page_Compiler::spec_hash( $old_spec ), 0, 12 );
		$snaps = array_values( array_filter( self::snapshot_list( $generated ), static function ( $n ) use ( $id ) {
			return $n['id'] !== $id;
		} ) );
		array_unshift( $snaps, array( 'id' => $id, 'at' => gmdate( 'c' ), 'by' => function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0, 'title' => (string) ( $old_spec['title'] ?? '' ), 'spec' => $old_spec ) );
		$generated['snapshots'] = array_slice( $snaps, 0, self::SNAP_MAX );
		return $generated;
	}

	/** every media:<id> / cf7:<id> of the spec must exist for this person @return WP_Error|null */
	private static function refs_error( array $spec ) {
		$refs = BizCity_SelfWP_Page_Compiler::refs( $spec );
		foreach ( $refs['media'] as $mid ) {
			if ( ! self::seam( 'media_ok', array( $mid ), array( __CLASS__, 'default_media_ok' ) ) ) {
				return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::NOT_FOUND, "Ảnh media:{$mid} không có trong thư viện (hoặc không phải ảnh).", 404 );
			}
		}
		foreach ( $refs['forms'] as $fid ) {
			if ( ! self::seam( 'form_ok', array( $fid ), array( __CLASS__, 'default_form_ok' ) ) ) {
				return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::NOT_FOUND, "Form cf7:{$fid} không tồn tại hoặc bạn không có quyền dùng.", 404 );
			}
		}
		return null;
	}

	/**
	 * Page of ours that this person may change while it is live. update_live: must be public + publish_pages. restore: any status but trash; publish_pages only when it is public.
	 *
	 * @return array|WP_Error the page row (with a spec in `generated`)
	 */
	private static function live_page( int $id, string $kind ) {
		$p = self::seam( 'get_page', array( $id ), array( __CLASS__, 'default_get_page' ) );
		if ( ! is_array( $p ) || 'page' !== ( $p['type'] ?? '' ) ) {
			return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::NOT_FOUND, "Không có trang #{$id} trên website.", 404 );
		}
		if ( ! self::can( 'edit_pages' ) ) {
			return self::denied( 'edit_pages', 'sửa trang' );
		}
		if ( ! self::can( 'edit_post', $id ) ) {
			return self::denied( 'edit_post', "sửa trang #{$id}" );
		}
		$public = 'publish' === (string) $p['status'];
		if ( ( $public || 'update_live' === $kind ) && ! self::can( 'publish_pages' ) ) {
			return self::denied( 'publish_pages', 'sửa trang đang công khai' );
		}
		if ( 'update_live' === $kind && ! $public ) {
			return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::QUERY_INVALID, "Trang «{$p['title']}» chưa công khai nên dùng wp.page.edit (không cần xác nhận).", 422, array( 'help_code' => 'selfwp_not_live' ) );
		}
		if ( 'trash' === (string) $p['status'] ) {
			return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::QUERY_INVALID, "Trang «{$p['title']}» đang ở thùng rác.", 422, array( 'help_code' => 'selfwp_trashed' ) );
		}
		if ( ! is_array( $p['generated']['spec'] ?? null ) ) {
			return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::QUERY_INVALID, "Trang #{$id} không do trợ lý dựng (hoặc dựng bản cũ) nên không sửa/hoàn tác từng phần được. Hãy chỉnh trong WordPress.", 422, array( 'help_code' => 'selfwp_not_generated', 'edit_url' => (string) self::seam( 'edit_url', array( $id ), static function ( $id ) {
				return function_exists( 'get_edit_post_link' ) ? (string) get_edit_post_link( $id, 'raw' ) : '';
			} ) ) );
		}
		return $p;
	}

	/** @return array|WP_Error plan of an update_live: nothing is written */
	private static function plan_live_edit( array $args ) {
		$id = (int) ( $args['page_id'] ?? 0 );
		if ( $id <= 0 ) {
			return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::QUERY_INVALID, 'Thiếu mã trang (page_id).', 422 );
		}
		$base = trim( (string) ( $args['base_spec_hash'] ?? '' ) );
		if ( '' === $base ) {
			return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::QUERY_INVALID, 'Sửa trang đang công khai bắt buộc kèm base_spec_hash (lấy từ wp.page.read) để không ghi đè thay đổi mới hơn.', 422, array( 'help_code' => 'selfwp_base_hash_required' ) );
		}
		$p = self::live_page( $id, 'update_live' );
		if ( is_wp_error( $p ) ) {
			return $p;
		}
		$old      = $p['generated']['spec'];
		$old_hash = BizCity_SelfWP_Page_Compiler::spec_hash( $old );
		if ( 0 !== strcasecmp( $base, $old_hash ) ) {
			return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::QUERY_INVALID, 'Trang đã được sửa sau lần đọc của bạn (bản mô tả đã khác). Đọc lại trang bằng wp.page.read rồi sửa lại.', 409, array( 'help_code' => 'selfwp_stale_spec', 'current_spec_hash' => $old_hash ) );
		}
		$applied = BizCity_SelfWP_Page_Compiler::apply_ops( $old, (array) ( $args['ops'] ?? array() ) );
		if ( ! $applied['ok'] ) {
			return self::invalid( $applied['errors'] );
		}
		$v = BizCity_SelfWP_Page_Compiler::validate( $applied['spec'] );
		if ( ! $v['ok'] ) {
			return self::invalid( $v['errors'] );
		}
		$bad = self::refs_error( $v['spec'] );
		if ( $bad ) {
			return $bad;
		}
		return array( 'id' => $id, 'page' => $p, 'old' => $old, 'old_hash' => $old_hash, 'spec' => $v['spec'], 'new_hash' => BizCity_SelfWP_Page_Compiler::spec_hash( $v['spec'] ), 'changes' => BizCity_SelfWP_Page_Compiler::diff( $old, $v['spec'] ) );
	}

	/** @return array|WP_Error plan of a restore: nothing is written */
	private static function plan_restore( array $args ) {
		$id = (int) ( $args['page_id'] ?? 0 );
		if ( $id <= 0 ) {
			return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::QUERY_INVALID, 'Thiếu mã trang (page_id).', 422 );
		}
		$sid = trim( (string) ( $args['snapshot_id'] ?? '' ) );
		if ( '' === $sid ) {
			return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::QUERY_INVALID, 'Thiếu snapshot_id (xem wp.page.read → snapshots).', 422, array( 'help_code' => 'selfwp_snapshot_required' ) );
		}
		$p = self::live_page( $id, 'restore' );
		if ( is_wp_error( $p ) ) {
			return $p;
		}
		$snap = null;
		foreach ( self::snapshot_list( (array) $p['generated'] ) as $n ) {
			if ( 0 === strcasecmp( $n['id'], $sid ) ) {
				$snap = $n;
				break;
			}
		}
		if ( null === $snap ) {
			return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::NOT_FOUND, "Không có bản lưu «{$sid}» của trang #{$id} (hết hạn 90 ngày hoặc sai mã). Xem danh sách bằng wp.page.read.", 404, array( 'help_code' => 'selfwp_snapshot_not_found' ) );
		}
		$v = BizCity_SelfWP_Page_Compiler::validate( $snap['spec'] ); // meta is data: never trust it, same gate as a fresh spec
		if ( ! $v['ok'] ) {
			return self::invalid( $v['errors'] );
		}
		$bad = self::refs_error( $v['spec'] );
		if ( $bad ) {
			return $bad;
		}
		$old = $p['generated']['spec'];
		return array( 'id' => $id, 'page' => $p, 'old' => $old, 'old_hash' => BizCity_SelfWP_Page_Compiler::spec_hash( $old ), 'spec' => $v['spec'], 'new_hash' => BizCity_SelfWP_Page_Compiler::spec_hash( $v['spec'] ), 'snapshot' => $snap, 'changes' => BizCity_SelfWP_Page_Compiler::diff( $old, $v['spec'] ) );
	}

	/** compile + save the new spec, keep the old one as a snapshot. @return true|WP_Error */
	private static function write_live( array $plan, int $uid, string $executor ) {
		$p         = $plan['page'];
		$transport = in_array( (string) ( $p['generated']['transport'] ?? '' ), array( 'flatsome', 'gutenberg' ), true ) ? (string) $p['generated']['transport'] : 'gutenberg';
		$r         = self::seam( 'update_post', array( $plan['id'], array( 'post_title' => $plan['spec']['title'], 'post_content' => BizCity_SelfWP_Page_Compiler::compile( $plan['spec'], $transport ) ) ), static function ( $id, $fields ) {
			return wp_update_post( wp_slash( array_merge( array( 'ID' => (int) $id ), $fields ) ), true );
		} );
		if ( is_wp_error( $r ) ) {
			return BizCity_MCP_Action_Support::from_business( $r );
		}
		if ( (int) $r <= 0 ) {
			return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::INTERNAL_ERROR, 'Không lưu được thay đổi của trang.', 500 );
		}
		$gen = array_merge( self::push_snapshot( (array) $p['generated'], $plan['old'] ), array( 'spec_hash' => $plan['new_hash'], 'spec' => $plan['spec'], 'edited_by' => $uid, 'edited_at' => gmdate( 'c' ), 'edited_via' => $executor ) );
		self::seam( 'update_meta', array( $plan['id'], self::META_GENERATED, $gen ), static function ( $id, $key, $value ) {
			return update_post_meta( $id, $key, $value );
		} );
		return true;
	}

	private static function live_url( int $id ): string {
		return (string) self::seam( 'public_url', array( $id ), array( __CLASS__, 'default_public_url' ) );
	}

	/** @return array|WP_Error */
	public static function page_update_live_preview( array $args, array $ctx ) {
		return BizCity_MCP_Action_Support::run_as( $ctx, static function ( $uid ) use ( $args ) {
			$plan = self::plan_live_edit( $args );
			if ( is_wp_error( $plan ) ) {
				return $plan;
			}
			if ( array() === $plan['changes'] ) {
				return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::QUERY_INVALID, 'Các thao tác này không làm thay đổi gì ở trang.', 422, array( 'help_code' => 'selfwp_no_change' ) );
			}
			$url = self::live_url( $plan['id'] );
			return array(
				'page_id' => $plan['id'],
				'title'   => $plan['page']['title'],
				'url'     => $url,
				'changes' => $plan['changes'],
				'summary' => 'Sửa trang ĐANG CÔNG KHAI «' . $plan['page']['title'] . '» (' . $url . '): ' . count( $plan['changes'] ) . ' thay đổi — khách vào xem thấy ngay. Bản cũ được lưu để hoàn tác.',
			);
		} );
	}

	/** @return array|WP_Error */
	public static function page_update_live( array $args, array $ctx ) {
		return BizCity_MCP_Action_Support::run_as( $ctx, static function ( $uid ) use ( $args ) {
			$plan = self::plan_live_edit( $args );
			if ( is_wp_error( $plan ) ) {
				return $plan;
			}
			$url = self::live_url( $plan['id'] );
			if ( array() === $plan['changes'] ) {
				return array( 'status' => 'done', 'page_id' => $plan['id'], 'url' => $url, 'changes' => array(), 'unchanged' => true, 'spec_hash' => $plan['new_hash'] );
			}
			$w = self::write_live( $plan, $uid, 'wp.page.update_live' );
			if ( is_wp_error( $w ) ) {
				return $w;
			}
			return array( 'status' => 'done', 'page_id' => $plan['id'], 'url' => $url, 'changes' => $plan['changes'], 'unchanged' => false, 'spec_hash' => $plan['new_hash'], 'snapshot_id' => substr( $plan['old_hash'], 0, 12 ) );
		} );
	}

	/** @return array|WP_Error */
	public static function page_restore_preview( array $args, array $ctx ) {
		return BizCity_MCP_Action_Support::run_as( $ctx, static function ( $uid ) use ( $args ) {
			$plan = self::plan_restore( $args );
			if ( is_wp_error( $plan ) ) {
				return $plan;
			}
			if ( array() === $plan['changes'] ) {
				return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::QUERY_INVALID, 'Trang đang đúng bản này rồi, không cần hoàn tác.', 422, array( 'help_code' => 'selfwp_no_change' ) );
			}
			$live = 'publish' === (string) $plan['page']['status'];
			return array(
				'page_id'     => $plan['id'],
				'title'       => $plan['page']['title'],
				'status'      => $plan['page']['status'],
				'snapshot_id' => $plan['snapshot']['id'],
				'snapshot_at' => $plan['snapshot']['at'],
				'changes'     => $plan['changes'],
				'summary'     => 'Đưa trang «' . $plan['page']['title'] . '» về bản lưu lúc ' . $plan['snapshot']['at'] . ': ' . count( $plan['changes'] ) . ' thay đổi' . ( $live ? ' — trang đang công khai nên khách thấy ngay' : '' ) . '. Bản hiện tại cũng được lưu.',
			);
		} );
	}

	/** @return array|WP_Error */
	public static function page_restore( array $args, array $ctx ) {
		return BizCity_MCP_Action_Support::run_as( $ctx, static function ( $uid ) use ( $args ) {
			$plan = self::plan_restore( $args );
			if ( is_wp_error( $plan ) ) {
				return $plan;
			}
			$url = self::live_url( $plan['id'] );
			if ( array() === $plan['changes'] ) { // a retry / second tap: already on that version
				return array( 'status' => 'done', 'page_id' => $plan['id'], 'url' => $url, 'changes' => array(), 'unchanged' => true, 'spec_hash' => $plan['new_hash'] );
			}
			$w = self::write_live( $plan, $uid, 'wp.page.restore' );
			if ( is_wp_error( $w ) ) {
				return $w;
			}
			return array( 'status' => 'done', 'page_id' => $plan['id'], 'url' => $url, 'changes' => $plan['changes'], 'unchanged' => false, 'spec_hash' => $plan['new_hash'], 'snapshot_id' => substr( $plan['old_hash'], 0, 12 ) );
		} );
	}

	/* ───────────────────────────── R0: wp.site.info · wp.content.list · cf7.form.list ─────────────────────── */

	/** [2026-10-17 02:09 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.118 S118-L4: chỉ ĐỌC. Không có dữ liệu cá nhân của người khác; danh sách quyền là danh sách đóng. */
	public static function site_info( array $args, array $ctx ) {
		return BizCity_MCP_Action_Support::run_as( $ctx, static function ( $uid ) {
			if ( ! self::can( 'read' ) ) {
				return self::denied( 'read', 'xem thông tin website' );
			}
			$i = (array) self::seam( 'site_info', array(), array( __CLASS__, 'default_site_info' ) );
			// D118-23: chỉ báo transport MÃ ĐÃ HỖ TRỢ; BZPB (nếu có) chỉ là tiện ích mở rộng, báo riêng
			$transports = array();
			if ( 'flatsome' === (string) ( $i['theme']['template'] ?? '' ) ) {
				$transports[] = 'flatsome';
			}
			$transports[] = 'gutenberg';
			$caps = array();
			foreach ( array( 'edit_pages', 'publish_pages', 'upload_files', 'wpcf7_edit_contact_forms' ) as $c ) {
				$caps[ $c ] = (bool) self::can( $c );
			}
			return array(
				'name'              => (string) ( $i['name'] ?? '' ),
				'url'               => (string) ( $i['url'] ?? '' ),
				'https'             => ! empty( $i['https'] ),
				'wp_version'        => (string) ( $i['wp_version'] ?? '' ),
				'theme'             => array( 'name' => (string) ( $i['theme']['name'] ?? '' ), 'slug' => (string) ( $i['theme']['slug'] ?? '' ), 'template' => (string) ( $i['theme']['template'] ?? '' ) ),
				'page_transports'   => $transports,
				'landing_template'  => ! empty( $i['landing_template'] ),
				'contact_form_7'    => ! empty( $i['cf7'] ),
				'extensions'        => array( 'pagebuilder' => ! empty( $i['bzpb'] ) ),
				'you_can'           => $caps,
			);
		} );
	}

	/** @return array|WP_Error */
	public static function content_list( array $args, array $ctx ) {
		return BizCity_MCP_Action_Support::run_as( $ctx, static function ( $uid ) use ( $args ) {
			$type = in_array( (string) ( $args['type'] ?? 'page' ), array( 'page', 'post' ), true ) ? (string) ( $args['type'] ?? 'page' ) : 'page';
			$edit = 'page' === $type ? 'edit_pages' : 'edit_posts';
			if ( ! self::can( $edit ) ) {
				return self::denied( $edit, 'xem danh sách ' . ( 'page' === $type ? 'trang' : 'bài viết' ) );
			}
			$status = in_array( (string) ( $args['status'] ?? 'any' ), array( 'any', 'draft', 'publish', 'pending', 'private' ), true ) ? (string) ( $args['status'] ?? 'any' ) : 'any';
			$limit  = max( 1, min( 20, (int) ( $args['limit'] ?? 10 ) ) );
			$search = trim( preg_replace( '/\s+/u', ' ', strip_tags( (string) ( $args['search'] ?? '' ) ) ) );
			$search = function_exists( 'mb_substr' ) ? mb_substr( $search, 0, 80, 'UTF-8' ) : substr( $search, 0, 80 );
			// người không có quyền sửa bài của người khác chỉ thấy bài của mình
			$all    = self::can( 'page' === $type ? 'edit_others_pages' : 'edit_others_posts' );
			$rows   = (array) self::seam( 'list_posts', array( array( 'type' => $type, 'status' => $status, 'search' => $search, 'limit' => $limit, 'author' => $all ? 0 : (int) $uid ) ), array( __CLASS__, 'default_list_posts' ) );
			$items  = array();
			foreach ( array_slice( $rows, 0, $limit ) as $r ) {
				$title   = function_exists( 'mb_substr' ) ? mb_substr( trim( (string) ( $r['title'] ?? '' ) ), 0, 120, 'UTF-8' ) : substr( trim( (string) ( $r['title'] ?? '' ) ), 0, 120 );
				$items[] = array(
					'id'        => (int) ( $r['id'] ?? 0 ),
					'title'     => $title,
					'status'    => (string) ( $r['status'] ?? '' ),
					'type'      => (string) ( $r['type'] ?? $type ),
					'modified'  => (string) ( $r['modified'] ?? '' ),
					'url'       => 'publish' === ( $r['status'] ?? '' ) ? (string) ( $r['url'] ?? '' ) : '', // trang chưa công khai không có địa chỉ để đưa ra
					'by_assistant' => ! empty( $r['generated'] ),
				);
			}
			return array( 'type' => $type, 'status' => $status, 'count' => count( $items ), 'only_mine' => ! $all, 'items' => $items );
		} );
	}

	/** @return array|WP_Error */
	public static function form_list( array $args, array $ctx ) {
		return BizCity_MCP_Action_Support::run_as( $ctx, static function ( $uid ) use ( $args ) {
			if ( ! self::can( 'wpcf7_read_contact_forms' ) ) {
				return self::denied( 'wpcf7_read_contact_forms', 'xem danh sách form liên hệ' );
			}
			$limit = max( 1, min( 30, (int) ( $args['limit'] ?? 20 ) ) );
			$rows  = (array) self::seam( 'list_forms', array( $limit ), array( __CLASS__, 'default_list_forms' ) );
			$items = array();
			foreach ( array_slice( $rows, 0, $limit ) as $r ) {
				$id      = (int) ( $r['id'] ?? 0 );
				$items[] = array( 'id' => $id, 'title' => function_exists( 'mb_substr' ) ? mb_substr( trim( (string) ( $r['title'] ?? '' ) ), 0, 120, 'UTF-8' ) : substr( trim( (string) ( $r['title'] ?? '' ) ), 0, 120 ), 'shortcode' => '[contact-form-7 id="' . $id . '"]' );
			}
			return array( 'count' => count( $items ), 'items' => $items );
		} );
	}

	public static function default_site_info(): array {
		$theme = function_exists( 'wp_get_theme' ) ? wp_get_theme() : null;
		return array(
			'name'             => function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'name' ) : '',
			'url'              => function_exists( 'home_url' ) ? (string) home_url( '/' ) : '',
			'https'            => function_exists( 'is_ssl' ) && is_ssl(),
			'wp_version'       => function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'version' ) : '',
			'theme'            => array(
				'name'     => $theme ? (string) $theme->get( 'Name' ) : '',
				'slug'     => function_exists( 'get_stylesheet' ) ? (string) get_stylesheet() : '',
				'template' => function_exists( 'get_template' ) ? (string) get_template() : '',
			),
			'landing_template' => function_exists( 'locate_template' ) && '' !== locate_template( self::TEMPLATE_LANDING ),
			'cf7'              => function_exists( 'wpcf7_save_contact_form' ),
			'bzpb'             => class_exists( 'BZPB_Export', false ),
		);
	}

	/** @return array<int,array> */
	public static function default_list_posts( $q ): array {
		if ( ! function_exists( 'get_posts' ) ) {
			return array();
		}
		$args = array( 'post_type' => $q['type'], 'post_status' => $q['status'], 'posts_per_page' => (int) $q['limit'], 'orderby' => 'modified', 'order' => 'DESC', 'no_found_rows' => true );
		if ( '' !== $q['search'] ) {
			$args['s'] = $q['search'];
		}
		if ( (int) $q['author'] > 0 ) {
			$args['author'] = (int) $q['author'];
		}
		$out = array();
		foreach ( (array) get_posts( $args ) as $p ) {
			$g     = get_post_meta( (int) $p->ID, self::META_GENERATED, true );
			$out[] = array( 'id' => (int) $p->ID, 'title' => (string) $p->post_title, 'status' => (string) $p->post_status, 'type' => (string) $p->post_type, 'modified' => (string) $p->post_modified_gmt, 'url' => 'publish' === $p->post_status ? (string) get_permalink( (int) $p->ID ) : '', 'generated' => is_array( $g ) && $g );
		}
		return $out;
	}

	/** @return array<int,array> */
	public static function default_list_forms( $limit ): array {
		if ( ! function_exists( 'get_posts' ) ) {
			return array();
		}
		$out = array();
		foreach ( (array) get_posts( array( 'post_type' => 'wpcf7_contact_form', 'post_status' => 'publish', 'posts_per_page' => (int) $limit, 'orderby' => 'date', 'order' => 'DESC', 'no_found_rows' => true ) ) as $p ) {
			$out[] = array( 'id' => (int) $p->ID, 'title' => (string) $p->post_title );
		}
		return $out;
	}

	/* ───────────────────────────── cf7.form.create ─────────────────────── */

	/** @return array|WP_Error */
	public static function form_create( array $args, array $ctx ) {
		return BizCity_MCP_Action_Support::run_as( $ctx, static function ( $uid ) use ( $args ) {
			if ( ! self::can( 'wpcf7_edit_contact_forms' ) ) {
				return self::denied( 'wpcf7_edit_contact_forms', 'tạo form liên hệ' );
			}
			$f = BizCity_SelfWP_Page_Compiler::form_template( $args );
			if ( ! $f['ok'] ) {
				return self::invalid( $f['errors'] );
			}
			$to = trim( (string) self::seam( 'user_email', array( $uid ), static function ( $uid ) {
				$u = function_exists( 'get_userdata' ) ? get_userdata( $uid ) : null;
				return ( $u && ! empty( $u->user_email ) ) ? (string) $u->user_email : '';
			} ) );
			// [2026-10-17 01:54 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.118 Q118-4: mặc định thư về email của người yêu cầu; người yêu cầu có thể điền MỘT địa chỉ khác (kiểm chặt, không danh sách, không xuống dòng)
			$custom       = trim( (string) ( $args['recipient_email'] ?? '' ) );
			$to_requester = $to;
			$is_requester = true;
			if ( '' !== $custom ) {
				if ( ! self::single_email( $custom ) ) {
					return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::QUERY_INVALID, 'Email nhận thư của form không hợp lệ: chỉ nhận MỘT địa chỉ, dạng ten@ten-mien.vn (không dấu phẩy, không khoảng trắng).', 422, array( 'help_code' => 'selfwp_recipient_invalid' ) );
				}
				$to           = $custom;
				$is_requester = 0 === strcasecmp( $custom, $to_requester );
			}
			if ( '' === $to || ! self::single_email( $to ) ) {
				return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::QUERY_INVALID, 'Tài khoản của bạn chưa có email hợp lệ để nhận thư từ form. Hãy cập nhật email trong hồ sơ WordPress rồi thử lại.', 422, array( 'hint' => 'Hồ sơ › Email', 'help_code' => 'selfwp_no_recipient' ) );
			}
			$reply = '';
			foreach ( $f['form']['fields'] as $fl ) {
				if ( 'email' === $fl['type'] ) {
					$reply = "Reply-To: [{$fl['name']}]";
					break;
				}
			}
			$save = array(
				'id'       => -1,
				'title'    => $f['form']['title'],
				'form'     => $f['template'],
				'mail'     => array(
					'active'             => true,
					'subject'            => '[_site_title] Liên hệ mới từ trang landing',
					'sender'             => '[_site_title] <wordpress@' . self::host() . '>',
					'recipient'          => $to,
					'body'               => $f['mail_body'] . "\n-- \nGửi từ [_url]",
					'additional_headers' => $reply,
					'attachments'        => '',
					'use_html'           => false,
					'exclude_blank'      => false,
				),
				'messages' => array( 'mail_sent_ok' => $f['form']['success_message'] ),
			);
			$id = self::seam( 'save_form', array( $save ), static function ( $save ) {
				if ( ! function_exists( 'wpcf7_save_contact_form' ) ) {
					return new WP_Error( 'cf7_missing', 'Contact Form 7 chưa được cài.' );
				}
				$tpl = class_exists( 'WPCF7_ContactForm' ) ? WPCF7_ContactForm::get_template() : null;
				if ( $tpl ) {
					$msg                   = (array) $tpl->prop( 'messages' );
					$msg['mail_sent_ok']   = $save['messages']['mail_sent_ok'];
					$save['messages']      = $msg;
				}
				$form = wpcf7_save_contact_form( $save );
				return ( $form && method_exists( $form, 'id' ) ) ? (int) $form->id() : 0;
			} );
			if ( is_wp_error( $id ) ) {
				return BizCity_MCP_Action_Support::from_business( $id );
			}
			$id = (int) $id;
			if ( $id <= 0 ) {
				return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::INTERNAL_ERROR, 'Không lưu được form (Contact Form 7 không trả id).', 500 );
			}
			return array(
				'form_id'          => $id,
				'shortcode'        => '[contact-form-7 id="' . $id . '"]',
				'recipient_masked' => self::mask_email( $to ),
				'recipient_is_requester' => $is_requester,
			);
		} );
	}

	/* ───────────────────────────── wp.landing.draft ────────────────────── */

	/** @return array|WP_Error */
	public static function landing_draft( array $args, array $ctx ) {
		return BizCity_MCP_Action_Support::run_as( $ctx, static function ( $uid ) use ( $args ) {
			if ( ! self::can( 'edit_pages' ) ) {
				return self::denied( 'edit_pages', 'tạo trang' );
			}
			$v = BizCity_SelfWP_Page_Compiler::validate( $args['spec'] ?? null );
			if ( ! $v['ok'] ) {
				return self::invalid( $v['errors'] );
			}
			$spec = $v['spec'];
			$refs = BizCity_SelfWP_Page_Compiler::refs( $spec );
			foreach ( $refs['media'] as $mid ) {
				if ( ! self::seam( 'media_ok', array( $mid ), array( __CLASS__, 'default_media_ok' ) ) ) {
					return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::NOT_FOUND, "Ảnh media:{$mid} không có trong thư viện (hoặc không phải ảnh).", 404 );
				}
			}
			foreach ( $refs['forms'] as $fid ) {
				if ( ! self::seam( 'form_ok', array( $fid ), array( __CLASS__, 'default_form_ok' ) ) ) {
					return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::NOT_FOUND, "Form cf7:{$fid} không tồn tại hoặc bạn không có quyền dùng.", 404 );
				}
			}
			$hash = BizCity_SelfWP_Page_Compiler::spec_hash( $spec );
			$dup  = (int) self::seam( 'find_duplicate', array( $uid, $hash ), array( __CLASS__, 'default_find_duplicate' ) );
			$want = (string) ( $args['transport'] ?? 'auto' );
			$flat = 'flatsome' === $want || ( 'auto' === $want && 'flatsome' === (string) self::seam( 'active_template', array(), static function () {
				return function_exists( 'get_template' ) ? (string) get_template() : '';
			} ) );
			$transport = $flat ? 'flatsome' : 'gutenberg';
			if ( $dup > 0 ) {
				return self::shape( $dup, $spec, $transport, $hash, true );
			}
			$postarr = array(
				'post_type'    => 'page',
				'post_status'  => 'draft', // create ≠ publish: never anything else here
				'post_title'   => $spec['title'],
				'post_content' => BizCity_SelfWP_Page_Compiler::compile( $spec, $transport ),
				'post_author'  => $uid,
			);
			if ( isset( $spec['slug'] ) ) {
				$postarr['post_name'] = $spec['slug'];
			}
			if ( ! empty( $spec['seo']['meta_description'] ) ) {
				$postarr['post_excerpt'] = $spec['seo']['meta_description'];
			}
			if ( $flat && self::seam( 'template_exists', array( self::TEMPLATE_LANDING ), static function ( $file ) {
				return function_exists( 'locate_template' ) && '' !== locate_template( $file );
			} ) ) {
				$postarr['page_template'] = self::TEMPLATE_LANDING;
			}
			$id = self::seam( 'insert_post', array( $postarr ), static function ( $postarr ) {
				return wp_insert_post( wp_slash( $postarr ), true );
			} );
			if ( is_wp_error( $id ) ) {
				return BizCity_MCP_Action_Support::from_business( $id );
			}
			$id = (int) $id;
			if ( $id <= 0 ) {
				return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::INTERNAL_ERROR, 'Không tạo được trang nháp.', 500 );
			}
			self::seam( 'update_meta', array( $id, self::META_GENERATED, array(
				'executor'  => 'wp.landing.draft',
				'spec_hash' => $hash,
				'transport' => $transport,
				'user_id'   => $uid,
				'at'        => gmdate( 'c' ),
				'spec'      => $spec, // [2026-10-17 02:15 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.118 S118-L5: bản mô tả đã chuẩn hoá, để wp.page.edit sửa từng phần mà không phải dựng lại từ đầu
			) ), static function ( $id, $key, $value ) {
				return update_post_meta( $id, $key, $value );
			} );
			return self::shape( $id, $spec, $transport, $hash, false );
		} );
	}

	/* ───────────────────────────── wp.page.publish ─────────────────────── */

	/**
	 * Checks shared by the preview and the commit: a page of ours that this person may edit and publish.
	 *
	 * @return array|WP_Error the page row
	 */
	private static function publishable( int $id ) {
		$p = self::seam( 'get_page', array( $id ), array( __CLASS__, 'default_get_page' ) );
		if ( ! is_array( $p ) || 'page' !== ( $p['type'] ?? '' ) ) {
			return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::NOT_FOUND, "Không có trang #{$id} trên website.", 404 );
		}
		if ( ! self::can( 'publish_pages' ) ) {
			return self::denied( 'publish_pages', 'đăng trang' );
		}
		if ( ! self::can( 'edit_post', $id ) ) {
			return self::denied( 'edit_post', "sửa trang #{$id}" );
		}
		if ( empty( $p['generated'] ) ) {
			return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::QUERY_INVALID, "Trang #{$id} không phải trang do trợ lý dựng nên trợ lý không đăng giúp. Hãy đăng trong WordPress.", 422, array( 'help_code' => 'selfwp_not_generated' ) );
		}
		return $p;
	}

	/** @return array|WP_Error */
	public static function page_publish_preview( array $args, array $ctx ) {
		return BizCity_MCP_Action_Support::run_as( $ctx, static function ( $uid ) use ( $args ) {
			$id = (int) ( $args['page_id'] ?? 0 );
			if ( $id <= 0 ) {
				return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::QUERY_INVALID, 'Thiếu mã trang (page_id).', 422 );
			}
			$p = self::publishable( $id );
			if ( is_wp_error( $p ) ) {
				return $p;
			}
			if ( 'publish' === $p['status'] ) {
				return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::QUERY_INVALID, "Trang «{$p['title']}» đã được đăng rồi.", 422, array( 'help_code' => 'selfwp_already_published' ) );
			}
			$url = (string) self::seam( 'public_url', array( $id ), array( __CLASS__, 'default_public_url' ) );
			return array(
				'page_id'   => $id,
				'title'     => $p['title'],
				'status'    => $p['status'],
				'url'       => $url,
				'transport' => (string) ( $p['generated']['transport'] ?? '' ),
				'summary'   => "Đăng công khai trang «{$p['title']}» tại {$url} — sau khi đăng ai có link cũng xem được.",
			);
		} );
	}

	/** @return array|WP_Error */
	public static function page_publish( array $args, array $ctx ) {
		return BizCity_MCP_Action_Support::run_as( $ctx, static function ( $uid ) use ( $args ) {
			$id = (int) ( $args['page_id'] ?? 0 );
			if ( $id <= 0 ) {
				return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::QUERY_INVALID, 'Thiếu mã trang (page_id).', 422 );
			}
			$p = self::publishable( $id );
			if ( is_wp_error( $p ) ) {
				return $p;
			}
			$url = (string) self::seam( 'public_url', array( $id ), array( __CLASS__, 'default_public_url' ) );
			if ( 'publish' === $p['status'] ) { // a retry / second tap: already public, nothing to do
				return array( 'status' => 'done', 'page_id' => $id, 'url' => $url, 'duplicate' => true );
			}
			$r = self::seam( 'publish_post', array( $id ), static function ( $id ) {
				return wp_update_post( array( 'ID' => (int) $id, 'post_status' => 'publish' ), true );
			} );
			if ( is_wp_error( $r ) ) {
				return BizCity_MCP_Action_Support::from_business( $r );
			}
			if ( (int) $r <= 0 ) {
				return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::INTERNAL_ERROR, 'Không đăng được trang.', 500 );
			}
			self::seam( 'update_meta', array( $id, '_bizcity_published', array( 'executor' => 'wp.page.publish', 'user_id' => $uid, 'at' => gmdate( 'c' ) ) ), static function ( $id, $key, $value ) {
				return update_post_meta( $id, $key, $value );
			} );
			// the address of a PUBLISHED page is its real permalink: ask again now that it is public
			$url = (string) self::seam( 'public_url', array( $id ), array( __CLASS__, 'default_public_url' ) );
			return array( 'status' => 'done', 'page_id' => $id, 'url' => $url, 'duplicate' => false );
		} );
	}

	private static function shape( int $id, array $spec, string $transport, string $hash, bool $dup ): array {
		return array(
			'page_id'     => $id,
			'title'       => $spec['title'],
			'status'      => 'draft',
			'transport'   => $transport,
			'preview_url' => (string) self::seam( 'preview_url', array( $id ), static function ( $id ) {
				return function_exists( 'get_preview_post_link' ) ? (string) get_preview_post_link( $id ) : '';
			} ),
			'edit_url'    => (string) self::seam( 'edit_url', array( $id ), static function ( $id ) {
				return function_exists( 'get_edit_post_link' ) ? (string) get_edit_post_link( $id, 'raw' ) : '';
			} ),
			'sections'    => count( $spec['sections'] ),
			'spec_hash'   => $hash,
			'duplicate'   => $dup,
		);
	}

	/* ───────────────────────────── defaults / helpers ──────────────────── */

	/** @return array|null */
	public static function default_get_page( $id ) {
		$p = function_exists( 'get_post' ) ? get_post( (int) $id ) : null;
		if ( ! $p ) {
			return null;
		}
		$g = function_exists( 'get_post_meta' ) ? get_post_meta( (int) $id, self::META_GENERATED, true ) : array();
		return array( 'id' => (int) $p->ID, 'type' => (string) $p->post_type, 'status' => (string) $p->post_status, 'title' => (string) $p->post_title, 'slug' => (string) $p->post_name, 'generated' => ( is_array( $g ) && $g ) ? $g : null );
	}

	public static function default_public_url( $id ): string {
		if ( ! function_exists( 'get_permalink' ) ) {
			return '';
		}
		$p = get_post( (int) $id );
		if ( $p && 'publish' === $p->post_status ) {
			return (string) get_permalink( (int) $id );
		}
		// a draft has no pretty permalink yet: build the address it WILL get from the sample permalink
		$s = function_exists( 'get_sample_permalink' ) ? get_sample_permalink( (int) $id ) : array();
		return isset( $s[0], $s[1] ) ? str_replace( array( '%pagename%', '%postname%' ), (string) $s[1], (string) $s[0] ) : (string) get_permalink( (int) $id );
	}

	public static function default_media_ok( $id ): bool {
		$p = function_exists( 'get_post' ) ? get_post( (int) $id ) : null;
		return $p && 'attachment' === $p->post_type && 0 === strpos( (string) $p->post_mime_type, 'image/' );
	}

	public static function default_form_ok( $id ): bool {
		$p = function_exists( 'get_post' ) ? get_post( (int) $id ) : null;
		return $p && 'wpcf7_contact_form' === $p->post_type && current_user_can( 'wpcf7_read_contact_form', (int) $id );
	}

	/** a draft this user made from the same spec in the last 15 minutes (the cell retried) */
	public static function default_find_duplicate( $uid, $hash ): int {
		if ( ! function_exists( 'get_posts' ) ) {
			return 0;
		}
		$rows = get_posts( array(
			'post_type'      => 'page',
			'post_status'    => 'draft',
			'author'         => (int) $uid,
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'date_query'     => array( array( 'after' => gmdate( 'Y-m-d H:i:s', time() - self::DUP_WINDOW_S ), 'column' => 'post_date_gmt' ) ),
			'meta_query'     => array( array( 'key' => self::META_GENERATED, 'value' => $hash, 'compare' => 'LIKE' ) ),
			'no_found_rows'  => true,
		) );
		return $rows ? (int) $rows[0] : 0;
	}

	private static function cf7_available(): bool {
		return function_exists( 'wpcf7_save_contact_form' ) || isset( self::$seams['save_form'] );
	}

	/** @param mixed ...$args */
	private static function can( string $cap, ...$args ): bool {
		if ( isset( self::$seams['user_can'] ) ) {
			return (bool) call_user_func( self::$seams['user_can'], $cap, ...$args );
		}
		return function_exists( 'current_user_can' ) && current_user_can( $cap, ...$args );
	}

	private static function seam( string $name, array $args, $default ) {
		$fn = self::$seams[ $name ] ?? $default;
		return call_user_func_array( $fn, $args );
	}

	private static function host(): string {
		$h = function_exists( 'wp_parse_url' ) && function_exists( 'home_url' ) ? (string) wp_parse_url( home_url(), PHP_URL_HOST ) : '';
		$h = preg_replace( '/^www\./', '', $h );
		return '' !== $h ? $h : 'example.com';
	}

	/** [2026-10-17 01:54 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.118 Q118-4: đúng MỘT email, ký tự an toàn, không thể chèn header thư hay nhiều người nhận */
	private static function single_email( string $e ): bool {
		return strlen( $e ) <= 100 && 1 === preg_match( '/^[A-Za-z0-9._%+\-]+@[A-Za-z0-9](?:[A-Za-z0-9\-]*[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9\-]*[A-Za-z0-9])?)*\.[A-Za-z]{2,}$/D', $e );
	}

	private static function mask_email( string $e ): string {
		$p = explode( '@', $e, 2 );
		if ( 2 !== count( $p ) ) {
			return '***';
		}
		return mb_substr( $p[0], 0, 1, 'UTF-8' ) . '***@' . $p[1];
	}

	private static function denied( string $cap, string $what ) {
		return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::PERMISSION_DENIED, "Tài khoản của bạn chưa có quyền {$what} (cần quyền WordPress \"{$cap}\").", 403, array( 'hint' => 'Nhờ quản trị viên cấp quyền hoặc dùng tài khoản có quyền này.', 'required_cap' => $cap ) );
	}

	/** @param array<int,array{path:string,code:string,message:string}> $errors */
	private static function invalid( array $errors ) {
		$msgs = array();
		foreach ( array_slice( $errors, 0, 4 ) as $e ) {
			$msgs[] = $e['path'] . ': ' . $e['message'];
		}
		return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::QUERY_INVALID, 'Nội dung chưa hợp lệ — ' . implode( ' · ', $msgs ), 422, array( 'errors' => array_slice( $errors, 0, 10 ), 'help_code' => 'selfwp_spec_invalid' ) );
	}
}

BizCity_SelfWP_Action_MCP_Service::init();
