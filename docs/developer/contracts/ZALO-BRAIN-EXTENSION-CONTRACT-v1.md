# Zalo Brain Extension Contract v1 (`zalo-brain-extension@1`)

<!-- [2026-10-09 Johnny Chu - Chu Hoàng Anh] PHASE-0.96 S96-6.2 (D96-16, D96-17, D96-20) -->

**Status:** v1.1.0 · 2026-10-11 (PHASE-0.98 — ba cửa small win: tool MCP · khối Automation · kịch bản; `register_from_manifest()`, `register_block()`, `register_scenarios()`, `mcp_tools` có hiệu lực; additive) <!-- [2026-10-11 Johnny Chu - Chu Hoàng Anh] PHASE-0.98 S98-L7 --> · v1.0.0 · 2026-10-09 · **Owner:** Zalo Brain core (`core/runtime/class-zalo-brain.php`).
**Small-win guide (người viết plugin đọc trước):** [EXTENSION-SMALL-WIN-GUIDE-v1.md](../framework/EXTENSION-SMALL-WIN-GUIDE-v1.md) · **Phase:** `core/channel-gateway/docs/PHASE-0.98-EXTENSION-BRIDGE/`.
**Machine form:** [ZALO-BRAIN-EXTENSION-CONTRACT-v1.json](ZALO-BRAIN-EXTENSION-CONTRACT-v1.json) · **Hooks:** [ZALO-BRAIN-HOOKS-v1.json](ZALO-BRAIN-HOOKS-v1.json) · **Validator:** `node bin/validate-zalo-brain-extension.mjs <plugin-dir>`.
**Design:** `core/channel-gateway/docs/PHASE-0.96-ZALO-BRAIN-CRM-SPLIT/50-ZALO-BRAIN-FRAMEWORK.md`.

## 1. What an extension is

A separate WordPress plugin that adds a capability to Zalo Brain (`bizcity-twin-ai`) without editing it. Reference implementations: **Zalo Brain CRM** (`bizcity-twin-crm`) and **Automation** (`bizcity-automation`).

Rules:

1. Header `Zalo Brain Addon: <part>` (R-ADDON-HOST v1, PHASE-0.102; the legacy `Requires Plugins: bizcity-twin-ai` is still accepted by the validator), `Network: false`. Activates per site; never requires a multisite network.
2. Ships a `zalo-brain.json` manifest at its root (§2).
3. Registers itself with `BizCity_Zalo_Brain` during `plugins_loaded`, before `zalo_brain_loaded` (priority 20) (§3).
4. Never `require`s a core file by relative path. Core classes are loaded before the extension or not at all; an extension that finds core missing shows one admin notice and loads nothing.
5. Uses only **published** hooks (§4) from the core families. Its own hooks use its own prefix.
6. Core never `require`s an extension. Core asks `BizCity_Zalo_Brain::has( '<feature>' )` (or `BizCity_CRM_Spine::has()`) and answers `feature_unavailable` / `crm_feature_unavailable` with the four R-ERROR-UX fields when the feature is absent. MCP tools that only exist with the extension are not registered without it.
7. Tables: declared in its own R-DCL changelog (`core/helper/schema/changelog/<id>.json`, listed under `extension_owned_changelogs` in `CLIENT-LEAN-BUDGET-v1.json`). Never dropped on deactivate/uninstall.
8. Published repository holds the production package only (R-PROD-REPO): PHP, built `dist/`, runtime JSON/templates. No `src/`, `node_modules/`, `docs/`, tests, diagnostics.

## 2. Manifest `zalo-brain.json`

```json
{
  "contract": "zalo-brain-extension@1",
  "id": "bizcity-twin-crm",
  "name": "Zalo Brain CRM",
  "version": "1.0.0",
  "requires": { "zalo-brain": ">=1.4.0", "spine": { "crm": ">=1.0.0" } },
  "features": [ "pipeline", "tasks", "sla", "reports" ],
  "surfaces": [ { "surface": "crm", "pages": [ "pipeline", "tasks" ] } ],
  "tables_changelog": "modules.twin-crm",
  "mcp_tools": [ "staff.assign" ],
  "licence": { "tier": "premium" }
}
```

| Key | Required | Meaning |
|---|---|---|
| `contract` | yes | exactly `zalo-brain-extension@1` |
| `id` | yes | plugin folder slug |
| `name`, `version` | yes | display name, semver |
| `requires.zalo-brain` | yes | semver range of the main plugin |
| `requires.spine` | no | spine contracts needed (`crm` → `crm-spine@1`) |
| `features` | yes | feature ids this extension provides (`has()` answers true) |
| `surfaces` | no | pages added under one of the 7 surfaces: `gpt`, `gateway`, `crm`, `twinchat`, `scheduler`, `flow`, `setting` |
| `tables_changelog` | no | R-DCL changelog id for its tables |
| `mcp_tools` | no (**1.1: required when the plugin registers tools**) | MCP tool names the plugin registers through `register_tool()`. 1.1: `register_extension()` stores them (`describe().declared_tools`); the validator checks each has a `@mcp bizcity-mcp-standard@1 tool <name>` marker in the plugin (or in `core/mcp` for a door core keeps on its behalf) — WARN in 1.1, RED in 1.2 |
| `mcp_library` | no (1.1) | relative path of the tool library JSON (`bizcity-mcp-library@1`, PHASE-0.101 doc 30 §2.2); its `tools[].name` ⊆ `mcp_tools` |
| `automation_blocks` | no (1.1) | Automation block ids the plugin registers through `register_block()`; the validator requires each block's `meta()` to declare `effect`, `guest_safe`, `required_cap` |
| `scenarios` | no (1.1) | relative path of a scenario/template JSON (array of `{slug, graph}` like Automation's `templates/*.json`); registered by `register_from_manifest()`, seeded by Automation when present (hash ⇒ reseed) |
| `skills` | no (1.1, reserved) | folder of `SKILL.md` the plugin ships (PHASE-0.101 doc 20 §4) — read in a later wave |
| `licence.tier` | no | 1API master tier required (`premium`, …) |

## 3. Registration API (`BizCity_Zalo_Brain`)

| Method | Use |
|---|---|
| `register_from_manifest( string $file )` | **1.1 — the one line an extension needs:** reads `zalo-brain.json`, remembers the plugin dir, calls `register_extension()` and registers `scenarios`. Returns the decoded manifest or null |
| `register_extension( array $manifest )` | once, with the decoded manifest; registers its features and surface pages; 1.1 also stores `mcp_tools` / `mcp_library` and (with `_dir`) the `scenarios` file |
| `register_tool( array $tool )` | 1.1: the **only** registration path for an extension's MCP tool (descriptor keys of `tool-registry@1` + `handler`; optional `feature`, `owner`, `required_cap` / `no_standard_cap`, `effects`, `budget_ms`). A write without `required_cap` or `no_standard_cap: true` logs a warning (1.1) and is refused in 1.2 |
| `register_block( $block, $owner )` | 1.1: an Automation block (`BizCity_Automation_Block`: `id()`, `kind()`, `meta()`, `execute()`); queued onto `bizcity_automation_register_blocks`, registered at once when the registry already booted; only recorded when Automation is absent. Its `meta()` declares `effect`, `guest_safe`, `required_cap`; the canvas shows it without an FE rebuild (`BOOT.externalBlocks`) |
| `register_scenarios( string $file, $owner )` | 1.1: a scenario JSON the plugin contributes (same shape as Automation `templates/*.json`); Automation reads `scenario_files()` (filter `bizcity_automation_blueprint_files`) |
| `blocks()` · `scenario_files()` · `declared_tools()` · `tools_by_owner()` | 1.1 queries; all also in `describe()` |
| `register_feature( $id, $version, $owner )` | extra feature at runtime |
| `register_surface( $surface, array $def )` | one page under a surface: `id`, `label`, `cap`, `position` (core uses < 40), `feature`, `render` |
| `has( $feature )` · `features()` · `extensions()` · `surfaces()` · `describe()` | queries; `describe()` is the FE boot DTO, REST `GET /wp-json/bizcity/v1/zalo-brain` |
| `unavailable_error( $feature, $plugin_name )` | standard missing-feature `WP_Error` |

CRM extensions may call `BizCity_CRM_Spine::register_extension()` instead: it mirrors everything into `BizCity_Zalo_Brain`.

Lifecycle:

```
bizcity-twin-ai.php → foundation → channel-gateway → BizCity_Zalo_Brain (registry) → core/crm (spine)
plugins_loaded@5   do_action( 'zalo_brain_register' )
plugins_loaded@6   extensions boot (bizcity-twin-crm, bizcity-automation …) and register
plugins_loaded@20  do_action( 'zalo_brain_loaded' )   ← registration closed (_doing_it_wrong after this)
```

## 4. Published hooks

Only the hooks listed as `published` in [ZALO-BRAIN-HOOKS-v1.json](ZALO-BRAIN-HOOKS-v1.json) are part of this contract. Every other `bizcity_*` hook is `internal`: it may be renamed or removed without notice. The v1 list:

| Family | Hooks |
|---|---|
| Framework | `zalo_brain_register`, `zalo_brain_loaded`, `zalo_brain_surfaces` (filter), `zalo_brain_boot_dto` (filter), `zalo_brain_has_feature` (filter) |
| CRM spine | `bizcity_crm_message_persisted`, `bizcity_crm_message_inserted`, `bizcity_crm_message_delivery_updated`, `bizcity_crm_contact_saved`, `bizcity_crm_magic_link_consumed`, `bizcity_crm_staff_status`, `bizcity_crm_spine_loaded`, `bizcity_crm_install_tables` (filter), `bizcity_crm_install_migrate`, `bizcity_crm_register_adapters` (filter), `bizcity_crm_spine_has_feature` (filter), `bizcity_crm_register_surfaces`, `bizcity_crm_inbox_actions`, `bizcity_crm_contact_card`, `bizcity_crm_conversation_dto` (filters, rendered in W7) |
| Channels | `bizcity_channel_normalized`, `bizcity_channel_outbound_logged` |
| Scheduler | `bizcity_crm_event_create` (filter) and the `bizcity_scheduler_*` result events listed in the JSON |
| MCP | `bizcity_mcp_register_tools` (**action**, no argument — register inside the callback; a filter callback never runs) |
| Automation (1.1) | `bizcity_automation_register_blocks` (action, `$registry`) · `bizcity_automation_blueprint_files` (filter, string[] of scenario JSON paths) |

## 5. Versioning

Adding an optional manifest key, a method or a published hook is a minor bump. Removing or changing the meaning of any of them is a major bump (`zalo-brain-extension@2`) with one release of overlap.

| Version | Date | Change |
|---|---|---|
| 1.1.0 | 2026-10-11 | PHASE-0.98 S98-L7: `register_from_manifest`, `register_block`, `register_scenarios`, queries; manifest `mcp_library` / `automation_blocks` / `scenarios` / `skills`; `mcp_tools` read and validated; `required_cap` / `no_standard_cap` on `register_tool`; two Automation hooks published; `describe()` + `contract_version`, `tools_by_owner`, `declared_tools`, `blocks`, `scenarios`. Additive — every 1.0 plugin keeps working. <!-- [2026-10-11 Johnny Chu - Chu Hoàng Anh] PHASE-0.98 --> |
| 1.0.0 | 2026-10-09 | PHASE-0.96 S96-6.2 — first version. |

## 6. Small win — ba cửa, bốn bước (1.1, PHASE-0.98, R-LEAN-4)

Một plugin góp khả năng cho Zalo Brain qua đúng **ba cửa**; cell dùng được ngay qua MCP (hội thoại / harness) hoặc qua kịch bản Automation. Mọi cửa đều **khai quyền** (R-NB-10, FRAMEWORK-GUIDE Phụ lục A): người đang làm việc thiếu quyền ⇒ mã lỗi 4 trường, không im lặng.

| Cửa | Plugin viết | Cell / người dùng thấy |
|---|---|---|
| **Tool MCP** | `register_tool()` với `mode` (bắt buộc), `read_only`, `confirm` (+ `preview` khi ghi), `required_cap` hoặc `no_standard_cap`, `llm_alias`; marker `// @mcp bizcity-mcp-standard@1 tool <name>` | tool xuất hiện trong `tools/list` ⇒ cell tự dựng tool cho model theo `mode` của người (owner/staff 1-1) — không sửa cell; harness thấy qua `harness-mcp-registry@1` |
| **Khối Automation** | `register_block()` với `meta()` khai `label, fields, defaults, effect, guest_safe, required_cap` (+ `requires`, `inputs`, `outputs`) | khối lên **canvas** của chủ (palette, inspector) không cần build FE; kịch bản chứa khối ⇒ `required_caps[]` tự tính; chạy ⇒ `block_cap_denied` khi thiếu quyền |
| **Kịch bản mẫu** | file JSON `scenarios` (blueprint `cell_request` + `return_to_cell`) | gieo vào thư viện Automation của site; cell thấy trong gói `automation`; người dùng gọi bằng một câu (R-AF-12) |

Bốn bước (≤ 4, R-LEAN-4): ① `zalo-brain.json` (id, features, `mcp_tools`, `automation_blocks`, `scenarios`) · ② một dòng `add_action('zalo_brain_register', fn() => BizCity_Zalo_Brain::register_from_manifest(__DIR__.'/zalo-brain.json'))` · ③ `register_tool()` / `register_block()` cho từng khả năng · ④ `node bin/validate-zalo-brain-extension.mjs <plugin>` xanh. Chi tiết, mẫu copy-paste và danh sách cap chuẩn: [EXTENSION-SMALL-WIN-GUIDE-v1.md](../framework/EXTENSION-SMALL-WIN-GUIDE-v1.md).
