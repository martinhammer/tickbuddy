<?php

declare(strict_types=1);

namespace OCA\Tickbuddy\Service;

/**
 * Editable days, Tickdroid's `EditableDays`: which days can change, counted
 * back from today. The web's toolbar choice and a public link's allowed days
 * take these values. The server's copy of `isEditable` in `src/editableDays.ts`.
 */
final class EditableDays {
	/** In menu order */
	public const VALUES = ['activeDay', 'activeAndPrevious', 'oneWeek', 'allDays'];

	/** Tickdroid's words, as the web shows them */
	public const LABELS = [
		'activeDay' => 'Today',
		'activeAndPrevious' => '+1 day',
		'oneWeek' => '1 week',
		'allDays' => 'All',
	];

	/** Days back from today that are allowed; null for no limit */
	private const HISTORY_DAYS = [
		'activeDay' => 0,
		'activeAndPrevious' => 1,
		'oneWeek' => 6,
		'allDays' => null,
	];

	/**
	 * Whether a setting allows a day: never after today, and not older than the
	 * setting allows. False for an unknown setting or a day that isn't a date.
	 *
	 * @param string $day YYYY-MM-DD
	 * @param string $today YYYY-MM-DD
	 */
	public static function allows(string $day, string $today, string $setting): bool {
		if (!array_key_exists($setting, self::HISTORY_DAYS)) {
			return false;
		}
		$dayNumber = StatsService::dayNumber($day);
		$todayNumber = StatsService::dayNumber($today);
		if ($dayNumber === null || $todayNumber === null || $dayNumber > $todayNumber) {
			return false;
		}
		$limit = self::HISTORY_DAYS[$setting];
		return $limit === null || $dayNumber >= $todayNumber - $limit;
	}
}
