<?php

declare(strict_types=1);

namespace OCA\Tickbuddy\Controller;

use OCA\Tickbuddy\AppInfo\Application;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IConfig;
use OCP\IRequest;

/**
 * @psalm-suppress UnusedClass
 */
class PreferencesController extends OCSController {
	private const VALID_VIEWS = ['journal', 'readonly', 'analytics'];

	public function __construct(
		string $appName,
		IRequest $request,
		private IConfig $config,
		private string $userId,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/preferences')]
	public function index(): DataResponse {
		$defaultView = $this->config->getUserValue(
			$this->userId,
			Application::APP_ID,
			'default_view',
			'journal',
		);
		return new DataResponse(['defaultView' => $defaultView]);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'PUT', url: '/api/preferences')]
	public function update(): DataResponse {
		$defaultView = (string)$this->request->getParam('defaultView', 'journal');
		if (!in_array($defaultView, self::VALID_VIEWS, true)) {
			$defaultView = 'journal';
		}

		$this->config->setUserValue(
			$this->userId,
			Application::APP_ID,
			'default_view',
			$defaultView,
		);
		return new DataResponse(['defaultView' => $defaultView]);
	}
}
