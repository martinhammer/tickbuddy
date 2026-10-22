<?php

declare(strict_types=1);

namespace OCA\Tickbuddy\Service;

use OCA\Tickbuddy\Db\Tick;
use OCA\Tickbuddy\Db\TickMapper;
use OCA\Tickbuddy\Db\Track;
use OCA\Tickbuddy\Db\TrackMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\Exception as DbException;

class TickService {
	public function __construct(
		private TickMapper $tickMapper,
		private TrackMapper $trackMapper,
		private NoteService $noteService,
		private StrictHandling $strictHandling,
	) {
	}

	/**
	 * @return Tick[]
	 */
	public function findByDateRange(string $userId, string $from, string $to): array {
		return $this->tickMapper->findByUserAndDateRange($userId, $from, $to);
	}

	/**
	 * First and last tick date per track. Tracks with no ticks are omitted.
	 *
	 * Private tracks are included — `private` hides tracks in the UI, it is not
	 * an authorization boundary, so clients filter it the same way they do for
	 * {@see findByDateRange}.
	 *
	 * @return list<array{trackId: int, oldest: string, newest: string}>
	 */
	public function findBounds(string $userId): array {
		return $this->tickMapper->findBoundsByUser($userId);
	}

	/**
	 * Toggle a boolean track tick. Returns true if the tick now exists, false if removed.
	 *
	 * On an archived track nothing changes: a client that asked for strict handling
	 * is refused, any other gets whether a tick exists.
	 *
	 * @param string|int|float|bool|null $note The day's new note ('' deletes it), or null to leave it;
	 *                                         written whichever way the tick flips
	 * @param string|int|float|bool|null $appendNote Text to add to the day's note, or null; not with `$note`
	 * @param bool $allowDuplicateNote Append `$appendNote` even if the note already holds it
	 * @return array{ticked: bool, value: int, note?: string} `value` is 1 or 0, as the tick list
	 *                                                        writes it; `note` only when one was sent
	 * @throws DoesNotExistException if track not found
	 * @throws InvalidTrackTypeException if track is not boolean
	 * @throws InvalidValueException if the date or the note is not valid, or both notes were sent
	 * @throws NoteConflictException if appending lost to other writers, after the tick was written
	 * @throws ReadOnlyTrackException if the track is archived and the client asked for strict handling
	 */
	public function toggle(string $userId, int $trackId, string $date, string|int|float|bool|null $note = null, string|int|float|bool|null $appendNote = null, bool $allowDuplicateNote = false): array {
		$track = $this->trackMapper->findByIdAndUser($trackId, $userId);
		if ($track->getType() !== 'boolean') {
			throw new InvalidTrackTypeException('Toggle is only available for boolean tracks');
		}
		self::checkDate($date);
		[$note, $appendNote] = self::checkNotes($note, $appendNote);
		if ($track->getArchivedAt() !== null) {
			$this->strictHandling->refuseArchived($track);
			// Archived history can't change. Not asked to refuse: answer with the stored state
			// so an offline client's late upload doesn't stall its queue; its next pull drops the tick.
			$ticked = $this->currentValue($userId, $trackId, $date) > 0;
			return ['ticked' => $ticked, 'value' => $ticked ? 1 : 0] + $this->storedNote($userId, $trackId, $date, $note, $appendNote);
		}
		$this->checkAppend($userId, $trackId, $date, $appendNote, $allowDuplicateNote);

		try {
			$tick = $this->tickMapper->findByUserTrackDate($userId, $trackId, $date);
			$this->tickMapper->delete($tick);
			$ticked = false;
		} catch (DoesNotExistException) {
			$this->insertTick($userId, $trackId, $date, 1);
			$ticked = true;
		}
		return ['ticked' => $ticked, 'value' => $ticked ? 1 : 0] + $this->writeNote($userId, $trackId, $date, $note, $appendNote, $allowDuplicateNote);
	}

	/**
	 * Set the value for a tick. Counter: the absolute value, 0 deletes. Yes/no:
	 * true ticks, false unticks; answers whether it is ticked.
	 *
	 * Unlike toggle(), setting a yes/no day is idempotent, so a retried request
	 * can never undo itself.
	 *
	 * On an archived track nothing changes: a client that asked for strict handling
	 * is refused, any other gets the stored state.
	 *
	 * @param mixed $value Counter: a number. Yes/no: true/false, 1/0, on/off or yes/no.
	 * @param string|int|float|bool|null $note The day's new note ('' deletes it), or null to leave it
	 * @param string|int|float|bool|null $appendNote Text to add to the day's note, or null; not with `$note`
	 * @param bool $allowDuplicateNote Append `$appendNote` even if the note already holds it
	 * @return array{value: int, note?: string} `note` only when one was sent
	 * @throws DoesNotExistException if track not found
	 * @throws InvalidValueException if a yes/no value, the date or the note is not valid, or both notes were sent
	 * @throws NoteConflictException if appending lost to other writers, after the tick was written
	 * @throws ReadOnlyTrackException if the track is archived and the client asked for strict handling
	 */
	public function set(string $userId, int $trackId, string $date, mixed $value, string|int|float|bool|null $note = null, string|int|float|bool|null $appendNote = null, bool $allowDuplicateNote = false): array {
		$track = $this->trackMapper->findByIdAndUser($trackId, $userId);
		if ($track->getType() === 'boolean') {
			return $this->setYesNo($track, $userId, $date, $value, $note, $appendNote, $allowDuplicateNote);
		}

		// Read as before this accepted yes/no values: a missing value is 0
		$value = is_scalar($value) ? (int)$value : 0;
		self::checkDate($date);
		[$note, $appendNote] = self::checkNotes($note, $appendNote);
		if ($track->getArchivedAt() !== null) {
			$this->strictHandling->refuseArchived($track);
			// As in toggle(): ignore the write, answer with what's stored
			return ['value' => $this->currentValue($userId, $trackId, $date)] + $this->storedNote($userId, $trackId, $date, $note, $appendNote);
		}
		$this->checkAppend($userId, $trackId, $date, $appendNote, $allowDuplicateNote);

		try {
			$tick = $this->tickMapper->findByUserTrackDate($userId, $trackId, $date);
			if ($value <= 0) {
				$this->tickMapper->delete($tick);
				$value = 0;
			} else {
				$tick->setValue($value);
				$this->tickMapper->update($tick);
			}
		} catch (DoesNotExistException) {
			if ($value <= 0) {
				$value = 0;
			} else {
				$this->insertTick($userId, $trackId, $date, $value);
			}
		}
		return ['value' => $value] + $this->writeNote($userId, $trackId, $date, $note, $appendNote, $allowDuplicateNote);
	}

	/**
	 * @return array{value: int, note?: string}
	 * @throws InvalidValueException
	 * @throws NoteConflictException
	 * @throws ReadOnlyTrackException
	 */
	private function setYesNo(Track $track, string $userId, string $date, mixed $value, string|int|float|bool|null $note, string|int|float|bool|null $appendNote, bool $allowDuplicateNote): array {
		$ticked = self::parseYesNo($value);
		if ($ticked === null) {
			throw new InvalidValueException('Yes/no tracks take a value of true or false');
		}
		self::checkDate($date);
		[$note, $appendNote] = self::checkNotes($note, $appendNote);
		$trackId = $track->getId();
		if ($track->getArchivedAt() !== null) {
			$this->strictHandling->refuseArchived($track);
			return ['value' => $this->currentValue($userId, $trackId, $date) > 0 ? 1 : 0] + $this->storedNote($userId, $trackId, $date, $note, $appendNote);
		}
		$this->checkAppend($userId, $trackId, $date, $appendNote, $allowDuplicateNote);

		$this->writeYesNo($userId, $trackId, $date, $ticked);
		return ['value' => $ticked ? 1 : 0] + $this->writeNote($userId, $trackId, $date, $note, $appendNote, $allowDuplicateNote);
	}

	private function writeYesNo(string $userId, int $trackId, string $date, bool $ticked): void {

		try {
			$tick = $this->tickMapper->findByUserTrackDate($userId, $trackId, $date);
			if (!$ticked) {
				$this->tickMapper->delete($tick);
			}
		} catch (DoesNotExistException) {
			if (!$ticked) {
				return;
			}
			try {
				$this->insertTick($userId, $trackId, $date, 1);
			} catch (DbException $e) {
				// Another request ticked the day in between: it is ticked, as asked
				if ($e->getReason() !== DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
					throw $e;
				}
			}
		}
	}

	/**
	 * Read a yes/no value: booleans, 1/0, and the words true/false, on/off and
	 * yes/no in any case with surrounding spaces ignored. Null for anything else.
	 *
	 * @internal Public for the tests
	 */
	public static function parseYesNo(mixed $value): ?bool {
		if (is_bool($value)) {
			return $value;
		}
		if (is_int($value)) {
			return match ($value) {
				1 => true,
				0 => false,
				default => null,
			};
		}
		if (!is_string($value)) {
			return null;
		}
		return match (strtolower(trim($value))) {
			'true', 'on', 'yes', '1' => true,
			'false', 'off', 'no', '0' => false,
			default => null,
		};
	}

	/**
	 * Add a signed delta to a counter tick, atomically, so concurrent increments
	 * from several devices and scripts all count. The value never goes below 0:
	 * a result of 0 or less deletes the row. Answers the value right after this
	 * request's own write, which may already include another request's delta.
	 *
	 * The UPDATE comes first because the common case is a row that already
	 * exists, and `value = value + delta` is applied atomically per row by every
	 * database. Only when nothing was updated is a row inserted; if another
	 * request inserted it in between, the unique index refuses ours and the
	 * UPDATE is retried once, which now lands.
	 *
	 * No explicit transaction: each statement is atomic on its own, and on
	 * PostgreSQL a unique violation aborts the whole transaction, which would
	 * make that retry impossible.
	 *
	 * On an archived track nothing changes: a client that asked for strict handling
	 * is refused, any other gets the stored value.
	 *
	 * @param string|int|float|bool|null $note The day's new note ('' deletes it), or null to leave it
	 * @param string|int|float|bool|null $appendNote Text to add to the day's note, or null; not with `$note`
	 * @param bool $allowDuplicateNote Append `$appendNote` even if the note already holds it
	 * @return array{value: int, note?: string} `note` only when one was sent
	 * @throws DoesNotExistException if track not found
	 * @throws InvalidTrackTypeException if track is not counter
	 * @throws InvalidValueException if delta is 0, or the date or the note is not valid, or both notes were sent
	 * @throws NoteConflictException if appending lost to other writers, after the tick was written
	 * @throws ReadOnlyTrackException if the track is archived and the client asked for strict handling
	 */
	public function increment(string $userId, int $trackId, string $date, int $delta, string|int|float|bool|null $note = null, string|int|float|bool|null $appendNote = null, bool $allowDuplicateNote = false): array {
		$track = $this->trackMapper->findByIdAndUser($trackId, $userId);
		if ($track->getType() !== 'counter') {
			throw new InvalidTrackTypeException('Increment is only available for counter tracks');
		}
		if ($delta === 0) {
			throw new InvalidValueException('Delta must be a whole number other than 0');
		}
		self::checkDate($date);
		[$note, $appendNote] = self::checkNotes($note, $appendNote);
		if ($track->getArchivedAt() !== null) {
			$this->strictHandling->refuseArchived($track);
			return ['value' => $this->currentValue($userId, $trackId, $date)] + $this->storedNote($userId, $trackId, $date, $note, $appendNote);
		}
		$this->checkAppend($userId, $trackId, $date, $appendNote, $allowDuplicateNote);

		$value = $this->addDelta($userId, $trackId, $date, $delta);
		return ['value' => $value] + $this->writeNote($userId, $trackId, $date, $note, $appendNote, $allowDuplicateNote);
	}

	/**
	 * @return int The value after this request's write
	 */
	private function addDelta(string $userId, int $trackId, string $date, int $delta): int {
		$updated = $this->tickMapper->addToValue($userId, $trackId, $date, $delta);
		if ($updated === 0) {
			if ($delta < 0) {
				// Nothing to take away from
				return 0;
			}
			try {
				$this->insertTick($userId, $trackId, $date, $delta);
				return $delta;
			} catch (DbException $e) {
				if ($e->getReason() !== DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
					throw $e;
				}
				// Another request inserted the day's row meanwhile: add to it
				$this->tickMapper->addToValue($userId, $trackId, $date, $delta);
			}
		}

		if ($this->tickMapper->deleteIfNotPositive($userId, $trackId, $date) > 0) {
			return 0;
		}
		return $this->currentValue($userId, $trackId, $date);
	}

	/**
	 * @throws InvalidValueException
	 */
	private static function checkDate(string $date): void {
		if (!TrackService::isValidDate($date)) {
			throw new InvalidValueException('Date must be a date as YYYY-MM-DD');
		}
	}

	/**
	 * The note or the text to append sent with a tick call, checked before
	 * anything is written; null for what wasn't sent.
	 *
	 * @return array{0: ?string, 1: ?string} The note and the text to append
	 * @throws InvalidValueException
	 */
	private static function checkNotes(string|int|float|bool|null $note, string|int|float|bool|null $appendNote): array {
		if ($note !== null && $appendNote !== null) {
			throw new InvalidValueException('Send note or appendNote, not both');
		}
		return [
			$note === null ? null : NoteService::normalise($note),
			$appendNote === null ? null : NoteService::normalise($appendNote),
		];
	}

	/**
	 * Before the tick is written: refuse text to append that wouldn't fit.
	 *
	 * @throws InvalidValueException
	 */
	private function checkAppend(string $userId, int $trackId, string $date, ?string $appendNote, bool $allowDuplicate): void {
		if ($appendNote !== null) {
			$this->noteService->checkAppend($userId, $trackId, $date, $appendNote, $allowDuplicate);
		}
	}

	/**
	 * Write a note sent with a tick call, or append to it, after the tick, for
	 * the answer. No transaction around the two writes, as increment() can't
	 * have one: a failure here leaves the tick written, which a retry of set
	 * or increment repairs (appending skips text already there).
	 *
	 * @return array{note?: string} The stored note, if one was sent
	 * @throws InvalidValueException if the note grew past the limit since checkAppend()
	 * @throws NoteConflictException
	 */
	private function writeNote(string $userId, int $trackId, string $date, ?string $note, ?string $appendNote, bool $allowDuplicate): array {
		if ($note !== null) {
			return ['note' => $this->noteService->writeForTick($userId, $trackId, $date, $note)];
		}
		if ($appendNote !== null) {
			return ['note' => $this->noteService->appendForTick($userId, $trackId, $date, $appendNote, $allowDuplicate)];
		}
		return [];
	}

	/**
	 * On an archived track: the stored note for the answer, if a note was sent.
	 *
	 * @return array{note?: string}
	 */
	private function storedNote(string $userId, int $trackId, string $date, ?string $note, ?string $appendNote): array {
		if ($note === null && $appendNote === null) {
			return [];
		}
		return ['note' => $this->noteService->stored($userId, $trackId, $date)];
	}

	private function insertTick(string $userId, int $trackId, string $date, int $value): void {
		$tick = new Tick();
		$tick->setUserId($userId);
		$tick->setTrackId($trackId);
		$tick->setDate($date);
		$tick->setValue($value);
		$this->tickMapper->insert($tick);
	}

	/**
	 * The stored value for a day, 0 when there is no tick.
	 */
	private function currentValue(string $userId, int $trackId, string $date): int {
		try {
			return $this->tickMapper->findByUserTrackDate($userId, $trackId, $date)->getValue();
		} catch (DoesNotExistException) {
			return 0;
		}
	}
}
