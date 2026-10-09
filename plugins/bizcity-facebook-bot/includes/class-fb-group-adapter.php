<?php
/**
 * Facebook groups (PHASE-0.90 S90-F4 SKELETON) - behind its OWN flag `bizcity_fb_groups` (default OFF).
 * platform `fb_group`, channel_ref = group_id. A1 reads the group feed on a schedule (there is no webhook), A3 replies under the
 * comment (BizCity_FB_Channel_Adapter::send), a group post goes through post(). Needs groups_access_member_info + publish_to_groups,
 * else step 3 shows "nhóm ✖". Everything goes through the adapter's Graph seam; NOT exercised against the real Graph API.
 *
 * @package BizCity_Facebook_Bot
 * @since   PHASE-0.90 S90-F4
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_FB_Group_Adapter', false ) ) {
	return;
}

final class BizCity_FB_Group_Adapter {

	const FLAG     = 'bizcity_fb_groups';
	const PLATFORM = 'fb_group';
	const SCOPES   = array( 'groups_access_member_info', 'publish_to_groups' );

	public static function enabled(): bool {
		return in_array( strtolower( (string) get_option( self::FLAG, '' ) ), array( '1', 'on', 'yes', 'true' ), true );
	}

	/** @return array {ok, missing[], known} - known=false when the OAuth never recorded the granted scopes. */
	public static function permission(): array {
		$granted = BizCity_FB_Channel_Adapter::granted_scopes();
		if ( null === $granted ) {
			return array( 'ok' => false, 'missing' => self::SCOPES, 'known' => false );
		}
		$missing = array_values( array_diff( self::SCOPES, $granted ) );
		return array( 'ok' => ! $missing, 'missing' => $missing, 'known' => true );
	}

	/**
	 * A1: the group's recent comments as group events ({group_id, comment{id,message,from,post_id,created_time}, self_id}),
	 * oldest first, only those newer than the stored cursor. Feed it to BizCity_FB_Channel_Adapter::build_inbound().
	 *
	 * @return array {ok, events[], error?}
	 */
	public static function read_group_feed( string $group_id, string $token, string $self_id = '' ): array {
		if ( ! self::enabled() ) {
			return array( 'ok' => false, 'events' => array(), 'error' => array( 'code' => 'groups_disabled', 'message' => 'Nhóm Facebook đang tắt.', 'hint' => 'Bật cờ bizcity_fb_groups sau khi cấp quyền nhóm.', 'help_code' => 'S90-F4-OFF' ) );
		}
		$perm = self::permission();
		if ( ! $perm['ok'] ) {
			return array( 'ok' => false, 'events' => array(), 'error' => array( 'code' => 'groups_permission_missing', 'message' => 'Chưa cấp quyền nhóm.', 'hint' => 'Đăng nhập lại Facebook và cấp ' . implode( ', ', $perm['missing'] ) . '.', 'help_code' => 'S90-F4-PERM' ) );
		}
		$r = BizCity_FB_Channel_Adapter::graph( 'GET', '/' . rawurlencode( $group_id ) . '/feed', array( 'fields' => 'id,comments.limit(25){id,message,from,created_time}', 'limit' => 10 ), $token );
		if ( ! $r['ok'] ) {
			return array( 'ok' => false, 'events' => array(), 'error' => array( 'code' => 'graph_error', 'message' => (string) $r['error']['message'], 'hint' => 'Kiểm tra quyền nhóm rồi thử lại.', 'help_code' => 'S90-F4-GRAPH' ) );
		}
		$cursor_key = 'bizcity_fb_group_cursor_' . md5( $group_id );
		$cursor     = (int) get_option( $cursor_key, 0 );
		$max        = $cursor;
		$events     = array();
		foreach ( (array) ( $r['data']['data'] ?? array() ) as $post ) {
			foreach ( (array) ( $post['comments']['data'] ?? array() ) as $c ) {
				$ts = (int) strtotime( (string) ( $c['created_time'] ?? '' ) );
				if ( $ts <= $cursor || empty( $c['id'] ) ) {
					continue;
				}
				$max      = max( $max, $ts );
				$events[] = array( 'group_id' => $group_id, 'self_id' => $self_id, 'ts' => $ts, 'comment' => array( 'id' => (string) $c['id'], 'message' => (string) ( $c['message'] ?? '' ), 'from' => (array) ( $c['from'] ?? array() ), 'post_id' => (string) $post['id'], 'created_time' => $ts ) );
			}
		}
		usort( $events, static function ( $a, $b ) { return $a['ts'] <=> $b['ts']; } );
		if ( $max > $cursor ) {
			update_option( $cursor_key, $max, false );
		}
		return array( 'ok' => true, 'events' => $events );
	}

	/** Group post (fb.group.post). @return array {ok, post_id?, error?} */
	public static function post( string $group_id, string $message, string $token ): array {
		if ( ! self::enabled() || ! self::permission()['ok'] ) {
			return array( 'ok' => false, 'error' => array( 'code' => 'groups_disabled', 'message' => 'Nhóm Facebook chưa bật hoặc chưa đủ quyền.' ) );
		}
		$r = BizCity_FB_Channel_Adapter::graph( 'POST', '/' . rawurlencode( $group_id ) . '/feed', array( 'message' => $message ), $token );
		return $r['ok'] ? array( 'ok' => true, 'post_id' => (string) ( $r['data']['id'] ?? '' ) ) : array( 'ok' => false, 'error' => $r['error'] );
	}
}
