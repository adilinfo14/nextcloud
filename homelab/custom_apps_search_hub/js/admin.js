(function () {
	'use strict';

	var container = document.getElementById('nss-content');
	var pollTimer = null;
	var currentConfig = null;

	function fmtDate(ts) {
		if (!ts) {
			return 'jamais';
		}
		var d = new Date(ts * 1000);
		return d.toLocaleString('fr-FR');
	}

	function fmtBytes(bytes) {
		if (!bytes) {
			return '0 o';
		}
		var units = ['o', 'Ko', 'Mo', 'Go'];
		var i = 0;
		var n = bytes;
		while (n >= 1024 && i < units.length - 1) {
			n = n / 1024;
			i++;
		}
		return n.toFixed(i === 0 ? 0 : 1) + ' ' + units[i];
	}

	function esc(s) {
		var div = document.createElement('div');
		div.textContent = String(s == null ? '' : s);
		return div.innerHTML;
	}

	function providerLabel(id) {
		var labels = { files: 'Fichiers', deck: 'Deck', collectives: 'Wiki (Collectives)', iaeasy: 'iaeasy.noschoixpourvous.com' };
		return labels[id] || id;
	}

	function render(data) {
		var html = '';

		if (!data.esOnline) {
			html += '<div class="nss-card nss-error">Elasticsearch injoignable (' + esc(data.elasticHost) + ')</div>';
		}

		// --- Architecture (vue d'ensemble) ---
		html += '<h3>Architecture</h3>';
		html += '<pre class="nss-schema">Recherche mot-cle (BM25)          Recherche par sens (kNN + LLM)\n' +
			'  Nextcloud IFullTextSearchManager    Requete -> embedding -> kNN\n' +
			'         |                                   |\n' +
			'         v                                   v\n' +
			'    Elasticsearch (' + esc(data.elasticIndex || '?') + ')\n' +
			'    documents (BM25) + passages (' + esc((data.embeddingBackfill || {}).vectorField || '?') + ')\n\n' +
			'Indexeurs :\n' +
			'  - cron BM25 (1 min)        : extraction texte -> Elasticsearch\n' +
			'  - cron embeddings (4h15)   : decoupage passages -> Ollama -> vecteurs\n' +
			'  - reclassement (a la requete, recherche par sens) : Ollama LLM\n\n' +
			'Details complets et schemas : page wiki "23 Architecture de Recherche+".</pre>';

		// --- Index Elasticsearch ---
		html += '<h3>Index Elasticsearch</h3>';
		html += '<div class="nss-cards">';
		html += '<div class="nss-card"><div class="nss-big">' + data.totalCount + '</div><div>documents (mot-cle)</div></div>';
		if (data.indexStats) {
			html += '<div class="nss-card"><div class="nss-big">' + fmtBytes(data.indexStats.sizeBytes) + '</div><div>taille de l\'index</div></div>';
			html += '<div class="nss-card"><div class="nss-big">' + data.indexStats.docCount + '</div><div>documents Lucene (total, passages inclus)</div></div>';
		}
		data.providers.forEach(function (p) {
			html += '<div class="nss-card"><div class="nss-big">' + p.count + '</div><div>' + esc(providerLabel(p.id)) + ' (mot-cle)</div></div>';
		});
		html += '</div>';

		// --- Base vectorielle / recherche par sens ---
		var eb = data.embeddingBackfill || {};
		var lastRun = eb.lastRun;
		html += '<h3>Base vectorielle (recherche par sens)</h3>';
		html += '<div class="nss-cards">';
		html += '<div class="nss-card"><div class="nss-big">' + (eb.totalPassages || 0) + '</div><div>passages indexes</div></div>';
		html += '<div class="nss-card"><div class="nss-big">' + (eb.totalChunkedDocuments || 0) + '</div><div>documents source couverts</div></div>';
		html += '<div class="nss-card"><div class="nss-big">' + (eb.vectorDims || '-') + '</div><div>dimensions (' + esc(eb.embeddingModel || '-') + ')</div></div>';
		html += '</div>';

		if (eb.providersChunked && eb.providersChunked.length) {
			html += '<table class="nss-table"><thead><tr><th>Connecteur</th><th>Documents (mot-cle)</th><th>Documents couverts (sens)</th></tr></thead><tbody>';
			data.providers.forEach(function (p) {
				var semantic = eb.providersChunked.filter(function (s) { return s.id === p.id; })[0];
				html += '<tr><td>' + esc(providerLabel(p.id)) + '</td><td>' + p.count + '</td><td>' + (semantic ? semantic.count : 0) + '</td></tr>';
			});
			html += '</tbody></table>';
		}

		html += '<table class="nss-table"><tbody>';
		html += '<tr><td>Champ vectoriel</td><td>' + esc(eb.vectorField || '-') + '</td></tr>';
		html += '<tr><td>Modele d\'embedding</td><td>' + esc(eb.embeddingModel || '-') + '</td></tr>';
		html += '<tr><td>Modele de reclassement (ranker)</td><td>' + esc(eb.rerankingModel || '-') + '</td></tr>';
		html += '<tr><td>Cron du backfill (quotidien, 4h15)</td><td>derniere execution : ' +
			(lastRun ? esc(fmtDate(lastRun.finishedAt)) : 'jamais') + '</td></tr>';
		if (lastRun) {
			html += '<tr><td>Resultat de la derniere execution</td><td>' +
				lastRun.documentsChunked + ' document(s), ' + lastRun.passagesCreated + ' passage(s) crees, ' +
				lastRun.skipped + ' ignores, ' + lastRun.errors + ' erreur(s)</td></tr>';
		}
		html += '</tbody></table>';
		html += '<button id="nss-embed-reindex-btn" class="button">Lancer le backfill des embeddings maintenant</button>';

		// --- Connecteurs ---
		html += '<h3>Connecteurs</h3>';
		html += '<p class="settings-hint">Une source de contenu indexee dans Recherche+ (chaque connecteur alimente a la fois la recherche mot-cle et la recherche par sens).</p>';
		var connectors = data.connectors || { active: [], proposed: [] };
		html += '<table class="nss-table"><thead><tr><th>Connecteur actif</th><th>Type</th></tr></thead><tbody>';
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
		if (connectors.active.some(function (c) { return c.id === 'iaeasy'; })) {
			html += '<button id="nss-iaeasy-reindex-btn" class="button">Synchroniser iaeasy maintenant</button>';
		}

		// --- Connecteurs natifs (OCR / groupfolders) ---
		html += '<h3>Connecteurs natifs Nextcloud</h3>';
		html += '<table class="nss-table"><tbody>';
		html += '<tr><td>Groupfolders indexes</td><td>' + (data.groupfoldersIndexed ? 'Oui' : 'Non') + '</td></tr>';
		html += '<tr><td>OCR (Tesseract) actif</td><td>' + (data.ocr.enabled ? 'Oui' : 'Non') + '</td></tr>';
		html += '<tr><td>OCR sur les PDF</td><td>' + (data.ocr.pdf ? 'Oui' : 'Non') + '</td></tr>';
		html += '<tr><td>Langues OCR</td><td>' + esc(data.ocr.lang || '-') + '</td></tr>';
		html += '</tbody></table>';

		// --- Execution / cron BM25 ---
		html += '<h3>Execution (mot-cle)</h3>';
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

		// --- Logs ---
		html += '<h3>Logs</h3><div id="nss-logs">Chargement...</div>';

		// --- Parametrage ---
		html += '<h3>Parametrage</h3><div id="nss-config">Chargement...</div>';

		container.innerHTML = html;

		var btn = document.getElementById('nss-reindex-btn');
		if (btn) {
			btn.addEventListener('click', triggerReindex);
		}

		var embedBtn = document.getElementById('nss-embed-reindex-btn');
		if (embedBtn) {
			embedBtn.addEventListener('click', triggerEmbedReindex);
		}

		var iaeasyBtn = document.getElementById('nss-iaeasy-reindex-btn');
		if (iaeasyBtn) {
			iaeasyBtn.addEventListener('click', triggerIaeasyReindex);
		}

		loadLogs();
		loadConfig();
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

	function triggerIaeasyReindex() {
		var btn = document.getElementById('nss-iaeasy-reindex-btn');
		if (btn) {
			btn.disabled = true;
			btn.textContent = 'Synchronisation iaeasy en tache de fond...';
		}
		fetch(OC.generateUrl('/apps/search_hub/admin/reindex-iaeasy'), {
			method: 'POST',
			headers: { requesttoken: OC.requestToken },
		}).then(function () {
			setTimeout(load, 3000);
		});
	}

	function loadLogs() {
		var el = document.getElementById('nss-logs');
		if (!el) {
			return;
		}
		fetch(OC.generateUrl('/apps/search_hub/admin/logs'), {
			headers: { requesttoken: OC.requestToken },
		})
			.then(function (r) { return r.json(); })
			.then(function (data) {
				var html = '';
				Object.keys(data.logs || {}).forEach(function (key) {
					var log = data.logs[key];
					html += '<h4>' + esc(log.label) + '</h4>';
					if (!log.available) {
						html += '<p class="settings-hint">Aucune execution manuelle enregistree.</p>';
					} else {
						html += '<pre class="nss-log">' + esc(log.lines.join('\n')) + '</pre>';
					}
				});
				if (data.hostOnlyLogs && data.hostOnlyLogs.length) {
					html += '<h4>Logs des cron automatiques (systeme de fichiers hote, pas lisibles ici)</h4>';
					html += '<ul>';
					data.hostOnlyLogs.forEach(function (l) {
						html += '<li><code>' + esc(l) + '</code></li>';
					});
					html += '</ul>';
				}
				el.innerHTML = html;
			})
			.catch(function () {
				el.innerHTML = '<p class="nss-error">Erreur de chargement des logs.</p>';
			});
	}

	function loadConfig() {
		var el = document.getElementById('nss-config');
		if (!el) {
			return;
		}
		fetch(OC.generateUrl('/apps/search_hub/admin/config'), {
			headers: { requesttoken: OC.requestToken },
		})
			.then(function (r) { return r.json(); })
			.then(function (config) {
				currentConfig = config;
				renderConfigForm(config);
			})
			.catch(function () {
				el.innerHTML = '<p class="nss-error">Erreur de chargement du parametrage.</p>';
			});
	}

	function renderConfigForm(config) {
		var el = document.getElementById('nss-config');
		var e = config.embedding;
		var r = config.reranking;

		var html = '<form id="nss-config-form">';
		html += '<h4>Recherche par sens - embedding</h4>';
		html += '<div class="nss-field"><label>Modele d\'embedding (Ollama)</label>' +
			'<input type="text" id="cfg-embedding-model" value="' + esc(e.model) + '"></div>';
		html += '<div class="nss-field"><label>Taille des passages (caracteres)</label>' +
			'<input type="number" id="cfg-chunkSize" value="' + e.chunkSize + '" min="500" max="20000"></div>';
		html += '<div class="nss-field"><label>Chevauchement entre passages (caracteres)</label>' +
			'<input type="number" id="cfg-chunkOverlap" value="' + e.chunkOverlap + '" min="0" max="5000"></div>';
		html += '<div class="nss-field"><label>Contenu minimal pour indexer (caracteres)</label>' +
			'<input type="number" id="cfg-minContentLen" value="' + e.minContentLen + '" min="1" max="1000"></div>';
		html += '<div class="nss-field"><label>Plafond de passages par document</label>' +
			'<input type="number" id="cfg-maxChunksPerDoc" value="' + e.maxChunksPerDoc + '" min="1" max="2000"></div>';
		html += '<div class="nss-field"><label>Extensions image exclues (separees par des virgules)</label>' +
			'<input type="text" id="cfg-imageExtensions" value="' + esc(e.imageExtensions.join(', ')) + '"></div>';
		html += '<div class="nss-field"><label>Extensions tableur exclues (separees par des virgules)</label>' +
			'<input type="text" id="cfg-spreadsheetExtensions" value="' + esc(e.spreadsheetExtensions.join(', ')) + '"></div>';
		html += '<div class="nss-field"><label>Dossiers de demo exclus (un par ligne, prefixe de chemin)</label>' +
			'<textarea id="cfg-demoPathPrefixes" rows="3">' + esc(e.demoPathPrefixes.join('\n')) + '</textarea></div>';
		html += '<div class="nss-field"><label>Titres exacts exclus (un par ligne)</label>' +
			'<textarea id="cfg-demoExactTitles" rows="4">' + esc(e.demoExactTitles.join('\n')) + '</textarea></div>';

		html += '<h4>Reclassement (ranker)</h4>';
		html += '<div class="nss-field"><label>Modele de reclassement (Ollama)</label>' +
			'<input type="text" id="cfg-reranking-model" value="' + esc(r.model) + '"></div>';
		html += '<div class="nss-field"><label>Nombre de candidats reclasses</label>' +
			'<input type="number" id="cfg-reranking-topN" value="' + r.topN + '" min="1" max="50"></div>';

		var ia = config.iaeasy || { apiBase: 'https://iaeasy.noschoixpourvous.com/api' };
		html += '<h4>Connecteur iaeasy</h4>';
		html += '<div class="nss-field"><label>URL de base de l\'API iaeasy</label>' +
			'<input type="text" id="cfg-iaeasy-apiBase" value="' + esc(ia.apiBase) + '"></div>';

		html += '<h4>Dictionnaire de synonymes / thesaurus</h4>';
		html += '<p class="settings-hint">Un groupe = des termes equivalents (ex: "IA, intelligence artificielle, AI"). Chercher l\'un elargit automatiquement la recherche aux autres.</p>';
		html += '<div id="nss-synonyms">';
		(config.synonyms || []).forEach(function (group, idx) {
			html += renderSynonymRow(idx, group.terms.join(', '));
		});
		html += '</div>';
		html += '<span class="nss-add-link" id="nss-add-synonym">+ Ajouter un groupe de synonymes</span>';

		html += '<div class="nss-form-actions">';
		html += '<button type="submit" class="button primary" id="nss-config-save">Enregistrer le parametrage</button>';
		html += '<span id="nss-config-saved" class="nss-saved-msg"></span>';
		html += '</div>';
		html += '</form>';

		el.innerHTML = html;

		document.getElementById('nss-add-synonym').addEventListener('click', function () {
			var wrap = document.getElementById('nss-synonyms');
			var idx = wrap.children.length;
			wrap.insertAdjacentHTML('beforeend', renderSynonymRow(idx, ''));
			bindSynonymRemove(wrap.lastElementChild);
		});

		Array.prototype.forEach.call(document.querySelectorAll('.nss-synonym-row'), bindSynonymRemove);

		document.getElementById('nss-config-form').addEventListener('submit', function (ev) {
			ev.preventDefault();
			saveConfig();
		});
	}

	function renderSynonymRow(idx, value) {
		return '<div class="nss-synonym-row" data-idx="' + idx + '">' +
			'<input type="text" class="nss-synonym-input" value="' + esc(value) + '" placeholder="terme1, terme2, terme3">' +
			'<span class="nss-remove-link">Retirer</span>' +
			'</div>';
	}

	function bindSynonymRemove(row) {
		var link = row.querySelector('.nss-remove-link');
		if (link) {
			link.addEventListener('click', function () {
				row.parentNode.removeChild(row);
			});
		}
	}

	function splitList(value) {
		return value.split(',').map(function (s) { return s.trim(); }).filter(function (s) { return s !== ''; });
	}

	function splitLines(value) {
		return value.split('\n').map(function (s) { return s.trim(); }).filter(function (s) { return s !== ''; });
	}

	function saveConfig() {
		var synonyms = [];
		Array.prototype.forEach.call(document.querySelectorAll('.nss-synonym-input'), function (input) {
			var terms = splitList(input.value);
			if (terms.length >= 2) {
				synonyms.push({ terms: terms });
			}
		});

		var payload = {
			embedding: {
				model: document.getElementById('cfg-embedding-model').value.trim(),
				chunkSize: parseInt(document.getElementById('cfg-chunkSize').value, 10),
				chunkOverlap: parseInt(document.getElementById('cfg-chunkOverlap').value, 10),
				minContentLen: parseInt(document.getElementById('cfg-minContentLen').value, 10),
				maxChunksPerDoc: parseInt(document.getElementById('cfg-maxChunksPerDoc').value, 10),
				imageExtensions: splitList(document.getElementById('cfg-imageExtensions').value),
				spreadsheetExtensions: splitList(document.getElementById('cfg-spreadsheetExtensions').value),
				demoPathPrefixes: splitLines(document.getElementById('cfg-demoPathPrefixes').value),
				demoExactTitles: splitLines(document.getElementById('cfg-demoExactTitles').value),
			},
			reranking: {
				model: document.getElementById('cfg-reranking-model').value.trim(),
				topN: parseInt(document.getElementById('cfg-reranking-topN').value, 10),
			},
			iaeasy: {
				apiBase: document.getElementById('cfg-iaeasy-apiBase').value.trim(),
			},
			synonyms: synonyms,
		};

		var saveBtn = document.getElementById('nss-config-save');
		var savedMsg = document.getElementById('nss-config-saved');
		saveBtn.disabled = true;
		saveBtn.textContent = 'Enregistrement...';

		fetch(OC.generateUrl('/apps/search_hub/admin/config'), {
			method: 'POST',
			headers: { requesttoken: OC.requestToken, 'Content-Type': 'application/json' },
			body: JSON.stringify(payload),
		})
			.then(function (r) { return r.json(); })
			.then(function (result) {
				saveBtn.disabled = false;
				saveBtn.textContent = 'Enregistrer le parametrage';
				if (result.saved) {
					savedMsg.textContent = 'Enregistre.';
					setTimeout(function () { savedMsg.textContent = ''; }, 3000);
				} else {
					savedMsg.textContent = 'Erreur : ' + (result.error || 'inconnue');
				}
			})
			.catch(function () {
				saveBtn.disabled = false;
				saveBtn.textContent = 'Enregistrer le parametrage';
				savedMsg.textContent = 'Erreur reseau.';
			});
	}

	document.addEventListener('DOMContentLoaded', load);
})();
