<?php

declare(strict_types=1);

/**
 * Rendered when TokenExchanger throws — the remote OCM peer is
 * unreachable, doesn't expose tokenEndPoint, or rejected the
 * authorization code.
 *
 * @var \OCP\IL10N $l
 * @var array{message:string} $_
 */
?>
<div id="content" style="padding: 2em; max-width: 40em; margin: 0 auto;">
	<h2><?php p($l->t('Cannot open shared webapp')); ?></h2>
	<p><?php p($l->t($_['message'])); ?></p>
	<p><?php p($l->t('Please try again later. If the problem persists, contact the sender of this share.')); ?></p>
</div>
