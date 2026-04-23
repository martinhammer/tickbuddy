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
	 * @return array{version: int, exportedAt: string, tracks: list<array>, ticks: list<array>}
	 */
	public function export(string $userId, bool $includePrivate): array {
		$tracks = $this->trackMapper->findAllByUser($userId);

		if (!$includePrivate) {
			$tracks = array_values(array_filter($tracks, fn ($t) => !$t->getPrivate()));
		}

		$trackIdToName = [];
		$exportTracks = [];
		foreach ($tracks as $track) {
			$trackIdToName[$track->getId()] = $track->getName();
			$exportTracks[] = [
				'name' => $track->getName(),
				'type' => $track->getType(),
				'sortOrder' => $track->getSortOrder(),
				'private' => $track->getPrivate(),
			];
		}

		$ticks = $this->tickMapper->findAllByUser($userId);
		$exportTicks = [];
		foreach ($ticks as $tick) {
			$trackName = $trackIdToName[$tick->getTrackId()] ?? null;
			if ($trackName === null) {
				continue;
			}
			$exportTicks[] = [
				'track' => $trackName,
				'date' => $tick->getDate(),
				'value' => $tick->getValue(),
			];
		}

		return [
			'version' => self::FORMAT_VERSION,
			'exportedAt' => date('c'),
			'tracks' => $exportTracks,
			'ticks' => $exportTicks,
		];
	}
}
