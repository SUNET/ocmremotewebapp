<?php

declare(strict_types=1);

namespace OCA\OCMRemoteWebApp\Federation;

use OCA\OCMRemoteWebApp\AppInfo\Application;
use OCA\OCMRemoteWebApp\Db\WebappShare;
use OCA\OCMRemoteWebApp\Db\WebappShareMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Federation\Exceptions\BadRequestException;
use OCP\Federation\Exceptions\ProviderCouldNotAddShareException;
use OCP\Federation\Exceptions\ProviderDoesNotExistsException;
use OCP\Federation\ICloudFederationFactory;
use OCP\Federation\ICloudFederationProviderManager;
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
		private ICloudFederationProviderManager $federationManager,
		private ICloudFederationFactory $federationFactory,
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

		// Forward the webdav protocol entry to NC's built-in "file" provider
		// so the shared folder also appears as a federated Files mount. The
		// file provider auto-accepts the mount for trusted servers and
		// otherwise leaves it pending. We mirror that decision onto the
		// webapp share so the two — which share one sharedSecret and are
		// really one logical share — stay in lockstep.
		$fileShareId = $this->forwardWebdavToFileProvider($share);
		$accepted = $fileShareId !== null
			&& $fileShareId !== ''
			&& $this->isAutoAcceptedFromTrustedServer((string)$share->getOwner());

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
		$entity->setState($accepted ? 'accepted' : 'pending');
		$entity->setCreatedAt(time());
		$entity->setAppName($parsed['appName']);
		$entity->setAppIcon($parsed['appIcon']);
		if ($fileShareId !== null && $fileShareId !== '') {
			$entity->setFileShareId($fileShareId);
		}

		$saved = $this->mapper->insert($entity);
		$this->logger->info('Stored inbound webapp share for {user} (state={state})', [
			'user' => $parsed['localUid'],
			'state' => $saved->getState(),
		]);

		return (string)$saved->getId();
	}

	/**
	 * Mirror NC's federated-files auto-accept rule: accept automatically
	 * when "auto-accept from trusted servers" is on and the owner's server
	 * is trusted. Same condition CloudFederationProviderFiles applies to the
	 * webdav mount, so both halves of the share resolve identically.
	 */
	private function isAutoAcceptedFromTrustedServer(string $ownerFederatedId): bool {
		$at = strrpos($ownerFederatedId, '@');
		if ($at === false) {
			return false;
		}
		$remote = substr($ownerFederatedId, $at + 1);
		if ($remote === '') {
			return false;
		}
		try {
			$fsp = \OCP\Server::get(\OCA\FederatedFileSharing\FederatedShareProvider::class);
			if (!$fsp->isFederatedTrustedShareAutoAccept()) {
				return false;
			}
			if (!class_exists(\OCA\Federation\TrustedServers::class)) {
				return false;
			}
			$trusted = \OCP\Server::get(\OCA\Federation\TrustedServers::class);
			return $trusted->isTrustedServer($remote);
		} catch (\Throwable $e) {
			$this->logger->debug('trusted-server auto-accept check failed: {msg}', ['msg' => $e->getMessage()]);
			return false;
		}
	}

	/**
	 * Synthesize a single-protocol webdav share from the multi-protocol
	 * envelope and hand it to NC's "file" cloud federation provider, which
	 * sets up the external Files mount. Returns the external share id, or
	 * null when no mount could be created — a missing mount must not fail
	 * the webapp share itself.
	 */
	private function forwardWebdavToFileProvider(ICloudFederationShare $original): ?string {
		$protocol = $original->getProtocol();
		$webdav = $protocol['webdav'] ?? null;
		if (!is_array($webdav)) {
			return null;
		}
		try {
			$fileProvider = $this->federationManager->getCloudFederationProvider('file');
		} catch (ProviderDoesNotExistsException $e) {
			$this->logger->warning('No "file" provider registered; webapp share has no Files mount: {msg}', ['msg' => $e->getMessage()]);
			return null;
		}

		$proxy = $this->federationFactory->getCloudFederationShare(
			$original->getShareWith(),
			$original->getResourceName(),
			$original->getDescription(),
			$original->getProviderId(),
			$original->getOwner(),
			$original->getOwnerDisplayName(),
			$original->getSharedBy(),
			$original->getSharedByDisplayName(),
			(string)($webdav['sharedSecret'] ?? ''),
			$original->getShareType(),
			'file',
		);
		$proxy->setProtocol(['name' => 'webdav', 'webdav' => $webdav]);

		try {
			return (string)$fileProvider->shareReceived($proxy);
		} catch (\Throwable $e) {
			$this->logger->warning('Forwarding webdav portion to file provider failed: {msg}', [
				'msg' => $e->getMessage(),
				'exception' => $e,
			]);
			return null;
		}
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
