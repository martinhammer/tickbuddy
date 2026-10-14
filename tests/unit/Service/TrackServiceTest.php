<?php

declare(strict_types=1);

namespace Service;

use OCA\Tickbuddy\Db\TickMapper;
use OCA\Tickbuddy\Db\Track;
use OCA\Tickbuddy\Db\TrackMapper;
use OCA\Tickbuddy\Service\InvalidArchiveDateException;
use OCA\Tickbuddy\Service\InvalidTrackTypeException;
use OCA\Tickbuddy\Service\TrackLimitReachedException;
use OCA\Tickbuddy\Service\TrackService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;

final class TrackServiceTest extends TestCase {
	private TrackMapper $trackMapper;
	private TickMapper $tickMapper;
	private ITimeFactory $timeFactory;
	private TrackService $service;
	private string $userId = 'testuser';

	protected function setUp(): void {
		$this->trackMapper = $this->createMock(TrackMapper::class);
		$this->tickMapper = $this->createMock(TickMapper::class);
		$this->timeFactory = $this->createMock(ITimeFactory::class);
		$this->timeFactory->method('getDateTime')->willReturn(new \DateTime('2026-10-15 01:30:00'));
		$this->service = new TrackService($this->trackMapper, $this->tickMapper, $this->timeFactory);
	}

	private function makeTrack(int $id, ?string $archivedAt = null, int $sortOrder = 3): Track {
		$track = new Track();
		$track->setId($id);
		$track->setUserId($this->userId);
		$track->setName('Exercise');
		$track->setSortOrder($sortOrder);
		$track->setArchivedAt($archivedAt);
		return $track;
	}

	/** Mock lookups of $track and return it from update() unchanged. */
	private function givenTrack(Track $track): void {
		$this->trackMapper->method('findByIdAndUser')->willReturn($track);
		$this->trackMapper->method('update')->willReturnCallback(fn (Track $t) => $t);
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

	/**
	 * The `private` flag must be persisted as an integer (0/1), never a PHP bool.
	 * The physical column is INTEGER on every database; binding a boolean
	 * parameter renders as 't'/'f' on Postgres, which the integer column rejects.
	 * If the entity's type drifts back to 'boolean', getPrivate() returns a bool
	 * and these assertSame() checks against integers fail — catching the regression
	 * before it reaches a Postgres install.
	 */
	public function testUpdatePrivateStoresInteger(): void {
		$track = new Track();
		$track->setId(7);
		$track->setUserId($this->userId);
		$this->trackMapper->method('findByIdAndUser')->willReturn($track);
		$this->trackMapper->method('update')->willReturnCallback(fn (Track $t) => $t);

		$updated = $this->service->update(7, $this->userId, private: true);
		$this->assertSame(1, $updated->getPrivate());

		$updated = $this->service->update(7, $this->userId, private: false);
		$this->assertSame(0, $updated->getPrivate());
	}

	public function testArchiveStoresTheClientDate(): void {
		$this->givenTrack($this->makeTrack(7));

		$updated = $this->service->update(7, $this->userId, archived: true, archivedAt: '2026-10-14');

		$this->assertSame('2026-10-14', $updated->getArchivedAt());
		$this->assertSame(3, $updated->getSortOrder());
	}

	public function testArchiveWithoutDateUsesTheServerDate(): void {
		$this->givenTrack($this->makeTrack(7));

		$updated = $this->service->update(7, $this->userId, archived: true);

		$this->assertSame('2026-10-15', $updated->getArchivedAt());
	}

	public static function invalidArchiveDateProvider(): array {
		return [
			'not a real day' => ['2026-02-30'],
			'not a date' => ['14 Oct'],
		];
	}

	/**
	 * @dataProvider invalidArchiveDateProvider
	 */
	public function testArchiveWithInvalidDateSavesNothing(string $date): void {
		$track = $this->makeTrack(7);
		$this->trackMapper->method('findByIdAndUser')->willReturn($track);
		$this->trackMapper->expects($this->never())->method('update');

		try {
			$this->service->update(7, $this->userId, name: 'Running', archived: true, archivedAt: $date);
			$this->fail('Expected InvalidArchiveDateException');
		} catch (InvalidArchiveDateException $e) {
			$this->assertSame('Archive date must be a date as YYYY-MM-DD', $e->getMessage());
		}
		$this->assertSame('Exercise', $track->getName());
		$this->assertNull($track->getArchivedAt());
	}

	public function testReArchiveTakesTheNewDate(): void {
		$this->givenTrack($this->makeTrack(7, '2026-08-14'));

		$updated = $this->service->update(7, $this->userId, archived: true, archivedAt: '2026-10-20');

		$this->assertSame('2026-10-20', $updated->getArchivedAt());
		$this->assertSame(3, $updated->getSortOrder());
	}

	public function testReArchiveWithoutDateUsesTheServerDate(): void {
		$this->givenTrack($this->makeTrack(7, '2026-08-14'));

		$updated = $this->service->update(7, $this->userId, archived: true);

		$this->assertSame('2026-10-15', $updated->getArchivedAt());
	}

	public static function isValidDateProvider(): array {
		return [
			'leap day' => ['2024-02-29', true],
			'no leap day' => ['2026-02-29', false],
			'month 13' => ['2026-13-01', false],
			'no padding' => ['2026-1-1', false],
			'empty' => ['', false],
		];
	}

	/**
	 * @dataProvider isValidDateProvider
	 */
	public function testIsValidDate(string $value, bool $valid): void {
		$this->assertSame($valid, TrackService::isValidDate($value));
	}

	public function testRestoreMovesTheTrackToTheEnd(): void {
		$this->givenTrack($this->makeTrack(7, '2026-08-14'));
		$this->trackMapper->method('getMaxSortOrder')->willReturn(9);

		$updated = $this->service->update(7, $this->userId, archived: false);

		$this->assertNull($updated->getArchivedAt());
		$this->assertSame(10, $updated->getSortOrder());
	}

	public function testRestoreOfActiveTrackChangesNothing(): void {
		$this->givenTrack($this->makeTrack(7));
		$this->trackMapper->expects($this->never())->method('getMaxSortOrder');

		$updated = $this->service->update(7, $this->userId, archived: false);

		$this->assertNull($updated->getArchivedAt());
		$this->assertSame(3, $updated->getSortOrder());
	}

	public function testRestorePositionWinsOverSortOrder(): void {
		$this->givenTrack($this->makeTrack(7, '2026-08-14'));
		$this->trackMapper->method('getMaxSortOrder')->willReturn(9);

		$updated = $this->service->update(7, $this->userId, sortOrder: 1, archived: false);

		$this->assertSame(10, $updated->getSortOrder());
	}

	public function testRenameAndArchiveInOneCall(): void {
		$this->givenTrack($this->makeTrack(7));

		$updated = $this->service->update(7, $this->userId, name: ' Running ', archived: true, archivedAt: '2026-10-14');

		$this->assertSame('Running', $updated->getName());
		$this->assertSame('2026-10-14', $updated->getArchivedAt());
	}

	public function testFindAllLeavesArchivedOutByDefault(): void {
		$active = [$this->makeTrack(1)];
		$this->trackMapper->expects($this->once())->method('findActiveByUser')->with($this->userId)->willReturn($active);
		$this->trackMapper->expects($this->never())->method('findAllByUser');

		$this->assertSame($active, $this->service->findAll($this->userId));
	}

	public function testFindAllIncludesArchivedWhenAsked(): void {
		$all = [$this->makeTrack(1), $this->makeTrack(2, '2026-08-14')];
		$this->trackMapper->expects($this->once())->method('findAllByUser')->with($this->userId)->willReturn($all);
		$this->trackMapper->expects($this->never())->method('findActiveByUser');

		$this->assertSame($all, $this->service->findAll($this->userId, includeArchived: true));
	}

	public static function fullOrderProvider(): array {
		return [
			'unlisted go after, in their order' => [[3, 1], [1, 2, 3, 4], [3, 1, 2, 4]],
			'unknown ids dropped' => [[3, 99, 1], [1, 2, 3], [3, 1, 2]],
			'duplicates kept once, at the first place' => [[2, 1, 2], [1, 2, 3], [2, 1, 3]],
			'no current tracks' => [[1, 2], [], []],
		];
	}

	/**
	 * @dataProvider fullOrderProvider
	 */
	public function testFullOrder(array $wanted, array $current, array $expected): void {
		$this->assertSame($expected, TrackService::fullOrder($wanted, $current));
	}

	public function testReorderSendsTheFullOrderAndReturnsActiveTracks(): void {
		$this->trackMapper->method('findAllByUser')->willReturn([
			$this->makeTrack(1, null, 1),
			$this->makeTrack(2, '2026-08-14', 2),
			$this->makeTrack(3, null, 3),
		]);
		$active = [$this->makeTrack(3, null, 1), $this->makeTrack(1, null, 2)];
		$this->trackMapper->method('findActiveByUser')->willReturn($active);
		$this->trackMapper->expects($this->once())->method('reorder')->with($this->userId, [3, 1, 2]);

		$this->assertSame($active, $this->service->reorder([3, 1], $this->userId));
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
