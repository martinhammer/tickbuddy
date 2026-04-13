<?php

declare(strict_types=1);

use OCP\Util;

Util::addScript(OCA\Tickbuddy\AppInfo\Application::APP_ID, OCA\Tickbuddy\AppInfo\Application::APP_ID . '-settings');
Util::addStyle(OCA\Tickbuddy\AppInfo\Application::APP_ID, OCA\Tickbuddy\AppInfo\Application::APP_ID . '-settings');

?>

<div id="tickbuddy-settings"></div>
