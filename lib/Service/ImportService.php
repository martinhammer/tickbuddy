<?php

declare(strict_types=1);

namespace OCA\Tickbuddy\Service;

use OCA\Tickbuddy\Db\Tick;
use OCA\Tickbuddy\Db\TickMapper;
use OCA\Tickbuddy\Db\Track;
use OCA\Tickbuddy\Db\TrackMapper;
use OCP\IDBConnection;

class ImportService {
	public const MODE_REPLACE = 'replace';
	public const MODE_MERGE = 'merge';

	public function __construct(
		private TrackMapper $trackMapper,
		private TickMapper $tickMapper,
		private IDBConnection $db,
	) {
	}

	/**
	 * Import tracks and ticks from a Tickmate SQLite backup file.
	 *
	 * @param string $filePath Path to the uploaded SQLite file
	 * @param string $mode 'replace' or 'merge'
	 * @param string $userId Current user ID
	 * @return array{tracks: int, ticks: int} Count of imported items
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
			throw new ImportException('No enabled tracks found in the backup file');
		}

		// Determine track type from ticks: if any date has multiple rows for a track, it's a counter
		$counterTracks = $this->detectCounterTracks($tmTicks);

		$this->db->beginTransaction();
		try {
			$trackIdMap = $this->importTracks($tmTracks, $counterTracks, $mode, $userId);
			$tickCount = $this->importTicks($tmTicks, $trackIdMap, $counterTracks, $userId);
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw new ImportException('Import failed: ' . $e->getMessage());
		}

		return ['tracks' => count($trackIdMap), 'ticks' => $tickCount];
	}

	/**
	 * Import tracks and ticks from a Tickbuddy JSON backup file.
	 *
	 * @param string $filePath Path to the uploaded JSON file
	 * @param string $mode 'replace' or 'merge'
	 * @param string $userId Current user ID
	 * @return array{tracks: int, ticks: int} Count of imported items
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

		/** @var list<array<string, mixed>> $jsonTracks */
		$jsonTracks = array_values($data['tracks']);
		$jsonTicks = [];
		if (isset($data['ticks']) && is_array($data['ticks'])) {
			$jsonTicks = array_values($data['ticks']);
		}

		if (empty($jsonTracks)) {
			throw new ImportException('No tracks found in the backup file');
		}

		// Validate and normalize tracks
		$trackEntries = [];
		/** @var array<string, list<int>> $positionsByName */
		$positionsByName = [];
		foreach ($jsonTracks as $i => $t) {
			$name = $this->cleanJsonTrackName($t['name'] ?? '');
			$type = (string)($t['type'] ?? 'boolean');
			if (!in_array($type, TrackService::VALID_TYPES, true)) {
				throw new ImportException("Track \"{$name}\" has invalid type \"{$type}\"");
			}
			$trackEntries[] = [
				'name' => $name,
				'type' => $type,
				'sortOrder' => (int)($t['sortOrder'] ?? $i + 1),
				'private' => (bool)($t['private'] ?? false),
			];
			$positionsByName[$name][] = $i;
		}

		// Resolve every tick to its track's position in the file before writing
		// anything, so a file whose ticks can't be matched changes nothing
		$resolvedTicks = $this->resolveJsonTicks($jsonTicks, $positionsByName, count($trackEntries));

		$this->db->beginTransaction();
		try {
			$trackIds = $this->importJsonTracks($trackEntries, $mode, $userId);
			$tickCount = $this->importJsonTicks($resolvedTicks, $trackIds, $userId);
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			if ($e instanceof ImportException) {
				throw $e;
			}
			throw new ImportException('Import failed: ' . $e->getMessage());
		}

		return ['tracks' => count($trackIds), 'ticks' => $tickCount];
	}

	/**
	 * Clean a track name from a JSON backup: trimmed, with blank meaning "Untitled".
	 */
	private function cleanJsonTrackName(mixed $name): string {
		$name = trim(is_scalar($name) ? (string)$name : '');
		return $name === '' ? 'Untitled' : $name;
	}

	/**
	 * Match each JSON tick to the position of its track in the file's tracks list.
	 *
	 * A tick's `trackIndex` wins when present; otherwise its `track` name must
	 * match exactly one track. Ticks naming a track that isn't in the file are skipped.
	 *
	 * @param list<mixed> $jsonTicks
	 * @param array<string, list<int>> $positionsByName Cleaned track name → positions in the file
	 * @return list<array{position: int, date: string, value: int}>
	 * @throws ImportException
	 */
	private function resolveJsonTicks(array $jsonTicks, array $positionsByName, int $trackCount): array {
		$resolved = [];

		foreach ($jsonTicks as $t) {
			if (!is_array($t)) {
				continue;
			}

			$date = (string)($t['date'] ?? '');
			$value = (int)($t['value'] ?? 1);
			if ($date === '' || $value <= 0) {
				continue;
			}

			if (array_key_exists('trackIndex', $t)) {
				$index = $t['trackIndex'];
				if (!is_int($index) || $index < 0 || $index >= $trackCount) {
					$shown = is_string($index) ? $index : json_encode($index, JSON_THROW_ON_ERROR);
					throw new ImportException("Tick on {$date} has an invalid track index \"{$shown}\"");
				}
				$position = $index;
			} else {
				$name = $this->cleanJsonTrackName($t['track'] ?? '');
				$positions = $positionsByName[$name] ?? [];
				if (count($positions) === 0) {
					continue;
				}
				if (count($positions) > 1) {
					throw new ImportException(
						"The backup has more than one track named \"{$name}\", so its ticks can't be matched. "
						. 'Rename one of them in the file and import again.'
					);
				}
				$position = $positions[0];
			}

			$resolved[] = ['position' => $position, 'date' => $date, 'value' => $value];
		}

		return $resolved;
	}

	/**
	 * @param list<array{name: string, type: string, sortOrder: int, private: bool}> $trackEntries
	 * @return list<int> New Tickbuddy track IDs, in file order
	 * @throws ImportException
	 */
	private function importJsonTracks(array $trackEntries, string $mode, string $userId): array {
		$existingNames = [];

		if ($mode === self::MODE_REPLACE) {
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
				$name .= ' (imported)';
			}

			$track = new Track();
			$track->setUserId($userId);
			$track->setName($name);
			$track->setType($entry['type']);
			$track->setSortOrder($sortOrder);
			$track->setPrivate($entry['private'] ? 1 : 0);
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
	 * Read enabled tracks from Tickmate DB, ordered by `order` column (or `_id` fallback).
	 *
	 * @return array<int, array{id: int, name: string, order: int}>
	 */
	private function readTickmateTracks(\SQLite3 $sqlite): array {
		// Check if 'order' column exists
		$hasOrder = false;
		$pragma = $sqlite->query('PRAGMA table_info(tracks)');
		while ($col = $pragma->fetchArray(SQLITE3_ASSOC)) {
			if ($col['name'] === 'order') {
				$hasOrder = true;
				break;
			}
		}

		$orderCol = $hasOrder ? '"order"' : '_id';
		$result = $sqlite->query("SELECT _id, name, enabled, {$orderCol} AS sort_col FROM tracks WHERE enabled = 1 ORDER BY sort_col ASC, _id ASC");

		$tracks = [];
		while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
			$id = (int)$row['_id'];
			$tracks[$id] = [
				'id' => $id,
				'name' => trim((string)$row['name']),
				'order' => (int)$row['sort_col'],
			];
		}
		return $tracks;
	}

	/**
	 * Read ticks from Tickmate DB, only for enabled tracks.
	 *
	 * @param array<int, array{id: int, name: string, order: int}> $tmTracks
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

			$ticks[$trackId][] = [
				'date' => $date,
				'trackId' => $trackId,
			];
		}
		return $ticks;
	}

	/**
	 * Detect which Tickmate tracks are counters (multiple rows per track+date).
	 *
	 * @param array<int, list<array{date: string, trackId: int}>> $tmTicks
	 * @return array<int, true> Set of Tickmate track IDs that are counters
	 */
	private function detectCounterTracks(array $tmTicks): array {
		$counters = [];
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
	 *
	 * @param array<int, array{id: int, name: string, order: int}> $tmTracks
	 * @param array<int, true> $counterTracks
	 * @return array<int, int> Map of Tickmate track ID → Tickbuddy track ID
	 * @throws ImportException
	 */
	private function importTracks(array $tmTracks, array $counterTracks, string $mode, string $userId): array {
		$existingNames = [];

		if ($mode === self::MODE_REPLACE) {
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
				$name .= ' (imported)';
			}

			$type = isset($counterTracks[$tmId]) ? 'counter' : 'boolean';

			$track = new Track();
			$track->setUserId($userId);
			$track->setName($name);
			$track->setType($type);
			$track->setSortOrder($sortOrder);
			$this->trackMapper->insert($track);

			$trackIdMap[$tmId] = $track->getId();
			$sortOrder++;
		}

		return $trackIdMap;
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
