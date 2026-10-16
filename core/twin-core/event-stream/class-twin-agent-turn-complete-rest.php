<?php
/**
 * Twin Agent turn write-back — `POST bizcity-twin/v1/turn-complete` (PHASE-0.87 CL-5, contract twin-agent-turn-complete@1.0).
 *
 * Cell outbox → Hub (HB-3) → here, with the number's per-account callback Bearer (same storage as the Zalo bridge /inbound
 * token). One `assistant_message` twin event per turn, its event_uuid derived from `idempotency_key`, so a retry of the same
 * turn is a no-op (`duplicate:true`). Memory / Context Bank work is NOT done here: listeners of
 * `bizcity_twin_agent_turn_completed` must only schedule their own job (never inline).
 *
 * For zalo_personal the existing `bot_reply` event keeps feeding the CRM thread; this only adds the twin record.
 * Web surfaces (twinchat|gpt, CL-6/CL-7) come through the same Hub relay with the answering number's token; their record belongs
 * to the asking `wp_user_id`.
 *
 * // @axis twin-agent-axis@1 seam SEAM-3
 *
 * @package BizCity_Twin_AI
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Twin_Agent_Turn_Complete_REST', false ) ) {
	return;
}

final class BizCity_Twin_Agent_Turn_Complete_REST {

	const NS        = 'bizcity-twin/v1';
	const CONTRACT  = 'twin-agent-turn-complete@1';
	const HOOK_DONE = 'bizcity_twin_agent_turn_completed';
	const ANSWER_MAX = 20000;

	/**
	 * Test seams: token(account_id): string · owner(account_id): int · staff(account_id, user_hash): int · exists(uuid): bool · ingest(envelope): string
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
		register_rest_route( self::NS, '/turn-complete', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'handle' ),
			'permission_callback' => '__return_true', // per-account Bearer verified in handler
		) );
	}

	public static function handle( WP_REST_Request $request ): WP_REST_Response {
		$body    = $request->get_json_params();
		$body    = is_array( $body ) ? $body : array();
		$account = sanitize_text_field( (string) ( $body['account_id'] ?? '' ) );
		$token   = '' !== $account ? self::token( $account ) : '';
		$header  = (string) $request->get_header( 'authorization' );
		$bearer  = stripos( $header, 'Bearer ' ) === 0 ? trim( substr( $header, 7 ) ) : '';
		if ( '' === $token || '' === $bearer || ! hash_equals( $token, $bearer ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'unauthorized' ), 401 );
		}
		$contract = (string) ( $body['contract'] ?? '' );
		$key      = sanitize_text_field( (string) ( $body['idempotency_key'] ?? '' ) );
		$trace    = substr( sanitize_text_field( (string) ( $body['trace_id'] ?? '' ) ), 0, 128 );
		if ( 0 !== strpos( $contract, self::CONTRACT ) || '' === $key || '' === $trace ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'invalid_param', 'message' => 'contract, idempotency_key and trace_id are required.' ), 400 );
		}

		$uuid = self::uuid_from_key( $key );
		if ( self::exists( $uuid ) ) {
			return new WP_REST_Response( array( 'ok' => true, 'duplicate' => true, 'stored' => 0 ), 200 );
		}
		// [2026-10-01 Claude Opus 5.5] PHASE-0.87 W2-5 — `staff` (doc 50 §5.5): the record belongs to that person, resolved from
		// the hash among THIS number's principals; an unknown hash keeps the record with no user (never under the owner).
		$role     = in_array( $body['role'] ?? '', array( 'owner', 'staff' ), true ) ? (string) $body['role'] : 'customer';
		$owner_id = 'owner' === $role ? self::owner( $account ) : ( 'staff' === $role ? self::staff( $account, (string) ( $body['user_hash'] ?? '' ) ) : 0 );
		// [2026-10-01 Claude Opus 5.5] PHASE-0.87 CL-6/CL-7 — a web turn (twinchat|gpt) belongs to the logged-in user who asked:
		// the site put that `wp_user_id` in the envelope (never the browser), the cell echoes it, this token proves the cell.
		$surface  = sanitize_key( (string) ( $body['surface'] ?? 'zalo_personal' ) );
		$wp_user  = in_array( $surface, array( 'twinchat', 'gpt' ), true ) ? max( 0, (int) ( $body['wp_user_id'] ?? 0 ) ) : 0;
		// [2026-10-13 01:20 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.106 S106-C1 — a Cell Chat turn (`via:"cell_chat"`, surface zalo_personal) was typed by a
		// logged-in user too: keep their `wp_user_id`, but only when it IS the person the cell answered as (the number's owner, or the staff member of
		// that hash) — the record never lands under someone else. Before 0.106 `via` was ignored and the record fell back to the owner.
		// `zalo` (PHASE-0.106 S106-B4, Q106-5): a real Zalo turn of the number's owner / staff that MADE files — only its files are listed, nothing else changes
		$via = in_array( $body['via'] ?? '', array( 'cell_chat', 'zalo' ), true ) ? (string) $body['via'] : '';
		if ( 'cell_chat' === $via && ! in_array( $surface, array( 'twinchat', 'gpt' ), true ) ) {
			// a numbered Cell Chat turn (surface zalo_personal); a web-home one (surface twinchat) already kept its user above
			$asked   = max( 0, (int) ( $body['wp_user_id'] ?? 0 ) );
			$wp_user = ( $asked > 0 && $asked === $owner_id ) ? $asked : 0;
		}
		if ( $wp_user > 0 ) {
			$owner_id = $wp_user;
		}
		$session_id = self::session_id( $body['session_id'] ?? '' );
		$files      = '' !== $via && in_array( $role, array( 'owner', 'staff' ), true ) ? self::files( $body['files'] ?? array() ) : array();
		$answer     = mb_substr( (string) ( $body['answer_md'] ?? '' ), 0, self::ANSWER_MAX );
		$payload  = array(
			'content'       => $answer,
			// PHASE-0.106 S106-C3: Ask Brain's list_turns reads `text`; `content` stays for the older readers
			'text'          => $answer,
			'via'           => $via,
			'thread_kind'   => in_array( $body['thread_kind'] ?? '', array( 'zalo_1_1', 'cellchat', 'cellchat_session' ), true ) ? (string) $body['thread_kind'] : '',
			'files'         => $files,
			'axis'          => 'twin-agent-axis@1',
			'surface'       => $surface,
			'account_id'    => $account,
			'wp_user_id'    => $wp_user,
			'role'          => $role,
			'modes'         => self::keys( $body['modes'] ?? array() ),
			'blocks'        => self::keys( $body['blocks'] ?? array() ),
			'model'         => substr( sanitize_text_field( (string) ( $body['model'] ?? '' ) ), 0, 120 ),
			'finish_reason' => sanitize_key( (string) ( $body['finish_reason'] ?? '' ) ),
			// [2026-10-01 Claude Opus 5.5] PHASE-0.87 WEB-EFFORT — the reasoning level the cell really used (after its clamp); additive.
			'reasoning_effort' => in_array( (string) ( $body['reasoning_effort'] ?? '' ), array( 'off', 'low', 'medium', 'high', 'xhigh' ), true ) ? (string) $body['reasoning_effort'] : '',
			// D87-EFFORT — present only when the cell LOWERED the picked level: {code, requested, effective} (UI scale), additive.
			'reasoning_limit'  => self::reasoning_limit( $body['reasoning_limit'] ?? null ),
			'tokens'        => array( 'prompt' => (int) ( $body['tokens']['prompt'] ?? 0 ), 'completion' => (int) ( $body['tokens']['completion'] ?? 0 ) ),
			'tools'         => self::rows( $body['tools'] ?? array(), array( 'tool', 'ok', 'ms' ) ),
			'packs_read'    => self::rows( $body['packs_read'] ?? array(), array( 'kind', 'version', 'as_of' ) ),
			'captured'      => (int) ( $body['captured'] ?? 0 ),
			'spans'         => array_map( 'intval', array_intersect_key( (array) ( $body['spans'] ?? array() ), array_flip( array( 't_recv', 't_ctx_ready', 't_first_token', 't_done' ) ) ) ),
			'idempotency_key' => $key,
		);
		$blog     = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : null;
		$now_ms   = (int) ( microtime( true ) * 1000 );
		$envelope = array(
			'event_uuid'   => $uuid,
			'event_type'   => 'assistant_message',
			'event_source' => 'server',
			'trace_id'     => $trace,
			'user_id'      => $owner_id > 0 ? $owner_id : null,
			'blog_id'      => $blog,
			'payload'      => $payload,
			// [2026-10-13 01:02 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.107 S107-L11 — number axis columns of the event stream.
			'account_id'   => $account,
			'actor_role'   => $role,
		);
		if ( '' !== $session_id ) {
			// PHASE-0.106 S106-C1: the TwinChat session (brain_sess_*) the cell echoed — Ask Brain's sessions VIEW groups by this column
			$envelope['session_id']       = $session_id;
			$envelope['created_epoch_ms'] = $now_ms;
		}
		try {
			self::ingest( $envelope );
			// PHASE-0.106 S106-C5 (D106-6): one `cell_output_created` per file, same session/trace; its uuid comes from the turn key + output id,
			// so a replayed turn-complete names the same rows. The bytes stay on the cell (7 days) until the site pulls them.
			$stored_files = 0;
			foreach ( $files as $i => $f ) {
				$created_uuid = self::uuid_from_key( $key . ':o:' . $f['output_id'] );
				self::ingest( array(
					'event_uuid'        => $created_uuid,
					'event_type'        => 'cell_output_created',
					'event_source'      => 'server',
					'trace_id'          => $trace,
					'session_id'        => '' !== $session_id ? $session_id : null,
					'user_id'           => $owner_id > 0 ? $owner_id : null,
					'blog_id'           => $blog,
					'parent_event_uuid' => $uuid,
					'account_id'        => $account, // PHASE-0.107 S107-L11
					'actor_role'        => $role,
					'created_epoch_ms'  => $now_ms + 1 + $i,
					'payload'           => $f + array( 'account_id' => $account, 'state' => 'pending' ),
				) );
				++$stored_files;
				// PHASE-0.106 S106-C5: a listener in channel-gateway (BizCity_Twin_Cell_Output_Store) copies image/audio/video to the media library
				do_action( 'bizcity_cell_output_created', array( 'event_uuid' => $created_uuid, 'user_id' => (int) $owner_id, 'account_id' => $account, 'session_id' => $session_id, 'trace_id' => $trace, 'output' => $f ) );
			}
		} catch ( \Throwable $e ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'event_invalid', 'message' => substr( $e->getMessage(), 0, 300 ) ), 422 );
		}
		do_action( self::HOOK_DONE, $envelope );
		return new WP_REST_Response( array( 'ok' => true, 'duplicate' => false, 'stored' => 1 + $stored_files ), 200 );
	}

	/** [2026-10-13 01:20 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.106 S106-C1 — the echoed TwinChat session id, or '' (same shape the event stream column takes). */
	public static function session_id( $raw ): string {
		$s = is_string( $raw ) ? trim( $raw ) : '';
		return ( '' !== $s && preg_match( '/^[A-Za-z0-9._:-]{1,64}$/', $s ) ) ? $s : '';
	}

	/**
	 * [2026-10-13 01:20 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.106 S106-C5 — `cell-output@1` rows, cleaned (≤ 10). Anything malformed is dropped,
	 * never stored: ids must match the cell's shapes, the name is plain text.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function files( $list ): array {
		$kinds = array( 'spreadsheet', 'document', 'presentation', 'pdf', 'image', 'audio', 'video', 'text' );
		$out   = array();
		foreach ( array_slice( is_array( $list ) ? $list : array(), 0, 10 ) as $f ) {
			if ( ! is_array( $f ) ) {
				continue;
			}
			$media  = (string) ( $f['media_id'] ?? '' );
			$output = (string) ( $f['output_id'] ?? '' );
			$name   = trim( sanitize_text_field( (string) ( $f['name'] ?? '' ) ) );
			$mime   = strtolower( trim( (string) ( $f['mime'] ?? '' ) ) );
			$kind   = (string) ( $f['kind'] ?? '' );
			if ( ! preg_match( '/^cm_[a-f0-9]{24}$/', $media ) || ! preg_match( '/^co_[a-f0-9]{24}$/', $output ) || '' === $name || ! preg_match( '#^[a-z0-9.+-]+/[a-z0-9.+-]+$#', $mime ) || ! in_array( $kind, $kinds, true ) ) {
				continue;
			}
			$out[] = array(
				'output_id'  => $output,
				'kind'       => $kind,
				'name'       => mb_substr( $name, 0, 200 ),
				'mime'       => substr( $mime, 0, 100 ),
				'bytes'      => max( 0, (int) ( $f['bytes'] ?? 0 ) ),
				'media_id'   => $media,
				'expires_at' => substr( sanitize_text_field( (string) ( $f['expires_at'] ?? '' ) ), 0, 40 ),
				'via'        => 'zalo' === ( $f['via'] ?? '' ) ? 'zalo' : 'cell_chat',
			);
		}
		return $out;
	}

	/** Deterministic UUID (version-5 shape) from the cell's idempotency key: the same turn always maps to the same row. */
	public static function uuid_from_key( string $key ): string {
		$h = sha1( 'twin-agent-turn|' . $key );
		return sprintf(
			'%s-%s-5%s-%s%s-%s',
			substr( $h, 0, 8 ),
			substr( $h, 8, 4 ),
			substr( $h, 13, 3 ),
			dechex( ( hexdec( $h[16] ) & 0x3 ) | 0x8 ),
			substr( $h, 17, 3 ),
			substr( $h, 20, 12 )
		);
	}

	/** @return string[] */
	private static function keys( $list ): array {
		return array_slice( array_values( array_filter( array_map( static function ( $v ) { return sanitize_key( (string) $v ); }, (array) $list ) ) ), 0, 20 );
	}

	private static function rows( $list, array $fields ): array {
		$out = array();
		foreach ( array_slice( (array) $list, 0, 50 ) as $r ) {
			if ( is_array( $r ) ) {
				$out[] = array_intersect_key( $r, array_flip( $fields ) );
			}
		}
		return $out;
	}

	public static function token( string $account ): string {
		if ( isset( self::$readers['token'] ) ) {
			return (string) call_user_func( self::$readers['token'], $account );
		}
		return class_exists( 'BizCity_Zalo_Bridge_Client' ) ? (string) BizCity_Zalo_Bridge_Client::instance()->expected_inbound_token( $account ) : '';
	}

	/** @param mixed $raw @return array{code:string,requested:string,effective:string}|null */
	private static function reasoning_limit( $raw ): ?array {
		if ( ! is_array( $raw ) ) {
			return null;
		}
		$ui   = array( 'fast', 'balanced', 'high', 'deep' );
		$code = sanitize_key( (string) ( $raw['code'] ?? '' ) );
		$req  = (string) ( $raw['requested'] ?? '' );
		$eff  = (string) ( $raw['effective'] ?? '' );
		if ( '' === $code || ! in_array( $req, $ui, true ) || ! in_array( $eff, $ui, true ) ) {
			return null;
		}
		return array( 'code' => $code, 'requested' => $req, 'effective' => $eff );
	}

	private static function staff( string $account, string $hash ): int {
		if ( isset( self::$readers['staff'] ) ) {
			return (int) call_user_func( self::$readers['staff'], $account, $hash );
		}
		$p = class_exists( 'BizCity_Zalo_Agent_Principals' ) ? BizCity_Zalo_Agent_Principals::by_hash( $account, $hash ) : null;
		return is_array( $p ) && 'staff' === $p['role'] ? (int) $p['user_id'] : 0;
	}

	public static function owner( string $account ): int {
		if ( isset( self::$readers['owner'] ) ) {
			return (int) call_user_func( self::$readers['owner'], $account );
		}
		$acc = class_exists( 'BizCity_Zalo_Mapping_Repo' ) ? BizCity_Zalo_Mapping_Repo::find_account_by_bridge_id( 'personal', $account ) : null;
		return is_array( $acc ) ? (int) ( $acc['owner_user_id'] ?? 0 ) : 0;
	}

	public static function exists( string $uuid ): bool {
		if ( isset( self::$readers['exists'] ) ) {
			return (bool) call_user_func( self::$readers['exists'], $uuid );
		}
		return class_exists( 'BizCity_Twin_Event_Store' ) && BizCity_Twin_Event_Store::exists( $uuid );
	}

	public static function ingest( array $envelope ): string {
		if ( isset( self::$readers['ingest'] ) ) {
			return (string) call_user_func( self::$readers['ingest'], $envelope );
		}
		return BizCity_Twin_Event_Bus::ingest_remote( $envelope );
	}
}
