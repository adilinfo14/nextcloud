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
			return new JSONResponse(['results' => [], 'facets' => $this->emptyFacets(), 'total' => 0]);
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
				'size' => 150,
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
				$metaTags = $document->getMetaTags();
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
					// Chapitre/sous-chapitre parent d'une page Collectives (voir
					// fulltextsearch_collectives::getParentChapterTitle) ; absent pour
					// les autres providers ou pour une page racine sans parent.
					'chapter' => $metaTags[0] ?? null,
				];
			}
		}

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
		$page = array_slice($filtered, 0, 60);

		// Le contenu complet (pour la previsualisation avec surbrillance) n'est recupere
		// que pour les resultats reellement affiches, pas pour les ~150 candidats bruts.
		foreach ($page as &$doc) {
			$fullContent = $this->fetchFullContent($doc['providerId'], $doc['id']);
			if ($fullContent !== '' && empty($doc['matchedTerms'])) {
				$doc['matchedTerms'] = $this->computeMatchedTerms($term, $doc['title'], $doc['excerpts'], $doc['tags'], $fullContent);
			}
			$doc['fullContent'] = $fullContent;
		}
		unset($doc);

		return new JSONResponse([
			'results' => $page,
			'facets' => $facets,
			'total' => count($filtered),
			'totalUnfiltered' => count($tabDocuments),
			'weightsUsed' => $weights,
			'isAdmin' => $this->groupManager->isAdmin($user->getUID()),
		]);
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
		$chapters = [];
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
				$chapters[$doc['chapter']] = ($chapters[$doc['chapter']] ?? 0) + 1;
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
		arsort($chapters);

		return [
			'providers' => $providers,
			'tags' => $tags,
			'collectives' => $collectives,
			'fileTypes' => $fileTypes,
			'chapters' => $chapters,
			'periods' => $periods,
		];
	}

	private function emptyFacets(): array {
		return ['providers' => [], 'tags' => [], 'collectives' => [], 'fileTypes' => [], 'chapters' => [], 'periods' => ['24h' => 0, '7j' => 0, '30j' => 0]];
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
