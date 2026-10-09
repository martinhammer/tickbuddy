import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'
import type { Tick, Track } from './types.ts'

// "Everything": the range Analytics and the dashboard widget fetch to get a
// track's whole history.
export const ALL_TICKS_FROM = '2000-01-01'
export const ALL_TICKS_TO = '2099-12-31'

export async function fetchTracks(): Promise<Track[]> {
	const response = await axios.get(generateOcsUrl('/apps/tickbuddy/api/tracks'))
	return response.data.ocs.data
}

/** Every tick of every track, private ones included (callers filter). */
export async function fetchAllTicks(): Promise<Tick[]> {
	const response = await axios.get(generateOcsUrl('/apps/tickbuddy/api/ticks'), {
		params: { from: ALL_TICKS_FROM, to: ALL_TICKS_TO },
	})
	return response.data.ocs.data
}
