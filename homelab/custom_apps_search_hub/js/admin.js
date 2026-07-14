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

		html += '<h3>Recherche par sens (embeddings)</h3>';
		var eb = data.embeddingBackfill || {};
		var lastRun = eb.lastRun;
		html += '<table class="nss-table"><tbody>';
		html += '<tr><td>Passages indexes (recherche par sens)</td><td>' + (eb.totalPassages || 0) + '</td></tr>';
		html += '<tr><td>Documents source ayant des passages</td><td>' + (eb.totalChunkedDocuments || 0) + '</td></tr>';
		html += '<tr><td>Cron du backfill (quotidien, 4h15)</td><td>derniere execution : ' +
			(lastRun ? esc(fmtDate(lastRun.finishedAt)) : 'jamais') + '</td></tr>';
		if (lastRun) {
			html += '<tr><td>Resultat de la derniere execution</td><td>' +
				lastRun.documentsChunked + ' document(s), ' + lastRun.passagesCreated + ' passage(s) crees, ' +
				lastRun.skipped + ' ignores, ' + lastRun.errors + ' erreur(s)</td></tr>';
		}
		html += '</tbody></table>';
		html += '<button id="nss-embed-reindex-btn" class="button">Lancer le backfill des embeddings maintenant</button>';

		html += '<h3>Connecteurs</h3>';
		html += '<p class="settings-hint">Une source de contenu indexee dans Recherche+ (chaque connecteur alimente a la fois la recherche mot-cle et la recherche par sens).</p>';
		var connectors = data.connectors || { active: [], proposed: [] };
		html += '<table class="nss-table"><thead><tr><th>Connecteur</th><th>Type</th></tr></thead><tbody>';
		connectors.active.forEach(function (c) {
			html += '<tr><td>✅ ' + esc(c.label) + '</td><td>' + esc(c.type) + '</td></tr>';
		});
		html += '</tbody></table>';
		if (connectors.proposed && connectors.proposed.length) {
			html += '<table class="nss-table"><thead><tr><th>Connecteur propose (pas encore construit)</th><th>Ce qu\'il faudrait</th></tr></thead><tbody>';
			connectors.proposed.forEach(function (c) {
				html += '<tr><td>◻️ ' + esc(c.label) + '</td><td>' + esc(c.reason) + '</td></tr>';
			});
			html += '</tbody></table>';
		}

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

		var embedBtn = document.getElementById('nss-embed-reindex-btn');
		if (embedBtn) {
			embedBtn.addEventListener('click', triggerEmbedReindex);
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

	function triggerEmbedReindex() {
		var btn = document.getElementById('nss-embed-reindex-btn');
		if (btn) {
			btn.disabled = true;
			btn.textContent = 'Backfill lance en tache de fond...';
		}
		fetch(OC.generateUrl('/apps/search_hub/admin/reindex-embeddings'), {
			method: 'POST',
			headers: { requesttoken: OC.requestToken },
		}).then(function () {
			setTimeout(load, 3000);
		});
	}

	document.addEventListener('DOMContentLoaded', load);
})();
