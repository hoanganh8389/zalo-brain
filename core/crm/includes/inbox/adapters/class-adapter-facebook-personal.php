<?php
/**
 * BizCity CRM — Facebook cá nhân (nick Messenger) qua Cell zalo-hub.
 *
 * [2026-11-24 Johnny Chu - Chu Hoàng Anh] PHASE-017 017.10 — kênh khách `facebook_personal` (D017-8: tên luôn có `_personal`,
 * KHÔNG phải Fanpage `facebook`). Cùng đường với Zalo Cá nhân: account là một dòng của Zalo Personal mapping (`kind = personal`),
 * gửi qua Hub → Cell `POST /wp/outbound`; Cell đọc `platform` của account để gửi bằng bridge Facebook (không tin body).
 * P1 (D017-7): chỉ tin 1-1 — tin nhóm Facebook bị bỏ ở đây.
 *
 * @package BizCity_Twin_CRM
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_CRM_Adapter_FacebookPersonal' ) || ! class_exists( 'BizCity_CRM_Adapter_ZaloPersonal' ) ) {
	return;
}

class BizCity_CRM_Adapter_FacebookPersonal extends BizCity_CRM_Adapter_ZaloPersonal {

	public function code(): string {
		return 'facebook_personal';
	}

	public function label(): string {
		return 'Facebook cá nhân';
	}

	public function normalize_inbound( array $raw ): ?array {
		if ( 'group' === sanitize_key( (string) ( $raw['thread_kind'] ?? '' ) ) || ! empty( $raw['is_group'] ) ) {
			return null; // D017-7: P1 không nhận nhóm Facebook
		}
		$normalized = parent::normalize_inbound( $raw );
		if ( ! is_array( $normalized ) ) {
			return null;
		}
		// [2026-11-26 Johnny Chu - Chu Hoàng Anh] chạy thật: liên hệ Facebook chưa có tên hiện "Zalo 982373" (nhãn dự phòng của adapter Zalo cha). Cell nay gửi tên từ threadName khi
		// bridge biết; chưa biết thì nhãn dự phòng phải đúng nền tảng: "Facebook <6 số cuối uid>".
		if ( '' === trim( (string) ( $raw['from_user_name'] ?? '' ) ) && isset( $normalized['contact_name'] ) && 0 === strpos( (string) $normalized['contact_name'], 'Zalo ' ) ) {
			$uid                        = (string) ( $raw['conversation_id'] ?? $raw['from_user_id'] ?? '' );
			$normalized['contact_name'] = 'Facebook ' . substr( $uid, -6 );
		}
		$normalized['inbox_name']  = 'Facebook cá nhân ' . (string) ( $raw['account_name'] ?? $raw['conversation_id'] ?? '' );
		$normalized['ai_metadata'] = array_merge( (array) ( $normalized['ai_metadata'] ?? array() ), array(
			'platform' => 'facebook_personal',
			'e2ee'     => ! empty( $raw['e2ee'] ),
		) );
		return $normalized;
	}
}
