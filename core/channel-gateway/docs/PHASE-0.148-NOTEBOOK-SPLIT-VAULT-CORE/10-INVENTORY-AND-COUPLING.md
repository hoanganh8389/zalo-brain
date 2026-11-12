# PHASE-0.148 · 10 — Hiện trạng đọc mã: kg-hub · TwinChat · Journal · Vault

<!-- [2026-11-12 08:38 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.148 W0 — census đọc mã (git grep, chỉ đọc). Số dòng là ảnh chụp 12/11; ~20 file kg-hub đang có sửa chưa commit ⇒ chạy lại tools/PHASE-0.148-split-census.selfcheck.mjs trước khi code W1. -->

Đường dẫn tính từ `bizcity-twin-ai/`.

## 1. kg-hub (`core/kg-hub/`)

- `bootstrap.php` (481 dòng) · `includes/` ~75 lớp + `adapters/ backfill/ clients/ filestore/ twin-sheet/` · `skeleton/` · `prompts/` · `contracts/` (17 JSON) · `assets/bztwin-skeleton.*`.
- Hằng: `BIZCITY_KG_HUB_DIR/_URL/_VERSION(0.21.2)/_INCLUDES/_SKELETON/_PROMPTS`.
- **Nạp:** không từ file chính. `core/guru/profile/bootstrap.php:160-170` ⇒ `BizCity_Safe_Loader::require_file(…, 'knowledge.kg_hub.bootstrap')`; file đó được file chính nạp ở dòng ~432, gác bởi `$_bizcity_admin_ctx && ! $_bizcity_twinchat_admin_shell_request`. `class-twinsearch-core.php` được file chính nạp riêng (dòng ~449).
- Feature flag: không có cờ bật/tắt kg-hub; chỉ filter `bizcity_kg_v06_dual_write`, `_v06_read_switch`, `_v07_file_primary_write`, `_v08_graph_embedding_migration` (bootstrap 454-476).

### 1a. Phần **KG** (đi sang notebook)
| Nhóm | Lớp |
|---|---|
| Dữ liệu | `BizCity_KG_Database` (+ `BizCity_KG_Installer`, tham chiếu từ `core/runtime/class-schema-registry.php:28`) |
| Notebook / nguồn | `_Notebook_Service`, `_Source_Service`, `_Content_Router`, `_Zalo_Number_Notebooks`, `_Owner_Pack_Exporter` |
| Tìm / đồ thị | `_Retriever`, `_Reranker`, `_Graph_Service`, `_Triplet_Extractor`, `BizCity_Embedding`, `_Vector_File_Store`, `_Private_File_Store`, `BizCity_TwinSearch_Core`, `BizCity_File_Parser` |
| Vận hành | `_Cost_Guard`, `_Cleanup_Service`, `_Auto_Promoter`, `_Guru_Builder`, `_Access`, `_Skeleton_Service/_Adapter/_Diagnostic` |
| Kênh | `_Channel_Notebook_Bridge`, `_Channel_Upload_Link_Service`, `_Channel_Progress_Notifier`, `_Channel_Notebook_Generic_Listener` |
| REST | `bizcity-knowledge/v2` ~41 route KG (`/notebooks*`, `/workspaces*`, `/acl/*`, `/entities*`, `/relations*`, `/graph`, `/gurus`, `/cost/*`, `/public-links*`, `/scoped/*`, `/zalo-numbers`, `/context-producers*`) · `bizcity-kg/v1/identity/*` · `bizcity/kg/v1` + `bzkg/v1` (skeleton) |
| Bảng | `bizcity_kg_{notebooks, notebook_sources, passages, entities, relations, passage_entities, passage_relations, triplet_queue, provenance, scope_links, sources, xref, passage_identities, skeleton_history, workspaces, grants, acl_log, usage_log, cleanup_log}` + `bizcity_notebook_character_attachments` |

> ⚠ `/context-producers*` + `class-context-producers*.php` được `core/runtime/class-zalo-brain.php:262` dùng ⇒ xếp vào **Vault** (xem 20 §1), không đi notebook.

### 1b. Phần **Vault + Journal** (ở lại lõi, dời sang `core/vault/`)
| Nhóm | File (`core/kg-hub/includes/`) |
|---|---|
| Journal (24 lớp) | `class-journal-{database, index, kinds, entry-meta, evidence, filer, doc-meta, doc-index-pack-exporter, rest, purge, ingest-listener, learning-sync, output-listener, job-link, tool-axis, skill-link, layer-rows, brain-map, pipeline, turn-uses, send-zalo, contact-profile, backfill, meta-backfill}.php` · `journal-api.php` (`bizcity_journal_record()`) |
| Vault | `class-twin-vault.php` · `class-brain-vault-store.php` · `class-vault-upload.php` · `class-vault-tags.php` · `class-scope-meter.php` |
| Quyền / liên kết | `class-brain-access.php` · `class-brain-resource-link.php` |
| Sheet | `twin-sheet/*` (9 file; CPT định nghĩa, REST `bizcity-knowledge/v2/twinsheets/*`, MCP `twinsheet.*`) |
| Hợp đồng | `contracts/{BRAIN-ACCESS, BRAIN-LEDGER, BRAIN-MAP, DOC-REGISTER, ENTRY-META, JOURNAL-ENTRY, JOURNAL-KINDS, JOURNAL-PIPELINE, TWIN-SHEET*, TWIN-VAULT, VAULT-APPEND}` · `NOTEBOOK-GRAPH-ASK` đi notebook |
| Bảng / lưu trữ | `bizcity_journal_entries` (một bảng) · option `bizcity_vault_folders`, `bizcity_vault_tag_aliases`, `bizcity_scope_meter_*` · file `uploads/bizcity-vault/<blog>/<ns>/<REF>/v<n>/r<rev>.md` (+ `.map.json`) |
| REST | `bizcity-vault/v1/{tree, list, search, graph, move, folder, folder/{nn}, note, note/append, upload, tags*, scope-matrix*}` · `bizcity-knowledge/v2/journal/{find, {id}, {id}/learn·meta·reextract·vault·candidates, export, summarize, team, versions, map, maturity, pipeline, build-trace, rebuild-read, contact/{id}/profile, send-to-zalo}` · `/documents/*` · `/notebooks/from-refs` |

⚠ `class-journal-learning-sync.php` + `/journal/{id}/learn` + `/notebooks/from-refs` là chỗ Journal **đẩy sang KG** ⇒ W2 đổi thành người nghe ở notebook (D148-6).

## 2. TwinChat (`modules/twinchat/`) — đi sang notebook nguyên khối

- Lớp: `BizCity_TwinChat_{Admin_Menu, Database, Sources_Database, Chunker, Sources_Service, Search_Engine, Rest_Controller, Vault_Pool_Controller, Entitlement_Proxy, Search_Proxy, Liteparse_Health, Workspace_REST, Notes_Service, Notes_Controller, Public_Page, Settings_Page, Learning_*(13)}`.
- Hằng: `BIZCITY_TWINCHAT_DIR/_URL/_VERSION(0.5.5)/_INCLUDES/_UI_DIR`, `BIZCITY_TWINCHAT_REST_NS = bizcity-twinchat/v1`.
- REST ~57 route (`/chat/{nb}/turn|stream`, `/sources/*`, `/search*`, `/learning/*`, `/notes*`, `/notebooks/{id}/vault-pool*`, `/public/ask|kg|mcp/{token}`, `/entitlement`, `/plans/summary`…).
- Bảng tạo: `bizcity_kg_learning_{jobs, events, batches}`; chỉ đọc: `bizcity_webchat_sources/_source_chunks/_messages`, `bzdoc_documents`, `bizcity_entitlement_usage`.
- Menu: "Twin Chat" (`bizcity-twinchat`) + "⚙ Settings" (`BizCity_TwinChat_Settings_Page` — "BizCity API & Gateway").
- **Nạp:** file chính dòng ~910-914 khi `$_bizcity_admin_ctx` hoặc URI `/twinchat`; cờ `$_bizcity_twinchat_admin_shell_request` (dòng 67) còn được `core/twinbrain/bootstrap.php:214` + `class-twinbrain-admin-menu.php:19` đọc; cron `bizcity_twinchat_learning_15min` (477), `bizcity_kg_learning_sweep` / `bizcity_kg_orphan_cleanup_weekly` (1166-1168).
- **Chưa track git:** `class-twinchat-vault-pool-controller.php`, `learning/class-twinchat-welcome-listener.php`, `diagnostics/*` ⇒ khi chuyển phải copy tay (memory "Upload NEW untracked files").
- Màn đang ở TwinChat mà thuộc **Vault** theo chỉ đạo: `ui/src/components/journal/{BrainMapView, BrainMaturity, BrainLedger}.tsx`, `lib/brain-map/brain-map-core.js`, `components/vault/*`, `VaultGraphPanel.tsx` ⇒ D148-7.
- Ràng buộc chéo: TwinChat → kg-hub ~280 chỗ (rest-controller 70, sources-service 48); kg-hub → TwinChat 10 file (`class-kg-channel-notebook-bridge.php` 26, `scoped-rest-controller` 18). Hai khối này **đi cùng nhau**, không tách được rời.

## 3. MCP (cửa duy nhất của cell)

| Tool | Ở đâu | Sau tách |
|---|---|---|
| `journal.{find, get, file, record, mirror, void, deliver, summarize, derive, meta.set, evidence.record}`, `track.get/set`, `vault.{get, materialize, revision}` | `core/mcp/includes/class-journal-mcp-tools.php` (1810 dòng) | lõi |
| `vault.{list, search, write, append, move}` | `Twin_Vault` | lõi |
| `vault.status`, `notebook.study` | `class-journal-pipeline.php` | `vault.status` lõi · `notebook.study` notebook |
| `journal.purge`, `journal.contact.profile`, `vault.tags.list`, `twinsheet.*` | kg-hub | lõi |
| `knowledge.search` (+ alias `brain.search`, `notebook_search` ở cell) | `class-mcp-tool-registry.php:983` ⇒ `knowledge_search_counted` (183): KG retriever + `Twin_Vault::public_passages` **chỉ cho `guru_public`** | lõi: **Vault cho mọi principal** + KG khi có notebook (D148-4) |
| `brain.graph_ask` | registry 1014 ⇒ `core/twinbrain/includes/class-twinbrain-graph-ask.php` | notebook (Q148-3) |
| `brain.list_notebooks`, `brain.get_passage`, `brain.get_citation_pack`, `brain.context.*` | registry / `class-brain-mcp-service.php` | notebook; lõi trả rỗng có `note: notebook_off` |

Cell (`zalo-hub/src`): `brain-core/mcp/hub-mcp-client.ts` ⇒ Hub `/zalo-hub/mcp` ⇒ site `bizcity-mcp/v1/mcp`. Gọi: `brain-api/types.ts` (journal.*, vault.get/materialize/revision), `brain-api/auto-folder.ts` + `entry-meta-extract.ts:248` (`vault.move`), `brain-core/knowledge/retrieve.ts` + `owner-tools/notebook-search-tool.ts` (`knowledge.search`), `agent/notebook-graph.ts` (`brain.graph_ask`). Guru folder: `wp-surface/guru-context-client.ts` (`vaultFoldersOf`) ⇒ `brain-api/find.ts` (`context_scope.folders`) ⇒ `agent-loop.ts:529`. **Không chỗ nào gọi kg-hub/twinchat trực tiếp.**

## 4. Ràng buộc vào (ngoài kg-hub + TwinChat) — việc của W2

Ký hiệu: **V** = gọi phần Vault/Journal (chỉ cần đổi đường nạp ở W1, không sửa) · **K** = gọi phần KG (phải qua cổng / guard ở W2) · **T** = gọi TwinChat.

| File | Ký hiệu chạm (dòng đầu tiên) | Loại |
|---|---|---|
| `core/mcp/includes/class-journal-mcp-tools.php` | Journal_Index 14, Filer 15, Twin_Vault 576, Kinds 602, Brain_Access 741, Job_Link 819, Entry_Meta 1002, Journal_Database 1028, Output_Listener 1284, `bizcity_journal_record` 1327, Brain_Resource_Link 1637, Evidence 1705, Brain_Vault_Store 1722 | V |
| `core/mcp/includes/class-brain-mcp-service.php` | KG_Retriever 9, Notebook_Service 75, Skeleton_Adapter 186, KG_Database 217, Content_Router 564, BizCity_KG 887 · Brain_Access 304, Journal_Database 699, Journal_Index 704 | K + V |
| `core/mcp/includes/class-mcp-tool-registry.php` | Notebook_Service 1092, Retriever 1102, `bizcity_kg_validate_citations_in_json` 1236 · Journal_Tool_Axis 118, Brain_Map 179, Twin_Vault 191, Journal_Index 211, Scope_Meter 795, Turn_Uses 799 | K + V |
| `core/mcp/includes/class-mcp-resource-service.php` | Notebook_Service 1001, KG_Database 1010, Content_Router 1020; nghe hook `bizcity_kg_*` 692-698 | K |
| `core/mcp/includes/class-mcp-retrieval-snapshot-store.php` | Retriever 16, KG_Database 92 | K |
| `core/mcp/includes/class-mcp-delegation.php` | Notebook_Service 272 · Twin_Vault 301 | K + V |
| `core/mcp/includes/class-mcp-client-scope-resolver.php` | Notebook_Service 6, bảng `kg_notebooks` 13 | K |
| `core/mcp/includes/class-mcp-citation.php` | bảng `kg_passages` 12, `kg_sources` 15 | K |
| `core/mcp/includes/class-document-mcp-service.php` | `bizcity_kg_validate_citations_in_json` 216 | K |
| `core/mcp/includes/brain/class-knowledge-stats-mcp-service.php` | KG_Database 74 | K |
| `core/mcp/bootstrap.php` 192 · `rest/class-mcp-admin-rest.php` 205 · `rest/class-mcp-http-controller.php` 318 | Journal_Index / Journal_MCP_Tools | V |
| `core/channel-gateway/includes/panel/class-panel-rest.php` | Journal_REST 18, Twin_Vault 21, Brain_Map 23, Journal_Database 695, Pipeline 771, Journal_Index 840, Brain_Vault_Store 891, `bizcity_journal_record` 920, Doc_Index_Pack_Exporter 964 · **KG_Private_File_Store 842** | V + K |
| `…/panel/class-panel-tz-views.php` 246 · `class-job-view-rest.php` 330 · `profile/class-brain-profile-mcp-tools.php` 15/317 | Job_Link / Journal_MCP_Tools / Journal_Index | V |
| `…/class-twin-cell-output-store.php` | **KG_Private_File_Store 37** · Output_Listener 338 | K + V |
| `…/core-routes/class-channel-core-rest.php` | KG_Database 2867 | K |
| `…/bot/class-guru-context-resolver.php` | bảng `kg_notebooks` 472, KG_Database 487 · Twin_Vault 651 | K + V |
| `…/bot/class-guru-notebook-blocks.php` | Retriever 10, KG_Database 121, Content_Router 128 | K |
| `…/bot/class-guru-seed.php` 341 | Twin_Vault | V |
| `…/class-web-post-publisher.php` 230 | meta `bizcity_kg_notebook_id` | K |
| `core/channel-gateway/bootstrap.php` 799 | cầu dự phòng `bizcity_twin_notebook_event` | K |
| `core/crm/includes/class-crm-group-secretary.php` | Journal_Index 694, Journal_Database 1205 | V |
| `core/twinbrain/*` | `bootstrap.php` Vector_File_Store 26 · `class-twinbrain-rest.php` KG_Access 228, KG_Database 812 · `citation-resolver` 381/431 · `schema` 192 · cờ shell request 214 | K (Q148-3) |
| `core/guru/profile/bootstrap.php` 160-170 | **nạp kg-hub** | đổi ở W1 |
| `core/guru/profile/includes/class-guru-service.php` 341 · `class-character-quick-edit-rest.php` 163/446 · `runtime/includes/class-twin-guru-context.php` 128 · `admin/includes/class-admin-menu.php` 2533 · `admin/views/character-edit.php` 128/497/505 · `admin/views/notebook-notes-panel.php` 18/48/66 · `admin/includes/class-admin-menu.php` 1648 + `templates/admin-memory.php` 63 | KG_Database, Notebook_Service, Source_Service, Vector_File_Store, BizCity_KG · TwinChat_Notes_Service | K + T |
| `core/runtime/class-zalo-brain.php` | Context_Producers 262, `bizcity_journal_record` 270, Journal_Kinds 272 | V |
| `core/runtime/agents/contracts/class-artifact-source-federation.php` 171/217 · `class-user-meta-cache.php` 25/211 | bảng `kg_sources`, `kg_notebooks`, `kg_workspaces`; meta `bizcity_twinchat_notebook_id` | K + T |
| `core/context-bank/includes/class-context-bank-kg-bridge.php` | BizCity_KG 74, Cost_Guard 89, Notebook_Service 341 | K |
| `core/skills/includes/class-skills-pack-exporter.php` 84/85 · `class-skill-manager.php` 45 | Journal_Index, Skill_Link, Journal_Database, Brain_Vault_Store | V |
| `core/bizcity-llm/includes/class-llm-client.php` 458 | KG_Cost_Guard | K |
| `core/bizcity-llm/includes/class-llm-settings.php` 101 · `class-llm-gateway-panel-rest.php` 45 · `class-usage-proxy-rest.php` 52 | TwinChat_Settings_Page, `BIZCITY_TWINCHAT_REST_NS` | T (Q148-6) |
| `core/conversation/class-conversation-store.php` 410 · `core/mcp/includes/class-brain-mcp-service.php` 811 | TwinChat_Sources_Database | T |
| `core/helper/bootstrap.php` 320-525 · `class-bizcity-legacy-table-policy.php` 49-209 | bảng kg/twinchat, Skeleton_Service, Learning_Database/Events | K + T |
| `core/cli/class-bizcity-framework-cli.php` 77/313 | Journal_MCP_Tools · Skeleton_Diagnostic | V + K |
| `core/twin-core/contracts/content-contracts.php` 37 · `includes/class-twin-content-registry.php` 109 · `bootstrap.php` 35 | `BizCity_KG_Source_Adapter_Interface`, `BIZCITY_TWINCHAT_USE_TWIN_AGENT` | K + T |
| `modules/twinshell/*` | Notebook_Service 180, Cleanup_Service 143, TwinChat Public_Page / Learning_Stream / Learning_Aggregator / REST NS | K + T (Q148-7) |
| `plugins/bizcity-zalo-bot/…/class-notebook-bridge-listener.php` | Channel_Notebook_Bridge 6, Progress_Notifier 68, Journal_Database 654, Upload_Link_Service 974, TwinChat Learning_Share_Adapter 914 · `class-upload-link-handler.php` 9/13 · `class-rest-api.php` 653 | K + V + T |
| `plugins/bizcity-zalo-personal/includes/shared/class-zalo-personal-knowledge-capture.php` | Progress_Notifier 20, Notebook_Bridge 297, Private_File_Store 823 · Journal_Index 182, Filer 613, Kinds 677, Brain_Map 1064, Journal_Database 1132 | K + V — **đường upload Zalo chính** (D148-6) |
| `plugins/bizcity-zalo-personal/…` `notebook-learned` 98/108 · `guru-knowledge-rest` 194/202 · `guru-knowledge-version` 81 · `pack-invalidate` 249 · `hub-guru-invalidate` 92 · `staff-principals-rest` 542 | KG_* | K |

**Đếm bằng máy (selfcheck, 2026-11-12 08:38 PM, chỉ `*.php` đã track git, ngoài kg-hub/twinchat/twinkg):**

| Loại | Dòng | File | Đợt |
|---|---|---|---|
| V Vault/Journal | 253 | 21 | W1 — chỉ đổi đường nạp, không sửa |
| K KG | 233 | 47 | W2 — về 0 (qua cổng) |
| T TwinChat | 28 | 15 | W2 — về 0 |

Nặng nhất (K): `class-brain-mcp-service.php` 31 · `class-context-bank-kg-bridge.php` 17 · zalo-bot `class-notebook-bridge-listener.php` 16 · `class-upload-link-handler.php` 13 · `class-mcp-resource-service.php` 12 · twinshell primitives 9 · guru-service 9 · character-edit 9. Chạy `--list` để có từng file:dòng.

## 5. Hook

| Hook | Ai bắn | Ai nghe | Sau tách |
|---|---|---|---|
| `bizcity_journal_entry_added` | `class-journal-index.php:197` | **chưa ai** | notebook nghe để học (D148-6) |
| `bizcity_kg_after_ingest_central`, `_after_extension_ingest`, `_after_source_delete`, `_notebook_stats_dirty`, `_notebook_deleted`, `_guru_attached/_detached` | kg-hub | mcp-resource-service 692-698, zalo-personal guru/pack invalidate | notebook bắn; người nghe ở lõi vẫn chạy (không có ai bắn = không chạy, vô hại) |
| `bizcity_kg_notebook_learned` | kg-hub | zalo-personal 45 | như trên |
| `bizcity_twin_agent_packs_changed` | kg-hub | crm 45, mcp 703, woo 53, zalo-pack 64 | **Vault cũng phải bắn** khi thư mục / note đổi (nay chỉ KG bắn) |
| `bizcity_channel_normalized`, `bizcity_cell_output_created/_stored`, `bizcity_mcp_tool_finished`, `bizcity_mcp_register_tools`, `bizcity_crm_*`, `bizcity_context_emit`, `zalo_brain_register`, `bizcity_skill*`, `bizcity_scheduler_event_completed`, `bizcity_brain_profile_installed` | nơi khác | kg-hub (Journal listener phần lớn) | người nghe Journal ⇒ `core/vault`; generic channel listener KG ⇒ notebook |
