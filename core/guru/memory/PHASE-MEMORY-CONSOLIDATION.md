# PHASE MEMORY-CONSOLIDATION — Historical SQL baseline (superseded by Context Bank)

> **Context Bank supersession - 2026-09-01:** This document is retained for
> historical audit context only. Its proposed SQL consolidation into
> `bizcity_memory` is no longer the implementation target. All new memory,
> session, note and rule payloads must use the registered encrypted
> JSONL/business filestore and Context Bank pointer/correlation ledger. No
> `bizcity_memory_*` SQL table or unified `bizcity_memory` table may receive
> new canonical payload writes. Use [R-CONTEXT-BANK](../../docs/rules/PHASE-0-RULE-CONTEXT-BANK.md),
> [PHASE-1.33](../../docs/roadmaps/PHASE-1.33-CONTEXT-BANK-IMPLEMENTATION-ROADMAP.md)
> and the lifecycle roadmap for current decisions.

> **Framework companion:** [R-TGL-CS - Twin Goal Loop Case Scope](../../docs/rules/PHASE-0-RULE-TWIN-GOAL-LOOP-CASE-SCOPE.md). `identity_uuid` là owner, không phải read scope; case-based memory phải dùng `goal_case` + `case_id`.

> **Trạng thái:** SPEC GỐC / D4→D7 GATE — D4/D5 staging code đã landed qua
> follow-up 2026-07-31; chưa migrate dữ liệu, chưa cutover, chưa drop. Đợi
> đủ DDV và founder sign-off trước M6/M7.
> **Owner:** `core/memory/` + cross-cut với `core/knowledge/` + `core/twinbrain/`.
> **Created:** 2026-05-24 · **Ngữ cảnh:** founder hỏi *"sao tôi thấy
> wp_1258_bizcity_memory_users chỉ lưu 2 rows, các bảng khác lưu thế nào,
> bảng nào không dùng thì comment out đi, gộp được thành 1 `_bizcity_memory`
> rồi chia type ra thì lại càng OK"*.

**Canonical follow-up docs (2026-07-31):**
[PHASE-MEMORY-UNIFY-ANALYSIS.md](docs/PHASE-MEMORY-UNIFY-ANALYSIS.md) là code audit
hiện trạng; [PHASE-MEMORY-UNIFY-ROADMAP.md](docs/PHASE-MEMORY-UNIFY-ROADMAP.md) là
roadmap triển khai Zalo/WebChat/Notes/TwinWeb; schema history canonical nằm tại
`core/diagnostics/changelog/core.memory.unified.json`.

**Reading rule:** The audit tables, SQL DDL and migration matrix below are
historical evidence from the pre-Context-Bank design. They are retained for
traceability and must not be executed or treated as current implementation
instructions. Current storage is defined by R-CONTEXT-BANK and Phase 1.33:
encrypted JSONL/business filestore payload plus a pointer-only Context Bank
ledger.

---

## 0 · Historical TL;DR

- 8 bảng `bizcity_memory_*` hiện hữu, **3 thực sự sống** trong pipeline
  TwinBrain, 1 sống nhưng isolated (webchat), 1 chết, 3 phụ trợ cho Memory
  Spec (Phase 1.15).
- **Historical D4→D7 plan, no longer current:** do not use this SQL
  consolidation as an implementation plan. Current work must:
  1. Wire migration data từ bảng cũ → bảng mới.
  2. Update Memory_Recall + Memory_Writer + REST owner-self.
  3. Bump R-DCL changelog + ship probe verify.
- The current destination is Context Bank: encrypted JSONL/business filestore
  payload plus `bizcity_context_bank` pointer/correlation metadata. The SQL
  table `bizcity_memory` is not a payload destination.

---

## 1 · Audit hiện trạng (2026-05-24)

| # | Bảng | Verdict | Owner | Ai READ | Ai WRITE | Pipeline Tier |
|---|---|---|---|---|---|---|
| 1 | `bizcity_memory_users` | ✅ **ACTIVE** | `core/knowledge` `BizCity_User_Memory` | `Memory_Recall::collect()` Tier A+B | `Memory_Writer::extract_and_persist()` + REST `/memory/me` | **A** (explicit) + **B** (extracted) |
| 2 | `bizcity_memory_episodic` | ✅ ACTIVE | `core/intent` `BizCity_Episodic_Memory` | `Memory_Recall::collect_episodic()` Tier C (cap 5) | Hook `bizcity_intent_processed` @12 | **C** (goal/event memories) |
| 3 | `bizcity_memory_rolling` | ✅ ACTIVE | `core/intent` `BizCity_Rolling_Memory` | `Memory_Recall::collect_rolling()` Tier D (cap 5) | Hook `bizcity_intent_processed` @10 | **D** (rolling conversation window) |
| 4 | `bizcity_memory_session` | ⚠️ ISOLATED | `modules/webchat` `BizCity_Webchat_Memory` | Webchat module nội bộ | LLM extract từ webchat history | (chưa nối vào TwinBrain) |
| 5 | `bizcity_memory_notes` | ✅ ACTIVE | `modules/twinchat/_library/notebooklm` `Notes_Service` | Notebook UI + studio input builder | Notes controller `wpdb->insert()` | (Companion notebook — chưa wire vào Layer 0.5) |
| 6 | `bizcity_memory_research` | ❌ **DEAD** | (migration artifact) | — | — | — |
| 7 | `bizcity_memory_specs` | ✅ ACTIVE | `core/memory` `BizCity_Memory_Manager` | REST `/wp-json/bizcity/memory/v1/*` + admin tree UI | Manager CRUD | (Phase 1.15 working brief — orthogonal với 4 Tier) |
| 8 | `bizcity_memory_logs` | ✅ ACTIVE | `core/memory` `BizCity_Memory_Log` | REST `/(?P<id>\d+)/log` | Append-only mỗi mutation `_specs` | (audit trail cho `_specs`) |

### 1.1 Vì sao `bizcity_memory_users` chỉ có 2 rows?

- User mới chat thử `"hãy nhớ tôi tên Johnny Chu"` → Layer 4.7 Writer Mode 1
  (regex explicit) match → INSERT 1 row tier=`explicit` type=`identity`.
- Các phát biểu khác (vd `"mình ko"` ngắn dưới gate 80 chars) bị Mode 2 LLM
  extractor skip — gate `cost_guard::can_extract` + `prompt_length >= 80`.
- Multi-turn pref learning chưa trigger vì conversation chưa ≥ 2 turn dài.
- 2 rows = đúng kỳ vọng cho user mới (1 identity explicit + 1 LLM-extracted).

### 1.2 Quy trình "user yêu cầu ghi nhớ" — trace end-to-end

```
[FE] user prompt "hãy nhớ tôi tên Johnny"
      │
      ▼
[REST] POST /bizcity-twinbrain/v1/twin/stream  (notebook chat) hoặc
       POST /bizcity-twinbrain/v1/brain/turn   (master Ask Brain)
      │
      ▼
[Pipeline] class-twinbrain-runtime.php complete_turn_stream()
      │
      ├── Layer 0.5  Memory_Recall::collect($user_id, $prompt)
      │     │
      │     ├── A: SELECT * FROM bizcity_memory_users WHERE tier='explicit' (cap 20)
      │     ├── B: SELECT * FROM bizcity_memory_users WHERE tier='extracted'
      │     │      ORDER BY (score*0.6 + keyword_overlap*0.4) DESC (cap 12)
      │     ├── C: SELECT * FROM bizcity_memory_episodic
      │     │      WHERE event_type IN ('goal_success','pref_change','pain_point')
      │     │      ORDER BY importance DESC (cap 5)
      │     └── D: SELECT * FROM bizcity_memory_rolling
      │            WHERE status='active' AND conversation_id=current (cap 5)
      │
      ▼ injects { block, citations[], counts:{A,B,C,D} } vào system_prompt
      │
      … (Layer 1-4.5: guru / selector / perspective / synth / final compose) …
      │
      ▼
[Layer 4.7]  Memory_Writer::extract_and_persist($trace_id, $prompt, $answer)
      │
      ├── Mode 1: regex "hãy nhớ|ghi nhớ|remember" match
      │     → BizCity_User_Memory::upsert_public(['tier'=>'explicit', 'score'=>80])
      │
      ├── Mode 2: LLM extractor (nano model, gated cost_guard)
      │     skip nếu turn<2 OR prompt<80 chars
      │     → upsert nhiều row tier='extracted' score 55-70
      │
      └── Mode 3: function-call tools (DEFERRED — chưa register skills)
                  memory_remember(type,key,text), memory_forget(id)
      │
      ▼ INSERT/UPDATE bizcity_memory_users
      ▼ emit SSE 'memory_write' { persisted:N, mode, ops, latency_ms }
      │
      ▼
[FE] AskBrainPanel + useTwinChatStream consume 'memory_write'
      → toast violet "Đã nhớ: N điều"
      → BrainMemoryButton badge bump +N
      → MemoryHubDrawer (nếu open) refetch list
```

### 1.3 Vì sao thấy 8 bảng nhưng đa số trống?

- `_research`: dead (migration artifact từ `webchat_research_jobs`).
- `_session`: chỉ webchat ghi (anonymous visitor session) — admin login
  thường không tạo row.
- `_notes`: chỉ tạo row khi user lưu notebook note thủ công.
- `_specs` + `_logs`: chỉ tạo khi có project + step pipeline (Phase 1.15
  workflow Markdown brief) — chưa có project thật → trống.
- `_episodic` + `_rolling`: chỉ tạo khi hook `bizcity_intent_processed`
  fire → cần Intent Engine chạy đầy đủ → chat fixture đơn giản chưa trigger.

---

## 2 · Kiến trúc hiện hành — Context Bank memory spine

The former 3-table SQL consolidation proposal is superseded. All
`bizcity_memory_*` payloads and the legacy unified `bizcity_memory` table are
retirement targets, not the new source of truth.

| Family | Context Bank role | Payload source | SQL disposition |
|---|---|---|---|
| user / episodic / rolling / session / notes | `memory` | Registered encrypted JSONL/business filestore | No new SQL payload writes; retain legacy rows as migration debt |
| specs | `rule`/governed reference | Canonical Skill/TwinNote/policy owner | Context Bank stores reference metadata only |
| logs | operational evidence | R-LOG-HYBRID JSONL logger | Retire SQL projection; Context Bank may keep provenance reference |
| research | `retire_only` | No automatic backfill | No new SQL writes or speculative migration |
| unified `bizcity_memory` | no independent payload role | Context Bank references canonical records | Do not create or expand as SQL payload warehouse |

Required path:

```text
tenant + verified identity resolved
  -> validated Context Bank memory/rule envelope
  -> encrypted JSONL/business filestore
  -> bizcity_context_bank pointer/correlation ledger
  -> bounded rollup/reference/retrieval pack
```

The Context Bank ledger stores only `record_id`, contract/version, tenant and
identity dimensions, scope, provenance, hashes, status and verified file
pointer metadata. It never stores memory text, decrypted payload, embedding or
copied JSON. `bizcity_memory_*` SQL rows remain protected migration debt until
the lifecycle owner approves zero-row cleanup.

## 3 · Context Bank migration roadmap

| Sprint | Task | Status |
|---|---|---|
| CB-M1 | Inventory every memory family and classify payload, owner, scope, retention and rollback owner. | ✅ Catalog decision recorded 2026-09-01 |
| CB-M2 | Register encrypted filestore contracts and Context Bank memory/rule reference schemas. | ⏳ Pending runtime implementation |
| CB-M3 | Add durable file receipt and tenant pointer/correlation ledger through R-DCL/R-CR/Site Provisioner. | ⏳ Blocked by Context Bank Phase CB2/CB3 |
| CB-M4 | Cut producers over to Context Bank admission; reject new SQL memory payload writes. | ⏳ Pending producer adapters |
| CB-M5 | Build identity/entity rollups and bounded retrieval with KG provenance. | ⏳ Pending Context Bank rollup/search |
| CB-M6 | Cut REST/TwinBrain/TwinChat readers to authorized Context Bank references and payload follows. | ⏳ Pending reader contract |
| CB-M7 | Retire each legacy SQL memory table only after pointer parity, observation, zero-row and owner approval gates. | 🔒 Blocked until CB-M2 through CB-M6 |

### 3.1 Acceptance final

- [ ] Every memory payload has a registered filestore contract and verified
  Context Bank pointer receipt.
- [ ] No new canonical write targets any `bizcity_memory_*` SQL table or
  `bizcity_memory`.
- [ ] Context Bank retrieval preserves tenant, verified identity, goal/case,
  notebook and group-chat isolation.
- [ ] Tombstone/delete invalidates Context Bank references and KG provenance.
- [ ] Bounded pointer-follow and rollup probes pass on current and target
  shard before legacy cleanup.
- [ ] Each legacy table has owner approval and a fresh zero-row check before
  controlled cleanup; no bulk DROP or automatic uninstall cleanup.

---

## 4 · Anti-patterns CẤM trong phase này

- ❌ Ghi memory payload mới vào bất kỳ `bizcity_memory_*` SQL table nào hoặc
  tạo lại unified `bizcity_memory` warehouse.
- ❌ Ghi memory body, decrypted text, embedding hoặc copied JSON vào
  `bizcity_context_bank`; ledger chỉ giữ pointer/correlation metadata.
- ❌ Cho browser, TwinChat hoặc KG-Hub đọc trực tiếp JSONL/path; mọi đọc phải
  qua Context Bank API với tenant/identity/capability validation.
- ❌ Drop legacy SQL rows trước khi có filestore receipt, pointer parity,
  observation, zero-row check và owner approval.
- ❌ Bỏ `bizcity_memory_specs` hoặc `_logs` vào Context Bank payload nếu chưa
  có owner contract; chúng cần được phân loại `rule` hoặc operational evidence.

---

## 5 · Câu hỏi mở (cần owner quyết)

1. Context Bank contract nào admit `bizcity_memory_session`, hay policy sẽ
  reject anonymous session records sau TTL?
2. Notes được admit như `memory` hay `rule` reference theo notebook/owner
  scope nào?
3. Context Bank rollup nào được phép promote memory facts sang KG-Hub, với
  provenance và correction policy ra sao?
4. Network owner nào chịu trách nhiệm zero-row/approval cleanup cho các SQL
  memory tables legacy?

---

## 6 · Reference

- BE: [core/twinbrain/includes/class-twinbrain-memory-recall.php](../twinbrain/includes/class-twinbrain-memory-recall.php)
- BE: [core/twinbrain/includes/class-twinbrain-memory-writer.php](../twinbrain/includes/class-twinbrain-memory-writer.php)
- BE: [core/twinbrain/includes/class-twinbrain-rest-memory-me.php](../twinbrain/includes/class-twinbrain-rest-memory-me.php)
- BE: [core/knowledge/includes/class-user-memory.php](../knowledge/includes/class-user-memory.php)
- BE: [core/intent/includes/conversation/class-episodic-memory.php](../intent/includes/conversation/class-episodic-memory.php)
- BE: [core/intent/includes/conversation/class-rolling-memory.php](../intent/includes/conversation/class-rolling-memory.php)
- BE: [core/memory/includes/class-memory-database.php](includes/class-memory-database.php)
- BE: [modules/webchat/includes/class-webchat-memory.php](../../modules/webchat/includes/class-webchat-memory.php)
- FE: [modules/twinchat/ui/src/stores/memoryBrainStore.ts](../../modules/twinchat/ui/src/stores/memoryBrainStore.ts)
- FE: [modules/twinchat/ui/src/components/MemoryHubDrawer.tsx](../../modules/twinchat/ui/src/components/MemoryHubDrawer.tsx)
- R-DCL: [core/diagnostics/changelog/core.knowledge.memory.json](../diagnostics/changelog/core.knowledge.memory.json)
- Phase doc: [PHASE-0.36-TWINBRAIN-UNIFIED.md §2.8](../../PHASE-0.36-TWINBRAIN-UNIFIED.md)
