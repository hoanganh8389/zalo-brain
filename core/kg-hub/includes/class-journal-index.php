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

	// [2026-10-20 10:53 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.126 S126-B1 (doc 20 §1, R-BRAIN-SKELETON) — 1.2.0: layer · tier · audience/access · belongs · version · job · ref_code (all additive).
	const CONTRACT     = 'journal-entry@1.2.0';
	const LAYERS       = array( 'document', 'notebook', 'twin_sheet', 'skill', 'job', 'output' );
	const STATES       = array( 'captured', 'review', 'verified', 'failed', 'void' );
	/** closed `source_type` set (R-JN-19); anything else ⇒ `plugin` */
	// [2026-10-19 10:05 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.124 W2 (R-JN-27) — + group_capture (tệp nhóm Zalo lưu theo lệnh / khi theo dõi), group_brief (sổ ghi chép nhóm, W3)
	const SOURCE_TYPES = array( 'owner_capture', 'web_upload', 'context_producer', 'derived', 'track', 'job', 'automation_run', 'cell_output', 'crm', 'plugin', 'file', 'menu', 'group_capture', 'group_brief' );
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
		// [2026-10-21 11:30 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.123 W3 — seam: a module that closes a period (Twin Sheet "Khóa kỳ") may refuse a new row. Return a WP_Error to refuse; null / anything else lets it through.
		$guard = apply_filters( 'bizcity_journal_pre_add', null, $owner, $kind, (string) ( $entry['doc_date'] ?? '' ), (string) ( $entry['idempotency_key'] ?? '' ), $entry ); // [2026-10-22 02:20 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.123 W7 (B2) — + the entry (6th, additive): a sheet a person made checks the types of the card the assistant sends // [2026-10-21 07:10 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.123 W4 — + idempotency key: a retry of a row that already exists must not be refused
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}
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
		// [2026-10-19 10:05 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.124 W2 — tài liệu thuộc nhóm nào (cộng thêm): khoá truy vấn là tag `grp-<gid>` (encode_tags bỏ dấu ":") (cột tags), khối này chỉ để hiển thị.
		if ( is_array( $entry['group'] ?? null ) ) {
			$g = $entry['group'];
			$metadata['group'] = array(
				'ref'          => substr( sanitize_text_field( (string) ( $g['ref'] ?? '' ) ), 0, 40 ),
				'gid'          => preg_match( '/^[0-9]{3,30}$/', (string) ( $g['gid'] ?? '' ) ) ? (string) $g['gid'] : '',
				'name'         => substr( sanitize_text_field( (string) ( $g['name'] ?? '' ) ), 0, 120 ),
				'sender_label' => substr( sanitize_text_field( (string) ( $g['sender_label'] ?? '' ) ), 0, 60 ),
				'sender_role'  => in_array( (string) ( $g['sender_role'] ?? '' ), array( 'owner', 'staff', 'member', 'bot' ), true ) ? (string) $g['sender_role'] : 'member',
				'msg_ref'      => substr( sanitize_text_field( (string) ( $g['msg_ref'] ?? '' ) ), 0, 40 ),
				'trigger'      => 'follow' === (string) ( $g['trigger'] ?? '' ) ? 'follow' : 'command',
			);
		}
		if ( 'failed' === $state ) {
			$metadata['error'] = array( 'code' => sanitize_key( (string) ( $entry['error']['code'] ?? 'store_failed' ) ), 'message' => sanitize_text_field( (string) ( $entry['error']['message'] ?? '' ) ) );
		}
		// [2026-10-20 10:53 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.126 S126-B1/J1/P1 — 1.2.0 blocks: access (customer switch forced off on a sensitive kind — R-JN-11), version (R-FILE-VERSION), job (job-link@1).
		$metadata['access'] = self::normalize_access( is_array( $entry['access'] ?? null ) ? $entry['access'] : array(), '' !== $sensitive );
		if ( is_array( $entry['version'] ?? null ) ) {
			$metadata['version'] = self::normalize_version( $entry['version'] );
		}
		if ( is_array( $entry['job'] ?? null ) && class_exists( 'BizCity_Journal_Job_Link' ) ) {
			$metadata['job'] = BizCity_Journal_Job_Link::normalize( $entry['job'] );
		}
		// [2026-10-24 05:33 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.133 (doc 20 §4) — the 0.133 blocks, all inside metadata (no column): entry-meta@1 (meta), proposed values (candidates),
		// an evidence envelope (evidence; job.evidence rows only). A file row with no meta yet is `pending` — the cell reads it right after (EM-3).
		if ( class_exists( 'BizCity_Journal_Entry_Meta' ) ) {
			if ( is_array( $entry['meta'] ?? null ) ) {
				$metadata['meta'] = BizCity_Journal_Entry_Meta::merge( array(), BizCity_Journal_Entry_Meta::normalize( $entry['meta'] )['meta'], (string) ( $entry['meta_source'] ?? 'site_code' ) );
			} elseif ( (int) ( $original['attachment_id'] ?? 0 ) > 0 && 'failed' !== $state ) {
				$metadata['meta'] = array( 'contract' => BizCity_Journal_Entry_Meta::CONTRACT, 'status' => 'pending', 'rev' => 0, 'locked' => array() );
			}
			if ( is_array( $entry['candidates'] ?? null ) ) {
				$metadata['candidates'] = BizCity_Journal_Entry_Meta::normalize_candidates( $entry['candidates'] );
			}
		}
		if ( is_array( $entry['evidence'] ?? null ) && 'job.evidence' === $kind ) {
			$metadata['evidence'] = $entry['evidence']; // already normalized by BizCity_Journal_Evidence::record() (the one writer)
		}
		if ( is_array( $entry['resource'] ?? null ) && class_exists( 'BizCity_Brain_Resource_Link' ) ) {
			$metadata['resource'] = $entry['resource']; // caller part (links / knowledge); the site part is computed after the insert
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
		if ( null === $existing ) {
			$row = self::derive( $row ); // PHASE-0.133: resource (identity) + find_text, computed once the row has its code
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
		// [2026-10-21 11:30 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.123 W3 — same seam for a state change (void / re-open) of an existing row.
		$guard = apply_filters( 'bizcity_journal_pre_state', null, $owner_user_id, (string) ( $row['kind'] ?? '' ), (string) ( $row['doc_date'] ?? '' ), $state );
		if ( is_wp_error( $guard ) ) {
			return $guard;
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
			// [2026-10-19 10:40 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.121 R-CONTACT-ID — which Zalo group (name) / CRM contact (id, a number — no name, no phone) the document belongs to.
			'group'       => (string) ( $entry['group']['name'] ?? '' ),
			'contact_id'  => (int) ( $entry['contact_id'] ?? 0 ),
			'source_ref'  => (string) ( $orig['doc_code'] ?? '' ) !== '' ? (string) $orig['doc_code'] : 'JE-' . (int) ( $entry['entry_id'] ?? 0 ),
		);
	}

	/** The journal subject of one CRM contact (same builder every caller shares, R-JN-7). */
	public static function contact_subject_hash( int $contact_id ): string {
		return $contact_id > 0 ? BizCity_Journal_Database::subject_hash( 'contact', (string) $contact_id ) : '';
	}

	/** `grp-<gid>` — the journal tag of a Zalo group (PHASE-0.124; encode_tags drops ":" so the prefix uses a hyphen). '' when gid is not a Zalo group id. */
	public static function group_tag( string $gid ): string {
		return 1 === preg_match( '/^[0-9]{3,30}$/', $gid ) ? 'grp-' . $gid : '';
	}

	/**
	 * Can this WP user attach documents to / read documents of this CRM contact? Exists, and the contact sits in an inbox the user may view
	 * (tenant admin ⇒ all). Never trusts an id from the client (R-CID-11).
	 */
	public static function contact_visible( int $contact_id, int $user_id ): bool {
		if ( $contact_id <= 0 || $user_id <= 0 || ! class_exists( 'BizCity_CRM_Repository' ) || ! class_exists( 'BizCity_CRM_Inbox_Access' ) ) {
			return false;
		}
		if ( ! is_array( BizCity_CRM_Repository::get_contact( $contact_id ) ) ) {
			return false;
		}
		$allowed = BizCity_CRM_Inbox_Access::allowed_inbox_ids( $user_id );
		if ( null === $allowed ) {
			return true;
		}
		if ( array() === $allowed ) {
			return false;
		}
		global $wpdb;
		$tbl = BizCity_CRM_DB_Installer_V2::tbl_contact_inboxes();
		$in  = implode( ',', array_map( 'intval', $allowed ) );
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tbl} WHERE contact_id = %d AND inbox_id IN ({$in})", $contact_id ) ) > 0; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- ints only
	}

	/** @var array|null */
	private static $register_sheet = null;

	/**
	 * [2026-10-19 09:18 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.121 S121-R4 + PHASE-0.123 D123-14 — `doc-register@1.0.0` (twin-sheet@1 shape):
	 * core/kg-hub/contracts/DOC-REGISTER-v1.json, read once per request. Columns, labels, views and projections live there only.
	 */
	public static function register_sheet(): array {
		if ( null === self::$register_sheet ) {
			$raw = (string) file_get_contents( dirname( __DIR__ ) . '/contracts/DOC-REGISTER-v1.json' );
			$doc = json_decode( $raw, true );
			self::$register_sheet = is_array( $doc ) ? $doc : array();
		}
		return self::$register_sheet;
	}

	/** Column keys of one projection (`csv` · `row`) as the contract orders them. */
	public static function register_columns( string $projection ): array {
		$p = self::register_sheet()['projections'][ $projection ] ?? array();
		return array_values( array_map( 'strval', (array) ( $p['columns'] ?? $p['keys'] ?? array() ) ) );
	}

	/** One view of the contract by key (`overview` · `store` · `contracts` · `detail`), or []. */
	public static function register_view( string $key ): array {
		foreach ( (array) ( self::register_sheet()['views'] ?? array() ) as $v ) {
			if ( is_array( $v ) && $key === (string) ( $v['key'] ?? '' ) ) {
				return $v;
			}
		}
		return array();
	}

	/** Card keys a person may edit on the web for `$kind` (views.detail.editable_card). */
	public static function register_editable_card( string $kind ): array {
		$map = (array) ( self::register_view( 'detail' )['editable_card'] ?? array() );
		return array_values( array_map( 'strval', (array) ( $map[ $kind ] ?? array() ) ) );
	}

	/** Fields of one built-in Twin Sheet (core/kg-hub/contracts/TWIN-SHEET-BUILTIN-v1.json, PHASE-0.123 W1), keyed by field key; [] when absent. */
	public static function builtin_sheet_fields( string $sheet_key ): array {
		static $builtin = null;
		if ( null === $builtin ) {
			$path    = dirname( __DIR__ ) . '/contracts/TWIN-SHEET-BUILTIN-v1.json';
			$doc     = is_readable( $path ) ? json_decode( (string) file_get_contents( $path ), true ) : null;
			$builtin = is_array( $doc['sheets'] ?? null ) ? $doc['sheets'] : array();
		}
		$out = array();
		foreach ( (array) ( $builtin[ $sheet_key ]['fields'] ?? array() ) as $f ) {
			if ( is_array( $f ) && '' !== (string) ( $f['key'] ?? '' ) ) {
				$out[ (string) $f['key'] ] = $f;
			}
		}
		return $out;
	}

	/** What the web reads (the shape `twinsheet.describe` returns, PHASE-0.123 doc 20 §4): no projections, no privacy internals. */
	public static function register_describe(): array {
		$keep   = array_flip( array( 'key', 'label', 'type', 'options', 'max', 'list', 'filter', 'sort', 'search', 'editable', 'readonly' ) );
		$s      = self::register_sheet();
		$fields = array();
		foreach ( (array) ( $s['fields'] ?? array() ) as $f ) {
			$fields[] = array_intersect_key( (array) $f, $keep );
		}
		$views = array();
		foreach ( (array) ( $s['views'] ?? array() ) as $v ) {
			if ( ! is_array( $v ) ) {
				continue;
			}
			// card.* columns take label + type from the kind's built-in Twin Sheet; editable = views.detail.editable_card.
			if ( '' !== (string) ( $v['kind'] ?? '' ) ) {
				$builtin  = self::builtin_sheet_fields( (string) $v['kind'] );
				$editable = self::register_editable_card( (string) $v['kind'] );
				$card     = array();
				foreach ( (array) ( $v['columns'] ?? array() ) as $col ) {
					if ( 0 !== strpos( (string) $col, 'card.' ) ) {
						continue;
					}
					$k      = substr( (string) $col, 5 );
					$f      = $builtin[ $k ] ?? array( 'key' => $k, 'label' => $k, 'type' => 'text' );
					$f      = array_intersect_key( $f, $keep );
					$f['source'] = 'card';
					if ( in_array( $k, $editable, true ) ) {
						$f['editable'] = true;
					}
					$card[] = $f;
				}
				$v['card_fields'] = $card;
				unset( $v['card_fields_from'] );
			}
			$views[] = $v;
		}
		return array(
			'contract'       => (string) ( $s['contract'] ?? '' ),
			'conforms_to'    => (string) ( $s['conforms_to'] ?? '' ),
			'sheet_key'      => (string) ( $s['sheet_key'] ?? '' ),
			'display_name'   => (string) ( $s['display_name'] ?? '' ),
			'schema_version' => (int) ( $s['schema_version'] ?? 1 ),
			'edit_policy'    => (string) ( $s['edit_policy'] ?? 'rev' ),
			'fields'         => $fields,
			'views'          => $views,
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
		if ( class_exists( 'BizCity_Journal_Entry_Meta' ) && ( ! empty( $filter['terms'] ) || ! empty( $filter['person'] ) || ! empty( $filter['has'] ) ) ) {
			return self::find_scored( $filter );
		}
		$res = BizCity_Journal_Database::instance()->find_indexed( $filter );
		$res['items'] = array_map( array( __CLASS__, 'to_journal_entry' ), $res['items'] );
		return $res;
	}

	/**
	 * [2026-10-24 05:44 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.133 S133-Ma (journal.find v2, doc 81 §4) — `terms[]` (folded phrases the cell expanded), `match` all|any,
	 * `person{name?, contact_ref?}`, `has[]` (ocr · about · people · keywords): SQL narrows on find_text (≤ 200 rows, newest first), PHP scores each row
	 * (BizCity_Journal_Entry_Meta::score) and pages by score; every item says `why` (which parts matched). Structured filters still apply in SQL.
	 */
	private static function find_scored( array $filter ): array {
		$terms = array();
		foreach ( array_slice( (array) ( $filter['terms'] ?? array() ), 0, 12 ) as $t ) {
			foreach ( BizCity_Journal_Entry_Meta::query_terms( (string) $t, 1 ) as $f ) {
				$terms[] = $f;
			}
		}
		$terms  = array_values( array_unique( $terms ) );
		$match  = 'any' === (string) ( $filter['match'] ?? 'all' ) ? 'any' : 'all';
		$person = is_array( $filter['person'] ?? null ) ? array_intersect_key( $filter['person'], array_flip( array( 'name', 'contact_ref' ) ) ) : array();
		$has    = array_values( array_intersect( array( 'ocr', 'about', 'people', 'keywords' ), array_map( 'strval', (array) ( $filter['has'] ?? array() ) ) ) );
		$sql    = $filter;
		unset( $sql['terms'], $sql['person'], $sql['has'] );
		$sql['terms_sql'] = $terms ?: ( '' !== (string) ( $person['name'] ?? '' ) ? array( BizCity_Journal_Entry_Meta::person_norm( (string) $person['name'] ) ) : array() );
		$sql['match']     = $terms ? $match : 'all';
		$sql['cap']       = true;
		$sql['limit']     = 200;
		$sql['offset']    = 0;
		$raw    = BizCity_Journal_Database::instance()->find_indexed( $sql );
		$scored = array();
		foreach ( $raw['items'] as $i => $row ) {
			$e = self::to_journal_entry( $row );
			if ( $has && ! BizCity_Journal_Entry_Meta::has( $e, $has ) ) {
				continue;
			}
			$s = BizCity_Journal_Entry_Meta::score( $e, $terms, $match, $person );
			if ( null === $s ) {
				continue;
			}
			$e['why']   = $s['why'];
			$e['score'] = $s['score'];
			$scored[]   = array( $s['score'], $i, $e );
		}
		usort( $scored, static function ( $a, $b ) { return $b[0] <=> $a[0] ?: $a[1] <=> $b[1]; } );
		$limit  = max( 1, min( 50, (int) ( $filter['limit'] ?? 20 ) ) );
		$offset = max( 0, (int) ( $filter['offset'] ?? 0 ) );
		return array( 'items' => array_map( static function ( $x ) { return $x[2]; }, array_slice( $scored, $offset, $limit ) ), 'total' => count( $scored ), 'scored' => true );
	}

	public static function get( int $id, int $owner_user_id = 0, bool $all = false ) {
		if ( ! class_exists( 'BizCity_Journal_Database' ) ) {
			return null;
		}
		$row = BizCity_Journal_Database::instance()->get( $id, $owner_user_id, $all );
		return $row ? self::to_journal_entry( $row ) : null;
	}

	/**
	 * [2026-10-21 11:58 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.126 ACI (doc 96) — the code a person reads back ("TL-47", "jb-31", " NB-12 ") ⇒ {prefix, n}; else null. Pure.
	 * The model and the person speak in codes; no tool should make them convert a code to a database id.
	 */
	public static function parse_ref( string $ref ): ?array {
		if ( 1 !== preg_match( '/^\s*([A-Za-z]{2})-(\d{1,12})\s*$/', $ref, $m ) || (int) $m[2] <= 0 ) {
			return null;
		}
		return array( 'prefix' => strtoupper( $m[1] ), 'n' => (int) $m[2] );
	}

	/**
	 * The owner's row behind one code: `TL-<n>` = the file code (source_ref of the row that keeps that original); `JE-<n>` = entry n whatever its kind;
	 * any other prefix (JB · NB · SO · KN · TC · CN · TD · VC · KH · EV) = entry n only when its own code carries that prefix. null = none / not theirs.
	 */
	public static function find_by_ref( string $ref, int $owner_user_id ): ?array {
		$p = self::parse_ref( $ref );
		if ( null === $p || $owner_user_id <= 0 || ! class_exists( 'BizCity_Journal_Database' ) ) {
			return null;
		}
		if ( 'TL' === $p['prefix'] ) {
			$row = BizCity_Journal_Database::instance()->get_by_source_ref( 'TL-' . $p['n'] );
			return $row && (int) $row['owner_user_id'] === $owner_user_id ? self::to_journal_entry( $row ) : null;
		}
		$e = self::get( $p['n'], $owner_user_id );
		if ( ! $e ) {
			return null;
		}
		return ( 'JE' === $p['prefix'] || strtoupper( (string) ( $e['ref_code'] ?? '' ) ) === $p['prefix'] . '-' . $p['n'] ) ? $e : null;
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
			// [2026-10-19 10:40 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.121 R-CONTACT-ID — `grp-<gid>` is the document's Zalo-group link, not a label a person types: editing labels keeps it.
			// [2026-10-20 10:53 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.126 S126-J1 — `jb-<id>` (job link) is kept the same way; both are keys, not labels.
			$is_link = static function ( $t ): bool { return 0 === strpos( (string) $t, 'grp-' ) || 0 === strpos( (string) $t, 'jb-' ); };
			$keep = array_values( array_filter( BizCity_Journal_Database::decode_tags( (string) ( $row['tags'] ?? '' ) ), $is_link ) );
			$new  = array_values( array_filter( array_map( 'strval', (array) $patch['tags'] ), static function ( $t ) use ( $is_link ) { return ! $is_link( $t ) || 0 === strpos( $t, 'jb-' ); } ) );
			$write['tags'] = array_values( array_unique( array_merge( $keep, $new ) ) ); // the links first: a 12-tag cap never drops them
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
		// [2026-10-19 10:40 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.121 R-CONTACT-ID — a document belongs to ONE CRM contact: subject{type:contact} + column subject_hash (indexed)
		// + metadata.contact{id}. `contact_id:0` unlinks. The caller (REST / MCP) has already checked the contact exists and is visible to this person.
		if ( array_key_exists( 'contact_id', $patch ) ) {
			$cid = max( 0, (int) $patch['contact_id'] );
			$hash = $cid > 0 ? self::contact_subject_hash( $cid ) : '';
			$write['subject_hash'] = $hash;
			$metadata['subject']   = $cid > 0 ? array( 'type' => 'contact', 'hash' => $hash ) : array( 'type' => 'none', 'hash' => '' );
			if ( $cid > 0 ) {
				$metadata['contact'] = array( 'id' => $cid );
			} else {
				unset( $metadata['contact'] );
			}
			$changed_meta = true;
		}
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
		// [2026-10-20 10:53 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.126 S126-P3 (brain-access@1 row switch, Q126-16) — `customer` off · view · download is set by a PERSON (REST);
		// a sensitive row refuses it (audience_locked). `delivered_to` adds / removes one customer ref (BS-7: the recipient of a product may fetch it).
		if ( array_key_exists( 'customer', $patch ) || array_key_exists( 'delivered_to', $patch ) || array_key_exists( 'undeliver', $patch ) ) {
			$sensitive = '' !== (string) ( $flags['sensitive'] ?? '' );
			$cur       = is_array( $metadata['access'] ?? null ) ? $metadata['access'] : array();
			if ( array_key_exists( 'customer', $patch ) ) {
				if ( $sensitive && 'off' !== (string) $patch['customer'] ) {
					return new WP_Error( 'audience_locked', 'Dòng này thuộc loại nhạy cảm (tài chính · sức khoẻ · trẻ em · cá nhân): không mở cho khách được.' );
				}
				$cur['customer'] = (string) $patch['customer'];
			}
			$list = array_map( 'strval', (array) ( $cur['delivered_to'] ?? array() ) );
			if ( array_key_exists( 'delivered_to', $patch ) ) {
				$list = array_merge( $list, array_map( 'strval', (array) $patch['delivered_to'] ) );
			}
			if ( array_key_exists( 'undeliver', $patch ) ) {
				$list = array_diff( $list, array_map( 'strval', (array) $patch['undeliver'] ) );
			}
			$cur['delivered_to'] = $list;
			$metadata['access'] = self::normalize_access( $cur, $sensitive );
			$changed_meta = true;
		}
		// R-FILE-VERSION: a person names a checkpoint ("bản gửi khách 20/10"); version numbers come from the writer, never from the web.
		if ( array_key_exists( 'checkpoint_label', $patch ) ) {
			$metadata['version'] = self::normalize_version( array_merge( is_array( $metadata['version'] ?? null ) ? $metadata['version'] : array(), array( 'checkpoint_label' => (string) $patch['checkpoint_label'] ) ) );
			$changed_meta = true;
		} elseif ( array_key_exists( 'version', $patch ) && is_array( $patch['version'] ) ) {
			$metadata['version'] = self::normalize_version( array_merge( is_array( $metadata['version'] ?? null ) ? $metadata['version'] : array(), $patch['version'] ) );
			$changed_meta = true;
		}
		// job-link@1: the job block is merged (a row joins a job once; later calls may add step_ref / version_ref).
		if ( array_key_exists( 'job', $patch ) && is_array( $patch['job'] ) && class_exists( 'BizCity_Journal_Job_Link' ) ) {
			$metadata['job'] = BizCity_Journal_Job_Link::normalize( array_merge( is_array( $metadata['job'] ?? null ) ? $metadata['job'] : array(), $patch['job'] ) );
			$changed_meta = true;
		}
		// [2026-10-24 05:33 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.133 — meta (already merged by BizCity_Journal_Entry_Meta::set), candidates, the caller part of resource,
		// and a representation written by vault.materialize. find_text is rebuilt below whenever anything changed.
		if ( array_key_exists( 'meta', $patch ) && is_array( $patch['meta'] ) ) {
			$metadata['meta'] = $patch['meta'];
			$changed_meta     = true;
		}
		if ( array_key_exists( 'candidates', $patch ) && is_array( $patch['candidates'] ) && class_exists( 'BizCity_Journal_Entry_Meta' ) ) {
			$metadata['candidates'] = BizCity_Journal_Entry_Meta::normalize_candidates( $patch['candidates'] );
			$changed_meta           = true;
		}
		if ( class_exists( 'BizCity_Brain_Resource_Link' ) && ( array_key_exists( 'representation', $patch ) || array_key_exists( 'resource', $patch ) ) ) {
			$res = is_array( $metadata['resource'] ?? null ) ? $metadata['resource'] : array();
			if ( is_array( $patch['resource'] ?? null ) ) {
				$res['links'] = BizCity_Brain_Resource_Link::normalize_links( array_merge( is_array( $res['links'] ?? null ) ? $res['links'] : array(), (array) ( $patch['resource']['links'] ?? array() ) ) );
				if ( is_array( $patch['resource']['knowledge'] ?? null ) ) {
					$res['knowledge'] = BizCity_Brain_Resource_Link::normalize_knowledge( array_merge( is_array( $res['knowledge'] ?? null ) ? $res['knowledge'] : array(), $patch['resource']['knowledge'] ) );
				}
			}
			if ( is_array( $patch['representation'] ?? null ) ) {
				$res = BizCity_Brain_Resource_Link::with_representation( $res, $patch['representation'] );
			}
			$metadata['resource'] = $res;
			$changed_meta         = true;
		}
		if ( $changed_meta ) {
			$write['metadata'] = $metadata;
		}
		// [2026-10-19 09:18 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.121 S121-R6 / PHASE-0.123 doc 20 §6 — a web editor's rev: stale ⇒ journal_revision_conflict, nothing written.
		if ( (int) ( $patch['expected_revision'] ?? 0 ) > 0 ) {
			$write['expected_revision'] = (int) $patch['expected_revision'];
		}
		if ( empty( $write ) ) {
			return self::to_journal_entry( $row );
		}
		$updated = $db->update( $id, $owner_user_id, $write );
		if ( is_wp_error( $updated ) ) {
			return $updated;
		}
		$updated = self::derive( $updated ); // PHASE-0.133: find_text follows every change of the row
		self::flush_doc_index( $owner_user_id );
		return self::to_journal_entry( $updated );
	}

	/**
	 * [2026-10-24 05:33 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.133 S133-V1 / S133-Ma — the fields the SITE derives from a row it just wrote:
	 * `metadata.resource` (brain-resource-link@1: source_ref@v, source_uuid reused along a version chain / same bytes, original locator) for rows that
	 * keep a file or live in the document / output layer, and `find_text` (entry-meta@1 search text). Written without a revision bump. Returns the raw row.
	 */
	public static function derive( array $row ): array {
		if ( ! class_exists( 'BizCity_Journal_Database' ) ) {
			return $row;
		}
		$db       = BizCity_Journal_Database::instance();
		$decoded  = json_decode( (string) ( $row['metadata'] ?? '' ), true );
		$metadata = is_array( $decoded ) ? $decoded : array();
		$entry    = self::to_journal_entry( $row );
		$write    = array();
		if ( class_exists( 'BizCity_Brain_Resource_Link' ) && BizCity_Brain_Resource_Link::applies( (string) $entry['layer'], (array) $entry['original'] ) && 'failed' !== (string) $entry['state'] ) {
			$owner = (int) $row['owner_user_id'];
			$site  = BizCity_Brain_Resource_Link::site_view( $metadata, (string) $entry['ref_code'], static function ( string $by, string $value ) use ( $owner, $db ) {
				if ( 'root' === $by ) {
					$root = self::find_by_ref( $value, $owner );
					return $root ? (string) ( $root['resource']['source_uuid'] ?? '' ) : null;
				}
				return 'sha' === $by && method_exists( $db, 'source_uuid_by_sha256' ) ? $db->source_uuid_by_sha256( $owner, $value ) : null;
			} );
			if ( '' === (string) ( $metadata['resource']['original']['captured_at'] ?? '' ) ) {
				$site['original']['captured_at'] = str_replace( ' ', 'T', (string) ( $row['created_at'] ?? gmdate( 'Y-m-d H:i:s' ) ) ) . 'Z';
			}
			$res = BizCity_Brain_Resource_Link::normalize( is_array( $metadata['resource'] ?? null ) ? $metadata['resource'] : array(), $site );
			if ( (int) ( $row['kg_source_id'] ?? 0 ) > 0 && 0 === (int) $res['knowledge']['kg_source_id'] ) {
				$res['knowledge'] = BizCity_Brain_Resource_Link::normalize_knowledge( array( 'kg_source_id' => (int) $row['kg_source_id'], 'indexed_rev' => (int) ( BizCity_Brain_Resource_Link::latest_representation( $res )['rev'] ?? 0 ), 'status' => 'ready' ) ); // S133-K1: the KG source joins the rev it was built from
			}
			$nb = (int) ( $row['notebook_id'] ?? 0 );
			if ( $nb > 0 && '' === $res['links']['notebook_ref'] ) {
				$res['links']['notebook_ref'] = 'NB-' . $nb;
			}
			$jr = (string) ( $entry['job']['job_ref'] ?? '' );
			if ( '' !== $jr && ! in_array( $jr, $res['links']['job_refs'], true ) ) {
				$res['links']['job_refs'][] = $jr;
			}
			if ( wp_json_encode( $res ) !== wp_json_encode( $metadata['resource'] ?? null ) ) {
				$metadata['resource'] = $res;
				$write['metadata']    = $metadata;
			}
		}
		if ( class_exists( 'BizCity_Journal_Entry_Meta' ) ) {
			$kdef = class_exists( 'BizCity_Journal_Kinds' ) ? (array) ( BizCity_Journal_Kinds::get( (string) $entry['kind'] ) ?? array() ) : array();
			$ft   = BizCity_Journal_Entry_Meta::find_text( $entry, is_array( $metadata['meta'] ?? null ) ? $metadata['meta'] : array(), (array) ( $kdef['card_fields'] ?? array() ) );
			if ( $ft !== (string) ( $row['find_text'] ?? '' ) ) {
				$write['find_text'] = $ft;
			}
		}
		if ( $write && method_exists( $db, 'write_derived' ) ) {
			$db->write_derived( (int) $row['id'], $write );
			if ( isset( $write['metadata'] ) ) {
				$row['metadata'] = wp_json_encode( $write['metadata'], JSON_UNESCAPED_UNICODE );
			}
			if ( isset( $write['find_text'] ) ) {
				$row['find_text'] = $write['find_text'];
			}
		}
		return $row;
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
		$out = array(
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
			'contact_id'   => is_array( $metadata['contact'] ?? null ) ? (int) ( $metadata['contact']['id'] ?? 0 ) : 0,
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
		// [2026-10-19 10:05 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.124 W2 — chỉ dòng thuộc nhóm mới có khoá này (hình dạng dòng cũ không đổi)
		if ( is_array( $metadata['group'] ?? null ) ) {
			$out['group'] = $metadata['group'];
		}
		// [2026-10-20 10:53 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.126 S126-B1/J1/P1 — 1.2.0 (doc 20 §1): layer · tier (documents / notebook only) · audience + access · belongs · version · job · ref_code.
		$layer            = self::layer_of( $out['kind'] );
		$out['layer']     = $layer;
		$out['tier']      = 'brain.notebook' === (string) ( $row['kind'] ?? '' ) ? null : self::tier_of( $layer, $learning ); // [2026-10-21 11:23 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.126 S126-B3 — the notebook's own row is a container: the tier belongs to its sources
		$out['access']    = self::normalize_access( is_array( $metadata['access'] ?? null ) ? $metadata['access'] : array(), '' !== (string) ( $out['flags']['sensitive'] ?? '' ) );
		$out['audience']  = 'off' === $out['access']['customer'] ? 'owner' : 'customer';
		$out['belongs']   = array(
			'notebook_id' => (int) ( $row['notebook_id'] ?? 0 ),
			'group'       => isset( $out['group'] ) ? array( 'gid' => (string) ( $out['group']['gid'] ?? '' ), 'name' => (string) ( $out['group']['name'] ?? '' ) ) : null,
			'contact_id'  => (int) $out['contact_id'],
		);
		$out['version']   = self::normalize_version( is_array( $metadata['version'] ?? null ) ? $metadata['version'] : array() );
		$out['job']       = is_array( $metadata['job'] ?? null ) && class_exists( 'BizCity_Journal_Job_Link' ) ? BizCity_Journal_Job_Link::normalize( $metadata['job'] ) : null;
		$out['ref_code']  = self::ref_code( $out['kind'], (int) $row['id'], $out['original'] );
		// [2026-10-24 05:33 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.133 — the 0.133 blocks, only on rows that have them (older shapes unchanged)
		foreach ( array( 'resource', 'meta', 'candidates', 'evidence' ) as $k ) {
			if ( is_array( $metadata[ $k ] ?? null ) ) {
				$out[ $k ] = $metadata[ $k ];
			}
		}
		return $out;
	}

	// ── journal-entry@1.2.0 derived fields (pure) ─────────────────────────

	// [2026-10-20 11:42 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.126 — method_exists, not class_exists: a partial upload (new index + old kinds file) degrades to the 1.1 shape instead of a fatal.
	/** The layer of a row (doc 05 §3): from journal-kinds@1.3 `layers`; `document` when the kinds class is absent. */
	public static function layer_of( string $kind ): string {
		$l = method_exists( 'BizCity_Journal_Kinds', 'layer_of' ) ? BizCity_Journal_Kinds::layer_of( $kind ) : 'document';
		return in_array( $l, self::LAYERS, true ) ? $l : 'document';
	}

	/** L1 · L2 · L3 for documents / notebook sources (graph done ⇒ L3 · embedded ⇒ L2 · else L1); null for every other layer (doc 05 §4). */
	public static function tier_of( string $layer, array $learning ): ?string {
		$has = method_exists( 'BizCity_Journal_Kinds', 'layer_has_tier' ) ? BizCity_Journal_Kinds::layer_has_tier( $layer ) : in_array( $layer, array( 'document', 'notebook' ), true );
		if ( ! $has ) {
			return null;
		}
		if ( 'done' === (string) ( $learning['graph_state'] ?? '' ) ) {
			return 'L3';
		}
		return '' !== (string) ( $learning['embedded_at'] ?? '' ) ? 'L2' : 'L1';
	}

	/**
	 * The code a person reads and types back (R-JN-25): the file code (TL-…) for a document row with an original; the kind's
	 * prefix (TC · CN · TD · VC · KH · JB · EV · NB · SO · KN) + entry id; else JE-<entry id>. Pure.
	 */
	public static function ref_code( string $kind, int $entry_id, array $original = array() ): string {
		if ( $entry_id <= 0 ) {
			return '';
		}
		$prefix = method_exists( 'BizCity_Journal_Kinds', 'ref_prefix' ) ? BizCity_Journal_Kinds::ref_prefix( $kind ) : '';
		if ( '' !== $prefix ) {
			return $prefix . '-' . $entry_id;
		}
		$code = (string) ( $original['doc_code'] ?? '' );
		return 1 === preg_match( '/^TL-\d+$/', $code ) ? $code : 'JE-' . $entry_id;
	}

	/** `access{customer: off|view|download, delivered_to: ref[]}` — a sensitive row is always `off` (R-JN-11). Pure. */
	public static function normalize_access( array $a, bool $sensitive ): array {
		$sw = strtolower( (string) ( $a['customer'] ?? 'off' ) );
		if ( $sensitive || ! in_array( $sw, array( 'off', 'view', 'download' ), true ) ) {
			$sw = 'off';
		}
		$to = array();
		foreach ( (array) ( $a['delivered_to'] ?? array() ) as $r ) {
			$r = strtolower( trim( (string) $r ) );
			if ( 1 === preg_match( '/^[hp]:[0-9a-f]{16}$/', $r ) && ! in_array( $r, $to, true ) ) {
				$to[] = $r;
			}
			if ( count( $to ) >= 50 ) {
				break;
			}
		}
		return array( 'customer' => $sw, 'delivered_to' => $to );
	}

	/** `version{n, root_ref, checkpoint_label, prev_entry_id}` (R-FILE-VERSION). Pure. */
	public static function normalize_version( array $v ): array {
		$root  = strtoupper( trim( (string) ( $v['root_ref'] ?? '' ) ) );
		$label = trim( strip_tags( (string) ( $v['checkpoint_label'] ?? '' ) ) ); // pure: no WP function (readers run without WordPress)
		return array(
			'n'                => max( 1, (int) ( $v['n'] ?? 1 ) ),
			'root_ref'         => 1 === preg_match( '/^[A-Z]{2}-\d{1,12}$/', $root ) ? $root : '',
			'checkpoint_label' => mb_substr( (string) preg_replace( '/[\r\n\t]+/', ' ', $label ), 0, 40 ),
			'prev_entry_id'    => max( 0, (int) ( $v['prev_entry_id'] ?? 0 ) ),
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
		$map     = array( 'owner_capture' => 'zalo_1_1', 'group_capture' => 'zalo_group', 'group_brief' => 'zalo_group', 'web_upload' => 'web_upload', 'context_producer' => 'plugin', 'derived' => 'cell', 'track' => 'cell', 'job' => 'job', 'automation_run' => 'automation', 'cell_output' => 'cell', 'crm' => 'crm' );
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
