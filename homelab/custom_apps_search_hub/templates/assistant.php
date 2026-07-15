<?php
\OCP\Util::addScript('search_hub', 'assistant');
\OCP\Util::addStyle('search_hub', 'assistant');
?>
<div id="content" class="app-search-hub">
	<div id="as-app">
		<div id="as-header">
			<h1>Assistant documentaire</h1>
			<p id="as-subtitle">Répond uniquement à partir des documents auxquels vous avez accès — et se tait plutôt que d'inventer.</p>
		</div>
		<div id="as-thread"></div>
		<form id="as-form">
			<input type="text" id="as-input" placeholder="Posez votre question..." autocomplete="off" autofocus />
			<button type="submit" id="as-submit">Envoyer</button>
		</form>
	</div>
</div>
