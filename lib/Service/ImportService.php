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
			/** @var list<array<string, mixed>> $jsonTicks */
			$jsonTicks = array_values($data['ticks']);
		}

		if (empty($jsonTracks)) {
			throw new ImportException('No tracks found in the backup file');
		}

		// Validate and normalize tracks
		$trackEntries = [];
		foreach ($jsonTracks as $i => $t) {
			$name = trim((string)($t['name'] ?? ''));
			if ($name === '') {
				$name = 'Untitled';
			}
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
		}

		$this->db->beginTransaction();
		try {
			$trackNameToId = $this->importJsonTracks($trackEntries, $mode, $userId);
			$tickCount = $this->importJsonTicks($jsonTicks, $trackNameToId, $userId);
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			if ($e instanceof ImportException) {
				throw $e;
			}
			throw new ImportException('Import failed: ' . $e->getMessage());
		}

		return ['tracks' => count($trackNameToId), 'ticks' => $tickCount];
	}

	/**
	 * @param list<array{name: string, type: string, sortOrder: int, private: bool}> $trackEntries
	 * @return array<string, int> Map of track name → Tickbuddy track ID
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

		$trackNameToId = [];
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
			$track->setPrivate($entry['private']);
			$this->trackMapper->insert($track);

			// Map the original name (from the file) to the new track ID
			$trackNameToId[$entry['name']] = $track->getId();
			$sortOrder++;
		}

		return $trackNameToId;
	}

	/**
	 * @param list<array> $jsonTicks
	 * @param array<string, int> $trackNameToId
	 * @return int Number of ticks imported
	 */
	private function importJsonTicks(array $jsonTicks, array $trackNameToId, string $userId): int {
		$tickCount = 0;

		foreach ($jsonTicks as $t) {
			$trackName = (string)($t['track'] ?? '');
			$trackId = $trackNameToId[$trackName] ?? null;
			if ($trackId === null) {
				continue;
			}

			$date = (string)($t['date'] ?? '');
			$value = (int)($t['value'] ?? 1);
			if ($date === '' || $value <= 0) {
				continue;
			}

			$tick = new Tick();
			$tick->setUserId($userId);
			$tick->setTrackId($trackId);
			$tick->setDate($date);
			$tick->setValue($value);
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
