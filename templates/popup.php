<?php

declare(strict_types=1);

/**
 * Popup display mode (#367 `popup` target).
 *
 * Button-driven POST form with target="_blank". Browsers block scripted
 * window.open without a user gesture, so we render a visible button the
 * user clicks — that click satisfies the gesture requirement.
 *
 * @var \OCP\IL10N $l
 * @var array{uri:string, accessToken:string, appName:string, resourceName:string} $_
 */
$label = $_['appName'] !== ''
	? $_['appName']
	: ($_['resourceName'] !== '' ? $_['resourceName'] : $l->t('Shared webapp'));
?>
<div id="content" style="padding: 2em; max-width: 40em; margin: 0 auto;">
	<h2><?php p($l->t('Open shared webapp: %s', [$label])); ?></h2>
	<p><?php p($l->t('Click the button below to open the shared webapp in a new browser tab.')); ?></p>
	<form method="POST" action="<?php p($_['uri']); ?>" target="_blank" enctype="application/x-www-form-urlencoded">
		<input type="hidden" name="access_token" value="<?php p($_['accessToken']); ?>">
		<button type="submit" class="button primary"><?php p($l->t('Open in new tab')); ?></button>
	</form>
</div>
