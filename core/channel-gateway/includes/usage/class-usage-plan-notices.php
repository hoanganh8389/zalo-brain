<?php
/**
 * Zalo 1-1 messages to the OWNER of a number about a plan order (PHASE-0.108 S108-L22.1, 20-REVENUE-PER-NUMBER.md §3.4, 24 K12):
 *   - the pay link of an order the admin made on the customer's behalf (screen ③ step 4),
 *   - the receipt "Đã nhận … gói Pro … gia hạn tới …" the moment the paid order was applied (screen ⑥ step 4).
 * Same send path as BizCity_Usage_Plan_Enforce (the number's own Zalo transport, to the owner uid); every message carries an idempotency key, so a retry
 * or a repeated hook never sends twice. Never throws, never blocks the sale: a number with no owner uid or no transport is logged and skipped.
 * The 1-per-day rule of the reminder (Enforce) does NOT apply to these two: both are an answer to something the customer or the admin just did.
 * Texts never contain a customer name, only the masked number.
 *
 * Test seams ($readers): owner_uid(account) · send(account, uid, text, idem): bool · label(account) · tz() · log(msg, ctx) · enabled(): bool
 *
 * // [2026-10-13 09:14 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.108 S108-L22.1.
 *
 * @package BizCity_Twin_AI
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_Usage_Plan_Notices {

	const OPT_RECEIPT = 'bizcity_plan_receipt_notice'; // 'no' switches the receipt off

	/** @var array<string,callable> */
	public static $readers = array();

	private static $initialized = false;

	public static function init(): void {
		if ( self::$initialized ) {
			return;
		}
		self::$initialized = true;
		add_action( 'bizcity_usage_plan_applied', array( __CLASS__, 'on_applied' ), 20, 3 ); // after the revenue ledger (priority 10)
	}

	/* ── pure texts ─────────────────────────────────────────────────── */

	public static function vnd( int $n ): string {
		return number_format( max( 0, $n ), 0, ',', '.' ) . ' ₫';
	}

	public static function link_text( string $label_masked, int $months, int $amount_vnd, string $pay_url ): string {
		return sprintf( 'Gói Pro %d tháng cho số %s: %s. Thanh toán bằng chuyển khoản tại %s — gói tự gia hạn ngay khi ngân hàng báo về.', $months, $label_masked, self::vnd( $amount_vnd ), $pay_url );
	}

	public static function receipt_text( string $label_masked, int $amount_vnd, string $until_dmy ): string {
		return sprintf( 'Đã nhận %s. Gói Pro của số %s gia hạn tới %s. Cảm ơn bạn!', self::vnd( $amount_vnd ), $label_masked, $until_dmy );
	}

	/** "31/01/2027" in the site timezone from a UTC "Y-m-d H:i:s". */
	public static function dmy( ?string $utc, DateTimeZone $tz ): string {
		$t = null === $utc || '' === $utc ? false : strtotime( $utc . ' UTC' );
		return false === $t ? '' : ( new DateTime( '@' . $t ) )->setTimezone( $tz )->format( 'd/m/Y' );
	}

	/* ── send ───────────────────────────────────────────────────────── */

	/** @return array{ok:bool,code:string} code: sent | no_owner_uid | no_transport */
	public static function send_link( string $account, int $order_id, int $months, int $amount_vnd, string $pay_url ): array {
		$uid = trim( self::owner_uid( $account ) );
		if ( '' === $uid ) {
			self::log( 'plan link not sent: the number has no owner uid.', array( 'account_id' => $account, 'order_id' => $order_id ) );
			return array( 'ok' => false, 'code' => 'no_owner_uid' );
		}
		$ok = self::send( $account, $uid, self::link_text( self::label( $account ), $months, $amount_vnd, $pay_url ), 'plan-link:' . $order_id );
		return $ok ? array( 'ok' => true, 'code' => 'sent' ) : array( 'ok' => false, 'code' => 'no_transport' );
	}

	/** Hook: the order was applied. `$o` = Plan_Woo::order() (account_id), `$plan_row` = the plan after the extension. Never throws. */
	public static function on_applied( $order_id, $o, $plan_row ): void {
		try {
			if ( ! self::enabled() ) {
				return;
			}
			$account = (string) ( is_array( $o ) ? ( $o['account_id'] ?? '' ) : '' );
			if ( '' === $account ) {
				return;
			}
			$uid = trim( self::owner_uid( $account ) );
			if ( '' === $uid ) {
				self::log( 'plan receipt not sent: the number has no owner uid.', array( 'account_id' => $account, 'order_id' => (int) $order_id ) );
				return;
			}
			$amount = self::amount( (int) $order_id );
			$until  = self::dmy( is_array( $plan_row ) ? (string) ( $plan_row['valid_until'] ?? '' ) : '', self::tz() );
			self::send( $account, $uid, self::receipt_text( self::label( $account ), $amount, $until ), 'plan-receipt:' . (int) $order_id );
		} catch ( \Throwable $e ) {
			self::log( 'plan receipt failed: ' . $e->getMessage(), array( 'order_id' => (int) $order_id ) );
		}
	}

	/* ── seams ──────────────────────────────────────────────────────── */

	private static function enabled(): bool {
		if ( isset( self::$readers['enabled'] ) ) {
			return (bool) call_user_func( self::$readers['enabled'] );
		}
		return ! function_exists( 'get_option' ) || 'no' !== (string) get_option( self::OPT_RECEIPT, 'yes' );
	}

	private static function amount( int $order_id ): int {
		if ( isset( self::$readers['amount'] ) ) {
			return (int) call_user_func( self::$readers['amount'], $order_id );
		}
		$o = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
		return $o ? (int) round( (float) $o->get_total() ) : 0;
	}

	private static function owner_uid( string $account ): string {
		if ( isset( self::$readers['owner_uid'] ) ) {
			return (string) call_user_func( self::$readers['owner_uid'], $account );
		}
		$p = class_exists( 'BizCity_Zalo_Agent_Principals' ) ? BizCity_Zalo_Agent_Principals::policy( $account ) : array();
		return (string) ( $p['owner_uid'] ?? '' );
	}

	private static function send( string $account, string $uid, string $text, string $idem ): bool {
		if ( isset( self::$readers['send'] ) ) {
			return (bool) call_user_func( self::$readers['send'], $account, $uid, $text, $idem );
		}
		if ( ! class_exists( 'BizCity_Zalo_Transport_Registry' ) ) {
			return false;
		}
		$transport = BizCity_Zalo_Transport_Registry::for_account( $account );
		if ( ! $transport ) {
			return false;
		}
		$res = $transport->send(
			array( 'bridge_account_id' => $account, 'peer_thread_id' => $uid, 'thread_kind' => 'personal' ),
			array( 'text' => $text, 'content_type' => 'text' ),
			array( 'idempotency_key' => $idem )
		);
		return is_array( $res ) && ! empty( $res['ok'] );
	}

	private static function label( string $account ): string {
		$raw = '';
		if ( isset( self::$readers['label'] ) ) {
			$raw = (string) call_user_func( self::$readers['label'], $account );
		} elseif ( class_exists( 'BizCity_Zalo_Mapping_Repo' ) ) {
			$a   = BizCity_Zalo_Mapping_Repo::find_account_by_bridge_id( 'personal', $account );
			$raw = is_array( $a ) ? trim( (string) ( $a['label'] ?? '' ) ) : '';
			$raw = '' !== $raw ? $raw : ( is_array( $a ) ? trim( (string) ( $a['account_name'] ?? '' ) ) : '' );
		}
		$masked = '' !== $raw && class_exists( 'BizCity_Usage_View_REST' ) ? BizCity_Usage_View_REST::mask( $raw ) : $raw;
		return '' !== $masked ? $masked : 'Số …' . substr( $account, -3 );
	}

	private static function tz(): DateTimeZone {
		if ( isset( self::$readers['tz'] ) ) {
			return call_user_func( self::$readers['tz'] );
		}
		return function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
	}

	private static function log( string $message, array $context ): void {
		if ( isset( self::$readers['log'] ) ) {
			call_user_func( self::$readers['log'], $message, $context );
			return;
		}
		if ( class_exists( 'BizCity_Channel_File_Logger' ) ) {
			BizCity_Channel_File_Logger::write( BizCity_Channel_File_Logger::CH_CHANNEL_GATEWAY, BizCity_Channel_File_Logger::LEVEL_WARN, 'usage_plan_notice', $message, $context );
		}
	}
}
