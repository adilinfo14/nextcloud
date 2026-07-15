(function () {
	'use strict';

	var app = document.getElementById('as-app');
	var thread = document.getElementById('as-thread');
	var form = document.getElementById('as-form');
	var input = document.getElementById('as-input');
	var submitBtn = document.getElementById('as-submit');

	function esc(s) {
		var div = document.createElement('div');
		div.textContent = String(s == null ? '' : s);
		return div.innerHTML;
	}

	function safeHref(link) {
		if (typeof link !== 'string' || link === '') {
			return '#';
		}
		if (/^(\/|https:\/\/|http:\/\/)/i.test(link)) {
			return esc(link);
		}
		return '#';
	}

	// Rend les citations [Source N] cliquables si une source N existe, en pointant
	// vers son lien - sinon les laisse en texte brut (ne jamais inventer un lien).
	function renderAnswerWithCitations(text, sources) {
		var escaped = esc(text);
		return escaped.replace(/\[Source (\d+)\]/g, function (match, num) {
			var idx = parseInt(num, 10) - 1;
			if (sources && sources[idx]) {
				return '<a href="' + safeHref(sources[idx].link) + '" target="_blank" rel="noopener" class="as-citation">' + esc(match) + '</a>';
			}
			return '<span class="as-citation as-citation-unresolved">' + esc(match) + '</span>';
		});
	}

	function addUserMessage(text) {
		var el = document.createElement('div');
		el.className = 'as-message as-message-user';
		el.innerHTML = '<div class="as-bubble">' + esc(text) + '</div>';
		thread.appendChild(el);
		thread.scrollTop = thread.scrollHeight;
	}

	function addPendingMessage() {
		var el = document.createElement('div');
		el.className = 'as-message as-message-assistant as-message-pending';
		el.innerHTML = '<div class="as-bubble"><span class="as-spinner"></span> Recherche dans vos documents accessibles...</div>';
		thread.appendChild(el);
		thread.scrollTop = thread.scrollHeight;
		return el;
	}

	function renderAbstained(el, data) {
		el.className = 'as-message as-message-assistant as-message-abstained';
		el.innerHTML = '<div class="as-bubble">' +
			'<div class="as-abstain-icon">◻︎</div>' +
			'<div>' + esc(data.message) + '</div>' +
			'</div>';
	}

	function renderAnswer(el, data) {
		el.className = 'as-message as-message-assistant';
		var sourcesHtml = '';
		if (data.sources && data.sources.length) {
			sourcesHtml = '<div class="as-sources"><div class="as-sources-label">Sources utilisées :</div>' +
				data.sources.map(function (s, i) {
					return '<a href="' + safeHref(s.link) + '" target="_blank" rel="noopener" class="as-source-chip">' +
						'[' + (i + 1) + '] ' + esc(s.title) + '</a>';
				}).join('') + '</div>';
		}
		var verifiedBadge = data.verified
			? '<span class="as-badge as-badge-verified">Vérifié</span>'
			: '<span class="as-badge as-badge-corrected">Corrigé après vérification</span>';
		el.innerHTML = '<div class="as-bubble">' +
			'<div class="as-answer-text">' + renderAnswerWithCitations(data.answer, data.sources) + '</div>' +
			verifiedBadge +
			sourcesHtml +
			'</div>';
	}

	function renderError(el, message) {
		el.className = 'as-message as-message-assistant as-message-error';
		el.innerHTML = '<div class="as-bubble">' + esc(message) + '</div>';
	}

	function ask(question) {
		app.classList.remove('as-empty');
		addUserMessage(question);
		var pendingEl = addPendingMessage();
		input.value = '';
		input.disabled = true;
		submitBtn.disabled = true;

		var body = new URLSearchParams();
		body.set('question', question);

		fetch(OC.generateUrl('/apps/search_hub/api/assistant/ask'), {
			method: 'POST',
			headers: { requesttoken: OC.requestToken, 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString(),
		})
			.then(function (r) {
				if (!r.ok) {
					throw new Error('HTTP ' + r.status);
				}
				return r.json();
			})
			.then(function (data) {
				if (data.abstained) {
					renderAbstained(pendingEl, data);
				} else if (data.answer) {
					renderAnswer(pendingEl, data);
				} else {
					renderError(pendingEl, "Une erreur est survenue, réessayez.");
				}
			})
			.catch(function () {
				renderError(pendingEl, "Une erreur est survenue lors de la génération de la réponse.");
			})
			.finally(function () {
				input.disabled = false;
				submitBtn.disabled = false;
				input.focus();
				thread.scrollTop = thread.scrollHeight;
			});
	}

	form.addEventListener('submit', function (ev) {
		ev.preventDefault();
		var question = input.value.trim();
		if (question === '') {
			return;
		}
		ask(question);
	});

	Array.prototype.forEach.call(document.querySelectorAll('.as-suggestion-chip'), function (chip) {
		chip.addEventListener('click', function () {
			ask(chip.getAttribute('data-question'));
		});
	});
})();
