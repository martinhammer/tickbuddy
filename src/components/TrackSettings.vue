<script setup lang="ts">
import { ref, onMounted } from 'vue'
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { generateOcsUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcSettingsSection from '@nextcloud/vue/components/NcSettingsSection'
// Toasts are a Vue component with hashed CSS-module class names since @nextcloud/dialogs 7,
// so the server's own stylesheets cannot style them — the app must ship this itself.
import '@nextcloud/dialogs/style.css'
import { fmtDay, todayStr, userLocale } from '../dates.ts'
import { ocsErrorMessage } from '../ocsError.ts'
import type { Track } from '../types.ts'

// Material Design Icons "pencil" and chevrons, copied in to avoid a dependency for three icons.
const MDI_PENCIL = 'M20.71,7.04C21.1,6.65 21.1,6 20.71,5.63L18.37,3.29C18,2.9 17.35,2.9 16.96,3.29L15.12,5.12L18.87,8.87M3,17.25V21H6.75L17.81,9.93L14.06,6.18L3,17.25Z'
const MDI_CHEVRON_RIGHT = 'M8.59,16.58L13.17,12L8.59,7.41L10,6L16,12L10,18L8.59,16.58Z'
const MDI_CHEVRON_DOWN = 'M7.41,8.58L12,13.17L16.59,8.58L18,10L12,16L6,10L7.41,8.58Z'

type ArchivedTrack = Track & { archivedAt: string }

// Active tracks in journal order, and archived tracks by name
const tracks = ref<Track[]>([])
const archivedTracks = ref<ArchivedTrack[]>([])
// The archived list starts collapsed on every page load
const showArchived = ref(false)
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
const trackPendingDelete = ref<Track | null>(null)
const trackPendingArchive = ref<Track | null>(null)
const dragIndex = ref<number | null>(null)
const dragOverIndex = ref<number | null>(null)

// Import state
const replaceMode = ref(false)
const importFile = ref<File | null>(null)
const importFileName = ref('')
const importFormat = ref<'tickmate' | 'json' | null>(null)
const importing = ref(false)
const confirmingReplace = ref(false)
const tickmateInputRef = ref<HTMLInputElement | null>(null)
const jsonInputRef = ref<HTMLInputElement | null>(null)

// Export state
const exportPrivate = ref(false)
const exporting = ref(false)

const apiUrl = generateOcsUrl('/apps/tickbuddy/api/tracks')
const prefsUrl = generateOcsUrl('/apps/tickbuddy/api/preferences')

async function fetchTracks() {
	loading.value = true
	try {
		const response = await axios.get(apiUrl, { params: { includeArchived: true } })
		const all: Track[] = response.data.ocs.data
		tracks.value = all.filter(t => t.archivedAt === null)
		archivedTracks.value = all
			.filter((t): t is ArchivedTrack => t.archivedAt !== null)
			.sort((a, b) => a.name.localeCompare(b.name, userLocale, { sensitivity: 'base' }))
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
	try {
		await axios.post(apiUrl, params)
	} catch (e) {
		// Keep the typed name and type so the user can correct and retry.
		showError(ocsErrorMessage(e, 'Could not add track'))
		return
	}
	newTrackName.value = ''
	newTrackType.value = trackTypeOptions[0]
	await fetchTracks()
}

function deleteTrack(track: Track) {
	trackPendingDelete.value = track
}

async function confirmDeleteTrack() {
	const track = trackPendingDelete.value
	if (!track) return
	trackPendingDelete.value = null
	try {
		await axios.delete(`${apiUrl}/${track.id}`)
		showSuccess(`Deleted track "${track.name}".`)
	} catch (e) {
		showError(ocsErrorMessage(e, `Could not delete track "${track.name}"`))
	}
	await fetchTracks()
}

async function setArchived(track: Track, archived: boolean) {
	const params = new URLSearchParams()
	params.append('archived', String(archived))
	if (archived) {
		// The browser's date, as for ticks
		params.append('archivedAt', todayStr())
	}
	try {
		await axios.put(`${apiUrl}/${track.id}`, params)
		showSuccess(archived ? `Archived track "${track.name}".` : `Restored track "${track.name}".`)
		if (archived) {
			// Open the list so the track is seen arriving
			showArchived.value = true
		}
	} catch (e) {
		showError(ocsErrorMessage(e, archived ? `Could not archive track "${track.name}"` : `Could not restore track "${track.name}"`))
	}
	await fetchTracks()
}

function confirmArchiveTrack() {
	const track = trackPendingArchive.value
	if (!track) return
	trackPendingArchive.value = null
	setArchived(track, true)
}

function archiveInsteadOfDelete() {
	const track = trackPendingDelete.value
	if (!track) return
	trackPendingDelete.value = null
	setArchived(track, true)
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
	try {
		await axios.put(`${apiUrl}/${track.id}`, params)
		track.name = trimmed
	} catch (e) {
		// The row already fell back to showing the old name, so there is nothing to revert.
		showError(ocsErrorMessage(e, `Could not rename track "${track.name}"`))
	}
}

async function togglePrivate(track: Track, event: Event) {
	const newValue = !track.private
	const params = new URLSearchParams()
	params.append('private', String(newValue))
	try {
		await axios.put(`${apiUrl}/${track.id}`, params)
		track.private = newValue
	} catch (e) {
		// The native checkbox flipped itself on click. `track.private` is unchanged, so the
		// rendered `:checked` prop is unchanged too and Vue patches nothing — the box would
		// stay visually flipped against the stored value. Put it back by hand.
		const input = event.target as HTMLInputElement | null
		if (input) {
			input.checked = track.private
		}
		showError(ocsErrorMessage(e, `Could not update privacy for track "${track.name}"`))
	}
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
	try {
		const response = await axios.put(`${apiUrl}/reorder`, params)
		tracks.value = response.data.ocs.data
	} catch (e) {
		// The move above was applied optimistically; undo it so the list cannot show an
		// order the server rejected.
		tracks.value.splice(toIndex, 1)
		tracks.value.splice(fromIndex, 0, moved)
		showError(ocsErrorMessage(e, 'Could not save the new track order'))
	}
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
	const previous = defaultView.value
	defaultView.value = option
	const params = new URLSearchParams()
	params.append('defaultView', option.id)
	try {
		await axios.put(prefsUrl, params)
		showSuccess(`Default screen set to "${option.label}".`)
	} catch (e) {
		defaultView.value = previous
		showError(ocsErrorMessage(e, 'Could not save the default screen'))
	}
}

function onTickmateFileChange(event: Event) {
	const input = event.target as HTMLInputElement
	const file = input.files?.[0] ?? null
	importFile.value = file
	importFileName.value = file?.name ?? ''
	importFormat.value = file ? 'tickmate' : null
}

function onJsonFileChange(event: Event) {
	const input = event.target as HTMLInputElement
	const file = input.files?.[0] ?? null
	importFile.value = file
	importFileName.value = file?.name ?? ''
	importFormat.value = file ? 'json' : null
}

function chooseTickmateFile() {
	tickmateInputRef.value?.click()
}

function chooseJsonFile() {
	jsonInputRef.value?.click()
}

function doImport() {
	if (!importFile.value || !importFormat.value) return

	if (replaceMode.value) {
		confirmingReplace.value = true
		return
	}
	runImport()
}

function confirmReplaceImport() {
	confirmingReplace.value = false
	runImport()
}

async function runImport() {
	if (!importFile.value || !importFormat.value) return

	importing.value = true
	try {
		const formData = new FormData()
		formData.append('file', importFile.value)
		formData.append('mode', replaceMode.value ? 'replace' : 'merge')
		const url = importFormat.value === 'json'
			? generateOcsUrl('/apps/tickbuddy/api/import/json')
			: generateOcsUrl('/apps/tickbuddy/api/import')
		const response = await axios.post(url, formData)
		const data = response.data.ocs.data
		showSuccess(`Imported ${data.tracks} tracks and ${data.ticks} ticks.`)
		await fetchTracks()
	} catch (e) {
		showError(ocsErrorMessage(e, 'Import failed'))
	} finally {
		importing.value = false
	}
}

async function doExport() {
	exporting.value = true
	try {
		const response = await axios.get(generateOcsUrl('/apps/tickbuddy/api/export'), {
			params: { includePrivate: exportPrivate.value },
		})
		const data = response.data.ocs.data
		const json = JSON.stringify(data, null, 2)
		const blob = new Blob([json], { type: 'application/json' })
		const url = URL.createObjectURL(blob)
		const a = document.createElement('a')
		a.href = url
		a.download = `tickbuddy-export-${new Date().toISOString().slice(0, 10)}.json`
		a.click()
		URL.revokeObjectURL(url)
		showSuccess('Export downloaded.')
	} catch (e) {
		showError(ocsErrorMessage(e, 'Export failed'))
	} finally {
		exporting.value = false
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
			<NcButton variant="primary"
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
					<td :class="$style.nameCell" @click="startEditing(track)">
						<NcTextField v-if="editingTrackId === track.id"
							v-model="editingName"
							label="Track name"
							autofocus
							@keyup.enter="saveName(track)"
							@blur="saveName(track)" />
						<span v-else :class="$style.nameLabel" title="Rename track">
							{{ track.name }}
							<span :class="$style.editIcon">
								<NcIconSvgWrapper :path="MDI_PENCIL" :size="16" inline />
							</span>
						</span>
					</td>
					<td>{{ track.type === 'counter' ? 'Counter' : 'Yes / No' }}</td>
					<td :class="$style.privateCell">
						<input :id="`private-${track.id}`"
							type="checkbox"
							class="checkbox"
							:checked="track.private"
							@change="togglePrivate(track, $event)">
						<label :for="`private-${track.id}`" />
					</td>
					<td>
						<div :class="$style.rowActions">
							<NcButton variant="tertiary-no-background"
								aria-label="Archive track"
								@click="trackPendingArchive = track">
								Archive
							</NcButton>
							<NcButton variant="tertiary-no-background"
								aria-label="Delete track"
								@click="deleteTrack(track)">
								Delete
							</NcButton>
						</div>
					</td>
				</tr>
			</tbody>
		</table>
		<p v-else-if="!loading">
			No tracks defined yet. Add one above.
		</p>

		<template v-if="archivedTracks.length > 0">
			<NcButton variant="tertiary"
				alignment="start-reverse"
				:aria-expanded="String(showArchived)"
				aria-controls="tickbuddy-archived-tracks"
				:class="$style.archivedToggle"
				@click="showArchived = !showArchived">
				<template #icon>
					<!-- directional: the closed chevron points left in right-to-left languages -->
					<NcIconSvgWrapper :path="showArchived ? MDI_CHEVRON_DOWN : MDI_CHEVRON_RIGHT" directional />
				</template>
				Archived tracks ({{ archivedTracks.length }})
			</NcButton>
			<table v-show="showArchived" id="tickbuddy-archived-tracks" :class="[$style.trackTable, $style.archivedTable]">
				<tbody>
					<tr v-for="track in archivedTracks" :key="track.id">
						<td>
							{{ track.name }}
							<span :class="$style.archivedDate">archived {{ fmtDay(track.archivedAt) }}</span>
						</td>
						<td>{{ track.type === 'counter' ? 'Counter' : 'Yes / No' }}{{ track.private ? ' • Private' : '' }}</td>
						<td>
							<div :class="$style.rowActions">
								<NcButton variant="tertiary-no-background"
									aria-label="Restore track"
									@click="setArchived(track, false)">
									Restore
								</NcButton>
								<NcButton variant="tertiary-no-background"
									aria-label="Delete track"
									@click="deleteTrack(track)">
									Delete
								</NcButton>
							</div>
						</td>
					</tr>
				</tbody>
			</table>
		</template>
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

	<NcSettingsSection name="Import/Export"
		description="Export your data as a JSON file for backup or transfer. Import from a previous Tickbuddy export or from a Tickmate backup.">
		<h3 :class="$style.subsectionHeading">
			Export
		</h3>
		<div :class="$style.exportSection">
			<NcCheckboxRadioSwitch v-model="exportPrivate"
				type="switch">
				Include private tracks
			</NcCheckboxRadioSwitch>
			<NcButton variant="secondary"
				:disabled="exporting"
				@click="doExport">
				{{ exporting ? 'Exporting...' : 'Export as JSON' }}
			</NcButton>
		</div>

		<h3 :class="$style.subsectionHeading">
			Import
		</h3>
		<div :class="$style.importToggle">
			<NcCheckboxRadioSwitch v-model="replaceMode"
				type="switch">
				Replace existing database
			</NcCheckboxRadioSwitch>
			<p :class="$style.importHint">
				When enabled, all your existing tracks and ticks will be deleted and replaced with the imported data.
				When disabled, imported tracks will be added after your existing ones.
			</p>
		</div>

		<input ref="tickmateInputRef"
			type="file"
			accept=".db"
			:class="$style.hiddenFileInput"
			@change="onTickmateFileChange">
		<input ref="jsonInputRef"
			type="file"
			accept=".json"
			:class="$style.hiddenFileInput"
			@change="onJsonFileChange">
		<div :class="$style.importButtons">
			<NcButton variant="secondary"
				@click="chooseJsonFile">
				{{ importFormat === 'json' ? importFileName : 'Tickbuddy backup file (.json)' }}
			</NcButton>
			<NcButton variant="secondary"
				@click="chooseTickmateFile">
				{{ importFormat === 'tickmate' ? importFileName : 'Tickmate backup file (.db)' }}
			</NcButton>
		</div>

		<NcButton variant="primary"
			:disabled="!importFile || importing"
			:class="$style.importAction"
			@click="doImport">
			{{ importing ? 'Importing...' : 'Import' }}
		</NcButton>
	</NcSettingsSection>

	<NcDialog v-if="trackPendingArchive"
		:open="true"
		:name="`Archive &quot;${trackPendingArchive.name}&quot;`"
		size="small"
		:buttons="[
			{ label: 'Cancel', variant: 'secondary', callback: () => { trackPendingArchive = null } },
			{ label: 'Archive', variant: 'primary', callback: confirmArchiveTrack },
		]"
		@update:open="(open) => { if (!open) trackPendingArchive = null }">
		<p :class="$style.dialogText">
			Archived tracks are no longer tracked or shown in the journal and widgets. All their ticks are kept, and you can restore them at any time.
		</p>
	</NcDialog>

	<!-- The actions slot replaces the `buttons` prop, which can't place a button on the left -->
	<NcDialog v-if="trackPendingDelete"
		:open="true"
		:name="`Delete &quot;${trackPendingDelete.name}&quot;`"
		size="small"
		@update:open="(open) => { if (!open) trackPendingDelete = null }">
		<NcNoteCard type="warning">
			This will permanently delete the track "{{ trackPendingDelete.name }}" and all its ticks. This action cannot be undone.
		</NcNoteCard>
		<p v-if="trackPendingDelete.archivedAt === null" :class="$style.dialogText">
			To keep its history, archive it instead.
		</p>
		<template #actions>
			<NcButton v-if="trackPendingDelete.archivedAt === null"
				variant="tertiary"
				:class="$style.archiveInstead"
				@click="archiveInsteadOfDelete">
				Archive instead
			</NcButton>
			<NcButton variant="secondary" @click="trackPendingDelete = null">
				Cancel
			</NcButton>
			<NcButton variant="primary" @click="confirmDeleteTrack">
				Delete
			</NcButton>
		</template>
	</NcDialog>

	<NcDialog v-if="confirmingReplace"
		:open="true"
		name="Replace all data"
		size="small"
		:buttons="[
			{ label: 'Cancel', variant: 'secondary', callback: () => { confirmingReplace = false } },
			{ label: 'Import and replace', variant: 'primary', callback: confirmReplaceImport },
		]"
		@update:open="(open) => { if (!open) confirmingReplace = false }">
		<NcNoteCard type="warning">
			This will permanently delete all your existing tracks and ticks and replace them with the imported data. This action cannot be undone.
		</NcNoteCard>
	</NcDialog>
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

.rowActions {
	display: flex;
	gap: 4px;
}

.archivedToggle {
	margin-top: 16px;
}

/* The toggle sits right above it, so the table's own top margin isn't needed */
.archivedTable {
	margin-top: 0;
}

.archivedDate {
	color: var(--color-text-maxcontrast);
	margin-inline-start: 8px;
	white-space: nowrap;
}

/* Plain text in a dialog gets NcNoteCard's 1rem above and below, so it sits as far
   from the title and the buttons as the warning boxes in the other dialogs do */
.dialogText {
	margin-block: 1rem;
}

/* The dialog's action row is justify-content: end; this pushes the button to the left */
.archiveInstead {
	margin-inline-end: auto;
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

.nameLabel {
	display: inline-flex;
	align-items: center;
	gap: 6px;
}

/* Opacity sits on this wrapper because NcIconSvgWrapper's scoped
   `.icon-vue { opacity: 1 }` outranks a plain class on the component. */
.editIcon {
	display: inline-flex;
	color: var(--color-text-maxcontrast);
	opacity: 0;
	transition: opacity var(--animation-quick);
}

.nameCell:hover .editIcon {
	opacity: 1;
}

/* Touch screens cannot hover, so keep the hint visible there. */
@media (hover: none) {
	.editIcon {
		opacity: 1;
	}
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

.subsectionHeading {
	font-size: 16px;
	font-weight: bold;
	margin-bottom: 12px;
}

.exportSection {
	display: flex;
	flex-direction: column;
	align-items: flex-start;
	gap: 12px;
	margin-bottom: 24px;
}

.hiddenFileInput {
	display: none;
}

.importButtons {
	display: flex;
	gap: 8px;
}

.importToggle {
	margin-bottom: 16px;
}

.importAction {
	margin-top: 12px;
}

.importHint {
	color: var(--color-text-maxcontrast);
	margin-top: 4px;
	padding-left: 56px;
}
</style>
