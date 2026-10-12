<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../tests/bootstrap.php';
$loader = require __DIR__ . '/../vendor/autoload.php';
$loader->addPsr4('OCA\\OCMRemoteWebApp\\Tests\\Unit\\', __DIR__ . '/unit/');

\OC_App::loadApp(OCA\OCMRemoteWebApp\AppInfo\Application::APP_ID);
OC_Hook::clear();
