<?php
// Backfill des embeddings pour Recherche+ - mode "Recherche par sens". Script
// autonome (pas de bootstrap Nextcloud necessaire).
//
// v3 (2026-07-14, meme journee que v2) : deux changements de fond par rapport a v2,
// tous deux documentes en detail dans la page wiki "23 Architecture de Recherche+" :
//
// 1. MODELE D'EMBEDDING : nomic-embed-text (768 dim) -> mxbai-embed-large (1024 dim).
//    Raison : mxbai-embed-large est etat de l'art pour sa categorie sur le benchmark
//    MTEB (verifie via recherche externe, pas suppose), deja disponible sur l'Ollama de
//    ce serveur, et empiriquement plus rapide ET plus robuste ici (encaisse un texte de
//    12 000 caracteres SANS meme avoir besoin de num_batch, contrairement a
//    nomic-embed-text qui plantait des ~800-1500 caracteres). Convention de prefixe
//    DIFFERENTE et importante a respecter (verifiee via la doc Ollama officielle, pas
//    devinee) : les PASSAGES/documents s'embarquent SANS prefixe, seule la REQUETE
//    utilisateur recoit le prefixe "Represent this sentence for searching relevant
//    passages: " (voir SearchController::embedText()). Champ Elasticsearch renomme
//    embedding_vector -> embedding_vector_v2 (dims 768 vs 1024 : Elasticsearch refuse
//    de changer les dims d'un champ dense_vector existant, meme vide - verifie
//    directement, pas suppose).
//
// 2. DECOUPAGE : passage d'un decoupage BRUT au nombre de caracteres (risque de couper
//    en plein milieu d'une phrase ou d'un mot) a un decoupage RECURSIF qui respecte les
//    frontieres naturelles du texte (paragraphes, puis phrases si un paragraphe est
//    trop long, puis un decoupage brut en tout dernier recours si une "phrase" seule
//    depasse deja la taille cible - ex: un tableau sans ponctuation). Meme philosophie
//    que le RecursiveCharacterTextSplitter de LangChain, reimplementee en PHP (pas de
//    nouvelle dependance) plutot qu'ajouter un outil/service externe.
//
// Incremental par construction : ne traite que les documents pas encore marques
// "chunked_v2" (voir la requete scroll plus bas) - a re-executer periodiquement (cron)
// pour rattraper les nouveaux documents indexes depuis.
//
// Piege important (trouve en prod le 2026-07-13, VRAIE cause identifiee le 2026-07-14) :
// nomic-embed-text plantait avec un panic Ollama ("caching disabled but unable to fit
// entire input in a batch") des que le texte depassait la taille de BATCH par defaut du
// serveur d'inference (512), PAS une vraie limite du modele - resolu en passant
// options.num_batch=4096 a l'appel. mxbai-embed-large n'a pas montre ce probleme dans
// les tests menes (jusqu'a 12 000 caracteres), mais num_batch est laisse actif par
// prudence (n'a aucun cout observe).
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
$model = 'mxbai-embed-large';
$vectorField = 'embedding_vector_v2';
$chunkedMarker = 'chunked_v2';
$batchSize = 20;
$chunkSize = 6000;
$chunkOverlap = 500;
$chunkSizeRetry = 350;
$embedOptions = ['num_batch' => 4096, 'num_ctx' => 4096];
$minContentLen = 20;
// Plafond decouvert en prod le 2026-07-14 : un vrai livre PDF de 2.8 Mo ("Intelligence
// artificielle" de John Paul Mueller) a produit des centaines de passages a lui seul.
// Le cout d'avoir PLUS de passages est uniquement la duree du backfill (job de fond
// nocturne, zero impact utilisateur) - PAS la vitesse de recherche (le volume de
// l'index n'affecte quasiment pas le temps d'une requete kNN, et les passages sont de
// toute facon deduppliques par document parent a la lecture). Au-dela de ce plafond
// (documents exceptionnellement volumineux), on repartit les chunks UNIFORMEMENT sur
// tout le document (pas juste le debut) pour garder une couverture representative.
$maxChunksPerDoc = 200;
$imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'heic', 'heif', 'webp', 'tiff', 'tif'];
// 5e piege trouve le 2026-07-14 (signale par l'utilisateur) : un tableur (ex.
// odoo_contacts_1000.xlsx, 1000 lignes de contacts) matchait sans rapport sur des
// requetes completement etrangeres. Cause differente des precedentes : ici le texte
// EST bien reel et non-vide (pas de faux positif "contenu vide"), mais des donnees
// TABULAIRES repetitives (colonnes nom/adresse/telephone/email) n'ont pas de "sens"
// prosaïque a comparer - un modele d'embedding entraine sur du texte naturel produit
// un vecteur peu fiable sur ce type de contenu structurel. Le decoupage recursif
// (paragraphes/phrases) ne l'aide pas non plus : un tableur n'a ni ligne vide ni
// ponctuation de fin de phrase, il retombe donc systematiquement sur le decoupage
// brut. Exclusion des extensions tableur, meme logique que les images ci-dessus -
// la recherche MOT-CLE reste evidemment inchangee (elle indexe toujours le contenu
// complet, tableur inclus).
$spreadsheetExtensions = ['xlsx', 'xls', 'ods', 'csv'];
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

/**
 * Decoupage brut au nombre de caracteres - dernier recours seulement, quand aucune
 * frontiere naturelle (paragraphe, phrase) n'est trouvable dans le texte a decouper
 * (ex: un tableau, une longue liste sans ponctuation de fin de phrase).
 *
 * @return string[]
 */
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

/**
 * Decoupe un paragraphe trop long en phrases (frontiere = ponctuation de fin de
 * phrase suivie d'un espace), puis regroupe ces phrases en "unites" sous chunkSize.
 * Si une phrase seule depasse deja chunkSize (rare), retombe sur hardSplitChars()
 * pour CETTE phrase uniquement.
 *
 * @return string[]
 */
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

/**
 * Decoupage RECURSIF respectant les frontieres naturelles du texte : paragraphes
 * d'abord (separateur "ligne(s) vide(s)"), puis phrases si un paragraphe depasse
 * chunkSize a lui seul, puis decoupage brut en tout dernier recours. Les "unites"
 * ainsi obtenues (chacune deja sous chunkSize) sont ensuite regroupees en chunks
 * finaux avec un chevauchement approximatif entre deux chunks consecutifs - meme
 * philosophie que le RecursiveCharacterTextSplitter de LangChain.
 *
 * @return string[]
 */
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

	// Regroupe les unites (deja sous chunkSize) en chunks finaux, avec chevauchement.
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

$scrollUrl = $elasticHost . '/' . $elasticIndex . '/_search?scroll=5m';
$result = esCurl('POST', $scrollUrl, [
	'size' => $batchSize,
	'_source' => ['title', 'content', 'parts', 'owner', 'users', 'groups', 'circles'],
	'query' => [
		'bool' => [
			'must_not' => [
				['exists' => ['field' => $chunkedMarker]],
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
		if (in_array($ext, $imageExtensions, true) || in_array($ext, $spreadsheetExtensions, true)) {
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

		$chunkFailure = false;
		$createdForThisDoc = 0;
		foreach ($textChunks as $i => $chunkText) {
			// Le titre est prefixe a chaque passage (contexte pour le modele + permet de
			// matcher sur le titre seul), mais SANS le prefixe "search_document:" de
			// nomic-embed-text : mxbai-embed-large attend les documents SANS prefixe -
			// seule la requete utilisateur recoit un prefixe (voir SearchController).
			$embedInput = $title . "\n" . $chunkText;

			$vector = embed($ollamaHost, $model, mb_substr($embedInput, 0, $chunkSize + 200), $embedOptions);
			if ($vector === null) {
				// Filet de securite pour un cas extreme non anticipe.
				$vector = embed($ollamaHost, $model, mb_substr($title . "\n" . mb_substr($chunkText, 0, $chunkSizeRetry), 0, $chunkSizeRetry + 200), $embedOptions);
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
				$vectorField => $vector,
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
			// passage du cron).
			$markResult = esCurl('POST', $elasticHost . '/' . $elasticIndex . '/_update/' . rawurlencode($id), [
				'doc' => [$chunkedMarker => true],
			]);
			if (isset($markResult['error'])) {
				$errors++;
				echo "ERREUR marquage $chunkedMarker $id : " . json_encode($markResult) . "\n";
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
