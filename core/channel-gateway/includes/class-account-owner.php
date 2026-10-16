<?php
/**
 * Biz Central Brain — Johnny Chu (Chu Hoàng Anh). Bizcity Central Brain, Giấy chứng nhận đăng ký quyền tác giả số 8877/2026/QTG.
 */
// [2026-10-17 01:00 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.116 S116-A1 (R-UW-1, R116-17) — a channel account never has "nobody" as its owner.
/**
 * Account owner — the ONE seam that guarantees a channel account has a WordPress user as its primary (owner) in the grant layer.
 *
 *   ensure( channel, account_id, hint_user_id = 0, actor_user_id = 0 )
 *     1. the account already has an active primary            ⇒ nothing changes (idempotent), source "existing"
 *     2. else the owner the creator named, or the filter `bizcity_account_owner_hint` (the plugin that owns the account table knows it)
 *                                                              ⇒ source "hint"
 *     3. else the shop owner (option `bizcity_shop_owner_user_id`, else the first administrator of this site) ⇒ source "shop_owner"
 *
 * It never invents a user and never writes owner 0. A candidate that is not a member of this site is skipped (the next rule applies).
 * It does NOT change an existing owner — moving an account to someone else is `reassign_owner()` / the takeover flow, a deliberate act.
 *
 * Zalo cá nhân already binds its owner at creation (`create_account_for_owner`); this class covers every OTHER path and repairs
 * accounts made before the rule (the Quyền tab calls it through "Gán chủ").
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\ChannelGateway
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Account_Owner', false ) ) {
	return;
}

final class BizCity_Account_Owner {

	const OPTION_SHOP_OWNER = 'bizcity_shop_owner_user_id';
	const FILTER_HINT       = 'bizcity_account_owner_hint';

	/** @return int the account's active primary user id, or 0 */
	public static function owner_of( $channel, $account_id ) {
		$primary = BizCity_Channel_User_Grant::primary_for_account( $channel, $account_id );
		return ( empty( $primary['conflict'] ) && ! empty( $primary['user_id'] ) ) ? (int) $primary['user_id'] : 0;
	}

	/** A user that can own accounts on THIS site: exists and is a member of the current blog. */
	private static function usable( $user_id ) {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 || ! get_userdata( $user_id ) ) {
			return false;
		}
		return ! function_exists( 'is_multisite' ) || ! is_multisite() || is_user_member_of_blog( $user_id, (int) get_current_blog_id() );
	}

	/** The shop's own account: the option when it names a usable user, else the first administrator of this site. 0 when there is none. */
	public static function shop_owner_id() {
		$opt = (int) get_option( self::OPTION_SHOP_OWNER, 0 );
		if ( self::usable( $opt ) ) {
			return $opt;
		}
		foreach ( (array) get_users( array( 'role' => 'administrator', 'orderby' => 'ID', 'order' => 'ASC', 'number' => 5, 'fields' => 'ID' ) ) as $id ) {
			$id = is_object( $id ) ? (int) $id->ID : (int) $id;
			if ( self::usable( $id ) ) {
				return $id;
			}
		}
		return 0;
	}

	/**
	 * @return array{ok:bool,user_id?:int,source?:string,created?:bool,reason?:string}
	 */
	public static function ensure( $channel, $account_id, $hint_user_id = 0, $actor_user_id = 0 ) {
		$existing = self::owner_of( $channel, $account_id );
		if ( $existing > 0 ) {
			return array( 'ok' => true, 'user_id' => $existing, 'source' => 'existing', 'created' => false );
		}
		$candidates = array();
		if ( (int) $hint_user_id > 0 ) {
			$candidates['hint'] = (int) $hint_user_id;
		}
		$filtered = (int) apply_filters( self::FILTER_HINT, 0, (string) $channel, (string) $account_id );
		if ( $filtered > 0 && ! in_array( $filtered, $candidates, true ) ) {
			$candidates['hint'] = $candidates['hint'] ?? $filtered;
		}
		$candidates['shop_owner'] = self::shop_owner_id();

		foreach ( $candidates as $source => $uid ) {
			if ( ! self::usable( $uid ) ) {
				continue;
			}
			$bound = BizCity_Channel_User_Grant::bind_primary_for_owner( $channel, $account_id, $uid, (int) $actor_user_id > 0 ? (int) $actor_user_id : (int) get_current_user_id(), true, array( 'source' => 'account_owner_ensure' ) );
			if ( ! empty( $bound['ok'] ) ) {
				return array( 'ok' => true, 'user_id' => $uid, 'source' => $source, 'created' => true );
			}
			// a refused bind (quota, conflict) is the answer: do NOT fall through to a different owner behind the caller's back
			return array( 'ok' => false, 'reason' => (string) ( $bound['reason'] ?? 'bind_failed' ) );
		}
		return array( 'ok' => false, 'reason' => 'no_usable_owner' );
	}
}
