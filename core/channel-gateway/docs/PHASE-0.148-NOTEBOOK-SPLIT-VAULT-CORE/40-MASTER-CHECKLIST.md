# PHASE-0.148 · 40 — Master checklist

> ☐ chưa làm · 🟡 có mã + unit · ✅ có bằng chứng runtime · ⛔ chờ chủ · ❌ huỷ.

<!-- [2026-11-12 08:38 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.148 W0. -->

## OPS / chủ
| ID | Việc | Doc | Lane | Trạng thái |
|---|---|---|---|---|
| S148-O1 | Chủ duyệt Q148-1…8 (Q148-9…11 ✅ vòng 2) | 00 §4 | OPS | ⛔ |
| S148-O2 | Tạo repo `zalo-brain-notebook` (sau Q148-2) | 30 §6 | OPS | ⛔ |

## W0 — tài liệu + census
| ID | Việc | Doc | Lane | Trạng thái |
|---|---|---|---|---|
| S148-W0a | Bộ hồ sơ phase (README · 00 · 10 · 20 · 30 · 40 · 70 · 95 · lanes · mockup · tools · evidence) | — | — | 🟡 2026-11-12 08:38 PM |
| S148-W0b | Selfcheck census `tools/PHASE-0.148-split-census.selfcheck.mjs` (đếm ràng buộc V/K/T, kiểm vị trí lớp) | 10 §4 | — | 🟡 baseline vòng 1: V 248/19 · K 224/45 · T 28/15; `--check=W1` đỏ đúng (chưa dời) |

## W1 — ba module lõi `core/vault` · `core/journal` · `core/sheet` (dời file, không đổi hành vi — doc 60 W1a–d)
| ID | Việc | Doc | Lane | Trạng thái |
|---|---|---|---|---|
| S148-C0 | W1a: `git mv core/skills core/vault`; sửa `bizcity-twin-ai.php:802-803`, `core/helper/bootstrap.php:262,386`, changelog `core.skills.json` ⇒ `core.vault.json`; hằng `BIZCITY_VAULT_*` + bí danh `BIZCITY_SKILLS_*` | 60 D148-15 | CL | ☐ |
| S148-C0b | W1c: `core/sheet/` (bootstrap + twin-sheet/* + hợp đồng TWIN-SHEET*, BRAIN-LEDGER) | 60 §2 | CL | ☐ |
| S148-C1 | W1b: `git mv` 24 `class-journal-*` + `journal-api.php` + brain-access + brain-resource-link + context-producers* ⇒ `core/journal/includes/` (trừ learning-sync) | 20 §1 | CL | ☐ |
| S148-C2 | W1d: `git mv` twin-vault, brain-vault-store, vault-upload, vault-tags, scope-meter ⇒ `core/vault/includes/vault/` | 20 §1 | CL | ☐ |
| S148-C3 | Nạp ba bootstrap `core/journal` ⇒ `core/sheet` ⇒ `core/vault` cùng cổng `core/mcp`, trước nó (D148-19); schema_owner `bizcity_journal_entries` ⇒ `core.journal`; lật ghi chú 0.101; kg-hub bootstrap bỏ file đã dời; `--check=W1` xanh | 20 §2 | CL | ☐ |
| S148-C4 | Tách `notebook.study` + `/notebooks/from-refs` ra khỏi `class-journal-pipeline.php` | 20 §1 | CL | ☐ |
| S148-C5 | Dời hợp đồng: JOURNAL-* / ENTRY-META / EVIDENCE / JOB-LINK / BRAIN-ACCESS / BRAIN-RESOURCE-LINK / BRAIN-MAP / DOC-REGISTER ⇒ `core/journal/contracts/`; TWIN-SHEET* / BRAIN-LEDGER ⇒ `core/sheet/contracts/`; TWIN-VAULT / VAULT-APPEND ⇒ `core/vault/contracts/`; sửa CONTRACT-INDEX | 20 §1 | CL | ☐ |

## W2 — cổng + tìm từ Vault + upload một cửa
| ID | Việc | Doc | Lane | Trạng thái |
|---|---|---|---|---|
| S148-C6 | `KG_Private_File_Store` = kho tệp của Vault (0.145, D148-14) ⇒ dời về `core/vault/includes/vault/`, giữ tên lớp | 50 | CL | ☐ |
| S148-C7 | `BizCity_Notebook_Port` (mặc định rỗng) | 20 §4 | CL | ☐ |
| S148-C8 | `knowledge.search` từ Vault theo Journal Index cho mọi principal + filter `bizcity_vault_knowledge_providers` | 20 §3 | CL | ☐ |
| S148-C9 | Thay K 224 dòng / 45 file + T 28 / 15 (10 §4) bằng cổng / guard; `--check=W2` xanh | 10 §4 | CL | ☐ |
| S148-C10 | Upload Zalo (personal + bot) ⇒ `BizCity_Vault_Upload::ingest_bytes()`; bỏ đường thẳng vào KG | 20 §6 | CL | ☐ |
| S148-C11 | Vault bắn `bizcity_twin_agent_packs_changed` khi thư mục / note đổi | 10 §5 | CL | ☐ |
| S148-C12 | `BizCity_TwinChat_Settings_Page` ⇒ `core/bizcity-llm` (sau Q148-6) | 30 §5 | CL | ⛔ |
| S148-U1 | 📚 Kho tri thức (`core/vault/app`): Brain map + Maturity · tab Sổ cái gọn · Hồ sơ tệp · tab Vault pool. Sổ cái đầy đủ (BrainLedger) = màn của `core/journal` (**không gộp**, D148-17). TwinChat chỉ mở link | 60 | UI | ⛔ Q148-5 |

## W3 — plugin `bizcity-twin-notebook`
| ID | Việc | Doc | Lane | Trạng thái |
|---|---|---|---|---|
| S148-N1 | Khung plugin: main file, `zalo-brain.json`, bootstrap guard, add-on locator, `BIZCITY_NOTEBOOK_LOAD` | 30 §2-3 | NB | ☐ |
| S148-N2 | Chuyển phần KG của `core/kg-hub` ⇒ `kg/` | 30 §2 | NB | ☐ |
| S148-N3 | Chuyển `modules/twinchat` (kể cả file chưa track) ⇒ `twinchat/`; `modules/twinkg` ⇒ `twinkg/` | 30 §2 | NB | ☐ |
| S148-N4 | `Port_Impl`, journal listener, knowledge provider, installer | 30 §4 | NB | ☐ |
| S148-N5 | graph_ask + phần KG của twinbrain (sau Q148-3) | 30 §2 | NB | ⛔ |
| S148-N6 | Cron + menu + `zalo_brain_apps` dời khỏi file chính | 30 §4 | NB | ☐ |

## W4 — gỡ khỏi lõi
| ID | Việc | Doc | Lane | Trạng thái |
|---|---|---|---|---|
| S148-C13 | Xoá bản cũ (`core/kg-hub` còn lại, `modules/twinchat`, `modules/twinkg`) khỏi lõi | 95 | CL | ☐ |
| S148-B1 | Cell: ca kiểm `brain.graph_ask` = `not_available` (không đổi mã) | 70 | BC | ☐ |

## Ca bằng chứng
| ID | Ca | Trạng thái |
|---|---|---|
| T148-1 | Golden không notebook: tải file Zalo ⇒ Guru trả lời có `[TL-n]` | ☐ |
| T148-2 | Tải file qua trang 📚 Kho tri thức ⇒ một dòng journal + Brain map thấy | ☐ |
| T148-3 | `vault.list` / `journal.find` qua MCP từ cell, request không admin ctx | ☐ |
| T148-4 | Bật notebook ⇒ tài liệu mới được học (hook), `brain.graph_ask` có `kg_highlight` | ☐ |
| T148-5 | Tắt notebook ⇒ không lỗi PHP, TwinChat menu ẩn, bảng KG còn nguyên | ☐ |
| T148-6 | T-GOLD-1 (golden scenario 4 câu) chạy lại sau W1, W2, W4 | ☐ |

## Log
| Thời điểm | Phiên | Việc |
|---|---|---|
| 2026-11-12 08:38 PM | W0 | Mở phase, census (2 lượt đọc mã), hồ sơ đủ khuôn; chưa đụng mã |
| 2026-11-12 08:58 PM | vòng 1 | Chủ: Vault = `core/skills`. Truy vết 0.120 → 0.147 (doc 15, 12 mâu thuẫn), doc 50 (D148-2 v2, 2a–c, 7 v2, 12–14, Q148-9…11); đổi đích `core/vault` ⇒ `core/skills` ở mọi doc + selfcheck |
| 2026-11-12 09:17 PM | vòng 2 | Chủ: đổi tên `core/skills` ⇒ `core/vault` · tách `core/journal` + `core/sheet` · không gộp Sổ cái · giữ `bizcity/skill/v1`. Doc 60 (D148-15…19, W1a–d); checklist + selfcheck theo ba module |
