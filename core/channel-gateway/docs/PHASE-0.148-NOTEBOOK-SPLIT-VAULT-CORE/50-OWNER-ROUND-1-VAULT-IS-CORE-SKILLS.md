# PHASE-0.148 · 50 — Vòng 1 của chủ: Vault = `core/skills`

<!-- [2026-11-12 08:58 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.148 vòng 1 — chủ 12/11 08:5x PM: "vault đang để ở core/skill, tên gọi khác của skill cũ. Trace lại tài liệu từ 0.120 -> 0.148". ⚠ File này thắng doc 00 / 20 / 30 / 95 khi mâu thuẫn. -->

> ⚠ **Vòng 2 thắng file này** ([60](60-OWNER-ROUND-2-VAULT-JOURNAL-SHEET.md)): D148-2 v2 / 2a / 7 v2 (phần "gộp") bị thay bởi D148-15…19 — `core/skills` đổi tên `core/vault`; Journal ⇒ `core/journal`, Twin Sheet ⇒ `core/sheet`; không gộp Sổ cái; giữ `bizcity/skill/v1`. Q148-9…11 đã trả lời.

## 0. Lời chủ
> *"Vault đang để ở core/skill, tên gọi khác của skill cũ."*

Truy vết đủ ở [15-DOC-TRACE-0120-0147](15-DOC-TRACE-0120-0147.md). Mã xác nhận: trang **📚 Kho tri thức** = `core/skills/includes/class-admin-page.php:69` (slug `bizcity-skills`), SPA `core/skills/app/src/vault/*`, thư viện `uploads/bizcity-skills/{blog}/` = namespace `skills` của Vault Store (`class-skill-manager.php:45`, 0.133 VA-1). Chỉ **phần server** (Vault + Journal + Brain map + Twin Sheet) bị đặt ở `core/kg-hub`.

## 1. Quyết định sửa (thay bản cũ)

| # | Bản cũ (doc 00/20) | Bản vòng 1 |
|---|---|---|
| **D148-2 v2** | tạo `core/vault/` mới | **Không có thư mục mới.** Nhà của Vault là **`core/skills`**. Dời vào: `core/skills/includes/vault/` (twin-vault, brain-vault-store, vault-upload, vault-tags, scope-meter, **kg-private-file-store**), `includes/journal/` (24 `class-journal-*` trừ learning-sync, `journal-api.php`), `includes/access/` (brain-access, brain-resource-link), `includes/context/` (context-producers*), `includes/twin-sheet/`, `contracts/` |
| D148-2a | — | Giữ tên thư mục `core/skills`, hằng `BIZCITY_SKILLS_DIR`, slug `bizcity-skills`, gốc `uploads/bizcity-skills` (D134-2: không dời tệp). Thêm hằng bí danh `BIZCITY_VAULT_DIR = BIZCITY_SKILLS_DIR` + `BIZCITY_VAULT_LOADED`. Đổi tên thư mục ⇒ Q148-9 |
| D148-2b | — | **Lật ghi chú 0.101** ở `core/skills/bootstrap.php` ("journal NEVER loaded again", "No DB"): `core/skills` sở hữu bảng `bizcity_journal_entries`; schema_owner `core.knowledge.kg-hub` ⇒ `core.skills` (lật Q133-2). Journal **trở về** nơi nó ở trước WP-12 |
| D148-2c | nạp vô điều kiện | `core/skills/bootstrap.php` nạp **cùng cổng với `core/mcp`** (`bizcity-twin-ai.php:739`, `:802` — admin/REST/cron) và **trước** `core/mcp`; **không còn** phụ thuộc `core/guru/profile/bootstrap.php`. Thêm: cổng `! $_bizcity_twinchat_admin_page` bỏ đi khi TwinChat rời lõi |
| **D148-7 v2** | Brain map sang Kho tri thức | **Một nhà cho mọi màn của Vault** = 📚 Kho tri thức: Sổ cái (gộp hai Sổ cái của D137-11 thành tab `#/vault/ledger`), Brain map + Maturity, Twin Sheet, Hồ sơ tệp, tab Vault pool (D143-11). TwinChat ở plugin chỉ **mở link** `#/vault/...`. Lật Q123-17, D126-3 (sửa), D129-3/4, T134-1 |
| D148-12 | — | Hai gốc lưu `uploads/bizcity-skills` + `uploads/bizcity-vault` / `bizcity_vault_root` **giữ nguyên** (D133-6, D136-15) |
| D148-13 | — | Ngăn notebook trong Vault (D131-1) = **ngăn tuỳ chọn** qua `BizCity_Notebook_Port`; nút "Học sâu" (D137-7) chỉ hiện khi `available()`; `vault.append` từ notebook (D143-8…10) giữ — đó là chiều notebook ⇒ Vault, hợp lệ |
| D148-14 | — | `BizCity_KG_Private_File_Store` là kho tệp của Vault (0.145) ⇒ dời về `core/skills/includes/vault/`, **giữ tên lớp** (D148-3). Đóng S148-C6 |

Không đổi: D148-1 (ba đường ranh — chỉ thay "`core/vault`" bằng "`core/skills` (Vault)"), D148-3…6, D148-8…11.

## 2. Ngân sách
0.134 §1 #7: ngân sách PHP site khoá 13.44 MB. Dời file **không thêm byte**; thêm chỉ `class-vault-knowledge-search.php` + `class-notebook-port.php` (ước ≤ 15 KB). Tách KG + TwinChat ra plugin **giảm** lõi (đo ở W4, `lean-scoreboard --check`).

## 3. Câu hỏi mới
| # | Câu hỏi | Gợi ý (1 = đề xuất) |
|---|---|---|
| Q148-9 | Đổi tên thư mục `core/skills` ⇒ `core/vault`? | **1. Không — giữ `core/skills`, thêm hằng bí danh** (không phá đường dẫn, slug, gốc tệp, deploy list cũ) · 2. đổi tên ở một đợt riêng sau W4 |
| Q148-10 | Gộp hai "Sổ cái" (tab Vault + sổ đầy đủ TwinChat, D137-11) thành một ở Kho tri thức? | **1. Gộp** — một sổ, bộ lọc đầy đủ · 2. giữ tab gọn ở Kho + sổ đầy đủ ở plugin |
| Q148-11 | REST cũ `bizcity/skill/v1` (giữ ≥ 2 đợt theo D134-3) — bỏ ở 0.148? | **1. Chưa bỏ** — chỉ ghi lịch bỏ sau W4 · 2. bỏ ở W4 |
