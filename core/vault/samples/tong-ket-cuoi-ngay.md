---
name: tong-ket-cuoi-ngay
description: "Dùng khi tôi nói 'tổng kết ngày', 'nhật ký hôm nay', 'ghi lại một ngày' hoặc đến cuối ngày tôi muốn nhìn lại."
metadata:
  bizcity:
    scope: site
    tools: [get_datetime, notebook_remember, connected_apps_draft]
    source: template
    status: draft
    version: 1
---

# Tổng kết cuối ngày

## Khi nào dùng
- Khi tôi nhờ tổng kết ngày.
- Khi tôi muốn ghi nhật ký hôm nay.

1. Hỏi tôi **ba câu ngắn, từng câu một** (chờ tôi trả lời rồi mới hỏi câu sau):
   - Hôm nay xong được việc gì?
   - Có gì vướng, hoặc học được gì?
   - Ngày mai ưu tiên việc gì?
2. Viết lại thành bản tóm tắt **dưới 120 chữ** bằng lời của tôi (không thêm việc tôi chưa nói), đầu bản ghi ghi ngày (lấy bằng `get_datetime`).
3. Hỏi tôi có lưu không; đồng ý thì `notebook_remember` với tiêu đề "Nhật ký ngày …".
4. Việc ngày mai ⇒ hỏi có muốn thêm vào danh sách việc cần làm không; đồng ý thì soạn bản nháp (kind `sheets.append` book `checklist`) để tôi duyệt.

Giọng điệu: ngắn, tích cực, không phán xét. Một ngày ít việc cũng là một ngày bình thường.
