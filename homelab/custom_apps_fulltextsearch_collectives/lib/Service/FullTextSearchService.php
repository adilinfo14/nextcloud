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
	/** @var array<int, array{title: string, collectiveId: ?int}>|null */
	private static ?array $pageIndex = null;

	/** @var array<int, int>|null childFileId => parentFileId */
	private static ?array $parentOf = null;

	/** @var array<int, string>|null collectiveId => slug, cache pour resolvePageTitle() */
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

		// pathinfo($file->getName()) donnerait "Readme" pour toute page-conteneur (une page
		// qui a des sous-pages est stockee par Collectives comme un dossier</Readme.md>) :
		// on reutilise donc la resolution de titre de buildPageIndex(), qui applique la
		// meme convention que OCA\Collectives\Model\PageInfo::fromFile() (titre = nom du
		// DOSSIER pour un Readme.md non-racine).
		$this->buildPageIndex();
		$title = self::$pageIndex[$fileId]['title'] ?? pathinfo($file->getName(), PATHINFO_FILENAME);
		$content = $file->getContent();

		$document->setTitle($title);
		$document->setContent($content);
		$document->setAccess($this->generateDocumentAccessFromFileId($fileId, $collectiveId));

		// Chapitre/sous-chapitre parent (deduit de subpage_order) : expose comme metatag
		// pour permettre a search_hub de le presenter en tant que facette de filtrage.
		$chapterTitle = $this->getParentChapterTitle($fileId);
		if ($chapterTitle !== null) {
			$document->setMetaTags([$chapterTitle]);
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

		return '/apps/collectives/' . rawurlencode($slug) . '?fileId=' . $fileId;
	}

	/**
	 * Construit (une seule fois par requete/job) l'index de toutes les pages Collectives :
	 * titre + collective, et la relation enfant -> parent deduite de subpage_order (seul
	 * champ qui porte la hierarchie, puisqu'il n'y a pas de colonne parent_id en base).
	 */
	private function buildPageIndex(): void {
		if (self::$pageIndex !== null) {
			return;
		}

		self::$pageIndex = [];
		self::$parentOf = [];

		$qb = $this->dbConnection->getQueryBuilder();
		$qb->select('cp.file_id', 'cp.subpage_order', 'fc.name', 'fc.path')
			->from('collectives_pages', 'cp')
			->innerJoin('cp', 'filecache', 'fc', $qb->expr()->eq('cp.file_id', 'fc.fileid'))
			->where($qb->expr()->isNull('cp.trash_timestamp'));

		$result = $qb->executeQuery();
		while ($row = $result->fetch()) {
			$fileId = (int)$row['file_id'];
			$collectiveId = $this->extractCollectiveIdFromPath($row['path']);
			self::$pageIndex[$fileId] = [
				'title' => $this->resolvePageTitle((string)$row['name'], (string)$row['path'], $collectiveId),
				'collectiveId' => $collectiveId,
			];

			$order = json_decode((string)$row['subpage_order'], true);
			if (is_array($order)) {
				foreach ($order as $childFileId) {
					self::$parentOf[(int)$childFileId] = $fileId;
				}
			}
		}
		$result->closeCursor();
	}

	/**
	 * Reproduit la convention de titre de l'app Collectives elle-meme
	 * (OCA\Collectives\Model\PageInfo::fromFile) : une page qui a des sous-pages est
	 * stockee physiquement comme un DOSSIER contenant un fichier "Readme.md" - le vrai
	 * titre affiche par Collectives est alors celui du dossier, jamais "Readme". Sans
	 * cette regle, toute page-chapitre/sous-chapitre apparaitrait dans la recherche (et
	 * dans la facette "chapitre") sous le nom generique et inutilisable "Readme".
	 */
	private function resolvePageTitle(string $fileName, string $path, ?int $collectiveId): string {
		if ($fileName !== 'Readme.md') {
			return pathinfo($fileName, PATHINFO_FILENAME);
		}

		if (preg_match('#/collectives/\d+/Readme\.md$#', $path)) {
			// Page d'accueil de la collective elle-meme : pas de dossier parent
			// pertinent, on retombe sur le slug (meme convention que le lien de la
			// collective ailleurs dans cet apps, cf. getPageLink()/extractCollectiveFromLink()).
			return $this->getCollectiveSlugCached($collectiveId) ?? 'Accueil';
		}

		$segments = explode('/', rtrim($path, '/'));
		array_pop($segments);
		$folderName = end($segments);

		return $folderName !== false && $folderName !== '' ? $folderName : 'Chapitre';
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

	/**
	 * Titre de la page parente directe (chapitre ou sous-chapitre) d'une page, ou null
	 * si la page est elle-meme une page racine (pas de parent dans subpage_order).
	 */
	public function getParentChapterTitle(int $fileId): ?string {
		$this->buildPageIndex();

		$parentId = self::$parentOf[$fileId] ?? null;
		if ($parentId === null) {
			return null;
		}

		return self::$pageIndex[$parentId]['title'] ?? null;
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
