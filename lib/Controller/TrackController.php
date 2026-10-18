<?php

declare(strict_types=1);

namespace OCA\Tickbuddy\Controller;

use OCA\Tickbuddy\Db\Track;
use OCA\Tickbuddy\ResponseDefinitions;
use OCA\Tickbuddy\Service\InvalidArchiveDateException;
use OCA\Tickbuddy\Service\InvalidTrackNameException;
use OCA\Tickbuddy\Service\InvalidTrackTypeException;
use OCA\Tickbuddy\Service\TrackLimitReachedException;
use OCA\Tickbuddy\Service\TrackService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\DB\Exception as DbException;
use OCP\IRequest;

/**
 * @psalm-import-type TickbuddyTrack from ResponseDefinitions
 *
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
	 *
	 * @return TickbuddyTrack
	 */
	private function serializeTrack(Track $track): array {
		return [
			'id' => $track->getId(),
			'name' => $track->getName(),
			'type' => $track->getType(),
			'sortOrder' => $track->getSortOrder(),
			'private' => $track->getPrivate() === 1,
			'archivedAt' => $track->getArchivedAt(),
		];
	}

	/**
	 * List the tracks of the current user
	 *
	 * @param bool $includeArchived Whether to include archived tracks
	 * @return DataResponse<Http::STATUS_OK, list<TickbuddyTrack>, array{}>
	 *
	 * 200: Tracks returned
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/tracks')]
	public function index(bool $includeArchived = false): DataResponse {
		$tracks = $this->trackService->findAll($this->userId, $includeArchived);
		return new DataResponse(array_values(array_map(fn (Track $t) => $this->serializeTrack($t), $tracks)));
	}

	/**
	 * Create a new track
	 *
	 * @param string $name Track name
	 * @param string $type Track type, either "boolean" or "counter"
	 * @return DataResponse<Http::STATUS_CREATED, TickbuddyTrack, array{}>|DataResponse<Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN, array{message: string}, array{}>
	 *
	 * 201: Track created
	 * 400: Invalid name or type
	 * 403: Track limit reached
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/tracks')]
	public function create(string $name = '', string $type = ''): DataResponse {
		try {
			$track = $this->trackService->create(trim($name), $type, $this->userId);
			return new DataResponse($this->serializeTrack($track), Http::STATUS_CREATED);
		} catch (InvalidTrackNameException|InvalidTrackTypeException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (TrackLimitReachedException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		}
	}

	/**
	 * Reorder tracks
	 *
	 * The listed tracks come first, in the order sent; every other track of the
	 * user (archived ones included) follows in its previous order.
	 *
	 * @param list<int> $trackIds Ordered list of track IDs
	 * @return DataResponse<Http::STATUS_OK, list<TickbuddyTrack>, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, array{message: string}, array{}>
	 *
	 * 200: Tracks reordered, active tracks returned in the new order
	 * 400: trackIds is required
	 */
	// This literal path overlaps `PUT /api/tracks/{id}`. That route constrains
	// {id} to digits, so the two can never collide regardless of the order the
	// router registers them in — keep the requirement there.
	#[NoAdminRequired]
	#[ApiRoute(verb: 'PUT', url: '/api/tracks/reorder')]
	public function reorder(array $trackIds = []): DataResponse {
		$ids = array_map('intval', $trackIds);
		if (empty($ids)) {
			return new DataResponse(['message' => 'trackIds is required'], Http::STATUS_BAD_REQUEST);
		}
		$tracks = $this->trackService->reorder($ids, $this->userId);
		return new DataResponse(array_values(array_map(fn (Track $t) => $this->serializeTrack($t), $tracks)));
	}

	/**
	 * Update a track
	 *
	 * @param int $id Track ID
	 * @param ?string $name New track name
	 * @param ?int $sortOrder New sort order
	 * @param ?bool $private Whether the track is private
	 * @param ?bool $archived Archive (true) or restore (false) the track. A restored track goes to the end of the list.
	 * @param ?string $archivedAt Archive date (YYYY-MM-DD), the client's today; the server's date if omitted
	 * @return DataResponse<Http::STATUS_OK, TickbuddyTrack, array{}>|DataResponse<Http::STATUS_BAD_REQUEST|Http::STATUS_NOT_FOUND|Http::STATUS_CONFLICT, array{message: string}, array{}>
	 *
	 * 200: Track updated
	 * 400: Invalid name or archive date
	 * 404: Track not found
	 * 409: Database conflict
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'PUT', url: '/api/tracks/{id}', requirements: ['id' => '\d+'])]
	public function update(
		int $id,
		?string $name = null,
		?int $sortOrder = null,
		?bool $private = null,
		?bool $archived = null,
		?string $archivedAt = null,
	): DataResponse {
		try {
			$track = $this->trackService->update($id, $this->userId, $name, $sortOrder, $private, $archived, $archivedAt);
			return new DataResponse($this->serializeTrack($track));
		} catch (InvalidTrackNameException|InvalidArchiveDateException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => 'Track not found'], Http::STATUS_NOT_FOUND);
		} catch (DbException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_CONFLICT);
		}
	}

	/**
	 * Delete a track and its ticks
	 *
	 * @param int $id Track ID
	 * @return DataResponse<Http::STATUS_NO_CONTENT, null, array{}>|DataResponse<Http::STATUS_NOT_FOUND, array{message: string}, array{}>
	 *
	 * 204: Track deleted
	 * 404: Track not found
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'DELETE', url: '/api/tracks/{id}', requirements: ['id' => '\d+'])]
	public function destroy(int $id): DataResponse {
		try {
			$this->trackService->delete($id, $this->userId);
			return new DataResponse(null, Http::STATUS_NO_CONTENT);
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => 'Track not found'], Http::STATUS_NOT_FOUND);
		}
	}
}
