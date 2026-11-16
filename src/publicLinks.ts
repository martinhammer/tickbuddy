import { addDays, parseDateStr, toDateStr, userLocale } from './dates.ts'
import { EDITABLE_DAYS_LABELS } from './editableDays.ts'
import { typeName } from './trackMenu.ts'
import type { PublicLink, Track } from './types.ts'

// The words of the Public links tab, the Tracks page and the confirms, kept
// here so they can be tested without a component.

/**
 * "1 public link", "2 public links", or nothing for none.
 *
 * @param count live links
 */
export function linkCountText(count: number): string {
	if (count <= 0) return ''
	return count === 1 ? '1 public link' : `${count} public links`
}

/**
 * The line under a track's name on the Tracks page: "Yes/No · Private · 2 public links".
 *
 * @param track the track
 * @param linkCount its live links; the archived list passes none, as their links are revoked
 */
export function trackSubname(track: Track, linkCount = 0): string {
	return [typeName(track.type), track.private ? 'Private' : '', linkCountText(linkCount)]
		.filter(part => part !== '')
		.join(' · ')
}

/**
 * "Tick only · +1 day".
 *
 * @param link the link
 */
export function linkDetails(link: PublicLink): string {
	const permission = link.permission === 'tick' ? 'Tick only' : 'View only'
	return `${permission} · ${EDITABLE_DAYS_LABELS[link.allowedDays] ?? link.allowedDays}`
}

/**
 * "Last used today, 03:12", "Last used yesterday, 22:10", "Last used Sat 14 Nov, 03:12"
 * (with the year outside this one), or "Not used yet". In the browser's time zone.
 *
 * @param lastUsedAt Unix seconds, or null
 * @param now the time to count from
 * @param locale a BCP 47 tag
 */
export function lastUsedText(lastUsedAt: number | null, now: Date, locale: string = userLocale): string {
	if (lastUsedAt === null) return 'Not used yet'
	const used = new Date(lastUsedAt * 1000)
	const time = new Intl.DateTimeFormat(locale, { hour: '2-digit', minute: '2-digit' }).format(used)
	const day = toDateStr(used)
	const today = toDateStr(now)
	if (day === today) return `Last used today, ${time}`
	if (day === addDays(today, -1)) return `Last used yesterday, ${time}`
	const options: Intl.DateTimeFormatOptions = { weekday: 'short', day: 'numeric', month: 'short' }
	if (day.slice(0, 4) !== today.slice(0, 4)) options.year = 'numeric'
	// As fmtDayLabel: no comma after the weekday
	return `Last used ${new Intl.DateTimeFormat(locale, options).format(parseDateStr(day)).replace(',', '')}, ${time}`
}

/**
 * The revoke confirm's text.
 *
 * @param link the link
 * @param trackName its track's name
 */
export function revokeText(link: PublicLink, trackName: string): string {
	return link.permission === 'tick'
		? `Scripts using this link will no longer be able to tick the track "${trackName}". Ticks already made are kept.`
		: `Scripts using this link will no longer be able to read the track "${trackName}".`
}

/**
 * The archive and delete confirms' line, or nothing for a track without links.
 *
 * @param count live links
 */
export function archiveLinksText(count: number): string {
	if (count <= 0) return ''
	return count === 1 ? 'This track\'s public link will be revoked.' : `This track's ${count} public links will be revoked.`
}

/**
 * The address up to and including `/ping/`, for the examples in "How to use
 * public links", which never show a token. Empty for an address without one.
 *
 * @param url a link's address
 */
export function exampleBase(url: string): string {
	const at = url.lastIndexOf('/ping/')
	return at < 0 ? '' : url.slice(0, at + '/ping/'.length)
}

/**
 * The dates in the examples: yesterday for `date`, and the seven days ending
 * today for `from` and `to`, so they work with +1 day and with 1 week.
 *
 * @param today the local date as YYYY-MM-DD
 */
export function exampleDates(today: string): { yesterday: string, from: string, to: string } {
	return { yesterday: addDays(today, -1), from: addDays(today, -6), to: today }
}
