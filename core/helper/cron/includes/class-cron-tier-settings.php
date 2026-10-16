<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Cron
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 *
 * BizCity_Cron_Tier_Settings — tier-based cron interval resolver + file-first flags.
 *
 * [2026-07-15 Johnny Chu] R-PERF / R-CRON-TIER — chia nhỏ tần suất cron theo
 * license tier để tránh over-load trên multisite (~1.400 blog):
 *   - free    → 10 phút / lần
 *   - pro     → 5 phút / lần
 *   - premium → 1 phút / lần
 *
 * Đọc tier bằng get_option('bizcity_hub_master_level') (KHÔNG gọi HTTP trong bind()).
 * Interval (phút) cấu hình được qua Network Admin UI (site option / network option),
 * lưu tại option `bizcity_cron_tier_minutes` = [ 'free'=>10, 'pro'=>5, 'premium'=>1 ].
 *
 * [2026-10-16 04:09 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.116 CS5.1 (doc 87) — the KG file-store rollout flags (v06–v09) moved to core/kg-hub/includes/filestore/class-kg-filestore-flags.php;
 * this class now only resolves the tier interval and the cron schedule names.
 *
 * PHP 7.4 compat: không match(), union type, nullsafe.
 */

defined( 'ABSPATH' ) || exit;

class BizCity_Cron_Tier_Settings {

	/** Option lưu map tier => số phút. Network-scoped trên multisite. */
	const OPT_TIER_MINUTES = 'bizcity_cron_tier_minutes';

	/** Default minutes per tier (yêu cầu 2026-07-15). */
	const DEFAULTS = array(
		'free'       => 10,
		'pro'        => 5,
		'premium'    => 1,
		'enterprise' => 1,
	);

	/**
	 * Đọc map tier => phút (đã merge với default).
	 *
	 * @return array<string,int>
	 */
	public static function get_tier_minutes(): array {
		$stored = self::get_network_option( self::OPT_TIER_MINUTES, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		$out = self::DEFAULTS;
		foreach ( self::DEFAULTS as $tier => $def ) {
			if ( isset( $stored[ $tier ] ) ) {
				$m = (int) $stored[ $tier ];
				// Clamp 1..1440 phút để tránh giá trị vô lý.
				if ( $m < 1 ) { $m = 1; }
				if ( $m > 1440 ) { $m = 1440; }
				$out[ $tier ] = $m;
			}
		}
		return $out;
	}

	/**
	 * Cập nhật map tier => phút.
	 *
	 * @param array<string,int> $map
	 */
	public static function update_tier_minutes( array $map ): void {
		$clean = array();
		foreach ( self::DEFAULTS as $tier => $def ) {
			$m = isset( $map[ $tier ] ) ? (int) $map[ $tier ] : $def;
			if ( $m < 1 ) { $m = 1; }
			if ( $m > 1440 ) { $m = 1440; }
			$clean[ $tier ] = $m;
		}
		self::update_network_option( self::OPT_TIER_MINUTES, $clean );
	}

	/**
	 * Tier hiện tại của site (từ entitlement cache — KHÔNG gọi HTTP).
	 */
	public static function current_tier(): string {
		$tier = (string) get_option( 'bizcity_hub_master_tier', '' );
		if ( $tier === '' ) {
			$tier = (string) get_option( 'bizcity_hub_master_level', 'free' );
		}
		$tier = strtolower( trim( $tier ) );

		// [2026-07-15 Johnny Chu] PHASE-MASTER-PLANS — normalize hub slugs
		// (master_pro/master_premium) to local tier buckets used by cron settings.
		if ( in_array( $tier, array( 'master_enterprise' ), true ) ) {
			$tier = 'enterprise';
		} elseif ( in_array( $tier, array( 'master_premium', 'business', 'plus' ), true ) ) {
			$tier = 'premium';
		} elseif ( in_array( $tier, array( 'master_pro', 'starter' ), true ) ) {
			$tier = 'pro';
		}

		if ( ! isset( self::DEFAULTS[ $tier ] ) ) {
			$tier = 'free';
		}
		return $tier;
	}

	/**
	 * Số phút interval cho tier hiện tại.
	 */
	public static function current_minutes(): int {
		$map  = self::get_tier_minutes();
		$tier = self::current_tier();
		return isset( $map[ $tier ] ) ? (int) $map[ $tier ] : (int) self::DEFAULTS['free'];
	}

	/**
	 * Tên cron schedule cho tier hiện tại (đăng ký động qua register_schedules()).
	 * Format: `bizcity_tier_{N}min`.
	 */
	public static function current_schedule_name(): string {
		return 'bizcity_tier_' . self::current_minutes() . 'min';
	}

	/**
	 * Đăng ký TẤT CẢ schedule names cần thiết (mọi giá trị phút trong map) vào
	 * cron_schedules — phải chạy vô điều kiện mỗi request (R-PERF / PERF-CRON-FIX)
	 * để wp_reschedule_event() không bao giờ gặp invalid_schedule.
	 *
	 * @param array $schedules
	 * @return array
	 */
	public static function register_schedules( $schedules ) {
		if ( ! is_array( $schedules ) ) {
			$schedules = array();
		}
		$minutes = array_unique( array_values( self::get_tier_minutes() ) );
		foreach ( $minutes as $m ) {
			$m    = (int) $m;
			$name = 'bizcity_tier_' . $m . 'min';
			if ( ! isset( $schedules[ $name ] ) ) {
				$schedules[ $name ] = array(
					'interval' => $m * MINUTE_IN_SECONDS,
					'display'  => sprintf( 'BizCity Tier — mỗi %d phút', $m ),
				);
			}
		}
		return $schedules;
	}

	/**
	 * Reschedule 1 hook về đúng schedule tier hiện tại nếu khác.
	 * Chỉ reschedule khi interval đổi (tránh churn mỗi request).
	 *
	 * @param string $hook
	 */
	public static function ensure_hook_interval( string $hook ): void {
		$want = self::current_schedule_name();
		$ts   = wp_next_scheduled( $hook );
		if ( $ts ) {
			$cur = wp_get_schedule( $hook );
			if ( $cur === $want ) {
				return; // đúng rồi, không làm gì.
			}
			wp_unschedule_event( $ts, $hook );
		}
		wp_schedule_event( time() + 60, $want, $hook );
	}

	// ─── Network-aware option helpers ────────────────────────────────────────

	private static function get_network_option( string $key, $default ) {
		if ( is_multisite() ) {
			$val = get_site_option( $key, null );
			if ( null !== $val ) {
				return $val;
			}
		}
		return get_option( $key, $default );
	}

	private static function update_network_option( string $key, $value ): void {
		if ( is_multisite() ) {
			update_site_option( $key, $value );
		} else {
			update_option( $key, $value );
		}
	}
}
