<?php
/**
 * BizCity_Channel_Core_Identity — who is calling a core route (`bizcity-channel/v1`): the logged-in WordPress user, nobody else.
 *
 * Same array shape as BizCity_TwinWeb_Identity::current() (user_id · guest_sid · is_guest · display) so the copied handlers read it unchanged, but it
 * never issues or reads the /gpt/ guest cookie: the core has no guests (the guest chat belongs to the /gpt/ add-on).
 *
 * [2026-10-23 Johnny Chu - Chu Hoàng Anh] PHASE-0.126 R126-T3 — new.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! class_exists( 'BizCity_Channel_Core_Identity', false ) ) {
	final class BizCity_Channel_Core_Identity {

		/**
		 * @return array{user_id:int, guest_sid:string, is_guest:bool, display:string}
		 */
		public static function current() {
			$user_id = (int) get_current_user_id();
			if ( $user_id <= 0 ) {
				return array( 'user_id' => 0, 'guest_sid' => '', 'is_guest' => true, 'display' => '' );
			}
			$user = get_userdata( $user_id );
			return array(
				'user_id'   => $user_id,
				'guest_sid' => '',
				'is_guest'  => false,
				'display'   => $user ? (string) $user->display_name : 'User',
			);
		}
	}
}
