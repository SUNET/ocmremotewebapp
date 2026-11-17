<?php

declare(strict_types=1);

namespace OCA\OCMRemoteWebApp\AppInfo;

use OCA\OCMRemoteWebApp\Federation\DelegatingCloudFederationProvider;
use OCA\OCMRemoteWebApp\Federation\WebappCloudFederationProvider;
use OCA\OCMRemoteWebApp\Listener\CSPListener;
use OCA\OCMRemoteWebApp\Listener\LocalOCMDiscoveryEventListener;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Federation\ICloudFederationProviderManager;
use OCP\OCM\Events\LocalOCMDiscoveryEvent;
use OCP\Security\CSP\AddContentSecurityPolicyEvent;

class Application extends App implements IBootstrap {
	public const APP_ID = 'ocmremotewebapp';

	/** Primary OCM resource type advertised via /.well-known/ocm. */
	public const WEBAPP_RESOURCE_TYPE = 'folder';

	/** Resource types for which this app accepts inbound webapp shares. */
	public const WEBAPP_RESOURCE_TYPES = ['folder', 'file'];

	/** User-facing label in NC's federation provider registry. */
	public const WEBAPP_DISPLAY_NAME = 'OCM Remote WebApp';

	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct() {
		parent::__construct(self::APP_ID);
	}

	public function register(IRegistrationContext $context): void {
		$context->registerEventListener(LocalOCMDiscoveryEvent::class, LocalOCMDiscoveryEventListener::class);
		$context->registerEventListener(AddContentSecurityPolicyEvent::class, CSPListener::class);
	}

	public function boot(IBootContext $context): void {
		$context->injectFn(function (ICloudFederationProviderManager $manager) use ($context): void {
			$providers = $manager->getAllCloudFederationProviders();
			foreach (self::WEBAPP_RESOURCE_TYPES as $type) {
				// Older Nextcloud versions register the Files provider only as "file".
				$originalCallback = $providers[$type]['callback']
					?? ($type === self::WEBAPP_RESOURCE_TYPE ? ($providers['file']['callback'] ?? null) : null);
				if (isset($providers[$type])) {
					$manager->removeCloudFederationProvider($type);
				}
				$manager->addCloudFederationProvider(
					$type,
					self::WEBAPP_DISPLAY_NAME,
					function () use ($context, $originalCallback, $type): DelegatingCloudFederationProvider {
						$originalProvider = $originalCallback !== null ? $originalCallback() : null;
						if ($originalProvider instanceof DelegatingCloudFederationProvider) {
							return $originalProvider;
						}
						return new DelegatingCloudFederationProvider(
							$context->getAppContainer()->get(WebappCloudFederationProvider::class),
							$originalProvider,
							$type,
						);
					},
				);
			}
		});
	}
}
