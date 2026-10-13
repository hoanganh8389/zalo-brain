# Zalo Brain cho lập trình viên — bắt đầu ở đây
<!-- [2026-10-12 11:40 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.103 Q103-8 — chủ chốt MỘT cửa: nguồn sự thật = docs/developer/: gộp phần riêng của kit PHASE-0.104 (4 bước, hook có handlers) vào đây; developer/ ở gốc đã bỏ. -->

<!-- [2026-10-12 03:35 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.103 S103-L1/L3/L5/L6 — MỘT cửa vào cho lập trình viên (Q103-1, Q103-8). -->
<!-- [2026-10-12 11:05 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.103 Q103-8 — chủ chốt: thư mục này đi kèm repo công khai hoanganh8389/zalo-brain. Đã rà: bỏ mọi đường dẫn tới repo riêng (cell, Hub, plugin Automation), tài liệu nội bộ (docs/contracts, audit, ADR, mã rủi ro) và số dòng; trỏ tới bộ `developer/` (hợp đồng + trình kiểm) đi kèm repo. -->

> **Tôn chỉ:** biến plugin bình thường của bạn thành một năng lực AI bán được cho khách ngay trên Zalo. Bạn **không phải xây lại chatbot** và **không sửa lõi**: bạn khai báo tool, Zalo Brain lo hội thoại, định tuyến AI, định danh, quyền, trí nhớ/tri thức và kịch bản.

```
Khách nhắn Zalo ─► CELL (hiểu · chọn việc) ─► HUB (xác thực · hạn mức · định tuyến) ─► plugin CỦA BẠN (tool MCP)
```

**Mẫu chạy được:** [`starter/bizcity-booking-starter/`](starter/bizcity-booking-starter/) — plugin lịch hẹn (xem giờ trống · đặt lịch · đổi lịch · hỏi trạng thái). Đọc nó trước, rồi sao chép.
**Cùng thư mục này:** [`STANDARD.md`](STANDARD.md) (chuẩn khung v1) · [`AGENTS.md`](AGENTS.md) (cho trợ lý AI lập trình của bạn) · [`contracts/`](contracts/) (hợp đồng máy đọc được) · [`tools/validate-extension.mjs`](tools/validate-extension.mjs) (trình kiểm, Node không cần cài gì thêm) · `starter/` (plugin mẫu).

| Bạn viết | Zalo Brain lo |
|---|---|
| Hàm nghiệp vụ (đọc giờ trống, đặt lịch…) | Hiểu tin nhắn, chọn tool, hỏi lại khi thiếu thông tin |
| Một tệp JSON mô tả tool (`mcp/tools.json`) | Phân quyền theo vai, xem trước → xác nhận → ghi đúng một lần |
| Một tệp khai plugin (`zalo-brain.json`) | Nhật ký, chống chạy trùng, cách ly từng site/người dùng |
| **Một** hook đăng ký | Kênh Zalo, cell AI, Hub (khoá, hạn mức, model) |

## 0. Bốn bước

1. **Chép plugin mẫu** `starter/bizcity-booking-starter/` sang `wp-content/plugins/<plugin-của-bạn>/`; đổi tên thư mục, `id` trong `zalo-brain.json` (= tên thư mục) và tiền tố tên tool (`appointment.*` → `<nhóm-của-bạn>.*`).
2. **Khai tool** trong `mcp/tools.json` (dữ liệu) và viết handler PHP (mã). Tool **ghi** bắt buộc có `preview` (xem trước, không ghi gì).
3. **Đăng ký bằng hook tiêu chuẩn** (mục 1).
4. **Kiểm** trước khi cài: từ thư mục plugin của bạn, `node <thư-mục-zalo-brain>/docs/developer/tools/validate-extension.mjs .` (Node ≥ 18) ⇒ phải ra `PASS`. Sau khi kích hoạt: `GET /wp-json/bizcity/v1/zalo-brain` (đăng nhập) liệt kê plugin, tool, kịch bản của bạn.
## 1. Đăng ký tool ở đâu? — một đáp án

**Plugin của bạn: `zalo-brain.json` + `mcp/tools.json`.** Ba đường còn lại vẫn chạy nhưng không dành cho bạn:

```
Bạn muốn trợ lý Zalo gọi được việc của plugin bạn?
├─ Bạn là tác giả plugin ─────────────► mcp/tools.json + BizCity_MCP_Library::register()   ← ĐƯỜNG CỦA BẠN
│    ├─ tool chỉ ĐỌC ............. read_only: true
│    └─ tool GHI ................. read_only: false + confirm: "always" + preview + required_cap
├─ Bạn KHÔNG sửa được plugin đó ──────► core/mcp/adapters/<slug>.json  (chỉ tool ĐỌC, tối đa 8)
└─ Bạn đang viết lõi Zalo Brain ──────► BizCity_MCP_Tool_Registry::register()
```

Đừng đăng ký tool trong `core/intent` — đó là máy hội thoại cũ, không phải mặt MCP.

Khung tối thiểu của một plugin (xem mẫu để có mã đầy đủ):

```php
// Header: "Zalo Brain Addon: <tên>" và "Network: false". KHÔNG dùng "Requires Plugins".
add_action( 'plugins_loaded', static function () {
	if ( ! defined( 'BIZCITY_TWIN_AI_VERSION' ) || ! class_exists( 'BizCity_Zalo_Brain' ) ) { return; }   // thiếu plugin chính ⇒ im lặng
	add_action( 'zalo_brain_register', static function () {
		$m = BizCity_Zalo_Brain::register_from_manifest( __DIR__ . '/zalo-brain.json', $handlers );   // $handlers: tên tool => callable | [ 'handler' => …, 'preview' => … ]
		// Bản Zalo Brain cũ hơn bỏ qua tham số thứ hai ⇒ tự đăng ký thư viện tool:
		if ( $m && ! isset( $m['_mcp_registered'] ) && class_exists( 'BizCity_MCP_Library' ) ) {
			BizCity_MCP_Library::register( __DIR__ . '/mcp/tools.json', $handlers, $m['id'] );
		}
	} );
}, 6 );
```

## 2. Bốn mốc làm quen (mục tiêu thiết kế — chưa kiểm chứng)

| Mốc | Bạn làm được | Cách |
|---|---|---|
| **5 phút** | Kết nối Zalo Brain với một Twin Client thử | cài plugin chính, 4 bước cài ở `/gateway/`; xem `GET /wp-json/bizcity/v1/zalo-brain` |
| **15 phút** | Đăng ký tool đầu tiên, Cell gọi được | chép mẫu → đổi tên → `node docs/developer/tools/validate-extension.mjs <thư-mục>` phải **PASS** → kích hoạt |
| **30 phút** | Chọn một mẫu kịch bản có sẵn và bật | `scenarios/*.json` của mẫu (hỏi giờ trống), hoặc mẫu dựng sẵn của plugin Automation |
| **60 phút** | Đưa trợ lý mẫu vào môi trường thử của khách | chủ số/nhân viên gọi tool trực tiếp; khách đi qua kịch bản (§3) |

Đây là mục tiêu để thiết kế trải nghiệm, **chưa có ca đo nào**. Vượt mốc thì ghi nguyên nhân để sửa, đừng hạ mốc.

## 3. Ai gọi được tool của bạn — đọc kỹ

| Người nhắn | Gọi tool MCP trực tiếp? | Ghi chú |
|---|---|---|
| **Chủ số / nhân viên** (owner, staff) | **Có**, mọi tool đã đăng ký và đủ quyền | Cell chỉ gọi MCP cho chủ số/nhân viên của một số đã gắn với site |
| **Khách** (customer) | **Không** — chỉ một danh sách cố định: `knowledge.search` · `knowledge.get_passage` · `knowledge.list_notebooks` · `commerce.search_products` · `site.content.search` · `automation.list_scenarios` · `automation.run_scenario` | danh sách này nằm ở Cell, không nằm ở plugin của bạn |

**Hệ quả:** để khách đặt/đổi lịch, bạn **không** thêm tool vào danh sách khách. Bạn viết một **kịch bản Automation** bọc tool của mình; khách chạy qua `automation.run_scenario`. Mẫu có sẵn kịch bản đọc (hỏi giờ trống). Kịch bản **ghi** cho khách chưa được mở (đây là quyết định bảo mật của nền tảng).

Tool **ghi** luôn đi hai bước: gọi lần 1 ⇒ nhận bản xem trước + `confirm_token`; gọi lần 2 kèm token ⇒ mới ghi. Handler của bạn chỉ lo ghi; việc hỏi xác nhận do registry (`BizCity_MCP_Tool_Registry`) làm.

## 4. Những chỗ dễ vấp

1. **Tool sai chuẩn bị bỏ im lặng.** Registry từ chối rồi chỉ `error_log`; thư viện bỏ tool và **chỉ ghi log khi `WP_DEBUG` bật**. Luôn chạy trình kiểm trước, và bật `WP_DEBUG_LOG` khi thử.
2. **Cổng MCP không nạp ở mọi ngữ cảnh.** `core/mcp/bootstrap.php` chỉ được nạp khi `$_bizcity_admin_ctx` đúng (xem `bizcity-twin-ai.php`). Thử bằng REST (`bizcity-mcp/v1`), không thử bằng trang frontend.
3. **Tool mới hiện trên cell sau tối đa ~60 giây** (cell nhớ danh sách tool khoảng 60 giây, trừ khi Hub báo đổi).
4. **Trần gọi:** tool đọc 5 giây, tool ghi 8 giây. Tool chậm hơn sẽ bị cắt.
5. **AI Builder của plugin Automation dùng danh mục khối cố định** và **chưa có khối gọi tool MCP chung**. Muốn một bước kịch bản gọi dịch vụ của bạn, hãy gửi **khối riêng** qua `automation_blocks` trong `zalo-brain.json` (mẫu có `appointment.check_slots`); AI Builder chưa tự thấy khối đó.
6. **Khối Automation của bạn phải khai** `effect`, `guest_safe`, `required_cap` trong `meta()` — thiếu là trình kiểm **FAIL**.
7. **Đừng đặt tên tool trùng lõi.** Lõi đã có `booking.available_slots` · `booking.create` · `booking.cancel` · `booking.sync_job` (xem `core/mcp/includes/actions/`). Registry ghi đè theo tên, nên tool trùng tên sẽ **thay thế âm thầm** tool lõi (hoặc bị thay). Plugin mẫu dùng nhóm `appointment.*` vì lý do này; chọn nhóm riêng cho plugin của bạn (`<plugin>.<việc>`). Registry **ghi log** `tool <tên> re-registered with a different handler (A::x → B::y); the last registration wins` khi gặp trùng — tìm dòng đó trong `debug.log`; hành vi (đăng ký sau thắng) **chưa đổi**.
8. **Mã lỗi:** registry chỉ giữ các mã `MCP_*` đã biết (ví dụ `MCP_NOT_FOUND`, `MCP_QUERY_INVALID`, `MCP_BOOKING_SLOT_TAKEN`); mã tự đặt bị đổi thành `MCP_INTERNAL_ERROR` và đánh dấu có thể thử lại — agent sẽ thử lại vô ích. Xem danh sách mã trong `core/mcp/includes/class-mcp-error.php`.
9. **Token xác nhận của tool ghi gắn với người + tool + tham số, không gắn blog.** Trên multisite, ranh giới giữa các site dựa vào transient theo site của WordPress — **chưa kiểm trên multisite thật**. Đừng đưa dữ liệu nhạy cảm vào phần xem trước (`preview`) rộng hơn mức người gọi được thấy.
10. **Registry chuyển nguyên `args` cho handler** — nó không biết handler có tin một `tenant_id`/`blog_id` do mô hình điền hay không. **Handler của bạn không được đọc tenant từ tham số**; lấy từ ngữ cảnh WordPress/`$ctx`.

## 4b. Tri thức: `knowledge.*` là tên chính danh, `brain.*` là bí danh cũ

Lõi đăng ký hai bộ tên cho cùng việc đọc tri thức (`core/mcp/includes/class-mcp-tool-registry.php`):

| Dùng tên này | Việc | Ghi chú |
|---|---|---|
| `knowledge.list_notebooks` · `knowledge.search` · `knowledge.get_passage` | liệt kê / tìm / lấy passage | **chính danh**; cell và danh sách tool của khách dùng bộ này |
| `brain.get_citation_pack` | gói trích dẫn từ một snapshot truy xuất | tool riêng, không có bí danh |
| `brain.list_notebooks` · `brain.search` · `brain.get_passage` | như trên | **bí danh cũ** (`alias_of`), cùng handler; principal uỷ quyền (cell) **không thấy** chúng |

Hãy gọi `knowledge.*`. **Đừng xoá hay đổi bí danh `brain.*`** — plugin cũ còn có thể dùng. Hai bộ tên trả cùng passage vì dùng chung handler.

## 5. Kiểm tra trước khi nói "chạy rồi"

```
node docs/developer/tools/validate-extension.mjs <thư-mục-plugin>     # PASS = hợp đồng đúng (chạy từ thư mục plugin Zalo Brain)
php -l <từng tệp .php>
```

Mẫu: PASS, 0 cảnh báo; bản sao cố ý hỏng (bỏ `preview`, bỏ `required_cap`) bị trình kiểm bắt. Trình kiểm chỉ kiểm **tĩnh** — nó không thay thế việc chạy trên WordPress thật.

## 6. Tự thử khi chưa có Zalo thật

Bộ dữ liệu webhook Zalo giả **chưa được công khai**. Cách thử không cần Zalo: cài plugin mẫu, rồi gọi tool qua REST `bizcity-mcp/v1/mcp` bằng một khoá MCP (JSON-RPC `tools/list`, `tools/call`). Luồng ghi: gọi `appointment.create` lần 1 để lấy `confirm_token`, lần 2 kèm token để ghi.

### Lấy khoá MCP

Khoá MCP do quản trị site tạo, **chỉ hiện một lần**. Cách nhanh nhất: wp-admin → **Cấu hình Zalo (Channel Gateway) → Vận hành → tab “Khoá MCP & lập trình viên”** → thẻ **“Cho lập trình viên (Zalo Brain)”** (tab này chỉ hiện với người có quyền quản lý; đường dẫn thẳng: thêm `#/ops/mcp` vào địa chỉ trang Cấu hình Zalo):

1. **① Tạo khoá thử (chỉ đọc)** — khoá chỉ có scope `brain.read`, client `zb-selfcheck`. Khoá hiện ở khung “Key mới” bên dưới; chép ngay.
2. **② Kiểm tra khung** — chạy kiểm ngay trên trang (chỉ đọc): danh bạ khung, cổng MCP, tool khai báo mà bị bỏ im lặng, tên tool trùng, bí danh `brain.*`, tool cho khách, rò rỉ khoá. Có khoá thì kiểm thêm danh mục tool.
3. **③ Sao chép lệnh dán Console** — tuỳ chọn, nếu muốn chạy bản Console thay vì nút ②.
4. **Thu hồi** khoá ở bảng “Keys đã cấp” khi xong.

Muốn tạo khoá với scope hay notebook khác: thẻ “Tạo MCP API key” ngay dưới. Không dùng giao diện: `POST /wp-json/bizcity-channel/v1/mcp/keys` (cần quyền quản trị; trả `key` một lần) và `DELETE …/mcp/keys/<id>` để thu hồi. MCP chỉ nhận `Authorization: Bearer <khoá>`, không nhận cookie.

Cùng màn có hai tab **ChatGPT** và **Claude**: địa chỉ máy chủ MCP, các bước kết nối, và trạng thái khoá của từng client (kể cả khoá đã thu hồi và thời điểm thu hồi). Lưu ý: token OAuth của ChatGPT/Claude gắn với khoá `active` mới nhất của tài khoản — **thu hồi khoá ấy là ngắt kết nối** client; dọn khoá thử trước khi kết nối client thật.

## 7. Bảng tương thích phiên bản

| Thành phần | Phiên bản | Ghi chú |
|---|---|---|
| Plugin chính `bizcity-twin-ai` | 1.4.0 | `requires.zalo-brain >= 1.4.0` trong manifest |
| Hợp đồng khung | `zalo-brain-extension@1` (tính năng 1.1) | 1.1 còn **cảnh báo**; dự kiến thành **lỗi** ở 1.2: tool ghi thiếu `required_cap`/`no_standard_cap`, tool khai báo mà thiếu dấu `@mcp` trong mã |
| Thư viện tool | `bizcity-mcp-library@1.0.0` (+ khoá tuỳ chọn 1.1: `when_to_use` ≤ 200 ký tự, `need` tổng 100, `audience`) | tool ghi thiếu `when_to_use` ⇒ cảnh báo |
| Bộ bọc plugin | `bizcity-mcp-adapter@1.0.0` | chỉ tool đọc, ≤ 8 |
| Từ vựng tool | `tool-registry@1` · `bizcity-mcp-standard@1` | xem `contracts/` |
| Header plugin | `Zalo Brain Addon: <tên>` + `Network: false` | thay `Requires Plugins`; trình kiểm vẫn nhận header cũ |

**Di trú từ bản cũ:** (1) bỏ `Requires Plugins`, thêm `Zalo Brain Addon:`; nhận plugin chính bằng hằng `BIZCITY_TWIN_AI_VERSION`. (2) Chuyển tool đăng ký rời rạc sang `mcp/tools.json`. (3) Không ghi cứng `/bizcity-twin-ai/` — thư mục có thể bị fork/đổi tên.

## 7b. Hook được công bố (mọi hook khác là nội bộ, có thể đổi)

`zalo_brain_register` · `zalo_brain_loaded` · `zalo_brain_surfaces` · `zalo_brain_boot_dto` · `bizcity_crm_message_persisted` · `bizcity_crm_contact_saved` · `bizcity_crm_install_tables` · `bizcity_crm_register_adapters` · `bizcity_channel_normalized` · `bizcity_channel_outbound_logged` · `bizcity_mcp_register_tools`. Danh sách máy đọc được: `contracts/ZALO-BRAIN-HOOKS-v1.json`.

## 8. Đọc tiếp

[STANDARD.md](STANDARD.md) · [AGENTS.md](AGENTS.md) · [contracts/](contracts/) · [starter/bizcity-booking-starter/](starter/bizcity-booking-starter/README.md).
