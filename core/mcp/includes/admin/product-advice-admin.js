/**
 * "Tư vấn AI" metabox helpers (PHASE-0.95 S95-F5): chip inputs over the one-item-per-line textareas + the pitch counter.
 * Vanilla JS, no build. The textarea stays the source of truth, so the form still posts the same data without JS.
 *
 * [2026-10-09 03:56 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.95-S95-F5 — new file.
 */
(function () {
	'use strict';

	function lines(v) {
		return String(v || '').split(/\r\n|\r|\n/).map(function (s) { return s.trim(); }).filter(Boolean);
	}

	function chipify(src) {
		var max = parseInt(src.getAttribute('data-max'), 10) || 0;
		var len = parseInt(src.getAttribute('data-len'), 10) || 0;
		var box = document.createElement('div');
		box.className = 'bzadv-chips' + (src.getAttribute('data-bad') === '1' ? ' bad' : '') + (src.classList.contains('bzadv-err') ? ' bzadv-err' : '');
		var input = document.createElement('input');
		input.type = 'text';
		input.className = 'bzadv-chip-in';
		input.placeholder = 'gõ rồi Enter';
		var note = document.createElement('div');
		note.className = 'bzadv-errmsg';
		var items = lines(src.value);

		function sync() {
			var all = items.slice();
			var pending = input.value.trim();
			if (pending) all.push(pending);
			src.value = all.join('\n');
			var msg = '';
			if (max && items.length > max) msg = 'Tối đa ' + max + ' mục (đang có ' + items.length + ').';
			else if (len && items.some(function (s) { return s.length > len; })) msg = 'Mỗi mục tối đa ' + len + ' ký tự.';
			note.textContent = msg;
		}

		function render() {
			Array.prototype.slice.call(box.querySelectorAll('.bzadv-chip')).forEach(function (c) { c.remove(); });
			items.forEach(function (text, i) {
				var chip = document.createElement('span');
				chip.className = 'bzadv-chip';
				chip.appendChild(document.createTextNode(text));
				var x = document.createElement('button');
				x.type = 'button';
				x.setAttribute('aria-label', 'Bỏ ' + text);
				x.textContent = '×';
				x.addEventListener('click', function () { items.splice(i, 1); render(); });
				chip.appendChild(x);
				box.insertBefore(chip, input);
			});
			sync();
		}

		function commit() {
			var parts = lines(input.value.replace(/,/g, '\n'));
			parts.forEach(function (p) { if (items.indexOf(p) === -1) items.push(p); });
			input.value = '';
			render();
		}

		input.addEventListener('keydown', function (e) {
			if (e.key === 'Enter' || e.key === ',') {
				e.preventDefault();
				commit();
			} else if (e.key === 'Backspace' && !input.value && items.length) {
				items.pop();
				render();
			}
		});
		input.addEventListener('input', sync);
		input.addEventListener('blur', function () { if (input.value.trim()) commit(); });
		box.addEventListener('click', function (e) { if (e.target === box) input.focus(); });

		// "Gợi ý từ mô tả" đổi textarea ⇒ vẽ lại chip
		src.addEventListener('bzadv:set', function () { items = lines(src.value); render(); });

		box.appendChild(input);
		src.style.display = 'none';
		src.parentNode.insertBefore(box, src);
		src.parentNode.insertBefore(note, src.nextSibling);
		render();
	}

	function counter(el) {
		var name = el.getAttribute('data-bzadv-counted');
		var out = document.querySelector('[data-bzadv-count="' + name + '"]');
		if (!out) return;
		var max = parseInt(out.getAttribute('data-max'), 10) || 0;
		function upd() {
			var n = Array.from ? Array.from(el.value).length : el.value.length;
			out.textContent = n + '/' + max;
			out.classList.toggle('bzadv-over', max > 0 && n > max);
		}
		el.addEventListener('input', upd);
		upd();
	}

	// [2026-10-09 11:12 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.95-S95-F5 — "Gợi ý từ mô tả": hỏi cell một bản nháp rồi CHỈ điền vào ô.
	// Ô đã có chữ chỉ bị ghi đè khi chủ đồng ý; không lưu gì — chủ bấm Cập nhật mới lưu.
	function draftButton(btn) {
		var cfg = window.bizcityAdviceDraft;
		var root = btn.closest('[data-bzadv]');
		var note = root && root.querySelector('[data-bzadv-draft-note]');
		if (!cfg || !root) { btn.disabled = true; return; }
		function say(t) { if (note) note.textContent = t; }

		function field(name) { return root.querySelector('[name="bizcity_advice[' + name + ']"]'); }
		function qInputs() { return root.querySelectorAll('input[name="bizcity_advice[key_questions][]"]'); }

		function fill(d) {
			var plan = [];
			['audience', 'goals', 'avoid_for'].forEach(function (k) {
				var el = field(k);
				if (el && d[k] && d[k].length) plan.push({ el: el, val: d[k].join('\n'), chips: true });
			});
			var qs = qInputs();
			(d.key_questions || []).forEach(function (q, i) { if (qs[i]) plan.push({ el: qs[i], val: q }); });
			var pitch = field('pitch');
			if (pitch && d.pitch) plan.push({ el: pitch, val: d.pitch });
			if (!plan.length) { say('Trợ lý không rút được gì từ mô tả — nhập tay nhé.'); return; }

			var busy = plan.filter(function (p) { return p.el.value.trim() !== '' && p.el.value.trim() !== p.val; });
			var overwrite = busy.length === 0 || window.confirm('Có ' + busy.length + ' ô đã có chữ. Thay bằng nháp của trợ lý?\n(Huỷ = chỉ điền các ô còn trống)');
			var n = 0;
			plan.forEach(function (p) {
				if (p.el.value.trim() !== '' && !overwrite) return;
				p.el.value = p.val;
				p.el.dispatchEvent(new Event(p.chips ? 'bzadv:set' : 'input'));
				n++;
			});
			say(n ? 'Trợ lý đã điền nháp vào ' + n + ' ô (chưa lưu). Xem lại rồi bấm Cập nhật.' : 'Không đổi ô nào.');
		}

		btn.addEventListener('click', function () {
			var label = btn.textContent;
			btn.disabled = true;
			btn.textContent = '✨ Đang soạn…';
			say('');
			var body = new URLSearchParams();
			body.set('action', cfg.action);
			body.set('nonce', cfg.nonce);
			body.set('product_id', btn.getAttribute('data-bzadv-draft'));
			fetch(cfg.ajax, { method: 'POST', credentials: 'same-origin', body: body })
				.then(function (r) { return r.json().catch(function () { return { ok: false }; }); })
				.then(function (r) {
					if (r && r.ok && r.draft) fill(r.draft);
					else say((r && r.error) || 'Trợ lý chưa soạn được nháp — thử lại sau ít phút hoặc nhập tay.');
				})
				.catch(function () { say('Mất kết nối — thử lại.'); })
				.then(function () { btn.disabled = false; btn.textContent = label; });
		});
	}

	function boot() {
		Array.prototype.forEach.call(document.querySelectorAll('textarea[data-bzadv-chips]'), chipify);
		Array.prototype.forEach.call(document.querySelectorAll('[data-bzadv-counted]'), counter);
		Array.prototype.forEach.call(document.querySelectorAll('[data-bzadv-draft]'), draftButton);
	}

	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
	else boot();
})();
