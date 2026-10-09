<?php
/**
 * Biz Central Brain — Johnny Chu (Chu Hoàng Anh). Bizcity Central Brain, Giấy chứng nhận đăng ký quyền tác giả số 8877/2026/QTG.
 *
 * BizCity_CRM_Customers_Pack — the `customers` projection pack in core (CORE-REDUCTION WP-20 W20-L3, D-W20-1).
 *
 * Base version: the cell answers about customers without the Zalo Brain CRM plugin. Same item shape as the old plugin exporter
 * (projection-pack@1.1, zalo-hub pack-items.ts CustomerItem): contact_ref, name, phone_masked (last 3 digits), orders,
 * total_spent, last_order_at, crm_stage, last_contact_at. No email, no address. Base `crm_stage` is ''; the plugin decorates
 * every row through the filter `bizcity_crm_pack_customer_row` (pipeline stage label).
 *
 * Facts: BizCity_CRM_Repository (contacts, latest conversation = owner/assignee, last activity, last outgoing message) + Woo
 * orders linked by `_bizcity_crm_contact_id` (BizCity_Woo_Pack_Exporter::contact_order_facts, same rule as the pipeline).
 * Whose customers: BizCity_CRM_Agent_Mode_Delegate::customers_scope() — a lead/agent gets only the contacts assigned to them
 * (scope person, D-TAA-7). Caps, cache keys and the version hash are the old ones (CUSTOMERS_TOP 200, CUSTOMER_SCAN 1000,
 * `bizcity_pack_biz_customers…`), so a running site keeps its cache.
 *
 * Hook (bizcity_crm_* family): apply_filters( 'bizcity_crm_pack_customer_row', array $row, int $contact_id, int[] $batch_ids ): array
 * — `$batch_ids` (optional 3rd arg) = every contact of this pack build, so a decorator can load its data once. When the row
 * passed in has a `stage` key (MCP reads), a decorator also fills it with the raw stage key.
 *
 * // @axis twin-agent-axis@1 pack customers
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\CRM
 */

// [2026-10-09 10:45 PM Johnny Chu - Chu Hoàng Anh] CORE-REDUCTION WP-20 W20-L3 — new file: base `customers` pack + row filter (D-W20-1).

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_CRM_Customers_Pack', false ) ) {
	return;
}

final class BizCity_CRM_Customers_Pack {

	const KIND          = 'customers';
	const PACK_FILTER   = 'bizcity_twin_agent_pack_exporters';
	const ROW_FILTER    = 'bizcity_crm_pack_customer_row';
	const CACHE_PREFIX  = 'bizcity_pack_biz_';
	const CACHE_TTL     = 600;
	const CUSTOMERS_TOP = 200;
	const CUSTOMER_SCAN = 1000;

	/**
	 * Test seams: customers(): list<{contact_id, name, owner_id, ordered, revenue, last_order_ts, last_activity_ts, last_out_ts}> ·
	 * phones(int[]): id => phone · currency(): string · now(): int · cache: false to bypass the transient.
	 *
	 * @var array<string,mixed>
	 */
	public static $readers = array();

	/** @var array<string,array> per-request memo */
	private static $memo = array();


	public static function register(): void {
		add_filter( self::PACK_FILTER, array( __CLASS__, 'exporters' ), 10 );
	}

	public static function exporters( $exporters ): array {
		$exporters = is_array( $exporters ) ? $exporters : array();
		$exporters[ self::KIND ] = array(
			'mode'      => self::KIND,
			'audience'  => 'owner_agent',
			'available' => array( __CLASS__, 'ready' ),
			'stats'     => static function ( array $ctx ) { return BizCity_CRM_Customers_Pack::stats( BizCity_CRM_Customers_Pack::person_of( $ctx ) ); },
			'page'      => static function ( array $ctx, int $after, int $limit ) { return BizCity_CRM_Customers_Pack::page( $after, $limit, BizCity_CRM_Customers_Pack::person_of( $ctx ) ); },
		);
		return $exporters;
	}

	/** The CRM spine is loaded (core). The plugin is never needed. */
	public static function ready(): bool {
		return isset( self::$readers['customers'] ) || ( class_exists( 'BizCity_CRM_Repository' ) && class_exists( 'BizCity_CRM_DB_Installer_V2' ) );
	}

	/**
	 * Whose customers (D-TAA-7): a CRM lead/agent gets only the contacts assigned to them (`scope: person`, a per-person pack keyed
	 * by their user_hash at the cell); admin/supervisor/owner get the shop. Returns the user id to filter by, 0 = whole shop.
	 */
	public static function person_of( array $ctx ): int {
		$user  = (int) ( $ctx['owner_user_id'] ?? 0 );
		$scope = class_exists( 'BizCity_CRM_Agent_Mode_Delegate' ) ? BizCity_CRM_Agent_Mode_Delegate::customers_scope( $user ) : 'shop';
		return 'person' === $scope ? $user : 0;
	}

	public static function stats( int $person = 0 ): array {
		$c    = self::computed( $person );
		$json = (string) wp_json_encode( $c['items'] );
		return array(
			'version' => 'v-' . substr( sha1( self::KIND . '|' . $person . '|' . $json ), 0, 8 ),
			'as_of'   => $c['as_of'],
			'bytes'   => strlen( $json ),
			'items'   => count( $c['items'] ),
			'scope'   => $person > 0 ? 'person' : 'shop',
		);
	}

	/** Offset paging (`after_id` = number of items already sent). */
	public static function page( int $after, int $limit, int $person = 0 ): array {
		$items = self::computed( $person )['items'];
		$after = max( 0, $after );
		$slice = array_slice( $items, $after, $limit );
		$last  = $after + count( $slice );
		return array( 'items' => $slice, 'last_id' => $last, 'more' => $last < count( $items ) );
	}

	/** @return array{as_of:string,items:array} */
	private static function computed( int $person ): array {
		$slot = $person > 0 ? self::KIND . '_u' . $person : self::KIND;
		if ( isset( self::$memo[ $slot ] ) ) {
			return self::$memo[ $slot ];
		}
		$cache = ! isset( self::$readers['cache'] ) || false !== self::$readers['cache'];
		if ( $cache && function_exists( 'get_transient' ) ) {
			$hit = get_transient( self::cache_key( $slot ) );
			if ( is_array( $hit ) && isset( $hit['items'] ) ) {
				return self::$memo[ $slot ] = $hit;
			}
		}
		$out = array( 'as_of' => gmdate( 'Y-m-d\TH:i:s\Z', self::now() ), 'items' => self::customer_items( $person ) );
		if ( $cache && function_exists( 'set_transient' ) ) {
			set_transient( self::cache_key( $slot ), $out, self::CACHE_TTL );
		}
		return self::$memo[ $slot ] = $out;
	}

	/**
	 * Drop every cached customers slot (shop + per person, by bumping the generation). `$announce` false when the caller sends
	 * the one `bizcity_twin_agent_packs_changed` itself (BizCity_Woo_Pack_Exporter::on_order_change names sales, orders, customers).
	 */
	public static function flush( bool $announce = true ): void {
		self::$memo = array();
		if ( function_exists( 'update_option' ) ) {
			update_option( self::CACHE_PREFIX . 'customers_gen', (int) get_option( self::CACHE_PREFIX . 'customers_gen', 0 ) + 1, false );
		}
		if ( function_exists( 'delete_transient' ) ) {
			delete_transient( self::cache_key( self::KIND ) );
		}
		if ( $announce ) {
			do_action( 'bizcity_twin_agent_packs_changed', array( self::KIND ) );
		}
	}

	/** Test seam reset. */
	public static function reset(): void {
		self::$memo = array();
	}

	/* ── items ────────────────────────────────────────────────────── */

	/** @param int $person > 0 ⇒ only contacts whose latest conversation is assigned to this user (`owner_id`, D-TAA-7). */
	public static function customer_items( int $person = 0 ): array {
		$rows = self::customer_rows();
		if ( $person > 0 ) {
			$rows = array_values( array_filter( $rows, static function ( $r ) use ( $person ) { return (int) ( $r['owner_id'] ?? 0 ) === $person; } ) );
		}
		usort( $rows, static function ( $a, $b ) {
			return array( (float) ( $b['revenue'] ?? 0 ), (int) ( $b['last_activity_ts'] ?? 0 ) ) <=> array( (float) ( $a['revenue'] ?? 0 ), (int) ( $a['last_activity_ts'] ?? 0 ) );
		} );
		$rows   = array_slice( $rows, 0, self::CUSTOMERS_TOP );
		$ids    = array_map( static function ( $r ) { return (int) $r['contact_id']; }, $rows );
		$phones = self::phones( $rows );
		$items  = array();
		foreach ( $rows as $r ) {
			$cid  = (int) $r['contact_id'];
			$last = max( (int) ( $r['last_activity_ts'] ?? 0 ), (int) ( $r['last_out_ts'] ?? 0 ) );
			$item = array(
				'contact_ref'     => 'crm:' . $cid,
				'name'            => self::text( (string) ( $r['name'] ?? '' ), 120 ),
				'phone_masked'    => self::mask_phone( (string) ( $phones[ $cid ] ?? '' ) ),
				'orders'          => (int) ( $r['ordered'] ?? 0 ),
				'total_spent'     => self::money( (float) ( $r['revenue'] ?? 0 ) ),
				'last_order_at'   => ! empty( $r['last_order_ts'] ) ? gmdate( 'Y-m-d\TH:i:s\Z', (int) $r['last_order_ts'] ) : '',
				'crm_stage'       => '',
				'last_contact_at' => $last > 0 ? gmdate( 'Y-m-d\TH:i:s\Z', $last ) : '',
			);
			$items[] = self::decorate( $item, $cid, $ids );
		}
		return $items;
	}

	/**
	 * One row through `bizcity_crm_pack_customer_row`. A decorator may only fill values: a non-array answer keeps the base row,
	 * and keys the base row does not have are dropped (the pack shape never changes for the cell).
	 *
	 * @param int[] $batch_ids every contact of this build (decorator prefetch hint)
	 */
	public static function decorate( array $row, int $contact_id, array $batch_ids = array() ): array {
		if ( ! function_exists( 'apply_filters' ) ) {
			return $row;
		}
		$out = apply_filters( self::ROW_FILTER, $row, $contact_id, $batch_ids ? array_values( array_map( 'intval', $batch_ids ) ) : array( $contact_id ) );
		if ( ! is_array( $out ) ) {
			return $row;
		}
		return array_merge( $row, array_intersect_key( $out, $row ) );
	}

	/**
	 * Pipeline stage of one contact for MCP reads: {stage: raw key, label: display label}; both '' without the CRM plugin.
	 *
	 * @return array{stage:string,label:string}
	 */
	public static function stage_of( int $contact_id ): array {
		$row = self::decorate( array( 'crm_stage' => '', 'stage' => '' ), $contact_id, array( $contact_id ) );
		return array( 'stage' => (string) $row['stage'], 'label' => (string) $row['crm_stage'] );
	}

	/**
	 * Base facts of some contacts (MCP reads: bizcity://customer/{id}, crm.customer.lookup): Repository facts + Woo order facts.
	 *
	 * @param int[] $ids
	 * @return array<int,array> contact_id => {contact_id, name, phone, owner_id, conversation_id, inbox_id, platform, channel_ref,
	 *                          last_activity_ts, last_out_ts, ordered, revenue, last_order_ts}
	 */
	public static function facts( array $ids ): array {
		if ( isset( self::$readers['facts'] ) ) {
			return (array) call_user_func( self::$readers['facts'], $ids );
		}
		if ( ! class_exists( 'BizCity_CRM_Repository' ) || ! method_exists( 'BizCity_CRM_Repository', 'customer_base_facts' ) || ! class_exists( 'BizCity_CRM_DB_Installer_V2' ) ) {
			return array();
		}
		$rows   = BizCity_CRM_Repository::customer_base_facts( $ids );
		$orders = $rows && class_exists( 'BizCity_Woo_Pack_Exporter' ) ? BizCity_Woo_Pack_Exporter::contact_order_facts( array_keys( $rows ) ) : array();
		foreach ( $rows as $cid => $r ) {
			$o            = (array) ( $orders[ $cid ] ?? array() );
			$rows[ $cid ] = $r + array(
				'ordered'       => (int) ( $o['ordered'] ?? 0 ),
				'revenue'       => (float) ( $o['revenue'] ?? 0 ),
				'last_order_ts' => (int) ( $o['last_order_ts'] ?? 0 ),
			);
		}
		return $rows;
	}

	private static function customer_rows(): array {
		if ( isset( self::$readers['customers'] ) ) {
			return (array) call_user_func( self::$readers['customers'] );
		}
		if ( ! class_exists( 'BizCity_CRM_Repository' ) || ! method_exists( 'BizCity_CRM_Repository', 'list_customer_ids' ) || ! class_exists( 'BizCity_CRM_DB_Installer_V2' ) ) {
			return array();
		}
		return array_values( self::facts( BizCity_CRM_Repository::list_customer_ids( null, self::CUSTOMER_SCAN ) ) );
	}

	/** @return array<int,string> contact_id => phone (seam `phones`, else the `phone` fact of the row). */
	private static function phones( array $rows ): array {
		$ids = array_values( array_filter( array_map( static function ( $r ) { return (int) ( $r['contact_id'] ?? 0 ); }, $rows ) ) );
		if ( ! $ids ) {
			return array();
		}
		if ( isset( self::$readers['phones'] ) ) {
			return (array) call_user_func( self::$readers['phones'], $ids );
		}
		$out = array();
		foreach ( $rows as $r ) {
			$out[ (int) $r['contact_id'] ] = (string) ( $r['phone'] ?? '' );
		}
		return $out;
	}

	/* ── helpers (same rules as the Woo packs) ────────────────────── */

	/** "…" + last 3 digits; fewer than 4 digits is not a phone ⇒ ''. Same rule as the cell (pack-items.ts maskPhone). */
	public static function mask_phone( string $phone ): string {
		$d = preg_replace( '/\D+/', '', $phone );
		return strlen( (string) $d ) >= 4 ? '…' . substr( (string) $d, -3 ) : '';
	}

	private static function text( string $s, int $max ): string {
		$s = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $s ) ) );
		return function_exists( 'mb_substr' ) ? mb_substr( $s, 0, $max ) : substr( $s, 0, $max );
	}

	/** VND has no minor unit; other currencies keep 2 decimals. */
	private static function money( float $v ) {
		return 'VND' === self::currency() ? (int) round( $v ) : round( $v, 2 );
	}

	private static function currency(): string {
		if ( isset( self::$readers['currency'] ) ) {
			return (string) call_user_func( self::$readers['currency'] );
		}
		return function_exists( 'get_woocommerce_currency' ) ? (string) get_woocommerce_currency() : 'VND';
	}

	private static function now(): int {
		return isset( self::$readers['now'] ) ? (int) call_user_func( self::$readers['now'] ) : time();
	}

	/** Same keys as the old plugin exporter; a per-person slot carries the generation so flush() drops every person. */
	private static function cache_key( string $slot ): string {
		$blog = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
		if ( false !== strpos( $slot, '_u' ) ) {
			$slot .= '_g' . ( function_exists( 'get_option' ) ? (int) get_option( self::CACHE_PREFIX . 'customers_gen', 0 ) : 0 );
		}
		return self::CACHE_PREFIX . $slot . '_' . $blog;
	}
}
