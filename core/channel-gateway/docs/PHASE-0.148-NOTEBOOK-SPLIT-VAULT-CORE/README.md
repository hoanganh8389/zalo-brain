# PHASE-0.148 — Lõi = Channel Gateway + Vault · TwinChat + kg-hub sang `bizcity-twin-notebook`

> **Trạng thái:** 📄 tài liệu nghiên cứu (W0) — 2026-11-12 08:38 PM. Chưa đụng mã, chưa tạo plugin, chưa deploy. Chờ chủ duyệt Q148-*.
> **Mục tiêu:** lõi Zalo Brain chỉ xoay quanh **hai trục: Channel Gateway + Vault (Kho tri thức)**. Người dùng tải tài liệu vào Vault ⇒ Guru chọn thư mục Vault ⇒ trả lời được ngay, **không cần TwinChat, không cần kg-hub**. Journal Index + sổ cái tài liệu + Brain map đi theo Vault. TwinChat + kg-hub (notebook, đồ thị tri thức, học) chuyển sang plugin riêng **`bizcity-twin-notebook`**.
> **Luật chạm tới:** R-ONE-VERTICAL-CELL · R-JOURNAL v1.1–1.4 · R-JN-28…31 (Journal Index = cửa não) · R-SCOPE-FOLDER + D142-17 · R-BRAIN-SKELETON · R-PROD-REPO · R-DIAG-LOCAL.
> **Hợp đồng:** journal-entry@1.2 · journal-kinds@1.3 · twin-vault@1 · vault-append@1 · brain-access@1 · entry-meta@1 · brain-map@1 · notebook-graph-ask@1 · zalo-brain-extension@1.
> **Lane có việc:** CL (site PHP) · NB (plugin mới) · UI · BC (cell — chỉ kiểm, không đổi tên tool) · OPS.
> **Phụ thuộc phase:** 0.96 (khuôn tách CRM: spine ở lõi + plugin) · 0.102 (khuôn add-on host) · 0.126 (journal = trục dọc) · 0.133/0.134 (Vault source first) · 0.136/0.142 (Guru chọn thư mục) · 0.143 (Notebook = builder của Vault) · 0.145.

<!-- [2026-11-12 08:38 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.148 W0 — mở phase từ chỉ đạo chủ 12/11: tách TwinChat + kg-hub, Journal Index sang Vault. -->

| Tài liệu | Một câu |
|---|---|
| [00-MASTER-PLAN](00-MASTER-PLAN.md) | chỉ đạo của chủ, đường ranh lõi / plugin, quyết định D148-*, câu hỏi Q148-*, 5 đợt |
| [10-INVENTORY-AND-COUPLING](10-INVENTORY-AND-COUPLING.md) | hiện trạng đọc mã: kg-hub, TwinChat, Journal, Vault — lớp, bảng, route, MCP, ai gọi ai (file:dòng) |
| [20-VAULT-CORE-DESIGN](20-VAULT-CORE-DESIGN.md) | module lõi `core/vault/`: cái gì dời vào, cổng (port) cho notebook, `knowledge.search` không cần KG |
| [30-NOTEBOOK-PLUGIN-DESIGN](30-NOTEBOOK-PLUGIN-DESIGN.md) | plugin `bizcity-twin-notebook`: cấu trúc, cách nạp, hook nhận tài liệu từ Vault, họ plugin Zalo Brain |
| [40-MASTER-CHECKLIST](40-MASTER-CHECKLIST.md) | nguồn trạng thái duy nhất S148-* / T148-* |
| [70-TEST-PLAN](70-TEST-PLAN.md) | kịch bản máy + người, golden "không có notebook vẫn trả lời" |
| [95-DEPLOY-LIST](95-DEPLOY-LIST.md) | thứ tự đợt, file đi theo từng đợt, cách lùi |
| [lanes/](lanes/LANE-CL-NB.md) · [mockups/](mockups/PHASE-0.148-core-vault-notebook-split.html) · [tools/](tools/PHASE-0.148-split-census.selfcheck.mjs) · [evidence/](evidence/README.md) | theo khuôn phase |
