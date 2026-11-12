# PHASE-0.148 — Lõi = Channel Gateway + Vault · TwinChat + kg-hub sang `bizcity-twin-notebook`

> **Trạng thái:** 📄 tài liệu nghiên cứu (W0 + vòng 1 + vòng 2) — 2026-11-12 09:17 PM. Vòng 2: lõi có **ba module** `core/vault` (đổi tên từ `core/skills`) · `core/journal` · `core/sheet` (doc 60); truy vết 0.120 → 0.147 ở doc 15. Chưa đụng mã, chưa tạo plugin, chưa deploy. Chờ chủ duyệt Q148-*.
> **Mục tiêu:** lõi Zalo Brain chỉ xoay quanh **hai trục: Channel Gateway + Vault (📚 Kho tri thức = `core/vault`, đổi tên từ `core/skills` — thư viện Skill cũ; Journal ở `core/journal`, Twin Sheet ở `core/sheet`)**. Người dùng tải tài liệu vào Vault ⇒ Guru chọn thư mục Vault ⇒ trả lời được ngay, **không cần TwinChat, không cần kg-hub**. Journal Index + sổ cái tài liệu + Brain map đi theo Vault. TwinChat + kg-hub (notebook, đồ thị tri thức, học) chuyển sang plugin riêng **`bizcity-twin-notebook`**.
> **Luật chạm tới:** R-ONE-VERTICAL-CELL · R-JOURNAL v1.1–1.4 · R-JN-28…31 (Journal Index = cửa não) · R-SCOPE-FOLDER + D142-17 · R-BRAIN-SKELETON · R-PROD-REPO · R-DIAG-LOCAL.
> **Hợp đồng:** journal-entry@1.2 · journal-kinds@1.3 · twin-vault@1 · vault-append@1 · brain-access@1 · entry-meta@1 · brain-map@1 · notebook-graph-ask@1 · zalo-brain-extension@1.
> **Lane có việc:** CL (site PHP) · NB (plugin mới) · UI · BC (cell — chỉ kiểm, không đổi tên tool) · OPS.
> **Phụ thuộc phase:** 0.96 (khuôn tách CRM: spine ở lõi + plugin) · 0.102 (khuôn add-on host) · 0.126 (journal = trục dọc) · 0.133/0.134 (Vault source first) · 0.136/0.142 (Guru chọn thư mục) · 0.143 (Notebook = builder của Vault) · 0.145.

<!-- [2026-11-12 08:38 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.148 W0 — mở phase từ chỉ đạo chủ 12/11: tách TwinChat + kg-hub, Journal Index sang Vault. -->

| Tài liệu | Một câu |
|---|---|
| [00-MASTER-PLAN](00-MASTER-PLAN.md) | chỉ đạo của chủ, đường ranh lõi / plugin, quyết định D148-*, câu hỏi Q148-*, 5 đợt |
| [15-DOC-TRACE-0120-0147](15-DOC-TRACE-0120-0147.md) | truy vết 0.120 → 0.147: Vault / Journal / Skill / kg-hub / TwinChat ở đâu, 12 mâu thuẫn và cách giải |
| [10-INVENTORY-AND-COUPLING](10-INVENTORY-AND-COUPLING.md) | hiện trạng đọc mã: kg-hub, TwinChat, Journal, Vault — lớp, bảng, route, MCP, ai gọi ai (file:dòng) |
| [20-VAULT-CORE-DESIGN](20-VAULT-CORE-DESIGN.md) | module lõi `core/skills/`: cái gì dời vào, cổng (port) cho notebook, `knowledge.search` không cần KG |
| [30-NOTEBOOK-PLUGIN-DESIGN](30-NOTEBOOK-PLUGIN-DESIGN.md) | plugin `bizcity-twin-notebook`: cấu trúc, cách nạp, hook nhận tài liệu từ Vault, họ plugin Zalo Brain |
| [50-OWNER-ROUND-1-VAULT-IS-CORE-SKILLS](50-OWNER-ROUND-1-VAULT-IS-CORE-SKILLS.md) | **vòng 1 (thắng khi mâu thuẫn):** Vault = `core/skills`; Journal trở về `core/skills`; mọi màn Vault ở 📚 Kho tri thức; Q148-9…11 |
| [60-OWNER-ROUND-2-VAULT-JOURNAL-SHEET](60-OWNER-ROUND-2-VAULT-JOURNAL-SHEET.md) | **vòng 2 (thắng vòng 1):** `core/skills` ⇒ `core/vault`; tách `core/journal` + `core/sheet` (hai hợp đồng lớn); không gộp Sổ cái; giữ `bizcity/skill/v1`; W1a–d |
| [40-MASTER-CHECKLIST](40-MASTER-CHECKLIST.md) | nguồn trạng thái duy nhất S148-* / T148-* |
| [70-TEST-PLAN](70-TEST-PLAN.md) | kịch bản máy + người, golden "không có notebook vẫn trả lời" |
| [95-DEPLOY-LIST](95-DEPLOY-LIST.md) | thứ tự đợt, file đi theo từng đợt, cách lùi |
| [lanes/](lanes/LANE-CL-NB.md) · [mockups/](mockups/PHASE-0.148-core-vault-notebook-split.html) · [tools/](tools/PHASE-0.148-split-census.selfcheck.mjs) · [evidence/](evidence/README.md) | theo khuôn phase |
