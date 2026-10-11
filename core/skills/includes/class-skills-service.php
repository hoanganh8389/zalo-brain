<?php
/**
 * BizCity_Skills_Service — the site's skills as SKILL.md files (PHASE-0.101 S101-L6, doc 90 §3, D101-16 v2).
 *
 * One format for people, the cell and the harness: agentskills SKILL.md — frontmatter `name` + `description`, a
 * Markdown body ≤ 8 KB, and the BizCity block `metadata.bizcity {scope, tools, source, status, version, from_job}`.
 * Lifecycle: `draft` (proposed by the harness or being written) → `approved` (enters the `skills` pack, the cell and
 * the harness see it within 60 s) → `disabled` (out of the pack, kept). Every save keeps ONE previous copy under
 * `_history/` so the owner can undo.
 *
 * This class reads and writes files only. Who may call is decided by the REST routes (edit_posts) and the MCP tools
 * (roles owner, confirm + preview on writes). No model, no database, no cell call (R-BCB-2, R-M595: the knowledge
 * stays on the client; the brain reads it through the pack and `skills.get`).
 *
 * Biz Central Brain — Johnny Chu (Chu Hoàng Anh).
 *
 * // [2026-10-11 Johnny Chu - Chu Hoàng Anh] PHASE-0.101 S101-L6 — new file.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Skills
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Skills_Service', false ) ) {
	return;
}

final class BizCity_Skills_Service {

	const FOLDER      = 'skills';
	const HISTORY     = '_history';
	const STATUSES    = array( 'draft', 'approved', 'disabled' );
	const SOURCES     = array( 'manual', 'harness', 'template' );
	const SCOPES      = array( 'site', 'number' );
	const MAX_SKILLS  = 60;
	const MAX_BODY    = 8192;
	const MAX_TOOLS   = 12;
	const MAX_DESC    = 300;
	const NAME_RE     = '/^[a-z0-9][a-z0-9-]{1,62}$/';
	const TOOL_RE     = '/^[a-z][a-z0-9_]*(\.[a-z0-9_]+){1,3}$/';

	/** @var callable|null test seam: () => BizCity_Skill_Manager */
	public static $manager = null;

	private static function mgr(): BizCity_Skill_Manager {
		return self::$manager ? call_user_func( self::$manager ) : BizCity_Skill_Manager::instance();
	}

	/* ── Read ─────────────────────────────────────────────────────────── */

	/**
	 * @param array $filter {status?: string, q?: string, source?: string}
	 * @return array [{name, description, tools, scope, source, status, version, path, updated_at, bytes, from_job?}]
	 */
	public static function list( array $filter = array(), int $limit = self::MAX_SKILLS ): array {
		$status = isset( $filter['status'] ) ? (string) $filter['status'] : '';
		$source = isset( $filter['source'] ) ? (string) $filter['source'] : '';
		$q      = isset( $filter['q'] ) ? self::fold( (string) $filter['q'] ) : '';
		$out    = array();
		foreach ( self::mgr()->get_all_skills() as $file ) {
			$s = self::from_raw( (string) $file['raw'], (string) $file['path'], (int) $file['lastModified'] );
			if ( ! $s ) {
				continue;
			}
			if ( '' !== $status && $s['status'] !== $status ) {
				continue;
			}
			if ( '' !== $source && $s['source'] !== $source ) {
				continue;
			}
			if ( '' !== $q && false === strpos( self::fold( $s['name'] . ' ' . $s['description'] ), $q ) ) {
				continue;
			}
			unset( $s['body_md'] );
			$out[] = $s;
		}
		usort( $out, static function ( $a, $b ) { return strcmp( $a['name'], $b['name'] ); } );
		return array_slice( $out, 0, max( 1, min( self::MAX_SKILLS, $limit ) ) );
	}

	/** @return array|null The skill with `body_md`, or null. */
	public static function get( string $name ) {
		$name = self::clean_name( $name );
		if ( '' === $name ) {
			return null;
		}
		foreach ( self::mgr()->get_all_skills() as $file ) {
			$s = self::from_raw( (string) $file['raw'], (string) $file['path'], (int) $file['lastModified'] );
			if ( $s && $s['name'] === $name ) {
				$s['has_previous'] = ! is_wp_error( self::mgr()->resolve_path( self::history_path( $name, $s['version'] - 1 ) ) )
					&& $s['version'] > 1
					&& is_file( self::mgr()->resolve_path( self::history_path( $name, $s['version'] - 1 ) ) );
				return $s;
			}
		}
		return null;
	}

	/* ── Write ────────────────────────────────────────────────────────── */

	/**
	 * A draft from the harness/cell (confirm never): only ever creates or updates a DRAFT. An approved or disabled
	 * skill of the same name is never touched (`skill_exists`).
	 *
	 * @param array $in {name, description, body_md, tools?: string[], scope?: string, from_job?: string}
	 * @return array|WP_Error {name, status, version, created}
	 */
	public static function propose( array $in ) {
		$name = self::clean_name( (string) ( $in['name'] ?? '' ) );
		if ( '' === $name ) {
			return new WP_Error( 'skill_invalid', 'Tên skill chỉ gồm chữ thường, số và gạch nối (2–63 ký tự).' );
		}
		$body = self::clean_body( (string) ( $in['body_md'] ?? '' ) );
		if ( is_wp_error( $body ) ) {
			return $body;
		}
		$existing = self::get( $name );
		if ( $existing && 'draft' !== $existing['status'] ) {
			return new WP_Error( 'skill_exists', 'Đã có skill "' . $name . '" (' . $existing['status'] . '); đề xuất không ghi đè skill đã duyệt.' );
		}
		if ( ! $existing && count( self::list() ) >= self::MAX_SKILLS ) {
			return new WP_Error( 'skill_limit', 'Thư viện đã đủ ' . self::MAX_SKILLS . ' skill; vô hiệu hoá hoặc xoá bớt trước.' );
		}
		$meta = array(
			'scope'    => self::clean_enum( (string) ( $in['scope'] ?? '' ), self::SCOPES, 'number' ),
			'tools'    => self::clean_tools( $in['tools'] ?? array() ),
			'source'   => 'harness',
			'status'   => 'draft',
			'version'  => $existing ? (int) $existing['version'] + 1 : 1,
			'from_job' => self::clean_job( (string) ( $in['from_job'] ?? '' ) ),
		);
		$desc = self::clean_desc( (string) ( $in['description'] ?? '' ) );
		if ( '' === $desc ) {
			return new WP_Error( 'skill_invalid', 'Thiếu mô tả (description) một câu.' );
		}
		$path = $existing ? $existing['path'] : self::default_path( $name );
		if ( $existing ) {
			self::keep_previous( $existing );
		}
		$w = self::mgr()->write_file( $path, self::build( $name, $desc, $meta, $body ) );
		if ( is_wp_error( $w ) ) {
			return $w;
		}
		do_action( 'bizcity_skills_changed', $name, 'draft', $existing ? 'propose_update' : 'propose' );
		return array( 'name' => $name, 'status' => 'draft', 'version' => $meta['version'], 'created' => ! $existing, 'path' => $path );
	}

	/**
	 * Owner save (confirm always): creates a manual draft or updates any field of an existing skill; version +1, the
	 * previous copy goes to `_history/`. Status never changes here (see set_status).
	 *
	 * @param array $in {name, description?, body_md?, tools?, scope?}
	 * @return array|WP_Error {name, status, version, created, changed[]}
	 */
	public static function save( array $in ) {
		$plan = self::save_plan( $in );
		if ( is_wp_error( $plan ) ) {
			return $plan;
		}
		if ( $plan['existing'] ) {
			self::keep_previous( $plan['existing'] );
		}
		$w = self::mgr()->write_file( $plan['path'], $plan['raw'] );
		if ( is_wp_error( $w ) ) {
			return $w;
		}
		do_action( 'bizcity_skills_changed', $plan['name'], $plan['status'], 'save' );
		if ( 'approved' === $plan['status'] ) {
			self::invalidate_pack();
		}
		return array( 'name' => $plan['name'], 'status' => $plan['status'], 'version' => $plan['version'], 'created' => ! $plan['existing'], 'changed' => $plan['changed'], 'path' => $plan['path'] );
	}

	/** Side-effect-free preview of save(): what would change. @return array|WP_Error */
	public static function save_preview( array $in ) {
		$plan = self::save_plan( $in );
		if ( is_wp_error( $plan ) ) {
			return $plan;
		}
		$verb = $plan['existing'] ? 'Sửa' : 'Tạo';
		return array(
			'summary'  => $verb . ' skill "' . $plan['name'] . '" (bản ' . $plan['version'] . ', ' . $plan['status'] . '): ' . ( $plan['changed'] ? implode( ', ', $plan['changed'] ) : 'không đổi' ) . '.',
			'name'     => $plan['name'],
			'version'  => $plan['version'],
			'status'   => $plan['status'],
			'changed'  => $plan['changed'],
			'bytes'    => strlen( $plan['raw'] ),
		);
	}

	/**
	 * @param array $in {name, status: draft|approved|disabled}
	 * @return array|WP_Error {name, status, previous_status}
	 */
	public static function set_status( array $in ) {
		$plan = self::status_plan( $in );
		if ( is_wp_error( $plan ) ) {
			return $plan;
		}
		$s    = $plan['existing'];
		$meta = array( 'scope' => $s['scope'], 'tools' => $s['tools'], 'source' => $s['source'], 'status' => $plan['status'], 'version' => $s['version'], 'from_job' => $s['from_job'] );
		$w    = self::mgr()->write_file( $s['path'], self::build( $s['name'], $s['description'], $meta, $s['body_md'] ) );
		if ( is_wp_error( $w ) ) {
			return $w;
		}
		do_action( 'bizcity_skills_changed', $s['name'], $plan['status'], 'set_status' );
		if ( 'approved' === $plan['status'] || 'approved' === $s['status'] ) {
			self::invalidate_pack();
		}
		return array( 'name' => $s['name'], 'status' => $plan['status'], 'previous_status' => $s['status'] );
	}

	/** @return array|WP_Error */
	public static function set_status_preview( array $in ) {
		$plan = self::status_plan( $in );
		if ( is_wp_error( $plan ) ) {
			return $plan;
		}
		$labels = array( 'draft' => 'nháp', 'approved' => 'duyệt (vào gói, não và harness dùng được)', 'disabled' => 'vô hiệu (rút khỏi gói, giữ file)' );
		return array(
			'summary'         => 'Skill "' . $plan['existing']['name'] . '": ' . $plan['existing']['status'] . ' → ' . $plan['status'] . ' — ' . $labels[ $plan['status'] ] . '.',
			'name'            => $plan['existing']['name'],
			'status'          => $plan['status'],
			'previous_status' => $plan['existing']['status'],
		);
	}

	/** Restore the previous copy (version-1) if it exists. @return array|WP_Error */
	public static function undo( string $name ) {
		$s = self::get( $name );
		if ( ! $s ) {
			return new WP_Error( 'skill_not_found', 'Không có skill "' . $name . '".' );
		}
		$prev = self::mgr()->read_file( self::history_path( $s['name'], $s['version'] - 1 ) );
		if ( is_wp_error( $prev ) ) {
			return new WP_Error( 'skill_no_history', 'Skill này chưa có bản trước để hoàn tác.' );
		}
		$w = self::mgr()->write_file( $s['path'], (string) $prev['raw'] );
		if ( is_wp_error( $w ) ) {
			return $w;
		}
		do_action( 'bizcity_skills_changed', $s['name'], $s['status'], 'undo' );
		self::invalidate_pack();
		return array( 'name' => $s['name'], 'version' => $s['version'] - 1 );
	}

	/* ── Plans (shared by write + preview) ─────────────────────────────── */

	private static function save_plan( array $in ) {
		$name = self::clean_name( (string) ( $in['name'] ?? '' ) );
		if ( '' === $name ) {
			return new WP_Error( 'skill_invalid', 'Tên skill chỉ gồm chữ thường, số và gạch nối (2–63 ký tự).' );
		}
		$existing = self::get( $name );
		$changed  = array();
		if ( ! $existing && count( self::list() ) >= self::MAX_SKILLS ) {
			return new WP_Error( 'skill_limit', 'Thư viện đã đủ ' . self::MAX_SKILLS . ' skill; vô hiệu hoá hoặc xoá bớt trước.' );
		}
		$desc = array_key_exists( 'description', $in ) ? self::clean_desc( (string) $in['description'] ) : ( $existing ? $existing['description'] : '' );
		if ( '' === $desc ) {
			return new WP_Error( 'skill_invalid', 'Thiếu mô tả (description) một câu.' );
		}
		$body = array_key_exists( 'body_md', $in ) ? self::clean_body( (string) $in['body_md'] ) : ( $existing ? $existing['body_md'] : '' );
		if ( is_wp_error( $body ) ) {
			return $body;
		}
		$tools = array_key_exists( 'tools', $in ) ? self::clean_tools( $in['tools'] ) : ( $existing ? $existing['tools'] : array() );
		$scope = array_key_exists( 'scope', $in ) ? self::clean_enum( (string) $in['scope'], self::SCOPES, 'number' ) : ( $existing ? $existing['scope'] : 'number' );
		if ( $existing ) {
			if ( $desc !== $existing['description'] ) { $changed[] = 'mô tả'; }
			if ( $body !== $existing['body_md'] ) { $changed[] = 'nội dung'; }
			if ( $tools !== $existing['tools'] ) { $changed[] = 'tools'; }
			if ( $scope !== $existing['scope'] ) { $changed[] = 'phạm vi'; }
		} else {
			$changed[] = 'tạo mới';
		}
		$meta = array(
			'scope'    => $scope,
			'tools'    => $tools,
			'source'   => $existing ? $existing['source'] : 'manual',
			'status'   => $existing ? $existing['status'] : 'draft',
			'version'  => $existing ? ( $changed ? (int) $existing['version'] + 1 : (int) $existing['version'] ) : 1,
			'from_job' => $existing ? $existing['from_job'] : '',
		);
		return array(
			'name'     => $name,
			'existing' => $existing,
			'path'     => $existing ? $existing['path'] : self::default_path( $name ),
			'raw'      => self::build( $name, $desc, $meta, $body ),
			'status'   => $meta['status'],
			'version'  => $meta['version'],
			'changed'  => $changed,
		);
	}

	private static function status_plan( array $in ) {
		$name = self::clean_name( (string) ( $in['name'] ?? '' ) );
		$st   = (string) ( $in['status'] ?? '' );
		if ( ! in_array( $st, self::STATUSES, true ) ) {
			return new WP_Error( 'skill_invalid', 'Trạng thái phải là draft, approved hoặc disabled.' );
		}
		$existing = '' !== $name ? self::get( $name ) : null;
		if ( ! $existing ) {
			return new WP_Error( 'skill_not_found', 'Không có skill "' . $name . '".' );
		}
		if ( $existing['status'] === $st ) {
			return new WP_Error( 'skill_unchanged', 'Skill "' . $name . '" đã ở trạng thái ' . $st . '.' );
		}
		return array( 'existing' => $existing, 'status' => $st );
	}

	/* ── SKILL.md format ───────────────────────────────────────────────── */

	/** @return array|null Normalised skill or null when the file is not a skill (no `name`). */
	public static function from_raw( string $raw, string $path = '', int $mtime = 0 ) {
		$p    = self::parse_skill( $raw );
		$name = self::clean_name( (string) ( $p['frontmatter']['name'] ?? '' ) );
		if ( '' === $name ) {
			return null;
		}
		$bz = isset( $p['frontmatter']['metadata']['bizcity'] ) && is_array( $p['frontmatter']['metadata']['bizcity'] ) ? $p['frontmatter']['metadata']['bizcity'] : array();
		$updated = (string) ( $bz['updated_at'] ?? '' );
		return array(
			'name'        => $name,
			'description' => self::clean_desc( (string) ( $p['frontmatter']['description'] ?? '' ) ),
			'tools'       => self::clean_tools( $bz['tools'] ?? array() ),
			'scope'       => self::clean_enum( (string) ( $bz['scope'] ?? '' ), self::SCOPES, 'number' ),
			'source'      => self::clean_enum( (string) ( $bz['source'] ?? '' ), self::SOURCES, 'manual' ),
			'status'      => self::clean_enum( (string) ( $bz['status'] ?? '' ), self::STATUSES, 'draft' ),
			'version'     => max( 1, (int) ( $bz['version'] ?? 1 ) ),
			'from_job'    => self::clean_job( (string) ( $bz['from_job'] ?? '' ) ),
			'path'        => $path,
			'updated_at'  => '' !== $updated ? $updated : ( $mtime > 0 ? gmdate( 'Y-m-d\TH:i:s\Z', $mtime ) : '' ),
			'bytes'       => strlen( trim( $p['content'] ) ),
			'body_md'     => trim( $p['content'] ), // normalised like clean_body(): a re-save of the same text is "no change"
		);
	}

	/** Build the SKILL.md text. */
	public static function build( string $name, string $description, array $meta, string $body ): string {
		$lines   = array( '---', 'name: ' . $name, 'description: "' . str_replace( array( '\\', '"' ), array( '\\\\', '\\"' ), $description ) . '"', 'metadata:', '  bizcity:' );
		$lines[] = '    scope: ' . $meta['scope'];
		$lines[] = '    tools: [' . implode( ', ', (array) $meta['tools'] ) . ']';
		$lines[] = '    source: ' . $meta['source'];
		$lines[] = '    status: ' . $meta['status'];
		$lines[] = '    version: ' . (int) $meta['version'];
		$lines[] = '    updated_at: ' . gmdate( 'Y-m-d\TH:i:s\Z' );
		if ( '' !== (string) ( $meta['from_job'] ?? '' ) ) {
			$lines[] = '    from_job: ' . $meta['from_job'];
		}
		$lines[] = '---';
		$lines[] = '';
		return implode( "\n", $lines ) . ltrim( $body, "\r\n" ) . ( '' === $body || "\n" === substr( $body, -1 ) ? '' : "\n" );
	}

	/**
	 * YAML-lite with nesting by indentation (maps, `key: value`, inline `[a, b]`, `- item` lists, quoted strings,
	 * true/false, numbers). Enough for SKILL.md; not a YAML parser.
	 *
	 * @return array{frontmatter:array,content:string}
	 */
	public static function parse_skill( string $raw ): array {
		if ( ! preg_match( '/\A---\s*\r?\n(.*?)\r?\n---\s*\r?\n?(.*)\z/s', $raw, $m ) ) {
			return array( 'frontmatter' => array(), 'content' => $raw );
		}
		$root  = array();
		$stack = array( array( 'indent' => -1, 'key' => null ) );
		$refs  = array( &$root );
		$last_key = null;
		foreach ( explode( "\n", str_replace( "\r", '', $m[1] ) ) as $line ) {
			$t = trim( $line );
			if ( '' === $t || '#' === $t[0] ) {
				continue;
			}
			$indent = strlen( $line ) - strlen( ltrim( $line, ' ' ) );
			if ( preg_match( '/^[-*]\s+(.*)$/', $t, $li ) ) {
				while ( count( $stack ) > 1 && $indent < $stack[ count( $stack ) - 1 ]['indent'] ) {
					array_pop( $stack );
					array_pop( $refs );
				}
				$container =& $refs[ count( $refs ) - 1 ];
				$container[] = self::scalar( $li[1] );
				unset( $container );
				continue;
			}
			if ( ! preg_match( '/^([A-Za-z_][\w.-]*)\s*:\s*(.*)$/', $t, $kv ) ) {
				continue;
			}
			while ( count( $stack ) > 1 && $indent <= $stack[ count( $stack ) - 1 ]['indent'] ) {
				array_pop( $stack );
				array_pop( $refs );
			}
			$container =& $refs[ count( $refs ) - 1 ];
			$key = $kv[1];
			$val = trim( $kv[2] );
			if ( '' === $val ) {
				$container[ $key ] = array();
				$stack[]           = array( 'indent' => $indent, 'key' => $key );
				$refs[]            = &$container[ $key ];
			} else {
				$container[ $key ] = self::scalar( $val );
			}
			$last_key = $key;
			unset( $container );
		}
		return array( 'frontmatter' => $root, 'content' => $m[2] );
	}

	private static function scalar( string $val ) {
		$val = trim( $val );
		if ( '[]' === $val ) {
			return array();
		}
		if ( preg_match( '/^\[(.*)\]$/', $val, $arr ) ) {
			return array_values( array_filter( array_map( static function ( $v ) { return trim( trim( $v ), "\"'" ); }, explode( ',', $arr[1] ) ), 'strlen' ) );
		}
		if ( 'true' === $val ) {
			return true;
		}
		if ( 'false' === $val ) {
			return false;
		}
		if ( is_numeric( $val ) ) {
			return $val + 0;
		}
		if ( strlen( $val ) >= 2 && '"' === $val[0] && '"' === substr( $val, -1 ) ) {
			return str_replace( array( '\\"', '\\\\' ), array( '"', '\\' ), substr( $val, 1, -1 ) );
		}
		return trim( $val, "'" );
	}

	/* ── Helpers ──────────────────────────────────────────────────────── */

	public static function clean_name( string $name ): string {
		$name = strtolower( trim( $name ) );
		return preg_match( self::NAME_RE, $name ) ? $name : '';
	}

	public static function clean_desc( string $d ): string {
		$d = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $d ) ) );
		return function_exists( 'mb_substr' ) ? mb_substr( $d, 0, self::MAX_DESC ) : substr( $d, 0, self::MAX_DESC );
	}

	/** @return string|WP_Error */
	public static function clean_body( string $body ) {
		$body = str_replace( "\r\n", "\n", $body );
		$body = preg_replace( '/<script\b[^>]*>.*?<\/script>/is', '', $body );
		$body = trim( (string) $body );
		if ( strlen( $body ) > self::MAX_BODY ) {
			return new WP_Error( 'skill_too_long', 'Thân skill tối đa ' . self::MAX_BODY . ' byte (hiện ' . strlen( $body ) . ').' );
		}
		return $body;
	}

	public static function clean_tools( $tools ): array {
		$out = array();
		foreach ( (array) $tools as $t ) {
			$t = strtolower( trim( (string) $t ) );
			if ( preg_match( self::TOOL_RE, $t ) && ! in_array( $t, $out, true ) ) {
				$out[] = $t;
			}
		}
		return array_slice( $out, 0, self::MAX_TOOLS );
	}

	private static function clean_enum( string $v, array $allowed, string $default ): string {
		return in_array( $v, $allowed, true ) ? $v : $default;
	}

	private static function clean_job( string $job ): string {
		return preg_match( '/^[A-Za-z0-9_-]{1,40}$/', $job ) ? $job : '';
	}

	private static function fold( string $s ): string {
		return strtolower( function_exists( 'remove_accents' ) ? remove_accents( $s ) : $s );
	}

	private static function default_path( string $name ): string {
		return '/' . self::FOLDER . '/' . $name . '.md';
	}

	private static function history_path( string $name, int $version ): string {
		return '/' . self::HISTORY . '/' . $name . '.v' . max( 1, $version ) . '.md';
	}

	/** Copy the current file to `_history/<name>.v<version>.md` (one previous copy per version; older ones pruned to 3). */
	private static function keep_previous( array $existing ): void {
		$cur = self::mgr()->read_file( (string) $existing['path'] );
		if ( is_wp_error( $cur ) ) {
			return;
		}
		self::mgr()->write_file( self::history_path( $existing['name'], (int) $existing['version'] ), (string) $cur['raw'] );
		$old = (int) $existing['version'] - 3;
		if ( $old >= 1 ) {
			self::mgr()->delete_file( self::history_path( $existing['name'], $old ) );
		}
	}

	/** Tell the cells the `skills` pack moved (debounced by BizCity_Zalo_Pack_Invalidate when the Zalo plugin is present). */
	private static function invalidate_pack(): void {
		if ( class_exists( 'BizCity_Skills_Pack_Exporter', false ) ) {
			BizCity_Skills_Pack_Exporter::flush();
		}
		if ( class_exists( 'BizCity_Zalo_Pack_Invalidate', false ) && method_exists( 'BizCity_Zalo_Pack_Invalidate', 'on_packs_changed' ) ) {
			BizCity_Zalo_Pack_Invalidate::on_packs_changed( array( BizCity_Skills_Pack_Exporter::KIND ) );
		}
	}
}
