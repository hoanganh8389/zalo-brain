<?php
/**
 * Plugin Name:     BizCity Booking Starter
 * Description:     Mẫu chuẩn của Zalo Brain: biến một plugin lịch hẹn thành năng lực AI trên Zalo (xem giờ trống · đặt lịch · đổi lịch · hỏi trạng thái) mà không viết lại chatbot.
 * Version:         0.1.0
 * Requires PHP:    7.4
 * License:         GPL-2.0-or-later
 * Zalo Brain Addon: booking-starter
 * Network:         false
 *
 * Đây là PLUGIN MẪU (PHASE-0.103 S103-L4). Nó nằm ở docs/developer/starter/ nên KHÔNG tự kích hoạt: chép cả thư mục này vào
 * wp-content/plugins/ khi muốn thử. Lưu dữ liệu bằng một option cho gọn — plugin thật thay `BizCity_Booking_Starter_Service` bằng
 * dịch vụ lịch hẹn của mình; hai file MCP/Automation phía dưới không phải sửa.
 *
 * // [2026-10-12 03:20 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.103 S103-L4 — new file.
 *
 * @package BizCity_Booking_Starter
 */

defined( 'ABSPATH' ) || exit;

define( 'BIZCITY_BOOKING_STARTER_DIR', __DIR__ );

// R-ADDON-HOST v1: nhận plugin chính bằng hằng, không bằng tên thư mục (thư mục có thể bị fork/đổi tên).
// Thiếu plugin chính ⇒ không nạp gì và không gây lỗi.
add_action(
	'plugins_loaded',
	static function () {
		if ( ! defined( 'BIZCITY_TWIN_AI_VERSION' ) || ! class_exists( 'BizCity_Zalo_Brain' ) ) {
			return;
		}
		require_once __DIR__ . '/includes/class-booking-starter-service.php';
		require_once __DIR__ . '/includes/class-booking-starter-tools.php';

		// Một cửa duy nhất (Q103-1): khai plugin + thư viện tool + khối + kịch bản.
		add_action(
			'zalo_brain_register',
			static function () {
				BizCity_Zalo_Brain::register_from_manifest( __DIR__ . '/zalo-brain.json' );
				BizCity_MCP_Library::register(
					__DIR__ . '/mcp/tools.json',
					BizCity_Booking_Starter_Tools::handlers(),
					'booking-starter'
				);
				// Khối Automation cần lớp nền của plugin Automation; nạp muộn khi sổ khối khởi động, thiếu Automation thì bỏ qua êm.
				$register_block = static function () {
					if ( ! class_exists( 'BizCity_Automation_Block_Base' ) ) {
						return;
					}
					require_once __DIR__ . '/includes/class-booking-starter-block.php';
					BizCity_Zalo_Brain::register_block( new BizCity_Booking_Starter_Block(), 'booking-starter' );
				};
				if ( did_action( 'bizcity_automation_register_blocks' ) ) {
					$register_block();
				} else {
					add_action( 'bizcity_automation_register_blocks', $register_block, 5 );
				}
			}
		);
	},
	6
);
