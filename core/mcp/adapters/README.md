# core/mcp/adapters — Zalo Brain wraps a plugin that has no manifest

<!-- [2026-10-11 Johnny Chu - Chu Hoàng Anh] PHASE-0.101 S101-L5 (doc 30 §3, D101-8) — contract bizcity-mcp-adapter@1.0.0, loader BizCity_MCP_Plugin_Adapter. -->

One JSON file per wrapped plugin, **≤ 8 read tools**, loaded on `bizcity_mcp_register_tools` (priority 5) by
`BizCity_MCP_Plugin_Adapter`. The adapter registers through `BizCity_Zalo_Brain::register_tool()` with
`owner = adapter:<slug>`, so `describe().tools_by_owner` tells the cell which plugin a tool belongs to.

| Key | Meaning |
|---|---|
| `requires` | `{ "class": … }` · `{ "function": … }` · `{ "filter": … }` — when it does not hold, no tool is registered (a disabled plugin takes its tools with it; never a fake answer) |
| `tools[].marker` | the catalog marker `@mcp bizcity-mcp-standard@1 tool <name>` — `bin/validate-mcp-standard.mjs` reads it from this JSON exactly as from PHP |
| `tools[].source` | `php_callable` (`Class::method` / function + `args` of literals or `$placeholders`), `wp_filter` (`apply_filters( filter, null, args, ctx )`), `rest_route` (internal GET) |
| `tools[].source.guard` | `crm_contact:<arg>` — resolves `contact_ref` through `BizCity_MCP_Action_Support::contact_access()` for the acting user and provides `$contact_id` |
| `tools[].output_map` | `data.<key>` ← dotted path into the raw answer; no map ⇒ the whole answer |

Rules: reads only (`read_only: true`, never `destructive`); `mode` + `scopes` required; the read budget is 5 s (over it the
answer carries `_slow_ms`); a plugin that later ships `zalo-brain.json` + `mcp/tools.json` with the same slug wins and the
adapter is skipped; a tool name already registered by real code is never shadowed. Files named `*.example` are ignored.
