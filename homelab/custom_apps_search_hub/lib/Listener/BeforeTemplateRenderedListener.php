<?php

declare(strict_types=1);

namespace OCA\SearchHub\Listener;

use OCA\SearchHub\AppInfo\Application;
use OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Util;

/**
 * Injecte le script qui redirige la loupe de recherche unifiee (en-tete Nextcloud)
 * vers Recherche+. Vit dans CE module (pas dans tk_theme) pour que desactiver ou
 * desinstaller Recherche+ retire aussi automatiquement cette redirection, sans
 * laisser un lien casse vers une page qui n'existe plus.
 *
 * @template-implements IEventListener<BeforeTemplateRenderedEvent>
 */
class BeforeTemplateRenderedListener implements IEventListener {
	public function handle(Event $event): void {
		if (!$event instanceof BeforeTemplateRenderedEvent) {
			return;
		}

		Util::addScript(Application::APP_ID, 'header-search');
	}
}
