<?php

declare(strict_types=1);

namespace OCA\Tickbuddy\Controller;

use OCA\Tickbuddy\Service\ImportException;
use OCA\Tickbuddy\Service\ImportService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;

/**
 * @psalm-suppress UnusedClass
 */
class ImportController extends OCSController {
	public function __construct(
		string $appName,
		IRequest $request,
		private ImportService $importService,
		private string $userId,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/import')]
	public function import(): DataResponse {
		$mode = (string)$this->request->getParam('mode', '');
		$file = $this->request->getUploadedFile('file');

		if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
			return new DataResponse(['message' => 'No file uploaded or upload error'], Http::STATUS_BAD_REQUEST);
		}

		if (!in_array($mode, [ImportService::MODE_REPLACE, ImportService::MODE_MERGE], true)) {
			return new DataResponse(['message' => 'Invalid mode. Use "replace" or "merge".'], Http::STATUS_BAD_REQUEST);
		}

		try {
			$result = $this->importService->importTickmate($file['tmp_name'], $mode, $this->userId);
			return new DataResponse($result);
		} catch (ImportException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}
}
