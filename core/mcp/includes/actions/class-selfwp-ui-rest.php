<?php
/**
 * Biz Central Brain — Johnny Chu (Chu Hoàng Anh). Bizcity Central Brain, Giấy chứng nhận đăng ký quyền tác giả số 8877/2026/QTG.
 */
// [2026-10-18 Johnny Chu - Chu Hoàng Anh] PHASE-0.118 S118-U1/U2 (mockup ②③④ đã duyệt) — REST of the "Website của tôi" page: Hoạt động · Hoàn tác · Kiểm tra 4 bước.
/**
 * GET  bizcity-channel/v1/self-wp/activity          ⇒ rows of what the assistant changed (pages' own history + theme + menu), newest first
 * POST bizcity-channel/v1/self-wp/undo              {page_id, snapshot_id, confirm?}  confirm=false ⇒ preview of the restore · true ⇒ do it
 * POST bizcity-channel/v1/self-wp/check                                                  ⇒ 4 checks, the last one creates then deletes a labelled draft page
 *
 * Nothing here has its own rights: Undo calls the SAME handlers the assistant uses (wp.page.restore[_preview]) as the person who clicks, so
 * edit_pages / edit_post / publish rules, the snapshot, the history entry and the audit line are all the existing ones. A person without
 * manage_options sees only the rows they made. Rows carry metadata + the ≤ 8 diff lines the person already saw, never page text.
 * Loaded by core/mcp/bootstrap.php (after the services it calls); registers its own routes.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\MCP
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_SelfWP_UI_REST', false ) ) {
	return;
}

final class BizCity_SelfWP_UI_REST {

	const NS        = 'bizcity-channel/v1';
	const PAGES_MAX = 30;
	const ROWS_MAX  = 100;
	const TEST_TITLE = '[BizCity kiểm tra] xoá ngay';

	/** Test seams, name → callable. Defaults call WordPress. */
	public static $seams = array();

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes() {
		$perm = static function () {
			return current_user_can( 'edit_pages' );
		};
		register_rest_route( self::NS, '/self-wp/activity', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'activity' ), 'permission_callback' => $perm ) );
		register_rest_route( self::NS, '/self-wp/undo', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'undo' ), 'permission_callback' => $perm ) );
		register_rest_route( self::NS, '/self-wp/check', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'check' ), 'permission_callback' => $perm ) );
	}

	/* ───────────────────────────── pure part (unit-tested) ───────────────────────────── */

	/**
	 * Fold the three sources into display rows.
	 *
	 * @param array<int,array{id:int,title:string,generated:array}> $pages  pages carrying the assistant's meta
	 * @param array<int,array> $theme  theme change rows (oldest first)
	 * @param array<int,array> $menu   menu change rows (oldest first)
	 * @param int      $viewer   current user id
	 * @param bool     $all      may see everybody's rows (manage_options)
	 * @param callable $name     user id ⇒ display name
	 * @return array<int,array>  newest first, ≤ ROWS_MAX
	 */
	public static function rows( array $pages, array $theme, array $menu, int $viewer, bool $all, callable $name ): array {
		$out = array();
		foreach ( $pages as $p ) {
			$gen   = (array) ( $p['generated'] ?? array() );
			$snaps = array();
			foreach ( (array) ( $gen['snapshots'] ?? array() ) as $s ) {
				if ( is_array( $s ) && is_string( $s['id'] ?? null ) ) {
					$snaps[] = $s['id'];
				}
			}
			foreach ( (array) ( $gen['history'] ?? array() ) as $e ) {
				if ( ! is_array( $e ) ) {
					continue;
				}
				$before = (string) ( $e['before_ref'] ?? '' );
				$out[]  = self::row( $e, 'page', (int) ( $p['id'] ?? 0 ), (string) ( $p['title'] ?? '' ), '' !== $before && in_array( $before, $snaps, true ), $before );
			}
		}
		foreach ( array( 'theme' => $theme, 'menu' => $menu ) as $kind => $list ) {
			foreach ( $list as $r ) {
				if ( ! is_array( $r ) ) {
					continue;
				}
				$keys   = array_keys( (array) ( $r['after'] ?? array() ) );
				$out[]  = self::row( array(
					'at'       => $r['at'] ?? '',
					'by'       => $r['by'] ?? 0,
					'executor' => 'theme' === $kind ? 'wp.theme.update' : 'wp.menu.update',
					'risk'     => 'R2',
					'changes'  => 'theme' === $kind ? array_map( 'strval', $keys ) : array(),
					'result'   => 'ok',
				), $kind, 0, '', false, '' );
			}
		}
		if ( ! $all ) {
			$out = array_values( array_filter( $out, static function ( $r ) use ( $viewer ) {
				return $viewer > 0 && $r['by'] === $viewer;
			} ) );
		}
		usort( $out, static function ( $a, $b ) {
			return strcmp( $b['at'], $a['at'] );
		} );
		$out = array_slice( $out, 0, self::ROWS_MAX );
		foreach ( $out as &$r ) {
			$r['by_name'] = $r['by'] > 0 ? (string) call_user_func( $name, $r['by'] ) : '';
		}
		unset( $r );
		return $out;
	}

	private static function row( array $e, string $kind, int $page_id, string $title, bool $can_restore, string $before ): array {
		return array(
			'at'          => (string) ( $e['at'] ?? '' ),
			'by'          => (int) ( $e['by'] ?? 0 ),
			'executor'    => (string) ( $e['executor'] ?? '' ),
			'risk'        => in_array( ( $e['risk'] ?? '' ), array( 'R0', 'R1', 'R2', 'R3' ), true ) ? (string) $e['risk'] : '',
			'approval'    => (string) ( $e['approval'] ?? '' ),
			'kind'        => $kind,
			'page_id'     => $page_id,
			'title'       => mb_substr( $title, 0, 80 ),
			'command'     => (string) ( $e['command_line'] ?? '' ),
			'changes'     => array_slice( array_map( static function ( $c ) {
				return mb_substr( (string) $c, 0, 80 );
			}, (array) ( $e['changes'] ?? array() ) ), 0, 8 ),
			'result'      => (string) ( $e['result'] ?? '' ),
			'can_restore' => $can_restore,
			'snapshot_id' => $can_restore ? $before : '',
		);
	}

	/**
	 * The 4 checks as data. `$probe` supplies the facts (so this part is testable): https, tool_known, site_info (array|string error), draft (array{ok,detail}).
	 *
	 * @return array{ok:bool,steps:array<int,array{id:string,label:string,ok:bool,detail:string}>}
	 */
	public static function steps( array $probe ): array {
		$si    = $probe['site_info'] ?? '';
		$si_ok = is_array( $si ) && isset( $si['page_transports'] );
		$d     = (array) ( $probe['draft'] ?? array() );
		$steps = array(
			array( 'id' => 'https', 'label' => 'Website dùng HTTPS', 'ok' => ! empty( $probe['https'] ), 'detail' => ! empty( $probe['https'] ) ? '' : 'Trợ lý chỉ nên làm việc với website có HTTPS.' ),
			array( 'id' => 'tool', 'label' => 'Website đã đăng ký công cụ dựng trang', 'ok' => ! empty( $probe['tool_known'] ), 'detail' => ! empty( $probe['tool_known'] ) ? '' : 'Chưa thấy wp.landing.draft: bản cài chưa đủ file.' ),
			array( 'id' => 'site_info', 'label' => 'Đọc được thông tin website (wp.site.info)', 'ok' => $si_ok, 'detail' => $si_ok ? '' : ( is_string( $si ) && '' !== $si ? $si : 'Không đọc được.' ) ),
			array( 'id' => 'draft', 'label' => 'Tạo rồi xoá một trang nháp thử bằng quyền của bạn', 'ok' => ! empty( $d['ok'] ), 'detail' => (string) ( $d['detail'] ?? '' ) ),
		);
		return array( 'ok' => ! in_array( false, array_column( $steps, 'ok' ), true ), 'steps' => $steps );
	}

	/* ───────────────────────────── REST callbacks ───────────────────────────── */

	public static function activity( $request ) {
		$viewer = (int) get_current_user_id();
		$all    = current_user_can( 'manage_options' );
		$pages  = array();
		foreach ( (array) self::seam( 'page_ids', array(), array( __CLASS__, 'default_page_ids' ) ) as $id ) {
			$gen = self::seam( 'page_meta', array( (int) $id ), static function ( $id ) {
				return get_post_meta( $id, BizCity_SelfWP_Action_MCP_Service::META_GENERATED, true );
			} );
			if ( is_array( $gen ) ) {
				$pages[] = array( 'id' => (int) $id, 'title' => (string) self::seam( 'page_title', array( (int) $id ), static function ( $id ) {
					return get_the_title( $id );
				} ), 'generated' => $gen );
			}
		}
		$opt = static function ( $key ) {
			$v = self::seam( 'option', array( $key ), static function ( $key ) {
				return get_option( $key, array() );
			} );
			return is_array( $v ) ? $v : array();
		};
		$rows = self::rows( $pages, $opt( BizCity_SelfWP_Theme_Service::OPTION ), $opt( BizCity_SelfWP_Menu_Service::OPTION ), $viewer, $all, static function ( $uid ) {
			$u = get_userdata( $uid );
			return $u ? (string) $u->display_name : '';
		} );
		return new WP_REST_Response( array( 'ok' => true, 'scope' => $all ? 'all' : 'mine', 'rows' => $rows ), 200 );
	}

	/** @return int[] */
	public static function default_page_ids(): array {
		return get_posts( array( 'post_type' => 'page', 'post_status' => 'any', 'meta_key' => BizCity_SelfWP_Action_MCP_Service::META_GENERATED, 'posts_per_page' => self::PAGES_MAX, 'orderby' => 'modified', 'order' => 'DESC', 'fields' => 'ids', 'no_found_rows' => true ) );
	}

	public static function undo( $request ) {
		$page_id = (int) $request->get_param( 'page_id' );
		$sid     = trim( (string) $request->get_param( 'snapshot_id' ) );
		$confirm = true === $request->get_param( 'confirm' );
		if ( $page_id <= 0 || '' === $sid || strlen( $sid ) > 64 ) {
			return self::bad( 'invalid_param', 'Cần page_id và snapshot_id.' );
		}
		$args = array( 'page_id' => $page_id, 'snapshot_id' => $sid );
		$ctx  = array( 'user_id' => (int) get_current_user_id(), 'source' => 'selfwp_ui' ); // _confirm stays empty ⇒ the history entry says "manual"
		$r    = $confirm ? BizCity_SelfWP_Action_MCP_Service::page_restore( $args, $ctx ) : BizCity_SelfWP_Action_MCP_Service::page_restore_preview( $args, $ctx );
		if ( is_wp_error( $r ) ) {
			$st = (array) $r->get_error_data();
			return self::bad( (string) $r->get_error_code(), $r->get_error_message(), (int) ( $st['status'] ?? 400 ) );
		}
		return new WP_REST_Response( array( 'ok' => true, 'confirmed' => $confirm, 'result' => $r ), 200 );
	}

	public static function check( $request ) {
		$ctx = array( 'user_id' => (int) get_current_user_id(), 'source' => 'selfwp_ui' );
		$si  = BizCity_SelfWP_Action_MCP_Service::site_info( array(), $ctx );
		$probe = array(
			'https'      => is_ssl() || 0 === strpos( (string) home_url(), 'https://' ),
			'tool_known' => in_array( 'wp.landing.draft', (array) self::seam( 'tool_names', array(), static function () {
				return class_exists( 'BizCity_MCP_Tool_Registry' ) ? BizCity_MCP_Tool_Registry::all_registered_tool_names() : array();
			} ), true ),
			'site_info'  => is_wp_error( $si ) ? $si->get_error_message() : $si,
			'draft'      => self::draft_probe(),
		);
		return new WP_REST_Response( array( 'ok' => true ) + self::steps( $probe ), 200 );
	}

	/** create a labelled draft with the clicker's own rights, then delete THAT id at once. @return array{ok:bool,detail:string} */
	private static function draft_probe(): array {
		return (array) self::seam( 'draft_probe', array(), static function () {
			if ( ! current_user_can( 'edit_pages' ) ) {
				return array( 'ok' => false, 'detail' => 'Tài khoản của bạn chưa có quyền sửa trang (edit_pages).' );
			}
			$id = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => self::TEST_TITLE, 'post_content' => '' ), true );
			if ( is_wp_error( $id ) || (int) $id <= 0 ) {
				return array( 'ok' => false, 'detail' => is_wp_error( $id ) ? $id->get_error_message() : 'Không tạo được trang nháp.' );
			}
			$gone = wp_delete_post( (int) $id, true );
			return $gone ? array( 'ok' => true, 'detail' => '' ) : array( 'ok' => false, 'detail' => 'Tạo được trang #' . (int) $id . ' nhưng chưa xoá được: hãy xoá tay trong Trang → Nháp.' );
		} );
	}

	private static function bad( $code, $message, $status = 400 ) {
		return new WP_REST_Response( array( 'ok' => false, 'code' => $code, 'message' => $message ), $status );
	}

	private static function seam( string $name, array $args, $default ) {
		return call_user_func_array( self::$seams[ $name ] ?? $default, $args );
	}
}

if ( function_exists( 'add_action' ) ) {
	BizCity_SelfWP_UI_REST::init();
}
