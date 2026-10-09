<?php
/**
 * BizCity_MCP_Action_Support — shared guards of the PHASE-0.88 CL-B action tools (CRM, commerce, booking, automation).
 *
 * Every handler runs as the WordPress user of the auth context (`ctx.user_id`, resolved by the transport — never by the
 * tool arguments) and re-checks that user's CRM / Woo rights here. No business logic lives in this file: it only asks
 * the owners (BizCity_CRM_Staff_Policy, BizCity_CRM_Agent_Mode_Delegate, BizCity_CRM_Spine_REST scope checks,
 * BizCity_CRM_Repository / BizCity_CRM_Customers_Pack) and shapes their answer for MCP.
 *
 * [2026-10-09 10:50 PM Johnny Chu - Chu Hoàng Anh] CORE-REDUCTION WP-20 W20-L5 (D-W20-1) — every customer READ here runs on core only
 * (no Zalo Brain CRM plugin needed); the plugin adds the pipeline stage through `bizcity_crm_pack_customer_row`. Write/admin tools
 * that need the plugin answer crm_unavailable() (R-ERROR-UX: code, message, hint, help_code).
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\MCP
 * @since      2026-10-01 (PHASE-0.88 CL-B)
 */

defined( 'ABSPATH' ) || exit;

// [2026-10-01 Claude Opus 5.5] PHASE-0.88 L1-8 — new file, guards shared by the CL-B action services.
final class BizCity_MCP_Action_Support {

	/** @var array<string,callable> test seams: candidates(query, inbox_ids|null): int[] */
	public static $readers = array();

	/**
	 * Run `$fn( $user_id )` as the context user. A context without a user never reaches business code.
	 *
	 * @return mixed|WP_Error
	 */
	public static function run_as( array $ctx, callable $fn ) {
		$uid = (int) ( $ctx['user_id'] ?? 0 );
		if ( $uid <= 0 ) {
			$uid = self::acting_user_id( $ctx ); // [2026-10-09 03:09 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.95-S95-A2 — luật 2b, guest automation only
		}
		if ( $uid <= 0 ) {
			return self::error( BizCity_MCP_Error::AUTH_INVALID, 'Không xác định được người dùng của lượt này.', 401 );
		}
		$previous = (int) get_current_user_id();
		if ( $previous !== $uid ) {
			wp_set_current_user( $uid );
		}
		try {
			return $fn( $uid );
		} finally {
			if ( $previous !== $uid ) {
				wp_set_current_user( $previous );
			}
		}
	}

	/** [2026-10-09 03:09 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.95-S95-A2 — the only tools a customer turn may run as the number's owner. */
	const GUEST_ACTING_TOOLS = array( 'automation.list_scenarios', 'automation.run_scenario' );

	/**
	 * [2026-10-09 03:09 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.95-S95-A2 (G95-1, contract 1.5.0 luật 2b) — a CUSTOMER turn (principal_kind
	 * guru_public, user_id 0) runs automation.list_scenarios / run_scenario as the WordPress owner of the number
	 * (`acting_user_id`, set by the bridge from ITS binding — never from the request). Every other tool keeps the 401. The role stays
	 * `customer` (principal_role reads ctx.role, not the user), so only audience-guest scenarios open.
	 * `ctx.tool` is the tool being run: set by the handler of that tool (the registry passes ctx unchanged).
	 */
	public static function acting_user_id( array $ctx ): int {
		$kind = class_exists( 'BizCity_MCP_Delegation' ) ? BizCity_MCP_Delegation::GURU_PUBLIC : 'guru_public';
		if ( (int) ( $ctx['user_id'] ?? 0 ) > 0 || $kind !== (string) ( $ctx['principal_kind'] ?? '' ) ) {
			return 0;
		}
		if ( ! in_array( (string) ( $ctx['tool'] ?? '' ), self::GUEST_ACTING_TOOLS, true ) ) {
			return 0;
		}
		return max( 0, (int) ( $ctx['acting_user_id'] ?? 0 ) );
	}

	public static function error( $code, $message, $status = 400, array $data = array() ) {
		return new WP_Error( $code, $message, array_merge( array( 'status' => (int) $status ), $data ) );
	}

	/**
	 * [2026-10-09 10:50 PM Johnny Chu - Chu Hoàng Anh] CORE-REDUCTION WP-20 W20-L5 — a write/admin CRM feature (`crm_pipeline` stage +
	 * note, `tasks` assign) is not installed: 4-field R-ERROR-UX error `crm_feature_unavailable` (message, hint "cài Zalo Brain CRM",
	 * help_code). The text comes from BizCity_CRM_Spine::unavailable_error() when the spine is loaded (one wording).
	 *
	 * @return WP_Error
	 */
	public static function crm_unavailable( $feature, $status = 503 ) {
		$feature = sanitize_key( (string) $feature );
		$message = 'Việc này cần plugin Zalo Brain CRM. Site này chưa cài hoặc chưa kích hoạt plugin đó.';
		$hint    = 'Cách sửa: vào Cài đặt › Mở rộng, cài và kích hoạt Zalo Brain CRM, rồi thử lại.';
		if ( class_exists( 'BizCity_CRM_Spine' ) && method_exists( 'BizCity_CRM_Spine', 'unavailable_error' ) ) {
			$e       = BizCity_CRM_Spine::unavailable_error( $feature, (int) $status );
			$d       = (array) $e->get_error_data();
			$message = (string) $e->get_error_message();
			$hint    = (string) ( $d['hint'] ?? $hint );
		}
		return self::error( BizCity_MCP_Error::CRM_FEATURE_UNAVAILABLE, $message, (int) $status, array(
			'hint'      => $hint,
			'help_code' => 'crm_feature_unavailable',
			'feature'   => $feature,
		) );
	}

	/**
	 * A WP_Error from a business owner (CRM, Woo, scheduler) becomes a catalogued MCP code while keeping its Vietnamese
	 * message (an unknown code would otherwise turn into the generic MCP_INTERNAL_ERROR text).
	 */
	public static function from_business( WP_Error $e ) {
		$data   = $e->get_error_data();
		$status = is_array( $data ) ? (int) ( $data['status'] ?? 0 ) : 0;
		$code   = (string) $e->get_error_code();
		if ( BizCity_MCP_Error::is_known_code( $code ) ) {
			return $e;
		}
		if ( 401 === $status || 403 === $status || false !== strpos( $code, 'scope' ) || false !== strpos( $code, 'manageable' ) ) {
			$code = BizCity_MCP_Error::SCOPE_DENIED;
		} elseif ( 404 === $status || false !== strpos( $code, 'not_found' ) ) {
			$code = BizCity_MCP_Error::NOT_FOUND;
		} elseif ( $status >= 400 && $status < 500 ) {
			$code = BizCity_MCP_Error::QUERY_INVALID;
		} else {
			return self::error( BizCity_MCP_Error::INTERNAL_ERROR, 'Lỗi nội bộ khi xử lý.', 500 );
		}
		return self::error( $code, (string) $e->get_error_message(), $status ?: 400 );
	}

	/* ── who is calling ──────────────────────────────────────────── */

	public static function is_admin( $uid ) {
		$uid = (int) $uid;
		return $uid > 0 && ( user_can( $uid, 'manage_options' ) || ( function_exists( 'is_super_admin' ) && is_super_admin( $uid ) ) );
	}

	/** CRM Staff_Policy role: admin | supervisor | lead | agent | none. */
	public static function crm_role( $uid ) {
		if ( class_exists( 'BizCity_CRM_Staff_Policy' ) ) {
			return (string) BizCity_CRM_Staff_Policy::role( (int) $uid );
		}
		return self::is_admin( $uid ) ? 'admin' : 'none';
	}

	public static function crm_rank( $uid ) {
		$rank = array( 'admin' => 4, 'supervisor' => 3, 'lead' => 2, 'agent' => 1 );
		return (int) ( $rank[ self::crm_role( $uid ) ] ?? 0 );
	}

	/** Agent+ in the CRM, or a shop manager in WooCommerce ("CRM tối thiểu: agent+"). */
	public static function can_sell( $uid ) {
		return self::is_admin( $uid ) || user_can( (int) $uid, 'manage_woocommerce' ) || user_can( (int) $uid, 'edit_shop_orders' ) || self::crm_rank( $uid ) >= 1;
	}

	/** Whose customers: `shop` | `person` (only contacts assigned to them, D-TAA-7) | '' (none). */
	public static function customers_scope( $uid ) {
		if ( class_exists( 'BizCity_CRM_Agent_Mode_Delegate' ) ) {
			$scope = (string) BizCity_CRM_Agent_Mode_Delegate::customers_scope( (int) $uid );
			return '' === $scope && self::is_admin( $uid ) ? 'shop' : $scope;
		}
		return self::is_admin( $uid ) ? 'shop' : '';
	}

	/* ── contacts ────────────────────────────────────────────────── */

	/** "crm:731" | "731" → 731; anything else → 0. */
	public static function contact_id( $ref ) {
		$ref = trim( (string) $ref );
		if ( preg_match( '/^(?:crm:)?(\d{1,12})$/', $ref, $m ) ) {
			return (int) $m[1];
		}
		return 0;
	}

	/**
	 * The contact as the CRM owners describe it (no scope check here).
	 *
	 * @return array|null {contact_id, name, phone, email, wp_user_id, tags[], owner_id, stage, conversation_id, orders, last_order_ts, last_activity_ts}
	 */
	public static function contact( $contact_id ) {
		$contact_id = (int) $contact_id;
		if ( $contact_id <= 0 || ! class_exists( 'BizCity_CRM_Repository' ) ) {
			return null;
		}
		$row = BizCity_CRM_Repository::get_contact( $contact_id );
		if ( ! is_array( $row ) || ! empty( $row['deleted_at'] ) ) {
			return null;
		}
		// [2026-10-09 10:50 PM Johnny Chu - Chu Hoàng Anh] CORE-REDUCTION WP-20 W20-L5 — base facts from core (owner, orders, activity);
		// the stage only when the CRM plugin decorates it (D-W20-1). Was BizCity_CRM_Customer_Pipeline::rows() (plugin only).
		$facts = array();
		$stage = array( 'stage' => '', 'label' => '' );
		if ( class_exists( 'BizCity_CRM_Customers_Pack' ) ) {
			$rows  = BizCity_CRM_Customers_Pack::facts( array( $contact_id ) );
			$facts = isset( $rows[ $contact_id ] ) && is_array( $rows[ $contact_id ] ) ? $rows[ $contact_id ] : array();
			$stage = BizCity_CRM_Customers_Pack::stage_of( $contact_id );
		}
		$name = trim( (string) ( $row['name'] ?? '' ) );
		if ( '' === $name ) {
			$name = trim( (string) ( $row['first_name'] ?? '' ) . ' ' . (string) ( $row['last_name'] ?? '' ) );
		}
		$tags = json_decode( (string) ( $row['tags_json'] ?? '' ), true );
		return array(
			'contact_id'       => $contact_id,
			'name'             => '' !== $name ? $name : 'Khách #' . $contact_id,
			'phone'            => (string) ( $row['phone'] ?? '' ),
			'email'            => (string) ( $row['email'] ?? '' ),
			'wp_user_id'       => (int) ( $row['wp_user_id'] ?? 0 ),
			'tags'             => is_array( $tags ) ? array_values( array_map( 'strval', $tags ) ) : array(),
			'owner_id'         => (int) ( $facts['owner_id'] ?? 0 ),
			'stage'            => (string) $stage['stage'],
			'stage_label'      => (string) $stage['label'],
			'conversation_id'  => (int) ( $facts['conversation_id'] ?? 0 ),
			'orders'           => (int) ( $facts['ordered'] ?? 0 ),
			'last_order_ts'    => (int) ( $facts['last_order_ts'] ?? 0 ),
			'last_activity_ts' => max( (int) ( $facts['last_activity_ts'] ?? 0 ), (int) ( $facts['last_out_ts'] ?? 0 ) ),
		);
	}

	/**
	 * D-TAA-7 + the CRM's own contact scope, as the current user: `person` scope ⇒ only contacts assigned to them; every
	 * scope ⇒ BizCity_CRM_Spine_REST::can_{read,write}_contact_scope must agree (inbox scope, handle-inbox cap).
	 *
	 * @return array|WP_Error contact()
	 */
	public static function contact_access( $contact_id, $write, $uid ) {
		$scope = self::customers_scope( $uid );
		if ( '' === $scope ) {
			return self::error( BizCity_MCP_Error::SCOPE_DENIED, 'Bạn chưa được xem khách hàng qua Agent.', 403 );
		}
		$contact = self::contact( $contact_id );
		if ( null === $contact ) {
			return self::error( BizCity_MCP_Error::NOT_FOUND, 'Không tìm thấy khách hàng.', 404 );
		}
		if ( 'person' === $scope && (int) $contact['owner_id'] !== (int) $uid ) {
			return self::error( BizCity_MCP_Error::SCOPE_DENIED, 'Khách này chưa được giao cho bạn.', 403 );
		}
		if ( ! self::crm_contact_scope( (int) $contact_id, (bool) $write ) ) {
			return self::error( BizCity_MCP_Error::SCOPE_DENIED, 'Khách này nằm ngoài phạm vi CRM của bạn.', 403 );
		}
		return $contact;
	}

	private static function crm_contact_scope( $contact_id, $write ) {
		// [2026-10-09 10:50 PM Johnny Chu - Chu Hoàng Anh] CORE-REDUCTION WP-20 W20-L5 — the scope checks live in the core spine
		// (BizCity_CRM_Spine_REST); the plugin's BizCity_CRM_REST_Controller only inherits them, so this runs without the plugin.
		if ( ! class_exists( 'BizCity_CRM_Spine_REST' ) ) {
			return self::is_admin( get_current_user_id() );
		}
		$req = new WP_REST_Request( $write ? 'PUT' : 'GET', '/bizcity-crm/v1/crm-contacts/' . (int) $contact_id );
		$req->set_param( 'id', (int) $contact_id );
		return $write ? (bool) BizCity_CRM_Spine_REST::can_write_contact_scope( $req ) : (bool) BizCity_CRM_Spine_REST::can_read_contact_scope( $req );
	}

	/**
	 * Contact ids matching a name / phone query inside the user's inbox scope (null = whole shop), newest first, ≤ 50.
	 *
	 * @param int[]|null $inbox_ids
	 * @return int[]
	 */
	public static function candidate_ids( $query, $inbox_ids ) {
		if ( isset( self::$readers['candidates'] ) ) {
			return array_map( 'intval', (array) call_user_func( self::$readers['candidates'], (string) $query, $inbox_ids ) );
		}
		// [2026-10-09 10:50 PM Johnny Chu - Chu Hoàng Anh] CORE-REDUCTION WP-20 W20-L5 — core repository reads (same SQL as the plugin
		// pipeline's contact_ids_for_inboxes / contact_in_scope), so crm.customer.lookup works without the plugin.
		if ( ! class_exists( 'BizCity_CRM_Repository' ) || ! method_exists( 'BizCity_CRM_Repository', 'list_customer_ids' ) || ! class_exists( 'BizCity_CRM_DB_Installer_V2' ) ) {
			return array();
		}
		$query = trim( (string) $query );
		if ( '' === $query ) {
			return array_slice( BizCity_CRM_Repository::list_customer_ids( $inbox_ids, 50 ), 0, 50 );
		}
		global $wpdb;
		$tbl    = BizCity_CRM_DB_Installer_V2::tbl_contacts();
		$digits = preg_replace( '/\D+/', '', $query );
		$where  = '`name` LIKE %s';
		$params = array( '%' . $wpdb->esc_like( $query ) . '%' );
		// [2026-10-09 03:20 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.95-S95-C4 — "tag:<slug>" ⇒ contacts whose tags_json holds exactly that tag
		// (same LIKE '%"slug"%' as the CRM repository's role filter); the inbox scope below still applies.
		$tag = self::tag_query( $query );
		if ( '' !== $tag ) {
			$where  = '`tags_json` LIKE %s';
			$params = array( '%' . $wpdb->esc_like( '"' . $tag . '"' ) . '%' );
			$digits = '';
		} elseif ( 0 === stripos( $query, 'tag:' ) ) {
			return array(); // "tag:" with nothing usable ⇒ no match, never a name search for "tag:"
		}
		if ( strlen( (string) $digits ) >= 3 ) {
			$where   .= ' OR `phone` LIKE %s';
			$params[] = '%' . $wpdb->esc_like( (string) $digits ) . '%';
		}
		$ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM `{$tbl}` WHERE deleted_at IS NULL AND ({$where}) ORDER BY updated_at DESC LIMIT 200", $params ) ) );
		if ( null === $inbox_ids ) {
			return array_slice( $ids, 0, 50 );
		}
		$out = array();
		foreach ( $ids as $id ) {
			if ( BizCity_CRM_Repository::contact_in_inboxes( $id, $inbox_ids ) ) {
				$out[] = $id;
			}
			if ( count( $out ) >= 50 ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * [2026-10-09 03:20 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.95-S95-C4 — the tag slug of a lookup query "tag:<slug>" ('' when the query is not
	 * a tag query): accents removed, lowercase, spaces ⇒ "_", only [a-z0-9_:-] kept ("tag: Nhiệt:Nóng" ⇒ "nhiet:nong"), ≤ 64 chars.
	 */
	public static function tag_query( $query ) {
		$q = trim( (string) $query );
		if ( 0 !== stripos( $q, 'tag:' ) ) {
			return '';
		}
		$slug = trim( substr( $q, 4 ) );
		if ( function_exists( 'remove_accents' ) ) {
			$slug = remove_accents( $slug );
		}
		$slug = strtr( $slug, array( 'đ' => 'd', 'Đ' => 'D' ) );
		$slug = strtolower( (string) preg_replace( '/\s+/', '_', $slug ) );
		return substr( (string) preg_replace( '/[^a-z0-9_:\-]/', '', $slug ), 0, 64 );
	}

	/** Inbox scope of the user for contact reads: null = whole shop (CRM admin), array = these inboxes. */
	public static function inbox_ids( $uid ) {
		// [2026-10-09 10:50 PM Johnny Chu - Chu Hoàng Anh] CORE-REDUCTION WP-20 W20-L5 — the core inbox access (what the plugin's
		// Customer_Pipeline::b2_inbox_ids() wrapped): null = whole shop, array = these inboxes.
		if ( class_exists( 'BizCity_CRM_Inbox_Access' ) ) {
			$allowed = BizCity_CRM_Inbox_Access::allowed_inbox_ids( (int) $uid );
			return null === $allowed ? null : array_values( array_map( 'intval', (array) $allowed ) );
		}
		return self::is_admin( $uid ) ? null : array();
	}

	/* ── staff ───────────────────────────────────────────────────── */

	/**
	 * A staff member of this site by user id or by (unique) display name; never the caller's identity.
	 *
	 * @return array|WP_Error {user_id, name, role}
	 */
	public static function staff( $user_id, $name ) {
		$user_id = (int) $user_id;
		$name    = trim( (string) $name );
		if ( $user_id <= 0 && '' !== $name ) {
			$hits = array();
			foreach ( (array) get_users( array( 'search' => '*' . $name . '*', 'search_columns' => array( 'display_name', 'user_login', 'user_nicename' ), 'number' => 10, 'fields' => array( 'ID', 'display_name' ) ) ) as $u ) {
				$id = (int) ( is_object( $u ) ? $u->ID : ( $u['ID'] ?? 0 ) );
				if ( $id > 0 && 'none' !== self::crm_role( $id ) ) {
					$hits[ $id ] = $id;
				}
			}
			if ( count( $hits ) > 1 ) {
				return self::error( BizCity_MCP_Error::QUERY_INVALID, 'Có nhiều nhân viên trùng tên "' . $name . '". Hãy nói rõ hơn.', 422 );
			}
			$user_id = (int) reset( $hits );
		}
		$user = $user_id > 0 ? get_userdata( $user_id ) : null;
		$role = $user ? self::crm_role( $user_id ) : 'none';
		if ( ! $user || 'none' === $role || ( class_exists( 'BizCity_CRM_Staff_Policy' ) && ! BizCity_CRM_Staff_Policy::is_assignable_user( $user_id ) ) ) {
			return self::error( BizCity_MCP_Error::NOT_FOUND, 'Không tìm thấy nhân viên này trong CRM của cửa hàng.', 404 );
		}
		return array( 'user_id' => $user_id, 'name' => (string) $user->display_name, 'role' => $role );
	}

	/**
	 * [2026-10-09 03:09 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.95-S95-A3/A4 — the contact as an automation run / preview needs it:
	 * {id, name, phone, email, address, birthday, crm_stage, tags}. contact() + the raw row (additional_attributes.address, birthday).
	 * Never holds a platform UID. Seam `$readers['contact_profile']`.
	 *
	 * @return array|null
	 */
	public static function contact_profile( $contact_id ) {
		$contact_id = (int) $contact_id;
		if ( $contact_id <= 0 ) {
			return null;
		}
		if ( isset( self::$readers['contact_profile'] ) ) {
			$p = call_user_func( self::$readers['contact_profile'], $contact_id );
			return is_array( $p ) ? $p : null;
		}
		$c = self::contact( $contact_id );
		if ( null === $c ) {
			return null;
		}
		$row   = class_exists( 'BizCity_CRM_Repository' ) ? (array) BizCity_CRM_Repository::get_contact( $contact_id ) : array();
		$attrs = $row['additional_attributes'] ?? array();
		$attrs = is_array( $attrs ) ? $attrs : json_decode( (string) $attrs, true );
		return array(
			'id'        => $contact_id,
			'name'      => (string) $c['name'],
			'phone'     => (string) $c['phone'],
			'email'     => (string) $c['email'],
			'address'   => is_array( $attrs ) ? trim( (string) ( $attrs['address'] ?? '' ) ) : '',
			'birthday'  => trim( (string) ( $row['birthday'] ?? '' ) ),
			'crm_stage' => (string) $c['stage'],
			'tags'      => (array) $c['tags'],
		);
	}

	/**
	 * [2026-10-09 03:09 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.95-S95-A3 — district / province only: the last two comma parts of an address
	 * ("12 Lê Thánh Tôn, Q.1, TP.HCM" ⇒ "Q.1, TP.HCM"); fewer than three parts ⇒ '' (could be the street itself).
	 */
	public static function mask_address( $address ) {
		$parts = array_values( array_filter( array_map( 'trim', explode( ',', (string) $address ) ), 'strlen' ) );
		return count( $parts ) >= 3 ? implode( ', ', array_slice( $parts, -2 ) ) : '';
	}

	/** "…" + last 3 digits; '' when too short to be a phone (same rule as the packs). */
	public static function mask_phone( $phone ) {
		$d = preg_replace( '/\D+/', '', (string) $phone );
		return strlen( (string) $d ) >= 4 ? '…' . substr( (string) $d, -3 ) : '';
	}

	public static function iso( $ts ) {
		return (int) $ts > 0 ? gmdate( 'Y-m-d\TH:i:s\Z', (int) $ts ) : '';
	}
}
