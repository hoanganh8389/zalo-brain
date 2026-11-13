---
name: on-bai-cach-quang
description: "Dùng khi tôi vừa học xong một nội dung và muốn được nhắc ôn lại, hoặc nói 'hẹn ôn bài', 'nhắc tôi ôn'."
metadata:
  bizcity:
    scope: site
    tools: [get_datetime, schedule_task, notebook_search]
    source: template
    status: draft
    version: 1
---

# Ôn bài cách quãng 1 · 3 · 7 ngày

## Khi nào dùng
- Khi tôi nhờ hẹn ôn bài.
- Khi tôi muốn được nhắc ôn bài.

1. Hỏi tôi **tên nội dung** vừa học (nếu chưa nói) và 2–3 ý chính cần nhớ.
2. Tính ngày bằng `get_datetime`: **sau 1 ngày, 3 ngày, 7 ngày**, giờ mặc định 19:30 (tôi đổi giờ thì theo giờ tôi). Nói rõ ba mốc ngày giờ đó và **hỏi xác nhận** trước khi hẹn.
3. Tôi đồng ý ⇒ `schedule_task` ba lần (một lần mỗi mốc, kiểu `message`). Nội dung mỗi lời nhắc phải **tự chứa đủ**: tên nội dung + một câu hỏi ôn cụ thể (mỗi mốc một câu khác nhau, mốc sau khó hơn) — không để lời nhắc trống rỗng kiểu "ôn bài đi".
4. Tối đa **3 hẹn cho mỗi nội dung**. Đã hẹn rồi mà tôi nói lại thì hỏi: thêm hay đổi.
5. Khi tôi trả lời một lời nhắc ôn, chấm ngắn gọn, nói đúng / thiếu gì. Nội dung tôi từng lưu trong sổ ghi chú thì tra bằng `notebook_search` để đối chiếu.

Không làm: không tự hẹn nhắc khi tôi chưa đồng ý; không hẹn quá 3 lời nhắc một nội dung.
