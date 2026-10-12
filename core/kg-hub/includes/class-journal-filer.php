<?php
/**
 * BizCity_Journal_Filer — K3 "xếp ngăn" (PHASE-0.100 S100-L17, doc 92 §4, R-JOURNAL R-JN-6/R-JN-7).
 *
 * `place()` is a PURE decision function (no DB, no notebook service call) — same contract/fixture pattern as
 * `BizCity_Skills_Service` body rules: given a journal-entry@1-shaped `$entry` + a small `$profile` block, it
 * returns WHICH notebooks the entry belongs in. `apply()` is the thin applier that turns that decision into real
 * KG-Hub notebooks + `kg_notebook_sources` many-to-many links, using the existing per-number workspace
 * (`BizCity_KG_Zalo_Number_Notebooks`) — never renaming or deleting a notebook the owner already named (R-JN-6).
 *
 * Biz Central Brain — Johnny Chu (Chu Hoàng Anh).
 *
 * [2026-10-12 10:25 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.100 S100-L17 — new file.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\KG_Hub
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Journal_Filer', false ) ) {
	return;
}

final class BizCity_Journal_Filer {

	/**
	 * Pure. Decide which notebooks (by a stable, filer-local key — not a DB id) an entry belongs in.
	 *
	 * @param array $entry   journal-entry@1 shape, minimally {kind, subject?:{type,hash}, tags?}.
	 * @param array $profile { per_subject_notebook?: bool|null (null = use the kind's family default) }.
	 * @return array{targets: array<int,array{type:string,kind?:string,subject_hash?:string,label?:string}>}
	 */
	public static function place( array $entry, array $profile = array() ): array {
		$targets = array();
		// 1. Every entry goes to the day inbox (today's behaviour, unchanged — doc 92 §4 rule 1).
		$targets[] = array( 'type' => 'day_inbox' );

		// 2. A known kind adds the kind shelf (many-to-many — rule 2).
		$kind = (string) ( $entry['kind'] ?? '' );
		if ( '' !== $kind && class_exists( 'BizCity_Journal_Database' ) ) {
			$kind = BizCity_Journal_Database::sanitize_kind( $kind );
		}
		if ( '' !== $kind ) {
			$label = class_exists( 'BizCity_Journal_Kinds' ) ? BizCity_Journal_Kinds::label( $kind ) : $kind;
			$targets[] = array( 'type' => 'kind_shelf', 'kind' => $kind, 'label' => $label );
		}

		// 3. subject + (profile flag or the kind's family default) adds the per-subject notebook (rule 3).
		$subject_hash = '';
		if ( is_array( $entry['subject'] ?? null ) ) {
			$subject_hash = (string) ( $entry['subject']['hash'] ?? '' );
		} elseif ( ! empty( $entry['subject_hash'] ) ) {
			$subject_hash = (string) $entry['subject_hash'];
		}
		if ( '' !== $subject_hash && '' !== $kind ) {
			$family = class_exists( 'BizCity_Journal_Kinds' ) ? BizCity_Journal_Kinds::family( $kind ) : explode( '.', $kind )[0];
			$wants_per_subject = array_key_exists( 'per_subject_notebook', $profile ) && null !== $profile['per_subject_notebook']
				? (bool) $profile['per_subject_notebook']
				: ( class_exists( 'BizCity_Journal_Kinds' ) ? BizCity_Journal_Kinds::per_subject_notebook_default( $family ) : false );
			if ( $wants_per_subject ) {
				$targets[] = array( 'type' => 'subject', 'subject_hash' => $subject_hash, 'label' => (string) ( $entry['subject']['label'] ?? '' ) );
			}
		}

		return array( 'targets' => $targets );
	}

	/**
	 * Apply a place() decision: find-or-create each target notebook in the number's workspace and link the
	 * KG source (many-to-many, never duplicated, never renamed/deleted — R-JN-6).
	 *
	 * @param array  $decision   place()'s return.
	 * @param int    $owner_user_id
	 * @param string $bridge_id  zalo-hub account id (number) — resolves the workspace, like the channel bridge does.
	 * @param int    $kg_source_id source row to link (0 = decision only, no KG link yet).
	 * @param string $day_key    Ymd, for the day-inbox notebook title parity with the channel bridge.
	 * @return array{notebook_ids:int[],created:int[]}
	 */
	public static function apply( array $decision, int $owner_user_id, string $bridge_id, int $kg_source_id, string $day_key = '' ): array {
		$notebook_ids = array();
		$created      = array();
		if ( $owner_user_id <= 0 || ! class_exists( 'BizCity_KG_Notebook_Service' ) ) {
			return array( 'notebook_ids' => $notebook_ids, 'created' => $created );
		}
		$workspace_id = class_exists( 'BizCity_KG_Zalo_Number_Notebooks' ) && '' !== $bridge_id
			? BizCity_KG_Zalo_Number_Notebooks::ensure_workspace( $owner_user_id, $bridge_id )
			: '';
		foreach ( (array) ( $decision['targets'] ?? array() ) as $target ) {
			$type = (string) ( $target['type'] ?? '' );
			if ( 'day_inbox' === $type ) {
				continue; // the channel bridge already creates/links the day-inbox notebook itself (unchanged behaviour).
			}
			$settings_match = array();
			$name = '';
			if ( 'kind_shelf' === $type ) {
				$settings_match = array( 'workspace_id' => $workspace_id, 'journal_shelf_kind' => (string) $target['kind'] );
				$name = (string) ( $target['label'] ?? $target['kind'] );
			} elseif ( 'subject' === $type ) {
				$settings_match = array( 'workspace_id' => $workspace_id, 'journal_subject_hash' => (string) $target['subject_hash'] );
				$name = '' !== (string) ( $target['label'] ?? '' ) ? (string) $target['label'] : 'Theo người';
			} else {
				continue;
			}
			$nb_id = self::find_or_create_notebook( $owner_user_id, $name, $settings_match, $created );
			if ( $nb_id > 0 ) {
				$notebook_ids[] = $nb_id;
				if ( $kg_source_id > 0 ) {
					self::link_source( $nb_id, $kg_source_id );
				}
			}
		}
		return array( 'notebook_ids' => array_values( array_unique( $notebook_ids ) ), 'created' => $created );
	}

	/** Find a notebook whose settings contain every key of `$match`, else create one (name never overwritten later). */
	private static function find_or_create_notebook( int $owner_user_id, string $name, array $match, array &$created ): int {
		$svc = BizCity_KG_Notebook_Service::instance();
		foreach ( (array) $svc->list_for_user( $owner_user_id, array( 'limit' => 200, 'with_stats' => false ) ) as $row ) {
			$settings = (array) ( $row['settings'] ?? array() );
			$hit = true;
			foreach ( $match as $k => $v ) {
				if ( (string) ( $settings[ $k ] ?? '' ) !== (string) $v ) {
					$hit = false;
					break;
				}
			}
			if ( $hit ) {
				return (int) $row['id'];
			}
		}
		$row = $svc->create( array(
			'name'     => sanitize_text_field( $name ),
			'settings' => $match,
		), $owner_user_id );
		if ( is_array( $row ) && ! empty( $row['id'] ) ) {
			$created[] = (int) $row['id'];
			return (int) $row['id'];
		}
		return 0;
	}

	/** `kg_notebook_sources` many-to-many — IGNORE on the unique key, never a duplicate row (R-JN-6). */
	private static function link_source( int $notebook_id, int $source_id ): void {
		if ( ! class_exists( 'BizCity_KG_Database' ) ) {
			return;
		}
		global $wpdb;
		$tbl = BizCity_KG_Database::instance()->tbl_notebook_sources();
		$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$tbl} (notebook_id, source_id) VALUES (%d, %d)", $notebook_id, $source_id ) );
	}
}
