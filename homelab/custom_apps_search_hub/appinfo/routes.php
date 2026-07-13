<?php

return [
	'routes' => [
		['name' => 'search#index', 'url' => '/', 'verb' => 'GET'],
		['name' => 'search#search', 'url' => '/api/search', 'verb' => 'GET'],
		['name' => 'status#get', 'url' => '/admin/status', 'verb' => 'GET'],
		['name' => 'status#reindex', 'url' => '/admin/reindex', 'verb' => 'POST'],
	],
];
