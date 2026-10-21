<?php
/**
 * BizCity_Journal_Job_Link — `job-link@1.0.0` (core/kg-hub/contracts/JOB-LINK-v1.json): one job = one header row `job.run`
 * (`JB-<id>`) in the journal, and every row written inside that job carries `metadata.job{job_id, job_ref, role, …}` plus the
 * query tag `jb-<header id>`. The machine ledger (steps, attempts) stays in the cell + the event stream (R-JN-19); this class
 * only keeps the HUMAN ledger: "what was asked, what came out, which rows belong to it".
 *
 * Writers: (a) `bizcity_twin_job_event` (job-event@1.1 reached the site) ⇒ upsert the header; (b) `journal.record` with a
 * `job` block ⇒ attach the row to its header and append its code to the header's output_refs.
 *
 * Biz Central Brain — Johnny Chu (Chu Hoàng Anh).
 *
 * [2026-10-20 10:53 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.126 S126-J1…J3 (doc 15, R-BRAIN-SKELETON BS-5/BS-6) — new file.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\KG_Hub
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Journal_Job_Link', false ) ) {
	return;
}

final class BizCity_Journal_Job_Link {

	const CONTRACT      = 'job-link@1.0.0';
	const KIND_HEADER   = 'job.run';
	const KIND_EVIDENCE = 'job.evidence';
	const ROLES         = array( 'header', 'input', 'output', 'evidence', 'lesson' );
	const STATUSES      = array( 'pending', 'running', 'reviewing', 'blocked', 'done', 'failed' );
	/** cell job-contract@1 status ⇒ the 6 statuses a person reads (doc 15 §3) */
	const FROM_CELL = array( 'queued' => 'pending', 'running' => 'running', 'retryable_failed' => 'running', 'waiting_approval' => 'reviewing', 'blocked_permission' => 'blocked', 'completed_verified' => 'done', 'completed_partial' => 'done', 'failed' => 'failed', 'cancelled' => 'failed' );
	/** status ⇒ journal state; never verified (R-JN-14) */
	const TO_STATE  = array( 'pending' => 'captured', 'running' => 'captured', 'reviewing' => 'review', 'blocked' => 'review', 'done' => 'captured', 'failed' => 'failed' );
	const MAX_REFS  = 40;

	private static $hooked = false;

	public static function register(): void {
		if ( self::$hooked ) {
			return;
		}
		self::$hooked = true;
		// after BizCity_Sheet_Bindings (10): the header is written once the site has adopted whatever the job created
		add_action( 'bizcity_twin_job_event', array( __CLASS__, 'on_job_event' ), 20, 1 );
		// [2026-10-21 11:32 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.126 R100-09 (BS-5) — installing a brain profile is ONE job (JB-), its skills are that job's outputs
		add_action( 'bizcity_brain_profile_installed', array( __CLASS__, 'on_profile_installed' ), 20, 1 );
	}

	/**
	 * The job a SITE flow is running right now (filter `bizcity_journal_ambient_job`, set by the flow for its own duration — e.g. the profile
	 * installer). Writers that have no job of their own (skill rows, …) join it. null when none. Never trusts a malformed id.
	 */
	public static function ambient(): ?array {
		$j = function_exists( 'apply_filters' ) ? apply_filters( 'bizcity_journal_ambient_job', null ) : null;
		if ( ! is_array( $j ) || ! self::is_job_id( (string) ( $j['job_id'] ?? '' ) ) ) {
			return null;
		}
		$role = in_array( (string) ( $j['role'] ?? '' ), array( 'input', 'output', 'evidence' ), true ) ? (string) $j['role'] : 'output';
		return array( 'job_id' => (string) $j['job_id'], 'role' => $role, 'goal' => (string) ( $j['goal'] ?? '' ) );
	}

	/** A site-side job (no job-event@1): put its final status and facts on the header (opened on first use). @return array|WP_Error the header */
	public static function close( int $owner, string $job_id, string $status, array $card = array(), string $goal = '' ) {
		$status = self::status_from_cell( $status );
		$header = self::header( $owner, $job_id, array( 'status' => $status, 'goal' => $goal, 'actor' => array( 'kind' => 'person' ) ) );
		if ( is_wp_error( $header ) ) {
			return $header;
		}
		$hid = (int) $header['entry_id'];
		BizCity_Journal_Index::file( $hid, $owner, array( 'card' => array( 'status' => $status ) + $card ) );
		$state = self::TO_STATE[ $status ];
		if ( $state !== (string) ( $header['state'] ?? '' ) && 'verified' !== (string) ( $header['state'] ?? '' ) ) {
			BizCity_Journal_Index::set_state( $hid, $owner, $state );
		}
		return BizCity_Journal_Index::get( $hid, $owner ) ?? $header;
	}

	/** Pure: the header facts of one profile install. @param array $info {profile_id, profile_version, name, guru_id, created[], errors[]} */
	public static function profile_job_card( array $info ): array {
		$made = array();
		foreach ( (array) ( $info['created'] ?? array() ) as $c ) {
			$k = sanitize_key( (string) ( $c['kind'] ?? '' ) );
			if ( '' !== $k ) {
				$made[ $k ] = ( $made[ $k ] ?? 0 ) + max( 1, (int) ( $c['count'] ?? 1 ) );
			}
		}
		$errors = count( (array) ( $info['errors'] ?? array() ) );
		return array(
			'status' => $errors ? 'blocked' : 'done',
			'goal'   => mb_substr( 'Cài hồ sơ «' . sanitize_text_field( (string) ( $info['name'] ?? $info['profile_id'] ?? '' ) ) . '» v' . sanitize_text_field( (string) ( $info['profile_version'] ?? '' ) ), 0, 200 ),
			'card'   => array( 'profile' => sanitize_key( (string) ( $info['profile_id'] ?? '' ) ), 'profile_version' => sanitize_text_field( (string) ( $info['profile_version'] ?? '' ) ), 'guru_id' => (int) ( $info['guru_id'] ?? 0 ), 'made' => $made, 'errors' => $errors ),
		);
	}

	public static function on_profile_installed( $info ): void {
		if ( ! is_array( $info ) || ! self::is_job_id( (string) ( $info['job_id'] ?? '' ) ) || ! class_exists( 'BizCity_Journal_Index' ) ) {
			return;
		}
		// the same person the skill rows go to (BizCity_Journal_Skill_Link::owner): the site owner, else the person who ran the install — one header, never two
		$owner = class_exists( 'BizCity_Twin_Web_Home' ) ? (int) BizCity_Twin_Web_Home::owner_user_id() : 0;
		if ( $owner <= 0 ) {
			$owner = (int) ( $info['owner'] ?? 0 );
		}
		if ( $owner <= 0 ) {
			return;
		}
		$p = self::profile_job_card( $info );
		self::close( $owner, (string) $info['job_id'], $p['status'], $p['card'], $p['goal'] );
	}

	/* ── pure helpers ─────────────────────────────────────────────────── */

	public static function is_job_id( string $s ): bool {
		return 1 === preg_match( '/^job_[0-9A-Za-z]{10,40}$/', $s );
	}

	public static function job_ref( int $header_id ): string {
		return $header_id > 0 ? 'JB-' . $header_id : '';
	}

	/** "JB-31" ⇒ 31; anything else ⇒ 0 */
	public static function parse_job_ref( string $ref ): int {
		return 1 === preg_match( '/^JB-([0-9]{1,12})$/', strtoupper( trim( $ref ) ), $m ) ? (int) $m[1] : 0;
	}

	public static function tag( int $header_id ): string {
		return $header_id > 0 ? 'jb-' . $header_id : '';
	}

	/** the header's idempotency key — its own prefix, so a cell record keyed `job:<id>` can never collide with it */
	public static function header_key( string $job_id, int $blog_id = 0 ): string {
		if ( ! self::is_job_id( $job_id ) ) {
			return '';
		}
		$blog = $blog_id > 0 ? $blog_id : ( function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0 );
		return 'jb:' . $blog . ':' . $job_id;
	}

	public static function status_from_cell( string $cell_status ): string {
		$s = sanitize_key( $cell_status );
		if ( in_array( $s, self::STATUSES, true ) ) {
			return $s;
		}
		return self::FROM_CELL[ $s ] ?? 'running';
	}

	/**
	 * `metadata.job` as the contract shapes it. Unknown / malformed keys are dropped, never stored. Pure.
	 *
	 * @param array $job {job_id?, job_ref?, role?, step_ref?, version_ref?, actor?{kind,ref,executor}, parent_job_ref?, conversation_ref?}
	 */
	public static function normalize( array $job ): array {
		$out   = array();
		$jid   = (string) ( $job['job_id'] ?? '' );
		if ( self::is_job_id( $jid ) ) {
			$out['job_id'] = $jid;
		}
		$ref = self::parse_job_ref( (string) ( $job['job_ref'] ?? '' ) );
		if ( $ref > 0 ) {
			$out['job_ref'] = self::job_ref( $ref );
		}
		$role = strtolower( (string) ( $job['role'] ?? '' ) );
		$out['role'] = in_array( $role, self::ROLES, true ) ? $role : 'output';
		$step = (string) ( $job['step_ref'] ?? '' );
		$out['step_ref'] = 1 === preg_match( '/^[a-z][a-z0-9_]{0,39}$/', $step ) ? $step : null;
		$ver = trim( (string) ( $job['version_ref'] ?? '' ) );
		$out['version_ref'] = 1 === preg_match( '/^([A-Za-z]{2}-\d{1,12})@([VvSs])(\d{1,6})$/', $ver, $vm ) ? strtoupper( $vm[1] ) . '@' . strtolower( $vm[2] ) . $vm[3] : null;
		$actor = is_array( $job['actor'] ?? null ) ? $job['actor'] : array();
		$kind  = sanitize_key( (string) ( $actor['kind'] ?? '' ) );
		$out['actor'] = array(
			'kind'     => in_array( $kind, array( 'owner', 'staff', 'group_member', 'customer', 'cell', 'executor', 'automation' ), true ) ? $kind : 'cell',
			'ref'      => substr( (string) preg_replace( '/[^A-Za-z0-9_:.-]/', '', (string) ( $actor['ref'] ?? '' ) ), 0, 80 ),
			'executor' => substr( (string) preg_replace( '/[^A-Za-z0-9_.-]/', '', (string) ( $actor['executor'] ?? '' ) ), 0, 80 ),
		);
		$parent = self::parse_job_ref( (string) ( $job['parent_job_ref'] ?? '' ) );
		$out['parent_job_ref']   = $parent > 0 ? self::job_ref( $parent ) : null;
		$conv                    = sanitize_text_field( (string) ( $job['conversation_ref'] ?? '' ) );
		$out['conversation_ref'] = '' !== $conv ? substr( $conv, 0, 120 ) : null;
		return $out;
	}

	/**
	 * Q126-17 / BS-5 — does this event justify a header row? A one-turn answer never does. Pure.
	 * `approval_requested` = approval; `progressed` = a step event (only multi-step jobs emit one); `completed` = ≥ 2 steps, or it
	 * wrote ≥ 1 journal row (ledger.rows), or it produced artifacts / evidence; `requested` alone = no.
	 */
	public static function should_open( string $kind, string $status, ?array $result ): bool {
		if ( 'approval_requested' === $kind || 'progressed' === $kind || 'owner_asked' === $kind || 'owner_answered' === $kind ) {
			return true; // job-event@1.2.0: a job that waits on the owner is a Job (BS-5 approval)
		}
		if ( 'completed' !== $kind ) {
			return false;
		}
		$r = is_array( $result ) ? $result : array();
		return count( (array) ( $r['steps'] ?? array() ) ) >= 2
			|| (int) ( $r['ledger']['rows'] ?? 0 ) >= 1
			|| ! empty( $r['artifacts'] )
			|| ! empty( $r['evidence'] );
	}

	/**
	 * Codes a person can read from a job-result@1 snapshot (artifacts + evidence): `TL-47`, `CN-12`, `journal:<id>` ⇒ the row's code.
	 * Never a URL, never a hash. Pure except the optional `$code_of( entry_id )` resolver.
	 *
	 * @return array{output_refs:string[],evidence_refs:string[]}
	 */
	public static function refs_from_result( ?array $result, ?callable $code_of = null ): array {
		$r    = is_array( $result ) ? $result : array();
		$outs = array();
		foreach ( (array) ( $r['artifacts'] ?? array() ) as $a ) {
			$ref = (string) ( is_array( $a ) ? ( $a['ref'] ?? '' ) : $a );
			if ( 1 === preg_match( '/^[A-Z]{2}-\d{1,12}$/', $ref ) ) {
				$outs[] = $ref;
			} elseif ( 1 === preg_match( '/^journal:(\d{1,12})$/', $ref, $m ) && $code_of ) {
				$c = (string) $code_of( (int) $m[1] );
				if ( '' !== $c ) {
					$outs[] = $c;
				}
			}
		}
		$evs = array();
		foreach ( (array) ( $r['evidence'] ?? array() ) as $e ) {
			$id = (string) ( is_array( $e ) ? ( $e['id'] ?? '' ) : '' );
			if ( 1 === preg_match( '/^evi_[0-9A-Za-z_]{1,40}$/', $id ) ) {
				$evs[] = $id;
			}
		}
		return array(
			'output_refs'   => array_slice( array_values( array_unique( $outs ) ), 0, self::MAX_REFS ),
			'evidence_refs' => array_slice( array_values( array_unique( $evs ) ), 0, self::MAX_REFS ),
		);
	}

	/**
	 * [2026-10-20 11:52 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.125 Q1.7 (job-event@1.2.0, doc 30 §8) — `card.waiting_on` of the JB- header. Pure.
	 * owner_asked + gate ⇒ {ask_id, kind, question, options, expires_at}; owner_answered for the SAME ask ⇒ null (cleared); anything else ⇒ no change ([]).
	 * The answer itself is a `job.decision` row the CELL writes (journal.record, key job:<job>.<ask_id>) — never derived here (one writer per row).
	 */
	public static function waiting_on_patch( string $kind, ?array $gate, ?array $current ): array {
		if ( null === $gate || '' === (string) ( $gate['ask_id'] ?? '' ) ) {
			return array();
		}
		if ( 'owner_asked' === $kind ) {
			return array( 'waiting_on' => array(
				'ask_id'     => (string) $gate['ask_id'],
				'kind'       => (string) ( $gate['kind'] ?? '' ),
				'question'   => (string) ( $gate['question'] ?? '' ),
				'options'    => array_values( (array) ( $gate['options'] ?? array() ) ),
				'expires_at' => (string) ( $gate['expires_at'] ?? '' ),
			) );
		}
		if ( 'owner_answered' === $kind && ( null === $current || (string) ( $current['ask_id'] ?? '' ) === (string) $gate['ask_id'] ) ) {
			return array( 'waiting_on' => null );
		}
		return array();
	}

	/** steps_total / steps_done from job-result@1 steps[] (completed_verified · submitted_unverified · skipped count as done). Pure. */
	public static function steps_of( ?array $result ): array {
		$steps = is_array( $result ) ? (array) ( $result['steps'] ?? array() ) : array();
		$done  = 0;
		foreach ( $steps as $s ) {
			if ( is_array( $s ) && in_array( (string) ( $s['status'] ?? '' ), array( 'completed_verified', 'submitted_unverified', 'skipped' ), true ) ) {
				$done++;
			}
		}
		return array( 'steps_total' => count( $steps ), 'steps_done' => $done );
	}

	/* ── header row ───────────────────────────────────────────────────── */

	/** The header row of a job for this owner, or null. */
	public static function find_header( int $owner, string $job_id ): ?array {
		$key = self::header_key( $job_id );
		if ( '' === $key || $owner <= 0 || ! class_exists( 'BizCity_Journal_Index' ) ) {
			return null;
		}
		$row = BizCity_Journal_Index::get_by_idempotency_key( $key, $owner );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Get-or-create the header (idempotent on `jb:<blog>:<job_id>`), then make sure it carries its own `jb-<id>` tag so
	 * `journal.find{job}` returns the header with its children.
	 *
	 * @param array $attrs {goal?, status?, requested_by?, skill?, due_at?, conversation_ref?, actor?}
	 * @return array|WP_Error journal-entry@1.2
	 */
	public static function header( int $owner, string $job_id, array $attrs = array() ) {
		if ( ! class_exists( 'BizCity_Journal_Index' ) ) {
			return new WP_Error( 'journal_unavailable', 'Sổ ghi chép Zalo Brain chưa sẵn sàng trên site này.' );
		}
		$key = self::header_key( $job_id );
		if ( '' === $key || $owner <= 0 ) {
			return new WP_Error( 'journal_invalid_entry', 'job_id phải dạng job_<10-40 ký tự> và cần owner.' );
		}
		$have = self::find_header( $owner, $job_id );
		if ( null === $have ) {
			$status = self::status_from_cell( (string) ( $attrs['status'] ?? 'running' ) );
			$goal   = sanitize_text_field( (string) ( $attrs['goal'] ?? '' ) );
			$have   = BizCity_Journal_Index::add( array(
				'owner_user_id'   => $owner,
				'kind'            => self::KIND_HEADER,
				'title'           => '' !== $goal ? mb_substr( $goal, 0, 200 ) : 'Việc ' . substr( $job_id, 4, 8 ),
				'doc_date'        => gmdate( 'Y-m-d' ),
				'state'           => self::TO_STATE[ $status ],
				'source_type'     => 'job',
				'source_ref'      => $job_id,
				'idempotency_key' => $key,
				'origin'          => array( 'surface' => 'job', 'ref' => $job_id ),
				'card'            => array(
					'status'        => $status,
					'requested_by'  => sanitize_key( (string) ( $attrs['requested_by'] ?? 'owner' ) ),
					'skill'         => substr( sanitize_text_field( (string) ( $attrs['skill'] ?? '' ) ), 0, 80 ),
					'steps_total'   => 0,
					'steps_done'    => 0,
					'due_at'        => sanitize_text_field( (string) ( $attrs['due_at'] ?? '' ) ),
					'delivered_at'  => '',
					'output_refs'   => array(),
					'evidence_refs' => array(),
				),
				'job'             => array( 'job_id' => $job_id, 'role' => 'header', 'actor' => is_array( $attrs['actor'] ?? null ) ? $attrs['actor'] : array( 'kind' => 'cell' ), 'conversation_ref' => (string) ( $attrs['conversation_ref'] ?? '' ) ),
				'by'              => array( 'role' => 'system' ),
			) );
			if ( is_wp_error( $have ) ) {
				return $have;
			}
		}
		$id  = (int) $have['entry_id'];
		$tag = self::tag( $id );
		if ( ! in_array( $tag, (array) ( $have['tags'] ?? array() ), true ) || self::job_ref( $id ) !== (string) ( $have['job']['job_ref'] ?? '' ) ) {
			$have = BizCity_Journal_Index::file( $id, $owner, array( 'tags' => array_merge( (array) ( $have['tags'] ?? array() ), array( $tag ) ), 'job' => array( 'job_ref' => self::job_ref( $id ), 'role' => 'header' ) ) );
		}
		return $have;
	}

	/**
	 * Attach one row (already written) to its job: `metadata.job` + tag on the row, code appended to the header's
	 * output_refs (role output) / evidence_refs (role evidence). Idempotent.
	 *
	 * @param array $entry journal-entry@1.2 of the child row (owner's own)
	 * @param array $job   {job_id (required), role?, step_ref?, version_ref?, actor?}
	 * @return array|WP_Error the child row, refreshed
	 */
	public static function attach( array $entry, int $owner, array $job ) {
		$job_id = (string) ( $job['job_id'] ?? '' );
		$header = self::header( $owner, $job_id, array( 'status' => 'running', 'actor' => $job['actor'] ?? null, 'goal' => (string) ( $job['goal'] ?? '' ) ) );
		if ( is_wp_error( $header ) ) {
			return $header;
		}
		$hid   = (int) $header['entry_id'];
		$child = (int) ( $entry['entry_id'] ?? 0 );
		if ( $child <= 0 || $child === $hid ) {
			return $entry;
		}
		$block = self::normalize( array_merge( is_array( $entry['job'] ?? null ) ? $entry['job'] : array(), $job, array( 'job_id' => $job_id, 'job_ref' => self::job_ref( $hid ) ) ) );
		if ( 'header' === $block['role'] ) {
			$block['role'] = 'output';
		}
		$tags = array_values( array_unique( array_merge( (array) ( $entry['tags'] ?? array() ), array( self::tag( $hid ) ) ) ) );
		$row  = BizCity_Journal_Index::file( $child, $owner, array( 'tags' => $tags, 'job' => $block ) );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		$code  = (string) ( $row['ref_code'] ?? '' );
		$card  = is_array( $header['card'] ?? null ) ? $header['card'] : array();
		$patch = array();
		if ( '' !== $code && in_array( $block['role'], array( 'output', 'evidence' ), true ) ) {
			$field = 'output' === $block['role'] ? 'output_refs' : 'evidence_refs';
			$list  = array_values( array_unique( array_merge( (array) ( $card[ $field ] ?? array() ), array( $code ) ) ) );
			if ( $list !== (array) ( $card[ $field ] ?? array() ) ) {
				$patch[ $field ] = array_slice( $list, 0, self::MAX_REFS );
			}
		}
		// [2026-10-20 11:33 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.126 S126-J4 — a job the cell runs WITHOUT job-event (a saved meeting) closes its own header: status running · done · failed
		$status = (string) ( $job['status'] ?? '' );
		if ( in_array( $status, array( 'running', 'done', 'failed' ), true ) && $status !== (string) ( $card['status'] ?? '' ) ) {
			$patch['status'] = $status;
			if ( 'done' === $status ) {
				$patch['delivered_at'] = gmdate( 'c' );
			}
		}
		if ( array() !== $patch ) {
			BizCity_Journal_Index::file( $hid, $owner, array( 'card' => $patch ) );
			if ( isset( $patch['status'] ) && self::TO_STATE[ $patch['status'] ] !== (string) ( $header['state'] ?? '' ) ) {
				BizCity_Journal_Index::set_state( $hid, $owner, self::TO_STATE[ $patch['status'] ] );
			}
		}
		return $row;
	}

	/**
	 * job-event@1.1 reached the site (`bizcity_twin_job_event`): open the header when the event justifies one (BS-5), then
	 * fold status, steps and product codes into it. Replays are harmless (idempotent key + card merge).
	 *
	 * @param array $e {user_id, job_id, seq, kind, status, result}
	 */
	public static function on_job_event( $e ): void {
		if ( ! is_array( $e ) || ! class_exists( 'BizCity_Journal_Index' ) ) {
			return;
		}
		$owner  = (int) ( $e['user_id'] ?? 0 );
		$job_id = (string) ( $e['job_id'] ?? '' );
		$kind   = (string) ( $e['kind'] ?? '' );
		$result = is_array( $e['result'] ?? null ) ? $e['result'] : null;
		if ( $owner <= 0 || ! self::is_job_id( $job_id ) ) {
			return;
		}
		$have = self::find_header( $owner, $job_id );
		if ( null === $have && ! self::should_open( $kind, (string) ( $e['status'] ?? '' ), $result ) ) {
			return;
		}
		$status = self::status_from_cell( (string) ( $e['status'] ?? '' ) );
		$skill  = (string) ( $result['skill']['id'] ?? ( $e['skill'] ?? '' ) );
		$header = self::header( $owner, $job_id, array( 'status' => $status, 'skill' => $skill ) );
		if ( is_wp_error( $header ) ) {
			return;
		}
		$hid  = (int) $header['entry_id'];
		$card = array( 'status' => $status ) + self::waiting_on_patch( $kind, is_array( $e['gate'] ?? null ) ? $e['gate'] : null, is_array( $header['card']['waiting_on'] ?? null ) ? $header['card']['waiting_on'] : null );
		if ( '' !== $skill ) {
			$card['skill'] = substr( sanitize_text_field( $skill ), 0, 80 );
		}
		if ( null !== $result ) {
			$card += self::steps_of( $result );
			$refs  = self::refs_from_result( $result, static function ( int $id ) use ( $owner ): string {
				$row = BizCity_Journal_Index::get( $id, $owner );
				return $row ? (string) ( $row['ref_code'] ?? '' ) : '';
			} );
			$cur   = is_array( $header['card'] ?? null ) ? $header['card'] : array();
			foreach ( array( 'output_refs', 'evidence_refs' ) as $f ) {
				$card[ $f ] = array_slice( array_values( array_unique( array_merge( (array) ( $cur[ $f ] ?? array() ), $refs[ $f ] ) ) ), 0, self::MAX_REFS );
			}
			if ( 'done' === $status && '' !== (string) ( $result['completed_at'] ?? '' ) ) {
				$card['delivered_at'] = substr( sanitize_text_field( (string) $result['completed_at'] ), 0, 32 );
			}
			if ( 'cancelled' === sanitize_key( (string) ( $e['status'] ?? '' ) ) ) {
				$card['cancelled'] = true;
			}
		}
		BizCity_Journal_Index::file( $hid, $owner, array( 'card' => $card ) );
		$state = self::TO_STATE[ $status ];
		if ( $state !== (string) ( $header['state'] ?? '' ) && 'verified' !== (string) ( $header['state'] ?? '' ) ) {
			BizCity_Journal_Index::set_state( $hid, $owner, $state, 'failed' === $state ? array( 'error' => array( 'code' => sanitize_key( (string) ( $e['failure_code'] ?? 'job_failed' ) ) ) ) : array() );
		}
	}

	/**
	 * `journal.find{job}` — turn `JB-31` or a cell `job_…` id into the tag filter. Returns '' when there is no such header for
	 * this owner (the caller then returns an empty page, never a 404 that leaks another person's job).
	 */
	public static function tag_for_query( int $owner, string $job ): string {
		$job = trim( $job );
		$id  = self::parse_job_ref( $job );
		if ( $id > 0 ) {
			$row = class_exists( 'BizCity_Journal_Index' ) ? BizCity_Journal_Index::get( $id, $owner ) : null;
			return $row && self::KIND_HEADER === (string) ( $row['kind'] ?? '' ) ? self::tag( $id ) : '';
		}
		if ( self::is_job_id( $job ) ) {
			$row = self::find_header( $owner, $job );
			return $row ? self::tag( (int) $row['entry_id'] ) : '';
		}
		return '';
	}
}
