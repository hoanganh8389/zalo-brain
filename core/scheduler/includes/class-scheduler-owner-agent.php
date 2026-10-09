<?php
/**
 * BizCity_Scheduler_Owner_Agent — the owner agent of a WP user: the active owner/staff principal of that user on one of this
 * site's zalo-hub numbers. It is who receives a calendar reminder fired by the cell (PHASE-0.92 D92-13, doc 71 §4.1).
 * No principal ⇒ null, and the caller says so out loud (REST 409 + four-step), never a silent drop.
 *
 * Biz Central Brain — Johnny Chu (Chu Hoàng Anh). Bizcity Central Brain, Giấy chứng nhận đăng ký quyền tác giả
 * số 8877/2026/QTG (Cục Bản quyền tác giả, 14/09/2026). R-BIZ-CENTRAL-BRAIN R-BCB-8.
 *
 * // [2026-10-06 11:15 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.92-S92-SC-2 — new file (lookup moved from Report_Back::requester()).
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Scheduler
 * @since      2026-10-06
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

if ( class_exists( 'BizCity_Scheduler_Owner_Agent' ) ) {
	return;
}

final class BizCity_Scheduler_Owner_Agent {

	/**
	 * Test seams: user_hash(user_id):string · accounts():list<{bridge_id}> · by_hash(bridge,hash):?{role,uid,user_id} ·
	 * person_id(user_id,bridge,uid):string
	 *
	 * @var array<string,callable>
	 */
	public static $readers = array();

	/**
	 * @return array{hash:string,number_ref:string,person_id:string,role:string}|null
	 */
	public static function resolve( int $user_id ) {
		if ( $user_id <= 0 ) {
			return null;
		}
		$hash = strtolower( self::user_hash( $user_id ) );
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $hash ) ) {
			return null;
		}
		return self::by_hash( $hash );
	}

	/**
	 * The owner agent behind a principal hash: the owner seat wins over a staff seat, then the first number.
	 *
	 * @return array{hash:string,number_ref:string,person_id:string,role:string}|null
	 */
	public static function by_hash( string $hash ) {
		$hash = strtolower( trim( $hash ) );
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $hash ) ) {
			return null;
		}
		$found = null;
		foreach ( self::accounts() as $acc ) {
			$bridge = is_array( $acc ) ? (string) ( $acc['bridge_id'] ?? '' ) : (string) $acc;
			if ( '' === $bridge ) {
				continue;
			}
			$p = self::principal( $bridge, $hash );
			if ( null === $p ) {
				continue;
			}
			$hit = array( 'bridge' => $bridge, 'p' => $p );
			if ( 'owner' === (string) ( $p['role'] ?? '' ) ) {
				$found = $hit;
				break;
			}
			if ( null === $found ) {
				$found = $hit;
			}
		}
		if ( null === $found ) {
			return null;
		}
		$p = $found['p'];
		return array(
			'hash'       => $hash,
			'number_ref' => $found['bridge'],
			'person_id'  => self::person_id( (int) ( $p['user_id'] ?? 0 ), $found['bridge'], (string) ( $p['uid'] ?? '' ) ),
			'role'       => (string) ( $p['role'] ?? '' ),
		);
	}

	// ─── Readers ────────────────────────────────────────────────────────

	private static function user_hash( int $user_id ): string {
		if ( isset( self::$readers['user_hash'] ) ) {
			return (string) call_user_func( self::$readers['user_hash'], $user_id );
		}
		return class_exists( 'BizCity_Zalo_Agent_Principals' ) ? (string) BizCity_Zalo_Agent_Principals::user_hash( $user_id ) : '';
	}

	/** zalo-hub numbers of this site: list<{bridge_id, owner_user_id, …}>. */
	public static function accounts(): array {
		if ( isset( self::$readers['accounts'] ) ) {
			return (array) call_user_func( self::$readers['accounts'] );
		}
		return class_exists( 'BizCity_Zalo_Hub_Config_Sync' ) ? (array) BizCity_Zalo_Hub_Config_Sync::accounts() : array();
	}

	private static function principal( string $bridge, string $hash ) {
		if ( isset( self::$readers['by_hash'] ) ) {
			$p = call_user_func( self::$readers['by_hash'], $bridge, $hash );
			return is_array( $p ) ? $p : null;
		}
		return class_exists( 'BizCity_Zalo_Agent_Principals' ) ? BizCity_Zalo_Agent_Principals::by_hash( $bridge, $hash ) : null;
	}

	/** R-CONTACT-ID person uuid (S90-Z3 person fields) — '' when the Identity Hub has none; never sent to the cell. */
	private static function person_id( int $user_id, string $bridge, string $uid ): string {
		if ( isset( self::$readers['person_id'] ) ) {
			return (string) call_user_func( self::$readers['person_id'], $user_id, $bridge, $uid );
		}
		// Read-only: bundle_person() would also write the Zalo link, which is the bundle's job, not a calendar save.
		if ( $user_id <= 0 || ! class_exists( 'BizCity_Contact_Identity' ) || ! method_exists( 'BizCity_Contact_Identity', 'person_of_user' ) ) {
			return '';
		}
		try {
			$id = (string) BizCity_Contact_Identity::person_of_user( $user_id );
		} catch ( \Throwable $e ) {
			return '';
		}
		return preg_match( '/^[A-Za-z0-9-]{8,36}$/', $id ) ? $id : '';
	}
}
