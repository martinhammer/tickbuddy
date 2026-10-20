<?php

declare(strict_types=1);

namespace Service;

use OCA\Tickbuddy\Db\Note;
use OCA\Tickbuddy\Db\NoteMapper;
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
	private NoteMapper $noteMapper;
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
		$this->noteMapper = $this->createMock(NoteMapper::class);
		$this->trackMapper = $this->createMock(TrackMapper::class);
		$this->db = $this->createMock(IDBConnection::class);
		$this->service = new ImportService($this->trackMapper, $this->tickMapper, $this->noteMapper, $this->db);

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

	public function testTickmateReplaceDeletesNotes(): void {
		$this->file = tempnam(sys_get_temp_dir(), 'tickbuddy-import-test');
		$sqlite = new \SQLite3($this->file);
		$sqlite->exec('CREATE TABLE tracks (_id INTEGER PRIMARY KEY, name TEXT, enabled INTEGER, "order" INTEGER)');
		$sqlite->exec('CREATE TABLE ticks (_id INTEGER PRIMARY KEY, _track_id INTEGER, year INTEGER, month INTEGER, day INTEGER)');
		$sqlite->exec("INSERT INTO tracks VALUES (1, 'Exercise', 1, 1)");
		$sqlite->exec('INSERT INTO ticks VALUES (1, 1, 2026, 9, 17)');
		$sqlite->close();
		$this->noteMapper->expects($this->once())->method('deleteAllByUser')->with($this->userId);

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
}
