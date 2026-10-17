(function () {
	'use strict';
	var root = document.getElementById('lt-overview-progress');
	if (!root || !window.ltOverview) return;
	var body = new URLSearchParams();
	body.set('action', 'lt_overview_progress');
	body.set('nonce', window.ltOverview.nonce);
	fetch(window.ltOverview.ajax, { method: 'POST', credentials: 'same-origin', body: body })
		.then(function (response) { if (!response.ok) throw new Error('request'); return response.json(); })
		.then(function (response) {
			if (!response.success) throw new Error('request');
			root.querySelectorAll('[data-language]').forEach(function (card) {
				var language = card.getAttribute('data-language');
				var data = response.data[language];
				if (!data) return;
				var total = Number(data.fields) || 0;
				var done = Number(data.translated) || 0;
				var outdated = Number(data.outdated) || 0;
				var percent = total ? Math.floor(100 * done / total) : 0;
				card.querySelector('.lt-large-number').textContent = percent + '%';
				card.querySelector('progress').value = percent;
				card.querySelector('.lt-progress-copy').textContent = window.ltOverview.summary.replace('%1$d', done.toLocaleString()).replace('%2$d', total.toLocaleString()).replace('%3$d', outdated.toLocaleString());
			});
		})
		.catch(function () {
			root.querySelectorAll('.lt-progress-copy').forEach(function (copy) { copy.textContent = window.ltOverview.error; });
		});
}());
