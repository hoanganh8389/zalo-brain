<?php
/**
 * Number work user — R-NUMBER-BRAIN R-NB-11 (PHASE-0.97 round 4, S97-W1).
 *
 * Every Zalo number works as ONE WordPress user ("Làm việc bằng quyền của"). A number never has "nobody": when the
 * administrator adds a number, step ③ offers, as the DEFAULT, "tạo tài khoản tự động" — this class creates that user —
 * or picks an existing user from the list. The user is the place the site stores what the number's reminders / inbox
 * / notebook need, and the ONLY source of what the Brain may do (WP role + caps, R-NB-3). It is deliberately minimal:
 *   - role = `subscriber` (filter `bizcity_number_work_user_role`); a role that holds an administrative cap is refused
 *     (fail closed → subscriber), so "auto" can never become a privilege escalation;
 *   - random password that is never stored or shown (the administrator can reset it in wp-admin if a person must log in);
 *   - address on the reserved `.invalid` TLD (RFC 2606): the account can never receive or send real mail;
 *   - idempotent per phone: a retry (double click, reload before QR) returns the same user, never a second one.
 * Raising what the Brain may do = raise this user's role in WordPress. Nothing here grants a cap.
 *
 * // @axis number-brain@1
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\ChannelGateway
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Number_Work_User', false ) ) {
	return;
}

final class BizCity_Number_Work_User {

	/** User meta: the phone digits this auto user was created for (idempotency key + "this account is automatic" marker). */
	const META_PHONE  = 'bizcity_number_work_phone';
	const FILTER_ROLE = 'bizcity_number_work_user_role';
	const DEFAULT_ROLE = 'subscriber';
	const EMAIL_DOMAIN = 'invalid.invalid';

	/** A role holding any of these is never used for an automatic account. */
	const FORBIDDEN_CAPS = array( 'manage_options', 'manage_network', 'edit_users', 'promote_users', 'create_users', 'unfiltered_upload', 'edit_others_posts', 'publish_posts', 'edit_posts' );

	/** Role of an automatic account: the filter's answer when it exists and is powerless, else `subscriber`. */
	public static function role(): string {
		$role = sanitize_key( (string) apply_filters( self::FILTER_ROLE, self::DEFAULT_ROLE ) );
		if ( '' === $role || ! function_exists( 'get_role' ) ) {
			return self::DEFAULT_ROLE;
		}
		$obj = get_role( $role );
		if ( ! $obj ) {
			return self::DEFAULT_ROLE;
		}
		foreach ( self::FORBIDDEN_CAPS as $cap ) {
			if ( $obj->has_cap( $cap ) ) {
				return self::DEFAULT_ROLE;
			}
		}
		return $role;
	}

	/** Digits of a phone label ("0912 345 678" ⇒ "0912345678"); '' when there are fewer than 8. */
	public static function digits( string $label ): string {
		$d = preg_replace( '/\D+/', '', $label );
		return is_string( $d ) && strlen( $d ) >= 8 && strlen( $d ) <= 15 ? $d : '';
	}

	/** The automatic user already made for this phone on THIS blog, or 0. */
	public static function find_for_phone( string $digits ): int {
		if ( '' === $digits ) {
			return 0;
		}
		$ids = get_users( array(
			'blog_id'    => (int) get_current_blog_id(),
			'meta_key'   => self::META_PHONE, // phpcs:ignore WordPress.DB.SlowDBQuery
			'meta_value' => $digits,          // phpcs:ignore WordPress.DB.SlowDBQuery
			'fields'     => 'ID',
			'number'     => 1,
		) );
		return $ids ? (int) $ids[0] : 0;
	}

	/**
	 * Get-or-create the automatic work user of a number.
	 *
	 * @param string $label Phone label as typed in step ③ (digits are taken from it).
	 * @return array{user_id:int,display_name:string,created:bool,roles:string[],caps:string[]}|WP_Error
	 */
	public static function ensure( string $label ) {
		$digits = self::digits( $label );
		if ( '' === $digits ) {
			return new WP_Error( 'invalid_param', 'Số điện thoại chưa đúng nên chưa tạo được tài khoản tự động.' );
		}
		$existing = self::find_for_phone( $digits );
		if ( $existing > 0 ) {
			return self::describe( $existing, false );
		}
		$login = 'zalo-' . $digits;
		if ( username_exists( $login ) ) {
			$login .= '-' . strtolower( wp_generate_password( 4, false, false ) );
		}
		$email = $login . '@' . self::EMAIL_DOMAIN;
		$name  = 'Zalo ' . $digits;
		$id    = wp_insert_user( array(
			'user_login'   => $login,
			'user_pass'    => wp_generate_password( 64, true, true ), // never stored or shown anywhere
			'user_email'   => $email,
			'display_name' => $name,
			'nickname'     => $name,
			'role'         => self::role(),
		) );
		if ( is_wp_error( $id ) ) {
			return new WP_Error( 'user_create_failed', 'Không tạo được tài khoản tự động.', array( 'reason' => $id->get_error_code() ) );
		}
		$id = (int) $id;
		if ( function_exists( 'is_multisite' ) && is_multisite() && function_exists( 'add_user_to_blog' ) && ! is_user_member_of_blog( $id, (int) get_current_blog_id() ) ) {
			add_user_to_blog( (int) get_current_blog_id(), $id, self::role() );
		}
		update_user_meta( $id, self::META_PHONE, $digits );
		update_user_meta( $id, 'show_admin_bar_front', 'false' );
		return self::describe( $id, true );
	}

	/** True for an account made by {@see ensure()} (admin lists show "tự động"). */
	public static function is_auto( int $user_id ): bool {
		return $user_id > 0 && '' !== (string) get_user_meta( $user_id, self::META_PHONE, true );
	}

	/** @return array{user_id:int,display_name:string,created:bool,roles:string[],caps:string[]} */
	private static function describe( int $user_id, bool $created ): array {
		$user      = get_userdata( $user_id );
		$wp_roles  = function_exists( 'wp_roles' ) ? wp_roles()->get_names() : array();
		$labels    = array();
		foreach ( $user ? (array) $user->roles : array() as $slug ) {
			$labels[] = isset( $wp_roles[ $slug ] ) ? translate_user_role( $wp_roles[ $slug ] ) : (string) $slug;
		}
		return array(
			'user_id'      => $user_id,
			'display_name' => $user ? sanitize_text_field( (string) $user->display_name ) : '',
			'created'      => $created,
			'roles'        => $labels,
			'caps'         => class_exists( 'BizCity_Agent_Mode_Access' ) && method_exists( 'BizCity_Agent_Mode_Access', 'caps_for_user' ) ? BizCity_Agent_Mode_Access::caps_for_user( $user_id ) : array(),
		);
	}
}
