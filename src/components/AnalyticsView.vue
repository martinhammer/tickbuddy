<script setup lang="ts">
import { ref, computed, nextTick, onMounted, watch } from 'vue'
import { getFirstDay } from '@nextcloud/l10n'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcButton from '@nextcloud/vue/components/NcButton'
import type { ChartOptions } from 'chart.js'
import { Line, PolarArea } from 'vue-chartjs'
import {
	Chart as ChartJS,
	CategoryScale,
	LinearScale,
	RadialLinearScale,
	PointElement,
	LineElement,
	ArcElement,
	Filler,
	Legend,
	SubTitle,
	Tooltip,
} from 'chart.js'
import zoomPlugin from 'chartjs-plugin-zoom'
import { fetchAllTicks, fetchStats, fetchTracks } from '../api.ts'
import { fmtDate, parseDateStr, toDateStr, todayStr, userLocale } from '../dates.ts'
import { EMPTY_FILL, LEVEL_ALPHA, fillFor, getPrimaryColor, hexToRgba, levelFor, valueText } from '../heatmap.ts'
import { loadPreferences, showPrivate } from '../preferences.ts'
import type { Tick, Track, TrackStats } from '../types.ts'

ChartJS.register(CategoryScale, LinearScale, RadialLinearScale, PointElement, LineElement, ArcElement, Filler, Legend, SubTitle, Tooltip, zoomPlugin)

const props = defineProps<{
	trackId?: number | null
}>()

const emit = defineEmits<{
	/** The track shown, and whether the picker lists it */
	'update:trackId': [trackId: number | null, listed: boolean]
}>()

// Active and archived tracks. The picker offers active ones (private ones only
// while shown); an archived or hidden private one is shown only when the route
// names it.
const allTracks = ref<Track[]>([])
const tracks = computed(() => allTracks.value.filter(t => t.archivedAt === null))
const allTicks = ref<Tick[]>([])
// The selected track's stats, with runs, from GET /api/stats.
const trackStats = ref<TrackStats | null>(null)
const selectedTrack = ref<{ id: number; label: string } | null>(null)
const loading = ref(false)

const trackOptions = computed(() => {
	const list = showPrivate.value ? tracks.value : tracks.value.filter(t => !t.private)
	return list.map(t => ({ id: t.id, label: t.name }))
})

// A track named by the route that the picker doesn't list (archived, or private
// while private tracks are hidden, from a row's ⋯ menu on Tracks): the picker's
// value, but not an option.
const namedOption = computed(() => {
	const track = allTracks.value.find(t => t.id === props.trackId)
	return track && !trackOptions.value.some(o => o.id === track.id) ? { id: track.id, label: track.name } : null
})

// The track to show when nothing valid is selected: the one handed in from a
// journal column header or named by the route if it is available, else the first one.
function resolveTrack(options: { id: number; label: string }[]) {
	return options.find(o => o.id === props.trackId) ?? namedOption.value ?? options[0] ?? null
}

function isShowable(id: number): boolean {
	return trackOptions.value.some(o => o.id === id) || namedOption.value?.id === id
}

// The URL can name a different track while this view stays mounted (history
// traversal, or a hand-edited hash), so follow the prop when it changes.
watch(() => props.trackId, (trackId) => {
	if (trackId == null || selectedTrack.value?.id === trackId) return
	const option = trackOptions.value.find(o => o.id === trackId)
		?? (namedOption.value?.id === trackId ? namedOption.value : undefined)
	if (option) {
		selectedTrack.value = option
	}
})

// When available tracks change (e.g. private toggle), ensure selection is still valid
watch(trackOptions, (options) => {
	if (!selectedTrack.value || !isShowable(selectedTrack.value.id)) {
		selectedTrack.value = resolveTrack(options)
	}
})

// The selected track's archive date, null while active. Its charts end on
// that day, as the server's stats do.
const archivedAt = computed(() => {
	if (!selectedTrack.value) return null
	return allTracks.value.find(t => t.id === selectedTrack.value!.id)?.archivedAt ?? null
})

const archivedLine = computed(() => archivedAt.value === null
	? null
	: `Archived on ${new Intl.DateTimeFormat(userLocale, { day: 'numeric', month: 'long', year: 'numeric' }).format(parseDateStr(archivedAt.value))}`)

const trackTicks = computed(() => {
	if (!selectedTrack.value) return []
	const end = archivedAt.value
	return allTicks.value
		.filter(t => t.trackId === selectedTrack.value!.id && (end === null || t.date <= end))
		.sort((a, b) => a.date.localeCompare(b.date))
})

const selectedTrackType = computed(() => {
	if (!selectedTrack.value) return 'boolean'
	return allTracks.value.find(t => t.id === selectedTrack.value!.id)?.type ?? 'boolean'
})

// --- Primary colour extraction ---
const primaryColor = ref(getPrimaryColor())

onMounted(() => {
	primaryColor.value = getPrimaryColor()
})

// --- Summary stats ---
// Worked out by the server (GET /api/stats, StatsService), so every client
// shows the same numbers. Ticks dated after today don't count.
const totalCount = computed(() => trackStats.value?.total ?? 0)

const weeklyMean = computed(() => trackStats.value?.weeklyMean ?? 0)

// The weeks the mean is taken over: from the first tick to today, counting
// both days, floored at one week.
const spanWeeks = computed(() => weeklyMean.value > 0 ? totalCount.value / weeklyMean.value : 0)

const twoWeekTrend = computed(() => {
	const stats = trackStats.value
	if (!stats) return 0
	const { last7, previous7, peak7 } = stats

	if (last7 === 0 && previous7 === 0) return 0

	// Scale by the peak rolling 7-day sum across history (floored at 7 so
	// sparse boolean tracks still calibrate sensibly — 7 = max possible per week).
	const scale = Math.max(peak7, 7)

	const ratio = Math.max(-1, Math.min(1, (last7 - previous7) / scale))
	const step = Math.round(ratio * 4) // -4..+4
	return step * 22.5
})

// --- Streaks ---
// The rule lives on the server (GET /api/stats, StatsService) so the dashboard
// widget shows the same numbers: an open today never ends a streak, it counts
// up to yesterday.
const streakData = computed(() => {
	const stats = trackStats.value
	const current = stats?.current ?? null
	const longestStreak = stats?.longestStreak ?? null
	const longestBreak = stats?.longestBreak ?? null
	return {
		currentLength: current?.length ?? 0,
		currentIsStreak: current?.isStreak ?? true,
		currentFrom: current?.from ?? '',
		currentTo: current?.to ?? '',
		upToYesterday: stats?.upToYesterday ?? false,
		longestStreak: longestStreak?.length ?? 0,
		longestStreakFrom: longestStreak?.from ?? '',
		longestStreakTo: longestStreak?.to ?? '',
		longestBreak: longestBreak?.length ?? 0,
		longestBreakFrom: longestBreak?.from ?? '',
		longestBreakTo: longestBreak?.to ?? '',
	}
})

// Format a run's date range for display under a streak stat, e.g.
// "Fri 1 Jan 2025 - Wed 15 Mar 2025" (or a single date when from === to).
function formatStreakRange(from: string, to: string): string {
	if (!from || !to) return ''
	return from === to ? fmtDate(from) : `${fmtDate(from)} - ${fmtDate(to)}`
}

// The current run ends today, or yesterday for a streak while today is still
// open, so show only its start, plus a hint in the yesterday case.
const currentRunTooltip = computed(() => {
	const { currentFrom: from, currentTo: to, upToYesterday } = streakData.value
	if (!from || !to) return []
	if (upToYesterday) {
		return from === to
			? ['Yesterday', 'Today not ticked yet']
			: [`Since ${fmtDate(from)}`, 'Up to yesterday, today not ticked yet']
	}
	return [from === to ? 'Today' : `Since ${fmtDate(from)}`]
})

// --- Stat card tooltips (custom, styled to match the heatmap tooltip) ---
// Each stats row positions its own tooltip (the rows are the relative
// containers), so the shared state records which row is currently showing one.
type StatRow = 'summary' | 'streaks'
const summaryRow = ref<HTMLElement | null>(null)
const streaksRow = ref<HTMLElement | null>(null)
const statTooltip = ref<{ visible: boolean; row: StatRow; x: number; y: number; lines: string[] }>({
	visible: false, row: 'summary', x: 0, y: 0, lines: [],
})

function showStatTooltip(row: StatRow, lines: string | string[], event: PointerEvent) {
	const wrap = row === 'summary' ? summaryRow.value : streaksRow.value
	const text = (Array.isArray(lines) ? lines : [lines]).filter(Boolean)
	if (!wrap || text.length === 0) return
	const rect = wrap.getBoundingClientRect()
	statTooltip.value = {
		visible: true,
		row,
		x: event.clientX - rect.left,
		y: event.clientY - rect.top,
		lines: text,
	}
}

function hideStatTooltip() {
	statTooltip.value.visible = false
}

// --- Summary card tooltip contents ---
const totalTooltip = computed(() => {
	const { firstTick, lastTick } = trackStats.value ?? {}
	if (!firstTick || !lastTick) return []
	return [
		`Oldest entry: ${fmtDate(firstTick)}`,
		`Newest entry: ${fmtDate(lastTick)}`,
	]
})

const weeklyMeanTooltip = computed(() => {
	const firstTick = trackStats.value?.firstTick
	if (!firstTick) return []
	const weeks = Math.round(spanWeeks.value)
	return [
		`Total ${totalCount.value} across ${weeks} ${weeks === 1 ? 'week' : 'weeks'}`,
		`Since ${fmtDate(firstTick)}`,
	]
})

const trendTooltip = computed(() => [
	`Last 7 days: ${trackStats.value?.last7 ?? 0}`,
	`Previous 7 days: ${trackStats.value?.previous7 ?? 0}`,
])

// --- Streaks/Breaks series ---
// The alternating sequence of streak (positive) and break (negative) run
// lengths from the stats' runs. Each run becomes one
// point on the chart; streaks point up, breaks point down.
const streaksBreaksChart = ref<any>(null)
function resetStreaksBreaksZoom() {
	streaksBreaksChart.value?.chart?.resetZoom()
}

const streaksBreaksData = computed(() => {
	const runs = trackStats.value?.runs ?? []
	if (runs.length === 0) return null

	const values = runs.map(r => r.isStreak ? r.length : -r.length)
	const labels = runs.map((_, i) => String(i + 1))
	const color = primaryColor.value

	const options: ChartOptions<'line'> = {
		responsive: true,
		maintainAspectRatio: false,
		plugins: {
			legend: { display: false },
			tooltip: {
				callbacks: {
					title: (items: any[]) => {
						const r = runs[items[0].dataIndex]
						return r.isStreak ? 'Streak' : 'Break'
					},
					label: (item: any) => {
						const r = runs[item.dataIndex]
						const dates = r.from === r.to ? r.from : `${r.from} – ${r.to}`
						return `${r.length} day(s) ${dates}`
					},
				},
			},
			zoom: {
				// modifierKey is omitted: the plugin already defaults it to null,
				// i.e. panning needs no modifier key held.
				pan: {
					enabled: true,
					mode: 'x',
				},
				zoom: {
					wheel: { enabled: true, speed: 0.1 },
					pinch: { enabled: true },
					drag: { enabled: false },
					mode: 'x',
				},
				limits: {
					x: { min: 'original', max: 'original', minRange: 3 },
				},
			},
		},
		scales: {
			y: {
				position: 'left',
				ticks: {
					precision: 0,
					callback: (v: number | string) => Math.abs(Number(v)),
				},
			},
			x: { display: false },
		},
	}

	return {
		data: {
			labels,
			datasets: [{
				data: values,
				fill: 'origin',
				backgroundColor: hexToRgba(color, 0.3),
				borderColor: color,
				borderWidth: 2,
				pointRadius: runs.length > 80 ? 0 : 3,
				pointHitRadius: 15,
				pointHoverRadius: 5,
				pointBackgroundColor: color,
				tension: 0.3,
			}],
		},
		options,
	}
})

// Chart.js plugin: draw each polar-area slice's label just outside its arc.
const polarSliceLabelsPlugin = {
	id: 'polarSliceLabels',
	afterDatasetsDraw(chart: any) {
		if (chart.config.type !== 'polarArea') return
		const meta = chart.getDatasetMeta(0)
		const labels: string[] = chart.data.labels ?? []
		const scale = chart.scales.r
		if (!scale) return
		const ctx = chart.ctx
		const cx = scale.xCenter
		const cy = scale.yCenter
		const outerR = scale.drawingArea + 12
		ctx.save()
		ctx.font = '11px sans-serif'
		ctx.fillStyle = ChartJS.defaults.color as string
		ctx.textBaseline = 'middle'
		for (let i = 0; i < meta.data.length; i++) {
			const arc: any = meta.data[i]
			const label = labels[i]
			if (!arc || !label) continue
			const mid = (arc.startAngle + arc.endAngle) / 2
			const x = cx + Math.cos(mid) * outerR
			const y = cy + Math.sin(mid) * outerR
			ctx.textAlign = Math.cos(mid) < -0.1 ? 'end' : Math.cos(mid) > 0.1 ? 'start' : 'center'
			ctx.fillText(label, x, y)
		}
		ctx.restore()
	},
}

// --- Polar charts (days of week & months across all years) ---
// Shade slices so highest value = darkest, reinforcing the slice radius and
// matching the calendar heatmap's Less → More direction.
function polarShades(values: number[]): string[] {
	const base = primaryColor.value
	const min = Math.min(...values)
	const max = Math.max(...values)
	const lightest = 0.2 // lowest value
	const darkest = 0.85 // highest value
	return values.map((v) => {
		const t = max === min ? 0.5 : (v - min) / (max - min)
		return hexToRgba(base, lightest + (darkest - lightest) * t)
	})
}

const daysOfWeekPolar = computed(() => {
	const ticks = trackTicks.value
	if (ticks.length === 0) return null

	const counts = [0, 0, 0, 0, 0, 0, 0] // 0=Mon..6=Sun
	for (const t of ticks) {
		const jsDay = new Date(t.date + 'T00:00:00').getDay()
		const idx = jsDay === 0 ? 6 : jsDay - 1
		counts[idx] += t.value
	}

	const formatter = new Intl.DateTimeFormat(userLocale, { weekday: 'short' })
	const dayNames: string[] = []
	for (let i = 0; i < 7; i++) {
		const d = new Date(2024, 0, 1 + i) // 2024-01-01 is Monday
		dayNames.push(formatter.format(d))
	}

	const startDay = localeFirstDay === 0 ? 6 : localeFirstDay - 1
	const labels: string[] = []
	const values: number[] = []
	for (let i = 0; i < 7; i++) {
		const idx = (startDay + i) % 7
		labels.push(dayNames[idx])
		values.push(counts[idx])
	}

	return {
		data: {
			labels,
			datasets: [{
				data: values,
				backgroundColor: polarShades(values),
				borderColor: primaryColor.value,
				borderWidth: 1,
			}],
		},
		options: {
			responsive: true,
			maintainAspectRatio: false,
			layout: { padding: 24 },
			plugins: {
				tooltip: { enabled: true },
				legend: { display: false },
			},
			scales: {
				r: { ticks: { display: false }, beginAtZero: true },
			},
		},
	}
})

const monthsPolar = computed(() => {
	const ticks = trackTicks.value
	if (ticks.length === 0) return null

	const counts = new Array(12).fill(0)
	for (const t of ticks) {
		const m = parseInt(t.date.slice(5, 7)) - 1
		counts[m] += t.value
	}

	const formatter = new Intl.DateTimeFormat(userLocale, { month: 'short' })
	const labels: string[] = []
	for (let i = 0; i < 12; i++) {
		labels.push(formatter.format(new Date(2024, i, 1)))
	}

	return {
		data: {
			labels,
			datasets: [{
				data: counts,
				backgroundColor: polarShades(counts),
				borderColor: primaryColor.value,
				borderWidth: 1,
			}],
		},
		options: {
			responsive: true,
			maintainAspectRatio: false,
			layout: { padding: 24 },
			plugins: {
				tooltip: { enabled: true },
				legend: { display: false },
			},
			scales: {
				r: { ticks: { display: false }, beginAtZero: true },
			},
		},
	}
})

// --- Time series helpers ---
function buildTimeSeries(
	bucketFn: (date: string) => string,
	labelFn: (key: string) => string,
): { data: any; options: any } | null {
	const ticks = trackTicks.value
	if (ticks.length === 0) return null

	const buckets = new Map<string, number>()
	for (const t of ticks) {
		const key = bucketFn(t.date)
		buckets.set(key, (buckets.get(key) ?? 0) + t.value)
	}

	// Fill gaps: generate all bucket keys between first and last
	const sortedKeys = [...buckets.keys()].sort()
	const allKeys: string[] = []
	if (sortedKeys.length > 0) {
		// For weeks/months/quarters/years we just use sorted unique keys
		// Gap filling is bucket-type specific, keep it simple
		allKeys.push(...sortedKeys)
	}

	const color = primaryColor.value
	return {
		data: {
			labels: allKeys.map(labelFn),
			datasets: [{
				data: allKeys.map(k => buckets.get(k) ?? 0),
				fill: true,
				backgroundColor: hexToRgba(color, 0.2),
				borderColor: color,
				borderWidth: 2,
				pointRadius: allKeys.length > 52 ? 0 : 3,
				pointHitRadius: 15,
				pointHoverRadius: 5,
				pointBackgroundColor: color,
				tension: 0.3,
			}],
		},
		options: {
			responsive: true,
			maintainAspectRatio: false,
			plugins: {
				tooltip: { enabled: true },
				legend: { display: false },
			},
			scales: {
				y: { beginAtZero: true, ticks: { precision: 0 } },
				x: {
					ticks: {
						maxRotation: 45,
						autoSkip: true,
						maxTicksLimit: 20,
					},
				},
			},
		},
	}
}

// First day of week (0=Sun..6=Sat, JS convention). Nextcloud computes this
// server-side from the account locale and injects it as window.firstDay, so
// getFirstDay() returns the same value in every browser. Calling Intl week-info
// directly does not: Chromium exposes it as the `weekInfo` property while
// Firefox only ships the `getWeekInfo()` method, and the two disagree on
// region-less locales — which is why the grid started on different days.
const localeFirstDay: number = getFirstDay()

// Short weekday names indexed by JS getDay() (0=Sun..6=Sat). 2024-01-07 was a Sunday.
const weekdayShortNames: string[] = (() => {
	const formatter = new Intl.DateTimeFormat(userLocale, { weekday: 'short' })
	const names: string[] = []
	for (let i = 0; i < 7; i++) names.push(formatter.format(new Date(2024, 0, 7 + i)))
	return names
})()

// ISO 8601 week: returns "YYYY-Wnn" (weeks start on Monday)
function isoWeek(dateStr: string): string {
	const d = new Date(dateStr + 'T00:00:00')
	const dayNum = d.getDay() || 7 // convert Sun=0 to 7
	d.setDate(d.getDate() + 4 - dayNum) // nearest Thursday
	const year = d.getFullYear()
	const jan1 = new Date(year, 0, 1)
	const weekNo = Math.ceil((((d.getTime() - jan1.getTime()) / 86400000) + 1) / 7)
	return `${year}-W${String(weekNo).padStart(2, '0')}`
}

const weeksChart = computed(() => buildTimeSeries(
	(date) => isoWeek(date),
	(key) => {
		const [y, w] = key.split('-W')
		return `${y} W${parseInt(w)}`
	},
))

const monthsChart = computed(() => buildTimeSeries(
	(date) => date.slice(0, 7), // YYYY-MM
	(key) => {
		const [y, m] = key.split('-')
		const d = new Date(parseInt(y), parseInt(m) - 1, 1)
		return d.toLocaleDateString(userLocale, { year: 'numeric', month: 'short' })
	},
))

const quartersChart = computed(() => buildTimeSeries(
	(date) => {
		const m = parseInt(date.slice(5, 7))
		const q = Math.ceil(m / 3)
		return `${date.slice(0, 4)}-Q${q}`
	},
	(key) => key,
))

const yearsChart = computed(() => buildTimeSeries(
	(date) => date.slice(0, 4),
	(key) => key,
))

// --- Calendar heatmap ---
// GitHub-contributions-style grid: one column per week, one row per weekday.
// The window always covers at least the trailing 365 days and extends further
// back if the track has older data; the container scrolls horizontally and is
// pinned to the right (most recent) edge.
const CELL_SIZE = 11
const CELL_GAP = 3
const CELL_PITCH = CELL_SIZE + CELL_GAP
// Weekday labels live in a fixed column outside the scroller so they stay put;
// the scrolling grid keeps just a small left pad.
const DAY_LABEL_COL = 34
const GRID_LEFT_PAD = 2
// Month labels are slanted, so the top gutter has to fit their diagonal height
// and the width needs slack for the last label to lean past the grid.
// The Chart.js axes render around 20°, not their `maxRotation: 45` ceiling —
// Chart.js uses the shallowest slant that keeps labels from colliding.
const MONTH_LABEL_ANGLE = 25
// Nudge right so the label's foot clears the boundary with the previous week.
const MONTH_LABEL_X_OFFSET = 3
const HEATMAP_TOP_GUTTER = 42
const MONTH_LABEL_BASELINE = HEATMAP_TOP_GUTTER - 6
const HEATMAP_RIGHT_PAD = 52

interface HeatmapCell {
	x: number
	y: number
	fill: string
	dateLabel: string
	valueText: string
}

const heatmapData = computed(() => {
	const ticks = trackTicks.value
	if (ticks.length === 0) return null

	const byDate = new Map<string, number>()
	for (const t of ticks) byDate.set(t.date, t.value)

	// Ends today, or on an archived track's archive date
	const today = archivedAt.value === null ? new Date() : parseDateStr(archivedAt.value)
	today.setHours(0, 0, 0, 0)

	// Trailing 365 days, extended back to the first tick if history is longer.
	const windowStart = new Date(today)
	windowStart.setDate(windowStart.getDate() - 364)
	const firstTick = parseDateStr(ticks[0].date)
	const start = firstTick < windowStart ? new Date(firstTick) : new Date(windowStart)
	// Align to the locale's first weekday so every column is a full week.
	while (start.getDay() !== localeFirstDay) start.setDate(start.getDate() - 1)

	const isCounter = selectedTrackType.value === 'counter'
	// The stats' maxValue ignores future-dated ticks, so the widget and this
	// heatmap shade the same day the same way.
	const maxValue = isCounter ? (trackStats.value?.maxValue ?? 0) : 1

	const color = primaryColor.value

	const cells: HeatmapCell[] = []
	const monthLabels: { x: number; y: number; transform: string; label: string }[] = []
	const monthFormatter = new Intl.DateTimeFormat(userLocale, { year: 'numeric', month: 'short' })
	let lastMonthLabelWeek = -99
	let offset = 0

	for (const d = new Date(start); d <= today; d.setDate(d.getDate() + 1), offset++) {
		const week = Math.floor(offset / 7)
		const row = offset % 7
		const ds = toDateStr(d)
		const value = byDate.get(ds) ?? 0
		cells.push({
			x: GRID_LEFT_PAD + week * CELL_PITCH,
			y: HEATMAP_TOP_GUTTER + row * CELL_PITCH,
			fill: fillFor(levelFor(value, isCounter, maxValue), color),
			dateLabel: fmtDate(ds),
			valueText: valueText(value, isCounter),
		})

		// Label the column containing the 1st, keeping labels from colliding.
		if (d.getDate() === 1 && week - lastMonthLabelWeek >= 3) {
			const x = GRID_LEFT_PAD + week * CELL_PITCH + MONTH_LABEL_X_OFFSET
			monthLabels.push({
				x,
				y: MONTH_LABEL_BASELINE,
				transform: `rotate(-${MONTH_LABEL_ANGLE} ${x} ${MONTH_LABEL_BASELINE})`,
				label: monthFormatter.format(d),
			})
			lastMonthLabelWeek = week
		}
	}

	const weeks = Math.ceil(offset / 7)

	// Label alternate rows (matching GitHub's Mon/Wed/Fri) in locale order.
	// Right-aligned within the fixed day-label column, a few px from the grid.
	const dayLabels: { y: number; label: string }[] = []
	for (let row = 1; row < 7; row += 2) {
		dayLabels.push({
			y: HEATMAP_TOP_GUTTER + row * CELL_PITCH + CELL_SIZE / 2,
			label: weekdayShortNames[(localeFirstDay + row) % 7],
		})
	}

	const height = HEATMAP_TOP_GUTTER + 7 * CELL_PITCH - CELL_GAP
	return {
		cells,
		monthLabels,
		dayLabels,
		dayColWidth: DAY_LABEL_COL,
		dayLabelX: DAY_LABEL_COL - 6,
		isCounter,
		// Only shown when there's history beyond the trailing year to scroll to.
		hint: weeks > 53
			? 'Hint: showing last 365 days, scroll sideways for earlier history.'
			: '',
		legend: LEVEL_ALPHA.slice(1).map(a => hexToRgba(color, a)),
		emptyFill: EMPTY_FILL,
		width: GRID_LEFT_PAD + weeks * CELL_PITCH + HEATMAP_RIGHT_PAD,
		height,
	}
})

const heatmapScroller = ref<HTMLElement | null>(null)
const heatmapWrap = ref<HTMLElement | null>(null)

// Keep the view pinned to today whenever the rendered grid changes.
watch(heatmapData, async () => {
	await nextTick()
	const el = heatmapScroller.value
	if (el) el.scrollLeft = el.scrollWidth
})

// --- Heatmap tooltip (custom, styled to match the Chart.js default) ---
const heatmapTooltip = ref<{
	visible: boolean
	x: number
	y: number
	title: string
	swatch: string
	text: string
}>({ visible: false, x: 0, y: 0, title: '', swatch: '', text: '' })

function showHeatmapTooltip(cell: HeatmapCell, event: PointerEvent) {
	const wrap = heatmapWrap.value
	if (!wrap) return
	const rect = wrap.getBoundingClientRect()
	heatmapTooltip.value = {
		visible: true,
		x: event.clientX - rect.left,
		y: event.clientY - rect.top,
		title: cell.dateLabel,
		swatch: cell.fill,
		text: cell.valueText,
	}
}

function hideHeatmapTooltip() {
	heatmapTooltip.value.visible = false
}

// --- Data fetching ---
async function loadTracks() {
	loading.value = true
	try {
		const [t] = await Promise.all([fetchTracks(true), loadPreferences()])
		allTracks.value = t
	} finally {
		loading.value = false
	}
}

let loadSeq = 0

async function loadTicks() {
	const seq = ++loadSeq
	const track = selectedTrack.value
	if (!track) {
		allTicks.value = []
		trackStats.value = null
		loading.value = false
		return
	}
	loading.value = true
	try {
		const [ticks, stats] = await Promise.all([
			fetchAllTicks(),
			fetchStats(todayStr(), { trackId: track.id, includeRuns: true }),
		])
		// The selection changed meanwhile: its own load has the last word.
		if (seq !== loadSeq) return
		allTicks.value = ticks
		trackStats.value = stats[0] ?? null
	} finally {
		if (seq === loadSeq) loading.value = false
	}
}

watch(selectedTrack, (track) => {
	emit('update:trackId', track?.id ?? null, track !== null && trackOptions.value.some(o => o.id === track.id))
	loadTicks()
})

onMounted(async () => {
	await loadTracks()
	selectedTrack.value = resolveTrack(trackOptions.value)
})
</script>

<template>
	<NcEmptyContent v-if="!loading && tracks.length === 0 && !namedOption" name="No tracks yet">
		<template #description>
			Add one in <a href="#tracks" :class="$style.link">Tracks</a>.
		</template>
	</NcEmptyContent>
	<NcEmptyContent v-else-if="!loading && trackOptions.length === 0 && !namedOption"
		name="All your tracks are private"
		description="Use Show private on Today or in the Journal to show them." />
	<div v-else-if="trackOptions.length > 0 || namedOption" :class="$style.wrapper">
		<div :class="$style.trackSelector">
			<NcSelect v-model="selectedTrack"
				:options="trackOptions"
				:clearable="false"
				input-label="Track"
				:class="$style.trackSelect" />
		</div>

		<template v-if="selectedTrack && !loading">
			<div v-if="archivedLine" :class="$style.archivedLine">
				{{ archivedLine }}
			</div>

			<div v-if="trackTicks.length === 0" :class="$style.empty">
				No data recorded for this track yet.
			</div>

			<template v-else>
				<!-- Summary stats -->
				<div ref="summaryRow" :class="$style.statsRow">
					<div :class="$style.statCard"
						@pointerenter="showStatTooltip('summary', totalTooltip, $event)"
						@pointermove="showStatTooltip('summary', totalTooltip, $event)"
						@pointerleave="hideStatTooltip">
						<div :class="$style.statValue">
							{{ totalCount }}
						</div>
						<div :class="$style.statLabel">
							Total
						</div>
					</div>
					<div :class="$style.statCard"
						@pointerenter="showStatTooltip('summary', weeklyMeanTooltip, $event)"
						@pointermove="showStatTooltip('summary', weeklyMeanTooltip, $event)"
						@pointerleave="hideStatTooltip">
						<div :class="$style.statValue">
							{{ weeklyMean.toFixed(1) }}
						</div>
						<div :class="$style.statLabel">
							Weekly mean
						</div>
					</div>
					<div :class="$style.statCard"
						@pointerenter="showStatTooltip('summary', trendTooltip, $event)"
						@pointermove="showStatTooltip('summary', trendTooltip, $event)"
						@pointerleave="hideStatTooltip">
						<div :class="$style.statValue">
							<span :class="$style.trendArrow"
								:style="{ transform: `rotate(${-twoWeekTrend}deg)` }">→</span>
						</div>
						<div :class="$style.statLabel">
							2-week trend
						</div>
					</div>
					<div v-show="statTooltip.visible && statTooltip.row === 'summary'"
						:class="$style.heatmapTooltip"
						:style="{ left: statTooltip.x + 'px', top: statTooltip.y + 'px' }">
						<div v-for="line in statTooltip.lines" :key="line">
							{{ line }}
						</div>
					</div>
				</div>

				<!-- Streaks -->
				<div ref="streaksRow" :class="$style.statsRow">
					<div :class="$style.statCard"
						@pointerenter="showStatTooltip('streaks', currentRunTooltip, $event)"
						@pointermove="showStatTooltip('streaks', currentRunTooltip, $event)"
						@pointerleave="hideStatTooltip">
						<div :class="$style.statValue">
							{{ streakData.currentLength }}
						</div>
						<div :class="$style.statLabel">
							{{ streakData.currentIsStreak ? 'Current streak' : 'Current break' }}
						</div>
					</div>
					<div :class="$style.statCard"
						@pointerenter="showStatTooltip('streaks', formatStreakRange(streakData.longestStreakFrom, streakData.longestStreakTo), $event)"
						@pointermove="showStatTooltip('streaks', formatStreakRange(streakData.longestStreakFrom, streakData.longestStreakTo), $event)"
						@pointerleave="hideStatTooltip">
						<div :class="$style.statValue">
							{{ streakData.longestStreak }}
						</div>
						<div :class="$style.statLabel">
							Longest streak
						</div>
					</div>
					<div :class="$style.statCard"
						@pointerenter="showStatTooltip('streaks', formatStreakRange(streakData.longestBreakFrom, streakData.longestBreakTo), $event)"
						@pointermove="showStatTooltip('streaks', formatStreakRange(streakData.longestBreakFrom, streakData.longestBreakTo), $event)"
						@pointerleave="hideStatTooltip">
						<div :class="$style.statValue">
							{{ streakData.longestBreak }}
						</div>
						<div :class="$style.statLabel">
							Longest break
						</div>
					</div>
					<div v-show="statTooltip.visible && statTooltip.row === 'streaks'"
						:class="$style.heatmapTooltip"
						:style="{ left: statTooltip.x + 'px', top: statTooltip.y + 'px' }">
						<div v-for="line in statTooltip.lines" :key="line">
							{{ line }}
						</div>
					</div>
				</div>

				<!-- Calendar heatmap -->
				<div v-if="heatmapData" :class="$style.chartSection">
					<h3 :class="$style.chartHeading">
						Calendar
					</h3>
					<p v-if="heatmapData.hint" :class="$style.chartHint">
						{{ heatmapData.hint }}
					</p>
					<div ref="heatmapWrap" :class="$style.heatmapWrap">
						<div :class="$style.heatmapLayout">
							<!-- Fixed weekday labels: stay visible while the grid scrolls. -->
							<svg :width="heatmapData.dayColWidth"
								:height="heatmapData.height"
								:class="$style.heatmapDays"
								aria-hidden="true">
								<text v-for="d in heatmapData.dayLabels"
									:key="`d-${d.y}`"
									:x="heatmapData.dayLabelX"
									:y="d.y"
									text-anchor="end"
									dominant-baseline="middle"
									:class="$style.heatmapLabel">{{ d.label }}</text>
							</svg>
							<div ref="heatmapScroller" :class="$style.heatmapScroller">
								<svg :width="heatmapData.width"
									:height="heatmapData.height"
									:class="$style.heatmap"
									role="img"
									aria-label="Daily activity calendar">
									<text v-for="m in heatmapData.monthLabels"
										:key="`m-${m.x}`"
										:x="m.x"
										:y="m.y"
										:transform="m.transform"
										text-anchor="start"
										:class="$style.heatmapLabel">{{ m.label }}</text>
									<rect v-for="(c, i) in heatmapData.cells"
										:key="i"
										:x="c.x"
										:y="c.y"
										:width="11"
										:height="11"
										rx="2"
										:fill="c.fill"
										@pointerenter="showHeatmapTooltip(c, $event)"
										@pointermove="showHeatmapTooltip(c, $event)"
										@pointerleave="hideHeatmapTooltip" />
								</svg>
							</div>
						</div>
						<div v-show="heatmapTooltip.visible"
							:class="$style.heatmapTooltip"
							:style="{ left: heatmapTooltip.x + 'px', top: heatmapTooltip.y + 'px' }">
							<div :class="$style.tooltipTitle">
								{{ heatmapTooltip.title }}
							</div>
							<div :class="$style.tooltipBody">
								<span :class="$style.tooltipSwatch"
									:style="{ backgroundImage: `linear-gradient(${heatmapTooltip.swatch}, ${heatmapTooltip.swatch})` }" />
								{{ heatmapTooltip.text }}
							</div>
						</div>
					</div>
					<div v-if="heatmapData.isCounter" :class="$style.heatmapLegend">
						<span>Less</span>
						<span :class="$style.legendSwatch" :style="{ background: heatmapData.emptyFill }" />
						<span v-for="(fill, i) in heatmapData.legend"
							:key="i"
							:class="$style.legendSwatch"
							:style="{ background: fill }" />
						<span>More</span>
					</div>
				</div>

				<!-- Streaks/Breaks -->
				<div v-if="streaksBreaksData" :class="$style.chartSection">
					<h3 :class="$style.chartHeading">
						Streaks/Breaks
					</h3>
					<p :class="$style.chartHint">
						Hint: scroll to zoom, drag to pan, click below to reset
					</p>
					<div :class="$style.chartContainer">
						<Line ref="streaksBreaksChart"
							:data="streaksBreaksData.data"
							:options="streaksBreaksData.options" />
					</div>
					<div :class="$style.resetZoomRow">
						<NcButton variant="tertiary" @click="resetStreaksBreaksZoom">
							Reset zoom
						</NcButton>
					</div>
				</div>

				<!-- Polar overview: days of week & months across all years -->
				<div v-if="daysOfWeekPolar && monthsPolar" :class="$style.polarRow">
					<div :class="$style.polarChart">
						<h3 :class="$style.chartHeading">
							Days of week
						</h3>
						<div :class="$style.polarContainer">
							<PolarArea :data="daysOfWeekPolar.data"
								:options="daysOfWeekPolar.options"
								:plugins="[polarSliceLabelsPlugin]" />
						</div>
					</div>
					<div :class="$style.polarChart">
						<h3 :class="$style.chartHeading">
							Months
						</h3>
						<div :class="$style.polarContainer">
							<PolarArea :data="monthsPolar.data"
								:options="monthsPolar.options"
								:plugins="[polarSliceLabelsPlugin]" />
						</div>
					</div>
				</div>

				<!-- Weeks -->
				<div v-if="weeksChart" :class="$style.chartSection">
					<h3 :class="$style.chartHeading">
						Weeks
					</h3>
					<div :class="$style.chartContainer">
						<Line :data="weeksChart.data" :options="weeksChart.options" />
					</div>
				</div>

				<!-- Months -->
				<div v-if="monthsChart" :class="$style.chartSection">
					<h3 :class="$style.chartHeading">
						Months
					</h3>
					<div :class="$style.chartContainer">
						<Line :data="monthsChart.data" :options="monthsChart.options" />
					</div>
				</div>

				<!-- Quarters -->
				<div v-if="quartersChart" :class="$style.chartSection">
					<h3 :class="$style.chartHeading">
						Quarters
					</h3>
					<div :class="$style.chartContainer">
						<Line :data="quartersChart.data" :options="quartersChart.options" />
					</div>
				</div>

				<!-- Years -->
				<div v-if="yearsChart" :class="$style.chartSection">
					<h3 :class="$style.chartHeading">
						Years
					</h3>
					<div :class="$style.chartContainer">
						<Line :data="yearsChart.data" :options="yearsChart.options" />
					</div>
				</div>
			</template>
		</template>
	</div>
</template>

<style module>
.wrapper {
	padding: 16px;
	max-width: 900px;
}

.trackSelector {
	display: flex;
	align-items: flex-end;
	gap: 4px;
	margin-bottom: 24px;
	padding-inline-start: 44px;
}

.link {
	text-decoration: underline;
}

.trackSelect {
	max-width: 300px;
}

/* Under the picker, lined up with it */
.archivedLine {
	margin-block: -12px 16px;
	padding-inline-start: 44px;
	color: var(--color-text-maxcontrast);
}

.empty {
	text-align: center;
	color: var(--color-text-maxcontrast);
	margin-top: 32px;
}

.statsRow {
	display: flex;
	gap: 16px;
	margin-bottom: 16px;
	position: relative;
}

.statCard {
	flex: 1;
	padding: 16px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	text-align: center;
}

.statValue {
	font-size: 28px;
	font-weight: bold;
	color: var(--color-primary-element);
}

.trendArrow {
	display: inline-block;
	transition: transform 0.3s ease;
}

.statLabel {
	font-size: 13px;
	color: var(--color-text-maxcontrast);
	margin-top: 4px;
}

.chartSection {
	margin-top: 24px;
}

.chartHeading {
	font-size: 16px;
	font-weight: bold;
	margin-bottom: 8px;
}

.chartContainer {
	height: 220px;
	position: relative;
}

.chartHint {
	font-size: 12px;
	font-style: italic;
	color: var(--color-text-maxcontrast);
	margin-bottom: 14px;
}

.resetZoomRow {
	margin-top: 8px;
}

.heatmapWrap {
	position: relative;
}

.heatmapLayout {
	display: flex;
	align-items: flex-start;
}

/* Custom tooltip mirroring the Chart.js default (rgba(0,0,0,0.8), #fff, 6px). */
.heatmapTooltip {
	position: absolute;
	z-index: 10;
	pointer-events: none;
	transform: translate(-50%, calc(-100% - 7px));
	padding: 6px 8px;
	border-radius: 6px;
	background: rgba(0, 0, 0, 0.8);
	color: #fff;
	font-size: 12px;
	line-height: 1.2;
	white-space: nowrap;
}

.heatmapTooltip::after {
	content: '';
	position: absolute;
	top: 100%;
	inset-inline-start: 50%;
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
}

.tooltipSwatch {
	width: 12px;
	height: 12px;
	border-radius: 2px;
	/* Flatten the cell's translucent fill over the page background, exactly as
	   the grid does, so the swatch matches the cell instead of compositing over
	   the dark tooltip. The fill is layered on via background-image. */
	background-color: var(--color-main-background);
}

.heatmapDays {
	flex: none;
	display: block;
}

.heatmapScroller {
	flex: 1 1 auto;
	min-width: 0;
	overflow-x: auto;
	overflow-y: hidden;
	/* Clearance for the horizontal scrollbar. Firefox (GTK) uses overlay
	   scrollbars that paint over the content's bottom edge and reserve no
	   layout space, so this padding keeps the bar off the last row of cells.
	   scrollbar-width:thin trims it further where honoured. */
	padding-bottom: 14px;
	scrollbar-width: thin;
}

.heatmap {
	display: block;
}

.heatmapLabel {
	font-size: 12px;
	fill: var(--color-text-maxcontrast);
}

.heatmapLegend {
	display: flex;
	align-items: center;
	justify-content: flex-end;
	gap: 4px;
	margin-top: 6px;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.legendSwatch {
	width: 11px;
	height: 11px;
	border-radius: 2px;
}

.polarRow {
	display: flex;
	gap: 16px;
	margin-top: 24px;
	flex-wrap: wrap;
}

.polarChart {
	flex: 1 1 0;
	min-width: 240px;
}

.polarContainer {
	height: 260px;
	position: relative;
}
</style>
