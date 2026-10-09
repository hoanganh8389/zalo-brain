<?php
/**
 * Zalo Brain — "Sổ tay theo số Zalo": one notebook workspace per Zalo number, owned by the number's owner.
 *
 * [2026-10-09 Johnny Chu - Chu Hoàng Anh] PHASE-0.96 S96-7.5 (Q96-13 = option a, owner decision 2026-10-09).
 * Before: every capture from Zalo Cá nhân went into one per-user workspace "Zalo Personal daily", so the documents of
 * two numbers owned by the same person were mixed, and a number handed to another staff member left its documents
 * behind. Now: whatever arrives through a number lands in that number's own workspace ("Sổ tay · 09…86"), in the
 * workspace list of the person who owns the number today. Matches "each number has one Agent Guru" and
 * R-TWIN-AGENT-AXIS (owner = the number's owner).
 *
 * No schema change: workspaces stay the `bizcity_kg_workspaces` user_meta list, membership stays
 * `notebooks.settings.workspace_id`; captures from a number already carry `settings.scope_id = zalo_account:<id>`.
 *
 *   - capture:    filter `bizcity_kg_capture_workspace_id` (fired by BizCity_KG_Channel_Notebook_Bridge) → number workspace.
 *   - migration:  migrate_batch() moves every existing number notebook into its number workspace and hands it to the
 *                 number's current owner (admin_init, 200 rows per request until done; never deletes anything).
 *   - ownership:  reconcile_account() re-runs the same move for one number (called when its notebooks are listed), so a
 *                 number whose owner changed brings its notebook to the new owner.
 *   - REST:       GET bizcity-knowledge/v2/zalo-numbers → the numbers I own (all numbers for an admin) with their workspace.
 *
 * @package BizCity_Twin_AI
 * @subpackage Core\KGHub
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_KG_Zalo_Number_Notebooks', false ) ) {
	return;
}

final class BizCity_KG_Zalo_Number_Notebooks {

	const SCOPE_PREFIX     = 'zalo_account:';
	const WS_PREFIX        = 'ws_zalo_';
	const LEGACY_WS        = 'ws_zalo_personal_daily';
	const MIGRATION_OPTION = 'bizcity_kg_zalo_number_notebooks_v1';
	const BATCH            = 200;
	const COLOR            = '#0068ff';

	public static function register(): void {
		add_filter( 'bizcity_kg_capture_workspace_id', array( __CLASS__, 'filter_capture_workspace' ), 10, 4 );
		add_action( 'admin_init', array( __CLASS__, 'maybe_migrate' ), 30 );
		add_action( 'rest_api_init', array( __CLASS__, 'register_rest' ) );
	}

	/* ── identity helpers ──────────────────────────────────────────────────────────────────────────────── */

	/** Bridge account id from a capture scope ("zalo_account:<id>"), or ''. */
	public static function bridge_id_from_scope( string $scope_id ): string {
		return 0 === strpos( $scope_id, self::SCOPE_PREFIX ) ? substr( $scope_id, strlen( self::SCOPE_PREFIX ) ) : '';
	}

	/** Stable workspace id for one number (sanitize_key-safe, bounded length). */
	public static function workspace_id( string $bridge_id ): string {
		$key = sanitize_key( $bridge_id );
		if ( '' === $key || strlen( $key ) > 40 ) {
			$key = substr( md5( $bridge_id ), 0, 16 );
		}
		return self::WS_PREFIX . $key;
	}

	/** The account row of a Zalo Cá nhân number, or null. */
	private static function account( string $bridge_id ): ?array {
		if ( '' === $bridge_id || ! class_exists( 'BizCity_Zalo_Mapping_Repo' ) ) {
			return null;
		}
		$row = BizCity_Zalo_Mapping_Repo::find_account_by_bridge_id( 'personal', $bridge_id );
		return is_array( $row ) ? $row : null;
	}

	/** Who owns the number today (0 when unknown). */
	public static function current_owner( string $bridge_id ): int {
		$row = self::account( $bridge_id );
		if ( ! $row ) {
			return 0;
		}
		$owner = (int) ( $row['owner_user_id'] ?? 0 );
		return $owner > 0 ? $owner : (int) ( $row['user_id'] ?? 0 );
	}

	/** Human label of the number: its label/account name, phone-like labels masked to the last 3 digits. */
	public static function number_label( string $bridge_id ): string {
		$row   = self::account( $bridge_id );
		$label = $row ? trim( (string) ( $row['label'] ?: $row['account_name'] ) ) : '';
		if ( '' === $label ) {
			$label = $bridge_id;
		}
		if ( class_exists( 'BizCity_Zalo_Personal_Knowledge_Capture' ) && method_exists( 'BizCity_Zalo_Personal_Knowledge_Capture', 'mask_label' ) ) {
			return BizCity_Zalo_Personal_Knowledge_Capture::mask_label( $label );
		}
		$digits = preg_replace( '/\D+/', '', $label );
		return strlen( (string) $digits ) >= 7 ? '…' . substr( (string) $digits, -3 ) : $label;
	}

	/* ── workspace in the owner's list ─────────────────────────────────────────────────────────────────── */

	private static function meta_key(): string {
		$blog_id = is_multisite() ? (int) get_current_blog_id() : 0;
		return $blog_id > 1 ? 'bizcity_kg_workspaces_' . $blog_id : 'bizcity_kg_workspaces';
	}

	private static function read_list( int $user_id ): array {
		$raw  = class_exists( 'BizCity_User_Meta_Cache' )
			? BizCity_User_Meta_Cache::get( $user_id, self::meta_key(), '' )
			: get_user_meta( $user_id, self::meta_key(), true );
		$list = is_string( $raw ) && '' !== $raw ? json_decode( $raw, true ) : $raw;
		return is_array( $list ) ? $list : array();
	}

	private static function write_list( int $user_id, array $list ): void {
		$encoded = wp_json_encode( array_values( $list ), JSON_UNESCAPED_UNICODE );
		if ( class_exists( 'BizCity_User_Meta_Cache' ) ) {
			BizCity_User_Meta_Cache::set( $user_id, self::meta_key(), $encoded );
		} else {
			update_user_meta( $user_id, self::meta_key(), $encoded );
		}
	}

	/** Make sure `$user_id` has the number's workspace; keeps its name in sync with the number label. */
	public static function ensure_workspace( int $user_id, string $bridge_id ): string {
		$ws_id = self::workspace_id( $bridge_id );
		if ( $user_id <= 0 ) {
			return $ws_id;
		}
		$name = 'Sổ tay · ' . self::number_label( $bridge_id );
		$list = self::read_list( $user_id );
		foreach ( $list as $i => $w ) {
			if ( is_array( $w ) && (string) ( $w['id'] ?? '' ) === $ws_id ) {
				if ( (string) ( $w['name'] ?? '' ) !== $name ) {
					$list[ $i ]['name'] = $name;
					self::write_list( $user_id, $list );
				}
				return $ws_id;
			}
		}
		$list[] = array(
			'id'         => $ws_id,
			'name'       => $name,
			'color'      => self::COLOR,
			'createdAt'  => current_time( 'mysql' ),
			'zalo_account' => $bridge_id,
		);
		self::write_list( $user_id, $list );
		return $ws_id;
	}

	/* ── capture ───────────────────────────────────────────────────────────────────────────────────────── */

	/**
	 * Filter `bizcity_kg_capture_workspace_id`: a capture scoped to a Zalo number goes to that number's workspace.
	 *
	 * @param string $ws_id   workspace the bridge resolved (per-user channel workspace).
	 * @param int    $user_id notebook owner the bridge resolved (the number's owner for owner-capture).
	 * @param string $channel capture channel.
	 * @param array  $scope   scope_type / scope_id.
	 */
	public static function filter_capture_workspace( $ws_id, $user_id, $channel, $scope ) {
		$bridge = self::bridge_id_from_scope( (string) ( is_array( $scope ) ? ( $scope['scope_id'] ?? '' ) : '' ) );
		if ( '' === $bridge ) {
			return $ws_id;
		}
		return self::ensure_workspace( (int) $user_id, $bridge );
	}

	/* ── migration / ownership ─────────────────────────────────────────────────────────────────────────── */

	/**
	 * Move one notebook row into its number workspace and give it to the number's current owner.
	 *
	 * @return string 'moved' | 'kept' | 'skipped'
	 */
	private static function settle_row( array $row ): string {
		global $wpdb;
		$settings = is_array( $row['settings'] ?? null ) ? $row['settings'] : json_decode( (string) ( $row['settings'] ?? '' ), true );
		if ( ! is_array( $settings ) ) {
			return 'skipped';
		}
		$bridge = self::bridge_id_from_scope( (string) ( $settings['scope_id'] ?? '' ) );
		if ( '' === $bridge ) {
			return 'skipped';
		}
		$owner  = self::current_owner( $bridge );
		$target = $owner > 0 && get_userdata( $owner ) ? $owner : (int) $row['owner_id'];
		$ws_id  = self::ensure_workspace( $target, $bridge );
		if ( (string) ( $settings['workspace_id'] ?? '' ) === $ws_id && (int) $row['owner_id'] === $target ) {
			return 'kept';
		}
		$settings['workspace_id'] = $ws_id;
		$settings['zalo_account'] = $bridge;
		if ( (int) $row['owner_id'] !== $target ) {
			$settings['previous_owner_id'] = (int) $row['owner_id'];
		}
		$wpdb->update(
			BizCity_KG_Database::instance()->tbl_notebooks(),
			array( 'owner_id' => $target, 'settings' => wp_json_encode( $settings, JSON_UNESCAPED_UNICODE ) ),
			array( 'id' => (int) $row['id'] ),
			array( '%d', '%s' ),
			array( '%d' )
		);
		return 'moved';
	}

	/** Notebook rows captured through a number (optionally one number), oldest id first, after `$after_id`. */
	private static function rows( string $bridge_id = '', int $after_id = 0, int $limit = self::BATCH ): array {
		if ( ! class_exists( 'BizCity_KG_Database' ) ) {
			return array();
		}
		global $wpdb;
		$tbl  = BizCity_KG_Database::instance()->tbl_notebooks();
		$like = '' === $bridge_id
			? '%"scope_id":"' . $wpdb->esc_like( self::SCOPE_PREFIX ) . '%'
			: '%"scope_id":"' . $wpdb->esc_like( self::SCOPE_PREFIX . $bridge_id ) . '"%';
		return (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT id, owner_id, settings, updated_at FROM {$tbl} WHERE id > %d AND settings LIKE %s ORDER BY id ASC LIMIT %d",
			$after_id,
			$like,
			$limit
		), ARRAY_A );
	}

	/** One batch of the one-time migration. Returns [moved, kept, last_id, done]. */
	public static function migrate_batch( int $after_id = 0 ): array {
		$moved = 0;
		$kept  = 0;
		$last  = $after_id;
		$rows  = self::rows( '', $after_id, self::BATCH );
		foreach ( $rows as $row ) {
			$r = self::settle_row( $row );
			$moved += 'moved' === $r ? 1 : 0;
			$kept  += 'kept' === $r ? 1 : 0;
			$last   = max( $last, (int) $row['id'] );
		}
		return array( $moved, $kept, $last, count( $rows ) < self::BATCH );
	}

	/** admin_init: run the migration in batches until done; the option records progress and totals (evidence). */
	public static function maybe_migrate(): void {
		if ( ! current_user_can( 'manage_options' ) || ! class_exists( 'BizCity_KG_Database' ) ) {
			return;
		}
		$state = get_option( self::MIGRATION_OPTION, array() );
		if ( is_array( $state ) && ! empty( $state['done'] ) ) {
			return;
		}
		$state = is_array( $state ) ? $state : array();
		list( $moved, $kept, $last, $done ) = self::migrate_batch( (int) ( $state['last_id'] ?? 0 ) );
		$state = array(
			'last_id'  => $last,
			'moved'    => (int) ( $state['moved'] ?? 0 ) + $moved,
			'kept'     => (int) ( $state['kept'] ?? 0 ) + $kept,
			'done'     => $done,
			'updated'  => current_time( 'mysql' ),
		);
		update_option( self::MIGRATION_OPTION, $state, false );
	}

	/**
	 * Re-settle every notebook of one number (owner changed, label changed) in one pass.
	 *
	 * @return array{moved:int,count:int,last_updated:string}
	 */
	public static function reconcile_account( string $bridge_id ): array {
		$moved = 0;
		$count = 0;
		$last  = '';
		$after = 0;
		do {
			$rows = self::rows( $bridge_id, $after, self::BATCH );
			foreach ( $rows as $row ) {
				$moved += 'moved' === self::settle_row( $row ) ? 1 : 0;
				$count++;
				$last   = max( $last, (string) ( $row['updated_at'] ?? '' ) );
				$after  = max( $after, (int) $row['id'] );
			}
		} while ( count( $rows ) === self::BATCH );
		return array( 'moved' => $moved, 'count' => $count, 'last_updated' => $last );
	}

	/* ── REST ──────────────────────────────────────────────────────────────────────────────────────────── */

	public static function register_rest(): void {
		register_rest_route( 'bizcity-knowledge/v2', '/zalo-numbers', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'rest_list' ),
			'permission_callback' => static function () {
				return is_user_logged_in();
			},
		) );
	}

	/** The numbers I own (every number for an admin), each with its notebook workspace and how much is in it. */
	public static function rest_list( WP_REST_Request $req ) {
		if ( ! class_exists( 'BizCity_Zalo_Mapping_Repo' ) ) {
			return rest_ensure_response( array( 'numbers' => array(), 'available' => false ) );
		}
		$uid      = get_current_user_id();
		$is_admin = current_user_can( 'manage_options' );
		$accounts = $is_admin
			? BizCity_Zalo_Mapping_Repo::list_personal_accounts( array( 'limit' => 200 ) )
			: BizCity_Zalo_Mapping_Repo::list_personal_accounts_for_owner( $uid );
		$out = array();
		foreach ( (array) $accounts as $acc ) {
			$bridge = (string) ( $acc['bridge_account_id'] ?? '' );
			if ( '' === $bridge ) {
				continue;
			}
			$owner = (int) ( $acc['owner_user_id'] ?? 0 ) ?: (int) ( $acc['user_id'] ?? 0 );
			// One pass: a number whose owner changed brings its notebook along, and we count what is in it.
			$state = self::reconcile_account( $bridge );
			$owner_user = $owner > 0 ? get_userdata( $owner ) : null;
			$out[] = array(
				'account_id'     => $bridge,
				'label'          => self::number_label( $bridge ),
				'status'         => (string) ( $acc['status'] ?? '' ),
				'owner_user_id'  => $owner,
				'owner_name'     => $owner_user ? (string) $owner_user->display_name : '',
				'is_mine'        => $owner === $uid,
				'workspace_id'   => self::workspace_id( $bridge ),
				'notebook_count' => $state['count'],
				'last_updated'   => $state['last_updated'],
			);
		}
		return rest_ensure_response( array(
			'numbers'   => $out,
			'available' => true,
			'migration' => get_option( self::MIGRATION_OPTION, array() ),
		) );
	}
}
