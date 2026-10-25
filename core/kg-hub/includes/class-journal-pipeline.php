<?php
/**
 * BizCity_Journal_Pipeline — `journal-pipeline@1`: the four steps a person sees a file go through
 * (Ghi sổ ⇒ Đọc ⇒ Vào kho ⇒ Học sâu-optional), DERIVED from one journal row — nothing stored, no queue table.
 *
 *   indexed  "Ghi sổ"  the row exists (find_text / pack membership are what "indexed" means)
 *   read     "Đọc"     metadata.meta.status (entry-meta@1): done · pending ⇒ doing · failed · skipped · none (never read) · na (no file)
 *   vaulted  "Vào kho" metadata.resource.representations[] has a vault:// ref (brain-resource-link@1); knowledge.status stale ⇒ stale
 *   studied  "Học sâu" kg source + metadata.learning{level, embedded_at, graph_state, status} ⇒ optional · doing (sub: chunks|embed|graph) · done · failed
 *
 * `level` stays the knowledge-ladder@1 L0–L4 of BizCity_Journal_Brain_Map::doc_level() — one ladder (PHASE-0.129); the pipeline only
 * retells it in four words. Read by: entry-card (?view=card), brain-map doc nodes (owner panel), Twin Vault list / tree rows, and
 * GET bizcity-knowledge/v2/journal/pipeline (the "Bảng tệp" of Kho tri thức 00 Home). POST journal/rebuild-read asks the cell to
 * read ≤ 20 old files again per call (same door as "Đọc lại": BizCity_Journal_Index::request_reread), never a request that walks the table.
 *
 * Biz Central Brain — Johnny Chu (Chu Hoàng Anh).
 *
 * // [2026-10-25 12:20 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.137 S137-B1/B2/B4 (doc 00 §3, D137-1/6) — new file.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\KG_Hub
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BizCity_Journal_Pipeline {
	const CONTRACT     = 'journal-pipeline@1.0.0';
	const NS           = 'bizcity-knowledge/v2';
	const STEPS        = array( 'indexed', 'read', 'vaulted', 'studied' );
	const LABELS       = array( 'indexed' => 'Ghi sổ', 'read' => 'Đọc', 'vaulted' => 'Vào kho', 'studied' => 'Học sâu' );
	const DOING_WINDOW = 120;   // seconds after a finished read during which "no Markdown yet" still reads as doing (the cell materializes right after meta.set)
	const SCAN         = 200;   // rows one list call derives (same window brain-map scans in pages)
	const REBUILD_MAX  = 20;    // files one rebuild call re-queues (R-PERF: no bulk work in a web request)
	const RANGES       = array( 'today' => 1, '7d' => 7, '30d' => 30, 'all' => 0 );

	private static $hooked = false;

	public static function register(): void {
		if ( self::$hooked || ! function_exists( 'add_action' ) ) {
			return;
		}
		self::$hooked = true;
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		// [2026-10-25 01:32 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.137 S137-B6 — the assistant reaches the same study door (Zalo "3 = học sâu luôn", "học kỹ 3 tệp này")
		add_action( 'bizcity_mcp_register_tools', array( __CLASS__, 'register_tools' ) );
		if ( function_exists( 'did_action' ) && did_action( 'bizcity_mcp_register_tools' ) ) {
			self::register_tools();
		}
	}

	public static function register_tools(): void {
		if ( ! class_exists( 'BizCity_MCP_Tool_Registry' ) ) {
			return;
		}
		$R = 'BizCity_MCP_Tool_Registry';
		// @mcp bizcity-mcp-standard@1 tool notebook.study
		$R::register( 'notebook.study', array(
			'title'          => 'Nghiên cứu sâu tệp trong Kho tri thức',
			'description'    => 'Học sâu 1–20 tệp ĐÃ VÀO KHO (có bản đọc Markdown) theo mã TL-…: nạp bản đọc vào một notebook (mới, hoặc notebook_id có sẵn của chủ) ⇒ cắt đoạn, nhúng, và dựng đồ thị tri thức khi kg=true; sau đó hỏi được nội dung có trích dẫn. Dùng khi chủ nói "học sâu / nghiên cứu kỹ / học kỹ các tệp này", hoặc trả lời "3" ở tin "Vào kho xong". Tệp chưa có bản đọc ⇒ mục đó báo read_first (bảo chủ chờ hoặc nhắn "đọc lại TL-…"); đã học ⇒ already. KHÔNG dùng để đọc hay tóm tắt một tệp (dùng vault.get / document.text.read), KHÔNG tự học sâu khi chủ không yêu cầu (tốn token của chủ), không dùng cho kỹ năng.',
			'input_schema'   => array( 'type' => 'object', 'required' => array( 'refs' ), 'properties' => array(
				'refs'        => array( 'type' => 'array', 'maxItems' => self::STUDY_MAX, 'items' => array( 'type' => 'string', 'maxLength' => 24 ), 'description' => 'Mã các tệp cần học sâu, vd ["TL-7255","TL-7253"] (lấy từ biên nhận, vault.list hoặc vault.search).' ),
				'notebook_id' => array( 'type' => 'integer', 'minimum' => 1, 'description' => 'Notebook có sẵn của chủ để học vào; bỏ trống = tạo notebook mới.' ),
				'title'       => array( 'type' => 'string', 'maxLength' => 120, 'description' => 'Tên notebook mới; bỏ trống = tên thư mục của tệp đầu hoặc "Nghiên cứu dd/mm".' ),
				'kg'          => array( 'type' => 'boolean', 'description' => 'true (mặc định) = dựng cả đồ thị tri thức; false = chỉ cắt đoạn + nhúng.' ),
			) ),
			'output_schema'  => $R::envelope_schema( array(
				'notebook' => array( 'type' => array( 'object', 'null' ) ),
				'items'    => array( 'type' => 'array' ),
				'learned'  => array( 'type' => 'integer' ),
				'skipped'  => array( 'type' => 'integer' ),
			), array( 'learned' ) ),
			'read_only'      => false,
			'destructive'    => false,
			'idempotent'     => true,
			'required_scope' => 'memory.write',
			'required_cap'   => 'edit_posts',
			'scopes'         => array( 'memory.write' ),
			'handler'        => array( __CLASS__, 'mcp_study' ),
			'mode'           => 'notebook',
			'roles'          => array( 'owner', 'staff' ),
			'confirm'        => 'never',
			'fallback_pack'  => null,
			'since'          => '0.137.0',
			'llm_alias'      => 'study_documents',
			'brain'          => array( 'layer' => 'notebook', 'permission' => 'write', 'crud' => 'write' ),
		) );
	}

	/** MCP door of study: the principal from the MCP context; service errors ⇒ the MCP catalog error with the reason kept. */
	public static function mcp_study( array $args, array $ctx ) {
		$res = self::study( array( 'owner_user_id' => (int) ( $ctx['owner_user_id'] ?? $ctx['user_id'] ?? 0 ), 'role' => 'owner' === (string) ( $ctx['role'] ?? '' ) ? 'owner' : 'staff' ), $args );
		if ( ! is_wp_error( $res ) ) {
			return $res;
		}
		$d = (array) $res->get_error_data();
		return new WP_Error( class_exists( 'BizCity_MCP_Error' ) ? BizCity_MCP_Error::QUERY_INVALID : 'query_invalid', $res->get_error_message(), array( 'status' => (int) ( $d['status'] ?? 400 ), 'reason' => $res->get_error_code(), 'hint' => (string) ( $d['hint'] ?? '' ) ) );
	}

	public static function routes(): void {
		register_rest_route( self::NS, '/journal/pipeline', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'rest_list' ),
			'permission_callback' => static function () { return is_user_logged_in(); },
		) );
		register_rest_route( self::NS, '/journal/rebuild-read', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'rest_rebuild' ),
			'permission_callback' => static function () { return current_user_can( 'manage_options' ); },
		) );
		// [2026-10-25 01:18 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.137 S137-B6 (D137-7) — the ONE door from Kho tri thức to a notebook ("Nghiên cứu sâu")
		register_rest_route( self::NS, '/notebooks/from-refs', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'rest_study' ),
			'permission_callback' => static function () { return current_user_can( 'edit_posts' ); },
		) );
	}

	// ── study: Kho tri thức ⇒ notebook (D137-7/8) ───────────────────────

	const STUDY_MAX = 20;

	/**
	 * Learn ≤ 20 journal rows deeply: each row's READABLE Markdown (vault://, latest rev — never the original file, never a second OCR) becomes one
	 * source of a notebook (new or given), the row receives kg_source_id · notebook_id · indexed_rev, and with `kg` the graph pass is queued through
	 * the same handler as "Học kỹ" (document.learn.set L2). A row with no Markdown ⇒ `read_first` (bấm Đọc lại trước); already learned and not stale
	 * ⇒ `already` unless `force`. The Journal row stays the one identity: the ingest listener is told (metadata.journal_entry_id) not to open a second row.
	 *
	 * @param array $ctx  {owner_user_id, role}
	 * @param array $args {refs: string[] (TL-… | JE-… | entry ids), notebook_id?: int, title?: string, kg?: bool (default true), force?: bool}
	 * @return array|WP_Error
	 */
	public static function study( array $ctx, array $args ) {
		$owner = (int) ( $ctx['owner_user_id'] ?? 0 );
		$role  = (string) ( $ctx['role'] ?? 'staff' );
		if ( $owner <= 0 || ! class_exists( 'BizCity_Journal_Index' ) || ! class_exists( 'BizCity_Brain_Vault_Store' ) ) {
			return new WP_Error( 'study_unavailable', 'Kho tri thức chưa sẵn sàng trên site này.', array( 'status' => 503, 'hint' => 'Kiểm tra module kg-hub đã bật.', 'help_code' => 'journal_pipeline' ) );
		}
		if ( ! class_exists( 'BizCity_TwinChat_Sources_Service' ) || ! class_exists( 'BizCity_KG_Notebook_Service' ) ) {
			return new WP_Error( 'study_unavailable', 'Chưa có nơi học sâu (TwinChat / KG-Hub chưa nạp).', array( 'status' => 503, 'hint' => 'Mở từ trang TwinChat hoặc bật module twinchat.', 'help_code' => 'journal_pipeline' ) );
		}
		$refs = array_values( array_unique( array_filter( array_map( static function ( $r ) { return trim( (string) $r ); }, (array) ( $args['refs'] ?? array() ) ) ) ) );
		if ( ! $refs ) {
			return new WP_Error( 'study_refs_empty', 'Chưa chọn tệp nào để học sâu.', array( 'status' => 400, 'hint' => 'Chọn ít nhất một tệp trong Bảng tệp hoặc thư mục.', 'help_code' => 'journal_pipeline' ) );
		}
		if ( count( $refs ) > self::STUDY_MAX ) {
			return new WP_Error( 'study_refs_too_many', 'Mỗi lần học sâu tối đa ' . self::STUDY_MAX . ' tệp.', array( 'status' => 400, 'hint' => 'Chọn ít hơn hoặc chia làm nhiều lượt.', 'help_code' => 'journal_pipeline' ) );
		}
		$kg    = ! array_key_exists( 'kg', $args ) || ! empty( $args['kg'] );
		$force = ! empty( $args['force'] );
		$rows  = array();
		$items = array();
		foreach ( $refs as $ref ) {
			$row = ctype_digit( $ref ) ? BizCity_Journal_Index::get( (int) $ref, $owner ) : BizCity_Journal_Index::find_by_ref( $ref, $owner );
			if ( ! $row ) {
				$items[] = array( 'ref' => $ref, 'entry_id' => 0, 'status' => 'not_found', 'error' => self::error_of( array( 'code' => 'journal_not_found', 'message' => 'Không có mã này trong sổ của bạn.', 'hint' => 'Kiểm lại mã TL-… trên thẻ.', 'help_code' => 'journal_pipeline' ) ) );
				continue;
			}
			$rows[] = $row;
		}
		if ( class_exists( 'BizCity_Brain_Access' ) ) {
			$rows = BizCity_Brain_Access::filter( $rows, BizCity_Brain_Access::principal( $ctx ), 'meta.read' );
		}
		// the notebook: given (must be the person's) or new
		$nbs = BizCity_KG_Notebook_Service::instance();
		$nb  = null;
		if ( (int) ( $args['notebook_id'] ?? 0 ) > 0 ) {
			$nb = $nbs->get( (int) $args['notebook_id'] );
			if ( ! $nb || (int) ( $nb['owner_id'] ?? 0 ) !== $owner ) {
				return new WP_Error( 'study_notebook_denied', 'Notebook này không phải của bạn.', array( 'status' => 404, 'hint' => 'Chọn notebook của bạn hoặc tạo mới.', 'help_code' => 'journal_pipeline' ) );
			}
		}
		$ready = array_values( array_filter( $rows, static function ( $e ) use ( $force ) {
			$has_md = ! empty( $e['resource']['representations'] );
			$kgid   = (int) ( $e['kg']['source_id'] ?? 0 );
			$stale  = 'stale' === (string) ( $e['resource']['knowledge']['status'] ?? '' );
			return $has_md && ( $kgid <= 0 || $stale || $force );
		} ) );
		if ( ! $nb && $ready ) {
			$title = trim( (string) ( $args['title'] ?? '' ) );
			if ( '' === $title ) {
				$first = $ready[0];
				$title = '' !== (string) ( $first['meta']['folder'] ?? '' ) && class_exists( 'BizCity_Twin_Vault' ) && method_exists( 'BizCity_Twin_Vault', 'folder_label' ) ? (string) BizCity_Twin_Vault::folder_label( (string) $first['meta']['folder'] ) : '';
				$title = '' !== $title ? $title : 'Nghiên cứu ' . gmdate( 'd/m' );
			}
			$created = $nbs->create( array( 'name' => mb_substr( $title, 0, 120 ), 'description' => 'Tạo từ Kho tri thức (PHASE-0.137): nguồn là bản đọc Markdown của ' . count( $ready ) . ' tệp.', 'notebook_scope' => 'personal' ), $owner );
			if ( is_wp_error( $created ) ) {
				return $created;
			}
			$nb = is_array( $created ) ? $created : $nbs->get( (int) $created );
			if ( ! $nb ) {
				return new WP_Error( 'study_notebook_failed', 'Không tạo được notebook.', array( 'status' => 500, 'hint' => 'Thử lại; vẫn lỗi thì xem log site.', 'help_code' => 'journal_pipeline' ) );
			}
		}
		$svc  = BizCity_TwinChat_Sources_Service::instance();
		$nbid = (int) ( $nb['id'] ?? 0 );
		foreach ( $rows as $e ) {
			$id   = (int) $e['entry_id'];
			$ref  = (string) ( $e['ref_code'] ?? $id );
			$kgid = (int) ( $e['kg']['source_id'] ?? 0 );
			if ( empty( $e['resource']['representations'] ) ) {
				$items[] = array( 'ref' => $ref, 'entry_id' => $id, 'status' => 'read_first', 'error' => self::error_of( array( 'code' => 'read_first', 'message' => 'Tệp chưa có bản đọc.', 'hint' => 'Bấm Đọc lại, chờ nấc Vào kho xanh rồi học sâu.', 'help_code' => 'journal_pipeline' ) ) );
				continue;
			}
			if ( $kgid > 0 && ! $force && 'stale' !== (string) ( $e['resource']['knowledge']['status'] ?? '' ) ) {
				$items[] = array( 'ref' => $ref, 'entry_id' => $id, 'status' => 'already', 'kg_source_id' => $kgid, 'notebook_id' => (int) ( $e['kg']['notebook_ids'][0] ?? 0 ) );
				continue;
			}
			// the whole readable text, page by page (the store slices at 6 000 chars)
			$md   = '';
			$rev  = 0;
			$vref = '';
			for ( $off = 0, $guard = 0; $guard < 200; $guard++ ) {
				$part = BizCity_Brain_Vault_Store::read( $e, 0, $off, 6000 );
				if ( is_wp_error( $part ) ) {
					break;
				}
				$md  .= (string) $part['markdown'];
				$rev  = (int) $part['rev'];
				$vref = (string) $part['ref'];
				if ( null === $part['next_offset'] ) {
					break;
				}
				$off = (int) $part['next_offset'];
			}
			if ( '' === trim( $md ) ) {
				$items[] = array( 'ref' => $ref, 'entry_id' => $id, 'status' => 'read_first', 'error' => self::error_of( array( 'code' => 'vault_empty', 'message' => 'Bản đọc rỗng.', 'hint' => 'Bấm Đọc lại để trợ lý đọc tệp lần nữa.', 'help_code' => 'journal_pipeline' ) ) );
				continue;
			}
			$title = '' !== (string) ( $e['meta']['title'] ?? '' ) ? (string) $e['meta']['title'] : (string) ( $e['title'] ?? $ref );
			$res   = $svc->ingest( $nbid, $owner, array(
				'type'     => 'text',
				'title'    => mb_substr( $title, 0, 200 ),
				'content'  => $md,
				'metadata' => array( 'origin' => 'vault', 'journal_entry_id' => $id, 'journal_ref' => $ref, 'vault_ref' => $vref, 'vault_rev' => $rev, 'source_ref' => (string) ( $e['resource']['source_ref'] ?? $ref ) ),
			) );
			if ( is_wp_error( $res ) ) {
				$items[] = array( 'ref' => $ref, 'entry_id' => $id, 'status' => 'failed', 'error' => self::error_of( array( 'code' => $res->get_error_code(), 'message' => $res->get_error_message(), 'hint' => 'Thử lại; nếu nội dung rỗng hãy Đọc lại tệp.', 'help_code' => 'journal_pipeline' ) ) );
				continue;
			}
			$new_kg = (int) ( $res['kg_source_id'] ?? 0 );
			$db     = BizCity_Journal_Database::instance();
			$db->mark_learning_projection( $id, $owner, array( 'learning_status' => 'learned', 'learning_error' => '', 'notebook_id' => $nbid, 'kg_source_id' => $new_kg > 0 ? $new_kg : $kgid ) );
			BizCity_Journal_Index::file( $id, $owner, array(
				'learning' => array( 'level' => 'L1', 'text_ready' => true, 'passages' => (int) ( $res['chunk_count'] ?? 0 ), 'embedded_at' => gmdate( 'c' ) ),
				'resource' => array( 'knowledge' => array( 'kg_source_id' => $new_kg > 0 ? $new_kg : $kgid, 'indexed_rev' => $rev, 'status' => 'ready' ) ),
			) );
			$graph = 'none';
			if ( $kg && class_exists( 'BizCity_KG_Private_File_Store' ) && method_exists( 'BizCity_KG_Private_File_Store', 'mcp_learn_set' ) ) {
				$g = BizCity_KG_Private_File_Store::mcp_learn_set( array( 'entry_id' => $id, 'level' => 'L2' ), array( 'user_id' => $owner, 'role' => in_array( $role, array( 'owner', 'staff' ), true ) ? $role : 'staff', 'allowed_notebook_ids' => array() ) );
				$graph = is_wp_error( $g ) ? 'skipped:' . $g->get_error_code() : (string) ( $g['graph_state'] ?? 'pending' );
			}
			$items[] = array( 'ref' => $ref, 'entry_id' => $id, 'status' => 'learned', 'kg_source_id' => $new_kg, 'notebook_id' => $nbid, 'passages' => (int) ( $res['chunk_count'] ?? 0 ), 'indexed_rev' => $rev, 'graph' => $graph, 'duplicate' => ! empty( $res['duplicate'] ) );
		}
		$learned = count( array_filter( $items, static function ( $i ) { return 'learned' === $i['status']; } ) );
		return array(
			'contract' => self::CONTRACT,
			'notebook' => $nb ? array( 'id' => $nbid, 'name' => (string) ( $nb['name'] ?? '' ) ) : null,
			'items'    => $items,
			'learned'  => $learned,
			'skipped'  => count( $items ) - $learned,
			'kg'       => $kg,
		);
	}

	public static function rest_study( WP_REST_Request $request ): WP_REST_Response {
		$body = $request->get_json_params();
		$args = is_array( $body ) ? $body : (array) $request->get_body_params();
		$res  = self::study( self::ctx(), $args );
		if ( is_wp_error( $res ) ) {
			$d = (array) $res->get_error_data();
			return new WP_REST_Response( array( 'code' => $res->get_error_code(), 'message' => $res->get_error_message(), 'hint' => (string) ( $d['hint'] ?? '' ), 'help_code' => (string) ( $d['help_code'] ?? 'journal_pipeline' ) ), (int) ( $d['status'] ?? 400 ) );
		}
		return new WP_REST_Response( $res, 200 );
	}

	// ── pure ─────────────────────────────────────────────────────────────

	/**
	 * The four steps of one journal-entry@1 row. Pure: no DB, no clock unless `$now` is null (then gmdate).
	 *
	 * @param array       $e     journal-entry@1 (as BizCity_Journal_Index::to_journal_entry returns it)
	 * @param string|null $level knowledge-ladder level already decided by brain-map (L0…L4) or null = unknown
	 * @param string|null $now   ISO-8601 UTC for the doing window; null = now
	 */
	public static function of( array $e, ?string $level = null, ?string $now = null ): array {
		$now_ts   = null !== $now ? (int) strtotime( $now ) : time();
		$meta     = is_array( $e['meta'] ?? null ) ? $e['meta'] : array();
		$res      = is_array( $e['resource'] ?? null ) ? $e['resource'] : array();
		$learn    = is_array( $e['learning'] ?? null ) ? $e['learning'] : array();
		$has_file = (int) ( $e['original']['attachment_id'] ?? 0 ) > 0;
		$ref      = (string) ( $e['ref_code'] ?? '' );
		$steps    = array();

		// ① indexed — the row IS the index (R-JOURNAL): created_at is the moment the file was booked
		$steps[] = array( 'id' => 'indexed', 'label' => self::LABELS['indexed'], 'state' => 'done', 'at' => (string) ( $e['created_at'] ?? '' ), 'why' => '' !== $ref ? $ref : 'row' );

		// ② read — entry-meta@1 status
		$ms      = (string) ( $meta['status'] ?? '' );
		$read_at = (string) ( $meta['by']['at'] ?? '' );
		$read    = array( 'id' => 'read', 'label' => self::LABELS['read'], 'state' => 'none', 'at' => $read_at );
		if ( ! $has_file && '' === $ms ) {
			$read['state'] = 'na'; // a note / sheet row has nothing to OCR
		} elseif ( 'done' === $ms || ( '' === $ms && ( '' !== (string) ( $meta['about'] ?? '' ) || '' !== (string) ( $meta['ocr']['text'] ?? '' ) ) ) ) {
			$read['state'] = 'done';
			$read['why']   = self::read_why( $meta );
		} elseif ( 'pending' === $ms ) {
			$read['state'] = 'doing';
			$read['op']    = substr( (string) ( $meta['by']['op'] ?? '' ), 0, 24 );
		} elseif ( 'failed' === $ms ) {
			$read['state'] = 'failed';
			$read['error'] = self::error_of( is_array( $meta['error'] ?? null ) ? $meta['error'] : array() );
		} elseif ( 'skipped' === $ms ) {
			$read['state'] = 'skipped';
			$read['error'] = self::error_of( is_array( $meta['error'] ?? null ) ? $meta['error'] : array() );
		}
		$steps[] = $read;

		// ③ vaulted — a vault:// representation of this row (brain-resource-link@1)
		$rep = null;
		foreach ( (array) ( $res['representations'] ?? array() ) as $r ) {
			if ( is_array( $r ) && 0 === strpos( (string) ( $r['ref'] ?? '' ), 'vault://' ) ) {
				$rep = $r; // list is oldest ⇒ newest; keep the last
			}
		}
		$folder  = (string) ( $meta['folder'] ?? '' );
		$vaulted = array( 'id' => 'vaulted', 'label' => self::LABELS['vaulted'], 'state' => 'none', 'at' => '', 'folder' => $folder );
		if ( $rep ) {
			$vaulted['state'] = 'stale' === (string) ( $res['knowledge']['status'] ?? '' ) ? 'stale' : 'done';
			$vaulted['ref']   = (string) $rep['ref'];
			$vaulted['rev']   = max( 1, (int) ( $rep['rev'] ?? 1 ) );
			$vaulted['at']    = '' !== $read_at ? $read_at : (string) ( $e['updated_at'] ?? '' );
		} elseif ( 'done' === $read['state'] && '' !== $read_at && $now_ts - (int) strtotime( $read_at ) < self::DOING_WINDOW ) {
			$vaulted['state'] = 'doing';
		} elseif ( 'na' === $read['state'] ) {
			$vaulted['state'] = 'na';
		}
		$steps[] = $vaulted;

		// ④ studied — optional; only when the row is a KG source
		$kg      = (int) ( $e['kg']['source_id'] ?? 0 ) > 0 || (int) ( $res['knowledge']['kg_source_id'] ?? 0 ) > 0;
		$lstatus = (string) ( $learn['status'] ?? '' );
		$graph   = (string) ( $learn['graph_state'] ?? 'none' );
		$studied = array( 'id' => 'studied', 'label' => self::LABELS['studied'], 'state' => 'optional', 'at' => '' );
		if ( 'failed' === $lstatus ) {
			$studied['state'] = 'failed';
			$studied['error'] = self::error_of( array( 'code' => 'learning_failed', 'message' => (string) ( $learn['error'] ?? 'Học sâu không xong.' ), 'hint' => 'Bấm "Học lại" để thử lại.' ) );
		} elseif ( $kg || in_array( $lstatus, array( 'queued', 'learning' ), true ) ) {
			$embedded = ! empty( $learn['embedded_at'] );
			if ( 'done' === $graph ) {
				$studied['state'] = 'done';
				$studied['sub']   = 'graph';
			} elseif ( 'pending' === $graph ) {
				$studied['state'] = 'doing';
				$studied['sub']   = 'graph';
			} elseif ( $embedded ) {
				$studied['state'] = 'done';
				$studied['sub']   = 'embed';
			} else {
				$studied['state'] = 'doing';
				$studied['sub']   = 'chunks';
			}
			$studied['at']        = (string) ( $learn['embedded_at'] ?? '' );
			$studied['passages']  = (int) ( $learn['passages'] ?? 0 );
			$studied['notebooks'] = array_values( array_map( 'intval', (array) ( $e['kg']['notebook_ids'] ?? array() ) ) );
		}
		$steps[] = $studied;

		$out = array(
			'contract' => self::CONTRACT,
			'ref'      => $ref,
			'entry_id' => (int) ( $e['entry_id'] ?? 0 ),
			'level'    => $level,
			'overall'  => self::overall( $steps ),
			'steps'    => $steps,
			'next'     => self::next_of( $read, $vaulted, $studied, $has_file ),
		);
		return $out;
	}

	/** Pure: counts a list of pipelines by step state + overall; what the five tiles of "Bảng tệp" show. */
	public static function summary( array $pipelines ): array {
		$by = array();
		foreach ( self::STEPS as $s ) {
			$by[ $s ] = array();
		}
		$overall = array( 'done' => 0, 'doing' => 0, 'failed' => 0, 'stale' => 0 );
		$levels  = array();
		foreach ( $pipelines as $p ) {
			foreach ( (array) ( $p['steps'] ?? array() ) as $st ) {
				$by[ $st['id'] ][ $st['state'] ] = ( $by[ $st['id'] ][ $st['state'] ] ?? 0 ) + 1;
			}
			$o = (string) ( $p['overall'] ?? 'done' );
			$overall[ $o ] = ( $overall[ $o ] ?? 0 ) + 1;
			$lv = (string) ( $p['level'] ?? '' );
			if ( '' !== $lv ) {
				$levels[ $lv ] = ( $levels[ $lv ] ?? 0 ) + 1;
			}
		}
		ksort( $levels );
		return array( 'total' => count( $pipelines ), 'by_step' => $by, 'overall' => $overall, 'levels' => $levels );
	}

	// ── used_in: who used this file (D137-3/4, S137-B8) ──────────────────

	const USES_MAX = 20;

	/**
	 * Pure: one raw journal row (id, kind, title, source_ref, metadata JSON, created_at) ⇒ one "use" of the source `$entry_id` / `$ref`, or null
	 * when the text match was a false hit. Kinds: `evidence` (sent / posted, with status) · `job` (an input of a job, JB-…) · `derived` (a new file
	 * made from it). The Journal stays the source of truth; this is a reading of it.
	 */
	public static function use_of_row( array $raw, int $entry_id, string $ref ): ?array {
		$m    = is_array( $raw['metadata'] ?? null ) ? $raw['metadata'] : json_decode( (string) ( $raw['metadata'] ?? '' ), true );
		$m    = is_array( $m ) ? $m : array();
		$pin  = '/^' . preg_quote( $ref, '/' ) . '@[vVsS]\d+$/';
		$kind = (string) ( $raw['kind'] ?? '' );
		$base = array( 'entry_id' => (int) ( $raw['id'] ?? 0 ), 'ref' => (string) ( $m['original']['doc_code'] ?? '' ), 'title' => mb_substr( (string) ( $raw['title'] ?? '' ), 0, 120 ), 'at' => (string) ( $raw['created_at'] ?? '' ) );
		$ev   = is_array( $m['evidence'] ?? null ) ? $m['evidence'] : array();
		if ( $ev ) {
			$refs = array_merge( (array) ( $ev['source_refs'] ?? array() ), array( (string) ( $ev['document_version_ref'] ?? '' ), (string) ( $ev['content_version_ref'] ?? '' ) ) );
			foreach ( $refs as $r ) {
				if ( 1 === preg_match( $pin, (string) $r ) ) {
					return $base + array( 'use' => 'evidence', 'what' => (string) ( $ev['provider'] ?? $ev['channel'] ?? 'gửi / đăng' ), 'status' => (string) ( $ev['status'] ?? 'unknown' ), 'version' => (string) $r );
				}
			}
		}
		$job = is_array( $m['job'] ?? null ) ? $m['job'] : array();
		if ( 1 === preg_match( $pin, (string) ( $job['version_ref'] ?? '' ) ) ) {
			return $base + array( 'use' => 'job', 'job_ref' => (string) ( $job['job_ref'] ?? '' ), 'role' => (string) ( $job['role'] ?? 'input' ), 'version' => (string) $job['version_ref'] );
		}
		if ( in_array( $entry_id, array_map( 'intval', (array) ( $m['derived_from'] ?? array() ) ), true ) ) {
			return $base + array( 'use' => 'derived', 'job_ref' => (string) ( $job['job_ref'] ?? '' ), 'kind' => $kind );
		}
		return null;
	}

	/** The uses of one row (≤ USES_MAX, newest first) — a view over the person's Journal; [] when the database class is absent. */
	public static function uses_of( array $entry, int $owner ): array {
		$id  = (int) ( $entry['entry_id'] ?? 0 );
		$ref = (string) ( $entry['ref_code'] ?? '' );
		if ( $id <= 0 || '' === $ref || ! class_exists( 'BizCity_Journal_Database' ) || ! method_exists( 'BizCity_Journal_Database', 'find_uses' ) ) {
			return array();
		}
		$out = array();
		foreach ( BizCity_Journal_Database::instance()->find_uses( $owner, $id, $ref ) as $raw ) {
			$u = self::use_of_row( (array) $raw, $id, $ref );
			if ( $u ) {
				$out[] = $u;
			}
			if ( count( $out ) >= self::USES_MAX ) {
				break;
			}
		}
		return $out;
	}

	/** Pure: one word for the row. failed > doing > stale > done. */
	public static function overall( array $steps ): string {
		$states = array_column( $steps, 'state' );
		foreach ( array( 'failed', 'doing', 'stale' ) as $s ) {
			if ( in_array( $s, $states, true ) ) {
				return $s;
			}
		}
		return 'done';
	}

	private static function next_of( array $read, array $vaulted, array $studied, bool $has_file ): array {
		if ( 'failed' === $read['state'] ) {
			return array( 'action' => 'reread', 'label' => 'Đọc lại' );
		}
		if ( 'none' === $read['state'] && $has_file ) {
			return array( 'action' => 'rebuild', 'label' => 'Build lại bản đọc' );
		}
		if ( 'stale' === $vaulted['state'] ) {
			return array( 'action' => 'restudy', 'label' => 'Học lại bản mới' );
		}
		if ( 'done' === $vaulted['state'] && '' === (string) $vaulted['folder'] ) {
			return array( 'action' => 'assign_folder', 'label' => 'Xếp thư mục' );
		}
		if ( 'optional' === $studied['state'] && 'done' === $vaulted['state'] ) {
			return array( 'action' => 'study', 'label' => 'Nghiên cứu sâu' );
		}
		return array( 'action' => 'none', 'label' => '' );
	}

	private static function read_why( array $meta ): string {
		$bits = array();
		if ( '' !== (string) ( $meta['about'] ?? '' ) ) {
			$bits[] = 'tóm tắt';
		}
		$n = mb_strlen( (string) ( $meta['ocr']['text'] ?? '' ) );
		if ( $n > 0 ) {
			$bits[] = $n . ' ký tự';
		}
		$k = count( (array) ( $meta['keywords'] ?? array() ) );
		if ( $k > 0 ) {
			$bits[] = $k . ' từ khoá';
		}
		return implode( ' · ', $bits );
	}

	/** R-ERROR-UX: always the four fields, never a bare string; nothing from the message that looks like a path. */
	private static function error_of( array $err ): array {
		return array(
			'code'      => substr( (string) preg_replace( '/[^a-z0-9_]/', '', strtolower( (string) ( $err['code'] ?? 'read_failed' ) ) ), 0, 40 ) ?: 'read_failed',
			'message'   => mb_substr( (string) ( $err['message'] ?? 'Không đọc được tệp này.' ), 0, 120 ),
			'hint'      => mb_substr( (string) ( $err['hint'] ?? 'Bấm Đọc lại; vẫn lỗi thì gửi lại tệp nhỏ hơn.' ), 0, 160 ),
			'help_code' => (string) ( $err['help_code'] ?? 'entry_meta' ),
		);
	}

	// ── site: list + rebuild ─────────────────────────────────────────────

	/** Declared kinds of the document / output layers (journal-kinds@1); the rows a file journey is about. */
	public static function doc_kinds(): array {
		if ( ! class_exists( 'BizCity_Journal_Kinds' ) || ! method_exists( 'BizCity_Journal_Kinds', 'layer_of' ) ) {
			return array();
		}
		$out = array();
		foreach ( array_keys( (array) ( BizCity_Journal_Kinds::all()['kinds'] ?? array() ) ) as $k ) {
			if ( in_array( BizCity_Journal_Kinds::layer_of( (string) $k ), array( 'document', 'output' ), true ) ) {
				$out[] = (string) $k;
			}
		}
		return $out;
	}

	/** entry ids in the person's doc_index@3 pack; null = pack unavailable (the tier alone decides the level) — same rule as brain-map. */
	private static function pack_ids( int $owner ): ?array {
		if ( ! class_exists( 'BizCity_Journal_Doc_Index_Pack_Exporter' ) || ! BizCity_Journal_Doc_Index_Pack_Exporter::available() ) {
			return null;
		}
		$ids = array();
		foreach ( (array) ( BizCity_Journal_Doc_Index_Pack_Exporter::build( $owner, 'owner' )['items'] ?? array() ) as $it ) {
			if ( is_array( $it ) && 'doc' === (string) ( $it['row'] ?? '' ) && (int) ( $it['entry_id'] ?? 0 ) > 0 ) {
				$ids[] = (int) $it['entry_id'];
			}
		}
		return $ids;
	}

	/** Level of one row the way brain-map decides it (one ladder). */
	public static function level_of( array $e, ?array $pack_ids ): ?string {
		if ( ! class_exists( 'BizCity_Journal_Brain_Map' ) ) {
			return null;
		}
		$in_pack = null === $pack_ids ? null : in_array( (int) ( $e['entry_id'] ?? 0 ), $pack_ids, true );
		return BizCity_Journal_Brain_Map::doc_level( $e, $in_pack );
	}

	/**
	 * The person's file rows of a range with their pipelines + the summary (one page, ≤ SCAN derived).
	 *
	 * @param array $ctx  {owner_user_id, role}
	 * @param array $args {range: today|7d|30d|all, step?, state?, folder?, limit≤50, offset}
	 */
	public static function list_for( array $ctx, array $args ): array {
		$owner = (int) ( $ctx['owner_user_id'] ?? 0 );
		$kinds = self::doc_kinds();
		if ( $owner <= 0 || ! $kinds || ! class_exists( 'BizCity_Journal_Index' ) ) {
			return array( 'contract' => self::CONTRACT, 'items' => array(), 'total' => 0, 'summary' => self::summary( array() ) );
		}
		$range  = (string) ( $args['range'] ?? 'today' );
		$days   = self::RANGES[ $range ] ?? 1;
		$filter = array( 'owner_user_id' => $owner, 'kinds' => $kinds, 'limit' => 50 );
		if ( $days > 0 ) {
			$filter['from'] = gmdate( 'Y-m-d', time() - ( $days - 1 ) * 86400 ) . ' 00:00:00';
		}
		if ( ! empty( $args['folder'] ) ) {
			$filter['folder'] = (string) $args['folder'];
		}
		$rows  = array();
		$total = 0;
		for ( $off = 0; $off < self::SCAN; $off += 50 ) {
			$page  = BizCity_Journal_Index::find( array_merge( $filter, array( 'offset' => $off ) ) );
			$total = max( $total, (int) ( $page['total'] ?? 0 ) );
			$rows  = array_merge( $rows, (array) ( $page['items'] ?? array() ) );
			if ( count( (array) ( $page['items'] ?? array() ) ) < 50 ) {
				break;
			}
		}
		if ( class_exists( 'BizCity_Brain_Access' ) ) {
			$rows = BizCity_Brain_Access::filter( $rows, BizCity_Brain_Access::principal( $ctx ), 'meta.read' );
		}
		$pack  = self::pack_ids( $owner );
		$now   = gmdate( 'c' );
		$items = array();
		foreach ( $rows as $e ) {
			$p = self::of( $e, self::level_of( $e, $pack ), $now );
			$p['title']      = mb_substr( '' !== (string) ( $e['meta']['title'] ?? '' ) ? (string) $e['meta']['title'] : (string) ( $e['title'] ?? '' ), 0, 120 );
			$p['kind']       = (string) ( $e['kind'] ?? '' );
			$p['file_name']  = mb_substr( (string) ( $e['original']['file_name'] ?? '' ), 0, 120 );
			$p['origin']     = array( 'surface' => (string) ( $e['origin']['surface'] ?? '' ) );
			$p['created_at'] = (string) ( $e['created_at'] ?? '' );
			$items[]         = $p;
		}
		$summary = self::summary( $items );
		$step    = (string) ( $args['step'] ?? '' );
		$state   = (string) ( $args['state'] ?? '' );
		if ( in_array( $step, self::STEPS, true ) && '' !== $state ) {
			$items = array_values( array_filter( $items, static function ( $p ) use ( $step, $state ) {
				foreach ( $p['steps'] as $st ) {
					if ( $st['id'] === $step ) {
						return $st['state'] === $state;
					}
				}
				return false;
			} ) );
		} elseif ( in_array( $state, array( 'doing', 'failed', 'stale' ), true ) ) {
			$items = array_values( array_filter( $items, static function ( $p ) use ( $state ) { return $p['overall'] === $state; } ) );
		}
		$limit  = min( 50, max( 1, (int) ( $args['limit'] ?? 20 ) ) );
		$offset = max( 0, (int) ( $args['offset'] ?? 0 ) );
		return array(
			'contract'      => self::CONTRACT,
			'range'         => isset( self::RANGES[ $range ] ) ? $range : 'today',
			'items'         => array_slice( $items, $offset, $limit ),
			'total'         => count( $items ),
			'scanned'       => count( $rows ),
			'range_total'   => $total,
			'truncated'     => $total > count( $rows ),
			'summary'       => $summary,
			'unread_files'  => self::count_unread( $owner, $kinds ),
			'rebuild_max'   => self::REBUILD_MAX,
		);
	}

	/** Files of the person that were never read (no entry-meta block) — the "N tệp cũ chưa có bản đọc" tile; SQL count only. */
	public static function count_unread( int $owner, array $kinds ): int {
		$r = BizCity_Journal_Index::find( array( 'owner_user_id' => $owner, 'kinds' => $kinds, 'has_file' => 1, 'meta_status' => 'none', 'limit' => 1 ) );
		return (int) ( $r['total'] ?? 0 );
	}

	/**
	 * Re-queue ≤ REBUILD_MAX never-read (or, with `failed:true`, failed) files of the owner: each goes through request_reread (meta.status ⇒ pending,
	 * by.op = reread) and the cell's sweep reads it within its next tick. Returns how many were queued and whether more remain. Never walks the table.
	 */
	public static function rebuild( int $owner, int $limit, bool $failed_too ): array {
		$kinds = self::doc_kinds();
		$limit = min( self::REBUILD_MAX, max( 1, $limit ) );
		$out   = array( 'contract' => self::CONTRACT, 'queued' => 0, 'skipped' => 0, 'refs' => array() );
		if ( $owner <= 0 || ! $kinds || ! class_exists( 'BizCity_Journal_Index' ) ) {
			return $out;
		}
		$statuses = $failed_too ? array( 'none', 'failed' ) : array( 'none' );
		foreach ( $statuses as $ms ) {
			if ( $out['queued'] >= $limit ) {
				break;
			}
			$page = BizCity_Journal_Index::find( array( 'owner_user_id' => $owner, 'kinds' => $kinds, 'has_file' => 1, 'meta_status' => $ms, 'limit' => $limit ) );
			foreach ( (array) ( $page['items'] ?? array() ) as $e ) {
				if ( $out['queued'] >= $limit ) {
					break;
				}
				$r = BizCity_Journal_Index::request_reread( (int) $e['entry_id'], $owner );
				if ( is_wp_error( $r ) ) {
					$out['skipped']++;
					continue;
				}
				$out['queued']++;
				if ( count( $out['refs'] ) < 20 ) {
					$out['refs'][] = (string) ( $e['ref_code'] ?? '' );
				}
			}
		}
		$out['remaining'] = self::count_unread( $owner, $kinds );
		return $out;
	}

	// ── REST ─────────────────────────────────────────────────────────────

	public static function rest_list( WP_REST_Request $request ): WP_REST_Response {
		$args = array();
		foreach ( array( 'range', 'step', 'state', 'folder', 'limit', 'offset' ) as $k ) {
			$v = $request->get_param( $k );
			if ( null !== $v ) {
				$args[ $k ] = is_scalar( $v ) ? (string) $v : '';
			}
		}
		if ( ! empty( $args['folder'] ) && 1 !== preg_match( '#^[0-9]{2}(/[a-z0-9-]{1,40})?$#', (string) $args['folder'] ) ) {
			return new WP_REST_Response( array( 'code' => 'folder_invalid', 'message' => 'Thư mục không đúng dạng.', 'hint' => 'Dùng số thư mục, ví dụ 20 hoặc 20/cong-viec.', 'help_code' => 'twin_vault' ), 400 );
		}
		return new WP_REST_Response( self::list_for( self::ctx(), $args ), 200 );
	}

	public static function rest_rebuild( WP_REST_Request $request ): WP_REST_Response {
		$ctx = self::ctx();
		$res = self::rebuild( (int) $ctx['owner_user_id'], (int) ( $request->get_param( 'limit' ) ?? self::REBUILD_MAX ), ! empty( $request->get_param( 'failed' ) ) );
		return new WP_REST_Response( $res, 200 );
	}

	private static function ctx(): array {
		return array( 'owner_user_id' => get_current_user_id(), 'role' => current_user_can( 'manage_options' ) ? 'owner' : 'staff' );
	}
}
