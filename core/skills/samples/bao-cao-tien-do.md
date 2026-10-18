---
name: bao-cao-tien-do
description: "Dùng khi tôi báo tiến độ một việc / dự án (vd 'dự án website xong 60%') hoặc hỏi 'dự án nào đang trễ', 'tiến độ'."
metadata:
  bizcity:
    scope: site
    tools: [connected_apps_draft, connected_apps_read, get_datetime]
    source: template
    status: draft
    version: 1
---

# Báo cáo tiến độ dự án

## Khi nào dùng
- Khi tôi muốn báo tiến độ dự án.
- Khi tôi hỏi tiến độ dự án.

**Ghi tiến độ:**
1. Cần: tên việc / dự án, và ít nhất một trong **% hoàn thành** hoặc **trạng thái** (dự kiến · đang làm · vướng · xong). Không chắc % hoặc xong chưa ⇒ hỏi lại.
2. Nếu tôi nói có vướng mắc, ghi vào ghi chú, đúng chữ tôi nói.
3. `connected_apps_draft` kind `sheets.append` book `progress`. Mỗi lần báo là **một dòng mới** (nhật ký, không sửa dòng cũ).

**Xem tiến độ:** `connected_apps_read` op `sheets.report` book `progress`. Trình bày theo thứ tự: **vướng / trễ hạn** → đang làm → xong gần đây, mỗi việc một dòng kèm %. Số đếm theo trạng thái lấy từ máy, không tự đếm.

Cuối cùng nêu một việc đáng chú ý nhất cần tôi quyết định (nếu có).
