<?php

declare(strict_types=1);

namespace OCA\Tickbuddy\Settings;

use OCA\Tickbuddy\AppInfo\Application;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Settings\ISettings;

/**
 * @psalm-suppress UnusedClass
 */
class PersonalSettings implements ISettings {
	public function getForm(): TemplateResponse {
		return new TemplateResponse(Application::APP_ID, 'settings/personal');
	}

	public function getSection(): string {
		return Application::APP_ID;
	}

	public function getPriority(): int {
		return 50;
	}
}
