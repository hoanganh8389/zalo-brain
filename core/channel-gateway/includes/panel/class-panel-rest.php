<?php
/**
 * bizcity-panel/v1 — what an owner panel opened from a Zalo link may call (PHASE-0.122 doc 20 §4.5, R-PL-6).
 *
 * A thin front: every route calls the service that already exists; nothing is re-implemented. Auth = the panel cookie (never a
 * WordPress login) + header `X-BizCity-Panel: 1` (forces a CORS preflight, so no other site can drive it) + the panel's group.
 * Inside the callback the panel's person becomes the current user for THIS request only, so the old services run unchanged and
 * keep their own permission checks (quyền chỉ bớt: a person without the channel grant still cannot connect).
 *
 *   GET  session                         every group   panel, group, label, who (masked), expires_at, panels of the group
 *   GET  key  · POST key/reset           every group   open sessions / waiting links / log · rotate the key now
 *   GET  connect/status                  connect       BizCity_Connect_Composio_REST (status of MY logins)
 *   POST connect/start {toolkit}         connect       start a Composio login, callback = this panel page
 *   POST connect/refresh {connected_account_id}  connect
 *   PUT  connect/default {connected_account_id}  connect
 *   GET  jobs · GET jobs/{id}            jobs          BizCity_Job_View_REST
 *   GET  jobs/next                       jobs          the person's calendar for 14 days (cell reminders mirrored as cell_job + site events) — read only
 *   GET  brain/overview · brain/find · brain/{id}   brain   BizCity_Journal_REST (the person's own register)
 *   PATCH brain/{id} {pinned|review}     brain         nothing else (delete = "XOÁ <mã>" in Zalo, D122-14)
 *   GET  brain/{id}/file                 brain         15-minute signed link of the private original
 * [2026-10-18 07:55 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.122 S122-F2/F3 — jobs.next, brain.docs, brain.notes.
 *
 * [2026-10-18 05:10 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.122 S122-P3 — new file. Loaded once by require_once in bootstrap.php.
 *
 * @package BizCity_Twin_AI
 * @subpackage Channel_Gateway
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_Panel_REST {

	const NS     = 'bizcity-panel/v1';
	const HEADER = 'x-bizcity-panel';

	/** @var array|null the session of this request, set by the permission callback */
	private static $session = null;

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes(): void {
		$routes = array(
			array( '/session', 'GET', 'get_session', '' ),
			array( '/key', 'GET', 'get_key', '' ),
			array( '/key/reset', 'POST', 'post_key_reset', '' ),
			array( '/connect/status', 'GET', 'connect_status', 'connect' ),
			array( '/connect/start', 'POST', 'connect_start', 'connect' ),
			array( '/connect/refresh', 'POST', 'connect_refresh', 'connect' ),
			array( '/connect/default', 'PUT', 'connect_default', 'connect' ),
			array( '/connect/facebook/pages', 'GET', 'fb_pages', 'connect' ), // [2026-10-18 10:00 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.122 W4 — pick the Fanpage(s) (shop owner / admin only)
			array( '/connect/facebook/pages', 'PUT', 'fb_pages_select', 'connect' ),
			array( '/connect/facebook/pages/(?P<page>[0-9]{5,30})/mode', 'PUT', 'fb_page_mode', 'connect' ),
			array( '/jobs', 'GET', 'jobs', 'jobs' ),
			array( '/jobs/next', 'GET', 'jobs_next', 'jobs' ), // [2026-10-18 07:55 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.122 S122-F2 (registered before jobs/{id})
			array( '/brain/overview', 'GET', 'brain_overview', 'brain' ), // [2026-10-18 07:55 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.122 S122-F3
			array( '/brain/find', 'GET', 'brain_find', 'brain' ),
			array( '/brain/(?P<id>[0-9]+)', 'GET', 'brain_get', 'brain' ),
			array( '/brain/(?P<id>[0-9]+)', 'PATCH', 'brain_patch', 'brain' ),
			array( '/brain/(?P<id>[0-9]+)/file', 'GET', 'brain_file', 'brain' ),
			array( '/brain/note', 'POST', 'brain_note', 'brain' ), // [2026-10-18 09:00 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.122 S122-F4 — a text note (R1)
			array( '/brain/(?P<id>[0-9]+)/title', 'PUT', 'brain_title', 'brain' ), // S122-F4 — rename an entry (R1)
			array( '/users', 'GET', 'users', 'users' ), // [2026-10-18 09:00 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.122 S122-F5 — read only; every change goes through Zalo (R3, two steps)
			array( '/jobs/(?P<id>job_[0-9A-Za-z]{10,40})/answer', 'POST', 'job_answer', 'jobs' ),
			array( '/jobs/(?P<id>job_[0-9A-Za-z]{10,40})/control', 'POST', 'job_control', 'jobs' ), // [2026-10-21 09:05 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.125 C3 (D125-19) — pause / resume / cancel / skip / note from the Quest Map // [2026-10-21 11:21 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.125 Q1.7 C2 (Q125-17) — answer the background job from the Quest Map
			array( '/jobs/(?P<id>job_[0-9A-Za-z]{10,40})/feedback', 'POST', 'job_feedback', 'jobs' ), // [2026-10-21 11:04 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.127 S127-C3 — 👍 / 👎 / "sửa…" on a finished job from the Quest Map
			array( '/jobs/(?P<id>[A-Za-z0-9_.:\-]{4,80})', 'GET', 'job', 'jobs' ),
		);
		foreach ( $routes as $r ) {
			$group = $r[3];
			register_rest_route( self::NS, $r[0], array(
				'methods'             => $r[1],
				'callback'            => array( __CLASS__, $r[2] ),
				'permission_callback' => static function ( WP_REST_Request $request ) use ( $group ) { return self::perm( $request, $group ); },
			) );
		}
	}

	/**
	 * Live session + our header + (when the route belongs to a group) that group. Pure decision in `allows()`; this binds the request.
	 *
	 * @return true|WP_Error
	 */
	public static function perm( WP_REST_Request $request, string $group ) {
		$s = BizCity_Panel_Gate::current_session();
		$v = self::allows( $s, (string) $request->get_header( self::HEADER ), $group );
		if ( true !== $v ) {
			return new WP_Error( $v, 'expired' === $v ? 'Bảng nhanh đã hết hạn hoặc đã bị khoá. Nhắn trợ lý trên Zalo để lấy link mới.' : 'Bảng nhanh này không mở được mục này.', array( 'status' => 'expired' === $v ? 401 : 403 ) );
		}
		self::$session = $s;
		wp_set_current_user( (int) $s['user_id'] );
		// [2026-10-18 11:00 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.122 review fix — the panel person is the current user for THIS route only; no later hook of the same request runs as them
		static $hooked = false;
		if ( ! $hooked ) {
			$hooked = true;
			add_filter( 'rest_post_dispatch', static function ( $result ) {
				wp_set_current_user( 0 );
				return $result;
			}, 999 );
		}
		return true;
	}

	/** Pure: true, or the refusal code (`expired` · `bad_header` · `wrong_group`). */
	public static function allows( ?array $session, string $header, string $group ) {
		if ( ! $session ) {
			return 'expired';
		}
		if ( '1' !== $header ) {
			return 'bad_header';
		}
		if ( '' !== $group && (string) ( $session['group'] ?? '' ) !== $group ) {
			return 'wrong_group';
		}
		return true;
	}

	// ── session / key ────────────────────────────────────────────────────

	public static function get_session(): WP_REST_Response {
		$s     = self::$session;
		$cat   = BizCity_Panel_Link::catalog();
		$group = (string) $s['group'];
		$mine  = array();
		foreach ( $cat as $id => $row ) {
			if ( BizCity_Panel_Link::group_allows( $group, $id ) ) {
				$mine[] = array( 'id' => $id, 'label' => $row['label'], 'body' => $row['body'], 'toolkit' => $row['toolkit'] );
			}
		}
		return new WP_REST_Response( array(
			'ok'         => true,
			'contract'   => 'owner-panel@1.0.0',
			'panel'      => (string) $s['panel'],
			'group'      => $group,
			'label'      => (string) ( $cat[ (string) $s['panel'] ]['label'] ?? $s['panel'] ),
			'who'        => BizCity_Panel_Gate::masked_name( (int) $s['user_id'] ),
			'expires_at' => (int) $s['expires_at'],
			'panels'     => $mine,
			'focus'      => (string) ( $s['focus'] ?? '' ), // [2026-10-20 03:30 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.125 Q1 — the job of a Quest Map link
			'zalo_hint'  => self::suggestions( (string) $s['panel'] ),
		), 200 );
	}

	public static function get_key(): WP_REST_Response {
		return new WP_REST_Response( array( 'ok' => true ) + BizCity_Panel_Link::state( (int) self::$session['user_id'], (string) self::$session['h'] ), 200 );
	}

	public static function post_key_reset(): WP_REST_Response {
		BizCity_Panel_Link::reset( (int) self::$session['user_id'], 'user' );
		BizCity_Panel_Gate::set_cookie( '', 0 );
		return new WP_REST_Response( array( 'ok' => true, 'reset' => true, 'message' => 'Đã đổi khoá. Mọi link và trang đang mở đều khoá lại. Cần mở lại thì nhắn trợ lý.' ), 200 );
	}

	// ── connect (Composio through the cell, same code as the Channel Gateway screen) ──

	public static function connect_status(): WP_REST_Response {
		$deny = self::channel_denied();
		return $deny ?: BizCity_Connect_Composio_REST::pipe( self::inner( 'GET', '/status' ) );
	}

	public static function connect_start( WP_REST_Request $request ): WP_REST_Response {
		$deny = self::channel_denied();
		if ( $deny ) {
			return $deny;
		}
		$toolkit = (string) ( ( (array) $request->get_json_params() )['toolkit'] ?? '' );
		if ( ! isset( BizCity_Panel_Link::catalog()[ 'connect.' . $toolkit ] ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'panel_unknown', 'message' => 'Ứng dụng này chưa nối được qua bảng nhanh.' ), 400 );
		}
		return BizCity_Connect_Composio_REST::start_connect( $toolkit, BizCity_Panel_Gate::open_url( 'connect.' . $toolkit, true ) );
	}

	public static function connect_refresh( WP_REST_Request $request ): WP_REST_Response {
		$deny = self::channel_denied();
		if ( $deny ) {
			return $deny;
		}
		// [2026-10-18 07:05 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.122 S122-B5 — the owner panel asks the cell to send the small-win note to Zalo when this login just became ACTIVE (once per login, cell-side)
		$body    = self::only( $request, array( 'connected_account_id', 'toolkit' ) );
		$toolkit = (string) ( $body['toolkit'] ?? '' );
		unset( $body['toolkit'] );
		$body['notify_owner'] = true;
		$body['suggestions']  = self::suggestions( 'connect.' . $toolkit );
		// Facebook: the note waits for the Fanpage pick (W4) — the cell answers small_win=needs_pages here
		return BizCity_Connect_Composio_REST::pipe( self::inner( 'POST', '/refresh', $body ) );
	}

	public static function connect_default( WP_REST_Request $request ): WP_REST_Response {
		$deny = self::channel_denied();
		return $deny ?: BizCity_Connect_Composio_REST::pipe( self::inner( 'PUT', '/default', self::only( $request, array( 'connected_account_id', 'toolkit' ) ) ) );
	}

	// ── Facebook Fanpage (PHASE-0.122 W4): the shop's login ⇒ only the site manager picks pages and the page mode ──

	/** Live pages of one Facebook login + which are selected. */
	public static function fb_pages( WP_REST_Request $request ): WP_REST_Response {
		$deny = self::shop_denied();
		if ( $deny ) {
			return $deny;
		}
		$conn = self::clean_id( (string) $request->get_param( 'connection' ) );
		if ( '' === $conn ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'invalid_param', 'message' => 'Thiếu tài khoản Facebook cần chọn trang.' ), 400 );
		}
		$r = self::inner( 'GET', '/facebook/pages' );
		$r->set_param( 'connection', $conn );
		return BizCity_Connect_Composio_REST::pipe( $r );
	}

	/** Select pages; the cell sends the small-win note to Zalo once a page is picked (once per login). */
	public static function fb_pages_select( WP_REST_Request $request ): WP_REST_Response {
		$deny = self::shop_denied();
		if ( $deny ) {
			return $deny;
		}
		$p    = (array) $request->get_json_params();
		$conn = self::clean_id( (string) ( $p['connected_account_id'] ?? '' ) );
		$ids  = self::page_ids( $p['page_ids'] ?? array() );
		if ( '' === $conn || null === $ids ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'invalid_param', 'message' => 'Chọn ít nhất một Fanpage hợp lệ.' ), 400 );
		}
		return BizCity_Connect_Composio_REST::pipe( self::inner( 'PUT', '/facebook/pages', array( 'connected_account_id' => $conn, 'page_ids' => $ids, 'notify_owner' => true, 'suggestions' => self::suggestions( 'connect.facebook' ) ) ) );
	}

	/** Page mode from the panel: only the two that never send to a customer — manual (nothing) and assisted (the cell drafts, the owner approves). Auto-send stays on the web screen. */
	public static function fb_page_mode( WP_REST_Request $request ): WP_REST_Response {
		$deny = self::shop_denied();
		if ( $deny ) {
			return $deny;
		}
		$mode = (string) ( ( (array) $request->get_json_params() )['mode'] ?? '' );
		if ( ! in_array( $mode, array( 'manual', 'assisted' ), true ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'invalid_param', 'message' => 'Bảng nhanh chỉ bật "chỉ xem" hoặc "tự soạn nháp". Chế độ tự gửi bật ở trang Công cụ trên website.' ), 400 );
		}
		return BizCity_Connect_Composio_REST::pipe( self::inner( 'PUT', '/pages/' . (string) $request['page'] . '/auto', array( 'mode' => $mode ) ) );
	}

	/** The shop's Facebook login belongs to the site manager (the cell refuses anyone else too). */
	private static function shop_denied(): ?WP_REST_Response {
		$deny = self::channel_denied();
		if ( $deny ) {
			return $deny;
		}
		if ( ! BizCity_Connect_REST::can_manage() ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'admin_only', 'message' => 'Fanpage là của cả cửa hàng: chỉ quản trị website chọn trang và đổi chế độ.', 'hint' => 'Nhờ chủ cửa hàng / quản trị làm bước này.' ), 403 );
		}
		return null;
	}

	/** Pure: a connected_account_id (Composio ca_…) or ''. */
	public static function clean_id( string $id ): string {
		return 1 === preg_match( '/^[A-Za-z0-9_-]{6,80}$/', $id ) ? $id : '';
	}

	/** Pure: 1–50 numeric Facebook page ids (deduplicated), else null. @return string[]|null */
	public static function page_ids( $raw ): ?array {
		if ( ! is_array( $raw ) ) {
			return null;
		}
		$out = array();
		foreach ( $raw as $id ) {
			if ( is_scalar( $id ) && 1 === preg_match( '/^[0-9]{5,30}$/', (string) $id ) ) {
				$out[ (string) $id ] = (string) $id;
			}
		}
		return $out && count( $out ) <= 50 ? array_values( $out ) : null;
	}

	// ── jobs ─────────────────────────────────────────────────────────────

	public static function jobs( WP_REST_Request $request ): WP_REST_Response {
		return BizCity_Job_View_REST::list_jobs( $request );
	}

	public static function job( WP_REST_Request $request ): WP_REST_Response {
		return BizCity_Job_View_REST::get_job( $request );
	}

	/** Next 14 days of the person's calendar. */
	/**
	 * [2026-10-21 11:21 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.125 Q1.7 C2 (Q125-16/17) — the owner answers their background job on the Quest Map:
	 * the open question (`ask_id` + option `n` and/or `text`) and/or corrections of the "em tạm hiểu" assumptions (`asks`). Rides the composio pipe
	 * (site → Hub → cell, `_user_ref` of the panel person); the CELL refuses anyone who did not give the job.
	 */
	public static function job_answer( WP_REST_Request $request ): WP_REST_Response {
		$p    = (array) $request->get_json_params();
		$body = array( 'job_id' => (string) $request->get_param( 'id' ) );
		if ( isset( $p['ask_id'] ) && is_string( $p['ask_id'] ) && 1 === preg_match( '/^ask_[0-9A-Za-z_.-]{3,120}$/', $p['ask_id'] ) ) {
			$body['ask_id'] = $p['ask_id'];
		}
		if ( isset( $p['n'] ) && is_numeric( $p['n'] ) ) {
			$body['n'] = max( 0, min( 9, (int) $p['n'] ) );
		}
		if ( isset( $p['text'] ) && is_string( $p['text'] ) && '' !== trim( $p['text'] ) ) {
			$body['text'] = mb_substr( sanitize_textarea_field( $p['text'] ), 0, 1500 );
		}
		foreach ( array_slice( is_array( $p['asks'] ?? null ) ? $p['asks'] : array(), 0, 3 ) as $a ) {
			if ( is_array( $a ) && 1 === preg_match( '/^m[1-9]$/', (string) ( $a['id'] ?? '' ) ) && is_string( $a['text'] ?? null ) && '' !== trim( $a['text'] ) ) {
				$body['asks'][] = array( 'id' => (string) $a['id'], 'text' => mb_substr( sanitize_text_field( $a['text'] ), 0, 300 ) );
			}
		}
		if ( ! isset( $body['ask_id'] ) && empty( $body['asks'] ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'invalid_param', 'message' => 'Chưa có câu trả lời nào.' ), 400 );
		}
		return BizCity_Connect_Composio_REST::pipe( self::inner( 'POST', '/quest/answer', $body ) );
	}

	/**
	 * [2026-10-21 09:05 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.125 C3 (D125-19) — the Quest Map's buttons and prompt box: `action` pause|resume|cancel|skip|note,
	 * `step_id` (skip), `text` (note). Same composio pipe as job_answer; the CELL refuses anyone who did not give the job.
	 * [2026-10-21 09:07 PM Johnny Chu - Chu Hoàng Anh] C3 — + `redo` (a finished step again, `step_id` + optional `text` = what to change).
	 */
	public static function job_control( WP_REST_Request $request ): WP_REST_Response {
		$p      = (array) $request->get_json_params();
		$action = (string) ( $p['action'] ?? '' );
		if ( ! in_array( $action, array( 'pause', 'resume', 'cancel', 'skip', 'redo', 'note' ), true ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'invalid_param', 'message' => 'Thao tác không hợp lệ.' ), 400 );
		}
		$body = array( 'job_id' => (string) $request->get_param( 'id' ), 'action' => $action );
		if ( in_array( $action, array( 'skip', 'redo' ), true ) && is_string( $p['step_id'] ?? null ) && 1 === preg_match( '/^[a-z][a-z0-9_]{0,39}$/', $p['step_id'] ) ) {
			$body['step_id'] = $p['step_id'];
		}
		if ( in_array( $action, array( 'note', 'redo' ), true ) && is_string( $p['text'] ?? null ) ) {
			$body['text'] = mb_substr( sanitize_textarea_field( $p['text'] ), 0, 500 );
		}
		return BizCity_Connect_Composio_REST::pipe( self::inner( 'POST', '/quest/control', $body ) );
	}

	/**
	 * [2026-10-21 11:04 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.127 S127-C3 (D127-8) — the owner's feedback on a FINISHED job from the Quest Map: `verdict` up|down|fix,
	 * `text` only with fix (3–500 chars, what to change). Same composio pipe as job_control; the CELL refuses anyone who did not give the job,
	 * a job that is not finished, and a second answer (one per job from the Map).
	 */
	public static function job_feedback( WP_REST_Request $request ): WP_REST_Response {
		$p       = (array) $request->get_json_params();
		$verdict = (string) ( $p['verdict'] ?? '' );
		if ( ! in_array( $verdict, array( 'up', 'down', 'fix' ), true ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'invalid_param', 'message' => 'Phản hồi không hợp lệ.' ), 400 );
		}
		$body = array( 'job_id' => (string) $request->get_param( 'id' ), 'verdict' => $verdict );
		if ( 'fix' === $verdict ) {
			$text = is_string( $p['text'] ?? null ) ? trim( mb_substr( sanitize_textarea_field( $p['text'] ), 0, 500 ) ) : '';
			if ( mb_strlen( $text ) < 3 ) {
				return new WP_REST_Response( array( 'ok' => false, 'code' => 'invalid_param', 'message' => 'Anh ghi điều cần sửa giúp em nhé.' ), 400 );
			}
			$body['text'] = $text;
		}
		return BizCity_Connect_Composio_REST::pipe( self::inner( 'POST', '/quest/feedback', $body ) );
	}

	public static function jobs_next(): WP_REST_Response {
		if ( ! class_exists( 'BizCity_Scheduler_Manager' ) ) {
			return new WP_REST_Response( array( 'ok' => true, 'items' => array(), 'note' => 'scheduler_off' ), 200 );
		}
		$now  = current_time( 'mysql' );
		$to   = gmdate( 'Y-m-d H:i:s', (int) strtotime( $now ) + 14 * DAY_IN_SECONDS );
		$rows = BizCity_Scheduler_Manager::instance()->get_events( get_current_user_id(), $now, $to, 'active' );
		$tz   = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
		return new WP_REST_Response( array( 'ok' => true, 'items' => self::next_rows( $rows, $tz ) ), 200 );
	}

	/** Pure: calendar rows ⇒ [{id, title, at (unix), kind}] soonest first, ≤ 30. `start_at` is the site's wall clock. */
	public static function next_rows( array $rows, DateTimeZone $tz ): array {
		$out = array();
		foreach ( $rows as $r ) {
			$r = (array) $r;
			try {
				$at = ( new DateTimeImmutable( (string) ( $r['start_at'] ?? '' ), $tz ) )->getTimestamp();
			} catch ( Exception $e ) {
				continue;
			}
			$type  = (string) ( $r['event_type'] ?? '' );
			$out[] = array(
				'id'    => (int) ( $r['id'] ?? 0 ),
				'title' => mb_substr( (string) ( $r['title'] ?? '' ), 0, 120 ),
				'at'    => $at,
				'kind'  => 'cell_job' === $type || 'cell' === (string) ( $r['dispatcher'] ?? '' ) ? 'Trợ lý nhắc' : ( 'fb_post' === $type || 'web_post' === $type ? 'Lịch đăng bài' : 'Lịch' ),
			);
		}
		usort( $out, static function ( $a, $b ) { return $a['at'] <=> $b['at']; } );
		return array_slice( $out, 0, 30 );
	}

	// ── brain (the person's own register, BizCity_Journal_REST) ───────────

	public static function brain_overview(): WP_REST_Response {
		return class_exists( 'BizCity_Journal_REST' ) ? BizCity_Journal_REST::overview( new WP_REST_Request( 'GET', '/bizcity-knowledge/v2/documents/overview' ) ) : self::brain_off();
	}

	public static function brain_find( WP_REST_Request $request ): WP_REST_Response {
		if ( ! class_exists( 'BizCity_Journal_REST' ) ) {
			return self::brain_off();
		}
		$r = new WP_REST_Request( 'GET', '/bizcity-knowledge/v2/journal/find' );
		foreach ( array( 'kind', 'status', 'q', 'offset' ) as $k ) {
			$v = $request->get_param( $k );
			if ( null !== $v && '' !== $v && is_scalar( $v ) ) {
				$r->set_param( $k, substr( sanitize_text_field( (string) $v ), 0, 80 ) );
			}
		}
		$r->set_param( 'limit', 30 );
		return BizCity_Journal_REST::find( $r );
	}

	public static function brain_get( WP_REST_Request $request ): WP_REST_Response {
		if ( ! class_exists( 'BizCity_Journal_REST' ) ) {
			return self::brain_off();
		}
		$r = new WP_REST_Request( 'GET', '/bizcity-knowledge/v2/journal/' . (int) $request['id'] );
		$r->set_param( 'id', (int) $request['id'] );
		return BizCity_Journal_REST::get( $r );
	}

	/** Only pinned / review from the panel. */
	public static function brain_patch( WP_REST_Request $request ): WP_REST_Response {
		if ( ! class_exists( 'BizCity_Journal_REST' ) ) {
			return self::brain_off();
		}
		$p    = (array) $request->get_json_params();
		$body = array();
		foreach ( array( 'pinned', 'review' ) as $k ) {
			if ( array_key_exists( $k, $p ) ) {
				$body[ $k ] = ! empty( $p[ $k ] );
			}
		}
		if ( ! $body ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'invalid_param', 'message' => 'Bảng nhanh chỉ ghim / đánh dấu cần xem lại.' ), 400 );
		}
		$r = new WP_REST_Request( 'PATCH', '/bizcity-knowledge/v2/journal/' . (int) $request['id'] );
		$r->set_param( 'id', (int) $request['id'] );
		$r->set_header( 'content-type', 'application/json' );
		$r->set_body( (string) wp_json_encode( $body ) );
		return BizCity_Journal_REST::patch( $r );
	}

	/** The person's own original, as a 15-minute signed link (never another person's). */
	public static function brain_file( WP_REST_Request $request ): WP_REST_Response {
		$e   = class_exists( 'BizCity_Journal_Index' ) ? BizCity_Journal_Index::get( (int) $request['id'], get_current_user_id() ) : null;
		$aid = is_array( $e ) ? (int) ( $e['original']['attachment_id'] ?? 0 ) : 0;
		if ( $aid <= 0 || ! class_exists( 'BizCity_KG_Private_File_Store' ) || ! BizCity_KG_Private_File_Store::has_bytes( $aid ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'no_file', 'message' => 'Mục này không có file gốc.' ), 404 );
		}
		return new WP_REST_Response( array( 'ok' => true, 'url' => BizCity_KG_Private_File_Store::signed_url( $aid ), 'expires_in' => BizCity_KG_Private_File_Store::SIGNED_TTL ), 200 );
	}

	/**
	 * [2026-10-18 09:00 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.122 S122-F4 — a text note into the person's own register through the ONE write door
	 * (`bizcity_journal_record`, R-JN-17), kind `common.note`. Idempotent per request id the panel sends.
	 */
	public static function brain_note( WP_REST_Request $request ): WP_REST_Response {
		$p    = (array) $request->get_json_params();
		$note = self::note_input( (string) ( $p['title'] ?? '' ), (string) ( $p['text'] ?? '' ) );
		if ( is_string( $note ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'invalid_param', 'message' => $note ), 400 );
		}
		if ( ! function_exists( 'bizcity_journal_record' ) ) {
			return self::brain_off();
		}
		$req = substr( preg_replace( '/[^A-Za-z0-9_-]/', '', (string) ( $p['request_id'] ?? '' ) ), 0, 40 );
		$r   = bizcity_journal_record( array(
			'kind'            => 'common.note',
			'title'           => $note['title'],
			'summary'         => $note['text'],
			'owner_user_id'   => get_current_user_id(),
			'source_type'     => 'web_upload', // closed set (R-JN-19); the panel is a web door
			'origin'          => array( 'surface' => 'owner_panel', 'ref' => '' ),
			'idempotency_key' => 'panel_note:' . get_current_user_id() . ':' . ( '' !== $req ? $req : md5( $note['title'] . '|' . $note['text'] ) ),
		) );
		if ( is_wp_error( $r ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => $r->get_error_code(), 'message' => $r->get_error_message() ), 422 );
		}
		return new WP_REST_Response( array( 'ok' => true, 'entry' => $r ), 201 );
	}

	/** Pure: a note title (≤ 120, else the first words of the text) + text (≤ 4000); a sentence when refused. @return array|string */
	public static function note_input( string $title, string $text ) {
		$text  = trim( preg_replace( "/\r\n?/", "\n", $text ) );
		$title = trim( preg_replace( '/\s+/u', ' ', $title ) );
		if ( '' === $text && '' === $title ) {
			return 'Ghi chú đang trống.';
		}
		if ( mb_strlen( $text ) > 4000 ) {
			return 'Ghi chú dài quá 4000 ký tự — gửi file vào Zalo thay vì gõ ở đây.';
		}
		if ( '' === $title ) {
			$title = mb_substr( preg_replace( '/\s+/u', ' ', $text ), 0, 60 );
		}
		return array( 'title' => mb_substr( $title, 0, 120 ), 'text' => $text );
	}

	/** S122-F4 — rename one of the person's own entries (title only; the row stays theirs). */
	public static function brain_title( WP_REST_Request $request ): WP_REST_Response {
		$title = trim( preg_replace( '/\s+/u', ' ', (string) ( ( (array) $request->get_json_params() )['title'] ?? '' ) ) );
		if ( '' === $title || mb_strlen( $title ) > 120 ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'invalid_param', 'message' => 'Tên từ 1 đến 120 ký tự.' ), 400 );
		}
		if ( ! class_exists( 'BizCity_Journal_Database' ) || ! class_exists( 'BizCity_Journal_Index' ) ) {
			return self::brain_off();
		}
		$r = BizCity_Journal_Database::instance()->update( (int) $request['id'], get_current_user_id(), array( 'title' => $title ) ); // owner-checked inside
		if ( is_wp_error( $r ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => $r->get_error_code(), 'message' => $r->get_error_message() ), 404 );
		}
		if ( class_exists( 'BizCity_Journal_Doc_Index_Pack_Exporter' ) ) {
			BizCity_Journal_Doc_Index_Pack_Exporter::flush( get_current_user_id() ); // the assistant's copy learns the new name
			do_action( 'bizcity_twin_agent_packs_changed', array( 'doc_index' ) );
		}
		return new WP_REST_Response( array( 'ok' => true, 'entry' => BizCity_Journal_Index::get( (int) $request['id'], get_current_user_id() ) ), 200 );
	}

	/**
	 * [2026-10-18 09:00 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.122 S122-F5 — the site's people (same rows as Channel Gateway › Người dùng, which keeps its own
	 * admin gate and leak guard). Read only: add / change level / remove = a sentence the owner sends in Zalo (create_website_account / set_website_account_role, R3 two steps).
	 */
	public static function users(): WP_REST_Response {
		if ( ! class_exists( 'BizCity_Admin_Users_Overview_REST' ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'users_unavailable', 'message' => 'Danh sách người dùng chưa có trên website này.' ), 503 );
		}
		$gate = BizCity_Admin_Users_Overview_REST::can_manage();
		if ( true !== $gate ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'admin_only', 'message' => 'Chỉ quản trị website xem được danh sách người dùng.' ), 403 );
		}
		$r = new WP_REST_Request( 'GET', '/bizcity-channel/v1/admin/users-overview' );
		$r->set_param( 'range', '7d' );
		$r->set_param( 'per_page', 50 );
		$resp = rest_ensure_response( BizCity_Admin_Users_Overview_REST::route( $r ) );
		$data = (array) $resp->get_data();
		return new WP_REST_Response( array( 'ok' => 200 === $resp->get_status() && isset( $data['rows'] ), 'people' => self::people_rows( (array) ( $data['rows'] ?? array() ) ) ), $resp->get_status() );
	}

	/** Pure: the overview rows ⇒ what the panel shows (name, role, level, numbers, last active) — nothing else leaves. */
	public static function people_rows( array $rows ): array {
		$out = array();
		foreach ( $rows as $row ) {
			$u     = (array) ( $row['user'] ?? array() );
			$out[] = array(
				'id'          => (int) ( $u['id'] ?? 0 ),
				'name'        => mb_substr( (string) ( $u['name'] ?? '' ), 0, 60 ),
				'wp_role'     => (string) ( $u['wp_role'] ?? '' ),
				'kind'        => (string) ( $u['kind'] ?? '' ),
				'level'       => isset( $row['level'] ) ? (string) $row['level'] : '',
				'numbers'     => count( (array) ( $row['numbers'] ?? array() ) ),
				'last_active' => (string) ( $row['last_active'] ?? '' ),
			);
		}
		return $out;
	}

	private static function brain_off(): WP_REST_Response {
		return new WP_REST_Response( array( 'ok' => false, 'code' => 'journal_unavailable', 'message' => 'Sổ tài liệu chưa sẵn sàng trên website này.' ), 503 );
	}

	// ── helpers ──────────────────────────────────────────────────────────

	/** The connect services keep their own gate: the panel person must hold the channel grant (or be admin). */
	private static function channel_denied(): ?WP_REST_Response {
		$ok = BizCity_Connect_Composio_REST::perm_channel();
		if ( true === $ok ) {
			return null;
		}
		$msg = is_wp_error( $ok ) ? $ok->get_error_message() : 'Bạn chưa được cấp quyền kênh.';
		return new WP_REST_Response( array( 'ok' => false, 'code' => 'not_allowed', 'message' => $msg, 'hint' => 'Nhờ quản trị viên cấp quyền "Kênh khách & Gmail".' ), 403 );
	}

	/** A request on the Channel Gateway route the existing pipe() expects. */
	private static function inner( string $method, string $path, array $body = array() ): WP_REST_Request {
		$r = new WP_REST_Request( $method, '/bizcity-channel/v1/connect/composio' . $path );
		if ( $body ) {
			$r->set_header( 'content-type', 'application/json' );
			$r->set_body( (string) wp_json_encode( $body ) );
		}
		return $r;
	}

	private static function only( WP_REST_Request $request, array $keys ): array {
		$p = (array) $request->get_json_params();
		$o = array();
		foreach ( $keys as $k ) {
			if ( isset( $p[ $k ] ) && is_scalar( $p[ $k ] ) ) {
				$o[ $k ] = substr( sanitize_text_field( (string) $p[ $k ] ), 0, 120 );
			}
		}
		return $o;
	}

	/** One sentence to send the assistant next, from the manifest's `chat_suggestions` (else a plain default). */
	public static function suggestions( string $panel ): array {
		$toolkit = 0 === strpos( $panel, 'connect.' ) ? substr( $panel, 8 ) : '';
		if ( '' === $toolkit || ! class_exists( 'BizCity_Connector_Registry' ) ) {
			return array();
		}
		foreach ( BizCity_Connector_Registry::instance()->all() as $m ) {
			if ( $toolkit === (string) ( $m['auth']['toolkit'] ?? '' ) ) {
				$out = array();
				foreach ( (array) ( $m['chat_suggestions'] ?? array() ) as $s ) {
					if ( ! empty( $s['text'] ) ) {
						$out[] = substr( (string) $s['text'], 0, 120 );
					}
				}
				return array_slice( $out, 0, 3 );
			}
		}
		return array();
	}
}
