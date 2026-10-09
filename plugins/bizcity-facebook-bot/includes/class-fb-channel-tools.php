<?php
/**
 * Channel-tier tool declarations of the Facebook adapter (PHASE-0.90 S90-T3, doc 60 §2): messenger.send, fb.comment.reply,
 * fb.post.create, fb.group.post (only while the groups flag is on), registered through `bizcity_mcp_register_tools` into the SAME
 * BizCity_MCP_Tool_Registry as the site tools (tier 'channel'). Handlers delegate to the adapter's A3 and take the page / thread
 * from the TURN context ($ctx['turn'] or the `bizcity_mcp_channel_turn` filter), NEVER from the model's arguments.
 * Customer-usable tools are scope 'thread' (they can only answer the conversation the turn is in); posting is owner/staff + confirm always.
 *
 * @package BizCity_Facebook_Bot
 * @since   PHASE-0.90 S90-T3
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_FB_Channel_Tools', false ) ) {
	return;
}

final class BizCity_FB_Channel_Tools {

	public static function register(): void {
		if ( ! class_exists( 'BizCity_MCP_Tool_Registry' ) ) {
			return;
		}
		$reply_out = BizCity_MCP_Tool_Registry::envelope_schema( array( 'sent' => array( 'type' => 'boolean' ), 'reason' => array( 'type' => 'string' ), 'handoff' => array( 'type' => 'boolean' ) ), array( 'sent' ) );
		$post_out  = BizCity_MCP_Tool_Registry::envelope_schema( array( 'status' => array( 'type' => 'string', 'enum' => array( 'needs_confirmation', 'done' ) ), 'preview' => array( 'type' => 'object' ), 'confirm_token' => array( 'type' => 'string' ), 'expires_at' => array( 'type' => 'string' ), 'post_id' => array( 'type' => 'string' ), 'permalink' => array( 'type' => 'string' ) ) );
		$text_in   = array( 'type' => 'object', 'required' => array( 'text' ), 'properties' => array( 'text' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 2000 ) ) );
		$post_in   = array( 'type' => 'object', 'required' => array( 'message' ), 'properties' => array( 'message' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 5000 ), 'confirm_token' => array( 'type' => 'string' ) ) );
		// [2026-10-05 08:05 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.91-B2-2 — catalog marker sits above $common so the validator window sees mode + read_only.
		// @mcp bizcity-mcp-standard@1 tool fb.post.create
		$common    = array( 'tier' => 'channel', 'read_only' => false, 'destructive' => false, 'idempotent' => false, 'open_world' => true, 'mode' => 'channel', 'since' => '0.90' );

		BizCity_MCP_Tool_Registry::register( 'messenger.send', $common + array(
			'title' => 'Nhắn khách trên Messenger', 'description' => 'Gửi một tin nhắn vào ĐÚNG cuộc trò chuyện Messenger của lượt này (trong cửa sổ 24 giờ). Không chọn được page hay người nhận.',
			'input_schema' => $text_in, 'output_schema' => $reply_out, 'platform' => array( 'messenger' ), 'roles' => array( 'owner', 'staff', 'customer' ), 'scope' => 'thread',
			'capability' => 'channel.messenger.send', 'required_scope' => 'channel.reply', 'scopes' => array( 'channel.reply' ), 'confirm' => 'never', 'llm_alias' => 'messenger_send',
			'handler' => array( __CLASS__, 'messenger_send' ),
		) );
		BizCity_MCP_Tool_Registry::register( 'fb.comment.reply', $common + array(
			'title' => 'Trả lời bình luận Facebook', 'description' => 'Trả lời ĐÚNG bình luận của lượt này (dưới chính bình luận đó). Không chọn được bài hay bình luận khác.',
			'input_schema' => $text_in, 'output_schema' => $reply_out, 'platform' => array( 'fb' ), 'roles' => array( 'owner', 'staff', 'customer' ), 'scope' => 'thread',
			'capability' => 'channel.fb.reply', 'required_scope' => 'channel.reply', 'scopes' => array( 'channel.reply' ), 'confirm' => 'never', 'llm_alias' => 'reply_comment',
			'handler' => array( __CLASS__, 'comment_reply' ),
		) );
		// [2026-10-05 08:00 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.91-B2-2 — run_at (scheduled-action@1): the post is a Lịch row the FB
		// publisher fires (now or at run_at), never a Graph call inside the MCP request. Catalog: system:true (D91-29) — the
		// model posts through the default scenario `fb_post`; this tool stays for system callers.
		$post_in_at = $post_in;
		$post_in_at['properties']['run_at'] = array( 'type' => 'string', 'description' => 'Tùy chọn. Giờ của website, dạng YYYY-MM-DD HH:MM. Bỏ trống = đăng ngay ở nền.' );
		$post_out_at = BizCity_MCP_Tool_Registry::envelope_schema( array( 'status' => array( 'type' => 'string', 'enum' => array( 'needs_confirmation', 'queued', 'scheduled', 'done' ) ), 'preview' => array( 'type' => 'object' ), 'confirm_token' => array( 'type' => 'string' ), 'expires_at' => array( 'type' => 'string' ), 'event_id' => array( 'type' => 'integer' ), 'run_at' => array( 'type' => 'string' ), 'post_id' => array( 'type' => 'string' ), 'permalink' => array( 'type' => 'string' ) ) );
		BizCity_MCP_Tool_Registry::register( 'fb.post.create', $common + array(
			'title' => 'Đăng bài lên Fanpage', 'description' => 'Đăng một bài lên Fanpage đã chọn ở bước kết nối, ngay hoặc theo giờ hẹn (run_at). Chỉ chủ/nhân viên. Lần gọi đầu chỉ trả bản xem trước + confirm_token; gọi lại cùng tham số kèm confirm_token sau khi người dùng đồng ý.',
			'input_schema' => $post_in_at, 'output_schema' => $post_out_at, 'platform' => array( 'fb' ), 'roles' => array( 'owner', 'staff' ), 'scope' => 'channel',
			'capability' => 'channel.fb.post', 'required_scope' => 'channel.post', 'scopes' => array( 'channel.post' ), 'confirm' => 'always', 'llm_alias' => 'fb_post_create',
			'handler' => array( __CLASS__, 'post_create' ), 'preview' => array( __CLASS__, 'post_preview' ),
		) );
		if ( class_exists( 'BizCity_FB_Group_Adapter' ) && BizCity_FB_Group_Adapter::enabled() ) {
			BizCity_MCP_Tool_Registry::register( 'fb.group.post', $common + array(
				'title' => 'Đăng bài vào nhóm Facebook', 'description' => 'Đăng một bài vào nhóm Facebook đã chọn. Chỉ chủ/nhân viên. Lần gọi đầu chỉ trả bản xem trước + confirm_token.',
				'input_schema' => $post_in, 'output_schema' => $post_out, 'platform' => array( 'fb_group' ), 'roles' => array( 'owner', 'staff' ), 'scope' => 'channel',
				'capability' => 'channel.fb.group.post', 'required_scope' => 'channel.post', 'scopes' => array( 'channel.post' ), 'confirm' => 'always', 'llm_alias' => 'fb_group_post',
				'handler' => array( __CLASS__, 'group_post' ), 'preview' => array( __CLASS__, 'group_preview' ),
			) );
		}
	}

	/** R-ERROR-UX: code + message + hint + help_code. */
	private static function target_error( string $code, string $message, string $hint ): WP_Error {
		return new WP_Error( $code, $message, array( 'hint' => $hint, 'help_code' => 'S90-T9-TARGET' ) );
	}

	/** The turn supplied by the caller ($ctx['turn'] or the `bizcity_mcp_channel_turn` filter). Arguments never reach this. */
	private static function turn_of( array $ctx ): array {
		return isset( $ctx['turn'] ) && is_array( $ctx['turn'] ) ? $ctx['turn'] : (array) apply_filters( 'bizcity_mcp_channel_turn', array(), $ctx );
	}

	/**
	 * Q90-9 (b): the SITE decides where a channel tool acts, never the model.
	 * (i) a turn context wins (CRM/Inbox flows); (ii) scope 'channel' without a turn: option bizcity_fb_default_page when it is a
	 * connected page, else the only connected page, else `channel_ambiguous`; (iii) scope 'thread' without a turn: `thread_required`.
	 *
	 * @return array{platform:string,channel_ref:string,thread_key?:string,turn?:array}|WP_Error
	 */
	public static function resolve_target( array $ctx, string $platform, string $scope ) {
		$t   = self::turn_of( $ctx );
		$ref = (string) ( $t['channel_ref'] ?? '' );
		if ( 'thread' === $scope ) {
			if ( ! $t ) {
				return self::target_error( 'thread_required', 'Công cụ này chỉ trả lời trong một cuộc trò chuyện đang mở.', 'Dùng công cụ này từ Hộp thư CRM, trong đúng cuộc trò chuyện cần trả lời.' );
			}
			if ( $platform !== (string) ( $t['platform'] ?? '' ) || '' === $ref ) {
				return self::target_error( 'no_turn_context', 'Công cụ này chỉ chạy trong một cuộc trò chuyện ' . $platform . '.', 'Dùng công cụ trong lượt của kênh tương ứng.' );
			}
			return array( 'platform' => $platform, 'channel_ref' => $ref, 'thread_key' => (string) ( $t['thread_key'] ?? $t['platform_uid'] ?? '' ), 'turn' => $t );
		}
		$set = 'fb_group' === $platform ? array_values( array_filter( array_map( 'strval', (array) get_option( 'bizcity_fb_group_ids', array() ) ) ) ) : array_map( 'strval', array_keys( BizCity_FB_Channel_Adapter::pages() ) );
		if ( '' !== $ref && in_array( $ref, $set, true ) ) {
			return array( 'platform' => $platform, 'channel_ref' => $ref );
		}
		if ( 'fb_group' !== $platform ) {
			$default = (string) get_option( 'bizcity_fb_default_page', '' );
			if ( '' !== $default && in_array( $default, $set, true ) ) {
				return array( 'platform' => $platform, 'channel_ref' => $default );
			}
		}
		if ( 1 === count( $set ) ) {
			return array( 'platform' => $platform, 'channel_ref' => $set[0] );
		}
		return self::target_error( 'channel_ambiguous', 'Chưa xác định được nơi đăng bài.', 'Chọn Fanpage mặc định ở bước 3 của phần cài đặt Facebook.' );
	}

	public static function messenger_send( array $args, array $ctx ) {
		$r = self::resolve_target( $ctx, 'messenger', 'thread' );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		$t = $r['turn'];
		return self::reply( $t, 'messenger', (string) ( $t['platform_uid'] ?? '' ), $r['channel_ref'], (string) ( $args['text'] ?? '' ) );
	}

	public static function comment_reply( array $args, array $ctx ) {
		$r = self::resolve_target( $ctx, 'fb', 'thread' );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		$t = $r['turn'];
		return self::reply( $t, 'fb', (string) ( $t['platform_uid'] ?? '' ), $r['channel_ref'], (string) ( $args['text'] ?? '' ) );
	}

	private static function reply( array $t, string $platform, string $uid, string $page, string $text ) {
		$out = array(
			'status' => 'reply', 'to_contact' => array( 'platform' => $platform, 'channel_ref' => $page, 'platform_uid' => $uid ), 'thread_key' => (string) ( $t['thread_key'] ?? $uid ),
			'parts' => array( array( 'type' => 'text', 'text' => $text ) ), 'constraints' => array( 'window_24h_until' => $t['window_24h_until'] ?? null, 'needs_human' => false ),
		);
		$r = BizCity_FB_Channel_Adapter::send( $out, BizCity_FB_Channel_Adapter::page_token( $page ) );
		return array( 'sent' => ! empty( $r['sent'] ), 'reason' => (string) $r['reason'], 'handoff' => ! empty( $r['handoff'] ) );
	}

	/** fb.post.create / fb.group.post target (Q90-9): the site's decision, see resolve_target(). @return string|WP_Error channel_ref */
	private static function target( array $ctx, string $platform ) {
		$r = self::resolve_target( $ctx, $platform, 'channel' );
		return is_wp_error( $r ) ? $r : $r['channel_ref'];
	}

	public static function group_preview( array $args, array $ctx ) {
		return self::post_preview( $args, $ctx, 'fb_group' );
	}

	public static function post_preview( array $args, array $ctx, string $platform = 'fb' ) {
		$ref = self::target( $ctx, $platform );
		if ( is_wp_error( $ref ) ) {
			return $ref;
		}
		$when = '';
		if ( 'fb' === $platform && class_exists( 'BizCity_MCP_Scheduled_Action' ) ) {
			// [2026-10-05 08:00 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.91-B2-2 — a bad run_at is refused at preview, before any token.
			$at = BizCity_MCP_Scheduled_Action::parse_run_at( $args['run_at'] ?? '' );
			if ( is_wp_error( $at ) ) {
				return $at;
			}
			$when = '' !== $at ? ' lúc ' . substr( $at, 0, 16 ) : ' ngay';
		}
		return array( 'summary' => 'Đăng bài lên ' . $ref . $when . ': ' . mb_substr( (string) ( $args['message'] ?? '' ), 0, 120 ), 'channel_ref' => $ref );
	}

	public static function post_create( array $args, array $ctx ) {
		$ref = self::target( $ctx, 'fb' );
		if ( is_wp_error( $ref ) ) {
			return $ref;
		}
		if ( class_exists( 'BizCity_MCP_Scheduled_Action' ) ) {
			// [2026-10-05 08:00 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.91-B2-2 — one Lịch row `fb_post`; BizCity_FB_Publisher posts it
			// (run-now single event, the 5-minute scan as the net). Page = the site's decision above, never the model's.
			$message = (string) ( $args['message'] ?? '' );
			$pages   = class_exists( 'BizCity_FB_Channel_Adapter' ) ? (array) BizCity_FB_Channel_Adapter::pages() : array();
			$name    = is_array( $pages[ $ref ] ?? null ) ? (string) ( $pages[ $ref ]['name'] ?? $pages[ $ref ]['page_name'] ?? $ref ) : $ref;
			return BizCity_MCP_Scheduled_Action::commit( 'fb.post.create', 'fb_post', $args, $ctx, array(
				'title'    => 'Đăng Facebook: ' . mb_substr( $message, 0, 60 ),
				'metadata' => array(
					'fb_page_id'        => $ref,
					'fb_page_name'      => $name,
					'fb_content'        => $message,
					'fb_publish_status' => 'pending',
					'owner_user_id'     => (int) ( $ctx['user_id'] ?? 0 ),
				),
			) );
		}
		$r = BizCity_FB_Channel_Adapter::publish_post( $ref, (string) ( $args['message'] ?? '' ), BizCity_FB_Channel_Adapter::page_token( $ref ) );
		return $r['ok'] ? array( 'status' => 'done', 'post_id' => $r['post_id'], 'permalink' => $r['permalink'] ) : new WP_Error( 'graph_error', 'Facebook không đăng được bài.', array( 'hint' => 'Kiểm tra quyền đăng bài của Fanpage.' ) );
	}

	public static function group_post( array $args, array $ctx ) {
		$ref = self::target( $ctx, 'fb_group' );
		if ( is_wp_error( $ref ) ) {
			return $ref;
		}
		$r = BizCity_FB_Group_Adapter::post( $ref, (string) ( $args['message'] ?? '' ), BizCity_FB_Channel_Adapter::page_token( $ref ) );
		return $r['ok'] ? array( 'status' => 'done', 'post_id' => $r['post_id'] ) : new WP_Error( 'graph_error', 'Facebook không đăng được bài vào nhóm.', array( 'hint' => 'Kiểm tra quyền nhóm.' ) );
	}
}

add_action( 'bizcity_mcp_register_tools', array( 'BizCity_FB_Channel_Tools', 'register' ) );
