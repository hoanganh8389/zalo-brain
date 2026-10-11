<?php
/**
 * BizCity_Site_Content_MCP_Service — `site.content.search`: published posts, pages, public CPTs and the media library, each row with its
 * https image (media-out@1), so the cell can SHOW a post / picture in chat (send_site_media) instead of pasting a link.
 *
 * Read-only, published content only (what any visitor can already see); never drafts, private posts, user data or file paths.
 * The cell collects `image_url` of the rows into the turn allowlist (zalo-hub/src/agent/tools/site-media.ts).
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\MCP\Actions
 * @since      PHASE-0.98 (2026-10-11)
 */

defined( 'ABSPATH' ) || exit;

// [2026-10-11 Johnny Chu - Chu Hoàng Anh] PHASE-0.98 S98-M7 — owner request: "search_products, search posts hoặc lấy từ CPT, thư viện ảnh có thể lấy thành thạo và gửi về".
final class BizCity_Site_Content_MCP_Service {

	const LIMIT_MAX   = 10;
	const EXCERPT_MAX = 240;
	/** post types never offered even when public (Woo products have commerce.search_products; attachments go through type=media) */
	const SKIP_TYPES = array( 'attachment', 'product', 'product_variation', 'revision', 'nav_menu_item', 'wp_block', 'wp_template', 'wp_navigation' );

	/** @var callable|null test seam: fn(array $query_args): array<int,object> (WP_Post-like rows) */
	public static $query = null;
	/** @var callable|null test seam: fn(int $attachment_id): string */
	public static $image = null;

	public static function init(): void {
		add_action( 'bizcity_mcp_register_tools', array( __CLASS__, 'register_tools' ) );
	}

	public static function register_tools(): void {
		$S = 'BizCity_MCP_Tool_Registry';
		// @mcp bizcity-mcp-standard@1 tool site.content.search
		BizCity_MCP_Tool_Registry::register( 'site.content.search', array(
			'title'          => 'Tìm bài viết, trang, nội dung và ảnh của website',
			'description'    => 'Tìm nội dung ĐÃ ĐĂNG của website theo từ khoá: bài viết (type=post), trang (page), loại nội dung riêng (tên post type công khai), hoặc ảnh trong thư viện (type=media). Mỗi dòng có tiêu đề, link, tóm tắt, ngày và image_url (ảnh đại diện / ảnh) để gửi ảnh vào chat bằng send_site_media. Không có bản nháp hay nội dung riêng tư. Sản phẩm Woo: dùng commerce.search_products.',
			'input_schema'   => array( 'type' => 'object', 'properties' => array(
				'q'     => array( 'type' => 'string', 'maxLength' => 120 ),
				'type'  => array( 'type' => 'string', 'maxLength' => 40, 'default' => 'any' ),
				'limit' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => self::LIMIT_MAX, 'default' => 5 ),
			) ),
			'output_schema'  => $S::envelope_schema( array(
				'items' => array( 'type' => 'array' ),
				'types' => array( 'type' => 'array' ),
				'as_of' => array( 'type' => 'string' ),
			), array( 'items' ) ),
			'read_only'      => true,
			'destructive'    => false,
			'idempotent'     => true,
			'required_scope' => 'brain.read',
			'handler'        => array( __CLASS__, 'search' ),
			'mode'           => 'notebook',
			'scopes'         => array( 'brain.read' ),
			'confirm'        => 'never',
			// PHASE-0.98 Q98-M2 (chủ: mở cho khách) — customers reach it through the Guru (guru_public); tool-registry@1 rule: customer ⇒ scope thread
			'roles'          => array( 'owner', 'staff', 'customer' ),
			'scope'          => 'thread',
			'llm_alias'      => 'site_content_search',
			'fallback_pack'  => null,
			'since'          => '0.98.0',
		) );
	}

	/** Public post types the tool may search (post, page + public CPTs minus SKIP_TYPES). */
	public static function searchable_types(): array {
		$types = function_exists( 'get_post_types' ) ? array_values( (array) get_post_types( array( 'public' => true ), 'names' ) ) : array( 'post', 'page' );
		return array_values( array_diff( array_map( 'strval', $types ), self::SKIP_TYPES ) );
	}

	public static function search( array $args, array $ctx ) {
		$q     = trim( sanitize_text_field( (string) ( $args['q'] ?? '' ) ) );
		$type  = sanitize_key( (string) ( $args['type'] ?? 'any' ) );
		$limit = max( 1, min( self::LIMIT_MAX, (int) ( $args['limit'] ?? 5 ) ) );
		$types = self::searchable_types();
		$media = in_array( $type, array( 'media', 'image', 'attachment' ), true );
		if ( ! $media && 'any' !== $type && '' !== $type && ! in_array( $type, $types, true ) ) {
			return new WP_Error( BizCity_MCP_Error::QUERY_INVALID, 'Loại nội dung "' . $type . '" không có hoặc không công khai.', array( 'status' => 400, 'hint' => 'Dùng một trong: ' . implode( ', ', $types ) . ', media.', 'types' => $types ) );
		}
		$query = $media
			? array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_mime_type' => 'image', 's' => $q, 'posts_per_page' => $limit, 'orderby' => '' !== $q ? 'relevance' : 'date', 'no_found_rows' => true )
			: array( 'post_type' => ( 'any' === $type || '' === $type ) ? $types : $type, 'post_status' => 'publish', 'has_password' => false, 's' => $q, 'posts_per_page' => $limit, 'orderby' => '' !== $q ? 'relevance' : 'date', 'no_found_rows' => true, 'ignore_sticky_posts' => true );
		$rows = is_callable( self::$query ) ? (array) call_user_func( self::$query, $query ) : ( function_exists( 'get_posts' ) ? (array) get_posts( $query + array( 'suppress_filters' => false ) ) : array() );
		$items = array();
		foreach ( $rows as $p ) {
			if ( ! is_object( $p ) || empty( $p->ID ) ) {
				continue;
			}
			$id    = (int) $p->ID;
			$img   = $media ? self::image_url( $id ) : self::image_url( function_exists( 'get_post_thumbnail_id' ) ? (int) get_post_thumbnail_id( $id ) : 0 );
			$items[] = array(
				'ref'       => ( $media ? 'media:' : 'post:' ) . $id,
				'type'      => $media ? 'media' : (string) ( $p->post_type ?? 'post' ),
				'title'     => wp_strip_all_tags( (string) ( $p->post_title ?? '' ) ),
				'link'      => $media ? $img : ( function_exists( 'get_permalink' ) ? (string) get_permalink( $id ) : '' ),
				'excerpt'   => self::excerpt( (string) ( $p->post_excerpt ?? '' ) ?: (string) ( $p->post_content ?? '' ) ),
				'date'      => (string) ( $p->post_date ?? '' ),
				'image_url' => $img,
			);
		}
		return array( 'items' => $items, 'types' => array_merge( $types, array( 'media' ) ), 'as_of' => gmdate( 'c' ) );
	}

	/** https URL of an attachment ('large'); http ⇒ https; '' when none. */
	private static function image_url( int $attachment_id ): string {
		if ( $attachment_id <= 0 ) {
			return '';
		}
		$u = is_callable( self::$image ) ? (string) call_user_func( self::$image, $attachment_id ) : ( function_exists( 'wp_get_attachment_image_url' ) ? (string) wp_get_attachment_image_url( $attachment_id, 'large' ) : '' );
		return 0 === stripos( $u, 'http://' ) ? 'https://' . substr( $u, 7 ) : ( 0 === stripos( $u, 'https://' ) ? $u : '' );
	}

	private static function excerpt( string $text ): string {
		$t = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( strip_shortcodes_safe( $text ) ) ) );
		return function_exists( 'mb_substr' ) && mb_strlen( $t ) > self::EXCERPT_MAX ? mb_substr( $t, 0, self::EXCERPT_MAX - 1 ) . '…' : $t;
	}
}

if ( ! function_exists( 'strip_shortcodes_safe' ) ) {
	/** strip_shortcodes() when WordPress is loaded; a plain [tag] stripper otherwise (unit tests). */
	function strip_shortcodes_safe( string $text ): string {
		return function_exists( 'strip_shortcodes' ) ? strip_shortcodes( $text ) : (string) preg_replace( '/\[[^\]]{1,200}\]/', '', $text );
	}
}

BizCity_Site_Content_MCP_Service::init();
