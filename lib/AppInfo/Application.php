<?php

declare(strict_types=1);

namespace OCA\OCMRemoteWebApp\AppInfo;

use OCA\OCMRemoteWebApp\Federation\WebappCloudFederationProvider;
use OCA\OCMRemoteWebApp\Listener\LocalOCMDiscoveryEventListener;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Federation\Exceptions\ProviderAlreadyExistsException;
use OCP\Federation\ICloudFederationProviderManager;
use OCP\OCM\Events\LocalOCMDiscoveryEvent;

class Application extends App implements IBootstrap {
	public const APP_ID = 'ocmremotewebapp';

	/** OCM resource type advertised via /.well-known/ocm. */
	public const WEBAPP_RESOURCE_TYPE = 'webapp';

	/** User-facing label in NC's federation provider registry. */
	public const WEBAPP_DISPLAY_NAME = 'OCM Remote WebApp';

	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct() {
		parent::__construct(self::APP_ID);
	}

	public function register(IRegistrationContext $context): void {
		$context->registerEventListener(LocalOCMDiscoveryEvent::class, LocalOCMDiscoveryEventListener::class);
	}

	public function boot(IBootContext $context): void {
		$context->injectFn(function (ICloudFederationProviderManager $manager): void {
			try {
				$manager->addCloudFederationProvider(
					self::WEBAPP_RESOURCE_TYPE,
					self::WEBAPP_DISPLAY_NAME,
					fn () => \OC::$server->get(WebappCloudFederationProvider::class),
				);
			} catch (ProviderAlreadyExistsException) {
				// already registered (e.g. double-boot in tests)
			}
		});
	}
}
