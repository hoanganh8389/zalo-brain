<?php
/**
 * BizCity_Scheduler_Hub_Pipe — the one site→cell pipe of the Scheduler: letters of `channel-inbound@1.x` on the internal
 * channel `scheduler`, posted to the Hub (`POST zalo-hub/channel/inbound`) with this blog's 1API key; the Hub relays body and
 * status to the cell unchanged. Shared by `job_result` (BizCity_Scheduler_Report_Back) and `job_upsert`
 * (BizCity_Scheduler_Cell_Dispatch). Moved out of Report_Back without behaviour change (PHASE-0.92 S92-SC-3).
 *
 * Every method takes the caller's `$readers` seam map (target / key_id / client_instance / http / now) so each caller keeps its
 * own test doubles.
 *
 * Biz Central Brain — Johnny Chu (Chu Hoàng Anh). Bizcity Central Brain, Giấy chứng nhận đăng ký quyền tác giả
 * số 8877/2026/QTG (Cục Bản quyền tác giả, 14/09/2026). R-BIZ-CENTRAL-BRAIN R-BCB-8.
 *
 * // [2026-10-06 11:15 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.92-S92-SC-3 — new file.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Scheduler
 * @since      2026-10-06
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

if ( class_exists( 'BizCity_Scheduler_Hub_Pipe' ) ) {
	return;
}

final class BizCity_Scheduler_Hub_Pipe {

	const PLATFORM      = 'scheduler';
	const PATH_INBOUND  = '/zalo-hub/channel/inbound';
	const PATH_REGISTER = '/zalo-hub/channel/register';
	const REG_OPTION    = 'bizcity_scheduler_report_channel';
	const KEY_ID_CACHE  = 'bizcity_scheduler_report_key_id';
	const CLIENT_OPTION = 'bizcity_zalo_client_instance_id'; // BizCity_Zalo_Personal_Hub_Client::CLIENT_INSTANCE_OPTION

	/** @return array{url:string,key:string,headers:array}|null Hub base (…/wp-json/bizcity/v1) + this blog's key. */
	public static function target( array $readers = array() ) {
		if ( isset( $readers['target'] ) ) {
			$t = call_user_func( $readers['target'] );
			return is_array( $t ) ? $t : null;
		}
		if ( ! class_exists( 'BizCity_Zalo_Personal_Hub_Client' ) || ! method_exists( 'BizCity_Zalo_Personal_Hub_Client', 'stream_target' ) ) {
			return null;
		}
		$t = BizCity_Zalo_Personal_Hub_Client::instance()->stream_target( '' );
		return is_array( $t ) && '' !== (string) ( $t['key'] ?? '' ) ? $t : null;
	}

	public static function key_id( array $readers = array() ): int {
		if ( isset( $readers['key_id'] ) ) {
			return (int) call_user_func( $readers['key_id'] );
		}
		$cached = function_exists( 'get_transient' ) ? (int) get_transient( self::KEY_ID_CACHE ) : 0;
		if ( $cached > 0 || ! class_exists( 'BizCity_Zalo_Personal_Hub_Client' ) ) {
			return $cached;
		}
		$h  = BizCity_Zalo_Personal_Hub_Client::instance()->health();
		$id = (int) ( $h['key_id'] ?? 0 );
		if ( $id > 0 && function_exists( 'set_transient' ) ) {
			set_transient( self::KEY_ID_CACHE, $id, 12 * 3600 );
		}
		return $id;
	}

	public static function client_instance( array $readers = array() ): string {
		if ( isset( $readers['client_instance'] ) ) {
			return (string) call_user_func( $readers['client_instance'] );
		}
		return function_exists( 'get_option' ) ? sanitize_key( (string) get_option( self::CLIENT_OPTION, '' ) ) : '';
	}

	/**
	 * Register the `scheduler` channel at the Hub once per site key (idempotent at the Hub; repeated on key change or after
	 * a 403). Never blocks the letter: a failure is remembered and retried with the next one.
	 */
	public static function ensure_registered( array $target, string $instance, array $readers = array() ): bool {
		$fp   = substr( hash( 'sha256', (string) $target['key'] . '|' . $instance ), 0, 16 );
		$seen = function_exists( 'get_option' ) ? get_option( self::REG_OPTION, array() ) : array();
		if ( is_array( $seen ) && ( $seen['fp'] ?? '' ) === $fp && ! empty( $seen['ok'] ) ) {
			return true;
		}
		$res = self::http( 'PUT', $target['url'] . self::PATH_REGISTER, $target, array( 'platform' => self::PLATFORM, 'channel_ref' => $instance ), $readers );
		$ok  = (int) $res['status'] >= 200 && (int) $res['status'] < 300;
		if ( function_exists( 'update_option' ) ) {
			update_option( self::REG_OPTION, array( 'fp' => $fp, 'ok' => $ok, 'http' => (int) $res['status'], 'at' => gmdate( 'c', self::now( $readers ) ) ), false );
		}
		return $ok;
	}

	public static function forget_registration(): void {
		if ( function_exists( 'delete_option' ) ) {
			delete_option( self::REG_OPTION );
		}
	}

	/** POST one letter to the Hub inbound route. @return array{status:int,body:mixed} status 0 = network error */
	public static function post_letter( array $target, array $letter, array $readers = array() ): array {
		return self::http( 'POST', $target['url'] . self::PATH_INBOUND, $target, $letter, $readers );
	}

	/** @return array{status:int,body:mixed} status 0 = network error */
	public static function http( string $method, string $url, array $target, array $body, array $readers = array() ): array {
		$headers = array_merge(
			(array) ( $target['headers'] ?? array() ),
			array( 'Authorization' => 'Bearer ' . (string) $target['key'], 'Content-Type' => 'application/json' )
		);
		$json = (string) wp_json_encode( $body );
		if ( isset( $readers['http'] ) ) {
			$r = call_user_func( $readers['http'], $method, $url, $headers, $json );
			return array( 'status' => (int) ( $r['status'] ?? 0 ), 'body' => $r['body'] ?? null );
		}
		if ( ! function_exists( 'wp_remote_request' ) ) {
			return array( 'status' => 0, 'body' => null );
		}
		$res = wp_remote_request( $url, array( 'method' => $method, 'headers' => $headers, 'body' => $json, 'timeout' => 10 ) );
		if ( is_wp_error( $res ) ) {
			return array( 'status' => 0, 'body' => null );
		}
		return array(
			'status' => (int) wp_remote_retrieve_response_code( $res ),
			'body'   => json_decode( (string) wp_remote_retrieve_body( $res ), true ),
		);
	}

	public static function now( array $readers = array() ): int {
		return isset( $readers['now'] ) ? (int) call_user_func( $readers['now'] ) : time();
	}
}
