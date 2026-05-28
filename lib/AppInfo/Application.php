<?php

declare(strict_types=1);

namespace OCA\OCMRemoteWebApp\AppInfo;

use OCA\OCMRemoteWebApp\Listener\LocalOCMDiscoveryEventListener;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\OCM\Events\LocalOCMDiscoveryEvent;

class Application extends App implements IBootstrap {
	public const APP_ID = 'ocmremotewebapp';

	/** OCM resource type advertised via /.well-known/ocm. */
	public const WEBAPP_RESOURCE_TYPE = 'webapp';
	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct() {
		parent::__construct(self::APP_ID);
	}

	public function register(IRegistrationContext $context): void {
		$context->registerEventListener(LocalOCMDiscoveryEvent::class, LocalOCMDiscoveryEventListener::class);
	}

	public function boot(IBootContext $context): void {
	}
}
