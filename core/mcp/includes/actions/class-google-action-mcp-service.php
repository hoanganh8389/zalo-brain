<?php
/**
 * BizCity_Google_Action_MCP_Service — Gmail tool of the one MCP standard (PHASE-0.93 gap B): gmail.send.
 *
 * Thin handler over the Google connection of "Kết nối" (BZGoogle_Token_Store / BZGoogle_Google_Service — the same accounts the
 * app BizCity or the user's own Google client logged in). Mode `channel` (already known to the cell and to every plan switch,
 * so the cell offers the tool with NO cell change: it builds its tools from the principal's own tools/list), scope
 * `channel.mail`. Owner/staff only, confirm ALWAYS (the first call returns a preview + confirm_token, the commit sends).
 *
 * Calendar needs no second tool: booking.create writes the user's Lịch and the Scheduler pushes the event to Google Calendar
 * when that user connected Google (BizCity_Scheduler_Google::on_event_created) — "one door per job".
 *
 * Safety: ONE recipient per call (no list, no cc/bcc), header injection stripped, 50 mails per user per day, the sender is the
 * caller's own connected account (never chosen by the model), and the mail needs the "Gửi email" permission of that login.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\MCP
 * @since      2026-10-07 (PHASE-0.93)
 */

defined( 'ABSPATH' ) || exit;

// [2026-10-07 10:20 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.93 gap B — new file.
final class BizCity_Google_Action_MCP_Service {

	const SEND_SCOPE_URI = 'https://www.googleapis.com/auth/gmail.send';
	const DAILY_CAP      = 50;

	/** @var array<string,callable> test seams: token(blog_id, uid), send(blog_id, uid, args), today() */
	public static $readers = array();

	public static function init() {
		add_action( 'bizcity_mcp_register_tools', array( __CLASS__, 'register_tools' ) );
	}

	public static function register_tools() {
		if ( ! isset( self::$readers['send'] ) && ( ! class_exists( 'BZGoogle_Google_Service' ) || ! class_exists( 'BZGoogle_Token_Store' ) ) ) {
			return;
		}
		$out = array(
			'status'        => array( 'type' => 'string', 'enum' => array( 'needs_confirmation', 'done' ) ),
			'preview'       => array( 'type' => 'object' ),
			'confirm_token' => array( 'type' => 'string' ),
			'expires_at'    => array( 'type' => 'string' ),
			'sent'          => array( 'type' => 'boolean' ),
			'from'          => array( 'type' => 'string' ),
			'message_id'    => array( 'type' => 'string' ),
		);

		// @mcp bizcity-mcp-standard@1 tool gmail.send
		BizCity_MCP_Tool_Registry::register( 'gmail.send', array(
			'title'          => 'Gửi email bằng Gmail',
			'description'    => 'Gửi MỘT email (một người nhận) từ tài khoản Gmail mà chính người dùng đã kết nối ở "Kết nối → Google". Lần gọi đầu chỉ trả bản xem trước + confirm_token; gọi lại cùng tham số kèm confirm_token sau khi người dùng đồng ý. Không chọn được người gửi.',
			'input_schema'   => array( 'type' => 'object', 'required' => array( 'to', 'subject', 'body' ), 'properties' => array(
				'to'            => array( 'type' => 'string', 'maxLength' => 190, 'description' => 'Một địa chỉ email.' ),
				'subject'       => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 200 ),
				'body'          => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 5000, 'description' => 'Văn bản thường; xuống dòng được giữ.' ),
				'confirm_token' => array( 'type' => 'string' ),
			) ),
			'output_schema'  => BizCity_MCP_Tool_Registry::envelope_schema( $out ),
			'read_only'      => false,
			'destructive'    => false,
			'idempotent'     => false,
			'open_world'     => true,
			'required_scope' => 'channel.mail',
			'handler'        => array( __CLASS__, 'send' ),
			'preview'        => array( __CLASS__, 'send_preview' ),
			'mode'           => 'channel',
			'scopes'         => array( 'channel.mail' ),
			'confirm'        => 'always',
			'llm_alias'      => 'gmail_send',
			'capability'     => 'google.gmail.send',
			'fallback_pack'  => null,
			'since'          => '0.93',
		) );
	}

	/* ── gmail.send (confirm = always) ───────────────────────────── */

	public static function send_preview( array $args, array $ctx ) {
		return BizCity_MCP_Action_Support::run_as( $ctx, static function ( $uid ) use ( $args ) {
			$plan = self::plan( $args, $uid );
			if ( is_wp_error( $plan ) ) {
				return $plan;
			}
			return array(
				'summary' => 'Gửi email tới ' . $plan['to'] . ', tiêu đề "' . $plan['subject'] . '", từ ' . $plan['from'] . '.',
				'to'      => $plan['to'],
				'subject' => $plan['subject'],
				'from'    => $plan['from'],
				'excerpt' => mb_substr( $plan['body'], 0, 240 ),
			);
		} );
	}

	public static function send( array $args, array $ctx ) {
		return BizCity_MCP_Action_Support::run_as( $ctx, static function ( $uid ) use ( $args ) {
			$plan = self::plan( $args, $uid ); // account, permission and daily cap re-checked at commit time
			if ( is_wp_error( $plan ) ) {
				return $plan;
			}
			$mail = array( 'to' => $plan['to'], 'subject' => $plan['subject'], 'body' => nl2br( esc_html( $plan['body'] ) ) );
			$res  = isset( self::$readers['send'] )
				? call_user_func( self::$readers['send'], get_current_blog_id(), $uid, $mail )
				: BZGoogle_Google_Service::gmail_send( get_current_blog_id(), $uid, $mail );
			if ( is_wp_error( $res ) ) {
				$data   = $res->get_error_data();
				$status = is_array( $data ) ? (int) ( $data['status'] ?? 0 ) : 0;
				if ( in_array( $status, array( 401, 403 ), true ) || 'no_token' === $res->get_error_code() ) {
					return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::GOOGLE_NOT_CONNECTED, 'Google không cho gửi email: đăng nhập lại và tick "Gửi email".', 409 );
				}
				return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::QUERY_INVALID, 'Gmail chưa gửi được: ' . mb_substr( $res->get_error_message(), 0, 80 ), 422 );
			}
			self::count_one( $uid );
			return array( 'sent' => true, 'from' => $plan['from'], 'message_id' => (string) ( is_array( $res ) ? ( $res['id'] ?? '' ) : '' ) );
		} );
	}

	/** @return array{to:string,subject:string,body:string,from:string}|WP_Error */
	private static function plan( array $args, $uid ) {
		if ( ! ( BizCity_MCP_Action_Support::is_admin( $uid ) || BizCity_MCP_Action_Support::crm_rank( $uid ) >= 1 ) ) {
			return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::SCOPE_DENIED, 'Bạn chưa có quyền gửi email thay mặt cửa hàng.', 403 );
		}
		$to = trim( (string) ( $args['to'] ?? '' ) );
		if ( '' === $to || false !== strpbrk( $to, ",; \r\n\t<>" ) || ! is_email( $to ) ) {
			return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::QUERY_INVALID, 'Cần đúng MỘT địa chỉ email người nhận.', 422 );
		}
		$subject = trim( preg_replace( '/[\r\n]+/', ' ', sanitize_text_field( (string) ( $args['subject'] ?? '' ) ) ) );
		$body    = trim( str_replace( "\r\n", "\n", (string) ( $args['body'] ?? '' ) ) );
		if ( '' === $subject || '' === $body ) {
			return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::QUERY_INVALID, 'Cần tiêu đề và nội dung email.', 422 );
		}
		$subject = mb_substr( $subject, 0, 200 );
		$body    = mb_substr( $body, 0, 5000 );
		$token   = isset( self::$readers['token'] ) ? call_user_func( self::$readers['token'], get_current_blog_id(), $uid ) : BZGoogle_Token_Store::get_token( get_current_blog_id(), $uid );
		if ( empty( $token ) || empty( $token['google_email'] ) ) {
			return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::GOOGLE_NOT_CONNECTED, 'Bạn chưa kết nối Google (Gmail).', 409 );
		}
		if ( false === strpos( ' ' . (string) ( $token['scope'] ?? '' ) . ' ', ' ' . self::SEND_SCOPE_URI . ' ' ) ) {
			return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::GOOGLE_NOT_CONNECTED, 'Tài khoản Google chưa cho phép "Gửi email".', 409 );
		}
		if ( self::sent_today( $uid ) >= self::DAILY_CAP ) {
			return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::RATE_LIMITED, 'Hôm nay đã gửi đủ ' . self::DAILY_CAP . ' email qua trợ lý.', 429 );
		}
		return array( 'to' => $to, 'subject' => $subject, 'body' => $body, 'from' => (string) $token['google_email'] );
	}

	private static function day_key( $uid ) {
		$day = isset( self::$readers['today'] ) ? (string) call_user_func( self::$readers['today'] ) : gmdate( 'Ymd' );
		return 'bizcity_gmail_send_cap_' . (int) $uid . '_' . $day;
	}

	private static function sent_today( $uid ): int {
		return (int) get_transient( self::day_key( $uid ) );
	}

	private static function count_one( $uid ): void {
		set_transient( self::day_key( $uid ), self::sent_today( $uid ) + 1, DAY_IN_SECONDS );
	}
}

BizCity_Google_Action_MCP_Service::init();
