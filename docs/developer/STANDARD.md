# Zalo Brain framework standard v1 — for plugin authors
<!-- [2026-10-12 11:40 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.103 Q103-8 — chủ chốt MỘT cửa: nguồn sự thật = docs/developer/: chuyển từ developer/ (PHASE-0.104) vào đây; developer/ ở gốc đã bỏ. -->

<!-- [2026-10-12 Johnny Chu - Chu Hoàng Anh] PHASE-0.104 S104-L5 — the shippable digest of the rules a plugin must meet. Sources of truth: contracts/ (mirrored), core/mcp/includes/class-mcp-error.php (error codes), core/runtime/class-zalo-brain.php (registration API). If this file and a contract disagree, the contract wins — tell the Zalo Brain team. -->

Contracts: `zalo-brain-extension@1` (manifest + registration) · `bizcity-mcp-library@1.0.0` (tool library) · `bizcity-mcp-standard@1`
(tool vocabulary, envelopes). Zalo Brain itself is the plugin `bizcity-twin-ai`; its folder may be renamed or forked.

## 1. Your plugin and the host

| Rule | Why |
|---|---|
| Header `Zalo Brain Addon: <part>` and `Network: false` | Zalo Brain finds add-ons by main-file name + this header in **any** folder |
| **No** `Requires Plugins:` header | It names a folder; the host folder can differ (`zalo-brain-main`, a fork…) |
| Detect the host with `defined( 'BIZCITY_TWIN_AI_VERSION' )` | Same reason; a constant survives any folder name |
| Refuse activation without the host: `register_activation_hook` ⇒ `deactivate_plugins()` + `wp_die( 'needs Zalo Brain' )` | No half-working plugin |
| Without the host at runtime: load nothing, show one admin notice | Never a fatal error |
| Never `require` a file of the host by path; never `WP_PLUGIN_DIR . '/bizcity-twin-ai/…'` | Host internals move between releases |
| Activates per site; never requires multisite | Production target is a single site |

Minimal main file: see `starter/bizcity-booking-starter/bizcity-booking-starter.php`.

## 2. `zalo-brain.json` (manifest)

| Key | Required | Meaning |
|---|---|---|
| `contract` | yes | `zalo-brain-extension@1` |
| `id` | yes | = your plugin folder slug |
| `name`, `version` | yes | display name, semver |
| `requires.zalo-brain` | yes | semver range of the host, e.g. `>=1.4.0` |
| `features[]` | yes | feature ids you provide (`BizCity_Zalo_Brain::has()` answers true) |
| `mcp_tools[]` | when you ship tools | every tool name you register |
| `mcp_library` | when you ship tools | relative path of your tool library, usually `mcp/tools.json` |
| `automation_blocks[]` | no | Automation block ids you register with `register_block()` |
| `scenarios` | no | relative path of a scenario JSON (`[{slug, graph}]`) |
| `surfaces[]` | no | extra pages under `gpt`, `gateway`, `crm`, `twinchat`, `scheduler`, `flow`, `setting`, or an `apps` entry |
| `tables_changelog` | no | changelog id of the tables you own (never dropped on deactivate) |

Full table: `contracts/ZALO-BRAIN-EXTENSION-CONTRACT-v1.md` §2.

## 3. Tool library `mcp/tools.json` (`bizcity-mcp-library@1.0.0`)

```json
{ "contract": "bizcity-mcp-library@1.0.0", "owner": "<id>", "tools": [ { …one object per tool… } ] }
```

| Field | Rule |
|---|---|
| `name` | `<group>.<verb>` or `<group>.<entity>.<verb>`; lowercase; unique; not a core group (`booking`, `crm`, `knowledge`, `brain`, `order`, `inventory`, `automation`, `document`…) |
| `title`, `description` | required; `description` says what it does and whether it WRITES |
| `when_to_use` | recommended (a write without it is a WARN today, RED in a later contract) |
| `mode` | required — the business mode that unlocks the tool for a delegated principal (e.g. `booking`) |
| `scopes[]` | required, e.g. `["booking.read"]` |
| `input_schema` | JSON Schema with `"type": "object"` |
| `roles` | who may call: `owner`, `staff`, `customer` (customer ⇒ read-only, thread scope) |
| `read_only` | `true` for reads; `false` makes it a write |
| write: `confirm`, `preview`, `required_cap` | `"always"`, `true`, a WordPress capability (or `no_standard_cap: true` with a reason) |
| `handler` | **never** in JSON — code lives in PHP |

Handler signature: `function ( array $args, array $ctx ) : array|WP_Error`. Preview: same signature, writes nothing, returns `['summary' => '…']`.
Zalo Brain calls the preview first and hands the user a confirm token; the handler runs only on the second call with the same arguments.
Changed arguments ⇒ `MCP_CONFIRM_ARGS_CHANGED`; a token is single-use and bound to the user and the client.

## 4. Error codes your handler may return

Only these codes pass through unchanged (`BizCity_MCP_Error::is_known_code`); any other code becomes `MCP_INTERNAL_ERROR` (retryable).
Most useful for plugins:

| Code | When |
|---|---|
| `MCP_QUERY_INVALID` | bad or missing argument |
| `MCP_NOT_FOUND` | the record does not exist (or is not the caller's) |
| `MCP_PERMISSION_DENIED` | the caller lacks the right |
| `MCP_BOOKING_SLOT_TAKEN` | a time slot is no longer free |
| `MCP_INVENTORY_NOT_ENOUGH` · `MCP_INVENTORY_RESERVE_UNAVAILABLE` | stock checks |
| `MCP_STAFF_UNREACHABLE` | nobody to hand the job to |
| `MCP_RATE_LIMITED` | your own limit was hit |
| `MCP_GOOGLE_NOT_CONNECTED` | an external account is not connected |

Also known (mostly raised by Zalo Brain itself): `MCP_AUTH_REQUIRED`, `MCP_AUTH_INVALID`, `MCP_SCOPE_DENIED`, `MCP_TENANT_NOT_FOUND`,
`MCP_NOTEBOOK_NOT_FOUND`, `MCP_NOTEBOOK_ACCESS_DENIED`, `MCP_RETRIEVAL_FAILED`, `MCP_SNAPSHOT_NOT_FOUND`, `MCP_SNAPSHOT_EXPIRED`,
`MCP_SNAPSHOT_STALE`, `MCP_PASSAGE_NOT_FOUND`, `MCP_PASSAGE_ACCESS_DENIED`, `MCP_CITATION_INVALID`, `MCP_CONTEXT_PACK_NOT_FOUND`,
`MCP_CONTEXT_PACK_EXPIRED`, `MCP_DRAFT_INVALID`, `MCP_RENDER_BLOCKED_BY_VALIDATION`, `MCP_RENDERER_UNAVAILABLE`, `MCP_RENDER_FAILED`,
`MCP_ACTION_CONFIRMATION_REQUIRED`, `MCP_TOOL_NOT_FOUND`, `MCP_TOOL_DISABLED`, `MCP_MODE_NOT_ALLOWED`, `MCP_DELEGATION_PRINCIPAL_UNBOUND`,
`MCP_CONFIRM_ARGS_CHANGED`, `MCP_CONFIRM_INVALID`, `MCP_WRITE_IN_PROGRESS`, `MCP_ROLE_GROUP_DENIED`, `crm_feature_unavailable`, `MCP_INTERNAL_ERROR`.

## 5. Published hooks (29 of 606)

Use only these (PHASE-0.104 added `bizcity_context_emit`); every other hook in `contracts/ZALO-BRAIN-HOOKS-v1.json` has `status: "internal"` and may change.

| Area | Hooks |
|---|---|
| Lifecycle | `zalo_brain_register` (action) · `zalo_brain_loaded` (action) · `zalo_brain_has_feature` · `zalo_brain_surfaces` · `zalo_brain_apps` · `zalo_brain_boot_dto` (filters) |
| MCP | `bizcity_mcp_register_tools` (action) |
| Data sources | `bizcity_context_emit` (action — you fire it with each record; `context-producer@1`) |
| Journal (the register) | `bizcity_journal_record( $entry )` (function — ONE row per thing worth remembering, `journal-entry@1.2.0` — declare layer · kind · ref code · job link · access (R-BRAIN-SKELETON BS-3; `contracts/JOURNAL-ENTRY-v1.json`, `JOB-LINK-v1.json`, `BRAIN-ACCESS-v1.json`; 2026-10-20 PHASE-0.126)) · `bizcity_journal_kinds` (filter) or manifest `journal_kinds` · actions `bizcity_journal_entry_added`, `bizcity_journal_entry_state` <!-- [2026-10-17 07:44 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.121 S121-J1…J5/K2/K5 code --> |
| Automation | `bizcity_automation_register_blocks` (action) · `bizcity_automation_blueprint_files` (filter) |
| Channels | `bizcity_channel_normalized` · `bizcity_channel_outbound_logged` (actions) |
| CRM | actions `bizcity_crm_contact_saved`, `bizcity_crm_event_create`, `bizcity_crm_install_migrate`, `bizcity_crm_magic_link_consumed`, `bizcity_crm_message_delivery_updated`, `bizcity_crm_message_inserted`, `bizcity_crm_message_persisted`, `bizcity_crm_spine_loaded` · filters `bizcity_crm_install_tables`, `bizcity_crm_pack_customer_row`, `bizcity_crm_register_adapters`, `bizcity_crm_spine_has_feature`, `bizcity_crm_staff_status`, `bizcity_crm_register_surfaces`, `bizcity_crm_inbox_actions`, `bizcity_crm_contact_card`, `bizcity_crm_conversation_dto` |

Journal kinds (no code): filter `bizcity_journal_kinds` or a `journal_kinds` key, shape in `contracts/JOURNAL-KINDS-v1.json`.

**Journal = the one register (R-JOURNAL v1.1, Tier 0).** Anything your plugin wants the assistant to remember, count, find or mirror to Google Sheets is ONE row in `bizcity_journal_entries` — never your own table. Declare your kinds (family = your slug, kinds `<slug>.<object>`), then:

```php
add_action( 'zalo_brain_register', fn() => BizCity_Zalo_Brain::register_from_manifest( __DIR__ . '/zalo-brain.json' ) ); // manifest: "journal_kinds": "journal-kinds.json"
$entry = bizcity_journal_record( array(
	'owner_user_id'   => $user_id,          // ZBC-3: every row has a WP user
	'kind'            => 'booking.table',   // declared above, else WP_Error journal_kind_undeclared
	'idempotency_key' => (string) $booking_id, // becomes plg:<slug>:<id> — a replay never adds a second row
	'plugin'          => 'bizcity-booking',
	'title'           => 'Bàn 4 người 19:00',
	'summary'         => 'Khách Lan, 4 người, 19:00 thứ 6.',
	'doc_date'        => '2026-10-24',
	'card'            => array( 'guests' => 4, 'time' => '19:00' ),
) );
```
The cell then answers "tối nay mấy bàn?" through MCP `journal.find` / `journal.summarize`; the Sheet mirror, CSV export and the register page come for free (`bizcity_journal_entry_added`). States: `captured · review · verified · failed · void` (`bizcity_journal_set_state()`; `verified` only by a person). <!-- [2026-10-17 07:44 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.121 S121-J1…J5/K2/K5 code -->

## 6. Lifecycle and the one standard hook

```
plugins_loaded@5   do_action( 'zalo_brain_register' )   ← register here
plugins_loaded@20  do_action( 'zalo_brain_loaded' )     ← registration closed; features/tools final
```

`BizCity_Zalo_Brain::register_from_manifest( string $file, array $handlers = [] ): ?array` reads the manifest, registers features, surfaces,
declared tools and scenarios, and — when `$handlers` is given and the manifest has `mcp_library` — registers the tool library owned by
the manifest id. The returned manifest carries `_mcp_registered` (names really registered). Older hosts ignore `$handlers`; see the
fallback in [README.md §2](README.md). Other calls: `register_block()`, `register_scenarios()`, `register_tool()`, `has()`, `describe()`.

## 7. Compatibility

- Within `1.x` changes are additive only; a breaking change bumps the major and keeps fixtures for both.
- Soft rules start as WARN and become RED one contract version later (announced in the contract's changelog).
- `GET /wp-json/bizcity/v1/zalo-brain` (logged-in) shows `contract_version`, your extension, `declared_tools`, `tools_by_owner`.

## 8. Before you ship

1. `node <zalo-brain>/docs/developer/tools/validate-extension.mjs .` ⇒ PASS.
2. `php -l` on every file; JSON parses.
3. Each write: preview writes nothing · commit once · token reused ⇒ refused · state changed in between ⇒ known `MCP_*` error.
4. Each handler filters by `$ctx`; a customer never sees another customer's data.
5. No secrets, no absolute home paths, no network calls in a preview.

## 9. Four doors, one hook (R-ZALO-BRAIN-FRAMEWORK v1.2)

<!-- [2026-10-12 10:10 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.104 — supreme rule v1.2 (CANON v1.153), owner decisions Q104-1…6. -->

| Door | Call | Status in this build |
|---|---|---|
| MCP tool | `register_from_manifest( $file, $handlers )` (+ fallback in README §2) | available |
| Automation block | `BizCity_Zalo_Brain::register_block( $block, $owner )`; declare `meta()` `label`, `effect`, `guest_safe`, `required_cap`, `ask_examples[]` | registration available; coloured list + one-click "cell scenario" from a block: **in progress (PHASE-0.104 S104-B1…B3)** |
| Scenario template | manifest `scenarios` | available |
| Data source | `register_context_producer( $id, $def )` (or manifest `context_producers[]`) + `do_action( 'bizcity_context_emit', $id, $record )` | available from PHASE-0.104 — guard with `method_exists( 'BizCity_Zalo_Brain', 'register_context_producer' )`; record = `{occurred_at, title, text?, card: {metric: number}, entity?: {type, key}}`; same `entity` twice in a day is counted once |

Rules for every door:

- Owner + site admin see everything; staff per `roles` / `required_cap`; customers never see a data source and reach only read-only, `guest_safe` blocks through a scenario the shop enabled.
- "Today" numbers come live from your own system (expose a read tool); daily notebooks serve history and months.
- Daily notebooks are kept forever; UIs show the 10 latest days with filter/search for older ones.
- Notebook text carries no phone, e-mail or address; mark sensitive kinds `sensitive`.
- A block that writes gets a scenario created **switched off** (preview + confirm); a read-only block's scenario can be switched on at once.
- Nothing your plugin ships may be read from `docs/` (except `docs/developer/`), `bin/` or `tests/` at runtime.

## 10. Platform input is untrusted (R-ZBF-11, field lesson)

<!-- [2026-10-18 02:27 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.121 — first real case: a Zalo file arrived with `propertyExt.ext` = `{"shouldParseLinkOrContact":0}`; the parser glued it onto the name (`x.docx. {…}`), the extension read as empty and a Word file was refused as "unsupported format". Additive to contract 1.x. -->

| Rule | Why |
|---|---|
| Every field that comes from a platform (Zalo, Meta, Woo, a browser, a user upload) is **untrusted data**: check its shape before you use it. | The platform's field can carry something other than what its name says. |
| Never concatenate an unchecked metadata field into a **name, path, extension, URL, key or SQL/JSON string**. Accept a value only if it matches the shape you expect (e.g. an extension: `^(?=.*[a-z])[a-z0-9]{1,8}$`) and otherwise fall back to what you already know. | One junk value turned a valid .docx into a refusal on both the cell and the site. |
| A refusal such as "unsupported format" must show (in the log or the receipt) the **raw value that failed**, never only the label. | The owner's screenshot showed the junk name — that is how the cause was found in one step. |
| A test with a payload you wrote yourself is not evidence about the platform. Capture one real payload per message type (mask phone, UID, URL, file hash) and keep it as a fixture; add a junk-value case next to it. | Our synthetic `share.file` fixture was well-formed, so 5,800 green tests missed it. |
| The same check applies on both sides: the host validates what a plugin sends, a plugin validates what the host passes. | The cell and the site each refused the file on their own. |
