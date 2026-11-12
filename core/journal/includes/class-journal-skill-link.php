<?php
/**
 * BizCity_Journal_Skill_Link — R-BRAIN-SKELETON BS-9: a skill is part of the CLIENT's brain. Its definition, every version and every
 * on / off are rows of the journal (kinds `skill.define` · `skill.revision` · `skill.state`, code `KN-`), so the owner sees and
 * recalls them like any other thing the brain has. The skill body itself stays in the client skill store (SKILL.md + sidecar);
 * the cell keeps only the orchestration registry + the pinned version (doc 50 §2, Q126-13 = B).
 *
 * One listener on `bizcity_skills_changed( name, status, op )` (BizCity_Skills_Service: propose · save · set_status · undo) — the
 * skill flow itself is untouched. A skill born in chat (`propose`) is a DRAFT row; only a person's `set_status approved` turns it
 * into the running version (state row `enabled: true`).
 *
 * Biz Central Brain — Johnny Chu (Chu Hoàng Anh).
 *
 * [2026-10-20 11:52 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.126 S126-K1 / W5 (doc 90 R101-01, R105-04, R117-08) — new file.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\KG_Hub
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Journal_Skill_Link', false ) ) {
	return;
}

final class BizCity_Journal_Skill_Link {

	const OPS = array( 'propose', 'propose_update', 'save', 'set_status', 'undo' );

	/** @var array<string,callable> test seams: get_skill(name), owner(), now() */
	public static $readers = array();

	private static $hooked = false;

	public static function register(): void {
		if ( self::$hooked ) {
			return;
		}
		self::$hooked = true;
		add_action( 'bizcity_skills_changed', array( __CLASS__, 'on_skill_changed' ), 20, 3 );
		add_action( 'bizcity_skill_resource_changed', array( __CLASS__, 'on_resource_changed' ), 20, 4 ); // [2026-10-22 04:41 PM Johnny Chu - Chu Hoàng Anh] S127-SK10
		add_action( 'bizcity_skill_lesson_added', array( __CLASS__, 'on_lesson_added' ), 20, 4 ); // [2026-10-22 04:46 PM Johnny Chu - Chu Hoàng Anh] S127-SK8
	}

	/**
	 * S127-SK8 — the `skill.lesson` row of one lesson the brain learned about a skill (from a finished job's 👎 / "sửa …"). Pure. review = pending until the owner
	 * puts it into a new version (applied_version), under the skill's KN- code; a job's lesson is linked to that job (role lesson, BS-4).
	 */
	public static function lesson_row( string $name, string $lesson, string $job, string $key, int $blog, int $owner, string $now ): array {
		return array(
			'owner_user_id'   => $owner,
			'kind'            => 'skill.lesson',
			'title'           => mb_substr( 'Bài học cho kỹ năng ' . $name, 0, 255 ),
			'summary'         => mb_substr( $lesson, 0, 300 ),
			'doc_date'        => substr( $now, 0, 10 ),
			'source_type'     => 'plugin',
			'source_ref'      => '' !== $job ? 'job:' . $job : 'skill:' . $name,
			'idempotency_key' => 'kn:' . $blog . ':' . $name . ':lesson:' . $key,
			'origin'          => array( 'surface' => 'plugin:skills', 'ref' => 'lesson' ),
			'card'            => array( 'skill_id' => $name, 'lesson' => mb_substr( $lesson, 0, 400 ), 'source_ref' => '' !== $job ? 'job:' . $job : '', 'review' => 'pending', 'applied_version' => '' ),
			'tags'            => array( 'skill-' . $name, 'skill-lesson' ),
			'by'              => array( 'role' => 'system' ),
		);
	}

	public static function on_lesson_added( $name, $lesson = '', $job = '', $key = '' ): void {
		if ( ! class_exists( 'BizCity_Journal_Index' ) || '' === (string) $name || '' === (string) $lesson ) {
			return;
		}
		$owner = self::owner();
		if ( $owner <= 0 ) {
			return;
		}
		$now  = isset( self::$readers['now'] ) ? (string) call_user_func( self::$readers['now'] ) : gmdate( 'Y-m-d\TH:i:s' );
		$blog = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
		$row  = self::lesson_row( (string) $name, (string) $lesson, (string) $job, (string) $key, $blog, $owner, $now );
		if ( null !== BizCity_Journal_Index::get_by_idempotency_key( $row['idempotency_key'], $owner ) ) {
			return;
		}
		$def = BizCity_Journal_Index::get_by_idempotency_key( self::define_key( $blog, (string) $name ), $owner );
		if ( is_array( $def ) ) {
			$row['derived_from'] = array( (int) $def['entry_id'] );
		}
		$added = BizCity_Journal_Index::add( $row );
		if ( is_array( $added ) && '' !== (string) $job && class_exists( 'BizCity_Journal_Job_Link' ) ) {
			BizCity_Journal_Job_Link::attach( $added, $owner, array( 'job_id' => (string) $job, 'role' => 'lesson' ) );
		}
	}

	/* ── S127-SK10: every file of a skill folder is a row (owner 16:31 "Journal have all vault") ── */

	public static function resource_key( int $blog, string $name, string $rel ): string {
		return 'kn:' . $blog . ':' . $name . ':res:' . substr( hash( 'sha256', $rel ), 0, 12 );
	}

	/**
	 * The journal row of ONE file inside a skill folder (`skills/<name>/references/x.md`): kind `skill.resource`, under the skill's KN- code. Pure.
	 *
	 * @param string $op write · delete
	 */
	public static function resource_row( string $name, string $rel, string $op, int $bytes, string $excerpt, int $blog, int $owner, string $now ): array {
		return array(
			'owner_user_id'   => $owner,
			'kind'            => 'skill.resource',
			'title'           => mb_substr( 'Tài liệu kỹ năng ' . $name . ': ' . $rel, 0, 255 ),
			'summary'         => 'delete' === $op ? 'Đã gỡ khỏi thư mục kỹ năng.' : mb_substr( trim( (string) preg_replace( '/\s+/', ' ', $excerpt ) ), 0, 300 ),
			'doc_date'        => substr( $now, 0, 10 ),
			'source_type'     => 'plugin',
			'source_ref'      => 'skill:' . $name . '/' . $rel,
			'idempotency_key' => self::resource_key( $blog, $name, $rel ),
			'origin'          => array( 'surface' => 'plugin:skills', 'ref' => 'resource' ),
			'card'            => array( 'skill_id' => $name, 'path' => $rel, 'bytes' => max( 0, $bytes ), 'op' => 'delete' === $op ? 'delete' : 'write' ),
			'tags'            => array( 'skill-' . $name, 'skill-resource' ),
			'by'              => array( 'role' => 'owner' ),
		);
	}

	/** `bizcity_skill_resource_changed( name, rel, op, excerpt )` — fired by the skill file routes when a file INSIDE a skill folder is written or removed */
	public static function on_resource_changed( $name, $rel = '', $op = 'write', $excerpt = '' ): void {
		if ( ! class_exists( 'BizCity_Journal_Index' ) || '' === (string) $name || '' === (string) $rel ) {
			return;
		}
		$owner = self::owner();
		if ( $owner <= 0 ) {
			return;
		}
		$now  = isset( self::$readers['now'] ) ? (string) call_user_func( self::$readers['now'] ) : gmdate( 'Y-m-d\TH:i:s' );
		$blog = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
		$row  = self::resource_row( (string) $name, (string) $rel, (string) $op, strlen( (string) $excerpt ), (string) $excerpt, $blog, $owner, $now );
		$def  = BizCity_Journal_Index::get_by_idempotency_key( self::define_key( $blog, (string) $name ), $owner );
		if ( is_array( $def ) ) {
			$row['derived_from'] = array( (int) $def['entry_id'] );
		}
		$had = BizCity_Journal_Index::get_by_idempotency_key( $row['idempotency_key'], $owner );
		if ( null === $had ) {
			BizCity_Journal_Index::add( $row );
		} else {
			BizCity_Journal_Index::file( (int) $had['entry_id'], $owner, array( 'card' => $row['card'], 'summary' => $row['summary'] ) );
		}
	}

	/* ── pure ─────────────────────────────────────────────────────────── */

	public static function define_key( int $blog, string $name ): string {
		return 'kn:' . $blog . ':' . $name;
	}

	/**
	 * The journal rows one skill change writes. Pure.
	 *
	 * @param array  $skill {name, description, status, version, tools[], scope, source, from_job}
	 * @param string $op    propose · propose_update · save · set_status · undo
	 * @param int    $by    the WP user who did it (0 = the cell / a job)
	 * @return array{define:array,event:array|null}
	 */
	public static function rows_for( array $skill, string $op, int $blog, int $owner, int $by, string $now ): array {
		$name    = (string) ( $skill['name'] ?? '' );
		$version = max( 1, (int) ( $skill['version'] ?? 1 ) );
		$status  = (string) ( $skill['status'] ?? 'draft' );
		$tools   = array_slice( array_values( array_map( 'strval', (array) ( $skill['tools'] ?? array() ) ) ), 0, 12 );
		$from    = (string) ( $skill['from_job'] ?? '' );
		$by_role = $by > 0 ? 'owner' : 'system';
		$define  = array(
			'owner_user_id'   => $owner,
			'kind'            => 'skill.define',
			'title'           => 'Kỹ năng: ' . $name,
			'summary'         => mb_substr( (string) ( $skill['description'] ?? '' ), 0, 300 ),
			'doc_date'        => substr( $now, 0, 10 ),
			'source_type'     => 'plugin',
			'source_ref'      => 'skill:' . $name,
			'idempotency_key' => self::define_key( $blog, $name ),
			'origin'          => array( 'surface' => 'plugin:skills', 'ref' => 'skill:' . $name ),
			'card'            => array( 'skill_id' => $name, 'name' => $name, 'version' => $version, 'permissions' => implode( ',', $tools ), 'executor' => (string) ( $skill['source'] ?? '' ), 'status' => $status, 'scope' => (string) ( $skill['scope'] ?? '' ) ),
			'tags'            => array( 'skill', 'skill-' . $name ),
			'by'              => array( 'role' => $by_role ),
		);
		$event = null;
		if ( in_array( $op, array( 'propose', 'propose_update', 'save', 'undo' ), true ) ) {
			$n     = 'undo' === $op ? max( 1, $version - 1 ) : $version;
			$event = array(
				'owner_user_id'   => $owner,
				'kind'            => 'skill.revision',
				'title'           => 'Kỹ năng ' . $name . ' — bản ' . $n . ( 'undo' === $op ? ' (hoàn tác)' : '' ),
				'summary'         => 'propose' === $op || 'propose_update' === $op ? 'Bản nháp trợ lý đề xuất — chờ chủ duyệt.' : ( 'undo' === $op ? 'Trở lại bản trước.' : 'Chủ sửa và lưu.' ),
				'doc_date'        => substr( $now, 0, 10 ),
				'source_type'     => 'plugin',
				'source_ref'      => 'skill:' . $name . '@v' . $n,
				// undo makes a NEW event (the same version may come back more than once)
				'idempotency_key' => 'kn:' . $blog . ':' . $name . ':v' . $n . ( 'undo' === $op ? ':undo:' . preg_replace( '/\D/', '', $now ) : '' ),
				'origin'          => array( 'surface' => 'plugin:skills', 'ref' => $op ),
				'card'            => array( 'skill_id' => $name, 'version' => $n, 'prev_version' => max( 0, $n - 1 ), 'change' => $op, 'eval' => '' ),
				'version'         => array( 'n' => $n ),
				'tags'            => array( 'skill-' . $name ),
				'by'              => array( 'role' => $by_role ),
			);
		} elseif ( 'set_status' === $op ) {
			$labels = array( 'approved' => 'bật (đang chạy)', 'disabled' => 'tắt', 'draft' => 'về nháp' );
			$event  = array(
				'owner_user_id'   => $owner,
				'kind'            => 'skill.state',
				'title'           => 'Kỹ năng ' . $name . ': ' . ( $labels[ $status ] ?? $status ),
				'doc_date'        => substr( $now, 0, 10 ),
				'source_type'     => 'plugin',
				'source_ref'      => 'skill:' . $name . '@v' . $version,
				'idempotency_key' => 'kn:' . $blog . ':' . $name . ':s:' . preg_replace( '/\D/', '', $now ),
				'origin'          => array( 'surface' => 'plugin:skills', 'ref' => 'set_status' ),
				'card'            => array( 'skill_id' => $name, 'enabled' => 'approved' === $status, 'scope' => (string) ( $skill['scope'] ?? '' ), 'by' => $by, 'version' => $version, 'status' => $status ),
				'tags'            => array( 'skill-' . $name ),
				'by'              => array( 'role' => $by_role ),
			);
		}
		// a draft the cell proposed while running a job belongs to that job (BS-4, role lesson: what the brain learned from the run)
		if ( null !== $event && 1 === preg_match( '/^job_[0-9A-Za-z]{10,40}$/', $from ) && in_array( $op, array( 'propose', 'propose_update' ), true ) ) {
			$event['job'] = array( 'job_id' => $from, 'role' => 'lesson' );
		}
		return array( 'define' => $define, 'event' => $event );
	}

	/* ── hook ─────────────────────────────────────────────────────────── */

	public static function on_skill_changed( $name, $status = '', $op = '' ): void {
		if ( ! class_exists( 'BizCity_Journal_Index' ) || ! in_array( (string) $op, self::OPS, true ) ) {
			return;
		}
		$skill = isset( self::$readers['get_skill'] ) ? call_user_func( self::$readers['get_skill'], (string) $name ) : ( class_exists( 'BizCity_Skills_Service' ) ? BizCity_Skills_Service::get( (string) $name ) : null );
		if ( ! is_array( $skill ) || '' === (string) ( $skill['name'] ?? '' ) ) {
			return;
		}
		$owner = self::owner();
		if ( $owner <= 0 ) {
			return; // ZBC-3: every row has a person; a site without one records nothing (the skill flow still works)
		}
		$by   = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		$now  = isset( self::$readers['now'] ) ? (string) call_user_func( self::$readers['now'] ) : gmdate( 'Y-m-d\TH:i:s' );
		$blog = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
		$rows = self::rows_for( $skill, (string) $op, $blog, $owner, $by, $now );
		$def  = BizCity_Journal_Index::get_by_idempotency_key( $rows['define']['idempotency_key'], $owner );
		if ( null === $def ) {
			$def = BizCity_Journal_Index::add( $rows['define'] );
		} else {
			$def = BizCity_Journal_Index::file( (int) $def['entry_id'], $owner, array( 'card' => $rows['define']['card'], 'summary' => $rows['define']['summary'] ) );
		}
		if ( null === $rows['event'] || ! is_array( $def ) ) {
			return;
		}
		$event = $rows['event'];
		$root  = (string) ( $def['ref_code'] ?? '' );
		if ( isset( $event['version'] ) && '' !== $root ) {
			$event['version']['root_ref'] = $root;
		}
		$event['derived_from'] = array( (int) $def['entry_id'] );
		$job = $event['job'] ?? null;
		unset( $event['job'] );
		// [2026-10-21 11:32 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.126 R100-09 — a skill written while a site flow runs a job (profile install) is that job's output
		if ( null === $job && class_exists( 'BizCity_Journal_Job_Link' ) && method_exists( 'BizCity_Journal_Job_Link', 'ambient' ) ) {
			$job = BizCity_Journal_Job_Link::ambient();
		}
		$row = BizCity_Journal_Index::add( $event );
		if ( is_array( $row ) && is_array( $job ) && class_exists( 'BizCity_Journal_Job_Link' ) ) {
			BizCity_Journal_Job_Link::attach( $row, $owner, $job );
		}
	}

	/** The person a skill row belongs to: the site owner (a skill is the site's), else the current user. */
	private static function owner(): int {
		if ( isset( self::$readers['owner'] ) ) {
			return (int) call_user_func( self::$readers['owner'] );
		}
		$o = class_exists( 'BizCity_Twin_Web_Home' ) ? (int) BizCity_Twin_Web_Home::owner_user_id() : 0;
		if ( $o <= 0 && function_exists( 'get_current_user_id' ) ) {
			$o = (int) get_current_user_id();
		}
		return $o;
	}
}
