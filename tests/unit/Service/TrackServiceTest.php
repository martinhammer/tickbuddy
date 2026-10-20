<?php

declare(strict_types=1);

namespace Service;

use OCA\Tickbuddy\Db\NoteMapper;
use OCA\Tickbuddy\Db\TickMapper;
use OCA\Tickbuddy\Db\Track;
use OCA\Tickbuddy\Db\TrackMapper;
use OCA\Tickbuddy\Service\InvalidArchiveDateException;
use OCA\Tickbuddy\Service\InvalidTrackNameException;
use OCA\Tickbuddy\Service\InvalidTrackTypeException;
use OCA\Tickbuddy\Service\ReadOnlyTrackException;
use OCA\Tickbuddy\Service\StrictHandling;
use OCA\Tickbuddy\Service\TrackLimitReachedException;
use OCA\Tickbuddy\Service\TrackService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;

final class TrackServiceTest extends TestCase {
	private TrackMapper $trackMapper;
	private TickMapper $tickMapper;
	private NoteMapper $noteMapper;
	private ITimeFactory $timeFactory;
	private StrictHandling $strictHandling;
	private TrackService $service;
	private string $userId = 'testuser';

	protected function setUp(): void {
		$this->trackMapper = $this->createMock(TrackMapper::class);
		$this->tickMapper = $this->createMock(TickMapper::class);
		$this->noteMapper = $this->createMock(NoteMapper::class);
		$this->timeFactory = $this->createMock(ITimeFactory::class);
		$this->timeFactory->method('getDateTime')->willReturn(new \DateTime('2026-10-15 01:30:00'));
		// A mock's refuseArchived() does nothing, so tests get the legacy answers unless they call strict()
		$this->strictHandling = $this->createMock(StrictHandling::class);
		$this->service = new TrackService($this->trackMapper, $this->tickMapper, $this->noteMapper, $this->timeFactory, $this->strictHandling);
	}

	/** The client sent Prefer: handling=strict: writes to archived tracks are refused. */
	private function strict(): void {
		$this->strictHandling->method('refuseArchived')->willReturnCallback(function (Track $track): void {
			throw new ReadOnlyTrackException($track->getName() . ' is archived, so nothing was changed', 'archived');
		});
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

	public function testReArchiveKeepsTheFirstDate(): void {
		$this->givenTrack($this->makeTrack(7, '2026-08-14'));
		$this->trackMapper->expects($this->never())->method('update');

		$updated = $this->service->update(7, $this->userId, archived: true, archivedAt: '2026-10-20');

		$this->assertSame('2026-08-14', $updated->getArchivedAt());
		$this->assertSame(3, $updated->getSortOrder());
	}

	public function testReArchiveWithoutDateKeepsTheFirstDate(): void {
		$this->givenTrack($this->makeTrack(7, '2026-08-14'));
		$this->trackMapper->expects($this->never())->method('update');

		$updated = $this->service->update(7, $this->userId, archived: true);

		$this->assertSame('2026-08-14', $updated->getArchivedAt());
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

	public function testDeleteRemovesTicksAndNotesToo(): void {
		$track = new Track();
		$track->setId(5);
		$track->setUserId($this->userId);
		$this->trackMapper->method('findByIdAndUser')->willReturn($track);

		$this->tickMapper->expects($this->once())
			->method('deleteByTrackId')
			->with(5);
		$this->noteMapper->expects($this->once())
			->method('deleteByTrackId')
			->with(5);
		$this->trackMapper->expects($this->once())
			->method('delete')
			->with($track);

		$this->service->delete(5, $this->userId);
	}

	// --- track names ---

	private function givenRoomForATrack(): void {
		$this->trackMapper->method('countByUser')->willReturn(0);
		$this->trackMapper->method('getMaxSortOrder')->willReturn(0);
		$this->trackMapper->method('insert')->willReturnCallback(fn (Track $t) => $t);
	}

	public function testCreateTakes255Characters(): void {
		$this->givenRoomForATrack();
		$name = str_repeat('a', 255);

		$this->assertSame($name, $this->service->create($name, 'boolean', $this->userId)->getName());
	}

	public function testCreateCountsCharactersNotBytes(): void {
		$this->givenRoomForATrack();
		$name = str_repeat('é', 255);

		$this->assertSame($name, $this->service->create($name, 'boolean', $this->userId)->getName());
	}

	public function testCreateRefuses256Characters(): void {
		$this->trackMapper->expects($this->never())->method('insert');

		$this->expectException(InvalidTrackNameException::class);
		$this->expectExceptionMessage('Track names can be at most 255 characters');
		$this->service->create(str_repeat('a', 256), 'boolean', $this->userId);
	}

	public function testRenameRefuses256Characters(): void {
		$this->trackMapper->method('findByIdAndUser')->willReturn($this->makeTrack(5));
		$this->trackMapper->expects($this->never())->method('update');

		$this->expectException(InvalidTrackNameException::class);
		$this->expectExceptionMessage('Track names can be at most 255 characters');
		$this->service->update(5, $this->userId, str_repeat('a', 256));
	}

	public function testRenameRefuses256CharactersAlsoWhenArchiving(): void {
		$track = $this->makeTrack(5);
		$this->trackMapper->method('findByIdAndUser')->willReturn($track);
		$this->trackMapper->expects($this->never())->method('update');

		try {
			$this->service->update(5, $this->userId, str_repeat('a', 256), archived: true, archivedAt: '2026-10-17');
			$this->fail('Expected InvalidTrackNameException');
		} catch (InvalidTrackNameException) {
			$this->assertNull($track->getArchivedAt());
			$this->assertSame('Exercise', $track->getName());
		}
	}

	public function testRenameToBlankThrows(): void {
		$this->trackMapper->method('findByIdAndUser')->willReturn($this->makeTrack(5));
		$this->trackMapper->expects($this->never())->method('update');

		$this->expectException(InvalidTrackNameException::class);
		$this->expectExceptionMessage('Track name cannot be empty');
		$this->service->update(5, $this->userId, '   ');
	}

	// --- archived tracks: only restore and delete ---

	public static function changesToAnArchivedTrack(): array {
		return [
			'rename' => [['name' => 'Running']],
			'rename to the same name' => [['name' => 'Exercise']],
			'private' => [['private' => true]],
			'sort order' => [['sortOrder' => 1]],
			're-archive' => [['archived' => true, 'archivedAt' => '2026-10-20']],
			're-archive without a date' => [['archived' => true]],
			're-archive with a rename' => [['name' => 'Running', 'archived' => true]],
		];
	}

	/**
	 * Without Prefer: handling=strict, the change is quietly ignored.
	 *
	 * @dataProvider changesToAnArchivedTrack
	 */
	public function testArchivedTrackIgnoresChanges(array $fields): void {
		$this->givenTrack($this->makeTrack(7, '2026-08-14'));
		$this->trackMapper->expects($this->never())->method('update');

		$updated = $this->service->update(7, $this->userId, ...$fields);

		$this->assertSame('Exercise', $updated->getName());
		$this->assertSame(3, $updated->getSortOrder());
		$this->assertSame(0, $updated->getPrivate());
		$this->assertSame('2026-08-14', $updated->getArchivedAt());
	}

	/**
	 * @dataProvider changesToAnArchivedTrack
	 */
	public function testStrictRefusesChangesToAnArchivedTrack(array $fields): void {
		$this->strict();
		$track = $this->makeTrack(7, '2026-08-14');
		$this->givenTrack($track);
		$this->trackMapper->expects($this->never())->method('update');

		try {
			$this->service->update(7, $this->userId, ...$fields);
			$this->fail('Expected ReadOnlyTrackException');
		} catch (ReadOnlyTrackException $e) {
			$this->assertSame('Exercise is archived, so nothing was changed', $e->getMessage());
			$this->assertSame('archived', $e->getReason());
		}
		$this->assertSame('Exercise', $track->getName());
		$this->assertSame('2026-08-14', $track->getArchivedAt());
	}

	public function testStrictAllowsAnUpdateWithNoFields(): void {
		$this->strict();
		$this->givenTrack($this->makeTrack(7, '2026-08-14'));

		$updated = $this->service->update(7, $this->userId);
		$this->assertSame('2026-08-14', $updated->getArchivedAt());

		$updated = $this->service->update(7, $this->userId, archivedAt: '2026-10-20');
		$this->assertSame('2026-08-14', $updated->getArchivedAt());
	}

	public function testStrictAllowsARestore(): void {
		$this->strict();
		$this->givenTrack($this->makeTrack(7, '2026-08-14'));
		$this->trackMapper->method('getMaxSortOrder')->willReturn(9);

		$updated = $this->service->update(7, $this->userId, archived: false);

		$this->assertNull($updated->getArchivedAt());
		$this->assertSame(10, $updated->getSortOrder());
	}

	public function testStrictAppliesNameAndPrivateWithARestore(): void {
		$this->strict();
		$this->givenTrack($this->makeTrack(7, '2026-08-14'));
		$this->trackMapper->method('getMaxSortOrder')->willReturn(9);

		$updated = $this->service->update(7, $this->userId, name: 'Running', private: true, archived: false);

		$this->assertNull($updated->getArchivedAt());
		$this->assertSame('Running', $updated->getName());
		$this->assertSame(1, $updated->getPrivate());
	}

	public function testStrictRestorePositionWinsOverSortOrder(): void {
		$this->strict();
		$this->givenTrack($this->makeTrack(7, '2026-08-14'));
		$this->trackMapper->method('getMaxSortOrder')->willReturn(9);

		$updated = $this->service->update(7, $this->userId, sortOrder: 1, archived: false);

		$this->assertSame(10, $updated->getSortOrder());
	}

	public function testStrictStillReportsABlankNameFirst(): void {
		$this->strict();
		$this->givenTrack($this->makeTrack(7, '2026-08-14'));

		$this->expectException(InvalidTrackNameException::class);
		$this->service->update(7, $this->userId, '   ');
	}

	public function testStrictStillReportsABadArchiveDateFirst(): void {
		$this->strict();
		$this->givenTrack($this->makeTrack(7, '2026-08-14'));

		$this->expectException(InvalidArchiveDateException::class);
		$this->service->update(7, $this->userId, archived: true, archivedAt: '20.10.2026');
	}

	public function testStrictRenamesAndArchivesAnActiveTrackInOneCall(): void {
		$this->strict();
		$this->givenTrack($this->makeTrack(7));

		$updated = $this->service->update(7, $this->userId, name: ' Running ', archived: true, archivedAt: '2026-10-14');

		$this->assertSame('Running', $updated->getName());
		$this->assertSame('2026-10-14', $updated->getArchivedAt());
	}

	private function givenTracksWithOneArchived(): void {
		$this->trackMapper->method('findAllByUser')->willReturn([
			$this->makeTrack(1, null, 1),
			$this->makeTrack(2, '2026-08-14', 2),
			$this->makeTrack(3, null, 3),
		]);
	}

	public function testReorderListingAnArchivedTrackChangesNothing(): void {
		$this->givenTracksWithOneArchived();
		$active = [$this->makeTrack(1, null, 1), $this->makeTrack(3, null, 3)];
		$this->trackMapper->method('findActiveByUser')->willReturn($active);
		$this->trackMapper->expects($this->never())->method('reorder');

		$this->assertSame($active, $this->service->reorder([3, 2, 1], $this->userId));
	}

	public function testStrictRefusesAReorderListingAnArchivedTrack(): void {
		$this->strict();
		$this->givenTracksWithOneArchived();
		$this->trackMapper->expects($this->never())->method('reorder');

		$this->expectException(ReadOnlyTrackException::class);
		$this->service->reorder([3, 1, 2], $this->userId);
	}

	public function testStrictReordersActiveTracksAndKeepsUnlistedArchivedOnes(): void {
		$this->strict();
		$this->givenTracksWithOneArchived();
		$this->trackMapper->expects($this->once())->method('reorder')->with($this->userId, [3, 1, 2]);

		$this->service->reorder([3, 1], $this->userId);
	}

	public function testStrictIgnoresIdsThatAreNotTheUsers(): void {
		$this->strict();
		$this->givenTracksWithOneArchived();
		$this->trackMapper->expects($this->once())->method('reorder')->with($this->userId, [3, 1, 2]);

		$this->service->reorder([99, 3, 1], $this->userId);
	}
}
