# BizCity Booking Starter — plugin mẫu của Zalo Brain

<!-- [2026-10-12 03:40 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.103 S103-L4 — mẫu chuẩn (Q103-11). -->

Một plugin lịch hẹn nhỏ, đủ để thấy **mọi thứ lập trình viên cần làm** để một plugin thành năng lực AI trên Zalo — và **không thứ gì** ngoài đó.

| Tệp | Việc |
|---|---|
| `bizcity-booking-starter.php` | header `Zalo Brain Addon:` + 1 đoạn đăng ký (một cửa) |
| `zalo-brain.json` | khai plugin: tính năng, 4 tool, 1 khối, 1 kịch bản |
| `mcp/tools.json` | 4 tool: `appointment.slots`, `appointment.status` (đọc) · `appointment.create`, `appointment.reschedule` (**ghi**: xác nhận + xem trước + `required_cap`) |
| `includes/class-booking-starter-tools.php` | 4 hàm xử lý + 2 hàm xem trước |
| `includes/class-booking-starter-service.php` | **dịch vụ lịch hẹn giả** (lưu bằng một option) — **thay bằng dịch vụ thật của bạn** |
| `includes/class-booking-starter-block.php` | khối Automation `appointment.check_slots` (chỉ đọc, an toàn cho khách) |
| `scenarios/booking-scenarios.json` | kịch bản "khách hỏi giờ trống ⇒ trả lời" |

## Dùng thử

1. Chép **cả thư mục** vào `wp-content/plugins/` (thư mục mẫu này nằm ngoài `plugins/` nên không tự kích hoạt) và kích hoạt. Cần plugin chính; thiếu thì plugin tự im.
2. Từ thư mục plugin Zalo Brain: `node developer/tools/validate-extension.mjs <đường-dẫn-thư-mục>` ⇒ phải **PASS**.
3. Bật `WP_DEBUG_LOG`: tool sai chuẩn chỉ để lại dòng log (thư viện chỉ log khi `WP_DEBUG` bật).
4. Chủ số/nhân viên nhắn Zalo "mai còn chỗ không" ⇒ Cell gọi `appointment.slots` (tool hiện trên cell sau tối đa ~60 giây).

## Đã kiểm gì, chưa kiểm gì (2026-10-12)

- ✔ validator hợp đồng PASS, 0 cảnh báo; bản sao cố ý hỏng bị bắt (thiếu `preview`, thiếu `required_cap`).
- ✔ `php -l` sạch; JSON hợp lệ.
- ✔ Test đơn vị nội bộ (10 ca, giả lập WordPress) trên **registry thật**: thư viện nhận 4 tool, **không trùng tên tool lõi**, tool ghi thiếu `preview` bị từ chối, xem trước không ghi → xác nhận ghi đúng một lần, giờ bị lấy giữa chừng, token của người khác bị từ chối, khách không gọi trực tiếp được.
- ✘ **chưa** chạy trên WordPress thật và **chưa** qua Cell/Hub. Test xanh là bằng chứng đơn vị, không phải bằng chứng chạy thật.

## Giới hạn cần biết

- **Khách không gọi được `appointment.create` trực tiếp** — danh sách tool cho khách là cố định (xem README lập trình viên §3). Kịch bản mẫu chỉ **đọc**. Cho khách đặt/đổi lịch cần quyết định bảo mật riêng của nền tảng.
- `appointment.status` chưa lọc theo người hỏi: plugin thật **phải** lọc theo `$ctx` để khách chỉ thấy lịch của chính mình.
- `required_cap` mẫu là `edit_posts`; đổi theo quyền thật của plugin bạn.
