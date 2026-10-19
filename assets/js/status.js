/* "Pages & products": translation panel opened in place, bulk actions. */
(function () {
	var C = window.ltStatus;
	var drawer = document.querySelector('.lt-drawer');
	if (!C || !drawer) return;
	var box = drawer.querySelector('.lt-drawer-box');
	var cur = null, dirty = false, busy = false;
	var $ = function (s, r) { return (r || document).querySelector(s); };
	var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };
	var T = C.i18n;

	function send(fd) {
		fd.append('nonce', C.nonce);
		return fetch(C.ajax, { method: 'POST', credentials: 'same-origin', body: fd }).then(function (r) { return r.json(); });
	}
	var progressRequest = 0;
	function refreshProgress() {
		if (!C.view) return;
		var fd = new FormData(), serial = ++progressRequest;
		fd.append('action', 'lt_source_progress');
		Object.keys(C.view).forEach(function (key) { fd.append(key, C.view[key]); });
		send(fd).then(function (r) {
			if (serial !== progressRequest || !r || !r.success) return;
			var side = $('.lt-side');
			if (side && r.data.sidebar !== null) {
				var scroll = side.scrollTop, back = $('.lt-back', side);
				side.innerHTML = r.data.sidebar;
				if (back) side.insertBefore(back, side.firstChild);
				side.scrollTop = scroll;
			}
			var p = r.data.progress;
			$$('.lt-head [data-lt-language]').forEach(function (row) {
				var lang = row.dataset.ltLanguage, bar = $('.lt-bar i', row), text = $('.lt-progress-text', row);
				if (bar) bar.style.width = (p.total ? Math.floor(100 * (p.translated[lang] || 0) / p.total) : 0) + '%';
				if (text) text.textContent = p.labels[lang] || '';
			});
		}).catch(function () { /* Saving succeeded; the next navigation refreshes these counts too. */ });
	}

	function grow(t) { t.style.height = 'auto'; t.style.height = (t.scrollHeight + 2) + 'px'; }
	function msg(text, kind) { var m = $('.lt-panel-msg', box); if (m) { m.textContent = text || ''; m.className = 'lt-panel-msg ' + (kind || ''); } }
	function rowOf(group, id) { return $('tr[data-key="' + group + '|' + id + '"]'); }

	function open(group, id, lang) {
		if (dirty && !window.confirm(T.unsaved)) return;
		dirty = false;
		cur = { group: group, id: String(id), lang: lang || (cur && cur.lang) || C.lang };
		drawer.hidden = false;
		document.body.classList.add('lt-drawer-open');
		box.innerHTML = '<p class="lt-panel-loading">' + T.loading + '</p>';
		$$('tr.is-current').forEach(function (r) { r.classList.remove('is-current'); });
		var tr = rowOf(group, id); if (tr) tr.classList.add('is-current');
		try { history.replaceState(null, '', '#lt=' + encodeURIComponent(group + '|' + id + '|' + cur.lang)); } catch (e) {}
		var fd = new FormData();
		fd.append('action', 'lt_panel'); fd.append('group', group); fd.append('id', id); fd.append('lang', cur.lang);
		send(fd).then(function (r) {
			if (!r || !r.success) { box.innerHTML = '<p class="lt-panel-loading">' + ((r && r.data && r.data.message) || T.error) + '</p>'; return; }
			box.innerHTML = r.data.html;
			setup();
		}).catch(function () { box.innerHTML = '<p class="lt-panel-loading">' + T.error + '</p>'; });
	}

	function close() {
		if (dirty && !window.confirm(T.unsaved)) return;
		dirty = false;
		drawer.hidden = true;
		document.body.classList.remove('lt-drawer-open');
		try { history.replaceState(null, '', location.pathname + location.search); } catch (e) {}
	}

	function setup() {
		var form = $('.lt-panel', box);
		if (!form) return;
		$$('textarea', form).forEach(grow);
		var first = $('.lt-row.is-todo textarea, .lt-row.is-todo input[type=text], .lt-row.is-new textarea, .lt-row.is-changed textarea, .lt-row.is-changed input[type=text]', form);
		if (first) { first.focus(); first.scrollIntoView({ block: 'center' }); }
		form.addEventListener('input', function (e) {
			var row = e.target.closest('.lt-row');
			if (row) { row.classList.add('is-dirty'); dirty = true; }
			if (e.target.tagName === 'TEXTAREA') grow(e.target);
		});
		form.addEventListener('submit', function (e) { e.preventDefault(); });
	}

	function nextRow() {
		var rows = $$('tr[data-key]');
		var at = rows.indexOf(rowOf(cur.group, cur.id));
		for (var i = at + 1; i < rows.length; i++) {
			if ($('.lt-st.todo, .lt-st.update', rows[i])) return rows[i];
		}
		return null;
	}

	function save(goNext) {
		var form = $('.lt-panel', box);
		if (!form || busy) return;
		var fd = new FormData(), changed = 0;
		fd.append('action', 'lt_panel_save'); fd.append('group', cur.group); fd.append('id', cur.id); fd.append('lang', cur.lang);
		var tr = rowOf(cur.group, cur.id);
		fd.append('depth', tr ? (tr.getAttribute('data-depth') || 0) : 0);
		$$('.lt-field', form).forEach(function (field) {
			var isParts = field.classList.contains('lt-parts');
			if (isParts ? !$('.lt-row.is-dirty', field) : !field.classList.contains('is-dirty')) return;
			$$('input, textarea', field).forEach(function (el) { if (el.name) fd.append(el.name, el.value); });
			changed++;
		});
		var go = function () {
			if (!goNext) return;
			var n = nextRow();
			if (n) { var a = $('.lt-open', n); open(a.getAttribute('data-group'), a.getAttribute('data-id'), cur.lang); }
			else msg(T.last, 'ok');
		};
		if (!changed) { dirty = false; go(); return; }
		busy = true; msg(T.saving);
		send(fd).then(function (r) {
			busy = false;
			if (!r || !r.success) { msg((r && r.data && r.data.message) || T.error, 'err'); return; }
			dirty = false;
			refreshProgress();
			// Refresh the line (and its variations) in the list.
			if (tr && r.data.row) {
				$$('tr.lt-var-' + cur.id).forEach(function (v) { v.parentNode.removeChild(v); });
				var tmp = document.createElement('tbody'); tmp.innerHTML = r.data.row;
				var fresh = $$('tr', tmp);
				fresh.forEach(function (f) { tr.parentNode.insertBefore(f, tr); });
				tr.parentNode.removeChild(tr);
				if (fresh[0]) fresh[0].classList.add('is-current');
			}
			if (goNext) { go(); return; }
			box.innerHTML = r.data.html; setup(); msg(r.data.message, 'ok');
		}).catch(function () { busy = false; msg(T.error, 'err'); });
	}

	function readPage(btn) {
		btn.disabled = true; btn.textContent = T.reading;
		var f = document.createElement('iframe');
		f.style.cssText = 'position:fixed;left:-10000px;top:0;width:1280px;height:900px;border:0';
		f.setAttribute('aria-hidden', 'true');
		f.onload = function () {
			if (f.parentNode) f.parentNode.removeChild(f);
			if (cur) open(cur.group, cur.id, cur.lang);
		};
		f.src = btn.getAttribute('data-url');
		document.body.appendChild(f);
	}

	document.addEventListener('click', function (e) {
		var t = e.target, el;
		if ((el = t.closest('.lt-open'))) { e.preventDefault(); open(el.getAttribute('data-group'), el.getAttribute('data-id'), el.getAttribute('data-lang')); return; }
		if (t.closest('.lt-panel-close') || t.classList.contains('lt-drawer-bg')) { close(); return; }
		if ((el = t.closest('.lt-lang-tab'))) { open(cur.group, cur.id, el.getAttribute('data-lang')); return; }
		if ((el = t.closest('.lt-copy'))) {
			var row = el.closest('.lt-row'), input = row && $('.lt-tr textarea, .lt-tr input[type=text]', row), src = row && $('.lt-src', row);
			if (input && src) { input.value = src.getAttribute('data-src'); if (input.tagName === 'TEXTAREA') grow(input); row.classList.add('is-dirty'); dirty = true; }
			return;
		}
		if ((el = t.closest('.lt-still'))) { var r = el.closest('.lt-row'); r.classList.add('is-dirty'); r.classList.remove('is-changed'); dirty = true; el.textContent = '✓'; el.disabled = true; return; }
		if (t.closest('.lt-save')) { save(false); return; }
		if (t.closest('.lt-save-next')) { save(true); return; }
		if ((el = t.closest('.lt-read-page'))) { readPage(el); return; }
		if ((el = t.closest('.lt-toggle'))) {
			e.preventDefault();
			var opened = el.getAttribute('aria-expanded') !== 'true';
			el.setAttribute('aria-expanded', opened);
			$('.lt-arrow', el).textContent = opened ? '▾' : '▸';
			$$('.lt-var-' + el.getAttribute('data-id')).forEach(function (v) { v.hidden = !opened; });
		}
	});
	document.addEventListener('change', function (e) {
		if (e.target.classList.contains('lt-check-all')) $$('input[name="items[]"]').forEach(function (c) { c.checked = e.target.checked; });
		if (e.target.classList.contains('lt-filter-left')) { var f = $('.lt-panel', box); if (f) f.classList.toggle('only-left', e.target.checked); }
	});
	document.addEventListener('keydown', function (e) {
		if (drawer.hidden) return;
		if (e.key === 'Escape') close();
		if (e.key === 'Enter' && (e.metaKey || e.ctrlKey)) { e.preventDefault(); save(true); }
	});
	window.addEventListener('beforeunload', function (e) { if (dirty) { e.preventDefault(); e.returnValue = ''; } });

	var bulk = $('.lt-bulk-form');
	if (bulk) bulk.addEventListener('submit', function (e) {
		var action = $('select[name="bulk"]', bulk).value;
		if (!action) { e.preventDefault(); return; }
		if (!$$('input[name="items[]"]:checked', bulk).length) { e.preventDefault(); window.alert(T.pick); return; }
		if (action === 'delete' && !window.confirm(T.confirm)) e.preventDefault();
	});

	var m = /^#lt=([^&]+)/.exec(location.hash);
	if (m) { var p = decodeURIComponent(m[1]).split('|'); if (p.length >= 2) open(p[0], p[1], p[2]); }
})();
