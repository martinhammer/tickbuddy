<?php

declare(strict_types=1);

namespace OCA\Tickbuddy\Controller;

use OCA\Tickbuddy\Db\Tick;
use OCA\Tickbuddy\ResponseDefinitions;
use OCA\Tickbuddy\Service\ErrorReason;
use OCA\Tickbuddy\Service\Flags;
use OCA\Tickbuddy\Service\IdempotencyService;
use OCA\Tickbuddy\Service\InvalidTrackTypeException;
use OCA\Tickbuddy\Service\InvalidValueException;
use OCA\Tickbuddy\Service\NoteConflictException;
use OCA\Tickbuddy\Service\ReadOnlyTrackException;
use OCA\Tickbuddy\Service\TickService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;

/**
 * @psalm-import-type TickbuddyError from ResponseDefinitions
 * @psalm-import-type TickbuddyTick from ResponseDefinitions
 * @psalm-import-type TickbuddyTickBounds from ResponseDefinitions
 *
 * @psalm-suppress UnusedClass
 */
class TickController extends OCSController {
	public function __construct(
		string $appName,
		IRequest $request,
		private TickService $tickService,
		private IdempotencyService $idempotency,
		private string $userId,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * @return TickbuddyTick
	 */
	private function serializeTick(Tick $tick): array {
		return [
			'id' => $tick->getId(),
			'trackId' => $tick->getTrackId(),
			'date' => $tick->getDate(),
			'value' => $tick->getValue(),
		];
	}

	/**
	 * List ticks within a date range
	 *
	 * @param string $from Start date (YYYY-MM-DD), inclusive
	 * @param string $to End date (YYYY-MM-DD), inclusive; the same as `from` for one day
	 * @return DataResponse<Http::STATUS_OK, list<TickbuddyTick>, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, TickbuddyError, array{}>
	 *
	 * 200: Ticks returned
	 * 400: From or to is missing or not a date, or from is after to
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/ticks')]
	public function index(string $from = '', string $to = ''): DataResponse {
		try {
			$ticks = $this->tickService->findByDateRange($this->userId, $from, $to);
		} catch (InvalidValueException $e) {
			return new DataResponse(['message' => $e->getMessage(), 'reason' => ErrorReason::INVALID_VALUE], Http::STATUS_BAD_REQUEST);
		}
		return new DataResponse(array_values(array_map(fn (Tick $t) => $this->serializeTick($t), $ticks)));
	}

	/**
	 * List the first and last tick date for each track
	 *
	 * Tracks that have no ticks are omitted. Includes private tracks; filter
	 * them client-side as with the tick list.
	 *
	 * @return DataResponse<Http::STATUS_OK, list<TickbuddyTickBounds>, array{}>
	 *
	 * 200: Bounds returned
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/ticks/bounds')]
	public function bounds(): DataResponse {
		return new DataResponse($this->tickService->findBounds($this->userId));
	}

	/**
	 * Toggle a boolean tick on or off
	 *
	 * With an `Idempotency-Key` header (a UUID), a retry with the same key and
	 * the same parameters is answered as the first request was and changes
	 * nothing; see the `idempotencyKey` capability.
	 *
	 * @param int $trackId Track ID
	 * @param string $date Date (YYYY-MM-DD)
	 * @param string|int|float|bool|null $note Optional note for the day: replaces the day's note, empty deletes it, missing leaves it
	 * @param string|int|float|bool|null $appendNote Optional text to add to the day's note on a new line, as POST /api/notes/append; not with `note`
	 * @param string|int|float|bool|null $allowDuplicateNote Append `appendNote` even if the note already holds it: true or false (also 1/0, on/off, yes/no)
	 * @return DataResponse<Http::STATUS_OK, array{ticked: bool, value: int, note?: string}, array{}>|DataResponse<Http::STATUS_BAD_REQUEST|Http::STATUS_NOT_FOUND|Http::STATUS_CONFLICT|Http::STATUS_UNPROCESSABLE_ENTITY|Http::STATUS_LOCKED, TickbuddyError, array{}>
	 *
	 * 200: Tick toggled; `value` is 1 if the day is now ticked, else 0, as the tick list writes it; `ticked` says the same and is kept for older clients; `note` is the day's whole stored note, only when `note` or `appendNote` was sent. On an archived track, without Prefer: handling=strict: nothing written, the stored state. With `Idempotency-Key`: a repeat of an earlier request answers as that request did, and changes nothing
	 * 400: Track is not a boolean track, the date or the note is invalid, the note would be too long, or both `note` and `appendNote` were sent, `allowDuplicateNote` isn't true or false, or `Idempotency-Key` is not a UUID; nothing written
	 * 404: Track not found
	 * 409: The tick was written, but the note kept changing while appending; append the text again with POST /api/notes/append, or a request with the same `Idempotency-Key` is still being handled; try again shortly
	 * 422: This `Idempotency-Key` was used for another request; nothing written
	 * 423: The track is archived; nothing written. Only for requests sent with Prefer: handling=strict, which otherwise get the 200 above
	 */
	// $note is a union for the framework: typed string, Nextcloud would answer a
	// bare 400 to a JSON number. NoteService::normalise reads it, as $appendNote.
	// $allowDuplicateNote too: typed bool, Nextcloud would read "no" as true. Its
	// fingerprint holds the flag as read, as it did when it was typed bool.
	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/ticks/toggle')]
	public function toggle(int $trackId = 0, string $date = '', string|int|float|bool|null $note = null, string|int|float|bool|null $appendNote = null, string|int|float|bool|null $allowDuplicateNote = false): DataResponse {
		$allowDuplicate = Flags::parse($allowDuplicateNote, false);
		return $this->idempotency->run($this->userId, 'ticks.toggle', [$trackId, $date, $note, $appendNote, $allowDuplicate], function () use ($trackId, $date, $note, $appendNote, $allowDuplicate): DataResponse {
			try {
				return new DataResponse($this->tickService->toggle($this->userId, $trackId, $date, $note, $appendNote, Flags::check('allowDuplicateNote', $allowDuplicate)));
			} catch (DoesNotExistException) {
				return new DataResponse(['message' => 'Track not found', 'reason' => ErrorReason::TRACK_NOT_FOUND], Http::STATUS_NOT_FOUND);
			} catch (InvalidTrackTypeException $e) {
				return new DataResponse(['message' => $e->getMessage(), 'reason' => ErrorReason::WRONG_TRACK_TYPE], Http::STATUS_BAD_REQUEST);
			} catch (InvalidValueException $e) {
				return new DataResponse(['message' => $e->getMessage(), 'reason' => ErrorReason::INVALID_VALUE], Http::STATUS_BAD_REQUEST);
			} catch (NoteConflictException $e) {
				return new DataResponse(['message' => $e->getMessage(), 'reason' => ErrorReason::NOTE_CONFLICT], Http::STATUS_CONFLICT);
			} catch (ReadOnlyTrackException $e) {
				return new DataResponse(['message' => $e->getMessage(), 'reason' => $e->getReason()], Http::STATUS_LOCKED);
			}
		});
	}

	/**
	 * Set a tick (counter: its absolute value; yes/no: ticked or not)
	 *
	 * With an `Idempotency-Key` header (a UUID), a retry with the same key and
	 * the same parameters is answered as the first request was and changes
	 * nothing; see the `idempotencyKey` capability.
	 *
	 * @param int $trackId Track ID
	 * @param string $date Date (YYYY-MM-DD)
	 * @param int|float|bool|string|null $value Counter: the new value, a whole number from 0 to 2147483647 (0 deletes the tick). Yes/no: true or false (also 1/0, on/off, yes/no)
	 * @param string|int|float|bool|null $note Optional note for the day: replaces the day's note, empty deletes it, missing leaves it
	 * @param string|int|float|bool|null $appendNote Optional text to add to the day's note on a new line, as POST /api/notes/append; not with `note`
	 * @param string|int|float|bool|null $allowDuplicateNote Append `appendNote` even if the note already holds it: true or false (also 1/0, on/off, yes/no)
	 * @return DataResponse<Http::STATUS_OK, array{value: int, note?: string}, array{}>|DataResponse<Http::STATUS_BAD_REQUEST|Http::STATUS_NOT_FOUND|Http::STATUS_CONFLICT|Http::STATUS_UNPROCESSABLE_ENTITY|Http::STATUS_LOCKED, TickbuddyError, array{}>
	 *
	 * 200: Value set; `note` is the day's whole stored note, only when `note` or `appendNote` was sent. On an archived track, without Prefer: handling=strict: nothing written, the stored state. With `Idempotency-Key`: a repeat of an earlier request answers as that request did, and changes nothing
	 * 400: Value not valid for the track (counter: a whole number from 0 to 2147483647; yes/no: true or false), the date or the note is invalid, the note would be too long, or both `note` and `appendNote` were sent, `allowDuplicateNote` isn't true or false, or `Idempotency-Key` is not a UUID; nothing written
	 * 404: Track not found
	 * 409: The tick was written, but the note kept changing while appending; append the text again with POST /api/notes/append, or a request with the same `Idempotency-Key` is still being handled; try again shortly
	 * 422: This `Idempotency-Key` was used for another request; nothing written
	 * 423: The track is archived; nothing written. Only for requests sent with Prefer: handling=strict, which otherwise get the 200 above
	 */
	// $value is a union for the framework: typed int, Nextcloud would turn
	// "true" or "on" into 0 before the service sees it, and without float a
	// JSON 5.5 would get a bare 400. $allowDuplicateNote as in toggle().
	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/ticks/set')]
	public function set(int $trackId = 0, string $date = '', int|float|bool|string|null $value = null, string|int|float|bool|null $note = null, string|int|float|bool|null $appendNote = null, string|int|float|bool|null $allowDuplicateNote = false): DataResponse {
		$allowDuplicate = Flags::parse($allowDuplicateNote, false);
		return $this->idempotency->run($this->userId, 'ticks.set', [$trackId, $date, $value, $note, $appendNote, $allowDuplicate], function () use ($trackId, $date, $value, $note, $appendNote, $allowDuplicate): DataResponse {
			try {
				return new DataResponse($this->tickService->set($this->userId, $trackId, $date, $value, $note, $appendNote, Flags::check('allowDuplicateNote', $allowDuplicate)));
			} catch (DoesNotExistException) {
				return new DataResponse(['message' => 'Track not found', 'reason' => ErrorReason::TRACK_NOT_FOUND], Http::STATUS_NOT_FOUND);
			} catch (InvalidTrackTypeException $e) {
				return new DataResponse(['message' => $e->getMessage(), 'reason' => ErrorReason::WRONG_TRACK_TYPE], Http::STATUS_BAD_REQUEST);
			} catch (InvalidValueException $e) {
				return new DataResponse(['message' => $e->getMessage(), 'reason' => ErrorReason::INVALID_VALUE], Http::STATUS_BAD_REQUEST);
			} catch (NoteConflictException $e) {
				return new DataResponse(['message' => $e->getMessage(), 'reason' => ErrorReason::NOTE_CONFLICT], Http::STATUS_CONFLICT);
			} catch (ReadOnlyTrackException $e) {
				return new DataResponse(['message' => $e->getMessage(), 'reason' => $e->getReason()], Http::STATUS_LOCKED);
			}
		});
	}

	/**
	 * Add to a counter tick
	 *
	 * Concurrent increments of the same day, from any mix of clients, all
	 * count. The value never goes below 0; reaching 0 deletes the tick.
	 *
	 * With an `Idempotency-Key` header (a UUID), a retry with the same key and
	 * the same parameters is answered as the first request was and changes
	 * nothing; see the `idempotencyKey` capability.
	 *
	 * @param int $trackId Track ID
	 * @param string $date Date (YYYY-MM-DD)
	 * @param string|int|float|bool|null $delta Amount to add, a whole number other than 0; negative to take away
	 * @param string|int|float|bool|null $note Optional note for the day: replaces the day's note, empty deletes it, missing leaves it
	 * @param string|int|float|bool|null $appendNote Optional text to add to the day's note on a new line, as POST /api/notes/append; not with `note`
	 * @param string|int|float|bool|null $allowDuplicateNote Append `appendNote` even if the note already holds it: true or false (also 1/0, on/off, yes/no)
	 * @return DataResponse<Http::STATUS_OK, array{value: int, note?: string}, array{}>|DataResponse<Http::STATUS_BAD_REQUEST|Http::STATUS_NOT_FOUND|Http::STATUS_CONFLICT|Http::STATUS_UNPROCESSABLE_ENTITY|Http::STATUS_LOCKED, TickbuddyError, array{}>
	 *
	 * 200: Value changed; `note` is the day's whole stored note, only when `note` or `appendNote` was sent. On an archived track, without Prefer: handling=strict: nothing written, the stored value. With `Idempotency-Key`: a repeat of an earlier request answers as that request did, and changes nothing
	 * 400: Not a counter track, delta, date or note invalid, the value would be more than 2147483647, the note would be too long, or both `note` and `appendNote` were sent, `allowDuplicateNote` isn't true or false, or `Idempotency-Key` is not a UUID; nothing written
	 * 404: Track not found
	 * 409: The tick was written, but the note kept changing while appending; append the text again with POST /api/notes/append, or a request with the same `Idempotency-Key` is still being handled; try again shortly
	 * 422: This `Idempotency-Key` was used for another request; nothing written
	 * 423: The track is archived; nothing written. Only for requests sent with Prefer: handling=strict, which otherwise get the 200 above
	 */
	// $delta is a union for the framework: typed int, Nextcloud would read "5abc"
	// as 5 and a JSON 1.9 as 1. Its fingerprint holds the number read, as it did
	// when it was typed int (Tickdroid sends a form-encoded "1" with a key).
	// $allowDuplicateNote as in toggle().
	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/ticks/increment')]
	public function increment(int $trackId = 0, string $date = '', string|int|float|bool|null $delta = 0, string|int|float|bool|null $note = null, string|int|float|bool|null $appendNote = null, string|int|float|bool|null $allowDuplicateNote = false): DataResponse {
		$parsedDelta = TickService::parseWholeNumber($delta, true);
		$allowDuplicate = Flags::parse($allowDuplicateNote, false);
		return $this->idempotency->run($this->userId, 'ticks.increment', [$trackId, $date, $parsedDelta, $note, $appendNote, $allowDuplicate], function () use ($trackId, $date, $parsedDelta, $note, $appendNote, $allowDuplicate): DataResponse {
			try {
				return new DataResponse($this->tickService->increment($this->userId, $trackId, $date, $parsedDelta, $note, $appendNote, Flags::check('allowDuplicateNote', $allowDuplicate)));
			} catch (DoesNotExistException) {
				return new DataResponse(['message' => 'Track not found', 'reason' => ErrorReason::TRACK_NOT_FOUND], Http::STATUS_NOT_FOUND);
			} catch (InvalidTrackTypeException $e) {
				return new DataResponse(['message' => $e->getMessage(), 'reason' => ErrorReason::WRONG_TRACK_TYPE], Http::STATUS_BAD_REQUEST);
			} catch (InvalidValueException $e) {
				return new DataResponse(['message' => $e->getMessage(), 'reason' => ErrorReason::INVALID_VALUE], Http::STATUS_BAD_REQUEST);
			} catch (NoteConflictException $e) {
				return new DataResponse(['message' => $e->getMessage(), 'reason' => ErrorReason::NOTE_CONFLICT], Http::STATUS_CONFLICT);
			} catch (ReadOnlyTrackException $e) {
				return new DataResponse(['message' => $e->getMessage(), 'reason' => $e->getReason()], Http::STATUS_LOCKED);
			}
		});
	}
}
