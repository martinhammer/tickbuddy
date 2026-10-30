<?php

declare(strict_types=1);

namespace OCA\Tickbuddy\Settings;

use OCA\Tickbuddy\AppInfo\Application;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IURLGenerator;
use OCP\Settings\ISettings;

/**
 * Tracks moved into the app; this section only links there now.
 *
 * @psalm-suppress UnusedClass
 */
class PersonalSettings implements ISettings {
	public function __construct(
		private IURLGenerator $urlGenerator,
	) {
	}

	public function getForm(): TemplateResponse {
		return new TemplateResponse(Application::APP_ID, 'settings/personal', [
			'tracksUrl' => $this->urlGenerator->linkToRoute(Application::APP_ID . '.page.index') . '#tracks',
		]);
	}

	public function getSection(): string {
		return Application::APP_ID;
	}

	public function getPriority(): int {
		return 50;
	}
}
