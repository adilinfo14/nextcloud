<?php
// Connecteur Recherche+ pour la documentation technique ConfIA/Lesensia
// (2026-07-14) - demande explicite de l'utilisateur ("indexer la doc technique
// RAG App Doc", PAS les donnees metier devis/factures/clients - voir page wiki
// "23 Architecture de Recherche+", section 10, pour la justification de ce
// choix de perimetre : ConfIA est multi-tenant avec des donnees clients
// sensibles, la doc technique (fichiers .md, endpoints API, schema DB) est en
// revanche une reference interne unique, sans notion de tenant.
//
// Source : PAS un appel HTTP direct (contrairement a iaeasy_index.php) - la
// doc technique de ConfIA n'expose aucune API publique (elle vit derriere
// l'auth JWT super admin, et l'exposer publiquement serait un risque de
// securite en soi : schema DB complet + toutes les routes API). A la place,
// un export JSON est genere COTE SERVEUR CONFIA (confia_doc_export.py, dans le
// conteneur "confia") puis pousse vers ce serveur via le meme lien Tailscale/
// cle SSH deja utilise par les backups ConfIA (confia-backup.sh) - voir
// /usr/local/bin/confia-doc-export-push.sh (cron 03:45 sur le VPS ConfIA) et
// le script d'orchestration cote Nextcloud qui fait le docker cp avant
// d'appeler ce script (confia-doc-index.sh, cron 04:35).
//
// Resynchronisation COMPLETE a chaque execution (meme logique qu'iaeasy) :
// volume modeste (~500 items), source de verite externe (le code ConfIA
// change), pas d'increment fiable possible sans re-fetch de toute facon.

$configPath = __DIR__ . '/search_hub_config.json';
$configDefaults = [
	'embedding' => [
		'model' => 'mxbai-embed-large',
		'chunkSize' => 6000,
		'chunkOverlap' => 500,
		'chunkSizeRetry' => 350,
	],
];

$userConfig = file_exists($configPath) ? json_decode((string)file_get_contents($configPath), true) : null;
$cfg = is_array($userConfig) ? array_replace_recursive($configDefaults, $userConfig) : $configDefaults;

$elasticHost = 'http://elasticsearch:9200';
$elasticIndex = 'nextcloud_tkonsulting';
$ollamaHost = 'http://ollama:11434';
$model = $cfg['embedding']['model'];
$vectorField = 'embedding_vector_v2';
$chunkedMarker = 'chunked_v2';
$chunkSize = (int)$cfg['embedding']['chunkSize'];
$chunkOverlap = (int)$cfg['embedding']['chunkOverlap'];
$chunkSizeRetry = (int)$cfg['embedding']['chunkSizeRetry'];
$embedOptions = ['num_batch' => 4096, 'num_ctx' => 4096];
$exportPath = __DIR__ . '/confia_doc_export.json';
$confiaLink = 'https://confia.noschoixpourvous.com/admin-v2/';

function esCurl(string $method, string $url, ?array $body = null): array {
	$ch = curl_init($url);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
	curl_setopt($ch, CURLOPT_TIMEOUT, 30);
	if ($body !== null) {
		curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
		curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
	}
	$raw = curl_exec($ch);
	$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
	curl_close($ch);
	if ($raw === false) {
		return ['error' => 'curl_failed', 'code' => $code];
	}
	$decoded = json_decode($raw, true);
	return is_array($decoded) ? $decoded : ['error' => 'bad_json', 'raw' => $raw];
}

function embed(string $ollamaHost, string $model, string $text, array $options): ?array {
	$ch = curl_init($ollamaHost . '/api/embeddings');
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_TIMEOUT, 60);
	curl_setopt($ch, CURLOPT_POST, true);
	curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['model' => $model, 'prompt' => $text, 'options' => $options]));
	curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
	$raw = curl_exec($ch);
	curl_close($ch);
	if ($raw === false) {
		return null;
	}
	$decoded = json_decode($raw, true);
	$vector = $decoded['embedding'] ?? null;
	return is_array($vector) ? $vector : null;
}

// --- Decoupage recursif (copie d'embed_backfill.php/iaeasy_index.php - garder synchronisees) ---

function hardSplitChars(string $text, int $chunkSize, int $overlap): array {
	$text = trim($text);
	$len = mb_strlen($text);
	if ($len === 0) {
		return [];
	}
	if ($len <= $chunkSize) {
		return [$text];
	}
	$pieces = [];
	$start = 0;
	$step = max(1, $chunkSize - $overlap);
	while ($start < $len) {
		$piece = trim(mb_substr($text, $start, $chunkSize));
		if (mb_strlen($piece) >= 20) {
			$pieces[] = $piece;
		}
		if ($start + $chunkSize >= $len) {
			break;
		}
		$start += $step;
	}
	return $pieces;
}

function splitParagraphIntoSentenceUnits(string $paragraph, int $chunkSize, int $overlap): array {
	$sentences = preg_split('/(?<=[.!?])\s+/u', $paragraph) ?: [$paragraph];
	$units = [];
	$buffer = '';
	foreach ($sentences as $sentence) {
		$sentence = trim($sentence);
		if ($sentence === '') {
			continue;
		}
		if (mb_strlen($sentence) > $chunkSize) {
			if ($buffer !== '') {
				$units[] = $buffer;
				$buffer = '';
			}
			foreach (hardSplitChars($sentence, $chunkSize, $overlap) as $piece) {
				$units[] = $piece;
			}
			continue;
		}
		$candidate = $buffer === '' ? $sentence : $buffer . ' ' . $sentence;
		if (mb_strlen($candidate) > $chunkSize && $buffer !== '') {
			$units[] = $buffer;
			$buffer = $sentence;
		} else {
			$buffer = $candidate;
		}
	}
	if ($buffer !== '') {
		$units[] = $buffer;
	}
	return $units;
}

function splitIntoChunks(string $text, int $chunkSize, int $overlap): array {
	$text = trim($text);
	if ($text === '') {
		return [];
	}
	if (mb_strlen($text) <= $chunkSize) {
		return [$text];
	}
	$paragraphs = preg_split('/\n\s*\n/u', $text) ?: [$text];
	$units = [];
	foreach ($paragraphs as $paragraph) {
		$paragraph = trim($paragraph);
		if ($paragraph === '') {
			continue;
		}
		if (mb_strlen($paragraph) <= $chunkSize) {
			$units[] = $paragraph;
		} else {
			foreach (splitParagraphIntoSentenceUnits($paragraph, $chunkSize, $overlap) as $unit) {
				$units[] = $unit;
			}
		}
	}
	$chunks = [];
	$current = '';
	foreach ($units as $unit) {
		$candidate = $current === '' ? $unit : $current . "\n\n" . $unit;
		if (mb_strlen($candidate) > $chunkSize && $current !== '') {
			$chunks[] = trim($current);
			$overlapText = mb_substr($current, max(0, mb_strlen($current) - $overlap));
			$current = trim($overlapText) . "\n\n" . $unit;
		} else {
			$current = $candidate;
		}
	}
	if (trim($current) !== '') {
		$chunks[] = trim($current);
	}
	return array_values(array_filter($chunks, static fn ($c) => mb_strlen($c) >= 20));
}

// --- Execution ---

if (!file_exists($exportPath)) {
	fwrite(STDERR, "Export introuvable ($exportPath) - le docker cp depuis le host a-t-il ete fait avant ce script ? Abandon.\n");
	exit(1);
}

$export = json_decode((string)file_get_contents($exportPath), true);
$items = is_array($export['items'] ?? null) ? $export['items'] : [];
echo "Documents a indexer : " . count($items) . " (export genere le " . ($export['generatedAt'] ?? '?') . ")\n";

if (empty($items)) {
	fwrite(STDERR, "Export vide - abandon sans toucher a l'index existant.\n");
	exit(1);
}

// Nettoyage complet du connecteur avant reconstruction (voir en-tete : resync totale).
esCurl('POST', $elasticHost . '/' . $elasticIndex . '/_delete_by_query?conflicts=proceed', [
	'query' => ['bool' => ['should' => [
		['term' => ['provider.keyword' => 'confia_doc']],
		['prefix' => ['parent_id' => 'confia_doc:']],
	], 'minimum_should_match' => 1]],
]);

$indexed = 0;
$passagesCreated = 0;
$errors = 0;

foreach ($items as $item) {
	$fullId = 'confia_doc:' . $item['id'];
	$title = (string)($item['title'] ?? $item['id']);
	$content = (string)($item['content'] ?? '');
	$subtype = (string)($item['subtype'] ?? 'doc');
	$now = time();

	$parentDoc = [
		'owner' => '',
		'users' => ['__all'],
		'groups' => [],
		'circles' => [],
		'provider' => 'confia_doc',
		'title' => $title,
		'content' => $content,
		'tags' => [$subtype],
		'lastModified' => $now,
		'external_link' => $confiaLink,
	];

	$putResult = esCurl('PUT', $elasticHost . '/' . $elasticIndex . '/_doc/' . rawurlencode($fullId), $parentDoc);
	if (isset($putResult['error'])) {
		$errors++;
		echo "ERREUR index parent $fullId : " . json_encode($putResult) . "\n";
		continue;
	}
	$indexed++;

	$textChunks = splitIntoChunks($content, $chunkSize, $chunkOverlap);
	if (empty($textChunks)) {
		continue;
	}

	foreach ($textChunks as $i => $chunkText) {
		$embedInput = $title . "\n" . $chunkText;
		$vector = embed($ollamaHost, $model, mb_substr($embedInput, 0, $chunkSize + 200), $embedOptions);
		if ($vector === null) {
			$vector = embed($ollamaHost, $model, mb_substr($title . "\n" . mb_substr($chunkText, 0, $chunkSizeRetry), 0, $chunkSizeRetry + 200), $embedOptions);
		}
		if ($vector === null) {
			$errors++;
			echo "ERREUR embed passage $fullId:p$i\n";
			continue;
		}

		$chunkDoc = [
			'owner' => '',
			'users' => ['__all'],
			'groups' => [],
			'circles' => [],
			'parent_id' => $fullId,
			'chunk_index' => $i,
			'chunk_text' => $chunkText,
			$vectorField => $vector,
		];
		$putChunk = esCurl('PUT', $elasticHost . '/' . $elasticIndex . '/_doc/' . rawurlencode($fullId . ':p' . $i), $chunkDoc);
		if (isset($putChunk['error'])) {
			$errors++;
			echo "ERREUR index passage $fullId:p$i : " . json_encode($putChunk) . "\n";
			continue;
		}
		$passagesCreated++;
	}

	esCurl('POST', $elasticHost . '/' . $elasticIndex . '/_update/' . rawurlencode($fullId), ['doc' => [$chunkedMarker => true]]);
}

echo "Termine. documents=$indexed passages=$passagesCreated erreurs=$errors\n";

file_put_contents('/var/www/html/custom_apps/search_hub/confia_doc_index_status.json', json_encode([
	'finishedAt' => time(),
	'documentsIndexed' => $indexed,
	'passagesCreated' => $passagesCreated,
	'errors' => $errors,
]));
