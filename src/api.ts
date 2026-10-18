import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'
import type { Tick, Track, TrackStats } from './types.ts'

// "Everything": the range Analytics fetches to get a track's whole history
// for its charts.
export const ALL_TICKS_FROM = '2000-01-01'
export const ALL_TICKS_TO = '2099-12-31'

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
