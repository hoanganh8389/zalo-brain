<?php
/**
 * `GET bizcity-channel/v1/guru/stats?days=7|30` — guru-stats@1: what each Agent Guru did for customers, summed over every Zalo number of this site
 * (PHASE-0.144 S144-CELL1 client part, contract C-BM6, doc 30 BM-5).
 *
 * Source: the `gurus` block of ops-day@1 (C-BM2) the site already keeps per number in option `bizcity_ops_day_<account>` (14 days, BizCity_Usage_Ops_Day).
 * No new table (R-TABLE-FREEZE), no Hub call. Names / icon / colour are joined from `wp_bizcity_characters` (settings.ui, C-BM7) at read time; the cell
 * never sends a name. `guru_id = 0` = turns no Guru served ("Chưa gắn Guru").
 *
 * Badges (C-BM6) are judged on the 30-day sums whatever `days` asks for: `loved` ≥ 50 praise · `hardworking` ≥ 300 replies ·
 * `right_first` replies ≥ 30 and complaints / replies ≤ 10 %. Note: the ops-day store keeps 14 days, so a "30-day" window covers at most those 14.
 *
 * Permission: same as Guru Studio (manage_options).
 *
 * Test seams ($readers): all_days(): array<account, array<date, day>> · meta(int[] ids): array<id, {name, ui}> · today(): 'Y-m-d'
 *
 * // [2026-10-27 Johnny Chu - Chu Hoàng Anh] PHASE-0.144 S144-CELL1 — new file.
 *
 * @package BizCity_Twin_AI
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_Usage_Guru_Stats {

	const NS       = 'bizcity-channel/v1';
	const CONTRACT = 'guru-stats@1';
	const KEYS     = array( 'replies', 'customers', 'praise', 'complaints', 'tool_ok', 'tool_err' );

	/** @var array<string,callable> */
	public static $readers = array();

	private static $initialized = false;

	public static function init(): void {
		if ( self::$initialized ) {
			return;
		}
		self::$initialized = true;
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes(): void {
		register_rest_route( self::NS, '/guru/stats', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'rest_get' ),
			'permission_callback' => array( __CLASS__, 'can' ),
			'args'                => array( 'days' => array( 'required' => false ) ),
		) );
	}

	/** same gate as Guru Studio */
	public static function can(): bool {
		if ( class_exists( 'BizCity_Guru_Studio', false ) ) {
			return (bool) BizCity_Guru_Studio::can();
		}
		return function_exists( 'current_user_can' ) && current_user_can( 'manage_options' );
	}

	public static function rest_get( WP_REST_Request $request ): WP_REST_Response {
		return new WP_REST_Response( self::view( (int) $request->get_param( 'days' ) ), 200 );
	}

	/** guru-stats@1 of this site */
	public static function view( int $days = 30 ): array {
		$all = self::all_days();
		$ids = array();
		foreach ( $all as $by_date ) {
			foreach ( (array) $by_date as $day ) {
				foreach ( (array) ( is_array( $day ) ? ( $day['gurus'] ?? array() ) : array() ) as $g ) {
					if ( is_array( $g ) && isset( $g['guru_id'] ) ) {
						$ids[ (int) $g['guru_id'] ] = true;
					}
				}
			}
		}
		unset( $ids[0] );
		return self::build( $all, $days, self::today(), self::meta( array_keys( $ids ) ) );
	}

	/* ── pure ───────────────────────────────────────────────────────── */

	/** 7 or 30 (anything else ⇒ 30) */
	public static function clamp_days( int $days ): int {
		return 7 === $days ? 7 : 30;
	}

	/**
	 * Sum the `gurus` lines of every number's days inside the window [today − days + 1, today].
	 *
	 * @param array  $by_account array<account, array<date 'Y-m-d', ops-day stored day>>
	 * @param int    $days       window length
	 * @param string $today      'Y-m-d'
	 * @return array<int,array<string,int>> guru_id ⇒ counts
	 */
	public static function aggregate( array $by_account, int $days, string $today ): array {
		$t = strtotime( $today . ' 00:00:00 UTC' );
		if ( false === $t ) {
			return array();
		}
		$from = gmdate( 'Y-m-d', $t - ( max( 1, $days ) - 1 ) * 86400 );
		$out  = array();
		foreach ( $by_account as $by_date ) {
			if ( ! is_array( $by_date ) ) {
				continue;
			}
			foreach ( $by_date as $date => $day ) {
				$date = (string) $date;
				if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) || $date < $from || $date > $today || ! is_array( $day ) || ! isset( $day['gurus'] ) || ! is_array( $day['gurus'] ) ) {
					continue;
				}
				foreach ( $day['gurus'] as $g ) {
					if ( ! is_array( $g ) || ! isset( $g['guru_id'] ) || ! is_numeric( $g['guru_id'] ) || (int) $g['guru_id'] < 0 ) {
						continue;
					}
					$id = (int) $g['guru_id'];
					if ( ! isset( $out[ $id ] ) ) {
						$out[ $id ] = array_fill_keys( self::KEYS, 0 );
					}
					foreach ( self::KEYS as $k ) {
						$out[ $id ][ $k ] += is_numeric( $g[ $k ] ?? null ) ? max( 0, (int) $g[ $k ] ) : 0;
					}
				}
			}
		}
		return $out;
	}

	/**
	 * C-BM6 badges of one Guru from its 30-day sums.
	 *
	 * @param array<string,int> $c
	 * @return string[]
	 */
	public static function badges( array $c ): array {
		$replies    = (int) ( $c['replies'] ?? 0 );
		$praise     = (int) ( $c['praise'] ?? 0 );
		$complaints = (int) ( $c['complaints'] ?? 0 );
		$b          = array();
		if ( $praise >= 50 ) {
			$b[] = 'loved';
		}
		if ( $replies >= 300 ) {
			$b[] = 'hardworking';
		}
		if ( $replies >= 30 && $complaints * 10 <= $replies ) {
			$b[] = 'right_first';
		}
		return $b;
	}

	/**
	 * The guru-stats@1 body (pure).
	 *
	 * @param array  $by_account array<account, array<date, day>>
	 * @param int    $days       7 | 30
	 * @param string $today      'Y-m-d'
	 * @param array  $meta       array<guru_id, {name:string, ui:{icon,color}}>
	 * @return array<string,mixed>
	 */
	public static function build( array $by_account, int $days, string $today, array $meta ): array {
		$days  = self::clamp_days( $days );
		$win   = self::aggregate( $by_account, $days, $today );
		$m30   = 30 === $days ? $win : self::aggregate( $by_account, 30, $today );
		$rows  = array();
		$tot   = array( 'replies' => 0, 'praise' => 0, 'complaints' => 0 );
		foreach ( $win as $id => $c ) {
			$info = isset( $meta[ $id ] ) && is_array( $meta[ $id ] ) ? $meta[ $id ] : array();
			$ui   = is_array( $info['ui'] ?? null ) ? $info['ui'] : array();
			$name = (string) ( $info['name'] ?? '' );
			$rows[] = array(
				'guru_id' => (int) $id,
				'name'    => '' !== $name ? $name : ( 0 === (int) $id ? 'Chưa gắn Guru' : 'Guru #' . (int) $id ),
				'icon'    => (string) ( $ui['icon'] ?? '🤖' ),
				'color'   => (string) ( $ui['color'] ?? 'slate' ),
			) + $c + array( 'badges' => 0 === (int) $id ? array() : self::badges( $m30[ $id ] ?? $c ) );
			foreach ( array_keys( $tot ) as $k ) {
				$tot[ $k ] += (int) $c[ $k ];
			}
		}
		usort( $rows, static function ( $a, $b ) {
			if ( ( 0 === $a['guru_id'] ) !== ( 0 === $b['guru_id'] ) ) {
				return 0 === $a['guru_id'] ? 1 : -1; // "Chưa gắn Guru" last
			}
			return $b['replies'] <=> $a['replies'] ?: $a['guru_id'] <=> $b['guru_id'];
		} );
		return array(
			'contract' => self::CONTRACT,
			'days'     => $days,
			'gurus'    => $rows,
			'totals'   => $tot,
		);
	}

	/* ── seams ─────────────────────────────────────────────────────── */

	private static function today(): string {
		if ( isset( self::$readers['today'] ) ) {
			return (string) call_user_func( self::$readers['today'] );
		}
		return function_exists( 'wp_date' ) ? (string) wp_date( 'Y-m-d' ) : gmdate( 'Y-m-d' );
	}

	/** every `bizcity_ops_day_<account>` option of this site ⇒ account ⇒ days */
	private static function all_days(): array {
		if ( isset( self::$readers['all_days'] ) ) {
			return (array) call_user_func( self::$readers['all_days'] );
		}
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return array();
		}
		$out = array();
		// [2026-10-28 Johnny Chu - Chu Hoàng Anh] PHASE-0.144 — the 31-day guru history (bizcity_guru_day_<account>) first; the 14-day ops day fills
		// any date it does not have (sites that received ops days before this version).
		foreach ( array( 'bizcity_guru_day_', class_exists( 'BizCity_Usage_Ops_Day', false ) ? BizCity_Usage_Ops_Day::OPTION : 'bizcity_ops_day_' ) as $prefix ) {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT 500", $wpdb->esc_like( $prefix ) . '%' ), ARRAY_A );
			foreach ( (array) $rows as $r ) {
				$account = substr( (string) $r['option_name'], strlen( $prefix ) );
				$v       = function_exists( 'maybe_unserialize' ) ? maybe_unserialize( $r['option_value'] ) : @unserialize( (string) $r['option_value'] ); // phpcs:ignore
				if ( '' === $account || ! is_array( $v ) ) {
					continue;
				}
				foreach ( $v as $date => $day ) {
					if ( ! isset( $out[ $account ][ $date ] ) && is_array( $day ) && isset( $day['gurus'] ) ) {
						$out[ $account ][ $date ] = array( 'gurus' => $day['gurus'] );
					}
				}
			}
		}
		return $out;
	}

	/** names + ui of the Gurus that appear (deleted Guru ⇒ absent ⇒ "Guru #id") */
	private static function meta( array $ids ): array {
		if ( isset( self::$readers['meta'] ) ) {
			return (array) call_user_func( self::$readers['meta'], $ids );
		}
		$out = array();
		if ( ! class_exists( 'BizCity_Knowledge_Database' ) ) {
			return $out;
		}
		$db = BizCity_Knowledge_Database::instance();
		foreach ( array_slice( $ids, 0, 50 ) as $id ) {
			$c = (int) $id > 0 ? $db->get_character( (int) $id ) : null;
			if ( ! $c ) {
				continue;
			}
			$out[ (int) $id ] = array(
				'name' => (string) ( $c->name ?? '' ),
				'ui'   => class_exists( 'BizCity_Guru_Studio', false ) ? BizCity_Guru_Studio::ui_of( $c->settings ?? '' ) : array( 'icon' => '🤖', 'color' => 'slate' ),
			);
		}
		return $out;
	}
}
