<?php

declare(strict_types=1);

namespace Service;

use OCA\Tickbuddy\Db\Tick;
use OCA\Tickbuddy\Db\TickMapper;
use OCA\Tickbuddy\Db\Track;
use OCA\Tickbuddy\Db\TrackMapper;
use OCA\Tickbuddy\Service\ImportException;
use OCA\Tickbuddy\Service\ImportService;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

final class ImportServiceTest extends TestCase {
	private TickMapper $tickMapper;
	private TrackMapper $trackMapper;
	private IDBConnection $db;
	private ImportService $service;
	private string $userId = 'testuser';
	private ?string $file = null;

	/** @var list<Track> Tracks inserted, in order; new ids start at 101 */
	private array $insertedTracks = [];
	/** @var list<array{int, string, int}> Ticks inserted as [trackId, date, value] */
	private array $insertedTicks = [];

	protected function setUp(): void {
		$this->tickMapper = $this->createMock(TickMapper::class);
		$this->trackMapper = $this->createMock(TrackMapper::class);
		$this->db = $this->createMock(IDBConnection::class);
		$this->service = new ImportService($this->trackMapper, $this->tickMapper, $this->db);

		$this->trackMapper->method('insert')->willReturnCallback(function (Track $track): Track {
			$track->setId(101 + count($this->insertedTracks));
			$this->insertedTracks[] = $track;
			return $track;
		});
		$this->tickMapper->method('insert')->willReturnCallback(function (Tick $tick): Tick {
			$this->insertedTicks[] = [$tick->getTrackId(), $tick->getDate(), $tick->getValue()];
			return $tick;
		});
	}

	protected function tearDown(): void {
		if ($this->file !== null) {
			unlink($this->file);
		}
	}

	private function import(array $data, string $mode = ImportService::MODE_REPLACE): array {
		$this->file = tempnam(sys_get_temp_dir(), 'tickbuddy-import-test');
		file_put_contents($this->file, json_encode($data));
		return $this->service->importJson($this->file, $mode, $this->userId);
	}

	/** The file from the spec: two "Exercise" tracks, ticked on the same day */
	private function duplicateNamesBackup(): array {
		return [
			'version' => 1,
			'exportedAt' => '2026-10-14T06:00:00+02:00',
			'tracks' => [
				['name' => 'Cups of coffee', 'type' => 'counter', 'sortOrder' => 1, 'private' => false],
				['name' => 'Exercise', 'type' => 'boolean', 'sortOrder' => 2, 'private' => false],
				['name' => 'Exercise', 'type' => 'boolean', 'sortOrder' => 3, 'private' => false],
			],
			'ticks' => [
				['track' => 'Cups of coffee', 'date' => '2026-10-13', 'value' => 2],
				['track' => 'Exercise', 'trackIndex' => 1, 'date' => '2026-10-13', 'value' => 1],
				['track' => 'Exercise', 'trackIndex' => 2, 'date' => '2026-10-13', 'value' => 1],
				['track' => 'Exercise', 'trackIndex' => 2, 'date' => '2026-03-01', 'value' => 1],
			],
		];
	}

	private function expectNoWrites(): void {
		$this->db->expects($this->never())->method('beginTransaction');
		$this->trackMapper->expects($this->never())->method('deleteAllByUser');
		$this->tickMapper->expects($this->never())->method('deleteAllByUser');
	}

	public function testTrackIndexPutsEachTickOnItsOwnTrack(): void {
		$result = $this->import($this->duplicateNamesBackup());

		$this->assertSame(['tracks' => 3, 'ticks' => 4], $result);
		$this->assertSame([
			[101, '2026-10-13', 2],
			[102, '2026-10-13', 1],
			[103, '2026-10-13', 1],
			[103, '2026-03-01', 1],
		], $this->insertedTicks);
	}

	public function testDuplicateNamesWithoutTrackIndexFail(): void {
		$data = $this->duplicateNamesBackup();
		foreach ($data['ticks'] as &$tick) {
			unset($tick['trackIndex']);
		}
		unset($tick);
		$this->expectNoWrites();

		$this->expectException(ImportException::class);
		$this->expectExceptionMessage('The backup has more than one track named "Exercise", so its ticks can\'t be matched. Rename one of them in the file and import again.');
		$this->import($data);
	}

	public static function invalidTrackIndexProvider(): array {
		return [
			'out of range' => [3, 'Tick on 2026-10-13 has an invalid track index "3"'],
			'negative' => [-1, 'Tick on 2026-10-13 has an invalid track index "-1"'],
			'string' => ['1', 'Tick on 2026-10-13 has an invalid track index "1"'],
			'fraction' => [1.5, 'Tick on 2026-10-13 has an invalid track index "1.5"'],
			'null' => [null, 'Tick on 2026-10-13 has an invalid track index "null"'],
		];
	}

	/**
	 * @dataProvider invalidTrackIndexProvider
	 */
	public function testInvalidTrackIndexFails(mixed $trackIndex, string $message): void {
		$data = $this->duplicateNamesBackup();
		$data['ticks'][1]['trackIndex'] = $trackIndex;
		$this->expectNoWrites();

		$this->expectException(ImportException::class);
		$this->expectExceptionMessage($message);
		$this->import($data);
	}

	public function testDuplicateNamesWithoutTicksImport(): void {
		$data = $this->duplicateNamesBackup();
		$data['ticks'] = [$data['ticks'][0]];

		$result = $this->import($data);

		$this->assertSame(['tracks' => 3, 'ticks' => 1], $result);
		$this->assertSame([[101, '2026-10-13', 2]], $this->insertedTicks);
	}

	public function testTickNamesAreCleanedLikeTrackNames(): void {
		$result = $this->import([
			'version' => 1,
			'tracks' => [
				['name' => ' Exercise ', 'type' => 'boolean'],
				['name' => '', 'type' => 'boolean'],
			],
			'ticks' => [
				['track' => 'Exercise', 'date' => '2026-10-13'],
				['track' => '', 'date' => '2026-10-12'],
			],
		]);

		$this->assertSame(['tracks' => 2, 'ticks' => 2], $result);
		$this->assertSame(['Exercise', 'Untitled'], array_map(fn (Track $t) => $t->getName(), $this->insertedTracks));
		$this->assertSame([
			[101, '2026-10-13', 1],
			[102, '2026-10-12', 1],
		], $this->insertedTicks);
	}

	public function testTickForUnknownTrackIsSkipped(): void {
		$result = $this->import([
			'version' => 1,
			'tracks' => [
				['name' => 'Exercise', 'type' => 'boolean'],
			],
			'ticks' => [
				['track' => 'Reading', 'date' => '2026-10-13', 'value' => 1],
				['track' => 'Exercise', 'date' => '2026-10-13', 'value' => 1],
			],
		]);

		$this->assertSame(['tracks' => 1, 'ticks' => 1], $result);
		$this->assertSame([[101, '2026-10-13', 1]], $this->insertedTicks);
	}

	public function testMergeRenamesDuplicatesAndKeepsTheirTicks(): void {
		$existing = new Track();
		$existing->setId(1);
		$existing->setName('Exercise');
		$this->trackMapper->method('findAllByUser')->willReturn([$existing]);
		$this->trackMapper->method('getMaxSortOrder')->willReturn(1);

		$result = $this->import($this->duplicateNamesBackup(), ImportService::MODE_MERGE);

		$this->assertSame(['tracks' => 3, 'ticks' => 4], $result);
		$this->assertSame(
			['Cups of coffee', 'Exercise (imported)', 'Exercise (imported)'],
			array_map(fn (Track $t) => $t->getName(), $this->insertedTracks),
		);
		$this->assertSame([
			[101, '2026-10-13', 2],
			[102, '2026-10-13', 1],
			[103, '2026-10-13', 1],
			[103, '2026-03-01', 1],
		], $this->insertedTicks);
	}
}
