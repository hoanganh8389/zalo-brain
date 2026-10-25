<?php
/**
 * BizCity_Brain_Vault_Store — the Readable Brain (PHASE-0.133 doc 30, R-SOURCE-FIRST-VAULT VA-1…6): one private Markdown store per site, addressed by
 * LOGICAL refs (`vault://TL-47/v2/r1`), never by paths a caller builds. Upgraded from core/skills: the file / path / guard logic of
 * BizCity_Skill_Manager is generalised here, and the skills library is simply the `skills` namespace (BizCity_Skill_Manager::library_dir()
 * asks this class; the path is byte-for-byte the old one, so every skill route, MCP tool and pack behaves as before).
 *
 *   namespaces   documents · images · notes · sheet-descriptors · job-summaries (new, under the vault root) · skills (the existing library)
 *   root         constant BIZCITY_VAULT_ROOT / option bizcity_vault_root (absolute, outside the web root — D133-5), else uploads/bizcity-vault/
 *                with deny files; per blog: <root>/<blog_id>/<namespace>/
 *   layout       <ns>/<REF>/v<n>/r<rev>.md (+ r<rev>.map.json sidecar)
 *   frontmatter  brain-markdown@1, written by the SITE as a projection of the journal row (VA-2): identity fields are never taken from a caller,
 *                authority fields (approved, customer, access other than inherit_from_source, money, job status) are dropped
 *
 * Reads go through brain-access@1 at the callers (REST / MCP); this class only stores. No model call (validate-no-llm-in-core).
 *
 * Biz Central Brain — Johnny Chu (Chu Hoàng Anh).
 *
 * [2026-10-24 05:35 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.133 S133-V2 — new file.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\KG_Hub
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Brain_Vault_Store', false ) ) {
	return;
}

final class BizCity_Brain_Vault_Store {

	const CONTRACT     = 'twin-vault@1.0.0'; // [2026-10-24 09:04 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.134 D134-5 — was brain-markdown@1.0.0 (files with that schema are read as an alias, never rewritten)
	const NAMESPACES   = array( 'documents', 'images', 'notes', 'sheet-descriptors', 'job-summaries', 'skills' );
	const TYPES        = array( 'document', 'image', 'note', 'sheet-descriptor', 'job-summary' );
	const MAX_MD_BYTES = 524288; // 512 KB per representation revision
	const MAX_REVS     = 50;
	/** frontmatter keys a file may never grant itself (MD-1 / VA-2) */
	const FORBIDDEN_KEYS = array( 'approved', 'customer', 'public', 'verified', 'amount', 'amounts', 'total', 'status_job', 'job_status', 'owner', 'tenant', 'permissions', 'acl', 'delivered_to' );

	// ── where (site) ────────────────────────────────────────────────────────

	/** The vault root of THIS blog, with a trailing slash; '' when no writable place exists. */
	public static function root(): string {
		$base = '';
		if ( defined( 'BIZCITY_VAULT_ROOT' ) && is_string( BIZCITY_VAULT_ROOT ) && '' !== BIZCITY_VAULT_ROOT ) {
			$base = (string) BIZCITY_VAULT_ROOT;
		} elseif ( function_exists( 'get_option' ) && '' !== (string) get_option( 'bizcity_vault_root', '' ) ) {
			$base = (string) get_option( 'bizcity_vault_root', '' );
		} elseif ( function_exists( 'wp_upload_dir' ) ) {
			$up   = wp_upload_dir( null, false );
			$base = rtrim( (string) ( $up['basedir'] ?? '' ), '/\\' ) . '/bizcity-vault';
		}
		if ( '' === $base ) {
			return '';
		}
		$blog = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
		return rtrim( str_replace( '\\', '/', $base ), '/' ) . '/' . $blog . '/';
	}

	/**
	 * The directory of one namespace. `skills` is the existing skills library (uploads/bizcity-skills/{blog}/), unchanged — the façade of VA-1.
	 */
	public static function namespace_dir( string $ns ): string {
		if ( ! in_array( $ns, self::NAMESPACES, true ) ) {
			return '';
		}
		if ( 'skills' === $ns ) {
			$upload = function_exists( 'wp_upload_dir' ) ? wp_upload_dir( null, false ) : array();
			$base   = rtrim( (string) ( $upload['basedir'] ?? '' ), '/\\' );
			return '' === $base ? '' : $base . '/bizcity-skills/' . ( function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0 ) . '/';
		}
		$root = self::root();
		return '' === $root ? '' : $root . $ns . '/';
	}

	/** The namespace a journal row's representations live in. Pure. */
	public static function namespace_of( string $kind, string $mime = '' ): string {
		if ( 'common.photo' === $kind || 'media.image.output' === $kind || 0 === strpos( $mime, 'image/' ) ) {
			return 'images';
		}
		if ( 'sheet.define' === $kind ) {
			return 'sheet-descriptors';
		}
		if ( 'job.run' === $kind ) {
			return 'job-summaries';
		}
		if ( in_array( $kind, array( 'common.note', 'common.instruction', 'task.minutes', 'grp.brief' ), true ) ) {
			return 'notes';
		}
		return 'documents';
	}

	/** The `type` of a row's Markdown (brain-markdown@1). Pure. */
	public static function type_of( string $ns ): string {
		return array( 'images' => 'image', 'notes' => 'note', 'sheet-descriptors' => 'sheet-descriptor', 'job-summaries' => 'job-summary' )[ $ns ] ?? 'document';
	}

	/** Relative path of one representation inside its namespace ("TL-47/v2/r1.md"); '' for a bad ref. Pure. */
	public static function relative_path( string $vault_ref, string $ext = 'md' ): string {
		$p = class_exists( 'BizCity_Brain_Resource_Link' ) ? BizCity_Brain_Resource_Link::parse_vault_ref( $vault_ref ) : null;
		if ( null === $p || ! in_array( $ext, array( 'md', 'map.json' ), true ) ) {
			return '';
		}
		return $p['ref'] . '/v' . $p['n'] . '/r' . $p['rev'] . '.' . $ext;
	}

	/**
	 * A path is inside $dir (realpath of the deepest existing parent, never a symlink on the way). Pure apart from the filesystem.
	 */
	public static function inside( string $dir, string $path ): bool {
		$dir_real = realpath( $dir );
		if ( false === $dir_real ) {
			return false;
		}
		$check = $path;
		while ( ! file_exists( $check ) ) {
			$parent = dirname( $check );
			if ( $parent === $check ) {
				return false;
			}
			$check = $parent;
		}
		if ( is_link( $check ) || ( file_exists( $path ) && is_link( $path ) ) ) {
			return false;
		}
		$real = realpath( $check );
		$dir_real = rtrim( str_replace( '\\', '/', $dir_real ), '/' ) . '/';
		$real     = rtrim( str_replace( '\\', '/', (string) $real ), '/' ) . '/';
		return false !== $real && 0 === strpos( $real, $dir_real );
	}

	/** Create the namespace directory with the deny files a web server may honour (the real protection is the root outside the web root). */
	public static function ensure( string $ns ): string {
		$dir = self::namespace_dir( $ns );
		if ( '' === $dir || 'skills' === $ns ) {
			return $dir;
		}
		if ( ! is_dir( $dir ) && ! ( function_exists( 'wp_mkdir_p' ) ? wp_mkdir_p( $dir ) : @mkdir( $dir, 0755, true ) ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return '';
		}
		$root = self::root();
		foreach ( array( '.htaccess' => "Require all denied\nDeny from all\n", 'index.php' => "<?php // Silence is golden.\n", 'web.config' => "<?xml version=\"1.0\"?><configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n" ) as $f => $body ) {
			if ( '' !== $root && ! file_exists( $root . $f ) ) {
				@file_put_contents( $root . $f, $body ); // phpcs:ignore
			}
		}
		return $dir;
	}

	// ── read / write (site) ─────────────────────────────────────────────────

	/**
	 * Store one representation revision of a row. `$parts` = {body_md (verbatim, the source's own words), ai_notes? (marked as not the source),
	 * extraction{kind, engine, status, reviewed, run_id}, sidecar? (array → .map.json)}. The frontmatter is built here from $entry (VA-2).
	 *
	 * @return array|WP_Error {ref, rev, content_sha256, bytes}
	 */
	public static function write( array $entry, int $rev, array $parts ) {
		$ref = (string) ( $entry['ref_code'] ?? '' );
		$n   = max( 1, (int) ( $entry['version']['n'] ?? 1 ) );
		$vr  = class_exists( 'BizCity_Brain_Resource_Link' ) ? BizCity_Brain_Resource_Link::vault_ref( $ref, $n, $rev ) : '';
		if ( '' === $vr ) {
			return new WP_Error( 'vault_bad_ref', 'Dòng này không có mã nguồn hợp lệ để lưu bản đọc.', array( 'status' => 400 ) );
		}
		$ns  = self::namespace_of( (string) ( $entry['kind'] ?? '' ), (string) ( $entry['original']['mime'] ?? '' ) );
		$dir = self::ensure( $ns );
		if ( '' === $dir ) {
			return new WP_Error( 'vault_unavailable', 'Kho bản đọc chưa sẵn sàng trên site này (không tạo được thư mục).', array( 'status' => 503, 'hint' => 'Kiểm BIZCITY_VAULT_ROOT / quyền ghi thư mục.', 'help_code' => 'vault_root' ) );
		}
		$md = self::compose( $entry, $rev, $parts );
		if ( strlen( $md ) > self::MAX_MD_BYTES ) {
			return new WP_Error( 'vault_too_large', 'Bản đọc quá lớn (> 512 KB).', array( 'status' => 413 ) );
		}
		$path = $dir . self::relative_path( $vr );
		if ( ! self::inside( $dir, $path ) ) {
			return new WP_Error( 'vault_path_refused', 'Đường dẫn kho không hợp lệ.', array( 'status' => 400 ) );
		}
		$folder = dirname( $path );
		if ( ! is_dir( $folder ) && ! ( function_exists( 'wp_mkdir_p' ) ? wp_mkdir_p( $folder ) : @mkdir( $folder, 0755, true ) ) ) { // phpcs:ignore
			return new WP_Error( 'vault_write_failed', 'Không ghi được bản đọc.', array( 'status' => 500 ) );
		}
		$tmp = $path . '.tmp' . self::tmp_suffix();
		if ( false === @file_put_contents( $tmp, $md, LOCK_EX ) || ! @rename( $tmp, $path ) ) { // phpcs:ignore
			@unlink( $tmp ); // phpcs:ignore
			return new WP_Error( 'vault_write_failed', 'Không ghi được bản đọc.', array( 'status' => 500 ) );
		}
		if ( is_array( $parts['sidecar'] ?? null ) ) {
			$side = $dir . self::relative_path( $vr, 'map.json' );
			if ( self::inside( $dir, $side ) ) {
				@file_put_contents( $side, (string) ( function_exists( 'wp_json_encode' ) ? wp_json_encode( $parts['sidecar'], JSON_UNESCAPED_UNICODE ) : json_encode( $parts['sidecar'] ) ), LOCK_EX ); // phpcs:ignore
			}
		}
		return array( 'ref' => $vr, 'rev' => $rev, 'content_sha256' => hash( 'sha256', $md ), 'bytes' => strlen( $md ), 'namespace' => $ns );
	}

	/**
	 * Read one revision (latest when $rev = 0). `$page` (1-based, by `origin_page` anchors) and `$offset/$limit` slice the body for the cell (≤ 6000 chars a call).
	 *
	 * @return array|WP_Error {ref, rev, markdown, frontmatter, chars, offset, next_offset, truncated}
	 */
	public static function read( array $entry, int $rev = 0, int $offset = 0, int $limit = 6000 ) {
		$res  = is_array( $entry['resource'] ?? null ) ? $entry['resource'] : array();
		$reps = (array) ( $res['representations'] ?? array() );
		$pick = null;
		foreach ( $reps as $r ) {
			if ( is_array( $r ) && ( 0 === $rev ? ( null === $pick || (int) $r['rev'] > (int) $pick['rev'] ) : (int) $r['rev'] === $rev ) ) {
				$pick = $r;
			}
		}
		if ( null === $pick ) {
			return new WP_Error( 'vault_not_found', 'Dòng này chưa có bản đọc (Markdown).', array( 'status' => 404, 'hint' => 'Trợ lý đọc tệp xong sẽ có; hoặc bấm "Đọc nội dung" trên thẻ.', 'help_code' => 'vault_missing' ) );
		}
		$ns   = self::namespace_of( (string) ( $entry['kind'] ?? '' ), (string) ( $entry['original']['mime'] ?? '' ) );
		$dir  = self::namespace_dir( $ns );
		$path = $dir . self::relative_path( (string) $pick['ref'] );
		if ( '' === $dir || ! self::inside( $dir, $path ) || ! is_readable( $path ) ) {
			return new WP_Error( 'vault_not_found', 'Không đọc được bản đọc đã lưu.', array( 'status' => 404 ) );
		}
		$md    = (string) file_get_contents( $path );
		$split = self::split_frontmatter( $md );
		$body  = $split['body'];
		$len   = mb_strlen( $body );
		$offset = max( 0, $offset );
		$limit  = max( 500, min( 6000, $limit ) );
		$slice  = mb_substr( $body, $offset, $limit );
		return array(
			'ref'         => (string) $pick['ref'],
			'rev'         => (int) $pick['rev'],
			'frontmatter' => $split['frontmatter'],
			'markdown'    => $slice,
			'chars'       => $len,
			'offset'      => $offset,
			'next_offset' => $offset + $limit < $len ? $offset + $limit : null,
			'truncated'   => $offset + $limit < $len,
		);
	}

	/** Revisions stored for a row (newest first): {rev, ref, content_sha256, extract, at}. Pure over the entry. */
	public static function revisions( array $entry ): array {
		$list = array();
		foreach ( (array) ( $entry['resource']['representations'] ?? array() ) as $r ) {
			if ( is_array( $r ) ) {
				$list[] = array( 'rev' => (int) $r['rev'], 'ref' => (string) $r['ref'], 'content_sha256' => (string) ( $r['content_sha256'] ?? '' ), 'extract' => $r['extract'] ?? array(), 'at' => (string) ( $r['at'] ?? '' ) );
			}
		}
		usort( $list, static function ( $a, $b ) { return $b['rev'] <=> $a['rev']; } );
		return array_slice( $list, 0, self::MAX_REVS );
	}

	// ── Markdown (pure) ─────────────────────────────────────────────────────

	/** The whole file: site-built frontmatter + title + the source's words + (separately) AI notes. Pure. */
	public static function compose( array $entry, int $rev, array $parts ): string {
		$fm    = self::frontmatter( $entry, $rev, is_array( $parts['extraction'] ?? null ) ? $parts['extraction'] : array() );
		$title = trim( (string) ( $entry['meta']['title'] ?? '' ) ) ?: trim( (string) ( $entry['title'] ?? '' ) );
		$body  = self::split_frontmatter( (string) ( $parts['body_md'] ?? '' ) )['body']; // a body can never smuggle its own frontmatter
		$body  = trim( (string) preg_replace( "/\r\n?/", "\n", $body ) );
		$out   = "---\n" . self::emit_yaml( $fm ) . "---\n# " . self::one_line( $title ) . "\n\n";
		if ( '' !== $body ) {
			$out .= $body . "\n";
		}
		$notes = trim( (string) ( $parts['ai_notes'] ?? '' ) );
		if ( '' !== $notes ) {
			$quoted = implode( "\n", array_map( static function ( $l ) { return '> ' . $l; }, explode( "\n", (string) preg_replace( "/\r\n?/", "\n", $notes ) ) ) );
			$out   .= "\n## Ghi chú AI (không phải văn bản gốc)\n" . $quoted . "\n"; // VA-3
		}
		return $out;
	}

	/** brain-markdown@1 frontmatter = a projection of the row (identity from the journal, never from the caller). Pure. */
	public static function frontmatter( array $entry, int $rev, array $extraction ): array {
		$ns  = self::namespace_of( (string) ( $entry['kind'] ?? '' ), (string) ( $entry['original']['mime'] ?? '' ) );
		$n   = max( 1, (int) ( $entry['version']['n'] ?? 1 ) );
		$ref = (string) ( $entry['ref_code'] ?? '' );
		$fm  = array(
			'schema'             => 'twin-vault@1',
			'type'               => self::type_of( $ns ),
			'ref_code'           => $ref,
			'version_ref'        => $ref . '@v' . $n,
			'original_ref'       => (string) ( $entry['resource']['original']['ref'] ?? ( 'asset://' . $ref . '/v' . $n ) ),
			'original_sha256'    => (string) ( $entry['original']['sha256'] ?? '' ),
			'representation_rev' => max( 1, $rev ),
			'kind'               => (string) ( $entry['kind'] ?? '' ),
			'extraction'         => array(
				'kind'     => substr( preg_replace( '/[^a-z_]/', '', (string) ( $extraction['kind'] ?? 'other' ) ), 0, 32 ) ?: 'other',
				'engine'   => substr( preg_replace( '/[^a-z_]/', '', (string) ( $extraction['engine'] ?? 'model' ) ), 0, 32 ) ?: 'model',
				'status'   => in_array( (string) ( $extraction['status'] ?? '' ), array( 'pending', 'completed', 'failed', 'skipped' ), true ) ? (string) $extraction['status'] : 'completed',
				'reviewed' => ! empty( $extraction['reviewed'] ),
			),
			'access'             => 'inherit_from_source',
		);
		$nb = (int) ( $entry['belongs']['notebook_id'] ?? 0 );
		if ( $nb > 0 ) {
			$fm['belongs'] = array( 'notebook_ref' => 'NB-' . $nb );
		}
		if ( '' !== (string) ( $entry['doc_date'] ?? '' ) ) {
			$fm['doc_date'] = (string) $entry['doc_date'];
		}
		// [2026-10-25 Johnny Chu - Chu Hoàng Anh] PHASE-0.136 S136-G6 (D136-16) — who the file belongs to, for a person reading the .md (projection; the Journal row is the source)
		$gid = (string) ( $entry['group']['gid'] ?? ( $entry['belongs']['group']['gid'] ?? '' ) );
		if ( 1 === preg_match( '/^[0-9]{3,30}$/', $gid ) ) {
			$fm['group'] = array_filter( array( 'gid' => $gid, 'ref' => (string) ( $entry['group']['ref'] ?? '' ), 'name' => mb_substr( (string) ( $entry['group']['name'] ?? ( $entry['belongs']['group']['name'] ?? '' ) ), 0, 120 ) ), 'strlen' );
		}
		if ( (int) ( $entry['contact_id'] ?? 0 ) > 0 ) {
			$fm['contact_id'] = (int) $entry['contact_id']; // the CRM contact (R-CONTACT-ID); no code prefix is defined for contacts
		}
		if ( '' !== (string) ( $entry['meta']['folder'] ?? '' ) ) {
			$fm['folder'] = (string) $entry['meta']['folder'];
		}
		return $fm;
	}

	/** Split "---\n<yaml>---\n<body>" ⇒ {frontmatter: array, body}. Unknown / forbidden keys are dropped (MD-1). Pure. */
	public static function split_frontmatter( string $md ): array {
		$md = (string) preg_replace( "/\r\n?/", "\n", $md );
		if ( 0 !== strpos( $md, "---\n" ) ) {
			return array( 'frontmatter' => array(), 'body' => $md );
		}
		$end = strpos( $md, "\n---\n", 4 );
		if ( false === $end ) {
			return array( 'frontmatter' => array(), 'body' => $md );
		}
		$yaml = substr( $md, 4, $end - 4 + 1 );
		$body = (string) substr( $md, $end + 5 );
		return array( 'frontmatter' => self::sanitize_frontmatter( self::parse_yaml( $yaml ) ), 'body' => $body );
	}

	/** MD-1 / VA-2: authority keys are never taken from a file; access is always inherited. Pure. */
	public static function sanitize_frontmatter( array $fm ): array {
		foreach ( self::FORBIDDEN_KEYS as $k ) {
			unset( $fm[ $k ] );
		}
		if ( isset( $fm['access'] ) && 'inherit_from_source' !== $fm['access'] ) {
			$fm['access'] = 'inherit_from_source';
		}
		return $fm;
	}

	/**
	 * YAML emitter for the frontmatter this class writes: nested maps (2 spaces), lists of scalars, scalars quoted when needed. Pure.
	 */
	public static function emit_yaml( array $map, int $depth = 0 ): string {
		$pad = str_repeat( '  ', $depth );
		$out = '';
		foreach ( $map as $k => $v ) {
			$k = preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $k );
			if ( is_array( $v ) && self::is_list( $v ) ) {
				$out .= $pad . $k . ":\n";
				foreach ( $v as $item ) {
					$out .= $pad . '  - ' . self::scalar_out( $item ) . "\n";
				}
			} elseif ( is_array( $v ) ) {
				$out .= $pad . $k . ":\n" . self::emit_yaml( $v, $depth + 1 );
			} else {
				$out .= $pad . $k . ': ' . self::scalar_out( $v ) . "\n";
			}
		}
		return $out;
	}

	/**
	 * Parser for the same subset (nested maps by 2-space indent, "- " lists of scalars, quoted / bare scalars, true/false/null/numbers). Anything
	 * outside the subset is ignored, never executed (no anchors, tags or multi-line blocks). Pure.
	 */
	public static function parse_yaml( string $yaml ): array {
		$root  = array();
		$stack = array( array( 'indent' => -1, 'ref' => &$root ) );
		$last_key = array();
		foreach ( explode( "\n", $yaml ) as $line ) {
			if ( '' === trim( $line ) || 0 === strpos( ltrim( $line ), '#' ) ) {
				continue;
			}
			$indent = strlen( $line ) - strlen( ltrim( $line, ' ' ) );
			$text   = trim( $line );
			while ( count( $stack ) > 1 && $indent <= $stack[ count( $stack ) - 1 ]['indent'] ) {
				array_pop( $stack );
			}
			$top = &$stack[ count( $stack ) - 1 ]['ref'];
			if ( 0 === strpos( $text, '- ' ) ) {
				$key = $last_key[ count( $stack ) - 1 ] ?? null;
				if ( count( $stack ) > 1 && null === $key ) {
					$top[] = self::scalar_in( substr( $text, 2 ) ); // "key:" then indented "- item" lines
				} elseif ( null !== $key && is_array( $top[ $key ] ?? null ) ) {
					$top[ $key ][] = self::scalar_in( substr( $text, 2 ) ); // "key:" then "- item" at the same indent
				}
				unset( $top );
				continue;
			}
			if ( 1 !== preg_match( '/^([A-Za-z0-9_-]{1,64}):(?:\s+(.*))?$/', $text, $m ) ) {
				unset( $top );
				continue;
			}
			$key = $m[1];
			if ( ! isset( $m[2] ) || '' === $m[2] ) {
				$top[ $key ] = array();
				$last_key[ count( $stack ) - 1 ] = $key;
				$stack[] = array( 'indent' => $indent, 'ref' => &$top[ $key ] );
			} else {
				$top[ $key ] = self::scalar_in( $m[2] );
				$last_key[ count( $stack ) - 1 ] = $key;
			}
			unset( $top );
		}
		return $root;
	}

	private static function is_list( array $a ): bool {
		return array() === $a || array_keys( $a ) === range( 0, count( $a ) - 1 );
	}

	private static function scalar_out( $v ): string {
		if ( is_bool( $v ) ) {
			return $v ? 'true' : 'false';
		}
		if ( null === $v ) {
			return 'null';
		}
		if ( is_int( $v ) || is_float( $v ) ) {
			return (string) $v;
		}
		$s = self::one_line( (string) $v );
		return 1 === preg_match( '/^[A-Za-z0-9_.@\/+:-]+$/', $s ) && ! in_array( strtolower( $s ), array( 'true', 'false', 'null', 'yes', 'no' ), true ) && ! is_numeric( $s ) ? $s : '"' . str_replace( array( '\\', '"' ), array( '\\\\', '\\"' ), $s ) . '"';
	}

	private static function scalar_in( string $s ) {
		$s = trim( $s );
		if ( '' === $s ) {
			return '';
		}
		if ( '"' === $s[0] && '"' === substr( $s, -1 ) && strlen( $s ) >= 2 ) {
			return str_replace( array( '\\"', '\\\\' ), array( '"', '\\' ), substr( $s, 1, -1 ) );
		}
		if ( "'" === $s[0] && "'" === substr( $s, -1 ) && strlen( $s ) >= 2 ) {
			return str_replace( "''", "'", substr( $s, 1, -1 ) );
		}
		$l = strtolower( $s );
		if ( 'true' === $l ) {
			return true;
		}
		if ( 'false' === $l ) {
			return false;
		}
		if ( 'null' === $l || '~' === $l ) {
			return null;
		}
		if ( 1 === preg_match( '/^-?\d{1,15}$/', $s ) ) {
			return (int) $s;
		}
		return $s;
	}

	/** A short random suffix for temp files (with or without WordPress). */
	private static function tmp_suffix(): string {
		return function_exists( 'wp_generate_password' ) ? wp_generate_password( 8, false, false ) : bin2hex( random_bytes( 4 ) );
	}

	private static function one_line( string $s ): string {
		return mb_substr( trim( (string) preg_replace( '/[\r\n\t]+/', ' ', $s ) ), 0, 300 );
	}
}

