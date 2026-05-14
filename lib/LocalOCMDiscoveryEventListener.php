<?php

declare(strict_types=1);

namespace OCA\OCMRemoteWebApp\Listener;

use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\OCM\Events\LocalOCMDiscoveryEvent;

/**
 * @template-implements IEventListener<LocalOCMDiscoveryEvent>
 */
class LocalOCMDiscoveryEventListener implements IEventListener {

    public function __construct(
        private LoggerInterface $logger,
        private IUserSession $userSession,
    ) {
    }

    public function handle(Event $event): void {
        if (!($event instanceof LocalOCMDiscoveryEvent)) {
            return;
        }

        // FIXME
        $event->addCapability('accept-webapp-iframe');
        $event->addCapability('accept-webapp-popup');
        $event->addCapability('accept-webapp-redirect');
        $event->registerResourceType('webapp', ['user'], ['webapp' => '/remote/webapp/ocm']);
    }
}
