<?php

declare(strict_types=1);

use OCP\Util;

Util::addScript(OCA\Tickbuddy\AppInfo\Application::APP_ID, OCA\Tickbuddy\AppInfo\Application::APP_ID . '-main');
Util::addStyle(OCA\Tickbuddy\AppInfo\Application::APP_ID, OCA\Tickbuddy\AppInfo\Application::APP_ID . '-main');

?>

<div id="tickbuddy"></div>
