(function () {
	'use strict';

	document.addEventListener('click', function (ev) {
		var trigger = ev.target.closest(
			'#unified-search, ' +
			'.unified-search, ' +
			'[data-cy-unified-search-trigger], ' +
			'.header-menu-trigger.unified-search, ' +
			'a.icon-search'
		);

		if (!trigger) {
			return;
		}

		// Ne redirige que le bouton d'ouverture de la recherche unifiee dans l'en-tete,
		// pas notre propre app (qui n'a aucun de ces selecteurs) ni un champ de recherche
		// deja ouvert a l'interieur d'une app (Fichiers, etc.).
		if (trigger.closest('#app-search_hub, .sh-searchbar')) {
			return;
		}

		ev.preventDefault();
		ev.stopPropagation();
		window.location.href = OC.generateUrl('/apps/search_hub/');
	}, true);
})();
