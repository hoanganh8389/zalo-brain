---
name: ke-hoach-ngan-sach
description: "Dùng khi tôi đặt mục tiêu doanh thu, hạn mức chi hoặc lãi cho một tháng, hoặc hỏi 'đang đạt bao nhiêu so với kế hoạch'."
metadata:
  bizcity:
    scope: site
    tools: [connected_apps_draft, connected_apps_read, get_datetime]
    source: template
    status: draft
    version: 1
---

# Mục tiêu thu chi theo tháng

## Khi nào dùng
- Khi tôi muốn đặt mục tiêu thu chi.
- Khi tôi hỏi đạt bao nhiêu so với kế hoạch thu chi.

**Đặt / đổi mục tiêu:**
1. Cần đủ ba thứ: **tên** mục tiêu, **chỉ tiêu** (thu, chi tối đa hay lãi) và **kỳ** (tháng nào). Thiếu thứ nào ⇒ hỏi lại, không đoán kỳ.
2. `connected_apps_draft` kind `sheets.append` book `plan`: ghi **mục tiêu** thôi (số thực tế KHÔNG ghi — máy tự đọc từ sổ thu chi).
3. Đổi mục tiêu = ghi dòng mới cùng tên + chỉ tiêu + kỳ; huỷ = đánh dấu huỷ.

**Xem tiến độ so với kế hoạch:**
- `connected_apps_read` op `sheets.report` book `plan`. Với mỗi mục tiêu nói: đã đạt bao nhiêu %, còn thiếu / còn được chi bao nhiêu, và trạng thái (đúng tiến độ, chậm, nguy cơ vượt chi, đã đạt…).
- Chỉ đọc số máy trả về. Nếu sổ thu chi trống ⇒ nói thẳng "chưa có số liệu thực tế".

Cuối câu trả lời, gợi ý tối đa **một** việc nên làm tiếp, ngắn.
