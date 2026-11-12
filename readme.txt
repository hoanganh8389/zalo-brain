=== Twin Brain ===
Contributors: hoanganh8389
Tags: second brain, knowledge base, ai assistant, zalo, rag
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.4.0
Text Domain: bizcity-twin-ai
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Khung second brain cho WordPress: sổ ghi, kho tri thức, trợ lý theo từng người dùng; mở rộng bằng plugin.

== Description ==

<!-- [2026-10-25 Johnny Chu - Chu Hoàng Anh] PHASE-0.138 S138-OPS2 — mẫu 90-RELEASE-PACKAGE §3; mục "Dịch vụ bên ngoài" theo R-CSB CSB-8. -->

Biến WordPress thành bộ não thứ hai của từng người: lưu và tổ chức tệp, ghi chú,
tài liệu; hỏi lại bằng ngôn ngữ tự nhiên có trích dẫn; mở rộng bằng plugin của bạn.

Plugin chạy trên site của bạn: tệp gốc, vector và sổ ghi ở lại site. Phần "nghĩ"
(đọc tài liệu, hiểu, trả lời) chạy ở dịch vụ BizCity, gọi tới bằng một khoá `biz-`.
Dùng được với Zalo và Facebook (kênh khách) khi site có tên miền thật.

= Cần tên miền thật có https =

Thử cài, tải tài liệu, hỏi đáp có trích dẫn và viết plugin mở rộng chạy được ngay
trên máy (localhost). Kênh Zalo / Facebook, kết nối một chạm, gói trả phí và việc
chạy nền thì **cần tên miền thật có https**: BizCity xử lý xong phải gọi ngược về
site của bạn qua https để giao kết quả (câu trả lời, tin nhắn khách, trạng thái việc).
Máy cá nhân (`localhost`, `127.0.0.1`, `*.local`) không có địa chỉ công khai để
BizCity gọi tới, nên phần đó luôn báo lý do thay vì hỏng im lặng.

Cách nhanh nhất để có tên miền: **tạo site qua trang đăng ký của BizCity**
(https://bizcity.vn). Site có sẵn tên miền và được tặng **1 TB** lưu trữ. Muốn tự
cài: một host WordPress bình thường có tên miền và chứng chỉ https là đủ.

== Dịch vụ bên ngoài ==

Plugin này KHÔNG gọi ra ngoài cho tới khi quản trị site nhập khoá.

1. BizCity Hub (https://bizcity.vn) — khi bạn nhập khoá `biz-`.
   Dùng cho: đọc tài liệu (OCR / Vision, giọng nói thành chữ), vector hoá và truy xuất
   (Embedding / RAG), trợ lý second brain (kể cả tìm kiếm web), kênh Zalo / Facebook.
   Gửi đi: nội dung bạn yêu cầu xử lý (văn bản, tệp hoặc đường link tệp), địa chỉ site,
   mã người dùng đã băm. Vector và tệp gốc lưu ở site của bạn.
   Điều khoản: <link> · Quyền riêng tư: <link>
2. OpenRouter (https://openrouter.ai) — chỉ khi quản trị site nhập khoá OpenRouter của
   chính mình (⚡ LLM Settings). Dùng cho tool sáng tạo: tạo ảnh, giọng nói, video, soạn
   nội dung tài liệu. Gửi đi: lời nhắc (prompt) và nội dung cần xử lý. Khoá chỉ lưu trên site.
   Điều khoản: https://openrouter.ai/terms · Quyền riêng tư: https://openrouter.ai/privacy

== Installation ==

1. Upload zip qua Plugins → Add New → Upload Plugin, rồi Activate.
2. Lấy khoá miễn phí tại https://bizcity.vn, dán vào trang Kết nối.
3. Chạy thử trên máy (localhost)? Đọc docs/developer/CELL-SERVICES.md mục "Chạy thử trên máy".

== Source code ==

Mã nguồn đầy đủ (kể cả mã frontend trước khi build): https://github.com/hoanganh8389/zalo-brain

== Changelog ==

= 1.4.0 =
* Bản phát hành cộng đồng đầu tiên trên WordPress.org với tên Twin Brain.
