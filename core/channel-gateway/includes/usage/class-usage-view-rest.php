<?php
/**
 * Menu "Sử dụng" REST — contract usage-view@1, read from the site's LOCAL copy of its client ledger
 * (PHASE-0.107 S107-L2' + L13, docs 20 §3, 62 §5.4, 64 §4). Namespace bizcity-channel/v1.
 *
 *   GET usage/summary?range=today|24h|7d|30d|prev_month&view=all|mine
 *   GET usage/by-day · usage/by-channel · usage/by-model · usage/recent?limit= · usage/by-person · usage/sessions · usage/tools
 *   GET usage/account/{id}/{summary|by-day|people|sessions|turns|tools}   (+ ?format=csv, streamed in batches)
 *
 * Tokens, turns, sessions, tools and ₫ come from `bizcity_usage_ledger` / `bizcity_usage_daily`. Only the balance, the budget
 * state and the Hub's USD→VND rate are read from the Hub (`/account/info` with the site's OWN key — main-site fallback OFF),
 * cached 60 s. No key ⇒ summary answers {state:"no_key"}.
 *
 * Visibility (62 §5.4), always in SQL: manage rights + view=all ⇒ everything; otherwise rows where bound_user_id = me (my
 * numbers, every actor) OR actor_user_id = me (staff: their own rows). Customers have no rows.
 * Never output cost, real tokens, markup, a user hash, a contact hash or any 64-hex string: contacts show as "Khách #a1b2",
 * sessions as a short `session_ref`, numbers masked (0562•••899); the full number only for a site admin with ?reveal=1.
 *
 * // [2026-10-13 01:08 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.107 S107-L2' / S107-L13 — usage-view@1 from the local ledger copy.
 *
 * @package BizCity_Twin_AI
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_Usage_View_REST {

	const NS        = 'bizcity-channel/v1';
	const CONTRACT  = 'usage-view@1';
	const HUB_CACHE = 'bizcity_usage_hub_view';
	const HUB_TTL   = 60;
	const RANGES    = array( 'today', '24h', '7d', '30d', 'prev_month' );
	const PARTS     = array( 'summary', 'by-day', 'people', 'sessions', 'turns', 'tools' );
	const CSV_BATCH = 500;

	/** Keys that must never reach the browser (R-USAGE-ONE-LEDGER §5; the selfcheck fails on any of them or on a 64-hex). */
	const FORBIDDEN = array( 'provider_cost_usd', 'commission_usd', 'markup_pct', 'tokens_prompt', 'tokens_completion', 'total_tokens', 'real_tokens', 'real_usd', 'cost_usd', 'actor_user_hash', 'contact_ref', 'user_hash', 'channel_ref', 'thread_ref' );

	/**
	 * Test seams: viewer(): int · is_admin(): bool · hub(): array{state,balance_usd,usd_vnd_rate,budget_state} ·
	 * rows(sql, args): array · account(id): array · name(user_id): string · now(): int · csv(filename, rows): void
	 *
	 * @var array<string,callable>
	 */
	public static $readers = array();

	private static $initialized = false;
	private static $names       = array();
	private static $accounts    = array();

	public static function init(): void {
		if ( self::$initialized ) {
			return;
		}
		self::$initialized = true;
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes(): void {
		$perm = array( __CLASS__, 'can_read' );
		$map  = array(
			'summary'    => 'route_summary',
			'by-day'     => 'route_by_day',
			'by-channel' => 'route_by_channel',
			'by-model'   => 'route_by_model',
			'recent'     => 'route_recent',
			'by-person'  => 'route_by_person',
			'sessions'   => 'route_sessions',
			'tools'      => 'route_tools',
		);
		foreach ( $map as $slug => $cb ) {
			register_rest_route( self::NS, '/usage/' . $slug, array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, $cb ),
				'permission_callback' => $perm,
			) );
		}
		register_rest_route( self::NS, '/usage/account/(?P<id>[A-Za-z0-9._:-]{1,64})/(?P<part>summary|by-day|people|sessions|turns|tools)', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'route_account' ),
			'permission_callback' => $perm,
		) );
	}

	public static function can_read() {
		if ( self::viewer() <= 0 ) {
			return new WP_Error( 'rest_forbidden', 'Login required.', array( 'status' => 401 ) );
		}
		return true;
	}

	/* ── visibility + range (pure, unit-tested) ─────────────────────── */

	/**
	 * SQL fragment that limits ledger/daily rows to what this viewer may see (62 §5.4).
	 *
	 * @return array{0:string,1:array<int,int>}
	 */
	public static function scope( int $viewer, bool $is_admin, string $view ): array {
		if ( $viewer <= 0 ) {
			return array( '1=0', array() );
		}
		if ( $is_admin && 'mine' !== $view ) {
			return array( '1=1', array() );
		}
		return array( '(bound_user_id = %d OR actor_user_id = %d)', array( $viewer, $viewer ) );
	}

	/**
	 * @return array{key:string,from:string,to:string,day_from:string,day_to:string,aligned:bool}
	 */
	public static function range( string $key, int $now, DateTimeZone $tz ): array {
		$key   = in_array( $key, self::RANGES, true ) ? $key : '7d';
		$local = new DateTime( '@' . $now );
		$local->setTimezone( $tz );
		$midnight = new DateTime( $local->format( 'Y-m-d' ) . ' 00:00:00', $tz );
		$to       = new DateTime( '@' . ( $now + 1 ) );
		$aligned  = true;
		switch ( $key ) {
			case 'today':
				$from = clone $midnight;
				break;
			case '24h':
				$from    = new DateTime( '@' . ( $now - 86400 ) );
				$aligned = false;
				break;
			case '30d':
				$from = ( clone $midnight )->modify( '-29 days' );
				break;
			case 'prev_month':
				$from = new DateTime( $local->format( 'Y-m-01' ) . ' 00:00:00', $tz );
				$to   = clone $from;
				$from->modify( '-1 month' );
				break;
			default:
				$from = ( clone $midnight )->modify( '-6 days' );
		}
		$day_from = ( clone $from )->setTimezone( $tz )->format( 'Y-m-d' );
		$day_to   = ( new DateTime( '@' . ( $to->getTimestamp() - 1 ) ) )->setTimezone( $tz )->format( 'Y-m-d' );
		return array(
			'key'      => $key,
			'from'     => gmdate( 'Y-m-d H:i:s', $from->getTimestamp() ),
			'to'       => gmdate( 'Y-m-d H:i:s', $to->getTimestamp() ),
			'day_from' => $day_from,
			'day_to'   => $day_to,
			'aligned'  => $aligned,
		);
	}

	/** 0562608899 / +84562608899 ⇒ 0562•••899 inside any label; a label with no phone stays as is (R-CID-11). */
	public static function mask( string $label ): string {
		return (string) preg_replace_callback( '/\+?\d[\d .-]{7,}\d/', static function ( $m ) {
			$d = preg_replace( '/\D/', '', $m[0] );
			if ( strlen( $d ) < 9 ) {
				return $m[0];
			}
			if ( 0 === strpos( $d, '84' ) && strlen( $d ) >= 11 ) {
				$d = '0' . substr( $d, 2 );
			}
			return substr( $d, 0, 4 ) . '•••' . substr( $d, -3 );
		}, $label );
	}

	/** Strip forbidden keys and blank any 64-hex string, recursively (second guard after the SELECT lists). */
	public static function clean( $data ) {
		if ( is_array( $data ) ) {
			$out = array();
			foreach ( $data as $k => $v ) {
				if ( is_string( $k ) && in_array( $k, self::FORBIDDEN, true ) ) {
					continue;
				}
				$out[ $k ] = self::clean( $v );
			}
			return $out;
		}
		if ( is_string( $data ) && 1 === preg_match( '/[0-9a-f]{64}/i', $data ) ) {
			return (string) preg_replace( '/[0-9a-f]{64}/i', '', $data );
		}
		return $data;
	}

	public static function vnd( float $usd, ?float $rate ): ?int {
		return ( null !== $rate && $rate > 0 ) ? (int) round( $usd * $rate ) : null;
	}

	/* ── site-level routes ─────────────────────────────────────────── */

	public static function route_summary( WP_REST_Request $request ): WP_REST_Response {
		$ctx = self::ctx( $request, 'today' );
		if ( 'no_key' === $ctx['hub']['state'] ) {
			return self::out( array( 'contract' => self::CONTRACT, 'state' => 'no_key', 'range' => $ctx['range']['key'] ) );
		}
		$t     = self::totals( $ctx );
		$rate  = $ctx['rate'];
		$admin = $ctx['admin'];
		$bal   = $admin ? $ctx['hub']['balance_usd'] : null;
		$avg   = $admin ? self::avg_daily_usd( $ctx ) : 0.0;
		$runway = ( null !== $bal && $avg > 0 ) ? (int) floor( $bal / $avg ) : null;
		$state  = (string) $ctx['hub']['budget_state'];
		if ( '' === $state ) {
			$state = null === $bal ? 'unknown' : ( $bal <= 0 ? 'exhausted' : ( null !== $runway && $runway < 3 ? 'low' : 'ok' ) );
		}
		return self::out( array(
			'contract'      => self::CONTRACT,
			'range'         => $ctx['range']['key'],
			'view'          => $ctx['view'],
			'billed_tokens' => $t['tokens'],
			'billed_usd'    => $t['usd'],
			'billed_vnd'    => self::vnd( $t['usd'], $rate ),
			'turns'         => $t['turns'],
			'tool_calls'    => $t['tool_calls'],
			'balance_usd'   => $bal,
			'balance_vnd'   => null === $bal ? null : self::vnd( $bal, $rate ),
			'budget_state'  => $state,
			'runway_days'   => $runway,
			'usd_vnd_rate'  => $rate,
			'hub_state'     => $ctx['hub']['state'],
		) );
	}

	public static function route_by_day( WP_REST_Request $request ): WP_REST_Response {
		$ctx = self::ctx( $request, '7d' );
		return self::out( array( 'contract' => self::CONTRACT, 'range' => $ctx['range']['key'], 'days' => self::days( $ctx, '' ), 'usd_vnd_rate' => $ctx['rate'] ) );
	}

	public static function route_by_channel( WP_REST_Request $request ): WP_REST_Response {
		$ctx = self::ctx( $request, 'today' );
		list( $w, $a ) = self::where( $ctx );
		$l    = self::ledger();
		$rows = self::rows( "SELECT account_id, platform, SUM(billed_tokens) AS tokens, COALESCE(SUM(billed_usd),0) AS usd,
				COUNT(DISTINCT CASE WHEN kind = 'llm' THEN NULLIF(turn_ref,'') END) AS turns, COUNT(DISTINCT NULLIF(thread_ref,'')) AS sessions,
				SUM(CASE WHEN kind = 'tool' THEN 1 ELSE 0 END) AS tool_calls
			FROM {$l} WHERE {$w} GROUP BY account_id, platform ORDER BY usd DESC, tokens DESC LIMIT 200", $a );
		$out = array();
		foreach ( $rows as $r ) {
			$acc   = self::account( (string) $r['account_id'] );
			$out[] = array(
				'account_id'    => (string) $r['account_id'],
				'platform'      => (string) $r['platform'],
				'label_masked'  => $acc['label_masked'],
				'name'          => $acc['name'],
				'owner_name'    => $acc['owner_user_id'] > 0 ? self::name( $acc['owner_user_id'] ) : '',
				'billed_tokens' => (int) $r['tokens'],
				'billed_usd'    => self::usd( $r['usd'] ),
				'billed_vnd'    => self::vnd( (float) $r['usd'], $ctx['rate'] ),
				'turns'         => (int) $r['turns'],
				'sessions'      => (int) $r['sessions'],
				'tool_calls'    => (int) $r['tool_calls'],
			);
		}
		return self::out( array( 'contract' => self::CONTRACT, 'range' => $ctx['range']['key'], 'view' => $ctx['view'], 'rows' => $out ) );
	}

	public static function route_by_model( WP_REST_Request $request ): WP_REST_Response {
		$ctx = self::ctx( $request, '7d' );
		list( $w, $a ) = self::where( $ctx );
		$l    = self::ledger();
		$rows = self::rows( "SELECT model, SUM(billed_tokens) AS tokens, COALESCE(SUM(billed_usd),0) AS usd, COUNT(DISTINCT NULLIF(turn_ref,'')) AS turns
			FROM {$l} WHERE {$w} AND kind = 'llm' GROUP BY model ORDER BY tokens DESC LIMIT 100", $a );
		$out = array();
		foreach ( $rows as $r ) {
			$out[] = array( 'model' => (string) $r['model'], 'billed_tokens' => (int) $r['tokens'], 'billed_usd' => self::usd( $r['usd'] ), 'billed_vnd' => self::vnd( (float) $r['usd'], $ctx['rate'] ), 'turns' => (int) $r['turns'] );
		}
		return self::out( array( 'contract' => self::CONTRACT, 'range' => $ctx['range']['key'], 'rows' => $out ) );
	}

	public static function route_recent( WP_REST_Request $request ): WP_REST_Response {
		$ctx   = self::ctx( $request, '30d' );
		$limit = min( 100, max( 1, (int) ( $request->get_param( 'limit' ) ?: 50 ) ) );
		list( $w, $a ) = self::where( $ctx );
		$l    = self::ledger();
		$rows = self::rows( "SELECT occurred_at, account_id, platform, kind, model, tool, tool_kind, billed_tokens, billed_usd, actor_role, actor_user_id, contact_key, row_ref, surface
			FROM {$l} WHERE {$w} ORDER BY occurred_at DESC, id DESC LIMIT {$limit}", $a );
		$out = array();
		foreach ( $rows as $r ) {
			$acc   = self::account( (string) $r['account_id'] );
			$out[] = array(
				'at'            => self::iso( (string) $r['occurred_at'] ),
				'account_id'    => (string) $r['account_id'],
				'platform'      => (string) $r['platform'],
				'label_masked'  => $acc['label_masked'],
				'kind'          => (string) $r['kind'],
				'model'         => (string) $r['model'],
				'tool'          => (string) $r['tool'],
				'tool_kind'     => (string) $r['tool_kind'],
				'billed_tokens' => (int) $r['billed_tokens'],
				'billed_vnd'    => null === $r['billed_usd'] ? null : self::vnd( (float) $r['billed_usd'], $ctx['rate'] ),
				'actor_role'    => (string) $r['actor_role'],
				'person'        => self::person( (string) $r['actor_role'], (int) $r['actor_user_id'], (string) $r['contact_key'] ),
				'row_ref'       => (string) $r['row_ref'],
				'surface'       => (string) ( $r['surface'] ?? '' ), // [2026-10-13 06:14 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.107 S107-L42 — gateway:<caller>
			);
		}
		return self::out( array( 'contract' => self::CONTRACT, 'range' => $ctx['range']['key'], 'rows' => $out ) );
	}

	public static function route_by_person( WP_REST_Request $request ): WP_REST_Response {
		$ctx = self::ctx( $request, '7d' );
		return self::out( array( 'contract' => self::CONTRACT, 'range' => $ctx['range']['key'], 'view' => $ctx['view'], 'rows' => self::people( $ctx, '' ) ) );
	}

	public static function route_sessions( WP_REST_Request $request ): WP_REST_Response {
		$ctx  = self::ctx( $request, '7d' );
		$page = self::sessions( $ctx, self::clean_id( (string) $request->get_param( 'account_id' ) ), $request, self::limit( $request ), self::offset( $request ) );
		return self::out( array( 'contract' => self::CONTRACT, 'range' => $ctx['range']['key'] ) + $page );
	}

	public static function route_tools( WP_REST_Request $request ): WP_REST_Response {
		$ctx = self::ctx( $request, '7d' );
		return self::out( array( 'contract' => self::CONTRACT, 'range' => $ctx['range']['key'], 'rows' => self::tools( $ctx, '' ) ) );
	}

	/* ── account detail (L13) ──────────────────────────────────────── */

	public static function route_account( WP_REST_Request $request ) {
		$id   = self::clean_id( (string) $request->get_param( 'id' ) );
		$part = (string) $request->get_param( 'part' );
		if ( '' === $id || ! in_array( $part, self::PARTS, true ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'invalid_param' ), 400 );
		}
		$ctx = self::ctx( $request, '7d' );
		if ( ! self::may_open_account( $ctx, $id ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'forbidden' ), 403 );
		}
		$csv = 'csv' === (string) $request->get_param( 'format' );
		switch ( $part ) {
			case 'summary':
				$body = self::account_summary( $ctx, $id, ! empty( $request->get_param( 'reveal' ) ) );
				if ( $csv ) {
					$flat = $body;
					unset( $flat['account'], $flat['sessions_by_role'], $flat['tool_calls_by_kind'] );
					return self::csv( 'summary', $id, array( $flat ) );
				}
				return self::out( $body );
			case 'by-day':
				$days = self::days( $ctx, $id );
				return $csv ? self::csv( 'by-day', $id, $days ) : self::out( array( 'contract' => self::CONTRACT, 'account_id' => $id, 'range' => $ctx['range']['key'], 'days' => $days ) );
			case 'people':
				$rows = ! empty( $request->get_param( 'customers' ) ) ? self::customers( $ctx, $id ) : self::people( $ctx, $id );
				return $csv ? self::csv( 'people', $id, $rows ) : self::out( array( 'contract' => self::CONTRACT, 'account_id' => $id, 'range' => $ctx['range']['key'], 'rows' => $rows ) );
			case 'tools':
				$rows = self::tools( $ctx, $id );
				return $csv ? self::csv( 'tools', $id, $rows ) : self::out( array( 'contract' => self::CONTRACT, 'account_id' => $id, 'range' => $ctx['range']['key'], 'rows' => $rows ) );
			case 'sessions':
			case 'turns':
				$fn = array( __CLASS__, 'sessions' === $part ? 'sessions' : 'turns' );
				if ( $csv ) {
					// streamed in CSV_BATCH pages: each page is written out before the next SELECT (never the whole tab in memory)
					$offset = 0;
					do {
						$page = call_user_func( $fn, $ctx, $id, $request, self::CSV_BATCH, $offset );
						self::csv_chunk( $part, $id, $page['rows'], 0 === $offset );
						$offset += self::CSV_BATCH;
					} while ( count( $page['rows'] ) === self::CSV_BATCH && $offset < 200000 );
					return self::csv_end();
				}
				return self::out( array( 'contract' => self::CONTRACT, 'account_id' => $id, 'range' => $ctx['range']['key'] ) + call_user_func( $fn, $ctx, $id, $request, self::limit( $request ), self::offset( $request ) ) );
		}
		return new WP_REST_Response( array( 'ok' => false ), 400 );
	}

	private static function may_open_account( array $ctx, string $id ): bool {
		if ( $ctx['admin'] ) {
			return true;
		}
		$acc = self::account( $id );
		if ( $acc['owner_user_id'] > 0 && $acc['owner_user_id'] === $ctx['viewer'] ) {
			return true;
		}
		$l   = self::ledger();
		$hit = self::rows( "SELECT 1 AS hit FROM {$l} WHERE account_id = %s AND (bound_user_id = %d OR actor_user_id = %d) LIMIT 1", array( $id, $ctx['viewer'], $ctx['viewer'] ) );
		return ! empty( $hit );
	}

	private static function account_summary( array $ctx, string $id, bool $reveal ): array {
		list( $w, $a ) = self::where( $ctx, $id );
		$l = self::ledger();
		$r = self::rows( "SELECT SUM(billed_tokens) AS tokens, COALESCE(SUM(CASE WHEN kind <> 'tool' THEN billed_usd END),0) AS token_usd,
				COALESCE(SUM(CASE WHEN kind = 'tool' THEN billed_usd END),0) AS tool_usd,
				COUNT(DISTINCT CASE WHEN kind = 'llm' THEN NULLIF(turn_ref,'') END) AS turns, COUNT(DISTINCT NULLIF(thread_ref,'')) AS sessions,
				COUNT(DISTINCT CASE WHEN actor_role = 'customer' THEN NULLIF(thread_ref,'') END) AS s_customer,
				COUNT(DISTINCT CASE WHEN actor_role = 'staff' THEN NULLIF(thread_ref,'') END) AS s_staff,
				COUNT(DISTINCT CASE WHEN actor_role = 'owner' THEN NULLIF(thread_ref,'') END) AS s_owner,
				SUM(CASE WHEN kind = 'tool' THEN 1 ELSE 0 END) AS tool_calls,
				SUM(CASE WHEN kind = 'tool' AND tool_kind = 'builtin' THEN 1 ELSE 0 END) AS t_builtin,
				SUM(CASE WHEN kind = 'tool' AND tool_kind = 'mcp' THEN 1 ELSE 0 END) AS t_mcp
			FROM {$l} WHERE {$w}", $a );
		$r    = $r ? $r[0] : array();
		$acc  = self::account( $id );
		$rate = $ctx['rate'];
		$usd  = (float) ( $r['token_usd'] ?? 0 ) + (float) ( $r['tool_usd'] ?? 0 );
		$turns = (int) ( $r['turns'] ?? 0 );
		$account = array(
			'account_id'   => $id,
			'platform'     => $acc['platform'],
			'label_masked' => $acc['label_masked'],
			'name'         => $acc['name'],
			'owner_name'   => $acc['owner_user_id'] > 0 ? self::name( $acc['owner_user_id'] ) : '',
			'status'       => $acc['status'],
		);
		if ( $reveal && $ctx['admin'] && '' !== $acc['label_full'] ) {
			$account['label_full'] = $acc['label_full']; // site admin only, on demand ("hiện số")
		}
		return array(
			'contract'           => self::CONTRACT,
			'range'              => $ctx['range']['key'],
			'view'               => $ctx['view'],
			'account'            => $account,
			'billed_tokens'      => (int) ( $r['tokens'] ?? 0 ),
			'billed_usd'         => round( $usd, 8 ),
			'billed_vnd'         => self::vnd( $usd, $rate ),
			'token_vnd'          => self::vnd( (float) ( $r['token_usd'] ?? 0 ), $rate ),
			'tool_vnd'           => self::vnd( (float) ( $r['tool_usd'] ?? 0 ), $rate ),
			'turns'              => $turns,
			'vnd_per_turn'       => ( $turns > 0 && null !== $rate ) ? self::vnd( $usd / $turns, $rate ) : null,
			'sessions'           => (int) ( $r['sessions'] ?? 0 ),
			'sessions_by_role'   => array( 'customer' => (int) ( $r['s_customer'] ?? 0 ), 'staff' => (int) ( $r['s_staff'] ?? 0 ), 'owner' => (int) ( $r['s_owner'] ?? 0 ) ),
			'tool_calls'         => (int) ( $r['tool_calls'] ?? 0 ),
			'tool_calls_by_kind' => array( 'builtin' => (int) ( $r['t_builtin'] ?? 0 ), 'mcp' => (int) ( $r['t_mcp'] ?? 0 ) ),
			'usd_vnd_rate'       => $rate,
		);
	}

	/* ── shared builders ───────────────────────────────────────────── */

	private static function totals( array $ctx ): array {
		$range = $ctx['range'];
		if ( $range['aligned'] ) {
			list( $sw, $sa ) = $ctx['scope'];
			$d = self::daily();
			$r = self::rows( "SELECT SUM(billed_tokens) AS tokens, COALESCE(SUM(billed_usd),0) AS usd,
					SUM(CASE WHEN kind = 'llm' THEN turns ELSE 0 END) AS turns, SUM(tool_calls) AS tool_calls
				FROM {$d} WHERE {$sw} AND day >= %s AND day <= %s", array_merge( $sa, array( $range['day_from'], $range['day_to'] ) ) );
		} else {
			list( $w, $a ) = self::where( $ctx );
			$l = self::ledger();
			$r = self::rows( "SELECT SUM(billed_tokens) AS tokens, COALESCE(SUM(billed_usd),0) AS usd,
					COUNT(DISTINCT CASE WHEN kind = 'llm' THEN NULLIF(turn_ref,'') END) AS turns, SUM(CASE WHEN kind = 'tool' THEN 1 ELSE 0 END) AS tool_calls
				FROM {$l} WHERE {$w}", $a );
		}
		$r = $r ? $r[0] : array();
		return array( 'tokens' => (int) ( $r['tokens'] ?? 0 ), 'usd' => self::usd( $r['usd'] ?? 0 ), 'turns' => (int) ( $r['turns'] ?? 0 ), 'tool_calls' => (int) ( $r['tool_calls'] ?? 0 ) );
	}

	/** Site-wide average spend per day over the last 7 local days (runway of the site's wallet). */
	private static function avg_daily_usd( array $ctx ): float {
		$r7 = self::range( '7d', self::now(), self::tz() );
		$d  = self::daily();
		$r  = self::rows( "SELECT COALESCE(SUM(billed_usd),0) AS usd FROM {$d} WHERE day >= %s AND day <= %s", array( $r7['day_from'], $r7['day_to'] ) );
		return $r ? (float) $r[0]['usd'] / 7 : 0.0;
	}

	private static function days( array $ctx, string $account ): array {
		list( $sw, $sa ) = $ctx['scope'];
		$range = $ctx['range'];
		$d     = self::daily();
		$args  = array_merge( $sa, array( $range['day_from'], $range['day_to'] ) );
		$acc   = '';
		if ( '' !== $account ) {
			$acc    = ' AND account_id = %s';
			$args[] = $account;
		}
		$rows = self::rows( "SELECT day, SUM(billed_tokens) AS tokens, COALESCE(SUM(CASE WHEN kind <> 'tool' THEN billed_usd END),0) AS token_usd,
				COALESCE(SUM(CASE WHEN kind = 'tool' THEN billed_usd END),0) AS tool_usd, SUM(CASE WHEN kind = 'llm' THEN turns ELSE 0 END) AS turns,
				SUM(tool_calls) AS tool_calls
			FROM {$d} WHERE {$sw} AND day >= %s AND day <= %s{$acc} GROUP BY day ORDER BY day", $args );
		$by = array();
		foreach ( $rows as $r ) {
			$by[ (string) $r['day'] ] = $r;
		}
		$out  = array();
		$tz   = self::tz();
		$cur  = new DateTime( $range['day_from'] . ' 00:00:00', $tz );
		$last = $range['day_to'];
		for ( $i = 0; $i < 62 && $cur->format( 'Y-m-d' ) <= $last; $i++ ) {
			$day   = $cur->format( 'Y-m-d' );
			$r     = $by[ $day ] ?? array();
			$tok   = (float) ( $r['token_usd'] ?? 0 );
			$tool  = (float) ( $r['tool_usd'] ?? 0 );
			$out[] = array(
				'date'          => $day,
				'billed_tokens' => (int) ( $r['tokens'] ?? 0 ),
				'billed_usd'    => round( $tok + $tool, 8 ),
				'billed_vnd'    => self::vnd( $tok + $tool, $ctx['rate'] ),
				'token_vnd'     => self::vnd( $tok, $ctx['rate'] ),
				'tool_vnd'      => self::vnd( $tool, $ctx['rate'] ),
				'turns'         => (int) ( $r['turns'] ?? 0 ),
				'tool_calls'    => (int) ( $r['tool_calls'] ?? 0 ),
			);
			$cur->modify( '+1 day' );
		}
		return $out;
	}

	/** Người: one row per (account, role, WP user); customers of a number grouped as "Khách (N)". */
	private static function people( array $ctx, string $account ): array {
		list( $w, $a ) = self::where( $ctx, $account );
		$l    = self::ledger();
		$rows = self::rows( "SELECT account_id, platform, actor_role, actor_user_id, SUM(billed_tokens) AS tokens, COALESCE(SUM(billed_usd),0) AS usd,
				COUNT(DISTINCT CASE WHEN kind = 'llm' THEN NULLIF(turn_ref,'') END) AS turns, COUNT(DISTINCT NULLIF(thread_ref,'')) AS sessions,
				SUM(CASE WHEN kind = 'tool' THEN 1 ELSE 0 END) AS tool_calls, COUNT(DISTINCT NULLIF(contact_key,'')) AS contacts
			FROM {$l} WHERE {$w} GROUP BY account_id, platform, actor_role, actor_user_id ORDER BY account_id, usd DESC LIMIT 500", $a );
		$out = array();
		foreach ( $rows as $r ) {
			$role = (string) $r['actor_role'];
			$uid  = (int) $r['actor_user_id'];
			$acc  = self::account( (string) $r['account_id'] );
			$name = 'customer' === $role
				? 'Khách (' . max( 1, (int) $r['contacts'] ) . ')'
				: ( $uid > 0 ? self::name( $uid ) : ( 'staff' === $role ? 'Nhân viên chưa liên kết' : ( 'system' === $role ? 'Hệ thống' : 'Chủ số' ) ) );
			$out[] = array(
				'account_id'    => (string) $r['account_id'],
				'platform'      => (string) $r['platform'],
				'label_masked'  => $acc['label_masked'],
				'actor_role'    => $role,
				'user_id'       => 'customer' === $role ? 0 : $uid,
				'name'          => $name,
				'contacts'      => 'customer' === $role ? (int) $r['contacts'] : 0,
				'billed_tokens' => (int) $r['tokens'],
				'billed_usd'    => self::usd( $r['usd'] ),
				'billed_vnd'    => self::vnd( (float) $r['usd'], $ctx['rate'] ),
				'turns'         => (int) $r['turns'],
				'sessions'      => (int) $r['sessions'],
				'tool_calls'    => (int) $r['tool_calls'],
			);
		}
		return $out;
	}

	/** Customers of one number, one row per short contact key ("Khách #a1b2"). */
	private static function customers( array $ctx, string $account ): array {
		list( $w, $a ) = self::where( $ctx, $account );
		$l    = self::ledger();
		$rows = self::rows( "SELECT contact_key, SUM(billed_tokens) AS tokens, COALESCE(SUM(billed_usd),0) AS usd,
				COUNT(DISTINCT CASE WHEN kind = 'llm' THEN NULLIF(turn_ref,'') END) AS turns, COUNT(DISTINCT NULLIF(thread_ref,'')) AS sessions, MAX(occurred_at) AS last_at
			FROM {$l} WHERE {$w} AND actor_role = 'customer' GROUP BY contact_key ORDER BY usd DESC LIMIT 500", $a );
		$out = array();
		foreach ( $rows as $r ) {
			$out[] = array(
				'contact_key'   => (string) $r['contact_key'],
				'name'          => self::person( 'customer', 0, (string) $r['contact_key'] ),
				'billed_tokens' => (int) $r['tokens'],
				'billed_vnd'    => self::vnd( (float) $r['usd'], $ctx['rate'] ),
				'turns'         => (int) $r['turns'],
				'sessions'      => (int) $r['sessions'],
				'last_at'       => self::iso( (string) $r['last_at'] ),
			);
		}
		return $out;
	}

	/** Phiên: one row per (account, thread), newest first, paginated. Filters: actor (WP user), role, contact. */
	private static function sessions( array $ctx, string $account, WP_REST_Request $request, int $limit, int $offset ): array {
		list( $w, $a ) = self::where( $ctx, $account );
		list( $w, $a ) = self::filters( $w, $a, $request );
		$l    = self::ledger();
		$rows = self::rows( "SELECT account_id, thread_ref, MIN(occurred_at) AS started_at, MAX(occurred_at) AS last_at, MAX(actor_role) AS actor_role,
				MAX(actor_user_id) AS actor_user_id, MAX(contact_key) AS contact_key, SUM(billed_tokens) AS tokens, COALESCE(SUM(billed_usd),0) AS usd,
				COUNT(DISTINCT CASE WHEN kind = 'llm' THEN NULLIF(turn_ref,'') END) AS turns, SUM(CASE WHEN kind = 'tool' THEN 1 ELSE 0 END) AS tool_calls
			FROM {$l} WHERE {$w} AND thread_ref <> '' GROUP BY account_id, thread_ref ORDER BY started_at DESC LIMIT {$limit} OFFSET {$offset}", $a );
		$total = self::rows( "SELECT COUNT(DISTINCT account_id, thread_ref) AS n FROM {$l} WHERE {$w} AND thread_ref <> ''", $a );
		$out   = array();
		foreach ( $rows as $r ) {
			$acc   = self::account( (string) $r['account_id'] );
			$out[] = array(
				'session_ref'   => self::ref( 's', (string) $r['account_id'] . '|' . (string) $r['thread_ref'] ),
				'account_id'    => (string) $r['account_id'],
				'label_masked'  => $acc['label_masked'],
				'started_at'    => self::iso( (string) $r['started_at'] ),
				'last_at'       => self::iso( (string) $r['last_at'] ),
				'actor_role'    => (string) $r['actor_role'],
				'person'        => self::person( (string) $r['actor_role'], (int) $r['actor_user_id'], (string) $r['contact_key'] ),
				'turns'         => (int) $r['turns'],
				'billed_tokens' => (int) $r['tokens'],
				'tool_calls'    => (int) $r['tool_calls'],
				'billed_vnd'    => self::vnd( (float) $r['usd'], $ctx['rate'] ),
			);
		}
		return array( 'rows' => $out, 'total' => $total ? (int) $total[0]['n'] : count( $out ), 'limit' => $limit, 'offset' => $offset );
	}

	/** Lượt: one row per answered turn (llm row + the tool rows of the same turn_ref), newest first, paginated. */
	private static function turns( array $ctx, string $account, WP_REST_Request $request, int $limit, int $offset ): array {
		list( $w, $a ) = self::where( $ctx, $account );
		list( $w, $a ) = self::filters( $w, $a, $request );
		$l    = self::ledger();
		$key  = "COALESCE(NULLIF(turn_ref,''), CONCAT('#', account_seq))";
		$rows = self::rows( "SELECT account_id, {$key} AS tk, MIN(occurred_at) AS at, MAX(actor_role) AS actor_role, MAX(actor_user_id) AS actor_user_id,
				MAX(contact_key) AS contact_key, MAX(CASE WHEN kind = 'llm' THEN model END) AS model, MAX(CASE WHEN kind = 'llm' THEN surface END) AS surface, SUM(billed_tokens) AS tokens,
				COALESCE(SUM(billed_usd),0) AS usd, GROUP_CONCAT(CASE WHEN kind = 'tool' THEN CONCAT(tool, '|', tool_kind) END SEPARATOR ',') AS tools,
				MAX(CASE WHEN kind = 'llm' THEN row_ref END) AS row_ref, MAX(is_error) AS is_error
			FROM {$l} WHERE {$w} GROUP BY account_id, tk ORDER BY at DESC LIMIT {$limit} OFFSET {$offset}", $a );
		$total = self::rows( "SELECT COUNT(DISTINCT account_id, {$key}) AS n FROM {$l} WHERE {$w}", $a );
		$out   = array();
		foreach ( $rows as $r ) {
			$tools = array();
			foreach ( array_filter( explode( ',', (string) $r['tools'] ) ) as $t ) {
				$p       = explode( '|', $t );
				$tools[] = array( 'tool' => (string) $p[0], 'tool_kind' => (string) ( $p[1] ?? '' ) );
			}
			$out[] = array(
				'at'            => self::iso( (string) $r['at'] ),
				'account_id'    => (string) $r['account_id'],
				'actor_role'    => (string) $r['actor_role'],
				'person'        => self::person( (string) $r['actor_role'], (int) $r['actor_user_id'], (string) $r['contact_key'] ),
				'model'         => (string) $r['model'],
				'billed_tokens' => (int) $r['tokens'],
				'tools'         => $tools,
				'billed_vnd'    => self::vnd( (float) $r['usd'], $ctx['rate'] ),
				'row_ref'       => (string) $r['row_ref'],
				'surface'       => (string) ( $r['surface'] ?? '' ), // [2026-10-13 06:14 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.107 S107-L42 — gateway:<caller>
				'is_error'      => (int) $r['is_error'],
			);
		}
		return array( 'rows' => $out, 'total' => $total ? (int) $total[0]['n'] : count( $out ), 'limit' => $limit, 'offset' => $offset );
	}

	/** Công cụ: per tool — calls, units, the Hub unit price that was applied (snapshot), money, last call. */
	private static function tools( array $ctx, string $account ): array {
		list( $w, $a ) = self::where( $ctx, $account );
		$l    = self::ledger();
		$rows = self::rows( "SELECT tool, tool_kind, COUNT(*) AS calls, SUM(units) AS units, COALESCE(SUM(billed_usd),0) AS usd,
				SUM(CASE WHEN billed_usd IS NULL THEN 1 ELSE 0 END) AS unpriced, MAX(occurred_at) AS last_at
			FROM {$l} WHERE {$w} AND kind = 'tool' GROUP BY tool, tool_kind ORDER BY calls DESC LIMIT 200", $a );
		$out = array();
		foreach ( $rows as $r ) {
			$units  = (float) $r['units'];
			$priced = (float) $r['units'] > 0 && (int) $r['unpriced'] < (int) $r['calls'];
			$unit   = $priced ? (float) $r['usd'] / max( 0.0001, $units ) : null;
			$out[]  = array(
				'tool'          => (string) $r['tool'],
				'tool_kind'     => (string) $r['tool_kind'],
				'calls'         => (int) $r['calls'],
				'units'         => round( $units, 4 ),
				'unit_usd'      => null === $unit ? null : round( $unit, 8 ),
				'unit_vnd'      => null === $unit ? null : self::vnd( $unit, $ctx['rate'] ),
				'billed_usd'    => self::usd( $r['usd'] ),
				'billed_vnd'    => self::vnd( (float) $r['usd'], $ctx['rate'] ),
				'unpriced'      => (int) $r['unpriced'],
				'last_at'       => self::iso( (string) $r['last_at'] ),
			);
		}
		return $out;
	}

	/* ── context, people, accounts ─────────────────────────────────── */

	private static function ctx( WP_REST_Request $request, string $default_range ): array {
		$viewer = self::viewer();
		$admin  = self::is_admin();
		$view   = (string) $request->get_param( 'view' );
		$view   = $admin ? ( 'mine' === $view ? 'mine' : 'all' ) : 'mine';
		$rkey   = (string) ( $request->get_param( 'range' ) ?: $default_range );
		$hub    = self::hub();
		return array(
			'viewer' => $viewer,
			'admin'  => $admin,
			'view'   => $view,
			'scope'  => self::scope( $viewer, $admin, $view ),
			'range'  => self::range( $rkey, self::now(), self::tz() ),
			'hub'    => $hub,
			'rate'   => $hub['usd_vnd_rate'],
		);
	}

	/** @return array{0:string,1:array} */
	private static function where( array $ctx, string $account = '' ): array {
		list( $w, $a ) = $ctx['scope'];
		$w  .= ' AND occurred_at >= %s AND occurred_at < %s';
		$a[] = $ctx['range']['from'];
		$a[] = $ctx['range']['to'];
		if ( '' !== $account ) {
			$w  .= ' AND account_id = %s';
			$a[] = $account;
		}
		return array( $w, $a );
	}

	private static function filters( string $w, array $a, WP_REST_Request $request ): array {
		$actor = (int) $request->get_param( 'actor' );
		if ( $actor > 0 ) {
			$w  .= ' AND actor_user_id = %d';
			$a[] = $actor;
		}
		$role = (string) $request->get_param( 'role' );
		if ( in_array( $role, BizCity_Usage_Ingest::ROLES, true ) ) {
			$w  .= ' AND actor_role = %s';
			$a[] = $role;
		}
		$contact = strtolower( (string) $request->get_param( 'contact' ) );
		if ( 1 === preg_match( '/^[0-9a-f]{4,16}$/', $contact ) ) {
			$w  .= ' AND contact_key = %s';
			$a[] = $contact;
		}
		return array( $w, $a );
	}

	private static function person( string $role, int $uid, string $contact ): string {
		if ( 'customer' === $role ) {
			return '' !== $contact ? 'Khách #' . substr( $contact, 0, 4 ) : 'Khách';
		}
		if ( $uid > 0 ) {
			return self::name( $uid );
		}
		return 'staff' === $role ? 'Nhân viên chưa liên kết' : ( 'system' === $role ? 'Hệ thống' : 'Chủ số' );
	}

	private static function name( int $uid ): string {
		if ( isset( self::$names[ $uid ] ) ) {
			return self::$names[ $uid ];
		}
		if ( isset( self::$readers['name'] ) ) {
			$n = (string) call_user_func( self::$readers['name'], $uid );
		} else {
			$u = function_exists( 'get_userdata' ) ? get_userdata( $uid ) : null;
			$n = $u ? (string) ( $u->display_name ?: $u->user_login ) : '#' . $uid;
		}
		// a number's work user is often named by its phone (R-NB-11): mask it like any label
		return self::$names[ $uid ] = self::mask( $n );
	}

	/** @return array{platform:string,label_masked:string,label_full:string,name:string,owner_user_id:int,status:string} */
	private static function account( string $id ): array {
		if ( isset( self::$accounts[ $id ] ) ) {
			return self::$accounts[ $id ];
		}
		if ( isset( self::$readers['account'] ) ) {
			$row = (array) call_user_func( self::$readers['account'], $id );
		} elseif ( 0 === strpos( $id, 'web-' ) ) {
			$row = array( 'web' => true );
		} else {
			$row = class_exists( 'BizCity_Zalo_Mapping_Repo' ) ? (array) BizCity_Zalo_Mapping_Repo::find_account_by_bridge_id( 'personal', $id ) : array();
		}
		if ( ! empty( $row['web'] ) ) {
			return self::$accounts[ $id ] = array( 'platform' => 'web', 'label_masked' => 'Web /gpt/', 'label_full' => '', 'name' => 'Web (/gpt/ · Ask Brain)', 'owner_user_id' => 0, 'status' => '' );
		}
		$label = trim( (string) ( $row['label'] ?? '' ) );
		$name  = trim( (string) ( $row['account_name'] ?? '' ) );
		$full  = '' !== $label ? $label : $name;
		$masked = '' !== $full ? self::mask( $full ) : 'Số …' . substr( $id, -3 );
		return self::$accounts[ $id ] = array(
			'platform'      => 'zalo_personal',
			'label_masked'  => $masked,
			'label_full'    => $full,
			'name'          => '' !== $name ? self::mask( $name ) : $masked,
			'owner_user_id' => (int) ( $row['owner_user_id'] ?? 0 ),
			'status'        => (string) ( $row['status'] ?? '' ),
		);
	}

	/** Does this user have anything to see in "Sử dụng"? (BOOT.caps.usage; site admins always do.) */
	public static function viewer_has_usage( int $uid ): bool {
		if ( $uid <= 0 ) {
			return false;
		}
		if ( class_exists( 'BizCity_Zalo_Mapping_Repo' ) && method_exists( 'BizCity_Zalo_Mapping_Repo', 'list_personal_accounts_for_owner' )
			&& ! empty( BizCity_Zalo_Mapping_Repo::list_personal_accounts_for_owner( $uid ) ) ) {
			return true;
		}
		if ( ! class_exists( 'BizCity_Usage_Schema' ) || ! BizCity_Usage_Schema::installed() ) {
			return false;
		}
		$l = self::ledger();
		return ! empty( self::rows( "SELECT 1 AS hit FROM {$l} WHERE actor_user_id = %d OR bound_user_id = %d LIMIT 1", array( $uid, $uid ) ) );
	}

	/* ── Hub (balance + rate), cached 60 s ──────────────────────────── */

	/** @return array{state:string,balance_usd:?float,usd_vnd_rate:?float,budget_state:string} */
	private static function hub(): array {
		if ( isset( self::$readers['hub'] ) ) {
			return (array) call_user_func( self::$readers['hub'] ) + array( 'state' => 'ok', 'balance_usd' => null, 'usd_vnd_rate' => null, 'budget_state' => '' );
		}
		$none = array( 'state' => 'no_key', 'balance_usd' => null, 'usd_vnd_rate' => null, 'budget_state' => '' );
		if ( ! class_exists( 'BizCity_LLM_Client' ) ) {
			return array( 'state' => 'no_client' ) + $none;
		}
		$client = BizCity_LLM_Client::instance();
		if ( '' === $client->get_api_key( false ) ) { // the site's OWN key; never the main site's (doc 20 §5)
			return $none;
		}
		// [2026-10-13 02:28 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.107 S107-L18 — wallet-view@1 (Hub /account/wallet) is the source of the balance, the
		// single Hub rate and the runway; /account/info below stays only as the fallback while the Hub route is not deployed.
		if ( class_exists( 'BizCity_Usage_Wallet' ) ) {
			$w = BizCity_Usage_Wallet::view();
			if ( 'ok' === ( $w['state'] ?? '' ) ) {
				$bal = $w['balance_usd'];
				return array(
					'state'        => 'ok',
					'balance_usd'  => $bal,
					'usd_vnd_rate' => $w['usd_vnd_rate'],
					'budget_state' => ( null !== $bal && $bal <= 0 ) ? 'exhausted' : ( ! empty( $w['low_balance'] ) ? 'low' : '' ),
				);
			}
		}
		$cached = get_transient( self::HUB_CACHE );
		if ( is_array( $cached ) && isset( $cached['state'] ) ) {
			return $cached;
		}
		$r    = $client->gateway_get( '/account/info', array(), 'GET', 8, false );
		$data = is_array( $r ) && isset( $r['data'] ) && is_array( $r['data'] ) ? $r['data'] : array();
		$rate = null;
		foreach ( array( $data['usd_vnd_rate'] ?? null, $data['currency']['rate'] ?? null, is_array( $r ) ? ( $r['usd_vnd_rate'] ?? null ) : null ) as $cand ) {
			if ( is_numeric( $cand ) && (float) $cand > 0 ) {
				$rate = (float) $cand;
				break;
			}
		}
		if ( null === $rate && class_exists( 'BizCity_Usage_Prices' ) ) {
			$p    = BizCity_Usage_Prices::table();
			$rate = $p['usd_vnd_rate'] > 0 ? (float) $p['usd_vnd_rate'] : null;
		}
		$rate = apply_filters( 'bizcity_usage_usd_vnd_rate', $rate );
		$out  = array(
			'state'        => empty( $data ) ? 'degraded' : 'ok',
			'balance_usd'  => isset( $data['balance_usd'] ) && is_numeric( $data['balance_usd'] ) ? round( (float) $data['balance_usd'], 4 ) : null,
			'usd_vnd_rate' => is_numeric( $rate ) && (float) $rate > 0 ? (float) $rate : null,
			'budget_state' => isset( $data['budget']['state'] ) ? sanitize_key( (string) $data['budget']['state'] ) : '',
		);
		set_transient( self::HUB_CACHE, $out, self::HUB_TTL );
		return $out;
	}

	/* ── low level ─────────────────────────────────────────────────── */

	private static function rows( string $sql, array $args ): array {
		if ( isset( self::$readers['rows'] ) ) {
			return (array) call_user_func( self::$readers['rows'], $sql, $args );
		}
		global $wpdb;
		if ( ! class_exists( 'BizCity_Usage_Schema' ) || ! BizCity_Usage_Schema::installed() ) {
			return array();
		}
		$q = empty( $args ) ? $sql : $wpdb->prepare( $sql, $args ); // phpcs:ignore WordPress.DB.PreparedSQL
		$r = $wpdb->get_results( $q, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		return is_array( $r ) ? $r : array();
	}

	private static function out( array $body ): WP_REST_Response {
		return new WP_REST_Response( self::clean( $body ), 200 );
	}

	/** CSV of one (small) tab. */
	private static function csv( string $part, string $id, array $rows ) {
		self::csv_chunk( $part, $id, $rows, true );
		return self::csv_end();
	}

	private static function csv_end() {
		if ( isset( self::$readers['csv'] ) ) {
			return new WP_REST_Response( array( 'ok' => true, 'csv' => true ), 200 );
		}
		exit;
	}

	/** Write one batch of CSV rows; the first batch sends the headers, the BOM and the column line. */
	private static function csv_chunk( string $part, string $id, array $rows, bool $first ): void {
		$rows = self::clean( $rows );
		if ( isset( self::$readers['csv'] ) ) {
			call_user_func( self::$readers['csv'], $part, $id, $rows, $first );
			return;
		}
		if ( $first ) {
			nocache_headers();
			header( 'Content-Type: text/csv; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename="usage-' . sanitize_file_name( $id . '-' . $part ) . '.csv"' );
			echo "\xEF\xBB\xBF"; // Excel reads UTF-8 (Vietnamese names)
		}
		$fh = fopen( 'php://output', 'w' );
		foreach ( $rows as $i => $r ) {
			$flat = array();
			foreach ( $r as $k => $v ) {
				$flat[ $k ] = is_array( $v ) ? implode( ' ', array_map( static function ( $x ) { return is_array( $x ) ? implode( ':', $x ) : (string) $x; }, $v ) ) : $v;
			}
			if ( $first && 0 === $i ) {
				fputcsv( $fh, array_keys( $flat ) );
			}
			fputcsv( $fh, array_values( $flat ) );
		}
		fclose( $fh );
		if ( function_exists( 'flush' ) ) {
			flush();
		}
	}

	private static function ref( string $prefix, string $raw ): string {
		return $prefix . '_' . substr( hash( 'sha256', $raw ), 0, 10 );
	}

	private static function iso( string $utc ): string {
		return '' === $utc ? '' : gmdate( 'c', (int) strtotime( $utc . ' UTC' ) );
	}

	private static function usd( $v ): float {
		return round( (float) $v, 8 );
	}

	private static function limit( WP_REST_Request $request ): int {
		return min( 100, max( 1, (int) ( $request->get_param( 'limit' ) ?: 25 ) ) );
	}

	private static function offset( WP_REST_Request $request ): int {
		$page = max( 1, (int) ( $request->get_param( 'page' ) ?: 1 ) );
		return ( $page - 1 ) * self::limit( $request );
	}

	private static function clean_id( string $raw ): string {
		return BizCity_Usage_Ingest::clean_id( $raw );
	}

	private static function ledger(): string {
		return class_exists( 'BizCity_Usage_Schema' ) ? BizCity_Usage_Schema::ledger_table() : 'bizcity_usage_ledger';
	}

	private static function daily(): string {
		return class_exists( 'BizCity_Usage_Schema' ) ? BizCity_Usage_Schema::daily_table() : 'bizcity_usage_daily';
	}

	private static function viewer(): int {
		return isset( self::$readers['viewer'] ) ? (int) call_user_func( self::$readers['viewer'] ) : (int) get_current_user_id();
	}

	private static function is_admin(): bool {
		if ( isset( self::$readers['is_admin'] ) ) {
			return (bool) call_user_func( self::$readers['is_admin'] );
		}
		return class_exists( 'BizCity_Network_Admin_Capability' ) ? BizCity_Network_Admin_Capability::can_manage() : current_user_can( 'manage_options' );
	}

	private static function now(): int {
		return isset( self::$readers['now'] ) ? (int) call_user_func( self::$readers['now'] ) : time();
	}

	private static function tz(): DateTimeZone {
		return function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
	}
}
