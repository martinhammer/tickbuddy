<?php

declare(strict_types=1);

namespace OCA\Tickbuddy\Service;

use OCA\Tickbuddy\Db\TickMapper;
use OCA\Tickbuddy\Db\Track;
use OCA\Tickbuddy\Db\TrackMapper;
use OCP\AppFramework\Db\DoesNotExistException;

class TrackService {
	public const MAX_TRACKS = 99;
	public const VALID_TYPES = ['boolean', 'counter'];

	public function __construct(
		private TrackMapper $trackMapper,
		private TickMapper $tickMapper,
	) {
	}

	/**
	 * @return Track[]
	 */
	public function findAll(string $userId): array {
		return $this->trackMapper->findAllByUser($userId);
	}

	/**
	 * @throws DoesNotExistException
	 */
	public function find(int $id, string $userId): Track {
		return $this->trackMapper->findByIdAndUser($id, $userId);
	}

	/**
	 * @throws TrackLimitReachedException
	 * @throws InvalidTrackTypeException
	 */
	public function create(string $name, string $type, string $userId): Track {
		$name = trim($name);
		if ($name === '') {
			throw new InvalidTrackNameException('Track name cannot be empty');
		}
		if (!in_array($type, self::VALID_TYPES, true)) {
			throw new InvalidTrackTypeException('Invalid track type: ' . $type);
		}

		if ($this->trackMapper->countByUser($userId) >= self::MAX_TRACKS) {
			throw new TrackLimitReachedException('Maximum of ' . self::MAX_TRACKS . ' tracks reached');
		}

		$sortOrder = $this->trackMapper->getMaxSortOrder($userId) + 1;

		$track = new Track();
		$track->setUserId($userId);
		$track->setName($name);
		$track->setType($type);
		$track->setSortOrder($sortOrder);
		return $this->trackMapper->insert($track);
	}

	/**
	 * @throws DoesNotExistException
	 */
	public function update(int $id, string $userId, ?string $name = null, ?int $sortOrder = null, ?bool $private = null): Track {
		$track = $this->trackMapper->findByIdAndUser($id, $userId);

		if ($name !== null) {
			$name = trim($name);
			if ($name === '') {
				throw new InvalidTrackNameException('Track name cannot be empty');
			}
			$track->setName($name);
		}
		if ($sortOrder !== null) {
			$track->setSortOrder($sortOrder);
		}
		if ($private !== null) {
			$track->setPrivate($private);
		}

		return $this->trackMapper->update($track);
	}

	/**
	 * @param int[] $trackIds Ordered list of track IDs
	 * @return Track[]
	 */
	public function reorder(array $trackIds, string $userId): array {
		$this->trackMapper->reorder($userId, $trackIds);
		return $this->trackMapper->findAllByUser($userId);
	}

	/**
	 * @throws DoesNotExistException
	 */
	public function delete(int $id, string $userId): void {
		$track = $this->trackMapper->findByIdAndUser($id, $userId);
		$this->tickMapper->deleteByTrackId($id);
		$this->trackMapper->delete($track);
	}
}
