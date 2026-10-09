import { addDays } from './dates.ts'

export interface Run {
	isStreak: boolean
	length: number
	from: string // YYYY-MM-DD, first day of the run
	to: string // YYYY-MM-DD, last day of the run
}

export interface StreakSummary {
	/** Chronological runs from the first tick (on or before today) to the last counted day. */
	runs: Run[]
	/** The last run, or null when there is no tick on or before today. */
	current: Run | null
	/** True when the current run is a streak that ends yesterday because today is still open. */
	upToYesterday: boolean
	longestStreak: Run | null
	longestBreak: Run | null
}

/**
 * Split one track's history into alternating streaks and breaks.
 *
 * The rule (shared by the dashboard widget and Analytics, and the reference for
 * Tickdroid): days from the first tick on or before today take part, ticks after
 * today are ignored, and an open today never ends a streak. If today is not
 * ticked but yesterday is, counting stops at yesterday; otherwise it runs to
 * today, so an open today after an unticked yesterday joins the break.
 *
 * @param tickedDates every date with a tick row for one track (any order, may include future dates)
 * @param today the local date as YYYY-MM-DD; passed in, never read inside, so it is testable
 */
export function summariseStreaks(tickedDates: Iterable<string>, today: string): StreakSummary {
	const ticked = new Set(tickedDates)
	let first: string | null = null
	for (const ds of ticked) {
		if (ds <= today && (first === null || ds < first)) first = ds
	}
	if (first === null) {
		return { runs: [], current: null, upToYesterday: false, longestStreak: null, longestBreak: null }
	}

	const yesterday = addDays(today, -1)
	const end = !ticked.has(today) && ticked.has(yesterday) ? yesterday : today

	const runs: Run[] = []
	let run: Run | null = null
	for (let ds = first; ds <= end; ds = addDays(ds, 1)) {
		const isStreak = ticked.has(ds)
		if (run && run.isStreak === isStreak) {
			run.length++
			run.to = ds
		} else {
			run = { isStreak, length: 1, from: ds, to: ds }
			runs.push(run)
		}
	}

	// Strict > keeps the first of equally long runs.
	let longestStreak: Run | null = null
	let longestBreak: Run | null = null
	for (const r of runs) {
		if (r.isStreak) {
			if (!longestStreak || r.length > longestStreak.length) longestStreak = r
		} else if (!longestBreak || r.length > longestBreak.length) {
			longestBreak = r
		}
	}

	return {
		runs,
		current: runs[runs.length - 1],
		upToYesterday: end !== today,
		longestStreak,
		longestBreak,
	}
}
