<?php

declare(strict_types=1);

namespace OCA\Tickbuddy\Controller;

use OCA\Tickbuddy\AppInfo\Application;
use OCA\Tickbuddy\ResponseDefinitions;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\Config\IUserConfig;
use OCP\IRequest;

/**
 * @psalm-import-type TickbuddyPreferences from ResponseDefinitions
 *
 * @psalm-suppress UnusedClass
 */
class PreferencesController extends OCSController {
	private const VALID_VIEWS = ['journal', 'readonly', 'analytics'];

	public function __construct(
		string $appName,
		IRequest $request,
		private IUserConfig $config,
		private string $userId,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Get the current user's preferences
	 *
	 * @return DataResponse<Http::STATUS_OK, TickbuddyPreferences, array{}>
	 *
	 * 200: Preferences returned
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/preferences')]
	public function index(): DataResponse {
		$defaultView = $this->config->getValueString(
			$this->userId,
			Application::APP_ID,
			'default_view',
			'journal',
		);
		return new DataResponse(['defaultView' => $defaultView]);
	}

	/**
	 * Update the current user's preferences
	 *
	 * @param string $defaultView Default view; one of "journal", "readonly", "analytics"
	 * @return DataResponse<Http::STATUS_OK, TickbuddyPreferences, array{}>
	 *
	 * 200: Preferences updated
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'PUT', url: '/api/preferences')]
	public function update(string $defaultView = 'journal'): DataResponse {
		if (!in_array($defaultView, self::VALID_VIEWS, true)) {
			$defaultView = 'journal';
		}

		$this->config->setValueString(
			$this->userId,
			Application::APP_ID,
			'default_view',
			$defaultView,
		);
		return new DataResponse(['defaultView' => $defaultView]);
	}
}
