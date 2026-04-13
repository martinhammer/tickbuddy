<?php

declare(strict_types=1);

namespace Service;

use OCA\Tickbuddy\Db\Tick;
use OCA\Tickbuddy\Db\TickMapper;
use OCA\Tickbuddy\Db\Track;
use OCA\Tickbuddy\Db\TrackMapper;
use OCA\Tickbuddy\Service\InvalidTrackTypeException;
use OCA\Tickbuddy\Service\TickService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;

final class TickServiceTest extends TestCase {
	private TickMapper $tickMapper;
	private TrackMapper $trackMapper;
	private TickService $service;
	private string $userId = 'testuser';

	protected function setUp(): void {
		$this->tickMapper = $this->createMock(TickMapper::class);
		$this->trackMapper = $this->createMock(TrackMapper::class);
		$this->service = new TickService($this->tickMapper, $this->trackMapper);
	}

	private function makeBooleanTrack(): Track {
		$track = new Track();
		$track->setId(1);
		$track->setType('boolean');
		$track->setUserId($this->userId);
		return $track;
	}

	private function makeCounterTrack(): Track {
		$track = new Track();
		$track->setId(2);
		$track->setType('counter');
		$track->setUserId($this->userId);
		return $track;
	}

	public function testToggleCreatesTickWhenNoneExists(): void {
		$this->trackMapper->method('findByIdAndUser')->willReturn($this->makeBooleanTrack());
		$this->tickMapper->method('findByUserTrackDate')
			->willThrowException(new DoesNotExistException(''));
		$this->tickMapper->expects($this->once())->method('insert');

		$result = $this->service->toggle($this->userId, 1, '2026-04-11');
		$this->assertTrue($result);
	}

	public function testToggleDeletesTickWhenExists(): void {
		$this->trackMapper->method('findByIdAndUser')->willReturn($this->makeBooleanTrack());
		$tick = new Tick();
		$tick->setId(10);
		$this->tickMapper->method('findByUserTrackDate')->willReturn($tick);
		$this->tickMapper->expects($this->once())->method('delete')->with($tick);

		$result = $this->service->toggle($this->userId, 1, '2026-04-11');
		$this->assertFalse($result);
	}

	public function testToggleRejectsCounterTrack(): void {
		$this->trackMapper->method('findByIdAndUser')->willReturn($this->makeCounterTrack());

		$this->expectException(InvalidTrackTypeException::class);
		$this->service->toggle($this->userId, 2, '2026-04-11');
	}

	public function testSetCreatesTickForNewCounter(): void {
		$this->trackMapper->method('findByIdAndUser')->willReturn($this->makeCounterTrack());
		$this->tickMapper->method('findByUserTrackDate')
			->willThrowException(new DoesNotExistException(''));
		$this->tickMapper->expects($this->once())->method('insert');

		$result = $this->service->set($this->userId, 2, '2026-04-11', 3);
		$this->assertEquals(3, $result);
	}

	public function testSetDeletesTickWhenValueIsZero(): void {
		$this->trackMapper->method('findByIdAndUser')->willReturn($this->makeCounterTrack());
		$tick = new Tick();
		$tick->setId(10);
		$this->tickMapper->method('findByUserTrackDate')->willReturn($tick);
		$this->tickMapper->expects($this->once())->method('delete')->with($tick);

		$result = $this->service->set($this->userId, 2, '2026-04-11', 0);
		$this->assertEquals(0, $result);
	}

	public function testSetRejectsBooleanTrack(): void {
		$this->trackMapper->method('findByIdAndUser')->willReturn($this->makeBooleanTrack());

		$this->expectException(InvalidTrackTypeException::class);
		$this->service->set($this->userId, 1, '2026-04-11', 5);
	}
}
