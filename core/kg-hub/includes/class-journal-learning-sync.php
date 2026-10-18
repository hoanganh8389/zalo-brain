<?php
/**
 * BizCity_Journal_Learning_Sync — every learning step writes its progress onto the document's journal row
 * (PHASE-0.121 B1-1 / S121-A1, R-JOURNAL R-JN-20/21, doc 76 §2).
 * Copyright (c) 2026 Chu Hoàng Anh (Johnny Chu), registered No. 8877/2026/QTG. See NOTICE.md for authorship scope; LICENSE for terms.
 *
 * Before: `learning_status = learned` only meant "ingest returned", nobody read it, and the cell could not tell whether a
 * document could be deep-searched (T2) or only read raw (T3). Now three hooks move `learning{}` on the ONE row:
 *   - `bizcity_kg_source_embedded( source_id, scope_id, user_id, chunks )`  ⇒ level L1, text_ready, passages, embedded_at
 *   - `bizcity_twinchat_learning_job_done( job, entities )`                 ⇒ graph_state done (+ entities when the job had one source)
 *   - `bizcity_twinchat_source_ingest_error( scope_id, source_id, message )` ⇒ learning_status failed + error
 * The row is found by the web key `src:<blog>:<source_id>` or through the KG mirror (`kg_sources.origin_id` = webchat source id
 * ⇒ `kg_source_id`), which also covers Zalo captures (key `oc:…`). No row ⇒ nothing (never creates one here).
 *
 * @package Bizcity_Twin_AI
 * @since   2026-10-17
 */

// [2026-10-17 08:40 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.121 B1-1 (S121-A1) — new file.

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Journal_Learning_Sync', false ) ) {
	return;
}

final class BizCity_Journal_Learning_Sync {

	private static $hooked = false;

	public static function register(): void {
		if ( self::$hooked || ! function_exists( 'add_action' ) ) {
			return;
		}
		self::$hooked = true;
		// after the ingest listener (20) created the row for web uploads; embedded fires before after_ingest, so the
		// row may not exist yet for a NEW web upload — the listener then seeds it already at L1 (entry_for()).
		add_action( 'bizcity_kg_source_embedded', array( __CLASS__, 'on_embedded' ), 30, 4 );
		add_action( 'bizcity_twinchat_learning_job_done', array( __CLASS__, 'on_job_done' ), 30, 2 );
		add_action( 'bizcity_twinchat_source_ingest_error', array( __CLASS__, 'on_ingest_error' ), 30, 3 );
	}

	// ── pure ─────────────────────────────────────────────────────────────

	/**
	 * The `file()` patch + learning_status for one step. Pure.
	 *
	 * @param string $stage embedded|graph_done|failed
	 * @param array  $args  {chunks?, entities?, single_source?, now?, message?}
	 * @return array{patch:array,learning_status:string,learning_error:string}
	 */
	public static function step( string $stage, array $args = array() ): array {
		$now = (string) ( $args['now'] ?? gmdate( 'c' ) );
		switch ( $stage ) {
			case 'embedded':
				return array(
					'patch'           => array( 'learning' => array( 'level' => 'L1', 'text_ready' => true, 'passages' => max( 0, (int) ( $args['chunks'] ?? 0 ) ), 'embedded_at' => $now ) ),
					'learning_status' => 'learned',
					'learning_error'  => '',
				);
			case 'graph_done':
				$l = array( 'graph_state' => 'done' );
				if ( ! empty( $args['single_source'] ) ) {
					$l['entities'] = max( 0, (int) ( $args['entities'] ?? 0 ) ); // a notebook-wide job cannot attribute entities to one row
				}
				return array( 'patch' => array( 'learning' => $l ), 'learning_status' => 'learned', 'learning_error' => '' );
			case 'failed':
				return array(
					'patch'           => array(),
					'learning_status' => 'failed',
					'learning_error'  => mb_substr( sanitize_text_field( (string) ( $args['message'] ?? '' ) ), 0, 480 ),
				);
		}
		return array( 'patch' => array(), 'learning_status' => '', 'learning_error' => '' );
	}

	/**
	 * Merge a learning patch into the row's current `learning{}` — never moves the level DOWN (an L2 command stays L2 when a
	 * re-embed reports L1) and never forgets a known graph_state. Pure.
	 */
	public static function merge( array $current, array $patch ): array {
		$order = array( 'L0' => 0, 'L1' => 1, 'L2' => 2, 'L3' => 3 );
		$out   = array_merge( $current, $patch );
		if ( isset( $current['level'], $patch['level'] ) && ( $order[ $current['level'] ] ?? 0 ) > ( $order[ $patch['level'] ] ?? 0 ) ) {
			$out['level'] = $current['level'];
		}
		if ( isset( $current['graph_state'] ) && 'done' === $current['graph_state'] && isset( $patch['graph_state'] ) && 'none' === $patch['graph_state'] ) {
			$out['graph_state'] = 'done';
		}
		return $out;
	}

	// ── hooks ────────────────────────────────────────────────────────────

	public static function on_embedded( $source_id, $scope_id, $user_id, $chunks ): void {
		self::apply( (int) $source_id, self::step( 'embedded', array( 'chunks' => (int) $chunks ) ) );
	}

	/** @param array $job learning job row {notebook_id, source_id?} · @param int $entities approved entities of this job */
	public static function on_job_done( $job, $entities ): void {
		if ( ! is_array( $job ) ) {
			return;
		}
		$source_id = (int) ( $job['source_id'] ?? 0 );
		if ( $source_id > 0 ) {
			self::apply( $source_id, self::step( 'graph_done', array( 'entities' => (int) $entities, 'single_source' => true ) ) );
			return;
		}
		// a coalesced (notebook-wide) job: every learned row of that notebook now has its graph pass done
		$nb = (int) ( $job['notebook_id'] ?? 0 );
		if ( $nb <= 0 || ! class_exists( 'BizCity_Journal_Database' ) ) {
			return;
		}
		global $wpdb;
		$table = BizCity_Journal_Database::instance()->get_table();
		$ids   = $wpdb->get_results( $wpdb->prepare( "SELECT id, owner_user_id FROM {$table} WHERE notebook_id = %d AND learning_status = 'learned' AND status NOT IN ('archived','void') LIMIT 200", $nb ), ARRAY_A ) ?: array();
		$step  = self::step( 'graph_done', array( 'single_source' => false ) );
		foreach ( $ids as $r ) {
			self::write( (int) $r['id'], (int) $r['owner_user_id'], $step );
		}
	}

	public static function on_ingest_error( $scope_id, $source_id, $message ): void {
		self::apply( (int) $source_id, self::step( 'failed', array( 'message' => (string) $message ) ) );
	}

	// ── internals ────────────────────────────────────────────────────────

	/** Find the row of a webchat source and write the step onto it. */
	private static function apply( int $source_id, array $step ): void {
		$row = self::row_for_source( $source_id );
		if ( $row ) {
			self::write( (int) $row['id'], (int) $row['owner_user_id'], $step );
		}
	}

	private static function write( int $id, int $owner, array $step ): void {
		if ( ! class_exists( 'BizCity_Journal_Index' ) || ! class_exists( 'BizCity_Journal_Database' ) ) {
			return;
		}
		$db = BizCity_Journal_Database::instance();
		if ( ! empty( $step['patch']['learning'] ) ) {
			$cur  = BizCity_Journal_Index::get( $id, $owner );
			$have = is_array( $cur['learning'] ?? null ) ? $cur['learning'] : array();
			unset( $have['status'], $have['error'], $have['allowed'] );
			BizCity_Journal_Index::file( $id, $owner, array( 'learning' => self::merge( $have, $step['patch']['learning'] ) ) );
		}
		if ( '' !== $step['learning_status'] ) {
			$db->mark_learning_projection( $id, $owner, array( 'learning_status' => $step['learning_status'], 'learning_error' => $step['learning_error'] ) );
		}
	}

	/** The journal row for a webchat source: `src:<blog>:<id>` first, else through the KG mirror row. */
	private static function row_for_source( int $source_id ): ?array {
		if ( $source_id <= 0 || ! class_exists( 'BizCity_Journal_Database' ) ) {
			return null;
		}
		$db   = BizCity_Journal_Database::instance();
		$blog = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
		$row  = $db->get_by_idempotency_key( 'src:' . $blog . ':' . $source_id );
		if ( $row ) {
			return $row;
		}
		if ( ! class_exists( 'BizCity_KG_Database' ) ) {
			return null;
		}
		global $wpdb;
		$kg = (int) $wpdb->get_var( $wpdb->prepare(
			'SELECT id FROM ' . BizCity_KG_Database::instance()->tbl_sources() . " WHERE origin_plugin = 'twinchat' AND origin_id = %d ORDER BY id DESC LIMIT 1",
			$source_id
		) );
		return $kg > 0 ? $db->get_by_kg_source_id( $kg ) : null;
	}
}
