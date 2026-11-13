<?php
/**
 * BizCity_Vault_Tags — vault-tag@1 (PHASE-0.145 S145-L6, ADR-145-2): the tag dictionary of the Kho tri thức, aggregated from the Journal rows' meta.keywords.
 *
 *   source   the Journal row is the source (meta.keywords); a Vault note's `tags` is a projection of it. No WordPress taxonomy, no new table (ADR 80-2).
 *   counts   only over rows the viewer may read (BizCity_Brain_Access meta.read, which also applies the Kho tri thức folder gate) — never a tenant-wide count.
 *   key      fold(casefold) of a tag (BizCity_Journal_Entry_Meta::fold); label = the first NFC spelling seen on the newest row (or the alias map's canonical label).
 *   aliases  blog option `bizcity_vault_tag_aliases` = { fold(alias): {tag: canonical label, label: alias as typed} } (≤ 2 000); a plain string value is read as the canonical label.
 *   cache    transient per (blog, principal, scope) 5 min; a generation counter bumped on `bizcity_journal_entry_meta_set` (Entry_Meta::set) and on every alias / rename write.
 *   REST     bizcity-vault/v1  GET /tags · POST /tags/rename · /tags/merge · /tags/alias (owner: manage cap) — every affected row goes through Entry_Meta::set
 *            source owner_edit with its own expected_rev (the same call REST journal/{id}/meta makes).      MCP  vault.tags.list (read).
 *
 * No model call (validate-no-llm-in-core).
 *
 * Biz Central Brain — Johnny Chu (Chu Hoàng Anh).
 *
 * [2026-10-27 09:38 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.145 S145-L6 — new file.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\KG_Hub
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Vault_Tags', false ) ) {
	return;
}

final class BizCity_Vault_Tags {

	const CONTRACT     = 'vault-tag@1';
	const NS           = 'bizcity-vault/v1';
	const ALIAS_OPTION = 'bizcity_vault_tag_aliases';
	const GEN_OPTION   = 'bizcity_vault_tags_gen';
	const MAX_ALIASES  = 2000;
	const CACHE_TTL    = 300;   // 5 min (ADR-145-4)
	const PAGE         = 50;    // the index serves ≤ 50 rows per call
	const SCAN_MAX     = 1000;  // rows read per list; beyond ⇒ total_exact = false (never a made-up count)
	const WRITE_MAX    = 500;   // rows one rename / merge may touch
	const IMAGE_KINDS  = array( 'common.photo', 'media.image.output' );

	private static $hooked = false;

	public static function init(): void {
		if ( self::$hooked || ! function_exists( 'add_action' ) ) {
			return;
		}
		self::$hooked = true;
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'bizcity_mcp_register_tools', array( __CLASS__, 'register_tools' ) );
		add_action( 'bizcity_journal_entry_meta_set', array( __CLASS__, 'flush' ), 10, 0 ); // Entry_Meta::set fires it after every meta write (PHASE-0.143 S143-L4 seam)
		if ( function_exists( 'did_action' ) && did_action( 'bizcity_mcp_register_tools' ) ) {
			self::register_tools();
		}
	}

	// ── pure ────────────────────────────────────────────────────────────

	public static function key( string $tag ): string {
		return class_exists( 'BizCity_Journal_Entry_Meta' ) ? BizCity_Journal_Entry_Meta::fold( $tag ) : mb_strtolower( trim( $tag ) );
	}

	/** A tag as people write it: NFC, trimmed, single spaces, ≤ 40 chars (entry-meta keyword limit). Pure. */
	public static function label( string $tag ): string {
		if ( class_exists( 'Normalizer' ) ) {
			$n   = Normalizer::normalize( $tag, Normalizer::FORM_C );
			$tag = false === $n ? $tag : $n;
		}
		return mb_substr( trim( (string) preg_replace( '/\s+/u', ' ', $tag ) ), 0, 40 );
	}

	/** The alias option read tolerant: fold(alias) ⇒ {tag, label}. Pure over its input. */
	public static function clean_aliases( $raw ): array {
		$out = array();
		foreach ( is_array( $raw ) ? $raw : array() as $k => $v ) {
			$tag   = is_array( $v ) ? (string) ( $v['tag'] ?? '' ) : ( is_scalar( $v ) ? (string) $v : '' );
			$alias = is_array( $v ) ? (string) ( $v['label'] ?? $k ) : (string) $k;
			$key   = self::key( (string) $k );
			$tag   = self::label( $tag );
			if ( '' === $key || '' === $tag || self::key( $tag ) === $key ) {
				continue;
			}
			$out[ $key ] = array( 'tag' => $tag, 'label' => self::label( $alias ) ?: $key );
			if ( count( $out ) >= self::MAX_ALIASES ) {
				break;
			}
		}
		return $out;
	}

	public static function is_image( array $e ): bool {
		return 0 === strpos( strtolower( (string) ( $e['original']['mime'] ?? '' ) ), 'image/' ) || in_array( (string) ( $e['kind'] ?? '' ), self::IMAGE_KINDS, true );
	}

	/** 'human' when a person locked / fixed the keywords of this row (entry-meta locked[] or keyword_src human), else 'auto'. Pure. */
	public static function row_src( array $e, string $tag = '' ): string {
		$meta = is_array( $e['meta'] ?? null ) ? $e['meta'] : array();
		if ( in_array( 'keywords', array_map( 'strval', (array) ( $meta['locked'] ?? array() ) ), true ) ) {
			return 'human';
		}
		$src = is_array( $meta['keyword_src'] ?? null ) ? $meta['keyword_src'] : array();
		return '' !== $tag && 'human' === (string) ( $src[ $tag ] ?? '' ) ? 'human' : 'auto';
	}

	/** "2026-10-27 19:40:00" (site clock) ⇒ ISO 8601 with offset; '' stays ''. */
	public static function iso( string $at ): string {
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}$/', $at ) ) {
			return $at;
		}
		try {
			$tz = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'Asia/Ho_Chi_Minh' );
			return ( new DateTime( str_replace( 'T', ' ', $at ), $tz ) )->format( 'c' );
		} catch ( \Throwable $e ) {
			return $at;
		}
	}

	/**
	 * The dictionary over rows already filtered by access (newest first). `$q` narrows by fold(tag or alias) substring. Pure.
	 *
	 * @return array<int,array{tag:string,key:string,aliases:string[],docs:int,images:int,last_used_at:string,src:string}>
	 */
	public static function aggregate( array $rows, array $aliases, string $q = '' ): array {
		$aliases = self::clean_aliases( $aliases );
		$by      = array();
		foreach ( $rows as $e ) {
			if ( ! is_array( $e ) ) {
				continue;
			}
			$seen  = array();
			$image = self::is_image( $e );
			$at    = (string) ( $e['updated_at'] ?? $e['created_at'] ?? '' );
			foreach ( (array) ( $e['meta']['keywords'] ?? array() ) as $raw ) {
				$spell = is_scalar( $raw ) ? self::label( (string) $raw ) : '';
				$k     = self::key( $spell );
				if ( '' === $k ) {
					continue;
				}
				$canon = isset( $aliases[ $k ] ) ? $aliases[ $k ]['tag'] : '';
				$ck    = '' !== $canon ? self::key( $canon ) : $k;
				if ( isset( $seen[ $ck ] ) ) {
					continue; // one row counts once per tag
				}
				$seen[ $ck ] = 1;
				if ( ! isset( $by[ $ck ] ) ) {
					$by[ $ck ] = array( 'tag' => '' !== $canon ? $canon : $spell, 'key' => $ck, 'aliases' => array(), 'docs' => 0, 'images' => 0, 'last_used_at' => '', 'src' => 'auto' );
				}
				$t = &$by[ $ck ];
				++$t['docs'];
				if ( $image ) {
					++$t['images'];
				}
				if ( $at > $t['last_used_at'] ) {
					$t['last_used_at'] = $at;
				}
				if ( 'human' === self::row_src( $e, (string) $raw ) ) {
					$t['src'] = 'human';
				}
				if ( $spell !== $t['tag'] && ! in_array( $spell, $t['aliases'], true ) ) {
					$t['aliases'][] = $spell; // another spelling seen on a row ("chau au" next to "Châu Âu")
				}
				unset( $t );
			}
		}
		foreach ( $aliases as $ak => $a ) {
			$ck = self::key( $a['tag'] );
			if ( isset( $by[ $ck ] ) && ! in_array( $a['label'], $by[ $ck ]['aliases'], true ) ) {
				$by[ $ck ]['aliases'][] = $a['label'];
			}
		}
		$fq = self::key( $q );
		if ( '' !== $fq ) {
			$by = array_filter( $by, static function ( $t ) use ( $fq ) {
				if ( false !== strpos( $t['key'], $fq ) ) {
					return true;
				}
				foreach ( $t['aliases'] as $a ) {
					if ( false !== strpos( self::key( $a ), $fq ) ) {
						return true;
					}
				}
				return false;
			} );
		}
		$list = array_values( $by );
		usort( $list, static function ( $a, $b ) { return $b['docs'] <=> $a['docs'] ?: strcmp( $b['last_used_at'], $a['last_used_at'] ) ?: strcmp( $a['key'], $b['key'] ); } );
		foreach ( $list as &$t ) {
			$t['last_used_at'] = self::iso( $t['last_used_at'] );
		}
		unset( $t );
		return $list;
	}

	/**
	 * A keyword list with every tag whose key is in `$from_keys` replaced by `$to` (position of the first kept, duplicates by key dropped). null when nothing changes. Pure.
	 */
	public static function rewrite( array $keywords, array $from_keys, string $to ): ?array {
		$to     = self::label( $to );
		$tk     = self::key( $to );
		$out    = array();
		$keys   = array();
		$change = false;
		foreach ( $keywords as $w ) {
			$w = is_scalar( $w ) ? (string) $w : '';
			$k = self::key( $w );
			if ( '' === $k ) {
				continue;
			}
			if ( in_array( $k, $from_keys, true ) ) {
				$change = $change || $w !== $to;
				$w      = $to;
				$k      = $tk;
			}
			if ( isset( $keys[ $k ] ) ) {
				$change = true;
				continue;
			}
			$keys[ $k ] = 1;
			$out[]      = $w;
		}
		return $change ? $out : null;
	}

	// ── site ────────────────────────────────────────────────────────────

	public static function aliases(): array {
		return function_exists( 'get_option' ) ? self::clean_aliases( get_option( self::ALIAS_OPTION, array() ) ) : array();
	}

	/** Drop every cached list of this blog (the generation is part of the cache key). */
	public static function flush(): void {
		if ( function_exists( 'update_option' ) ) {
			update_option( self::GEN_OPTION, (int) get_option( self::GEN_OPTION, 0 ) + 1, false );
		}
	}

	private static function owner( array $ctx ): int {
		return (int) ( $ctx['owner_user_id'] ?? $ctx['user_id'] ?? 0 );
	}

	/**
	 * Rows of the owner the principal may `$permission`, newest first, ≤ SCAN_MAX. @return array{rows:array,exact:bool,scanned:int}
	 */
	private static function readable_rows( array $ctx, string $folder, string $permission ): array {
		$owner = self::owner( $ctx );
		if ( $owner <= 0 || ! class_exists( 'BizCity_Journal_Index' ) ) {
			return array( 'rows' => array(), 'exact' => true, 'scanned' => 0 );
		}
		$principal = class_exists( 'BizCity_Brain_Access' ) ? BizCity_Brain_Access::principal( $ctx ) : null;
		$rows      = array();
		$scanned   = 0;
		$exact     = true;
		for ( $off = 0; ; $off += self::PAGE ) {
			if ( $off >= self::SCAN_MAX ) {
				$exact = false;
				break;
			}
			$filter = array( 'owner_user_id' => $owner, 'limit' => self::PAGE, 'offset' => $off );
			if ( '' !== $folder ) {
				$filter['folder'] = $folder;
			}
			$res  = BizCity_Journal_Index::find( $filter );
			$page = (array) ( $res['items'] ?? array() );
			$scanned += count( $page );
			$page = array_filter( $page, static function ( $e ) { return is_array( $e ) && ! empty( $e['meta']['keywords'] ); } );
			if ( null !== $principal ) {
				$page = BizCity_Brain_Access::filter( array_values( $page ), $principal, $permission );
			}
			foreach ( $page as $e ) {
				$rows[] = $e;
			}
			if ( count( (array) ( $res['items'] ?? array() ) ) < self::PAGE ) {
				break;
			}
		}
		return array( 'rows' => $rows, 'exact' => $exact, 'scanned' => $scanned );
	}

	/** The folder of a request: a number / name resolved through the Kho tri thức folders; '' = every folder. */
	private static function folder_of( $ref ): string {
		$ref = trim( (string) $ref );
		if ( '' === $ref ) {
			return '';
		}
		if ( class_exists( 'BizCity_Twin_Vault' ) ) {
			return (string) BizCity_Twin_Vault::resolve_folder( $ref, BizCity_Twin_Vault::folders() );
		}
		return 1 === preg_match( '/^[0-9]{2}$/', $ref ) ? $ref : '';
	}

	/**
	 * vault.tags.list {q?, folder?} ⇒ {contract, items[], total, total_exact, scanned}. Cached per (blog, principal, scope) for 5 min.
	 */
	public static function list( array $ctx, string $q = '', $folder = '' ): array {
		$folder = self::folder_of( $folder );
		$p      = class_exists( 'BizCity_Brain_Access' ) ? BizCity_Brain_Access::principal( $ctx ) : array( 'type' => (string) ( $ctx['role'] ?? '' ), 'user_id' => self::owner( $ctx ) );
		$blog   = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
		$gen    = function_exists( 'get_option' ) ? (int) get_option( self::GEN_OPTION, 0 ) : 0;
		$ckey   = 'bz_vtags_' . md5( implode( '|', array( $blog, $gen, self::owner( $ctx ), (string) ( $p['type'] ?? '' ), (int) ( $p['user_id'] ?? 0 ), (string) ( $p['account_id'] ?? '' ), implode( ',', (array) ( $p['gids'] ?? array() ) ), self::key( $q ), $folder ) ) );
		if ( function_exists( 'get_transient' ) ) {
			$hit = get_transient( $ckey );
			if ( is_array( $hit ) && isset( $hit['items'] ) ) {
				return $hit + array( 'cached' => true );
			}
		}
		$read  = self::readable_rows( $ctx, $folder, 'meta.read' );
		$items = self::aggregate( $read['rows'], self::aliases(), $q );
		$out   = array( 'contract' => self::CONTRACT, 'items' => $items, 'total' => count( $items ), 'total_exact' => $read['exact'], 'scanned' => $read['scanned'], 'folder' => $folder );
		if ( function_exists( 'set_transient' ) ) {
			set_transient( $ckey, $out, self::CACHE_TTL );
		}
		return $out;
	}

	/**
	 * Replace every tag whose key is in `$from` by `$to` on each row of the owner the person may write, one Entry_Meta::set (owner_edit, expected_rev) per row.
	 * @return array|WP_Error {ok, affected, conflicts, failed}
	 */
	public static function retag( array $ctx, array $from, string $to ) {
		$to   = self::label( $to );
		$keys = array_values( array_unique( array_filter( array_map( array( __CLASS__, 'key' ), array_map( 'strval', $from ) ), 'strlen' ) ) );
		if ( '' === self::key( $to ) || ! $keys ) {
			return new WP_Error( 'vault_tag_invalid', 'Cần tên nhãn cũ và tên nhãn mới.', array( 'status' => 400, 'hint' => 'Gõ nhãn cần đổi và nhãn mới rồi thử lại.', 'help_code' => 'vault_tags' ) );
		}
		if ( ! class_exists( 'BizCity_Journal_Entry_Meta' ) ) {
			return new WP_Error( 'journal_unavailable', 'Sổ chưa sẵn sàng trên site này.', array( 'status' => 503, 'help_code' => 'vault_tags' ) );
		}
		$owner     = self::owner( $ctx );
		$affected  = 0;
		$conflicts = array();
		$failed    = array();
		foreach ( self::readable_rows( $ctx, '', 'write' )['rows'] as $e ) {
			if ( $affected + count( $failed ) + count( $conflicts ) >= self::WRITE_MAX ) {
				break;
			}
			$kw = self::rewrite( (array) ( $e['meta']['keywords'] ?? array() ), $keys, $to );
			if ( null === $kw ) {
				continue;
			}
			$res = BizCity_Journal_Entry_Meta::set( $owner, array( 'entry_id' => (int) $e['entry_id'] ), array( 'keywords' => array_slice( $kw, 0, BizCity_Journal_Entry_Meta::MAX_KEYWORDS ) ), array(
				'merge'        => true,
				'expected_rev' => (int) ( $e['meta']['rev'] ?? 0 ),
				'source'       => 'owner_edit',
			) );
			if ( is_wp_error( $res ) ) {
				if ( 'entry_meta_conflict' === $res->get_error_code() ) {
					$conflicts[] = (string) ( $e['ref_code'] ?? $e['entry_id'] );
				} else {
					$failed[] = (string) ( $e['ref_code'] ?? $e['entry_id'] );
				}
				continue;
			}
			++$affected;
		}
		// the alias map follows: an alias that pointed at a renamed tag now points at the new label; a renamed spelling becomes nobody's alias of itself
		$aliases = self::aliases();
		$changed = false;
		foreach ( $aliases as $ak => $a ) {
			if ( in_array( self::key( $a['tag'] ), $keys, true ) ) {
				$aliases[ $ak ]['tag'] = $to;
				$changed               = true;
			}
			if ( self::key( $to ) === $ak ) {
				unset( $aliases[ $ak ] );
				$changed = true;
			}
		}
		if ( $changed && function_exists( 'update_option' ) ) {
			update_option( self::ALIAS_OPTION, $aliases, false );
		}
		self::flush();
		return array( 'ok' => ! $failed, 'affected' => $affected, 'conflicts' => $conflicts, 'failed' => $failed );
	}

	/** Add (or remove) one alias of a canonical tag in the blog option. @return array|WP_Error {ok, affected} */
	public static function set_alias( string $tag, string $alias, bool $remove = false ) {
		$tag = self::label( $tag );
		$ak  = self::key( $alias );
		if ( '' === self::key( $tag ) || '' === $ak || self::key( $tag ) === $ak ) {
			return new WP_Error( 'vault_tag_invalid', 'Cần nhãn và một tên gọi khác của nhãn.', array( 'status' => 400, 'hint' => 'Ví dụ: nhãn "Châu Âu", tên gọi khác "EU".', 'help_code' => 'vault_tags' ) );
		}
		$aliases = self::aliases();
		if ( $remove ) {
			$hit = isset( $aliases[ $ak ] );
			unset( $aliases[ $ak ] );
		} else {
			if ( count( $aliases ) >= self::MAX_ALIASES && ! isset( $aliases[ $ak ] ) ) {
				return new WP_Error( 'vault_tag_alias_full', 'Đã đủ 2 000 tên gọi khác.', array( 'status' => 409, 'hint' => 'Xoá bớt tên gọi khác không dùng nữa.', 'help_code' => 'vault_tags' ) );
			}
			$hit            = true;
			$aliases[ $ak ] = array( 'tag' => $tag, 'label' => self::label( $alias ) );
		}
		if ( function_exists( 'update_option' ) ) {
			update_option( self::ALIAS_OPTION, $aliases, false );
		}
		self::flush();
		return array( 'ok' => true, 'affected' => $hit ? 1 : 0 );
	}

	// ── REST bizcity-vault/v1 ───────────────────────────────────────────

	public static function can_manage(): bool {
		return class_exists( 'BizCity_Network_Admin_Capability' ) ? BizCity_Network_Admin_Capability::can_manage() : current_user_can( 'manage_options' );
	}

	public static function routes(): void {
		register_rest_route( self::NS, '/tags', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'rest_list' ), 'permission_callback' => static function () { return is_user_logged_in(); } ) );
		foreach ( array( 'rename', 'merge', 'alias' ) as $op ) {
			register_rest_route( self::NS, '/tags/' . $op, array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'rest_' . $op ), 'permission_callback' => array( __CLASS__, 'can_manage' ) ) );
		}
	}

	/** Same principal as the Twin Vault REST: the logged-in person reads their own rows (Brain_Access owner / staff of their own book). */
	private static function rest_ctx(): array {
		return array( 'owner_user_id' => (int) get_current_user_id(), 'role' => current_user_can( 'manage_options' ) ? 'owner' : 'staff' );
	}

	private static function body( WP_REST_Request $r ): array {
		$b = $r->get_json_params();
		return is_array( $b ) ? $b : (array) $r->get_body_params();
	}

	private static function respond( $res ): WP_REST_Response {
		if ( is_wp_error( $res ) ) {
			$d = (array) $res->get_error_data();
			return new WP_REST_Response( array( 'code' => $res->get_error_code(), 'message' => $res->get_error_message(), 'hint' => (string) ( $d['hint'] ?? '' ), 'help_code' => (string) ( $d['help_code'] ?? 'vault_tags' ) ), (int) ( $d['status'] ?? 400 ) );
		}
		return new WP_REST_Response( $res, 200 );
	}

	public static function rest_list( WP_REST_Request $r ): WP_REST_Response {
		return self::respond( self::list( self::rest_ctx(), (string) $r->get_param( 'q' ), (string) $r->get_param( 'folder' ) ) );
	}

	/** {from, to} */
	public static function rest_rename( WP_REST_Request $r ): WP_REST_Response {
		$b = self::body( $r );
		return self::respond( self::retag( self::rest_ctx(), array( (string) ( $b['from'] ?? '' ) ), (string) ( $b['to'] ?? '' ) ) );
	}

	/** {from[], into} */
	public static function rest_merge( WP_REST_Request $r ): WP_REST_Response {
		$b = self::body( $r );
		return self::respond( self::retag( self::rest_ctx(), array_slice( array_map( 'strval', array_filter( (array) ( $b['from'] ?? array() ), 'is_scalar' ) ), 0, 50 ), (string) ( $b['into'] ?? '' ) ) );
	}

	/** {tag, alias, remove?} */
	public static function rest_alias( WP_REST_Request $r ): WP_REST_Response {
		$b = self::body( $r );
		return self::respond( self::set_alias( (string) ( $b['tag'] ?? '' ), (string) ( $b['alias'] ?? '' ), ! empty( $b['remove'] ) ) );
	}

	// ── MCP vault.tags.list ─────────────────────────────────────────────

	public static function register_tools(): void {
		if ( ! class_exists( 'BizCity_MCP_Tool_Registry' ) ) {
			return;
		}
		$R = 'BizCity_MCP_Tool_Registry';
		// @mcp bizcity-mcp-standard@1 tool vault.tags.list
		$R::register( 'vault.tags.list', array( 'mode' => 'notebook', 'roles' => array( 'owner', 'staff' ), 'confirm' => 'never', 'fallback_pack' => null, 'since' => '0.145.0' ) + array(
			'title'          => 'Danh sách nhãn của Kho tri thức',
			'description'    => 'Liệt kê các NHÃN (từ khoá) của tài liệu / ảnh trong Kho tri thức, mỗi nhãn có số tài liệu, số ảnh, tên gọi khác và lần dùng gần nhất — chỉ đếm trên các mục người hỏi được xem. Dùng khi hỏi "kho có những nhãn / chủ đề nào", "nhãn nào nhiều ảnh nhất", hoặc để chọn đúng nhãn trước khi tìm. q = lọc nhãn theo chữ (có dấu hay không dấu); folder = số hoặc tên thư mục. KHÔNG dùng để tìm tài liệu (dùng journal_find / vault.search); KHÔNG đổi tên nhãn (chủ đổi trên web).',
			'input_schema'   => array( 'type' => 'object', 'properties' => array( 'q' => array( 'type' => 'string', 'maxLength' => 60 ), 'folder' => array( 'type' => 'string', 'maxLength' => 60, 'description' => 'Thư mục: số (vd 50) hoặc tên.' ) ) ),
			'output_schema'  => $R::envelope_schema( array( 'items' => array( 'type' => 'array' ), 'total' => array( 'type' => 'integer' ), 'total_exact' => array( 'type' => 'boolean' ) ), array( 'items' ) ),
			'read_only'      => true, 'idempotent' => true, 'required_scope' => 'brain.read', 'scopes' => array( 'brain.read' ),
			'handler'        => array( __CLASS__, 'mcp_list' ),
			'llm_alias'      => 'vault_tags_list',
			'brain'          => array( 'tier' => 'L1', 'permission' => 'meta.read', 'crud' => 'read', 'answer_hint' => 'instant' ),
		) );
	}

	public static function mcp_list( array $args, array $ctx ): array {
		return self::list( $ctx, (string) ( $args['q'] ?? '' ), (string) ( $args['folder'] ?? '' ) );
	}
}
