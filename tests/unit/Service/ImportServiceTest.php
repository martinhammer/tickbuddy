<?php

declare(strict_types=1);

namespace Service;

use OCA\Tickbuddy\Db\Note;
use OCA\Tickbuddy\Db\NoteMapper;
use OCA\Tickbuddy\Db\PublicLinkMapper;
use OCA\Tickbuddy\Db\Tick;
use OCA\Tickbuddy\Db\TickMapper;
use OCA\Tickbuddy\Db\Track;
use OCA\Tickbuddy\Db\TrackMapper;
use OCA\Tickbuddy\Db\TrackOption;
use OCA\Tickbuddy\Db\TrackOptionMapper;
use OCA\Tickbuddy\Service\ImportException;
use OCA\Tickbuddy\Service\ImportService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\Exception as DbException;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class ImportServiceTest extends TestCase {
	private TickMapper $tickMapper;
	private NoteMapper $noteMapper;
	private PublicLinkMapper $publicLinkMapper;
	private TrackMapper $trackMapper;
	private TrackOptionMapper $optionMapper;
	private IDBConnection $db;
	private LoggerInterface $logger;
	private ImportService $service;
	private string $userId = 'testuser';
	private ?string $file = null;

	/** @var list<Track> Tracks inserted, in order; new ids start at 101 */
	private array $insertedTracks = [];
	/** @var list<array{int, string, int}> Ticks inserted as [trackId, date, value] */
	private array $insertedTicks = [];

	protected function setUp(): void {
		$this->tickMapper = $this->createMock(TickMapper::class);
		$this->noteMapper = $this->createMock(NoteMapper::class);
		$this->publicLinkMapper = $this->createMock(PublicLinkMapper::class);
		$this->trackMapper = $this->createMock(TrackMapper::class);
		$this->db = $this->createMock(IDBConnection::class);
		$timeFactory = $this->createMock(ITimeFactory::class);
		$timeFactory->method('getDateTime')->willReturn(new \DateTime('2026-10-20 09:00:00'));
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->optionMapper = $this->createMock(TrackOptionMapper::class);
		$this->service = new ImportService($this->trackMapper, $this->tickMapper, $this->noteMapper, $this->publicLinkMapper, $this->db, $timeFactory, $this->logger, $this->optionMapper);

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

		$this->assertSame(['tracks' => 3, 'ticks' => 4, 'notes' => 0], $result);
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

		$this->assertSame(['tracks' => 3, 'ticks' => 1, 'notes' => 0], $result);
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

		$this->assertSame(['tracks' => 2, 'ticks' => 2, 'notes' => 0], $result);
		$this->assertSame(['Exercise', 'Untitled'], array_map(fn (Track $t) => $t->getName(), $this->insertedTracks));
		$this->assertSame([
			[101, '2026-10-13', 1],
			[102, '2026-10-12', 1],
		], $this->insertedTicks);
	}

	public function testMergeRenamesDuplicatesAndKeepsTheirTicks(): void {
		$existing = new Track();
		$existing->setId(1);
		$existing->setName('Exercise');
		$this->trackMapper->method('findAllByUser')->willReturn([$existing]);
		$this->trackMapper->method('getMaxSortOrder')->willReturn(1);

		$result = $this->import($this->duplicateNamesBackup(), ImportService::MODE_MERGE);

		$this->assertSame(['tracks' => 3, 'ticks' => 4, 'notes' => 0], $result);
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

	public function testArchivedAtIsImported(): void {
		$this->import([
			'version' => 1,
			'tracks' => [
				['name' => 'Smoking', 'type' => 'boolean', 'archivedAt' => '2026-08-14'],
				['name' => 'Exercise', 'type' => 'boolean'],
				['name' => 'Sweets', 'type' => 'counter', 'archivedAt' => null],
			],
		]);

		$this->assertSame(
			['2026-08-14', null, null],
			array_map(fn (Track $t) => $t->getArchivedAt(), $this->insertedTracks),
		);
	}

	public static function invalidArchivedAtProvider(): array {
		return [
			'not a real day' => ['2026-02-30', 'Track "Smoking" has invalid archive date "2026-02-30"'],
			'not a date' => ['14 Aug', 'Track "Smoking" has invalid archive date "14 Aug"'],
			'not a string' => [true, 'Track "Smoking" has invalid archive date "true"'],
		];
	}

	/**
	 * @dataProvider invalidArchivedAtProvider
	 */
	public function testInvalidArchivedAtFails(mixed $archivedAt, string $message): void {
		$this->expectNoWrites();
		$this->db->expects($this->never())->method('commit');

		$this->expectException(ImportException::class);
		$this->expectExceptionMessage($message);
		$this->import([
			'version' => 1,
			'tracks' => [
				['name' => 'Exercise', 'type' => 'boolean'],
				['name' => 'Smoking', 'type' => 'boolean', 'archivedAt' => $archivedAt],
			],
		]);
	}

	public function testMergeLimitCountsArchivedTracks(): void {
		$existing = [];
		for ($i = 1; $i <= 98; $i++) {
			$track = new Track();
			$track->setId($i);
			$track->setName("Track {$i}");
			$track->setArchivedAt($i % 2 === 0 ? '2026-08-14' : null);
			$existing[] = $track;
		}
		$this->trackMapper->method('findAllByUser')->willReturn($existing);
		$this->trackMapper->expects($this->never())->method('insert');
		$this->db->expects($this->never())->method('commit');

		$this->expectException(ImportException::class);
		$this->expectExceptionMessage('Import would exceed the 99 track limit. You have 98 tracks and the backup contains 2.');
		$this->import([
			'version' => 1,
			'tracks' => [
				['name' => 'Exercise', 'type' => 'boolean'],
				['name' => 'Smoking', 'type' => 'boolean'],
			],
		], ImportService::MODE_MERGE);
	}

	public function testTrackNameOver255CharactersFails(): void {
		$this->expectNoWrites();
		$this->db->expects($this->never())->method('commit');

		$this->expectException(ImportException::class);
		$this->expectExceptionMessage('Track "' . str_repeat('a', 40) . '…" has a name longer than 255 characters');
		$this->import([
			'version' => 1,
			'tracks' => [
				['name' => 'Exercise', 'type' => 'boolean'],
				['name' => str_repeat('a', 256), 'type' => 'boolean'],
			],
		]);
	}

	public function testTickmateTrackNameOver255CharactersFails(): void {
		$this->file = tempnam(sys_get_temp_dir(), 'tickbuddy-import-test');
		$sqlite = new \SQLite3($this->file);
		$sqlite->exec('CREATE TABLE tracks (_id INTEGER PRIMARY KEY, name TEXT, enabled INTEGER, "order" INTEGER)');
		$sqlite->exec('CREATE TABLE ticks (_id INTEGER PRIMARY KEY, _track_id INTEGER, year INTEGER, month INTEGER, day INTEGER)');
		$sqlite->exec("INSERT INTO tracks VALUES (1, 'Exercise', 1, 1), (2, '" . str_repeat('a', 256) . "', 1, 2)");
		$sqlite->exec('INSERT INTO ticks VALUES (1, 1, 2026, 9, 17)');
		$sqlite->close();
		$this->expectNoWrites();
		$this->db->expects($this->never())->method('commit');

		$this->expectException(ImportException::class);
		$this->expectExceptionMessage('Track "' . str_repeat('a', 40) . '…" has a name longer than 255 characters');
		$this->service->importTickmate($this->file, ImportService::MODE_REPLACE, $this->userId);
	}

	// --- notes ---

	/** @var list<array{int, string, string}> Notes inserted as [trackId, date, text] */
	private array $insertedNotes = [];

	private function captureNotes(): void {
		$this->noteMapper->method('insert')->willReturnCallback(function (Note $note): Note {
			$this->insertedNotes[] = [$note->getTrackId(), $note->getDate(), $note->getText()];
			return $note;
		});
	}

	private function notesBackup(array $notes): array {
		$data = $this->duplicateNamesBackup();
		$data['notes'] = $notes;
		return $data;
	}

	public function testNotesAreImported(): void {
		$this->captureNotes();
		$this->db->expects($this->once())->method('commit');

		$result = $this->import($this->notesBackup([
			['track' => 'Cups of coffee', 'date' => '2026-10-13', 'text' => 'decaf'],
			['track' => 'Exercise', 'trackIndex' => 2, 'date' => '2026-10-13', 'text' => " ran\r\n5 km "],
			['track' => 'Cups of coffee', 'date' => '2026-10-14', 'text' => '  '],
		]));

		$this->assertSame(['tracks' => 3, 'ticks' => 4, 'notes' => 2], $result);
		$this->assertSame([
			[101, '2026-10-13', 'decaf'],
			[103, '2026-10-13', "ran\n5 km"],
		], $this->insertedNotes);
	}

	public function testNoteAtTheLimitIsImported(): void {
		$this->captureNotes();

		$this->import($this->notesBackup([
			['track' => 'Cups of coffee', 'date' => '2026-10-13', 'text' => str_repeat('a', 500)],
		]));

		$this->assertSame([[101, '2026-10-13', str_repeat('a', 500)]], $this->insertedNotes);
	}

	public function testMergeImportsNotesOnTheNewTracks(): void {
		$this->captureNotes();
		$existing = new Track();
		$existing->setId(1);
		$existing->setName('Cups of coffee');
		$this->trackMapper->method('findAllByUser')->willReturn([$existing]);
		$this->noteMapper->expects($this->never())->method('deleteAllByUser');

		$this->import($this->notesBackup([
			['track' => 'Cups of coffee', 'date' => '2026-10-13', 'text' => 'decaf'],
		]), ImportService::MODE_MERGE);

		$this->assertSame('Cups of coffee (imported)', $this->insertedTracks[0]->getName());
		$this->assertSame([[101, '2026-10-13', 'decaf']], $this->insertedNotes);
	}

	public function testReplaceDeletesNotes(): void {
		$this->noteMapper->expects($this->once())->method('deleteAllByUser')->with($this->userId);

		$this->import($this->duplicateNamesBackup());
	}

	public function testReplaceDeletesPublicLinks(): void {
		$this->publicLinkMapper->expects($this->once())->method('deleteAllByUser')->with($this->userId);

		$this->import($this->duplicateNamesBackup());
	}

	public function testMergeKeepsPublicLinks(): void {
		$this->publicLinkMapper->expects($this->never())->method('deleteAllByUser');

		$this->import($this->duplicateNamesBackup(), ImportService::MODE_MERGE);
	}

	public function testTickmateReplaceDeletesNotesAndPublicLinks(): void {
		$this->file = tempnam(sys_get_temp_dir(), 'tickbuddy-import-test');
		$sqlite = new \SQLite3($this->file);
		$sqlite->exec('CREATE TABLE tracks (_id INTEGER PRIMARY KEY, name TEXT, enabled INTEGER, "order" INTEGER)');
		$sqlite->exec('CREATE TABLE ticks (_id INTEGER PRIMARY KEY, _track_id INTEGER, year INTEGER, month INTEGER, day INTEGER)');
		$sqlite->exec("INSERT INTO tracks VALUES (1, 'Exercise', 1, 1)");
		$sqlite->exec('INSERT INTO ticks VALUES (1, 1, 2026, 9, 17)');
		$sqlite->close();
		$this->noteMapper->expects($this->once())->method('deleteAllByUser')->with($this->userId);
		$this->publicLinkMapper->expects($this->once())->method('deleteAllByUser')->with($this->userId);

		$result = $this->service->importTickmate($this->file, ImportService::MODE_REPLACE, $this->userId);

		$this->assertSame(['tracks' => 1, 'ticks' => 1, 'notes' => 0], $result);
	}

	public static function invalidNoteProvider(): array {
		return [
			'unknown track' => [
				['track' => 'Reading', 'date' => '2026-10-13', 'text' => 'a'],
				'Note on 2026-10-13 is for a track "Reading" that isn\'t in the backup',
			],
			'invalid track index' => [
				['track' => 'Exercise', 'trackIndex' => 3, 'date' => '2026-10-13', 'text' => 'a'],
				'Note on 2026-10-13 has an invalid track index "3"',
			],
			'repeated name without index' => [
				['track' => 'Exercise', 'date' => '2026-10-13', 'text' => 'a'],
				'The backup has more than one track named "Exercise", so its notes can\'t be matched. Rename one of them in the file and import again.',
			],
			'not a real date' => [
				['track' => 'Cups of coffee', 'date' => '2026-02-30', 'text' => 'a'],
				'Note has an invalid date "2026-02-30"',
			],
			'missing date' => [
				['track' => 'Cups of coffee', 'text' => 'a'],
				'Note has an invalid date ""',
			],
			'501 characters' => [
				['track' => 'Cups of coffee', 'date' => '2026-10-13', 'text' => str_repeat('a', 501)],
				'Note on 2026-10-13: Notes can be at most 500 characters',
			],
			'control characters' => [
				['track' => 'Cups of coffee', 'date' => '2026-10-13', 'text' => "\x1b[2J"],
				"Note on 2026-10-13: Notes can't contain control characters",
			],
			'JSON true as text' => [
				['track' => 'Cups of coffee', 'date' => '2026-10-13', 'text' => true],
				'Note on 2026-10-13: Notes must be UTF-8 text',
			],
			'repeated day' => [
				['track' => 'Cups of coffee', 'date' => '2026-10-11', 'text' => 'again'],
				'Note on 2026-10-11 for track "Cups of coffee" appears twice',
			],
			'not an object' => ['a note', 'Invalid backup: a note is not an object'],
		];
	}

	/**
	 * @dataProvider invalidNoteProvider
	 */
	public function testInvalidNoteFails(mixed $note, string $message): void {
		$this->expectNoWrites();
		$this->noteMapper->expects($this->never())->method('deleteAllByUser');
		$this->noteMapper->expects($this->never())->method('insert');

		$this->expectException(ImportException::class);
		$this->expectExceptionMessage($message);
		$this->import($this->notesBackup([
			['track' => 'Cups of coffee', 'date' => '2026-10-11', 'text' => 'first'],
			$note,
		]));
	}

	public function testEmptyNotesAreSkipped(): void {
		$this->captureNotes();

		$result = $this->import($this->notesBackup([
			['track' => 'Cups of coffee', 'date' => '2026-10-11', 'text' => " \n "],
			['track' => 'Cups of coffee', 'date' => '2026-10-12'],
			['track' => 'Cups of coffee', 'date' => '2026-10-13', 'text' => 7],
		]));

		$this->assertSame(1, $result['notes']);
		$this->assertSame([[101, '2026-10-13', '7']], $this->insertedNotes);
	}

	// --- checks on ticks and entries ---

	/** One counter and one yes/no track, and the given ticks */
	private function ticksBackup(array $ticks): array {
		return [
			'version' => 1,
			'tracks' => [
				['name' => 'Coffee', 'type' => 'counter'],
				['name' => 'Exercise', 'type' => 'boolean'],
			],
			'ticks' => $ticks,
		];
	}

	public static function invalidTickProvider(): array {
		return [
			'impossible date' => [
				['track' => 'Coffee', 'date' => '2026-02-30', 'value' => 1],
				'Tick on track "Coffee" has an invalid date "2026-02-30"',
			],
			'no date' => [
				['track' => 'Coffee', 'value' => 1],
				'Tick on track "Coffee" has an invalid date ""',
			],
			'unknown track' => [
				['track' => 'Reading', 'date' => '2026-10-13', 'value' => 1],
				'Tick on 2026-10-13 is for a track "Reading" that isn\'t in the backup',
			],
			'negative counter' => [
				['track' => 'Coffee', 'date' => '2026-10-13', 'value' => -3],
				'Tick on 2026-10-13 has an invalid value "-3"',
			],
			'counter past the limit' => [
				['track' => 'Coffee', 'date' => '2026-10-13', 'value' => 2147483648],
				'Tick on 2026-10-13 has an invalid value "2147483648"',
			],
			'fractional counter' => [
				['track' => 'Coffee', 'date' => '2026-10-13', 'value' => 2.7],
				'Tick on 2026-10-13 has an invalid value "2.7"',
			],
			'text on a counter' => [
				['track' => 'Coffee', 'date' => '2026-10-13', 'value' => 'abc'],
				'Tick on 2026-10-13 has an invalid value "abc"',
			],
			'5 on a yes/no track' => [
				['track' => 'Exercise', 'date' => '2026-10-13', 'value' => 5],
				'Tick on 2026-10-13 has an invalid value "5"',
			],
			'repeated day' => [
				['track' => 'Coffee', 'date' => '2026-10-10', 'value' => 3],
				'Tick on 2026-10-10 for track "Coffee" appears twice',
			],
			'not an object' => [
				'a tick',
				'Invalid backup: a tick is not an object',
			],
		];
	}

	/**
	 * @dataProvider invalidTickProvider
	 */
	public function testInvalidTickFails(mixed $tick, string $message): void {
		$this->expectNoWrites();

		$this->expectException(ImportException::class);
		$this->expectExceptionMessage($message);
		$this->import($this->ticksBackup([
			['track' => 'Coffee', 'date' => '2026-10-10', 'value' => 2],
			$tick,
		]));
	}

	public static function malformedBackupProvider(): array {
		$base = ['version' => 1, 'tracks' => [['name' => 'Coffee', 'type' => 'counter']]];
		return [
			'track not an object' => [
				['version' => 1, 'tracks' => [['name' => 'Coffee'], 'Exercise']],
				'Invalid backup: track 2 is not an object',
			],
			'ticks a string' => [$base + ['ticks' => 'none'], 'Invalid backup: ticks is not a list'],
			'ticks an object' => [$base + ['ticks' => ['a' => []]], 'Invalid backup: ticks is not a list'],
			'notes a string' => [$base + ['notes' => 'none'], 'Invalid backup: notes is not a list'],
		];
	}

	/**
	 * @dataProvider malformedBackupProvider
	 */
	public function testMalformedBackupFails(array $data, string $message): void {
		$this->expectNoWrites();

		$this->expectException(ImportException::class);
		$this->expectExceptionMessage($message);
		$this->import($data);
	}

	public function testCounterValueAtTheLimitImports(): void {
		$this->import($this->ticksBackup([['track' => 'Coffee', 'date' => '2026-10-13', 'value' => 2147483647]]));

		$this->assertSame([[101, '2026-10-13', 2147483647]], $this->insertedTicks);
	}

	public static function privateProvider(): array {
		return [
			'true' => [['private' => true], 1],
			'"false"' => [['private' => 'false'], 0],
			'1' => [['private' => 1], 1],
			'"no"' => [['private' => 'no'], 0],
			'missing' => [[], 0],
			'null' => [['private' => null], 0],
		];
	}

	/**
	 * @dataProvider privateProvider
	 */
	public function testPrivateIsReadAsTheApiReadsIt(array $private, int $stored): void {
		$this->import(['version' => 1, 'tracks' => [['name' => 'Smoking', 'type' => 'boolean'] + $private]]);

		$this->assertSame($stored, $this->insertedTracks[0]->getPrivate());
	}

	public function testUnreadablePrivateFails(): void {
		$this->expectNoWrites();

		$this->expectException(ImportException::class);
		$this->expectExceptionMessage('Track "Smoking" has invalid private value "maybe"');
		$this->import(['version' => 1, 'tracks' => [['name' => 'Smoking', 'type' => 'boolean', 'private' => 'maybe']]]);
	}

	public function testDatabaseFailureIsLoggedAndAnsweredWithAFixedSentence(): void {
		$e = new DbException('SQLSTATE[08006]: connection to "db.internal" lost');
		$this->trackMapper->method('deleteAllByUser')->willThrowException($e);
		$this->db->expects($this->once())->method('rollBack');
		$this->logger->expects($this->once())->method('error')->with('Import failed', ['exception' => $e]);

		try {
			$this->import(['version' => 1, 'tracks' => [['name' => 'Smoking', 'type' => 'boolean']]]);
			$this->fail('The import must fail');
		} catch (ImportException $failure) {
			$this->assertSame('The import failed, so nothing was imported', $failure->getMessage());
		}
	}

	public function testTickmateDatabaseFailureIsAnsweredWithAFixedSentence(): void {
		$this->tickmateFile("(1, 'Exercise', 1, 1)", '(1, 1, 2026, 9, 17)');
		$this->trackMapper->method('deleteAllByUser')->willThrowException(new DbException('SQLSTATE[08006]'));
		$this->logger->expects($this->once())->method('error');

		$this->expectExceptionMessage('The import failed, so nothing was imported');
		$this->service->importTickmate($this->file, ImportService::MODE_REPLACE, $this->userId);
	}

	public function testTickValuesThatImport(): void {
		$result = $this->import($this->ticksBackup([
			['track' => 'Coffee', 'date' => '2026-10-01'],
			['track' => 'Coffee', 'date' => '2026-10-02', 'value' => 0],
			['track' => 'Coffee', 'date' => '2026-10-03', 'value' => 4],
			['track' => 'Exercise', 'date' => '2026-10-01', 'value' => 'Yes'],
			['track' => 'Exercise', 'date' => '2026-10-02', 'value' => 'on'],
			['track' => 'Exercise', 'date' => '2026-10-03', 'value' => true],
			['track' => 'Exercise', 'date' => '2026-10-04', 'value' => 'off'],
			['track' => 'Exercise', 'date' => '2026-10-05', 'value' => false],
			['track' => 'Exercise', 'date' => '2026-10-06'],
		]));

		$this->assertSame(6, $result['ticks']);
		$this->assertSame([
			[101, '2026-10-01', 1],
			[101, '2026-10-03', 4],
			[102, '2026-10-01', 1],
			[102, '2026-10-02', 1],
			[102, '2026-10-03', 1],
			[102, '2026-10-06', 1],
		], $this->insertedTicks);
	}

	private function tickmateFile(
		string $tracks,
		string $ticks,
		string $trackColumns = '_id INTEGER PRIMARY KEY, name TEXT, enabled INTEGER, "order" INTEGER',
	): void {
		$this->file = tempnam(sys_get_temp_dir(), 'tickbuddy-import-test');
		$sqlite = new \SQLite3($this->file);
		$sqlite->exec("CREATE TABLE tracks ({$trackColumns})");
		$sqlite->exec('CREATE TABLE ticks (_id INTEGER PRIMARY KEY, _track_id INTEGER, year INTEGER, month INTEGER, day INTEGER)');
		$sqlite->exec("INSERT INTO tracks VALUES {$tracks}");
		$sqlite->exec("INSERT INTO ticks VALUES {$ticks}");
		$sqlite->close();
	}

	/** A Tickmate file built from raw SQL, for structures tickmateFile() can't make */
	private function tickmateFileFromSql(string ...$statements): void {
		$this->file = tempnam(sys_get_temp_dir(), 'tickbuddy-import-test');
		$sqlite = new \SQLite3($this->file);
		foreach ($statements as $sql) {
			$sqlite->exec($sql);
		}
		$sqlite->close();
	}

	private function expectTickmateFailure(string $message): void {
		$this->db->expects($this->never())->method('beginTransaction');
		try {
			$this->service->importTickmate($this->file, ImportService::MODE_REPLACE, $this->userId);
			$this->fail('The import must fail');
		} catch (ImportException $e) {
			$this->assertSame($message, $e->getMessage());
		}
	}

	public function testTickmateGeneratedColumnIsRefusedUnread(): void {
		// The size is what an attacker picks, up to SQLite's 1 GB limit, worked
		// out on every read of `name`; the structure alone is refused
		$this->tickmateFileFromSql(
			'CREATE TABLE tracks (_id INTEGER PRIMARY KEY, name BLOB GENERATED ALWAYS AS (zeroblob(1000)) VIRTUAL, enabled INTEGER, "order" INTEGER)',
			'CREATE TABLE ticks (_id INTEGER PRIMARY KEY, _track_id INTEGER, year INTEGER, month INTEGER, day INTEGER)',
			'INSERT INTO tracks (_id, enabled, "order") VALUES (1, 1, 1)',
		);

		$this->expectTickmateFailure('Not a valid Tickmate backup: unexpected table structure');
	}

	public function testTickmateGeneratedColumnInTicksIsRefused(): void {
		$this->tickmateFileFromSql(
			'CREATE TABLE tracks (_id INTEGER PRIMARY KEY, name TEXT, enabled INTEGER, "order" INTEGER)',
			'CREATE TABLE ticks (_id INTEGER PRIMARY KEY, _track_id INTEGER, year INTEGER, month INTEGER, day INTEGER, pad BLOB AS (zeroblob(100)) STORED)',
			"INSERT INTO tracks VALUES (1, 'Exercise', 1, 1)",
		);

		$this->expectTickmateFailure('Not a valid Tickmate backup: unexpected table structure');
	}

	public function testTickmateVirtualTableIsRefused(): void {
		$probe = new \SQLite3(':memory:');
		$hasFts5 = @$probe->exec('CREATE VIRTUAL TABLE t USING fts5(a)');
		$probe->close();
		if (!$hasFts5) {
			$this->markTestSkipped('This SQLite has no fts5 module to make a virtual table with');
		}
		$this->tickmateFileFromSql(
			'CREATE VIRTUAL TABLE tracks USING fts5(_id, name, enabled, "order")',
			'CREATE TABLE ticks (_id INTEGER PRIMARY KEY, _track_id INTEGER, year INTEGER, month INTEGER, day INTEGER)',
		);

		$this->expectTickmateFailure('Not a valid Tickmate backup: unexpected table structure');
	}

	public function testTickmateFileThatIsNotSqliteIsA400(): void {
		$this->file = tempnam(sys_get_temp_dir(), 'tickbuddy-import-test');
		file_put_contents($this->file, str_repeat('not a database ', 100));

		$this->expectTickmateFailure('Not a valid Tickmate backup: the file could not be read');
	}

	public function testTickmateTicksOverTheLimitAreRefusedBeforeReading(): void {
		$this->tickmateFileFromSql(
			'CREATE TABLE tracks (_id INTEGER PRIMARY KEY, name TEXT, enabled INTEGER, "order" INTEGER)',
			'CREATE TABLE ticks (_id INTEGER PRIMARY KEY, _track_id INTEGER, year INTEGER, month INTEGER, day INTEGER)',
			"INSERT INTO tracks VALUES (1, 'Exercise', 1, 1)",
			'WITH RECURSIVE n(i) AS (SELECT 1 UNION ALL SELECT i + 1 FROM n LIMIT ' . (ImportService::MAX_TICKMATE_TICKS + 1) . ')'
				. ' INSERT INTO ticks SELECT i, 1, 2026, 0, 1 FROM n',
		);

		$this->expectTickmateFailure('The backup contains 200001 ticks, which exceeds the limit of 200000.');
	}

	public function testTickmateTicksAtTheLimitAreRead(): void {
		$this->tickmateFileFromSql(
			'CREATE TABLE tracks (_id INTEGER PRIMARY KEY, name TEXT, enabled INTEGER, "order" INTEGER)',
			'CREATE TABLE ticks (_id INTEGER PRIMARY KEY, _track_id INTEGER, year INTEGER, month INTEGER, day INTEGER)',
			"INSERT INTO tracks VALUES (1, 'Exercise', 1, 1)",
			// Ticks for a track the backup doesn't have don't count
			'WITH RECURSIVE n(i) AS (SELECT 1 UNION ALL SELECT i + 1 FROM n LIMIT ' . ImportService::MAX_TICKMATE_TICKS . ')'
				. ' INSERT INTO ticks SELECT i, 1, 2026, 0, 1 FROM n',
			'INSERT INTO ticks VALUES (999999, 7, 2026, 0, 1)',
		);

		$result = $this->service->importTickmate($this->file, ImportService::MODE_REPLACE, $this->userId);
		// All on one day: one counter tick of 200000
		$this->assertSame(['tracks' => 1, 'ticks' => 1, 'notes' => 0], $result);
		$this->assertSame([[101, '2026-01-01', 200000]], $this->insertedTicks);
	}

	public function testTickmateNumbersStoredAsTextAreReadAsNumbers(): void {
		$this->tickmateFile("('1', 'Exercise', '1', '1')", "(1, '1', '2026', '9', '17')");

		$this->service->importTickmate($this->file, ImportService::MODE_REPLACE, $this->userId);
		$this->assertSame([[101, '2026-10-17', 1]], $this->insertedTicks);
	}

	public function testTickmateImpossibleDateFails(): void {
		// Tickmate months are 0-based: 1 is February
		$this->tickmateFile("(1, 'Exercise', 1, 1)", '(1, 1, 2026, 9, 17), (2, 1, 2026, 1, 30)');
		$this->expectNoWrites();

		$this->expectException(ImportException::class);
		$this->expectExceptionMessage('Tickmate tick on track "Exercise" has an invalid date "2026-02-30"');
		$this->service->importTickmate($this->file, ImportService::MODE_REPLACE, $this->userId);
	}

	/** Tracks as current Tickmate writes them, with its "multiple entries per day" setting */
	private const TICKMATE_COUNTER_COLUMNS = '_id INTEGER PRIMARY KEY, name TEXT, enabled INTEGER, multiple_entries_per_day INTEGER, "order" INTEGER';

	public function testTickmateMultipleEntriesTrackImportsAsCounter(): void {
		// Never ticked twice on a day, yet a counter in Tickmate
		$this->tickmateFile(
			"(1, 'Coffee', 1, 1, 1), (2, 'Exercise', 1, 0, 2)",
			'(1, 1, 2026, 9, 17), (2, 1, 2026, 9, 18), (3, 2, 2026, 9, 17)',
			self::TICKMATE_COUNTER_COLUMNS,
		);

		$this->service->importTickmate($this->file, ImportService::MODE_REPLACE, $this->userId);

		$this->assertSame(['counter', 'boolean'], array_map(fn (Track $t) => $t->getType(), $this->insertedTracks));
		$this->assertSame([
			[101, '2026-10-17', 1],
			[101, '2026-10-18', 1],
			[102, '2026-10-17', 1],
		], $this->insertedTicks);
	}

	public function testTickmateCountsKeptWhenMultipleEntriesSwitchedOff(): void {
		$this->tickmateFile(
			"(1, 'Coffee', 1, 0, 1)",
			'(1, 1, 2026, 9, 17), (2, 1, 2026, 9, 17), (3, 1, 2026, 9, 18)',
			self::TICKMATE_COUNTER_COLUMNS,
		);

		$this->service->importTickmate($this->file, ImportService::MODE_REPLACE, $this->userId);

		$this->assertSame('counter', $this->insertedTracks[0]->getType());
		$this->assertSame([[101, '2026-10-17', 2], [101, '2026-10-18', 1]], $this->insertedTicks);
	}

	public function testTickmateWithoutMultipleEntriesColumnDetectsCountersFromTicks(): void {
		$this->tickmateFile(
			"(1, 'Coffee', 1, 1), (2, 'Exercise', 1, 2)",
			'(1, 1, 2026, 9, 17), (2, 1, 2026, 9, 17), (3, 2, 2026, 9, 17)',
		);

		$this->service->importTickmate($this->file, ImportService::MODE_REPLACE, $this->userId);

		$this->assertSame(['counter', 'boolean'], array_map(fn (Track $t) => $t->getType(), $this->insertedTracks));
		$this->assertSame([[101, '2026-10-17', 2], [102, '2026-10-17', 1]], $this->insertedTicks);
	}

	public function testTickmateDisabledTrackImportsArchivedOnItsLastTick(): void {
		$this->tickmateFile(
			"(1, 'Exercise', 1, 1), (2, 'Smoking', 0, 2), (3, 'Reading', 1, 3)",
			'(1, 1, 2026, 9, 17), (2, 2, 2021, 2, 5), (3, 2, 2021, 4, 30), (4, 2, 2021, 3, 1)',
		);

		$result = $this->service->importTickmate($this->file, ImportService::MODE_REPLACE, $this->userId);

		$this->assertSame(['tracks' => 3, 'ticks' => 4, 'notes' => 0], $result);
		$this->assertSame(
			[['Exercise', 1, null], ['Smoking', 2, '2021-05-30'], ['Reading', 3, null]],
			array_map(fn (Track $t) => [$t->getName(), $t->getSortOrder(), $t->getArchivedAt()], $this->insertedTracks),
		);
		$this->assertContains([102, '2021-03-05', 1], $this->insertedTicks);
	}

	public static function tickmateArchiveDateProvider(): array {
		return [
			'no ticks' => ['', '2026-10-20'],
			'last tick after the import date' => ['(1, 1, 2026, 9, 17), (2, 1, 2026, 10, 3)', '2026-10-20'],
			'last tick on the import date' => ['(1, 1, 2026, 9, 20)', '2026-10-20'],
		];
	}

	/**
	 * @dataProvider tickmateArchiveDateProvider
	 */
	public function testTickmateDisabledTrackArchiveDateIsNeverAfterTheImport(string $ticks, string $archivedAt): void {
		$this->file = tempnam(sys_get_temp_dir(), 'tickbuddy-import-test');
		$sqlite = new \SQLite3($this->file);
		$sqlite->exec('CREATE TABLE tracks (_id INTEGER PRIMARY KEY, name TEXT, enabled INTEGER, "order" INTEGER)');
		$sqlite->exec('CREATE TABLE ticks (_id INTEGER PRIMARY KEY, _track_id INTEGER, year INTEGER, month INTEGER, day INTEGER)');
		$sqlite->exec("INSERT INTO tracks VALUES (1, 'Smoking', 0, 1)");
		if ($ticks !== '') {
			$sqlite->exec("INSERT INTO ticks VALUES {$ticks}");
		}
		$sqlite->close();

		$this->service->importTickmate($this->file, ImportService::MODE_REPLACE, $this->userId);

		$this->assertSame($archivedAt, $this->insertedTracks[0]->getArchivedAt());
	}

	public function testTickmateMergeLimitCountsDisabledTracks(): void {
		$existing = [];
		for ($i = 1; $i <= 98; $i++) {
			$track = new Track();
			$track->setId($i);
			$track->setName("Track {$i}");
			$existing[] = $track;
		}
		$this->trackMapper->method('findAllByUser')->willReturn($existing);
		$this->tickmateFile("(1, 'Exercise', 1, 1), (2, 'Smoking', 0, 2)", '(1, 1, 2026, 9, 17)');
		$this->trackMapper->expects($this->never())->method('insert');
		$this->db->expects($this->never())->method('commit');

		$this->expectException(ImportException::class);
		$this->expectExceptionMessage('Import would exceed the 99 track limit. You have 98 tracks and the backup contains 2.');
		$this->service->importTickmate($this->file, ImportService::MODE_MERGE, $this->userId);
	}

	public function testTickmateTrackLimitMessageHasNoPrefix(): void {
		$existing = [];
		for ($i = 1; $i <= 98; $i++) {
			$track = new Track();
			$track->setId($i);
			$track->setName("Track {$i}");
			$existing[] = $track;
		}
		$this->trackMapper->method('findAllByUser')->willReturn($existing);
		$this->tickmateFile("(1, 'Exercise', 1, 1), (2, 'Smoking', 1, 2)", '(1, 1, 2026, 9, 17)');
		$this->logger->expects($this->never())->method('error');

		try {
			$this->service->importTickmate($this->file, ImportService::MODE_MERGE, $this->userId);
			$this->fail('The import must fail');
		} catch (ImportException $e) {
			$this->assertSame('Import would exceed the 99 track limit. You have 98 tracks and the backup contains 2.', $e->getMessage());
		}
	}

	private function existingTrack(string $name): void {
		$existing = new Track();
		$existing->setId(1);
		$existing->setName($name);
		$this->trackMapper->method('findAllByUser')->willReturn([$existing]);
	}

	public function testMergeSuffixKeepsLongNameWithin255Characters(): void {
		$name = str_repeat('é', 255);
		$this->existingTrack($name);

		$this->import(['version' => 1, 'tracks' => [['name' => $name, 'type' => 'boolean']]], ImportService::MODE_MERGE);

		$imported = $this->insertedTracks[0]->getName();
		$this->assertSame(str_repeat('é', 244) . ' (imported)', $imported);
		$this->assertSame(255, mb_strlen($imported));
	}

	public function testTickmateMergeSuffixKeepsLongNameWithin255Characters(): void {
		$name = str_repeat('a', 255);
		$this->existingTrack($name);
		$this->tickmateFile("(1, '{$name}', 1, 1)", '(1, 1, 2026, 9, 17)');

		$this->service->importTickmate($this->file, ImportService::MODE_MERGE, $this->userId);

		$this->assertSame(str_repeat('a', 244) . ' (imported)', $this->insertedTracks[0]->getName());
	}

	// --- Number and Choice ---

	/** The export of a Number and a Choice track, as ExportService writes it */
	private function numberAndChoiceBackup(): array {
		return [
			'version' => 1,
			'exportedAt' => '2026-11-17T06:00:00+01:00',
			'tracks' => [
				['name' => 'Run', 'type' => 'number', 'sortOrder' => 1, 'private' => false, 'unit' => 'km', 'decimalPlaces' => 1, 'allowNegative' => false],
				['name' => "Mum's mood", 'type' => 'choice', 'sortOrder' => 2, 'private' => true, 'options' => ['Excited', 'Happy', 'Normal', 'Sad'], 'orderedScale' => true],
			],
			'ticks' => [
				// More decimals than the track has now, and a negative value it no longer allows: kept
				['track' => 'Run', 'date' => '2026-11-15', 'value' => 10.45],
				['track' => 'Run', 'date' => '2026-11-16', 'value' => -1.5],
				// A reading of 0 is an entry, not "nothing"
				['track' => 'Run', 'date' => '2026-11-17', 'value' => 0],
				['track' => "Mum's mood", 'date' => '2026-11-17', 'value' => 2],
			],
		];
	}

	/** @return \ArrayObject<int, array{0: int, 1: int, 2: string}> */
	private function collectOptions(): \ArrayObject {
		$options = new \ArrayObject();
		$this->optionMapper->method('insert')->willReturnCallback(function (TrackOption $o) use ($options): TrackOption {
			$options[] = [$o->getTrackId(), $o->getPlace(), $o->getLabel()];
			return $o;
		});
		return $options;
	}

	public function testNumberAndChoiceImportAsStored(): void {
		$options = $this->collectOptions();
		$this->optionMapper->expects($this->once())->method('deleteAllByUser')->with($this->userId);

		$result = $this->import($this->numberAndChoiceBackup());

		$this->assertSame(['tracks' => 2, 'ticks' => 4, 'notes' => 0], $result);
		[$run, $mood] = $this->insertedTracks;
		$this->assertSame(['km', 1, 0, null], [$run->getUnit(), $run->getDecimalPlaces(), $run->getAllowNegative(), $run->getOrderedScale()]);
		$this->assertSame([null, null, null, 1], [$mood->getUnit(), $mood->getDecimalPlaces(), $mood->getAllowNegative(), $mood->getOrderedScale()]);
		$this->assertSame([[102, 1, 'Excited'], [102, 2, 'Happy'], [102, 3, 'Normal'], [102, 4, 'Sad']], $options->getArrayCopy());
		$this->assertSame([
			[101, '2026-11-15', 10450],
			[101, '2026-11-16', -1500],
			[101, '2026-11-17', 0],
			[102, '2026-11-17', 2],
		], $this->insertedTicks);
	}

	public function testNumberSettingsDefaultWhenMissing(): void {
		$this->import(['version' => 1, 'tracks' => [['name' => 'Run', 'type' => 'number']]]);

		$this->assertSame([null, 1, 0], [$this->insertedTracks[0]->getUnit(), $this->insertedTracks[0]->getDecimalPlaces(), $this->insertedTracks[0]->getAllowNegative()]);
	}

	public function testMergeSuffixesOnlyTheName(): void {
		$existing = new Track();
		$existing->setName("Mum's mood");
		$this->trackMapper->method('findAllByUser')->willReturn([$existing]);
		$options = $this->collectOptions();

		$this->import($this->numberAndChoiceBackup(), ImportService::MODE_MERGE);

		$this->assertSame("Mum's mood (imported)", $this->insertedTracks[1]->getName());
		$this->assertSame('Excited', $options[0][2]);
	}

	public static function refusedNumberOrChoiceProvider(): array {
		$run = ['name' => 'Run', 'type' => 'number'];
		$mood = ['name' => 'Mood', 'type' => 'choice', 'options' => ['A', 'B', 'C', 'D']];
		return [
			'decimal places 5' => [[$run + ['decimalPlaces' => 5]], [], 'Track "Run" has invalid decimal places "5"'],
			'decimal places as text' => [[$run + ['decimalPlaces' => '1']], [], 'Track "Run" has invalid decimal places "1"'],
			'unit not text' => [[$run + ['unit' => 5]], [], 'Track "Run" has an invalid unit'],
			'long unit' => [[$run + ['unit' => str_repeat('x', 256)]], [], 'Track "Run" has an invalid unit'],
			'unreadable allowNegative' => [[$run + ['allowNegative' => 'maybe']], [], 'Track "Run" has invalid allowNegative value "maybe"'],
			'no options' => [[['name' => 'Mood', 'type' => 'choice']], [], 'Track "Mood" needs 2 to 99 options'],
			'one option' => [[['name' => 'Mood', 'type' => 'choice', 'options' => ['A']]], [], 'Track "Mood" needs 2 to 99 options'],
			'options not a list' => [[['name' => 'Mood', 'type' => 'choice', 'options' => 'A, B']], [], 'Track "Mood" needs 2 to 99 options'],
			'empty option' => [[['name' => 'Mood', 'type' => 'choice', 'options' => ['A', ' ']]], [], 'Track "Mood" has an empty option'],
			'option not text' => [[['name' => 'Mood', 'type' => 'choice', 'options' => ['A', 2]]], [], 'Track "Mood" has an empty option'],
			'same option twice' => [[['name' => 'Mood', 'type' => 'choice', 'options' => ['Happy', 'happy']]], [], 'Track "Mood" has the option "happy" twice'],
			'unreadable orderedScale' => [[$mood + ['orderedScale' => 'x']], [], 'Track "Mood" has invalid orderedScale value "x"'],
			'options on a number' => [[$run + ['options' => ['A', 'B']]], [], 'Track "Run" has settings of another type: options'],
			'unit on a choice' => [[$mood + ['unit' => 'km']], [], 'Track "Mood" has settings of another type: unit'],
			'unit on a counter' => [[['name' => 'Coffee', 'type' => 'counter', 'unit' => '']], [], 'Track "Coffee" has settings of another type: unit'],
			'number as text' => [[$run], [['track' => 'Run', 'date' => '2026-11-17', 'value' => '10.4']], 'Tick on 2026-11-17 has an invalid value "10.4"'],
			'four decimals' => [[$run], [['track' => 'Run', 'date' => '2026-11-17', 'value' => 1.2345]], 'Tick on 2026-11-17 has an invalid value "1.2345"'],
			'number beyond the limit' => [[$run], [['track' => 'Run', 'date' => '2026-11-17', 'value' => 3000000]], 'Tick on 2026-11-17 has an invalid value "3000000"'],
			'number without a value' => [[$run], [['track' => 'Run', 'date' => '2026-11-17']], 'Tick on 2026-11-17 has an invalid value "null"'],
			'number null' => [[$run], [['track' => 'Run', 'date' => '2026-11-17', 'value' => null]], 'Tick on 2026-11-17 has an invalid value "null"'],
			'choice 0' => [[$mood], [['track' => 'Mood', 'date' => '2026-11-17', 'value' => 0]], 'Tick on 2026-11-17 has an invalid value "0"'],
			'choice past the options' => [[$mood], [['track' => 'Mood', 'date' => '2026-11-17', 'value' => 5]], 'Tick on 2026-11-17 has an invalid value "5"'],
			'choice as text' => [[$mood], [['track' => 'Mood', 'date' => '2026-11-17', 'value' => '2']], 'Tick on 2026-11-17 has an invalid value "2"'],
		];
	}

	/**
	 * @dataProvider refusedNumberOrChoiceProvider
	 */
	public function testNumberAndChoiceRefusals(array $tracks, array $ticks, string $message): void {
		$this->expectNoWrites();

		$this->expectException(ImportException::class);
		$this->expectExceptionMessage($message);
		$this->import(['version' => 1, 'tracks' => $tracks, 'ticks' => $ticks]);
	}
}
