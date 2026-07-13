<?php

declare(strict_types=1);

namespace OCA\FullTextSearch_Collectives\Provider;

use OC\FullTextSearch\Model\IndexDocument;
use OC\FullTextSearch\Model\SearchTemplate;
use OCA\FullTextSearch_Collectives\Service\FullTextSearchService;
use OCP\FullTextSearch\IFullTextSearchPlatform;
use OCP\FullTextSearch\IFullTextSearchProvider;
use OCP\FullTextSearch\Model\IIndex;
use OCP\FullTextSearch\Model\IIndexDocument;
use OCP\FullTextSearch\Model\IIndexOptions;
use OCP\FullTextSearch\Model\IRunner;
use OCP\FullTextSearch\Model\ISearchRequest;
use OCP\FullTextSearch\Model\ISearchResult;
use OCP\FullTextSearch\Model\ISearchTemplate;
use OCP\IURLGenerator;

class CollectivesProvider implements IFullTextSearchProvider {
	public const PROVIDER_ID = 'collectives';

	private ?IRunner $runner = null;
	private ?IIndexOptions $indexOptions = null;

	public function __construct(
		private FullTextSearchService $fullTextSearchService,
		private IURLGenerator $urlGenerator,
	) {
	}

	public function getId(): string {
		return self::PROVIDER_ID;
	}

	public function getName(): string {
		return 'Collectives';
	}

	public function getConfiguration(): array {
		return [];
	}

	public function setRunner(IRunner $runner) {
		$this->runner = $runner;
	}

	public function setIndexOptions(IIndexOptions $options) {
		$this->indexOptions = $options;
	}

	public function getSearchTemplate(): ISearchTemplate {
		/** @psalm-var ISearchTemplate */
		return new SearchTemplate('icon-collectives', 'icons');
	}

	public function loadProvider() {
	}

	public function generateChunks(string $userId): array {
		return [];
	}

	public function generateIndexableDocuments(string $userId, string $chunk): array {
		$pages = $this->fullTextSearchService->getPagesFromUser($userId);

		$documents = [];
		foreach ($pages as $page) {
			$documents[] = $this->fullTextSearchService->generateIndexDocumentFromPage($page['fileId']);
		}

		return $documents;
	}

	public function fillIndexDocument(IIndexDocument $document) {
		$this->fullTextSearchService->fillIndexDocument($document);
		$this->updateRunnerInfo('info', $document->getTitle());
	}

	public function isDocumentUpToDate(IIndexDocument $document): bool {
		return false;
	}

	public function updateDocument(IIndex $index): IIndexDocument {
		/** @psalm-var IIndexDocument */
		$document = new IndexDocument(self::PROVIDER_ID, $index->getDocumentId());
		$document->setIndex($index);

		$this->fullTextSearchService->fillIndexDocument($document);

		return $document;
	}

	public function onInitializingIndex(IFullTextSearchPlatform $platform) {
	}

	public function onResettingIndex(IFullTextSearchPlatform $platform) {
	}

	public function unloadProvider() {
	}

	public function improveSearchRequest(ISearchRequest $request) {
	}

	public function improveSearchResult(ISearchResult $searchResult) {
		foreach ($searchResult->getDocuments() as $document) {
			$fileId = (int)$document->getId();
			$link = $this->fullTextSearchService->getPageLink($fileId);
			$document->setLink($link ?? $this->urlGenerator->linkToRoute('collectives.start.index'));
		}
	}

	private function updateRunnerInfo(string $info, string $value) {
		if ($this->runner === null) {
			return;
		}

		$this->runner->setInfo($info, $value);
	}
}
