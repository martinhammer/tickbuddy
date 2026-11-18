<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue'
import NcAppSidebar from '@nextcloud/vue/components/NcAppSidebar'
import NcAppSidebarHeader from '@nextcloud/vue/components/NcAppSidebarHeader'
import NcAppSidebarTab from '@nextcloud/vue/components/NcAppSidebarTab'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { MDI_ARCHIVE_OUTLINE, MDI_DELETE_OUTLINE, MDI_FORMAT_LIST_CHECKS, MDI_LINK_VARIANT, MDI_PENCIL_OUTLINE } from '../icons.ts'
import { trackSubname } from '../publicLinks.ts'
import type { Track, TrackOption } from '../types.ts'
import PublicLinksTab from './PublicLinksTab.vue'

// A track's details in Nextcloud's sidebar: the name with a rename pencil after
// it and the type, then two tabs. Details holds the Private switch, and Archive
// and Delete as buttons rather than behind a ⋯ menu (as on Tickdroid's track
// screen); Public links holds the track's links. The sidebar opens on Details,
// and keeps the tab shown when another track's sidebar replaces this one's, as
// Files' sidebar does.
//
// NcAppSidebar has no slot after the name, so the name block is drawn in its info
// slot, which replaces the built-in name, subname and actions but keeps the close
// button. The name stays an NcAppSidebarHeader, which the sidebar focuses on open.

// The server's limit (TrackService::MAX_NAME_LENGTH). maxlength counts UTF-16
// units, so names with emoji stop a little sooner, never later.
const MAX_NAME_LENGTH = 255

/** A Number's or Choice's setting, saved on its own as Private is */
export type TrackSetting =
	| { field: 'unit', value: string }
	| { field: 'decimalPlaces', value: number }
	| { field: 'allowNegative', value: boolean }
	| { field: 'orderedScale', value: boolean }

const props = defineProps<{
	track: Track
	/** Start editing the name (Rename track in the row's ⋯ menu) */
	renameRequested?: boolean
}>()

const emit = defineEmits<{
	close: []
	rename: [name: string]
	'rename-started': []
	'set-private': [value: boolean]
	'set-setting': [setting: TrackSetting]
	'rename-option': [option: TrackOption, label: string]
	archive: []
	delete: []
	/** A public link was created or revoked */
	'links-changed': []
}>()

// Kept across a track change: this component stays mounted while the track
// changes, and is mounted afresh (on Details) when the sidebar opens again
const activeTab = ref('details')

const editing = ref(false)
const draft = ref(props.track.name)
const nameInput = ref<HTMLInputElement | null>(null)
const pencil = ref<InstanceType<typeof NcButton> | null>(null)
// The sidebar focuses its header once it has slid in, so a rename asked for while
// it opens waits for that
const opened = ref(false)

watch(() => props.track.id, () => {
	editing.value = false
	draft.value = props.track.name
	unitDraft.value = props.track.unit ?? ''
	renamingOption.value = null
})

// --- Number settings ---
const DECIMAL_CHOICES = [0, 1, 2, 3].map(n => ({ id: n, label: String(n) }))

const unitDraft = ref(props.track.unit ?? '')
watch(() => props.track.unit, (unit) => {
	unitDraft.value = unit ?? ''
})

// Enter or losing focus saves a changed unit (empty is no unit); Escape puts it back
function saveUnit(): void {
	const unit = unitDraft.value.trim()
	unitDraft.value = unit
	if (unit !== (props.track.unit ?? '')) emit('set-setting', { field: 'unit', value: unit })
}

function cancelUnit(event: KeyboardEvent): void {
	unitDraft.value = props.track.unit ?? ''
	;(event.target as HTMLElement | null)?.blur()
}

const decimalPlaces = computed({
	get: () => DECIMAL_CHOICES.find(c => c.id === (props.track.decimalPlaces ?? 1)) ?? DECIMAL_CHOICES[1],
	set: (choice: { id: number } | null) => {
		if (choice && choice.id !== props.track.decimalPlaces) emit('set-setting', { field: 'decimalPlaces', value: choice.id })
	},
})

// --- Choice options ---
// Renamed in place, as the track's name is: Enter or losing focus saves a
// changed label that isn't blank, Escape cancels
const renamingOption = ref<number | null>(null)
const optionDraft = ref('')
const optionInput = ref<HTMLInputElement[]>([])

async function startOptionRename(option: TrackOption): Promise<void> {
	optionDraft.value = option.label
	renamingOption.value = option.value
	await nextTick()
	optionInput.value[0]?.focus()
	optionInput.value[0]?.select()
}

function saveOption(option: TrackOption): void {
	if (renamingOption.value !== option.value) return
	renamingOption.value = null
	const label = optionDraft.value.trim()
	if (label && label !== option.label) emit('rename-option', option, label)
}

function cancelOption(): void {
	renamingOption.value = null
}

watch([() => props.renameRequested, opened], ([requested, isOpen]) => {
	if (!requested || !isOpen) return
	emit('rename-started')
	startEditing()
}, { immediate: true })

async function startEditing(): Promise<void> {
	draft.value = props.track.name
	editing.value = true
	await nextTick()
	nameInput.value?.focus()
	nameInput.value?.select()
}

// Enter and losing focus save; a blank or unchanged name isn't sent (the server
// refuses a blank one). Saving ends editing first, so the blur that follows
// Enter doesn't save twice.
function save(): void {
	if (!editing.value) return
	editing.value = false
	const trimmed = draft.value.trim()
	if (trimmed && trimmed !== props.track.name) emit('rename', trimmed)
}

function cancel(): void {
	editing.value = false
	draft.value = props.track.name
}

// After Enter or Escape the field is gone, so focus goes back to the pencil
// rather than to the page (a click elsewhere keeps its own focus)
async function finishWithKey(finish: () => void): Promise<void> {
	finish()
	await nextTick()
	const button = pencil.value?.$el as HTMLElement | undefined
	button?.focus()
}
</script>

<template>
	<NcAppSidebar v-model:active="activeTab"
		:name="track.name"
		:class="$style.sidebar"
		@opened="opened = true"
		@close="emit('close')">
		<template #info>
			<div :class="$style.head">
				<div :class="$style.nameRow">
					<NcAppSidebarHeader v-show="!editing"
						:class="$style.name"
						:name="track.name"
						:title="track.name"
						@click="startEditing" />
					<NcButton v-show="!editing"
						ref="pencil"
						:class="$style.pencil"
						variant="tertiary"
						size="small"
						aria-label="Rename track"
						title="Rename track"
						@click="startEditing">
						<template #icon>
							<NcIconSvgWrapper :path="MDI_PENCIL_OUTLINE" :size="20" :class="$style.pencilIcon" />
						</template>
					</NcButton>
					<input v-if="editing"
						ref="nameInput"
						v-model="draft"
						:class="$style.nameInput"
						type="text"
						:maxlength="MAX_NAME_LENGTH"
						placeholder="Name"
						aria-label="Track name"
						@keydown.enter.prevent="finishWithKey(save)"
						@keydown.esc.stop.prevent="finishWithKey(cancel)"
						@blur="save">
				</div>
				<p :class="$style.subname">
					{{ trackSubname(track, track.publicLinkCount) }}
				</p>
			</div>
		</template>

		<NcAppSidebarTab id="details" name="Details" :order="1">
			<template #icon>
				<NcIconSvgWrapper :path="MDI_FORMAT_LIST_CHECKS" :size="20" />
			</template>
			<div :class="$style.body">
				<NcCheckboxRadioSwitch :model-value="track.private"
					:class="$style.switch"
					type="switch"
					@update:model-value="(value: boolean) => emit('set-private', value)">
					Private
				</NcCheckboxRadioSwitch>
				<p :class="$style.help">
					Hidden in the journal and widgets unless private tracks are shown.
				</p>

				<!-- A Number's settings save as soon as they're edited, as Private does -->
				<div v-if="track.type === 'number'" :class="$style.settings">
					<NcTextField v-model="unitDraft"
						label="Unit (optional)"
						:maxlength="MAX_NAME_LENGTH"
						@keydown.enter.prevent="saveUnit"
						@keydown.esc.stop.prevent="cancelUnit"
						@blur="saveUnit" />
					<NcSelect v-model="decimalPlaces"
						:options="DECIMAL_CHOICES"
						:clearable="false"
						:searchable="false"
						input-label="Decimal places"
						:class="$style.decimals" />
					<p :class="$style.help">
						Fewer decimals show earlier days rounded; saved values don't change.
					</p>
					<NcCheckboxRadioSwitch :model-value="track.allowNegative ?? false"
						:class="$style.switch"
						type="switch"
						@update:model-value="(value: boolean) => emit('set-setting', { field: 'allowNegative', value })">
						Allow negative values
					</NcCheckboxRadioSwitch>
				</div>

				<!-- A Choice's options can be renamed, but not added, removed or reordered -->
				<div v-else-if="track.type === 'choice'" :class="$style.settings">
					<h3 :class="$style.settingsHeading">
						Options
					</h3>
					<ol :class="$style.options">
						<li v-for="option in track.options ?? []" :key="option.value" :class="$style.option">
							<span :class="$style.place">{{ option.value }}</span>
							<input v-if="renamingOption === option.value"
								ref="optionInput"
								v-model="optionDraft"
								:class="$style.optionInput"
								type="text"
								:maxlength="MAX_NAME_LENGTH"
								:aria-label="`Option ${option.value}`"
								@keydown.enter.prevent="saveOption(option)"
								@keydown.esc.stop.prevent="cancelOption"
								@blur="saveOption(option)">
							<template v-else>
								<span :class="$style.optionLabel" :title="option.label" @click="startOptionRename(option)">{{ option.label }}</span>
								<NcButton :class="$style.optionPencil"
									variant="tertiary"
									size="small"
									:aria-label="`Rename option ${option.label}`"
									title="Rename option"
									@click="startOptionRename(option)">
									<template #icon>
										<NcIconSvgWrapper :path="MDI_PENCIL_OUTLINE" :size="16" :class="$style.pencilIcon" />
									</template>
								</NcButton>
							</template>
						</li>
					</ol>
					<NcCheckboxRadioSwitch :model-value="track.orderedScale ?? false"
						:class="$style.switch"
						type="switch"
						@update:model-value="(value: boolean) => emit('set-setting', { field: 'orderedScale', value })">
						Ordered scale
					</NcCheckboxRadioSwitch>
					<p :class="$style.help">
						The options are ordered from lowest to highest.
					</p>
				</div>

				<div :class="$style.buttons">
					<NcButton variant="secondary" @click="emit('archive')">
						<template #icon>
							<NcIconSvgWrapper :path="MDI_ARCHIVE_OUTLINE" />
						</template>
						Archive track
					</NcButton>
					<NcButton variant="error" @click="emit('delete')">
						<template #icon>
							<NcIconSvgWrapper :path="MDI_DELETE_OUTLINE" />
						</template>
						Delete track
					</NcButton>
				</div>
			</div>
		</NcAppSidebarTab>
		<NcAppSidebarTab id="public-links" name="Public links" :order="2">
			<template #icon>
				<NcIconSvgWrapper :path="MDI_LINK_VARIANT" :size="20" />
			</template>
			<!-- Mounted each time the tab is shown, so it loads afresh -->
			<PublicLinksTab v-if="activeTab === 'public-links'"
				:track="track"
				@changed="emit('links-changed')" />
		</NcAppSidebarTab>
	</NcAppSidebar>
</template>

<style module>
/* At phone width the sidebar is 100vw wide and anchored right, so inside
   Nextcloud's content area (which has a margin) its left edge was clipped; fill
   that area instead. Doubled class to outrank the library's scoped rule */
@media only screen and (max-width: 512px) {
	.sidebar.sidebar:global(.app-sidebar) {
		--app-sidebar-width: 100%;
	}
}

/* The built-in header's spacing (NcAppSidebar's .app-sidebar-header__desc), with
   room at the end for the close button. Both variables are the sidebar's own */
.head {
	box-sizing: border-box;
	min-width: 0;
	padding-inline: var(--app-sidebar-padding);
	padding-inline-end: var(--app-sidebar-close-button-offset);
	padding-block: var(--app-sidebar-padding) calc(var(--app-sidebar-padding) / 2);
}

.nameRow {
	display: flex;
	align-items: center;
	gap: 4px;
	min-height: var(--default-clickable-area);
}

.name {
	min-width: 0;
	margin: 0;
	padding: 0;
	overflow: hidden;
	font-size: 20px;
	line-height: 30px;
	white-space: nowrap;
	text-overflow: ellipsis;
	cursor: text;
}

/* Grey like the note button: the text's muted colour mixed into the background,
   3:1 against it; the text colour under the pointer or with keyboard focus.
   Coloured on the icon, as the button's scoped tertiary colour outranks a class */
.pencil {
	flex: none;
}

.pencilIcon {
	color: color-mix(in srgb, var(--color-text-maxcontrast) 75%, var(--color-main-background));
}

.pencil:hover .pencilIcon,
.pencil:focus-visible .pencilIcon {
	color: var(--color-main-text);
}

/* Matches the built-in name field (font, size and padding) */
.head .nameInput {
	flex: 1 1 auto;
	min-width: 0;
	margin: 0;
	padding: 7px;
	font-size: 20px;
	font-weight: var(--font-weight-heading, bold);
}

.subname {
	overflow: hidden;
	margin: 0;
	color: var(--color-text-maxcontrast);
	white-space: nowrap;
	text-overflow: ellipsis;
}

/* One left edge under the name: the switch, its help text and the buttons share
   the header's inline padding */
.body {
	padding-block: 8px;
	padding-inline: var(--app-sidebar-padding);
}

/* The switch pads its content on both sides; without the start padding its
   toggle starts on the body's edge like everything else */
.body .switch :global(.checkbox-radio-switch__content) {
	padding-inline-start: 0;
}

.help {
	color: var(--color-text-maxcontrast);
}

.settings {
	display: flex;
	flex-direction: column;
	gap: 8px;
	margin-block-start: 16px;
}

.settings .help {
	margin: 0;
}

/* NcSelect's own rule (.nc-select.v-select.select, 0,3,0) sets a 260px
   minimum, so it doesn't follow its column; the selector has to outrank it */
.decimals:global(.nc-select.v-select.select) {
	width: 100%;
	min-width: 0;
	max-width: none;
}

.settingsHeading {
	margin: 0;
	font-size: inherit;
	font-weight: bold;
}

.options {
	display: flex;
	flex-direction: column;
	margin: 0;
	padding: 0;
	list-style: none;
}

.option {
	display: flex;
	align-items: center;
	gap: 8px;
	min-height: var(--default-clickable-area);
}

/* The place, muted, right-aligned in a column of its own */
.place {
	flex: none;
	min-width: 2ch;
	color: var(--color-text-maxcontrast);
	font-variant-numeric: tabular-nums;
	text-align: end;
}

.optionLabel {
	min-width: 0;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
	cursor: text;
}

/* Shown under the pointer, with focus and always on touchscreens, as Today's
   note button; hidden with opacity, so Tab reaches it */
.optionPencil {
	flex: none;
	opacity: 0;
}

.option:hover .optionPencil,
.option:focus-within .optionPencil {
	opacity: 1;
}

@media (hover: none) {
	.optionPencil {
		opacity: 1;
	}
}

.option .optionInput {
	flex: 1 1 auto;
	min-width: 0;
	margin: 0;
}

.buttons {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	margin-block-start: 16px;
}
</style>
