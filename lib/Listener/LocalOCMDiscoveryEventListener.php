<?php

declare(strict_types=1);

namespace OCA\OCMRemoteWebApp\Listener;

use OCA\OCMRemoteWebApp\AppInfo\Application;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\OCM\Events\LocalOCMDiscoveryEvent;

/**
 * @template-implements IEventListener<LocalOCMDiscoveryEvent>
 */
class LocalOCMDiscoveryEventListener implements IEventListener {

	public function handle(Event $event): void {
		if (!($event instanceof LocalOCMDiscoveryEvent)) {
			return;
		}

		// Receive-only app: per cs3org/OCM-API#367 we advertise
		// `webapp-receive` and NOT `webapp` (which is the sender-side
		// marker). Inbound shares reach us via cloud_federation_api,
		// which dispatches by resourceType to the provider registered
		// in Application::boot(); no per-app path needs advertising.
		$event->registerResourceType(
			Application::WEBAPP_RESOURCE_TYPE,
			['user'],
			[
				'webapp-receive' => [
					'targets' => ['blank', 'iframe'],
				],
			],
		);
	}
}
