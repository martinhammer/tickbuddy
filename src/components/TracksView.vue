<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { generateOcsUrl } from '@nextcloud/router'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcListItem from '@nextcloud/vue/components/NcListItem'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
// Toasts are a Vue component with hashed CSS-module class names since @nextcloud/dialogs 7,
// so the server's own stylesheets cannot style them — the app must ship this itself.
import '@nextcloud/dialogs/style.css'
import { fetchTracks } from '../api.ts'
import { fmtDay, todayStr, userLocale } from '../dates.ts'
import {
	MDI_ARCHIVE_OUTLINE,
	MDI_ARROW_DOWN,
	MDI_ARROW_UP,
	MDI_CHART_BAR,
	MDI_CHEVRON_DOWN,
	MDI_CHEVRON_RIGHT,
	MDI_DELETE_OUTLINE,
	MDI_DRAG,
	MDI_FILE_SWAP_OUTLINE,
	MDI_PENCIL_OUTLINE,
	MDI_PLUS,
	MDI_RESTORE,
	MDI_TRAY_ARROW_DOWN,
	MDI_TRAY_ARROW_UP,
} from '../icons.ts'
import { ocsErrorMessage } from '../ocsError.ts'
import { errorReason, httpStatus } from '../strictHandling.ts'
import { dropIndex, typeName } from '../trackMenu.ts'
import type { TrackMenuItem } from '../trackMenu.ts'
import type { Track } from '../types.ts'
import { useToday } from '../useToday.ts'
import { refusedAsReadOnly } from '../writeErrors.ts'
import ExportDialog from './ExportDialog.vue'
import ImportDialog from './ImportDialog.vue'
import NewTrackDialog from './NewTrackDialog.vue'
import TrackConfirmDialogs from './TrackConfirmDialogs.vue'
import TrackSidebar from './TrackSidebar.vue'

// Setting up tracks: the list, details in the sidebar, New track, archive,
// delete, reorder, and Import and Export. The only screen that asks for
// archived tracks.

const props = defineProps<{
	/** The track whose sidebar is open */
	trackId: number | null
}>()

const emit = defineEmits<{
	'open-track': [trackId: number]
	'close-track': []
}>()

type ArchivedTrack = Track & { archivedAt: string }

const apiUrl = generateOcsUrl('/apps/tickbuddy/api/tracks')

// Active tracks in journal order, and archived tracks by name
const tracks = ref<Track[]>([])
const archivedTracks = ref<ArchivedTrack[]>([])
const status = ref<'loading' | 'ready' | 'error'>('loading')
// The archived list starts collapsed on every page load
const showArchived = ref(false)
const trackPendingDelete = ref<Track | null>(null)
const trackPendingArchive = ref<Track | null>(null)
const dragIndex = ref<number | null>(null)
const dragOverIndex = ref<number | null>(null)
const dialog = ref<'new' | 'import' | 'export' | null>(null)

const { loaded } = useToday(() => { loadTracks().catch(() => {}) })

const selectedTrack = computed(() => tracks.value.find(t => t.id === props.trackId) ?? null)

// An unknown or archived track named in the URL opens the list alone
watch([selectedTrack, status], () => {
	if (status.value === 'ready' && props.trackId !== null && !selectedTrack.value) {
		emit('close-track')
	}
})

async function loadTracks(): Promise<void> {
	const all = await fetchTracks(true)
	tracks.value = all.filter(t => t.archivedAt === null)
	archivedTracks.value = all
		.filter((t): t is ArchivedTrack => t.archivedAt !== null)
		.sort((a, b) => a.name.localeCompare(b.name, userLocale, { sensitivity: 'base' }))
	status.value = 'ready'
	loaded()
}

async function initialLoad(): Promise<void> {
	status.value = 'loading'
	try {
		await loadTracks()
	} catch {
		status.value = 'error'
	}
}

// After a change: a failed reload keeps the list as it is
async function reload(): Promise<void> {
	try {
		await loadTracks()
	} catch {
		// keep the list
	}
}

// The name of the first of `ids` that is now archived
function firstNowArchived(ids: number[]): string | undefined {
	for (const id of ids) {
		const track = archivedTracks.value.find(t => t.id === id)
		if (track) return track.name
	}
	return undefined
}

function openTrack(track: Track, event: MouseEvent | KeyboardEvent): void {
	// Let the browser open the link in a new tab or window
	if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return
	event.preventDefault()
	emit('open-track', track.id)
}

// --- The ⋯ menu ---
function menuItems(track: Track): TrackMenuItem[] {
	const index = tracks.value.findIndex(t => t.id === track.id)
	const items: TrackMenuItem[] = [
		{ key: 'analytics', label: 'Show analytics', icon: MDI_CHART_BAR, run: () => { window.location.hash = `#analytics/${track.id}` } },
		{ key: 'rename', label: 'Rename track', icon: MDI_PENCIL_OUTLINE, run: () => renameTrack(track) },
	]
	if (index > 0) {
		items.push({ key: 'up', label: 'Move up', icon: MDI_ARROW_UP, run: () => move(index, index - 1) })
	}
	if (index >= 0 && index < tracks.value.length - 1) {
		items.push({ key: 'down', label: 'Move down', icon: MDI_ARROW_DOWN, run: () => move(index, index + 1) })
	}
	items.push(
		{ key: 'archive', label: 'Archive track', icon: MDI_ARCHIVE_OUTLINE, run: () => { trackPendingArchive.value = track } },
		{ key: 'delete', label: 'Delete track', icon: MDI_DELETE_OUTLINE, danger: true, run: () => { trackPendingDelete.value = track } },
	)
	return items
}

// Rename opens the track's sidebar with its name already being edited (as Files'
// Rename does in a file's ⋯ menu)
const renamingTrackId = ref<number | null>(null)

function renameTrack(track: Track) {
	renamingTrackId.value = track.id
	if (props.trackId !== track.id) emit('open-track', track.id)
}

// --- Delete, archive, restore ---
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
	await reload()
}

async function setArchived(track: Track, archived: boolean) {
	const params = new URLSearchParams()
	params.append('archived', String(archived))
	if (archived) {
		// The browser's date, as for ticks
		params.append('archivedAt', todayStr())
	}
	let done = true
	try {
		await axios.put(`${apiUrl}/${track.id}`, params)
	} catch (e) {
		// Archived first by another tab or device: what the user asked for has happened
		// (the date stored first stands), so this is a success, not an error. Settled for
		// every client; don't turn it back into an error toast.
		const alreadyArchived = archived && httpStatus(e) === 423 && errorReason(e) === 'archived'
		if (!alreadyArchived) {
			if (await refusedAsReadOnly(e, track.name, loadTracks)) return
			showError(ocsErrorMessage(e, archived ? `Could not archive track "${track.name}"` : `Could not restore track "${track.name}"`))
			done = false
		}
	}
	if (done) {
		showSuccess(archived ? `Archived track "${track.name}".` : `Restored track "${track.name}".`)
		if (archived) {
			// Open the list so the track is seen arriving
			showArchived.value = true
		}
	}
	await reload()
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

// --- Sidebar ---
async function saveName(track: Track, name: string) {
	const params = new URLSearchParams()
	params.append('name', name)
	try {
		await axios.put(`${apiUrl}/${track.id}`, params)
		track.name = name
	} catch (e) {
		// The sidebar already fell back to showing the old name, so there is nothing to revert.
		if (await refusedAsReadOnly(e, track.name, loadTracks)) return
		showError(ocsErrorMessage(e, `Could not rename track "${track.name}"`))
	}
}

// The switch shows track.private, so a refused change leaves it as it was
async function setPrivate(track: Track, value: boolean) {
	const params = new URLSearchParams()
	params.append('private', String(value))
	try {
		await axios.put(`${apiUrl}/${track.id}`, params)
		track.private = value
	} catch (e) {
		if (await refusedAsReadOnly(e, track.name, loadTracks)) return
		showError(ocsErrorMessage(e, `Could not update privacy for track "${track.name}"`))
	}
}

// --- Reorder ---
async function move(fromIndex: number, toIndex: number) {
	if (fromIndex === toIndex) return
	const moved = tracks.value.splice(fromIndex, 1)[0]
	tracks.value.splice(toIndex, 0, moved)

	const trackIds = tracks.value.map(t => t.id)
	const params = new URLSearchParams()
	trackIds.forEach(id => params.append('trackIds[]', String(id)))
	try {
		const response = await axios.put(`${apiUrl}/reorder`, params)
		tracks.value = response.data.ocs.data
	} catch (e) {
		// A track in the list was archived elsewhere: the reload replaces the optimistic order
		if (await refusedAsReadOnly(e, () => firstNowArchived(trackIds), loadTracks)) return
		// The move above was applied optimistically; undo it so the list cannot show an
		// order the server rejected.
		tracks.value.splice(toIndex, 1)
		tracks.value.splice(fromIndex, 0, moved)
		showError(ocsErrorMessage(e, 'Could not save the new track order'))
	}
}

function onDragStart(index: number, event: DragEvent) {
	dragIndex.value = index
	if (event.dataTransfer) {
		event.dataTransfer.effectAllowed = 'move'
	}
}

function onDragOver(index: number, event: DragEvent) {
	// Only a drag started on a handle here, not a link dragged in from elsewhere
	if (dragIndex.value === null) return
	event.preventDefault()
	if (event.dataTransfer) {
		event.dataTransfer.dropEffect = 'move'
	}
	dragOverIndex.value = index
}

function onDragLeave() {
	dragOverIndex.value = null
}

function onDrop(toIndex: number) {
	const fromIndex = dragIndex.value
	dragIndex.value = null
	dragOverIndex.value = null
	if (fromIndex === null) return
	// move() does nothing when this is where the track already is, and undoes a
	// refused save at this index too
	move(fromIndex, dropIndex(fromIndex, toIndex))
}

function onDragEnd() {
	dragIndex.value = null
	dragOverIndex.value = null
}

// --- Dialogs ---
async function onCreated() {
	dialog.value = null
	await reload()
}

async function onImported() {
	dialog.value = null
	await reload()
}

onMounted(() => {
	initialLoad()
})
</script>

<template>
	<NcAppContent>
		<div :class="$style.page">
			<header :class="$style.header">
				<h2 :class="$style.title">
					Tracks
				</h2>
				<div :class="$style.headerActions">
					<NcButton variant="primary" @click="dialog = 'new'">
						<template #icon>
							<NcIconSvgWrapper :path="MDI_PLUS" />
						</template>
						New track
					</NcButton>
					<NcActions aria-label="Import and export" title="Import and export">
						<template #icon>
							<NcIconSvgWrapper :path="MDI_FILE_SWAP_OUTLINE" />
						</template>
						<NcActionButton close-after-click @click="dialog = 'import'">
							<template #icon>
								<NcIconSvgWrapper :path="MDI_TRAY_ARROW_UP" />
							</template>
							Import
						</NcActionButton>
						<NcActionButton close-after-click @click="dialog = 'export'">
							<template #icon>
								<NcIconSvgWrapper :path="MDI_TRAY_ARROW_DOWN" />
							</template>
							Export
						</NcActionButton>
					</NcActions>
				</div>
			</header>

			<NcLoadingIcon v-if="status === 'loading'" :size="32" :class="$style.loading" />
			<NcEmptyContent v-else-if="status === 'error'"
				name="Couldn't load your tracks"
				description="Check your connection, then try again.">
				<template #action>
					<NcButton @click="initialLoad">
						Try again
					</NcButton>
				</template>
			</NcEmptyContent>
			<template v-else>
				<ul v-if="tracks.length > 0" :class="$style.list">
					<NcListItem v-for="(track, index) in tracks"
						:key="track.id"
						:name="track.name"
						:href="`#tracks/${track.id}`"
						:active="track.id === trackId"
						force-display-actions
						:class="[$style.trackRow, { [$style.dragOver]: dragOverIndex === index }]"
						@click="openTrack(track, $event)"
						@dragover="onDragOver(index, $event)"
						@dragleave="onDragLeave"
						@drop="onDrop(index)"
						@dragend="onDragEnd">
						<template #icon>
							<span :class="$style.dragHandle"
								draggable="true"
								aria-hidden="true"
								title="Drag to reorder"
								@click.stop.prevent
								@dragstart.stop="onDragStart(index, $event)">
								<NcIconSvgWrapper :path="MDI_DRAG" />
							</span>
						</template>
						<template #subname>
							{{ typeName(track.type) }}{{ track.private ? ' · Private' : '' }}
						</template>
						<template #actions>
							<NcActionButton v-for="item in menuItems(track)"
								:key="item.key"
								:class="item.danger && $style.danger"
								close-after-click
								@click="item.run">
								<template #icon>
									<NcIconSvgWrapper :path="item.icon" />
								</template>
								{{ item.label }}
							</NcActionButton>
						</template>
					</NcListItem>
				</ul>
				<NcEmptyContent v-else
					name="No tracks yet"
					description="Add one with New track." />

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
					<ul v-show="showArchived" id="tickbuddy-archived-tracks" :class="$style.archivedList">
						<!-- Not NcListItems, which are always links, but laid out as they are:
							 an empty space the drag handle's width, the name where the active
							 names start, and the ⋯ in the same column -->
						<li v-for="track in archivedTracks" :key="track.id" :class="[$style.trackRow, $style.archivedRow]">
							<div :class="$style.archivedItem">
								<NcIconSvgWrapper :path="MDI_DRAG" :class="$style.handleSpace" />
								<div :class="$style.archivedText">
									<div :class="$style.archivedName">
										{{ track.name }}
										<span :class="$style.archivedDate">archived {{ fmtDay(track.archivedAt) }}</span>
									</div>
									<div :class="$style.archivedType">
										{{ typeName(track.type) }}{{ track.private ? ' · Private' : '' }}
									</div>
								</div>
								<NcActions :class="$style.archivedActions">
									<NcActionButton close-after-click @click="setArchived(track, false)">
										<template #icon>
											<NcIconSvgWrapper :path="MDI_RESTORE" />
										</template>
										Restore track
									</NcActionButton>
									<NcActionButton :class="$style.danger" close-after-click @click="trackPendingDelete = track">
										<template #icon>
											<NcIconSvgWrapper :path="MDI_DELETE_OUTLINE" />
										</template>
										Delete track
									</NcActionButton>
								</NcActions>
							</div>
						</li>
					</ul>
				</template>
			</template>
		</div>

		<TrackConfirmDialogs :archiving="trackPendingArchive"
			:deleting="trackPendingDelete"
			@confirm-archive="confirmArchiveTrack"
			@confirm-delete="confirmDeleteTrack"
			@archive-instead="archiveInsteadOfDelete"
			@cancel="trackPendingArchive = null; trackPendingDelete = null" />
		<NewTrackDialog v-if="dialog === 'new'" @close="dialog = null" @created="onCreated" />
		<ImportDialog v-if="dialog === 'import'" @close="dialog = null" @imported="onImported" />
		<ExportDialog v-if="dialog === 'export'" @close="dialog = null" />
	</NcAppContent>

	<TrackSidebar v-if="selectedTrack"
		:track="selectedTrack"
		:rename-requested="renamingTrackId === selectedTrack.id"
		@close="emit('close-track')"
		@rename-started="renamingTrackId = null"
		@archive="trackPendingArchive = selectedTrack"
		@delete="trackPendingDelete = selectedTrack"
		@rename="(name) => selectedTrack && saveName(selectedTrack, name)"
		@set-private="(value) => selectedTrack && setPrivate(selectedTrack, value)" />
</template>

<style module>
.page {
	box-sizing: border-box;
	max-width: 760px;
	padding: 8px 16px 16px;
	/* The navigation's toggle sits over the top left of the content */
	padding-inline-start: 52px;
}

.header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 8px;
	min-height: 50px;
	margin-block-end: 8px;
}

.title {
	margin: 0;
	font-size: 20px;
	font-weight: bold;
}

.headerActions {
	display: flex;
	align-items: center;
	gap: 4px;
}

.loading {
	margin-block-start: 48px;
}

.list {
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.dragHandle {
	display: flex;
	align-items: center;
	color: var(--color-text-maxcontrast);
	cursor: grab;
}

.dragHandle:active {
	cursor: grabbing;
}

.dragOver {
	box-shadow: inset 0 2px 0 var(--color-primary-element);
}

.danger :global(.action-button) {
	color: var(--color-error-text);
}

.archivedToggle {
	margin-block-start: 16px;
}

.archivedList {
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.trackRow {
	border-block-end: 1px solid var(--color-border);
}

/* NcListItem's spacing: its wrapper's 2px 4px, then the item's grid baseline */
.archivedRow {
	padding: 2px 4px;
}

.archivedItem {
	box-sizing: border-box;
	display: flex;
	align-items: center;
	height: calc(2lh + 2 * var(--default-grid-baseline));
	padding: var(--default-grid-baseline);
}

/* Takes the drag handle's room without showing it */
.handleSpace {
	flex: none;
	visibility: hidden;
}

.archivedText {
	flex: 1 1 auto;
	min-width: 0;
	padding-inline-start: calc(2 * var(--default-grid-baseline));
}

.archivedName {
	overflow: hidden;
	font-weight: var(--font-weight-element, 500);
	white-space: nowrap;
	text-overflow: ellipsis;
}

.archivedDate {
	color: var(--color-text-maxcontrast);
	margin-inline-start: 8px;
	white-space: nowrap;
}

.archivedType {
	overflow: hidden;
	color: var(--color-text-maxcontrast);
	white-space: nowrap;
	text-overflow: ellipsis;
}

.archivedActions {
	flex: none;
	margin-inline-start: var(--default-grid-baseline);
}

@media (max-width: 1023px) {
	.page {
		padding-inline-end: 12px;
	}
}
</style>
