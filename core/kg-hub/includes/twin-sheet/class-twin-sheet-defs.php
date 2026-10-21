<?php
/**
 * Where the definitions of the person's OWN sheets live: the CPT `twin_sheet` (PHASE-0.123 D123-2, W3).
 *
 *   BizCity_Twin_Sheet_Defs      the interface the registry / service use (tests swap in an in-memory fake)
 *   BizCity_Twin_Sheet_Cpt_Defs  the WordPress implementation: one post per sheet
 *
 * One post = one DEFINITION (name, columns, views). It never holds a number or a name of a customer: the rows are journal rows
 * (D123-1). The post is not public in any way — not queryable, not in search, not in REST, no UI, no export. The structure JSON lives in
 * `post_content`, so WordPress revisions keep older structures for free (doc 34 §2); the stable key is `data.s<post ID>`.
 *
 * Cache contract (R-CACHE): the list of all definitions is cached in BizCity_Cache group `journal` (key per blog); every insert / update
 * flushes that group. No private in-process cache array. No DB or cache call at file scope: everything happens inside methods.
 *
 * Biz Central Brain — Johnny Chu (Chu Hoàng Anh).
 *
 * [2026-10-19 11:45 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.123 W3 (B11) — new file.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\KG_Hub\Twin_Sheet
 */

defined( 'ABSPATH' ) || exit;

if ( ! interface_exists( 'BizCity_Twin_Sheet_Defs', false ) ) {
	interface BizCity_Twin_Sheet_Defs {

		/** @return array[] every active sheet of every person on this blog: {id, key, owner, title, icon, folder, status, def} */
		public function all(): array;

		/** @return array|null one record by key (`data.s<ID>`), active only */
		public function get_by_key( string $key );

		public function count_for_owner( int $owner ): int;

		/**
		 * @param array $record {title, icon, folder, def}
		 * @return int|WP_Error the new id
		 */
		public function insert( int $owner, array $record );

		/**
		 * @param array $patch {title?, icon?, folder?, status? ('active'|'trash'), def?}
		 * @return true|WP_Error
		 */
		public function update( int $id, array $patch );
	}
}

if ( ! interface_exists( 'BizCity_Twin_Sheet_Version_Store', false ) ) {
	/** Optional door of a Defs store: the structure versions of one sheet (PHASE-0.123 W3). */
	interface BizCity_Twin_Sheet_Version_Store {
		/** @return array[] stored version records, oldest first */
		public function versions( int $id ): array;

		/** @param array[] $versions oldest first */
		public function save_versions( int $id, array $versions ): bool;
	}
}

if ( ! class_exists( 'BizCity_Twin_Sheet_Cpt_Defs', false ) ) {
	final class BizCity_Twin_Sheet_Cpt_Defs implements BizCity_Twin_Sheet_Defs, BizCity_Twin_Sheet_Version_Store {

		const POST_TYPE = 'twin_sheet';
		const LIMIT     = 500;

		/** Hooked on `init` by BizCity_Twin_Sheet_API::init(). Private on every side: no URL, no search, no REST, no UI, no export. */
		public static function register_post_type(): void {
			if ( post_type_exists( self::POST_TYPE ) ) {
				return;
			}
			register_post_type( self::POST_TYPE, array(
				'labels'              => array( 'name' => 'Sổ (Twin Sheet)', 'singular_name' => 'Sổ' ),
				'public'              => false,
				'publicly_queryable'  => false,
				'exclude_from_search' => true,
				'show_ui'             => false,
				'show_in_menu'        => false,
				'show_in_nav_menus'   => false,
				'show_in_rest'        => false,
				'rewrite'             => false,
				'query_var'           => false,
				'can_export'          => false,
				'has_archive'         => false,
				'supports'            => array( 'title', 'editor', 'revisions' ),
				'capability_type'     => 'post',
				'map_meta_cap'        => true,
			) );
		}

		private static function cache_key(): string {
			return 'twinsheet_defs_' . (int) get_current_blog_id();
		}

		private static function flush(): void {
			if ( class_exists( 'BizCity_Cache' ) && class_exists( 'BizCity_Journal_Database' ) ) {
				BizCity_Cache::flush_group( BizCity_Journal_Database::CACHE_GROUP );
			}
		}

		/** @return array|null record from a WP_Post (null when the post is not a usable definition) */
		private static function to_record( $p ) {
			if ( ! $p || self::POST_TYPE !== $p->post_type ) {
				return null;
			}
			$def = json_decode( (string) $p->post_content, true );
			if ( ! is_array( $def ) || ! is_array( $def['fields'] ?? null ) ) {
				return null;
			}
			return array(
				'id'     => (int) $p->ID,
				'key'    => 'data.s' . (int) $p->ID,
				'owner'  => (int) $p->post_author,
				'title'  => (string) $p->post_title,
				'icon'   => (string) get_post_meta( $p->ID, '_twin_icon', true ),
				'folder' => (string) get_post_meta( $p->ID, '_twin_folder', true ),
				'status' => 'trash' === $p->post_status ? 'trash' : 'active',
				'def'    => $def,
			);
		}

		public function all(): array {
			$hit = class_exists( 'BizCity_Cache' ) && class_exists( 'BizCity_Journal_Database' ) ? BizCity_Cache::get( BizCity_Journal_Database::CACHE_GROUP, self::cache_key() ) : false;
			if ( is_array( $hit ) ) {
				return $hit;
			}
			$out   = array();
			$posts = get_posts( array(
				'post_type'        => self::POST_TYPE,
				'post_status'      => 'private',
				'numberposts'      => self::LIMIT,
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'suppress_filters' => true,
				'no_found_rows'    => true,
			) );
			foreach ( (array) $posts as $p ) {
				$r = self::to_record( $p );
				if ( $r ) {
					$out[] = $r;
				}
			}
			if ( class_exists( 'BizCity_Cache' ) && class_exists( 'BizCity_Journal_Database' ) ) {
				BizCity_Cache::set( BizCity_Journal_Database::CACHE_GROUP, self::cache_key(), $out, 300 );
			}
			return $out;
		}

		public function get_by_key( string $key ) {
			foreach ( $this->all() as $r ) {
				if ( $r['key'] === $key ) {
					return $r;
				}
			}
			return null;
		}

		public function count_for_owner( int $owner ): int {
			$n = 0;
			foreach ( $this->all() as $r ) {
				if ( $r['owner'] === $owner ) {
					$n++;
				}
			}
			return $n;
		}

		public function insert( int $owner, array $record ) {
			$id = wp_insert_post( wp_slash( array(
				'post_type'    => self::POST_TYPE,
				'post_status'  => 'private',
				'post_author'  => $owner,
				'post_title'   => (string) $record['title'],
				'post_content' => wp_json_encode( $record['def'], JSON_UNESCAPED_UNICODE ),
			) ), true );
			if ( is_wp_error( $id ) || (int) $id <= 0 ) {
				return BizCity_Twin_Sheet_Schema::error( 'save_failed', 'Chưa tạo được sổ.', 'Thử lại sau ít phút.', 500 );
			}
			update_post_meta( $id, '_twin_sheet_key', 'data.s' . (int) $id );
			update_post_meta( $id, '_twin_schema_version', (int) ( $record['def']['schema_version'] ?? 1 ) );
			update_post_meta( $id, '_twin_icon', (string) ( $record['icon'] ?? '' ) );
			update_post_meta( $id, '_twin_folder', (string) ( $record['folder'] ?? '' ) );
			self::flush();
			return (int) $id;
		}

		// [2026-10-21 08:30 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.123 W3 — structure versions live in post meta (compact snapshots), not in post_content
		public function versions( int $id ): array {
			$p = get_post( $id );
			if ( ! $p || self::POST_TYPE !== $p->post_type ) {
				return array();
			}
			$raw = json_decode( (string) get_post_meta( $id, '_twin_versions', true ), true );
			return is_array( $raw ) ? array_values( array_filter( $raw, 'is_array' ) ) : array();
		}

		public function save_versions( int $id, array $versions ): bool {
			$p = get_post( $id );
			if ( ! $p || self::POST_TYPE !== $p->post_type ) {
				return false;
			}
			update_post_meta( $id, '_twin_versions', wp_slash( wp_json_encode( array_values( $versions ), JSON_UNESCAPED_UNICODE ) ) );
			if ( ! empty( $versions ) ) {
				update_post_meta( $id, '_twin_schema_version', (int) end( $versions )['v'] );
			}
			return true;
		}

		/** Filter `wp_revisions_to_keep`: WordPress revisions of a sheet post stay on as a second net (20, Q123-13). */
		public static function revisions_to_keep( $num, $post ) {
			return is_object( $post ) && self::POST_TYPE === ( $post->post_type ?? '' ) ? 20 : $num;
		}

		public function update( int $id, array $patch ) {
			$p = get_post( $id );
			if ( ! $p || self::POST_TYPE !== $p->post_type ) {
				return BizCity_Twin_Sheet_Schema::error( 'sheet_not_found', 'Không tìm thấy sổ này.', 'Mở danh sách sổ và chọn lại.', 404 );
			}
			$post = array( 'ID' => $id );
			if ( isset( $patch['title'] ) ) {
				$post['post_title'] = (string) $patch['title'];
			}
			if ( isset( $patch['def'] ) ) {
				$post['post_content'] = wp_json_encode( $patch['def'], JSON_UNESCAPED_UNICODE );
			}
			if ( isset( $patch['status'] ) ) {
				$post['post_status'] = 'trash' === $patch['status'] ? 'trash' : 'private';
			}
			if ( count( $post ) > 1 ) {
				$r = wp_update_post( wp_slash( $post ), true );
				if ( is_wp_error( $r ) ) {
					return BizCity_Twin_Sheet_Schema::error( 'save_failed', 'Chưa lưu được thay đổi.', 'Thử lại sau ít phút.', 500 );
				}
			}
			foreach ( array( 'icon' => '_twin_icon', 'folder' => '_twin_folder' ) as $k => $meta ) {
				if ( isset( $patch[ $k ] ) ) {
					update_post_meta( $id, $meta, (string) $patch[ $k ] );
				}
			}
			self::flush();
			return true;
		}
	}
}
