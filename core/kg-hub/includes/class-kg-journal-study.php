<?php
/**
 * BizCity_KG_Journal_Study — "Học sâu / Nghiên cứu sâu" (PHASE-0.137 D137-7/8): ≤ 20 journal rows' readable Markdown (Vault) become sources of a
 * notebook (KG + TwinChat sources service), the rows get kg_source_id · notebook_id · indexed_rev, and the graph pass is queued.
 *
 * It is a NOTEBOOK capability: it needs the KG notebook service + TwinChat sources service. The journal keeps the doors (MCP notebook.study,
 * POST bizcity-knowledge/v2/notebooks/from-refs) and asks through filter `bizcity_journal_study`; this class answers it. Without the notebook the
 * door says "study_unavailable" (503) and notebook.study is not offered to the assistant.
 *
 * // [2026-11-13 Johnny Chu - Chu Hoàng Anh] PHASE-0.148 W2 S148-C4 / S148-C14 (D148-19) — moved here unchanged from
 * // core/journal/includes/class-journal-pipeline.php (study()), so the journal no longer calls Vault / KG / TwinChat classes. Goes to the
 * // bizcity-twin-notebook plugin with the rest of kg-hub (W3).
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\KG_Hub
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_KG_Journal_Study', false ) ) {
	return;
}

final class BizCity_KG_Journal_Study {

	/** Filter `bizcity_journal_study`: null ⇒ answer it; something else ⇒ another provider already did. */
	public static function filter( $res, array $ctx, array $args ) {
		return null !== $res ? $res : self::study( $ctx, $args );
	}

	public static function register(): void {
		if ( function_exists( 'add_filter' ) ) {
			add_filter( 'bizcity_journal_study', array( __CLASS__, 'filter' ), 10, 3 );
		}
	}

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
			return new WP_Error( 'study_refs_empty', 'Chưa chọn tệp nào để học sâu.', array( 'status' => 400, 'hint' => 'Chọn ít nhất một tệp trong Sổ cái hoặc thư mục.', 'help_code' => 'journal_pipeline' ) );
		}
		if ( count( $refs ) > BizCity_Journal_Pipeline::STUDY_MAX ) {
			return new WP_Error( 'study_refs_too_many', 'Mỗi lần học sâu tối đa ' . BizCity_Journal_Pipeline::STUDY_MAX . ' tệp.', array( 'status' => 400, 'hint' => 'Chọn ít hơn hoặc chia làm nhiều lượt.', 'help_code' => 'journal_pipeline' ) );
		}
		$kg    = ! array_key_exists( 'kg', $args ) || ! empty( $args['kg'] );
		$force = ! empty( $args['force'] );
		$rows  = array();
		$items = array();
		foreach ( $refs as $ref ) {
			$row = ctype_digit( $ref ) ? BizCity_Journal_Index::get( (int) $ref, $owner ) : BizCity_Journal_Index::find_by_ref( $ref, $owner );
			if ( ! $row ) {
				$items[] = array( 'ref' => $ref, 'entry_id' => 0, 'status' => 'not_found', 'error' => BizCity_Journal_Pipeline::error_of( array( 'code' => 'journal_not_found', 'message' => 'Không có mã này trong sổ của bạn.', 'hint' => 'Kiểm lại mã TL-… trên thẻ.', 'help_code' => 'journal_pipeline' ) ) );
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
				$items[] = array( 'ref' => $ref, 'entry_id' => $id, 'status' => 'read_first', 'error' => BizCity_Journal_Pipeline::error_of( array( 'code' => 'read_first', 'message' => 'Tệp chưa có bản đọc.', 'hint' => 'Bấm Đọc lại, chờ nấc Vào kho xanh rồi học sâu.', 'help_code' => 'journal_pipeline' ) ) );
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
				$items[] = array( 'ref' => $ref, 'entry_id' => $id, 'status' => 'read_first', 'error' => BizCity_Journal_Pipeline::error_of( array( 'code' => 'vault_empty', 'message' => 'Bản đọc rỗng.', 'hint' => 'Bấm Đọc lại để trợ lý đọc tệp lần nữa.', 'help_code' => 'journal_pipeline' ) ) );
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
				$items[] = array( 'ref' => $ref, 'entry_id' => $id, 'status' => 'failed', 'error' => BizCity_Journal_Pipeline::error_of( array( 'code' => $res->get_error_code(), 'message' => $res->get_error_message(), 'hint' => 'Thử lại; nếu nội dung rỗng hãy Đọc lại tệp.', 'help_code' => 'journal_pipeline' ) ) );
				continue;
			}
			$new_kg = (int) ( $res['kg_source_id'] ?? 0 );
			$db     = BizCity_Journal_Database::instance();
			$db->mark_learning_projection( $id, $owner, array( 'learning_status' => 'learned', 'learning_error' => '', 'notebook_id' => $nbid, 'kg_source_id' => $new_kg > 0 ? $new_kg : $kgid ) );
			BizCity_Journal_Index::file( $id, $owner, array(
				'learning' => array( 'level' => 'L1', 'text_ready' => true, 'passages' => (int) ( $res['chunk_count'] ?? 0 ), 'embedded_at' => gmdate( 'c' ), 'graph_state' => $kg ? 'none' : 'skipped' ), // skipped = studied on purpose, no graph (the Học sâu step reads it as done/embed)
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
		// S137-A13 — the same trace as a rebuild: scope (notebook · kg) + the files that were taken in, live state read from the row later
		$traced = array();
		foreach ( $items as $i ) {
			if ( 'learned' === $i['status'] ) {
				$traced[] = array( 'ref' => (string) $i['ref'], 'entry_id' => (int) $i['entry_id'], 'title' => '', 'from' => 'vault' );
			}
		}
		$trace_id = $traced ? BizCity_Journal_Pipeline::trace_push( $owner, 'study', array( 'notebook_id' => $nbid, 'kg' => $kg, 'asked' => count( $refs ) ), $traced ) : '';
		return array(
			'trace_id' => $trace_id,
			'contract' => BizCity_Journal_Pipeline::CONTRACT,
			'notebook' => $nb ? array( 'id' => $nbid, 'name' => (string) ( $nb['name'] ?? '' ) ) : null,
			'items'    => $items,
			'learned'  => $learned,
			'skipped'  => count( $items ) - $learned,
			'kg'       => $kg,
		);
	}
}
