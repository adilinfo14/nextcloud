<?php
/** @var \OCP\IURLGenerator $urlGenerator */
\OCP\Util::addScript('search_hub', 'admin');
\OCP\Util::addStyle('search_hub', 'admin');
?>
<div id="nc-search-status" class="section">
	<h2><?php p($l->t('Recherche+ : etat de l\'indexation')); ?></h2>
	<p class="settings-hint"><?php p($l->t('Vue d\'ensemble de l\'indexation Elasticsearch dont depend Recherche+ : connecteurs actifs, OCR, dernieres executions.')); ?></p>

	<div id="nss-content">
		<p><?php p($l->t('Chargement...')); ?></p>
	</div>
</div>
