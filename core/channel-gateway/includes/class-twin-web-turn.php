<?php
/**
 * Web surfaces of the Twin Agent — TwinChat (PHASE-0.87 CL-6) and Twin GPT `/gpt/` (CL-7) as adapters of the cell's
 * `runAgentTurn` (R-TWIN-AGENT-AXIS R-TAA-1/13, contract twin-agent-turn@1.2.0, fixtures taa/turn.twinchat.json + turn.gpt.json).
 *
 *   A1 intake     the surface hands over {user_id, session_id, text, history (site-owned, oldest first), notebook_id};
 *                 this class builds the envelope — the user is get_current_user_id() of the caller, never a body field.
 *   A2 principal  role + modes from server facts only: the user's principal on a zalo-hub number of this site
 *                 (BizCity_Zalo_Agent_Principals: owner = the number's owner_user_id with "UID chủ tài khoản" set, staff =
 *                 staff_principals row), modes = agent-mode-access@1, `user_hash` = the value the Zalo bundle carries.
 *                 No principal ⇒ customer, modes [] and no hash (no owner tools / packs).
 *   A3 delivery   the Hub relay `POST bizcity/v1/zalo-hub/twin-agent/turn` streams the cell's SSE frames; they are passed
 *                 to the browser byte for byte (see "transition bridge" below).
 *   A4 record     the cell writes the twin record (`turn-complete`, CL-5); the site keeps only its web transcript (the
 *                 surface's message store) from the `final_done` frame. No owner-capture from the web.
 *
 * Cut-over flag per surface (R-TAA §8 step 4), option `bizcity_twin_web_turn_path_<surface>` = auto|node|php, default auto:
 *   php  ⇒ the PHP pipeline answers, unchanged — since CELL-FIRST E-3 (2026-10-08) only while the emergency constant
 *          BIZCITY_TWIN_WEB_TURN_FORCE_PHP is true in wp-config; without it a stored php reads as auto. No number / no key /
 *          no Hub route are guided errors in auto as well (L1), no longer a silent PHP answer;
 *   node ⇒ always this path (errors are shown, no fallback);
 *   auto ⇒ this path when the site has a zalo-hub number, knows its key_id and the Hub has the route; an old Hub
 *          (404 rest_no_route) is remembered for 10 minutes; a Hub/cell that cannot take the turn before the first
 *          frame (unreachable, 5xx, number not on the Hub, old cell) falls back to php for that turn. Staff go out as role
 *          `staff` (twin-agent-turn@1.3, fixture taa/turn.twinchat.staff.json — the cell accepts it since 2026-10-01); an
 *          older cell that rejects the 1.3 envelope (400) sends that staff turn back to php in auto. Way back: filter
 *          `bizcity_twin_web_turn_staff_role` ⇒ false (staff go out as customer, and auto keeps them on php).
 *
 * Which number answers (`account_id`): the first zalo-hub number where the user is the active owner principal, else the
 * first where they are an active staff principal (with ≥ 1 mode), else the site's default number (option
 * `bizcity_twin_web_turn_account` when it is a zalo-hub number of this site, else the first zalo-hub number).
 *
 * TRANSITION BRIDGE — remove at NV-3: the site PHP reads the Hub SSE with cURL and writes it to the browser unchanged.
 * Allowed by R-PROVIDER-FLOW §6 (v2.6, owner decision D87-STREAM 2026-10-01: web turns may cross site PHP + Hub as a
 * byte-passthrough bridge until NV-3 is live) — no LLM call, no prompt building, no
 * post-processing of the answer here. The final design is the browser opening `stream_url` on the public stream host
 * with a single-use ticket (PHASE-0.84 RT-N2…N4, NV-1…NV-4).
 *
 * // @axis twin-agent-axis@1 surface twinchat,gpt
 *
 * @package BizCity_Twin_AI
 * @since   PHASE-0.87 CL-6/CL-7 (2026-10-01)
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Twin_Web_Turn', false ) ) {
	return;
}

final class BizCity_Twin_Web_Turn {

	const CONTRACT       = 'twin-agent-turn@1.2.0';
	const EFFORTS        = array( 'fast', 'balanced', 'high', 'deep' ); // UI Nhanh/Vừa/Cao/Sâu — the cell maps + clamps (off…high)
	const CONTRACT_STAFF = 'twin-agent-turn@1.3.0'; // role `staff` exists from 1.3 (owner/customer turns stay 1.2 for older cells)
	// [2026-10-03 Claude Opus 5.5] PHASE-0.90 S90-W2 (R-CONTACT-ID): `contact` + optional account_id — a site with no Zalo number answers through its web home.
	const CONTRACT_CONTACT = 'twin-agent-turn@1.4.0';
	// [2026-10-12 10:05 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.105 S105-C2 — Cell Chat (mirror the owner's Zalo 1-1 thread from the web): twin-agent-turn@1.5 = 1.4 + `mirror` + `mirror_opts`.
	const CONTRACT_MIRROR   = 'twin-agent-turn@1.5.0';
	const CELL_CHAT_PATH    = '/zalo-hub/cell-chat';
	const CELL_CHAT_CAPS_KEY = 'bizcity_cell_chat_caps';
	const CELL_CHAT_CAPS_TTL = 60;
	const AXIS           = 'twin-agent-axis@1';
	const SURFACES       = array( 'twinchat', 'gpt' );
	const FLAGS          = array( 'auto', 'node', 'php' );
	const FLAG_DEFAULT   = 'auto';
	// [2026-10-03 Claude Opus 5.5] PHASE-0.89 S89-B7/B5 — Ask Brain (`brain`) answers through the cell by default now that the
	// timeline (S89-S3/S4) and the focused notebook (S89-B5) work there. Way back: `wp option update bizcity_twin_web_turn_path_brain php`.
	// Needs the cell with S89-B5 first (deploy order cell → Hub → site); an older cell ignores the focused notebook.
	const FLAG_DEFAULTS  = array();
	// [2026-10-03 Claude Opus 5.5] PHASE-0.89 S89-B1 — vertical brain modes (law, tax, woo_bizops…) go to the cell only when this option is 'on'.
	// Default off: a cell older than brain-vertical@1 would IGNORE the field and answer a law question as plain chat (no allowlist,
	// no disclaimer). Turn it on after the cell with brain-vertical@1 is deployed: `wp option update bizcity_twin_web_turn_verticals on`.
	const VERTICALS_OPTION = 'bizcity_twin_web_turn_verticals';
	const OPTION_PREFIX  = 'bizcity_twin_web_turn_path_';
	const OPTION_ACCOUNT = 'bizcity_twin_web_turn_account';
	const FILTER_STAFF   = 'bizcity_twin_web_turn_staff_role';
	const HUB_PATH       = '/zalo-hub/twin-agent/turn';
	const NO_ROUTE_KEY   = 'bizcity_twin_web_turn_no_route';
	const NO_ROUTE_TTL   = 600;
	const KEY_ID_KEY     = 'bizcity_twin_web_turn_key_id';
	const KEY_ID_TTL     = 43200;
	const HISTORY_MAX    = 20;
	const TEXT_MAX       = 8000;
	const PARTS_MAX      = 20; // cell envelope: ≤ 20 parts (1 text + ≤ 19 images)
	const TIMEOUT        = 310; // Hub relay allows 300 s per turn
	const ERR_BODY_MAX   = 65536;

	/** Vietnamese R-ERROR-UX texts of the site side: code ⇒ [status, message, hint]. */
	const ERRORS = array(
		// [2026-10-08 Johnny Chu - Chu Hoàng Anh] CELL-FIRST E-3 (L1) — config gaps answer with how to connect; no php hint any more.
		'no_zalo_hub_number' => array( 409, 'Website chưa có số Zalo nào chạy trên Zalo Hub để trả lời.', 'Kết nối một số ở Bot Studio (Zalo Hub) theo 4 bước cài đặt rồi hỏi lại.' ),
		'hub_key_unknown'    => array( 503, 'Chưa xác định được API key của website ở BizCity Hub.', 'Kiểm tra API key BizCity trong phần cài đặt rồi thử lại.' ),
		'hub_route_missing'  => array( 501, 'BizCity Hub chưa hỗ trợ trả lời web bằng Agent.', 'Báo quản trị nền tảng cập nhật BizCity Hub rồi thử lại.' ),
		'hub_unreachable'    => array( 502, 'Không kết nối được BizCity Hub để trả lời.', 'Thử lại sau ít giây.' ),
		'session_forbidden'  => array( 403, 'Phiên chat này không thuộc tài khoản của bạn.', 'Mở một cuộc trò chuyện mới rồi hỏi lại.' ),
		'empty_message'      => array( 400, 'Tin nhắn không được để trống.', 'Nhập câu hỏi rồi gửi lại.' ),
		'stream_interrupted' => array( 502, 'Luồng trả lời bị ngắt trước khi hoàn tất.', 'Gửi lại câu hỏi.' ),
		// [2026-10-12 10:05 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.105 S105-C2 — Cell Chat errors (the same codes the cell answers in its `error` frame).
		'not_owner'          => array( 403, 'Bạn không phải chủ hoặc nhân viên của số này.', 'Chọn số bạn là chủ hoặc nhân viên, hoặc nhờ chủ số thêm bạn ở Bot Studio.' ),
		'focus_needs_notebook_mode' => array( 403, 'Tài khoản của bạn chưa được bật quyền "Sổ ghi chú" trên Agent.', 'Nhờ chủ tài khoản bật quyền Sổ ghi chú cho bạn ở Bot Studio, hoặc bỏ chọn sổ rồi hỏi lại.' ),
	);

	/** Emergency constant: only with it does a stored `php` flag still route a surface to the PHP pipeline (E-3 L1-a). */
	const FORCE_PHP_CONST = 'BIZCITY_TWIN_WEB_TURN_FORCE_PHP';

	/**
	 * Test seams: option(name, default) · accounts(): list<{bridge_id}> · principals(bridge_id): list · user_hash(user_id) ·
	 * key_id(): int · target(): ?{url,key,headers} · guru_ref(bridge_id): string · trace_id(): string · now(): int ·
	 * transport(target, body, on_bytes(chunk, status, content_type): bool): {ok, status} · out(bytes) · open() · staff_supported(): bool · force_php(): bool
	 *
	 * @var array<string,callable>
	 */
	public static $readers = array();

	/* ── decision ─────────────────────────────────────────────────── */

	/** Registry ids of brain-vertical@1 (zalo-hub/src/agent/verticals/registry.ts) - the only values a surface may send as `vertical` */
	const VERTICAL_IDS = array( 'quick', 'deep', 'social', 'products', 'woo_bizops', 'astro', 'company', 'law', 'tax', 'gov', 'med', 'scholar', 'nutri' );

	public static function is_vertical( string $id ): bool {
		return in_array( $id, self::VERTICAL_IDS, true );
	}

	public static function verticals_enabled(): bool {
		return 'on' === strtolower( trim( (string) self::option( self::VERTICALS_OPTION, 'off' ) ) );
	}

	/**
	 * auto|node|php for one surface (unknown value ⇒ default). [2026-10-08 Johnny Chu - Chu Hoàng Anh] CELL-FIRST E-3 L1-a:
	 * `php` holds only while the emergency constant BIZCITY_TWIN_WEB_TURN_FORCE_PHP is true (wp-config), else it reads as auto.
	 */
	public static function flag( string $surface ): string {
		$v = strtolower( trim( (string) self::option( self::OPTION_PREFIX . $surface, self::FLAG_DEFAULTS[ $surface ] ?? self::FLAG_DEFAULT ) ) );
		if ( function_exists( 'apply_filters' ) ) {
			$v = (string) apply_filters( 'bizcity_twin_web_turn_path', $v, $surface );
		}
		if ( 'php' === $v && ! self::force_php() ) {
			return self::FLAG_DEFAULT;
		}
		return in_array( $v, self::FLAGS, true ) ? $v : self::FLAG_DEFAULT;
	}

	public static function force_php(): bool {
		if ( isset( self::$readers['force_php'] ) ) {
			return (bool) call_user_func( self::$readers['force_php'] );
		}
		return defined( self::FORCE_PHP_CONST ) && (bool) constant( self::FORCE_PHP_CONST );
	}

	/**
	 * Which path answers this turn. Node ⇒ also carries the resolved principal and key_id.
	 *
	 * @return array{path:string,reason:string,flag:string,error?:string,principal?:array,key_id?:int}
	 */
	public static function decide( string $surface, int $user_id, string $flag_key = '' ): array {
		$flag = self::flag( '' !== $flag_key ? $flag_key : $surface );
		$php  = static function ( string $reason ) use ( $flag ): array { return array( 'path' => 'php', 'reason' => $reason, 'flag' => $flag ); };
		$err  = static function ( string $code ) use ( $flag ): array { return array( 'path' => 'error', 'reason' => $code, 'flag' => $flag, 'error' => $code ); };
		if ( ! in_array( $surface, self::SURFACES, true ) || 'php' === $flag ) {
			return $php( 'flag_php' );
		}
		if ( $user_id <= 0 ) {
			return $php( 'guest' ); // guests keep the PHP surface rules (/gpt/ guest quota, profile chat)
		}
		$accounts = self::accounts();
		// PHASE-0.90 S90-W4: no Zalo number is no longer a reason to stay on PHP - the site's web home answers (way back: filter
		// `bizcity_twin_web_home` ⇒ false restores the old rule).
		$home = ! $accounts && class_exists( 'BizCity_Twin_Web_Home' ) && BizCity_Twin_Web_Home::enabled();
		// [2026-10-08 Johnny Chu - Chu Hoàng Anh] CELL-FIRST E-3 L1-b/c/d — config gaps are guided errors in auto too (cell
		// first: no silent MPR answer); staff with the staff role off go to the cell as customer (principal() already does that).
		if ( ! $accounts && ! $home ) {
			return $err( 'no_zalo_hub_number' );
		}
		if ( 'auto' === $flag && self::route_missing() ) {
			return $err( 'hub_route_missing' );
		}
		$key_id = self::key_id();
		if ( $key_id <= 0 || null === self::target() ) {
			return $err( 'hub_key_unknown' );
		}
		$principal = $home ? self::web_home_principal( $user_id ) : self::principal( $user_id, $accounts );
		return array( 'path' => 'node', 'reason' => 'node', 'flag' => $flag, 'principal' => $principal, 'key_id' => $key_id );
	}

	/**
	 * PHASE-0.89 S89-B5/B7 — Ask Brain answers from the person's OWN notebooks and memory. On the cell only an owner/staff
	 * principal has those (anyone else is a customer there: no notebook, no memory — worse than the MPR pipeline), and a
	 * focused notebook is pinned only through the `notebook` mode. '' = the cell may answer; else the reason to stay on php.
	 */
	/** CELL-FIRST E-3 L1-e — a stay reason that is answered with its R-ERROR-UX body instead of the MPR pipeline. */
	public static function stay_is_error( string $reason ): bool {
		return 'focus_needs_notebook_mode' === $reason;
	}

	public static function brain_stay_reason( array $decision, int $focus_notebook_id ): string {
		if ( 'node' !== ( $decision['path'] ?? '' ) ) {
			return '';
		}
		$principal = (array) ( $decision['principal'] ?? array() );
		if ( ! in_array( (string) ( $principal['role'] ?? '' ), array( 'owner', 'staff' ), true ) ) {
			return 'not_agent_principal';
		}
		if ( $focus_notebook_id > 0 && ! in_array( 'notebook', (array) ( $principal['modes'] ?? array() ), true ) ) {
			return 'focus_needs_notebook_mode';
		}
		return '';
	}

	/**
	 * PHASE-0.89 S89-Q6 (doc 44) — the cell's `final_done.suggestions` as the site stores them with the assistant message, so a
	 * reopened session still shows the "Tra trong sổ X" / "Phân tích chuyên sâu" buttons. Only the contract keys survive
	 * (fixture taa/turn.suggestions.json): ≤ 3 notebook buttons + ≤ 1 deep, short strings, no passage text ever.
	 *
	 * @return list<array<string,mixed>>
	 */
	public static function clean_suggestions( $raw ): array {
		$out   = array();
		$books = 0;
		$deep  = 0;
		foreach ( is_array( $raw ) ? $raw : array() as $s ) {
			if ( ! is_array( $s ) ) {
				continue;
			}
			$kind  = (string) ( $s['kind'] ?? '' );
			$label = trim( (string) ( $s['label'] ?? '' ) );
			$ask   = trim( (string) ( $s['ask'] ?? '' ) );
			if ( '' === $label || '' === $ask ) {
				continue;
			}
			$label = function_exists( 'mb_substr' ) ? mb_substr( $label, 0, 120 ) : substr( $label, 0, 120 );
			$ask   = function_exists( 'mb_substr' ) ? mb_substr( $ask, 0, 2000 ) : substr( $ask, 0, 2000 );
			if ( 'notebook' === $kind && $books < 3 && (int) ( $s['notebook_id'] ?? 0 ) > 0 && in_array( (string) ( $s['scope'] ?? '' ), array( 'mine', 'shop' ), true ) ) {
				$out[] = array( 'kind' => 'notebook', 'scope' => (string) $s['scope'], 'notebook_id' => (int) $s['notebook_id'], 'label' => $label, 'hits' => max( 0, (int) ( $s['hits'] ?? 0 ) ), 'ask' => $ask );
				$books++;
			} elseif ( 'deep' === $kind && 0 === $deep ) {
				$out[] = array( 'kind' => 'deep', 'label' => $label, 'ask' => $ask );
				$deep++;
			}
		}
		return $out;
	}

	/**
	 * PHASE-0.90 S90-W4 - principal of a user on the site's own web channel (no number): role + modes from
	 * BizCity_Twin_Web_Home (server facts), no `account_id` (the cell answers on the tenant's web home). Staff go out as
	 * customer when the staff role is switched off, like on a number.
	 *
	 * @return array{kind:string,account_id:string,role:string,role_evidence:string,modes:string[],user_hash:string}
	 */
	public static function web_home_principal( int $user_id ): array {
		$r = BizCity_Twin_Web_Home::role_of( $user_id );
		if ( 'staff' === $r['role'] && ! self::staff_supported() ) {
			return self::as_role( 'staff', '', 'customer', 'default_customer', array() );
		}
		return self::as_role( 'owner' === $r['role'] ? 'owner' : ( 'staff' === $r['role'] ? 'staff' : 'none' ), '', $r['role'], $r['role_evidence'], $r );
	}

	/**
	 * The user's principal on this site's zalo-hub numbers and the number that answers (rule in the file header).
	 *
	 * @param string[] $accounts bridge ids
	 * @return array{kind:string,account_id:string,role:string,role_evidence:string,modes:string[],user_hash:string}
	 */
	public static function principal( int $user_id, array $accounts ): array {
		$owner = null;
		$staff = null;
		foreach ( $accounts as $bridge ) {
			foreach ( self::principals( (string) $bridge ) as $p ) {
				if ( empty( $p['active'] ) || (int) ( $p['user_id'] ?? 0 ) !== $user_id || empty( $p['modes'] ) || '' === (string) ( $p['user_hash'] ?? '' ) ) {
					continue; // no mode ⇒ customer, like a staff entry without modes on Zalo (turn.zalo.staff.json)
				}
				if ( 'owner' === ( $p['role'] ?? '' ) && null === $owner ) {
					$owner = array( (string) $bridge, $p );
				} elseif ( 'staff' === ( $p['role'] ?? '' ) && null === $staff ) {
					$staff = array( (string) $bridge, $p );
				}
			}
		}
		if ( $owner ) {
			return self::as_role( 'owner', $owner[0], 'owner', 'wp_principal', $owner[1] );
		}
		if ( $staff ) {
			// [2026-10-01 Claude Opus 5.5] PHASE-0.87 W2 web — the cell's web turn accepts `staff` (twin-agent-turn@1.3). With the
			// filter off, staff go out as customer (fail closed, never widened to owner).
			return self::staff_supported()
				? self::as_role( 'staff', $staff[0], 'staff', 'wp_principal', $staff[1] )
				: self::as_role( 'staff', $staff[0], 'customer', 'default_customer', array() );
		}
		return self::as_role( 'none', self::default_account( $accounts ), 'customer', 'default_customer', array() );
	}

	/* ── Cell Chat (PHASE-0.105, doc 70) ──────────────────────────── */

	/**
	 * [2026-10-12 10:05 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.105 S105-C1 — every zalo-hub number where the user is the verified
	 * owner (principal userHash set) or an active staff member with ≥ 1 mode. Internal rows keep the hash/modes the envelope needs;
	 * the browser only ever gets owned_numbers(). principal() (Brain Chat) is untouched: it still picks ONE number.
	 *
	 * @return list<array{account_id:string,label:string,role:string,linked:bool,user_hash:string,modes:string[]}>
	 */
	public static function owned_rows( int $user_id ): array {
		$out = array();
		if ( $user_id <= 0 ) {
			return $out;
		}
		foreach ( self::accounts() as $bridge ) {
			foreach ( self::principals( (string) $bridge ) as $p ) {
				$role = (string) ( $p['role'] ?? '' );
				if ( empty( $p['active'] ) || (int) ( $p['user_id'] ?? 0 ) !== $user_id || '' === (string) ( $p['user_hash'] ?? '' ) || ! in_array( $role, array( 'owner', 'staff' ), true ) ) {
					continue;
				}
				if ( 'staff' === $role && empty( $p['modes'] ) ) {
					continue; // no mode ⇒ customer on the cell, nothing to mirror
				}
				$out[] = array(
					'account_id' => (string) $bridge,
					'label'      => self::number_label( (string) $bridge ),
					'role'       => $role,
					'linked'     => 'owner' === $role || '' !== trim( (string) ( $p['uid'] ?? '' ) ),
					'user_hash'  => strtolower( (string) $p['user_hash'] ),
					'modes'      => array_values( array_map( 'strval', (array) ( $p['modes'] ?? array() ) ) ),
				);
				break; // one row per number (owner first)
			}
		}
		return $out;
	}

	/**
	 * Browser-safe list: {account_id, label "…8899", role, linked, cell_chat}. `cell_chat` = the cell answers Cell Chat (capabilities
	 * through the Hub, cached 60 s; false on any failure).
	 *
	 * @return list<array{account_id:string,label:string,role:string,linked:bool,cell_chat:bool}>
	 */
	public static function owned_numbers( int $user_id ): array {
		$rows = self::owned_rows( $user_id );
		$caps = $rows ? self::cell_chat_capable() : false;
		$out  = array();
		foreach ( $rows as $r ) {
			$out[] = array( 'account_id' => $r['account_id'], 'label' => $r['label'], 'role' => $r['role'], 'linked' => $r['linked'], 'cell_chat' => $caps, 'builtins' => $caps ? self::cell_chat_builtins( $r['account_id'] ) : array() );
		}
		return $out;
	}

	/**
	 * [2026-10-13 01:20 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.106 S106-B5/U4 — the quick-create buttons THIS number can run (cell `capabilities.builtins`
	 * through the Hub, cached 60 s per number; [] on any failure or an older cell, so TwinChat simply shows no button).
	 *
	 * @return string[]
	 */
	public static function cell_chat_builtins( string $account_id ): array {
		$account_id = (string) preg_replace( '/[^0-9]/', '', $account_id );
		if ( '' === $account_id ) {
			return array();
		}
		$key = self::CELL_CHAT_CAPS_KEY . '_b_' . $account_id;
		if ( function_exists( 'get_transient' ) ) {
			$c = get_transient( $key );
			if ( is_array( $c ) ) {
				return $c;
			}
		}
		$r   = self::hub_get( self::CELL_CHAT_PATH . '/capabilities?account_id=' . rawurlencode( $account_id ) );
		$out = array();
		foreach ( (array) ( $r['builtins'] ?? array() ) as $k ) {
			if ( is_string( $k ) && 1 === preg_match( '/^[a-z0-9_.]{1,64}$/', $k ) ) {
				$out[] = $k;
			}
		}
		$out = array_slice( array_values( array_unique( $out ) ), 0, 12 );
		if ( function_exists( 'set_transient' ) ) {
			set_transient( $key, $out, self::CELL_CHAT_CAPS_TTL );
		}
		return $out;
	}

	/** "…8899" - the last 4 digits of the number's label, else of its bridge id. Never the full phone. */
	private static function number_label( string $bridge ): string {
		if ( isset( self::$readers['label'] ) ) {
			return (string) call_user_func( self::$readers['label'], $bridge );
		}
		$label = '';
		if ( class_exists( 'BizCity_Zalo_Hub_Config_Sync' ) ) {
			foreach ( (array) BizCity_Zalo_Hub_Config_Sync::accounts() as $a ) {
				if ( (string) ( $a['bridge_id'] ?? '' ) === $bridge ) {
					$label = (string) ( $a['label'] ?? '' );
					break;
				}
			}
		}
		$digits = preg_replace( '/\D/', '', $label );
		$digits = strlen( (string) $digits ) >= 4 ? $digits : preg_replace( '/\D/', '', $bridge );
		return '…' . substr( (string) $digits, -4 );
	}

	/** GET a Hub path as the site (test seam `hub_get`). @return array decoded body, or array() on any failure */
	public static function hub_get( string $path ): array {
		if ( isset( self::$readers['hub_get'] ) ) {
			return (array) call_user_func( self::$readers['hub_get'], $path );
		}
		if ( ! class_exists( 'BizCity_Zalo_Personal_Hub_Client' ) || ! BizCity_Zalo_Personal_Hub_Client::instance()->is_ready_fast() ) {
			return array();
		}
		return (array) BizCity_Zalo_Personal_Hub_Client::instance()->get_managed_path( $path );
	}

	/** Cell capabilities `cell_chat:1` via the Hub, cached 60 s (a failure is cached too - the picker must not hammer a dead Hub). */
	public static function cell_chat_capable(): bool {
		$key = self::CELL_CHAT_CAPS_KEY . '_' . ( function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 1 );
		if ( function_exists( 'get_transient' ) ) {
			$c = get_transient( $key );
			if ( 'yes' === $c || 'no' === $c ) {
				return 'yes' === $c;
			}
		}
		$r  = self::hub_get( self::CELL_CHAT_PATH . '/capabilities' );
		$ok = ! empty( $r ) && false !== ( $r['ok'] ?? true ) && 1 === (int) ( $r['cell_chat'] ?? 0 );
		if ( function_exists( 'set_transient' ) ) {
			set_transient( $key, $ok ? 'yes' : 'no', self::CELL_CHAT_CAPS_TTL );
		}
		return $ok;
	}

	/** `web-<key_id>` when the site answers through its web home (no number needed), else ''. */
	public static function web_account_id(): string {
		$key_id = self::key_id();
		return $key_id > 0 && class_exists( 'BizCity_Twin_Web_Home' ) && BizCity_Twin_Web_Home::enabled() ? 'web-' . $key_id : '';
	}

	/**
	 * R-SETUP-4 four steps as server facts: ① BizCity key present ② the Hub knows this site (key id learnt) ③ a Zalo Hub number
	 * exists ④ the default Agent Guru exists. Test seam `setup`.
	 *
	 * @return array{step1:bool,step2:bool,step3:bool,step4:bool}
	 */
	public static function setup_flags(): array {
		if ( isset( self::$readers['setup'] ) ) {
			return (array) call_user_func( self::$readers['setup'] );
		}
		$key = false;
		if ( class_exists( 'BizCity_LLM_Gateway_Panel_REST' ) && method_exists( 'BizCity_LLM_Gateway_Panel_REST', 'projection' ) ) {
			$p   = (array) BizCity_LLM_Gateway_Panel_REST::projection();
			$key = ! empty( $p['key']['configured'] );
		}
		return array(
			'step1' => $key,
			'step2' => $key && self::key_id() > 0,
			'step3' => (bool) self::accounts(),
			'step4' => class_exists( 'BizCity_Guru_Context_Resolver' ) && method_exists( 'BizCity_Guru_Context_Resolver', 'default_character_id' ) && (int) BizCity_Guru_Context_Resolver::default_character_id( false ) > 0,
		);
	}

	/**
	 * One Cell Chat turn - NEVER the MPR path. `$turn` = {user_id, session_id, text, account_id?, mirror_simulate?}. Streams the cell's SSE
	 * through pipe() with the `node` flag (no php fallback) and turns every failure into an SSE `error` frame + `end`.
	 * `account_id` must be one of owned_rows(); absent ⇒ the site's web home (plain web turn, `contact`, no `account_id`).
	 *
	 * @return string 'done' (the stream is closed; the caller must exit)
	 */
	public static function cell_chat_run( array $turn ): string {
		$user_id = (int) ( $turn['user_id'] ?? 0 );
		if ( '' === trim( (string) ( $turn['text'] ?? '' ) ) ) {
			return self::stream_error( 'empty_message' );
		}
		// [2026-10-13 03:00 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.105 S105-B3 — ONE conversation id per person: without a session id, `session_id()` mints a fresh one from the trace id on EVERY turn, and a web-home
		// Cell Chat then opens a new cell thread (`web:twinchat:<new>`) for every message - no history, no summary, a Sessions page full of one-message chats. A real number shares the Zalo 1-1 thread anyway.
		// [2026-10-13 01:20 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.106 S106-C4 — the fallback is now the person's MAIN session `brain_sess_0_<uid>_cc00`
		// (a real Ask Brain session id, so the turn shows in Sessions); the cell maps it to the main thread exactly like the old `cc_u<uid>`.
		if ( '' === trim( (string) ( $turn['session_id'] ?? '' ) ) && $user_id > 0 ) {
			$turn['session_id'] = self::main_session_id( $user_id );
		}
		$key_id = self::key_id();
		if ( $key_id <= 0 || null === self::target() ) {
			return self::stream_error( 'hub_key_unknown' );
		}
		$account = trim( (string) ( $turn['account_id'] ?? '' ) );
		if ( '' !== $account ) {
			$principal = null;
			foreach ( self::owned_rows( $user_id ) as $r ) {
				if ( $r['account_id'] === $account ) {
					$principal = self::as_role( $r['role'], $account, $r['role'], 'wp_principal', array( 'modes' => $r['modes'], 'user_hash' => $r['user_hash'] ) );
					break;
				}
			}
		} else {
			$principal = self::web_home_member( $user_id );
		}
		if ( null === $principal ) {
			return self::stream_error( 'not_owner' );
		}
		$turn['mirror'] = true;
		unset( $turn['history'] );
		$env = self::envelope( 'twinchat', $turn, $principal, $key_id );
		// [2026-10-13 01:20 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.106 S106-C9 (D106-3, owner 2026-10-13) — the message goes THROUGH the event stream:
		// the question is written into its TwinChat session first, then sent to the cell in this same request (SSE unchanged, never a queue).
		// The answer comes back as the cell's turn-complete (`assistant_message`, same session + trace). One writer per event type.
		self::record_cell_chat_question( $env, $user_id, (string) $turn['text'] );
		$res = self::pipe( $env, 'node', null, array(
			'on_error' => static function ( array $d ) use ( $env, $user_id ): void {
				self::record_cell_chat_failure( $env, $user_id, (string) ( $d['code'] ?? 'turn_failed' ), (string) ( $d['message'] ?? '' ) );
			},
		) );
		if ( $res instanceof WP_REST_Response ) {
			$d    = (array) $res->get_data();
			$hint = (string) ( $d['hint'] ?? '' );
			// [2026-10-13 00:50 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.105 S105-C2 — say WHY the Hub answer was not a stream (HTTP status / code / curl errno only, no body): "Hub unreachable" alone hides a 4xx/5xx or an old cell
			if ( 'hub_unreachable' === (string) ( $d['code'] ?? 'hub_unreachable' ) && '' !== self::fallback_detail() ) {
				$hint = trim( $hint . ' Chi tiết kỹ thuật: ' . self::fallback_detail() );
			}
			self::record_cell_chat_failure( $env, $user_id, (string) ( $d['code'] ?? 'hub_unreachable' ), (string) ( $d['message'] ?? '' ) );
			return self::stream_error( (string) ( $d['code'] ?? 'hub_unreachable' ), (string) ( $d['message'] ?? '' ), $hint, (string) ( $d['help_code'] ?? '' ) );
		}
		return 'done';
	}

	/** [2026-10-13 01:20 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.106 S106-C4 — the person's MAIN Cell Chat session (= their Zalo 1-1 thread on the cell). */
	public static function main_session_id( int $user_id ): string {
		return 'brain_sess_0_' . max( 0, $user_id ) . '_cc00';
	}

	/** Test seam: replaces `BizCity_Twin_Event_Bus::ingest_remote` for the Cell Chat records (PHASE-0.106). @var callable|null */
	public static $record_seam = null;

	/**
	 * [2026-10-13 01:20 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.106 S106-C9 — `user_message` of a Cell Chat turn, written BEFORE the cell answers.
	 * uuid from the trace id (a retried request with the same trace never doubles it). A storage failure never blocks the turn.
	 */
	public static function record_cell_chat_question( array $env, int $user_id, string $text ): void {
		$session = (string) ( $env['session']['session_id'] ?? '' );
		$trace   = (string) ( $env['trace_id'] ?? '' );
		if ( '' === $session || '' === $trace || $user_id <= 0 ) {
			return;
		}
		$content = self::clip( $text );
		self::record_event( array(
			'event_uuid'       => self::record_uuid( $trace . ':u' ),
			'event_type'       => 'user_message',
			'event_source'     => 'twinchat',
			'trace_id'         => $trace,
			'session_id'       => $session,
			'user_id'          => $user_id,
			'blog_id'          => function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : null,
			'created_epoch_ms' => (int) ( microtime( true ) * 1000 ),
			'payload'          => array(
				'content'    => $content,
				'text'       => $content,
				'via'        => 'cell_chat',
				'account_id' => (string) ( $env['account_id'] ?? '' ),
				'surface'    => 'twinchat',
			),
		) );
	}

	/** [2026-10-13 01:20 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.106 S106-C9 — the Hub / cell could not take the turn: the question stays in its session, marked failed. */
	public static function record_cell_chat_failure( array $env, int $user_id, string $code, string $message ): void {
		$session = (string) ( $env['session']['session_id'] ?? '' );
		$trace   = (string) ( $env['trace_id'] ?? '' );
		if ( '' === $session || '' === $trace || $user_id <= 0 ) {
			return;
		}
		self::record_event( array(
			'event_uuid'       => self::record_uuid( $trace . ':e' ),
			'event_type'       => 'cell_turn_failed',
			'event_source'     => 'twinchat',
			'trace_id'         => $trace,
			'session_id'       => $session,
			'user_id'          => $user_id,
			'blog_id'          => function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : null,
			'created_epoch_ms' => (int) ( microtime( true ) * 1000 ),
			'payload'          => array(
				'trace_id' => $trace,
				'code'     => substr( sanitize_key( $code ), 0, 64 ),
				'message'  => mb_substr( trim( strip_tags( $message ) ), 0, 300 ),
				'via'      => 'cell_chat',
			),
		) );
	}

	/** Version-5-shaped uuid of a Cell Chat record (same shape the turn-complete route uses). */
	private static function record_uuid( string $key ): string {
		if ( class_exists( 'BizCity_Twin_Agent_Turn_Complete_REST' ) ) {
			return BizCity_Twin_Agent_Turn_Complete_REST::uuid_from_key( 'cell-chat|' . $key );
		}
		$h = sha1( 'cell-chat|' . $key );
		return sprintf( '%s-%s-5%s-%s%s-%s', substr( $h, 0, 8 ), substr( $h, 8, 4 ), substr( $h, 13, 3 ), dechex( ( hexdec( $h[16] ) & 0x3 ) | 0x8 ), substr( $h, 17, 3 ), substr( $h, 20, 12 ) );
	}

	private static function record_event( array $event ): void {
		try {
			if ( null !== self::$record_seam ) {
				call_user_func( self::$record_seam, $event );
				return;
			}
			if ( class_exists( 'BizCity_Twin_Event_Bus' ) && method_exists( 'BizCity_Twin_Event_Bus', 'ingest_remote' ) ) {
				BizCity_Twin_Event_Bus::ingest_remote( $event );
			}
		} catch ( \Throwable $e ) {
			if ( function_exists( 'error_log' ) ) {
				error_log( '[CellChat] event record failed: ' . substr( $e->getMessage(), 0, 200 ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
			}
		}
	}

	/** The user's web-home principal when the site has a web home and the user is its verified owner/staff, else null. */
	public static function web_home_member( int $user_id ): ?array {
		if ( $user_id <= 0 || ! class_exists( 'BizCity_Twin_Web_Home' ) || ! BizCity_Twin_Web_Home::enabled() ) {
			return null;
		}
		$p = self::web_home_principal( $user_id );
		return in_array( $p['role'], array( 'owner', 'staff' ), true ) && '' !== $p['user_hash'] ? $p : null;
	}

	/**
	 * [2026-10-12 10:05 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.105 S105-C9 - the shared thread of the caller: Hub `cell-chat/history` with the
	 * caller's OWN user_hash (never taken from the browser). `$account_id` '' = web home.
	 *
	 * @return array{status:int,body:array}
	 */
	public static function cell_chat_history( int $user_id, string $account_id, int $limit, string $session_id = '' ): array {
		$account_id = trim( $account_id );
		$hash       = '';
		$target     = $account_id;
		if ( '' !== $account_id ) {
			foreach ( self::owned_rows( $user_id ) as $r ) {
				if ( $r['account_id'] === $account_id ) {
					$hash = $r['user_hash'];
				}
			}
		} else {
			$p      = self::web_home_member( $user_id );
			$hash   = $p ? $p['user_hash'] : '';
			$target = self::web_account_id();
		}
		if ( '' === $hash || '' === $target ) {
			return array( 'status' => 403, 'body' => array( 'ok' => false, 'code' => 'not_owner', 'message' => self::ERRORS['not_owner'][1], 'hint' => self::ERRORS['not_owner'][2], 'help_code' => 'S105-CC-SITE-NOT-OWNER' ) );
		}
		$limit = max( 1, min( 100, $limit ) );
		// [2026-10-13 01:20 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.106 S106-U2 — a TwinChat session other than the main one reads its own cell thread
		$session_q = 1 === preg_match( '/^[A-Za-z0-9._:-]{1,64}$/', $session_id ) ? '&session_id=' . rawurlencode( $session_id ) : '';
		$b     = self::hub_get( self::CELL_CHAT_PATH . '/history?account_id=' . rawurlencode( $target ) . '&user_hash=' . rawurlencode( $hash ) . '&limit=' . $limit . $session_q );
		if ( ! $b || false === ( $b['ok'] ?? true ) || isset( $b['_degraded'] ) ) {
			$code = (string) ( $b['code'] ?? 'hub_unreachable' );
			$e    = self::ERRORS[ $code ] ?? self::ERRORS['hub_unreachable'];
			return array( 'status' => 'not_owner' === $code ? 403 : 502, 'body' => array( 'ok' => false, 'code' => $code, 'message' => (string) ( $b['message'] ?? $e[1] ), 'hint' => (string) ( $b['hint'] ?? $e[2] ), 'help_code' => (string) ( $b['help_code'] ?? 'S105-CC-SITE-HISTORY' ) ) );
		}
		$items = array();
		foreach ( array_slice( (array) ( $b['items'] ?? array() ), 0, 100 ) as $it ) {
			if ( is_array( $it ) ) {
				$row   = array( 'role' => 'assistant' === ( $it['role'] ?? '' ) ? 'assistant' : 'user', 'text' => (string) ( $it['text'] ?? '' ), 'via' => 'zalo' === ( $it['via'] ?? '' ) ? 'zalo' : 'cell_chat', 'at' => (string) ( $it['at'] ?? '' ) );
				// [2026-10-13 01:10 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.105 v2 S105-M2 — files the cell remembered for this row (links only; bytes come from the media route).
				$files = self::clean_files( $it['files'] ?? array() );
				if ( $files ) {
					$row['files'] = $files;
				}
				$items[] = $row;
			}
		}
		return array( 'status' => 200, 'body' => array( 'ok' => true, 'items' => $items, 'thread' => 'zalo_1_1' === ( $b['thread'] ?? '' ) ? 'zalo_1_1' : 'cellchat', 'linked' => ! empty( $b['linked'] ) ) );
	}

	/**
	 * [2026-10-13 01:10 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.105 v2 S105-M2 — keep only {media_id,name,mime,bytes,kind,expired}; drop malformed entries; max 10.
	 *
	 * @param mixed $raw
	 * @return list<array{media_id:string,name:string,mime:string,bytes:int,kind:string,expired:bool}>
	 */
	public static function clean_files( $raw ): array {
		$out = array();
		if ( ! is_array( $raw ) ) {
			return $out;
		}
		foreach ( $raw as $f ) {
			if ( count( $out ) >= 10 ) {
				break;
			}
			if ( ! is_array( $f ) || ! is_string( $f['media_id'] ?? null ) || ! preg_match( '/^cm_[a-f0-9]{24}$/', $f['media_id'] ) ) {
				continue;
			}
			$mime = is_string( $f['mime'] ?? null ) ? trim( $f['mime'] ) : '';
			if ( strlen( $mime ) > 100 || ! preg_match( '#^[A-Za-z0-9][A-Za-z0-9!\#$&^_.+-]*/[A-Za-z0-9][A-Za-z0-9!\#$&^_.+-]*(\s*;.*)?$#', $mime ) ) {
				continue;
			}
			$name = is_string( $f['name'] ?? null ) ? $f['name'] : '';
			$name = trim( (string) preg_replace( '/[\x00-\x1F\x7F\/\\\\]+/', '', $name ) );
			$name = function_exists( 'mb_substr' ) ? mb_substr( $name, 0, 160, 'UTF-8' ) : substr( $name, 0, 160 );
			$kind = (string) ( $f['kind'] ?? '' );
			if ( ! is_numeric( $f['bytes'] ?? null ) || (float) $f['bytes'] < 0 || ! in_array( $kind, array( 'image', 'audio', 'video', 'file' ), true ) ) {
				continue;
			}
			$out[] = array( 'media_id' => $f['media_id'], 'name' => $name, 'mime' => $mime, 'bytes' => (int) $f['bytes'], 'kind' => $kind, 'expired' => ! empty( $f['expired'] ) );
		}
		return $out;
	}

	/** Single byte range only; anything else is dropped (the cell then answers the whole file). */
	public static function clean_range( string $raw ): string {
		$raw = trim( $raw );
		return preg_match( '/^bytes=(\d{1,15}-\d{0,15}|-\d{1,15})$/', $raw ) ? $raw : '';
	}

	/** Whitelisted response header lines from the Hub => lower-case name => value (control chars stripped). */
	public static function media_headers( array $lines ): array {
		$allow = array( 'content-type', 'content-length', 'content-range', 'accept-ranges', 'content-disposition', 'x-content-type-options' );
		$out   = array();
		foreach ( $lines as $line ) {
			$p = strpos( (string) $line, ':' );
			if ( false === $p ) {
				continue;
			}
			$name = strtolower( trim( substr( (string) $line, 0, $p ) ) );
			if ( in_array( $name, $allow, true ) ) {
				$out[ $name ] = trim( (string) preg_replace( '/[\x00-\x1F\x7F]/', '', substr( (string) $line, $p + 1 ) ) );
			}
		}
		return $out;
	}

	/**
	 * [2026-10-13 01:10 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.105 v2 S105-M3 - stream one cell file to the browser. The caller's OWN user_hash
	 * (owned_rows / web home) is resolved here, never taken from the browser; not owner/staff ⇒ 403 `not_owner` WITHOUT calling the Hub.
	 * Success returns {status,streamed:true} after the bytes were written (the REST handler then exits); failures return {status,body}.
	 *
	 * @return array{status:int,body?:array,streamed?:bool}
	 */
	public static function cell_chat_media( int $user_id, string $account_id, string $media_id, string $range = '' ): array {
		$account_id = trim( $account_id );
		$hash       = '';
		$target     = $account_id;
		if ( ! preg_match( '/^cm_[a-f0-9]{24}$/', $media_id ) ) {
			return array( 'status' => 400, 'body' => array( 'ok' => false, 'code' => 'invalid_param', 'message' => 'Mã tệp không hợp lệ.', 'hint' => 'Mở lại cuộc trò chuyện rồi thử lại.', 'help_code' => 'S105-CC-SITE-MEDIA-ID' ) );
		}
		if ( '' !== $account_id ) {
			foreach ( self::owned_rows( $user_id ) as $r ) {
				if ( $r['account_id'] === $account_id ) {
					$hash = $r['user_hash'];
				}
			}
		} else {
			$p      = self::web_home_member( $user_id );
			$hash   = $p ? $p['user_hash'] : '';
			$target = self::web_account_id();
		}
		if ( '' === $hash || '' === $target ) {
			return array( 'status' => 403, 'body' => array( 'ok' => false, 'code' => 'not_owner', 'message' => self::ERRORS['not_owner'][1], 'hint' => self::ERRORS['not_owner'][2], 'help_code' => 'S105-CC-SITE-NOT-OWNER' ) );
		}
		$path = self::CELL_CHAT_PATH . '/media/' . $media_id . '?account_id=' . rawurlencode( $target ) . '&user_hash=' . rawurlencode( $hash );
		$r    = self::hub_stream_get( $path, self::clean_range( $range ) );
		if ( empty( $r['streamed'] ) ) {
			$e = self::ERRORS['hub_unreachable'];
			return array( 'status' => 502, 'body' => array( 'ok' => false, 'code' => 'hub_unreachable', 'message' => $e[1], 'hint' => $e[2], 'help_code' => 'S105-CC-SITE-MEDIA' ) );
		}
		return array( 'status' => (int) $r['status'], 'streamed' => true );
	}

	/**
	 * [2026-10-13 01:40 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.106 S106-C5 — the same owner-checked fetch as cell_chat_media(), into a local FILE (the cron copy
	 * of an image/audio/video to the media library). Never writes more than `$max` bytes; a non-200 leaves the file empty.
	 *
	 * @return array{status:int,bytes:int}
	 */
	public static function cell_chat_media_to_file( int $user_id, string $account_id, string $media_id, string $dest, int $max ): array {
		$account_id = trim( $account_id );
		$hash       = '';
		$target     = $account_id;
		if ( ! preg_match( '/^cm_[a-f0-9]{24}$/', $media_id ) || '' === $dest ) {
			return array( 'status' => 400, 'bytes' => 0 );
		}
		if ( '' !== $account_id ) {
			foreach ( self::owned_rows( $user_id ) as $r ) {
				if ( $r['account_id'] === $account_id ) {
					$hash = $r['user_hash'];
				}
			}
		} else {
			$p      = self::web_home_member( $user_id );
			$hash   = $p ? $p['user_hash'] : '';
			$target = self::web_account_id();
		}
		if ( '' === $hash || '' === $target ) {
			return array( 'status' => 403, 'bytes' => 0 );
		}
		$path = self::CELL_CHAT_PATH . '/media/' . $media_id . '?account_id=' . rawurlencode( $target ) . '&user_hash=' . rawurlencode( $hash );
		return self::hub_download( $path, $dest, $max );
	}

	/**
	 * GET a Hub path as the site into a file (test seam `hub_download`).
	 *
	 * @return array{status:int,bytes:int}
	 */
	public static function hub_download( string $path, string $dest, int $max ): array {
		if ( isset( self::$readers['hub_download'] ) ) {
			return (array) call_user_func( self::$readers['hub_download'], $path, $dest, $max );
		}
		if ( ! function_exists( 'curl_init' ) || ! class_exists( 'BizCity_Zalo_Personal_Hub_Client' ) ) {
			return array( 'status' => 0, 'bytes' => 0 );
		}
		$target = BizCity_Zalo_Personal_Hub_Client::instance()->stream_target( $path );
		$fh     = $target ? @fopen( $dest, 'wb' ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions
		if ( ! $target || ! $fh ) {
			return array( 'status' => 0, 'bytes' => 0 );
		}
		$headers = array( 'Authorization: Bearer ' . (string) $target['key'], 'X-Site-URL: ' . ( function_exists( 'home_url' ) ? home_url() : '' ) );
		foreach ( (array) ( $target['headers'] ?? array() ) as $k => $v ) {
			$headers[] = $k . ': ' . $v;
		}
		$status = 0;
		$bytes  = 0;
		$ch     = curl_init( (string) $target['url'] );
		curl_setopt_array( $ch, array(
			CURLOPT_HTTPHEADER     => $headers,
			CURLOPT_TIMEOUT        => 110,
			CURLOPT_CONNECTTIMEOUT => 5,
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_HEADERFUNCTION => static function ( $ch, $line ) use ( &$status ) {
				if ( preg_match( '#^HTTP/\S+\s+(\d{3})#', $line, $m ) ) {
					$status = (int) $m[1];
				}
				return strlen( $line );
			},
			CURLOPT_WRITEFUNCTION  => static function ( $ch, $chunk ) use ( &$status, &$bytes, $fh, $max ) {
				if ( 200 !== $status ) {
					return strlen( $chunk ); // an error body is not the file
				}
				$bytes += strlen( $chunk );
				if ( $bytes > $max ) {
					return 0; // over the cap ⇒ abort
				}
				return (int) fwrite( $fh, $chunk ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			},
		) );
		curl_exec( $ch );
		curl_close( $ch );
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( 200 !== $status || $bytes > $max ) {
			return array( 'status' => 200 === $status ? 413 : $status, 'bytes' => 0 );
		}
		return array( 'status' => $status, 'bytes' => $bytes );
	}

	/**
	 * GET a Hub path as the site and PIPE the body to the browser (cURL WRITEFUNCTION; never buffered whole). Headers are sent on the first
	 * body chunk BEFORE draining stray output buffers (ob_end_clean, never flush - S105-H4 lesson). Test seam `hub_stream`.
	 *
	 * @return array{streamed:bool,status:int}  streamed=false ⇒ nothing was sent (Hub unreachable)
	 */
	public static function hub_stream_get( string $path, string $range = '' ): array {
		if ( isset( self::$readers['hub_stream'] ) ) {
			return (array) call_user_func( self::$readers['hub_stream'], $path, $range );
		}
		if ( ! function_exists( 'curl_init' ) || ! class_exists( 'BizCity_Zalo_Personal_Hub_Client' ) ) {
			return array( 'streamed' => false, 'status' => 0 );
		}
		$target = BizCity_Zalo_Personal_Hub_Client::instance()->stream_target( $path );
		if ( ! $target ) {
			return array( 'streamed' => false, 'status' => 0 );
		}
		ignore_user_abort( false );
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 130 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		$status  = 0;
		$lines   = array();
		$started = false;
		$begin   = static function () use ( &$status, &$lines, &$started ): void {
			$started = true;
			if ( ! headers_sent() ) {
				header_remove( 'Content-Type' );
				header_remove( 'Content-Length' );
				header_remove( 'Expires' );
				header_remove( 'Pragma' );
				http_response_code( $status );
				foreach ( self::media_headers( $lines ) as $n => $v ) {
					header( $n . ': ' . $v, true );
				}
				header( $status >= 200 && $status < 300 ? 'Cache-Control: private, max-age=3600' : 'Cache-Control: no-store', true );
			}
			while ( ob_get_level() > 0 ) {
				@ob_end_clean(); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			}
		};
		$headers = array( 'Authorization: Bearer ' . (string) $target['key'], 'X-Site-URL: ' . ( function_exists( 'home_url' ) ? home_url() : '' ) );
		foreach ( (array) ( $target['headers'] ?? array() ) as $k => $v ) {
			$headers[] = $k . ': ' . $v;
		}
		if ( '' !== $range ) {
			$headers[] = 'Range: ' . $range;
		}
		$ch = curl_init( (string) $target['url'] );
		curl_setopt_array( $ch, array(
			CURLOPT_HTTPHEADER     => $headers,
			CURLOPT_TIMEOUT        => 125,
			CURLOPT_CONNECTTIMEOUT => 5,
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_HEADERFUNCTION => static function ( $ch, $line ) use ( &$status, &$lines ) {
				if ( preg_match( '#^HTTP/\S+\s+(\d{3})#', $line, $m ) ) {
					$status = (int) $m[1];
					$lines  = array();
				} else {
					$lines[] = $line;
				}
				return strlen( $line );
			},
			CURLOPT_WRITEFUNCTION  => static function ( $ch, $chunk ) use ( &$started, $begin ) {
				if ( ! $started ) {
					$begin();
				}
				echo $chunk; // phpcs:ignore WordPress.Security.EscapeOutput -- file bytes from the Hub, passed through unchanged
				flush();
				return connection_aborted() ? 0 : strlen( $chunk );
			},
		) );
		curl_exec( $ch );
		curl_close( $ch );
		if ( ! $started ) {
			if ( $status <= 0 || $status >= 600 ) {
				return array( 'streamed' => false, 'status' => 0 );
			}
			$begin(); // empty body (416, 304, 0-byte file): still relay status + headers
		}
		return array( 'streamed' => true, 'status' => $status );
	}

	/** Open the SSE and write one `error` + `end` frame (R-ERROR-UX fields). Site codes come from ERRORS; a Hub/cell code keeps its own text. */
	public static function stream_error( string $code, string $message = '', string $hint = '', string $help_code = '' ): string {
		$e = self::ERRORS[ $code ] ?? null;
		self::open();
		self::out( self::frame( 'error', array(
			'trace_id'  => self::trace_id(),
			'code'      => $code,
			'message'   => '' !== $message ? $message : ( $e ? $e[1] : self::ERRORS['hub_unreachable'][1] ),
			'hint'      => '' !== $hint ? $hint : ( $e ? $e[2] : self::ERRORS['hub_unreachable'][2] ),
			'help_code' => '' !== $help_code ? $help_code : 'S105-CC-SITE-' . strtoupper( str_replace( '_', '-', $code ) ),
			'retryable' => false,
		) ) );
		self::out( self::frame( 'end', array() ) );
		return 'done';
	}

	/* ── envelope (A1 + A2) ───────────────────────────────────────── */

	/**
	 * twin-agent-turn@1.2.0 envelope. `$turn` = {user_id, session_id, text, history[{role, content|text}], notebook_id?, thread_key?, effort?, images?: list<url>}.
	 */
	public static function envelope( string $surface, array $turn, array $principal, int $key_id ): array {
		$user_id = (int) $turn['user_id'];
		$ref     = substr( self::user_hash( $user_id ), 0, 16 );
		$who     = array( 'kind' => 'wp_user', 'ref' => $ref, 'wp_user_id' => $user_id );
		if ( in_array( $principal['role'], array( 'owner', 'staff' ), true ) && '' !== $principal['user_hash'] ) {
			$who['user_hash'] = $principal['user_hash'];
		}
		$session = array( 'session_id' => self::session_id( (string) ( $turn['session_id'] ?? '' ) ) );
		if ( ! empty( $turn['thread_key'] ) ) {
			$session['thread_key'] = substr( (string) $turn['thread_key'], 0, 200 );
		}
		$env = array(
			'contract'      => ! empty( $turn['mirror'] ) ? self::CONTRACT_MIRROR : ( ( '' === (string) $principal['account_id'] || ! empty( $turn['vertical'] ) ) ? self::CONTRACT_CONTACT : ( 'staff' === $principal['role'] ? self::CONTRACT_STAFF : self::CONTRACT ) ),
			'axis'          => self::AXIS,
			'trace_id'      => self::trace_id(),
			'surface'       => $surface,
			'tenant'        => array( 'key_id' => $key_id, 'blog_id' => function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 1 ),
			'account_id'    => (string) $principal['account_id'],
			'principal'     => $who,
			'role'          => $principal['role'],
			'role_evidence' => $principal['role_evidence'],
			'modes'         => array_values( $principal['modes'] ),
			'session'       => $session,
			'parts'         => self::parts( (string) $turn['text'], (array) ( $turn['images'] ?? array() ) ),
			'history'       => self::history( (array) ( $turn['history'] ?? array() ) ),
		);
		if ( (int) ( $turn['notebook_id'] ?? 0 ) > 0 ) {
			$env['focus'] = array( 'notebook_id' => (int) $turn['notebook_id'] );
		}
		// PHASE-0.89 S89-B1: the vertical brain mode asked for (registry id of brain-vertical@1; the cell resolves it and gates it by role)
		if ( ! empty( $turn['vertical'] ) && 1 === preg_match( '/^[a-z_]{2,24}$/', (string) $turn['vertical'] ) ) {
			$env['vertical'] = (string) $turn['vertical'];
		}
		// [2026-10-01 Claude Opus 5.5] PHASE-0.87 — per-turn reasoning level (additive, optional; owner 2026-10-01). Only the 4 UI
		// values leave the site; the cell maps them to thinking (off/low/medium/high) and clamps to the agent ceiling, the model
		// and the tenant plan — so a customer may pick any of them and still never exceeds the ceiling.
		$effort = (string) ( $turn['effort'] ?? '' );
		if ( in_array( $effort, self::EFFORTS, true ) ) {
			$env['effort'] = $effort;
		}
		$guru = '' !== (string) $principal['account_id'] ? self::guru_ref( (string) $principal['account_id'] ) : '';
		if ( '' !== $guru ) {
			$env['guru_ref'] = $guru;
		}
		// PHASE-0.90 (R-CONTACT-ID, contact-identity@1 ContactIn): a numbered turn names the number that answers; a home turn
		// (no number) names only the web channel. Never a tenant or person id (R-CID-3).
		if ( '' === (string) $principal['account_id'] ) {
			// `contact` takes the place of `account_id` (fixture key order)
			$at      = array_search( 'account_id', array_keys( $env ), true );
			$contact = array(
				'platform'     => 'wp',
				'channel_ref'  => class_exists( 'BizCity_Twin_Web_Home' ) ? BizCity_Twin_Web_Home::channel_ref() : '',
				'platform_uid' => (string) $user_id,
			);
			$env = array_slice( $env, 0, (int) $at, true ) + array( 'contact' => $contact ) + array_slice( $env, (int) $at + 1, null, true );
		}
		// [2026-10-12 10:05 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.105 S105-C2 — Cell Chat (additive, doc 70 §1): the cell owns the shared
		// thread, so NO `history` goes out; `mirror` turns the mirror on and `mirror_opts.simulate_actions` labels action tools as dry-run.
		if ( ! empty( $turn['mirror'] ) ) {
			unset( $env['history'] );
			$env['mirror']      = 'zalo_owner';
			$env['mirror_opts'] = array( 'simulate_actions' => ! empty( $turn['mirror_simulate'] ) );
			// [2026-10-13 01:20 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.106 S106-B6 — the quick-create button pressed (a soft hint the cell names only when the turn has that tool)
			$prefer = (string) ( $turn['prefer_tool'] ?? '' );
			if ( 1 === preg_match( '/^[a-z0-9_.]{1,64}$/', $prefer ) ) {
				$env['mirror_opts']['prefer_tool'] = $prefer;
				$label = trim( strip_tags( (string) ( $turn['prefer_label'] ?? '' ) ) );
				if ( '' !== $label ) {
					$env['mirror_opts']['prefer_label'] = mb_substr( $label, 0, 40 );
				}
			}
		}
		$env['sent_at'] = gmdate( 'Y-m-d\TH:i:s\Z', self::now() );
		return $env;
	}

	/**
	 * [2026-10-01 Claude Opus 5.5] PHASE-0.87 — text part, then one image part per absolute http(s) URL the cell can fetch
	 * (cell partSchema: image url ≤ 2000, ≤ 20 parts). Other URLs are skipped, never rewritten.
	 */
	public static function parts( string $text, array $images ): array {
		$parts = array( array( 'type' => 'text', 'text' => self::clip( $text ) ) );
		foreach ( $images as $url ) {
			$url = trim( (string) $url );
			if ( count( $parts ) < self::PARTS_MAX && strlen( $url ) <= 2000 && preg_match( '#^https?://[^\s/?\#]+\S*$#i', $url ) ) {
				$parts[] = array( 'type' => 'image', 'url' => $url );
			}
		}
		return $parts;
	}

	/**
	 * Owned attachment rows {mime_type, url} ⇒ image URLs for `$turn['images']`, or null when the turn must stay on php: any
	 * non-image (pdf/docx/audio…), a URL the cell cannot fetch (not absolute http(s)), or more images than the parts cap.
	 */
	public static function image_urls( array $attachments ): ?array {
		$urls = array();
		foreach ( $attachments as $a ) {
			$a   = is_array( $a ) ? $a : array();
			$url = trim( (string) ( $a['url'] ?? '' ) );
			if ( 0 !== strpos( strtolower( (string) ( $a['mime_type'] ?? '' ) ), 'image/' ) || 2 !== count( self::parts( '', array( $url ) ) ) ) {
				return null;
			}
			$urls[] = $url;
		}
		return count( $urls ) < self::PARTS_MAX ? $urls : null;
	}

	/** Recent turns, oldest first, ≤ HISTORY_MAX, text only (cell schema: role user|assistant, text ≤ 8000). */
	public static function history( array $rows ): array {
		$out = array();
		foreach ( $rows as $r ) {
			if ( ! is_array( $r ) ) {
				continue;
			}
			$role = (string) ( $r['role'] ?? '' );
			$text = trim( (string) ( $r['text'] ?? $r['content'] ?? '' ) );
			if ( '' === $text || ! in_array( $role, array( 'user', 'assistant' ), true ) ) {
				continue;
			}
			$out[] = array( 'role' => $role, 'text' => self::clip( $text ) );
		}
		return array_slice( $out, -self::HISTORY_MAX );
	}

	/* ── run ──────────────────────────────────────────────────────── */

	/**
	 * Answer one web turn through the Hub when the flag says so.
	 *
	 * `$turn['persist']` = callable( array $final_done, string $trace_id ): int — writes the site's web transcript (user +
	 * assistant rows) once the cell's `final_done` frame arrived; returns the assistant row id (0 = none).
	 *
	 * @return string|WP_REST_Response 'php' (caller runs its PHP pipeline) · 'done' (SSE written, caller must exit) · error before any byte
	 */
	public static function run( string $surface, array $turn ) {
		$user_id = (int) ( $turn['user_id'] ?? 0 );
		// PHASE-0.89 S89-B7: a surface may keep its OWN cut-over flag (Ask Brain = `brain`) while using the twinchat envelope.
		$d       = self::decide( $surface, $user_id, (string) ( $turn['flag_key'] ?? '' ) );
		self::trace( $surface, $d );
		if ( 'php' === $d['path'] ) {
			return 'php';
		}
		if ( 'error' === $d['path'] ) {
			return self::error( $d['error'] );
		}
		if ( '' === trim( (string) ( $turn['text'] ?? '' ) ) ) {
			return self::error( 'empty_message' );
		}
		$env = self::envelope( $surface, $turn, $d['principal'], (int) $d['key_id'] );
		$shape = array();
		foreach ( array( 'prelude', 'after_done' ) as $k ) {
			if ( isset( $turn[ $k ] ) && is_callable( $turn[ $k ] ) ) {
				$shape[ $k ] = $turn[ $k ];
			}
		}
		return self::pipe( $env, $d['flag'], isset( $turn['persist'] ) && is_callable( $turn['persist'] ) ? $turn['persist'] : null, $shape );
	}

	/**
	 * TRANSITION BRIDGE (remove at NV-3): Hub SSE ⇒ browser, frame by frame, bytes unchanged. Nothing is written to the
	 * browser before the first SSE frame arrived, so a Hub/cell that cannot take the turn can still fall back to php.
	 *
	 * @return string|WP_REST_Response
	 */
	/** Why the last pipe() gave up before the cell's first frame (status / code / curl errno only - never a body, a key or a UID). */
	private static $fallback = '';
	private static $errno    = 0;

	/**
	 * Shape (never content) of a non-SSE answer, for the fallback detail: content-type family + what the body looks like
	 * ("json{ok,error}", "html", "empty", "text"). Top-level JSON key NAMES only (letters, max 4) - no values.
	 */
	private static function body_shape( $st ): string {
		$ct   = strtolower( trim( explode( ';', (string) ( $st->ctype ?? '' ) )[0] ) );
		$body = ltrim( (string) $st->err );
		if ( '' === $body ) {
			$kind = 'empty';
		} elseif ( '<' === $body[0] ) {
			$kind = 'html';
		} elseif ( '{' === $body[0] || '[' === $body[0] ) {
			$j    = json_decode( $body, true );
			$keys = is_array( $j ) ? array_slice( array_filter( array_map( 'strval', array_keys( $j ) ), static function ( $k ) { return 1 === preg_match( '/^[A-Za-z_]{1,16}$/', $k ); } ), 0, 4 ) : array();
			$kind = 'json{' . implode( ',', $keys ) . '}';
		} else {
			$kind = 'text';
		}
		return '.' . ( '' !== $ct ? $ct : 'noctype' ) . '.' . $kind;
	}

	/** Compact reason of the last cell → PHP fallback, e.g. "http_0:curl28", "http_404:rest_no_route", "hub_key_missing"; '' if none. */
	public static function fallback_detail(): string {
		return self::$fallback;
	}

	private static function note_fallback( string $detail ): void {
		self::$fallback = substr( preg_replace( '/[^A-Za-z0-9_.:{},\/-]/', '', $detail ), 0, 120 );
		if ( '' !== self::$fallback && function_exists( 'error_log' ) ) {
			error_log( '[BIZCITY_TWIN_WEB_TURN] cell_fallback ' . self::$fallback );
		}
	}

	public static function pipe( array $env, string $flag, $persist = null, array $shape = array() ) {
		self::$fallback = '';
		self::$errno    = 0;
		$target = self::target();
		if ( null === $target ) {
			self::note_fallback( 'hub_key_missing' );
			return 'auto' === $flag ? 'php' : self::error( 'hub_key_unknown' );
		}
		// PHASE-0.90 S90-W2: a web-home owner/staff turn needs the cell to hold the current owner/staff block (pushed lazily,
		// at most once a minute, only when it changed; a failed push never blocks the turn - the cell then fails closed).
		if ( empty( $env['account_id'] ) && in_array( (string) ( $env['role'] ?? '' ), array( 'owner', 'staff' ), true ) && class_exists( 'BizCity_Twin_Web_Home' ) ) {
			BizCity_Twin_Web_Home::ensure_pushed();
		}
		$st = (object) array( 'opened' => false, 'buf' => '', 'err' => '', 'ctype' => '', 'ended' => false, 'fallback' => false, 'status' => 0, 'aborted' => false, 'staff' => 'staff' === ( $env['role'] ?? '' ), 'home' => empty( $env['account_id'] ), 'shape' => $shape );
		$on_bytes = static function ( string $chunk, int $status, string $ctype ) use ( $st, $flag, $persist, $env ): bool {
			$st->status = $status;
			$st->ctype  = substr( (string) $ctype, 0, 60 );
			if ( ! $st->opened ) {
				if ( 200 !== $status || false === stripos( $ctype, 'event-stream' ) || '' !== $st->err ) {
					$st->err = substr( $st->err . $chunk, 0, self::ERR_BODY_MAX );
					return true;
				}
				$st->buf .= $chunk;
				$pos = strpos( $st->buf, "\n\n" );
				if ( false === $pos ) {
					if ( strlen( $st->buf ) > self::ERR_BODY_MAX ) {
						$st->err = substr( $st->buf, 0, self::ERR_BODY_MAX );
						$st->buf = '';
					}
					return true;
				}
				$first = self::parse( substr( $st->buf, 0, $pos ) );
				if ( null === $first ) {          // not SSE (an old cell answering JSON through the Hub's SSE pipe)
					$st->err = substr( $st->buf, 0, self::ERR_BODY_MAX );
					$st->buf = '';
					return true;
				}
				if ( 'auto' === $flag && 'error' === $first['event'] && 'cell_unreachable' === (string) ( $first['data']['code'] ?? '' ) ) {
					$st->fallback = true;           // the Hub could not reach the cell: nothing was answered yet
					$st->buf      = '';
					return false;
				}
				self::open();
				$st->opened = true;
				// S89-S3 (twin-stream@1 rule 4): the ONE site frame before the cell's first byte says which path answers
				self::out( self::frame( 'twin_event', self::path_event( 'node', 'node', $flag, (string) $env['trace_id'] ) ) );
				// S89-B7: frames the surface's own UI needs before the cell's first frame (never changes a cell byte)
				self::emit_shape( $st, 'prelude', array(), (string) $env['trace_id'] );
			} else {
				$st->buf .= $chunk;
			}
			self::forward( $st, $persist, (string) $env['trace_id'] );
			if ( function_exists( 'connection_aborted' ) && connection_aborted() ) {
				$st->aborted = true;
				return false;                       // the browser left ⇒ drop the Hub stream ⇒ the cell sees the disconnect
			}
			return true;
		};
		$res = self::transport( $target, (string) self::json( $env ), $on_bytes );
		self::$errno = (int) ( $res['errno'] ?? 0 );
		if ( '' === (string) $st->ctype ) {
			$st->ctype = substr( (string) ( $res['ctype'] ?? '' ), 0, 60 ); // an empty body never reaches on_bytes, so the header is read from the transport
		}
		if ( ! $st->opened ) {
			if ( '' !== $st->buf ) {
				$st->err = substr( $st->buf, 0, self::ERR_BODY_MAX );
			}
			return self::upstream_failure( $st, (int) ( $res['status'] ?? $st->status ), $flag );
		}
		if ( '' !== $st->buf ) {
			self::out( $st->buf );
			$st->buf = '';
		}
		if ( ! $st->ended && ! $st->aborted ) {
			$e = self::ERRORS['stream_interrupted'];
			self::out( self::frame( 'error', array( 'trace_id' => (string) $env['trace_id'], 'code' => 'stream_interrupted', 'message' => $e[1], 'hint' => $e[2], 'help_code' => 'S87-WEB-SITE-STREAM', 'retryable' => true ) ) );
			self::out( self::frame( 'end', array() ) );
		}
		return 'done';
	}

	/** Write every complete frame; on `final_done` record the transcript (A4, site side) right after forwarding it. */
	private static function forward( $st, $persist, string $trace_id ): void {
		while ( false !== ( $pos = strpos( $st->buf, "\n\n" ) ) ) {
			$raw     = substr( $st->buf, 0, $pos + 2 );
			$st->buf = (string) substr( $st->buf, $pos + 2 );
			self::out( $raw );
			$f = self::parse( substr( $raw, 0, -2 ) );
			if ( ! $f ) {
				continue;
			}
			if ( 'final_done' === $f['event'] && $persist ) {
				$id = 0;
				try {
					$id = (int) call_user_func( $persist, (array) $f['data'], $trace_id );
				} catch ( \Throwable $e ) {
					$id = 0; // the answer is already on screen; a failed transcript write must not break the stream
				}
				if ( $id > 0 ) {
					self::out( self::frame( 'assistant_persisted', array( 'message_id' => $id ) ) ); // lets TwinChat pin the row
				}
			}
			// [2026-10-13 01:40 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.106 S106-C9 — an `error` frame the CELL sends mid-stream (llm_not_configured, turn_failed, busy…):
			// the surface may record it (Cell Chat writes `cell_turn_failed` into the session). A recording failure never touches the stream.
			if ( 'error' === $f['event'] && ! empty( $st->shape['on_error'] ) ) {
				try {
					call_user_func( $st->shape['on_error'], (array) $f['data'] );
				} catch ( \Throwable $e ) {
					// ignore
				}
			}
			if ( 'final_done' === $f['event'] ) {
				self::emit_shape( $st, 'after_done', (array) $f['data'], $trace_id );
			} elseif ( 'end' === $f['event'] ) {
				$st->ended = true;
			}
		}
	}

	/** Surface frames around the cell's: `prelude` () and `after_done` (final_done data). Each returns list<[event, data]>. */
	private static function emit_shape( $st, string $key, array $arg, string $trace_id ): void {
		if ( empty( $st->shape[ $key ] ) ) {
			return;
		}
		try {
			$frames = (array) ( 'prelude' === $key ? call_user_func( $st->shape[ $key ] ) : call_user_func( $st->shape[ $key ], $arg ) );
		} catch ( Throwable $e ) {
			return; // a UI nicety must never break the stream
		}
		foreach ( $frames as $fr ) {
			if ( is_array( $fr ) && isset( $fr[0] ) && is_string( $fr[0] ) ) {
				self::out( self::frame( $fr[0], (array) ( $fr[1] ?? array() ) ) );
			}
		}
	}

	/** @return string|WP_REST_Response */
	private static function upstream_failure( $st, int $status, string $flag ) {
		$auto = 'auto' === $flag;
		if ( $st->fallback ) {
			self::note_fallback( 'cell_unreachable' );
			return 'php';
		}
		$data = json_decode( trim( (string) $st->err ), true );
		$data = is_array( $data ) ? $data : array();
		$code = (string) ( $data['code'] ?? '' );
		// the reason, kept for the `path` frame and the PHP error log: HTTP status, the envelope's code, curl errno (28 = timeout)
		self::note_fallback( 'http_' . $status . ( '' !== $code ? ':' . $code : ( 200 === $status ? ':not_sse' . self::body_shape( $st ) : '' ) ) . ( self::$errno > 0 ? ':curl' . self::$errno : '' ) );
		if ( 404 === $status && 'rest_no_route' === $code ) {
			self::remember_route_missing();
			return $auto ? 'php' : self::error( 'hub_route_missing' );
		}
		if ( 'tenant_mismatch' === $code && function_exists( 'delete_transient' ) ) {
			delete_transient( self::KEY_ID_KEY ); // the site key changed since key_id was cached: learn it again next turn
		}
		// A staff turn on an older cell (no twin-agent-turn@1.3) is refused with 400 before any frame: php answers it instead.
		// PHASE-0.90: the same for a web-home turn (1.4 envelope without account_id) reaching a cell that predates it
		$old_cell_staff  = 400 === $status && ( ! empty( $st->staff ) || ! empty( $st->home ) );
		$retry_elsewhere = 0 === $status || $status >= 500 || ( 404 === $status && 'account_not_found' === $code ) || 200 === $status || 'tenant_mismatch' === $code || $old_cell_staff;
		if ( $auto && $retry_elsewhere ) {
			return 'php';
		}
		if ( '' !== $code && isset( $data['message'] ) && $status >= 400 ) {
			// The Hub already answers R-ERROR-UX in Vietnamese (S87-WEB-*): pass it on unchanged.
			return self::response( $status, $code, (string) $data['message'], (string) ( $data['hint'] ?? '' ), (string) ( $data['help_code'] ?? $code ) );
		}
		return self::error( 'hub_unreachable' );
	}

	/* ── SSE helpers ──────────────────────────────────────────────── */

	/** One SSE block ⇒ {event, data} or null when it is not SSE. Comment-only blocks are SSE (event ''). */
	public static function parse( string $block ): ?array {
		$event = '';
		$data  = '';
		$seen  = false;
		foreach ( preg_split( '/\r?\n/', $block ) as $line ) {
			if ( '' === $line ) {
				continue;
			}
			if ( 0 === strpos( $line, 'event:' ) ) {
				$event = trim( substr( $line, 6 ) );
			} elseif ( 0 === strpos( $line, 'data:' ) ) {
				$data .= ltrim( substr( $line, 5 ) );
			} elseif ( 0 !== strpos( $line, ':' ) && 0 !== strpos( $line, 'id:' ) && 0 !== strpos( $line, 'retry:' ) ) {
				return null;
			}
			$seen = true;
		}
		if ( ! $seen ) {
			return null;
		}
		$decoded = '' === $data ? array() : json_decode( $data, true );
		return array( 'event' => $event, 'data' => is_array( $decoded ) ? $decoded : array() );
	}

	/**
	 * twin-stream@1 `path` event (PHASE-0.89 S89-S3): which pipeline answers this turn. Same keys as the cell's twin_event
	 * frames so TwinChat's ingest and TwinEventTimeline read it; `seq` -1 keeps it out of the cell's monotonic counter (a
	 * client drops frames whose seq is below the last one it saw). The legacy PHP path writes it with path `legacy`.
	 */
	public static function path_event( string $path, string $reason, string $flag, string $trace_id = '', string $detail = '' ): array {
		$data = array( 'path' => $path, 'reason' => $reason, 'flag' => $flag );
		if ( '' !== $detail ) {
			$data['detail'] = $detail; // why the cell was skipped (status / code only), see fallback_detail()
		}
		return array(
			'trace_id'         => $trace_id,
			'seq'              => -1,
			'event'            => 'path',
			'event_type'       => 'path',
			'at_ms'            => 0,
			'data'             => $data,
			'event_uuid'       => function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : bin2hex( random_bytes( 16 ) ),
			'event_source'     => 'site',
			'created_epoch_ms' => (int) floor( microtime( true ) * 1000 ),
			'payload'          => array( 'stage' => 'path' ) + $data,
		);
	}

	public static function frame( string $event, array $data ): string {
		return 'event: ' . $event . "\ndata: " . self::json( (object) $data ) . "\n\n";
	}

	/* ── errors (R-ERROR-UX) ──────────────────────────────────────── */

	public static function error( string $code ): WP_REST_Response {
		$e = self::ERRORS[ $code ] ?? self::ERRORS['hub_unreachable'];
		return self::response( $e[0], $code, $e[1], $e[2], 'S87-WEB-SITE-' . strtoupper( str_replace( '_', '-', $code ) ) );
	}

	private static function response( int $status, string $code, string $message, string $hint, string $help_code ): WP_REST_Response {
		return new WP_REST_Response( array( 'ok' => false, 'code' => $code, 'message' => $message, 'hint' => $hint, 'help_code' => $help_code ), $status );
	}

	/* ── helpers ──────────────────────────────────────────────────── */

	private static function as_role( string $kind, string $account, string $role, string $evidence, array $p ): array {
		$owner_like = in_array( $role, array( 'owner', 'staff' ), true );
		return array(
			'kind'          => $kind,
			'account_id'    => $account,
			'role'          => $role,
			'role_evidence' => $evidence,
			'modes'         => $owner_like ? array_values( array_map( 'strval', (array) ( $p['modes'] ?? array() ) ) ) : array(),
			'user_hash'     => $owner_like ? strtolower( (string) ( $p['user_hash'] ?? '' ) ) : '',
		);
	}

	private static function default_account( array $accounts ): string {
		$pick = (string) self::option( self::OPTION_ACCOUNT, '' );
		return '' !== $pick && in_array( $pick, $accounts, true ) ? $pick : (string) reset( $accounts );
	}

	/** The cell accepts session ids `[A-Za-z0-9._:-]{1,100}`; anything else is replaced by a stable hash. */
	public static function session_id( string $sid ): string {
		$sid = trim( $sid );
		return preg_match( '/^[A-Za-z0-9._:-]{1,100}$/', $sid ) ? $sid : 's_' . substr( sha1( '' === $sid ? self::trace_id() : $sid ), 0, 32 );
	}

	private static function clip( string $s ): string {
		return function_exists( 'mb_substr' ) ? mb_substr( $s, 0, self::TEXT_MAX ) : substr( $s, 0, self::TEXT_MAX );
	}

	private static function json( $v ): string {
		$flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
		return (string) ( function_exists( 'wp_json_encode' ) ? wp_json_encode( $v, $flags ) : json_encode( $v, $flags ) );
	}

	private static function route_missing(): bool {
		return function_exists( 'get_transient' ) && (bool) get_transient( self::NO_ROUTE_KEY );
	}

	private static function remember_route_missing(): void {
		if ( function_exists( 'set_transient' ) ) {
			set_transient( self::NO_ROUTE_KEY, 1, self::NO_ROUTE_TTL );
		}
	}

	/** Trace of the decision only — never a UID, a hash or the message (R-DDV, R-PRIVACY). */
	private static function trace( string $surface, array $d ): void {
		if ( function_exists( 'do_action' ) ) {
			do_action( 'bizcity_twin_web_turn_decided', array( 'surface' => $surface, 'path' => $d['path'], 'reason' => $d['reason'], 'flag' => $d['flag'], 'role' => isset( $d['principal'] ) ? $d['principal']['role'] : '' ) );
		}
	}

	/* ── readers (server facts) ───────────────────────────────────── */

	private static function option( string $name, $default ) {
		if ( isset( self::$readers['option'] ) ) {
			return call_user_func( self::$readers['option'], $name, $default );
		}
		return function_exists( 'get_option' ) ? get_option( $name, $default ) : $default;
	}

	/** @return string[] zalo-hub bridge ids of this site */
	private static function accounts(): array {
		$rows = isset( self::$readers['accounts'] )
			? (array) call_user_func( self::$readers['accounts'] )
			: ( class_exists( 'BizCity_Zalo_Hub_Config_Sync' ) ? BizCity_Zalo_Hub_Config_Sync::accounts() : array() );
		$out = array();
		foreach ( $rows as $r ) {
			$id = is_array( $r ) ? (string) ( $r['bridge_id'] ?? '' ) : (string) $r;
			if ( '' !== $id ) {
				$out[] = $id;
			}
		}
		return array_values( array_unique( $out ) );
	}

	private static function principals( string $bridge ): array {
		if ( isset( self::$readers['principals'] ) ) {
			return (array) call_user_func( self::$readers['principals'], $bridge );
		}
		return class_exists( 'BizCity_Zalo_Agent_Principals' ) ? BizCity_Zalo_Agent_Principals::principals( $bridge ) : array();
	}

	private static function user_hash( int $user_id ): string {
		if ( isset( self::$readers['user_hash'] ) ) {
			return (string) call_user_func( self::$readers['user_hash'], $user_id );
		}
		return class_exists( 'BizCity_Agent_Mode_Access' ) ? BizCity_Agent_Mode_Access::user_hash( $user_id ) : '';
	}

	private static function staff_supported(): bool {
		if ( isset( self::$readers['staff_supported'] ) ) {
			return (bool) call_user_func( self::$readers['staff_supported'] );
		}
		return ! function_exists( 'apply_filters' ) || (bool) apply_filters( self::FILTER_STAFF, true );
	}

	/** This blog's 1API key id at the Hub (cached 12 h; learnt from the Hub's /zalo-personal-bridge/health). */
	private static function key_id(): int {
		if ( isset( self::$readers['key_id'] ) ) {
			return (int) call_user_func( self::$readers['key_id'] );
		}
		$cached = function_exists( 'get_transient' ) ? (int) get_transient( self::KEY_ID_KEY ) : 0;
		if ( $cached > 0 || ! class_exists( 'BizCity_Zalo_Personal_Hub_Client' ) ) {
			return $cached;
		}
		$h  = BizCity_Zalo_Personal_Hub_Client::instance()->health();
		$id = (int) ( $h['key_id'] ?? 0 );
		if ( $id > 0 && function_exists( 'set_transient' ) ) {
			set_transient( self::KEY_ID_KEY, $id, self::KEY_ID_TTL );
		}
		return $id;
	}

	private static function target(): ?array {
		if ( isset( self::$readers['target'] ) ) {
			$t = call_user_func( self::$readers['target'] );
			return is_array( $t ) ? $t : null;
		}
		return class_exists( 'BizCity_Zalo_Personal_Hub_Client' ) && method_exists( 'BizCity_Zalo_Personal_Hub_Client', 'stream_target' )
			? BizCity_Zalo_Personal_Hub_Client::instance()->stream_target( self::HUB_PATH )
			: null;
	}

	private static function guru_ref( string $bridge ): string {
		if ( isset( self::$readers['guru_ref'] ) ) {
			return (string) call_user_func( self::$readers['guru_ref'], $bridge );
		}
		$b  = class_exists( 'BizCity_Channel_Binding' ) ? BizCity_Channel_Binding::resolve( 'ZALO_PERSONAL', $bridge ) : null;
		$id = is_array( $b ) ? (int) ( $b['character_id'] ?? 0 ) : 0;
		return $id > 0 ? 'guru:' . $id : '';
	}

	private static function trace_id(): string {
		if ( isset( self::$readers['trace_id'] ) ) {
			return (string) call_user_func( self::$readers['trace_id'] );
		}
		return 'TW' . strtoupper( bin2hex( random_bytes( 12 ) ) );
	}

	private static function now(): int {
		return isset( self::$readers['now'] ) ? (int) call_user_func( self::$readers['now'] ) : time();
	}

	/* ── transport (transition bridge) ────────────────────────────── */

	/** @return array{ok:bool,status:int} */
	private static function transport( array $target, string $body, callable $on_bytes ): array {
		if ( isset( self::$readers['transport'] ) ) {
			return (array) call_user_func( self::$readers['transport'], $target, $body, $on_bytes );
		}
		if ( ! function_exists( 'curl_init' ) ) {
			return array( 'ok' => false, 'status' => 0 );
		}
		$status  = 0;
		$ctype   = '';
		$headers = array(
			'Authorization: Bearer ' . (string) $target['key'],
			'Content-Type: application/json',
			'Accept: text/event-stream',
			'X-Site-URL: ' . ( function_exists( 'home_url' ) ? home_url() : '' ),
		);
		foreach ( (array) ( $target['headers'] ?? array() ) as $k => $v ) {
			$headers[] = $k . ': ' . $v;
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( self::TIMEOUT + 10 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		ignore_user_abort( true ); // keep running long enough to write the transcript; aborts are detected per chunk
		$ch = curl_init( (string) $target['url'] );
		curl_setopt_array( $ch, array(
			CURLOPT_POST           => true,
			CURLOPT_POSTFIELDS     => $body,
			CURLOPT_HTTPHEADER     => $headers,
			CURLOPT_TIMEOUT        => self::TIMEOUT,
			CURLOPT_CONNECTTIMEOUT => 5,
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_HEADERFUNCTION => static function ( $ch, $line ) use ( &$status, &$ctype ) {
				if ( preg_match( '#^HTTP/\S+\s+(\d{3})#', $line, $m ) ) {
					$status = (int) $m[1];
					$ctype  = '';
				} elseif ( 0 === stripos( $line, 'content-type:' ) ) {
					$ctype = trim( substr( $line, 13 ) );
				}
				return strlen( $line );
			},
			CURLOPT_WRITEFUNCTION  => static function ( $ch, $chunk ) use ( &$status, &$ctype, $on_bytes ) {
				return $on_bytes( (string) $chunk, (int) $status, (string) $ctype ) ? strlen( $chunk ) : 0;
			},
		) );
		$ok    = curl_exec( $ch );
		$errno = (int) curl_errno( $ch );
		curl_close( $ch );
		return array( 'ok' => false !== $ok, 'status' => (int) $status, 'errno' => $errno, 'ctype' => (string) $ctype );
	}

	private static function open(): void {
		if ( isset( self::$readers['open'] ) ) {
			call_user_func( self::$readers['open'] );
			return;
		}
		if ( ! headers_sent() ) {
			header_remove( 'Content-Type' );
			header_remove( 'Content-Length' );
			header( 'Content-Type: text/event-stream; charset=UTF-8' );
			header( 'Cache-Control: no-cache, no-store, no-transform, must-revalidate' );
			header( 'X-Accel-Buffering: no' );
			header( 'Content-Encoding: none' );
		}
		while ( ob_get_level() > 0 ) {
			@ob_end_clean(); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		// Same 4 KB primer as the TwinChat PHP stream (proxies that wait for a minimum body before flushing).
		self::out( ": sse-open\n" . str_repeat( ' ', 4096 ) . "\n\n" );
	}

	private static function out( string $bytes ): void {
		if ( isset( self::$readers['out'] ) ) {
			call_user_func( self::$readers['out'], $bytes );
			return;
		}
		echo $bytes; // phpcs:ignore WordPress.Security.EscapeOutput -- SSE bytes from the cell (unchanged) or site frames built above
		flush();
	}
}
