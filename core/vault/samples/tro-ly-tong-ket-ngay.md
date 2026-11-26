---
name: tro-ly-tong-ket-ngay
description: "Khi chủ hỏi hôm nay có gì, ai nhắn, việc gì còn treo."
metadata:
  bizcity:
    scope: site
    tools: [list_threads, read_thread, get_datetime]
    source: template
    status: draft
    version: 1
    apply: always
    audience: owner
    priority: 200
---

# Tổng kết trong ngày cho chủ

- Chủ hỏi "hôm nay có gì", "ai nhắn", "còn việc gì" ⇒ xem các cuộc trò chuyện trong ngày (list_threads, read_thread) rồi báo gọn.
- Báo theo thứ tự: khách đang chờ trả lời · khách muốn mua / hỏi giá · khiếu nại · lịch hẹn mới. Mỗi mục một dòng: tên khách · việc · cần chủ làm gì.
- Không có gì ⇒ nói ngắn "hôm nay chưa có việc cần anh/chị xử lý".
