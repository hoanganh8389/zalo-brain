<?php
/**
 * brain-map@1 — "Bản đồ não": where each of the person's documents sits in their brain (contracts/BRAIN-MAP-v1.json).
 *
 * A VIEW over the person's own journal rows: documents (layer document / output) are dots, notebooks · Zalo groups · CRM
 * contacts · jobs are hubs, versions and derivations are lines. Every number is counted here from rows; no model, no table,
 * no second store. The level of a document follows knowledge-ladder@1 (L0 saved · L1 found instantly · L2 understood ·
 * L3 linked · L4 used), the instant layer being the person's doc_index@3 pack.
 *
 *   GET bizcity-knowledge/v2/journal/map?entry=   TwinChat › Journal › Bản đồ (a logged-in person, their own rows)
 *   bizcity-panel/v1 brain/map                    the owner panel `brain.map` (BizCity_Panel_REST calls rest())
 *   receipt_panel_link()                          the one-time link the second-brain receipt carries (job.panel_link)
 *
 * Cache contract (R-CACHE): group `journal` (BizCity_Journal_Database::CACHE_GROUP, flushed on every journal write);
 * key `brain_map_<blog>_<owner>_<focus>`; TTL 60 s; a degraded answer (journal missing) is never cached.
 *
 * [2026-10-22 01:50 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.129 W1/W2 — new file (owner approved the mockup 2026-10-22).
 *
 * @package BizCity_Twin_AI
 * @subpackage KG_Hub
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_Journal_Brain_Map {

	const CONTRACT = 'brain-map@1.0.0';
	// [2026-10-22 08:31 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.128 doc 68 (owner 20:2x) — 1.1.0 adds only hub kinds sheet / skill hanging off a drawn job (edges writes_sheet / uses_skill)
	const CONTRACT_V11    = 'brain-map@1.1.0';
	// [2026-10-24 05:55 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.133 S133-Mc (doc 81 §8) — 1.2.0 adds person / topic hubs from entry-meta@1 (edges mentions_person / about_topic); only when one is drawn
	const CONTRACT_V12    = 'brain-map@1.2.0';
	const META_PEOPLE_PER_DOC = 3;
	const META_TOPICS_PER_DOC = 2;
	const SKILL_NAME_RE   = '/^[a-z0-9][a-z0-9-]{0,63}$/';
	const LADDER   = 'knowledge-ladder@1.0.0';

	const MAX_NODES = 300;
	const MAX_DOCS  = 240;
	const MAX_HUBS  = 60;
	const SCAN      = 500;
	const CACHE_TTL = 60;

	const OPT_RECEIPT    = 'bizcity_brain_map_receipt';    // 'off' = no map link in the receipt
	const OPT_RECEIPT_AT = 'bizcity_brain_map_receipt_at'; // last time a receipt carried a link
	const RECEIPT_EVERY  = 600;                             // ≤ 1 link / 10 min (D129-2)

	const LEVELS = array( 'L0', 'L1', 'L2', 'L3', 'L4' );

	// [2026-10-22 04:30 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.129 W3 (B7) — "Lượt tra" / "Lỗ hổng" measured where the search happens: the site's knowledge.search
	// (the assistant's notebook_search). One small rolling user option of the person (≤ 30 day buckets + ≤ 10 recent empty questions of ≤ 60 chars):
	// low volume, owner-only, nothing else needs it — no table (R-LEAN-4). Customers' searches are never counted here.
	const OPT_LOOKUPS   = 'bizcity_brain_map_lookups';
	const LOOKUP_DAYS   = 30;
	const RECENT_GAPS   = 10;

	private static $hooked = false;

	public static function init(): void {
		if ( self::$hooked ) {
			return;
		}
		self::$hooked = true;
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes(): void {
		register_rest_route( 'bizcity-knowledge/v2', '/journal/map', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'rest' ),
			'permission_callback' => static function () { return is_user_logged_in(); },
		) );
		// [2026-10-22 05:55 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.129 W3 (A16, B10) — the assistant's knowledge maturity (knowledge-maturity@1): read by anyone logged in, imported by a site manager
		register_rest_route( 'bizcity-knowledge/v2', '/journal/maturity', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'rest_maturity' ),
				'permission_callback' => static function () { return is_user_logged_in(); },
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'rest_maturity_import' ),
				'permission_callback' => static function () { return current_user_can( 'manage_options' ); },
			),
		) );
	}

	// ── knowledge maturity (PHASE-0.129 W3, A16 / B10) ────────────────────

	const OPT_MATURITY = 'bizcity_knowledge_maturity'; // blog option: {latest, published, published_at, history[≤10]}
	const MATURITY_CONTRACT = 'knowledge-maturity@1.0.0';
	const MATURITY_MAX_BYTES = 65536;

	public static function rest_maturity(): WP_REST_Response {
		$s = function_exists( 'get_option' ) ? get_option( self::OPT_MATURITY, null ) : null;
		$s = is_array( $s ) ? $s : array();
		return new WP_REST_Response( array(
			'ok'           => true,
			'report'       => $s['latest'] ?? null,
			'published'    => $s['published'] ?? null,
			'published_at' => $s['published_at'] ?? null,
			'history'      => $s['history'] ?? array(),
			'can_import'   => current_user_can( 'manage_options' ),
		), 200 );
	}

	public static function rest_maturity_import( WP_REST_Request $request ): WP_REST_Response {
		$raw = (string) $request->get_body();
		// [2026-10-22 07:28 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.129 reflect #9 — too big says so (not "not a report")
		if ( strlen( $raw ) > self::MATURITY_MAX_BYTES ) {
			return new WP_REST_Response( self::error( 'payload_too_large', 'File báo cáo lớn quá 64 KB.', 'Chọn đúng file knowledge-maturity-….json (thường chỉ vài KB).', 'brain_maturity' ), 413 );
		}
		$r   = json_decode( $raw, true );
		$c   = is_array( $r ) ? self::clean_maturity( $r ) : null;
		if ( null === $c ) {
			return new WP_REST_Response( self::error( 'invalid_param', 'Đây không phải báo cáo knowledge-maturity@1.', 'Chọn file evals/out/knowledge-maturity-….json mà pnpm eval vừa ghi.', 'brain_maturity' ), 400 );
		}
		$prev = get_option( self::OPT_MATURITY, null );
		$next = self::merge_maturity( is_array( $prev ) ? $prev : null, $c );
		update_option( self::OPT_MATURITY, $next, false );
		return self::rest_maturity();
	}

	/** Pure: a knowledge-maturity@1 report reduced to what the screen shows (no raw rows), or null when it is not one. */
	public static function clean_maturity( array $r ): ?array {
		if ( self::MATURITY_CONTRACT !== (string) ( $r['contract'] ?? '' ) || ! is_array( $r['by_level'] ?? null ) ) {
			return null;
		}
		$lv  = static function ( $v ) { return in_array( $v, self::LEVELS, true ) ? $v : null; };
		$num = static function ( $a, string $k ) { return max( 0, min( 1000, (int) ( is_array( $a ) ? ( $a[ $k ] ?? 0 ) : 0 ) ) ); };
		$by  = array();
		foreach ( self::LEVELS as $l ) {
			$b = $r['by_level'][ $l ] ?? null;
			if ( ! is_array( $b ) ) {
				return null;
			}
			$by[ $l ] = array(
				'may'    => array( 'pass' => $num( $b['may'] ?? null, 'pass' ), 'total' => $num( $b['may'] ?? null, 'total' ) ),
				'nguoi'  => array( 'pass' => $num( $b['nguoi'] ?? null, 'pass' ), 'total' => $num( $b['nguoi'] ?? null, 'total' ), 'skipped' => $num( $b['nguoi'] ?? null, 'skipped' ) ),
				'status' => 'skip',
			);
			$by[ $l ]['may']['pass']   = min( $by[ $l ]['may']['pass'], $by[ $l ]['may']['total'] );
			$by[ $l ]['nguoi']['pass'] = min( $by[ $l ]['nguoi']['pass'], $by[ $l ]['nguoi']['total'] );
		}
		$red = array();
		foreach ( array_slice( is_array( $r['red'] ?? null ) ? $r['red'] : array(), 0, 50 ) as $x ) {
			if ( is_array( $x ) && null !== $lv( $x['level'] ?? null ) ) {
				$red[] = array( 'id' => substr( preg_replace( '/[^A-Za-z0-9_-]/', '', (string) ( $x['id'] ?? '' ) ), 0, 60 ), 'level' => $x['level'], 'codes' => array_slice( array_map( static function ( $c ) { return substr( preg_replace( '/[^a-z_]/', '', (string) $c ), 0, 40 ); }, (array) ( $x['codes'] ?? array() ) ), 0, 5 ) );
			}
		}
		// [2026-10-22 07:25 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.129 reflect #12 — every level's status is RE-COMPUTED from its counts (same rule as zalo-hub
		// evals/knowledge-maturity.ts); the file's own statuses and level are never trusted
		foreach ( self::LEVELS as $l ) {
			$by[ $l ]['status'] = self::level_status( $by[ $l ], in_array( $l, array_column( $red, 'level' ), true ) );
		}
		$pub = null;
		foreach ( self::LEVELS as $l ) {
			if ( 'pass' !== $by[ $l ]['status'] ) {
				break;
			}
			$pub = $l;
		}
		$t = strtotime( (string) ( $r['run_at'] ?? '' ) );
		return array(
			'contract'        => self::MATURITY_CONTRACT,
			'run_at'          => false === $t ? gmdate( 'Y-m-d\TH:i:s\Z' ) : gmdate( 'Y-m-d\TH:i:s\Z', $t ),
			'level_published' => $pub,
			'by_level'        => $by,
			'red'             => $red,
			'gaps'            => array_slice( array_values( array_map( static function ( $g ) { return substr( preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $g ), 0, 80 ); }, array_filter( (array) ( $r['gaps'] ?? array() ), 'is_scalar' ) ) ), 0, 50 ),
		);
	}

	/** Pure ([2026-10-22 07:25 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.129 reflect #12): red · skip (a layer empty, or > 50 % human SKIP) · pass (both ≥ 80 %) · fail. */
	public static function level_status( array $b, bool $red ): string {
		if ( $red ) {
			return 'red';
		}
		$mt = (int) $b['may']['total'];
		$nt = (int) $b['nguoi']['total'];
		$ns = (int) $b['nguoi']['skipped'];
		if ( 0 === $mt || 0 === $nt || $ns * 2 > $nt + $ns ) {
			return 'skip';
		}
		return ( (int) $b['may']['pass'] * 5 >= $mt * 4 && (int) $b['nguoi']['pass'] * 5 >= $nt * 4 ) ? 'pass' : 'fail';
	}

	/** Pure: store the new run; the published level never goes down unless the new run has a red finding (R-BCB-16 style). */
	public static function merge_maturity( ?array $prev, array $new ): array {
		$rank = static function ( $l ) { $i = array_search( $l, self::LEVELS, true ); return false === $i ? -1 : (int) $i; };
		$old  = $prev['published'] ?? null;
		$keep = empty( $new['red'] ) && $rank( $old ) > $rank( $new['level_published'] );
		$hist = array_slice( array_merge( array( array( 'run_at' => $new['run_at'], 'level' => $new['level_published'], 'red' => count( $new['red'] ) ) ), is_array( $prev['history'] ?? null ) ? $prev['history'] : array() ), 0, 10 );
		return array(
			'latest'       => $new,
			'published'    => $keep ? $old : $new['level_published'],
			'published_at' => $keep ? ( $prev['published_at'] ?? null ) : $new['run_at'],
			'history'      => $hist,
		);
	}

	// ── REST ─────────────────────────────────────────────────────────────

	/** The current person's map; `entry` (or `$focus`) = the row to put in the middle. Both REST doors call this. */
	public static function rest( WP_REST_Request $request, int $focus = 0 ): WP_REST_Response {
		$raw = $request->get_param( 'entry' );
		if ( null !== $raw && '' !== $raw ) {
			if ( ! is_scalar( $raw ) || 1 !== preg_match( '/^\d{1,12}$/', (string) $raw ) ) {
				return new WP_REST_Response( self::error( 'invalid_param', 'Mã mục không hợp lệ.', 'Mở lại bản đồ từ link trợ lý gửi.', 'brain_map' ), 400 );
			}
			$focus = (int) $raw;
		}
		$owner = (int) get_current_user_id();
		if ( $owner <= 0 || ! class_exists( 'BizCity_Journal_Database' ) || ! class_exists( 'BizCity_Journal_Index' ) ) {
			return new WP_REST_Response( self::error( 'journal_unavailable', 'Sổ tài liệu chưa sẵn sàng trên website này.', 'Thử lại sau ít phút hoặc báo kỹ thuật.', 'brain_map' ), 503 );
		}
		return new WP_REST_Response( self::for_owner( $owner, $focus ), 200 );
	}

	/** The map of one person (cached 60 s in the journal group, which every journal write flushes). */
	public static function for_owner( int $owner, int $focus = 0 ): array {
		$key = 'brain_map_' . ( function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 1 ) . '_' . $owner . '_' . max( 0, $focus );
		$hit = class_exists( 'BizCity_Cache' ) ? BizCity_Cache::get( BizCity_Journal_Database::CACHE_GROUP, $key ) : false;
		if ( is_array( $hit ) && isset( $hit['contract'] ) ) {
			return self::with_lookups( $hit, $owner ); // [2026-10-22 06:55 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.129 reflect #7
		}
		BizCity_Journal_Database::maybe_install();
		// [2026-10-22 06:55 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.129 reflect #2 — scan DOCUMENT / OUTPUT rows only (a busy Twin Sheet must not push the documents out of a
		// mixed 500-row window), in pages of the journal's own find(); + notebook rows (names) and job rows (titles). `docs_total` = the real count.
		$entries = array();
		$total   = 0;
		$kinds   = self::layer_kinds( array( 'document', 'output' ) );
		for ( $off = 0; $kinds && $off < self::SCAN; $off += 50 ) {
			$page  = BizCity_Journal_Index::find( array( 'owner_user_id' => $owner, 'kinds' => $kinds, 'limit' => 50, 'offset' => $off ) );
			$total = max( $total, (int) ( $page['total'] ?? 0 ) );
			$entries = array_merge( $entries, (array) ( $page['items'] ?? array() ) );
			if ( count( (array) ( $page['items'] ?? array() ) ) < 50 ) {
				break;
			}
		}
		foreach ( array( array( 'brain.notebook' ), array( 'job.run' ) ) as $hub_kinds ) { // hub labels: notebook names, JB- headers (not their evidence / decision rows)
			if ( $hub_kinds ) {
				$entries = array_merge( $entries, (array) ( BizCity_Journal_Index::find( array( 'owner_user_id' => $owner, 'kinds' => $hub_kinds, 'limit' => 50 ) )['items'] ?? array() ) );
			}
		}
		$out     = self::build( $entries, array(
			'pack_ids'      => self::pack_ids( $owner ),
			'contact_names' => self::contact_names( $entries, $owner ),
			'sheet_names'   => self::sheet_names(), // [2026-10-22 08:31 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.128 doc 68
			'skill_refs'    => self::skill_refs( $entries, $owner ),
			'focus'         => $focus,
			'now'           => gmdate( 'Y-m-d H:i:s' ),
			// [2026-10-22 08:00 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.129 reflect #8 — created_at is the DB's CURRENT_TIMESTAMP (its own time zone): compare with the DB's NOW()
			'db_now'        => self::db_now(),
		) );
		$out['stats']['docs_total'] = max( $total, (int) $out['stats']['docs'] );
		if ( $out['stats']['docs_total'] > $out['stats']['docs'] ) {
			$out['truncated'] = true; // older documents beyond the 500 scanned are counted in docs_total only
		}
		if ( class_exists( 'BizCity_Cache' ) ) {
			BizCity_Cache::set( BizCity_Journal_Database::CACHE_GROUP, $key, $out, self::CACHE_TTL );
		}
		return self::with_lookups( $out, $owner );
	}

	/**
	 * [2026-10-22 06:55 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.129 reflect #7 — lookups / gaps merged AFTER the cache (they change on every search; the
	 * map itself is cached 60 s), so the numbers never lag behind the map.
	 */
	private static function with_lookups( array $out, int $owner ): array {
		$state = function_exists( 'get_user_option' ) ? get_user_option( self::OPT_LOOKUPS, $owner ) : null;
		$out['stats'] = array_merge( (array) $out['stats'], self::lookup_stats( $state, time() ) );
		return $out;
	}

	/** PHASE-0.129 reflect #8 — the DB clock ('Y-m-d H:i:s', same zone as created_at); '' when unavailable (then UTC is used). */
	private static function db_now(): string {
		global $wpdb;
		$v = isset( $wpdb ) && is_object( $wpdb ) ? (string) $wpdb->get_var( 'SELECT NOW()' ) : '';
		return 1 === preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $v ) ? $v : '';
	}

	/** Declared kinds whose layer is one of `$layers` (journal-kinds@1). */
	private static function layer_kinds( array $layers ): array {
		if ( ! method_exists( 'BizCity_Journal_Kinds', 'all' ) || ! method_exists( 'BizCity_Journal_Kinds', 'layer_of' ) ) {
			return array();
		}
		$out = array();
		foreach ( array_keys( (array) ( BizCity_Journal_Kinds::all()['kinds'] ?? array() ) ) as $k ) {
			if ( in_array( BizCity_Journal_Kinds::layer_of( (string) $k ), $layers, true ) ) {
				$out[] = (string) $k;
			}
		}
		return $out;
	}

	/** entry ids the assistant sees instantly (doc_index@3 doc rows); null = the pack is unavailable (then the tier alone decides). */
	private static function pack_ids( int $owner ): ?array {
		if ( ! class_exists( 'BizCity_Journal_Doc_Index_Pack_Exporter' ) || ! BizCity_Journal_Doc_Index_Pack_Exporter::available() ) {
			return null;
		}
		$ids = array();
		foreach ( (array) ( BizCity_Journal_Doc_Index_Pack_Exporter::build( $owner, 'owner' )['items'] ?? array() ) as $it ) {
			if ( is_array( $it ) && 'doc' === (string) ( $it['row'] ?? '' ) && (int) ( $it['entry_id'] ?? 0 ) > 0 ) {
				$ids[] = (int) $it['entry_id'];
			}
		}
		return $ids;
	}

	/** CRM names of the contacts the person may already see (same rule as documents/overview); others stay a number. */
	private static function contact_names( array $entries, int $owner ): array {
		$names = array();
		if ( ! class_exists( 'BizCity_CRM_Repository' ) ) {
			return $names;
		}
		foreach ( $entries as $e ) {
			$cid = (int) ( $e['belongs']['contact_id'] ?? $e['contact_id'] ?? 0 );
			if ( $cid <= 0 || isset( $names[ $cid ] ) || count( $names ) >= self::MAX_HUBS ) {
				continue;
			}
			$row            = BizCity_Journal_Index::contact_visible( $cid, $owner ) ? BizCity_CRM_Repository::get_contact( $cid ) : null;
			$names[ $cid ] = is_array( $row ) ? mb_substr( (string) ( $row['name'] ?? '' ), 0, 60 ) : '';
		}
		return $names;
	}

	/**
	 * [2026-10-22 08:31 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.128 doc 68 — ref prefix ⇒ label of each built-in Twin Sheet whose rows carry a code
	 * (journal-kinds ref_codes × TWIN-SHEET-BUILTIN labels): TC Sổ thu chi · CN Sổ công nợ · KH · TD · VC.
	 */
	private static function sheet_names(): array {
		if ( ! method_exists( 'BizCity_Journal_Kinds', 'ref_prefixes' ) ) {
			return array();
		}
		$path    = dirname( __DIR__ ) . '/contracts/TWIN-SHEET-BUILTIN-v1.json';
		$doc     = is_readable( $path ) ? json_decode( (string) file_get_contents( $path ), true ) : null;
		$sheets  = is_array( $doc['sheets'] ?? null ) ? $doc['sheets'] : array();
		$out     = array();
		foreach ( BizCity_Journal_Kinds::ref_prefixes() as $kind => $px ) {
			if ( isset( $sheets[ $kind ] ) && 1 === preg_match( '/^[A-Z]{2}$/', (string) $px ) ) {
				$out[ (string) $px ] = mb_substr( (string) ( $sheets[ $kind ]['label'] ?? $kind ), 0, 60 );
			}
		}
		return $out;
	}

	/** [PHASE-0.128 doc 68] skill name ⇒ KN- code of its definition row, only when that row is the person's own (the map never shows another person's row); else ''. */
	private static function skill_refs( array $entries, int $owner ): array {
		$out = array();
		if ( ! class_exists( 'BizCity_Journal_Skill_Link' ) || ! method_exists( 'BizCity_Journal_Skill_Link', 'define_key' ) || ! function_exists( 'get_current_blog_id' ) ) {
			return $out;
		}
		foreach ( $entries as $e ) {
			$sk = 'job.run' === (string) ( $e['kind'] ?? '' ) ? (string) ( $e['card']['skill'] ?? '' ) : '';
			if ( 1 !== preg_match( self::SKILL_NAME_RE, $sk ) || isset( $out[ $sk ] ) || count( $out ) >= self::MAX_HUBS ) {
				continue;
			}
			$def        = BizCity_Journal_Database::instance()->get_by_idempotency_key( BizCity_Journal_Skill_Link::define_key( (int) get_current_blog_id(), $sk ) );
			$out[ $sk ] = is_array( $def ) && (int) ( $def['owner_user_id'] ?? 0 ) === $owner ? BizCity_Journal_Index::ref_code( (string) $def['kind'], (int) $def['id'] ) : '';
		}
		return $out;
	}

	// ── pure ─────────────────────────────────────────────────────────────

	/**
	 * brain-map@1 from journal-entry@1.2 rows (newest first). Pure.
	 *
	 * @param array $ctx {pack_ids: int[]|null, contact_names: array<int,string>, focus: int, now: 'Y-m-d H:i:s' UTC}
	 */
	public static function build( array $entries, array $ctx ): array {
		$pack     = isset( $ctx['pack_ids'] ) && is_array( $ctx['pack_ids'] ) ? array_flip( array_map( 'intval', $ctx['pack_ids'] ) ) : null;
		$names    = is_array( $ctx['contact_names'] ?? null ) ? $ctx['contact_names'] : array();
		$focus_id = max( 0, (int) ( $ctx['focus'] ?? 0 ) );
		$now      = (string) ( $ctx['now'] ?? gmdate( 'Y-m-d H:i:s' ) );
		$clock    = '' !== (string) ( $ctx['db_now'] ?? '' ) ? (string) $ctx['db_now'] : $now; // [2026-10-22 08:00 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.129 reflect #8
		$week_ago = gmdate( 'Y-m-d H:i:s', (int) strtotime( $clock . ' UTC -7 days' ) );

		$docs      = array();
		$notebooks = array();
		$jobs      = array();
		foreach ( $entries as $e ) {
			if ( ! is_array( $e ) || 'void' === (string) ( $e['state'] ?? '' ) ) {
				continue;
			}
			$layer = (string) ( $e['layer'] ?? 'document' );
			if ( 'brain.notebook' === (string) ( $e['kind'] ?? '' ) ) {
				$nb = (int) ( $e['belongs']['notebook_id'] ?? $e['card']['notebook_id'] ?? 0 );
				if ( $nb > 0 ) {
					$notebooks[ $nb ] = mb_substr( (string) ( $e['card']['name'] ?? $e['title'] ?? '' ), 0, 60 );
				}
				continue;
			}
			if ( 'job' === $layer ) {
				$ref = (string) ( $e['ref_code'] ?? '' );
				if ( 1 === preg_match( '/^JB-\d+$/', $ref ) ) {
					// [2026-10-22 08:31 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.128 doc 68 (owner 20:2x, hub-and-spoke) — 1.1.0: the skill the job followed + the sheets it wrote rows into
					$jobs[ $ref ] = array( 'title' => mb_substr( (string) ( $e['title'] ?? '' ), 0, 60 ), 'status' => (string) ( $e['card']['status'] ?? '' ), 'skill' => (string) ( $e['card']['skill'] ?? '' ), 'outs' => array_slice( array_map( 'strval', (array) ( $e['card']['output_refs'] ?? array() ) ), 0, 20 ) );
				}
				continue;
			}
			if ( 'document' === $layer || 'output' === $layer ) {
				$docs[] = $e;
			}
		}

		// stats over EVERY document (the cap below only limits what is drawn)
		$stats = array( 'docs' => 0, 'by_level' => array_fill_keys( self::LEVELS, 0 ), 'failed' => 0, 'outside_pack' => 0, 'unassigned' => 0, 'new_7d' => 0, 'measured' => false );
		$level = array();
		foreach ( $docs as $e ) {
			$id = (int) ( $e['entry_id'] ?? 0 );
			$lv = self::doc_level( $e, null === $pack ? null : isset( $pack[ $id ] ) );
			$level[ $id ] = $lv;
			$stats['docs']++;
			if ( null === $lv ) {
				$stats['failed']++;
			} else {
				$stats['by_level'][ $lv ]++;
				if ( null !== $pack && ! isset( $pack[ $id ] ) ) {
					$stats['outside_pack']++;
				}
			}
			if ( '' === (string) ( $e['belongs']['group']['gid'] ?? '' ) && (int) ( $e['belongs']['contact_id'] ?? 0 ) <= 0 ) {
				$stats['unassigned']++; // same rule as documents/overview: no Zalo group and no CRM contact
			}
			if ( (string) ( $e['created_at'] ?? '' ) >= $week_ago ) {
				$stats['new_7d']++;
			}
		}

		// [2026-10-22 04:30 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.129 W3 (B7) — lookups / gaps counted at the site's knowledge.search (absent ⇒ measured false, the screen says "chưa đo")
		$stats = array_merge( $stats, self::lookup_stats( $ctx['lookups'] ?? null, (int) strtotime( $now . ' UTC' ) ) );

		$kept = self::pick_docs( $docs, $focus_id );
		$nodes = array();
		$edges = array();
		$hubs  = array();
		$ids   = array();
		// [2026-10-22 08:31 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.128 doc 68 — 1.1.0 hubs: sheet (ref prefix ⇒ sheet label) · skill (name ⇒ KN- of the person's own row, else '')
		$sheet_names = is_array( $ctx['sheet_names'] ?? null ) ? $ctx['sheet_names'] : array();
		$skill_refs  = is_array( $ctx['skill_refs'] ?? null ) ? $ctx['skill_refs'] : array();
		$job_linked  = array();
		foreach ( $kept as $e ) {
			$ids[ (int) $e['entry_id'] ] = true;
		}
		$hub = static function ( string $nid, array $node ) use ( &$hubs, &$nodes ): bool {
			if ( isset( $hubs[ $nid ] ) ) {
				return true;
			}
			if ( count( $hubs ) >= self::MAX_HUBS ) {
				return false;
			}
			$hubs[ $nid ] = true;
			$nodes[]      = array( 'id' => $nid ) + $node;
			return true;
		};
		foreach ( $kept as $e ) {
			$id    = (int) $e['entry_id'];
			$nid   = 'd' . $id;
			$nodes[] = self::doc_node( $e, $level[ $id ] ?? null, $id === $focus_id );
			$nb = (int) ( $e['belongs']['notebook_id'] ?? 0 );
			if ( $nb > 0 && $hub( 'n' . $nb, array( 'kind' => 'notebook', 'label' => '' !== (string) ( $notebooks[ $nb ] ?? '' ) ? $notebooks[ $nb ] : 'Notebook ' . $nb, 'ref_code' => '' ) ) ) {
				$edges[] = array( 'from' => $nid, 'to' => 'n' . $nb, 'type' => 'in_notebook' );
			}
			$gid = (string) ( $e['belongs']['group']['gid'] ?? '' );
			if ( 1 === preg_match( '/^[0-9A-Za-z_.:-]{1,64}$/', $gid ) && $hub( 'g' . $gid, array( 'kind' => 'group', 'label' => mb_substr( '' !== (string) ( $e['belongs']['group']['name'] ?? '' ) ? (string) $e['belongs']['group']['name'] : 'Nhóm Zalo', 0, 60 ), 'ref_code' => '' ) ) ) {
				$edges[] = array( 'from' => $nid, 'to' => 'g' . $gid, 'type' => 'in_group' );
			}
			$cid = (int) ( $e['belongs']['contact_id'] ?? 0 );
			if ( $cid > 0 && $hub( 'c' . $cid, array( 'kind' => 'contact', 'label' => '' !== (string) ( $names[ $cid ] ?? '' ) ? (string) $names[ $cid ] : 'Liên hệ #' . $cid, 'ref_code' => '' ) ) ) {
				$edges[] = array( 'from' => $nid, 'to' => 'c' . $cid, 'type' => 'about_contact' );
			}
			$jr = (string) ( $e['job']['job_ref'] ?? '' );
			if ( 1 === preg_match( '/^JB-\d+$/', $jr ) && $hub( 'j' . substr( $jr, 3 ), array( 'kind' => 'job', 'label' => '' !== (string) ( $jobs[ $jr ]['title'] ?? '' ) ? $jr . ' ' . $jobs[ $jr ]['title'] : $jr, 'ref_code' => $jr, 'status' => (string) ( $jobs[ $jr ]['status'] ?? '' ) ) ) ) {
				$edges[] = array( 'from' => $nid, 'to' => 'j' . substr( $jr, 3 ), 'type' => 'input' === (string) ( $e['job']['role'] ?? '' ) ? 'input_of_job' : 'output_of_job' );
				// [2026-10-22 08:31 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.128 doc 68 — once per drawn job: job → skill (`uses_skill`) and job → each sheet it wrote rows into (`writes_sheet`)
				if ( ! isset( $job_linked[ $jr ] ) ) {
					$job_linked[ $jr ] = true;
					$jid = 'j' . substr( $jr, 3 );
					$sk  = (string) ( $jobs[ $jr ]['skill'] ?? '' );
					if ( 1 === preg_match( self::SKILL_NAME_RE, $sk ) && $hub( 'k' . $sk, array( 'kind' => 'skill', 'label' => $sk, 'ref_code' => (string) ( $skill_refs[ $sk ] ?? '' ) ) ) ) {
						$edges[] = array( 'from' => $jid, 'to' => 'k' . $sk, 'type' => 'uses_skill' );
					}
					$seen_sheet = array();
					foreach ( (array) ( $jobs[ $jr ]['outs'] ?? array() ) as $out_ref ) {
						$px = 1 === preg_match( '/^([A-Z]{2})-\d{1,12}$/', (string) $out_ref, $m ) ? $m[1] : '';
						if ( '' === $px || ! isset( $sheet_names[ $px ] ) || isset( $seen_sheet[ $px ] ) ) {
							continue;
						}
						$seen_sheet[ $px ] = true;
						if ( $hub( 's' . $px, array( 'kind' => 'sheet', 'label' => $sheet_names[ $px ], 'ref_code' => '' ) ) ) {
							$edges[] = array( 'from' => $jid, 'to' => 's' . $px, 'type' => 'writes_sheet' );
						}
					}
				}
			}
			$prev = (int) ( $e['version']['prev_entry_id'] ?? 0 );
			if ( $prev > 0 && isset( $ids[ $prev ] ) ) {
				$edges[] = array( 'from' => 'd' . $prev, 'to' => $nid, 'type' => 'version_of' );
			}
			foreach ( array_slice( (array) ( $e['derived_from'] ?? array() ), 0, 5 ) as $src ) {
				$src = (int) $src;
				if ( $src > 0 && $src !== $prev && isset( $ids[ $src ] ) ) {
					$edges[] = array( 'from' => 'd' . $src, 'to' => $nid, 'type' => 'derived_from' );
				}
			}
			// PHASE-0.133 S133-Mc — the people a row names and its topics (entry-meta@1), one hub per folded name; a sensitive row never draws them
			if ( '' === (string) ( $e['flags']['sensitive'] ?? '' ) ) {
				foreach ( self::meta_hubs( $e ) as $h ) {
					if ( $hub( $h['id'], array( 'kind' => $h['kind'], 'label' => $h['label'], 'ref_code' => '' ) ) ) {
						$edges[] = array( 'from' => $nid, 'to' => $h['id'], 'type' => 'person' === $h['kind'] ? 'mentions_person' : 'about_topic' );
					}
				}
			}
		}

		$focus = null;
		if ( $focus_id > 0 ) {
			foreach ( $docs as $e ) {
				if ( (int) $e['entry_id'] === $focus_id ) {
					$focus = array( 'entry_id' => $focus_id, 'ref_code' => (string) ( $e['ref_code'] ?? '' ), 'level' => $level[ $focus_id ] ?? null, 'state' => self::node_state( $e ) );
					break;
				}
			}
			if ( null === $focus ) {
				$focus = array( 'entry_id' => $focus_id, 'ref_code' => '', 'level' => null, 'state' => 'missing' ); // not (yet) one of this person's documents
			}
		}

		$v11 = false;
		$v12 = false;
		foreach ( $nodes as $n ) {
			if ( in_array( (string) ( $n['kind'] ?? '' ), array( 'sheet', 'skill' ), true ) ) {
				$v11 = true;
			}
			if ( in_array( (string) ( $n['kind'] ?? '' ), array( 'person', 'topic' ), true ) ) {
				$v12 = true;
			}
		}
		return array(
			'contract'  => $v12 ? self::CONTRACT_V12 : ( $v11 ? self::CONTRACT_V11 : self::CONTRACT ), // 1.1.0 / 1.2.0 only when such a hub is drawn; else the 1.0.0 bytes
			'ladder'    => self::LADDER,
			'as_of'     => gmdate( 'Y-m-d\TH:i:s\Z', (int) strtotime( $now . ' UTC' ) ),
			'focus'     => $focus,
			'stats'     => $stats,
			'nodes'     => $nodes,
			'edges'     => $edges,
			'truncated' => count( $kept ) < count( $docs ),
		);
	}

	/**
	 * [2026-10-24 05:55 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.133 S133-Mc — person / topic hubs of one row from its entry-meta@1: ≤ 3 people (by folded name, honorifics
	 * dropped), ≤ 2 topics; ids are short hashes of the folded key so the same name on two rows meets at one hub. Pure.
	 *
	 * @return array<int,array{id:string,kind:string,label:string}>
	 */
	public static function meta_hubs( array $e ): array {
		$meta = is_array( $e['meta'] ?? null ) ? $e['meta'] : array();
		$out  = array();
		$fold = class_exists( 'BizCity_Journal_Entry_Meta' );
		foreach ( array_slice( (array) ( $meta['people'] ?? array() ), 0, self::META_PEOPLE_PER_DOC ) as $p ) {
			$name = is_array( $p ) ? trim( (string) ( $p['name'] ?? '' ) ) : '';
			$key  = is_array( $p ) && '' !== (string) ( $p['norm'] ?? '' ) ? (string) $p['norm'] : ( $fold ? BizCity_Journal_Entry_Meta::person_norm( $name ) : mb_strtolower( $name ) );
			if ( '' !== $key && '' !== $name ) {
				$out[] = array( 'id' => 'p' . substr( md5( $key ), 0, 10 ), 'kind' => 'person', 'label' => mb_substr( $name, 0, 60 ) );
			}
		}
		foreach ( array_slice( (array) ( $meta['topics'] ?? array() ), 0, self::META_TOPICS_PER_DOC ) as $t ) {
			$t   = trim( (string) $t );
			$key = $fold ? BizCity_Journal_Entry_Meta::fold( $t ) : mb_strtolower( $t );
			if ( '' !== $key ) {
				$out[] = array( 'id' => 't' . substr( md5( $key ), 0, 10 ), 'kind' => 'topic', 'label' => mb_substr( $t, 0, 60 ) );
			}
		}
		return $out;
	}

	/**
	 * knowledge-ladder@1 level of one document row; null = not saved (state failed). Pure.
	 *
	 * @param bool|null $in_pack the row is in the person's doc_index@3 pack; null = unknown (the pack is unavailable ⇒ the tier decides)
	 */
	public static function doc_level( array $e, ?bool $in_pack ): ?string {
		if ( 'failed' === (string) ( $e['state'] ?? '' ) ) {
			return null;
		}
		if ( false === $in_pack ) {
			return 'L0';
		}
		$tier = (string) ( $e['tier'] ?? '' );
		if ( 'L3' === $tier ) {
			$used = 'off' !== (string) ( $e['access']['customer'] ?? 'off' ) || 'L3' === (string) ( $e['learning']['level'] ?? '' );
			return $used ? 'L4' : 'L3';
		}
		if ( 'L2' === $tier ) {
			return 'L2';
		}
		return 'L1';
	}

	/** Pure: saved · review · failed. */
	public static function node_state( array $e ): string {
		$s = (string) ( $e['state'] ?? 'captured' );
		if ( 'failed' === $s ) {
			return 'failed';
		}
		return 'review' === $s || ! empty( $e['flags']['review'] ) ? 'review' : 'saved';
	}

	/** Pure: "PDF" · "Word" · "Excel" · "PowerPoint" · "Ảnh" · "Ghi âm" · "Video" · "Tệp" · "Ghi chú" (no original). */
	public static function type_label( string $mime, bool $has_file ): string {
		$m = strtolower( $mime );
		if ( ! $has_file && '' === $m ) {
			return 'Ghi chú';
		}
		$map = array( 'pdf' => 'PDF', 'word' => 'Word', 'msword' => 'Word', 'officedocument.wordprocessing' => 'Word', 'sheet' => 'Excel', 'excel' => 'Excel', 'csv' => 'Excel', 'presentation' => 'PowerPoint', 'powerpoint' => 'PowerPoint', 'image' => 'Ảnh', 'audio' => 'Ghi âm', 'video' => 'Video' );
		foreach ( $map as $needle => $label ) {
			if ( false !== strpos( $m, $needle ) ) {
				return $label;
			}
		}
		return 'Tệp';
	}

	/** Pure: one document node (no URL, no path, no hash). */
	public static function doc_node( array $e, ?string $level, bool $focus ): array {
		$orig = is_array( $e['original'] ?? null ) ? $e['original'] : array();
		$ref  = (string) ( $e['ref_code'] ?? '' );
		$sens = '' !== (string) ( $e['flags']['sensitive'] ?? '' );
		$node = array(
			'id'         => 'd' . (int) $e['entry_id'],
			'kind'       => 'doc',
			'jkind'      => (string) ( $e['kind'] ?? '' ), // [2026-10-24 09:04 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.134 — Twin Vault types the node (document · image · note …)
			'entry_id'   => (int) $e['entry_id'],
			'label'      => mb_substr( '' !== (string) ( $e['title'] ?? '' ) ? (string) $e['title'] : ( '' !== $ref ? $ref : 'JE-' . (int) $e['entry_id'] ), 0, 80 ),
			'ref_code'   => $ref,
			'level'      => $level,
			'state'      => self::node_state( $e ),
			'type'       => self::type_label( (string) ( $orig['mime'] ?? '' ), (int) ( $orig['attachment_id'] ?? 0 ) > 0 ),
			'file_name'  => mb_substr( (string) ( $orig['file_name'] ?? '' ), 0, 120 ),
			'size_bytes' => max( 0, (int) ( $orig['size'] ?? 0 ) ),
			'has_file'   => (int) ( $orig['attachment_id'] ?? 0 ) > 0,
			'version'    => array( 'n' => max( 1, (int) ( $e['version']['n'] ?? 1 ) ), 'root_ref' => (string) ( $e['version']['root_ref'] ?? '' ) ),
			'doc_date'   => is_string( $e['doc_date'] ?? null ) ? substr( (string) $e['doc_date'], 0, 10 ) : '',
			'created_at' => (string) ( $e['created_at'] ?? '' ),
			'kind_label' => class_exists( 'BizCity_Journal_Kinds' ) ? BizCity_Journal_Kinds::label( (string) ( $e['kind'] ?? '' ) ) : (string) ( $e['kind'] ?? '' ),
			'customer'   => $sens ? 'locked' : (string) ( $e['access']['customer'] ?? 'off' ),
			'unassigned' => '' === (string) ( $e['belongs']['group']['gid'] ?? '' ) && (int) ( $e['belongs']['contact_id'] ?? 0 ) <= 0,
			'focus'      => $focus,
		);
		if ( null !== ( $e['error']['code'] ?? null ) && 'failed' === $node['state'] ) {
			$node['error_code'] = substr( (string) preg_replace( '/[^a-z0-9_]/i', '', (string) $e['error']['code'] ), 0, 40 );
		}
		// [2026-10-25 12:30 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.137 S137-B2 (D137-2) — the owner panel's focus file shows Ghi sổ ⇒ Đọc ⇒ Vào kho ⇒ Học sâu, not only "Đã lưu · L1"
		if ( class_exists( 'BizCity_Journal_Pipeline' ) ) {
			$node['pipeline'] = BizCity_Journal_Pipeline::of( $e, $level );
		}
		return $node;
	}

	/**
	 * Which documents are drawn (≤ MAX_DOCS): the focus first, then its family (same notebook / group / contact / job / version
	 * root), then the newest. Order of `$docs` = newest first. Pure.
	 */
	public static function pick_docs( array $docs, int $focus_id ): array {
		$focus = null;
		foreach ( $docs as $e ) {
			if ( (int) ( $e['entry_id'] ?? 0 ) === $focus_id ) {
				$focus = $e;
				break;
			}
		}
		$keys = static function ( array $e ): array {
			$k = array();
			if ( (int) ( $e['belongs']['notebook_id'] ?? 0 ) > 0 ) {
				$k[] = 'n' . (int) $e['belongs']['notebook_id'];
			}
			if ( '' !== (string) ( $e['belongs']['group']['gid'] ?? '' ) ) {
				$k[] = 'g' . (string) $e['belongs']['group']['gid'];
			}
			if ( (int) ( $e['belongs']['contact_id'] ?? 0 ) > 0 ) {
				$k[] = 'c' . (int) $e['belongs']['contact_id'];
			}
			if ( '' !== (string) ( $e['job']['job_ref'] ?? '' ) ) {
				$k[] = 'j' . (string) $e['job']['job_ref'];
			}
			if ( '' !== (string) ( $e['version']['root_ref'] ?? '' ) ) {
				$k[] = 'v' . (string) $e['version']['root_ref'];
			}
			return $k;
		};
		$first = array();
		$rest  = array();
		$fam   = null === $focus ? array() : array_flip( $keys( $focus ) );
		foreach ( $docs as $e ) {
			if ( null !== $focus && (int) $e['entry_id'] === $focus_id ) {
				continue;
			}
			$hit = false;
			foreach ( $keys( $e ) as $k ) {
				if ( isset( $fam[ $k ] ) ) {
					$hit = true;
					break;
				}
			}
			if ( $hit ) {
				$first[] = $e;
			} else {
				$rest[] = $e;
			}
		}
		return array_slice( array_merge( null === $focus ? array() : array( $focus ), $first, $rest ), 0, self::MAX_DOCS );
	}

	// ── lookups / gaps (PHASE-0.129 W3, B7) ──────────────────────────────

	/**
	 * [2026-10-22 04:30 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.129 W3 — after one knowledge.search: count it for the owner / staff who asked (a customer's
	 * search is never counted). `$result` = the handler's answer; a WP_Error (refused / broken) is not a lookup. Never throws.
	 *
	 * @param mixed $result
	 */
	public static function note_search( array $args, array $ctx, $result ): void {
		$uid  = (int) ( $ctx['user_id'] ?? 0 );
		$role = (string) ( $ctx['role'] ?? '' );
		if ( $uid <= 0 || ! in_array( $role, array( 'owner', 'staff' ), true ) || ! is_array( $result ) || ! function_exists( 'get_user_option' ) ) {
			return;
		}
		try {
			$state = get_user_option( self::OPT_LOOKUPS, $uid );
			$next  = self::add_search( is_array( $state ) ? $state : array(), count( (array) ( $result['passages'] ?? array() ) ), (string) ( $args['query'] ?? '' ), time() );
			update_user_option( $uid, self::OPT_LOOKUPS, $next );
		} catch ( \Throwable $e ) {
			unset( $e ); // a counter must never break the search
		}
	}

	/** Pure: the rolling state after one search with `$passages` results (0 = a gap). */
	public static function add_search( array $state, int $passages, string $query, int $now ): array {
		$day  = gmdate( 'Ymd', $now );
		$days = is_array( $state['days'] ?? null ) ? $state['days'] : array();
		$cur  = is_array( $days[ $day ] ?? null ) ? $days[ $day ] : array( 0, 0 );
		$days[ $day ] = array( (int) $cur[0] + 1, (int) $cur[1] + ( $passages <= 0 ? 1 : 0 ) );
		$floor = gmdate( 'Ymd', $now - ( self::LOOKUP_DAYS - 1 ) * 86400 );
		foreach ( array_keys( $days ) as $d ) {
			if ( (string) $d < $floor ) {
				unset( $days[ $d ] );
			}
		}
		$recent = array_values( array_filter( is_array( $state['recent_gaps'] ?? null ) ? $state['recent_gaps'] : array(), 'is_array' ) );
		if ( $passages <= 0 ) {
			$q = trim( (string) preg_replace( '/\s+/u', ' ', strip_tags( $query ) ) );
			if ( '' !== $q ) {
				array_unshift( $recent, array( 'at' => $now, 'q' => mb_substr( $q, 0, 60 ) ) );
			}
		}
		return array(
			'since'       => (int) ( $state['since'] ?? 0 ) > 0 ? (int) $state['since'] : $now,
			'days'        => $days,
			'recent_gaps' => array_slice( $recent, 0, self::RECENT_GAPS ),
		);
	}

	/** Pure: {measured, lookups_30d, gaps_30d, gaps_recent[{q, at}], since} — `measured` false until the first counted search. */
	public static function lookup_stats( $state, int $now ): array {
		if ( ! is_array( $state ) || ! is_array( $state['days'] ?? null ) ) {
			return array( 'measured' => false );
		}
		$floor = gmdate( 'Ymd', $now - ( self::LOOKUP_DAYS - 1 ) * 86400 );
		$l     = 0;
		$g     = 0;
		foreach ( $state['days'] as $d => $c ) {
			if ( (string) $d >= $floor && is_array( $c ) ) {
				$l += (int) ( $c[0] ?? 0 );
				$g += (int) ( $c[1] ?? 0 );
			}
		}
		$recent = array();
		foreach ( (array) ( $state['recent_gaps'] ?? array() ) as $r ) {
			if ( is_array( $r ) && (int) ( $r['at'] ?? 0 ) >= $now - self::LOOKUP_DAYS * 86400 ) {
				$recent[] = array( 'q' => mb_substr( (string) ( $r['q'] ?? '' ), 0, 60 ), 'at' => gmdate( 'Y-m-d\TH:i:s\Z', (int) $r['at'] ) );
			}
		}
		return array( 'measured' => true, 'lookups_30d' => $l, 'gaps_30d' => $g, 'gaps_recent' => $recent, 'since' => gmdate( 'Y-m-d', (int) ( $state['since'] ?? $now ) ) );
	}

	// ── receipt link (PHASE-0.129 §3) ────────────────────────────────────

	/** Pure: may this receipt carry a map link? Off by the person, or one was sent in the last 10 minutes ⇒ no. */
	public static function should_link( string $pref, int $last_at, int $now ): bool {
		return 'off' !== $pref && ( $last_at <= 0 || $now - $last_at >= self::RECEIPT_EVERY );
	}

	/**
	 * The `job.panel_link` of a second-brain receipt: a one-time `brain.map` link opening on `$entry_id`, or null (switched off,
	 * too soon, no https, panel code absent, issue refused). The URL leaves only in this array, for the cell's fixed sentence.
	 *
	 * @return array{panel:string,label:string,url:string,expires_at:string}|null
	 */
	public static function receipt_panel_link( int $owner, int $entry_id ): ?array {
		if ( $owner <= 0 || $entry_id <= 0 || ! class_exists( 'BizCity_Panel_Link' ) || ! class_exists( 'BizCity_Panel_Gate' ) || ! function_exists( 'get_user_option' ) ) {
			return null;
		}
		$pref = (string) get_user_option( self::OPT_RECEIPT, $owner );
		if ( ! self::should_link( $pref, (int) get_user_option( self::OPT_RECEIPT_AT, $owner ), time() ) ) {
			return null;
		}
		if ( 0 !== strpos( home_url( '/' ), 'https://' ) && ! (bool) apply_filters( 'bizcity_panel_allow_http', false ) ) {
			return null;
		}
		// [2026-10-22 07:15 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.129 reflect #4 — never kill a link the owner asked for, keep headroom in the rate limit, no key rotation;
		// the 10-minute clock starts only when the cell says the letter was delivered (mark_receipt_sent)
		if ( method_exists( 'BizCity_Panel_Link', 'can_auto_issue' ) && ! BizCity_Panel_Link::can_auto_issue( $owner, 'brain.map' ) ) {
			return null;
		}
		$r = BizCity_Panel_Link::issue( $owner, 'brain.map', '', 'entry:' . $entry_id, true );
		if ( empty( $r['ok'] ) ) {
			return null;
		}
		return array(
			'panel'      => 'brain.map',
			'label'      => (string) ( $r['panel']['label'] ?? 'Bản đồ não' ),
			'url'        => BizCity_Panel_Gate::ticket_url( (string) $r['token'] ),
			'expires_at' => gmdate( 'c', (int) $r['expires_at'] ),
		);
	}

	/** [2026-10-22 07:15 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.129 reflect #4 — the receipt that carried a map link reached the owner: start the 10-minute pause. */
	public static function mark_receipt_sent( int $owner ): void {
		if ( $owner > 0 && function_exists( 'update_user_option' ) ) {
			update_user_option( $owner, self::OPT_RECEIPT_AT, time() );
		}
	}

	/** Turn the receipt link on / off for the person (the panel's "Tắt gửi bản đồ"). */
	public static function set_receipt( int $owner, bool $on ): bool {
		return $owner > 0 && function_exists( 'update_user_option' ) && false !== update_user_option( $owner, self::OPT_RECEIPT, $on ? 'on' : 'off' );
	}

	public static function receipt_on( int $owner ): bool {
		return 'off' !== ( function_exists( 'get_user_option' ) ? (string) get_user_option( self::OPT_RECEIPT, $owner ) : '' );
	}

	private static function error( string $code, string $message, string $hint, string $help ): array {
		return array( 'ok' => false, 'code' => $code, 'message' => $message, 'hint' => $hint, 'help_code' => $help );
	}
}
