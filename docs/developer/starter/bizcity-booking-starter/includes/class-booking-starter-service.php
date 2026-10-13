<?php
/**
 * Dịch vụ lịch hẹn của PLUGIN MẪU — thay lớp này bằng dịch vụ thật của plugin bạn; MCP và Automation chỉ gọi bốn hàm công khai.
 *
 * // [2026-10-12 03:20 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.103 S103-L4 — new file.
 *
 * @package BizCity_Booking_Starter
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_Booking_Starter_Service {

	const OPTION = 'bizcity_booking_starter_items';
	/** Giờ làm việc mẫu: mỗi giờ một khung từ 09 tới 16. */
	const HOURS = array( '09:00', '10:00', '11:00', '12:00', '13:00', '14:00', '15:00', '16:00' );

	/** @return string[] các khung "YYYY-MM-DD HH:MM" còn trống của một ngày (rỗng nếu ngày sai). */
	public static function free_slots( string $date ): array {
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return array();
		}
		$taken = array();
		foreach ( self::all() as $b ) {
			if ( 'cancelled' !== $b['status'] ) {
				$taken[ $b['slot'] ] = true;
			}
		}
		$out = array();
		foreach ( self::HOURS as $h ) {
			$slot = $date . ' ' . $h;
			if ( ! isset( $taken[ $slot ] ) ) {
				$out[] = $slot;
			}
		}
		return $out;
	}

	/** @return array|null */
	public static function get( string $ref ) {
		$all = self::all();
		return isset( $all[ $ref ] ) ? $all[ $ref ] : null;
	}

	public static function is_valid_slot( string $slot ): bool {
		if ( ! preg_match( '/^(\d{4}-\d{2}-\d{2}) (\d{2}:\d{2})$/', $slot, $m ) ) {
			return false;
		}
		return in_array( $m[2], self::HOURS, true );
	}

	public static function is_free( string $slot ): bool {
		return self::is_valid_slot( $slot ) && in_array( $slot, self::free_slots( substr( $slot, 0, 10 ) ), true );
	}

	/** @return array|WP_Error booking đã tạo */
	public static function create( string $slot, string $name, string $phone = '' ) {
		if ( ! self::is_free( $slot ) ) {
			return new WP_Error( 'MCP_BOOKING_SLOT_TAKEN', 'Khung giờ này đã có người đặt hoặc không hợp lệ.', array( 'status' => 409 ) ); // [2026-10-12 Johnny Chu - Chu Hoàng Anh] PHASE-0.103 S103-L9 — a known MCP_* code, not a private one (see class-booking-starter-tools.php)
		}
		$all  = self::all();
		$ref  = 'bk_' . substr( md5( $slot . '|' . $name . '|' . microtime( true ) ), 0, 8 );
		$item = array(
			'ref'    => $ref,
			'slot'   => $slot,
			'name'   => $name,
			'phone'  => $phone,
			'status' => 'confirmed',
		);
		$all[ $ref ] = $item;
		update_option( self::OPTION, $all, false );
		return $item;
	}

	/** @return array|WP_Error booking sau khi đổi */
	public static function reschedule( string $ref, string $slot ) {
		$all = self::all();
		if ( ! isset( $all[ $ref ] ) ) {
			return new WP_Error( 'MCP_NOT_FOUND', 'Không thấy lịch hẹn này.', array( 'status' => 404 ) );
		}
		if ( ! self::is_free( $slot ) ) {
			return new WP_Error( 'MCP_BOOKING_SLOT_TAKEN', 'Khung giờ mới đã có người đặt hoặc không hợp lệ.', array( 'status' => 409 ) );
		}
		$all[ $ref ]['slot'] = $slot;
		update_option( self::OPTION, $all, false );
		return $all[ $ref ];
	}

	/** @return array<string,array> */
	private static function all(): array {
		$v = get_option( self::OPTION, array() );
		return is_array( $v ) ? $v : array();
	}
}
