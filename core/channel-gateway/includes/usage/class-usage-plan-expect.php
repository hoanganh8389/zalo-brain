<?php
/**
 * "Hết dự kiến" of one number — the earlier of the plan end and the day the monthly token allowance runs out at the recent pace
 * (PHASE-0.108 S108-L20.1, 24-TECH-DESIGN-REVENUE.md §3.2, mockup ② column "Hết dự kiến").
 *
 * Pure: every input is passed in (no DB, no clock). The caller supplies the 7-day average, how many days of data that average rests on and the end of
 * the allowance cycle (first of next month, site timezone), so the result is the same in tests and in production.
 *
 * Rules (24 §3.2):
 *  - state none / expired ⇒ null (nothing left to predict).
 *  - plan_end = valid_until.
 *  - token_end only when allowance > 0, avg_daily > 0 and days_of_data >= 3 (fewer days ⇒ "chưa đủ dữ liệu", never a guess); allowance already used up ⇒ now.
 *  - the allowance resets every month, so a token_end past the cycle end is not a real end and is dropped.
 *  - result = whichever of the two comes first; a tie goes to the plan (it is the harder fact).
 *
 * // [2026-10-13 09:07 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.108 S108-L20.1.
 *
 * @package BizCity_Twin_AI
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_Usage_Plan_Expect {

	const MIN_DAYS_OF_DATA = 3;

	/**
	 * @param array{state?:string,valid_until?:?string,monthly_token_allowance?:int} $plan valid_until is a UTC "Y-m-d H:i:s" string (as stored)
	 * @param int      $used_tokens      billed tokens used in the current allowance cycle
	 * @param float    $avg_daily_tokens billed tokens per day over the last 7 days
	 * @param int      $days_of_data     how many of those days actually had data (0-7)
	 * @param int      $now              unix seconds
	 * @param int|null $cycle_end        unix seconds of the next allowance reset; null = no cap on the horizon
	 * @return array{ts:int,date:string,reason:string}|null reason: plan | token
	 */
	public static function expected_end( array $plan, int $used_tokens, float $avg_daily_tokens, int $days_of_data, int $now, ?int $cycle_end = null ): ?array {
		$state = (string) ( $plan['state'] ?? 'none' );
		if ( ! in_array( $state, array( 'trial', 'active' ), true ) ) {
			return null;
		}
		$plan_end = self::ts( $plan['valid_until'] ?? null );
		$token_end = null;

		$allowance = (int) ( $plan['monthly_token_allowance'] ?? 0 );
		if ( $allowance > 0 ) {
			if ( $used_tokens >= $allowance ) {
				$token_end = $now;
			} elseif ( $avg_daily_tokens > 0 && $days_of_data >= self::MIN_DAYS_OF_DATA ) {
				$token_end = $now + (int) ceil( ( $allowance - $used_tokens ) / $avg_daily_tokens * 86400 );
			}
			if ( null !== $token_end && null !== $cycle_end && $token_end >= $cycle_end ) {
				$token_end = null; // the allowance resets before it would run out
			}
		}

		if ( null === $plan_end && null === $token_end ) {
			return null;
		}
		if ( null !== $token_end && ( null === $plan_end || $token_end < $plan_end ) ) {
			return array( 'ts' => $token_end, 'date' => gmdate( 'Y-m-d\TH:i:s\Z', $token_end ), 'reason' => 'token' );
		}
		return array( 'ts' => (int) $plan_end, 'date' => gmdate( 'Y-m-d\TH:i:s\Z', (int) $plan_end ), 'reason' => 'plan' );
	}

	/** True when the plan is still running but the allowance will run out before it (the ⏳ flag of mockup ①②). */
	public static function token_before_plan( ?array $expected ): bool {
		return is_array( $expected ) && 'token' === $expected['reason'];
	}

	private static function ts( $s ): ?int {
		if ( null === $s || '' === (string) $s || '0000-00-00 00:00:00' === (string) $s ) {
			return null;
		}
		$t = strtotime( (string) $s . ' UTC' );
		return false === $t ? null : (int) $t;
	}
}
