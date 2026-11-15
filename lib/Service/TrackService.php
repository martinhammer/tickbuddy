<?php

declare(strict_types=1);

namespace OCA\Tickbuddy\Service;

use OCA\Tickbuddy\Db\NoteMapper;
use OCA\Tickbuddy\Db\TickMapper;
use OCA\Tickbuddy\Db\Track;
use OCA\Tickbuddy\Db\TrackMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\Exception as DbException;
use OCP\IDBConnection;

class TrackService {
	public const MAX_TRACKS = 99;
	/** The name column's size. Checked here because SQLite doesn't enforce it. */
	public const MAX_NAME_LENGTH = 255;
	public const VALID_TYPES = ['boolean', 'counter'];
	/** Reorder attempts before a reorder racing other writes is told to try again */
	private const REORDER_ATTEMPTS = 3;
	/** Attempts at taking the end of the list, which a create or restore elsewhere can take first */
	private const END_OF_LIST_ATTEMPTS = 3;

	public function __construct(
		private TrackMapper $trackMapper,
		private TickMapper $tickMapper,
		private NoteMapper $noteMapper,
		private ITimeFactory $timeFactory,
		private StrictHandling $strictHandling,
		private IDBConnection $db,
	) {
	}

	/**
	 * Whether a string is a real calendar date written as YYYY-MM-DD.
	 */
	public static function isValidDate(string $value): bool {
		if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) !== 1) {
			return false;
		}
		return checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
	}

	/**
	 * Check a date range sent to a list endpoint, both ends required. One day
	 * (from = to) is a range.
	 *
	 * @throws InvalidValueException if from or to is not YYYY-MM-DD, or from is after to
	 */
	public static function checkDateRange(string $from, string $to): void {
		if (!self::isValidDate($from) || !self::isValidDate($to)) {
			throw new InvalidValueException('From and to must be dates as YYYY-MM-DD');
		}
		if ($from > $to) {
			throw new InvalidValueException('From must be on or before to');
		}
	}

	/**
	 * A track name trimmed, refused when blank or longer than the column.
	 *
	 * @throws InvalidTrackNameException
	 */
	private static function cleanName(string $name): string {
		$name = trim($name);
		if ($name === '') {
			throw new InvalidTrackNameException('Track name cannot be empty');
		}
		if (mb_strlen($name) > self::MAX_NAME_LENGTH) {
			throw new InvalidTrackNameException('Track names can be at most ' . self::MAX_NAME_LENGTH . ' characters');
		}
		return $name;
	}

	/**
	 * @param bool $includeArchived Whether to include archived tracks
	 * @return Track[]
	 */
	public function findAll(string $userId, bool $includeArchived = false): array {
		return $includeArchived
			? $this->trackMapper->findAllByUser($userId)
			: $this->trackMapper->findActiveByUser($userId);
	}

	/**
	 * @throws DoesNotExistException
	 */
	public function find(int $id, string $userId): Track {
		return $this->trackMapper->findByIdAndUser($id, $userId);
	}

	/**
	 * Create a track at the end of the list. A create or restore elsewhere can
	 * take that place between reading it and inserting: the unique index then
	 * refuses this insert, and the limit and the end are read again.
	 *
	 * @throws TrackLimitReachedException
	 * @throws InvalidTrackNameException
	 * @throws InvalidTrackTypeException
	 * @throws DbException if other writes kept taking the end of the list
	 */
	public function create(string $name, string $type, string $userId): Track {
		$name = self::cleanName($name);
		if (!in_array($type, self::VALID_TYPES, true)) {
			throw new InvalidTrackTypeException('Invalid track type: ' . $type);
		}

		for ($attempt = 1; ; $attempt++) {
			if ($this->trackMapper->countByUser($userId) >= self::MAX_TRACKS) {
				throw new TrackLimitReachedException('Maximum of ' . self::MAX_TRACKS . ' tracks reached');
			}

			$track = new Track();
			$track->setUserId($userId);
			$track->setName($name);
			$track->setType($type);
			$track->setSortOrder($this->trackMapper->getMaxSortOrder($userId) + 1);
			try {
				return $this->trackMapper->insert($track);
			} catch (DbException $e) {
				if ($e->getReason() !== DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION || $attempt >= self::END_OF_LIST_ATTEMPTS) {
					throw $e;
				}
			}
		}
	}

	/**
	 * Update a track. Name, sort order and private are applied first, then
	 * archive or restore, so a restore's end-of-list position wins over a
	 * sort order sent with it.
	 *
	 * An archived track only takes a restore (with or without other fields).
	 * Any other change, re-archiving included, is refused for clients that
	 * asked for strict handling and quietly ignored for the rest.
	 *
	 * @param ?bool $archived True archives (on an archived track: refused for strict
	 *                        clients, ignored for the rest), false restores
	 * @param ?string $archivedAt Archive date as YYYY-MM-DD, the client's today; the
	 *                            server's date if null. Only read when archiving.
	 * @throws DoesNotExistException
	 * @throws InvalidTrackNameException
	 * @throws InvalidArchiveDateException
	 * @throws ReadOnlyTrackException if the track is archived, this isn't a restore, and
	 *                                the client asked for strict handling
	 */
	public function update(
		int $id,
		string $userId,
		?string $name = null,
		?int $sortOrder = null,
		?bool $private = null,
		?bool $archived = null,
		?string $archivedAt = null,
	): Track {
		$track = $this->trackMapper->findByIdAndUser($id, $userId);

		if ($archived === true && $archivedAt !== null && !self::isValidDate($archivedAt)) {
			throw new InvalidArchiveDateException('Archive date must be a date as YYYY-MM-DD');
		}
		// Checked before anything is changed on the entity, and before the archived
		// check, so a blank name is a 400 on an archived track too
		$name = $name === null ? null : self::cleanName($name);
		// An archived track can only be restored or deleted. Anything else (re-archiving
		// included) is refused for clients that asked, and quietly ignored for the rest.
		if ($track->getArchivedAt() !== null && $archived !== false
			&& ($name !== null || $sortOrder !== null || $private !== null || $archived === true)) {
			$this->strictHandling->refuseArchived($track);
			return $track;
		}
		if ($name !== null) {
			$track->setName($name);
		}
		if ($sortOrder !== null) {
			$track->setSortOrder($sortOrder);
		}
		if ($private !== null) {
			$track->setPrivate($private ? 1 : 0);
		}
		if ($archived === true) {
			$track->setArchivedAt($archivedAt ?? $this->timeFactory->getDateTime()->format('Y-m-d'));
		} elseif ($archived === false && $track->getArchivedAt() !== null) {
			$track->setArchivedAt(null);
			return $this->updateAtEnd($track, $userId);
		}

		return $this->trackMapper->update($track);
	}

	/**
	 * Save a track moved to the end of the list. The max includes this track's
	 * own position, but a create or restore elsewhere can take max + 1 between
	 * reading it and writing: the unique index then refuses this update, and
	 * the end is read again.
	 *
	 * @throws DbException if other writes kept taking the end of the list
	 */
	private function updateAtEnd(Track $track, string $userId): Track {
		for ($attempt = 1; ; $attempt++) {
			$track->setSortOrder($this->trackMapper->getMaxSortOrder($userId) + 1);
			try {
				return $this->trackMapper->update($track);
			} catch (DbException $e) {
				if ($e->getReason() !== DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION || $attempt >= self::END_OF_LIST_ATTEMPTS) {
					throw $e;
				}
			}
		}
	}

	/**
	 * Put the given tracks first, in the order given, and every other track of
	 * the user (archived ones, or ones the client didn't know about) after them
	 * in their previous order. Ids that aren't the user's are dropped.
	 *
	 * A list naming an archived track changes nothing: refused for clients that
	 * asked for strict handling, quietly ignored for the rest.
	 *
	 * In one transaction, with the user's tracks locked from the read on, so a
	 * renumbering that fails halfway leaves the previous order rather than a
	 * mix of old and new numbers. A track created or restored elsewhere in the
	 * meantime can still take a number this one hands out; the whole reorder is
	 * then rolled back and tried again with that track in the list.
	 *
	 * @param int[] $trackIds Ordered list of track IDs
	 * @return Track[] The active tracks, in the new order
	 * @throws ReadOnlyTrackException if an archived track is listed and the client asked for strict handling
	 * @throws TrackOrderConflictException if other writes kept taking the numbers
	 */
	public function reorder(array $trackIds, string $userId): array {
		for ($attempt = 0; $attempt < self::REORDER_ATTEMPTS; $attempt++) {
			$this->db->beginTransaction();
			try {
				$this->reorderLocked($trackIds, $userId);
				$this->db->commit();
				return $this->trackMapper->findActiveByUser($userId);
			} catch (DbException $e) {
				$this->db->rollBack();
				if ($e->getReason() !== DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
					throw $e;
				}
			} catch (\Throwable $e) {
				$this->db->rollBack();
				throw $e;
			}
		}
		throw new TrackOrderConflictException('The tracks changed while reordering; try again');
	}

	/**
	 * The reorder itself, inside reorder()'s transaction.
	 *
	 * @param int[] $trackIds
	 * @throws ReadOnlyTrackException
	 */
	private function reorderLocked(array $trackIds, string $userId): void {
		$all = $this->trackMapper->findAllByUser($userId, true);
		// A reorder that lists an archived track is refused whole for strict clients,
		// and ignored whole for the rest. Ids not in $byId aren't the user's.
		$byId = [];
		foreach ($all as $track) {
			$byId[$track->getId()] = $track;
		}
		foreach ($trackIds as $id) {
			if (isset($byId[$id]) && $byId[$id]->getArchivedAt() !== null) {
				$this->strictHandling->refuseArchived($byId[$id]);
				return;
			}
		}
		$order = self::fullOrder($trackIds, array_map(fn (Track $t) => $t->getId(), $all));
		$this->trackMapper->reorder($userId, $order);
	}

	/**
	 * The ids of $wanted that are in $current (each once, at its first place),
	 * followed by the rest of $current in its own order.
	 *
	 * @internal Public for the tests
	 * @param int[] $wanted
	 * @param int[] $current
	 * @return list<int>
	 */
	public static function fullOrder(array $wanted, array $current): array {
		$known = array_fill_keys($current, true);
		$order = [];
		foreach ($wanted as $id) {
			if (isset($known[$id]) && !isset($order[$id])) {
				$order[$id] = $id;
			}
		}
		foreach ($current as $id) {
			if (!isset($order[$id])) {
				$order[$id] = $id;
			}
		}
		return array_values($order);
	}

	/**
	 * @throws DoesNotExistException
	 */
	public function delete(int $id, string $userId): void {
		$track = $this->trackMapper->findByIdAndUser($id, $userId);
		$this->tickMapper->deleteByTrackId($id);
		$this->noteMapper->deleteByTrackId($id);
		$this->trackMapper->delete($track);
	}
}
