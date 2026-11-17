<?php

declare(strict_types=1);

namespace OCA\Tickbuddy\Service;

use OCA\Tickbuddy\Db\NoteMapper;
use OCA\Tickbuddy\Db\PublicLinkMapper;
use OCA\Tickbuddy\Db\TickMapper;
use OCA\Tickbuddy\Db\Track;
use OCA\Tickbuddy\Db\TrackMapper;
use OCA\Tickbuddy\Db\TrackOption;
use OCA\Tickbuddy\Db\TrackOptionMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\Exception as DbException;
use OCP\IDBConnection;

class TrackService {
	public const MAX_TRACKS = 99;
	/** The name column's size. Checked here because SQLite doesn't enforce it. */
	public const MAX_NAME_LENGTH = 255;
	public const VALID_TYPES = ['boolean', 'counter', 'number', 'choice'];
	/**
	 * The types every client knows. Reads leave out tracks of other types (and
	 * their ticks, notes and stats) unless the client sends `includeAllTypes`,
	 * so a client that predates a type never meets it.
	 */
	public const BASE_TYPES = ['boolean', 'counter'];
	public const MIN_OPTIONS = 2;
	public const MAX_OPTIONS = 99;
	public const MAX_DECIMAL_PLACES = 3;
	public const DEFAULT_DECIMAL_PLACES = 1;
	/** Reorder attempts before a reorder racing other writes is told to try again */
	private const REORDER_ATTEMPTS = 3;
	/** Attempts at taking the end of the list, which a create or restore elsewhere can take first */
	private const END_OF_LIST_ATTEMPTS = 3;

	public function __construct(
		private TrackMapper $trackMapper,
		private TickMapper $tickMapper,
		private NoteMapper $noteMapper,
		private PublicLinkMapper $publicLinkMapper,
		private ITimeFactory $timeFactory,
		private StrictHandling $strictHandling,
		private IDBConnection $db,
		private TrackOptionMapper $optionMapper,
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
	 * An option label trimmed, refused when blank or longer than the column.
	 *
	 * @throws InvalidValueException
	 */
	private static function cleanLabel(mixed $label): string {
		if (!is_string($label) && !is_int($label) && !is_float($label)) {
			throw new InvalidValueException('Options must be a list of labels');
		}
		$label = trim((string)$label);
		if ($label === '') {
			throw new InvalidValueException('Option labels cannot be empty');
		}
		if (mb_strlen($label) > self::MAX_NAME_LENGTH) {
			throw new InvalidValueException('Option labels can be at most ' . self::MAX_NAME_LENGTH . ' characters');
		}
		return $label;
	}

	/**
	 * A Number's unit trimmed, null when empty; refused when longer than the column.
	 *
	 * @throws InvalidValueException
	 */
	private static function cleanUnit(string $unit): ?string {
		$unit = trim($unit);
		if (mb_strlen($unit) > self::MAX_NAME_LENGTH) {
			throw new InvalidValueException('Units can be at most ' . self::MAX_NAME_LENGTH . ' characters');
		}
		return $unit === '' ? null : $unit;
	}

	/**
	 * @throws InvalidValueException unless it is a whole number from 0 to 3
	 */
	private static function decimalPlaces(string|int|float|bool $value): int {
		$places = TickService::parseWholeNumber($value, false);
		if ($places === null || $places > self::MAX_DECIMAL_PLACES) {
			throw new InvalidValueException('Decimal places must be 0, 1, 2 or 3');
		}
		return $places;
	}

	/**
	 * Refuse two labels that are the same once trimmed, ignoring case.
	 *
	 * @param string[] $labels Cleaned labels
	 * @throws InvalidValueException
	 */
	private static function checkDistinct(array $labels): void {
		$seen = [];
		foreach ($labels as $label) {
			$key = mb_strtolower($label);
			if (isset($seen[$key])) {
				throw new InvalidValueException('Two options can\'t have the same label: "' . $label . '"');
			}
			$seen[$key] = true;
		}
	}

	/**
	 * Refuse settings sent for a track of another type.
	 *
	 * @throws InvalidValueException
	 */
	private static function checkSettingsFit(string $type, TrackSettings $settings): void {
		if ($type !== 'number' && $settings->numberFields() !== []) {
			throw new InvalidValueException('unit, decimalPlaces and allowNegative are only for number tracks');
		}
		if ($type !== 'choice' && $settings->choiceFields() !== []) {
			throw new InvalidValueException('options and orderedScale are only for choice tracks');
		}
	}

	/**
	 * @param bool $includeArchived Whether to include archived tracks
	 * @param bool $includeAllTypes Whether to include tracks of types not in BASE_TYPES
	 * @return Track[]
	 */
	public function findAll(string $userId, bool $includeArchived = false, bool $includeAllTypes = true): array {
		$types = $includeAllTypes ? null : self::BASE_TYPES;
		return $includeArchived
			? $this->trackMapper->findAllByUser($userId, false, $types)
			: $this->trackMapper->findActiveByUser($userId, $types);
	}

	/**
	 * Several tracks' options in one query, as track id => options in place
	 * order; tracks without options are absent.
	 *
	 * @param Track[] $tracks
	 * @return array<int, list<TrackOption>>
	 */
	public function optionsFor(array $tracks, string $userId): array {
		$choices = array_filter($tracks, fn (Track $t) => $t->getType() === 'choice');
		return $this->optionMapper->findByTracks(array_map(fn (Track $t) => $t->getId(), $choices), $userId);
	}

	/**
	 * A Choice track's options, in place order.
	 *
	 * @return list<TrackOption>
	 */
	public function options(Track $track): array {
		return $track->getType() === 'choice' ? array_values($this->optionMapper->findByTrack($track->getId(), $track->getUserId())) : [];
	}

	/**
	 * @throws DoesNotExistException
	 */
	public function find(int $id, string $userId): Track {
		return $this->trackMapper->findByIdAndUser($id, $userId);
	}

	/**
	 * Create a track at the end of the list, with a Choice's options, in one
	 * transaction, so a failure never leaves a Choice without its options. A
	 * create or restore elsewhere can take the end of the list between reading
	 * it and inserting: the unique index then refuses this insert, the
	 * transaction is rolled back, and the limit and the end are read again.
	 *
	 * Checks, in order, before anything is written: name, type, settings of
	 * another type, the type's own settings, then the track limit.
	 *
	 * @throws TrackLimitReachedException
	 * @throws InvalidTrackNameException
	 * @throws InvalidTrackTypeException
	 * @throws InvalidValueException if a setting is wrong or belongs to another type
	 * @throws DbException if other writes kept taking the end of the list
	 */
	public function create(string $name, string $type, string $userId, ?TrackSettings $settings = null): Track {
		$settings ??= new TrackSettings();
		$name = self::cleanName($name);
		if (!in_array($type, self::VALID_TYPES, true)) {
			throw new InvalidTrackTypeException('Invalid track type: ' . $type);
		}
		self::checkSettingsFit($type, $settings);
		$labels = [];
		$unit = null;
		$decimalPlaces = null;
		$allowNegative = null;
		$orderedScale = null;
		if ($type === 'number') {
			$unit = $settings->unit === null ? null : self::cleanUnit($settings->unit);
			$decimalPlaces = $settings->decimalPlaces === null ? self::DEFAULT_DECIMAL_PLACES : self::decimalPlaces($settings->decimalPlaces);
			$allowNegative = Flags::read('allowNegative', $settings->allowNegative, false) ? 1 : 0;
		} elseif ($type === 'choice') {
			$options = $settings->options ?? [];
			if (!array_is_list($options) || count($options) < self::MIN_OPTIONS || count($options) > self::MAX_OPTIONS) {
				throw new InvalidValueException('Choice tracks need ' . self::MIN_OPTIONS . ' to ' . self::MAX_OPTIONS . ' options');
			}
			$labels = array_map(fn ($label) => self::cleanLabel($label), $options);
			self::checkDistinct($labels);
			$orderedScale = Flags::read('orderedScale', $settings->orderedScale, false) ? 1 : 0;
		}

		for ($attempt = 1; ; $attempt++) {
			if ($this->trackMapper->countByUser($userId) >= self::MAX_TRACKS) {
				throw new TrackLimitReachedException('Maximum of ' . self::MAX_TRACKS . ' tracks reached');
			}

			// A new entity each attempt: a rolled-back insert leaves its id on the old one
			$track = new Track();
			$track->setUserId($userId);
			$track->setName($name);
			$track->setType($type);
			$track->setUnit($unit);
			$track->setDecimalPlaces($decimalPlaces);
			$track->setAllowNegative($allowNegative);
			$track->setOrderedScale($orderedScale);
			$track->setSortOrder($this->trackMapper->getMaxSortOrder($userId) + 1);
			$this->db->beginTransaction();
			try {
				$track = $this->trackMapper->insert($track);
				$this->insertOptions($track, $labels);
				$this->db->commit();
				return $track;
			} catch (DbException $e) {
				$this->db->rollBack();
				if ($e->getReason() !== DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION || $attempt >= self::END_OF_LIST_ATTEMPTS) {
					throw $e;
				}
			} catch (\Throwable $e) {
				$this->db->rollBack();
				throw $e;
			}
		}
	}

	/**
	 * Store a new Choice track's options, the first label at place 1.
	 *
	 * @param list<string> $labels
	 */
	private function insertOptions(Track $track, array $labels): void {
		foreach ($labels as $index => $label) {
			$option = new TrackOption();
			$option->setUserId($track->getUserId());
			$option->setTrackId($track->getId());
			$option->setPlace($index + 1);
			$option->setLabel($label);
			$this->optionMapper->insert($option);
		}
	}

	/**
	 * Update a track. Name, sort order and private are applied first, then
	 * archive or restore, so a restore's end-of-list position wins over a
	 * sort order sent with it.
	 *
	 * Archiving revokes every public link of the track; restoring brings none back.
	 *
	 * An archived track only takes a restore (with or without other fields).
	 * Any other change, re-archiving included, is refused for clients that
	 * asked for strict handling and quietly ignored for the rest.
	 *
	 * @param ?bool $archived True archives (on an archived track: refused for strict
	 *                        clients, ignored for the rest), false restores
	 * @param ?string $archivedAt Archive date as YYYY-MM-DD, the client's today; the
	 *                            server's date if null. Only read when archiving.
	 * @param ?TrackSettings $settings A Number's unit (empty removes it), decimal places
	 *                                 and allowNegative, or a Choice's orderedScale;
	 *                                 null fields are left as they are. Never `options`.
	 * @throws DoesNotExistException
	 * @throws InvalidTrackNameException
	 * @throws InvalidValueException if a setting is wrong or belongs to another type
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
		?TrackSettings $settings = null,
	): Track {
		$track = $this->trackMapper->findByIdAndUser($id, $userId);
		$settings ??= new TrackSettings();

		if ($archived === true && $archivedAt !== null && !self::isValidDate($archivedAt)) {
			throw new InvalidArchiveDateException('Archive date must be a date as YYYY-MM-DD');
		}
		// Checked before anything is changed on the entity, and before the archived
		// check, so a blank name is a 400 on an archived track too
		$name = $name === null ? null : self::cleanName($name);
		// The type settings likewise. Options themselves are renamed one at a time
		// (renameOption), so they never reach here.
		if ($settings->options !== null) {
			throw new InvalidValueException('Options can\'t be changed here: rename one with PUT /api/tracks/{id}/options/{value}');
		}
		self::checkSettingsFit($track->getType(), $settings);
		$unit = $settings->unit === null ? false : self::cleanUnit($settings->unit);
		$decimalPlaces = $settings->decimalPlaces === null ? null : self::decimalPlaces($settings->decimalPlaces);
		$allowNegative = Flags::readOptional('allowNegative', $settings->allowNegative);
		$orderedScale = Flags::readOptional('orderedScale', $settings->orderedScale);
		$changesSettings = $unit !== false || $decimalPlaces !== null || $allowNegative !== null || $orderedScale !== null;
		// An archived track can only be restored or deleted. Anything else (re-archiving
		// included) is refused for clients that asked, and quietly ignored for the rest.
		if ($track->getArchivedAt() !== null && $archived !== false
			&& ($name !== null || $sortOrder !== null || $private !== null || $changesSettings || $archived === true)) {
			$this->strictHandling->refuseArchived($track);
			return $track;
		}
		// Settings never change a stored value: fewer decimals only show older days rounded
		if ($unit !== false) {
			$track->setUnit($unit);
		}
		if ($decimalPlaces !== null) {
			$track->setDecimalPlaces($decimalPlaces);
		}
		if ($allowNegative !== null) {
			$track->setAllowNegative($allowNegative ? 1 : 0);
		}
		if ($orderedScale !== null) {
			$track->setOrderedScale($orderedScale ? 1 : 0);
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
			// Only an active track gets here: re-archiving returned above, with nothing left to revoke
			$track->setArchivedAt($archivedAt ?? $this->timeFactory->getDateTime()->format('Y-m-d'));
			$track = $this->trackMapper->update($track);
			$this->publicLinkMapper->revokeByTrack($track->getId(), $this->timeFactory->getTime());
			return $track;
		} elseif ($archived === false && $track->getArchivedAt() !== null) {
			$track->setArchivedAt(null);
			return $this->updateAtEnd($track, $userId);
		}

		return $this->trackMapper->update($track);
	}

	/**
	 * Rename one option of a Choice track. The option's place, and so every
	 * day that holds it, stays as it is.
	 *
	 * An archived track is refused for every client: no client that predates
	 * archiving calls this.
	 *
	 * @throws DoesNotExistException if the track isn't the user's
	 * @throws InvalidTrackTypeException if the track isn't a Choice
	 * @throws OptionNotFoundException if the track has no option at this place
	 * @throws InvalidValueException if the label is blank, too long or another option's
	 * @throws ReadOnlyTrackException if the track is archived
	 */
	public function renameOption(int $trackId, string $userId, int $place, string $label): Track {
		$track = $this->trackMapper->findByIdAndUser($trackId, $userId);
		if ($track->getType() !== 'choice') {
			throw new InvalidTrackTypeException('Only choice tracks have options');
		}
		$options = $this->optionMapper->findByTrack($trackId, $userId);
		$option = null;
		$others = [];
		foreach ($options as $o) {
			if ($o->getPlace() === $place) {
				$option = $o;
			} else {
				$others[] = $o->getLabel();
			}
		}
		if ($option === null) {
			throw new OptionNotFoundException('Option not found');
		}
		$label = self::cleanLabel($label);
		self::checkDistinct([...$others, $label]);
		if ($track->getArchivedAt() !== null) {
			throw new ReadOnlyTrackException($track->getName() . ' is archived, so nothing was changed', ErrorReason::ARCHIVED);
		}
		if ($label !== $option->getLabel()) {
			$option->setLabel($label);
			$this->optionMapper->update($option);
		}
		return $track;
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
	 * @param bool $includeAllTypes Whether the answer includes tracks of types not in
	 *                              BASE_TYPES; the reorder itself covers every track
	 * @return Track[] The active tracks, in the new order
	 * @throws ReadOnlyTrackException if an archived track is listed and the client asked for strict handling
	 * @throws TrackOrderConflictException if other writes kept taking the numbers
	 */
	public function reorder(array $trackIds, string $userId, bool $includeAllTypes = true): array {
		for ($attempt = 0; $attempt < self::REORDER_ATTEMPTS; $attempt++) {
			$this->db->beginTransaction();
			try {
				$this->reorderLocked($trackIds, $userId);
				$this->db->commit();
				return $this->trackMapper->findActiveByUser($userId, $includeAllTypes ? null : self::BASE_TYPES);
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
		$this->publicLinkMapper->deleteByTrackId($id);
		$this->optionMapper->deleteByTrackId($id);
		$this->trackMapper->delete($track);
	}
}
