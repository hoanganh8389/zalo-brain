<?php
/**
 * BizCity_Automation_Action_MCP_Service — automation.run of the one MCP standard (PHASE-0.88 L1-11, lane CL-B;
 * mode `automation`, new in 0.88).
 *
 * [2026-10-05 08:26 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.91-AX-1 — Automation is its own plugin, `bizcity-automation`
 * (owner decision 2026-10-05, after two reverts the same day: core/automation this morning, bizcity-twin-brain-addon
 * before that — Automation is sold as a standalone Pro/Add-on, needs its own install/activate toggle). "add-on" below
 * means that plugin. PHASE-0.91 adds the cell's ONE door to it: automation.run_scenario (by slug) +
 * automation.list_scenarios; automation.run stays the admin path (system:true).
 * Biz Central Brain — Johnny Chu (Chu Hoàng Anh), Bizcity Central Brain, GCN quyền tác giả số 8877/2026/QTG.
 *
 * The workflow engine lives in the sibling plugin `bizcity-automation` (folder `automation/` inside it). This tool is
 * registered when that plugin is installed — loaded, or just present on disk (installed(), PHASE-0.91 AX-0.2): the request gate in
 * bizcity-twin-ai.php does not load the add-on on MCP routes, so the handlers load it lazily (boot_addon()). Without
 * the add-on the tool is not in tools/list and a call answers MCP_TOOL_NOT_FOUND. Commit = the add-on's own queue: Repo_Runs::enqueue() +
 * the `bizcity_automation_run_async` loopback event, exactly like `POST bizcity-automation/v1/workflows/{id}/run?async=1`
 * — so a long workflow never runs inside the MCP request (8 s write budget). Site administrators only.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\MCP
 * @since      2026-10-01 (PHASE-0.88 CL-B)
 */

defined( 'ABSPATH' ) || exit;

// [2026-10-01 Claude Opus 5.5] PHASE-0.88 L1-11 — new file, automation.run on BizCity_MCP_Tool_Registry.
final class BizCity_Automation_Action_MCP_Service {

	// [2026-10-09 Johnny Chu - Chu Hoàng Anh] 48 KB of real UTF-8: a 1 000–1 200 word Vietnamese article (web_post) is ~10–15 KB. Hub relay allows 256 KB.
	const INPUT_MAX_BYTES = 49152;

	/**
	 * [2026-10-09 Johnny Chu - Chu Hoàng Anh] R-AF-13 — one row per declared input: {name, label, size, excerpt}. size = "N từ" (long
	 * text) / "N ký tự" / "link" / "trống"; excerpt ≤ 120 chars, never the whole article.
	 *
	 * @return array<int,array{name:string,label:string,size:string,excerpt:string}>
	 */
	private static function input_report( array $declared, array $input ): array {
		$out = array();
		foreach ( $declared as $in ) {
			$name = (string) ( $in['name'] ?? '' );
			if ( '' === $name ) {
				continue;
			}
			$v    = $input[ $name ] ?? '';
			$v    = is_scalar( $v ) ? trim( (string) $v ) : '';
			$len  = function_exists( 'mb_strlen' ) ? mb_strlen( $v, 'UTF-8' ) : strlen( $v );
			if ( '' === $v ) {
				$size = 'trống';
			} elseif ( preg_match( '#^https?://\S+$#i', $v ) ) {
				$size = 'link';
			} elseif ( 'long_text' === (string) ( $in['type'] ?? '' ) || $len > 200 ) {
				$size = (int) preg_match_all( '/[\p{L}\p{N}]+/u', $v ) . ' từ';
			} else {
				$size = $len . ' ký tự';
			}
			$ex    = preg_replace( '/\s+/u', ' ', $v );
			// [2026-10-09 09:37 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.95 R-CID-11 — the excerpt reaches the model too: phone / email / address masked.
			$kind  = self::private_kind( $in, $v );
			if ( '' !== $kind && '' !== $v ) {
				$ex = self::masked_value( $kind, $v );
			}
			$out[] = array(
				'name'    => $name,
				'label'   => (string) ( $in['label'] ?? $name ),
				'size'    => $size,
				'excerpt' => function_exists( 'mb_strlen' ) && mb_strlen( $ex, 'UTF-8' ) > 120 ? mb_substr( $ex, 0, 119, 'UTF-8' ) . '…' : $ex,
			);
		}
		return $out;
	}

	/**
	 * [2026-10-09 09:37 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.95 R-CID-11 (core spec §8) — what the model reads about an input must never carry a
	 * raw phone / email / address. Kind = the declared crm_field (phone|email|address), else a value that looks like a phone / an email; '' = public.
	 */
	public static function private_kind( array $in, string $v ): string {
		$crm = (string) ( $in['crm_field'] ?? '' );
		if ( in_array( $crm, array( 'phone', 'email', 'address' ), true ) ) {
			return $crm;
		}
		if ( preg_match( '/^\+?[\d\s.\-()]{9,16}$/', $v ) ) {
			return 'phone';
		}
		return preg_match( '/^\S+@\S+\.\S+$/', $v ) ? 'email' : '';
	}

	/** Masked form for the model: phone "…678", email "l…@example.com", address "Q.1, TP.HCM" (or "(đã che)"). */
	public static function masked_value( string $kind, string $v ): string {
		if ( 'phone' === $kind ) {
			$m = BizCity_MCP_Action_Support::mask_phone( $v );
			return '' !== $m ? $m : '(đã che)';
		}
		if ( 'email' === $kind ) {
			$at = strrchr( $v, '@' );
			return false === $at ? '(đã che)' : substr( $v, 0, 1 ) . '…' . $at;
		}
		$masked = method_exists( 'BizCity_MCP_Action_Support', 'mask_address' ) ? (string) BizCity_MCP_Action_Support::mask_address( $v ) : '';
		return '' !== $masked ? $masked : '(đã che)';
	}

	/** Real UTF-8 size of the input (an accented letter is 2–3 bytes, not the 6 of an escaped one). */
	private static function input_bytes( array $input ): int {
		return strlen( (string) wp_json_encode( $input, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
	}

	/** @var callable|null test seam: fn(): bool — replaces boot_addon() in pack_exporters() */
	public static $boot = null;

	public static function init() {
		add_action( 'bizcity_mcp_register_tools', array( __CLASS__, 'register_tools' ) );
		// [2026-10-09 Johnny Chu - Chu Hoàng Anh] the pack routes (/zalo-bridge/packs*, pulled by the Hub for the cell) are not in the
		// Automation load gate ⇒ the `automation` exporter was missing there and the cell never stored the scenario pack.
		add_filter( 'bizcity_twin_agent_pack_exporters', array( __CLASS__, 'pack_exporters' ), 20 );
	}

	/**
	 * [2026-10-09 Johnny Chu - Chu Hoàng Anh] Lazy seam for the pack exporters, like the MCP tools: the list lacks `automation` and the
	 * add-on is on disk ⇒ boot it, then register its exporter (its own priority-10 filter has already run in this pass).
	 *
	 * @param mixed $list
	 */
	public static function pack_exporters( $list ): array {
		$list = is_array( $list ) ? $list : array();
		if ( isset( $list['automation'] ) || ! self::installed() ) {
			return $list;
		}
		$ok = is_callable( self::$boot ) ? (bool) call_user_func( self::$boot ) : (bool) self::boot_addon();
		if ( $ok && class_exists( 'BizCity_Automation_Cell_Pack_Exporter' ) && method_exists( 'BizCity_Automation_Cell_Pack_Exporter', 'exporters' ) ) {
			$list = (array) BizCity_Automation_Cell_Pack_Exporter::exporters( $list );
		}
		return $list;
	}

	/**
	 * [2026-10-05 04:43 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.91-AX-0.2 — is the add-on there? Cheap: class check, else the locator asks
	 * the disk. Loads nothing (used by register_tools on every MCP request).
	 */
	public static function installed() {
		return class_exists( 'BizCity_Automation_Repo_Workflows' )
			|| ( class_exists( 'BizCity_Addon_Locator' ) && '' !== BizCity_Addon_Locator::file( 'automation/bootstrap.php' ) );
	}

	/**
	 * [2026-10-05 04:43 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.91-AX-0.2 — load the add-on (+ its tables) only when an automation tool is
	 * really called. The bootstrap guards itself with BIZCITY_AUTOMATION_LOADED. false ⇒ the caller answers TOOL_NOT_FOUND.
	 */
	public static function boot_addon() {
		if ( ! class_exists( 'BizCity_Automation_Repo_Workflows' ) ) {
			if ( ! class_exists( 'BizCity_Addon_Locator' ) ) {
				return false;
			}
			$file = BizCity_Addon_Locator::file( 'automation/bootstrap.php' );
			if ( '' === $file ) {
				return false;
			}
			require_once $file;
			if ( class_exists( 'BizCity_Automation_Installer' ) ) {
				BizCity_Automation_Installer::ensure(); // the first request on a fresh blog may not have the tables yet
			}
			if ( class_exists( 'BizCity_Automation_Cell_Catalog' ) ) {
				BizCity_Automation_Cell_Catalog::ensure_defaults(); // [2026-10-05 07:55 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.91-B2-2b — once per site (option-guarded)
			}
		}
		return class_exists( 'BizCity_Automation_Repo_Workflows' ) && class_exists( 'BizCity_Automation_Repo_Runs' );
	}

	/** @deprecated 2026-10-05 PHASE-0.91 AX-0.2 — kept for callers; = installed(). */
	public static function available() {
		return self::installed();
	}

	/**
	 * [2026-10-05 11:15 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.91-AX-LIC CL-LIC-4 — gate 1 of doc 120 §3: the site's 1API master must be
	 * Premium+ (BizCity_Twin_Addon_License, synced from the Hub). Below that the automation.* tools are not listed at all — the cell
	 * then says "không có kịch bản" honestly instead of a 403 later. The license class lives in modules/twinshell but is standalone;
	 * load it here because the TwinShell module does not boot on MCP routes. No class ⇒ no gate (fail-open, like Feature_Gate).
	 */
	public static function licensed() {
		if ( ! class_exists( 'BizCity_Twin_Addon_License' ) && defined( 'BIZCITY_TWIN_AI_DIR' ) ) {
			$file = BIZCITY_TWIN_AI_DIR . 'modules/twinshell/includes/class-twin-addon-license.php';
			if ( is_file( $file ) ) {
				require_once $file;
			}
		}
		return ! class_exists( 'BizCity_Twin_Addon_License' ) || BizCity_Twin_Addon_License::automation_allowed();
	}

	public static function register_tools() {
		if ( ! self::installed() ) {
			return; // add-on absent: no tool (never a fake success)
		}
		if ( ! self::licensed() ) {
			return; // CL-LIC-4: below Premium the tools do not exist for this site
		}

		// @mcp bizcity-mcp-standard@1 tool automation.run
		BizCity_MCP_Tool_Registry::register( 'automation.run', array(
			'title'          => 'Chạy kịch bản tự động',
			'description'    => 'Chạy một kịch bản Automation (workflow_id) của cửa hàng, có thể kèm dữ liệu đầu vào (input). Chỉ quản trị viên dùng được. Kịch bản chạy nền; kết quả trả run_id để theo dõi. Lần gọi đầu chỉ trả bản xem trước + confirm_token; gọi lại cùng tham số kèm confirm_token sau khi người dùng đồng ý.',
			'input_schema'   => array( 'type' => 'object', 'required' => array( 'workflow_id' ), 'properties' => array(
				'workflow_id'   => array( 'type' => 'integer', 'minimum' => 1 ),
				'input'         => array( 'type' => 'object' ),
				'confirm_token' => array( 'type' => 'string' ),
			) ),
			'output_schema'  => BizCity_MCP_Tool_Registry::envelope_schema( array(
				'status'        => array( 'type' => 'string', 'enum' => array( 'needs_confirmation', 'done' ) ),
				'preview'       => array( 'type' => 'object' ),
				'confirm_token' => array( 'type' => 'string' ),
				'expires_at'    => array( 'type' => 'string' ),
				'run_id'        => array( 'type' => 'string' ),
				'workflow'      => array( 'type' => 'object' ),
			) ),
			'read_only'      => false,
			'destructive'    => false,
			'idempotent'     => false,
			'open_world'     => true, // a workflow may message customers or call external services
			'required_scope' => 'automation.run',
			'handler'        => array( __CLASS__, 'run' ),
			'preview'        => array( __CLASS__, 'preview' ),
			'mode'           => 'automation',
			'scopes'         => array( 'automation.run' ),
			'confirm'        => 'always',
			'llm_alias'      => 'automation_run',
			'fallback_pack'  => null,
			'since'          => '0.88.4',
		) );

		// [2026-10-05 06:20 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.91-AX-1.4 — the ONE model door to the extension arm (R-VA-11): by slug.
		// @mcp bizcity-mcp-standard@1 tool automation.run_scenario
		BizCity_MCP_Tool_Registry::register( 'automation.run_scenario', array(
			'title'          => 'Chạy kịch bản tự động',
			// [2026-10-08 02:55 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.94-S94-PKG — website chỉ NHẬN yêu cầu + nguyên liệu đã đủ: trợ lý (cell) hỏi người dùng / tự tạo nguyên liệu TRƯỚC, rồi gửi prompt + input JSON
			'description'    => 'Giao cho website chạy một kịch bản (kỹ năng) theo mã scenario. Website KHÔNG hỏi lại người dùng: bạn phải chuẩn bị ĐỦ nguyên liệu trước (hỏi người dùng những gì kịch bản cần hỏi, tự viết nội dung, tự tạo ảnh và gửi LINK ảnh), rồi gửi prompt = yêu cầu gốc của người dùng và input = gói JSON đầy đủ. Chỉ dùng khi tool có sẵn của trợ lý không làm trọn được việc. Kịch bản chạy ở nền; kết quả sẽ được gửi lại cho chính người yêu cầu, nên hãy nói trước thời gian dự kiến. Lần gọi đầu chỉ trả bản xem trước + confirm_token; gọi lại cùng tham số kèm confirm_token sau khi người dùng đồng ý.',
			'input_schema'   => array( 'type' => 'object', 'required' => array( 'scenario' ), 'properties' => array(
				'scenario'      => array( 'type' => 'string', 'maxLength' => 40 ),
				'prompt'        => array( 'type' => 'string', 'maxLength' => 2000 ),
				'input'         => array( 'type' => 'object' ),
				// [2026-10-09 03:09 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.95-S95-A3 — automation-scenario@1.5.0: who the run is for, TOP level (never inside input); required on a guest turn.
				'contact'       => array( 'type' => 'object', 'properties' => array(
					'platform'     => array( 'type' => 'string', 'maxLength' => 32 ),
					'channel_ref'  => array( 'type' => 'string', 'maxLength' => 190 ),
					'platform_uid' => array( 'type' => 'string', 'maxLength' => 190 ),
					'display_name' => array( 'type' => 'string', 'maxLength' => 120 ),
				) ),
				'confirm_token' => array( 'type' => 'string' ),
				// [2026-10-10 12:23 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.95-S95-W13 — D95-22: the cell's scheduled turn links the run row to its job (cell run id, workflow, anchor Lịch row). Cell-built, never the model's.
				'job'           => array( 'type' => 'object', 'properties' => array( 'run_id' => array( 'type' => 'string', 'maxLength' => 64 ), 'workflow_id' => array( 'type' => 'integer', 'minimum' => 1 ), 'site_event_id' => array( 'type' => 'integer', 'minimum' => 1 ) ) ),
			) ),
			'output_schema'  => BizCity_MCP_Tool_Registry::envelope_schema( array(
				'status'        => array( 'type' => 'string', 'enum' => array( 'needs_confirmation', 'queued' ) ),
				'preview'       => array( 'type' => 'object' ),
				'confirm_token' => array( 'type' => 'string' ),
				'expires_at'    => array( 'type' => 'string' ),
				'run_id'        => array( 'type' => 'string' ),
				'scenario'      => array( 'type' => 'string' ),
				'workflow_id'   => array( 'type' => 'integer' ),
				'eta_seconds'   => array( 'type' => 'integer' ),
				'report_back'   => array( 'type' => 'boolean' ),
				'event_id'      => array( 'type' => 'integer' ),
			) ),
			'read_only'      => false,
			'destructive'    => false,
			'idempotent'     => false,
			'open_world'     => true,
			'required_scope' => 'automation.run',
			'handler'        => array( __CLASS__, 'run_scenario' ),
			'preview'        => array( __CLASS__, 'preview_scenario' ),
			'mode'           => 'automation',
			'scopes'         => array( 'automation.run' ),
			'confirm'        => 'always',
			'llm_alias'      => 'automation_run_scenario',
			'fallback_pack'  => null,
			'since'          => '0.91.1',
		) );

		// [2026-10-05 06:20 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.91-AX-1.5 — what the model may call, filtered by the caller's role.
		// @mcp bizcity-mcp-standard@1 tool automation.list_scenarios
		BizCity_MCP_Tool_Registry::register( 'automation.list_scenarios', array(
			'title'          => 'Danh sách kịch bản tự động',
			'description'    => 'Liệt kê các kịch bản (kỹ năng) website đã chuẩn bị mà người này được gọi: mã scenario, tên, một câu mô tả, trường dữ liệu vào, thời gian dự kiến. Chỉ đọc. Dùng trước automation_run_scenario khi chưa biết mã.',
			'input_schema'   => array( 'type' => 'object', 'properties' => array(
				'q' => array( 'type' => 'string', 'maxLength' => 80 ),
			) ),
			'output_schema'  => BizCity_MCP_Tool_Registry::envelope_schema( array(
				'scenarios' => array( 'type' => 'array' ),
				'total'     => array( 'type' => 'integer' ),
			) ),
			'read_only'      => true,
			'destructive'    => false,
			'idempotent'     => true,
			'open_world'     => false,
			'required_scope' => 'automation.run',
			'handler'        => array( __CLASS__, 'list_scenarios' ),
			'mode'           => 'automation',
			'scopes'         => array( 'automation.run' ),
			'confirm'        => 'never',
			'llm_alias'      => 'automation_list_scenarios',
			'fallback_pack'  => null,
			'since'          => '0.91.1',
		) );
	}

	/* ── automation.run_scenario / automation.list_scenarios (PHASE-0.91 AX-1) ─────────────────────────────── */

	const LIST_MAX        = 20;
	const DEDUPE_TTL      = 120;
	const SCENARIO_SOURCE = 'mcp.automation_run_scenario';

	/**
	 * Preview of a scenario run (no write). Names the plan, its slug and #workflow_id (D91-27 / AX-2.6 site part).
	 * auto_confirm = true only with the scenario's own standing authorisation (D91-31, AX-6).
	 */
	public static function preview_scenario( array $args, array $ctx ) {
		// [2026-10-05 06:20 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.91-AX-1.4 — same 7 refusals as the commit, nothing written.
		$ctx = self::as_tool( $ctx, 'automation.run_scenario' ); // [2026-10-09 03:09 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.95-S95-A2
		return BizCity_MCP_Action_Support::run_as( $ctx, static function ( $uid ) use ( $args, $ctx ) {
			$plan = self::plan_scenario( $args, $ctx, (int) $uid );
			if ( is_wp_error( $plan ) ) {
				return $plan;
			}
			$e    = $plan['entry'];
			$cell = $e['cell'];
			// [2026-10-09 Johnny Chu - Chu Hoàng Anh] Never show a value cut at 40 chars as if it were the input: the model read
			// "content = <40 chars>, image_url = " as "the site cut my article and lost the image" and re-sent without the token
			// (live 2026-10-09 14:13). Long text ⇒ its length, links whole, empty ⇒ "trống"; the commit gets the full inputs.
			$bits = array();
			// [2026-10-09 03:50 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.95 R-CID-11 (core spec §8) — the summary is read by the model: a phone / email
			// (declared crm_field, or a value that looks like a phone) is shown masked; the commit still receives the full value.
			$decl = array();
			foreach ( (array) ( $cell['inputs'] ?? array() ) as $in ) {
				$decl[ (string) ( $in['name'] ?? '' ) ] = (array) $in;
			}
			foreach ( $plan['input'] as $k => $v ) {
				if ( ! is_scalar( $v ) || count( $bits ) >= 6 ) {
					continue;
				}
				$v    = trim( (string) $v );
				$len  = function_exists( 'mb_strlen' ) ? mb_strlen( $v, 'UTF-8' ) : strlen( $v );
				$kind = '' !== $v ? self::private_kind( $decl[ (string) $k ] ?? array(), $v ) : '';
				if ( '' !== $kind ) {
					$bits[] = $k . ': ' . self::masked_value( $kind, $v );
				} elseif ( '' === $v ) {
					$bits[] = $k . ': trống';
				} elseif ( preg_match( '#^https?://\S+$#i', $v ) ) {
					$bits[] = $k . ': ' . self::short( $v, 300 );
				} elseif ( $len > 80 ) {
					$bits[] = $k . ': ' . $len . ' ký tự';
				} else {
					$bits[] = $k . ': «' . $v . '»';
				}
			}
			$summary = 'Làm theo kế hoạch đã chuẩn bị: «' . $cell['label'] . '» (' . $cell['slug'] . ', workflow #' . (int) $e['workflow_id'] . ')'
				. ( $bits ? ' với ' . implode( '; ', $bits ) : '' )
				. '. Khi xác nhận, website nhận đủ nguyên văn các thông tin trên. Khoảng ' . (int) $cell['eta_seconds'] . ' giây, kết quả sẽ gửi lại cho bạn.';
			$out = array(
				'summary'     => $summary,
				'scenario'    => $cell['slug'],
				'name'        => $cell['label'],
				'workflow_id' => (int) $e['workflow_id'],
				'eta_seconds' => (int) $cell['eta_seconds'],
				'inputs'      => self::input_report( (array) $cell['inputs'], $plan['input'] ), // [2026-10-09 Johnny Chu - Chu Hoàng Anh] R-AF-13 T1
			);
			// [2026-10-09 03:09 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.95-S95-A3 — what the CRM already knows, masked (never the UID, never the full phone).
			if ( ! empty( $plan['cell']['contact_id'] ) ) {
				$out['contact_known'] = self::contact_known( (int) $plan['cell']['contact_id'] );
			}
			if ( 'never' === $cell['confirm'] && ! empty( $cell['standing_auth']['by'] ) ) {
				$out['auto_confirm']  = true; // D91-31: read ONLY by confirm_gate, ONLY for automation.run_scenario
				$out['authorized_by'] = (int) $cell['standing_auth']['by'];
			}
			return $out;
		} );
	}

	/**
	 * Commit (after the confirm gate): ① Lịch row automation_run (report_back, notify off, `_cell` from the auth ctx)
	 * ② de-dup key ③ enqueue with `_scheduler_event` ④ async loopback event. Never runs the workflow in this request.
	 */
	public static function run_scenario( array $args, array $ctx ) {
		// [2026-10-05 06:20 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.91-AX-1.4 — D91-34: one Lịch row per cell-requested run.
		$ctx = self::as_tool( $ctx, 'automation.run_scenario' ); // [2026-10-09 03:09 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.95-S95-A2
		return BizCity_MCP_Action_Support::run_as( $ctx, static function ( $uid ) use ( $args, $ctx ) {
			$plan = self::plan_scenario( $args, $ctx, (int) $uid );
			if ( is_wp_error( $plan ) ) {
				return $plan;
			}
			$e    = $plan['entry'];
			$cell = $e['cell'];
			$wf   = (int) $e['workflow_id'];
			// [2026-10-09 Johnny Chu - Chu Hoàng Anh] R-AF-13 T4 — say what the website RECEIVED (each input + its size), the cell relays it verbatim.
			$received = self::input_report( (array) $cell['inputs'], $plan['input'] );
			$done = static function ( $run_id, $event_id, $dup ) use ( $cell, $wf, $received ) {
				$lines = array();
				foreach ( $received as $r ) {
					$lines[] = $r['label'] . ': ' . $r['size'];
				}
				$out = array(
					'status'        => 'queued',
					'run_id'        => (string) $run_id,
					'scenario'      => $cell['slug'],
					'workflow_id'   => $wf,
					'eta_seconds'   => (int) $cell['eta_seconds'],
					'report_back'   => true,
					'event_id'      => (int) $event_id,
					'received'      => $received,
					'received_text' => 'Website đã nhận đủ để chạy «' . $cell['label'] . '» (' . $cell['slug'] . ', workflow #' . $wf . '): ' . implode( ' · ', $lines ) . '. Mã xử lý ' . (string) $run_id . ', dự kiến ' . (int) $cell['eta_seconds'] . ' giây; xong sẽ gửi log từng bước.',
				);
				if ( $dup ) {
					$out['duplicate'] = true;
				}
				return $out;
			};

			// [2026-10-09 03:09 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.95-S95-A2 — guests share the owner's uid (acting user): the requester hash +
			// contact keep two customers' identical orders apart.
			$dedupe = 'bzc_scn_' . md5( get_current_blog_id() . '|' . (int) $uid . '|' . $plan['cell']['user_hash'] . '|' . (int) $plan['cell']['contact_id'] . '|' . $cell['slug'] . '|' . wp_json_encode( $plan['input'] ) );
			$prev   = get_transient( $dedupe );
			if ( is_array( $prev ) && ! empty( $prev['run_id'] ) ) {
				return $done( $prev['run_id'], (int) ( $prev['event_id'] ?? 0 ), true ); // same order twice within 2 min
			}
			if ( ! class_exists( 'BizCity_Scheduler_Run_Ledger' ) ) {
				return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::INTERNAL_ERROR, 'Lịch của website chưa sẵn sàng.', 503 );
			}

			// ① the row is the receipt and the report-back source (D91-34).
			$event_id = BizCity_Scheduler_Run_Ledger::open( array(
				'user_id'      => (int) $uid,
				'title'        => $cell['label'], // [2026-10-06 05:40 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.92-S92-FIX-1 — the scenario's name, as on the mockup
				'workflow_id'  => $wf,
				'scenario_ref' => $cell['slug'],
				'source'       => $plan['cell']['platform'] === 'zalo' ? 'cell' : 'wp',
				'report_back'  => true,
				'notify'       => false,
				'cell'         => $plan['cell'],
				'job'          => self::job_link( $args, $ctx, (string) $plan['role'] ), // [2026-10-10 12:23 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.95-S95-W13
			) );
			if ( is_wp_error( $event_id ) ) {
				return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::INTERNAL_ERROR, 'Không ghi được dòng Lịch cho lần chạy.', 500 );
			}
			// ② de-dup before anything is queued.
			set_transient( $dedupe, array( 'run_id' => '', 'event_id' => (int) $event_id ), self::DEDUPE_TTL );

			// ③ the queue (payload: inputs + identity built HERE, never from the caller).
			$payload = array_merge( $plan['input'], array(
				'prompt'           => (string) ( $plan['prompt'] ?? '' ), // [2026-10-08 02:55 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.94-S94-PKG — {{trigger.prompt}}
				'_cell'            => $plan['cell'],
				'_scheduler_event' => (int) $event_id,
				'_owner_user_id'   => (int) $uid,
				'wp_user_id'       => (int) $uid,
				'source'           => self::SCENARIO_SOURCE,
			) );
			$run_id = BizCity_Automation_Repo_Runs::enqueue( $wf, $payload, '', array( 'user_id' => (int) $uid ) );
			if ( is_wp_error( $run_id ) ) {
				BizCity_Scheduler_Run_Ledger::finish( (int) $event_id, 'failed', array( 'error' => 'enqueue_failed' ) );
				delete_transient( $dedupe );
				return BizCity_MCP_Action_Support::from_business( $run_id );
			}
			set_transient( $dedupe, array( 'run_id' => (string) $run_id, 'event_id' => (int) $event_id ), self::DEDUPE_TTL );

			// ④ run in its own request (loopback), exactly like REST run?async=1.
			do_action( 'bizcity_automation_run_enqueued', $run_id, $wf, $payload );
			if ( function_exists( 'wp_next_scheduled' ) && ! wp_next_scheduled( 'bizcity_automation_run_async', array( $run_id ) ) ) {
				wp_schedule_single_event( time(), 'bizcity_automation_run_async', array( $run_id ) );
			}
			if ( function_exists( 'spawn_cron' ) ) {
				spawn_cron();
			}
			return $done( $run_id, $event_id, false );
		} );
	}

	/** Read-only catalog for the caller's role (≤ 20 rows, never graph / prompt / internal links). */
	public static function list_scenarios( array $args, array $ctx ) {
		// [2026-10-05 06:20 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.91-AX-1.5 — hides off / not configured / HIL / read-only cards.
		$ctx = self::as_tool( $ctx, 'automation.list_scenarios' ); // [2026-10-09 03:09 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.95-S95-A2
		return BizCity_MCP_Action_Support::run_as( $ctx, static function ( $uid ) use ( $args, $ctx ) {
			if ( ! self::boot_addon() || ! class_exists( 'BizCity_Automation_Cell_Catalog' ) ) {
				return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::TOOL_NOT_FOUND, 'Website chưa bật phần tự động hoá.', 404 );
			}
			$role = self::principal_role( $ctx, (int) $uid );
			if ( '' === $role ) {
				return array( 'scenarios' => array(), 'total' => 0 );
			}
			$q    = isset( $args['q'] ) ? self::short( (string) $args['q'], 80 ) : '';
			$rows = array();
			// [2026-10-06 02:10 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.91-AX-1.5 — a search text that matches nothing must not hide the whole
			// catalog from the model (it then says "chưa có kịch bản" although one exists): fall back to the role's full list, flagged.
			// [2026-10-08 02:55 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.94-G-4 — same shape as the pack 1.3 (status · missing · target · item_rev · rich inputs); not_configured listed, marked
			$with_nc = in_array( $role, array( 'owner', 'staff' ), true ); // khách vãng lai không thấy "chưa cấu hình" + lý do nội bộ
			$list = BizCity_Automation_Cell_Catalog::list_for_cell( $role, $q, $with_nc );
			$q_matched = true;
			if ( '' !== $q && ! $list ) {
				$list      = BizCity_Automation_Cell_Catalog::list_for_cell( $role, '', $with_nc );
				$q_matched = false;
			}
			foreach ( $list as $e ) {
				$c      = $e['cell'];
				$inputs = array();
				foreach ( $c['inputs'] as $in ) {
					$f = array( 'name' => $in['name'], 'label' => $in['label'], 'type' => $in['type'], 'required' => (bool) $in['required'] );
					if ( ! empty( $in['options'] ) ) {
						$f['options'] = $in['options'];
					}
					// [2026-10-08 02:55 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.94-G-4 — nguồn nguyên liệu + câu hỏi (cell gom đủ trước khi gọi, contract 1.4 §4a)
					if ( '' !== (string) ( $in['source'] ?? '' ) ) {
						$f['source'] = (string) $in['source'];
					}
					if ( '' !== (string) ( $in['ask'] ?? '' ) ) {
						$f['ask'] = (string) $in['ask'];
					}
					// [2026-10-09 03:09 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.95-S95-A1 — 1.5.0 crm_field / consent
					if ( '' !== (string) ( $in['crm_field'] ?? '' ) ) {
						$f['crm_field'] = (string) $in['crm_field'];
					}
					if ( ! empty( $in['consent'] ) ) {
						$f['consent'] = true;
					}
					$inputs[] = $f;
				}
				$packed = class_exists( 'BizCity_Automation_Cell_Pack_Exporter' ) ? BizCity_Automation_Cell_Pack_Exporter::item_of( $e ) : array();
				$rows[] = array(
					'slug'        => $c['slug'],
					'name'        => $c['label'],
					'one_line'    => $c['one_line'],
					'keywords'    => $c['keywords'],
					'inputs'      => $inputs,
					'eta_seconds' => (int) $c['eta_seconds'],
					'reply'       => $c['reply'],
					// [2026-10-06 09:52 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.92-S92-CL-4 / S92-CL-3 — who may call it and what it does (cell confirms harder for publish/order/booking).
					'audience'    => (string) ( $c['audience'] ?? 'owner_agent' ),
					'effects'     => array_values( (array) ( $e['effects'] ?? array() ) ),
					'workflow_id' => (int) $e['workflow_id'],
					'status'      => 'not_configured' === (string) ( $e['blocks'] ?? '' ) ? 'not_configured' : 'usable',
				);
				$last = &$rows[ count( $rows ) - 1 ];
				foreach ( array( 'missing', 'target' ) as $k ) {
					if ( isset( $packed[ $k ] ) ) {
						$last[ $k ] = $packed[ $k ];
					}
				}
				$last['item_rev'] = (string) ( $packed['item_rev'] ?? '' );
				unset( $last );
				if ( count( $rows ) >= self::LIST_MAX ) {
					break;
				}
			}
			$out = array( 'scenarios' => $rows, 'total' => count( $rows ) );
			if ( ! $q_matched ) {
				$out['q_matched'] = false; // the list is everything this person may run; pick by name / one_line
			}
			return $out;
		} );
	}

	/**
	 * The 7 refusals in contract order (§4 rule 4): add-on → principal → scenario (missing / off / HIL / read-only /
	 * not configured) → role → rate → required inputs. Scenario codes travel as `details.reason` under a catalogued
	 * MCP code (the MCP error catalog is closed).
	 *
	 * @return array|WP_Error { entry, input, role, cell (the `_cell` block) }
	 */
	private static function plan_scenario( array $args, array $ctx, int $uid ) {
		// [2026-10-05 06:20 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.91-AX-1.4 / AX-1.7 — refusal order of automation-scenario@1 §8.
		$S = 'BizCity_MCP_Action_Support';
		// 1. add-on
		// [2026-10-05 11:00 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.91-AX-LOC — both scenario classes of the sibling plugin are guarded (an older
		// bizcity-automation without Cell_Scenario must answer TOOL_NOT_FOUND, never fatal on missing_inputs()).
		if ( ! self::boot_addon() || ! class_exists( 'BizCity_Automation_Cell_Catalog' ) || ! class_exists( 'BizCity_Automation_Cell_Scenario' ) ) {
			return $S::error( BizCity_MCP_Error::TOOL_NOT_FOUND, 'Website chưa bật phần tự động hoá.', 404 );
		}
		// 2. principal (identity ONLY from the auth context)
		$role = self::principal_role( $ctx, $uid );
		$cell = self::cell_block( $ctx, $role );
		if ( class_exists( 'BizCity_MCP_Delegation' ) && BizCity_MCP_Delegation::is_delegated( $ctx ) && '' === $cell['user_hash'] ) {
			return $S::error( BizCity_MCP_Error::DELEGATION_PRINCIPAL_UNBOUND, 'Không xác định được người giao việc.', 401 );
		}
		// 3. scenario
		$slug  = strtolower( trim( (string) ( $args['scenario'] ?? '' ) ) );
		$entry = BizCity_Automation_Cell_Catalog::find_by_slug( $slug );
		if ( null === $entry ) {
			return $S::error( BizCity_MCP_Error::NOT_FOUND, 'Không có kịch bản tên đó.', 404, array(
				'reason'      => 'scenario_not_found',
				'suggestions' => BizCity_Automation_Cell_Catalog::nearest_slugs( $slug ),
			) );
		}
		$name = $entry['cell']['label'];
		switch ( $entry['blocks'] ) {
			case '':
				break;
			case 'hil':
				return $S::error( BizCity_MCP_Error::QUERY_INVALID, 'Kịch bản «' . $name . '» cần hỏi đáp từng bước trên Zalo Bot; trợ lý chưa chạy được.', 409, array( 'reason' => 'scenario_needs_hil' ) );
			case 'read_only':
				return $S::error( BizCity_MCP_Error::QUERY_INVALID, 'Việc này tra cứu được ngay, không cần kịch bản.', 409, array( 'reason' => 'scenario_is_read_only' ) );
			case 'guest_unsafe':
				// [2026-10-05 11:40 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.91-AX-PERM — a guest card that holds an unsafe step is unusable for everyone until the owner fixes it.
				return $S::error( BizCity_MCP_Error::QUERY_INVALID, 'Kịch bản «' . $name . '» chưa sẵn sàng để chạy.', 409, array( 'reason' => 'scenario_guest_unsafe' ) );
			case 'not_configured':
				$first = $entry['missing'][0]['label'] ?? 'Kịch bản chưa cấu hình đủ.';
				return $S::error( BizCity_MCP_Error::QUERY_INVALID, $first, 409, array( 'reason' => 'scenario_not_configured', 'missing' => $entry['missing'] ) );
			default: // disabled, no_slug
				return $S::error( BizCity_MCP_Error::QUERY_INVALID, 'Kịch bản «' . $name . '» đang tắt.', 409, array( 'reason' => 'scenario_disabled' ) );
		}
		// 4. role (replaces the 0.88 is_admin check). [2026-10-05 11:40 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.91-AX-PERM — a customer passes only on audience `guest`
		//    (cell.roles then holds `customer`); owner_agent cards stay owner / staff.
		if ( '' === $role || ! in_array( $role, $entry['cell']['roles'], true ) ) {
			return $S::error( BizCity_MCP_Error::SCOPE_DENIED, 'Bạn chưa được phép chạy kịch bản này.', 403, array( 'reason' => 'scenario_role_denied' ) );
		}
		// 4b. contact (automation-scenario@1.5.0 §4): who the run is for ⇒ CRM contact id in `_cell`. Required on a guest turn.
		$contact = self::resolve_contact( $args, $role );
		if ( is_wp_error( $contact ) ) {
			return $contact;
		}
		$cell['contact_id'] = (int) $contact;
		// 5. rate
		$per_day = (int) ( $entry['cell']['rate']['per_day'] ?? 0 );
		if ( $per_day > 0 && BizCity_Automation_Cell_Catalog::runs_today( (int) $entry['workflow_id'], $cell['user_hash'], $uid ) >= $per_day ) {
			return $S::error( BizCity_MCP_Error::RATE_LIMITED, 'Hôm nay đã chạy đủ số lần cho phép.', 429, array( 'reason' => 'scenario_rate_limited', 'per_day' => $per_day ) );
		}
		// 6. inputs (identity / engine keys are the server's)
		$input = isset( $args['input'] ) && is_array( $args['input'] ) ? $args['input'] : array();
		foreach ( array_keys( $input ) as $k ) {
			if ( '_' === substr( (string) $k, 0, 1 ) || in_array( (string) $k, array( 'wp_user_id', 'source', 'user_hash', 'role', 'principal' ), true ) ) {
				unset( $input[ $k ] );
			}
		}
		if ( self::input_bytes( $input ) > self::INPUT_MAX_BYTES ) {
			return $S::error( BizCity_MCP_Error::QUERY_INVALID, 'Dữ liệu đầu vào quá lớn (tối đa 48 KB).', 413 );
		}
		// [2026-10-09 Johnny Chu - Chu Hoàng Anh] `text` of an old template = the person's message ({{trigger.text}}). The cell sends that
		// request as `prompt`, so an empty `text` is filled from it, never asked again (live 2026-10-09 14:13: 422 "Thiếu thông tin: Nội dung yêu cầu").
		$req = trim( (string) ( $args['prompt'] ?? '' ) );
		foreach ( (array) $entry['cell']['inputs'] as $in ) {
			$nm = (string) ( $in['name'] ?? '' );
			if ( in_array( $nm, array( 'text', 'prompt' ), true ) && '' !== $req && '' === trim( (string) ( is_scalar( $input[ $nm ] ?? null ) ? $input[ $nm ] : '' ) ) ) {
				$input[ $nm ] = function_exists( 'mb_substr' ) ? mb_substr( $req, 0, 2000, 'UTF-8' ) : substr( $req, 0, 2000 );
			}
		}
		$missing = BizCity_Automation_Cell_Scenario::missing_inputs( $entry['cell'], $input );
		if ( $missing ) {
			return $S::error( BizCity_MCP_Error::QUERY_INVALID, 'Thiếu thông tin: ' . $missing[0]['label'] . '.', 422, array( 'reason' => 'scenario_input_missing', 'fields' => $missing ) );
		}
		// [2026-10-08 02:55 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.94-S94-PKG — gói nguyên liệu phải đúng kiểu đã khai (ảnh / link = URL http(s), số, ngày, lựa chọn trong danh sách): website không hỏi lại
		$bad = self::invalid_inputs( (array) $entry['cell']['inputs'], $input );
		if ( $bad ) {
			return $S::error( BizCity_MCP_Error::QUERY_INVALID, 'Sai dạng thông tin: ' . $bad[0]['label'] . ' — ' . $bad[0]['why'] . '.', 422, array( 'reason' => 'scenario_input_invalid', 'fields' => $bad ) );
		}
		// [2026-10-08 02:55 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.94-S94-PKG — prompt = yêu cầu gốc của người dùng (cell gửi kèm gói); kịch bản đọc qua {{trigger.prompt}}
		$prompt = trim( (string) ( $args['prompt'] ?? '' ) );
		$prompt = function_exists( 'mb_substr' ) ? mb_substr( $prompt, 0, 2000, 'UTF-8' ) : substr( $prompt, 0, 2000 );
		// [2026-10-06 11:24 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.92 S92-LLM-11 — a declared optional input the caller left out is '', so
		// {{trigger.image_url}} resolves to nothing instead of staying a literal placeholder in a post.
		foreach ( (array) $entry['cell']['inputs'] as $in ) {
			if ( ! array_key_exists( (string) $in['name'], $input ) ) {
				$input[ (string) $in['name'] ] = '';
			}
		}
		$cell['scenario'] = $entry['cell']['slug'];
		return array( 'entry' => $entry, 'input' => $input, 'prompt' => $prompt, 'role' => $role, 'cell' => $cell );
	}

	/**
	 * [2026-10-08 02:55 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.94-S94-PKG — kiểm kiểu từng input đã khai (automation-scenario@1.4.0). Rỗng = bỏ qua (thiếu bắt buộc đã do missing_inputs chặn).
	 *
	 * @return array<int,array{name:string,label:string,why:string}>
	 */
	private static function invalid_inputs( array $declared, array $input ): array {
		$out = array();
		foreach ( $declared as $in ) {
			$name = (string) ( $in['name'] ?? '' );
			$v    = $input[ $name ] ?? null;
			if ( null === $v || '' === $v ) {
				continue;
			}
			$type = (string) ( $in['type'] ?? 'text' );
			$why  = '';
			// [2026-10-09 Johnny Chu - Chu Hoàng Anh] min_words — live 15:25: an instruction sentence was published as the article.
			$minw = (int) ( $in['min_words'] ?? 0 );
			if ( $minw > 0 && is_string( $v ) ) {
				$words = preg_match_all( '/[\p{L}\p{N}]+/u', $v );
				if ( (int) $words < $minw ) {
					$out[] = array( 'name' => $name, 'label' => (string) ( $in['label'] ?? $name ), 'why' => 'mới có ' . (int) $words . ' từ, cần là BÀI VIẾT HOÀN CHỈNH ít nhất ' . $minw . ' từ do trợ lý tự viết (không phải lời dặn) — viết đủ rồi gửi lại' );
					continue;
				}
			}
			if ( in_array( $type, array( 'image', 'audio', 'url' ), true ) ) {
				if ( ! is_string( $v ) || ! preg_match( '#^https?://[^\s]+$#i', trim( $v ) ) ) {
					$why = 'image' === $type ? 'cần LINK ảnh (http/https), không phải mô tả' : 'cần một đường link http/https';
				}
			} elseif ( 'number' === $type ) {
				if ( ! is_numeric( $v ) ) {
					$why = 'cần một con số';
				}
			} elseif ( 'date' === $type ) {
				if ( ! is_string( $v ) || false === strtotime( $v ) ) {
					$why = 'cần ngày giờ hợp lệ';
				}
			} elseif ( ( 'enum' === $type || 'choice' === $type ) && ! empty( $in['options'] ) ) {
				if ( ! in_array( (string) $v, array_map( 'strval', (array) $in['options'] ), true ) ) {
					$why = 'chỉ nhận: ' . implode( ', ', array_slice( (array) $in['options'], 0, 8 ) );
				}
			} elseif ( ! is_scalar( $v ) ) {
				$why = 'cần chữ';
			}
			if ( '' !== $why ) {
				$out[] = array( 'name' => $name, 'label' => (string) ( $in['label'] ?? $name ), 'why' => $why );
			}
		}
		return $out;
	}

	/**
	 * owner | staff | customer | '' for the caller. Delegated (cell) calls carry the role the site resolved from ITS
	 * binding; a site user (TwinWeb /gpt/, MCP key) is owner when administrator, staff when a CRM agent+, else nobody.
	 * [2026-10-05 11:40 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.91-AX-PERM — `customer` = a person chatting with the shop (khách vãng lai), only from a guru_public delegated context
	 * the site built itself; it opens nothing but audience-guest scenarios.
	 */
	public static function principal_role( array $ctx, int $uid ): string {
		$role = (string) ( $ctx['role'] ?? '' );
		if ( class_exists( 'BizCity_MCP_Delegation' ) && BizCity_MCP_Delegation::is_delegated( $ctx ) ) {
			if ( 'customer' === $role ) {
				return BizCity_MCP_Delegation::GURU_PUBLIC === (string) ( $ctx['principal_kind'] ?? '' ) ? 'customer' : '';
			}
			return in_array( $role, array( 'owner', 'staff' ), true ) ? $role : '';
		}
		if ( BizCity_MCP_Action_Support::is_admin( $uid ) ) {
			return 'owner';
		}
		return BizCity_MCP_Action_Support::crm_rank( $uid ) >= 1 ? 'staff' : '';
	}

	/**
	 * [2026-10-10 12:23 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.95-S95-W13 — D95-22: `job` {run_id, workflow_id, site_event_id} ⇒ the run row's meta.job. Honoured only on a cell call (delegated)
	 * for an owner/staff turn — a guest turn never comes from a schedule. Ids are clamped; an absent or bad block ⇒ empty array.
	 */
	public static function job_link( array $args, array $ctx, string $role ): array {
		$raw = isset( $args['job'] ) && is_array( $args['job'] ) ? $args['job'] : array();
		$delegated = class_exists( 'BizCity_MCP_Delegation' ) && BizCity_MCP_Delegation::is_delegated( $ctx );
		if ( ! $raw || ! $delegated || 'customer' === $role ) {
			return array();
		}
		$out = array();
		$run = substr( (string) preg_replace( '/[^A-Za-z0-9._:-]/', '', is_scalar( $raw['run_id'] ?? null ) ? (string) $raw['run_id'] : '' ), 0, 64 );
		if ( '' !== $run ) {
			$out['cell_run_id'] = $run;
		}
		foreach ( array( 'workflow_id', 'site_event_id' ) as $k ) {
			$n = isset( $raw[ $k ] ) && is_numeric( $raw[ $k ] ) ? (int) $raw[ $k ] : 0;
			if ( $n > 0 ) {
				$out[ $k ] = $n;
			}
		}
		return $out;
	}

	/** The `_cell` block — built from the auth context ONLY (contract §4 rule 2). */
	private static function cell_block( array $ctx, string $role ): array {
		$delegated = class_exists( 'BizCity_MCP_Delegation' ) && BizCity_MCP_Delegation::is_delegated( $ctx );
		$hash      = strtolower( preg_replace( '/[^a-f0-9]/i', '', (string) ( $ctx['user_hash'] ?? '' ) ) );
		return array(
			'platform'   => $delegated ? 'zalo' : 'wp',
			'account_id' => self::short( (string) ( $ctx['account_id'] ?? '' ), 64 ),
			'user_hash'  => substr( $hash, 0, 64 ),
			'role'       => $role,
			'surface'    => $delegated ? 'zalo_1_1' : 'wp',
			'turn_id'    => self::short( (string) ( $ctx['turn_id'] ?? '' ), 64 ),
			'scenario'   => '',
			// [2026-10-09 03:09 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.95-S95-A2/A3 — contract 1.5.0 §1.1: CRM contact (int, set by plan_scenario) and
			// whose turn it is. Never the platform UID.
			'contact_id'   => 0,
			'on_behalf_of' => 'customer' === $role ? 'guest' : '',
		);
	}

	/** @var callable|null test seam: fn(string $canon, string $uid, string $channel_ref, array $data): int — replaces BizCity_CRM_Contact_Identity::resolve_or_create */
	public static $contact_resolver = null;

	/**
	 * [2026-10-09 03:09 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.95-S95-A2 — the registry passes ctx unchanged: the handler names the tool it is,
	 * so run_as() can open the acting user for exactly these tools (BizCity_MCP_Action_Support::GUEST_ACTING_TOOLS).
	 */
	private static function as_tool( array $ctx, string $tool ): array {
		$ctx['tool'] = $tool;
		return $ctx;
	}

	/**
	 * [2026-10-09 03:09 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.95-S95-A3 — top-level `contact` ⇒ canonical platform ⇒ resolve_or_create ⇒ contact id.
	 * Guest (customer) turn: absent / no uid ⇒ 422 scenario_contact_missing; owner / staff: optional (0 = no contact). Lookup or create
	 * fails ⇒ 409 scenario_contact_unresolved. The UID is used for the lookup only: never logged, echoed or stored in `_cell` (R-CID-11).
	 *
	 * @return int|WP_Error
	 */
	private static function resolve_contact( array $args, string $role ) {
		$S     = 'BizCity_MCP_Action_Support';
		$raw   = isset( $args['contact'] ) && is_array( $args['contact'] ) ? $args['contact'] : array();
		$uid   = substr( trim( (string) ( $raw['platform_uid'] ?? '' ) ), 0, 190 );
		$guest = 'customer' === $role;
		if ( '' === $uid ) {
			if ( $guest ) {
				return $S::error( BizCity_MCP_Error::QUERY_INVALID, 'Thiếu thông tin người nhắn của lượt này.', 422, array( 'reason' => 'scenario_contact_missing' ) );
			}
			return 0;
		}
		$canon = class_exists( 'BizCity_CRM_Contact_Identity' ) ? BizCity_CRM_Contact_Identity::canon_platform( (string) ( $raw['platform'] ?? 'zalo' ) ) : strtolower( (string) ( $raw['platform'] ?? 'zalo' ) );
		$canon = '' !== $canon ? $canon : 'zalo';
		$ref   = substr( trim( (string) ( $raw['channel_ref'] ?? '' ) ), 0, 190 );
		$name  = self::short( (string) ( $raw['display_name'] ?? '' ), 120 );
		$data  = array( 'name' => '' !== $name ? $name : 'Khách ' . ( 'zalo' === $canon ? 'Zalo' : $canon ), 'source' => 'cell_scenario' ); // never the UID as a name
		$id    = 0;
		try {
			if ( is_callable( self::$contact_resolver ) ) {
				$id = (int) call_user_func( self::$contact_resolver, $canon, $uid, $ref, $data );
			} elseif ( class_exists( 'BizCity_CRM_Contact_Identity' ) ) {
				$id = (int) BizCity_CRM_Contact_Identity::resolve_or_create( $canon, $uid, $ref, $data );
			}
		} catch ( \Throwable $e ) {
			$id = 0;
		}
		if ( $id <= 0 ) {
			// [2026-10-09 09:25 PM Johnny Chu - Chu Hoàng Anh] R-AF-12 — owner / staff: the contact is only context, never a gate (it blocked "đăng web" on 0562 608 899, turn 925).
			if ( ! $guest ) {
				return 0;
			}
			return $S::error( BizCity_MCP_Error::QUERY_INVALID, 'Chưa xác định được khách hàng trong CRM.', 409, array( 'reason' => 'scenario_contact_unresolved' ) );
		}
		return $id;
	}

	/**
	 * [2026-10-09 03:09 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.95-S95-A3 — preview `contact_known` (contract 1.5.0): name, masked phone, district /
	 * province only, has_email, has_birthday. Unknown contact ⇒ all empty.
	 */
	private static function contact_known( int $contact_id ): array {
		$p = BizCity_MCP_Action_Support::contact_profile( $contact_id );
		$p = is_array( $p ) ? $p : array();
		return array(
			'name'           => (string) ( $p['name'] ?? '' ),
			'phone_masked'   => BizCity_MCP_Action_Support::mask_phone( (string) ( $p['phone'] ?? '' ) ),
			'address_masked' => BizCity_MCP_Action_Support::mask_address( (string) ( $p['address'] ?? '' ) ),
			'has_email'      => '' !== trim( (string) ( $p['email'] ?? '' ) ),
			'has_birthday'   => '' !== trim( (string) ( $p['birthday'] ?? '' ) ),
		);
	}

	private static function short( string $s, int $max ): string {
		$s = trim( (string) preg_replace( '/[\x00-\x1F\x7F]+/u', ' ', $s ) );
		return function_exists( 'mb_substr' ) ? mb_substr( $s, 0, $max, 'UTF-8' ) : substr( $s, 0, $max );
	}

	public static function preview( array $args, array $ctx ) {
		return BizCity_MCP_Action_Support::run_as( $ctx, static function ( $uid ) use ( $args ) {
			$plan = self::plan( $args, $uid );
			if ( is_wp_error( $plan ) ) {
				return $plan;
			}
			return array(
				'summary'  => 'Chạy kịch bản "' . $plan['workflow']['name'] . '"' . ( $plan['input'] ? ' với ' . count( $plan['input'] ) . ' trường dữ liệu' : '' ) . '.',
				'workflow' => $plan['workflow'],
			);
		} );
	}

	public static function run( array $args, array $ctx ) {
		return BizCity_MCP_Action_Support::run_as( $ctx, static function ( $uid ) use ( $args ) {
			$plan = self::plan( $args, $uid );
			if ( is_wp_error( $plan ) ) {
				return $plan;
			}
			$payload = array_merge( $plan['input'], array(
				'_owner_user_id' => (int) $uid,
				'wp_user_id'     => (int) $uid,
				'source'         => 'mcp.automation_run',
			) );
			$run_id = BizCity_Automation_Repo_Runs::enqueue( (int) $plan['workflow']['id'], $payload );
			if ( is_wp_error( $run_id ) ) {
				return BizCity_MCP_Action_Support::from_business( $run_id );
			}
			do_action( 'bizcity_automation_run_enqueued', $run_id, (int) $plan['workflow']['id'], $payload );
			if ( function_exists( 'wp_next_scheduled' ) && ! wp_next_scheduled( 'bizcity_automation_run_async', array( $run_id ) ) ) {
				wp_schedule_single_event( time(), 'bizcity_automation_run_async', array( $run_id ) );
			}
			return array( 'run_id' => (string) $run_id, 'workflow' => $plan['workflow'] );
		} );
	}

	private static function plan( array $args, $uid ) {
		if ( ! BizCity_MCP_Action_Support::is_admin( $uid ) ) {
			return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::SCOPE_DENIED, 'Chỉ quản trị viên mới chạy được kịch bản tự động.', 403 );
		}
		if ( ! self::boot_addon() ) { // [2026-10-05 04:43 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.91-AX-0.2 — lazy load at the seam
			return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::TOOL_NOT_FOUND, 'Add-on Automation chưa được cài trên site này.', 404 );
		}
		$id = (int) ( $args['workflow_id'] ?? 0 );
		$wf = $id > 0 ? BizCity_Automation_Repo_Workflows::find( $id ) : null;
		if ( ! is_array( $wf ) || ! empty( $wf['deleted_at'] ) ) {
			return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::NOT_FOUND, 'Không tìm thấy kịch bản #' . $id . '.', 404 );
		}
		if ( empty( $wf['enabled'] ) ) {
			return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::QUERY_INVALID, 'Kịch bản "' . (string) ( $wf['name'] ?? '#' . $id ) . '" đang tắt. Bật nó trong Automation rồi thử lại.', 409 );
		}
		$input = isset( $args['input'] ) && is_array( $args['input'] ) ? $args['input'] : array();
		foreach ( array_keys( $input ) as $k ) {
			if ( '_' === substr( (string) $k, 0, 1 ) || in_array( (string) $k, array( 'wp_user_id', 'source' ), true ) ) {
				unset( $input[ $k ] ); // identity / engine keys are set by the server, never by the caller
			}
		}
		if ( self::input_bytes( $input ) > self::INPUT_MAX_BYTES ) {
			return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::QUERY_INVALID, 'Dữ liệu đầu vào quá lớn (tối đa 48 KB).', 413 );
		}
		return array( 'workflow' => array( 'id' => (int) $wf['id'], 'name' => (string) ( $wf['name'] ?? '' ) ), 'input' => $input );
	}
}

BizCity_Automation_Action_MCP_Service::init();
