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

	const SCHEMA_VERSION = '1.1.0';
	const SCHEMA_VERSION_KEY = 'bizcity_journal_db_version';
	const CACHE_GROUP = 'journal';
	// [2026-10-12 09:52 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.100 S100-L15 — backfill batch size, bounded (R-JOURNAL R-JN-4).
	const BACKFILL_BATCH = 500;

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
			status              ENUM('draft','captured','published','archived') NOT NULL DEFAULT 'draft',
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
		$where = array( "status != 'archived'" );
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
		$where  = array( "status != 'archived'" );
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
		// `q` narrows the already-structured-filtered set (title only — R-JN-5: semantic search is a separate pass).
		if ( '' !== trim( (string) ( $filter['q'] ?? '' ) ) ) {
			$where[]  = 'title LIKE %s';
			$params[] = '%' . $wpdb->esc_like( trim( (string) $filter['q'] ) ) . '%';
		}
		$where_sql = implode( ' AND ', $where );
		$total = (int) $wpdb->get_var( $params ? $wpdb->prepare( "SELECT COUNT(*) FROM {$this->table} WHERE {$where_sql}", $params ) : "SELECT COUNT(*) FROM {$this->table} WHERE {$where_sql}" );
		$limit  = min( 50, max( 1, (int) ( $filter['limit'] ?? 20 ) ) );
		$offset = max( 0, (int) ( $filter['offset'] ?? 0 ) );
		$list_params = array_merge( $params, array( $limit, $offset ) );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->table} WHERE {$where_sql} ORDER BY doc_date DESC, id DESC LIMIT %d OFFSET %d", $list_params ), ARRAY_A ) ?: array();
		return array( 'items' => $rows, 'total' => $total );
	}

	/** Shelf rows for `doc_index` (doc 92 §5): one row per `kind` the owner has, with count/date range/top tags/review count. */
	public function shelf_rows( int $owner_user_id, int $max = 40 ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT kind, COUNT(*) AS c, MIN(doc_date) AS d_from, MAX(doc_date) AS d_to, MAX(updated_at) AS u
			 FROM {$this->table} WHERE owner_user_id = %d AND status != 'archived' AND kind != '' GROUP BY kind ORDER BY c DESC LIMIT %d",
			$owner_user_id, max( 1, $max )
		), ARRAY_A ) ?: array();
		return $rows;
	}

	/** Subject rows for `doc_index` (doc 92 §5): per subject, kind counts + last activity. */
	public function subject_rows( int $owner_user_id, int $max = 30 ): array {
		global $wpdb;
		$subjects = $wpdb->get_col( $wpdb->prepare(
			"SELECT DISTINCT subject_hash FROM {$this->table} WHERE owner_user_id = %d AND status != 'archived' AND subject_hash != '' ORDER BY updated_at DESC LIMIT %d",
			$owner_user_id, max( 1, $max )
		) ) ?: array();
		$out = array();
		foreach ( $subjects as $sh ) {
			$kind_rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT kind, COUNT(*) AS c, MAX(doc_date) AS last FROM {$this->table} WHERE owner_user_id = %d AND subject_hash = %s AND status != 'archived' GROUP BY kind",
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
	public function recent_rows( int $owner_user_id, int $max = 20 ): array {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT id, kind, title, doc_date, tags, updated_at FROM {$this->table} WHERE owner_user_id = %d AND status != 'archived' ORDER BY updated_at DESC LIMIT %d",
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
		if ( array_key_exists( 'status', $data ) && in_array( $data['status'], array( 'draft', 'captured', 'published', 'archived' ), true ) ) {
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
		if ( empty( $write ) ) {
			return $row;
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
			'status'          => in_array( $data['status'] ?? 'draft', array( 'draft', 'captured', 'published' ), true ) ? $data['status'] : 'draft',
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
		);
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

if ( class_exists( 'BizCity_Schema_Registry' ) ) {
	BizCity_Schema_Registry::register(
		'bizcity_journal_entries',
		'core.skills',
		BizCity_Journal_Database::SCHEMA_VERSION,
		BizCity_Journal_Database::SCHEMA_VERSION_KEY,
		array( 'BizCity_Journal_Database', 'maybe_install' )
	);
}
