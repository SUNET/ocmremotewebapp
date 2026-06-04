<?php

declare(strict_types=1);

namespace OCA\OCMRemoteWebApp\Listener;

use OCA\OCMRemoteWebApp\Db\WebappShareMapper;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IUserSession;
use OCP\Security\CSP\AddContentSecurityPolicyEvent;

/**
 * Whitelists every remote web app this user has a share for. The launcher
 * (PageController::open) POSTs the access token to the share's URI and,
 * for the iframe target, embeds that URI — both blocked by the default
 * CSP (form-action / frame-src default to 'self'). We add each distinct
 * share-URI origin so the launch can reach the remote hub.
 *
 * @template-implements IEventListener<AddContentSecurityPolicyEvent>
 */
class CSPListener implements IEventListener {

	public function __construct(
		private ?string $userId,
		private WebappShareMapper $mapper,
		private IUserSession $userSession,
	) {
	}

	public function handle(Event $event): void {
		if (!($event instanceof AddContentSecurityPolicyEvent)) {
			return;
		}
		$uid = $this->userId ?? $this->userSession->getUser()?->getUID() ?? '';
		if ($uid === '') {
			return;
		}

		$origins = [];
		foreach ($this->mapper->findAllByUid($uid) as $share) {
			$origin = $this->originOf($share->getUri());
			if ($origin !== null) {
				$origins[$origin] = true;
			}
		}
		if ($origins === []) {
			return;
		}

		$csp = new ContentSecurityPolicy();
		foreach (array_keys($origins) as $origin) {
			$csp->addAllowedFrameDomain($origin);
			$csp->addAllowedFormActionDomain($origin);
			$csp->addAllowedConnectDomain($origin);
		}
		// form-action defaults to 'self'; once we add explicit domains we
		// must re-add 'self' so the rest of the app keeps working.
		$csp->addAllowedFormActionDomain('\'self\'');
		$event->addPolicy($csp);
	}

	private function originOf(string $uri): ?string {
		$parts = parse_url($uri);
		if (!isset($parts['scheme'], $parts['host'])) {
			return null;
		}
		$origin = $parts['scheme'] . '://' . $parts['host'];
		if (isset($parts['port'])) {
			$origin .= ':' . $parts['port'];
		}
		return $origin;
	}
}
