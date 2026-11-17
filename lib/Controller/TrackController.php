<?php

declare(strict_types=1);

namespace OCA\Tickbuddy\Controller;

use OCA\Tickbuddy\Db\Track;
use OCA\Tickbuddy\Db\TrackOption;
use OCA\Tickbuddy\ResponseDefinitions;
use OCA\Tickbuddy\Service\ErrorReason;
use OCA\Tickbuddy\Service\Flags;
use OCA\Tickbuddy\Service\IdempotencyService;
use OCA\Tickbuddy\Service\InvalidArchiveDateException;
use OCA\Tickbuddy\Service\InvalidTrackNameException;
use OCA\Tickbuddy\Service\InvalidTrackTypeException;
use OCA\Tickbuddy\Service\InvalidValueException;
use OCA\Tickbuddy\Service\OptionNotFoundException;
use OCA\Tickbuddy\Service\PublicLinkService;
use OCA\Tickbuddy\Service\ReadOnlyTrackException;
use OCA\Tickbuddy\Service\TrackLimitReachedException;
use OCA\Tickbuddy\Service\TrackOrderConflictException;
use OCA\Tickbuddy\Service\TrackService;
use OCA\Tickbuddy\Service\TrackSettings;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\DB\Exception as DbException;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

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
		private PublicLinkService $publicLinks,
		private IdempotencyService $idempotency,
		private string $userId,
		private LoggerInterface $logger,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Explicitly serialize a Track entity to an array. We do this manually
	 * because automatic Entity serialization in DataResponse does not
	 * reliably include protected properties across Nextcloud versions.
	 *
	 * Every type's settings are on every track, null where the type has none.
	 *
	 * @param int $publicLinkCount The track's live public links
	 * @param TrackOption[] $options A Choice's options, in place order
	 * @return TickbuddyTrack
	 */
	private function serializeTrack(Track $track, int $publicLinkCount, array $options = []): array {
		$isNumber = $track->getType() === 'number';
		$isChoice = $track->getType() === 'choice';
		return [
			'id' => $track->getId(),
			'name' => $track->getName(),
			'type' => $track->getType(),
			'sortOrder' => $track->getSortOrder(),
			'private' => $track->getPrivate() === 1,
			'archivedAt' => $track->getArchivedAt(),
			'publicLinkCount' => $publicLinkCount,
			'unit' => $isNumber ? ($track->getUnit() ?? '') : null,
			'decimalPlaces' => $isNumber ? ($track->getDecimalPlaces() ?? TrackService::DEFAULT_DECIMAL_PLACES) : null,
			'allowNegative' => $isNumber ? $track->getAllowNegative() === 1 : null,
			'options' => $isChoice
				? array_values(array_map(fn (TrackOption $o) => ['value' => $o->getPlace(), 'label' => $o->getLabel()], $options))
				: null,
			'orderedScale' => $isChoice ? $track->getOrderedScale() === 1 : null,
		];
	}

	/**
	 * One track, with its link count and options read for it.
	 *
	 * @return TickbuddyTrack
	 */
	private function serializeOne(Track $track): array {
		return $this->serializeTrack($track, $this->publicLinks->countForTrack($track->getId()), $this->trackService->options($track));
	}

	/**
	 * @param Track[] $tracks
	 * @return list<TickbuddyTrack>
	 */
	private function serializeTracks(array $tracks): array {
		// One query for every track's count, one for every track's options
		$counts = $this->publicLinks->countsByTrack($this->userId);
		$options = $this->trackService->optionsFor($tracks, $this->userId);
		return array_values(array_map(fn (Track $t) => $this->serializeTrack($t, $counts[$t->getId()] ?? 0, $options[$t->getId()] ?? []), $tracks));
	}

	/**
	 * List the tracks of the current user
	 *
	 * @param string|int|float|bool|null $includeArchived Whether to include archived tracks: true or false (also 1/0, on/off, yes/no)
	 * @param string|int|float|bool|null $includeAllTypes Whether to include tracks of every type, read as `includeArchived`. Without it only "boolean" and "counter" tracks are listed, so a client that predates a type never meets it
	 * @return DataResponse<Http::STATUS_OK, list<TickbuddyTrack>, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, TickbuddyError, array{}>
	 *
	 * 200: Tracks returned
	 * 400: includeArchived or includeAllTypes isn't true or false
	 */
	// Flags are unions for the framework: typed bool, Nextcloud would read "no" as true
	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/tracks')]
	public function index(string|int|float|bool|null $includeArchived = false, string|int|float|bool|null $includeAllTypes = false): DataResponse {
		try {
			$includeArchived = Flags::read('includeArchived', $includeArchived, false);
			$includeAllTypes = Flags::read('includeAllTypes', $includeAllTypes, false);
		} catch (InvalidValueException $e) {
			return new DataResponse(['message' => $e->getMessage(), 'reason' => ErrorReason::INVALID_VALUE], Http::STATUS_BAD_REQUEST);
		}
		return new DataResponse($this->serializeTracks($this->trackService->findAll($this->userId, $includeArchived, $includeAllTypes)));
	}

	/**
	 * Create a new track
	 *
	 * With an `Idempotency-Key` header (a UUID), a retry with the same key and
	 * the same parameters is answered as the first request was and changes
	 * nothing; see the `idempotencyKey` capability.
	 *
	 * @param string $name Track name
	 * @param string $type Track type: "boolean", "counter", "number" or "choice"
	 * @param ?string $unit Number only: the unit shown after the value, at most 255 characters; none if empty or missing
	 * @param string|int|float|bool|null $decimalPlaces Number only: 0, 1, 2 or 3; 1 if missing
	 * @param string|int|float|bool|null $allowNegative Number only: whether values below 0 are allowed: true or false (also 1/0, on/off, yes/no); false if missing
	 * @param ?list<string> $options Choice only, required: 2 to 99 labels in order, each at most 255 characters, no two the same ignoring case. An option's place (1 for the first) is the value a day stores
	 * @param string|int|float|bool|null $orderedScale Choice only: whether the options are a scale, read as `allowNegative`; false if missing
	 * @return DataResponse<Http::STATUS_CREATED, TickbuddyTrack, array{}>|DataResponse<Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_CONFLICT|Http::STATUS_UNPROCESSABLE_ENTITY, TickbuddyError, array{}>
	 *
	 * 201: Track created. With `Idempotency-Key`: a repeat of an earlier request answers as that request did, and changes nothing
	 * 400: Invalid name, type or setting, a setting of another type, or `Idempotency-Key` is not a UUID
	 * 403: Track limit reached
	 * 409: A request with the same `Idempotency-Key` is still being handled; try again shortly
	 * 422: This `Idempotency-Key` was used for another request; nothing written
	 */
	// Numbers and flags are unions for the framework, as on ticks: typed int or
	// bool, Nextcloud would read "no" as true and a JSON 1.5 as 1
	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/tracks')]
	public function create(
		string $name = '',
		string $type = '',
		?string $unit = null,
		string|int|float|bool|null $decimalPlaces = null,
		string|int|float|bool|null $allowNegative = null,
		?array $options = null,
		string|int|float|bool|null $orderedScale = null,
	): DataResponse {
		$settings = new TrackSettings($unit, $decimalPlaces, $allowNegative, $options, $orderedScale);
		return $this->idempotency->run($this->userId, 'tracks.create', [$name, $type, ...$settings->fingerprint()], function () use ($name, $type, $settings): DataResponse {
			try {
				$track = $this->trackService->create(trim($name), $type, $this->userId, $settings);
				// A new track has no links yet
				return new DataResponse($this->serializeTrack($track, 0, $this->trackService->options($track)), Http::STATUS_CREATED);
			} catch (InvalidTrackNameException|InvalidTrackTypeException|InvalidValueException $e) {
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
	 * @param string|int|float|bool|null $includeAllTypes Whether the answer lists tracks of every type, as on GET /api/tracks; the reorder itself always covers every track
	 * @return DataResponse<Http::STATUS_OK, list<TickbuddyTrack>, array{}>|DataResponse<Http::STATUS_BAD_REQUEST|Http::STATUS_CONFLICT|Http::STATUS_LOCKED, TickbuddyError, array{}>
	 *
	 * 200: Tracks reordered, active tracks returned in the new order. When an archived track is listed, without Prefer: handling=strict: nothing changed, active tracks in their current order
	 * 400: trackIds is required
	 * 409: Tracks kept being created or restored elsewhere while reordering; nothing changed, try again
	 * 423: An archived track is listed; nothing changed. Only for requests sent with Prefer: handling=strict, which otherwise get the 200 above
	 */
	// This literal path overlaps `PUT /api/tracks/{id}`. That route constrains
	// {id} to digits, so the two can never collide regardless of the order the
	// router registers them in — keep the requirement there.
	#[NoAdminRequired]
	#[ApiRoute(verb: 'PUT', url: '/api/tracks/reorder')]
	public function reorder(array $trackIds = [], string|int|float|bool|null $includeAllTypes = false): DataResponse {
		try {
			$includeAllTypes = Flags::read('includeAllTypes', $includeAllTypes, false);
		} catch (InvalidValueException $e) {
			return new DataResponse(['message' => $e->getMessage(), 'reason' => ErrorReason::INVALID_VALUE], Http::STATUS_BAD_REQUEST);
		}
		$ids = array_map('intval', $trackIds);
		if (empty($ids)) {
			return new DataResponse(['message' => 'trackIds is required', 'reason' => ErrorReason::INVALID_VALUE], Http::STATUS_BAD_REQUEST);
		}
		try {
			$tracks = $this->trackService->reorder($ids, $this->userId, $includeAllTypes);
		} catch (TrackOrderConflictException $e) {
			return new DataResponse(['message' => $e->getMessage(), 'reason' => ErrorReason::DATABASE_CONFLICT], Http::STATUS_CONFLICT);
		} catch (ReadOnlyTrackException $e) {
			return new DataResponse(['message' => $e->getMessage(), 'reason' => $e->getReason()], Http::STATUS_LOCKED);
		}
		return new DataResponse($this->serializeTracks($tracks));
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
	 * @param string|int|float|bool|null $private Whether the track is private: true or false (also 1/0, on/off, yes/no)
	 * @param string|int|float|bool|null $archived Archive (true) or restore (false) the track, read as `private` is. A restored track goes to the end of the list. Archiving an archived track is refused (423) for clients that send Prefer: handling=strict
	 * @param ?string $archivedAt Archive date (YYYY-MM-DD), the client's today; the server's date if omitted
	 * @param ?string $unit Number only: the new unit; empty removes it
	 * @param string|int|float|bool|null $decimalPlaces Number only: 0, 1, 2 or 3. Stored values don't change: fewer decimals show older days rounded
	 * @param string|int|float|bool|null $allowNegative Number only: whether new values below 0 are allowed, read as `private`. Days already stored keep their value
	 * @param string|int|float|bool|null $orderedScale Choice only: whether the options are a scale, read as `private`
	 * @return DataResponse<Http::STATUS_OK, TickbuddyTrack, array{}>|DataResponse<Http::STATUS_BAD_REQUEST|Http::STATUS_NOT_FOUND|Http::STATUS_CONFLICT|Http::STATUS_LOCKED, TickbuddyError, array{}>
	 *
	 * 200: Track updated. On an archived track, anything but a restore without Prefer: handling=strict: nothing changed, the unchanged track
	 * 400: Invalid name, archive date or setting, a setting of another type, or a flag isn't true or false; nothing changed
	 * 404: Track not found
	 * 409: Another change took the track's place in the list meanwhile (a sortOrder another track holds); nothing changed, try again
	 * 423: The track is archived and this isn't a restore; nothing changed. Only for requests sent with Prefer: handling=strict, which otherwise get the 200 above
	 */
	// Flags as in index(); null leaves the field as it is
	#[NoAdminRequired]
	#[ApiRoute(verb: 'PUT', url: '/api/tracks/{id}', requirements: ['id' => '\d+'])]
	public function update(
		int $id,
		?string $name = null,
		?int $sortOrder = null,
		string|int|float|bool|null $private = null,
		string|int|float|bool|null $archived = null,
		?string $archivedAt = null,
		?string $unit = null,
		string|int|float|bool|null $decimalPlaces = null,
		string|int|float|bool|null $allowNegative = null,
		string|int|float|bool|null $orderedScale = null,
	): DataResponse {
		try {
			$private = Flags::readOptional('private', $private);
			$archived = Flags::readOptional('archived', $archived);
			$settings = new TrackSettings($unit, $decimalPlaces, $allowNegative, null, $orderedScale);
			$track = $this->trackService->update($id, $this->userId, $name, $sortOrder, $private, $archived, $archivedAt, $settings);
			return new DataResponse($this->serializeOne($track));
		} catch (InvalidTrackNameException|InvalidArchiveDateException|InvalidValueException $e) {
			return new DataResponse(['message' => $e->getMessage(), 'reason' => ErrorReason::INVALID_VALUE], Http::STATUS_BAD_REQUEST);
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => 'Track not found', 'reason' => ErrorReason::TRACK_NOT_FOUND], Http::STATUS_NOT_FOUND);
		} catch (DbException $e) {
			// Only the unique index on the list's order is a conflict to try again;
			// anything else is the server's error. The driver's text stays in the log.
			if ($e->getReason() !== DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				throw $e;
			}
			$this->logger->info('A track update lost to another change', ['exception' => $e]);
			return new DataResponse(['message' => 'The tracks changed while saving; try again', 'reason' => ErrorReason::DATABASE_CONFLICT], Http::STATUS_CONFLICT);
		} catch (ReadOnlyTrackException $e) {
			return new DataResponse(['message' => $e->getMessage(), 'reason' => $e->getReason()], Http::STATUS_LOCKED);
		}
	}

	/**
	 * Rename an option of a Choice track
	 *
	 * The option keeps its place, so every day that holds it shows the new label.
	 * Options can't be added, removed or reordered.
	 *
	 * @param int $id Track ID
	 * @param int $value The option's place (1 for the first)
	 * @param string $label The new label, at most 255 characters, not another option's (ignoring case)
	 * @return DataResponse<Http::STATUS_OK, TickbuddyTrack, array{}>|DataResponse<Http::STATUS_BAD_REQUEST|Http::STATUS_NOT_FOUND|Http::STATUS_LOCKED, TickbuddyError, array{}>
	 *
	 * 200: Option renamed (or already had this label), the whole track
	 * 400: The track isn't a Choice, or the label is blank, too long or another option's; nothing changed
	 * 404: Track or option not found
	 * 423: The track is archived; nothing changed
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'PUT', url: '/api/tracks/{id}/options/{value}', requirements: ['id' => '\d+', 'value' => '\d+'])]
	public function renameOption(int $id, int $value, string $label = ''): DataResponse {
		try {
			return new DataResponse($this->serializeOne($this->trackService->renameOption($id, $this->userId, $value, $label)));
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => 'Track not found', 'reason' => ErrorReason::TRACK_NOT_FOUND], Http::STATUS_NOT_FOUND);
		} catch (OptionNotFoundException $e) {
			return new DataResponse(['message' => $e->getMessage(), 'reason' => ErrorReason::OPTION_NOT_FOUND], Http::STATUS_NOT_FOUND);
		} catch (InvalidTrackTypeException $e) {
			return new DataResponse(['message' => $e->getMessage(), 'reason' => ErrorReason::WRONG_TRACK_TYPE], Http::STATUS_BAD_REQUEST);
		} catch (InvalidValueException $e) {
			return new DataResponse(['message' => $e->getMessage(), 'reason' => ErrorReason::INVALID_VALUE], Http::STATUS_BAD_REQUEST);
		} catch (ReadOnlyTrackException $e) {
			return new DataResponse(['message' => $e->getMessage(), 'reason' => $e->getReason()], Http::STATUS_LOCKED);
		}
	}

	/**
	 * Delete a track and its ticks, notes, options and public links
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
