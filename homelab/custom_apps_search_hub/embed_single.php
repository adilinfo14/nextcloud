<?php
// Reindexation CIBLEE (recherche par sens) pour un ou plusieurs documents precis,
// declenchee manuellement depuis la console admin (StatusController::reindexDocument())
// - PAS le cron incremental (embed_backfill.php), qui ne traite QUE les documents
// jamais encore marques "chunked_v2" et applique les filtres demo/image/tableur.
//
// Volontairement DIFFERENT du backfill sur deux points, les deux devoirs du fait que
// l'admin cible ICI un document precis, en connaissance de cause :
// 1. Force le retraitement meme si deja "chunked_v2" (vrai "reindex", pas juste
//    "backfill des nouveaux") - supprime d'abord les anciens passages pour eviter
//    d'en laisser des perimes si le decoupage change entre-temps.
// 2. Ignore les filtres demo/image/tableur du backfill : si l'admin demande
//    explicitement ce document par son titre, il sait ce qu'il fait. Seul le garde-fou
//    MIN_CONTENT_LEN reste actif (jamais indexer un vecteur sans contenu reel).
//
// Argument CLI unique : un terme de recherche sur le titre (correspond a plusieurs
// documents si le terme est ambigu - tous les documents correspondants sont traites).

$configPath = __DIR__ . '/search_hub_config.json';
$configDefaults = [
	'embedding' => [
		'model' => 'mxbai-embed-large',
		'chunkSize' => 6000,
		'chunkOverlap' => 500,
		'chunkSizeRetry' => 350,
		'minContentLen' => 20,
		'maxChunksPerDoc' => 200,
	],
];
$userConfig = file_exists($configPath) ? json_decode((string)file_get_contents($configPath), true) : null;
$cfg = is_array($userConfig) ? array_replace_recursive($configDefaults, $userConfig) : $configDefaults;
$embeddingCfg = $cfg['embedding'];

$elasticHost = 'http://elasticsearch:9200';
$elasticIndex = 'nextcloud_tkonsulting';
$ollamaHost = 'http://ollama:11434';
$model = $embeddingCfg['model'];
$vectorField = 'embedding_vector_v2';
$chunkedMarker = 'chunked_v2';
$chunkSize = (int)$embeddingCfg['chunkSize'];
$chunkOverlap = (int)$embeddingCfg['chunkOverlap'];
$chunkSizeRetry = (int)$embeddingCfg['chunkSizeRetry'];
$embedOptions = ['num_batch' => 4096, 'num_ctx' => 4096];
$minContentLen = (int)$embeddingCfg['minContentLen'];
$maxChunksPerDoc = (int)$embeddingCfg['maxChunksPerDoc'];

$query = trim((string)($argv[1] ?? ''));
if ($query === '') {
	fwrite(STDERR, "Usage: php embed_single.php \"terme de recherche sur le titre\"\n");
	exit(1);
}

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

function embed(string $ollamaHost, string $model, string $text, array $options = []): ?array {
	$payload = ['model' => $model, 'prompt' => $text];
	if (!empty($options)) {
		$payload['options'] = $options;
	}
	$ch = curl_init($ollamaHost . '/api/embeddings');
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_TIMEOUT, 60);
	curl_setopt($ch, CURLOPT_POST, true);
	curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
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

// "should" combine un match classique (titres avec espaces) et un wildcard sur le
// terme en minuscule (titres sans espace, ex: "ApprendreleMachineLearning...pdf",
// un seul token pour l'analyseur standard - un match exact ne trouverait jamais de
// sous-chaine dedans).
$searchResult = esCurl('POST', $elasticHost . '/' . $elasticIndex . '/_search', [
	'size' => 30,
	'_source' => ['title', 'content', 'parts', 'owner', 'users', 'groups', 'circles'],
	'query' => [
		'bool' => [
			'should' => [
				['match' => ['title' => $query]],
				['wildcard' => ['title' => ['value' => '*' . mb_strtolower($query) . '*']]],
			],
			'minimum_should_match' => 1,
			'must_not' => [['exists' => ['field' => 'parent_id']]],
		],
	],
]);

$hits = $searchResult['hits']['hits'] ?? [];
$found = count($hits);
echo "Recherche \"$query\" : $found document(s) trouve(s)\n";

$documentsChunked = 0;
$passagesCreated = 0;
$skipped = 0;
$errors = 0;

foreach ($hits as $hit) {
	$id = $hit['_id'];
	$source = $hit['_source'] ?? [];
	$title = (string)($source['title'] ?? '');
	$content = (string)($source['content'] ?? '');
	$parts = $source['parts'] ?? [];
	$partsText = is_array($parts) ? implode(' ', array_filter($parts, 'is_string')) : '';
	$realContent = trim($content . ' ' . $partsText);

	echo "- $title ($id)\n";

	if (mb_strlen($realContent) < $minContentLen) {
		echo "  ignore : pas de contenu reel exploitable\n";
		$skipped++;
		continue;
	}

	// Supprime les anciens passages avant de reecrire, pour un vrai "reindex" (pas
	// un simple ajout qui laisserait des passages perimes si le decoupage a change).
	esCurl('POST', $elasticHost . '/' . $elasticIndex . '/_delete_by_query', [
		'query' => ['term' => ['parent_id' => $id]],
	]);

	$textChunks = splitIntoChunks($realContent, $chunkSize, $chunkOverlap);
	if (count($textChunks) > $maxChunksPerDoc) {
		$totalChunks = count($textChunks);
		$sampled = [];
		for ($k = 0; $k < $maxChunksPerDoc; $k++) {
			$sampled[] = $textChunks[(int)floor($k * ($totalChunks - 1) / max(1, $maxChunksPerDoc - 1))];
		}
		$textChunks = array_values(array_unique($sampled));
	}

	$access = [
		'owner' => $source['owner'] ?? null,
		'users' => $source['users'] ?? [],
		'groups' => $source['groups'] ?? [],
		'circles' => $source['circles'] ?? [],
	];

	$createdForThisDoc = 0;
	$docHadFailure = false;
	foreach ($textChunks as $i => $chunkText) {
		$embedInput = $title . "\n" . $chunkText;
		$vector = embed($ollamaHost, $model, mb_substr($embedInput, 0, $chunkSize + 200), $embedOptions);
		if ($vector === null) {
			$vector = embed($ollamaHost, $model, mb_substr($title . "\n" . mb_substr($chunkText, 0, $chunkSizeRetry), 0, $chunkSizeRetry + 200), $embedOptions);
		}
		if ($vector === null) {
			echo "  ERREUR embed passage p$i\n";
			$docHadFailure = true;
			continue;
		}
		$chunkDoc = array_merge($access, [
			'parent_id' => $id,
			'chunk_index' => $i,
			'chunk_text' => $chunkText,
			$vectorField => $vector,
		]);
		$putResult = esCurl('PUT', $elasticHost . '/' . $elasticIndex . '/_doc/' . rawurlencode($id . ':p' . $i), $chunkDoc);
		if (isset($putResult['error'])) {
			echo "  ERREUR index passage p$i : " . json_encode($putResult) . "\n";
			$docHadFailure = true;
			continue;
		}
		$createdForThisDoc++;
		$passagesCreated++;
	}

	if ($createdForThisDoc > 0) {
		$markResult = esCurl('POST', $elasticHost . '/' . $elasticIndex . '/_update/' . rawurlencode($id), [
			'doc' => [$chunkedMarker => true],
		]);
		if (isset($markResult['error'])) {
			echo "  ERREUR marquage $chunkedMarker : " . json_encode($markResult) . "\n";
			$errors++;
		} else {
			$documentsChunked++;
			echo "  OK : $createdForThisDoc passage(s) cree(s)\n";
		}
	} else {
		$skipped++;
	}
	if ($docHadFailure) {
		$errors++;
	}
}

echo "Termine. trouves=$found documents=$documentsChunked passages=$passagesCreated skipped=$skipped errors=$errors\n";
