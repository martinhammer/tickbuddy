<?php

declare(strict_types=1);

namespace OCA\Tickbuddy\Controller;

use OCA\Tickbuddy\Db\Track;
use OCA\Tickbuddy\Service\InvalidTrackNameException;
use OCA\Tickbuddy\Service\InvalidTrackTypeException;
use OCA\Tickbuddy\Service\TrackLimitReachedException;
use OCA\Tickbuddy\Service\TrackService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\DB\Exception as DbException;
use OCP\IRequest;

/**
 * @psalm-suppress UnusedClass
 */
class TrackController extends OCSController {
	public function __construct(
		string $appName,
		IRequest $request,
		private TrackService $trackService,
		private string $userId,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Explicitly serialize a Track entity to an array. We do this manually
	 * because automatic Entity serialization in DataResponse does not
	 * reliably include protected properties across Nextcloud versions.
	 */
	private function serializeTrack(Track $track): array {
		return [
			'id' => $track->getId(),
			'name' => $track->getName(),
			'type' => $track->getType(),
			'sortOrder' => $track->getSortOrder(),
		];
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/tracks')]
	public function index(): DataResponse {
		$tracks = $this->trackService->findAll($this->userId);
		return new DataResponse(array_map(fn (Track $t) => $this->serializeTrack($t), $tracks));
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/tracks')]
	public function create(): DataResponse {
		$name = trim((string)$this->request->getParam('name', ''));
		$type = (string)$this->request->getParam('type', '');

		try {
			$track = $this->trackService->create($name, $type, $this->userId);
			return new DataResponse($this->serializeTrack($track), Http::STATUS_CREATED);
		} catch (InvalidTrackNameException|InvalidTrackTypeException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (TrackLimitReachedException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		}
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'PUT', url: '/api/tracks/{id}')]
	public function update(int $id): DataResponse {
		$nameParam = $this->request->getParam('name');
		$sortOrderParam = $this->request->getParam('sortOrder');
		$name = $nameParam !== null ? (string)$nameParam : null;
		$sortOrder = $sortOrderParam !== null ? (int)$sortOrderParam : null;

		try {
			$track = $this->trackService->update($id, $this->userId, $name, $sortOrder);
			return new DataResponse($this->serializeTrack($track));
		} catch (\OCP\AppFramework\Db\DoesNotExistException) {
			return new DataResponse(['message' => 'Track not found'], Http::STATUS_NOT_FOUND);
		} catch (DbException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_CONFLICT);
		}
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'DELETE', url: '/api/tracks/{id}')]
	public function destroy(int $id): DataResponse {
		try {
			$this->trackService->delete($id, $this->userId);
			return new DataResponse(null, Http::STATUS_NO_CONTENT);
		} catch (\OCP\AppFramework\Db\DoesNotExistException) {
			return new DataResponse(['message' => 'Track not found'], Http::STATUS_NOT_FOUND);
		}
	}
}
