<?php

declare(strict_types=1);

namespace Service;

use OCA\Tickbuddy\Db\Note;
use OCA\Tickbuddy\Db\NoteMapper;
use OCA\Tickbuddy\Db\Track;
use OCA\Tickbuddy\Db\TrackMapper;
use OCA\Tickbuddy\Service\InvalidValueException;
use OCA\Tickbuddy\Service\NoteService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\Exception as DbException;
use PHPUnit\Framework\TestCase;

final class NoteServiceTest extends TestCase {
	private NoteMapper $noteMapper;
	private TrackMapper $trackMapper;
	private NoteService $service;
	private string $userId = 'testuser';

	protected function setUp(): void {
		$this->noteMapper = $this->createMock(NoteMapper::class);
		$this->trackMapper = $this->createMock(TrackMapper::class);
		$this->service = new NoteService($this->noteMapper, $this->trackMapper);
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
			'201 characters' => [str_repeat('a', 201), 'Notes can be at most 200 characters'],
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
			'200 characters' => [str_repeat('a', 200), str_repeat('a', 200)],
			'200 code points' => [str_repeat('é', 200), str_repeat('é', 200)],
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
		$this->service->set($this->userId, 1, '2026-10-18', str_repeat('a', 201));
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
		$this->service->set($this->userId, 99, '2026-02-30', str_repeat('a', 201));
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
		$this->service->set($this->userId, 1, '2026-10-18', str_repeat('a', 201));
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
}
