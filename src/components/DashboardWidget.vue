<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import { fetchAllTicks, fetchTracks } from '../api.ts'
import { addDays, fmtDate, parseDateStr, todayStr, userLocale } from '../dates.ts'
import { fillFor, getPrimaryColor, levelFor, valueText } from '../heatmap.ts'
import { summariseStreaks, type StreakSummary } from '../streaks.ts'
import type { Tick, Track } from '../types.ts'

// Material Design Icons "refresh", copied in to avoid a dependency for one icon.
const MDI_REFRESH = 'M17.65,6.35C16.2,4.9 14.21,4 12,4A8,8 0 0,0 4,12A8,8 0 0,0 12,20C15.73,20 18.84,17.45 19.73,14H17.65C16.83,16.33 14.61,18 12,18A6,6 0 0,1 6,12A6,6 0 0,1 12,6C13.66,6 15.14,6.69 16.22,7.78L13,11H20V4L17.65,6.35Z'

// Quiet reload on focus once the data is older than this.
const STALE_AFTER_MS = 5 * 60 * 1000

const appUrl = generateUrl('/apps/tickbuddy/')
const journalUrl = appUrl + '#journal'
const settingsUrl = generateUrl('/settings/user/tickbuddy')

function analyticsUrl(trackId: number): string {
	return appUrl + '#analytics/' + trackId
}

// --- State ---
type LoadStatus = 'loading' | 'ready' | 'error'

const status = ref<LoadStatus>('loading')
const refreshing = ref(false)
const tracks = ref<Track[]>([])
const ticks = ref<Tick[]>([])
// The day the widget shows. Follows midnight and tab focus (see below), and
// everything date-dependent is computed from it.
const today = ref(todayStr())
const primaryColor = ref(getPrimaryColor())
let lastLoadedAt = 0
let loadSeq = 0
let inFlight = 0

const root = ref<HTMLElement | null>(null)
const rowsEl = ref<HTMLElement | null>(null)
const tooltipEl = ref<HTMLElement | null>(null)

// Private tracks never appear here, whatever the app's "Show private tracks" says.
const visibleTracks = computed(() => tracks.value.filter(t => !t.private))

const view = computed<'loading' | 'error' | 'empty' | 'allPrivate' | 'grid'>(() => {
	if (status.value === 'loading') return 'loading'
	if (status.value === 'error') return 'error'
	if (tracks.value.length === 0) return 'empty'
	if (visibleTracks.value.length === 0) return 'allPrivate'
	return 'grid'
})

// Per-track date → value, and each track's highest value ever (counter shading
// is relative to it, as in the Analytics heatmap).
const tickIndex = computed(() => {
	const byTrack = new Map<number, Map<string, number>>()
	const maxByTrack = new Map<number, number>()
	for (const t of ticks.value) {
		let byDate = byTrack.get(t.trackId)
		if (!byDate) {
			byDate = new Map()
			byTrack.set(t.trackId, byDate)
		}
		byDate.set(t.date, t.value)
		maxByTrack.set(t.trackId, Math.max(maxByTrack.get(t.trackId) ?? 0, t.value))
	}
	return { byTrack, maxByTrack }
})

// Rolling seven days, today first.
const days = computed(() => Array.from({ length: 7 }, (_, i) => addDays(today.value, -i)))

const rangeFormatter = new Intl.DateTimeFormat(userLocale, { day: 'numeric', month: 'long' })
const weekdayFormatter = new Intl.DateTimeFormat(userLocale, { weekday: 'narrow' })

// Always ends today, so no year is needed.
const rangeLabel = computed(() => rangeFormatter.formatRange(parseDateStr(days.value[6]), parseDateStr(days.value[0])))

const dayHeads = computed(() => days.value.map((ds, i) => {
	const d = parseDateStr(ds)
	return { date: ds, top: i === 0 ? 'Today' : weekdayFormatter.format(d), day: d.getDate() }
}))

interface Cell {
	trackId: number
	date: string
	value: number
	level: number
	fill: string
}

interface Row {
	track: Track
	isCounter: boolean
	cells: Cell[]
	summary: StreakSummary
	todayOpen: boolean
}

// Cells carry their track and date (also as data attributes), so an editable
// widget can add a click handler without reshaping anything.
const rows = computed<Row[]>(() => visibleTracks.value.map((track) => {
	const byDate = tickIndex.value.byTrack.get(track.id) ?? new Map<string, number>()
	const maxValue = tickIndex.value.maxByTrack.get(track.id) ?? 0
	const isCounter = track.type === 'counter'
	const cells = days.value.map((date) => {
		const value = byDate.get(date) ?? 0
		const level = levelFor(value, isCounter, maxValue)
		return { trackId: track.id, date, value, level, fill: fillFor(level, primaryColor.value) }
	})
	return {
		track,
		isCounter,
		cells,
		summary: summariseStreaks(byDate.keys(), today.value),
		todayOpen: cells[0].value <= 0,
	}
}))

function numText(row: Row): string {
	return row.summary.current ? String(row.summary.current.length) : '–'
}

function numTooltip(row: Row): string {
	const current = row.summary.current
	if (!current) return 'No ticks yet'
	if (row.summary.upToYesterday) return `${current.length}-day streak, up to yesterday`
	return `${current.isStreak ? 'Streak' : 'Break'} since ${fmtDate(current.from)}`
}

function cellLabel(row: Row, cell: Cell): string {
	return `${row.track.name}, ${fmtDate(cell.date)}: ${valueText(cell.value, row.isCounter)}`
}

// --- Loading ---
async function load(mode: 'initial' | 'refresh' | 'quiet'): Promise<void> {
	// A quiet reload never competes with one already running.
	if (mode === 'quiet' && inFlight > 0) return
	const seq = ++loadSeq
	inFlight++
	if (mode === 'refresh') refreshing.value = true
	today.value = todayStr()
	try {
		const [t, k] = await Promise.all([fetchTracks(), fetchAllTicks()])
		// A newer load started meanwhile: let it have the last word.
		if (seq !== loadSeq) return
		tracks.value = t
		ticks.value = k
		status.value = 'ready'
		lastLoadedAt = Date.now()
	} catch {
		if (seq !== loadSeq) return
		// Quiet reloads keep whatever is on screen.
		if (mode !== 'quiet') status.value = 'error'
	} finally {
		inFlight--
		if (seq === loadSeq) refreshing.value = false
	}
}

function refresh(): void {
	load('refresh')
}

// --- Following the day ---
// Timers are throttled in background tabs and paused during sleep, so the
// midnight timer is backed up by focus and visibility checks.
let midnightTimer: ReturnType<typeof setTimeout> | undefined

function armMidnightTimer(): void {
	clearTimeout(midnightTimer)
	const next = new Date()
	next.setHours(24, 0, 1, 0) // one second after the next local midnight
	midnightTimer = setTimeout(() => {
		if (!root.value?.isConnected) return
		checkDay()
		armMidnightTimer()
	}, next.getTime() - Date.now())
}

/** Move to a new day if it has changed; true when it did. */
function checkDay(): boolean {
	if (!root.value?.isConnected) return false
	const now = todayStr()
	if (now === today.value) return false
	today.value = now
	load('quiet')
	return true
}

function onWake(): void {
	if (!root.value?.isConnected) return
	armMidnightTimer()
	if (!checkDay() && Date.now() - lastLoadedAt > STALE_AFTER_MS) {
		load('quiet')
	}
}

function onVisibilityChange(): void {
	if (document.visibilityState === 'visible') onWake()
}

// --- Scrollbar compensation ---
// With classic (non-overlay) scrollbars the rows are narrower than the header
// by the scrollbar's width; pad the header by the same amount to keep the
// columns aligned.
const scrollbarWidth = ref(0)
let resizeObserver: ResizeObserver | null = null

function measureScrollbar(): void {
	const el = rowsEl.value
	scrollbarWidth.value = el ? el.offsetWidth - el.clientWidth : 0
}

watch(rowsEl, (el, old) => {
	if (old) resizeObserver?.unobserve(old)
	if (el) {
		resizeObserver?.observe(el)
		measureScrollbar()
	}
})

watch(rows, async () => {
	await nextTick()
	measureScrollbar()
})

// --- Tooltip ---
// One tooltip for the whole widget, outside the scrolling rows so it is never
// clipped. Positioned above its anchor and kept within the widget's width: the
// box shifts, the arrow stays on the anchor.
const tooltip = ref({
	visible: false,
	measuring: false,
	left: 0,
	top: 0,
	width: 0,
	arrowLeft: 0,
	title: '',
	lines: [] as string[],
	swatch: '',
})

async function showTooltip(anchor: HTMLElement, content: { title?: string, lines: string[], swatch?: string }): Promise<void> {
	const rootEl = root.value
	if (!rootEl) return
	const rootRect = rootEl.getBoundingClientRect()
	const rect = anchor.getBoundingClientRect()
	const centre = rect.left + rect.width / 2 - rootRect.left
	// Lay the box out at the left edge first so it can take up to the full
	// widget width, measure it, then move it into place at that width.
	tooltip.value = {
		visible: true,
		measuring: true,
		left: 0,
		top: rect.top - rootRect.top,
		width: 0,
		arrowLeft: 0,
		title: content.title ?? '',
		lines: content.lines,
		swatch: content.swatch ?? '',
	}
	await nextTick()
	const box = tooltipEl.value
	if (!box || !tooltip.value.visible) return
	const width = box.offsetWidth
	const left = Math.max(0, Math.min(rootEl.clientWidth - width, centre - width / 2))
	tooltip.value.left = left
	tooltip.value.width = width
	tooltip.value.arrowLeft = centre - left
	tooltip.value.measuring = false
}

function hideTooltip(): void {
	tooltip.value.visible = false
}

function showCellTooltip(event: Event, row: Row, cell: Cell): void {
	showTooltip(event.currentTarget as HTMLElement, {
		title: row.track.name,
		lines: [fmtDate(cell.date), valueText(cell.value, row.isCounter)],
		swatch: cell.fill,
	})
}

// Only for names the two-line clamp actually cuts off. The clamp is on the
// inner text span; the tooltip anchors to the link around it.
function showNameTooltip(event: Event, row: Row): void {
	const link = event.currentTarget as HTMLElement
	const text = link.firstElementChild as HTMLElement | null
	if (text && (text.scrollHeight > text.clientHeight + 1 || text.scrollWidth > text.clientWidth + 1)) {
		showTooltip(link, { lines: [row.track.name] })
	}
}

function showNumTooltip(event: Event, row: Row): void {
	showTooltip(event.currentTarget as HTMLElement, { lines: [numTooltip(row)] })
}

// --- Lifecycle ---
onMounted(() => {
	primaryColor.value = getPrimaryColor()
	resizeObserver = new ResizeObserver(measureScrollbar)
	if (rowsEl.value) resizeObserver.observe(rowsEl.value)
	armMidnightTimer()
	document.addEventListener('visibilitychange', onVisibilityChange)
	window.addEventListener('focus', onWake)
	load('initial')
})

onBeforeUnmount(() => {
	clearTimeout(midnightTimer)
	document.removeEventListener('visibilitychange', onVisibilityChange)
	window.removeEventListener('focus', onWake)
	resizeObserver?.disconnect()
	resizeObserver = null
})
</script>

<template>
	<div ref="root" :class="$style.root">
		<div :class="$style.topLine">
			<span :class="$style.range">{{ rangeLabel }}</span>
			<NcButton variant="tertiary"
				aria-label="Refresh"
				title="Refresh"
				:disabled="refreshing"
				@click="refresh">
				<template #icon>
					<NcLoadingIcon v-if="refreshing" :size="20" />
					<NcIconSvgWrapper v-else :path="MDI_REFRESH" />
				</template>
			</NcButton>
		</div>

		<div v-if="view === 'grid'" :class="$style.grid">
			<div :class="$style.headRow"
				:style="{ paddingInlineEnd: `${4 + scrollbarWidth}px` }"
				aria-hidden="true">
				<span />
				<span v-for="(head, i) in dayHeads"
					:key="head.date"
					:class="[$style.headDay, i === 0 && $style.headToday]">
					<span :class="i === 0 && $style.todayLabel">{{ head.top }}</span>
					<span>{{ head.day }}</span>
				</span>
				<span :class="$style.headNum">
					<span :class="$style.streakKey">Streak</span>
					<span>Break</span>
				</span>
			</div>
			<div ref="rowsEl" :class="$style.rows" @scroll="hideTooltip">
				<div v-for="row in rows" :key="row.track.id" :class="$style.row">
					<a :href="analyticsUrl(row.track.id)"
						:class="$style.name"
						@pointerenter="showNameTooltip($event, row)"
						@pointerleave="hideTooltip"
						@focus="showNameTooltip($event, row)"
						@blur="hideTooltip"><span :class="$style.nameText">{{ row.track.name }}</span></a>
					<span v-for="(cell, i) in row.cells"
						:key="cell.date"
						role="img"
						:aria-label="cellLabel(row, cell)"
						:data-track-id="cell.trackId"
						:data-date="cell.date"
						:class="[$style.square, i === 0 && $style.squareToday, i === 0 && row.todayOpen && $style.squareOpen]"
						:style="{ background: cell.fill }"
						@pointerenter="showCellTooltip($event, row, cell)"
						@pointerleave="hideTooltip" />
					<span :class="[
							$style.num,
							row.summary.current?.isStreak ? $style.numStreak : $style.numBreak,
							row.summary.upToYesterday && $style.numUpToYesterday,
						]"
						@pointerenter="showNumTooltip($event, row)"
						@pointerleave="hideTooltip">
						<span aria-hidden="true">{{ numText(row) }}</span>
						<span :class="$style.visuallyHidden">{{ numTooltip(row) }}</span>
					</span>
				</div>
			</div>
		</div>

		<div v-else :class="$style.message">
			<NcLoadingIcon v-if="view === 'loading'" :size="32" />
			<template v-else-if="view === 'empty'">
				<span :class="$style.messageTitle">No tracks yet</span>
				<span :class="$style.messageText">Add tracks in Tickbuddy settings</span>
			</template>
			<template v-else-if="view === 'allPrivate'">
				<span :class="$style.messageTitle">All your tracks are private</span>
				<span :class="$style.messageText">Private tracks never appear on the dashboard.</span>
			</template>
			<template v-else>
				<span :class="$style.messageTitle">Couldn't load your tracks</span>
				<span :class="$style.messageText">Check your connection, then try again.</span>
				<NcButton variant="tertiary" :disabled="refreshing" @click="refresh">
					Try again
				</NcButton>
			</template>
		</div>

		<div v-if="view !== 'loading'" :class="$style.footer">
			<NcButton v-if="view === 'empty' || view === 'allPrivate'"
				variant="secondary"
				:href="settingsUrl">
				Edit settings
			</NcButton>
			<NcButton v-else variant="secondary" :href="journalUrl">
				Edit journal
			</NcButton>
		</div>

		<div v-show="tooltip.visible"
			ref="tooltipEl"
			:class="$style.tooltip"
			:style="{
				left: `${tooltip.left}px`,
				top: `${tooltip.top}px`,
				width: tooltip.measuring ? undefined : `${tooltip.width}px`,
				visibility: tooltip.measuring ? 'hidden' : undefined,
				'--arrow-left': `${tooltip.arrowLeft}px`,
			}">
			<div v-if="tooltip.title" :class="$style.tooltipTitle">
				{{ tooltip.title }}
			</div>
			<div v-for="(line, i) in tooltip.lines"
				:key="i"
				:class="tooltip.swatch && i === tooltip.lines.length - 1 && $style.tooltipBody">
				<span v-if="tooltip.swatch && i === tooltip.lines.length - 1"
					:class="$style.tooltipSwatch"
					:style="{ backgroundImage: `linear-gradient(${tooltip.swatch}, ${tooltip.swatch})` }" />
				{{ line }}
			</div>
		</div>
	</div>
</template>

<style module>
.root {
	position: relative;
	display: flex;
	flex-direction: column;
	height: 100%;
	/* The panel's normal content height; caps the one-column layout, where the
	   dashboard lets panels grow. */
	max-height: 424px;
	font-size: 13px;
}

.topLine {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding-inline-start: 4px;
}

.range {
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}

/* Shared by the header and the rows. At 288px this leaves about 86px for names
   (less while the rows show a classic scrollbar). */
.headRow,
.row {
	display: grid;
	grid-template-columns: minmax(0, 1fr) repeat(7, 18px) 36px;
	column-gap: 4px;
	align-items: center;
	padding-inline: 4px;
}

.headRow {
	font-size: 11px;
	line-height: 1.2;
	color: var(--color-text-maxcontrast);
	padding-bottom: 4px;
}

.headDay {
	display: flex;
	flex-direction: column;
	align-items: center;
}

.headToday {
	color: var(--color-primary-element);
	font-weight: bold;
}

/* "Today" is wider than its column. Anchor it to the column's end so it
   overflows towards the (empty) name column above the track names, keeping
   every day column the same width; the day number stays centred. */
.todayLabel {
	align-self: flex-end;
	white-space: nowrap;
}

.headNum {
	display: flex;
	flex-direction: column;
	align-items: flex-end;
}

.streakKey {
	color: var(--color-primary-element);
}

/* Takes the space between the top line and the footer and centres the header
   and rows in it vertically. Once the rows outgrow it they shrink to fit and
   scroll, so the header stays put. */
.grid {
	flex: 1;
	min-height: 0;
	display: flex;
	flex-direction: column;
	justify-content: center;
}

.rows {
	min-height: 0;
	overflow-y: auto;
	/* No scrollbar-gutter: a reserved gutter costs classic scrollbars their full
	   width even when nothing scrolls. The header is padded by the measured
	   scrollbar width instead, so columns stay aligned either way. */
	scrollbar-width: thin;
	/* Invisible until the rows are hovered or hold keyboard focus. Its space
	   stays reserved, so nothing shifts when it appears. */
	scrollbar-color: transparent transparent;
}

.rows:hover,
.rows:focus-within {
	/* Nextcloud's own scrollbar colours, including the high-contrast themes. */
	scrollbar-color: var(--color-scrollbar);
}

/* Eight rows fit the 424px panel without scrolling: the rows area is about
   318px once the top line, header and footer are taken out. */
.row {
	height: 38px;
}

/* The hover box, styled like Nextcloud's own dashboard items. It reaches into
   the row's start padding so the text stays aligned with the range label. */
.name {
	display: flex;
	align-items: center;
	align-self: stretch;
	min-width: 0;
	margin-block: 2px;
	margin-inline-start: -4px;
	padding-inline: 4px;
	border-radius: var(--border-radius-large);
	color: var(--color-main-text);
	text-decoration: none;
}

.name:hover,
.name:focus-visible {
	background-color: var(--color-background-hover);
}

/* The two-line clamp lives here, not on .name: padding on the clamped box
   would show a sliver of a third line. */
.nameText {
	display: -webkit-box;
	-webkit-line-clamp: 2;
	line-clamp: 2;
	-webkit-box-orient: vertical;
	overflow: hidden;
	overflow-wrap: anywhere;
	hyphens: manual;
	line-height: 1.2;
}

.square {
	justify-self: center;
	width: 18px;
	height: 18px;
	border-radius: 3px;
}

/* Drawn inside the square, so today is the same size as the other days. */
.squareToday {
	outline: 1.5px solid color-mix(in srgb, var(--color-primary-element) 60%, transparent);
	outline-offset: -1.5px;
}

.squareOpen {
	outline-style: dashed;
}

.num {
	justify-self: end;
	font-size: 14px;
	font-variant-numeric: tabular-nums;
	font-weight: normal;
	text-align: end;
}

.numStreak {
	color: var(--color-primary-element);
}

.numBreak {
	color: var(--color-text-maxcontrast);
}

.numUpToYesterday {
	text-decoration: underline dashed 1px;
	text-underline-offset: 3px;
}

.visuallyHidden {
	position: absolute;
	width: 1px;
	height: 1px;
	overflow: hidden;
	clip-path: inset(50%);
	white-space: nowrap;
}

.message {
	flex: 1;
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	gap: 6px;
	padding-inline: 16px;
	text-align: center;
}

.messageTitle {
	font-weight: 600;
	font-size: 14px;
}

.messageText {
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}

.footer {
	display: flex;
	justify-content: center;
	padding-top: 8px;
}

/* The Analytics tooltip (mirroring the Chart.js default), anchored above its
   element with the arrow at --arrow-left. Lines only wrap when wider than the
   widget. */
.tooltip {
	position: absolute;
	z-index: 10;
	pointer-events: none;
	box-sizing: border-box;
	max-width: 100%;
	transform: translateY(calc(-100% - 7px));
	padding: 6px 8px;
	border-radius: 6px;
	background: rgba(0, 0, 0, 0.8);
	color: #fff;
	font-size: 12px;
	line-height: 1.2;
	overflow-wrap: anywhere;
}

.tooltip::after {
	content: '';
	position: absolute;
	top: 100%;
	/* Physical, like the box's own left: both are measured in screen pixels. */
	/* stylelint-disable-next-line csstools/use-logical */
	left: var(--arrow-left);
	transform: translateX(-50%);
	border: 5px solid transparent;
	border-top-color: rgba(0, 0, 0, 0.8);
}

.tooltipTitle {
	font-weight: bold;
	margin-bottom: 6px;
}

.tooltipBody {
	display: flex;
	align-items: center;
	gap: 6px;
	margin-top: 2px;
}

.tooltipSwatch {
	flex: none;
	width: 12px;
	height: 12px;
	border-radius: 2px;
	/* Flatten the cell's translucent fill over the page background, as in
	   Analytics, so the swatch matches the cell. */
	background-color: var(--color-main-background);
}
</style>
