<?php
/**
 * Handler MCP của plugin mẫu. Mỗi tool = một hàm gọi vào dịch vụ lịch hẹn; mô tả/lược đồ/quyền nằm ở mcp/tools.json (dữ liệu, không phải mã).
 *
 * Tool GHI không tự hỏi xác nhận: registry đã làm (gọi lần 1 ⇒ preview() + confirm_token, lần 2 ⇒ handler). Handler chỉ ghi.
 * Lỗi trả WP_Error có mã. QUAN TRỌNG: registry chỉ giữ nguyên mã `MCP_*` đã biết (BizCity_MCP_Error::is_known_code); mã tự đặt như `slot_unavailable`
 * bị đổi thành MCP_INTERNAL_ERROR (retryable) và agent sẽ thử lại vô ích. Dùng MCP_BOOKING_SLOT_TAKEN · MCP_NOT_FOUND · MCP_QUERY_INVALID.
 * [2026-10-12 Johnny Chu - Chu Hoàng Anh] PHASE-0.103 S103-L9 — sửa sau khi test BookingStarterS103Test lộ ra mẫu cũ trả mã riêng.
 *
 * // [2026-10-12 03:20 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.103 S103-L4 — new file.
 *
 * @package BizCity_Booking_Starter
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_Booking_Starter_Tools {

	/** @return array<string,mixed> name => callable | { handler, preview } (đưa cho BizCity_MCP_Library::register) */
	public static function handlers(): array {
		return array(
			'appointment.slots'      => array( __CLASS__, 'slots' ),
			'appointment.status'     => array( __CLASS__, 'status' ),
			'appointment.create'     => array(
				'handler' => array( __CLASS__, 'create' ),
				'preview' => array( __CLASS__, 'create_preview' ),
			),
			'appointment.reschedule' => array(
				'handler' => array( __CLASS__, 'reschedule' ),
				'preview' => array( __CLASS__, 'reschedule_preview' ),
			),
		);
	}

	// @mcp bizcity-mcp-standard@1 tool appointment.slots
	public static function slots( array $args, array $ctx = array() ) {
		$date = trim( (string) ( $args['date'] ?? '' ) );
		$free = BizCity_Booking_Starter_Service::free_slots( $date );
		if ( array() === $free ) {
			return new WP_Error( 'MCP_QUERY_INVALID', 'Ngày phải dạng YYYY-MM-DD.', array( 'status' => 400 ) );
		}
		return array( 'date' => $date, 'free' => $free, 'count' => count( $free ) );
	}

	// @mcp bizcity-mcp-standard@1 tool appointment.status
	public static function status( array $args, array $ctx = array() ) {
		$item = BizCity_Booking_Starter_Service::get( trim( (string) ( $args['ref'] ?? '' ) ) );
		if ( null === $item ) {
			return new WP_Error( 'MCP_NOT_FOUND', 'Không thấy lịch hẹn này.', array( 'status' => 404 ) );
		}
		// Plugin thật: lọc theo người hỏi ($ctx) — khách chỉ thấy lịch của chính mình.
		return array( 'ref' => $item['ref'], 'slot' => $item['slot'], 'status' => $item['status'] );
	}

	// @mcp bizcity-mcp-standard@1 tool appointment.create
	public static function create( array $args, array $ctx = array() ) {
		$r = BizCity_Booking_Starter_Service::create(
			trim( (string) ( $args['slot'] ?? '' ) ),
			sanitize_text_field( (string) ( $args['name'] ?? '' ) ),
			sanitize_text_field( (string) ( $args['phone'] ?? '' ) )
		);
		return is_wp_error( $r ) ? $r : array( 'ref' => $r['ref'], 'slot' => $r['slot'], 'status' => $r['status'] );
	}

	/** Xem trước KHÔNG ghi gì. */
	public static function create_preview( array $args, array $ctx = array() ) {
		$slot = trim( (string) ( $args['slot'] ?? '' ) );
		if ( ! BizCity_Booking_Starter_Service::is_free( $slot ) ) {
			return new WP_Error( 'MCP_BOOKING_SLOT_TAKEN', 'Khung giờ này đã có người đặt hoặc không hợp lệ.', array( 'status' => 409 ) );
		}
		return array( 'summary' => 'Đặt lịch ' . $slot . ' cho ' . sanitize_text_field( (string) ( $args['name'] ?? '' ) ) );
	}

	// @mcp bizcity-mcp-standard@1 tool appointment.reschedule
	public static function reschedule( array $args, array $ctx = array() ) {
		$r = BizCity_Booking_Starter_Service::reschedule( trim( (string) ( $args['ref'] ?? '' ) ), trim( (string) ( $args['slot'] ?? '' ) ) );
		return is_wp_error( $r ) ? $r : array( 'ref' => $r['ref'], 'slot' => $r['slot'], 'status' => $r['status'] );
	}

	public static function reschedule_preview( array $args, array $ctx = array() ) {
		$item = BizCity_Booking_Starter_Service::get( trim( (string) ( $args['ref'] ?? '' ) ) );
		if ( null === $item ) {
			return new WP_Error( 'MCP_NOT_FOUND', 'Không thấy lịch hẹn này.', array( 'status' => 404 ) );
		}
		$slot = trim( (string) ( $args['slot'] ?? '' ) );
		if ( ! BizCity_Booking_Starter_Service::is_free( $slot ) ) {
			return new WP_Error( 'MCP_BOOKING_SLOT_TAKEN', 'Khung giờ mới đã có người đặt hoặc không hợp lệ.', array( 'status' => 409 ) );
		}
		return array( 'summary' => 'Dời lịch ' . $item['ref'] . ' từ ' . $item['slot'] . ' sang ' . $slot );
	}
}
