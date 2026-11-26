<?php
/**
 * BizCity_Vault_Link — "🔗 Dán link" of the Kho tri thức (link-import@1, PHASE-0.153 Lane L, doc 20).
 * Copyright (c) 2026 Chu Hoàng Anh (Johnny Chu), registered No. 8877/2026/QTG. See NOTICE.md for authorship scope; LICENSE for terms.
 *
 * One web page learned into the Kho = ONE library note `/link/<host>/<sha12>.md` (frontmatter `source: web` + `web{…}`) + ONE Journal row `common.link` (LK-n).
 * The site never fetches a page and never calls a model (R-GW-8, R-VERTICAL-AXIS): `POST bizcity-vault/v1/link` only writes a WAITING note
 * (meta.status pending); the cell finds it on its 5-minute beat (journal.find {kind common.link, meta_status pending}), reads the page (+ links behind it,
 * ≤ 2 levels), and writes the same note back through `vault.write {link}` + `journal.meta.set`. Zalo links take the same door from the cell.
 *
 * @package Bizcity_Twin_AI
 * @since   2026-11-22
 */

// [2026-11-22 03:15 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.153 S153-L1 — new file (owner 22/11: "import học từ link qua dialog và qua Zalo").

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Vault_Link', false ) ) {
	return;
}

final class BizCity_Vault_Link {

	const CONTRACT  = 'link-import@1.0.0';
	const NS        = 'bizcity-vault/v1';
	const MAX_URLS  = 5;
	/** links a person may hand in per day (dialog + Zalo share it through the note rows; this counter guards the dialog door) */
	const DAY_MAX   = 20;
	const VIA       = array( 'tavily_extract', 'tavily_map', 'fetch', 'jina', '' );
	// [2026-11-22 04:21 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.153 S153-L2 — + reading: the cell has picked the link up (the Sổ row says "đang đọc" either way; the note says which)
	const STATUSES  = array( 'pending', 'reading', 'done', 'failed' );
	const FROM      = array( 'web', 'zalo' );
	/** query keys that only track a click; dropped so the same page is the same note */
	const TRACKING  = array( 'fbclid', 'gclid', 'zarsrc', 'mc_cid', 'mc_eid', 'igshid', 'ref_src' );

	private static $hooked = false;

	public static function register(): void {
		if ( self::$hooked || ! function_exists( 'add_action' ) ) {
			return;
		}
		self::$hooked = true;
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		// [2026-11-22 08:30 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.153 S153-C5 — a website job queued from Zalo (the cell writes its meta) joins the person's job list too, so the Kho shows it waiting
		add_action( 'bizcity_journal_entry_meta_set', array( __CLASS__, 'on_meta_set' ), 10, 4 );
	}

	/** journal.meta.set wrote `crawl` on a row ⇒ that website job is in its owner's list (once). */
	public static function on_meta_set( $entry_id, $owner, $meta, $source = '' ): void {
		$entry_id = (int) $entry_id;
		$owner    = (int) $owner;
		if ( $entry_id <= 0 || $owner <= 0 || ! is_array( $meta ) || ! is_array( $meta['crawl'] ?? null ) || in_array( $entry_id, self::crawl_ids( $owner ), true ) ) {
			return;
		}
		self::crawl_remember( $owner, $entry_id );
	}

	public static function routes(): void {
		$can = static function () {
			return current_user_can( 'edit_posts' );
		};
		register_rest_route( self::NS, '/link', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'rest_link' ),
			'permission_callback' => $can,
		) );
		// [2026-11-22 06:57 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.153 S153-C1 (site-crawl@1, doc 21 §4.4) — "học cả website": queue a job, list / read jobs, a person's buttons.
		// The site only writes the waiting note + meta; the cell maps, reads and writes the pages (R-GW-8).
		register_rest_route( self::NS, '/crawl', array(
			array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'rest_crawl_start' ), 'permission_callback' => $can ),
			array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'rest_crawl_list' ), 'permission_callback' => $can ),
		) );
		register_rest_route( self::NS, '/crawl/(?P<id>\d+)', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'rest_crawl_get' ),
			'permission_callback' => $can,
		) );
		register_rest_route( self::NS, '/crawl/(?P<id>\d+)/(?P<verb>pause|resume|cancel|start|recrawl|more)', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'rest_crawl_verb' ),
			'permission_callback' => $can,
		) );
	}

	// ── site-crawl@1 (PHASE-0.153 Lane C, doc 21) ────────────────────────

	const CRAWL_CONTRACT = 'site-crawl@1.0.0';
	/** pages per job a person may pick (Q153-C1: 200 default, 1 000 most) */
	const CRAWL_LIMITS   = array( 50, 200, 500, 1000 );
	const CRAWL_DEFAULT  = 200;
	/** states in which the job still holds the person's one slot (Q153-C1: one job at a time) */
	const CRAWL_BUSY     = array( 'queued', 'mapping', 'mapped', 'reading', 'paused' );
	/** states the Kho keeps pulling every 15 s */
	const CRAWL_RUNNING  = array( 'queued', 'mapping', 'reading' );
	const CRAWL_POLL_S   = 15;
	const CRAWL_JOBS_KEY = 'bizcity_vault_crawl_jobs';
	/** "Bỏ qua các mục" chips of the dialog ⇒ path prefixes (the cell adds its own junk filter on top) */
	const CRAWL_PRESETS  = array(
		'login'  => array( '/dang-nhap', '/dang-ky', '/login', '/register', '/tai-khoan', '/my-account', '/wp-login.php', '/wp-admin' ),
		'cart'   => array( '/gio-hang', '/cart', '/checkout', '/thanh-toan' ),
		'tag'    => array( '/tag', '/page', '/author' ),
		'search' => array( '/search', '/tim-kiem' ),
	);
	/** which verb a state accepts */
	const CRAWL_VERBS    = array(
		'pause'   => array( 'queued', 'mapping', 'mapped', 'reading' ),
		'resume'  => array( 'paused' ),
		'cancel'  => array( 'queued', 'mapping', 'mapped', 'reading', 'paused' ),
		'start'   => array( 'mapped' ),
		'recrawl' => array( 'done', 'failed', 'cancelled' ),
		'more'    => array( 'done' ),
	);

	/**
	 * "super-mro.com", "www.super-mro.com/san-pham/x.html" or "https://…" ⇒ {url: "https://super-mro.com/", host, path: "/san-pham"?}; [] when not public. Pure.
	 * The job always starts at the root of the host; a path the person typed becomes the "only this section" scope when they ask for it.
	 */
	public static function site_root( string $raw ): array {
		$raw = trim( $raw );
		if ( '' === $raw ) {
			return array();
		}
		if ( 1 !== preg_match( '#^https?://#i', $raw ) ) {
			$raw = 'https://' . ltrim( $raw, '/' );
		}
		$url = self::normalize_url( $raw );
		if ( '' === $url ) {
			return array();
		}
		$host = self::host_of( $url );
		$path = rtrim( (string) parse_url( $url, PHP_URL_PATH ), '/' );
		$out  = array( 'url' => 'https://' . $host . '/', 'host' => $host );
		if ( '' !== $path ) {
			$seg         = explode( '/', trim( $path, '/' ) );
			$out['path'] = '/' . $seg[0]; // the first segment = the section
		}
		return $out;
	}

	/** /link/<host>/_site.md — one website note per host in a person's Kho. Pure. */
	public static function site_path( string $host ): string {
		$h = trim( substr( (string) preg_replace( '/[^a-z0-9.-]/', '-', strtolower( $host ) ), 0, 63 ), '.-' );
		return '/link/' . ( '' !== $h ? $h : 'web' ) . '/_site.md';
	}

	/** 200 by default; only the four sizes of the dialog; never above the site cap (filter). Pure apart from the filter. */
	public static function crawl_limit( $raw ): int {
		$max = function_exists( 'apply_filters' ) ? (int) apply_filters( 'bizcity_vault_crawl_max_pages', 1000 ) : 1000;
		$n   = (int) $raw;
		$n   = in_array( $n, self::CRAWL_LIMITS, true ) ? $n : self::CRAWL_DEFAULT;
		return max( 1, min( $n, $max > 0 ? $max : self::CRAWL_DEFAULT ) );
	}

	/** presets[] + extra paths ⇒ ≤ 20 path prefixes ("/gio-hang"). Pure. */
	public static function crawl_exclude( array $presets, array $extra ): array {
		$out = array();
		foreach ( $presets as $p ) {
			foreach ( self::CRAWL_PRESETS[ (string) $p ] ?? array() as $x ) {
				$out[] = $x;
			}
		}
		foreach ( $extra as $x ) {
			$x = '/' . trim( (string) $x, " /\t" );
			if ( false === strpos( $x, '..' ) && 1 === preg_match( '#^/[a-z0-9._~%-]{1,80}(/[a-z0-9._~%-]{1,80})*$#i', $x ) ) {
				$out[] = strtolower( $x );
			}
		}
		return array_slice( array_values( array_unique( $out ) ), 0, 20 );
	}

	/** The body of a website note before the cell maps it. Pure. */
	public static function site_pending_markdown( string $url, int $limit ): string {
		return '> Nguồn: <' . $url . ">\n\nTrợ lý đang chờ lập sơ đồ website này để học tối đa " . $limit . " trang (Lập sơ đồ · Đọc trang · Vào kho).\n";
	}

	/** A finished job may be queued again; a running one holds the slot. Pure. */
	public static function crawl_busy( string $state ): bool {
		return in_array( $state, self::CRAWL_BUSY, true );
	}

	/** Did the person press something the cell has not acted on yet? (want.at after crawl.want_seen) Pure. */
	public static function want_pending( array $crawl, array $want ): bool {
		$at = (string) ( $want['at'] ?? '' );
		return '' !== $at && strcmp( $at, (string) ( $crawl['want_seen'] ?? '' ) ) > 0;
	}

	/**
	 * The REST view of one website job from its Journal row (journal-entry@1). [] when the row is not a website job. Pure.
	 *
	 * @return array{entry_id:int,ref:string,id:string,url:string,host:string,title:string,folder:string,crawl:array,want:?array,want_pending:bool,busy:bool,running:bool}|array{}
	 */
	public static function crawl_view( array $entry ): array {
		$card = is_array( $entry['card'] ?? null ) ? $entry['card'] : array();
		if ( 'common.link' !== (string) ( $entry['kind'] ?? '' ) || 'site' !== (string) ( $card['web_kind'] ?? '' ) ) {
			return array();
		}
		$meta  = is_array( $entry['meta'] ?? null ) ? $entry['meta'] : array();
		$crawl = is_array( $meta['crawl'] ?? null ) ? $meta['crawl'] : array( 'state' => 'queued' );
		$want  = is_array( $meta['crawl_want'] ?? null ) ? $meta['crawl_want'] : null;
		$state = (string) ( $crawl['state'] ?? 'queued' );
		return array(
			'entry_id'     => (int) ( $entry['entry_id'] ?? 0 ),
			'ref'          => (string) ( $entry['ref_code'] ?? '' ),
			'id'           => 'lib:' . (string) ( $card['vault_path'] ?? '' ),
			'url'          => (string) ( $card['url'] ?? '' ),
			'host'         => (string) ( $card['url_host'] ?? '' ),
			'title'        => (string) ( $entry['title'] ?? '' ),
			'folder'       => (string) ( $meta['folder'] ?? '' ),
			'crawl'        => $crawl,
			'want'         => $want,
			'want_pending' => null !== $want && self::want_pending( $crawl, $want ),
			'busy'         => self::crawl_busy( $state ),
			'running'      => in_array( $state, self::CRAWL_RUNNING, true ),
		);
	}

	/** states that hold the ONE running slot of a person (a queued job waits behind them) */
	const CRAWL_ACTIVE = array( 'mapping', 'mapped', 'reading', 'paused' );

	/**
	 * [2026-11-22 08:30 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.153 S153-C5 (chủ 22/11: "cần hiện website dca.com.vn đang chờ để học, để user biết là chờ super-mro học xong") —
	 * the queue of a person: ONE job runs (mapping / mapped / reading / paused), queued jobs wait in order (oldest first). Each queued view gets `position` (1 = next)
	 * and `waiting_for` (the host it waits behind). Pure.
	 */
	public static function crawl_queue( array $views ): array {
		$active = null;
		foreach ( $views as $v ) {
			if ( in_array( (string) ( $v['crawl']['state'] ?? '' ), self::CRAWL_ACTIVE, true ) ) {
				$active = $v;
				break;
			}
		}
		$queued = array_values( array_filter( $views, static function ( $v ) {
			return 'queued' === (string) ( $v['crawl']['state'] ?? '' );
		} ) );
		usort( $queued, static function ( $a, $b ) {
			return (int) $a['entry_id'] <=> (int) $b['entry_id'];
		} );
		$pos  = array();
		$prev = $active ? (string) $active['host'] : '';
		foreach ( $queued as $i => $q ) {
			$pos[ (int) $q['entry_id'] ] = array( 'position' => $i + 1, 'waiting_for' => $prev );
			$prev                        = (string) $q['host'];
		}
		foreach ( $views as $k => $v ) {
			$p = $pos[ (int) $v['entry_id'] ] ?? null;
			if ( $p && ( $active || $p['position'] > 1 ) ) { // the first queued job with nothing running ahead is simply "chờ trợ lý nhận việc"
				$views[ $k ]['queue'] = $p;
			}
		}
		return $views;
	}

	/** entry ids of a person's website jobs, newest first (user meta, ≤ 20 — R-DATA-STORAGE: small per-user list, no table). */
	private static function crawl_ids( int $owner ): array {
		$ids = function_exists( 'get_user_meta' ) ? get_user_meta( $owner, self::CRAWL_JOBS_KEY, true ) : array();
		return array_values( array_filter( array_map( 'intval', is_array( $ids ) ? $ids : array() ) ) );
	}

	private static function crawl_remember( int $owner, int $entry_id ): void {
		$ids = array_slice( array_values( array_unique( array_merge( array( $entry_id ), self::crawl_ids( $owner ) ) ) ), 0, 20 );
		update_user_meta( $owner, self::CRAWL_JOBS_KEY, $ids );
	}

	/** @return array[] views of the person's jobs (rows that are gone or are no longer website jobs are skipped) */
	private static function crawl_jobs( int $owner ): array {
		$out = array();
		if ( ! class_exists( 'BizCity_Journal_Index' ) ) {
			return $out;
		}
		foreach ( self::crawl_ids( $owner ) as $id ) {
			$row  = BizCity_Journal_Index::get( $id, $owner );
			$view = is_array( $row ) ? self::crawl_view( $row ) : array();
			if ( $view ) {
				$out[] = $view;
			}
		}
		$out = self::crawl_queue( $out ); // PHASE-0.153 S153-C5
		// [2026-11-23 11:29 AM Johnny Chu - Chu Hoàng Anh] HOTFIX-SITE-CRAWL-RUNNER — every job of the person says whether an assistant can take it
		$runner = $out ? self::runner( $owner ) : null;
		if ( $runner ) {
			foreach ( $out as $k => $v ) {
				$out[ $k ]['runner'] = $runner;
			}
		}
		return $out;
	}

	/**
	 * [2026-11-23 11:29 AM Johnny Chu - Chu Hoàng Anh] HOTFIX-SITE-CRAWL-RUNNER (PHASE-0.153 S153-C8, chủ 23/11) — who takes the job: the cell works only for a
	 * Kho whose owner has a ready Zalo assistant number (Agent Guru + "UID chủ tài khoản" + owner agent on). Otherwise the job waits forever; the Kho now says
	 * why and what to do (R-ERROR-UX). null = unknown (the Zalo plugin is not loaded) ⇒ nothing new is said.
	 */
	public static function runner( int $owner ): ?array {
		static $memo = array();
		if ( isset( $memo[ $owner ] ) ) {
			return $memo[ $owner ];
		}
		if ( ! class_exists( 'BizCity_Zalo_Hub_Config_Sync' ) || ! method_exists( 'BizCity_Zalo_Hub_Config_Sync', 'owner_ready' ) ) {
			return null;
		}
		$r    = BizCity_Zalo_Hub_Config_Sync::owner_ready( $owner );
		$u    = function_exists( 'get_userdata' ) ? get_userdata( $owner ) : null;
		$who  = $u ? (string) $u->display_name : 'user #' . $owner;
		$num  = '' !== $r['label'] ? 'Số Zalo ' . $r['label'] : 'Số Zalo của bạn';
		$text = array(
			'assistant_no_number'    => array( 'Tài khoản ' . $who . ' chưa có số Zalo trợ lý nên chưa ai nhận việc này.', 'Nhờ quản trị ghép một số Zalo cho tài khoản này (Kho tri thức › Trợ lý của tôi); có số là trợ lý tự nhận việc trong khoảng 5 phút.' ),
			'assistant_no_guru'      => array( $num . ' chưa gắn Agent Guru nên trợ lý chưa nhận việc này.', 'Mở Channel Gateway › Bot Studio, chọn Agent Guru cho số này; trợ lý tự nhận việc trong khoảng 5 phút.' ),
			'assistant_no_owner_uid' => array( $num . ' chưa khai "UID chủ tài khoản" nên trợ lý chưa nhận việc này.', 'Mở Channel Gateway › Bot Studio, điền UID chủ tài khoản cho số này; trợ lý tự nhận việc trong khoảng 5 phút.' ),
			'assistant_off'          => array( 'Trợ lý chủ của số ' . ( '' !== $r['label'] ? $r['label'] : 'Zalo của bạn' ) . ' đang tắt nên chưa nhận việc này.', 'Bật lại trợ lý chủ của số này ở Channel Gateway › Bot Studio.' ),
		);
		$out = $r['ready']
			? array( 'ready' => true, 'label' => (string) $r['label'] )
			: array(
				'ready'     => false,
				'code'      => (string) $r['code'],
				'message'   => $text[ $r['code'] ][0],
				'hint'      => $text[ $r['code'] ][1],
				'help_code' => 'vault_site_crawl',
				'url'       => function_exists( 'admin_url' ) ? admin_url( 'admin.php?page=bizcity-my-assistant' . ( (int) get_current_user_id() !== $owner ? '&as_user=' . $owner : '' ) ) : '',
			);
		$memo[ $owner ] = $out;
		return $out;
	}

	private static function crawl_owner(): int {
		$ctx = class_exists( 'BizCity_Twin_Vault' ) && method_exists( 'BizCity_Twin_Vault', 'person_ctx' ) ? BizCity_Twin_Vault::person_ctx() : array();
		return (int) ( $ctx['owner_user_id'] ?? get_current_user_id() );
	}

	private static function crawl_meta( int $owner, int $entry_id, array $meta ) {
		if ( ! class_exists( 'BizCity_Journal_Entry_Meta' ) ) {
			return new WP_Error( 'journal_unavailable', 'Sổ chưa sẵn sàng trên site này.' );
		}
		return BizCity_Journal_Entry_Meta::set( $owner, array( 'entry_id' => $entry_id ), $meta, array( 'merge' => true, 'source' => 'site_code' ) );
	}

	private static function crawl_fail( string $code, string $message, int $status, string $hint, array $extra = array() ): WP_REST_Response {
		return new WP_REST_Response( array( 'code' => $code, 'message' => $message, 'hint' => $hint, 'help_code' => 'vault_site_crawl' ) + $extra, $status );
	}

	/**
	 * POST bizcity-vault/v1/crawl {url, scope?: "site"|"section", limit?: 50|200|500|1000, presets?: ["login","cart","tag","search"], exclude?: ["/x"],
	 * subdomains?: bool, folder?: "60", preview?: bool} ⇒ {contract, job}. Writes the waiting website note + meta (status pending, crawl.state queued).
	 */
	public static function rest_crawl_start( WP_REST_Request $r ): WP_REST_Response {
		if ( ! class_exists( 'BizCity_Twin_Vault' ) || ! method_exists( 'BizCity_Twin_Vault', 'person_ctx' ) || ! class_exists( 'BizCity_Journal_Index' ) ) {
			return self::crawl_fail( 'vault_unavailable', 'Kho tri thức chưa sẵn sàng trên site này.', 503, 'Thử lại sau ít phút.' );
		}
		$b    = $r->get_json_params();
		$b    = is_array( $b ) ? $b : (array) $r->get_body_params();
		$raw  = trim( (string) ( $b['url'] ?? '' ) );
		$root = self::site_root( $raw );
		if ( ! $root ) {
			$host = (string) parse_url( 1 === preg_match( '#^https?://#i', $raw ) ? $raw : 'https://' . $raw, PHP_URL_HOST );
			return '' !== $raw && '' !== $host && ! self::is_public_host( $host )
				? self::crawl_fail( 'site_private', 'Địa chỉ nội bộ, trợ lý không đọc được.', 400, 'Nhập tên miền công khai, ví dụ ten-mien.com.' )
				: self::crawl_fail( 'site_invalid', 'Địa chỉ website chưa đúng.', 400, 'Nhập tên miền, ví dụ ten-mien.com.' );
		}
		$ctx   = BizCity_Twin_Vault::person_ctx();
		$owner = (int) ( $ctx['owner_user_id'] ?? get_current_user_id() );
		// [2026-11-22 08:30 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.153 S153-C5 — another website is no longer refused: it QUEUES behind the running one (one runs, Q153-C1);
		// only the same website twice is refused
		$queued_after = '';
		foreach ( self::crawl_jobs( $owner ) as $job ) {
			if ( $job['busy'] && $job['host'] === $root['host'] ) {
				return self::crawl_fail( 'site_busy', $job['host'] . ' đang được học hoặc đang chờ (' . (int) ( $job['crawl']['stored'] ?? 0 ) . '/' . (int) ( $job['crawl']['kept'] ?? $job['crawl']['limit'] ?? 0 ) . ' trang).', 409,
					'Mở tiến trình để theo dõi, hoặc đợi xong rồi bấm "Học lại thay đổi".', array( 'job' => $job ) );
			}
			if ( $job['busy'] ) {
				$queued_after = $job['host'];
			}
		}
		$limit   = self::crawl_limit( $b['limit'] ?? self::CRAWL_DEFAULT );
		$section = 'section' === (string) ( $b['scope'] ?? 'site' ) ? (string) ( $b['path'] ?? ( $root['path'] ?? '' ) ) : '';
		$section = 1 === preg_match( '#^/[a-z0-9._~%-]{1,80}$#i', '/' . trim( $section, '/ ' ) ) && '' !== trim( $section, '/ ' ) ? '/' . trim( $section, '/ ' ) : '';
		$scope   = array(
			'host'       => $root['host'],
			'subdomains' => ! empty( $b['subdomains'] ),
			'exclude'    => self::crawl_exclude( (array) ( $b['presets'] ?? array_keys( self::CRAWL_PRESETS ) ), (array) ( $b['exclude'] ?? array() ) ),
		) + ( '' !== $section ? array( 'path' => $section ) : array() );
		$fold  = substr( preg_replace( '/\D/', '', (string) ( $b['folder'] ?? '' ) ), 0, 2 );
		$path  = self::site_path( $root['host'] );
		$res = BizCity_Twin_Vault::write( array(
			'title'    => 'Website ' . $root['host'] . ( '' !== $section ? $section : '' ),
			'markdown' => self::site_pending_markdown( $root['url'], $limit ),
			'path'     => $path,
			'link'     => array( 'url' => $root['url'], 'depth' => 0, 'status' => 'pending', 'from' => 'web', 'kind' => 'site' ),
		) + ( '' !== $fold ? array( 'folder' => $fold ) : array() ), $ctx );
		if ( is_wp_error( $res ) ) {
			$d = (array) $res->get_error_data();
			return self::crawl_fail( $res->get_error_code(), $res->get_error_message(), (int) ( $d['status'] ?? 400 ), (string) ( $d['hint'] ?? 'Thử lại sau ít phút.' ) );
		}
		$entry_id = (int) ( $res['entry_id'] ?? 0 );
		$old      = $entry_id > 0 ? BizCity_Journal_Index::get( $entry_id, $owner ) : null; // the same host learned before ⇒ the same row (idempotent key): next run
		$run      = 1 + (int) ( $old['meta']['crawl']['run'] ?? 0 );
		$now      = gmdate( 'Y-m-d\TH:i:s\Z' );
		$set      = self::crawl_meta( $owner, $entry_id, array(
			'status' => 'pending', // the cell's beat looks for pending link rows; it tells a website job by card.web_kind
			'crawl'  => array( 'state' => 'queued', 'scope' => $scope, 'limit' => $limit, 'run' => $run, 'preview' => ! empty( $b['preview'] ), 'updated_at' => $now, 'want_seen' => $now ), // an old button of a previous run is not pending

		) );
		if ( is_wp_error( $set ) ) {
			error_log( '[vault-link] crawl queue ' . $entry_id . ': ' . $set->get_error_code() );
			return self::crawl_fail( 'site_queue_failed', 'Chưa xếp được việc học website.', 500, 'Thử lại sau ít phút; nếu vẫn lỗi, báo quản trị.' );
		}
		self::crawl_remember( $owner, $entry_id );
		$view = null;
		foreach ( self::crawl_jobs( $owner ) as $j ) { // the view with its place in the queue
			if ( (int) $j['entry_id'] === $entry_id ) {
				$view = $j;
			}
		}
		$view = $view ?: self::crawl_view( $set );
		$runner = self::runner( $owner ); // [2026-11-23 11:29 AM Johnny Chu - Chu Hoàng Anh] HOTFIX-SITE-CRAWL-RUNNER
		if ( $view && $runner && ! isset( $view['runner'] ) ) {
			$view['runner'] = $runner;
		}
		return new WP_REST_Response( array( 'contract' => self::CRAWL_CONTRACT, 'job' => $view ?: array( 'entry_id' => $entry_id, 'ref' => (string) ( $set['ref_code'] ?? '' ) ), 'queued_after' => $queued_after ), 200 );
	}

	/** GET bizcity-vault/v1/crawl[?active=1] ⇒ {contract, jobs[], poll_s} — poll_s = 15 while a job runs, 0 when the Kho may stop pulling. */
	public static function rest_crawl_list( WP_REST_Request $r ): WP_REST_Response {
		$jobs = self::crawl_jobs( self::crawl_owner() );
		if ( $r->get_param( 'active' ) ) {
			$jobs = array_values( array_filter( $jobs, static function ( $j ) {
				return $j['busy'] || $j['want_pending'];
			} ) );
		}
		$running = array_filter( $jobs, static function ( $j ) {
			return $j['running'] || $j['want_pending'];
		} );
		return new WP_REST_Response( array( 'contract' => self::CRAWL_CONTRACT, 'jobs' => $jobs, 'poll_s' => $running ? self::CRAWL_POLL_S : 0 ), 200 );
	}

	/** GET bizcity-vault/v1/crawl/<entry_id> ⇒ {contract, job} (404 when it is not this person's website job). */
	public static function rest_crawl_get( WP_REST_Request $r ): WP_REST_Response {
		$owner = self::crawl_owner();
		$row   = class_exists( 'BizCity_Journal_Index' ) ? BizCity_Journal_Index::get( (int) $r['id'], $owner ) : null;
		$view  = is_array( $row ) ? self::crawl_view( $row ) : array();
		if ( ! $view ) {
			return self::crawl_fail( 'site_not_found', 'Không có việc học website này trong Kho của bạn.', 404, 'Tải lại Kho rồi mở lại tiến trình.' );
		}
		$runner = self::runner( $owner ); // [2026-11-23 11:29 AM Johnny Chu - Chu Hoàng Anh] HOTFIX-SITE-CRAWL-RUNNER
		if ( $runner ) {
			$view['runner'] = $runner;
		}
		return new WP_REST_Response( array( 'contract' => self::CRAWL_CONTRACT, 'job' => $view, 'poll_s' => $view['running'] || $view['want_pending'] ? self::CRAWL_POLL_S : 0 ), 200 );
	}

	/**
	 * POST bizcity-vault/v1/crawl/<entry_id>/<verb> — the person's button. The site writes `crawl_want` only; the cell acts on its next batch (≤ 60 s)
	 * and moves `crawl.state`. Two exceptions, where no cell is working on the job: cancel of a queued job ⇒ cancelled now; recrawl / more of a
	 * finished job ⇒ queued again (status pending, run + 1 for recrawl).
	 */
	public static function rest_crawl_verb( WP_REST_Request $r ): WP_REST_Response {
		$owner = self::crawl_owner();
		$verb  = (string) $r['verb'];
		$row   = class_exists( 'BizCity_Journal_Index' ) ? BizCity_Journal_Index::get( (int) $r['id'], $owner ) : null;
		$view  = is_array( $row ) ? self::crawl_view( $row ) : array();
		if ( ! $view ) {
			return self::crawl_fail( 'site_not_found', 'Không có việc học website này trong Kho của bạn.', 404, 'Tải lại Kho rồi mở lại tiến trình.' );
		}
		$crawl = $view['crawl'];
		$state = (string) ( $crawl['state'] ?? 'queued' );
		if ( ! in_array( $state, self::CRAWL_VERBS[ $verb ] ?? array(), true ) || ( 'more' === $verb && (int) ( $crawl['dropped']['over_limit'] ?? 0 ) <= 0 ) ) {
			return self::crawl_fail( 'site_verb_refused', 'Việc đang ở trạng thái "' . $state . '", chưa làm được thao tác này.', 409, 'Tải lại tiến trình rồi chọn lại.', array( 'job' => $view ) );
		}
		// PHASE-0.153 S153-C5 — "Học lại" / "Học tiếp" while another website runs: queued behind it (no refusal)
		$now  = gmdate( 'Y-m-d\TH:i:s\Z' );
		$meta = array( 'crawl_want' => array( 'do' => $verb, 'at' => $now, 'by' => (int) get_current_user_id() ) );
		if ( 'cancel' === $verb && 'queued' === $state ) {
			$meta['crawl']  = array( 'state' => 'cancelled', 'finished_at' => $now, 'updated_at' => $now, 'want_seen' => $now ) + $crawl;
			$meta['status'] = 'skipped';
		} elseif ( 'recrawl' === $verb || 'more' === $verb ) {
			$next = array( 'state' => 'queued', 'updated_at' => $now ) + $crawl;
			unset( $next['finished_at'], $next['error'] );
			if ( 'recrawl' === $verb ) {
				$next['run'] = 1 + (int) ( $crawl['run'] ?? 1 );
			} else {
				$next['limit'] = min( 1000, (int) ( $crawl['limit'] ?? self::CRAWL_DEFAULT ) + (int) $crawl['dropped']['over_limit'] );
			}
			$meta['crawl']  = $next;
			$meta['status'] = 'pending';
		}
		$set = self::crawl_meta( $owner, (int) $view['entry_id'], $meta );
		if ( is_wp_error( $set ) ) {
			error_log( '[vault-link] crawl ' . $verb . ' ' . $view['entry_id'] . ': ' . $set->get_error_code() );
			return self::crawl_fail( 'site_verb_failed', 'Chưa ghi được thao tác.', 500, 'Thử lại sau ít phút.' );
		}
		$out = self::crawl_view( $set );
		return new WP_REST_Response( array( 'contract' => self::CRAWL_CONTRACT, 'job' => $out ?: $view, 'poll_s' => self::CRAWL_POLL_S ), 200 );
	}

	// ── pure ─────────────────────────────────────────────────────────────

	/** "HTTPS://Example.com/a?utm_source=x&b=1#top" ⇒ "https://example.com/a?b=1"; '' when not a public-looking http(s) URL. Pure. */
	public static function normalize_url( string $raw ): string {
		$raw = trim( $raw );
		if ( '' === $raw || strlen( $raw ) > 2048 || 1 !== preg_match( '#^https?://#i', $raw ) || preg_match( '/\s/', $raw ) ) {
			return '';
		}
		$p = parse_url( $raw );
		if ( ! is_array( $p ) || empty( $p['host'] ) || ! empty( $p['user'] ) || ! empty( $p['pass'] ) ) {
			return '';
		}
		$host = strtolower( rtrim( (string) $p['host'], '.' ) );
		if ( ! self::is_public_host( $host ) ) {
			return '';
		}
		$scheme = strtolower( (string) $p['scheme'] );
		$port   = isset( $p['port'] ) && ! in_array( (int) $p['port'], array( 80, 443 ), true ) ? ':' . (int) $p['port'] : '';
		$path   = '' !== (string) ( $p['path'] ?? '' ) ? (string) $p['path'] : '/';
		$query  = '';
		if ( '' !== (string) ( $p['query'] ?? '' ) ) {
			$keep = array();
			foreach ( explode( '&', (string) $p['query'] ) as $pair ) {
				$k = strtolower( (string) strtok( $pair, '=' ) );
				if ( '' === $k || 0 === strpos( $k, 'utm_' ) || in_array( $k, self::TRACKING, true ) ) {
					continue;
				}
				$keep[] = $pair;
			}
			$query = $keep ? '?' . implode( '&', $keep ) : '';
		}
		return $scheme . '://' . $host . $port . $path . $query;
	}

	/** A host a person may hand in: a name with a dot, not localhost / *.local / *.internal, not an IP literal in a private / loopback / reserved range. Pure. */
	public static function is_public_host( string $host ): bool {
		$host = strtolower( trim( $host, '[]' ) );
		if ( '' === $host || 'localhost' === $host || preg_match( '/\.(local|localhost|internal|lan|home|corp|intranet)$/', $host ) ) {
			return false;
		}
		if ( false !== filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return false !== filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
		}
		return 1 === preg_match( '/^(?=.{1,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9-]{2,63}$/', $host );
	}

	/** "https://www.Example.gov.vn/x" ⇒ "example.gov.vn" (no leading www). Pure. */
	public static function host_of( string $url ): string {
		$h = strtolower( (string) parse_url( $url, PHP_URL_HOST ) );
		return 0 === strpos( $h, 'www.' ) ? substr( $h, 4 ) : $h;
	}

	/** The library path of a page: /link/<host>/<sha1(url)[0:12]>.md — the same URL is always the same note. Pure. */
	public static function note_path( string $normalized_url ): string {
		$host = preg_replace( '/[^a-z0-9.-]/', '-', self::host_of( $normalized_url ) );
		$host = trim( substr( (string) $host, 0, 63 ), '.-' );
		return '/link/' . ( '' !== $host ? $host : 'web' ) . '/' . substr( sha1( $normalized_url ), 0, 12 ) . '.md';
	}

	/**
	 * The `web` block of a link note (frontmatter + Journal card), every key checked; [] when there is no usable URL. Pure.
	 *
	 * @return array{url?:string,host?:string,from?:string,status?:string,depth?:int,fetched_at?:string,parent_ref?:string,internal?:bool,authority?:int,via?:string,content_sha256?:string,links_found?:int,links_kept?:int,title?:string}
	 */
	public static function web_fm( array $in ): array {
		$url = self::normalize_url( (string) ( $in['url'] ?? '' ) );
		if ( '' === $url ) {
			return array();
		}
		$out = array( 'url' => $url, 'host' => self::host_of( $url ) );
		$from        = (string) ( $in['from'] ?? 'web' );
		$out['from'] = in_array( $from, self::FROM, true ) ? $from : 'web';
		$st            = (string) ( $in['status'] ?? 'pending' );
		$out['status'] = in_array( $st, self::STATUSES, true ) ? $st : 'pending';
		$out['depth']  = max( 0, min( 2, (int) ( $in['depth'] ?? 0 ) ) );
		if ( 1 === preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2})?(\.\d+)?Z$/', (string) ( $in['fetched_at'] ?? '' ) ) ) {
			$out['fetched_at'] = (string) $in['fetched_at'];
		}
		if ( 1 === preg_match( '/^LK-\d{1,12}$/', strtoupper( (string) ( $in['parent_ref'] ?? '' ) ) ) ) {
			$out['parent_ref'] = strtoupper( (string) $in['parent_ref'] );
		}
		if ( isset( $in['internal'] ) ) {
			$out['internal'] = (bool) $in['internal'];
		}
		if ( isset( $in['authority'] ) && is_numeric( $in['authority'] ) ) {
			$out['authority'] = max( 0, min( 100, (int) $in['authority'] ) );
		}
		$via = (string) ( $in['via'] ?? '' );
		if ( '' !== $via && in_array( $via, self::VIA, true ) ) {
			$out['via'] = $via;
		}
		// [2026-11-22 06:57 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.153 S153-C1 — kind "site" = the note of a whole-website job (site-crawl@1); a page has no kind
		if ( 'site' === (string) ( $in['kind'] ?? '' ) ) {
			$out['kind'] = 'site';
		}
		if ( 1 === preg_match( '/^[0-9a-f]{64}$/', (string) ( $in['content_sha256'] ?? '' ) ) ) {
			$out['content_sha256'] = (string) $in['content_sha256'];
		}
		foreach ( array( 'links_found', 'links_kept' ) as $k ) {
			if ( isset( $in[ $k ] ) && is_numeric( $in[ $k ] ) ) {
				$out[ $k ] = max( 0, min( 500, (int) $in[ $k ] ) );
			}
		}
		$title = trim( (string) preg_replace( '/\s+/u', ' ', (string) ( $in['title'] ?? '' ) ) );
		if ( '' !== $title ) {
			$out['title'] = mb_substr( $title, 0, 160 );
		}
		return $out;
	}

	/** The Journal card of a link row: the url + what a person reads on the card (card_fields title · url_host · summary). Pure. */
	public static function card_of( array $web, string $vault_path ): array {
		// only what never changes after the first write (the card is set when the row is born; status / fetched_at live in meta + frontmatter)
		$c = array( 'vault_path' => $vault_path, 'url' => (string) ( $web['url'] ?? '' ), 'url_host' => (string) ( $web['host'] ?? '' ), 'depth' => (int) ( $web['depth'] ?? 0 ) );
		foreach ( array( 'parent_ref', 'authority', 'internal' ) as $k ) {
			if ( isset( $web[ $k ] ) ) {
				$c[ $k ] = $web[ $k ];
			}
		}
		if ( 'site' === (string) ( $web['kind'] ?? '' ) ) { // PHASE-0.153 S153-C1 — the cell and the Kho tell a website job from a page by this
			$c['web_kind'] = 'site';
		}
		return $c;
	}

	/**
	 * [2026-11-22 04:21 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.153 S153-L2 (chủ 22/11: "ghi link đầy đủ, không chỉ super-mro.com") — the name of a waiting note:
	 * the whole link without https:// (≤ 160, cut in the middle with …). Pure.
	 */
	public static function display_url( string $url ): string {
		$s = rtrim( (string) preg_replace( '#^https?://(www\.)?#i', '', $url ), '/' );
		return mb_strlen( $s ) <= 160 ? $s : mb_substr( $s, 0, 110 ) . '…' . mb_substr( $s, -49 );
	}

	/** The body of a note that waits for the cell. Pure. */
	public static function pending_markdown( string $url ): string {
		return '> Nguồn: <' . $url . ">\n\nTrợ lý đang chờ đọc trang này (Ghi sổ ✓ · Đọc · Vào kho).\n";
	}

	/** urls[] (array, or one string with a link per line) ⇒ trimmed, de-duplicated, order kept. Pure. */
	public static function urls_in( $raw ): array {
		$list = is_array( $raw ) ? $raw : preg_split( '/[\r\n]+/', (string) $raw );
		$out  = array();
		foreach ( (array) $list as $u ) {
			$u = is_scalar( $u ) ? trim( (string) $u ) : '';
			if ( '' !== $u && ! in_array( $u, $out, true ) ) {
				$out[] = $u;
			}
		}
		return $out;
	}

	// ── REST ─────────────────────────────────────────────────────────────

	/** POST bizcity-vault/v1/link {urls: string[] | "a\nb", depth?: 0|1|2 (default 1, Q153-L2), folder?: "20"} ⇒ {contract, depth, lines[]} */
	public static function rest_link( WP_REST_Request $r ): WP_REST_Response {
		if ( ! class_exists( 'BizCity_Twin_Vault' ) || ! method_exists( 'BizCity_Twin_Vault', 'person_ctx' ) ) {
			return self::fail( 'vault_unavailable', 'Kho tri thức chưa sẵn sàng trên site này.', 503, 'Thử lại sau ít phút.' );
		}
		$b     = $r->get_json_params();
		$b     = is_array( $b ) ? $b : (array) $r->get_body_params();
		$urls  = self::urls_in( $b['urls'] ?? '' );
		$depth = isset( $b['depth'] ) ? max( 0, min( 2, (int) $b['depth'] ) ) : 1;
		$fold  = substr( preg_replace( '/\D/', '', (string) ( $b['folder'] ?? '' ) ), 0, 2 );
		if ( ! $urls ) {
			return self::fail( 'link_invalid', 'Chưa có link nào.', 400, 'Dán một link bắt đầu bằng https://' );
		}
		if ( count( $urls ) > self::MAX_URLS ) {
			return self::fail( 'link_too_many', 'Tối đa ' . self::MAX_URLS . ' link một lần.', 400, 'Bớt link rồi gửi lại, phần còn lại gửi lượt sau.' );
		}
		$uid   = (int) get_current_user_id();
		$tkey  = 'bizcity_vault_link_day_' . $uid . '_' . gmdate( 'Ymd' );
		$used  = (int) get_transient( $tkey );
		$ctx   = BizCity_Twin_Vault::person_ctx();
		$lines = array();
		foreach ( $urls as $raw ) {
			$url = self::normalize_url( $raw );
			if ( '' === $url ) {
				$private = 1 === preg_match( '#^https?://#i', $raw ) && ! self::is_public_host( (string) parse_url( $raw, PHP_URL_HOST ) );
				$lines[] = array( 'url' => mb_substr( $raw, 0, 300 ), 'error' => $private
					? array( 'code' => 'link_private', 'message' => 'Link nội bộ, trợ lý không đọc được.', 'hint' => 'Dán link công khai trên internet.', 'help_code' => 'vault_link' )
					: array( 'code' => 'link_invalid', 'message' => 'Link không hợp lệ.', 'hint' => 'Dán đủ link, bắt đầu bằng https://', 'help_code' => 'vault_link' ) );
				continue;
			}
			$path = self::note_path( $url );
			$have = BizCity_Twin_Vault::note( 'lib:' . $path, $ctx, 0, 200 );
			if ( ! is_wp_error( $have ) && is_array( $have['frontmatter']['web'] ?? null ) ) {
				$st      = (string) ( $have['frontmatter']['web']['status'] ?? '' );
				$lines[] = array( 'url' => $url, 'id' => 'lib:' . $path, 'ref' => strtoupper( (string) strtok( (string) ( $have['source_ref'] ?? '' ), '@' ) ), 'duplicate' => 'done' === $st, 'status' => '' !== $st ? $st : 'pending' );
				continue;
			}
			if ( $used >= self::DAY_MAX ) {
				$lines[] = array( 'url' => $url, 'error' => array( 'code' => 'link_quota', 'message' => 'Hết lượt đọc link hôm nay (' . self::DAY_MAX . '/' . self::DAY_MAX . ').', 'hint' => 'Thử lại vào ngày mai.', 'help_code' => 'vault_link' ) );
				continue;
			}
			$res = BizCity_Twin_Vault::write( array(
				'title'    => self::display_url( $url ), // PHASE-0.153 S153-L2 — the whole link, not only the host
				'markdown' => self::pending_markdown( $url ),
				'path'     => $path,
				'link'     => array( 'url' => $url, 'depth' => $depth, 'status' => 'pending', 'from' => 'web' ),
			) + ( '' !== $fold ? array( 'folder' => $fold ) : array() ), $ctx );
			if ( is_wp_error( $res ) ) {
				$d       = (array) $res->get_error_data();
				$lines[] = array( 'url' => $url, 'error' => array( 'code' => $res->get_error_code(), 'message' => $res->get_error_message(), 'hint' => (string) ( $d['hint'] ?? 'Thử lại sau ít phút.' ), 'help_code' => 'vault_link' ) );
				continue;
			}
			++$used;
			self::mark_pending( (int) ( $ctx['owner_user_id'] ?? $uid ), (int) ( $res['entry_id'] ?? 0 ) );
			$lines[] = array( 'url' => $url, 'id' => (string) $res['id'], 'ref' => strtoupper( (string) strtok( (string) $res['source_ref'], '@' ) ), 'entry_id' => (int) ( $res['entry_id'] ?? 0 ), 'duplicate' => false, 'status' => 'pending', 'folder' => (string) ( $res['vault_folder'] ?? '' ) );
		}
		set_transient( $tkey, $used, DAY_IN_SECONDS );
		// [2026-11-23 11:29 AM Johnny Chu - Chu Hoàng Anh] HOTFIX-SITE-CRAWL-RUNNER — single links wait for the same assistant
		$runner = self::runner( (int) ( $ctx['owner_user_id'] ?? $uid ) );
		return new WP_REST_Response( array( 'contract' => self::CONTRACT, 'depth' => $depth, 'lines' => $lines ) + ( $runner ? array( 'runner' => $runner ) : array() ), 200 );
	}

	/** meta.status pending on the row (the cell's 5-minute beat looks for exactly that); a refusal is logged, the note stays. */
	public static function mark_pending( int $owner, int $entry_id ): void {
		if ( $entry_id <= 0 || ! class_exists( 'BizCity_Journal_Entry_Meta' ) ) {
			return;
		}
		$r = BizCity_Journal_Entry_Meta::set( $owner, array( 'entry_id' => $entry_id ), array( 'status' => 'pending' ), array( 'merge' => true, 'source' => 'site_code' ) );
		if ( is_wp_error( $r ) ) {
			error_log( '[vault-link] mark pending ' . $entry_id . ': ' . $r->get_error_code() );
		}
	}

	private static function fail( string $code, string $message, int $status, string $hint = '' ): WP_REST_Response {
		return new WP_REST_Response( array( 'code' => $code, 'message' => $message, 'hint' => $hint, 'help_code' => 'vault_link' ), $status );
	}
}
