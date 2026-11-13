<?php
/**
 * BizCity_KG_Notebook_Port_Impl — the notebook's answer to BizCity_Notebook_Port (core/vault): while the KG lives in core/kg-hub it registers here;
 * in W3 this file moves to the bizcity-twin-notebook plugin unchanged. Methods not implemented fall back to the port's "no notebook" value.
 *
 * // [2026-11-13 Johnny Chu - Chu Hoàng Anh] PHASE-0.148 W2 S148-C7 (doc 20 §4, D148-5) — new file.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\KG_Hub
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_KG_Notebook_Port_Impl', false ) ) {
	return;
}

final class BizCity_KG_Notebook_Port_Impl {

	public static function boot(): void {
		if ( class_exists( 'BizCity_Notebook_Port', false ) ) {
			BizCity_Notebook_Port::register( new self() );
		}
	}

	public function available(): bool {
		return class_exists( 'BizCity_Brain_MCP_Service' );
	}

	/** knowledge.search over the notebooks (KG retriever) — exactly the call knowledge_search_counted made before PHASE-0.148. */
	public function search( array $args, array $ctx ) {
		return BizCity_Brain_MCP_Service::instance()->search( $args, $ctx );
	}
}
