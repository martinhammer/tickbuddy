<?php

declare(strict_types=1);

namespace OCA\Tickbuddy\Controller;

use OCA\Tickbuddy\Db\Note;
use OCA\Tickbuddy\ResponseDefinitions;
use OCA\Tickbuddy\Service\InvalidValueException;
use OCA\Tickbuddy\Service\NoteConflictException;
use OCA\Tickbuddy\Service\NoteService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;

/**
 * @psalm-import-type TickbuddyNote from ResponseDefinitions
 *
 * @psalm-suppress UnusedClass
 */
class NoteController extends OCSController {
	public function __construct(
		string $appName,
		IRequest $request,
		private NoteService $noteService,
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
	 * @return DataResponse<Http::STATUS_OK, list<TickbuddyNote>, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, array{message: string}, array{}>
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
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	/**
	 * Set the note on a track's day
	 *
	 * On an archived track nothing changes and the answer is the stored note.
	 *
	 * @param int $trackId Track ID
	 * @param string $date Date (YYYY-MM-DD)
	 * @param string|int|float|bool|null $text Plain text, at most 500 characters; empty deletes the note
	 * @return DataResponse<Http::STATUS_OK, array{text: string}, array{}>|DataResponse<Http::STATUS_BAD_REQUEST|Http::STATUS_NOT_FOUND, array{message: string}, array{}>
	 *
	 * 200: Note set; `text` is the stored text, empty if none
	 * 400: Date or text invalid
	 * 404: Track not found
	 */
	// $text is a union for the framework: typed string, Nextcloud would answer
	// a bare 400 to a JSON number instead of storing its digits.
	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/notes/set')]
	public function set(int $trackId = 0, string $date = '', string|int|float|bool|null $text = ''): DataResponse {
		try {
			return new DataResponse(['text' => $this->noteService->set($this->userId, $trackId, $date, $text)]);
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => 'Track not found'], Http::STATUS_NOT_FOUND);
		} catch (InvalidValueException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	/**
	 * Append to the note on a track's day
	 *
	 * Adds the text on a new line, or stores it if the day has no note. Text
	 * whose lines are already in the note, in order and as whole lines, is
	 * skipped (unless `allowDuplicate`), so a retry never adds it twice. Empty
	 * text changes nothing; on an archived track nothing changes either. Safe
	 * against other writers: it never overwrites a change made meanwhile.
	 *
	 * @param int $trackId Track ID
	 * @param string $date Date (YYYY-MM-DD)
	 * @param string|int|float|bool|null $text Plain text to add
	 * @param bool $allowDuplicate Append the text even if the note already holds it
	 * @return DataResponse<Http::STATUS_OK, array{text: string}, array{}>|DataResponse<Http::STATUS_BAD_REQUEST|Http::STATUS_NOT_FOUND|Http::STATUS_CONFLICT, array{message: string}, array{}>
	 *
	 * 200: Text appended, or already there; `text` is the whole stored note, empty if none
	 * 400: Date or text invalid, or the note would be longer than 500 characters
	 * 404: Track not found
	 * 409: The note kept changing while appending; nothing was written, try again
	 */
	// $text is a union for the framework, as in set()
	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/notes/append')]
	public function append(int $trackId = 0, string $date = '', string|int|float|bool|null $text = '', bool $allowDuplicate = false): DataResponse {
		try {
			return new DataResponse(['text' => $this->noteService->append($this->userId, $trackId, $date, $text, $allowDuplicate)]);
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => 'Track not found'], Http::STATUS_NOT_FOUND);
		} catch (InvalidValueException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (NoteConflictException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_CONFLICT);
		}
	}

	/**
	 * Delete the note on a track's day
	 *
	 * Answers 204 also when the day had no note. On an archived track nothing is deleted.
	 *
	 * @param int $trackId Track ID
	 * @param string $date Date (YYYY-MM-DD)
	 * @return DataResponse<Http::STATUS_NO_CONTENT, null, array{}>|DataResponse<Http::STATUS_BAD_REQUEST|Http::STATUS_NOT_FOUND, array{message: string}, array{}>
	 *
	 * 204: Note deleted
	 * 400: Date invalid
	 * 404: Track not found
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'DELETE', url: '/api/notes')]
	public function destroy(int $trackId = 0, string $date = ''): DataResponse {
		try {
			$this->noteService->delete($this->userId, $trackId, $date);
			return new DataResponse(null, Http::STATUS_NO_CONTENT);
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => 'Track not found'], Http::STATUS_NOT_FOUND);
		} catch (InvalidValueException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}
}
