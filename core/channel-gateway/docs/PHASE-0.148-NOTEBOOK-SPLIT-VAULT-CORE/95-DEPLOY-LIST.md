# PHASE-0.148 · 95 — Danh sách deploy

<!-- [2026-11-12 08:38 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.148 W0 — chưa có gì để deploy; danh sách theo đợt để lúc code điền. -->

> ⚠ **Vòng 1 (2026-11-12 08:58 PM) thắng khi mâu thuẫn:** Vault = `core/skills` (thư viện Skill cũ), không tạo thư mục mới — xem [50-OWNER-ROUND-1](50-OWNER-ROUND-1-VAULT-IS-CORE-SKILLS.md) + truy vết [15](15-DOC-TRACE-0120-0147.md). Đường dẫn trong file này đã đổi `core/vault` ⇒ `core/skills`.
> ⚠ **Vòng 2 (2026-11-12 09:17 PM) thắng vòng 1:** ba module lõi **`core/vault`** (đổi tên từ `core/skills`) · **`core/journal`** · **`core/sheet`**; không gộp Sổ cái; giữ `bizcity/skill/v1` — xem [60-OWNER-ROUND-2](60-OWNER-ROUND-2-VAULT-JOURNAL-SHEET.md). Đường dẫn `core/skills/includes/journal|twin-sheet` trong file này ⇒ đọc là `core/journal` / `core/sheet`.

Thứ tự mọi đợt: **Site (lõi) ⇒ plugin notebook**. Hub và cell **không đổi**. Mỗi đợt deploy riêng, chạy T148-6 (T-GOLD-1) xong mới sang đợt sau.

## W1 — lõi
- **Mới trong `core/skills/`:** `includes/{vault,journal,access,context,twin-sheet}/` + `contracts/` (file dời từ kg-hub); **sửa** `core/skills/bootstrap.php` (nạp thêm).
- **Sửa:** `bizcity-twin-ai.php` (nạp `core/skills/bootstrap.php` trước `core/mcp`) · `core/kg-hub/bootstrap.php` (bỏ file đã dời) · `core/mcp/bootstrap.php` nếu đổi thứ tự.
- **Xoá trên VPS sau khi chạy ổn:** các file đã dời trong `core/kg-hub/includes/` (bản lõi mới không còn require; để lại cũng vô hại nhờ guard `class_exists`).
- Lùi: upload lại `bizcity-twin-ai.php` + `core/kg-hub/bootstrap.php` bản cũ.

## W2 — lõi + plugins kênh
- `core/skills/includes/{search,port}/*` (mới) · `core/mcp/includes/class-mcp-tool-registry.php` · các file K/T ở 10 §4 · `plugins/bizcity-zalo-personal/includes/shared/class-zalo-personal-knowledge-capture.php` · `plugins/bizcity-zalo-bot/…/class-notebook-bridge-listener.php`, `class-upload-link-handler.php`.
- UI (nếu Q148-5 = 1): `core/skills/assets/dist/*` (build từ `core/skills/app/src/vault/`).
- Lùi: filter `bizcity_vault_knowledge_providers` vẫn có KG khi kg-hub còn trong lõi ⇒ hành vi cũ.

## W3 — plugin mới
- Upload cả thư mục `wp-content/plugins/bizcity-twin-notebook/` (**không** `ui/src`, `node_modules`, `tests`, `docs`).
- Copy tay file chưa track git: `twinchat/includes/class-twinchat-vault-pool-controller.php`, `twinchat/includes/learning/class-twinchat-welcome-listener.php` (diagnostics giữ local, R-DIAG-LOCAL).
- Bật plugin trên site; `BIZCITY_NOTEBOOK_LOAD` để `auto`.
- Lùi: tắt plugin ⇒ `auto` nạp bản cũ trong lõi.

## W4 — gỡ lõi
- Xoá `core/kg-hub` phần còn lại, `modules/twinchat`, `modules/twinkg` khỏi lõi; bỏ cron + require tương ứng trong `bizcity-twin-ai.php`.
- Chỉ làm khi mọi site cần notebook đã cài plugin (Hub ▸ danh sách site).
- Lùi: cài lại gói lõi bản W3.

## Sau deploy (cấu hình)
1. Không có. Tên bảng / option / route giữ nguyên ⇒ không migration.
2. Site không mua Notebook: để plugin tắt — Vault vẫn đủ trả lời.
