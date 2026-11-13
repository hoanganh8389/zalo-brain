<?php
/**
 * BizCity_Vault_Knowledge_Search — `knowledge.search` answered from the Vault (Kho tri thức) through the Journal Index, for every principal.
 *
 *   customer turn (guru_public)  ⇒ BizCity_Twin_Vault::public_passages()     (Guru's PUBLIC folders, no internal code — PHASE-0.136, unchanged)
 *   owner / staff / their Guru   ⇒ BizCity_Twin_Vault::knowledge_passages()  (role gate + Guru folders, TL- code kept)
 *   + filter `bizcity_vault_knowledge_providers` (array $extra, $args, $ctx) — more passages of the same shape from an add-on
 *
 * The result goes out under `vault_passages` (the key the cell already reads: zalo-hub retrieve.ts ⇒ hits `kho:n`), so Hub and cell are unchanged.
 * The notebook (KG) passages stay under `passages` and come through BizCity_Notebook_Port. No model call, no regex routing.
 *
 * // [2026-11-13 Johnny Chu - Chu Hoàng Anh] PHASE-0.148 W2 S148-C8 (doc 20 §3, D148-4) — new file.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Vault
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Vault_Knowledge_Search', false ) ) {
	return;
}

final class BizCity_Vault_Knowledge_Search {
	const LIMIT = 3; // the cell reads ≤ 3 vault passages

	/** @return array<int,array> passages {title, folder, text, ref?, image_url?} — [] when nothing matched or the Vault is absent */
	public static function passages( array $args, array $ctx ): array {
		$q = trim( (string) ( $args['query'] ?? '' ) );
		if ( '' === $q || ! class_exists( 'BizCity_Twin_Vault', false ) ) {
			return array();
		}
		$guru = (int) ( $ctx['guru_character_id'] ?? 0 );
		if ( 'guru_public' === (string) ( $ctx['principal_kind'] ?? '' ) ) {
			$out = method_exists( 'BizCity_Twin_Vault', 'public_passages' ) ? BizCity_Twin_Vault::public_passages( $guru, $q, (int) ( $ctx['acting_user_id'] ?? 0 ) ) : array();
		} else {
			$out = method_exists( 'BizCity_Twin_Vault', 'knowledge_passages' ) ? BizCity_Twin_Vault::knowledge_passages( $guru, $q, $ctx, self::LIMIT ) : array();
		}
		if ( function_exists( 'apply_filters' ) ) {
			$extra = apply_filters( 'bizcity_vault_knowledge_providers', array(), $args, $ctx );
			foreach ( is_array( $extra ) ? $extra : array() as $p ) {
				if ( is_array( $p ) && '' !== trim( (string) ( $p['text'] ?? '' ) ) ) {
					$out[] = $p;
				}
			}
		}
		return array_slice( array_values( $out ), 0, self::LIMIT );
	}
}
