<?php

declare(strict_types=1);

namespace OCA\SearchHub\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\FullTextSearch\IFullTextSearchManager;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

class SearchController extends Controller {
	private const STOPWORDS = [
		'le', 'la', 'les', 'un', 'une', 'des', 'de', 'du', 'et', 'en', 'sur', 'pour',
		'avec', 'dans', 'au', 'aux', 'ce', 'ces', 'cet', 'cette', 'que', 'qui', 'est',
		'sont', 'par', 'se', 'sa', 'son', 'ses', 'ne', 'pas', 'plus', 'ou', 'mais', 'donc',
		'the', 'a', 'an', 'of', 'to', 'in', 'and', 'or', 'is', 'are',
	];

	private const MAX_TERM_LENGTH = 300;
	private const PAGE_SIZE = 60;
	private const RAW_FETCH_SIZE = 500;

	public function __construct(
		string $appName,
		IRequest $request,
		private IFullTextSearchManager $fullTextSearchManager,
		private IUserSession $userSession,
		private IURLGenerator $urlGenerator,
		private IAppConfig $appConfig,
		private IGroupManager $groupManager,
		private LoggerInterface $logger,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(): TemplateResponse {
		return new TemplateResponse('search_hub', 'main');
	}

	/**
	 * Suggestions de titres pour la barre de recherche (autocompletion), en complement
	 * de l'historique local gere cote client (localStorage). Reutilise
	 * IFullTextSearchManager::search() - donc le meme controle d'acces que la recherche
	 * normale - avec un lot volontairement petit, plutot qu'une requete ES dediee.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function suggest(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['suggestions' => []]);
		}

		$term = trim((string)$this->request->getParam('term', ''));
		if (mb_strlen($term) < 2 || !$this->fullTextSearchManager->isAvailable()) {
			return new JSONResponse(['suggestions' => []]);
		}
		if (mb_strlen($term) > self::MAX_TERM_LENGTH) {
			$term = mb_substr($term, 0, self::MAX_TERM_LENGTH);
		}

		try {
			$rawResults = $this->fullTextSearchManager->search([
				'providers' => 'all',
				'search' => $term,
				'size' => 15,
				'page' => 1,
			], $user->getUID());
		} catch (\Throwable $e) {
			return new JSONResponse(['suggestions' => []]);
		}

		$titles = [];
		foreach ($rawResults as $searchResult) {
			foreach ($searchResult->getDocuments() as $document) {
				$title = $document->getTitle();
				if ($document->getProviderId() === 'files' && str_starts_with($title, '.Collectifs/')) {
					continue;
				}
				$shortTitle = $this->shortenTitleForSuggestion($title);
				if ($shortTitle !== '' && !in_array($shortTitle, $titles, true)) {
					$titles[] = $shortTitle;
				}
				if (count($titles) >= 8) {
					break 2;
				}
			}
		}

		return new JSONResponse(['suggestions' => $titles]);
	}

	private function shortenTitleForSuggestion(string $title): string {
		$parts = explode('/', $title);
		$short = end($parts);
		$withoutExt = preg_replace('/\.[a-zA-Z0-9]+$/', '', $short);
		return $withoutExt ?? $short;
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function search(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'forbidden'], 403);
		}

		$term = trim((string)$this->request->getParam('term', ''));
		if ($term === '') {
			return new JSONResponse(['results' => [], 'facets' => $this->emptyFacets(), 'total' => 0, 'page' => 1, 'totalPages' => 1]);
		}
		if (mb_strlen($term) > self::MAX_TERM_LENGTH) {
			$term = mb_substr($term, 0, self::MAX_TERM_LENGTH);
		}

		if (!$this->fullTextSearchManager->isAvailable()) {
			return new JSONResponse(['error' => 'search_unavailable'], 503);
		}

		try {
			$rawResults = $this->fullTextSearchManager->search([
				'providers' => 'all',
				'search' => $term,
				'size' => self::RAW_FETCH_SIZE,
				'page' => 1,
			], $user->getUID());
		} catch (\Throwable $e) {
			$this->logger->error('search_hub: recherche echouee', ['exception' => $e]);
			return new JSONResponse(['error' => 'search_failed'], 500);
		}

		$documents = [];
		foreach ($rawResults as $searchResult) {
			foreach ($searchResult->getDocuments() as $document) {
				if ($document->getProviderId() === 'files' && str_starts_with($document->getTitle(), '.Collectifs/')) {
					// Copie physique en double d'une page Collectives, indexee sans contenu
					// (le vrai contenu, avec extraits, vient du provider "collectives").
					continue;
				}

				$excerpts = $document->getExcerpts();
				$originalLink = $document->getLink();
				$title = $document->getTitle();
				$tags = $document->getTags();
				$excerptTexts = array_slice(array_map(static fn ($e) => $e['excerpt'], $excerpts), 0, 3);

				$documents[] = [
					'id' => $document->getId(),
					'providerId' => $document->getProviderId(),
					'title' => $title,
					'link' => $this->buildOpenLink($document->getProviderId(), $document->getId(), $originalLink),
					'modifiedTime' => $document->getModifiedTime(),
					'tags' => $tags,
					'excerpts' => $excerptTexts,
					'esScore' => (float)$document->getScore(),
					'matchedTerms' => $this->computeMatchedTerms($term, $title, $excerptTexts, $tags),
					'titleMatch' => !empty($this->computeMatchedTerms($term, $title, [], [])),
					'collective' => $this->extractCollectiveFromLink($document->getProviderId(), $originalLink),
					'fileType' => $this->extractFileType($document->getProviderId(), $title),
					// Rempli juste apres, via fetchChapterMap() : IIndexDocument::getMetaTags()
					// revient toujours vide ici (fulltextsearch_elasticsearch::parseSearchEntry()
					// n'inclut ni tags ni metatags dans le chemin de recherche multi-resultats,
					// contrairement au chemin "un seul document" getDocument()).
					'chapter' => null,
				];
			}
		}

		// Contournement de la limite ci-dessus : on va chercher les metatags (chapitre
		// parent) directement dans Elasticsearch via un _mget groupe (une seule requete
		// HTTP pour tous les documents "collectives" du lot), plutot qu'un GET par document.
		$chapterMap = $this->fetchChapterMap($documents);
		foreach ($documents as &$doc) {
			if ($doc['providerId'] === 'collectives') {
				$doc['chapter'] = $chapterMap[$doc['providerId'] . ':' . $doc['id']] ?? null;
			}
		}
		unset($doc);

		$provider = (string)$this->request->getParam('provider', '');
		$tabDocuments = $provider === ''
			? $documents
			: array_values(array_filter($documents, static fn ($doc) => $doc['providerId'] === $provider));

		$facets = $this->computeFacets($tabDocuments);
		$facets['providers'] = $this->computeProviderCounts($documents);

		$weights = $this->readWeightParams();
		$tabDocuments = $this->computeWeightedScores($tabDocuments, $term, $weights);

		$filtered = $this->applyFilters($tabDocuments);
		$filtered = $this->applySort($filtered, (string)$this->request->getParam('sort', 'relevance'));

		$total = count($filtered);
		$totalPages = max(1, (int)ceil($total / self::PAGE_SIZE));
		$requestedPage = max(1, (int)$this->request->getParam('page', 1));
		$requestedPage = min($requestedPage, $totalPages);
		$pageResults = array_slice($filtered, ($requestedPage - 1) * self::PAGE_SIZE, self::PAGE_SIZE);

		// Le contenu complet (pour la previsualisation avec surbrillance) n'est recupere
		// que pour les resultats de la page reellement affichee, pas pour tous les
		// candidats bruts remontes par Elasticsearch.
		foreach ($pageResults as &$doc) {
			$fullContent = $this->fetchFullContent($doc['providerId'], $doc['id']);
			if ($fullContent !== '' && empty($doc['matchedTerms'])) {
				$doc['matchedTerms'] = $this->computeMatchedTerms($term, $doc['title'], $doc['excerpts'], $doc['tags'], $fullContent);
			}
			$doc['fullContent'] = $fullContent;
		}
		unset($doc);

		return new JSONResponse([
			'results' => $pageResults,
			'facets' => $facets,
			'total' => $total,
			'totalUnfiltered' => count($tabDocuments),
			'page' => $requestedPage,
			'totalPages' => $totalPages,
			'weightsUsed' => $weights,
			'isAdmin' => $this->groupManager->isAdmin($user->getUID()),
		]);
	}

	/**
	 * "Recherche par sens" : recherche par similarite vectorielle (kNN Elasticsearch,
	 * embeddings nomic-embed-text via Ollama), en complement de la recherche par mot-cle
	 * de search(). Necessite un requete directe a Elasticsearch (l'API publique
	 * IFullTextSearchManager ne supporte pas kNN) : le controle d'acces normalement
	 * assure par cette API est donc reproduit manuellement ici (buildAccessFilterShould())
	 * en suivant exactement la meme logique que fulltextsearch::SearchService (owner,
	 * users, groupes, cercles) - ne jamais simplifier ce filtre.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function searchNeural(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'forbidden'], 403);
		}

		$term = trim((string)$this->request->getParam('term', ''));
		if ($term === '') {
			return new JSONResponse(['results' => [], 'facets' => $this->emptyFacets(), 'total' => 0, 'page' => 1, 'totalPages' => 1]);
		}
		if (mb_strlen($term) > self::MAX_TERM_LENGTH) {
			$term = mb_substr($term, 0, self::MAX_TERM_LENGTH);
		}

		$elasticHost = $this->appConfig->getValueString('fulltextsearch_elasticsearch', 'elastic_host', '', true);
		$elasticIndex = $this->appConfig->getValueString('fulltextsearch_elasticsearch', 'elastic_index', '', true);
		if ($elasticHost === '' || $elasticIndex === '') {
			return new JSONResponse(['error' => 'search_unavailable'], 503);
		}

		$vector = $this->embedText('search_query: ' . $term);
		if ($vector === null) {
			$this->logger->error('search_hub: echec embedding de la requete (recherche neuronale)');
			return new JSONResponse(['error' => 'search_failed'], 500);
		}

		$query = [
			'size' => self::RAW_FETCH_SIZE,
			'_source' => ['title', 'tags', 'lastModified'],
			'knn' => [
				'field' => 'embedding_vector',
				'query_vector' => $vector,
				'k' => self::RAW_FETCH_SIZE,
				'num_candidates' => self::RAW_FETCH_SIZE * 2,
				'filter' => [
					'bool' => ['should' => $this->buildAccessFilterShould($user)],
				],
			],
		];

		$ch = curl_init(rtrim($elasticHost, '/') . '/' . $elasticIndex . '/_search');
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_TIMEOUT, 15);
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($query));
		curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
		$body = curl_exec($ch);
		$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);

		if ($body === false || $httpCode >= 400) {
			$this->logger->error('search_hub: recherche neuronale echouee', ['httpCode' => $httpCode]);
			return new JSONResponse(['error' => 'search_failed'], 500);
		}

		$decoded = json_decode($body, true);
		$hits = $decoded['hits']['hits'] ?? [];

		$documents = [];
		foreach ($hits as $hit) {
			[$providerId, $documentId] = array_pad(explode(':', (string)$hit['_id'], 2), 2, '');
			if ($providerId === 'files' && str_starts_with((string)($hit['_source']['title'] ?? ''), '.Collectifs/')) {
				continue;
			}

			$title = (string)($hit['_source']['title'] ?? '');
			$tags = $hit['_source']['tags'] ?? [];
			$link = $this->resolveNeuralLink($providerId, $documentId);

			$documents[] = [
				'id' => $documentId,
				'providerId' => $providerId,
				'title' => $title,
				'link' => $link,
				'modifiedTime' => (int)($hit['_source']['lastModified'] ?? 0),
				'tags' => is_array($tags) ? $tags : [],
				'excerpts' => [],
				'esScore' => (float)($hit['_score'] ?? 0),
				'matchedTerms' => $this->computeMatchedTerms($term, $title, [], is_array($tags) ? $tags : []),
				'titleMatch' => !empty($this->computeMatchedTerms($term, $title, [], [])),
				'collective' => $this->extractCollectiveFromLink($providerId, $link),
				'fileType' => $this->extractFileType($providerId, $title),
				'chapter' => null,
			];
		}

		$chapterMap = $this->fetchChapterMap($documents);
		foreach ($documents as &$doc) {
			if ($doc['providerId'] === 'collectives') {
				$doc['chapter'] = $chapterMap[$doc['providerId'] . ':' . $doc['id']] ?? null;
			}
		}
		unset($doc);

		$provider = (string)$this->request->getParam('provider', '');
		$tabDocuments = $provider === ''
			? $documents
			: array_values(array_filter($documents, static fn ($doc) => $doc['providerId'] === $provider));

		$facets = $this->computeFacets($tabDocuments);
		$facets['providers'] = $this->computeProviderCounts($documents);

		$filtered = $this->applyFilters($tabDocuments);
		// Le classement par similarite vectorielle EST le tri par defaut ici ; les autres
		// options (date/titre) restent proposees mais "pertinence"/"pondere" n'ont pas de
		// sens sans score BM25 ni couverture lexicale fiable - geres normalement par
		// applySort() qui retombe sur l'ordre kNN d'origine par defaut.
		$filtered = $this->applySort($filtered, (string)$this->request->getParam('sort', 'relevance'));

		$total = count($filtered);
		$totalPages = max(1, (int)ceil($total / self::PAGE_SIZE));
		$requestedPage = max(1, (int)$this->request->getParam('page', 1));
		$requestedPage = min($requestedPage, $totalPages);
		$pageResults = array_slice($filtered, ($requestedPage - 1) * self::PAGE_SIZE, self::PAGE_SIZE);

		// La recherche par sens n'a pas de "mot exact" a mettre en avant (c'est le principe :
		// elle matche sur le SENS, pas le mot). Au minimum, on affiche un extrait du contenu
		// reel deja recupere pour la previsualisation, centre sur un eventuel chevauchement
		// lexical (matchedTerms) quand il y en a un, sinon le debut du document.
		foreach ($pageResults as &$doc) {
			$fullContent = $this->fetchFullContent($doc['providerId'], $doc['id']);
			if ($fullContent !== '' && empty($doc['matchedTerms'])) {
				$doc['matchedTerms'] = $this->computeMatchedTerms($term, $doc['title'], [], $doc['tags'], $fullContent);
			}
			$doc['fullContent'] = $fullContent;
			if ($fullContent !== '') {
				$doc['excerpts'] = [$this->buildContentSnippet($fullContent, $doc['matchedTerms'])];
			}
		}
		unset($doc);

		return new JSONResponse([
			'results' => $pageResults,
			'facets' => $facets,
			'total' => $total,
			'totalUnfiltered' => count($tabDocuments),
			'page' => $requestedPage,
			'totalPages' => $totalPages,
			'isAdmin' => $this->groupManager->isAdmin($user->getUID()),
			'neural' => true,
		]);
	}

	/**
	 * Extrait de contenu pour un resultat de recherche neuronale : centre sur le premier
	 * terme de matchedTerms trouve dans le texte (chevauchement lexical incident, meme
	 * quand le vrai "match" est semantique), sinon les premiers caracteres du document.
	 */
	private function buildContentSnippet(string $fullContent, array $matchedTerms): string {
		$maxLen = 220;
		$lower = mb_strtolower($fullContent);

		$bestPos = null;
		foreach ($matchedTerms as $term) {
			$pos = mb_strpos($lower, mb_strtolower($term));
			if ($pos !== false && ($bestPos === null || $pos < $bestPos)) {
				$bestPos = $pos;
			}
		}

		if ($bestPos !== null) {
			$start = max(0, $bestPos - 80);
			$snippet = trim(mb_substr($fullContent, $start, $maxLen));
			return ($start > 0 ? '… ' : '') . $snippet . '…';
		}

		$snippet = trim(mb_substr($fullContent, 0, $maxLen));
		return $snippet . (mb_strlen($fullContent) > $maxLen ? '…' : '');
	}

	/**
	 * Reproduit fidelement OCA\FullTextSearch\Service\SearchService::getDocumentAccessFromUser()
	 * (app "fulltextsearch" coeur) : c'est normalement CETTE logique qui filtre les
	 * resultats par droits d'acces avant de les renvoyer. On la duplique ici car le kNN
	 * direct sur Elasticsearch contourne cette API. Ne pas simplifier (ex: retirer les
	 * cercles) sans revalider que ca ne fuite pas de document prive.
	 */
	private function buildAccessFilterShould(\OCP\IUser $user): array {
		$viewerId = $user->getUID();
		$should = [
			['term' => ['owner.keyword' => $viewerId]],
			['term' => ['users.keyword' => $viewerId]],
			['term' => ['users.keyword' => '__all']],
		];

		foreach ($this->groupManager->getUserGroupIds($user) as $group) {
			$should[] = ['term' => ['groups.keyword' => $group]];
		}

		try {
			$circleManager = \OC::$server->get(\OCA\Circles\CirclesManager::class);
			$circleManager->startSession();
			foreach ($circleManager->getCircles() as $circle) {
				$should[] = ['term' => ['circles.keyword' => $circle->getSingleId()]];
			}
		} catch (\Throwable $e) {
			// App Circles indisponible : owner/users/groupes suffisent deja pour Files/Deck ;
			// seul un partage explicite par cercle (hors appartenance Collectives, deja
			// couverte via "users") serait manque ici.
		}

		return $should;
	}

	/**
	 * Lien navigable pour un resultat de recherche neuronale : la recherche kNN etant une
	 * requete Elasticsearch brute (pas IFullTextSearchManager::search()), le hook
	 * improveSearchResult() de chaque provider n'est jamais appele - on reproduit
	 * l'equivalent au cas par cas en reutilisant les services deja existants de chaque app.
	 */
	private function resolveNeuralLink(string $providerId, string $documentId): string {
		if ($providerId === 'files' && ctype_digit($documentId)) {
			try {
				return $this->urlGenerator->linkToRoute('files.View.showFile', ['fileid' => (int)$documentId]);
			} catch (\Throwable $e) {
				return '';
			}
		}

		if ($providerId === 'collectives') {
			try {
				$service = \OC::$server->get(\OCA\FullTextSearch_Collectives\Service\FullTextSearchService::class);
				return $service->getPageLink((int)$documentId) ?? '';
			} catch (\Throwable $e) {
				return '';
			}
		}

		if ($providerId === 'deck') {
			try {
				$service = \OC::$server->get(\OCA\Deck\Service\FullTextSearchService::class);
				$board = $service->getBoardFromCardId((int)$documentId);
				return $this->urlGenerator->linkToRoute('deck.page.index') . '/board/' . $board->getId() . '/card/' . $documentId;
			} catch (\Throwable $e) {
				return '';
			}
		}

		return '';
	}

	private function embedText(string $text): ?array {
		$ollamaHost = $this->appConfig->getValueString('search_hub', 'ollama_host', 'http://ollama:11434');
		$model = $this->appConfig->getValueString('search_hub', 'embedding_model', 'nomic-embed-text');

		$ch = curl_init(rtrim($ollamaHost, '/') . '/api/embeddings');
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_TIMEOUT, 15);
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['model' => $model, 'prompt' => $text]));
		curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
		$body = curl_exec($ch);
		$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);

		if ($body === false || $httpCode >= 400) {
			return null;
		}

		$decoded = json_decode($body, true);
		$vector = $decoded['embedding'] ?? null;
		return is_array($vector) ? $vector : null;
	}

	/**
	 * "Pourquoi ce resultat ?" - explication en langage naturel d'un match de la
	 * recherche par sens (kNN), la ou le surlignage lexical (matchedTerms) ne suffit
	 * pas puisque le principe meme de la recherche semantique est de matcher sans mot
	 * commun. Calcule A LA DEMANDE (un clic par resultat), jamais pour tout un lot de
	 * resultats d'un coup - un appel LLM prend plusieurs secondes sur ce serveur sans
	 * GPU, generer ca pour 60 resultats a chaque recherche serait inutilisable.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[UserRateLimit(limit: 20, period: 60)]
	public function explainMatch(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'forbidden'], 403);
		}

		$term = trim((string)$this->request->getParam('term', ''));
		$title = trim((string)$this->request->getParam('title', ''));
		$snippet = trim((string)$this->request->getParam('snippet', ''));
		if ($term === '' || $title === '') {
			return new JSONResponse(['error' => 'invalid_params'], 400);
		}
		if (mb_strlen($term) > self::MAX_TERM_LENGTH) {
			$term = mb_substr($term, 0, self::MAX_TERM_LENGTH);
		}
		$snippet = mb_substr($snippet, 0, 800);

		$explanation = $this->generateExplanation($term, $title, $snippet);
		if ($explanation === null) {
			return new JSONResponse(['error' => 'generation_failed'], 500);
		}

		return new JSONResponse(['explanation' => $explanation]);
	}

	private function generateExplanation(string $term, string $title, string $snippet): ?string {
		$ollamaHost = $this->appConfig->getValueString('search_hub', 'ollama_host', 'http://ollama:11434');
		$model = $this->appConfig->getValueString('search_hub', 'explain_model', 'llama3:8b');

		$prompt = "Une recherche par sens (semantique) a trouve ce document pertinent pour la question posee, "
			. "meme s'il ne contient pas forcement les memes mots. En UNE SEULE phrase courte et concrete "
			. "(pas plus de 30 mots), en francais, explique le lien entre la question et ce document. "
			. "Ne repete pas la question mot pour mot, va droit au but.\n\n"
			. "Question de recherche : " . $term . "\n"
			. "Titre du document : " . $title . "\n"
			. "Extrait du document : " . $snippet;

		$ch = curl_init(rtrim($ollamaHost, '/') . '/api/generate');
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_TIMEOUT, 30);
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
			'model' => $model,
			'prompt' => $prompt,
			'stream' => false,
		]));
		curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
		$body = curl_exec($ch);
		$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);

		if ($body === false || $httpCode >= 400) {
			$this->logger->error('search_hub: echec generation explication de match', ['httpCode' => $httpCode]);
			return null;
		}

		$decoded = json_decode($body, true);
		$response = trim((string)($decoded['response'] ?? ''));
		return $response !== '' ? $response : null;
	}

	private function computeMatchedTerms(string $term, string $title, array $excerpts, array $tags, string $fullContent = ''): array {
		$words = preg_split('/\s+/u', mb_strtolower(trim($term)));
		$words = array_values(array_unique(array_filter($words, static function ($w) {
			return mb_strlen($w) >= 2 && !in_array($w, self::STOPWORDS, true);
		})));

		if (empty($words)) {
			return [];
		}

		$haystack = mb_strtolower($title . ' ' . implode(' ', $excerpts) . ' ' . implode(' ', $tags) . ' ' . $fullContent);

		return array_values(array_filter($words, static function ($w) use ($haystack) {
			return mb_strpos($haystack, $w) !== false;
		}));
	}

	private function applySort(array $documents, string $sort): array {
		if ($sort === 'date') {
			usort($documents, static fn ($a, $b) => $b['modifiedTime'] <=> $a['modifiedTime']);
		} elseif ($sort === 'title') {
			usort($documents, static fn ($a, $b) => strcasecmp($a['title'], $b['title']));
		} elseif ($sort === 'title_boost') {
			// Tri stable (PHP 8+) : a l'interieur de chaque groupe (titre-matche / pas),
			// l'ordre de pertinence Elasticsearch d'origine est preserve.
			usort($documents, static fn ($a, $b) => (int)$b['titleMatch'] <=> (int)$a['titleMatch']);
		} elseif ($sort === 'weighted') {
			usort($documents, static fn ($a, $b) => $b['weightedScore'] <=> $a['weightedScore']);
		}
		// 'relevance' (par defaut) : on garde l'ordre renvoye par Elasticsearch (score de pertinence).
		return $documents;
	}

	/**
	 * Formule de scoring composite classique en recherche d'entreprise (le meme principe
	 * que des moteurs comme Sinequa) : on combine plusieurs signaux normalises (0 a 1)
	 * en une seule note ponderee par document, plutot que de se fier uniquement au score
	 * brut d'Elasticsearch.
	 *
	 * Poids retenus (modifiable ici si besoin) :
	 *  - 50% pertinence texte (score BM25 d'Elasticsearch, normalise sur le lot de resultats)
	 *  - 20% presence du/des terme(s) dans le TITRE (signal fort de pertinence "documentaire")
	 *  - 20% couverture de la requete (proportion des mots cherches reellement retrouves)
	 *  - 10% fraicheur (decroissance exponentielle sur la date de modification, demi-vie 1 an)
	 */
	private const DEFAULT_WEIGHTS = ['relevance' => 0.5, 'title' => 0.2, 'coverage' => 0.2, 'recency' => 0.1];

	/**
	 * Lit les 4 poids depuis la requete, mais UNIQUEMENT si l'utilisateur courant est
	 * administrateur : un utilisateur normal ne doit pas pouvoir personnaliser le
	 * classement (demande explicite), donc on ignore silencieusement des parametres
	 * wXxx envoyes par un compte non-admin (le frontend ne les affiche deja pas, mais
	 * la verification doit se faire cote serveur, pas seulement cote client).
	 */
	private function readWeightParams(): array {
		$user = $this->userSession->getUser();
		if ($user === null || !$this->groupManager->isAdmin($user->getUID())) {
			return self::DEFAULT_WEIGHTS;
		}

		$raw = [
			'relevance' => (float)$this->request->getParam('wRelevance', 0.5),
			'title' => (float)$this->request->getParam('wTitle', 0.2),
			'coverage' => (float)$this->request->getParam('wCoverage', 0.2),
			'recency' => (float)$this->request->getParam('wRecency', 0.1),
		];

		// Defense contre des valeurs non-finies (NAN/INF) qu'un cast (float) sur une
		// chaine forgee ("NAN", "INF") peut produire et qui casseraient le tri ensuite.
		foreach ($raw as $key => $value) {
			if (!is_finite($value) || $value < 0) {
				return self::DEFAULT_WEIGHTS;
			}
		}

		$sum = array_sum($raw);
		if ($sum <= 0) {
			return self::DEFAULT_WEIGHTS;
		}

		return array_map(static fn ($w) => $w / $sum, $raw);
	}

	private function computeWeightedScores(array $documents, string $term, array $weights): array {
		$weightRelevance = $weights['relevance'];
		$weightTitle = $weights['title'];
		$weightCoverage = $weights['coverage'];
		$weightRecency = $weights['recency'];
		$recencyHalfLifeDays = 365;

		$queryWordCount = count(array_filter(
			preg_split('/\s+/u', mb_strtolower(trim($term))),
			static fn ($w) => mb_strlen($w) >= 2 && !in_array($w, self::STOPWORDS, true)
		));
		$queryWordCount = max(1, $queryWordCount);

		$maxScore = 0.0;
		foreach ($documents as $doc) {
			$maxScore = max($maxScore, $doc['esScore']);
		}
		$maxScore = max($maxScore, 0.0001);

		$now = time();

		foreach ($documents as &$doc) {
			$relevance = $doc['esScore'] / $maxScore;
			$titleBonus = $doc['titleMatch'] ? 1.0 : 0.0;
			$coverage = count($doc['matchedTerms']) / $queryWordCount;

			$recency = 0.0;
			if ($doc['modifiedTime'] > 0) {
				$ageDays = max(0, ($now - $doc['modifiedTime']) / 86400);
				$recency = exp(-$ageDays / $recencyHalfLifeDays);
			}

			$doc['weightedScore'] =
				$weightRelevance * $relevance
				+ $weightTitle * $titleBonus
				+ $weightCoverage * $coverage
				+ $weightRecency * $recency;
		}
		unset($doc);

		return $documents;
	}

	private function computeProviderCounts(array $documents): array {
		$providers = [];
		foreach ($documents as $doc) {
			$providers[$doc['providerId']] = ($providers[$doc['providerId']] ?? 0) + 1;
		}
		return $providers;
	}

	/**
	 * Va chercher le texte deja extrait (Tika/OCR au moment de l'indexation) directement
	 * dans Elasticsearch, plutot que relire le fichier source : pour un PDF/Office/image,
	 * les octets bruts du fichier ne sont pas du texte exploitable, alors que le texte
	 * extrait stocke dans l'index, lui, l'est deja.
	 */
	private function fetchFullContent(string $providerId, string $documentId): string {
		if ($providerId === 'deck') {
			return '';
		}

		$elasticHost = $this->appConfig->getValueString('fulltextsearch_elasticsearch', 'elastic_host', '', true);
		$elasticIndex = $this->appConfig->getValueString('fulltextsearch_elasticsearch', 'elastic_index', '', true);
		if ($elasticHost === '' || $elasticIndex === '') {
			return '';
		}

		$docId = rawurlencode($providerId . ':' . $documentId);
		$url = rtrim($elasticHost, '/') . '/' . $elasticIndex . '/_doc/' . $docId;

		$ch = curl_init($url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_TIMEOUT, 5);
		$body = curl_exec($ch);
		$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);

		if ($body === false || $httpCode >= 400) {
			return '';
		}

		$decoded = json_decode($body, true);
		$source = $decoded['_source'] ?? null;
		if (!is_array($source)) {
			return '';
		}

		$content = (string)($source['content'] ?? '');
		$parts = $source['parts'] ?? [];
		$partsText = is_array($parts) ? implode(' ', array_filter($parts, 'is_string')) : '';

		$full = trim($content . ' ' . $partsText);
		return mb_substr($full, 0, 20000);
	}

	/**
	 * Chapitre/sous-chapitre parent de chaque document "collectives" du lot, en UNE
	 * seule requete Elasticsearch (_mget) plutot qu'un GET par document. Necessaire car
	 * IIndexDocument::getMetaTags() revient toujours vide sur le chemin de recherche
	 * (voir commentaire dans search()) alors que le champ existe bien dans l'index.
	 *
	 * @return array<string, string> cle "providerId:documentId" => titre du chapitre
	 */
	private function fetchChapterMap(array $documents): array {
		$elasticHost = $this->appConfig->getValueString('fulltextsearch_elasticsearch', 'elastic_host', '', true);
		$elasticIndex = $this->appConfig->getValueString('fulltextsearch_elasticsearch', 'elastic_index', '', true);
		if ($elasticHost === '' || $elasticIndex === '') {
			return [];
		}

		$docs = [];
		foreach ($documents as $doc) {
			if ($doc['providerId'] === 'collectives') {
				$docs[] = ['_id' => 'collectives:' . $doc['id'], '_source' => ['metatags']];
			}
		}
		if (empty($docs)) {
			return [];
		}

		$url = rtrim($elasticHost, '/') . '/' . $elasticIndex . '/_mget';

		$ch = curl_init($url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_TIMEOUT, 5);
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['docs' => $docs]));
		curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
		$body = curl_exec($ch);
		$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);

		if ($body === false || $httpCode >= 400) {
			return [];
		}

		$decoded = json_decode($body, true);
		$map = [];
		foreach (($decoded['docs'] ?? []) as $hit) {
			if (empty($hit['found']) || !isset($hit['_id'])) {
				continue;
			}
			$metatags = $hit['_source']['metatags'] ?? [];
			if (is_array($metatags) && !empty($metatags[0])) {
				$map[$hit['_id']] = (string)$metatags[0];
			}
		}

		return $map;
	}

	private function buildOpenLink(string $providerId, string $documentId, string $fallbackLink): string {
		if ($providerId === 'files' && ctype_digit($documentId)) {
			try {
				return $this->urlGenerator->linkToRoute('files.View.showFile', ['fileid' => (int)$documentId]);
			} catch (\Throwable $e) {
				return $fallbackLink;
			}
		}

		return $fallbackLink;
	}

	private function extractCollectiveFromLink(string $providerId, string $link): ?string {
		if ($providerId !== 'collectives') {
			return null;
		}
		if (preg_match('#/apps/collectives/([^?/]+)#', $link, $m)) {
			return rawurldecode($m[1]);
		}
		return null;
	}

	private function extractFileType(string $providerId, string $title): string {
		if ($providerId === 'deck') {
			return 'carte';
		}
		if ($providerId === 'collectives') {
			return 'page-wiki';
		}
		$ext = strtolower(pathinfo($title, PATHINFO_EXTENSION));
		return match (true) {
			$ext === 'pdf' => 'pdf',
			in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'], true) => 'image',
			in_array($ext, ['doc', 'docx', 'odt'], true) => 'document',
			in_array($ext, ['xls', 'xlsx', 'ods'], true) => 'tableur',
			in_array($ext, ['ppt', 'pptx', 'odp'], true) => 'presentation',
			$ext === 'md' => 'texte',
			default => 'autre',
		};
	}

	private function computeFacets(array $documents): array {
		$providers = [];
		$tags = [];
		$collectives = [];
		$fileTypes = [];
		// Chapitres regroupes PAR collective (et non en liste plate) : sinon les chapitres
		// de plusieurs collectives se melangent sans distinction possible pour l'utilisateur.
		$chaptersByCollective = [];
		$periods = ['24h' => 0, '7j' => 0, '30j' => 0];
		$now = time();
		$periodLimits = ['24h' => 86400, '7j' => 604800, '30j' => 2592000];

		foreach ($documents as $doc) {
			$providers[$doc['providerId']] = ($providers[$doc['providerId']] ?? 0) + 1;

			foreach ($doc['tags'] as $tag) {
				$tags[$tag] = ($tags[$tag] ?? 0) + 1;
			}

			if ($doc['collective'] !== null) {
				$collectives[$doc['collective']] = ($collectives[$doc['collective']] ?? 0) + 1;
			}

			$fileTypes[$doc['fileType']] = ($fileTypes[$doc['fileType']] ?? 0) + 1;

			if (!empty($doc['chapter'])) {
				$collectiveKey = $doc['collective'] ?? '';
				$chaptersByCollective[$collectiveKey][$doc['chapter']] =
					($chaptersByCollective[$collectiveKey][$doc['chapter']] ?? 0) + 1;
			}

			if ($doc['modifiedTime'] > 0) {
				foreach ($periodLimits as $period => $limit) {
					if (($now - $doc['modifiedTime']) <= $limit) {
						$periods[$period]++;
					}
				}
			}
		}

		arsort($tags);
		arsort($collectives);
		arsort($fileTypes);
		foreach ($chaptersByCollective as &$chaptersForCollective) {
			arsort($chaptersForCollective);
		}
		unset($chaptersForCollective);

		return [
			'providers' => $providers,
			'tags' => $tags,
			'collectives' => $collectives,
			'fileTypes' => $fileTypes,
			'chaptersByCollective' => $chaptersByCollective,
			'periods' => $periods,
		];
	}

	private function emptyFacets(): array {
		return ['providers' => [], 'tags' => [], 'collectives' => [], 'fileTypes' => [], 'chaptersByCollective' => [], 'periods' => ['24h' => 0, '7j' => 0, '30j' => 0]];
	}

	private function applyFilters(array $documents): array {
		$provider = (string)$this->request->getParam('provider', '');
		$tag = (string)$this->request->getParam('tag', '');
		$collective = (string)$this->request->getParam('collective', '');
		$fileType = (string)$this->request->getParam('fileType', '');
		$chapter = (string)$this->request->getParam('chapter', '');
		$period = (string)$this->request->getParam('period', '');

		return array_values(array_filter($documents, function ($doc) use ($provider, $tag, $collective, $fileType, $chapter, $period) {
			if ($provider !== '' && $doc['providerId'] !== $provider) {
				return false;
			}
			if ($tag !== '' && !in_array($tag, $doc['tags'], true)) {
				return false;
			}
			if ($collective !== '' && $doc['collective'] !== $collective) {
				return false;
			}
			if ($fileType !== '' && $doc['fileType'] !== $fileType) {
				return false;
			}
			if ($chapter !== '' && $doc['chapter'] !== $chapter) {
				return false;
			}
			if ($period !== '' && $doc['modifiedTime'] > 0) {
				$now = time();
				$limits = ['24h' => 86400, '7j' => 604800, '30j' => 2592000];
				if (isset($limits[$period]) && ($now - $doc['modifiedTime']) > $limits[$period]) {
					return false;
				}
			}
			return true;
		}));
	}
}
