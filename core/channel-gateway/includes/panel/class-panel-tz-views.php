<?php
/**
 * BizCity_Panel_TZ_Views — two TwinZalo screens read from the cell (PHASE-0.128 doc 68):
 *
 *   track( uid )    ⑭ "Theo dõi hoàn thành" (S128-U15) — track-view@1 of the number the person OWNS: week ring · gate numbers · feedback ⇒ lessons.
 *   accounts( uid ) ⑱ "Tài khoản bot" (S128-U17)       — bot-accounts@1 of the site's numbers; the site ADMIN only (manage_options).
 *
 * Road: cell `/wp/brain/{scoreboard,accounts}` → Hub `zalo-personal-bridge/brain/*` (BRAIN_ROOTS) → hub client `tz_view_call` → here.
 * The routes live in class-panel-rest.php (`tz/track`, `tz/accounts`, group twinzalo) and only call these two methods. Only known fields are
 * copied; counts and rates only (the cell already refuses token / level keys). Each result carries `status` (HTTP code for the route).
 *
 * Biz Central Brain — Johnny Chu (Chu Hoàng Anh).
 *
 * [2026-10-22 11:47 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.128 TwinZalo S128-U15 / U17 — new file.
 *
 * @package Bizcity_Twin_AI
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Panel_TZ_Views', false ) ) {
	return;
}

final class BizCity_Panel_TZ_Views {

	const TRACK    = 'track-view@1.0.0';
	const ACCOUNTS = 'bot-accounts@1.0.0';
	const KINDS    = array( 'online', 'qr_pending', 'offline', 'disabled' );

	const MESSAGES = array(
		'no_number'       => array( 'Anh chưa là chủ số Zalo nào trên website này.', 'Gắn số Zalo ở Channel Gateway › Zalo cá nhân rồi mở lại TwinZalo.' ),
		'admin_only'      => array( 'Chỉ quản trị website xem được danh sách số.', 'Nhờ quản trị site mở mục này.' ),
		'hub_unavailable' => array( 'Chưa đọc được số liệu từ trợ lý (Hub / cell không trả lời).', 'Kéo xuống để tải lại sau ít phút.' ),
	);

	/** Test seams: owned(uid) · is_admin(uid) · bridges() · hub(view, bridge) · bindings() · job_ref(uid, job_id) */
	public static $readers = array();

	// ── entry points (called by BizCity_Panel_REST::tz_track / tz_accounts) ──

	public static function track( int $uid ): array {
		$bridge = self::owned_number( $uid );
		if ( '' === $bridge ) {
			return self::error( 'no_number', 404 );
		}
		$raw = self::hub( 'scoreboard', $bridge );
		if ( empty( $raw['ok'] ) || self::TRACK !== (string) ( $raw['contract'] ?? '' ) ) {
			return self::error( 'hub_unavailable', 502, array( 'code_hub' => (string) ( $raw['code'] ?? '' ) ) );
		}
		$out = self::track_view( $raw );
		foreach ( $out['feedback']['recent'] as $i => $f ) {
			$out['feedback']['recent'][ $i ]['job_ref'] = self::job_ref( $uid, $f['job_id'] );
		}
		return $out + array( 'status' => 200 );
	}

	public static function accounts( int $uid ): array {
		if ( ! self::is_admin( $uid ) ) {
			return self::error( 'admin_only', 403 );
		}
		$bridge = self::owned_number( $uid );
		if ( '' === $bridge ) {
			$all    = self::bridges();
			$bridge = (string) ( $all[0] ?? '' );
		}
		if ( '' === $bridge ) {
			return self::error( 'no_number', 404 );
		}
		$raw = self::hub( 'accounts', $bridge );
		if ( empty( $raw['ok'] ) || self::ACCOUNTS !== (string) ( $raw['contract'] ?? '' ) ) {
			return self::error( 'hub_unavailable', 502, array( 'code_hub' => (string) ( $raw['code'] ?? '' ) ) );
		}
		$mine = array();
		foreach ( self::owned( $uid ) as $r ) {
			$mine[ (string) ( $r['account_id'] ?? '' ) ] = true;
		}
		return self::accounts_view( $raw, self::bindings(), $mine ) + array( 'status' => 200 );
	}

	// ── pure ─────────────────────────────────────────────────────────────

	/** Pure: track-view@1 reduced to the known fields. */
	public static function track_view( array $r ): array {
		$w  = is_array( $r['week'] ?? null ) ? $r['week'] : array();
		$g  = is_array( $r['gates'] ?? null ) ? $r['gates'] : array();
		$fb = is_array( $r['feedback'] ?? null ) ? $r['feedback'] : array();
		$recent = array();
		foreach ( array_slice( array_filter( (array) ( $fb['recent'] ?? array() ), 'is_array' ), 0, 5 ) as $f ) {
			$job = (string) ( $f['job_id'] ?? '' );
			$v   = (string) ( $f['verdict'] ?? '' );
			if ( 1 !== preg_match( '/^job_[0-9A-Za-z]{10,40}$/', $job ) || ! in_array( $v, array( 'up', 'down', 'fix' ), true ) ) {
				continue;
			}
			$recent[] = array( 'job_id' => $job, 'job_ref' => '', 'verdict' => $v, 'lesson' => isset( $f['lesson'] ) && null !== $f['lesson'] ? mb_substr( (string) $f['lesson'], 0, 120 ) : null );
		}
		return array(
			'ok'       => true,
			'contract' => self::TRACK,
			'window'   => self::window( $r ),
			'week'     => array(
				'total'     => self::n( $w['total'] ?? 0 ),
				'verified'  => self::n( $w['verified'] ?? 0 ),
				'partial'   => self::n( $w['partial'] ?? 0 ),
				'failed'    => self::n( $w['failed'] ?? 0 ),
				'cancelled' => self::n( $w['cancelled'] ?? 0 ),
				'running'   => self::n( $w['running'] ?? 0 ),
				'pct'       => isset( $w['pct'] ) && null !== $w['pct'] ? max( 0, min( 100, (int) $w['pct'] ) ) : null,
			),
			'gates'    => array(
				'first_pass_rate'  => self::rate( $g['first_pass_rate'] ?? null ),
				'owner_asked_rate' => self::rate( $g['owner_asked_rate'] ?? null ),
				'no_rescue_rate'   => self::rate( $g['no_rescue_rate'] ?? null ),
				'layers'           => self::n( $g['layers'] ?? 0 ),
				'workers'          => self::n( $g['workers'] ?? 0 ),
			),
			'feedback' => array( 'up' => self::n( $fb['up'] ?? 0 ), 'down' => self::n( $fb['down'] ?? 0 ), 'lessons' => self::n( $fb['lessons'] ?? 0 ), 'recent' => $recent ),
		);
	}

	/**
	 * Pure: bot-accounts@1 reduced to the known fields + the site's own label and binding id of each number (so the screen opens its settings).
	 *
	 * @param array $bindings channel bindings (platform, account_id, id, account_label|label)
	 * @param array $mine     bridge ids the person owns ⇒ `mine`
	 */
	public static function accounts_view( array $r, array $bindings, array $mine ): array {
		$site = array();
		foreach ( $bindings as $b ) {
			if ( is_array( $b ) && 'ZALO_PERSONAL' === strtoupper( (string) ( $b['platform'] ?? '' ) ) && '' !== (string) ( $b['account_id'] ?? '' ) ) {
				$site[ (string) $b['account_id'] ] = array( 'id' => (int) ( $b['id'] ?? 0 ), 'label' => (string) ( $b['account_label'] ?? $b['label'] ?? '' ) );
			}
		}
		$rows = array();
		foreach ( array_slice( array_filter( (array) ( $r['accounts'] ?? array() ), 'is_array' ), 0, 100 ) as $a ) {
			$id = preg_replace( '/[^0-9]/', '', (string) ( $a['account_id'] ?? '' ) );
			if ( '' === $id ) {
				continue;
			}
			$st     = in_array( (string) ( $a['status'] ?? '' ), self::KINDS, true ) ? (string) $a['status'] : 'offline';
			$w      = is_array( $a['week'] ?? null ) ? $a['week'] : array();
			$label  = '' !== (string) ( $site[ $id ]['label'] ?? '' ) ? $site[ $id ]['label'] : (string) ( $a['label'] ?? '' );
			$since  = (string) ( $a['state_since'] ?? '' );
			$rows[] = array(
				'account_id'  => $id,
				'binding_id'  => (int) ( $site[ $id ]['id'] ?? 0 ),
				'label'       => mb_substr( '' !== $label ? $label : 'Số Zalo', 0, 60 ),
				'status'      => $st,
				'state_since' => 1 === preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', $since ) ? $since : null,
				'mine'        => isset( $mine[ $id ] ),
				'jobs_today'  => self::n( $a['jobs_today'] ?? 0 ),
				'staff'       => self::n( $a['staff'] ?? 0 ),
				'quest_staff' => ! empty( $a['quest_staff'] ),
				'week'        => array( 'jobs' => self::n( $w['jobs'] ?? 0 ), 'first_pass_rate' => self::rate( $w['first_pass_rate'] ?? null ), 'owner_asked_rate' => self::rate( $w['owner_asked_rate'] ?? null ) ),
			);
		}
		usort( $rows, static function ( $x, $y ) { return array( $y['mine'], $x['label'] ) <=> array( $x['mine'], $y['label'] ); } ); // the person's own numbers first
		return array(
			'ok'       => true,
			'contract' => self::ACCOUNTS,
			'window'   => self::window( $r ),
			'counts'   => array( 'total' => count( $rows ), 'online' => count( array_filter( $rows, static function ( $x ) { return 'online' === $x['status']; } ) ), 'qr_pending' => count( array_filter( $rows, static function ( $x ) { return 'qr_pending' === $x['status']; } ) ) ),
			'accounts' => $rows,
		);
	}

	private static function window( array $r ): array {
		$w   = is_array( $r['window'] ?? null ) ? $r['window'] : array();
		$day = static function ( $v ) { return 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $v ) ? (string) $v : ''; };
		return array( 'from' => $day( $w['from'] ?? '' ), 'to' => $day( $w['to'] ?? '' ) );
	}

	private static function n( $v ): int {
		return max( 0, min( 1000000, (int) $v ) );
	}

	private static function rate( $v ): ?float {
		return is_numeric( $v ) ? max( 0.0, min( 1.0, round( (float) $v, 3 ) ) ) : null;
	}

	// ── reads (seams) ────────────────────────────────────────────────────

	private static function owned( int $uid ): array {
		if ( isset( self::$readers['owned'] ) ) {
			return (array) call_user_func( self::$readers['owned'], $uid );
		}
		return class_exists( 'BizCity_Twin_Web_Turn' ) && method_exists( 'BizCity_Twin_Web_Turn', 'owned_rows' ) ? BizCity_Twin_Web_Turn::owned_rows( $uid ) : array();
	}

	/** the first number the person OWNS (same rule as tz/ask), '' when none */
	private static function owned_number( int $uid ): string {
		foreach ( self::owned( $uid ) as $r ) {
			if ( 'owner' === (string) ( $r['role'] ?? '' ) && '' !== (string) ( $r['account_id'] ?? '' ) ) {
				return (string) $r['account_id'];
			}
		}
		return '';
	}

	private static function is_admin( int $uid ): bool {
		if ( isset( self::$readers['is_admin'] ) ) {
			return (bool) call_user_func( self::$readers['is_admin'], $uid );
		}
		return $uid > 0 && function_exists( 'user_can' ) && user_can( $uid, 'manage_options' );
	}

	private static function bridges(): array {
		if ( isset( self::$readers['bridges'] ) ) {
			return (array) call_user_func( self::$readers['bridges'] );
		}
		if ( ! class_exists( 'BizCity_Zalo_Hub_Config_Sync' ) || ! method_exists( 'BizCity_Zalo_Hub_Config_Sync', 'accounts' ) ) {
			return array();
		}
		$out = array(); // rows {bridge_id, label, owner_user_id} of the zalo_hub numbers
		foreach ( (array) BizCity_Zalo_Hub_Config_Sync::accounts() as $row ) {
			$id = is_array( $row ) ? (string) ( $row['bridge_id'] ?? '' ) : (string) $row;
			if ( '' !== $id ) {
				$out[] = $id;
			}
		}
		return $out;
	}

	private static function hub( string $view, string $bridge ): array {
		if ( isset( self::$readers['hub'] ) ) {
			return (array) call_user_func( self::$readers['hub'], $view, $bridge );
		}
		if ( ! class_exists( 'BizCity_Zalo_Personal_Hub_Client' ) || ! method_exists( 'BizCity_Zalo_Personal_Hub_Client', 'tz_view_call' ) ) {
			return array( 'ok' => false, 'code' => 'hub_client_missing' );
		}
		return BizCity_Zalo_Personal_Hub_Client::instance()->tz_view_call( $view, $bridge );
	}

	private static function bindings(): array {
		if ( isset( self::$readers['bindings'] ) ) {
			return (array) call_user_func( self::$readers['bindings'] );
		}
		return class_exists( 'BizCity_Channel_Binding' ) ? (array) BizCity_Channel_Binding::all() : array();
	}

	/** the JB- code of the person's job header ('' when the journal has none) */
	private static function job_ref( int $uid, string $job_id ): string {
		if ( isset( self::$readers['job_ref'] ) ) {
			return (string) call_user_func( self::$readers['job_ref'], $uid, $job_id );
		}
		if ( ! class_exists( 'BizCity_Journal_Job_Link' ) || ! method_exists( 'BizCity_Journal_Job_Link', 'find_header' ) ) {
			return '';
		}
		$h   = BizCity_Journal_Job_Link::find_header( $uid, $job_id );
		$ref = is_array( $h ) ? (string) ( $h['ref_code'] ?? '' ) : '';
		return 1 === preg_match( '/^JB-\d{1,12}$/', $ref ) ? $ref : '';
	}

	private static function error( string $code, int $status, array $extra = array() ): array {
		$m = self::MESSAGES[ $code ] ?? array( 'Không thực hiện được.', 'Thử lại sau.' );
		return array( 'ok' => false, 'code' => $code, 'message' => $m[0], 'hint' => $m[1], 'help_code' => 'S128-TZ-' . $status, 'status' => $status ) + $extra;
	}
}
