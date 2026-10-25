import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'
import { isTickbuddyApiUrl, PREFER_STRICT } from './strictHandling.ts'
import type { Note, Preferences, Tick, Track, TrackStats } from './types.ts'

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

/**
 * The user's tracks in journal order: the active ones, or with `includeArchived` the archived ones too.
 *
 * @param includeArchived also list archived tracks (only the Tracks page asks)
 */
export async function fetchTracks(includeArchived = false): Promise<Track[]> {
	const response = await axios.get(generateOcsUrl('/apps/tickbuddy/api/tracks'),
		includeArchived ? { params: { includeArchived: true } } : undefined)
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

/**
 * Every note in a date range, private and archived tracks' included (callers filter).
 *
 * @param from first day, YYYY-MM-DD
 * @param to last day, YYYY-MM-DD, inclusive
 */
export async function fetchNotes(from: string, to: string): Promise<Note[]> {
	const response = await axios.get(generateOcsUrl('/apps/tickbuddy/api/notes'), {
		params: { from, to },
	})
	return response.data.ocs.data
}

/**
 * Replace a day's note.
 *
 * @param trackId the track
 * @param date the day, YYYY-MM-DD
 * @param text the new text; empty deletes the note
 * @return the text as stored ("" when it was deleted)
 */
export async function setNote(trackId: number, date: string, text: string): Promise<string> {
	const params = new URLSearchParams()
	params.append('trackId', String(trackId))
	params.append('date', date)
	params.append('text', text)
	const response = await axios.post(generateOcsUrl('/apps/tickbuddy/api/notes/set'), params)
	return response.data.ocs.data.text
}

/**
 * Delete a day's note; fine when there is none.
 *
 * @param trackId the track
 * @param date the day, YYYY-MM-DD
 */
export async function deleteNote(trackId: number, date: string): Promise<void> {
	await axios.delete(generateOcsUrl('/apps/tickbuddy/api/notes'), { params: { trackId, date } })
}

/**
 * Set a yes/no day to a state, rather than toggling it, so a click never undoes
 * what another client wrote meanwhile.
 *
 * @param trackId a yes/no track
 * @param date the day, YYYY-MM-DD
 * @param value the state the control now shows
 * @return the stored value, 1 or 0
 */
export async function setYesNo(trackId: number, date: string, value: boolean): Promise<number> {
	const params = new URLSearchParams()
	params.append('trackId', String(trackId))
	params.append('date', date)
	params.append('value', String(value))
	const response = await axios.post(generateOcsUrl('/apps/tickbuddy/api/ticks/set'), params)
	return response.data.ocs.data.value
}

/**
 * Add to or take from a counter, rather than sending a new value worked out
 * from what the page last loaded, so changes made elsewhere meanwhile count too.
 *
 * @param trackId a counter track
 * @param date the day, YYYY-MM-DD
 * @param delta 1 or -1
 * @return the stored value
 */
export async function incrementCounter(trackId: number, date: string, delta: number): Promise<number> {
	const params = new URLSearchParams()
	params.append('trackId', String(trackId))
	params.append('date', date)
	params.append('delta', String(delta))
	const response = await axios.post(generateOcsUrl('/apps/tickbuddy/api/ticks/increment'), params)
	return response.data.ocs.data.value
}

export async function fetchPreferences(): Promise<Preferences> {
	const response = await axios.get(generateOcsUrl('/apps/tickbuddy/api/preferences'))
	return response.data.ocs.data
}

/**
 * Save one preference; the others stay as they are.
 *
 * @param field the preference
 * @param value its new value
 */
export async function savePreference<K extends keyof Preferences>(field: K, value: Preferences[K]): Promise<void> {
	const params = new URLSearchParams()
	params.append(field, String(value))
	await axios.put(generateOcsUrl('/apps/tickbuddy/api/preferences'), params)
}
