<?php

declare(strict_types=1);

namespace OCA\Tickbuddy\Service;

use OCA\Tickbuddy\Db\Note;
use OCA\Tickbuddy\Db\NoteMapper;
use OCA\Tickbuddy\Db\Tick;
use OCA\Tickbuddy\Db\TickMapper;
use OCA\Tickbuddy\Db\Track;
use OCA\Tickbuddy\Db\TrackMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;

class ImportService {
	public const MODE_REPLACE = 'replace';
	public const MODE_MERGE = 'merge';
	/** Added in Merge to an imported track whose name is already taken */
	private const IMPORTED_SUFFIX = ' (imported)';

	public function __construct(
		private TrackMapper $trackMapper,
		private TickMapper $tickMapper,
		private NoteMapper $noteMapper,
		private IDBConnection $db,
		private ITimeFactory $timeFactory,
	) {
	}

	/**
	 * Import tracks and ticks from a Tickmate SQLite backup file.
	 *
	 * @param string $filePath Path to the uploaded SQLite file
	 * @param string $mode 'replace' or 'merge'
	 * @param string $userId Current user ID
	 * @return array{tracks: int, ticks: int, notes: int} Count of imported items
	 * @throws ImportException
	 */
	public function importTickmate(string $filePath, string $mode, string $userId): array {
		if (!in_array($mode, [self::MODE_REPLACE, self::MODE_MERGE], true)) {
			throw new ImportException('Invalid import mode');
		}

		if (!file_exists($filePath)) {
			throw new ImportException('Upload file not found');
		}

		$sqlite = new \SQLite3($filePath, SQLITE3_OPEN_READONLY);

		try {
			$this->validateTickmateDb($sqlite);
			$tmTracks = $this->readTickmateTracks($sqlite);
			$tmTicks = $this->readTickmateTicks($sqlite, $tmTracks);
		} finally {
			$sqlite->close();
		}

		if (empty($tmTracks)) {
			throw new ImportException('No tracks found in the backup file');
		}

		$counterTracks = $this->detectCounterTracks($tmTracks, $tmTicks);

		$this->db->beginTransaction();
		try {
			$today = $this->timeFactory->getDateTime()->format('Y-m-d');
			$trackIdMap = $this->importTracks($tmTracks, $tmTicks, $counterTracks, $today, $mode, $userId);
			$tickCount = $this->importTicks($tmTicks, $trackIdMap, $counterTracks, $userId);
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw new ImportException('Import failed: ' . $e->getMessage());
		}

		// Tickmate has no notes
		return ['tracks' => count($trackIdMap), 'ticks' => $tickCount, 'notes' => 0];
	}

	/**
	 * Import tracks and ticks from a Tickbuddy JSON backup file.
	 *
	 * @param string $filePath Path to the uploaded JSON file
	 * @param string $mode 'replace' or 'merge'
	 * @param string $userId Current user ID
	 * @return array{tracks: int, ticks: int, notes: int} Count of imported items
	 * @throws ImportException
	 */
	public function importJson(string $filePath, string $mode, string $userId): array {
		if (!in_array($mode, [self::MODE_REPLACE, self::MODE_MERGE], true)) {
			throw new ImportException('Invalid import mode');
		}

		if (!file_exists($filePath)) {
			throw new ImportException('Upload file not found');
		}

		$json = file_get_contents($filePath);
		if ($json === false) {
			throw new ImportException('Could not read file');
		}

		$data = json_decode($json, true);
		if (!is_array($data)) {
			throw new ImportException('Invalid JSON file');
		}

		if (!isset($data['version']) || $data['version'] !== 1) {
			throw new ImportException('Unsupported backup version');
		}

		if (!isset($data['tracks']) || !is_array($data['tracks'])) {
			throw new ImportException('Invalid backup: missing tracks');
		}

		$jsonTracks = array_values($data['tracks']);
		$jsonTicks = self::optionalList($data, 'ticks');
		$jsonNotes = self::optionalList($data, 'notes');

		if (empty($jsonTracks)) {
			throw new ImportException('No tracks found in the backup file');
		}

		// Validate and normalize tracks
		$trackEntries = [];
		/** @var array<string, list<int>> $positionsByName */
		$positionsByName = [];
		foreach ($jsonTracks as $i => $t) {
			if (!self::isObject($t)) {
				throw new ImportException('Invalid backup: track ' . ($i + 1) . ' is not an object');
			}
			$name = $this->cleanJsonTrackName($t['name'] ?? '');
			self::checkNameLength($name);
			$type = (string)($t['type'] ?? 'boolean');
			if (!in_array($type, TrackService::VALID_TYPES, true)) {
				throw new ImportException("Track \"{$name}\" has invalid type \"{$type}\"");
			}
			// Missing or null: active (files from before archiving have no key)
			$archivedAt = $t['archivedAt'] ?? null;
			if ($archivedAt !== null && (!is_string($archivedAt) || !TrackService::isValidDate($archivedAt))) {
				$shown = is_string($archivedAt) ? $archivedAt : json_encode($archivedAt, JSON_THROW_ON_ERROR);
				throw new ImportException("Track \"{$name}\" has invalid archive date \"{$shown}\"");
			}
			$trackEntries[] = [
				'name' => $name,
				'type' => $type,
				'sortOrder' => (int)($t['sortOrder'] ?? $i + 1),
				'private' => (bool)($t['private'] ?? false),
				'archivedAt' => $archivedAt,
			];
			$positionsByName[$name][] = $i;
		}

		// Resolve and check every tick and note before writing anything, so a
		// file with one that can't be matched or read changes nothing
		$resolvedTicks = $this->resolveJsonTicks($jsonTicks, $positionsByName, $trackEntries);
		$resolvedNotes = $this->resolveJsonNotes($jsonNotes, $positionsByName, $trackEntries);

		$this->db->beginTransaction();
		try {
			$trackIds = $this->importJsonTracks($trackEntries, $mode, $userId);
			$tickCount = $this->importJsonTicks($resolvedTicks, $trackIds, $userId);
			$noteCount = $this->importJsonNotes($resolvedNotes, $trackIds, $userId);
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			if ($e instanceof ImportException) {
				throw $e;
			}
			throw new ImportException('Import failed: ' . $e->getMessage());
		}

		return ['tracks' => count($trackIds), 'ticks' => $tickCount, 'notes' => $noteCount];
	}

	/**
	 * Refuse a track name longer than the column, before anything is written.
	 *
	 * @throws ImportException
	 */
	private static function checkNameLength(string $name): void {
		if (mb_strlen($name) > TrackService::MAX_NAME_LENGTH) {
			$shown = mb_substr($name, 0, 40) . '…';
			throw new ImportException("Track \"{$shown}\" has a name longer than " . TrackService::MAX_NAME_LENGTH . ' characters');
		}
	}

	/**
	 * Clean a track name from a JSON backup: trimmed, with blank meaning "Untitled".
	 */
	private function cleanJsonTrackName(mixed $name): string {
		$name = trim(is_scalar($name) ? (string)$name : '');
		return $name === '' ? 'Untitled' : $name;
	}

	/**
	 * The `ticks` or `notes` list of a backup: empty when the key is missing.
	 *
	 * @param array<array-key, mixed> $data
	 * @return list<mixed>
	 * @throws ImportException when the key is there but isn't a list
	 */
	private static function optionalList(array $data, string $key): array {
		if (!isset($data[$key])) {
			return [];
		}
		if (!is_array($data[$key]) || !array_is_list($data[$key])) {
			throw new ImportException("Invalid backup: {$key} is not a list");
		}
		return $data[$key];
	}

	/**
	 * Whether a decoded JSON value was an object. Decoded as arrays, `{}` and
	 * `[]` look the same, so an empty one counts as an object.
	 *
	 * @psalm-assert-if-true array<array-key, mixed> $value
	 */
	private static function isObject(mixed $value): bool {
		return is_array($value) && ($value === [] || !array_is_list($value));
	}

	/**
	 * A value from a backup, quoted in a message as it was written.
	 */
	private static function shown(mixed $value): string {
		return is_string($value) ? $value : json_encode($value, JSON_THROW_ON_ERROR);
	}

	/**
	 * Match each JSON tick to the position of its track in the file's tracks
	 * list, and check it.
	 *
	 * A tick's `trackIndex` wins when present; otherwise its `track` name must
	 * match exactly one track. A tick for a track that isn't in the file, with a
	 * date that isn't real, with a value its track can't take, or on a day its
	 * track already has a tick on fails the import. Ticks that mean "nothing"
	 * (a counter's 0, a yes/no track's false) are skipped.
	 *
	 * @param list<mixed> $jsonTicks
	 * @param array<string, list<int>> $positionsByName Cleaned track name → positions in the file
	 * @param list<array{name: string, type: string, ...}> $trackEntries
	 * @return list<array{position: int, date: string, value: int}>
	 * @throws ImportException
	 */
	private function resolveJsonTicks(array $jsonTicks, array $positionsByName, array $trackEntries): array {
		$resolved = [];
		/** @var array<string, true> $seen "position date" of every tick so far */
		$seen = [];

		foreach ($jsonTicks as $t) {
			if (!self::isObject($t)) {
				throw new ImportException('Invalid backup: a tick is not an object');
			}

			$date = $t['date'] ?? '';
			if (!is_string($date) || !TrackService::isValidDate($date)) {
				$name = $this->cleanJsonTrackName($t['track'] ?? '');
				throw new ImportException("Tick on track \"{$name}\" has an invalid date \"" . self::shown($date) . '"');
			}

			$position = $this->resolveJsonTrackPosition($t, 'Tick', $date, $positionsByName, count($trackEntries));
			$track = $trackEntries[$position];

			// Missing: 1, as in files from before counters
			$value = array_key_exists('value', $t)
				? self::jsonTickValue($track['type'], $t['value'])
				: 1;
			if ($value === null) {
				throw new ImportException("Tick on {$date} has an invalid value \"" . self::shown($t['value']) . '"');
			}

			if (isset($seen["{$position} {$date}"])) {
				throw new ImportException("Tick on {$date} for track \"{$track['name']}\" appears twice");
			}
			$seen["{$position} {$date}"] = true;

			if ($value > 0) {
				$resolved[] = ['position' => $position, 'date' => $date, 'value' => $value];
			}
		}

		return $resolved;
	}

	/**
	 * A tick value from a backup: yes/no tracks take what `set` takes (true
	 * is 1, false 0), counters a whole number from 0 up. 0 means no tick.
	 *
	 * @return ?int Null when the track can't take the value
	 */
	private static function jsonTickValue(string $type, mixed $value): ?int {
		if ($type === 'boolean') {
			$ticked = TickService::parseYesNo($value);
			return $ticked === null ? null : (int)$ticked;
		}
		return is_int($value) && $value >= 0 ? $value : null;
	}

	/**
	 * Match each JSON note to its track's position in the file, as for ticks,
	 * and check it with the API's rules. A note that can't be matched or read
	 * fails the import; one whose text is empty after cleaning is skipped, as
	 * empty text means "no note".
	 *
	 * @param list<mixed> $jsonNotes
	 * @param array<string, list<int>> $positionsByName Cleaned track name → positions in the file
	 * @param list<array{name: string, type: string, ...}> $trackEntries
	 * @return list<array{position: int, date: string, text: string}>
	 * @throws ImportException
	 */
	private function resolveJsonNotes(array $jsonNotes, array $positionsByName, array $trackEntries): array {
		$resolved = [];
		/** @var array<string, true> $seen "position date" of every note so far */
		$seen = [];

		foreach ($jsonNotes as $n) {
			if (!self::isObject($n)) {
				throw new ImportException('Invalid backup: a note is not an object');
			}

			$date = $n['date'] ?? '';
			if (!is_string($date) || !TrackService::isValidDate($date)) {
				throw new ImportException('Note has an invalid date "' . self::shown($date) . '"');
			}

			$position = $this->resolveJsonTrackPosition($n, 'Note', $date, $positionsByName, count($trackEntries));

			try {
				$text = NoteService::normalise($n['text'] ?? null);
			} catch (InvalidValueException $e) {
				throw new ImportException("Note on {$date}: " . $e->getMessage());
			}

			if (isset($seen["{$position} {$date}"])) {
				throw new ImportException("Note on {$date} for track \"{$trackEntries[$position]['name']}\" appears twice");
			}
			$seen["{$position} {$date}"] = true;

			if ($text !== '') {
				$resolved[] = ['position' => $position, 'date' => $date, 'text' => $text];
			}
		}

		return $resolved;
	}

	/**
	 * The position in the file's tracks list of the track a JSON tick or note
	 * belongs to: its `trackIndex` when present, else the one track with its
	 * `track` name.
	 *
	 * @param array<array-key, mixed> $item
	 * @param 'Tick'|'Note' $kind For the messages
	 * @param array<string, list<int>> $positionsByName
	 * @throws ImportException for an invalid index, a name no track has, or a name several tracks share
	 */
	private function resolveJsonTrackPosition(array $item, string $kind, string $date, array $positionsByName, int $trackCount): int {
		if (array_key_exists('trackIndex', $item)) {
			$index = $item['trackIndex'];
			if (!is_int($index) || $index < 0 || $index >= $trackCount) {
				throw new ImportException("{$kind} on {$date} has an invalid track index \"" . self::shown($index) . '"');
			}
			return $index;
		}

		$name = $this->cleanJsonTrackName($item['track'] ?? '');
		$positions = $positionsByName[$name] ?? [];
		if (count($positions) === 0) {
			throw new ImportException("{$kind} on {$date} is for a track \"{$name}\" that isn't in the backup");
		}
		if (count($positions) > 1) {
			$items = strtolower($kind) . 's';
			throw new ImportException(
				"The backup has more than one track named \"{$name}\", so its {$items} can't be matched. "
				. 'Rename one of them in the file and import again.'
			);
		}
		return $positions[0];
	}

	/**
	 * A clashing name with the Merge suffix, cut so the result fits the column.
	 */
	private static function withImportedSuffix(string $name): string {
		$room = TrackService::MAX_NAME_LENGTH - mb_strlen(self::IMPORTED_SUFFIX);
		return mb_substr($name, 0, $room) . self::IMPORTED_SUFFIX;
	}

	/**
	 * @param list<array{name: string, type: string, sortOrder: int, private: bool, archivedAt: ?string}> $trackEntries
	 * @return list<int> New Tickbuddy track IDs, in file order
	 * @throws ImportException
	 */
	private function importJsonTracks(array $trackEntries, string $mode, string $userId): array {
		$existingNames = [];

		if ($mode === self::MODE_REPLACE) {
			$this->noteMapper->deleteAllByUser($userId);
			$this->tickMapper->deleteAllByUser($userId);
			$this->trackMapper->deleteAllByUser($userId);
			$startSortOrder = 1;
		} else {
			$existingTracks = $this->trackMapper->findAllByUser($userId);
			$existingCount = count($existingTracks);

			if ($existingCount + count($trackEntries) > TrackService::MAX_TRACKS) {
				throw new ImportException(
					'Import would exceed the ' . TrackService::MAX_TRACKS . ' track limit. '
					. 'You have ' . $existingCount . ' tracks and the backup contains ' . count($trackEntries) . '.'
				);
			}

			foreach ($existingTracks as $t) {
				$existingNames[mb_strtolower($t->getName())] = true;
			}
			$startSortOrder = $this->trackMapper->getMaxSortOrder($userId) + 1;
		}

		if (count($trackEntries) > TrackService::MAX_TRACKS) {
			throw new ImportException(
				'The backup contains ' . count($trackEntries) . ' tracks, which exceeds the limit of ' . TrackService::MAX_TRACKS . '.'
			);
		}

		$trackIds = [];
		$sortOrder = $startSortOrder;

		foreach ($trackEntries as $entry) {
			$name = $entry['name'];

			if ($mode === self::MODE_MERGE && isset($existingNames[mb_strtolower($name)])) {
				$name = self::withImportedSuffix($name);
			}

			$track = new Track();
			$track->setUserId($userId);
			$track->setName($name);
			$track->setType($entry['type']);
			$track->setSortOrder($sortOrder);
			$track->setPrivate($entry['private'] ? 1 : 0);
			$track->setArchivedAt($entry['archivedAt']);
			$this->trackMapper->insert($track);

			$trackIds[] = $track->getId();
			$sortOrder++;
		}

		return $trackIds;
	}

	/**
	 * @param list<array{position: int, date: string, value: int}> $resolvedTicks
	 * @param list<int> $trackIds New track IDs, by position in the file
	 * @return int Number of ticks imported
	 */
	private function importJsonTicks(array $resolvedTicks, array $trackIds, string $userId): int {
		$tickCount = 0;

		foreach ($resolvedTicks as $t) {
			$tick = new Tick();
			$tick->setUserId($userId);
			$tick->setTrackId($trackIds[$t['position']]);
			$tick->setDate($t['date']);
			$tick->setValue($t['value']);
			$this->tickMapper->insert($tick);
			$tickCount++;
		}

		return $tickCount;
	}

	/**
	 * @param list<array{position: int, date: string, text: string}> $resolvedNotes
	 * @param list<int> $trackIds New track IDs, by position in the file
	 * @return int Number of notes imported
	 */
	private function importJsonNotes(array $resolvedNotes, array $trackIds, string $userId): int {
		$noteCount = 0;

		foreach ($resolvedNotes as $n) {
			$note = new Note();
			$note->setUserId($userId);
			$note->setTrackId($trackIds[$n['position']]);
			$note->setDate($n['date']);
			$note->setText($n['text']);
			$this->noteMapper->insert($note);
			$noteCount++;
		}

		return $noteCount;
	}

	private function validateTickmateDb(\SQLite3 $sqlite): void {
		// Check that required tables exist
		$result = $sqlite->query("SELECT name FROM sqlite_master WHERE type='table' AND name IN ('tracks', 'ticks')");
		$tables = [];
		while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
			$tables[] = (string)$row['name'];
		}
		if (!in_array('tracks', $tables, true) || !in_array('ticks', $tables, true)) {
			throw new ImportException('Not a valid Tickmate backup: missing tracks or ticks table');
		}
	}

	/**
	 * Read tracks from Tickmate DB, enabled and disabled, ordered by `order` column (or `_id` fallback).
	 *
	 * @return array<int, array{id: int, name: string, order: int, counter: bool, enabled: bool}>
	 */
	private function readTickmateTracks(\SQLite3 $sqlite): array {
		// Older Tickmate databases lack `order` and `multiple_entries_per_day`
		$columns = [];
		$pragma = $sqlite->query('PRAGMA table_info(tracks)');
		while ($col = $pragma->fetchArray(SQLITE3_ASSOC)) {
			$columns[(string)$col['name']] = true;
		}

		$orderCol = isset($columns['order']) ? '"order"' : '_id';
		$counterCol = isset($columns['multiple_entries_per_day']) ? 'multiple_entries_per_day' : '0';
		$result = $sqlite->query("SELECT _id, name, enabled, {$orderCol} AS sort_col, {$counterCol} AS counter FROM tracks ORDER BY sort_col ASC, _id ASC");

		$tracks = [];
		while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
			$id = (int)$row['_id'];
			$name = trim((string)$row['name']);
			self::checkNameLength($name);
			$tracks[$id] = [
				'id' => $id,
				'name' => $name,
				'order' => (int)$row['sort_col'],
				'counter' => (int)$row['counter'] === 1,
				'enabled' => (int)$row['enabled'] === 1,
			];
		}
		return $tracks;
	}

	/**
	 * Read ticks from Tickmate DB for the tracks read.
	 *
	 * @param array<int, array{id: int, name: string, order: int, counter: bool, enabled: bool}> $tmTracks
	 * @return array<int, list<array{date: string, trackId: int}>> Grouped by Tickmate track ID
	 */
	private function readTickmateTicks(\SQLite3 $sqlite, array $tmTracks): array {
		$trackIds = array_keys($tmTracks);
		if (empty($trackIds)) {
			return [];
		}

		$placeholders = implode(',', $trackIds);
		$result = $sqlite->query("SELECT _track_id, year, month, day FROM ticks WHERE _track_id IN ({$placeholders}) ORDER BY _track_id, year, month, day");

		$ticks = [];
		while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
			$trackId = (int)$row['_track_id'];
			// Tickmate months are 0-indexed (Java Calendar convention)
			$month = (int)$row['month'] + 1;
			$year = (int)$row['year'];
			$day = (int)$row['day'];
			$date = sprintf('%04d-%02d-%02d', $year, $month, $day);
			if (!TrackService::isValidDate($date)) {
				$name = $tmTracks[$trackId]['name'];
				throw new ImportException("Tickmate tick on track \"{$name}\" has an invalid date \"{$date}\"");
			}

			$ticks[$trackId][] = [
				'date' => $date,
				'trackId' => $trackId,
			];
		}
		return $ticks;
	}

	/**
	 * Detect which Tickmate tracks are counters: those with "multiple entries per day"
	 * switched on, and those with several rows on one date (older backups lack the
	 * setting, and a track switched off after counting still holds its counts).
	 *
	 * @param array<int, array{id: int, name: string, order: int, counter: bool, enabled: bool}> $tmTracks
	 * @param array<int, list<array{date: string, trackId: int}>> $tmTicks
	 * @return array<int, true> Set of Tickmate track IDs that are counters
	 */
	private function detectCounterTracks(array $tmTracks, array $tmTicks): array {
		$counters = [];
		foreach ($tmTracks as $trackId => $tmTrack) {
			if ($tmTrack['counter']) {
				$counters[$trackId] = true;
			}
		}
		foreach ($tmTicks as $trackId => $ticks) {
			$dateCounts = [];
			foreach ($ticks as $tick) {
				$dateCounts[$tick['date']] = ($dateCounts[$tick['date']] ?? 0) + 1;
			}
			foreach ($dateCounts as $count) {
				if ($count > 1) {
					$counters[$trackId] = true;
					break;
				}
			}
		}
		return $counters;
	}

	/**
	 * Import tracks into Tickbuddy, handling Replace/Merge and name collisions.
	 * Disabled Tickmate tracks come in archived.
	 *
	 * @param array<int, array{id: int, name: string, order: int, counter: bool, enabled: bool}> $tmTracks
	 * @param array<int, list<array{date: string, trackId: int}>> $tmTicks
	 * @param array<int, true> $counterTracks
	 * @param string $today Import date as YYYY-MM-DD
	 * @return array<int, int> Map of Tickmate track ID → Tickbuddy track ID
	 * @throws ImportException
	 */
	private function importTracks(array $tmTracks, array $tmTicks, array $counterTracks, string $today, string $mode, string $userId): array {
		$existingNames = [];

		if ($mode === self::MODE_REPLACE) {
			$this->noteMapper->deleteAllByUser($userId);
			$this->tickMapper->deleteAllByUser($userId);
			$this->trackMapper->deleteAllByUser($userId);
			$startSortOrder = 1;
		} else {
			// Merge: imported tracks come after existing ones
			$existingTracks = $this->trackMapper->findAllByUser($userId);
			$existingCount = count($existingTracks);

			if ($existingCount + count($tmTracks) > TrackService::MAX_TRACKS) {
				throw new ImportException(
					'Import would exceed the ' . TrackService::MAX_TRACKS . ' track limit. '
					. 'You have ' . $existingCount . ' tracks and the backup contains ' . count($tmTracks) . '.'
				);
			}

			foreach ($existingTracks as $t) {
				$existingNames[mb_strtolower($t->getName())] = true;
			}
			$startSortOrder = $this->trackMapper->getMaxSortOrder($userId) + 1;
		}

		if (count($tmTracks) > TrackService::MAX_TRACKS) {
			throw new ImportException(
				'The backup contains ' . count($tmTracks) . ' tracks, which exceeds the limit of ' . TrackService::MAX_TRACKS . '.'
			);
		}

		$trackIdMap = [];
		$sortOrder = $startSortOrder;

		foreach ($tmTracks as $tmId => $tmTrack) {
			$name = $tmTrack['name'];
			if ($name === '') {
				$name = 'Untitled';
			}

			// Handle name collision in merge mode
			if ($mode === self::MODE_MERGE && isset($existingNames[mb_strtolower($name)])) {
				$name = self::withImportedSuffix($name);
			}

			$type = isset($counterTracks[$tmId]) ? 'counter' : 'boolean';

			$track = new Track();
			$track->setUserId($userId);
			$track->setName($name);
			$track->setType($type);
			$track->setSortOrder($sortOrder);
			if (!$tmTrack['enabled']) {
				$track->setArchivedAt(self::tickmateArchiveDate($tmTicks[$tmId] ?? [], $today));
			}
			$this->trackMapper->insert($track);

			$trackIdMap[$tmId] = $track->getId();
			$sortOrder++;
		}

		return $trackIdMap;
	}

	/**
	 * Tickmate doesn't record when a track was disabled: take its last tick as the
	 * closest guess, or the import date when that is earlier or there are no ticks.
	 *
	 * @param list<array{date: string, trackId: int}> $ticks
	 */
	private static function tickmateArchiveDate(array $ticks, string $today): string {
		if ($ticks === []) {
			return $today;
		}
		return min(max(array_column($ticks, 'date')), $today);
	}

	/**
	 * Import ticks into Tickbuddy.
	 *
	 * @param array<int, list<array{date: string, trackId: int}>> $tmTicks
	 * @param array<int, int> $trackIdMap Tickmate ID → Tickbuddy ID
	 * @param array<int, true> $counterTracks
	 * @return int Number of ticks imported
	 */
	private function importTicks(array $tmTicks, array $trackIdMap, array $counterTracks, string $userId): int {
		$tickCount = 0;

		foreach ($tmTicks as $tmTrackId => $ticks) {
			if (!isset($trackIdMap[$tmTrackId])) {
				continue;
			}
			$buddyTrackId = $trackIdMap[$tmTrackId];

			if (isset($counterTracks[$tmTrackId])) {
				// Counter track: aggregate rows per date
				$dateCounts = [];
				foreach ($ticks as $t) {
					$dateCounts[$t['date']] = ($dateCounts[$t['date']] ?? 0) + 1;
				}
				foreach ($dateCounts as $date => $value) {
					$tick = new Tick();
					$tick->setUserId($userId);
					$tick->setTrackId($buddyTrackId);
					$tick->setDate($date);
					$tick->setValue($value);
					$this->tickMapper->insert($tick);
					$tickCount++;
				}
			} else {
				// Boolean track: one tick per date (deduplicate just in case)
				$seenDates = [];
				foreach ($ticks as $t) {
					if (isset($seenDates[$t['date']])) {
						continue;
					}
					$seenDates[$t['date']] = true;

					$tick = new Tick();
					$tick->setUserId($userId);
					$tick->setTrackId($buddyTrackId);
					$tick->setDate($t['date']);
					$tick->setValue(1);
					$this->tickMapper->insert($tick);
					$tickCount++;
				}
			}
		}

		return $tickCount;
	}
}
