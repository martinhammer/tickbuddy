<?php

declare(strict_types=1);

namespace OCA\Tickbuddy\Settings;

use OCA\Tickbuddy\AppInfo\Application;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

/**
 * @psalm-suppress UnusedClass
 */
class PersonalSection implements IIconSection {
	public function __construct(
		private IURLGenerator $urlGenerator,
		private IL10N $l,
	) {
	}

	public function getID(): string {
		return Application::APP_ID;
	}

	public function getName(): string {
		return $this->l->t('Tickbuddy');
	}

	public function getPriority(): int {
		return 50;
	}

	public function getIcon(): string {
		return $this->urlGenerator->imagePath(Application::APP_ID, 'app-dark.svg');
	}
}
