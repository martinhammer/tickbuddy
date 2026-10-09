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
