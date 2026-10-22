<?php
/**
 * panel-link@1 — the ONE link the assistant sends its owner over Zalo (PHASE-0.122, doc 20, R-PANEL-LINK).
 *
 * Ticket (one use, 15 min) → gate POST → narrow panel session (cookie `bzc_panel`, 30 min sliding, 2 h max). Never a WordPress login.
 * Every ticket and session is tied to the person's private panel key (user option): a key change kills them all.
 * The key is created on first use and rotates by itself (D122-13, owner 2026-10-18): after 24 h when no session is open
 * (checked when the next link is issued), always after 48 h, and after 3 bad ticket openings in a row. "đổi khoá" rotates it at once.
 *
 * No table (R-PL-12): tickets + sessions are transients keyed by sha256; key, counters, live lists and a short log are user options.
 * The token is never stored, never logged; only its sha256.
 *
 * [2026-10-18 05:10 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.122 S122-P1 — new file. Loaded once by require_once in bootstrap.php.
 *
 * @package BizCity_Twin_AI
 * @subpackage Channel_Gateway
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_Panel_Link {

	const CONTRACT = 'panel-link@1.0.0';

	const TICKET_TTL   = 900;    // 15 min to open (Q122-1)
	const SESSION_IDLE = 1800;   // 30 min sliding
	const SESSION_MAX  = 7200;   // 2 h hard
	const ROTATE_IDLE  = 86400;  // 24 h: rotate at next issue when no session is open (D122-13)
	const ROTATE_HARD  = 172800; // 48 h: rotate whatever happens
	const BAD_LIMIT    = 3;      // bad ticket openings in a row ⇒ rotate
	const ISSUE_LIMIT  = 5;      // links per window (R-PL-10)
	const ISSUE_WINDOW = 600;
	const MAX_SESSIONS = 3;
	const LOG_MAX      = 20;

	const COOKIE    = 'bzc_panel';
	const T_TICKET  = 'bzc_pl_';
	const T_SESSION = 'bzc_ps_';

	const OPT_KV       = 'bizcity_panel_kv';
	const OPT_KEY      = 'bizcity_panel_key';
	const OPT_KEY_AT   = 'bizcity_panel_key_at';
	const OPT_BAD      = 'bizcity_panel_bad';
	const OPT_TICKETS  = 'bizcity_panel_tickets';  // panel ⇒ {h, issued_at, exp}: the one live ticket per panel
	const OPT_SESSIONS = 'bizcity_panel_sessions'; // [{h, panel, opened_at, ua}]
	const OPT_ISSUED   = 'bizcity_panel_issued';   // timestamps of recent issues (rate limit)
	const OPT_LOG      = 'bizcity_panel_log';      // [{at, ev, panel?, reason?}] newest first

	/**
	 * Seam for tests: array of callables
	 *   tget(k) tset(k, v, ttl) tdel(k) · uget(uid, k) uset(uid, k, v) · now() · rand(n) · catalog() (optional: the panel list)
	 *
	 * @var array|null
	 */
	public static $store = null;

	// ── catalog ──────────────────────────────────────────────────────────

	/** Toolkits Kết nối nhanh accepts (same list as BizCity_Connect_Composio_REST::post_connect). */
	const TOOLKITS = array( 'gmail', 'googledrive', 'facebook', 'googlesheets', 'googlecalendar', 'googlemeet', 'canva' );

	/**
	 * Every panel the assistant may send: core panels of contracts/panels.json + one `connect.<toolkit>` per Composio connector manifest (R-PL-13).
	 *
	 * @return array<string,array{id:string,label:string,group:string,body:string,risk_max:string,say:string[],google:bool}>
	 */
	public static function catalog(): array {
		if ( isset( self::$store['catalog'] ) ) {
			return (array) call_user_func( self::$store['catalog'] );
		}
		static $cache = null;
		if ( null !== $cache ) {
			return $cache;
		}
		$out  = array();
		$file = dirname( __DIR__, 2 ) . '/contracts/panels.json';
		$json = is_readable( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : null;
		foreach ( (array) ( $json['panels'] ?? array() ) as $p ) {
			$row = self::panel_row( (array) $p );
			if ( $row ) {
				$out[ $row['id'] ] = $row;
			}
		}
		if ( class_exists( 'BizCity_Connector_Registry' ) ) {
			foreach ( BizCity_Connector_Registry::instance()->all() as $m ) {
				$row = self::connector_panel( (array) $m );
				if ( $row ) {
					$out[ $row['id'] ] = $row;
				}
			}
		}
		$cache = $out;
		return $out;
	}

	/** Pure: a manifest with Composio auth ⇒ its `connect.<toolkit>` panel (manifest `panel` block overrides label / say). */
	public static function connector_panel( array $m ): ?array {
		$auth    = (array) ( $m['auth'] ?? array() );
		$toolkit = (string) ( $auth['toolkit'] ?? '' );
		if ( 'oauth_composio' !== ( $auth['kind'] ?? '' ) || ! in_array( $toolkit, self::TOOLKITS, true ) || ! empty( $m['planned'] ) ) {
			return null;
		}
		$p = (array) ( $m['panel'] ?? array() );
		return self::panel_row( array(
			'id'       => 'connect.' . $toolkit,
			'label'    => (string) ( $p['label'] ?? ( 'Kết nối ' . (string) ( $m['label'] ?? $toolkit ) ) ),
			'group'    => 'connect',
			'body'     => 'steps',
			'risk_max' => 'R1',
			'say'      => (array) ( $p['say'] ?? array() ),
			'toolkit'  => $toolkit,
		) );
	}

	/** Pure: normalise one catalog row; null when the id is not `group.name` / `group`. */
	public static function panel_row( array $p ): ?array {
		$id = (string) ( $p['id'] ?? '' );
		if ( 1 !== preg_match( '/^[a-z][a-z_]*(\.[a-z][a-z0-9_]*)?$/', $id ) ) {
			return null;
		}
		$toolkit = (string) ( $p['toolkit'] ?? '' );
		return array(
			'id'       => $id,
			'label'    => substr( (string) ( $p['label'] ?? $id ), 0, 80 ),
			'group'    => self::group_of( $id ),
			'body'     => (string) ( $p['body'] ?? 'list' ),
			'risk_max' => (string) ( $p['risk_max'] ?? 'R0' ),
			'say'      => array_slice( array_values( array_map( 'strval', (array) ( $p['say'] ?? array() ) ) ), 0, 6 ),
			'toolkit'  => $toolkit,
			'google'   => 0 === strpos( $toolkit, 'google' ) || 'gmail' === $toolkit,
		);
	}

	/** Pure ([2026-10-20 03:30 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.125 Q1): a job id the panel may open on, or ''. */
	public static function clean_focus( string $focus ): string {
		// [2026-10-22 01:50 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.129 W2 — + `entry:<id>`: the journal row a brain.map link opens on (the receipt's file)
		return 1 === preg_match( '/^(job_[0-9A-Za-z]{10,40}|entry:[1-9][0-9]{0,11})$/', $focus ) ? $focus : '';
	}

	/** Pure ([2026-10-22 01:50 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.129 W2): the entry id of an `entry:<id>` focus, else 0. */
	public static function focus_entry( string $focus ): int {
		return 1 === preg_match( '/^entry:([1-9][0-9]{0,11})$/', $focus, $m ) ? (int) $m[1] : 0;
	}

	/** Pure: `connect.gmail` ⇒ `connect`; `key` ⇒ `key`. */
	public static function group_of( string $panel ): string {
		$dot = strpos( $panel, '.' );
		return false === $dot ? $panel : substr( $panel, 0, $dot );
	}

	/**
	 * [2026-10-22 08:50 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.128 TwinZalo (D128-12, doc 68 TZ0-1) — the `twinzalo` panel is the owner's own app: its session
	 * may open the panels of these groups (the person's own jobs, brain and connections — never `users`, which is the site admin's people matrix).
	 * Nothing new is writable: each route keeps its own group check, owner check and risk rules.
	 */
	const TWINZALO_GROUPS = array( 'jobs', 'brain', 'connect' );

	/** Pure: may a session of `$group` open `$panel`? Same group, or the key panel (always); a TwinZalo session also its groups. */
	public static function group_allows( string $group, string $panel ): bool {
		return 'key' === $panel || self::group_of( $panel ) === $group || ( 'twinzalo' === $group && in_array( self::group_of( $panel ), self::TWINZALO_GROUPS, true ) );
	}

	// ── key ──────────────────────────────────────────────────────────────

	/** Create the key on first use; rotate when due. `$issuing` = a new link is being issued (the 24 h idle rule applies only then). */
	public static function ensure_key( int $uid, bool $issuing ): void {
		if ( '' === (string) self::uget( $uid, self::OPT_KEY ) ) {
			self::write_key( $uid, 1 );
			return;
		}
		$age = self::now() - (int) self::uget( $uid, self::OPT_KEY_AT );
		// [2026-10-18 11:00 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.122 review fix — the 48 h rule never kills a page in use: it acts when a link is issued or no session is open (a session lives ≤ 2 h, so the key is ≤ 50 h old at worst)
		if ( $age >= self::ROTATE_HARD && ( $issuing || 0 === count( self::live_sessions( $uid ) ) ) ) {
			self::reset( $uid, 'auto_48h' );
		} elseif ( $issuing && $age >= self::ROTATE_IDLE && 0 === count( self::live_sessions( $uid ) ) ) {
			self::reset( $uid, 'auto_24h' );
		}
	}

	/**
	 * Change the key: every ticket and session of this person dies now (R-PL-5). Reasons: user · auto_24h · auto_48h · bad_tickets.
	 */
	public static function reset( int $uid, string $reason ): void {
		$kv = max( 1, (int) self::uget( $uid, self::OPT_KV ) ) + 1;
		foreach ( self::uarr( $uid, self::OPT_TICKETS ) as $t ) {
			self::tdel( self::T_TICKET . (string) ( $t['h'] ?? '' ) );
		}
		foreach ( self::uarr( $uid, self::OPT_SESSIONS ) as $s ) {
			self::tdel( self::T_SESSION . (string) ( $s['h'] ?? '' ) );
		}
		self::uset( $uid, self::OPT_TICKETS, array() );
		self::uset( $uid, self::OPT_SESSIONS, array() );
		self::uset( $uid, self::OPT_BAD, 0 );
		self::write_key( $uid, $kv );
		self::log( $uid, array( 'ev' => 'key_reset', 'reason' => $reason ) );
	}

	private static function write_key( int $uid, int $kv ): void {
		self::uset( $uid, self::OPT_KEY, self::b64url( self::rand( 32 ) ) );
		self::uset( $uid, self::OPT_KV, $kv );
		self::uset( $uid, self::OPT_KEY_AT, self::now() );
	}

	// ── tickets ──────────────────────────────────────────────────────────

	/**
	 * Issue a one-use ticket for `$panel` of `$uid` (the owner, checked by the caller).
	 *
	 * @return array{ok:true, token:string, expires_at:int, panel:array}|array{ok:false, code:string}
	 */
	// [2026-10-20 03:30 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.125 Q1 — `$focus` = the job id a Quest Map link opens on (jobs.quest); '' for every other panel
	/**
	 * [2026-10-22 07:12 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.129 reflect #4 — may a link be issued UNASKED (the brain.map link of a receipt)? Not while the
	 * owner still holds an unused ticket of that panel (issuing would kill the one they asked for), and only with headroom in the 5 / 10 min limit
	 * (≤ 3 used) so the owner's own next request is never refused because of an automatic one.
	 */
	public static function can_auto_issue( int $uid, string $panel ): bool {
		if ( $uid <= 0 ) {
			return false;
		}
		$now     = self::now();
		$tickets = self::prune_tickets( self::uarr( $uid, self::OPT_TICKETS ), $now );
		if ( isset( $tickets[ $panel ] ) ) {
			return false;
		}
		$recent = array_filter( array_map( 'intval', self::uarr( $uid, self::OPT_ISSUED ) ), static function ( $t ) use ( $now ) { return $t > $now - self::ISSUE_WINDOW; } );
		return count( $recent ) <= self::ISSUE_LIMIT - 2;
	}

	// [2026-10-22 07:12 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.129 reflect #4 — `$auto` (issued unasked): the key is never rotated here (a rotation would close a page the owner has open)
	public static function issue( int $uid, string $panel, string $turn_id = '', string $focus = '', bool $auto = false ): array {
		$cat = self::catalog();
		if ( $uid <= 0 ) {
			return array( 'ok' => false, 'code' => 'not_owner' );
		}
		if ( ! isset( $cat[ $panel ] ) ) {
			return array( 'ok' => false, 'code' => 'panel_unknown' );
		}
		$now    = self::now();
		$recent = array_values( array_filter( array_map( 'intval', self::uarr( $uid, self::OPT_ISSUED ) ), static function ( $t ) use ( $now ) { return $t > $now - self::ISSUE_WINDOW; } ) );
		if ( count( $recent ) >= self::ISSUE_LIMIT ) {
			return array( 'ok' => false, 'code' => 'rate_limited' );
		}
		self::ensure_key( $uid, ! $auto );
		$tickets = self::uarr( $uid, self::OPT_TICKETS );
		if ( isset( $tickets[ $panel ]['h'] ) ) {
			self::tdel( self::T_TICKET . (string) $tickets[ $panel ]['h'] ); // R-PL-3: the older unused link of this panel dies
		}
		$token = 'pl1_' . self::b64url( self::rand( 32 ) );
		$h     = hash( 'sha256', $token );
		$exp   = $now + self::TICKET_TTL;
		self::tset( self::T_TICKET . $h, array(
			'contract'  => self::CONTRACT,
			'blog_id'   => function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 1,
			'user_id'   => $uid,
			'panel'     => $panel,
			'group'     => self::group_of( $panel ),
			'kv'        => (int) self::uget( $uid, self::OPT_KV ),
			'issued_at' => $now,
			'exp'       => $exp,
			'turn'      => substr( (string) preg_replace( '/[^A-Za-z0-9_.:\-]/', '', $turn_id ), 0, 64 ),
			'focus'     => self::clean_focus( $focus ),
			'consumed'  => false,
		), self::TICKET_TTL );
		$tickets[ $panel ] = array( 'h' => $h, 'issued_at' => $now, 'exp' => $exp );
		self::uset( $uid, self::OPT_TICKETS, self::prune_tickets( $tickets, $now ) );
		$recent[] = $now;
		self::uset( $uid, self::OPT_ISSUED, $recent );
		self::log( $uid, array( 'ev' => 'issued', 'panel' => $panel ) );
		return array( 'ok' => true, 'token' => $token, 'expires_at' => $exp, 'panel' => $cat[ $panel ] );
	}

	/**
	 * What a ticket is, without using it (GET of the gate must never consume — R-PL-4).
	 * A bad opening (used ticket) counts toward the auto rotation of its owner; an unknown ticket counts for nobody.
	 *
	 * @return array{ok:true, ticket:array, panel:array}|array{ok:false, code:string}
	 */
	public static function peek( string $token, bool $count_bad = true ): array {
		if ( 1 !== preg_match( '/^pl1_[A-Za-z0-9_-]{40,60}$/', $token ) ) {
			return array( 'ok' => false, 'code' => 'invalid' );
		}
		$t = self::tget( self::T_TICKET . hash( 'sha256', $token ) );
		if ( ! is_array( $t ) ) {
			return array( 'ok' => false, 'code' => 'gone' );
		}
		$uid = (int) ( $t['user_id'] ?? 0 );
		if ( ! empty( $t['consumed'] ) ) {
			if ( $count_bad ) {
				self::note_bad( $uid );
			}
			return array( 'ok' => false, 'code' => 'used' );
		}
		if ( (int) ( $t['exp'] ?? 0 ) <= self::now() ) {
			return array( 'ok' => false, 'code' => 'gone' );
		}
		if ( (int) ( $t['kv'] ?? 0 ) !== (int) self::uget( $uid, self::OPT_KV ) ) {
			return array( 'ok' => false, 'code' => 'key_reset' );
		}
		$cat = self::catalog();
		if ( ! isset( $cat[ (string) $t['panel'] ] ) ) {
			return array( 'ok' => false, 'code' => 'panel_unknown' );
		}
		return array( 'ok' => true, 'ticket' => $t, 'panel' => $cat[ (string) $t['panel'] ] );
	}

	/**
	 * Use the ticket (gate POST): mark it used (kept as a tombstone until it would have expired), open a session.
	 *
	 * @return array{ok:true, cookie:string, panel:array, max_age:int}|array{ok:false, code:string}
	 */
	public static function consume( string $token, string $ua = '' ): array {
		$p = self::peek( $token );
		if ( empty( $p['ok'] ) ) {
			return $p;
		}
		// [2026-10-18 11:00 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.122 review fix — peek + tset is read-then-write: two POSTs at once (double tap, a bot) must not both open a session. One wins the lock, the other sees "used".
		$lock = 'bzc_plk_' . substr( hash( 'sha256', $token ), 0, 40 );
		if ( ! self::lock( $lock ) ) {
			return array( 'ok' => false, 'code' => 'used' );
		}
		$t   = $p['ticket'];
		$uid = (int) $t['user_id'];
		$now = self::now();
		$key = self::T_TICKET . hash( 'sha256', $token );
		self::tset( $key, array( 'user_id' => $uid, 'consumed' => true, 'exp' => (int) $t['exp'] ), max( 1, (int) $t['exp'] - $now ) );
		$tickets = self::uarr( $uid, self::OPT_TICKETS );
		unset( $tickets[ (string) $t['panel'] ] );
		self::uset( $uid, self::OPT_TICKETS, $tickets );
		self::uset( $uid, self::OPT_BAD, 0 );

		$sid = self::b64url( self::rand( 24 ) );
		$h   = hash( 'sha256', $sid );
		self::tset( self::T_SESSION . $h, array(
			'user_id'   => $uid,
			'panel'     => (string) $t['panel'],
			'group'     => (string) $t['group'],
			'kv'        => (int) $t['kv'],
			'opened_at' => $now,
			'last_at'   => $now,
			'hard_exp'  => $now + self::SESSION_MAX,
			'focus'     => self::clean_focus( (string) ( $t['focus'] ?? '' ) ),
		), self::SESSION_MAX );
		$list   = self::live_sessions( $uid );
		$list[] = array( 'h' => $h, 'panel' => (string) $t['panel'], 'opened_at' => $now, 'ua' => self::ua_label( $ua ) );
		while ( count( $list ) > self::MAX_SESSIONS ) {
			$old = array_shift( $list ); // R-PL-10: the 4th session pushes out the oldest
			self::tdel( self::T_SESSION . (string) $old['h'] );
		}
		self::uset( $uid, self::OPT_SESSIONS, $list );
		self::log( $uid, array( 'ev' => 'opened', 'panel' => (string) $t['panel'] ) );
		self::unlock( $lock );
		return array( 'ok' => true, 'cookie' => $sid . '.' . self::sign( $uid, $sid, (int) $t['kv'] ), 'panel' => $p['panel'], 'max_age' => self::SESSION_MAX );
	}

	/** A bad opening of one person's ticket; the 3rd in a row rotates the key. Returns true when it rotated. */
	public static function note_bad( int $uid ): bool {
		if ( $uid <= 0 ) {
			return false;
		}
		$bad = (int) self::uget( $uid, self::OPT_BAD ) + 1;
		if ( $bad >= self::BAD_LIMIT ) {
			self::reset( $uid, 'bad_tickets' );
			return true;
		}
		self::uset( $uid, self::OPT_BAD, $bad );
		return false;
	}

	// ── sessions ─────────────────────────────────────────────────────────

	/**
	 * The session behind a cookie value, or null. Checks signature, key version, idle and hard expiry, and the 48 h rotation.
	 * Slides `last_at` (written at most once a minute).
	 */
	public static function session( string $cookie ): ?array {
		$parts = explode( '.', $cookie );
		if ( 2 !== count( $parts ) || 1 !== preg_match( '/^[A-Za-z0-9_-]{20,40}$/', $parts[0] ) ) {
			return null;
		}
		$h = hash( 'sha256', $parts[0] );
		$s = self::tget( self::T_SESSION . $h );
		if ( ! is_array( $s ) ) {
			return null;
		}
		$uid = (int) ( $s['user_id'] ?? 0 );
		$now = self::now();
		self::ensure_key( $uid, false );
		$kv = (int) self::uget( $uid, self::OPT_KV );
		if ( (int) ( $s['kv'] ?? 0 ) !== $kv || ! hash_equals( self::sign( $uid, $parts[0], $kv ), $parts[1] ) ) {
			return null;
		}
		if ( $now > (int) $s['hard_exp'] || $now - (int) $s['last_at'] > self::SESSION_IDLE ) {
			self::tdel( self::T_SESSION . $h );
			return null;
		}
		if ( $now - (int) $s['last_at'] >= 60 ) {
			$s['last_at'] = $now;
			self::tset( self::T_SESSION . $h, $s, max( 1, (int) $s['hard_exp'] - $now ) );
		}
		$s['expires_at'] = min( (int) $s['hard_exp'], (int) $s['last_at'] + self::SESSION_IDLE );
		$s['h']          = $h;
		return $s;
	}

	/** Close one session (logout of the panel). */
	public static function close( array $session ): void {
		self::tdel( self::T_SESSION . (string) ( $session['h'] ?? '' ) );
	}

	/** Sessions of this person still alive (their transient exists). */
	public static function live_sessions( int $uid ): array {
		$out = array();
		foreach ( self::uarr( $uid, self::OPT_SESSIONS ) as $s ) {
			$v = self::tget( self::T_SESSION . (string) ( $s['h'] ?? '' ) );
			if ( is_array( $v ) && (int) ( $v['hard_exp'] ?? 0 ) > self::now() && self::now() - (int) ( $v['last_at'] ?? 0 ) <= self::SESSION_IDLE ) {
				$out[] = $s + array( 'last_at' => (int) $v['last_at'] );
			}
		}
		return $out;
	}

	/** For the Khoá panel: what is open, what is waiting, what happened. No token, no hash leaves here. */
	public static function state( int $uid, string $current_h = '' ): array {
		$now     = self::now();
		$cat     = self::catalog();
		$label   = static function ( $p ) use ( $cat ) { return (string) ( $cat[ $p ]['label'] ?? $p ); };
		$open    = array();
		foreach ( self::live_sessions( $uid ) as $s ) {
			$open[] = array( 'panel' => $label( (string) $s['panel'] ), 'opened_at' => (int) $s['opened_at'], 'last_at' => (int) $s['last_at'], 'ua' => (string) ( $s['ua'] ?? '' ), 'current' => $current_h === $s['h'] );
		}
		$waiting = array();
		foreach ( self::prune_tickets( self::uarr( $uid, self::OPT_TICKETS ), $now ) as $panel => $t ) {
			$waiting[] = array( 'panel' => $label( (string) $panel ), 'issued_at' => (int) $t['issued_at'], 'expires_at' => (int) $t['exp'] );
		}
		$log = array();
		foreach ( self::uarr( $uid, self::OPT_LOG ) as $e ) {
			$log[] = array( 'at' => (int) ( $e['at'] ?? 0 ), 'ev' => (string) ( $e['ev'] ?? '' ), 'panel' => isset( $e['panel'] ) ? $label( (string) $e['panel'] ) : '', 'reason' => (string) ( $e['reason'] ?? '' ) );
		}
		return array(
			'key_at'          => (int) self::uget( $uid, self::OPT_KEY_AT ),
			'auto_rotate_s'   => self::ROTATE_IDLE,
			'open'            => $open,
			'waiting'         => $waiting,
			'log'             => $log,
		);
	}

	// ── pure helpers ─────────────────────────────────────────────────────

	/** Pure: drop expired rows of the panel ⇒ ticket map. */
	public static function prune_tickets( array $tickets, int $now ): array {
		return array_filter( $tickets, static function ( $t ) use ( $now ) { return is_array( $t ) && (int) ( $t['exp'] ?? 0 ) > $now; } );
	}

	/** Pure: "Chrome Android" / "Safari iPhone" / "Zalo" … — never the raw user agent. */
	public static function ua_label( string $ua ): string {
		$os  = preg_match( '/iPhone|iPad/i', $ua ) ? 'iPhone' : ( preg_match( '/Android/i', $ua ) ? 'Android' : ( preg_match( '/Windows/i', $ua ) ? 'Windows' : ( preg_match( '/Mac OS/i', $ua ) ? 'Mac' : '' ) ) );
		$app = preg_match( '/Zalo/i', $ua ) ? 'Zalo' : ( preg_match( '/Edg\//', $ua ) ? 'Edge' : ( preg_match( '/Chrome|CriOS/', $ua ) ? 'Chrome' : ( preg_match( '/Safari/', $ua ) ? 'Safari' : ( preg_match( '/Firefox|FxiOS/', $ua ) ? 'Firefox' : '' ) ) ) );
		return trim( $app . ' ' . $os );
	}

	/** Pure: the in-app browser of Zalo (Google refuses OAuth inside it — GAP-122-11). */
	public static function is_zalo_webview( string $ua ): bool {
		return 1 === preg_match( '/Zalo/i', $ua );
	}

	public static function b64url( string $bytes ): string {
		return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' );
	}

	private static function sign( int $uid, string $sid, int $kv ): string {
		$key = (string) self::uget( $uid, self::OPT_KEY );
		return self::b64url( hash_hmac( 'sha256', $sid . '|' . $uid . '|' . $kv, $key . ( function_exists( 'wp_salt' ) ? wp_salt( 'auth' ) : '' ), true ) );
	}

	private static function log( int $uid, array $e ): void {
		$log = self::uarr( $uid, self::OPT_LOG );
		array_unshift( $log, array( 'at' => self::now() ) + $e );
		self::uset( $uid, self::OPT_LOG, array_slice( $log, 0, self::LOG_MAX ) );
	}

	// ── storage (seam) ───────────────────────────────────────────────────

	/** First caller wins (an INSERT on a unique option name is atomic); without WordPress (tests) the seam or true. */
	private static function lock( string $name ): bool {
		if ( isset( self::$store['lock'] ) ) {
			return (bool) call_user_func( self::$store['lock'], $name );
		}
		return function_exists( 'add_option' ) ? (bool) add_option( $name, (string) self::now(), '', 'no' ) : true;
	}

	private static function unlock( string $name ): void {
		if ( isset( self::$store['unlock'] ) ) {
			call_user_func( self::$store['unlock'], $name );
		} elseif ( function_exists( 'delete_option' ) ) {
			delete_option( $name );
		}
	}

	private static function now(): int {
		return isset( self::$store['now'] ) ? (int) call_user_func( self::$store['now'] ) : time();
	}

	private static function rand( int $n ): string {
		return isset( self::$store['rand'] ) ? (string) call_user_func( self::$store['rand'], $n ) : random_bytes( $n );
	}

	private static function tget( string $k ) {
		return isset( self::$store['tget'] ) ? call_user_func( self::$store['tget'], $k ) : get_transient( $k );
	}

	private static function tset( string $k, $v, int $ttl ): void {
		isset( self::$store['tset'] ) ? call_user_func( self::$store['tset'], $k, $v, $ttl ) : set_transient( $k, $v, $ttl );
	}

	private static function tdel( string $k ): void {
		isset( self::$store['tdel'] ) ? call_user_func( self::$store['tdel'], $k ) : delete_transient( $k );
	}

	private static function uget( int $uid, string $k ) {
		if ( isset( self::$store['uget'] ) ) {
			return call_user_func( self::$store['uget'], $uid, $k );
		}
		$v = get_user_option( $k, $uid );
		return false === $v ? '' : $v;
	}

	/** user option that holds a list / map; anything else (missing, corrupted) reads as empty */
	private static function uarr( int $uid, string $k ): array {
		$v = self::uget( $uid, $k );
		return is_array( $v ) ? $v : array();
	}

	private static function uset( int $uid, string $k, $v ): void {
		isset( self::$store['uset'] ) ? call_user_func( self::$store['uset'], $uid, $k, $v ) : update_user_option( $uid, $k, $v );
	}
}
