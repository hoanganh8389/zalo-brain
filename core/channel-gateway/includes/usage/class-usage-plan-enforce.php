<?php
/**
 * Plan enforcement on the SITE (PHASE-0.107 round 5): the cell only measures tokens and holds no plan, no gate, no notice.
 *
 * evaluate(plan row, used tokens, now): `needs_off` = plan_mode = on AND (state expired OR valid_until passed OR (allowance > 0 AND used >=
 * allowance)); reason `plan_expired` | `plan_allowance_exhausted`. `used` = SUM(billed_tokens) of this site's own ledger (BizCity_Usage_Plans::used).
 *
 * apply() flips the EXISTING per-number AI switch (the cell already has the `ai_disabled` gate): the server-side function behind
 * `POST bizcity-channel/v1/zalo-bridge/accounts/{id}/ai-enabled` is `BizCity_Zalo_Personal_Hub_Client::instance()->set_ai_enabled( $id, $bool )`
 * (the REST handler `BizCity_Zalo_Bridge_REST::handle_set_ai_enabled` only wraps it) — called directly, no HTTP hop to our own REST.
 * Rules:
 *   - plan_mode off ⇒ nothing is touched (fail-safe: a number always runs unless this class turned it off). The one exception is a number
 *     WE switched off (`ai_off_by_plan` set): when its plan stops being enforced (renewed, or plan_mode turned off) it is switched back on.
 *   - a number whose AI is off for another reason (a human, the Hub pool) is NEVER touched and NEVER switched on by us;
 *   - AI is switched back ON only when `ai_off_by_plan` is set (we did it) and the plan no longer needs it off;
 *   - idempotent; a failed switch call is recorded in `enforce_error` and retried by the next trigger.
 * Triggers: after ledger-ingest stored rows of a plan_mode=on account, the daily cron, and right after set_plan / bulk / import / extend_pro / Woo.
 *
 * Owner notice: when a number is blocked by us, ONE fixed text per account per day goes to the number's OWNER 1-1 thread through the site's
 * Zalo transport (BizCity_Zalo_Transport_Registry::for_account()->send to `policy.owner_uid`). No owner uid / no transport ⇒ log + skip.
 * There is NO customer notice: while AI is off customers stay silent and their messages still reach the CRM inbox for a human.
 *
 * // [2026-10-13 03:30 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.107 round 5 — site-side plan enforcement + owner notice.
 *
 * @package BizCity_Twin_AI
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_Usage_Plan_Enforce {

	const REASON_EXPIRED   = 'plan_expired';
	const REASON_ALLOWANCE = 'plan_allowance_exhausted';
	const OPT_RENEW_URL    = 'bizcity_plan_renew_url';

	/** Test seams: ai_enabled(id): bool · switch(id, bool): array{ok,code} · owner_uid(id): string · send(id, uid, text, idem): bool · label(id): string · log(msg, ctx) · option(n, d) */
	public static $readers = array();

	/**
	 * @return array{needs_off:bool,reason:string}
	 */
	public static function evaluate( array $row, int $used, int $now ): array {
		if ( 'on' !== ( $row['plan_mode'] ?? 'off' ) ) {
			return array( 'needs_off' => false, 'reason' => '' );
		}
		$state = BizCity_Usage_Plans::effective_state( $row, $now );
		$until = BizCity_Usage_Plans::ts( $row['valid_until'] ?? null );
		if ( 'expired' === $state || ( $until > 0 && $now >= $until ) ) {
			return array( 'needs_off' => true, 'reason' => self::REASON_EXPIRED );
		}
		$allow = (int) ( $row['monthly_token_allowance'] ?? 0 );
		if ( $allow > 0 && $used >= $allow ) {
			return array( 'needs_off' => true, 'reason' => self::REASON_ALLOWANCE );
		}
		return array( 'needs_off' => false, 'reason' => '' );
	}

	/**
	 * Evaluate and, if needed, flip the AI switch of one number.
	 *
	 * @return array{action:string,reason:string,error:string} action ∈ none | ai_off | ai_on | kept_off | human_off | error
	 */
	public static function apply( string $platform, string $account ): array {
		$none = array( 'action' => 'none', 'reason' => '', 'error' => '' );
		if ( BizCity_Usage_Plans::PLATFORM !== $platform ) {
			return $none;
		}
		$row = BizCity_Usage_Plans::get( $platform, $account );
		if ( ! $row ) {
			return $none;
		}
		$now    = BizCity_Usage_Plans::now();
		$on     = 'on' === $row['plan_mode'];
		$ours   = (string) ( $row['ai_off_by_plan'] ?? '' );
		if ( ! $on && '' === $ours ) {
			return $none; // fail-safe: plan_mode off ⇒ never touch anything
		}
		$used = ( $on && (int) $row['monthly_token_allowance'] > 0 ) ? BizCity_Usage_Plans::used( $row, $now ) : 0;
		$ev   = self::evaluate( $row, $used, $now );
		$ai   = self::ai_enabled( $account );

		if ( $ev['needs_off'] ) {
			if ( $ai ) {
				$r = self::switch_ai( $account, false );
				if ( ! $r['ok'] ) {
					return self::fail( $row, $r['code'] );
				}
				$row['ai_off_by_plan'] = $ev['reason'];
				$row['ai_off_at']      = BizCity_Usage_Plans::fmt( $now );
				$row['enforce_error']  = '';
				$row = self::save( $row );
				self::notify( $row, $ev['reason'], $now );
				return array( 'action' => 'ai_off', 'reason' => $ev['reason'], 'error' => '' );
			}
			if ( '' === $ours ) {
				return array( 'action' => 'human_off', 'reason' => '', 'error' => '' ); // off for another reason: not ours, never touched
			}
			if ( $ours !== $ev['reason'] || '' !== (string) $row['enforce_error'] ) {
				$row['ai_off_by_plan'] = $ev['reason'];
				$row['enforce_error']  = '';
				$row = self::save( $row );
			}
			self::notify( $row, $ev['reason'], $now ); // deduped to one per day
			return array( 'action' => 'kept_off', 'reason' => $ev['reason'], 'error' => '' );
		}

		if ( '' !== $ours ) {
			if ( ! $ai ) {
				$r = self::switch_ai( $account, true );
				if ( ! $r['ok'] ) {
					return self::fail( $row, $r['code'] );
				}
			}
			$row['ai_off_by_plan'] = '';
			$row['ai_off_at']      = null;
			$row['enforce_error']  = '';
			self::save( $row );
			return array( 'action' => 'ai_on', 'reason' => '', 'error' => '' );
		}
		if ( '' !== (string) $row['enforce_error'] ) {
			$row['enforce_error'] = '';
			self::save( $row );
		}
		return $none;
	}

	/** After ledger-ingest stored rows: only accounts that have plan_mode on are looked at. */
	public static function on_ingest( string $account ): ?array {
		$row = BizCity_Usage_Plans::get( BizCity_Usage_Plans::PLATFORM, $account );
		if ( ! $row || 'on' !== $row['plan_mode'] ) {
			return null;
		}
		return self::apply( BizCity_Usage_Plans::PLATFORM, $account );
	}

	/** The fixed owner text (pure; unit-tested). */
	public static function notice_text( array $row, string $reason, string $label_masked, string $link ): string {
		if ( self::REASON_ALLOWANCE === $reason ) {
			$why = 'hết hạn mức token';
		} elseif ( 'trial' === ( $row['plan_code'] ?? '' ) ) {
			$why = 'hết thời gian dùng thử';
		} else {
			$why = 'hết hạn gói';
		}
		$text = sprintf( 'Trợ lý của số %s đã tạm dừng: %s. Gia hạn tại %s để dùng lại.', $label_masked, $why, $link );
		$extra = trim( (string) ( $row['notice_text'] ?? '' ) );
		return '' !== $extra ? $text . "\n" . $extra : $text;
	}

	public static function renew_url(): string {
		$u = trim( (string) self::option( self::OPT_RENEW_URL, '' ) );
		if ( '' !== $u && 1 === preg_match( '#^https?://#i', $u ) ) {
			return $u;
		}
		// [2026-10-13 09:21 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.108 S108-U14.1 / S108-L22.2 — an explicit option still wins; otherwise the reminder opens "Gói Zalo của tôi",
		// where the customer picks a package for THIS number and pays by bank transfer (the generic My Account page has nothing to buy).
		if ( class_exists( 'BizCity_Usage_MyPlans_Endpoint' ) ) {
			$mine = BizCity_Usage_MyPlans_Endpoint::url();
			if ( '' !== $mine ) {
				return $mine;
			}
		}
		if ( function_exists( 'wc_get_page_permalink' ) ) {
			$w = (string) wc_get_page_permalink( 'myaccount' );
			if ( '' !== $w ) {
				return $w;
			}
		}
		return function_exists( 'home_url' ) ? (string) home_url( '/' ) : '/';
	}

	/* ── notice ─────────────────────────────────────────────────────── */

	private static function notify( array $row, string $reason, int $now ): void {
		$account = (string) $row['account_id'];
		$tz      = BizCity_Usage_Plans::tz();
		$day     = static function ( $ts ) use ( $tz ) { return ( new DateTime( '@' . (int) $ts ) )->setTimezone( $tz )->format( 'Y-m-d' ); };
		$last    = BizCity_Usage_Plans::ts( $row['last_notice_at'] ?? null );
		if ( $last > 0 && (string) $row['last_notice_reason'] === $reason && $day( $last ) === $day( $now ) ) {
			return; // one per account per day
		}
		$uid = trim( self::owner_uid( $account ) );
		if ( '' === $uid ) {
			self::log( 'plan notice skipped: the number has no owner uid.', array( 'account_id' => $account, 'reason' => $reason ) );
			return;
		}
		$text = self::notice_text( $row, $reason, self::label( $account ), self::renew_url() );
		if ( ! self::send( $account, $uid, $text, 'plan-notice:' . $account . ':' . $day( $now ) . ':' . $reason ) ) {
			self::log( 'plan notice not sent: no Zalo send path for this number.', array( 'account_id' => $account, 'reason' => $reason ) );
			return;
		}
		$row['last_notice_at']     = BizCity_Usage_Plans::fmt( $now );
		$row['last_notice_reason'] = $reason;
		self::save( $row );
	}

	/* ── helpers + seams ───────────────────────────────────────────── */

	private static function fail( array $row, string $code ): array {
		$row['enforce_error'] = substr( 'switch_' . preg_replace( '/[^a-z0-9_]/', '', strtolower( $code ) ), 0, 80 );
		self::save( $row );
		return array( 'action' => 'error', 'reason' => '', 'error' => $row['enforce_error'] );
	}

	private static function save( array $row ): array {
		$saved = BizCity_Usage_Plans::save( $row );
		return is_array( $saved ) ? $saved : $row;
	}

	private static function ai_enabled( string $account ): bool {
		if ( isset( self::$readers['ai_enabled'] ) ) {
			return (bool) call_user_func( self::$readers['ai_enabled'], $account );
		}
		return class_exists( 'BizCity_Zalo_Account_Flags' ) ? BizCity_Zalo_Account_Flags::ai_enabled( $account ) : true;
	}

	/** @return array{ok:bool,code:string} */
	private static function switch_ai( string $account, bool $enabled ): array {
		if ( isset( self::$readers['switch'] ) ) {
			return (array) call_user_func( self::$readers['switch'], $account, $enabled );
		}
		if ( ! class_exists( 'BizCity_Zalo_Personal_Hub_Client' ) ) {
			return array( 'ok' => false, 'code' => 'managed_client_missing' );
		}
		$client = BizCity_Zalo_Personal_Hub_Client::instance();
		if ( method_exists( $client, 'is_ready_fast' ) && ! $client->is_ready_fast() ) {
			return array( 'ok' => false, 'code' => 'managed_client_missing' );
		}
		$r = $client->set_ai_enabled( $account, $enabled ); // same function the ai-enabled REST handler calls
		return ! empty( $r['success'] ) ? array( 'ok' => true, 'code' => '' ) : array( 'ok' => false, 'code' => (string) ( $r['code'] ?? 'failed' ) );
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
		$masked = '' !== $raw ? BizCity_Usage_View_REST::mask( $raw ) : '';
		return '' !== $masked ? $masked : 'Số …' . substr( $account, -3 );
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

	private static function option( string $name, $default ) {
		if ( isset( self::$readers['option'] ) ) {
			return call_user_func( self::$readers['option'], $name, $default );
		}
		return function_exists( 'get_option' ) ? get_option( $name, $default ) : $default;
	}
}
