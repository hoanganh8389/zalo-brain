<?php
/**
 * Facebook four-step setup backend (PHASE-0.90 S90-F1, R-SETUP-4) under bizcity-channel/v1, admin only.
 *   GET    /fb/setup         the 4 steps, each with one of 5 states (locked | checking | ok | fail | denied) + message/hint/help_code
 *   POST   /fb/setup/pages   {page_ids[], owner_user_id}  register each page with the Hub (messenger + fb) - additive
 *   DELETE /fb/setup/pages   {page_ids[]}                 unregister + forget
 *   POST   /fb/setup/guru    {page_id, character_id, mode?}  Agent Guru per page (BizCity_Channel_Binding platform FB_PAGE)
 * PHASE-0.109 (the cell answers Messenger directly, doc 60/62) adds, per page:
 *   POST   /fb/setup/source  {page_id, source: hub|own, app_secret?}   BizCity's app, or the client's own (secret goes to the cell once, never kept here)
 *   POST   /fb/setup/migrate {page_id}                                  QUICK MOVE: reuse the client's old page token / Guru / bot switches / own app secret + verify token; the owner only changes the Callback URL in Meta
 *   POST   /fb/setup/answer  {page_id, answer_path: cell_direct|site_legacy}   who answers: the cell (Meta calls it) or the old bot (way back)
 *   GET    /fb/setup/trace?page_id=                                    the cell's last turns of the page (ms per leg, no text) + 24 h health
 *   GET|POST /fb/setup/tools {page_id[, tools_off[]|null]}             built-in tools the cell may use for this page (cell's list; page only turns OFF)
 * "ok" for step 3 comes ONLY from the server: every selected page registered at the Hub AND Facebook's webhook reached this site
 * (BizCity_FB_Channel_Adapter::OPT_PING); never from a client claim. No token or secret is ever in a response.
 *
 * @package BizCity_Facebook_Bot
 * @since   PHASE-0.90 S90-F1
 */

defined( 'ABSPATH' ) || exit;

// [2026-10-14 11:53 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.109 — the `class_exists(self, false) → return` guard that stood here ALWAYS returned: a top-level class without a parent is hoisted at compile time, so it already
// exists when the guard runs, and every statement after the class (the add_action below) was skipped. Production proof (bizcity.vn): class loaded, has_action(rest_api_init) false, /fb/setup 404.
// The file is loaded with require_once only, so no guard is needed.

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

	/** [2026-10-14 08:04 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.109 S109-L5 — the Guru (character) picked for a page, 0 when none: what the cell answers with */
	public static function guru_of_page( string $page ): int {
		$b = self::binding( $page );
		return (int) ( $b['character_id'] ?? 0 );
	}

	public static function register_routes(): void {
		$perm = array( __CLASS__, 'perm_admin' );
		register_rest_route( self::NS, '/fb/setup', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'get_setup' ), 'permission_callback' => $perm ) );
		register_rest_route( self::NS, '/fb/setup/pages', array(
			array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'post_pages' ), 'permission_callback' => $perm ),
			array( 'methods' => 'DELETE', 'callback' => array( __CLASS__, 'delete_pages' ), 'permission_callback' => $perm ),
		) );
		register_rest_route( self::NS, '/fb/setup/guru', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'post_guru' ), 'permission_callback' => $perm ) );
		// [2026-10-14 08:04 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.109 S109-L10/L11/L13
		register_rest_route( self::NS, '/fb/setup/source', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'post_source' ), 'permission_callback' => $perm ) );
		register_rest_route( self::NS, '/fb/setup/migrate', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'post_migrate' ), 'permission_callback' => $perm ) );
		register_rest_route( self::NS, '/fb/setup/answer', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'post_answer' ), 'permission_callback' => $perm ) );
		register_rest_route( self::NS, '/fb/setup/claim-route', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'post_claim_route' ), 'permission_callback' => $perm ) );
		register_rest_route( self::NS, '/fb/setup/trace', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'get_trace' ), 'permission_callback' => $perm ) );
		register_rest_route( self::NS, '/fb/setup/tools', array(
			array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'get_tools' ), 'permission_callback' => $perm ),
			array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'post_tools' ), 'permission_callback' => $perm ),
		) );
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

	/**
	 * Step ③ "Đạt" comes from the server only. A page the CELL answers is proven by the cell (Meta delivered a customer message to it,
	 * `preflight` check `webhook`); a page the old bot answers is proven by this site's own webhook ping. Mixed pages need both.
	 */
	private static function webhook_reached( array $selected, int $site_ping ): bool {
		$need_site = false;
		foreach ( array_keys( $selected ) as $pid ) {
			if ( ! BizCity_FB_Cell_Direct::is_cell_direct( (string) $pid ) ) {
				$need_site = true;
				continue;
			}
			$pf = BizCity_FB_Cell_Direct::preflight( (string) $pid );
			$ok = false;
			foreach ( (array) ( $pf['checks'] ?? array() ) as $c ) {
				if ( 'webhook' === ( $c['id'] ?? '' ) && 'ok' === ( $c['state'] ?? '' ) ) {
					$ok = true;
				}
			}
			if ( ! $ok ) {
				return false;
			}
		}
		return ! $need_site || $site_ping > 0;
	}

	/* ---------------- GET ---------------- */

	public static function get_setup( $request = null ) {
		return rest_ensure_response( self::build() );
	}

	/**
	 * [2026-10-14 08:04 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.109 S109-L7 (fb-setup@1.1) — what the screen needs to say WHO answers a page and whether it is ready.
	 * `answered_by` is never guessed from a switch: cell (Meta calls the cell, or the old site adapter relays to it) | legacy (the old bot) | off (page not chosen).
	 * Secrets never appear: only `callback_url` + `verify_token` of an OWN app (the owner pastes both into Meta; the App Secret is not stored).
	 */
	/**
	 * [2026-10-15 Johnny Chu - Chu Hoàng Anh] PHASE-0.109 S109-L24 audit — the domain / blog id of ANOTHER tenant is shown only to a network admin. A site admin who merely sees that
	 * the hub routes the page elsewhere gets `here:false` and nothing else (no cross-tenant disclosure).
	 */
	private static function route_view( ?array $own ): ?array {
		if ( null === $own ) {
			return null;
		}
		if ( $own['here'] ) {
			return array( 'here' => true, 'blog' => (int) $own['blog'], 'site' => '' );
		}
		$super = isset( self::$seams['is_super'] ) ? (bool) call_user_func( self::$seams['is_super'] ) : ( function_exists( 'is_super_admin' ) && is_super_admin() );
		return $super ? $own : array( 'here' => false, 'blog' => 0, 'site' => '' );
	}

	private static function cell_fields( string $pid, bool $selected, int $guru_id ): array {
		$s       = BizCity_FB_Cell_Direct::state( $pid );
		$direct  = BizCity_FB_Cell_Direct::PATH_CELL === $s['path'];
		$relay   = ! $direct && 'cell' === BizCity_FB_Channel_Adapter::mode();
		$route   = 'own' === $s['source'] ? ( '' !== $s['route_id'] ? $s['route_id'] : BizCity_FB_Cell_Direct::own_route()['route_id'] ) : '';
		$verify  = 'own' === $s['source'] ? ( '' !== $s['verify_token'] ? $s['verify_token'] : BizCity_FB_Cell_Direct::own_route()['verify_token'] ) : '';
		$pf      = $direct || 'own' === $s['source'] ? BizCity_FB_Cell_Direct::preflight( $pid ) : null;
		return array(
			'source'            => $s['source'],
			'answer_path'       => $s['path'],
			'ingress'           => $direct ? ( 'own' === $s['source'] ? $s['ingress'] : BizCity_FB_Cell_Direct::INGRESS_DIRECT ) : null, // site_forward = Meta still calls the site, which hands the events to the cell · meta_direct = Meta calls the cell
			'answer_route'      => $direct ? ( ( BizCity_FB_Cell_Direct::INGRESS_FORWARD === $s['ingress'] && 'own' === $s['source'] && '' !== $route ) ? 'site_forward' : 'cell_direct' ) : ( $relay ? 'site_relay' : 'legacy' ),
			'answered_by'       => ! $selected ? 'off' : ( $direct || $relay ? 'cell' : 'legacy' ),
			'guru_reaches_cell' => $direct && $guru_id > 0 && '' === $s['last_error'] && $s['pushed_at'] > 0,
			'callback_url'      => BizCity_FB_Cell_Direct::callback_url( $route ),
			'verify_token'      => $verify,
			'tools_configured'  => null !== $s['tools_off'],
			'cell_error'        => $s['last_error'],
			'last_in_at'        => $pf['last_in_at'] ?? null,
			'health'            => $pf['health'] ?? null, // {count_24h, failed_24h, p50_ms, p95_ms, last_at} from the cell, null until it answers
			'preflight'         => $pf['checks'] ?? array(),
			// the cell is armed (path cell_direct) but Facebook has not reached it yet: the old bot still answers until the Callback URL is changed
			'cell_waiting_webhook' => $direct && ! self::check_ok( $pf, 'webhook' ),
			'app_move'          => 'own' === $s['source'] ? self::app_move( $pid ) : null,
			// S109-L24: the blog the Facebook hub routes this page to (multisite). `here:false` = the Guru picked on THIS screen is not the one the cell will look for
			'legacy_ai'         => (bool) BizCity_FB_Cell_Direct::legacy_flags( $pid )['ai_enabled'], // the old bot's "AI trả lời" switch: auto-adoption only starts when it is ON
			'route'             => self::route_view( BizCity_FB_Cell_Direct::route_owner( $pid ) ),
		);
	}

	/**
	 * [2026-10-14 08:25 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.109 Q109-R4-1 (owner: "cảnh báo và hướng dẫn") — Meta calls ONE Callback URL per app, so pointing an own app at the cell moves EVERY page
	 * that uses it. For a page on the client's own app: the other pages on that app and which of them still answer through the old bot (they would go silent after the move).
	 * `comments_note`: Q109-R4-2 (owner: keep the own app for comments) - the cell does not read comments yet, so comments of every page on this app must stay on the site.
	 */
	private static function app_move( string $pid ): array {
		$names = self::connected();
		$pages = array();
		foreach ( array_keys( BizCity_FB_Channel_Adapter::pages() ) as $other ) {
			$other = (string) $other;
			if ( $other === $pid || 'own' !== BizCity_FB_Cell_Direct::state( $other )['source'] ) {
				continue;
			}
			$pages[] = array( 'page_id' => $other, 'name' => (string) ( $names[ $other ] ?? '' ), 'answered_by' => BizCity_FB_Cell_Direct::is_cell_direct( $other ) ? 'cell' : 'legacy' );
		}
		return array(
			'pages'          => $pages,
			'legacy_pages'   => array_values( array_filter( $pages, static function ( $p ) { return 'legacy' === $p['answered_by']; } ) ),
			'comments_note'  => true,
		);
	}

	private static function check_ok( ?array $pf, string $id ): bool {
		foreach ( (array) ( $pf['checks'] ?? array() ) as $c ) {
			if ( $id === ( $c['id'] ?? '' ) && 'ok' === ( $c['state'] ?? '' ) ) {
				return true;
			}
		}
		return false;
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
		} elseif ( ! self::webhook_reached( $selected, $ping ) ) {
			$s3 = self::step( 3, 'pages', 'Kết nối Fanpage', 'checking', 'Đang chờ Facebook gửi thử về máy chủ trả lời.', 'Gửi một tin nhắn vào Fanpage hoặc bấm Test ở cấu hình webhook Meta.', 'S90-F1-PING' );
		} else {
			$s3 = self::step( 3, 'pages', 'Kết nối Fanpage', 'ok', 'Facebook đã gửi tin về.' );
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
			// the Guru the owner picked on the card exists even for a page that never went through step ③ (the hand-off reads the same binding): show it for every page
			$binding = self::binding( (string) $pid );
			$pages[] = array(
				'page_id'       => (string) $pid,
				'name'          => $name,
				'selected'      => null !== $row,
				'owner_user_id' => $owner,
				'owner_name'    => $udata ? (string) $udata->display_name : '',
				'registered'    => array( 'messenger' => 'ok' === ( $row['registered']['messenger'] ?? '' ), 'fb' => 'ok' === ( $row['registered']['fb'] ?? '' ) ),
				'guru_id'       => (int) ( $binding['character_id'] ?? 0 ),
			) + self::cell_fields( (string) $pid, null !== $row, (int) ( $binding['character_id'] ?? 0 ) );
		}
		$steps = array( $s1, $s2, $s3, $s4 );
		return array(
			'contract'      => 'fb-setup@1.1',
			// which site answers this screen (its OWN id and host - never another tenant's): the selfcheck prints it so a multisite run says where it ran
			'this_site'     => array( 'blog_id' => function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0, 'host' => function_exists( 'home_url' ) ? (string) wp_parse_url( home_url(), PHP_URL_HOST ) : '' ),
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
			if ( isset( BizCity_FB_Cell_Direct::all()[ $pid ] ) ) {
				BizCity_FB_Cell_Direct::remove_page( $pid ); // PHASE-0.109: the cell forgets the page, its token and its tool list
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
		BizCity_FB_Cell_Direct::push_if_active( $page[0] ); // the page's Guru is what the cell answers with
		return rest_ensure_response( array( 'ok' => true, 'binding_id' => $id, 'setup' => self::build() ) );
	}

	/* ---------------- PHASE-0.109: source / answer path / tools ---------------- */

	/** @return string|WP_Error a chosen page id of this site */
	private static function chosen_page( $request ) {
		$page = self::ids( array( $request->get_param( 'page_id' ) ) );
		if ( ! $page ) {
			return self::err( 'invalid_param', 'Thiếu page.', 'Truyền page_id.' );
		}
		if ( ! isset( BizCity_FB_Channel_Adapter::pages()[ $page[0] ] ) ) {
			return self::err( 'invalid_param', 'Page này chưa được chọn ở bước 3.', 'Chọn và kết nối page trước.', 400, 'S90-F1-PAGES' );
		}
		return $page[0];
	}

	/** Frame of a failed push: R-ERROR-UX four fields, nothing else from the Hub */
	private static function push_error( array $e ): WP_Error {
		return self::err( (string) ( $e['code'] ?? 'cell_unreachable' ), (string) ( $e['message'] ?? 'Chưa gửi được cấu hình tới máy chủ.' ), (string) ( $e['hint'] ?? 'Thử lại sau ít giây.' ), 502, (string) ( $e['help_code'] ?? 'S109-L10' ) );
	}

	/**
	 * POST /fb/setup/source {page_id, source, app_secret?, verify_token?} — ① hub: BizCity's shared Meta app (nothing to paste) · ② own: the client's app.
	 * For an own app the App Secret goes to the cell now and is not kept; the response carries the Callback URL + Verify token the owner pastes into Meta.
	 */
	public static function post_source( $request ) {
		$page = self::chosen_page( $request );
		if ( is_wp_error( $page ) ) {
			return $page;
		}
		$source = (string) $request->get_param( 'source' );
		if ( ! in_array( $source, BizCity_FB_Cell_Direct::SOURCES, true ) ) {
			return self::err( 'invalid_param', 'Nguồn kết nối không hợp lệ.', 'source là hub (app BizCity) hoặc own (app của bạn).' );
		}
		$patch = array( 'source' => $source );
		$out   = array();
		if ( 'own' === $source ) {
			$secret = trim( (string) $request->get_param( 'app_secret' ) );
			$cur    = BizCity_FB_Cell_Direct::own_route();
			if ( '' === $secret && '' === $cur['route_id'] ) {
				return self::err( 'invalid_param', 'Chưa nhập App Secret của app Meta.', 'Dán App Secret ở Meta for Developers › App settings › Basic.', 400, 'S109-L10-SECRET' );
			}
			if ( '' !== $secret ) {
				if ( strlen( $secret ) < 8 || strlen( $secret ) > 200 ) {
					return self::err( 'invalid_param', 'App Secret không đúng định dạng.', 'Chép lại App Secret từ Meta for Developers.', 400, 'S109-L10-SECRET' );
				}
				$r = BizCity_FB_Cell_Direct::push_route( $secret, trim( (string) $request->get_param( 'verify_token' ) ) );
				if ( ! $r['ok'] ) {
					return self::push_error( (array) $r['error'] );
				}
			}
			$route         = BizCity_FB_Cell_Direct::own_route();
			$patch        += array( 'route_id' => $route['route_id'], 'verify_token' => $route['verify_token'] );
			$out['callback_url'] = BizCity_FB_Cell_Direct::callback_url( $route['route_id'] );
			$out['verify_token'] = $route['verify_token'];
		}
		BizCity_FB_Cell_Direct::save( $page, $patch );
		$push = BizCity_FB_Cell_Direct::path_of( $page ) === BizCity_FB_Cell_Direct::PATH_CELL ? BizCity_FB_Cell_Direct::push_page( $page ) : array( 'ok' => true );
		if ( ! $push['ok'] ) {
			return self::push_error( (array) $push['error'] );
		}
		return rest_ensure_response( array( 'ok' => true ) + $out + array( 'setup' => self::build() ) );
	}

	/**
	 * POST /fb/setup/answer {page_id, answer_path} — who answers the page. `cell_direct`: the cell answers when Meta calls it (the owner must have pointed the app's
	 * Callback URL at the cell: ALL pages of that Meta app then go to the cell). `site_legacy`: the cell is told `enabled:false` and NOBODY auto-answers ([2026-10-24 Johnny Chu - Chu Hoàng Anh] PHASE-0.126 R126-FB: the old PHP bot is gone) — the owner's "Tắt trợ lý" choice.
	 */
	public static function post_answer( $request ) {
		$page = self::chosen_page( $request );
		if ( is_wp_error( $page ) ) {
			return $page;
		}
		$path = (string) $request->get_param( 'answer_path' );
		if ( ! in_array( $path, array( BizCity_FB_Cell_Direct::PATH_CELL, BizCity_FB_Cell_Direct::PATH_LEGACY ), true ) ) {
			return self::err( 'invalid_param', 'Cách trả lời không hợp lệ.', 'answer_path là cell_direct hoặc site_legacy.' );
		}
		if ( BizCity_FB_Cell_Direct::PATH_CELL === $path && self::guru_of_page( $page ) <= 0 ) {
			return self::err( 'invalid_param', 'Page chưa có Agent Guru.', 'Chọn Agent Guru cho page trước khi để máy chủ trả lời.', 400, 'S90-F1-GURU' );
		}
		// the owner's own choice beats the automatic adoption: "Tắt trợ lý" (site_legacy) = opted out (never adopted again), the cell = back in
		BizCity_FB_Cell_Direct::save( $page, array( 'path' => $path, 'opt_out' => BizCity_FB_Cell_Direct::PATH_LEGACY === $path ) );
		$push = BizCity_FB_Cell_Direct::push_page( $page );
		if ( ! $push['ok'] ) {
			if ( BizCity_FB_Cell_Direct::PATH_CELL === $path ) {
				BizCity_FB_Cell_Direct::save( $page, array( 'path' => BizCity_FB_Cell_Direct::PATH_LEGACY ) ); // never leave a page "answered by the cell" that the cell does not know
			}
			return self::push_error( (array) $push['error'] );
		}
		return rest_ensure_response( array( 'ok' => true, 'setup' => self::build() ) );
	}

	/**
	 * [2026-10-14 10:22 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.109 S109-L18/L19 — make an old page a "chosen" page (registered at the Hub, a person in charge) when it is not one yet. Used by the quick move AND by the
	 * webhook's automatic adoption. @return null on success (or already chosen), else the WP_Error to answer with
	 */
	public static function ensure_page_chosen( string $pid, int $owner ) {
		if ( isset( BizCity_FB_Channel_Adapter::pages()[ $pid ] ) ) {
			return null;
		}
		$reg = self::post_pages( new WP_REST_Request( 'POST', '/x', array( 'page_ids' => array( $pid ), 'owner_user_id' => $owner ) ) );
		if ( is_wp_error( $reg ) ) {
			return $reg;
		}
		$data = is_object( $reg ) && method_exists( $reg, 'get_data' ) ? (array) $reg->get_data() : (array) $reg;
		if ( empty( $data['ok'] ) ) {
			$e = (array) ( $data['errors'][0] ?? array() );
			return self::push_error( $e + array( 'code' => 'register_failed', 'message' => 'Chưa đăng ký được page với máy chủ.', 'hint' => 'Kiểm tra bước 1–2 (khoá 1API) rồi thử lại.', 'help_code' => 'S109-L18' ) );
		}
		return null;
	}

	/**
	 * [2026-10-15 10:24 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.109 S109-L24 — POST /fb/setup/claim-route {page_id}: "nhận page về site này". Multisite only, NETWORK ADMIN only (it moves a page between tenants):
	 * the page keeps the Guru / token / bot switches the owner already set on THIS site, and from now on the hub hands its messages to this blog - so nothing is picked twice.
	 */
	public static function post_claim_route( $request ) {
		$page = self::chosen_page( $request );
		if ( is_wp_error( $page ) ) {
			return $page;
		}
		$super = isset( self::$seams['is_super'] ) ? (bool) call_user_func( self::$seams['is_super'] ) : ( function_exists( 'is_super_admin' ) && is_super_admin() );
		if ( ! $super ) {
			return self::err( 'permission_denied', 'Chỉ quản trị mạng mới chuyển được page giữa các site.', 'Nhờ quản trị mạng bấm "Nhận page về site này", hoặc chọn Guru ở site đang nhận tin của page.', 403, 'S109-L24' );
		}
		$own = BizCity_FB_Cell_Direct::route_owner( $page );
		if ( null === $own ) {
			return self::err( 'invalid_param', 'Không cần chuyển: hệ thống không dùng định tuyến nhiều site.', 'Bỏ qua.', 400, 'S109-L24' );
		}
		if ( $own['here'] ) {
			return rest_ensure_response( array( 'ok' => true, 'moved' => false, 'setup' => self::build() ) );
		}
		$token = BizCity_FB_Cell_Direct::page_token_of( $page );
		if ( '' === $token ) {
			return self::err( 'invalid_param', 'Site này chưa có token của page.', 'Kết nối lại page ở bước 3 rồi thử lại.', 400, 'S109-L24' );
		}
		$ok = isset( self::$seams['claim_route'] ) ? (bool) call_user_func( self::$seams['claim_route'], $page, (int) get_current_blog_id(), $token ) : ( class_exists( 'BizCity_Facebook_Central_Webhook' ) && BizCity_Facebook_Central_Webhook::register_route( $page, (int) get_current_blog_id(), '', $token ) );
		if ( ! $ok ) {
			return self::err( 'save_failed', 'Chưa chuyển được page về site này.', 'Thử lại; nếu vẫn lỗi báo quản trị.', 500, 'S109-L24' );
		}
		// a page changing tenant leaves a trace (who, from, to): the route table is network-global
		error_log( sprintf( '[BizCity FB Route] CLAIM page=%s from_blog=%d to_blog=%d by_user=%d', $page, (int) $own['blog'], (int) get_current_blog_id(), (int) get_current_user_id() ) );
		return rest_ensure_response( array( 'ok' => true, 'moved' => true, 'from_blog' => $own['blog'], 'setup' => self::build() ) );
	}

	/** POST /fb/setup/migrate {page_id} — quick move with the old configuration (see BizCity_FB_Cell_Direct::migrate) */
	public static function post_migrate( $request ) {
		// [2026-10-14 10:04 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.109 S109-L18 — a page connected BEFORE the four-step setup existed was never "chosen at step ③" (never registered at the Hub, no person in charge).
		// The quick move must not make the owner redo that: it registers the page now with the person pressing the button as the person in charge, then moves it.
		$ids = self::ids( array( $request->get_param( 'page_id' ) ) );
		if ( ! $ids ) {
			return self::err( 'invalid_param', 'Thiếu page.', 'Truyền page_id.' );
		}
		$chosen = self::ensure_page_chosen( $ids[0], (int) get_current_user_id() );
		if ( null !== $chosen ) {
			return $chosen;
		}
		$page = self::chosen_page( $request );
		if ( is_wp_error( $page ) ) {
			return $page;
		}
		$r = BizCity_FB_Cell_Direct::migrate( $page );
		if ( ! $r['ok'] ) {
			return self::push_error( (array) $r['error'] );
		}
		unset( $r['ok'] );
		return rest_ensure_response( array( 'ok' => true ) + $r + array( 'setup' => self::build() ) );
	}

	/** GET /fb/setup/trace?page_id= — the cell's record of the last turns: where the time went, the result, a masked customer; never a message */
	public static function get_trace( $request ) {
		$page = self::chosen_page( $request );
		if ( is_wp_error( $page ) ) {
			return $page;
		}
		$t = BizCity_FB_Cell_Direct::trace( $page, min( 100, max( 1, (int) ( $request->get_param( 'limit' ) ?: 20 ) ) ) );
		if ( null === $t ) {
			return self::err( 'cell_unreachable', 'Chưa lấy được lịch sử lượt từ máy chủ.', 'Kiểm tra bước 1–2 rồi thử lại.', 502, 'S109-L8' );
		}
		return rest_ensure_response( array( 'ok' => true, 'page_id' => $page ) + $t );
	}

	/** GET /fb/setup/tools?page_id= — the cell's list of tools that can reach a customer turn of this page: key, group, on, locked */
	public static function get_tools( $request ) {
		$page = self::chosen_page( $request );
		if ( is_wp_error( $page ) ) {
			return $page;
		}
		$t = BizCity_FB_Cell_Direct::tools( $page );
		if ( null === $t ) {
			return self::err( 'cell_unreachable', 'Chưa lấy được danh sách công cụ từ máy chủ.', 'Kiểm tra bước 1–2 rồi thử lại.', 502, 'S109-L13' );
		}
		return rest_ensure_response( array( 'ok' => true, 'page_id' => $page ) + $t );
	}

	/**
	 * POST /fb/setup/tools {page_id, tools_off: string[] | null} — null = the preset "Chăm khách"; an array = the owner's choice (the page only turns tools OFF in
	 * addition; the cell still keeps back the Guru's / plan's off list and the file-creating group while Messenger cannot send files).
	 */
	public static function post_tools( $request ) {
		$page = self::chosen_page( $request );
		if ( is_wp_error( $page ) ) {
			return $page;
		}
		$raw = $request->get_param( 'tools_off' );
		if ( null === $raw ) {
			$tools = null;
		} elseif ( is_array( $raw ) ) {
			$tools = array();
			foreach ( $raw as $k ) {
				if ( ! is_string( $k ) || ! preg_match( '/^[a-z0-9_]{1,64}$/', $k ) ) {
					return self::err( 'invalid_param', 'Tên công cụ không hợp lệ.', 'tools_off là danh sách khoá công cụ dạng chữ thường_gạch_dưới.', 400, 'S109-L13' );
				}
				$tools[] = $k;
			}
			$tools = array_slice( array_values( array_unique( $tools ) ), 0, 200 );
		} else {
			return self::err( 'invalid_param', 'tools_off phải là danh sách hoặc null.', 'Truyền tools_off: [] hoặc null.', 400, 'S109-L13' );
		}
		BizCity_FB_Cell_Direct::save( $page, array( 'tools_off' => $tools ) );
		// pushed whatever the path: a page still on the old bot is sent `enabled:false` with its tool list, so the screen shows the real choice the moment it is switched
		$push = BizCity_FB_Cell_Direct::push_page( $page );
		if ( ! $push['ok'] ) {
			return self::push_error( (array) $push['error'] );
		}
		return rest_ensure_response( array( 'ok' => true, 'page_id' => $page, 'tools_configured' => null !== $tools ) );
	}
}

add_action( 'rest_api_init', array( 'BizCity_FB_Setup_REST', 'register_routes' ) );
