<?php
/**
 * BizCity_Skill_Manager — file CRUD of the SKILL.md library (PHASE-0.101 S101-L6, lean revival of core/_archived/skills).
 *
 * Skills are Markdown files under `uploads/bizcity-skills/{blog_id}/` (per-blog, writable, never in git). This class
 * only knows files and folders: tree, read, write, delete, create folder, a flat frontmatter parser and a traversal-safe
 * path resolver. Skill semantics (SKILL.md frontmatter `metadata.bizcity`, status, version) live in
 * `BizCity_Skills_Service`. Nothing here calls a model, the database or the cell.
 *
 * Multisite (R-MSDB): the library directory is computed on every call from the CURRENT blog, so a caller inside
 * `switch_to_blog()` reads and writes that blog's library.
 *
 * Biz Central Brain — Johnny Chu (Chu Hoàng Anh).
 *
 * // [2026-10-11 Johnny Chu - Chu Hoàng Anh] PHASE-0.101 S101-L6 — trimmed from the archived manager: DB merges, matching,
 * //   archetypes and character clones removed; library path lazy per blog instead of a file-scope constant (R-PERF).
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Skills
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Skill_Manager', false ) ) {
	return;
}

class BizCity_Skill_Manager {

	/** Folders whose name starts with this are internal (history copies) and never listed. */
	const INTERNAL_PREFIX = '_';

	private static $instance = null;

	public static function instance(): self {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/** `uploads/bizcity-skills/{blog_id}/` with a trailing slash — computed per call (after any switch_to_blog). */
	public static function library_dir(): string {
		$upload = wp_upload_dir( null, false );
		$base   = is_array( $upload ) && ! empty( $upload['basedir'] ) ? (string) $upload['basedir'] : WP_CONTENT_DIR . '/uploads';
		return rtrim( $base, '/\\' ) . '/bizcity-skills/' . (int) get_current_blog_id() . '/';
	}

	/** Create the library (and its web-access guards) on first use. */
	public function ensure_library(): bool {
		$dir = self::library_dir();
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return false;
		}
		$htaccess = $dir . '.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			file_put_contents( $htaccess, "Order deny,allow\nDeny from all\n" );
		}
		$index = $dir . 'index.php';
		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, "<?php\n// Silence is golden.\n" );
		}
		return true;
	}

	/* ── Tree (for the editor SPA) ───────────────────────────────────── */

	/** @return array [{id, name, isDir, path?, parentId?, lastModified?}] — id "0" is the root. */
	public function get_file_tree(): array {
		$this->ensure_library();
		$tree    = array( array( 'id' => '0', 'name' => '/', 'path' => '/', 'isDir' => true ) );
		$counter = 1;
		$this->scan_dir( rtrim( self::library_dir(), '/\\' ), '0', $tree, $counter );
		return $tree;
	}

	private function scan_dir( string $dir, string $parent_id, array &$tree, int &$counter ): void {
		$entries = @scandir( $dir );
		if ( ! $entries ) {
			return;
		}
		$base = rtrim( self::library_dir(), '/\\' );
		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry || 'index.php' === $entry || '.htaccess' === $entry ) {
				continue;
			}
			$full = $dir . DIRECTORY_SEPARATOR . $entry;
			$rel  = str_replace( '\\', '/', str_replace( $base, '', $full ) );
			if ( is_dir( $full ) ) {
				if ( 0 === strpos( $entry, self::INTERNAL_PREFIX ) ) {
					continue;
				}
				$id     = (string) $counter++;
				$tree[] = array( 'id' => $id, 'name' => $entry, 'isDir' => true, 'path' => $rel, 'parentId' => $parent_id, 'lastModified' => (int) filemtime( $full ) );
				$this->scan_dir( $full, $id, $tree, $counter );
			} elseif ( 'md' === pathinfo( $entry, PATHINFO_EXTENSION ) ) {
				$tree[] = array( 'id' => (string) $counter++, 'name' => $entry, 'isDir' => false, 'parentId' => $parent_id, 'lastModified' => (int) filemtime( $full ) );
			}
		}
	}

	/* ── CRUD ────────────────────────────────────────────────────────── */

	/** @return array{path:string,frontmatter:array,content:string,raw:string,lastModified:int}|WP_Error */
	public function read_file( string $relative_path ) {
		$abs = $this->resolve_path( $relative_path );
		if ( is_wp_error( $abs ) ) {
			return $abs;
		}
		if ( ! is_file( $abs ) ) {
			return new WP_Error( 'not_found', 'Không có file này.' );
		}
		$raw    = (string) file_get_contents( $abs );
		$parsed = $this->parse_frontmatter( $raw );
		return array(
			'path'         => $relative_path,
			'frontmatter'  => $parsed['frontmatter'],
			'content'      => $parsed['content'],
			'raw'          => $raw,
			'lastModified' => (int) filemtime( $abs ),
		);
	}

	/** @return true|WP_Error */
	public function write_file( string $relative_path, string $raw ) {
		$abs = $this->resolve_path( $relative_path );
		if ( is_wp_error( $abs ) ) {
			return $abs;
		}
		if ( 'md' !== pathinfo( $abs, PATHINFO_EXTENSION ) ) {
			return new WP_Error( 'invalid_ext', 'Chỉ nhận file .md.' );
		}
		$this->ensure_library();
		$dir = dirname( $abs );
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return new WP_Error( 'write_error', 'Không tạo được thư mục.' );
		}
		if ( false === file_put_contents( $abs, $raw ) ) {
			return new WP_Error( 'write_error', 'Không ghi được file.' );
		}
		return true;
	}

	/**
	 * [2026-10-17 03:57 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.117 D117-24 — write the `<name>.twin.json` sidecar of a skill (twin-skill@1).
	 * Separate from write_file() on purpose: the generic POST /file route stays .md-only, so a twin file is only ever written by
	 * BizCity_Skills_Service::save() after the cell checked it. @return true|WP_Error
	 */
	public function write_sidecar( string $relative_path, string $json ) {
		if ( 1 !== preg_match( '#/[a-z0-9][a-z0-9-]{1,62}(\.v[0-9]{1,6})?\.twin\.json$#', $relative_path ) ) {
			return new WP_Error( 'invalid_ext', 'Chỉ nhận file <tên>.twin.json.' );
		}
		$abs = $this->resolve_path( $relative_path );
		if ( is_wp_error( $abs ) ) {
			return $abs;
		}
		$this->ensure_library();
		$dir = dirname( $abs );
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return new WP_Error( 'write_error', 'Không tạo được thư mục.' );
		}
		return false === file_put_contents( $abs, $json ) ? new WP_Error( 'write_error', 'Không ghi được file.' ) : true;
	}

	/** Delete a file, or an EMPTY folder. @return true|WP_Error */
	public function delete_file( string $relative_path ) {
		$abs = $this->resolve_path( $relative_path );
		if ( is_wp_error( $abs ) ) {
			return $abs;
		}
		if ( ! file_exists( $abs ) ) {
			return new WP_Error( 'not_found', 'Không có file này.' );
		}
		if ( is_dir( $abs ) ) {
			$entries = array_diff( (array) scandir( $abs ), array( '.', '..', 'index.php' ) );
			if ( count( $entries ) > 0 ) {
				return new WP_Error( 'not_empty', 'Thư mục chưa rỗng.' );
			}
			$idx = $abs . DIRECTORY_SEPARATOR . 'index.php';
			if ( is_file( $idx ) ) {
				unlink( $idx );
			}
			return rmdir( $abs ) ? true : new WP_Error( 'delete_error', 'Không xoá được thư mục.' );
		}
		return unlink( $abs ) ? true : new WP_Error( 'delete_error', 'Không xoá được file.' );
	}

	/** @return true|WP_Error */
	public function create_folder( string $folder_name ) {
		$abs = $this->resolve_path( '/' . ltrim( $folder_name, '/' ) );
		if ( is_wp_error( $abs ) ) {
			return $abs;
		}
		if ( is_dir( $abs ) ) {
			return new WP_Error( 'exists', 'Thư mục đã có.' );
		}
		$this->ensure_library();
		if ( ! wp_mkdir_p( $abs ) ) {
			return new WP_Error( 'create_error', 'Không tạo được thư mục.' );
		}
		$idx = $abs . DIRECTORY_SEPARATOR . 'index.php';
		if ( ! file_exists( $idx ) ) {
			file_put_contents( $idx, "<?php\n// Silence is golden.\n" );
		}
		return true;
	}

	/** Every .md of the library (internal folders skipped). @return array [{path, frontmatter, content, raw, lastModified}] */
	public function get_all_skills(): array {
		$this->ensure_library();
		$out = array();
		$this->collect( rtrim( self::library_dir(), '/\\' ), $out );
		return $out;
	}

	private function collect( string $dir, array &$out ): void {
		$entries = @scandir( $dir );
		if ( ! $entries ) {
			return;
		}
		$base = rtrim( self::library_dir(), '/\\' );
		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry || 'index.php' === $entry ) {
				continue;
			}
			$full = $dir . DIRECTORY_SEPARATOR . $entry;
			if ( is_dir( $full ) ) {
				if ( 0 !== strpos( $entry, self::INTERNAL_PREFIX ) ) {
					$this->collect( $full, $out );
				}
			} elseif ( 'md' === pathinfo( $entry, PATHINFO_EXTENSION ) ) {
				$raw    = (string) file_get_contents( $full );
				$parsed = $this->parse_frontmatter( $raw );
				$out[]  = array(
					'path'         => str_replace( '\\', '/', str_replace( $base, '', $full ) ),
					'frontmatter'  => $parsed['frontmatter'],
					'content'      => $parsed['content'],
					'raw'          => $raw,
					'lastModified' => (int) filemtime( $full ),
				);
			}
		}
	}

	/* ── Helpers ─────────────────────────────────────────────────────── */

	/**
	 * Relative → absolute with traversal protection: null bytes, `..` (also URL-encoded), a safe character set, and the
	 * resolved path must stay inside the library. @return string|WP_Error
	 */
	public function resolve_path( string $relative_path ) {
		if ( false !== strpos( $relative_path, "\0" ) ) {
			return new WP_Error( 'traversal', 'Đường dẫn không hợp lệ.' );
		}
		$relative_path = '/' . ltrim( str_replace( '\\', '/', $relative_path ), '/' );
		$decoded       = rawurldecode( $relative_path );
		if ( preg_match( '/\.\./', $decoded ) ) {
			return new WP_Error( 'traversal', 'Đường dẫn không hợp lệ.' );
		}
		if ( preg_match( '/[^a-zA-Z0-9\-_\.\/\p{L}\p{N}]/u', ltrim( $decoded, '/' ) ) ) {
			return new WP_Error( 'invalid_chars', 'Tên file chỉ gồm chữ, số, gạch nối, gạch dưới và dấu chấm.' );
		}
		$library = rtrim( self::library_dir(), '/\\' );
		$abs     = str_replace( '/', DIRECTORY_SEPARATOR, $library . $relative_path );
		$real_lib = realpath( $library );
		if ( ! $real_lib ) {
			return $abs; // library not created yet — ensure_library() runs before any write
		}
		$check = file_exists( $abs ) ? $abs : dirname( $abs );
		$real  = realpath( $check );
		if ( $real && 0 !== strpos( $real, $real_lib ) ) {
			return new WP_Error( 'traversal', 'Đường dẫn nằm ngoài thư viện.' );
		}
		return $abs;
	}

	/**
	 * Flat YAML-subset frontmatter: `key: value`, inline `[a, b]`, multi-line `- item` lists, true/false/numbers. Nested
	 * blocks (`metadata:` → `bizcity:`) are parsed by BizCity_Skills_Service::parse_skill(); here they fold into keys
	 * named by their LAST segment only when unambiguous, which the editor tree does not need.
	 *
	 * @return array{frontmatter:array,content:string}
	 */
	public function parse_frontmatter( string $raw ): array {
		$fm      = array();
		$content = $raw;
		if ( preg_match( '/\A---\s*\r?\n(.*?)\r?\n---\s*\r?\n(.*)\z/s', $raw, $m ) ) {
			$content     = $m[2];
			$current_key = null;
			foreach ( explode( "\n", $m[1] ) as $line ) {
				$trimmed = rtrim( $line );
				if ( '' === $trimmed || '#' === $trimmed[0] ) {
					continue;
				}
				if ( preg_match( '/^\s+[-*]\s+(.*)$/', $trimmed, $li ) ) {
					if ( null !== $current_key ) {
						$item = trim( $li[1], "\"' " );
						if ( '' !== $item ) {
							if ( ! isset( $fm[ $current_key ] ) || ! is_array( $fm[ $current_key ] ) ) {
								$fm[ $current_key ] = array();
							}
							$fm[ $current_key ][] = $item;
						}
					}
					continue;
				}
				if ( preg_match( '/^\s*(\w[\w_-]*)\s*:\s*(.*)$/', $trimmed, $kv ) ) {
					$key         = $kv[1];
					$val         = trim( $kv[2] );
					$current_key = $key;
					if ( '' === $val || '[]' === $val ) {
						$fm[ $key ] = array();
					} elseif ( preg_match( '/^\[(.+)\]$/', $val, $arr ) ) {
						$fm[ $key ] = array_map( static function ( $v ) { return trim( trim( $v ), "\"'" ); }, explode( ',', $arr[1] ) );
					} elseif ( 'true' === $val ) {
						$fm[ $key ] = true;
					} elseif ( 'false' === $val ) {
						$fm[ $key ] = false;
					} elseif ( is_numeric( $val ) ) {
						$fm[ $key ] = $val + 0;
					} else {
						$fm[ $key ] = trim( $val, "\"'" );
					}
				}
			}
		}
		return array( 'frontmatter' => $fm, 'content' => $content );
	}
}
