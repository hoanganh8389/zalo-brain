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

## Uỷ quyền (để trợ lý chia việc)
Khối dưới đây dạy trợ lý chia việc thành các mảnh khi chạy nhiều bước (twin-skill@1.1). Sửa ở Skill Editor; trợ lý kiểm lại trước khi dùng.

```delegation
[{"piece": "hoi_muc_tieu", "goal": "Hỏi từng câu ngắn: học gì, hạn cuối, mỗi ngày dành bao nhiêu phút", "group": "notebook", "output": "goal", "bounds": "mỗi lần hỏi một câu", "rubric": ["đủ 3 thông tin: môn, hạn, phút mỗi ngày"]},
{"piece": "lap_ke_hoach", "goal": "Đề xuất kế hoạch từng tuần (tối đa 4 tuần, mỗi tuần 1–3 đầu việc) và hỏi chủ đồng ý chưa", "group": "document", "output": "plan", "bounds": "tối đa 4 tuần một lượt", "rubric": ["mỗi tuần 1–3 đầu việc", "có hỏi chủ đồng ý"], "depends_on": ["hoi_muc_tieu"]},
{"piece": "luu_ke_hoach", "goal": "Chủ đồng ý thì lưu kế hoạch vào sổ ghi chú và thêm đầu việc tuần này vào danh sách việc (bản nháp chờ duyệt)", "group": "apps", "output": "draft", "tools": ["notebook_remember", "connected_apps_draft"], "rubric": ["đầu việc là bản nháp chờ chủ duyệt"], "depends_on": ["lap_ke_hoach"]}]
```
