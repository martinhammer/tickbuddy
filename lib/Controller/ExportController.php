<?php

declare(strict_types=1);

namespace OCA\Tickbuddy\Controller;

use OCA\Tickbuddy\Service\ExportService;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;

/**
 * @psalm-suppress UnusedClass
 */
class ExportController extends OCSController {
	public function __construct(
		string $appName,
		IRequest $request,
		private ExportService $exportService,
		private string $userId,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/export')]
	public function export(): DataResponse {
		$includePrivate = filter_var(
			$this->request->getParam('includePrivate', 'false'),
			FILTER_VALIDATE_BOOLEAN,
		);

		$data = $this->exportService->export($this->userId, $includePrivate);
		return new DataResponse($data);
	}
}
