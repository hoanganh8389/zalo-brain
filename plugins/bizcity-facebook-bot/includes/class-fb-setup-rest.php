<?php
/**
 * Facebook four-step setup backend (PHASE-0.90 S90-F1, R-SETUP-4) under bizcity-channel/v1, admin only.
 *   GET    /fb/setup         the 4 steps, each with one of 5 states (locked | checking | ok | fail | denied) + message/hint/help_code
 *   POST   /fb/setup/pages   {page_ids[], owner_user_id}  register each page with the Hub (messenger + fb) - additive
 *   DELETE /fb/setup/pages   {page_ids[]}                 unregister + forget
 *   POST   /fb/setup/guru    {page_id, character_id, mode?}  Agent Guru per page (BizCity_Channel_Binding platform FB_PAGE)
 * "ok" for step 3 comes ONLY from the server: every selected page registered at the Hub AND Facebook's webhook reached this site
 * (BizCity_FB_Channel_Adapter::OPT_PING); never from a client claim. No token or secret is ever in a response.
 *
 * @package BizCity_Facebook_Bot
 * @since   PHASE-0.90 S90-F1
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_FB_Setup_REST', false ) ) {
	return;
}

final class BizCity_FB_Setup_REST {

	const NS        = 'bizcity-channel/v1';
	const PLATFORMS = array( 'messenger', 'fb' );
	/** Q90-9: default Fanpage of the channel tools (fb.post.create) when no conversation names one. */
	const OPT_DEFAULT_PAGE = 'bizcity_fb_default_page';

	/** @var array<string,callable> Tests: binding_resolve(page_id): ?array, binding_upsert(args): int. */
	public static $seams = array();

	private static function binding( string $page_id ): ?array {
		if ( isset( self::$seams['binding_resolve'] ) ) {
			return call_user_func( self::$seams['binding_resolve'], $page_id );
		}
		return class_exists( 'BizCity_Channel_Binding' ) ? BizCity_Channel_Binding::resolve( 'FB_PAGE', $page_id ) : null;
	}

	public static function register_routes(): void {
		$perm = array( __CLASS__, 'perm_admin' );
		register_rest_route( self::NS, '/fb/setup', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'get_setup' ), 'permission_callback' => $perm ) );
		register_rest_route( self::NS, '/fb/setup/pages', array(
			array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'post_pages' ), 'permission_callback' => $perm ),
			array( 'methods' => 'DELETE', 'callback' => array( __CLASS__, 'delete_pages' ), 'permission_callback' => $perm ),
		) );
		register_rest_route( self::NS, '/fb/setup/guru', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'post_guru' ), 'permission_callback' => $perm ) );
	}

	public static function perm_admin(): bool {
		return class_exists( 'BizCity_Network_Admin_Capability' ) ? BizCity_Network_Admin_Capability::can_manage() : current_user_can( 'manage_options' );
	}

	/** R-ERROR-UX: code + message + hint + help_code. */
	private static function err( string $code, string $message, string $hint, int $status = 400, string $help = 'S90-F1-ERR' ): WP_Error {
		return new WP_Error( $code, $message, array( 'status' => $status, 'hint' => $hint, 'help_code' => $help ) );
	}

	/** @param array $page_ids */
	private static function ids( $value ): array {
		return array_values( array_unique( array_filter( array_map( static function ( $v ) { return preg_replace( '/[^0-9A-Za-z_-]/', '', (string) $v ); }, (array) $value ) ) ) );
	}

	/** page_id => name for every page the Facebook OAuth connected (tokens stay inside the bot plugin). */
	private static function connected(): array {
		if ( ! class_exists( 'BizCity_Facebook_Bot_Database' ) ) {
			return array();
		}
		$out = array();
		foreach ( (array) BizCity_Facebook_Bot_Database::instance()->get_connected_pages() as $p ) {
			if ( ! empty( $p->page_id ) ) {
				$out[ (string) $p->page_id ] = (string) ( $p->bot_name ?? '' );
			}
		}
		return $out;
	}

	/* ---------------- GET ---------------- */

	public static function get_setup( $request = null ) {
		return rest_ensure_response( self::build() );
	}

	private static function step( int $n, string $id, string $title, string $state, string $message = '', string $hint = '', string $help = '' ): array {
		return array( 'n' => $n, 'id' => $id, 'title' => $title, 'state' => $state, 'message' => $message, 'hint' => $hint, 'help_code' => $help );
	}

	public static function build(): array {
		$connected = self::connected();
		$selected  = BizCity_FB_Channel_Adapter::pages();
		$scopes    = BizCity_FB_Channel_Adapter::granted_scopes();
		$ping      = (int) get_option( BizCity_FB_Channel_Adapter::OPT_PING, 0 );
		$groups_on = class_exists( 'BizCity_FB_Group_Adapter' ) && BizCity_FB_Group_Adapter::enabled();
		$has = static function ( array $need ) use ( $scopes ) {
			return null === $scopes ? null : ! array_diff( $need, $scopes );
		};
		$perm_groups = class_exists( 'BizCity_FB_Group_Adapter' ) ? BizCity_FB_Group_Adapter::permission() : array( 'ok' => false, 'known' => false );
		$permissions = array(
			'messages' => $has( array( 'pages_messaging' ) ),
			'comments' => $has( array( 'pages_read_user_content', 'pages_manage_engagement' ) ),
			'publish'  => $has( array( 'pages_manage_posts' ) ),
			'groups'   => $perm_groups['known'] ? (bool) $perm_groups['ok'] : null,
		);

		// ① 1API key
		$s1 = BizCity_FB_Hub_Client::is_ready()
			? self::step( 1, 'account', 'Kết nối tài khoản BizCity', 'ok', 'Đã có khoá 1API.' )
			: self::step( 1, 'account', 'Kết nối tài khoản BizCity', 'fail', 'Chưa có khoá 1API.', 'Dán khoá 1API lấy ở trang tài khoản BizCity.', 'S90-F1-KEY' );
		// ② server / cell
		$key_id = 'ok' === $s1['state'] ? BizCity_FB_Hub_Client::key_id( true ) : 0;
		if ( 'ok' !== $s1['state'] ) {
			$s2 = self::step( 2, 'server', 'Kết nối máy chủ', 'locked', 'Chờ bước 1.' );
		} elseif ( $key_id > 0 ) {
			$s2 = self::step( 2, 'server', 'Kết nối máy chủ', 'ok', 'Máy chủ BizCity đã nhận khoá của site.' );
		} else {
			$s2 = self::step( 2, 'server', 'Kết nối máy chủ', 'fail', 'Chưa kết nối được máy chủ BizCity.', 'Kiểm tra khoá 1API và thử lại.', 'S90-F1-HUB' );
		}
		// ③ pages
		$errors = array();
		foreach ( $selected as $pid => $row ) {
			foreach ( self::PLATFORMS as $plat ) {
				$r = $row['registered'][ $plat ] ?? '';
				if ( 'ok' !== $r ) {
					$errors[] = array( 'page_id' => (string) $pid, 'platform' => $plat, 'code' => (string) $r, 'hint' => (string) ( $row['hint'] ?? '' ) );
				}
			}
		}
		if ( 'ok' !== $s2['state'] ) {
			$s3 = self::step( 3, 'pages', 'Kết nối Fanpage', 'locked', 'Chờ bước 2.' );
		} elseif ( ! $connected ) {
			$s3 = self::step( 3, 'pages', 'Kết nối Fanpage', 'fail', 'Chưa có Fanpage nào.', 'Bấm Đăng nhập Facebook và cấp quyền.', 'S90-F1-OAUTH' );
		} elseif ( ! $selected ) {
			$s3 = self::step( 3, 'pages', 'Kết nối Fanpage', 'fail', 'Chưa chọn Fanpage và người phụ trách.', 'Chọn ít nhất một page và một người phụ trách.', 'S90-F1-PAGES' );
		} elseif ( false === $permissions['messages'] ) {
			$s3 = self::step( 3, 'pages', 'Kết nối Fanpage', 'denied', 'Chưa cấp quyền tin nhắn.', 'Đăng nhập lại Facebook và tick quyền pages_messaging.', 'S90-F1-PERM' );
		} elseif ( $errors ) {
			$hint = '';
			foreach ( $errors as $e ) {
				$hint = '' !== $e['hint'] ? $e['hint'] : $hint;
			}
			$taken = (bool) array_filter( $errors, static function ( $e ) { return 'channel_taken' === $e['code']; } );
			$s3    = self::step( 3, 'pages', 'Kết nối Fanpage', 'fail', $taken ? 'Chưa đạt: page đã kết nối ở tài khoản khác.' : 'Chưa đăng ký được page với máy chủ.', $hint, 'S90-F1-REG' );
		} elseif ( $ping <= 0 ) {
			$s3 = self::step( 3, 'pages', 'Kết nối Fanpage', 'checking', 'Đang chờ Facebook gửi thử về site.', 'Gửi một tin nhắn vào Fanpage hoặc bấm Test ở cấu hình webhook Meta.', 'S90-F1-PING' );
		} else {
			$s3 = self::step( 3, 'pages', 'Kết nối Fanpage', 'ok', 'Facebook đã gửi về site.' );
		}
		// ④ Agent Guru per page
		$unbound = array();
		foreach ( array_keys( $selected ) as $pid ) {
			$b = self::binding( (string) $pid );
			if ( ! $b || (int) ( $b['character_id'] ?? 0 ) <= 0 ) {
				$unbound[] = (string) $pid;
			}
		}
		if ( ! in_array( $s3['state'], array( 'ok', 'checking' ), true ) ) {
			$s4 = self::step( 4, 'guru', 'Chọn Agent Guru', 'denied' === $s3['state'] ? 'denied' : 'locked', 'Chờ bước 3.' );
		} elseif ( $unbound ) {
			$s4 = self::step( 4, 'guru', 'Chọn Agent Guru', 'fail', 'Còn ' . count( $unbound ) . ' page chưa có Agent Guru.', 'Chọn Agent Guru cho từng page.', 'S90-F1-GURU' );
		} else {
			$s4 = self::step( 4, 'guru', 'Chọn Agent Guru', 'ok', 'Mỗi page đã có Agent Guru.' );
		}

		$pages = array();
		foreach ( $connected as $pid => $name ) {
			$row     = $selected[ $pid ] ?? null;
			$owner   = (int) ( $row['owner_user_id'] ?? 0 );
			$udata   = $owner > 0 && function_exists( 'get_userdata' ) ? get_userdata( $owner ) : null;
			$binding = $row ? self::binding( (string) $pid ) : null;
			$pages[] = array(
				'page_id'       => (string) $pid,
				'name'          => $name,
				'selected'      => null !== $row,
				'owner_user_id' => $owner,
				'owner_name'    => $udata ? (string) $udata->display_name : '',
				'registered'    => array( 'messenger' => 'ok' === ( $row['registered']['messenger'] ?? '' ), 'fb' => 'ok' === ( $row['registered']['fb'] ?? '' ) ),
				'guru_id'       => (int) ( $binding['character_id'] ?? 0 ),
			);
		}
		$steps = array( $s1, $s2, $s3, $s4 );
		return array(
			'contract'      => 'fb-setup@1',
			'adapter'       => BizCity_FB_Channel_Adapter::mode(),
			'groups_flag'   => $groups_on,
			'permissions'   => $permissions,
			'groups_label'  => true === $permissions['groups'] ? 'nhóm ✔' : 'nhóm ✖',
			'key_suffix'    => 'ok' === $s1['state'] && class_exists( 'BizCity_LLM_Client' ) ? substr( (string) BizCity_LLM_Client::instance()->get_api_key( false ), -4 ) : '',
			'webhook_ping'  => array( 'received' => $ping > 0, 'at' => $ping > 0 ? gmdate( 'c', $ping ) : null ),
			'steps'         => $steps,
			'pages'         => $pages,
			'default_page_id' => (string) get_option( self::OPT_DEFAULT_PAGE, '' ),
			'done'          => 4 === count( array_filter( $steps, static function ( $s ) { return 'ok' === $s['state']; } ) ),
		);
	}

	/* ---------------- POST / DELETE pages ---------------- */

	public static function post_pages( $request ) {
		$ids   = self::ids( $request->get_param( 'page_ids' ) );
		$owner = (int) $request->get_param( 'owner_user_id' );
		if ( ! $ids ) {
			return self::err( 'invalid_param', 'Chưa chọn Fanpage.', 'Chọn ít nhất một page (page_ids).' );
		}
		if ( $owner <= 0 || ! get_userdata( $owner ) ) {
			return self::err( 'invalid_param', 'Người phụ trách không hợp lệ.', 'Chọn một tài khoản WordPress làm người phụ trách (owner_user_id).' );
		}
		$connected = self::connected();
		$unknown   = array_diff( $ids, array_keys( $connected ) );
		if ( $unknown ) {
			return self::err( 'invalid_param', 'Có page chưa được đăng nhập Facebook cấp quyền.', 'Bấm Đăng nhập Facebook trước.', 400, 'S90-F1-OAUTH' );
		}
		$pages   = BizCity_FB_Channel_Adapter::pages();
		$default = null; // Q90-9: the Fanpage the Agent posts to when no conversation names one; the model never picks it
		if ( null !== $request->get_param( 'default_page_id' ) ) {
			$want = self::ids( array( $request->get_param( 'default_page_id' ) ) );
			if ( $want ) {
				if ( ! in_array( $want[0], $ids, true ) && ! isset( $pages[ $want[0] ] ) ) {
					return self::err( 'invalid_param', 'Fanpage mặc định phải là một trong các page đã chọn.', 'Chọn Fanpage mặc định trong số các page ở bước 3 (default_page_id).', 400, 'S90-F1-PAGES' );
				}
				$default = $want[0];
			} else {
				$default = '';
			}
		}
		$result = array();
		$errors = array();
		foreach ( $ids as $pid ) {
			$row = array( 'owner_user_id' => $owner, 'registered' => array(), 'at' => time(), 'hint' => '' );
			foreach ( self::PLATFORMS as $plat ) {
				$r = BizCity_FB_Hub_Client::register( $plat, $pid );
				$row['registered'][ $plat ] = $r['ok'] ? 'ok' : (string) $r['error']['code'];
				if ( ! $r['ok'] ) {
					$row['hint'] = (string) $r['error']['hint'];
					$errors[]    = $r['error'] + array( 'page_id' => $pid, 'platform' => $plat );
				} elseif ( ! empty( $r['data']['cell_id'] ) ) {
					$row['cell_id'] = (string) $r['data']['cell_id'];
				}
			}
			$pages[ $pid ]  = $row;
			$result[ $pid ] = $row['registered'];
		}
		update_option( BizCity_FB_Channel_Adapter::OPT_PAGES, $pages, false );
		if ( null !== $default ) {
			update_option( self::OPT_DEFAULT_PAGE, $default, false );
		}
		return rest_ensure_response( array( 'ok' => ! $errors, 'registered' => $result, 'errors' => $errors, 'setup' => self::build() ) );
	}

	public static function delete_pages( $request ) {
		$ids = self::ids( $request->get_param( 'page_ids' ) );
		if ( ! $ids ) {
			return self::err( 'invalid_param', 'Chưa chọn Fanpage cần ngắt.', 'Truyền page_ids.' );
		}
		$pages  = BizCity_FB_Channel_Adapter::pages();
		$errors = array();
		foreach ( $ids as $pid ) {
			foreach ( self::PLATFORMS as $plat ) {
				$r = BizCity_FB_Hub_Client::unregister( $plat, $pid );
				if ( ! $r['ok'] ) {
					$errors[] = $r['error'] + array( 'page_id' => $pid, 'platform' => $plat );
				}
			}
			unset( $pages[ $pid ] ); // forgotten locally either way: the adapter stops forwarding at once, the old bot keeps answering
		}
		update_option( BizCity_FB_Channel_Adapter::OPT_PAGES, $pages, false );
		if ( in_array( (string) get_option( self::OPT_DEFAULT_PAGE, '' ), $ids, true ) ) {
			update_option( self::OPT_DEFAULT_PAGE, '', false ); // the default page was disconnected
		}
		return rest_ensure_response( array( 'ok' => ! $errors, 'errors' => $errors, 'setup' => self::build() ) );
	}

	/* ---------------- POST guru ---------------- */

	public static function post_guru( $request ) {
		$page = self::ids( array( $request->get_param( 'page_id' ) ) );
		$cid  = (int) $request->get_param( 'character_id' );
		if ( ! $page || $cid <= 0 ) {
			return self::err( 'invalid_param', 'Thiếu page hoặc Agent Guru.', 'Truyền page_id và character_id.' );
		}
		if ( ! isset( BizCity_FB_Channel_Adapter::pages()[ $page[0] ] ) ) {
			return self::err( 'invalid_param', 'Page này chưa được chọn ở bước 3.', 'Chọn và kết nối page trước.', 400, 'S90-F1-PAGES' );
		}
		if ( ! isset( self::$seams['binding_upsert'] ) && ! class_exists( 'BizCity_Channel_Binding' ) ) {
			return self::err( 'module_not_loaded', 'Chưa có Channel Gateway.', 'Bật Channel Gateway rồi thử lại.', 503 );
		}
		$mode = in_array( (string) $request->get_param( 'mode' ), array( 'auto', 'manual', 'hybrid', 'roundrobin' ), true ) ? (string) $request->get_param( 'mode' ) : 'auto';
		$args = array( 'platform' => 'FB_PAGE', 'account_id' => $page[0], 'character_id' => $cid, 'mode' => $mode );
		$id   = isset( self::$seams['binding_upsert'] ) ? (int) call_user_func( self::$seams['binding_upsert'], $args ) : BizCity_Channel_Binding::upsert( $args );
		if ( $id <= 0 ) {
			return self::err( 'save_failed', 'Chưa lưu được Agent Guru cho page.', 'Thử lại; nếu vẫn lỗi báo quản trị.', 500 );
		}
		return rest_ensure_response( array( 'ok' => true, 'binding_id' => $id, 'setup' => self::build() ) );
	}
}

add_action( 'rest_api_init', array( 'BizCity_FB_Setup_REST', 'register_routes' ) );
