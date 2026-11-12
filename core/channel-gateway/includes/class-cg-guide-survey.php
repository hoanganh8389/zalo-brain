<?php
/**
 * BizCity Channel Gateway — "Hướng dẫn": the guide cards as a SURVEY (PHASE-0.145 S145-L10, doc 55 §2–§4 + doc 56 round 3, contracts case-guide@1 · case-feedback@1).
 *
 *   GET  bizcity-channel/v1/guide/cases?round=   the static pack (contracts/CASE-GUIDE-v1.json) + ready / answerable per card + the caller's OWN answers (from the cell)
 *   POST bizcity-channel/v1/guide/feedback       one answer {case, verdict, reasons[], stars?, note?, round?, tested_on{channel, account_ref?}}
 *   GET  bizcity-channel/v1/guide/rounds         survey rounds (manage cap) · POST upsert one round {id?, name, from?, to?, cases?[], open?}
 *
 * Who answers (D145-16): every logged-in person who can open the Gateway — admin, number owner, staff, tester; never an anonymous guest.
 * The SERVER sets everything about the person (D145-16, doc 56 §4): respondent_role, principal (short hash), run_kind, the number routed to; whatever role /
 * tenant / principal the browser sends is dropped (only the whitelisted answer keys are read).
 *
 *   respondent_role   admin         BizCity_Network_Admin_Capability::can_manage() (fallback manage_options)
 *                     number_owner  owner of ≥ 1 zalo-hub number of this site (BizCity_Zalo_Hub_Config_Sync::accounts() owner_user_id)
 *                     tester        cap `bizcity_cg_tester` OR user meta `bizcity_cg_tester` = 1 (set by an admin) — run_kind "qa"
 *                     staff         anyone else logged in (on a number's staff list or not)
 *                     order: admin > number_owner > tester > staff. Analysis only: a role never changes a permission.
 *   tested_on         zalo_personal + account_ref of a number the person may SEE (admin: every zalo-hub number · owner: theirs · staff: numbers_for_user) | twinchat | web
 *   account_ref       "acct:" + 4 hex of sha256(blog|bridge_id|salt) — never the UID / phone
 *   principal         "p:" + 6 hex of BizCity_Agent_Mode_Access::user_hash() (the per-blog salted person hash)
 *
 * Storage (D145-13): the answer goes site ⇒ Hub ⇒ cell `POST /wp/brain/case-feedback` (one audit_log row, codes + numbers only). A free-text note ≤ 300 chars
 * (D145-14) never leaves the site: it becomes ONE Journal row kind `feedback.note` owned by the writer (declared through `bizcity_journal_kinds`); the cell
 * only gets `has_note: true`. Rounds = blog option `bizcity_cg_guide_rounds` (no table); the default round "always" ("Thường xuyên") is always open.
 * No model call (validate-no-llm-in-core).
 *
 * [2026-10-27 09:52 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.145 S145-L10 — new file.
 *
 * @package BizCity_Channel_Gateway
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_CG_Guide_Survey', false ) ) {
	return;
}

final class BizCity_CG_Guide_Survey {

	const NS            = 'bizcity-channel/v1';
	const ROUNDS_OPTION = 'bizcity_cg_guide_rounds';
	const SHIPPED_OPTION = 'bizcity_cg_guide_shipped';
	const TESTER_KEY    = 'bizcity_cg_tester';
	const NOTE_KIND     = 'feedback.note';
	const NOTE_MAX      = 300;
	const ROUNDS_MAX    = 30;
	const VERDICTS      = array( 'up', 'fix', 'down' );
	const CHANNELS      = array( 'zalo_personal', 'twinchat', 'web' );
	const ROLES         = array( 'admin', 'number_owner', 'tester', 'staff' );
	const DEFAULT_ROUND = array( 'id' => 'always', 'name' => 'Thường xuyên', 'from' => '', 'to' => '', 'cases' => array(), 'open' => true );

	/**
	 * Test seams (no WordPress): hub(method, path, bridge, query, body) · accounts() · staff_numbers(uid) · can_manage(uid) · is_tester(uid) · user_hash(uid)
	 * · record(entry) · option(name) · save_option(name, value) · now().
	 *
	 * @var array<string,callable>
	 */
	public static $readers = array();

	/** @var array|null */
	private static $pack = null;

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_filter( 'bizcity_journal_kinds', array( __CLASS__, 'declare_kind' ) );
		if ( class_exists( 'BizCity_Journal_Kinds', false ) && method_exists( 'BizCity_Journal_Kinds', 'reset' ) ) {
			BizCity_Journal_Kinds::reset(); // the catalog may have been read before init: forget it so feedback.note is declared
		}
	}

	/** journal-kinds: `feedback.note` — a person's free-text note on a guide card (D145-14). Ledger row, not knowledge. */
	public static function declare_kind( $all ) {
		$all = is_array( $all ) ? $all : array();
		$all['kinds'] = is_array( $all['kinds'] ?? null ) ? $all['kinds'] : array();
		if ( ! isset( $all['kinds'][ self::NOTE_KIND ] ) ) {
			$all['kinds'][ self::NOTE_KIND ] = array(
				'family'      => 'job',
				'label'       => 'Ghi chú khảo sát',
				'icon'        => 'feedback',
				'sensitive'   => '',
				'learn'       => 'none',
				'card_fields' => array( 'case', 'round', 'verdict', 'note' ),
				'_stamp'      => '[2026-10-27 09:52 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.145 S145-L10 (D145-14) — the free-text note of a Gateway guide answer; the cell gets has_note only.',
			);
		}
		return $all;
	}

	public static function register_routes(): void {
		$person = static function () {
			return self::can_answer() ? true : new WP_Error( 'auth_required', 'Cần đăng nhập.', array( 'status' => 401 ) );
		};
		$manage = static function () {
			return self::can_manage( self::uid() ) ? true : new WP_Error( 'guide_forbidden', 'Chỉ quản trị mở / đóng đợt khảo sát.', array( 'status' => 403 ) );
		};
		register_rest_route( self::NS, '/guide/cases', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'rest_cases' ), 'permission_callback' => $person ) );
		register_rest_route( self::NS, '/guide/feedback', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'rest_feedback' ), 'permission_callback' => $person ) );
		register_rest_route( self::NS, '/guide/rounds', array(
			array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'rest_rounds' ), 'permission_callback' => $manage ),
			array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'rest_round_save' ), 'permission_callback' => $manage ),
		) );
	}

	/** D145-16: any logged-in person who can open the Gateway (filter `bizcity_cg_guide_can_answer` to narrow). */
	public static function can_answer(): bool {
		$in = function_exists( 'is_user_logged_in' ) && is_user_logged_in();
		return $in && (bool) ( function_exists( 'apply_filters' ) ? apply_filters( 'bizcity_cg_guide_can_answer', true, self::uid() ) : true );
	}

	// ── the static pack ─────────────────────────────────────────────────

	public static function pack(): array {
		if ( null === self::$pack ) {
			$raw        = @file_get_contents( dirname( __DIR__ ) . '/contracts/CASE-GUIDE-v1.json' ); // phpcs:ignore
			$dec        = is_string( $raw ) ? json_decode( $raw, true ) : null;
			self::$pack = is_array( $dec ) && is_array( $dec['cases'] ?? null ) ? $dec : array( 'contract' => 'case-guide@1', 'cases' => array(), 'reasons_closed_list' => array() );
		}
		return self::$pack;
	}

	public static function case_of( string $id ): ?array {
		foreach ( (array) self::pack()['cases'] as $c ) {
			if ( is_array( $c ) && (string) ( $c['case'] ?? '' ) === $id ) {
				return $c;
			}
		}
		return null;
	}

	/** Ready = not "soon" and every required work item is shipped on this site (option + filter + what the cell reports). Pure over its inputs. */
	public static function is_ready( array $case, array $shipped ): bool {
		if ( 'soon' === (string) ( $case['flag'] ?? '' ) ) {
			return false;
		}
		return ! array_diff( array_map( 'strval', (array) ( $case['requires'] ?? array() ) ), $shipped );
	}

	/** qa cards are answered by a tester / admin only; soon cards by nobody. Pure. */
	public static function is_answerable( array $case, array $shipped, string $role ): bool {
		if ( ! self::is_ready( $case, $shipped ) ) {
			return false;
		}
		return 'qa' !== (string) ( $case['flag'] ?? '' ) || in_array( $role, array( 'tester', 'admin' ), true );
	}

	private static function shipped( array $cell = array() ): array {
		$ids = array_map( 'strval', (array) self::option( self::SHIPPED_OPTION, array() ) );
		$ids = array_merge( $ids, array_map( 'strval', (array) ( $cell['shipped'] ?? array() ) ) );
		if ( function_exists( 'apply_filters' ) ) {
			$ids = (array) apply_filters( 'bizcity_cg_guide_shipped_ids', $ids );
		}
		return array_values( array_unique( array_filter( array_map( 'strval', $ids ), static function ( $s ) { return 1 === preg_match( '/^S145-[A-Z][0-9]{1,2}$/', $s ); } ) ) );
	}

	// ── the person (server side only) ───────────────────────────────────

	private static function uid(): int {
		return isset( self::$readers['uid'] ) ? (int) call_user_func( self::$readers['uid'] ) : ( function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0 );
	}

	public static function can_manage( int $uid ): bool {
		if ( isset( self::$readers['can_manage'] ) ) {
			return (bool) call_user_func( self::$readers['can_manage'], $uid );
		}
		if ( $uid <= 0 ) {
			return false;
		}
		return class_exists( 'BizCity_Network_Admin_Capability' ) ? BizCity_Network_Admin_Capability::can_manage( $uid ) : ( function_exists( 'user_can' ) && user_can( $uid, 'manage_options' ) );
	}

	private static function is_tester( int $uid ): bool {
		if ( isset( self::$readers['is_tester'] ) ) {
			return (bool) call_user_func( self::$readers['is_tester'], $uid );
		}
		return $uid > 0 && ( ( function_exists( 'user_can' ) && user_can( $uid, self::TESTER_KEY ) ) || ( function_exists( 'get_user_meta' ) && in_array( (string) get_user_meta( $uid, self::TESTER_KEY, true ), array( '1', 'yes', 'true' ), true ) ) );
	}

	/** zalo-hub numbers of the site: [{bridge_id, label, owner_user_id}] */
	private static function accounts(): array {
		if ( isset( self::$readers['accounts'] ) ) {
			return (array) call_user_func( self::$readers['accounts'] );
		}
		return class_exists( 'BizCity_Zalo_Hub_Config_Sync' ) ? (array) BizCity_Zalo_Hub_Config_Sync::accounts() : array();
	}

	/** Numbers where the person is on the staff list: [{account_id, label, status}] */
	private static function staff_numbers( int $uid ): array {
		if ( isset( self::$readers['staff_numbers'] ) ) {
			return (array) call_user_func( self::$readers['staff_numbers'], $uid );
		}
		return class_exists( 'BizCity_Zalo_Agent_Principals' ) ? (array) BizCity_Zalo_Agent_Principals::numbers_for_user( $uid ) : array();
	}

	/** The role of the respondent, decided here only. Pure over the seams. */
	public static function role_of( int $uid ): string {
		if ( self::can_manage( $uid ) ) {
			return 'admin';
		}
		foreach ( self::accounts() as $a ) {
			if ( $uid > 0 && (int) ( $a['owner_user_id'] ?? 0 ) === $uid ) {
				return 'number_owner';
			}
		}
		return self::is_tester( $uid ) ? 'tester' : 'staff';
	}

	public static function account_ref( string $bridge_id ): string {
		$blog = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
		$salt = function_exists( 'wp_salt' ) ? wp_salt( 'auth' ) : 'bizcity';
		return 'acct:' . substr( hash( 'sha256', $blog . '|' . $bridge_id . '|' . $salt ), 0, 4 );
	}

	public static function principal( int $uid ): string {
		$h = isset( self::$readers['user_hash'] ) ? (string) call_user_func( self::$readers['user_hash'], $uid ) : ( class_exists( 'BizCity_Agent_Mode_Access' ) ? BizCity_Agent_Mode_Access::user_hash( $uid ) : '' );
		if ( '' === $h ) {
			$h = hash( 'sha256', ( function_exists( 'get_current_blog_id' ) ? get_current_blog_id() : 0 ) . '|' . $uid . '|' . ( function_exists( 'wp_salt' ) ? wp_salt( 'auth' ) : '' ) );
		}
		return 'p:' . substr( strtolower( $h ), 0, 6 );
	}

	/**
	 * The numbers this person may SEE, as answer choices (same sources as the Số Zalo page): [{account_ref, bridge_id, label}]. bridge_id never leaves the server.
	 */
	public static function visible_numbers( int $uid ): array {
		$all  = array();
		foreach ( self::accounts() as $a ) {
			$all[ (string) ( $a['bridge_id'] ?? '' ) ] = $a;
		}
		$admin = self::can_manage( $uid );
		$out   = array();
		foreach ( $all as $bid => $a ) {
			if ( '' !== $bid && ( $admin || ( $uid > 0 && (int) ( $a['owner_user_id'] ?? 0 ) === $uid ) ) ) {
				$out[ $bid ] = array( 'account_ref' => self::account_ref( $bid ), 'bridge_id' => $bid, 'label' => self::mask( (string) ( $a['label'] ?? '' ), $bid ) );
			}
		}
		foreach ( self::staff_numbers( $uid ) as $n ) {
			$bid = (string) ( $n['account_id'] ?? '' );
			if ( '' !== $bid && ! isset( $out[ $bid ] ) && isset( $all[ $bid ] ) && 'suspended' !== (string) ( $n['status'] ?? '' ) ) {
				$out[ $bid ] = array( 'account_ref' => self::account_ref( $bid ), 'bridge_id' => $bid, 'label' => (string) ( $n['label'] ?? self::mask( '', $bid ) ) );
			}
		}
		return array_values( $out );
	}

	private static function mask( string $label, string $bid ): string {
		$label = '' !== trim( $label ) ? trim( $label ) : $bid;
		return class_exists( 'BizCity_Zalo_Personal_Knowledge_Capture' ) ? BizCity_Zalo_Personal_Knowledge_Capture::mask_label( $label ) : ( preg_match( '/^\d{6,}$/', $label ) ? '…' . substr( $label, -3 ) : $label );
	}

	// ── rounds ──────────────────────────────────────────────────────────

	public static function rounds(): array {
		$out = array( 'always' => self::DEFAULT_ROUND );
		foreach ( (array) self::option( self::ROUNDS_OPTION, array() ) as $r ) {
			$r = self::clean_round( is_array( $r ) ? $r : array() );
			if ( null !== $r && 'always' !== $r['id'] ) {
				$out[ $r['id'] ] = $r;
			}
		}
		return array_values( $out );
	}

	/** One round, cleaned; null when it has no usable id / name. Pure. */
	public static function clean_round( array $r ): ?array {
		$name = trim( (string) preg_replace( '/\s+/u', ' ', self::strip( (string) ( $r['name'] ?? '' ) ) ) );
		$id   = strtolower( (string) preg_replace( '/[^a-z0-9-]+/i', '-', (string) ( $r['id'] ?? '' ) ) );
		$id   = trim( substr( $id, 0, 40 ), '-' );
		if ( '' === $id && '' !== $name ) {
			$id = trim( substr( strtolower( (string) preg_replace( '/[^a-z0-9]+/i', '-', self::fold( $name ) ) ), 0, 40 ), '-' );
		}
		if ( '' === $id || '' === $name ) {
			return null;
		}
		$date  = static function ( $d ): string { return 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $d ) ? (string) $d : ''; };
		$cases = array_values( array_unique( array_filter( array_map( 'strval', (array) ( $r['cases'] ?? array() ) ), static function ( $c ) { return 1 === preg_match( '/^B145-\d{2}$/', $c ); } ) ) );
		return array( 'id' => $id, 'name' => mb_substr( $name, 0, 80 ), 'from' => $date( $r['from'] ?? '' ), 'to' => $date( $r['to'] ?? '' ), 'cases' => $cases, 'open' => ! array_key_exists( 'open', $r ) || ! empty( $r['open'] ) );
	}

	/** Open now: the switch on and today within [from, to] (site date). Pure over `$today`. */
	public static function round_open( array $r, string $today ): bool {
		if ( empty( $r['open'] ) ) {
			return false;
		}
		return ( '' === (string) $r['from'] || $today >= (string) $r['from'] ) && ( '' === (string) $r['to'] || $today <= (string) $r['to'] );
	}

	private static function round_by_id( string $id ): ?array {
		foreach ( self::rounds() as $r ) {
			if ( $r['id'] === $id ) {
				return $r;
			}
		}
		return null;
	}

	private static function today(): string {
		if ( isset( self::$readers['now'] ) ) {
			return (string) call_user_func( self::$readers['now'] );
		}
		return function_exists( 'wp_date' ) ? (string) wp_date( 'Y-m-d' ) : gmdate( 'Y-m-d' );
	}

	// ── the answer ──────────────────────────────────────────────────────

	/** marks the person can give (doc 55 §3): C1 + C2 from the verdict and reasons, C5 = stars 1–5 (optional). Pure. */
	public static function marks( string $verdict, array $reasons, int $stars = 0 ): array {
		$short = in_array( 'thieu_ket_qua', $reasons, true ) || in_array( 'chi_1_file', $reasons, true );
		if ( 'up' === $verdict ) {
			$m = array( 'C1' => 5, 'C2' => 5 );
		} elseif ( 'fix' === $verdict ) {
			$m = array( 'C1' => 3, 'C2' => $short ? 2 : 3 );
		} else {
			$m = array( 'C1' => 0, 'C2' => in_array( 'thieu_ket_qua', $reasons, true ) ? 1 : 3 );
		}
		if ( $stars >= 1 && $stars <= 5 ) {
			$m['C5'] = $stars;
		}
		return $m;
	}

	/**
	 * Build the case-feedback@1 body for the cell from the browser's answer and the SERVER's view of the person. Only whitelisted answer keys are read: any
	 * `role`, `respondent_role`, `principal`, `tenant`, `account_id`, `run_kind` the browser sends is ignored. @return array|WP_Error {body, note, bridge_id}
	 */
	public static function build( array $in, int $uid ) {
		if ( $uid <= 0 ) {
			return new WP_Error( 'auth_required', 'Cần đăng nhập.', array( 'status' => 401 ) );
		}
		$role  = self::role_of( $uid );
		$cid   = (string) ( $in['case'] ?? '' );
		$case  = self::case_of( $cid );
		if ( null === $case ) {
			return new WP_Error( 'case_unknown', 'Không có nghiệp vụ này trong hướng dẫn.', array( 'status' => 400, 'hint' => 'Tải lại trang Hướng dẫn.' ) );
		}
		$verdict = (string) ( $in['verdict'] ?? '' );
		if ( ! in_array( $verdict, self::VERDICTS, true ) ) {
			return new WP_Error( 'verdict_invalid', 'Chọn Làm được / Được một phần / Chưa được.', array( 'status' => 400, 'hint' => 'Bấm một trong ba nút.' ) );
		}
		$rid   = (string) ( $in['round'] ?? '' ) ?: 'always';
		$round = self::round_by_id( $rid );
		if ( null === $round || ! self::round_open( $round, self::today() ) ) {
			return new WP_Error( 'round_closed', 'Đợt khảo sát này đã đóng.', array( 'status' => 409, 'hint' => 'Chọn đợt "Thường xuyên" hoặc đợt đang mở.' ) );
		}
		if ( $round['cases'] && ! in_array( $cid, $round['cases'], true ) ) {
			return new WP_Error( 'case_not_in_round', 'Nghiệp vụ này không thuộc đợt khảo sát đang chọn.', array( 'status' => 409, 'hint' => 'Chọn đợt khác.' ) );
		}
		if ( ! self::is_answerable( $case, self::shipped(), $role ) ) {
			return new WP_Error( 'case_not_open', 'Nghiệp vụ này chưa mở để đánh giá.', array( 'status' => 409, 'hint' => 'Thẻ "Sắp có" hoặc chỉ dành cho người kiểm thử.' ) );
		}
		$closed  = array_map( 'strval', (array) ( self::pack()['reasons_closed_list'] ?? array() ) );
		$reasons = array_values( array_unique( array_intersect( array_map( 'strval', array_filter( (array) ( $in['reasons'] ?? array() ), 'is_scalar' ) ), $closed ) ) );
		$stars   = (int) ( $in['stars'] ?? 0 );
		$t       = is_array( $in['tested_on'] ?? null ) ? $in['tested_on'] : array();
		$channel = in_array( (string) ( $t['channel'] ?? '' ), self::CHANNELS, true ) ? (string) $t['channel'] : 'web';
		$numbers = self::visible_numbers( $uid );
		$bridge  = '';
		$acct    = null;
		if ( 'zalo_personal' === $channel ) {
			foreach ( $numbers as $n ) {
				if ( hash_equals( $n['account_ref'], (string) ( $t['account_ref'] ?? '' ) ) ) {
					$bridge = $n['bridge_id'];
					$acct   = $n['account_ref'];
				}
			}
			if ( '' === $bridge ) {
				return new WP_Error( 'account_not_visible', 'Bạn không có số Zalo này.', array( 'status' => 400, 'hint' => 'Chọn một số trong danh sách "Đã thử trên", hoặc TwinChat / Web.' ) );
			}
		}
		if ( '' === $bridge ) { // twinchat / web: the cell of the person's first number, else the site's first zalo-hub number (one tenant per site)
			$bridge = (string) ( $numbers[0]['bridge_id'] ?? ( self::accounts()[0]['bridge_id'] ?? '' ) );
		}
		$note = trim( (string) preg_replace( '/\s+/u', ' ', self::strip( (string) ( $in['note'] ?? '' ) ) ) );
		$note = mb_substr( $note, 0, self::NOTE_MAX );
		$body = array(
			'case'      => $cid,
			'verdict'   => $verdict,
			'marks'     => self::marks( $verdict, $reasons, $stars ),
			'reasons'   => $reasons,
			'has_note'  => '' !== $note,
			'survey'    => array( 'round' => $round['id'], 'respondent_role' => $role, 'tested_on' => array( 'channel' => $channel, 'account_ref' => $acct ) ),
			'principal' => self::principal( $uid ),
			'account_id' => $bridge,
			'run_kind'  => 'tester' === $role ? 'qa' : 'user',
			'channel'   => 'cg_guide',
			'build'     => array( 'site' => self::build_hash() ),
		);
		return array( 'body' => $body, 'note' => $note, 'bridge_id' => $bridge, 'case' => $case );
	}

	private static function build_hash(): string {
		if ( defined( 'BIZCITY_BUILD_HASH' ) ) {
			return substr( preg_replace( '/[^a-f0-9]/', '', strtolower( (string) BIZCITY_BUILD_HASH ) ), 0, 7 );
		}
		return substr( sha1( defined( 'BIZCITY_TWIN_AI_VERSION' ) ? (string) BIZCITY_TWIN_AI_VERSION : '0' ), 0, 7 );
	}

	/** One answer: cell first (the score), then the note row on the site. @return array|WP_Error */
	public static function submit( array $in, int $uid ) {
		$b = self::build( $in, $uid );
		if ( is_wp_error( $b ) ) {
			return $b;
		}
		if ( '' === $b['bridge_id'] ) {
			return new WP_Error( 'no_cell', 'Site chưa nối trợ lý (chưa có số Zalo chạy Zalo Hub).', array( 'status' => 409, 'hint' => 'Nối một số Zalo trước, rồi trả lời khảo sát.' ) );
		}
		$res = self::hub( 'POST', 'case-feedback', $b['bridge_id'], array(), $b['body'] );
		if ( empty( $res['ok'] ) || ! empty( $res['_degraded'] ) ) {
			$code = preg_replace( '/[^a-z0-9_]/', '', strtolower( (string) ( $res['error'] ?? $res['code'] ?? 'hub_unavailable' ) ) );
			return new WP_Error( '' !== $code ? $code : 'hub_unavailable', 'Chưa lưu được câu trả lời (trợ lý không trả lời).', array( 'status' => in_array( $code, array( 'case_unknown', 'verdict_invalid' ), true ) ? 400 : 502, 'hint' => 'Bấm lại sau ít phút — câu trả lời chưa bị mất trên trang.' ) );
		}
		$note_ref = '';
		if ( '' !== $b['note'] ) {
			$row      = self::record( array(
				'owner_user_id'   => $uid,
				'kind'            => self::NOTE_KIND,
				'title'           => mb_substr( 'Ghi chú: ' . (string) ( $b['case']['title'] ?? $b['body']['case'] ), 0, 200 ),
				'summary'         => $b['note'],
				'card'            => array( 'case' => $b['body']['case'], 'round' => $b['body']['survey']['round'], 'verdict' => $b['body']['verdict'], 'note' => $b['note'] ),
				'tags'            => array(),
				'source_type'     => 'cg_guide',
				'origin'          => array( 'surface' => 'cg_guide', 'ref' => $b['body']['case'] ),
				'idempotency_key' => 'cgfb:' . $b['body']['survey']['round'] . ':' . $b['body']['case'] . ':' . $uid . ':' . substr( hash( 'sha256', $b['note'] ), 0, 12 ),
				'by'              => array( 'role' => $b['body']['survey']['respondent_role'] ),
			) );
			$note_ref = is_array( $row ) ? (string) ( $row['ref_code'] ?? '' ) : '';
		}
		return array( 'ok' => true, 'id' => (int) ( $res['id'] ?? 0 ), 'case' => $b['body']['case'], 'verdict' => $b['body']['verdict'], 'round' => $b['body']['survey']['round'], 'respondent_role' => $b['body']['survey']['respondent_role'], 'linked' => '' !== (string) ( $res['turn_ref'] ?? '' ), 'supersedes' => $res['supersedes'] ?? null, 'note_saved' => '' !== $note_ref );
	}

	/** GET /guide/cases view: pack + ready / answerable + my answers. */
	public static function view( string $rid, int $uid ): array {
		$role    = self::role_of( $uid );
		$round   = self::round_by_id( '' !== $rid ? $rid : 'always' ) ?? self::DEFAULT_ROUND;
		$numbers = self::visible_numbers( $uid );
		$bridge  = (string) ( $numbers[0]['bridge_id'] ?? ( self::accounts()[0]['bridge_id'] ?? '' ) );
		$cell    = '' !== $bridge ? self::hub( 'GET', 'case-scoreboard', $bridge, array( 'principal' => self::principal( $uid ), 'round' => $round['id'], 'days' => 30 ), array() ) : array();
		$cell_ok = ! empty( $cell ) && empty( $cell['_degraded'] ) && false !== ( $cell['ok'] ?? true ) && is_array( $cell['cases'] ?? $cell['mine'] ?? null );
		$shipped = self::shipped( $cell_ok ? $cell : array() );
		$mine    = $cell_ok && is_array( $cell['mine'] ?? null ) ? array_filter( $cell['mine'], static function ( $v ) { return in_array( (string) $v, self::VERDICTS, true ); } ) : array();
		$cases   = array();
		foreach ( (array) self::pack()['cases'] as $c ) {
			if ( ! is_array( $c ) || ( $round['cases'] && ! in_array( (string) $c['case'], $round['cases'], true ) ) ) {
				continue;
			}
			$c['ready']      = self::is_ready( $c, $shipped );
			$c['answerable'] = self::is_answerable( $c, $shipped, $role ) && self::round_open( $round, self::today() );
			$c['mine']       = $mine[ $c['case'] ] ?? null;
			$cases[]         = $c;
		}
		$opts = array();
		foreach ( $numbers as $n ) {
			$opts[] = array( 'channel' => 'zalo_personal', 'account_ref' => $n['account_ref'], 'label' => $n['label'] );
		}
		$opts[] = array( 'channel' => 'twinchat', 'account_ref' => null, 'label' => 'TwinChat' );
		$opts[] = array( 'channel' => 'web', 'account_ref' => null, 'label' => 'Web' );
		$pack   = self::pack();
		unset( $pack['cases'], $pack['_stamp'] );
		return $pack + array(
			'round'             => $round + array( 'open_now' => self::round_open( $round, self::today() ) ),
			'rounds'            => array_values( array_filter( self::rounds(), static function ( $r ) { return self::round_open( $r, self::today() ); } ) ),
			'respondent_role'   => $role,
			'tested_on_options' => $opts,
			'cases'             => $cases,
			'answered'          => count( array_filter( $cases, static function ( $c ) { return null !== $c['mine'] && $c['answerable']; } ) ),
			'answerable_total'  => count( array_filter( $cases, static function ( $c ) { return $c['answerable']; } ) ),
			'cell_ok'           => $cell_ok,
		) + ( self::can_manage( $uid ) && $cell_ok && is_array( $cell['cases'] ?? null ) ? array( 'results' => $cell['cases'] ) : array() ); // tab "Kết quả": site totals per case / role, never per person (doc 56 §7)
	}

	// ── REST ────────────────────────────────────────────────────────────

	private static function respond( $res ): WP_REST_Response {
		if ( is_wp_error( $res ) ) {
			$d = (array) $res->get_error_data();
			return new WP_REST_Response( array( 'ok' => false, 'code' => $res->get_error_code(), 'message' => $res->get_error_message(), 'hint' => (string) ( $d['hint'] ?? '' ), 'help_code' => 'S145-L10-' . (int) ( $d['status'] ?? 400 ) ), (int) ( $d['status'] ?? 400 ) );
		}
		return new WP_REST_Response( $res, 200 );
	}

	private static function body( WP_REST_Request $r ): array {
		$b = $r->get_json_params();
		return is_array( $b ) ? $b : (array) $r->get_body_params();
	}

	public static function rest_cases( WP_REST_Request $r ): WP_REST_Response {
		return self::respond( self::view( (string) $r->get_param( 'round' ), self::uid() ) );
	}

	public static function rest_feedback( WP_REST_Request $r ): WP_REST_Response {
		return self::respond( self::submit( self::body( $r ), self::uid() ) );
	}

	public static function rest_rounds( WP_REST_Request $r ): WP_REST_Response {
		return self::respond( array( 'rounds' => self::rounds(), 'today' => self::today() ) );
	}

	/** Upsert one round (the default "always" keeps its id; it can be renamed but not closed). */
	public static function rest_round_save( WP_REST_Request $r ): WP_REST_Response {
		return self::respond( self::save_round( self::body( $r ) ) );
	}

	/** @return array|WP_Error {rounds} */
	public static function save_round( array $in ) {
		$round = self::clean_round( $in );
		if ( null === $round ) {
			return new WP_Error( 'round_invalid', 'Đợt cần tên.', array( 'status' => 400, 'hint' => 'Ví dụ: "Pilot 0.145 — tuần 1".' ) );
		}
		$saved = array();
		foreach ( (array) self::option( self::ROUNDS_OPTION, array() ) as $x ) {
			$x = self::clean_round( is_array( $x ) ? $x : array() );
			if ( null !== $x && $x['id'] !== $round['id'] ) {
				$saved[] = $x;
			}
		}
		if ( 'always' === $round['id'] ) {
			$round['open'] = true; // "Thường xuyên" is always open
			$round['from'] = '';
			$round['to']   = '';
		}
		$saved[] = $round;
		if ( count( $saved ) > self::ROUNDS_MAX ) {
			return new WP_Error( 'round_limit', 'Đã đủ 30 đợt.', array( 'status' => 409, 'hint' => 'Đóng / xoá bớt đợt cũ.' ) );
		}
		self::save_option( self::ROUNDS_OPTION, $saved );
		return array( 'rounds' => self::rounds() );
	}

	// ── seams ───────────────────────────────────────────────────────────

	private static function hub( string $method, string $path, string $bridge, array $query, array $body ): array {
		if ( isset( self::$readers['hub'] ) ) {
			return (array) call_user_func( self::$readers['hub'], $method, $path, $bridge, $query, $body );
		}
		if ( ! class_exists( 'BizCity_Zalo_Personal_Hub_Client' ) || ! method_exists( 'BizCity_Zalo_Personal_Hub_Client', 'guide_call' ) ) {
			return array( 'ok' => false, '_degraded' => true, 'code' => 'hub_client_missing' );
		}
		return (array) BizCity_Zalo_Personal_Hub_Client::instance()->guide_call( $method, $path, $bridge, $query, $body );
	}

	private static function record( array $entry ) {
		if ( isset( self::$readers['record'] ) ) {
			return call_user_func( self::$readers['record'], $entry );
		}
		return function_exists( 'bizcity_journal_record' ) ? bizcity_journal_record( $entry ) : new WP_Error( 'journal_unavailable', 'Sổ chưa sẵn sàng.' );
	}

	private static function option( string $name, $default ) {
		if ( isset( self::$readers['option'] ) ) {
			$v = call_user_func( self::$readers['option'], $name );
			return null === $v ? $default : $v;
		}
		return function_exists( 'get_option' ) ? get_option( $name, $default ) : $default;
	}

	private static function save_option( string $name, $value ): void {
		if ( isset( self::$readers['save_option'] ) ) {
			call_user_func( self::$readers['save_option'], $name, $value );
			return;
		}
		if ( function_exists( 'update_option' ) ) {
			update_option( $name, $value, false );
		}
	}

	private static function fold( string $s ): string {
		return class_exists( 'BizCity_Journal_Entry_Meta' ) ? BizCity_Journal_Entry_Meta::fold( $s ) : strtolower( $s );
	}

	/** wp_strip_all_tags when WordPress is loaded, strip_tags otherwise (unit tests). */
	private static function strip( string $s ): string {
		return function_exists( 'wp_strip_all_tags' ) ? (string) wp_strip_all_tags( $s ) : trim( strip_tags( $s ) );
	}
}
