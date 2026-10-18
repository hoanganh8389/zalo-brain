<?php
/**
 * BizCity_SelfWP_Menu_Service — `wp.menu.list` (R0) and `wp.menu.update` (R2): the cell edits a navigation menu of THIS website (PHASE-0.118 S118-L8, doc 20).
 *
 *  - the model sends OPERATIONS (add / remove / move / rename, ≤ 10), never a whole menu; the site works out the new structure, shows a diff (R2 preview →
 *    confirm, token bound to the arguments) and only then writes;
 *  - limits: ≤ 40 items per menu, 2 levels deep, titles are plain text ≤ 60 chars; a link is a PUBLISHED page of this site, an internal path (`/lien-he`) or an
 *    absolute http(s) address with a real host (no IP literal, no `user@`, no javascript: / data: / mailto: / tel:) — a menu link is only ever a link;
 *  - mode `site_admin` + the WordPress capability `edit_theme_options` (re-checked in every handler);
 *  - the previous structure is saved BEFORE the first write (option `bizcity_selfwp_menu_changes`, ≤ 20, no new table) so `revert: <change id>` rebuilds it;
 *    a revert is itself a logged change and can be reverted; one site-change-log@1 audit line per write (item titles and addresses are NOT in it).
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\MCP
 * @since      2026-10-18 (PHASE-0.118 S118-L8)
 */

defined( 'ABSPATH' ) || exit;

// [2026-10-18 02:00 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.118 S118-L8 — new file: wp.menu.list + wp.menu.update.
final class BizCity_SelfWP_Menu_Service {

	const OPTION     = 'bizcity_selfwp_menu_changes';
	const KEEP       = 20;
	const MAX_OPS    = 10;
	const MAX_ITEMS  = 40;
	const MAX_DEPTH  = 2;
	const LIST_MENUS = 5;

	/**
	 * Test seams, name → callable. Defaults call WordPress.
	 *   user_can(string $cap): bool · list_menus(): array rows {id,name,locations:string[]} · get_items(int $menu_id): array|null (null = no such menu)
	 *   page_ok(int $id): array|null {id,title,status} · apply_items(int $menu_id, array $items): array|WP_Error (returns the items as stored, with their final ids)
	 *   get_changes(): array · set_changes(array $rows): void
	 * An item = {id:int, title:string, page_id:int, url:string, parent:int}. The list order IS the menu order.
	 *
	 * @var array<string,callable>
	 */
	public static $seams = array();

	public static function init() {
		add_action( 'bizcity_mcp_register_tools', array( __CLASS__, 'register_tools' ) );
	}

	public static function register_tools() {
		$S = 'BizCity_MCP_Tool_Registry';

		// @mcp bizcity-mcp-standard@1 tool wp.menu.list
		BizCity_MCP_Tool_Registry::register( 'wp.menu.list', array(
			'executor'       => 'wp.menu.list',
			'title'          => 'Các menu của website',
			'description'    => 'Liệt kê các menu điều hướng (id, tên, vị trí hiển thị) kèm các mục của từng menu (item_id, tiêu đề, mục cha, trang hoặc địa chỉ) và vài lần sửa menu gần đây (change_id). Chỉ đọc. Gọi trước khi sửa menu để biết item_id.',
			'input_schema'   => array( 'type' => 'object', 'properties' => new stdClass() ),
			'output_schema'  => $S::envelope_schema( array( 'menus' => array( 'type' => 'array' ), 'changes' => array( 'type' => 'array' ) ), array( 'menus' ) ),
			'read_only'      => true,
			'idempotent'     => true,
			'required_scope' => 'site.admin',
			'required_cap'   => 'edit_theme_options',
			'handler'        => array( __CLASS__, 'menu_list' ),
			'mode'           => 'site_admin',
			'scopes'         => array( 'site.admin' ),
			'confirm'        => 'never',
			'llm_alias'      => 'wp_menu_list',
			'fallback_pack'  => null,
			'since'          => '0.118.0',
		) );

		// @mcp bizcity-mcp-standard@1 tool wp.menu.update
		BizCity_MCP_Tool_Registry::register( 'wp.menu.update', array(
			'executor'       => 'wp.menu.update',
			'title'          => 'Sửa menu website',
			'description'    => 'Đồng bộ hệ thống (chỉ zalo-hub gọi): sửa MỘT menu bằng danh sách thao tác (tối đa 10): add {title?, page_id | url, parent?, after?}, remove {item_id}, move {item_id, parent?, after?}, rename {item_id, title}; HOẶC hoàn tác một lần sửa (revert = change_id). Mỗi menu tối đa 40 mục, 2 cấp. Liên kết chỉ là trang ĐÃ đăng của site, đường dẫn nội bộ (/lien-he) hoặc địa chỉ http(s). Lần đầu chỉ trả bản xem trước (diff) + confirm_token, CHƯA ghi; gọi lại ĐÚNG tham số kèm confirm_token sau khi người yêu cầu đồng ý.',
			'input_schema'   => array( 'type' => 'object', 'required' => array( 'menu_id' ), 'properties' => array(
				'menu_id'       => array( 'type' => 'integer', 'minimum' => 1 ),
				'ops'           => array( 'type' => 'array', 'minItems' => 1, 'maxItems' => self::MAX_OPS, 'items' => array( 'type' => 'object', 'required' => array( 'op' ), 'properties' => array(
					'op'      => array( 'type' => 'string', 'enum' => array( 'add', 'remove', 'move', 'rename' ) ),
					'item_id' => array( 'type' => 'integer', 'minimum' => 1 ),
					'title'   => array( 'type' => 'string', 'maxLength' => 60 ),
					'page_id' => array( 'type' => 'integer', 'minimum' => 1 ),
					'url'     => array( 'type' => 'string', 'maxLength' => 200 ),
					'parent'  => array( 'type' => 'integer', 'minimum' => 0 ),
					'after'   => array( 'type' => 'integer', 'minimum' => 0 ),
				) ) ),
				'revert'        => array( 'type' => 'string', 'minLength' => 6, 'maxLength' => 16 ),
				'confirm_token' => array( 'type' => 'string' ),
			) ),
			'output_schema'  => $S::envelope_schema( array( 'status' => array( 'type' => 'string' ), 'preview' => array( 'type' => 'object' ), 'confirm_token' => array( 'type' => 'string' ), 'expires_at' => array( 'type' => 'string' ), 'menu_id' => array( 'type' => 'integer' ), 'change_id' => array( 'type' => 'string' ), 'changes' => array( 'type' => 'array' ), 'items' => array( 'type' => 'array' ), 'unchanged' => array( 'type' => 'boolean' ) ), array( 'status' ) ),
			'read_only'      => false,
			'destructive'    => false,
			'idempotent'     => false,
			'required_scope' => 'site.admin',
			'required_cap'   => 'edit_theme_options',
			'handler'        => array( __CLASS__, 'menu_update' ),
			'preview'        => array( __CLASS__, 'menu_update_preview' ),
			'mode'           => 'site_admin',
			'scopes'         => array( 'site.admin' ),
			'confirm'        => 'always',
			'llm_alias'      => 'wp_menu_update',
			'fallback_pack'  => null,
			'since'          => '0.118.0',
		) );
	}

	/* ───────────────────────────── values ──────────────────── */

	private static function clean_title( $v ): string {
		$s = is_string( $v ) ? strip_tags( $v ) : '';
		$s = (string) preg_replace( '/[\x00-\x1F\x7F<>]+/u', ' ', $s );
		$s = trim( (string) preg_replace( '/\s+/u', ' ', $s ) );
		return function_exists( 'mb_substr' ) ? mb_substr( $s, 0, 60, 'UTF-8' ) : substr( $s, 0, 60 );
	}

	/**
	 * A menu link is only ever a link: an internal path or a real http(s) host.
	 *
	 * @return string|null the normalised address, null = refused
	 */
	public static function clean_url( $raw ): ?string {
		if ( ! is_string( $raw ) ) {
			return null;
		}
		$u = trim( $raw );
		if ( '' === $u || strlen( $u ) > 200 || preg_match( '/[\x00-\x20\x7F<>"\'\\\\`]/', $u ) ) {
			return null;
		}
		if ( '/' === $u[0] ) {
			return ( 0 === strpos( $u, '//' ) || false !== strpos( $u, '..' ) ) ? null : $u; // "//host" is a protocol-relative external address
		}
		if ( 1 !== preg_match( '#^https?://#i', $u ) ) {
			return null; // javascript: data: mailto: tel: file: ftp: …
		}
		$p = parse_url( $u );
		if ( ! is_array( $p ) || empty( $p['host'] ) || isset( $p['user'] ) || isset( $p['pass'] ) ) {
			return null;
		}
		$h = strtolower( (string) $p['host'] );
		$labels = explode( '.', $h );
		// a public name has a dot (so no `localhost`, `intranet`, `[::1]`) and its LAST label starts with a letter: that excludes every IPv4 spelling a browser
		// understands (127.0.0.1, 0x7f.0.0.1, 0177.0.0.1, 2130706433, 127.1) - and the usual intranet suffixes
		if ( count( $labels ) < 2 || 1 !== preg_match( '/^[a-z][a-z0-9-]{1,62}$/', (string) end( $labels ) ) || 1 === preg_match( '/\.(local|internal|localhost|lan)$/', $h ) ) {
			return null;
		}
		return $u;
	}

	/** @return array{id:int,title:string,page_id:int,url:string,parent:int} */
	private static function norm_item( array $r ): array {
		return array(
			'id'      => (int) ( $r['id'] ?? 0 ),
			'title'   => self::clean_title( $r['title'] ?? '' ),
			'page_id' => (int) ( $r['page_id'] ?? 0 ),
			'url'     => (string) ( $r['url'] ?? '' ),
			'parent'  => (int) ( $r['parent'] ?? 0 ),
		);
	}

	/** how an item is shown in a diff line */
	private static function show( array $it ): string {
		return '«' . $it['title'] . '»' . ( $it['page_id'] > 0 ? ' (trang #' . $it['page_id'] . ')' : ' (' . $it['url'] . ')' );
	}

	/* ───────────────────────────── plan (nothing is written) ──────────────────── */

	/** @return array|WP_Error {menu_id, items(before), new(after), lines, mode, revert_of} */
	private static function plan( array $args ) {
		$menu_id = is_int( $args['menu_id'] ?? null ) ? (int) $args['menu_id'] : 0;
		if ( $menu_id <= 0 ) {
			return self::bad( 'selfwp_menu_args', 'Thiếu mã menu (menu_id, lấy từ wp.menu.list).' );
		}
		$cur = self::seam( 'get_items', array( $menu_id ), array( __CLASS__, 'default_get_items' ) );
		if ( ! is_array( $cur ) ) {
			return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::NOT_FOUND, "Không có menu #{$menu_id}.", 404, array( 'help_code' => 'selfwp_menu_not_found' ) );
		}
		$before  = array_values( array_map( array( __CLASS__, 'norm_item' ), $cur ) );
		$has_ops = isset( $args['ops'] );
		$has_rev = isset( $args['revert'] ) && '' !== trim( (string) $args['revert'] );
		if ( $has_ops === $has_rev ) {
			return self::bad( 'selfwp_menu_args', 'Cần đúng MỘT trong hai: ops (các thao tác) hoặc revert (mã lần sửa cần hoàn tác).' );
		}
		$of = '';
		if ( $has_rev ) {
			$row = null;
			foreach ( self::changes() as $c ) {
				if ( 0 === strcasecmp( (string) $c['id'], trim( (string) $args['revert'] ) ) && (int) $c['menu_id'] === $menu_id ) {
					$row = $c;
					break;
				}
			}
			if ( null === $row ) {
				return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::NOT_FOUND, 'Không có lần sửa «' . substr( (string) preg_replace( '/[^A-Za-z0-9_]/', '?', (string) $args['revert'] ), 0, 16 ) . "» của menu #{$menu_id} (sai mã hoặc quá giới hạn lưu). Xem bằng wp.menu.list.", 404, array( 'help_code' => 'selfwp_change_not_found' ) );
			}
			$new = array();
			foreach ( (array) $row['before'] as $r ) { // saved data goes through the same gate as a new value
				$it = self::norm_item( (array) $r );
				if ( '' === $it['title'] || ( $it['page_id'] <= 0 && null === self::clean_url( $it['url'] ) ) ) {
					return self::bad( 'selfwp_menu_value', 'Bản lưu có một mục không còn hợp lệ nên không hoàn tác tự động.' );
				}
				$new[] = $it;
			}
			$of = (string) $row['id'];
		} else {
			$ops = $args['ops'];
			if ( ! is_array( $ops ) || array() === $ops || count( $ops ) > self::MAX_OPS ) {
				return self::bad( 'selfwp_menu_args', 'ops cần từ 1 đến ' . self::MAX_OPS . ' thao tác.' );
			}
			$new = $before;
			foreach ( array_values( $ops ) as $i => $op ) {
				$r = self::apply_op( $new, is_array( $op ) ? $op : array(), $i );
				if ( is_wp_error( $r ) ) {
					return $r;
				}
			}
		}
		if ( count( $new ) > self::MAX_ITEMS ) {
			return self::bad( 'selfwp_menu_too_big', 'Menu tối đa ' . self::MAX_ITEMS . ' mục.' );
		}
		foreach ( $new as $it ) {
			if ( $it['parent'] > 0 ) {
				$par = null;
				foreach ( $new as $p ) {
					if ( $p['id'] === $it['parent'] ) {
						$par = $p;
						break;
					}
				}
				if ( null === $par || $par['parent'] > 0 || $it['id'] === $it['parent'] ) {
					return self::bad( 'selfwp_menu_depth', 'Menu chỉ có ' . self::MAX_DEPTH . ' cấp: mục cha phải là mục cấp 1 của cùng menu.' );
				}
			}
		}
		return array( 'menu_id' => $menu_id, 'before' => $before, 'new' => $new, 'lines' => self::diff( $before, $new ), 'mode' => $has_rev ? 'revert' : 'update', 'revert_of' => $of );
	}

	/**
	 * One operation on the working list. New items get a negative temporary id (-1, -2 …) so a later op of the same call can point at them.
	 *
	 * @param array<int,array> $list
	 * @return true|WP_Error
	 */
	private static function apply_op( array &$list, array $op, int $n ) {
		$kind = (string) ( $op['op'] ?? '' );
		$at   = static function ( array $l, int $id ) {
			foreach ( $l as $i => $it ) {
				if ( $it['id'] === $id ) {
					return $i;
				}
			}
			return null;
		};
		$p = 'ops[' . $n . ']';
		if ( 'add' === $kind ) {
			$page = is_int( $op['page_id'] ?? null ) ? (int) $op['page_id'] : 0;
			$url  = isset( $op['url'] ) ? self::clean_url( $op['url'] ) : '';
			if ( ( $page > 0 ) === ( isset( $op['url'] ) ) ) {
				return self::bad( 'selfwp_menu_op', "{$p}: cần đúng MỘT trong page_id hoặc url." );
			}
			$title = self::clean_title( $op['title'] ?? '' );
			if ( $page > 0 ) {
				$pg = self::seam( 'page_ok', array( $page ), array( __CLASS__, 'default_page_ok' ) );
				if ( ! is_array( $pg ) || 'publish' !== (string) ( $pg['status'] ?? '' ) ) {
					return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::NOT_FOUND, "{$p}: trang #{$page} không có hoặc chưa đăng (menu chỉ trỏ tới trang đã công khai).", 404, array( 'help_code' => 'selfwp_menu_page' ) );
				}
				$title = '' !== $title ? $title : self::clean_title( $pg['title'] ?? '' );
				$url   = '';
			} elseif ( null === $url ) {
				return self::bad( 'selfwp_menu_url', "{$p}: địa chỉ không hợp lệ — chỉ nhận đường dẫn nội bộ (/lien-he) hoặc http(s)://tên-miền (không IP, không javascript:, mailto:, tel:)." );
			}
			if ( '' === $title ) {
				return self::bad( 'selfwp_menu_op', "{$p}: thiếu tiêu đề mục." );
			}
			$parent = is_int( $op['parent'] ?? null ) ? (int) $op['parent'] : 0;
			if ( $parent > 0 && null === $at( $list, $parent ) ) {
				return self::bad( 'selfwp_menu_op', "{$p}: mục cha #{$parent} không có trong menu." );
			}
			$temp = -1;
			foreach ( $list as $it ) {
				$temp = min( $temp, $it['id'] - 1 );
			}
			$item = array( 'id' => $temp, 'title' => $title, 'page_id' => $page, 'url' => (string) $url, 'parent' => $parent );
			$pos  = count( $list );
			if ( is_int( $op['after'] ?? null ) && $op['after'] > 0 ) {
				$ai = $at( $list, (int) $op['after'] );
				if ( null === $ai ) {
					return self::bad( 'selfwp_menu_op', "{$p}: mục #{$op['after']} (after) không có trong menu." );
				}
				$pos = $ai + 1;
			} elseif ( isset( $op['after'] ) && 0 === $op['after'] ) {
				$pos = 0;
			}
			array_splice( $list, $pos, 0, array( $item ) );
			return true;
		}
		$id = is_int( $op['item_id'] ?? null ) ? (int) $op['item_id'] : 0;
		$ix = $id > 0 ? $at( $list, $id ) : null;
		if ( null === $ix ) {
			return self::bad( 'selfwp_menu_op', "{$p}: mục #{$id} không có trong menu (xem item_id bằng wp.menu.list)." );
		}
		if ( 'rename' === $kind ) {
			$t = self::clean_title( $op['title'] ?? '' );
			if ( '' === $t ) {
				return self::bad( 'selfwp_menu_op', "{$p}: thiếu tiêu đề mới." );
			}
			$list[ $ix ]['title'] = $t;
			return true;
		}
		if ( 'remove' === $kind ) {
			$list = array_values( array_filter( $list, static function ( $it ) use ( $id ) {
				return $it['id'] !== $id && $it['parent'] !== $id; // a parent takes its children with it (shown in the diff)
			} ) );
			return true;
		}
		if ( 'move' === $kind ) {
			$item = $list[ $ix ];
			if ( array_key_exists( 'parent', $op ) ) {
				$par = is_int( $op['parent'] ) ? (int) $op['parent'] : -1;
				if ( $par < 0 || $par === $id || ( $par > 0 && null === $at( $list, $par ) ) ) {
					return self::bad( 'selfwp_menu_op', "{$p}: mục cha không hợp lệ." );
				}
				$item['parent'] = $par;
			}
			array_splice( $list, $ix, 1 );
			$pos = count( $list );
			if ( is_int( $op['after'] ?? null ) && $op['after'] > 0 ) {
				$ai = $at( $list, (int) $op['after'] );
				if ( null === $ai ) { // the item itself was taken out of the list above, so `after` = itself lands here too
					return self::bad( 'selfwp_menu_op', "{$p}: mục #{$op['after']} (after) không có trong menu." );
				}
				$pos = $ai + 1;
			} elseif ( isset( $op['after'] ) && 0 === $op['after'] ) {
				$pos = 0;
			} elseif ( ! array_key_exists( 'after', $op ) ) {
				$pos = $ix; // only the parent changed: stay where it was
			}
			array_splice( $list, min( $pos, count( $list ) ), 0, array( $item ) );
			return true;
		}
		return self::bad( 'selfwp_menu_op', "{$p}: thao tác chưa hỗ trợ (add, remove, move, rename)." );
	}

	/** @return string[] ≤ 12 lines, in words */
	private static function diff( array $a, array $b ): array {
		$ia = array();
		foreach ( $a as $i => $it ) {
			$ia[ $it['id'] ] = array( $i, $it );
		}
		$ib = array();
		foreach ( $b as $i => $it ) {
			$ib[ $it['id'] ] = array( $i, $it );
		}
		$lines = array();
		foreach ( $b as $it ) {
			if ( ! isset( $ia[ $it['id'] ] ) ) {
				$lines[] = 'Thêm ' . self::show( $it ) . ( $it['parent'] > 0 ? ' dưới mục #' . $it['parent'] : '' );
			}
		}
		foreach ( $a as $it ) {
			if ( ! isset( $ib[ $it['id'] ] ) ) {
				$lines[] = 'Bỏ ' . self::show( $it );
			}
		}
		foreach ( $b as $i => $it ) {
			if ( ! isset( $ia[ $it['id'] ] ) ) {
				continue;
			}
			[ $oi, $old ] = $ia[ $it['id'] ];
			if ( $old['title'] !== $it['title'] ) {
				$lines[] = 'Đổi tên «' . $old['title'] . '» → «' . $it['title'] . '»';
			}
			if ( $old['parent'] !== $it['parent'] ) {
				$lines[] = 'Chuyển «' . $it['title'] . '» ' . ( $it['parent'] > 0 ? 'xuống làm mục con của #' . $it['parent'] : 'lên cấp 1' );
			}
		}
		$order_a = array_values( array_intersect( array_column( $a, 'id' ), array_column( $b, 'id' ) ) );
		$order_b = array_values( array_intersect( array_column( $b, 'id' ), array_column( $a, 'id' ) ) );
		if ( $order_a !== $order_b ) {
			$lines[] = 'Đổi thứ tự các mục';
		}
		if ( count( $lines ) > 12 ) {
			$extra   = count( $lines ) - 11;
			$lines   = array_slice( $lines, 0, 11 );
			$lines[] = '… và ' . $extra . ' thay đổi nữa';
		}
		return $lines;
	}

	/* ───────────────────────────── handlers ──────────────────── */

	/** @return array|WP_Error */
	public static function menu_list( array $args, array $ctx ) {
		return BizCity_MCP_Action_Support::run_as( $ctx, static function ( $uid ) {
			if ( ! self::can( 'edit_theme_options' ) ) {
				return self::denied();
			}
			$menus = array();
			foreach ( array_slice( (array) self::seam( 'list_menus', array(), array( __CLASS__, 'default_list_menus' ) ), 0, self::LIST_MENUS ) as $m ) {
				$items = self::seam( 'get_items', array( (int) $m['id'] ), array( __CLASS__, 'default_get_items' ) );
				$items = is_array( $items ) ? array_values( array_map( array( __CLASS__, 'norm_item' ), $items ) ) : array();
				$menus[] = array(
					'id'        => (int) $m['id'],
					'name'      => self::clean_title( $m['name'] ?? '' ),
					'locations' => array_slice( array_map( 'strval', (array) ( $m['locations'] ?? array() ) ), 0, 6 ),
					'count'     => count( $items ),
					'items'     => array_slice( array_map( static function ( $it ) {
						return array( 'item_id' => $it['id'], 'title' => $it['title'], 'parent' => $it['parent'], 'page_id' => $it['page_id'], 'url' => $it['url'] );
					}, $items ), 0, self::MAX_ITEMS ),
				);
			}
			$changes = array();
			foreach ( array_slice( array_reverse( self::changes() ), 0, 5 ) as $c ) {
				$changes[] = array( 'change_id' => $c['id'], 'menu_id' => (int) $c['menu_id'], 'at' => $c['at'], 'via' => $c['via'] );
			}
			return array( 'menus' => $menus, 'changes' => $changes );
		} );
	}

	/** @return array|WP_Error */
	public static function menu_update_preview( array $args, array $ctx ) {
		return BizCity_MCP_Action_Support::run_as( $ctx, static function ( $uid ) use ( $args ) {
			if ( ! self::can( 'edit_theme_options' ) ) {
				return self::denied();
			}
			$plan = self::plan( $args );
			if ( is_wp_error( $plan ) ) {
				return $plan;
			}
			if ( array() === $plan['lines'] ) {
				return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::QUERY_INVALID, 'Các thao tác này không làm menu đổi gì.', 422, array( 'help_code' => 'selfwp_no_change' ) );
			}
			return array(
				'menu_id' => $plan['menu_id'],
				'changes' => $plan['lines'],
				'summary' => ( 'revert' === $plan['mode'] ? 'Hoàn tác lần sửa menu «' . $plan['revert_of'] . '»' : 'Sửa menu #' . $plan['menu_id'] ) . ': ' . count( $plan['lines'] ) . ' thay đổi — khách vào xem thấy ngay. Cấu trúc cũ được lưu để hoàn tác.',
			);
		} );
	}

	/** @return array|WP_Error */
	public static function menu_update( array $args, array $ctx ) {
		return BizCity_MCP_Action_Support::run_as( $ctx, static function ( $uid ) use ( $args, $ctx ) {
			if ( ! self::can( 'edit_theme_options' ) ) {
				return self::denied();
			}
			$plan = self::plan( $args );
			if ( is_wp_error( $plan ) ) {
				return $plan;
			}
			if ( array() === $plan['lines'] ) { // a retry / second tap: already there
				return array( 'status' => 'done', 'menu_id' => $plan['menu_id'], 'unchanged' => true, 'changes' => array(), 'change_id' => '', 'items' => array() );
			}
			$id  = substr( hash( 'sha256', wp_json_encode( array( $plan['menu_id'], $plan['before'], $plan['new'], gmdate( 'c' ), $uid ) ) ), 0, 12 );
			$row = array( 'id' => $id, 'at' => gmdate( 'c' ), 'by' => $uid, 'menu_id' => $plan['menu_id'], 'via' => $plan['mode'], 'revert_of' => $plan['revert_of'], 'before' => $plan['before'] );
			// keep the old structure FIRST: if the write below stops half way, the way back already exists
			self::seam( 'set_changes', array( array_slice( array_merge( self::changes(), array( $row ) ), -self::KEEP ) ), static function ( $rows ) {
				return update_option( self::OPTION, $rows, false );
			} );
			$stored = self::seam( 'apply_items', array( $plan['menu_id'], $plan['new'] ), array( __CLASS__, 'default_apply_items' ) );
			if ( is_wp_error( $stored ) ) {
				return BizCity_MCP_Action_Support::from_business( $stored );
			}
			// the audit names HOW MANY changes, never item titles or addresses (those stay in the saved structure)
			$entry                 = BizCity_SelfWP_Action_MCP_Service::change_entry( 'wp.menu.update', 'R2', $uid, $ctx, $plan['menu_id'], '', $id, array_fill( 0, count( $plan['lines'] ), '·' ), 'menu' );
			$entry['command_line'] = 'wp menu item update <menu ' . $plan['menu_id'] . '>  # titles and addresses are not logged';
			BizCity_SelfWP_Action_MCP_Service::audit_change( $entry );
			return array(
				'status'    => 'done',
				'menu_id'   => $plan['menu_id'],
				'unchanged' => false,
				'change_id' => $id,
				'changes'   => $plan['lines'],
				'items'     => array_slice( array_map( static function ( $it ) {
					$it = self::norm_item( (array) $it );
					return array( 'item_id' => $it['id'], 'title' => $it['title'], 'parent' => $it['parent'] );
				}, (array) $stored ), 0, self::MAX_ITEMS ),
			);
		} );
	}

	/* ───────────────────────────── WordPress defaults ──────────────────── */

	/** @return array<int,array> */
	public static function default_list_menus(): array {
		if ( ! function_exists( 'wp_get_nav_menus' ) ) {
			return array();
		}
		$loc = function_exists( 'get_nav_menu_locations' ) ? (array) get_nav_menu_locations() : array();
		$out = array();
		foreach ( (array) wp_get_nav_menus() as $m ) {
			$out[] = array( 'id' => (int) $m->term_id, 'name' => (string) $m->name, 'locations' => array_keys( array_filter( $loc, static function ( $v ) use ( $m ) {
				return (int) $v === (int) $m->term_id;
			} ) ) );
		}
		return $out;
	}

	/** @return array<int,array>|null */
	public static function default_get_items( $menu_id ): ?array {
		if ( ! function_exists( 'wp_get_nav_menu_object' ) || ! wp_get_nav_menu_object( (int) $menu_id ) ) {
			return null;
		}
		$out = array();
		foreach ( (array) wp_get_nav_menu_items( (int) $menu_id ) as $it ) {
			$page = ( 'post_type' === $it->type && 'page' === $it->object ) ? (int) $it->object_id : 0;
			$out[] = array( 'id' => (int) $it->ID, 'title' => (string) $it->title, 'page_id' => $page, 'url' => $page > 0 ? '' : (string) $it->url, 'parent' => (int) $it->menu_item_parent );
		}
		return $out;
	}

	/** @return array|null */
	public static function default_page_ok( $id ): ?array {
		$p = function_exists( 'get_post' ) ? get_post( (int) $id ) : null;
		return ( $p && 'page' === $p->post_type ) ? array( 'id' => (int) $p->ID, 'title' => (string) $p->post_title, 'status' => (string) $p->post_status ) : null;
	}

	/**
	 * Rebuild the menu to match $items (list order = menu order): known ids are updated in place, unknown / negative ids are created, ids no longer listed are deleted.
	 * A parent that points at an item created in this call is remapped to its new id.
	 *
	 * @return array|WP_Error the items as stored
	 */
	public static function default_apply_items( $menu_id, $items ) {
		if ( ! function_exists( 'wp_update_nav_menu_item' ) ) {
			return new WP_Error( 'wp_missing', 'WordPress chưa sẵn sàng.' );
		}
		$existing = array();
		foreach ( (array) wp_get_nav_menu_items( (int) $menu_id ) as $it ) {
			$existing[ (int) $it->ID ] = true;
		}
		$map = array();
		$out = array();
		foreach ( array_values( (array) $items ) as $pos => $it ) {
			$args = array(
				'menu-item-title'     => $it['title'],
				'menu-item-position'  => $pos + 1,
				'menu-item-status'    => 'publish',
				'menu-item-parent-id' => 0,
			);
			if ( $it['parent'] > 0 ) {
				$args['menu-item-parent-id'] = (int) ( $map[ $it['parent'] ] ?? $it['parent'] );
			}
			if ( $it['page_id'] > 0 ) {
				$args += array( 'menu-item-type' => 'post_type', 'menu-item-object' => 'page', 'menu-item-object-id' => (int) $it['page_id'] );
			} else {
				$args += array( 'menu-item-type' => 'custom', 'menu-item-url' => $it['url'] );
			}
			$known = $it['id'] > 0 && isset( $existing[ $it['id'] ] );
			$new   = wp_update_nav_menu_item( (int) $menu_id, $known ? (int) $it['id'] : 0, $args );
			if ( is_wp_error( $new ) ) {
				return $new;
			}
			$map[ $it['id'] ] = (int) $new;
			$out[]            = array( 'id' => (int) $new, 'title' => $it['title'], 'page_id' => $it['page_id'], 'url' => $it['url'], 'parent' => (int) $args['menu-item-parent-id'] );
			unset( $existing[ (int) $new ] );
		}
		foreach ( array_keys( $existing ) as $gone ) {
			if ( 'nav_menu_item' === get_post_type( (int) $gone ) ) { // a menu entry is a post of type nav_menu_item: never delete anything else, whatever id arrives here
				wp_delete_post( (int) $gone, true );
			}
		}
		return $out;
	}

	/* ───────────────────────────── helpers ──────────────────── */

	/** @return array<int,array> oldest first */
	private static function changes(): array {
		$rows = self::seam( 'get_changes', array(), static function () {
			return function_exists( 'get_option' ) ? get_option( self::OPTION, array() ) : array();
		} );
		$out = array();
		foreach ( (array) $rows as $r ) {
			if ( is_array( $r ) && is_string( $r['id'] ?? null ) && is_array( $r['before'] ?? null ) ) {
				$out[] = $r + array( 'at' => '', 'by' => 0, 'menu_id' => 0, 'via' => 'update', 'revert_of' => '' );
			}
		}
		return $out;
	}

	private static function can( string $cap ): bool {
		if ( isset( self::$seams['user_can'] ) ) {
			return (bool) call_user_func( self::$seams['user_can'], $cap );
		}
		return function_exists( 'current_user_can' ) && current_user_can( $cap );
	}

	private static function seam( string $name, array $args, $default ) {
		$fn = self::$seams[ $name ] ?? $default;
		return call_user_func_array( $fn, $args );
	}

	private static function bad( string $help, string $message ) {
		return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::QUERY_INVALID, $message, 422, array( 'help_code' => $help ) );
	}

	private static function denied() {
		return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::PERMISSION_DENIED, 'Tài khoản của bạn chưa có quyền sửa menu (cần quyền WordPress "edit_theme_options").', 403, array( 'hint' => 'Chỉ quản trị viên sửa được menu website.', 'required_cap' => 'edit_theme_options' ) );
	}
}

BizCity_SelfWP_Menu_Service::init();
