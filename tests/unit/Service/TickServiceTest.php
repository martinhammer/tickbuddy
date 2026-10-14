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

	private function archive(Track $track): Track {
		$track->setArchivedAt('2026-08-14');
		return $track;
	}

	private function makeTick(int $value): Tick {
		$tick = new Tick();
		$tick->setId(10);
		$tick->setValue($value);
		return $tick;
	}

	private function expectNoTickWrites(): void {
		$this->tickMapper->expects($this->never())->method('insert');
		$this->tickMapper->expects($this->never())->method('update');
		$this->tickMapper->expects($this->never())->method('delete');
	}

	public function testToggleOnArchivedTrackKeepsExistingTick(): void {
		$this->trackMapper->method('findByIdAndUser')->willReturn($this->archive($this->makeBooleanTrack()));
		$this->tickMapper->method('findByUserTrackDate')->willReturn($this->makeTick(1));
		$this->expectNoTickWrites();

		$this->assertTrue($this->service->toggle($this->userId, 1, '2026-10-14'));
	}

	public function testToggleOnArchivedTrackAddsNoTick(): void {
		$this->trackMapper->method('findByIdAndUser')->willReturn($this->archive($this->makeBooleanTrack()));
		$this->tickMapper->method('findByUserTrackDate')
			->willThrowException(new DoesNotExistException(''));
		$this->expectNoTickWrites();

		$this->assertFalse($this->service->toggle($this->userId, 1, '2026-10-14'));
	}

	public function testSetOnArchivedTrackKeepsStoredValue(): void {
		$this->trackMapper->method('findByIdAndUser')->willReturn($this->archive($this->makeCounterTrack()));
		$this->tickMapper->method('findByUserTrackDate')->willReturn($this->makeTick(3));
		$this->expectNoTickWrites();

		$this->assertSame(3, $this->service->set($this->userId, 2, '2026-10-14', 5));
	}

	public function testSetOnArchivedTrackAddsNoTick(): void {
		$this->trackMapper->method('findByIdAndUser')->willReturn($this->archive($this->makeCounterTrack()));
		$this->tickMapper->method('findByUserTrackDate')
			->willThrowException(new DoesNotExistException(''));
		$this->expectNoTickWrites();

		$this->assertSame(0, $this->service->set($this->userId, 2, '2026-10-14', 2));
	}

	public function testToggleOnArchivedCounterTrackStillRejected(): void {
		$this->trackMapper->method('findByIdAndUser')->willReturn($this->archive($this->makeCounterTrack()));

		$this->expectException(InvalidTrackTypeException::class);
		$this->service->toggle($this->userId, 2, '2026-10-14');
	}

	public function testFindBoundsIsScopedToTheUser(): void {
		$bounds = [
			['trackId' => 1, 'oldest' => '2026-03-07', 'newest' => '2026-07-28'],
			['trackId' => 2, 'oldest' => '2026-05-01', 'newest' => '2026-05-01'],
		];
		$this->tickMapper->expects($this->once())
			->method('findBoundsByUser')
			->with($this->userId)
			->willReturn($bounds);

		$this->assertSame($bounds, $this->service->findBounds($this->userId));
	}

	public function testFindBoundsReturnsEmptyListWhenNoTicks(): void {
		$this->tickMapper->method('findBoundsByUser')->willReturn([]);

		$this->assertSame([], $this->service->findBounds($this->userId));
	}
}
