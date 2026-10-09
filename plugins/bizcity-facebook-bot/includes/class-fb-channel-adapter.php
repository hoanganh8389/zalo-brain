<?php
/**
 * BizCity_FB_Channel_Adapter - Facebook comments + Messenger as an adapter of the cell's one brain (PHASE-0.90 S90-F2, R-TAA-13).
 *
 * The four duties:
 *   A1 build_inbound()   webhook event -> channel-inbound@1.0.0 (pure; no role / person / tenant id, R-CID-3)
 *   A2 bind_identity()   BizCity_Identity_Hub::bind for messenger / fb, never linked to a WP user (R-CID-6)
 *   A3 send()            channel-outbound@1.0.0 -> Graph (Messenger inside the 24 h window, comment under THAT comment)
 *   A4 CRM rows + usage  the existing CRM Facebook ingestor (inbound) and its gateway-outbound mirror
 * Flag `bizcity_fb_adapter` = legacy (DEFAULT: nothing is forwarded, the old bot runs untouched) | cell (forward to the Hub;
 * reply => send; skip / any Hub error / unsent => handle() returns false and the OLD bot path runs unchanged, F-T6).
 *
 * Seams (tests): $seams['graph'](method, path, params, token), ['now'], ['token'](page_id), ['bind'], ['history'], ['name'],
 * ['crm_inbound'], ['crm_outbound'], ['handoff'], ['inbox_save'], ['trace_id'].
 *
 * @package BizCity_Facebook_Bot
 * @since   PHASE-0.90 S90-F2
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_FB_Channel_Adapter', false ) ) {
	return;
}

final class BizCity_FB_Channel_Adapter {

	const CONTRACT_IN   = 'channel-inbound@1.0.0';
	const FLAG          = 'bizcity_fb_adapter';
	const OPT_PAGES     = 'bizcity_fb_adapter_pages';
	const OPT_PING      = 'bizcity_fb_adapter_ping';
	const OPT_HUMAN     = 'bizcity_fb_needs_human';
	const OPT_SCOPES    = 'bizcity_fb_granted_scopes';
	const P_MESSENGER   = 'messenger';
	const P_FB          = 'fb';
	const P_GROUP       = 'fb_group';
	const GRAPH         = 'https://graph.facebook.com/v18.0';
	const WINDOW        = 86400;
	const HISTORY_MAX   = 20;

	/** @var array<string,callable> */
	public static $seams = array();

	private static function seam( string $name, callable $default, array $args = array() ) {
		$fn = isset( self::$seams[ $name ] ) ? self::$seams[ $name ] : $default;
		return call_user_func_array( $fn, $args );
	}

	private static function now(): int {
		return (int) self::seam( 'now', 'time' );
	}

	/** legacy (default) | cell. Anything but the exact word `cell` is legacy. */
	public static function mode(): string {
		return 'cell' === get_option( self::FLAG, 'legacy' ) ? 'cell' : 'legacy';
	}

	/* ---------------- pages chosen at step 3 (written by BizCity_FB_Setup_REST) ---------------- */

	/** @return array<string,array> page_id => {owner_user_id, registered{messenger,fb}, at} */
	public static function pages(): array {
		$p = get_option( self::OPT_PAGES, array() );
		return is_array( $p ) ? $p : array();
	}

	public static function owner_for_page( string $page_id ): int {
		$p = self::pages();
		return (int) ( $p[ $page_id ]['owner_user_id'] ?? 0 );
	}

	/** A page forwards to the Hub only after the Hub accepted its registration for that platform. */
	public static function page_registered( string $page_id, string $platform ): bool {
		$p = self::pages();
		return 'ok' === ( $p[ $page_id ]['registered'][ $platform ] ?? '' );
	}

	public static function page_token( string $page_id ): string {
		return (string) self::seam( 'token', static function ( $page_id ) {
			if ( class_exists( 'BizCity_Facebook_Bot_Database' ) ) {
				$bot = BizCity_Facebook_Bot_Database::instance()->get_bot_by_page_id( $page_id );
				if ( $bot && ! empty( $bot->page_access_token ) ) {
					return (string) $bot->page_access_token;
				}
			}
			foreach ( (array) get_option( 'fb_pages_connected', array() ) as $p ) {
				if ( (string) ( $p['id'] ?? '' ) === $page_id && ! empty( $p['access_token'] ) ) {
					return (string) $p['access_token'];
				}
			}
			return '';
		}, array( $page_id ) );
	}

	/** Scopes the OAuth recorded (null = never recorded). */
	public static function granted_scopes(): ?array {
		$s = get_option( self::OPT_SCOPES, null );
		return is_array( $s ) ? array_values( array_map( 'strval', $s ) ) : null;
	}

	/* ---------------- webhook entry (one guarded call from the old handler) ---------------- */

	/**
	 * @param array $event {page_id, messaging} | {page_id, change}
	 * @return bool true = the cell answered and the answer was sent (or handed to a human): the old bot must NOT run.
	 */
	public static function handle( array $event ): bool {
		self::touch_ping();
		if ( 'cell' !== self::mode() ) {
			return false;
		}
		try {
			$in = self::build_inbound( $event );
			if ( null === $in ) {
				return false;
			}
			$page = (string) $in['channel']['channel_ref'];
			$plat = (string) $in['channel']['platform'];
			if ( ! self::page_registered( $page, $plat ) ) {
				return false;
			}
			$claim = 'bizcity_fb_adapter_claim_' . md5( $plat . '|' . $page . '|' . $in['message']['id'] );
			if ( get_transient( $claim ) ) {
				return true; // a duplicate delivery of an event this adapter already took (answered or handed to the old bot)
			}
			set_transient( $claim, 1, 600 );
			self::crm_inbound( $in, $event ); // A4 first: a CRM row exists whatever happens next
			if ( ! BizCity_FB_Hub_Client::is_ready() ) {
				return false;
			}
			if ( $in['tenant']['key_id'] <= 0 ) {
				$in['tenant']['key_id'] = BizCity_FB_Hub_Client::key_id( true );
				if ( $in['tenant']['key_id'] <= 0 ) {
					return false;
				}
			}
			self::bind_identity( $in );
			$res = BizCity_FB_Hub_Client::inbound( $in );
			if ( ! $res['ok'] || 'reply' !== (string) ( $res['data']['status'] ?? '' ) ) {
				return false;
			}
			$sent = self::send( $res['data'], self::page_token( $page ) );
			if ( ! empty( $sent['sent'] ) ) {
				self::seam( 'inbox_save', array( __CLASS__, 'inbox_save' ), array( $in, $res['data'] ) );
			}
			return ! empty( $sent['sent'] ) || ! empty( $sent['handoff'] );
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/** Server-side proof that Facebook reached this site's webhook (the only source of step 3 "Đạt"). Throttled, never throws. */
	private static function touch_ping(): void {
		$last = (int) get_option( self::OPT_PING, 0 );
		if ( self::now() - $last > 600 ) {
			update_option( self::OPT_PING, self::now(), false );
		}
	}

	/* ---------------- A1 ---------------- */

	/**
	 * @param array $event {page_id, messaging} | {page_id, change} | {group_id, comment, self_id?}
	 * @return array|null channel-inbound@1.0.0 body, or null when the event is not a customer message (echo, own comment, non-text).
	 */
	public static function build_inbound( array $event ): ?array {
		if ( isset( $event['group_id'] ) ) {
			return self::build_group( $event );
		}
		$page = (string) ( $event['page_id'] ?? '' );
		if ( '' === $page ) {
			return null;
		}
		if ( isset( $event['messaging'] ) && is_array( $event['messaging'] ) ) {
			return self::build_messenger( $page, $event['messaging'] );
		}
		if ( isset( $event['change']['value'] ) && is_array( $event['change']['value'] ) ) {
			return self::build_comment( $page, $event['change']['value'] );
		}
		return null;
	}

	private static function build_messenger( string $page, array $m ): ?array {
		$msg  = is_array( $m['message'] ?? null ) ? $m['message'] : array();
		$psid = (string) ( $m['sender']['id'] ?? '' );
		$text = trim( (string) ( $msg['text'] ?? '' ) );
		if ( ! empty( $msg['is_echo'] ) || '' === $psid || $psid === $page || '' === $text ) {
			return null; // echo / the page itself / not text: the old path owns them
		}
		$ts   = (int) ( ( $m['timestamp'] ?? 0 ) / 1000 );
		$ts   = $ts > 0 ? $ts : self::now();
		$mid  = (string) ( $msg['mid'] ?? '' );
		$name = (string) self::seam( 'name', array( __CLASS__, 'customer_name' ), array( $page, $psid ) );
		return self::envelope( self::P_MESSENGER, $page, $psid, $name, $psid, $mid ?: 'fbsim:' . $page . ':' . $psid . ':' . $ts, $text, $ts, array(
			'kind' => 'message', 'window_24h_until' => gmdate( 'Y-m-d\TH:i:s\Z', $ts + self::WINDOW ), 'comment_parent' => null,
		), (array) self::seam( 'history', array( __CLASS__, 'history' ), array( $page, $psid ) ) );
	}

	private static function build_comment( string $page, array $v ): ?array {
		$comment_id = (string) ( $v['comment_id'] ?? '' );
		$from       = (string) ( $v['from']['id'] ?? '' );
		$text       = trim( (string) ( $v['message'] ?? '' ) );
		if ( 'comment' !== ( $v['item'] ?? '' ) || 'add' !== ( $v['verb'] ?? 'add' ) || '' === $comment_id || '' === $from || $from === $page || '' === $text ) {
			return null;
		}
		return self::comment_envelope( self::P_FB, $page, $from, (string) ( $v['from']['name'] ?? '' ), (string) ( $v['post_id'] ?? '' ), $comment_id, $text, (int) ( $v['created_time'] ?? 0 ) );
	}

	private static function build_group( array $e ): ?array {
		$c    = is_array( $e['comment'] ?? null ) ? $e['comment'] : array();
		$from = (string) ( $c['from']['id'] ?? '' );
		$text = trim( (string) ( $c['message'] ?? '' ) );
		if ( '' === (string) $e['group_id'] || '' === (string) ( $c['id'] ?? '' ) || '' === $from || '' === $text || (string) ( $e['self_id'] ?? '' ) === $from ) {
			return null;
		}
		return self::comment_envelope( self::P_GROUP, (string) $e['group_id'], $from, (string) ( $c['from']['name'] ?? '' ), (string) ( $c['post_id'] ?? '' ), (string) $c['id'], $text, (int) ( $c['created_time'] ?? 0 ) );
	}

	private static function comment_envelope( string $platform, string $ref, string $uid, string $name, string $post_id, string $comment_id, string $text, int $ts ): array {
		$thread = $post_id . '#' . $comment_id;
		return self::envelope( $platform, $ref, $uid, $name, $thread, $comment_id, $text, $ts > 0 ? $ts : self::now(), array(
			'kind' => 'comment', 'window_24h_until' => null, 'comment_parent' => $thread,
		), array() );
	}

	private static function envelope( string $platform, string $ref, string $uid, string $name, string $thread, string $mid, string $text, int $ts, array $meta, array $history ): array {
		$contact = array( 'platform' => $platform, 'channel_ref' => $ref, 'platform_uid' => $uid );
		if ( '' !== $name ) {
			$contact['display_name'] = $name;
		}
		return array(
			'contract'      => self::CONTRACT_IN,
			'trace_id'      => (string) self::seam( 'trace_id', static function ( $platform, $ref, $mid ) {
				return 'CHTRACE' . strtoupper( substr( hash( 'sha256', $platform . '|' . $ref . '|' . $mid ), 0, 16 ) );
			}, array( $platform, $ref, $mid ) ),
			'tenant'        => array( 'key_id' => BizCity_FB_Hub_Client::key_id( false ) ),
			'contact'       => $contact,
			'channel'       => array( 'platform' => $platform, 'channel_ref' => $ref ),
			'thread_key'    => $thread,
			'message'       => array( 'id' => $mid, 'text' => $text, 'parts' => array( array( 'type' => 'text', 'text' => $text ) ), 'sent_at' => gmdate( 'Y-m-d\TH:i:s\Z', $ts ) ),
			'platform_meta' => $meta,
			'history'       => array_slice( array_values( $history ), -self::HISTORY_MAX ),
		);
	}

	/** Last customer name the old bot stored, if any. */
	public static function customer_name( $page, $psid ): string {
		if ( ! class_exists( 'BizCity_Facebook_Bot_Database' ) ) {
			return '';
		}
		$c = BizCity_Facebook_Bot_Database::instance()->get_customer( $psid, $page );
		return is_object( $c ) ? (string) ( $c->name ?? '' ) : '';
	}

	/** History from the old bot's inbox table (cheap, indexed by client). */
	public static function history( $page, $psid ): array {
		if ( ! class_exists( 'BizCity_Facebook_Bot_Database' ) ) {
			return array();
		}
		$out = array();
		foreach ( (array) BizCity_Facebook_Bot_Database::instance()->get_inbox_messages( (string) $psid, (string) $page, 40 ) as $row ) {
			$text = trim( (string) ( $row->message_text ?? '' ) );
			if ( '' !== $text ) {
				$out[] = array( 'role' => 'client' === ( $row->sender_type ?? '' ) ? 'user' : 'assistant', 'text' => $text );
			}
		}
		return $out;
	}

	/* ---------------- A2 ---------------- */

	/** The contact is NEVER attached to a WP user here: only a verified link may do that (R-CID-6). */
	public static function bind_identity( array $inbound ) {
		$c = $inbound['contact'];
		return self::seam( 'bind', static function ( $c ) {
			if ( ! class_exists( 'BizCity_Identity_Hub' ) ) {
				return new WP_Error( 'identity_hub_missing', 'Chưa có Identity Hub.' );
			}
			$meta = isset( $c['display_name'] ) ? array( 'display_label' => $c['display_name'] ) : array();
			return BizCity_Identity_Hub::bind( $c['platform'], $c['channel_ref'], $c['platform_uid'], 0, 0, true, $meta );
		}, array( $c ) );
	}

	/* ---------------- A3 ---------------- */

	/**
	 * @param array  $out        channel-outbound@1.0.0
	 * @param string $page_token page (or group) access token
	 * @return array {sent, reason, mid?, handoff?, task_created?, error?}
	 */
	public static function send( array $out, string $page_token ): array {
		if ( 'reply' !== (string) ( $out['status'] ?? '' ) ) {
			return array( 'sent' => false, 'reason' => 'not_reply' );
		}
		$to       = (array) ( $out['to_contact'] ?? array() );
		$platform = (string) ( $to['platform'] ?? '' );
		$page     = (string) ( $to['channel_ref'] ?? '' );
		$thread   = (string) ( $out['thread_key'] ?? '' );
		$texts    = array();
		foreach ( (array) ( $out['parts'] ?? array() ) as $part ) {
			if ( 'text' === ( $part['type'] ?? '' ) && '' !== trim( (string) ( $part['text'] ?? '' ) ) ) {
				$texts[] = mb_substr( (string) $part['text'], 0, 2000 );
			}
		}
		if ( ! $texts ) {
			return array( 'sent' => false, 'reason' => 'no_text_parts' );
		}
		if ( '' === $page_token ) {
			return array( 'sent' => false, 'reason' => 'no_token' );
		}
		$cons  = (array) ( $out['constraints'] ?? array() );
		$until = ! empty( $cons['window_24h_until'] ) ? (int) strtotime( (string) $cons['window_24h_until'] ) : 0;
		$mid   = '';
		if ( self::P_MESSENGER === $platform ) {
			$uid = (string) ( $to['platform_uid'] ?? '' );
			if ( '' === $uid ) {
				return array( 'sent' => false, 'reason' => 'bad_recipient' );
			}
			if ( $until > 0 && self::now() > $until ) {
				return self::handoff( $out, 'window_closed' ); // outside 24 h: never send, a person follows up
			}
			foreach ( $texts as $text ) {
				$r = self::graph( 'POST', '/me/messages', array( 'recipient' => array( 'id' => $uid ), 'messaging_type' => 'RESPONSE', 'message' => array( 'text' => $text ) ), $page_token );
				if ( ! $r['ok'] ) {
					return in_array( (int) $r['error']['code'], array( 10 ), true ) || 2018278 === (int) $r['error']['subcode']
						? self::handoff( $out, 'window_closed' )
						: array( 'sent' => false, 'reason' => 'graph_error', 'error' => $r['error'] );
				}
				$mid = (string) ( $r['data']['message_id'] ?? $mid );
				self::seam( 'crm_outbound', array( __CLASS__, 'crm_outbound' ), array( $page, $uid, $text, $mid ) );
			}
		} elseif ( self::P_FB === $platform || ( self::P_GROUP === $platform && class_exists( 'BizCity_FB_Group_Adapter' ) && BizCity_FB_Group_Adapter::enabled() ) ) {
			$pos        = strpos( $thread, '#' );
			$comment_id = false === $pos ? '' : substr( $thread, $pos + 1 );
			if ( '' === $comment_id ) {
				return array( 'sent' => false, 'reason' => 'bad_thread' );
			}
			foreach ( $texts as $text ) {
				$r = self::graph( 'POST', '/' . rawurlencode( $comment_id ) . '/comments', array( 'message' => $text ), $page_token );
				if ( ! $r['ok'] ) {
					return array( 'sent' => false, 'reason' => 'graph_error', 'error' => $r['error'] );
				}
				$mid = (string) ( $r['data']['id'] ?? $mid );
			}
		} else {
			return array( 'sent' => false, 'reason' => self::P_GROUP === $platform ? 'groups_disabled' : 'unsupported_platform' );
		}
		if ( ! empty( $cons['needs_human'] ) ) {
			self::handoff( $out, 'needs_human_flag' ); // sent, and a person still follows up
		}
		return array( 'sent' => true, 'reason' => 'sent', 'mid' => $mid );
	}

	/**
	 * The one Graph seam. @return array {ok, status, data, error{code,subcode,message}|null}
	 */
	public static function graph( string $method, string $path, array $params, string $token ): array {
		if ( isset( self::$seams['graph'] ) ) {
			return call_user_func( self::$seams['graph'], $method, $path, $params, $token );
		}
		$url  = self::GRAPH . $path . ( false === strpos( $path, '?' ) ? '?' : '&' ) . 'access_token=' . rawurlencode( $token );
		$args = array( 'method' => $method, 'timeout' => 20 );
		if ( 'GET' !== $method ) {
			$args['headers'] = array( 'Content-Type' => 'application/json' );
			$args['body']    = wp_json_encode( $params );
		} elseif ( $params ) {
			$url .= '&' . http_build_query( $params );
		}
		$resp = wp_remote_request( $url, $args );
		if ( is_wp_error( $resp ) ) {
			return array( 'ok' => false, 'status' => 0, 'data' => array(), 'error' => array( 'code' => 0, 'subcode' => 0, 'message' => $resp->get_error_message() ) );
		}
		$http = (int) wp_remote_retrieve_response_code( $resp );
		$data = json_decode( (string) wp_remote_retrieve_body( $resp ), true );
		$data = is_array( $data ) ? $data : array();
		if ( $http >= 400 || isset( $data['error'] ) ) {
			$e = is_array( $data['error'] ?? null ) ? $data['error'] : array();
			return array( 'ok' => false, 'status' => $http, 'data' => array(), 'error' => array( 'code' => (int) ( $e['code'] ?? 0 ), 'subcode' => (int) ( $e['error_subcode'] ?? 0 ), 'message' => (string) ( $e['message'] ?? 'HTTP ' . $http ) ) );
		}
		return array( 'ok' => true, 'status' => $http, 'data' => $data, 'error' => null );
	}

	/** Outside the window / a flagged turn: never send, give the person in charge a CRM task (or a recorded needs_human row). */
	private static function handoff( array $out, string $why ): array {
		$to   = (array) ( $out['to_contact'] ?? array() );
		$page = (string) ( $to['channel_ref'] ?? '' );
		$info = array( 'page_id' => $page, 'platform' => (string) ( $to['platform'] ?? '' ), 'thread_key' => (string) ( $out['thread_key'] ?? '' ), 'reason' => $why, 'owner_user_id' => self::owner_for_page( $page ), 'at' => self::now() );
		$task = self::seam( 'handoff', static function ( $info ) {
			if ( $info['owner_user_id'] <= 0 || ! class_exists( 'BizCity_CRM_Task_Handoff' ) ) {
				return null;
			}
			return BizCity_CRM_Task_Handoff::create( $info['owner_user_id'], array(
				'assignee_user_id' => $info['owner_user_id'],
				'title'            => 'Khách Facebook cần người trả lời',
				'instructions'     => 'Khách nhắn ngoài cửa sổ 24 giờ của Messenger (hoặc bot đánh dấu cần người). Mở CRM Inbox, kênh Facebook, trả lời tay.',
				'priority'         => 'high',
			) );
		}, array( $info ) );
		$created = is_array( $task ) && ! empty( $task['created'] );
		if ( ! $created ) {
			$log   = (array) get_option( self::OPT_HUMAN, array() );
			$log[] = $info;
			update_option( self::OPT_HUMAN, array_slice( $log, -50 ), false );
		}
		do_action( 'bizcity_fb_needs_human', $info + array( 'task_created' => $created ) );
		return array( 'sent' => false, 'handoff' => true, 'reason' => $why, 'task_created' => $created );
	}

	/* ---------------- A4 (reuse the CRM ingestors; never a second writer) ---------------- */

	private static function crm_inbound( array $in, array $event ): void {
		self::seam( 'crm_inbound', static function ( $in, $event ) {
			if ( ! class_exists( 'BizCity_CRM_Facebook_Ingestor' ) ) {
				return;
			}
			$c   = $in['contact'];
			$raw = array( 'page_id' => $c['channel_ref'], 'user_id' => $c['platform_uid'], 'message' => $in['message']['text'], 'user_name' => $c['display_name'] ?? '' );
			if ( 'comment' === $in['platform_meta']['kind'] ) {
				$v   = (array) ( $event['change']['value'] ?? array() );
				$raw += array( 'comment_id' => (string) ( $v['comment_id'] ?? '' ), 'post_id' => (string) ( $v['post_id'] ?? '' ), 'from_id' => $c['platform_uid'] );
			} else {
				$raw += array( 'event' => array( 'message' => array( 'mid' => $in['message']['id'] ) ), 'timestamp' => (int) ( $event['messaging']['timestamp'] ?? 0 ) );
			}
			BizCity_CRM_Facebook_Ingestor::instance()->on_normalized( array( 'platform' => 'comment' === $in['platform_meta']['kind'] ? 'FB_FEED' : 'FB_MESS', 'account_id' => $c['channel_ref'], 'user_id' => $c['platform_uid'], 'message' => $in['message']['text'], 'raw' => $raw ) );
		}, array( $in, $event ) );
	}

	public static function crm_outbound( $page, $uid, $text, $mid ): void {
		if ( class_exists( 'BizCity_CRM_Facebook_Ingestor' ) ) {
			BizCity_CRM_Facebook_Ingestor::on_gateway_outbound( array( 'sent' => true, 'platform' => 'FB_MESS', 'chat_id' => 'fb_' . $page . '_' . $uid, 'message' => $text, 'extra' => array( 'mid' => $mid, 'source' => 'fb-channel-adapter' ) ) );
		}
	}

	/** Keep the old bot's inbox table (its history source) in step while the cell answers. */
	public static function inbox_save( array $in, array $out ): void {
		if ( ! class_exists( 'BizCity_Facebook_Bot_Database' ) || 'message' !== $in['platform_meta']['kind'] ) {
			return;
		}
		$db = BizCity_Facebook_Bot_Database::instance();
		$c  = $in['contact'];
		$db->save_inbox_message( array( 'bot_id' => 0, 'client_id' => $c['platform_uid'], 'client_name' => $c['display_name'] ?? '', 'page_id' => $c['channel_ref'], 'message_id' => $in['message']['id'], 'message_text' => $in['message']['text'], 'message_type' => 'text', 'sender_type' => 'client' ) );
		foreach ( (array) $out['parts'] as $part ) {
			$db->save_inbox_message( array( 'bot_id' => 0, 'client_id' => $c['platform_uid'], 'page_id' => $c['channel_ref'], 'message_id' => '', 'message_text' => (string) ( $part['text'] ?? '' ), 'message_type' => 'text', 'sender_type' => 'bot' ) );
		}
	}

	/** Post to a page feed (fb.post.create). @return array {ok, post_id?, permalink?, error?} */
	public static function publish_post( string $page_id, string $message, string $token ): array {
		$r = self::graph( 'POST', '/' . rawurlencode( $page_id ) . '/feed', array( 'message' => $message ), $token );
		if ( ! $r['ok'] ) {
			return array( 'ok' => false, 'error' => $r['error'] );
		}
		$id = (string) ( $r['data']['id'] ?? $r['data']['post_id'] ?? '' );
		return array( 'ok' => '' !== $id, 'post_id' => $id, 'permalink' => false !== strpos( $id, '_' ) ? 'https://www.facebook.com/' . str_replace( '_', '/posts/', $id ) : '' );
	}
}
