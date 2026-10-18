<?php

declare(strict_types=1);

namespace OCA\Tickbuddy\Service;

use OCA\Tickbuddy\Db\NoteMapper;
use OCA\Tickbuddy\Db\TickMapper;
use OCA\Tickbuddy\Db\TrackMapper;

class ExportService {
	public const FORMAT_VERSION = 1;

	public function __construct(
		private TrackMapper $trackMapper,
		private TickMapper $tickMapper,
		private NoteMapper $noteMapper,
	) {
	}

	/**
	 * Export all tracks, ticks and notes for a user as a JSON-serializable array.
	 * Archived tracks are always included. `notes` is only there when there is
	 * at least one, so files without notes keep exactly the original format.
	 *
	 * @param string $userId
	 * @param bool $includePrivate Whether to include private tracks
	 * @return array{version: int, exportedAt: string, tracks: list<array<string, mixed>>, ticks: list<array<string, mixed>>, notes?: list<array<string, mixed>>}
	 */
	public function export(string $userId, bool $includePrivate): array {
		$tracks = $this->trackMapper->findAllByUser($userId);

		if (!$includePrivate) {
			$tracks = array_values(array_filter($tracks, fn ($t) => $t->getPrivate() !== 1));
		}

		// Position of each track in the exported list, so ticks of tracks whose
		// name isn't unique in the file can say which one they belong to
		$trackIdToIndex = [];
		$exportTracks = [];
		foreach ($tracks as $track) {
			$trackIdToIndex[$track->getId()] = count($exportTracks);
			$exportTrack = [
				'name' => $track->getName(),
				'type' => $track->getType(),
				'sortOrder' => $track->getSortOrder(),
				'private' => $track->getPrivate() === 1,
			];
			// Only written on archived tracks, so files with nothing archived
			// keep exactly the original format
			if ($track->getArchivedAt() !== null) {
				$exportTrack['archivedAt'] = $track->getArchivedAt();
			}
			$exportTracks[] = $exportTrack;
		}

		$nameCounts = array_count_values(array_column($exportTracks, 'name'));

		// Ticks and notes name their track; trackIndex only when the name repeats,
		// so files without duplicate names keep exactly the original format
		$trackRef = function (int $trackId) use ($trackIdToIndex, $exportTracks, $nameCounts): ?array {
			$trackIndex = $trackIdToIndex[$trackId] ?? null;
			if ($trackIndex === null) {
				return null;
			}
			$trackName = $exportTracks[$trackIndex]['name'];
			$ref = ['track' => $trackName];
			if ($nameCounts[$trackName] > 1) {
				$ref['trackIndex'] = $trackIndex;
			}
			return $ref;
		};

		$exportTicks = [];
		foreach ($this->tickMapper->findAllByUser($userId) as $tick) {
			$exportTick = $trackRef($tick->getTrackId());
			if ($exportTick === null) {
				continue;
			}
			$exportTick['date'] = $tick->getDate();
			$exportTick['value'] = $tick->getValue();
			$exportTicks[] = $exportTick;
		}

		$exportNotes = [];
		foreach ($this->noteMapper->findAllByUser($userId) as $note) {
			$exportNote = $trackRef($note->getTrackId());
			if ($exportNote === null) {
				continue;
			}
			$exportNote['date'] = $note->getDate();
			$exportNote['text'] = $note->getText();
			$exportNotes[] = $exportNote;
		}

		$export = [
			'version' => self::FORMAT_VERSION,
			'exportedAt' => date('c'),
			'tracks' => $exportTracks,
			'ticks' => $exportTicks,
		];
		if ($exportNotes !== []) {
			$export['notes'] = $exportNotes;
		}
		return $export;
	}
}
