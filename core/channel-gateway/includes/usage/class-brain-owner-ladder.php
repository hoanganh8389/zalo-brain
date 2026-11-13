<?php
/**
 * The 6-step owner ladder as DATA — "where am I, what is missing for the next step" (PHASE-0.108 S108-L13, 10-SPEC §5).
 *
 * Pure: takes the owner's counts, returns every step with its requirements (have / need / met), which steps are reached,
 * and the requirements still missing for the next step. The REST layer only gathers the counts; the screen only draws this.
 * Step conditions mirror brain-milestones@1 `owner_tiers` and BizCity_Brain_Journey_REST::owner_tier() (kept in step by a test).
 *
 * // [2026-10-14 01:20 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.108 S108-L13.
 *
 * @package BizCity_Twin_AI
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_Brain_Owner_Ladder {

	/** Master needs the average maturity of the running numbers to reach this (the data lives HERE with the other ladder numbers; self::MASTER_MATURITY mirrors it, a test keeps them equal). */
	const MASTER_MATURITY = 60;

	/** Requirement labels (Vietnamese, shown as-is). */
	const LABELS = array(
		'brains_born'         => 'Trợ lý đã tạo',
		'brains_active'       => 'Trợ lý đang chạy',
		'max_brain_replies'   => 'Lượt khách của trợ lý nhiều nhất',
		'max_brain_trained'   => 'Mục được dạy của trợ lý nhiều nhất',
		'total_replies'       => 'Tổng lượt khách',
		// [2026-10-16 12:30 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.112 S112-L5 (D112-3/D112-5) — the Master step no longer asks for a MONEY condition (payback 3 months): it reads the maturity of the running numbers.
		'maturity_avg'        => 'Trưởng thành trung bình của các trợ lý đang chạy (0–100)',
		'diamond_tier_brains' => 'Trợ lý có huy hiệu kim cương',
	);

	/** @return array<int,array{level:int,code:string,label:string,needs:array<string,int>}> */
	public static function steps(): array {
		return array(
			array( 'level' => 1, 'code' => 'sower',       'label' => 'Người gieo',        'needs' => array( 'brains_born' => 1 ) ),
			array( 'level' => 2, 'code' => 'nurturer',    'label' => 'Người nuôi',        'needs' => array( 'max_brain_replies' => 1000 ) ),
			array( 'level' => 3, 'code' => 'trainer',     'label' => 'Huấn luyện viên',   'needs' => array( 'brains_active' => 2, 'max_brain_trained' => 50 ) ),
			array( 'level' => 4, 'code' => 'supervisor',  'label' => 'Quản đốc',          'needs' => array( 'brains_active' => 10, 'total_replies' => 10000 ) ),
			array( 'level' => 5, 'code' => 'master',      'label' => 'Master Zalo Brain', 'needs' => array( 'brains_active' => 30, 'maturity_avg' => self::MASTER_MATURITY ) ),
			array( 'level' => 6, 'code' => 'grandmaster', 'label' => 'Grandmaster',       'needs' => array( 'brains_active' => 100, 'diamond_tier_brains' => 10 ) ),
		);
	}

	/**
	 * @param array<string,int> $have keys of self::LABELS (missing keys count as 0)
	 * @return array{
	 *   level:int,
	 *   ladder:array<int,array{level:int,code:string,label:string,reached:bool,current:bool,requirements:array<int,array{key:string,label:string,have:int,need:int,met:bool}>}>,
	 *   next:?array{level:int,code:string,label:string},
	 *   missing:array<int,array{key:string,label:string,have:int,need:int,remaining:int}>
	 * }
	 */
	public static function build( array $have ): array {
		$level  = 0;
		$ladder = array();
		foreach ( self::steps() as $step ) {
			$reqs = array();
			$all  = true;
			foreach ( $step['needs'] as $key => $need ) {
				$got   = (int) ( $have[ $key ] ?? 0 );
				$met   = $got >= $need;
				$all   = $all && $met;
				$reqs[] = array( 'key' => $key, 'label' => self::LABELS[ $key ] ?? $key, 'have' => $got, 'need' => $need, 'met' => $met );
			}
			if ( $all ) {
				$level = max( $level, $step['level'] ); // highest step whose requirements are ALL met wins (same rule as owner_tier()).
			}
			$ladder[] = array(
				'level' => $step['level'], 'code' => $step['code'], 'label' => $step['label'],
				'reached' => false, 'current' => false, 'requirements' => $reqs,
			);
		}
		$next = null;
		$missing = array();
		foreach ( $ladder as $i => $row ) {
			$ladder[ $i ]['reached'] = $row['level'] <= $level;
			$ladder[ $i ]['current'] = $row['level'] === $level;
			if ( null === $next && $row['level'] === $level + 1 ) {
				$next = array( 'level' => $row['level'], 'code' => $row['code'], 'label' => $row['label'] );
				foreach ( $row['requirements'] as $r ) {
					if ( ! $r['met'] ) {
						$missing[] = array( 'key' => $r['key'], 'label' => $r['label'], 'have' => $r['have'], 'need' => $r['need'], 'remaining' => $r['need'] - $r['have'] );
					}
				}
			}
		}
		return array( 'level' => $level, 'ladder' => $ladder, 'next' => $next, 'missing' => $missing );
	}
}
