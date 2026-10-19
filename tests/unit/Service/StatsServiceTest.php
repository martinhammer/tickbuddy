<?php

declare(strict_types=1);

namespace Service;

use OCA\Tickbuddy\Db\TickMapper;
use OCA\Tickbuddy\Db\Track;
use OCA\Tickbuddy\Service\InvalidValueException;
use OCA\Tickbuddy\Service\StatsService;
use OCA\Tickbuddy\Service\TrackService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;

final class StatsServiceTest extends TestCase {
	// The reference cases from the dashboard widget spec (§4.4), ported one
	// for one from src/streaks.test.ts. Today is Thursday 2026-10-08 throughout.
	private const TODAY = '2026-10-08';

	private TickMapper $tickMapper;
	private TrackService $trackService;
	private StatsService $service;
	private string $userId = 'testuser';

	protected function setUp(): void {
		$this->tickMapper = $this->createMock(TickMapper::class);
		$this->trackService = $this->createMock(TrackService::class);
		$timeFactory = $this->createMock(ITimeFactory::class);
		$timeFactory->method('getDateTime')->willReturn(new \DateTime('2026-10-15 01:30:00'));
		$this->service = new StatsService($this->tickMapper, $this->trackService, $timeFactory);
	}

	/** @return list<string> */
	private static function range(string $from, string $to): array {
		$out = [];
		for ($d = new \DateTimeImmutable($from, new \DateTimeZone('UTC')); $d->format('Y-m-d') <= $to; $d = $d->modify('+1 day')) {
			$out[] = $d->format('Y-m-d');
		}
		return $out;
	}

	private static function makeRun(bool $isStreak, string $from, string $to, int $length): array {
		return ['isStreak' => $isStreak, 'length' => $length, 'from' => $from, 'to' => $to];
	}

	private const NO_RUNS = ['runs' => [], 'current' => null, 'upToYesterday' => false, 'longestStreak' => null, 'longestBreak' => null];

	// --- summariseStreaks: the 12 cases of src/streaks.test.ts ---

	public function test1TodayTickedExtendsTheStreak(): void {
		$s = StatsService::summariseStreaks(self::range('2026-10-05', '2026-10-08'), self::TODAY);
		$this->assertSame(self::makeRun(true, '2026-10-05', '2026-10-08', 4), $s['current']);
		$this->assertFalse($s['upToYesterday']);
		$this->assertCount(1, $s['runs']);
	}

	public function test2OpenTodayAfterATickedYesterdayCountsUpToYesterday(): void {
		$s = StatsService::summariseStreaks(self::range('2026-10-04', '2026-10-07'), self::TODAY);
		$this->assertSame(self::makeRun(true, '2026-10-04', '2026-10-07', 4), $s['current']);
		$this->assertTrue($s['upToYesterday']);
		$this->assertSame([$s['current']], $s['runs']); // no break run for today
		$this->assertNull($s['longestBreak']);
	}

	public function test3OpenTodayAfterAnUntickedYesterdayJoinsTheBreak(): void {
		$s = StatsService::summariseStreaks(['2026-10-01', '2026-10-04'], self::TODAY);
		$this->assertSame(self::makeRun(false, '2026-10-05', '2026-10-08', 4), $s['current']);
		$this->assertFalse($s['upToYesterday']);
		$this->assertSame([
			self::makeRun(true, '2026-10-01', '2026-10-01', 1),
			self::makeRun(false, '2026-10-02', '2026-10-03', 2),
			self::makeRun(true, '2026-10-04', '2026-10-04', 1),
			self::makeRun(false, '2026-10-05', '2026-10-08', 4),
		], $s['runs']);
		$this->assertSame($s['current'], $s['longestBreak']);
		$this->assertSame($s['runs'][0], $s['longestStreak']); // first of equal runs wins
	}

	public function test4OnlyYesterdayTickedIsADayStreakUpToYesterday(): void {
		$s = StatsService::summariseStreaks(['2026-10-07'], self::TODAY);
		$this->assertSame(self::makeRun(true, '2026-10-07', '2026-10-07', 1), $s['current']);
		$this->assertTrue($s['upToYesterday']);
	}

	public function test5OnlyTodayTickedIsADayStreak(): void {
		$s = StatsService::summariseStreaks(['2026-10-08'], self::TODAY);
		$this->assertSame(self::makeRun(true, '2026-10-08', '2026-10-08', 1), $s['current']);
		$this->assertFalse($s['upToYesterday']);
	}

	public function test6NoTicksHasNoRuns(): void {
		$this->assertSame(self::NO_RUNS, StatsService::summariseStreaks([], self::TODAY));
	}

	public function test7FutureTicksAreIgnored(): void {
		$this->assertSame(self::NO_RUNS, StatsService::summariseStreaks(['2026-10-10'], self::TODAY));
		// ...also next to past ones.
		$s = StatsService::summariseStreaks(['2026-10-08', '2026-10-09', '2026-10-10'], self::TODAY);
		$this->assertSame(self::makeRun(true, '2026-10-08', '2026-10-08', 1), $s['current']);
	}

	public function test8TickedEveryDayUpToYesterdayHasNoBreakAtAll(): void {
		$s = StatsService::summariseStreaks(self::range('2026-10-01', '2026-10-07'), self::TODAY);
		$this->assertSame(self::makeRun(true, '2026-10-01', '2026-10-07', 7), $s['current']);
		$this->assertTrue($s['upToYesterday']);
		$this->assertSame($s['current'], $s['longestStreak']);
		$this->assertNull($s['longestBreak']);
	}

	public function test9StreakBreakStreakUpToYesterday(): void {
		$s = StatsService::summariseStreaks(['2026-10-01', '2026-10-02', '2026-10-06', '2026-10-07'], self::TODAY);
		$this->assertSame([
			self::makeRun(true, '2026-10-01', '2026-10-02', 2),
			self::makeRun(false, '2026-10-03', '2026-10-05', 3),
			self::makeRun(true, '2026-10-06', '2026-10-07', 2),
		], $s['runs']);
		$this->assertSame($s['runs'][2], $s['current']);
		$this->assertTrue($s['upToYesterday']);
		$this->assertSame($s['runs'][0], $s['longestStreak']);
		$this->assertSame(3, $s['longestBreak']['length'] ?? null);
	}

	public function test10RunsAcrossTheEuDstChangeStayWholeDays(): void {
		$zone = date_default_timezone_get();
		date_default_timezone_set('Europe/Berlin');
		try {
			$s = StatsService::summariseStreaks(self::range('2026-03-27', '2026-03-31'), self::TODAY);
			$this->assertSame(self::makeRun(true, '2026-03-27', '2026-03-31', 5), $s['runs'][0]);
			$this->assertSame(self::makeRun(false, '2026-04-01', '2026-10-08', 191), $s['current']);
			$this->assertFalse($s['upToYesterday']);
		} finally {
			date_default_timezone_set($zone);
		}
	}

	public function testGivesTheSameResultForUnsortedAndDuplicatedInput(): void {
		$dates = ['2026-10-01', '2026-10-02', '2026-10-06', '2026-10-07'];
		$shuffled = ['2026-10-07', '2026-10-01', '2026-10-06', '2026-10-07', '2026-10-02', '2026-10-01'];
		$this->assertSame(StatsService::summariseStreaks($dates, self::TODAY), StatsService::summariseStreaks($shuffled, self::TODAY));
	}

	public function testHandlesTenYearsOfDailyTicks(): void {
		$dates = self::range('2016-10-09', self::TODAY);
		$s = StatsService::summariseStreaks($dates, self::TODAY);
		$this->assertCount(1, $s['runs']);
		$this->assertSame(count($dates), $s['current']['length'] ?? null);
		$this->assertSame(3652, $s['current']['length'] ?? null); // 3650 days plus 29 Feb 2020 and 2024
	}

	// --- summarise ---

	/** @param list<string> $dates */
	private static function daily(array $dates, int $value = 1): array {
		return array_fill_keys($dates, $value);
	}

	public function testCounterStats(): void {
		$values = [
			'2026-09-20' => 4, // first tick, 18 days to E counting both
			'2026-09-21' => 2,
			'2026-09-25' => 9,
			'2026-09-27' => 1, // previous7: 09-25 .. 10-01
			'2026-10-01' => 3,
			'2026-10-03' => 5, // last7: 10-02 .. 10-08
			'2026-10-07' => 2,
		];
		$s = StatsService::summarise($values, self::TODAY, false);

		$this->assertSame(26, $s['total']);
		$this->assertEqualsWithDelta(26 / (19 / 7), $s['weeklyMean'], 1e-9);
		$this->assertSame(7, $s['last7']);
		$this->assertSame(13, $s['previous7']);
		// Window ending 09-26: 09-20 .. 09-26 = 4 + 2 + 9
		$this->assertSame(15, $s['peak7']);
		$this->assertSame('2026-09-20', $s['firstTick']);
		$this->assertSame('2026-10-07', $s['lastTick']);
		$this->assertSame(9, $s['maxValue']);
		$this->assertSame(0, $s['minValue']); // days were missed
		$this->assertTrue($s['upToYesterday']);
		$this->assertArrayNotHasKey('runs', $s);
	}

	public function testPeak7CountsAWindowStartingBeforeTheFirstTick(): void {
		$s = StatsService::summarise(['2026-10-01' => 6], self::TODAY, false);
		$this->assertSame(6, $s['peak7']);
	}

	public function testMinValueWithoutAMissedDayIsTheLowestValue(): void {
		$s = StatsService::summarise(['2026-10-06' => 3, '2026-10-07' => 2, '2026-10-08' => 5], self::TODAY, false);
		$this->assertSame(2, $s['minValue']);
		$this->assertSame(5, $s['maxValue']);
	}

	public function testWeeklyMeanOfASingleTickTodayIsTheTotal(): void {
		$s = StatsService::summarise([self::TODAY => 3], self::TODAY, false);
		$this->assertEqualsWithDelta(3.0, $s['weeklyMean'], 1e-9);
	}

	public function testWeeklyMeanOfDailyTicksIsSeven(): void {
		// Martin's screenshots: 8 and 14 daily ticks up to today read 8.0 and 7.5 before
		$eight = StatsService::summarise(self::daily(self::range('2026-10-01', self::TODAY)), self::TODAY, false);
		$this->assertEqualsWithDelta(7.0, $eight['weeklyMean'], 1e-9);
		$fourteen = StatsService::summarise(self::daily(self::range('2026-09-25', self::TODAY)), self::TODAY, false);
		$this->assertEqualsWithDelta(7.0, $fourteen['weeklyMean'], 1e-9);
	}

	public function testWeeklyMeanRunsToTheEndDay(): void {
		// 14 daily ticks, then 7 open days up to E: 14 / (21 / 7)
		$s = StatsService::summarise(self::daily(self::range('2026-09-18', '2026-10-01')), self::TODAY, false);
		$this->assertEqualsWithDelta(14 / 3, $s['weeklyMean'], 1e-9);
	}

	public function testYesNoMinAndMax(): void {
		$mixed = StatsService::summarise(self::daily(['2026-10-01', '2026-10-05']), self::TODAY, false);
		$this->assertSame([1, 0], [$mixed['maxValue'], $mixed['minValue']]);

		$every = StatsService::summarise(self::daily(self::range('2026-10-01', self::TODAY)), self::TODAY, false);
		$this->assertSame([1, 1], [$every['maxValue'], $every['minValue']]);

		$none = StatsService::summarise([], self::TODAY, false);
		$this->assertSame([0, 0], [$none['maxValue'], $none['minValue']]);
	}

	public function testNoTicks(): void {
		$this->assertSame([
			'total' => 0,
			'weeklyMean' => 0.0,
			'last7' => 0,
			'previous7' => 0,
			'peak7' => 0,
			'current' => null,
			'upToYesterday' => false,
			'longestStreak' => null,
			'longestBreak' => null,
			'firstTick' => null,
			'lastTick' => null,
			'maxValue' => 0,
			'minValue' => 0,
			'runs' => [],
		], StatsService::summarise([], self::TODAY, true));
	}

	public function testAFutureTickChangesNothing(): void {
		$values = ['2026-09-20' => 4, '2026-10-01' => 3, '2026-10-07' => 2];
		$withFuture = $values + ['2026-10-12' => 50];
		$this->assertSame(
			StatsService::summarise($values, self::TODAY, true),
			StatsService::summarise($withFuture, self::TODAY, true),
		);
	}

	public function testRunsOnlyWithIncludeRuns(): void {
		$s = StatsService::summarise(self::daily(['2026-10-01', '2026-10-04']), self::TODAY, true);
		$this->assertCount(4, $s['runs']);
		$this->assertSame($s['current'], $s['runs'][3]);
	}

	// --- forUser ---

	private function makeTrack(int $id, ?string $archivedAt = null): Track {
		$track = new Track();
		$track->setId($id);
		$track->setUserId($this->userId);
		$track->setType('boolean');
		$track->setArchivedAt($archivedAt);
		return $track;
	}

	public function testEveryTrackGetsAnEntryInTrackOrder(): void {
		$this->trackService->method('findAll')->with($this->userId, false)
			->willReturn([$this->makeTrack(4), $this->makeTrack(2)]);
		$this->tickMapper->method('findValuesByUser')->with($this->userId, self::TODAY, null)
			->willReturn([2 => ['2026-10-08' => 1]]);

		$stats = $this->service->forUser($this->userId, self::TODAY, null, false, false);

		$this->assertSame([4, 2], array_column($stats, 'trackId'));
		$this->assertSame(0, $stats[0]['total']);
		$this->assertNull($stats[0]['current']);
		$this->assertNull($stats[0]['firstTick']);
		$this->assertSame(1, $stats[1]['total']);
		$this->assertArrayNotHasKey('runs', $stats[1]);
	}

	public function testIncludeArchivedIsPassedOn(): void {
		$this->trackService->expects($this->once())->method('findAll')->with($this->userId, true)
			->willReturn([$this->makeTrack(1), $this->makeTrack(2, '2026-08-14')]);
		$this->tickMapper->method('findValuesByUser')->willReturn([]);

		$stats = $this->service->forUser($this->userId, self::TODAY, null, true, true);

		$this->assertSame([1, 2], array_column($stats, 'trackId'));
		$this->assertSame([], $stats[1]['runs']);
	}

	public function testTrackIdOfAnArchivedTrackIsReturned(): void {
		$this->trackService->expects($this->never())->method('findAll');
		$this->trackService->method('find')->with(7, $this->userId)->willReturn($this->makeTrack(7, '2026-08-14'));
		$this->tickMapper->method('findValuesByUser')->with($this->userId, self::TODAY, 7)->willReturn([]);

		$stats = $this->service->forUser($this->userId, self::TODAY, 7, false, false);

		$this->assertSame([7], array_column($stats, 'trackId'));
	}

	public function testTrackIdNotFoundThrows(): void {
		$this->trackService->method('find')->willThrowException(new DoesNotExistException(''));

		$this->expectException(DoesNotExistException::class);
		$this->service->forUser($this->userId, self::TODAY, 99, false, false);
	}

	public function testEmptyTodayUsesTheServerDate(): void {
		$this->trackService->method('findAll')->willReturn([$this->makeTrack(1)]);
		$this->tickMapper->expects($this->once())->method('findValuesByUser')
			->with($this->userId, '2026-10-15', null)
			->willReturn([1 => ['2026-10-15' => 1]]);

		$stats = $this->service->forUser($this->userId, '', null, false, false);

		$this->assertSame('2026-10-15', $stats[0]['current']['to'] ?? null);
	}

	public static function invalidTodayProvider(): array {
		return [['2026-02-30'], ['17 Oct']];
	}

	/**
	 * @dataProvider invalidTodayProvider
	 */
	public function testInvalidTodayThrows(string $today): void {
		$this->tickMapper->expects($this->never())->method('findValuesByUser');

		$this->expectException(InvalidValueException::class);
		$this->expectExceptionMessage('Today must be a date as YYYY-MM-DD');
		$this->service->forUser($this->userId, $today, null, false, false);
	}

	public function testArchivedTrackEndsOnItsArchiveDate(): void {
		$this->trackService->method('find')->willReturn($this->makeTrack(3, '2026-08-14'));
		$ticks = self::daily(self::range('2026-08-01', '2026-08-13'));
		$ticks['2026-09-01'] = 1; // after the archive date: ignored
		$this->tickMapper->method('findValuesByUser')->with($this->userId, '2026-10-17', 3)->willReturn([3 => $ticks]);

		$s = $this->service->forUser($this->userId, '2026-10-17', 3, false, false)[0];

		$this->assertSame(13, $s['total']);
		$this->assertSame(self::makeRun(true, '2026-08-01', '2026-08-13', 13), $s['current']);
		$this->assertTrue($s['upToYesterday']); // relative to the archive day
		$this->assertSame('2026-08-13', $s['lastTick']);
		$this->assertSame(6, $s['last7']); // 08-08 .. 08-14
		$this->assertEqualsWithDelta(13 / 2, $s['weeklyMean'], 1e-9); // 14 days
	}
	// --- a period (from, to) ---

	public function testAStreakCrossingFromIsCutAtFrom(): void {
		$ticks = self::daily(self::range('2026-02-20', '2026-03-05'));
		$s = StatsService::summarise($ticks, '2026-03-10', true, '2026-03-01');
		$this->assertSame(5, $s['total']);
		$this->assertSame('2026-03-01', $s['firstTick']);
		$this->assertSame(self::makeRun(true, '2026-03-01', '2026-03-05', 5), $s['runs'][0]);
		$this->assertSame(self::makeRun(false, '2026-03-06', '2026-03-10', 5), $s['current']);
	}

	public function testAStreakCrossingToIsCutAtTo(): void {
		$this->trackService->method('find')->willReturn($this->makeTrack(1));
		$this->tickMapper->method('findValuesByUser')->with($this->userId, '2026-03-31', 1, '2026-03-01')
			->willReturn([1 => self::daily(self::range('2026-03-25', '2026-04-05'))]);

		$s = $this->service->forUser($this->userId, self::TODAY, 1, true, false, '2026-03-01', '2026-03-31')[0];

		$this->assertSame(7, $s['total']);
		$this->assertSame('2026-03-31', $s['lastTick']);
		$this->assertSame(self::makeRun(true, '2026-03-25', '2026-03-31', 7), $s['current']);
		$this->assertFalse($s['upToYesterday']);
	}

	public function testWeeklyMeanCoversTheWholePeriod(): void {
		// Four ticks, all in the period's last 9 days: 4 / 4 weeks, not 4 / (9 / 7)
		$ticks = self::daily(['2026-03-20', '2026-03-23', '2026-03-25', '2026-03-28']);
		$s = StatsService::summarise($ticks, '2026-03-28', false, '2026-03-01');
		$this->assertEqualsWithDelta(1.0, $s['weeklyMean'], 1e-9);
	}

	public function testUntickedDaysAtThePeriodStartAreABreak(): void {
		$s = StatsService::summarise(self::daily(['2026-03-10']), '2026-03-10', true, '2026-03-01');
		$this->assertSame([
			self::makeRun(false, '2026-03-01', '2026-03-09', 9),
			self::makeRun(true, '2026-03-10', '2026-03-10', 1),
		], $s['runs']);
		$this->assertSame(0, $s['minValue']);
	}

	public function testAPeriodWithoutTicksIsOneBreak(): void {
		$s = StatsService::summarise([], '2025-12-31', true, '2025-01-01');
		$break = self::makeRun(false, '2025-01-01', '2025-12-31', 365);
		$this->assertSame([$break], $s['runs']);
		$this->assertSame($break, $s['current']);
		$this->assertSame($break, $s['longestBreak']);
		$this->assertNull($s['longestStreak']);
		$this->assertSame(0, $s['total']);
		$this->assertEqualsWithDelta(0.0, $s['weeklyMean'], 1e-9);
		$this->assertNull($s['firstTick']);
	}

	public function testToAfterTodayStopsAtToday(): void {
		$this->trackService->method('find')->willReturn($this->makeTrack(1));
		$this->tickMapper->expects($this->once())->method('findValuesByUser')
			->with($this->userId, self::TODAY, 1, '2026-10-01')
			->willReturn([1 => ['2026-10-07' => 1, '2026-10-12' => 1]]); // a tick after today

		$s = $this->service->forUser($this->userId, self::TODAY, 1, true, false, '2026-10-01', '2026-12-31')[0];

		$this->assertSame(1, $s['total']);
		$this->assertSame('2026-10-07', $s['lastTick']);
		$this->assertSame(self::makeRun(true, '2026-10-07', '2026-10-07', 1), $s['current']);
		$this->assertTrue($s['upToYesterday']);
		$this->assertEqualsWithDelta(7 / 8, $s['weeklyMean'], 1e-9); // 8 days
	}

	public function testCurrentIsAsOfTheEndOfAPastPeriod(): void {
		$ticks = self::daily(self::range('2025-12-20', '2025-12-30'));
		$s = StatsService::summarise($ticks, '2025-12-31', false, '2025-01-01');
		$this->assertSame(self::makeRun(true, '2025-12-20', '2025-12-30', 11), $s['current']);
		$this->assertTrue($s['upToYesterday']);
		$this->assertSame(self::makeRun(false, '2025-01-01', '2025-12-19', 353), $s['longestBreak']);
	}

	public function testATrackArchivedBeforeFromGivesTheEmptyAnswer(): void {
		$this->trackService->method('find')->willReturn($this->makeTrack(3, '2025-06-30'));
		$this->tickMapper->method('findValuesByUser')->willReturn([]);

		$s = $this->service->forUser($this->userId, self::TODAY, 3, true, false, '2026-01-01', '2026-12-31')[0];

		$this->assertSame(['trackId' => 3] + StatsService::summarise([], self::TODAY, true), $s);
	}

	public function testATrackArchivedInsideThePeriodStopsOnItsArchiveDate(): void {
		$this->trackService->method('find')->willReturn($this->makeTrack(3, '2026-03-14'));
		$this->tickMapper->method('findValuesByUser')
			->willReturn([3 => self::daily(['2026-03-10', '2026-03-12', '2026-03-20'])]);

		$s = $this->service->forUser($this->userId, self::TODAY, 3, true, false, '2026-03-01', '2026-03-31')[0];

		$this->assertSame(2, $s['total']);
		$this->assertSame('2026-03-14', end($s['runs'])['to'] ?? null);
		$this->assertEqualsWithDelta(1.0, $s['weeklyMean'], 1e-9); // 2 / (14 / 7)
	}

	public function testSevenDaySumsAreRelativeToTheEndDayAndInsideThePeriod(): void {
		$values = [
			'2026-02-27' => 50, // before the period: ignored
			'2026-03-01' => 2,  // previous7: 03-01 .. 03-07 (only those inside)
			'2026-03-05' => 3,
			'2026-03-09' => 4,  // last7: 03-08 .. 03-14
			'2026-03-14' => 1,
			'2026-03-20' => 99, // after to: ignored
		];
		$s = StatsService::summarise($values, '2026-03-14', false, '2026-03-01');
		$this->assertSame(5, $s['last7']);
		$this->assertSame(5, $s['previous7']);
		$this->assertSame(7, $s['peak7']); // 03-05 .. 03-11
		$this->assertSame(10, $s['total']);
		$this->assertSame(4, $s['maxValue']);
	}

	public static function invalidPeriodProvider(): array {
		return [['2026-02-30', ''], ['', '17 Oct'], ['2026-1-1', '2026-12-31']];
	}

	/**
	 * @dataProvider invalidPeriodProvider
	 */
	public function testInvalidFromOrToThrows(string $from, string $to): void {
		$this->tickMapper->expects($this->never())->method('findValuesByUser');

		$this->expectException(InvalidValueException::class);
		$this->expectExceptionMessage('From and to must be dates as YYYY-MM-DD');
		$this->service->forUser($this->userId, self::TODAY, null, false, false, $from, $to);
	}

	public function testFromAfterToThrows(): void {
		$this->tickMapper->expects($this->never())->method('findValuesByUser');

		$this->expectException(InvalidValueException::class);
		$this->expectExceptionMessage('From must be on or before to');
		$this->service->forUser($this->userId, self::TODAY, null, false, false, '2026-03-02', '2026-03-01');
	}
}
