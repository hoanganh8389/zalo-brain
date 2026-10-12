<?php
/**
 * BizCity_Brain_Profile_Service — plan / install / uninstall / export a `brain-profile@1` package into a Guru
 * (PHASE-0.100 S100-L3/L8/L9, doc 30 §3). "A profile is a folder with a manifest, not a runtime" — installing it
 * means writing into the six stores Zalo Brain already has (Guru · knowledge · skills · scheduler · scenarios ·
 * permissions/policy); nothing new is invented here.
 *
 * `validate()` is a PHP port of the essential rules the Node validator (`bin/validate-brain-profile.mjs`) already
 * enforces on sample packs and fixtures — kept intentionally smaller (no shell-out, R-BC-3): contract/shape,
 * `tool_packs` vocabulary + guest restriction, `policy.json` weight sums + threshold range, schedule cron density
 * (minute-stepper, same rule as the JS port), safety.md required for sensitive families. A package that already
 * passed the Node validator (sample packs, CI) will always pass this port too.
 *
 * Biz Central Brain — Johnny Chu (Chu Hoàng Anh).
 *
 * // [2026-10-12 09:48 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.100 S100-L3/L8/L9 — new file.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Channel_Gateway\Profile
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Brain_Profile_Service', false ) ) {
	return;
}

final class BizCity_Brain_Profile_Service {

	const CONTRACT           = 'brain-profile@1.1.0';
	const TOOL_PACK_IDS      = array(
		'knowledge.notebook', 'knowledge.skills', 'knowledge.products', 'knowledge.data', 'knowledge.memory',
		'knowledge.program', 'knowledge.astro', 'knowledge.lesson',
		'action.order', 'action.booking', 'action.publish', 'action.crm', 'action.automation', 'action.research',
	);
	const SENSITIVE_FAMILIES = array( 'astro', 'nutrition', 'nanny' );
	const FAMILIES           = array( 'education', 'commerce', 'astro', 'nutrition', 'nanny', 'tasks' );
	/** doc 30 §3.3 — tool_pack id ⇒ agent-mode-access mode id a grant unlocks (owner/staff only) */
	const PACK_MODE_MAP      = array(
		'knowledge.notebook' => 'notebook',
		'knowledge.skills'   => 'notebook',
		'knowledge.data'     => 'customers', // closest existing CRM-gated mode; sales/orders/stock granted per-pack below
		'action.order'       => 'orders',
		'action.booking'     => 'booking',
		'action.publish'     => 'channel',
		'action.crm'         => 'customers',
		'action.automation'  => 'automation',
		'action.research'    => 'deep_analysis',
		'knowledge.lesson'   => 'notebook',
		'knowledge.astro'    => 'astro_self',
	);
	/** doc 30 §3.3 — tool_pack id ⇒ MCP tool names to allowlist-enable (additive only, S100-L8) */
	const PACK_TOOL_MAP      = array(
		'knowledge.notebook'  => array( 'knowledge.search' ),
		'knowledge.skills'    => array( 'skills.list', 'skills.get' ),
		'knowledge.products'  => array( 'commerce.search_products', 'knowledge.search' ),
		'knowledge.data'      => array( 'sales.summary', 'order.status', 'inventory.check', 'crm.customer.lookup' ),
		'action.order'        => array( 'order.create', 'inventory.reserve', 'inventory.release' ),
		'action.booking'      => array( 'booking.create', 'booking.list' ),
		'action.publish'      => array( 'fb.post.create', 'site.content.create' ),
		'action.crm'          => array( 'crm.task.create', 'crm.customer.update', 'staff.notify' ),
		'action.automation'   => array( 'automation.list_scenarios', 'automation.run_scenario' ),
		'action.research'     => array( 'request_deep_analysis', 'request_harness' ),
		'knowledge.lesson'    => array( 'lesson.next', 'lesson.progress.set' ),
		'knowledge.astro'     => array( 'astro.self_profile', 'astro.transit', 'astro.links', 'coach.list_templates', 'coach.list_maps' ),
	);

	/* ================================================================
	 *  Validate (PHP port of bin/validate-brain-profile.mjs's hard rules)
	 * ================================================================ */

	/** @return array{ok:bool,findings:array} */
	public static function validate( string $dir ): array {
		$findings = array();
		$add      = function ( string $code, array $detail = array() ) use ( &$findings ) {
			$findings[] = array_merge( array( 'code' => $code ), $detail );
		};
		$pf = rtrim( $dir, '/\\' ) . '/profile.json';
		if ( ! is_readable( $pf ) ) {
			$add( 'profile_file_missing', array( 'file' => 'profile.json' ) );
			return array( 'ok' => false, 'findings' => $findings );
		}
		$p = json_decode( (string) file_get_contents( $pf ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( ! is_array( $p ) ) {
			$add( 'profile_invalid', array( 'error' => 'invalid JSON' ) );
			return array( 'ok' => false, 'findings' => $findings );
		}
		if ( ! preg_match( '/^brain-profile@1\.\d+\.\d+$/', (string) ( $p['contract'] ?? '' ) ) ) {
			$add( 'profile_invalid', array( 'field' => 'contract' ) );
		}
		foreach ( array( 'id', 'name', 'family', 'version', 'summary', 'audience', 'tool_packs' ) as $k ) {
			if ( ! isset( $p[ $k ] ) ) {
				$add( 'profile_invalid', array( 'field' => $k, 'why' => 'required' ) );
			}
		}
		$family = (string) ( $p['family'] ?? '' );
		if ( $family && ! in_array( $family, self::FAMILIES, true ) ) {
			$add( 'profile_invalid', array( 'field' => 'family', 'value' => $family ) );
		}
		if ( $family && in_array( $family, self::SENSITIVE_FAMILIES, true ) ) {
			$safety = rtrim( $dir, '/\\' ) . '/' . ( $p['safety_md'] ?? 'safety.md' );
			if ( ! is_readable( $safety ) ) {
				$add( 'profile_invalid', array( 'field' => 'safety_md', 'why' => 'required for sensitive family ' . $family ) );
			}
		}
		// tool_packs vocabulary + guest restriction (R-AUTOMATION-PERMISSION)
		$tp = (array) ( $p['tool_packs'] ?? array() );
		foreach ( array( 'owner', 'staff', 'guest' ) as $role ) {
			foreach ( (array) ( $tp[ $role ] ?? array() ) as $id ) {
				if ( ! in_array( $id, self::TOOL_PACK_IDS, true ) ) {
					$add( 'unknown_tool_pack', array( 'role' => $role, 'pack' => $id ) );
				}
				if ( 'guest' === $role && 0 !== strpos( (string) $id, 'knowledge.' ) && 'action.automation' !== $id ) {
					$add( 'guest_action_pack_forbidden', array( 'pack' => $id ) );
				}
			}
		}
		// policy.json
		$policyRel = (string) ( $p['policy']['file'] ?? '' );
		if ( $policyRel ) {
			$pol = json_decode( self::read_file( $dir, $policyRel ), true );
			if ( is_array( $pol ) ) {
				$ask = (array) ( $pol['ask'] ?? array() );
				foreach ( array( 'threshold' => array( 40, 90 ), 'max_rounds' => array( 0, 2 ), 'max_points' => array( 1, 3 ) ) as $field => $range ) {
					if ( isset( $ask[ $field ] ) && ( $ask[ $field ] < $range[0] || $ask[ $field ] > $range[1] ) ) {
						$add( 'policy_threshold_out_of_range', array( 'field' => 'ask.' . $field, 'value' => $ask[ $field ] ) );
					}
				}
				foreach ( (array) ( $pol['packs'] ?? array() ) as $pack_id => $v ) {
					if ( ! is_array( $v ) || ! isset( $v['need'] ) ) {
						continue;
					}
					$sum = array_sum( array_map( 'floatval', (array) $v['need'] ) );
					if ( abs( $sum - 100 ) > 1e-6 ) {
						$add( 'policy_weights_not_100', array( 'pack' => $pack_id, 'sum' => $sum ) );
					}
				}
			}
		}
		// schedule.json — cron density (minute-stepper, same rule the JS port uses)
		$scheduleRel = (string) ( $p['schedule']['file'] ?? '' );
		if ( $scheduleRel ) {
			$sch = json_decode( self::read_file( $dir, $scheduleRel ), true );
			if ( is_array( $sch ) ) {
				foreach ( (array) ( $sch['items'] ?? array() ) as $it ) {
					$cron = (string) ( $it['cron'] ?? '' );
					if ( '' === $cron ) {
						continue;
					}
					$gap = self::cron_next_gap_minutes( $cron );
					if ( null === $gap ) {
						$add( 'schedule_too_dense', array( 'id' => $it['id'] ?? '', 'why' => 'invalid cron' ) );
					} elseif ( $gap < 15 ) {
						$add( 'schedule_too_dense', array( 'id' => $it['id'] ?? '', 'gap_minutes' => $gap ) );
					}
				}
			}
		}
		return array( 'ok' => empty( $findings ), 'findings' => $findings );
	}

	private static function read_file( string $dir, string $rel ): string {
		$fp = rtrim( $dir, '/\\' ) . '/' . $rel;
		return is_readable( $fp ) ? (string) file_get_contents( $fp ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	}

	/** Minute-stepper cron gap (PHP port of the JS validator's cronNextGapMinutes), capped at ~1 year. */
	private static function cron_next_gap_minutes( string $expr ) {
		$parts = preg_split( '/\s+/', trim( $expr ) );
		if ( 5 !== count( $parts ) ) {
			return null;
		}
		$ranges = array( array( 0, 59 ), array( 0, 23 ), array( 1, 31 ), array( 1, 12 ), array( 0, 6 ) );
		$fields = array();
		foreach ( $parts as $i => $field ) {
			$set = self::cron_field_set( $field, $ranges[ $i ][0], $ranges[ $i ][1] );
			if ( null === $set ) {
				return null;
			}
			$fields[] = $set;
		}
		$now   = time();
		$t     = $now - ( $now % 60 ) + 60;
		$found = array();
		for ( $i = 0; $i < 60 * 24 * 370 && count( $found ) < 2; $i++, $t += 60 ) {
			$min = (int) gmdate( 'i', $t );
			$hr  = (int) gmdate( 'G', $t );
			$dom = (int) gmdate( 'j', $t );
			$mon = (int) gmdate( 'n', $t );
			$dow = (int) gmdate( 'w', $t );
			if ( in_array( $min, $fields[0], true ) && in_array( $hr, $fields[1], true ) && in_array( $dom, $fields[2], true ) && in_array( $mon, $fields[3], true ) && in_array( $dow, $fields[4], true ) ) {
				$found[] = $t;
			}
		}
		return count( $found ) >= 2 ? ( $found[1] - $found[0] ) / 60 : null;
	}

	private static function cron_field_set( string $field, int $min, int $max ) {
		$out = array();
		foreach ( explode( ',', $field ) as $part ) {
			if ( ! preg_match( '/^(\*|\d+)(?:-(\d+))?(?:\/(\d+))?$/', $part, $m ) ) {
				return null;
			}
			$lo   = '*' === $m[1] ? $min : (int) $m[1];
			$hi   = isset( $m[2] ) && '' !== $m[2] ? (int) $m[2] : ( '*' === $m[1] ? $max : $lo );
			$step = isset( $m[3] ) && '' !== $m[3] ? (int) $m[3] : 1;
			if ( $lo < $min || $hi > $max || $step <= 0 ) {
				return null;
			}
			for ( $v = $lo; $v <= $hi; $v += $step ) {
				$out[] = $v;
			}
		}
		return $out;
	}

	/* ================================================================
	 *  Templates / manifest reads
	 * ================================================================ */

	/** @return array{items:array} sample packs under examples/brain-profiles/* (bizcity-twin-ai ships them read-only) */
	public static function list_templates(): array {
		$items = array();
		$base  = defined( 'BIZCITY_TWIN_AI_DIR' ) ? BIZCITY_TWIN_AI_DIR . 'examples/brain-profiles/' : '';
		if ( '' === $base || ! is_dir( $base ) ) {
			return array( 'items' => $items );
		}
		foreach ( glob( $base . '*', GLOB_ONLYDIR ) ?: array() as $d ) {
			$pf = rtrim( $d, '/\\' ) . '/profile.json';
			if ( ! is_readable( $pf ) ) {
				continue;
			}
			$p = json_decode( (string) file_get_contents( $pf ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( ! is_array( $p ) ) {
				continue;
			}
			$missing = self::missing_requirements( $p );
			$items[] = array(
				'id'      => (string) ( $p['id'] ?? basename( $d ) ),
				'name'    => (string) ( $p['name'] ?? '' ),
				'family'  => (string) ( $p['family'] ?? '' ),
				'version' => (string) ( $p['version'] ?? '' ),
				'summary' => (string) ( $p['summary'] ?? '' ),
				'requires' => (array) ( $p['requires'] ?? array() ),
				'missing' => $missing,
				'_dir'    => $d, // internal; stripped by REST layer
			);
		}
		return array( 'items' => $items );
	}

	/** @return string[] human-readable reasons a template is not fully usable on this site yet */
	private static function missing_requirements( array $p ): array {
		$missing = array();
		foreach ( (array) ( $p['requires']['plugins'] ?? array() ) as $slug => $need ) {
			if ( 'required' !== $need ) {
				continue;
			}
			$found = false;
			foreach ( get_option( 'active_plugins', array() ) as $ap ) {
				if ( false !== strpos( $ap, (string) $slug ) ) {
					$found = true;
					break;
				}
			}
			if ( ! $found ) {
				$missing[] = 'Cần plugin ' . $slug;
			}
		}
		return $missing;
	}

	/* ================================================================
	 *  Plan
	 * ================================================================ */

	/** @return array{create:array,skip:array,missing:array,diff:array} */
	public static function plan( string $dir, int $guru_id = 0 ): array {
		$pf = rtrim( $dir, '/\\' ) . '/profile.json';
		$p  = is_readable( $pf ) ? json_decode( (string) file_get_contents( $pf ), true ) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( ! is_array( $p ) ) {
			return array( 'create' => array(), 'skip' => array(), 'missing' => array( array( 'what' => 'profile.json', 'fix' => 'Kiểm tra lại gói đã tải lên', 'who_fixes' => 'owner' ) ), 'diff' => array() );
		}
		$create  = array();
		$skip    = array();
		$missing = self::missing_requirements( $p );
		if ( is_readable( rtrim( $dir, '/\\' ) . '/' . ( $p['persona']['file'] ?? 'persona.md' ) ) ) {
			$create[] = array( 'kind' => 'guru', 'label' => 'Guru (persona + lời chào)', 'count' => 1 );
		}
		if ( ! empty( $p['persona']['faq'] ) && is_readable( rtrim( $dir, '/\\' ) . '/' . $p['persona']['faq'] ) ) {
			$faq      = json_decode( self::read_file( $dir, (string) $p['persona']['faq'] ), true );
			$create[] = array( 'kind' => 'faq', 'label' => 'Câu hỏi thường gặp', 'count' => is_array( $faq ) ? count( $faq ) : 0 );
		}
		$skills_dir = rtrim( $dir, '/\\' ) . '/' . ( $p['skills']['dir'] ?? 'skills' );
		if ( is_dir( $skills_dir ) ) {
			$files = glob( $skills_dir . '/*.md' ) ?: array();
			if ( $files ) {
				$create[] = array( 'kind' => 'skill', 'label' => 'Cách làm (skill)', 'count' => count( $files ) );
			}
		}
		$scheduleRel = (string) ( $p['schedule']['file'] ?? '' );
		if ( $scheduleRel && is_readable( rtrim( $dir, '/\\' ) . '/' . $scheduleRel ) ) {
			$sch      = json_decode( self::read_file( $dir, $scheduleRel ), true );
			$items    = is_array( $sch ) ? (array) ( $sch['items'] ?? array() ) : array();
			$create[] = array( 'kind' => 'schedule', 'label' => 'Lịch tự động', 'count' => count( $items ) );
		}
		$scenariosRel = (string) ( $p['scenarios']['file'] ?? '' );
		if ( $scenariosRel ) {
			if ( ! class_exists( 'BizCity_Automation_Templates_Seeder' ) ) {
				$skip[] = array( 'kind' => 'scenario', 'label' => 'Kịch bản tự động', 'reason' => 'Chưa cài plugin Automation' );
			} elseif ( is_readable( rtrim( $dir, '/\\' ) . '/' . $scenariosRel ) ) {
				$sc       = json_decode( self::read_file( $dir, $scenariosRel ), true );
				$create[] = array( 'kind' => 'scenario', 'label' => 'Kịch bản tự động', 'count' => is_array( $sc ) ? count( $sc ) : 0 );
			}
		}
		if ( ! empty( $p['knowledge']['dir'] ) && is_dir( rtrim( $dir, '/\\' ) . '/' . $p['knowledge']['dir'] ) ) {
			$create[] = array( 'kind' => 'knowledge', 'label' => 'Tài liệu / giáo trình', 'count' => 1 );
		}
		$diff = array();
		if ( $guru_id > 0 ) {
			$diff[] = array( 'field' => 'guru', 'current' => 'guru#' . $guru_id, 'proposed' => 'ghép vào Guru hiện có (không ghi đè)' );
		} else {
			$diff[] = array( 'field' => 'guru', 'current' => null, 'proposed' => 'tạo Guru mới từ gói' );
		}
		return array( 'create' => $create, 'skip' => $skip, 'missing' => $missing, 'diff' => $diff );
	}

	/* ================================================================
	 *  Install / uninstall / export — delegated to the installer (write steps)
	 * ================================================================ */

	/** @param array $opts {guru_id:int (0=create new), take_fields:string[]} @return array|WP_Error */
	public static function install( string $dir, array $opts ) {
		if ( ! class_exists( 'BizCity_Brain_Profile_Installer' ) ) {
			return new WP_Error( 'module_not_loaded', 'Installer chưa sẵn sàng.', array( 'status' => 503 ) );
		}
		$v = self::validate( $dir );
		if ( ! $v['ok'] ) {
			return new WP_Error( 'profile_invalid', 'Gói không hợp lệ.', array( 'status' => 422, 'findings' => $v['findings'], 'hint' => 'Xem findings[] để biết dòng lỗi.', 'help_code' => 'profile_invalid' ) );
		}
		return BizCity_Brain_Profile_Installer::install( $dir, $opts );
	}

	/** @return bool|WP_Error */
	public static function uninstall( int $guru_id ) {
		if ( ! class_exists( 'BizCity_Brain_Profile_Installer' ) ) {
			return new WP_Error( 'module_not_loaded', 'Installer chưa sẵn sàng.', array( 'status' => 503 ) );
		}
		return BizCity_Brain_Profile_Installer::uninstall( $guru_id );
	}

	/** @return array{file_path:string,bytes:int}|WP_Error */
	public static function export( int $guru_id, bool $include_knowledge = false ) {
		if ( ! class_exists( 'BizCity_Brain_Profile_Installer' ) ) {
			return new WP_Error( 'module_not_loaded', 'Installer chưa sẵn sàng.', array( 'status' => 503 ) );
		}
		return BizCity_Brain_Profile_Installer::export( $guru_id, $include_knowledge );
	}
}
