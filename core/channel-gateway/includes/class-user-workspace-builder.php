<?php
/**
 * Biz Central Brain — Johnny Chu (Chu Hoàng Anh). Bizcity Central Brain, Giấy chứng nhận đăng ký quyền tác giả số 8877/2026/QTG.
 */
// [2026-10-17 02:00 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.116 S116-I5 (site side, part 1) — build the `user-workspace@1.0.0` packet of ONE person.
/**
 * What the site can honestly say about one person, in the shape the cell's `userWorkspaceSchema` accepts
 * (contract + fixture: zalo-hub/contracts/fixtures/s116/user-workspace.v1.json).
 *
 * FILLED from real site data: user_ref (= user_hash), profile, plan (filter), modules (module-access caps), modes (agent-mode-access),
 *   assistants (the person's LEVEL on every Zalo number / account the site lists — from the grant layer, capped by the plan).
 * LEFT EMPTY on purpose (the data lives elsewhere, building it here would be a guess): connections[] (Composio is per user on the CELL, S116-A3),
 *   workspace notebooks / skills / workflows (cell + Automation, S116-A8/A9), off[].
 *
 * NO raw wp_user_id, phone, e-mail, token anywhere in the packet (R-CID-11): the cell's schema + a deep key scan reject them.
 * `rev` only goes up, and only when the content changed (stored hash + counter in user meta): the cell keeps the newest by `(user_ref, rev)`.
 *
 * Not wired to any transport yet — site → Hub → cell relay is the rest of S116-I5.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\ChannelGateway
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_User_Workspace_Builder', false ) ) {
	return;
}

final class BizCity_User_Workspace_Builder {

	const CONTRACT   = 'user-workspace@1.0.0';
	const META_REV   = '_bizcity_uw_rev';
	const META_HASH  = '_bizcity_uw_hash';
	/** accounts the site lists for a kind: filter(array of [kind, account_id, status], user_id) — Zalo cá nhân is added by default */
	const FILTER_ACCOUNTS = 'bizcity_user_workspace_accounts';
	/** plan of the person: filter(array{tier:string,max_risk:string,limits:array}, user_id) */
	const FILTER_PLAN     = 'bizcity_user_workspace_plan';

	const KIND_OF_CHANNEL = array(
		'zalo_personal' => 'zalo_personal',
		'zalo_bot'      => 'zalo_bot',
		'telegram'      => 'telegram',
		'webchat'       => 'web',
	);

	/** @return array<string,mixed>|null null when the user does not exist on this site */
	public static function build( $user_id ) {
		$user_id = (int) $user_id;
		$user    = $user_id > 0 ? get_userdata( $user_id ) : false;
		if ( ! $user ) {
			return null;
		}
		$plan = self::plan( $user_id );
		$ws   = array(
			'contract'   => self::CONTRACT,
			'user_ref'   => BizCity_Agent_Mode_Access::user_ref( $user_id ),
			'rev'        => 0,
			'profile'    => self::profile( $user_id, $user ),
			'plan'       => $plan,
			'modules'    => self::modules( $user_id ),
			'modes'      => array_values( array_intersect( (array) BizCity_Agent_Mode_Access::modes_for_user( $user_id ), self::KNOWN_MODES ) ),
			'assistants' => self::assistants( $user_id, $plan['max_risk'] ),
			'connections' => array(),
			'workspace'  => array(
				'notebooks' => array(),
				'skills'    => array(),
				'workflows' => array(),
				'settings'  => array( 'report_hour' => 20, 'report_on' => true, 'quiet_hours' => '', 'default_guru' => 0 ),
			),
			'off'        => array(),
		);
		$ws['rev'] = self::rev_for( $user_id, $ws );
		return $ws;
	}

	/** the modes the cell's schema knows (owner-agent-block KNOWN_AGENT_MODES + content): anything else is dropped, not guessed */
	const KNOWN_MODES = array( 'notebook', 'sales', 'orders', 'customers', 'stock', 'deep_analysis', 'booking', 'channel', 'automation', 'astro_self', 'content' );

	private static function plan( $user_id ) {
		$plan = apply_filters( self::FILTER_PLAN, array( 'tier' => '', 'max_risk' => 'R3', 'limits' => array() ), $user_id );
		$plan = is_array( $plan ) ? $plan : array();
		$risk = strtoupper( (string) ( $plan['max_risk'] ?? 'R3' ) );
		return array(
			'tier'     => substr( (string) ( $plan['tier'] ?? '' ), 0, 24 ),
			'max_risk' => in_array( $risk, BizCity_Channel_User_Grant::LEVELS, true ) ? $risk : 'R3',
			// an empty PHP array would encode as [] — the contract says object ({}): found by validating this very packet with the cell's zod schema
			'limits'   => ( is_array( $plan['limits'] ?? null ) && $plan['limits'] ) ? array_map( 'intval', $plan['limits'] ) : new stdClass(),
		);
	}

	private static function profile( $user_id, $user ) {
		$shop = class_exists( 'BizCity_Account_Owner' ) ? BizCity_Account_Owner::shop_owner_id() : 0;
		$role = $user_id === $shop ? 'shop_owner' : ( user_can( $user_id, 'manage_options' ) || user_can( $user_id, 'manage_woocommerce' ) ? 'manager' : 'member' );
		$tz   = function_exists( 'wp_timezone_string' ) ? (string) wp_timezone_string() : 'UTC';
		return array(
			'display'     => substr( (string) $user->display_name, 0, 60 ),
			'placeholder' => '' !== (string) get_user_meta( $user_id, 'bizcity_placeholder', true ),
			'role'        => $role,
			'contacts'    => array( 'zalo_uids' => array(), 'telegram_ids' => array() ), // contact ids stay on the cell (owner capture); the site sends none
			'timezone'    => substr( '' !== $tz ? $tz : 'UTC', 0, 40 ),
			'locale'      => substr( (string) ( function_exists( 'get_user_locale' ) ? get_user_locale( $user_id ) : 'vi' ), 0, 10 ),
		);
	}

	/** Phân quyền module rows the person holds (cap bizcity_use_<id> on governed entries) */
	private static function modules( $user_id ) {
		$out = array();
		if ( ! class_exists( 'BizCity_Twin_Shell_Registry' ) ) {
			return $out;
		}
		foreach ( BizCity_Twin_Shell_Registry::instance()->all() as $entry ) {
			if ( ! empty( $entry['access'] ) && user_can( $user_id, 'bizcity_use_' . $entry['id'] ) ) {
				$out[] = substr( (string) $entry['id'], 0, 60 );
			}
		}
		return $out;
	}

	/**
	 * The person's level on every account the site lists. Grants store only an HMAC of the account id, so the raw ids come from the SOURCES
	 * (Zalo cá nhân: the mapping table), and the level is asked per (channel, account, user). Capped by the plan.
	 */
	private static function assistants( $user_id, $max_risk ) {
		$sources = array();
		if ( class_exists( 'BizCity_Zalo_Mapping_Repo' ) ) {
			foreach ( (array) BizCity_Zalo_Mapping_Repo::list_personal_accounts( array( 'limit' => 200 ) ) as $row ) {
				if ( ! empty( $row['bridge_account_id'] ) ) {
					$sources[] = array( 'channel' => 'zalo_personal', 'account_id' => (string) $row['bridge_account_id'], 'status' => (string) ( $row['status'] ?? '' ) );
				}
			}
		}
		$sources = apply_filters( self::FILTER_ACCOUNTS, $sources, $user_id );
		$cap     = array_search( $max_risk, BizCity_Channel_User_Grant::LEVELS, true );
		$out     = array();
		$seen    = array();
		foreach ( (array) $sources as $s ) {
			$channel = (string) ( $s['channel'] ?? '' );
			$account = (string) ( $s['account_id'] ?? '' );
			$kind    = self::KIND_OF_CHANNEL[ $channel ] ?? '';
			if ( '' === $kind || '' === $account || isset( $seen[ $kind . '|' . $account ] ) ) {
				continue;
			}
			$level = BizCity_Channel_User_Grant::level_for( $channel, $account, $user_id );
			if ( null === $level ) {
				continue;
			}
			$seen[ $kind . '|' . $account ] = true;
			$rank = array_search( $level, BizCity_Channel_User_Grant::LEVELS, true );
			if ( false !== $cap && $rank > $cap ) {
				$level = BizCity_Channel_User_Grant::LEVELS[ $cap ]; // the plan ceiling: layers only SUBTRACT
			}
			$paused = in_array( strtolower( (string) ( $s['status'] ?? '' ) ), array( 'paused', 'disabled', 'revoked' ), true );
			$out[]  = array(
				'kind'       => $kind,
				'account_id' => $account,
				'own'        => (int) BizCity_Account_Owner::owner_of( $channel, $account ) === $user_id,
				'level'      => $level,
				'status'     => $paused ? 'paused' : 'active',
			);
		}
		return $out;
	}

	/** Monotonic revision: bumps only when the content (without rev) changed. */
	private static function rev_for( $user_id, array $ws ) {
		$body = $ws;
		unset( $body['rev'] );
		$hash = hash( 'sha256', wp_json_encode( $body ) );
		$rev  = (int) get_user_meta( $user_id, self::META_REV, true );
		if ( $rev <= 0 || $hash !== (string) get_user_meta( $user_id, self::META_HASH, true ) ) {
			$rev++;
			update_user_meta( $user_id, self::META_REV, $rev );
			update_user_meta( $user_id, self::META_HASH, $hash );
		}
		return $rev;
	}
}
