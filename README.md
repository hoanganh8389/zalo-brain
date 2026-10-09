# Zalo Brain

<!-- [2026-10-09 Johnny Chu - Chu Hoàng Anh] PHASE-0.96 S96-6.2 (D96-13) — README chuẩn của Zalo Brain (thay README "BTCare Twin AI"; bản cũ giữ ở máy). -->

**Bộ não Zalo của WordPress.** Mọi số Zalo của doanh nghiệp được một trợ lý tiếp nhận, trả lời, ghi nhớ và giao việc — ngay trong WordPress.

*All Channel, One Brain.*

[![Version](https://img.shields.io/badge/version-1.4.0-orange)](bizcity-twin-ai.php)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-777BB4)](https://www.php.net/)
[![WordPress](https://img.shields.io/badge/WordPress-6.0%2B-21759B?logo=wordpress&logoColor=white)](https://wordpress.org/)
[![License](https://img.shields.io/badge/license-GPL--2.0--or--later-2ea44f)](LICENSE)

[Dùng thử](https://libedemo.bizcity.vn/twin/) · [Lịch sử phát hành](CHANGELOG.md) · [Bảo mật](SECURITY.md) · [Đóng góp](CONTRIBUTING.md)

---

## 1. Zalo Brain là gì

Một plugin WordPress biến các số Zalo (Zalo Cá nhân, Zalo OA, Zalo Bot) của doanh nghiệp thành **một trợ lý duy nhất**. Tin nhắn đến từ số nào cũng về một sổ cái; một bộ não (Biz Central Brain, chạy ở máy chủ của BizCity) đọc, trả lời bằng kiến thức của chính doanh nghiệp, ghi nhớ theo từng số, và tự giao việc hoặc nhắc lịch.

## 2. Ra đời để làm gì

Doanh nghiệp Việt bán hàng và chăm khách trên Zalo, nhưng kiến thức lại nằm rải rác trong điện thoại của từng nhân viên. Khách hỏi lúc nửa đêm thì không ai trả lời; nhân viên nghỉ việc thì lịch sử khách đi theo. Zalo Brain gom tất cả về **một não, một kho, một lịch** trên website của chính doanh nghiệp.

## 3. Giúp ai

- **Chủ shop, chủ doanh nghiệp nhỏ** — cài một lần, trợ lý trả lời khách 24/7 bằng giọng của shop, nhắn trợ lý như nhắn một nhân viên.
- **Nhân viên có số Zalo riêng** — tự gắn số của mình vào trợ lý, vẫn nhận tin như cũ, trợ lý trả lời giúp khi bận và ghi lại mọi việc.
- **Đại lý triển khai** — dựng cho khách trong vài phút, mở rộng bằng plugin thay vì sửa lõi.

## 4. Giá trị

- **Trả lời ngay** — khách nhắn số nào cũng được trả lời, có dẫn nguồn từ tài liệu của shop (`/gpt/`).
- **Nhớ theo từng số** — tài liệu, ghi nhớ chủ gửi qua Zalo được lưu vào sổ tay của số đó (`/twinchat/`).
- **Giao việc và báo kết quả** — trợ lý tạo nhắc việc, chạy đúng giờ, báo xong hay lỗi (`/scheduler/`).
- **Mở rộng bằng kịch bản** — đăng web, đăng fanpage, chăm khách theo kịch bản (`/flow/`).

## 5. Sứ mệnh

**Doanh nghiệp được điều phối hoàn toàn bởi trợ lý.** Chủ nói với trợ lý; trợ lý điều phối các số Zalo của nhân viên, website, fanpage, lịch và kho. Các kênh khác — website, Facebook/Messenger, Telegram, email — là **công cụ làm việc** của trợ lý, không phải những hệ thống riêng phải học.

## 6. Cài trong 4 bước

Mở `/gpt/` trên site của bạn sau khi kích hoạt plugin:

1. **Kết nối tài khoản BizCity** — dán khoá 1API, hệ thống tự kiểm tra.
2. **Kết nối máy chủ Zalo** — tự kiểm tra, không cần chọn gì.
3. **Quét QR bằng Zalo** — số của bạn được gắn với tài khoản đang đăng nhập.
4. **Chọn Agent Guru** — đã chọn sẵn trợ lý mặc định; bấm "Bắt đầu" là trò chuyện được ngay.

Không cần multisite, không cần cấu hình máy chủ.

<p align="center"><a href="https://libedemo.bizcity.vn/gpt/"><img src="https://media.bizcity.vn/uploads/sites/1258/2026/05/Screenshot-2026-05-06-003857-scaled.png" alt="Zalo Brain — trợ lý trên /gpt/" width="820"></a></p>

## 7. Bảy bề mặt

| Đường dẫn | Tên | Việc |
|---|---|---|
| `/gpt/` | Trợ lý | Cài đặt 4 bước rồi nói chuyện ngay với trợ lý |
| `/gateway/` | Cấu hình Zalo | Số Zalo, Agent Guru, công cụ (web, fanpage, Telegram, email), vận hành |
| `/crm/` | Đội Zalo | Hộp thư theo số, liên hệ, đội và phạm vi của từng người |
| `/twinchat/` | Sổ tay | Kho kiến thức theo từng số Zalo và chủ số |
| `/scheduler/` | Lịch & nhiệm vụ | Việc trợ lý nhận về, kết quả xong/lỗi, nhật ký |
| `/flow/` | Kịch bản | Mở rộng khả năng làm việc của trợ lý (plugin Automation) |
| `/setting/` | Cài đặt | Khoá, máy chủ, giao diện, thành viên, mở rộng |

## 8. Mở rộng: thêm plugin, không sửa lõi

Plugin mở rộng chính thức:

| Plugin | Thêm gì |
|---|---|
| **Zalo Brain CRM** (`bizcity-twin-crm`) | Pipeline, giao việc, SLA, báo cáo, chiến dịch, broadcast, hoá đơn, CSKH tự động |
| **Automation** (`bizcity-automation`) | Kịch bản cho trợ lý: đăng web, fanpage, chăm khách |

Viết plugin mở rộng theo hợp đồng **`zalo-brain-extension@1`**:

1. Header `Requires Plugins: bizcity-twin-ai` và `Network: false`.
2. File `zalo-brain.json` ở gốc plugin:

```json
{
  "contract": "zalo-brain-extension@1",
  "id": "my-plugin",
  "name": "My Plugin",
  "version": "1.0.0",
  "requires": { "zalo-brain": ">=1.4.0" },
  "features": [ "my_feature" ],
  "surfaces": [ { "surface": "crm", "pages": [ "my-page" ] } ]
}
```

3. Đăng ký trong `plugins_loaded` (trước `zalo_brain_loaded`, ưu tiên 20):

```php
add_action( 'plugins_loaded', static function () {
	if ( ! class_exists( 'BizCity_Zalo_Brain' ) ) {
		return; // Zalo Brain chưa kích hoạt: không nạp gì.
	}
	$manifest = json_decode( (string) file_get_contents( __DIR__ . '/zalo-brain.json' ), true );
	BizCity_Zalo_Brain::register_extension( $manifest );
}, 6 );
```

4. Lõi hỏi `BizCity_Zalo_Brain::has( 'my_feature' )` trước khi dùng tính năng của bạn; khi vắng, lõi trả lỗi `feature_unavailable` có đủ `message`, `hint`, `help_code`.
5. Chỉ dùng các hook được công bố: `zalo_brain_register`, `zalo_brain_loaded`, `zalo_brain_surfaces`, `zalo_brain_boot_dto`, các hook sổ cái `bizcity_crm_message_persisted`, `bizcity_crm_contact_saved`, `bizcity_crm_install_tables`, `bizcity_crm_register_adapters`, kênh `bizcity_channel_normalized`, `bizcity_channel_outbound_logged`, MCP `bizcity_mcp_register_tools`. Mọi hook khác là nội bộ và có thể đổi.

Xem trạng thái của site: `GET /wp-json/bizcity/v1/zalo-brain`.

## 9. Kiến trúc trong một hình

```
Zalo Cá nhân · Zalo OA · Zalo Bot (kênh chính)        web · fanpage · Messenger · Telegram · email (công cụ)
                 │                                                        │
                 ▼                                                        ▼
        core/channel-gateway  ── envelope chuẩn ──►  core/crm  (sổ cái: liên hệ · hội thoại · tin nhắn · phạm vi)
                                                          │
                                                          ▼
                                   Hub BizCity ──► Biz Central Brain (trợ lý: suy nghĩ, trả lời, chọn công cụ)
                                                          │ MCP
                                                          ▼
        core/mcp ◄── core/kg-hub · core/context-bank (95 % tri thức) · core/scheduler (lịch nhiệm vụ)
```

Ba bất biến: **một não** (mọi lượt trả lời ở Biz Central Brain), **một sổ cái** (mọi tin vào `core/crm`, định danh người lấy từ não), **một cửa** (não vào site chỉ qua MCP).

## 10. Giấy phép và tác giả

Phát hành theo [GPL-2.0-or-later](LICENSE). Thương hiệu: xem [TRADEMARK.md](TRADEMARK.md).

**Tác giả:** Biz Central Brain — Johnny Chu (Chu Hoàng Anh). *Bizcity Central Brain — Hệ thống Não Chúa Đa-site Phân tán*, Giấy chứng nhận đăng ký quyền tác giả số 8877/2026/QTG.

- Cộng đồng Zalo: [zalo.me/g/0r4gp7hf4213svceflmw](https://zalo.me/g/0r4gp7hf4213svceflmw)
- Website: [bizcity.vn](https://bizcity.vn)
- Liên hệ: `hoanganh.itm@gmail.com`
