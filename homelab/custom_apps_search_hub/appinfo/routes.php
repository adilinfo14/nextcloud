<?php

return [
	'routes' => [
		['name' => 'search#index', 'url' => '/', 'verb' => 'GET'],
		['name' => 'search#search', 'url' => '/api/search', 'verb' => 'GET'],
		['name' => 'search#searchNeural', 'url' => '/api/search-neural', 'verb' => 'GET'],
		['name' => 'search#suggest', 'url' => '/api/suggest', 'verb' => 'GET'],
		['name' => 'search#explainMatch', 'url' => '/api/explain-match', 'verb' => 'GET'],
		['name' => 'status#get', 'url' => '/admin/status', 'verb' => 'GET'],
		['name' => 'status#reindex', 'url' => '/admin/reindex', 'verb' => 'POST'],
		['name' => 'status#reindexEmbeddings', 'url' => '/admin/reindex-embeddings', 'verb' => 'POST'],
		['name' => 'status#getConfig', 'url' => '/admin/config', 'verb' => 'GET'],
		['name' => 'status#saveConfig', 'url' => '/admin/config', 'verb' => 'POST'],
		['name' => 'status#getLogs', 'url' => '/admin/logs', 'verb' => 'GET'],
	],
];
