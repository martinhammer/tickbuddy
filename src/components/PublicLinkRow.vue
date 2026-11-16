<script setup lang="ts">
import { nextTick, ref } from 'vue'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import { MDI_CHECK, MDI_CONTENT_COPY, MDI_DELETE_OUTLINE, MDI_LINK_VARIANT, MDI_PENCIL_OUTLINE } from '../icons.ts'
import { lastUsedText, linkDetails } from '../publicLinks.ts'
import type { PublicLink } from '../types.ts'

// One public link in the Public links tab: a round link icon, the name, what it
// does and when it was last called, then the copy button and the ⋯ menu (Rename,
// Revoke). Renaming leaves just the icon and a name field, which works as the
// track's name in the sidebar. The address itself is never shown.

// The server's limit (PublicLinkService::MAX_NAME_LENGTH)
const MAX_NAME_LENGTH = 255

const props = defineProps<{
	link: PublicLink
	/** For "Last used": the time the list was loaded */
	now: Date
	/** Just copied: the copy button shows a tick, as Files' does */
	copied: boolean
}>()

const emit = defineEmits<{
	copy: []
	rename: [name: string]
	revoke: []
}>()

const editing = ref(false)
const draft = ref('')
const nameInput = ref<HTMLInputElement | null>(null)
const menu = ref<InstanceType<typeof NcActions> | null>(null)

async function startEditing(): Promise<void> {
	draft.value = props.link.name
	editing.value = true
	await nextTick()
	nameInput.value?.focus()
	nameInput.value?.select()
}

// Enter or losing focus saves; a blank or unchanged name isn't sent. Saving ends
// editing first, so the blur that follows Enter doesn't save twice.
function save(): void {
	if (!editing.value) return
	editing.value = false
	const trimmed = draft.value.trim()
	if (trimmed && trimmed !== props.link.name) emit('rename', trimmed)
}

function cancel(): void {
	editing.value = false
}

// After Enter or Escape the field is gone, so focus goes to the ⋯ rather than
// to the page (a click elsewhere keeps its own focus)
async function finishWithKey(finish: () => void): Promise<void> {
	finish()
	await nextTick()
	const root = menu.value?.$el as HTMLElement | undefined
	root?.querySelector('button')?.focus()
}
</script>

<template>
	<li :class="$style.row">
		<span :class="$style.avatar" aria-hidden="true">
			<NcIconSvgWrapper :path="MDI_LINK_VARIANT" :size="20" />
		</span>
		<input v-if="editing"
			ref="nameInput"
			v-model="draft"
			:class="$style.nameInput"
			type="text"
			:maxlength="MAX_NAME_LENGTH"
			aria-label="Link name"
			@keydown.enter.prevent="finishWithKey(save)"
			@keydown.esc.stop.prevent="finishWithKey(cancel)"
			@blur="save">
		<template v-else>
			<div :class="$style.text">
				<div :class="$style.name" :title="link.name">
					{{ link.name }}
				</div>
				<div :class="$style.details">
					{{ linkDetails(link) }}
				</div>
				<div :class="$style.details">
					{{ lastUsedText(link.lastUsedAt, now) }}
				</div>
			</div>
			<NcButton variant="tertiary"
				aria-label="Copy link"
				:title="copied ? 'Link copied' : 'Copy link'"
				@click="emit('copy')">
				<template #icon>
					<NcIconSvgWrapper v-if="copied" :path="MDI_CHECK" :class="$style.copied" />
					<NcIconSvgWrapper v-else :path="MDI_CONTENT_COPY" />
				</template>
			</NcButton>
			<NcActions ref="menu">
				<NcActionButton close-after-click @click="startEditing">
					<template #icon>
						<NcIconSvgWrapper :path="MDI_PENCIL_OUTLINE" />
					</template>
					Rename
				</NcActionButton>
				<NcActionButton close-after-click @click="emit('revoke')">
					<template #icon>
						<NcIconSvgWrapper :path="MDI_DELETE_OUTLINE" />
					</template>
					Revoke
				</NcActionButton>
			</NcActions>
		</template>
	</li>
</template>

<style module>
.row {
	display: flex;
	align-items: center;
	gap: 8px;
	padding-block: 6px;
}

/* White on the theme colour, toned down with the muted text colour */
.avatar {
	display: flex;
	flex: none;
	align-items: center;
	justify-content: center;
	width: 34px;
	height: 34px;
	border-radius: 50%;
	background-color: color-mix(in srgb, var(--color-primary-element) 70%, var(--color-text-maxcontrast));
	color: white;
}

.text {
	flex: 1 1 auto;
	min-width: 0;
}

.name {
	overflow: hidden;
	white-space: nowrap;
	text-overflow: ellipsis;
}

.details {
	overflow: hidden;
	color: var(--color-text-maxcontrast);
	white-space: nowrap;
	text-overflow: ellipsis;
}

.copied {
	color: var(--color-border-success);
}

/* Under the row's class, to outrank core's input styles */
.row .nameInput {
	flex: 1 1 auto;
	min-width: 0;
	margin: 0;
}
</style>
