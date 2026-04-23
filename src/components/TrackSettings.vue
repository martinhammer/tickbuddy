<script setup lang="ts">
import { ref, onMounted } from 'vue'
import axios from '@nextcloud/axios'
import { showConfirmation } from '@nextcloud/dialogs'
import { generateOcsUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcSelect from '@nextcloud/vue/components/NcSelect'
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
const trackTypeOptions = [
	{ id: 'boolean', label: 'Yes / No' },
	{ id: 'counter', label: 'Counter' },
]
const newTrackType = ref(trackTypeOptions[0])
const loading = ref(false)
const defaultViewOptions = [
	{ id: 'journal', label: 'Edit journal' },
	{ id: 'readonly', label: 'View journal' },
	{ id: 'analytics', label: 'Analytics' },
]
const defaultView = ref(defaultViewOptions[0])
const editingTrackId = ref<number | null>(null)
const editingName = ref('')
const dragIndex = ref<number | null>(null)
const dragOverIndex = ref<number | null>(null)

// Import state
const replaceMode = ref(false)
const importFile = ref<File | null>(null)
const importFileName = ref('')
const importing = ref(false)
const importResult = ref<{ type: 'success' | 'error'; message: string } | null>(null)
const fileInputRef = ref<HTMLInputElement | null>(null)

const apiUrl = generateOcsUrl('/apps/tickbuddy/api/tracks')
const prefsUrl = generateOcsUrl('/apps/tickbuddy/api/preferences')

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
	params.append('type', newTrackType.value.id)
	await axios.post(apiUrl, params)
	newTrackName.value = ''
	newTrackType.value = trackTypeOptions[0]
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

async function fetchPreferences() {
	const response = await axios.get(prefsUrl)
	const viewId = response.data.ocs.data.defaultView
	const match = defaultViewOptions.find(o => o.id === viewId)
	if (match) {
		defaultView.value = match
	}
}

async function saveDefaultView(option: { id: string; label: string }) {
	defaultView.value = option
	const params = new URLSearchParams()
	params.append('defaultView', option.id)
	await axios.put(prefsUrl, params)
}

function onFileChange(event: Event) {
	const input = event.target as HTMLInputElement
	const file = input.files?.[0] ?? null
	importFile.value = file
	importFileName.value = file?.name ?? ''
	importResult.value = null
}

function chooseFile() {
	fileInputRef.value?.click()
}

async function doImport() {
	if (!importFile.value) return

	if (replaceMode.value) {
		const confirmed = await showConfirmation({
			name: 'Replace all data',
			text: 'This will delete all your existing tracks and ticks and replace them with the imported data. Are you sure?',
			labelConfirm: 'Replace',
		})
		if (!confirmed) return
	}

	importing.value = true
	importResult.value = null
	try {
		const formData = new FormData()
		formData.append('file', importFile.value)
		formData.append('mode', replaceMode.value ? 'replace' : 'merge')
		const response = await axios.post(generateOcsUrl('/apps/tickbuddy/api/import'), formData)
		const data = response.data.ocs.data
		importResult.value = {
			type: 'success',
			message: `Imported ${data.tracks} tracks and ${data.ticks} ticks.`,
		}
		await fetchTracks()
	} catch (e: any) {
		const message = e.response?.data?.ocs?.data?.message ?? e.message ?? 'Import failed'
		importResult.value = { type: 'error', message }
	} finally {
		importing.value = false
	}
}

onMounted(() => {
	fetchTracks()
	fetchPreferences()
})
</script>

<template>
	<NcSettingsSection name="Tickbuddy"
		description="Define the tracks you want to monitor. Each track represents a habit or event to record daily.">
		<div :class="$style.addForm">
			<NcTextField v-model="newTrackName"
				label="Track name"
				placeholder="e.g. Exercise, Coffee, Reading..."
				@keyup.enter="addTrack" />
			<NcSelect v-model="newTrackType"
				:options="trackTypeOptions"
				:clearable="false"
				input-label="Track type"
				:class="$style.typeSelect" />
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
						<input :id="`private-${track.id}`"
							type="checkbox"
							class="checkbox"
							:checked="track.private"
							@change="togglePrivate(track)">
						<label :for="`private-${track.id}`" />
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

	<NcSettingsSection name="Preferences">
		<div>
			<NcSelect :model-value="defaultView"
				:options="defaultViewOptions"
				:clearable="false"
				input-label="Default screen"
				:class="$style.prefSelect"
				@update:model-value="saveDefaultView" />
		</div>
	</NcSettingsSection>

	<NcSettingsSection name="Import from Tickmate"
		description="Import tracks and ticks from a Tickmate backup file (.db).">
		<input ref="fileInputRef"
			type="file"
			accept=".db"
			:class="$style.hiddenFileInput"
			@change="onFileChange">
		<NcButton type="secondary"
			@click="chooseFile">
			{{ importFileName || 'Choose backup file' }}
		</NcButton>

		<div :class="$style.importToggle">
			<NcCheckboxRadioSwitch type="switch"
				v-model="replaceMode">
				Replace existing database
			</NcCheckboxRadioSwitch>
			<p :class="$style.importHint">
				When enabled, all your existing tracks and ticks will be deleted and replaced with the imported data.
				When disabled, imported tracks will be added after your existing ones.
			</p>
		</div>

		<NcButton type="primary"
			:disabled="!importFile || importing"
			@click="doImport">
			{{ importing ? 'Importing...' : 'Import' }}
		</NcButton>

		<p v-if="importResult"
			:class="importResult.type === 'success' ? $style.importSuccess : $style.importError">
			{{ importResult.message }}
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
	min-width: 150px;
	max-width: 180px;
}

.addButton {
	white-space: nowrap;
	flex-shrink: 0;
}

.prefSelect {
	min-width: 180px;
	max-width: 250px;
	margin-top: 12px;
}

.hiddenFileInput {
	display: none;
}

.importToggle {
	margin-top: 16px;
	margin-bottom: 16px;
}

.importHint {
	color: var(--color-text-maxcontrast);
	margin-top: 4px;
	padding-left: 56px;
}

.importSuccess {
	color: var(--color-success-text);
	font-weight: bold;
	margin-top: 12px;
}

.importError {
	color: var(--color-error-text);
	font-weight: bold;
	margin-top: 12px;
}
</style>
