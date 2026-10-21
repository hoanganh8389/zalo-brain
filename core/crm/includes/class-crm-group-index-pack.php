<?php
/**
 * Biz Central Brain — Johnny Chu (Chu Hoàng Anh). Bizcity Central Brain, Giấy chứng nhận đăng ký quyền tác giả số 8877/2026/QTG.
 *
 * BizCity_CRM_Group_Index_Pack — projection pack `group_index` (group-index@1, PHASE-0.124 G0 / B12, doc 20 §5).
 *
 * Bản đồ các nhóm Zalo mà thư ký của MỘT số đang lo (theo dõi hoặc có người được giao): tên, NH-, lần cuối có tin, ≤ 5 tệp gần đây
 * (mã TL-), brief mới nhất ≤ 3 dòng. ≤ 4 KB mỗi nhóm, ≤ 30 nhóm ⇒ ≤ 120 KB trần, thực tế vài KB (R-MEMORY-5-95: cell chỉ giữ nhãn).
 * Cell dùng để trả "nhóm nào, đang thế nào" KHÔNG gọi site (G0); số liệu / tin gốc vẫn qua MCP group.* (G1–G3).
 *
 * Một bản mỗi số (giống `skills`, `automation`): nội dung không phụ thuộc người kéo; cell tự chặn theo bậc nhóm (D124-11) trước khi đọc.
 * Làm mới: đổi cài đặt nhóm (apply_command) và mọi lần ghi sổ (`doc_index` đổi ⇒ `group_index` đổi theo), báo cell qua
 * `bizcity_twin_agent_packs_changed` (debounce chung).
 *
 * // [2026-10-20 06:01 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.124 G0 — new file.
 * // @axis twin-agent-axis@1 pack group_index
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\CRM
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_CRM_Group_Index_Pack', false ) ) {
	return;
}

final class BizCity_CRM_Group_Index_Pack {

	const KIND        = 'group_index';
	const CONTRACT    = 'group-index@1';
	const PACK_FILTER = 'bizcity_twin_agent_pack_exporters';
	const MAX_GROUPS  = 30;
	const GROUP_BYTES = 4096;
	const CACHE_TTL   = 60;

	/** Test seam: rows(bridge): array thay cho BizCity_CRM_Group_Secretary::index_rows. */
	public static $readers = array();

	public static function register(): void {
		if ( ! function_exists( 'add_filter' ) ) {
			return;
		}
		add_filter( self::PACK_FILTER, array( __CLASS__, 'exporters' ), 10 );
		add_action( 'bizcity_twin_agent_packs_changed', array( __CLASS__, 'on_packs_changed' ), 10, 1 );
	}

	public static function exporters( $exporters ): array {
		$exporters = is_array( $exporters ) ? $exporters : array();
		$exporters[ self::KIND ] = array(
			'mode'      => 'notebook',
			'audience'  => 'owner_agent',
			'available' => array( __CLASS__, 'available' ),
			'stats'     => array( __CLASS__, 'stats' ),
			'page'      => array( __CLASS__, 'page' ),
		);
		return $exporters;
	}

	public static function available(): bool {
		return class_exists( 'BizCity_CRM_Group_Secretary', false );
	}

	/** @param array $ctx {account_id (= bridge), owner_user_id, role, ...} */
	public static function stats( array $ctx ): array {
		$items = self::build( (string) ( $ctx['account_id'] ?? '' ) );
		$json  = (string) wp_json_encode( $items );
		return array(
			'version'   => 'v-' . substr( sha1( self::KIND . '|' . $json ), 0, 8 ),
			'as_of'     => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'bytes'     => strlen( $json ),
			'items'     => count( $items ),
			'truncated' => false,
		);
	}

	public static function page( array $ctx, int $after, int $limit ): array {
		$all   = self::build( (string) ( $ctx['account_id'] ?? '' ) );
		$items = array_slice( $all, max( 0, $after ), max( 1, $limit ) );
		$last  = max( 0, $after ) + count( $items );
		return array( 'items' => $items, 'last_id' => $last, 'more' => $last < count( $all ) );
	}

	/** meta + một dòng mỗi nhóm. Số không có nhóm nào được lo ⇒ chỉ meta. */
	public static function build( string $bridge ): array {
		if ( '' === $bridge || ! self::available() ) {
			return array();
		}
		$key = self::cache_key( $bridge );
		$hit = ! isset( self::$readers['rows'] ) && function_exists( 'get_transient' ) ? get_transient( $key ) : false;
		if ( is_array( $hit ) ) {
			return $hit;
		}
		$rows  = isset( self::$readers['rows'] ) ? (array) call_user_func( self::$readers['rows'], $bridge ) : BizCity_CRM_Group_Secretary::index_rows( $bridge, self::MAX_GROUPS, self::GROUP_BYTES );
		$items = array_merge( array( array( 'row' => 'meta', 'contract' => self::CONTRACT ) ), array_slice( array_values( $rows ), 0, self::MAX_GROUPS ) );
		if ( ! isset( self::$readers['rows'] ) && function_exists( 'set_transient' ) ) {
			set_transient( $key, $items, self::CACHE_TTL );
		}
		return $items;
	}

	/** Cài đặt nhóm của một số đổi ⇒ bỏ bản đệm + báo cell. */
	public static function changed( string $bridge ): void {
		if ( '' !== $bridge && function_exists( 'delete_transient' ) ) {
			delete_transient( self::cache_key( $bridge ) );
		}
		if ( function_exists( 'do_action' ) ) {
			do_action( 'bizcity_twin_agent_packs_changed', array( self::KIND ) );
		}
	}

	/** Mỗi lần ghi sổ (doc_index đổi) — tệp / brief / sản phẩm nhóm đều là dòng sổ ⇒ group_index cũng có thể đổi (bản đệm 60 s tự hết). */
	public static function on_packs_changed( $kinds = array() ): void {
		$kinds = array_map( 'strval', (array) $kinds );
		if ( in_array( 'doc_index', $kinds, true ) && ! in_array( self::KIND, $kinds, true ) && function_exists( 'do_action' ) ) {
			do_action( 'bizcity_twin_agent_packs_changed', array( self::KIND ) );
		}
	}

	private static function cache_key( string $bridge ): string {
		return 'bizcity_pack_group_index_' . ( function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0 ) . '_' . md5( $bridge );
	}
}
