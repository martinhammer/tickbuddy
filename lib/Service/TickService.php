<?php

declare(strict_types=1);

namespace OCA\Tickbuddy\Service;

use OCA\Tickbuddy\Db\Tick;
use OCA\Tickbuddy\Db\TickMapper;
use OCA\Tickbuddy\Db\TrackMapper;
use OCP\AppFramework\Db\DoesNotExistException;

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
	 */
	public function toggle(string $userId, int $trackId, string $date): bool {
		$track = $this->trackMapper->findByIdAndUser($trackId, $userId);
		if ($track->getType() !== 'boolean') {
			throw new InvalidTrackTypeException('Toggle is only available for boolean tracks');
		}
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
			$tick = new Tick();
			$tick->setUserId($userId);
			$tick->setTrackId($trackId);
			$tick->setDate($date);
			$tick->setValue(1);
			$this->tickMapper->insert($tick);
			return true;
		}
	}

	/**
	 * Set the value for a counter track tick. Deletes the tick if value is 0.
	 *
	 * On an archived track nothing changes and the answer is the stored value.
	 *
	 * @throws DoesNotExistException if track not found
	 * @throws InvalidTrackTypeException if track is not counter
	 */
	public function set(string $userId, int $trackId, string $date, int $value): int {
		$track = $this->trackMapper->findByIdAndUser($trackId, $userId);
		if ($track->getType() !== 'counter') {
			throw new InvalidTrackTypeException('Set is only available for counter tracks');
		}
		if ($track->getArchivedAt() !== null) {
			// As in toggle(): ignore the write, answer with what's stored
			return $this->currentValue($userId, $trackId, $date);
		}

		try {
			$tick = $this->tickMapper->findByUserTrackDate($userId, $trackId, $date);
			if ($value <= 0) {
				$this->tickMapper->delete($tick);
				return 0;
			}
			$tick->setValue($value);
			$this->tickMapper->update($tick);
			return $value;
		} catch (DoesNotExistException) {
			if ($value <= 0) {
				return 0;
			}
			$tick = new Tick();
			$tick->setUserId($userId);
			$tick->setTrackId($trackId);
			$tick->setDate($date);
			$tick->setValue($value);
			$this->tickMapper->insert($tick);
			return $value;
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
