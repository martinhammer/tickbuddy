<?php

declare(strict_types=1);

namespace Service;

use OCA\Tickbuddy\Db\Tick;
use OCA\Tickbuddy\Db\TickMapper;
use OCA\Tickbuddy\Db\Track;
use OCA\Tickbuddy\Db\TrackMapper;
use OCA\Tickbuddy\Service\InvalidTrackTypeException;
use OCA\Tickbuddy\Service\InvalidValueException;
use OCA\Tickbuddy\Service\NoteConflictException;
use OCA\Tickbuddy\Service\NoteService;
use OCA\Tickbuddy\Service\ReadOnlyTrackException;
use OCA\Tickbuddy\Service\StrictHandling;
use OCA\Tickbuddy\Service\TickService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\Exception as DbException;
use PHPUnit\Framework\TestCase;

final class TickServiceTest extends TestCase {
	private TickMapper $tickMapper;
	private TrackMapper $trackMapper;
	private NoteService $noteService;
	private StrictHandling $strictHandling;
	private TickService $service;
	private string $userId = 'testuser';

	protected function setUp(): void {
		$this->tickMapper = $this->createMock(TickMapper::class);
		$this->trackMapper = $this->createMock(TrackMapper::class);
		$this->noteService = $this->createMock(NoteService::class);
		// A mock's refuseArchived() does nothing, so tests get the legacy answers unless they call strict()
		$this->strictHandling = $this->createMock(StrictHandling::class);
		$this->service = new TickService($this->tickMapper, $this->trackMapper, $this->noteService, $this->strictHandling);
	}

	/** The client sent Prefer: handling=strict: writes to archived tracks are refused. */
	private function strict(): void {
		$this->strictHandling->method('refuseArchived')->willReturnCallback(function (Track $track): void {
			throw new ReadOnlyTrackException($track->getName() . ' is archived, so nothing was changed', 'archived');
		});
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
		$this->assertSame(['ticked' => true, 'value' => 1], $result);
	}

	public function testToggleDeletesTickWhenExists(): void {
		$this->trackMapper->method('findByIdAndUser')->willReturn($this->makeBooleanTrack());
		$tick = new Tick();
		$tick->setId(10);
		$this->tickMapper->method('findByUserTrackDate')->willReturn($tick);
		$this->tickMapper->expects($this->once())->method('delete')->with($tick);

		$result = $this->service->toggle($this->userId, 1, '2026-04-11');
		$this->assertSame(['ticked' => false, 'value' => 0], $result);
	}

	public function testToggleRacingAnotherInsertUndoesIt(): void {
		$this->trackMapper->method('findByIdAndUser')->willReturn($this->makeBooleanTrack());
		$winner = $this->makeTick(1);
		$this->tickMapper->method('findByUserTrackDate')->willReturnOnConsecutiveCalls(
			$this->throwException(new DoesNotExistException('')),
			$winner,
		);
		$this->tickMapper->expects($this->once())->method('insert')->willThrowException($this->uniqueViolation());
		$this->tickMapper->expects($this->once())->method('delete')->with($winner);

		$this->assertSame(['ticked' => false, 'value' => 0], $this->service->toggle($this->userId, 1, '2026-04-11'));
	}

	public function testToggleRacingAnInsertAlreadyUndoneIsUnticked(): void {
		$this->trackMapper->method('findByIdAndUser')->willReturn($this->makeBooleanTrack());
		$this->noTick();
		$this->tickMapper->method('insert')->willThrowException($this->uniqueViolation());
		$this->tickMapper->expects($this->never())->method('delete');

		$this->assertSame(['ticked' => false, 'value' => 0], $this->service->toggle($this->userId, 1, '2026-04-11'));
	}

	public function testToggleOtherDatabaseErrorsAreNotCaught(): void {
		$this->trackMapper->method('findByIdAndUser')->willReturn($this->makeBooleanTrack());
		$this->noTick();
		$e = new DbException('Connection lost');
		$this->tickMapper->method('insert')->willThrowException($e);
		$this->tickMapper->expects($this->never())->method('delete');

		$this->expectExceptionObject($e);
		$this->service->toggle($this->userId, 1, '2026-04-11');
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
		$this->assertSame(['value' => 3], $result);
	}

	public function testSetDeletesTickWhenValueIsZero(): void {
		$this->trackMapper->method('findByIdAndUser')->willReturn($this->makeCounterTrack());
		$tick = new Tick();
		$tick->setId(10);
		$this->tickMapper->method('findByUserTrackDate')->willReturn($tick);
		$this->tickMapper->expects($this->once())->method('delete')->with($tick);

		$result = $this->service->set($this->userId, 2, '2026-04-11', 0);
		$this->assertSame(['value' => 0], $result);
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

		$this->assertSame(['ticked' => true, 'value' => 1], $this->service->toggle($this->userId, 1, '2026-10-14'));
	}

	public function testToggleOnArchivedTrackAddsNoTick(): void {
		$this->trackMapper->method('findByIdAndUser')->willReturn($this->archive($this->makeBooleanTrack()));
		$this->tickMapper->method('findByUserTrackDate')
			->willThrowException(new DoesNotExistException(''));
		$this->expectNoTickWrites();

		$this->assertSame(['ticked' => false, 'value' => 0], $this->service->toggle($this->userId, 1, '2026-10-14'));
	}

	public function testSetOnArchivedTrackKeepsStoredValue(): void {
		$this->trackMapper->method('findByIdAndUser')->willReturn($this->archive($this->makeCounterTrack()));
		$this->tickMapper->method('findByUserTrackDate')->willReturn($this->makeTick(3));
		$this->expectNoTickWrites();

		$this->assertSame(['value' => 3], $this->service->set($this->userId, 2, '2026-10-14', 5));
	}

	public function testSetOnArchivedTrackAddsNoTick(): void {
		$this->trackMapper->method('findByIdAndUser')->willReturn($this->archive($this->makeCounterTrack()));
		$this->tickMapper->method('findByUserTrackDate')
			->willThrowException(new DoesNotExistException(''));
		$this->expectNoTickWrites();

		$this->assertSame(['value' => 0], $this->service->set($this->userId, 2, '2026-10-14', 2));
	}

	public function testToggleOnArchivedCounterTrackStillRejected(): void {
		$this->trackMapper->method('findByIdAndUser')->willReturn($this->archive($this->makeCounterTrack()));

		$this->expectException(InvalidTrackTypeException::class);
		$this->service->toggle($this->userId, 2, '2026-10-14');
	}

	// --- set on yes/no tracks ---

	private function noTick(): void {
		$this->tickMapper->method('findByUserTrackDate')
			->willThrowException(new DoesNotExistException(''));
	}

	public static function trueValueProvider(): array {
		return [[true], ['true'], ['TRUE'], [' on '], ['Yes'], [1], ['1']];
	}

	public static function falseValueProvider(): array {
		return [[false], ['off'], ['NO'], [0], ['0']];
	}

	public static function invalidYesNoProvider(): array {
		return [[null], [''], [2], ['maybe']];
	}

	/**
	 * @dataProvider trueValueProvider
	 */
	public function testSetTrueTicksAnUntickedDay(mixed $value): void {
		$this->trackMapper->method('findByIdAndUser')->willReturn($this->makeBooleanTrack());
		$this->noTick();
		$this->tickMapper->expects($this->once())->method('insert')
			->with($this->callback(fn (Tick $t) => $t->getValue() === 1 && $t->getDate() === '2026-10-17'));

		$this->assertSame(['value' => 1], $this->service->set($this->userId, 1, '2026-10-17', $value));
	}

	/**
	 * @dataProvider trueValueProvider
	 */
	public function testSetTrueOnATickedDayChangesNothing(mixed $value): void {
		$this->trackMapper->method('findByIdAndUser')->willReturn($this->makeBooleanTrack());
		$this->tickMapper->method('findByUserTrackDate')->willReturn($this->makeTick(1));
		$this->expectNoTickWrites();

		$this->assertSame(['value' => 1], $this->service->set($this->userId, 1, '2026-10-17', $value));
	}

	/**
	 * @dataProvider falseValueProvider
	 */
	public function testSetFalseUnticksATickedDay(mixed $value): void {
		$this->trackMapper->method('findByIdAndUser')->willReturn($this->makeBooleanTrack());
		$tick = $this->makeTick(1);
		$this->tickMapper->method('findByUserTrackDate')->willReturn($tick);
		$this->tickMapper->expects($this->once())->method('delete')->with($tick);

		$this->assertSame(['value' => 0], $this->service->set($this->userId, 1, '2026-10-17', $value));
	}

	/**
	 * @dataProvider falseValueProvider
	 */
	public function testSetFalseOnAnUntickedDayChangesNothing(mixed $value): void {
		$this->trackMapper->method('findByIdAndUser')->willReturn($this->makeBooleanTrack());
		$this->noTick();
		$this->expectNoTickWrites();

		$this->assertSame(['value' => 0], $this->service->set($this->userId, 1, '2026-10-17', $value));
	}

	/**
	 * @dataProvider invalidYesNoProvider
	 */
	public function testSetRejectsAnInvalidYesNoValue(mixed $value): void {
		$this->trackMapper->method('findByIdAndUser')->willReturn($this->makeBooleanTrack());
		$this->expectNoTickWrites();

		$this->expectException(InvalidValueException::class);
		$this->expectExceptionMessage('Yes/no tracks take a value of true or false');
		$this->service->set($this->userId, 1, '2026-10-17', $value);
	}

	public function testSetTrueRacingAnotherInsertIsTicked(): void {
		$this->trackMapper->method('findByIdAndUser')->willReturn($this->makeBooleanTrack());
		$this->noTick();
		$this->tickMapper->method('insert')->willThrowException($this->uniqueViolation());

		$this->assertSame(['value' => 1], $this->service->set($this->userId, 1, '2026-10-17', true));
	}

	public function testSetCounterRacingAnotherInsertReplacesItsValue(): void {
		$this->trackMapper->method('findByIdAndUser')->willReturn($this->makeCounterTrack());
		$winner = $this->makeTick(2);
		$this->tickMapper->method('findByUserTrackDate')->willReturnOnConsecutiveCalls(
			$this->throwException(new DoesNotExistException('')),
			$winner,
		);
		$this->tickMapper->expects($this->once())->method('insert')->willThrowException($this->uniqueViolation());
		$this->tickMapper->expects($this->once())->method('update')
			->with($this->callback(fn (Tick $t) => $t === $winner && $t->getValue() === 5));

		$this->assertSame(['value' => 5], $this->service->set($this->userId, 2, '2026-10-17', 5));
	}

	public function testSetCounterRacingAnInsertAlreadyDeletedInsertsAgain(): void {
		$this->trackMapper->method('findByIdAndUser')->willReturn($this->makeCounterTrack());
		$this->noTick();
		$this->tickMapper->expects($this->exactly(2))->method('insert')->willReturnOnConsecutiveCalls(
			$this->throwException($this->uniqueViolation()),
			$this->returnArgument(0),
		);

		$this->assertSame(['value' => 5], $this->service->set($this->userId, 2, '2026-10-17', 5));
	}

	public function testSetCounterGivesUpAfterThreeLostRaces(): void {
		$this->trackMapper->method('findByIdAndUser')->willReturn($this->makeCounterTrack());
		$this->noTick();
		$e = $this->uniqueViolation();
		$this->tickMapper->expects($this->exactly(3))->method('insert')->willThrowException($e);

		$this->expectExceptionObject($e);
		$this->service->set($this->userId, 2, '2026-10-17', 5);
	}

	public static function acceptedCounterValueProvider(): array {
		return [
			'integer' => [5, 5],
			'digits' => ['5', 5],
			'digits with spaces' => [' 5 ', 5],
			'leading zeros' => ['007', 7],
			'the limit' => [2147483647, 2147483647],
			'the limit as digits' => ['2147483647', 2147483647],
		];
	}

	/**
	 * @dataProvider acceptedCounterValueProvider
	 */
	public function testSetCounterAcceptsAWholeNumber(mixed $value, int $stored): void {
		$this->trackMapper->method('findByIdAndUser')->willReturn($this->makeCounterTrack());
		$this->noTick();
		$this->tickMapper->expects($this->once())->method('insert')
			->with($this->callback(fn (Tick $t) => $t->getValue() === $stored));

		$this->assertSame(['value' => $stored], $this->service->set($this->userId, 2, '2026-10-17', $value));
	}

	public function testSetCounterZeroDeletesTheTick(): void {
		$this->trackMapper->method('findByIdAndUser')->willReturn($this->makeCounterTrack());
		$tick = $this->makeTick(4);
		$this->tickMapper->method('findByUserTrackDate')->willReturn($tick);
		$this->tickMapper->expects($this->once())->method('delete')->with($tick);

		$this->assertSame(['value' => 0], $this->service->set($this->userId, 2, '2026-10-17', '0'));
	}

	public static function refusedCounterValueProvider(): array {
		$notANumber = 'Counter tracks take a whole number, 0 or more';
		$tooLarge = 'Counter values can be at most 2147483647';
		return [
			'missing' => [null, $notANumber],
			'empty' => ['', $notANumber],
			'true' => [true, $notANumber],
			'false' => [false, $notANumber],
			'"true"' => ['true', $notANumber],
			'trailing text' => ['5abc', $notANumber],
			'negative digits' => ['-5', $notANumber],
			'negative integer' => [-5, $notANumber],
			'plus sign' => ['+5', $notANumber],
			'decimal digits' => ['5.0', $notANumber],
			'exponent' => ['1e3', $notANumber],
			'float' => [5.5, $notANumber],
			'whole float' => [5.0, $notANumber],
			'one past the limit' => [2147483648, $tooLarge],
			'one past the limit as digits' => ['2147483648', $tooLarge],
			'beyond PHP integers' => ['99999999999999999999', $tooLarge],
		];
	}

	/**
	 * @dataProvider refusedCounterValueProvider
	 */
	public function testSetCounterRefusesAnythingElse(mixed $value, string $message): void {
		$this->trackMapper->method('findByIdAndUser')->willReturn($this->makeCounterTrack());
		$this->expectNoTickWrites();
		$this->noteService->expects($this->never())->method('writeForTick');

		$this->expectException(InvalidValueException::class);
		$this->expectExceptionMessage($message);
		$this->service->set($this->userId, 2, '2026-10-17', $value, 'a note');
	}

	public function testSetCounterReportsTheValueBeforeTheDate(): void {
		$this->trackMapper->method('findByIdAndUser')->willReturn($this->makeCounterTrack());

		$this->expectExceptionMessage('Counter tracks take a whole number, 0 or more');
		$this->service->set($this->userId, 2, '2026-02-30', 'x');
	}

	public function testSetCounterOnArchivedTrackStillRejectsAnInvalidValue(): void {
		$this->trackMapper->method('findByIdAndUser')->willReturn($this->archive($this->makeCounterTrack()));

		$this->expectExceptionMessage('Counter tracks take a whole number, 0 or more');
		$this->service->set($this->userId, 2, '2026-10-17', -1);
	}

	public function testSetCounterReadsAStringValue(): void {
		$this->trackMapper->method('findByIdAndUser')->willReturn($this->makeCounterTrack());
		$this->noTick();
		$this->tickMapper->expects($this->once())->method('insert')
			->with($this->callback(fn (Tick $t) => $t->getValue() === 5));

		$this->assertSame(['value' => 5], $this->service->set($this->userId, 2, '2026-10-17', '5'));
	}

	public function testSetOnArchivedYesNoTrackAnswersTheStoredState(): void {
		$this->trackMapper->method('findByIdAndUser')->willReturn($this->archive($this->makeBooleanTrack()));
		$this->noTick();
		$this->expectNoTickWrites();

		$this->assertSame(['value' => 0], $this->service->set($this->userId, 1, '2026-10-14', true));
	}

	public function testSetOnArchivedYesNoTrackStillRejectsAnInvalidValue(): void {
		$this->trackMapper->method('findByIdAndUser')->willReturn($this->archive($this->makeBooleanTrack()));
		$this->expectNoTickWrites();

		$this->expectException(InvalidValueException::class);
		$this->service->set($this->userId, 1, '2026-10-14', 'maybe');
	}

	// --- dates ---

	public static function invalidDateProvider(): array {
		return [['2026-02-30'], ['2026-2-3'], ['']];
	}

	/**
	 * @dataProvider invalidDateProvider
	 */
	public function testToggleRejectsAnInvalidDate(string $date): void {
		$this->trackMapper->method('findByIdAndUser')->willReturn($this->makeBooleanTrack());
		$this->expectNoTickWrites();

		$this->expectException(InvalidValueException::class);
		$this->expectExceptionMessage('Date must be a date as YYYY-MM-DD');
		$this->service->toggle($this->userId, 1, $date);
	}

	/**
	 * @dataProvider invalidDateProvider
	 */
	public function testSetCounterRejectsAnInvalidDate(string $date): void {
		$this->trackMapper->method('findByIdAndUser')->willReturn($this->makeCounterTrack());
		$this->expectNoTickWrites();

		$this->expectException(InvalidValueException::class);
		$this->expectExceptionMessage('Date must be a date as YYYY-MM-DD');
		$this->service->set($this->userId, 2, $date, 3);
	}

	/**
	 * @dataProvider invalidDateProvider
	 */
	public function testSetYesNoRejectsAnInvalidDate(string $date): void {
		$this->trackMapper->method('findByIdAndUser')->willReturn($this->makeBooleanTrack());
		$this->expectNoTickWrites();

		$this->expectException(InvalidValueException::class);
		$this->expectExceptionMessage('Date must be a date as YYYY-MM-DD');
		$this->service->set($this->userId, 1, $date, true);
	}

	public function testArchivedTrackStillRejectsAnInvalidDate(): void {
		$this->trackMapper->method('findByIdAndUser')->willReturn($this->archive($this->makeBooleanTrack()));
		$this->expectNoTickWrites();

		$this->expectException(InvalidValueException::class);
		$this->service->toggle($this->userId, 1, '2026-02-30');
	}

	public function testWrongTypeIsReportedBeforeAnInvalidDate(): void {
		$this->trackMapper->method('findByIdAndUser')->willReturn($this->makeCounterTrack());

		$this->expectException(InvalidTrackTypeException::class);
		$this->service->toggle($this->userId, 2, '2026-02-30');
	}

	// --- increment ---

	private function uniqueViolation(): DbException {
		$e = $this->createMock(DbException::class);
		$e->method('getReason')->willReturn(DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION);
		return $e;
	}

	public function testIncrementRejectsYesNoTrack(): void {
		$this->trackMapper->method('findByIdAndUser')->willReturn($this->makeBooleanTrack());

		$this->expectException(InvalidTrackTypeException::class);
		$this->expectExceptionMessage('Increment is only available for counter tracks');
		$this->service->increment($this->userId, 1, '2026-10-17', 1);
	}

	public function testIncrementRejectsDeltaZero(): void {
		$this->trackMapper->method('findByIdAndUser')->willReturn($this->makeCounterTrack());
		$this->tickMapper->expects($this->never())->method('addToValue');

		$this->expectException(InvalidValueException::class);
		$this->expectExceptionMessage('Delta must be a whole number other than 0');
		$this->service->increment($this->userId, 2, '2026-10-17', 0);
	}

	public function testIncrementRejectsAnInvalidDate(): void {
		$this->trackMapper->method('findByIdAndUser')->willReturn($this->makeCounterTrack());
		$this->tickMapper->expects($this->never())->method('addToValue');

		$this->expectException(InvalidValueException::class);
		$this->expectExceptionMessage('Date must be a date as YYYY-MM-DD');
		$this->service->increment($this->userId, 2, '2026-02-30', 1);
	}

	public function testIncrementOfAnExistingRowAnswersTheNewValue(): void {
		$this->trackMapper->method('findByIdAndUser')->willReturn($this->makeCounterTrack());
		$this->tickMapper->expects($this->once())->method('addToValue')
			->with($this->userId, 2, '2026-10-17', 2)->willReturn(1);
		$this->tickMapper->method('deleteIfNotPositive')->willReturn(0);
		$this->tickMapper->method('findByUserTrackDate')->willReturn($this->makeTick(5));
		$this->tickMapper->expects($this->never())->method('insert');

		$this->assertSame(5, $this->service->increment($this->userId, 2, '2026-10-17', 2)['value']);
	}

	public function testIncrementToZeroOrLessAnswersZero(): void {
		$this->trackMapper->method('findByIdAndUser')->willReturn($this->makeCounterTrack());
		$this->tickMapper->method('addToValue')->willReturn(1);
		$this->tickMapper->expects($this->once())->method('deleteIfNotPositive')->willReturn(1);

		$this->assertSame(0, $this->service->increment($this->userId, 2, '2026-10-17', -10)['value']);
	}

	public function testIncrementOfAnEmptyDayInsertsTheDelta(): void {
		$this->trackMapper->method('findByIdAndUser')->willReturn($this->makeCounterTrack());
		$this->tickMapper->method('addToValue')->willReturn(0);
		$this->tickMapper->expects($this->once())->method('insert')
			->with($this->callback(fn (Tick $t) => $t->getValue() === 3));

		$this->assertSame(3, $this->service->increment($this->userId, 2, '2026-10-17', 3)['value']);
	}

	public function testDecrementOfAnEmptyDayAnswersZero(): void {
		$this->trackMapper->method('findByIdAndUser')->willReturn($this->makeCounterTrack());
		$this->tickMapper->method('addToValue')->willReturn(0);
		$this->tickMapper->expects($this->never())->method('insert');

		$this->assertSame(0, $this->service->increment($this->userId, 2, '2026-10-17', -2)['value']);
	}

	public function testIncrementRacingAnotherInsertAddsToItsRow(): void {
		$this->trackMapper->method('findByIdAndUser')->willReturn($this->makeCounterTrack());
		$this->tickMapper->expects($this->exactly(2))->method('addToValue')
			->with($this->userId, 2, '2026-10-17', 3)
			->willReturnOnConsecutiveCalls(0, 1);
		$this->tickMapper->method('insert')->willThrowException($this->uniqueViolation());
		$this->tickMapper->method('deleteIfNotPositive')->willReturn(0);
		$this->tickMapper->method('findByUserTrackDate')->willReturn($this->makeTick(4));

		$this->assertSame(4, $this->service->increment($this->userId, 2, '2026-10-17', 3)['value']);
	}

	public function testIncrementRacingAnInsertAlreadyDeletedInsertsAgain(): void {
		$this->trackMapper->method('findByIdAndUser')->willReturn($this->makeCounterTrack());
		// Our insert lost to another request's, whose row a decrement then deleted
		$this->noTick();
		$this->tickMapper->expects($this->exactly(2))->method('addToValue')->willReturn(0);
		$this->tickMapper->expects($this->exactly(2))->method('insert')->willReturnOnConsecutiveCalls(
			$this->throwException($this->uniqueViolation()),
			$this->returnArgument(0),
		);

		$this->assertSame(3, $this->service->increment($this->userId, 2, '2026-10-17', 3)['value']);
	}

	public function testIncrementGivesUpAfterThreeLostRacesRatherThanAnswerZero(): void {
		$this->trackMapper->method('findByIdAndUser')->willReturn($this->makeCounterTrack());
		$this->noTick();
		$this->tickMapper->expects($this->exactly(3))->method('addToValue')->willReturn(0);
		$e = $this->uniqueViolation();
		$this->tickMapper->expects($this->exactly(3))->method('insert')->willThrowException($e);

		$this->expectExceptionObject($e);
		$this->service->increment($this->userId, 2, '2026-10-17', 3);
	}

	public static function wholeNumberProvider(): array {
		return [
			'integer' => [1, true, 1],
			'negative integer' => [-3, true, -3],
			'digits' => ['1', true, 1],
			'negative digits' => [' -3 ', true, -3],
			'leading zeros' => ['007', true, 7],
			'minus zero' => ['-0', true, 0],
			'beyond PHP integers' => ['99999999999999999999', true, PHP_INT_MAX],
			'below PHP integers' => ['-99999999999999999999', true, PHP_INT_MIN],
			'negative, unsigned' => [-3, false, null],
			'negative digits, unsigned' => ['-3', false, null],
			'empty' => ['', true, null],
			'plus sign' => ['+1', true, null],
			'two minus signs' => ['--1', true, null],
			'decimal digits' => ['1.0', true, null],
			'exponent' => ['1e3', true, null],
			'trailing text' => ['5abc', true, null],
			'float' => [1.9, true, null],
			'boolean' => [true, true, null],
			'null' => [null, true, null],
		];
	}

	/**
	 * @dataProvider wholeNumberProvider
	 */
	public function testParseWholeNumber(mixed $value, bool $signed, ?int $expected): void {
		$this->assertSame($expected, TickService::parseWholeNumber($value, $signed));
	}

	public function testIncrementWithoutAReadableDeltaWritesNothing(): void {
		$this->trackMapper->method('findByIdAndUser')->willReturn($this->makeCounterTrack());
		$this->tickMapper->expects($this->never())->method('addToValue');

		$this->expectException(InvalidValueException::class);
		$this->expectExceptionMessage('Delta must be a whole number other than 0');
		$this->service->increment($this->userId, 2, '2026-10-17', null);
	}

	public function testIncrementPassesTheLimitToTheUpdate(): void {
		$this->trackMapper->method('findByIdAndUser')->willReturn($this->makeCounterTrack());
		$this->tickMapper->expects($this->once())->method('addToValue')
			->with($this->userId, 2, '2026-10-17', 3, 2147483647)->willReturn(1);
		$this->tickMapper->method('findByUserTrackDate')->willReturn($this->makeTick(2147483647));

		// The row held 2147483644: the update reached the limit itself
		$this->assertSame(2147483647, $this->service->increment($this->userId, 2, '2026-10-17', 3)['value']);
	}

	public function testIncrementByMoreThanTheLimitWritesNothing(): void {
		$this->trackMapper->method('findByIdAndUser')->willReturn($this->makeCounterTrack());
		$this->tickMapper->expects($this->never())->method('addToValue');

		$this->expectException(InvalidValueException::class);
		$this->expectExceptionMessage('The value would be more than 2147483647');
		$this->service->increment($this->userId, 2, '2026-10-17', 2147483648);
	}

	public function testIncrementOfARowTooLargeToTakeItWritesNothing(): void {
		$this->trackMapper->method('findByIdAndUser')->willReturn($this->makeCounterTrack());
		// The update's bound left the row alone, and the insert met it
		$this->tickMapper->expects($this->once())->method('addToValue')->willReturn(0);
		$this->tickMapper->expects($this->once())->method('insert')->willThrowException($this->uniqueViolation());
		$this->tickMapper->method('findByUserTrackDate')->willReturn($this->makeTick(2147483645));
		$this->tickMapper->expects($this->never())->method('update');
		$this->tickMapper->expects($this->never())->method('delete');

		$this->expectException(InvalidValueException::class);
		$this->expectExceptionMessage('The value would be more than 2147483647');
		$this->service->increment($this->userId, 2, '2026-10-17', 3);
	}

	public function testIncrementRacingAnInsertThatCanStillTakeItAddsToIt(): void {
		$this->trackMapper->method('findByIdAndUser')->willReturn($this->makeCounterTrack());
		$this->tickMapper->expects($this->exactly(2))->method('addToValue')->willReturnOnConsecutiveCalls(0, 1);
		$this->tickMapper->expects($this->once())->method('insert')->willThrowException($this->uniqueViolation());
		$this->tickMapper->method('findByUserTrackDate')->willReturnOnConsecutiveCalls($this->makeTick(2), $this->makeTick(5));

		$this->assertSame(5, $this->service->increment($this->userId, 2, '2026-10-17', 3)['value']);
	}

	public function testDecrementBelowTheLimitCountsAsTheLimitAndDeletesTheTick(): void {
		$this->trackMapper->method('findByIdAndUser')->willReturn($this->makeCounterTrack());
		$this->tickMapper->expects($this->once())->method('addToValue')
			->with($this->userId, 2, '2026-10-17', -2147483647, 2147483647)->willReturn(1);
		$this->tickMapper->expects($this->once())->method('deleteIfNotPositive')->willReturn(1);

		$this->assertSame(0, $this->service->increment($this->userId, 2, '2026-10-17', PHP_INT_MIN)['value']);
	}

	// --- reading a range ---

	public static function invalidRangeProvider(): array {
		return [
			'from missing' => ['', '2026-10-17', 'From and to must be dates as YYYY-MM-DD'],
			'to missing' => ['2026-10-17', '', 'From and to must be dates as YYYY-MM-DD'],
			'not a date' => ['2026-10-17', '2026-02-30', 'From and to must be dates as YYYY-MM-DD'],
			'reversed' => ['2026-11-10', '2026-11-01', 'From must be on or before to'],
		];
	}

	/**
	 * @dataProvider invalidRangeProvider
	 */
	public function testFindByDateRangeRefusesAnInvalidRange(string $from, string $to, string $message): void {
		$this->tickMapper->expects($this->never())->method('findByUserAndDateRange');

		$this->expectException(InvalidValueException::class);
		$this->expectExceptionMessage($message);
		$this->service->findByDateRange($this->userId, $from, $to);
	}

	public function testFindByDateRangeReadsOneDay(): void {
		$this->tickMapper->expects($this->once())->method('findByUserAndDateRange')
			->with($this->userId, '2026-11-01', '2026-11-01')->willReturn([]);

		$this->assertSame([], $this->service->findByDateRange($this->userId, '2026-11-01', '2026-11-01'));
	}

	public function testIncrementOnArchivedCounterAnswersTheStoredValue(): void {
		$this->trackMapper->method('findByIdAndUser')->willReturn($this->archive($this->makeCounterTrack()));
		$this->tickMapper->method('findByUserTrackDate')->willReturn($this->makeTick(3));
		$this->tickMapper->expects($this->never())->method('addToValue');
		$this->expectNoTickWrites();

		$this->assertSame(3, $this->service->increment($this->userId, 2, '2026-10-14', 2)['value']);
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

	// --- notes on tick calls ---

	private function expectNoteWritten(string $date, string $text, string $stored): void {
		$this->noteService->expects($this->once())->method('writeForTick')
			->with($this->userId, $this->anything(), $date, $text)
			->willReturn($stored);
	}

	public function testWithoutNoteNotesAreUntouched(): void {
		$this->trackMapper->method('findByIdAndUser')->willReturnCallback(
			fn (int $id) => $id === 1 ? $this->makeBooleanTrack() : $this->makeCounterTrack()
		);
		$this->noTick();
		$this->tickMapper->method('addToValue')->willReturn(1);
		$this->noteService->expects($this->never())->method($this->anything());

		$this->assertSame(['ticked' => true, 'value' => 1], $this->service->toggle($this->userId, 1, '2026-10-18'));
		$this->assertSame(['value' => 1], $this->service->set($this->userId, 1, '2026-10-18', true));
		$this->assertSame(['value' => 3], $this->service->set($this->userId, 2, '2026-10-18', 3));
		$this->assertArrayNotHasKey('note', $this->service->increment($this->userId, 2, '2026-10-18', 1));
	}

	public function testToggleOnWritesTickThenNote(): void {
		$this->trackMapper->method('findByIdAndUser')->willReturn($this->makeBooleanTrack());
		$this->noTick();
		$order = [];
		$this->tickMapper->expects($this->once())->method('insert')
			->willReturnCallback(function (Tick $t) use (&$order) {
				$order[] = 'tick';
				return $t;
			});
		$this->noteService->expects($this->once())->method('writeForTick')
			->with($this->userId, 1, '2026-10-18', 'backup 03:14')
			->willReturnCallback(function () use (&$order) {
				$order[] = 'note';
				return 'backup 03:14';
			});

		$this->assertSame(
			['ticked' => true, 'value' => 1, 'note' => 'backup 03:14'],
			$this->service->toggle($this->userId, 1, '2026-10-18', ' backup 03:14 '),
		);
		$this->assertSame(['tick', 'note'], $order);
	}

	public function testToggleOffKeepsWritingTheNote(): void {
		$this->trackMapper->method('findByIdAndUser')->willReturn($this->makeBooleanTrack());
		$this->tickMapper->method('findByUserTrackDate')->willReturn($this->makeTick(1));
		$this->tickMapper->expects($this->once())->method('delete');
		$this->expectNoteWritten('2026-10-18', 'backup 03:14', 'backup 03:14');

		$this->assertSame(
			['ticked' => false, 'value' => 0, 'note' => 'backup 03:14'],
			$this->service->toggle($this->userId, 1, '2026-10-18', 'backup 03:14'),
		);
	}

	public function testSetYesNoWithNote(): void {
		$this->trackMapper->method('findByIdAndUser')->willReturn($this->makeBooleanTrack());
		$this->noTick();
		$this->tickMapper->expects($this->once())->method('insert');
		$this->expectNoteWritten('2026-10-18', 'backup 03:14', 'backup 03:14');

		$this->assertSame(
			['value' => 1, 'note' => 'backup 03:14'],
			$this->service->set($this->userId, 1, '2026-10-18', 'true', 'backup 03:14'),
		);
	}

	public function testSetCounterWithNote(): void {
		$this->trackMapper->method('findByIdAndUser')->willReturn($this->makeCounterTrack());
		$this->noTick();
		$this->tickMapper->expects($this->once())->method('insert');
		$this->expectNoteWritten('2026-10-18', 'backup 03:14', 'backup 03:14');

		$this->assertSame(
			['value' => 4, 'note' => 'backup 03:14'],
			$this->service->set($this->userId, 2, '2026-10-18', 4, 'backup 03:14'),
		);
	}

	public function testIncrementWithNote(): void {
		$this->trackMapper->method('findByIdAndUser')->willReturn($this->makeCounterTrack());
		$this->tickMapper->method('addToValue')->willReturn(1);
		$this->tickMapper->method('findByUserTrackDate')->willReturn($this->makeTick(3));
		$this->expectNoteWritten('2026-10-18', 'backup 03:14', 'backup 03:14');

		$this->assertSame(
			['value' => 3, 'note' => 'backup 03:14'],
			$this->service->increment($this->userId, 2, '2026-10-18', 1, 'backup 03:14'),
		);
	}

	public function testEmptyNoteDeletesTheNote(): void {
		$this->trackMapper->method('findByIdAndUser')->willReturn($this->makeCounterTrack());
		$this->noTick();
		$this->expectNoteWritten('2026-10-18', '', '');

		$this->assertSame(
			['value' => 2, 'note' => ''],
			$this->service->set($this->userId, 2, '2026-10-18', 2, ''),
		);
	}

	private function expectNothingWritten(): void {
		$this->expectNoTickWrites();
		$this->tickMapper->expects($this->never())->method('addToValue');
		$this->noteService->expects($this->never())->method('writeForTick');
	}

	public function testTooLongNoteWritesNothingOnToggle(): void {
		$this->trackMapper->method('findByIdAndUser')->willReturn($this->makeBooleanTrack());
		$this->noTick();
		$this->expectNothingWritten();

		$this->expectException(InvalidValueException::class);
		$this->expectExceptionMessage('Notes can be at most 500 characters');
		$this->service->toggle($this->userId, 1, '2026-10-18', str_repeat('a', 501));
	}

	public function testTooLongNoteWritesNothingOnYesNoSet(): void {
		$this->trackMapper->method('findByIdAndUser')->willReturn($this->makeBooleanTrack());
		$this->noTick();
		$this->expectNothingWritten();

		$this->expectException(InvalidValueException::class);
		$this->service->set($this->userId, 1, '2026-10-18', true, str_repeat('a', 501));
	}

	public function testTooLongNoteWritesNothingOnCounterSet(): void {
		$this->trackMapper->method('findByIdAndUser')->willReturn($this->makeCounterTrack());
		$this->noTick();
		$this->expectNothingWritten();

		$this->expectException(InvalidValueException::class);
		$this->service->set($this->userId, 2, '2026-10-18', 3, str_repeat('a', 501));
	}

	public function testTooLongNoteWritesNothingOnIncrement(): void {
		$this->trackMapper->method('findByIdAndUser')->willReturn($this->makeCounterTrack());
		$this->expectNothingWritten();

		$this->expectException(InvalidValueException::class);
		$this->service->increment($this->userId, 2, '2026-10-18', 1, str_repeat('a', 501));
	}

	public function testControlCharactersInNoteWriteNothing(): void {
		$this->trackMapper->method('findByIdAndUser')->willReturn($this->makeBooleanTrack());
		$this->expectNothingWritten();

		$this->expectException(InvalidValueException::class);
		$this->expectExceptionMessage("Notes can't contain control characters");
		$this->service->toggle($this->userId, 1, '2026-10-18', "\x1b[2J");
	}

	public function testInvalidDateIsReportedBeforeAnInvalidNote(): void {
		$this->trackMapper->method('findByIdAndUser')->willReturn($this->makeBooleanTrack());

		$this->expectException(InvalidValueException::class);
		$this->expectExceptionMessage('Date must be a date as YYYY-MM-DD');
		$this->service->toggle($this->userId, 1, '2026-02-30', str_repeat('a', 501));
	}

	public function testArchivedTrackWithNoteAnswersStoredNote(): void {
		$this->trackMapper->method('findByIdAndUser')->willReturnCallback(
			fn (int $id) => $this->archive($id === 1 ? $this->makeBooleanTrack() : $this->makeCounterTrack())
		);
		$this->tickMapper->method('findByUserTrackDate')->willReturn($this->makeTick(2));
		$this->expectNothingWritten();
		$this->noteService->method('stored')->willReturn('kept');

		$this->assertSame(['ticked' => true, 'value' => 1, 'note' => 'kept'], $this->service->toggle($this->userId, 1, '2026-10-18', 'new'));
		$this->assertSame(['value' => 1, 'note' => 'kept'], $this->service->set($this->userId, 1, '2026-10-18', false, 'new'));
		$this->assertSame(['value' => 2, 'note' => 'kept'], $this->service->set($this->userId, 2, '2026-10-18', 5, 'new'));
		$this->assertSame(['value' => 2, 'note' => 'kept'], $this->service->increment($this->userId, 2, '2026-10-18', 1, 'new'));
	}

	// --- appendNote on tick calls ---

	public function testToggleAppendsAfterTheTick(): void {
		$this->trackMapper->method('findByIdAndUser')->willReturn($this->makeBooleanTrack());
		$this->noTick();
		$order = [];
		$this->noteService->expects($this->once())->method('checkAppend')
			->with($this->userId, 1, '2026-10-18', 'https://strava.com/a/2', false)
			->willReturnCallback(function () use (&$order) {
				$order[] = 'check';
			});
		$this->tickMapper->expects($this->once())->method('insert')
			->willReturnCallback(function (Tick $t) use (&$order) {
				$order[] = 'tick';
				return $t;
			});
		$this->noteService->expects($this->once())->method('appendForTick')
			->with($this->userId, 1, '2026-10-18', 'https://strava.com/a/2', false)
			->willReturnCallback(function () use (&$order) {
				$order[] = 'append';
				return "https://strava.com/a/1\nhttps://strava.com/a/2";
			});
		$this->noteService->expects($this->never())->method('writeForTick');

		$this->assertSame(
			['ticked' => true, 'value' => 1, 'note' => "https://strava.com/a/1\nhttps://strava.com/a/2"],
			$this->service->toggle($this->userId, 1, '2026-10-18', null, ' https://strava.com/a/2 '),
		);
		$this->assertSame(['check', 'tick', 'append'], $order);
	}

	public function testSetYesNoAppendsNote(): void {
		$this->trackMapper->method('findByIdAndUser')->willReturn($this->makeBooleanTrack());
		$this->noTick();
		$this->tickMapper->expects($this->once())->method('insert');
		$this->noteService->expects($this->once())->method('appendForTick')
			->with($this->userId, 1, '2026-10-18', 'link', true)
			->willReturn("link\nlink");

		$this->assertSame(
			['value' => 1, 'note' => "link\nlink"],
			$this->service->set($this->userId, 1, '2026-10-18', true, null, 'link', true),
		);
	}

	public function testSetCounterAppendsNote(): void {
		$this->trackMapper->method('findByIdAndUser')->willReturn($this->makeCounterTrack());
		$this->noTick();
		$this->tickMapper->expects($this->once())->method('insert');
		$this->noteService->expects($this->once())->method('appendForTick')
			->with($this->userId, 2, '2026-10-18', 'link', false)
			->willReturn('link');

		$this->assertSame(
			['value' => 4, 'note' => 'link'],
			$this->service->set($this->userId, 2, '2026-10-18', 4, null, 'link'),
		);
	}

	public function testIncrementAppendsNote(): void {
		$this->trackMapper->method('findByIdAndUser')->willReturn($this->makeCounterTrack());
		$this->tickMapper->method('addToValue')->willReturn(1);
		$this->tickMapper->method('findByUserTrackDate')->willReturn($this->makeTick(3));
		$this->noteService->expects($this->once())->method('appendForTick')
			->with($this->userId, 2, '2026-10-18', 'link', false)
			->willReturn("first\nlink");

		$this->assertSame(
			['value' => 3, 'note' => "first\nlink"],
			$this->service->increment($this->userId, 2, '2026-10-18', 1, null, 'link'),
		);
	}

	public function testNoteAndAppendNoteTogetherWriteNothing(): void {
		$this->trackMapper->method('findByIdAndUser')->willReturnCallback(
			fn (int $id) => $id === 1 ? $this->makeBooleanTrack() : $this->makeCounterTrack()
		);
		$this->noTick();
		$this->expectNothingWritten();
		$this->noteService->expects($this->never())->method('appendForTick');

		$calls = [
			fn () => $this->service->toggle($this->userId, 1, '2026-10-18', 'a', 'b'),
			fn () => $this->service->set($this->userId, 1, '2026-10-18', true, 'a', 'b'),
			fn () => $this->service->set($this->userId, 2, '2026-10-18', 3, 'a', 'b'),
			fn () => $this->service->increment($this->userId, 2, '2026-10-18', 1, 'a', 'b'),
		];
		foreach ($calls as $call) {
			try {
				$call();
				$this->fail('Both note and appendNote were accepted');
			} catch (InvalidValueException $e) {
				$this->assertSame('Send note or appendNote, not both', $e->getMessage());
			}
		}
	}

	public function testAppendNoteOverTheLimitWritesNoTick(): void {
		$this->trackMapper->method('findByIdAndUser')->willReturnCallback(
			fn (int $id) => $id === 1 ? $this->makeBooleanTrack() : $this->makeCounterTrack()
		);
		$this->noTick();
		$this->expectNothingWritten();
		$this->noteService->expects($this->never())->method('appendForTick');
		$this->noteService->method('checkAppend')
			->willThrowException(new InvalidValueException('The note would be longer than 500 characters'));

		$calls = [
			fn () => $this->service->toggle($this->userId, 1, '2026-10-18', null, 'link'),
			fn () => $this->service->set($this->userId, 1, '2026-10-18', true, null, 'link'),
			fn () => $this->service->set($this->userId, 2, '2026-10-18', 3, null, 'link'),
			fn () => $this->service->increment($this->userId, 2, '2026-10-18', 1, null, 'link'),
		];
		foreach ($calls as $call) {
			try {
				$call();
				$this->fail('A note over the limit was accepted');
			} catch (InvalidValueException $e) {
				$this->assertSame('The note would be longer than 500 characters', $e->getMessage());
			}
		}
	}

	public function testAppendNoteTextOverTheLimitWritesNothing(): void {
		$this->trackMapper->method('findByIdAndUser')->willReturn($this->makeBooleanTrack());
		$this->expectNothingWritten();

		$this->expectException(InvalidValueException::class);
		$this->expectExceptionMessage('Notes can be at most 500 characters');
		$this->service->toggle($this->userId, 1, '2026-10-18', null, str_repeat('a', 501));
	}

	public function testAppendNoteAtTheLimitIsAccepted(): void {
		$this->trackMapper->method('findByIdAndUser')->willReturn($this->makeBooleanTrack());
		$this->noTick();
		$this->noteService->expects($this->once())->method('appendForTick')
			->with($this->userId, 1, '2026-10-18', str_repeat('a', 500), false)
			->willReturn(str_repeat('a', 500));

		$this->assertSame(str_repeat('a', 500), $this->service->toggle($this->userId, 1, '2026-10-18', null, str_repeat('a', 500))['note']);
	}

	public function testArchivedTrackWithAppendNoteAnswersStoredNote(): void {
		$this->trackMapper->method('findByIdAndUser')->willReturnCallback(
			fn (int $id) => $this->archive($id === 1 ? $this->makeBooleanTrack() : $this->makeCounterTrack())
		);
		$this->tickMapper->method('findByUserTrackDate')->willReturn($this->makeTick(2));
		$this->expectNothingWritten();
		$this->noteService->expects($this->never())->method('appendForTick');
		$this->noteService->expects($this->never())->method('checkAppend');
		$this->noteService->method('stored')->willReturn('kept');

		$this->assertSame(['ticked' => true, 'value' => 1, 'note' => 'kept'], $this->service->toggle($this->userId, 1, '2026-10-18', null, 'new'));
		$this->assertSame(['value' => 1, 'note' => 'kept'], $this->service->set($this->userId, 1, '2026-10-18', false, null, 'new'));
		$this->assertSame(['value' => 2, 'note' => 'kept'], $this->service->set($this->userId, 2, '2026-10-18', 5, null, 'new'));
		$this->assertSame(['value' => 2, 'note' => 'kept'], $this->service->increment($this->userId, 2, '2026-10-18', 1, null, 'new'));
	}

	public function testAppendConflictAfterTheTickIsPassedOn(): void {
		$this->trackMapper->method('findByIdAndUser')->willReturn($this->makeCounterTrack());
		$this->tickMapper->method('addToValue')->willReturn(1);
		$this->tickMapper->method('findByUserTrackDate')->willReturn($this->makeTick(3));
		$this->noteService->method('appendForTick')
			->willThrowException(new NoteConflictException('The note changed while appending; try again'));

		$this->expectException(NoteConflictException::class);
		$this->service->increment($this->userId, 2, '2026-10-18', 1, null, 'link');
	}

	// --- strict handling (Prefer: handling=strict) ---

	/** Every write to an archived track is refused, with nothing written. */
	private function expectRefused(callable $call): void {
		try {
			$call();
			$this->fail('Expected ReadOnlyTrackException');
		} catch (ReadOnlyTrackException $e) {
			$this->assertSame('archived', $e->getReason());
		}
	}

	private function givenArchivedTracks(): void {
		$this->trackMapper->method('findByIdAndUser')->willReturnCallback(
			fn (int $id) => $this->archive($id === 1 ? $this->makeBooleanTrack() : $this->makeCounterTrack())
		);
		$this->tickMapper->method('findByUserTrackDate')->willReturn($this->makeTick(2));
	}

	private function expectNoNoteWrites(): void {
		$this->noteService->expects($this->never())->method('writeForTick');
		$this->noteService->expects($this->never())->method('appendForTick');
		$this->noteService->expects($this->never())->method('checkAppend');
	}

	public function testStrictRefusesEveryTickWriteOnAnArchivedTrack(): void {
		$this->strict();
		$this->givenArchivedTracks();
		$this->expectNothingWritten();
		$this->expectNoNoteWrites();

		$this->expectRefused(fn () => $this->service->toggle($this->userId, 1, '2026-10-18'));
		$this->expectRefused(fn () => $this->service->set($this->userId, 1, '2026-10-18', true));
		$this->expectRefused(fn () => $this->service->set($this->userId, 2, '2026-10-18', 5));
		$this->expectRefused(fn () => $this->service->increment($this->userId, 2, '2026-10-18', 1));
	}

	public function testStrictRefusesAWriteThatWouldChangeNothing(): void {
		$this->strict();
		$this->givenArchivedTracks();
		$this->expectNothingWritten();

		// The stored value is already 2
		$this->expectRefused(fn () => $this->service->set($this->userId, 2, '2026-10-18', 2));
	}

	public function testStrictRefusesTickWritesWithANote(): void {
		$this->strict();
		$this->givenArchivedTracks();
		$this->expectNothingWritten();
		$this->expectNoNoteWrites();

		$this->expectRefused(fn () => $this->service->toggle($this->userId, 1, '2026-10-18', 'new'));
		$this->expectRefused(fn () => $this->service->set($this->userId, 1, '2026-10-18', false, 'new'));
		$this->expectRefused(fn () => $this->service->set($this->userId, 2, '2026-10-18', 5, 'new'));
		$this->expectRefused(fn () => $this->service->increment($this->userId, 2, '2026-10-18', 1, 'new'));
	}

	public function testStrictRefusesTickWritesWithAnAppendNote(): void {
		$this->strict();
		$this->givenArchivedTracks();
		$this->expectNothingWritten();
		$this->expectNoNoteWrites();

		$this->expectRefused(fn () => $this->service->toggle($this->userId, 1, '2026-10-18', null, 'new'));
		$this->expectRefused(fn () => $this->service->set($this->userId, 1, '2026-10-18', false, null, 'new'));
		$this->expectRefused(fn () => $this->service->set($this->userId, 2, '2026-10-18', 5, null, 'new'));
		$this->expectRefused(fn () => $this->service->increment($this->userId, 2, '2026-10-18', 1, null, 'new'));
	}

	public static function checksBeforeTheRefusal(): array {
		return [
			'toggle on a counter' => [fn (TickService $s) => $s->toggle('testuser', 2, '2026-10-18'), InvalidTrackTypeException::class],
			'increment on a yes/no track' => [fn (TickService $s) => $s->increment('testuser', 1, '2026-10-18', 1), InvalidTrackTypeException::class],
			'yes/no set to maybe' => [fn (TickService $s) => $s->set('testuser', 1, '2026-10-18', 'maybe'), InvalidValueException::class],
			'delta 0' => [fn (TickService $s) => $s->increment('testuser', 2, '2026-10-18', 0), InvalidValueException::class],
			'bad date' => [fn (TickService $s) => $s->toggle('testuser', 1, '17 Oct'), InvalidValueException::class],
			'invalid note' => [fn (TickService $s) => $s->toggle('testuser', 1, '2026-10-18', "a\x00b"), InvalidValueException::class],
			'note and appendNote' => [fn (TickService $s) => $s->set('testuser', 2, '2026-10-18', 1, 'a', 'b'), InvalidValueException::class],
		];
	}

	/**
	 * @dataProvider checksBeforeTheRefusal
	 */
	public function testStrictStillReportsBadRequestsFirst(callable $call, string $exception): void {
		$this->strict();
		$this->givenArchivedTracks();
		$this->expectNothingWritten();

		$this->expectException($exception);
		$call($this->service);
	}

	public function testStrictLeavesActiveTracksAlone(): void {
		$this->strict();
		$this->trackMapper->method('findByIdAndUser')->willReturn($this->makeBooleanTrack());
		$this->noTick();
		$this->tickMapper->expects($this->once())->method('insert');

		$this->assertSame(1, $this->service->toggle($this->userId, 1, '2026-10-18')['value']);
	}
}
