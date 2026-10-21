<?php
/**
 * BizCity_Brain_Access — `brain-access@1.0.0` (core/kg-hub/contracts/BRAIN-ACCESS-v1.json): the ONE place the site decides who
 * may read / search / fetch / send / write a journal row. Every door (journal.* MCP + REST, the doc_index pack, the private
 * file route, send_document) asks here; the cell only carries the principal, it never grants (R-BRAIN-SKELETON BS-7).
 *
 * Pure: `decide()` reads the principal and the row it is given, nothing else. A denied read answers 404 (no existence leak).
 *
 * Biz Central Brain — Johnny Chu (Chu Hoàng Anh).
 *
 * [2026-10-20 10:53 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.126 S126-P1/P2 (doc 25, Q126-15/16) — new file.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\KG_Hub
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Brain_Access', false ) ) {
	return;
}

final class BizCity_Brain_Access {

	const CONTRACT    = 'brain-access@1.0.0';
	const PERMISSIONS = array( 'meta.read', 'content.read', 'search.vector', 'search.kg', 'file.get', 'file.send', 'write', 'state' );
	const PRINCIPALS  = array( 'owner', 'staff', 'group_member', 'customer', 'contact', 'automation', 'system' );
	const SWITCH      = array( 'off', 'view', 'download' );
	const READS       = array( 'meta.read', 'content.read', 'search.vector', 'search.kg', 'file.get' );
	const VIEW        = array( 'meta.read', 'content.read', 'search.vector', 'search.kg' );

	/**
	 * The principal of a turn from an MCP / REST ctx. `role` comes from the Hub-signed envelope (delegation) or the logged-in
	 * WordPress user — never from a parameter the model sends.
	 *
	 * @param array $ctx {role, user_id|owner_user_id, account_id?, gids?[], ref?}
	 * @return array{type:string,user_id:int,account_id:string,gids:string[],ref:string}
	 */
	public static function principal( array $ctx ): array {
		$role = sanitize_key( (string) ( $ctx['role'] ?? '' ) );
		$uid  = (int) ( $ctx['user_id'] ?? $ctx['owner_user_id'] ?? 0 );
		// [2026-10-20 11:33 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.126 S126-P2 — a logged-in WordPress user with no delegated role (web, app password) acts on their OWN book: owner of
		// their own rows only (the same scope journal.find always had). A role that is named but unknown stays a stranger.
		if ( '' === $role && $uid > 0 ) {
			$role = 'owner';
		}
		if ( ! in_array( $role, self::PRINCIPALS, true ) ) {
			$role = 'contact';
		}
		$gids = array();
		foreach ( (array) ( $ctx['gids'] ?? array() ) as $g ) {
			if ( 1 === preg_match( '/^[0-9]{3,30}$/', (string) $g ) ) {
				$gids[] = (string) $g;
			}
		}
		return array(
			'type'       => $role,
			'user_id'    => $uid,
			'account_id' => (string) ( $ctx['account_id'] ?? '' ),
			'gids'       => $gids,
			'ref'        => substr( (string) preg_replace( '/[^a-z0-9:_-]/', '', strtolower( (string) ( $ctx['ref'] ?? $ctx['user_hash'] ?? '' ) ) ), 0, 80 ),
		);
	}

	/** `off` · `view` · `download` of one row (journal-entry@1.2 access.customer; legacy audience=customer reads as view). Pure. */
	public static function customer_switch( array $entry ): string {
		$sw = strtolower( (string) ( $entry['access']['customer'] ?? '' ) );
		if ( in_array( $sw, self::SWITCH, true ) ) {
			return $sw;
		}
		return 'customer' === (string) ( $entry['audience'] ?? '' ) ? 'view' : 'off';
	}

	public static function is_sensitive( array $entry ): bool {
		return '' !== (string) ( $entry['flags']['sensitive'] ?? '' );
	}

	/**
	 * The decision. `$entry` is a journal-entry@1.x array (owner_user_id, flags.sensitive, access, group.gid, team?).
	 *
	 * @return array{allow:bool,code:string,status:int}
	 */
	public static function decide( array $principal, array $entry, string $permission ): array {
		if ( ! in_array( $permission, self::PERMISSIONS, true ) ) {
			return array( 'allow' => false, 'code' => 'permission_unknown', 'status' => 400 );
		}
		$allowed = self::grants( $principal, $entry );
		if ( in_array( $permission, $allowed, true ) ) {
			return array( 'allow' => true, 'code' => '', 'status' => 200 );
		}
		return array( 'allow' => false, 'code' => 'access_denied', 'status' => in_array( $permission, self::READS, true ) ? 404 : 403 );
	}

	/** Every permission this principal holds on this row (the matrix of BRAIN-ACCESS-v1.json, as code). Pure. */
	public static function grants( array $principal, array $entry ): array {
		$type  = (string) ( $principal['type'] ?? 'contact' );
		$uid   = (int) ( $principal['user_id'] ?? 0 );
		$owner = (int) ( $entry['owner_user_id'] ?? 0 );
		$own   = $uid > 0 && $uid === $owner;
		switch ( $type ) {
			case 'system':
				return self::PERMISSIONS;
			case 'owner':
				if ( $own ) {
					return self::PERMISSIONS;
				}
				// a staff row sent into a number this owner holds (Q121-8): read only
				return ! empty( $entry['team'] ) ? self::READS : array();
			case 'staff':
				return $own ? array( 'meta.read', 'content.read', 'search.vector', 'search.kg', 'file.get', 'file.send', 'write' ) : array();
			case 'automation':
				return $own ? array( 'meta.read', 'content.read', 'search.vector', 'search.kg', 'file.get', 'write' ) : array();
			case 'group_member':
				$gid = (string) ( $entry['group']['gid'] ?? '' );
				return '' !== $gid && in_array( $gid, (array) ( $principal['gids'] ?? array() ), true ) ? array( 'meta.read', 'content.read', 'search.vector', 'search.kg', 'file.get', 'file.send', 'write' ) : array();
			case 'customer':
				if ( self::is_sensitive( $entry ) ) {
					return array(); // R-JN-11: never, whatever the switch
				}
				$out = array();
				$ref = (string) ( $principal['ref'] ?? '' );
				if ( '' !== $ref && in_array( $ref, array_map( 'strval', (array) ( $entry['access']['delivered_to'] ?? array() ) ), true ) ) {
					$out = array( 'meta.read', 'content.read', 'file.get' ); // BS-7: the recipient of a delivered file
				}
				$sw = self::customer_switch( $entry );
				if ( 'view' === $sw ) {
					$out = array_merge( $out, self::VIEW );
				} elseif ( 'download' === $sw ) {
					$out = array_merge( $out, self::VIEW, array( 'file.get' ) );
				}
				return array_values( array_unique( $out ) );
			default:
				return array();
		}
	}

	/** Keep the rows this principal may `$permission`. Pure. */
	public static function filter( array $entries, array $principal, string $permission ): array {
		$out = array();
		foreach ( $entries as $e ) {
			if ( is_array( $e ) && self::decide( $principal, $e, $permission )['allow'] ) {
				$out[] = $e;
			}
		}
		return $out;
	}

	/**
	 * `file.get` on a private original by its store facts (the shape BizCity_KG_Private_File_Store::may_read had, Q121-8):
	 * owner / staff read their own; the owner of the number reads what staff sent into it (`u<id>|zalo_account:<number>`).
	 * Anything else (customer, contact, unknown) is decided per journal row by decide(), not here.
	 */
	public static function decide_file( int $file_owner, string $workspace, array $principal, string $account_id ): bool {
		$type = (string) ( $principal['type'] ?? '' );
		$uid  = (int) ( $principal['user_id'] ?? 0 );
		if ( $uid <= 0 || ! in_array( $type, array( 'owner', 'staff' ), true ) ) {
			return false;
		}
		if ( $file_owner > 0 && $file_owner === $uid ) {
			return true;
		}
		$scope = (string) substr( $workspace, (int) strpos( $workspace, '|' ) + 1 );
		return 'owner' === $type && '' !== $account_id && 'zalo_account:' . $account_id === $scope;
	}

	/** The WP_Error a denied decision becomes (code + HTTP status in data; message never names the row). */
	public static function error( array $decision ): WP_Error {
		$code = (string) ( $decision['code'] ?? 'access_denied' );
		$msg  = 'permission_unknown' === $code ? 'Quyền không hợp lệ.' : 'Không tìm thấy dòng sổ này (hoặc anh/chị không có quyền).';
		return new WP_Error( $code, $msg, array( 'status' => (int) ( $decision['status'] ?? 404 ) ) );
	}
}
