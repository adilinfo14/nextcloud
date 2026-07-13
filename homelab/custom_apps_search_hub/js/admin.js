(function () {
	'use strict';

	var container = document.getElementById('nss-content');
	var pollTimer = null;

	function fmtDate(ts) {
		if (!ts) {
			return 'jamais';
		}
		var d = new Date(ts * 1000);
		return d.toLocaleString('fr-FR');
	}

	function esc(s) {
		var div = document.createElement('div');
		div.textContent = String(s);
		return div.innerHTML;
	}

	function providerLabel(id) {
		var labels = { files: 'Fichiers', deck: 'Deck', collectives: 'Wiki (Collectives)' };
		return labels[id] || id;
	}

	function render(data) {
		var html = '';

		if (!data.esOnline) {
			html += '<div class="nss-card nss-error">Elasticsearch injoignable (' + esc(data.elasticHost) + ')</div>';
		}

		html += '<div class="nss-cards">';
		html += '<div class="nss-card"><div class="nss-big">' + data.totalCount + '</div><div>documents indexes (total)</div></div>';

		data.providers.forEach(function (p) {
			html += '<div class="nss-card"><div class="nss-big">' + p.count + '</div><div>' + esc(providerLabel(p.id)) + '</div></div>';
		});

		html += '</div>';

		html += '<h3>Connecteurs</h3>';
		html += '<table class="nss-table"><tbody>';
		html += '<tr><td>Groupfolders indexes</td><td>' + (data.groupfoldersIndexed ? 'Oui' : 'Non') + '</td></tr>';
		html += '<tr><td>OCR (Tesseract) actif</td><td>' + (data.ocr.enabled ? 'Oui' : 'Non') + '</td></tr>';
		html += '<tr><td>OCR sur les PDF</td><td>' + (data.ocr.pdf ? 'Oui' : 'Non') + '</td></tr>';
		html += '<tr><td>Langues OCR</td><td>' + esc(data.ocr.lang || '-') + '</td></tr>';
		html += '</tbody></table>';

		html += '<h3>Execution</h3>';
		html += '<table class="nss-table"><tbody>';
		html += '<tr><td>Cron d\'indexation (toutes les minutes)</td><td>derniere execution : ' + esc(fmtDate(data.cronLastRun)) + '</td></tr>';
		html += '<tr><td>Reindexation manuelle en cours</td><td>' + (data.isRunning ? '<strong>Oui, en cours...</strong>' : 'Non') + '</td></tr>';
		html += '</tbody></table>';

		if (data.recentTicks && data.recentTicks.length) {
			html += '<h3>5 dernieres executions</h3>';
			html += '<table class="nss-table"><thead><tr><th>Source</th><th>Statut</th><th>Action</th><th>Quand</th></tr></thead><tbody>';
			data.recentTicks.forEach(function (t) {
				html += '<tr><td>' + esc(t.source) + '</td><td>' + esc(t.status) + '</td><td>' + esc(t.action || '-') + '</td><td>' + esc(fmtDate(t.tick)) + '</td></tr>';
			});
			html += '</tbody></table>';
		}

		html += '<button id="nss-reindex-btn" class="button" ' + (data.isRunning ? 'disabled' : '') + '>' +
			(data.isRunning ? 'Reindexation en cours...' : 'Relancer une indexation') + '</button>';

		container.innerHTML = html;

		var btn = document.getElementById('nss-reindex-btn');
		if (btn) {
			btn.addEventListener('click', triggerReindex);
		}
	}

	function load() {
		fetch(OC.generateUrl('/apps/search_hub/admin/status'), {
			headers: { requesttoken: OC.requestToken },
		})
			.then(function (r) { return r.json(); })
			.then(function (data) {
				render(data);
				if (data.isRunning && !pollTimer) {
					pollTimer = setInterval(load, 10000);
				} else if (!data.isRunning && pollTimer) {
					clearInterval(pollTimer);
					pollTimer = null;
				}
			})
			.catch(function () {
				container.innerHTML = '<div class="nss-card nss-error">Erreur de chargement du statut.</div>';
			});
	}

	function triggerReindex() {
		fetch(OC.generateUrl('/apps/search_hub/admin/reindex'), {
			method: 'POST',
			headers: { requesttoken: OC.requestToken },
		}).then(function () {
			load();
		});
	}

	document.addEventListener('DOMContentLoaded', load);
})();
