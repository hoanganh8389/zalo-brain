<?php
/**
 * Facebook / Messenger answered by the CELL directly (PHASE-0.109 round 2, doc 60/62) — the site's control side.
 *
 * Meta calls the cell's public webhook (https://bizcity.vn/fb-hook/<route_id>, Apache ProxyPass); the cell verifies, gathers messages,
 * answers through the page's Guru, sends with Graph itself and reports usage to the Hub. The site no longer sits on the message path: it only
 *   - tells the cell, through the Hub, which pages answer, with which Guru and tools, and the secrets the cell needs (`channel-config@1`);
 *   - receives the CRM records the cell sends back (`channel-record@1`, BizCity_FB_Channel_Record).
 *
 * Per page, option `bizcity_fb_cell_direct[page_id]`:
 *   path          site_legacy (default, the old bot answers when Meta still calls the site) | cell_direct
 *   source        hub = BizCity's shared Meta app | own = the client's own Meta app (route below)
 *   route_id      cell-made id of the client's own app route ('' for the shared app)
 *   verify_token  the verify token the owner pastes into Meta for the own app (a handshake token, not a secret of Meta)
 *   tools_off     null = nobody chose (preset "Chăm khách") | array of tool keys the owner turned off
 *   pushed_at / pushed_hash / last_error   what the cell last accepted
 * The App Secret is NEVER stored here: it goes to the cell once and is not kept. The page token is read from the old bot's store at push time.
 * The way back is `path = site_legacy` (the cell is told `enabled:false`, the old bot answers as before 0.109).
 *
 * @package BizCity_Facebook_Bot
 * @since   PHASE-0.109 S109-L10/L11/L13
 */
// [2026-10-14 08:04 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.109 S109-L10/L11/L13

defined( 'ABSPATH' ) || exit;

// [2026-10-14 11:53 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.109 — the `class_exists(self, false) → return` guard that stood here ALWAYS returned: a top-level class without a parent is hoisted at compile time, so it already
// exists when the guard runs, and every statement after the class (the add_action below) was skipped. Production proof (bizcity.vn): class loaded, has_action(rest_api_init) false, /fb/setup 404.
// The file is loaded with require_once only, so no guard is needed.

final class BizCity_FB_Cell_Direct {

	const OPT          = 'bizcity_fb_cell_direct';
	const OPT_ROUTE    = 'bizcity_fb_cell_route';
	const HOOK_BASE    = 'https://bizcity.vn/fb-hook/';
	const PATH_LEGACY  = 'site_legacy';
	const PATH_CELL    = 'cell_direct';
	/** [2026-10-14 09:35 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.109 S109-L17 — how the cell RECEIVES Meta's events: the SITE forwards them (Meta keeps calling the site, nothing to change in Meta) or Meta calls the cell directly */
	const INGRESS_FORWARD = 'site_forward';
	const INGRESS_DIRECT  = 'meta_direct';
	const FORWARD_TIMEOUT  = 4;
	/** option: 'off' stops the automatic adoption of old pages (default on) */
	const OPT_AUTO         = 'bizcity_fb_cell_autoadopt';
	const SOURCES      = array( 'hub', 'own' );
	const PREFLIGHT_TTL = 60;

	/** @var array<string,callable> Tests: now(): int · web_pushed(): bool · guru_of(page): int · page_token(page): string */
	public static $seams = array();

	private static function seam( string $name, callable $default, array $args = array() ) {
		$fn = isset( self::$seams[ $name ] ) ? self::$seams[ $name ] : $default;
		return call_user_func_array( $fn, $args );
	}

	private static function now(): int {
		return (int) self::seam( 'now', 'time' );
	}

	/** Where Meta is told to call: the cell's public gateway (filter `bizcity_fb_hook_base` for a different host) */
	public static function hook_base(): string {
		$base = function_exists( 'apply_filters' ) ? (string) apply_filters( 'bizcity_fb_hook_base', self::HOOK_BASE ) : self::HOOK_BASE;
		return rtrim( $base, '/' ) . '/';
	}

	/** The Callback URL an owner of an own app pastes into Meta › Webhooks (the shared BizCity app needs nothing) */
	public static function callback_url( string $route_id ): string {
		return '' === $route_id ? '' : self::hook_base() . rawurlencode( $route_id );
	}

	/** @return array<string,array> page_id => stored state */
	public static function all(): array {
		$v = get_option( self::OPT, array() );
		return is_array( $v ) ? $v : array();
	}

	/** One page's state with every key present */
	public static function state( string $page ): array {
		$s = self::all()[ $page ] ?? array();
		$s = is_array( $s ) ? $s : array();
		return array(
			'path'         => self::PATH_CELL === ( $s['path'] ?? '' ) ? self::PATH_CELL : self::PATH_LEGACY,
			'source'       => in_array( $s['source'] ?? '', self::SOURCES, true ) ? (string) $s['source'] : 'hub',
			'route_id'     => (string) ( $s['route_id'] ?? '' ),
			'ingress'      => self::INGRESS_DIRECT === ( $s['ingress'] ?? '' ) ? self::INGRESS_DIRECT : self::INGRESS_FORWARD,
			'verify_token' => (string) ( $s['verify_token'] ?? '' ),
			'tools_off'    => isset( $s['tools_off'] ) && is_array( $s['tools_off'] ) ? array_values( array_map( 'strval', $s['tools_off'] ) ) : null,
			'pushed_at'    => (int) ( $s['pushed_at'] ?? 0 ),
			'pushed_hash'  => (string) ( $s['pushed_hash'] ?? '' ),
			'last_error'   => (string) ( $s['last_error'] ?? '' ),
			'opt_out'      => ! empty( $s['opt_out'] ),
			'adopted_at'   => (int) ( $s['adopted_at'] ?? 0 ),
		);
	}

	public static function path_of( string $page ): string {
		return self::state( $page )['path'];
	}

	public static function is_cell_direct( string $page ): bool {
		return self::PATH_CELL === self::path_of( $page );
	}

	public static function save( string $page, array $patch ): array {
		$all          = self::all();
		$all[ $page ] = array_merge( (array) ( $all[ $page ] ?? array() ), $patch );
		update_option( self::OPT, $all, false );
		return self::state( $page );
	}

	public static function forget( string $page ): void {
		$all = self::all();
		unset( $all[ $page ] );
		update_option( self::OPT, $all, false );
	}

	/** The site-wide own-app route {route_id, verify_token} (one Meta app per site) */
	public static function own_route(): array {
		$r = get_option( self::OPT_ROUTE, array() );
		return array( 'route_id' => (string) ( $r['route_id'] ?? '' ), 'verify_token' => (string) ( $r['verify_token'] ?? '' ) );
	}

	/** Guru (character) ids of the pages the cell answers: the web bridge serves exactly these Gurus to the cell (and no other) */
	public static function guru_ids(): array {
		$ids = array();
		foreach ( self::all() as $page => $_ ) {
			if ( self::is_cell_direct( (string) $page ) ) {
				$id = self::guru_of( (string) $page );
				if ( $id > 0 ) {
					$ids[ $id ] = $id;
				}
			}
		}
		return array_values( $ids );
	}

	public static function guru_of( string $page ): int {
		return (int) self::seam( 'guru_of', static function ( $page ) {
			return class_exists( 'BizCity_FB_Setup_REST', false ) ? BizCity_FB_Setup_REST::guru_of_page( $page ) : 0;
		}, array( $page ) );
	}

	/** The page token this site holds (seam-able); never returned to a screen */
	public static function page_token_of( string $page ): string {
		return self::token_of( $page );
	}

	private static function token_of( string $page ): string {
		return (string) self::seam( 'page_token', static function ( $page ) {
			return class_exists( 'BizCity_FB_Channel_Adapter', false ) ? BizCity_FB_Channel_Adapter::page_token( $page ) : '';
		}, array( $page ) );
	}

	/* ---------------- push to the cell (through the Hub) ---------------- */

	/**
	 * Create or update the site's OWN-app route on the cell. The App Secret goes to the cell now and is not kept here.
	 *
	 * @return array {ok, route_id?, callback_url?, verify_token?, error?}
	 */
	public static function push_route( string $app_secret, string $verify_token = '' ): array {
		$cur   = self::own_route();
		$verify = '' !== $verify_token ? $verify_token : ( '' !== $cur['verify_token'] ? $cur['verify_token'] : ( function_exists( 'wp_generate_password' ) ? wp_generate_password( 24, false, false ) : bin2hex( random_bytes( 12 ) ) ) );
		$route = array( 'source' => 'own', 'app_secret' => $app_secret, 'verify_token' => $verify );
		// S109-L27: where the cell hands this app's COMMENTS back to (it reads messages only). Sent only when it is a public https URL - the cell refuses anything else and a refused route would fail the whole push.
		$relay = self::relay_url();
		if ( '' !== $relay ) {
			$route['relay_url'] = $relay;
		}
		if ( '' !== $cur['route_id'] ) {
			$route['route_id'] = $cur['route_id'];
		}
		$r = BizCity_FB_Hub_Client::config_put( array( 'contract' => 'channel-config@1.0.0', 'route' => $route ) );
		if ( ! $r['ok'] || empty( $r['data']['route_id'] ) ) {
			return array( 'ok' => false, 'error' => $r['error'] ?? array( 'code' => 'hub_unreachable', 'message' => 'Chưa gửi được cấu hình app tới máy chủ.', 'hint' => 'Thử lại sau ít giây.', 'help_code' => 'S109-L10' ) );
		}
		$route_id = (string) $r['data']['route_id'];
		update_option( self::OPT_ROUTE, array( 'route_id' => $route_id, 'verify_token' => $verify ), false );
		return array( 'ok' => true, 'route_id' => $route_id, 'callback_url' => self::callback_url( $route_id ), 'verify_token' => $verify );
	}

	/* ---------------- the client's OLD configuration, reused as it is (quick move to the cell) ---------------- */

	/**
	 * The App Secret the old Facebook OAuth already uses (same lookup order as BizCity_Facebook_OAuth). READ ONLY: nothing here stores it again; the quick move
	 * sends it to the cell once so the owner does not type it a second time.
	 */
	public static function legacy_app_secret(): string {
		return (string) self::seam( 'legacy_secret', static function () {
			foreach ( array( 'bztfb_app_secret', 'fb_app_secret' ) as $opt ) {
				$v = (string) get_option( $opt, '' );
				if ( '' !== $v ) {
					return $v;
				}
			}
			return defined( 'FB_APP_SECRET' ) ? (string) FB_APP_SECRET : '';
		} );
	}

	/** The verify token the owner already put into Meta for the old webhook (site token, then the network one) */
	public static function legacy_verify_token(): string {
		return (string) self::seam( 'legacy_verify', static function () {
			$v = trim( (string) get_option( 'bztfb_verify_token', '' ) );
			return '' !== $v ? $v : trim( (string) get_site_option( 'bizcity_fb_verify_token', '' ) );
		} );
	}

	/**
	 * The old bot's own switches for a page, kept as they are: a page whose bot is off, or whose "AI trả lời" is off, must NOT start answering just because it moved
	 * to the cell. Unknown (no old bot table on this site) = on.
	 *
	 * @return array {known: bool, ai_enabled: bool}
	 */
	public static function legacy_flags( string $page ): array {
		return (array) self::seam( 'legacy_flags', static function ( $page ) {
			if ( ! class_exists( 'BizCity_Facebook_Bot_Database', false ) || ! method_exists( 'BizCity_Facebook_Bot_Database', 'get_bot_by_page_id' ) ) {
				return array( 'known' => false, 'ai_enabled' => true );
			}
			$bot = BizCity_Facebook_Bot_Database::instance()->get_bot_by_page_id( $page ); // only an ACTIVE bot row comes back
			return array( 'known' => true, 'has_bot' => is_object( $bot ), 'ai_enabled' => is_object( $bot ) && ! empty( $bot->ai_enabled ) );
		}, array( $page ) );
	}

	/**
	 * Quick move of ONE page to the cell with the client's existing configuration: its page token, its Guru binding, its bot switches, its own Meta app
	 * (App Secret + verify token already on the site). The owner changes ONE thing in Meta - the Callback URL. Nothing old is deleted or edited: the way back
	 * is "Bot cũ" + the old Callback URL.
	 *
	 * Arming the cell early is safe: while Meta still calls the SITE the cell receives nothing and the old bot keeps answering; the moment the Callback URL is
	 * changed the cell takes over (and the screen says "chờ đổi Callback URL" until Facebook has really reached it).
	 *
	 * @return array {ok, callback_url?, verify_token?, verify_token_changed?, ai_enabled?, error?}
	 */
	public static function migrate( string $page ): array {
		$err = static function ( string $code, string $message, string $hint, string $help ): array {
			return array( 'ok' => false, 'error' => array( 'code' => $code, 'message' => $message, 'hint' => $hint, 'help_code' => $help ) );
		};
		if ( self::guru_of( $page ) <= 0 ) {
			return $err( 'no_guru', 'Page chưa có Agent Guru.', 'Chọn Agent Guru cho page rồi chuyển.', 'S90-F1-GURU' );
		}
		$secret = self::legacy_app_secret();
		if ( '' === $secret ) {
			return $err( 'no_own_app', 'Site chưa lưu App Secret của app Meta.', 'Chọn “App của bạn” rồi dán App Secret một lần (Meta for Developers › App settings › Basic).', 'S109-L15-SECRET' );
		}
		// the old default token ("bizgpt") is short and guessable and the cell refuses it: make a fresh one and tell the owner to paste it with the URL
		$old   = self::legacy_verify_token();
		$keep  = strlen( $old ) >= 8 && 'bizgpt' !== $old;
		$route = self::push_route( $secret, $keep ? $old : '' );
		if ( ! $route['ok'] ) {
			return array( 'ok' => false, 'error' => $route['error'] );
		}
		self::save( $page, array( 'source' => 'own', 'route_id' => $route['route_id'], 'verify_token' => $route['verify_token'], 'path' => self::PATH_CELL, 'ingress' => self::INGRESS_FORWARD ) );
		$push = self::push_page( $page );
		if ( ! $push['ok'] ) {
			self::save( $page, array( 'path' => self::PATH_LEGACY ) ); // never leave a page "answered by the cell" that the cell does not know
			return array( 'ok' => false, 'error' => $push['error'] );
		}
		return array(
			'ok'                  => true,
			'callback_url'        => $route['callback_url'],
			'verify_token'        => $route['verify_token'],
			'verify_token_changed' => ! $keep,
			'ai_enabled'          => (bool) self::legacy_flags( $page )['ai_enabled'],
			// the owner changes NOTHING in Meta: the site hands the events to the cell (S109-L17). `callback_url` stays for the optional direct mode.
			'ingress'             => self::INGRESS_FORWARD,
			'meta_change_needed'  => false,
		);
	}

	/**
	 * [2026-10-14 10:23 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.109 S109-L19 — THE SHELL: `?fbhook=1` of an existing client stays exactly as it is, and the first signed Messenger message of a page connects it to the cell BY ITSELF.
	 * Nothing is typed, nothing is pressed, nothing changes in Meta: everything the cell needs is already on the site (page token, the Guru binding, the bot's switches, and the App
	 * Secret that verified THIS very request).
	 *
	 * Adopts a page only when ALL hold: the switch is on (option `bizcity_fb_cell_autoadopt` ≠ 'off', filter `bizcity_fb_cell_autoadopt`), the Hub key is ready, the old bot is active
	 * with "AI trả lời" ON, the request is signed by a secret the site knows, the owner has not chosen "Bot cũ" for the page, and a failed attempt is not repeated for 10 minutes.
	 * Any failure leaves the page exactly as it was (the old handler runs), so adoption can only ever ADD an answerer, never silence one.
	 */
	public static function auto_adopt( string $raw, string $header, array $data, string $verified_secret = '' ): array {
		// Why each page was (not) adopted: logged by the webhook handler so "chưa trả lời" is never a mystery. Keys: '*' = whole request, else page id.
		$why = array();
		if ( ! self::auto_enabled() ) {
			return array( '*' => 'switch_off' );
		}
		if ( empty( $data['entry'] ) || ! is_array( $data['entry'] ) ) {
			return array();
		}
		// the central door verifies on the hub blog with a network secret; a sub-blog may not store it, so the door passes the one that verified (re-checked here, never trusted blindly)
		$secret = '';
		if ( '' !== $verified_secret && '' !== trim( $header ) && hash_equals( 'sha256=' . hash_hmac( 'sha256', $raw, $verified_secret ), trim( $header ) ) ) {
			$secret = $verified_secret;
		}
		if ( '' === $secret ) {
			$secret = class_exists( 'BizCity_FB_Webhook_Signature', false ) ? BizCity_FB_Webhook_Signature::matching_secret( $raw, $header ) : '';
		}
		if ( '' === $secret || strlen( $secret ) < 8 ) {
			return array( '*' => 'no_known_secret_matches_signature' );
		}
		if ( ! BizCity_FB_Hub_Client::is_ready() ) {
			return array( '*' => 'hub_key_not_ready' );
		}
		foreach ( $data['entry'] as $entry ) {
			$page = (string) ( $entry['id'] ?? '' );
			if ( '' === $page || empty( $entry['messaging'] ) || ! is_array( $entry['messaging'] ) || ! preg_match( '/^[0-9]{5,30}$/', $page ) ) {
				continue;
			}
			$why[ $page ] = self::adopt_page_if_eligible( $page, $secret );
		}
		return $why;
	}

	/**
	 * [2026-10-15 12:24 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.109 S109-L26 — the gates of ONE page, shared by the webhook (first signed message) and the owner's own request (cron / admin / OAuth).
	 * @return string the reason code: already_cell | owner_chose_old_bot | no_guru[:diag] | ai_reply_off_in_old_bot | backoff_10min | adopted | adopted_with_default_guru | adopt_failed:<why>
	 */
	private static function adopt_page_if_eligible( string $page, string $secret, bool $just_connected = false ): string {
		$s = self::state( $page );
		if ( self::PATH_CELL === $s['path'] ) {
			return 'already_cell';
		}
		if ( $s['opt_out'] ) {
			return 'owner_chose_old_bot';
		}
		$defaulted = self::guru_of( $page ) <= 0;
		// ${ST}: a page the owner just connected may get the blog's default Guru CREATED ("Trợ lý mặc định") - nobody should have to open Bot Studio first
		if ( $defaulted && self::default_guru( $just_connected ) <= 0 ) {
			// no Guru for the page AND no default Guru in THIS blog: the owner picks one (a Guru is never borrowed from another blog).
			// The suffix says WHY the blog saw none: the binding table is absent in this blog, or the DB router refused the read (a tenant whose tables live elsewhere)
			return 'no_guru' . self::binding_diag();
		}
		$flags = self::legacy_flags( $page );
		// ${ST}: a page just connected through Facebook login is meant to answer: with no bot row there is no old switch to respect (admin-flow pages), and an unticked
		// "AI trả lời" on a freshly (re)connected page is switched on (the way back is the "Bot cũ" choice, which `opt_out` keeps)
		$no_row = $just_connected && array_key_exists( 'has_bot', $flags ) && false === $flags['has_bot'];
		if ( ! $flags['ai_enabled'] && ! $no_row ) {
			if ( ! ( $just_connected && self::enable_ai( $page ) ) ) {
				return 'ai_reply_off_in_old_bot'; // the old config says this page does not answer with AI: do not start now
			}
		}
		$lock = 'bizcity_fb_adopt_' . md5( $page );
		if ( false !== get_transient( $lock ) ) {
			return 'backoff_10min';
		}
		set_transient( $lock, 1, 10 * MINUTE_IN_SECONDS );
		if ( self::adopt( $page, $secret, $just_connected ) ) {
			delete_transient( $lock );
			return $defaulted ? 'adopted_with_default_guru' : 'adopted';
		}
		return 'adopt_failed:' . substr( (string) self::state( $page )['last_error'], 0, 120 );
	}

	/**
	 * [2026-10-15 12:24 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.109 S109-L26 (doc 66 / owner: "đưa thẳng định tuyến về cell, đỡ lỗi multishard router") — adopt the pages of THIS blog from this blog's OWN request:
	 * cron, an admin screen, or right after Facebook OAuth. Nothing here runs inside a webhook that was switched into another blog, so the DB router is never asked to answer
	 * for a blog it was not routed to. The App Secret comes from this blog's own options; when the blog knows more than one, none is guessed (the first signed webhook
	 * carries the exact one). A page the hub routes to ANOTHER blog is never adopted from here.
	 * @return array<string,string> '*' or page id => reason code
	 */
	public static function run_self_adopt( string $trigger = 'cron', array $just_connected = array() ): array {
		if ( ! self::auto_enabled() ) {
			return array( '*' => 'switch_off' );
		}
		if ( ! BizCity_FB_Hub_Client::is_ready() ) {
			return array( '*' => 'hub_key_not_ready' );
		}
		$just    = array_values( array_filter( array_map( 'strval', $just_connected ), static function ( $p ) { return (bool) preg_match( '/^[0-9]{5,30}$/', $p ); } ) );
		$secrets = class_exists( 'BizCity_FB_Webhook_Signature', false ) ? array_values( BizCity_FB_Webhook_Signature::secrets() ) : array();
		$single  = 1 === count( $secrets ) && strlen( (string) $secrets[0] ) >= 8 ? (string) $secrets[0] : '';
		if ( '' === $single && ! $just ) {
			return array( '*' => 0 === count( $secrets ) ? 'no_app_secret_known' : 'ambiguous_app_secret' );
		}
		$out = array();
		foreach ( array_values( array_unique( array_merge( self::own_page_ids(), $just ) ) ) as $page ) {
			$route = self::route_owner( $page );
			if ( null !== $route && ! $route['here'] ) {
				$out[ $page ] = 'routed_to_other_blog';
				continue;
			}
			$is_new = in_array( $page, $just, true );
			// ${ST}: the app that issued the page token (stored with the bot when the owner logged in) is the app Facebook signs this page's events with
			$secret = $is_new && strlen( self::bot_app_secret( $page ) ) >= 8 ? self::bot_app_secret( $page ) : $single;
			if ( '' === $secret ) {
				$out[ $page ] = 'ambiguous_app_secret';
				continue;
			}
			$out[ $page ] = self::adopt_page_if_eligible( $page, $secret, $is_new );
			// the blog's owner reads WHY a page is still on the old bot from the PHP error log: every adoption outcome, and a blocker (no_guru…, ai off) at most once per 6 h per page
			$loud = 0 === strpos( $out[ $page ], 'adopt' ) || ( ! in_array( $out[ $page ], array( 'already_cell', 'routed_to_other_blog', 'backoff_10min' ), true ) && function_exists( 'get_transient' ) && false === get_transient( 'bizcity_fb_selfadopt_note_' . md5( $page . $out[ $page ] ) ) && set_transient( 'bizcity_fb_selfadopt_note_' . md5( $page . $out[ $page ] ), 1, 6 * 3600 ) );
			if ( $loud && function_exists( 'error_log' ) ) {
				error_log( sprintf( '[BizCity FB self-adopt] trigger=%s blog=%d page=%s why=%s', $trigger, function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0, $page, $out[ $page ] ) );
			}
		}
		return $out;
	}

	/** [2026-10-15 01:00 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.109 S109-L31 — the App Secret stored with this page's bot row when the owner logged in ('' = none) */
	public static function bot_app_secret( string $page ): string {
		return (string) self::seam( 'bot_app_secret', static function ( $page ) {
			if ( ! class_exists( 'BizCity_Facebook_Bot_Database', false ) || ! method_exists( 'BizCity_Facebook_Bot_Database', 'get_bot_by_page_id' ) ) {
				return '';
			}
			$bot = BizCity_Facebook_Bot_Database::instance()->get_bot_by_page_id( $page );
			return is_object( $bot ) ? (string) ( $bot->app_secret ?? '' ) : '';
		}, array( $page ) );
	}

	/** [2026-10-15 01:00 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.109 S109-L31 — switch the old bot's "AI trả lời" on for a page the owner just connected. @return bool it is on now */
	private static function enable_ai( string $page ): bool {
		return (bool) self::seam( 'enable_ai', static function ( $page ) {
			if ( ! class_exists( 'BizCity_Facebook_Bot_Database', false ) || ! method_exists( 'BizCity_Facebook_Bot_Database', 'get_bot_by_page_id' ) ) {
				return false;
			}
			$db  = BizCity_Facebook_Bot_Database::instance();
			$bot = $db->get_bot_by_page_id( $page );
			if ( ! is_object( $bot ) || empty( $bot->id ) ) {
				return false;
			}
			$db->update_bot( (int) $bot->id, array( 'ai_enabled' => 1 ) );
			return true;
		}, array( $page ) );
	}

	/** @return string[] page ids connected on THIS blog (the old bot's table) */
	public static function own_page_ids(): array {
		return (array) self::seam( 'own_pages', static function () {
			if ( ! class_exists( 'BizCity_Facebook_Bot_Database' ) ) {
				return array();
			}
			$ids = array();
			foreach ( (array) BizCity_Facebook_Bot_Database::instance()->get_connected_pages() as $p ) {
				if ( ! empty( $p->page_id ) && preg_match( '/^[0-9]{5,30}$/', (string) $p->page_id ) ) {
					$ids[] = (string) $p->page_id;
				}
			}
			return array_values( array_unique( $ids ) );
		} );
	}

	/** [2026-10-15 Johnny Chu - Chu Hoàng Anh] PHASE-0.109 S109-L29 — the blog id the network-global route table gives this page, 0 when none / not multisite (no blog-relative flag: callable from anywhere) */
	public static function route_owner_blog( string $page ): int {
		$own = self::route_owner( $page );
		return null === $own ? 0 : (int) $own['blog'];
	}

	/** [2026-10-15 Johnny Chu - Chu Hoàng Anh] PHASE-0.109 S109-L29 — Facebook OAuth just finished for this blog (flag written by the callback while it was switched in): adopt the blog's pages from THIS blog's own request, once */
	public static function maybe_run_pending(): void {
		if ( ! function_exists( 'get_option' ) || ! get_option( 'bizcity_fb_adopt_pending', 0 ) ) {
			return;
		}
		$pending = get_option( 'bizcity_fb_adopt_pending', 0 );
		delete_option( 'bizcity_fb_adopt_pending' );
		self::run_self_adopt( 'oauth_landing', is_array( $pending ) ? $pending : array() ); // the page ids of THIS login
	}

	/** An admin of this blog opened a screen: at most once per 10 minutes, retry the pages that are not on the cell yet */
	public static function maybe_self_adopt_admin(): void {
		if ( ! function_exists( 'get_transient' ) || false !== get_transient( 'bizcity_fb_self_adopt_admin' ) ) {
			return;
		}
		set_transient( 'bizcity_fb_self_adopt_admin', 1, 10 * MINUTE_IN_SECONDS );
		self::run_self_adopt( 'admin' );
	}

	public static function schedule_self_adopt(): void {
		if ( function_exists( 'wp_next_scheduled' ) && ! wp_next_scheduled( 'bizcity_fb_self_adopt' ) ) {
			wp_schedule_event( time() + 120, 'hourly', 'bizcity_fb_self_adopt' );
		}
	}

	/**
	 * [2026-10-15 12:24 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.109 S109-L25 — on a multisite/multishard network a hand-off runs SWITCHED into the owning blog; a read of that blog's `bizcity_channel_bindings`
	 * can come back empty because the table does not exist there (the Channel Gateway never ran its installer on that blog) or because the DB router refused the read.
	 * Both look like "the owner picked no Guru". Returns '' (table readable), ':binding_table_missing', ':db_error:<text>' or ':diag_unavailable'.
	 */
	public static function binding_diag(): string {
		return (string) self::seam( 'binding_diag', static function () {
			global $wpdb;
			if ( ! class_exists( 'BizCity_Channel_Binding' ) || ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_var' ) ) {
				return '';
			}
			try {
				$table = BizCity_Channel_Binding::table();
				$prev  = $wpdb->suppress_errors( true );
				$has   = $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s LIMIT 1', $table ) );
				$err   = (string) $wpdb->last_error;
				$wpdb->suppress_errors( $prev );
				if ( '' !== $err ) {
					return ':db_error:' . substr( $err, 0, 120 );
				}
				return empty( $has ) ? ':binding_table_missing' : '';
			} catch ( Throwable $e ) {
				return ':diag_unavailable';
			}
		} );
	}

	public static function auto_enabled(): bool {
		if ( 'off' === (string) get_option( self::OPT_AUTO, 'on' ) ) {
			return false;
		}
		return ! function_exists( 'apply_filters' ) || (bool) apply_filters( 'bizcity_fb_cell_autoadopt', true );
	}

	/**
	 * [2026-10-15 09:59 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.109 S109-L23 (doc 66 Q1-B) — the default Guru of THIS blog (R-SETUP-4 v1.2 "default Guru"): lets a page that nobody mapped to a Guru be adopted with zero clicks.
	 * Always the blog that owns the page (we run switched into it) - never another blog's Guru (R-GURU-PRIVATE, R-B2B2C). 0 = none.
	 */
	public static function default_guru( bool $create = false ): int {
		return (int) self::seam( 'default_guru', static function ( $create ) {
			return class_exists( 'BizCity_Guru_Context_Resolver' ) ? (int) BizCity_Guru_Context_Resolver::default_character_id( (bool) $create ) : 0;
		}, array( $create ) );
	}

	/**
	 * [2026-10-15 10:24 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.109 S109-L24 — which blog of the network owns this page for the Facebook hub (global route table). On a multisite hub the page may be routed to a blog
	 * other than the one the owner is working in: the Guru they picked lives in THEIR blog, the cell asks the ROUTE blog (doc 66 G6). null = not multisite / no route.
	 * @return array{blog:int,here:bool,site:string}|null
	 */
	public static function route_owner( string $page ): ?array {
		return self::seam( 'route_owner', static function ( $page ) {
			if ( ! function_exists( 'is_multisite' ) || ! is_multisite() ) {
				return null;
			}
			global $wpdb;
			$prev = $wpdb->suppress_errors( true );
			$blog = (int) $wpdb->get_var( $wpdb->prepare( "SELECT blog_id FROM {$wpdb->base_prefix}bizcity_facebook_page_routes WHERE page_id = %s LIMIT 1", $page ) );
			$wpdb->suppress_errors( $prev );
			return $blog > 0 ? array( 'blog' => $blog, 'here' => $blog === (int) get_current_blog_id(), 'site' => (string) get_site_url( $blog ) ) : null;
		}, array( $page ) );
	}

	/** [2026-10-15 Johnny Chu - Chu Hoàng Anh] PHASE-0.109 S109-L27 — this site's own webhook (the old handler reads comments), or '' when it is not a public https URL */
	public static function relay_url(): string {
		$url = (string) self::seam( 'relay_url', static function () {
			$u = function_exists( 'home_url' ) ? home_url( '/?fbhook=1' ) : '';
			return function_exists( 'apply_filters' ) ? (string) apply_filters( 'bizcity_fb_relay_url', $u ) : $u;
		} );
		$host = (string) parse_url( $url, PHP_URL_HOST );
		if ( 0 !== strpos( $url, 'https://' ) || '' === $host || 'localhost' === $host || preg_match( '/^(127.|10.|192.168.|169.254.)|.(local|internal)$/', $host ) ) {
			return '';
		}
		return strlen( $url ) <= 300 ? $url : '';
	}

	/** The person in charge for a page adopted without anybody pressing a button: the site's web owner, else its first administrator */
	private static function default_owner(): int {
		return (int) self::seam( 'default_owner', static function () {
			$o = class_exists( 'BizCity_Twin_Web_Home', false ) ? (int) BizCity_Twin_Web_Home::owner_user_id() : 0;
			if ( $o > 0 ) {
				return $o;
			}
			$ids = function_exists( 'get_users' ) ? get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID', 'orderby' => 'ID', 'order' => 'ASC' ) ) : array();
			return (int) ( $ids[0] ?? 0 );
		} );
	}

	/** One page: register (if never chosen) -> route with the request's own secret -> state -> push. Short Hub timeout; reverts on any failure. */
	private static function adopt( string $page, string $secret, bool $just_connected = false ): bool {
		$old = BizCity_FB_Hub_Client::$timeout;
		BizCity_FB_Hub_Client::$timeout = 8; // Meta waits ~20 s for the whole webhook answer
		try {
			$owner = self::default_owner();
			if ( $owner <= 0 || ( class_exists( 'BizCity_FB_Setup_REST', false ) && null !== BizCity_FB_Setup_REST::ensure_page_chosen( $page, $owner ) ) ) {
				return false;
			}
			// no Guru mapped to the page: bind this blog's default one (what the owner would otherwise have to pick by hand)
			if ( self::guru_of( $page ) <= 0 ) {
				$cid = self::default_guru( $just_connected );
				$r   = class_exists( 'BizCity_FB_Setup_REST', false ) && $cid > 0 ? BizCity_FB_Setup_REST::post_guru( new WP_REST_Request( 'POST', '/x', array( 'page_id' => $page, 'character_id' => $cid ) ) ) : null;
				if ( null === $r || is_wp_error( $r ) || self::guru_of( $page ) <= 0 ) {
					return false;
				}
			}
			$legacy = self::legacy_verify_token();
			$keep   = strlen( $legacy ) >= 8 && 'bizgpt' !== $legacy;
			$route  = self::push_route( $secret, $keep ? $legacy : '' );
			if ( ! $route['ok'] ) {
				return false;
			}
			self::save( $page, array( 'source' => 'own', 'route_id' => $route['route_id'], 'verify_token' => $route['verify_token'], 'path' => self::PATH_CELL, 'ingress' => self::INGRESS_FORWARD, 'opt_out' => false, 'adopted_at' => self::now() ) );
			if ( ! self::push_page( $page )['ok'] ) {
				self::save( $page, array( 'path' => self::PATH_LEGACY ) );
				return false;
			}
			return true;
		} catch ( \Throwable $e ) {
			self::save( $page, array( 'path' => self::PATH_LEGACY ) );
			return false;
		} finally {
			BizCity_FB_Hub_Client::$timeout = $old;
		}
	}

	/**
	 * [2026-10-14 10:23 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.109 S109-L17 — hand Meta's Messenger events to the cell WITHOUT changing anything in Meta.
	 *
	 * Meta still calls the site's webhook (the Callback URL + verify token the client configured long ago). After the site has verified the signature, the RAW body and the
	 * original `X-Hub-Signature-256` are POSTed unchanged to the cell's public gateway; the cell verifies the very same HMAC (it holds the same App Secret) and answers 2xx in
	 * well under a second. Only pages the cell answers (path cell_direct, ingress site_forward) are covered; everything else in the payload (comments / `feed`, pages still on
	 * the old bot) stays with the old handler. A failed forward covers NOTHING: the old handler runs exactly as before, so a cell outage never silences a page more than it was.
	 *
	 * @param string $raw    the request body as Meta sent it
	 * @param string $header the X-Hub-Signature-256 header as Meta sent it
	 * @param array  $data   json_decode( $raw )
	 * @return array<string,true> page ids whose Messenger events the cell now owns (the old handler must skip them)
	 */
	public static function forward( string $raw, string $header, array $data ): array {
		if ( '' === $header || empty( $data['entry'] ) || ! is_array( $data['entry'] ) ) {
			return array();
		}
		$wanted = array();
		foreach ( $data['entry'] as $entry ) {
			$page = (string) ( $entry['id'] ?? '' );
			if ( '' !== $page && ! empty( $entry['messaging'] ) && is_array( $entry['messaging'] ) && self::is_cell_direct( $page ) ) {
				$s = self::state( $page );
				// [2026-10-15 Johnny Chu - Chu Hoàng Anh] PHASE-0.109 S109-L33 — owner: "app client điền như nào thì giữ nguyên, sao phải đổi?". Whatever the stored source / route says, a request that REACHED this site for a cell page and is signed
				// with an App Secret this site knows is enough to wire the page: the shell repairs its own route (the same thing adoption does) and forwards. Nobody re-types a secret, nothing changes in Meta.
				if ( ! ( 'own' === $s['source'] && '' !== $s['route_id'] ) && self::heal_route( $page, $raw, $header ) ) {
					$s = self::state( $page );
				}
				// [2026-10-15 Johnny Chu - Chu Hoàng Anh] PHASE-0.109 S109-L32: a request that REACHED this site for a cell page is forwarded whenever the page has its own route - also when the ingress says `meta_direct`. In prod (bonbon, 2026-10-15)
				// the page was switched to the dev app while the app's Callback URL still pointed at the site: forwarding stopped ("Meta calls the cell itself") and nobody answered for hours.
				// A second copy is harmless: the cell claims every message once by page + mid (duplicate), and a signature of another app is refused (403) without touching the old handler.
				if ( 'own' === $s['source'] && '' !== $s['route_id'] ) {
					$wanted[ $page ] = $s['route_id'];
				}
			}
		}
		if ( ! $wanted ) {
			return array();
		}
		$route = (string) reset( $wanted );
		$ok    = (bool) self::seam( 'forward_http', static function ( $url, $raw, $header ) {
			$r = wp_remote_post( $url, array(
				'timeout'     => self::FORWARD_TIMEOUT,
				'redirection' => 0,
				'headers'     => array( 'Content-Type' => 'application/json', 'X-Hub-Signature-256' => $header ),
				'body'        => $raw,
			) );
			$code = is_wp_error( $r ) ? 0 : (int) wp_remote_retrieve_response_code( $r );
			return $code >= 200 && $code < 300;
		}, array( self::callback_url( $route ), $raw, $header ) );
		if ( ! $ok ) {
			return array();
		}
		$covered = array();
		foreach ( $wanted as $page => $r ) {
			if ( $r === $route ) {
				$covered[ $page ] = true; // one POST carries the whole payload; the cell takes the pages bound to this route
			}
		}
		return $covered;
	}

	/**
	 * [2026-10-15 Johnny Chu - Chu Hoàng Anh] PHASE-0.109 S109-L33 — give a cell page its OWN route from the secret that verified THIS request (state: source own + route_id + site_forward), then tell the cell the page's connection so
	 * the cell binds the page to that route. Short Hub timeout (Meta waits ~20 s); a failed attempt is not repeated for 10 minutes. Never touches the page's path (cell stays cell).
	 */
	private static function heal_route( string $page, string $raw, string $header ): bool {
		$secret = class_exists( 'BizCity_FB_Webhook_Signature', false ) ? (string) BizCity_FB_Webhook_Signature::matching_secret( $raw, $header ) : '';
		if ( '' === $secret || strlen( $secret ) < 8 ) {
			return false;
		}
		$key = 'bizcity_fb_heal_' . md5( $page );
		if ( function_exists( 'get_transient' ) && get_transient( $key ) ) {
			return false;
		}
		$old = BizCity_FB_Hub_Client::$timeout;
		BizCity_FB_Hub_Client::$timeout = 8;
		$ok = false;
		try {
			$legacy = self::legacy_verify_token();
			$keep   = strlen( $legacy ) >= 8 && 'bizgpt' !== $legacy;
			$route  = self::push_route( $secret, $keep ? $legacy : '' );
			if ( $route['ok'] ) {
				self::save( $page, array( 'source' => 'own', 'route_id' => $route['route_id'], 'verify_token' => $route['verify_token'], 'ingress' => self::INGRESS_FORWARD ) );
				$ok = (bool) self::push_page( $page )['ok'];
			}
		} catch ( Throwable $e ) {
			$ok = false;
		} finally {
			BizCity_FB_Hub_Client::$timeout = $old;
		}
		if ( ! $ok && function_exists( 'set_transient' ) ) {
			set_transient( $key, 1, 600 );
		}
		return $ok;
	}

	/** The `connections[]` entry of one page, exactly what the cell stores */
	public static function connection( string $page ): array {
		$s = self::state( $page );
		return array(
			'platform'   => 'messenger',
			'channel_ref' => $page,
			'route_id'   => 'own' === $s['source'] && '' !== $s['route_id'] ? $s['route_id'] : 'shared',
			'enabled'    => self::PATH_CELL === $s['path'] && (bool) self::legacy_flags( $page )['ai_enabled'],
			'guru_id'    => max( 0, self::guru_of( $page ) ),
			'tools_off'  => $s['tools_off'],
		);
	}

	/**
	 * Tell the cell the current state of ONE page (path / Guru / tools / token). The page token rides along every push (the cell keeps it
	 * encrypted, so a rotated token needs no extra step); an empty token is simply not sent (the cell keeps what it has).
	 *
	 * @return array {ok, error?}
	 */
	public static function push_page( string $page ): array {
		// the web channel registration is what lets the cell send its CRM records back to this site (Hub relay needs the callback)
		if ( (bool) self::seam( 'web_pushed', static function () {
			return class_exists( 'BizCity_Twin_Web_Home', false ) ? (bool) BizCity_Twin_Web_Home::ensure_pushed( true ) : true;
		} ) === false ) {
			return self::failed( $page, array( 'code' => 'web_channel_not_registered', 'message' => 'Site chưa đăng ký kênh web với máy chủ.', 'hint' => 'Mở trang chat web của chủ một lần rồi thử lại.', 'help_code' => 'S109-L10-WEB' ) );
		}
		$conn  = self::connection( $page );
		$token = self::token_of( $page );
		if ( '' !== $token ) {
			$conn['page_token'] = $token;
		} elseif ( $conn['enabled'] ) {
			return self::failed( $page, array( 'code' => 'no_page_token', 'message' => 'Chưa có token của Fanpage này.', 'hint' => 'Đăng nhập lại Facebook để cấp token cho page.', 'help_code' => 'S109-L10-TOKEN' ) );
		}
		$r = BizCity_FB_Hub_Client::config_put( array( 'contract' => 'channel-config@1.0.0', 'connections' => array( $conn ) ) );
		if ( ! $r['ok'] ) {
			return self::failed( $page, $r['error'] );
		}
		unset( $conn['page_token'] );
		self::save( $page, array( 'pushed_at' => self::now(), 'pushed_hash' => hash( 'sha256', wp_json_encode( $conn ) ), 'last_error' => '' ) );
		delete_transient( 'bizcity_fb_cell_pf_' . md5( $page ) );
		return array( 'ok' => true );
	}

	private static function failed( string $page, array $error ): array {
		self::save( $page, array( 'last_error' => (string) ( $error['code'] ?? 'failed' ) ) );
		return array( 'ok' => false, 'error' => $error );
	}

	/** Re-push a page that already answers through the cell (Guru, token or tools changed). No-op for a page on the old bot. */
	public static function push_if_active( string $page ): void {
		if ( self::is_cell_direct( $page ) ) {
			self::push_page( $page );
		}
	}

	/**
	 * [2026-10-14 09:08 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.109 S109-L16 — OAuth just saved new page tokens (action `bizcity_fb_pages_saved`): push every page the cell answers so
	 * it never keeps answering with a token Facebook has already replaced. Pages on the old bot are not touched. Best effort: a failed push shows as `cell_error` on the card.
	 */
	public static function on_pages_saved( $page_ids ): void {
		foreach ( (array) $page_ids as $page ) {
			$page = (string) $page;
			if ( '' !== $page && self::is_cell_direct( $page ) ) {
				self::push_page( $page );
			}
		}
	}

	/** Disconnect: the cell forgets the page and its secrets */
	public static function remove_page( string $page ): void {
		BizCity_FB_Hub_Client::config_put( array( 'contract' => 'channel-config@1.0.0', 'remove' => array( array( 'platform' => 'messenger', 'channel_ref' => $page ) ) ) );
		self::forget( $page );
	}

	/* ---------------- reads for the setup screen ---------------- */

	/** The cell's own checks for a page (60 s cache); null when the cell cannot be asked */
	public static function preflight( string $page, bool $fresh = false ): ?array {
		$key = 'bizcity_fb_cell_pf_' . md5( $page );
		if ( ! $fresh ) {
			$hit = get_transient( $key );
			if ( is_array( $hit ) ) {
				return $hit;
			}
		}
		$r = BizCity_FB_Hub_Client::preflight( $page );
		if ( ! $r['ok'] || ! is_array( $r['data']['checks'] ?? null ) ) {
			return null;
		}
		$out = array( 'last_in_at' => $r['data']['last_in_at'] ?? null, 'health' => is_array( $r['data']['health'] ?? null ) ? $r['data']['health'] : null, 'checks' => $r['data']['checks'] );
		set_transient( $key, $out, self::PREFLIGHT_TTL );
		return $out;
	}

	/** Recent turns of a page + its 24 h health (count, failures, p50/p95): the cell's own record, null when the cell cannot be asked */
	public static function trace( string $page, int $limit = 20 ): ?array {
		$r = BizCity_FB_Hub_Client::trace( $page, $limit );
		if ( ! $r['ok'] || ! is_array( $r['data']['rows'] ?? null ) ) {
			return null;
		}
		return array( 'health' => (array) ( $r['data']['health'] ?? array() ), 'rows' => $r['data']['rows'] );
	}

	/** Tools of a page: the cell's list (key, group, on, locked), or null when the cell cannot be asked */
	public static function tools( string $page ): ?array {
		$r = BizCity_FB_Hub_Client::tools( $page );
		if ( ! $r['ok'] || ! is_array( $r['data']['tools'] ?? null ) ) {
			return null;
		}
		return array( 'tools_configured' => (bool) ( $r['data']['tools_configured'] ?? false ), 'tools' => $r['data']['tools'] );
	}
}

if ( function_exists( 'add_action' ) ) {
	add_action( 'bizcity_fb_pages_saved', array( 'BizCity_FB_Cell_Direct', 'on_pages_saved' ) );
	// S109-L26: adoption from the blog's OWN request (never from a webhook switched into another blog)
	add_action( 'bizcity_fb_self_adopt', static function () { BizCity_FB_Cell_Direct::run_self_adopt( 'cron' ); } );
	add_action( 'init', array( 'BizCity_FB_Cell_Direct', 'schedule_self_adopt' ), 30 );
	add_action( 'init', array( 'BizCity_FB_Cell_Direct', 'maybe_run_pending' ), 31 );
	add_action( 'admin_init', array( 'BizCity_FB_Cell_Direct', 'maybe_self_adopt_admin' ), 30 );
}
