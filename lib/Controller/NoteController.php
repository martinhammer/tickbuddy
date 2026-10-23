<?php

declare(strict_types=1);

namespace OCA\Tickbuddy\Controller;

use OCA\Tickbuddy\Db\Note;
use OCA\Tickbuddy\ResponseDefinitions;
use OCA\Tickbuddy\Service\ErrorReason;
use OCA\Tickbuddy\Service\IdempotencyService;
use OCA\Tickbuddy\Service\InvalidValueException;
use OCA\Tickbuddy\Service\NoteConflictException;
use OCA\Tickbuddy\Service\NoteService;
use OCA\Tickbuddy\Service\ReadOnlyTrackException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;

/**
 * @psalm-import-type TickbuddyError from ResponseDefinitions
 * @psalm-import-type TickbuddyNote from ResponseDefinitions
 *
 * @psalm-suppress UnusedClass
 */
class NoteController extends OCSController {
	public function __construct(
		string $appName,
		IRequest $request,
		private NoteService $noteService,
		private IdempotencyService $idempotency,
		private string $userId,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * @return TickbuddyNote
	 */
	private function serializeNote(Note $note): array {
		return [
			'trackId' => $note->getTrackId(),
			'date' => $note->getDate(),
			'text' => $note->getText(),
		];
	}

	/**
	 * List notes within a date range
	 *
	 * Includes the notes of private and archived tracks.
	 *
	 * @param string $from Start date (YYYY-MM-DD), inclusive
	 * @param string $to End date (YYYY-MM-DD), inclusive
	 * @return DataResponse<Http::STATUS_OK, list<TickbuddyNote>, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, TickbuddyError, array{}>
	 *
	 * 200: Notes returned, newest first
	 * 400: From or to is missing or not a date
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/notes')]
	public function index(string $from = '', string $to = ''): DataResponse {
		try {
			$notes = $this->noteService->findByDateRange($this->userId, $from, $to);
			return new DataResponse(array_values(array_map(fn (Note $n) => $this->serializeNote($n), $notes)));
		} catch (InvalidValueException $e) {
			return new DataResponse(['message' => $e->getMessage(), 'reason' => ErrorReason::INVALID_VALUE], Http::STATUS_BAD_REQUEST);
		}
	}

	/**
	 * Set the note on a track's day
	 *
	 * On an archived track nothing changes: requests sent with Prefer: handling=strict
	 * get 423, others the stored note.
	 *
	 * @param int $trackId Track ID
	 * @param string $date Date (YYYY-MM-DD)
	 * @param string|int|float|bool|null $text Plain text, at most 500 characters; empty deletes the note
	 * @return DataResponse<Http::STATUS_OK, array{text: string}, array{}>|DataResponse<Http::STATUS_BAD_REQUEST|Http::STATUS_NOT_FOUND|Http::STATUS_LOCKED, TickbuddyError, array{}>
	 *
	 * 200: Note set; `text` is the stored text, empty if none. On an archived track, without Prefer: handling=strict: nothing written, the stored note
	 * 400: Date or text invalid
	 * 404: Track not found
	 * 423: The track is archived; nothing written. Only for requests sent with Prefer: handling=strict, which otherwise get the 200 above
	 */
	// $text is a union for the framework: typed string, Nextcloud would answer
	// a bare 400 to a JSON number instead of storing its digits.
	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/notes/set')]
	public function set(int $trackId = 0, string $date = '', string|int|float|bool|null $text = ''): DataResponse {
		try {
			return new DataResponse(['text' => $this->noteService->set($this->userId, $trackId, $date, $text)]);
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => 'Track not found', 'reason' => ErrorReason::TRACK_NOT_FOUND], Http::STATUS_NOT_FOUND);
		} catch (InvalidValueException $e) {
			return new DataResponse(['message' => $e->getMessage(), 'reason' => ErrorReason::INVALID_VALUE], Http::STATUS_BAD_REQUEST);
		} catch (ReadOnlyTrackException $e) {
			return new DataResponse(['message' => $e->getMessage(), 'reason' => $e->getReason()], Http::STATUS_LOCKED);
		}
	}

	/**
	 * Append to the note on a track's day
	 *
	 * Adds the text on a new line, or stores it if the day has no note. Text
	 * whose lines are already in the note, in order and as whole lines, is
	 * skipped (unless `allowDuplicate`), so a retry never adds it twice. Empty
	 * text changes nothing. On an archived track nothing changes either:
	 * requests sent with Prefer: handling=strict get 423 (empty text too),
	 * others the stored note. Safe against other writers: it never overwrites
	 * a change made meanwhile.
	 *
	 * With an `Idempotency-Key` header (a UUID), a retry with the same key and
	 * the same parameters is answered as the first request was and changes
	 * nothing; see the `idempotencyKey` capability.
	 *
	 * @param int $trackId Track ID
	 * @param string $date Date (YYYY-MM-DD)
	 * @param string|int|float|bool|null $text Plain text to add
	 * @param bool $allowDuplicate Append the text even if the note already holds it
	 * @return DataResponse<Http::STATUS_OK, array{text: string}, array{}>|DataResponse<Http::STATUS_BAD_REQUEST|Http::STATUS_NOT_FOUND|Http::STATUS_CONFLICT|Http::STATUS_UNPROCESSABLE_ENTITY|Http::STATUS_LOCKED, TickbuddyError, array{}>
	 *
	 * 200: Text appended, or already there; `text` is the whole stored note, empty if none. On an archived track, without Prefer: handling=strict: nothing written, the stored note. With `Idempotency-Key`: a repeat of an earlier request answers as that request did, and changes nothing
	 * 400: Date or text invalid, the note would be longer than 500 characters, or `Idempotency-Key` is not a UUID
	 * 404: Track not found
	 * 409: The note kept changing while appending; nothing was written, try again, or a request with the same `Idempotency-Key` is still being handled; try again shortly
	 * 422: This `Idempotency-Key` was used for another request; nothing written
	 * 423: The track is archived; nothing written. Only for requests sent with Prefer: handling=strict, which otherwise get the 200 above
	 */
	// $text is a union for the framework, as in set()
	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/notes/append')]
	public function append(int $trackId = 0, string $date = '', string|int|float|bool|null $text = '', bool $allowDuplicate = false): DataResponse {
		return $this->idempotency->run($this->userId, 'notes.append', [$trackId, $date, $text, $allowDuplicate], function () use ($trackId, $date, $text, $allowDuplicate): DataResponse {
			try {
				return new DataResponse(['text' => $this->noteService->append($this->userId, $trackId, $date, $text, $allowDuplicate)]);
			} catch (DoesNotExistException) {
				return new DataResponse(['message' => 'Track not found', 'reason' => ErrorReason::TRACK_NOT_FOUND], Http::STATUS_NOT_FOUND);
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
	 * Delete the note on a track's day
	 *
	 * Answers 204 also when the day had no note. On an archived track nothing is
	 * deleted: requests sent with Prefer: handling=strict get 423, others 204.
	 *
	 * @param int $trackId Track ID
	 * @param string $date Date (YYYY-MM-DD)
	 * @return DataResponse<Http::STATUS_NO_CONTENT, null, array{}>|DataResponse<Http::STATUS_BAD_REQUEST|Http::STATUS_NOT_FOUND|Http::STATUS_LOCKED, TickbuddyError, array{}>
	 *
	 * 204: Note deleted. On an archived track, without Prefer: handling=strict: nothing deleted
	 * 400: Date invalid
	 * 404: Track not found
	 * 423: The track is archived; nothing deleted. Only for requests sent with Prefer: handling=strict, which otherwise get the 204 above
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'DELETE', url: '/api/notes')]
	public function destroy(int $trackId = 0, string $date = ''): DataResponse {
		try {
			$this->noteService->delete($this->userId, $trackId, $date);
			return new DataResponse(null, Http::STATUS_NO_CONTENT);
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => 'Track not found', 'reason' => ErrorReason::TRACK_NOT_FOUND], Http::STATUS_NOT_FOUND);
		} catch (InvalidValueException $e) {
			return new DataResponse(['message' => $e->getMessage(), 'reason' => ErrorReason::INVALID_VALUE], Http::STATUS_BAD_REQUEST);
		} catch (ReadOnlyTrackException $e) {
			return new DataResponse(['message' => $e->getMessage(), 'reason' => $e->getReason()], Http::STATUS_LOCKED);
		}
	}
}
