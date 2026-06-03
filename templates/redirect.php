<?php

declare(strict_types=1);

/**
 * Redirect display mode (#367 `blank` target).
 *
 * A 302 cannot carry a POST body, and #367 requires the access token in
 * a form field. So "redirect" here renders an HTML page that
 * auto-submits a form to the share URI in the same tab.
 *
 * @var \OCP\IL10N $l
 * @var array{uri:string, accessToken:string, appName:string} $_
 */
$title = $_['appName'] !== '' ? $_['appName'] : $l->t('Opening shared webapp');
?><!DOCTYPE html>
<html lang="<?php p($l->getLanguageCode()); ?>">
<head>
	<meta charset="utf-8">
	<title><?php p($title); ?></title>
	<style>
		body { font-family: sans-serif; padding: 2em; }
	</style>
</head>
<body>
	<noscript>
		<p><?php p($l->t('JavaScript is disabled. Click the button below to continue.')); ?></p>
	</noscript>
	<form id="ocm-launch" method="POST" action="<?php p($_['uri']); ?>" target="_self" enctype="application/x-www-form-urlencoded">
		<input type="hidden" name="access_token" value="<?php p($_['accessToken']); ?>">
		<noscript>
			<button type="submit"><?php p($l->t('Continue')); ?></button>
		</noscript>
	</form>
	<script>document.getElementById('ocm-launch').submit();</script>
</body>
</html>
