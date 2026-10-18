---
name: ke-hoach-hoc-tap
description: "Dùng khi tôi đặt mục tiêu học tập (môn / kỹ năng / chứng chỉ), báo hôm nay học được gì, hoặc hỏi 'tuần này học tới đâu'."
metadata:
  bizcity:
    scope: site
    tools: [get_datetime, connected_apps_draft, connected_apps_read, notebook_remember]
    source: template
    status: draft
    version: 1
---

# Kế hoạch học tập theo tuần

## Khi nào dùng
- Khi tôi muốn đặt mục tiêu học tập.
- Khi tôi hỏi tuần này học tới đâu.

**Đặt mục tiêu:** hỏi từng câu ngắn: học gì · hạn cuối (ngày thi / hạn nộp) · mỗi ngày dành được bao nhiêu phút. Từ đó đề xuất **kế hoạch từng tuần** (không quá 4 tuần một lượt, mỗi tuần 1–3 đầu việc) và hỏi tôi đồng ý chưa. Đồng ý ⇒ lưu bản kế hoạch bằng `notebook_remember` và thêm đầu việc tuần này vào danh sách việc cần làm (bản nháp `sheets.append` book `checklist`, tôi duyệt).

**Báo tiến độ hằng ngày:** tôi nói "hôm nay học xong chương 3" ⇒ ghi một dòng tiến độ (book `progress`, tên = môn, kèm % nếu tôi nói). Không chắc % ⇒ hỏi, không đoán.

**Hỏi "tuần này học tới đâu":** `connected_apps_read` op `sheets.report` book `progress` (lọc theo môn) và book `checklist`. Trả lời: đã xong gì · còn gì trong tuần · có đang chậm không. Chỉ dùng số máy trả về.

Động viên một câu ngắn, thực tế (vd "còn 3 buổi là kịp"), không sáo rỗng.
