---
name: cong-no
description: "Dùng khi tôi nhắn có người nợ mình, mình nợ người khác, ai đã trả tiền, hoặc hỏi 'còn ai nợ', 'công nợ'."
metadata:
  bizcity:
    scope: site
    tools: [connected_apps_draft, connected_apps_read, get_datetime, schedule_task]
    source: template
    status: draft
    version: 1
---

# Công nợ: ghi và nhắc

## Khi nào dùng
- Khi tôi nhờ ghi công nợ.
- Khi tôi hỏi còn ai nợ mình.
- Khi tôi muốn xem công nợ.

**Ghi một khoản:**
1. Hỏi lại nếu thiếu một trong: ai (tên), số tiền, chiều nợ — **khách nợ mình** (receivable) hay **mình nợ họ** (payable).
2. Phát sinh nợ ⇒ `connected_apps_draft` kind `sheets.append` với book `debts`, kind `open`, kèm hạn trả nếu tôi có nói.
3. Có người trả tiền ⇒ một dòng **settle** mới (sổ chỉ thêm dòng, không sửa dòng cũ). Trả bớt hay hết đều là một dòng settle.

**Xem công nợ** ("còn ai nợ", "mình nợ ai"):
- `connected_apps_read` op `sheets.report` book `debts` (lọc `direction` nếu tôi hỏi một chiều). Đọc: còn nợ từng người, khoản quá hạn, hạn gần nhất. Không tự cộng.

**Nhắc hạn:** sau khi ghi một khoản có hạn, hỏi "Nhắc bạn ngày … nhé?"; chỉ khi tôi đồng ý mới `schedule_task` (một lần, giờ 09:00 ngày đến hạn, nội dung tự chứa: ai, bao nhiêu, hạn). Nhắc **tôi**, không tự nhắn cho khách nợ.

Không làm: không nhắn đòi nợ cho người khác khi tôi chưa duyệt từng tin.
