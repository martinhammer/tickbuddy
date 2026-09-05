<script setup lang="ts">
import { ref, watch, onMounted, onBeforeUnmount } from 'vue'
import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcAppNavigation from '@nextcloud/vue/components/NcAppNavigation'
import NcAppNavigationItem from '@nextcloud/vue/components/NcAppNavigationItem'
import NcAppNavigationSettings from '@nextcloud/vue/components/NcAppNavigationSettings'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcContent from '@nextcloud/vue/components/NcContent'
import AnalyticsView from './components/AnalyticsView.vue'
import TickGrid from './components/TickGrid.vue'

type View = 'journal' | 'readonly' | 'analytics'

const VIEWS: View[] = ['journal', 'readonly', 'analytics']

const currentView = ref<View>('journal')
const showPrivate = ref(false)
// Track shown in Analytics. Kept here so it survives switching views, and so a
// click on a journal column header can preselect it.
const analyticsTrackId = ref<number | null>(null)

// --- Hash routing -----------------------------------------------------------
// The view lives in the URL hash (#journal, #analytics/3), not in the path:
// PageController only serves /apps/tickbuddy/, so a real path would 404 on
// reload. Navigation is therefore just a hash change — the browser maintains
// the history stack and we only translate between hash and state, which is why
// Back/Forward work without a router.

function hashFor(view: View, trackId: number | null): string {
	return view === 'analytics' && trackId !== null ? `#analytics/${trackId}` : `#${view}`
}

function currentHash(): string {
	return hashFor(currentView.value, analyticsTrackId.value)
}

function parseHash(): { view: View, trackId: number | null } | null {
	const [name, rawId] = window.location.hash.slice(1).split('/')
	if (!VIEWS.includes(name as View)) return null
	const trackId = Number.parseInt(rawId, 10)
	return { view: name as View, trackId: Number.isNaN(trackId) ? null : trackId }
}

function applyHash(): void {
	const parsed = parseHash()
	if (!parsed) {
		// Unknown or bare "#" — keep the state we have and make the URL match it.
		history.replaceState(null, '', currentHash())
		return
	}
	currentView.value = parsed.view
	// Only adopt a track when the hash names one, so leaving Analytics does not
	// forget which track was open.
	if (parsed.trackId !== null) {
		analyticsTrackId.value = parsed.trackId
	}
}

function showAnalyticsFor(trackId: number): void {
	// Assigning the hash is the navigation; hashchange feeds it back into state.
	window.location.hash = hashFor('analytics', trackId)
}

// Picking a track in the Analytics dropdown refines the current view rather
// than being a navigation of its own, so it replaces the history entry instead
// of pushing one — otherwise Back would step through every track viewed.
watch(analyticsTrackId, () => {
	if (currentView.value === 'analytics') {
		history.replaceState(null, '', currentHash())
	}
})

async function loadDefaultView(): Promise<void> {
	try {
		const response = await axios.get(generateOcsUrl('/apps/tickbuddy/api/preferences'))
		const view = response.data.ocs.data.defaultView as View
		if (VIEWS.includes(view)) {
			currentView.value = view
		}
	} catch {
		// use default
	}
}

onMounted(async () => {
	window.addEventListener('hashchange', applyHash)
	if (parseHash()) {
		// A link or reload named the view; it wins over the saved preference.
		applyHash()
	} else {
		await loadDefaultView()
	}
	// Stamp the state onto the current entry rather than pushing, so the first
	// Back leaves the app instead of merely stripping the hash.
	history.replaceState(null, '', currentHash())
})

onBeforeUnmount(() => {
	window.removeEventListener('hashchange', applyHash)
})
</script>

<template>
	<NcContent app-name="tickbuddy">
		<NcAppNavigation>
			<template #list>
				<NcAppNavigationItem name="Edit journal"
					href="#journal"
					:active="currentView === 'journal'" />
				<NcAppNavigationItem name="View journal"
					href="#readonly"
					:active="currentView === 'readonly'" />
				<NcAppNavigationItem name="Analytics"
					:href="hashFor('analytics', analyticsTrackId)"
					:active="currentView === 'analytics'" />
			</template>
			<template #footer>
				<NcAppNavigationSettings>
					<NcCheckboxRadioSwitch v-model="showPrivate"
						type="switch">
						Show private tracks
					</NcCheckboxRadioSwitch>
				</NcAppNavigationSettings>
			</template>
		</NcAppNavigation>
		<NcAppContent>
			<TickGrid v-if="currentView === 'journal'"
				:show-private="showPrivate"
				@select-track="showAnalyticsFor" />
			<TickGrid v-else-if="currentView === 'readonly'"
				:show-private="showPrivate"
				readonly
				@select-track="showAnalyticsFor" />
			<AnalyticsView v-else-if="currentView === 'analytics'"
				v-model:track-id="analyticsTrackId"
				:show-private="showPrivate" />
		</NcAppContent>
	</NcContent>
</template>
