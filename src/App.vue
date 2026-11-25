<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { emit } from '@nextcloud/event-bus'
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcAppNavigation from '@nextcloud/vue/components/NcAppNavigation'
import NcAppNavigationItem from '@nextcloud/vue/components/NcAppNavigationItem'
import NcContent from '@nextcloud/vue/components/NcContent'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import { useIsMobile } from '@nextcloud/vue/composables/useIsMobile'
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

const isMobile = useIsMobile()

const route = ref<Route>(parseHash(window.location.hash, todayStr()).route)
// The last track the Analytics picker lists that it showed, for the
// navigation's link, so it survives switching screens. A track the picker
// doesn't list (archived, or private while hidden) is shown when the route
// names it, but never kept here.
const analyticsTrackId = ref<number | null>(null)
// Whether the open track sidebar was opened from the list, by pushing a history
// entry: closing it then goes back, so Back and ✕ agree.
let sidebarPushed = false

function applyHash(): void {
	const previous = route.value
	const { route: next, replaceWith } = parseHash(window.location.hash, todayStr())
	if (replaceWith !== null) history.replaceState(null, '', replaceWith)
	route.value = next
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

/**
 * On a phone, put the navigation drawer away once a screen is picked.
 *
 * NcAppNavigationItem does this itself only for router links; ours are plain
 * hash links, so the drawer stayed over the screen just picked. NcAppNavigation
 * has no controlled `open` prop, so its event bus is the way to say so. A click
 * with a modifier key opens a new tab or window, and leaves the drawer as it is;
 * on desktop the navigation is not a drawer and stays put.
 * @param event the click on a navigation item
 */
function closeNavigationOnMobile(event: MouseEvent): void {
	if (!isMobile.value || event.metaKey || event.altKey || event.ctrlKey || event.shiftKey) return
	emit('toggle-navigation', { open: false })
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

// The track Analytics shows: put it in the URL, and remember it if the picker lists it
function setAnalyticsTrack(trackId: number | null, listed: boolean): void {
	if (listed) analyticsTrackId.value = trackId
	if (route.value.screen === 'analytics') {
		replaceRoute({ screen: 'analytics', trackId })
	}
}

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
		<NcAppNavigation aria-label="Tickbuddy screens">
			<template #list>
				<NcAppNavigationItem name="Today"
					href="#today"
					:active="route.screen === 'today'"
					@click="closeNavigationOnMobile">
					<template #icon>
						<NcIconSvgWrapper :path="MDI_CHECKBOX_MARKED_OUTLINE" />
					</template>
				</NcAppNavigationItem>
				<NcAppNavigationItem name="Journal"
					href="#journal"
					:active="route.screen === 'journal'"
					@click="closeNavigationOnMobile">
					<template #icon>
						<NcIconSvgWrapper :path="MDI_CALENDAR_MONTH_OUTLINE" />
					</template>
				</NcAppNavigationItem>
				<NcAppNavigationItem name="Analytics"
					:href="hashFor({ screen: 'analytics', trackId: analyticsTrackId })"
					:active="route.screen === 'analytics'"
					@click="closeNavigationOnMobile">
					<template #icon>
						<NcIconSvgWrapper :path="MDI_CHART_BAR" />
					</template>
				</NcAppNavigationItem>
				<NcAppNavigationItem name="Tracks"
					href="#tracks"
					:active="route.screen === 'tracks'"
					@click="closeNavigationOnMobile">
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
				:track-id="route.trackId ?? analyticsTrackId"
				@update:track-id="setAnalyticsTrack" />
		</NcAppContent>
	</NcContent>
</template>
