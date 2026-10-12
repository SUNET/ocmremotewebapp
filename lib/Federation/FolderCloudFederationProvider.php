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
 * Decorates the folder provider without taking ownership of non-webapp shares.
 */
class FolderCloudFederationProvider implements IValidationAwareCloudFederationProvider, ISignedCloudFederationProvider {

	public function __construct(
		private WebappCloudFederationProvider $webappProvider,
		private ?ICloudFederationProvider $folderProvider,
	) {
	}

	public function getShareType(): string {
		return Application::WEBAPP_RESOURCE_TYPE;
	}

	public function getSupportedShareTypes(): array {
		return array_values(array_unique(array_merge(
			$this->folderProvider?->getSupportedShareTypes() ?? [],
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
			return $this->webappProvider->notificationReceived($notificationType, $providerId, $notification);
		}
		return $this->getFolderProvider()->notificationReceived($notificationType, $providerId, $notification);
	}

	/** @param array<string, mixed> $payload */
	public function getFederationIdFromSharedSecret(string $sharedSecret, array $payload): string {
		if ($this->folderProvider instanceof ISignedCloudFederationProvider) {
			return $this->folderProvider->getFederationIdFromSharedSecret($sharedSecret, $payload);
		}
		return '';
	}

	private function providerForShare(ICloudFederationShare $share): ICloudFederationProvider {
		$protocol = $share->getProtocol();
		// Presence, not validity, selects the webapp provider: malformed entries
		// must be rejected there rather than accepted as ordinary folder shares.
		if (($protocol['name'] ?? '') === 'webapp' || array_key_exists('webapp', $protocol)) {
			return $this->webappProvider;
		}
		return $this->getFolderProvider();
	}

	private function getFolderProvider(): ICloudFederationProvider {
		if ($this->folderProvider === null) {
			throw new ProviderDoesNotExistsException(Application::WEBAPP_RESOURCE_TYPE);
		}
		return $this->folderProvider;
	}
}
