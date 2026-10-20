import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'
import { isTickbuddyApiUrl, PREFER_STRICT } from './strictHandling.ts'
import type { Tick, Track, TrackStats } from './types.ts'

// "Everything": the range Analytics fetches to get a track's whole history
// for its charts.
export const ALL_TICKS_FROM = '2000-01-01'
export const ALL_TICKS_TO = '2099-12-31'

let strictRegistered = false

/**
 * Send `Prefer: handling=strict` on every request to Tickbuddy's API, so the server
 * answers 423 instead of a quiet 200 when a write hits an archived track. Only
 * Tickbuddy's URLs: other Nextcloud endpoints (WebDAV reads Prefer) must not get it.
 * `@nextcloud/axios` is one instance per bundle, so this covers this app's requests,
 * those its `@nextcloud/vue` components make included (hence the URL check).
 */
export function preferStrictHandling(): void {
	if (strictRegistered) return
	strictRegistered = true
	axios.interceptors.request.use((config) => {
		if (isTickbuddyApiUrl(config.url)) {
			config.headers.set('Prefer', PREFER_STRICT)
		}
		return config
	})
}

export async function fetchTracks(): Promise<Track[]> {
	const response = await axios.get(generateOcsUrl('/apps/tickbuddy/api/tracks'))
	return response.data.ocs.data
}

/**
 * Every tick of every track in a date range, private ones included (callers filter).
 *
 * @param from first day, YYYY-MM-DD
 * @param to last day, YYYY-MM-DD, inclusive
 */
export async function fetchTicks(from: string, to: string): Promise<Tick[]> {
	const response = await axios.get(generateOcsUrl('/apps/tickbuddy/api/ticks'), {
		params: { from, to },
	})
	return response.data.ocs.data
}

/** Every tick of every track, private ones included (callers filter). */
export function fetchAllTicks(): Promise<Tick[]> {
	return fetchTicks(ALL_TICKS_FROM, ALL_TICKS_TO)
}

/**
 * Stats and streaks for every active track, or for one.
 *
 * @param today the local date as YYYY-MM-DD: whether today is still open decides where a streak ends
 * @param options what to ask for
 * @param options.trackId one track only (also an archived one)
 * @param options.includeRuns add every streak and break to each entry
 */
export async function fetchStats(today: string, options: { trackId?: number, includeRuns?: boolean } = {}): Promise<TrackStats[]> {
	const params: Record<string, string | number | boolean> = { today }
	if (options.trackId !== undefined) params.trackId = options.trackId
	if (options.includeRuns) params.includeRuns = true
	const response = await axios.get(generateOcsUrl('/apps/tickbuddy/api/stats'), { params })
	return response.data.ocs.data
}
