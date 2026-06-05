<?php

declare(strict_types=1);

/**
 * Iframe display mode (OCM-API#368 `iframe` target).
 *
 * Rendered inside the Nextcloud shell (RENDER_AS_USER) so the top bar and
 * app navigation remain around the embedded remote webapp — the same
 * pattern the integration_jupyterhub iframe page uses. This template emits
 * only the inner content; NC supplies the surrounding page.
 *
 * The remote app is loaded by auto-submitting a hidden POST form into the
 * iframe, so the access token travels in the body (no query-string leak).
 * CSP `frame-src` / `form-action` for the remote origin come from
 * CSPListener (§4.7); the inline auto-submit is stamped with NC's
 * per-request nonce so strict-dynamic doesn't block it. Styles are inline
 * (css/ is build output and gitignored); NC's CSP allows inline styles.
 *
 * @var \OCP\IL10N $l
 * @var array{uri:string, accessToken:string, sandbox:string, appName:string} $_
 */

$nonce = \OCP\Server::get(\OC\Security\CSP\ContentSecurityPolicyNonceManager::class)->getNonce();
?>
<style>
	#content { display: flex; }
	#ocm-embed { flex: 1 1 auto; min-height: 0; display: flex; width: 100%; height: 100%; }
	#ocm-embed-frame { flex: 1 1 auto; width: 100%; height: 100%; border: 0; display: block; }
	#ocm-launch { display: none; }
</style>
<div id="ocm-embed">
	<iframe
		id="ocm-embed-frame"
		name="ocm-target"
		sandbox="<?php p($_['sandbox']); ?>"
		allow="fullscreen"
		referrerpolicy="no-referrer"></iframe>
	<form id="ocm-launch" method="POST" action="<?php p($_['uri']); ?>" target="ocm-target" enctype="application/x-www-form-urlencoded">
		<input type="hidden" name="access_token" value="<?php p($_['accessToken']); ?>">
	</form>
</div>
<script nonce="<?php p($nonce); ?>">document.getElementById('ocm-launch').submit();</script>
