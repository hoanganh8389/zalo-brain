# PHASE-0.148 · 30 — Plugin `bizcity-twin-notebook` + họ plugin Zalo Brain

<!-- [2026-11-12 08:38 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.148 W0 — thiết kế, chưa mã, chưa tạo thư mục. -->

> ⚠ **Vòng 1 (2026-11-12 08:58 PM) thắng khi mâu thuẫn:** Vault = `core/skills` (thư viện Skill cũ), không tạo thư mục mới — xem [50-OWNER-ROUND-1](50-OWNER-ROUND-1-VAULT-IS-CORE-SKILLS.md) + truy vết [15](15-DOC-TRACE-0120-0147.md). Đường dẫn trong file này đã đổi `core/vault` ⇒ `core/skills`.
> ⚠ **Vòng 2 (2026-11-12 09:17 PM) thắng vòng 1:** ba module lõi **`core/vault`** (đổi tên từ `core/skills`) · **`core/journal`** · **`core/sheet`**; không gộp Sổ cái; giữ `bizcity/skill/v1` — xem [60-OWNER-ROUND-2](60-OWNER-ROUND-2-VAULT-JOURNAL-SHEET.md). Đường dẫn `core/skills/includes/journal|twin-sheet` trong file này ⇒ đọc là `core/journal` / `core/sheet`.

## 1. Nhiệm vụ một câu
Notebook = **xưởng học nâng cao trên Vault**: chat theo notebook (TwinChat), đồ thị tri thức (entity / relation / triplet), embedding + tìm ngữ nghĩa, học theo lô, đồ thị `brain.graph_ask`. Không có nó, Zalo Brain vẫn trả lời từ Vault.

## 2. Cây thư mục (khuôn 0.102 add-on host + 0.96 CRM)

```
wp-content/plugins/bizcity-twin-notebook/
├── bizcity-twin-notebook.php      header "Zalo Brain Addon: notebook"; không tự nạp gì — host (add-on locator) gọi
├── zalo-brain.json                zalo-brain-extension@1: id notebook, requires.zalo-brain ≥ <bản có core/skills>, features, surfaces, tables_changelog, mcp_tools, licence
├── bootstrap.php                  guard: BIZCITY_VAULT_LOADED + class_exists('BizCity_Notebook_Port') ⇒ nạp kg/ rồi twinchat/; Port::register(new BizCity_Notebook_Port_Impl)
├── kg/                            phần KG của core/kg-hub (10 §1a): includes/, adapters/, backfill/, clients/, filestore/, skeleton/, prompts/, contracts/NOTEBOOK-GRAPH-ASK*, assets/
├── twinchat/                      modules/twinchat nguyên khối (includes/, notebooklm/, ui/dist — KHÔNG đưa ui/src lên repo, R-PROD-REPO)
├── twinkg/                        modules/twinkg (UI đồ thị)
├── graph-ask/                     class-twinbrain-graph-ask.php (Q148-3)
├── includes/
│   ├── class-notebook-port-impl.php
│   ├── class-notebook-journal-listener.php   nghe bizcity_journal_entry_added ⇒ học (D148-6)
│   ├── class-notebook-knowledge-provider.php filter bizcity_vault_knowledge_providers (D148-4)
│   └── class-notebook-installer.php          19 bảng bizcity_kg_* + 3 bảng learning (D148-9)
└── uninstall.php                  KHÔNG xoá bảng trừ khi chủ bật tuỳ chọn "xoá dữ liệu notebook"
```

## 3. Nạp (D148-8)

| `BIZCITY_NOTEBOOK_LOAD` | Hành vi |
|---|---|
| `auto` (mặc định trong W3) | có plugin `bizcity-twin-notebook` đang bật ⇒ nạp từ plugin; không có ⇒ nạp bản cũ trong lõi (`core/kg-hub` phần KG + `modules/twinchat`) — **chỉ trong W3** |
| `active` | chỉ nạp từ plugin; không có plugin ⇒ không có notebook |
| `off` | không nạp notebook ở đâu cả (dùng để chạy golden T148-1) |

Sau W4: bản cũ trong lõi bị xoá, `auto` = `active`. Host định vị plugin qua `includes/class-bizcity-addon-locator.php` (như `bizcity-automation`), không đường dẫn cứng.

Chống nạp đôi: cả bản lõi cũ và plugin đều kiểm `defined('BIZCITY_KG_HUB_DIR')` / `defined('BIZCITY_TWINCHAT_DIR')` trước khi define; bản nào nạp trước thắng, bản sau ghi log một lần.

## 4. Những gì notebook đăng ký vào lõi

| Vào đâu | Cái gì |
|---|---|
| `BizCity_Notebook_Port::register()` | toàn bộ hàm 20 §4 |
| `bizcity_vault_knowledge_providers` | passage KG (retriever + reranker) cho `knowledge.search` |
| `bizcity_mcp_register_tools` | `brain.graph_ask`, `brain.list_notebooks`, `brain.get_passage`, `brain.get_citation_pack`, `brain.context.*`, `notebook.study` (thay bản rỗng của lõi) |
| `bizcity_journal_entry_added` | học tài liệu mới vào notebook gắn số / Guru |
| `zalo_brain_apps` | ô "📓 Notebook" + "💬 Twin Chat" trên trang chủ Channel Gateway |
| Menu | "Twin Chat", "Đồ thị tri thức" dưới menu Zalo Brain |
| Cron | `bizcity_twinchat_learning_15min`, `bizcity_kg_learning_sweep`, `bizcity_kg_orphan_cleanup_weekly` (dời khỏi file chính) |

## 5. Không đi theo notebook
- `BizCity_TwinChat_Settings_Page` ("BizCity API & Gateway") ⇒ `core/bizcity-llm` (Q148-6).
- Màn Brain map / BrainLedger / Vault pool ⇒ trang 📚 Kho tri thức (D148-7). TwinChat dùng lại qua API.
- Bảng `bizcity_journal_entries`, option `bizcity_vault_*` ⇒ lõi.

## 6. Họ plugin Zalo Brain (D148-10)

| # | Plugin | Repo | Lõi giữ gì cho nó | Trạng thái |
|---|---|---|---|---|
| 1 | **CRM** `bizcity-twin-crm` | `zalo-brain-crm` | spine `core/crm` (`BizCity_CRM_Spine`) | có (0.96) |
| 2 | **Notebook** `bizcity-twin-notebook` | `zalo-brain-notebook` (Q148-2) | `core/skills` + `BizCity_Notebook_Port` | phase này |
| 3 | **Bizcoach-pro** `bizcoach-pro` | — | `zalo_brain_apps`, `bizcity_twinbrain_vertical_bridge_registry` | có |
| 4 | **Automation** `bizcity-automation` | `bizcity-automation` | add-on locator, `core/mcp` | có (0.91) |
| … | (TwinWeb, billing, video, voice …) | | | |

Chuẩn chung cho mọi plugin trong họ:
1. `zalo-brain.json` (zalo-brain-extension@1) + guard phiên bản `BIZCITY_TWIN_AI_VERSION`, thông báo admin khi thiếu lõi.
2. Gọi lõi chỉ qua **cổng / filter / MCP** — không `BizCity_*` nội bộ của lõi ngoài danh sách công khai (`docs/developer/STANDARD.md`).
3. Tool AI đi qua cửa `core/mcp` (MCP one standard); không chạm token (R-TOKEN-LEDGER-CELL).
4. Tài liệu đi vào **Vault** (journal row) — plugin không tự giữ sổ tài liệu riêng.
5. Repo GitHub = gói runtime (R-PROD-REPO).

## 7. Rủi ro

| Rủi ro | Giảm |
|---|---|
| TwinChat ↔ kg-hub dính ~290 chỗ, tách nửa vời gãy | chuyển **nguyên khối cùng lúc** ở W3; không sửa bên trong |
| File chưa track git (`vault-pool-controller`, `welcome-listener`, `diagnostics/*`) bị bỏ sót | danh sách copy tay trong 95; selfcheck kiểm có mặt |
| ~20 file kg-hub đang sửa dở (phiên khác) | W1 chỉ bắt đầu khi `git status core/kg-hub` sạch (feedback "parallel sessions") |
| Request front mất Vault vì thứ tự nạp | T148-3 gọi `vault.list` qua MCP khi không đăng nhập admin |
| Site đang chạy có notebook mà plugin chưa cài khi lên W4 | W4 chỉ khi Hub thấy mọi site đã có plugin hoặc chủ chấp nhận mất notebook |
