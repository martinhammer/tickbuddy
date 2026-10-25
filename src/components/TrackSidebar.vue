<script setup lang="ts">
import { ref, watch } from 'vue'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcAppSidebar from '@nextcloud/vue/components/NcAppSidebar'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import { MDI_PENCIL } from '../icons.ts'
import { typeName } from '../trackMenu.ts'
import type { TrackMenuItem } from '../trackMenu.ts'
import type { Track } from '../types.ts'

// A track's details in Nextcloud's sidebar: the name, editable in place, the
// type, and the Private switch. No tabs until there is a second one.

// The server's limit (TrackService::MAX_NAME_LENGTH). The sidebar's own field has
// no maxlength, so longer input is cut here; this counts UTF-16 units, so names
// with emoji stop a little sooner, never later.
const MAX_NAME_LENGTH = 255

const props = defineProps<{
	track: Track
	menuItems: TrackMenuItem[]
}>()

const emit = defineEmits<{
	close: []
	rename: [name: string]
	'set-private': [value: boolean]
}>()

const editing = ref(false)
const draft = ref(props.track.name)

watch(() => props.track.id, () => {
	editing.value = false
	draft.value = props.track.name
})

function startEditing(): void {
	draft.value = props.track.name
	editing.value = true
}

function onNameEditable(value: boolean): void {
	if (value && !editing.value) draft.value = props.track.name
	editing.value = value
}

function onNameInput(value: string): void {
	draft.value = value.slice(0, MAX_NAME_LENGTH)
}

// Enter, the → button and a click outside all save; Escape cancels. A blank
// name isn't sent (the server refuses it).
function submit(): void {
	const trimmed = draft.value.trim()
	if (trimmed && trimmed !== props.track.name) emit('rename', trimmed)
}
</script>

<template>
	<NcAppSidebar :name="editing ? draft : track.name"
		:name-editable="editing"
		name-placeholder="Name"
		:subname="typeName(track.type)"
		@update:name-editable="onNameEditable"
		@update:name="onNameInput"
		@submit-name="submit"
		@close="emit('close')">
		<template #tertiary-actions>
			<NcButton variant="tertiary"
				aria-label="Rename track"
				title="Rename track"
				@click="startEditing">
				<template #icon>
					<NcIconSvgWrapper :path="MDI_PENCIL" />
				</template>
			</NcButton>
		</template>
		<template #secondary-actions>
			<NcActionButton v-for="item in menuItems"
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

		<div :class="$style.body">
			<NcCheckboxRadioSwitch :model-value="track.private"
				type="switch"
				@update:model-value="(value: boolean) => emit('set-private', value)">
				Private
			</NcCheckboxRadioSwitch>
			<p :class="$style.help">
				Hidden in the journal and widgets unless private tracks are shown.
			</p>
		</div>
	</NcAppSidebar>
</template>

<style module>
.body {
	padding: 8px 16px;
}

.help {
	color: var(--color-text-maxcontrast);
	padding-inline-start: 4px;
}

.danger :global(.action-button) {
	color: var(--color-error-text);
}
</style>
