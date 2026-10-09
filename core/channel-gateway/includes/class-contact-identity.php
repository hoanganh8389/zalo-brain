<?php
/**
 * Contact identity (R-CONTACT-ID, PHASE-0.90 S90-Z2/Z3) — the site side of the identity axis.
 *
 * The cell keys a person per channel as (tenant, platform, channel_ref, platform_uid) and joins one person's rows by
 * `person_id`. On the site, the person is the Identity Hub `identity_uuid` (R-CH-IDMEM); this class is the single place
 * that turns a WordPress user (owner / staff of a number) into the `person_id` + `bindings` sent in owner-agent-block@1.4.
 *
 *  - `wp` row:   channel_ref = this installation's client_instance_id (same id the site sends to the Hub), uid = user id.
 *  - `zalo` row: bound to the same identity ONLY when the UID was verified at Bot Studio (R-CID-6a). If that Zalo UID
 *                is already bound to ANOTHER identity, nothing is merged here (merging is an admin decision in CRM).
 *
 * Never throws; every failure ⇒ no person (the cell then links nothing, as with a 1.3 bundle). No raw UID in logs.
 *
 * // @axis twin-agent-axis@1 seam SEAM-2
 *
 * @package BizCity_Twin_AI
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_Contact_Identity {

	/** Shared installation id (same option as the Zalo hub client, R-B2B2C) */
	const INSTANCE_OPTION = 'bizcity_zalo_client_instance_id';

	/** R-CID-5b lowercase platform ⇒ Identity Hub label (normalize_platform keeps unknown labels as-is, upper-cased) */
	const HUB_PLATFORM = array(
		'wp'   => 'WP_USER',
		'zalo' => 'ZALO_PERSONAL',
	);

	/** @var array<int,string|null> per-request cache user_id ⇒ identity uuid */
	private static $cache = array();

	/** Stable per-installation id; created once with the same format as the Zalo hub client. */
	public static function site_instance(): string {
		$v = function_exists( 'get_option' ) ? sanitize_key( (string) get_option( self::INSTANCE_OPTION, '' ) ) : '';
		if ( '' !== $v ) {
			return $v;
		}
		if ( ! function_exists( 'wp_generate_uuid4' ) || ! function_exists( 'update_option' ) ) {
			return '';
		}
		$v = 'b2i_' . str_replace( '-', '', wp_generate_uuid4() );
		update_option( self::INSTANCE_OPTION, $v, false );
		return $v;
	}

	/** Identity Hub uuid of a WP user (creates the `wp` binding once). Null when the hub is unavailable. */
	public static function person_of_user( int $user_id ): ?string {
		if ( $user_id <= 0 || ! class_exists( 'BizCity_Identity_Hub' ) ) {
			return null;
		}
		if ( array_key_exists( $user_id, self::$cache ) ) {
			return self::$cache[ $user_id ];
		}
		$site = self::site_instance();
		$uuid = null;
		if ( '' !== $site ) {
			try {
				$r    = BizCity_Identity_Hub::bind( self::HUB_PLATFORM['wp'], $site, (string) $user_id, $user_id );
				$uuid = is_array( $r ) && ! empty( $r['identity_uuid'] ) ? strtolower( (string) $r['identity_uuid'] ) : null;
			} catch ( Throwable $e ) {
				$uuid = null;
			}
		}
		self::$cache[ $user_id ] = $uuid;
		return $uuid;
	}

	/**
	 * Bind a VERIFIED Zalo UID of this number to the user's identity. Returns true when the Zalo row belongs to that
	 * identity afterwards; false when unverified, unavailable, or already someone else's (not merged here).
	 */
	public static function link_zalo( int $user_id, string $account_id, string $uid, ?string $verified_at ): bool {
		$uid = trim( $uid );
		if ( empty( $verified_at ) || '' === $uid || '' === trim( $account_id ) || ! class_exists( 'BizCity_Identity_Hub' ) ) {
			return false;
		}
		$person = self::person_of_user( $user_id );
		if ( null === $person ) {
			return false;
		}
		try {
			$existing = BizCity_Identity_Hub::resolve_binding( self::HUB_PLATFORM['zalo'], $account_id, $uid );
			if ( is_array( $existing ) ) {
				return strtolower( (string) ( $existing['identity_uuid'] ?? '' ) ) === $person;
			}
			$r = BizCity_Identity_Hub::bind( self::HUB_PLATFORM['zalo'], $account_id, $uid, $user_id );
			return is_array( $r ) && strtolower( (string) ( $r['identity_uuid'] ?? '' ) ) === $person;
		} catch ( Throwable $e ) {
			return false;
		}
	}

	/**
	 * owner-agent-block@1.4 person fields for one principal: `person_id` + the person's `wp` binding on this site.
	 * Empty array ⇒ send nothing (the cell links nothing).
	 *
	 * @return array{person_id?:string,bindings?:list<array{platform:string,channel_ref:string,platform_uid:string}>}
	 */
	public static function bundle_person( int $user_id, string $account_id = '', string $uid = '', ?string $verified_at = null ): array {
		$person = self::person_of_user( $user_id );
		$site   = self::site_instance();
		if ( null === $person || '' === $site ) {
			return array();
		}
		if ( '' !== $account_id && '' !== $uid ) {
			self::link_zalo( $user_id, $account_id, $uid, $verified_at );
		}
		return array(
			'person_id' => $person,
			'bindings'  => array(
				array( 'platform' => 'wp', 'channel_ref' => $site, 'platform_uid' => (string) $user_id ),
			),
		);
	}

	/** Test seam: forget the per-request cache. */
	public static function reset_cache(): void {
		self::$cache = array();
	}
}
