<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { showError } from '@nextcloud/dialogs'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
// Toasts are a Vue component with hashed CSS-module class names since @nextcloud/dialogs 7,
// so the server's own stylesheets cannot style them — the app must ship this itself.
import '@nextcloud/dialogs/style.css'
import { deleteNote, fetchNotes, fetchStats, fetchTicks, fetchTracks, incrementCounter, setNote, setYesNo } from '../api.ts'
import { addDays, dayHeading } from '../dates.ts'
import { isEditable, LOCKED_DAY_MESSAGE } from '../editableDays.ts'
import { fillFor, getPrimaryColor, levelFor } from '../heatmap.ts'
import { MDI_CALENDAR_TODAY, MDI_CHEVRON_LEFT, MDI_CHEVRON_RIGHT } from '../icons.ts'
import { editableDays, loadPreferences, showPrivate } from '../preferences.ts'
import type { Track, TrackStats } from '../types.ts'
import { useToday } from '../useToday.ts'
import { writeFailed } from '../writeErrors.ts'
import EditableDaysMenu from './EditableDaysMenu.vue'
import PrivateToggle from './PrivateToggle.vue'
import TodayRow from './TodayRow.vue'

const props = defineProps<{
	/** The day shown as YYYY-MM-DD, or null for today (which then follows midnight) */
	day: string | null
}>()

const emit = defineEmits<{
	'update:day': [day: string | null]
}>()

type LoadStatus = 'loading' | 'ready' | 'error'

const status = ref<LoadStatus>('loading')
const tracks = ref<Track[]>([])
const stats = ref<TrackStats[]>([])
// Tick values and notes by tickKey(), for the last seven days and the day shown
const tickValues = ref<Record<string, number>>({})
const notes = ref<Record<string, string>>({})
const primaryColor = ref(getPrimaryColor())
let loadSeq = 0
let daySeq = 0
let inFlight = 0

const { today, loaded } = useToday(() => load('quiet'))

const shownDay = computed(() => props.day ?? today.value)

function tickKey(trackId: number, date: string): string {
	return `${trackId}|${date}`
}

const visibleTracks = computed(() => showPrivate.value ? tracks.value : tracks.value.filter(t => !t.private))

const view = computed<'loading' | 'error' | 'empty' | 'allPrivate' | 'list'>(() => {
	if (status.value === 'loading') return 'loading'
	if (status.value === 'error') return 'error'
	if (tracks.value.length === 0) return 'empty'
	if (visibleTracks.value.length === 0) return 'allPrivate'
	return 'list'
})

const statsByTrack = computed(() => new Map(stats.value.map(s => [s.trackId, s])))

// Squares and streaks always show the real today, whichever day the screen shows.
const week = computed(() => Array.from({ length: 7 }, (_, i) => addDays(today.value, -i)))

const locked = computed(() => !isEditable(shownDay.value, today.value, editableDays.value))

// The Editable days button shows while the day is locked, and stays on a day
// unlocked with it until another day is shown, so the choice can be undone.
const changedEditableDaysOn = ref<string | null>(null)
const showEditableDays = computed(() => locked.value || changedEditableDaysOn.value === shownDay.value)

// --- Header ---
const heading = computed(() => dayHeading(shownDay.value, today.value))

function goToDay(day: string): void {
	emit('update:day', day >= today.value ? null : day)
}

// --- Rows ---
const rows = computed(() => visibleTracks.value.map((track) => {
	const trackStats = statsByTrack.value.get(track.id)
	const isCounter = track.type === 'counter'
	const maxValue = trackStats?.maxValue ?? 0
	return {
		track,
		stats: trackStats,
		value: tickValues.value[tickKey(track.id, shownDay.value)] ?? 0,
		note: notes.value[tickKey(track.id, shownDay.value)] ?? '',
		squares: week.value.map((date) => {
			const value = tickValues.value[tickKey(track.id, date)] ?? 0
			return { date, value, fill: fillFor(levelFor(value, isCounter, maxValue), primaryColor.value) }
		}),
	}
}))

// --- Loading ---
function needsOwnTicks(day: string): boolean {
	return day < addDays(today.value, -6)
}

async function load(mode: 'initial' | 'quiet' | 'retry'): Promise<void> {
	// A quiet reload never competes with one already running.
	if (mode === 'quiet' && inFlight > 0) return
	const seq = ++loadSeq
	inFlight++
	if (mode === 'retry') status.value = 'loading'
	const now = today.value
	const day = shownDay.value
	try {
		const [t, k, dayTicks, s, n] = await Promise.all([
			fetchTracks(),
			fetchTicks(addDays(now, -6), now),
			needsOwnTicks(day) ? fetchTicks(day, day) : Promise.resolve([]),
			fetchStats(now),
			fetchNotes(day, day),
			loadPreferences(),
		])
		// A newer load started meanwhile: let it have the last word.
		if (seq !== loadSeq) return
		tracks.value = t
		stats.value = s
		const values: Record<string, number> = {}
		for (const tick of [...k, ...dayTicks]) values[tickKey(tick.trackId, tick.date)] = tick.value
		tickValues.value = values
		const dayNotes: Record<string, string> = {}
		for (const note of n) dayNotes[tickKey(note.trackId, note.date)] = note.text
		notes.value = dayNotes
		status.value = 'ready'
		loaded()
		// The day shown changed while this ran
		if (day !== shownDay.value) loadDay(shownDay.value)
	} catch {
		if (seq !== loadSeq) return
		// Quiet reloads keep whatever is on screen.
		if (mode !== 'quiet') status.value = 'error'
	} finally {
		inFlight--
	}
}

// Another day: only its ticks (when outside the seven days) and notes.
async function loadDay(day: string): Promise<void> {
	const seq = ++daySeq
	try {
		const [dayTicks, n] = await Promise.all([
			needsOwnTicks(day) ? fetchTicks(day, day) : Promise.resolve(null),
			fetchNotes(day, day),
		])
		if (seq !== daySeq || day !== shownDay.value) return
		if (dayTicks) {
			const values = { ...tickValues.value }
			for (const track of tracks.value) delete values[tickKey(track.id, day)]
			for (const tick of dayTicks) values[tickKey(tick.trackId, tick.date)] = tick.value
			tickValues.value = values
		}
		const dayNotes = { ...notes.value }
		for (const note of n) dayNotes[tickKey(note.trackId, note.date)] = note.text
		notes.value = dayNotes
	} catch {
		if (seq !== daySeq) return
		showError('Could not load the day')
	}
}

watch(shownDay, (day, previous) => {
	if (day !== previous) changedEditableDaysOn.value = null
	if (status.value === 'ready' && day !== previous) loadDay(day)
})

function reload(): Promise<void> {
	return load('quiet')
}

// --- Writes ---
// The control shows the server's answer, not an optimistic guess.
async function refreshStats(trackId: number): Promise<void> {
	try {
		const [entry] = await fetchStats(today.value, { trackId })
		if (entry) stats.value = stats.value.map(s => s.trackId === trackId ? entry : s)
	} catch {
		// The streak catches up on the next load
	}
}

function lockedClick(): void {
	showError(LOCKED_DAY_MESSAGE)
}

async function onSetYesNo(track: Track, value: boolean): Promise<void> {
	const day = shownDay.value
	try {
		const stored = await setYesNo(track.id, day, value)
		tickValues.value[tickKey(track.id, day)] = stored
	} catch (e) {
		await writeFailed(e, track.name, reload)
		return
	}
	refreshStats(track.id)
}

async function onIncrement(track: Track, delta: number): Promise<void> {
	const day = shownDay.value
	try {
		const stored = await incrementCounter(track.id, day, delta)
		tickValues.value[tickKey(track.id, day)] = stored
	} catch (e) {
		await writeFailed(e, track.name, reload)
		return
	}
	refreshStats(track.id)
}

function noteSaver(track: Track): (text: string) => Promise<boolean> {
	const day = shownDay.value
	return async (text: string) => {
		try {
			if (text === '') {
				await deleteNote(track.id, day)
				notes.value[tickKey(track.id, day)] = ''
			} else {
				notes.value[tickKey(track.id, day)] = await setNote(track.id, day, text)
			}
			return true
		} catch (e) {
			await writeFailed(e, track.name, reload, 'Could not save the note')
			return false
		}
	}
}

onMounted(() => {
	primaryColor.value = getPrimaryColor()
	load('initial')
})
</script>

<template>
	<div :class="$style.column">
		<header :class="$style.header">
			<div :class="$style.heading">
				<h2 :class="$style.title">
					{{ heading.title }}
				</h2>
				<div :class="$style.date">
					{{ heading.line }}
				</div>
			</div>
			<div :class="$style.actions">
				<PrivateToggle />
				<EditableDaysMenu v-if="showEditableDays" @changed="changedEditableDaysOn = shownDay" />
				<NcButton v-if="shownDay !== today" variant="secondary" @click="goToDay(today)">
					<template #icon>
						<NcIconSvgWrapper :path="MDI_CALENDAR_TODAY" />
					</template>
					Today
				</NcButton>
				<NcButton variant="tertiary"
					aria-label="Previous day"
					title="Previous day"
					@click="goToDay(addDays(shownDay, -1))">
					<template #icon>
						<NcIconSvgWrapper :path="MDI_CHEVRON_LEFT" directional />
					</template>
				</NcButton>
				<NcButton variant="tertiary"
					aria-label="Next day"
					title="Next day"
					:disabled="shownDay >= today"
					@click="goToDay(addDays(shownDay, 1))">
					<template #icon>
						<NcIconSvgWrapper :path="MDI_CHEVRON_RIGHT" directional />
					</template>
				</NcButton>
			</div>
		</header>

		<NcLoadingIcon v-if="view === 'loading'" :size="32" :class="$style.loading" />
		<NcEmptyContent v-else-if="view === 'empty'" name="No tracks yet">
			<template #description>
				Add one in <a href="#tracks" :class="$style.link">Tracks</a>.
			</template>
		</NcEmptyContent>
		<NcEmptyContent v-else-if="view === 'allPrivate'"
			name="All your tracks are private"
			description="Use Show private to show them." />
		<NcEmptyContent v-else-if="view === 'error'"
			name="Couldn't load your tracks"
			description="Check your connection, then try again.">
			<template #action>
				<NcButton @click="load('retry')">
					Try again
				</NcButton>
			</template>
		</NcEmptyContent>
		<ul v-else :class="$style.rows">
			<!-- Keyed by day too: a note field left open belongs to its day -->
			<TodayRow v-for="row in rows"
				:key="`${row.track.id}|${shownDay}`"
				:track="row.track"
				:value="row.value"
				:squares="row.squares"
				:stats="row.stats"
				:note="row.note"
				:locked="locked"
				:save-note="noteSaver(row.track)"
				@set-yes-no="(value) => onSetYesNo(row.track, value)"
				@increment="(delta) => onIncrement(row.track, delta)"
				@locked="lockedClick" />
		</ul>
	</div>
</template>

<style module>
/* Left-aligned, as Analytics lays out its page */
/* Rows start under the title, both clear of the navigation's toggle, which
   sits over the top left of the content (as on the Tracks page). The 660px
   column plus the 44px indent. */
.column {
	box-sizing: border-box;
	max-width: 704px;
	padding: 16px;
	padding-inline-start: 60px;
}

.header {
	display: flex;
	flex-wrap: wrap;
	align-items: flex-start;
	justify-content: space-between;
	gap: 8px;
	margin-block-end: 8px;
}

.heading {
	min-width: 0;
}

.title {
	margin: 0;
	font-size: 20px;
	font-weight: bold;
	line-height: 1.3;
}

.date {
	color: var(--color-text-maxcontrast);
}

.actions {
	flex: none;
	display: flex;
	align-items: center;
	gap: 4px;
	margin-inline-start: auto;
}

.loading {
	margin-block-start: 48px;
}

.link {
	text-decoration: underline;
}

.rows {
	display: flex;
	flex-direction: column;
}

@media (max-width: 1023px) {
	.column {
		padding: 12px;
		padding-inline-start: 56px;
	}
}
</style>
