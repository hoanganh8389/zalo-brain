<?php
/**
 * BizCity_Journal_Send_Zalo — "Gửi về Zalo" of the web Find panel (PHASE-0.145 S145-U6, doc 30 §4, D145-07).
 *
 * POST bizcity-knowledge/v2/journal/send-to-zalo {refs: TL-…[@vN] ≤ 10, account_ref?}
 * The logged-in person sends ORIGINAL files of their own register to THEIR OWN chat with their assistant number (never to anyone else: sending to
 * another person stays the outreach path + JB-, BS-5). The web click + the panel's confirm dialog is the one confirmation (R-ONE-ASK).
 *   1. who: the person's own number (owner ⇒ the binding's owner_uid; staff ⇒ their own row's zalo_uid) — never a recipient from the browser;
 *   2. each ref through BizCity_KG_Private_File_Store::mcp_link (brain-access decide file.get, the asked version, 15-minute signed URL, sha256);
 *   3. ONE outbound to the cell (`purpose: owner_files` ⇒ the cell does not pause the bot on that thread); per-file receipt {ref, version_ref, sha256, status}.
 * A file the person may not read is "not found" (never says it exists). Nothing is written to the register here (the cell's outbound history keeps the send).
 *
 * Biz Central Brain — Johnny Chu (Chu Hoàng Anh).
 *
 * [2026-10-27 11:20 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.145 S145-U6 — new file.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\KG_Hub
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Journal_Send_Zalo', false ) ) {
	return;
}

final class BizCity_Journal_Send_Zalo {

	const NS  = 'bizcity-knowledge/v2';
	const MAX = 10;

	/** Test seams: accounts() · policy(bid) · link(code, ctx) · outbound(bid, recipient, text, attachments, idem) · account_ref(bid) · now() */
	public static $readers = null;

	public static function register(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes(): void {
		register_rest_route( self::NS, '/journal/send-to-zalo', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'rest' ),
			'permission_callback' => static function () { return is_user_logged_in(); },
		) );
	}

	/**
	 * Pure: the refs asked (TL-n or TL-n@vN, deduplicated, order kept). WP_Error when none or more than MAX.
	 *
	 * @return string[]|WP_Error
	 */
	public static function codes_of( $refs ) {
		$out = array();
		foreach ( (array) $refs as $r ) {
			$r = strtoupper( trim( (string) ( is_scalar( $r ) ? $r : '' ) ) );
			$r = preg_replace( '/@V(\d+)$/', '@v$1', $r );
			if ( 1 === preg_match( '/^TL-[1-9][0-9]{0,11}(@v[1-9][0-9]{0,3})?$/', (string) $r ) && ! in_array( $r, $out, true ) ) {
				$out[] = $r;
			}
		}
		if ( ! $out ) {
			return new WP_Error( 'refs_required', 'Chưa chọn tệp nào (mã có dạng TL-123).', array( 'status' => 400 ) );
		}
		if ( count( $out ) > self::MAX ) {
			return new WP_Error( 'too_many', 'Mỗi đợt gửi tối đa ' . self::MAX . ' tệp — chọn bớt rồi gửi tiếp đợt sau.', array( 'status' => 400, 'max' => self::MAX ) );
		}
		return $out;
	}

	/**
	 * The person's own chat with an assistant number: owned numbers first (owner_uid of the binding), then numbers where they are staff (their row's
	 * zalo_uid). `$account_ref` (acct:xxxx, same hash as the Gateway survey) picks one; '' ⇒ the first. null ⇒ the person has no reachable chat.
	 *
	 * @return array{bridge_id:string, recipient:string, role:string}|null
	 */
	public static function target( int $uid, string $account_ref ): ?array {
		if ( $uid <= 0 ) {
			return null;
		}
		$found = array();
		foreach ( self::accounts() as $a ) {
			$bid = (string) ( $a['bridge_id'] ?? '' );
			if ( '' === $bid ) {
				continue;
			}
			$policy = self::policy( $bid );
			if ( (int) ( $a['owner_user_id'] ?? 0 ) === $uid ) {
				$rcpt = self::clean_uid( (string) ( $policy['owner_uid'] ?? '' ) );
				if ( '' !== $rcpt ) {
					$found[] = array( 'bridge_id' => $bid, 'recipient' => $rcpt, 'role' => 'owner' );
				}
				continue;
			}
			$rows = class_exists( 'BizCity_Zalo_Agent_Principals' ) ? BizCity_Zalo_Agent_Principals::from_policy( $policy ) : (array) ( $policy['principals'] ?? array() );
			foreach ( (array) $rows as $row ) {
				if ( (int) ( $row['user_id'] ?? 0 ) === $uid && ! empty( $row['enabled'] ) ) {
					$rcpt = self::clean_uid( (string) ( $row['zalo_uid'] ?? '' ) );
					if ( '' !== $rcpt ) {
						$found[] = array( 'bridge_id' => $bid, 'recipient' => $rcpt, 'role' => 'staff' );
					}
				}
			}
		}
		if ( '' !== $account_ref ) {
			foreach ( $found as $f ) {
				if ( self::account_ref( $f['bridge_id'] ) === $account_ref ) {
					return $f;
				}
			}
			return null;
		}
		return $found[0] ?? null;
	}

	/** @return array|WP_Error */
	public static function send( array $in, int $uid ) {
		$codes = self::codes_of( $in['refs'] ?? array() );
		if ( is_wp_error( $codes ) ) {
			return $codes;
		}
		$target = self::target( $uid, (string) ( $in['account_ref'] ?? '' ) );
		if ( null === $target ) {
			return new WP_Error( 'no_zalo_chat', 'Tài khoản này chưa có chat Zalo với trợ lý (chưa là chủ hoặc nhân sự của số nào).', array( 'status' => 409, 'hint' => 'Kết nối số Zalo ở Channel Gateway hoặc nhờ chủ số thêm anh/chị vào danh sách nhân sự.' ) );
		}
		$ctx         = array( 'owner_user_id' => $uid, 'user_id' => $uid, 'role' => 'owner' === $target['role'] ? 'owner' : 'staff', 'account_id' => $target['bridge_id'] );
		$attachments = array();
		$items       = array();
		foreach ( $codes as $code ) {
			$l = self::link( $code, $ctx );
			if ( is_wp_error( $l ) || ! is_array( $l ) || '' === (string) ( $l['url'] ?? '' ) ) {
				$items[] = array( 'ref' => $code, 'status' => 'failed', 'error' => is_wp_error( $l ) ? $l->get_error_code() : 'not_found' );
				continue;
			}
			$attachments[] = array( 'url' => (string) $l['url'], 'name' => (string) ( $l['file_name'] ?? $code ) );
			$items[]       = array( 'ref' => $code, 'version_ref' => (string) ( $l['version_ref'] ?? '' ), 'sha256' => (string) ( $l['sha256'] ?? '' ), 'name' => (string) ( $l['file_name'] ?? '' ), 'status' => 'queued' );
		}
		if ( ! $attachments ) {
			return new WP_Error( 'nothing_sendable', 'Không gửi được tệp nào: không tìm thấy hoặc không có bản gốc.', array( 'status' => 404, 'items' => $items ) );
		}
		$n    = count( $attachments );
		$idem = 's145-send-' . substr( hash( 'sha256', $uid . '|' . $target['bridge_id'] . '|' . implode( ',', $codes ) . '|' . (int) floor( self::now() / 60 ) ), 0, 24 );
		$res  = self::outbound( $target['bridge_id'], $target['recipient'], '📎 ' . $n . ' tệp gốc từ Kho tài liệu', $attachments, $idem );
		$ok   = ! empty( $res['ok'] ) || ! empty( $res['success'] );
		foreach ( $items as $i => $it ) {
			if ( 'queued' === $it['status'] ) {
				$items[ $i ]['status'] = $ok ? 'sent' : 'failed';
				if ( ! $ok ) {
					$items[ $i ]['error'] = (string) ( $res['code'] ?? $res['error'] ?? 'send_failed' );
				}
			}
		}
		if ( ! $ok ) {
			// never "đã gửi" when the cell did not accept (K7)
			return new WP_Error( 'send_failed', 'Chưa gửi được về Zalo (số Zalo đang tắt hoặc mất kết nối).', array( 'status' => 502, 'items' => $items, 'hint' => 'Kiểm tra số ở Channel Gateway rồi bấm gửi lại.' ) );
		}
		return array( 'ok' => true, 'contract' => 'send-documents@1', 'sent' => count( array_filter( $items, static function ( $x ) { return 'sent' === $x['status']; } ) ), 'failed' => count( array_filter( $items, static function ( $x ) { return 'failed' === $x['status']; } ) ), 'items' => $items );
	}

	public static function rest( WP_REST_Request $request ): WP_REST_Response {
		$p   = (array) $request->get_json_params();
		$res = self::send( array( 'refs' => $p['refs'] ?? array(), 'account_ref' => is_scalar( $p['account_ref'] ?? null ) ? (string) $p['account_ref'] : '' ), (int) get_current_user_id() );
		if ( is_wp_error( $res ) ) {
			$d = (array) $res->get_error_data();
			return new WP_REST_Response( array( 'ok' => false, 'code' => $res->get_error_code(), 'message' => $res->get_error_message(), 'hint' => (string) ( $d['hint'] ?? '' ), 'items' => $d['items'] ?? array(), 'help_code' => 'S145-U6' ), (int) ( $d['status'] ?? 400 ) );
		}
		return new WP_REST_Response( $res, 200 );
	}

	// ── seams ────────────────────────────────────────────────────────────

	private static function accounts(): array {
		if ( isset( self::$readers['accounts'] ) ) {
			return (array) call_user_func( self::$readers['accounts'] );
		}
		return class_exists( 'BizCity_Zalo_Hub_Config_Sync' ) ? (array) BizCity_Zalo_Hub_Config_Sync::accounts() : array();
	}

	private static function policy( string $bid ): array {
		if ( isset( self::$readers['policy'] ) ) {
			return (array) call_user_func( self::$readers['policy'], $bid );
		}
		return class_exists( 'BizCity_Zalo_Agent_Principals' ) ? (array) BizCity_Zalo_Agent_Principals::policy( $bid ) : array();
	}

	private static function link( string $code, array $ctx ) {
		if ( isset( self::$readers['link'] ) ) {
			return call_user_func( self::$readers['link'], $code, $ctx );
		}
		return class_exists( 'BizCity_KG_Private_File_Store' ) ? BizCity_KG_Private_File_Store::mcp_link( array( 'doc_code' => $code ), $ctx ) : new WP_Error( 'store_off', 'Kho file chưa bật.' );
	}

	private static function outbound( string $bid, string $recipient, string $text, array $attachments, string $idem ): array {
		if ( isset( self::$readers['outbound'] ) ) {
			return (array) call_user_func( self::$readers['outbound'], $bid, $recipient, $text, $attachments, $idem );
		}
		if ( ! class_exists( 'BizCity_Zalo_Personal_Hub_Client' ) ) {
			return array( 'ok' => false, 'code' => 'hub_client_missing' );
		}
		return (array) BizCity_Zalo_Personal_Hub_Client::instance()->enqueue_outbound( $bid, $recipient, $text, 'file', $attachments, 'user', array(), $idem, array(), array( 'purpose' => 'owner_files' ) );
	}

	public static function account_ref( string $bid ): string {
		if ( isset( self::$readers['account_ref'] ) ) {
			return (string) call_user_func( self::$readers['account_ref'], $bid );
		}
		return class_exists( 'BizCity_CG_Guide_Survey' ) ? BizCity_CG_Guide_Survey::account_ref( $bid ) : 'acct:' . substr( hash( 'sha256', $bid ), 0, 4 );
	}

	private static function now(): int {
		return isset( self::$readers['now'] ) ? (int) call_user_func( self::$readers['now'] ) : time();
	}

	private static function clean_uid( string $uid ): string {
		$uid = trim( $uid );
		return 1 === preg_match( '/^\d{5,32}$/', $uid ) ? $uid : '';
	}
}
