<script setup lang="ts">
import { nextTick, ref, watch } from 'vue'
import NcAppSidebar from '@nextcloud/vue/components/NcAppSidebar'
import NcAppSidebarHeader from '@nextcloud/vue/components/NcAppSidebarHeader'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import { MDI_ARCHIVE_OUTLINE, MDI_DELETE_OUTLINE, MDI_PENCIL_OUTLINE } from '../icons.ts'
import { typeName } from '../trackMenu.ts'
import type { Track } from '../types.ts'

// A track's details in Nextcloud's sidebar: the name with a rename pencil after
// it, the type, the Private switch, and Archive and Delete as buttons in the body
// rather than behind a ⋯ menu (as on Tickdroid's track screen). No tabs until
// there is a second one.
//
// NcAppSidebar has no slot after the name, so the name block is drawn in its info
// slot, which replaces the built-in name, subname and actions but keeps the close
// button. The name stays an NcAppSidebarHeader, which the sidebar focuses on open.

// The server's limit (TrackService::MAX_NAME_LENGTH). maxlength counts UTF-16
// units, so names with emoji stop a little sooner, never later.
const MAX_NAME_LENGTH = 255

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
	archive: []
	delete: []
}>()

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
})

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
	<NcAppSidebar :name="track.name"
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
					{{ typeName(track.type) }}
				</p>
			</div>
		</template>

		<div :class="$style.body">
			<NcCheckboxRadioSwitch :model-value="track.private"
				type="switch"
				@update:model-value="(value: boolean) => emit('set-private', value)">
				Private
			</NcCheckboxRadioSwitch>
			<p :class="$style.help">
				Hidden in the journal and widgets unless private tracks are shown.
			</p>
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
	</NcAppSidebar>
</template>

<style module>
/* The built-in header's spacing (NcAppSidebar's .app-sidebar-header__desc), with
   room at the end for the close button */
.head {
	box-sizing: border-box;
	min-width: 0;
	padding-block: var(--app-sidebar-padding) calc(var(--app-sidebar-padding) / 2);
	padding-inline: var(--app-sidebar-padding) calc(var(--default-clickable-area) + 2 * var(--app-sidebar-padding));
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

.body {
	padding: 8px 16px;
}

.help {
	color: var(--color-text-maxcontrast);
	padding-inline-start: 4px;
}

.buttons {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	margin-block-start: 16px;
}
</style>
