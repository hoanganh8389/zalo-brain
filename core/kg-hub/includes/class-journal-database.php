<?php
/**
 * BizCity Journal Entry storage.
 *
 * Journal entries are the canonical authoring object. The skills registry and
 * KG source tables are separate runtime/learning projections.
 *
 * [2026-09-26 Claude Opus 5.5] CORE-REDUCTION WP-12 R3 — owner moved from core/skills (archived) to core/kg-hub.
 * Live writers: the Zalo Bot capture (plugins/bizcity-zalo-bot class-notebook-bridge-listener.php) and
 * BizCity_KG_Channel_Notebook_Bridge. Table `bizcity_journal_entries` and class name are unchanged.
 *
 * [2026-10-12 09:52 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.100 S100-L15 (R-JOURNAL R-JN-4) — this table becomes the
 * one index "client has what, where" table: adds `kind`, `subject_hash`, `doc_date`, `tags` query columns + 2
 * indexes. `BizCity_Journal_Index` (class-journal-index.php) is the read/write API other lanes call; this class
 * stays the low-level storage (schema + raw CRUD), unchanged in shape for existing callers.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\KG_Hub
 * @since 2026-08-02
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

if ( class_exists( 'BizCity_Journal_Database', false ) ) {
	return;
}

final class BizCity_Journal_Database {

	// [2026-10-17 07:44 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.121 S121-J3 (R-JN-14, Q121-13) — 1.2.0: `status` ENUM + review / verified / failed / void (journal-entry@1.1.0 `state`); dbDelta alters the column in place.
	// [2026-10-24 05:31 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.133 S133-Ma (R-ENTRY-META EM-7, D133-M1) — 1.3.0: + `find_text` (built by code from the row + entry-meta@1, as written and folded); the only new column of the phase.
	const SCHEMA_VERSION = '1.3.0';
	const SCHEMA_VERSION_KEY = 'bizcity_journal_db_version';
	const CACHE_GROUP = 'journal';
	// [2026-10-12 09:52 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.100 S100-L15 — backfill batch size, bounded (R-JOURNAL R-JN-4).
	const BACKFILL_BATCH = 500;
	/** journal-entry@1.1.0 `state` values (+ legacy draft/published/archived). `void` and `archived` are hidden from every listing. */
	const STATUSES = array( 'draft', 'captured', 'published', 'archived', 'review', 'verified', 'failed', 'void' );

	private static $instance = null;
	private $table;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		global $wpdb;
		$this->table = $wpdb->prefix . 'bizcity_journal_entries';
	}

	public function get_table(): string {
		return $this->table;
	}

	/**
	 * Install the per-blog Journal table when the central provisioner invokes it.
	 */
	public static function maybe_install(): void {
		self::instance()->ensure_schema();
	}

	private function ensure_schema(): void {
		$stored = get_option( self::SCHEMA_VERSION_KEY, '' );
		if ( self::SCHEMA_VERSION === $stored ) {
			return;
		}

		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		// [2026-10-12 09:52 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.100 S100-L15 (R-JOURNAL R-JN-4) — `kind`,
		// `subject_hash`, `doc_date`, `tags` are the index query columns (doc 92 §5): the thẻ body stays in
		// `metadata`, file/KG pointers stay in `notebook_id`/`kg_source_id`. dbDelta diffs this full CREATE TABLE
		// against the live one, so a pre-1.1.0 install gets these columns added in place (no data loss).
		$sql = "CREATE TABLE {$this->table} (
			id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			owner_user_id       BIGINT UNSIGNED NOT NULL DEFAULT 0,
			workspace_id        VARCHAR(64) NOT NULL DEFAULT 'notion',
			title               VARCHAR(255) NOT NULL DEFAULT '',
			body                LONGTEXT NOT NULL,
			status              ENUM('draft','captured','published','archived','review','verified','failed','void') NOT NULL DEFAULT 'draft',
			source_type         VARCHAR(32) NOT NULL DEFAULT 'menu',
			source_ref          VARCHAR(191) NOT NULL DEFAULT '',
			idempotency_key     VARCHAR(191) NOT NULL DEFAULT '',
			revision            BIGINT UNSIGNED NOT NULL DEFAULT 1,
			learning_status     ENUM('not_queued','queued','processing','learned','failed','retryable') NOT NULL DEFAULT 'not_queued',
			learning_error      VARCHAR(500) NOT NULL DEFAULT '',
			notebook_id         BIGINT UNSIGNED NOT NULL DEFAULT 0,
			kg_source_id        BIGINT UNSIGNED NOT NULL DEFAULT 0,
			kind                VARCHAR(48) NOT NULL DEFAULT '',
			subject_hash        CHAR(18) NOT NULL DEFAULT '',
			doc_date            DATE NULL DEFAULT NULL,
			tags                VARCHAR(400) NOT NULL DEFAULT '',
			metadata            LONGTEXT DEFAULT NULL,
			find_text           MEDIUMTEXT NULL DEFAULT NULL,
			created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY uniq_idempotency_key (idempotency_key),
			KEY idx_owner_status (owner_user_id, status),
			KEY idx_workspace_updated (workspace_id, updated_at),
			KEY idx_learning_status (learning_status),
			KEY idx_kg_source (kg_source_id),
			KEY idx_owner_kind_date (owner_user_id, kind, doc_date),
			KEY idx_subject_date (subject_hash, doc_date)
		) {$charset};";

		// [2026-08-02 Johnny Chu] PHASE-SKILLS-JOURNAL — provision Journal
		// storage through the WordPress schema path, then stamp the version.
		dbDelta( $sql );
		// [2026-10-12 09:52 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.100 S100-L15 — backfill old rows (kind='') to
		// `common.*` by `source_type` before stamping the new version, bounded + idempotent (safe to re-run: it
		// only ever touches rows where kind is still empty).
		if ( version_compare( (string) $stored, '1.1.0', '<' ) ) {
			$this->backfill_kind_from_source_type();
		}
		update_option( self::SCHEMA_VERSION_KEY, self::SCHEMA_VERSION, true );
	}

	/**
	 * [2026-10-12 09:52 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.100 S100-L15 — one-time backfill: rows written
	 * before `kind` existed get `common.*` from their `source_type` (doc 92 §9 S100-L15). Bounded batches
	 * (BACKFILL_BATCH rows / pass) so a large table never blocks the request that triggers `ensure_schema()`.
	 * Idempotent: only rows with `kind = ''` are touched, so re-running after a partial pass is safe.
	 */
	private function backfill_kind_from_source_type(): void {
		global $wpdb;
		$map = array(
			'image'      => 'common.photo',
			'photo'      => 'common.photo',
			'file'       => 'common.document',
			'document'   => 'common.document',
			'audio'      => 'common.voice_note',
			'voice'      => 'common.voice_note',
			'voice_note' => 'common.voice_note',
			'receipt'    => 'common.receipt',
			'note'       => 'common.note',
			'menu'       => 'common.note',
		);
		$guard = 0;
		do {
			$rows = $wpdb->get_results( "SELECT id, source_type FROM {$this->table} WHERE kind = '' ORDER BY id ASC LIMIT " . (int) self::BACKFILL_BATCH, ARRAY_A ) ?: array();
			if ( empty( $rows ) ) {
				break;
			}
			foreach ( $rows as $row ) {
				$kind = $map[ strtolower( (string) $row['source_type'] ) ] ?? 'common.document';
				$wpdb->update( $this->table, array( 'kind' => $kind ), array( 'id' => (int) $row['id'] ) );
			}
			$guard++;
		} while ( count( $rows ) >= self::BACKFILL_BATCH && $guard < 200 ); // hard ceiling: never an infinite loop on a stuck row.
		$this->flush_cache();
	}

	/**
	 * Create or return one entry for an idempotency key.
	 *
	 * @return array|WP_Error
	 */
	public function create( array $data ) {
		// [2026-08-02 Johnny Chu] PHASE-SKILLS-JOURNAL — Journal save is
		// independent from KG/LLM availability and safe on webhook replay.
		$owner = (int) ( $data['owner_user_id'] ?? 0 );
		$title = sanitize_text_field( (string) ( $data['title'] ?? '' ) );
		$body  = wp_kses_post( (string) ( $data['body'] ?? '' ) );
		$key   = sanitize_text_field( (string) ( $data['idempotency_key'] ?? '' ) );
		if ( $key === '' ) {
			// [2026-08-02 Johnny Chu] PHASE-SKILLS-JOURNAL — menu-created
			// entries need a unique key even when no replay key is supplied.
			$key = 'generated:' . wp_generate_uuid4();
		}
		if ( $owner <= 0 || $title === '' || trim( wp_strip_all_tags( $body ) ) === '' ) {
			return new WP_Error( 'journal_invalid_entry', 'Nhật ký cần có người sở hữu, tiêu đề và nội dung.' );
		}

		if ( $key !== '' ) {
			$existing = $this->get_by_idempotency_key( $key );
			if ( $existing ) {
				return $existing;
			}
		}

		global $wpdb;
		$inserted = $wpdb->insert( $this->table, $this->normalize_write_data( $data, $owner, $title, $body, $key ) );
		if ( false === $inserted ) {
			if ( $key !== '' ) {
				$existing = $this->get_by_idempotency_key( $key );
				if ( $existing ) {
					return $existing;
				}
			}
			return new WP_Error( 'journal_save_failed', 'Không thể lưu nhật ký.' );
		}

		$id = (int) $wpdb->insert_id;
		$this->flush_cache();
		return $this->get( $id, $owner );
	}

	/**
	 * List entries visible to an owner. Administrators may request all entries.
	 */
	public function list_entries( int $owner_user_id, string $workspace_id = '', int $limit = 50, int $offset = 0, bool $all = false ): array {
		// [2026-08-02 Johnny Chu] PHASE-SKILLS-JOURNAL — cache reads by blog,
		// owner, workspace, pagination, and visibility scope.
		$limit  = min( 100, max( 1, $limit ) );
		$offset = max( 0, $offset );
		$cache_key = 'entries_' . (int) get_current_blog_id() . '_' . ( $all ? 'all' : (int) $owner_user_id ) . '_' . md5( $workspace_id . ':' . $limit . ':' . $offset );
		$cached = $this->cache_get( $cache_key );
		if ( false !== $cached ) {
			return is_array( $cached ) ? $cached : array();
		}

		global $wpdb;
		$where = array( "status NOT IN ('archived','void')" );
		$params = array();
		if ( ! $all ) {
			$where[] = 'owner_user_id = %d';
			$params[] = $owner_user_id;
		}
		if ( $workspace_id !== '' ) {
			$where[] = 'workspace_id = %s';
			$params[] = sanitize_key( $workspace_id );
		}
		$params[] = $limit;
		$params[] = $offset;
		$sql = "SELECT * FROM {$this->table} WHERE " . implode( ' AND ', $where ) . ' ORDER BY updated_at DESC, id DESC LIMIT %d OFFSET %d';
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$params ), ARRAY_A ) ?: array();
		$this->cache_set( $cache_key, $rows );
		return $rows;
	}

	/**
	 * [2026-10-12 09:52 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.100 S100-L15 (R-JOURNAL R-JN-4/R-JN-5) — the SQL
	 * filter `BizCity_Journal_Index::find()` builds on: structured columns first, `q` (title LIKE) only narrows
	 * what the structured filter already matched. Never archived.
	 *
	 * @param array $filter {owner_user_id, all?, kind?, kinds?[], kind_prefix?, tags?[], subject_hash?, from?, to?, q?, limit, offset}
	 * @return array{items:array,total:int}
	 */
	public function find_indexed( array $filter ): array {
		global $wpdb;
		$owner = (int) ( $filter['owner_user_id'] ?? 0 );
		$all   = ! empty( $filter['all'] );
		$where  = array( "status NOT IN ('archived','void')" );
		$params = array();
		if ( ! $all ) {
			$where[]  = 'owner_user_id = %d';
			$params[] = $owner;
		}
		if ( ! empty( $filter['kind'] ) ) {
			$where[]  = 'kind = %s';
			$params[] = self::sanitize_kind( (string) $filter['kind'] );
		} elseif ( ! empty( $filter['kinds'] ) && is_array( $filter['kinds'] ) ) {
			$kinds = array_map( array( __CLASS__, 'sanitize_kind' ), $filter['kinds'] );
			$kinds = array_values( array_filter( $kinds ) );
			if ( ! empty( $kinds ) ) {
				$in       = implode( ',', array_fill( 0, count( $kinds ), '%s' ) );
				$where[]  = "kind IN ({$in})";
				$params   = array_merge( $params, $kinds );
			}
		} elseif ( ! empty( $filter['kind_prefix'] ) ) {
			$where[]  = 'kind LIKE %s';
			$params[] = $wpdb->esc_like( self::sanitize_kind( (string) $filter['kind_prefix'] ) ) . '%';
		}
		if ( ! empty( $filter['subject_hash'] ) ) {
			$where[]  = 'subject_hash = %s';
			$params[] = self::sanitize_subject_hash( (string) $filter['subject_hash'] );
		}
		if ( ! empty( $filter['from'] ) ) {
			$where[]  = 'doc_date >= %s';
			$params[] = (string) $filter['from'];
		}
		if ( ! empty( $filter['to'] ) ) {
			$where[]  = 'doc_date <= %s';
			$params[] = (string) $filter['to'];
		}
		if ( ! empty( $filter['tags'] ) && is_array( $filter['tags'] ) ) {
			foreach ( array_slice( $filter['tags'], 0, 5 ) as $t ) {
				$t = strtolower( (string) preg_replace( '/[^a-z0-9\-]/', '', trim( (string) $t ) ) );
				if ( '' === $t ) {
					continue;
				}
				$where[]  = 'tags LIKE %s';
				$params[] = '%' . $wpdb->esc_like( ',' . $t . ',' ) . '%';
			}
		}
		// [2026-10-17 10:10 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.121 B4-1 (S121-R6, doc 70 §6) — web "Kho" filters: one live state, and rows that keep an original file.
		if ( ! empty( $filter['status'] ) && in_array( (string) $filter['status'], array( 'captured', 'review', 'verified', 'failed' ), true ) ) {
			$where[]  = 'status = %s';
			$params[] = (string) $filter['status'];
		}
		if ( ! empty( $filter['has_file'] ) ) {
			$where[]  = 'metadata LIKE %s AND metadata NOT LIKE %s';
			$params[] = '%' . $wpdb->esc_like( '"attachment_id":' ) . '%';
			$params[] = '%' . $wpdb->esc_like( '"attachment_id":0' ) . '%';
		}
		// [2026-10-25 12:30 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.137 S137-B3 — has_markdown 1|0: a vault:// representation (brain-resource-link@1) exists / does not ("chưa có bản đọc")
		if ( isset( $filter['has_markdown'] ) && '' !== (string) $filter['has_markdown'] ) {
			$where[]  = ! empty( $filter['has_markdown'] ) && 'false' !== (string) $filter['has_markdown'] ? 'metadata LIKE %s' : '(metadata IS NULL OR metadata NOT LIKE %s)';
			$params[] = '%' . $wpdb->esc_like( '"ref":"vault://' ) . '%';
		}
		// `q` narrows the already-structured-filtered set (R-JN-5: semantic search is a separate pass — knowledge.search).
		// [2026-10-21 11:58 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.126 ACI (doc 96 F-3) — word by word, title OR summary: "hợp đồng thuê Cty A" finds
		// "Cty A - HĐ thuê kho" (was one exact substring of the title, so any other word order found nothing).
		// [2026-10-24 05:31 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.133 S133-Ma (journal.find v2, doc 81 §4) — each word also matches `find_text` (summary + OCR + keywords + people, as written AND folded),
		// so "nha truong" finds "nhà trường" written on a photo. Old rows without find_text still match on title / body as before.
		foreach ( self::query_words( (string) ( $filter['q'] ?? '' ) ) as $w ) {
			$like     = '%' . $wpdb->esc_like( $w ) . '%';
			$fold     = class_exists( 'BizCity_Journal_Entry_Meta' ) ? BizCity_Journal_Entry_Meta::fold( $w ) : $w;
			$where[]  = '(title LIKE %s OR body LIKE %s OR find_text LIKE %s OR find_text LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
			$params[] = '%' . $wpdb->esc_like( $fold ) . '%';
		}
		// `terms` (folded phrases from the cell) — any ⇒ one of them (scored in PHP), all ⇒ each of them; matched on find_text / title / body
		$terms = array_values( array_filter( array_map( 'strval', (array) ( $filter['terms_sql'] ?? array() ) ), 'strlen' ) );
		if ( $terms ) {
			$ors = array();
			foreach ( array_slice( $terms, 0, 12 ) as $t ) {
				$like  = '%' . $wpdb->esc_like( $t ) . '%';
				$ors[] = '(find_text LIKE %s OR title LIKE %s OR body LIKE %s)';
				$params[] = $like;
				$params[] = $like;
				$params[] = $like;
			}
			$where[] = '(' . implode( 'any' === (string) ( $filter['match'] ?? 'all' ) ? ' OR ' : ' AND ', $ors ) . ')';
		}
		// PHASE-0.133 S133-V1 — the rows of ONE logical source (every version keeps its source_uuid)
		if ( ! empty( $filter['source_uuid'] ) && 1 === preg_match( '/^[0-9a-f-]{36}$/', (string) $filter['source_uuid'] ) ) {
			$where[]  = 'metadata LIKE %s';
			$params[] = '%' . $wpdb->esc_like( '"source_uuid":"' . (string) $filter['source_uuid'] . '"' ) . '%';
		}
		// [2026-10-24 09:48 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.133 S133-Mb — rows by the state of their readable part (the cell's re-read sweep): none = never read
		// (no entry-meta block), pending / failed / done = narrowed by text here, the exact meta.status is re-checked by BizCity_Journal_Index::find
		$ms = (string) ( $filter['meta_status'] ?? '' );
		if ( 'none' === $ms ) {
			$where[]  = '(metadata IS NULL OR metadata NOT LIKE %s)';
			$params[] = '%' . $wpdb->esc_like( '"meta":{' ) . '%';
		} elseif ( in_array( $ms, array( 'pending', 'failed', 'done' ), true ) ) {
			$where[]  = 'metadata LIKE %s AND metadata LIKE %s';
			$params[] = '%' . $wpdb->esc_like( '"meta":{' ) . '%';
			$params[] = '%' . $wpdb->esc_like( '"status":"' . $ms . '"' ) . '%';
		}
		// [2026-10-25 PHASE-0.134 D134-13] rows filed in a Kho tri thức folder ("30" matches "30" and "30/…"): metadata.meta.folder, text-narrowed like meta_status
		if ( ! empty( $filter['folder'] ) && 1 === preg_match( '#^[0-9]{2}(/[a-z0-9-]{1,40})?$#', (string) $filter['folder'] ) ) {
			$where[]  = 'metadata LIKE %s';
			$params[] = '%' . $wpdb->esc_like( '"folder":"' . (string) $filter['folder'] ) . '%';
		}
		// [2026-10-25] PHASE-0.136 L6 — several folders (a role's): any of them
		$fl = array_values( array_filter( array_slice( (array) ( $filter['folders'] ?? array() ), 0, 4 ), static function ( $f ) { return 1 === preg_match( '#^[0-9]{2}(/[a-z0-9-]{1,40})?$#', (string) $f ); } ) );
		if ( $fl ) {
			$where[] = '(' . implode( ' OR ', array_fill( 0, count( $fl ), 'metadata LIKE %s' ) ) . ')';
			foreach ( $fl as $f ) {
				$params[] = '%' . $wpdb->esc_like( '"folder":"' . (string) $f ) . '%';
			}
		}
		$where_sql = implode( ' AND ', $where );
		$total = (int) $wpdb->get_var( $params ? $wpdb->prepare( "SELECT COUNT(*) FROM {$this->table} WHERE {$where_sql}", $params ) : "SELECT COUNT(*) FROM {$this->table} WHERE {$where_sql}" );
		$limit  = min( ! empty( $filter['cap'] ) ? 200 : 50, max( 1, (int) ( $filter['limit'] ?? 20 ) ) ); // PHASE-0.133: scored searches read up to 200 then page in PHP
		$offset = max( 0, (int) ( $filter['offset'] ?? 0 ) );
		$list_params = array_merge( $params, array( $limit, $offset ) );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->table} WHERE {$where_sql} ORDER BY doc_date DESC, id DESC LIMIT %d OFFSET %d", $list_params ), ARRAY_A ) ?: array();
		return array( 'items' => $rows, 'total' => $total );
	}

	/**
	 * [2026-10-21 11:58 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.126 ACI (doc 96 F-3) — the words of a free-text `q`: split on spaces / punctuation,
	 * at most 6 (each one must match). Filler words a person types around the real words are dropped. Pure.
	 *
	 * @return string[]
	 */
	public static function query_words( string $q ): array {
		$q = trim( $q );
		if ( '' === $q ) {
			return array();
		}
		$filler = array( 'cho', 'của', 'cua', 'và', 'va', 'các', 'cac', 'những', 'nhung', 'cái', 'cai', 'về', 've', 'tìm', 'tim', 'xem', 'file', 'tài', 'liệu', 'the', 'and', 'of' );
		$out    = array();
		foreach ( preg_split( '/[\s,.;:!?"\'()\[\]{}\/\\\\|]+/u', $q ) ?: array() as $w ) {
			$w = trim( (string) $w );
			if ( '' === $w || in_array( mb_strtolower( $w ), $filler, true ) || in_array( $w, $out, true ) ) { // one letter stays: "Cty A", "kho B"
				continue;
			}
			$out[] = mb_substr( $w, 0, 40 );
			if ( count( $out ) >= 6 ) {
				break;
			}
		}
		// a query made only of filler words still searches for itself (never an unfiltered page)
		return $out ?: array( mb_substr( $q, 0, 40 ) );
	}

	/** Shelf rows for `doc_index` (doc 92 §5): one row per `kind` the owner has, with count/date range/top tags/review count. */
	public function shelf_rows( int $owner_user_id, int $max = 40 ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT kind, COUNT(*) AS c, MIN(doc_date) AS d_from, MAX(doc_date) AS d_to, MAX(updated_at) AS u
			 FROM {$this->table} WHERE owner_user_id = %d AND status NOT IN ('archived','void') AND kind != '' GROUP BY kind ORDER BY c DESC LIMIT %d",
			$owner_user_id, max( 1, $max )
		), ARRAY_A ) ?: array();
		return $rows;
	}

	/** Subject rows for `doc_index` (doc 92 §5): per subject, kind counts + last activity. */
	public function subject_rows( int $owner_user_id, int $max = 30 ): array {
		global $wpdb;
		$subjects = $wpdb->get_col( $wpdb->prepare(
			"SELECT DISTINCT subject_hash FROM {$this->table} WHERE owner_user_id = %d AND status NOT IN ('archived','void') AND subject_hash != '' ORDER BY updated_at DESC LIMIT %d",
			$owner_user_id, max( 1, $max )
		) ) ?: array();
		$out = array();
		foreach ( $subjects as $sh ) {
			$kind_rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT kind, COUNT(*) AS c, MAX(doc_date) AS last FROM {$this->table} WHERE owner_user_id = %d AND subject_hash = %s AND status NOT IN ('archived','void') GROUP BY kind",
				$owner_user_id, $sh
			), ARRAY_A ) ?: array();
			$kinds = array();
			$last  = '';
			foreach ( $kind_rows as $kr ) {
				$kinds[ (string) $kr['kind'] ] = (int) $kr['c'];
				$last = max( $last, (string) $kr['last'] );
			}
			$out[] = array( 'subject_hash' => $sh, 'kinds' => $kinds, 'last' => $last );
		}
		return $out;
	}

	/** Recent rows for `doc_index` (doc 92 §5). */
	/** [2026-10-17 08:40 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.121 B1-2 (S121-A2, I-1) — full rows for the cell registry (newest first), hidden states excluded. */
	public function register_rows( int $owner_user_id, int $max = 120 ): array {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$this->table} WHERE owner_user_id = %d AND status NOT IN ('archived','void') ORDER BY updated_at DESC, id DESC LIMIT %d",
			$owner_user_id, max( 1, min( 500, $max ) )
		), ARRAY_A ) ?: array();
	}

	/**
	 * [2026-10-22 09:22 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.128 TwinZalo ㉑ (U19, chủ 20:2x "3 GB / 50 GB") — bytes the person's second brain keeps: every live row
	 * with an original file, ALL rows (not the 500 newest), one count per file (attachment_id, else sha256). Reads only `metadata`; cached 60 s in BizCity_Cache group CACHE_GROUP like the brain map (every journal write flushes it). @return array{bytes:int, files:int, capped:bool}
	 */
	public function storage_bytes( int $owner_user_id, int $cap = 20000 ): array {
		global $wpdb;
		$key = 'storage_' . $owner_user_id;
		$hit = class_exists( 'BizCity_Cache' ) ? BizCity_Cache::get( self::CACHE_GROUP, $key ) : false; // same cache as the brain map: every journal write flushes this group
		if ( is_array( $hit ) ) {
			return $hit;
		}
		$rows = $wpdb->get_col( $wpdb->prepare(
			"SELECT metadata FROM {$this->table} WHERE owner_user_id = %d AND status NOT IN ('archived','void') AND metadata LIKE %s LIMIT %d",
			$owner_user_id, '%' . $wpdb->esc_like( '"size"' ) . '%', max( 1, $cap )
		) ) ?: array();
		$out = self::storage_from_metadata( $rows );
		$out['capped'] = count( $rows ) >= $cap;
		if ( class_exists( 'BizCity_Cache' ) ) {
			BizCity_Cache::set( self::CACHE_GROUP, $key, $out, 60 );
		}
		return $out;
	}

	/**
	 * [2026-10-25 01:32 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.137 S137-B8 (D137-3/4) — the rows of THIS owner that USED a source: its code pinned
	 * as a version (`TL-7257@v…` in job.version_ref / evidence source_refs / document_version_ref) or its entry id in `derived_from`. Newest first,
	 * ≤ $limit; SQL narrows by text, the caller re-checks exactly (BizCity_Journal_Pipeline::uses_of). Cached 60 s in CACHE_GROUP (every journal
	 * write flushes it); key carries blog + owner + id (R-MSDB). Never the row itself. @return array<int,array> raw rows (id, kind, title, source_ref, metadata, created_at)
	 */
	public function find_uses( int $owner_user_id, int $entry_id, string $ref_code, int $limit = 40 ): array {
		global $wpdb;
		if ( $owner_user_id <= 0 || $entry_id <= 0 || 1 !== preg_match( '/^[A-Z]{2}-\d{1,12}$/', $ref_code ) ) {
			return array();
		}
		$blog = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 1;
		$key  = 'uses_' . $blog . '_' . $owner_user_id . '_' . $entry_id;
		$hit  = class_exists( 'BizCity_Cache' ) ? BizCity_Cache::get( self::CACHE_GROUP, $key ) : false;
		if ( is_array( $hit ) ) {
			return $hit;
		}
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, kind, title, source_ref, metadata, created_at FROM {$this->table}
			  WHERE owner_user_id = %d AND id <> %d AND status NOT IN ('archived','void')
			    AND ( metadata LIKE %s OR ( metadata LIKE %s AND metadata LIKE %s ) )
			  ORDER BY id DESC LIMIT %d",
			$owner_user_id, $entry_id,
			'%' . $wpdb->esc_like( '"' . $ref_code . '@' ) . '%',
			'%' . $wpdb->esc_like( '"derived_from":[' ) . '%',
			'%' . $wpdb->esc_like( (string) $entry_id ) . '%',
			max( 1, min( 100, $limit ) )
		), ARRAY_A ) ?: array();
		if ( class_exists( 'BizCity_Cache' ) ) {
			BizCity_Cache::set( self::CACHE_GROUP, $key, $rows, 60 );
		}
		return $rows;
	}

	/** Pure: JSON `metadata` strings ⇒ {bytes, files}; a file is counted once (attachment_id, else sha256, else the row). */
	public static function storage_from_metadata( array $rows ): array {
		$seen  = array();
		$bytes = 0;
		foreach ( $rows as $i => $raw ) {
			$m = is_array( $raw ) ? $raw : json_decode( (string) $raw, true );
			$o = is_array( $m['original'] ?? null ) ? $m['original'] : array();
			$size = (int) ( $o['size'] ?? $o['bytes'] ?? 0 );
			if ( $size <= 0 ) {
				continue;
			}
			$id = (int) ( $o['attachment_id'] ?? 0 ) > 0 ? 'a' . (int) $o['attachment_id'] : ( '' !== (string) ( $o['sha256'] ?? '' ) ? 's' . $o['sha256'] : 'r' . $i );
			if ( isset( $seen[ $id ] ) ) {
				continue;
			}
			$seen[ $id ] = true;
			$bytes      += $size;
		}
		return array( 'bytes' => $bytes, 'files' => count( $seen ) );
	}

	/**
	 * [2026-10-17 11:10 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.121 Q121-8 web — rows OTHER people (staff) filed from documents they sent into one of `$bridge_ids`
	 * (Q121-17: their book; Q121-8: the number's owner may read them). Joined on the private original's workspace
	 * `u<sender>|zalo_account:<bridge>`; only `TL-<attachment>` rows qualify.
	 */
	public function number_rows( array $bridge_ids, int $exclude_owner, int $max = 200, int $entry_id = 0 ): array {
		global $wpdb;
		$bridge_ids = array_values( array_filter( array_map( 'strval', $bridge_ids ), static function ( $b ) { return '' !== $b && 1 === preg_match( '/^[A-Za-z0-9_.:-]{1,64}$/', $b ); } ) );
		if ( array() === $bridge_ids ) {
			return array();
		}
		$likes  = array();
		$params = array( $exclude_owner );
		foreach ( $bridge_ids as $b ) {
			$likes[]  = 'pm.meta_value LIKE %s';
			$params[] = '%' . $wpdb->esc_like( '|zalo_account:' . $b );
		}
		$one = '';
		if ( $entry_id > 0 ) {
			$one      = ' AND j.id = %d';
			$params[] = $entry_id;
		}
		$params[] = max( 1, min( 500, $max ) );
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT j.* FROM {$this->table} j
			 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = CAST(SUBSTRING(j.source_ref, 4) AS UNSIGNED) AND pm.meta_key = '_bizcity_sb_workspace'
			 WHERE j.source_ref LIKE 'TL-%%' AND j.owner_user_id <> %d AND j.status NOT IN ('archived','void') AND (" . implode( ' OR ', $likes ) . "){$one}
			 ORDER BY j.updated_at DESC, j.id DESC LIMIT %d",
			$params
		), ARRAY_A ) ?: array();
	}

	/**
	 * [2026-10-19 09:12 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.123 W1 (B4) — ids of one Twin Sheet slice: one owner, one kind, an optional
	 * doc_date range. Reads idx_owner_kind_date only (integers, newest id first), capped at $max so a sheet scan is bounded; the
	 * archived/void filter happens on the full rows (status is not in the index).
	 *
	 * @return int[]
	 */
	public function slice_ids( int $owner_user_id, string $kind, string $from, string $to, int $max ): array {
		global $wpdb;
		$kind = self::sanitize_kind( $kind );
		if ( $owner_user_id <= 0 || '' === $kind ) {
			return array();
		}
		$where  = array( 'owner_user_id = %d', 'kind = %s' );
		$params = array( $owner_user_id, $kind );
		if ( null !== self::sanitize_doc_date( $from ) ) {
			$where[]  = 'doc_date >= %s';
			$params[] = $from;
		}
		if ( null !== self::sanitize_doc_date( $to ) ) {
			$where[]  = 'doc_date <= %s';
			$params[] = $to;
		}
		$params[] = max( 1, min( 100000, $max ) );
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$this->table} WHERE " . implode( ' AND ', $where ) . ' ORDER BY id DESC LIMIT %d', $params ) ) ?: array();
		return array_map( 'intval', $ids );
	}

	/**
	 * [2026-10-19 09:12 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.123 W1 (B4) — full rows for ids already scoped by slice_ids() (≤ 500 per call,
	 * the caller batches). Owner re-checked in SQL: a stray id of another person returns nothing.
	 */
	public function rows_by_ids( array $ids, int $owner_user_id ): array {
		global $wpdb;
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ), static function ( $i ) { return $i > 0; } ) ) );
		$ids = array_slice( $ids, 0, 500 );
		if ( array() === $ids || $owner_user_id <= 0 ) {
			return array();
		}
		$in = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->table} WHERE owner_user_id = %d AND status <> 'archived' AND id IN ({$in})", array_merge( array( $owner_user_id ), $ids ) ), ARRAY_A ) ?: array();
	}

	public function recent_rows( int $owner_user_id, int $max = 20 ): array {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT id, kind, title, doc_date, tags, updated_at FROM {$this->table} WHERE owner_user_id = %d AND status NOT IN ('archived','void') ORDER BY updated_at DESC LIMIT %d",
			$owner_user_id, max( 1, $max )
		), ARRAY_A ) ?: array();
	}

	public function get( int $id, int $owner_user_id = 0, bool $all = false ) {
		if ( $id <= 0 ) {
			return null;
		}
		$cache_key = 'entry_' . (int) get_current_blog_id() . '_' . $id;
		$cached = $this->cache_get( $cache_key );
		$row = false !== $cached ? $cached : null;
		if ( false === $cached ) {
			global $wpdb;
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table} WHERE id = %d LIMIT 1", $id ), ARRAY_A );
			$this->cache_set( $cache_key, $row ?: array() );
		}
		if ( ! is_array( $row ) || empty( $row ) ) {
			return null;
		}
		if ( ! $all && $owner_user_id > 0 && (int) $row['owner_user_id'] !== $owner_user_id ) {
			return null;
		}
		return $row;
	}

	public function get_by_idempotency_key( string $key ): ?array {
		$key = trim( $key );
		if ( $key === '' ) {
			return null;
		}
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table} WHERE idempotency_key = %s LIMIT 1", $key ), ARRAY_A );
		return $row ?: null;
	}

	/**
	 * Update an owned entry and increment its revision.
	 *
	 * @return array|WP_Error
	 */
	public function update( int $id, int $owner_user_id, array $data, bool $all = false ) {
		// [2026-08-02 Johnny Chu] PHASE-SKILLS-JOURNAL — ownership is checked
		// before any Journal mutation.
		$row = $this->get( $id, $owner_user_id, $all );
		if ( ! $row ) {
			return new WP_Error( 'journal_not_found', 'Không tìm thấy nhật ký.' );
		}
		// [2026-09-05 Johnny Chu - Chu Hoàng Anh] PHASE-1.33A — reject stale Journal editors before mutating the canonical owner row.
		$expected_revision = array_key_exists( 'expected_revision', $data ) ? max( 0, (int) $data['expected_revision'] ) : 0;
		if ( $expected_revision > 0 && $expected_revision !== (int) $row['revision'] ) {
			return new WP_Error( 'journal_revision_conflict', 'Nhật ký đã được cập nhật ở nơi khác.' );
		}
		$write = array();
		if ( array_key_exists( 'title', $data ) ) {
			$write['title'] = sanitize_text_field( (string) $data['title'] );
		}
		if ( array_key_exists( 'body', $data ) ) {
			$write['body'] = wp_kses_post( (string) $data['body'] );
		}
		if ( array_key_exists( 'status', $data ) && in_array( $data['status'], self::STATUSES, true ) ) {
			$write['status'] = $data['status'];
		}
		if ( array_key_exists( 'metadata', $data ) ) {
			$write['metadata'] = is_array( $data['metadata'] ) ? wp_json_encode( $data['metadata'], JSON_UNESCAPED_UNICODE ) : (string) $data['metadata'];
		}
		// [2026-10-12 09:52 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.100 S100-L15/L17 — index columns are patchable
		// (journal.file: re-kind, re-tag, re-subject a row — R-JN-6 "đổi loại = đổi liên kết, không chép file").
		if ( array_key_exists( 'kind', $data ) ) {
			$write['kind'] = self::sanitize_kind( (string) $data['kind'] );
		}
		if ( array_key_exists( 'subject_hash', $data ) ) {
			$write['subject_hash'] = self::sanitize_subject_hash( (string) $data['subject_hash'] );
		}
		if ( array_key_exists( 'doc_date', $data ) ) {
			$write['doc_date'] = self::sanitize_doc_date( $data['doc_date'] );
		}
		if ( array_key_exists( 'tags', $data ) ) {
			$write['tags'] = self::encode_tags( (array) $data['tags'] );
		}
		// PHASE-0.133 S133-Ma — `find_text` is rebuilt by BizCity_Journal_Index whenever the row or its meta changes; it is not a revision of its own.
		$find_only = false;
		if ( array_key_exists( 'find_text', $data ) ) {
			$write['find_text'] = self::clean_find_text( (string) $data['find_text'] );
			$find_only          = 1 === count( $write );
		}
		if ( empty( $write ) ) {
			return $row;
		}
		if ( $find_only ) {
			global $wpdb;
			$wpdb->update( $this->table, $write, array( 'id' => $id ) );
			$this->flush_cache();
			return $this->get( $id, $owner_user_id, $all );
		}
		$write['revision'] = (int) $row['revision'] + 1;
		global $wpdb;
		$where = array( 'id' => $id );
		if ( $expected_revision > 0 ) {
			$where['revision'] = $expected_revision;
		}
		$updated = $wpdb->update( $this->table, $write, $where );
		if ( false === $updated ) {
			return new WP_Error( 'journal_update_failed', 'Không thể cập nhật nhật ký.' );
		}
		if ( $expected_revision > 0 && 1 !== (int) $updated ) {
			return new WP_Error( 'journal_revision_conflict', 'Nhật ký đã được cập nhật ở nơi khác.' );
		}
		$this->flush_cache();
		return $this->get( $id, $owner_user_id, $all );
	}

	/** [2026-10-17 08:40 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.121 B1-4 (S121-A4, I-3) — the row whose source_ref is a document code (TL-…), any owner; null when none. */
	public function get_by_source_ref( string $source_ref ): ?array {
		$source_ref = trim( $source_ref );
		if ( '' === $source_ref ) {
			return null;
		}
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table} WHERE source_ref = %s AND status NOT IN ('archived','void') ORDER BY id ASC LIMIT 1", $source_ref ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * [2026-10-25 Johnny Chu - Chu Hoàng Anh] PHASE-0.134 — the row of ONE owner whose ORIGINAL carries a document code (TL-…): a file the assistant made gets its
	 * TL- code from the private store (metadata.original.doc_code) while its source_ref stays the cell output id (co_…), so get_by_source_ref misses it.
	 */
	public function get_by_doc_code( string $code, int $owner_user_id ): ?array {
		if ( 1 !== preg_match( '/^TL-[0-9]{1,12}$/', $code ) || $owner_user_id <= 0 ) {
			return null;
		}
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table} WHERE owner_user_id = %d AND metadata LIKE %s AND status NOT IN ('archived','void') ORDER BY id DESC LIMIT 1", $owner_user_id, '%' . $wpdb->esc_like( '"doc_code":"' . $code . '"' ) . '%' ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/** [2026-10-17 07:44 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.121 S121-J4 — the row that points at one KG source (idx_kg_source), any owner; null when none. */
	public function get_by_kg_source_id( int $kg_source_id ): ?array {
		if ( $kg_source_id <= 0 ) {
			return null;
		}
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table} WHERE kg_source_id = %d ORDER BY id ASC LIMIT 1", $kg_source_id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	public function archive( int $id, int $owner_user_id, bool $all = false, int $expected_revision = 0 ) {
		// [2026-09-05 Johnny Chu - Chu Hoàng Anh] PHASE-1.33A — archive uses the same optimistic revision boundary as Journal updates.
		$data = array( 'status' => 'archived' );
		if ( $expected_revision > 0 ) {
			$data['expected_revision'] = $expected_revision;
		}
		return $this->update( $id, $owner_user_id, $data, $all );
	}

	/**
	 * Link a learning projection without changing the Journal body/revision.
	 *
	 * @return array|WP_Error
	 */
	public function mark_learning_projection( int $id, int $owner_user_id, array $projection ) {
		$row = $this->get( $id, $owner_user_id );
		if ( ! $row ) {
			return new WP_Error( 'journal_not_found', 'Không tìm thấy nhật ký.' );
		}

		$status = sanitize_key( (string) ( $projection['learning_status'] ?? 'queued' ) );
		$allowed = array( 'not_queued', 'queued', 'processing', 'learned', 'failed', 'retryable' );
		if ( ! in_array( $status, $allowed, true ) ) {
			$status = 'queued';
		}
		$metadata = $projection['metadata'] ?? null;
		$write = array(
			'learning_status' => $status,
			'learning_error'  => sanitize_text_field( (string) ( $projection['learning_error'] ?? '' ) ),
			'notebook_id'     => (int) ( $projection['notebook_id'] ?? $row['notebook_id'] ),
			'kg_source_id'    => (int) ( $projection['kg_source_id'] ?? $row['kg_source_id'] ),
		);
		if ( null !== $metadata ) {
			$write['metadata'] = is_array( $metadata ) ? wp_json_encode( $metadata, JSON_UNESCAPED_UNICODE ) : (string) $metadata;
		}

		global $wpdb;
		if ( false === $wpdb->update( $this->table, $write, array( 'id' => $id ) ) ) {
			return new WP_Error( 'journal_projection_update_failed', 'Không thể cập nhật trạng thái học của nhật ký.' );
		}
		$this->flush_cache();
		return $this->get( $id, $owner_user_id );
	}

	private function normalize_write_data( array $data, int $owner, string $title, string $body, string $key ): array {
		$metadata = $data['metadata'] ?? array();
		return array(
			'owner_user_id'   => $owner,
			'workspace_id'    => sanitize_key( (string) ( $data['workspace_id'] ?? 'notion' ) ) ?: 'notion',
			'title'           => $title,
			'body'            => $body,
			'status'          => in_array( $data['status'] ?? 'draft', self::STATUSES, true ) && 'archived' !== ( $data['status'] ?? '' ) ? $data['status'] : 'draft',
			'source_type'     => sanitize_key( (string) ( $data['source_type'] ?? 'menu' ) ) ?: 'menu',
			'source_ref'      => sanitize_text_field( (string) ( $data['source_ref'] ?? '' ) ),
			'idempotency_key' => $key,
			'revision'        => 1,
			'learning_status' => 'not_queued',
			'notebook_id'     => (int) ( $data['notebook_id'] ?? 0 ),
			'kg_source_id'    => (int) ( $data['kg_source_id'] ?? 0 ),
			// [2026-10-12 09:52 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.100 S100-L15 (R-JOURNAL R-JN-4) — index columns.
			'kind'            => self::sanitize_kind( (string) ( $data['kind'] ?? '' ) ),
			'subject_hash'    => self::sanitize_subject_hash( (string) ( $data['subject_hash'] ?? '' ) ),
			'doc_date'        => self::sanitize_doc_date( $data['doc_date'] ?? null ),
			'tags'            => self::encode_tags( (array) ( $data['tags'] ?? array() ) ),
			'metadata'        => is_array( $metadata ) ? wp_json_encode( $metadata, JSON_UNESCAPED_UNICODE ) : (string) $metadata,
			'find_text'       => self::clean_find_text( (string) ( $data['find_text'] ?? '' ) ), // PHASE-0.133 S133-Ma
		);
	}

	/**
	 * [2026-10-24 05:36 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.133 S133-V1 / S133-Ma — fields DERIVED by the site from a row it just wrote (metadata.resource,
	 * find_text): written without a revision bump (not an edit by anyone), owner already checked by the caller.
	 */
	public function write_derived( int $id, array $data ) {
		global $wpdb;
		$write = array();
		if ( array_key_exists( 'metadata', $data ) ) {
			$write['metadata'] = is_array( $data['metadata'] ) ? wp_json_encode( $data['metadata'], JSON_UNESCAPED_UNICODE ) : (string) $data['metadata'];
		}
		if ( array_key_exists( 'find_text', $data ) ) {
			$write['find_text'] = self::clean_find_text( (string) $data['find_text'] );
		}
		if ( ! $write || $id <= 0 ) {
			return false;
		}
		$ok = $wpdb->update( $this->table, $write, array( 'id' => $id ) );
		$this->flush_cache();
		return false !== $ok;
	}

	/** PHASE-0.133 S133-V1 — the source_uuid an owner's earlier row with the same original bytes already carries ('' = none). */
	public function source_uuid_by_sha256( int $owner_user_id, string $sha256 ): string {
		global $wpdb;
		if ( $owner_user_id <= 0 || 1 !== preg_match( '/^[0-9a-f]{64}$/', $sha256 ) ) {
			return '';
		}
		$meta = (string) $wpdb->get_var( $wpdb->prepare( "SELECT metadata FROM {$this->table} WHERE owner_user_id = %d AND metadata LIKE %s AND metadata LIKE %s ORDER BY id ASC LIMIT 1", $owner_user_id, '%' . $wpdb->esc_like( '"sha256":"' . $sha256 . '"' ) . '%', '%' . $wpdb->esc_like( '"source_uuid":"' ) . '%' ) );
		$m    = json_decode( $meta, true );
		return is_array( $m ) ? (string) ( $m['resource']['source_uuid'] ?? '' ) : '';
	}

	/** find_text as stored: no markup, single spaces, ≤ 12 000 chars (PHASE-0.133). Pure apart from wp_strip_all_tags. */
	public static function clean_find_text( string $s ): string {
		$s = function_exists( 'wp_strip_all_tags' ) ? wp_strip_all_tags( $s ) : strip_tags( $s );
		$s = (string) preg_replace( '/\s+/u', ' ', $s );
		return mb_substr( trim( $s ), 0, 12000 );
	}

	/** `<family>.<kind>`, lowercase, dot + alnum/underscore only, ≤ 48 chars (journal-kinds@1). */
	public static function sanitize_kind( string $kind ): string {
		$kind = strtolower( trim( $kind ) );
		$kind = (string) preg_replace( '/[^a-z0-9_.]/', '', $kind );
		return substr( $kind, 0, 48 );
	}

	/** `s-` + 16 hex (R-CID-11 hashed subject, doc 92 §2). Anything else is dropped (empty = "no subject"). */
	public static function sanitize_subject_hash( string $hash ): string {
		$hash = strtolower( trim( $hash ) );
		return 1 === preg_match( '/^s-[0-9a-f]{16}$/', $hash ) ? $hash : '';
	}

	/** 's-' + first 16 hex of sha256(type ':' value) — the one subject-hash builder every caller shares. */
	public static function subject_hash( string $type, string $value ): string {
		$type  = sanitize_key( $type ) ?: 'self';
		$value = trim( $value );
		if ( '' === $value && 'self' !== $type ) {
			return '';
		}
		return 's-' . substr( hash( 'sha256', $type . ':' . $value ), 0, 16 );
	}

	/** 'Y-m-d' or null. */
	public static function sanitize_doc_date( $date ) {
		$date = trim( (string) $date );
		return 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ? $date : null;
	}

	/** Stored as ',a,b,c,' so `LIKE '%,tag,%'` matches a whole tag (doc 92 §5). Lowercase, ≤ 12 tags, ≤ 40 chars each. */
	public static function encode_tags( array $tags ): string {
		$clean = array();
		foreach ( $tags as $t ) {
			$t = strtolower( trim( (string) $t ) );
			$t = (string) preg_replace( '/[^a-z0-9\-]/', '', $t );
			$t = substr( $t, 0, 40 );
			if ( '' !== $t && ! in_array( $t, $clean, true ) ) {
				$clean[] = $t;
			}
			if ( count( $clean ) >= 12 ) {
				break;
			}
		}
		return empty( $clean ) ? '' : ',' . implode( ',', $clean ) . ',';
	}

	/** Reverse of encode_tags(). */
	public static function decode_tags( string $stored ): array {
		$stored = trim( $stored, ',' );
		return '' === $stored ? array() : explode( ',', $stored );
	}

	private function flush_cache(): void {
		if ( class_exists( 'BizCity_Cache' ) ) {
			BizCity_Cache::flush_group( self::CACHE_GROUP );
		}
	}

	private function cache_get( string $key ) {
		if ( class_exists( 'BizCity_Cache' ) ) {
			return BizCity_Cache::get( self::CACHE_GROUP, $key );
		}
		return false;
	}

	private function cache_set( string $key, $value ): void {
		if ( class_exists( 'BizCity_Cache' ) ) {
			BizCity_Cache::set( self::CACHE_GROUP, $key, $value, defined( 'BizCity_Cache::TTL_MEDIUM' ) ? BizCity_Cache::TTL_MEDIUM : 300 );
		}
	}
}

// [2026-10-20 10:53 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.126 S126-G3 (GAP-123-5) — the spine table is owned by core/kg-hub: changelog core.knowledge.kg-hub.json (was core.skills).
if ( class_exists( 'BizCity_Schema_Registry' ) ) {
	BizCity_Schema_Registry::register(
		'bizcity_journal_entries',
		'core.knowledge.kg-hub',
		BizCity_Journal_Database::SCHEMA_VERSION,
		BizCity_Journal_Database::SCHEMA_VERSION_KEY,
		array( 'BizCity_Journal_Database', 'maybe_install' )
	);
}
