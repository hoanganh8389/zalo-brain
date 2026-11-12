# PHASE-0.148 · 00 — Master plan: lõi = Channel Gateway + Vault, Notebook là plugin riêng

<!-- [2026-11-12 08:38 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.148 W0 — từ chỉ đạo chủ 12/11. -->

> ⚠ **Vòng 1 (2026-11-12 08:58 PM) thắng khi mâu thuẫn:** Vault = `core/skills` (thư viện Skill cũ), không tạo thư mục mới — xem [50-OWNER-ROUND-1](50-OWNER-ROUND-1-VAULT-IS-CORE-SKILLS.md) + truy vết [15](15-DOC-TRACE-0120-0147.md). Đường dẫn trong file này đã đổi `core/vault` ⇒ `core/skills`.
> ⚠ **Vòng 2 (2026-11-12 09:17 PM) thắng vòng 1:** ba module lõi **`core/vault`** (đổi tên từ `core/skills`) · **`core/journal`** · **`core/sheet`**; không gộp Sổ cái; giữ `bizcity/skill/v1` — xem [60-OWNER-ROUND-2](60-OWNER-ROUND-2-VAULT-JOURNAL-SHEET.md). Đường dẫn `core/skills/includes/journal|twin-sheet` trong file này ⇒ đọc là `core/journal` / `core/sheet`.

## 0. Chỉ đạo của chủ (12/11)

> *"Research việc tách module twinchat và kg-hub ra. Core của zalo brain chỉ xoay quanh trục chính channel gateway và vault thôi. Người dùng tải vault lên, agent guru chọn vault, là có thể trả lời được rồi. Phần journal index cần move từ kg hub twinchat sang vault. Vì journal index là để lập sổ cái tài liệu tải lên qua zalo hoặc trực tiếp qua admin page. Brain map knowledge dùng vault. Journal ledger đi theo vault. Cell sẽ mcp knowledge tool gọi vault theo journal index."*
> *"Trục twinchat và kghub sẽ đưa vào bizcity-twin-notebook. Bộ plugin xoay quanh zalo brain sau này sẽ gồm: 1. CRM 2. Notebook 3. Bizcoach-pro 4. Automation …"*

## 1. Hiện trạng đọc mã (tóm — chi tiết + file:dòng ở [10](10-INVENTORY-AND-COUPLING.md))

| Điều | Thực tế |
|---|---|
| Vault và Journal ở đâu | **Cả hai nằm trong `core/kg-hub/`.** `BizCity_Twin_Vault` (`class-twin-vault.php`, 1948 dòng), `BizCity_Brain_Vault_Store`, `BizCity_Vault_Upload`, 24 lớp `class-journal-*.php`, `journal-api.php`, `twin-sheet/*` — tất cả nạp chung một vòng `foreach` ở `core/kg-hub/bootstrap.php:113-171`. Không có module vault riêng. |
| Bảng | Journal: **một bảng** `{prefix}bizcity_journal_entries`. Vault không có bảng: thư mục ở option `bizcity_vault_folders`, bản markdown ở `uploads/bizcity-vault/<blog>/…/v<n>/r<rev>.md`. KG: 19 bảng `bizcity_kg_*`. TwinChat: 3 bảng `bizcity_kg_learning_*` + đọc `bizcity_webchat_*`. |
| kg-hub nạp khi nào | Không nạp thẳng từ file chính: `core/guru/profile/bootstrap.php:160-170` gọi `BizCity_Safe_Loader` — tức **Vault + Journal sống nhờ Guru profile**. |
| TwinChat | `modules/twinchat/` — ~57 route `bizcity-twinchat/v1`, UI Vite riêng; chứa luôn màn Brain map (`BrainMapView.tsx`), BrainLedger, Vault pool. |
| Ràng buộc | TwinChat → kg-hub ~280 chỗ; kg-hub → TwinChat 10 file. Ngoài hai module (đếm bằng máy): **V 248 dòng / 19 file · K 224 / 45 · T 28 / 15** (vòng 1) ở core/mcp, channel-gateway, guru, crm, twinbrain, skills, runtime, helper, twinshell, plugins/zalo-bot, plugins/zalo-personal gọi thẳng lớp kg-hub. |
| Cell | Chỉ đi **một cửa** MCP (`hub-mcp-client.ts` ⇒ Hub ⇒ `bizcity-mcp/v1/mcp` của site). Gọi `journal.*`, `vault.get/materialize/revision/move`, `knowledge.search`, `brain.graph_ask`. **Không gọi endpoint kg-hub/twinchat nào** ⇒ đổi chỗ phía site không phải sửa cell nếu giữ nguyên tên tool. |
| Guru chọn thư mục | Liên kết nằm trên thư mục (`bizcity_vault_folders[].gurus[]`), đọc qua `Twin_Vault::folders_for_guru()`; cell đổi thành `context_scope.folders` trên `journal.find`. Đã đúng trục Vault — chỉ cần Vault tự đứng được. |
| Plugin `bizcity-twin-notebook` | **Chưa có.** Khuôn sẵn: `bizcity-automation` (add-on locator), `bizcity-twin-crm` (spine ở lõi + `plugins_loaded`), `bizcoach-pro`. |

**Một câu:** việc chính không phải "đẩy kg-hub ra" mà là **rút Vault + Journal ra khỏi kg-hub về **`core/skills` — nhà của Vault (thư viện Skill cũ)** và cho nó tự nạp**, rồi phần còn lại của kg-hub + TwinChat mới đi sang plugin.

## 2. Ba đường ranh (D148-1)

| Ở **lõi** Zalo Brain (`bizcity-twin-ai`) | Ở **`bizcity-twin-notebook`** | Ở cell (không đổi) |
|---|---|---|
| **Channel Gateway** (như nay) | **TwinChat** toàn bộ `modules/twinchat/` (chat notebook, sources, learning, notes, public page, UI) | tool `journal_find`, `journal_get`, `notebook_search`, `vault.*` giữ tên |
| **`core/skills` = Vault (📚 Kho tri thức)**: Twin Vault (thư mục, note, upload, tags, scope meter), Vault store markdown, **Journal Index + sổ cái** (`bizcity_journal_entries`, kinds, entry-meta, evidence, filer, pipeline đọc file, purge, job-link, tool-axis, skill-link, contact-profile, send-zalo), brain-access, **Brain map + maturity**, Twin Sheet, hợp đồng journal/vault | **kg-hub phần KG**: notebooks, sources, passages, entities/relations, triplet, retriever/reranker, embedding + vector file store, graph service, skeleton, cost guard, cleanup, auto-promoter, guru builder, workspace/ACL, channel-notebook bridge, upload-link, progress notifier, owner-pack exporter | `brain.graph_ask` trả `not_available` khi không có notebook ⇒ cổng `kg_highlight` tắt, trả lời vẫn chạy |
| `core/mcp`: `journal.*`, `vault.*`, `knowledge.search` (**mặc định tìm trong Vault theo Journal Index**) | `modules/twinkg` (UI đồ thị), `brain.graph_ask`, `knowledge.search` nguồn KG bổ sung, `notebook.study` | |
| Cổng `BizCity_Notebook_Port` (rỗng khi không có plugin) | đăng ký vào cổng khi bật | |

## 3. Quyết định đề xuất (D148-*) — thành chốt khi chủ duyệt Q tương ứng

| # | Quyết định | Q |
|---|---|---|
| D148-1 | Ba đường ranh như §2. Lõi = Channel Gateway + Vault. | Q148-1 |
| D148-2 v2 | Vault + Journal dời về **`core/skills`** (đã là nhà của Vault: trang 📚 Kho tri thức, SPA, `uploads/bizcity-skills`); `core/skills/bootstrap.php` nạp cùng cổng `core/mcp`, trước `core/mcp`, không qua Guru profile. Hằng bí danh `BIZCITY_VAULT_DIR`, `BIZCITY_VAULT_LOADED`. Chi tiết doc 50 | Q148-9 |
| D148-3 | **Giữ nguyên tên lớp, tên bảng, tên option, namespace REST** (`bizcity-vault/v1`, `bizcity-knowledge/v2/journal/*`) và tên MCP tool. Chỉ dời file. Không migration dữ liệu. | — |
| D148-4 | `knowledge.search` ở lõi trả kết quả từ **Vault markdown theo Journal Index** (find_text + thư mục Guru, R-SCOPE-FOLDER). Notebook bật ⇒ thêm nguồn KG qua filter `bizcity_vault_knowledge_providers`. Đúng D142-17: Guru trả lời từ markdown Vault. | Q148-3 |
| D148-5 | Mọi chỗ ngoài notebook gọi lớp KG ⇒ đi qua `BizCity_Notebook_Port` (`available()`, `search()`, `notebook_for_guru()`…) hoặc guard `class_exists`. Không còn `BizCity_KG_*` gọi thẳng trong lõi sau W3. | — |
| D148-6 | Tài liệu tải lên (Zalo **hoặc** trang admin) **luôn** vào Vault + một dòng journal trước; notebook chỉ **nghe** `bizcity_journal_entry_added` (hook đã có, chưa ai nghe) để học thêm. Bỏ đường Zalo ⇒ KG notebook trực tiếp. | Q148-4 |
| D148-7 | Brain map (`/journal/map`, `/journal/maturity`, BrainLedger) dời về Vault: API ở `core/skills`, màn hiển thị chuyển từ TwinChat sang trang **📚 Kho tri thức** (`core/skills/app/src/vault/`). | Q148-5 |
| D148-8 | Plugin mới **`bizcity-twin-notebook`** theo khuôn 0.102 add-on host: header `Zalo Brain Addon: notebook`, `zalo-brain.json` (zalo-brain-extension@1), hằng nạp `BIZCITY_NOTEBOOK_LOAD` = auto · active · off. Trong thời gian chuyển: **auto** = có plugin thì nạp từ plugin, không có thì nạp bản cũ trong lõi ⇒ lùi được từng đợt. | Q148-2 |
| D148-9 | Bảng `bizcity_kg_*`, `bizcity_kg_learning_*` **thuộc plugin notebook** (installer đi theo). Tắt plugin không xoá bảng. Lõi không tạo bảng KG nữa. | — |
| D148-10 | Họ plugin Zalo Brain: **CRM** (`bizcity-twin-crm`) · **Notebook** (`bizcity-twin-notebook`) · **Bizcoach-pro** · **Automation** (`bizcity-automation`) · … Cùng một chuẩn: `zalo-brain-extension@1`, MCP qua cửa `core/mcp`, không chạm token (R-TOKEN-LEDGER-CELL). | — |
| D148-11 | Cell không đổi mã. Chỉ thêm ca kiểm: `brain.graph_ask` = `not_available` ⇒ không lỗi, không `kg_highlight`. | — |

## 4. Câu hỏi chờ chủ (Q148-*)

| # | Câu hỏi | Gợi ý (1 = đề xuất) |
|---|---|---|
| Q148-1 | Đồng ý ba đường ranh §2? | **1. Đồng ý** · 2. giữ Twin Sheet ở notebook |
| Q148-2 | Tên plugin + repo GitHub? | **1. `bizcity-twin-notebook` → repo `zalo-brain-notebook`** (giống `zalo-brain-crm`) · 2. tên khác |
| Q148-3 | `core/twinbrain` (graph_ask, citation resolver, vector store) đi đâu? | **1. graph_ask + phần đọc KG sang notebook; twinbrain ở lõi giữ phần không đụng KG** · 2. cả twinbrain sang notebook |
| Q148-4 | Upload qua Zalo: bỏ ghi thẳng vào KG notebook, chỉ ghi Vault + journal? | **1. Đúng — notebook học sau qua hook** · 2. giữ cả hai đường khi có notebook |
| Q148-5 | Màn Brain map / BrainLedger chuyển sang trang 📚 Kho tri thức? | **1. Có** · 2. để ở Channel Gateway SPA |
| Q148-6 | Trang cài đặt "BizCity API & Gateway" đang là lớp TwinChat (`BizCity_TwinChat_Settings_Page`, dùng ở `bizcity-llm`) | **1. Dời vào `core/bizcity-llm`** (lõi cần nó dù không có notebook) · 2. đi theo notebook |
| Q148-7 | TwinShell (learning SDK, public page) phụ thuộc TwinChat | **1. Phần learning của TwinShell đi theo notebook; TwinShell ẩn mục đó khi notebook tắt** · 2. TwinShell sang notebook luôn |
| Q148-8 | Tài liệu phase này lên GitHub? (`.gitignore` đang chặn `**/docs/` theo R-PROD-REPO) | **1. PR riêng chỉ chứa thư mục phase này (force-add), để chủ đọc rồi quyết** · 2. giữ local |
| Q148-9…11 | đổi tên `core/skills`? · gộp hai Sổ cái? · bỏ `bizcity/skill/v1`? | ✅ **chủ 12/11 09:1x PM:** đổi tên ⇒ `core/vault` · không gộp (+ tách `core/journal`, `core/sheet`) · không bỏ — [60](60-OWNER-ROUND-2-VAULT-JOURNAL-SHEET.md) |

## 5. Năm đợt (mỗi đợt ≤ 4 bước, chi tiết [40](40-MASTER-CHECKLIST.md))

| Đợt | Việc | Lùi được bằng |
|---|---|---|
| **W0** | Tài liệu + census (phase này) + selfcheck đếm ràng buộc | — |
| **W1** | `git mv` Vault + Journal + Twin Sheet + Brain map từ `core/kg-hub/` sang `core/skills/`; `core/skills/bootstrap.php` nạp thêm các file đó, trước `core/mcp`; kg-hub bootstrap bỏ các file đó khỏi vòng `foreach`. **Không đổi hành vi.** | revert commit |
| **W2** | `BizCity_Notebook_Port` + `knowledge.search` từ Vault; thay ~75 chỗ gọi thẳng bằng cổng / guard; đường upload Zalo đi Vault trước (D148-6) | filter tắt cổng |
| **W3** | Tạo `bizcity-twin-notebook` (khuôn 0.102), chuyển `modules/twinchat`, phần KG của `core/kg-hub`, `modules/twinkg`; `BIZCITY_NOTEBOOK_LOAD=auto` | `BIZCITY_NOTEBOOK_LOAD=off` / bản cũ trong lõi |
| **W4** | Xoá bản cũ khỏi lõi; golden T148-1 (site **không** có notebook: tải tài liệu ⇒ Guru trả lời) + T-GOLD-1; repo GitHub cho notebook | cài lại bản lõi trước W4 |

## 6. Phase này **không** làm
Đổi tên lớp / bảng / tool · migration dữ liệu · đổi cell · viết lại TwinChat UI · gộp CRM / Bizcoach / Automation (chỉ ghi chuẩn chung) · deploy.
