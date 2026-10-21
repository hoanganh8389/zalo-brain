<?php
/**
 * BizCity_Journal_Output_Listener — what the machine MAKES for the owner gets one journal row (R-JOURNAL v1.2 R-JN-19,
 * PHASE-0.121 B2-4 / B2-5). Copyright (c) 2026 Chu Hoàng Anh (Johnny Chu), registered No. 8877/2026/QTG. See NOTICE.md.
 *
 * Machine ledgers stay where they are (Job steps, token ledger, run logs, event stream). Only the RESULT the owner may want
 * to find, count or send again becomes exactly one row:
 *   - B2-4 a cell output (image / audio / video / document the cell generated) — hook `bizcity_cell_output_created`
 *          (core/twin-core/event-stream/class-twin-agent-turn-complete-rest.php), key `out:<blog>:<output_id>`, kind
 *          `media.<kind>.output`; when the site later pulls the file (`bizcity_cell_output_stored`) the row gets the attachment;
 *   - B2-5 an Automation run that produced something (reply text or artifacts) — hook `bizcity_scheduler_event_completed`,
 *          key `run:<blog>:<run_id>`, kind `task.output`. A run with no result is machine history only: no row.
 *
 * @package Bizcity_Twin_AI
 * @since   2026-10-17
 */

// [2026-10-17 09:05 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.121 B2-4 / B2-5 (S121-K4, S121-K1 site) — new file.

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Journal_Output_Listener', false ) ) {
	return;
}

final class BizCity_Journal_Output_Listener {

	private static $hooked = false;

	public static function register(): void {
		if ( self::$hooked || ! function_exists( 'add_action' ) ) {
			return;
		}
		self::$hooked = true;
		add_action( 'bizcity_cell_output_created', array( __CLASS__, 'on_cell_output' ), 20, 1 );
		add_action( 'bizcity_cell_output_stored', array( __CLASS__, 'on_cell_output_stored' ), 20, 2 );
		add_action( 'bizcity_scheduler_event_completed', array( __CLASS__, 'on_event_completed' ), 30, 2 );
	}

	// ── pure ─────────────────────────────────────────────────────────────

	/** cell-output@1 kind ⇒ journal kind. */
	public static function kind_for_output( string $kind ): string {
		$kind = strtolower( $kind );
		if ( in_array( $kind, array( 'image', 'audio', 'video' ), true ) ) {
			return 'media.' . $kind . '.output';
		}
		if ( 'voice' === $kind ) {
			return 'media.audio.output';
		}
		return 'media.document.output';
	}

	/**
	 * journal-entry@1.2 input for one cell output, or null when the payload is not usable. Pure.
	 *
	 * @param array $job {event_uuid, user_id, account_id, session_id, trace_id, output{output_id, kind, name, mime, bytes, media_id}}
	 */
	public static function entry_for_output( int $blog_id, array $job ): ?array {
		$o   = is_array( $job['output'] ?? null ) ? $job['output'] : array();
		$oid = (string) ( $o['output_id'] ?? '' );
		$uid = (int) ( $job['user_id'] ?? 0 );
		if ( $uid <= 0 || ! preg_match( '/^co_[a-f0-9]{24}$/', $oid ) ) {
			return null;
		}
		$name  = trim( (string) ( $o['name'] ?? '' ) );
		$entry = array(
			'owner_user_id'   => $uid,
			'kind'            => self::kind_for_output( (string) ( $o['kind'] ?? '' ) ),
			'title'           => '' !== $name ? mb_substr( $name, 0, 255 ) : 'Sản phẩm của trợ lý',
			'idempotency_key' => 'out:' . max( 0, $blog_id ) . ':' . $oid,
			'source_type'     => 'cell_output',
			'source_ref'      => $oid,
			'origin'          => array( 'surface' => 'cell', 'ref' => (string) ( $job['trace_id'] ?? '' ) ),
			'original'        => array( 'file_name' => $name, 'mime' => (string) ( $o['mime'] ?? '' ), 'size' => (int) ( $o['bytes'] ?? 0 ), 'doc_code' => '' ),
			'card'            => array( 'session' => (string) ( $job['session_id'] ?? '' ) ),
			'learning'        => array( 'level' => 'L0' ),
			'by'              => array( 'role' => 'system' ),
			'confidence'      => 1.0,
		);
		// [2026-10-20 11:33 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.126 R106-04 / R110-01 / R111b-01 (job-link@1) — a file a Job made belongs to that Job (role output); the listener attaches it after the add
		$jid = (string) ( $job['job_id'] ?? '' );
		if ( 1 !== preg_match( '/^job_[0-9A-Za-z]{10,40}$/', $jid ) && 1 === preg_match( '/^job_[0-9A-Za-z]{10,40}$/', (string) ( $job['trace_id'] ?? '' ) ) ) {
			$jid = (string) $job['trace_id'];
		}
		if ( 1 === preg_match( '/^job_[0-9A-Za-z]{10,40}$/', $jid ) ) {
			$entry['job'] = array( 'job_id' => $jid, 'role' => 'output' );
		} elseif ( 1 === preg_match( '/^pv_([0-9a-z]{8,38})$/', (string) ( $job['job_id'] ?? '' ), $pv ) ) {
			// [2026-10-20 11:52 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.126 doc 90 R110-01 / R111b-01 — a photo→video job of the cell (`pv_…`, no job-event): a one-shot Job closed when its video arrives.
			// A single picture (`img_…`) is a one-turn answer, never a Job (BS-5).
			$entry['job'] = array( 'job_id' => 'job_pv' . $pv[1], 'role' => 'output', 'status' => 'done', 'goal' => 'Dựng video từ ảnh' . ( '' !== $name ? ': ' . mb_substr( $name, 0, 80 ) : '' ) );
		}
		// [2026-10-18 05:17 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.120 doc 82 §13 (R-FILE-VERSION FV-7) — mỗi phiên bản là MỘT dòng sổ có ghi chú: summary = đã sửa gì,
		// card = họ + số bản + bản cha, tag `fv:<họ>` để web nhóm các bản của cùng một tài liệu và xem theo phiên bản. Dòng sổ không bao giờ bị xoá theo hạn của cell.
		$ver = is_array( $o['version'] ?? null ) ? $o['version'] : null;
		if ( null !== $ver && preg_match( '/^fv_[a-f0-9]{12}$/', (string) ( $ver['family'] ?? '' ) ) && (int) ( $ver['v'] ?? 0 ) >= 1 ) {
			$entry['summary']                 = mb_substr( (string) ( $ver['note'] ?? '' ), 0, 600 );
			$entry['card']['version_family']  = (string) $ver['family'];
			$entry['card']['version']         = (int) $ver['v'];
			$entry['card']['version_parent']  = isset( $ver['parent_v'] ) ? (int) $ver['parent_v'] : 0;
			$entry['card']['version_at']      = (string) ( $ver['at'] ?? '' );
			$entry['tags']                    = array( 'fv:' . (string) $ver['family'], 'v' . (int) $ver['v'] );
			// [2026-10-20 11:33 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.126 (journal-entry@1.2 version, R-FILE-VERSION) — the version block the BrainLedger and journal/versions read
			$entry['version'] = array( 'n' => (int) $ver['v'] );
			// [2026-10-19 11:43 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.124 W4 (R-FILE-VERSION FV-12) — bản thuộc một nhóm Zalo ⇒ tag grp-<gid> (tab "Sản phẩm" của Sổ nhóm) + khối nhóm
			$grp = is_array( $ver['group'] ?? null ) ? $ver['group'] : null;
			if ( null !== $grp && preg_match( '/^[0-9]{3,30}$/', (string) ( $grp['gid'] ?? '' ) ) ) {
				$entry['tags'][]          = 'grp-' . (string) $grp['gid'];
				$entry['group']           = array( 'gid' => (string) $grp['gid'], 'ref' => (string) ( $grp['ref'] ?? '' ) );
				$entry['origin']['surface'] = 'zalo_group';
			}
		}
		return $entry;
	}

	/**
	 * journal-entry@1.2 input for a finished Automation run row (scheduler run ledger), or null when it produced nothing. Pure.
	 *
	 * @param array $row run ledger row {id, user_id, title, status, event_type, metadata{run_id, reply_text, artifacts[], scenario_ref}}
	 */
	public static function entry_for_run( int $blog_id, array $row ): ?array {
		$meta   = is_array( $row['metadata'] ?? null ) ? $row['metadata'] : array();
		$run_id = (string) ( $meta['run_id'] ?? '' );
		$uid    = (int) ( $row['user_id'] ?? 0 );
		$reply  = trim( (string) ( $meta['reply_text'] ?? '' ) );
		$arts   = is_array( $meta['artifacts'] ?? null ) ? $meta['artifacts'] : array();
		if ( 'done' !== (string) ( $row['status'] ?? '' ) || '' === $run_id || $uid <= 0 || ( '' === $reply && array() === $arts ) ) {
			return null; // machine history only (R-JN-19)
		}
		$title = trim( (string) ( $row['title'] ?? '' ) );
		return array(
			'owner_user_id'   => $uid,
			'kind'            => 'task.output',
			'title'           => '' !== $title ? mb_substr( $title, 0, 255 ) : 'Kết quả kịch bản',
			'summary'         => mb_substr( $reply, 0, 600 ),
			'idempotency_key' => 'run:' . max( 0, $blog_id ) . ':' . substr( preg_replace( '/[^A-Za-z0-9_.-]/', '', $run_id ), 0, 80 ),
			'source_type'     => 'automation_run',
			'source_ref'      => 'crm_event:' . (int) ( $row['id'] ?? 0 ),
			'origin'          => array( 'surface' => 'automation', 'ref' => $run_id ),
			'card'            => array( 'scenario' => (string) ( $meta['scenario_ref'] ?? '' ), 'artifacts' => count( $arts ) ),
			'learning'        => array( 'level' => 'L0' ),
			'by'              => array( 'role' => 'system' ),
			'confidence'      => 1.0,
		);
	}

	// ── hooks ────────────────────────────────────────────────────────────

	public static function on_cell_output( $job ): void {
		if ( ! is_array( $job ) || ! class_exists( 'BizCity_Journal_Index' ) ) {
			return;
		}
		$entry = self::entry_for_output( self::blog(), $job );
		if ( null !== $entry ) {
			$row = BizCity_Journal_Index::add( $entry );
			// [2026-10-20 11:33 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.126 R110-01 — under its JB- header (tag jb-, output_refs on the header)
			if ( is_array( $row ) && is_array( $entry['job'] ?? null ) && class_exists( 'BizCity_Journal_Job_Link' ) ) {
				BizCity_Journal_Job_Link::attach( $row, (int) $entry['owner_user_id'], $entry['job'] );
			}
		}
	}

	/** The site pulled the file: the row now points at the attachment (code `OUT-<id>`, not a private TL- document). */
	public static function on_cell_output_stored( $job, $attachment_id ): void {
		$oid = is_array( $job ) ? (string) ( $job['output']['output_id'] ?? '' ) : '';
		if ( '' === $oid || (int) $attachment_id <= 0 || ! class_exists( 'BizCity_Journal_Index' ) ) {
			return;
		}
		// [2026-10-18 05:17 PM Johnny Chu - Chu Hoàng Anh] doc 82 §13 FV-9 — phiên bản tài liệu nằm ở kho riêng tư ⇒ mã TL-… (đọc/gửi lại như tệp chủ gửi); còn lại OUT-…
		$code = class_exists( 'BizCity_KG_Private_File_Store' ) && BizCity_KG_Private_File_Store::is_private( (int) $attachment_id ) ? BizCity_KG_Private_File_Store::doc_code( (int) $attachment_id ) : 'OUT-' . (int) $attachment_id;
		BizCity_Journal_Index::file_by_idempotency_key( 'out:' . self::blog() . ':' . $oid, 0, array( 'original' => array( 'attachment_id' => (int) $attachment_id, 'doc_code' => $code ) ) );
	}

	public static function on_event_completed( $id, $event = null ): void {
		if ( ! class_exists( 'BizCity_Scheduler_Run_Ledger' ) || ! class_exists( 'BizCity_Journal_Index' ) ) {
			return;
		}
		$row = BizCity_Scheduler_Run_Ledger::get( (int) $id );
		if ( ! is_array( $row ) ) {
			return;
		}
		$entry = self::entry_for_run( self::blog(), $row );
		if ( null !== $entry ) {
			BizCity_Journal_Index::add( $entry );
		}
	}

	private static function blog(): int {
		return function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
	}
}
