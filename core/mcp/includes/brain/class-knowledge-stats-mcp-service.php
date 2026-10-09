<?php
/**
 * BizCity_Knowledge_Stats_MCP_Service — `knowledge.stats`: how much knowledge this site holds (notebooks, passages, bytes),
 * so the zalo-hub cell can estimate its R-MEMORY-5-95 allocation (cell working memory vs client knowledge, Q91-26: an
 * estimate, never an exact rule). System call of the cell only (CELL_SYSTEM_MCP_TOOLS), never offered to the model.
 *
 * Bytes are counted the way BizCity_Zalo_Guru_Knowledge_Version does: LENGTH(content) + file_length for passages moved to
 * the filestore. Cached one hour (the number only drives a dashboard ratio). Numbers only - no title, no content.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\MCP
 * @since      2026-10-05 (PHASE-0.91 M-8a)
 */

defined( 'ABSPATH' ) || exit;

// [2026-10-05 09:45 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.91-R-MEMORY-5-95 M-8a — new file, site knowledge size for the cell's 5/95 estimate.
final class BizCity_Knowledge_Stats_MCP_Service {

	const CACHE_KEY = 'bizcity_mcp_knowledge_stats_v1';
	const CACHE_TTL = 3600;

	/** @var callable|null test seam: () => array{notebooks:int,passages:int,bytes:int} */
	public static $reader = null;

	public static function init() {
		add_action( 'bizcity_mcp_register_tools', array( __CLASS__, 'register_tools' ) );
	}

	public static function register_tools() {
		// @mcp bizcity-mcp-standard@1 tool knowledge.stats
		BizCity_MCP_Tool_Registry::register( 'knowledge.stats', array(
			'title'          => 'Dung lượng tri thức của site',
			'description'    => 'Đồng bộ hệ thống (chỉ zalo-hub gọi): tổng số notebook, đoạn và byte tri thức site đang giữ, để cell ước lượng phân bổ trí nhớ 5/95. Chỉ số liệu, không nội dung.',
			'input_schema'   => array( 'type' => 'object', 'properties' => new stdClass() ),
			'output_schema'  => BizCity_MCP_Tool_Registry::envelope_schema( array(
				'notebooks' => array( 'type' => 'integer' ),
				'passages'  => array( 'type' => 'integer' ),
				'bytes'     => array( 'type' => 'integer' ),
				'as_of'     => array( 'type' => 'string' ),
			), array( 'bytes' ) ),
			'read_only'      => true,
			'idempotent'     => true,
			'required_scope' => 'brain.read',
			'handler'        => array( __CLASS__, 'stats' ),
			'mode'           => 'notebook',
			'scopes'         => array( 'brain.read' ),
			'confirm'        => 'never',
			'llm_alias'      => 'knowledge_stats',
			'fallback_pack'  => null,
			'since'          => '0.91.1',
		) );
	}

	public static function stats( array $args, array $ctx ) {
		$cached = function_exists( 'get_transient' ) ? get_transient( self::CACHE_KEY ) : false;
		if ( is_array( $cached ) && isset( $cached['bytes'] ) ) {
			return $cached;
		}
		$raw = self::$reader ? (array) call_user_func( self::$reader ) : self::read();
		$out = array(
			'notebooks' => (int) ( $raw['notebooks'] ?? 0 ),
			'passages'  => (int) ( $raw['passages'] ?? 0 ),
			'bytes'     => (int) ( $raw['bytes'] ?? 0 ),
			'as_of'     => gmdate( 'Y-m-d\TH:i:s\Z' ),
		);
		if ( function_exists( 'set_transient' ) ) {
			set_transient( self::CACHE_KEY, $out, self::CACHE_TTL );
		}
		return $out;
	}

	private static function read(): array {
		if ( ! class_exists( 'BizCity_KG_Database' ) ) {
			return array();
		}
		global $wpdb;
		$db  = BizCity_KG_Database::instance();
		$tbl = $db->tbl_passages();
		$len = function_exists( 'bizcity_columns_exist' ) && bizcity_columns_exist( $tbl, array( 'file_length' ) )
			? 'LENGTH(content) + COALESCE(file_length, 0)'
			: 'LENGTH(content)';
		$p  = $wpdb->get_row( "SELECT COUNT(*) AS n, COALESCE(SUM({$len}), 0) AS bytes FROM {$tbl}", ARRAY_A );
		$nb = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $db->tbl_notebooks() );
		return array( 'notebooks' => $nb, 'passages' => (int) ( $p['n'] ?? 0 ), 'bytes' => (int) ( $p['bytes'] ?? 0 ) );
	}
}

BizCity_Knowledge_Stats_MCP_Service::init();
