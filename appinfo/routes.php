<?php

declare(strict_types=1);

/**
 * Routes for ocmremotewebapp.
 *
 * Two surfaces:
 *
 *   - User-facing HTML pages (`page#index`, `page#open`). The index is the
 *     app's landing in the NC shell; `open` is the launcher reached by
 *     clicking a share card. `{token}` is the row's local URL key
 *     (random hex), NOT the share's `shared_secret` — see §4.4 in plan.md
 *     for the leak-vector reasoning.
 *
 *   - JSON API consumed by the Vue front-end (`received#*`, `config#*`).
 *     Accept/decline are local UI state transitions; no OCM federation
 *     notification is sent (we own no IShare — see §4.3 in plan.md).
 *
 * The OCM inbound endpoint itself is NOT declared here — NC's
 * cloud_federation_api owns `/.well-known/ocm` and `/ocm/shares` and
 * dispatches to our WebappCloudFederationProvider via the
 * addCloudFederationProvider() registration in Application::boot().
 */
return [
	'routes' => [
		['name' => 'page#index',        'url' => '/',                              'verb' => 'GET'],
		['name' => 'page#open',         'url' => '/ocm/open/{token}',              'verb' => 'GET'],
		['name' => 'received#list',     'url' => '/api/v1/shares',                 'verb' => 'GET'],
		['name' => 'received#accept',   'url' => '/api/v1/shares/{id}/accept',     'verb' => 'POST'],
		['name' => 'received#decline',  'url' => '/api/v1/shares/{id}/decline',    'verb' => 'POST'],
	],
];
