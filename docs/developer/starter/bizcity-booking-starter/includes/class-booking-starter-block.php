<?php
/**
 * Khối Automation `appointment.check_slots` của plugin mẫu: đọc giờ trống để một kịch bản trả lời khách.
 *
 * Khối này CHỈ ĐỌC (effect = read, guest_safe = true) nên khách chạy qua `automation.run_scenario` được. Việc GHI (đặt/đổi lịch) đi qua tool
 * MCP có xác nhận, không phải khối này. Chỉ nạp khi plugin Automation đã có lớp nền (xem bizcity-booking-starter.php).
 *
 * // [2026-10-12 03:20 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.103 S103-L4 — new file.
 *
 * @package BizCity_Booking_Starter
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_Booking_Starter_Block extends BizCity_Automation_Block_Base {

	public function id(): string   { return 'appointment.check_slots'; }
	public function kind(): string { return 'action'; }

	public function meta(): array {
		return array(
			'label'      => 'Xem giờ trống',
			'short'      => 'giờ trống',
			// [2026-10-12 Johnny Chu - Chu Hoàng Anh] PHASE-0.104 S104-B6 — used by "⚡ Tạo kịch bản" (one_line + keywords of the cell scenario).
			'description'  => 'Liệt kê các khung giờ còn trống của một ngày (mặc định ngày mai). Chỉ đọc.',
			'ask_examples' => array( 'còn giờ trống không', 'mai rảnh giờ nào', 'còn chỗ không', 'lịch trống' ),
			'category'   => 'action',
			'color'      => '#0f766e',
			'icon'       => 'calendar',
			'defaults'   => array( 'label' => 'giờ trống', 'date' => '' ),
			'fields'     => array(
				array( 'name' => 'label', 'label' => 'Tên hiển thị', 'type' => 'text' ),
				array( 'name' => 'date', 'label' => 'Ngày (YYYY-MM-DD)', 'type' => 'text' ),
			),
			'inputs'     => array( 'date' ),
			'outputs'    => array( 'free', 'count', 'text' ),
			'effect'     => 'read',
			'guest_safe' => true,
			'required_cap' => '',
		);
	}

	public function execute( array $ctx, array $data ) {
		$date = trim( (string) $this->resolve( (string) ( $data['date'] ?? '' ), $ctx ) );
		if ( '' === $date || false !== strpos( $date, '{{' ) ) {
			$date = wp_date( 'Y-m-d', time() + DAY_IN_SECONDS ); // không nói ngày ⇒ hôm sau
		}
		$free = BizCity_Booking_Starter_Service::free_slots( $date );
		if ( array() === $free ) {
			return array( 'free' => array(), 'count' => 0, 'text' => 'Ngày này chưa có khung giờ hợp lệ hoặc đã kín chỗ.' );
		}
		return array(
			'free'  => $free,
			'count' => count( $free ),
			'text'  => 'Còn trống: ' . implode( ', ', array_map( static function ( $s ) { return substr( $s, 11 ); }, $free ) ),
		);
	}
}
