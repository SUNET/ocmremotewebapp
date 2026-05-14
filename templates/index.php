<?php

declare(strict_types=1);

use OCP\Util;

Util::addScript(OCA\OCMRemoteWebApp\AppInfo\Application::APP_ID, OCA\OCMRemoteWebApp\AppInfo\Application::APP_ID . '-main');
Util::addStyle(OCA\OCMRemoteWebApp\AppInfo\Application::APP_ID, OCA\OCMRemoteWebApp\AppInfo\Application::APP_ID . '-main');

?>

<div id="ocmremotewebapp"></div>
