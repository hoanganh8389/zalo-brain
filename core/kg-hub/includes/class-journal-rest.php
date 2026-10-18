<?php
/**
 * BizCity_Journal_REST — `bizcity-knowledge/v2/journal` (PHASE-0.100 S100-L22, doc 92 §9, R-JOURNAL R-JN-5).
 *
 * Same handlers as the MCP tools (`BizCity_Journal_MCP_Tools::find/get/summarize`) — one logic, two doors — read
 * only, `is_user_logged_in()` gate, owner = the logged-in WordPress user (REST never acts as a delegated principal).
 *
 * Biz Central Brain — Johnny Chu (Chu Hoàng Anh).
 *
 * [2026-10-12 12:05 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.100 S100-L22 — new file.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\KG_Hub
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Journal_REST', false ) ) {
	return;
}

final class BizCity_Journal_REST {

	const NS = 'bizcity-knowledge/v2';

	private static $hooked = false;

	public static function init(): void {
		if ( self::$hooked ) {
			return;
		}
		self::$hooked = true;
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes(): void {
		register_rest_route( self::NS, '/journal/find', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'find' ),
			'permission_callback' => array( __CLASS__, 'permission' ),
		) );
		register_rest_route( self::NS, '/journal/(?P<id>\d+)', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get' ),
				'permission_callback' => array( __CLASS__, 'permission' ),
			),
			// [2026-10-17 09:35 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.121 B4-2 (S121-R6, doc 70 §6) — a PERSON edits labels / verifies on the web (never MCP).
			array(
				'methods'             => 'PATCH, POST',
				'callback'            => array( __CLASS__, 'patch' ),
				'permission_callback' => array( __CLASS__, 'permission' ),
			),
		) );
		// [2026-10-17 09:35 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.121 B4-2 (S121-R6, doc 70 §6) — the four web screens read these (bizcity-twin-crm apps/documents).
		register_rest_route( self::NS, '/documents/overview', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'overview' ),
			'permission_callback' => array( __CLASS__, 'permission' ),
		) );
		// [2026-10-17 10:10 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.121 B4-1 (S121-R6, doc 70 §6) — "Học kỹ" / "Ngừng học sâu" from the web = the same handler as MCP document.learn.set (I-7, L3 refused).
		register_rest_route( self::NS, '/journal/(?P<id>\d+)/learn', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'learn' ),
			'permission_callback' => array( __CLASS__, 'permission' ),
		) );
		// [2026-10-17 11:10 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.121 Q121-8 web — what staff sent into the numbers I own (read-only).
		register_rest_route( self::NS, '/journal/team', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'team' ),
			'permission_callback' => array( __CLASS__, 'permission' ),
		) );
		register_rest_route( self::NS, '/journal/export', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'export_csv' ),
			'permission_callback' => array( __CLASS__, 'permission' ),
		) );
		register_rest_route( self::NS, '/journal/summarize', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'summarize' ),
			'permission_callback' => array( __CLASS__, 'permission' ),
		) );
	}

	public static function permission() {
		return is_user_logged_in();
	}

	public static function find( WP_REST_Request $request ): WP_REST_Response {
		if ( ! class_exists( 'BizCity_Journal_MCP_Tools' ) ) {
			return new WP_REST_Response( array( 'code' => 'journal_unavailable', 'message' => 'Chỉ mục nhật ký chưa sẵn sàng trên site này.', 'hint' => '', 'help_code' => 'S100-JN-REST-1' ), 503 );
		}
		$args = self::read_params( $request, array( 'kind', 'kinds', 'kind_prefix', 'tags', 'subject_hash', 'from', 'to', 'q', 'limit', 'offset', 'status', 'has_file' ) );
		$res  = BizCity_Journal_MCP_Tools::find( $args, self::ctx() );
		return self::respond( $res );
	}

	public static function get( WP_REST_Request $request ): WP_REST_Response {
		if ( ! class_exists( 'BizCity_Journal_MCP_Tools' ) ) {
			return new WP_REST_Response( array( 'code' => 'journal_unavailable', 'message' => 'Chỉ mục nhật ký chưa sẵn sàng trên site này.', 'hint' => '', 'help_code' => 'S100-JN-REST-1' ), 503 );
		}
		$res = BizCity_Journal_MCP_Tools::get( array( 'entry_id' => (int) $request->get_param( 'id' ) ), self::ctx() );
		if ( is_wp_error( $res ) || ! is_array( $res ) || isset( $res['code'] ) ) {
			$team = self::team_entries( (int) get_current_user_id(), (int) $request->get_param( 'id' ) ); // [2026-10-17 11:10 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.121 Q121-8 web
			if ( array() !== $team ) {
				return new WP_REST_Response( $team[0], 200 );
			}
		}
		return self::respond( $res );
	}

	public static function summarize( WP_REST_Request $request ): WP_REST_Response {
		if ( ! class_exists( 'BizCity_Journal_MCP_Tools' ) ) {
			return new WP_REST_Response( array( 'code' => 'journal_unavailable', 'message' => 'Chỉ mục nhật ký chưa sẵn sàng trên site này.', 'hint' => '', 'help_code' => 'S100-JN-REST-1' ), 503 );
		}
		$args = self::read_params( $request, array( 'kind', 'kinds', 'subject_hash', 'from', 'to', 'field', 'group_by' ) );
		$res  = BizCity_Journal_MCP_Tools::summarize( $args, self::ctx() );
		return self::respond( $res );
	}

	// ── [2026-10-17 09:35 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.121 B4-2 (S121-R6, doc 70 §6) ──────────────────────────────────────

	/** Keys a person may patch from the web. */
	const PATCH_KEYS = array( 'kind', 'tags', 'doc_date', 'pinned', 'review', 'summary', 'card' );

	/**
	 * Pure: split a web patch into the file() patch and the state change. `verified:true` ⇒ state verified (by this person);
	 * `verified:false` ⇒ back to captured; `state:void` ⇒ void ("xoá khỏi sổ", row kept). Anything else is ignored.
	 *
	 * @return array{patch:array,state:string}
	 */
	public static function split_patch( array $body ): array {
		$patch = array_intersect_key( $body, array_flip( self::PATCH_KEYS ) );
		if ( isset( $patch['card'] ) ) { // scalar card fields only (e.g. a contract end_date typed on the web)
			$patch['card'] = is_array( $patch['card'] ) ? array_map( 'strval', array_filter( $patch['card'], 'is_scalar' ) ) : array();
		}
		$state = '';
		if ( array_key_exists( 'verified', $body ) ) {
			$state = ! empty( $body['verified'] ) ? 'verified' : 'captured';
		}
		if ( 'void' === (string) ( $body['state'] ?? '' ) ) {
			$state = 'void';
		}
		return array( 'patch' => $patch, 'state' => $state );
	}

	public static function patch( WP_REST_Request $request ): WP_REST_Response {
		if ( ! class_exists( 'BizCity_Journal_Index' ) ) {
			return new WP_REST_Response( array( 'code' => 'journal_unavailable', 'message' => 'Chỉ mục nhật ký chưa sẵn sàng trên site này.', 'hint' => '', 'help_code' => 'S121-JN-REST-1' ), 503 );
		}
		$id    = (int) $request->get_param( 'id' );
		$owner = (int) get_current_user_id();
		$body  = $request->get_json_params();
		$split = self::split_patch( is_array( $body ) ? $body : (array) $request->get_body_params() );
		$out   = null;
		if ( array() !== $split['patch'] ) {
			$out = BizCity_Journal_Index::file( $id, $owner, $split['patch'] );
			if ( is_wp_error( $out ) ) {
				return self::respond( $out );
			}
		}
		if ( '' !== $split['state'] ) {
			$out = BizCity_Journal_Index::set_state( $id, $owner, $split['state'], array(), 'verified' === $split['state'] ? $owner : 0 );
		}
		return self::respond( null === $out ? BizCity_Journal_Index::get( $id, $owner ) : $out );
	}

	/**
	 * Pure: the "Tổng quan" numbers from the person's journal-entry@1.1 rows (newest first).
	 *
	 * @return array{total:int,by_kind:array,contracts:int,expiring:array,review:int,failed:int,mirror_pending:int,levels:array}
	 */
	public static function overview_from_entries( array $entries, string $today ): array {
		$soon = gmdate( 'Y-m-d', (int) strtotime( $today . ' +30 days UTC' ) );
		$out  = array( 'total' => 0, 'by_kind' => array(), 'contracts' => 0, 'expiring' => array(), 'review' => 0, 'failed' => 0, 'mirror_pending' => 0, 'levels' => array( 'L0' => 0, 'L1' => 0, 'L2' => 0, 'L3' => 0 ) );
		foreach ( $entries as $e ) {
			$state = (string) ( $e['state'] ?? 'captured' );
			if ( 'void' === $state ) {
				continue;
			}
			$out['total']++;
			$kind = (string) ( $e['kind'] ?? '' );
			$out['by_kind'][ $kind ] = ( $out['by_kind'][ $kind ] ?? 0 ) + 1;
			if ( 'review' === $state || ! empty( $e['flags']['review'] ) ) {
				$out['review']++;
			}
			if ( 'failed' === $state ) {
				$out['failed']++;
			}
			if ( 'pending' === (string) ( $e['mirror']['sheet'] ?? '' ) || 'failed' === (string) ( $e['mirror']['sheet'] ?? '' ) ) {
				$out['mirror_pending']++;
			}
			$lvl = (string) ( $e['learning']['level'] ?? 'L0' );
			if ( isset( $out['levels'][ $lvl ] ) ) {
				$out['levels'][ $lvl ]++;
			}
			if ( 'biz.contract' === $kind ) {
				$out['contracts']++;
				$end = is_scalar( $e['card']['end_date'] ?? null ) ? (string) $e['card']['end_date'] : '';
				if ( '' !== $end && $end >= $today && $end <= $soon ) {
					$out['expiring'][] = array( 'entry_id' => (int) ( $e['entry_id'] ?? 0 ), 'code' => (string) ( $e['original']['doc_code'] ?? '' ), 'title' => (string) ( $e['title'] ?? '' ), 'end_date' => $end );
				}
			}
		}
		arsort( $out['by_kind'] );
		usort( $out['expiring'], static function ( $a, $b ) { return strcmp( $a['end_date'], $b['end_date'] ); } );
		return $out;
	}

	public static function overview( WP_REST_Request $request ): WP_REST_Response {
		if ( ! class_exists( 'BizCity_Journal_Database' ) || ! class_exists( 'BizCity_Journal_Index' ) ) {
			return new WP_REST_Response( array( 'code' => 'journal_unavailable', 'message' => 'Chỉ mục nhật ký chưa sẵn sàng trên site này.', 'hint' => '', 'help_code' => 'S121-JN-REST-1' ), 503 );
		}
		$owner   = (int) get_current_user_id();
		$entries = array_map( array( 'BizCity_Journal_Index', 'to_journal_entry' ), BizCity_Journal_Database::instance()->register_rows( $owner, 500 ) );
		$out     = self::overview_from_entries( $entries, gmdate( 'Y-m-d' ) );
		$out['upcoming_reminders'] = self::upcoming_reminders( $owner );
		$out['team_total']         = count( self::team_entries( $owner ) ); // [2026-10-17 11:10 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.121 Q121-8 web
		$out['kind_labels']        = array();
		foreach ( array_keys( $out['by_kind'] ) as $k ) {
			$out['kind_labels'][ $k ] = class_exists( 'BizCity_Journal_Kinds' ) ? BizCity_Journal_Kinds::label( (string) $k ) : (string) $k;
		}
		return new WP_REST_Response( $out, 200 );
	}

	/** [2026-10-17 11:10 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.121 Q121-8 web — Zalo Cá nhân numbers whose CURRENT owner is $user_id. */
	public static function owned_bridges( int $user_id ): array {
		if ( $user_id <= 0 || ! class_exists( 'BizCity_Zalo_Mapping_Repo' ) ) {
			return array();
		}
		$out = array();
		foreach ( (array) BizCity_Zalo_Mapping_Repo::list_personal_accounts_for_owner( $user_id ) as $row ) {
			$b = (string) ( $row['bridge_account_id'] ?? '' );
			if ( '' !== $b ) {
				$out[] = $b;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/** Staff rows of my numbers as journal entries, flagged `read_only` + `team` (no edit / verify / learn from here). */
	public static function team_entries( int $user_id, int $entry_id = 0, int $max = 200 ): array {
		if ( ! class_exists( 'BizCity_Journal_Database' ) || ! class_exists( 'BizCity_Journal_Index' ) ) {
			return array();
		}
		$out = array();
		foreach ( BizCity_Journal_Database::instance()->number_rows( self::owned_bridges( $user_id ), $user_id, $max, $entry_id ) as $row ) {
			$e = BizCity_Journal_Index::to_journal_entry( $row );
			unset( $e['owner_user_id'] );
			$e['team']      = true;
			$e['read_only'] = true;
			$out[] = $e;
		}
		return $out;
	}

	public static function team( WP_REST_Request $request ): WP_REST_Response {
		$items = self::team_entries( (int) get_current_user_id() );
		return new WP_REST_Response( array( 'items' => $items, 'total' => count( $items ) ), 200 );
	}

	public static function learn( WP_REST_Request $request ): WP_REST_Response {
		if ( ! class_exists( 'BizCity_KG_Private_File_Store' ) ) {
			return new WP_REST_Response( array( 'code' => 'journal_unavailable', 'message' => 'Chỉ mục nhật ký chưa sẵn sàng trên site này.', 'hint' => '', 'help_code' => 'S121-JN-REST-1' ), 503 );
		}
		$ctx = self::ctx();
		return self::respond( BizCity_KG_Private_File_Store::mcp_learn_set(
			array( 'entry_id' => (int) $request->get_param( 'id' ), 'level' => (string) $request->get_param( 'level' ) ),
			array( 'user_id' => (int) $ctx['owner_user_id'], 'role' => (string) $ctx['role'], 'allowed_notebook_ids' => array() )
		) );
	}

	/** Reminders of the next 7 days (site mirror of the cell clock, bizcity_crm_events). Count only — the calendar page lists them. */
	private static function upcoming_reminders( int $owner ): int {
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return 0;
		}
		$tbl = $wpdb->prefix . 'bizcity_crm_events';
		if ( $tbl !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tbl ) ) ) {
			return 0;
		}
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tbl} WHERE user_id = %d AND start_at BETWEEN UTC_TIMESTAMP() AND DATE_ADD(UTC_TIMESTAMP(), INTERVAL 7 DAY) AND status NOT IN ('cancelled','done')", $owner ) );
	}

	/** Pure: doc-register@1 rows ⇒ CSV (UTF-8 BOM for Excel; formula-leading cells neutralised). */
	public static function csv_of( array $rows ): string {
		$cols = array( 'doc_code', 'captured_at', 'doc_date', 'title', 'file_name', 'kind_label', 'summary', 'key_fields', 'tags', 'state', 'verified', 'sender_role', 'level' );
		$esc  = static function ( $v ): string {
			$v = (string) $v;
			if ( '' !== $v && false !== strpos( '=+-@', $v[0] ) ) {
				$v = "'" . $v; // CSV injection guard
			}
			return '"' . str_replace( '"', '""', $v ) . '"';
		};
		$out = "\xEF\xBB\xBF" . implode( ',', $cols ) . "\r\n";
		foreach ( $rows as $r ) {
			$line = array();
			foreach ( $cols as $c ) {
				$line[] = $esc( $r[ $c ] ?? '' );
			}
			$out .= implode( ',', $line ) . "\r\n";
		}
		return $out;
	}

	/** GET journal/export — the register as CSV (no Google Sheet needed, R-JN-15). */
	public static function export_csv( WP_REST_Request $request ) {
		if ( ! class_exists( 'BizCity_Journal_Database' ) || ! class_exists( 'BizCity_Journal_Index' ) ) {
			return new WP_REST_Response( array( 'code' => 'journal_unavailable', 'message' => 'Chỉ mục nhật ký chưa sẵn sàng trên site này.', 'hint' => '', 'help_code' => 'S121-JN-REST-1' ), 503 );
		}
		$owner = (int) get_current_user_id();
		$rows  = array();
		foreach ( BizCity_Journal_Database::instance()->register_rows( $owner, 500 ) as $r ) {
			$rows[] = BizCity_Journal_Index::register_row( BizCity_Journal_Index::to_journal_entry( $r ) );
		}
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="so-tai-lieu-' . gmdate( 'Ymd' ) . '.csv"' );
		echo self::csv_of( $rows ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV body, cells quoted + injection-guarded
		exit;
	}

	private static function ctx(): array {
		return array( 'owner_user_id' => get_current_user_id(), 'role' => current_user_can( 'manage_options' ) ? 'owner' : 'staff' );
	}

	private static function read_params( WP_REST_Request $request, array $keys ): array {
		$out = array();
		foreach ( $keys as $k ) {
			$v = $request->get_param( $k );
			if ( null !== $v ) {
				$out[ $k ] = $v;
			}
		}
		return $out;
	}

	/** @param array|WP_Error $res */
	private static function respond( $res ): WP_REST_Response {
		if ( is_wp_error( $res ) ) {
			$data = $res->get_error_data();
			$status = is_array( $data ) && ! empty( $data['status'] ) ? (int) $data['status'] : 400;
			return new WP_REST_Response( array(
				'code'      => $res->get_error_code(),
				'message'   => $res->get_error_message(),
				'hint'      => '',
				'help_code' => 'S100-JN-REST-' . $status,
			), $status );
		}
		return new WP_REST_Response( $res, 200 );
	}
}
