<?php

declare(strict_types=1);

namespace OCA\OCMRemoteWebApp\Federation;

use OCA\OCMRemoteWebApp\AppInfo\Application;
use OCA\OCMRemoteWebApp\Db\WebappShare;
use OCA\OCMRemoteWebApp\Db\WebappShareMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Federation\Exceptions\BadRequestException;
use OCP\Federation\Exceptions\ProviderCouldNotAddShareException;
use OCP\Federation\ICloudFederationShare;
use OCP\Federation\IValidationAwareCloudFederationProvider;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Handles inbound OCM shares whose resourceType is "webapp".
 *
 * NC's cloud_federation_api routes inbound /ocm/shares requests with
 * `resourceType: webapp` to us because Application::boot() registered
 * this provider via addCloudFederationProvider().
 *
 * Accepts only the post-#367 wire shape: payload in `protocol.webapp`,
 * `permissions` enum, absolute `uri`. v1 envelopes are rejected.
 */
class WebappCloudFederationProvider implements IValidationAwareCloudFederationProvider {

	private const ALLOWED_PERMISSIONS = ['view', 'read', 'write', 'share'];
	private const ALLOWED_TARGETS = ['blank', 'iframe', 'redirect'];

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
	 * Side-effect-free validation of the share envelope. Cloud federation
	 * API calls this on every incoming /ocm/shares request before
	 * shareReceived(); see {@see IValidationAwareCloudFederationProvider}.
	 *
	 * @throws BadRequestException If the envelope is structurally invalid.
	 * @throws ProviderCouldNotAddShareException For other rejections.
	 */
	public function validateShare(ICloudFederationShare $share): void {
		$this->parseShare($share);
	}

	/**
	 * @throws ProviderCouldNotAddShareException
	 */
	public function shareReceived(ICloudFederationShare $share): string {
		$parsed = $this->parseShare($share);

		$entity = new WebappShare();
		$entity->setLocalUid($parsed['localUid']);
		$entity->setToken(bin2hex(random_bytes(16)));
		$entity->setRemoteOwner((string)$share->getOwner());
		$entity->setRemoteSharedBy((string)$share->getSharedBy());
		$entity->setResourceName((string)$share->getResourceName());
		$entity->setUri($parsed['uri']);
		$entity->setPermissions($parsed['permissions']);
		$entity->setTargets($parsed['targets']);
		$entity->setRefreshToken($parsed['refreshToken']);
		// access_token / access_token_expires intentionally left NULL —
		// TokenExchanger mints them lazily on first launch.
		$entity->setState('pending');
		$entity->setCreatedAt(time());
		$entity->setAppName($parsed['appName']);
		$entity->setAppIcon($parsed['appIcon']);

		$saved = $this->mapper->insert($entity);
		$this->logger->info('Stored inbound webapp share for {user}', ['user' => $parsed['localUid']]);

		return (string)$saved->getId();
	}

	/**
	 * Pure parser: validates the share envelope and returns the fields
	 * shareReceived() will persist. No DB writes, no random tokens — safe
	 * to call from validateShare() as well.
	 *
	 * @return array{
	 *     localUid: string,
	 *     uri: string,
	 *     permissions: string,
	 *     targets: string,
	 *     refreshToken: string,
	 *     appName: string,
	 *     appIcon: string,
	 * }
	 * @throws BadRequestException
	 * @throws ProviderCouldNotAddShareException
	 */
	private function parseShare(ICloudFederationShare $share): array {
		if ($share->getResourceType() !== Application::WEBAPP_RESOURCE_TYPE) {
			throw new BadRequestException(['resourceType']);
		}

		$localUid = $this->resolveLocalUser($share->getShareWith());
		if ($localUid === null) {
			throw new ProviderCouldNotAddShareException('Unknown recipient', '', 400);
		}

		$webapp = $this->extractWebappEntry($share->getProtocol());
		if ($webapp === null) {
			throw new BadRequestException(['protocol.webapp']);
		}

		$uri = (string)($webapp['uri'] ?? '');
		if ($uri === '' || !$this->isAbsoluteUri($uri)) {
			throw new BadRequestException(['protocol.webapp.uri']);
		}

		// Per OCM-API#368 `permissions` is a non-empty array of
		// view/read/write/share. Filter to known values and reject when
		// nothing survives.
		$rawPermissions = $webapp['permissions'] ?? null;
		if (!is_array($rawPermissions)) {
			throw new BadRequestException(['protocol.webapp.permissions']);
		}
		$permissionsList = array_values(array_filter(
			$rawPermissions,
			fn ($p) => is_string($p) && in_array($p, self::ALLOWED_PERMISSIONS, true),
		));
		if ($permissionsList === []) {
			throw new BadRequestException(['protocol.webapp.permissions']);
		}

		$refreshToken = (string)($webapp['sharedSecret'] ?? '');
		if ($refreshToken === '') {
			throw new BadRequestException(['protocol.webapp.sharedSecret']);
		}

		return [
			'localUid' => $localUid,
			'uri' => $uri,
			'permissions' => (string)json_encode($permissionsList),
			'targets' => $this->encodeTargets($webapp['targets'] ?? null),
			'refreshToken' => $refreshToken,
			'appName' => (string)($webapp['appName'] ?? ''),
			'appIcon' => (string)($webapp['appIcon'] ?? ''),
		];
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
	 * Pull the `webapp` entry out of the protocol envelope.
	 * Accept only:
	 *   - `{name: "webapp", webapp: {...}}`
	 *   - `{name: "multi",  webapp: {...}, webdav: {...}}` (webdav ignored)
	 *
	 * v1's `{name: "webapp", options: {...}}` is rejected.
	 *
	 * @param array<mixed> $protocol
	 * @return array<string, mixed>|null
	 */
	private function extractWebappEntry(array $protocol): ?array {
		$name = (string)($protocol['name'] ?? '');
		if (($name === 'webapp' || $name === 'multi') && isset($protocol['webapp']) && is_array($protocol['webapp'])) {
			return $protocol['webapp'];
		}
		return null;
	}

	private function isAbsoluteUri(string $uri): bool {
		$scheme = parse_url($uri, PHP_URL_SCHEME);
		return $scheme === 'http' || $scheme === 'https';
	}

	/**
	 * Encode `targets` as JSON, filtering to known values. Empty/missing
	 * input falls back to the RFC default `["blank"]`.
	 *
	 * @param mixed $raw
	 */
	private function encodeTargets($raw): string {
		if (!is_array($raw)) {
			return '["blank"]';
		}
		$clean = array_values(array_filter(
			$raw,
			fn ($t) => is_string($t) && in_array($t, self::ALLOWED_TARGETS, true),
		));
		if ($clean === []) {
			return '["blank"]';
		}
		return (string)json_encode($clean);
	}
}
