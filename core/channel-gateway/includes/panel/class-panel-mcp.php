<?php
/**
 * Site MCP tools behind the cell tools `open_panel` / `panel_key_reset` (PHASE-0.122 doc 20 §4.1, §5):
 *
 *   panel.link.issue {panel}  ⇒ {panel, label, url, expires_at, expires_in}   owner of the number only, for themself (D122-8: R1 with the auto-rotating key)
 *   panel.key.reset  {}       ⇒ {reset:true}                                    owner only; only ever REMOVES access, so no confirmation
 *
 * `llm_alias` = the cell keys ⇒ the cell never offers these to the model as generic site tools (collision rule in site-tool-definitions.ts):
 * the URL goes from here to the cell's fixed sentence and to Zalo, never into a prompt, a log or the ledger (R-PL-8).
 * Mode `notebook` (the person's Second Brain workspace): a delegated principal reaches a tool only through a mode (BizCity_MCP_Delegation::tool_allowed).
 *
 * [2026-10-18 05:10 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.122 S122-P4 — new file. Loaded once by require_once in bootstrap.php.
 *
 * @package BizCity_Twin_AI
 * @subpackage Channel_Gateway
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_Panel_MCP {

	const TOOL_ISSUE = 'panel.link.issue';
	const TOOL_RESET = 'panel.key.reset';

	/** @var callable|null Tests: fn(): bool — "this site serves https" */
	public static $https_seam = null;

	public static function init(): void {
		add_action( 'bizcity_mcp_register_tools', array( __CLASS__, 'register' ) );
	}

	public static function register(): void {
		if ( ! class_exists( 'BizCity_MCP_Tool_Registry' ) ) {
			return;
		}
		$R   = 'BizCity_MCP_Tool_Registry';
		$ids = array_keys( BizCity_Panel_Link::catalog() );
		// @mcp bizcity-mcp-standard@1 tool panel.link.issue
		$R::register( self::TOOL_ISSUE, array(
			'executor'       => 'panel.link.issue',
			'title'          => 'Gửi chủ link Bảng nhanh',
			'description'    => 'Tạo link một lần (15 phút) mở Bảng nhanh của CHÍNH chủ số: kết nối Gmail / Drive / Lịch / Sheets / Fanpage, xem việc trợ lý đã làm, khoá bảo mật. Link không bao giờ trả cho model.',
			'input_schema'   => array( 'type' => 'object', 'required' => array( 'panel' ), 'properties' => array(
				'panel'  => array( 'type' => 'string', 'enum' => $ids ),
				// [2026-10-20 03:30 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.125 Q1 — the job a jobs.quest link opens on (sent by the cell when a planned job starts)
				'job_id' => array( 'type' => 'string', 'pattern' => '^job_[0-9A-Za-z]{10,40}$' ),
				// [2026-10-22 01:50 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.129 W2 — the journal row a brain.map link opens on
				'entry_id' => array( 'type' => 'integer', 'minimum' => 1 ),
			) ),
			'output_schema'  => $R::envelope_schema( array(
				'panel'      => array( 'type' => 'string' ),
				'label'      => array( 'type' => 'string' ),
				'url'        => array( 'type' => 'string' ),
				'expires_at' => array( 'type' => 'string' ),
				'expires_in' => array( 'type' => 'integer' ),
			), array( 'panel', 'url' ) ),
			'read_only'      => false,
			'destructive'    => false,
			'idempotent'     => false, // a new ticket per call; the older unused one of the same panel dies
			'required_scope' => 'brain.read',
			'handler'        => array( __CLASS__, 'mcp_issue' ),
			'mode'           => 'notebook',
			'roles'          => array( 'owner' ),
			'scopes'         => array( 'brain.read' ),
			'confirm'        => 'never',
			'llm_alias'      => 'open_panel',
			'fallback_pack'  => null,
			'since'          => '0.122.0',
		) );
		// @mcp bizcity-mcp-standard@1 tool panel.key.reset
		$R::register( self::TOOL_RESET, array(
			'executor'       => 'panel.key.reset',
			'title'          => 'Đổi khoá Bảng nhanh',
			'description'    => 'Đổi khoá bảo mật của chủ số: mọi link Bảng nhanh chưa dùng và mọi trang đang mở khoá lại ngay.',
			'input_schema'   => array( 'type' => 'object', 'properties' => new stdClass(), 'required' => array() ),
			'output_schema'  => $R::envelope_schema( array( 'reset' => array( 'type' => 'boolean' ) ), array( 'reset' ) ),
			'read_only'      => false,
			'destructive'    => false,
			'idempotent'     => true,
			'required_scope' => 'brain.read',
			'handler'        => array( __CLASS__, 'mcp_reset' ),
			'mode'           => 'notebook',
			'roles'          => array( 'owner' ),
			'scopes'         => array( 'brain.read' ),
			'confirm'        => 'never',
			'llm_alias'      => 'panel_key_reset',
			'fallback_pack'  => null,
			'since'          => '0.122.0',
		) );
	}

	/** @return array|WP_Error */
	public static function mcp_issue( array $args, array $ctx ) {
		$who = self::owner_of( $ctx );
		if ( is_wp_error( $who ) ) {
			return $who;
		}
		$panel = (string) ( $args['panel'] ?? '' );
		$gate  = self::decide( $panel, BizCity_Panel_Link::catalog(), self::https(), self::may_connect( $who ), user_can( $who, 'manage_options' ) );
		if ( '' !== $gate ) {
			return self::fail( $gate );
		}
		// [2026-10-22 01:50 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.129 W2 — focus from focus_of(): jobs.quest ⇒ job id, brain.map ⇒ entry:<id>
		$r = BizCity_Panel_Link::issue( $who, $panel, (string) ( $ctx['turn_id'] ?? '' ), self::focus_of( $panel, $args ) );
		if ( empty( $r['ok'] ) ) {
			return self::fail( (string) $r['code'] );
		}
		return array(
			'panel'      => $panel,
			'label'      => (string) $r['panel']['label'],
			'url'        => BizCity_Panel_Gate::ticket_url( (string) $r['token'] ),
			'expires_at' => gmdate( 'c', (int) $r['expires_at'] ),
			'expires_in' => BizCity_Panel_Link::TICKET_TTL,
		);
	}

	/** Pure ([2026-10-22 01:50 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.129 W2): the focus a link carries — the job of jobs.quest, the row of brain.map, else ''. */
	public static function focus_of( string $panel, array $args ): string {
		if ( 'jobs.quest' === $panel ) {
			return (string) ( $args['job_id'] ?? '' );
		}
		if ( 'brain.map' === $panel && is_numeric( $args['entry_id'] ?? null ) && (int) $args['entry_id'] > 0 ) {
			return 'entry:' . (int) $args['entry_id'];
		}
		return '';
	}

	/** @return array|WP_Error */
	public static function mcp_reset( array $args, array $ctx ) {
		unset( $args );
		$who = self::owner_of( $ctx );
		if ( is_wp_error( $who ) ) {
			return $who;
		}
		BizCity_Panel_Link::reset( $who, 'user' );
		return array( 'reset' => true );
	}

	/**
	 * Pure: '' = go, else the refusal code. Order: unknown panel · no https · a connect panel for a person without the channel grant ·
	 * [2026-10-18 09:05 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.122 S122-F5 — the people panel for a person who does not manage the site.
	 */
	public static function decide( string $panel, array $catalog, bool $https, bool $may_connect, bool $may_manage = false ): string {
		if ( ! isset( $catalog[ $panel ] ) ) {
			return 'panel_unknown';
		}
		if ( ! $https ) {
			return 'no_https';
		}
		if ( 'connect' === BizCity_Panel_Link::group_of( $panel ) && ! $may_connect ) {
			return 'module_off';
		}
		if ( 'users' === BizCity_Panel_Link::group_of( $panel ) && ! $may_manage ) {
			return 'admin_only';
		}
		return '';
	}

	/** Pure: the sentence the cell may pass on (no URL, no id). */
	public static function message_of( string $code ): string {
		$say = array(
			'not_owner'     => 'Chỉ chủ số mới nhận được link Bảng nhanh.',
			'panel_unknown' => 'Không có bảng này.',
			'no_https'      => 'Website chưa có khoá bảo mật (https) nên chưa gửi link được. Báo kỹ thuật bật https.',
			'module_off'    => 'Tài khoản website của anh/chị chưa được cấp quyền kết nối ứng dụng.',
			'rate_limited'  => 'Vừa gửi nhiều link quá, đợi vài phút rồi nhắn lại.',
			'admin_only'    => 'Chỉ quản trị website xem được bảng người dùng.',
		);
		return $say[ $code ] ?? 'Chưa tạo được link.';
	}

	/** @return int|WP_Error the WP user of the owner of this number */
	private static function owner_of( array $ctx ) {
		$uid = (int) ( $ctx['user_id'] ?? 0 );
		if ( 'owner' !== (string) ( $ctx['role'] ?? '' ) || $uid <= 0 ) {
			return self::fail( 'not_owner' );
		}
		return $uid;
	}

	private static function may_connect( int $uid ): bool {
		return user_can( $uid, 'manage_options' ) || user_can( $uid, 'bizcity_agent_mode_channel' );
	}

	private static function https(): bool {
		if ( is_callable( self::$https_seam ) ) {
			return (bool) call_user_func( self::$https_seam );
		}
		return 0 === strpos( home_url( '/' ), 'https://' ) || (bool) apply_filters( 'bizcity_panel_allow_http', false );
	}

	private static function fail( string $code ): WP_Error {
		$http = array( 'not_owner' => 403, 'panel_unknown' => 404, 'no_https' => 424, 'module_off' => 403, 'admin_only' => 403, 'rate_limited' => 429 );
		$mcp  = class_exists( 'BizCity_MCP_Error' ) ? ( 'panel_unknown' === $code ? BizCity_MCP_Error::NOT_FOUND : $code ) : $code;
		return new WP_Error( $mcp, self::message_of( $code ), array( 'status' => $http[ $code ] ?? 400, 'reason' => $code ) );
	}
}
