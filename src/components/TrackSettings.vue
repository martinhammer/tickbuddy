<script setup lang="ts">
import { ref, onMounted } from 'vue'
import axios from '@nextcloud/axios'
import { showConfirmation } from '@nextcloud/dialogs'
import { generateOcsUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcSettingsSection from '@nextcloud/vue/components/NcSettingsSection'

interface Track {
	id: number
	name: string
	type: string
	sortOrder: number
	private: boolean
}

const tracks = ref<Track[]>([])
const newTrackName = ref('')
const newTrackType = ref<'boolean' | 'counter'>('boolean')
const loading = ref(false)
const editingTrackId = ref<number | null>(null)
const editingName = ref('')
const dragIndex = ref<number | null>(null)
const dragOverIndex = ref<number | null>(null)

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

async function deleteTrack(track: Track) {
	const confirmed = await showConfirmation({
		name: 'Delete track',
		text: `Are you sure you want to delete "${track.name}"? All its data will be lost.`,
		labelConfirm: 'Delete',
	})
	if (!confirmed) return

	await axios.delete(`${apiUrl}/${track.id}`)
	await fetchTracks()
}

function startEditing(track: Track) {
	editingTrackId.value = track.id
	editingName.value = track.name
}

async function saveName(track: Track) {
	const trimmed = editingName.value.trim()
	editingTrackId.value = null
	if (!trimmed || trimmed === track.name) return

	const params = new URLSearchParams()
	params.append('name', trimmed)
	await axios.put(`${apiUrl}/${track.id}`, params)
	track.name = trimmed
}

async function togglePrivate(track: Track) {
	const newValue = !track.private
	const params = new URLSearchParams()
	params.append('private', String(newValue))
	await axios.put(`${apiUrl}/${track.id}`, params)
	track.private = newValue
}

function onDragStart(index: number, event: DragEvent) {
	dragIndex.value = index
	if (event.dataTransfer) {
		event.dataTransfer.effectAllowed = 'move'
	}
}

function onDragOver(index: number, event: DragEvent) {
	event.preventDefault()
	if (event.dataTransfer) {
		event.dataTransfer.dropEffect = 'move'
	}
	dragOverIndex.value = index
}

function onDragLeave() {
	dragOverIndex.value = null
}

async function onDrop(toIndex: number) {
	const fromIndex = dragIndex.value
	dragIndex.value = null
	dragOverIndex.value = null
	if (fromIndex === null || fromIndex === toIndex) return

	const moved = tracks.value.splice(fromIndex, 1)[0]
	tracks.value.splice(toIndex, 0, moved)

	const trackIds = tracks.value.map(t => t.id)
	const params = new URLSearchParams()
	trackIds.forEach(id => params.append('trackIds[]', String(id)))
	const response = await axios.put(`${apiUrl}/reorder`, params)
	tracks.value = response.data.ocs.data
}

function onDragEnd() {
	dragIndex.value = null
	dragOverIndex.value = null
}

onMounted(fetchTracks)
</script>

<template>
	<NcSettingsSection name="Tickbuddy"
		description="Define the tracks you want to monitor. Each track represents a habit or event to record daily.">
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

		<table v-if="tracks.length > 0" :class="$style.trackTable">
			<thead>
				<tr>
					<th />
					<th>Name</th>
					<th>Type</th>
					<th>Private</th>
					<th />
				</tr>
			</thead>
			<tbody>
				<tr v-for="(track, index) in tracks"
					:key="track.id"
					:class="{ [$style.dragOver]: dragOverIndex === index }"
					@dragover="onDragOver(index, $event)"
					@dragleave="onDragLeave"
					@drop="onDrop(index)"
					@dragend="onDragEnd">
					<td :class="$style.dragHandle"
						draggable="true"
						@dragstart="onDragStart(index, $event)">
						⠿
					</td>
					<td @click="startEditing(track)" :class="$style.nameCell">
						<NcTextField v-if="editingTrackId === track.id"
							v-model="editingName"
							label="Track name"
							autofocus
							@keyup.enter="saveName(track)"
							@blur="saveName(track)" />
						<span v-else>{{ track.name }}</span>
					</td>
					<td>{{ track.type === 'counter' ? 'Counter' : 'Yes / No' }}</td>
					<td :class="$style.privateCell">
						<input type="checkbox"
							:checked="track.private"
							@change="togglePrivate(track)">
					</td>
					<td>
						<NcButton type="tertiary-no-background"
							aria-label="Delete track"
							@click="deleteTrack(track)">
							Delete
						</NcButton>
					</td>
				</tr>
			</tbody>
		</table>
		<p v-else-if="!loading">
			No tracks defined yet. Add one above.
		</p>
	</NcSettingsSection>
</template>

<style module>
.trackTable {
	width: 100%;
	margin-top: 16px;
	border-collapse: collapse;
}

.trackTable th,
.trackTable td {
	padding: 8px 12px;
	text-align: left;
	border-bottom: 1px solid var(--color-border);
}

.dragHandle {
	cursor: grab;
	width: 24px;
	text-align: center;
	color: var(--color-text-maxcontrast);
	user-select: none;
	font-size: 16px;
}

.dragHandle:active {
	cursor: grabbing;
}

.dragOver {
	border-top: 2px solid var(--color-primary-element) !important;
}

.nameCell {
	cursor: pointer;
}

.nameCell:hover {
	background: var(--color-background-hover);
}

.addForm {
	display: flex;
	align-items: flex-end;
	gap: 8px;
	margin-bottom: 32px;
}

.typeSelect {
	min-width: 180px;
	height: var(--default-clickable-area, 44px);
	padding: 0 36px 0 16px;
	border: 2px solid var(--color-border-maxcontrast);
	border-radius: var(--border-radius-element, 32px);
	background-color: var(--color-main-background);
	color: var(--color-main-text);
	font-size: var(--default-font-size, 15px);
	line-height: 1.5;
	appearance: none;
	-webkit-appearance: none;
	-moz-appearance: none;
	cursor: pointer;
	background-image: url("data:image/svg+xml;charset=utf-8,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 16 16'%3E%3Cpath fill='none' stroke='%23fff' stroke-width='2' stroke-linecap='round' stroke-linejoin='round' d='M4 6l4 4 4-4'/%3E%3C/svg%3E");
	background-repeat: no-repeat;
	background-position: right 12px center;
	background-size: 16px 16px;
}

.typeSelect:hover,
.typeSelect:focus {
	border-color: var(--color-primary-element);
	outline: none;
}

.addButton {
	white-space: nowrap;
}
</style>
