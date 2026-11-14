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
	/** The highest counter value: the value column is a 32-bit signed integer on MySQL, PostgreSQL and Oracle */
	public const MAX_VALUE = 2147483647;
	/** Write attempts before a write that keeps losing to other requests gives up */
	private const WRITE_ATTEMPTS = 3;

	public function __construct(
		private TickMapper $tickMapper,
		private TrackMapper $trackMapper,
		private NoteService $noteService,
		private StrictHandling $strictHandling,
	) {
	}

	/**
	 * Ticks of every track of the user in a date range, both ends inclusive.
	 *
	 * @return Tick[]
	 * @throws InvalidValueException if from or to is not YYYY-MM-DD, or from is after to
	 */
	public function findByDateRange(string $userId, string $from, string $to): array {
		TrackService::checkDateRange($from, $to);
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
			try {
				$this->insertTick($userId, $trackId, $date, 1);
				$ticked = true;
			} catch (DbException $e) {
				if ($e->getReason() !== DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
					throw $e;
				}
				// Another request ticked the day in between: this toggle undoes
				// it, as a second toggle does
				$this->deleteTick($userId, $trackId, $date);
				$ticked = false;
			}
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
	 * @param mixed $value Counter: a whole number from 0 to MAX_VALUE, as an integer or a string
	 *                     of digits; 0 deletes the tick. Yes/no: true/false, 1/0, on/off or yes/no.
	 * @param string|int|float|bool|null $note The day's new note ('' deletes it), or null to leave it
	 * @param string|int|float|bool|null $appendNote Text to add to the day's note, or null; not with `$note`
	 * @param bool $allowDuplicateNote Append `$appendNote` even if the note already holds it
	 * @return array{value: int, note?: string} `note` only when one was sent
	 * @throws DoesNotExistException if track not found
	 * @throws InvalidValueException if the value, the date or the note is not valid, or both notes were sent
	 * @throws NoteConflictException if appending lost to other writers, after the tick was written
	 * @throws ReadOnlyTrackException if the track is archived and the client asked for strict handling
	 */
	public function set(string $userId, int $trackId, string $date, mixed $value, string|int|float|bool|null $note = null, string|int|float|bool|null $appendNote = null, bool $allowDuplicateNote = false): array {
		$track = $this->trackMapper->findByIdAndUser($trackId, $userId);
		if ($track->getType() === 'boolean') {
			return $this->setYesNo($track, $userId, $date, $value, $note, $appendNote, $allowDuplicateNote);
		}

		$value = self::counterValue($value);
		self::checkDate($date);
		[$note, $appendNote] = self::checkNotes($note, $appendNote);
		if ($track->getArchivedAt() !== null) {
			$this->strictHandling->refuseArchived($track);
			// As in toggle(): ignore the write, answer with what's stored
			return ['value' => $this->currentValue($userId, $trackId, $date)] + $this->storedNote($userId, $trackId, $date, $note, $appendNote);
		}
		$this->checkAppend($userId, $trackId, $date, $appendNote, $allowDuplicateNote);

		$this->writeCounter($userId, $trackId, $date, $value);
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

	/**
	 * Store a counter's absolute value, 0 deleting the row. Last write wins: if
	 * another request inserted the day's row in between, this value replaces
	 * its value; if that row is gone again by the time it is read, insert again.
	 *
	 * @throws DbException if other requests kept inserting and deleting the row
	 */
	private function writeCounter(string $userId, int $trackId, string $date, int $value): void {
		for ($attempt = 1; ; $attempt++) {
			try {
				$tick = $this->tickMapper->findByUserTrackDate($userId, $trackId, $date);
				if ($value === 0) {
					$this->tickMapper->delete($tick);
				} else {
					$tick->setValue($value);
					$this->tickMapper->update($tick);
				}
				return;
			} catch (DoesNotExistException) {
				if ($value === 0) {
					return;
				}
				try {
					$this->insertTick($userId, $trackId, $date, $value);
					return;
				} catch (DbException $e) {
					if ($e->getReason() !== DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION || $attempt >= self::WRITE_ATTEMPTS) {
						throw $e;
					}
				}
			}
		}
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
	 * A counter value sent to set().
	 *
	 * @throws InvalidValueException if it isn't a whole number from 0 to MAX_VALUE
	 */
	private static function counterValue(mixed $value): int {
		$value = self::parseWholeNumber($value, false);
		if ($value === null) {
			throw new InvalidValueException('Counter tracks take a whole number, 0 or more');
		}
		if ($value > self::MAX_VALUE) {
			throw new InvalidValueException('Counter values can be at most ' . self::MAX_VALUE);
		}
		return $value;
	}

	/**
	 * Read a whole number: a JSON integer, or a string that is digits once
	 * trimmed ("007" is 7), with a leading minus when `$signed`. Null for
	 * anything else: floats, booleans, "+5", "5.0", "1e3", "5abc", empty.
	 * A number beyond PHP's integers reads as PHP_INT_MAX or PHP_INT_MIN,
	 * which every caller refuses or limits anyway.
	 *
	 * @internal Public for the controllers, which fingerprint the number read
	 */
	public static function parseWholeNumber(mixed $value, bool $signed): ?int {
		if (is_int($value)) {
			return $signed || $value >= 0 ? $value : null;
		}
		if (!is_string($value)) {
			return null;
		}
		if (preg_match($signed ? '/^(-?)(\d+)$/D' : '/^()(\d+)$/D', trim($value), $m) !== 1) {
			return null;
		}
		$digits = ltrim($m[2], '0');
		// Eighteen digits always fit a 64-bit integer
		if (strlen($digits) > 18) {
			return $m[1] === '-' ? PHP_INT_MIN : PHP_INT_MAX;
		}
		return $m[1] === '-' ? -(int)$digits : (int)$digits;
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
	 * UPDATE is tried again. That row can be gone again by then (a decrement
	 * to 0, or a set of 0), so the two are tried in turn, a few times.
	 *
	 * No explicit transaction: each statement is atomic on its own, and on
	 * PostgreSQL a unique violation aborts the whole transaction, which would
	 * make that retry impossible.
	 *
	 * The value never goes above MAX_VALUE either: a delta that would take it
	 * there is refused, with nothing written. The bound is part of the UPDATE,
	 * so two increments can't pass it together.
	 *
	 * On an archived track nothing changes: a client that asked for strict handling
	 * is refused, any other gets the stored value.
	 *
	 * @param ?int $delta Read with {@see parseWholeNumber}, null if it couldn't be; below
	 *                    -MAX_VALUE counts as -MAX_VALUE, which empties any day
	 * @param string|int|float|bool|null $note The day's new note ('' deletes it), or null to leave it
	 * @param string|int|float|bool|null $appendNote Text to add to the day's note, or null; not with `$note`
	 * @param bool $allowDuplicateNote Append `$appendNote` even if the note already holds it
	 * @return array{value: int, note?: string} `note` only when one was sent
	 * @throws DoesNotExistException if track not found
	 * @throws InvalidTrackTypeException if track is not counter
	 * @throws InvalidValueException if delta is missing, not a whole number or 0, the result would pass
	 *                               MAX_VALUE, or the date or the note is not valid, or both notes were sent
	 * @throws NoteConflictException if appending lost to other writers, after the tick was written
	 * @throws ReadOnlyTrackException if the track is archived and the client asked for strict handling
	 */
	public function increment(string $userId, int $trackId, string $date, ?int $delta, string|int|float|bool|null $note = null, string|int|float|bool|null $appendNote = null, bool $allowDuplicateNote = false): array {
		$track = $this->trackMapper->findByIdAndUser($trackId, $userId);
		if ($track->getType() !== 'counter') {
			throw new InvalidTrackTypeException('Increment is only available for counter tracks');
		}
		if ($delta === null || $delta === 0) {
			throw new InvalidValueException('Delta must be a whole number other than 0');
		}
		if ($delta > self::MAX_VALUE) {
			throw self::tooLarge();
		}
		$delta = max($delta, -self::MAX_VALUE);
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
	 * An UPDATE that changes nothing means either no row, or (for a positive
	 * delta) a row too large to take it. The insert tells them apart: the
	 * unique index refuses it when there is a row, which is then read.
	 *
	 * @return int The value after this request's write
	 * @throws InvalidValueException if the stored value can't take the delta
	 * @throws DbException if other requests kept inserting and deleting the row,
	 *                     with the delta not stored
	 */
	private function addDelta(string $userId, int $trackId, string $date, int $delta): int {
		for ($attempt = 1; ; $attempt++) {
			if ($this->tickMapper->addToValue($userId, $trackId, $date, $delta, self::MAX_VALUE) > 0) {
				break;
			}
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
				if ($this->currentValue($userId, $trackId, $date) > self::MAX_VALUE - $delta) {
					throw self::tooLarge();
				}
				if ($attempt >= self::WRITE_ATTEMPTS) {
					throw $e;
				}
				// Another request inserted the day's row meanwhile: add to it
			}
		}

		if ($this->tickMapper->deleteIfNotPositive($userId, $trackId, $date) > 0) {
			return 0;
		}
		return $this->currentValue($userId, $trackId, $date);
	}

	private static function tooLarge(): InvalidValueException {
		return new InvalidValueException('The value would be more than ' . self::MAX_VALUE);
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

	private function deleteTick(string $userId, int $trackId, string $date): void {
		try {
			$this->tickMapper->delete($this->tickMapper->findByUserTrackDate($userId, $trackId, $date));
		} catch (DoesNotExistException) {
			// Already gone
		}
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
