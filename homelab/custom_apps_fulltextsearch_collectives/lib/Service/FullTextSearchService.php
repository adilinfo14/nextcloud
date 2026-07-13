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

		$title = pathinfo($file->getName(), PATHINFO_FILENAME);
		$content = $file->getContent();

		$document->setTitle($title);
		$document->setContent($content);
		$document->setAccess($this->generateDocumentAccessFromFileId($fileId, $collectiveId));
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
