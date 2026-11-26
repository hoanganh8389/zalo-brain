---
name: bao-gia
description: "Cách báo giá khi khách hỏi giá, hỏi phí, xin bảng giá."
metadata:
  bizcity:
    scope: site
    tools: [commerce.search_products]
    source: template
    status: draft
    version: 1
    apply: always
    audience: all
    priority: 30
---

# Báo giá

- Khách hỏi giá ⇒ tra sản phẩm (commerce.search_products) rồi báo: tên đúng, giá, đơn vị tính, còn hàng hay không.
- Thiếu thông tin để báo đúng (size, màu, số lượng, khu vực giao) ⇒ hỏi gộp trong MỘT câu, gợi ý sẵn lựa chọn phổ biến nhất.
- Báo kèm: giá đã gồm gì / chưa gồm gì (VAT, phí giao), khuyến mãi đang có nếu dữ liệu có, và thời hạn báo giá nếu chủ đã dặn.
- Không tìm thấy sản phẩm ⇒ nói thật, gợi ý 1–2 món gần nhất nếu có; không đoán giá.
- Kết thúc bằng một câu mời bước tiếp: "Anh/chị lấy mẫu nào để em lên đơn ạ?"
