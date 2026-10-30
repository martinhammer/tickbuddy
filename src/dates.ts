import { getLocale } from '@nextcloud/l10n'

/** The user's Nextcloud locale as a BCP 47 tag, for Intl formatters. */
export const userLocale = getLocale().replace('_', '-')

/**
 * A local date as YYYY-MM-DD (not toISOString, which is UTC).
 *
 * @param d the date
 */
export function toDateStr(d: Date): string {
	const y = d.getFullYear()
	const m = String(d.getMonth() + 1).padStart(2, '0')
	const day = String(d.getDate()).padStart(2, '0')
	return `${y}-${m}-${day}`
}

/**
 * A YYYY-MM-DD string as local midnight.
 *
 * @param ds the date as YYYY-MM-DD
 */
export function parseDateStr(ds: string): Date {
	return new Date(ds + 'T00:00:00')
}

/**
 * Shift a YYYY-MM-DD date by whole days. Goes through setDate rather than
 * adding milliseconds, so a 23- or 25-hour DST day still counts as one day.
 *
 * @param ds the date as YYYY-MM-DD
 * @param n days to add (negative to go back)
 */
export function addDays(ds: string, n: number): string {
	const d = parseDateStr(ds)
	d.setDate(d.getDate() + n)
	return toDateStr(d)
}

/** The browser's local date as YYYY-MM-DD. */
export function todayStr(): string {
	return toDateStr(new Date())
}

const dateFormatter = new Intl.DateTimeFormat(userLocale, {
	weekday: 'short', day: 'numeric', month: 'short', year: 'numeric',
})

/**
 * A date for tooltips and stat hints, e.g. "Mon 5 Oct 2026". Chart.js title
 * lines carry no comma, so drop the one Intl adds after the weekday.
 *
 * @param ds the date as YYYY-MM-DD
 */
export function fmtDate(ds: string): string {
	return dateFormatter.format(parseDateStr(ds)).replace(',', '')
}

const dayFormatter = new Intl.DateTimeFormat(userLocale, {
	day: 'numeric', month: 'short', year: 'numeric',
})

/**
 * A date without its weekday, e.g. "14 Aug 2026" ("Aug 14, 2026" in US English).
 *
 * @param ds the date as YYYY-MM-DD
 */
export function fmtDay(ds: string): string {
	return dayFormatter.format(parseDateStr(ds))
}

const shortDayFormatter = new Intl.DateTimeFormat(userLocale, { weekday: 'short', day: 'numeric', month: 'short' })

/**
 * A date for labels where the year is mostly known, e.g. "Mon 19 Oct", with
 * the year added outside the current one ("Mon 19 Oct 2025").
 *
 * @param ds the date as YYYY-MM-DD
 * @param today the local date as YYYY-MM-DD
 */
export function fmtDayLabel(ds: string, today: string): string {
	return ds.slice(0, 4) === today.slice(0, 4)
		? shortDayFormatter.format(parseDateStr(ds)).replace(',', '')
		: fmtDate(ds)
}

// Weekend days from the user's Nextcloud locale, as ISO day numbers (1=Mon … 7=Sun).
// Chromium has the weekInfo property, Firefox the getWeekInfo() method; Saturday
// and Sunday when neither is there.
const weekendDays: Set<number> = (() => {
	try {
		const locale = new Intl.Locale(userLocale) as Intl.Locale & {
			weekInfo?: { weekend?: number[] }
			getWeekInfo?: () => { weekend?: number[] }
		}
		const info = locale.weekInfo ?? locale.getWeekInfo?.()
		if (info?.weekend) return new Set(info.weekend)
	} catch {
		// ignore
	}
	return new Set([6, 7])
})()

/**
 * Whether a day is a weekend day in the user's locale.
 *
 * @param ds the date as YYYY-MM-DD
 */
export function isWeekend(ds: string): boolean {
	const jsDay = parseDateStr(ds).getDay()
	return weekendDays.has(jsDay === 0 ? 7 : jsDay)
}

/**
 * The newest of Today's seven squares: today, or, once the day shown is more
 * than six days back, six days after it, so the day shown is the oldest square.
 * Stepping a day back or forward moves the outline one square or scrolls the
 * squares one day, never jumps.
 *
 * @param day the day shown as YYYY-MM-DD, not after today
 * @param today the local date as YYYY-MM-DD
 */
export function squaresEnd(day: string, today: string): string {
	return day < addDays(today, -6) ? addDays(day, 6) : today
}

/**
 * Today's title and the line under it, by how far back the day is: "Today",
 * "Yesterday" and "2 days ago" to "6 days ago" over the full date; from 7 days
 * back the date itself ("6 October", with the year in an earlier one) over the
 * full date with its year.
 *
 * @param day the day shown as YYYY-MM-DD, not after today
 * @param today the local date as YYYY-MM-DD
 * @param locale a BCP 47 tag; the user's locale unless testing
 */
export function dayHeading(day: string, today: string, locale: string = userLocale): { title: string, line: string } {
	const date = parseDateStr(day)
	// Walked with addDays, so a DST day still counts as one
	let daysBack = 0
	while (daysBack < 7 && addDays(today, -daysBack) !== day) daysBack++
	if (daysBack < 7) {
		// numeric: 'auto' gives "today" and "yesterday" rather than "0 days ago"
		const relative = new Intl.RelativeTimeFormat(locale, { numeric: 'auto' }).format(-daysBack, 'day')
		return {
			title: relative.charAt(0).toLocaleUpperCase(locale) + relative.slice(1),
			line: new Intl.DateTimeFormat(locale, { weekday: 'long', day: 'numeric', month: 'long' }).format(date),
		}
	}
	const sameYear = day.slice(0, 4) === today.slice(0, 4)
	return {
		title: new Intl.DateTimeFormat(locale, { day: 'numeric', month: 'long', year: sameYear ? undefined : 'numeric' }).format(date),
		line: new Intl.DateTimeFormat(locale, { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }).format(date),
	}
}
