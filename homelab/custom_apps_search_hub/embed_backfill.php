<?php
// Backfill des embeddings (nomic-embed-text, 768 dim) pour les documents Elasticsearch
// qui n'en ont pas encore (recherche_hub / Recherche+ - mode "Recherche par sens").
// Script autonome (pas de bootstrap Nextcloud necessaire) : incremental par construction
// (ne cible que embedding_vector manquant), donc a re-executer periodiquement (cron) pour
// rattraper les nouveaux documents indexes depuis.
//
// Piege important (trouve en prod le 2026-07-13) : nomic-embed-text plante avec un
// panic Ollama ("caching disabled but unable to fit entire input in a batch") si le
// texte envoye depasse la taille de batch du modele (~512 tokens). Un texte de 3000
// caracteres declenchait ce crash sur ~170/1808 documents (ceux au contenu le plus
// dense). D'ou TEXT_MAX_LEN volontairement conservateur (800 car.) + un retry
// automatique avec un texte encore plus court en cas d'echec, plutot que de perdre le
// document silencieusement.
//
// 2e piege trouve le 2026-07-14 : un dossier (ou fichier quasi vide) n'a PAS de
// "content" mais A un titre ("Notes", "Photos", "Mon Iphone"...) - embarquer seulement
// ce titre produit un vecteur tres court/generique qui se retrouve, par similarite
// cosinus, artificiellement "proche" de PRESQUE TOUTES les requetes (remonte en tete
// de resultats sans aucun rapport). D'ou l'exigence d'un contenu REEL minimal
// (MIN_CONTENT_LEN), independamment du titre.
//
// 3e piege trouve le 2026-07-14 (meme session) : files_fulltextsearch_tesseract fait
// tourner l'OCR sur TOUTES les images (pas seulement les scans de documents) - une
// vraie photo de vacances produit un "content" de bruit OCR (ex: "ES PE hn\n\nere =")
// qui passe le filtre MIN_CONTENT_LEN mais n'a evidemment aucun sens. D'ou l'exclusion
// explicite des extensions image (les photos n'ont rien a faire dans une recherche
// SEMANTIQUE par definition - aucun contenu textuel reel a comparer).
//
// 4e piege trouve le 2026-07-14 (meme session, signale par l'utilisateur) : un
// document avec du VRAI contenu coherent (ex: "Modeles/Menu.odt", un menu de
// restaurant complet) peut quand meme ressortir sans rapport avec une requete
// technique - pas un bug d'embedding, mais du bruit STRUCTUREL : "Modeles/" est le
// pack de modeles Office LIVRE PAR DEFAUT avec toute installation Nextcloud (Menu,
// Facture, CV, Certificat...), jamais du vrai contenu utilisateur. Deja identifie
// comme tel dans ce projet (cf. exclusion similaire faite pour la bibliotheque de
// documents Tables) - meme logique appliquee ici : exclure les dossiers de demo
// connus du skeleton Nextcloud plutot que de les embarquer.

$elasticHost = 'http://elasticsearch:9200';
$elasticIndex = 'nextcloud_tkonsulting';
$ollamaHost = 'http://ollama:11434';
$model = 'nomic-embed-text';
$batchSize = 30;
$textMaxLen = 800;
$textMaxLenRetry = 300;
$minContentLen = 20;
$imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'heic', 'heif', 'webp', 'tiff', 'tif'];
$demoPathPrefixes = ['Modèles/', 'Templates/', 'Notes/'];
$demoExactTitles = ['Documents/Example.md'];

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

$scrollUrl = $elasticHost . '/' . $elasticIndex . '/_search?scroll=5m';
$result = esCurl('POST', $scrollUrl, [
	'size' => $batchSize,
	'_source' => ['title', 'content', 'parts'],
	'query' => ['bool' => ['must_not' => ['exists' => ['field' => 'embedding_vector']]]],
]);

if (isset($result['error']) && !isset($result['_scroll_id'])) {
	fwrite(STDERR, "Erreur ES initiale : " . json_encode($result) . "\n");
	exit(1);
}

$scrollId = $result['_scroll_id'] ?? null;
$total = $result['hits']['total']['value'] ?? 0;
$processed = 0;
$embedded = 0;
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
			// Pas de contenu reel (dossier, fichier vide/quasi-vide) : on ne calcule PAS
			// d'embedding pour ce document plutot que d'embarquer juste son titre (voir le
			// 2e piege documente plus haut) - il n'apparaitra jamais en recherche par sens,
			// ce qui est correct puisqu'il n'a aucun "sens" a comparer.
			$skipped++;
			continue;
		}
		$fullText = trim($title . "\n" . $content . ' ' . $partsText);

		$vector = embed($ollamaHost, $model, 'search_document: ' . mb_substr($fullText, 0, $textMaxLen));
		if ($vector === null) {
			// Probable crash "batch trop grand" sur un document tres dense : retente une
			// seule fois avec un texte plus court avant d'abandonner ce document.
			$vector = embed($ollamaHost, $model, 'search_document: ' . mb_substr($fullText, 0, $textMaxLenRetry));
		}
		if ($vector === null) {
			$errors++;
			echo "ERREUR embed $id\n";
			continue;
		}

		$updateResult = esCurl('POST', $elasticHost . '/' . $elasticIndex . '/_update/' . rawurlencode($id), [
			'doc' => ['embedding_vector' => $vector],
		]);
		if (isset($updateResult['error'])) {
			$errors++;
			echo "ERREUR update $id : " . json_encode($updateResult) . "\n";
		} else {
			$embedded++;
		}
	}

	echo "Progression : $processed / $total (embedded=$embedded, skipped=$skipped, errors=$errors)\n";

	$result = esCurl('POST', $elasticHost . '/_search/scroll', [
		'scroll' => '5m',
		'scroll_id' => $scrollId,
	]);
	$scrollId = $result['_scroll_id'] ?? $scrollId;
}

echo "Termine. processed=$processed embedded=$embedded skipped=$skipped errors=$errors\n";
