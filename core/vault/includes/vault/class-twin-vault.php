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
		array( 'n' => '50', 'label' => 'Chăm sóc khách hàng', 'icon' => '🤝', 'aliases' => array( 'customer support', 'chăm sóc khách hàng', 'khách hàng', 'cskh', 'hỗ trợ khách hàng', 'support', 'khiếu nại' ) ),
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
		if ( '' !== $best ) {
			return $best;
		}
		// [2026-10-26 12:11 AM Johnny Chu - Chu Hoàng Anh] owner "Gọi vault khách hàng cho anh": the spoken name is a PART of the label ("khách hàng" ⇒ "Chăm sóc khách hàng"): the longest run of whole words shared with ONE folder's label / alias (≥ 2 words, or one word of ≥ 4 letters); a tie between two folders answers '' (never guess a folder)
		$qw   = array_values( array_filter( explode( ' ', trim( $q ) ) ) );
		$top  = 0;
		$hits = array();
		foreach ( $folders as $f ) {
			foreach ( array_merge( array( (string) ( $f['label'] ?? '' ) ), (array) ( $f['aliases'] ?? array() ) ) as $name ) {
				$nw  = array_values( array_filter( explode( ' ', self::fold( (string) $name ) ) ) );
				$run = 0;
				for ( $i = 0; $i < count( $qw ); $i++ ) {
					for ( $j = 0; $j < count( $nw ); $j++ ) {
						$k = 0;
						while ( $i + $k < count( $qw ) && $j + $k < count( $nw ) && $qw[ $i + $k ] === $nw[ $j + $k ] ) {
							$k++;
						}
						$run = max( $run, $k );
					}
				}
				$long = $run >= 2 || ( 1 === $run && 1 === count( array_unique( array_intersect( $qw, $nw ) ) ) && mb_strlen( implode( '', array_intersect( $qw, $nw ) ) ) >= 4 );
				if ( $long && $run >= $top ) {
					if ( $run > $top ) {
						$hits = array();
					}
					$top = $run;
					$hits[ (string) $f['n'] ] = true;
				}
			}
		}
		return 1 === count( $hits ) ? (string) key( $hits ) : '';
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
		$sargs  = array( 'q' => mb_substr( $q, 0, 120 ), 'limit' => 20, 'folders' => $folders, 'markdown_only' => true );
		$hits   = (array) ( self::search( $sargs, $ctx )['items'] ?? array() );
		if ( ! $hits && count( self::query_words( $q ) ) > 1 ) { // [2026-10-28 02:25 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.145 S145-G2 — nothing matched every word ⇒ one retry on half the words
			$hits = (array) ( self::search( $sargs + array( 'relaxed' => 1 ), $ctx )['items'] ?? array() );
		} // [2026-10-26 11:48 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.142 R-SCOPE-FOLDER SF-2: the Guru's public folders go INTO the search (before: top 20 of the whole vault, then dropped ⇒ 0 passages when other folders matched first)
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
			$one   = array( 'title' => (string) $h['title'], 'folder' => (string) ( $labels[ $f ] ?? $f ), 'text' => trim( mb_substr( $text, 0, 1200 ) ) );
			$img   = self::public_media_url( $h, $owner );
			if ( '' !== $img ) {
				$one['image_url'] = $img; // S145-G4 — media-out@1 key: the cell collects it, `send_site_media` sends it (only URLs this turn returned)
			}
			$out[] = $one;
			if ( count( $out ) >= $limit ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * [2026-10-28 02:25 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.145 S145-G4/G6 (D145-19, doc 57) — the ORIGINAL photo of a note found in a PUBLIC folder of the Guru, as a 15-minute signed URL.
	 * The folder gate is the caller's (public_passages only passes hits of the Guru's public folders); here: a journal row of the owner, not sensitive (R-JN-11), a raster photo (never SVG /
	 * documents), bytes present. '' otherwise. The URL is minted per call and carries no internal code.
	 */
	public static function public_media_url( array $hit, int $owner ): string {
		$eid = (int) ( $hit['entry_id'] ?? 0 );
		if ( $eid <= 0 || ! class_exists( 'BizCity_Journal_Index' ) || ! class_exists( 'BizCity_KG_Private_File_Store' ) ) {
			return '';
		}
		$e = BizCity_Journal_Index::get( $eid, $owner );
		if ( ! is_array( $e ) || ( class_exists( 'BizCity_Brain_Access' ) && BizCity_Brain_Access::is_sensitive( $e ) ) ) {
			return '';
		}
		$att  = (int) ( $e['original']['attachment_id'] ?? 0 );
		$mime = BizCity_Journal_Index::mime_of( $e );
		if ( $att <= 0 || 'image' !== BizCity_KG_Private_File_Store::view_kind( $mime ) || ! BizCity_KG_Private_File_Store::has_bytes( $att ) ) {
			return '';
		}
		// [2026-10-28 10:30 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.145 golden G — the INLINE signed URL (image content type, no attachment disposition): Facebook refused to fetch the download variant (the 2nd poster never arrived)
		if ( BizCity_KG_Private_File_Store::is_private( $att ) ) {
			$inline = BizCity_KG_Private_File_Store::signed_view_url( $att );
			return '' !== $inline ? $inline : BizCity_KG_Private_File_Store::signed_url( $att );
		}
		return function_exists( 'wp_get_attachment_url' ) ? (string) wp_get_attachment_url( $att ) : '';
	}

	/** [2026-10-26 11:48 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.142 R-SCOPE-FOLDER — clean, unique folder list ("50", "30/lop-a"), max 12 (the Kho has 11 roots). Pure. */
	public static function scope_list( array $folders ): array {
		return array_slice( array_values( array_unique( array_filter( array_map( array( __CLASS__, 'clean_folder' ), $folders ), 'strlen' ) ) ), 0, 12 );
	}

	/** [2026-10-26 11:48 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.142 R-SCOPE-FOLDER — is a row's folder inside the scope? "50" covers "50" and "50/…"; an unfiled row is never inside a scope. Pure. */
	public static function in_folders( string $have, array $scope ): bool {
		if ( '' === $have ) {
			return false;
		}
		foreach ( $scope as $f ) {
			if ( $have === $f || 0 === strpos( $have, $f . '/' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * [2026-10-26 11:48 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.142 R-SCOPE-FOLDER SF-5 — documents per root folder ("Guru biết gì"): library files by their folder + journal rows counted IN the index (one COUNT per folder,
	 * or BizCity_Journal_Database::count_by_folder once it exists). Not capped at 100 like tree(). @return array<string,int> "50" => 12
	 */
	// [2026-10-26 11:58 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.142 D142-17 — `$knowledge` true = only rows that HAVE a markdown reading (what a Guru learned); false = every source filed in the folder (input to learn from).
	public static function folder_counts( int $owner, bool $knowledge = false ): array {
		$out = array();
		foreach ( self::lib_files() as $f ) {
			$n = substr( self::clean_folder( $f['fm']['folder'] ?? '' ), 0, 2 );
			if ( '' !== $n ) {
				$out[ $n ] = ( $out[ $n ] ?? 0 ) + 1;
			}
		}
		if ( $owner <= 0 ) {
			return $out;
		}
		if ( ! $knowledge && class_exists( 'BizCity_Journal_Database' ) && method_exists( 'BizCity_Journal_Database', 'count_by_folder' ) ) {
			foreach ( (array) BizCity_Journal_Database::count_by_folder( $owner ) as $n => $c ) {
				$out[ (string) $n ] = ( $out[ (string) $n ] ?? 0 ) + (int) $c;
			}
			return $out;
		}
		if ( class_exists( 'BizCity_Journal_Index' ) ) {
			foreach ( self::folders() as $f ) {
				$n         = (string) $f['n'];
				$out[ $n ] = ( $out[ $n ] ?? 0 ) + (int) ( BizCity_Journal_Index::find( array( 'owner_user_id' => $owner, 'folder' => $n, 'limit' => 1 ) + ( $knowledge ? array( 'has_markdown' => 1 ) : array() ) )['total'] ?? 0 );
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
		// [2026-10-26 12:11 AM Johnny Chu - Chu Hoàng Anh] owner "Gọi vault khách hàng": a folder named with no type lists every kind filed there — before, only notes came back and the documents / images of the folder were missing
		if ( '' === (string) ( $args['type'] ?? '' ) && '' !== self::resolve_folder( $args['folder'] ?? '', self::folders() ) ) {
			$all   = array();
			$total = 0;
			foreach ( array( 'document', 'image', 'note', 'sheet' ) as $t ) {
				$part  = self::list_notes( array( 'type' => $t ) + $args, $ctx );
				$all   = array_merge( $all, (array) $part['items'] );
				$total += count( (array) $part['items'] );
			}
			$lim = max( 1, min( 100, (int) ( $args['limit'] ?? 30 ) ) );
			return array( 'contract' => self::CONTRACT, 'type' => 'all', 'items' => array_slice( $all, 0, $lim ), 'total' => $total );
		}
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

	/**
	 * [2026-10-28 05:40 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.145 golden G (chủ 17:16: trợ lý "chưa đọc được bảng giá" dù Vault đã có bản đọc) — the Markdown READING (bản đọc) of a journal row as plain text, all pages (≤ 200 000 chars).
	 * The caller has already decided access (document.text.read / vault.get). '' when the row has no reading. Pure of the caller's role.
	 */
	public static function reading_text( string $ref_code, int $owner ): string {
		if ( '' === $ref_code || ! class_exists( 'BizCity_Journal_Index' ) || ! class_exists( 'BizCity_Brain_Vault_Store' ) ) {
			return '';
		}
		$row = BizCity_Journal_Index::find_by_ref( $ref_code, $owner );
		if ( ! is_array( $row ) ) {
			return '';
		}
		$out = '';
		$off = 0;
		for ( $i = 0; $i < 40; $i++ ) { // 40 × 6000 = 240 000 > the 200 000 cap
			$read = BizCity_Brain_Vault_Store::read( $row, 0, $off, 6000 );
			if ( is_wp_error( $read ) ) {
				break;
			}
			$out .= (string) $read['markdown'];
			if ( null === ( $read['next_offset'] ?? null ) || mb_strlen( $out ) >= 200000 ) {
				break;
			}
			$off = (int) $read['next_offset'];
		}
		return $out;
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
			'original' => self::original_info( $row, $ctx ), // [2026-10-28 02:55 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.145 — what the right column draws: kind · name · size · view link · download link
			'links' => array( 'out' => array(), 'tools' => array(), 'in' => self::backlinks( 'src:' . $row['ref_code'], '' ) ),
			'updated_at' => (string) ( $row['updated_at'] ?? '' ), 'editable' => false,
			'folder_suggest' => self::suggest_for_row( $row, $ctx ),
		) + ( class_exists( 'BizCity_Journal_Pipeline' ) ? array(
			// [2026-10-25 01:32 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.137 S137-A7/B8 — Hồ sơ tệp: the four steps + "Trợ lý đã dùng tệp này" (a view over the owner's Journal, ≤ 20)
			'pipeline' => BizCity_Journal_Pipeline::of( $row, BizCity_Journal_Pipeline::level_of( $row, null ) ),
			'used_in'  => BizCity_Journal_Pipeline::uses_of( $row, self::owner( $ctx ) ),
		) : array() );
	}

	/**
	 * [2026-10-28 02:55 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.145 (chủ 28/10: "xem được ảnh, nhạc, video hoặc tải file về ở cột phải … thay vì chỉ lưu metadata") — the original of a row for the
	 * viewer: {kind: image|audio|video|file, mime, name, size, view_url ('' unless it can open in the page), download_url}. null when the row has no file or brain-access refuses file.get
	 * (the same gate as original_url — never a link the person may not fetch). A photo kept on the old PUBLIC media path views from its own media URL.
	 */
	private static function original_info( array $row, array $ctx ): ?array {
		$att = (int) ( $row['original']['attachment_id'] ?? 0 );
		if ( $att <= 0 || ! class_exists( 'BizCity_KG_Private_File_Store' ) ) {
			return null;
		}
		if ( class_exists( 'BizCity_Brain_Access' ) && ! BizCity_Brain_Access::decide( BizCity_Brain_Access::principal( $ctx ), $row, 'file.get' )['allow'] ) {
			return null;
		}
		$mime = strtolower( (string) ( $row['original']['mime'] ?? '' ) );
		if ( '' === $mime && function_exists( 'get_post_mime_type' ) ) {
			$mime = strtolower( (string) get_post_mime_type( $att ) );
		}
		$private  = BizCity_KG_Private_File_Store::is_private( $att );
		$download = $private ? BizCity_KG_Private_File_Store::signed_url( $att ) : ( function_exists( 'wp_get_attachment_url' ) ? (string) wp_get_attachment_url( $att ) : '' );
		if ( '' === $download ) {
			return null;
		}
		$view = $private ? BizCity_KG_Private_File_Store::signed_view_url( $att ) : ( BizCity_KG_Private_File_Store::inline_ok( $mime ) ? $download : '' );
		return array(
			'kind'         => BizCity_KG_Private_File_Store::view_kind( $mime ),
			'mime'         => $mime,
			'name'         => (string) ( $row['original']['file_name'] ?? $row['original']['name'] ?? '' ),
			'size'         => (int) ( $row['original']['size'] ?? 0 ),
			'view_url'     => $view,
			'download_url' => $download,
		);
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
	/**
	 * [2026-10-26 11:48 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.142 R-SCOPE-FOLDER SF-2 — `folders` (array) narrows IN the query (pre-filter, never "search everything then drop"): library files by their folder,
	 * journal rows through BizCity_Journal_Index::find(folders) (one call: the index takes 12), every row re-checked against the set. `folders: []` = narrowed to nothing.
	 */
	public static function search( array $args, array $ctx ): array {
		$scoped = array_key_exists( 'folders', $args ) && is_array( $args['folders'] );
		$fl     = $scoped ? self::scope_list( $args['folders'] ) : array();
		if ( $scoped && ! $fl ) {
			return array( 'contract' => self::CONTRACT, 'items' => array(), 'total' => 0, 'scope' => 'empty' );
		}
		$q      = trim( (string) ( $args['q'] ?? '' ) );
		$types  = array_values( array_intersect( self::TYPES, array_map( 'strval', (array) ( $args['types'] ?? array() ) ) ) ) ?: self::TYPES;
		$limit  = max( 1, min( 50, (int) ( $args['limit'] ?? 20 ) ) );
		$offset = max( 0, min( 1000, (int) ( $args['offset'] ?? 0 ) ) ); // [2026-10-27 09:35 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.145 S145-L2 — page through hits (offset + has_more)
		$fq     = self::fold( $q );
		$hits   = array();
		if ( '' === $fq ) {
			return array( 'contract' => self::CONTRACT, 'items' => array(), 'total' => 0, 'offset' => $offset, 'has_more' => false );
		}
		// [2026-10-27 09:35 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.145 S145-L2 — "cây xanh sân trường" = every word must match (folded), not the whole phrase; one word keeps the old path
		$words = self::query_words( $q );
		$multi = count( $words ) > 1;
		$need  = ! empty( $args['relaxed'] ) ? max( 1, (int) ceil( count( $words ) / 2 ) ) : count( $words ); // S145-G2: one retry may accept half the words, best rows first
		foreach ( self::lib_files() as $f ) {
			$type = self::lib_type( $f['path'] );
			if ( ! in_array( $type, $types, true ) || ( $scoped && ! self::in_folders( self::clean_folder( $f['fm']['folder'] ?? '' ), $fl ) ) ) {
				continue;
			}
			$why   = array();
			$score = 0;
			$seen  = array();
			foreach ( array( 'title' => array( self::title_of( $f['fm'], $f['md'], $f['path'] ), 4 ), 'keywords' => array( implode( ' ', array_map( 'strval', array_filter( (array) ( $f['fm']['tags'] ?? array() ), 'is_scalar' ) ) ), 3 ), 'about' => array( $f['md'], 1 ) ) as $part => $pw ) {
				if ( '' === $pw[0] ) {
					continue;
				}
				$text = self::fold( $pw[0] );
				if ( ! $multi ) {
					if ( false !== strpos( $text, $fq ) ) {
						$why[]  = $part;
						$score += $pw[1];
					}
					continue;
				}
				$got = array_values( array_filter( $words, static function ( $w ) use ( $text ) { return false !== strpos( $text, $w ); } ) );
				if ( $got ) {
					$why[]  = $part;
					$score += max( 1, (int) round( $pw[1] * count( $got ) / count( $words ) ) );
					$seen   = array_merge( $seen, $got );
				}
			}
			if ( $multi && count( array_unique( $seen ) ) < $need ) {
				continue; // match all (S145-G2 relaxed: at least half the words): a word found nowhere in the note ⇒ not a hit
			}
			if ( $score > 0 ) {
				$hits[] = self::lib_item( $f ) + array( 'why' => $why, 'score' => $score );
			}
		}
		$more_rows = false;
		if ( class_exists( 'BizCity_Journal_Index' ) && array_diff( $types, array( 'skill' ) ) ) {
			$rows = array();
			$want = max( $limit, min( 50, $offset + $limit + 1 ) ); // the index serves ≤ 50 per call; one more than the page tells has_more
			foreach ( $scoped ? array_chunk( $fl, 12 ) : array( array() ) as $chunk ) { // 12 = the index cap (SF-3); scope_list already caps at 12
				$filter = $multi
					? array( 'owner_user_id' => self::owner( $ctx ), 'terms' => $words, 'match' => empty( $args['relaxed'] ) ? 'all' : 'any', 'limit' => $want ) // PHASE-0.145 S145-L2: each word a term, all must match
					: array( 'owner_user_id' => self::owner( $ctx ), 'terms' => array( $q ), 'match' => 'any', 'limit' => $want );
				if ( ! empty( $args['markdown_only'] ) ) {
					$filter['has_markdown'] = 1; // [2026-10-26 11:58 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.142 D142-17: a Guru reads the markdown knowledge (bản đọc), never a raw source file
				}
				if ( $chunk ) {
					$filter['folders'] = $chunk;
				}
				$found = BizCity_Journal_Index::find( $filter );
				if ( (int) ( $found['total'] ?? 0 ) > count( (array) ( $found['items'] ?? array() ) ) ) {
					$more_rows = true; // PHASE-0.145 S145-L2 — the index holds more than this call returned
				}
				foreach ( (array) ( $found['items'] ?? array() ) as $e ) {
					$rows[ (int) ( $e['entry_id'] ?? 0 ) ?: count( $rows ) + 1000000 ] = $e;
				}
			}
			if ( $scoped ) {
				$rows = array_filter( $rows, static function ( $e ) use ( $fl ) { return self::in_folders( self::clean_folder( $e['meta']['folder'] ?? '' ), $fl ); } );
			}
			$items = class_exists( 'BizCity_Brain_Access' ) ? BizCity_Brain_Access::filter( array_values( $rows ), BizCity_Brain_Access::principal( $ctx ), 'meta.read' ) : array_values( $rows );
			foreach ( $items as $e ) {
				$t = self::row_type( (string) $e['kind'], (string) ( $e['layer'] ?? '' ), (int) ( $e['original']['attachment_id'] ?? 0 ) > 0 );
				if ( '' !== $t && in_array( $t, $types, true ) ) {
					$hits[] = self::row_item( $e, $t ) + array( 'why' => (array) ( $e['why'] ?? array() ), 'score' => (int) ( $e['score'] ?? 1 ) );
				}
			}
		}
		$hits = self::gate( $hits, $ctx );
		usort( $hits, static function ( $a, $b ) { return $b['score'] <=> $a['score']; } );
		// [2026-10-27 09:35 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.145 S145-L2 — a page (offset) + has_more; `total` = hits seen after access (a floor when has_more comes from the index)
		return array( 'contract' => self::CONTRACT, 'items' => array_slice( $hits, $offset, $limit ), 'total' => count( $hits ), 'offset' => $offset, 'has_more' => count( $hits ) > $offset + $limit || $more_rows, 'words' => $multi ? $words : array() );
	}

	/**
	 * [2026-10-27 09:35 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.145 S145-L2 — the words of a query, folded (BizCity_Journal_Entry_Meta::fold), unique, ≤ 8. One word ⇒ [word]. Pure.
	 * "Cây  XANH, sân trường" ⇒ ["cay", "xanh", "san", "truong"].
	 */
	public static function query_words( string $q ): array {
		$out = array();
		foreach ( explode( ' ', self::fold( $q ) ) as $w ) {
			if ( '' !== $w && ! in_array( $w, $out, true ) ) {
				$out[] = mb_substr( $w, 0, 60 );
			}
		}
		// [2026-10-28 02:25 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.145 S145-G2 — the filler words of a spoken request (gửi, cho anh, ảnh, chương trình…) never narrow a search
		if ( count( $out ) > 1 && class_exists( 'BizCity_Journal_Entry_Meta' ) && method_exists( 'BizCity_Journal_Entry_Meta', 'drop_filler' ) ) {
			$out = BizCity_Journal_Entry_Meta::drop_filler( $out );
		}
		return array_slice( $out, 0, 8 );
	}

	/**
	 * [2026-10-27 09:37 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.145 S145-L5 — a tag list as entry-meta keeps keywords: trimmed, ≤ 40 chars, one per fold key (first spelling kept), ≤ 20, order kept. Pure.
	 */
	public static function note_tags( $list ): array {
		$out  = array();
		$keys = array();
		foreach ( (array) $list as $t ) {
			$t = is_scalar( $t ) ? mb_substr( trim( (string) preg_replace( '/\s+/u', ' ', (string) $t ) ), 0, 40 ) : '';
			$k = self::fold( $t );
			if ( '' !== $t && '' !== $k && ! isset( $keys[ $k ] ) ) {
				$keys[ $k ] = 1;
				$out[]      = $t;
			}
		}
		return array_slice( $out, 0, 20 );
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
		// [2026-10-27 06:45 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.143 S143-L5 — a notebook pool is written by vault.append only: a plain write would drop its entries[]
		if ( null !== $cur && self::POOL_KIND === (string) ( $cur['fm']['kind'] ?? '' ) ) {
			return new WP_Error( 'vault_pool_use_append', 'File này do notebook nuôi: thêm mục bằng "Lưu vào kho tri thức".', array( 'status' => 409, 'hint' => 'Lưu thêm từ notebook hoặc tạo ghi chú mới.', 'help_code' => 'twin_vault' ) );
		}
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
			// [2026-10-27 09:37 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.145 S145-L5 (H4) — `tags` of a note = a projection of the row's meta.keywords: a changed list goes back to the Journal
			// through the one meta door (Entry_Meta::set, the same call REST journal/{id}/meta makes: owner_edit + expected_rev for a person; site_code for the model's door)
			if ( array_key_exists( 'tags', $fm_in ) && class_exists( 'BizCity_Journal_Entry_Meta' ) ) {
				$tags = self::note_tags( $fm_in['tags'] );
				if ( $tags !== self::note_tags( $row['meta']['keywords'] ?? array() ) ) {
					$kw = BizCity_Journal_Entry_Meta::set( $owner, array( 'entry_id' => (int) $row['entry_id'] ), array( 'keywords' => $tags ), array(
						'merge'        => true,
						'expected_rev' => (int) ( $row['meta']['rev'] ?? 0 ),
						'source'       => 'person' === (string) ( $ctx['role'] ?? '' ) ? 'owner_edit' : 'site_code',
					) );
					$row = is_wp_error( $kw ) ? $row : $kw; // a refused keyword write never loses the note itself
				}
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

	// ── [2026-10-27 06:45 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.143 S143-L5 (D143-8…12) — "Lưu vào kho tri thức": one pool .md per notebook, one `entries[]` property per save ──

	const POOL_KIND        = 'notebook_pool';
	const POOL_MAX_ENTRIES = 200;

	/** "/60/dd-noi-khoa.md" (part 2 ⇒ "-2"): the folder NUMBER + the notebook's name. '' when the folder is not a folder shape. Pure. */
	public static function pool_path( string $folder, string $title, int $part = 1 ): string {
		$n = self::clean_folder( $folder );
		if ( '' === $n ) {
			return '';
		}
		$slug = (string) preg_replace( '/\.md$/', '', self::slug( $title ) );
		return self::clean_path( '/' . $n . '/' . $slug . ( $part > 1 ? '-' . $part : '' ) . '.md' );
	}

	/** One id per save, from the idempotency key (the same save never gets two ids). Pure. */
	public static function pool_entry_id( string $idem, int $ts ): string {
		return 'e-' . gmdate( 'Ymd-Hi', $ts ) . '-' . substr( md5( $idem ), 0, 4 );
	}

	/** A pool file's frontmatter: scalar keys by emit_yaml, `entries` as one JSON object per line (valid YAML flow map; the store's subset has no list-of-maps). Pure. */
	public static function pool_emit( array $head, array $entries, array $tail ): string {
		$out = BizCity_Brain_Vault_Store::emit_yaml( $head ) . "entries:\n";
		foreach ( $entries as $e ) {
			$out .= '  - ' . json_encode( $e, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "\n";
		}
		return $out . BizCity_Brain_Vault_Store::emit_yaml( $tail );
	}

	/** Read a pool file: {fm (without entries), entries (decoded), body}. Pure. */
	public static function pool_parse( string $raw ): array {
		$s       = BizCity_Brain_Vault_Store::split_frontmatter( $raw );
		$fm      = $s['frontmatter'];
		$entries = array();
		foreach ( (array) ( $fm['entries'] ?? array() ) as $it ) {
			$j = is_string( $it ) ? json_decode( $it, true ) : ( is_array( $it ) ? $it : null );
			if ( is_array( $j ) && '' !== (string) ( $j['id'] ?? '' ) ) {
				$entries[] = $j;
			}
		}
		unset( $fm['entries'] );
		return array( 'fm' => $fm, 'entries' => $entries, 'body' => (string) $s['body'] );
	}

	/** Frontmatter of a pool from its scalar keys + entries, in the fixed order (head · entries · tail). Pure. */
	public static function pool_rewrite( array $fm, array $entries ): string {
		$head = array();
		foreach ( array( 'schema', 'type', 'kind', 'title', 'notebook_ref', 'folder', 'tags' ) as $k ) {
			if ( isset( $fm[ $k ] ) ) {
				$head[ $k ] = $fm[ $k ];
			}
		}
		$tail = array();
		foreach ( array( 'source_ref', 'rev', 'created', 'updated' ) as $k ) {
			if ( isset( $fm[ $k ] ) ) {
				$tail[ $k ] = $fm[ $k ];
			}
		}
		return self::pool_emit( $head, $entries, $tail );
	}

	/** "NB-17" moved to folder X ⇒ the notebook's saved pool setting says X too. */
	private static function pool_follow_folder( string $ref, string $folder ): void {
		if ( 1 !== preg_match( '/^NB-(\d+)$/', $ref, $m ) || ! class_exists( 'BizCity_KG_Notebook_Service' ) ) {
			return;
		}
		$svc = BizCity_KG_Notebook_Service::instance();
		$nb  = $svc->get( (int) $m[1] );
		if ( is_array( $nb ) ) {
			$st                         = (array) ( $nb['settings'] ?? array() );
			$st['vault_pool']           = (array) ( $st['vault_pool'] ?? array() );
			$st['vault_pool']['folder'] = $folder;
			$svc->update( (int) $m[1], array( 'settings' => $st ) );
		}
	}

	/** The body block of one save: heading with the anchor, the answer as the assistant wrote it (tokens kept), the sources, the stamp. Pure. */
	public static function pool_section( array $e, string $answer_md ): string {
		$q   = trim( (string) preg_replace( '/\s+/u', ' ', (string) $e['q'] ) );
		$out = '## ' . mb_substr( $q, 0, 200 ) . ' {#' . $e['id'] . "}\n" . trim( $answer_md ) . "\n";
		if ( ! empty( $e['cites'] ) ) {
			$out .= "\n**Nguồn:** " . implode( ' ', array_map( static function ( $c ) { return '[' . $c . ']'; }, (array) $e['cites'] ) ) . "\n";
		}
		return $out . "\n_Lưu " . gmdate( 'd/m/Y H:i', (int) strtotime( (string) $e['saved_at'] ) ) . ' UTC' . ( '' !== (string) ( $e['source_ref'] ?? '' ) ? ' · từ ' . $e['source_ref'] : '' ) . "_\n";
	}

	/**
	 * vault-append@1 — add ONE save to the notebook's pool file (creates it on the first save, in the folder the person chose).
	 * Atomic under a file lock; the same message saved twice is one entry (idempotency). One Journal row `learn` per save (R-JOURNAL), the file's own
	 * `common.note` row comes from write() on creation only. @return array|WP_Error {id, path, entry_id, anchor, journal_entry_id, entries_count, created_file, duplicated}
	 */
	public static function append( array $args, array $ctx ) {
		$owner = self::owner( $ctx );
		$nb_id = (int) ( $args['notebook_id'] ?? 0 );
		$q     = trim( (string) ( $args['q'] ?? '' ) );
		$ans   = trim( (string) ( $args['answer_md'] ?? '' ) );
		if ( $owner <= 0 || $nb_id <= 0 || '' === $q || '' === $ans || strlen( $ans ) > 100000 ) {
			return new WP_Error( 'vault_append_invalid', 'Cần notebook, câu hỏi và câu trả lời để lưu (≤ 100 KB).', array( 'status' => 400, 'hint' => 'Chọn một câu trả lời của Twin rồi lưu lại.', 'help_code' => 'twin_vault' ) );
		}
		if ( ! class_exists( 'BizCity_KG_Notebook_Service' ) ) {
			return new WP_Error( 'vault_unavailable', 'Notebook chưa sẵn sàng trên site này.', array( 'status' => 503, 'help_code' => 'twin_vault' ) );
		}
		$nb = BizCity_KG_Notebook_Service::instance()->get( $nb_id );
		if ( ! is_array( $nb ) || ( class_exists( 'BizCity_KG_Access' ) && ! BizCity_KG_Access::can_read_notebook( $nb_id, $owner ) ) ) {
			return new WP_Error( 'vault_not_found', 'Không có notebook này.', array( 'status' => 404, 'help_code' => 'twin_vault' ) );
		}
		$cites = array();
		foreach ( array_slice( (array) ( $args['cites'] ?? array() ), 0, 20 ) as $c ) {
			$c = trim( (string) $c, " []\t" );
			if ( 1 === preg_match( '#^nb:\d+/p\d+$#', $c ) ) {
				$cites[ $c ] = $c;
			}
		}
		$cites    = array_values( $cites );
		$blog     = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
		$msg      = preg_replace( '/[^A-Za-z0-9_.:-]/', '', (string) ( $args['message_id'] ?? '' ) );
		$idem     = 'vault-append:' . $blog . ':' . $nb_id . ':' . ( '' !== $msg ? $msg : substr( md5( $q . "\n" . $ans ), 0, 16 ) );
		$settings = (array) ( $nb['settings'] ?? array() );
		$pool     = (array) ( $settings['vault_pool'] ?? array() );
		$base     = self::lib_dir();
		if ( '' !== $base && ! is_dir( $base ) ) { // a site that never saved a library note has no folder yet
			function_exists( 'wp_mkdir_p' ) ? wp_mkdir_p( $base ) : @mkdir( $base, 0755, true ); // phpcs:ignore
		}
		if ( '' === $base || ! is_dir( $base ) ) {
			return new WP_Error( 'vault_unavailable', 'Kho tri thức chưa sẵn sàng trên site này.', array( 'status' => 503, 'help_code' => 'twin_vault' ) );
		}

		// the pool: the saved one, else the folder the person chose now (the FE opens the folder sheet on `vault_pool_folder_needed`)
		$path   = self::clean_path( (string) ( $pool['path'] ?? '' ) );
		$folder = '';
		if ( '' === $path ) {
			$folder = self::resolve_folder( (string) ( $args['folder'] ?? '' ), self::folders() );
			if ( '' === $folder ) {
				return new WP_Error( 'vault_pool_folder_needed', 'Chọn thư mục Kho tri thức cho notebook này.', array( 'status' => 409, 'hint' => 'Chọn một thư mục (vd 60 Sản phẩm & dịch vụ) rồi lưu lại.', 'help_code' => 'twin_vault', 'suggest' => array_column( self::folders(), 'n' ) ) );
			}
			for ( $part = 1; $part <= 9; $part++ ) { // an unrelated file with the same name is never overwritten
				$cand = self::pool_path( $folder, (string) $nb['name'], $part );
				$cur  = '' !== $cand && is_file( $base . $cand ) ? self::pool_parse( (string) file_get_contents( $base . $cand ) ) : null;
				if ( '' !== $cand && ( null === $cur || 'NB-' . $nb_id === (string) ( $cur['fm']['notebook_ref'] ?? '' ) ) ) {
					$path = $cand;
					break;
				}
			}
		} else {
			$folder = self::clean_folder( (string) ( $pool['folder'] ?? explode( '/', ltrim( $path, '/' ) )[0] ) );
		}
		if ( '' === $path ) {
			return new WP_Error( 'vault_path_refused', 'Không đặt được tên file cho notebook này.', array( 'status' => 400, 'help_code' => 'twin_vault' ) );
		}
		if ( ! self::folder_visible( $folder, self::role_of( $ctx ), self::access_map() ) ) {
			return new WP_Error( 'vault_folder_denied', 'Thư mục này chỉ dành cho chủ.', array( 'status' => 403, 'hint' => 'Chọn thư mục khác hoặc nhờ chủ cấp quyền.', 'help_code' => 'twin_vault' ) );
		}
		$abs = $base . $path;
		if ( ! BizCity_Brain_Vault_Store::inside( $base, $abs ) ) {
			return new WP_Error( 'vault_path_refused', 'Đường dẫn không hợp lệ.', array( 'status' => 400, 'help_code' => 'twin_vault' ) );
		}

		// a file lock per notebook: two saves at once never drop one
		$lockdir = $base . '/_locks';
		if ( ! is_dir( $lockdir ) ) {
			@mkdir( $lockdir, 0755, true ); // phpcs:ignore
		}
		$lock = @fopen( $lockdir . '/nbpool-' . $nb_id . '.lock', 'c' ); // phpcs:ignore
		if ( $lock ) {
			flock( $lock, LOCK_EX );
		}
		try {
			$created = ! is_file( $abs );
			if ( $created ) { // first save: the file and its `common.note` row come from the one write door
				$w = self::write( array( 'title' => (string) $nb['name'], 'markdown' => '> Tri thức đã lưu từ notebook **' . (string) $nb['name'] . '** (NB-' . $nb_id . "). Mỗi mục là một câu hỏi đã được Twin trả lời có nguồn.\n", 'path' => $path, 'folder' => $folder ), $ctx );
				if ( is_wp_error( $w ) ) {
					return $w;
				}
			}
			$cur = self::pool_parse( (string) file_get_contents( $abs ) );
			if ( ! $created && self::POOL_KIND !== (string) ( $cur['fm']['kind'] ?? '' ) && 'NB-' . $nb_id !== (string) ( $cur['fm']['notebook_ref'] ?? '' ) && ! empty( $cur['fm'] ) && ! empty( $pool['path'] ) ) {
				return new WP_Error( 'vault_pool_conflict', 'File pool bị đổi ở nơi khác.', array( 'status' => 409, 'hint' => 'Mở Vault trong notebook và chọn lại thư mục.', 'help_code' => 'twin_vault' ) );
			}
			$idem12 = substr( md5( $idem ), 0, 12 );
			foreach ( $cur['entries'] as $e ) { // the same save twice ⇒ the first entry
				if ( $idem12 === (string) ( $e['idem'] ?? '' ) ) {
					return array( 'contract' => 'vault-append@1', 'id' => 'lib:' . $path, 'path' => 'lib:' . $path, 'entry_id' => (string) $e['id'], 'anchor' => '#' . $e['id'], 'journal_entry_id' => 0, 'entries_count' => count( $cur['entries'] ), 'created_file' => false, 'duplicated' => true );
				}
			}
			if ( count( $cur['entries'] ) >= self::POOL_MAX_ENTRIES ) { // a full file rolls over to "-2" (the setting follows)
				$next = 2;
				if ( 1 === preg_match( '#-(\d)\.md$#', $path, $m ) ) {
					$next = (int) $m[1] + 1;
				}
				if ( $next > 9 ) {
					return new WP_Error( 'vault_pool_full', 'File pool của notebook đã đầy.', array( 'status' => 409, 'help_code' => 'twin_vault' ) );
				}
				$settings['vault_pool'] = array( 'path' => self::pool_path( $folder, (string) $nb['name'], $next ), 'folder' => $folder, 'chosen_at' => gmdate( 'c' ), 'entries_count' => 0 );
				BizCity_KG_Notebook_Service::instance()->update( $nb_id, array( 'settings' => $settings ) );
				return self::append( $args, $ctx );
			}

			$now   = time();
			$stamp = gmdate( 'Y-m-d\TH:i:s\Z', $now );
			$entry = array( 'id' => self::pool_entry_id( $idem, $now ), 'q' => mb_substr( $q, 0, 300 ), 'saved_at' => $stamp, 'by' => $owner, 'source_ref' => '' !== $msg ? 'msg:' . $msg : '', 'anchor' => '', 'cites' => $cites, 'idem' => $idem12 );
			$entry['anchor'] = '#' . $entry['id'];
			$entries         = array_merge( $cur['entries'], array( $entry ) );
			$fm              = $cur['fm'];
			$tags            = array_values( array_unique( array_merge( array( 'notebook' ), (array) ( $fm['tags'] ?? array() ), array( preg_replace( '/\.md$/', '', self::slug( (string) $nb['name'] ) ) ) ) ) );
			$head            = array( 'schema' => 'twin-vault@1', 'type' => 'note', 'kind' => self::POOL_KIND, 'title' => (string) ( $fm['title'] ?? $nb['name'] ), 'notebook_ref' => 'NB-' . $nb_id ) + ( '' !== $folder ? array( 'folder' => $folder ) : array() ) + array( 'tags' => array_slice( $tags, 0, 20 ) );
			$tail            = array( 'source_ref' => (string) ( $fm['source_ref'] ?? '' ), 'rev' => (int) ( $fm['rev'] ?? 0 ) + 1, 'created' => (string) ( $fm['created'] ?? $stamp ), 'updated' => $stamp );
			$body            = rtrim( $cur['body'] ) . "\n\n" . self::pool_section( $entry, $ans );
			$raw             = "---\n" . self::pool_emit( $head, $entries, $tail ) . "---\n" . ltrim( $body ) . "\n";
			if ( strlen( $raw ) > self::MAX_MD * 4 || false === @file_put_contents( $abs, $raw, LOCK_EX ) ) { // phpcs:ignore
				return new WP_Error( 'vault_write_failed', 'Không ghi được vào file pool.', array( 'status' => 500, 'help_code' => 'twin_vault' ) );
			}
			self::$files = null;

			$jid = 0;
			if ( class_exists( 'BizCity_Journal_Index' ) ) { // exactly one `learn` row per save (R-JOURNAL)
				$row = BizCity_Journal_Index::add( array( 'owner_user_id' => $owner, 'kind' => 'learn', 'title' => mb_substr( $q, 0, 200 ), 'summary' => mb_substr( $ans, 0, 600 ), 'doc_date' => gmdate( 'Y-m-d' ), 'source_type' => 'vault', 'source_ref' => mb_substr( 'lib:' . $path . '#' . $entry['id'], 0, 120 ), 'idempotency_key' => mb_substr( $idem, 0, 120 ), 'origin' => array( 'surface' => 'notebook' ), 'card' => array( 'vault_path' => $path, 'anchor' => $entry['anchor'], 'notebook_id' => $nb_id, 'cites' => $cites ) ) );
				$jid = is_wp_error( $row ) ? 0 : (int) ( $row['entry_id'] ?? 0 );
			}
			$settings['vault_pool'] = array( 'path' => $path, 'folder' => $folder, 'chosen_at' => (string) ( $pool['chosen_at'] ?? gmdate( 'c' ) ), 'entries_count' => count( $entries ) );
			BizCity_KG_Notebook_Service::instance()->update( $nb_id, array( 'settings' => $settings ) );
			do_action( 'bizcity_vault_appended', $path, $entry, $nb_id );
			do_action( 'bizcity_kg_notebook_notes_pinned', $nb_id, $owner, $jid ); // the skeleton keeps preferring saved answers
			return array( 'contract' => 'vault-append@1', 'id' => 'lib:' . $path, 'path' => 'lib:' . $path, 'entry_id' => $entry['id'], 'anchor' => $entry['anchor'], 'journal_entry_id' => $jid, 'entries_count' => count( $entries ), 'created_file' => $created, 'duplicated' => false, 'vault_folder' => $folder );
		} finally {
			if ( $lock ) {
				flock( $lock, LOCK_UN );
				fclose( $lock );
			}
		}
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
			if ( self::POOL_KIND === (string) ( $fm['kind'] ?? '' ) ) { // PHASE-0.143: a pool keeps its entries[] (one JSON per line) and its notebook follows the new folder
				$pp  = self::pool_parse( (string) file_get_contents( self::lib_dir() . $f['path'] ) );
				$out = "---\n" . self::pool_rewrite( $fm, $pp['entries'] ) . "---\n" . trim( (string) $f['md'] ) . "\n";
				self::pool_follow_folder( (string) ( $fm['notebook_ref'] ?? '' ), $folder );
			}
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
			// [2026-10-27 09:30 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.143 S143-U3 — a notebook pool file hangs off the notebook that feeds it ("Nuôi bởi")
			if ( self::POOL_KIND === (string) ( $f['fm']['kind'] ?? '' ) && 1 === preg_match( '/^NB-\d+$/', (string) ( $f['fm']['notebook_ref'] ?? '' ) ) ) {
				$nid           = 'nbpool:' . $f['fm']['notebook_ref'];
				$nodes[ $nid ] = $nodes[ $nid ] ?? array( 'id' => $nid, 'type' => 'notebook', 'label' => (string) $f['fm']['notebook_ref'] . ' · ' . (string) ( $f['fm']['title'] ?? '' ), 'ref' => (string) $f['fm']['notebook_ref'], 'date' => '' );
				$edges[]       = array( 'from' => $from, 'to' => $nid, 'rel' => 'fed_by' );
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
		register_rest_route( self::NS, '/note/append', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'rest_append' ), 'permission_callback' => $write ) ); // PHASE-0.143 S143-L5
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

	/** PHASE-0.143 S143-L5 — "Lưu vào kho tri thức" (vault-append@1). */
	public static function rest_append( WP_REST_Request $r ): WP_REST_Response {
		$b = $r->get_json_params();
		$b = is_array( $b ) ? $b : (array) $r->get_body_params();
		return self::respond( self::append( $b, array( 'role' => 'person' ) + self::rest_ctx() ) );
	}

	public static function rest_move( WP_REST_Request $r ): WP_REST_Response {
		$b = $r->get_json_params();
		$b = is_array( $b ) ? $b : (array) $r->get_body_params();
		return self::respond( self::move( $b, array( 'role' => 'person' ) + self::rest_ctx() ) );
	}

	/**
	 * [2026-10-25 07:53 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.137 S137-A3 (owner "có thêm nút Xếp vào thư mục trên Bảng nhanh") — the ONE move door for another REST surface (the owner panel,
	 * whose session user is the current user for the route): the same move() + the same role gate as the vault's own rest_move. @return array|WP_Error {id, vault_folder}
	 */
	public static function move_as_current_user( string $ref, string $folder ) {
		return self::move( array( 'id' => 'src:' . strtoupper( trim( $ref ) ), 'folder' => $folder ), array( 'role' => 'person' ) + self::rest_ctx() );
	}

	/** The folders the current user may file into (number · label · icon) — the folders their role can see. Pure over folders() + access_map(). */
	public static function folders_for_current_user(): array {
		$role = self::role_of( self::rest_ctx() );
		$map  = self::access_map();
		$out  = array();
		foreach ( self::folders() as $f ) {
			if ( self::folder_visible( (string) $f['n'], $role, $map ) ) {
				$out[] = array( 'n' => (string) $f['n'], 'label' => (string) $f['label'], 'icon' => (string) ( $f['icon'] ?? '📁' ) );
			}
		}
		return $out;
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
			'description'    => 'Liệt kê các ghi chú của Kho tri thức (Twin Vault) theo loại: document (tài liệu), image (ảnh), note (ghi chú), sheet (sổ), job (việc), skill (kỹ năng). Mỗi mục có id (lib:/… hoặc src:TL-…), tiêu đề, source_ref. Đọc nội dung bằng vault.get {id}. KHÔNG dùng để tìm theo nội dung (dùng vault.search). Chủ nói "gọi / mở / cho xem kho tri thức (vault) …" hoặc nêu một THƯ MỤC ("vault khách hàng", "kho sản phẩm", "thư mục pháp lý") ⇒ gọi tool này với folder = nguyên cụm chủ nói (site tự khớp tên thư mục), bỏ trống type = mọi loại trong thư mục. Mỗi mục có pipeline (các bước Ghi sổ · Đọc · Vào kho · Học sâu). Hỏi file nào ĐANG tải / đang học / lỗi ⇒ dùng vault.status.',
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
			// [2026-10-27 09:35 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.145 S145-L2 — + offset; the answer says has_more (several words in q = every word must match)
			'input_schema'   => array( 'type' => 'object', 'required' => array( 'q' ), 'properties' => array( 'q' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 120, 'description' => 'Nhiều từ ⇒ mọi từ đều phải khớp (có dấu hay không dấu).' ), 'types' => array( 'type' => 'array', 'items' => $types, 'maxItems' => 6 ), 'limit' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 50 ), 'offset' => array( 'type' => 'integer', 'minimum' => 0, 'maximum' => 1000, 'description' => 'Trang kế: offset = offset cũ + limit khi has_more = true.' ) ) ),
			'output_schema'  => $R::envelope_schema( array( 'items' => array( 'type' => 'array' ), 'total' => array( 'type' => 'integer' ), 'has_more' => array( 'type' => 'boolean' ) ), array( 'items' ) ),
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
		// [2026-10-27 06:45 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.143 S143-L5 (D143-8…12) — vault-append@1
		// @mcp bizcity-mcp-standard@1 tool vault.append
		$R::register( 'vault.append', $common + array(
			'title'          => 'Lưu một câu trả lời vào kho tri thức của notebook',
			'description'    => 'Thêm MỘT mục (câu hỏi + câu trả lời có nguồn) vào file Markdown tổng của notebook trong Kho tri thức; lần đầu cần folder (số hoặc tên thư mục). Cùng message_id lưu hai lần = một mục. Chỉ dùng khi chủ nói "lưu cái này vào kho tri thức"; KHÔNG dùng cho ghi chú tự do (dùng vault.write) và KHÔNG dùng cho "ghi nhớ …" của chủ (notebook_remember).',
			'input_schema'   => array( 'type' => 'object', 'required' => array( 'notebook_id', 'q', 'answer_md' ), 'properties' => array(
				'notebook_id' => array( 'type' => 'integer', 'minimum' => 1 ), 'q' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 300, 'description' => 'Câu hỏi của chủ.' ),
				'answer_md' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 100000, 'description' => 'Câu trả lời Markdown, giữ nguyên mã [nb:X/pY].' ),
				'message_id' => array( 'type' => 'string', 'maxLength' => 60, 'description' => 'Id tin nhắn trả lời (chống lưu trùng).' ),
				'cites' => array( 'type' => 'array', 'items' => array( 'type' => 'string', 'pattern' => '^nb:\d+/p\d+$' ), 'maxItems' => 20 ),
				'folder' => array( 'type' => 'string', 'maxLength' => 60, 'description' => 'Thư mục lần đầu: số (vd 60) hoặc tên ("Sản phẩm").' ),
			) ),
			'output_schema'  => $R::envelope_schema( array( 'path' => array( 'type' => 'string' ), 'entry_id' => array( 'type' => 'string' ), 'duplicated' => array( 'type' => 'boolean' ) ), array( 'path' ) ),
			'read_only'      => false, 'destructive' => false, 'idempotent' => true, 'required_scope' => 'memory.write', 'required_cap' => 'edit_posts', 'scopes' => array( 'memory.write' ),
			'handler'        => array( __CLASS__, 'mcp_append' ),
			'llm_alias'      => 'vault_append',
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

	/** MCP door of append: like mcp_write, the model is never a person. */
	public static function mcp_append( array $args, array $ctx ) {
		$ctx['vrole'] = self::role_of( $ctx );
		unset( $ctx['role'] );
		$res = self::append( $args, $ctx );
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
