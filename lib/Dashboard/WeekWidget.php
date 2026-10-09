<?php

declare(strict_types=1);

namespace OCA\Tickbuddy\Dashboard;

use OCA\Tickbuddy\AppInfo\Application;
use OCP\Dashboard\IIconWidget;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Util;

/**
 * Read-only dashboard widget: the last seven days of each non-private track,
 * plus its current streak or break. Rendered client-side by
 * src/components/DashboardWidget.vue.
 */
class WeekWidget implements IIconWidget {
	/** Stored in every user's dashboard layout, so it can never be renamed. */
	public const ID = 'tickbuddy-week';

	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct(
		private IL10N $l,
		private IURLGenerator $urlGenerator,
	) {
	}

	public function getId(): string {
		return self::ID;
	}

	public function getTitle(): string {
		return $this->l->t('Tickbuddy');
	}

	public function getOrder(): int {
		return 50;
	}

	public function getIconClass(): string {
		return 'icon-tickbuddy-widget';
	}

	public function getIconUrl(): string {
		return $this->urlGenerator->getAbsoluteURL(
			$this->urlGenerator->imagePath(Application::APP_ID, 'app-dark.svg'),
		);
	}

	public function getUrl(): ?string {
		return $this->urlGenerator->linkToRouteAbsolute('tickbuddy.page.index');
	}

	/**
	 * Runs on every dashboard page load, whether or not the user has added the
	 * widget, so the entry it adds stays tiny and lazy-loads the widget itself.
	 */
	public function load(): void {
		// After the dashboard's own script, so OCA.Dashboard exists when ours runs.
		Util::addScript(Application::APP_ID, Application::APP_ID . '-dashboard', 'dashboard');
		Util::addStyle(Application::APP_ID, Application::APP_ID . '-dashboard');
	}
}
