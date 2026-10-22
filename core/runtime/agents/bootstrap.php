<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Agents
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 *
 * Bootstrap for core/runtime/agents — only the Universal Artifact-Source Federation contract is left here.
 *
 * [2026-10-22 06:40 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.126 Q126-19 (owner: "gỡ, chấp nhận mất Image Studio, Studio Builder và thẻ duyệt HIL";
 * "Cell là trục não chính, không dùng PHP để suy luận") — the PHP agent primitives (BizCity_TwinShell_Agent / _Tool, BizCity_Twin_Agent_Registry,
 * the echo / image / twin_root agents) moved to core/_archived/runtime-agent-loop-20261022/ together with the runner and the REST /run endpoint.
 * The `bizcity_register_agent` filter is still applied by a few readers (guru skill bridge, twinbrain intent matcher, twin-crm probes); with no
 * registrant it returns an empty list, which every reader already handles.
 *
 * @since 1.13.0
 */

defined( 'ABSPATH' ) || exit;
if ( defined( 'BIZCITY_TWIN_AGENTS_LOADED' ) ) return;
define( 'BIZCITY_TWIN_AGENTS_LOADED', true );

// Vòng 4.5.0.a — Universal Artifact-Source Federation (Rule 8g). KG-Hub federation, CRM and TwinWeb read it.
require_once __DIR__ . '/contracts/class-artifact-source-federation.php';
