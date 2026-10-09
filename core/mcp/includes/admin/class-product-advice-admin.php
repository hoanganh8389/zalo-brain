<?php
/**
 * BizCity_Product_Advice_Admin — "Tư vấn AI" on the WooCommerce product edit screen (post meta) and the product
 * category edit screen (term meta), plus the advice CSV template/import on the product list (PHASE-0.95 S95-F5).
 *
 * Flow ≤ 4 steps (R-LEAN-4): open product → fill any field (all optional) → Cập nhật. CSV: Tải khuôn → fill → Nhập CSV.
 * A field over its product-advice@1 cap is NOT silently cut: the advice block is refused, the product itself still
 * saves, the notice names the exact field, and the form comes back with what the owner typed. Storage goes through
 * BizCity_Product_Advice::sanitize()/sanitize_category() only.
 *
 * "Gợi ý từ mô tả": admin-ajax bizcity_advice_draft → Hub zalo-hub/consult/advice-draft → the cell drafts audience/goals/
 * avoid_for/key_questions/pitch from the product's own text. The JS only FILLS the form; nothing is saved until the owner
 * presses Cập nhật (R-BCB-10: chủ duyệt). Product text is never logged.
 *
 * Loaded from core/mcp/bootstrap.php only when is_admin().
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\MCP\Admin
 * @since      PHASE-0.95 (2026-10-09)
 */

defined( 'ABSPATH' ) || exit;

// [2026-10-09 03:56 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.95-S95-F5 — new file, product + product_cat "Tư vấn AI" metaboxes and advice CSV import.
final class BizCity_Product_Advice_Admin {

	const NONCE      = 'bizcity_advice_nonce';
	const NONCE_ACT  = 'bizcity_advice_save';
	const CSV_NONCE  = 'bizcity_advice_csv';
	const MAX_CSV    = 2097152; // 2 MB
	const NOTICE_TTL = 120;
	const DRAFT_ACT  = 'bizcity_advice_draft';

	/** @var array<string,callable> test seams: get_meta(id) · update_meta(id, value) · delete_meta(id) · find_product(match) · can_edit(id) · product_payload(id) · accounts() · hub(path, body) */
	public static $io = array();

	public static function init(): void {
		add_action( 'add_meta_boxes_product', array( __CLASS__, 'add_product_box' ) );
		add_action( 'save_post_product', array( __CLASS__, 'on_save_product' ), 20, 2 );
		add_action( 'product_cat_edit_form_fields', array( __CLASS__, 'render_category_box' ), 20 );
		add_action( 'edited_product_cat', array( __CLASS__, 'on_save_category' ), 20 );
		add_action( 'admin_notices', array( __CLASS__, 'print_notices' ) );
		add_action( 'all_admin_notices', array( __CLASS__, 'render_csv_bar' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'admin_post_bizcity_advice_csv_template', array( __CLASS__, 'download_template' ) );
		add_action( 'admin_post_bizcity_advice_csv_import', array( __CLASS__, 'import_csv' ) );
		// [2026-10-09 11:08 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.95-S95-F5 — "Gợi ý từ mô tả" (cell soạn nháp, chủ duyệt)
		add_action( 'wp_ajax_' . self::DRAFT_ACT, array( __CLASS__, 'ajax_draft' ) );
	}

	/* ── assets ───────────────────────────────────────────────────── */

	public static function enqueue( $hook ): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen ) {
			return;
		}
		$on = ( 'product' === $screen->post_type && in_array( $screen->base, array( 'post', 'edit' ), true ) )
			|| ( 'product_cat' === $screen->taxonomy && 'term' === $screen->base );
		if ( ! $on ) {
			return;
		}
		wp_enqueue_script( 'bizcity-product-advice-admin', plugins_url( 'product-advice-admin.js', __FILE__ ), array(), '0.95.2', true );
		wp_localize_script( 'bizcity-product-advice-admin', 'bizcityAdviceDraft', array(
			'ajax'   => admin_url( 'admin-ajax.php' ),
			'action' => self::DRAFT_ACT,
			'nonce'  => wp_create_nonce( self::DRAFT_ACT ),
		) );
		wp_register_style( 'bizcity-product-advice-admin', false, array(), '0.95.1' );
		wp_enqueue_style( 'bizcity-product-advice-admin' );
		wp_add_inline_style( 'bizcity-product-advice-admin', self::css() );
	}

	private static function css(): string {
		return '.bzadv-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}@media(max-width:782px){.bzadv-grid{grid-template-columns:1fr}}'
			. '.bzadv label.bzadv-l{display:block;font-weight:600;margin:8px 0 2px}.bzadv .bzadv-h{color:#646970;font-size:12px;font-weight:400}'
			. '.bzadv input[type=text],.bzadv input[type=number],.bzadv select,.bzadv textarea{width:100%;max-width:100%}'
			. '.bzadv-chips{display:flex;gap:4px;flex-wrap:wrap;border:1px solid #8c8f94;border-radius:4px;padding:4px;min-height:32px;background:#fff}'
			. '.bzadv-chip{background:#f6f7f7;border:1px solid #dcdcde;border-radius:12px;padding:1px 4px 1px 8px;font-size:12px;display:inline-flex;align-items:center;gap:2px}'
			. '.bzadv-chip button{border:0;background:none;cursor:pointer;color:#646970;padding:0 2px;line-height:1}'
			. '.bzadv-chips.bad .bzadv-chip{background:#fcf0f1;border-color:#f2b8b9;color:#d63638}'
			. '.bzadv-chips input.bzadv-chip-in{border:0!important;box-shadow:none!important;flex:1;min-width:120px;padding:2px 4px;width:auto}'
			. '.bzadv .bzadv-err{border-color:#d63638!important;box-shadow:0 0 0 1px #d63638!important}.bzadv .bzadv-errmsg{color:#d63638;font-size:12px}'
			. '.bzadv .bzadv-over{color:#d63638;font-weight:600}.bzadv-row{display:flex;gap:8px;align-items:center;flex-wrap:wrap}'
			. '.bzadv-csv{background:#fff;border:1px solid #dcdcde;border-radius:4px;padding:10px 12px;margin:10px 0}';
	}

	/* ── product metabox ──────────────────────────────────────────── */

	public static function add_product_box(): void {
		add_meta_box( 'bizcity_product_advice', 'Tư vấn AI — giúp trợ lý hỏi đúng và gợi ý đúng người', array( __CLASS__, 'render_product_box' ), 'product', 'normal', 'default' );
	}

	public static function render_product_box( $post ): void {
		$post_id = (int) $post->ID;
		$flash   = self::take_flash( 'p' . $post_id );
		$errors  = $flash['errors'] ?? array();
		$a       = isset( $flash['raw'] ) ? $flash['raw'] : ( BizCity_Product_Advice::for_product( $post_id ) ?: BizCity_Product_Advice::sanitize( array() ) );
		$v       = static function ( $k, $d = '' ) use ( $a ) { return $a[ $k ] ?? $d; };
		wp_nonce_field( self::NONCE_ACT, self::NONCE );
		$tiers = array( 'strategic' => 'Chiến lược', 'core' => 'Chủ lực', 'clearance' => 'Xả hàng', 'normal' => 'Thường' );
		$nexts = array( 'order' => 'Đặt hàng', 'booking' => 'Đặt lịch', 'brief' => 'Gửi bản so sánh', 'contact_staff' => 'Chuyển nhân viên' );
		$qs    = array_values( (array) $v( 'key_questions', array() ) );
		$pitch = (string) $v( 'pitch' );
		?>
		<div class="bzadv" data-bzadv="product">
			<p class="bzadv-h">Không bắt buộc — món chưa khai vẫn được gợi ý. Dữ liệu này vào prompt của trợ lý dưới dạng dữ liệu tham khảo, không phải lệnh.</p>
			<div class="bzadv-grid">
				<div><?php self::label( 'tier', 'Hạng', $errors ); ?>
					<select name="bizcity_advice[tier]" class="<?php echo self::err_class( 'tier', $errors ); ?>">
						<?php foreach ( $tiers as $k => $t ) : ?><option value="<?php echo esc_attr( $k ); ?>" <?php selected( $v( 'tier', 'normal' ), $k ); ?>><?php echo esc_html( $t ); ?></option><?php endforeach; ?>
					</select>
					<span class="bzadv-h">Chiến lược chỉ được ưu tiên khi hai món phù hợp ngang nhau (tối đa +5/100).</span>
				</div>
				<div><?php self::label( 'priority', 'Ưu tiên trong hạng', $errors, '0–100' ); ?>
					<input type="number" min="0" max="100" name="bizcity_advice[priority]" value="<?php echo esc_attr( (string) $v( 'priority', '' ) ); ?>" class="<?php echo self::err_class( 'priority', $errors ); ?>">
				</div>
				<div><?php self::label( 'audience', 'Dành cho', $errors, '≤ 5 mục' ); self::chips( 'audience', (array) $v( 'audience', array() ), $errors, 5, 40 ); ?></div>
				<div><?php self::label( 'age_min', 'Độ tuổi', $errors ); ?>
					<div class="bzadv-row">
						<input type="number" min="0" step="0.5" style="width:80px" name="bizcity_advice[age_min]" value="<?php echo esc_attr( (string) $v( 'age_min', '' ) ); ?>" class="<?php echo self::err_class( 'age_min', $errors ); ?>" aria-label="Tuổi từ"> –
						<input type="number" min="0" step="0.5" style="width:80px" name="bizcity_advice[age_max]" value="<?php echo esc_attr( (string) $v( 'age_max', '' ) ); ?>" class="<?php echo self::err_class( 'age_max', $errors ); ?>" aria-label="Tuổi đến">
						<select name="bizcity_advice[age_unit]" style="width:90px"><option value="year" <?php selected( $v( 'age_unit', 'year' ), 'year' ); ?>>năm</option><option value="month" <?php selected( $v( 'age_unit', 'year' ), 'month' ); ?>>tháng</option></select>
					</div>
					<?php self::field_error( 'age_max', $errors ); ?>
				</div>
				<div><?php self::label( 'goals', 'Giải quyết nhu cầu', $errors, '≤ 6 mục' ); self::chips( 'goals', (array) $v( 'goals', array() ), $errors, 6, 40 ); ?></div>
				<div><?php self::label( 'avoid_for', 'Không dùng cho', $errors, '(chống chỉ định — trợ lý loại món nếu khách có)' ); self::chips( 'avoid_for', (array) $v( 'avoid_for', array() ), $errors, 6, 40, true ); ?></div>
			</div>
			<?php self::label( 'key_questions', 'Câu nên hỏi trước khi gợi ý', $errors, '(≤ 3)' ); ?>
			<?php for ( $i = 0; $i < 3; $i++ ) : ?>
				<input type="text" style="margin-top:4px" name="bizcity_advice[key_questions][]" value="<?php echo esc_attr( (string) ( $qs[ $i ] ?? '' ) ); ?>" placeholder="<?php echo 0 === $i ? 'vd: Bé có hay táo bón không?' : 'Câu thứ ' . ( $i + 1 ) . ' (không bắt buộc)'; ?>" class="<?php echo self::err_class( 'key_questions', $errors ); ?>">
			<?php endfor; ?>
			<?php self::label( 'pitch', 'Lời giới thiệu cho khách', $errors, '<span data-bzadv-count="pitch" data-max="300">' . self::len( $pitch ) . '/300</span>', true ); ?>
			<textarea rows="2" name="bizcity_advice[pitch]" data-bzadv-counted="pitch" class="<?php echo self::err_class( 'pitch', $errors ); ?>"><?php echo esc_textarea( $pitch ); ?></textarea>
			<div class="bzadv-grid">
				<div><?php self::label( 'next_tool', 'Việc tiếp theo hợp với món', $errors ); ?>
					<select name="bizcity_advice[next_tool]"><?php foreach ( $nexts as $k => $t ) : ?><option value="<?php echo esc_attr( $k ); ?>" <?php selected( $v( 'next_tool', 'order' ), $k ); ?>><?php echo esc_html( $t ); ?></option><?php endforeach; ?></select>
				</div>
				<div><?php self::label( 'pair_with', 'Mua kèm', $errors, '(≤ 3 mã sản phẩm, cách nhau dấu phẩy)' ); ?>
					<input type="text" name="bizcity_advice[pair_with]" value="<?php echo esc_attr( self::ids_text( (array) $v( 'pair_with', array() ) ) ); ?>" placeholder="vd: 587, 612" class="<?php echo self::err_class( 'pair_with', $errors ); ?>">
				</div>
			</div>
			<?php self::label( 'compliance', 'Câu lưu ý trợ lý phải nói kèm', $errors, '(không bắt buộc, ≤ 200 ký tự)' ); ?>
			<input type="text" name="bizcity_advice[compliance]" value="<?php echo esc_attr( (string) $v( 'compliance' ) ); ?>" placeholder="vd: Sản phẩm không thay thế thuốc chữa bệnh" class="<?php echo self::err_class( 'compliance', $errors ); ?>">
			<div class="bzadv-row" style="margin-top:10px">
				<span class="bzadv-h">Bấm <b>Cập nhật</b> của sản phẩm để lưu.</span>
				<button type="button" class="button" data-bzadv-draft="<?php echo (int) $post_id; ?>" title="Trợ lý soạn nháp từ tên, mô tả, danh mục, thuộc tính của sản phẩm. Chỉ điền vào ô — bấm Cập nhật mới lưu.">✨ Gợi ý từ mô tả</button>
				<span class="bzadv-h" data-bzadv-draft-note role="status" aria-live="polite"></span>
			</div>
		</div>
		<?php
	}

	public static function on_save_product( $post_id, $post = null ): void {
		$post_id = (int) $post_id;
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ( function_exists( 'wp_is_post_revision' ) && wp_is_post_revision( $post_id ) ) ) {
			return;
		}
		if ( ! isset( $_POST[ self::NONCE ], $_POST['bizcity_advice'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE ] ) ), self::NONCE_ACT ) ) {
			return; // quick edit, REST, imports: advice untouched.
		}
		if ( ! current_user_can( 'edit_post', $post_id ) || ! is_array( $_POST['bizcity_advice'] ) ) {
			return;
		}
		$form   = wp_unslash( $_POST['bizcity_advice'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized by save_product().
		$result = self::save_product( $post_id, $form );
		if ( $result['errors'] ) {
			self::put_flash( 'p' . $post_id, array( 'errors' => $result['errors'], 'raw' => $result['raw'] ) );
			self::put_notice( 'error', self::errors_text( 'Tư vấn AI chưa lưu — sản phẩm vẫn được lưu. Sửa ô:', $result['errors'] ) );
		}
	}

	/**
	 * Save handler (testable): form → raw → cap check → sanitize → store (or delete when blank).
	 *
	 * @return array{saved:bool, deleted:bool, errors:array<string,string>, raw:array, clean:?array}
	 */
	public static function save_product( int $post_id, array $form ): array {
		$raw    = BizCity_Product_Advice_Form::from_product_form( $form );
		$errors = BizCity_Product_Advice_Form::cap_errors( $raw );
		if ( $errors ) {
			return array( 'saved' => false, 'deleted' => false, 'errors' => $errors, 'raw' => $raw, 'clean' => null );
		}
		$clean = BizCity_Product_Advice::sanitize( $raw );
		if ( BizCity_Product_Advice_Form::is_blank( $clean ) ) {
			self::io( 'delete_meta', $post_id );
			return array( 'saved' => false, 'deleted' => true, 'errors' => array(), 'raw' => $raw, 'clean' => null );
		}
		self::io( 'update_meta', $post_id, $clean );
		return array( 'saved' => true, 'deleted' => false, 'errors' => array(), 'raw' => $raw, 'clean' => $clean );
	}

	/* ── product_cat term box ─────────────────────────────────────── */

	public static function render_category_box( $term ): void {
		$term_id = (int) $term->term_id;
		$flash   = self::take_flash( 't' . $term_id );
		$errors  = $flash['errors'] ?? array();
		$a       = isset( $flash['raw'] ) ? $flash['raw'] : ( BizCity_Product_Advice::for_category( $term_id ) ?: BizCity_Product_Advice::sanitize_category( array() ) );
		$ask     = array_values( (array) ( $a['ask_first'] ?? array() ) );
		?>
		<tr class="form-field bzadv-term"><th scope="row">Tư vấn AI</th><td>
			<?php wp_nonce_field( self::NONCE_ACT, self::NONCE ); ?>
			<div class="bzadv" data-bzadv="category">
				<p class="bzadv-h">Câu trợ lý hỏi khi khách mới nói tới nhóm hàng này (<?php echo (int) $term->count; ?> món). Không bắt buộc.</p>
				<?php self::label( 'ask_first', 'Câu nên hỏi khi khách nói tới nhóm này', $errors, '(≤ 3)' ); ?>
				<?php for ( $i = 0; $i < 3; $i++ ) : ?>
					<input type="text" style="margin-top:4px" name="bizcity_advice[ask_first][]" value="<?php echo esc_attr( (string) ( $ask[ $i ] ?? '' ) ); ?>" placeholder="<?php echo 0 === $i ? 'vd: Bé mấy tháng/tuổi?' : 'Câu thứ ' . ( $i + 1 ) . ' (không bắt buộc)'; ?>" class="<?php echo self::err_class( 'ask_first', $errors ); ?>">
				<?php endfor; ?>
				<?php self::label( 'facets_to_ask', 'Thuộc tính nên hỏi', $errors, '≤ 6 mục' ); self::chips( 'facets_to_ask', (array) ( $a['facets_to_ask'] ?? array() ), $errors, 6, 40 ); ?>
				<?php self::label( 'synonyms', 'Từ đồng nghĩa khách hay dùng', $errors, '(giúp khớp khi món chưa có meta; vd: táo bón=khó tiêu)' ); self::chips( 'synonyms', (array) ( $a['synonyms'] ?? array() ), $errors, 20, 60 ); ?>
				<?php self::label( 'compliance', 'Câu lưu ý chung của nhóm', $errors, '(không bắt buộc, ≤ 200 ký tự)' ); ?>
				<input type="text" name="bizcity_advice[compliance]" value="<?php echo esc_attr( (string) ( $a['compliance'] ?? '' ) ); ?>" class="<?php echo self::err_class( 'compliance', $errors ); ?>">
			</div>
		</td></tr>
		<?php
	}

	public static function on_save_category( $term_id ): void {
		$term_id = (int) $term_id;
		if ( ! isset( $_POST[ self::NONCE ], $_POST['bizcity_advice'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE ] ) ), self::NONCE_ACT ) ) {
			return;
		}
		if ( ! ( current_user_can( 'manage_product_terms' ) || current_user_can( 'manage_options' ) ) || ! is_array( $_POST['bizcity_advice'] ) ) {
			return;
		}
		$result = self::save_category( $term_id, wp_unslash( $_POST['bizcity_advice'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized by save_category().
		if ( $result['errors'] ) {
			self::put_flash( 't' . $term_id, array( 'errors' => $result['errors'], 'raw' => $result['raw'] ) );
			self::put_notice( 'error', self::errors_text( 'Tư vấn AI của danh mục chưa lưu — danh mục vẫn được lưu. Sửa ô:', $result['errors'] ) );
		}
	}

	/** @return array{saved:bool, deleted:bool, errors:array<string,string>, raw:array, clean:?array} */
	public static function save_category( int $term_id, array $form ): array {
		$raw    = BizCity_Product_Advice_Form::from_category_form( $form );
		$errors = BizCity_Product_Advice_Form::cap_errors( $raw, true );
		if ( $errors ) {
			return array( 'saved' => false, 'deleted' => false, 'errors' => $errors, 'raw' => $raw, 'clean' => null );
		}
		$clean = BizCity_Product_Advice::sanitize_category( $raw );
		if ( BizCity_Product_Advice_Form::is_blank( $clean, true ) ) {
			self::io( 'delete_term_meta', $term_id );
			return array( 'saved' => false, 'deleted' => true, 'errors' => array(), 'raw' => $raw, 'clean' => null );
		}
		self::io( 'update_term_meta', $term_id, $clean );
		return array( 'saved' => true, 'deleted' => false, 'errors' => array(), 'raw' => $raw, 'clean' => $clean );
	}

	/* ── CSV on the product list ──────────────────────────────────── */

	public static function render_csv_bar(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'edit-product' !== $screen->id || ! self::can_csv() ) {
			return;
		}
		list( $with, $total ) = self::coverage();
		$tpl = wp_nonce_url( admin_url( 'admin-post.php?action=bizcity_advice_csv_template' ), self::CSV_NONCE );
		?>
		<div class="bzadv-csv" data-bzadv="csv">
			<strong>Nhập CSV tư vấn AI</strong>
			<form class="bzadv-row" method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:6px">
				<a class="button" href="<?php echo esc_url( $tpl ); ?>">Tải khuôn CSV</a>
				<input type="hidden" name="action" value="bizcity_advice_csv_import">
				<?php wp_nonce_field( self::CSV_NONCE ); ?>
				<input type="file" name="bizcity_advice_csv" accept=".csv,text/csv" required>
				<button type="submit" class="button">Nhập CSV</button>
				<span class="bzadv-h">Khuôn = <code>product-catalog-sample.csv</code> + cột <code><?php echo esc_html( implode( ', ', array_keys( BizCity_Product_Advice_Form::CSV_COLUMNS ) ) ); ?></code>. Danh sách cách nhau bằng <code>|</code>. Dòng lỗi báo đúng cột, dòng đúng vẫn nhập; ô trống giữ nguyên.</span>
			</form>
			<p class="bzadv-h" style="margin:6px 0 0"><b><?php echo (int) $with; ?>/<?php echo (int) $total; ?></b> món đã có thông tin tư vấn — không bắt buộc, bổ sung dần.</p>
		</div>
		<?php
	}

	public static function download_template(): void {
		if ( ! self::can_csv() || ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), self::CSV_NONCE ) ) {
			wp_die( 'Không có quyền.', '', array( 'response' => 403 ) );
		}
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="product-advice-' . gmdate( 'Ymd' ) . '.csv"' );
		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" );
		fputcsv( $out, BizCity_Product_Advice_Form::template_header() );
		$ids = get_posts( array( 'post_type' => 'product', 'post_status' => array( 'publish', 'draft', 'pending', 'private' ), 'fields' => 'ids', 'posts_per_page' => -1, 'orderby' => 'ID', 'order' => 'ASC', 'no_found_rows' => true ) );
		foreach ( $ids as $id ) {
			$id   = (int) $id;
			$p    = function_exists( 'wc_get_product' ) ? wc_get_product( $id ) : null;
			$cats = function_exists( 'wp_get_post_terms' ) ? wp_get_post_terms( $id, 'product_cat', array( 'fields' => 'names' ) ) : array();
			fputcsv( $out, BizCity_Product_Advice_Form::template_row( array(
				'id'       => $id,
				'sku'      => $p ? $p->get_sku() : '',
				'name'     => get_the_title( $id ),
				'category' => is_array( $cats ) ? implode( ' | ', $cats ) : '',
				'price'    => $p ? $p->get_price() : '',
			), BizCity_Product_Advice::for_product( $id ) ) );
		}
		fclose( $out );
		exit;
	}

	public static function import_csv(): void {
		if ( ! self::can_csv() || ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), self::CSV_NONCE ) ) {
			wp_die( 'Không có quyền.', '', array( 'response' => 403 ) );
		}
		$back = admin_url( 'edit.php?post_type=product' );
		$file = $_FILES['bizcity_advice_csv'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( ! is_array( $file ) || UPLOAD_ERR_OK !== (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) || ! is_uploaded_file( (string) $file['tmp_name'] ) ) {
			self::put_notice( 'error', array( 'Nhập CSV: chưa nhận được tệp.' ) );
			wp_safe_redirect( $back );
			exit;
		}
		if ( (int) $file['size'] > self::MAX_CSV ) {
			self::put_notice( 'error', array( 'Nhập CSV: tệp lớn hơn 2 MB — chia nhỏ rồi nhập lại.' ) );
			wp_safe_redirect( $back );
			exit;
		}
		$summary = self::import_text( (string) file_get_contents( (string) $file['tmp_name'] ) );
		$lines   = array( sprintf( 'Nhập CSV tư vấn: %d sản phẩm đã cập nhật, %d dòng lỗi.', $summary['updated'], count( $summary['errors'] ) ) );
		foreach ( array_slice( $summary['errors'], 0, 20 ) as $e ) {
			$lines[] = sprintf( 'Dòng %d%s: %s', $e['line'], '' !== $e['column'] ? ' · cột ' . $e['column'] : '', $e['message'] );
		}
		if ( count( $summary['errors'] ) > 20 ) {
			$lines[] = sprintf( '… và %d lỗi khác.', count( $summary['errors'] ) - 20 );
		}
		self::put_notice( $summary['errors'] ? 'warning' : 'success', $lines );
		wp_safe_redirect( $back );
		exit;
	}

	/**
	 * CSV text → stored advice (testable). Each good row: find the product, merge cells over its advice, sanitize, store.
	 *
	 * @return array{updated:int, errors:array<int,array{line:int,column:string,message:string}>}
	 */
	public static function import_text( string $csv ): array {
		$parsed  = BizCity_Product_Advice_Form::parse_csv( $csv );
		$errors  = $parsed['errors'];
		$updated = 0;
		foreach ( $parsed['rows'] as $row ) {
			$found = self::io( 'find_product', $row['match'] );
			if ( is_string( $found ) ) {
				$errors[] = array( 'line' => $row['line'], 'column' => (string) key( $row['match'] ), 'message' => $found );
				continue;
			}
			$id = (int) $found;
			if ( ! self::io( 'can_edit', $id ) ) {
				$errors[] = array( 'line' => $row['line'], 'column' => 'product_id', 'message' => 'không có quyền sửa sản phẩm #' . $id . '.' );
				continue;
			}
			$clean = BizCity_Product_Advice::sanitize( BizCity_Product_Advice_Form::merge( self::io( 'get_meta', $id ), $row['advice'] ) );
			self::io( 'update_meta', $id, $clean );
			$updated++;
		}
		usort( $errors, static function ( $a, $b ) { return $a['line'] <=> $b['line']; } );
		return array( 'updated' => $updated, 'errors' => $errors );
	}

	/* ── "Gợi ý từ mô tả" ─────────────────────────────────────────── */

	public static function ajax_draft(): void {
		if ( ! check_ajax_referer( self::DRAFT_ACT, 'nonce', false ) ) {
			wp_send_json( array( 'ok' => false, 'error' => 'Phiên làm việc hết hạn — tải lại trang rồi thử lại.' ), 403 );
		}
		$r = self::draft( isset( $_POST['product_id'] ) ? (int) $_POST['product_id'] : 0 ); // phpcs:ignore WordPress.Security.NonceVerification -- checked above
		wp_send_json( $r, $r['ok'] ? 200 : 400 );
	}

	/**
	 * Product id ⇒ {ok:true, draft:{audience,goals,avoid_for,key_questions,pitch}} or {ok:false, error}. Saves nothing.
	 * The cell's draft is cleaned again here to product-advice@1 caps (never trust the wire).
	 */
	public static function draft( int $product_id ): array {
		if ( $product_id <= 0 || ! self::io( 'can_edit', $product_id ) ) {
			return array( 'ok' => false, 'error' => 'Bạn không có quyền sửa sản phẩm này.' );
		}
		$product = (array) self::io( 'product_payload', $product_id );
		if ( '' === trim( (string) ( $product['name'] ?? '' ) ) ) {
			return array( 'ok' => false, 'error' => 'Không đọc được sản phẩm — lưu sản phẩm (có tên) trước rồi thử lại.' );
		}
		if ( '' === trim( (string) ( $product['short'] ?? '' ) . (string) ( $product['description'] ?? '' ) ) && empty( $product['attrs'] ) ) {
			return array( 'ok' => false, 'error' => 'Sản phẩm chưa có mô tả hay thuộc tính để trợ lý dựa vào — thêm mô tả ngắn trước.' );
		}
		$accounts = array_values( array_filter( array_map( 'strval', (array) self::io( 'accounts' ) ) ) );
		if ( ! $accounts ) {
			return array( 'ok' => false, 'error' => 'Website chưa nối số Zalo nào với trợ lý (cell) nên chưa soạn nháp được.' );
		}
		$r = (array) self::io( 'hub', '/zalo-hub/consult/advice-draft', array( 'account_id' => $accounts[0], 'product' => $product ) );
		if ( true !== ( $r['ok'] ?? null ) || ! is_array( $r['draft'] ?? null ) ) {
			$code = (string) ( $r['code'] ?? '' );
			if ( 'rate_limited' === $code ) {
				$msg = 'Bấm hơi nhanh — đợi một phút rồi thử lại.';
			} elseif ( 'hub_not_ready' === $code ) {
				$msg = 'Website chưa có khoá kết nối BizCity (1API) nên chưa hỏi được trợ lý.';
			} else {
				$msg = 'Trợ lý chưa soạn được nháp — thử lại sau ít phút hoặc nhập tay.';
			}
			return array( 'ok' => false, 'error' => $msg );
		}
		$d     = $r['draft'];
		$clean = static function ( $s ): string {
			return is_string( $s ) ? trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $s ) ) ) : '';
		};
		$list  = static function ( $v, int $n, int $len ) use ( $clean ): array {
			$out = array();
			foreach ( is_array( $v ) ? $v : array() as $x ) {
				$t = $clean( $x );
				if ( '' !== $t && mb_strlen( $t ) <= $len && ! in_array( $t, $out, true ) ) {
					$out[] = $t;
				}
				if ( count( $out ) >= $n ) {
					break;
				}
			}
			return $out;
		};
		$pitch = $clean( $d['pitch'] ?? null );
		return array(
			'ok'    => true,
			'draft' => array(
				'audience'      => $list( $d['audience'] ?? null, 5, 40 ),
				'goals'         => $list( $d['goals'] ?? null, 6, 40 ),
				'avoid_for'     => $list( $d['avoid_for'] ?? null, 6, 40 ),
				'key_questions' => $list( $d['key_questions'] ?? null, 3, 120 ),
				'pitch'         => mb_substr( $pitch, 0, 300 ),
			),
		);
	}

	/** Product ⇒ the text the cell may read (name, short, description, category names, ≤ 8 attributes). No price, stock or customer data. */
	public static function product_payload( int $id ): array {
		$p = function_exists( 'wc_get_product' ) ? wc_get_product( $id ) : null;
		if ( ! $p ) {
			return array();
		}
		$txt   = static function ( $s, int $n ): string {
			return mb_substr( trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( (string) $s ) ) ), 0, $n );
		};
		$cats  = wp_get_post_terms( $id, 'product_cat', array( 'fields' => 'names' ) );
		$attrs = array();
		foreach ( (array) $p->get_attributes() as $key => $attr ) {
			$label = function_exists( 'wc_attribute_label' ) ? wc_attribute_label( (string) $key, $p ) : (string) $key;
			$val   = $txt( $p->get_attribute( (string) $key ), 80 );
			if ( '' !== $val ) {
				$attrs[ $txt( $label, 40 ) ] = $val;
			}
			if ( count( $attrs ) >= 8 ) {
				break;
			}
		}
		return array(
			'name'        => $txt( $p->get_name(), 200 ),
			'short'       => $txt( $p->get_short_description(), 500 ),
			'description' => $txt( $p->get_description(), 1500 ),
			'categories'  => is_wp_error( $cats ) ? array() : array_slice( array_map( 'strval', (array) $cats ), 0, 5 ),
			'attrs'       => $attrs,
		);
	}

	/** match {product_id?, sku?, product_name?} ⇒ product id, or a Vietnamese error string. */
	public static function find_product( array $match ) {
		if ( isset( $match['product_id'] ) ) {
			$id = (int) $match['product_id'];
			return ( $id > 0 && 'product' === get_post_type( $id ) ) ? $id : 'không tìm thấy sản phẩm #' . $match['product_id'] . '.';
		}
		if ( isset( $match['sku'] ) && function_exists( 'wc_get_product_id_by_sku' ) ) {
			$id = (int) wc_get_product_id_by_sku( $match['sku'] );
			if ( $id > 0 ) {
				return $id;
			}
			if ( ! isset( $match['product_name'] ) ) {
				return 'không tìm thấy SKU "' . $match['sku'] . '".';
			}
		}
		if ( isset( $match['product_name'] ) ) {
			$ids = get_posts( array( 'post_type' => 'product', 'post_status' => array( 'publish', 'draft', 'pending', 'private' ), 'title' => $match['product_name'], 'fields' => 'ids', 'posts_per_page' => 2, 'no_found_rows' => true ) );
			if ( 1 === count( $ids ) ) {
				return (int) $ids[0];
			}
			return $ids ? 'nhiều sản phẩm trùng tên "' . $match['product_name'] . '" — thêm product_id.' : 'không tìm thấy sản phẩm tên "' . $match['product_name'] . '".';
		}
		return 'không tìm thấy sản phẩm.';
	}

	/* ── notices + flash ──────────────────────────────────────────── */

	public static function print_notices(): void {
		$key = 'bizcity_advice_notice_' . get_current_user_id();
		$n   = get_transient( $key );
		if ( ! is_array( $n ) || empty( $n['lines'] ) ) {
			return;
		}
		delete_transient( $key );
		$type = in_array( $n['type'] ?? '', array( 'error', 'warning', 'success' ), true ) ? $n['type'] : 'info';
		echo '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible"><p>' . implode( '<br>', array_map( 'esc_html', (array) $n['lines'] ) ) . '</p></div>';
	}

	private static function put_notice( string $type, array $lines ): void {
		set_transient( 'bizcity_advice_notice_' . get_current_user_id(), array( 'type' => $type, 'lines' => $lines ), self::NOTICE_TTL );
	}

	private static function put_flash( string $id, array $data ): void {
		set_transient( 'bizcity_advice_flash_' . get_current_user_id() . '_' . $id, $data, self::NOTICE_TTL );
	}

	private static function take_flash( string $id ): array {
		if ( ! function_exists( 'get_transient' ) ) {
			return array();
		}
		$key = 'bizcity_advice_flash_' . get_current_user_id() . '_' . $id;
		$v   = get_transient( $key );
		if ( false !== $v ) {
			delete_transient( $key );
		}
		return is_array( $v ) ? $v : array();
	}

	/** ["Prefix", "• Lời giới thiệu cho khách: tối đa 300 ký tự (đang có 342)."] */
	public static function errors_text( string $prefix, array $errors ): array {
		$lines = array( $prefix );
		foreach ( $errors as $field => $msg ) {
			$lines[] = '• ' . ( BizCity_Product_Advice_Form::LABELS[ $field ] ?? $field ) . ': ' . $msg;
		}
		return $lines;
	}

	/* ── render helpers ───────────────────────────────────────────── */

	private static function label( string $field, string $text, array $errors, string $hint = '', bool $hint_html = false ): void {
		echo '<label class="bzadv-l">' . esc_html( $text );
		if ( '' !== $hint ) {
			echo ' <span class="bzadv-h">' . ( $hint_html ? wp_kses( $hint, array( 'span' => array( 'data-bzadv-count' => true, 'data-max' => true ) ) ) : esc_html( $hint ) ) . '</span>';
		}
		echo '</label>';
		self::field_error( $field, $errors );
	}

	private static function field_error( string $field, array $errors ): void {
		if ( isset( $errors[ $field ] ) ) {
			echo '<div class="bzadv-errmsg" data-bzadv-error="' . esc_attr( $field ) . '">' . esc_html( $errors[ $field ] ) . '</div>';
		}
	}

	private static function err_class( string $field, array $errors ): string {
		return isset( $errors[ $field ] ) ? 'bzadv-err' : '';
	}

	/** Chip widget: textarea (one item per line) is the source of truth; JS turns it into chips (visible as-is without JS). */
	private static function chips( string $field, array $items, array $errors, int $max, int $len, bool $bad = false ): void {
		printf(
			'<textarea rows="2" name="bizcity_advice[%1$s]" class="bzadv-chip-src %2$s" data-bzadv-chips="%1$s" data-max="%3$d" data-len="%4$d" data-bad="%5$d" placeholder="Mỗi dòng một mục">%6$s</textarea>',
			esc_attr( $field ),
			esc_attr( self::err_class( $field, $errors ) ),
			(int) $max,
			(int) $len,
			$bad ? 1 : 0,
			esc_textarea( implode( "\n", array_map( 'strval', $items ) ) )
		);
	}

	private static function ids_text( array $ids ): string {
		return implode( ', ', array_map( 'intval', $ids ) );
	}

	private static function len( string $s ): int {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $s, 'UTF-8' ) : strlen( $s );
	}

	private static function can_csv(): bool {
		return current_user_can( 'edit_products' ) || current_user_can( 'manage_options' );
	}

	/** [products with advice, all products] for the status line. */
	private static function coverage(): array {
		global $wpdb;
		$with  = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(DISTINCT pm.post_id) FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = %s AND p.post_type = 'product' AND p.post_status IN ('publish','draft','pending','private')",
			BizCity_Product_Advice::META_KEY
		) );
		$c     = wp_count_posts( 'product' );
		$total = (int) ( ( $c->publish ?? 0 ) + ( $c->draft ?? 0 ) + ( $c->pending ?? 0 ) + ( $c->private ?? 0 ) );
		return array( $with, $total );
	}

	/** I/O through seams (tests) or WordPress. */
	private static function io( string $op, ...$args ) {
		if ( isset( self::$io[ $op ] ) ) {
			return call_user_func_array( self::$io[ $op ], $args );
		}
		switch ( $op ) {
			case 'get_meta':
				return BizCity_Product_Advice::for_product( (int) $args[0] );
			case 'update_meta':
				return update_post_meta( (int) $args[0], BizCity_Product_Advice::META_KEY, $args[1] );
			case 'delete_meta':
				return delete_post_meta( (int) $args[0], BizCity_Product_Advice::META_KEY );
			case 'update_term_meta':
				return update_term_meta( (int) $args[0], BizCity_Product_Advice::META_KEY, $args[1] );
			case 'delete_term_meta':
				return delete_term_meta( (int) $args[0], BizCity_Product_Advice::META_KEY );
			case 'find_product':
				return self::find_product( (array) $args[0] );
			case 'can_edit':
				return current_user_can( 'edit_post', (int) $args[0] );
			case 'product_payload':
				return self::product_payload( (int) $args[0] );
			case 'accounts':
				$ids = array();
				if ( class_exists( 'BizCity_Zalo_Hub_Config_Sync' ) ) {
					foreach ( (array) BizCity_Zalo_Hub_Config_Sync::accounts() as $a ) {
						$ids[] = (string) ( $a['bridge_id'] ?? '' );
					}
				}
				return $ids;
			case 'hub':
				if ( ! class_exists( 'BizCity_Zalo_Personal_Hub_Client' ) || ! BizCity_Zalo_Personal_Hub_Client::instance()->is_ready_fast() ) {
					return array( 'ok' => false, 'code' => 'hub_not_ready' );
				}
				return (array) BizCity_Zalo_Personal_Hub_Client::instance()->post_managed_path( (string) $args[0], (array) $args[1] );
		}
		return null;
	}
}
