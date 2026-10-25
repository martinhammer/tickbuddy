<script setup lang="ts">
import { computed, nextTick, onMounted, ref, watch } from 'vue'
import { showError } from '@nextcloud/dialogs'
import NcActionCaption from '@nextcloud/vue/components/NcActionCaption'
import NcActionRadio from '@nextcloud/vue/components/NcActionRadio'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDateTimePickerNative from '@nextcloud/vue/components/NcDateTimePickerNative'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcPopover from '@nextcloud/vue/components/NcPopover'
import { useIsMobile } from '@nextcloud/vue/composables/useIsMobile'
// Toasts are a Vue component with hashed CSS-module class names since @nextcloud/dialogs 7,
// so the server's own stylesheets cannot style them — the app must ship this itself.
import '@nextcloud/dialogs/style.css'
import { deleteNote, fetchNotes, fetchTicks, fetchTracks, incrementCounter, setNote, setYesNo } from '../api.ts'
import { addDays, fmtDayLabel, parseDateStr, toDateStr } from '../dates.ts'
import { EDITABLE_DAYS, EDITABLE_DAYS_LABELS, isEditable, LOCKED_DAY_MESSAGE } from '../editableDays.ts'
import { MDI_BOOK_OPEN_PAGE_VARIANT_OUTLINE, MDI_CALENDAR_OUTLINE, MDI_CALENDAR_TODAY, MDI_LOCK_OPEN_VARIANT_OUTLINE, MDI_LOCK_OUTLINE } from '../icons.ts'
import type { JournalActions } from '../journal.ts'
import { editableDays, journalLayout, loadPreferences, showPrivate } from '../preferences.ts'
import type { EditableDays, Note, Tick, Track } from '../types.ts'
import { useToday } from '../useToday.ts'
import { writeFailed } from '../writeErrors.ts'
import DaysDownGrid from './DaysDownGrid.vue'
import PrivateToggle from './PrivateToggle.vue'
import TracksDownGrid from './TracksDownGrid.vue'

// Edit journal and View journal in one: two layouts, Editable days, controls
// under the pointer, notes in the cells, and Go to date.

const emit = defineEmits<{
	'open-day': [date: string]
	'select-track': [trackId: number]
}>()

const PAGE_DAYS = 30

type LoadStatus = 'loading' | 'ready' | 'error'

const status = ref<LoadStatus>('loading')
const tracks = ref<Track[]>([])
// Tick values and notes by tickKey(), for the days loaded
const tickValues = ref<Record<string, number>>({})
const notes = ref<Record<string, string>>({})
// The first day shown, chosen with Go to date; null while it is today (and follows midnight)
const anchor = ref<string | null>(null)
const daysLoaded = ref(PAGE_DAYS)
const loadingMore = ref(false)
const goToDateOpen = ref(false)
// Today's row (Days down) or column (Tracks down) has scrolled out of view
const todayScrolledAway = ref(false)
const scroller = ref<HTMLElement | null>(null)
const isMobile = useIsMobile()
let loadSeq = 0
let inFlight = 0

const { today, loaded } = useToday(() => load('quiet'))

const firstDay = computed(() => anchor.value ?? today.value)

// Newest first, from the first day back
const dates = computed(() => Array.from({ length: daysLoaded.value }, (_, i) => addDays(firstDay.value, -i)))

const visibleTracks = computed(() => showPrivate.value ? tracks.value : tracks.value.filter(t => !t.private))

const view = computed<'loading' | 'error' | 'empty' | 'allPrivate' | 'grid'>(() => {
	if (status.value === 'loading') return 'loading'
	if (status.value === 'error') return 'error'
	if (tracks.value.length === 0) return 'empty'
	if (visibleTracks.value.length === 0) return 'allPrivate'
	return 'grid'
})

function tickKey(trackId: number, date: string): string {
	return `${trackId}|${date}`
}

// --- Toolbar ---
const layoutLabel = computed(() => journalLayout.value === 'daysDown' ? 'Switch to Tracks down' : 'Switch to Days down')
const editableDaysLabel = computed(() => `Editable days: ${EDITABLE_DAYS_LABELS[editableDays.value]}`)

function toggleLayout(): void {
	journalLayout.value = journalLayout.value === 'daysDown' ? 'tracksDown' : 'daysDown'
}

function setEditableDays(value: string | number): void {
	editableDays.value = value as EditableDays
}

// The picker's value: the first day as a local-midnight Date
const pickedDate = computed<Date>({
	get: () => parseDateStr(firstDay.value),
	set: (date) => {
		goToDateOpen.value = false
		if (!date || Number.isNaN(date.getTime())) return
		const day = toDateStr(date)
		goTo(day >= today.value ? null : day)
	},
})

const maxDate = computed(() => parseDateStr(today.value))

function goTo(day: string | null): void {
	anchor.value = day
	daysLoaded.value = PAGE_DAYS
	todayScrolledAway.value = false
	scroller.value?.scrollTo({ top: 0, left: 0 })
	load('page')
}

// Shown whenever today is out of view: after Go to date, or scrolled away from
const showTodayButton = computed(() => anchor.value !== null || todayScrolledAway.value)

function backToToday(): void {
	if (anchor.value !== null) {
		goTo(null)
		return
	}
	scroller.value?.scrollTo({ top: 0, left: 0 })
}

// Today is out of view once it has gone wholly under the sticky header row
// (Days down) or the sticky name column (Tracks down). Measured on scroll rather
// than with an IntersectionObserver, which can't see what a sticky cell covers.
function checkTodayInView(): void {
	const el = scroller.value
	const todayEl = el?.querySelector('[data-today]')
	const sticky = el?.querySelector('[data-sticky]')
	if (!el || !todayEl || !sticky) {
		todayScrolledAway.value = false
		return
	}
	const t = todayEl.getBoundingClientRect()
	const s = sticky.getBoundingClientRect()
	if (journalLayout.value === 'daysDown') {
		todayScrolledAway.value = t.bottom <= s.bottom + 1
	} else if (getComputedStyle(el).direction === 'rtl') {
		todayScrolledAway.value = t.left >= s.left - 1
	} else {
		todayScrolledAway.value = t.right <= s.right + 1
	}
}

// --- Loading ---
function applyRange(ticks: Tick[], rangeNotes: Note[], replace: boolean): void {
	const values = replace ? {} : { ...tickValues.value }
	const noteTexts = replace ? {} : { ...notes.value }
	for (const tick of ticks) values[tickKey(tick.trackId, tick.date)] = tick.value
	for (const note of rangeNotes) noteTexts[tickKey(note.trackId, note.date)] = note.text
	tickValues.value = values
	notes.value = noteTexts
}

// Everything shown, again. 'page' shows the spinner, as a first load does.
async function load(mode: 'initial' | 'page' | 'quiet' | 'retry'): Promise<void> {
	// A quiet reload never competes with one already running.
	if (mode === 'quiet' && inFlight > 0) return
	const seq = ++loadSeq
	inFlight++
	if (mode !== 'quiet') status.value = 'loading'
	const from = dates.value[dates.value.length - 1]
	const to = dates.value[0]
	try {
		const [t, k, n] = await Promise.all([fetchTracks(), fetchTicks(from, to), fetchNotes(from, to), loadPreferences()])
		// A newer load started meanwhile: let it have the last word.
		if (seq !== loadSeq) return
		tracks.value = t
		applyRange(k, n, true)
		status.value = 'ready'
		loaded()
	} catch {
		if (seq !== loadSeq) return
		// Quiet reloads keep whatever is on screen.
		if (mode !== 'quiet') status.value = 'error'
	} finally {
		inFlight--
	}
}

// The next 30 older days, as the end of the grid comes into view
async function loadMore(): Promise<void> {
	if (loadingMore.value || status.value !== 'ready') return
	loadingMore.value = true
	const seq = loadSeq
	const last = dates.value[dates.value.length - 1]
	const from = addDays(last, -PAGE_DAYS)
	const to = addDays(last, -1)
	try {
		const [t, k, n] = await Promise.all([fetchTracks(), fetchTicks(from, to), fetchNotes(from, to)])
		// A full load started meanwhile and replaces everything
		if (seq !== loadSeq) return
		tracks.value = t
		applyRange(k, n, false)
		daysLoaded.value += PAGE_DAYS
	} catch {
		// The sentinel tries again when it next comes into view
	} finally {
		loadingMore.value = false
	}
}

function reload(): Promise<void> {
	return load('quiet')
}

// --- Cells ---
async function onSetYesNo(track: Track, date: string, value: boolean, input: HTMLInputElement | null): Promise<void> {
	try {
		tickValues.value[tickKey(track.id, date)] = await setYesNo(track.id, date, value)
	} catch (e) {
		// The native checkbox flipped itself on click. The ticks are unchanged, so the
		// rendered `:checked` prop is unchanged too and Vue patches nothing — the box would
		// stay visually flipped against the stored value. Put it back by hand.
		if (input) input.checked = (tickValues.value[tickKey(track.id, date)] ?? 0) > 0
		await writeFailed(e, track.name, reload)
	}
}

async function onIncrement(track: Track, date: string, delta: number): Promise<void> {
	try {
		tickValues.value[tickKey(track.id, date)] = await incrementCounter(track.id, date, delta)
	} catch (e) {
		await writeFailed(e, track.name, reload)
	}
}

async function onSaveNote(track: Track, date: string, text: string): Promise<boolean> {
	try {
		if (text === '') {
			await deleteNote(track.id, date)
			notes.value[tickKey(track.id, date)] = ''
		} else {
			notes.value[tickKey(track.id, date)] = await setNote(track.id, date, text)
		}
		return true
	} catch (e) {
		await writeFailed(e, track.name, reload, 'Could not save the note')
		return false
	}
}

const actions: JournalActions = {
	valueOf: (trackId, date) => tickValues.value[tickKey(trackId, date)] ?? 0,
	noteOf: (trackId, date) => notes.value[tickKey(trackId, date)] ?? '',
	isLocked: date => !isEditable(date, today.value, editableDays.value),
	setYesNo: onSetYesNo,
	increment: onIncrement,
	saveNote: onSaveNote,
	lockedClick: () => { showError(LOCKED_DAY_MESSAGE) },
	openDay: date => emit('open-day', date),
	openTrack: trackId => emit('select-track', trackId),
	dayLabel: date => fmtDayLabel(date, today.value),
}

// Switching layout keeps the first day, and starts at it
watch(journalLayout, async () => {
	await nextTick()
	scroller.value?.scrollTo({ top: 0, left: 0 })
	checkTodayInView()
})

onMounted(() => {
	load('initial')
})
</script>

<template>
	<div :class="$style.journal">
		<div :class="$style.toolbar">
			<div :class="$style.toolbarStart">
				<NcPopover v-model:shown="goToDateOpen" popup-role="dialog">
					<template #trigger>
						<NcButton variant="tertiary"
							:aria-label="isMobile ? 'Go to date' : undefined"
							:title="isMobile ? 'Go to date' : undefined">
							<template #icon>
								<NcIconSvgWrapper :path="MDI_CALENDAR_OUTLINE" />
							</template>
							<template v-if="!isMobile">
								Go to date
							</template>
						</NcButton>
					</template>
					<!-- NcDateTimePickerNative renders an NcInputField, whose root is width: 100% -->
					<div :class="$style.datePicker">
						<NcDateTimePickerNative v-model="pickedDate"
							type="date"
							label="Go to date"
							:max="maxDate" />
					</div>
				</NcPopover>
				<NcButton v-if="showTodayButton" variant="secondary" @click="backToToday">
					<template #icon>
						<NcIconSvgWrapper :path="MDI_CALENDAR_TODAY" />
					</template>
					Today
				</NcButton>
			</div>
			<div :class="$style.toolbarEnd">
				<PrivateToggle />
				<NcButton variant="tertiary"
					:aria-label="layoutLabel"
					:title="layoutLabel"
					@click="toggleLayout">
					<template #icon>
						<NcIconSvgWrapper :path="MDI_BOOK_OPEN_PAGE_VARIANT_OUTLINE" />
					</template>
				</NcButton>
				<NcActions :aria-label="editableDaysLabel" :title="editableDaysLabel">
					<template #icon>
						<NcIconSvgWrapper :path="editableDays === 'allDays' ? MDI_LOCK_OPEN_VARIANT_OUTLINE : MDI_LOCK_OUTLINE" />
					</template>
					<NcActionCaption name="Editable days" />
					<NcActionRadio v-for="option in EDITABLE_DAYS"
						:key="option"
						name="tickbuddy-editable-days"
						:value="option"
						:model-value="editableDays"
						@update:model-value="setEditableDays">
						{{ EDITABLE_DAYS_LABELS[option] }}
					</NcActionRadio>
				</NcActions>
			</div>
		</div>

		<div ref="scroller" :class="$style.content" @scroll.passive="checkTodayInView">
			<NcLoadingIcon v-if="view === 'loading'" :size="32" :class="$style.loading" />
			<NcEmptyContent v-else-if="view === 'empty'" name="No tracks yet">
				<template #description>
					Add one in <a href="#tracks" :class="$style.link">Tracks</a>.
				</template>
			</NcEmptyContent>
			<NcEmptyContent v-else-if="view === 'allPrivate'"
				name="All your tracks are private"
				description="Use the eye button to show them." />
			<NcEmptyContent v-else-if="view === 'error'"
				name="Couldn't load your tracks"
				description="Check your connection, then try again.">
				<template #action>
					<NcButton @click="load('retry')">
						Try again
					</NcButton>
				</template>
			</NcEmptyContent>
			<DaysDownGrid v-else-if="journalLayout === 'daysDown'"
				:tracks="visibleTracks"
				:dates="dates"
				:today="today"
				:actions="actions"
				@more="loadMore" />
			<TracksDownGrid v-else
				:tracks="visibleTracks"
				:dates="dates"
				:today="today"
				:actions="actions"
				@more="loadMore" />
		</div>
	</div>
</template>

<style module>
/* The toolbar stays put; the grid scrolls under it, both ways, with its
   header row and first column sticky */
.journal {
	display: flex;
	flex-direction: column;
	height: 100%;
}

.toolbar {
	flex: none;
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 8px;
	padding-block: 8px;
	/* The navigation's toggle sits over the top left of the content */
	padding-inline: 52px 8px;
}

.toolbarStart,
.toolbarEnd {
	display: flex;
	align-items: center;
	gap: 4px;
}

.datePicker {
	width: 200px;
	padding: 12px;
}

.content {
	flex: 1;
	min-height: 0;
	overflow: auto;
	padding-inline: 8px;
}

.loading {
	margin-block-start: 48px;
}

.link {
	text-decoration: underline;
}
</style>
