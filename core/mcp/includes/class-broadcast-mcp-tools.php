<?php
/**
 * BizCity_Broadcast_MCP_Tools — `broadcast.pack` (PHASE-0.146 S146-L5, doc 21 §3): the cell pulls the approved pack of one
 * "Lịch thông báo" row when its clock fires (8 s budget, the snapshot of the letter is its fallback).
 *
 * | Tool | Kind | Who | Confirm |
 * |---|---|---|---|
 * | broadcast.pack | read, CELL-system (the model never sees it) | the number of the turn (delegation account_id) | never |
 *
 * Scope: only a `channel_kind = zalo_group_broadcast` row whose `account_ref` IS the number of the call — another number of the same
 * site, or another site, reads `not_found`. `rev` ≠ the row's rev ⇒ `broadcast_rev_mismatch` (the cell stops before sending).
 * The rows live in the Zalo Brain CRM add-on (BizCity_CRM_Announce_Store); without the add-on the tool is not registered.
 *
 * Biz Central Brain — Johnny Chu (Chu Hoàng Anh). Bizcity Central Brain, Giấy chứng nhận đăng ký quyền tác giả số 8877/2026/QTG.
 *
 * [2026-10-28 07:20 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.146 S146-L5 — new file. No class_exists guard (early-bind, see class-group-mcp-tools.php).
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\MCP
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_Broadcast_MCP_Tools {

	const SINCE = '0.146.0';

	/** Test seams: row(ref):?array · recipients(id):array · tz():string */
	public static $readers = array();

	private static $hooked = false;

	public static function init(): void {
		if ( self::$hooked ) {
			return;
		}
		self::$hooked = true;
		add_action( 'bizcity_mcp_register_tools', array( __CLASS__, 'register_tools' ) );
		if ( function_exists( 'did_action' ) && did_action( 'bizcity_mcp_register_tools' ) ) {
			self::register_tools();
		}
	}

	public static function register_tools(): void {
		if ( ! class_exists( 'BizCity_MCP_Tool_Registry' ) || ! class_exists( 'BizCity_CRM_Announce_Store', false ) ) {
			// the add-on loads after core: one late retry at init, then quiet (no CRM add-on ⇒ no tool, by design)
			if ( function_exists( 'add_action' ) && function_exists( 'did_action' ) && ! did_action( 'init' ) && ! has_action( 'init', array( __CLASS__, 'register_tools' ) ) ) {
				add_action( 'init', array( __CLASS__, 'register_tools' ), 99 );
			}
			return;
		}
		$R = 'BizCity_MCP_Tool_Registry';
		// @mcp bizcity-mcp-standard@1 tool broadcast.pack
		$R::register( 'broadcast.pack', array(
			'title'          => 'Gói một lịch thông báo',
			'description'    => 'Công cụ hệ thống (mô hình không gọi): cell kéo gói group-broadcast@1 đã duyệt của một lịch thông báo (nội dung, tệp, người nhận) của đúng số này, đúng rev. Không trả lịch của số khác.',
			'input_schema'   => array( 'type' => 'object', 'required' => array( 'broadcast_ref' ), 'properties' => array(
				'broadcast_ref' => array( 'type' => 'string', 'pattern' => '^BC-[a-f0-9]{6,32}$', 'description' => 'Mã lịch thông báo BC-… (từ thư job_upsert).' ),
				'rev'           => array( 'type' => 'integer', 'minimum' => 1, 'description' => 'Bản của lịch cell đang giữ; lệch ⇒ broadcast_rev_mismatch.' ),
			) ),
			'output_schema'  => $R::envelope_schema( array( 'pack' => array( 'type' => 'object' ) ), array( 'pack' ) ),
			'read_only'      => true,
			'idempotent'     => true,
			'required_scope' => 'brain.read',
			'handler'        => array( __CLASS__, 'pack' ),
			'mode'           => 'notebook',
			'roles'          => array( 'owner', 'staff' ),
			'scopes'         => array( 'brain.read' ),
			'confirm'        => 'never',
			'llm_alias'      => 'broadcast_pack',
			'fallback_pack'  => null,
			'since'          => self::SINCE,
		) );
	}

	/** @return array{pack:array}|WP_Error */
	public static function pack( array $args, array $ctx ) {
		$bridge = preg_replace( '/[^0-9]/', '', (string) ( $ctx['account_id'] ?? '' ) );
		$ref    = (string) ( $args['broadcast_ref'] ?? '' );
		$row    = '' !== $bridge && preg_match( '/^BC-[a-f0-9]{6,32}$/', $ref ) ? self::row( $ref ) : null;
		if ( ! is_array( $row ) || (string) ( $row['account_ref'] ?? '' ) !== $bridge || 'deleted' === (string) ( $row['state_reason'] ?? '' ) ) {
			return self::err( 'not_found', 'Không có lịch thông báo này cho số Zalo của lượt.', 404 );
		}
		$rev = isset( $args['rev'] ) ? (int) $args['rev'] : 0;
		if ( $rev > 0 && $rev !== (int) $row['rev'] ) {
			return self::err( 'broadcast_rev_mismatch', 'Lịch đã được sửa (rev ' . (int) $row['rev'] . '), gói rev ' . $rev . ' không còn.', 409 );
		}
		$recipients = isset( self::$readers['recipients'] ) ? (array) call_user_func( self::$readers['recipients'], (int) $row['id'] ) : BizCity_CRM_Announce_Store::recipients( (int) $row['id'] );
		$tz         = isset( self::$readers['tz'] ) ? (string) call_user_func( self::$readers['tz'] ) : BizCity_CRM_Announce_Store::tz();
		return array( 'pack' => BizCity_CRM_Announce_Store::build_pack( $row, $recipients, $tz ) );
	}

	private static function row( string $ref ) {
		if ( isset( self::$readers['row'] ) ) {
			return call_user_func( self::$readers['row'], $ref );
		}
		return BizCity_CRM_Announce_Store::get_by_ref( $ref );
	}

	private static function err( string $reason, string $message, int $status ): WP_Error {
		$mcp = class_exists( 'BizCity_MCP_Error' ) && defined( 'BizCity_MCP_Error::QUERY_INVALID' ) ? BizCity_MCP_Error::QUERY_INVALID : 'query_invalid';
		return new WP_Error( $mcp, $message, array( 'status' => $status, 'reason' => $reason ) );
	}
}

BizCity_Broadcast_MCP_Tools::init();
