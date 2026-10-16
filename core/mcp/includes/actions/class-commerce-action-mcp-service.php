<?php
/**
 * BizCity_Commerce_Action_MCP_Service — order / inventory tools of the one MCP standard (PHASE-0.88 L1-9, lane CL-B):
 * order.create, inventory.reserve, inventory.release, inventory.check.
 *
 * Q88-2 (fixture inventory.reserve.json): no reservation table. A hold = a WooCommerce `pending` order (created by the
 * CRM order adapter when a customer is named, else wc_create_order) + Woo's own
 * Automattic\WooCommerce\Checkout\Helpers\ReserveStock (table wc_reserved_stock; Woo's cron cancels unpaid pending orders
 * after woocommerce_hold_stock_minutes). Orders made here carry order meta `_bizcity_mcp_hold`; inventory.release only
 * touches those. Missing ReserveStock ⇒ MCP_INVENTORY_RESERVE_UNAVAILABLE, never a silent success.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\MCP
 * @since      2026-10-01 (PHASE-0.88 CL-B)
 */

defined( 'ABSPATH' ) || exit;

// [2026-10-01 Claude Opus 5.5] PHASE-0.88 L1-9 — new file, order + inventory tools on BizCity_MCP_Tool_Registry.
final class BizCity_Commerce_Action_MCP_Service {

	const META_HOLD    = '_bizcity_mcp_hold';
	const META_HOLD_BY = '_bizcity_mcp_hold_by';
	const MAX_LINES    = 50;
	const META_MATCH   = '_bizcity_customer_match'; // [2026-10-11 Johnny Chu - Chu Hoàng Anh] PHASE-0.95 S95-F19 — 'pending' = chờ nhân viên ghép hồ sơ

	/** Woo's internal class (namespace may move between Woo versions — always class_exists first). Test seam. */
	public static $reserve_class = 'Automattic\\WooCommerce\\Checkout\\Helpers\\ReserveStock';

	public static function init() {
		add_action( 'bizcity_mcp_register_tools', array( __CLASS__, 'register_tools' ) );
	}

	public static function register_tools() {
		self::register_guest_tool(); // [2026-10-15 Johnny Chu - Chu Hoàng Anh] PHASE-0.115 S115-W
		$order_input = array( 'type' => 'object', 'required' => array( 'items' ), 'properties' => array(
			'contact_ref'   => array( 'type' => 'string' ),
			'items'         => array( 'type' => 'array', 'minItems' => 1, 'items' => array( 'type' => 'object', 'required' => array( 'product_id', 'qty' ), 'properties' => array( 'product_id' => array( 'type' => 'integer', 'minimum' => 1 ), 'qty' => array( 'type' => 'integer', 'minimum' => 1 ) ) ) ),
			'hold_minutes'  => array( 'type' => 'integer', 'minimum' => 5, 'maximum' => 1440 ),
			'note'          => array( 'type' => 'string' ),
			// [2026-10-09 03:14 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.95-S95-F9 — the handler also reads a top-level `contact{platform, channel_ref,
			// platform_uid, display_name}` (doc 25 §2.1). Not listed here yet: this descriptor is frozen byte-for-byte in the shared fixture
			// zalo-hub/contracts/fixtures/mcp/bridge.tools_list.owner.json — the schema line lands with that fixture bump (S95-R2).
			'confirm_token' => array( 'type' => 'string' ),
		) );
		$order_output = BizCity_MCP_Tool_Registry::envelope_schema( array(
			'status'          => array( 'type' => 'string', 'enum' => array( 'needs_confirmation', 'done' ) ),
			'preview'         => array( 'type' => 'object' ),
			'confirm_token'   => array( 'type' => 'string' ),
			'expires_at'      => array( 'type' => 'string' ),
			'order_id'        => array( 'type' => 'integer' ),
			'reserved'        => array( 'type' => 'array' ),
			'hold_expires_at' => array( 'type' => 'string' ),
		) );
		// [2026-10-11 Johnny Chu - Chu Hoàng Anh] PHASE-0.95 S95-F19 — ca thật: model tra CRM để lấy contact_ref, không thấy hồ sơ ⇒ không lên đơn.
		// order.create nhận thẳng thông tin giao hàng khách tự khai; ghép hồ sơ CRM là việc của nhân viên (handoff), không chặn đơn.
		$order_create_input = $order_input;
		$order_create_input['properties'] = array_slice( $order_input['properties'], 0, 4, true ) + array(
			'customer' => array( 'type' => 'object', 'properties' => array(
				'name'    => array( 'type' => 'string', 'maxLength' => 80 ),
				'phone'   => array( 'type' => 'string', 'maxLength' => 20 ),
				'address' => array( 'type' => 'string', 'maxLength' => 255 ),
				'email'   => array( 'type' => 'string', 'maxLength' => 120 ),
			) ),
		) + array_slice( $order_input['properties'], 4, null, true );

		// @mcp bizcity-mcp-standard@1 tool order.create
		BizCity_MCP_Tool_Registry::register( 'order.create', array(
			'title'          => 'Tạo đơn nháp (giữ hàng)',
			'description'    => 'Tạo đơn WooCommerce trạng thái chờ (pending) và giữ hàng trong hold_minutes. Không cần hồ sơ CRM hay mã khách: ghi tên, số điện thoại, địa chỉ giao khách đã nói vào customer (không tra CRM trước khi tạo đơn); nhân viên ghép hồ sơ sau. contact_ref chỉ dùng khi đã có sẵn. Lần gọi đầu chỉ trả bản xem trước + confirm_token; gọi lại cùng tham số kèm confirm_token sau khi người dùng đồng ý.',
			'input_schema'   => $order_create_input,
			'output_schema'  => $order_output,
			'read_only'      => false,
			'destructive'    => false,
			'idempotent'     => false,
			'required_scope' => 'order.write',
			'required_cap'   => 'edit_shop_orders', // [2026-10-11 Johnny Chu - Chu Hoàng Anh] PHASE-0.97 S97-L12
			'handler'        => array( __CLASS__, 'create_order' ),
			'preview'        => array( __CLASS__, 'preview_order' ),
			'mode'           => 'orders',
			'scopes'         => array( 'order.write' ),
			'confirm'        => 'always',
			'llm_alias'      => 'order_create',
			'fallback_pack'  => null,
			'since'          => '0.88.3',
		) );

		// @mcp bizcity-mcp-standard@1 tool inventory.reserve
		BizCity_MCP_Tool_Registry::register( 'inventory.reserve', array(
			'title'          => 'Giữ hàng',
			'description'    => 'Giữ số lượng sản phẩm trong hold_minutes (mặc định 30, tối đa theo thiết lập giữ hàng của WooCommerce) bằng một đơn nháp; không bắt buộc có khách. Lần gọi đầu chỉ trả bản xem trước + confirm_token; gọi lại cùng tham số kèm confirm_token sau khi người dùng đồng ý. Nhả hàng sớm bằng inventory.release.',
			'input_schema'   => $order_input,
			'output_schema'  => $order_output,
			'read_only'      => false,
			'destructive'    => false,
			'idempotent'     => false,
			'required_scope' => 'inventory.write',
			'required_cap'   => 'manage_woocommerce', // [2026-10-11 Johnny Chu - Chu Hoàng Anh] PHASE-0.97 S97-L12
			'handler'        => array( __CLASS__, 'reserve' ),
			'preview'        => array( __CLASS__, 'preview_reserve' ),
			'mode'           => 'orders',
			'scopes'         => array( 'inventory.write' ),
			'confirm'        => 'always',
			'llm_alias'      => 'inventory_reserve',
			'fallback_pack'  => null,
			'since'          => '0.88.3',
		) );

		// @mcp bizcity-mcp-standard@1 tool inventory.release
		BizCity_MCP_Tool_Registry::register( 'inventory.release', array(
			'title'          => 'Nhả hàng đang giữ',
			'description'    => 'Nhả hàng của một đơn giữ hàng do Agent tạo (order.create / inventory.reserve) và huỷ đơn nháp đó. Không dùng được cho đơn khách đặt thật.',
			'input_schema'   => array( 'type' => 'object', 'required' => array( 'order_id' ), 'properties' => array( 'order_id' => array( 'type' => 'integer', 'minimum' => 1 ) ) ),
			'output_schema'  => BizCity_MCP_Tool_Registry::envelope_schema( array( 'order_id' => array( 'type' => 'integer' ), 'released' => array( 'type' => 'array' ), 'order_status' => array( 'type' => 'string' ) ), array( 'order_id', 'released' ) ),
			'read_only'      => false,
			'destructive'    => true,
			'idempotent'     => true,
			'required_scope' => 'inventory.write',
			'required_cap'   => 'manage_woocommerce', // [2026-10-11 Johnny Chu - Chu Hoàng Anh] PHASE-0.97 S97-L12
			'handler'        => array( __CLASS__, 'release' ),
			'mode'           => 'orders',
			'scopes'         => array( 'inventory.write' ),
			'confirm'        => 'never',
			'llm_alias'      => 'inventory_release',
			'fallback_pack'  => null,
			'since'          => '0.88.3',
		) );

		// @mcp bizcity-mcp-standard@1 tool inventory.check
		BizCity_MCP_Tool_Registry::register( 'inventory.check', array(
			'executor'       => 'inventory.stock.read', // [2026-10-16 04:36 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.116 S116-B2
			'title'          => 'Kiểm tra tồn kho',
			'description'    => 'Tồn kho theo sản phẩm: tồn, đang giữ cho đơn chờ, còn bán được (available = tồn − đang giữ), trạng thái; kèm danh sách sắp hết / hết hàng. Không có product_ids thì xem các sản phẩm đang bán (tối đa limit).',
			'input_schema'   => array( 'type' => 'object', 'properties' => array(
				'product_ids'         => array( 'type' => 'array', 'maxItems' => self::MAX_LINES, 'items' => array( 'type' => 'integer', 'minimum' => 1 ) ),
				'limit'               => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20 ),
				'low_stock_threshold' => array( 'type' => 'integer', 'minimum' => 0, 'default' => 5 ),
			) ),
			'output_schema'  => BizCity_MCP_Tool_Registry::envelope_schema( array( 'items' => array( 'type' => 'array' ), 'low_stock' => array( 'type' => 'array' ), 'out_of_stock' => array( 'type' => 'array' ), 'reserve_supported' => array( 'type' => 'boolean' ) ), array( 'items', 'low_stock', 'out_of_stock' ) ),
			'read_only'      => true,
			'idempotent'     => true,
			'required_scope' => 'business.read',
			'handler'        => array( __CLASS__, 'check' ),
			'mode'           => 'stock',
			'scopes'         => array( 'business.read' ),
			'confirm'        => 'never',
			'llm_alias'      => 'biz_stock',
			'fallback_pack'  => 'stock',
			'since'          => '0.88.2',
		) );
	}

	/* ── order.place (customer of a chat channel) ─────────────────── */

	/** Test seams: product(id) · counts(identity): array{pending:int,today:int} · new_order(plan) · reserve(order, minutes): bool · pay_info(order_id, ctx): array|WP_Error|null */
	public static $guest_seams = array();

	private static function guest_seam( $name, $default, array $args = array() ) {
		return isset( self::$guest_seams[ $name ] ) ? call_user_func_array( self::$guest_seams[ $name ], $args ) : call_user_func_array( $default, $args );
	}

	/** Limits a guest order obeys (filter `bizcity_guest_order_limits`) — the owner can only make them stricter or looser on purpose, never remove them */
	public static function guest_limits() {
		// [2026-10-15 Johnny Chu - Chu Hoàng Anh] chủ: chặn chống spam từ 5 đơn chờ thanh toán trở lên (trước là 2); trần/ngày nâng 10 để không chặn sớm hơn trần chờ
		$d = array( 'lines' => 5, 'qty' => 20, 'pending' => 5, 'per_day' => 10 );
		$l = function_exists( 'apply_filters' ) ? (array) apply_filters( 'bizcity_guest_order_limits', $d ) : $d;
		return array(
			'lines'   => max( 1, min( 20, (int) ( $l['lines'] ?? $d['lines'] ) ) ),
			'qty'     => max( 1, min( 100, (int) ( $l['qty'] ?? $d['qty'] ) ) ),
			'pending' => max( 1, min( 10, (int) ( $l['pending'] ?? $d['pending'] ) ) ),
			'per_day' => max( 1, min( 50, (int) ( $l['per_day'] ?? $d['per_day'] ) ) ),
		);
	}

	private static function register_guest_tool() {
		$input = array( 'type' => 'object', 'required' => array( 'items', 'customer' ), 'properties' => array(
			'items'         => array( 'type' => 'array', 'minItems' => 1, 'maxItems' => 20, 'items' => array( 'type' => 'object', 'properties' => array(
				'product_id' => array( 'type' => 'integer', 'minimum' => 1 ),
				'ref'        => array( 'type' => 'string', 'maxLength' => 80 ),
				'name'       => array( 'type' => 'string', 'maxLength' => 120 ), // [2026-10-15 Johnny Chu - Chu Hoàng Anh] PHASE-0.115: the site finds the product by its name (one cheap query), no id needed
				'qty'        => array( 'type' => 'integer', 'minimum' => 1 ),
			) ) ),
			'customer'      => array( 'type' => 'object', 'properties' => array(
				'name'    => array( 'type' => 'string', 'maxLength' => 80 ),
				'phone'   => array( 'type' => 'string', 'maxLength' => 20 ),
				'address' => array( 'type' => 'string', 'maxLength' => 255 ),
				'email'   => array( 'type' => 'string', 'maxLength' => 120 ),
			) ),
			'note'          => array( 'type' => 'string', 'maxLength' => 300 ),
			'confirm_token' => array( 'type' => 'string' ),
		) );
		$output = BizCity_MCP_Tool_Registry::envelope_schema( array(
			'status'          => array( 'type' => 'string', 'enum' => array( 'needs_confirmation', 'done' ) ),
			'preview'         => array( 'type' => 'object' ),
			'confirm_token'   => array( 'type' => 'string' ),
			'expires_at'      => array( 'type' => 'string' ),
			'order_id'        => array( 'type' => 'integer' ),
			'total'           => array( 'type' => 'number' ),
			'currency'        => array( 'type' => 'string' ),
			'hold_expires_at' => array( 'type' => 'string' ),
			'payment'         => array( 'type' => 'object' ),
		) );
		// @mcp bizcity-mcp-standard@1 tool order.place
		BizCity_MCP_Tool_Registry::register( 'order.place', array(
			'executor'       => 'order.place', // [2026-10-16 04:36 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.116 S116-B2
			'title'          => 'Khách đặt hàng (kênh chat)',
			'description'    => 'Khách đang chat tự đặt đơn: tên người nhận, SĐT, địa chỉ + dòng hàng (product_id hoặc ref "product:ID", qty). Giá luôn lấy từ website. Lần đầu chỉ trả bản xem trước + confirm_token; gọi lại ĐÚNG tham số kèm confirm_token sau khi khách đồng ý. Khách là người đang nhắn (không phải đối số); khách mới được ghi vào CRM.',
			'input_schema'   => $input,
			'output_schema'  => $output,
			'read_only'      => false,
			'destructive'    => false,
			'idempotent'     => false,
			'required_scope' => 'commerce.order.guest',
			'handler'        => array( __CLASS__, 'guest_place' ),
			'preview'        => array( __CLASS__, 'guest_preview' ),
			'mode'           => 'stock',
			'roles'          => array( 'customer' ),
			'scope'          => 'thread',
			'scopes'         => array( 'commerce.order.guest' ),
			'confirm'        => 'always',
			'llm_alias'      => 'order_place',
			'fallback_pack'  => null,
			'since'          => '0.115.0',
		) );
	}

	/**
	 * [2026-10-15 Johnny Chu - Chu Hoàng Anh] PHASE-0.115 — the product a customer's words point to, found ON THE SITE with ONE light query (publish only, newest first, ≤ 8 rows, no popularity sort, no scoring): the order no longer depends on
	 * the heavy consult search (which timed out at 6 s on a slow site). Unique match ⇒ id; several ⇒ an error that LISTS them so the bot asks; none ⇒ not found. Seam guest_seams['find'](name): array of {id,name}.
	 *
	 * @return int|WP_Error
	 */
	private static function find_product_by_name( string $name ) {
		$name = trim( function_exists( 'sanitize_text_field' ) ? sanitize_text_field( $name ) : $name );
		if ( '' === $name ) {
			return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::QUERY_INVALID, 'Chưa rõ khách chọn sản phẩm nào.', 422 );
		}
		$rows = (array) self::guest_seam( 'find', static function ( $name ) {
			if ( ! function_exists( 'wc_get_products' ) ) {
				return array();
			}
			$out = array();
			foreach ( (array) wc_get_products( array( 'status' => 'publish', 'limit' => 8, 'return' => 'objects', 's' => $name, 'orderby' => 'date', 'order' => 'DESC' ) ) as $p ) {
				if ( is_object( $p ) ) {
					$out[] = array( 'id' => (int) $p->get_id(), 'name' => (string) $p->get_name() );
				}
			}
			return $out;
		}, array( $name ) );
		$plain = static function ( $s ) {
			$s = function_exists( 'remove_accents' ) ? remove_accents( (string) $s ) : (string) $s;
			return trim( (string) preg_replace( '/[^a-z0-9]+/', ' ', strtolower( str_replace( 'đ', 'd', mb_strtolower( $s, 'UTF-8' ) ) ) ) );
		};
		$n    = $plain( $name );
		$hits = array_values( array_filter( $rows, static function ( $r ) use ( $plain, $n ) {
			$t = $plain( $r['name'] );
			return '' !== $n && ( false !== strpos( $t, $n ) || false !== strpos( $n, $t ) );
		} ) );
		$pick = $hits ?: $rows;
		if ( 1 === count( $pick ) ) {
			return (int) $pick[0]['id'];
		}
		if ( ! $pick ) {
			return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::NOT_FOUND, 'Không tìm thấy sản phẩm tên «' . $name . '» đang bán.', 404 );
		}
		$list = array_map( static function ( $r ) { return $r['name']; }, array_slice( $pick, 0, 5 ) );
		return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::QUERY_INVALID, 'Có nhiều sản phẩm khớp «' . $name . '»: ' . implode( ' · ', $list ) . '. Hỏi khách chọn món nào.', 422, array( 'candidates' => $list ) );
	}

	/** {platform, channel_ref, platform_uid} of the channel customer — from the context the web bridge built from the Hub header, NEVER from the arguments */
	private static function guest_identity( array $ctx ) {
		$c        = is_array( $ctx['customer'] ?? null ) ? $ctx['customer'] : array();
		$platform = strtolower( trim( (string) ( $c['platform'] ?? '' ) ) );
		$ref      = trim( (string) ( $c['channel_ref'] ?? '' ) );
		$uid      = trim( (string) ( $c['platform_uid'] ?? '' ) );
		if ( '' === $platform || '' === $ref || '' === $uid ) {
			return null;
		}
		return array(
			'platform'     => class_exists( 'BizCity_CRM_Contact_Identity' ) ? BizCity_CRM_Contact_Identity::canon_platform( $platform ) : $platform,
			'channel_ref'  => substr( $ref, 0, 190 ),
			'platform_uid' => substr( $uid, 0, 190 ),
			'display_name' => '',
		);
	}

	/** Orders this customer already has: waiting for payment, and made today */
	private static function guest_counts( array $id ) {
		return (array) self::guest_seam( 'counts', static function ( $id ) {
			if ( ! function_exists( 'wc_get_orders' ) ) {
				return array( 'pending' => 0, 'today' => 0 );
			}
			$meta = array(
				'relation' => 'AND',
				array( 'key' => '_bizcity_platform_uid', 'value' => $id['platform_uid'] ),
				array( 'key' => '_bizcity_channel_ref', 'value' => $id['channel_ref'] ),
				array( 'key' => self::META_GUEST, 'value' => '1' ),
			);
			$pending = wc_get_orders( array( 'limit' => 20, 'return' => 'ids', 'status' => array( 'pending', 'on-hold' ), 'meta_query' => $meta ) );
			$today   = wc_get_orders( array( 'limit' => 60, 'return' => 'ids', 'date_created' => '>' . ( time() - DAY_IN_SECONDS ), 'meta_query' => $meta ) );
			return array( 'pending' => count( (array) $pending ), 'today' => count( (array) $today ) );
		}, array( $id ) );
	}

	/**
	 * Validate + price + availability for a CUSTOMER order, side-effect free (preview and commit share it). Prices come from WooCommerce at this moment — there is no
	 * price argument and no free-text line at all, so a customer (or text injected into the model) cannot make an order cheaper or invent a product.
	 *
	 * @return array|WP_Error
	 */
	private static function guest_plan( array $args, array $ctx ) {
		$id = self::guest_identity( $ctx );
		if ( null === $id ) {
			return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::SCOPE_DENIED, 'Kênh này chưa nhận ra khách để đặt hàng.', 403 );
		}
		$rs = self::reserve_stock();
		if ( null === $rs && ! isset( self::$guest_seams['product'] ) ) {
			return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::INVENTORY_RESERVE_UNAVAILABLE, 'Cửa hàng chưa bật giữ hàng của WooCommerce.', 503 );
		}
		$lim    = self::guest_limits();
		$qty_by = array();
		foreach ( array_slice( (array) ( $args['items'] ?? array() ), 0, $lim['lines'] + 1 ) as $row ) {
			$row = is_array( $row ) ? $row : array();
			$pid = (int) ( $row['product_id'] ?? 0 );
			if ( $pid <= 0 && 1 === preg_match( '/^product:([1-9][0-9]{0,17})$/', trim( (string) ( $row['ref'] ?? '' ) ), $m ) ) {
				$pid = (int) $m[1];
			}
			$qty = (int) ( $row['qty'] ?? 0 );
			if ( $pid <= 0 && '' !== trim( (string) ( $row['name'] ?? '' ) ) ) {
				$found = self::find_product_by_name( (string) $row['name'] );
				if ( is_wp_error( $found ) ) {
					return $found;
				}
				$pid = (int) $found;
			}
			if ( $pid <= 0 || $qty < 1 || $qty > $lim['qty'] ) {
				return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::QUERY_INVALID, 'Mỗi dòng cần sản phẩm và số lượng từ 1 đến ' . $lim['qty'] . '.', 422 );
			}
			$qty_by[ $pid ] = ( $qty_by[ $pid ] ?? 0 ) + $qty;
		}
		if ( ! $qty_by || count( $qty_by ) > $lim['lines'] ) {
			return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::QUERY_INVALID, 'Một đơn từ 1 đến ' . $lim['lines'] . ' sản phẩm.', 422 );
		}
		foreach ( $qty_by as $q ) {
			if ( $q > $lim['qty'] ) {
				return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::QUERY_INVALID, 'Mỗi sản phẩm tối đa ' . $lim['qty'] . ' cái; đơn lớn hơn nhờ nhân viên hỗ trợ.', 422 );
			}
		}
		// who the goods go to: all three are needed, nothing is guessed (the model asks ONE merged question)
		$customer = self::customer_of( $args );
		$missing  = array();
		if ( ! is_array( $customer ) || mb_strlen( (string) $customer['name'] ) < 2 ) {
			$missing[] = 'name';
		}
		if ( ! is_array( $customer ) || '' === (string) $customer['phone'] ) {
			$missing[] = 'phone';
		}
		if ( ! is_array( $customer ) || mb_strlen( (string) $customer['address'] ) < 8 ) {
			$missing[] = 'address';
		}
		if ( $missing ) {
			return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::QUERY_INVALID, 'Cần đủ tên người nhận, số điện thoại và địa chỉ giao hàng (thiếu: ' . implode( ', ', $missing ) . ').', 422, array( 'missing' => $missing ) );
		}
		$id['display_name'] = (string) $customer['name'];
		$counts = self::guest_counts( $id );
		if ( (int) ( $counts['pending'] ?? 0 ) >= $lim['pending'] ) {
			return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::RATE_LIMITED, 'Bạn đang có ' . (int) $counts['pending'] . ' đơn chờ thanh toán. Hãy thanh toán hoặc đợi đơn cũ hết hạn trước khi đặt thêm.', 429, array( 'reason' => 'guest_order_pending_cap' ) );
		}
		if ( (int) ( $counts['today'] ?? 0 ) >= $lim['per_day'] ) {
			return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::RATE_LIMITED, 'Hôm nay đã đủ số đơn cho phép qua tin nhắn. Nhân viên sẽ hỗ trợ thêm.', 429, array( 'reason' => 'guest_order_day_cap' ) );
		}
		$allowed_cats = array_values( array_filter( array_map( 'intval', (array) ( $ctx['allowed_product_cat_ids'] ?? array() ) ) ) );
		$lines = array();
		$total = 0.0;
		$short = array();
		foreach ( $qty_by as $pid => $qty ) {
			$product = self::guest_seam( 'product', static function ( $pid ) {
				return function_exists( 'wc_get_product' ) ? wc_get_product( $pid ) : null;
			}, array( $pid ) );
			if ( ! $product || ! is_object( $product ) || 'publish' !== (string) $product->get_status() || ! $product->is_purchasable() ) {
				return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::NOT_FOUND, 'Không tìm thấy sản phẩm #' . $pid . ' đang bán.', 404 );
			}
			if ( ! $product->is_type( 'simple' ) && ! $product->is_type( 'variation' ) ) {
				return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::QUERY_INVALID, 'Sản phẩm #' . $pid . ' cần chọn phiên bản; nhờ nhân viên hỗ trợ.', 422 );
			}
			if ( $allowed_cats && ! array_intersect( $allowed_cats, array_map( 'intval', (array) $product->get_category_ids() ) ) ) {
				return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::NOT_FOUND, 'Không tìm thấy sản phẩm #' . $pid . ' đang bán.', 404 );
			}
			$unit = self::num( $product->get_price() );
			if ( $unit <= 0 ) {
				return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::QUERY_INVALID, 'Sản phẩm #' . $pid . ' chưa có giá; nhờ nhân viên báo giá.', 422 );
			}
			$row = self::stock_row( $product, $rs );
			if ( 'outofstock' === $row['stock_status'] || ( null !== $row['available'] && $qty > $row['available'] ) ) {
				$short[] = array( 'product_id' => $pid, 'name' => $row['name'], 'requested' => $qty, 'available' => null === $row['available'] ? 0 : $row['available'] );
				continue;
			}
			$total  += $unit * $qty;
			$lines[] = array( 'product_id' => $pid, 'name' => $row['name'], 'qty' => $qty, 'unit_price' => $unit, 'line_total' => self::num( $unit * $qty ), 'product' => $product );
		}
		if ( $short ) {
			$s = $short[0];
			return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::INVENTORY_NOT_ENOUGH, 'Không đủ hàng: ' . $s['name'] . ' còn ' . $s['available'] . ', cần ' . $s['requested'] . '.', 409, array( 'short' => $short ) );
		}
		return array(
			'lines'        => $lines,
			'total'        => self::num( $total ),
			'currency'     => function_exists( 'get_woocommerce_currency' ) ? (string) get_woocommerce_currency() : 'VND',
			'hold_minutes' => self::hold_minutes( 0 ),
			'contact'      => null,
			'customer'     => $customer,
			'identity'     => $id,
			'note'         => trim( function_exists( 'sanitize_textarea_field' ) ? sanitize_textarea_field( (string) ( $args['note'] ?? '' ) ) : (string) ( $args['note'] ?? '' ) ),
		);
	}

	public static function guest_preview( array $args, array $ctx ) {
		$plan = self::guest_plan( $args, $ctx );
		if ( is_wp_error( $plan ) ) {
			return $plan;
		}
		$parts = array();
		foreach ( $plan['lines'] as $l ) {
			$parts[] = $l['qty'] . ' × ' . $l['name'] . ' (' . self::money( $l['unit_price'], $plan['currency'] ) . ')';
		}
		return array(
			'summary'      => 'Đặt hàng: ' . implode( ', ', $parts ) . ' = ' . self::money( $plan['total'], $plan['currency'] ) . '. Giao: ' . self::ship_line( $plan['customer'] ) . '. Giữ hàng ' . $plan['hold_minutes'] . ' phút chờ thanh toán.',
			'lines'        => array_map( static function ( $l ) { unset( $l['product'] ); return $l; }, $plan['lines'] ),
			'total'        => $plan['total'],
			'currency'     => $plan['currency'],
			'hold_minutes' => $plan['hold_minutes'],
		);
	}

	/** Commit (after the registry's confirm gate): order + stock hold + CRM contact (new customer recorded) + the payment info of THIS order, in one answer */
	public static function guest_place( array $args, array $ctx ) {
		$plan = self::guest_plan( $args, $ctx ); // availability, caps and the identity are re-checked at commit time
		if ( is_wp_error( $plan ) ) {
			return $plan;
		}
		$order = self::guest_seam( 'new_order', static function ( $plan ) {
			return self::new_order( $plan ); // apply_customer + stamp_identity (resolve_or_create: a new customer becomes a CRM contact)
		}, array( $plan ) );
		if ( is_wp_error( $order ) ) {
			return $order;
		}
		$order->update_meta_data( self::META_HOLD, 1 );
		$order->update_meta_data( self::META_HOLD_BY, 0 );
		$order->update_meta_data( self::META_GUEST, 1 );
		$method = (string) self::guest_seam( 'payment_method', array( __CLASS__, 'guest_payment_method' ) );
		if ( '' !== $method && method_exists( $order, 'set_payment_method' ) ) {
			$order->set_payment_method( $method );
		}
		$order->add_order_note( 'Khách đặt hàng qua tin nhắn (' . $plan['identity']['platform'] . '); nhân viên xác nhận và gọi lại nếu cần.' );
		$order->save();
		$expires = time() + $plan['hold_minutes'] * 60;
		$held    = (bool) self::guest_seam( 'reserve', static function ( $order, $minutes ) {
			$class = self::$reserve_class;
			if ( ! class_exists( $class ) ) {
				return true; // plan() already refused when the hold is unavailable
			}
			$rs = new $class();
			try {
				$rs->reserve_stock_for_order( $order, $minutes );
				return true;
			} catch ( \Exception $e ) {
				$rs->release_stock_for_order( $order );
				return false;
			}
		}, array( $order, $plan['hold_minutes'] ) );
		if ( ! $held ) {
			$order->update_status( 'cancelled', 'Không giữ được hàng (khách đặt qua tin nhắn).' );
			return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::INVENTORY_NOT_ENOUGH, 'Không đủ hàng để giữ (vừa có đơn khác giữ). Kiểm tra lại số lượng.', 409 );
		}
		$order_id = (int) $order->get_id();
		// the order EXISTS now: nothing in the payment lookup may turn it into an error answer (the customer would retry and the caps would count a second order)
		try {
			$pay = self::guest_seam( 'pay_info', static function ( $order_id, $ctx ) {
				return class_exists( 'BizCity_Commerce_Brain_MCP_Service' ) ? BizCity_Commerce_Brain_MCP_Service::instance()->pay_info( array( 'order_id' => $order_id ), $ctx ) : null;
			}, array( $order_id, $ctx ) );
		} catch ( Throwable $e ) {
			$pay = null;
		}
		return array(
			'status'          => 'done',
			'order_id'        => $order_id,
			'total'           => $plan['total'],
			'currency'        => $plan['currency'],
			'hold_expires_at' => BizCity_MCP_Action_Support::iso( $expires ),
			'payment'         => ( is_array( $pay ) && ! empty( $pay['order_id'] ) ) ? $pay : null, // null = no TTCK / COD: the staff sends the payment details
		);
	}

	/** The payment method of a guest order: the first TTCK gateway when it is active, else bank transfer; filter `bizcity_guest_order_payment_method` */
	public static function guest_payment_method() {
		$id = '';
		if ( function_exists( 'WC' ) && is_object( WC() ) && is_object( WC()->payment_gateways() ) ) {
			foreach ( (array) WC()->payment_gateways()->get_available_payment_gateways() as $gid => $gw ) {
				if ( 0 === strpos( (string) $gid, 'ttck' ) ) {
					$id = (string) $gid;
					break;
				}
			}
			if ( '' === $id && isset( WC()->payment_gateways()->payment_gateways()['bacs'] ) ) {
				$id = 'bacs';
			}
		}
		return (string) apply_filters( 'bizcity_guest_order_payment_method', $id );
	}

	const META_GUEST = '_bizcity_guest_order';

	/* ── order.create / inventory.reserve ────────────────────────── */

	public static function preview_order( array $args, array $ctx ) {
		return self::preview( $args, $ctx, 'Tạo đơn nháp' );
	}

	public static function preview_reserve( array $args, array $ctx ) {
		return self::preview( $args, $ctx, 'Giữ hàng' );
	}

	public static function create_order( array $args, array $ctx ) {
		return self::commit( $args, $ctx );
	}

	/** Thin alias of order.create (contact optional there too): same plan, same draft order + Woo hold. */
	public static function reserve( array $args, array $ctx ) {
		return self::commit( $args, $ctx );
	}

	private static function preview( array $args, array $ctx, $verb ) {
		return BizCity_MCP_Action_Support::run_as( $ctx, static function ( $uid ) use ( $args, $verb ) {
			$plan = self::plan( $args, $uid );
			if ( is_wp_error( $plan ) ) {
				return $plan;
			}
			$parts = array();
			foreach ( $plan['lines'] as $l ) {
				$parts[] = $l['qty'] . ' × ' . $l['name'] . ' (' . self::money( $l['unit_price'], $plan['currency'] ) . ')';
			}
			$who  = $plan['contact'] ? ' cho ' . $plan['contact']['name'] : '';
			$ship = self::ship_line( $plan['customer'] );
			return array(
				'summary'      => $verb . $who . ': ' . implode( ', ', $parts ) . ' = ' . self::money( $plan['total'], $plan['currency'] ) . ', giữ hàng ' . $plan['hold_minutes'] . ' phút.' . ( '' !== $ship ? ' Giao: ' . $ship . '.' : '' ),
				'lines'        => array_map( static function ( $l ) { unset( $l['product'] ); return $l; }, $plan['lines'] ),
				'total'        => $plan['total'],
				'currency'     => $plan['currency'],
				'hold_minutes' => $plan['hold_minutes'],
			);
		} );
	}

	private static function commit( array $args, array $ctx ) {
		return BizCity_MCP_Action_Support::run_as( $ctx, static function ( $uid ) use ( $args ) {
			$plan = self::plan( $args, $uid ); // availability re-checked at commit time
			if ( is_wp_error( $plan ) ) {
				return $plan;
			}
			$order = self::new_order( $plan );
			if ( is_wp_error( $order ) ) {
				return $order;
			}
			$expires = time() + $plan['hold_minutes'] * 60;
			$order->update_meta_data( self::META_HOLD, 1 );
			$order->update_meta_data( self::META_HOLD_BY, (int) $uid );
			$order->save();
			$class = self::$reserve_class;
			$rs    = new $class();
			try {
				$rs->reserve_stock_for_order( $order, $plan['hold_minutes'] );
			} catch ( \Exception $e ) {
				// Someone else took the stock between plan and reserve: undo the draft, say so.
				$rs->release_stock_for_order( $order );
				$order->update_status( 'cancelled', 'Không giữ được hàng (Agent MCP).' );
				return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::INVENTORY_NOT_ENOUGH, 'Không đủ hàng để giữ (vừa có đơn khác giữ). Kiểm tra lại tồn kho.', 409 );
			}
			$reserved = array();
			foreach ( $plan['lines'] as $l ) {
				$reserved[] = array( 'product_id' => $l['product_id'], 'qty' => $l['qty'] );
			}
			return array(
				'status'          => 'done',
				'order_id'        => (int) $order->get_id(),
				'reserved'        => $reserved,
				'hold_expires_at' => BizCity_MCP_Action_Support::iso( $expires ),
				'total'           => $plan['total'],
				'currency'        => $plan['currency'],
			);
		} );
	}

	/**
	 * Validate + price + availability, side-effect free (preview and commit share it).
	 *
	 * @return array|WP_Error {lines[], total, currency, hold_minutes, contact|null, note}
	 */
	private static function plan( array $args, $uid ) {
		if ( ! BizCity_MCP_Action_Support::can_sell( $uid ) ) {
			return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::SCOPE_DENIED, 'Bạn chưa có quyền tạo đơn / giữ hàng.', 403 );
		}
		$rs = self::reserve_stock();
		if ( null === $rs || ! function_exists( 'wc_get_product' ) ) {
			return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::INVENTORY_RESERVE_UNAVAILABLE, 'Cửa hàng chưa bật giữ hàng của WooCommerce.', 503 );
		}
		$qty_by = array();
		foreach ( array_slice( (array) ( $args['items'] ?? array() ), 0, self::MAX_LINES + 1 ) as $row ) {
			$pid = (int) ( is_array( $row ) ? ( $row['product_id'] ?? 0 ) : 0 );
			$qty = (int) ( is_array( $row ) ? ( $row['qty'] ?? 0 ) : 0 );
			if ( $pid <= 0 || $qty <= 0 ) {
				return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::QUERY_INVALID, 'Mỗi dòng cần product_id và qty ≥ 1.', 422 );
			}
			$qty_by[ $pid ] = ( $qty_by[ $pid ] ?? 0 ) + $qty;
		}
		if ( ! $qty_by || count( $qty_by ) > self::MAX_LINES ) {
			return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::QUERY_INVALID, 'Cần từ 1 đến 50 sản phẩm.', 422 );
		}
		$contact = null;
		$ref     = trim( (string) ( $args['contact_ref'] ?? '' ) );
		if ( '' !== $ref ) {
			$cid = BizCity_MCP_Action_Support::contact_id( $ref );
			$contact = $cid > 0 ? BizCity_MCP_Action_Support::contact_access( $cid, false, $uid ) : BizCity_MCP_Action_Support::error( BizCity_MCP_Error::QUERY_INVALID, 'contact_ref không hợp lệ (ví dụ "crm:731").', 422 );
			if ( is_wp_error( $contact ) ) {
				return $contact;
			}
		}
		$lines = array();
		$total = 0.0;
		$short = array();
		foreach ( $qty_by as $pid => $qty ) {
			$product = wc_get_product( $pid );
			if ( ! $product ) {
				return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::NOT_FOUND, 'Không tìm thấy sản phẩm #' . $pid . '.', 404 );
			}
			$row = self::stock_row( $product, $rs );
			if ( 'outofstock' === $row['stock_status'] || ( null !== $row['available'] && $qty > $row['available'] ) ) {
				$short[] = array( 'product_id' => $pid, 'name' => $row['name'], 'requested' => $qty, 'available' => null === $row['available'] ? 0 : $row['available'] );
				continue;
			}
			$unit    = self::num( $product->get_price() );
			$total  += $unit * $qty;
			$lines[] = array( 'product_id' => $pid, 'name' => $row['name'], 'qty' => $qty, 'unit_price' => $unit, 'line_total' => self::num( $unit * $qty ), 'product' => $product );
		}
		if ( $short ) {
			$s = $short[0];
			return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::INVENTORY_NOT_ENOUGH, 'Không đủ hàng: ' . $s['name'] . ' còn ' . $s['available'] . ', cần ' . $s['requested'] . '.', 409, array( 'short' => $short ) );
		}
		return array(
			'lines'        => $lines,
			'total'        => self::num( $total ),
			'currency'     => function_exists( 'get_woocommerce_currency' ) ? (string) get_woocommerce_currency() : 'VND',
			'hold_minutes' => self::hold_minutes( $args['hold_minutes'] ?? 0 ),
			'contact'      => $contact,
			'customer'     => self::customer_of( $args ),
			'identity'     => self::identity_of( $args ),
			'note'         => trim( sanitize_textarea_field( (string) ( $args['note'] ?? '' ) ) ),
		);
	}

	/**
	 * [2026-10-09 03:14 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.95-S95-F9 — top-level `contact` of order.create, cleaned (no lookup, no write:
	 * the plan is shared with the preview). null when there is no UID.
	 */
	private static function identity_of( array $args ) {
		$raw = isset( $args['contact'] ) && is_array( $args['contact'] ) ? $args['contact'] : array();
		$uid = substr( trim( (string) ( $raw['platform_uid'] ?? '' ) ), 0, 190 );
		if ( '' === $uid ) {
			return null;
		}
		$platform = (string) ( $raw['platform'] ?? 'zalo' );
		return array(
			'platform'     => class_exists( 'BizCity_CRM_Contact_Identity' ) ? BizCity_CRM_Contact_Identity::canon_platform( $platform ) : strtolower( $platform ),
			'channel_ref'  => substr( trim( (string) ( $raw['channel_ref'] ?? '' ) ), 0, 190 ),
			'platform_uid' => $uid,
			'display_name' => trim( sanitize_text_field( (string) ( $raw['display_name'] ?? '' ) ) ),
		);
	}

	/**
	 * [2026-10-11 Johnny Chu - Chu Hoàng Anh] PHASE-0.95 S95-F19 — `customer{name, phone, address, email}` khách tự khai, đã làm sạch; null khi trống.
	 * SĐT chỉ giữ chữ số (+ ở đầu), 8–15 số, sai thì bỏ (không chặn đơn).
	 */
	public static function customer_of( array $args ) {
		$raw   = isset( $args['customer'] ) && is_array( $args['customer'] ) ? $args['customer'] : array();
		$phone = preg_replace( '/[^\d+]/', '', (string) ( $raw['phone'] ?? '' ) );
		$phone = preg_match( '/^\+?\d{8,15}$/', $phone ) ? $phone : '';
		$email = trim( (string) ( $raw['email'] ?? '' ) );
		$c     = array(
			'name'    => mb_substr( trim( sanitize_text_field( (string) ( $raw['name'] ?? '' ) ) ), 0, 80 ),
			'phone'   => $phone,
			'address' => mb_substr( trim( sanitize_text_field( (string) ( $raw['address'] ?? '' ) ) ), 0, 255 ),
			'email'   => false !== filter_var( $email, FILTER_VALIDATE_EMAIL ) ? substr( $email, 0, 120 ) : '',
		);
		return '' === implode( '', $c ) ? null : $c;
	}

	/** Dòng giao hàng cho bản xem trước: SĐT che còn 3 số cuối. */
	private static function ship_line( $c ) {
		if ( ! is_array( $c ) ) {
			return '';
		}
		$phone = '' !== $c['phone'] ? str_repeat( '•', max( 0, strlen( $c['phone'] ) - 3 ) ) . substr( $c['phone'], -3 ) : '';
		return implode( ', ', array_filter( array( $c['name'], $phone, $c['address'] ) ) );
	}

	/** Ghi thông tin khách tự khai vào billing + shipping của đơn; đơn chưa gắn hồ sơ CRM ⇒ cờ cho nhân viên ghép (handoff). */
	private static function apply_customer( $order, array $plan ) {
		$c = $plan['customer'] ?? null;
		if ( ! is_object( $order ) ) {
			return;
		}
		if ( is_array( $c ) ) {
			if ( '' !== $c['name'] ) {
				$order->set_billing_first_name( $c['name'] );
				$order->set_shipping_first_name( $c['name'] );
			}
			if ( '' !== $c['phone'] ) {
				$order->set_billing_phone( $c['phone'] );
				if ( method_exists( $order, 'set_shipping_phone' ) ) {
					$order->set_shipping_phone( $c['phone'] );
				}
			}
			if ( '' !== $c['address'] ) {
				$order->set_billing_address_1( $c['address'] );
				$order->set_shipping_address_1( $c['address'] );
			}
			if ( '' !== $c['email'] ) {
				$order->set_billing_email( $c['email'] );
			}
		}
		if ( ! $plan['contact'] ) {
			$order->update_meta_data( self::META_MATCH, 'pending' );
			$order->add_order_note( 'Khách đặt qua Zalo Brain, chưa gắn hồ sơ CRM — nhân viên kiểm tra và ghép hồ sơ nếu cần.' );
		}
	}

	/** @var callable|null test seam: fn(string $canon, string $uid, string $channel_ref, array $data): int — replaces resolve_or_create */
	public static $contact_resolver = null;

	/**
	 * [2026-10-09 03:14 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.95-S95-F9 — stamp the 7 identity meta (before the caller's save()) and fill an empty
	 * billing name: named CRM contact > CRM contact of the identity > display_name. The UID never goes into a note.
	 */
	private static function stamp_identity( $order, array $plan ) {
		$id = $plan['identity'] ?? null;
		if ( ! is_array( $id ) || ! is_object( $order ) || ! class_exists( 'BizCity_CRM_Contact_Identity' ) ) {
			return;
		}
		$cid  = $plan['contact'] ? (int) $plan['contact']['contact_id'] : 0;
		$name = $plan['contact'] ? (string) $plan['contact']['name'] : '';
		if ( $cid <= 0 ) {
			$data = array( 'name' => '' !== $id['display_name'] ? $id['display_name'] : 'Khách ' . $id['platform'], 'source' => 'mcp_order_create' );
			// [2026-10-11 Johnny Chu - Chu Hoàng Anh] PHASE-0.95 S95-F19 — khách mới: ghi luôn tên/SĐT/email khách tự khai vào hồ sơ CRM
			$cust = is_array( $plan['customer'] ?? null ) ? $plan['customer'] : array();
			foreach ( array( 'name', 'phone', 'email' ) as $k ) {
				if ( '' !== ( $cust[ $k ] ?? '' ) ) {
					$data[ $k ] = $cust[ $k ];
				}
			}
			$cid  = is_callable( self::$contact_resolver )
				? (int) call_user_func( self::$contact_resolver, $id['platform'], $id['platform_uid'], $id['channel_ref'], $data )
				: (int) BizCity_CRM_Contact_Identity::resolve_or_create( $id['platform'], $id['platform_uid'], $id['channel_ref'], $data );
			$known = $cid > 0 ? BizCity_MCP_Action_Support::contact( $cid ) : null;
			$name  = is_array( $known ) ? (string) $known['name'] : '';
		}
		foreach ( BizCity_CRM_Contact_Identity::order_identity_meta( array(
			'platform'     => $id['platform'],
			'channel_ref'  => $id['channel_ref'],
			'platform_uid' => $id['platform_uid'],
			'display_name' => $id['display_name'],
			'contact_id'   => $cid,
			'source'       => 'mcp_order_create',
		) ) as $k => $v ) {
			$order->update_meta_data( $k, $v );
		}
		if ( method_exists( $order, 'get_billing_first_name' ) && '' === trim( (string) $order->get_billing_first_name() ) ) {
			$bill = '' !== trim( $name ) ? $name : $id['display_name'];
			if ( '' !== trim( $bill ) ) {
				$order->set_billing_first_name( $bill );
			}
		}
	}

	/** [5, woocommerce_hold_stock_minutes or 60]; default 30 (or the cap when lower). */
	public static function hold_minutes( $asked ) {
		$cap = (int) get_option( 'woocommerce_hold_stock_minutes', 60 );
		$cap = max( 5, $cap > 0 ? $cap : 60 );
		$asked = (int) $asked;
		return max( 5, min( $cap, $asked > 0 ? $asked : 30 ) );
	}

	/** Pending draft: CRM order adapter when a customer is named (pipeline attribution), else wc_create_order. */
	private static function new_order( array $plan ) {
		$c = $plan['contact'];
		$adapter = $c && class_exists( 'BizCity_CRM_Order_Adapter_Registry' ) ? BizCity_CRM_Order_Adapter_Registry::default_adapter() : null;
		try {
			if ( $adapter ) {
				$res = $adapter->create_order( array(
					'conversation_id' => (int) $c['conversation_id'],
					'contact'         => array( 'id' => (int) $c['contact_id'], 'name' => $c['name'], 'email' => $c['email'], 'phone' => $c['phone'], 'wp_user_id' => (int) $c['wp_user_id'] ),
					'items'           => array_map( static function ( $l ) { return array( 'product_id' => $l['product_id'], 'qty' => $l['qty'] ); }, $plan['lines'] ),
					'note'            => $plan['note'],
				) );
				$order = function_exists( 'wc_get_order' ) ? wc_get_order( (int) ( $res['order_id'] ?? 0 ) ) : null;
			} elseif ( function_exists( 'wc_create_order' ) ) {
				$order = wc_create_order( array( 'status' => 'pending', 'created_via' => 'bizcity-mcp', 'customer_id' => $c ? (int) $c['wp_user_id'] : 0 ) );
				if ( is_wp_error( $order ) ) {
					return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::INTERNAL_ERROR, 'Không tạo được đơn nháp.', 500 );
				}
				foreach ( $plan['lines'] as $l ) {
					$order->add_product( $l['product'], $l['qty'] );
				}
				if ( $c ) {
					$order->set_billing_first_name( (string) $c['name'] );
					if ( '' !== $c['phone'] ) {
						$order->set_billing_phone( (string) $c['phone'] );
					}
					$order->update_meta_data( '_bizcity_crm_contact_id', (int) $c['contact_id'] );
				}
				if ( '' !== $plan['note'] ) {
					$order->add_order_note( 'Ghi chú: ' . $plan['note'] );
				}
				$order->add_order_note( 'Đơn nháp giữ hàng tạo qua Agent (MCP).' );
				$order->calculate_totals();
			} else {
				$order = null;
			}
		} catch ( \Exception $e ) {
			return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::INTERNAL_ERROR, 'Không tạo được đơn nháp.', 500 );
		}
		if ( ! $order || ! is_object( $order ) ) {
			return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::INVENTORY_RESERVE_UNAVAILABLE, 'WooCommerce chưa sẵn sàng để tạo đơn.', 503 );
		}
		self::apply_customer( $order, $plan ); // [2026-10-11 Johnny Chu - Chu Hoàng Anh] PHASE-0.95 S95-F19 — trước stamp_identity: tên khách khai thắng display_name
		self::stamp_identity( $order, $plan ); // [2026-10-09 03:14 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.95-S95-F9 — saved by commit()
		return $order;
	}

	/* ── inventory.release ───────────────────────────────────────── */

	public static function release( array $args, array $ctx ) {
		return BizCity_MCP_Action_Support::run_as( $ctx, static function ( $uid ) use ( $args ) {
			if ( ! BizCity_MCP_Action_Support::can_sell( $uid ) ) {
				return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::SCOPE_DENIED, 'Bạn chưa có quyền nhả hàng.', 403 );
			}
			$rs = self::reserve_stock();
			if ( null === $rs || ! function_exists( 'wc_get_order' ) ) {
				return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::INVENTORY_RESERVE_UNAVAILABLE, 'Cửa hàng chưa bật giữ hàng của WooCommerce.', 503 );
			}
			$order_id = (int) ( $args['order_id'] ?? 0 );
			$order    = $order_id > 0 ? wc_get_order( $order_id ) : null;
			if ( ! $order || ! is_object( $order ) ) {
				return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::NOT_FOUND, 'Không tìm thấy đơn #' . $order_id . '.', 404 );
			}
			if ( ! $order->get_meta( self::META_HOLD ) || 'pending' !== (string) $order->get_status() ) {
				return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::SCOPE_DENIED, 'Chỉ nhả được đơn giữ hàng do Agent tạo và còn đang chờ.', 403 );
			}
			$by = (int) $order->get_meta( self::META_HOLD_BY );
			if ( $by !== (int) $uid && BizCity_MCP_Action_Support::crm_rank( $uid ) < 3 && ! BizCity_MCP_Action_Support::is_admin( $uid ) ) {
				return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::SCOPE_DENIED, 'Đơn giữ hàng này do người khác tạo.', 403 );
			}
			$released = array();
			foreach ( (array) $order->get_items() as $item ) {
				if ( is_object( $item ) && method_exists( $item, 'get_product_id' ) ) {
					$released[] = array( 'product_id' => (int) $item->get_product_id(), 'qty' => (int) $item->get_quantity() );
				}
			}
			$rs->release_stock_for_order( $order );
			$order->update_status( 'cancelled', 'Nhả hàng giữ qua Agent (MCP).' );
			return array( 'status' => 'done', 'order_id' => $order_id, 'released' => $released, 'order_status' => (string) $order->get_status() );
		} );
	}

	/* ── inventory.check ─────────────────────────────────────────── */

	public static function check( array $args, array $ctx ) {
		return BizCity_MCP_Action_Support::run_as( $ctx, static function ( $uid ) use ( $args ) {
			if ( ! BizCity_MCP_Action_Support::can_sell( $uid ) ) {
				return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::SCOPE_DENIED, 'Bạn chưa có quyền xem tồn kho.', 403 );
			}
			if ( ! function_exists( 'wc_get_product' ) ) {
				return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::INVENTORY_RESERVE_UNAVAILABLE, 'WooCommerce chưa bật.', 503 );
			}
			$rs        = self::reserve_stock();
			$threshold = max( 0, (int) ( $args['low_stock_threshold'] ?? 5 ) );
			$ids       = array_slice( array_values( array_unique( array_filter( array_map( 'intval', (array) ( $args['product_ids'] ?? array() ) ) ) ) ), 0, self::MAX_LINES );
			if ( $ids ) {
				$products = array_filter( array_map( 'wc_get_product', $ids ) );
			} else {
				$limit    = max( 1, min( 100, (int) ( $args['limit'] ?? 20 ) ) );
				$products = function_exists( 'wc_get_products' ) ? (array) wc_get_products( array( 'limit' => $limit, 'status' => 'publish', 'return' => 'objects' ) ) : array();
			}
			$items = array();
			$low   = array();
			$out   = array();
			foreach ( $products as $p ) {
				if ( ! is_object( $p ) ) {
					continue;
				}
				$row     = self::stock_row( $p, $rs );
				$items[] = $row;
				$short   = array( 'product_id' => $row['product_id'], 'name' => $row['name'], 'available' => $row['available'] );
				if ( 'outofstock' === $row['stock_status'] || ( null !== $row['available'] && $row['available'] <= 0 ) ) {
					$out[] = $short;
				} elseif ( null !== $row['available'] && $row['available'] <= $threshold ) {
					$low[] = $short;
				}
			}
			return array( 'items' => $items, 'low_stock' => $low, 'out_of_stock' => $out, 'reserve_supported' => null !== $rs );
		} );
	}

	/* ── helpers ─────────────────────────────────────────────────── */

	/** @return object|null Woo ReserveStock, or null when this Woo has no stock reservation. */
	private static function reserve_stock() {
		$class = self::$reserve_class;
		return class_exists( $class ) ? new $class() : null;
	}

	/** stock = Woo stock quantity (null when not managed), reserved = held by pending orders, available = stock − reserved. */
	private static function stock_row( $product, $rs ) {
		$managed  = method_exists( $product, 'managing_stock' ) && $product->managing_stock() && ! ( method_exists( $product, 'backorders_allowed' ) && $product->backorders_allowed() );
		$stock    = $managed ? (int) $product->get_stock_quantity() : null;
		$reserved = $managed && $rs ? (int) $rs->get_reserved_stock( $product ) : 0;
		return array(
			'product_id'   => (int) $product->get_id(),
			'name'         => (string) $product->get_name(),
			'stock'        => $stock,
			'reserved'     => $reserved,
			'available'    => $managed ? max( 0, $stock - $reserved ) : null,
			'stock_status' => (string) $product->get_stock_status(),
		);
	}

	/** Whole amounts as integers (225000, not 225000.0) — the shape of fixture confirm.flow.json. */
	private static function num( $v ) {
		$f = (float) $v;
		return floor( $f ) == $f && abs( $f ) < PHP_INT_MAX ? (int) $f : $f;
	}

	private static function money( $v, $currency ) {
		$v = (float) $v;
		$s = number_format( $v, ( floor( $v ) == $v ) ? 0 : 2, ',', '.' );
		return 'VND' === $currency ? $s . 'đ' : $s . ' ' . $currency;
	}
}

BizCity_Commerce_Action_MCP_Service::init();
