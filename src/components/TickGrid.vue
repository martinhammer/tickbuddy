<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'

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

const tracks = ref<Track[]>([])
const ticks = ref<Tick[]>([])
const daysToShow = ref(30)
const loading = ref(false)
const showPrivate = ref(false)

const visibleTracks = computed(() => {
	if (showPrivate.value) return tracks.value
	return tracks.value.filter(t => !t.private)
})

const hasPrivateTracks = computed(() => tracks.value.some(t => t.private))

const tracksUrl = generateOcsUrl('/apps/tickbuddy/api/tracks')
const ticksUrl = generateOcsUrl('/apps/tickbuddy/api/ticks')

const dates = computed(() => {
	const result: string[] = []
	const today = new Date()
	for (let i = 0; i < daysToShow.value; i++) {
		const d = new Date(today)
		d.setDate(today.getDate() - i)
		result.push(d.toISOString().split('T')[0])
	}
	return result
})

function formatDate(dateStr: string): string {
	const d = new Date(dateStr + 'T00:00:00')
	const today = new Date()
	today.setHours(0, 0, 0, 0)
	const diff = Math.round((today.getTime() - d.getTime()) / (1000 * 60 * 60 * 24))
	if (diff === 0) return 'Today'
	if (diff === 1) return 'Yesterday'
	return d.toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric' })
}

function getTickValue(trackId: number, date: string): number {
	const tick = ticks.value.find(t => t.trackId === trackId && t.date === date)
	return tick ? tick.value : 0
}

function isTicked(trackId: number, date: string): boolean {
	return getTickValue(trackId, date) > 0
}

async function toggleBoolean(trackId: number, date: string) {
	const params = new URLSearchParams()
	params.append('trackId', String(trackId))
	params.append('date', date)
	const response = await axios.post(`${ticksUrl}/toggle`, params)
	const ticked = response.data.ocs.data.ticked
	if (ticked) {
		ticks.value.push({ id: 0, trackId, date, value: 1 })
	} else {
		ticks.value = ticks.value.filter(t => !(t.trackId === trackId && t.date === date))
	}
}

async function setCounter(trackId: number, date: string, delta: number) {
	const current = getTickValue(trackId, date)
	const newValue = Math.max(0, current + delta)
	const params = new URLSearchParams()
	params.append('trackId', String(trackId))
	params.append('date', date)
	params.append('value', String(newValue))
	const response = await axios.post(`${ticksUrl}/set`, params)
	const value = response.data.ocs.data.value

	ticks.value = ticks.value.filter(t => !(t.trackId === trackId && t.date === date))
	if (value > 0) {
		ticks.value.push({ id: 0, trackId, date, value })
	}
}

async function fetchData() {
	loading.value = true
	try {
		const from = dates.value[dates.value.length - 1]
		const to = dates.value[0]
		const [tracksRes, ticksRes] = await Promise.all([
			axios.get(tracksUrl),
			axios.get(ticksUrl, { params: { from, to } }),
		])
		tracks.value = tracksRes.data.ocs.data
		ticks.value = ticksRes.data.ocs.data
	} finally {
		loading.value = false
	}
}

function loadMore() {
	daysToShow.value += 30
	fetchData()
}

onMounted(fetchData)
</script>

<template>
	<div :class="$style.gridWrapper">
		<p v-if="!loading && tracks.length === 0" :class="$style.empty">
			No tracks defined yet. Go to Settings → Personal → Tickbuddy to add some.
		</p>
		<template v-else>
			<div v-if="hasPrivateTracks" :class="$style.toolbar">
				<label :class="$style.privateToggle">
					<input type="checkbox" v-model="showPrivate">
					Show private tracks
				</label>
			</div>
			<p v-if="visibleTracks.length === 0" :class="$style.empty">
				All tracks are private. Use the toggle above to show them.
			</p>
		</template>
		<table v-if="visibleTracks.length > 0" :class="$style.grid">
			<thead>
				<tr>
					<th :class="$style.dateHeader" />
					<th v-for="track in visibleTracks" :key="track.id" :class="$style.trackHeader">
						{{ track.name }}
					</th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="date in dates" :key="date">
					<td :class="$style.dateCell">
						{{ formatDate(date) }}
					</td>
					<td v-for="track in visibleTracks"
						:key="track.id"
						:class="$style.tickCell">
						<template v-if="track.type === 'boolean'">
							<input type="checkbox"
								:checked="isTicked(track.id, date)"
								@change="toggleBoolean(track.id, date)">
						</template>
						<template v-else>
							<div :class="$style.counter">
								<button :class="$style.counterBtn"
									:disabled="getTickValue(track.id, date) === 0"
									@click="setCounter(track.id, date, -1)">
									−
								</button>
								<span :class="$style.counterValue">{{ getTickValue(track.id, date) }}</span>
								<button :class="$style.counterBtn"
									@click="setCounter(track.id, date, 1)">
									+
								</button>
							</div>
						</template>
					</td>
				</tr>
			</tbody>
		</table>
		<div v-if="visibleTracks.length > 0" :class="$style.loadMore">
			<button @click="loadMore">
				Load more days
			</button>
		</div>
	</div>
</template>

<style module>
.gridWrapper {
	padding: 16px;
}

.toolbar {
	display: flex;
	justify-content: flex-end;
	margin-bottom: 8px;
}

.privateToggle {
	display: flex;
	align-items: center;
	gap: 6px;
	cursor: pointer;
	color: var(--color-text-maxcontrast);
	font-size: var(--default-font-size, 15px);
}

.empty {
	text-align: center;
	color: var(--color-text-maxcontrast);
	margin-top: 32px;
}

.grid {
	width: 100%;
	border-collapse: collapse;
}

.dateHeader {
	position: sticky;
	top: 0;
	background: var(--color-main-background);
}

.trackHeader {
	position: sticky;
	top: 0;
	background: var(--color-main-background);
	padding: 8px;
	text-align: center;
	font-weight: bold;
	white-space: nowrap;
}

.dateCell {
	padding: 6px 12px;
	white-space: nowrap;
	border-bottom: 1px solid var(--color-border);
}

.tickCell {
	padding: 6px 8px;
	text-align: center;
	border-bottom: 1px solid var(--color-border);
}

.counter {
	display: inline-flex;
	align-items: center;
	gap: 4px;
}

.counterBtn {
	width: 24px;
	height: 24px;
	padding: 0;
	border: 1px solid var(--color-border);
	border-radius: 4px;
	background: var(--color-main-background);
	cursor: pointer;
	font-size: 14px;
	line-height: 1;
}

.counterBtn:hover {
	background: var(--color-background-hover);
}

.counterValue {
	min-width: 20px;
	text-align: center;
}

.loadMore {
	text-align: center;
	margin-top: 16px;
}
</style>
