<?php
\OCP\Util::addScript('search_hub', 'assistant');
\OCP\Util::addStyle('search_hub', 'assistant');
?>
<div id="content" class="app-search-hub">
	<div id="as-app" class="as-empty">
		<div id="as-center">
			<div id="as-header">
				<div id="as-icon">✦</div>
				<h1>Assistant documentaire</h1>
				<p id="as-subtitle">Répond uniquement à partir des documents auxquels vous avez accès — et se tait plutôt que d'inventer.</p>
			</div>
			<form id="as-form">
				<input type="text" id="as-input" placeholder="Posez votre question..." autocomplete="off" autofocus />
				<button type="submit" id="as-submit">Envoyer</button>
			</form>
			<div id="as-suggestions">
				<button type="button" class="as-suggestion-chip" data-question="Quel modèle d'embedding est utilisé pour la recherche par sens, et pourquoi ce choix ?">Quel modèle d'embedding est utilisé pour la recherche par sens ?</button>
				<button type="button" class="as-suggestion-chip" data-question="Quelles sont les sources de bruit déjà identifiées dans la recherche par sens ?">Quelles sont les sources de bruit dans la recherche par sens ?</button>
				<button type="button" class="as-suggestion-chip" data-question="Comment est structuré le pipeline d'indexation par embeddings ?">Comment fonctionne le pipeline d'indexation ?</button>
			</div>
		</div>
		<div id="as-thread"></div>
	</div>
</div>
