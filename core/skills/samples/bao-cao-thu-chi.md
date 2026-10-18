---
name: bao-cao-thu-chi
description: "Dùng khi tôi hỏi tổng thu chi hôm nay, tuần này, tháng này hoặc một khoảng ngày; hoặc nhờ báo cáo thu chi."
metadata:
  bizcity:
    scope: site
    tools: [connected_apps_read, get_datetime, create_excel_file]
    source: template
    status: draft
    version: 1
---

# Báo cáo thu chi

## Khi nào dùng
- Khi tôi nhờ báo cáo thu chi.
- Khi tôi hỏi tổng thu chi tuần này.
- Khi tôi muốn xem thu chi tháng này.

Khi tôi hỏi tình hình thu chi, hãy:

1. Xác định khoảng thời gian bằng `get_datetime`: "hôm nay", "tuần này" (thứ Hai đến hôm nay), "tháng này", "tháng trước" hoặc hai ngày tôi nói. Không rõ khoảng ⇒ hỏi lại một câu.
2. Gọi `connected_apps_read` với op `sheets.report` (bỏ trống book = sổ thu chi) kèm `from` / `to`.
3. Trả lời ngắn: **tổng thu · tổng chi · chênh lệch**. CHỈ dùng số máy trả về, không tự cộng.
4. Nếu kết quả có nhóm chi, nêu 3 nhóm chi lớn nhất và một nhận xét một câu (vd nhóm nào tăng bất thường nếu tôi hỏi so với kỳ trước — khi đó gọi thêm cho kỳ trước).
5. Kết quả có dòng lỗi (`bad_rows`) ⇒ nói rõ dòng nào sai để tôi sửa.
6. Tôi nhờ "xuất file" ⇒ tạo file Excel với các cột: Ngày · Loại · Số tiền · Nhóm · Ghi chú, lấy từ kết quả máy trả.

Chưa liên kết sổ ⇒ chỉ tôi vào Công cụ › Kết nối nhanh › Google Sheets; đừng bịa số.
