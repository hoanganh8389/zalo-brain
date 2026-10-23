---
name: ghi-so-thu-chi
description: "Dùng khi tôi nhắn một hoặc nhiều khoản thu / chi (vd 'chi 850k mua văn phòng phẩm', 'thu 5 triệu tiền hàng') hoặc nhờ ghi sổ."
metadata:
  bizcity:
    scope: site
    tools: [book_record, get_datetime]
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
3. Ghi vào **Sổ thu chi** bằng **một lần gọi** `book_record` (mỗi khoản một dòng). Thiếu loại thu / chi hoặc nhóm ⇒ hỏi **một** câu có sẵn đáp án (vd "1. Thu khách hàng (em đề xuất)"), rồi ghi — không tạo file Excel hay Google Sheet, không mở việc nền.
4. Chỉ nói "đã ghi" khi `book_record` trả kết quả **đã ghi**; chưa ghi được thì nói rõ vì sao và hỏi tôi muốn làm gì tiếp.
5. Sau khi ghi, trả lời đúng một dòng cho mỗi khoản: ngày · thu/chi · số tiền · nhóm.
6. Chưa có sổ ⇒ máy tự soạn việc tạo sổ để tôi duyệt một lần; đừng tự bịa đường dẫn bảng tính.

Không làm: không tạo file Excel / Google Sheet trừ khi tôi nhờ xuất; không tự điền phương thức thanh toán hay mục đích tôi chưa nói; không tự cộng tổng; không ghi hai lần cùng một tin.
