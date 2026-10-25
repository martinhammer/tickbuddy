<script setup lang="ts">
import { nextTick, onMounted, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import { MDI_CLOSE } from '../icons.ts'

// Editing a note in place. Enter saves, Shift+Enter adds a line break, Escape
// cancels, losing focus saves, and ✕ deletes the note. Saving empty text
// deletes it too. While a save runs the field is read-only; when it fails the
// field stays open with what was typed, so nothing is lost.

// The server's limit (NoteService::MAX_LENGTH) in code points. maxlength counts
// UTF-16 units, so a note with emoji stops a little sooner, never later.
const MAX_LENGTH = 500

type Save = (text: string) => Promise<boolean>

const props = defineProps<{
	/** The stored note, "" when there is none */
	text: string
	trackName: string
	/** Stores the text ("" deletes the note); resolves false when it wasn't saved */
	save: Save
}>()

const emit = defineEmits<{
	close: []
}>()

const root = ref<HTMLElement | null>(null)
const draft = ref(props.text)
const busy = ref(false)
// Closed, or closing: a blur after Enter or Escape must not save again
let done = false

function textarea(): HTMLTextAreaElement | null {
	return root.value?.querySelector('textarea') ?? null
}

// NcTextArea keeps its height; grow it with the text instead.
function fit(): void {
	const el = textarea()
	if (!el) return
	el.style.height = 'auto'
	el.style.height = `${el.scrollHeight + el.offsetHeight - el.clientHeight}px`
}

async function commit(text: string): Promise<void> {
	if (done || busy.value) return
	const trimmed = text.trim()
	if (trimmed === props.text.trim()) {
		done = true
		emit('close')
		return
	}
	busy.value = true
	const saved = await props.save(trimmed)
	busy.value = false
	if (saved) {
		done = true
		emit('close')
	}
}

function remove(): void {
	if (props.text === '') {
		// Nothing stored to delete: drop what was typed
		done = true
		emit('close')
		return
	}
	commit('')
}

function onKeydown(event: KeyboardEvent): void {
	if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
		event.preventDefault()
		commit(draft.value)
	} else if (event.key === 'Escape') {
		// Only this field closes, not the sidebar or popover it sits in
		event.preventDefault()
		event.stopPropagation()
		done = true
		emit('close')
	}
}

function onFocusout(event: FocusEvent): void {
	// Tabbing to ✕ stays within the field
	if (root.value?.contains(event.relatedTarget as Node | null)) return
	commit(draft.value)
}

onMounted(async () => {
	await nextTick()
	const el = textarea()
	if (!el) return
	fit()
	el.focus()
	el.setSelectionRange(el.value.length, el.value.length)
})
</script>

<template>
	<!-- click.stop: a click in the field (✕ included) is not a click on what holds it -->
	<div ref="root"
		:class="$style.field"
		@click.stop
		@keydown="onKeydown"
		@focusout="onFocusout">
		<div :class="$style.box">
			<NcTextArea v-model="draft"
				label-outside
				:aria-label="`Note for ${trackName}`"
				:maxlength="MAX_LENGTH"
				:readonly="busy"
				resize="none"
				rows="1"
				:input-class="$style.input"
				@input="fit" />
			<!-- mousedown.prevent: a click on ✕ mustn't blur the text first, which would save it -->
			<NcButton variant="tertiary"
				size="small"
				aria-label="Delete note"
				title="Delete note"
				:disabled="busy"
				:class="$style.delete"
				@mousedown.prevent
				@click="remove">
				<template #icon>
					<NcIconSvgWrapper :path="MDI_CLOSE" :size="16" />
				</template>
			</NcButton>
		</div>
		<p :class="$style.hint">
			Enter saves · Shift+Enter new line · Escape cancels · ✕ deletes the note
		</p>
	</div>
</template>

<style module>
.field {
	min-width: 0;
}

.box {
	position: relative;
}

/* Room for ✕, which sits inside the border at the top right */
.input {
	min-height: 0 !important;
	padding-inline-end: 36px !important;
	overflow-y: hidden;
}

.delete {
	position: absolute !important;
	inset-block-start: 4px;
	inset-inline-end: 4px;
}

.hint {
	margin-block-start: 4px;
	color: var(--color-text-maxcontrast);
	font-size: 12px;
	line-height: 1.3;
}
</style>
