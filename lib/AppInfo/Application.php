<?php

declare(strict_types=1);

namespace OCA\OCMRemoteWebApp\AppInfo;

use OCA\OCMRemoteWebApp\Federation\FolderCloudFederationProvider;
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

	/** OCM resource type advertised via /.well-known/ocm. */
	public const WEBAPP_RESOURCE_TYPE = 'folder';

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
			// Older Nextcloud versions register the Files provider only as "file".
			$originalCallback = $providers[self::WEBAPP_RESOURCE_TYPE]['callback']
				?? $providers['file']['callback']
				?? null;
			$manager->removeCloudFederationProvider(self::WEBAPP_RESOURCE_TYPE);
			$manager->addCloudFederationProvider(
				self::WEBAPP_RESOURCE_TYPE,
				self::WEBAPP_DISPLAY_NAME,
				function () use ($context, $originalCallback): FolderCloudFederationProvider {
					$originalProvider = $originalCallback !== null ? $originalCallback() : null;
					if ($originalProvider instanceof FolderCloudFederationProvider) {
						return $originalProvider;
					}
					return new FolderCloudFederationProvider(
						$context->getAppContainer()->get(WebappCloudFederationProvider::class),
						$originalProvider,
					);
				},
			);
		});
	}
}
