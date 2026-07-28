<script setup lang="ts">
import { ref, computed, nextTick, onMounted, watch } from 'vue'
import axios from '@nextcloud/axios'
import { getLocale, getFirstDay } from '@nextcloud/l10n'
import { generateOcsUrl, generateUrl } from '@nextcloud/router'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcButton from '@nextcloud/vue/components/NcButton'
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

ChartJS.register(CategoryScale, LinearScale, RadialLinearScale, PointElement, LineElement, ArcElement, Filler, Legend, SubTitle, Tooltip, zoomPlugin)

interface Track {
	id: number
	name: string
	type: string
	sortOrder: number
	private: boolean
}

interface Tick {
	id: number
	trackId: number
	date: string
	value: number
}

const props = defineProps<{
	showPrivate: boolean
}>()

const tracks = ref<Track[]>([])
const allTicks = ref<Tick[]>([])
const selectedTrack = ref<{ id: number; label: string } | null>(null)
const loading = ref(false)

const tracksUrl = generateOcsUrl('/apps/tickbuddy/api/tracks')
const ticksUrl = generateOcsUrl('/apps/tickbuddy/api/ticks')
const settingsUrl = generateUrl('/settings/user/tickbuddy')
const userLocale = getLocale().replace('_', '-')

const trackOptions = computed(() => {
	const list = props.showPrivate ? tracks.value : tracks.value.filter(t => !t.private)
	return list.map(t => ({ id: t.id, label: t.name }))
})

// When available tracks change (e.g. private toggle), ensure selection is still valid
watch(trackOptions, (options) => {
	if (!selectedTrack.value || !options.some(o => o.id === selectedTrack.value!.id)) {
		selectedTrack.value = options.length > 0 ? options[0] : null
	}
})

const trackTicks = computed(() => {
	if (!selectedTrack.value) return []
	return allTicks.value
		.filter(t => t.trackId === selectedTrack.value!.id)
		.sort((a, b) => a.date.localeCompare(b.date))
})

const selectedTrackType = computed(() => {
	if (!selectedTrack.value) return 'boolean'
	return tracks.value.find(t => t.id === selectedTrack.value!.id)?.type ?? 'boolean'
})

// --- Primary colour extraction ---
function getPrimaryColor(): string {
	if (typeof document === 'undefined') return '#0082c9'
	return getComputedStyle(document.documentElement).getPropertyValue('--color-primary-element').trim() || '#0082c9'
}

function hexToRgba(hex: string, alpha: number): string {
	const r = parseInt(hex.slice(1, 3), 16)
	const g = parseInt(hex.slice(3, 5), 16)
	const b = parseInt(hex.slice(5, 7), 16)
	return `rgba(${r}, ${g}, ${b}, ${alpha})`
}

const primaryColor = ref(getPrimaryColor())

onMounted(() => {
	primaryColor.value = getPrimaryColor()
})

// --- Summary stats ---
const totalCount = computed(() => {
	return trackTicks.value.reduce((sum, t) => sum + t.value, 0)
})

// Span the mean is taken over: first to last entry, floored at one week so a
// track with a single entry doesn't divide by ~0.
const spanWeeks = computed(() => {
	const ticks = trackTicks.value
	if (ticks.length === 0) return 0
	const first = new Date(ticks[0].date + 'T00:00:00')
	const last = new Date(ticks[ticks.length - 1].date + 'T00:00:00')
	return Math.max(1, (last.getTime() - first.getTime()) / (7 * 24 * 60 * 60 * 1000))
})

const weeklyMean = computed(() => {
	if (spanWeeks.value === 0) return 0
	return totalCount.value / spanWeeks.value
})

// The two halves the trend arrow compares: the last 7 days against the 7
// before them.
const twoWeekWindows = computed(() => {
	const today = new Date()
	today.setHours(0, 0, 0, 0)
	const oneWeekAgo = new Date(today)
	oneWeekAgo.setDate(today.getDate() - 7)
	const twoWeeksAgo = new Date(today)
	twoWeeksAgo.setDate(today.getDate() - 14)

	const todayStr = toDateStr(today)
	const oneWeekStr = toDateStr(oneWeekAgo)
	const twoWeekStr = toDateStr(twoWeeksAgo)

	return {
		thisWeek: trackTicks.value
			.filter(t => t.date > oneWeekStr && t.date <= todayStr)
			.reduce((s, t) => s + t.value, 0),
		lastWeek: trackTicks.value
			.filter(t => t.date > twoWeekStr && t.date <= oneWeekStr)
			.reduce((s, t) => s + t.value, 0),
	}
})

const twoWeekTrend = computed(() => {
	const today = new Date()
	today.setHours(0, 0, 0, 0)
	const { thisWeek, lastWeek } = twoWeekWindows.value

	if (thisWeek === 0 && lastWeek === 0) return 0

	// Scale by peak rolling 7-day sum across history (floored at 7 so sparse
	// boolean tracks still calibrate sensibly — 7 = max possible per week).
	const byDate = new Map<string, number>()
	for (const t of trackTicks.value) byDate.set(t.date, t.value)
	let peak = 0
	const sorted = [...byDate.keys()].sort()
	if (sorted.length > 0) {
		const first = new Date(sorted[0] + 'T00:00:00')
		const window: number[] = []
		let windowSum = 0
		for (let d = new Date(first); d <= today; d.setDate(d.getDate() + 1)) {
			const v = byDate.get(toDateStr(d)) ?? 0
			window.push(v)
			windowSum += v
			if (window.length > 7) windowSum -= window.shift()!
			if (windowSum > peak) peak = windowSum
		}
	}
	const scale = Math.max(peak, 7)

	const ratio = Math.max(-1, Math.min(1, (thisWeek - lastWeek) / scale))
	const step = Math.round(ratio * 4) // -4..+4
	return step * 22.5
})

// --- Streaks ---
const streakData = computed(() => {
	const ticks = trackTicks.value
	if (ticks.length === 0) {
		return {
			currentLength: 0,
			currentIsStreak: true,
			longestStreak: 0,
			longestBreak: 0,
			currentFrom: '',
			currentTo: '',
			longestStreakFrom: '',
			longestStreakTo: '',
			longestBreakFrom: '',
			longestBreakTo: '',
		}
	}

	// Build a set of all ticked dates
	const tickedDates = new Set(ticks.map(t => t.date))

	// Find the full date range
	const first = new Date(ticks[0].date + 'T00:00:00')
	const today = new Date()
	today.setHours(0, 0, 0, 0)

	const toStr = (d: Date) => {
		const y = d.getFullYear()
		const m = String(d.getMonth() + 1).padStart(2, '0')
		const day = String(d.getDate()).padStart(2, '0')
		return `${y}-${m}-${day}`
	}

	let longestStreak = 0
	let longestBreak = 0
	let streak = 0
	let breakLen = 0
	let streakStart = ''
	let breakStart = ''
	let longestStreakFrom = ''
	let longestStreakTo = ''
	let longestBreakFrom = ''
	let longestBreakTo = ''

	// Walk from first tick date to today
	for (let d = new Date(first); d <= today; d.setDate(d.getDate() + 1)) {
		const ds = toStr(d)
		if (tickedDates.has(ds)) {
			if (streak === 0) streakStart = ds
			streak++
			if (streak > longestStreak) {
				longestStreak = streak
				longestStreakFrom = streakStart
				longestStreakTo = ds
			}
			breakLen = 0
		} else {
			if (breakLen === 0) breakStart = ds
			breakLen++
			if (breakLen > longestBreak) {
				longestBreak = breakLen
				longestBreakFrom = breakStart
				longestBreakTo = ds
			}
			streak = 0
		}
	}

	// Current run: today is either ticked (streak) or not (break). Count back
	// the consecutive days matching today's state.
	const currentIsStreak = tickedDates.has(toStr(today))
	const currentTo = toStr(today)
	let currentLength = 0
	let currentFrom = currentTo
	for (let d = new Date(today); d >= first; d.setDate(d.getDate() - 1)) {
		if (tickedDates.has(toStr(d)) === currentIsStreak) {
			currentLength++
			currentFrom = toStr(d)
		} else {
			break
		}
	}

	return {
		currentLength,
		currentIsStreak,
		longestStreak,
		longestBreak,
		currentFrom,
		currentTo,
		longestStreakFrom,
		longestStreakTo,
		longestBreakFrom,
		longestBreakTo,
	}
})

// Format a run's date range for display under a streak stat, e.g.
// "Fri 1 Jan 2025 - Wed 15 Mar 2025" (or a single date when from === to).
const streakDateFormatter = new Intl.DateTimeFormat(userLocale, {
	weekday: 'short', day: 'numeric', month: 'short', year: 'numeric',
})
function fmtStreakDate(ds: string): string {
	return streakDateFormatter.format(new Date(ds + 'T00:00:00')).replace(',', '')
}
function formatStreakRange(from: string, to: string): string {
	if (!from || !to) return ''
	return from === to ? fmtStreakDate(from) : `${fmtStreakDate(from)} - ${fmtStreakDate(to)}`
}
// The current run always ends today, so show only its start: "Today" for a
// single day, otherwise "Since <start date>".
function formatCurrentRange(from: string, to: string): string {
	if (!from || !to) return ''
	return from === to ? 'Today' : `Since ${fmtStreakDate(from)}`
}

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
	const ticks = trackTicks.value
	if (ticks.length === 0) return []
	return [
		`Oldest entry: ${fmtStreakDate(ticks[0].date)}`,
		`Newest entry: ${fmtStreakDate(ticks[ticks.length - 1].date)}`,
	]
})

const weeklyMeanTooltip = computed(() => {
	const ticks = trackTicks.value
	if (ticks.length === 0) return []
	const weeks = Math.round(spanWeeks.value)
	return [
		`Total ${totalCount.value} across ${weeks} ${weeks === 1 ? 'week' : 'weeks'}`,
		`Since ${fmtStreakDate(ticks[0].date)}`,
	]
})

const trendTooltip = computed(() => [
	`Last 7 days: ${twoWeekWindows.value.thisWeek}`,
	`Previous 7 days: ${twoWeekWindows.value.lastWeek}`,
])

// --- Streaks/Breaks series ---
// Walk from first tick to today, producing an alternating sequence of
// streak (positive) and break (negative) run lengths. Each run becomes one
// point on the chart; streaks point up, breaks point down.
const streaksBreaksChart = ref<any>(null)
function resetStreaksBreaksZoom() {
	streaksBreaksChart.value?.chart?.resetZoom()
}

const streaksBreaksData = computed(() => {
	const ticks = trackTicks.value
	if (ticks.length === 0) return null

	const tickedDates = new Set(ticks.map(t => t.date))
	const first = new Date(ticks[0].date + 'T00:00:00')
	const today = new Date()
	today.setHours(0, 0, 0, 0)

	const runs: { length: number; isStreak: boolean; from: string; to: string }[] = []
	let currentLen = 0
	let currentIsStreak: boolean | null = null
	let currentFrom = ''
	let currentTo = ''

	for (let d = new Date(first); d <= today; d.setDate(d.getDate() + 1)) {
		const ds = toDateStr(d)
		const ticked = tickedDates.has(ds)
		if (currentIsStreak === null) {
			currentIsStreak = ticked
			currentLen = 1
			currentFrom = ds
			currentTo = ds
		} else if (ticked === currentIsStreak) {
			currentLen++
			currentTo = ds
		} else {
			runs.push({ length: currentLen, isStreak: currentIsStreak, from: currentFrom, to: currentTo })
			currentIsStreak = ticked
			currentLen = 1
			currentFrom = ds
			currentTo = ds
		}
	}
	if (currentIsStreak !== null) {
		runs.push({ length: currentLen, isStreak: currentIsStreak, from: currentFrom, to: currentTo })
	}

	if (runs.length === 0) return null

	const values = runs.map(r => r.isStreak ? r.length : -r.length)
	const labels = runs.map((_, i) => String(i + 1))
	const color = primaryColor.value

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
		options: {
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
					pan: {
						enabled: true,
						mode: 'x',
						modifierKey: null,
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
						callback: (v: number) => Math.abs(v),
					},
				},
				x: { display: false },
			},
		},
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
function toDateStr(d: Date): string {
	const y = d.getFullYear()
	const m = String(d.getMonth() + 1).padStart(2, '0')
	const day = String(d.getDate()).padStart(2, '0')
	return `${y}-${m}-${day}`
}

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
// Alpha per level: index 0 is unused (empty days get a background colour).
const LEVEL_ALPHA = [0, 0.25, 0.45, 0.68, 0.9]

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

	const today = new Date()
	today.setHours(0, 0, 0, 0)

	// Trailing 365 days, extended back to the first tick if history is longer.
	const windowStart = new Date(today)
	windowStart.setDate(windowStart.getDate() - 364)
	const firstTick = new Date(ticks[0].date + 'T00:00:00')
	const start = firstTick < windowStart ? new Date(firstTick) : new Date(windowStart)
	// Align to the locale's first weekday so every column is a full week.
	while (start.getDay() !== localeFirstDay) start.setDate(start.getDate() - 1)

	const isCounter = selectedTrackType.value === 'counter'
	const maxValue = isCounter ? Math.max(...ticks.map(t => t.value)) : 1

	// Booleans get a single shade at level 2 — full strength reads as too heavy
	// when every ticked day is identical. Counters are quantised into four
	// levels against that track's own maximum; small ranges map value→level
	// directly so a track that never exceeds 3 still uses distinct shades.
	function levelFor(value: number): number {
		if (value <= 0) return 0
		if (!isCounter) return 2
		if (maxValue <= 4) return Math.min(4, value)
		return Math.max(1, Math.min(4, Math.ceil((value / maxValue) * 4)))
	}

	const color = primaryColor.value
	const emptyFill = 'var(--color-background-dark)'
	const dateFormatter = new Intl.DateTimeFormat(userLocale, {
		weekday: 'short', year: 'numeric', month: 'short', day: 'numeric',
	})

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
		const level = levelFor(value)
		const fill = level === 0 ? emptyFill : hexToRgba(color, LEVEL_ALPHA[level])
		const valueText = value > 0
			? (isCounter ? String(value) : 'Ticked')
			: (isCounter ? '0' : 'Not ticked')

		cells.push({
			x: GRID_LEFT_PAD + week * CELL_PITCH,
			y: HEATMAP_TOP_GUTTER + row * CELL_PITCH,
			fill,
			// Chart.js title lines carry no comma; drop the one Intl adds.
			dateLabel: dateFormatter.format(d).replace(',', ''),
			valueText,
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
		emptyFill,
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
async function fetchTracks() {
	loading.value = true
	try {
		const response = await axios.get(tracksUrl)
		tracks.value = response.data.ocs.data
	} finally {
		loading.value = false
	}
}

async function fetchTicks() {
	if (!selectedTrack.value) {
		allTicks.value = []
		return
	}
	loading.value = true
	try {
		const response = await axios.get(ticksUrl, {
			params: { from: '2000-01-01', to: '2099-12-31' },
		})
		allTicks.value = response.data.ocs.data
	} finally {
		loading.value = false
	}
}

watch(selectedTrack, () => {
	fetchTicks()
})

onMounted(async () => {
	await fetchTracks()
	if (trackOptions.value.length > 0) {
		selectedTrack.value = trackOptions.value[0]
	}
})
</script>

<template>
	<p v-if="!loading && tracks.length === 0" :class="$style.emptyStandalone">
		No tracks defined yet. Go to <a :href="settingsUrl">Settings → Personal → Tickbuddy</a> to add some.
	</p>
	<p v-else-if="!loading && trackOptions.length === 0" :class="$style.emptyStandalone">
		All tracks are private. Enable "Show private tracks" in the sidebar settings to show them.
	</p>
	<div v-else-if="trackOptions.length > 0" :class="$style.wrapper">
		<div :class="$style.trackSelector">
			<NcSelect v-model="selectedTrack"
				:options="trackOptions"
				:clearable="false"
				input-label="Track"
				:class="$style.trackSelect" />
		</div>

		<template v-if="selectedTrack && !loading">
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
						@pointerenter="showStatTooltip('streaks', formatCurrentRange(streakData.currentFrom, streakData.currentTo), $event)"
						@pointermove="showStatTooltip('streaks', formatCurrentRange(streakData.currentFrom, streakData.currentTo), $event)"
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
	margin-bottom: 24px;
	padding-left: 44px;
}

.trackSelect {
	max-width: 300px;
}

.empty {
	text-align: center;
	color: var(--color-text-maxcontrast);
	margin-top: 32px;
}

.emptyStandalone {
	text-align: center;
	color: var(--color-text-maxcontrast);
	padding: 16px;
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
