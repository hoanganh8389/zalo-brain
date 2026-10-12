<?php
/**
 * BizCity_Brain_Profile_MCP_Tools — `profile.*` and `program.*` (PHASE-0.100 S100-L5/L11, INTERFACES I-5).
 *
 * | Tool | Kind | Who | Confirm |
 * |---|---|---|---|
 * | profile.list / profile.get | read | owner, staff | never |
 * | profile.propose | write (draft only, `_incoming/<id>/`) | owner | never |
 * | profile.set_policy | write (ask.threshold 40–90) | owner | always + preview |
 * | profile.export | write (creates a file) | owner | always |
 * | program.next | read | owner | never |
 * | program.propose | write (draft only) | owner | never |
 *
 * Mode `notebook`. `program.next` reads the serving Guru's `program.json` + the latest `track.point` journal
 * entry for `subject_hash` via `BizCity_Journal_Index::find()` (I-4) guarded by `class_exists` — the journal
 * lane builds that class in parallel; absent ⇒ first unit + `track_unavailable` note (never a hard error).
 *
 * Biz Central Brain — Johnny Chu (Chu Hoàng Anh).
 *
 * // [2026-10-12 09:48 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.100 S100-L5/L11 — new file.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Channel_Gateway\Profile
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Brain_Profile_MCP_Tools', false ) ) {
	return;
}

final class BizCity_Brain_Profile_MCP_Tools {

	const SINCE = '0.100.0';
	const CAP   = 'edit_posts';

	private static $hooked = false;

	public static function init(): void {
		if ( self::$hooked ) {
			return;
		}
		self::$hooked = true;
		add_action( 'bizcity_mcp_register_tools', array( __CLASS__, 'register_tools' ) );
	}

	public static function register_tools(): void {
		if ( ! class_exists( 'BizCity_MCP_Tool_Registry' ) ) {
			return;
		}
		$R = 'BizCity_MCP_Tool_Registry';

		// @mcp bizcity-mcp-standard@1 tool profile.list
		$R::register( 'profile.list', array(
			'title'          => 'Danh sách hồ sơ trợ lý đã nạp',
			'description'    => 'Liệt kê các hồ sơ brain-profile đã nạp vào Guru trên site này (id, phiên bản, họ).',
			'input_schema'   => array( 'type' => 'object', 'properties' => array() ),
			'output_schema'  => $R::envelope_schema( array( 'items' => array( 'type' => 'array' ) ), array( 'items' ) ),
			'read_only'      => true,
			'idempotent'     => true,
			'required_scope' => 'brain.read',
			'handler'        => array( __CLASS__, 'list_installed' ),
			'mode'           => 'notebook',
			'roles'          => array( 'owner', 'staff' ),
			'scopes'         => array( 'brain.read' ),
			'confirm'        => 'never',
			'llm_alias'      => 'profile_list',
			'fallback_pack'  => null,
			'since'          => self::SINCE,
		) );

		// @mcp bizcity-mcp-standard@1 tool profile.get
		$R::register( 'profile.get', array(
			'title'          => 'Đọc một hồ sơ trợ lý',
			'description'    => 'Trả manifest và trạng thái cài của một hồ sơ theo guru_id.',
			'input_schema'   => array( 'type' => 'object', 'required' => array( 'guru_id' ), 'properties' => array( 'guru_id' => array( 'type' => 'integer', 'minimum' => 1 ) ) ),
			'output_schema'  => $R::envelope_schema( array( 'profile' => array( 'type' => array( 'object', 'null' ) ) ), array() ),
			'read_only'      => true,
			'idempotent'     => true,
			'required_scope' => 'brain.read',
			'handler'        => array( __CLASS__, 'get' ),
			'mode'           => 'notebook',
			'roles'          => array( 'owner', 'staff' ),
			'scopes'         => array( 'brain.read' ),
			'confirm'        => 'never',
			'llm_alias'      => 'profile_get',
			'fallback_pack'  => null,
			'since'          => self::SINCE,
		) );

		// @mcp bizcity-mcp-standard@1 tool profile.propose
		$R::register( 'profile.propose', array(
			'title'          => 'Soạn nháp hồ sơ trợ lý',
			'description'    => 'Soạn một nháp brain-profile từ hội thoại (chủ duyệt sau ở /gateway/) — không ghi vào kho nào, chỉ tạo nháp.',
			'input_schema'   => array( 'type' => 'object', 'required' => array( 'family', 'name', 'persona_text' ), 'properties' => array(
				'family'         => array( 'type' => 'string', 'enum' => array( 'education', 'commerce', 'astro', 'nutrition', 'nanny', 'tasks' ) ),
				'name'           => array( 'type' => 'string', 'maxLength' => 80 ),
				'audience_guest' => array( 'type' => array( 'string', 'null' ), 'maxLength' => 200 ),
				'persona_text'   => array( 'type' => 'string', 'maxLength' => 8000 ),
				'skills'         => array( 'type' => 'array', 'items' => array( 'type' => 'object', 'properties' => array( 'title' => array( 'type' => 'string' ), 'body_md' => array( 'type' => 'string' ) ) ), 'maxItems' => 30 ),
				'schedule'       => array( 'type' => 'array', 'maxItems' => 40 ),
				'policy'         => array( 'type' => 'object', 'properties' => array( 'threshold' => array( 'type' => 'integer', 'minimum' => 40, 'maximum' => 90 ) ) ),
			) ),
			'output_schema'  => $R::envelope_schema( array( 'draft_id' => array( 'type' => 'string' ), 'open_url' => array( 'type' => 'string' ) ), array( 'draft_id' ) ),
			'read_only'      => false,
			'destructive'    => false,
			'idempotent'     => false,
			'required_scope' => 'memory.write',
			'required_cap'   => 'edit_posts', // = self::CAP (literal for the R19 marker check)
			'handler'        => array( __CLASS__, 'propose' ),
			'mode'           => 'notebook',
			'roles'          => array( 'owner' ),
			'scopes'         => array( 'memory.write' ),
			'confirm'        => 'never',
			'llm_alias'      => 'profile_propose',
			'fallback_pack'  => null,
			'since'          => self::SINCE,
		) );

		// @mcp bizcity-mcp-standard@1 tool profile.set_policy
		$R::register( 'profile.set_policy', array(
			'title'          => 'Đổi mức hỏi kỹ của trợ lý',
			'description'    => 'Đổi ask.threshold (40–90) của Guru — "từ nay hỏi kỹ hơn/ít hơn". Luôn xem trước trước khi áp.',
			'input_schema'   => array( 'type' => 'object', 'required' => array( 'guru_id', 'threshold' ), 'properties' => array(
				'guru_id'   => array( 'type' => 'integer', 'minimum' => 1 ),
				'threshold' => array( 'type' => 'integer', 'minimum' => 40, 'maximum' => 90 ),
			) ),
			'output_schema'  => $R::envelope_schema( array( 'applied' => array( 'type' => 'boolean' ), 'threshold' => array( 'type' => 'integer' ) ), array( 'applied' ) ),
			'read_only'      => false,
			'destructive'    => false,
			'idempotent'     => true,
			'required_scope' => 'memory.write',
			'required_cap'   => 'edit_posts', // = self::CAP (literal for the R19 marker check)
			'handler'        => array( __CLASS__, 'set_policy' ),
			'preview_handler' => array( __CLASS__, 'set_policy_preview' ),
			'mode'           => 'notebook',
			'roles'          => array( 'owner' ),
			'scopes'         => array( 'memory.write' ),
			'confirm'        => 'always',
			'preview'        => true,
			'llm_alias'      => 'profile_set_policy',
			'fallback_pack'  => null,
			'since'          => self::SINCE,
		) );

		// @mcp bizcity-mcp-standard@1 tool profile.export
		$R::register( 'profile.export', array(
			'title'          => 'Xuất hồ sơ trợ lý thành file',
			'description'    => 'Hoá số đang chạy thành một gói .zip để gửi vào chat hoặc chia sẻ.',
			'input_schema'   => array( 'type' => 'object', 'required' => array( 'guru_id' ), 'properties' => array(
				'guru_id'           => array( 'type' => 'integer', 'minimum' => 1 ),
				'include_knowledge' => array( 'type' => 'boolean' ),
			) ),
			'output_schema'  => $R::envelope_schema( array( 'file_url' => array( 'type' => 'string' ), 'bytes' => array( 'type' => 'integer' ) ), array( 'file_url' ) ),
			'read_only'      => false,
			'destructive'    => false,
			'idempotent'     => false,
			'required_scope' => 'memory.write',
			'required_cap'   => 'edit_posts', // = self::CAP (literal for the R19 marker check)
			'handler'        => array( __CLASS__, 'export' ),
			'mode'           => 'notebook',
			'roles'          => array( 'owner' ),
			'scopes'         => array( 'memory.write' ),
			'confirm'        => 'always',
			'llm_alias'      => 'profile_export',
			'fallback_pack'  => null,
			'since'          => self::SINCE,
		) );

		// @mcp bizcity-mcp-standard@1 tool program.next
		$R::register( 'program.next', array(
			'title'          => 'Bài/bước kế tiếp của chương trình',
			'description'    => 'Trả đơn vị kế tiếp của program.json (giáo trình/thực đơn/lịch trình...) theo tiến độ đã ghi trong sổ tay.',
			'input_schema'   => array( 'type' => 'object', 'required' => array( 'subject_hash' ), 'properties' => array(
				'guru_id'      => array( 'type' => 'integer', 'minimum' => 1 ),
				'account_id'   => array( 'type' => 'string', 'maxLength' => 80 ),
				'subject_hash' => array( 'type' => 'string', 'maxLength' => 80 ),
			) ),
			'output_schema'  => $R::envelope_schema( array( 'unit' => array( 'type' => array( 'object', 'null' ) ), 'note' => array( 'type' => 'string' ) ), array() ),
			'read_only'      => true,
			'idempotent'     => true,
			'required_scope' => 'brain.read',
			'handler'        => array( __CLASS__, 'program_next' ),
			'mode'           => 'notebook',
			'roles'          => array( 'owner' ),
			'scopes'         => array( 'brain.read' ),
			'confirm'        => 'never',
			'llm_alias'      => 'program_next',
			'fallback_pack'  => null,
			'since'          => self::SINCE,
		) );

		// @mcp bizcity-mcp-standard@1 tool program.propose
		$R::register( 'program.propose', array(
			'title'          => 'Soạn nháp chương trình',
			'description'    => 'Soạn nháp program.json (các đơn vị/bài) cho một Guru — chủ duyệt sau.',
			'input_schema'   => array( 'type' => 'object', 'required' => array( 'guru_id', 'kind', 'units' ), 'properties' => array(
				'guru_id' => array( 'type' => 'integer', 'minimum' => 1 ),
				'kind'    => array( 'type' => 'string', 'enum' => array( 'curriculum', 'meal_plan', 'routine', 'task_list', 'cycle', 'journey' ) ),
				'units'   => array( 'type' => 'array', 'maxItems' => 400 ),
			) ),
			'output_schema'  => $R::envelope_schema( array( 'saved' => array( 'type' => 'boolean' ) ), array( 'saved' ) ),
			'read_only'      => false,
			'destructive'    => false,
			'idempotent'     => true,
			'required_scope' => 'memory.write',
			'required_cap'   => 'edit_posts', // = self::CAP (literal for the R19 marker check)
			'handler'        => array( __CLASS__, 'program_propose' ),
			'mode'           => 'notebook',
			'roles'          => array( 'owner' ),
			'scopes'         => array( 'memory.write' ),
			'confirm'        => 'never',
			'llm_alias'      => 'program_propose',
			'fallback_pack'  => null,
			'since'          => self::SINCE,
		) );
	}

	/* ── handlers ─────────────────────────────────────────────────── */

	public static function list_installed( array $args, array $ctx = array() ): array {
		$items = array();
		if ( class_exists( 'BizCity_Brain_Profile_Store' ) ) {
			foreach ( BizCity_Brain_Profile_Store::all_installed() as $guru_id => $install ) {
				$items[] = array( 'guru_id' => $guru_id, 'id' => $install['profile_id'] ?? '', 'version' => $install['profile_version'] ?? '', 'installed_at' => $install['installed_at'] ?? '' );
			}
		}
		return array( 'items' => $items );
	}

	public static function get( array $args, array $ctx = array() ) {
		$guru_id = (int) ( $args['guru_id'] ?? 0 );
		if ( $guru_id <= 0 ) {
			return new WP_Error( 'invalid_param', 'guru_id required', array( 'hint' => 'Truyền guru_id hợp lệ.', 'help_code' => 'invalid_param' ) );
		}
		$installed = class_exists( 'BizCity_Brain_Profile_Store' ) ? BizCity_Brain_Profile_Store::installed_of( $guru_id ) : null;
		return array( 'profile' => $installed );
	}

	public static function propose( array $args, array $ctx = array() ) {
		if ( ! class_exists( 'BizCity_Brain_Profile_Store' ) ) {
			return new WP_Error( 'module_not_loaded', 'Profile store chưa sẵn sàng.', array( 'hint' => '', 'help_code' => 'module_not_loaded' ) );
		}
		$draft_id = 'd' . substr( md5( wp_generate_uuid4() ), 0, 10 );
		$dir      = BizCity_Brain_Profile_Store::draft_dir( $draft_id );
		$slug     = sanitize_title( (string) ( $args['name'] ?? 'tro-ly' ) ) ?: 'tro-ly';
		$profile  = array(
			'contract' => 'brain-profile@1.1.0',
			'id'       => $slug,
			'name'     => (string) ( $args['name'] ?? '' ),
			'family'   => (string) ( $args['family'] ?? 'tasks' ),
			'version'  => '0.1.0',
			'summary'  => mb_substr( (string) ( $args['persona_text'] ?? '' ), 0, 200 ),
			'audience' => array( 'owner' => 'chủ số', 'guest' => $args['audience_guest'] ?? null ),
			'persona'  => array( 'file' => 'persona.md' ),
			'tool_packs' => array( 'owner' => array(), 'staff' => array(), 'guest' => array() ),
		);
		BizCity_Brain_Profile_Store::write_json( $dir . 'profile.json', $profile );
		BizCity_Brain_Profile_Store::write_text( $dir . 'persona.md', "---\nname: " . (string) ( $args['name'] ?? '' ) . "\n---\n" . (string) ( $args['persona_text'] ?? '' ) . "\n" );
		BizCity_Brain_Profile_Store::write_json( $dir . 'DRAFT.json', array( 'from_tool' => 'profile.propose', 'principal_hash' => substr( md5( (string) ( $ctx['principal'] ?? '' ) ), 0, 12 ), 'at' => gmdate( 'c' ) ) );
		$open_url = class_exists( 'BizCity_Twin_Route' ) && method_exists( 'BizCity_Twin_Route', 'url' ) ? BizCity_Twin_Route::url( 'gateway', array( 'profile_draft' => $draft_id ) ) : '';
		return array( 'draft_id' => $draft_id, 'open_url' => $open_url );
	}

	public static function set_policy_preview( array $args, array $ctx = array() ) {
		return array( 'preview' => array( 'guru_id' => (int) ( $args['guru_id'] ?? 0 ), 'ask_threshold' => (int) ( $args['threshold'] ?? 60 ) ) );
	}

	public static function set_policy( array $args, array $ctx = array() ) {
		$guru_id   = (int) ( $args['guru_id'] ?? 0 );
		$threshold = max( 40, min( 90, (int) ( $args['threshold'] ?? 60 ) ) );
		if ( $guru_id <= 0 ) {
			return new WP_Error( 'invalid_param', 'guru_id required', array( 'hint' => '', 'help_code' => 'invalid_param' ) );
		}
		if ( class_exists( 'BizCity_Bot_Config_Repo' ) ) {
			$r = BizCity_Bot_Config_Repo::save( $guru_id, array( 'scope' => array( 'consult_min_score' => $threshold ) ) );
			if ( is_wp_error( $r ) ) {
				return $r;
			}
		}
		if ( class_exists( 'BizCity_Brain_Profile_Store' ) ) {
			$gd  = BizCity_Brain_Profile_Store::guru_dir( $guru_id );
			$pol = BizCity_Brain_Profile_Store::read_json( $gd . 'policy.json' ) ?: array( 'contract' => 'brain-policy@1.0.0', 'packs' => array(), 'guru_tools_off' => array() );
			$pol['ask']   = array_merge( (array) ( $pol['ask'] ?? array() ), array( 'threshold' => $threshold ) );
			$pol['match'] = array( 'threshold' => $threshold );
			BizCity_Brain_Profile_Store::write_json( $gd . 'policy.json', $pol );
		}
		return array( 'applied' => true, 'threshold' => $threshold );
	}

	public static function export( array $args, array $ctx = array() ) {
		if ( ! class_exists( 'BizCity_Brain_Profile_Service' ) ) {
			return new WP_Error( 'module_not_loaded', 'Profile service chưa sẵn sàng.', array( 'hint' => '', 'help_code' => 'module_not_loaded' ) );
		}
		$r = BizCity_Brain_Profile_Service::export( (int) ( $args['guru_id'] ?? 0 ), ! empty( $args['include_knowledge'] ) );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		$file_url = is_file( $r['file_path'] ) && function_exists( 'wp_nonce_url' ) ? add_query_arg( array( '_wpnonce' => wp_create_nonce( 'bizcity_brain_export_' . basename( $r['file_path'] ) ) ), rest_url( BizCity_Brain_Profile_REST::NS . BizCity_Brain_Profile_REST::BASE . '/guru/' . (int) ( $args['guru_id'] ?? 0 ) . '/export' ) ) : '';
		return array( 'file_url' => $file_url, 'bytes' => (int) ( $r['bytes'] ?? 0 ) );
	}

	public static function program_next( array $args, array $ctx = array() ) {
		$guru_id      = (int) ( $args['guru_id'] ?? 0 );
		$subject_hash = (string) ( $args['subject_hash'] ?? '' );
		if ( '' === $subject_hash ) {
			return new WP_Error( 'invalid_param', 'subject_hash required', array( 'hint' => '', 'help_code' => 'invalid_param' ) );
		}
		if ( $guru_id <= 0 && class_exists( 'BizCity_Guru_Context_Resolver' ) ) {
			$guru_id = (int) BizCity_Guru_Context_Resolver::answering_character_id( 0 );
		}
		$program = $guru_id > 0 && class_exists( 'BizCity_Brain_Profile_Store' ) ? BizCity_Brain_Profile_Store::read_json( BizCity_Brain_Profile_Store::guru_dir( $guru_id ) . 'program.json' ) : null;
		$units   = is_array( $program ) ? (array) ( $program['units'] ?? array() ) : array();
		if ( ! $units ) {
			return array( 'unit' => null, 'note' => 'no_program' );
		}
		$last_unit_id = null;
		$note         = '';
		if ( class_exists( 'BizCity_Journal_Index' ) ) {
			$found = BizCity_Journal_Index::find( array( 'owner_user_id' => (int) ( $ctx['owner_user_id'] ?? 0 ), 'kind' => 'track.point', 'subject_hash' => $subject_hash, 'limit' => 1 ) );
			$row   = is_array( $found ) ? ( $found['items'][0] ?? null ) : null;
			$card  = is_array( $row ) ? (array) ( $row['card'] ?? array() ) : array();
			$last_unit_id = $card['unit_id'] ?? null;
		} else {
			$note = 'track_unavailable';
		}
		$next = $units[0];
		if ( $last_unit_id ) {
			$idx = null;
			foreach ( $units as $i => $u ) {
				if ( ( $u['id'] ?? null ) === $last_unit_id ) {
					$idx = $i;
					break;
				}
			}
			if ( null !== $idx && isset( $units[ $idx + 1 ] ) ) {
				$next = $units[ $idx + 1 ];
			} elseif ( null !== $idx ) {
				$next = null; // program finished
				$note = $note ?: 'program_complete';
			}
		}
		return array( 'unit' => $next, 'note' => $note );
	}

	public static function program_propose( array $args, array $ctx = array() ) {
		$guru_id = (int) ( $args['guru_id'] ?? 0 );
		if ( $guru_id <= 0 || ! class_exists( 'BizCity_Brain_Profile_Store' ) ) {
			return new WP_Error( 'invalid_param', 'guru_id required', array( 'hint' => '', 'help_code' => 'invalid_param' ) );
		}
		$doc = array( 'contract' => 'program@1.0.0', 'kind' => (string) ( $args['kind'] ?? 'task_list' ), 'units' => (array) ( $args['units'] ?? array() ) );
		$ok  = BizCity_Brain_Profile_Store::write_json( BizCity_Brain_Profile_Store::guru_dir( $guru_id ) . 'program.json', $doc );
		return array( 'saved' => (bool) $ok );
	}
}
