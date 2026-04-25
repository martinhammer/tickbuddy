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
	private const MAX_UPLOAD_BYTES = 20 * 1024 * 1024;

	public function __construct(
		string $appName,
		IRequest $request,
		private ImportService $importService,
		private string $userId,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * @return array{file: array{tmp_name: string, size: int, error: int}, mode: string}|DataResponse
	 * @psalm-suppress MixedReturnTypeCoercion
	 */
	private function validateUpload(): array|DataResponse {
		$mode = (string)$this->request->getParam('mode', '');
		$file = $this->request->getUploadedFile('file');

		if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
			return new DataResponse(['message' => 'No file uploaded or upload error'], Http::STATUS_BAD_REQUEST);
		}

		if (($file['size'] ?? 0) > self::MAX_UPLOAD_BYTES) {
			return new DataResponse(['message' => 'File too large (max 20 MB).'], Http::STATUS_BAD_REQUEST);
		}

		if (!in_array($mode, [ImportService::MODE_REPLACE, ImportService::MODE_MERGE], true)) {
			return new DataResponse(['message' => 'Invalid mode. Use "replace" or "merge".'], Http::STATUS_BAD_REQUEST);
		}

		return ['file' => $file, 'mode' => $mode];
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/import')]
	public function import(): DataResponse {
		$validated = $this->validateUpload();
		if ($validated instanceof DataResponse) {
			return $validated;
		}

		try {
			$result = $this->importService->importTickmate($validated['file']['tmp_name'], $validated['mode'], $this->userId);
			return new DataResponse($result);
		} catch (ImportException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/import/json')]
	public function importJson(): DataResponse {
		$validated = $this->validateUpload();
		if ($validated instanceof DataResponse) {
			return $validated;
		}

		try {
			$result = $this->importService->importJson($validated['file']['tmp_name'], $validated['mode'], $this->userId);
			return new DataResponse($result);
		} catch (ImportException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}
}
