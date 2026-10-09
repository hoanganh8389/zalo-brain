<?php
/**
 * BizCity_Commerce_Brain_MCP_Service — read-only WooCommerce catalog,
 * order, and customer tools for MCP Brain.
 *
 * Wraps the canonical WooCommerce CRUD API only (`wc_get_products()`,
 * `wc_get_orders()`, `WP_User_Query` with the `customer` role WooCommerce
 * assigns automatically). No new SQL, no duplicate WooCommerce data layer.
 * Every method degrades explicitly (`_degraded:true`) when WooCommerce is
 * not active instead of throwing, matching BizCity_Business_MCP_Service.
 *
 * PII note: order/customer rows can contain billing name/phone/address.
 * BizCity_MCP_Tool_Registry::write_audit() only persists response `data`
 * *keys*, never values, so this is safe under the existing audit contract
 * — no additional redaction needed here.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\MCP\Brain
 * @since      2026-07-30 (PHASE-0.54-MCP Wave R)
 */

defined( 'ABSPATH' ) || exit;

// [2026-07-30 Johnny Chu] PHASE-0.54-MCP Wave R — read-only WooCommerce bridge (products, orders, customers).
final class BizCity_Commerce_Brain_MCP_Service {

	private static $instance = null;

	// [2026-10-09 03:18 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.95-S95-F3/G1 — consult-result@1 of commerce.search_products.
	const CONSULT_CONTRACT   = 'consult-result@1.0.0';
	const CONSULT_MAX_ITEMS  = 5;
	const CONSULT_CANDIDATES = 20;
	const CONSULT_MAX_BYTES  = 3072;
	const CONSULT_SHORT      = 200;
	const CONSULT_LOW_STOCK  = 5;

	/**
	 * Test seams (PHASE-0.95): consult_products(query{q, cat_ids, limit}): list<row> · product_groups(): list<{id, name, parent}>
	 * · now(): int. A row = {id, name, short, status, visibility, password, categories[], category_ids[], attrs{}, price,
	 * regular_price, stock, total_sales, rating, image_url, gallery[], link}.
	 *
	 * @var array<string,callable>
	 */
	public static $readers = array();

	public static function instance() {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function list_products( array $args, array $ctx ) {
		if ( ! function_exists( 'wc_get_products' ) ) {
			return array( '_degraded' => true, 'reason' => 'woocommerce_unavailable', 'items' => array(), 'total' => 0 );
		}
		$limit  = isset( $args['limit'] ) ? max( 1, min( 100, (int) $args['limit'] ) ) : 20;
		$page   = isset( $args['page'] ) ? max( 1, (int) $args['page'] ) : 1;
		$query_args = array(
			'limit'    => $limit,
			'page'     => $page,
			'paginate' => true,
			'return'   => 'objects',
			'orderby'  => 'date',
			'order'    => 'DESC',
		);
		if ( ! empty( $args['search'] ) ) {
			$query_args['s'] = sanitize_text_field( (string) $args['search'] );
		}
		if ( ! empty( $args['status'] ) ) {
			$query_args['status'] = sanitize_key( (string) $args['status'] );
		} else {
			$query_args['status'] = array( 'publish', 'draft' );
		}
		if ( ! empty( $args['category'] ) ) {
			$query_args['category'] = array( sanitize_title( (string) $args['category'] ) );
		}
		$result   = wc_get_products( $query_args );
		$products = is_object( $result ) && isset( $result->products ) ? (array) $result->products : (array) $result;
		$total    = is_object( $result ) && isset( $result->total ) ? (int) $result->total : count( $products );
		return array(
			'source' => 'wc_get_products',
			'page'   => $page,
			'limit'  => $limit,
			'total'  => $total,
			'items'  => array_map( array( $this, 'present_product_summary' ), $products ),
		);
	}

	/* ── commerce.search_products (PHASE-0.95 S95-F3 / G1 / F14) ────────── */

	/**
	 * [2026-10-09 03:18 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.95-S95-F3/G1 — consult search: ≤ 20 public candidates ⇒ D95-16 score
	 * (BizCity_Commerce_Consult_Score) ⇒ sorted ⇒ ≤ 5 items in `consult-result@1` (≤ 3 KB).
	 *
	 * Reads PUBLIC catalog data only (published, catalog-visible, no password), so a customer turn (principal guru_public,
	 * user 0) runs without any WordPress user — there is no run_as. On a customer turn the Guru's product categories apply
	 * (ctx.allowed_product_cat_ids, already expanded to descendants, S95-F14): a group outside it gives no item and an
	 * `ask_next` naming the groups this Guru advises on (`notice: product_out_of_scope`, data — not an error).
	 *
	 * @param array $args {q?, group_id?, facets?{}, need?{for_whom, age, age_unit, goals[], constraints[], budget_max, form}, limit ≤ 5, page}
	 */
	public function search_products( array $args, array $ctx ) {
		if ( ! isset( self::$readers['consult_products'] ) && ! function_exists( 'wc_get_products' ) ) {
			return array( '_degraded' => true, 'reason' => 'woocommerce_unavailable', 'contract' => self::CONSULT_CONTRACT, 'domain' => 'product', 'items' => array(), 'ask_next' => array(), 'as_of' => self::consult_now(), 'truncated' => false );
		}
		$limit    = isset( $args['limit'] ) ? max( 1, min( self::CONSULT_MAX_ITEMS, (int) $args['limit'] ) ) : self::CONSULT_MAX_ITEMS;
		$page     = isset( $args['page'] ) ? max( 1, min( 4, (int) $args['page'] ) ) : 1;
		$q        = self::cut_text( function_exists( 'sanitize_text_field' ) ? sanitize_text_field( (string) ( $args['q'] ?? '' ) ) : (string) ( $args['q'] ?? '' ), 120 );
		$group_id = isset( $args['group_id'] ) ? max( 0, (int) $args['group_id'] ) : 0;
		$need     = self::consult_need( $args );
		$groups   = self::product_groups();
		$guest    = 'guru_public' === (string) ( $ctx['principal_kind'] ?? '' );
		$allowed  = $guest ? array_values( array_filter( array_map( 'intval', (array) ( $ctx['allowed_product_cat_ids'] ?? array() ) ) ) ) : array();

		if ( $guest && $allowed && $group_id > 0 && ! in_array( $group_id, $allowed, true ) ) {
			return self::consult_out_of_scope( $allowed, $groups );
		}
		$cat_ids = $group_id > 0 ? array( $group_id ) : $allowed;
		$rows    = self::consult_candidates( $q, $cat_ids );
		if ( ! $rows && '' !== $q ) {
			$rows = self::consult_candidates( '', $cat_ids ); // WP search ANDs every word: let the score pick instead
		}
		$rows = array_values( array_filter( $rows, static function ( $r ) use ( $allowed ) {
			if ( 'publish' !== (string) ( $r['status'] ?? 'publish' ) || '' !== (string) ( $r['password'] ?? '' ) ) {
				return false;
			}
			if ( ! in_array( (string) ( $r['visibility'] ?? 'visible' ), array( 'visible', 'search' ), true ) ) {
				return false;
			}
			return ! $allowed || (bool) array_intersect( $allowed, array_map( 'intval', (array) ( $r['category_ids'] ?? array() ) ) );
		} ) );

		// pop = sales share (0.7) + rating (0.3), normalised inside this candidate set.
		$max_sales = 0;
		foreach ( $rows as $r ) {
			$max_sales = max( $max_sales, (int) ( $r['total_sales'] ?? 0 ) );
		}
		$scored = array();
		$hide   = function_exists( 'apply_filters' ) ? (bool) apply_filters( 'bizcity_consult_hide_price', false ) : false; // Q95-2
		foreach ( $rows as $r ) {
			$advice  = class_exists( 'BizCity_Product_Advice' ) ? BizCity_Product_Advice::for_product( (int) $r['id'] ) : null;
			$gid     = self::row_group_id( $r, $group_id );
			$gadvice = ( $gid > 0 && class_exists( 'BizCity_Product_Advice' ) ) ? BizCity_Product_Advice::for_category( $gid ) : null;
			$price   = $hide ? null : self::money_or_null( $r['price'] ?? null );
			$pop     = ( $max_sales > 0 ? 0.7 * ( (int) ( $r['total_sales'] ?? 0 ) / $max_sales ) : 0.0 ) + 0.3 * max( 0.0, min( 5.0, (float) ( $r['rating'] ?? 0 ) ) ) / 5;
			$product = array(
				'ref'        => 'product:' . (int) $r['id'],
				'name'       => (string) ( $r['name'] ?? '' ),
				'short'      => (string) ( $r['short'] ?? '' ),
				'categories' => array_values( array_map( 'strval', (array) ( $r['categories'] ?? array() ) ) ),
				'attrs'      => (array) ( $r['attrs'] ?? array() ),
				'price'      => $price,
				'stock'      => (string) ( $r['stock'] ?? 'instock' ),
				'pop'        => round( $pop, 4 ),
			);
			$match    = BizCity_Commerce_Consult_Score::score( $product, $advice, $need, array( 'synonyms' => $gadvice['synonyms'] ?? array() ) );
			$scored[] = array( 'row' => $r, 'product' => $product, 'advice' => $advice, 'gid' => $gid, 'gadvice' => $gadvice, 'match' => $match, 'hide' => $hide );
		}
		usort( $scored, static function ( $a, $b ) {
			return array( $b['match']['score'], (string) $a['product']['name'] ) <=> array( $a['match']['score'], (string) $b['product']['name'] );
		} );
		$slice = array_slice( $scored, ( $page - 1 ) * $limit, $limit );

		$items = array();
		foreach ( $slice as $s ) {
			$items[] = self::consult_item( $s, $groups );
		}
		$ask = array();
		if ( $slice ) {
			$top = $slice[0];
			foreach ( array_merge( (array) ( $top['advice']['key_questions'] ?? array() ), (array) ( $top['gadvice']['ask_first'] ?? array() ) ) as $qq ) {
				$qq = trim( (string) $qq );
				if ( '' !== $qq && ! in_array( $qq, $ask, true ) ) {
					$ask[] = $qq;
				}
			}
		} elseif ( $group_id > 0 && class_exists( 'BizCity_Product_Advice' ) ) {
			$ask = (array) ( BizCity_Product_Advice::for_category( $group_id )['ask_first'] ?? array() );
		}
		return self::consult_fit( array(
			'contract'  => self::CONSULT_CONTRACT,
			'domain'    => 'product',
			'items'     => $items,
			'ask_next'  => array_slice( array_values( $ask ), 0, 3 ),
			'as_of'     => self::consult_now(),
			'truncated' => false,
		) );
	}

	/** need{} of the call (+ top-level facets), every key present. */
	private static function consult_need( array $args ): array {
		$n    = is_array( $args['need'] ?? null ) ? $args['need'] : array();
		$list = static function ( $v, int $max ) {
			$out = array();
			foreach ( is_array( $v ) ? $v : ( '' !== trim( (string) $v ) ? array( $v ) : array() ) as $x ) {
				$x = self::cut_text( (string) $x, 60 );
				if ( '' !== $x && ! in_array( $x, $out, true ) ) {
					$out[] = $x;
				}
			}
			return array_slice( $out, 0, $max );
		};
		$facets = array();
		foreach ( (array) ( $args['facets'] ?? $n['facets'] ?? array() ) as $k => $v ) {
			if ( is_scalar( $v ) && '' !== trim( (string) $k ) && '' !== trim( (string) $v ) ) {
				$facets[ self::cut_text( (string) $k, 40 ) ] = self::cut_text( (string) $v, 60 );
			}
			if ( count( $facets ) >= 6 ) {
				break;
			}
		}
		$age    = $n['age'] ?? null;
		$budget = $n['budget_max'] ?? null;
		return array(
			'for_whom'    => self::cut_text( (string) ( $n['for_whom'] ?? '' ), 60 ),
			'age'         => is_numeric( $age ) && $age >= 0 ? 0 + $age : null,
			'age_unit'    => 'month' === (string) ( $n['age_unit'] ?? '' ) ? 'month' : 'year',
			'goals'       => $list( $n['goals'] ?? array(), 6 ),
			'constraints' => $list( $n['constraints'] ?? array(), 6 ),
			'budget_max'  => is_numeric( $budget ) && $budget > 0 ? 0 + $budget : null,
			'form'        => self::cut_text( (string) ( $n['form'] ?? '' ), 40 ),
			'facets'      => $facets,
		);
	}

	/** One consult-result@1 item. */
	private static function consult_item( array $s, array $groups ): array {
		$r       = $s['row'];
		$advice  = $s['advice'];
		$regular = $s['hide'] ? null : self::money_or_null( $r['regular_price'] ?? null );
		$price   = $s['product']['price'];
		$attrs   = array();
		foreach ( (array) ( $r['attrs'] ?? array() ) as $k => $v ) {
			$attrs[ self::cut_text( (string) $k, 40 ) ] = self::cut_text( is_array( $v ) ? implode( ', ', array_map( 'strval', $v ) ) : (string) $v, 60 );
			if ( count( $attrs ) >= 6 ) {
				break;
			}
		}
		$group = isset( $groups[ $s['gid'] ] ) ? (string) $groups[ $s['gid'] ]['name'] : (string) ( ( (array) ( $r['categories'] ?? array() ) )[0] ?? '' );
		$gallery = array();
		foreach ( (array) ( $r['gallery'] ?? array() ) as $u ) {
			$u = self::https_url( (string) $u );
			if ( '' !== $u ) {
				$gallery[] = $u;
			}
			if ( count( $gallery ) >= 3 ) {
				break;
			}
		}
		$compliance = (string) ( $advice['compliance'] ?? '' );
		if ( '' === $compliance ) {
			$compliance = (string) ( $s['gadvice']['compliance'] ?? '' );
		}
		return array(
			'ref'        => 'product:' . (int) $r['id'],
			'title'      => self::cut_text( (string) ( $r['name'] ?? '' ), 200 ),
			'facts'      => array(
				'price'         => $price,
				'regular_price' => ( null !== $regular && null !== $price && $regular > $price ) ? $regular : null,
				'currency'      => function_exists( 'get_woocommerce_currency' ) ? (string) get_woocommerce_currency() : 'VND',
				'stock'         => (string) ( $r['stock'] ?? 'instock' ),
				'group'         => $group,
				'attrs'         => $attrs ? $attrs : new stdClass(),
				'short'         => self::cut_text( (string) ( $r['short'] ?? '' ), self::CONSULT_SHORT ),
			),
			'media'      => array( 'image_url' => self::https_url( (string) ( $r['image_url'] ?? '' ) ), 'gallery' => $gallery ),
			'link'       => (string) ( $r['link'] ?? '' ),
			'advice'     => $advice ? array(
				'tier'      => (string) $advice['tier'],
				'audience'  => (array) $advice['audience'],
				'goals'     => (array) $advice['goals'],
				'avoid_for' => (array) $advice['avoid_for'],
				'pitch'     => (string) $advice['pitch'],
				'pair_with' => (array) $advice['pair_with'],
			) : null,
			'match'      => $s['match'],
			'next_tool'  => (string) ( $advice['next_tool'] ?? 'order' ),
			'compliance' => $compliance,
		);
	}

	/** Keep the whole result ≤ 3 KB: shorten pitch, then short, then drop galleries, then trailing items. */
	private static function consult_fit( array $out ): array {
		$size = static function ( array $o ): int { return strlen( (string) wp_json_encode( $o ) ); };
		if ( $size( $out ) <= self::CONSULT_MAX_BYTES ) {
			return $out;
		}
		$out['truncated'] = true;
		$steps = array(
			static function ( array &$i ) { if ( is_array( $i['advice'] ) ) { $i['advice']['pitch'] = self::cut_text( (string) $i['advice']['pitch'], 120 ); } },
			static function ( array &$i ) { $i['facts']['short'] = self::cut_text( (string) $i['facts']['short'], 80 ); },
			static function ( array &$i ) { $i['media']['gallery'] = array(); },
			static function ( array &$i ) { if ( is_array( $i['advice'] ) ) { $i['advice']['pitch'] = ''; } $i['facts']['short'] = ''; },
		);
		foreach ( $steps as $step ) {
			foreach ( $out['items'] as &$item ) {
				$step( $item );
			}
			unset( $item );
			if ( $size( $out ) <= self::CONSULT_MAX_BYTES ) {
				return $out;
			}
		}
		while ( count( $out['items'] ) > 1 && $size( $out ) > self::CONSULT_MAX_BYTES ) {
			array_pop( $out['items'] );
		}
		return $out;
	}

	/** Customer asked for a group outside the Guru's categories: no item, the groups this Guru advises on instead. */
	private static function consult_out_of_scope( array $allowed, array $groups ): array {
		$names = array();
		foreach ( $allowed as $id ) {
			$g = $groups[ $id ] ?? null;
			// top of the chosen tree only (a parent not in the set)
			if ( $g && ! in_array( (int) $g['parent'], $allowed, true ) && '' !== (string) $g['name'] ) {
				$names[] = (string) $g['name'];
			}
		}
		$names = array_slice( array_values( array_unique( $names ) ), 0, 5 );
		return array(
			'contract'  => self::CONSULT_CONTRACT,
			'domain'    => 'product',
			'items'     => array(),
			'ask_next'  => $names ? array( 'Bên mình tư vấn nhóm ' . implode( ', ', $names ) . ' — anh/chị quan tâm nhóm nào?' ) : array(),
			'as_of'     => self::consult_now(),
			'truncated' => false,
			'notice'    => 'product_out_of_scope',
		);
	}

	/** Group of a row: the asked group when the row is in it, else its first category. */
	private static function row_group_id( array $r, int $group_id ): int {
		$ids = array_values( array_map( 'intval', (array) ( $r['category_ids'] ?? array() ) ) );
		if ( $group_id > 0 && ( in_array( $group_id, $ids, true ) || ! $ids ) ) {
			return $group_id;
		}
		return (int) ( $ids[0] ?? $group_id );
	}

	/** @return array<int,array{id:int,name:string,parent:int}> product_cat terms by id */
	public static function product_groups(): array {
		$rows = array();
		if ( isset( self::$readers['product_groups'] ) ) {
			$rows = (array) call_user_func( self::$readers['product_groups'] );
		} elseif ( function_exists( 'get_terms' ) && ( ! function_exists( 'taxonomy_exists' ) || taxonomy_exists( 'product_cat' ) ) ) {
			$terms = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false, 'number' => 1000 ) );
			foreach ( is_array( $terms ) ? $terms : array() as $t ) {
				$rows[] = array( 'id' => (int) $t->term_id, 'name' => (string) $t->name, 'parent' => (int) $t->parent );
			}
		}
		$out = array();
		foreach ( $rows as $g ) {
			$g = (array) $g;
			$id = (int) ( $g['id'] ?? 0 );
			if ( $id > 0 ) {
				$out[ $id ] = array( 'id' => $id, 'name' => (string) ( $g['name'] ?? '' ), 'parent' => (int) ( $g['parent'] ?? 0 ) );
			}
		}
		return $out;
	}

	/** ≤ 20 public candidates through wc_get_products (category slugs include children). */
	private static function consult_candidates( string $q, array $cat_ids ): array {
		if ( isset( self::$readers['consult_products'] ) ) {
			return (array) call_user_func( self::$readers['consult_products'], array( 'q' => $q, 'cat_ids' => $cat_ids, 'limit' => self::CONSULT_CANDIDATES ) );
		}
		$query = array( 'status' => 'publish', 'limit' => self::CONSULT_CANDIDATES, 'return' => 'objects', 'orderby' => 'popularity', 'order' => 'DESC' );
		if ( '' !== $q ) {
			$query['s'] = $q;
		}
		if ( $cat_ids ) {
			$slugs = array();
			foreach ( $cat_ids as $id ) {
				$t = function_exists( 'get_term' ) ? get_term( (int) $id, 'product_cat' ) : null;
				if ( $t && ! is_wp_error( $t ) ) {
					$slugs[] = (string) $t->slug;
				}
			}
			if ( ! $slugs ) {
				return array();
			}
			$query['category'] = $slugs;
		}
		$out = array();
		foreach ( (array) wc_get_products( $query ) as $p ) {
			if ( is_object( $p ) && method_exists( $p, 'get_id' ) ) {
				$out[] = self::consult_row( $p );
			}
		}
		return $out;
	}

	/** WC_Product ⇒ public row (no exact stock count, no cost, no meta beyond attributes). */
	private static function consult_row( $p ): array {
		$id    = (int) $p->get_id();
		$short = wp_strip_all_tags( (string) $p->get_short_description() );
		if ( '' === trim( $short ) ) {
			$short = wp_strip_all_tags( (string) $p->get_description() );
		}
		$attrs = array();
		foreach ( (array) $p->get_attributes() as $attr ) {
			if ( ! is_object( $attr ) || ! method_exists( $attr, 'get_name' ) || ( method_exists( $attr, 'get_visible' ) && ! $attr->get_visible() ) ) {
				continue;
			}
			$name   = (string) $attr->get_name();
			$label  = function_exists( 'wc_attribute_label' ) ? (string) wc_attribute_label( $name, $p ) : $name;
			$values = $attr->is_taxonomy() && function_exists( 'wc_get_product_terms' ) ? (array) wc_get_product_terms( $id, $name, array( 'fields' => 'names' ) ) : (array) $attr->get_options();
			$attrs[ $label ] = implode( ', ', array_map( 'strval', $values ) );
		}
		$qty    = $p->get_stock_quantity();
		$status = (string) $p->get_stock_status();
		$stock  = 'instock';
		if ( 'outofstock' === $status || ( $p->managing_stock() && null !== $qty && $qty <= 0 ) ) {
			$stock = 'out';
		} elseif ( $p->managing_stock() && null !== $qty && $qty <= self::CONSULT_LOW_STOCK ) {
			$stock = 'low';
		}
		$cats = function_exists( 'wc_get_product_terms' ) ? (array) wc_get_product_terms( $id, 'product_cat', array( 'fields' => 'names' ) ) : array();
		$gallery = array();
		foreach ( array_slice( (array) $p->get_gallery_image_ids(), 0, 3 ) as $gid ) {
			$gallery[] = self::https_image( (int) $gid );
		}
		return array(
			'id'            => $id,
			'name'          => (string) $p->get_name(),
			'short'         => $short,
			'status'        => (string) $p->get_status(),
			'visibility'    => (string) $p->get_catalog_visibility(),
			'password'      => method_exists( $p, 'get_post_password' ) ? (string) $p->get_post_password() : '',
			'categories'    => array_values( array_map( 'strval', $cats ) ),
			'category_ids'  => array_values( array_map( 'intval', (array) $p->get_category_ids() ) ),
			'attrs'         => $attrs,
			'price'         => $p->get_price(),
			'regular_price' => $p->get_regular_price(),
			'stock'         => $stock,
			'total_sales'   => (int) $p->get_total_sales(),
			'rating'        => (float) $p->get_average_rating(),
			'image_url'     => self::https_image( (int) $p->get_image_id() ),
			'gallery'       => array_values( array_filter( $gallery ) ),
			'link'          => (string) $p->get_permalink(),
		);
	}

	/** Attachment ⇒ its `woocommerce_single` URL when https, else ''. */
	private static function https_image( int $attachment_id ): string {
		if ( $attachment_id <= 0 || ! function_exists( 'wp_get_attachment_image_url' ) ) {
			return '';
		}
		return self::https_url( (string) wp_get_attachment_image_url( $attachment_id, 'woocommerce_single' ) );
	}

	private static function https_url( string $url ): string {
		return 0 === stripos( $url, 'https://' ) ? $url : '';
	}

	/** Numeric money ⇒ int (VND) / float, '' / non-numeric ⇒ null (hidden price, Q95-2). */
	private static function money_or_null( $v ) {
		if ( null === $v || '' === $v || ! is_numeric( $v ) ) {
			return null;
		}
		$f = (float) $v;
		return floor( $f ) === $f ? (int) $f : round( $f, 2 );
	}

	private static function cut_text( string $s, int $max ): string {
		$s = trim( (string) preg_replace( '/\s+/u', ' ', $s ) );
		return function_exists( 'mb_substr' ) ? mb_substr( $s, 0, $max, 'UTF-8' ) : substr( $s, 0, $max );
	}

	private static function consult_now(): string {
		$ts = isset( self::$readers['now'] ) ? (int) call_user_func( self::$readers['now'] ) : time();
		return function_exists( 'wp_date' ) ? (string) wp_date( 'c', $ts ) : gmdate( 'c', $ts );
	}

	public function get_product( array $args, array $ctx ) {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return array( '_degraded' => true, 'reason' => 'woocommerce_unavailable' );
		}
		$product = null;
		if ( ! empty( $args['product_id'] ) ) {
			$product = wc_get_product( absint( $args['product_id'] ) );
		} elseif ( ! empty( $args['sku'] ) && function_exists( 'wc_get_product_id_by_sku' ) ) {
			$id = wc_get_product_id_by_sku( sanitize_text_field( (string) $args['sku'] ) );
			$product = $id ? wc_get_product( $id ) : false;
		}
		if ( ! $product ) {
			return new WP_Error( BizCity_MCP_Error::NOT_FOUND, 'Không tìm thấy sản phẩm.', array( 'status' => 404 ) );
		}
		return array( 'source' => 'wc_get_product', 'item' => $this->present_product_detail( $product ) );
	}

	public function list_orders( array $args, array $ctx ) {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return array( '_degraded' => true, 'reason' => 'woocommerce_unavailable', 'items' => array(), 'total' => 0 );
		}
		$limit = isset( $args['limit'] ) ? max( 1, min( 100, (int) $args['limit'] ) ) : 20;
		$page  = isset( $args['page'] ) ? max( 1, (int) $args['page'] ) : 1;
		$query_args = array(
			'limit'    => $limit,
			'page'     => $page,
			'paginate' => true,
			'return'   => 'objects',
			'orderby'  => 'date',
			'order'    => 'DESC',
			'type'     => 'shop_order',
		);
		if ( ! empty( $args['status'] ) ) {
			$query_args['status'] = sanitize_key( (string) $args['status'] );
		}
		if ( ! empty( $args['customer_id'] ) ) {
			$query_args['customer_id'] = absint( $args['customer_id'] );
		}
		if ( ! empty( $args['from'] ) || ! empty( $args['to'] ) ) {
			$from = ! empty( $args['from'] ) ? sanitize_text_field( (string) $args['from'] ) : '';
			$to   = ! empty( $args['to'] ) ? sanitize_text_field( (string) $args['to'] ) : '';
			$query_args['date_created'] = trim( $from ) . '...' . trim( $to );
		}
		if ( ! empty( $args['search'] ) ) {
			$query_args['s'] = sanitize_text_field( (string) $args['search'] );
		}
		$result = wc_get_orders( $query_args );
		$orders = is_object( $result ) && isset( $result->orders ) ? (array) $result->orders : (array) $result;
		$total  = is_object( $result ) && isset( $result->total ) ? (int) $result->total : count( $orders );
		return array(
			'source' => 'wc_get_orders',
			'page'   => $page,
			'limit'  => $limit,
			'total'  => $total,
			'items'  => array_map( array( $this, 'present_order_summary' ), $orders ),
		);
	}

	public function get_order( array $args, array $ctx ) {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return array( '_degraded' => true, 'reason' => 'woocommerce_unavailable' );
		}
		$order_id = absint( $args['order_id'] ?? 0 );
		if ( ! $order_id ) {
			return new WP_Error( BizCity_MCP_Error::QUERY_INVALID, 'Thiếu order_id.', array( 'status' => 400 ) );
		}
		$order = wc_get_order( $order_id );
		if ( ! $order || is_wp_error( $order ) ) {
			return new WP_Error( BizCity_MCP_Error::NOT_FOUND, 'Không tìm thấy đơn hàng.', array( 'status' => 404 ) );
		}
		return array( 'source' => 'wc_get_order', 'item' => $this->present_order_detail( $order ) );
	}

	/**
	 * [2026-10-01 Claude Opus 5.5] PHASE-0.88 L1 wave 2 — `order.status` (llm alias biz_orders): one order by id, or the
	 * latest N (≤ 20) orders. Thin wrapper over get_order / list_orders; the customer's phone is masked to the last 3 digits
	 * (same rule as the `orders` pack) and e-mail / billing address are not returned to the agent.
	 */
	public function order_status( array $args, array $ctx ) {
		$order_id = absint( $args['order_id'] ?? 0 );
		if ( $order_id > 0 ) {
			$res = $this->get_order( array( 'order_id' => $order_id ), $ctx );
			if ( is_wp_error( $res ) || ! empty( $res['_degraded'] ) ) {
				return $res;
			}
			$item  = (array) ( $res['item'] ?? array() );
			$phone = (string) ( $item['billing_phone'] ?? '' );
			unset( $item['billing_phone'], $item['billing_email'], $item['billing_address'] );
			$item['phone_masked'] = self::mask_phone( $phone );
			return array( 'source' => (string) ( $res['source'] ?? '' ), 'order' => $item );
		}
		$limit = isset( $args['limit'] ) ? max( 1, min( 20, (int) $args['limit'] ) ) : 5;
		$res   = $this->list_orders( array( 'limit' => $limit, 'page' => 1, 'status' => (string) ( $args['status'] ?? '' ) ), $ctx );
		if ( is_wp_error( $res ) || ! empty( $res['_degraded'] ) ) {
			return $res;
		}
		$orders = array();
		foreach ( (array) ( $res['items'] ?? array() ) as $row ) {
			unset( $row['billing_email'] );
			$orders[] = $row;
		}
		return array( 'source' => (string) ( $res['source'] ?? '' ), 'total' => (int) ( $res['total'] ?? count( $orders ) ), 'orders' => $orders );
	}

	/** "…" + last 3 digits; '' when the number is too short to mask safely (orders pack rule). */
	public static function mask_phone( $phone ) {
		$d = preg_replace( '/\D+/', '', (string) $phone );
		return strlen( (string) $d ) >= 4 ? '…' . substr( (string) $d, -3 ) : '';
	}

	public function list_customers( array $args, array $ctx ) {
		if ( ! class_exists( 'WP_User_Query' ) || ! function_exists( 'wc_get_customer_order_count' ) ) {
			return array( '_degraded' => true, 'reason' => 'woocommerce_unavailable', 'items' => array(), 'total' => 0 );
		}
		$limit = isset( $args['limit'] ) ? max( 1, min( 100, (int) $args['limit'] ) ) : 20;
		$page  = isset( $args['page'] ) ? max( 1, (int) $args['page'] ) : 1;
		$query_args = array(
			'role'    => 'customer',
			'number'  => $limit,
			'paged'   => $page,
			'orderby' => 'registered',
			'order'   => 'DESC',
			'fields'  => 'all',
		);
		if ( ! empty( $args['search'] ) ) {
			$query_args['search']         = '*' . sanitize_text_field( (string) $args['search'] ) . '*';
			$query_args['search_columns'] = array( 'user_login', 'user_email', 'display_name' );
		}
		$query = new WP_User_Query( $query_args );
		$users = $query->get_results();
		return array(
			'source'      => 'WP_User_Query(role=customer) + WooCommerce order aggregates',
			'page'        => $page,
			'limit'       => $limit,
			'total'       => (int) $query->get_total(),
			'items'       => array_map( array( $this, 'present_customer_summary' ), $users ),
			'note'        => 'Chỉ liệt kê khách có tài khoản WordPress (role customer); đơn hàng khách vãng lai (guest checkout) chưa có trong danh sách này.',
		);
	}

	public function get_customer( array $args, array $ctx ) {
		$customer_id = absint( $args['customer_id'] ?? 0 );
		if ( ! $customer_id ) {
			return new WP_Error( BizCity_MCP_Error::QUERY_INVALID, 'Thiếu customer_id.', array( 'status' => 400 ) );
		}
		$user = get_userdata( $customer_id );
		if ( ! $user ) {
			return new WP_Error( BizCity_MCP_Error::NOT_FOUND, 'Không tìm thấy khách hàng.', array( 'status' => 404 ) );
		}
		$recent_orders = array();
		if ( function_exists( 'wc_get_orders' ) ) {
			$orders = wc_get_orders( array( 'customer_id' => $customer_id, 'limit' => 5, 'orderby' => 'date', 'order' => 'DESC', 'return' => 'objects' ) );
			foreach ( (array) $orders as $order ) {
				$recent_orders[] = $this->present_order_summary( $order );
			}
		}
		return array(
			'source' => 'get_userdata + wc_get_orders',
			'item'   => array_merge( $this->present_customer_summary( $user ), array( 'recent_orders' => $recent_orders ) ),
		);
	}

	private function present_product_summary( $product ) {
		if ( ! is_object( $product ) || ! method_exists( $product, 'get_id' ) ) {
			return array();
		}
		return array(
			'id'            => $product->get_id(),
			'name'          => $product->get_name(),
			'sku'           => $product->get_sku(),
			'status'        => $product->get_status(),
			'type'          => $product->get_type(),
			'price'         => $product->get_price(),
			'regular_price' => $product->get_regular_price(),
			'sale_price'    => $product->get_sale_price(),
			'stock_status'  => $product->get_stock_status(),
			'stock_quantity'=> $product->get_stock_quantity(),
			'permalink'     => get_permalink( $product->get_id() ),
			// [2026-10-09 03:18 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.95-S95-F3 — product cards need the picture (https only, '' otherwise).
			'image_url'     => self::https_image( (int) $product->get_image_id() ),
		);
	}

	private function present_product_detail( $product ) {
		$summary = $this->present_product_summary( $product );
		return array_merge( $summary, array(
			'description'       => wp_strip_all_tags( (string) $product->get_description() ),
			'short_description' => wp_strip_all_tags( (string) $product->get_short_description() ),
			'categories'        => wp_get_post_terms( $product->get_id(), 'product_cat', array( 'fields' => 'names' ) ),
			'image_url'         => wp_get_attachment_url( $product->get_image_id() ) ?: '',
			'gallery_count'     => count( $product->get_gallery_image_ids() ),
			'currency'          => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'VND',
		) );
	}

	private function present_order_summary( $order ) {
		if ( ! is_object( $order ) || ! method_exists( $order, 'get_id' ) ) {
			return array();
		}
		return array(
			'id'             => $order->get_id(),
			'status'         => $order->get_status(),
			'total'          => $order->get_total(),
			'currency'       => $order->get_currency(),
			'date_created'   => $order->get_date_created() ? $order->get_date_created()->date( 'c' ) : '',
			'customer_id'    => $order->get_customer_id(),
			'billing_name'   => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
			'billing_email'  => $order->get_billing_email(),
			'payment_method' => $order->get_payment_method_title(),
			'item_count'     => $order->get_item_count(),
		);
	}

	private function present_order_detail( $order ) {
		$summary = $this->present_order_summary( $order );
		$items   = array();
		foreach ( $order->get_items() as $item ) {
			$items[] = array(
				'name'     => $item->get_name(),
				'quantity' => $item->get_quantity(),
				'total'    => $item->get_total(),
				'product_id' => $item->get_product_id(),
			);
		}
		return array_merge( $summary, array(
			'billing_phone'  => $order->get_billing_phone(),
			'billing_address'=> $order->get_formatted_billing_address(),
			'shipping_address' => $order->get_formatted_shipping_address(),
			'customer_note'  => $order->get_customer_note(),
			'items'          => $items,
		) );
	}

	private function present_customer_summary( $user ) {
		if ( ! is_object( $user ) || ! isset( $user->ID ) ) {
			return array();
		}
		$order_count = function_exists( 'wc_get_customer_order_count' ) ? (int) wc_get_customer_order_count( $user->ID ) : 0;
		$total_spent = function_exists( 'wc_get_customer_total_spent' ) ? (float) wc_get_customer_total_spent( $user->ID ) : 0.0;
		return array(
			'id'            => (int) $user->ID,
			'display_name'  => $user->display_name,
			'email'         => $user->user_email,
			'billing_phone' => get_user_meta( $user->ID, 'billing_phone', true ),
			'registered_at' => $user->user_registered,
			'order_count'   => $order_count,
			'total_spent'   => $total_spent,
		);
	}
}
