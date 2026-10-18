---
name: ghi-so-thu-chi
description: "Dùng khi tôi nhắn một hoặc nhiều khoản thu / chi (vd 'chi 850k mua văn phòng phẩm', 'thu 5 triệu tiền hàng') hoặc nhờ ghi sổ."
metadata:
  bizcity:
    scope: site
    tools: [connected_apps_draft, connected_apps_read, get_datetime]
    source: template
    status: draft
    version: 1
---

# Ghi sổ thu chi

## Khi nào dùng
- Khi tôi nhờ ghi sổ thu chi.
- Khi tôi muốn ghi một khoản chi.
- Khi tôi muốn ghi một khoản thu.

Khi tôi nhắn một hoặc nhiều khoản thu / chi (có thể nhiều dòng trong một tin), hãy:

1. Tách từng khoản: **loại** (thu hay chi), **số tiền**, **ngày** (không nói thì là hôm nay — lấy bằng `get_datetime`), **nhóm** (ăn uống, đi lại, hàng hoá, lương…), **ghi chú**, **người nhận / trả** nếu có.
2. Số tiền thiếu hoặc mơ hồ ("vài trăm", "kha khá") ⇒ **hỏi lại**, không đoán.
3. Soạn bản nháp bằng `connected_apps_draft` (kind `sheets.append`) để tôi duyệt. Nhiều khoản ⇒ một bản nháp, nhiều dòng.
4. Chỉ nói "đã ghi" khi công cụ duyệt trả kết quả **đã ghi**. Chưa duyệt thì nói rõ "đang chờ bạn duyệt".
5. Sau khi ghi, trả lời đúng một dòng cho mỗi khoản: ngày · thu/chi · số tiền · nhóm.
6. Chưa có sổ ⇒ máy tự soạn việc tạo sổ để tôi duyệt một lần; đừng tự bịa đường dẫn bảng tính.

Không làm: không tự điền phương thức thanh toán hay mục đích tôi chưa nói; không tự cộng tổng; không ghi hai lần cùng một tin.
