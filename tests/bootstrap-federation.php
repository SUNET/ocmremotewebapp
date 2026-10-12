<?php

declare(strict_types=1);

define('OCMREMOTEWEBAPP_STANDALONE_TESTS', true);

$serverRoot = getenv('NEXTCLOUD_SERVER_ROOT') ?: dirname(__DIR__, 3);
if (!is_file($serverRoot . '/3rdparty/autoload.php')) {
	throw new RuntimeException('Set NEXTCLOUD_SERVER_ROOT to a Nextcloud checkout with installed dependencies.');
}

// Load the public APIs without booting a server or connecting to its database.
require_once $serverRoot . '/3rdparty/autoload.php';
$loader = new \Composer\Autoload\ClassLoader();
$loader->addPsr4('OCP\\', $serverRoot . '/lib/public/');
$loader->addPsr4('OCA\\OCMRemoteWebApp\\Tests\\Unit\\', __DIR__ . '/unit/');
$loader->register();
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/stubs/OC.php';
