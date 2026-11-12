# PHASE-0.148 · Lane CL (site lõi) + NB (plugin notebook) + UI + BC

<!-- [2026-11-12 08:38 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.148 W0. Trạng thái chỉ ở 40-MASTER-CHECKLIST. -->

> ⚠ **Vòng 1 (2026-11-12 08:58 PM) thắng khi mâu thuẫn:** Vault = `core/skills` (thư viện Skill cũ), không tạo thư mục mới — xem [50-OWNER-ROUND-1](../50-OWNER-ROUND-1-VAULT-IS-CORE-SKILLS.md) + truy vết [15](../15-DOC-TRACE-0120-0147.md). Đường dẫn trong file này đã đổi `core/vault` ⇒ `core/skills`.
> ⚠ **Vòng 2 (2026-11-12 09:17 PM) thắng vòng 1:** ba module lõi **`core/vault`** (đổi tên từ `core/skills`) · **`core/journal`** · **`core/sheet`**; không gộp Sổ cái; giữ `bizcity/skill/v1` — xem [60-OWNER-ROUND-2](../60-OWNER-ROUND-2-VAULT-JOURNAL-SHEET.md). Đường dẫn `core/skills/includes/journal|twin-sheet` trong file này ⇒ đọc là `core/journal` / `core/sheet`.

| Lane | Phạm vi | Thứ tự | ID |
|---|---|---|---|
| CL (site PHP lõi) | Vault ở `core/skills` (D148-2 v2); dời Vault + Journal + Twin Sheet + Brain map; cổng `BizCity_Notebook_Port`; `knowledge.search` từ Vault; ~45 chỗ K/T qua cổng; upload Zalo một cửa | W1 ⇒ W2 ⇒ W4 | S148-C1…C13 |
| NB (plugin `bizcity-twin-notebook`) | khung add-on; chuyển nguyên khối KG + TwinChat + TwinKG; Port_Impl; nghe journal; installer | W3 | S148-N1…N6 |
| UI | Brain map / BrainLedger sang trang 📚 Kho tri thức | W2 | S148-U1 |
| BC (cell) | **không đổi mã**; thêm ca kiểm `brain.graph_ask` = `not_available` | W4 | S148-B1 |
| HB (Hub) | không đổi (chuyển tiếp MCP như cũ) | — | — |

## Quy tắc code
- Dời bằng `git mv` (giữ lịch sử); **không** sửa nội dung trong cùng commit dời — commit dời riêng, commit sửa đường dẫn riêng.
- Không đổi tên lớp / bảng / option / route / tool (D148-3).
- Mỗi file sửa: dấu `[YYYY-MM-DD hh:mm AM/PM Johnny Chu - Chu Hoàng Anh] PHASE-0.148 …` giờ thật (PowerShell `Get-Date`).
- Trước W1: `git status core/kg-hub modules/twinchat` phải sạch (phiên khác đang sửa).
- Không sửa file khi một phiên khác đang chạy bộ test (memory "no edits while peer suite runs").
