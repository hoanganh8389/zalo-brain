<?php
/**
 * Zalo OA as an adapter of the cell brain (PHASE-0.93 gap A, R-TWIN-AGENT-AXIS: one runAgentTurn for every channel).
 *
 * Same four slots as BizCity_FB_Channel_Adapter (the cell already declares platform `zalo_oa`, direction external, role
 * always `customer`; the Hub already relays channel-inbound@1 for it — nothing changes in the cell or the Hub):
 *   A1  webhook event ⇒ channel-inbound@1.0.0 (contact = oa_id + OA user id; the body carries no role / tenant_id / person_id)
 *   A2  Identity_Hub::bind (never attached to a WP user: only a verified link may, R-CID-6)
 *   A3  POST zalo-hub/channel/inbound ⇒ channel-outbound@1.0.0 ⇒ the OA send API (BizCity_Zalo_OA_Integration::send_outbound)
 *   A4  CRM Inbox rows for the customer message and the answer (reuse BizCity_CRM_Facebook_Ingestor, never a second writer)
 *
 * Who goes through the cell: mode `auto` (default) when the OA is registered at the Hub (the "Kết nối" flow registers it) AND its
 * Agent Guru binding answers automatically (auto | hybrid). Everything else keeps the old Guru path unchanged (manual binding =
 * a person answers in the CRM; follow / image / non-text events; any Hub / cell failure = skip ⇒ the old path runs).
 * Way back: option `bizcity_zalo_oa_adapter` = `legacy`.
 *
 * Zalo's customer-service message only works within 48 h of the customer's last message: a refused send is never retried or
 * faked — the person in charge gets a CRM task (needs-human), exactly like Messenger outside its window.
 *
 * [2026-10-07 09:10 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.93 gap A — new file.
 *
 * @package BizCity_Twin_AI
 * @subpackage Channel_Gateway
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Zalo_OA_Channel_Adapter', false ) ) {
	return;
}

final class BizCity_Zalo_OA_Channel_Adapter {

	const CONTRACT_IN = 'channel-inbound@1.0.0';
	const FLAG        = 'bizcity_zalo_oa_adapter';
	const PLATFORM    = 'zalo_oa';
	const PATH_INBOUND = '/zalo-hub/channel/inbound';
	const OPT_HUMAN   = 'bizcity_zalo_oa_needs_human';
	const KEY_ID_OPTION = 'bizcity_twin_web_turn_key_id';
	const HISTORY_MAX = 20;

	/** @var array<string,callable> Tests: ready, key_id, transport(method,path,payload), send(account,uid,text), crm_inbound, crm_outbound, handoff, bind, binding(oa), registered(oa), now. */
	public static $seams = array();

	private static function seam( string $name, callable $default, array $args = array() ) {
		return call_user_func_array( isset( self::$seams[ $name ] ) ? self::$seams[ $name ] : $default, $args );
	}

	private static function now(): int {
		return isset( self::$seams['now'] ) ? (int) call_user_func( self::$seams['now'] ) : time();
	}

	/** 'cell' | 'legacy' — the documented way back is `legacy`. */
	public static function mode(): string {
		return 'legacy' === get_option( self::FLAG, 'auto' ) ? 'legacy' : 'cell';
	}

	/** Does an inbound event of this OA go through the cell? (mode + Hub registration + a binding that answers by itself) */
	public static function wants_cell( string $oa_id ): bool {
		if ( 'cell' !== self::mode() || '' === $oa_id ) {
			return false;
		}
		$registered = (bool) self::seam( 'registered', static function ( $oa ) {
			return class_exists( 'BizCity_Connect_REST' ) && BizCity_Connect_REST::is_registered( 'zalo_oa', $oa );
		}, array( $oa_id ) );
		if ( ! $registered ) {
			return false;
		}
		$binding = self::seam( 'binding', static function ( $oa ) {
			return class_exists( 'BizCity_Channel_Binding' ) ? BizCity_Channel_Binding::resolve( 'ZALO_OA', $oa ) : null;
		}, array( $oa_id ) );
		return is_array( $binding ) && (int) ( $binding['character_id'] ?? 0 ) > 0 && in_array( (string) ( $binding['mode'] ?? 'auto' ), array( 'auto', 'hybrid' ), true );
	}

	/* ---------------- webhook entry (one guarded call from class-channel-rest-api.php) ---------------- */

	/**
	 * @param array $envelope normalized webhook envelope {instance_id (oa_id), sender_id, text, type, mid, raw, timestamp}
	 * @param array $account  decrypted OA account (access_token …)
	 * @return bool true = the cell answered and the answer was sent (or handed to a person): the old Guru path must NOT run.
	 */
	public static function handle( array $envelope, array $account ): bool {
		try {
			$oa = (string) ( $envelope['instance_id'] ?? '' );
			if ( ! self::wants_cell( $oa ) ) {
				return false;
			}
			$in = self::build_inbound( $envelope );
			if ( null === $in ) {
				return false;
			}
			$claim = 'bizcity_zalo_oa_adapter_claim_' . md5( $oa . '|' . $in['message']['id'] );
			if ( get_transient( $claim ) ) {
				return true; // a duplicate delivery of an event this adapter already took
			}
			set_transient( $claim, 1, 600 );
			self::seam( 'crm_inbound', array( __CLASS__, 'crm_inbound' ), array( $in, $envelope ) ); // a CRM row exists whatever happens next
			if ( ! self::ready() ) {
				return false;
			}
			if ( $in['tenant']['key_id'] <= 0 ) {
				$in['tenant']['key_id'] = self::key_id();
				if ( $in['tenant']['key_id'] <= 0 ) {
					return false;
				}
			}
			self::bind_identity( $in );
			$res = self::inbound( $in );
			if ( ! $res['ok'] || 'reply' !== (string) ( $res['data']['status'] ?? '' ) ) {
				return false; // skip / error ⇒ the old path answers (or a person does)
			}
			$sent = self::send( $res['data'], $account );
			return ! empty( $sent['sent'] ) || ! empty( $sent['handoff'] );
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/* ---------------- A1 ---------------- */

	/** @return array|null channel-inbound@1.0.0 body, or null when the event is not a customer text message (follow, image, OA echo …). */
	public static function build_inbound( array $envelope ): ?array {
		$oa   = preg_replace( '/[^0-9]/', '', (string) ( $envelope['instance_id'] ?? '' ) );
		$uid  = preg_replace( '/[^0-9]/', '', (string) ( $envelope['sender_id'] ?? '' ) );
		$text = trim( (string) ( $envelope['text'] ?? '' ) );
		$type = (string) ( $envelope['type'] ?? '' );
		$raw  = is_array( $envelope['raw'] ?? null ) ? $envelope['raw'] : array();
		$ev   = (string) ( $raw['event_name'] ?? '' );
		if ( '' === $oa || '' === $uid || $uid === $oa || '' === $text || 'text' !== $type || ( '' !== $ev && 'user_send_text' !== $ev ) ) {
			return null;
		}
		$ts   = (int) ( $envelope['timestamp'] ?? 0 );
		$ts   = $ts > 9999999999 ? (int) ( $ts / 1000 ) : $ts; // Zalo sends milliseconds
		$ts   = $ts > 0 ? $ts : self::now();
		$mid  = (string) ( $envelope['mid'] ?? '' );
		$mid  = '' !== $mid ? $mid : 'zoasim:' . $oa . ':' . $uid . ':' . $ts;
		$name = (string) ( $envelope['sender_name'] ?? ( $raw['sender']['display_name'] ?? '' ) );
		$contact = array( 'platform' => self::PLATFORM, 'channel_ref' => $oa, 'platform_uid' => $uid );
		if ( '' !== $name ) {
			$contact['display_name'] = $name;
		}
		return array(
			'contract'      => self::CONTRACT_IN,
			'trace_id'      => 'CHTRACE' . strtoupper( substr( hash( 'sha256', self::PLATFORM . '|' . $oa . '|' . $mid ), 0, 16 ) ),
			'tenant'        => array( 'key_id' => self::key_id() ),
			'contact'       => $contact,
			'channel'       => array( 'platform' => self::PLATFORM, 'channel_ref' => $oa ),
			'thread_key'    => $uid,
			'message'       => array( 'id' => $mid, 'text' => $text, 'parts' => array( array( 'type' => 'text', 'text' => $text ) ), 'sent_at' => gmdate( 'Y-m-d\TH:i:s\Z', $ts ) ),
			'platform_meta' => array( 'kind' => 'message', 'window_24h_until' => null, 'comment_parent' => null ),
			'history'       => array_slice( array_values( (array) self::seam( 'history', array( __CLASS__, 'history' ), array( $oa, $uid ) ) ), -self::HISTORY_MAX ),
		);
	}

	/** Last messages of this customer from the site's own channel log (cheap, bounded). */
	public static function history( $oa, $uid ): array {
		if ( ! class_exists( 'BizCity_Channel_Messages' ) ) {
			return array();
		}
		$out  = array();
		$rows = (array) BizCity_Channel_Messages::query( array( 'platform' => 'ZALO_OA', 'chat_id' => 'zalooa_' . $oa . '_' . $uid, 'limit' => self::HISTORY_MAX ) );
		foreach ( array_reverse( $rows ) as $row ) { // query() is newest first
			$text = trim( (string) ( $row['body'] ?? '' ) );
			if ( '' !== $text ) {
				$out[] = array( 'role' => BizCity_Channel_Messages::DIR_OUTBOUND === (int) ( $row['direction'] ?? 0 ) ? 'assistant' : 'user', 'text' => mb_substr( $text, 0, 1000 ) );
			}
		}
		return $out;
	}

	/* ---------------- A2 ---------------- */

	public static function bind_identity( array $inbound ) {
		return self::seam( 'bind', static function ( $c ) {
			if ( ! class_exists( 'BizCity_Identity_Hub' ) ) {
				return new WP_Error( 'identity_hub_missing', 'Chưa có Identity Hub.' );
			}
			$meta = isset( $c['display_name'] ) ? array( 'display_label' => $c['display_name'] ) : array();
			return BizCity_Identity_Hub::bind( 'ZALO_OA', $c['channel_ref'], $c['platform_uid'], 0, 0, true, $meta );
		}, array( $inbound['contact'] ) );
	}

	/* ---------------- A3 ---------------- */

	public static function ready(): bool {
		if ( isset( self::$seams['ready'] ) ) {
			return (bool) call_user_func( self::$seams['ready'] );
		}
		return class_exists( 'BizCity_LLM_Client' ) && '' !== (string) BizCity_LLM_Client::instance()->get_api_key( false );
	}

	public static function key_id(): int {
		if ( isset( self::$seams['key_id'] ) ) {
			return (int) call_user_func( self::$seams['key_id'] );
		}
		$cached = (int) get_transient( self::KEY_ID_OPTION );
		if ( $cached > 0 || ! class_exists( 'BizCity_Zalo_Personal_Hub_Client' ) ) {
			return $cached;
		}
		$id = (int) ( BizCity_Zalo_Personal_Hub_Client::instance()->health()['key_id'] ?? 0 );
		if ( $id > 0 ) {
			set_transient( self::KEY_ID_OPTION, $id, 12 * HOUR_IN_SECONDS );
		}
		return $id;
	}

	/** @return array {ok, data} — never throws, no secret in it */
	public static function inbound( array $body ): array {
		$raw = isset( self::$seams['transport'] )
			? call_user_func( self::$seams['transport'], 'POST', self::PATH_INBOUND, $body )
			: BizCity_LLM_Client::instance()->gateway_post( self::PATH_INBOUND, $body, 25, false );
		if ( ! is_array( $raw ) ) {
			return array( 'ok' => false, 'data' => array() );
		}
		$bad = ! empty( $raw['_degraded'] ) || (int) ( $raw['http_code'] ?? 200 ) >= 400 || ( isset( $raw['code'] ) && ! isset( $raw['status'] ) );
		unset( $raw['_degraded'], $raw['http_code'] );
		return array( 'ok' => ! $bad, 'data' => $bad ? array() : $raw );
	}

	/**
	 * @param array $out     channel-outbound@1.0.0
	 * @param array $account decrypted OA account
	 * @return array {sent, reason, mid?, handoff?, task_created?, error?}
	 */
	public static function send( array $out, array $account ): array {
		if ( 'reply' !== (string) ( $out['status'] ?? '' ) ) {
			return array( 'sent' => false, 'reason' => 'not_reply' );
		}
		$to    = (array) ( $out['to_contact'] ?? array() );
		$oa    = (string) ( $to['channel_ref'] ?? '' );
		$uid   = (string) ( $to['platform_uid'] ?? '' );
		$texts = array();
		foreach ( (array) ( $out['parts'] ?? array() ) as $part ) {
			if ( 'text' === ( $part['type'] ?? '' ) && '' !== trim( (string) ( $part['text'] ?? '' ) ) ) {
				$texts[] = mb_substr( (string) $part['text'], 0, 2000 );
			}
		}
		if ( ! $texts ) {
			return array( 'sent' => false, 'reason' => 'no_text_parts' );
		}
		if ( '' === $uid || '' === $oa || self::PLATFORM !== (string) ( $to['platform'] ?? '' ) ) {
			return array( 'sent' => false, 'reason' => 'bad_recipient' );
		}
		$mid = '';
		foreach ( $texts as $text ) {
			$r = self::seam( 'send', array( __CLASS__, 'api_send' ), array( $account, $uid, $text ) );
			if ( is_wp_error( $r ) || empty( $r['sent'] ) ) {
				// Zalo refuses outside its customer-service window (or with a revoked grant): never retry, a person follows up
				return self::handoff( $out, is_wp_error( $r ) ? 'send_failed' : 'window_or_grant', is_array( $r ) ? (string) ( $r['code'] ?? '' ) : '' );
			}
			$mid = (string) ( $r['mid'] ?? $mid );
			self::seam( 'crm_outbound', array( __CLASS__, 'crm_outbound' ), array( $oa, $uid, $text, $mid ) );
		}
		if ( ! empty( ( (array) ( $out['constraints'] ?? array() ) )['needs_human'] ) ) {
			self::handoff( $out, 'needs_human_flag' ); // sent, and a person still follows up
		}
		return array( 'sent' => true, 'reason' => 'sent', 'mid' => $mid );
	}

	/** The one OA send seam: the registry's zalo_oa integration with this account loaded. */
	public static function api_send( array $account, string $uid, string $text ) {
		if ( ! class_exists( 'BizCity_Integration_Registry' ) ) {
			return new WP_Error( 'registry_missing', 'Chưa có Integration Registry.' );
		}
		$integ = BizCity_Integration_Registry::instance()->get( 'zalo_oa' );
		if ( ! $integ ) {
			return new WP_Error( 'integration_missing', 'Chưa có kênh Zalo OA.' );
		}
		$one = clone $integ;
		$one->set_account( $account );
		return $one->send_outbound( array( 'recipient' => $uid, 'text' => $text, 'type' => 'text' ), $account );
	}

	private static function handoff( array $out, string $why, string $code = '' ): array {
		$to   = (array) ( $out['to_contact'] ?? array() );
		$oa   = (string) ( $to['channel_ref'] ?? '' );
		$info = array( 'oa_id' => $oa, 'platform' => self::PLATFORM, 'thread_key' => (string) ( $out['thread_key'] ?? '' ), 'reason' => $why, 'code' => $code, 'owner_user_id' => self::owner_for_oa( $oa ), 'at' => self::now() );
		$task = self::seam( 'handoff', static function ( $info ) {
			if ( $info['owner_user_id'] <= 0 || ! class_exists( 'BizCity_CRM_Task_Handoff' ) ) {
				return null;
			}
			return BizCity_CRM_Task_Handoff::create( $info['owner_user_id'], array(
				'assignee_user_id' => $info['owner_user_id'],
				'title'            => 'Khách Zalo OA cần người trả lời',
				'instructions'     => 'Zalo không cho trợ lý gửi tin (khách nhắn đã quá 48 giờ, hoặc quyền OA bị thu hồi), hoặc bot đánh dấu cần người. Mở CRM Inbox, kênh Zalo OA, trả lời tay.',
				'priority'         => 'high',
			) );
		}, array( $info ) );
		$created = is_array( $task ) && ! empty( $task['created'] );
		if ( ! $created ) {
			$log   = (array) get_option( self::OPT_HUMAN, array() );
			$log[] = $info;
			update_option( self::OPT_HUMAN, array_slice( $log, -50 ), false );
		}
		do_action( 'bizcity_zalo_oa_needs_human', $info + array( 'task_created' => $created ) );
		return array( 'sent' => false, 'handoff' => true, 'reason' => $why, 'task_created' => $created );
	}

	/** Owner of the OA = the person chosen at step ③ of "Kết nối". */
	public static function owner_for_oa( string $oa ): int {
		$o = get_option( 'bizcity_connect_owners', array() );
		return (int) ( $o['zalo_oa'][ $oa ] ?? 0 );
	}

	/* ---------------- A4 ---------------- */

	public static function crm_inbound( array $in, array $envelope ): void {
		if ( ! class_exists( 'BizCity_CRM_Facebook_Ingestor' ) ) {
			return;
		}
		$c   = $in['contact'];
		$raw = is_array( $envelope['raw'] ?? null ) ? $envelope['raw'] : array();
		BizCity_CRM_Facebook_Ingestor::instance()->on_workflow_trigger( 'bizcity_zalo_oa_message_received', array(
			'account_id'      => $c['channel_ref'],
			'account_name'    => (string) ( $envelope['account_name'] ?? $c['channel_ref'] ),
			'conversation_id' => $c['channel_ref'],
			'from_user_id'    => $c['platform_uid'],
			'from_user_name'  => (string) ( $c['display_name'] ?? '' ),
			'message_id'      => (string) $in['message']['id'],
			'message_text'    => (string) $in['message']['text'],
			'message_time'    => current_time( 'mysql' ),
			'event_name'      => 'user_send_text',
			'raw'             => $raw,
		) );
	}

	public static function crm_outbound( $oa, $uid, $text, $mid ): void {
		if ( ! class_exists( 'BizCity_CRM_Facebook_Ingestor' ) ) {
			return;
		}
		try {
			BizCity_CRM_Facebook_Ingestor::instance()->ingest_outbound( 'zalo_oa', array(
				'inbox_ref'          => (string) $oa,
				'inbox_name'         => 'Zalo OA ' . $oa,
				'source_id'          => (string) $uid,
				'contact_name'       => '',
				'content'            => (string) $text,
				'content_type'       => 'text',
				'external_source_id' => (string) $mid,
				'received_at'        => current_time( 'mysql' ),
				'sender_type'        => 'agent_bot',
				'thread_kind'        => '',
				'group_id'           => '',
				'ai_metadata'        => array( 'source' => 'zalo-oa-channel-adapter' ),
			) );
		} catch ( \Throwable $e ) {
			// the answer is already sent; a missing CRM mirror row must never turn into an error
		}
	}
}
