/* Editable search worksheet. Bulk operations fill drafts; only the explicit Save action writes data. */
(function () {
	'use strict';
	var C = window.ltFind;
	if (!C) return;
	var root = document.querySelector('.lt-find'), T = C.i18n;
	var $ = function (s) { return root.querySelector(s); };
	var $$ = function (s) { return Array.prototype.slice.call(root.querySelectorAll(s)); };
	var busy = false, preview = [];
	var inputs = $$('.lt-find-value');
	var baselines = new WeakMap();
	inputs.forEach(function (input) { baselines.set(input, input.value); grow(input); });
	function grow(input) { input.style.height = 'auto'; input.style.height = Math.max(62, input.scrollHeight + 2) + 'px'; }
	function format(s, n) { return s.replace(/%(?:\d+\$)?d/, String(n)); }
	function message(text, error) { var el = $('#lt-find-message'); el.textContent = text; el.classList.toggle('is-error', !!error); }
	function dirty(input) { return input.value !== baselines.get(input); }
	function rows() { return $$('.lt-find-row').filter(function (row) { return row.querySelector('.lt-find-check').checked; }); }
	function clearPreview() { preview = []; $('#lt-find-preview-box').hidden = true; }
	function updateSelection() {
		var checked = rows();
		$$('.lt-find-row').forEach(function (r) { r.classList.toggle('is-selected', r.querySelector('.lt-find-check').checked); });
		$('#lt-find-selected').textContent = checked.length ? String(checked.length) : '';
		$('#lt-find-all').checked = checked.length === $$('.lt-find-row').length;
		$('#lt-find-all').indeterminate = checked.length > 0 && !$('#lt-find-all').checked;
	}
	function update(input) {
		input.classList.toggle('is-dirty', dirty(input));
		if (dirty(input)) input.closest('tr').querySelector('.lt-find-check').checked = true;
		grow(input); updateSelection();
	}
	function targets() {
		var lang = $('#lt-find-target').value;
		return rows().map(function (r) { return r.querySelector('.lt-find-value[data-lang="' + lang + '"]'); }).filter(Boolean);
	}
	function fill(input, value) { input.value = value; update(input); }
	root.addEventListener('input', function (e) {
		clearPreview();
		if (e.target.classList.contains('lt-find-value')) update(e.target);
	});
	root.addEventListener('change', function (e) {
		clearPreview();
		if (e.target.id === 'lt-find-all') $$('.lt-find-check').forEach(function (box) { box.checked = e.target.checked; });
		updateSelection();
	});
	$('#lt-find-fill').addEventListener('click', function () {
		var selected = targets(), list = selected.filter(function (input) { return input.getAttribute('data-whole') !== '1'; }), value = $('#lt-find-common').value;
		if (selected.length && !list.length) { message(T.whole, true); return; }
		if (!list.length) { message(T.pick, true); return; }
		if (!value.trim()) { message(T.empty, true); return; }
		clearPreview(); list.forEach(function (input) { fill(input, value); });
		message(format(T.filled, list.length) + (list.length < selected.length ? '\n' + T.whole : ''));
	});

	/* Keep HTML/format markers, shortcodes, placeholders, URLs and addresses intact. Literal text only. */
	function replaceText(text, from, to) {
		if (!from) return text;
		var protectedText = /(<!--[\s\S]*?-->|<[^>]*>|\[\/?[a-zA-Z_][^\]]*\]|\{[a-zA-Z_][a-zA-Z0-9_]*\}|%(?:\d+\$)?[-+0-9.]*[bcdeEfFgGosuxX]|https?:\/\/[^\s<>]+|[\w.+-]+@[\w.-]+\.[a-z]{2,})/gi;
		var matcher = new RegExp(from.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'gi');
		return text.split(protectedText).map(function (part, i) { return i % 2 ? part : part.replace(matcher, function () { return to; }); }).join('');
	}
	$('#lt-find-preview').addEventListener('click', function () {
		clearPreview();
		var list = targets(), from = $('#lt-find-from').value, to = $('#lt-find-to').value;
		if (!list.length) { message(T.pick, true); return; }
		if (!from) { message(T.empty, true); return; }
		var source = $('#lt-find-from-source').checked;
		list.forEach(function (input) {
			var before = input.value, base = before.trim() || !source ? before : input.getAttribute('data-original');
			var after = replaceText(base, from, to);
			if (after !== base && after !== before) preview.push({ input: input, before: before, base: base, after: after });
		});
		if (!preview.length) { message(T.none); return; }
		var ul = $('#lt-find-preview-list'); ul.textContent = '';
		preview.forEach(function (change) {
			var li = document.createElement('li'), title = document.createElement('strong'), before = document.createElement('del'), after = document.createElement('ins');
			title.textContent = change.input.getAttribute('aria-label'); before.textContent = change.base; after.textContent = change.after;
			li.appendChild(title); li.appendChild(before); li.appendChild(after); ul.appendChild(li);
		});
		$('#lt-find-preview-message').textContent = format(T.preview, preview.length);
		$('#lt-find-preview-box').hidden = false;
	});
	$('#lt-find-apply').addEventListener('click', function () {
		var applied = 0;
		preview.forEach(function (change) { if (change.input.value === change.before) { fill(change.input, change.after); applied++; } });
		clearPreview(); message(format(T.filled, applied));
	});

	$('#lt-find-save').addEventListener('click', async function () {
		if (busy) return;
		var selected = rows();
		if (!selected.length) { message(T.pick, true); return; }
		var batches = {};
		selected.forEach(function (row) {
			Array.prototype.forEach.call(row.querySelectorAll('.lt-find-value'), function (input) {
				if (!dirty(input)) return;
				var key = row.getAttribute('data-key'), lang = input.getAttribute('data-lang'), id = key + '|' + lang;
				var meta = C.entries[key].languages[lang];
				if (!batches[id]) batches[id] = { key: key, lang: lang, values: meta.values.slice(), inputs: [] };
				batches[id].values[Number(row.getAttribute('data-part'))] = input.value;
				batches[id].inputs.push(input);
			});
		});
		var jobs = Object.keys(batches).map(function (key) { return batches[key]; });
		if (!jobs.length) { message(T.nothing); return; }
		busy = true; clearPreview(); root.classList.add('is-saving');
		var controls = $$('input, textarea, select, button');
		controls.forEach(function (el) { el.disabled = true; });
		var saved = 0;
		try {
			for (var i = 0; i < jobs.length; i++) {
				message(T.saving + ' ' + (i + 1) + '/' + jobs.length);
				var job = jobs[i], entry = C.entries[job.key], meta = entry.languages[job.lang];
				var fd = new FormData();
				fd.append('action', 'lt_find_save'); fd.append('nonce', C.nonce); fd.append('group', entry.group); fd.append('id', entry.id); fd.append('field', entry.field); fd.append('lang', job.lang); fd.append('hash', entry.hash); fd.append('revision', meta.revision);
				if (meta.parts) fd.append('parts', JSON.stringify(job.values)); else fd.append('value', job.values[0]);
				var response = await fetch(C.ajax, { method: 'POST', credentials: 'same-origin', body: fd });
				var result = await response.json();
				if (!result.success) throw new Error(result.data && result.data.message || T.error);
				meta.revision = result.data.revision; meta.values = job.values.slice();
				job.inputs.forEach(function (input) {
					baselines.set(input, input.value); input.classList.remove('is-dirty', 'is-changed', 'is-todo');
					input.classList.add(result.data.state.state === 'ok' ? 'is-ok' : 'is-changed');
				});
				saved++;
			}
			message(format(T.saved, saved));
		} catch (error) {
			message((saved ? format(T.saved, saved) + '\n' : '') + (error.message || T.error), true);
		} finally {
			busy = false; root.classList.remove('is-saving'); controls.forEach(function (el) { el.disabled = false; });
		}
	});
	window.addEventListener('beforeunload', function (e) {
		if (inputs.some(dirty)) { e.preventDefault(); e.returnValue = ''; }
	});
	updateSelection();
})();
