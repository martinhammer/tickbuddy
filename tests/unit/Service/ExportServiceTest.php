<?php

declare(strict_types=1);

namespace Service;

use OCA\Tickbuddy\Db\Tick;
use OCA\Tickbuddy\Db\TickMapper;
use OCA\Tickbuddy\Db\Track;
use OCA\Tickbuddy\Db\TrackMapper;
use OCA\Tickbuddy\Service\ExportService;
use PHPUnit\Framework\TestCase;

final class ExportServiceTest extends TestCase {
	private TickMapper $tickMapper;
	private TrackMapper $trackMapper;
	private ExportService $service;
	private string $userId = 'testuser';

	protected function setUp(): void {
		$this->tickMapper = $this->createMock(TickMapper::class);
		$this->trackMapper = $this->createMock(TrackMapper::class);
		$this->service = new ExportService($this->trackMapper, $this->tickMapper);
	}

	private function makeTrack(int $id, string $name, string $type, int $sortOrder, bool $private = false, ?string $archivedAt = null): Track {
		$track = new Track();
		$track->setId($id);
		$track->setUserId($this->userId);
		$track->setName($name);
		$track->setType($type);
		$track->setSortOrder($sortOrder);
		$track->setPrivate($private ? 1 : 0);
		$track->setArchivedAt($archivedAt);
		return $track;
	}

	private function makeTick(int $trackId, string $date, int $value = 1): Tick {
		$tick = new Tick();
		$tick->setUserId($this->userId);
		$tick->setTrackId($trackId);
		$tick->setDate($date);
		$tick->setValue($value);
		return $tick;
	}

	public function testTicksOfDuplicateNamesCarryTrackIndex(): void {
		$this->trackMapper->method('findAllByUser')->willReturn([
			$this->makeTrack(10, 'Cups of coffee', 'counter', 1),
			$this->makeTrack(20, 'Exercise', 'boolean', 2),
			$this->makeTrack(30, 'Exercise', 'boolean', 3),
		]);
		$this->tickMapper->method('findAllByUser')->willReturn([
			$this->makeTick(10, '2026-10-13', 2),
			$this->makeTick(20, '2026-10-13'),
			$this->makeTick(30, '2026-10-13'),
			$this->makeTick(30, '2026-03-01'),
		]);

		$result = $this->service->export($this->userId, true);

		$this->assertSame([
			['track' => 'Cups of coffee', 'date' => '2026-10-13', 'value' => 2],
			['track' => 'Exercise', 'trackIndex' => 1, 'date' => '2026-10-13', 'value' => 1],
			['track' => 'Exercise', 'trackIndex' => 2, 'date' => '2026-10-13', 'value' => 1],
			['track' => 'Exercise', 'trackIndex' => 2, 'date' => '2026-03-01', 'value' => 1],
		], $result['ticks']);
	}

	public function testUniqueNamesKeepOriginalTickShape(): void {
		$this->trackMapper->method('findAllByUser')->willReturn([
			$this->makeTrack(10, 'Cups of coffee', 'counter', 1),
			$this->makeTrack(20, 'Exercise', 'boolean', 2),
		]);
		$this->tickMapper->method('findAllByUser')->willReturn([
			$this->makeTick(10, '2026-10-13', 2),
			$this->makeTick(20, '2026-10-13'),
		]);

		$result = $this->service->export($this->userId, true);

		$this->assertSame([
			['track' => 'Cups of coffee', 'date' => '2026-10-13', 'value' => 2],
			['track' => 'Exercise', 'date' => '2026-10-13', 'value' => 1],
		], $result['ticks']);
	}

	public function testPrivateTwinLeftOutMakesNameUnique(): void {
		$this->trackMapper->method('findAllByUser')->willReturn([
			$this->makeTrack(10, 'Exercise', 'boolean', 1, true),
			$this->makeTrack(20, 'Cups of coffee', 'counter', 2),
			$this->makeTrack(30, 'Exercise', 'boolean', 3),
		]);
		$this->tickMapper->method('findAllByUser')->willReturn([
			$this->makeTick(10, '2026-10-12'),
			$this->makeTick(30, '2026-10-13'),
		]);

		$result = $this->service->export($this->userId, false);

		$this->assertSame(['Cups of coffee', 'Exercise'], array_column($result['tracks'], 'name'));
		$this->assertSame([
			['track' => 'Exercise', 'date' => '2026-10-13', 'value' => 1],
		], $result['ticks']);
	}

	public function testTrackIndexCountsOnlyExportedTracks(): void {
		$this->trackMapper->method('findAllByUser')->willReturn([
			$this->makeTrack(10, 'Diary', 'boolean', 1, true),
			$this->makeTrack(20, 'Exercise', 'boolean', 2),
			$this->makeTrack(30, 'Exercise', 'boolean', 3),
		]);
		$this->tickMapper->method('findAllByUser')->willReturn([
			$this->makeTick(10, '2026-10-13'),
			$this->makeTick(20, '2026-10-13'),
			$this->makeTick(30, '2026-10-13'),
		]);

		$result = $this->service->export($this->userId, false);

		$this->assertSame([0, 1], array_column($result['ticks'], 'trackIndex'));
	}

	public function testArchivedTrackCarriesArchivedAt(): void {
		$this->trackMapper->method('findAllByUser')->willReturn([
			$this->makeTrack(10, 'Exercise', 'boolean', 1),
			$this->makeTrack(20, 'Smoking', 'boolean', 2, false, '2026-08-14'),
		]);
		$this->tickMapper->method('findAllByUser')->willReturn([]);

		$result = $this->service->export($this->userId, true);

		$this->assertSame([
			['name' => 'Exercise', 'type' => 'boolean', 'sortOrder' => 1, 'private' => false],
			['name' => 'Smoking', 'type' => 'boolean', 'sortOrder' => 2, 'private' => false, 'archivedAt' => '2026-08-14'],
		], $result['tracks']);
	}

	public function testArchivedPrivateTrackFollowsIncludePrivate(): void {
		$this->trackMapper->method('findAllByUser')->willReturn([
			$this->makeTrack(10, 'Exercise', 'boolean', 1),
			$this->makeTrack(20, 'Diary', 'boolean', 2, true, '2026-08-14'),
		]);
		$this->tickMapper->method('findAllByUser')->willReturn([]);

		$this->assertSame(['Exercise'], array_column($this->service->export($this->userId, false)['tracks'], 'name'));
		$this->assertSame(['Exercise', 'Diary'], array_column($this->service->export($this->userId, true)['tracks'], 'name'));
	}
}
