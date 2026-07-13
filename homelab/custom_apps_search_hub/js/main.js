(function () {
	'use strict';

	var state = {
		term: '',
		provider: '',
		tag: '',
		collective: '',
		fileType: '',
		chapter: '',
		period: '',
		sort: 'relevance',
		weights: { wRelevance: 50, wTitle: 20, wCoverage: 20, wRecency: 10 },
	};

	var WEIGHT_LABELS = {
		wRelevance: 'Pertinence texte',
		wTitle: 'Titre',
		wCoverage: 'Couverture de la requete',
		wRecency: 'Fraicheur',
	};

	var SORT_LABELS = {
		relevance: 'Pertinence',
		weighted: 'Score pondere (recommande)',
		title_boost: 'Pertinence (titre prioritaire)',
		date: 'Plus recent',
		title: 'Titre (A-Z)',
	};

	var lastData = null;
	var debounceTimer = null;

	var PROVIDER_LABELS = { files: 'Fichiers', collectives: 'Wiki', deck: 'Deck' };
	var TYPE_LABELS = {
		pdf: 'PDF', image: 'Image', document: 'Document texte', tableur: 'Tableur',
		presentation: 'Presentation', texte: 'Texte', 'page-wiki': 'Page wiki', carte: 'Carte Deck', autre: 'Autre',
	};
	var PERIOD_LABELS = { '24h': 'Dernieres 24h', '7j': '7 derniers jours', '30j': '30 derniers jours' };

	function esc(s) {
		var div = document.createElement('div');
		div.textContent = String(s == null ? '' : s);
		return div.innerHTML;
	}

	// Defense en profondeur : esc() protege contre l'injection de balises HTML, mais
	// pas contre un lien "javascript:" insere comme attribut href. Les liens viennent
	// normalement de sources internes de confiance (providers Nextcloud), mais on ne
	// fait jamais confiance aveuglement a une donnee affichee dans le DOM.
	function safeHref(link) {
		if (typeof link !== 'string') {
			return '#';
		}
		if (/^(\/|https:\/\/|http:\/\/)/i.test(link)) {
			return esc(link);
		}
		return '#';
	}

	function highlight(text, term) {
		if (!term) {
			return esc(text);
		}
		var escaped = esc(text);
		var safeTerm = term.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
		try {
			var re = new RegExp('(' + safeTerm + ')', 'ig');
			return escaped.replace(re, '<mark>$1</mark>');
		} catch (e) {
			return escaped;
		}
	}

	function highlightTerms(text, terms) {
		var escaped = esc(text);
		if (!terms || !terms.length) {
			return escaped;
		}
		var safeTerms = terms.map(function (t) { return t.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'); });
		try {
			var re = new RegExp('(' + safeTerms.join('|') + ')', 'ig');
			return escaped.replace(re, '<mark>$1</mark>');
		} catch (e) {
			return escaped;
		}
	}

	function previewLabel(fileType) {
		if (fileType === 'pdf') {
			return 'Extrait PDF';
		}
		return 'Previsualisation';
	}

	function fmtDate(ts) {
		if (!ts) {
			return '';
		}
		return new Date(ts * 1000).toLocaleDateString('fr-FR');
	}

	function runSearch() {
		var input = document.getElementById('sh-input');
		state.term = input.value.trim();

		if (state.term === '') {
			lastData = null;
			renderAll();
			return;
		}

		document.getElementById('sh-results').innerHTML = '<div id="sh-loading">Recherche en cours...</div>';

		var params = new URLSearchParams({
			term: state.term,
			provider: state.provider,
			tag: state.tag,
			collective: state.collective,
			fileType: state.fileType,
			chapter: state.chapter,
			period: state.period,
			sort: state.sort,
			wRelevance: state.weights.wRelevance,
			wTitle: state.weights.wTitle,
			wCoverage: state.weights.wCoverage,
			wRecency: state.weights.wRecency,
		});

		fetch(OC.generateUrl('/apps/search_hub/api/search') + '?' + params.toString(), {
			headers: { requesttoken: OC.requestToken },
		})
			.then(function (r) { return r.json(); })
			.then(function (data) {
				lastData = data;
				renderAll();
			})
			.catch(function () {
				document.getElementById('sh-results').innerHTML = '<div id="sh-empty">Erreur lors de la recherche.</div>';
			});
	}

	function renderAll() {
		renderTabs();
		renderFilters();
		renderResults();
	}

	function renderTabs() {
		var tabsEl = document.getElementById('sh-tabs');
		var facets = (lastData && lastData.facets) || { providers: {} };
		var total = lastData ? lastData.totalUnfiltered : 0;

		var tabs = [{ id: '', label: 'Tous', count: total }];
		['files', 'collectives', 'deck'].forEach(function (p) {
			tabs.push({ id: p, label: PROVIDER_LABELS[p], count: facets.providers[p] || 0 });
		});

		tabsEl.innerHTML = tabs.map(function (t) {
			var active = state.provider === t.id ? ' active' : '';
			return '<div class="sh-tab' + active + '" data-provider="' + t.id + '">' + esc(t.label) +
				'<span class="sh-tab-count">' + t.count + '</span></div>';
		}).join('');

		Array.prototype.forEach.call(tabsEl.querySelectorAll('.sh-tab'), function (el) {
			el.addEventListener('click', function () {
				state.provider = el.getAttribute('data-provider');
				runSearch();
			});
		});
	}

	function renderFilterGroup(title, options, labels, stateKey) {
		if (!options || Object.keys(options).length === 0) {
			return '';
		}
		var html = '<div class="sh-filter-group"><h4>' + esc(title) + '</h4>';
		Object.keys(options).forEach(function (key) {
			var active = state[stateKey] === key ? ' active' : '';
			var label = (labels && labels[key]) || key;
			html += '<div class="sh-filter-option' + active + '" data-key="' + stateKey + '" data-value="' + esc(key) + '">' +
				'<span>' + esc(label) + '</span><span class="sh-count">' + options[key] + '</span></div>';
		});
		html += '</div>';
		return html;
	}

	function renderFilters() {
		var filtersEl = document.getElementById('sh-filters');
		if (!lastData) {
			filtersEl.innerHTML = '';
			return;
		}
		var facets = lastData.facets;

		var html = '';
		html += renderFilterGroup('Etiquettes', facets.tags, null, 'tag');
		html += renderFilterGroup('Collective', facets.collectives, null, 'collective');
		html += renderFilterGroup('Type de document', facets.fileTypes, TYPE_LABELS, 'fileType');
		html += renderFilterGroup('Chapitre / sous-chapitre', facets.chapters, null, 'chapter');
		html += renderFilterGroup('Periode', facets.periods, PERIOD_LABELS, 'period');

		filtersEl.innerHTML = html;

		Array.prototype.forEach.call(filtersEl.querySelectorAll('.sh-filter-option'), function (el) {
			el.addEventListener('click', function () {
				var key = el.getAttribute('data-key');
				var value = el.getAttribute('data-value');
				state[key] = (state[key] === value) ? '' : value;
				runSearch();
			});
		});
	}

	function renderResults() {
		var resultsEl = document.getElementById('sh-results');

		if (!lastData) {
			resultsEl.innerHTML = '<div id="sh-empty">Commencez a taper pour rechercher.</div>';
			return;
		}

		var sortOptions = Object.keys(SORT_LABELS).map(function (key) {
			var selected = state.sort === key ? ' selected' : '';
			return '<option value="' + key + '"' + selected + '>' + esc(SORT_LABELS[key]) + '</option>';
		}).join('');
		var toolbarHtml = '<div id="sh-toolbar">' +
			'<span>' + lastData.total + ' resultat(s)</span>' +
			'<label>Trier par : <select id="sh-sort">' + sortOptions + '</select></label>' +
			'</div>';

		var weightsHtml = (state.sort === 'weighted' && lastData.isAdmin) ? renderWeightsPanel() : '';

		if (lastData.results.length === 0) {
			resultsEl.innerHTML = toolbarHtml + weightsHtml + '<div id="sh-empty">Aucun resultat pour cette recherche.</div>';
			bindSortSelect();
			bindWeightInputs();
			return;
		}

		resultsEl.innerHTML = toolbarHtml + weightsHtml + lastData.results.map(function (r, idx) {
			var excerpts = (r.excerpts && r.excerpts.length) ? r.excerpts : [];
			var excerptsHtml = excerpts.map(function (ex) {
				return '<div class="sh-result-excerpt">' + highlight(ex, state.term) + '</div>';
			}).join('');

			var titleParts = r.title.split('/');
			var shortTitle = titleParts[titleParts.length - 1].replace(/\.[a-zA-Z0-9]+$/, '');

			var meta = [];
			meta.push('<span class="sh-badge">' + esc(PROVIDER_LABELS[r.providerId] || r.providerId) + '</span>');
			if (r.collective) {
				meta.push(esc(r.collective));
			}
			if (r.chapter) {
				meta.push(esc(r.chapter));
			}
			if (r.modifiedTime) {
				meta.push(fmtDate(r.modifiedTime));
			}
			meta.push('<span class="sh-result-path">' + esc(r.title) + '</span>');

			var matchedHtml = (r.matchedTerms && r.matchedTerms.length)
				? '<div class="sh-result-matched">Correspond a : ' + r.matchedTerms.map(esc).join(', ') + '</div>'
				: '<div class="sh-result-matched sh-result-matched-elsewhere">Correspond ailleurs dans le document</div>';

			return '<div class="sh-result">' +
				'<a href="' + safeHref(r.link) + '" target="_blank" rel="noopener" class="sh-result-title">' + esc(shortTitle) + '</a>' +
				excerptsHtml +
				matchedHtml +
				'<div class="sh-result-meta">' + meta.join('<span>&middot;</span>') +
				'<span class="sh-preview-btn" data-idx="' + idx + '">' + esc(previewLabel(r.fileType)) + '</span>' +
				'</div>' +
				'</div>';
		}).join('');

		Array.prototype.forEach.call(resultsEl.querySelectorAll('.sh-preview-btn'), function (el) {
			el.addEventListener('click', function () {
				var idx = parseInt(el.getAttribute('data-idx'), 10);
				openPreview(lastData.results[idx]);
			});
		});

		bindSortSelect();
		bindWeightInputs();
	}

	function bindSortSelect() {
		var sortEl = document.getElementById('sh-sort');
		if (sortEl) {
			sortEl.addEventListener('change', function () {
				state.sort = sortEl.value;
				runSearch();
			});
		}
	}

	function renderWeightsPanel() {
		var total = Object.keys(state.weights).reduce(function (sum, k) { return sum + state.weights[k]; }, 0) || 1;

		var rows = Object.keys(WEIGHT_LABELS).map(function (key) {
			var pct = Math.round((state.weights[key] / total) * 100);
			return '<div class="sh-weight-row">' +
				'<label>' + esc(WEIGHT_LABELS[key]) + '</label>' +
				'<input type="range" min="0" max="100" value="' + state.weights[key] + '" data-weight="' + key + '" id="sh-weight-' + key + '" />' +
				'<span class="sh-weight-pct" id="sh-weight-pct-' + key + '">' + pct + '%</span>' +
				'</div>';
		}).join('');

		return '<div id="sh-weights-panel">' +
			'<h4>Reglage du score pondere</h4>' +
			rows +
			'<span class="sh-weight-reset" id="sh-weight-reset">Reinitialiser</span>' +
			'</div>';
	}

	function bindWeightInputs() {
		var panel = document.getElementById('sh-weights-panel');
		if (!panel) {
			return;
		}

		Object.keys(WEIGHT_LABELS).forEach(function (key) {
			var input = document.getElementById('sh-weight-' + key);
			if (!input) {
				return;
			}
			input.addEventListener('input', function () {
				state.weights[key] = parseInt(input.value, 10);
				updateWeightPercentages();
			});
			input.addEventListener('change', function () {
				runSearch();
			});
		});

		var resetBtn = document.getElementById('sh-weight-reset');
		if (resetBtn) {
			resetBtn.addEventListener('click', function () {
				state.weights = { wRelevance: 50, wTitle: 20, wCoverage: 20, wRecency: 10 };
				runSearch();
			});
		}
	}

	function updateWeightPercentages() {
		var total = Object.keys(state.weights).reduce(function (sum, k) { return sum + state.weights[k]; }, 0) || 1;
		Object.keys(WEIGHT_LABELS).forEach(function (key) {
			var el = document.getElementById('sh-weight-pct-' + key);
			if (el) {
				el.textContent = Math.round((state.weights[key] / total) * 100) + '%';
			}
		});
	}

	var previewMarks = [];
	var previewMarkIndex = -1;

	function openPreview(result) {
		var panel = document.getElementById('sh-preview');
		var terms = (result.matchedTerms && result.matchedTerms.length) ? result.matchedTerms : state.term.split(/\s+/);

		var bodyHtml;
		var hasFulltext = !!result.fullContent;
		if (hasFulltext) {
			bodyHtml = '<div class="sh-preview-fulltext" id="sh-preview-fulltext">' +
				highlightTerms(result.fullContent, terms) + '</div>';
		} else {
			var excerpts = (result.excerpts && result.excerpts.length) ? result.excerpts : [];
			bodyHtml = excerpts.length
				? excerpts.map(function (ex) { return '<div class="sh-preview-excerpt">' + highlight(ex, state.term) + '</div>'; }).join('')
				: '<p>Aucun contenu disponible pour cet element (ex : carte Deck).</p>';
		}

		var titleParts = result.title.split('/');
		var shortTitle = titleParts[titleParts.length - 1].replace(/\.[a-zA-Z0-9]+$/, '');

		var navHtml = hasFulltext
			? '<div class="sh-preview-nav" id="sh-preview-nav">' +
				'<span class="sh-preview-nav-btn" id="sh-preview-prev">&lsaquo; Precedent</span>' +
				'<span class="sh-preview-nav-count" id="sh-preview-count"></span>' +
				'<span class="sh-preview-nav-btn" id="sh-preview-next">Suivant &rsaquo;</span>' +
			'</div>'
			: '';

		panel.innerHTML =
			'<div class="sh-preview-header">' +
			'<span class="sh-badge">' + esc(previewLabel(result.fileType)) + '</span>' +
			'<span class="sh-preview-close" id="sh-preview-close">&times;</span>' +
			'</div>' +
			'<h3>' + esc(shortTitle) + '</h3>' +
			'<div class="sh-preview-meta">' + esc(PROVIDER_LABELS[result.providerId] || result.providerId) +
			(result.collective ? ' &middot; ' + esc(result.collective) : '') + '<br>' + esc(result.title) + '</div>' +
			'<a class="button primary" href="' + safeHref(result.link) + '" target="_blank" rel="noopener">Ouvrir le fichier</a>' +
			navHtml +
			bodyHtml;

		panel.classList.add('open');
		document.getElementById('sh-preview-close').addEventListener('click', closePreview);

		previewMarks = Array.prototype.slice.call(panel.querySelectorAll('mark'));
		previewMarkIndex = -1;

		if (previewMarks.length) {
			goToMark(0);
			document.getElementById('sh-preview-prev').addEventListener('click', function () {
				goToMark(previewMarkIndex - 1 < 0 ? previewMarks.length - 1 : previewMarkIndex - 1);
			});
			document.getElementById('sh-preview-next').addEventListener('click', function () {
				goToMark((previewMarkIndex + 1) % previewMarks.length);
			});
		}
	}

	function goToMark(idx) {
		if (!previewMarks.length) {
			return;
		}
		if (previewMarkIndex >= 0 && previewMarks[previewMarkIndex]) {
			previewMarks[previewMarkIndex].classList.remove('sh-mark-active');
		}
		previewMarkIndex = idx;
		var mark = previewMarks[previewMarkIndex];
		mark.classList.add('sh-mark-active');
		mark.scrollIntoView({ block: 'center' });
		var counter = document.getElementById('sh-preview-count');
		if (counter) {
			counter.textContent = (previewMarkIndex + 1) + ' / ' + previewMarks.length;
		}
	}

	function closePreview() {
		document.getElementById('sh-preview').classList.remove('open');
	}

	function init() {
		var input = document.getElementById('sh-input');
		input.addEventListener('input', function () {
			clearTimeout(debounceTimer);
			debounceTimer = setTimeout(runSearch, 350);
		});
		renderAll();
	}

	document.addEventListener('DOMContentLoaded', init);
})();
