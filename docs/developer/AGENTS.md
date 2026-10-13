# AGENTS.md — for the AI coding assistant of a Zalo Brain plugin author
<!-- [2026-10-12 11:40 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.103 Q103-8 — chủ chốt MỘT cửa: nguồn sự thật = docs/developer/: chuyển từ developer/ (PHASE-0.104) vào đây; developer/ ở gốc đã bỏ. -->

<!-- [2026-10-12 Johnny Chu - Chu Hoàng Anh] PHASE-0.104 S104-L5 — ships with Zalo Brain in docs/developer/ (the single door). Audience: an AI assistant (Claude Code, Codex, Cursor, Copilot…) helping a developer build a plugin that Zalo Brain can call. Not the instructions for working ON Zalo Brain itself. -->

You are helping a developer turn their WordPress plugin into an AI capability that Zalo Brain can use on Zalo. Read this file, then
[STANDARD.md](STANDARD.md), then copy the shape of `starter/bizcity-booking-starter/`. Write code in English; user-facing strings may be Vietnamese.

## Hard rules (a plugin that breaks one is rejected by the validator or misbehaves in production)

1. **Never edit Zalo Brain** (`bizcity-twin-ai/` or any renamed copy). Everything goes in the developer's own plugin folder.
2. **One standard hook.** Register inside `add_action( 'zalo_brain_register', … )` with
   `BizCity_Zalo_Brain::register_from_manifest( __DIR__ . '/zalo-brain.json', <handlers> )`, plus the fallback call shown in
   [README.md §2](README.md) for older Zalo Brain builds. Do not register after `zalo_brain_loaded`.
3. **Detect the host by constant, not by folder.** Plugin header has `Zalo Brain Addon: <part>` and `Network: false`; **no** `Requires Plugins`.
   Guard runtime code with `defined( 'BIZCITY_TWIN_AI_VERSION' )`; refuse activation without it. Never build paths like
   `WP_PLUGIN_DIR . '/bizcity-twin-ai/…'` — the host folder can be renamed or forked.
4. **Tools are data + code.** Describe each tool in `mcp/tools.json` (`bizcity-mcp-library@1.0.0`): `name`, `title`, `description`,
   `when_to_use`, `mode`, `scopes[]`, `input_schema` (JSON Schema object), `roles`, `read_only`. The PHP side only maps names to handlers.
   List every tool name in `zalo-brain.json` `mcp_tools[]` and mark its handler with `// @mcp bizcity-mcp-standard@1 tool <name>`.
5. **Names:** `<group>.<verb>` or `<group>.<entity>.<verb>`, lowercase, prefixed by the plugin's own domain. Never reuse a core name
   (`booking.*`, `crm.*`, `knowledge.*`, `order.*`, `inventory.*`, `automation.*` belong to Zalo Brain).
6. **Every write tool** (`read_only: false`) declares `confirm: "always"`, `preview: true`, `required_cap` (a WordPress capability) and
   ships a `preview` callable that **writes nothing** and returns `{ summary }`. Zalo Brain owns the confirm token — handlers never check it.
7. **Errors:** return `WP_Error` with a **known** `MCP_*` code (list in STANDARD.md §4). Any other code is masked as `MCP_INTERNAL_ERROR`
   and marked retryable. Re-check preconditions inside the handler (the state may change between preview and commit).
8. **Scope the data to the caller.** Use `$ctx` (user, role, client) inside handlers; a customer must only ever see their own rows.
   Customer-facing tools must be read-only and are offered only when the shop's assistant allows them.
9. **Only published hooks** (29, listed in STANDARD.md §5 / `contracts/ZALO-BRAIN-HOOKS-v1.json`). Internal hooks may change any release.
10. **No secrets, no home paths** in the plugin or its JSON. No network calls inside a `preview`.

## Done means

- `node <zalo-brain>/docs/developer/tools/validate-extension.mjs .` prints **PASS** (WARN only for documented soft rules).
- `php -l` is clean on every PHP file; every JSON file parses.
- Each write tool: preview writes nothing; commit writes exactly once; a second commit with the same token is refused.
- Each error path returns a known `MCP_*` code.
- After activation, `GET /wp-json/bizcity/v1/zalo-brain` lists the plugin id and its tools.

Say plainly what you did **not** verify (for example: "not run on a real WordPress site / not called through Zalo").

## Where to look

| Need | File |
|---|---|
| Copy-ready plugin | `starter/bizcity-booking-starter/` |
| Manifest + hook contract | `contracts/ZALO-BRAIN-EXTENSION-CONTRACT-v1.md` (+ `.json`) |
| Tool vocabulary, envelopes, error codes | `contracts/BIZCITY-MCP-STANDARD-v1.json`, `contracts/BIZCITY-MCP-TOOL-CONTRACT-v1.md` |
| Published vs internal hooks | `contracts/ZALO-BRAIN-HOOKS-v1.json` (`status: "published"`) |
| Journal kinds a plugin may add | `contracts/JOURNAL-KINDS-v1.json` (+ filter `bizcity_journal_kinds`) |
