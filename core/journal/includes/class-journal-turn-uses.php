<?php
/**
 * BizCity_Journal_Turn_Uses — PHASE-0.137 S137-B8 (D137-3/4, Q137-3): "Trợ lý đã dùng tệp này" from CHAT TURNS.
 *
 * Jobs, files made from a file and sends with a receipt are already read from the Journal (BizCity_Journal_Pipeline::uses_of). What the Journal
 * cannot tell is that a chat turn OPENED a file: the cell asked `journal.get {ref}`, `document.text.read {doc_code}` (read it) or
 * `document.file.link {doc_code}` (sent / downloaded it). This class notes those successful calls of a real turn (turn_id from X-BizCity-Turn-Id;
 * the cell's machine turns (em- read-at-save, ems- sweep, gsrc-, sb-, sched-, … see MACHINE_TURN_RE) are not uses) as one line {r ref · t turn hash · at · op · who} in a ring of the last
 * RING_MAX lines — one option, written ONCE at the end of the request (no table, R-LEAN-4; same pattern as BizCity_Scope_Meter). A customer turn is
 * kept ANONYMOUS (`who: guest`, Q137-3 = 1): the owner sees which files customers ask about, never who.
 * Called by BizCity_MCP_Tool_Registry::finish_call (guarded). Never throws; measuring never breaks a tool call.
 *
 * [2026-10-25 06:27 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.137 W4 B8 — new file (owner: "tập trung 137 hoàn thiện"; llm-router-3f takes W4).
 *
 * @package BizCity_KG_Hub
 */

defined( 'ABSPATH' ) || exit;

class BizCity_Journal_Turn_Uses {

	const OPTION   = 'bizcity_journal_turn_uses';
	const RING_MAX = 500;
	/** uses of ONE file shown on its card (the Journal uses come first, see BizCity_Journal_Pipeline::USES_MAX) */
	const PER_REF  = 10;
	const MACHINE_TURN_RE = '/^(em|ems|gsrc|sb|sched|dash|ev|fork|gb|rep)-/';
	/** tool ⇒ [ argument holding the code, op ] */
	const TOOLS = array(
		'journal.get'        => array( 'ref', 'get' ),
		'document.text.read' => array( 'doc_code', 'read' ),
		'document.file.link' => array( 'doc_code', 'send' ),
	);

	/** @var array<int,array> lines of this request, flushed at shutdown */
	private static $pending = array();
	private static $hooked  = false;

	/** finish_call hook: queue the line of a successful file read / send by a real turn. */
	public static function observe( string $name, array $args, array $ctx, array $envelope ): void {
		try {
			$line = self::line_of( $name, $args, $ctx, $envelope, time() );
			if ( null === $line ) {
				return;
			}
			self::$pending[] = $line;
			if ( ! self::$hooked && function_exists( 'add_action' ) ) {
				self::$hooked = true;
				add_action( 'shutdown', array( __CLASS__, 'flush' ) );
			}
		} catch ( \Throwable $e ) {
			unset( $e );
		}
	}

	/**
	 * Pure: one call ⇒ one use line, or null (not a file tool · failed · no real turn · not a TL- code).
	 *
	 * @return array{r:string,t:string,at:int,op:string,who:string}|null
	 */
	public static function line_of( string $name, array $args, array $ctx, array $envelope, int $now ): ?array {
		if ( ! isset( self::TOOLS[ $name ] ) || empty( $envelope['success'] ) ) {
			return null;
		}
		$turn = trim( (string) ( $ctx['turn_id'] ?? '' ) );
		// the cell's MACHINE turns read files for the system, not for a person (turnId prefixes in zalo-hub/src, checked 2026-10-25): em read-at-save ·
		// ems sweep · gsrc generated source · sb job-result save · sched scheduler mirror · dash dashboard · ev evidence · fork skill fork · gb group brief job ·
		// rep journal-first report. A chat turn is `<cell>-t<id>` (Zalo) or the site's trace_id (web / channel).
		if ( '' === $turn || 1 === preg_match( self::MACHINE_TURN_RE, $turn ) ) {
			return null;
		}
		[ $key, $op ] = self::TOOLS[ $name ];
		$ref = strtoupper( trim( (string) ( $args[ $key ] ?? '' ) ) );
		$ref = (string) preg_replace( '/@.*$/', '', $ref ); // TL-47@v2 ⇒ the file TL-47
		if ( 1 !== preg_match( '/^TL-\d{1,12}$/', $ref ) ) {
			return null;
		}
		$role  = (string) ( $ctx['role'] ?? '' );
		$guest = 'guru_public' === (string) ( $ctx['principal_kind'] ?? '' ) || in_array( $role, array( 'customer', 'contact', 'group_member' ), true );
		return array(
			'r'   => $ref,
			't'   => substr( hash( 'sha256', $turn ), 0, 12 ),
			'at'  => $now,
			'op'  => $op,
			'who' => $guest ? 'guest' : ( 'staff' === $role ? 'staff' : 'owner' ),
		);
	}

	/**
	 * Pure: the ring after adding new lines — one line per (file, turn) keeps its strongest op (send > read > get), newest first, ≤ $max.
	 *
	 * @param array<int,array> $ring
	 * @param array<int,array> $new
	 * @return array<int,array>
	 */
	public static function merged( array $ring, array $new, int $max = self::RING_MAX ): array {
		$rank = array( 'get' => 1, 'read' => 2, 'send' => 3 );
		$by   = array();
		foreach ( array_merge( $new, $ring ) as $l ) {
			if ( ! is_array( $l ) || empty( $l['r'] ) || empty( $l['t'] ) ) {
				continue;
			}
			$k = $l['r'] . '|' . $l['t'];
			if ( ! isset( $by[ $k ] ) ) {
				$by[ $k ] = $l;
				continue;
			}
			if ( ( $rank[ $l['op'] ] ?? 0 ) > ( $rank[ $by[ $k ]['op'] ] ?? 0 ) ) {
				$by[ $k ]['op'] = $l['op'];
			}
			$by[ $k ]['at'] = max( (int) $by[ $k ]['at'], (int) $l['at'] );
		}
		$out = array_values( $by );
		usort( $out, static function ( $a, $b ) { return (int) $b['at'] <=> (int) $a['at']; } );
		return array_slice( $out, 0, max( 1, $max ) );
	}

	/**
	 * Pure: the uses of one file as card rows (`use: chat`), newest first, ≤ PER_REF.
	 *
	 * @return array<int,array{entry_id:int,ref:string,title:string,at:string,use:string,op:string,who:string,turn:string}>
	 */
	public static function uses_for( array $ring, string $ref ): array {
		$ref = strtoupper( (string) preg_replace( '/@.*$/', '', trim( $ref ) ) );
		$out = array();
		foreach ( $ring as $l ) {
			if ( ! is_array( $l ) || ( $l['r'] ?? '' ) !== $ref ) {
				continue;
			}
			$out[] = array(
				'entry_id' => 0,
				'ref'      => $ref,
				'title'    => '',
				'at'       => gmdate( 'Y-m-d H:i', (int) ( $l['at'] ?? 0 ) ),
				'use'      => 'chat',
				'op'       => (string) ( $l['op'] ?? 'get' ),
				'who'      => (string) ( $l['who'] ?? 'owner' ),
				'turn'     => (string) ( $l['t'] ?? '' ),
			);
			if ( count( $out ) >= self::PER_REF ) {
				break;
			}
		}
		return $out;
	}

	/** The stored ring (newest first). */
	public static function ring(): array {
		$r = function_exists( 'get_option' ) ? get_option( self::OPTION, array() ) : array();
		return is_array( $r ) ? $r : array();
	}

	/** shutdown: one write per request. */
	public static function flush(): void {
		if ( ! self::$pending || ! function_exists( 'update_option' ) ) {
			return;
		}
		try {
			update_option( self::OPTION, self::merged( self::ring(), self::$pending ), false );
		} catch ( \Throwable $e ) {
			unset( $e );
		}
		self::$pending = array();
	}

	/** test seam */
	public static function reset(): void {
		self::$pending = array();
		self::$hooked  = false;
	}
}
