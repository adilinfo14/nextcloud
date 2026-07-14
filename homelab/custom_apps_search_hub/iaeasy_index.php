<?php
// Connecteur Recherche+ pour iaeasy.noschoixpourvous.com (2026-07-14) - application
// separee (FastAPI/Python), PAS une app Nextcloud : aucun IFullTextSearchProvider ne
// s'applique (voir page wiki "23 Architecture de Recherche+", section Connecteurs).
// Ce script indexe directement dans le MEME index Elasticsearch que Fichiers/Deck/
// Collectives, en reproduisant le meme schema de document (owner/users/groups/circles,
// provider, title, content, tags, lastModified) - iaeasy etant 100% public et sans
// notion d'ACL propre, "users" est mis a ["__all"] (convention deja utilisee par
// SearchController::buildAccessFilterShould() pour "visible par tout utilisateur
// Nextcloud connecte").
//
// 7 sources retenues sur les endpoints publics reels de l'API iaeasy (verifiees en
// direct via curl le 2026-07-14, PAS supposees a partir d'un ancien echange) :
// catalogue (35 modeles), agents/templates (20 gabarits), agents/composants
// (13 briques), training/scenarios (~3 scenarios), glossaire (83 termes), metiers
// (16 fiches), securite (10 risques OWASP LLM Top 10), videos (~10 videos).
// DELIBEREMENT exclus : strategie-test (742 Ko, ~1676 cas de test quasi-repetitifs,
// plus proche d'un cahier QA que d'un contenu a rechercher semantiquement - aurait
// dwarfe tout le reste de l'index) et avis (avis visiteurs, contenu dynamique/tiny,
// pas une reference stable).
//
// Resynchronisation COMPLETE a chaque execution (pas d'increment) : le volume est
// petit (~190 items), le cout d'un re-fetch+re-embed total est negligeable, et ca
// evite toute derive/orphelin si le contenu source change cote iaeasy (titre modifie,
// item supprime...). Supprime d'abord tout ce qui porte provider=iaeasy (parents ET
// passages), puis reconstruit de zero.

$configPath = __DIR__ . '/search_hub_config.json';
$configDefaults = [
	'embedding' => [
		'model' => 'mxbai-embed-large',
		'chunkSize' => 6000,
		'chunkOverlap' => 500,
		'chunkSizeRetry' => 350,
	],
	'iaeasy' => [
		'apiBase' => 'https://iaeasy.noschoixpourvous.com/api',
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
$apiBase = rtrim($cfg['iaeasy']['apiBase'], '/');

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

function fetchJson(string $url): ?array {
	$ch = curl_init($url);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_TIMEOUT, 20);
	$raw = curl_exec($ch);
	$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
	curl_close($ch);
	if ($raw === false || $code >= 400) {
		return null;
	}
	$decoded = json_decode($raw, true);
	return is_array($decoded) ? $decoded : null;
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

function slugify(string $text): string {
	$slug = mb_strtolower($text);
	$slug = preg_replace('/[^a-z0-9]+/u', '-', $slug) ?? $slug;
	return trim($slug, '-');
}

// --- Decoupage recursif (copie de embed_backfill.php - meme logique, garder synchronisees) ---

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

// --- Recuperation des 7 sources et mise en forme en documents "provider=iaeasy" ---

/** @return array{id: string, title: string, content: string, link: string, subtype: string}[] */
function buildDocuments(string $apiBase): array {
	$docs = [];

	$catalogue = fetchJson($apiBase . '/catalogue') ?? [];
	foreach ($catalogue as $m) {
		$exemples = array_map(static fn ($e) => ($e['label'] ?? '') . ' : ' . ($e['input'] ?? ''), $m['cas_usage']['exemples'] ?? []);
		$content = implode("\n\n", array_filter([
			$m['famille'] ?? '', $m['secteur'] ?? '', $m['taille'] ?? '',
			$m['description_pedagogique'] ?? '',
			$m['cas_usage']['enonce'] ?? '',
			implode("\n", $exemples),
			implode("\n", $m['cas_usage']['idees_usage'] ?? []),
		]));
		$docs[] = ['id' => 'catalogue:' . $m['id'], 'title' => (string)($m['nom'] ?? $m['id']), 'content' => $content, 'link' => '/catalogue', 'subtype' => 'modele'];
	}

	$templates = fetchJson($apiBase . '/agents/templates') ?? [];
	foreach ($templates as $t) {
		$content = implode("\n\n", array_filter([
			$t['categorie'] ?? '', $t['description'] ?? '',
			"Avantages : " . implode('; ', $t['avantages'] ?? []),
			"Inconvenients : " . implode('; ', $t['inconvenients'] ?? []),
		]));
		$docs[] = ['id' => 'templates:' . $t['id'], 'title' => (string)($t['titre'] ?? $t['id']), 'content' => $content, 'link' => '/constructeur', 'subtype' => 'gabarit'];
	}

	$composants = fetchJson($apiBase . '/agents/composants') ?? [];
	foreach ($composants as $c) {
		$content = implode("\n\n", array_filter([$c['categorie'] ?? '', $c['description'] ?? '', $c['entree_sortie'] ?? '']));
		$docs[] = ['id' => 'composants:' . $c['id'], 'title' => (string)($c['titre'] ?? $c['id']), 'content' => $content, 'link' => '/constructeur', 'subtype' => 'brique'];
	}

	$scenarios = fetchJson($apiBase . '/training/scenarios') ?? [];
	foreach ($scenarios as $s) {
		$content = implode("\n\n", array_filter([$s['famille_algo'] ?? '', $s['modele_base'] ?? '', $s['cas_usage'] ?? '']));
		$docs[] = ['id' => 'scenarios:' . $s['id'], 'title' => (string)($s['titre'] ?? $s['id']), 'content' => $content, 'link' => '/entrainement', 'subtype' => 'scenario'];
	}

	$glossaire = fetchJson($apiBase . '/glossaire') ?? [];
	foreach ($glossaire as $g) {
		$terme = (string)($g['terme'] ?? '');
		if ($terme === '') {
			continue;
		}
		$content = implode("\n\n", array_filter([$g['categorie'] ?? '', $g['definition_simple'] ?? '', 'Ou le voir : ' . ($g['ou_le_voir'] ?? '')]));
		$docs[] = ['id' => 'glossaire:' . slugify($terme), 'title' => $terme, 'content' => $content, 'link' => '/glossaire', 'subtype' => 'glossaire'];
	}

	$metiers = fetchJson($apiBase . '/metiers') ?? [];
	foreach ($metiers as $m) {
		$casUsage = array_map(static fn ($c) => ($c['titre'] ?? '') . ' : ' . ($c['description'] ?? ''), $m['cas_usage'] ?? []);
		$content = implode("\n\n", array_filter([$m['secteur'] ?? '', $m['description'] ?? '', implode("\n", $casUsage)]));
		$docs[] = ['id' => 'metiers:' . $m['id'], 'title' => (string)($m['titre'] ?? $m['id']), 'content' => $content, 'link' => '/metiers', 'subtype' => 'metier'];
	}

	$securite = fetchJson($apiBase . '/securite');
	foreach (($securite['risques'] ?? []) as $r) {
		$content = implode("\n\n", array_filter([$r['risque'] ?? '', $r['exemple_concret'] ?? '']));
		$docs[] = ['id' => 'securite:' . $r['id'], 'title' => (string)($r['titre'] ?? $r['id']), 'content' => $content, 'link' => '/securite', 'subtype' => 'securite'];
	}

	$videos = fetchJson($apiBase . '/videos') ?? [];
	foreach ($videos as $v) {
		$content = (string)($v['description'] ?? '');
		$docs[] = ['id' => 'videos:' . $v['id'], 'title' => (string)($v['titre'] ?? $v['id']), 'content' => $content, 'link' => '/videos', 'subtype' => 'video'];
	}

	return $docs;
}

// --- Execution ---

echo "Recuperation des sources iaeasy ($apiBase)...\n";
$documents = buildDocuments($apiBase);
echo "Documents a indexer : " . count($documents) . "\n";

if (empty($documents)) {
	fwrite(STDERR, "Aucun document recupere - API iaeasy injoignable ? Abandon sans toucher a l'index existant.\n");
	exit(1);
}

// Nettoyage complet du connecteur avant reconstruction (voir en-tete : resync totale).
esCurl('POST', $elasticHost . '/' . $elasticIndex . '/_delete_by_query?conflicts=proceed', [
	'query' => ['bool' => ['should' => [
		['term' => ['provider.keyword' => 'iaeasy']],
		['prefix' => ['parent_id' => 'iaeasy:']],
	], 'minimum_should_match' => 1]],
]);

$indexed = 0;
$passagesCreated = 0;
$errors = 0;

foreach ($documents as $doc) {
	$fullId = 'iaeasy:' . $doc['id'];
	$now = time();

	$parentDoc = [
		'owner' => '',
		'users' => ['__all'],
		'groups' => [],
		'circles' => [],
		'provider' => 'iaeasy',
		'title' => $doc['title'],
		'content' => $doc['content'],
		'tags' => [$doc['subtype']],
		'lastModified' => $now,
		'iaeasy_link' => 'https://iaeasy.noschoixpourvous.com' . $doc['link'],
	];

	$putResult = esCurl('PUT', $elasticHost . '/' . $elasticIndex . '/_doc/' . rawurlencode($fullId), $parentDoc);
	if (isset($putResult['error'])) {
		$errors++;
		echo "ERREUR index parent $fullId : " . json_encode($putResult) . "\n";
		continue;
	}
	$indexed++;

	$textChunks = splitIntoChunks($doc['content'], $chunkSize, $chunkOverlap);
	if (empty($textChunks)) {
		continue;
	}

	foreach ($textChunks as $i => $chunkText) {
		$embedInput = $doc['title'] . "\n" . $chunkText;
		$vector = embed($ollamaHost, $model, mb_substr($embedInput, 0, $chunkSize + 200), $embedOptions);
		if ($vector === null) {
			$vector = embed($ollamaHost, $model, mb_substr($doc['title'] . "\n" . mb_substr($chunkText, 0, $chunkSizeRetry), 0, $chunkSizeRetry + 200), $embedOptions);
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

	// Marque le parent comme deja traite pour la recherche par sens (meme convention
	// que embed_backfill.php - permet a StatusController de le compter dans
	// providersChunked sans logique dediee).
	esCurl('POST', $elasticHost . '/' . $elasticIndex . '/_update/' . rawurlencode($fullId), ['doc' => [$chunkedMarker => true]]);
}

echo "Termine. documents=$indexed passages=$passagesCreated erreurs=$errors\n";

file_put_contents('/var/www/html/custom_apps/search_hub/iaeasy_index_status.json', json_encode([
	'finishedAt' => time(),
	'documentsIndexed' => $indexed,
	'passagesCreated' => $passagesCreated,
	'errors' => $errors,
]));
