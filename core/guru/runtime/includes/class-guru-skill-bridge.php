<?php
/**
 * BizCity Twin AI — Guru ↔ Skill (Tool) bridge (Phase B / F7.B2).
 *
 * Static PHP API. Used by `BizCity_CRM_Admin_Chat_Policy` to gate which
 * tools a given character/guru is allowed to call (R-MPRT-5 anti-jailbreak).
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Persona
 * @since      1.4.0
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Guru_Skill_Bridge', false ) ) {
	return;
}

class BizCity_Guru_Skill_Bridge {

	const VALID_CLASSES = array( 'producer', 'distributor', 'retriever' );

	/**
	 * Return tool ids enabled for a given guru/character.
	 *
	 * Empty array = no binding row exists → caller (policy) treats as
	 * "no restriction" or "deny all" depending on its own contract.
	 * `BizCity_CRM_Admin_Chat_Policy` treats empty as "no restriction".
	 *
	 * @return string[]
	 */
	/**
	 * [2026-10-25 Johnny Chu - Chu Hoàng Anh] PHASE-0.126 R126-TB (owner: "bizcity_guru_skills cũng bỏ, giờ qua vault, gắn vault vào guru") — the Guru ↔ tool binding table is quarantined:
	 * it never had a writer, so every Guru already meant "no restriction" (empty list). What a Guru knows comes from its Vault /
	 * notebooks; what it may do is the cell's tool policy. The API stays (brain add-on + CRM admin-chat call it) and answers without SQL.
	 */
	public static function tools_for_guru( int $guru_id ): array {
		return array();
	}

	/** @return array<int,array<string,mixed>> */
	public static function list_for_guru( int $guru_id ): array {
		return array(); // [2026-10-25 Johnny Chu - Chu Hoàng Anh] PHASE-0.126 R126-TB — no binding rows any more.
	}

	/**
	 * Attach a tool to a guru (idempotent — UPSERT on uniq_guru_tool).
	 *
	 * @param int    $guru_id
	 * @param string $tool_id     Tool name as registered by provider.
	 * @param string $tool_class  producer|distributor|retriever (default producer).
	 * @param int    $priority    Lower = higher priority. Default 100.
	 * @return int|false Inserted/updated row id, or false on validation fail.
	 */
	public static function attach( int $guru_id, string $tool_id, string $tool_class = 'producer', int $priority = 100 ) {
		return false; // [2026-10-25 Johnny Chu - Chu Hoàng Anh] PHASE-0.126 R126-TB — binding retired (Guru = instruction + Vault).
	}

	public static function detach( int $guru_id, string $tool_id ): bool {
		return false; // [2026-10-25 Johnny Chu - Chu Hoàng Anh] PHASE-0.126 R126-TB — binding retired.
	}

	public static function set_enabled( int $guru_id, string $tool_id, bool $enabled ): bool {
		return false; // [2026-10-25 Johnny Chu - Chu Hoàng Anh] PHASE-0.126 R126-TB — binding retired.
	}

	/**
	 * Tools known to the runtime (Layer 2 + Layer 3 walk) but not yet bound to this guru.
	 *
	 * @return array<int,array{tool_id:string,tool_class:string,source:string}>
	 */
	public static function list_unbound_for_guru( int $guru_id ): array {
		$bound = array_flip( self::tools_for_guru( $guru_id ) );
		$known = self::all_known_tools();
		$out   = array();
		foreach ( $known as $t ) {
			if ( ! isset( $bound[ $t['tool_id'] ] ) ) { $out[] = $t; }
		}
		return $out;
	}

	/**
	 * Walk Layer 2 + Layer 3 to enumerate every tool currently registered.
	 *
	 * @return array<int,array{tool_id:string,tool_class:string,source:string}>
	 */
	public static function all_known_tools(): array {
		$out = array();
		$seen = array();

		// Layer 2 — agent tools.
		$agents = apply_filters( 'bizcity_register_agent', array() );
		if ( is_array( $agents ) ) {
			foreach ( $agents as $agent ) {
				$tools = is_array( $agent ) ? ( $agent['tools'] ?? null ) : ( is_object( $agent ) ? ( $agent->tools ?? null ) : null );
				if ( ! is_array( $tools ) ) { continue; }
				foreach ( $tools as $tool ) {
					$tid = self::probe_field( $tool, array( 'name', 'id' ) );
					$tc  = self::probe_field( $tool, array( 'tool_class' ) );
					if ( $tid === '' || isset( $seen[ $tid ] ) ) { continue; }
					$seen[ $tid ] = 1;
					$out[] = array(
						'tool_id'    => $tid,
						'tool_class' => in_array( $tc, self::VALID_CLASSES, true ) ? $tc : 'producer',
						'source'     => 'agent',
					);
				}
			}
		}

		// Layer 3 — persona providers.
		$providers = apply_filters( 'bizcity_persona_tool_providers', array() );
		if ( is_array( $providers ) ) {
			foreach ( $providers as $p ) {
				if ( ! is_object( $p ) || ! method_exists( $p, 'get_tool_definitions' ) ) { continue; }
				$defs = $p->get_tool_definitions();
				if ( ! is_array( $defs ) ) { continue; }
				foreach ( $defs as $def ) {
					$tid = self::probe_field( $def, array( 'name', 'id' ) );
					$tc  = self::probe_field( $def, array( 'tool_class' ) );
					if ( $tid === '' || isset( $seen[ $tid ] ) ) { continue; }
					$seen[ $tid ] = 1;
					$out[] = array(
						'tool_id'    => $tid,
						'tool_class' => in_array( $tc, self::VALID_CLASSES, true ) ? $tc : 'producer',
						'source'     => 'provider:' . get_class( $p ),
					);
				}
			}
		}

		return $out;
	}

	public static function count_total(): int {
		return 0; // [2026-10-25] PHASE-0.126 R126-TB — bizcity_guru_skills quarantined, no bindings.
	}

	public static function count_for_guru( int $guru_id ): int {
		return 0; // [2026-10-25] PHASE-0.126 R126-TB — bizcity_guru_skills quarantined, no bindings.
	}

	/** @param mixed $thing */
	private static function probe_field( $thing, array $keys ): string {
		foreach ( $keys as $k ) {
			if ( is_array( $thing ) && array_key_exists( $k, $thing ) ) { return (string) $thing[ $k ]; }
			if ( is_object( $thing ) ) {
				if ( isset( $thing->{$k} ) ) { return (string) $thing->{$k}; }
				$g = 'get_' . $k;
				if ( method_exists( $thing, $g ) ) { return (string) $thing->{$g}(); }
			}
		}
		return '';
	}
}
