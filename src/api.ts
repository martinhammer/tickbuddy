import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'
import { isTickbuddyApiUrl, PREFER_STRICT } from './strictHandling.ts'
import type { EditableDays, LinkPermission, Note, Preferences, PublicLink, Tick, Track, TrackStats } from './types.ts'

let strictRegistered = false

// The web knows every type there is, so its reads ask for all of them: without
// `includeAllTypes` the server leaves out Number and Choice tracks (and their
// ticks, notes and stats), for clients that predate them.
const ALL_TYPES = { includeAllTypes: true }

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
	const response = await axios.get(generateOcsUrl('/apps/tickbuddy/api/tracks'), {
		params: includeArchived ? { ...ALL_TYPES, includeArchived: true } : ALL_TYPES,
	})
	return response.data.ocs.data
}

/**
 * Every tick of every track in a date range, private ones included (callers
 * filter), or of one track.
 *
 * @param from first day, YYYY-MM-DD
 * @param to last day, YYYY-MM-DD, inclusive
 * @param trackId one track only (also an archived one)
 */
export async function fetchTicks(from: string, to: string, trackId?: number): Promise<Tick[]> {
	const response = await axios.get(generateOcsUrl('/apps/tickbuddy/api/ticks'), {
		params: trackId === undefined ? { ...ALL_TYPES, from, to } : { from, to, trackId },
	})
	return response.data.ocs.data
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
	const params: Record<string, string | number | boolean> = { ...ALL_TYPES, today }
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
		params: { ...ALL_TYPES, from, to },
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

/**
 * Set a Number or Choice day, or clear it.
 *
 * @param trackId a Number or Choice track
 * @param date the day, YYYY-MM-DD
 * @param value a Number's value with a point (numberValue.ts toWire), a Choice's
 *              place, or null to clear the day (sent as "", as a form can't send null)
 * @return the stored value, null when the day has none
 */
export async function setValue(trackId: number, date: string, value: string | null): Promise<number | null> {
	const params = new URLSearchParams()
	params.append('trackId', String(trackId))
	params.append('date', date)
	params.append('value', value ?? '')
	const response = await axios.post(generateOcsUrl('/apps/tickbuddy/api/ticks/set'), params)
	return response.data.ocs.data.value
}

/**
 * Rename one option of a Choice track.
 *
 * @param trackId a Choice track
 * @param place the option's place, from 1
 * @param label its new label
 * @return the whole track
 */
export async function renameOption(trackId: number, place: number, label: string): Promise<Track> {
	const params = new URLSearchParams()
	params.append('label', label)
	const response = await axios.put(generateOcsUrl(`/apps/tickbuddy/api/tracks/${trackId}/options/${place}`), params)
	return response.data.ocs.data
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

/**
 * A track's public links, oldest first.
 *
 * @param trackId the track
 */
export async function fetchPublicLinks(trackId: number): Promise<PublicLink[]> {
	const response = await axios.get(generateOcsUrl('/apps/tickbuddy/api/public-links'), { params: { trackId } })
	return response.data.ocs.data
}

/**
 * Create a public link. Its permission and allowed days can't change later.
 *
 * @param trackId the track
 * @param name the link's name
 * @param permission tick or view
 * @param allowedDays the days a call may tick or read
 */
export async function createPublicLink(trackId: number, name: string, permission: LinkPermission, allowedDays: EditableDays): Promise<PublicLink> {
	const params = new URLSearchParams()
	params.append('trackId', String(trackId))
	params.append('name', name)
	params.append('permission', permission)
	params.append('allowedDays', allowedDays)
	const response = await axios.post(generateOcsUrl('/apps/tickbuddy/api/public-links'), params)
	return response.data.ocs.data
}

/**
 * @param id the link
 * @param name its new name
 * @return the renamed link
 */
export async function renamePublicLink(id: number, name: string): Promise<PublicLink> {
	const params = new URLSearchParams()
	params.append('name', name)
	const response = await axios.put(generateOcsUrl(`/apps/tickbuddy/api/public-links/${id}`), params)
	return response.data.ocs.data
}

/**
 * Revoke a public link; fine when it already is.
 *
 * @param id the link
 */
export async function revokePublicLink(id: number): Promise<void> {
	await axios.delete(generateOcsUrl(`/apps/tickbuddy/api/public-links/${id}`))
}
