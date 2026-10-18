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
	 * On an archived track nothing changes and the answer is whether a tick exists.
	 *
	 * @throws DoesNotExistException if track not found
	 * @throws InvalidTrackTypeException if track is not boolean
	 * @throws InvalidValueException if the date is not YYYY-MM-DD
	 */
	public function toggle(string $userId, int $trackId, string $date): bool {
		$track = $this->trackMapper->findByIdAndUser($trackId, $userId);
		if ($track->getType() !== 'boolean') {
			throw new InvalidTrackTypeException('Toggle is only available for boolean tracks');
		}
		self::checkDate($date);
		if ($track->getArchivedAt() !== null) {
			// Archived history can't change. Answer with the stored state so an offline
			// client's late upload doesn't stall its queue; its next pull drops the tick.
			return $this->currentValue($userId, $trackId, $date) > 0;
		}

		try {
			$tick = $this->tickMapper->findByUserTrackDate($userId, $trackId, $date);
			$this->tickMapper->delete($tick);
			return false;
		} catch (DoesNotExistException) {
			$this->insertTick($userId, $trackId, $date, 1);
			return true;
		}
	}

	/**
	 * Set the value for a tick. Counter: the absolute value, 0 deletes. Yes/no:
	 * true ticks, false unticks; answers whether it is ticked.
	 *
	 * Unlike toggle(), setting a yes/no day is idempotent, so a retried request
	 * can never undo itself.
	 *
	 * On an archived track nothing changes and the answer is the stored state.
	 *
	 * @param mixed $value Counter: a number. Yes/no: true/false, 1/0, on/off or yes/no.
	 * @return array{value: int|bool}
	 * @throws DoesNotExistException if track not found
	 * @throws InvalidValueException if a yes/no value or the date is not valid
	 */
	public function set(string $userId, int $trackId, string $date, mixed $value): array {
		$track = $this->trackMapper->findByIdAndUser($trackId, $userId);
		if ($track->getType() === 'boolean') {
			return ['value' => $this->setYesNo($track, $userId, $date, $value)];
		}

		// Read as before this accepted yes/no values: a missing value is 0
		$value = is_scalar($value) ? (int)$value : 0;
		self::checkDate($date);
		if ($track->getArchivedAt() !== null) {
			// As in toggle(): ignore the write, answer with what's stored
			return ['value' => $this->currentValue($userId, $trackId, $date)];
		}

		try {
			$tick = $this->tickMapper->findByUserTrackDate($userId, $trackId, $date);
			if ($value <= 0) {
				$this->tickMapper->delete($tick);
				return ['value' => 0];
			}
			$tick->setValue($value);
			$this->tickMapper->update($tick);
			return ['value' => $value];
		} catch (DoesNotExistException) {
			if ($value <= 0) {
				return ['value' => 0];
			}
			$this->insertTick($userId, $trackId, $date, $value);
			return ['value' => $value];
		}
	}

	/**
	 * @throws InvalidValueException
	 */
	private function setYesNo(Track $track, string $userId, string $date, mixed $value): bool {
		$ticked = self::parseYesNo($value);
		if ($ticked === null) {
			throw new InvalidValueException('Yes/no tracks take a value of true or false');
		}
		self::checkDate($date);
		$trackId = $track->getId();
		if ($track->getArchivedAt() !== null) {
			return $this->currentValue($userId, $trackId, $date) > 0;
		}

		try {
			$tick = $this->tickMapper->findByUserTrackDate($userId, $trackId, $date);
			if (!$ticked) {
				$this->tickMapper->delete($tick);
			}
			return $ticked;
		} catch (DoesNotExistException) {
			if (!$ticked) {
				return false;
			}
			try {
				$this->insertTick($userId, $trackId, $date, 1);
			} catch (DbException $e) {
				// Another request ticked the day in between: it is ticked, as asked
				if ($e->getReason() !== DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
					throw $e;
				}
			}
			return true;
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
	 * On an archived track nothing changes and the answer is the stored value.
	 *
	 * @throws DoesNotExistException if track not found
	 * @throws InvalidTrackTypeException if track is not counter
	 * @throws InvalidValueException if delta is 0 or the date is not valid
	 */
	public function increment(string $userId, int $trackId, string $date, int $delta): int {
		$track = $this->trackMapper->findByIdAndUser($trackId, $userId);
		if ($track->getType() !== 'counter') {
			throw new InvalidTrackTypeException('Increment is only available for counter tracks');
		}
		if ($delta === 0) {
			throw new InvalidValueException('Delta must be a whole number other than 0');
		}
		self::checkDate($date);
		if ($track->getArchivedAt() !== null) {
			return $this->currentValue($userId, $trackId, $date);
		}

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
