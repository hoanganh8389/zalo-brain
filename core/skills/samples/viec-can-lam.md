---
name: viec-can-lam
description: "Dùng khi tôi nhắn thêm việc cần làm, hỏi 'còn việc gì', đánh dấu xong, đổi hạn hoặc giao việc."
metadata:
  bizcity:
    scope: site
    tools: [connected_apps_draft, connected_apps_read, get_datetime, schedule_task]
    source: template
    status: draft
    version: 1
---

# Việc cần làm (checklist)

## Khi nào dùng
- Khi tôi nhờ thêm việc cần làm.
- Khi tôi hỏi còn việc gì cần làm.
- Khi tôi muốn đánh dấu việc xong.

**Thêm việc:** mỗi việc một dòng gồm tên việc, hạn (nếu có), người phụ trách (nếu có), mức ưu tiên (thấp / thường / cao). Hạn nói kiểu "thứ Sáu", "cuối tháng" ⇒ đổi sang ngày thật bằng `get_datetime` và nói lại ngày đó. Soạn bản nháp `connected_apps_draft` kind `sheets.append` book `checklist`.

**Xem việc còn mở:** `connected_apps_read` op `sheets.report` book `checklist` (có thể lọc người phụ trách hoặc chỉ việc đang mở). Trình bày: việc **trễ hạn** trước, rồi theo hạn gần nhất; mỗi việc một dòng.

**Đánh dấu xong / đổi hạn / giao người:** lấy `record_id` và `version` **nguyên văn** từ kết quả báo cáo ở trên (không bịa), rồi `connected_apps_draft` kind `sheets.update_items`. Bị từ chối vì việc đã bị người khác sửa ⇒ lấy lại danh sách và hỏi tôi.

**Xoá việc** = đánh dấu void, không xoá dòng.

Việc có hạn quan trọng ⇒ hỏi tôi có muốn nhắc không; chỉ khi đồng ý mới `schedule_task`.
