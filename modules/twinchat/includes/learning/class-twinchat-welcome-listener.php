<?php
/**
 * Bizcity Twin AI — TwinChat welcome card (thẻ chào) after a source is read.
 *
 * [2026-10-27 07:10 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.143 S143-L4 (D143-6/D143-7) — the card comes back WITHOUT the old queue, cron or PHP LLM call
 * (modules/_archived/twinchat-welcome-20261022). The cell reads the file (0.140 entry-meta extract), writes about + topics + questions through
 * `journal.meta.set`; this listener hears `bizcity_journal_entry_meta_set` and writes ONE assistant message in the notebook's chat in the shape the FE
 * already renders (kg_entities.welcome + suggestions), plus a `chat` learning event. The same source never gets two cards.
 *
 * @package Bizcity_Twin_AI
 * @subpackage TwinChat\Learning
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

final class BizCity_TwinChat_Welcome_Listener {

	private static $bound = false;

	public static function bind(): void {
		if ( self::$bound ) {
			return;
		}
		self::$bound = true;
		add_action( 'bizcity_journal_entry_meta_set', array( __CLASS__, 'on_meta_set' ), 20, 4 );
	}

	/** Markdown body of the card (the shape the old runner stored). Pure. */
	public static function compose_body( string $title, array $meta ): string {
		$lines   = array();
		$lines[] = '📚 **' . ( '' !== trim( $title ) ? trim( $title ) : 'Tài liệu mới' ) . '**';
		$about   = trim( (string) ( $meta['about'] ?? '' ) );
		if ( '' !== $about ) {
			$lines[] = '';
			$lines[] = $about;
		}
		$topics = self::clean_list( $meta['topics'] ?? array(), 6, 40 );
		if ( $topics ) {
			$lines[] = '';
			$lines[] = '**Chủ đề chính:** ' . implode( ' · ', $topics );
		}
		$qs = self::clean_list( $meta['questions'] ?? array(), 5, 200 );
		if ( $qs ) {
			$lines[] = '';
			$lines[] = '**Bạn có thể hỏi mình:**';
			foreach ( $qs as $q ) {
				$lines[] = '- ' . $q;
			}
		}
		return implode( "\n", $lines );
	}

	/** A card needs something to say: an `about` and at least one question or topic. Pure. */
	public static function worth_a_card( array $meta ): bool {
		return '' !== trim( (string) ( $meta['about'] ?? '' ) ) && ( self::clean_list( $meta['questions'] ?? array(), 5, 200 ) || self::clean_list( $meta['topics'] ?? array(), 6, 40 ) );
	}

	/** @return string[] */
	public static function clean_list( $raw, int $max, int $chars ): array {
		$out = array();
		foreach ( (array) $raw as $v ) {
			$v = is_scalar( $v ) ? trim( (string) preg_replace( '/\s+/u', ' ', (string) $v ) ) : '';
			if ( '' !== $v ) {
				$out[] = mb_substr( $v, 0, $chars );
			}
		}
		return array_slice( array_values( array_unique( $out ) ), 0, $max );
	}

	/** notebook id + kg source id of a journal row (either row shape). Pure. @return int[] [notebook_id, kg_source_id] */
	public static function link_of( array $row ): array {
		$nb  = (int) ( $row['kg']['notebook_ids'][0] ?? $row['notebook_id'] ?? 0 );
		$src = (int) ( $row['kg']['source_id'] ?? $row['kg_source_id'] ?? 0 );
		return array( $nb, $src );
	}

	/** `bizcity_journal_entry_meta_set( $entry_id, $owner, $meta, $source )` */
	public static function on_meta_set( $entry_id, $owner = 0, $meta = array(), $source = '' ): void {
		try {
			if ( ! in_array( (string) $source, array( 'cell_auto', 'cell_tool' ), true ) || ! is_array( $meta ) || ! self::worth_a_card( $meta ) ) {
				return;
			}
			if ( ! class_exists( 'BizCity_Journal_Index' ) || ! class_exists( 'BizCity_TwinChat_Database' ) ) {
				return;
			}
			$row = BizCity_Journal_Index::get( (int) $entry_id, (int) $owner );
			if ( ! is_array( $row ) ) {
				return;
			}
			list( $nb, $src ) = self::link_of( $row );
			if ( $nb <= 0 ) {
				return; // not a notebook source (a Zalo file has its own progress notifier)
			}
			self::write_card( $nb, (int) $owner, $src > 0 ? $src : (int) $entry_id, (string) ( $row['title'] ?? '' ), $meta );
		} catch ( \Throwable $e ) {
			error_log( '[twinchat-welcome] ' . $e->getMessage() );
		}
	}

	private static function write_card( int $nb, int $user_id, int $source_key, string $title, array $meta ): void {
		$db = BizCity_TwinChat_Database::instance();
		if ( self::already_welcomed( $nb, $source_key ) ) {
			return;
		}
		$session = self::latest_session( $nb, $user_id );
		if ( '' === $session ) {
			return; // no chat yet: never open a session just to say hello
		}
		$topics = self::clean_list( $meta['topics'] ?? array(), 6, 40 );
		$qs     = self::clean_list( $meta['questions'] ?? array(), 5, 200 );
		$body   = self::compose_body( $title, $meta );
		$msg_id = (int) $db->insert_message( array(
			'notebook_id' => $nb,
			'user_id'     => $user_id,
			'session_id'  => $session,
			'role'        => 'assistant',
			'content'     => $body,
			'kg_entities' => array( 'welcome' => true, 'source_id' => $source_key, 'source_title' => $title, 'key_topics' => $topics, 'suggestions' => $qs, 'learned' => array( 'entities' => 0, 'relations' => 0 ) ),
			'suggestions' => $qs, // the FE reads persisted chips as string[]
		) );
		if ( $msg_id <= 0 ) {
			return;
		}
		if ( class_exists( 'BizCity_TwinChat_Learning_Events' ) ) {
			BizCity_TwinChat_Learning_Events::instance()->push( $nb, 'chat', array(
				'message_id' => $msg_id, 'session_id' => $session, 'role' => 'assistant', 'content' => $body,
				'meta'       => array( 'kind' => 'welcome', 'source_id' => $source_key, 'source_title' => $title, 'suggestions' => $qs, 'key_topics' => $topics ),
			) );
		}
		if ( $qs && class_exists( 'BizCity_Twin_Event_Bus' ) ) {
			BizCity_Twin_Event_Bus::dispatch_v2( 'suggestion_emitted', array(
				'message_id' => (string) $msg_id, 'reason' => 'welcome', 'notebook_id' => $nb,
				'items'      => $qs, // the FE chip store takes strings
			), array( 'event_source' => 'system' ) );
		}
		// the "Twin đã học xong" bubble is redundant for a source that has its card
		set_transient( 'bc_welcomed_' . $nb . '_' . $source_key, 1, DAY_IN_SECONDS );
	}

	/** One query: a welcome message for this source exists in the notebook. */
	private static function already_welcomed( int $nb, int $source_key ): bool {
		if ( false !== get_transient( 'bc_welcomed_' . $nb . '_' . $source_key ) ) {
			return true;
		}
		global $wpdb;
		$like = '%"welcome":true%"source_id":' . $source_key . '%';
		$n    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}bizcity_webchat_messages WHERE plugin_slug = 'twinchat' AND project_id = %s AND meta LIKE %s", (string) $nb, $like ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $n > 0;
	}

	/** The newest TwinChat session of this person in this notebook ('' = none). */
	private static function latest_session( int $nb, int $user_id ): string {
		if ( class_exists( 'BizCity_WebChat_Session_State' ) ) {
			$states = BizCity_WebChat_Session_State::instance()->list_for_user( $user_id > 0 ? $user_id : null, BizCity_TwinChat_Database::PLATFORM, 1, (string) $nb, 'all' );
			if ( ! empty( $states ) ) {
				return (string) $states[0]->session_id;
			}
		}
		return '';
	}

	/** True when this learning job's source already has its card: the "Twin đã học xong" bubble is then redundant (D143-7). Pure apart from the transient. */
	public static function has_card( int $notebook_id, int $source_id ): bool {
		return $source_id > 0 && false !== get_transient( 'bc_welcomed_' . $notebook_id . '_' . $source_id );
	}

	/** A learning job finished (Learning_Notifier::notify): the card's "N khái niệm · M quan hệ" learns the real numbers (D143-7). */
	public static function on_job_done( int $notebook_id, array $job ): void {
		try {
			$sid = (int) ( $job['source_id'] ?? 0 );
			if ( ! self::has_card( $notebook_id, $sid ) || ! class_exists( 'BizCity_TwinChat_Learning_Events' ) ) {
				return;
			}
			BizCity_TwinChat_Learning_Events::instance()->push( $notebook_id, 'chat', array(
				'role' => 'system',
				'meta' => array( 'kind' => 'welcome_update', 'source_id' => $sid, 'learned' => array( 'entities' => (int) ( $job['entities_approved'] ?? 0 ), 'relations' => (int) ( $job['triplets_extracted'] ?? 0 ) ) ),
			) );
		} catch ( \Throwable $e ) {
			error_log( '[twinchat-welcome] ' . $e->getMessage() );
		}
	}
}
