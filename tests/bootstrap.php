<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

// Load Nextcloud's OCP stubs for the test runtime only.
// We don't add this to composer's autoload-dev because Psalm would then
// analyze every OCP stub file as project code, producing hundreds of
// false-positive errors in Nextcloud framework code.
spl_autoload_register(function (string $class): void {
	if (!str_starts_with($class, 'OCP\\')) {
		return;
	}
	$path = __DIR__ . '/../vendor/nextcloud/ocp/' . str_replace('\\', '/', $class) . '.php';
	if (is_file($path)) {
		require_once $path;
	}
});
