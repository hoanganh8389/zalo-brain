<?php
/**
 * BizCity_Scope_Meter — PHASE-0.136 doc 50 SW-A…D (Matrix "Phạm vi tri thức", D136-21…23, Q136-13…15 = 1).
 *
 * The SITE measures every knowledge read a cell turn makes (journal.find, vault.*, knowledge.search, journal.contact.profile, twinsheet.rows /
 * aggregate): who asked (owner / staff / customer), which Guru, role, folders, source, rows returned, coverage, and whether an empty narrowed
 * read had to be widened. No content, no customer question (Q136-15: the owner's / staff's own question is kept for "Câu hỏi chưa có kiến
 * thức"; a customer only by the topic words the cell sends, else just counted). No new table: one option per day (30 days), a ring of the last
 * 300 reads, a ring of the last 200 gaps — all written ONCE at the end of the request.
 *
 * REST (bizcity-vault/v1, edit_posts): GET /scope-matrix · /scope-matrix/turns · /scope-matrix/gaps. Changing who reads what stays where it is
 * (folder gurus[] — PATCH /folder/{nn}); this class never stores a second setting.
 *
 * [2026-10-25 Johnny Chu - Chu Hoàng Anh] PHASE-0.136 S136-M2…M5 — new file (owner: "ok rồi, bóc tách mockup, build checklist và code nhé").
 *
 * @package BizCity_KG_Hub
 */

defined( 'ABSPATH' ) || exit;

class BizCity_Scope_Meter {

	const CONTRACT   = 'scope-matrix@1.0.0';
	const DAY_PREFIX = 'bizcity_scope_meter_d_';
	const RECENT     = 'bizcity_scope_meter_recent';
	const GAPS       = 'bizcity_scope_meter_gaps';
	const KEEP_DAYS  = 30;
	const RECENT_MAX = 300;
	const GAPS_MAX   = 200;
	const MAX_KEYS   = 600; // cells per day (guru × column) — a cap, never an error
	/** tool ⇒ source */
	const TOOLS = array(
		'journal.find'            => 'journal',
		'journal.contact.profile' => 'journal',
		'vault.search'            => 'vault',
		'vault.list'              => 'vault',
		'vault.get'               => 'vault',
		'knowledge.search'        => 'notebook',
		'twinsheet.rows'          => 'sheet',
		'twinsheet.aggregate'     => 'sheet',
	);

	/** @var array<string,callable> test seams: get_option, update_option, delete_option, transient_get, transient_set, transient_delete, now, owner_guru */
	public static $readers = array();
	private static $pending = array();
	private static $hooked  = false;

	public static function init(): void {
		if ( function_exists( 'add_action' ) ) {
			add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		}
	}

	/* ───────────────────────── SW-A: measure (pure line + side-effect queue) ───────────────────────── */

	/** Called by BizCity_MCP_Tool_Registry::finish_call for every tool; ignores everything but the knowledge reads. Never throws. */
	public static function observe( string $name, array $args, array $ctx, array $envelope ): void {
		if ( ! isset( self::TOOLS[ $name ] ) ) {
			return;
		}
		try {
			$line = self::line( $name, $args, $ctx, $envelope, self::owner_guru() );
			$line = self::with_widened( $line );
			self::$pending[] = $line;
			if ( ! self::$hooked && ! isset( self::$readers['get_option'] ) && function_exists( 'add_action' ) ) {
				self::$hooked = true;
				add_action( 'shutdown', array( __CLASS__, 'flush' ) );
			}
		} catch ( \Throwable $e ) {
			unset( $e ); // measuring never breaks a tool call
		}
	}

	/**
	 * Pure: one measured read. `who` owner|staff|customer · `row` g<guru id>|public · `cols` folder numbers or journal|vault|notebook|sheet ·
	 * `cov` full (≥ 3 rows) | partial (1–2) | none | error · `narrowed` = a folder / role narrowed the read.
	 */
	public static function line( string $name, array $args, array $ctx, array $envelope, int $owner_guru = 0 ): array {
		$cs       = isset( $args['context_scope'] ) && is_array( $args['context_scope'] ) ? $args['context_scope'] : ( isset( $ctx['context_scope'] ) && is_array( $ctx['context_scope'] ) ? $ctx['context_scope'] : array() );
		$customer = 'guru_public' === (string) ( $ctx['principal_kind'] ?? '' ) || in_array( (string) ( $ctx['role'] ?? '' ), array( 'customer', 'contact', 'group_member' ), true );
		// S136-M5b — every role reads the Guru notebooks through guru:public; the cell says an owner / staff asked (labels the line only — the site already filtered by principal)
		$asker = (string) ( $cs['asker'] ?? '' );
		// (the guru:public principal always carries role = customer, so only that principal may be relabelled)
		$public_principal = 'guru_public' === (string) ( $ctx['principal_kind'] ?? '' );
		if ( $public_principal && in_array( $asker, array( 'owner', 'staff' ), true ) ) {
			$customer = false;
		}
		$who      = $customer ? 'customer' : ( $public_principal ? $asker : ( 'staff' === (string) ( $ctx['role'] ?? '' ) ? 'staff' : 'owner' ) );
		$guru     = (int) ( $ctx['guru_character_id'] ?? 0 );
		if ( $guru <= 0 && ! $customer ) {
			$guru = $owner_guru; // PHASE-0.136 D136-26 — owner / staff 1-1 turns answer with the Quản gia
		}
		$folders = array();
		foreach ( array_merge( array( $args['folder'] ?? '', $cs['folder'] ?? '' ), (array) ( $cs['folders'] ?? array() ) ) as $f ) {
			$n = 1 === preg_match( '/^([0-9]{2})(?:\/[a-z0-9-]{1,40})?$/', trim( (string) $f ), $m ) ? $m[1] : '';
			if ( '' !== $n && ! in_array( $n, $folders, true ) ) {
				$folders[] = $n;
			}
		}
		$folders = array_slice( $folders, 0, 4 );
		$source  = self::TOOLS[ $name ] ?? 'journal';
		$data    = isset( $envelope['data'] ) && is_array( $envelope['data'] ) ? $envelope['data'] : array();
		$vault_p = isset( $data['vault_passages'] ) && is_array( $data['vault_passages'] ) ? count( $data['vault_passages'] ) : 0;
		$rows    = self::rows_of( $data ) + $vault_p;
		if ( 'knowledge.search' === $name && $customer && $vault_p > 0 ) {
			$source = 'public';
		}
		if ( 'vault' === $source || ( 'journal.find' === $name && $folders ) ) {
			$cols = $folders ? $folders : array( 'vault' === $source ? 'vault' : 'journal' );
		} else {
			$cols = array( $source );
		}
		$ok  = ! empty( $envelope['success'] );
		$cov = ! $ok ? 'error' : ( $rows <= 0 ? 'none' : ( $rows < 3 ? 'partial' : 'full' ) );
		$q   = '';
		if ( 'none' === $cov ) {
			// Q136-15 = 1: the owner's / staff's own words (≤ 120 chars); a customer only by the topic words the cell extracted (≤ 5 words), else nothing
			$q = $customer ? self::topic( (string) ( $cs['topic'] ?? '' ) ) : self::clip( (string) ( $args['query'] ?? $args['q'] ?? '' ), 120 );
		}
		return array(
			't'        => (int) self::now(),
			'th'       => '' !== (string) ( $ctx['turn_id'] ?? '' ) ? substr( hash( 'sha256', (string) $ctx['turn_id'] ), 0, 12 ) : '',
			'tool'     => $name,
			'who'      => $who,
			'row'      => $customer ? 'public' : 'g' . max( 0, $guru ),
			'guru'     => max( 0, $guru ),
			'role'     => preg_replace( '/[^a-z_]/', '', (string) ( $cs['role'] ?? '' ) ),
			'view'     => 'as_customer' === (string) ( $cs['view'] ?? '' ) ? 'as_customer' : '',
			'cols'     => $cols,
			'src'      => $source,
			'rows'     => $rows,
			'cov'      => $cov,
			'narrowed' => (bool) $folders || '' !== (string) ( $cs['role'] ?? '' ),
			'widened'  => false,
			'q'        => $q,
		);
	}

	/** Pure: how many things a read returned (first list found; journal.contact.profile reports an int). */
	public static function rows_of( array $data ): int {
		foreach ( array( 'items', 'rows', 'passages', 'cards', 'results', 'notes', 'hits', 'entries' ) as $k ) {
			if ( isset( $data[ $k ] ) && is_array( $data[ $k ] ) ) {
				return count( $data[ $k ] );
			}
			if ( isset( $data[ $k ] ) && is_int( $data[ $k ] ) ) {
				return max( 0, $data[ $k ] );
			}
		}
		if ( isset( $data['value'] ) && isset( $data['count'] ) ) { // twinsheet.aggregate
			return (int) $data['count'];
		}
		return isset( $data['ref'] ) || isset( $data['md'] ) ? 1 : 0; // a single get
	}

	/** An empty NARROWED read leaves a 10-minute mark for its turn; the next un-narrowed read of the same tool in that turn is "widened". */
	private static function with_widened( array $line ): array {
		if ( '' === $line['th'] ) {
			return $line;
		}
		$k = 'bizcity_sm_w_' . $line['th'] . '_' . md5( $line['tool'] );
		if ( $line['narrowed'] && 'none' === $line['cov'] ) {
			self::t_set( $k, 1, 600 );
		} elseif ( ! $line['narrowed'] && self::t_get( $k ) ) {
			$line['widened'] = true;
			self::t_del( $k );
		}
		return $line;
	}

	/** One write per option at the end of the request (also callable by tests). */
	public static function flush(): void {
		if ( ! self::$pending ) {
			return;
		}
		$lines         = self::$pending;
		self::$pending = array();
		$day_key = self::DAY_PREFIX . gmdate( 'Ymd', (int) self::now() );
		$day     = (array) self::opt( $day_key, array() );
		foreach ( $lines as $l ) {
			$day = self::count_into( $day, $l );
		}
		self::opt_set( $day_key, $day );
		$recent = array_merge( (array) self::opt( self::RECENT, array() ), array_map( static function ( $l ) { unset( $l['q'] ); return $l; }, $lines ) );
		self::opt_set( self::RECENT, array_slice( $recent, -self::RECENT_MAX ) );
		$gaps = array();
		foreach ( $lines as $l ) {
			if ( 'none' === $l['cov'] ) {
				$gaps[] = array( 't' => $l['t'], 'who' => $l['who'], 'row' => $l['row'], 'role' => $l['role'], 'cols' => $l['cols'], 'q' => $l['q'] );
			}
		}
		if ( $gaps ) {
			self::opt_set( self::GAPS, array_slice( array_merge( (array) self::opt( self::GAPS, array() ), $gaps ), -self::GAPS_MAX ) );
		}
		$old = self::DAY_PREFIX . gmdate( 'Ymd', (int) self::now() - ( self::KEEP_DAYS + 1 ) * DAY_IN_SECONDS );
		self::opt_del( $old );
	}

	/** Pure: add one line to a day's counters — `<row>|<col>` and `_all` ⇒ [reads, hit, empty, widened]. */
	public static function count_into( array $day, array $l ): array {
		$hit = in_array( $l['cov'], array( 'full', 'partial' ), true ) ? 1 : 0;
		$add = static function ( array $day, string $k ) use ( $l, $hit ): array {
			if ( ! isset( $day[ $k ] ) && count( $day ) >= self::MAX_KEYS ) {
				return $day;
			}
			$c       = isset( $day[ $k ] ) ? $day[ $k ] : array( 0, 0, 0, 0 );
			$day[ $k ] = array( $c[0] + 1, $c[1] + $hit, $c[2] + ( $hit ? 0 : 1 ), $c[3] + ( $l['widened'] ? 1 : 0 ) );
			return $day;
		};
		$day = $add( $day, '_all' );
		foreach ( (array) $l['cols'] as $col ) {
			$day = $add( $day, $l['row'] . '|' . $col );
		}
		return $day;
	}

	/* ───────────────────────── SW-B / C / D: read back ───────────────────────── */

	/** Pure: sum day counters. */
	public static function sum_days( array $days ): array {
		$out = array();
		foreach ( $days as $day ) {
			foreach ( (array) $day as $k => $c ) {
				$o         = $out[ $k ] ?? array( 0, 0, 0, 0 );
				$out[ $k ] = array( $o[0] + (int) $c[0], $o[1] + (int) $c[1], $o[2] + (int) $c[2], $o[3] + (int) $c[3] );
			}
		}
		return $out;
	}

	/** Pure: "Câu hỏi chưa có kiến thức" grouped by (who, text) — newest first, count, the role / folders they were asked under. */
	public static function gap_list( array $gaps, int $since ): array {
		$by = array();
		foreach ( $gaps as $g ) {
			if ( (int) ( $g['t'] ?? 0 ) < $since ) {
				continue;
			}
			$text = (string) ( $g['q'] ?? '' );
			$key  = ( $g['who'] ?? '' ) . '|' . mb_strtolower( '' !== $text ? $text : '—' ) . '|' . ( $g['role'] ?? '' );
			if ( ! isset( $by[ $key ] ) ) {
				$by[ $key ] = array( 'text' => $text, 'who' => (string) ( $g['who'] ?? '' ), 'role' => (string) ( $g['role'] ?? '' ), 'cols' => array(), 'count' => 0, 'last' => 0 );
			}
			$by[ $key ]['count']++;
			$by[ $key ]['last'] = max( $by[ $key ]['last'], (int) $g['t'] );
			$by[ $key ]['cols'] = array_values( array_unique( array_merge( $by[ $key ]['cols'], (array) ( $g['cols'] ?? array() ) ) ) );
		}
		$list = array_values( $by );
		usort( $list, static function ( $a, $b ) { return array( $b['count'], $b['last'] ) <=> array( $a['count'], $a['last'] ); } );
		return array_slice( $list, 0, 50 );
	}

	public static function routes(): void {
		$can = static function () { return current_user_can( 'edit_posts' ); };
		register_rest_route( 'bizcity-vault/v1', '/scope-matrix', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'rest_matrix' ), 'permission_callback' => $can ) );
		register_rest_route( 'bizcity-vault/v1', '/scope-matrix/turns', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'rest_turns' ), 'permission_callback' => $can ) );
		register_rest_route( 'bizcity-vault/v1', '/scope-matrix/gaps', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'rest_gaps' ), 'permission_callback' => $can ) );
	}

	private static function days_of( $req ): int {
		$d = (int) ( is_object( $req ) ? $req->get_param( 'days' ) : 7 );
		return in_array( $d, array( 7, 30 ), true ) ? $d : 7;
	}

	/** GET /scope-matrix?days=7|30 — the grid: Gurus (rows), folders + notebook / sheet / journal (columns), switches + numbers. */
	public static function rest_matrix( $req ) {
		$days = self::days_of( $req );
		$list = array();
		for ( $i = 0; $i < $days; $i++ ) {
			$list[] = (array) self::opt( self::DAY_PREFIX . gmdate( 'Ymd', (int) self::now() - $i * DAY_IN_SECONDS ), array() );
		}
		$cells   = self::sum_days( $list );
		$all     = $cells['_all'] ?? array( 0, 0, 0, 0 );
		unset( $cells['_all'] );
		$folders = class_exists( 'BizCity_Twin_Vault' ) ? BizCity_Twin_Vault::folders() : array();
		return rest_ensure_response( array(
			'contract' => self::CONTRACT,
			'days'     => $days,
			'kpis'     => array( 'reads' => $all[0], 'hit_rate' => $all[0] ? round( $all[1] * 100 / $all[0] ) : null, 'widen_rate' => $all[0] ? round( $all[3] * 100 / $all[0] ) : null ),
			'gurus'    => self::gurus( $folders, $cells ),
			'folders'  => array_map( static function ( $f ) { return array( 'n' => $f['n'], 'label' => $f['label'], 'icon' => $f['icon'], 'access' => $f['access'], 'gurus' => array_values( array_map( 'intval', (array) ( $f['gurus'] ?? array() ) ) ) ); }, $folders ),
			'cells'    => (object) $cells,
			'can_edit' => current_user_can( 'manage_options' ),
		) );
	}

	/** GET /scope-matrix/turns?row=g12|public&col=50|notebook|…&days= — the last reads of one cell (no content; turn_hash opens the cell's "Tra phạm vi"). */
	public static function rest_turns( $req ) {
		$row   = preg_replace( '/[^a-z0-9]/', '', (string) $req->get_param( 'row' ) );
		$col   = preg_replace( '/[^a-z0-9_]/', '', (string) $req->get_param( 'col' ) );
		$since = (int) self::now() - self::days_of( $req ) * DAY_IN_SECONDS;
		$out   = array();
		foreach ( array_reverse( (array) self::opt( self::RECENT, array() ) ) as $l ) {
			if ( (int) ( $l['t'] ?? 0 ) >= $since && ( '' === $row || $row === ( $l['row'] ?? '' ) ) && ( '' === $col || in_array( $col, (array) ( $l['cols'] ?? array() ), true ) ) ) {
				$out[] = array_intersect_key( $l, array_flip( array( 't', 'th', 'tool', 'who', 'src', 'rows', 'cov', 'widened', 'role', 'cols' ) ) );
				if ( count( $out ) >= 50 ) {
					break;
				}
			}
		}
		return rest_ensure_response( array( 'contract' => self::CONTRACT, 'items' => $out, 'keep_hours_cell' => 168 ) );
	}

	/** GET /scope-matrix/gaps?days=30 — "Câu hỏi chưa có kiến thức". */
	public static function rest_gaps( $req ) {
		$since = (int) self::now() - self::days_of( $req ) * DAY_IN_SECONDS;
		return rest_ensure_response( array( 'contract' => self::CONTRACT, 'items' => self::gap_list( (array) self::opt( self::GAPS, array() ), $since ) ) );
	}

	/** Rows of the grid: the Quản gia, the default (Đối ngoại), every Guru reading a folder, every Guru seen in the counters. */
	private static function gurus( array $folders, array $cells ): array {
		$ids = array();
		$owner   = self::owner_guru();
		$default = class_exists( 'BizCity_Guru_Context_Resolver' ) ? BizCity_Guru_Context_Resolver::default_character_id( false ) : 0;
		foreach ( array( $owner, $default ) as $id ) {
			if ( $id > 0 ) { $ids[ $id ] = true; }
		}
		foreach ( $folders as $f ) {
			foreach ( (array) ( $f['gurus'] ?? array() ) as $id ) { $ids[ (int) $id ] = true; }
		}
		foreach ( array_keys( $cells ) as $k ) {
			if ( 1 === preg_match( '/^g([0-9]+)\|/', (string) $k, $m ) ) { $ids[ (int) $m[1] ] = true; }
		}
		$db  = class_exists( 'BizCity_Knowledge_Database' ) && method_exists( 'BizCity_Knowledge_Database', 'instance' ) ? BizCity_Knowledge_Database::instance() : null;
		$out = array();
		foreach ( array_keys( $ids ) as $id ) {
			$c = $id > 0 && $db ? $db->get_character( $id ) : null;
			if ( $id > 0 && ! $c ) {
				continue;
			}
			$scope = $id > 0 && class_exists( 'BizCity_Guru_Context_Resolver' ) ? BizCity_Guru_Context_Resolver::scope( $id ) : array();
			$out[] = array(
				'id'            => (int) $id,
				'name'          => $c ? (string) $c->name : 'Chủ / nhân sự',
				'role'          => (string) ( $scope['role'] ?? 'none' ),
				'notebooks'     => count( (array) ( $scope['notebook_ids'] ?? array() ) ),
				'is_owner_guru' => $id === $owner,
				'is_default'    => $id === $default,
			);
		}
		usort( $out, static function ( $a, $b ) { return array( $b['is_owner_guru'], $b['is_default'], $a['name'] ) <=> array( $a['is_owner_guru'], $a['is_default'], $b['name'] ); } );
		return $out;
	}

	/* ───────────────────────── helpers / seams ───────────────────────── */

	private static function topic( string $t ): string {
		$words = preg_split( '/\s+/u', trim( preg_replace( '/[0-9@+()\[\]{}<>"]/u', ' ', $t ) ) );
		return self::clip( implode( ' ', array_slice( array_filter( (array) $words, 'strlen' ), 0, 5 ) ), 60 );
	}

	private static function clip( string $s, int $n ): string {
		$s = trim( preg_replace( '/\s+/u', ' ', function_exists( 'wp_strip_all_tags' ) ? wp_strip_all_tags( $s ) : strip_tags( $s ) ) );
		return mb_strlen( $s ) > $n ? mb_substr( $s, 0, $n - 1 ) . '…' : $s;
	}

	private static function owner_guru(): int {
		if ( isset( self::$readers['owner_guru'] ) ) { return (int) call_user_func( self::$readers['owner_guru'] ); }
		return class_exists( 'BizCity_Guru_Seed' ) ? BizCity_Guru_Seed::owner_character_id() : 0;
	}

	private static function now() {
		return isset( self::$readers['now'] ) ? (int) call_user_func( self::$readers['now'] ) : time();
	}

	private static function opt( string $n, $d ) {
		if ( isset( self::$readers['get_option'] ) ) { return call_user_func( self::$readers['get_option'], $n, $d ); }
		return function_exists( 'get_option' ) ? get_option( $n, $d ) : $d;
	}

	private static function opt_set( string $n, $v ): void {
		if ( isset( self::$readers['update_option'] ) ) { call_user_func( self::$readers['update_option'], $n, $v ); return; }
		if ( function_exists( 'update_option' ) ) { update_option( $n, $v, false ); }
	}

	private static function opt_del( string $n ): void {
		if ( isset( self::$readers['delete_option'] ) ) { call_user_func( self::$readers['delete_option'], $n ); return; }
		if ( function_exists( 'delete_option' ) ) { delete_option( $n ); }
	}

	private static function t_get( string $k ) {
		if ( isset( self::$readers['transient_get'] ) ) { return call_user_func( self::$readers['transient_get'], $k ); }
		return function_exists( 'get_transient' ) ? get_transient( $k ) : false;
	}

	private static function t_set( string $k, $v, int $ttl ): void {
		if ( isset( self::$readers['transient_set'] ) ) { call_user_func( self::$readers['transient_set'], $k, $v, $ttl ); return; }
		if ( function_exists( 'set_transient' ) ) { set_transient( $k, $v, $ttl ); }
	}

	private static function t_del( string $k ): void {
		if ( isset( self::$readers['transient_delete'] ) ) { call_user_func( self::$readers['transient_delete'], $k ); return; }
		if ( function_exists( 'delete_transient' ) ) { delete_transient( $k ); }
	}
}
