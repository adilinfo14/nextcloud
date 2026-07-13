<?php
\OCP\Util::addScript('search_hub', 'main');
\OCP\Util::addStyle('search_hub', 'main');
?>
<div id="content" class="app-search-hub">
	<div id="sh-app">
		<div id="sh-searchbar">
			<div id="sh-input-wrap">
				<input type="text" id="sh-input" placeholder="Rechercher dans les fichiers, le wiki, Deck..." autocomplete="off" />
				<div id="sh-suggestions"></div>
			</div>
			<span id="sh-neural-toggle" title="Recherche par sens (similarite semantique plutot que mot-cle exact)">Recherche par sens</span>
		</div>

		<div id="sh-tabs"></div>

		<div id="sh-body">
			<div id="sh-filters"></div>
			<div id="sh-results"></div>
		</div>
	</div>
	<div id="sh-preview" class="sh-preview-panel"></div>
</div>
