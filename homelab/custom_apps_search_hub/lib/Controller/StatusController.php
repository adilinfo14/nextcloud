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

		if ($elasticHost !== '' && $elasticIndex !== '') {
			$countData = $this->esInternalRequest('GET', rtrim($elasticHost, '/') . '/' . $elasticIndex . '/_count');
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
		if ($esOnline) {
			$passageCount = $this->esInternalRequest(
				'POST',
				rtrim($elasticHost, '/') . '/' . $elasticIndex . '/_count',
				['query' => ['exists' => ['field' => 'parent_id']]]
			);
			$totalPassages = (int)($passageCount['count'] ?? 0);

			$chunkedCount = $this->esInternalRequest(
				'POST',
				rtrim($elasticHost, '/') . '/' . $elasticIndex . '/_count',
				['query' => ['exists' => ['field' => 'chunked_v2']]]
			);
			$totalChunkedDocuments = (int)($chunkedCount['count'] ?? 0);
		}

		$statusPath = '/var/www/html/custom_apps/search_hub/embed_backfill_status.json';
		$lastRun = null;
		if (file_exists($statusPath)) {
			$decoded = json_decode((string)file_get_contents($statusPath), true);
			if (is_array($decoded)) {
				$lastRun = $decoded;
			}
		}

		return [
			'totalPassages' => $totalPassages,
			'totalChunkedDocuments' => $totalChunkedDocuments,
			'lastRun' => $lastRun,
		];
	}

	/**
	 * Rend explicite la notion de "connecteur" (une source de contenu indexee dans
	 * Recherche+) - demande utilisateur du 2026-07-14 : montrer clairement ce qui EST
	 * connecte aujourd'hui, et ce qui pourrait l'etre (ex: iaeasy.noschoixpourvous.com,
	 * une application separee sur le meme serveur, actuellement AUCUN connecteur -
	 * ni Fichiers/Wiki/Deck, tous bases sur l'API Nextcloud, ne s'y applique).
	 */
	private function getConnectors(): array {
		return [
			'active' => [
				['id' => 'files', 'label' => 'Fichiers', 'type' => 'Application Nextcloud native (IFullTextSearchProvider)'],
				['id' => 'collectives', 'label' => 'Wiki (Collectives)', 'type' => 'Connecteur custom (fulltextsearch_collectives, developpe pour ce projet)'],
				['id' => 'deck', 'label' => 'Deck', 'type' => 'Application Nextcloud native (IFullTextSearchProvider)'],
			],
			'proposed' => [
				[
					'id' => 'iaeasy',
					'label' => 'iaeasy.noschoixpourvous.com',
					'reason' => 'Application separee (FastAPI/Python), pas une app Nextcloud - necessiterait un connecteur d\'un genre different (indexation depuis l\'exterieur, pas via IFullTextSearchProvider).',
				],
			],
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
}
