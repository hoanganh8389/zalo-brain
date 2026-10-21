<?php
/**
 * BizCity_Journal_Layer_Rows — the CONTAINERS of the brain get their own journal row (R-BRAIN-SKELETON BS-2, Q126-4/5):
 *   a notebook ⇒ one `brain.notebook` row (NB-), key `nb:<blog>:<notebook id>`, `notebook_id` column = the notebook;
 *   a sheet the person made (twin_sheet CPT, 0.123 W3) ⇒ one `sheet.define` row (SO-), key `so:<blog>:<post id>` (built-in sheets have none).
 * The rows are INDEX rows only (BS-1): the notebook's sources / chunks stay in KG-Hub, the sheet's definition stays in its post.
 * Written on save (hooks), voided on delete / trash, and a lazy reconcile fills the rows of containers made before this file shipped.
 *
 * Biz Central Brain — Johnny Chu (Chu Hoàng Anh).
 *
 * [2026-10-21 11:14 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.126 S126-B3 (doc 00 D126-4/5, doc 90 R116-02) — new file.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\KG_Hub
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Journal_Layer_Rows', false ) ) {
	return;
}

final class BizCity_Journal_Layer_Rows {

	const RECONCILE_TTL = DAY_IN_SECONDS;
	const RECONCILE_MAX = 200;

	private static $hooked = false;

	public static function register(): void {
		if ( self::$hooked ) {
			return;
		}
		self::$hooked = true;
		add_action( 'bizcity_kg_notebook_saved', array( __CLASS__, 'on_notebook_saved' ), 20, 1 );
		add_action( 'bizcity_kg_notebook_deleted', array( __CLASS__, 'on_notebook_deleted' ), 20, 1 );
		add_action( 'save_post_twin_sheet', array( __CLASS__, 'on_sheet_saved' ), 20, 2 );
		add_action( 'wp_trash_post', array( __CLASS__, 'on_post_gone' ), 20, 1 );
		add_action( 'before_delete_post', array( __CLASS__, 'on_post_gone' ), 20, 1 );
	}

	/* ── pure ─────────────────────────────────────────────────────────── */

	/** journal-entry@1.2 input of one notebook (null when unusable). @param array $nb {id, name, description, owner_id, notebook_scope} */
	public static function notebook_entry( array $nb, int $blog ): ?array {
		$id    = (int) ( $nb['id'] ?? 0 );
		$owner = (int) ( $nb['owner_id'] ?? 0 );
		if ( $id <= 0 || $owner <= 0 ) {
			return null;
		}
		$name  = trim( (string) ( $nb['name'] ?? '' ) );
		return array(
			'owner_user_id'   => $owner,
			'kind'            => 'brain.notebook',
			'title'           => '' !== $name ? mb_substr( $name, 0, 200 ) : 'Notebook #' . $id,
			'summary'         => mb_substr( trim( wp_strip_all_tags( (string) ( $nb['description'] ?? '' ) ) ), 0, 300 ),
			'source_type'     => 'plugin',
			'source_ref'      => 'kg_notebook:' . $id,
			'idempotency_key' => 'nb:' . $blog . ':' . $id,
			'notebook_id'     => $id,
			'origin'          => array( 'surface' => 'plugin:kg-hub', 'ref' => 'kg_notebook:' . $id ),
			'card'            => array( 'notebook_id' => $id, 'name' => $name, 'scope' => (string) ( $nb['notebook_scope'] ?? 'personal' ) ), // no counts: they would go stale (the notebook owns them)
			'by'              => array( 'role' => 'owner' ),
		);
	}

	/** journal-entry@1.2 input of one person-made sheet (null when not a usable definition). */
	public static function sheet_entry( int $post_id, int $owner, string $title, string $content, int $blog ): ?array {
		$def = json_decode( $content, true );
		if ( $post_id <= 0 || $owner <= 0 || ! is_array( $def ) || ! is_array( $def['fields'] ?? null ) ) {
			return null;
		}
		$title = trim( $title );
		return array(
			'owner_user_id'   => $owner,
			'kind'            => 'sheet.define',
			'title'           => '' !== $title ? mb_substr( $title, 0, 200 ) : 'Sổ #' . $post_id,
			'source_type'     => 'plugin',
			'source_ref'      => 'twin_sheet:' . $post_id,
			'idempotency_key' => 'so:' . $blog . ':' . $post_id,
			'origin'          => array( 'surface' => 'plugin:twin-sheet', 'ref' => 'twin_sheet:' . $post_id ),
			'card'            => array( 'sheet_key' => 'data.s' . $post_id, 'display_name' => $title, 'origin' => 'custom', 'schema_version' => (int) ( $def['schema_version'] ?? 1 ), 'kind' => (string) ( $def['kind'] ?? '' ), 'fields' => count( $def['fields'] ) ),
			'by'              => array( 'role' => 'owner' ),
		);
	}

	/* ── write ────────────────────────────────────────────────────────── */

	/** Insert, or refresh title / summary / card of, the row of one container. */
	public static function upsert( array $entry ): void {
		if ( ! class_exists( 'BizCity_Journal_Index' ) ) {
			return;
		}
		$owner = (int) $entry['owner_user_id'];
		$have  = BizCity_Journal_Index::get_by_idempotency_key( (string) $entry['idempotency_key'], $owner );
		if ( null === $have ) {
			BizCity_Journal_Index::add( $entry );
			return;
		}
		if ( 'void' === (string) ( $have['state'] ?? '' ) ) {
			BizCity_Journal_Index::set_state( (int) $have['entry_id'], $owner, 'captured' );
		}
		$card = is_array( $have['card'] ?? null ) ? $have['card'] : array();
		if ( $card != $entry['card'] || (string) ( $have['title'] ?? '' ) !== (string) $entry['title'] ) { // phpcs:ignore Universal.Operators.StrictComparisons -- array compare by value
			BizCity_Journal_Index::file( (int) $have['entry_id'], $owner, array( 'card' => $entry['card'], 'summary' => (string) ( $entry['summary'] ?? '' ) ) );
			if ( (string) ( $have['title'] ?? '' ) !== (string) $entry['title'] && class_exists( 'BizCity_Journal_Database' ) ) {
				BizCity_Journal_Database::instance()->update( (int) $have['entry_id'], $owner, array( 'title' => (string) $entry['title'] ) );
			}
		}
	}

	private static function void_key( string $key ): void {
		if ( ! class_exists( 'BizCity_Journal_Database' ) || ! class_exists( 'BizCity_Journal_Index' ) ) {
			return;
		}
		$row = BizCity_Journal_Database::instance()->get_by_idempotency_key( $key );
		if ( $row && 'void' !== (string) $row['status'] ) {
			BizCity_Journal_Index::set_state( (int) $row['id'], (int) $row['owner_user_id'], 'void' );
		}
	}

	private static function blog(): int {
		return function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
	}

	/* ── hooks ────────────────────────────────────────────────────────── */

	public static function on_notebook_saved( $nb ): void {
		if ( is_array( $nb ) ) {
			$e = self::notebook_entry( $nb, self::blog() );
			if ( null !== $e ) {
				self::upsert( $e );
			}
		}
	}

	public static function on_notebook_deleted( $id ): void {
		self::void_key( 'nb:' . self::blog() . ':' . (int) $id );
	}

	public static function on_sheet_saved( $post_id, $post = null ): void {
		if ( ! is_object( $post ) || 'twin_sheet' !== (string) $post->post_type || 'auto-draft' === (string) $post->post_status || ( function_exists( 'wp_is_post_revision' ) && wp_is_post_revision( (int) $post_id ) ) ) {
			return;
		}
		if ( 'trash' === (string) $post->post_status ) {
			self::void_key( 'so:' . self::blog() . ':' . (int) $post_id );
			return;
		}
		$e = self::sheet_entry( (int) $post_id, (int) $post->post_author, (string) $post->post_title, (string) $post->post_content, self::blog() );
		if ( null !== $e ) {
			self::upsert( $e );
		}
	}

	public static function on_post_gone( $post_id ): void {
		if ( function_exists( 'get_post_type' ) && 'twin_sheet' === get_post_type( (int) $post_id ) ) {
			self::void_key( 'so:' . self::blog() . ':' . (int) $post_id );
		}
	}

	/**
	 * Lazy reconcile, at most once a day per owner: the containers made before this file shipped get their row. Bounded (≤ 200 each).
	 * Called by the ledger overview (GET documents/overview) — the person sees the rows the first time they open the Sổ cái.
	 */
	public static function reconcile_owner( int $owner ): int {
		if ( $owner <= 0 || ! function_exists( 'get_transient' ) ) {
			return 0;
		}
		$tk = 'bizcity_journal_layer_rows_' . self::blog() . '_' . $owner;
		if ( get_transient( $tk ) ) {
			return 0;
		}
		set_transient( $tk, 1, self::RECONCILE_TTL );
		$n = 0;
		global $wpdb;
		if ( class_exists( 'BizCity_KG_Database' ) && isset( $wpdb ) ) {
			$tbl  = BizCity_KG_Database::instance()->tbl_notebooks();
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, name, description, owner_id, notebook_scope FROM {$tbl} WHERE owner_id = %d ORDER BY id DESC LIMIT %d", $owner, self::RECONCILE_MAX ), ARRAY_A ) ?: array(); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from the KG installer
			foreach ( $rows as $r ) {
				$e = self::notebook_entry( $r, self::blog() );
				if ( null !== $e && null === BizCity_Journal_Index::get_by_idempotency_key( (string) $e['idempotency_key'], $owner ) ) {
					BizCity_Journal_Index::add( $e );
					$n++;
				}
			}
		}
		if ( function_exists( 'get_posts' ) ) {
			foreach ( get_posts( array( 'post_type' => 'twin_sheet', 'post_status' => 'publish', 'author' => $owner, 'numberposts' => self::RECONCILE_MAX, 'suppress_filters' => true ) ) as $p ) {
				$e = self::sheet_entry( (int) $p->ID, (int) $p->post_author, (string) $p->post_title, (string) $p->post_content, self::blog() );
				if ( null !== $e && null === BizCity_Journal_Index::get_by_idempotency_key( (string) $e['idempotency_key'], $owner ) ) {
					BizCity_Journal_Index::add( $e );
					$n++;
				}
			}
		}
		return $n;
	}
}
