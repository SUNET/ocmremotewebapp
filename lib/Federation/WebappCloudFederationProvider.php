<?php

declare(strict_types=1);

namespace OCA\OCMRemoteWebApp\Federation;

use OCA\OCMRemoteWebApp\AppInfo\Application;
use OCA\OCMRemoteWebApp\Db\WebappShare;
use OCA\OCMRemoteWebApp\Db\WebappShareMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Federation\Exceptions\ProviderCouldNotAddShareException;
use OCP\Federation\ICloudFederationProvider;
use OCP\Federation\ICloudFederationShare;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Handles inbound OCM shares whose resourceType is "webapp".
 *
 * NC's cloud_federation_api routes inbound /ocm/shares requests with
 * `resourceType: webapp` to us because Application::boot() registered
 * this provider via addCloudFederationProvider().
 */
class WebappCloudFederationProvider implements ICloudFederationProvider {

	public function __construct(
		private IUserManager $userManager,
		private WebappShareMapper $mapper,
		private LoggerInterface $logger,
	) {
	}

	public function getShareType(): string {
		return Application::WEBAPP_RESOURCE_TYPE;
	}

	public function getSupportedShareTypes(): array {
		return ['user'];
	}

	/**
	 * @throws ProviderCouldNotAddShareException
	 */
	public function shareReceived(ICloudFederationShare $share): string {
		if ($share->getResourceType() !== Application::WEBAPP_RESOURCE_TYPE) {
			throw new ProviderCouldNotAddShareException('Unsupported resource type', '', 400);
		}

		$localUid = $this->resolveLocalUser($share->getShareWith());
		if ($localUid === null) {
			throw new ProviderCouldNotAddShareException('Unknown recipient', '', 400);
		}

		$webapp = $this->extractWebappEntry($share->getProtocol());
		if ($webapp === null) {
			throw new ProviderCouldNotAddShareException('webapp protocol entry missing', '', 400);
		}

		$uri = (string)($webapp['uri'] ?? '');
		if ($uri === '') {
			throw new ProviderCouldNotAddShareException('webapp.uri missing or empty', '', 400);
		}

		// v2 detection — per-share, not per-server. Any of the
		// new fields tells us the sender speaks v2; otherwise treat as v1.
		$isV2 = isset($webapp['permissions']) || isset($webapp['targets']) || isset($webapp['appName']);
		$permissions = $this->normalizePermissions(
			$isV2
				? (string)($webapp['permissions'] ?? 'view')
				: (string)($webapp['viewMode'] ?? 'view')
		);
		$targets = $this->encodeTargets($webapp['targets'] ?? null);

		// Fresh local URL key, deliberately NOT derived from sharedSecret —
		// the launcher URL ends up in browser history, proxy logs, and
		// Referer headers, so using the bearer here would leak it. The
		// bearer goes in the `shared_secret` column only and is transported
		// via POST body (v2) or destination-origin query string (v1).
		$token = bin2hex(random_bytes(16));

		$entity = new WebappShare();
		$entity->setLocalUid($localUid);
		$entity->setToken($token);
		$entity->setRemoteOwner((string)$share->getOwner());
		$entity->setRemoteSharedBy((string)$share->getSharedBy());
		$entity->setResourceName((string)$share->getResourceName());
		$entity->setUri($uri);
		$entity->setPermissions($permissions);
		$entity->setTargets($targets);
		$entity->setSharedSecret((string)($webapp['sharedSecret'] ?? ''));
		$entity->setState('pending');
		$entity->setCreatedAt(time());
		$entity->setProtocolVersion($isV2 ? 'v2' : 'v1');
		$entity->setAppName((string)($webapp['appName'] ?? ''));
		$entity->setAppIcon((string)($webapp['appIcon'] ?? ''));

		$saved = $this->mapper->insert($entity);
		$this->logger->info('Stored inbound webapp share for {user}', ['user' => $localUid]);

		return (string)$saved->getId();
	}

	/**
	 * The only inbound notification a receive-only app can meaningfully act
	 * on is SHARE_UNSHARED — the sender has revoked the share, so we drop
	 * the row.
	 *
	 * @param array<string, mixed> $notification
	 * @return array<string>
	 */
	public function notificationReceived($notificationType, $providerId, array $notification): array {
		if ($notificationType !== 'SHARE_UNSHARED') {
			return [];
		}
		$id = (int)$providerId;
		if ($id <= 0) {
			return [];
		}
		try {
			$entity = $this->mapper->findById($id);
			$this->mapper->delete($entity);
		} catch (DoesNotExistException) {
			// nothing to remove.
		}
		return [];
	}

	/**
	 * `shareWith` is the recipient's federated ID, `user@host`. NC's
	 * cloud_federation_api has already validated the host portion matches
	 * this instance; we just take the local uid.
	 */
	private function resolveLocalUser(string $shareWith): ?string {
		$at = strrpos($shareWith, '@');
		$uid = $at === false ? $shareWith : substr($shareWith, 0, $at);
		return $this->userManager->userExists($uid) ? $uid : null;
	}

	/**
	 * Pull the `webapp` entry out of the protocol envelope. We accept:
	 *   - `{name: "webapp", webapp: {...}}` (Option 2/3 of v1 RFC, and v2)
	 *   - `{name: "webapp", options: {...}}` (Option 1, deprecated v1.0)
	 *   - `{name: "multi", webapp: {...}, webdav: {...}}`
	 *
	 * @param array<mixed> $protocol
	 * @return array<string, mixed>|null
	 */
	private function extractWebappEntry(array $protocol): ?array {
		$name = (string)($protocol['name'] ?? '');
		if (($name === 'webapp' || $name === 'multi') && isset($protocol['webapp']) && is_array($protocol['webapp'])) {
			return $protocol['webapp'];
		}
		if ($name === 'webapp' && isset($protocol['options']) && is_array($protocol['options'])) {
			return $protocol['options'];
		}
		return null;
	}

	/**
	 * Accepts both the v1 `viewMode` enum (view/read/write) and the v2
	 * `permissions` enum (adds `share`). The wire value is stored verbatim;
	 * unknown values fall back to the safest option, `view`.
	 */
	private function normalizePermissions(string $value): string {
		return match ($value) {
			'view', 'read', 'write', 'share' => $value,
			default => 'view',
		};
	}

	/**
	 * Encode v2 `targets` as JSON, filtering to known string values. v1
	 * shares (no `targets` field) get stored as ''.
	 *
	 * @param mixed $raw
	 */
	private function encodeTargets($raw): string {
		if (!is_array($raw)) {
			return '';
		}
		$clean = array_values(array_filter(
			$raw,
			fn ($t) => is_string($t) && in_array($t, ['blank', 'iframe', 'popup'], true),
		));
		return $clean === [] ? '' : (string)json_encode($clean);
	}
}
