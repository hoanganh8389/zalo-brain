<?php
/**
 * BizCity_Memory_MCP_Service — `memory.save` / `memory.recall` / `memory.forget`: the cell's long-term memory about a
 * PERSON lives on the client (R-MEMORY-5-95 R-M595-5), in the store Bot Studio already uses — "Ghi nhớ lâu dài":
 * BizCity_User_Memory (encrypted JSONL + Context Bank pointer, keyed by Identity Hub `identity_uuid`). No new table.
 *
 * Owner decision Q-MEM-1 (2026-10-08): only what the user ASKED to remember (`kind: explicit`) and corrections
 * (`kind: correction` + `replaces` = the old fact) come here; what the bot noticed by itself stays in the cell.
 *
 * Who the memory is about (`contact`), never who is asking:
 *   - zalo  { platform:'zalo', channel_ref, platform_uid } — channel_ref MUST be the number of this delegated call
 *           (ctx account_id): an owner/staff of number X only touches people who talk to number X.
 *           Identity = Identity Hub ZALO_PERSONAL (account_id, uid), same tuple as BizCity_Bot_Memory.
 *   - wp    { platform:'wp', platform_uid } — only the caller's own WP user.
 *   - absent ⇒ the caller's own person (BizCity_Contact_Identity::person_of_user).
 * Group / thread memory is not personal memory (R-CH-IDMEM) ⇒ `memory_scope_unsupported`.
 *
 * System tools: only the cell calls them (cell `CELL_SYSTEM_MCP_TOOLS`); the model keeps using its builtin save_memory.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\MCP
 * @since      2026-10-08 (PHASE-0.94 MEM-H1)
 */

defined( 'ABSPATH' ) || exit;

// [2026-10-08 02:42 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.94-MEM-H1 — new file, long-term person memory over MCP (Q-MEM-1).
final class BizCity_Memory_MCP_Service {

	const TEXT_MIN     = 5;
	const TEXT_MAX     = 500;
	const RECALL_MAX   = 50;
	const SOURCE       = 'cell';
	const KINDS        = array( 'explicit', 'correction' );

	/** @var callable|null test seam: fn(array $contact, array $ctx, bool $create): string identity_uuid|'' */
	public static $identity = null;

	public static function init() {
		add_action( 'bizcity_mcp_register_tools', array( __CLASS__, 'register_tools' ) );
	}

	public static function register_tools() {
		if ( ! class_exists( 'BizCity_Bot_Memory' ) || ! method_exists( 'BizCity_Bot_Memory', 'store_save' ) ) {
			return; // memory owner not loaded: the tools do not exist (never a fake success)
		}
		$S       = 'BizCity_MCP_Tool_Registry';
		$contact = array( 'type' => 'object', 'properties' => array(
			'platform'     => array( 'type' => 'string', 'enum' => array( 'zalo', 'wp' ) ),
			'channel_ref'  => array( 'type' => 'string', 'maxLength' => 64 ),
			'platform_uid' => array( 'type' => 'string', 'maxLength' => 64 ),
		) );

		// @mcp bizcity-mcp-standard@1 tool memory.save
		BizCity_MCP_Tool_Registry::register( 'memory.save', array(
			'title'          => 'Ghi nhớ lâu dài về một người',
			'description'    => 'Đồng bộ hệ thống (chỉ zalo-hub gọi): lưu một điều người dùng CHỦ ĐỘNG bảo nhớ (kind explicit) hoặc một lần ĐÍNH CHÍNH (kind correction, replaces = điều cũ) vào "Ghi nhớ lâu dài" của website, theo người (contact).',
			'input_schema'   => array( 'type' => 'object', 'required' => array( 'text' ), 'properties' => array(
				'text'     => array( 'type' => 'string', 'minLength' => self::TEXT_MIN, 'maxLength' => self::TEXT_MAX ),
				'kind'     => array( 'type' => 'string', 'enum' => self::KINDS, 'default' => 'explicit' ),
				'replaces' => array( 'type' => 'string', 'maxLength' => self::TEXT_MAX ),
				'about'    => array( 'type' => 'string', 'enum' => array( 'sender', 'thread' ), 'default' => 'sender' ),
				'contact'  => $contact,
				'source'   => array( 'type' => 'string', 'maxLength' => 40 ),
			) ),
			'output_schema'  => $S::envelope_schema( array(
				'saved'     => array( 'type' => 'boolean' ),
				'duplicate' => array( 'type' => 'boolean' ),
				'replaced'  => array( 'type' => 'boolean' ),
			), array( 'saved' ) ),
			'read_only'      => false,
			'destructive'    => false,
			'idempotent'     => true,
			'required_scope' => 'memory.write',
			'handler'        => array( __CLASS__, 'save' ),
			'mode'           => 'notebook',
			'scopes'         => array( 'memory.write' ),
			'confirm'        => 'never',
			'llm_alias'      => 'memory_save',
			'fallback_pack'  => null,
			'since'          => '0.94.0',
		) );

		// @mcp bizcity-mcp-standard@1 tool memory.recall
		BizCity_MCP_Tool_Registry::register( 'memory.recall', array(
			'title'          => 'Đọc ghi nhớ lâu dài về một người',
			'description'    => 'Đồng bộ hệ thống (chỉ zalo-hub gọi): các điều đã ghi nhớ lâu dài về một người (contact), cũ trước mới sau, tối đa 50.',
			'input_schema'   => array( 'type' => 'object', 'properties' => array(
				'contact' => $contact,
				'limit'   => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => self::RECALL_MAX, 'default' => 12 ),
			) ),
			'output_schema'  => $S::envelope_schema( array(
				'facts' => array( 'type' => 'array' ),
				'total' => array( 'type' => 'integer' ),
			), array( 'facts' ) ),
			'read_only'      => true,
			'idempotent'     => true,
			'required_scope' => 'memory.read',
			'handler'        => array( __CLASS__, 'recall' ),
			'mode'           => 'notebook',
			'scopes'         => array( 'memory.read' ),
			'confirm'        => 'never',
			'llm_alias'      => 'memory_recall',
			'fallback_pack'  => null,
			'since'          => '0.94.0',
		) );

		// @mcp bizcity-mcp-standard@1 tool memory.forget
		BizCity_MCP_Tool_Registry::register( 'memory.forget', array(
			'title'          => 'Bỏ một điều đã ghi nhớ',
			'description'    => 'Đồng bộ hệ thống (chỉ zalo-hub gọi): bỏ một điều đã ghi nhớ về một người, theo record_id hoặc đoạn chữ khớp đúng MỘT điều.',
			'input_schema'   => array( 'type' => 'object', 'properties' => array(
				'record_id' => array( 'type' => 'string', 'maxLength' => 80 ),
				'text'      => array( 'type' => 'string', 'minLength' => 3, 'maxLength' => self::TEXT_MAX ),
				'contact'   => $contact,
			) ),
			'output_schema'  => $S::envelope_schema( array(
				'forgotten' => array( 'type' => 'boolean' ),
				'text'      => array( 'type' => 'string' ),
			), array( 'forgotten' ) ),
			'read_only'      => false,
			'destructive'    => true,
			'idempotent'     => true,
			'required_scope' => 'memory.write',
			'handler'        => array( __CLASS__, 'forget' ),
			'mode'           => 'notebook',
			'scopes'         => array( 'memory.write' ),
			'confirm'        => 'never',
			'llm_alias'      => 'memory_forget',
			'fallback_pack'  => null,
			'since'          => '0.94.0',
		) );
	}

	/* ── handlers ───────────────────────────────────────────────────── */

	public static function save( array $args, array $ctx ) {
		return BizCity_MCP_Action_Support::run_as( $ctx, static function ( $uid ) use ( $args, $ctx ) {
			if ( 'thread' === (string) ( $args['about'] ?? 'sender' ) ) {
				return self::unsupported();
			}
			$text = self::clip( (string) ( $args['text'] ?? '' ) );
			if ( self::len( $text ) < self::TEXT_MIN ) {
				return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::QUERY_INVALID, 'Nội dung cần nhớ quá ngắn.', 422 );
			}
			if ( BizCity_Bot_Memory::looks_like_otp( $text ) ) {
				return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::QUERY_INVALID, 'Nội dung trông như mã OTP/xác thực — không lưu vào trí nhớ lâu dài.', 422, array( 'reason' => 'memory_secret_refused' ) );
			}
			$kind = in_array( (string) ( $args['kind'] ?? '' ), self::KINDS, true ) ? (string) $args['kind'] : 'explicit';
			$uuid = self::identity( $args, $ctx, (int) $uid, true );
			if ( is_wp_error( $uuid ) ) {
				return $uuid;
			}
			$op = BizCity_Bot_Memory::store_save( $uuid, $text, array(
				'source'           => self::SOURCE,
				'kind'             => $kind,
				'learned_in_group' => false,
				'account_id'       => (string) ( $ctx['account_id'] ?? '' ),
				'by_user_id'       => (int) $uid,
			) );
			if ( 'insert' !== $op && 'update' !== $op ) {
				return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::INTERNAL_ERROR, 'Không ghi được vào trí nhớ lâu dài của website.', 503, array( 'reason' => 'memory_write_failed' ) );
			}
			$replaced = false;
			$old      = trim( (string) ( $args['replaces'] ?? '' ) );
			if ( 'correction' === $kind && '' !== $old && $old !== $text ) {
				foreach ( (array) BizCity_Bot_Memory::store_read_as_principal( $uuid, self::delegation( $ctx, 'mcp_memory_save' ) ) as $row ) { // [2026-10-08 02:42 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.94-MEM-H3
					$row = (array) $row;
					if ( self::lower( trim( (string) ( $row['memory_text'] ?? '' ) ) ) === self::lower( $old ) && '' !== (string) ( $row['record_id'] ?? '' ) ) {
						$replaced = BizCity_Bot_Memory::store_forget( $uuid, (string) $row['record_id'] ) || $replaced;
					}
				}
			}
			return array( 'saved' => true, 'duplicate' => 'update' === $op, 'replaced' => $replaced );
		} );
	}

	public static function recall( array $args, array $ctx ) {
		return BizCity_MCP_Action_Support::run_as( $ctx, static function ( $uid ) use ( $args, $ctx ) {
			$uuid = self::identity( $args, $ctx, (int) $uid, false );
			if ( is_wp_error( $uuid ) ) {
				return $uuid;
			}
			if ( '' === $uuid ) {
				return array( 'facts' => array(), 'total' => 0 ); // nobody bound yet ⇒ nothing remembered (reading never creates)
			}
			$limit = max( 1, min( self::RECALL_MAX, (int) ( $args['limit'] ?? 12 ) ) );
			$facts = self::facts( $uuid, self::delegation( $ctx, 'mcp_memory_recall' ) );
			if ( null === $facts ) {
				return self::read_denied();
			}
			return array( 'facts' => array_slice( $facts, -$limit ), 'total' => count( $facts ) );
		} );
	}

	public static function forget( array $args, array $ctx ) {
		return BizCity_MCP_Action_Support::run_as( $ctx, static function ( $uid ) use ( $args, $ctx ) {
			$uuid = self::identity( $args, $ctx, (int) $uid, false );
			if ( is_wp_error( $uuid ) ) {
				return $uuid;
			}
			$facts = '' === $uuid ? array() : self::facts( $uuid, self::delegation( $ctx, 'mcp_memory_forget' ) );
			if ( null === $facts ) {
				return self::read_denied();
			}
			$id    = trim( (string) ( $args['record_id'] ?? '' ) );
			$snip  = trim( (string) ( $args['text'] ?? '' ) );
			$hit   = array();
			foreach ( $facts as $f ) {
				if ( ( '' !== $id && $f['record_id'] === $id ) || ( '' === $id && '' !== $snip && false !== strpos( self::lower( $f['text'] ), self::lower( $snip ) ) ) ) {
					$hit[] = $f;
				}
			}
			if ( empty( $hit ) ) {
				return array( 'forgotten' => false, 'text' => '' ); // already gone ⇒ idempotent
			}
			if ( count( array_unique( array_column( $hit, 'text' ) ) ) > 1 ) {
				return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::QUERY_INVALID, 'Đoạn chữ khớp nhiều điều đang nhớ — cần cụ thể hơn.', 422, array( 'reason' => 'memory_ambiguous', 'matches' => array_values( array_unique( array_column( $hit, 'text' ) ) ) ) );
			}
			$ok = false;
			foreach ( $hit as $f ) {
				$ok = BizCity_Bot_Memory::store_forget( $uuid, $f['record_id'] ) || $ok;
			}
			return array( 'forgotten' => $ok, 'text' => $hit[0]['text'] );
		} );
	}

	/* ── identity of the person the memory is about ─────────────────── */

	/**
	 * @return string|WP_Error identity_uuid ('' = not bound yet, only when $create is false)
	 */
	private static function identity( array $args, array $ctx, int $uid, bool $create ) {
		$c        = is_array( $args['contact'] ?? null ) ? $args['contact'] : array();
		$platform = strtolower( trim( (string) ( $c['platform'] ?? '' ) ) );
		$puid     = trim( (string) ( $c['platform_uid'] ?? '' ) );
		if ( is_callable( self::$identity ) ) {
			$u = call_user_func( self::$identity, $c, $ctx, $create );
			return is_wp_error( $u ) ? $u : strtolower( (string) $u );
		}
		if ( 'zalo' === $platform ) {
			$number = trim( (string) ( $ctx['account_id'] ?? '' ) );
			$ref    = trim( (string) ( $c['channel_ref'] ?? '' ) );
			if ( '' === $puid || '' === $number || ( '' !== $ref && $ref !== $number ) ) {
				// an owner/staff of number X only touches people of number X
				return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::SCOPE_DENIED, 'Người này không thuộc số Zalo của lượt gọi.', 403, array( 'reason' => 'memory_contact_wrong_number' ) );
			}
			$u = BizCity_Bot_Memory::identity_for_claim( array( 'account_id' => $number, 'sender_uid' => $puid, 'chat_kind' => 'user' ), $create );
			return '' === $u && $create ? self::no_identity() : $u;
		}
		if ( '' === $platform || 'wp' === $platform ) {
			if ( 'wp' === $platform && '' !== $puid && (int) $puid !== $uid ) {
				return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::SCOPE_DENIED, 'Chỉ ghi nhớ được cho chính tài khoản của bạn.', 403, array( 'reason' => 'memory_contact_not_self' ) );
			}
			$u = class_exists( 'BizCity_Contact_Identity' ) ? (string) BizCity_Contact_Identity::person_of_user( $uid ) : '';
			return '' === $u && $create ? self::no_identity() : strtolower( $u );
		}
		return self::unsupported();
	}

	/** @return array<int,array{record_id:string,text:string,created_at:string,kind:string}>|null oldest first; null = may not read */
	private static function facts( string $uuid, array $delegation = array() ) {
		$rows = BizCity_Bot_Memory::store_read_as_principal( $uuid, $delegation ); // [2026-10-08 02:42 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.94-MEM-H3 — MCP = REST + logged-in principal: no runtime grant here
		if ( null === $rows ) {
			return null;
		}
		$out = array();
		foreach ( $rows as $row ) {
			$row  = (array) $row;
			$text = trim( (string) ( $row['memory_text'] ?? '' ) );
			if ( '' === $text ) {
				continue;
			}
			$meta  = is_array( $row['metadata'] ?? null ) ? $row['metadata'] : json_decode( (string) ( $row['metadata'] ?? '' ), true );
			$meta  = is_array( $meta ) ? $meta : array();
			if ( ! empty( $meta['learned_in_group'] ) ) {
				continue; // a group-learned fact never leaves its group (R-CH-IDMEM)
			}
			$out[] = array(
				'record_id'  => (string) ( $row['record_id'] ?? '' ),
				'text'       => $text,
				'created_at' => (string) ( $row['created_at'] ?? '' ),
				'kind'       => (string) ( $meta['kind'] ?? 'explicit' ),
			);
		}
		usort( $out, static function ( $a, $b ) {
			return strcmp( $a['created_at'], $b['created_at'] );
		} );
		return $out;
	}

	/**
	 * [2026-10-08 02:42 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.94-Q81-1 — Context Bank delegated grant is opened ONLY for a cell-delegated owner/staff principal of a number (ctx built by
	 * BizCity_MCP_Delegation::context from the Hub pipe, never from arguments). Any other caller (OAuth / API key) ⇒ none.
	 */
	private static function delegation( array $ctx, string $reason ): array {
		$delegated = class_exists( 'BizCity_MCP_Delegation' ) && BizCity_MCP_Delegation::AUTH_METHOD === (string) ( $ctx['auth_method'] ?? '' );
		if ( ! $delegated || ! in_array( (string) ( $ctx['role'] ?? '' ), array( 'owner', 'staff' ), true ) || '' === (string) ( $ctx['account_id'] ?? '' ) ) {
			return array();
		}
		return array( 'account_id' => (string) $ctx['account_id'], 'reason' => $reason );
	}

	private static function read_denied() {
		// [2026-10-08 02:42 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.94-MEM-H3 — honest: "you may not read it" ≠ "nothing remembered"; the cell keeps its own copy for this person
		return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::SCOPE_DENIED, 'Tài khoản gọi chưa được đọc ghi nhớ lâu dài của người này (cần quản trị viên).', 403, array( 'reason' => 'memory_read_scope_denied' ) );
	}

	private static function unsupported() {
		return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::QUERY_INVALID, 'Chỉ ghi nhớ lâu dài về MỘT người; điều về nhóm ở lại trợ lý.', 422, array( 'reason' => 'memory_scope_unsupported' ) );
	}

	private static function no_identity() {
		return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::INTERNAL_ERROR, 'Chưa xác định được người này trên website (Identity Hub).', 503, array( 'reason' => 'memory_identity_unavailable' ) );
	}

	private static function clip( string $s ): string {
		$s = trim( (string) preg_replace( '/\s+/u', ' ', $s ) );
		return function_exists( 'mb_substr' ) ? (string) mb_substr( $s, 0, self::TEXT_MAX, 'UTF-8' ) : substr( $s, 0, self::TEXT_MAX );
	}

	private static function len( string $s ): int {
		return function_exists( 'mb_strlen' ) ? (int) mb_strlen( $s, 'UTF-8' ) : strlen( $s );
	}

	private static function lower( string $s ): string {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $s, 'UTF-8' ) : strtolower( $s );
	}
}

BizCity_Memory_MCP_Service::init();
