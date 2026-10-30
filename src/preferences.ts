import { computed, reactive } from 'vue'
import { showError } from '@nextcloud/dialogs'
import { useIsMobile } from '@nextcloud/vue/composables/useIsMobile'
import { fetchPreferences, savePreference } from './api.ts'
import type { EditableDays, JournalLayout, Preferences } from './types.ts'

// The toolbar choices (private tracks, Journal layout, Editable days), saved on
// the server with one value for desktop and one for mobile. Which one applies
// follows the window width, as Nextcloud's own components do (under 1024px is
// mobile), and switches live when the width crosses the line.

/** For someone who has never changed them, and when the load fails. */
const STARTING: Preferences = {
	journalLayoutDesktop: 'daysDown',
	journalLayoutMobile: 'daysDown',
	showPrivateDesktop: false,
	showPrivateMobile: false,
	editableDaysDesktop: 'allDays',
	editableDaysMobile: 'activeDay',
}

const stored = reactive<Preferences>({ ...STARTING })
const isMobile = useIsMobile()
let loaded: Promise<void> | null = null

/** Load the saved values, once per page; later calls wait for the same load. */
export function loadPreferences(): Promise<void> {
	loaded ??= fetchPreferences()
		.then((prefs) => { Object.assign(stored, prefs) })
		.catch(() => {
			// The starting values apply
		})
	return loaded
}

type Field = keyof Preferences

// The value for the current device class. The UI changes at once; a failed
// save keeps the new value for this page.
function choice<T extends Preferences[Field]>(desktop: Field, mobile: Field) {
	return computed<T>({
		get: () => stored[isMobile.value ? mobile : desktop] as T,
		set: (value) => {
			const field = isMobile.value ? mobile : desktop
			if (stored[field] === value) return
			;(stored as Record<Field, T>)[field] = value
			savePreference(field, value).catch(() => {
				showError('Could not save the setting')
			})
		},
	})
}

export const showPrivate = choice<boolean>('showPrivateDesktop', 'showPrivateMobile')
export const journalLayout = choice<JournalLayout>('journalLayoutDesktop', 'journalLayoutMobile')
export const editableDays = choice<EditableDays>('editableDaysDesktop', 'editableDaysMobile')
