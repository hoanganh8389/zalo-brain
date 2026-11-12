# PHASE-0.148 · 60 — Vòng 2 của chủ: ba module `core/vault` · `core/journal` · `core/sheet`

<!-- [2026-11-12 09:17 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.148 vòng 2 — chủ trả lời Q148-9…11 + chỉ đạo tách Journal / Sheet. ⚠ File này thắng doc 00 / 20 / 30 / 50 / 95 khi mâu thuẫn. -->

## 0. Lời chủ
> *"rename core/vault ok. tách thành core/journal cho rõ ràng. ko nên merge. Có 2 hợp đồng lớn là sheet và journal, tách. not drop the old bizcity/skill/v1"*

| Câu | Trả lời | Ghi |
|---|---|---|
| Q148-9 đổi tên `core/skills` ⇒ `core/vault`? | **Đồng ý** | D148-15 |
| Q148-10 gộp hai Sổ cái? | **Không gộp** | D148-17. Hiểu "ko nên merge" theo hai nghĩa cùng lúc: Journal **không** nhập vào Vault, và hai màn Sổ cái **không** gộp. Chủ sửa nếu sai |
| (mới) Journal | **Module riêng `core/journal`** | D148-16 |
| (mới) Sheet | **Module riêng `core/sheet`** — hai hợp đồng lớn `journal-entry@1` và `twin-sheet@1` tách nhau | D148-16 |
| Q148-11 bỏ `bizcity/skill/v1`? | **Không bỏ** | D148-18 |

## 1. Quyết định vòng 2

| # | Quyết định |
|---|---|
| **D148-15** | Đổi tên thư mục `core/skills` ⇒ **`core/vault`** (một commit `git mv` riêng, không sửa nội dung). Giữ cho tương thích: hằng `BIZCITY_SKILLS_DIR` / `BIZCITY_SKILLS_VERSION` (define bằng giá trị mới), slug trang `bizcity-skills`, gốc tệp `uploads/bizcity-skills` (D134-2, D148-12), REST `bizcity/skill/v1`. Thêm `BIZCITY_VAULT_DIR`, `BIZCITY_VAULT_LOADED`. Sửa đường dẫn: `bizcity-twin-ai.php:802-803`, `core/helper/bootstrap.php:262,386` (`owner_module` ⇒ `core/vault`), changelog `core/helper/schema/changelog/core.skills.json` ⇒ `core.vault.json` (giữ bản cũ làm bí danh một đợt). Lật D148-2a (giữ tên) |
| **D148-16** | **Ba module lõi, mỗi module một hợp đồng chính** (thay D148-2 v2 — không dồn Journal / Sheet vào Vault): xem §2. |
| **D148-17** | Không gộp Sổ cái. **Sổ cái đầy đủ** (BrainLedger: lọc, xuất, phiên bản, xoá thật) là màn của `core/journal`; **tab Sổ cái gọn** trong 📚 Kho tri thức (D137-11) giữ là một *góc nhìn* của Vault gọi cùng API `journal/find`. Cả hai ở lõi; TwinChat (plugin) chỉ mở link. Lật phần "gộp" của D148-7 v2 |
| **D148-18** | Giữ `bizcity/skill/v1` (D134-3) — không có lịch bỏ trong 0.148 |
| **D148-19** | Thứ tự phụ thuộc một chiều: **`core/journal`** (không phụ thuộc ai) ⇒ **`core/sheet`** (một dòng sheet = một dòng journal, D123-1) ⇒ **`core/vault`** (ghi note mở dòng journal, D134-6; đọc sheet làm ngăn, D131-10) ⇒ `core/mcp`. Journal **không** được gọi Vault / Sheet (chỉ hook). Selfcheck kiểm chiều này |

## 2. Ba module

```
core/journal/                  hợp đồng chính: journal-entry@1.2 · journal-kinds@1.3
├── bootstrap.php              define BIZCITY_JOURNAL_DIR, BIZCITY_JOURNAL_LOADED; schema_owner core.journal (bảng bizcity_journal_entries)
├── includes/                  24 class-journal-* (trừ learning-sync ⇒ notebook) · journal-api.php (bizcity_journal_record)
│                              · class-brain-access · class-brain-resource-link · class-context-producers*
│                              · class-journal-brain-map (API /journal/map, /journal/maturity — màn hiển thị ở Vault)
├── admin/ (W2)                màn Sổ cái đầy đủ (BrainLedger) — D148-17
└── contracts/                 JOURNAL-ENTRY · JOURNAL-KINDS · JOURNAL-PIPELINE · ENTRY-META · EVIDENCE · JOB-LINK · BRAIN-ACCESS · BRAIN-RESOURCE-LINK · BRAIN-MAP · DOC-REGISTER

core/sheet/                    hợp đồng chính: twin-sheet@1
├── bootstrap.php              define BIZCITY_SHEET_DIR, BIZCITY_SHEET_LOADED; CPT twin_sheet
├── includes/                  9 file từ core/kg-hub/includes/twin-sheet/ (Twin_Sheet_API, _Service, _Journal_Store, _Cpt_Defs …)
└── contracts/                 TWIN-SHEET · TWIN-SHEET-BUILTIN · TWIN-SHEET-TEMPLATES · BRAIN-LEDGER (sheet_key brain.ledger — một sheet dựng trên journal)

core/vault/                    (đổi tên từ core/skills) hợp đồng chính: twin-vault@1 · vault-append@1
├── bootstrap.php              như core/skills hiện nay + nạp nhóm vault/ ; define BIZCITY_VAULT_* + bí danh BIZCITY_SKILLS_*
├── app/ · assets/dist/        SPA 📚 Kho tri thức (đã có) + màn Brain map, tab Sổ cái gọn, Hồ sơ tệp, tab Vault pool
├── includes/                  đã có: skill-manager, skills-service, skill-rest-api, skills-mcp-tools, skills-pack-exporter, admin-page, samples
│   └── vault/                 class-twin-vault · brain-vault-store · vault-upload · vault-tags · scope-meter · kg-private-file-store (D148-14)
│                              · class-vault-knowledge-search (W2) · class-notebook-port (W2)
├── samples/                   đã có
└── contracts/                 TWIN-VAULT · VAULT-APPEND
```

MCP vẫn ở `core/mcp`: `journal.*` ⇐ core/journal · `twinsheet.*` ⇐ core/sheet · `vault.*`, `knowledge.search` ⇐ core/vault. Tên tool, route, bảng, option **không đổi** (D148-3). `NOTEBOOK-GRAPH-ASK` ⇒ plugin notebook.

Nạp trong `bizcity-twin-ai.php`: ba bootstrap liền nhau, cùng cổng `core/mcp` (`:739`), theo thứ tự D148-19, **trước** `core/mcp`; bỏ phụ thuộc `core/guru/profile/bootstrap.php`.

## 3. Đợt W1 viết lại

| Bước | Việc | Commit |
|---|---|---|
| W1a | `git mv core/skills core/vault` + sửa 3 chỗ đường dẫn + hằng bí danh | 2 commit (dời · sửa) |
| W1b | tạo `core/journal/`, `git mv` journal + access + context + brain-map + hợp đồng từ kg-hub | 2 commit |
| W1c | tạo `core/sheet/`, `git mv` twin-sheet + hợp đồng | 2 commit |
| W1d | `git mv` nhóm vault từ kg-hub ⇒ `core/vault/includes/vault/`; kg-hub bootstrap bỏ mọi file đã dời; `--check=W1` xanh; T-GOLD-1 | 2 commit |

## 4. Còn chờ chủ
Q148-1…8 (doc 00 §4). Q148-9…11 đã trả lời ở trên.
