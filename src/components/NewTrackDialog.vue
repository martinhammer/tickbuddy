<script setup lang="ts">
import { computed, nextTick, ref } from 'vue'
import axios from '@nextcloud/axios'
import { showError } from '@nextcloud/dialogs'
import { generateOcsUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { MDI_CLOSE, MDI_DRAG, MDI_EYE_OUTLINE, MDI_PLUS } from '../icons.ts'
import { ocsErrorMessage } from '../ocsError.ts'
import { dropIndex, gapAt } from '../trackMenu.ts'

// The server's limit (TrackService::MAX_NAME_LENGTH), so a longer paste is cut
// here instead of refused. maxlength counts UTF-16 units, so names with emoji
// stop a little sooner, never later.
const MAX_NAME_LENGTH = 255
// The server's limits for a Choice (TrackService::MIN_OPTIONS, MAX_OPTIONS)
const MIN_OPTIONS = 2
const MAX_OPTIONS = 99
const DECIMAL_CHOICES = [0, 1, 2, 3].map(n => ({ id: n, label: String(n) }))

const emit = defineEmits<{
	close: []
	created: []
}>()

const apiUrl = generateOcsUrl('/apps/tickbuddy/api/tracks')

const name = ref('')
const type = ref('boolean')
const isPrivate = ref(false)
const adding = ref(false)

// Number: what the dialog starts with. Switching type keeps what was typed in
// the other type's fields; only the chosen type's are sent.
const unit = ref('')
const decimalPlaces = ref(DECIMAL_CHOICES[1])
const allowNegative = ref(false)

// Choice: one field per option, two to start. `id` keys the fields while they move.
interface OptionDraft {
	id: number
	label: string
}
let nextOptionId = 0
const options = ref<OptionDraft[]>([{ id: nextOptionId++, label: '' }, { id: nextOptionId++, label: '' }])
const orderedScale = ref(false)
const optionList = ref<HTMLElement | null>(null)

// The second of two labels that are the same ignoring case (as the server compares them)
const repeatedOptions = computed(() => {
	const seen = new Set<string>()
	const repeated = new Set<number>()
	for (const option of options.value) {
		const key = option.label.trim().toLocaleLowerCase()
		if (key === '') continue
		if (seen.has(key)) repeated.add(option.id)
		seen.add(key)
	}
	return repeated
})

const optionsReady = computed(() => options.value.every(o => o.label.trim() !== '') && repeatedOptions.value.size === 0)

const canAdd = computed(() => name.value.trim() !== '' && !adding.value && (type.value !== 'choice' || optionsReady.value))

async function focusOption(index: number): Promise<void> {
	await nextTick()
	optionList.value?.querySelectorAll('input')[index]?.focus()
}

function addOption(): void {
	if (options.value.length >= MAX_OPTIONS) return
	options.value.push({ id: nextOptionId++, label: '' })
	focusOption(options.value.length - 1)
}

function removeOption(index: number): void {
	if (options.value.length <= MIN_OPTIONS) return
	options.value.splice(index, 1)
	focusOption(Math.min(index, options.value.length - 1))
}

// Enter moves to the next option, or adds one after the last
function onOptionEnter(index: number): void {
	if (index < options.value.length - 1) {
		focusOption(index + 1)
	} else {
		addOption()
	}
}

function moveOption(from: number, to: number): void {
	if (to < 0 || to >= options.value.length || from === to) return
	const [moved] = options.value.splice(from, 1)
	options.value.splice(to, 0, moved)
}

// Dragged by the handle, as the Tracks page's rows; the arrow keys on the
// handle are the keyboard's way
const dragIndex = ref<number | null>(null)
const dragGap = ref<number | null>(null)

function onDragStart(index: number, event: DragEvent): void {
	dragIndex.value = index
	if (event.dataTransfer) event.dataTransfer.effectAllowed = 'move'
}

function onDragOver(index: number, event: DragEvent): void {
	if (dragIndex.value === null) return
	event.preventDefault()
	dragGap.value = gapAt(index, event.clientY, (event.currentTarget as HTMLElement).getBoundingClientRect())
}

function onDrop(event: DragEvent): void {
	event.preventDefault()
	if (dragIndex.value !== null && dragGap.value !== null) moveOption(dragIndex.value, dropIndex(dragIndex.value, dragGap.value))
	dragIndex.value = null
	dragGap.value = null
}

function onDragEnd(): void {
	dragIndex.value = null
	dragGap.value = null
}

async function onHandleKey(index: number, delta: number, event: KeyboardEvent): Promise<void> {
	const to = index + delta
	if (to < 0 || to >= options.value.length) return
	moveOption(index, to)
	await nextTick()
	const handles = optionList.value?.querySelectorAll<HTMLElement>('[data-handle]')
	handles?.[to]?.focus()
	event.preventDefault()
}

// What the chosen type adds to the create
function settings(): Record<string, unknown> {
	if (type.value === 'number') {
		return { unit: unit.value.trim(), decimalPlaces: decimalPlaces.value.id, allowNegative: allowNegative.value }
	}
	if (type.value === 'choice') {
		return { options: options.value.map(o => o.label.trim()), orderedScale: orderedScale.value }
	}
	return {}
}

async function add(): Promise<void> {
	const trimmed = name.value.trim()
	if (!canAdd.value) return
	adding.value = true
	let id: number
	try {
		// JSON, so a Choice's options go as a real list
		const response = await axios.post(apiUrl, { name: trimmed, type: type.value, ...settings() })
		id = response.data.ocs.data.id
	} catch (e) {
		// Keep what was typed so the user can correct and retry.
		showError(ocsErrorMessage(e, 'Could not add track'))
		adding.value = false
		return
	}
	// The server takes no `private` on create, so this is a second call, as in Tickdroid.
	// If it fails the track stays, not private.
	if (isPrivate.value) {
		const privateParams = new URLSearchParams()
		privateParams.append('private', 'true')
		try {
			await axios.put(`${apiUrl}/${id}`, privateParams)
		} catch {
			showError('Track created, but it couldn\'t be made private. Open it to try again.')
		}
	}
	adding.value = false
	emit('created')
}
</script>

<template>
	<NcDialog :open="true"
		name="New track"
		size="small"
		@update:open="(open) => { if (!open) emit('close') }">
		<div :class="$style.form">
			<p :class="$style.intro">
				A track is a habit or event you record each day.
			</p>
			<NcTextField v-model="name"
				label="Name"
				:maxlength="MAX_NAME_LENGTH"
				placeholder="e.g. Exercise, Coffee, Reading…"
				autofocus
				@keyup.enter="add" />
			<fieldset :class="$style.type">
				<legend :class="$style.legend">
					Type
				</legend>
				<NcCheckboxRadioSwitch v-model="type"
					type="radio"
					value="boolean"
					name="tickbuddy-new-track-type">
					Yes/No
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch v-model="type"
					type="radio"
					value="counter"
					name="tickbuddy-new-track-type">
					Counter
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch v-model="type"
					type="radio"
					value="number"
					name="tickbuddy-new-track-type">
					Number
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch v-model="type"
					type="radio"
					value="choice"
					name="tickbuddy-new-track-type">
					Choice
				</NcCheckboxRadioSwitch>
				<p :class="$style.hint">
					The type can't be changed later.
				</p>
			</fieldset>

			<div v-if="type === 'number'" :class="$style.settings">
				<!-- NcTextField shows its label inside the empty field, so a placeholder never shows -->
				<NcTextField v-model="unit"
					label="Unit (optional)"
					:maxlength="MAX_NAME_LENGTH" />
				<NcSelect v-model="decimalPlaces"
					:options="DECIMAL_CHOICES"
					:clearable="false"
					:searchable="false"
					input-label="Decimal places"
					:class="$style.decimals" />
				<NcCheckboxRadioSwitch v-model="allowNegative" type="switch">
					Allow negative values
				</NcCheckboxRadioSwitch>
			</div>

			<div v-else-if="type === 'choice'" :class="$style.settings">
				<div :class="$style.legend">
					Options
				</div>
				<ol ref="optionList" :class="$style.options">
					<li v-for="(option, index) in options"
						:key="option.id"
						:class="[$style.option, {
							[$style.dragOver]: dragGap === index,
							[$style.dragOverEnd]: dragGap === options.length && index === options.length - 1,
						}]"
						@dragover="onDragOver(index, $event)"
						@dragleave="dragGap = null"
						@drop="onDrop"
						@dragend="onDragEnd">
						<span :class="$style.handle"
							data-handle
							draggable="true"
							tabindex="0"
							role="button"
							aria-label="Move option"
							title="Move option"
							@dragstart="onDragStart(index, $event)"
							@keydown.up="onHandleKey(index, -1, $event)"
							@keydown.down="onHandleKey(index, 1, $event)">
							<NcIconSvgWrapper :path="MDI_DRAG" />
						</span>
						<NcTextField v-model="option.label"
							:label="`Option ${index + 1}`"
							:label-outside="true"
							:placeholder="`Option ${index + 1}`"
							:maxlength="MAX_NAME_LENGTH"
							:error="repeatedOptions.has(option.id)"
							:helper-text="repeatedOptions.has(option.id) ? 'Another option has this label.' : ''"
							:class="$style.optionField"
							@keydown.enter.prevent="onOptionEnter(index)" />
						<NcButton v-if="options.length > MIN_OPTIONS"
							variant="tertiary"
							aria-label="Remove option"
							title="Remove option"
							@click="removeOption(index)">
							<template #icon>
								<NcIconSvgWrapper :path="MDI_CLOSE" />
							</template>
						</NcButton>
					</li>
				</ol>
				<NcButton v-if="options.length < MAX_OPTIONS" variant="tertiary" @click="addOption">
					<template #icon>
						<NcIconSvgWrapper :path="MDI_PLUS" />
					</template>
					Add option
				</NcButton>
				<p :class="$style.hint">
					Options can be renamed later, but not added, removed or reordered.
				</p>
				<NcCheckboxRadioSwitch v-model="orderedScale" type="switch">
					Ordered scale
					<template #description>
						The options are ordered from lowest to highest.
					</template>
				</NcCheckboxRadioSwitch>
			</div>

			<NcCheckboxRadioSwitch v-model="isPrivate" type="switch">
				Private
				<template #description>
					Hidden unless Show private tracks
					<NcIconSvgWrapper :path="MDI_EYE_OUTLINE" :size="16" inline />
					is selected. Never shown on the dashboard widget.
				</template>
			</NcCheckboxRadioSwitch>
		</div>
		<template #actions>
			<NcButton variant="secondary" @click="emit('close')">
				Cancel
			</NcButton>
			<NcButton variant="primary" :disabled="!canAdd" @click="add">
				Add track
			</NcButton>
		</template>
	</NcDialog>
</template>

<style module>
.form {
	display: flex;
	flex-direction: column;
	gap: 16px;
	padding-block: 8px;
}

.intro {
	margin: 0;
}

.type {
	margin: 0;
	padding: 0;
	border: none;
}

.legend {
	margin-block-end: 6px;
	font-weight: bold;
}

.hint {
	margin-block-start: 6px;
	color: var(--color-text-maxcontrast);
}

/* The chosen type's settings, straight under Type's hint, in the dialog's own column */
.settings {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.settings .hint {
	margin: 0;
}

/* NcSelect's own rule (.nc-select.v-select.select, 0,3,0) sets a 260px
   minimum, so it doesn't follow its column; the selector has to outrank it */
.decimals:global(.nc-select.v-select.select) {
	width: 100%;
	min-width: 0;
	max-width: none;
}

.options {
	display: flex;
	flex-direction: column;
	gap: 4px;
	margin: 0;
	padding: 0;
	list-style: none;
}

.option {
	display: flex;
	align-items: flex-start;
	gap: 4px;
}

.handle {
	flex: none;
	display: flex;
	align-items: center;
	justify-content: center;
	height: var(--default-clickable-area);
	color: var(--color-text-maxcontrast);
	cursor: grab;
}

.optionField {
	flex: 1 1 auto;
	min-width: 0;
}

.dragOver {
	box-shadow: 0 -2px 0 var(--color-primary-element);
}

.dragOverEnd {
	box-shadow: 0 2px 0 var(--color-primary-element);
}
</style>
