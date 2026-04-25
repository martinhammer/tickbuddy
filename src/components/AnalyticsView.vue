<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue'
import axios from '@nextcloud/axios'
import { getLocale } from '@nextcloud/l10n'
import { generateOcsUrl, generateUrl } from '@nextcloud/router'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import { Bar, Line } from 'vue-chartjs'
import {
	Chart as ChartJS,
	CategoryScale,
	LinearScale,
	PointElement,
	LineElement,
	BarElement,
	Filler,
	Tooltip,
} from 'chart.js'

ChartJS.register(CategoryScale, LinearScale, PointElement, LineElement, BarElement, Filler, Tooltip)

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
const userLocale = getLocale()

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

const weeklyMean = computed(() => {
	const ticks = trackTicks.value
	if (ticks.length === 0) return 0
	const first = new Date(ticks[0].date + 'T00:00:00')
	const last = new Date(ticks[ticks.length - 1].date + 'T00:00:00')
	const weeks = Math.max(1, (last.getTime() - first.getTime()) / (7 * 24 * 60 * 60 * 1000))
	return totalCount.value / weeks
})

const twoWeekTrend = computed(() => {
	const today = new Date()
	today.setHours(0, 0, 0, 0)
	const oneWeekAgo = new Date(today)
	oneWeekAgo.setDate(today.getDate() - 7)
	const twoWeeksAgo = new Date(today)
	twoWeeksAgo.setDate(today.getDate() - 14)

	const toStr = (d: Date) => {
		const y = d.getFullYear()
		const m = String(d.getMonth() + 1).padStart(2, '0')
		const day = String(d.getDate()).padStart(2, '0')
		return `${y}-${m}-${day}`
	}

	const todayStr = toStr(today)
	const oneWeekStr = toStr(oneWeekAgo)
	const twoWeekStr = toStr(twoWeeksAgo)

	const thisWeek = trackTicks.value
		.filter(t => t.date > oneWeekStr && t.date <= todayStr)
		.reduce((s, t) => s + t.value, 0)
	const lastWeek = trackTicks.value
		.filter(t => t.date > twoWeekStr && t.date <= oneWeekStr)
		.reduce((s, t) => s + t.value, 0)

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
	if (ticks.length === 0) return { currentStreak: 0, longestStreak: 0, longestBreak: 0 }

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

	let currentStreak = 0
	let longestStreak = 0
	let longestBreak = 0
	let streak = 0
	let breakLen = 0

	// Walk from first tick date to today
	for (let d = new Date(first); d <= today; d.setDate(d.getDate() + 1)) {
		const ds = toStr(d)
		if (tickedDates.has(ds)) {
			streak++
			longestStreak = Math.max(longestStreak, streak)
			longestBreak = Math.max(longestBreak, breakLen)
			breakLen = 0
		} else {
			breakLen++
			longestStreak = Math.max(longestStreak, streak)
			streak = 0
		}
	}
	longestBreak = Math.max(longestBreak, breakLen)

	// Current streak: count back from today
	currentStreak = 0
	for (let d = new Date(today); d >= first; d.setDate(d.getDate() - 1)) {
		if (tickedDates.has(toStr(d))) {
			currentStreak++
		} else {
			break
		}
	}

	return { currentStreak, longestStreak, longestBreak }
})

// --- Days of week ---
const daysOfWeekData = computed(() => {
	const ticks = trackTicks.value
	if (ticks.length === 0) return null

	// Count ticks per ISO day (1=Mon..7=Sun)
	const counts = [0, 0, 0, 0, 0, 0, 0] // index 0=Mon..6=Sun
	let total = 0
	for (const t of ticks) {
		const jsDay = new Date(t.date + 'T00:00:00').getDay()
		const idx = jsDay === 0 ? 6 : jsDay - 1 // convert to Mon=0..Sun=6
		counts[idx] += t.value
		total += t.value
	}

	// Get locale-aware day names
	const formatter = new Intl.DateTimeFormat(userLocale, { weekday: 'short' })
	// Build day names starting from Monday
	const dayNames: string[] = []
	for (let i = 0; i < 7; i++) {
		// 2024-01-01 is a Monday
		const d = new Date(2024, 0, 1 + i)
		dayNames.push(formatter.format(d))
	}

	// Convert localeFirstDay (JS: 0=Sun..6=Sat) to our array index (0=Mon..6=Sun)
	const startDay = localeFirstDay === 0 ? 6 : localeFirstDay - 1

	// Reorder from locale start day
	const labels: string[] = []
	const values: number[] = []
	const percentages: string[] = []
	for (let i = 0; i < 7; i++) {
		const idx = (startDay + i) % 7
		labels.push(dayNames[idx])
		values.push(counts[idx])
		percentages.push(total > 0 ? Math.round((counts[idx] / total) * 100) + '%' : '0%')
	}

	const color = primaryColor.value
	return {
		data: {
			labels,
			datasets: [{
				data: values,
				backgroundColor: hexToRgba(color, 0.6),
				borderColor: color,
				borderWidth: 1,
			}],
		},
		percentages,
		options: {
			responsive: true,
			maintainAspectRatio: false,
			plugins: { tooltip: { enabled: true } },
			scales: {
				y: { beginAtZero: true, ticks: { precision: 0 } },
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
			plugins: { tooltip: { enabled: true } },
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

// Locale-aware first day of week: 0=Sun, 1=Mon, ..., 6=Sat (JS convention)
const localeFirstDay: number = (() => {
	try {
		const locale = new Intl.Locale(userLocale)
		const info = (locale as any).weekInfo ?? (locale as any).getWeekInfo?.()
		if (info?.firstDay) {
			// weekInfo.firstDay: 1=Mon..7=Sun → convert to JS: 0=Sun..6=Sat
			return info.firstDay === 7 ? 0 : info.firstDay
		}
	} catch { /* ignore */ }
	return 1 // default Monday
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
				<div :class="$style.statsRow">
					<div :class="$style.statCard">
						<div :class="$style.statValue">
							{{ totalCount }}
						</div>
						<div :class="$style.statLabel">
							Total
						</div>
					</div>
					<div :class="$style.statCard">
						<div :class="$style.statValue">
							{{ weeklyMean.toFixed(1) }}
						</div>
						<div :class="$style.statLabel">
							Weekly mean
						</div>
					</div>
					<div :class="$style.statCard">
						<div :class="$style.statValue">
							<span :class="$style.trendArrow"
								:style="{ transform: `rotate(${-twoWeekTrend}deg)` }">→</span>
						</div>
						<div :class="$style.statLabel">
							2-week trend
						</div>
					</div>
				</div>

				<!-- Streaks -->
				<div :class="$style.statsRow">
					<div :class="$style.statCard">
						<div :class="$style.statValue">
							{{ streakData.currentStreak }}
						</div>
						<div :class="$style.statLabel">
							Current streak
						</div>
					</div>
					<div :class="$style.statCard">
						<div :class="$style.statValue">
							{{ streakData.longestStreak }}
						</div>
						<div :class="$style.statLabel">
							Longest streak
						</div>
					</div>
					<div :class="$style.statCard">
						<div :class="$style.statValue">
							{{ streakData.longestBreak }}
						</div>
						<div :class="$style.statLabel">
							Longest break
						</div>
					</div>
				</div>

				<!-- Days of week -->
				<div v-if="daysOfWeekData" :class="$style.chartSection">
					<h3 :class="$style.chartHeading">
						Days of week
					</h3>
					<div :class="$style.chartContainer">
						<Bar :data="daysOfWeekData.data" :options="daysOfWeekData.options" />
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
</style>
