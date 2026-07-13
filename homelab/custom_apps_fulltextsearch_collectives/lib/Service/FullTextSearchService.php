<?php

declare(strict_types=1);

namespace OCA\FullTextSearch_Collectives\Service;

use OC\FullTextSearch\Model\DocumentAccess;
use OCA\Collectives\Service\PageService;
use OCA\FullTextSearch_Collectives\Provider\CollectivesProvider;
use OCP\Files\NotFoundException;
use OCP\FullTextSearch\Model\IDocumentAccess;
use OCP\FullTextSearch\Model\IIndexDocument;
use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\IUserSession;

class FullTextSearchService {
	/** @var array<int, string>|null collectiveId => slug, cache pour resolveTitleAndChapter() */
	private static ?array $collectiveSlugs = null;

	public function __construct(
		private IDBConnection $dbConnection,
		private PageService $pageService,
		private IUserManager $userManager,
		private IUserSession $userSession,
	) {
	}

	/**
	 * Toutes les pages (non corbeillees) des collectives dont $userId est membre du cercle.
	 * Le lien page -> collective n'existe pas en base (pas de colonne collective_id sur
	 * collectives_pages) : il se deduit du chemin physique du fichier, qui contient
	 * toujours "/collectives/<id>/".
	 *
	 * @return array<int, array{fileId: int, collectiveId: int}>
	 */
	public function getPagesFromUser(string $userId): array {
		$memberCollectiveIds = $this->getCollectiveIdsForUser($userId);
		if (empty($memberCollectiveIds)) {
			return [];
		}

		$qb = $this->dbConnection->getQueryBuilder();
		$qb->select('cp.file_id', 'fc.path')
			->from('collectives_pages', 'cp')
			->innerJoin('cp', 'filecache', 'fc', $qb->expr()->eq('cp.file_id', 'fc.fileid'))
			->where($qb->expr()->isNull('cp.trash_timestamp'));

		$result = $qb->executeQuery();
		$pages = [];
		while ($row = $result->fetch()) {
			$collectiveId = $this->extractCollectiveIdFromPath($row['path']);
			if ($collectiveId !== null && in_array($collectiveId, $memberCollectiveIds, true)) {
				$pages[] = [
					'fileId' => (int)$row['file_id'],
					'collectiveId' => $collectiveId,
				];
			}
		}
		$result->closeCursor();

		return $pages;
	}

	public function generateIndexDocumentFromPage(int $fileId): IIndexDocument {
		/** @psalm-var IIndexDocument */
		return new \OC\FullTextSearch\Model\IndexDocument(CollectivesProvider::PROVIDER_ID, (string)$fileId);
	}

	public function fillIndexDocument(IIndexDocument $document): void {
		$fileId = (int)$document->getId();

		$collectiveId = $this->getCollectiveIdForPage($fileId);
		if ($collectiveId === null) {
			throw new NotFoundException('Page introuvable en base : ' . $fileId);
		}

		$memberUserId = $this->getAnyMemberUserId($collectiveId);
		if ($memberUserId === null) {
			throw new NotFoundException('Aucun membre trouve pour la collective : ' . $collectiveId);
		}

		$member = $this->userManager->get($memberUserId);
		if ($member === null) {
			throw new NotFoundException('Utilisateur membre introuvable : ' . $memberUserId);
		}
		$this->userSession->setUser($member);
		\OC_Util::setupFS($memberUserId);

		try {
			$file = $this->pageService->getPageFile($collectiveId, $fileId, $memberUserId);
		} catch (\Throwable $e) {
			throw new NotFoundException('Page introuvable via PageService : ' . $fileId . ' (' . $e->getMessage() . ')');
		}

		$row = $this->loadNameAndPath($fileId);
		[$title, $chapter] = $row !== null
			? $this->resolveTitleAndChapter((string)$row['name'], (string)$row['path'], $collectiveId)
			: [pathinfo($file->getName(), PATHINFO_FILENAME), null];

		$content = $file->getContent();

		$document->setTitle($title);
		$document->setContent($content);
		$document->setAccess($this->generateDocumentAccessFromFileId($fileId, $collectiveId));

		// Chapitre/sous-chapitre parent : expose comme metatag pour permettre a search_hub
		// de le presenter en tant que facette de filtrage.
		if ($chapter !== null) {
			$document->setMetaTags([$chapter]);
		}
	}

	private function extractCollectiveIdFromPath(?string $path): ?int {
		if ($path === null || !preg_match('#/collectives/(\d+)/#', $path, $matches)) {
			return null;
		}

		return (int)$matches[1];
	}

	private function getCollectiveIdForPage(int $fileId): ?int {
		$qb = $this->dbConnection->getQueryBuilder();
		$qb->select('path')
			->from('filecache')
			->where($qb->expr()->eq('fileid', $qb->createNamedParameter($fileId, \PDO::PARAM_INT)));
		$result = $qb->executeQuery();
		$path = $result->fetchOne();
		$result->closeCursor();

		return $path !== false ? $this->extractCollectiveIdFromPath($path) : null;
	}

	public function getPageLink(int $fileId): ?string {
		$collectiveId = $this->getCollectiveIdForPage($fileId);
		if ($collectiveId === null) {
			return null;
		}

		$qb = $this->dbConnection->getQueryBuilder();
		$qb->select('slug')
			->from('collectives')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($collectiveId, \PDO::PARAM_INT)));
		$result = $qb->executeQuery();
		$slug = $result->fetchOne();
		$result->closeCursor();

		if ($slug === false || $slug === '') {
			return null;
		}

		// L'app Collectives route sur "<slug>-<id>", jamais le slug seul (confirme en lisant
		// OCA\Collectives\Db\Collective::getUrlPath()) : sans le "-<id>", le lien tombe sur
		// "Collectif introuvable" cote frontend Vue. Piege trouve tardivement car jamais
		// clique reellement avant (seulement verifie via reflexion/tests automatises).
		return '/apps/collectives/' . rawurlencode($slug . '-' . $collectiveId) . '?fileId=' . $fileId;
	}

	private function loadNameAndPath(int $fileId): ?array {
		$qb = $this->dbConnection->getQueryBuilder();
		$qb->select('name', 'path')
			->from('filecache')
			->where($qb->expr()->eq('fileid', $qb->createNamedParameter($fileId, \PDO::PARAM_INT)));
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();

		return $row !== false ? $row : null;
	}

	/**
	 * Titre affiche et chapitre/sous-chapitre parent d'une page, deduits UNIQUEMENT du
	 * chemin physique du fichier - PAS de subpage_order (colonne cosmetique qui ne porte
	 * qu'un indice d'ordre d'affichage, pas la relation parent/enfant : verifie en base,
	 * la plupart des pages ne referencent jamais leurs propres enfants dans ce champ).
	 * La hierarchie reelle de Collectives EST la structure de dossiers, un point c'est
	 * tout : le dossier contenant directement le fichier EST son chapitre.
	 *
	 * Convention de titre reprise de OCA\Collectives\Model\PageInfo::fromFile() : une page
	 * qui a des sous-pages est stockee comme un dossier contenant "Readme.md" - le titre
	 * reel est alors celui du dossier, jamais "Readme".
	 *
	 * @return array{0: string, 1: ?string} [titre, chapitre-parent-ou-null]
	 */
	private function resolveTitleAndChapter(string $fileName, string $path, ?int $collectiveId): array {
		if (!preg_match('#/collectives/\d+/(.*)$#', $path, $m)) {
			return [pathinfo($fileName, PATHINFO_FILENAME), null];
		}

		$segments = explode('/', $m[1]);
		array_pop($segments); // retire le nom du fichier lui-meme : ne reste que les dossiers

		if ($fileName === 'Readme.md') {
			if (empty($segments)) {
				// Page d'accueil de la collective elle-meme : pas de dossier, pas de chapitre.
				return [$this->getCollectiveSlugCached($collectiveId) ?? 'Accueil', null];
			}
			$ownTitle = array_pop($segments); // dernier dossier = nom de cette page-conteneur
			$chapter = empty($segments) ? null : end($segments);
			return [$ownTitle, $chapter];
		}

		$chapter = empty($segments) ? null : end($segments);
		return [pathinfo($fileName, PATHINFO_FILENAME), $chapter];
	}

	private function getCollectiveSlugCached(?int $collectiveId): ?string {
		if ($collectiveId === null) {
			return null;
		}

		if (self::$collectiveSlugs === null) {
			self::$collectiveSlugs = [];
			$qb = $this->dbConnection->getQueryBuilder();
			$qb->select('id', 'slug')->from('collectives');
			$result = $qb->executeQuery();
			while ($row = $result->fetch()) {
				self::$collectiveSlugs[(int)$row['id']] = (string)$row['slug'];
			}
			$result->closeCursor();
		}

		$slug = self::$collectiveSlugs[$collectiveId] ?? null;
		return $slug !== null && $slug !== '' ? rawurldecode($slug) : null;
	}

	private function getCollectiveIdsForUser(string $userId): array {
		$qb = $this->dbConnection->getQueryBuilder();
		$qb->select('c.id')
			->from('collectives', 'c')
			->innerJoin('c', 'circles_member', 'cm', $qb->expr()->eq('c.circle_unique_id', 'cm.circle_id'))
			->where($qb->expr()->eq('cm.user_id', $qb->createNamedParameter($userId)));
		$result = $qb->executeQuery();
		$ids = [];
		while ($id = $result->fetchOne()) {
			$ids[] = (int)$id;
		}
		$result->closeCursor();

		return $ids;
	}

	private function getCircleIdForCollective(int $collectiveId): ?string {
		$qb = $this->dbConnection->getQueryBuilder();
		$qb->select('circle_unique_id')
			->from('collectives')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($collectiveId, \PDO::PARAM_INT)));
		$result = $qb->executeQuery();
		$circleId = $result->fetchOne();
		$result->closeCursor();

		return $circleId !== false ? $circleId : null;
	}

	private function getAnyMemberUserId(int $collectiveId): ?string {
		$circleId = $this->getCircleIdForCollective($collectiveId);
		if ($circleId === null) {
			return null;
		}

		$qb = $this->dbConnection->getQueryBuilder();
		$qb->select('user_id')
			->from('circles_member')
			->where($qb->expr()->eq('circle_id', $qb->createNamedParameter($circleId)));
		$result = $qb->executeQuery();
		$userId = $result->fetchOne();
		$result->closeCursor();

		return $userId !== false ? $userId : null;
	}

	public function generateDocumentAccessFromFileId(int $fileId, ?int $collectiveId = null): IDocumentAccess {
		/** @psalm-var IDocumentAccess */
		$access = new DocumentAccess();

		$collectiveId = $collectiveId ?? $this->getCollectiveIdForPage($fileId);
		$circleId = $collectiveId !== null ? $this->getCircleIdForCollective($collectiveId) : null;

		if ($circleId !== null) {
			$qb = $this->dbConnection->getQueryBuilder();
			$qb->select('user_id')
				->from('circles_member')
				->where($qb->expr()->eq('circle_id', $qb->createNamedParameter($circleId)));
			$result = $qb->executeQuery();
			while ($userId = $result->fetchOne()) {
				$access->addUser($userId);
			}
			$result->closeCursor();
		}

		return $access;
	}
}
