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
	/** [2026-10-22 04:35 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.128 S127-SK10 (owner 16:31: Q-SK-1 client owns skills · Q-SK-4 standard structure like Claude Code / Obsidian):
	 * description ≤ 1024 (Agent Skills standard, was 300) — it says WHAT the skill does and WHEN to use it */
	const MAX_DESC    = 1024;
	/** Agent Skills `name`: 1–64 of a-z 0-9 -, no leading / trailing -, no -- ; must equal its folder (`skills/<name>/SKILL.md`). New and saved skills only. */
	const NAME_RE     = '/^(?!.*--)[a-z0-9](?:[a-z0-9-]{0,62}[a-z0-9])?$/';
	/** the rule before SK10 — still READ so an old flat skill keeps working until it is converted */
	const LEGACY_NAME_RE = '/^[a-z0-9][a-z0-9-]{1,62}$/';
	/** the one file that IS the skill inside its folder (`skills/<name>/SKILL.md`); everything else in the folder is a resource */
	const SKILL_FILE  = 'SKILL.md';
	/** per-blog marker: the one-time conversion of flat `<name>.md` files ran (SK10, Q-SK-4) */
	const LAYOUT_OPTION = 'bizcity_skills_layout';
	const TOOL_RE     = '/^[a-z][a-z0-9_]*(\.[a-z0-9_]+){0,3}$/'; // [2026-10-11 Johnny Chu - Chu Hoàng Anh] PHASE-0.101 S102-L3 — {0,3}: tool của cell không có dấu chấm (web_search, create_excel_file)

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
		self::ensure_layout(); // S127-SK10: convert old flat skills once per blog
		foreach ( self::skill_files() as $file ) {
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
		self::ensure_layout();
		foreach ( self::skill_files() as $file ) {
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
			return new WP_Error( 'skill_invalid', 'Tên skill chỉ gồm chữ thường, số và gạch nối (1–64 ký tự, không bắt đầu / kết thúc bằng gạch, không hai gạch liền).' );
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
			'license'  => $existing ? (string) ( $existing['license'] ?? '' ) : '',
			'compatibility' => $existing ? (string) ( $existing['compatibility'] ?? '' ) : '',
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
		// [2026-10-17 03:59 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.117 D117-24 — a skill already in use (approved) only takes steps the cell checked green; otherwise set it back to draft first
		if ( $plan['twin'] && $plan['existing'] && 'approved' === $plan['status'] && empty( $plan['twin']['check']['valid'] ) ) {
			return new WP_Error( 'skill_check_failed', 'Skill đang dùng nên chỉ lưu được các bước đã kiểm xanh.' );
		}
		if ( $plan['existing'] ) {
			self::keep_previous( $plan['existing'] );
		}
		$w = self::mgr()->write_file( $plan['path'], $plan['raw'] );
		if ( is_wp_error( $w ) ) {
			return $w;
		}
		if ( $plan['twin'] ) {
			$w = self::mgr()->write_sidecar( self::twin_path( $plan['name'] ), (string) wp_json_encode( $plan['twin'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT ) );
			if ( is_wp_error( $w ) ) {
				return $w;
			}
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
		// [2026-10-17 03:59 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.117 D117-24 — the steps of that version come back with it
		$prev_twin = self::mgr()->read_file( '/' . self::HISTORY . '/' . $s['name'] . '.v' . max( 1, (int) $s['version'] - 1 ) . '.twin.json' );
		if ( ! is_wp_error( $prev_twin ) ) {
			self::mgr()->write_sidecar( self::twin_path( $s['name'] ), (string) $prev_twin['raw'] );
		}
		do_action( 'bizcity_skills_changed', $s['name'], $s['status'], 'undo' );
		self::invalidate_pack();
		return array( 'name' => $s['name'], 'version' => $s['version'] - 1 );
	}

	/* ── twin-skill@1 sidecar (PHASE-0.117 D117-24) ───────────────────── */
	// [2026-10-17 03:59 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.117 D117-24 — `skills/<name>.twin.json` = {skill, check}; the cell checks, this class only stores

	const TWIN_CONTRACT = 'twin-skill@1.0.0';

	/** @return array{skill: array, check: array}|null */
	public static function get_twin( string $name ) {
		$name = self::clean_name( $name );
		if ( '' === $name ) {
			return null;
		}
		$f = self::mgr()->read_file( self::twin_path( $name ) );
		if ( is_wp_error( $f ) ) {
			return null;
		}
		$j = json_decode( (string) $f['raw'], true );
		return ( is_array( $j ) && is_array( $j['skill'] ?? null ) && is_array( $j['check'] ?? null ) ) ? $j : null;
	}

	/** Stored and checked green for THIS version (and this body, when given), bytes unchanged since the check. */
	public static function twin_publishable( array $twin, int $version, ?string $body_md = null ): bool {
		$c = (array) ( $twin['check'] ?? array() );
		return ! empty( $c['valid'] )
			&& ( null === $body_md || self::body_hash( $body_md ) === (string) ( $twin['skill']['body_hash'] ?? '' ) )
			&& (int) ( $twin['skill']['version'] ?? 0 ) === $version
			&& hash_equals( (string) ( $c['skill_hash'] ?? '' ), self::twin_hash( (array) $twin['skill'] ) );
	}

	public static function twin_hash( array $skill ): string {
		return hash( 'sha256', (string) wp_json_encode( $skill, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
	}

	/** The file written next to the .md: skill pinned to this name / version / body, and the check result. */
	private static function twin_file( array $skill, string $name, int $version, string $body_hash, array $check ): array {
		$skill['skill_id']  = $name;
		$skill['version']   = $version;
		$skill['body_hash'] = $body_hash;
		return array(
			'skill' => $skill,
			'check' => array(
				'valid'      => ! empty( $check['valid'] ),
				'degraded'   => ! empty( $check['degraded'] ),
				'errors'     => max( 0, (int) ( $check['errors'] ?? 0 ) ),
				'at'         => gmdate( 'Y-m-d\TH:i:s\Z' ),
				'skill_hash' => self::twin_hash( $skill ),
			),
		);
	}

	/** What decides "the steps changed": everything but the fields this class pins. */
	private static function twin_core( array $skill ): string {
		unset( $skill['skill_id'], $skill['version'], $skill['body_hash'], $skill['status'] );
		return (string) wp_json_encode( $skill );
	}

	/** [2026-10-22 04:35 PM Johnny Chu - Chu Hoàng Anh] S127-SK10 — the sidecar lives in the skill's folder; a not-yet-converted flat skill still has `<name>.twin.json` */
	private static function twin_path( string $name ): string {
		$abs = self::mgr()->resolve_path( '/' . self::FOLDER . '/' . $name . '.twin.json' );
		if ( ! is_wp_error( $abs ) && is_file( $abs ) && ! self::is_folder_skill( $name ) ) {
			return '/' . self::FOLDER . '/' . $name . '.twin.json';
		}
		return self::folder_of( $name ) . '/twin.json';
	}

	private static function is_folder_skill( string $name ): bool {
		$abs = self::mgr()->resolve_path( self::default_path( $name ) );
		return ! is_wp_error( $abs ) && is_file( $abs );
	}

	/* ── Plans (shared by write + preview) ─────────────────────────────── */

	private static function save_plan( array $in ) {
		$name = self::clean_name( (string) ( $in['name'] ?? '' ) );
		if ( '' === $name ) {
			return new WP_Error( 'skill_invalid', 'Tên skill chỉ gồm chữ thường, số và gạch nối (1–64 ký tự, không bắt đầu / kết thúc bằng gạch, không hai gạch liền).' );
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
		// [2026-10-17 03:59 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.117 D117-24 — the steps (twin-skill@1) are part of the version: a change bumps it like the body does
		$twin = null;
		if ( array_key_exists( 'twin', $in ) ) {
			if ( ! is_array( $in['twin'] ) || self::TWIN_CONTRACT !== ( $in['twin']['contract'] ?? '' ) || ! is_array( $in['twin']['steps'] ?? null ) ) {
				return new WP_Error( 'skill_invalid', 'Các bước của skill không đúng dạng twin-skill@1.' );
			}
			$twin = $in['twin'];
			$old  = $existing ? self::get_twin( $name ) : null;
			if ( $existing && ( ! $old || self::twin_core( $old['skill'] ) !== self::twin_core( $twin ) ) ) {
				$changed[] = 'các bước';
			} elseif ( ! $existing ) {
				$changed[] = 'các bước';
			}
		}
		$meta = array(
			'scope'    => $scope,
			'tools'    => $tools,
			// [2026-10-17 09:40 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.117 S117-W2 — a NEW skill may say where it came from (template); an existing one keeps its source
			'source'   => $existing ? $existing['source'] : ( isset( $in['source'] ) && in_array( (string) $in['source'], self::SOURCES, true ) ? (string) $in['source'] : 'manual' ),
			'status'   => $existing ? $existing['status'] : 'draft',
			'version'  => $existing ? ( $changed ? (int) $existing['version'] + 1 : (int) $existing['version'] ) : 1,
			'from_job' => $existing ? $existing['from_job'] : '',
			'license'  => $existing ? (string) ( $existing['license'] ?? '' ) : '',
			'compatibility' => $existing ? (string) ( $existing['compatibility'] ?? '' ) : '',
		);
		return array(
			'name'     => $name,
			'existing' => $existing,
			'path'     => $existing ? $existing['path'] : self::default_path( $name ),
			'raw'      => self::build( $name, $desc, $meta, $body ),
			'status'   => $meta['status'],
			'version'  => $meta['version'],
			'changed'  => $changed,
			'twin'     => null === $twin ? null : self::twin_file( $twin, $name, (int) $meta['version'], self::body_hash( $body ), (array) ( $in['twin_check'] ?? array() ) ),
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
		// [2026-10-17 03:59 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.117 D117-24 — publish only the version the cell checked green (same version, same bytes as the check)
		if ( 'approved' === $st ) {
			$twin = self::get_twin( $name );
			if ( $twin && ! self::twin_publishable( $twin, (int) $existing['version'], (string) $existing['body_md'] ) ) {
				return new WP_Error( 'skill_not_checked', 'Các bước của skill chưa được kiểm xanh cho bản đang lưu.' );
			}
		}
		return array( 'existing' => $existing, 'status' => $st );
	}

	/* ── SKILL.md format ───────────────────────────────────────────────── */

	/** @return array|null Normalised skill or null when the file is not a skill (no `name`). */
	public static function from_raw( string $raw, string $path = '', int $mtime = 0 ) {
		$p    = self::parse_skill( $raw );
		$name = self::read_name( (string) ( $p['frontmatter']['name'] ?? '' ) );
		if ( '' === $name ) {
			return null;
		}
		$bz      = self::bizcity_meta( (array) ( $p['frontmatter']['metadata'] ?? array() ) );
		$updated = (string) ( $bz['updated_at'] ?? '' );
		// S127-SK10: tools = the standard `allowed-tools` (a space- or comma-separated list), else the old `metadata.bizcity.tools`
		$allowed = $p['frontmatter']['allowed-tools'] ?? null;
		$tools   = null !== $allowed ? ( is_array( $allowed ) ? $allowed : preg_split( '/[\s,]+/', (string) $allowed, -1, PREG_SPLIT_NO_EMPTY ) ) : ( $bz['tools'] ?? array() );
		return array(
			'name'        => $name,
			'description' => self::clean_desc( (string) ( $p['frontmatter']['description'] ?? '' ) ),
			'license'     => mb_substr( (string) ( $p['frontmatter']['license'] ?? '' ), 0, 120 ),
			'compatibility' => mb_substr( (string) ( $p['frontmatter']['compatibility'] ?? '' ), 0, 500 ),
			'layout'      => basename( '/' . ltrim( $path, '/' ) ) === self::SKILL_FILE ? 'folder' : 'flat',
			'tools'       => self::clean_tools( is_array( $tools ) ? $tools : array() ),
			'scope'       => self::clean_enum( (string) ( $bz['scope'] ?? '' ), self::SCOPES, 'number' ),
			'source'      => self::clean_enum( (string) ( $bz['source'] ?? '' ), self::SOURCES, 'manual' ),
			'status'      => self::clean_enum( (string) ( $bz['status'] ?? '' ), self::STATUSES, 'draft' ),
			'version'     => max( 1, (int) ( $bz['version'] ?? 1 ) ),
			'from_job'    => self::clean_job( (string) ( $bz['from_job'] ?? '' ) ),
			'path'        => $path,
			'updated_at'  => '' !== $updated ? $updated : ( $mtime > 0 ? gmdate( 'Y-m-d\TH:i:s\Z', $mtime ) : '' ),
			'bytes'       => strlen( trim( $p['content'] ) ),
			'body_md'     => trim( $p['content'] ), // normalised like clean_body(): a re-save of the same text is "no change"
			'hash'        => self::body_hash( $p['content'] ), // [2026-10-11 Johnny Chu - Chu Hoàng Anh] PHASE-0.101 S102-L2/L3 — khớp `skill_plans.body_hash` của cell (kế hoạch đã dịch còn đúng thân)
		);
	}

	/**
	 * The BizCity fields of a skill's `metadata` (pure): the standard form is a map STRING ⇒ STRING with flat keys `bizcity.status`, `bizcity.version`… (SK10);
	 * the form before SK10 is the nested `metadata.bizcity {status, version, tools[]…}` — both are read, the flat key wins.
	 */
	public static function bizcity_meta( array $metadata ): array {
		$out = isset( $metadata['bizcity'] ) && is_array( $metadata['bizcity'] ) ? $metadata['bizcity'] : array();
		foreach ( $metadata as $k => $v ) {
			if ( is_string( $k ) && 0 === strpos( $k, 'bizcity.' ) && ! is_array( $v ) ) {
				$out[ substr( $k, 8 ) ] = $v;
			}
		}
		return $out;
	}

	/**
	 * Build the SKILL.md text — [2026-10-22 04:35 PM Johnny Chu - Chu Hoàng Anh] S127-SK10: the Agent Skills standard frontmatter (Claude Code / Obsidian-readable):
	 * `name` · `description` · optional `license` / `compatibility` · `allowed-tools` (space-separated) · `metadata` = flat STRING ⇒ STRING keys `bizcity.*`.
	 */
	public static function build( string $name, string $description, array $meta, string $body ): string {
		$q       = static function ( string $s ): string {
			return '"' . str_replace( array( '\\', '"', "\n", "\r" ), array( '\\\\', '\\"', ' ', '' ), $s ) . '"';
		};
		$lines   = array( '---', 'name: ' . $name, 'description: ' . $q( $description ) );
		if ( '' !== (string) ( $meta['license'] ?? '' ) ) {
			$lines[] = 'license: ' . $q( (string) $meta['license'] );
		}
		if ( '' !== (string) ( $meta['compatibility'] ?? '' ) ) {
			$lines[] = 'compatibility: ' . $q( (string) $meta['compatibility'] );
		}
		$tools = self::clean_tools( $meta['tools'] ?? array() ); // a space-separated list: a junk "Bad Tool" must never become two tools
		if ( $tools ) {
			$lines[] = 'allowed-tools: ' . implode( ' ', $tools );
		}
		$lines[] = 'metadata:';
		$lines[] = '  bizcity.scope: ' . $q( (string) $meta['scope'] );
		$lines[] = '  bizcity.source: ' . $q( (string) $meta['source'] );
		$lines[] = '  bizcity.status: ' . $q( (string) $meta['status'] );
		$lines[] = '  bizcity.version: ' . $q( (string) (int) $meta['version'] );
		$lines[] = '  bizcity.updated_at: ' . $q( gmdate( 'Y-m-d\TH:i:s\Z' ) );
		if ( '' !== (string) ( $meta['from_job'] ?? '' ) ) {
			$lines[] = '  bizcity.from_job: ' . $q( (string) $meta['from_job'] );
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

	/* ── Create from a human title (PHASE-0.101 S102-L9) ─────────────── */

	/**
	 * "Nghiên cứu Facebook" ⇒ `nghien-cuu-facebook`: accents folded (đ ⇒ d), anything else ⇒ one hyphen, ≤ 55 chars so a
	 * `-99` suffix still fits NAME_RE. Too short ⇒ `skill`.
	 */
	public static function slugify_name( string $title ): string {
		$s = str_replace( array( 'đ', 'Đ' ), 'd', $title );
		$s = function_exists( 'remove_accents' ) ? remove_accents( $s ) : $s;
		if ( preg_match( '/[^\x00-\x7F]/', $s ) ) { // no WP / partial fold: Vietnamese vowels by hand
			$s = function_exists( 'mb_strtolower' ) ? mb_strtolower( $s, 'UTF-8' ) : $s;
			$map = array( 'a' => 'àáạảãâầấậẩẫăằắặẳẵ', 'e' => 'èéẹẻẽêềếệểễ', 'i' => 'ìíịỉĩ', 'o' => 'òóọỏõôồốộổỗơờớợởỡ', 'u' => 'ùúụủũưừứựửữ', 'y' => 'ỳýỵỷỹ' );
			foreach ( $map as $ascii => $chars ) {
				$s = (string) preg_replace( '/[' . $chars . ']/u', $ascii, $s );
			}
		}
		$s = strtolower( $s );
		$s = trim( (string) preg_replace( '/[^a-z0-9]+/', '-', $s ), '-' );
		$s = trim( substr( $s, 0, 55 ), '-' );
		return strlen( $s ) >= 2 ? $s : 'skill';
	}

	/** First free name: `base`, then `base-2`, `base-3`… — free = no skill of that name AND no file at /skills/<name>.md. */
	public static function unique_name( string $title ): string {
		$base  = self::slugify_name( $title );
		$taken = array();
		foreach ( self::skill_files() as $file ) {
			$s = self::from_raw( (string) $file['raw'], (string) $file['path'] );
			if ( $s ) {
				$taken[ $s['name'] ] = true;
			}
		}
		for ( $i = 1; $i <= 99; $i++ ) {
			$name = 1 === $i ? $base : $base . '-' . $i;
			$abs  = self::mgr()->resolve_path( self::default_path( $name ) );
			if ( ! isset( $taken[ $name ] ) && ! is_wp_error( $abs ) && ! file_exists( $abs ) ) {
				return $name;
			}
		}
		return $base . '-' . substr( md5( uniqid( '', true ) ), 0, 6 );
	}

	/**
	 * New skill file from the editor: the owner types a Vietnamese title, the server picks the unique slug and fills the
	 * template placeholders `{{name}}` / `{{title}}`. Always lands in /skills/ as a draft-or-whatever-the-template-says file;
	 * never overwrites.
	 *
	 * @param array $in {title: string, raw: string}
	 * @return array|WP_Error {path, name, title, raw}
	 */
	public static function create_from_title( array $in ) {
		$title = trim( (string) preg_replace( '/[\r\n"\\\\]+/', ' ', wp_strip_all_tags( (string) ( $in['title'] ?? '' ) ) ) );
		$title = function_exists( 'mb_substr' ) ? mb_substr( $title, 0, 80 ) : substr( $title, 0, 80 );
		if ( '' === $title ) {
			return new WP_Error( 'title_required', 'Nhập tên skill (tiếng Việt được).' );
		}
		if ( count( self::list() ) >= self::MAX_SKILLS ) {
			return new WP_Error( 'skill_limit', 'Thư viện đã đủ ' . self::MAX_SKILLS . ' skill.' );
		}
		$name = self::unique_name( $title );
		$tpl  = (string) ( $in['raw'] ?? '' );
		if ( '' === trim( $tpl ) ) {
			$tpl = self::build( '{{name}}', '{{title}}', array( 'scope' => 'site', 'tools' => array(), 'source' => 'manual', 'status' => 'draft', 'version' => 1 ), "# {{title}}\n" );
		}
		$raw  = str_replace( array( '{{name}}', '{{title}}' ), array( $name, $title ), $tpl );
		$raw  = (string) preg_replace( '/<script\b[^>]*>.*?<\/script>/is', '', $raw );
		$path = self::default_path( $name );
		$r    = self::mgr()->write_file( $path, $raw );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		self::invalidate_pack();
		return array( 'path' => $path, 'name' => $name, 'title' => $title, 'raw' => $raw );
	}

	/** 16 hex đầu sha256 của thân đã chuẩn hoá (
 ⇒ 
, trim) — CÙNG công thức với cell `skillBodyHash` (zalo-hub/src/skills/skill-plan.ts). // [2026-10-11 Johnny Chu - Chu Hoàng Anh] PHASE-0.101 S102-L2/L3 */
	public static function body_hash( string $body ): string {
		return substr( hash( 'sha256', trim( str_replace( "\r\n", "\n", $body ) ) ), 0, 16 );
	}

	public static function clean_name( string $name ): string {
		$name = strtolower( trim( $name ) );
		return preg_match( self::NAME_RE, $name ) ? $name : '';
	}

	/** a name as READ from a file: the standard rule, or the old one (a flat skill named before SK10 still lists until it is converted) */
	public static function read_name( string $name ): string {
		$name = strtolower( trim( $name ) );
		return preg_match( self::NAME_RE, $name ) || preg_match( self::LEGACY_NAME_RE, $name ) ? $name : '';
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

	/** [2026-10-22 04:35 PM Johnny Chu - Chu Hoàng Anh] S127-SK10 — the standard layout: one folder per skill, `SKILL.md` inside (resources beside it) */
	public static function default_path( string $name ): string {
		return '/' . self::FOLDER . '/' . $name . '/' . self::SKILL_FILE;
	}

	/** the folder of a skill (`/skills/<name>`) */
	public static function folder_of( string $name ): string {
		return '/' . self::FOLDER . '/' . $name;
	}

	/**
	 * What a library .md file is (pure). `skill` = a SKILL.md (or an old flat `<name>.md` not inside a skill folder) · `resource` = any other file inside a
	 * skill folder (`/skills/<name>/references/x.md`) · `history` = under `_history/`. $skill_dirs = the folders that hold a SKILL.md.
	 *
	 * @return array{kind:string,skill:string,rel:string}
	 */
	public static function classify_path( string $path, array $skill_dirs ): array {
		$path = '/' . ltrim( str_replace( '\\', '/', $path ), '/' );
		if ( 0 === strpos( $path, '/' . self::HISTORY . '/' ) ) {
			return array( 'kind' => 'history', 'skill' => '', 'rel' => '' );
		}
		if ( basename( $path ) === self::SKILL_FILE ) {
			return array( 'kind' => 'skill', 'skill' => basename( dirname( $path ) ), 'rel' => self::SKILL_FILE );
		}
		foreach ( $skill_dirs as $dir ) {
			$dir = rtrim( (string) $dir, '/' );
			if ( '' !== $dir && 0 === strpos( $path, $dir . '/' ) ) {
				return array( 'kind' => 'resource', 'skill' => basename( $dir ), 'rel' => substr( $path, strlen( $dir ) + 1 ) );
			}
		}
		return array( 'kind' => 'skill', 'skill' => '', 'rel' => '' );
	}

	/**
	 * [2026-10-22 04:41 PM Johnny Chu - Chu Hoàng Anh] S127-SK10 — a file was written / removed by the skill file routes: when it sits INSIDE a skill folder (a resource),
	 * tell the journal (`bizcity_skill_resource_changed`, one `skill.resource` row per file — owner 16:31 "Journal have all vault"). Returns the skill name or ''.
	 */
	public static function notify_resource( string $path, string $op, string $raw = '' ): string {
		$path = '/' . ltrim( str_replace( '\\', '/', $path ), '/' );
		$dirs = self::skill_dirs( self::mgr()->get_all_skills() );
		// a removed file is no longer listed: its own folder still counts when that folder holds a SKILL.md
		if ( 'delete' === $op && preg_match( '#^(/' . self::FOLDER . '/[^/]+)/#', $path, $m ) && self::is_folder_skill( basename( $m[1] ) ) ) {
			$dirs[] = $m[1];
		}
		$c = self::classify_path( $path, $dirs );
		if ( 'resource' !== $c['kind'] ) {
			return '';
		}
		do_action( 'bizcity_skill_resource_changed', $c['skill'], $c['rel'], 'delete' === $op ? 'delete' : 'write', substr( $raw, 0, 2000 ) );
		return $c['skill'];
	}

	const LESSONS_FILE = 'LESSONS.md';
	const LESSONS_MAX  = 30;

	/**
	 * [2026-10-22 04:46 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.128 S127-SK8 (T126-L6, G-SK-8; Q-SK-1 the client owns skills) — what the brain learned from a finished job about
	 * a skill. It NEVER edits the skill: the lesson is appended to the skill folder's `LESSONS.md` (a resource ⇒ its own journal row) and recorded as a `skill.lesson`
	 * row; the owner reads them in the Skill Editor and decides what goes into SKILL.md (a new version through save). The same lesson twice is one entry; at most
	 * LESSONS_MAX are kept in the file (oldest dropped — every one stays in the journal).
	 *
	 * @param array $in {name, lesson, job_ref?, rubric_add?: string[]}
	 * @return array{name:string,path:string,added:bool,count:int}|WP_Error
	 */
	public static function add_lesson( array $in ) {
		$name   = self::read_name( (string) ( $in['name'] ?? '' ) );
		$lesson = trim( (string) preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) ( $in['lesson'] ?? '' ) ) ) );
		$lesson = function_exists( 'mb_substr' ) ? mb_substr( $lesson, 0, 400 ) : substr( $lesson, 0, 400 );
		if ( '' === $name || mb_strlen( $lesson ) < 4 ) {
			return new WP_Error( 'skill_invalid', 'Cần tên skill và nội dung bài học (≥ 4 ký tự).' );
		}
		$s = self::get( $name );
		if ( ! $s ) {
			return new WP_Error( 'skill_not_found', 'Không có skill "' . $name . '".' );
		}
		$job     = preg_match( '/^job_[0-9A-Za-z]{10,40}$/', (string) ( $in['job_ref'] ?? '' ) ) ? (string) $in['job_ref'] : '';
		$rubric  = array_slice( array_values( array_filter( array_map( static function ( $r ) { return trim( wp_strip_all_tags( (string) $r ) ); }, (array) ( $in['rubric_add'] ?? array() ) ), 'strlen' ) ), 0, 3 );
		$rel     = self::LESSONS_FILE;
		$path    = 'folder' === ( $s['layout'] ?? 'flat' ) ? self::folder_of( $name ) . '/' . $rel : '';
		if ( '' === $path ) {
			return new WP_Error( 'skill_flat', 'Skill này chưa ở dạng thư mục (chưa chuyển đổi) — bài học chỉ ghi khi skill có thư mục.' );
		}
		$cur     = self::mgr()->read_file( $path );
		$text    = is_wp_error( $cur ) ? '' : (string) $cur['raw'];
		$entries = array_values( array_filter( preg_split( '/\n(?=- \[)/', preg_replace( '/\A#[^\n]*\n+/', '', $text ) ), static function ( $e ) { return '' !== trim( $e ); } ) );
		$key     = substr( hash( 'sha256', $lesson ), 0, 10 );
		$added   = false === strpos( $text, '(' . $key . ')' );
		if ( $added ) {
			$line = '- [' . gmdate( 'Y-m-d' ) . '] ' . $lesson . ( $job ? ' — việc ' . $job : '' ) . ' (' . $key . ')';
			foreach ( $rubric as $r ) {
				$line .= "\n  - tiêu chí đề xuất: " . $r;
			}
			$entries[] = $line;
			$entries   = array_slice( $entries, -self::LESSONS_MAX );
			$raw       = "# Bài học của skill " . $name . "\n\n" . implode( "\n", array_map( 'rtrim', $entries ) ) . "\n";
			$w         = self::mgr()->write_file( $path, $raw );
			if ( is_wp_error( $w ) ) {
				return $w;
			}
			self::notify_resource( $path, 'write', $raw );
			do_action( 'bizcity_skill_lesson_added', $name, $lesson, $job, $key );
		}
		return array( 'name' => $name, 'path' => $rel, 'added' => $added, 'count' => count( $entries ) );
	}

	/** @var bool one conversion check per request */
	private static $layout_checked = false;

	/** Run the one-time conversion of flat skills for this blog (per-blog option; a failure leaves the option unset so the next request retries). */
	public static function ensure_layout(): void {
		if ( self::$layout_checked ) {
			return;
		}
		self::$layout_checked = true;
		if ( function_exists( 'get_option' ) && 'v2' === get_option( self::LAYOUT_OPTION ) ) {
			return;
		}
		$r = self::migrate_layout();
		if ( empty( $r['errors'] ) && function_exists( 'update_option' ) ) {
			update_option( self::LAYOUT_OPTION, 'v2', false );
		}
	}

	/** tests: forget that the conversion ran in this request */
	public static function reset_layout_check(): void {
		self::$layout_checked = false;
	}

	/**
	 * [2026-10-22 04:35 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.128 S127-SK10 (Q-SK-4 = yes, owner 16:31) — convert every flat `<name>.md` skill to `/skills/<name>/SKILL.md`
	 * with the standard frontmatter, its sidecar to `/skills/<name>/twin.json`. Status, version, body and steps are kept byte for byte (the cell's plan stays
	 * valid: body hash + twin hash unchanged). The old file is COPIED to `_history/<name>.flat.md` before it is removed — nothing is lost. A name that breaks the
	 * standard rule (`--`, trailing `-`) is left flat and reported: renaming would cut the cell's pins to it.
	 *
	 * @return array{converted:string[],kept:array,errors:array}
	 */
	public static function migrate_layout(): array {
		$out = array( 'converted' => array(), 'kept' => array(), 'errors' => array() );
		foreach ( self::skill_files() as $file ) {
			$path = '/' . ltrim( (string) $file['path'], '/' );
			if ( basename( $path ) === self::SKILL_FILE ) {
				continue;
			}
			$s = self::from_raw( (string) $file['raw'], $path, (int) ( $file['lastModified'] ?? 0 ) );
			if ( ! $s ) {
				continue;
			}
			$name = $s['name'];
			if ( '' === self::clean_name( $name ) ) {
				$out['kept'][] = array( 'name' => $name, 'why' => 'name_not_standard' );
				continue;
			}
			$history = '/' . self::HISTORY . '/' . $name . '.flat.md';
			$copy    = self::mgr()->write_file( $history, (string) $file['raw'] );
			if ( is_wp_error( $copy ) ) {
				$out['errors'][] = array( 'name' => $name, 'code' => $copy->get_error_code() );
				continue;
			}
			if ( self::is_folder_skill( $name ) ) {
				// the folder already holds this skill: it wins, the flat copy is kept in history only
				self::mgr()->delete_file( $path );
				$out['kept'][] = array( 'name' => $name, 'why' => 'folder_exists' );
				continue;
			}
			$meta = array( 'scope' => $s['scope'], 'tools' => $s['tools'], 'source' => $s['source'], 'status' => $s['status'], 'version' => $s['version'], 'from_job' => $s['from_job'], 'license' => $s['license'] ?? '', 'compatibility' => $s['compatibility'] ?? '' );
			$w    = self::mgr()->write_file( self::default_path( $name ), self::build( $name, $s['description'], $meta, $s['body_md'] ) );
			if ( is_wp_error( $w ) ) {
				$out['errors'][] = array( 'name' => $name, 'code' => $w->get_error_code() );
				continue;
			}
			$flat_twin = '/' . self::FOLDER . '/' . $name . '.twin.json';
			$twin      = self::mgr()->read_file( $flat_twin );
			if ( ! is_wp_error( $twin ) ) {
				$tw = self::mgr()->write_sidecar( self::folder_of( $name ) . '/twin.json', (string) $twin['raw'] );
				if ( ! is_wp_error( $tw ) ) {
					self::mgr()->delete_file( $flat_twin );
				}
			}
			self::mgr()->delete_file( $path );
			$out['converted'][] = $name;
		}
		if ( $out['converted'] ) {
			self::invalidate_pack();
		}
		return $out;
	}

	/** the folders of the library that hold a SKILL.md */
	private static function skill_dirs( array $files ): array {
		$dirs = array();
		foreach ( $files as $f ) {
			$p = '/' . ltrim( str_replace( '\\', '/', (string) ( $f['path'] ?? '' ) ), '/' );
			if ( basename( $p ) === self::SKILL_FILE ) {
				$dirs[] = dirname( $p );
			}
		}
		return $dirs;
	}

	/** the skill files of the library (SKILL.md + not-yet-converted flat ones), never resources or history */
	private static function skill_files(): array {
		$all  = self::mgr()->get_all_skills();
		$dirs = self::skill_dirs( $all );
		return array_values( array_filter( $all, static function ( $f ) use ( $dirs ) {
			return 'skill' === self::classify_path( (string) $f['path'], $dirs )['kind'];
		} ) );
	}

	/**
	 * The resources of one skill (Agent Skills tier ③ "execution": files the agent reads WHEN it needs them). Paths relative to the skill folder.
	 *
	 * @return array [{path, bytes, updated_at}]
	 */
	public static function resources( string $name ): array {
		$name = self::read_name( $name );
		if ( '' === $name ) {
			return array();
		}
		$all  = self::mgr()->get_all_skills();
		$dirs = self::skill_dirs( $all );
		$out  = array();
		foreach ( $all as $f ) {
			$c = self::classify_path( (string) $f['path'], $dirs );
			if ( 'resource' === $c['kind'] && $c['skill'] === $name ) {
				$out[] = array( 'path' => $c['rel'], 'bytes' => strlen( (string) $f['raw'] ), 'updated_at' => ! empty( $f['lastModified'] ) ? gmdate( 'Y-m-d\TH:i:s\Z', (int) $f['lastModified'] ) : '' );
			}
		}
		usort( $out, static function ( $a, $b ) { return strcmp( $a['path'], $b['path'] ); } );
		return array_slice( $out, 0, 50 );
	}

	/**
	 * Read ONE resource of a skill (S127-SK7, `skills.resource`): only inside that skill's folder, only an existing .md, never SKILL.md / the sidecar.
	 *
	 * @return array{name:string,path:string,content:string,bytes:int}|WP_Error
	 */
	public static function read_resource( string $name, string $rel ) {
		$name = self::read_name( $name );
		$rel  = ltrim( str_replace( '\\', '/', $rel ), '/' );
		if ( '' === $name || '' === $rel || false !== strpos( $rel, '..' ) || self::SKILL_FILE === $rel || 'md' !== pathinfo( $rel, PATHINFO_EXTENSION ) ) {
			return new WP_Error( 'resource_invalid', 'Tài nguyên không hợp lệ (chỉ tệp .md trong thư mục của skill).' );
		}
		$f = self::mgr()->read_file( self::folder_of( $name ) . '/' . $rel );
		if ( is_wp_error( $f ) ) {
			return new WP_Error( 'resource_not_found', 'Skill "' . $name . '" không có tài nguyên ' . $rel . '.' );
		}
		$content = (string) $f['raw'];
		return array( 'name' => $name, 'path' => $rel, 'content' => substr( $content, 0, 16384 ), 'bytes' => strlen( $content ) );
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
		// [2026-10-17 03:59 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.117 D117-24 — the steps go to history on the same beat as the .md
		$twin = self::mgr()->read_file( self::twin_path( (string) $existing['name'] ) );
		if ( ! is_wp_error( $twin ) ) {
			self::mgr()->write_sidecar( '/' . self::HISTORY . '/' . $existing['name'] . '.v' . max( 1, (int) $existing['version'] ) . '.twin.json', (string) $twin['raw'] );
			if ( $old >= 1 ) {
				self::mgr()->delete_file( '/' . self::HISTORY . '/' . $existing['name'] . '.v' . $old . '.twin.json' );
			}
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
