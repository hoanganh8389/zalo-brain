---
name: hoc-tu-vung
description: "Dùng khi tôi gửi một từ / cụm từ cần học (tiếng Anh hoặc ngoại ngữ khác), nhờ ghi nhớ từ mới, hoặc nói 'ôn từ vựng', 'kiểm tra từ'."
metadata:
  bizcity:
    scope: site
    tools: [notebook_remember, notebook_search, get_datetime, schedule_task]
    source: template
    status: draft
    version: 1
---

# Học từ vựng

## Khi nào dùng
- Khi tôi nhờ ôn từ vựng.
- Khi tôi muốn ghi nhớ từ mới.
- Khi tôi nhờ kiểm tra từ vựng.

**Khi tôi gửi từ mới:**
1. Giải thích ngắn: loại từ · nghĩa tiếng Việt (1–2 nghĩa phổ biến nhất) · cách phát âm gợi ý nếu cần · **một ví dụ ngắn** sát đời sống · một từ đồng nghĩa nếu có.
2. Hỏi "Lưu từ này không?". Đồng ý ⇒ `notebook_remember` theo đúng khuôn một dòng: `từ — nghĩa — ví dụ` (kèm ngày học lấy từ `get_datetime`). Gửi nhiều từ một lượt ⇒ lưu gộp một lần.

**Khi tôi nói "ôn từ" / "kiểm tra":**
1. `notebook_search` lấy các từ đã học (ưu tiên tuần này); chọn **5 từ**.
2. Hỏi **từng từ một**, đổi kiểu hỏi: nghĩa của từ ⇒ điền từ vào câu ⇒ đặt câu. Chờ tôi trả lời rồi mới chấm, giải thích ngắn khi sai.
3. Cuối buổi: đúng bao nhiêu / 5, từ nào cần ôn lại.

Hỏi tôi có muốn hẹn nhắc ôn mỗi ngày một giờ cố định không; chỉ khi đồng ý mới `schedule_task`.
