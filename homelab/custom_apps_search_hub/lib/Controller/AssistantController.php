<?php

declare(strict_types=1);

namespace OCA\SearchHub\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Assistant "reponse ancree, respectueuse des droits" (2026-07-15) - prototype de
 * l'architecture presentee au client : voir la page wiki "24 Assistant reponses
 * ancrees" pour le schema complet. Reutilise le MEME mecanisme de recherche par
 * sens filtree par droits que SearchController::searchNeural() (buildAccessFilterShould,
 * embedText, fetchParentMetadata) - duplique ici plutot qu'extrait en service partage,
 * meme choix deja fait pour StatusController::loadSearchHubConfig() dans ce projet
 * (limite le risque sur le code de recherche existant, qui fonctionne et est teste).
 *
 * Pipeline (chaque etape peut interrompre et faire "abstenir" l'assistant plutot que
 * de risquer une reponse fausse ou hors-droits) :
 *   1. Recherche par sens filtree par droits (identique a searchNeural)
 *   2. Seuil de confiance - abstention si rien d'assez pertinent
 *   3. Generation strictement ancree (le modele n'a acces qu'aux passages retrouves)
 *   4. Controle de veracite - UN SECOND appel LLM, independant, verifie que la
 *      reponse est bien soutenue par les passages avant de l'afficher
 *   5. Journal d'audit - chaque question, les documents utilises, le verdict
 */
class AssistantController extends Controller {
	private const MAX_QUESTION_LENGTH = 500;
	private const TOP_K_PASSAGES = 8;
	private const AUDIT_LOG_PATH = '/var/www/html/custom_apps/search_hub/assistant_audit.log';

	public function __construct(
		string $appName,
		IRequest $request,
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
		'embedding' => ['model' => 'mxbai-embed-large'],
		'reranking' => ['model' => 'llama3:8b'],
		'assistant' => [
			'minConfidenceScore' => 0.78,
			'generationModel' => 'llama3:8b',
			'verificationModel' => 'llama3:8b',
		],
	];

	/**
	 * Meme fichier de configuration que SearchController/StatusController - source
	 * unique, deja etabli dans ce projet pour eviter toute divergence.
	 */
	private function loadConfig(): array {
		$userConfig = file_exists(self::CONFIG_PATH) ? json_decode((string)file_get_contents(self::CONFIG_PATH), true) : null;
		return is_array($userConfig) ? array_replace_recursive(self::CONFIG_DEFAULTS, $userConfig) : self::CONFIG_DEFAULTS;
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(): \OCP\AppFramework\Http\TemplateResponse {
		return new \OCP\AppFramework\Http\TemplateResponse('search_hub', 'assistant');
	}

	/**
	 * Point d'entree principal - voir le pipeline decrit en tete de fichier.
	 * Rate-limit resserre (comme searchNeural et explainMatch) : chaque question
	 * declenche potentiellement 2 appels LLM (generation + verification), couteux
	 * sur ce serveur CPU sans GPU.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[UserRateLimit(limit: 15, period: 60)]
	public function ask(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'forbidden'], 403);
		}

		$question = trim((string)$this->request->getParam('question', ''));
		if ($question === '') {
			return new JSONResponse(['error' => 'invalid_params'], 400);
		}
		if (mb_strlen($question) > self::MAX_QUESTION_LENGTH) {
			$question = mb_substr($question, 0, self::MAX_QUESTION_LENGTH);
		}

		$config = $this->loadConfig();

		// --- Etape 1 : recherche par sens filtree par droits ---
		$passages = $this->retrieveGroundedPassages($question, $user);

		// --- Etape 2 : seuil de confiance ---
		$topScore = $passages[0]['score'] ?? 0.0;
		$minScore = (float)$config['assistant']['minConfidenceScore'];
		if (empty($passages) || $topScore < $minScore) {
			$this->logAudit($user, $question, $passages, null, 'abstained_low_confidence', $topScore);
			return new JSONResponse([
				'abstained' => true,
				'reason' => 'no_relevant_content',
				'message' => "Je n'ai rien trouvé d'assez pertinent dans les documents auxquels vous avez accès pour répondre à cette question.",
				'sources' => [],
			]);
		}

		// --- Etape 3 : generation strictement ancree ---
		$draftAnswer = $this->generateGroundedAnswer($question, $passages, $config['assistant']['generationModel']);
		if ($draftAnswer === null) {
			$this->logAudit($user, $question, $passages, null, 'generation_failed', $topScore);
			return new JSONResponse(['error' => 'generation_failed'], 500);
		}

		// --- Etape 4 : controle de veracite (second modele independant) ---
		$verification = $this->verifyGroundedness($question, $draftAnswer, $passages, $config['assistant']['verificationModel']);

		if ($verification['verdict'] === 'unsupported') {
			$this->logAudit($user, $question, $passages, $draftAnswer, 'abstained_unverified', $topScore);
			return new JSONResponse([
				'abstained' => true,
				'reason' => 'not_grounded',
				'message' => "Je ne suis pas en mesure de répondre de façon fiable à cette question à partir des documents disponibles.",
				'sources' => [],
			]);
		}

		$finalAnswer = $verification['correctedAnswer'] ?? $draftAnswer;

		// --- Etape 5 : journal d'audit ---
		$this->logAudit($user, $question, $passages, $finalAnswer, 'answered', $topScore);

		return new JSONResponse([
			'abstained' => false,
			'answer' => $finalAnswer,
			'verified' => $verification['verdict'] === 'valid',
			'sources' => array_map(static fn ($p) => [
				'title' => $p['title'],
				'link' => $p['link'],
				'providerId' => $p['providerId'],
				'excerpt' => mb_substr($p['chunkText'], 0, 200),
			], $passages),
		]);
	}

	/**
	 * Point d'entree pour un client MCP (ex: Claude Desktop) - AUCUNE generation ni
	 * verification locale ici : Claude lui-meme fait ce travail cote client, avec un
	 * modele bien plus capable que le llama3:8b local. Ce endpoint ne fait QUE la
	 * partie sensible et non-negociable : la recherche filtree par droits (etape 1)
	 * et le seuil de confiance (etape 2) - Claude ne voit jamais un document hors
	 * des droits de l'utilisateur qui l'interroge, exactement comme le pipeline complet.
	 *
	 * Authentification : mot de passe d'application Nextcloud (Basic Auth), PAS la
	 * session web - chaque utilisateur Claude Desktop doit avoir son propre mot de
	 * passe d'application pour que le filtrage par droits s'applique a LUI, jamais un
	 * compte de service partage qui casserait tout le principe de l'architecture.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[UserRateLimit(limit: 30, period: 60)]
	public function mcpSearch(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'forbidden'], 403);
		}

		$question = trim((string)$this->request->getParam('question', ''));
		if ($question === '') {
			return new JSONResponse(['error' => 'invalid_params'], 400);
		}
		if (mb_strlen($question) > self::MAX_QUESTION_LENGTH) {
			$question = mb_substr($question, 0, self::MAX_QUESTION_LENGTH);
		}

		$config = $this->loadConfig();
		$passages = $this->retrieveGroundedPassages($question, $user);

		$topScore = $passages[0]['score'] ?? 0.0;
		$minScore = (float)$config['assistant']['minConfidenceScore'];
		if (empty($passages) || $topScore < $minScore) {
			$this->logAudit($user, $question, $passages, null, 'mcp_no_results', $topScore);
			return new JSONResponse(['found' => false, 'passages' => []]);
		}

		$this->logAudit($user, $question, $passages, null, 'mcp_search', $topScore);

		return new JSONResponse([
			'found' => true,
			'passages' => array_map(static fn ($p) => [
				'title' => $p['title'],
				'link' => $p['link'],
				'text' => $p['chunkText'],
			], $passages),
		]);
	}

	/**
	 * Recherche par sens filtree par droits - reproduit fidelement
	 * SearchController::searchNeural() (meme embedding, meme filtre d'acces, meme
	 * regroupement par document parent) mais renvoie une liste plate de passages
	 * consommable pour un prompt de generation, sans facettes/pagination/tri
	 * (inutiles ici).
	 *
	 * @return array<int, array{score: float, chunkText: string, title: string, link: string, providerId: string}>
	 */
	private function retrieveGroundedPassages(string $question, \OCP\IUser $user): array {
		$elasticHost = $this->appConfig->getValueString('fulltextsearch_elasticsearch', 'elastic_host', '', true);
		$elasticIndex = $this->appConfig->getValueString('fulltextsearch_elasticsearch', 'elastic_index', '', true);
		if ($elasticHost === '' || $elasticIndex === '') {
			return [];
		}

		$vector = $this->embedText('Represent this sentence for searching relevant passages: ' . $question);
		if ($vector === null) {
			$this->logger->error('search_hub assistant: echec embedding de la question');
			return [];
		}

		$passageK = 200;
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
			$this->logger->error('search_hub assistant: recherche echouee', ['httpCode' => $httpCode]);
			return [];
		}

		$decoded = json_decode($body, true);
		$hits = $decoded['hits']['hits'] ?? [];

		// Un seul passage (le meilleur) par document parent - assez de contexte
		// distinct pour la generation sans repeter le meme document plusieurs fois.
		$bestByParent = [];
		foreach ($hits as $hit) {
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
			return [];
		}

		uasort($bestByParent, static fn ($a, $b) => $b['score'] <=> $a['score']);
		$bestByParent = array_slice($bestByParent, 0, self::TOP_K_PASSAGES, true);

		$parentMeta = $this->fetchParentMetadata(array_keys($bestByParent));

		$passages = [];
		foreach ($bestByParent as $parentId => $best) {
			[$providerId, $documentId] = array_pad(explode(':', $parentId, 2), 2, '');
			$meta = $parentMeta[$parentId] ?? null;
			if ($meta === null) {
				continue;
			}
			$isExternal = in_array($providerId, ['iaeasy', 'confia_doc'], true);
			$link = $isExternal ? ($meta['externalLink'] ?? '') : $this->resolveNativeLink($providerId, $documentId);

			$passages[] = [
				'score' => $best['score'],
				'chunkText' => $best['chunkText'],
				'title' => $meta['title'],
				'link' => $link,
				'providerId' => $providerId,
			];
		}

		return $passages;
	}

	/** Meme logique que SearchController::buildAccessFilterShould() - ne pas simplifier. */
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
			// App Circles indisponible : owner/users/groupes suffisent deja pour Files/Deck.
		}

		return $should;
	}

	private function fetchParentMetadata(array $parentIds): array {
		$elasticHost = $this->appConfig->getValueString('fulltextsearch_elasticsearch', 'elastic_host', '', true);
		$elasticIndex = $this->appConfig->getValueString('fulltextsearch_elasticsearch', 'elastic_index', '', true);
		if ($elasticHost === '' || $elasticIndex === '' || empty($parentIds)) {
			return [];
		}

		$docs = array_map(static fn ($id) => ['_id' => $id, '_source' => ['title', 'external_link']], $parentIds);

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
			$map[$hit['_id']] = [
				'title' => (string)($source['title'] ?? ''),
				'externalLink' => (string)($source['external_link'] ?? ''),
			];
		}

		return $map;
	}

	private function resolveNativeLink(string $providerId, string $documentId): string {
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

		$ch = curl_init(rtrim($ollamaHost, '/') . '/api/embeddings');
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_TIMEOUT, 15);
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['model' => $model, 'prompt' => $text, 'keep_alive' => '30m', 'options' => ['num_batch' => 4096, 'num_ctx' => 4096]]));
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
	 * Etape 3 : generation STRICTEMENT ancree. Le prompt interdit explicitement au
	 * modele de completer avec ses propres connaissances - c'est necessaire mais
	 * PAS suffisant seul (d'ou le controle de veracite independant a l'etape 4, qui
	 * ne fait pas confiance a cette seule consigne).
	 */
	private function generateGroundedAnswer(string $question, array $passages, string $model): ?string {
		$context = '';
		foreach ($passages as $i => $p) {
			$context .= "[Source " . ($i + 1) . " : " . $p['title'] . "]\n" . mb_substr($p['chunkText'], 0, 1500) . "\n\n";
		}

		$prompt = "Tu es un assistant documentaire. Reponds à la question UNIQUEMENT à partir des sources "
			. "fournies ci-dessous. Ne complete JAMAIS avec des connaissances generales ou ce que tu sais par "
			. "ailleurs - si l'information n'est pas dans les sources, dis explicitement \"Je ne sais pas\" "
			. "plutot que de deviner. Cite le numero de la source pour chaque affirmation (ex: [Source 2]).\n\n"
			. "SOURCES :\n\n" . $context
			. "QUESTION : " . $question . "\n\n"
			. "REPONSE (en francais, avec citations [Source N]) :";

		return $this->generateRaw($prompt, $model, 130);
	}

	/**
	 * Etape 4 : controle de veracite - UN SECOND appel LLM, distinct de celui qui a
	 * redige la reponse, dont le seul travail est de verifier chaque affirmation
	 * contre les sources. Ne fait JAMAIS confiance a la consigne de l'etape 3 seule :
	 * un modele qui a deja "decide" de repondre peut rester biaise en faveur de sa
	 * propre reponse - un second regard, avec un prompt different (verifier plutot
	 * que generer), attrape plus de cas.
	 *
	 * @return array{verdict: 'valid'|'corrected'|'unsupported', correctedAnswer: ?string}
	 */
	private function verifyGroundedness(string $question, string $answer, array $passages, string $model): array {
		$context = '';
		foreach ($passages as $i => $p) {
			$context .= "[Source " . ($i + 1) . "]\n" . mb_substr($p['chunkText'], 0, 1500) . "\n\n";
		}

		$prompt = "Tu es un controleur de qualite strict. Voici des SOURCES, une QUESTION, et une REPONSE "
			. "generee par un autre modele a partir de ces sources. Ta tache : verifier que CHAQUE affirmation "
			. "de la reponse est reellement soutenue par le contenu des sources (pas juste plausible - "
			. "explicitement present).\n\n"
			. "SOURCES :\n\n" . $context
			. "QUESTION : " . $question . "\n\n"
			. "REPONSE A VERIFIER :\n" . $answer . "\n\n"
			. "Reponds UNIQUEMENT par un de ces 3 formats exacts, sans autre texte :\n"
			. "VALIDE (si tout est soutenu par les sources)\n"
			. "CORRIGE: <version corrigee ne gardant que les affirmations soutenues>\n"
			. "NON_SOUTENU (si rien d'important n'est soutenu par les sources)";

		$response = $this->generateRaw($prompt, $model, 130);
		if ($response === null) {
			// Echec du controle lui-meme = on ne peut pas garantir la fiabilite -
			// prudence : on traite comme non soutenu plutot que de laisser passer.
			return ['verdict' => 'unsupported', 'correctedAnswer' => null];
		}

		$trimmed = trim($response);
		if (stripos($trimmed, 'VALIDE') === 0) {
			return ['verdict' => 'valid', 'correctedAnswer' => null];
		}
		if (stripos($trimmed, 'NON_SOUTENU') === 0) {
			return ['verdict' => 'unsupported', 'correctedAnswer' => null];
		}
		if (stripos($trimmed, 'CORRIGE') === 0) {
			$corrected = trim(preg_replace('/^CORRIGE\s*:\s*/i', '', $trimmed));
			if ($corrected === '') {
				return ['verdict' => 'unsupported', 'correctedAnswer' => null];
			}
			return ['verdict' => 'corrected', 'correctedAnswer' => $corrected];
		}

		// Format de reponse inattendu du controleur : prudence, on n'affiche pas
		// une reponse dont on n'a pas pu confirmer la fiabilite.
		return ['verdict' => 'unsupported', 'correctedAnswer' => null];
	}

	private function generateRaw(string $prompt, string $model, int $timeout): ?string {
		$ollamaHost = $this->appConfig->getValueString('search_hub', 'ollama_host', 'http://ollama:11434');

		$ch = curl_init(rtrim($ollamaHost, '/') . '/api/generate');
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
		curl_setopt($ch, CURLOPT_POST, true);
		// keep_alive : garde le modele charge en memoire 30 min au lieu des 5 min par
		// defaut d'Ollama - un serveur sans GPU met un temps non-negligeable a recharger
		// un modele de 4-5 Go a froid, ce qui a deja fait echouer une generation en
		// conditions reelles (timeout de 120s consomme par le seul rechargement).
		curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['model' => $model, 'prompt' => $prompt, 'stream' => false, 'keep_alive' => '30m']));
		curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
		$body = curl_exec($ch);
		$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);

		if ($body === false || $httpCode >= 400) {
			$this->logger->error('search_hub assistant: echec appel LLM', ['httpCode' => $httpCode]);
			return null;
		}

		$decoded = json_decode($body, true);
		$response = trim((string)($decoded['response'] ?? ''));
		return $response !== '' ? $response : null;
	}

	/**
	 * Etape 5 : journal d'audit - append-only, une ligne JSON par question. Prototype
	 * volontairement simple (fichier, pas une table dediee) : suffisant pour
	 * demontrer le principe, une vraie mise en production utiliserait une table SQL
	 * requetable plutot qu'un fichier plat.
	 */
	private function logAudit(\OCP\IUser $user, string $question, array $passages, ?string $answer, string $outcome, float $topScore): void {
		$entry = [
			'timestamp' => time(),
			'user' => $user->getUID(),
			'question' => $question,
			'outcome' => $outcome,
			'topScore' => round($topScore, 4),
			'sourcesUsed' => array_map(static fn ($p) => $p['providerId'] . ':' . $p['title'], $passages),
			'answerGiven' => $answer,
		];
		file_put_contents(self::AUDIT_LOG_PATH, json_encode($entry, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
	}
}
