<?php

declare(strict_types=1);

namespace OCA\Tickbuddy\Controller;

use OCA\Tickbuddy\ResponseDefinitions;
use OCA\Tickbuddy\Service\ExportService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;

/**
 * @psalm-import-type TickbuddyExport from ResponseDefinitions
 *
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

	/**
	 * Export all of the current user's data as JSON
	 *
	 * @param bool $includePrivate Whether to include tracks marked as private
	 * @return DataResponse<Http::STATUS_OK, TickbuddyExport, array{}>
	 *
	 * 200: Export returned
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/export')]
	public function export(bool $includePrivate = false): DataResponse {
		$data = $this->exportService->export($this->userId, $includePrivate);
		return new DataResponse($data);
	}
}
