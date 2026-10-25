<?php
/**
 * BizCity_Twin_Vault — Twin Vault, "Kho tri thức" (PHASE-0.134, R-TWIN-VAULT, contract twin-vault@1): one Markdown + YAML store per site that turns documents,
 * images and information into records that are readable, linkable, editable and traceable to their source (the Journal row, `source_ref`).
 *
 *   notes   `lib:/note/x.md` = a Markdown file of the existing library (uploads/bizcity-skills/{blog}/, same paths: the Skill editor keeps working)
 *           `src:TL-47`      = the readable copy of one Journal row (0.133 Vault Store representations)
 *   tree    six virtual roots by type (document · image · note · sheet · job · skill), D134-2
 *   graph   the Journal edges of brain-map@1.2.0 + [[wikilinks]] / `links:` + `@tool` in skills (TV-5: only what is declared)
 *   REST    bizcity-vault/v1  /tree · /list · /note (GET, POST) · /search · /graph       MCP  vault.list · vault.search · vault.write (vault.get in core/mcp)
 *
 * Reads are decided by brain-access@1 at the source (TV-7); a write opens / updates exactly one Journal row `common.note` (TV-2, D134-6) and never touches
 * /skills or /tools (people edit skills in the Steps editor; the model only writes notes, D134-8). No model call (validate-no-llm-in-core), no new table.
 *
 * Biz Central Brain — Johnny Chu (Chu Hoàng Anh).
 *
 * [2026-10-24 07:36 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.134 W1 (S134-L1…L6) — new file.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\KG_Hub
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Twin_Vault', false ) ) {
	return;
}

final class BizCity_Twin_Vault {

	const CONTRACT  = 'twin-vault@1.0.0';
	const NS        = 'bizcity-vault/v1';
	const TYPES     = array( 'document', 'image', 'note', 'sheet', 'job', 'skill' );
	const LABELS    = array( 'document' => 'Tài liệu', 'image' => 'Media', 'note' => 'Ghi chú', 'sheet' => 'Sổ', 'job' => 'Việc', 'skill' => 'Kỹ năng' );
	const SKILL_DIRS = array( 'skills', 'tools' );
	const KINDS     = array(
		'image' => array( 'common.photo', 'media.image.output', 'media.audio.output', 'media.video.output' ),
		'note'  => array( 'common.note', 'common.instruction', 'task.minutes', 'grp.brief' ),
		'sheet' => array( 'sheet.define' ),
		'job'   => array( 'job.run' ),
	);
	/**
	 * Digital folders (D134-10): number + label; the user adds more. 00 Home is the overview, not a folder.
	 * [2026-10-25 Johnny Chu - Chu Hoàng Anh] PHASE-0.136 (owner: "bổ sung 50 Customer Support … 55 Legal") — + 50 · 55 · 60 and `aliases`: the words a person
	 * uses for the folder ("lưu vào chăm sóc khách hàng", "học cho pháp lý") so vault.move / vault.write accept a NAME, not only a number. A default missing from
	 * a site's saved list is added back on read (sites that saved 10–40 get 50 · 55 · 60); a number the person already uses keeps the person's folder.
	 */
	const DEFAULT_FOLDERS = array(
		array( 'n' => '10', 'label' => 'Học & dạy', 'icon' => '🎓', 'aliases' => array( 'học', 'dạy', 'đào tạo', 'giáo án', 'bài học', 'training' ) ),
		array( 'n' => '20', 'label' => 'Công việc', 'icon' => '💼', 'aliases' => array( 'công việc', 'vận hành', 'quy trình', 'sop' ) ),
		array( 'n' => '30', 'label' => 'Dự án', 'icon' => '🗂️', 'aliases' => array( 'dự án', 'project' ) ),
		array( 'n' => '40', 'label' => 'Nội dung & sáng tạo', 'icon' => '✨', 'aliases' => array( 'nội dung', 'marketing', 'sáng tạo', 'content', 'bài đăng' ) ),
		array( 'n' => '50', 'label' => 'Chăm sóc khách hàng', 'icon' => '🤝', 'aliases' => array( 'customer support', 'chăm sóc khách hàng', 'cskh', 'hỗ trợ khách hàng', 'support', 'khiếu nại' ) ),
		array( 'n' => '55', 'label' => 'Pháp lý & hợp đồng', 'icon' => '⚖️', 'access' => 'owner', 'aliases' => array( 'legal', 'pháp lý', 'hợp đồng', 'luật', 'giấy phép' ) ),
		array( 'n' => '60', 'label' => 'Sản phẩm & dịch vụ', 'icon' => '📦', 'aliases' => array( 'sản phẩm', 'dịch vụ', 'product', 'bảng giá', 'catalog' ) ),
		// [2026-10-25] PHASE-0.136 Q136-1/2 (chủ "ok theo đề xuất"): + 65 · 70 · 80 · 90; 55 · 70 · 80 mặc định "Chỉ chủ", còn lại "Chủ + nhân sự"
		array( 'n' => '65', 'label' => 'Bán hàng & báo giá', 'icon' => '🛒', 'aliases' => array( 'bán hàng', 'báo giá', 'sales', 'đơn hàng', 'chào hàng' ) ),
		array( 'n' => '70', 'label' => 'Tài chính & công nợ', 'icon' => '💰', 'access' => 'owner', 'aliases' => array( 'tài chính', 'kế toán', 'công nợ', 'hoá đơn', 'hóa đơn', 'thu chi' ) ),
		array( 'n' => '80', 'label' => 'Nhân sự & nội bộ', 'icon' => '👥', 'access' => 'owner', 'aliases' => array( 'nhân sự', 'nội bộ', 'hr', 'lương' ) ),
		array( 'n' => '90', 'label' => 'Lưu trữ', 'icon' => '🗄️', 'aliases' => array( 'lưu trữ', 'archive' ) ),
	);
	const FOLDER_OPTION = 'bizcity_vault_folders';
	/** Who may see what is filed in a folder (D134-13): owner only · owner + staff (default) · public (stored; no public door exists yet). */
	const ACCESS = array( 'owner', 'staff', 'public' );
	const MAX_FILES = 400;
	const MAX_NODES = 400;
	const MAX_MD    = 200000;

	private static $hooked = false;

	public static function init(): void {
		if ( self::$hooked || ! function_exists( 'add_action' ) ) {
			return;
		}
		self::$hooked = true;
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'bizcity_mcp_register_tools', array( __CLASS__, 'register_tools' ) );
		if ( function_exists( 'did_action' ) && did_action( 'bizcity_mcp_register_tools' ) ) {
			self::register_tools();
		}
	}

	// ── pure ─────────────────────────────────────────────────────────────

	/** A library path a note may live at: "/folder/…/name.md", safe characters only, no "..". '' otherwise. Pure. */
	public static function clean_path( string $path ): string {
		$p = '/' . ltrim( str_replace( '\\', '/', trim( $path ) ), '/' );
		if ( false !== strpos( $p, "\0" ) || false !== strpos( rawurldecode( $p ), '..' ) || 1 !== preg_match( '#^/(?:[\p{L}\p{N}_-][\p{L}\p{N}._-]{0,63}/){0,4}[\p{L}\p{N}_-][\p{L}\p{N}._-]{0,120}\.md$#u', $p ) ) {
			return '';
		}
		return $p;
	}

	/** Type of a library file by its first folder: /skills, /tools ⇒ skill; anything else ⇒ note (D134-2). Pure. */
	public static function lib_type( string $path ): string {
		$first = explode( '/', ltrim( $path, '/' ) )[0] ?? '';
		return in_array( $first, self::SKILL_DIRS, true ) ? 'skill' : 'note';
	}

	/** Type of a Journal row by kind (+ layer for documents); '' = not a vault note. Pure. */
	public static function row_type( string $kind, string $layer, bool $has_file ): string {
		foreach ( self::KINDS as $t => $kinds ) {
			if ( in_array( $kind, $kinds, true ) ) {
				return $t;
			}
		}
		if ( 0 === strpos( $kind, 'skill.' ) ) {
			return 'skill';
		}
		return in_array( $layer, array( 'document', 'output' ), true ) && $has_file ? 'document' : '';
	}

	/** "lib:/note/x.md" ⇒ [lib, path] · "src:TL-47" / "TL-47@v2" / "TL-47" ⇒ [src, code]; null otherwise. Pure. */
	public static function parse_id( string $id ): ?array {
		$id = trim( $id );
		if ( 0 === strpos( $id, 'lib:' ) ) {
			$p = self::clean_path( substr( $id, 4 ) );
			return '' === $p ? null : array( 'lib', $p );
		}
		if ( 0 === strpos( $id, 'src:' ) ) {
			$id = substr( $id, 4 );
		}
		return 1 === preg_match( '/^([A-Za-z]{2}-\d{1,12})(?:@[vs]\d{1,6})?$/', $id, $m ) ? array( 'src', strtoupper( $m[1] ) ) : null;
	}

	/** Outgoing links of a Markdown body: [[TL-47 …]] / [[title]] (targets) and @tool.name (tools). Pure. */
	public static function links_of( string $md, array $fm = array() ): array {
		$targets = array();
		if ( preg_match_all( '/\[\[([^\[\]\n|]{1,160})(?:\|[^\]\n]{0,80})?\]\]/u', $md, $m ) ) {
			foreach ( $m[1] as $t ) {
				$targets[] = trim( $t );
			}
		}
		foreach ( (array) ( $fm['links'] ?? array() ) as $l ) {
			if ( is_scalar( $l ) ) {
				$targets[] = trim( (string) preg_replace( '/^\[\[|\]\]$/', '', (string) $l ) );
			}
		}
		$tools = array();
		if ( preg_match_all( '/(?<![\w.@])@([a-z][a-z0-9_]*\.[a-z0-9_.]+[a-z0-9_])/', $md, $t ) ) {
			$tools = $t[1];
		}
		return array( 'targets' => array_slice( array_values( array_unique( array_filter( $targets, 'strlen' ) ) ), 0, 50 ), 'tools' => array_slice( array_values( array_unique( $tools ) ), 0, 30 ) );
	}

	/** A link target ⇒ a note id: "TL-47 …" ⇒ src:TL-47; else the library note whose title / file name folds to it; '' when unknown. Pure. */
	public static function resolve_link( string $target, array $titles ): string {
		if ( 1 === preg_match( '/^([A-Za-z]{2}-\d{1,12})\b/', $target, $m ) ) {
			return 'src:' . strtoupper( $m[1] );
		}
		$k = self::fold( preg_replace( '/\.md$/', '', basename( $target ) ) );
		return (string) ( $titles[ $k ] ?? '' );
	}

	/** Title: frontmatter title / name ⇒ first "# " line ⇒ file name. Pure. */
	public static function title_of( array $fm, string $md, string $path ): string {
		foreach ( array( 'title', 'name' ) as $k ) {
			if ( is_scalar( $fm[ $k ] ?? null ) && '' !== trim( (string) $fm[ $k ] ) ) {
				return mb_substr( trim( (string) $fm[ $k ] ), 0, 160 );
			}
		}
		if ( 1 === preg_match( '/^#\s+(.{1,160})$/mu', $md, $m ) ) {
			return trim( $m[1] );
		}
		return (string) preg_replace( '/\.md$/', '', basename( $path ) );
	}

	/** A file name from a title ("Chuẩn bị họp phụ huynh" ⇒ "chuan-bi-hop-phu-huynh.md"). Pure. */
	public static function slug( string $title ): string {
		$s = trim( (string) preg_replace( '/[^a-z0-9]+/', '-', self::fold( $title ) ), '-' );
		return ( '' !== $s ? substr( $s, 0, 80 ) : 'ghi-chu-' . gmdate( 'YmdHis' ) ) . '.md';
	}

	public static function fold( string $s ): string {
		return class_exists( 'BizCity_Journal_Entry_Meta' ) ? BizCity_Journal_Entry_Meta::fold( $s ) : mb_strtolower( trim( $s ) );
	}

	/** The file a note is saved as: frontmatter (twin-vault@1, site keys last so a caller can never set them) + body. Pure. */
	public static function compose( array $fm_in, string $title, string $md, string $source_ref, int $rev, string $created, string $now ): string {
		$fm = array();
		foreach ( array( 'tags', 'links', 'aliases' ) as $k ) { // the only keys a writer may set besides title (TV-3)
			if ( isset( $fm_in[ $k ] ) ) {
				$fm[ $k ] = array_slice( array_values( array_filter( array_map( static function ( $v ) { return is_scalar( $v ) ? mb_substr( trim( (string) $v ), 0, 80 ) : ''; }, (array) $fm_in[ $k ] ), 'strlen' ) ), 0, 20 );
			}
		}
		$folder = self::clean_folder( $fm_in['folder'] ?? '' ); // the folder a note is filed in (D134-10); a bad shape is dropped
		$fm     = array( 'schema' => 'twin-vault@1', 'type' => 'note', 'title' => mb_substr( $title, 0, 160 ) ) + $fm + ( '' !== $folder ? array( 'folder' => $folder ) : array() ) + array( 'source_ref' => $source_ref, 'rev' => max( 1, $rev ), 'created' => $created, 'updated' => $now );
		$body = trim( (string) preg_replace( "/\r\n?/", "\n", BizCity_Brain_Vault_Store::split_frontmatter( $md )['body'] ) );
		return "---\n" . BizCity_Brain_Vault_Store::emit_yaml( $fm ) . "---\n" . $body . "\n";
	}

	/** "30" or "30/lop-tieng-trung": two digits, optional slug. '' when not that shape. Pure. */
	public static function clean_folder( $f ): string {
		$f = trim( (string) $f );
		return 1 === preg_match( '#^[0-9]{2}(/[a-z0-9-]{1,40})?$#', $f ) ? $f : '';
	}

	/** The folder list: the option when set, else the defaults. Each {n,label,icon}, sorted by number. */
	public static function folders(): array {
		$raw = function_exists( 'get_option' ) ? get_option( self::FOLDER_OPTION, array() ) : array();
		$out = array();
		$defaults = array();
		foreach ( self::DEFAULT_FOLDERS as $d ) {
			$defaults[ $d['n'] ] = $d;
		}
		// the saved list first (the person's names win), then every default whose number is still free
		foreach ( array_merge( is_array( $raw ) ? array_values( $raw ) : array(), array_values( self::DEFAULT_FOLDERS ) ) as $f ) {
			$n = self::clean_folder( $f['n'] ?? '' );
			if ( '' !== $n && false === strpos( $n, '/' ) && ! isset( $out[ $n ] ) ) {
				// a saved default without aliases (saved before 0.136) gets the default's aliases
				$aliases = array_key_exists( 'aliases', $f ) ? $f['aliases'] : ( $defaults[ $n ]['aliases'] ?? array() );
				$out[ $n ] = array( 'n' => $n, 'label' => mb_substr( trim( (string) ( $f['label'] ?? '' ) ), 0, 40 ) ?: $n, 'icon' => mb_substr( trim( (string) ( $f['icon'] ?? '' ) ), 0, 8 ) ?: '📁', 'access' => in_array( (string) ( $f['access'] ?? '' ), self::ACCESS, true ) ? (string) $f['access'] : 'staff', 'rules' => self::clean_rules( $f['rules'] ?? array() ), 'gurus' => self::clean_ids( $f['gurus'] ?? array() ), 'aliases' => self::clean_aliases( $aliases ) );
			}
		}
		ksort( $out );
		return array_values( $out );
	}

	/** The next free number: the next ten after the highest, else the first free five (15, 25 …), else any free 10–99. '' when full. Pure. */
	public static function next_folder_number( array $taken ): string {
		$max = 0;
		foreach ( $taken as $n ) {
			$max = max( $max, (int) $n );
		}
		$taken = array_map( 'strval', $taken );
		$free  = static function ( int $c ) use ( $taken ): bool { return ! in_array( sprintf( '%02d', $c ), $taken, true ); };
		for ( $c = max( 10, (int) ( floor( $max / 10 ) * 10 ) + 10 ); $c <= 90; $c += 10 ) {
			if ( $free( $c ) ) {
				return sprintf( '%02d', $c );
			}
		}
		foreach ( array( 5, 1 ) as $step ) {
			for ( $c = 10; $c <= 99; $c += $step ) {
				if ( $free( $c ) ) {
					return sprintf( '%02d', $c );
				}
			}
		}
		return '';
	}

	/** Words a person calls a folder (≤ 12, 2–40 chars, unique by folded form). Pure. */
	public static function clean_aliases( $list ): array {
		$out  = array();
		$seen = array();
		foreach ( (array) $list as $a ) {
			$a = is_scalar( $a ) ? mb_substr( trim( (string) $a ), 0, 40 ) : '';
			$f = self::fold( $a );
			if ( mb_strlen( $f ) >= 2 && ! isset( $seen[ $f ] ) ) {
				$seen[ $f ] = 1;
				$out[]      = $a;
			}
		}
		return array_slice( $out, 0, 12 );
	}

	/**
	 * [2026-10-25] PHASE-0.136 — a folder by number OR by name: "50", "50/lop-a", "Chăm sóc khách hàng", "customer support", "CSKH", "pháp lý".
	 * Number shape ⇒ itself; else the folded words must equal a folder's label or one of its aliases, or contain it as whole words ("lưu vào chăm sóc
	 * khách hàng" ⇒ 50). The longest match wins (so "hợp đồng kinh doanh" with a "hợp đồng kinh doanh" alias beats "hợp đồng"). '' = none. Pure over $folders.
	 */
	public static function resolve_folder( $ref, array $folders ): string {
		$ref = trim( (string) $ref );
		if ( '' === $ref ) {
			return '';
		}
		$n = self::clean_folder( $ref );
		if ( '' !== $n ) {
			return $n;
		}
		$q    = ' ' . self::fold( $ref ) . ' ';
		$best = '';
		$len  = 0;
		foreach ( $folders as $f ) {
			foreach ( array_merge( array( (string) ( $f['label'] ?? '' ) ), (array) ( $f['aliases'] ?? array() ) ) as $name ) {
				$w = self::fold( (string) $name );
				if ( mb_strlen( $w ) >= 2 && false !== strpos( $q, ' ' . $w . ' ' ) && mb_strlen( $w ) > $len ) {
					$best = (string) $f['n'];
					$len  = mb_strlen( $w );
				}
			}
		}
		return $best;
	}

	/** Add a folder (owner / staff): label + icon, number given by the site. @return array|WP_Error the new folder */
	public static function add_folder( string $label, string $icon = '', string $access = 'staff' ) {
		$label = mb_substr( trim( $label ), 0, 40 );
		if ( '' === $label ) {
			return new WP_Error( 'vault_folder_invalid', 'Thư mục cần một cái tên.', array( 'status' => 400, 'hint' => 'Đặt tên thư mục rồi lưu lại.', 'help_code' => 'twin_vault' ) );
		}
		$list = self::folders();
		$n    = self::next_folder_number( array_column( $list, 'n' ) );
		if ( '' === $n ) {
			return new WP_Error( 'vault_folder_full', 'Đã dùng hết số thư mục (10–90).', array( 'status' => 409, 'hint' => 'Đổi tên hoặc dùng lại một thư mục có sẵn.', 'help_code' => 'twin_vault' ) );
		}
		$f      = array( 'n' => $n, 'label' => $label, 'icon' => mb_substr( trim( $icon ), 0, 8 ) ?: '📁', 'access' => in_array( $access, self::ACCESS, true ) ? $access : 'staff' );
		$list[] = $f;
		update_option( self::FOLDER_OPTION, $list, false );
		return $f;
	}

	/** Rename (and re-icon) a folder; the number never changes here (moving items is a separate, confirmed action). @return array|WP_Error */
	public static function rename_folder( string $n, string $label, string $icon = '', string $access = '' ) {
		return self::update_folder( $n, array( 'label' => $label ) + ( '' !== trim( $icon ) ? array( 'icon' => $icon ) : array() ) + ( '' !== $access ? array( 'access' => $access ) : array() ) );
	}

	/**
	 * [2026-10-25 Johnny Chu - Chu Hoàng Anh] PHASE-0.134 S134-T10 — the folder sheet saves through here: label · icon · access · rules (gợi ý xếp) · gurus
	 * (Guru dùng thư mục này làm kiến thức, D134-20). Only the keys sent change; the number never does. @return array|WP_Error the folder
	 */
	public static function update_folder( string $n, array $patch ) {
		$list = self::folders();
		foreach ( $list as $i => $f ) {
			if ( $f['n'] !== $n ) {
				continue;
			}
			if ( array_key_exists( 'label', $patch ) ) {
				$label = mb_substr( trim( (string) $patch['label'] ), 0, 40 );
				if ( '' === $label ) {
					return new WP_Error( 'vault_folder_invalid', 'Thư mục cần một cái tên.', array( 'status' => 400, 'hint' => 'Đặt tên thư mục rồi lưu lại.', 'help_code' => 'twin_vault' ) );
				}
				$list[ $i ]['label'] = $label;
			}
			if ( '' !== trim( (string) ( $patch['icon'] ?? '' ) ) ) {
				$list[ $i ]['icon'] = mb_substr( trim( (string) $patch['icon'] ), 0, 8 );
			}
			if ( in_array( (string) ( $patch['access'] ?? '' ), self::ACCESS, true ) ) {
				$list[ $i ]['access'] = (string) $patch['access'];
			}
			if ( array_key_exists( 'rules', $patch ) ) {
				$list[ $i ]['rules'] = self::clean_rules( is_array( $patch['rules'] ) ? $patch['rules'] : array() );
			}
			if ( array_key_exists( 'gurus', $patch ) ) {
				$list[ $i ]['gurus'] = self::clean_ids( $patch['gurus'] );
			}
			if ( array_key_exists( 'aliases', $patch ) ) { // PHASE-0.136 — the names a person / the assistant may use for this folder
				$list[ $i ]['aliases'] = self::clean_aliases( $patch['aliases'] );
			}
			update_option( self::FOLDER_OPTION, $list, false );
			return $list[ $i ];
		}
		return new WP_Error( 'vault_folder_not_found', 'Không có thư mục này.', array( 'status' => 404, 'hint' => 'Tải lại cây.', 'help_code' => 'twin_vault' ) );
	}

	/** Suggestion rules of a folder (D134-18): keywords (≤ 20, folded, 2–40 chars) · Zalo group ids (≤ 10). Pure. */
	public static function clean_rules( $r ): array {
		$r  = is_array( $r ) ? $r : array();
		$kw = array();
		foreach ( (array) ( $r['keywords'] ?? array() ) as $k ) {
			$f = is_scalar( $k ) ? mb_substr( self::fold( (string) $k ), 0, 40 ) : '';
			if ( mb_strlen( $f ) >= 2 && ! in_array( $f, $kw, true ) ) {
				$kw[] = $f;
			}
		}
		$groups = array();
		foreach ( (array) ( $r['groups'] ?? array() ) as $g ) {
			if ( is_scalar( $g ) && 1 === preg_match( '/^[0-9]{3,30}$/', (string) $g ) && ! in_array( (string) $g, $groups, true ) ) {
				$groups[] = (string) $g;
			}
		}
		return array( 'keywords' => array_slice( $kw, 0, 20 ), 'groups' => array_slice( $groups, 0, 10 ) );
	}

	/** Positive unique ints (≤ 10). Pure. */
	public static function clean_ids( $ids ): array {
		$out = array();
		foreach ( (array) $ids as $v ) {
			$v = (int) $v;
			if ( $v > 0 && ! in_array( $v, $out, true ) ) {
				$out[] = $v;
			}
		}
		return array_slice( $out, 0, 10 );
	}

	/**
	 * D134-18 — the folder an UNFILED item would go in by the owner's own rules, or null: each rule keyword found as a whole word in the item's
	 * words (title · about · keywords · topics · caption) = 1 point, its Zalo group = 3 points; best score wins, a tie goes to the lower number.
	 * Only a suggestion: the person (or the assistant on the owner's word) files it with vault.move. Site code, no model. Pure.
	 *
	 * @param array $item {title?, meta?{title,about,keywords,topics,caption}, group?{gid}, belongs?{group{gid}}, vault_folder?}
	 * @return array{n:string,label:string,icon:string,why:string[]}|null
	 */
	public static function suggest_folder( array $item, array $folders ): ?array {
		if ( '' !== self::clean_folder( $item['vault_folder'] ?? ( $item['meta']['folder'] ?? '' ) ) ) {
			return null;
		}
		$m    = is_array( $item['meta'] ?? null ) ? $item['meta'] : array();
		$text = ' ' . self::fold( implode( ' ', array_map( 'strval', array_merge(
			array( (string) ( $item['title'] ?? '' ), (string) ( $m['title'] ?? '' ), (string) ( $m['about'] ?? '' ), (string) ( $m['caption'] ?? '' ), (string) ( $item['text'] ?? '' ) ),
			array_filter( (array) ( $m['keywords'] ?? array() ), 'is_scalar' ),
			array_filter( (array) ( $m['topics'] ?? array() ), 'is_scalar' )
		) ) ) ) . ' ';
		$gid  = (string) ( $item['group']['gid'] ?? ( $item['belongs']['group']['gid'] ?? '' ) );
		$best = null;
		foreach ( $folders as $f ) {
			$score = 0;
			$why   = array();
			foreach ( (array) ( $f['rules']['keywords'] ?? array() ) as $k ) {
				if ( '' !== (string) $k && false !== strpos( $text, ' ' . $k . ' ' ) ) {
					$score++;
					$why[] = (string) $k;
				}
			}
			if ( '' !== $gid && in_array( $gid, (array) ( $f['rules']['groups'] ?? array() ), true ) ) {
				$score += 3;
				$why[]  = 'nhóm Zalo';
			}
			if ( $score > 0 && ( null === $best || $score > $best[0] ) ) {
				$best = array( $score, array( 'n' => (string) $f['n'], 'label' => (string) $f['label'], 'icon' => (string) ( $f['icon'] ?? '' ), 'why' => array_slice( $why, 0, 5 ) ) );
			}
		}
		return null === $best ? null : $best[1];
	}

	/** D134-18 — the suggestion for one Journal row (journal card / vault note), among the folders this role may see; null when none / filed. */
	public static function suggest_for_row( array $row, array $ctx = array() ): ?array {
		$role = array() === $ctx ? 'owner' : self::role_of( $ctx ); // no ctx = the owner's own card (journal REST view=card)
		$fs   = array_values( array_filter( self::folders(), static function ( $f ) use ( $role ) { return self::access_allows( (string) $f['access'], $role ); } ) );
		return self::suggest_folder( $row, $fs );
	}

	/** D134-20 — the folders a Guru uses as its knowledge (folder sheet › "Guru dùng thư mục này"). [] = none chosen (the Guru reads as before). */
	public static function folders_for_guru( int $guru_id ): array {
		if ( $guru_id <= 0 ) {
			return array();
		}
		$out = array();
		foreach ( self::folders() as $f ) {
			if ( in_array( $guru_id, (array) $f['gurus'], true ) ) {
				$out[] = (string) $f['n'];
			}
		}
		return $out;
	}

	/** PHASE-0.136 S136-L5 — the folders of a Guru that a CUSTOMER may read: mapped to the Guru (gurus[]) AND access = public. */
	public static function public_folders_for_guru( int $guru_id ): array {
		$map = self::access_map();
		return array_values( array_filter( self::folders_for_guru( $guru_id ), static function ( $n ) use ( $map ) { return 'public' === (string) ( $map[ $n ] ?? '' ); } ) );
	}

	/**
	 * PHASE-0.136 S136-L5 (D136-5/6) — the customer door: short passages from notes of the Guru's PUBLIC folders that match the question.
	 * Never an internal code (no TL-/JE-), never a folder the Guru is not mapped to, never a non-public folder; access is re-read every call (no cache).
	 * Text is the note's own words (library note body / readable copy), ≤ 1200 chars each, ≤ $limit passages. Pure of the caller's role: the caller
	 * (knowledge.search for principal guru_public) decides that this is a customer turn.
	 *
	 * @return array<int,array{title:string,folder:string,text:string}>
	 */
	public static function public_passages( int $guru_id, string $q, int $owner, int $limit = 3 ): array {
		$folders = self::public_folders_for_guru( $guru_id );
		if ( ! $folders || '' === trim( $q ) ) {
			return array();
		}
		$labels = array_column( self::folders(), 'label', 'n' );
		$ctx    = array( 'owner_user_id' => $owner, 'vrole' => 'owner' ); // the public gate is the folder list above, not the role
		$hits   = (array) ( self::search( array( 'q' => mb_substr( $q, 0, 120 ), 'limit' => 20 ), $ctx )['items'] ?? array() );
		$out    = array();
		foreach ( $hits as $h ) {
			$f = substr( (string) ( $h['vault_folder'] ?? '' ), 0, 2 );
			if ( ! in_array( $f, $folders, true ) ) {
				continue;
			}
			$n = self::note_open( (string) $h['id'], $ctx, 0, 1200 );
			if ( ! is_array( $n ) || '' === trim( (string) ( $n['markdown'] ?? '' ) ) ) {
				continue;
			}
			$text  = (string) preg_replace( '/\[\[([A-Za-z]{2}-\d{1,12})(?:\|([^\]]+))?\]\]/u', '$2', (string) $n['markdown'] ); // [[TL-47|tên]] ⇒ tên, [[TL-47]] ⇒ ''
			$text  = (string) preg_replace( '/\b[A-Z]{2}-\d{1,12}(@v\d+)?\b/', '', $text ); // no internal code reaches a customer
			$out[] = array( 'title' => (string) $h['title'], 'folder' => (string) ( $labels[ $f ] ?? $f ), 'text' => trim( mb_substr( $text, 0, 1200 ) ) );
			if ( count( $out ) >= $limit ) {
				break;
			}
		}
		return $out;
	}

	/** The Gurus of this site for the folder sheet (id · name), owner only. */
	/**
	 * PHASE-0.136 S136-D2b — the folder sheet sets a Guru's ROLE (the Guru's own setting, scope.role — the same value Agent Guru shows). Owner only (REST).
	 * @param array $roles {guru_id: role}
	 * @return array<int,string>|WP_Error the roles written
	 */
	public static function set_guru_roles( array $roles ) {
		if ( ! class_exists( 'BizCity_Bot_Config_Repo' ) || ! class_exists( 'BizCity_Guru_Context_Resolver' ) ) {
			return new WP_Error( 'guru_unavailable', 'Chưa đổi được vai Guru trên site này.', array( 'status' => 503, 'hint' => 'Đổi vai trong Agent Guru.', 'help_code' => 'twin_vault' ) );
		}
		$done = array();
		foreach ( array_slice( $roles, 0, 20, true ) as $id => $role ) {
			$id   = (int) $id;
			$role = BizCity_Guru_Context_Resolver::clean_role( $role );
			if ( $id <= 0 ) {
				continue;
			}
			$r = BizCity_Bot_Config_Repo::save( $id, array( 'scope' => array( 'role' => $role ) ) );
			if ( is_wp_error( $r ) ) {
				return $r;
			}
			$done[ $id ] = $role;
		}
		return $done;
	}

	private static function guru_choices(): array {
		if ( ! class_exists( 'BizCity_Knowledge_Database' ) || ! method_exists( 'BizCity_Knowledge_Database', 'instance' ) ) {
			return array();
		}
		$out = array();
		foreach ( (array) BizCity_Knowledge_Database::instance()->get_characters( array( 'limit' => 50 ) ) as $c ) {
			$c = (array) $c;
			if ( (int) ( $c['id'] ?? 0 ) > 0 ) {
				// PHASE-0.136 S136-D2b — + the Guru's role (scope.role, BizCity_Guru_Context_Resolver) for the folder sheet's role picker
				$role  = class_exists( 'BizCity_Guru_Context_Resolver' ) && method_exists( 'BizCity_Guru_Context_Resolver', 'scope' ) ? (string) ( BizCity_Guru_Context_Resolver::scope( (int) $c['id'] )['role'] ?? 'none' ) : 'none';
				$out[] = array( 'id' => (int) $c['id'], 'name' => mb_substr( (string) ( $c['name'] ?? '' ), 0, 80 ), 'role' => $role );
			}
		}
		return $out;
	}

	/** Pure (D134-13): may this role see what is filed under a folder of this access? owner sees all; staff: staff · public; anyone else: public. */
	public static function access_allows( string $access, string $role ): bool {
		if ( 'owner' === $role ) {
			return true;
		}
		return 'staff' === $role ? in_array( $access, array( 'staff', 'public' ), true ) : 'public' === $access;
	}

	/** The role the vault is read as: the REST person (vrole) or the MCP principal's role; unknown ⇒ staff, never owner. */
	public static function role_of( array $ctx ): string {
		$r = (string) ( $ctx['vrole'] ?? $ctx['role'] ?? 'staff' );
		// [2026-10-25 Johnny Chu - Chu Hoàng Anh] PHASE-0.126 R126-SEC M4 — keep an explicit `public` role (REST non-staff).
		if ( 'public' === $r ) {
			return 'public';
		}
		return 'owner' === $r ? 'owner' : 'staff';
	}

	/** Pure: is an item filed in `$vfolder` visible to `$role` given the access map (folder number ⇒ access)? Unfiled / unknown folder ⇒ staff level (as before folders existed). */
	public static function folder_visible( string $vfolder, string $role, array $map ): bool {
		return self::access_allows( (string) ( $map[ substr( $vfolder, 0, 2 ) ] ?? 'staff' ), $role );
	}

	/**
	 * [2026-10-25 Johnny Chu - Chu Hoàng Anh] PHASE-0.126 R126-SEC M4 — folder gate per item id. The `public` view gates the
	 * site-wide library (lib:) only; a src: Journal row is already the caller's own (find_by_ref/owner + Brain_Access), so a
	 * non-staff account keeps its own rows exactly as before.
	 */
	private static function item_visible( string $id, string $vfolder, string $role, array $map ): bool {
		if ( 'public' === $role && 0 === strpos( $id, 'src:' ) ) {
			return true;
		}
		return self::folder_visible( $vfolder, $role, $map );
	}

	/** The access of the folder a Journal row is filed in (meta.folder); 'staff' when unfiled / unknown. Used by brain-access decide(). */
	public static function folder_access_of( array $entry ): string {
		$f = self::clean_folder( $entry['meta']['folder'] ?? '' );
		return '' === $f ? 'staff' : ( self::access_map()[ substr( $f, 0, 2 ) ] ?? 'staff' );
	}

	/** @return array<string,string> folder number ⇒ access */
	public static function access_map(): array {
		$m = array();
		foreach ( self::folders() as $f ) {
			$m[ $f['n'] ] = (string) $f['access'];
		}
		return $m;
	}

	/** Items the principal's role may see (owner: all). */
	private static function gate( array $items, array $ctx ): array {
		$role = self::role_of( $ctx );
		if ( 'owner' === $role ) {
			return $items;
		}
		$map = self::access_map();
		return array_values( array_filter( $items, static function ( $i ) use ( $role, $map ) { return self::item_visible( (string) ( $i['id'] ?? '' ), (string) ( $i['vault_folder'] ?? '' ), $role, $map ); } ) );
	}

	/** Ids (lib: / src:) filed in a folder this role may not see — for the graph, which is built from other sources. */
	private static function hidden_ids( array $ctx ): array {
		$role = self::role_of( $ctx );
		$bad  = array();
		foreach ( self::access_map() as $n => $a ) {
			if ( ! self::access_allows( $a, $role ) ) {
				$bad[] = (string) $n;
			}
		}
		$ids = array();
		// [2026-10-25 Johnny Chu - Chu Hoàng Anh] PHASE-0.126 R126-SEC M4 — the public view also drops unfiled library notes
		// (unfiled = staff level); its own src: rows stay (see item_visible).
		$public = 'public' === $role;
		if ( ! $bad && ! $public ) {
			return $ids;
		}
		$map = self::access_map();
		foreach ( self::lib_files() as $f ) {
			$fn = self::clean_folder( $f['fm']['folder'] ?? '' );
			if ( in_array( substr( $fn, 0, 2 ), $bad, true ) || ( $public && ! self::folder_visible( $fn, $role, $map ) ) ) {
				$ids[ 'lib:' . $f['path'] ] = 1;
			}
		}
		if ( ! $public && class_exists( 'BizCity_Journal_Index' ) ) {
			foreach ( $bad as $n ) {
				for ( $off = 0; $off < 400; $off += 50 ) {
					$res = BizCity_Journal_Index::find( array( 'owner_user_id' => self::owner( $ctx ), 'folder' => $n, 'limit' => 50, 'offset' => $off ) );
					foreach ( (array) $res['items'] as $e ) {
						$ids[ 'src:' . (string) $e['ref_code'] ] = 1;
					}
					if ( count( (array) $res['items'] ) < 50 ) {
						break;
					}
				}
			}
		}
		return $ids;
	}

	// ── library files (the skills namespace of the Vault Store, same paths) ─

	private static function lib_dir(): string {
		return class_exists( 'BizCity_Brain_Vault_Store' ) ? rtrim( BizCity_Brain_Vault_Store::namespace_dir( 'skills' ), '/' ) : '';
	}

	/** @var array|null this request's library scan (a note view reads it for title index + backlinks) */
	private static $files = null;

	/** Every .md of the library (internal "_" folders skipped), ≤ MAX_FILES: [{path, fm, md, mtime}]. Scanned once per request. */
	public static function lib_files(): array {
		if ( null !== self::$files ) {
			return self::$files;
		}
		$base = self::lib_dir();
		$out  = array();
		if ( '' === $base || ! is_dir( $base ) ) {
			return self::$files = $out;
		}
		$it = new RecursiveIteratorIterator( new RecursiveCallbackFilterIterator( new RecursiveDirectoryIterator( $base, FilesystemIterator::SKIP_DOTS ), static function ( $f ) {
			return 0 !== strpos( $f->getFilename(), '_' ) && ( $f->isDir() || 'md' === strtolower( $f->getExtension() ) ) && ! $f->isLink();
		} ) );
		foreach ( $it as $f ) {
			if ( count( $out ) >= self::MAX_FILES ) {
				break;
			}
			$path = '/' . ltrim( str_replace( '\\', '/', substr( $f->getPathname(), strlen( $base ) ) ), '/' );
			if ( '' === self::clean_path( $path ) || $f->getSize() > self::MAX_MD ) {
				continue;
			}
			$split = BizCity_Brain_Vault_Store::split_frontmatter( (string) file_get_contents( $f->getPathname() ) );
			$out[] = array( 'path' => $path, 'fm' => $split['frontmatter'], 'md' => $split['body'], 'mtime' => (int) $f->getMTime() );
		}
		usort( $out, static function ( $a, $b ) { return strcmp( $a['path'], $b['path'] ); } );
		return self::$files = $out;
	}

	private static function lib_read( string $path ): ?array {
		$base = self::lib_dir();
		$abs  = $base . $path;
		if ( '' === $base || ! BizCity_Brain_Vault_Store::inside( $base, $abs ) || ! is_file( $abs ) ) {
			return null;
		}
		$split = BizCity_Brain_Vault_Store::split_frontmatter( (string) file_get_contents( $abs ) );
		return array( 'path' => $path, 'fm' => $split['frontmatter'], 'md' => $split['body'], 'mtime' => (int) filemtime( $abs ) );
	}

	// ── site: tree · list · note · search · write · graph ────────────────

	private static function owner( array $ctx ): int {
		return (int) ( $ctx['owner_user_id'] ?? $ctx['user_id'] ?? 0 );
	}

	/** Journal rows of one type the principal may see (newest first). @return array{items:array,total:int} */
	private static function rows( string $type, array $ctx, int $limit, int $offset = 0, string $q = '' ): array {
		if ( ! class_exists( 'BizCity_Journal_Index' ) || ! class_exists( 'BizCity_Journal_Kinds' ) ) {
			return array( 'items' => array(), 'total' => 0 );
		}
		$kinds = self::KINDS[ $type ] ?? array();
		if ( 'document' === $type ) {
			foreach ( array_keys( (array) BizCity_Journal_Kinds::all()['kinds'] ) as $k ) {
				if ( 'document' === self::row_type( (string) $k, BizCity_Journal_Kinds::layer_of( (string) $k ), true ) ) {
					$kinds[] = (string) $k;
				}
			}
		}
		$filter = array( 'owner_user_id' => self::owner( $ctx ), 'kinds' => $kinds ?: array( '__none__' ), 'limit' => $limit, 'offset' => $offset );
		if ( 'document' === $type ) {
			$filter['has_file'] = 1;
		}
		if ( '' !== $q ) {
			$filter['q'] = $q;
		}
		$res = BizCity_Journal_Index::find( $filter );
		if ( class_exists( 'BizCity_Brain_Access' ) ) {
			$res['items'] = BizCity_Brain_Access::filter( (array) $res['items'], BizCity_Brain_Access::principal( $ctx ), 'meta.read' );
		}
		return $res;
	}

	private static function row_item( array $e, string $type ): array {
		$item = array( 'id' => 'src:' . (string) $e['ref_code'], 'type' => $type, 'title' => (string) ( $e['meta']['title'] ?? '' ) ?: (string) $e['title'], 'source_ref' => (string) ( $e['resource']['source_ref'] ?? $e['ref_code'] ), 'entry_id' => (int) $e['entry_id'], 'vault_folder' => self::clean_folder( $e['meta']['folder'] ?? '' ), 'people' => ! empty( $e['meta']['people'] ) || (int) ( $e['contact_id'] ?? 0 ) > 0, 'updated_at' => (string) ( $e['updated_at'] ?? '' ), 'has_markdown' => ! empty( $e['resource']['representations'] ) );
		// [2026-10-25 12:30 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.137 S137-B2 (D137-1) — every Kho tri thức row carries its four steps + level (tier decides: the pack is not read per row)
		if ( class_exists( 'BizCity_Journal_Pipeline' ) ) {
			$level            = BizCity_Journal_Pipeline::level_of( $e, null );
			$item['level']    = $level;
			$item['pipeline'] = BizCity_Journal_Pipeline::of( $e, $level );
		}
		return $item;
	}

	private static function lib_item( array $f ): array {
		return array( 'id' => 'lib:' . $f['path'], 'type' => self::lib_type( $f['path'] ), 'title' => self::title_of( $f['fm'], $f['md'], $f['path'] ), 'path' => $f['path'], 'source_ref' => (string) ( $f['fm']['source_ref'] ?? '' ), 'updated_at' => gmdate( 'Y-m-d\TH:i:s\Z', $f['mtime'] ), 'folder' => dirname( $f['path'] ), 'vault_folder' => self::clean_folder( $f['fm']['folder'] ?? '' ) );
	}

	/** The six roots with counts and their newest children (≤ 100 rows each + every library file). */
	public static function tree( array $ctx, string $view = 'folders' ): array {
		$lib   = array_map( array( __CLASS__, 'lib_item' ), self::lib_files() );
		$roots = array();
		foreach ( self::TYPES as $t ) {
			$res      = 'skill' === $t ? array( 'items' => array(), 'total' => 0 ) : self::rows( $t, $ctx, 100 ); // skills = the library files (their KN- rows would repeat them)
			$children = array_map( static function ( $e ) use ( $t ) { return self::row_item( $e, $t ); }, (array) $res['items'] );
			$files    = array_values( array_filter( $lib, static function ( $i ) use ( $t ) { return $i['type'] === $t; } ) );
			$all      = array_merge( $files, $children );
			$shown    = self::gate( $all, $ctx );
			$roots[]  = array( 'id' => $t, 'label' => self::LABELS[ $t ], 'type' => $t, 'count' => max( 0, (int) $res['total'] + count( $files ) - ( count( $all ) - count( $shown ) ) ), 'children' => $shown );
		}
		$out = array( 'contract' => self::CONTRACT, 'roots' => $roots );
		if ( 'types' !== $view ) {
			$out['folders']         = self::folder_groups( $roots );
			$role                   = self::role_of( $ctx );
			$out['folders']['list'] = array_values( array_filter( $out['folders']['list'], static function ( $f ) use ( $role ) { return self::access_allows( (string) $f['access'], $role ); } ) );
			if ( 'owner' === $role ) {
				$out['gurus'] = self::guru_choices(); // the folder sheet's "Guru dùng thư mục này" (D134-20)
			}
		}
		return $out;
	}

	/** Group 1 of the tree (D134-10): 00 Home (counts + unfiled) then every digital folder with the ids filed in it. Pure over the roots. */
	public static function folder_groups( array $roots ): array {
		$by      = array();
		$all     = 0;
		$unfiled = 0;
		foreach ( $roots as $r ) {
			foreach ( (array) ( $r['children'] ?? array() ) as $c ) {
				$all++;
				$n = self::clean_folder( $c['vault_folder'] ?? '' );
				if ( '' === $n ) {
					$unfiled++;
				} else {
					$by[ substr( $n, 0, 2 ) ][] = (string) $c['id'];
				}
			}
		}
		$list = array();
		foreach ( self::folders() as $f ) {
			$ids    = $by[ $f['n'] ] ?? array();
			$list[] = $f + array( 'count' => count( $ids ), 'ids' => $ids );
		}
		$counts = array_map( static function ( $r ) { return array( 'type' => $r['type'], 'label' => $r['label'], 'count' => (int) $r['count'] ); }, $roots );
		return array( 'home' => array( 'n' => '00', 'label' => 'Home', 'total' => $all, 'unfiled' => $unfiled, 'counts' => $counts ), 'list' => $list );
	}

	public static function list_notes( array $args, array $ctx ): array {
		$type  = in_array( (string) ( $args['type'] ?? '' ), self::TYPES, true ) ? (string) $args['type'] : 'note';
		$limit = max( 1, min( 100, (int) ( $args['limit'] ?? 30 ) ) );
		$off   = max( 0, (int) ( $args['offset'] ?? 0 ) );
		$items = array();
		if ( in_array( $type, array( 'note', 'skill' ), true ) ) {
			foreach ( self::lib_files() as $f ) {
				if ( self::lib_type( $f['path'] ) === $type ) {
					$items[] = self::lib_item( $f );
				}
			}
		}
		$res   = 'skill' === $type ? array( 'items' => array(), 'total' => 0 ) : self::rows( $type, $ctx, $limit, $off, (string) ( $args['q'] ?? '' ) );
		$items = array_merge( array_slice( $items, $off, $limit ), array_map( static function ( $e ) use ( $type ) { return self::row_item( $e, $type ); }, (array) $res['items'] ) );
		$items = self::gate( $items, $ctx );
		$fold  = self::resolve_folder( $args['folder'] ?? '', self::folders() );
		if ( '' !== $fold ) { // narrows only: a prefix of the note's folder
			$items = array_values( array_filter( $items, static function ( $i ) use ( $fold ) { return 0 === strpos( (string) ( $i['vault_folder'] ?? '' ), $fold ); } ) );
		}
		return array( 'contract' => self::CONTRACT, 'type' => $type, 'items' => array_slice( $items, 0, $limit ), 'total' => (int) $res['total'] + count( $items ) );
	}

	/** One note (twin-vault@1 §1). @return array|WP_Error */
	public static function note( string $id, array $ctx, int $offset = 0, int $limit = 6000 ) {
		$res = self::note_open( $id, $ctx, $offset, $limit );
		if ( is_array( $res ) && ! self::item_visible( (string) ( $res['id'] ?? '' ), (string) ( $res['vault_folder'] ?? '' ), self::role_of( $ctx ), self::access_map() ) ) {
			return new WP_Error( 'vault_folder_denied', 'Thư mục này chỉ dành cho chủ.', array( 'status' => 403, 'hint' => 'Nhờ chủ cấp quyền cho thư mục này.', 'help_code' => 'twin_vault' ) );
		}
		return $res;
	}

	private static function note_open( string $id, array $ctx, int $offset = 0, int $limit = 6000 ) {
		$p = self::parse_id( $id );
		if ( null === $p ) {
			return new WP_Error( 'vault_bad_id', 'Mã ghi chú không hợp lệ (dạng lib:/note/x.md hoặc src:TL-47).', array( 'status' => 400, 'hint' => 'Lấy id từ cây hoặc kết quả tìm.', 'help_code' => 'twin_vault' ) );
		}
		if ( 'lib' === $p[0] ) {
			$f = self::lib_read( $p[1] );
			if ( null === $f ) {
				return new WP_Error( 'vault_not_found', 'Không có ghi chú này trong Kho tri thức.', array( 'status' => 404, 'hint' => 'Tải lại cây.', 'help_code' => 'twin_vault' ) );
			}
			$titles = self::title_index();
			$out    = self::links_of( $f['md'], $f['fm'] );
			return array(
				'contract' => self::CONTRACT, 'id' => 'lib:' . $f['path'], 'type' => self::lib_type( $f['path'] ), 'title' => self::title_of( $f['fm'], $f['md'], $f['path'] ),
				'path' => $f['path'], 'source_ref' => (string) ( $f['fm']['source_ref'] ?? '' ), 'entry_id' => 0, 'frontmatter' => $f['fm'], 'vault_folder' => self::clean_folder( $f['fm']['folder'] ?? '' ),
				'markdown' => mb_substr( $f['md'], max( 0, $offset ), max( 500, min( 6000, $limit ) ) ), 'chars' => mb_strlen( $f['md'] ),
				'next_offset' => $offset + $limit < mb_strlen( $f['md'] ) ? $offset + $limit : null,
				'links' => array( 'out' => array_values( array_filter( array_map( static function ( $t ) use ( $titles ) { return self::resolve_link( $t, $titles ); }, $out['targets'] ) ) ), 'tools' => $out['tools'], 'in' => self::backlinks( 'lib:' . $f['path'], self::title_of( $f['fm'], $f['md'], $f['path'] ) ) ),
				'updated_at' => gmdate( 'Y-m-d\TH:i:s\Z', $f['mtime'] ), 'editable' => 'note' === self::lib_type( $f['path'] ),
				'folder_suggest' => 'note' === self::lib_type( $f['path'] ) ? self::suggest_for_row( array( 'title' => self::title_of( $f['fm'], $f['md'], $f['path'] ), 'text' => mb_substr( $f['md'], 0, 1500 ), 'meta' => array( 'keywords' => array_filter( (array) ( $f['fm']['tags'] ?? array() ), 'is_scalar' ) ), 'vault_folder' => self::clean_folder( $f['fm']['folder'] ?? '' ) ), $ctx ) : null,
			);
		}
		$row = class_exists( 'BizCity_Journal_Index' ) ? BizCity_Journal_Index::find_by_ref( $p[1], self::owner( $ctx ) ) : null;
		if ( $row && class_exists( 'BizCity_Brain_Access' ) && ! BizCity_Brain_Access::decide( BizCity_Brain_Access::principal( $ctx ), $row, 'content.read' )['allow'] ) {
			$row = null;
		}
		if ( ! $row ) {
			return new WP_Error( 'vault_not_found', 'Không có ghi chú này trong Kho tri thức.', array( 'status' => 404, 'help_code' => 'twin_vault' ) );
		}
		$read = BizCity_Brain_Vault_Store::read( $row, 0, $offset, $limit );
		$md   = is_wp_error( $read ) ? '' : (string) $read['markdown'];
		$type = self::row_type( (string) $row['kind'], (string) ( $row['layer'] ?? '' ), (int) ( $row['original']['attachment_id'] ?? 0 ) > 0 ) ?: 'document';
		return array(
			'contract' => self::CONTRACT, 'id' => 'src:' . $row['ref_code'], 'type' => $type, 'title' => (string) ( $row['meta']['title'] ?? '' ) ?: (string) $row['title'],
			'source_ref' => (string) ( $row['resource']['source_ref'] ?? $row['ref_code'] ), 'entry_id' => (int) $row['entry_id'], 'vault_folder' => self::clean_folder( $row['meta']['folder'] ?? '' ),
			'frontmatter' => is_wp_error( $read ) ? array( 'schema' => 'twin-vault@1', 'type' => $type, 'source_ref' => (string) ( $row['resource']['source_ref'] ?? $row['ref_code'] ) ) : (array) $read['frontmatter'],
			'markdown' => $md, 'chars' => is_wp_error( $read ) ? 0 : (int) $read['chars'], 'next_offset' => is_wp_error( $read ) ? null : $read['next_offset'],
			'has_markdown' => ! is_wp_error( $read ), 'about' => (string) ( $row['meta']['about'] ?? $row['summary'] ?? '' ),
			'original_url' => self::original_url( $row, $ctx ), // [2026-10-24 09:38 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.134 (UI gap) — signed, only when this person may fetch the file
			'links' => array( 'out' => array(), 'tools' => array(), 'in' => self::backlinks( 'src:' . $row['ref_code'], '' ) ),
			'updated_at' => (string) ( $row['updated_at'] ?? '' ), 'editable' => false,
			'folder_suggest' => self::suggest_for_row( $row, $ctx ),
		) + ( class_exists( 'BizCity_Journal_Pipeline' ) ? array(
			// [2026-10-25 01:32 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.137 S137-A7/B8 — Hồ sơ tệp: the four steps + "Trợ lý đã dùng tệp này" (a view over the owner's Journal, ≤ 20)
			'pipeline' => BizCity_Journal_Pipeline::of( $row, BizCity_Journal_Pipeline::level_of( $row, null ) ),
			'used_in'  => BizCity_Journal_Pipeline::uses_of( $row, self::owner( $ctx ) ),
		) : array() );
	}

	/** A signed link to the original file of a row, '' unless brain-access@1 grants file.get. */
	private static function original_url( array $row, array $ctx ): string {
		$att = (int) ( $row['original']['attachment_id'] ?? 0 );
		if ( $att <= 0 || ! class_exists( 'BizCity_KG_Private_File_Store' ) || ! method_exists( 'BizCity_KG_Private_File_Store', 'signed_url' ) ) {
			return '';
		}
		if ( class_exists( 'BizCity_Brain_Access' ) && ! BizCity_Brain_Access::decide( BizCity_Brain_Access::principal( $ctx ), $row, 'file.get' )['allow'] ) {
			return '';
		}
		return (string) BizCity_KG_Private_File_Store::signed_url( $att );
	}

	/** fold(title or file name) ⇒ lib id, for [[title]] links. */
	private static function title_index(): array {
		$out = array();
		foreach ( self::lib_files() as $f ) {
			$out[ self::fold( self::title_of( $f['fm'], $f['md'], $f['path'] ) ) ] = 'lib:' . $f['path'];
			$out[ self::fold( preg_replace( '/\.md$/', '', basename( $f['path'] ) ) ) ] = 'lib:' . $f['path'];
		}
		return $out;
	}

	/** Library notes whose links point at $id. */
	private static function backlinks( string $id, string $title ): array {
		$code   = 0 === strpos( $id, 'src:' ) ? substr( $id, 4 ) : '';
		$titles = self::title_index();
		$in     = array();
		foreach ( self::lib_files() as $f ) {
			foreach ( self::links_of( $f['md'], $f['fm'] )['targets'] as $t ) {
				if ( self::resolve_link( $t, $titles ) === $id || ( '' !== $code && 0 === strpos( strtoupper( $t ), $code ) ) ) {
					$in[] = 'lib:' . $f['path'];
					break;
				}
			}
		}
		return array_slice( array_values( array_unique( $in ) ), 0, 50 );
	}

	/** Library (title / frontmatter / body) + Journal (find v2 on find_text), each hit with `why`. */
	public static function search( array $args, array $ctx ): array {
		$q     = trim( (string) ( $args['q'] ?? '' ) );
		$types = array_values( array_intersect( self::TYPES, array_map( 'strval', (array) ( $args['types'] ?? array() ) ) ) ) ?: self::TYPES;
		$limit = max( 1, min( 50, (int) ( $args['limit'] ?? 20 ) ) );
		$fq    = self::fold( $q );
		$hits  = array();
		if ( '' === $fq ) {
			return array( 'contract' => self::CONTRACT, 'items' => array(), 'total' => 0 );
		}
		foreach ( self::lib_files() as $f ) {
			$type = self::lib_type( $f['path'] );
			if ( ! in_array( $type, $types, true ) ) {
				continue;
			}
			$why   = array();
			$score = 0;
			foreach ( array( 'title' => array( self::title_of( $f['fm'], $f['md'], $f['path'] ), 4 ), 'keywords' => array( implode( ' ', array_map( 'strval', array_filter( (array) ( $f['fm']['tags'] ?? array() ), 'is_scalar' ) ) ), 3 ), 'about' => array( $f['md'], 1 ) ) as $part => $pw ) {
				if ( '' !== $pw[0] && false !== strpos( self::fold( $pw[0] ), $fq ) ) {
					$why[]  = $part;
					$score += $pw[1];
				}
			}
			if ( $score > 0 ) {
				$hits[] = self::lib_item( $f ) + array( 'why' => $why, 'score' => $score );
			}
		}
		if ( class_exists( 'BizCity_Journal_Index' ) && array_diff( $types, array( 'skill' ) ) ) {
			$res = BizCity_Journal_Index::find( array( 'owner_user_id' => self::owner( $ctx ), 'terms' => array( $q ), 'match' => 'any', 'limit' => $limit ) );
			$items = class_exists( 'BizCity_Brain_Access' ) ? BizCity_Brain_Access::filter( (array) $res['items'], BizCity_Brain_Access::principal( $ctx ), 'meta.read' ) : (array) $res['items'];
			foreach ( $items as $e ) {
				$t = self::row_type( (string) $e['kind'], (string) ( $e['layer'] ?? '' ), (int) ( $e['original']['attachment_id'] ?? 0 ) > 0 );
				if ( '' !== $t && in_array( $t, $types, true ) ) {
					$hits[] = self::row_item( $e, $t ) + array( 'why' => (array) ( $e['why'] ?? array() ), 'score' => (int) ( $e['score'] ?? 1 ) );
				}
			}
		}
		$hits = self::gate( $hits, $ctx );
		usort( $hits, static function ( $a, $b ) { return $b['score'] <=> $a['score']; } );
		return array( 'contract' => self::CONTRACT, 'items' => array_slice( $hits, 0, $limit ), 'total' => count( $hits ) );
	}

	/**
	 * Save a note (D134-6): Markdown file in a note folder (never /skills or /tools) + exactly one Journal row `common.note` (key vault:<blog>:<path>),
	 * whose source_ref the site writes into the frontmatter. `expected_updated` stale ⇒ vault_conflict (409). @return array|WP_Error {id, source_ref, updated_at, entry_id}
	 */
	public static function write( array $args, array $ctx ) {
		$owner = self::owner( $ctx );
		$title = trim( (string) ( $args['title'] ?? '' ) );
		$md    = (string) ( $args['markdown'] ?? '' );
		if ( $owner <= 0 || '' === $title || '' === trim( $md ) || strlen( $md ) > self::MAX_MD ) {
			return new WP_Error( 'vault_note_invalid', 'Ghi chú cần tiêu đề và nội dung (≤ 200 KB).', array( 'status' => 400, 'hint' => 'Điền tiêu đề và nội dung rồi lưu lại.', 'help_code' => 'twin_vault' ) );
		}
		$path = '' !== (string) ( $args['path'] ?? '' ) ? self::clean_path( (string) $args['path'] ) : ( 0 === strpos( (string) ( $args['id'] ?? '' ), 'lib:' ) ? self::clean_path( substr( (string) $args['id'], 4 ) ) : '/note/' . self::slug( $title ) );
		if ( '' === $path || 'note' !== self::lib_type( $path ) ) {
			return new WP_Error( 'vault_path_refused', 'Chỉ lưu được vào mục Ghi chú (kỹ năng sửa bằng "Sửa từng bước").', array( 'status' => 400, 'hint' => 'Chọn thư mục /note, /nhat-ky hoặc một thư mục ghi chú.', 'help_code' => 'twin_vault' ) );
		}
		$base = self::lib_dir();
		$abs  = $base . $path;
		if ( '' === $base || ! BizCity_Brain_Vault_Store::inside( $base, $abs ) ) {
			return new WP_Error( 'vault_unavailable', 'Kho tri thức chưa sẵn sàng trên site này.', array( 'status' => 503, 'help_code' => 'twin_vault' ) );
		}
		$cur = is_file( $abs ) ? self::lib_read( $path ) : null;
		$exp = (string) ( $args['expected_updated'] ?? '' );
		if ( null !== $cur && '' !== $exp && $exp !== (string) ( $cur['fm']['updated'] ?? '' ) ) {
			return new WP_Error( 'vault_conflict', 'Ghi chú vừa được sửa ở nơi khác.', array( 'status' => 409, 'hint' => 'Tải lại ghi chú rồi sửa lại.', 'help_code' => 'twin_vault' ) );
		}
		$now  = gmdate( 'Y-m-d\TH:i:s\Z' );
		$row  = null;
		$fm_in = is_array( $args['frontmatter'] ?? null ) ? $args['frontmatter'] : array();
		if ( array_key_exists( 'folder', $args ) ) {
			$fm_in['folder'] = self::resolve_folder( $args['folder'], self::folders() ); // PHASE-0.136: a name works too
		}
		$folder          = self::clean_folder( $fm_in['folder'] ?? ( $cur['fm']['folder'] ?? '' ) ); // an edit without a folder keeps the one it had
		$fm_in['folder'] = $folder;
		if ( ! self::folder_visible( $folder, self::role_of( $ctx ), self::access_map() ) ) { // D134-13: no filing into a folder this role may not see
			return new WP_Error( 'vault_folder_denied', 'Thư mục này chỉ dành cho chủ.', array( 'status' => 403, 'hint' => 'Chọn thư mục khác hoặc nhờ chủ cấp quyền.', 'help_code' => 'twin_vault' ) );
		}
		if ( class_exists( 'BizCity_Journal_Index' ) ) {
			$blog  = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
			$entry = array( 'owner_user_id' => $owner, 'kind' => 'common.note', 'title' => mb_substr( $title, 0, 200 ), 'summary' => mb_substr( trim( BizCity_Brain_Vault_Store::split_frontmatter( $md )['body'] ), 0, 600 ), 'doc_date' => gmdate( 'Y-m-d' ), 'source_type' => 'vault', 'source_ref' => mb_substr( 'lib:' . $path, 0, 120 ), 'idempotency_key' => 'vault:' . $blog . ':' . substr( md5( $path ), 0, 24 ), 'origin' => array( 'surface' => 'vault' ), 'card' => array( 'vault_path' => $path ) );
			$row   = BizCity_Journal_Index::add( $entry );
			if ( is_wp_error( $row ) ) {
				return $row;
			}
			if ( null !== $cur && class_exists( 'BizCity_Journal_Entry_Meta' ) ) { // an edit: the row follows the file (title + about through entry-meta; find_text rebuilt)
				$meta = BizCity_Journal_Entry_Meta::merge( (array) ( $row['meta'] ?? array() ), BizCity_Journal_Entry_Meta::normalize( array( 'title' => $title, 'about' => mb_substr( $entry['summary'], 0, 600 ) ) + ( '' !== $folder ? array( 'folder' => $folder ) : array() ) )['meta'], 'person' === (string) ( $ctx['role'] ?? '' ) ? 'owner_edit' : 'site_code' );
				$upd  = BizCity_Journal_Index::file( (int) $row['entry_id'], $owner, array( 'summary' => $entry['summary'], 'meta' => $meta ) );
				$row  = is_wp_error( $upd ) ? $row : $upd;
			}
		}
		$sref = $row ? (string) ( $row['resource']['source_ref'] ?? ( $row['ref_code'] . '@v1' ) ) : '';
		$raw  = self::compose( $fm_in, $title, $md, $sref, (int) ( $cur['fm']['rev'] ?? 0 ) + 1, (string) ( $cur['fm']['created'] ?? $now ), $now );
		if ( ! is_dir( dirname( $abs ) ) && ! ( function_exists( 'wp_mkdir_p' ) ? wp_mkdir_p( dirname( $abs ) ) : @mkdir( dirname( $abs ), 0755, true ) ) ) { // phpcs:ignore
			return new WP_Error( 'vault_write_failed', 'Không ghi được ghi chú.', array( 'status' => 500, 'help_code' => 'twin_vault' ) );
		}
		if ( false === @file_put_contents( $abs, $raw, LOCK_EX ) ) { // phpcs:ignore
			return new WP_Error( 'vault_write_failed', 'Không ghi được ghi chú.', array( 'status' => 500, 'help_code' => 'twin_vault' ) );
		}
		self::$files = null;
		return array( 'contract' => self::CONTRACT, 'id' => 'lib:' . $path, 'source_ref' => $sref, 'entry_id' => $row ? (int) $row['entry_id'] : 0, 'updated_at' => $now, 'created' => null === $cur, 'vault_folder' => $folder );
	}

	/**
	 * [2026-10-25 Johnny Chu - Chu Hoàng Anh] PHASE-0.134 S134-T9 — file ONE item into a digital folder (drag & drop in the tree, "Chuyển thư mục" on a note,
	 * MCP vault.move when the owner asks the assistant). folder '' = take it out of every folder. A library note: frontmatter `folder` rewritten, body untouched;
	 * a Journal row (src:TL-…): metadata.meta.folder through the one meta door (entry-meta@1 set). A folder the caller's role may not see is refused both ways
	 * (into it, and out of it). @return array|WP_Error {id, vault_folder}
	 */
	public static function move( array $args, array $ctx ) {
		$raw    = trim( (string) ( $args['folder'] ?? '' ) );
		$folder = self::resolve_folder( $raw, self::folders() ); // PHASE-0.136: a number or a name ("Chăm sóc khách hàng")
		if ( '' !== $raw && '' === $folder ) {
			return new WP_Error( 'vault_folder_invalid', 'Không có thư mục "' . mb_substr( $raw, 0, 40 ) . '" trong Kho tri thức.', array( 'status' => 400, 'hint' => 'Dùng số (vd 50) hoặc tên thư mục có trong cây; cần thì tạo thư mục mới trước.', 'help_code' => 'twin_vault' ) );
		}
		$role = self::role_of( $ctx );
		$map  = self::access_map();
		// [2026-10-25 Johnny Chu - Chu Hoàng Anh] PHASE-0.126 R126-SEC M4 — id-aware gate (public keeps moving its own src: rows).
		if ( ! self::item_visible( (string) ( $args['id'] ?? '' ), $folder, $role, $map ) ) {
			return new WP_Error( 'vault_folder_denied', 'Thư mục này chỉ dành cho chủ.', array( 'status' => 403, 'hint' => 'Chọn thư mục khác hoặc nhờ chủ cấp quyền.', 'help_code' => 'twin_vault' ) );
		}
		$id = (string) ( $args['id'] ?? '' );
		$p  = self::parse_id( $id );
		if ( null === $p ) {
			return new WP_Error( 'vault_bad_id', 'Mã ghi chú không hợp lệ (dạng lib:/note/x.md hoặc src:TL-47).', array( 'status' => 400, 'hint' => 'Lấy id từ cây hoặc kết quả tìm.', 'help_code' => 'twin_vault' ) );
		}
		$denied = new WP_Error( 'vault_folder_denied', 'Mục này nằm trong thư mục chỉ dành cho chủ.', array( 'status' => 403, 'hint' => 'Nhờ chủ chuyển giúp.', 'help_code' => 'twin_vault' ) );
		if ( 'lib' === $p[0] ) {
			$f = self::lib_read( $p[1] );
			if ( null === $f ) {
				return new WP_Error( 'vault_not_found', 'Không có ghi chú này trong Kho tri thức.', array( 'status' => 404, 'hint' => 'Tải lại cây.', 'help_code' => 'twin_vault' ) );
			}
			if ( 'note' !== self::lib_type( $f['path'] ) ) {
				return new WP_Error( 'vault_path_refused', 'Kỹ năng không xếp vào thư mục (giữ ở mục Kỹ năng).', array( 'status' => 400, 'hint' => 'Chỉ ghi chú mới chuyển thư mục được.', 'help_code' => 'twin_vault' ) );
			}
			if ( ! self::folder_visible( self::clean_folder( $f['fm']['folder'] ?? '' ), $role, $map ) ) {
				return $denied;
			}
			$fm = $f['fm'];
			if ( '' === $folder ) {
				unset( $fm['folder'] );
			} else {
				$fm['folder'] = $folder;
			}
			$out = "---\n" . BizCity_Brain_Vault_Store::emit_yaml( $fm ) . "---\n" . trim( (string) $f['md'] ) . "\n";
			if ( false === @file_put_contents( self::lib_dir() . $f['path'], $out, LOCK_EX ) ) { // phpcs:ignore
				return new WP_Error( 'vault_write_failed', 'Không ghi được ghi chú.', array( 'status' => 500, 'help_code' => 'twin_vault' ) );
			}
			self::$files = null;
			return array( 'contract' => self::CONTRACT, 'id' => 'lib:' . $f['path'], 'vault_folder' => $folder );
		}
		if ( ! class_exists( 'BizCity_Journal_Index' ) || ! class_exists( 'BizCity_Journal_Entry_Meta' ) ) {
			return new WP_Error( 'vault_unavailable', 'Kho tri thức chưa sẵn sàng trên site này.', array( 'status' => 503, 'help_code' => 'twin_vault' ) );
		}
		$row = BizCity_Journal_Index::find_by_ref( $p[1], self::owner( $ctx ) );
		if ( $row && class_exists( 'BizCity_Brain_Access' ) && ! BizCity_Brain_Access::decide( BizCity_Brain_Access::principal( $ctx ), $row, 'write' )['allow'] ) {
			$row = null;
		}
		if ( ! $row ) {
			return new WP_Error( 'vault_not_found', 'Không có ghi chú này trong Kho tri thức.', array( 'status' => 404, 'help_code' => 'twin_vault' ) );
		}
		if ( ! self::item_visible( 'src:' . (string) $row['ref_code'], self::clean_folder( $row['meta']['folder'] ?? '' ), $role, $map ) ) {
			return $denied;
		}
		$res = BizCity_Journal_Entry_Meta::set( self::owner( $ctx ), array( 'entry_id' => (int) $row['entry_id'] ), array( 'folder' => $folder ), array( 'merge' => true, 'source' => 'person' === (string) ( $ctx['role'] ?? '' ) ? 'owner_edit' : 'cell_tool' ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return array( 'contract' => self::CONTRACT, 'id' => 'src:' . (string) $row['ref_code'], 'vault_folder' => $folder );
	}

	/**
	 * The vault graph (twin-vault@1 §3): brain-map@1.2.0 nodes / edges renamed to vault ids + library notes + [[wikilinks]] + skill → @tool.
	 * Filters: types[], since (Y-m-d), focus (id + 2 hops). ≤ MAX_NODES.
	 */
	public static function graph( array $args, array $ctx ): array {
		$nodes = array();
		$edges = array();
		$map   = array();
		if ( class_exists( 'BizCity_Journal_Brain_Map' ) && self::owner( $ctx ) > 0 ) {
			$bm = BizCity_Journal_Brain_Map::for_owner( self::owner( $ctx ) );
			foreach ( (array) ( $bm['nodes'] ?? array() ) as $n ) {
				$k = (string) ( $n['kind'] ?? '' );
				if ( 'doc' === $k ) {
					$t  = self::row_type( (string) ( $n['jkind'] ?? '' ), class_exists( 'BizCity_Journal_Kinds' ) ? BizCity_Journal_Kinds::layer_of( (string) ( $n['jkind'] ?? '' ) ) : 'document', ! empty( $n['has_file'] ) ) ?: 'document';
					$id = 'src:' . (string) $n['ref_code'];
					$nodes[ $id ] = array( 'id' => $id, 'type' => $t, 'label' => (string) $n['label'], 'ref' => (string) $n['ref_code'], 'date' => substr( (string) ( $n['doc_date'] ?: $n['created_at'] ), 0, 10 ) );
				} else {
					$t  = array( 'contact' => 'person', 'job' => 'job', 'sheet' => 'sheet', 'skill' => 'skill' )[ $k ] ?? $k;
					$id = 'job' === $k && '' !== (string) ( $n['ref_code'] ?? '' ) ? 'src:' . $n['ref_code'] : ( 'skill' === $k ? self::skill_id( (string) $n['label'] ) : 'hub:' . (string) $n['id'] );
					$nodes[ $id ] = array( 'id' => $id, 'type' => $t, 'label' => (string) $n['label'], 'ref' => (string) ( $n['ref_code'] ?? '' ), 'date' => '' );
				}
				$map[ (string) $n['id'] ] = $id;
			}
			foreach ( (array) ( $bm['edges'] ?? array() ) as $e ) {
				if ( isset( $map[ $e['from'] ], $map[ $e['to'] ] ) ) {
					$edges[] = array( 'from' => $map[ $e['from'] ], 'to' => $map[ $e['to'] ], 'rel' => (string) $e['type'] );
				}
			}
		}
		$files  = self::lib_files();
		$titles = array();
		foreach ( $files as $f ) {
			$it = self::lib_item( $f );
			$nodes[ $it['id'] ] = array( 'id' => $it['id'], 'type' => $it['type'], 'label' => $it['title'], 'ref' => $it['source_ref'], 'date' => substr( $it['updated_at'], 0, 10 ) );
			$titles[ self::fold( $it['title'] ) ] = $it['id'];
			$titles[ self::fold( preg_replace( '/\.md$/', '', basename( $f['path'] ) ) ) ] = $it['id'];
		}
		foreach ( $files as $f ) {
			$from = 'lib:' . $f['path'];
			$l    = self::links_of( $f['md'], $f['fm'] );
			foreach ( $l['targets'] as $t ) {
				$to = self::resolve_link( $t, $titles );
				if ( '' !== $to && $to !== $from && isset( $nodes[ $to ] ) ) {
					$edges[] = array( 'from' => $from, 'to' => $to, 'rel' => 'wikilink' );
				}
			}
			foreach ( $l['tools'] as $tool ) {
				$tid = 'tool:' . $tool;
				$nodes[ $tid ] = $nodes[ $tid ] ?? array( 'id' => $tid, 'type' => 'tool', 'label' => $tool, 'ref' => '', 'date' => '' );
				$edges[] = array( 'from' => $from, 'to' => $tid, 'rel' => 'uses_tool' );
			}
		}
		foreach ( array_keys( self::hidden_ids( $ctx ) ) as $hid ) { // D134-13: notes in a folder this role may not see leave the graph with their edges
			unset( $nodes[ $hid ] );
		}
		$edges = array_values( array_filter( $edges, static function ( $e ) use ( $nodes ) { return isset( $nodes[ $e['from'] ], $nodes[ $e['to'] ] ); } ) );
		return self::filter_graph( array_values( $nodes ), $edges, $args );
	}

	/** [2026-10-24 09:38 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.134 (UI gap) — a skill hub ⇒ its real file: /skills/<name>/SKILL.md (Agent Skills layout) when it exists, else /skills/<name>.md. */
	private static function skill_id( string $name ): string {
		$name = (string) preg_replace( '/[^a-z0-9-]/', '', strtolower( $name ) );
		$base = self::lib_dir();
		return 'lib:' . ( '' !== $base && is_file( $base . '/skills/' . $name . '/SKILL.md' ) ? '/skills/' . $name . '/SKILL.md' : '/skills/' . $name . '.md' );
	}

	/** Types / since / focus filters, degree, cap. Pure. */
	public static function filter_graph( array $nodes, array $edges, array $args ): array {
		$hubs  = array( 'person', 'topic', 'notebook', 'group', 'tool' );
		$types = array_map( 'strval', (array) ( $args['types'] ?? array() ) );
		$since = 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) ( $args['since'] ?? '' ) ) ? (string) $args['since'] : '';
		$keep  = array();
		foreach ( $nodes as $n ) {
			if ( ( $types && ! in_array( $n['type'], $types, true ) && ! in_array( $n['type'], $hubs, true ) ) || ( '' !== $since && '' !== $n['date'] && $n['date'] < $since ) ) {
				continue;
			}
			$keep[ $n['id'] ] = $n;
		}
		$edges = array_values( array_filter( $edges, static function ( $e ) use ( $keep ) { return isset( $keep[ $e['from'] ], $keep[ $e['to'] ] ); } ) );
		$focus = (string) ( $args['focus'] ?? '' );
		if ( '' !== $focus && isset( $keep[ $focus ] ) ) {
			$near = array( $focus => true );
			for ( $hop = 0; $hop < 2; $hop++ ) {
				$next = $near; // grow from this hop's set only, so one pass never chains further than one step
				foreach ( $edges as $e ) {
					if ( isset( $near[ $e['from'] ] ) || isset( $near[ $e['to'] ] ) ) {
						$next[ $e['from'] ] = $next[ $e['to'] ] = true;
					}
				}
				$near = $next;
			}
			$keep  = array_intersect_key( $keep, $near );
			$edges = array_values( array_filter( $edges, static function ( $e ) use ( $keep ) { return isset( $keep[ $e['from'] ], $keep[ $e['to'] ] ); } ) );
		}
		$deg = array();
		foreach ( $edges as $e ) {
			$deg[ $e['from'] ] = ( $deg[ $e['from'] ] ?? 0 ) + 1;
			$deg[ $e['to'] ]   = ( $deg[ $e['to'] ] ?? 0 ) + 1;
		}
		$list = array();
		foreach ( $keep as $id => $n ) {
			if ( in_array( $n['type'], $hubs, true ) && empty( $deg[ $id ] ) ) {
				continue; // a hub with no edge left is noise
			}
			$list[] = $n + array( 'degree' => (int) ( $deg[ $id ] ?? 0 ) );
		}
		usort( $list, static function ( $a, $b ) { return $b['degree'] <=> $a['degree']; } );
		$truncated = count( $list ) > self::MAX_NODES;
		$list      = array_slice( $list, 0, self::MAX_NODES );
		$ids       = array_flip( array_column( $list, 'id' ) );
		return array( 'contract' => self::CONTRACT, 'nodes' => $list, 'edges' => array_values( array_filter( $edges, static function ( $e ) use ( $ids ) { return isset( $ids[ $e['from'] ], $ids[ $e['to'] ] ); } ) ), 'truncated' => $truncated );
	}

	// ── REST bizcity-vault/v1 ────────────────────────────────────────────

	public static function routes(): void {
		$read  = static function () { return is_user_logged_in(); };
		$write = static function () { return current_user_can( 'edit_posts' ); };
		foreach ( array( '/tree' => 'rest_tree', '/list' => 'rest_list', '/search' => 'rest_search', '/graph' => 'rest_graph' ) as $route => $cb ) {
			register_rest_route( self::NS, $route, array( 'methods' => 'GET', 'callback' => array( __CLASS__, $cb ), 'permission_callback' => $read ) );
		}
		register_rest_route( self::NS, '/move', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'rest_move' ), 'permission_callback' => $write ) );
		register_rest_route( self::NS, '/folder', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'rest_folder_add' ), 'permission_callback' => $write ) );
		register_rest_route( self::NS, '/folder/(?P<n>[0-9]{2})', array( 'methods' => 'PATCH', 'callback' => array( __CLASS__, 'rest_folder_rename' ), 'permission_callback' => $write ) );
		register_rest_route( self::NS, '/note', array(
			array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'rest_note' ), 'permission_callback' => $read ),
			array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'rest_write' ), 'permission_callback' => $write ),
		) );
	}

	private static function rest_ctx(): array {
		// [2026-10-25 Johnny Chu - Chu Hoàng Anh] PHASE-0.126 R126-SEC M4 — `staff` only for CRM staff (handle-inbox cap /
		// team role, incl. editors + bizcity_crm_staff); every other logged-in account reads the public view only.
		$uid      = (int) get_current_user_id();
		$is_staff = class_exists( 'BizCity_CRM_Capabilities' ) && BizCity_CRM_Capabilities::user_can_handle_inbox( $uid );
		if ( ! $is_staff && class_exists( 'BizCity_CRM_Staff_Policy' ) ) {
			$is_staff = BizCity_CRM_Staff_Policy::ROLE_NONE !== BizCity_CRM_Staff_Policy::role( $uid );
		}
		$role = current_user_can( 'manage_options' ) ? 'owner' : 'staff'; // Brain_Access principal of the caller's own rows (unchanged)
		$vrole = 'owner' === $role ? 'owner' : ( $is_staff ? 'staff' : 'public' ); // folder gate of the site-wide library
		return array( 'owner_user_id' => get_current_user_id(), 'role' => $role, 'vrole' => $vrole );
	}

	private static function respond( $res ): WP_REST_Response {
		if ( is_wp_error( $res ) ) {
			$d = (array) $res->get_error_data();
			return new WP_REST_Response( array( 'code' => $res->get_error_code(), 'message' => $res->get_error_message(), 'hint' => (string) ( $d['hint'] ?? '' ), 'help_code' => (string) ( $d['help_code'] ?? 'twin_vault' ) ), (int) ( $d['status'] ?? 400 ) );
		}
		return new WP_REST_Response( $res, 200 );
	}

	public static function rest_tree( WP_REST_Request $r ): WP_REST_Response {
		return self::respond( self::tree( self::rest_ctx(), 'types' === (string) $r->get_param( 'view' ) ? 'types' : 'folders' ) );
	}

	public static function rest_move( WP_REST_Request $r ): WP_REST_Response {
		$b = $r->get_json_params();
		$b = is_array( $b ) ? $b : (array) $r->get_body_params();
		return self::respond( self::move( $b, array( 'role' => 'person' ) + self::rest_ctx() ) );
	}

	public static function rest_folder_add( WP_REST_Request $r ): WP_REST_Response {
		$b = $r->get_json_params();
		$b = is_array( $b ) ? $b : (array) $r->get_body_params();
		return self::respond( self::add_folder( (string) ( $b['label'] ?? '' ), (string) ( $b['icon'] ?? '' ), (string) ( $b['access'] ?? 'staff' ) ) );
	}

	public static function rest_folder_rename( WP_REST_Request $r ): WP_REST_Response {
		$b = $r->get_json_params();
		$b = is_array( $b ) ? $b : (array) $r->get_body_params();
		if ( isset( $b['access'] ) && ! current_user_can( 'manage_options' ) ) { // who may see a folder is the owner's call
			return self::respond( new WP_Error( 'vault_folder_denied', 'Chỉ chủ mới đổi được quyền xem của thư mục.', array( 'status' => 403, 'hint' => 'Nhờ chủ đổi quyền.', 'help_code' => 'twin_vault' ) ) );
		}
		if ( ( isset( $b['gurus'] ) || isset( $b['guru_roles'] ) ) && ! current_user_can( 'manage_options' ) ) { // which Guru reads a folder — and its role — is the owner's call too
			return self::respond( new WP_Error( 'vault_folder_denied', 'Chỉ chủ mới chọn được Guru dùng thư mục.', array( 'status' => 403, 'hint' => 'Nhờ chủ chọn.', 'help_code' => 'twin_vault' ) ) );
		}
		$out = self::update_folder( (string) $r->get_param( 'n' ), array_intersect_key( $b, array_flip( array( 'label', 'icon', 'access', 'rules', 'gurus', 'aliases' ) ) ) );
		if ( ! is_wp_error( $out ) && is_array( $b['guru_roles'] ?? null ) && $b['guru_roles'] ) { // PHASE-0.136 S136-D2b
			$roles = self::set_guru_roles( $b['guru_roles'] );
			if ( is_wp_error( $roles ) ) {
				return self::respond( $roles );
			}
			$out['guru_roles'] = $roles;
		}
		return self::respond( $out );
	}

	public static function rest_list( WP_REST_Request $r ): WP_REST_Response {
		return self::respond( self::list_notes( (array) $r->get_params(), self::rest_ctx() ) );
	}

	public static function rest_search( WP_REST_Request $r ): WP_REST_Response {
		return self::respond( self::search( (array) $r->get_params(), self::rest_ctx() ) );
	}

	public static function rest_graph( WP_REST_Request $r ): WP_REST_Response {
		return self::respond( self::graph( (array) $r->get_params(), self::rest_ctx() ) );
	}

	public static function rest_note( WP_REST_Request $r ): WP_REST_Response {
		return self::respond( self::note( (string) $r->get_param( 'id' ), self::rest_ctx(), (int) $r->get_param( 'offset' ), (int) ( $r->get_param( 'limit' ) ?: 6000 ) ) );
	}

	public static function rest_write( WP_REST_Request $r ): WP_REST_Response {
		$body = $r->get_json_params();
		return self::respond( self::write( is_array( $body ) ? $body : (array) $r->get_body_params(), array( 'role' => 'person' ) + self::rest_ctx() ) );
	}

	// ── MCP vault.list · vault.search · vault.write (vault.get lives in core/mcp, it reads lib: ids through note()) ──

	public static function register_tools(): void {
		if ( ! class_exists( 'BizCity_MCP_Tool_Registry' ) ) {
			return;
		}
		$R      = 'BizCity_MCP_Tool_Registry';
		$common = array( 'mode' => 'notebook', 'roles' => array( 'owner', 'staff' ), 'confirm' => 'never', 'fallback_pack' => null, 'since' => '0.134.0' );
		$types  = array( 'type' => 'string', 'enum' => self::TYPES );

		// @mcp bizcity-mcp-standard@1 tool vault.list
		$R::register( 'vault.list', $common + array(
			'title'          => 'Liệt kê Kho tri thức',
			'description'    => 'Liệt kê các ghi chú của Kho tri thức (Twin Vault) theo loại: document (tài liệu), image (ảnh), note (ghi chú), sheet (sổ), job (việc), skill (kỹ năng). Mỗi mục có id (lib:/… hoặc src:TL-…), tiêu đề, source_ref. Đọc nội dung bằng vault.get {id}. KHÔNG dùng để tìm theo nội dung (dùng vault.search).',
			'input_schema'   => array( 'type' => 'object', 'properties' => array( 'type' => $types, 'q' => array( 'type' => 'string', 'maxLength' => 120 ), 'folder' => array( 'type' => 'string', 'maxLength' => 60, 'description' => 'Thư mục: số (vd 50) hoặc tên ("Chăm sóc khách hàng", "Pháp lý").' ), 'limit' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 100 ), 'offset' => array( 'type' => 'integer', 'minimum' => 0 ) ) ),
			'output_schema'  => $R::envelope_schema( array( 'items' => array( 'type' => 'array' ), 'total' => array( 'type' => 'integer' ) ), array( 'items' ) ),
			'read_only'      => true, 'idempotent' => true, 'required_scope' => 'brain.read', 'scopes' => array( 'brain.read' ),
			'handler'        => array( __CLASS__, 'list_notes' ),
			'llm_alias'      => 'vault_list',
			'brain'          => array( 'tier' => 'L1', 'permission' => 'meta.read', 'crud' => 'read', 'answer_hint' => 'instant' ),
		) );

		// @mcp bizcity-mcp-standard@1 tool vault.search
		$R::register( 'vault.search', $common + array(
			'title'          => 'Tìm trong Kho tri thức',
			'description'    => 'Tìm ghi chú trong Kho tri thức theo chữ (có dấu hay không dấu): tiêu đề, nhãn, nội dung ghi chú, chữ đọc được trên ảnh / tài liệu. Mỗi kết quả có why (khớp ở đâu). Dùng khi hỏi "trong kho có gì về …", "ghi chú nào nói …". KHÔNG dùng để tính số / tổng (dùng twinsheet.*); KHÔNG trả nội dung tệp gốc.',
			'input_schema'   => array( 'type' => 'object', 'required' => array( 'q' ), 'properties' => array( 'q' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 120 ), 'types' => array( 'type' => 'array', 'items' => $types, 'maxItems' => 6 ), 'limit' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 50 ) ) ),
			'output_schema'  => $R::envelope_schema( array( 'items' => array( 'type' => 'array' ), 'total' => array( 'type' => 'integer' ) ), array( 'items' ) ),
			'read_only'      => true, 'idempotent' => true, 'required_scope' => 'brain.read', 'scopes' => array( 'brain.read' ),
			'handler'        => array( __CLASS__, 'search' ),
			'llm_alias'      => 'vault_search',
			'brain'          => array( 'tier' => 'L1', 'permission' => 'meta.read', 'crud' => 'read', 'answer_hint' => 'instant' ),
		) );

		// @mcp bizcity-mcp-standard@1 tool vault.write
		$R::register( 'vault.write', $common + array(
			'title'          => 'Ghi một ghi chú vào Kho tri thức',
			'description'    => 'Lưu MỘT ghi chú Markdown vào Kho tri thức của chủ (mục Ghi chú; không sửa kỹ năng, không sửa bản đọc tài liệu). Liên kết tới tài liệu bằng [[TL-47]]. Site tự mở một dòng Sổ và ghi source_ref. Sửa ghi chú cũ: gửi id (lib:/note/…) + expected_updated. KHÔNG dùng cho "ghi nhớ …" của chủ (đó là notebook_remember vào sổ ngày); KHÔNG sửa kỹ năng, KHÔNG sửa bản đọc tài liệu.',
			'input_schema'   => array( 'type' => 'object', 'required' => array( 'title', 'markdown' ), 'properties' => array(
				'title' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 160, 'description' => 'Tiêu đề ghi chú.' ), 'markdown' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 200000, 'description' => 'Nội dung Markdown; nối tài liệu bằng [[TL-47]].' ),
				'id' => array( 'type' => 'string', 'maxLength' => 200, 'description' => 'Chỉ khi sửa: id ghi chú lib:/note/….' ), 'path' => array( 'type' => 'string', 'maxLength' => 200, 'description' => 'Tuỳ chọn: đường dẫn /note/…/ten.md; bỏ trống ⇒ site đặt theo tiêu đề.' ), 'folder' => array( 'type' => 'string', 'maxLength' => 60, 'description' => 'Thư mục để lưu: số (vd 55) hoặc tên ("Pháp lý", "chăm sóc khách hàng").' ),
				'frontmatter' => array( 'type' => 'object', 'description' => 'Chỉ tags · links · aliases; các khoá khác do site điền.' ), 'expected_updated' => array( 'type' => 'string', 'maxLength' => 30, 'description' => 'Khi sửa: giá trị updated đã đọc; khác ⇒ vault_conflict.' ),
			) ),
			'output_schema'  => $R::envelope_schema( array( 'id' => array( 'type' => 'string' ), 'source_ref' => array( 'type' => 'string' ) ), array( 'id' ) ),
			'read_only'      => false, 'destructive' => false, 'idempotent' => true, 'required_scope' => 'memory.write', 'required_cap' => 'edit_posts', 'scopes' => array( 'memory.write' ),
			'handler'        => array( __CLASS__, 'mcp_write' ),
			'llm_alias'      => 'vault_write',
			'brain'          => array( 'layer' => 'document', 'permission' => 'write', 'crud' => 'write', 'answer_hint' => 'instant' ),
		) );
		// @mcp bizcity-mcp-standard@1 tool vault.move
		$R::register( 'vault.move', $common + array(
			'title'          => 'Xếp một mục vào thư mục của Kho tri thức',
			'description'    => 'Xếp MỘT mục của Kho tri thức (ghi chú lib:/note/… hoặc tài liệu / ảnh src:TL-…) vào một thư mục số (vd "30" hoặc "30/lop-tieng-trung"); folder rỗng = bỏ khỏi thư mục; folder nhận SỐ hoặc TÊN thư mục (truyền nguyên cụm chữ của chủ, site tự khớp). Chỉ dùng khi chủ yêu cầu xếp / chuyển thư mục, vd "lưu tài liệu này vào chăm sóc khách hàng" ⇒ {id: src:TL-… của tệp vừa gửi, folder: "chăm sóc khách hàng"}; "học / lưu hợp đồng này cho pháp lý" ⇒ folder "pháp lý". Lỗi vault_folder_invalid ⇒ hỏi chủ MỘT câu có gợi ý (1 = thư mục gần nhất), không tự tạo thư mục. KHÔNG tự xếp khi chủ không nói; KHÔNG dùng để tạo ghi chú mới (dùng vault.write); không xếp được kỹ năng.',
			'input_schema'   => array( 'type' => 'object', 'required' => array( 'id', 'folder' ), 'properties' => array(
				'id'     => array( 'type' => 'string', 'maxLength' => 200, 'description' => 'Id của mục: lib:/note/x.md hoặc src:TL-47 (lấy từ vault.list / vault.search).' ),
				'folder' => array( 'type' => 'string', 'maxLength' => 60, 'description' => 'Thư mục đích: số ("50") hoặc tên ("Chăm sóc khách hàng", "Pháp lý"); chuỗi rỗng = bỏ khỏi thư mục.' ),
			) ),
			'output_schema'  => $R::envelope_schema( array( 'id' => array( 'type' => 'string' ), 'vault_folder' => array( 'type' => 'string' ) ), array( 'id' ) ),
			'read_only'      => false, 'destructive' => false, 'idempotent' => true, 'required_scope' => 'memory.write', 'required_cap' => 'edit_posts', 'scopes' => array( 'memory.write' ),
			'handler'        => array( __CLASS__, 'mcp_move' ),
			'llm_alias'      => 'vault_move',
			'brain'          => array( 'layer' => 'document', 'permission' => 'write', 'crud' => 'write', 'answer_hint' => 'instant' ),
		) );
	}

	/** MCP door of move: like mcp_write, the model is never a person; the principal's role is kept for the folder gate. */
	public static function mcp_move( array $args, array $ctx ) {
		$ctx['vrole'] = self::role_of( $ctx );
		unset( $ctx['role'] );
		$res = self::move( $args, $ctx );
		if ( ! is_wp_error( $res ) ) {
			return $res;
		}
		$d = (array) $res->get_error_data();
		return new WP_Error( class_exists( 'BizCity_MCP_Error' ) ? BizCity_MCP_Error::QUERY_INVALID : 'query_invalid', $res->get_error_message(), array( 'status' => (int) ( $d['status'] ?? 400 ), 'reason' => $res->get_error_code(), 'hint' => (string) ( $d['hint'] ?? '' ) ) );
	}

	/** MCP door of write: the model is never a person (no owner_edit); service errors ⇒ the MCP catalog error with the reason kept. */
	public static function mcp_write( array $args, array $ctx ) {
		$ctx['vrole'] = self::role_of( $ctx ); // the principal's role, kept for the folder gate
		unset( $ctx['role'] );
		$res = self::write( $args, $ctx );
		if ( ! is_wp_error( $res ) ) {
			return $res;
		}
		$d = (array) $res->get_error_data();
		return new WP_Error( class_exists( 'BizCity_MCP_Error' ) ? BizCity_MCP_Error::QUERY_INVALID : 'query_invalid', $res->get_error_message(), array( 'status' => (int) ( $d['status'] ?? 400 ), 'reason' => $res->get_error_code(), 'hint' => (string) ( $d['hint'] ?? '' ) ) );
	}
}
