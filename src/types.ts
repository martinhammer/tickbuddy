export interface Track {
	id: number
	name: string
	type: string
	sortOrder: number
	private: boolean
	/** The archive date as YYYY-MM-DD, or null while active. */
	archivedAt: string | null
}

export interface Tick {
	id: number
	trackId: number
	date: string
	value: number
}

/** A streak (ticked days in a row) or a break (unticked days in a row). */
export interface Run {
	isStreak: boolean
	length: number
	from: string // YYYY-MM-DD, first day of the run
	to: string // YYYY-MM-DD, last day of the run
}

/**
 * One track's entry in `GET /api/stats`, worked out up to the track's end day:
 * the `today` sent, or an archived track's archive date if earlier.
 */
export interface TrackStats {
	trackId: number
	total: number
	/** Not rounded. */
	weeklyMean: number
	last7: number
	previous7: number
	peak7: number
	/** The last run, null with no ticks. */
	current: Run | null
	/** True when the current run is a streak that ends yesterday because today is still open. */
	upToYesterday: boolean
	longestStreak: Run | null
	longestBreak: Run | null
	firstTick: string | null
	lastTick: string | null
	/** Highest value over every day from the first tick, an unticked day counting as 0. */
	maxValue: number
	minValue: number
	/** Only when asked for with includeRuns. */
	runs?: Run[]
}

/** A note on a track's day (`GET /api/notes`); track and date are its key. */
export interface Note {
	trackId: number
	date: string // YYYY-MM-DD
	text: string
}

/** The Journal's two layouts, as Tickdroid's (`JournalLayout.kt`). */
export type JournalLayout = 'daysDown' | 'tracksDown'

/** Which days a click can change, as Tickdroid's (`EditableDays.kt`). */
export type EditableDays = 'activeDay' | 'activeAndPrevious' | 'oneWeek' | 'allDays'

/** `GET /api/preferences`: the toolbar choices, one value per device class. */
export interface Preferences {
	journalLayoutDesktop: JournalLayout
	journalLayoutMobile: JournalLayout
	showPrivateDesktop: boolean
	showPrivateMobile: boolean
	editableDaysDesktop: EditableDays
	editableDaysMobile: EditableDays
}
