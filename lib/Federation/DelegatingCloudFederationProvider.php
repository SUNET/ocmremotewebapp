<?php

declare(strict_types=1);

namespace OCA\OCMRemoteWebApp\Federation;

use OCA\OCMRemoteWebApp\AppInfo\Application;
use OCP\Federation\Exceptions\ProviderDoesNotExistsException;
use OCP\Federation\ICloudFederationProvider;
use OCP\Federation\ICloudFederationShare;
use OCP\Federation\ISignedCloudFederationProvider;
use OCP\Federation\IValidationAwareCloudFederationProvider;

/**
 * Decorates the core provider for one resource type (folder or file)
 * without taking ownership of non-webapp shares: shares carrying a
 * `webapp` protocol entry go to the webapp provider, everything else to
 * the wrapped core provider.
 *
 * Notifications are always forwarded to the core provider when one is
 * wrapped (so the paired Files mount is reaped even for `file`
 * resourceType notifications, which would otherwise be claimed by the
 * webapp provider); webapp share rows owned by this app are additionally
 * cleaned up.
 */
class DelegatingCloudFederationProvider implements IValidationAwareCloudFederationProvider, ISignedCloudFederationProvider {

	public function __construct(
		private WebappCloudFederationProvider $webappProvider,
		private ?ICloudFederationProvider $coreProvider,
		private string $resourceType = Application::WEBAPP_RESOURCE_TYPE,
	) {
	}

	public function getShareType(): string {
		return $this->resourceType;
	}

	public function getSupportedShareTypes(): array {
		return array_values(array_unique(array_merge(
			$this->coreProvider?->getSupportedShareTypes() ?? [],
			$this->webappProvider->getSupportedShareTypes(),
		)));
	}

	public function validateShare(ICloudFederationShare $share): void {
		$provider = $this->providerForShare($share);
		if ($provider instanceof IValidationAwareCloudFederationProvider) {
			$provider->validateShare($share);
		}
	}

	public function shareReceived(ICloudFederationShare $share): string {
		return $this->providerForShare($share)->shareReceived($share);
	}

	/**
	 * @param array<string, mixed> $notification
	 * @return array<string>
	 */
	public function notificationReceived(string $notificationType, string $providerId, array $notification): array {
		if ($this->webappProvider->ownsNotification($providerId, $notification)) {
			$this->webappProvider->notificationReceived($notificationType, $providerId, $notification);
		}
		if ($this->coreProvider !== null) {
			return $this->coreProvider->notificationReceived($notificationType, $providerId, $notification);
		}
		return [];
	}

	/** @param array<string, mixed> $payload */
	public function getFederationIdFromSharedSecret(string $sharedSecret, array $payload): string {
		if ($this->coreProvider instanceof ISignedCloudFederationProvider) {
			return $this->coreProvider->getFederationIdFromSharedSecret($sharedSecret, $payload);
		}
		return '';
	}

	private function providerForShare(ICloudFederationShare $share): ICloudFederationProvider {
		$protocol = $share->getProtocol();
		// Presence, not validity, selects the webapp provider: malformed entries
		// must be rejected there rather than accepted as ordinary shares.
		if (($protocol['name'] ?? '') === 'webapp' || array_key_exists('webapp', $protocol)) {
			return $this->webappProvider;
		}
		return $this->getCoreProvider();
	}

	private function getCoreProvider(): ICloudFederationProvider {
		if ($this->coreProvider === null) {
			throw new ProviderDoesNotExistsException($this->resourceType);
		}
		return $this->coreProvider;
	}
}
