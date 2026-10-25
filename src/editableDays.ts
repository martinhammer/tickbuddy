import { addDays } from './dates.ts'
import type { EditableDays } from './types.ts'

/** In menu order. */
export const EDITABLE_DAYS: EditableDays[] = ['activeDay', 'activeAndPrevious', 'oneWeek', 'allDays']

/** Tickdroid's words (`SettingsScreen.kt`). */
export const EDITABLE_DAYS_LABELS: Record<EditableDays, string> = {
	activeDay: 'Today',
	activeAndPrevious: '+1 day',
	oneWeek: '1 week',
	allDays: 'All',
}

// Days back from today that stay editable; null for no limit.
const HISTORY_DAYS: Record<EditableDays, number | null> = {
	activeDay: 0,
	activeAndPrevious: 1,
	oneWeek: 6,
	allDays: null,
}

/** What a click on a locked day says, as a toast and as the lock's tooltip. */
export const LOCKED_DAY_MESSAGE = 'This day is locked. Select editable days in the Journal.'

/**
 * Whether a click may change this day, as Tickdroid's `EditableDays.isEditable`:
 * never after today, and not older than the setting allows.
 *
 * @param day the day as YYYY-MM-DD
 * @param today the local date as YYYY-MM-DD
 * @param setting the Editable days value in use
 */
export function isEditable(day: string, today: string, setting: EditableDays): boolean {
	// YYYY-MM-DD strings sort as dates
	if (day > today) return false
	const limit = HISTORY_DAYS[setting]
	if (limit === null) return true
	return day >= addDays(today, -limit)
}
