---
name: tom-tat-tai-lieu
description: "Dùng khi tôi gửi một file (Word, PDF, Excel, slide…) hoặc ảnh chụp trang sách và nhờ tóm tắt, rút ý chính, ra câu hỏi ôn."
metadata:
  bizcity:
    scope: site
    tools: [read_file, document_ocr, notebook_remember, create_markdown_file]
    source: template
    status: draft
    version: 1
---

# Tóm tắt tài liệu vừa gửi

## Khi nào dùng
- Khi tôi nhờ tóm tắt tài liệu vừa gửi.
- Khi tôi muốn rút ý chính từ file.

1. Đọc file tôi **vừa gửi trong cuộc trò chuyện này** bằng `read_file`. File dài ⇒ đọc từng đoạn, đừng bỏ phần giữa. PDF chỉ là ảnh (đọc không ra chữ) ⇒ dùng `document_ocr`.
2. Trả lời theo đúng khuôn:
   - **Một câu**: tài liệu nói về gì.
   - **5 ý chính** (mỗi ý một dòng).
   - **Thuật ngữ cần nhớ** (tối đa 5, kèm nghĩa ngắn).
   - **3 câu hỏi tự kiểm tra** (chưa đưa đáp án; tôi hỏi mới đưa).
3. Chỉ dùng nội dung trong file. Chỗ nào file không nói thì nói "tài liệu không đề cập", không bổ sung từ kiến thức ngoài mà không báo rõ.
4. Hỏi tôi có muốn lưu bản tóm tắt vào sổ ghi chú không (`notebook_remember`) hoặc xuất thành file Markdown (`create_markdown_file`).

File .doc / .xls đời cũ không đọc được ⇒ nói đúng lý do và gợi ý lưu lại sang .docx / .xlsx.

## Uỷ quyền (để trợ lý chia việc)
Khối dưới đây dạy trợ lý chia việc thành các mảnh khi chạy nhiều bước (twin-skill@1.1). Sửa ở Skill Editor; trợ lý kiểm lại trước khi dùng.

```delegation
[{"piece": "doc_file", "goal": "Đọc trọn file vừa gửi trong cuộc trò chuyện này (PDF chỉ là ảnh thì dùng OCR)", "group": "document", "output": "text", "tools": ["read_file", "document_ocr"], "bounds": "chỉ file trong cuộc trò chuyện này, đọc hết, không bỏ đoạn giữa", "rubric": ["đọc hết file, không bỏ đoạn giữa"]},
{"piece": "tom_tat", "goal": "Viết một câu tổng quát, 5 ý chính, tối đa 5 thuật ngữ và 3 câu hỏi tự kiểm tra", "group": "document", "output": "summary", "bounds": "chỉ dùng nội dung trong file; thiếu thì ghi 'tài liệu không đề cập'", "rubric": ["đủ 5 ý chính", "chỉ dùng nội dung trong file"], "depends_on": ["doc_file"]},
{"piece": "luu_so", "goal": "Hỏi chủ có muốn lưu bản tóm tắt vào sổ ghi chú; đồng ý thì lưu", "group": "notebook", "output": "note", "tools": ["notebook_remember"], "rubric": ["chỉ lưu khi chủ đồng ý"], "depends_on": ["tom_tat"]}]
```
