<?php
/**
 * BizCity_KG_Learn_Gate — the ONE gate before a billable graph extraction (L2 "Hiểu sâu"), R-JOURNAL v1.2 R-JN-20,
 * PHASE-0.121 B3-1 / S121-K6. Copyright (c) 2026 Chu Hoàng Anh (Johnny Chu), registered No. 8877/2026/QTG. See NOTICE.md.
 *
 * Before: every notebook source went chunk → embed → triplet LLM → auto-approve, except a Zalo number's daily notebook — and
 * that exception lived only in `extract_notebook_pending()`, not on the learning pipeline's own path (doc 74 §1). Now
 * `BizCity_KG_Triplet_Extractor::extract_passage()` asks this gate first, whichever path called it:
 *   1. a sensitive kind (health · child · finance · personal) ⇒ NO graph, ever;
 *   2. the owner asked (journal row `learning.level` L2 / L3) ⇒ yes;
 *   3. the kind declares `learn: graph` (research.*, …) ⇒ yes;
 *   4. the notebook feeds a Guru (attached) ⇒ yes;
 *   5. a Zalo number's notebook (scope zalo_account:*) ⇒ no (D86-5; filter `bizcity_kg_extract_zalo_number_notebooks` re-opens it);
 *   6. anything else ⇒ filter `bizcity_kg_graph_default` (default TRUE: web notebooks keep today's behaviour — Q121-22 only
 *      decided the number notebooks).
 * A closed gate marks the passage `skipped` (reason l2_gate:<why>) — no LLM call, no cost — and the journal row graph_state skipped.
 *
 * @package Bizcity_Twin_AI
 * @since   2026-10-17
 */

// [2026-10-17 09:20 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.121 B3-1 (S121-K6, R-JN-20) — new file.

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_KG_Learn_Gate', false ) ) {
	return;
}

final class BizCity_KG_Learn_Gate {

	/** @var array<string,callable> test seams: facts(passage_row):array */
	public static $readers = array();

	/**
	 * Pure decision.
	 *
	 * @param array $f {sensitive:string, level:string, kind_learn:string, guru_attached:bool, number_notebook:bool,
	 *                  number_override:bool, default:bool}
	 * @return array{allowed:bool,reason:string}
	 */
	public static function decide( array $f ): array {
		if ( '' !== (string) ( $f['sensitive'] ?? '' ) ) {
			return array( 'allowed' => false, 'reason' => 'sensitive' );
		}
		if ( in_array( (string) ( $f['level'] ?? '' ), array( 'L2', 'L3' ), true ) ) {
			return array( 'allowed' => true, 'reason' => 'owner_level' );
		}
		if ( 'graph' === (string) ( $f['kind_learn'] ?? '' ) ) {
			return array( 'allowed' => true, 'reason' => 'kind_graph' );
		}
		if ( ! empty( $f['guru_attached'] ) ) {
			return array( 'allowed' => true, 'reason' => 'guru' );
		}
		if ( ! empty( $f['number_notebook'] ) ) {
			return ! empty( $f['number_override'] ) ? array( 'allowed' => true, 'reason' => 'number_override' ) : array( 'allowed' => false, 'reason' => 'number_notebook' );
		}
		return array( 'allowed' => ! empty( $f['default'] ), 'reason' => ! empty( $f['default'] ) ? 'default' : 'default_off' );
	}

	/** Facts for one passage row {id, notebook_id, source_id}. */
	public static function facts( array $passage ): array {
		if ( isset( self::$readers['facts'] ) ) {
			return (array) call_user_func( self::$readers['facts'], $passage );
		}
		$nb    = (int) ( $passage['notebook_id'] ?? 0 );
		$kg    = (int) ( $passage['source_id'] ?? 0 );
		$facts = array(
			'sensitive'       => '',
			'level'           => '',
			'kind_learn'      => '',
			'guru_attached'   => self::guru_attached( $nb ),
			'number_notebook' => class_exists( 'BizCity_KG_Triplet_Extractor' ) ? BizCity_KG_Triplet_Extractor::is_number_notebook( $nb ) : false,
			'number_override' => (bool) apply_filters( 'bizcity_kg_extract_zalo_number_notebooks', false, $nb ),
			'default'         => (bool) apply_filters( 'bizcity_kg_graph_default', true, $nb ),
		);
		$row = $kg > 0 && class_exists( 'BizCity_Journal_Database' ) ? BizCity_Journal_Database::instance()->get_by_kg_source_id( $kg ) : null;
		if ( $row ) {
			$kind = (string) ( $row['kind'] ?? '' );
			$meta = json_decode( (string) ( $row['metadata'] ?? '' ), true );
			$meta = is_array( $meta ) ? $meta : array();
			$facts['sensitive']  = class_exists( 'BizCity_Journal_Kinds' ) ? BizCity_Journal_Kinds::sensitive( $kind ) : (string) ( $meta['flags']['sensitive'] ?? '' );
			$facts['level']      = (string) ( $meta['learning']['level'] ?? '' );
			$facts['kind_learn'] = class_exists( 'BizCity_Journal_Kinds' ) ? BizCity_Journal_Kinds::learn( $kind ) : '';
			$facts['_entry']     = array( 'id' => (int) $row['id'], 'owner' => (int) $row['owner_user_id'] );
		}
		return $facts;
	}

	/**
	 * Gate one passage. Closed ⇒ passage `skipped` (no LLM), journal row graph_state skipped (once per source per request).
	 *
	 * @return array{allowed:bool,reason:string}
	 */
	public static function check_passage( array $passage ): array {
		$facts    = self::facts( $passage );
		$decision = self::decide( $facts );
		if ( ! $decision['allowed'] ) {
			static $marked = array();
			$entry = $facts['_entry'] ?? null;
			if ( is_array( $entry ) && empty( $marked[ $entry['id'] ] ) && class_exists( 'BizCity_Journal_Index' ) ) {
				$marked[ $entry['id'] ] = true;
				BizCity_Journal_Index::file( $entry['id'], $entry['owner'], array( 'learning' => array( 'graph_state' => 'skipped' ) ) );
			}
		}
		return $decision;
	}

	/** Is this notebook attached to a Guru (kg-hub notebook ⇄ character attachments)? */
	private static function guru_attached( int $notebook_id ): bool {
		global $wpdb;
		if ( $notebook_id <= 0 || ! isset( $wpdb ) || ! class_exists( 'BizCity_KG_Database' ) || ! method_exists( 'BizCity_KG_Database', 'tbl_notebook_character_attachments' ) ) {
			return false;
		}
		$tbl = BizCity_KG_Database::instance()->tbl_notebook_character_attachments();
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tbl} WHERE notebook_id = %d", $notebook_id ) ) > 0;
	}
}
