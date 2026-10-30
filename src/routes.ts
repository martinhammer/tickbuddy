import { parseDateStr, toDateStr } from './dates.ts'

// The screen lives in the URL hash (#today, #analytics/3), not in the path:
// PageController only serves /apps/tickbuddy/, so a real path would 404 on
// reload. Navigation is therefore just a hash change: the browser keeps the
// history and App.vue only translates between hash and state, which is why
// Back and Forward work without a router.

export type Route =
	/** `day` is null on today, so Today follows midnight */
	| { screen: 'today', day: string | null }
	| { screen: 'journal' }
	/** `trackId` null: Analytics picks the last track viewed, or the first */
	| { screen: 'analytics', trackId: number | null }
	/** `trackId`: that track's sidebar is open */
	| { screen: 'tracks', trackId: number | null }

export interface ParsedHash {
	route: Route
	/** The hash to put in place of the one given (history.replaceState), or null when it already fits. */
	replaceWith: string | null
}

export function hashFor(route: Route): string {
	switch (route.screen) {
	case 'today':
		return route.day === null ? '#today' : `#today/${route.day}`
	case 'journal':
		return '#journal'
	case 'analytics':
	case 'tracks':
		return route.trackId === null ? `#${route.screen}` : `#${route.screen}/${route.trackId}`
	}
}

function isRealDate(ds: string): boolean {
	return /^\d{4}-\d{2}-\d{2}$/.test(ds) && toDateStr(parseDateStr(ds)) === ds
}

function parseId(raw: string | undefined): number | null {
	return raw !== undefined && /^\d+$/.test(raw) ? Number.parseInt(raw, 10) : null
}

/**
 * The screen a hash names. Anything unknown is Today; a day that isn't real or
 * is after today is today; `#readonly` (View journal's old link) is the Journal.
 *
 * @param hash `window.location.hash`, with or without its "#"
 * @param today the local date as YYYY-MM-DD
 */
export function parseHash(hash: string, today: string): ParsedHash {
	const given = hash.startsWith('#') ? hash : `#${hash}`
	const [name, arg, ...rest] = given.slice(1).split('/')
	let route: Route
	switch (name) {
	case 'today':
		route = { screen: 'today', day: arg !== undefined && isRealDate(arg) && arg < today ? arg : null }
		break
	case 'journal':
	case 'readonly':
		route = { screen: 'journal' }
		break
	case 'analytics':
	case 'tracks':
		route = { screen: name, trackId: rest.length === 0 ? parseId(arg) : null }
		break
	default:
		route = { screen: 'today', day: null }
	}
	const canonical = hashFor(route)
	return { route, replaceWith: canonical === given ? null : canonical }
}
