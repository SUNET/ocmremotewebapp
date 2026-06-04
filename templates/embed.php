<?php

declare(strict_types=1);

/**
 * Iframe display mode (#367 `iframe` target).
 *
 * Full-bleed iframe with a hidden POST form targeting it by name. The
 * form auto-submits on load, so the iframe loads via POST with the
 * access token in the body (no query-string leak).
 *
 * CSP `frame-src` and `form-action` for the remote origin come from
 * CSPListener (§4.7).
 *
 * @var \OCP\IL10N $l
 * @var array{uri:string, accessToken:string, sandbox:string, appName:string} $_
 */
$title = $_['appName'] !== '' ? $_['appName'] : 'OCM Remote WebApp';
// NC's default CSP is strict-dynamic + a per-request nonce, so an inline
// script without that nonce is blocked. Stamp it so the auto-submit runs.
$nonce = \OCP\Server::get(\OC\Security\CSP\ContentSecurityPolicyNonceManager::class)->getNonce();
?><!DOCTYPE html>
<html lang="<?php p($l->getLanguageCode()); ?>">
<head>
	<meta charset="utf-8">
	<title><?php p($title); ?></title>
	<style>
		html, body { margin: 0; padding: 0; height: 100%; width: 100%; overflow: hidden; }
		iframe { border: 0; width: 100vw; height: 100vh; display: block; }
		form { display: none; }
	</style>
</head>
<body>
	<iframe
		name="ocm-target"
		sandbox="<?php p($_['sandbox']); ?>"
		allow="fullscreen"
		referrerpolicy="no-referrer">
	</iframe>
	<form id="ocm-launch" method="POST" action="<?php p($_['uri']); ?>" target="ocm-target" enctype="application/x-www-form-urlencoded">
		<input type="hidden" name="access_token" value="<?php p($_['accessToken']); ?>">
	</form>
	<script nonce="<?php p($nonce); ?>">document.getElementById('ocm-launch').submit();</script>
</body>
</html>
