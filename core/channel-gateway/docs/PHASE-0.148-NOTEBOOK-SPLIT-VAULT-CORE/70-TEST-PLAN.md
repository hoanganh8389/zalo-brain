# PHASE-0.148 · 70 — Kế hoạch kiểm thử

<!-- [2026-11-12 08:38 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.148 W0. -->

## Máy (trên máy dev, R-DIAG-LOCAL — không chạy trên VPS / GitHub)
- Census: `node core/channel-gateway/docs/PHASE-0.148-NOTEBOOK-SPLIT-VAULT-CORE/tools/PHASE-0.148-split-census.selfcheck.mjs` (báo cáo) · `--check=W1` / `--check=W2` / `--check=W4` (đỏ nếu đợt chưa đạt).
- Phpunit site sau W1: toàn bộ test có chữ `Journal`, `Vault`, `BrainAccess`, `EntryMeta`, `TwinSheet`, `McpKnowledgeAlias*`, `GuruContext*` — **số pass/fail phải bằng trước W1** (ghi baseline vào evidence trước khi dời).
- Sau W2: test mới `VaultKnowledgeSearchS148Test` (không có lớp KG nào ⇒ trả passage Vault theo thư mục Guru; cap 12 thư mục; `vault_passages` giữ khoá) · `NotebookPortS148Test` (mặc định rỗng, register thay).
- Cell: `zalo-hub` unit `notebook-graph` với fixture `not_available` ⇒ không `kg_highlight`, không lỗi.
- `node core/channel-gateway/docs/tools/phase-docs-audit.mjs --check` (thư mục phase đủ khuôn).

## Người (site thật, số thật)
| # | Làm | Mong đợi |
|---|---|---|
| T148-1 | `BIZCITY_NOTEBOOK_LOAD=off`. Gửi PDF bảng giá qua Zalo cho số chủ; gắn thư mục cho Guru Đối ngoại; khách hỏi "giá gói X?" | trả lời đúng, có `[TL-n]`; log không có lớp `BizCity_KG_*` |
| T148-2 | Kéo file .docx vào 📚 Kho tri thức | một dòng journal (kind document, folder đúng), Brain map hiện tài liệu |
| T148-3 | Từ cell gọi `vault.list`, `journal.find` (request MCP thường) | trả dữ liệu; không 404 / `class not found` |
| T148-4 | Cài + bật `bizcity-twin-notebook`; gửi tài liệu mới | sau vài phút tài liệu có trong notebook; câu hỏi đồ thị có `kg_highlight` |
| T148-5 | Tắt plugin notebook | admin không lỗi; menu Twin Chat ẩn; bảng `bizcity_kg_*` còn; Guru vẫn trả lời từ Vault |
| T148-6 | T-GOLD-1 sau W1, W2, W4 | như baseline |
