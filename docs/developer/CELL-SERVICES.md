# Zalo Brain cung cấp gì, bạn tự lo gì

<!-- [2026-10-25 04:00 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.138 — bản công khai của R-CELL-SERVICE-BOUNDARY; không trỏ tới repo riêng hay tài liệu nội bộ (Q103-8). -->
<!-- [2026-10-25 Johnny Chu - Chu Hoàng Anh] PHASE-0.138 W1–W5 — cập nhật theo mã: khoá OpenRouter riêng, thẻ dịch vụ, chạy thử trên máy (bảng = BizCity_Site_Environment::dev_limits()). -->

> Dành cho lập trình viên cài Zalo Brain (trên WordPress.org: **Twin Brain**) và xây plugin trên nó. Đọc trước khi viết tool, để biết việc nào gọi dịch vụ của BizCity, việc nào bạn tự cắm nhà cung cấp.

Zalo Brain là khung để bạn dựng **bộ não thứ hai** (second brain) trên WordPress: lưu và tổ chức lại kho tệp, ghi chú, tài liệu; hỏi lại bằng ngôn ngữ tự nhiên có trích dẫn; mở rộng bằng plugin của bạn. Plugin chạy trên site của bạn. Phần "nghĩ" (đọc tài liệu, hiểu, trả lời) chạy ở **cell** của BizCity, bạn gọi tới bằng một khoá `biz-`.

Trang **⚡ LLM Settings** có thẻ **"Dịch vụ: BizCity cung cấp gì, bạn tự lo gì"** tóm tắt đúng bảng dưới đây cho site của bạn.

## 1. Ba nhóm dịch vụ

| Nhóm | Gồm | Ai cung cấp | Trả tiền |
|---|---|---|---|
| **A · Cell cung cấp** | **OCR / Vision** — đọc tài liệu, ảnh, ghi âm thành chữ; trích xuất, phân loại · **Embedding / RAG** — vector hoá, lập chỉ mục, truy xuất ngữ cảnh · **Second Brain Assistant** — hỏi đáp, trí nhớ, tìm kiếm (cả tìm kiếm web), hiểu ngữ cảnh, điều phối việc · **Kênh** — Zalo, Facebook, chat web | BizCity, qua khoá `biz-` | theo gói / ví của khoá. Có gói Free |
| **B · Bạn tự chọn** | **Lưu trữ**: tệp gốc, vector, sổ ghi đều nằm ở site của bạn | bạn | bạn |
| **C · Tool sáng tạo** | tạo ảnh, giọng nói, video, nhạc, tài liệu | site tạo qua đăng ký BizCity: BizCity, qua khoá `biz-`. Site tự cài: **OpenRouter bằng khoá của chính bạn** | site BizCity: ví của khoá. Site tự cài: bạn trả thẳng cho OpenRouter |

Ba điều không đổi:

- Nhóm A luôn đi qua khoá `biz-`. Không có cách chạy OCR / RAG / trợ lý bằng khoá OpenRouter riêng.
- Khoá OpenRouter của bạn chỉ nằm trong database site của bạn, chỉ PHP phía server đọc. Nó không được gửi lên BizCity, không hiện ra trình duyệt, không vào log.
- Mọi lần một tool chạy, nhóm nào cũng vậy, để lại một dòng sổ việc trên site của bạn. Dòng sổ ghi việc đã làm; tiền bạn trả cho OpenRouter không nằm trong sổ của BizCity.

## 2. Tool sáng tạo bằng khoá OpenRouter của bạn (site tự cài)

1. Tạo khoá tại https://openrouter.ai (dạng `sk-or-…`), nạp tiền ở đó.
2. Mở **⚡ LLM Settings** → thẻ **"Khoá OpenRouter của bạn"** → dán khoá → **Lưu** → **Kiểm tra**.
3. Từ đó các tool sáng tạo của Zalo Brain (và của plugin xây trên nó) gọi OpenRouter bằng khoá này.

| Tool | Qua OpenRouter | Ghi chú |
|---|---|---|
| Tạo ảnh | có | mặc định `google/gemini-2.5-flash-image`; ảnh trả về được lưu vào Media |
| Giọng nói (đọc văn bản) | có | mặc định `openai/gpt-4o-mini-tts-2025-12-15` |
| Video | có | mặc định `google/veo-3.1`; chạy nền, xong thì tải về Media |
| Soạn nội dung tài liệu | có | mô hình chữ mặc định `openai/gpt-4o-mini`. Xuất ra file Word / Excel / PowerPoint chưa có trong bản này |
| Nhạc, ghép mặt / thử đồ | chưa | OpenRouter chưa có; plugin mở rộng có thể tự cắm nhà cung cấp qua filter `bizcity_byok_provider` |

Chưa cài khoá thì tool sáng tạo báo **"Chưa cài khoá OpenRouter"**, không âm thầm tính tiền vào khoá `biz-`. Đổi mô hình: option `bizcity_byok_model_{text|image|tts|video}` hoặc filter `bizcity_byok_model`.

## 3. Lưu trữ

| Bạn cài theo cách nào | Dung lượng |
|---|---|
| Tạo site qua trang đăng ký của BizCity (site trong mạng multisite BizCity) | BizCity tặng **1 TB**, hiện trên Bảng nhanh (con số để bạn theo dõi, không chặn tải lên) |
| Tự cài plugin lên host của bạn (self-host) | bạn tự lo: dung lượng là ổ đĩa của host, hoặc kho mà plugin offload của bạn trỏ tới. Bảng nhanh chỉ hiện "đã dùng" |

Zalo Brain không có cấu hình lưu trữ riêng: tệp đi qua thư viện Media và thư mục `uploads` của WordPress. Muốn đẩy tệp lên S3 / R2 / NAS / Google Drive thì cài một plugin offload độc lập. Cell chỉ đọc tệp để trích nội dung; tệp gốc, vector và sổ ghi ở lại site của bạn.

Lưu ý khi dùng plugin offload: chỉ mục vector nằm ở `wp-content/uploads/twin-vectors/` dưới dạng tệp thường, không phải mục Media, nên vẫn ở trên đĩa của host. Khi sao lưu hay chuyển host, nhớ mang theo thư mục này.

## 4. Bắt đầu

1. Cài plugin, kích hoạt. Tên thư mục tuỳ ý (`bizcity-twin-ai/`, `twin-brain/`, `zalo-brain-main/` từ GitHub đều chạy).
2. Site có tên miền thật và https: bấm **Kết nối BizCity** để lấy khoá một chạm. Site thử trên máy: lấy khoá miễn phí tại https://bizcity.vn, rồi dán vào ô API key ở trang **BizCity LLM — AI Gateway Configuration** (menu **⚡ LLM Settings**).
3. Bấm **Test Connection**. Gói (Free / Pro / Premium) cập nhật khi bạn mở lại trang này (tối đa 5 phút một lần) và theo WP-cron.
4. Viết plugin của bạn theo [README.md](README.md). Tool nhóm A gọi qua các facade có sẵn của Zalo Brain; tool sáng tạo gọi qua lớp `BizCity_BYOK` hoặc filter `bizcity_byok_provider`. Không tự gọi API của nhà cung cấp AI từ plugin, và không tự lưu khoá AI khác.

## 5. Bạn cần gì để dùng

| Bạn muốn | Cần tên miền thật có https? |
|---|---|
| Thử cài, tải tài liệu, hỏi đáp có trích dẫn, viết plugin mở rộng | **Không** — chạy được trên máy (mục 6) |
| Dùng trợ lý trên web hằng ngày | **Nên có.** Trên máy trợ lý vẫn trả lời và lưu câu trả lời; nhưng việc chạy nền, nhắc việc, tệp trợ lý tạo ra không về được máy |
| Trợ lý trên Zalo, Facebook (kênh khách, trợ lý của chủ qua Zalo) | **Bắt buộc** |
| Mua gói trả phí | **Bắt buộc** |

**Vì sao cần tên miền thật cho Zalo và cho trợ lý dùng hằng ngày:** BizCity xử lý xong phải **gọi ngược lại site của bạn** để giao kết quả — tin nhắn khách gửi qua Zalo / Facebook, trạng thái một việc vừa chạy xong, tệp trợ lý vừa tạo, lời nhắc đến giờ. Lời gọi ngược đó luôn đi qua https tới đúng tên miền bạn đã đăng ký với khoá. Máy cá nhân (`localhost`, `127.0.0.1`, `*.local`, `*.test`) không có địa chỉ mà BizCity gọi tới được, nên phần "gọi ngược" luôn thất bại trên máy — không phải do cấu hình sai, mà do máy không nhận kết nối từ bên ngoài. Tên miền thật + https là điều kiện cho mọi thứ cần đường hai chiều.

Cách nhanh nhất để có tên miền: **tạo site qua trang đăng ký của BizCity**. Site có sẵn tên miền và được tặng **1 TB lưu trữ**. Muốn tự cài: một host WordPress bình thường có tên miền và chứng chỉ https (Let's Encrypt miễn phí) là đủ.

## 6. Chạy thử trên máy (localhost)

Khi site chạy trên máy (`localhost`, IP, `*.local`, `*.test`, `*.localhost`, `*.lan`…), trang **⚡ LLM Settings** hiện thẻ **"Đang chạy thử trên máy"** với đúng bảng này. Tính năng không chạy được sẽ báo lý do và cách sửa, không hỏng im lặng.

| Việc | Trên máy | Ghi chú |
|---|---|---|
| Notebook, sổ ghi, kho tri thức | ✅ | chạy hoàn toàn trên site |
| Dán khoá `biz-` và Test Connection | ✅ | gói Free; tối đa 2 khoá thử nghiệm mỗi tài khoản |
| Tải PDF / tài liệu ⇒ OCR ⇒ vào kho | ✅ | nội dung tệp được gửi kèm lời gọi |
| Embedding / RAG, hỏi có trích dẫn | ✅ | máy cần ra được internet tới bizcity.vn |
| Trợ lý trả lời trên web | ✅ | câu trả lời được lưu ngay trên site |
| Kết nối một chạm | ❌ | dán khoá vào ô API key |
| Mua gói trả phí | ❌ | gói trả phí cần tên miền thật |
| Phân tích ảnh / ghi âm theo đường link | ❌ | BizCity không tải được tệp từ máy của bạn |
| Tệp cell tạo ra, việc chạy nền báo về site | ❌ | BizCity phải gọi ngược về site qua https |
| Kênh Zalo, Facebook | ❌ | cần tên miền thật có https |

Mẹo:

- **Cài trong thư mục con hay permalink "Plain" đều được.** Plugin tự đổi các lời gọi `/wp-json/…` sang đúng địa chỉ REST của site.
- **Đừng dùng khoá của site thật trên máy.** Lời gọi vẫn chạy, nhưng tính tiền vào ví của site thật.
- **XAMPP / WAMP trên Windows:** plugin tự dùng chứng chỉ CA của WordPress khi PHP chưa cấu hình `curl.cainfo`. Nếu vẫn lỗi TLS, thêm vào `php.ini`: `curl.cainfo = "<thư mục WordPress>\wp-includes\certificates\ca-bundle.crt"` rồi khởi động lại Apache.
- **Đã đặt `DISABLE_WP_CRON`?** Mở lại trang ⚡ LLM Settings để đồng bộ gói, hoặc chạy `wp cron event run --due-now`.
- **Muốn thử kênh:** dùng một tên miền thật có https trỏ về máy (ví dụ Cloudflare Tunnel với tên miền của bạn). Đừng dùng tên miền tạm đổi mỗi lần chạy: khoá ràng theo tên miền.

## 7. Câu hỏi thường gặp

**Tôi có phải trả tiền để thử không?** Không. Gói Free đủ để thử nhóm A trên máy và trên site thật.

**Tôi có thể tự chạy cell không?** Không trong bản này. Cell là dịch vụ của BizCity; plugin là mã nguồn mở (GPL-2.0-or-later).

**Plugin có gửi dữ liệu đi khi tôi chưa nhập khoá không?** Không. Chưa có khoá `biz-` thì mọi lời gọi dịch vụ ngoài trả lỗi `no_api_key` ngay trên site; chưa có khoá OpenRouter thì không gọi OpenRouter.
