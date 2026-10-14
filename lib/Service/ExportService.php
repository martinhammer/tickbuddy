<?php

declare(strict_types=1);

namespace OCA\Tickbuddy\Service;

use OCA\Tickbuddy\Db\TickMapper;
use OCA\Tickbuddy\Db\TrackMapper;

class ExportService {
	public const FORMAT_VERSION = 1;

	public function __construct(
		private TrackMapper $trackMapper,
		private TickMapper $tickMapper,
	) {
	}

	/**
	 * Export all tracks and ticks for a user as a JSON-serializable array.
	 *
	 * @param string $userId
	 * @param bool $includePrivate Whether to include private tracks
	 * @return array{version: int, exportedAt: string, tracks: list<array<string, mixed>>, ticks: list<array<string, mixed>>}
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
			$exportTracks[] = [
				'name' => $track->getName(),
				'type' => $track->getType(),
				'sortOrder' => $track->getSortOrder(),
				'private' => $track->getPrivate() === 1,
			];
		}

		$nameCounts = array_count_values(array_column($exportTracks, 'name'));

		$ticks = $this->tickMapper->findAllByUser($userId);
		$exportTicks = [];
		foreach ($ticks as $tick) {
			$trackIndex = $trackIdToIndex[$tick->getTrackId()] ?? null;
			if ($trackIndex === null) {
				continue;
			}
			$trackName = $exportTracks[$trackIndex]['name'];
			$exportTick = ['track' => $trackName];
			// Only written when the name repeats, so files without duplicate
			// names keep exactly the original format
			if ($nameCounts[$trackName] > 1) {
				$exportTick['trackIndex'] = $trackIndex;
			}
			$exportTick['date'] = $tick->getDate();
			$exportTick['value'] = $tick->getValue();
			$exportTicks[] = $exportTick;
		}

		return [
			'version' => self::FORMAT_VERSION,
			'exportedAt' => date('c'),
			'tracks' => $exportTracks,
			'ticks' => $exportTicks,
		];
	}
}
