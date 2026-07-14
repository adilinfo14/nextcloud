<?php

declare(strict_types=1);

namespace OCA\SearchHub\Controller;

use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

class StatusController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private IConfig $config,
		private IAppConfig $appConfig,
		private IDBConnection $db,
		private IUserSession $userSession,
		private IGroupManager $groupManager,
		private IAppManager $appManager,
		private LoggerInterface $logger,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Appel HTTP direct (pas via IClientService, qui bloque par design les hotes
	 * internes de type "elasticsearch" comme mesure anti-SSRF) - l'URL vient
	 * uniquement de la config admin de fulltextsearch_elasticsearch, jamais
	 * d'une entree utilisateur, donc pas de risque SSRF ici.
	 */
	private function esInternalRequest(string $method, string $url, ?array $jsonBody = null): ?array {
		$ch = curl_init($url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_TIMEOUT, 5);
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
		if ($jsonBody !== null) {
			curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($jsonBody));
			curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
		}
		$body = curl_exec($ch);
		$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);

		if ($body === false || $httpCode >= 400) {
			return null;
		}

		$decoded = json_decode($body, true);
		return is_array($decoded) ? $decoded : null;
	}

	private function isCurrentUserAdmin(): bool {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return false;
		}
		return $this->groupManager->isAdmin($user->getUID());
	}

	public function get(): JSONResponse {
		if (!$this->isCurrentUserAdmin()) {
			return new JSONResponse(['error' => 'forbidden'], 403);
		}

		$elasticHost = $this->appConfig->getValueString('fulltextsearch_elasticsearch', 'elastic_host', '', true);
		$elasticIndex = $this->appConfig->getValueString('fulltextsearch_elasticsearch', 'elastic_index', '', true);

		$providers = [];
		$esOnline = false;
		$totalCount = 0;
		$indexStats = null;

		if ($elasticHost !== '' && $elasticIndex !== '') {
			// Exclut les passages (parent_id) du compte "documents" classique - ils vivent
			// dans le MEME index Elasticsearch mais ne sont pas des documents BM25 a part
			// entiere (voir getEmbeddingBackfillStatus() pour leur propre compteur dedie).
			// Sans ce filtre, ce total inclurait les ~400+ passages et deviendrait trompeur.
			$countData = $this->esInternalRequest(
				'POST',
				rtrim($elasticHost, '/') . '/' . $elasticIndex . '/_count',
				['query' => ['bool' => ['must_not' => ['exists' => ['field' => 'parent_id']]]]]
			);
			if ($countData !== null) {
				$totalCount = (int)($countData['count'] ?? 0);
				$esOnline = true;

				$aggData = $this->esInternalRequest(
					'POST',
					rtrim($elasticHost, '/') . '/' . $elasticIndex . '/_search',
					['size' => 0, 'aggs' => ['by_provider' => ['terms' => ['field' => 'provider.keyword', 'size' => 20]]]]
				);
				$buckets = $aggData['aggregations']['by_provider']['buckets'] ?? [];
				foreach ($buckets as $bucket) {
					$providers[] = [
						'id' => $bucket['key'],
						'count' => $bucket['doc_count'],
					];
				}

				$statsData = $this->esInternalRequest('GET', rtrim($elasticHost, '/') . '/' . $elasticIndex . '/_stats/store,docs');
				$primaries = $statsData['_all']['primaries'] ?? null;
				if ($primaries !== null) {
					$indexStats = [
						'sizeBytes' => (int)($primaries['store']['size_in_bytes'] ?? 0),
						'docCount' => (int)($primaries['docs']['count'] ?? 0),
						'deletedDocs' => (int)($primaries['docs']['deleted'] ?? 0),
					];
				}
			}
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from('fulltextsearch_ticks')
			->orderBy('id', 'DESC')
			->setMaxResults(5);
		$result = $qb->executeQuery();
		$recentTicks = [];
		$isRunning = false;
		while ($row = $result->fetch()) {
			$recentTicks[] = [
				'source' => $row['source'],
				'status' => $row['status'],
				'action' => $row['action'],
				'tick' => (int)$row['tick'],
			];
		}
		$result->closeCursor();
		if (!empty($recentTicks) && $recentTicks[0]['status'] === 'run') {
			$isRunning = true;
		}

		$cronLastRun = null;
		$qbJob = $this->db->getQueryBuilder();
		$qbJob->select('last_run')
			->from('jobs')
			->where($qbJob->expr()->eq('class', $qbJob->createNamedParameter('OCA\\FullTextSearch\\Cron\\Index')));
		$jobResult = $qbJob->executeQuery();
		$lastRun = $jobResult->fetchOne();
		$jobResult->closeCursor();
		if ($lastRun !== false) {
			$cronLastRun = (int)$lastRun;
		}

		$ocr = [
			'enabled' => $this->config->getAppValue('files_fulltextsearch_tesseract', 'tesseract_enabled', '0') === '1',
			'pdf' => $this->config->getAppValue('files_fulltextsearch_tesseract', 'tesseract_pdf', '0') === '1',
			'lang' => $this->config->getAppValue('files_fulltextsearch_tesseract', 'tesseract_lang', ''),
			'installed' => $this->appManager->isEnabledForUser('files_fulltextsearch_tesseract'),
		];

		$groupfoldersIndexed = $this->config->getAppValue('files_fulltextsearch', 'files_group_folders', '0') === '1';

		$embeddingBackfill = $this->getEmbeddingBackfillStatus($elasticHost, $elasticIndex, $esOnline);

		return new JSONResponse([
			'esOnline' => $esOnline,
			'elasticHost' => $elasticHost,
			'elasticIndex' => $elasticIndex,
			'totalCount' => $totalCount,
			'providers' => $providers,
			'indexStats' => $indexStats,
			'recentTicks' => $recentTicks,
			'isRunning' => $isRunning,
			'cronLastRun' => $cronLastRun,
			'ocr' => $ocr,
			'groupfoldersIndexed' => $groupfoldersIndexed,
			'embeddingBackfill' => $embeddingBackfill,
			'connectors' => $this->getConnectors(),
		]);
	}

	/**
	 * Statut de la recherche PAR SENS (kNN sur passages) : distinct du statut BM25
	 * ci-dessus (source de donnees differente - fichier de statut ecrit par
	 * embed_backfill.php, PAS la table fulltextsearch_ticks qui ne couvre que le cron
	 * d'indexation classique).
	 */
	private function getEmbeddingBackfillStatus(string $elasticHost, string $elasticIndex, bool $esOnline): array {
		$totalPassages = 0;
		$totalChunkedDocuments = 0;
		$providersChunked = [];
		if ($esOnline) {
			$passageCount = $this->esInternalRequest(
				'POST',
				rtrim($elasticHost, '/') . '/' . $elasticIndex . '/_count',
				['query' => ['exists' => ['field' => 'parent_id']]]
			);
			$totalPassages = (int)($passageCount['count'] ?? 0);

			$chunkedAgg = $this->esInternalRequest(
				'POST',
				rtrim($elasticHost, '/') . '/' . $elasticIndex . '/_search',
				[
					'size' => 0,
					'query' => ['exists' => ['field' => 'chunked_v2']],
					'aggs' => ['by_provider' => ['terms' => ['field' => 'provider.keyword', 'size' => 20]]],
				]
			);
			$chunkedBuckets = $chunkedAgg['aggregations']['by_provider']['buckets'] ?? [];
			foreach ($chunkedBuckets as $bucket) {
				$providersChunked[] = ['id' => $bucket['key'], 'count' => $bucket['doc_count']];
				$totalChunkedDocuments += $bucket['doc_count'];
			}
		}

		$statusPath = '/var/www/html/custom_apps/search_hub/embed_backfill_status.json';
		$lastRun = null;
		if (file_exists($statusPath)) {
			$decoded = json_decode((string)file_get_contents($statusPath), true);
			if (is_array($decoded)) {
				$lastRun = $decoded;
			}
		}

		$config = $this->loadSearchHubConfig();

		return [
			'totalPassages' => $totalPassages,
			'totalChunkedDocuments' => $totalChunkedDocuments,
			'providersChunked' => $providersChunked,
			'vectorField' => 'embedding_vector_v2',
			'vectorDims' => 1024,
			'embeddingModel' => $config['embedding']['model'] ?? '-',
			'rerankingModel' => $config['reranking']['model'] ?? '-',
			'lastRun' => $lastRun,
		];
	}

	private const CONFIG_PATH = '/var/www/html/custom_apps/search_hub/search_hub_config.json';
	private const CONFIG_DEFAULTS = [
		'embedding' => [
			'model' => 'mxbai-embed-large',
			'chunkSize' => 6000,
			'chunkOverlap' => 500,
			'chunkSizeRetry' => 350,
			'minContentLen' => 20,
			'maxChunksPerDoc' => 200,
			'imageExtensions' => ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'heic', 'heif', 'webp', 'tiff', 'tif'],
			'spreadsheetExtensions' => ['xlsx', 'xls', 'ods', 'csv'],
			'demoPathPrefixes' => ['Modèles/', 'Templates/', 'Notes/'],
			'demoExactTitles' => [
				'Documents/Example.md',
				'1. Ouvrez pour en apprendre davantage sur les tableaux et les cartes',
				'2. Faites glisser les cartes vers la gauche et la droite, vers le haut et le bas',
				'Créez votre première carte !',
				'3. Appliquer un formatage riche et lier le contenu',
				'4. Partagez, commentez et collaborez !',
			],
		],
		'reranking' => ['model' => 'llama3:8b', 'topN' => 15],
		'synonyms' => [],
		'iaeasy' => ['apiBase' => 'https://iaeasy.noschoixpourvous.com/api'],
	];

	/**
	 * Meme fichier de configuration que SearchController/embed_backfill.php (source
	 * unique, evite toute divergence entre ce qui est affiche et ce qui est reellement
	 * utilise par le moteur).
	 */
	private function loadSearchHubConfig(): array {
		if (!file_exists(self::CONFIG_PATH)) {
			return self::CONFIG_DEFAULTS;
		}
		$decoded = json_decode((string)file_get_contents(self::CONFIG_PATH), true);
		return is_array($decoded) ? array_replace_recursive(self::CONFIG_DEFAULTS, $decoded) : self::CONFIG_DEFAULTS;
	}

	public function getConfig(): JSONResponse {
		if (!$this->isCurrentUserAdmin()) {
			return new JSONResponse(['error' => 'forbidden'], 403);
		}
		return new JSONResponse($this->loadSearchHubConfig());
	}

	/**
	 * Validation minimale mais REELLE avant ecriture (types attendus, bornes sur les
	 * nombres) - meme reserve a un admin, on n'ecrit jamais tel quel ce qui vient d'un
	 * formulaire cote client sur un fichier lu ensuite par un script d'indexation.
	 */
	private function sanitizeConfig(array $input): array {
		$out = self::CONFIG_DEFAULTS;

		if (isset($input['embedding']) && is_array($input['embedding'])) {
			$e = $input['embedding'];
			if (isset($e['model']) && is_string($e['model']) && $e['model'] !== '') {
				$out['embedding']['model'] = $e['model'];
			}
			foreach (['chunkSize', 'chunkOverlap', 'chunkSizeRetry', 'minContentLen', 'maxChunksPerDoc'] as $key) {
				if (isset($e[$key]) && is_numeric($e[$key])) {
					$out['embedding'][$key] = max(1, (int)$e[$key]);
				}
			}
			foreach (['imageExtensions', 'spreadsheetExtensions', 'demoPathPrefixes', 'demoExactTitles'] as $key) {
				if (isset($e[$key]) && is_array($e[$key])) {
					$out['embedding'][$key] = array_values(array_filter(
						array_map('trim', array_map('strval', $e[$key])),
						static fn ($v) => $v !== ''
					));
				}
			}
		}

		if (isset($input['reranking']) && is_array($input['reranking'])) {
			$r = $input['reranking'];
			if (isset($r['model']) && is_string($r['model']) && $r['model'] !== '') {
				$out['reranking']['model'] = $r['model'];
			}
			if (isset($r['topN']) && is_numeric($r['topN'])) {
				$out['reranking']['topN'] = max(1, min(50, (int)$r['topN']));
			}
		}

		if (isset($input['iaeasy']) && is_array($input['iaeasy'])) {
			$apiBase = $input['iaeasy']['apiBase'] ?? '';
			if (is_string($apiBase) && preg_match('#^https://#', $apiBase)) {
				$out['iaeasy']['apiBase'] = rtrim($apiBase, '/');
			}
		}

		if (isset($input['synonyms']) && is_array($input['synonyms'])) {
			$groups = [];
			foreach ($input['synonyms'] as $group) {
				if (!is_array($group) || !isset($group['terms']) || !is_array($group['terms'])) {
					continue;
				}
				$terms = array_values(array_filter(
					array_map('trim', array_map('strval', $group['terms'])),
					static fn ($t) => $t !== ''
				));
				if (count($terms) >= 2) {
					$groups[] = ['terms' => $terms];
				}
			}
			$out['synonyms'] = $groups;
		}

		return $out;
	}

	public function saveConfig(): JSONResponse {
		if (!$this->isCurrentUserAdmin()) {
			return new JSONResponse(['error' => 'forbidden'], 403);
		}

		$raw = file_get_contents('php://input');
		$decoded = json_decode((string)$raw, true);
		if (!is_array($decoded)) {
			return new JSONResponse(['error' => 'invalid_json'], 400);
		}

		$sanitized = $this->sanitizeConfig($decoded);
		file_put_contents(self::CONFIG_PATH, json_encode($sanitized, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
		$this->logger->info('search_hub: configuration mise a jour depuis la console admin');

		return new JSONResponse(['saved' => true, 'config' => $sanitized]);
	}

	/**
	 * Rend explicite la notion de "connecteur" (une source de contenu indexee dans
	 * Recherche+) - demande utilisateur du 2026-07-14 : montrer clairement ce qui EST
	 * connecte aujourd'hui, et ce qui pourrait l'etre. Deux connecteurs "externes"
	 * (pas des IFullTextSearchProvider) ajoutes le meme jour :
	 * - iaeasy.noschoixpourvous.com (iaeasy_index.php) : indexation directe ES depuis
	 *   l'API publique de ce site (catalogue, gabarits/briques, scenarios,
	 *   glossaire, metiers, securite, videos).
	 * - confia_doc (confia_doc_index.php) : documentation TECHNIQUE de ConfIA/
	 *   Lesensia (docs .md, endpoints API, schema DB) - volontairement PAS les
	 *   donnees metier (devis/factures/clients), qui sont multi-tenant et
	 *   necessiteraient de resoudre le cloisonnement entre artisans avant toute
	 *   indexation (choix utilisateur explicite). Source = export JSON pousse
	 *   depuis le VPS ConfIA via le meme lien Tailscale/cle SSH que les backups
	 *   (confia-doc-export-push.sh, cron 03:45), PAS un appel HTTP direct comme
	 *   iaeasy (cette doc n'a pas d'API publique, et l'exposer publiquement serait
	 *   un risque de securite - schema DB + toutes les routes API).
	 */
	private function getConnectors(): array {
		return [
			'active' => [
				['id' => 'files', 'label' => 'Fichiers', 'type' => 'Application Nextcloud native (IFullTextSearchProvider)'],
				['id' => 'collectives', 'label' => 'Wiki (Collectives)', 'type' => 'Connecteur custom (fulltextsearch_collectives, developpe pour ce projet)'],
				['id' => 'deck', 'label' => 'Deck', 'type' => 'Application Nextcloud native (IFullTextSearchProvider)'],
				['id' => 'iaeasy', 'label' => 'iaeasy.noschoixpourvous.com', 'type' => 'Connecteur custom (iaeasy_index.php, indexation directe ES depuis l\'API publique - pas un IFullTextSearchProvider)'],
				['id' => 'confia_doc', 'label' => 'Lesensia - doc technique', 'type' => 'Connecteur custom (confia_doc_index.php, export pousse via Tailscale - doc/endpoints/schema DB uniquement, pas de donnees client)'],
			],
			'proposed' => [],
		];
	}

	public function reindex(): JSONResponse {
		if (!$this->isCurrentUserAdmin()) {
			return new JSONResponse(['error' => 'forbidden'], 403);
		}

		$logPath = '/tmp/search_hub_reindex.log';
		$cmd = 'nohup php /var/www/html/occ fulltextsearch:index --no-interaction > '
			. escapeshellarg($logPath) . ' 2>&1 & echo $!';

		exec($cmd, $output);
		$this->logger->info('search_hub: reindexation manuelle declenchee depuis le tableau de bord admin');

		return new JSONResponse(['started' => true, 'pid' => $output[0] ?? null]);
	}

	public function reindexEmbeddings(): JSONResponse {
		if (!$this->isCurrentUserAdmin()) {
			return new JSONResponse(['error' => 'forbidden'], 403);
		}

		$logPath = '/tmp/search_hub_embed_backfill.log';
		$cmd = 'nohup php /var/www/html/custom_apps/search_hub/embed_backfill.php > '
			. escapeshellarg($logPath) . ' 2>&1 & echo $!';

		exec($cmd, $output);
		$this->logger->info('search_hub: backfill des embeddings declenche manuellement depuis le tableau de bord admin');

		return new JSONResponse(['started' => true, 'pid' => $output[0] ?? null]);
	}

	/**
	 * Synchronisation du connecteur iaeasy : resync COMPLETE (pas incrementale, voir
	 * en-tete d'iaeasy_index.php) depuis l'API publique iaeasy.noschoixpourvous.com,
	 * quelques secondes a quelques minutes selon Ollama - meme pattern de declenchement
	 * en tache de fond que reindexEmbeddings() ci-dessus.
	 */
	public function reindexIaeasy(): JSONResponse {
		if (!$this->isCurrentUserAdmin()) {
			return new JSONResponse(['error' => 'forbidden'], 403);
		}

		$logPath = '/tmp/search_hub_iaeasy_index.log';
		$cmd = 'nohup php /var/www/html/custom_apps/search_hub/iaeasy_index.php > '
			. escapeshellarg($logPath) . ' 2>&1 & echo $!';

		exec($cmd, $output);
		$this->logger->info('search_hub: synchronisation iaeasy declenchee manuellement depuis le tableau de bord admin');

		return new JSONResponse(['started' => true, 'pid' => $output[0] ?? null]);
	}

	/**
	 * Synchronisation du connecteur confia_doc : reindexe depuis l'export JSON DEJA
	 * present dans le conteneur (confia_doc_export.json) - contrairement a iaeasy,
	 * ce declenchement manuel ne force PAS un nouvel export cote ConfIA (le
	 * docker cp depuis le host est un pas HOTE separe, hors de portee de PHP dans
	 * le conteneur Nextcloud) : utile pour rejouer l'indexation apres une erreur ou
	 * confirmer la fraicheur d'un export deja pousse par le cron ConfIA (03:45).
	 */
	public function reindexConfiaDoc(): JSONResponse {
		if (!$this->isCurrentUserAdmin()) {
			return new JSONResponse(['error' => 'forbidden'], 403);
		}

		$logPath = '/tmp/search_hub_confia_doc_index.log';
		$cmd = 'nohup php /var/www/html/custom_apps/search_hub/confia_doc_index.php > '
			. escapeshellarg($logPath) . ' 2>&1 & echo $!';

		exec($cmd, $output);
		$this->logger->info('search_hub: synchronisation confia_doc declenchee manuellement depuis le tableau de bord admin');

		return new JSONResponse(['started' => true, 'pid' => $output[0] ?? null]);
	}

	/**
	 * Logs LISIBLES depuis la console admin : uniquement ceux ecrits DANS le conteneur
	 * (les executions manuelles declenchees ci-dessus). Le cron quotidien du backfill
	 * ecrit lui sur le systeme de fichiers HOTE (/home/adil/search-hub-embeddings.log,
	 * cf. crontab) - invisible depuis PHP web, note explicitement plutot que tente une
	 * lecture qui echouerait silencieusement.
	 */
	public function getLogs(): JSONResponse {
		if (!$this->isCurrentUserAdmin()) {
			return new JSONResponse(['error' => 'forbidden'], 403);
		}

		$logs = [];
		$files = [
			'reindex' => ['path' => '/tmp/search_hub_reindex.log', 'label' => 'Derniere reindexation manuelle (mot-cle)'],
			'embedBackfill' => ['path' => '/tmp/search_hub_embed_backfill.log', 'label' => 'Dernier backfill manuel (embeddings)'],
			'iaeasyIndex' => ['path' => '/tmp/search_hub_iaeasy_index.log', 'label' => 'Derniere synchronisation iaeasy'],
			'confiaDocIndex' => ['path' => '/tmp/search_hub_confia_doc_index.log', 'label' => 'Derniere synchronisation confia_doc'],
		];

		foreach ($files as $key => $info) {
			if (!file_exists($info['path'])) {
				$logs[$key] = ['label' => $info['label'], 'available' => false, 'lines' => []];
				continue;
			}
			$content = (string)file_get_contents($info['path']);
			$lines = array_slice(array_filter(explode("\n", $content)), -30);
			$logs[$key] = ['label' => $info['label'], 'available' => true, 'lines' => array_values($lines)];
		}

		return new JSONResponse([
			'logs' => $logs,
			'hostOnlyLogs' => [
				'/home/adil/nextcloud-cron.log (cron BM25, 1 min)',
				'/home/adil/search-hub-embeddings.log (cron embeddings, 4h15)',
				'/home/adil/search-hub-iaeasy.log (cron connecteur iaeasy, 4h30)',
				'/home/adil/search-hub-confia-doc.log (cron connecteur confia_doc, 4h35 - cote VPS ConfIA : /var/log/confia-doc-export.log, export+push 3h45)',
			],
		]);
	}
}
