<?php

declare(strict_types=1);

namespace Service;

use OCA\Tickbuddy\Db\Note;
use OCA\Tickbuddy\Db\NoteMapper;
use OCA\Tickbuddy\Db\Track;
use OCA\Tickbuddy\Db\TrackMapper;
use OCA\Tickbuddy\Service\InvalidValueException;
use OCA\Tickbuddy\Service\NoteConflictException;
use OCA\Tickbuddy\Service\NoteService;
use OCA\Tickbuddy\Service\ReadOnlyTrackException;
use OCA\Tickbuddy\Service\StrictHandling;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\Exception as DbException;
use PHPUnit\Framework\TestCase;

final class NoteServiceTest extends TestCase {
	private NoteMapper $noteMapper;
	private TrackMapper $trackMapper;
	private StrictHandling $strictHandling;
	private NoteService $service;
	private string $userId = 'testuser';

	protected function setUp(): void {
		$this->noteMapper = $this->createMock(NoteMapper::class);
		$this->trackMapper = $this->createMock(TrackMapper::class);
		// A mock's refuseArchived() does nothing, so tests get the legacy answers unless they call strict()
		$this->strictHandling = $this->createMock(StrictHandling::class);
		$this->service = new NoteService($this->noteMapper, $this->trackMapper, $this->strictHandling);
	}

	/** The client sent Prefer: handling=strict: writes to archived tracks are refused. */
	private function strict(): void {
		$this->strictHandling->method('refuseArchived')->willReturnCallback(function (Track $track): void {
			throw new ReadOnlyTrackException($track->getName() . ' is archived, so nothing was changed', 'archived');
		});
	}

	private function track(?string $archivedAt = null): Track {
		$track = new Track();
		$track->setId(1);
		$track->setType('boolean');
		$track->setUserId($this->userId);
		$track->setArchivedAt($archivedAt);
		$this->trackMapper->method('findByIdAndUser')->willReturn($track);
		return $track;
	}

	private function note(string $text): Note {
		$note = new Note();
		$note->setId(7);
		$note->setUserId($this->userId);
		$note->setTrackId(1);
		$note->setDate('2026-10-18');
		$note->setText($text);
		return $note;
	}

	private function noNote(): void {
		$this->noteMapper->method('findByUserTrackDate')
			->willThrowException(new DoesNotExistException(''));
	}

	private function expectNoNoteWrites(): void {
		$this->noteMapper->expects($this->never())->method('insert');
		$this->noteMapper->expects($this->never())->method('update');
		$this->noteMapper->expects($this->never())->method('deleteByUserTrackDate');
	}

	// --- normalise ---

	public static function refusedTexts(): array {
		return [
			'invalid UTF-8' => ["\xff", 'Notes must be UTF-8 text'],
			'escape sequence' => ["\x1b[2J", "Notes can't contain control characters"],
			'NUL' => ["a\x00b", "Notes can't contain control characters"],
			'DEL' => ["a\x7fb", "Notes can't contain control characters"],
			'C1 next line' => ["a\u{0085}b", "Notes can't contain control characters"],
			'501 characters' => [str_repeat('a', 501), 'Notes can be at most 500 characters'],
			'array' => [['a'], 'Notes must be UTF-8 text'],
			'JSON true' => [true, 'Notes must be UTF-8 text'],
			'JSON false' => [false, 'Notes must be UTF-8 text'],
		];
	}

	/**
	 * @dataProvider refusedTexts
	 */
	public function testNormaliseRefuses(mixed $text, string $message): void {
		$this->expectException(InvalidValueException::class);
		$this->expectExceptionMessage($message);
		NoteService::normalise($text);
	}

	public static function normalisedTexts(): array {
		return [
			'tab kept' => ["a\tb", "a\tb"],
			'line break kept' => ["a\nb", "a\nb"],
			'markup kept as is' => ['<script>alert(1)</script>', '<script>alert(1)</script>'],
			'trimmed' => ["  \n backup 03:14 \n ", 'backup 03:14'],
			'CRLF' => ["a\r\nb", "a\nb"],
			'CR' => ["a\rb", "a\nb"],
			'number' => [42, '42'],
			'null' => [null, ''],
			'whitespace only' => [" \n\t ", ''],
			'500 characters' => [str_repeat('a', 500), str_repeat('a', 500)],
			'500 code points' => [str_repeat('é', 500), str_repeat('é', 500)],
			'emoji' => ['☕️ 2', '☕️ 2'],
		];
	}

	/**
	 * @dataProvider normalisedTexts
	 */
	public function testNormalise(mixed $text, string $expected): void {
		$this->assertSame($expected, NoteService::normalise($text));
	}

	// --- set ---

	public function testSetInsertsNewNote(): void {
		$this->track();
		$this->noNote();
		$this->noteMapper->expects($this->once())->method('insert')
			->with($this->callback(fn (Note $n) => $n->getUserId() === $this->userId
				&& $n->getTrackId() === 1
				&& $n->getDate() === '2026-10-18'
				&& $n->getText() === 'backup 03:14'));
		$this->noteMapper->expects($this->never())->method('update');

		$this->assertSame('backup 03:14', $this->service->set($this->userId, 1, '2026-10-18', ' backup 03:14 '));
	}

	public function testSetReplacesExistingNote(): void {
		$this->track();
		$note = $this->note('old');
		$this->noteMapper->method('findByUserTrackDate')->willReturn($note);
		$this->noteMapper->expects($this->never())->method('insert');
		$this->noteMapper->expects($this->once())->method('update')
			->with($this->callback(fn (Note $n) => $n === $note && $n->getText() === 'new'));

		$this->assertSame('new', $this->service->set($this->userId, 1, '2026-10-18', 'new'));
	}

	public static function emptyTexts(): array {
		return [
			'empty' => [''],
			'whitespace' => [" \n "],
		];
	}

	/**
	 * @dataProvider emptyTexts
	 */
	public function testSetEmptyDeletes(string $text): void {
		$this->track();
		$this->noteMapper->expects($this->once())->method('deleteByUserTrackDate')
			->with($this->userId, 1, '2026-10-18');
		$this->noteMapper->expects($this->never())->method('insert');
		$this->noteMapper->expects($this->never())->method('update');

		$this->assertSame('', $this->service->set($this->userId, 1, '2026-10-18', $text));
	}

	public function testSetRefusesInvalidDate(): void {
		$this->track();
		$this->expectNoNoteWrites();

		$this->expectException(InvalidValueException::class);
		$this->expectExceptionMessage('Date must be a date as YYYY-MM-DD');
		$this->service->set($this->userId, 1, '2026-02-30', 'note');
	}

	public function testSetRefusesTooLongText(): void {
		$this->track();
		$this->expectNoNoteWrites();

		$this->expectException(InvalidValueException::class);
		$this->service->set($this->userId, 1, '2026-10-18', str_repeat('a', 501));
	}

	public function testSetUnknownTrack(): void {
		$this->trackMapper->method('findByIdAndUser')
			->willThrowException(new DoesNotExistException(''));
		$this->expectNoNoteWrites();

		$this->expectException(DoesNotExistException::class);
		$this->service->set($this->userId, 99, '2026-10-18', 'note');
	}

	public function testUnknownTrackIsReportedBeforeAnInvalidDate(): void {
		$this->trackMapper->method('findByIdAndUser')
			->willThrowException(new DoesNotExistException(''));

		$this->expectException(DoesNotExistException::class);
		$this->service->set($this->userId, 99, '2026-02-30', str_repeat('a', 501));
	}

	public function testSetOnArchivedTrackAnswersStoredNote(): void {
		$this->track('2026-10-01');
		$this->noteMapper->method('findByUserTrackDate')->willReturn($this->note('kept'));
		$this->expectNoNoteWrites();

		$this->assertSame('kept', $this->service->set($this->userId, 1, '2026-10-18', 'new'));
	}

	public function testSetOnArchivedTrackStillChecksText(): void {
		$this->track('2026-10-01');

		$this->expectException(InvalidValueException::class);
		$this->service->set($this->userId, 1, '2026-10-18', str_repeat('a', 501));
	}

	public function testSetRacingInsertUpdatesTheWinner(): void {
		$this->track();
		$winner = $this->note('theirs');
		$this->noteMapper->method('findByUserTrackDate')->willReturnOnConsecutiveCalls(
			$this->throwException(new DoesNotExistException('')),
			$winner,
		);
		$e = $this->createMock(DbException::class);
		$e->method('getReason')->willReturn(DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION);
		$this->noteMapper->expects($this->once())->method('insert')->willThrowException($e);
		$this->noteMapper->expects($this->once())->method('update')
			->with($this->callback(fn (Note $n) => $n === $winner && $n->getText() === 'mine'));

		$this->assertSame('mine', $this->service->set($this->userId, 1, '2026-10-18', 'mine'));
	}

	// --- append ---

	private function uniqueViolation(): DbException {
		$e = $this->createMock(DbException::class);
		$e->method('getReason')->willReturn(DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION);
		return $e;
	}

	public function testAppendToNoNoteStoresTheText(): void {
		$this->track();
		$this->noNote();
		$this->noteMapper->expects($this->once())->method('insert')
			->with($this->callback(fn (Note $n) => $n->getUserId() === $this->userId
				&& $n->getTrackId() === 1
				&& $n->getDate() === '2026-10-18'
				&& $n->getText() === 'https://strava.com/a/1'));
		$this->noteMapper->expects($this->never())->method('replaceText');

		$this->assertSame('https://strava.com/a/1', $this->service->append($this->userId, 1, '2026-10-18', ' https://strava.com/a/1 '));
	}

	public function testAppendJoinsWithALineBreak(): void {
		$this->track();
		$this->noteMapper->method('findByUserTrackDate')->willReturn($this->note('https://strava.com/a/1'));
		$this->noteMapper->expects($this->never())->method('insert');
		$this->noteMapper->expects($this->once())->method('replaceText')
			->with(7, 'https://strava.com/a/1', "https://strava.com/a/1\nhttps://strava.com/a/2")
			->willReturn(1);

		$this->assertSame(
			"https://strava.com/a/1\nhttps://strava.com/a/2",
			$this->service->append($this->userId, 1, '2026-10-18', 'https://strava.com/a/2'),
		);
	}

	public static function textsAlreadyThere(): array {
		return [
			'the same line' => ["a\nb\nc", 'b'],
			'the only line' => ['b', 'b'],
			'after trimming' => ["a\nb", " b\n"],
			'several lines in order' => ["a\nb\nc", "b\nc"],
			'the whole note' => ["a\nb", "a\nb"],
		];
	}

	/**
	 * @dataProvider textsAlreadyThere
	 */
	public function testAppendSkipsTextAlreadyThere(string $stored, string $text): void {
		$this->track();
		$this->noteMapper->method('findByUserTrackDate')->willReturn($this->note($stored));
		$this->expectNoNoteWrites();
		$this->noteMapper->expects($this->never())->method('replaceText');

		$this->assertSame($stored, $this->service->append($this->userId, 1, '2026-10-18', $text));
	}

	public static function textsNotThere(): array {
		return [
			'part of a line' => ["https://strava.com/a/12\nx", 'https://strava.com/a/1', "https://strava.com/a/12\nx\nhttps://strava.com/a/1"],
			'lines in another order' => ["a\nb", "b\na", "a\nb\nb\na"],
			'lines not next to each other' => ["a\nx\nb", "a\nb", "a\nx\nb\na\nb"],
			'another case' => ['Run', 'run', "Run\nrun"],
		];
	}

	/**
	 * @dataProvider textsNotThere
	 */
	public function testAppendAddsTextNotThereAsWholeLines(string $stored, string $text, string $expected): void {
		$this->track();
		$this->noteMapper->method('findByUserTrackDate')->willReturn($this->note($stored));
		$this->noteMapper->expects($this->once())->method('replaceText')
			->with(7, $stored, $expected)
			->willReturn(1);

		$this->assertSame($expected, $this->service->append($this->userId, 1, '2026-10-18', $text));
	}

	public function testAppendAllowDuplicateAppendsAgain(): void {
		$this->track();
		$this->noteMapper->method('findByUserTrackDate')->willReturn($this->note('a'));
		$this->noteMapper->expects($this->once())->method('replaceText')
			->with(7, 'a', "a\na")
			->willReturn(1);

		$this->assertSame("a\na", $this->service->append($this->userId, 1, '2026-10-18', 'a', true));
	}

	/**
	 * @dataProvider emptyTexts
	 */
	public function testAppendEmptyChangesNothing(string $text): void {
		$this->track();
		$this->noteMapper->method('findByUserTrackDate')->willReturn($this->note('kept'));
		$this->expectNoNoteWrites();
		$this->noteMapper->expects($this->never())->method('replaceText');

		$this->assertSame('kept', $this->service->append($this->userId, 1, '2026-10-18', $text));
	}

	public function testAppendEmptyToNoNoteAnswersEmpty(): void {
		$this->track();
		$this->noNote();
		$this->expectNoNoteWrites();

		$this->assertSame('', $this->service->append($this->userId, 1, '2026-10-18', ''));
	}

	public function testAppendUpToTheLimit(): void {
		$this->track();
		$stored = str_repeat('a', 250);
		$this->noteMapper->method('findByUserTrackDate')->willReturn($this->note($stored));
		$this->noteMapper->expects($this->once())->method('replaceText')->willReturn(1);

		// 250 + the line break + 249 = 500
		$this->assertSame(500, mb_strlen($this->service->append($this->userId, 1, '2026-10-18', str_repeat('é', 249))));
	}

	public function testAppendOverTheLimitWritesNothing(): void {
		$this->track();
		$this->noteMapper->method('findByUserTrackDate')->willReturn($this->note(str_repeat('a', 250)));
		$this->expectNoNoteWrites();
		$this->noteMapper->expects($this->never())->method('replaceText');

		$this->expectException(InvalidValueException::class);
		$this->expectExceptionMessage('The note would be longer than 500 characters');
		$this->service->append($this->userId, 1, '2026-10-18', str_repeat('b', 250));
	}

	public function testAppendTextOverTheLimitOnItsOwn(): void {
		$this->track();
		$this->expectNoNoteWrites();

		$this->expectException(InvalidValueException::class);
		$this->expectExceptionMessage('Notes can be at most 500 characters');
		$this->service->append($this->userId, 1, '2026-10-18', str_repeat('a', 501));
	}

	public function testAppendOnArchivedTrackChangesNothing(): void {
		$this->track('2026-10-01');
		$this->noteMapper->method('findByUserTrackDate')->willReturn($this->note('kept'));
		$this->expectNoNoteWrites();
		$this->noteMapper->expects($this->never())->method('replaceText');

		$this->assertSame('kept', $this->service->append($this->userId, 1, '2026-10-18', 'new'));
	}

	public function testAppendUnknownTrack(): void {
		$this->trackMapper->method('findByIdAndUser')
			->willThrowException(new DoesNotExistException(''));
		$this->expectNoNoteWrites();

		$this->expectException(DoesNotExistException::class);
		$this->service->append($this->userId, 99, '2026-10-18', 'note');
	}

	public function testAppendRefusesInvalidDate(): void {
		$this->track();
		$this->expectNoNoteWrites();

		$this->expectException(InvalidValueException::class);
		$this->expectExceptionMessage('Date must be a date as YYYY-MM-DD');
		$this->service->append($this->userId, 1, '2026-02-30', 'note');
	}

	public static function refusedAppendTexts(): array {
		return [
			'invalid UTF-8' => ["\xff", 'Notes must be UTF-8 text'],
			'control characters' => ["\x1b[2J", "Notes can't contain control characters"],
		];
	}

	/**
	 * @dataProvider refusedAppendTexts
	 */
	public function testAppendRefusesTextAsSetDoes(string $text, string $message): void {
		$this->track();
		$this->expectNoNoteWrites();

		$this->expectException(InvalidValueException::class);
		$this->expectExceptionMessage($message);
		$this->service->append($this->userId, 1, '2026-10-18', $text);
	}

	public function testAppendRetriesAfterLosingTheRace(): void {
		$this->track();
		$this->noteMapper->method('findByUserTrackDate')->willReturnOnConsecutiveCalls(
			$this->note('a'),
			$this->note("a\ntheirs"),
		);
		$this->noteMapper->expects($this->exactly(2))->method('replaceText')
			->willReturnCallback(fn (int $id, string $old, string $new) => $old === 'a' ? 0 : 1);

		$this->assertSame("a\ntheirs\nmine", $this->service->append($this->userId, 1, '2026-10-18', 'mine'));
	}

	public function testAppendRetrySkipsTextTheWinnerAdded(): void {
		$this->track();
		$this->noteMapper->method('findByUserTrackDate')->willReturnOnConsecutiveCalls(
			$this->note('a'),
			$this->note("a\nmine"),
		);
		$this->noteMapper->expects($this->once())->method('replaceText')->willReturn(0);

		$this->assertSame("a\nmine", $this->service->append($this->userId, 1, '2026-10-18', 'mine'));
	}

	public function testAppendAfterLosingTheInsertAppendsToTheWinner(): void {
		$this->track();
		$this->noteMapper->method('findByUserTrackDate')->willReturnOnConsecutiveCalls(
			$this->throwException(new DoesNotExistException('')),
			$this->note('theirs'),
		);
		$this->noteMapper->expects($this->once())->method('insert')->willThrowException($this->uniqueViolation());
		$this->noteMapper->expects($this->once())->method('replaceText')
			->with(7, 'theirs', "theirs\nmine")
			->willReturn(1);

		$this->assertSame("theirs\nmine", $this->service->append($this->userId, 1, '2026-10-18', 'mine'));
	}

	public function testAppendGivesUpAfterThreeLostRaces(): void {
		$this->track();
		$this->noteMapper->method('findByUserTrackDate')->willReturnCallback(fn () => $this->note('a'));
		$this->noteMapper->expects($this->exactly(3))->method('replaceText')->willReturn(0);

		$this->expectException(NoteConflictException::class);
		$this->expectExceptionMessage('The note changed while appending; try again');
		$this->service->append($this->userId, 1, '2026-10-18', 'mine');
	}

	public function testAppendOtherDatabaseErrorsAreNotRetried(): void {
		$this->track();
		$this->noNote();
		$e = $this->createMock(DbException::class);
		$e->method('getReason')->willReturn(DbException::REASON_DRIVER);
		$this->noteMapper->expects($this->once())->method('insert')->willThrowException($e);

		$this->expectException(DbException::class);
		$this->service->append($this->userId, 1, '2026-10-18', 'mine');
	}

	public function testCheckAppendRefusesWhatWouldNotFit(): void {
		$this->noteMapper->method('findByUserTrackDate')->willReturn($this->note(str_repeat('a', 400)));

		$this->expectException(InvalidValueException::class);
		$this->expectExceptionMessage('The note would be longer than 500 characters');
		$this->service->checkAppend($this->userId, 1, '2026-10-18', str_repeat('b', 100), false);
	}

	public function testCheckAppendPassesTextAlreadyThere(): void {
		$this->noteMapper->method('findByUserTrackDate')->willReturn($this->note(str_repeat('a', 500)));

		$this->service->checkAppend($this->userId, 1, '2026-10-18', str_repeat('a', 500), false);
		$this->addToAssertionCount(1);
	}

	// --- delete ---

	public function testDelete(): void {
		$this->track();
		$this->noteMapper->expects($this->once())->method('deleteByUserTrackDate')
			->with($this->userId, 1, '2026-10-18');

		$this->service->delete($this->userId, 1, '2026-10-18');
	}

	public function testDeleteOnArchivedTrackDeletesNothing(): void {
		$this->track('2026-10-01');
		$this->expectNoNoteWrites();

		$this->service->delete($this->userId, 1, '2026-10-18');
	}

	public function testDeleteRefusesInvalidDate(): void {
		$this->track();
		$this->expectNoNoteWrites();

		$this->expectException(InvalidValueException::class);
		$this->service->delete($this->userId, 1, '18.10.2026');
	}

	// --- findByDateRange ---

	public static function invalidRanges(): array {
		return [
			'missing from' => ['', '2026-10-18'],
			'missing to' => ['2026-10-01', ''],
			'not a date' => ['2026-02-30', '2026-10-18'],
		];
	}

	/**
	 * @dataProvider invalidRanges
	 */
	public function testFindByDateRangeRefusesInvalidDates(string $from, string $to): void {
		$this->noteMapper->expects($this->never())->method('findByUserAndDateRange');

		$this->expectException(InvalidValueException::class);
		$this->expectExceptionMessage('From and to must be dates as YYYY-MM-DD');
		$this->service->findByDateRange($this->userId, $from, $to);
	}

	public function testFindByDateRange(): void {
		$notes = [$this->note('a')];
		$this->noteMapper->expects($this->once())->method('findByUserAndDateRange')
			->with($this->userId, '2026-10-01', '2026-10-18')
			->willReturn($notes);

		$this->assertSame($notes, $this->service->findByDateRange($this->userId, '2026-10-01', '2026-10-18'));
	}

	// --- strict handling (Prefer: handling=strict) ---

	private function expectRefused(callable $call): void {
		try {
			$call();
			$this->fail('Expected ReadOnlyTrackException');
		} catch (ReadOnlyTrackException $e) {
			$this->assertSame('archived', $e->getReason());
		}
	}

	public function testStrictRefusesEveryNoteWriteOnAnArchivedTrack(): void {
		$this->strict();
		$this->track('2026-10-01');
		$this->noteMapper->method('findByUserTrackDate')->willReturn($this->note('kept'));
		$this->expectNoNoteWrites();
		$this->noteMapper->expects($this->never())->method('replaceText');

		$this->expectRefused(fn () => $this->service->set($this->userId, 1, '2026-10-18', 'new'));
		$this->expectRefused(fn () => $this->service->append($this->userId, 1, '2026-10-18', 'new'));
		$this->expectRefused(fn () => $this->service->delete($this->userId, 1, '2026-10-18'));
	}

	public function testStrictRefusesWritesThatWouldChangeNothing(): void {
		$this->strict();
		$this->track('2026-10-01');
		$this->noNote();
		$this->expectNoNoteWrites();

		$this->expectRefused(fn () => $this->service->append($this->userId, 1, '2026-10-18', ''));
		$this->expectRefused(fn () => $this->service->set($this->userId, 1, '2026-10-18', ''));
		$this->expectRefused(fn () => $this->service->delete($this->userId, 1, '2026-10-18'));
	}

	public function testStrictStillReportsInvalidTextFirst(): void {
		$this->strict();
		$this->track('2026-10-01');
		$this->expectNoNoteWrites();

		$this->expectException(InvalidValueException::class);
		$this->service->set($this->userId, 1, '2026-10-18', str_repeat('a', 501));
	}

	public function testStrictStillReportsAnInvalidDateFirstOnDelete(): void {
		$this->strict();
		$this->track('2026-10-01');
		$this->expectNoNoteWrites();

		$this->expectException(InvalidValueException::class);
		$this->service->delete($this->userId, 1, '18.10.2026');
	}
}
