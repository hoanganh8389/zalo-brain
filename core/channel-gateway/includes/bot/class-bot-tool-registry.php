<?php
/**
 * Bot Studio — the tool catalogue the operator switches on/off per Guru and per number. The CELL runs every tool.
 *
 * Until PHASE-0.126 this was the PHP turn engine's registry (statuses from which PHP executor was loaded). That engine is
 * gone (owner 2026-10-24: 100% qua cell). What is left is what the UI and the config sync need:
 *   rows()  — GET bizcity-channel/v1/bot/tools: one row per tool id the operator can toggle. The toggles
 *             (settings.bot.disabled_tools / enabled_optional_tools, binding policy disabled_tools) are pushed to the
 *             cell by BizCity_Zalo_Hub_Config_Sync, which maps these ids to the cell's own tool keys (its TOOL_MAP).
 * Only ids the cell knows are listed (`cell` below = the cell's key); PHP-only tools (create_music, create_video, stt,
 * the vertical PHP tools) left with the engine. Per-number plan / budget state of media tools is the separate
 * GET /bot/tools/zalo-hub-status (asked live from the Hub).
 * The engine version is archived at core/_archived/bot-engine-20261024/kept-originals/class-bot-tool-registry.php.
 *
 * // [2026-10-24 Johnny Chu - Chu Hoàng Anh] PHASE-0.126 R126-BOT — rewritten as the cell catalogue.
 *
 * @package BizCity_Twin_AI
 * @subpackage Channel_Gateway\Bot
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_Bot_Tool_Registry {

	const STATUS_AVAILABLE    = 'available';
	const STATUS_UNCONFIGURED = 'unconfigured';
	const STATUS_NEEDS_BRIDGE = 'needs_bridge';

	/**
	 * tool id => label, group (read|action), description, cell (the cell's tool key(s)), default_off (opt-in per Guru).
	 * Zalo actions add scope (thread|group) and owner (only the number's owner uid may trigger it).
	 *
	 * @return array<string,array>
	 */
	public static function catalog(): array {
		return array(
			// ── read ──
			'current_datetime'     => array( 'label' => 'Ngày giờ hiện tại', 'group' => 'read', 'cell' => 'get_datetime', 'description' => 'Trả lời "hôm nay thứ mấy / mấy giờ" theo giờ Việt Nam.' ),
			'web_search'           => array( 'label' => 'Tra cứu web', 'group' => 'read', 'cell' => 'web_search', 'description' => 'Tìm nguồn cho câu hỏi thời sự, giá cả, sự kiện.' ),
			'read_url'             => array( 'label' => 'Đọc nội dung trang', 'group' => 'read', 'cell' => 'web_fetch', 'description' => 'Bóc chữ từ một URL khách gửi.' ),
			'read_image'           => array( 'label' => 'Xem ảnh khách gửi', 'group' => 'read', 'cell' => 'read_image', 'description' => 'Nhìn ảnh khách gửi với một câu hỏi cụ thể (đếm, đọc chữ nhỏ, so màu).' ),
			'list_threads'         => array( 'label' => 'Liệt kê nhóm khác (chủ tài khoản)', 'group' => 'read', 'cell' => 'list_threads', 'description' => 'Chỉ chủ tài khoản, chat riêng: đếm các nhóm Zalo khác số này đang tham gia.' ),
			'read_thread'          => array( 'label' => 'Đọc nội dung nhóm khác (chủ tài khoản)', 'group' => 'read', 'cell' => 'read_thread', 'description' => 'Chỉ chủ tài khoản, chat riêng: đọc tin gần đây của một nhóm đã liệt kê.' ),
			'scrape_social_data'   => array( 'label' => 'Cào dữ liệu mạng xã hội', 'group' => 'read', 'cell' => 'scrape_social_data', 'description' => 'Lấy dữ liệu công khai Facebook / TikTok / YouTube / Shopee.' ),
			'search_arxiv'         => array( 'label' => 'Tra cứu arXiv', 'group' => 'read', 'cell' => 'search_arxiv', 'default_off' => true, 'description' => 'Tìm bài báo arXiv.' ),
			'search_scholar'       => array( 'label' => 'Tra cứu bài báo khoa học', 'group' => 'read', 'cell' => 'search_scholar', 'default_off' => true, 'description' => 'Semantic Scholar · Crossref · PubMed.' ),
			'search_github'        => array( 'label' => 'Tra cứu GitHub', 'group' => 'read', 'cell' => 'search_github', 'default_off' => true, 'description' => 'Tìm repo / issue GitHub.' ),
			'search_stackexchange' => array( 'label' => 'Tra cứu StackOverflow', 'group' => 'read', 'cell' => 'search_stackexchange', 'default_off' => true, 'description' => 'Câu hỏi kỹ thuật trên StackOverflow / SuperUser / ServerFault.' ),
			'search_hackernews'    => array( 'label' => 'Tra cứu Hacker News', 'group' => 'read', 'cell' => 'search_hackernews', 'default_off' => true, 'description' => 'Bài thảo luận công nghệ trên Hacker News.' ),
			'search_wikipedia'     => array( 'label' => 'Tra cứu Wikipedia', 'group' => 'read', 'cell' => 'search_wikipedia', 'default_off' => true, 'description' => 'Bài Wikipedia tiếng Việt / tiếng Anh.' ),
			// ── action ──
			'save_memory'          => array( 'label' => 'Ghi nhớ lâu dài', 'group' => 'action', 'cell' => 'save_memory', 'description' => 'Nhớ điều khách nói (sở thích, thông tin, đính chính) để dùng ở lần chat sau. Chỉ chat riêng.' ),
			'schedule_task'        => array( 'label' => 'Hẹn lịch nhắc / việc lặp', 'group' => 'action', 'cell' => 'schedule_task', 'default_off' => true, 'description' => 'Đặt / xem / huỷ lịch nhắc cho cuộc chat này. Bot tự nói khi tới giờ — bật khi thật cần.' ),
			'generate_image'       => array( 'label' => 'Vẽ ảnh AI', 'group' => 'action', 'cell' => 'create_image', 'description' => 'Vẽ mới / sửa ảnh khách vừa gửi.' ),
			'create_document'      => array( 'label' => 'Tạo file Word / Excel / CSV / Markdown', 'group' => 'action', 'cell' => 'create_word_document, create_excel_file, create_csv_file, create_markdown_file', 'default_off' => true, 'description' => 'Tạo và gửi một file khi khách cần văn bản / bảng dài (báo giá, hợp đồng, bảng kê).' ),
			'tts'                  => array( 'label' => 'Giọng nói (TTS)', 'group' => 'action', 'cell' => 'create_voice', 'description' => 'Gửi thêm một tin thoại đọc đoạn ngắn khi khách xin nghe giọng.' ),
			'send_file'            => array( 'label' => 'Gửi file', 'group' => 'action', 'cell' => 'send_file', 'description' => 'Gửi file kèm chú thích.' ),
			// ── Zalo actions (cell's own names; group-only / owner-only rules are enforced by the cell) ──
			'get_group_info'              => array( 'label' => 'Xem thành viên nhóm', 'group' => 'action', 'cell' => 'get_group_info', 'scope' => 'group', 'owner' => false, 'description' => 'Danh sách thành viên nhóm (uid + tên) — trước khi tag / bổ nhiệm ai đó theo tên.' ),
			'mention_member'              => array( 'label' => 'Nhắc tên (@tag) thành viên', 'group' => 'action', 'cell' => 'tag_member', 'scope' => 'group', 'owner' => false, 'description' => 'Trong nhóm: gọi tên một thành viên ở đầu câu trả lời.' ),
			'react_message'               => array( 'label' => 'Thả cảm xúc', 'group' => 'action', 'cell' => 'add_reaction', 'scope' => 'thread', 'owner' => false, 'description' => 'Thả một cảm xúc vào tin khách vừa gửi.' ),
			'send_sticker'                => array( 'label' => 'Gửi sticker', 'group' => 'action', 'cell' => 'send_sticker', 'scope' => 'thread', 'owner' => false, 'description' => 'Gửi một nhãn dán Zalo theo từ khoá cảm xúc.' ),
			'create_poll'                 => array( 'label' => 'Tạo bình chọn', 'group' => 'action', 'cell' => 'create_poll', 'scope' => 'group', 'owner' => false, 'description' => 'Tạo bình chọn trong nhóm đang chat.' ),
			'lock_poll'                   => array( 'label' => 'Khoá bình chọn', 'group' => 'action', 'cell' => 'lock_poll', 'scope' => 'group', 'owner' => false, 'description' => 'Khoá bình chọn đang mở.' ),
			'recall_message'              => array( 'label' => 'Thu hồi tin bot vừa gửi', 'group' => 'action', 'cell' => 'undo_message', 'scope' => 'thread', 'owner' => true, 'description' => 'Chỉ chủ tài khoản: thu hồi tin gần nhất bot đã gửi.' ),
			'list_pending_group_members'  => array( 'label' => 'Xem danh sách chờ duyệt', 'group' => 'action', 'cell' => 'list_pending_group_members', 'scope' => 'group', 'owner' => true, 'description' => 'Chỉ chủ tài khoản: người đang chờ duyệt vào nhóm.' ),
			'review_pending_group_member' => array( 'label' => 'Duyệt / từ chối thành viên', 'group' => 'action', 'cell' => 'review_pending_member', 'scope' => 'group', 'owner' => true, 'description' => 'Chỉ chủ tài khoản: duyệt hoặc từ chối một người đang chờ.' ),
			'kick_group_member'           => array( 'label' => 'Kick thành viên', 'group' => 'action', 'cell' => 'kick_member', 'scope' => 'group', 'owner' => true, 'description' => 'Chỉ chủ tài khoản: đuổi một thành viên khỏi nhóm.' ),
			'transfer_group_owner'        => array( 'label' => 'Chuyển quyền trưởng nhóm', 'group' => 'action', 'cell' => 'transfer_group_owner', 'scope' => 'group', 'owner' => true, 'description' => 'Chỉ chủ tài khoản: chuyển trưởng nhóm — không hoàn tác được.' ),
			'set_group_deputy'            => array( 'label' => 'Bổ nhiệm / gỡ phó nhóm', 'group' => 'action', 'cell' => 'set_group_deputy', 'scope' => 'group', 'owner' => true, 'description' => 'Chỉ chủ tài khoản: bổ nhiệm hoặc gỡ phó nhóm.' ),
			'set_group_member_blocked'    => array( 'label' => 'Chặn / bỏ chặn khỏi nhóm', 'group' => 'action', 'cell' => 'set_group_member_blocked', 'scope' => 'group', 'owner' => true, 'description' => 'Chỉ chủ tài khoản: chặn hoặc bỏ chặn một người vào lại nhóm.' ),
			'set_group_invite_link'       => array( 'label' => 'Bật / tắt link mời nhóm', 'group' => 'action', 'cell' => 'set_group_invite_link', 'scope' => 'group', 'owner' => true, 'description' => 'Chỉ chủ tài khoản: bật (trả link) hoặc tắt link mời nhóm.' ),
			'rename_group'                => array( 'label' => 'Đổi tên nhóm', 'group' => 'action', 'cell' => 'rename_group', 'scope' => 'group', 'owner' => true, 'description' => 'Chỉ chủ tài khoản: đổi tên nhóm đang chat.' ),
			'change_group_avatar'         => array( 'label' => 'Đổi ảnh nhóm', 'group' => 'action', 'cell' => 'change_group_avatar', 'scope' => 'group', 'owner' => true, 'description' => 'Chỉ chủ tài khoản: đặt ảnh vừa gửi làm ảnh nhóm.' ),
			'pin_group_note'              => array( 'label' => 'Ghim thông báo nhóm', 'group' => 'action', 'cell' => 'pin_group_note', 'scope' => 'group', 'owner' => true, 'description' => 'Chỉ chủ tài khoản: tạo và ghim một thông báo lên đầu nhóm.' ),
		);
	}

	/**
	 * Catalogue rows for the tool matrices. Same row shape as before; every tool is `available` because the cell runs it
	 * (whether this number's plan allows a media tool is GET /bot/tools/zalo-hub-status).
	 *
	 * @param object|null $character  Unused since the PHP vertical tools left (kept for the caller's signature).
	 * @param string      $account_id Unused (kept for the caller's signature).
	 * @return array<int,array{id:string,label:string,group:string,description:string,infra:string,status:string,hint:string,kind:string,default_off:bool}>
	 */
	public static function rows( $character = null, string $account_id = '' ): array {
		$out = array();
		foreach ( self::catalog() as $id => $row ) {
			$where = '';
			if ( isset( $row['scope'] ) ) {
				$where = ( 'group' === $row['scope'] ? ' · chỉ trong nhóm' : ' · chat riêng và nhóm' ) . ( ! empty( $row['owner'] ) ? ' · chỉ chủ tài khoản' : '' );
			}
			$out[] = array(
				'id'          => $id,
				'label'       => $row['label'],
				'group'       => $row['group'],
				'description' => $row['description'],
				'infra'       => 'cell: ' . $row['cell'] . $where,
				'status'      => self::STATUS_AVAILABLE,
				'hint'        => 'Cell xử lý.',
				'kind'        => 'builtin',
				'default_off' => ! empty( $row['default_off'] ),
			);
		}
		return $out;
	}
}
