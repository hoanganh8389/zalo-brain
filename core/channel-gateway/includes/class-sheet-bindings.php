<?php
/**
 * BizCity Channel Gateway — the Google Sheets a PERSON has bound as their cash book (PHASE-0.117 S117-S1).
 *
 * Stored per person (user meta `bizcity_sheet_bindings`, JSON): `[{template, spreadsheet_id, tab, bound_at}]`, ONE per template. No e-mail, no name,
 * no token. It rides to the cell inside the user-workspace@1 bundle (`bindings.sheets[]`, built by BizCity_User_Workspace_Builder) — the cell writes /
 * reads ONLY the sheet named here, never one the model names.
 *
 * @package BizCity_Channel_Gateway
 */
// [2026-10-16 10:46 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.117 S117-S1

defined( 'ABSPATH' ) || exit;

final class BizCity_Sheet_Bindings {

	const META      = 'bizcity_sheet_bindings';
	/**
	 * the skills whose Job creates a book for the person ⇒ the template that book gets (cell: src/jobs/ledger-job.ts skillOf(), src/sheets/book-templates.ts FAMILIES).
	 * [2026-10-17 03:33 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.117 S117-W1 — one workbook, several books: a debts setup may add a TAB to the cash-book spreadsheet.
	 */
	const SETUP_SKILLS = array(
		'ledger.setup' => 'cashbook_v2',
		'debts.setup'  => 'debts_v1',
		'progress.setup' => 'progress_v1',
		'checklist.setup' => 'checklist_v1',
		'plan.setup' => 'plan_v1',
		'docs.setup' => 'docs_v1', // [2026-10-18 01:35 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.121 A2-3 / B2-3 (I-8) — sổ tài liệu (tab TaiLieu)
	);
	/** must equal zalo-hub src/sheets/template-ids.ts TEMPLATE_IDS */
	const TEMPLATES = array( 'cashbook_v1', 'cashbook_v2', 'debts_v1', 'progress_v1', 'checklist_v1', 'plan_v1', 'docs_v1' ); // [2026-10-18 01:35 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.121 A2-3 / B2-3 (I-8)

	/** [2026-10-17 03:10 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.117 S117-S7 — the site adopts a book the CELL created: the Job event carries evidence `sheet_created` with ref `sheet:<id>!<tab>` */
	public static function init(): void {
		add_action( 'bizcity_twin_job_event', array( __CLASS__, 'on_job_event' ), 10, 1 );
	}

	/**
	 * A finished `ledger.setup` / `debts.setup` Job of THIS person ⇒ bind the book it created (cashbook_v2 / debts_v1), unless they already have one, then push the bundle at once so the
	 * cell sees the site as the owner of the binding. Nothing else is read from the event; ids are validated like any manual binding.
	 *
	 * @param array<string,mixed> $e twin hook payload {user_id, kind, status, result}
	 */
	public static function on_job_event( $e ): void {
		if ( ! is_array( $e ) || 'completed' !== ( $e['kind'] ?? '' ) || ! in_array( $e['status'] ?? '', array( 'completed_verified', 'completed_partial' ), true ) ) {
			return;
		}
		$uid    = (int) ( $e['user_id'] ?? 0 );
		$result = is_array( $e['result'] ?? null ) ? $e['result'] : array();
		$new = self::SETUP_SKILLS[ (string) ( $result['skill']['id'] ?? '' ) ] ?? '';
		if ( $uid <= 0 || '' === $new ) {
			return;
		}
		foreach ( (array) ( $result['evidence'] ?? array() ) as $ev ) {
			if ( ! is_array( $ev ) || 'sheet_created' !== ( $ev['kind'] ?? '' ) || ! preg_match( '/^sheet:([A-Za-z0-9_-]{20,80})!(.+)$/u', (string) ( $ev['ref'] ?? '' ), $m ) ) {
				continue;
			}
			$have = false;
			foreach ( self::get( $uid ) as $b ) {
				$have = $have || $new === $b['template'] || self::family_of( $new ) === self::family_of( $b['template'] );
			}
			if ( $have || ! self::valid_tab( $m[2] ) ) {
				return;
			}
			if ( self::set( $uid, $new, $m[1], $m[2] )['ok'] && class_exists( 'BizCity_User_Workspace_Pusher' ) ) {
				BizCity_User_Workspace_Pusher::push( $uid, true );
			}
			return;
		}
	}

	/** 'cashbook' | 'debts' | 'progress' — a person has ONE book per family */
	public static function family_of( string $template ): string {
		return (string) preg_replace( '/_v\d+$/', '', $template );
	}

	/** A spreadsheet id, or a full docs.google.com/spreadsheets/d/<id>/… URL → the id ('' when it is neither) */
	public static function id_from( $input ): string {
		$s = trim( (string) $input );
		if ( preg_match( '#/spreadsheets/d/([A-Za-z0-9_-]{20,80})#', $s, $m ) ) {
			return $m[1];
		}
		return preg_match( '/^[A-Za-z0-9_-]{20,80}$/', $s ) ? $s : '';
	}

	public static function valid_tab( $tab ): bool {
		$t = is_string( $tab ) ? trim( $tab ) : '';
		return '' !== $t && strlen( $t ) <= 60 && 1 === preg_match( '/^[^\[\]*?:\\\\\/!\']+$/u', $t );
	}

	/** @return array<int,array{template:string,spreadsheet_id:string,tab:string,bound_at:string}> */
	public static function get( $user_id ): array {
		$raw  = get_user_meta( (int) $user_id, self::META, true );
		$list = is_string( $raw ) && '' !== $raw ? json_decode( $raw, true ) : $raw;
		$out  = array();
		foreach ( is_array( $list ) ? $list : array() as $b ) {
			if ( is_array( $b ) && in_array( $b['template'] ?? '', self::TEMPLATES, true ) && '' !== self::id_from( $b['spreadsheet_id'] ?? '' ) && self::valid_tab( $b['tab'] ?? '' ) ) {
				$out[] = array( 'template' => (string) $b['template'], 'spreadsheet_id' => (string) $b['spreadsheet_id'], 'tab' => (string) $b['tab'], 'bound_at' => (string) ( $b['bound_at'] ?? '' ) );
			}
		}
		return $out;
	}

	/** @return array{ok:bool,id?:string,code?:string,message?:string} shape check only; nothing is stored */
	public static function validate( string $template, $spreadsheet, $tab ): array {
		if ( ! in_array( $template, self::TEMPLATES, true ) ) {
			return array( 'ok' => false, 'code' => 'invalid_param', 'message' => 'Mẫu sổ không hợp lệ.' );
		}
		$id = self::id_from( $spreadsheet );
		if ( '' === $id ) {
			return array( 'ok' => false, 'code' => 'invalid_param', 'message' => 'Dán địa chỉ (URL) hoặc mã của bảng tính Google Sheets.' );
		}
		if ( ! self::valid_tab( $tab ) ) {
			return array( 'ok' => false, 'code' => 'invalid_param', 'message' => 'Tên tab không hợp lệ (không chứa [ ] * ? : / ! hoặc dấu nháy đơn).' );
		}
		return array( 'ok' => true, 'id' => $id );
	}

	/** @return array{ok:bool,code?:string,message?:string} */
	public static function set( $user_id, string $template, $spreadsheet, $tab ): array {
		$v = self::validate( $template, $spreadsheet, $tab );
		if ( empty( $v['ok'] ) ) {
			return $v;
		}
		$id = (string) $v['id'];
		$list = array_values( array_filter( self::get( $user_id ), static function ( $b ) use ( $template ) {
			return $b['template'] !== $template;
		} ) );
		$list[] = array( 'template' => $template, 'spreadsheet_id' => $id, 'tab' => trim( (string) $tab ), 'bound_at' => gmdate( 'Y-m-d\TH:i:s\Z' ) );
		update_user_meta( (int) $user_id, self::META, wp_json_encode( $list ) );
		return array( 'ok' => true );
	}

	public static function remove( $user_id, string $template ): void {
		$list = array_values( array_filter( self::get( $user_id ), static function ( $b ) use ( $template ) {
			return $b['template'] !== $template;
		} ) );
		update_user_meta( (int) $user_id, self::META, wp_json_encode( $list ) );
	}

	/** what goes into the workspace bundle (only the fields the cell's schema knows) */
	public static function for_workspace( $user_id ): array {
		return self::get( $user_id );
	}
}
