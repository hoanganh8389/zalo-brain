<?php
/**
 * `POST bizcity-channel/v1/usage/ledger-ingest` — the site receives its own client ledger rows (PHASE-0.107 S107-L10,
 * contract usage-ledger@1, fixture zalo-hub/contracts/fixtures/s107/usage-ledger.v1.json).
 *
 * Path: cell outbox `client_ledger` → Hub `POST /zalo-hub/usage-ledger` (pure forwarder, stores nothing) → here.
 * Auth = exactly the turn-complete check: the Bearer must equal BizCity_Zalo_Bridge_Client::expected_inbound_token() of the
 * number the Hub authenticated with. That number is named by header `X-BizCity-Auth-Account` (a web account `web-<key>` has
 * no bridge row, so the cell picks any active number of the same tenant); without the header it is the body `auth_account_id`,
 * then `account_id`. `X-BizCity-Managed-Bridge` is informational, exactly as turn-complete ignores it.
 *
 * One batch = one account, ordered by account_seq. Continuity: rows ≤ the stored head are duplicates (INSERT IGNORE on
 * UNIQUE(account_id, account_seq)); a row past head+1 is a gap ⇒ nothing after it is stored and the answer carries
 * `gap_from_account_seq` so the cell resends from there. The cell is the source of truth for tokens and tool counts.
 *
 * Who a row belongs to is resolved ONCE, here, and frozen:
 *   bound_user_id = owner_user_id of the number (find_account_by_bridge_id('personal', id)); a web account: the actor;
 *   actor_user_id = the owner/staff whose user_hash it is (Principals::by_hash; web: Twin_Web_Home::principal_by_hash); customers 0.
 * An unknown account keeps its rows (bound_user_id 0, resolve_state 'unknown_account') and logs one warning.
 * Only whitelisted billed columns are stored: never cost, real tokens, markup, raw hashes or message text.
 *
 * // [2026-10-13 01:08 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.107 S107-L10 — ledger ingest (continuity, gap, user resolution, tool pricing).
 *
 * @package BizCity_Twin_AI
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_Usage_Ingest {

	const NS        = 'bizcity-channel/v1';
	const CONTRACT  = 'usage-ledger@1';
	const MAX_ROWS  = 200;
	// [2026-10-15 04:00 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.112 S112-L1a — `activity`: hàng 0 token của cell khi tin ĐÃ tới / không tới kênh. Site cũ gặp kind lạ vẫn lưu thành `adjust` (không gãy), site mới giữ đúng nhãn.
	const KINDS     = array( 'llm', 'tool', 'adjust', 'reversal', 'activity' );
	const ROLES     = array( 'owner', 'staff', 'customer', 'system' );
	const ID_RE     = '/^[A-Za-z0-9._:-]{1,64}$/';

	/**
	 * Test seams: token(account): string · owner(account): int · principal(account, hash): int · web_principal(hash): int ·
	 * head(account): int · insert(row): bool (true = inserted) · refresh_daily(day, account): void · prices(): array ·
	 * log(message, context): void · now(): int · tz(): DateTimeZone
	 *
	 * @var array<string,callable>
	 */
	public static $readers = array();

	private static $initialized = false;

	public static function init(): void {
		if ( self::$initialized ) {
			return;
		}
		self::$initialized = true;
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes(): void {
		register_rest_route( self::NS, '/usage/ledger-ingest', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'handle' ),
			'permission_callback' => '__return_true', // per-account Bearer verified in handler (same as turn-complete)
		) );
	}

	public static function handle( WP_REST_Request $request ): WP_REST_Response {
		$body    = $request->get_json_params();
		$body    = is_array( $body ) ? $body : array();
		$auth    = self::clean_id( (string) $request->get_header( 'x_bizcity_auth_account' ) );
		if ( '' === $auth ) {
			$auth = self::clean_id( (string) ( $body['auth_account_id'] ?? '' ) );
		}
		if ( '' === $auth ) {
			$auth = self::clean_id( (string) ( $body['account_id'] ?? '' ) );
		}
		$token  = '' !== $auth ? self::token( $auth ) : '';
		$header = (string) $request->get_header( 'authorization' );
		$bearer = stripos( $header, 'Bearer ' ) === 0 ? trim( substr( $header, 7 ) ) : '';
		if ( '' === $token || '' === $bearer || ! hash_equals( $token, $bearer ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'unauthorized' ), 401 );
		}
		$account = self::clean_id( (string) ( $body['account_id'] ?? '' ) );
		if ( 0 !== strpos( (string) ( $body['contract'] ?? '' ), self::CONTRACT ) || '' === $account || ! isset( $body['rows'] ) || ! is_array( $body['rows'] ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'invalid_param', 'message' => 'contract, account_id and rows are required.' ), 400 );
		}
		if ( count( $body['rows'] ) > self::MAX_ROWS ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'invalid_param', 'message' => 'at most ' . self::MAX_ROWS . ' rows per batch.' ), 400 );
		}
		$rows = array();
		foreach ( $body['rows'] as $r ) {
			if ( ! is_array( $r ) ) {
				continue;
			}
			$seq = (int) ( $r['account_seq'] ?? 0 );
			if ( $seq <= 0 ) {
				return new WP_REST_Response( array( 'ok' => false, 'code' => 'invalid_param', 'message' => 'every row needs account_seq > 0.' ), 400 );
			}
			$row_account = self::clean_id( (string) ( $r['account_id'] ?? $account ) );
			if ( $row_account !== $account ) {
				return new WP_REST_Response( array( 'ok' => false, 'code' => 'invalid_param', 'message' => 'one batch = one account_id.' ), 400 );
			}
			$rows[ $seq ] = $r; // a seq repeated inside the batch counts once
		}
		ksort( $rows );
		if ( ! isset( self::$readers['head'] ) && class_exists( 'BizCity_Usage_Schema' ) ) {
			BizCity_Usage_Schema::maybe_install();
		}
		$answer = self::ingest( $account, array_values( $rows ) );
		// [2026-10-13 03:30 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.107 round 5 — new ledger rows may push a plan account over its allowance: run the plan
		// enforcement for THIS account, and only when it has plan_mode on (the batch no longer carries any token figure; the sum comes from our own ledger).
		if ( (int) $answer['inserted'] > 0 && class_exists( 'BizCity_Usage_Plan_Enforce' ) ) {
			BizCity_Usage_Plan_Enforce::on_ingest( $account );
		}
		// [2026-10-13 Johnny Chu - Chu Hoàng Anh] PHASE-0.108 S108-L3 — one hook for anything that needs to react
		// to new ledger rows for this account (today: the brain-journey evaluator). No existing hook to reuse.
		if ( (int) $answer['inserted'] > 0 ) {
			do_action( 'bizcity_usage_ledger_ingested', $account, $answer );
		}
		return new WP_REST_Response( $answer, 200 );
	}

	/**
	 * Store one batch (rows sorted by account_seq, all of `$account`).
	 *
	 * @return array<string,mixed> usage-ledger@1 answer
	 */
	public static function ingest( string $account, array $rows ): array {
		$head     = self::head( $account );
		$expected = $head + 1;
		$inserted = 0;
		$dup      = 0;
		$gap      = null;
		$touched  = array();
		$ctx      = array( 'account' => $account, 'web' => 0 === strpos( $account, 'web-' ), 'owner' => null, 'actors' => array(), 'warned' => false );
		foreach ( $rows as $r ) {
			$seq = (int) $r['account_seq'];
			if ( $seq < $expected ) {
				++$dup; // already stored (the head is always contiguous: nothing is ever stored past a gap)
				continue;
			}
			if ( $seq > $expected ) {
				$gap = $expected;
				break;
			}
			$rec = self::project( $r, $ctx );
			if ( self::insert( $rec ) ) {
				++$inserted;
			} else {
				++$dup;
			}
			$touched[ $rec['occurred_day'] ] = true;
			$expected = $seq + 1;
		}
		foreach ( array_keys( $touched ) as $day ) {
			self::refresh_daily( (string) $day, $account );
		}
		$out = array(
			'ok'                   => true,
			'contract'             => self::CONTRACT,
			'account_id'           => $account,
			'acked_to_account_seq' => $expected - 1,
			'inserted'             => $inserted,
			'duplicate'            => $dup,
		);
		if ( null !== $gap ) {
			$out['gap_from_account_seq'] = $gap;
		}
		return $out;
	}

	/**
	 * One wire row ⇒ one stored row: whitelisted, cleaned, users resolved (frozen), tool priced.
	 *
	 * @param array<string,mixed> $r
	 * @param array<string,mixed> $ctx per-batch lookup cache
	 * @return array<string,mixed>
	 */
	public static function project( array $r, array &$ctx ): array {
		$now  = self::now();
		$at   = isset( $r['at'] ) ? strtotime( (string) $r['at'] ) : false;
		$at   = ( false === $at || $at <= 0 ) ? $now : (int) $at;
		$kind = sanitize_key( (string) ( $r['kind'] ?? 'llm' ) );
		$kind = in_array( $kind, self::KINDS, true ) ? $kind : 'adjust';
		$role = in_array( $r['actor_role'] ?? '', self::ROLES, true ) ? (string) $r['actor_role'] : 'customer';
		$hash = strtolower( trim( (string) ( $r['actor_user_hash'] ?? '' ) ) );
		$hash = 1 === preg_match( '/^[0-9a-f]{64}$/', $hash ) ? $hash : '';
		// [2026-10-13 01:45 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.107 S107-L10 — the cell sends its one trace hash `h:` + 16 hex
		// (contactOut().platform_ref), not 64 hex: drop the `h:` prefix so customers keep a distinct contact_key.
		$cref = (string) preg_replace( '/^h:/', '', strtolower( trim( (string) ( $r['contact_ref'] ?? '' ) ) ) );
		$model = substr( self::text( (string) ( $r['model'] ?? '' ) ), 0, 120 );
		$tool  = substr( (string) preg_replace( '/[^A-Za-z0-9._:\/-]/', '', (string) ( $r['tool'] ?? '' ) ), 0, 80 );
		$units = is_numeric( $r['units'] ?? null ) ? (float) $r['units'] : 0.0;

		$actor = self::actor_user( $role, $hash, $ctx );
		$bound = $ctx['web'] ? $actor : self::bound_user( $ctx );
		$state = 'ok';
		if ( ! $ctx['web'] && $bound <= 0 ) {
			$state = 'unknown_account';
			if ( ! $ctx['warned'] ) {
				$ctx['warned'] = true;
				self::log( 'usage-ledger@1: account not found on this site; rows kept with bound_user_id 0.', array( 'account_id' => $ctx['account'] ) );
			}
		}

		$billed_usd = isset( $r['billed_usd'] ) && is_numeric( $r['billed_usd'] ) ? number_format( (float) $r['billed_usd'], 8, '.', '' ) : null;
		$source     = 'cell';
		$version    = '';
		if ( 'tool' === $kind && null === $billed_usd ) {
			$provider = false !== strpos( $model, '/' ) ? strtolower( (string) strtok( $model, '/' ) ) : '';
			$p          = BizCity_Usage_Prices::price( self::prices(), $tool, $provider, $units, $at );
			$billed_usd = $p['billed_usd'];
			$source     = $p['price_source'];
			$version    = $p['price_version'];
		}

		return array(
			'account_id'    => $ctx['account'],
			'account_seq'   => (int) $r['account_seq'],
			'row_ref'       => 1 === preg_match( '/^[0-9a-f]{4,16}$/', strtolower( (string) ( $r['row_ref'] ?? '' ) ) ) ? strtolower( (string) $r['row_ref'] ) : '',
			'kind'          => $kind,
			'occurred_at'   => gmdate( 'Y-m-d H:i:s', $at ),
			'occurred_day'  => self::local_day( $at ),
			'platform'      => substr( sanitize_key( (string) ( $r['platform'] ?? '' ) ), 0, 24 ),
			'channel_ref'   => substr( self::text( (string) ( $r['channel_ref'] ?? '' ) ), 0, 96 ),
			'bound_user_id' => max( 0, $bound ),
			'actor_role'    => $role,
			'actor_user_id' => max( 0, $actor ),
			// R-CID-11: only a short prefix of the cell contact hash is kept (display "Khách #a1b2"), never the 64-hex.
			'contact_key'   => 1 === preg_match( '/^[0-9a-f]{12,}$/', $cref ) ? substr( $cref, 0, 12 ) : '',
			'thread_ref'    => substr( self::text( (string) ( $r['thread_ref'] ?? '' ) ), 0, 96 ),
			'turn_ref'      => substr( self::text( (string) ( $r['turn_ref'] ?? '' ) ), 0, 96 ),
			'surface'       => self::tag( (string) ( $r['surface'] ?? '' ) ), // [2026-10-13 06:14 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.107 S107-L42 — keeps gateway:<caller>
			'model'         => $model,
			'tool'          => $tool,
			'tool_kind'     => in_array( $r['tool_kind'] ?? '', array( 'builtin', 'mcp' ), true ) ? (string) $r['tool_kind'] : '',
			'units'         => number_format( $units, 4, '.', '' ),
			'billed_tokens' => (int) ( $r['billed_tokens'] ?? 0 ),
			'billed_usd'    => $billed_usd,
			'price_source'  => $source,
			'price_version' => $version,
			'is_error'      => empty( $r['is_error'] ) ? 0 : 1,
			'resolve_state' => $state,
			'received_at'   => gmdate( 'Y-m-d H:i:s', $now ),
		);
	}

	/* ── user resolution (frozen at receive time) ───────────────────── */

	private static function bound_user( array &$ctx ): int {
		if ( null === $ctx['owner'] ) {
			$ctx['owner'] = self::owner( $ctx['account'] );
		}
		return (int) $ctx['owner'];
	}

	private static function actor_user( string $role, string $hash, array &$ctx ): int {
		if ( ! in_array( $role, array( 'owner', 'staff' ), true ) ) {
			return 0; // customers and system rows never map to a WP user
		}
		$key = $role . '|' . $hash;
		if ( isset( $ctx['actors'][ $key ] ) ) {
			return $ctx['actors'][ $key ];
		}
		$uid = 0;
		if ( '' !== $hash ) {
			$uid = $ctx['web'] ? self::web_principal( $hash ) : self::principal( $ctx['account'], $hash );
		}
		if ( $uid <= 0 && 'owner' === $role && ! $ctx['web'] ) {
			$uid = self::bound_user( $ctx ); // same rule as turn-complete: the owner role is the number's owner
		}
		// an unknown staff hash stays 0 — never filed under the owner
		return $ctx['actors'][ $key ] = max( 0, (int) $uid );
	}

	/* ── helpers ───────────────────────────────────────────────────── */

	public static function clean_id( string $raw ): string {
		$s = trim( $raw );
		return 1 === preg_match( self::ID_RE, $s ) ? $s : '';
	}

	// [2026-10-13 06:14 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.107 S107-L42 — lowercase [a-z0-9_.:-], max 96 (sanitize_key would strip ':' and '.').
	private static function tag( string $s ): string {
		return substr( (string) preg_replace( '/[^a-z0-9_.:-]/', '', strtolower( trim( $s ) ) ), 0, 96 );
	}

	private static function text( string $s ): string {
		return function_exists( 'sanitize_text_field' ) ? sanitize_text_field( $s ) : trim( (string) preg_replace( '/[\x00-\x1F<>]/', '', $s ) );
	}

	private static function local_day( int $ts ): string {
		$tz = isset( self::$readers['tz'] ) ? call_user_func( self::$readers['tz'] ) : ( function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' ) );
		$dt = new DateTime( '@' . $ts );
		$dt->setTimezone( $tz instanceof DateTimeZone ? $tz : new DateTimeZone( 'UTC' ) );
		return $dt->format( 'Y-m-d' );
	}

	private static function now(): int {
		return isset( self::$readers['now'] ) ? (int) call_user_func( self::$readers['now'] ) : time();
	}

	private static function token( string $account ): string {
		if ( isset( self::$readers['token'] ) ) {
			return (string) call_user_func( self::$readers['token'], $account );
		}
		return class_exists( 'BizCity_Zalo_Bridge_Client' ) ? (string) BizCity_Zalo_Bridge_Client::instance()->expected_inbound_token( $account ) : '';
	}

	private static function owner( string $account ): int {
		if ( isset( self::$readers['owner'] ) ) {
			return (int) call_user_func( self::$readers['owner'], $account );
		}
		$acc = class_exists( 'BizCity_Zalo_Mapping_Repo' ) ? BizCity_Zalo_Mapping_Repo::find_account_by_bridge_id( 'personal', $account ) : null;
		return is_array( $acc ) ? (int) ( $acc['owner_user_id'] ?? 0 ) : 0;
	}

	private static function principal( string $account, string $hash ): int {
		if ( isset( self::$readers['principal'] ) ) {
			return (int) call_user_func( self::$readers['principal'], $account, $hash );
		}
		$p = class_exists( 'BizCity_Zalo_Agent_Principals' ) ? BizCity_Zalo_Agent_Principals::by_hash( $account, $hash ) : null;
		return is_array( $p ) ? (int) ( $p['user_id'] ?? 0 ) : 0;
	}

	private static function web_principal( string $hash ): int {
		if ( isset( self::$readers['web_principal'] ) ) {
			return (int) call_user_func( self::$readers['web_principal'], $hash );
		}
		$p = class_exists( 'BizCity_Twin_Web_Home' ) ? BizCity_Twin_Web_Home::principal_by_hash( $hash ) : null;
		return is_array( $p ) ? (int) ( $p['user_id'] ?? 0 ) : 0;
	}

	private static function prices(): array {
		if ( isset( self::$readers['prices'] ) ) {
			return (array) call_user_func( self::$readers['prices'] );
		}
		return BizCity_Usage_Prices::table();
	}

	private static function log( string $message, array $context ): void {
		if ( isset( self::$readers['log'] ) ) {
			call_user_func( self::$readers['log'], $message, $context );
			return;
		}
		if ( class_exists( 'BizCity_Channel_File_Logger' ) && method_exists( 'BizCity_Channel_File_Logger', 'write' ) ) {
			BizCity_Channel_File_Logger::write( BizCity_Channel_File_Logger::CH_CHANNEL_GATEWAY, BizCity_Channel_File_Logger::LEVEL_WARN, 'usage_ledger_unknown_account', $message, $context );
			return;
		}
		error_log( '[bizcity-usage] ' . $message . ' ' . wp_json_encode( $context ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
	}

	private static function head( string $account ): int {
		if ( isset( self::$readers['head'] ) ) {
			return (int) call_user_func( self::$readers['head'], $account );
		}
		global $wpdb;
		$t = BizCity_Usage_Schema::ledger_table();
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT MAX(account_seq) FROM {$t} WHERE account_id = %s", $account ) );
	}

	private static function insert( array $row ): bool {
		if ( isset( self::$readers['insert'] ) ) {
			return (bool) call_user_func( self::$readers['insert'], $row );
		}
		global $wpdb;
		$t      = BizCity_Usage_Schema::ledger_table();
		$cols   = array_keys( $row );
		$values = array();
		$marks  = array();
		foreach ( $row as $v ) {
			if ( null === $v ) {
				$marks[] = 'NULL';
				continue;
			}
			$marks[]  = is_int( $v ) ? '%d' : '%s';
			$values[] = $v;
		}
		$sql = "INSERT IGNORE INTO {$t} (`" . implode( '`,`', $cols ) . '`) VALUES (' . implode( ',', $marks ) . ')';
		$wpdb->query( $wpdb->prepare( $sql, $values ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		return (int) $wpdb->rows_affected > 0;
	}

	/** Rebuild the daily groups of one (site-local day, account) from the ledger (idempotent upsert). */
	private static function refresh_daily( string $day, string $account ): void {
		if ( isset( self::$readers['refresh_daily'] ) ) {
			call_user_func( self::$readers['refresh_daily'], $day, $account );
			return;
		}
		global $wpdb;
		$l   = BizCity_Usage_Schema::ledger_table();
		$d   = BizCity_Usage_Schema::daily_table();
		$now = gmdate( 'Y-m-d H:i:s', self::now() );
		$sql = "INSERT INTO {$d} (day, platform, account_id, bound_user_id, actor_role, actor_user_id, kind, billed_tokens, billed_usd, turns, threads, tool_calls, updated_at)
			SELECT occurred_day, platform, account_id, bound_user_id, actor_role, actor_user_id, kind,
				SUM(billed_tokens), COALESCE(SUM(billed_usd),0), COUNT(DISTINCT NULLIF(turn_ref,'')), COUNT(DISTINCT NULLIF(thread_ref,'')),
				SUM(CASE WHEN kind = 'tool' THEN 1 ELSE 0 END), %s
			FROM {$l} WHERE occurred_day = %s AND account_id = %s
			GROUP BY occurred_day, platform, account_id, bound_user_id, actor_role, actor_user_id, kind
			ON DUPLICATE KEY UPDATE billed_tokens = VALUES(billed_tokens), billed_usd = VALUES(billed_usd), turns = VALUES(turns),
				threads = VALUES(threads), tool_calls = VALUES(tool_calls), updated_at = VALUES(updated_at)";
		$wpdb->query( $wpdb->prepare( $sql, $now, $day, $account ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}
}
