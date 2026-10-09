<?php
/**
 * Web home of the Twin Agent (PHASE-0.90 S90-W2/W4, R-CONTACT-ID): who is the OWNER and who are the STAFF of this site's own
 * web channel (Ask Brain, `/gpt/`) when the site has no Zalo number to answer through.
 *
 * Roles come from server facts only (R-TAA-3): the owner is the site's primary administrator
 * (option `bizcity_twin_web_owner_user_id`, else the user of `admin_email`, else the first administrator); every other
 * user that holds ≥ 1 agent mode (agent-mode-access@1) is staff; everybody else is a customer. The same list is projected to
 * the cell as the web-home block (owner-agent-block@1.4, no Zalo UID) through the Hub `PUT zalo-hub/web-home`; the cell checks
 * a web owner against it (S90-A5), so the site's `role` is never the only proof.
 *
 * The push is lazy and cheap: the block is rebuilt at most once a minute (and when `bizcity_agent_modes_changed` fires), hashed,
 * and sent only when the hash changed. A failed push never blocks a turn: the cell keeps its previous block and fails closed
 * to customer for anyone it cannot prove.
 *
 * // @axis twin-agent-axis@1 seam SEAM-2
 *
 * @package BizCity_Twin_AI
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Twin_Web_Home', false ) ) {
	return;
}

final class BizCity_Twin_Web_Home {

	const OWNER_OPTION   = 'bizcity_twin_web_owner_user_id';
	const PUSHED_OPTION  = 'bizcity_twin_web_home_pushed';
	const CHECK_KEY      = 'bizcity_twin_web_home_checked';
	const PEOPLE_KEY     = 'bizcity_twin_web_home_people';
	const CHECK_TTL      = 60;
	const STAFF_MAX      = 20; // cell MAX_STAFF_PRINCIPALS
	const PATH           = '/zalo-hub/web-home';
	const TOKEN_OPTION   = 'bizcity_twin_web_bridge_token';
	const FILTER         = 'bizcity_twin_web_home'; // false ⇒ the old behaviour (no number ⇒ PHP), the documented way back
	const CANDIDATE_ROLES = array( 'administrator', 'editor', 'shop_manager', 'author', 'contributor' );

	/**
	 * Test seams: option(name, default) · update_option(name, value) · people(): list<{user_id,user_hash,modes[]}> ·
	 * owner_user_id(): int · person(user_id): array (bundle_person shape) · push(body): bool · instance(): string · enabled(): bool
	 *
	 * @var array<string,callable>
	 */
	public static $readers = array();

	public static function enabled(): bool {
		if ( isset( self::$readers['enabled'] ) ) {
			return (bool) call_user_func( self::$readers['enabled'] );
		}
		return ! function_exists( 'apply_filters' ) || (bool) apply_filters( self::FILTER, true );
	}

	/** The site's web channel id (`channel_ref` of the `wp` contact): its installation id, shared with the Hub registration. */
	public static function channel_ref(): string {
		if ( isset( self::$readers['instance'] ) ) {
			return (string) call_user_func( self::$readers['instance'] );
		}
		return class_exists( 'BizCity_Contact_Identity' ) ? BizCity_Contact_Identity::site_instance() : '';
	}

	/** Candidate people (≥ 1 mode, hash known), owner first. @return list<array{user_id:int,user_hash:string,modes:string[]}> */
	public static function people(): array {
		if ( isset( self::$readers['people'] ) ) {
			return (array) call_user_func( self::$readers['people'] );
		}
		$cached = function_exists( 'get_transient' ) ? get_transient( self::PEOPLE_KEY ) : false;
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$out = array();
		if ( function_exists( 'get_users' ) && class_exists( 'BizCity_Agent_Mode_Access' ) ) {
			$users = (array) get_users( array( 'role__in' => self::CANDIDATE_ROLES, 'number' => self::STAFF_MAX + 5, 'fields' => 'ID', 'orderby' => 'ID' ) );
			foreach ( $users as $uid ) {
				$uid   = (int) $uid;
				$modes = BizCity_Agent_Mode_Access::modes_for_user( $uid );
				$hash  = BizCity_Agent_Mode_Access::user_hash( $uid );
				if ( $uid > 0 && $modes && '' !== $hash ) {
					$out[] = array( 'user_id' => $uid, 'user_hash' => $hash, 'modes' => array_values( $modes ) );
				}
			}
		}
		if ( function_exists( 'set_transient' ) ) {
			set_transient( self::PEOPLE_KEY, $out, self::CHECK_TTL );
		}
		return $out;
	}

	/** The site's primary owner among `people()`: configured user, else admin_email's user, else the first administrator. */
	public static function owner_user_id( array $people = array() ): int {
		if ( isset( self::$readers['owner_user_id'] ) ) {
			return (int) call_user_func( self::$readers['owner_user_id'] );
		}
		$people = $people ? $people : self::people();
		$ids    = array_map( static function ( $p ) { return (int) $p['user_id']; }, $people );
		$cfg    = (int) self::option( self::OWNER_OPTION, 0 );
		if ( $cfg > 0 && in_array( $cfg, $ids, true ) ) {
			return $cfg;
		}
		if ( function_exists( 'get_option' ) && function_exists( 'get_user_by' ) ) {
			$u = get_user_by( 'email', (string) get_option( 'admin_email', '' ) );
			if ( $u && in_array( (int) $u->ID, $ids, true ) ) {
				return (int) $u->ID;
			}
		}
		foreach ( $people as $p ) {
			if ( class_exists( 'BizCity_Agent_Mode_Access' ) && BizCity_Agent_Mode_Access::is_site_admin( (int) $p['user_id'] ) ) {
				return (int) $p['user_id'];
			}
		}
		return 0;
	}

	/**
	 * Server-side role of a user on the web home.
	 *
	 * @return array{role:string,role_evidence:string,modes:string[],user_hash:string}
	 */
	public static function role_of( int $user_id ): array {
		$none = array( 'role' => 'customer', 'role_evidence' => 'default_customer', 'modes' => array(), 'user_hash' => '' );
		if ( $user_id <= 0 ) {
			return $none;
		}
		$people = self::people();
		$owner  = self::owner_user_id( $people );
		foreach ( $people as $p ) {
			if ( (int) $p['user_id'] !== $user_id ) {
				continue;
			}
			return array(
				'role'          => ( $owner === $user_id ) ? 'owner' : 'staff',
				'role_evidence' => 'wp_principal',
				'modes'         => array_values( $p['modes'] ),
				'user_hash'     => (string) $p['user_hash'],
			);
		}
		return $none;
	}

	/**
	 * Credential the Hub presents when it carries the cell's MCP calls to this site's web bridge (BizCity_Twin_Web_Bridge_REST).
	 * Random, created once, kept in an option (autoload off); only ever sent to the Hub over the site's 1API key.
	 */
	public static function bridge_token(): string {
		if ( isset( self::$readers['bridge_token'] ) ) {
			return (string) call_user_func( self::$readers['bridge_token'] );
		}
		$t = (string) self::option( self::TOKEN_OPTION, '' );
		if ( strlen( $t ) < 32 ) {
			$t = function_exists( 'wp_generate_password' ) ? wp_generate_password( 48, false, false ) : bin2hex( random_bytes( 24 ) );
			self::update_option( self::TOKEN_OPTION, $t );
		}
		return $t;
	}

	/** Public MCP URL of this site's web bridge ('' when the bridge class is not loaded) */
	public static function bridge_url(): string {
		if ( isset( self::$readers['bridge_url'] ) ) {
			return (string) call_user_func( self::$readers['bridge_url'] );
		}
		return class_exists( 'BizCity_Twin_Web_Bridge_REST' ) ? BizCity_Twin_Web_Bridge_REST::url() : '';
	}

	/**
	 * Active owner/staff of the web home with this user_hash, as a delegation principal {role, user_id, user_hash, modes}, or null.
	 * The ONLY way the web bridge learns who is calling (never from the request).
	 */
	public static function principal_by_hash( string $hash ): ?array {
		$hash = strtolower( trim( $hash ) );
		if ( 1 !== preg_match( '/^[0-9a-f]{64}$/', $hash ) ) {
			return null;
		}
		$people = self::people();
		$owner  = self::owner_user_id( $people );
		foreach ( $people as $p ) {
			if ( hash_equals( strtolower( (string) $p['user_hash'] ), $hash ) ) {
				return array(
					'role'      => ( (int) $p['user_id'] === $owner ) ? 'owner' : 'staff',
					'user_id'   => (int) $p['user_id'],
					'user_hash' => $hash,
					'modes'     => array_values( $p['modes'] ),
				);
			}
		}
		return null;
	}

	/** owner-agent-block@1.4 for the web home (no Zalo UID; the people are projected with their Identity Hub person). */
	public static function block(): array {
		$people = self::people();
		$owner  = self::owner_user_id( $people );
		$block  = array( 'enabled' => false, 'principal' => null, 'capture' => array( 'files' => false, 'remember' => false ), 'owner_knowledge_ref' => '', 'staff' => array() );
		foreach ( $people as $p ) {
			$uid    = (int) $p['user_id'];
			$person = isset( self::$readers['person'] ) ? (array) call_user_func( self::$readers['person'], $uid ) : ( class_exists( 'BizCity_Contact_Identity' ) ? BizCity_Contact_Identity::bundle_person( $uid ) : array() );
			if ( $uid === $owner ) {
				$block['enabled']   = true;
				$block['principal'] = array( 'user_hash' => $p['user_hash'], 'modes' => array_values( $p['modes'] ) ) + $person;
			} elseif ( count( $block['staff'] ) < self::STAFF_MAX ) {
				// the cell keys a staff entry by a uid string: there is no Zalo UID here, so a stable non-numeric id (never a Zalo sender)
				$block['staff'][] = array( 'uid' => 'web:' . $uid, 'user_hash' => $p['user_hash'], 'modes' => array_values( $p['modes'] ), 'capture' => array( 'files' => false, 'remember' => false ) ) + $person;
			}
		}
		return $block;
	}

	/**
	 * Push the block to the cell when it changed since the last successful push. Cheap on the hot path: one transient read
	 * within a minute. Returns true when the cell has the current block (unchanged or just pushed).
	 */
	public static function ensure_pushed( bool $force = false ): bool {
		if ( ! $force && function_exists( 'get_transient' ) && get_transient( self::CHECK_KEY ) ) {
			return true;
		}
		$channel = self::channel_ref();
		if ( '' === $channel ) {
			return false;
		}
		$body = array( 'channel_ref' => $channel, 'owner_agent' => self::block() );
		$url  = self::bridge_url();
		if ( '' !== $url ) {
			// Hub keeps it encrypted and uses it to carry the cell's MCP calls to this site (R-CID-4: the channel belongs to this key)
			$body['callback'] = array( 'url' => $url, 'token' => self::bridge_token() );
		}
		$hash = hash( 'sha256', wp_json_encode( $body ) );
		$ok   = true;
		if ( (string) self::option( self::PUSHED_OPTION, '' ) !== $hash ) {
			$ok = self::push( $body );
			if ( $ok ) {
				self::update_option( self::PUSHED_OPTION, $hash );
			}
		}
		if ( $ok && function_exists( 'set_transient' ) ) {
			set_transient( self::CHECK_KEY, 1, self::CHECK_TTL );
		}
		return $ok;
	}

	/** Forget the caches (agent modes changed): the next turn rebuilds and re-pushes if the block differs. */
	public static function invalidate(): void {
		if ( function_exists( 'delete_transient' ) ) {
			delete_transient( self::CHECK_KEY );
			delete_transient( self::PEOPLE_KEY );
		}
	}

	private static function push( array $body ): bool {
		if ( isset( self::$readers['push'] ) ) {
			return (bool) call_user_func( self::$readers['push'], $body );
		}
		if ( ! class_exists( 'BizCity_Zalo_Personal_Hub_Client' ) || ! method_exists( 'BizCity_Zalo_Personal_Hub_Client', 'stream_target' ) || ! function_exists( 'wp_remote_request' ) ) {
			return false;
		}
		$t = BizCity_Zalo_Personal_Hub_Client::instance()->stream_target( self::PATH );
		if ( ! is_array( $t ) || empty( $t['key'] ) ) {
			return false;
		}
		$headers = array( 'Authorization' => 'Bearer ' . (string) $t['key'], 'Content-Type' => 'application/json', 'X-Site-URL' => function_exists( 'home_url' ) ? home_url() : '' );
		foreach ( (array) ( $t['headers'] ?? array() ) as $k => $v ) {
			$headers[ $k ] = $v;
		}
		$r = wp_remote_request( (string) $t['url'], array( 'method' => 'PUT', 'timeout' => 8, 'redirection' => 0, 'headers' => $headers, 'body' => wp_json_encode( $body ) ) );
		if ( is_wp_error( $r ) ) {
			return false;
		}
		$code = (int) wp_remote_retrieve_response_code( $r );
		$data = json_decode( (string) wp_remote_retrieve_body( $r ), true );
		return $code >= 200 && $code < 300 && is_array( $data ) && ! empty( $data['ok'] );
	}

	private static function option( string $name, $default ) {
		if ( isset( self::$readers['option'] ) ) {
			return call_user_func( self::$readers['option'], $name, $default );
		}
		return function_exists( 'get_option' ) ? get_option( $name, $default ) : $default;
	}

	private static function update_option( string $name, $value ): void {
		if ( isset( self::$readers['update_option'] ) ) {
			call_user_func( self::$readers['update_option'], $name, $value );
			return;
		}
		if ( function_exists( 'update_option' ) ) {
			update_option( $name, $value, false );
		}
	}
}

if ( function_exists( 'add_action' ) ) {
	add_action( 'bizcity_agent_modes_changed', array( 'BizCity_Twin_Web_Home', 'invalidate' ) );
}
