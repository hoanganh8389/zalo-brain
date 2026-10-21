<?php
/**
 * BizCity_Journal_Tool_Axis — R-BRAIN-SKELETON BS-3: a tool that WRITES brain data declares how it sits on the spine, in its own MCP
 * descriptor (`'journal' => {kind, source_ref, title, card[], job, job_prefix, goal}`). After the tool succeeds, the site writes ONE
 * journal row from the tool's result and links it under a Job header (BS-5: a write with a confirmation is a Job). The tool's own code
 * stays as it was; the journal is fed from one place (the registry's `bizcity_mcp_tool_finished`).
 *
 * `job`: `turn` = the turn id is a cell job (`job_…`) ⇒ that Job; `synthetic` = a one-shot job of the site, id `job_<prefix><ref>`, closed
 * `done` at once (a confirmed order, a published page); `none` = no Job.
 *
 * Biz Central Brain — Johnny Chu (Chu Hoàng Anh).
 *
 * [2026-10-20 11:52 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.126 W3 / W8 (doc 90 R103-01, R115-01, R116-03, R118-01) — new file.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\KG_Hub
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Journal_Tool_Axis', false ) ) {
	return;
}

final class BizCity_Journal_Tool_Axis {

	/** @var array<string,callable> test seams: owner(account_id), axis(tool) */
	public static $readers = array();

	private static $hooked = false;

	public static function register(): void {
		if ( self::$hooked ) {
			return;
		}
		self::$hooked = true;
		add_action( 'bizcity_mcp_tool_finished', array( __CLASS__, 'on_tool_finished' ), 20, 4 );
	}

	/** `{key}` placeholders from the tool result; unknown ⇒ ''. Pure. */
	public static function fill( string $tpl, array $data ): string {
		return (string) preg_replace_callback( '/\{([a-z0-9_]{1,40})\}/', static function ( $m ) use ( $data ) {
			$v = $data[ $m[1] ] ?? '';
			return is_scalar( $v ) ? (string) $v : '';
		}, $tpl );
	}

	/**
	 * The row (journal-entry@1.2 input) + the job block for one successful call, or null when the declaration / result is unusable. Pure.
	 *
	 * @param array $axis the descriptor's `journal` block
	 * @param array $data the tool result (`envelope.data`)
	 * @param array $ctx  {turn_id, user_hash, role}
	 * @return array{entry:array,job:array|null}|null
	 */
	public static function plan( string $tool, array $axis, array $data, array $ctx, int $owner, int $blog ): ?array {
		$kind = (string) ( $axis['kind'] ?? '' );
		$ref  = self::fill( (string) ( $axis['source_ref'] ?? '' ), $data );
		if ( $owner <= 0 || '' === $kind || '' === $ref || false !== strpos( $ref, '{' ) || preg_match( '/:$/', $ref ) ) {
			return null; // no person, no kind, or the result lacks the key the declaration names ⇒ nothing (never a half row)
		}
		$card = array();
		foreach ( (array) ( $axis['card'] ?? array() ) as $k ) {
			$k = (string) $k;
			if ( isset( $data[ $k ] ) && is_scalar( $data[ $k ] ) ) {
				$card[ $k ] = $data[ $k ];
			}
		}
		$title = trim( self::fill( (string) ( $axis['title'] ?? $tool ), $data ) );
		$hash  = strtolower( (string) ( $ctx['user_hash'] ?? '' ) );
		$entry = array(
			'owner_user_id'   => $owner,
			'kind'            => $kind,
			'title'           => '' !== $title ? mb_substr( $title, 0, 200 ) : $tool,
			'doc_date'        => gmdate( 'Y-m-d' ),
			'card'            => $card,
			'source_type'     => 'plugin',
			'source_ref'      => substr( $ref, 0, 120 ),
			'idempotency_key' => 'mcp:' . $blog . ':' . $tool . ':' . substr( $ref, 0, 100 ),
			'origin'          => array( 'surface' => 'plugin:mcp', 'ref' => $tool ),
			'by'              => array( 'role' => in_array( (string) ( $ctx['role'] ?? '' ), array( 'owner', 'staff', 'customer' ), true ) ? (string) $ctx['role'] : 'system' ),
		);
		// a product made for / sent to the customer of this turn: that customer may fetch it (BS-7a); a sensitive kind stays closed anyway
		if ( 'customer' === (string) ( $ctx['role'] ?? '' ) && 1 === preg_match( '/^[0-9a-f]{64}$/', $hash ) ) {
			$entry['access'] = array( 'customer' => 'off', 'delivered_to' => array( 'h:' . substr( $hash, 0, 16 ) ) );
		}
		$job  = null;
		$mode = (string) ( $axis['job'] ?? 'none' );
		$turn = (string) ( $ctx['turn_id'] ?? '' );
		if ( 'turn' === $mode || 'synthetic' === $mode ) {
			if ( 1 === preg_match( '/^job_[0-9A-Za-z]{10,40}$/', $turn ) ) {
				$job = array( 'job_id' => $turn, 'role' => 'output' );
			} elseif ( 'synthetic' === $mode ) {
				$id = (string) preg_replace( '/[^0-9A-Za-z]/', '', (string) ( $axis['job_prefix'] ?? 'mc' ) . str_pad( (string) preg_replace( '/[^0-9A-Za-z]/', '', substr( $ref, (int) strrpos( $ref, ':' ) + 1 ) ), 10, '0', STR_PAD_LEFT ) );
				if ( 1 === preg_match( '/^[0-9A-Za-z]{10,40}$/', $id ) ) {
					$job = array( 'job_id' => 'job_' . $id, 'role' => 'output', 'status' => 'done', 'goal' => mb_substr( self::fill( (string) ( $axis['goal'] ?? $title ), $data ), 0, 200 ) );
				}
			}
		}
		return array( 'entry' => $entry, 'job' => $job );
	}

	/**
	 * `bizcity_mcp_tool_finished( name, args, ctx, envelope )` — fired by BizCity_MCP_Tool_Registry::finish_call for a successful write.
	 */
	public static function on_tool_finished( $name, $args = array(), $ctx = array(), $envelope = array() ): void {
		if ( ! class_exists( 'BizCity_Journal_Index' ) || ! is_array( $envelope ) || empty( $envelope['success'] ) || ! is_array( $envelope['data'] ?? null ) ) {
			return;
		}
		$axis = isset( self::$readers['axis'] ) ? call_user_func( self::$readers['axis'], (string) $name ) : ( method_exists( 'BizCity_MCP_Tool_Registry', 'journal_of' ) ? BizCity_MCP_Tool_Registry::journal_of( (string) $name ) : null );
		if ( ! is_array( $axis ) ) {
			return;
		}
		$ctx  = is_array( $ctx ) ? $ctx : array();
		$data = $envelope['data'];
		if ( isset( $data['status'] ) && 'needs_confirmation' === (string) $data['status'] ) {
			return; // a preview is not a write
		}
		$owner = self::owner( $ctx );
		$blog  = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
		$plan  = self::plan( (string) $name, $axis, $data, $ctx, $owner, $blog );
		if ( null === $plan ) {
			return;
		}
		$row = BizCity_Journal_Index::add( $plan['entry'] );
		if ( is_array( $row ) && is_array( $plan['job'] ) && class_exists( 'BizCity_Journal_Job_Link' ) ) {
			BizCity_Journal_Job_Link::attach( $row, $owner, $plan['job'] );
		}
	}

	/** The person a row belongs to: the acting WP user, else the owner of the turn's number (a customer turn has no WP user), else the site owner. */
	private static function owner( array $ctx ): int {
		$uid = (int) ( $ctx['user_id'] ?? 0 );
		if ( $uid > 0 ) {
			return $uid;
		}
		$acc = (string) ( $ctx['account_id'] ?? '' );
		if ( isset( self::$readers['owner'] ) ) {
			return (int) call_user_func( self::$readers['owner'], $acc );
		}
		if ( '' !== $acc && class_exists( 'BizCity_Twin_Agent_Turn_Complete_REST' ) ) {
			$o = (int) BizCity_Twin_Agent_Turn_Complete_REST::owner( $acc );
			if ( $o > 0 ) {
				return $o;
			}
		}
		return class_exists( 'BizCity_Twin_Web_Home' ) ? (int) BizCity_Twin_Web_Home::owner_user_id() : 0;
	}
}
