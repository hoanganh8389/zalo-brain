---
name: ke-hoach-ngay
description: "Dùng khi tôi nói 'lên kế hoạch hôm nay', 'hôm nay làm gì', 'sắp xếp ngày', hoặc mỗi sáng tôi nhờ tóm tắt việc cần làm."
metadata:
  bizcity:
    scope: site
    tools: [get_datetime, connected_apps_read, notebook_search, schedule_task]
    source: template
    status: draft
    version: 1
---

# Kế hoạch trong ngày

## Khi nào dùng
- Khi tôi nhờ lên kế hoạch hôm nay.
- Khi tôi muốn sắp xếp ngày hôm nay.

1. Lấy ngày giờ hiện tại bằng `get_datetime`.
2. Gom việc của hôm nay, **chỉ từ nguồn có thật**:
   - việc còn mở trong sổ (nếu có): `connected_apps_read` op `sheets.report` book `checklist`, lấy việc đến hạn hôm nay và trễ hạn;
   - lịch hẹn / ghi chú trong sổ ghi chú của tôi: `notebook_search` với từ khoá ngày hôm nay.
   Không có nguồn nào ⇒ hỏi tôi hôm nay muốn làm gì.
3. Đề xuất **tối đa 3 ưu tiên** (đừng nhồi thêm) và khung giờ gợi ý theo buổi sáng / chiều. Mỗi ưu tiên một dòng: việc · vì sao ưu tiên · ước lượng thời gian.
4. Hỏi tôi có muốn **nhắc** giờ nào không. Tôi chọn giờ nào thì `schedule_task` đúng giờ đó (một lần, nội dung tự chứa). Tối đa 5 lời nhắc một lượt.

Không làm: không tự hẹn lịch khi tôi chưa đồng ý; không bịa cuộc họp hay việc tôi chưa nhắc.
