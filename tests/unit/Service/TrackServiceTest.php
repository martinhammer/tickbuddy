<?php

declare(strict_types=1);

namespace Service;

use OCA\Tickbuddy\Db\TickMapper;
use OCA\Tickbuddy\Db\Track;
use OCA\Tickbuddy\Db\TrackMapper;
use OCA\Tickbuddy\Service\InvalidTrackTypeException;
use OCA\Tickbuddy\Service\TrackLimitReachedException;
use OCA\Tickbuddy\Service\TrackService;
use PHPUnit\Framework\TestCase;

final class TrackServiceTest extends TestCase {
	private TrackMapper $trackMapper;
	private TickMapper $tickMapper;
	private TrackService $service;
	private string $userId = 'testuser';

	protected function setUp(): void {
		$this->trackMapper = $this->createMock(TrackMapper::class);
		$this->tickMapper = $this->createMock(TickMapper::class);
		$this->service = new TrackService($this->trackMapper, $this->tickMapper);
	}

	public function testCreateBooleanTrack(): void {
		$this->trackMapper->method('countByUser')->willReturn(0);
		$this->trackMapper->method('getMaxSortOrder')->willReturn(0);
		$this->trackMapper->method('insert')->willReturnCallback(fn (Track $t) => $t);

		$track = $this->service->create('Exercise', 'boolean', $this->userId);

		$this->assertEquals('Exercise', $track->getName());
		$this->assertEquals('boolean', $track->getType());
		$this->assertEquals(1, $track->getSortOrder());
	}

	public function testCreateCounterTrack(): void {
		$this->trackMapper->method('countByUser')->willReturn(0);
		$this->trackMapper->method('getMaxSortOrder')->willReturn(3);
		$this->trackMapper->method('insert')->willReturnCallback(fn (Track $t) => $t);

		$track = $this->service->create('Coffee', 'counter', $this->userId);

		$this->assertEquals('Coffee', $track->getName());
		$this->assertEquals('counter', $track->getType());
		$this->assertEquals(4, $track->getSortOrder());
	}

	public function testCreateWithInvalidTypeThrows(): void {
		$this->expectException(InvalidTrackTypeException::class);
		$this->service->create('Bad', 'invalid', $this->userId);
	}

	public function testCreateAtLimitThrows(): void {
		$this->trackMapper->method('countByUser')->willReturn(99);

		$this->expectException(TrackLimitReachedException::class);
		$this->service->create('One too many', 'boolean', $this->userId);
	}

	public function testDeleteRemovesTicksToo(): void {
		$track = new Track();
		$track->setId(5);
		$track->setUserId($this->userId);
		$this->trackMapper->method('findByIdAndUser')->willReturn($track);

		$this->tickMapper->expects($this->once())
			->method('deleteByTrackId')
			->with(5);
		$this->trackMapper->expects($this->once())
			->method('delete')
			->with($track);

		$this->service->delete(5, $this->userId);
	}
}
