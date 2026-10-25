<?php
/**
 * BizCity_Journal_Contact_Profile — "Hồ sơ khách" (PHASE-0.136 S136-L4, D136-3): ONE view over the Journal rows of one CRM contact — never a second
 * store (R-JOURNAL: a ledger row is a journal row, totals are added by the site). Rows are the owner's, linked to the contact by subject_hash
 * (R-CONTACT-ID, BizCity_Journal_Index::contact_subject_hash) and filtered by brain-access@1 decide() (meta.read).
 *
 *   last order        biz.order — newest: ref · date · total
 *   contracts         biz.contract — still running (end_date ≥ today or none), newest first, ≤ 5
 *   debt balance      fin.debt — per direction (receivable = họ nợ mình · payable = mình nợ họ): open adds, settle subtracts; ≥ 0
 *   last care         the newest row of any kind (what / when)
 *   files             documents of folders 50 · 55 (CSKH · Pháp lý) for this person, ≤ 5
 *
 * REST  GET bizcity-knowledge/v2/journal/contact/{id}/profile   (logged in; the owner's own book)
 * MCP   journal.contact.profile {contact_id}                     (owner / staff; read)
 *
 * // [2026-10-25 Johnny Chu - Chu Hoàng Anh] PHASE-0.136 S136-L4 — new file.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\KG_Hub
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Journal_Contact_Profile', false ) ) {
	return;
}

final class BizCity_Journal_Contact_Profile {

	const CONTRACT  = 'contact-profile@1.0.0';
	const NS        = 'bizcity-knowledge/v2';
	const MAX_ROWS  = 200;
	const CARE_DIRS = array( '50', '55' );

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

	/** Signed money of one fin.debt row for its direction: open ⇒ +amount, settle ⇒ −amount. Pure. */
	public static function debt_delta( array $card ): array {
		$dir    = (string) ( $card['direction'] ?? '' );
		$dir    = in_array( $dir, array( 'receivable', 'owe_me' ), true ) ? 'receivable' : ( in_array( $dir, array( 'payable', 'i_owe' ), true ) ? 'payable' : '' );
		$amount = is_numeric( $card['amount'] ?? null ) ? (float) $card['amount'] : 0.0;
		$sign   = 'settle' === (string) ( $card['type'] ?? 'open' ) ? -1 : 1;
		return array( $dir, $sign * $amount );
	}

	/**
	 * The profile from a list of journal-entry rows of ONE contact (any order). Pure.
	 *
	 * @param array  $rows  journal-entry@1.2 rows (kind · ref_code · doc_date · created_at · title · card · meta.folder · status)
	 * @param string $today Y-m-d
	 */
	public static function profile_of( array $rows, string $today ): array {
		usort( $rows, static function ( $a, $b ) {
			return strcmp( (string) ( $b['doc_date'] ?? $b['created_at'] ?? '' ), (string) ( $a['doc_date'] ?? $a['created_at'] ?? '' ) );
		} );
		$order     = null;
		$contracts = array();
		$debt      = array( 'receivable' => 0.0, 'payable' => 0.0 );
		$files     = array();
		$counts    = array();
		$last      = null;
		foreach ( $rows as $r ) {
			if ( in_array( (string) ( $r['status'] ?? '' ), array( 'void', 'archived' ), true ) ) {
				continue;
			}
			$kind = (string) ( $r['kind'] ?? '' );
			$card = is_array( $r['card'] ?? null ) ? $r['card'] : array();
			$date = substr( (string) ( $r['doc_date'] ?? $r['created_at'] ?? '' ), 0, 10 );
			$ref  = (string) ( $r['ref_code'] ?? '' );
			$counts[ $kind ] = ( $counts[ $kind ] ?? 0 ) + 1;
			$last = $last ?? array( 'ref' => $ref, 'kind' => $kind, 'title' => (string) ( $r['title'] ?? '' ), 'date' => $date );
			if ( 'biz.order' === $kind && null === $order ) {
				$order = array( 'ref' => $ref, 'date' => $date, 'total' => is_numeric( $card['total'] ?? null ) ? (float) $card['total'] : null, 'title' => (string) ( $r['title'] ?? '' ) );
			} elseif ( 'biz.contract' === $kind && count( $contracts ) < 5 ) {
				$end = substr( (string) ( $card['end_date'] ?? '' ), 0, 10 );
				if ( '' === $end || $end >= $today ) {
					$contracts[] = array( 'ref' => $ref, 'title' => (string) ( $r['title'] ?? '' ), 'date' => $date, 'end_date' => $end, 'total' => is_numeric( $card['total'] ?? null ) ? (float) $card['total'] : null );
				}
			} elseif ( 'fin.debt' === $kind ) {
				list( $dir, $delta ) = self::debt_delta( $card );
				if ( '' !== $dir ) {
					$debt[ $dir ] += $delta;
				}
			}
			$folder = substr( (string) ( $r['meta']['folder'] ?? '' ), 0, 2 );
			if ( in_array( $folder, self::CARE_DIRS, true ) && count( $files ) < 5 ) {
				$files[] = array( 'ref' => $ref, 'title' => (string) ( $r['title'] ?? '' ), 'folder' => $folder );
			}
		}
		return array(
			'contract'   => self::CONTRACT,
			'last_order' => $order,
			'contracts'  => $contracts,
			'debt'       => array( 'receivable' => max( 0.0, round( $debt['receivable'], 2 ) ), 'payable' => max( 0.0, round( $debt['payable'], 2 ) ) ),
			'last_care'  => $last,
			'files'      => $files,
			'counts'     => $counts,
			'rows'       => array_sum( $counts ),
		);
	}

	/** The owner's rows of one contact, decided at source. @return array|WP_Error profile + contact_id */
	public static function for_contact( int $contact_id, array $ctx ) {
		$owner = (int) ( $ctx['owner_user_id'] ?? $ctx['user_id'] ?? 0 );
		if ( $contact_id <= 0 || $owner <= 0 ) {
			return new WP_Error( 'contact_invalid', 'Cần mã khách (contact_id).', array( 'status' => 400, 'hint' => 'Lấy contact_id từ dòng sổ hoặc thẻ khách.' ) );
		}
		if ( ! class_exists( 'BizCity_Journal_Index' ) ) {
			return new WP_Error( 'journal_unavailable', 'Sổ chưa sẵn sàng trên site này.', array( 'status' => 503 ) );
		}
		$hash = BizCity_Journal_Index::contact_subject_hash( $contact_id );
		$rows = array();
		for ( $off = 0; $off < self::MAX_ROWS; $off += 50 ) {
			$res  = BizCity_Journal_Index::find( array( 'owner_user_id' => $owner, 'subject_hash' => $hash, 'limit' => 50, 'offset' => $off ) );
			$page = (array) ( $res['items'] ?? array() );
			$rows = array_merge( $rows, $page );
			if ( count( $page ) < 50 ) {
				break;
			}
		}
		if ( class_exists( 'BizCity_Brain_Access' ) ) {
			$rows = BizCity_Brain_Access::filter( $rows, BizCity_Brain_Access::principal( $ctx ), 'meta.read' );
		}
		$today = function_exists( 'wp_date' ) ? (string) wp_date( 'Y-m-d' ) : gmdate( 'Y-m-d' );
		return array( 'contact_id' => $contact_id ) + self::profile_of( $rows, $today );
	}

	// ── REST ─────────────────────────────────────────────────────────────

	public static function routes(): void {
		register_rest_route( self::NS, '/journal/contact/(?P<id>\d+)/profile', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'rest_profile' ),
			'permission_callback' => static function () { return is_user_logged_in(); },
		) );
	}

	public static function rest_profile( WP_REST_Request $r ): WP_REST_Response {
		$res = self::for_contact( (int) $r->get_param( 'id' ), array( 'owner_user_id' => get_current_user_id() ) );
		if ( is_wp_error( $res ) ) {
			$d = (array) $res->get_error_data();
			return new WP_REST_Response( array( 'code' => $res->get_error_code(), 'message' => $res->get_error_message(), 'hint' => (string) ( $d['hint'] ?? '' ) ), (int) ( $d['status'] ?? 400 ) );
		}
		return new WP_REST_Response( $res, 200 );
	}

	// ── MCP ──────────────────────────────────────────────────────────────

	public static function register_tools(): void {
		if ( ! class_exists( 'BizCity_MCP_Tool_Registry' ) ) {
			return;
		}
		$R = 'BizCity_MCP_Tool_Registry';
		// @mcp bizcity-mcp-standard@1 tool journal.contact.profile
		$R::register( 'journal.contact.profile', array(
			'title'          => 'Hồ sơ một khách',
			'description'    => 'Hồ sơ MỘT khách theo contact_id, cộng từ sổ của chủ: đơn gần nhất, hợp đồng còn hiệu lực, công nợ còn lại (họ nợ mình / mình nợ họ — site cộng, không đoán), lần chăm sóc gần nhất, tài liệu ở thư mục Chăm sóc khách hàng / Pháp lý. Dùng khi chăm sóc / tư vấn một người cụ thể. KHÔNG dùng để liệt kê mọi khách; KHÔNG trả nội dung tệp.',
			'input_schema'   => array( 'type' => 'object', 'required' => array( 'contact_id' ), 'properties' => array(
				'contact_id' => array( 'type' => 'integer', 'minimum' => 1, 'description' => 'Mã khách (contact_id) lấy từ dòng sổ / thẻ khách.' ),
			) ),
			'output_schema'  => $R::envelope_schema( array( 'contact_id' => array( 'type' => 'integer' ), 'debt' => array( 'type' => 'object' ) ), array( 'contact_id' ) ),
			'read_only'      => true,
			'idempotent'     => true,
			'required_scope' => 'brain.read',
			'scopes'         => array( 'brain.read' ),
			'handler'        => array( __CLASS__, 'mcp_profile' ),
			'mode'           => 'notebook',
			'roles'          => array( 'owner', 'staff' ),
			'confirm'        => 'never',
			'llm_alias'      => 'journal_contact_profile',
			'fallback_pack'  => null,
			'since'          => '0.136.0',
			'brain'          => array( 'tier' => 'L1', 'permission' => 'meta.read', 'crud' => 'read', 'answer_hint' => 'instant' ),
		) );
	}

	public static function mcp_profile( array $args, array $ctx ) {
		return self::for_contact( (int) ( $args['contact_id'] ?? 0 ), $ctx );
	}
}
