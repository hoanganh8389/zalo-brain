<?php
/**
 * BizCity_Journal_Index — the `journal-entry@1.0.0` read/write API over `bizcity_journal_entries` (PHASE-0.100
 * S100-L15/doc 92 §2, lanes/INTERFACES.md I-4). This is the API the site-profile lane calls (class_exists guard)
 * and the one `BizCity_Journal_MCP_Tools` (core/mcp) delegates to. SQL filters on the index columns first; `q`
 * only narrows the already-structured-filtered set (R-JOURNAL R-JN-5) — no KG retriever call here (that stays a
 * separate, heavier semantic pass the cell/skill layer may add later; noted, not built, per the task's own caution).
 *
 * Every read/write takes `owner_user_id` (0 + `all:true` = admin/diagnostics cross-owner read, same shape as
 * `BizCity_Journal_Database::list_entries()`).
 *
 * Biz Central Brain — Johnny Chu (Chu Hoàng Anh).
 *
 * [2026-10-12 10:15 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.100 S100-L15/L19 — new file.
 * [2026-10-17 07:44 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.121 S121-J2/J3 (R-JOURNAL v1.1 R-JN-12…18) — `journal-entry@1.1.0`
 * (core/kg-hub/contracts/JOURNAL-ENTRY-v1.json): state · summary · original{doc_code, sha256…} · origin · error · mirror · learning;
 * `set_state()` (verified = a person, never a model); `register_row()` = the doc-register@1 projection; action
 * `bizcity_journal_entry_added` on a NEW row only (a replay on the same idempotency_key stays silent). This class is the ONE
 * writer every entry point and every plugin goes through (`bizcity_journal_record()`): no second ledger anywhere.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\KG_Hub
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Journal_Index', false ) ) {
	return;
}

final class BizCity_Journal_Index {

	const CONTRACT     = 'journal-entry@1.1.0';
	const STATES       = array( 'captured', 'review', 'verified', 'failed', 'void' );
	/** closed `source_type` set (R-JN-19); anything else ⇒ `plugin` */
	const SOURCE_TYPES = array( 'owner_capture', 'web_upload', 'context_producer', 'derived', 'track', 'job', 'automation_run', 'cell_output', 'crm', 'plugin', 'file', 'menu' );
	const LEARN_LEVELS = array( 'L0', 'L1', 'L2', 'L3' );

	/**
	 * Create one journal-entry@1 row. Idempotent on `idempotency_key` — a replay returns the existing row
	 * (same contract as `BizCity_Journal_Database::create()`).
	 *
	 * @param array $entry {owner_user_id, workspace_id?, kind, title, doc_date?, subject_hash?|subject{type,hash},
	 *                       tags?, card?, source_type, source_ref?, idempotency_key, notebook_id?, kg_source_id?,
	 *                       flags?, by?, confidence?, original?}
	 * @return array|WP_Error journal-entry@1 row (decoded), or WP_Error.
	 */
	public static function add( array $entry ) {
		if ( ! class_exists( 'BizCity_Journal_Database' ) ) {
			return new WP_Error( 'journal_unavailable', 'Chỉ mục nhật ký chưa sẵn sàng trên site này.' );
		}
		BizCity_Journal_Database::maybe_install();
		$owner = (int) ( $entry['owner_user_id'] ?? 0 );
		if ( $owner <= 0 ) {
			return new WP_Error( 'journal_invalid_entry', 'Thiếu owner_user_id.' );
		}
		$kind  = BizCity_Journal_Kinds::resolve( (string) ( $entry['kind'] ?? '' ), (string) ( $entry['original']['mime'] ?? '' ) );
		$title = sanitize_text_field( (string) ( $entry['title'] ?? '' ) );
		if ( '' === $title ) {
			$title = BizCity_Journal_Kinds::label( $kind ) . ' ' . gmdate( 'd/m' );
		}
		$subject_hash = (string) ( $entry['subject_hash'] ?? '' );
		if ( '' === $subject_hash && is_array( $entry['subject'] ?? null ) && '' !== (string) ( $entry['subject']['hash'] ?? '' ) ) {
			$subject_hash = (string) $entry['subject']['hash'];
		}
		$confidence = isset( $entry['confidence'] ) ? (float) $entry['confidence'] : 1.0;
		$flags      = is_array( $entry['flags'] ?? null ) ? $entry['flags'] : array();
		if ( $confidence < 0.6 ) {
			$flags['review'] = true;
		}
		$sensitive = BizCity_Journal_Kinds::sensitive( $kind );
		if ( '' !== $sensitive ) {
			$flags['sensitive'] = $sensitive; // R-JN-11 — stamped from the kind, caller cannot downgrade it.
		}
		// [2026-10-17 07:44 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.121 — journal-entry@1.1.0 fields (all additive).
		$state = self::sanitize_state( (string) ( $entry['state'] ?? '' ), 'captured' );
		if ( 'verified' === $state && (int) ( $flags['verified']['by'] ?? 0 ) <= 0 ) {
			return new WP_Error( 'journal_verified_needs_person', 'Trạng thái verified chỉ do người đặt (flags.verified.by).' ); // R-JN-14
		}
		$original = self::normalize_original( is_array( $entry['original'] ?? null ) ? $entry['original'] : array() );
		$summary  = sanitize_textarea_field( (string) ( $entry['summary'] ?? ( $entry['card']['summary'] ?? '' ) ) );
		$metadata = array(
			'card'       => is_array( $entry['card'] ?? null ) ? $entry['card'] : array(),
			'confidence' => $confidence,
			'original'   => $original,
			'subject'    => is_array( $entry['subject'] ?? null ) ? $entry['subject'] : array( 'type' => $subject_hash ? 'contact' : 'none', 'hash' => $subject_hash ),
			'derived_from' => array_values( array_map( 'intval', (array) ( $entry['derived_from'] ?? array() ) ) ),
			'flags'      => $flags,
			'by'         => is_array( $entry['by'] ?? null ) ? $entry['by'] : array(),
			'origin'     => self::normalize_origin( is_array( $entry['origin'] ?? null ) ? $entry['origin'] : array(), (string) ( $entry['source_type'] ?? '' ) ),
			'mirror'     => array( 'sheet' => in_array( (string) ( $entry['mirror']['sheet'] ?? '' ), array( 'off', 'pending', 'ok', 'failed' ), true ) ? (string) $entry['mirror']['sheet'] : 'off', 'row_ref' => (string) ( $entry['mirror']['row_ref'] ?? '' ) ),
			'learning'   => self::normalize_learning( is_array( $entry['learning'] ?? null ) ? $entry['learning'] : array(), $kind ),
		);
		if ( 'failed' === $state ) {
			$metadata['error'] = array( 'code' => sanitize_key( (string) ( $entry['error']['code'] ?? 'store_failed' ) ), 'message' => sanitize_text_field( (string) ( $entry['error']['message'] ?? '' ) ) );
		}
		$key      = (string) ( $entry['idempotency_key'] ?? '' );
		$existing = '' !== $key ? BizCity_Journal_Database::instance()->get_by_idempotency_key( $key ) : null;
		$row = BizCity_Journal_Database::instance()->create( array(
			'owner_user_id'   => $owner,
			'workspace_id'    => (string) ( $entry['workspace_id'] ?? 'journal' ),
			'title'           => $title,
			'body'            => '' !== $summary ? $summary : $title, // body = summary (journal-entry@1.1.0); the title stands in when nobody wrote one.
			'status'          => $state,
			'source_type'     => self::sanitize_source_type( (string) ( $entry['source_type'] ?? 'file' ) ),
			'source_ref'      => (string) ( $entry['source_ref'] ?? ( $original['doc_code'] ?? '' ) ),
			'idempotency_key' => (string) ( $entry['idempotency_key'] ?? '' ),
			'notebook_id'     => (int) ( $entry['notebook_id'] ?? 0 ),
			'kg_source_id'    => (int) ( $entry['kg_source_id'] ?? 0 ),
			'kind'            => $kind,
			'subject_hash'    => $subject_hash,
			'doc_date'        => $entry['doc_date'] ?? null,
			'tags'            => (array) ( $entry['tags'] ?? array() ),
			'metadata'        => $metadata,
		) );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		self::flush_doc_index( $owner );
		$out = self::to_journal_entry( $row );
		if ( null === $existing && function_exists( 'do_action' ) ) {
			/**
			 * [2026-10-17 07:44 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.121 S121-J5 (R-JN-15/R-JN-17) — ONE new journal row. The Sheet mirror, Automation and plugins listen here;
			 * nobody polls the table. Not fired on an idempotent replay.
			 *
			 * @param array $out journal-entry@1.1.0
			 */
			do_action( 'bizcity_journal_entry_added', $out );
		}
		return $out;
	}

	/**
	 * [2026-10-17 07:44 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.121 S121-J3 (R-JN-14) — move a row along its life cycle: captured · review · verified · failed · void.
	 * `verified` needs the PERSON who verified ($by_user_id > 0) — a model, a job or a cron can never set it. `void` keeps the
	 * row (history). Fires `bizcity_journal_entry_state( entry, from, to )`.
	 *
	 * @return array|WP_Error journal-entry@1.1.0
	 */
	public static function set_state( int $id, int $owner_user_id, string $state, array $extra = array(), int $by_user_id = 0 ) {
		if ( ! class_exists( 'BizCity_Journal_Database' ) ) {
			return new WP_Error( 'journal_unavailable', 'Chỉ mục nhật ký chưa sẵn sàng trên site này.' );
		}
		$state = self::sanitize_state( $state, '' );
		if ( '' === $state ) {
			return new WP_Error( 'journal_invalid_state', 'Trạng thái không hợp lệ (captured · review · verified · failed · void).' );
		}
		if ( 'verified' === $state && $by_user_id <= 0 ) {
			return new WP_Error( 'journal_verified_needs_person', 'Trạng thái verified chỉ do người đặt.' );
		}
		$db  = BizCity_Journal_Database::instance();
		$row = $db->get( $id, $owner_user_id );
		if ( ! $row ) {
			return new WP_Error( 'journal_not_found', 'Không tìm thấy mục nhật ký.' );
		}
		$from     = (string) ( $row['status'] ?? '' );
		$metadata = is_array( json_decode( (string) ( $row['metadata'] ?? '' ), true ) ) ? json_decode( (string) $row['metadata'], true ) : array();
		$flags    = is_array( $metadata['flags'] ?? null ) ? $metadata['flags'] : array();
		if ( 'verified' === $state ) {
			$flags['verified'] = array( 'by' => $by_user_id, 'at' => gmdate( 'c' ) );
			$flags['review']   = false;
		} elseif ( isset( $flags['verified'] ) && in_array( $state, array( 'captured', 'review' ), true ) ) {
			unset( $flags['verified'] );
		}
		$metadata['flags'] = $flags;
		if ( 'failed' === $state ) {
			$metadata['error'] = array( 'code' => sanitize_key( (string) ( $extra['error']['code'] ?? 'store_failed' ) ), 'message' => sanitize_text_field( (string) ( $extra['error']['message'] ?? '' ) ) );
		} else {
			unset( $metadata['error'] );
		}
		if ( isset( $extra['mirror'] ) && is_array( $extra['mirror'] ) ) {
			$metadata['mirror'] = array_merge( is_array( $metadata['mirror'] ?? null ) ? $metadata['mirror'] : array(), $extra['mirror'] );
		}
		$updated = $db->update( $id, $owner_user_id, array( 'status' => $state, 'metadata' => $metadata ) );
		if ( is_wp_error( $updated ) ) {
			return $updated;
		}
		self::flush_doc_index( $owner_user_id );
		$out = self::to_journal_entry( $updated );
		if ( function_exists( 'do_action' ) ) {
			do_action( 'bizcity_journal_entry_state', $out, $from, $state );
		}
		return $out;
	}

	/**
	 * [2026-10-17 07:44 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.121 S121-R4 (doc 70 §3) — `doc-register@1`: ONE flat row for the Google Sheet mirror (`sheet-ledger@1`
	 * docs_v1), CSV export and the web register. A projection of journal-entry@1.1.0, never a second source. Carries no URL,
	 * no hash, no phone, no uid (R-JN-11, doc 30 §6). Pure.
	 */
	public static function register_row( array $entry ): array {
		$kind      = (string) ( $entry['kind'] ?? '' );
		$orig      = is_array( $entry['original'] ?? null ) ? $entry['original'] : array();
		$flags     = is_array( $entry['flags'] ?? null ) ? $entry['flags'] : array();
		$sensitive = '' !== (string) ( $flags['sensitive'] ?? '' );
		$card      = is_array( $entry['card'] ?? null ) ? $entry['card'] : array();
		$fields    = array();
		$decl      = class_exists( 'BizCity_Journal_Kinds' ) ? BizCity_Journal_Kinds::get( $kind ) : null;
		foreach ( (array) ( $decl['card_fields'] ?? array() ) as $k ) {
			if ( 'summary' !== $k && isset( $card[ $k ] ) && is_scalar( $card[ $k ] ) ) {
				$fields[] = $k . ': ' . mb_substr( (string) $card[ $k ], 0, 80 );
			}
		}
		return array(
			'contract'    => 'doc-register@1',
			'doc_code'    => (string) ( $orig['doc_code'] ?? '' ),
			'entry_id'    => (int) ( $entry['entry_id'] ?? 0 ),
			'captured_at' => (string) ( $entry['created_at'] ?? '' ),
			'doc_date'    => (string) ( $entry['doc_date'] ?? '' ),
			'title'       => (string) ( $entry['title'] ?? '' ),
			'file_name'   => (string) ( $orig['file_name'] ?? '' ),
			'kind'        => $kind,
			'kind_label'  => class_exists( 'BizCity_Journal_Kinds' ) ? BizCity_Journal_Kinds::label( $kind ) : $kind,
			// Q121-16 default: a sensitive kind (finance / health / child / personal) mirrors name + date + state only.
			'summary'     => $sensitive ? '' : mb_substr( (string) ( $entry['summary'] ?? '' ), 0, 300 ),
			'key_fields'  => $sensitive ? '' : implode( '; ', $fields ),
			'tags'        => implode( ',', array_map( 'strval', (array) ( $entry['tags'] ?? array() ) ) ),
			'state'       => (string) ( $entry['state'] ?? '' ),
			'verified'    => ! empty( $flags['verified']['by'] ) ? 'yes' : 'no',
			'sender_role' => (string) ( $entry['by']['role'] ?? '' ),
			'notebook'    => (string) ( $entry['kg']['notebook_ids'][0] ?? '' ),
			'level'       => (string) ( $entry['learning']['level'] ?? 'L0' ),
			'source_ref'  => (string) ( $orig['doc_code'] ?? '' ) !== '' ? (string) $orig['doc_code'] : 'JE-' . (int) ( $entry['entry_id'] ?? 0 ),
		);
	}

	/**
	 * @param array $filter {owner_user_id, all?, kind?, kinds?[], kind_prefix?, tags?[], subject_hash?, from?, to?, q?, limit≤50, offset?}
	 * @return array{items:array,total:int}
	 */
	public static function find( array $filter ): array {
		if ( ! class_exists( 'BizCity_Journal_Database' ) ) {
			return array( 'items' => array(), 'total' => 0 );
		}
		BizCity_Journal_Database::maybe_install();
		$res = BizCity_Journal_Database::instance()->find_indexed( $filter );
		$res['items'] = array_map( array( __CLASS__, 'to_journal_entry' ), $res['items'] );
		return $res;
	}

	public static function get( int $id, int $owner_user_id = 0, bool $all = false ) {
		if ( ! class_exists( 'BizCity_Journal_Database' ) ) {
			return null;
		}
		$row = BizCity_Journal_Database::instance()->get( $id, $owner_user_id, $all );
		return $row ? self::to_journal_entry( $row ) : null;
	}

	public static function get_by_idempotency_key( string $key, int $owner_user_id = 0 ) {
		if ( ! class_exists( 'BizCity_Journal_Database' ) ) {
			return null;
		}
		$row = BizCity_Journal_Database::instance()->get_by_idempotency_key( $key );
		if ( ! $row ) {
			return null;
		}
		if ( $owner_user_id > 0 && (int) $row['owner_user_id'] !== $owner_user_id ) {
			return null;
		}
		return self::to_journal_entry( $row );
	}

	/**
	 * Patch kind/tags/subject_hash/flags(/pinned/review)/doc_date on a row the owner already has — idempotent,
	 * never touches `original` (R-JN-2). `$patch` keys: kind?, tags?, subject_hash?, doc_date?, pinned?, review?,
	 * card? (merges into metadata.card — used by the owner-capture follow-up "card" event, I-3).
	 *
	 * @return array|WP_Error journal-entry@1 row.
	 */
	public static function file( int $id, int $owner_user_id, array $patch ) {
		if ( ! class_exists( 'BizCity_Journal_Database' ) ) {
			return new WP_Error( 'journal_unavailable', 'Chỉ mục nhật ký chưa sẵn sàng trên site này.' );
		}
		$db  = BizCity_Journal_Database::instance();
		$row = $db->get( $id, $owner_user_id );
		if ( ! $row ) {
			return new WP_Error( 'journal_not_found', 'Không tìm thấy mục nhật ký.' );
		}
		$write = array();
		if ( array_key_exists( 'kind', $patch ) ) {
			$write['kind'] = (string) $patch['kind'];
		}
		if ( array_key_exists( 'tags', $patch ) ) {
			$write['tags'] = (array) $patch['tags'];
		}
		if ( array_key_exists( 'subject_hash', $patch ) ) {
			$write['subject_hash'] = (string) $patch['subject_hash'];
		}
		if ( array_key_exists( 'doc_date', $patch ) ) {
			$write['doc_date'] = $patch['doc_date'];
		}
		$metadata = is_array( json_decode( (string) ( $row['metadata'] ?? '' ), true ) ) ? json_decode( (string) $row['metadata'], true ) : array();
		$flags    = is_array( $metadata['flags'] ?? null ) ? $metadata['flags'] : array();
		$changed_meta = false;
		if ( array_key_exists( 'pinned', $patch ) ) {
			$flags['pinned'] = (bool) $patch['pinned'];
			$changed_meta = true;
		}
		if ( array_key_exists( 'review', $patch ) ) {
			$flags['review'] = (bool) $patch['review'];
			$changed_meta = true;
		}
		if ( $changed_meta ) {
			$metadata['flags'] = $flags;
		}
		// [2026-10-17 07:44 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.121 — 1.1.0 patches: summary (body), learning progress (R-JN-20/21), original pointers added later (a set sha256 is never overwritten — R-JN-2).
		if ( array_key_exists( 'summary', $patch ) ) {
			$write['body'] = sanitize_textarea_field( (string) $patch['summary'] ) ?: (string) $row['title'];
		}
		if ( array_key_exists( 'learning', $patch ) && is_array( $patch['learning'] ) ) {
			$metadata['learning'] = self::normalize_learning( array_merge( is_array( $metadata['learning'] ?? null ) ? $metadata['learning'] : array(), $patch['learning'] ), (string) ( $row['kind'] ?? '' ) );
			$changed_meta = true;
		}
		if ( array_key_exists( 'original', $patch ) && is_array( $patch['original'] ) ) {
			$cur = is_array( $metadata['original'] ?? null ) ? $metadata['original'] : array();
			$new = self::normalize_original( array_merge( $cur, $patch['original'] ) );
			if ( '' !== (string) ( $cur['sha256'] ?? '' ) ) {
				$new['sha256'] = $cur['sha256'];
			}
			$metadata['original'] = $new;
			$changed_meta = true;
		}
		if ( array_key_exists( 'card', $patch ) && is_array( $patch['card'] ) ) {
			// [2026-10-12 10:15 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.100 — the owner-capture@1.2.0 follow-up "card"
			// event (I-3): keep one previous card for undo, like skill's prev_body_md.
			$metadata['prev_card'] = is_array( $metadata['card'] ?? null ) ? $metadata['card'] : array();
			$metadata['card']      = array_merge( is_array( $metadata['card'] ?? null ) ? $metadata['card'] : array(), $patch['card'] );
			$changed_meta = true;
		}
		if ( $changed_meta ) {
			$write['metadata'] = $metadata;
		}
		if ( empty( $write ) ) {
			return self::to_journal_entry( $row );
		}
		$updated = $db->update( $id, $owner_user_id, $write );
		if ( is_wp_error( $updated ) ) {
			return $updated;
		}
		self::flush_doc_index( $owner_user_id );
		return self::to_journal_entry( $updated );
	}

	/**
	 * `file()` by `idempotency_key` instead of `id` — used by (a) the owner-capture@1.2.0 follow-up `kind:"card"`
	 * event, which only knows the file's `idempotency_key`, never its row id (I-3), and (b) `track.set` upserts
	 * (I-4 "theo dõi"), which key on `track:<blog>:<subject_hash>:<unit_id|->:<Y-m-d>`.
	 *
	 * @return array|WP_Error
	 */
	public static function file_by_idempotency_key( string $idempotency_key, int $owner_user_id, array $patch ) {
		if ( ! class_exists( 'BizCity_Journal_Database' ) ) {
			return new WP_Error( 'journal_unavailable', 'Chỉ mục nhật ký chưa sẵn sàng trên site này.' );
		}
		$row = BizCity_Journal_Database::instance()->get_by_idempotency_key( $idempotency_key );
		if ( ! $row || ( $owner_user_id > 0 && (int) $row['owner_user_id'] !== $owner_user_id ) ) {
			return new WP_Error( 'journal_not_found', 'Không tìm thấy mục nhật ký theo khoá idempotency.' );
		}
		return self::file( (int) $row['id'], (int) $row['owner_user_id'], $patch );
	}

	/**
	 * Deterministic aggregation over a structured card field (R-JOURNAL: "site tính tất định, không model").
	 *
	 * @param array  $filter   same shape as find().
	 * @param string $field    dotted path inside metadata.card, e.g. "total_kcal" or "items.*.kcal" (sums every match).
	 * @param string $group_by 'day'|'week'|'month'.
	 * @return array{groups:array<string,array{sum:float,count:int,avg:float}>,total:array{sum:float,count:int}}
	 */
	public static function summarize( array $filter, string $field, string $group_by = 'day' ): array {
		$filter['limit'] = 50;
		$groups     = array();
		$sum_total  = 0.0;
		$count_total = 0;
		$offset = 0;
		$guard  = 0;
		do {
			$filter['offset'] = $offset;
			$page = self::find( $filter );
			foreach ( $page['items'] as $item ) {
				$values = self::extract_field( (array) ( $item['card'] ?? array() ), $field );
				if ( empty( $values ) ) {
					continue;
				}
				$bucket = self::date_bucket( (string) ( $item['doc_date'] ?? '' ), $group_by );
				if ( '' === $bucket ) {
					continue;
				}
				if ( ! isset( $groups[ $bucket ] ) ) {
					$groups[ $bucket ] = array( 'sum' => 0.0, 'count' => 0 );
				}
				foreach ( $values as $v ) {
					$groups[ $bucket ]['sum'] += $v;
					$groups[ $bucket ]['count']++;
					$sum_total += $v;
					$count_total++;
				}
			}
			$offset += count( $page['items'] );
			$guard++;
		} while ( count( $page['items'] ) >= $filter['limit'] && $guard < 20 && $offset < $page['total'] );
		foreach ( $groups as $k => $g ) {
			$groups[ $k ]['avg'] = $g['count'] > 0 ? round( $g['sum'] / $g['count'], 2 ) : 0.0;
		}
		ksort( $groups );
		return array(
			'groups' => $groups,
			'total'  => array( 'sum' => round( $sum_total, 2 ), 'count' => $count_total ),
		);
	}

	/** @return float[] every numeric value `$field` matches inside `$card` (supports a flat key or `items.*.key`). */
	private static function extract_field( array $card, string $field ): array {
		if ( false === strpos( $field, '.' ) ) {
			return is_numeric( $card[ $field ] ?? null ) ? array( (float) $card[ $field ] ) : array();
		}
		$parts = explode( '.', $field, 3 );
		if ( count( $parts ) === 3 && '*' === $parts[1] && is_array( $card[ $parts[0] ] ?? null ) ) {
			$out = array();
			foreach ( $card[ $parts[0] ] as $row ) {
				if ( is_array( $row ) && is_numeric( $row[ $parts[2] ] ?? null ) ) {
					$out[] = (float) $row[ $parts[2] ];
				}
			}
			return $out;
		}
		return array();
	}

	private static function date_bucket( string $doc_date, string $group_by ): string {
		if ( '' === $doc_date || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $doc_date ) ) {
			return '';
		}
		if ( 'month' === $group_by ) {
			return substr( $doc_date, 0, 7 );
		}
		if ( 'week' === $group_by ) {
			$ts = strtotime( $doc_date . ' UTC' );
			return false === $ts ? '' : gmdate( 'o-\WW', $ts );
		}
		return $doc_date;
	}

	/** journal-entry@1.1.0 shape (JOURNAL-ENTRY-v1.json; every 1.0.0 key unchanged, doc 92 §2) from a raw `bizcity_journal_entries` row. */
	public static function to_journal_entry( array $row ): array {
		$metadata = array();
		if ( ! empty( $row['metadata'] ) ) {
			$decoded  = json_decode( (string) $row['metadata'], true );
			$metadata = is_array( $decoded ) ? $decoded : array();
		}
		$tags = class_exists( 'BizCity_Journal_Database' ) ? BizCity_Journal_Database::decode_tags( (string) ( $row['tags'] ?? '' ) ) : array();
		$title    = (string) ( $row['title'] ?? '' );
		$body     = trim( (string) ( $row['body'] ?? '' ) );
		$status   = (string) ( $row['status'] ?? '' );
		$learning = self::normalize_learning( is_array( $metadata['learning'] ?? null ) ? $metadata['learning'] : array(), (string) ( $row['kind'] ?? '' ) );
		$learning['status'] = (string) ( $row['learning_status'] ?? 'not_queued' );
		$learning['error']  = (string) ( $row['learning_error'] ?? '' );
		return array(
			'contract'     => self::CONTRACT,
			'entry_id'     => (int) $row['id'],
			'kind'         => (string) ( $row['kind'] ?? '' ),
			'title'        => $title,
			'summary'      => '' !== $body && $body !== $title ? $body : '',
			'state'        => in_array( $status, self::STATES, true ) ? $status : ( 'archived' === $status ? 'void' : 'captured' ),
			'origin'       => is_array( $metadata['origin'] ?? null ) ? $metadata['origin'] : array( 'surface' => '', 'ref' => (string) ( $row['source_ref'] ?? '' ) ),
			'source_type'  => (string) ( $row['source_type'] ?? '' ),
			'source_ref'   => (string) ( $row['source_ref'] ?? '' ),
			'error'        => is_array( $metadata['error'] ?? null ) ? $metadata['error'] : null,
			'mirror'       => is_array( $metadata['mirror'] ?? null ) ? $metadata['mirror'] : array( 'sheet' => 'off', 'row_ref' => '' ),
			'learning'     => $learning,
			'doc_date'     => $row['doc_date'] ?? null,
			'subject'      => is_array( $metadata['subject'] ?? null ) ? $metadata['subject'] : array( 'type' => 'none', 'hash' => (string) ( $row['subject_hash'] ?? '' ) ),
			'tags'         => $tags,
			'card'         => is_array( $metadata['card'] ?? null ) ? $metadata['card'] : array(),
			'confidence'   => isset( $metadata['confidence'] ) ? (float) $metadata['confidence'] : 1.0,
			'original'     => is_array( $metadata['original'] ?? null ) ? $metadata['original'] : array(),
			'kg'           => array( 'source_id' => (int) ( $row['kg_source_id'] ?? 0 ), 'notebook_ids' => array_values( array_filter( array( (int) ( $row['notebook_id'] ?? 0 ) ) ) ) ),
			'derived_from' => is_array( $metadata['derived_from'] ?? null ) ? $metadata['derived_from'] : array(),
			'flags'        => is_array( $metadata['flags'] ?? null ) ? $metadata['flags'] : array(),
			'by'           => is_array( $metadata['by'] ?? null ) ? $metadata['by'] : array(),
			'rev'          => (int) ( $row['revision'] ?? 1 ),
			'created_at'   => (string) ( $row['created_at'] ?? '' ),
			'updated_at'   => (string) ( $row['updated_at'] ?? '' ),
			'owner_user_id'=> (int) ( $row['owner_user_id'] ?? 0 ),
		);
	}

	// ── journal-entry@1.1.0 normalizers (pure) ───────────────────────────

	/** One of STATES, else $default. */
	public static function sanitize_state( string $state, string $default = 'captured' ): string {
		$state = strtolower( trim( $state ) );
		return in_array( $state, self::STATES, true ) ? $state : $default;
	}

	/** Closed set (R-JN-19): a known value is kept; anything else ⇒ `plugin`. */
	public static function sanitize_source_type( string $type ): string {
		$type = sanitize_key( $type );
		return in_array( $type, self::SOURCE_TYPES, true ) ? $type : 'plugin';
	}

	/** `original{attachment_id, doc_code, file_name, mime, size, sha256}` — doc_code derived from attachment_id when absent. */
	public static function normalize_original( array $o ): array {
		$id  = (int) ( $o['attachment_id'] ?? $o['file_id'] ?? 0 );
		$sha = strtolower( (string) ( $o['sha256'] ?? '' ) );
		$out = array(
			'attachment_id' => $id,
			'doc_code'      => '' !== (string) ( $o['doc_code'] ?? '' ) ? (string) $o['doc_code'] : ( $id > 0 ? 'TL-' . $id : '' ),
			'file_name'     => sanitize_text_field( (string) ( $o['file_name'] ?? '' ) ),
			'mime'          => sanitize_text_field( (string) ( $o['mime'] ?? '' ) ),
			'size'          => (int) ( $o['size'] ?? $o['bytes'] ?? 0 ),
			'sha256'        => 1 === preg_match( '/^[0-9a-f]{64}$/', $sha ) ? $sha : '',
		);
		foreach ( $o as $k => $v ) { // keep extra scalar keys older writers set; never drop data
			if ( ! array_key_exists( $k, $out ) && is_scalar( $v ) ) {
				$out[ $k ] = $v;
			}
		}
		return $out;
	}

	/** `origin{surface, ref}`; surface derived from source_type when the caller gave none. */
	public static function normalize_origin( array $origin, string $source_type ): array {
		$map     = array( 'owner_capture' => 'zalo_1_1', 'web_upload' => 'web_upload', 'context_producer' => 'plugin', 'derived' => 'cell', 'track' => 'cell', 'job' => 'job', 'automation_run' => 'automation', 'cell_output' => 'cell', 'crm' => 'crm' );
		$surface = sanitize_text_field( (string) ( $origin['surface'] ?? '' ) );
		if ( '' === $surface ) {
			$surface = $map[ sanitize_key( $source_type ) ] ?? 'plugin';
		}
		return array( 'surface' => substr( $surface, 0, 64 ), 'ref' => substr( sanitize_text_field( (string) ( $origin['ref'] ?? '' ) ), 0, 191 ) );
	}

	/** `learning{level, allowed, text_ready, passages, embedded_at, graph_state, entities}` (R-JN-20); `allowed` = the kind's `learn`. */
	public static function normalize_learning( array $l, string $kind ): array {
		$level = strtoupper( (string) ( $l['level'] ?? '' ) );
		if ( ! in_array( $level, self::LEARN_LEVELS, true ) ) {
			$level = 'L0';
		}
		$graph = strtolower( (string) ( $l['graph_state'] ?? 'none' ) );
		return array(
			'level'       => $level,
			'allowed'     => class_exists( 'BizCity_Journal_Kinds' ) ? BizCity_Journal_Kinds::learn( $kind ) : 'vector',
			'text_ready'  => ! empty( $l['text_ready'] ),
			'passages'    => (int) ( $l['passages'] ?? 0 ),
			'embedded_at' => isset( $l['embedded_at'] ) && '' !== (string) $l['embedded_at'] ? (string) $l['embedded_at'] : null,
			'graph_state' => in_array( $graph, array( 'none', 'pending', 'done', 'skipped' ), true ) ? $graph : 'none',
			'entities'    => (int) ( $l['entities'] ?? 0 ),
		);
	}

	/** 60 s debounce invalidation of the owner's `doc_index` pack (same pattern every pack kind uses). */
	private static function flush_doc_index( int $owner_user_id ): void {
		if ( function_exists( 'do_action' ) ) {
			do_action( 'bizcity_twin_agent_packs_changed', array( 'doc_index' ) );
		}
		if ( class_exists( 'BizCity_Journal_Doc_Index_Pack_Exporter' ) ) {
			BizCity_Journal_Doc_Index_Pack_Exporter::flush( $owner_user_id );
		}
	}
}
