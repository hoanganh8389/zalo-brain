<?php
/**
 * BizCity_CRM_Lead_Score — one rule for "the lead score" of a contact (PHASE-0.95 D95-6, owner 2026-10-10):
 *   - `lead_score`      written by a person (CRM classify, import) — 0 = not set;
 *   - `lead_score_cell` written by the assistant (cell, action.crm_signal) when it labels nóng/ấm/lạnh — NULL = none; a reference;
 *   - effective = the person's score when > 0, else the cell's, else 0. The cell never overwrites the person's score.
 * Readers (filters, exports, packs) use effective() / effective_sql(); writers pick their own column.
 *
 * Biz Central Brain — Johnny Chu (Chu Hoàng Anh). Bizcity Central Brain, Giấy chứng nhận đăng ký quyền tác giả
 * số 8877/2026/QTG (Cục Bản quyền tác giả, 14/09/2026). R-BIZ-CENTRAL-BRAIN R-BCB-8.
 *
 * // [2026-10-10 12:33 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.95 D95-6 — new file.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\CRM
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_CRM_Lead_Score' ) ) {
	return;
}

final class BizCity_CRM_Lead_Score {

	const PERSON_COLUMN = 'lead_score';
	const CELL_COLUMN   = 'lead_score_cell';

	/** Effective score of one contact row (array with lead_score / lead_score_cell). Pure. */
	public static function effective( array $row ): int {
		$person = (int) ( $row[ self::PERSON_COLUMN ] ?? 0 );
		if ( $person > 0 ) {
			return min( 100, $person );
		}
		$cell = $row[ self::CELL_COLUMN ] ?? null;
		return null === $cell || '' === $cell ? 0 : max( 0, min( 100, (int) $cell ) );
	}

	/** Who the effective score comes from: person · cell · none. Pure. */
	public static function source( array $row ): string {
		if ( (int) ( $row[ self::PERSON_COLUMN ] ?? 0 ) > 0 ) {
			return 'person';
		}
		$cell = $row[ self::CELL_COLUMN ] ?? null;
		return null === $cell || '' === $cell ? 'none' : 'cell';
	}

	/**
	 * SQL expression of the effective score for a table alias ('' = no alias). Falls back to the person column alone while the
	 * 2.1.0 migration has not added `lead_score_cell` yet (pass $has_cell_column = false).
	 */
	public static function effective_sql( string $alias = '', bool $has_cell_column = true ): string {
		$p = ( '' !== $alias ? $alias . '.' : '' ) . self::PERSON_COLUMN;
		if ( ! $has_cell_column ) {
			return $p;
		}
		$c = ( '' !== $alias ? $alias . '.' : '' ) . self::CELL_COLUMN;
		return "(CASE WHEN {$p} > 0 THEN {$p} ELSE COALESCE({$c}, 0) END)";
	}

	/** Does the contacts table already have the cell column (2.1.0)? Cached per request. */
	public static function has_cell_column(): bool {
		static $has = null;
		if ( null === $has ) {
			global $wpdb;
			$t   = $wpdb->prefix . 'bizcity_crm_contacts';
			$has = (bool) $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM `{$t}` LIKE %s", self::CELL_COLUMN ) );
		}
		return $has;
	}
}
