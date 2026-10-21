<?php
/**
 * BizCity Channel Gateway — "Việc đã làm": the person's assistant Jobs, read from the twin event stream (PHASE-0.117 S117-J5).
 *
 *   GET bizcity-channel/v1/jobs              the current person's latest Jobs (one row per job_id, newest first)
 *   GET bizcity-channel/v1/jobs/<job_id>     one Job folded from its events (R-JOB-9): by `seq`, duplicates ignored, gaps reported
 *
 *   POST bizcity-channel/v1/jobs/<job_id>/hide   {hidden: bool} hide / show a Job on THIS screen (user meta; the cell's machine ledger is never touched)
 *   POST bizcity-channel/v1/jobs/journal-void    {ref_code, reason?} "Hủy dòng" — the journal row stays, state void (R-JOURNAL v1.4 R-JN-25 / R-JN-26)
 *   POST bizcity-channel/v1/jobs/hide            {ids: string[], hidden: bool} hide / show SEVERAL Jobs at once (≤ 50; only the person's own)
 *
 * [2026-10-18 04:44 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.117 S117-K2g — the page is a matrix (rows = skill, columns = status group, views by day / by number), computed in the browser
 * from the list: each list row now carries account_id (the number the Job came from), journal_refs and hidden. No second store (R-CM-8).
 *
 * [2026-10-18 04:25 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.117 S117-K2e (R-JN-26): the page is not read-only any more — every stopped Job has a way out on the screen.
 * Each person sees ONLY their own Jobs (even an admin). Nothing here is content: steps, statuses, evidence references, a failure CODE.
 * The fold is a pure function (`fold()`), tested without WordPress.
 *
 * @package BizCity_Channel_Gateway
 */
// [2026-10-17 02:40 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.117 S117-J5

defined( 'ABSPATH' ) || exit;

final class BizCity_Job_View_REST {

	const NS    = 'bizcity-channel/v1';
	const TYPES = array( 'job_progressed', 'job_completed' );
	const HIDDEN_META = 'bizcity_jobs_hidden';
	const HIDDEN_MAX  = 500;

	/** @var callable|null tests: fn(int $user_id, ?string $job_id): array of rows {event_type, payload, created_epoch_ms} */
	public static $rows_seam = null;

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes(): void {
		$perm = static function () {
			return is_user_logged_in() ? true : new WP_Error( 'auth_required', 'Cần đăng nhập.', array( 'status' => 401 ) );
		};
		register_rest_route( self::NS, '/jobs', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'list_jobs' ), 'permission_callback' => $perm ) );
		register_rest_route( self::NS, '/jobs/(?P<id>job_[0-9A-Za-z]{10,40})', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'get_job' ), 'permission_callback' => $perm ) );
		register_rest_route( self::NS, '/jobs/(?P<id>job_[0-9A-Za-z]{10,40})/hide', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'hide_job' ), 'permission_callback' => $perm ) );
		register_rest_route( self::NS, '/jobs/journal-void', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'journal_void' ), 'permission_callback' => $perm ) );
		register_rest_route( self::NS, '/jobs/hide', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'hide_jobs' ), 'permission_callback' => $perm ) ); // [2026-10-18 04:44 PM Johnny Chu - Chu Hoàng Anh] S117-K2g
	}

	/**
	 * R-JOB-9: fold the events of ONE Job. Sort by seq, keep the first of a duplicated seq, the final state is the event with the
	 * highest seq; a missing seq between 1 and the highest is reported in `gaps` (the screen says "đang cập nhật", never guesses).
	 *
	 * @param array<int,array{event_type:string,payload:array,created_epoch_ms?:int}> $rows
	 */
	public static function fold( array $rows ): array {
		$by_seq = array();
		foreach ( $rows as $r ) {
			$p   = is_array( $r['payload'] ?? null ) ? $r['payload'] : array();
			$seq = (int) ( $p['seq'] ?? 0 );
			if ( $seq <= 0 || isset( $by_seq[ $seq ] ) ) {
				continue;
			}
			$by_seq[ $seq ] = array( 'type' => (string) ( $r['event_type'] ?? '' ), 'p' => $p, 'at' => (int) ( $r['created_epoch_ms'] ?? 0 ) );
		}
		if ( ! $by_seq ) {
			return array();
		}
		ksort( $by_seq );
		$max  = (int) max( array_keys( $by_seq ) );
		$gaps = array();
		for ( $i = 1; $i <= $max; $i++ ) {
			if ( ! isset( $by_seq[ $i ] ) ) {
				$gaps[] = $i;
			}
		}
		$last   = $by_seq[ $max ];
		$trail  = array();
		$result = null;
		foreach ( $by_seq as $seq => $e ) {
			$trail[] = array( 'seq' => $seq, 'kind' => (string) ( $e['p']['kind'] ?? '' ), 'status' => (string) ( $e['p']['status'] ?? '' ), 'step_id' => (string) ( $e['p']['step_id'] ?? '' ), 'at' => $e['at'] );
			if ( 'job_completed' === $e['type'] && isset( $e['p']['result'] ) && is_array( $e['p']['result'] ) ) {
				$result = $e['p']['result'];
			}
		}
		// [2026-10-17 05:54 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.117 S117-J6: the skill rides on every event; the finished result (when there is one) wins
		$skill = is_array( $result ) ? (string) ( $result['skill']['id'] ?? '' ) : '';
		foreach ( array_reverse( $by_seq, true ) as $e ) {
			if ( '' === $skill && ! empty( $e['p']['skill'] ) ) {
				$skill = (string) $e['p']['skill'];
			}
		}
		$account = ''; // [2026-10-18 04:44 PM Johnny Chu - Chu Hoàng Anh] S117-K2g: the number (bridge account id) the Job came from — "Theo số trợ lý"
		foreach ( array_reverse( $by_seq, true ) as $e ) {
			if ( isset( $e['p']['account_id'] ) && preg_match( '/^\d{1,20}$/', (string) $e['p']['account_id'] ) ) {
				$account = (string) $e['p']['account_id'];
				break;
			}
		}
		$failure = '';
		foreach ( array_reverse( $by_seq, true ) as $e ) {
			if ( ! empty( $e['p']['failure_code'] ) ) {
				$failure = (string) $e['p']['failure_code'];
				break;
			}
		}
		// [2026-10-18 06:16 PM Johnny Chu - Chu Hoàng Anh] S117-K2h: the draft the Job is waiting on — only while it still waits (a decided draft is history, no button)
		$draft = '';
		if ( 'waiting_approval' === (string) ( $last['p']['status'] ?? '' ) ) {
			foreach ( array_reverse( $by_seq, true ) as $e ) {
				if ( ! empty( $e['p']['draft_id'] ) && preg_match( '/^act_[A-Za-z0-9_-]{8,40}$/', (string) $e['p']['draft_id'] ) ) {
					$draft = (string) $e['p']['draft_id'];
					break;
				}
			}
		}
		// [2026-10-20 03:30 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.125 Q1 — the newest Quest Map snapshot (highest seq that carries one)
		$quest = null;
		foreach ( array_reverse( $by_seq, true ) as $e ) {
			if ( isset( $e['p']['quest'] ) && is_array( $e['p']['quest'] ) ) {
				$quest = $e['p']['quest'];
				break;
			}
		}
		return array(
			'job_id'       => (string) ( $last['p']['job_id'] ?? '' ),
			'status'       => (string) ( $last['p']['status'] ?? '' ),
			'last_seq'     => $max,
			'gaps'         => $gaps,
			'failure_code' => $failure,
			'skill'        => $skill,
			'account_id'   => $account,
			'draft_id'     => $draft,
			'trail'        => $trail,
			'result'       => $result,
			'result_omitted' => ! empty( $last['p']['result_omitted'] ),
			'quest'        => $quest,
			'updated_at'   => $last['at'],
		);
	}

	private static function rows( int $user_id, ?string $job_id ): array {
		if ( is_callable( self::$rows_seam ) ) {
			return (array) call_user_func( self::$rows_seam, $user_id, $job_id );
		}
		if ( ! class_exists( 'BizCity_Twin_Event_Stream_Schema' ) ) {
			return array();
		}
		global $wpdb;
		$table = BizCity_Twin_Event_Stream_Schema::table();
		$blog  = (int) get_current_blog_id();
		$in    = "'" . implode( "','", self::TYPES ) . "'";
		$sql   = null === $job_id
			? $wpdb->prepare( "SELECT event_type, trace_id, payload_json, created_epoch_ms FROM {$table} WHERE user_id = %d AND blog_id = %d AND event_type IN ({$in}) ORDER BY id DESC LIMIT 600", $user_id, $blog )
			: $wpdb->prepare( "SELECT event_type, trace_id, payload_json, created_epoch_ms FROM {$table} WHERE user_id = %d AND blog_id = %d AND trace_id = %s AND event_type IN ({$in}) ORDER BY id ASC LIMIT 500", $user_id, $blog, $job_id );
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		$out  = array();
		foreach ( is_array( $rows ) ? $rows : array() as $r ) {
			$out[] = array( 'event_type' => (string) $r['event_type'], 'trace_id' => (string) $r['trace_id'], 'payload' => json_decode( (string) $r['payload_json'], true ) ?: array(), 'created_epoch_ms' => (int) $r['created_epoch_ms'] );
		}
		return $out;
	}

	public static function list_jobs( WP_REST_Request $request ): WP_REST_Response {
		$groups = array();
		foreach ( self::rows( get_current_user_id(), null ) as $r ) {
			$groups[ (string) ( $r['trace_id'] ?? ( $r['payload']['job_id'] ?? '' ) ) ][] = $r;
		}
		$jobs = array();
		$hidden      = self::hidden( get_current_user_id() );
		$show_hidden = (bool) $request->get_param( 'hidden' );
		$hidden_n    = 0;
		foreach ( $groups as $id => $rows ) {
			$f = self::fold( $rows );
			if ( ! $f ) {
				continue;
			}
			$is_hidden = in_array( (string) ( $f['job_id'] ?: $id ), $hidden, true );
			$hidden_n += $is_hidden ? 1 : 0;
			if ( $is_hidden !== $show_hidden ) {
				continue;
			}
			$jobs[] = array(
				'job_id'       => $f['job_id'] ?: $id,
				'status'       => $f['status'],
				'skill'        => (string) ( $f['skill'] ?? '' ),
				'failure_code' => $f['failure_code'],
				'updated_at'   => $f['updated_at'],
				'account_id'   => (string) ( $f['account_id'] ?? '' ),
				'journal_refs' => self::journal_refs( $f ),
				'hidden'       => $is_hidden,
				'draft_id'     => (string) ( $f['draft_id'] ?? '' ),
				'account_label' => self::account_label( (string) ( $f['account_id'] ?? '' ) ),
			);
		}
		usort( $jobs, static function ( $a, $b ) { return $b['updated_at'] <=> $a['updated_at']; } );
		return new WP_REST_Response( array( 'ok' => true, 'jobs' => array_slice( $jobs, 0, 50 ), 'hidden_count' => $hidden_n ), 200 );
	}

	public static function get_job( WP_REST_Request $request ): WP_REST_Response {
		$id = (string) $request->get_param( 'id' );
		$f  = self::fold( self::rows( get_current_user_id(), $id ) );
		if ( ! $f ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'job_not_found', 'message' => 'Không có việc này.' ), 404 );
		}
		$f['journal_refs'] = self::journal_refs( $f );
		$f['hidden']       = in_array( $id, self::hidden( get_current_user_id() ), true );
		$f['account_label'] = self::account_label( (string) ( $f['account_id'] ?? '' ) );
		return new WP_REST_Response( array( 'ok' => true, 'job' => $f ), 200 );
	}

	/** the ref codes (TC-12, CN-3…) a Job wrote into the journal: evidence `journal_row` with ref `journal:TC-12,TC-13`. Pure. */
	public static function journal_refs( array $folded ): array {
		$out = array();
		foreach ( (array) ( $folded['result']['evidence'] ?? array() ) as $e ) {
			if ( is_array( $e ) && 'journal_row' === ( $e['kind'] ?? '' ) && 0 === strpos( (string) ( $e['ref'] ?? '' ), 'journal:' ) ) {
				foreach ( explode( ',', substr( (string) $e['ref'], 8 ) ) as $c ) {
					$c = strtoupper( trim( $c ) );
					if ( preg_match( '/^(TC|CN|TD|VC|KH)-\d{1,12}$/', $c ) ) {
						$out[] = $c;
					}
				}
			}
		}
		return array_values( array_unique( $out ) );
	}

	/** @var array<string,string> per-request cache of number labels */
	private static $labels = array();

	/**
	 * [2026-10-18 06:16 PM Johnny Chu - Chu Hoàng Anh] S117-K2h — "Theo số trợ lý": the number's own label, MASKED (never a full phone number, R-CID-11;
	 * same mask as the journey page): a name stays a name, a phone-like label keeps head + tail digits. Unknown / no repo ⇒ "Số …<last 3>".
	 */
	public static function account_label( string $id ): string {
		if ( '' === $id ) {
			return '';
		}
		if ( isset( self::$labels[ $id ] ) ) {
			return self::$labels[ $id ];
		}
		$label = '';
		if ( class_exists( 'BizCity_Zalo_Mapping_Repo' ) && method_exists( 'BizCity_Zalo_Mapping_Repo', 'find_account_by_bridge_id' ) ) {
			$row   = (array) BizCity_Zalo_Mapping_Repo::find_account_by_bridge_id( 'personal', $id );
			$label = trim( (string) ( $row['label'] ?? $row['account_name'] ?? '' ) );
		}
		$digits = preg_replace( '/\D/', '', $label );
		if ( '' === $label ) {
			$label = 'Số …' . substr( $id, -3 );
		} elseif ( strlen( $digits ) >= 7 ) {
			$label = substr( $digits, 0, 4 ) . ' ••• ' . substr( $digits, -3 );
		} else {
			$label = function_exists( 'mb_substr' ) ? mb_substr( $label, 0, 40 ) : substr( $label, 0, 40 );
		}
		return self::$labels[ $id ] = $label;
	}

	/** @return string[] job ids this person hid */
	private static function hidden( int $uid ): array {
		if ( $uid <= 0 || ! function_exists( 'get_user_meta' ) ) {
			return array();
		}
		$raw = get_user_meta( $uid, self::HIDDEN_META, true );
		return is_array( $raw ) ? array_values( array_filter( $raw, 'is_string' ) ) : array();
	}

	public static function hide_job( WP_REST_Request $request ): WP_REST_Response {
		$uid = get_current_user_id();
		$id  = (string) $request->get_param( 'id' );
		if ( ! self::fold( self::rows( $uid, $id ) ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'job_not_found', 'message' => 'Không có việc này.' ), 404 );
		}
		$hide = self::hide_flag( $request->get_param( 'hidden' ) );
		self::set_hidden( $uid, array( $id ), $hide );
		return new WP_REST_Response( array( 'ok' => true, 'job_id' => $id, 'hidden' => $hide ), 200 );
	}

	/** absent ⇒ hide; false / 0 / "0" / "false" / "" ⇒ show again */
	private static function hide_flag( $p ): bool {
		return null === $p ? true : ! in_array( $p, array( false, 0, '0', 'false', '' ), true );
	}

	private static function set_hidden( int $uid, array $ids, bool $hide ): void {
		$list = array_values( array_diff( self::hidden( $uid ), $ids ) );
		if ( $hide ) {
			$list = array_merge( $ids, $list );
		}
		update_user_meta( $uid, self::HIDDEN_META, array_slice( $list, 0, self::HIDDEN_MAX ) );
	}

	/**
	 * [2026-10-18 04:44 PM Johnny Chu - Chu Hoàng Anh] S117-K2g — "Ẩn các việc đã chọn" on the matrix panel. Every id must be the person's own Job (a foreign or unknown id is
	 * reported in `skipped`, never hidden — no existence leak: the same answer for "not yours" and "does not exist").
	 */
	public static function hide_jobs( WP_REST_Request $request ): WP_REST_Response {
		$uid = get_current_user_id();
		$raw = $request->get_param( 'ids' );
		$ids = array();
		foreach ( is_array( $raw ) ? $raw : array() as $id ) {
			if ( is_string( $id ) && preg_match( '/^job_[0-9A-Za-z]{10,40}$/', $id ) ) {
				$ids[] = $id;
			}
		}
		$ids = array_values( array_unique( $ids ) );
		if ( ! $ids || count( $ids ) > 50 ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'invalid_param', 'message' => 'Chọn từ 1 đến 50 việc.' ), 400 );
		}
		$own = array();
		$skipped = array();
		foreach ( $ids as $id ) {
			if ( self::fold( self::rows( $uid, $id ) ) ) {
				$own[] = $id;
			} else {
				$skipped[] = $id;
			}
		}
		$hide = self::hide_flag( $request->get_param( 'hidden' ) );
		if ( $own ) {
			self::set_hidden( $uid, $own, $hide );
		}
		return new WP_REST_Response( array( 'ok' => true, 'hidden' => $hide, 'changed' => $own, 'skipped' => $skipped ), 200 );
	}

	/** "Hủy dòng" on the Job page — same door the cell uses (journal.void): the writer voids own rows; a site admin counts as the number owner (staff rows). */
	public static function journal_void( WP_REST_Request $request ): WP_REST_Response {
		$code = strtoupper( trim( (string) $request->get_param( 'ref_code' ) ) );
		if ( ! preg_match( '/^(TC|CN|TD|VC|KH)-\d{1,12}$/', $code ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'invalid_param', 'message' => 'Mã dòng sổ không đúng dạng (vd TC-123).' ), 400 );
		}
		if ( ! class_exists( 'BizCity_Journal_MCP_Tools' ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'journal_unavailable', 'message' => 'Sổ ghi chép chưa sẵn sàng trên site này.' ), 503 );
		}
		$ctx = array( 'owner_user_id' => get_current_user_id(), 'role' => current_user_can( 'manage_options' ) ? 'owner' : 'staff' );
		$r   = BizCity_Journal_MCP_Tools::void_row( array( 'ref_code' => $code, 'reason' => sanitize_text_field( (string) $request->get_param( 'reason' ) ) ), $ctx );
		if ( is_wp_error( $r ) ) {
			$reason = (string) ( $r->get_error_data()['reason'] ?? $r->get_error_code() );
			return new WP_REST_Response( array( 'ok' => false, 'code' => $reason, 'message' => $r->get_error_message() ), 'journal_not_found' === $reason ? 404 : 400 );
		}
		return new WP_REST_Response( array( 'ok' => true ) + $r, 200 );
	}
}
