<?php

declare(strict_types=1);

namespace OCA\Tickbuddy\Service;

use OCA\Tickbuddy\Db\TickMapper;
use OCA\Tickbuddy\Db\Track;
use OCA\Tickbuddy\ResponseDefinitions;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Per-track stats and streaks, worked out on the server so every client shows
 * the same numbers without downloading whole histories.
 *
 * Stats cover all time by default, or a period (from, to): then only the
 * period's days take part, every one of them from its first day (unticked
 * days at the start are a break), and runs crossing either edge are cut there.
 *
 * Dates are handled as day numbers (days since 1970-01-01, written back with
 * gmdate), so a DST change can never make a day 23 or 25 hours long.
 *
 * @psalm-import-type TickbuddyRun from ResponseDefinitions
 * @psalm-import-type TickbuddyStats from ResponseDefinitions
 */
class StatsService {
	private const SECONDS_PER_DAY = 86400;
	private const NO_RUNS = ['runs' => [], 'current' => null, 'upToYesterday' => false, 'longestStreak' => null, 'longestBreak' => null];

	public function __construct(
		private TickMapper $tickMapper,
		private TrackService $trackService,
		private ITimeFactory $timeFactory,
	) {
	}

	/**
	 * Stats for every track of the user (in track list order), or for one.
	 *
	 * Each track is worked out up to its end day: the earliest of $to, $today
	 * and, for an archived track, its archive date, so it reads as it stood the
	 * day it was archived. Ticks after the end day are ignored. With $from,
	 * every day from $from takes part; without, days count from the first tick.
	 *
	 * @param string $today The client's date as YYYY-MM-DD; the server's date if empty
	 * @param ?int $trackId One track only (returned even if archived), or null for all
	 * @param bool $includeArchived With all tracks, include archived ones
	 * @param string $from The period's first day as YYYY-MM-DD; none if empty
	 * @param string $to The period's last day as YYYY-MM-DD; none if empty
	 * @return list<TickbuddyStats>
	 * @throws InvalidValueException if $today, $from or $to is not a date, or $from is after $to
	 * @throws DoesNotExistException if $trackId is not the user's
	 */
	public function forUser(string $userId, string $today, ?int $trackId, bool $includeRuns, bool $includeArchived, string $from = '', string $to = ''): array {
		if ($today === '') {
			$today = $this->timeFactory->getDateTime()->format('Y-m-d');
		} elseif (!TrackService::isValidDate($today)) {
			throw new InvalidValueException('Today must be a date as YYYY-MM-DD');
		}
		if (($from !== '' && !TrackService::isValidDate($from)) || ($to !== '' && !TrackService::isValidDate($to))) {
			throw new InvalidValueException('From and to must be dates as YYYY-MM-DD');
		}
		if ($from !== '' && $to !== '' && $from > $to) {
			throw new InvalidValueException('From must be on or before to');
		}

		$start = $from !== '' ? $from : null;
		$last = $to !== '' && $to < $today ? $to : $today;
		$tracks = $trackId !== null
			? [$this->trackService->find($trackId, $userId)]
			: $this->trackService->findAll($userId, $includeArchived);
		$values = $this->tickMapper->findValuesByUser($userId, $last, $trackId, $start);

		return array_values(array_map(function (Track $track) use ($values, $last, $start, $includeRuns): array {
			$archivedAt = $track->getArchivedAt();
			$end = $archivedAt !== null && $archivedAt < $last ? $archivedAt : $last;
			return ['trackId' => $track->getId()] + self::summarise($values[$track->getId()] ?? [], $end, $includeRuns, $start);
		}, $tracks));
	}

	/**
	 * One track's stats from its date => value map, from $start (or the first
	 * tick) up to and including $end. $end before $start gives the empty answer.
	 *
	 * @param array<string, int> $values Every tick of the track (any dates, any order)
	 * @param string $end The period's last day, YYYY-MM-DD; for all-time stats, today
	 * @param ?string $start The period's first day, YYYY-MM-DD, from which every day
	 *                       takes part; null to count from the first tick
	 * @return array{
	 *     total: int,
	 *     weeklyMean: float,
	 *     last7: int,
	 *     previous7: int,
	 *     peak7: int,
	 *     current: ?TickbuddyRun,
	 *     upToYesterday: bool,
	 *     longestStreak: ?TickbuddyRun,
	 *     longestBreak: ?TickbuddyRun,
	 *     firstTick: ?string,
	 *     lastTick: ?string,
	 *     maxValue: int,
	 *     minValue: int,
	 *     runs?: list<TickbuddyRun>,
	 * }
	 */
	public static function summarise(array $values, string $end, bool $includeRuns, ?string $start = null): array {
		$endDay = self::requireDayNumber($end);
		$startDay = $start !== null ? self::requireDayNumber($start) : null;
		/** @var array<int, int> $byDay */
		$byDay = [];
		foreach ($values as $date => $value) {
			$day = self::dayNumber($date);
			if ($day !== null && $day <= $endDay && ($startDay === null || $day >= $startDay)) {
				$byDay[$day] = $value;
			}
		}

		$streaks = self::runsFromDays($byDay, $endDay, $startDay);
		$stats = [
			'total' => 0,
			'weeklyMean' => 0.0,
			'last7' => 0,
			'previous7' => 0,
			'peak7' => 0,
			'current' => $streaks['current'],
			'upToYesterday' => $streaks['upToYesterday'],
			'longestStreak' => $streaks['longestStreak'],
			'longestBreak' => $streaks['longestBreak'],
			'firstTick' => null,
			'lastTick' => null,
			'maxValue' => 0,
			'minValue' => 0,
		];

		if ($byDay !== []) {
			$first = min(array_keys($byDay));
			$last = max(array_keys($byDay));
			$total = array_sum($byDay);

			// One pass over every day from the period's first day (else the
			// first tick) to the end day: the 7-day sums, the rolling peak, and
			// the highest and lowest value (an unticked day counting as 0).
			$from = $startDay ?? $first;
			$last7 = 0;
			$previous7 = 0;
			$peak7 = 0;
			$window = 0;
			$max = PHP_INT_MIN;
			$min = PHP_INT_MAX;
			for ($day = $from; $day <= $endDay; $day++) {
				$value = $byDay[$day] ?? 0;
				$window += $value - ($byDay[$day - 7] ?? 0);
				$peak7 = max($peak7, $window);
				$max = max($max, $value);
				$min = min($min, $value);
				if ($day > $endDay - 7) {
					$last7 += $value;
				} elseif ($day > $endDay - 14) {
					$previous7 += $value;
				}
			}

			// Both end days count, so ticking every day reads exactly 7
			$weeks = max(1.0, ($endDay - $from + 1) / 7);
			$stats['total'] = $total;
			$stats['weeklyMean'] = $total / $weeks;
			$stats['last7'] = $last7;
			$stats['previous7'] = $previous7;
			$stats['peak7'] = $peak7;
			$stats['firstTick'] = self::dateOf($first);
			$stats['lastTick'] = self::dateOf($last);
			$stats['maxValue'] = $max;
			$stats['minValue'] = $min;
		}

		if ($includeRuns) {
			$stats['runs'] = $streaks['runs'];
		}
		return $stats;
	}

	/**
	 * Split one track's history into alternating streaks and breaks.
	 *
	 * The rule (the reference for every client: the web, Tickdroid, Tickcli,
	 * TickLLMe): days from the first tick on or before today take part, ticks
	 * after today are ignored, and an open today never ends a streak. If today
	 * is not ticked but yesterday is, counting stops at yesterday; otherwise it
	 * runs to today, so an open today after an unticked yesterday joins the break.
	 *
	 * @param iterable<string> $tickedDates Every date with a tick row for one track
	 *                                      (any order, may include future dates)
	 * @param string $today The day counting runs up to, YYYY-MM-DD; passed in, never
	 *                      read inside, so it is testable
	 * @return array{
	 *     runs: list<TickbuddyRun>,
	 *     current: ?TickbuddyRun,
	 *     upToYesterday: bool,
	 *     longestStreak: ?TickbuddyRun,
	 *     longestBreak: ?TickbuddyRun,
	 * }
	 */
	public static function summariseStreaks(iterable $tickedDates, string $today): array {
		$todayDay = self::requireDayNumber($today);
		$ticked = [];
		foreach ($tickedDates as $date) {
			$day = self::dayNumber($date);
			if ($day !== null && $day <= $todayDay) {
				$ticked[$day] = 1;
			}
		}
		return self::runsFromDays($ticked, $todayDay);
	}

	/**
	 * {@see summariseStreaks} on day numbers, optionally over a period.
	 *
	 * With $start, every day from $start takes part, so unticked days at the
	 * start are a break, and a period without ticks is one break covering it.
	 * $today is the period's last day, to which the open-today rule applies.
	 *
	 * @param array<int, int> $ticked Day number => anything, only days from $start
	 *                                (if given) on or before $today
	 * @param ?int $start The period's first day, or null to start at the first tick
	 * @return array{
	 *     runs: list<TickbuddyRun>,
	 *     current: ?TickbuddyRun,
	 *     upToYesterday: bool,
	 *     longestStreak: ?TickbuddyRun,
	 *     longestBreak: ?TickbuddyRun,
	 * }
	 */
	private static function runsFromDays(array $ticked, int $today, ?int $start = null): array {
		if ($start !== null) {
			if ($start > $today) {
				return self::NO_RUNS;
			}
			$first = $start;
		} elseif ($ticked === []) {
			return self::NO_RUNS;
		} else {
			$first = min(array_keys($ticked));
		}
		$end = !isset($ticked[$today]) && isset($ticked[$today - 1]) ? $today - 1 : $today;

		/** @var list<array{isStreak: bool, from: int, to: int}> $spans */
		$spans = [];
		$open = -1;
		for ($day = $first; $day <= $end; $day++) {
			$isStreak = isset($ticked[$day]);
			if ($open >= 0 && $spans[$open]['isStreak'] === $isStreak) {
				$spans[$open]['to'] = $day;
			} else {
				$spans[] = ['isStreak' => $isStreak, 'from' => $day, 'to' => $day];
				$open++;
			}
		}

		/** @var list<TickbuddyRun> $runs */
		$runs = [];
		foreach ($spans as $span) {
			$runs[] = [
				'isStreak' => $span['isStreak'],
				'length' => $span['to'] - $span['from'] + 1,
				'from' => self::dateOf($span['from']),
				'to' => self::dateOf($span['to']),
			];
		}

		// Strict > keeps the first of equally long runs.
		$current = null;
		/** @var ?TickbuddyRun $longestStreak */
		$longestStreak = null;
		/** @var ?TickbuddyRun $longestBreak */
		$longestBreak = null;
		foreach ($runs as $run) {
			$current = $run;
			if ($run['isStreak']) {
				if ($longestStreak === null || $run['length'] > $longestStreak['length']) {
					$longestStreak = $run;
				}
			} elseif ($longestBreak === null || $run['length'] > $longestBreak['length']) {
				$longestBreak = $run;
			}
		}

		return [
			'runs' => $runs,
			'current' => $current,
			'upToYesterday' => $end !== $today,
			'longestStreak' => $longestStreak,
			'longestBreak' => $longestBreak,
		];
	}

	/**
	 * Days since 1970-01-01 of a YYYY-MM-DD date, or null when it isn't a real
	 * date. Computed arithmetically (Howard Hinnant's days_from_civil) rather
	 * than through DateTime objects: this runs once per tick row.
	 */
	private static function dayNumber(string $date): ?int {
		if (!TrackService::isValidDate($date)) {
			return null;
		}
		$y = (int)substr($date, 0, 4);
		$m = (int)substr($date, 5, 2);
		$d = (int)substr($date, 8, 2);
		if ($m <= 2) {
			$y--;
		}
		$era = intdiv($y, 400);
		$yoe = $y - $era * 400;
		$doy = intdiv(153 * ($m + ($m > 2 ? -3 : 9)) + 2, 5) + $d - 1;
		$doe = $yoe * 365 + intdiv($yoe, 4) - intdiv($yoe, 100) + $doy;
		return $era * 146097 + $doe - 719468;
	}

	private static function requireDayNumber(string $date): int {
		return self::dayNumber($date) ?? throw new \InvalidArgumentException('Not a date: ' . $date);
	}

	/** The YYYY-MM-DD date of a day number. */
	private static function dateOf(int $day): string {
		return gmdate('Y-m-d', $day * self::SECONDS_PER_DAY);
	}
}
