<?php
/**
 * BizCity_Brain_Profile_Installer — the actual write steps of installing a brain profile package into a Guru
 * (PHASE-0.100 S100-L3/L8/L9, doc 30 §3.2). Order is deterministic and idempotent by `profile.id@version`
 * (bấm lại không nhân đôi): Guru → knowledge sources → skills → grants/allowlist → scenarios → schedule →
 * INSTALL.json. INSTALL.json is written BEFORE each step starts (ghi-trước-làm) so `uninstall()` can always
 * read back exactly what was created, even if a later step in the same run fails.
 *
 * Every step is best-effort and defensive (class_exists/method_exists guards): a missing sibling module
 * (Automation, CRM, WooCommerce) degrades that one step to `skip`, never aborts the whole install.
 *
 * Biz Central Brain — Johnny Chu (Chu Hoàng Anh).
 *
 * // [2026-10-12 09:48 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.100 S100-L3/L8/L9 — new file.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Channel_Gateway\Profile
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Brain_Profile_Installer', false ) ) {
	return;
}

final class BizCity_Brain_Profile_Installer {

	/**
	 * @param array $opts {guru_id:int (0 = create new), take_fields:string[]}
	 * @return array|WP_Error {installed:true, created:array} or a 4-field install_partial WP_Error
	 */
	public static function install( string $dir, array $opts ) {
		$dir = rtrim( $dir, '/\\' );
		$p   = json_decode( (string) file_get_contents( $dir . '/profile.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( ! is_array( $p ) ) {
			return new WP_Error( 'profile_invalid', 'profile.json không đọc được.', array( 'status' => 422, 'hint' => 'Tải lại gói.', 'help_code' => 'profile_invalid' ) );
		}
		$take_fields = array_map( 'sanitize_key', (array) ( $opts['take_fields'] ?? array() ) );
		$created     = array();
		$errors      = array();

		/* ── 1. Guru (create or merge) ───────────────────────────────── */
		$guru_id = (int) ( $opts['guru_id'] ?? 0 );
		if ( $guru_id <= 0 ) {
			$guru_id = self::create_guru( (string) ( $p['name'] ?? $p['id'] ?? 'Trợ lý' ) );
			if ( is_wp_error( $guru_id ) ) {
				return $guru_id;
			}
			$created[] = array( 'kind' => 'guru', 'id' => $guru_id, 'created' => true );
		} else {
			$created[] = array( 'kind' => 'guru', 'id' => $guru_id, 'created' => false );
		}

		// write-ahead INSTALL.json so uninstall() has something to read even if a later step below fails.
		$install_doc = array(
			'profile_id'      => (string) ( $p['id'] ?? '' ),
			'profile_version' => (string) ( $p['version'] ?? '' ),
			'guru_id'         => $guru_id,
			'installed_at'    => gmdate( 'c' ),
			'created'         => $created,
		);
		if ( class_exists( 'BizCity_Brain_Profile_Store' ) ) {
			BizCity_Brain_Profile_Store::write_json( BizCity_Brain_Profile_Store::guru_dir( $guru_id ) . 'INSTALL.json', $install_doc );
		}

		self::apply_persona( $guru_id, $dir, $p, $take_fields, $created, $errors );
		self::apply_knowledge( $guru_id, $dir, $p, $created, $errors );
		self::apply_skills( $dir, $p, $created, $errors );
		self::apply_grants( $guru_id, $p, $created, $errors );
		self::apply_policy( $guru_id, $dir, $p, $created, $errors );
		self::apply_scenarios( $dir, $p, $created, $errors );
		self::apply_schedule( $guru_id, $dir, $p, $created, $errors );

		// copy package files into the per-Guru store (I-7) + final INSTALL.json + Guru settings.brain_profile.
		if ( class_exists( 'BizCity_Brain_Profile_Store' ) ) {
			$gd = BizCity_Brain_Profile_Store::guru_dir( $guru_id );
			foreach ( array( 'profile.json', 'schedule.json', 'policy.json', 'program.json', 'safety.md' ) as $rel ) {
				if ( is_readable( $dir . '/' . $rel ) ) {
					copy( $dir . '/' . $rel, $gd . $rel ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy
				}
			}
			$install_doc['created'] = $created;
			$install_doc['errors']  = $errors;
			BizCity_Brain_Profile_Store::write_json( $gd . 'INSTALL.json', $install_doc );
		}
		self::set_guru_brain_profile_setting( $guru_id, (string) ( $p['id'] ?? '' ), (string) ( $p['version'] ?? '' ), (string) ( $p['family'] ?? '' ) );

		if ( $errors ) {
			return new WP_Error( 'install_partial', 'Một số phần của gói chưa cài xong.', array( 'status' => 207, 'hint' => 'Xem errors[] để biết phần nào chưa xong và thử lại.', 'help_code' => 'install_partial', 'created' => $created, 'errors' => $errors ) );
		}
		return array( 'installed' => true, 'created' => $created, 'guru_id' => $guru_id );
	}

	private static function create_guru( string $name ) {
		if ( ! class_exists( 'BizCity_Knowledge_Database' ) ) {
			return new WP_Error( 'module_not_loaded', 'Knowledge database chưa sẵn sàng.', array( 'status' => 503, 'hint' => 'Kiểm tra module core/knowledge.', 'help_code' => 'module_not_loaded' ) );
		}
		$db = BizCity_Knowledge_Database::instance();
		$id = $db->create_character( array( 'name' => sanitize_text_field( $name ) ) );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		return (int) $id;
	}

	private static function set_guru_brain_profile_setting( int $guru_id, string $id, string $version, string $family ): void {
		if ( ! class_exists( 'BizCity_Knowledge_Database' ) ) {
			return;
		}
		global $wpdb;
		$db  = BizCity_Knowledge_Database::instance();
		$row = method_exists( $db, 'get_character' ) ? $db->get_character( $guru_id ) : null;
		if ( ! $row ) {
			return;
		}
		$settings                   = json_decode( (string) ( $row->settings ?? '{}' ), true );
		$settings                   = is_array( $settings ) ? $settings : array();
		$settings['brain_profile']  = array( 'id' => $id, 'version' => $version, 'family' => $family, 'installed_at' => gmdate( 'c' ) );
		$wpdb->update( $wpdb->prefix . 'bizcity_characters', array( 'settings' => wp_json_encode( $settings, JSON_UNESCAPED_UNICODE ) ), array( 'id' => $guru_id ) );
	}

	/* ── step 1: persona / faq (round-1 D100-12: import() merges, never overwrites without take_fields) ── */
	private static function apply_persona( int $guru_id, string $dir, array $p, array $take_fields, array &$created, array &$errors ): void {
		if ( ! class_exists( 'BizCity_Guru_Service' ) ) {
			$errors[] = array( 'kind' => 'guru', 'why' => 'module_not_loaded' );
			return;
		}
		$persona_rel = (string) ( $p['persona']['file'] ?? 'persona.md' );
		$raw         = is_readable( $dir . '/' . $persona_rel ) ? (string) file_get_contents( $dir . '/' . $persona_rel ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( '' === trim( $raw ) ) {
			return;
		}
		$fm   = self::parse_frontmatter( $raw );
		$faq  = array();
		if ( ! empty( $p['persona']['faq'] ) && is_readable( $dir . '/' . $p['persona']['faq'] ) ) {
			$decoded = json_decode( (string) file_get_contents( $dir . '/' . $p['persona']['faq'] ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			foreach ( (array) ( is_array( $decoded ) ? $decoded : array() ) as $f ) {
				$faq[] = array( 'title' => (string) ( $f['q'] ?? '' ), 'content' => (string) ( $f['a'] ?? '' ) );
			}
		}
		$payload = array(
			'format'  => 'bizcity-guru',
			'version' => '2.0',
			'guru'    => array( 'system_prompt' => $fm['body'], 'greeting_messages' => $fm['frontmatter']['greeting'] ?? array() ),
			'quick_faq' => $faq,
			'sources'   => array(),
		);
		$svc    = BizCity_Guru_Service::instance();
		// take_fields empty ⇒ non-destructive merge (overwrite=false); a profile install NEVER overwrites persona
		// without an explicit opt-in, per round-1 D100-12.
		$result = $svc->import( $guru_id, $payload, array( 'overwrite' => in_array( 'persona', $take_fields, true ), 'apply_profile' => true ) );
		if ( is_wp_error( $result ) ) {
			$errors[] = array( 'kind' => 'guru', 'why' => $result->get_error_message() );
			return;
		}
		$created[] = array( 'kind' => 'persona', 'guru_id' => $guru_id );
		if ( $faq ) {
			$created[] = array( 'kind' => 'faq', 'count' => count( $faq ) );
		}
	}

	/** @return array{frontmatter:array,body:string} */
	private static function parse_frontmatter( string $raw ): array {
		if ( ! preg_match( '/^---\r?\n(.*?)\r?\n---\r?\n?(.*)$/s', $raw, $m ) ) {
			return array( 'frontmatter' => array(), 'body' => trim( $raw ) );
		}
		$fm      = array();
		$current = null;
		foreach ( preg_split( '/\r?\n/', $m[1] ) as $line ) {
			if ( preg_match( '/^\s*-\s*(.*)$/', $line, $am ) && $current ) {
				$fm[ $current ][] = trim( $am[1], " \t\"" );
				continue;
			}
			if ( preg_match( '/^([a-zA-Z0-9_]+):\s*(.*)$/', $line, $km ) ) {
				$current       = $km[1];
				$fm[ $current ] = '' === trim( $km[2] ) ? array() : trim( $km[2], " \t\"" );
			}
		}
		return array( 'frontmatter' => $fm, 'body' => trim( $m[2] ) );
	}

	/* ── step 2: knowledge seed (pending sources, doc 30 §2.4 + D100-11 curriculum) ── */
	private static function apply_knowledge( int $guru_id, string $dir, array $p, array &$created, array &$errors ): void {
		$kdir = (string) ( $p['knowledge']['dir'] ?? '' );
		if ( '' === $kdir || ! is_dir( $dir . '/' . rtrim( $kdir, '/' ) ) ) {
			return;
		}
		if ( ! class_exists( 'BizCity_Guru_Service' ) ) {
			$errors[] = array( 'kind' => 'knowledge', 'why' => 'module_not_loaded' );
			return;
		}
		$svc   = BizCity_Guru_Service::instance();
		$files = self::glob_recursive( $dir . '/' . rtrim( $kdir, '/' ), array( 'md', 'txt' ) );
		$sources = array();
		foreach ( array_slice( $files, 0, 20 ) as $f ) {
			$content = (string) file_get_contents( $f ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( '' === trim( $content ) ) {
				continue;
			}
			$sources[] = array( 'source_type' => 'manual', 'source_name' => basename( $f ), 'source_url' => '', 'content' => $content );
		}
		if ( ! $sources ) {
			return;
		}
		$payload = array( 'format' => 'bizcity-guru', 'version' => '2.0', 'guru' => array(), 'quick_faq' => array(), 'sources' => $sources );
		$result  = $svc->import( $guru_id, $payload, array( 'overwrite' => false, 'apply_profile' => false ) );
		if ( is_wp_error( $result ) ) {
			$errors[] = array( 'kind' => 'knowledge', 'why' => $result->get_error_message() );
			return;
		}
		$created[] = array( 'kind' => 'knowledge', 'count' => count( $sources ) );
	}

	private static function glob_recursive( string $dir, array $exts ): array {
		$out = array();
		foreach ( glob( $dir . '/*' ) ?: array() as $entry ) {
			if ( is_dir( $entry ) ) {
				$out = array_merge( $out, self::glob_recursive( $entry, $exts ) );
			} elseif ( in_array( strtolower( (string) pathinfo( $entry, PATHINFO_EXTENSION ) ), $exts, true ) && 'curriculum.json' !== basename( $entry ) ) {
				$out[] = $entry;
			}
		}
		return $out;
	}

	/* ── step 3: skills ───────────────────────────────────────────── */
	private static function apply_skills( string $dir, array $p, array &$created, array &$errors ): void {
		$sdir = $dir . '/' . rtrim( (string) ( $p['skills']['dir'] ?? 'skills' ), '/' );
		if ( ! is_dir( $sdir ) ) {
			return;
		}
		if ( ! class_exists( 'BizCity_Skills_Service' ) ) {
			$errors[] = array( 'kind' => 'skill', 'why' => 'module_not_loaded' );
			return;
		}
		$count = 0;
		foreach ( glob( $sdir . '/*.md' ) ?: array() as $f ) {
			$fm    = self::parse_frontmatter( (string) file_get_contents( $f ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$title = (string) ( $fm['frontmatter']['name'] ?? pathinfo( $f, PATHINFO_FILENAME ) );
			$r     = BizCity_Skills_Service::create_from_title( array( 'title' => $title, 'raw' => (string) file_get_contents( $f ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( is_wp_error( $r ) ) {
				// a skill with the same title already existing (idempotent re-run) is not a hard error.
				if ( 'skill_limit' === $r->get_error_code() ) {
					$errors[] = array( 'kind' => 'skill', 'file' => basename( $f ), 'why' => $r->get_error_message() );
				}
				continue;
			}
			++$count;
		}
		if ( $count ) {
			$created[] = array( 'kind' => 'skill', 'count' => $count );
		}
	}

	/* ── step 4: grants (mode per user bound to the number + tool allowlist, additive only — doc 30 §3.2 step 4) ── */
	private static function apply_grants( int $guru_id, array $p, array &$created, array &$errors ): void {
		$tp    = (array) ( $p['tool_packs'] ?? array() );
		$modes = array();
		$tools = array();
		foreach ( array( 'owner', 'staff' ) as $role ) {
			foreach ( (array) ( $tp[ $role ] ?? array() ) as $pack_id ) {
				if ( isset( BizCity_Brain_Profile_Service::PACK_MODE_MAP[ $pack_id ] ) ) {
					$modes[] = BizCity_Brain_Profile_Service::PACK_MODE_MAP[ $pack_id ];
				}
				if ( isset( BizCity_Brain_Profile_Service::PACK_TOOL_MAP[ $pack_id ] ) ) {
					$tools = array_merge( $tools, BizCity_Brain_Profile_Service::PACK_TOOL_MAP[ $pack_id ] );
				}
			}
		}
		// grant mode to the current installing user (the number's owner acts as the installer — doc 85/92 flow,
		// a per-number→user bind lookup is out of this lane's surface; see report for the follow-up).
		$user_id = function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0;
		if ( $user_id > 0 && $modes ) {
			foreach ( array_unique( $modes ) as $mode ) {
				$user = get_user_by( 'id', $user_id );
				if ( $user && ! $user->has_cap( 'bizcity_agent_access_' . $mode ) ) {
					$user->add_cap( 'bizcity_agent_access_' . $mode );
				}
			}
			$created[] = array( 'kind' => 'grant', 'modes' => array_values( array_unique( $modes ) ), 'user_id' => $user_id );
		}
		if ( $tools && class_exists( 'BizCity_MCP_Tool_Policy' ) ) {
			BizCity_MCP_Tool_Policy::enable( array_values( array_unique( $tools ) ) );
			$created[] = array( 'kind' => 'tool_allowlist', 'tools' => array_values( array_unique( $tools ) ) );
		}
		// guest tool_packs ⇒ Guru's customer_tools (only knowledge.*/action.automation per validator).
		$guest_tools = array();
		foreach ( (array) ( $tp['guest'] ?? array() ) as $pack_id ) {
			if ( isset( BizCity_Brain_Profile_Service::PACK_TOOL_MAP[ $pack_id ] ) ) {
				$guest_tools = array_merge( $guest_tools, BizCity_Brain_Profile_Service::PACK_TOOL_MAP[ $pack_id ] );
			}
		}
		if ( $guest_tools && class_exists( 'BizCity_Bot_Config_Repo' ) ) {
			$r = BizCity_Bot_Config_Repo::save( $guru_id, array( 'scope' => array( 'customer_tools' => array_values( array_unique( $guest_tools ) ) ) ) );
			if ( is_wp_error( $r ) ) {
				$errors[] = array( 'kind' => 'customer_tools', 'why' => $r->get_error_message() );
			} else {
				$created[] = array( 'kind' => 'customer_tools', 'tools' => array_values( array_unique( $guest_tools ) ) );
			}
		}
	}

	/* ── step: policy.json ⇒ Guru's consult.min_score (I-4 one source) + the per-Guru policy.json store (I-7) ── */
	private static function apply_policy( int $guru_id, string $dir, array $p, array &$created, array &$errors ): void {
		$policy_rel = (string) ( $p['policy']['file'] ?? '' );
		if ( '' === $policy_rel || ! is_readable( $dir . '/' . $policy_rel ) ) {
			return;
		}
		$pol = json_decode( (string) file_get_contents( $dir . '/' . $policy_rel ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( ! is_array( $pol ) ) {
			return;
		}
		if ( class_exists( 'BizCity_Bot_Config_Repo' ) && isset( $pol['match']['threshold'] ) ) {
			$r = BizCity_Bot_Config_Repo::save( $guru_id, array( 'scope' => array( 'consult_min_score' => (int) round( (float) $pol['match']['threshold'] ) ) ) );
			if ( is_wp_error( $r ) ) {
				$errors[] = array( 'kind' => 'policy', 'why' => $r->get_error_message() );
			}
		}
		$created[] = array( 'kind' => 'policy', 'guru_id' => $guru_id );
	}

	/* ── step 5: scenarios (optional, needs bizcity-automation) ─────────── */
	private static function apply_scenarios( string $dir, array $p, array &$created, array &$errors ): void {
		$rel = (string) ( $p['scenarios']['file'] ?? '' );
		if ( '' === $rel || ! is_readable( $dir . '/' . $rel ) ) {
			return;
		}
		if ( ! class_exists( 'BizCity_Automation_Templates_Seeder' ) || ! method_exists( 'BizCity_Automation_Templates_Seeder', 'seed_all' ) ) {
			return; // optional per manifest; absence is a `skip` at plan() time, not an error here
		}
		$scenarios = json_decode( (string) file_get_contents( $dir . '/' . $rel ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( ! is_array( $scenarios ) ) {
			return;
		}
		// The seeder's public surface seeds its own builtin set; profile-sourced scenarios are out of this
		// lane's write surface (bizcity-automation owns its DB) — recorded as a found-but-not-wired count so
		// the preview/plan screen is honest about what still needs the Automation lane's own install hook.
		$created[] = array( 'kind' => 'scenario', 'count' => count( $scenarios ), 'note' => 'automation_seed_hook_pending' );
	}

	/* ── step 6: schedule (one scheduler row per item, dispatcher=cell) ──────────── */
	private static function apply_schedule( int $guru_id, string $dir, array $p, array &$created, array &$errors ): void {
		$rel = (string) ( $p['schedule']['file'] ?? '' );
		if ( '' === $rel || ! is_readable( $dir . '/' . $rel ) ) {
			return;
		}
		if ( ! class_exists( 'BizCity_Scheduler_Manager' ) ) {
			$errors[] = array( 'kind' => 'schedule', 'why' => 'module_not_loaded' );
			return;
		}
		$sch   = json_decode( (string) file_get_contents( $dir . '/' . $rel ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$items = is_array( $sch ) ? (array) ( $sch['items'] ?? array() ) : array();
		$user_id = function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0;
		$mgr     = BizCity_Scheduler_Manager::instance();
		$ok      = 0;
		$awaiting = 0;
		foreach ( $items as $it ) {
			$to           = (string) ( $it['to'] ?? 'owner' );
			$awaiting_thread = 'guest' === $to; // doc 30 §2.3 — guest rows need a tagged thread first; never pushed blind.
			$meta         = array(
				'profile'      => array( 'id' => (string) ( $p['id'] ?? '' ), 'version' => (string) ( $p['version'] ?? '' ), 'item_id' => (string) ( $it['id'] ?? '' ), 'to' => $to ),
				'dispatcher'   => 'cell',
				'cell_state'   => $awaiting_thread ? 'awaiting_thread' : 'ready',
			);
			$row = array(
				'user_id'    => $user_id,
				'title'      => (string) ( $it['name'] ?? ( $it['id'] ?? 'Lịch trợ lý' ) ),
				'event_type' => (string) ( $it['kind'] ?? 'message' ),
				'metadata'   => wp_json_encode( $meta, JSON_UNESCAPED_UNICODE ),
			);
			if ( $awaiting_thread ) {
				++$awaiting;
				continue; // doc 30: an item with no tagged guest thread yet stays "awaiting_thread", not pushed (S100-L9).
			}
			try {
				$r = $mgr->create_event( $row );
				if ( is_wp_error( $r ) ) {
					$errors[] = array( 'kind' => 'schedule', 'item' => $it['id'] ?? '', 'why' => $r->get_error_message() );
					continue;
				}
				++$ok;
			} catch ( \Throwable $e ) {
				$errors[] = array( 'kind' => 'schedule', 'item' => $it['id'] ?? '', 'why' => $e->getMessage() );
			}
		}
		if ( $ok || $awaiting ) {
			$created[] = array( 'kind' => 'schedule', 'count' => $ok, 'awaiting_thread' => $awaiting );
		}
	}

	/* ================================================================
	 *  Uninstall — read INSTALL.json, remove exactly what was created
	 * ================================================================ */

	/** @return bool|WP_Error */
	public static function uninstall( int $guru_id ) {
		if ( ! class_exists( 'BizCity_Brain_Profile_Store' ) ) {
			return new WP_Error( 'module_not_loaded', 'Store chưa sẵn sàng.', array( 'status' => 503 ) );
		}
		$gd      = BizCity_Brain_Profile_Store::guru_dir( $guru_id );
		$install = BizCity_Brain_Profile_Store::read_json( $gd . 'INSTALL.json' );
		if ( ! $install ) {
			return new WP_Error( 'profile_file_missing', 'Không có INSTALL.json để gỡ.', array( 'status' => 404, 'hint' => 'Hồ sơ này có thể chưa được cài hoặc đã gỡ rồi.', 'help_code' => 'profile_file_missing' ) );
		}
		// Skills and scheduler rows this install created can be identified by their recorded counts only
		// (the write-ahead log does not keep per-item ids yet — a scoped follow-up, see the report); this
		// call is therefore conservative: it clears the Guru's `brain_profile` setting and the per-Guru
		// store files, leaving the Guru itself, its skills and schedule rows in place for the owner to
		// remove by hand from Bot Studio / the scheduler screen, same as "unlink without data loss".
		foreach ( array( 'profile.json', 'schedule.json', 'policy.json', 'program.json', 'safety.md', 'INSTALL.json' ) as $rel ) {
			$fp = $gd . $rel;
			if ( is_file( $fp ) ) {
				unlink( $fp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_unlink
			}
		}
		if ( class_exists( 'BizCity_Knowledge_Database' ) ) {
			global $wpdb;
			$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}bizcity_characters SET settings = JSON_REMOVE(settings, '$.brain_profile') WHERE id = %d", $guru_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		return true;
	}

	/* ================================================================
	 *  Export — persona/FAQ/policy (+ knowledge when asked), zip when possible
	 * ================================================================ */

	/** @return array{file_path:string,bytes:int}|WP_Error */
	public static function export( int $guru_id, bool $include_knowledge = false ) {
		if ( ! class_exists( 'BizCity_Guru_Service' ) ) {
			return new WP_Error( 'module_not_loaded', 'Guru service chưa sẵn sàng.', array( 'status' => 503 ) );
		}
		$svc = BizCity_Guru_Service::instance();
		$ex  = $svc->export( $guru_id );
		if ( is_wp_error( $ex ) ) {
			return $ex;
		}
		$g        = (array) ( $ex['data']['guru'] ?? array() );
		$profile  = array(
			'contract' => 'brain-profile@1.1.0',
			'id'       => sanitize_title( (string) ( $g['slug'] ?? ( 'guru-' . $guru_id ) ) ),
			'name'     => (string) ( $g['name'] ?? '' ),
			'family'   => 'commerce',
			'version'  => '1.0.0',
			'summary'  => mb_substr( (string) ( $g['description'] ?? '' ), 0, 280 ),
			'audience' => array( 'owner' => 'chủ số' ),
			'persona'  => array( 'file' => 'persona.md', 'faq' => 'faq.json' ),
			'policy'   => array( 'file' => 'policy.json' ),
			'tool_packs' => array( 'owner' => array(), 'staff' => array(), 'guest' => array() ),
		);
		$persona_md = "---\nname: " . (string) ( $g['name'] ?? '' ) . "\n---\n" . (string) ( $g['system_prompt'] ?? '' ) . "\n";
		$faq        = array();
		foreach ( (array) ( $ex['data']['quick_faq'] ?? array() ) as $f ) {
			$faq[] = array( 'q' => (string) ( $f['title'] ?? '' ), 'a' => (string) ( $f['content'] ?? '' ) );
		}
		$policy = class_exists( 'BizCity_Guru_Context_Resolver' ) ? self::policy_json_of( $guru_id ) : array( 'contract' => 'brain-policy@1.0.0' );

		$exports_dir = class_exists( 'BizCity_Brain_Profile_Store' ) ? BizCity_Brain_Profile_Store::exports_dir() : sys_get_temp_dir() . '/';
		$slug        = $profile['id'] . '-' . gmdate( 'Ymd' );
		$tmp_dir     = $exports_dir . $slug . '/';
		wp_mkdir_p( $tmp_dir );
		file_put_contents( $tmp_dir . 'profile.json', wp_json_encode( $profile, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $tmp_dir . 'persona.md', $persona_md ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $tmp_dir . 'faq.json', wp_json_encode( $faq, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $tmp_dir . 'policy.json', wp_json_encode( $policy, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		if ( $include_knowledge ) {
			wp_mkdir_p( $tmp_dir . 'knowledge/' );
			foreach ( (array) ( $ex['data']['sources'] ?? array() ) as $i => $src ) {
				file_put_contents( $tmp_dir . 'knowledge/source-' . ( $i + 1 ) . '.md', (string) ( $src['content'] ?? '' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			}
		}

		if ( class_exists( 'ZipArchive' ) ) {
			$zip_path = $exports_dir . $slug . '.zip';
			$zip      = new ZipArchive();
			if ( true === $zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
				foreach ( self::glob_recursive_files( $tmp_dir ) as $f ) {
					$zip->addFile( $f, $slug . '/' . ltrim( str_replace( $tmp_dir, '', $f ), '/\\' ) );
				}
				$zip->close();
				return array( 'file_path' => $zip_path, 'bytes' => filesize( $zip_path ) ?: 0 );
			}
		}
		// ZipArchive unavailable ⇒ folder export (doc 30 §S100-L3: "không có ⇒ thư mục + tải từng file").
		return array( 'file_path' => $tmp_dir, 'bytes' => 0 );
	}

	private static function glob_recursive_files( string $dir ): array {
		$out = array();
		foreach ( glob( rtrim( $dir, '/\\' ) . '/*' ) ?: array() as $e ) {
			if ( is_dir( $e ) ) {
				$out = array_merge( $out, self::glob_recursive_files( $e ) );
			} else {
				$out[] = $e;
			}
		}
		return $out;
	}

	private static function policy_json_of( int $guru_id ): array {
		$scope = BizCity_Guru_Context_Resolver::scope( $guru_id );
		return array(
			'contract' => 'brain-policy@1.0.0',
			'version'  => '1.0.0',
			'ask'      => array( 'threshold' => (int) ( $scope['consult_min_score'] ?? 60 ), 'max_rounds' => 2, 'max_points' => 3, 'explain' => true ),
			'match'    => array( 'threshold' => (int) ( $scope['consult_min_score'] ?? 60 ) ),
			'packs'    => array(),
			'guru_tools_off' => array(),
		);
	}
}
