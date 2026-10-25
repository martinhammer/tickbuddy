<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcAppNavigation from '@nextcloud/vue/components/NcAppNavigation'
import NcAppNavigationItem from '@nextcloud/vue/components/NcAppNavigationItem'
import NcContent from '@nextcloud/vue/components/NcContent'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import { todayStr } from './dates.ts'
import { MDI_CALENDAR_MONTH_OUTLINE, MDI_CHART_BAR, MDI_CHECKBOX_MARKED_OUTLINE, MDI_FORMAT_LIST_CHECKS } from './icons.ts'
import { loadPreferences } from './preferences.ts'
import { hashFor, parseHash } from './routes.ts'
import type { Route } from './routes.ts'
import AnalyticsView from './components/AnalyticsView.vue'
import JournalView from './components/JournalView.vue'
import TodayView from './components/TodayView.vue'
import TracksView from './components/TracksView.vue'

// Four screens with one job each: tick (Today), review (Journal), understand
// (Analytics), set up (Tracks). The screen lives in the URL hash; see routes.ts.

const route = ref<Route>(parseHash(window.location.hash, todayStr()).route)
// Track shown in Analytics. Kept here so it survives switching screens, and so
// a click on a journal track name can preselect it.
const analyticsTrackId = ref<number | null>(route.value.screen === 'analytics' ? route.value.trackId : null)
// Whether the open track sidebar was opened from the list, by pushing a history
// entry: closing it then goes back, so Back and ✕ agree.
let sidebarPushed = false

function applyHash(): void {
	const previous = route.value
	const { route: next, replaceWith } = parseHash(window.location.hash, todayStr())
	if (replaceWith !== null) history.replaceState(null, '', replaceWith)
	route.value = next
	if (next.screen === 'analytics' && next.trackId !== null) {
		analyticsTrackId.value = next.trackId
	}
	sidebarPushed = sidebarPushed && next.screen === 'tracks' && next.trackId !== null
		&& previous.screen === 'tracks'
}

// Refining the screen shown (another day on Today, another track in Analytics
// or the sidebar) replaces the history entry rather than pushing one, so Back
// leaves the screen instead of stepping through every day or track.
function replaceRoute(next: Route): void {
	route.value = next
	history.replaceState(null, '', hashFor(next))
}

function navigate(next: Route): void {
	// Assigning the hash is the navigation; hashchange feeds it back into state.
	window.location.hash = hashFor(next)
}

function setTodayDay(day: string | null): void {
	replaceRoute({ screen: 'today', day })
}

function openTrackSidebar(trackId: number): void {
	const current = route.value
	if (current.screen === 'tracks' && current.trackId !== null) {
		replaceRoute({ screen: 'tracks', trackId })
		return
	}
	sidebarPushed = current.screen === 'tracks'
	navigate({ screen: 'tracks', trackId })
}

function closeTrackSidebar(): void {
	if (sidebarPushed) {
		sidebarPushed = false
		history.back()
		return
	}
	replaceRoute({ screen: 'tracks', trackId: null })
}

watch(analyticsTrackId, (trackId) => {
	if (route.value.screen === 'analytics') {
		replaceRoute({ screen: 'analytics', trackId })
	}
})

onMounted(() => {
	window.addEventListener('hashchange', applyHash)
	// Make the URL match: the first Back then leaves the app instead of merely
	// stripping the hash.
	history.replaceState(null, '', hashFor(route.value))
	loadPreferences()
})

onBeforeUnmount(() => {
	window.removeEventListener('hashchange', applyHash)
})
</script>

<template>
	<NcContent app-name="tickbuddy">
		<NcAppNavigation>
			<template #list>
				<NcAppNavigationItem name="Today"
					href="#today"
					:active="route.screen === 'today'">
					<template #icon>
						<NcIconSvgWrapper :path="MDI_CHECKBOX_MARKED_OUTLINE" />
					</template>
				</NcAppNavigationItem>
				<NcAppNavigationItem name="Journal"
					href="#journal"
					:active="route.screen === 'journal'">
					<template #icon>
						<NcIconSvgWrapper :path="MDI_CALENDAR_MONTH_OUTLINE" />
					</template>
				</NcAppNavigationItem>
				<NcAppNavigationItem name="Analytics"
					:href="hashFor({ screen: 'analytics', trackId: analyticsTrackId })"
					:active="route.screen === 'analytics'">
					<template #icon>
						<NcIconSvgWrapper :path="MDI_CHART_BAR" />
					</template>
				</NcAppNavigationItem>
				<NcAppNavigationItem name="Tracks"
					href="#tracks"
					:active="route.screen === 'tracks'">
					<template #icon>
						<NcIconSvgWrapper :path="MDI_FORMAT_LIST_CHECKS" />
					</template>
				</NcAppNavigationItem>
			</template>
		</NcAppNavigation>
		<!-- Tracks brings its own NcAppContent, with the sidebar beside it -->
		<TracksView v-if="route.screen === 'tracks'"
			:track-id="route.trackId"
			@open-track="openTrackSidebar"
			@close-track="closeTrackSidebar" />
		<NcAppContent v-else>
			<TodayView v-if="route.screen === 'today'"
				:day="route.day"
				@update:day="setTodayDay" />
			<JournalView v-else-if="route.screen === 'journal'"
				@open-day="(day) => navigate({ screen: 'today', day })"
				@select-track="(trackId) => navigate({ screen: 'analytics', trackId })" />
			<AnalyticsView v-else-if="route.screen === 'analytics'"
				v-model:track-id="analyticsTrackId" />
		</NcAppContent>
	</NcContent>
</template>
