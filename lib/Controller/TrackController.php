<?php

declare(strict_types=1);

namespace OCA\Tickbuddy\Controller;

use OCA\Tickbuddy\Db\Track;
use OCA\Tickbuddy\ResponseDefinitions;
use OCA\Tickbuddy\Service\ErrorReason;
use OCA\Tickbuddy\Service\IdempotencyService;
use OCA\Tickbuddy\Service\InvalidArchiveDateException;
use OCA\Tickbuddy\Service\InvalidTrackNameException;
use OCA\Tickbuddy\Service\InvalidTrackTypeException;
use OCA\Tickbuddy\Service\ReadOnlyTrackException;
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
 * @psalm-import-type TickbuddyError from ResponseDefinitions
 * @psalm-import-type TickbuddyTrack from ResponseDefinitions
 *
 * @psalm-suppress UnusedClass
 */
class TrackController extends OCSController {
	public function __construct(
		string $appName,
		IRequest $request,
		private TrackService $trackService,
		private IdempotencyService $idempotency,
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
	 * With an `Idempotency-Key` header (a UUID), a retry with the same key and
	 * the same parameters is answered as the first request was and changes
	 * nothing; see the `idempotencyKey` capability.
	 *
	 * @param string $name Track name
	 * @param string $type Track type, either "boolean" or "counter"
	 * @return DataResponse<Http::STATUS_CREATED, TickbuddyTrack, array{}>|DataResponse<Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_CONFLICT|Http::STATUS_UNPROCESSABLE_ENTITY, TickbuddyError, array{}>
	 *
	 * 201: Track created. With `Idempotency-Key`: a repeat of an earlier request answers as that request did, and changes nothing
	 * 400: Invalid name or type, or `Idempotency-Key` is not a UUID
	 * 403: Track limit reached
	 * 409: A request with the same `Idempotency-Key` is still being handled; try again shortly
	 * 422: This `Idempotency-Key` was used for another request; nothing written
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/tracks')]
	public function create(string $name = '', string $type = ''): DataResponse {
		return $this->idempotency->run($this->userId, 'tracks.create', [$name, $type], function () use ($name, $type): DataResponse {
			try {
				$track = $this->trackService->create(trim($name), $type, $this->userId);
				return new DataResponse($this->serializeTrack($track), Http::STATUS_CREATED);
			} catch (InvalidTrackNameException|InvalidTrackTypeException $e) {
				// An unknown type is a bad value here, not a wrong endpoint as for ticks
				return new DataResponse(['message' => $e->getMessage(), 'reason' => ErrorReason::INVALID_VALUE], Http::STATUS_BAD_REQUEST);
			} catch (TrackLimitReachedException $e) {
				return new DataResponse(['message' => $e->getMessage(), 'reason' => ErrorReason::TRACK_LIMIT_REACHED], Http::STATUS_FORBIDDEN);
			}
		});
	}

	/**
	 * Reorder tracks
	 *
	 * The listed tracks come first, in the order sent; every other track of the
	 * user (archived ones included) follows in its previous order. Ids that
	 * aren't the user's are ignored. A list naming an archived track changes
	 * nothing: requests sent with Prefer: handling=strict get 423, others the
	 * active tracks in their current order.
	 *
	 * @param list<int> $trackIds Ordered list of track IDs
	 * @return DataResponse<Http::STATUS_OK, list<TickbuddyTrack>, array{}>|DataResponse<Http::STATUS_BAD_REQUEST|Http::STATUS_LOCKED, TickbuddyError, array{}>
	 *
	 * 200: Tracks reordered, active tracks returned in the new order. When an archived track is listed, without Prefer: handling=strict: nothing changed, active tracks in their current order
	 * 400: trackIds is required
	 * 423: An archived track is listed; nothing changed. Only for requests sent with Prefer: handling=strict, which otherwise get the 200 above
	 */
	// This literal path overlaps `PUT /api/tracks/{id}`. That route constrains
	// {id} to digits, so the two can never collide regardless of the order the
	// router registers them in — keep the requirement there.
	#[NoAdminRequired]
	#[ApiRoute(verb: 'PUT', url: '/api/tracks/reorder')]
	public function reorder(array $trackIds = []): DataResponse {
		$ids = array_map('intval', $trackIds);
		if (empty($ids)) {
			return new DataResponse(['message' => 'trackIds is required', 'reason' => ErrorReason::INVALID_VALUE], Http::STATUS_BAD_REQUEST);
		}
		try {
			$tracks = $this->trackService->reorder($ids, $this->userId);
		} catch (ReadOnlyTrackException $e) {
			return new DataResponse(['message' => $e->getMessage(), 'reason' => $e->getReason()], Http::STATUS_LOCKED);
		}
		return new DataResponse(array_values(array_map(fn (Track $t) => $this->serializeTrack($t), $tracks)));
	}

	/**
	 * Update a track
	 *
	 * Change one thing per call. Several fields in one call are applied together,
	 * but this is strongly discouraged: an archived track refuses every change
	 * except a restore (`archived=false`) for clients that send
	 * `Prefer: handling=strict`, and a restore sent with a new name or private
	 * restores first and then applies them. Other clients get the unchanged
	 * track instead of the refusal.
	 *
	 * @param int $id Track ID
	 * @param ?string $name New track name
	 * @param ?int $sortOrder New sort order
	 * @param ?bool $private Whether the track is private
	 * @param ?bool $archived Archive (true) or restore (false) the track. A restored track goes to the end of the list. Archiving an archived track is refused (423) for clients that send Prefer: handling=strict
	 * @param ?string $archivedAt Archive date (YYYY-MM-DD), the client's today; the server's date if omitted
	 * @return DataResponse<Http::STATUS_OK, TickbuddyTrack, array{}>|DataResponse<Http::STATUS_BAD_REQUEST|Http::STATUS_NOT_FOUND|Http::STATUS_CONFLICT|Http::STATUS_LOCKED, TickbuddyError, array{}>
	 *
	 * 200: Track updated. On an archived track, anything but a restore without Prefer: handling=strict: nothing changed, the unchanged track
	 * 400: Invalid name or archive date
	 * 404: Track not found
	 * 409: Database conflict
	 * 423: The track is archived and this isn't a restore; nothing changed. Only for requests sent with Prefer: handling=strict, which otherwise get the 200 above
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
			return new DataResponse(['message' => $e->getMessage(), 'reason' => ErrorReason::INVALID_VALUE], Http::STATUS_BAD_REQUEST);
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => 'Track not found', 'reason' => ErrorReason::TRACK_NOT_FOUND], Http::STATUS_NOT_FOUND);
		} catch (DbException $e) {
			return new DataResponse(['message' => $e->getMessage(), 'reason' => ErrorReason::DATABASE_CONFLICT], Http::STATUS_CONFLICT);
		} catch (ReadOnlyTrackException $e) {
			return new DataResponse(['message' => $e->getMessage(), 'reason' => $e->getReason()], Http::STATUS_LOCKED);
		}
	}

	/**
	 * Delete a track and its ticks
	 *
	 * @param int $id Track ID
	 * @return DataResponse<Http::STATUS_NO_CONTENT, null, array{}>|DataResponse<Http::STATUS_NOT_FOUND, TickbuddyError, array{}>
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
			return new DataResponse(['message' => 'Track not found', 'reason' => ErrorReason::TRACK_NOT_FOUND], Http::STATUS_NOT_FOUND);
		}
	}
}
