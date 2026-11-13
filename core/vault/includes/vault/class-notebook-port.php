<?php
/**
 * BizCity_Notebook_Port — the ONE door from the core (Channel Gateway + Vault + Journal) to the optional notebook (KG + TwinChat).
 *
 * Default = "no notebook": every call answers the documented empty value, so a site without the notebook still answers from its Vault.
 * The notebook registers an implementation object (`register()`); the object may implement any subset of the methods below — a missing
 * method falls back to the default. The core never names a KG / TwinChat class; it asks this port.
 *
 *   available(): bool                                 false
 *   search( array $args, array $ctx ): ?array         null    (knowledge.search notebook passages — retrieval-snapshot@1 shape)
 *   notebooks_for_guru( int $guru_id ): int[]          []
 *   notebook_for_number( string $account_id ): int     0
 *   stats(): array                                     {notebooks: 0}
 *   graph_ask( array $args, array $ctx ): array        {ok: false, code: not_available}
 *
 * // [2026-11-13 Johnny Chu - Chu Hoàng Anh] PHASE-0.148 W2 S148-C7 (doc 20 §4, D148-5) — new file.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Vault
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Notebook_Port', false ) ) {
	return;
}

final class BizCity_Notebook_Port {

	/** @var object|null */
	private static $impl = null;

	/** The notebook registers itself (once; a second registration replaces the first — the last plugin loaded wins). */
	public static function register( $impl ): void {
		self::$impl = is_object( $impl ) ? $impl : null;
	}

	/** Tests / uninstall: back to "no notebook". */
	public static function reset(): void {
		self::$impl = null;
	}

	public static function available(): bool {
		return null !== self::$impl && ( ! method_exists( self::$impl, 'available' ) || true === (bool) self::$impl->available() );
	}

	/** @return array|null notebook search result (passages + allowed_citations + retrieval_snapshot_id), or null without the notebook */
	public static function search( array $args, array $ctx ) {
		$r = self::call( 'search', null, $args, $ctx );
		return is_array( $r ) || ( is_object( $r ) && is_a( $r, 'WP_Error' ) ) ? $r : null;
	}

	/** @return int[] */
	public static function notebooks_for_guru( int $guru_id ): array {
		return array_values( array_filter( array_map( 'intval', (array) self::call( 'notebooks_for_guru', array(), $guru_id ) ) ) );
	}

	public static function notebook_for_number( string $account_id ): int {
		return (int) self::call( 'notebook_for_number', 0, $account_id );
	}

	public static function stats(): array {
		$s = self::call( 'stats', null );
		return is_array( $s ) ? $s : array( 'notebooks' => 0 );
	}

	public static function graph_ask( array $args, array $ctx ): array {
		$r = self::call( 'graph_ask', null, $args, $ctx );
		return is_array( $r ) ? $r : array( 'ok' => false, 'code' => 'not_available', 'message' => 'Chưa bật Notebook trên site này.' );
	}

	/** @return mixed the implementation's answer, or $default when there is no notebook / no such method */
	private static function call( string $method, $default, ...$args ) {
		if ( ! self::available() || ! method_exists( self::$impl, $method ) ) {
			return $default;
		}
		return self::$impl->$method( ...$args );
	}
}
