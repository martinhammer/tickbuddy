<?php

declare(strict_types=1);

namespace OCA\Tickbuddy\Controller;

use OCA\Tickbuddy\ResponseDefinitions;
use OCA\Tickbuddy\Service\ErrorReason;
use OCA\Tickbuddy\Service\ExportService;
use OCA\Tickbuddy\Service\Flags;
use OCA\Tickbuddy\Service\InvalidValueException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;

/**
 * @psalm-import-type TickbuddyError from ResponseDefinitions
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
	 * @param string|int|float|bool|null $includePrivate Whether to include tracks marked as private: true or false (also 1/0, on/off, yes/no)
	 * @return DataResponse<Http::STATUS_OK, TickbuddyExport, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, TickbuddyError, array{}>
	 *
	 * 200: Export returned
	 * 400: includePrivate isn't true or false
	 */
	// The flag is a union for the framework: typed bool, Nextcloud would read "no" as true
	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/export')]
	public function export(string|int|float|bool|null $includePrivate = false): DataResponse {
		try {
			$includePrivate = Flags::read('includePrivate', $includePrivate, false);
		} catch (InvalidValueException $e) {
			return new DataResponse(['message' => $e->getMessage(), 'reason' => ErrorReason::INVALID_VALUE], Http::STATUS_BAD_REQUEST);
		}
		$data = $this->exportService->export($this->userId, $includePrivate);
		return new DataResponse($data);
	}
}
