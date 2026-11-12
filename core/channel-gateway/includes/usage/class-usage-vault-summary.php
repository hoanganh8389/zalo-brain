<?php
/**
 * `GET bizcity-channel/v1/usage/vault-summary[?refresh=1]` — vault-summary@1: the three counts of the Overview "Gói & License" card line
 * "Kho của bạn ở site này: 📚 Vault N tài liệu · 🔌 K công cụ" (PHASE-0.144 S144-UI3, doc 30 BM-1).
 * [2026-10-28 Johnny Chu - Chu Hoàng Anh] PHASE-0.144 — chủ: "bỏ vai trò context bank đi … bỏ ra khỏi core": the Context Bank count is gone from this contract.
 *
 *   { contract: 'vault-summary@1', vault_docs: int|null, tools: int|null }   null = that source is not on this site (or not ready)
 *
 * Sources — existing tables / summary functions only (R-TABLE-FREEZE: no new table), one COUNT each, cached 10 minutes in a transient per viewer:
 *   vault_docs    COUNT(*) of `{prefix}bizcity_journal_entries` (BizCity_Journal_Database) rows of the viewer that keep an original file
 *                 (`has_file = 1`, archived / void left out — the same rows the Kho tri thức "Tài liệu" list shows). Read through idx_owner_meta
 *                 (owner_user_id, meta_status, has_file): one owner's range, never a full scan. Before the 1.4.0 index backfill finished
 *                 (index_cols_ready() false) ⇒ null: the LIKE fallback on `metadata` would be a full LONGTEXT scan.
 *   tools         operating tool families (Woo, CRM, lịch hẹn, nhân sự, Gmail, website, văn bản, báo cáo, tự động hoá) that have at least one
 *                 registered AND enabled MCP tool on this site (BizCity_MCP_Tool_Registry::catalog_for_settings — the in-memory catalog the MCP Access
 *                 tab already reads; no DB). Brain / knowledge / memory / journal tools are the site's own store, not a connected tool. Null without the registry.
 *
 * Permission: same as usage/subscription (BizCity_Usage_Plans_REST::can_manage — managers only).
 *
 * Test seams ($readers): vault_docs(int owner): ?int · tool_names(): ?string[] · owner(): int · cache_get(key) · cache_set(key, value)
 *
 * // [2026-10-28 Johnny Chu - Chu Hoàng Anh] PHASE-0.144 S144-UI3 — new file.
 *
 * @package BizCity_Twin_AI
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_Usage_Vault_Summary {

	const NS        = 'bizcity-channel/v1';
	const CONTRACT  = 'vault-summary@1';
	const TRANSIENT = 'bizcity_vault_summary_'; // + owner user id
	const TTL       = 600;                      // 10 minutes

	/**
	 * MCP tool name prefix ⇒ operating tool family (the "🔌 K công cụ" of the card counts families, not single tools).
	 * Prefixes not listed (brain, knowledge, memory, journal, vault, group, track, registry, site, …) are the site's own store / internals.
	 */
	const TOOL_FAMILIES = array(
		'commerce'   => 'woo',
		'order'      => 'woo',
		'inventory'  => 'woo',
		'crm'        => 'crm',
		'pipeline'   => 'crm',
		'booking'    => 'booking',
		'staff'      => 'staff',
		'gmail'      => 'gmail',
		'wp'         => 'website',
		'page'       => 'website',
		'content'    => 'website',
		'document'   => 'document',
		'report'     => 'report',
		'business'   => 'report',
		'sales'      => 'report',
		'automation' => 'automation',
	);

	/** @var array<string,callable> */
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
		register_rest_route( self::NS, '/usage/vault-summary', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'route_get' ),
			'permission_callback' => array( 'BizCity_Usage_Plans_REST', 'can_manage' ), // same gate as usage/subscription
		) );
	}

	public static function route_get( WP_REST_Request $request ): WP_REST_Response {
		return new WP_REST_Response( self::view( ! empty( $request->get_param( 'refresh' ) ) ), 200 );
	}

	/** vault-summary@1 of the viewer on this site (cached TTL seconds; `$refresh` reads the sources again). */
	public static function view( bool $refresh = false ): array {
		$owner = self::owner();
		$key   = self::TRANSIENT . $owner;
		if ( ! $refresh ) {
			$hit = self::cache_get( $key );
			if ( is_array( $hit ) && self::CONTRACT === ( $hit['contract'] ?? '' ) ) {
				return self::shape( $hit );
			}
		}
		$tools = self::tool_names();
		$out   = self::shape( array(
			'vault_docs'   => self::vault_docs( $owner ),
			'tools'        => null === $tools ? null : self::tool_families( $tools ),
		) );
		self::cache_set( $key, $out );
		return $out;
	}

	/* ── pure ───────────────────────────────────────────────────────── */

	/** The wire shape: contract + two counts, each an int ≥ 0 or null (source absent). Unknown keys (a cached `context_bank` included) are dropped. */
	public static function shape( array $raw ): array {
		return array(
			'contract'     => self::CONTRACT,
			'vault_docs'   => self::count_or_null( $raw['vault_docs'] ?? null ),
			'tools'        => self::count_or_null( $raw['tools'] ?? null ),
		);
	}

	/** A whole count ≥ 0, or null for null / false / '' / non-numeric / negative (a failed read is "no source", never 0). */
	public static function count_or_null( $v ): ?int {
		if ( is_int( $v ) ) {
			return $v >= 0 ? $v : null;
		}
		if ( is_string( $v ) && 1 === preg_match( '/^\d+$/', trim( $v ) ) ) {
			return (int) trim( $v ); // $wpdb->get_var() answers a string
		}
		if ( is_float( $v ) && $v >= 0 && floor( $v ) === $v ) {
			return (int) $v;
		}
		return null;
	}

	/** How many distinct operating families (TOOL_FAMILIES) the given MCP tool names cover. */
	public static function tool_families( array $names ): int {
		$seen = array();
		foreach ( $names as $name ) {
			$name   = strtolower( trim( (string) $name ) );
			$prefix = strstr( $name, '.', true );
			$prefix = false === $prefix ? $name : $prefix;
			if ( isset( self::TOOL_FAMILIES[ $prefix ] ) ) {
				$seen[ self::TOOL_FAMILIES[ $prefix ] ] = true;
			}
		}
		return count( $seen );
	}

	/* ── sources ────────────────────────────────────────────────────── */

	private static function owner(): int {
		if ( isset( self::$readers['owner'] ) ) {
			return (int) call_user_func( self::$readers['owner'] );
		}
		return function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
	}

	/** Vault rows of the owner with an original file (idx_owner_meta range); null when the journal is absent or its index columns are not backfilled yet. */
	private static function vault_docs( int $owner ): ?int {
		if ( isset( self::$readers['vault_docs'] ) ) {
			return self::count_or_null( call_user_func( self::$readers['vault_docs'], $owner ) );
		}
		if ( $owner <= 0 || ! class_exists( 'BizCity_Journal_Database' ) || ! method_exists( 'BizCity_Journal_Database', 'index_cols_ready' ) ) {
			return null;
		}
		try {
			if ( ! BizCity_Journal_Database::index_cols_ready() ) {
				return null; // never the LIKE fallback on `metadata` (full scan)
			}
			global $wpdb;
			$t = (string) BizCity_Journal_Database::instance()->get_table();
			if ( ! self::table_exists( $t ) ) {
				return null;
			}
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from the owning class
			$n = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$t}` WHERE owner_user_id = %d AND has_file = 1 AND status NOT IN ('archived','void')", $owner ) );
			return self::count_or_null( $n );
		} catch ( \Throwable $e ) { // a journal hiccup must not break the Overview card
			return null;
		}
	}


	/** Names of the registered AND enabled MCP tools; null without the registry. */
	private static function tool_names(): ?array {
		if ( isset( self::$readers['tool_names'] ) ) {
			$r = call_user_func( self::$readers['tool_names'] );
			return is_array( $r ) ? $r : null;
		}
		if ( ! class_exists( 'BizCity_MCP_Tool_Registry' ) || ! method_exists( 'BizCity_MCP_Tool_Registry', 'catalog_for_settings' ) ) {
			return null;
		}
		try {
			$names = array();
			foreach ( (array) BizCity_MCP_Tool_Registry::catalog_for_settings() as $t ) {
				if ( is_array( $t ) && ! empty( $t['enabled'] ) && isset( $t['name'] ) ) {
					$names[] = (string) $t['name'];
				}
			}
			return $names;
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	private static function table_exists( string $t ): bool {
		global $wpdb;
		if ( '' === $t || ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return false;
		}
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) === $t; // a missing table must not print a DB error into the JSON answer
	}

	private static function cache_get( string $key ) {
		if ( isset( self::$readers['cache_get'] ) ) {
			return call_user_func( self::$readers['cache_get'], $key );
		}
		return function_exists( 'get_transient' ) ? get_transient( $key ) : false;
	}

	private static function cache_set( string $key, array $value ): void {
		if ( isset( self::$readers['cache_set'] ) ) {
			call_user_func( self::$readers['cache_set'], $key, $value );
			return;
		}
		if ( function_exists( 'set_transient' ) ) {
			set_transient( $key, $value, self::TTL );
		}
	}
}
