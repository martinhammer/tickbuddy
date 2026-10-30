import { showError } from '@nextcloud/dialogs'
import { ocsErrorMessage } from './ocsError.ts'
import { errorReason, httpStatus } from './strictHandling.ts'

/**
 * A tick or note write the server refused or that failed. A track archived (423)
 * or deleted (Tickbuddy's own 404) elsewhere leaves the screen on the reload;
 * anything else, Nextcloud's 404 for a disabled app included, keeps the screen
 * as it is.
 *
 * @param e the rejection value
 * @param trackName the track written to
 * @param reload loads the screen's tracks again
 * @param fallback what to say when the answer isn't Tickbuddy's
 */
export async function writeFailed(e: unknown, trackName: string, reload: () => Promise<unknown>, fallback = 'Could not save the change'): Promise<void> {
	const readOnly = httpStatus(e) === 423
	const reason = errorReason(e)
	if (readOnly && reason === 'archived') {
		showError(`${trackName} is archived, so the change wasn't saved.`)
	} else if (reason !== null) {
		showError(ocsErrorMessage(e, fallback))
	} else {
		// Not Tickbuddy's answer (Nextcloud's own, or none): its meta message
		// ("Invalid query…" while the app is disabled) isn't meant for people
		showError(fallback)
	}
	if (readOnly || reason === 'trackNotFound') {
		try {
			await reload()
		} catch {
			// The app went away in between (an upgrade disables it): keep the screen
		}
	}
}

/**
 * True when a track change was refused because the track is read-only (423): the
 * tracks are reloaded and the toast shown, so the caller shows nothing more. The
 * name may be a function, looked up after the reload (a reorder names the track
 * now archived).
 *
 * @param e the rejection value
 * @param name the track's name, or a function giving it after the reload
 * @param reload loads the tracks again
 */
export async function refusedAsReadOnly(e: unknown, name: string | (() => string | undefined), reload: () => Promise<unknown>): Promise<boolean> {
	if (httpStatus(e) !== 423) return false
	try {
		await reload()
	} catch {
		// Still say why the change wasn't saved
	}
	const trackName = typeof name === 'function' ? name() : name
	if (errorReason(e) === 'archived' && trackName !== undefined) {
		showError(`${trackName} is archived, so the change wasn't saved.`)
	} else {
		// Another read-only reason, or no name to give: the server's message names the track
		showError(ocsErrorMessage(e, 'Could not save the change'))
	}
	return true
}
