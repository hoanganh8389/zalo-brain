# PHASE-0.148 · 20 — Thiết kế Vault ở `core/skills`: Vault + Journal Index tự đứng

<!-- [2026-11-12 08:38 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.148 W0 — thiết kế, chưa mã. -->

> ⚠ **Vòng 1 (2026-11-12 08:58 PM) thắng khi mâu thuẫn:** Vault = `core/skills` (thư viện Skill cũ), không tạo thư mục mới — xem [50-OWNER-ROUND-1](50-OWNER-ROUND-1-VAULT-IS-CORE-SKILLS.md) + truy vết [15](15-DOC-TRACE-0120-0147.md). Đường dẫn trong file này đã đổi `core/vault` ⇒ `core/skills`.
> ⚠ **Vòng 2 (2026-11-12 09:17 PM) thắng vòng 1:** ba module lõi **`core/vault`** (đổi tên từ `core/skills`) · **`core/journal`** · **`core/sheet`**; không gộp Sổ cái; giữ `bizcity/skill/v1` — xem [60-OWNER-ROUND-2](60-OWNER-ROUND-2-VAULT-JOURNAL-SHEET.md). Đường dẫn `core/skills/includes/journal|twin-sheet` trong file này ⇒ đọc là `core/journal` / `core/sheet`.

## 0. Golden của lõi (phải chạy khi **không** có notebook)

1. Chủ tải tài liệu: gửi file qua Zalo **hoặc** kéo vào trang 📚 Kho tri thức.
2. Vault lưu bản markdown (`vault://TL-n/v1/r1`) + **một dòng journal** (sổ cái) — có `find_text`, `folder`, entry-meta.
3. Chủ (hoặc Guru Studio) gắn thư mục cho Guru.
4. Khách hỏi qua Zalo ⇒ cell `journal.find` (theo thư mục Guru) ⇒ `vault.get` / `knowledge.search` ⇒ trả lời có trích dẫn. Không có lớp `BizCity_KG_*` nào được nạp.

## 1. Cây thư mục

```
core/skills/                 (ĐÃ CÓ — nhà của Vault: 📚 Kho tri thức, SPA app/src/vault, uploads/bizcity-skills)
├── bootstrap.php            SỬA: nạp thêm các nhóm dưới; define bí danh BIZCITY_VAULT_DIR = BIZCITY_SKILLS_DIR, BIZCITY_VAULT_LOADED; lật ghi chú 0.101 "journal NEVER loaded / No DB"
├── app/ · assets/dist/      ĐÃ CÓ — SPA Kho tri thức; thêm màn Sổ cái · Brain map · Twin Sheet (D148-7 v2)
├── includes/                ĐÃ CÓ: skill-manager, skills-service, skill-rest-api, skills-mcp-tools, skills-pack-exporter, admin-page, samples
│   ├── journal/             24 class-journal-*.php + journal-api.php            (từ core/kg-hub/includes/)
│   ├── vault/               class-twin-vault, brain-vault-store, vault-upload, vault-tags, scope-meter, kg-private-file-store (D148-14)
│   ├── access/              class-brain-access, brain-resource-link
│   ├── context/             class-context-producers*.php (runtime/class-zalo-brain.php cần)
│   ├── twin-sheet/          9 file (từ core/kg-hub/includes/twin-sheet/)
│   ├── search/              class-vault-knowledge-search.php   (MỚI — W2, §3)
│   └── port/                class-notebook-port.php            (MỚI — W2, §4)
└── contracts/               BRAIN-ACCESS, BRAIN-LEDGER, BRAIN-MAP, DOC-REGISTER, ENTRY-META, JOURNAL-*, TWIN-SHEET*, TWIN-VAULT, VAULT-APPEND
```

Thứ tự nạp trong file chính: `core/skills` ⇒ `core/mcp` ⇒ (`core/guru` …) ⇒ notebook (plugin, `plugins_loaded` ưu tiên 7). Vault không được `require` lớp KG nào ở bước nạp.

### Việc cần kiểm khi dời (W1)
- Mỗi file dời: tìm `BIZCITY_KG_HUB_DIR`, `BIZCITY_KG_HUB_INCLUDES`, `__DIR__ . '/../'`, `dirname(__FILE__)` ⇒ đổi sang `BIZCITY_VAULT_DIR`. (Memory: "No hard-coded plugin folder".)
- `class-journal-pipeline.php`: tách `notebook.study` + `/notebooks/from-refs` ra khỏi lớp (ở lại kg-hub, sau đi notebook); `vault.status`, `/journal/pipeline`, `/journal/build-trace` ở lõi.
- `class-journal-learning-sync.php` + `/journal/{id}/learn`: ở lại kg-hub (đi notebook) — đây là chiều Journal ⇒ KG.
- `class-journal-brain-map.php::note_search()` đang được `knowledge_search_counted` gọi có guard `class_exists` ⇒ giữ.
- `bizcity_vault_folders` và các option khác: **không đổi tên**, không migration.
- Hợp đồng dời ⇒ cập nhật `docs/contracts/CONTRACT-INDEX-v1.md` (đường dẫn mới).

## 2. Nạp và sống còn

| Hiện nay | Sau W1 |
|---|---|
| Vault server + Journal chỉ có khi `core/guru/profile/bootstrap.php` chạy; SPA Vault (`core/skills`) nạp riêng ở `bizcity-twin-ai.php:802` | một module: `core/skills/bootstrap.php` nạp server + SPA, **cùng cổng `core/mcp`** (`:739` — admin / REST / cron) và trước nó; không phụ thuộc Guru profile |
| Thiếu kg-hub ⇒ Vault + Journal mất theo | Thiếu notebook ⇒ chỉ mất KG; Vault + Journal nguyên vẹn |

Kiểm hiệu năng (R-MEMORY-5-95 ở site): đo số file nạp trên một request front trước/sau W1; mục tiêu ≤ +2 file (bootstrap + autoloader map).

## 3. `knowledge.search` không cần KG (D148-4)

Hiện `knowledge_search_counted` (`class-mcp-tool-registry.php:183`): `BizCity_Brain_MCP_Service::search()` (KG retriever) + `Twin_Vault::public_passages()` **chỉ** cho `guru_public`.

Sau W2 (`core/skills/includes/search/class-vault-knowledge-search.php`):

```
knowledge_search_counted(args, ctx)
  scope   = thư mục của Guru / principal (Twin_Vault::folders_for_guru hoặc public_folders_for_guru) — R-SCOPE-FOLDER, cap 12
  rows    = Journal_Index::find(query, folders=scope, kinds=document|note|sheet, limit)      ← Journal Index = cửa (R-JN-28)
  vault   = với mỗi row: Brain_Vault_Store đọc đoạn markdown khớp ⇒ passage {entry_id, vault_ref, folder, text, cite:"[TL-n]"}
  extra   = apply_filters('bizcity_vault_knowledge_providers', [], args, ctx)               ← notebook thêm passage KG khi bật
  result  = { retrieval_snapshot_id, passages: vault + extra, allowed_citations, vault_passages (giữ khoá cũ cho cell) }
  Brain_Map::note_search(args, ctx, result)
```

- Giữ khoá `vault_passages` + `passages` như cũ ⇒ cell (`retrieve.ts`, `notebook-search-tool.ts`) không đổi.
- `retrieval_snapshot_id`: snapshot store (`class-mcp-retrieval-snapshot-store.php`) hiện dựa KG ⇒ lõi tạo snapshot rỗng `vs:` + danh sách entry_id; khi notebook bật dùng store KG như cũ.
- Không gọi LLM, không regex định tuyến (feedback "limit regex").

## 4. Cổng `BizCity_Notebook_Port` (D148-5)

Một lớp tĩnh ở lõi, mặc định "không có notebook". Plugin notebook gọi `BizCity_Notebook_Port::register( $impl )` khi nạp.

| Hàm | Mặc định (không notebook) | Ai dùng (10 §4) |
|---|---|---|
| `available(): bool` | `false` | mọi chỗ K |
| `search(args, ctx): array` | `[]` | brain-mcp-service, guru-notebook-blocks, context-bank |
| `notebooks_for_guru(guru_id): int[]` | `[]` | guru-context-resolver, client-scope-resolver |
| `notebook_for_number(account_id): int` | `0` | zalo-personal, web-post-publisher |
| `private_file_store(): ?object` | lưu trữ file của Vault (`Brain_Vault_Store`) | panel-rest 842, twin-cell-output-store 37 |
| `cost_guard_check(feature, user): bool` | `true` | bizcity-llm client 458 |
| `validate_citations(json): array` | chỉ kiểm `[TL-n]` của Vault | tool-registry 1236, document-mcp-service 216 |
| `stats(): array` | `{notebooks:0}` | knowledge-stats-mcp-service |
| `graph_ask(args, ctx): array` | `{ok:false, code:'not_available'}` | `brain.graph_ask` |

⚠ `KG_Private_File_Store` đang được Vault/panel dùng như kho file riêng. W2 phải xác minh nó có phải **kho của Vault** thật không (nếu đúng ⇒ dời vào `core/skills`, không qua cổng). Ghi vào S148-C6.

## 5. Brain map + sổ cái đi theo Vault (D148-7)

- API: `/journal/map`, `/journal/maturity`, `BRAIN-LEDGER-v1` (sheet_key `brain.ledger`, twin-sheet@1) ⇒ ở `core/skills` từ W1.
- Màn: chuyển `BrainMapView.tsx`, `BrainMaturity.tsx`, `BrainLedger.tsx`, `brain-map-core.js` từ `modules/twinchat/ui` sang `core/skills/app/src/vault/` (trang 📚 Kho tri thức), build vào `core/skills/assets/dist/skill-app.js`. TwinChat (ở notebook) gọi lại cùng API.
- Brain map khi không có notebook: chỉ lớp Vault (thư mục ⇒ tài liệu ⇒ phiên bản ⇒ lượt dùng); lớp entity/relation chỉ hiện khi `available()`.
- `bizcity_twin_agent_packs_changed`: Vault bắn khi `update_folder`, `write`, `append`, `move`, upload xong (người nghe ở crm / mcp / zalo-pack đã có sẵn).

## 6. Upload một cửa (D148-6)

| Đường | Hiện nay | Sau W2 |
|---|---|---|
| Trang admin | `POST bizcity-vault/v1/upload` (`BizCity_Vault_Upload`) ⇒ Vault + journal | giữ |
| Zalo (cá nhân) | `class-zalo-personal-knowledge-capture.php`: Journal_Filer/Index **và** `KG_Channel_Notebook_Bridge` + `Progress_Notifier` + `Private_File_Store` | chỉ gọi `BizCity_Vault_Upload::ingest_bytes()` (hàm dùng chung mới, cùng lõi với REST) ⇒ Vault + journal; thông báo tiến độ dùng tin Zalo thường |
| Zalo bot | `class-notebook-bridge-listener.php` + `class-upload-link-handler.php` (KG) | như Zalo cá nhân; link tải lên ⇒ trang Vault |
| Notebook bật | — | nghe `bizcity_journal_entry_added` ⇒ học thêm vào notebook (triplet, embedding). Không chặn trả lời. |
