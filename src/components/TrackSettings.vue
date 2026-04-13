<script setup lang="ts">
import { ref, onMounted } from 'vue'
import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcSettingsSection from '@nextcloud/vue/components/NcSettingsSection'

interface Track {
	id: number
	name: string
	type: string
	sortOrder: number
}

const tracks = ref<Track[]>([])
const newTrackName = ref('')
const newTrackType = ref<'boolean' | 'counter'>('boolean')
const loading = ref(false)

const apiUrl = generateOcsUrl('/apps/tickbuddy/api/tracks')

async function fetchTracks() {
	loading.value = true
	try {
		const response = await axios.get(apiUrl)
		tracks.value = response.data.ocs.data
	} finally {
		loading.value = false
	}
}

async function addTrack() {
	const name = newTrackName.value.trim()
	if (!name) return

	const params = new URLSearchParams()
	params.append('name', name)
	params.append('type', newTrackType.value)
	await axios.post(apiUrl, params)
	newTrackName.value = ''
	newTrackType.value = 'boolean'
	await fetchTracks()
}

async function deleteTrack(id: number) {
	await axios.delete(`${apiUrl}/${id}`)
	await fetchTracks()
}

onMounted(fetchTracks)
</script>

<template>
	<NcSettingsSection name="Tickbuddy"
		description="Define the tracks you want to monitor. Each track represents a habit or event to record daily.">
		<table v-if="tracks.length > 0" :class="$style.trackTable">
			<thead>
				<tr>
					<th>Name</th>
					<th>Type</th>
					<th />
				</tr>
			</thead>
			<tbody>
				<tr v-for="track in tracks" :key="track.id">
					<td>{{ track.name }}</td>
					<td>{{ track.type === 'counter' ? 'Counter' : 'Yes / No' }}</td>
					<td>
						<NcButton type="tertiary-no-background"
							aria-label="Delete track"
							@click="deleteTrack(track.id)">
							Delete
						</NcButton>
					</td>
				</tr>
			</tbody>
		</table>
		<p v-else-if="!loading">
			No tracks defined yet. Add one below.
		</p>

		<div :class="$style.addForm">
			<NcTextField v-model="newTrackName"
				label="Track name"
				placeholder="e.g. Exercise, Coffee, Reading..."
				@keyup.enter="addTrack" />
			<select v-model="newTrackType" :class="$style.typeSelect">
				<option value="boolean">
					Yes / No
				</option>
				<option value="counter">
					Counter
				</option>
			</select>
			<NcButton type="primary"
				:disabled="!newTrackName.trim()"
				:class="$style.addButton"
				@click="addTrack">
				Add track
			</NcButton>
		</div>
	</NcSettingsSection>
</template>

<style module>
.trackTable {
	width: 100%;
	margin-bottom: 16px;
	border-collapse: collapse;
}

.trackTable th,
.trackTable td {
	padding: 8px 12px;
	text-align: left;
	border-bottom: 1px solid var(--color-border);
}

.addForm {
	display: flex;
	align-items: flex-end;
	gap: 8px;
	margin-top: 16px;
}

.typeSelect {
	min-width: 140px;
	height: 44px;
	padding: 0 12px;
	border: 2px solid var(--color-border-maxcontrast);
	border-radius: var(--border-radius-large);
	background: var(--color-main-background);
	color: var(--color-main-text);
}

.addButton {
	white-space: nowrap;
}
</style>
