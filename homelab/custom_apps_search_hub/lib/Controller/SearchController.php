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

	private const CONFIG_PATH = '/var/www/html/custom_apps/search_hub/search_hub_config.json';
	private const CONFIG_DEFAULTS = [
		'embedding' => [
			'model' => 'mxbai-embed-large',
		],
		'reranking' => [
			'model' => 'llama3:8b',
			'topN' => 15,
		],
		'synonyms' => [],
	];

	/**
	 * Configuration centralisee (2026-07-14), editable depuis la console admin
	 * (Parametrage) : meme fichier lu par embed_backfill.php, pour eviter qu'un
	 * changement de modele fait via l'admin ne s'applique qu'a l'un des deux (le
	 * backfill ET la recherche doivent utiliser le meme modele d'embedding).
	 * Cache statique : le fichier n'est lu qu'une fois par requete PHP, pas a
	 * chaque appel de config().
	 */
	private static ?array $configCache = null;

	private function loadConfig(): array {
		if (self::$configCache !== null) {
			return self::$configCache;
		}

		$userConfig = file_exists(self::CONFIG_PATH) ? json_decode((string)file_get_contents(self::CONFIG_PATH), true) : null;
		self::$configCache = is_array($userConfig) ? array_replace_recursive(self::CONFIG_DEFAULTS, $userConfig) : self::CONFIG_DEFAULTS;

		return self::$configCache;
	}

	/**
	 * Dictionnaire de synonymes/thesaurus (2026-07-14) : si le terme cherche correspond
	 * (MOT ENTIER, pas sous-chaine) a un des termes d'un groupe configure, les AUTRES
	 * termes de ce groupe sont ajoutes au contexte envoye au moteur (BM25 ou embedding) -
	 * sans jamais toucher le $term original utilise pour l'affichage/surbrillance. Ex: un
	 * groupe ["IA", "intelligence artificielle", "AI"] fait que chercher "IA" elargit
	 * aussi la requete avec "intelligence artificielle" et "AI".
	 *
	 * Piege trouve en testant (2026-07-14) : une comparaison par SOUS-CHAINE simple
	 * (mb_strpos) faisait matcher "IA" a l'interieur de "connaissances" (conn-AI-ssances)
	 * - "gestion des connaissances" se voyait alors, a tort, enrichi avec tout le groupe
	 * de synonymes IA. D'ou l'usage de \b (frontiere de mot) via preg_match plutot qu'une
	 * simple recherche de sous-chaine.
	 */
	private function expandWithSynonyms(string $term): string {
		$groups = $this->loadConfig()['synonyms'] ?? [];
		if (empty($groups)) {
			return $term;
		}

		$matchesWholeWord = static function (string $needle, string $haystack): bool {
			return $needle !== '' && preg_match('/\b' . preg_quote($needle, '/') . '\b/ui', $haystack) === 1;
		};

		$extra = [];
		foreach ($groups as $group) {
			$groupTerms = $group['terms'] ?? [];
			$matches = false;
			foreach ($groupTerms as $t) {
				if ($matchesWholeWord($t, $term)) {
					$matches = true;
					break;
				}
			}
			if (!$matches) {
				continue;
			}
			foreach ($groupTerms as $other) {
				if ($other !== '' && !$matchesWholeWord($other, $term)) {
					$extra[] = $other;
				}
			}
		}

		if (empty($extra)) {
			return $term;
		}

		return $term . ' ' . implode(' ', array_unique($extra));
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

		// Le terme ORIGINAL ($term) reste utilise pour l'affichage/surbrillance -
		// l'expansion par synonymes n'elargit QUE la requete envoyee au moteur, pour ne
		// jamais surligner a l'utilisateur un mot qu'il n'a pas lui-meme tape.
		$expandedTerm = $this->expandWithSynonyms($term);

		try {
			$rawResults = $this->fullTextSearchManager->search([
				'providers' => 'all',
				'search' => $expandedTerm,
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

		// Connecteur iaeasy.noschoixpourvous.com (2026-07-14) : application separee, pas
		// une app Nextcloud - IFullTextSearchManager ne le connait pas (aucun
		// IFullTextSearchProvider enregistre), donc ses documents (indexes directement
		// dans le MEME index ES par iaeasy_index.php, provider="iaeasy") ne remontent
		// jamais via l'appel ci-dessus. On va les chercher separement par une requete ES
		// directe et on les fusionne dans le meme tableau $documents - tout le reste du
		// pipeline (facettes, ponderation, tri, filtres) les traite alors comme n'importe
		// quel autre resultat.
		foreach ($this->fetchIaeasyKeywordMatches($expandedTerm, $term) as $iaeasyDoc) {
			$documents[] = $iaeasyDoc;
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
	 * embeddings mxbai-embed-large via Ollama - etat de l'art MTEB pour sa categorie,
	 * champ "embedding_vector_v2" 1024 dim, cf. embed_backfill.php), en complement de la
	 * recherche par mot-cle
	 * de search(). Necessite un requete directe a Elasticsearch (l'API publique
	 * IFullTextSearchManager ne supporte pas kNN) : le controle d'acces normalement
	 * assure par cette API est donc reproduit manuellement ici (buildAccessFilterShould())
	 * en suivant exactement la meme logique que fulltextsearch::SearchService (owner,
	 * users, groupes, cercles) - ne jamais simplifier ce filtre.
	 *
	 * Architecture "finder" + "ranker" (v2, 2026-07-14, cf. page wiki "23 Architecture
	 * de Recherche+") :
	 *  - FINDER : le kNN cible desormais des PASSAGES (~900 caracteres, generes par
	 *    embed_backfill.php), pas des documents entiers - un document long dont le
	 *    passage pertinent est au milieu est maintenant trouvable, alors qu'un seul
	 *    vecteur sur les 800 premiers caracteres du document le ratait. Les hits sont
	 *    regroupes par document parent (meilleur score de passage retenu).
	 *  - RANKER : les ~15 meilleurs documents (post-finder) sont reclasses par un
	 *    modele de langage local en UN SEUL appel (rerankWithLLM()) - plus precis que
	 *    le score de similarite cosinus seul, qui ne "comprend" pas vraiment la
	 *    requete, juste sa proximite vectorielle.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[UserRateLimit(limit: 30, period: 60)]
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

		// Convention de prefixe mxbai-embed-large (verifiee via la doc Ollama officielle,
		// pas devinee) : SEULE la requete recoit ce prefixe d'instruction - les passages
		// eux (embed_backfill.php) sont embarques sans aucun prefixe. L'expansion par
		// synonymes elargit le CONTEXTE embarque (donc le sens capte par le vecteur),
		// mais $term (original) reste utilise pour l'affichage/surbrillance.
		$vector = $this->embedText('Represent this sentence for searching relevant passages: ' . $this->expandWithSynonyms($term));
		if ($vector === null) {
			$this->logger->error('search_hub: echec embedding de la requete (recherche neuronale)');
			return new JSONResponse(['error' => 'search_failed'], 500);
		}

		// k/num_candidates plus genereux que RAW_FETCH_SIZE : plusieurs passages peuvent
		// venir du meme document, il en faut davantage en amont pour obtenir assez de
		// documents PARENTS distincts une fois regroupes.
		$passageK = self::RAW_FETCH_SIZE;
		$query = [
			'size' => $passageK,
			'_source' => ['parent_id', 'chunk_text', 'chunk_index'],
			'knn' => [
				'field' => 'embedding_vector_v2',
				'query_vector' => $vector,
				'k' => $passageK,
				'num_candidates' => $passageK * 3,
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
		$passageHits = $decoded['hits']['hits'] ?? [];

		// Regroupement des passages par document PARENT : on ne garde que le meilleur
		// passage (score le plus eleve) par parent, qui devient le score et l'extrait
		// "naturel" (semantiquement le plus pertinent, pas une heuristique) de ce document.
		$bestByParent = [];
		foreach ($passageHits as $hit) {
			$parentId = (string)($hit['_source']['parent_id'] ?? '');
			if ($parentId === '') {
				continue;
			}
			$score = (float)($hit['_score'] ?? 0);
			if (!isset($bestByParent[$parentId]) || $score > $bestByParent[$parentId]['score']) {
				$bestByParent[$parentId] = [
					'score' => $score,
					'chunkText' => (string)($hit['_source']['chunk_text'] ?? ''),
				];
			}
		}

		if (empty($bestByParent)) {
			return new JSONResponse(['results' => [], 'facets' => $this->emptyFacets(), 'total' => 0, 'page' => 1, 'totalPages' => 1, 'neural' => true]);
		}

		$parentMeta = $this->fetchParentMetadata(array_keys($bestByParent));

		$documents = [];
		foreach ($bestByParent as $parentId => $best) {
			[$providerId, $documentId] = array_pad(explode(':', $parentId, 2), 2, '');
			$meta = $parentMeta[$parentId] ?? null;
			if ($meta === null) {
				// Document supprime/desindexe depuis le calcul du passage (rare, cf.
				// prochain backfill) - on ne peut pas l'afficher sans son titre/lien.
				continue;
			}

			$title = $meta['title'];
			if ($providerId === 'files' && str_starts_with($title, '.Collectifs/')) {
				continue;
			}

			$tags = $meta['tags'];
			// iaeasy n'a pas de route Nextcloud a resoudre (resolveNeuralLink() ne connait
			// que files/collectives/deck) - le lien absolu est deja stocke sur le document
			// parent par iaeasy_index.php (voir fetchParentMetadata()).
			$link = $providerId === 'iaeasy' ? ($meta['iaeasyLink'] ?? '') : $this->resolveNeuralLink($providerId, $documentId);
			$matchedTerms = $this->computeMatchedTerms($term, $title, [$best['chunkText']], $tags);

			$documents[] = [
				'id' => $documentId,
				'providerId' => $providerId,
				'title' => $title,
				'link' => $link,
				'modifiedTime' => $meta['lastModified'],
				'tags' => $tags,
				'excerpts' => [$best['chunkText']],
				'esScore' => $best['score'],
				'matchedTerms' => $matchedTerms,
				'titleMatch' => !empty($this->computeMatchedTerms($term, $title, [], [])),
				'collective' => $this->extractCollectiveFromLink($providerId, $link),
				'fileType' => $providerId === 'iaeasy' ? $this->iaeasyFileType($tags) : $this->extractFileType($providerId, $title),
				'chapter' => null,
			];
		}

		// L'ordre kNN (par score de passage) reste la base ; le RANKER (LLM) va ensuite
		// affiner ce classement sur le sous-ensemble le plus prometteur.
		usort($documents, static fn ($a, $b) => $b['esScore'] <=> $a['esScore']);

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

		$sort = (string)$this->request->getParam('sort', 'relevance');
		if ($sort === 'relevance' && count($tabDocuments) > 1) {
			$tabDocuments = $this->rerankWithLLM($term, $tabDocuments);
		}

		$facets = $this->computeFacets($tabDocuments);
		$facets['providers'] = $this->computeProviderCounts($documents);

		$filtered = $this->applyFilters($tabDocuments);
		// applySort() : 'relevance' preserve l'ordre du tableau tel quel, donc l'ordre
		// issu du RANKER ci-dessus si applicable. Les autres tris (date/titre) restent
		// disponibles mais n'ont pas de sens "pondere" ici (pas de score BM25).
		$filtered = $this->applySort($filtered, $sort);

		$total = count($filtered);
		$totalPages = max(1, (int)ceil($total / self::PAGE_SIZE));
		$requestedPage = max(1, (int)$this->request->getParam('page', 1));
		$requestedPage = min($requestedPage, $totalPages);
		$pageResults = array_slice($filtered, ($requestedPage - 1) * self::PAGE_SIZE, self::PAGE_SIZE);

		// Contenu complet uniquement pour la previsualisation plein texte (bouton
		// "Previsualisation") - l'extrait de liste, lui, vient deja du meilleur passage
		// semantique ci-dessus, plus precis qu'une heuristique sur le contenu complet.
		foreach ($pageResults as &$doc) {
			$doc['fullContent'] = $this->fetchFullContent($doc['providerId'], $doc['id']);
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
	 * Metadonnees (titre/tags/date) des documents PARENTS d'un lot de passages, via un
	 * _mget groupe (une seule requete HTTP pour tous les parents du lot) - meme
	 * principe que fetchChapterMap(). Necessaire car le finder (kNN) ne renvoie que des
	 * passages, qui n'ont pas eux-memes de titre/tags/date (portes par le document
	 * original, jamais duplique sur chaque passage pour eviter la redondance).
	 *
	 * @return array<string, array{title: string, tags: array, lastModified: int}>
	 */
	private function fetchParentMetadata(array $parentIds): array {
		$elasticHost = $this->appConfig->getValueString('fulltextsearch_elasticsearch', 'elastic_host', '', true);
		$elasticIndex = $this->appConfig->getValueString('fulltextsearch_elasticsearch', 'elastic_index', '', true);
		if ($elasticHost === '' || $elasticIndex === '' || empty($parentIds)) {
			return [];
		}

		$docs = array_map(static fn ($id) => ['_id' => $id, '_source' => ['title', 'tags', 'lastModified', 'iaeasy_link']], $parentIds);

		$ch = curl_init(rtrim($elasticHost, '/') . '/' . $elasticIndex . '/_mget');
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_TIMEOUT, 10);
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
			$source = $hit['_source'] ?? [];
			$tags = $source['tags'] ?? [];
			$map[$hit['_id']] = [
				'title' => (string)($source['title'] ?? ''),
				'tags' => is_array($tags) ? $tags : [],
				'lastModified' => (int)($source['lastModified'] ?? 0),
				'iaeasyLink' => (string)($source['iaeasy_link'] ?? ''),
			];
		}

		return $map;
	}

	/**
	 * Recherche mot-cle sur les documents du connecteur iaeasy (voir commentaire dans
	 * search()) : requete ES directe filtree sur provider="iaeasy" (les passages n'ont
	 * jamais ce champ - seuls les documents PARENTS l'ont - donc le filtre les exclut
	 * naturellement, pas besoin d'un must_not exists parent_id supplementaire). $term
	 * ORIGINAL (pas $expandedTerm) sert uniquement au calcul de matchedTerms/titleMatch,
	 * comme partout ailleurs dans cette classe.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function fetchIaeasyKeywordMatches(string $expandedTerm, string $term): array {
		$elasticHost = $this->appConfig->getValueString('fulltextsearch_elasticsearch', 'elastic_host', '', true);
		$elasticIndex = $this->appConfig->getValueString('fulltextsearch_elasticsearch', 'elastic_index', '', true);
		if ($elasticHost === '' || $elasticIndex === '') {
			return [];
		}

		$query = [
			'size' => 100,
			'_source' => ['title', 'content', 'tags', 'lastModified', 'iaeasy_link'],
			'query' => [
				'bool' => [
					'must' => [['multi_match' => ['query' => $expandedTerm, 'fields' => ['title^2', 'content']]]],
					'filter' => [['term' => ['provider.keyword' => 'iaeasy']]],
				],
			],
		];

		$ch = curl_init(rtrim($elasticHost, '/') . '/' . $elasticIndex . '/_search');
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_TIMEOUT, 10);
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($query));
		curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
		$body = curl_exec($ch);
		$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);

		if ($body === false || $httpCode >= 400) {
			return [];
		}

		$decoded = json_decode($body, true);
		$hits = $decoded['hits']['hits'] ?? [];

		$documents = [];
		foreach ($hits as $hit) {
			$source = $hit['_source'] ?? [];
			$fullId = (string)$hit['_id']; // "iaeasy:<source>:<itemId>"
			$documentId = mb_substr($fullId, mb_strlen('iaeasy:'));
			$title = (string)($source['title'] ?? '');
			$content = (string)($source['content'] ?? '');
			$tags = is_array($source['tags'] ?? null) ? $source['tags'] : [];
			$excerpt = mb_substr($content, 0, 300);

			$documents[] = [
				'id' => $documentId,
				'providerId' => 'iaeasy',
				'title' => $title,
				'link' => (string)($source['iaeasy_link'] ?? 'https://iaeasy.noschoixpourvous.com'),
				'modifiedTime' => (int)($source['lastModified'] ?? 0),
				'tags' => $tags,
				'excerpts' => $excerpt !== '' ? [$excerpt] : [],
				'esScore' => (float)($hit['_score'] ?? 0),
				'matchedTerms' => $this->computeMatchedTerms($term, $title, [$excerpt], $tags, $content),
				'titleMatch' => !empty($this->computeMatchedTerms($term, $title, [], [])),
				'collective' => null,
				'fileType' => $this->iaeasyFileType($tags),
				'chapter' => null,
			];
		}

		return $documents;
	}

	/**
	 * Le "type de document" affiche pour un resultat iaeasy vient du "subtype" pose
	 * par iaeasy_index.php dans le champ tags (ex: "modele", "glossaire", "metier") -
	 * PAS de extractFileType() (pensee pour une extension de fichier, non pertinente
	 * ici puisque ce ne sont jamais de vrais fichiers).
	 */
	private function iaeasyFileType(array $tags): string {
		$labels = [
			'modele' => 'modele-ia', 'gabarit' => 'gabarit-agent', 'brique' => 'brique-agent',
			'scenario' => 'scenario-entrainement', 'glossaire' => 'glossaire', 'metier' => 'metier',
			'securite' => 'securite', 'video' => 'video',
		];
		$subtype = $tags[0] ?? '';
		return $labels[$subtype] ?? 'iaeasy';
	}

	/**
	 * RANKER : reclasse les N meilleurs candidats du finder (kNN) via un modele de
	 * langage local, en UN SEUL appel (pas un par document - beaucoup trop lent). Le
	 * score de similarite cosinus mesure une proximite vectorielle, pas une comprehension
	 * reelle de la requete ; un LLM qui lit le titre + le meilleur extrait de chaque
	 * candidat peut corriger les cas ou le finder classe mal (ex: un candidat vectoriellement
	 * proche mais hors-sujet, ou un candidat pertinent legerement moins proche qu'un
	 * candidat generique).
	 */
	private function rerankWithLLM(string $term, array $documents): array {
		$topN = array_slice($documents, 0, (int)$this->loadConfig()['reranking']['topN']);
		$rest = array_slice($documents, (int)$this->loadConfig()['reranking']['topN']);

		$listText = '';
		foreach ($topN as $i => $doc) {
			$snippet = mb_substr($doc['excerpts'][0] ?? '', 0, 220);
			$listText .= ($i + 1) . ". Titre : " . $doc['title'] . "\n   Extrait : " . $snippet . "\n\n";
		}

		$prompt = "Requete de recherche : \"" . $term . "\"\n\n"
			. "Voici " . count($topN) . " documents candidats (titre + extrait) :\n\n"
			. $listText
			. "Classe ces documents du PLUS pertinent au MOINS pertinent pour cette requete precise. "
			. "Reponds UNIQUEMENT avec leurs numeros separes par des virgules, du plus au moins pertinent, "
			. "sans aucun texte ni explication. Exemple de reponse attendue : 3,1,5,2,4";

		$response = $this->generateRaw($prompt);
		if ($response === null) {
			return $documents;
		}

		$order = $this->parseRankingResponse($response, count($topN));
		if ($order === null) {
			return $documents;
		}

		$reranked = [];
		$used = [];
		foreach ($order as $idx) {
			if (isset($topN[$idx]) && !isset($used[$idx])) {
				$reranked[] = $topN[$idx];
				$used[$idx] = true;
			}
		}
		// Filet de securite : un indice manquant/invalide dans la reponse du LLM ne doit
		// pas faire disparaitre un candidat - on rajoute ceux non repris, dans leur ordre
		// kNN d'origine.
		foreach ($topN as $idx => $doc) {
			if (!isset($used[$idx])) {
				$reranked[] = $doc;
			}
		}

		return array_merge($reranked, $rest);
	}

	/**
	 * Parse "3,1,5,2,4" -> [2,0,4,1,3] (indices 0-based). Retourne null si la reponse du
	 * LLM n'est pas exploitable (format inattendu) - rerankWithLLM() retombe alors sur
	 * l'ordre kNN d'origine plutot que de planter ou d'afficher un ordre incoherent.
	 */
	private function parseRankingResponse(string $response, int $expectedCount): ?array {
		if (!preg_match_all('/\d+/', $response, $matches)) {
			return null;
		}
		$numbers = array_map('intval', $matches[0]);
		$indices = array_values(array_unique(array_map(static fn ($n) => $n - 1, $numbers)));
		$indices = array_values(array_filter($indices, static fn ($i) => $i >= 0 && $i < $expectedCount));

		// Trop peu d'indices exploitables (le LLM a mal repondu) : pas fiable, on abandonne.
		if (count($indices) < max(1, (int)($expectedCount * 0.5))) {
			return null;
		}

		return $indices;
	}

	private function generateRaw(string $prompt): ?string {
		$ollamaHost = $this->appConfig->getValueString('search_hub', 'ollama_host', 'http://ollama:11434');
		$model = $this->loadConfig()['reranking']['model'];

		$ch = curl_init(rtrim($ollamaHost, '/') . '/api/generate');
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_TIMEOUT, 45);
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
			$this->logger->error('search_hub: echec appel generation LLM', ['httpCode' => $httpCode]);
			return null;
		}

		$decoded = json_decode($body, true);
		$response = trim((string)($decoded['response'] ?? ''));
		return $response !== '' ? $response : null;
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
		$model = $this->loadConfig()['embedding']['model'];

		// num_batch : evite le plantage Ollama "unable to fit entire input in a batch" sur
		// un texte long (cf. embed_backfill.php) - les requetes utilisateur restent courtes
		// (MAX_TERM_LENGTH=300) donc jamais concernees en pratique, mais coherence assuree
		// si cette limite change un jour.
		$ch = curl_init(rtrim($ollamaHost, '/') . '/api/embeddings');
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_TIMEOUT, 15);
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['model' => $model, 'prompt' => $text, 'options' => ['num_batch' => 4096, 'num_ctx' => 4096]]));
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
		$prompt = "Une recherche par sens (semantique) a trouve ce document pertinent pour la question posee, "
			. "meme s'il ne contient pas forcement les memes mots. En UNE SEULE phrase courte et concrete "
			. "(pas plus de 30 mots), en francais, explique le lien entre la question et ce document. "
			. "Ne repete pas la question mot pour mot, va droit au but.\n\n"
			. "Question de recherche : " . $term . "\n"
			. "Titre du document : " . $title . "\n"
			. "Extrait du document : " . $snippet;

		return $this->generateRaw($prompt);
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
