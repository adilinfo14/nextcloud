<?php
// Backfill des embeddings (nomic-embed-text, 768 dim) pour Recherche+ - mode
// "Recherche par sens". Script autonome (pas de bootstrap Nextcloud necessaire).
//
// v2 (2026-07-14) : passage au DECOUPAGE EN PASSAGES (chunking) plutot qu'un seul
// vecteur par document entier. Raison : un vecteur unique calcule sur les 800
// premiers caracteres d'un document long ne represente que son debut - si le
// passage pertinent est au milieu ou a la fin, la recherche par sens le rate. Chaque
// document REEL et ELIGIBLE est maintenant decoupe en passages de ~900 caracteres
// (chevauchement 150 car.) ; CHAQUE passage devient un document Elasticsearch
// LEGER a part entiere (id "<providerId>:<documentId>:p<N>"), avec son propre
// vecteur et une COPIE des champs d'acces du document parent (owner/users/groups/
// circles) pour que le filtre d'acces de searchNeural() fonctionne identiquement
// passage par passage. Le document original garde son "content" complet (utilise
// par la recherche mot-cle et la previsualisation) mais N'A PLUS de champ
// embedding_vector - la recherche par sens ne cible plus que les passages, dedupliques
// par document parent a la lecture (voir SearchController::searchNeural()).
//
// Incremental par construction : ne traite que les documents pas encore marques
// "chunked" (voir la requete scroll plus bas) - a re-executer periodiquement (cron)
// pour rattraper les nouveaux documents indexes depuis.
//
// Piege important (trouve en prod le 2026-07-13) : nomic-embed-text plante avec un
// panic Ollama ("caching disabled but unable to fit entire input in a batch") si le
// texte envoye depasse la taille de batch du modele (~512 tokens). D'ou CHUNK_SIZE
// volontairement conservateur (900 car.) + un retry automatique avec un texte plus
// court en cas d'echec, plutot que de perdre le passage silencieusement.
//
// 2e piege trouve le 2026-07-14 : un dossier (ou fichier quasi vide) n'a PAS de
// "content" mais A un titre ("Notes", "Photos", "Mon Iphone"...) - embarquer
// seulement ce titre produit un vecteur tres court/generique qui se retrouve, par
// similarite cosinus, artificiellement "proche" de PRESQUE TOUTES les requetes.
// D'ou l'exigence d'un contenu REEL minimal (MIN_CONTENT_LEN), independamment du
// titre.
//
// 3e piege trouve le 2026-07-14 (meme session) : files_fulltextsearch_tesseract fait
// tourner l'OCR sur TOUTES les images (pas seulement les scans de documents) - une
// vraie photo de vacances produit un "content" de bruit OCR (ex: "ES PE hn\n\nere =")
// qui passe le filtre MIN_CONTENT_LEN mais n'a evidemment aucun sens. D'ou
// l'exclusion explicite des extensions image.
//
// 4e piege trouve le 2026-07-14 (signale par l'utilisateur) : un document au contenu
// tout a fait coherent (ex: "Modeles/Menu.odt", un menu de restaurant complet) peut
// quand meme ressortir sans rapport - pas un bug d'embedding mais du bruit
// STRUCTUREL : "Modeles/" est le pack de gabarits Office livre par defaut avec toute
// installation Nextcloud, jamais du vrai contenu utilisateur. D'ou l'exclusion des
// dossiers/cartes de demo connus du skeleton Nextcloud (deja identifies comme tels
// ailleurs dans ce projet).

$elasticHost = 'http://elasticsearch:9200';
$elasticIndex = 'nextcloud_tkonsulting';
$ollamaHost = 'http://ollama:11434';
$model = 'nomic-embed-text';
$batchSize = 20;
$chunkSize = 900;
$chunkOverlap = 150;
$chunkSizeRetry = 350;
$minContentLen = 20;
// Plafond decouvert en prod le 2026-07-14 : un vrai livre PDF de 2.8 Mo ("Intelligence
// artificielle" de John Paul Mueller) a produit 1182 passages a lui seul, ralentissant
// le backfill de facon disproportionnee pour un seul document. Au-dela de ce plafond,
// on repartit les chunks UNIFORMEMENT sur tout le document (pas juste le debut) pour
// garder une couverture representative plutot que de tronquer bêtement au debut.
$maxChunksPerDoc = 40;
$imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'heic', 'heif', 'webp', 'tiff', 'tif'];
$demoPathPrefixes = ['Modèles/', 'Templates/', 'Notes/'];
$demoExactTitles = [
	'Documents/Example.md',
	// Cartes du tableau Deck de demo "Bienvenue dans Nextcloud Deck !" (board id=1,
	// verifie en base) - meme categorie de bruit que Modeles/Notes ci-dessus.
	'1. Ouvrez pour en apprendre davantage sur les tableaux et les cartes',
	'2. Faites glisser les cartes vers la gauche et la droite, vers le haut et le bas',
	'Créez votre première carte !',
	'3. Appliquer un formatage riche et lier le contenu',
	'4. Partagez, commentez et collaborez !',
];

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

function embed(string $ollamaHost, string $model, string $text): ?array {
	$ch = curl_init($ollamaHost . '/api/embeddings');
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_TIMEOUT, 60);
	curl_setopt($ch, CURLOPT_POST, true);
	curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['model' => $model, 'prompt' => $text]));
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

/** @return string[] */
function splitIntoChunks(string $text, int $chunkSize, int $overlap): array {
	$text = trim($text);
	$len = mb_strlen($text);
	if ($len === 0) {
		return [];
	}
	if ($len <= $chunkSize) {
		return [$text];
	}

	$chunks = [];
	$start = 0;
	$step = max(1, $chunkSize - $overlap);
	while ($start < $len) {
		$chunk = trim(mb_substr($text, $start, $chunkSize));
		if (mb_strlen($chunk) >= 20) {
			$chunks[] = $chunk;
		}
		if ($start + $chunkSize >= $len) {
			break;
		}
		$start += $step;
	}
	return $chunks;
}

$scrollUrl = $elasticHost . '/' . $elasticIndex . '/_search?scroll=5m';
$result = esCurl('POST', $scrollUrl, [
	'size' => $batchSize,
	'_source' => ['title', 'content', 'parts', 'owner', 'users', 'groups', 'circles'],
	'query' => [
		'bool' => [
			'must_not' => [
				['exists' => ['field' => 'chunked']],
				['exists' => ['field' => 'parent_id']], // ne jamais re-traiter un passage lui-meme
			],
		],
	],
]);

if (isset($result['error']) && !isset($result['_scroll_id'])) {
	fwrite(STDERR, "Erreur ES initiale : " . json_encode($result) . "\n");
	exit(1);
}

$scrollId = $result['_scroll_id'] ?? null;
$total = $result['hits']['total']['value'] ?? 0;
$processed = 0;
$documentsChunked = 0;
$passagesCreated = 0;
$skipped = 0;
$errors = 0;

echo "A traiter : $total\n";

while (true) {
	$hits = $result['hits']['hits'] ?? [];
	if (empty($hits)) {
		break;
	}

	foreach ($hits as $hit) {
		$processed++;
		$id = $hit['_id'];
		$source = $hit['_source'] ?? [];
		$title = (string)($source['title'] ?? '');
		$content = (string)($source['content'] ?? '');
		$parts = $source['parts'] ?? [];
		$partsText = is_array($parts) ? implode(' ', array_filter($parts, 'is_string')) : '';

		$isDemo = in_array($title, $demoExactTitles, true);
		if (!$isDemo) {
			foreach ($demoPathPrefixes as $prefix) {
				if (str_starts_with($title, $prefix)) {
					$isDemo = true;
					break;
				}
			}
		}
		if ($isDemo) {
			$skipped++;
			continue;
		}

		$ext = strtolower(pathinfo($title, PATHINFO_EXTENSION));
		if (in_array($ext, $imageExtensions, true)) {
			$skipped++;
			continue;
		}

		$realContent = trim($content . ' ' . $partsText);
		if (mb_strlen($realContent) < $minContentLen) {
			// Pas de contenu reel (dossier, fichier vide/quasi-vide) : aucun passage cree,
			// ce document n'apparaitra jamais en recherche par sens - correct, il n'a
			// aucun "sens" a comparer.
			$skipped++;
			continue;
		}

		$textChunks = splitIntoChunks($realContent, $chunkSize, $chunkOverlap);
		if (empty($textChunks)) {
			$skipped++;
			continue;
		}
		if (count($textChunks) > $maxChunksPerDoc) {
			// Echantillonnage UNIFORME sur tout le document (pas juste les $maxChunksPerDoc
			// premiers) : garde une couverture representative du debut a la fin plutot que
			// de ne jamais voir la deuxieme moitie d'un document tres long.
			$total = count($textChunks);
			$sampled = [];
			for ($k = 0; $k < $maxChunksPerDoc; $k++) {
				$sampled[] = $textChunks[(int)floor($k * ($total - 1) / max(1, $maxChunksPerDoc - 1))];
			}
			$textChunks = array_values(array_unique($sampled));
		}

		$access = [
			'owner' => $source['owner'] ?? null,
			'users' => $source['users'] ?? [],
			'groups' => $source['groups'] ?? [],
			'circles' => $source['circles'] ?? [],
		];

		$chunkFailure = false;
		$createdForThisDoc = 0;
		foreach ($textChunks as $i => $chunkText) {
			// Le titre est prefixe a chaque passage : donne du contexte au modele
			// d'embedding (sans lui, un passage isole du milieu d'un document perd le
			// sujet general) et permet aussi de matcher directement sur le titre seul.
			$embedInput = 'search_document: ' . $title . "\n" . $chunkText;

			$vector = embed($ollamaHost, $model, mb_substr($embedInput, 0, $chunkSize + 200));
			if ($vector === null) {
				$vector = embed($ollamaHost, $model, mb_substr('search_document: ' . $title . "\n" . mb_substr($chunkText, 0, $chunkSizeRetry), 0, $chunkSizeRetry + 200));
			}
			if ($vector === null) {
				$chunkFailure = true;
				echo "ERREUR embed passage $id:p$i\n";
				continue;
			}

			$chunkDoc = array_merge($access, [
				'parent_id' => $id,
				'chunk_index' => $i,
				'chunk_text' => $chunkText,
				'embedding_vector' => $vector,
			]);
			$putResult = esCurl('PUT', $elasticHost . '/' . $elasticIndex . '/_doc/' . rawurlencode($id . ':p' . $i), $chunkDoc);
			if (isset($putResult['error'])) {
				$chunkFailure = true;
				echo "ERREUR index passage $id:p$i : " . json_encode($putResult) . "\n";
				continue;
			}
			$createdForThisDoc++;
			$passagesCreated++;
		}

		if ($createdForThisDoc > 0) {
			// Marque le document parent comme traite (evite de le retraiter au prochain
			// passage du cron) et retire un eventuel embedding_vector residuel de
			// l'ancienne approche "un seul vecteur par document" (v1).
			$markResult = esCurl('POST', $elasticHost . '/' . $elasticIndex . '/_update/' . rawurlencode($id), [
				'script' => [
					'lang' => 'painless',
					'source' => "ctx._source.chunked = true; if (ctx._source.containsKey('embedding_vector')) { ctx._source.remove('embedding_vector'); }",
				],
			]);
			if (isset($markResult['error'])) {
				$errors++;
				echo "ERREUR marquage chunked $id : " . json_encode($markResult) . "\n";
			} else {
				$documentsChunked++;
			}
		}
		if ($chunkFailure) {
			$errors++;
		}
	}

	echo "Progression : $processed / $total (documents=$documentsChunked, passages=$passagesCreated, skipped=$skipped, errors=$errors)\n";

	$result = esCurl('POST', $elasticHost . '/_search/scroll', [
		'scroll' => '5m',
		'scroll_id' => $scrollId,
	]);
	$scrollId = $result['_scroll_id'] ?? $scrollId;
}

echo "Termine. processed=$processed documents=$documentsChunked passages=$passagesCreated skipped=$skipped errors=$errors\n";
