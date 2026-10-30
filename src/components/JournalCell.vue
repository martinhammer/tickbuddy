<script setup lang="ts">
import { computed, ref } from 'vue'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import { MDI_NOTE_TEXT_OUTLINE } from '../icons.ts'
import type { JournalActions } from '../journal.ts'
import type { Track } from '../types.ts'
import NoteField from './NoteField.vue'
import NoteText from './NoteText.vue'

// One day of one track. At rest it reads like View journal did (✓, or the
// count); under the pointer or with keyboard focus a floating bar shows the
// controls, with the checkbox or the count exactly where the value sits at rest,
// then the note button. On a touchscreen they always show, without the note
// button. The controls are always in the DOM, so Tab reaches them. A locked day
// keeps its at-rest look throughout, and a click on it says why.

const props = defineProps<{
	track: Track
	date: string
	actions: JournalActions
}>()

const editing = ref(false)
const noteBox = ref<HTMLElement | null>(null)

const isYesNo = computed(() => props.track.type === 'boolean')
const isCounter = computed(() => props.track.type === 'counter')
const value = computed(() => props.actions.valueOf(props.track.id, props.date))
const note = computed(() => props.actions.noteOf(props.track.id, props.date))
const locked = computed(() => props.actions.isLocked(props.date))
const dayLabel = computed(() => props.actions.dayLabel(props.date))

const label = computed(() => {
	const shown = isYesNo.value ? (value.value > 0 ? 'ticked' : 'not ticked') : String(value.value)
	return `${props.track.name}, ${dayLabel.value}: ${shown}${note.value ? ', has a note' : ''}`
})

const noteLabel = computed(() => note.value
	? `Edit the note for ${props.track.name}`
	: `Add a note for ${props.track.name}`)

// A locked day changes nothing, and says why. Its note can still be read,
// "Show more" included.
function onClickCapture(event: Event): void {
	if (!locked.value) return
	if (noteBox.value?.contains(event.target as Node)) return
	event.preventDefault()
	event.stopPropagation()
	props.actions.lockedClick()
}

function onCheckboxChange(event: Event): void {
	const input = event.target as HTMLInputElement | null
	props.actions.setYesNo(props.track, props.date, input?.checked ?? value.value <= 0, input)
}

function openEditor(): void {
	if (!locked.value) editing.value = true
}

function save(text: string): Promise<boolean> {
	return props.actions.saveNote(props.track, props.date, text)
}
</script>

<template>
	<div :class="[$style.cell, locked && $style.locked, editing && $style.editing]"
		role="group"
		:aria-label="label"
		@click.capture="onClickCapture">
		<span v-if="note" :class="$style.dot" aria-hidden="true" />

		<template v-if="isYesNo">
			<span :class="[$style.mark, !locked && $style.hidesOnHover]" aria-hidden="true">{{ value > 0 ? '✓' : '' }}</span>
			<span v-if="!locked" :class="[$style.control, $style.bar, $style.yesNoBar]">
				<span :class="$style.checkboxSlot">
					<input :id="`tick-${track.id}-${date}`"
						type="checkbox"
						class="checkbox"
						:checked="value > 0"
						:aria-label="`${track.name}, ${dayLabel}`"
						@change="onCheckboxChange">
					<label :for="`tick-${track.id}-${date}`" />
				</span>
				<span :class="$style.divider" aria-hidden="true" />
				<button type="button"
					:class="$style.noteButton"
					:aria-label="noteLabel"
					:title="noteLabel"
					@click="openEditor">
					<NcIconSvgWrapper :path="MDI_NOTE_TEXT_OUTLINE" :size="20" :class="$style.noteIcon" />
				</button>
			</span>
		</template>

		<template v-else-if="isCounter">
			<span :class="[$style.num, value === 0 && $style.zero, !locked && $style.hidesOnHover]" aria-hidden="true">{{ value }}</span>
			<span v-if="!locked" :class="[$style.control, $style.bar, $style.counterBar]">
				<button type="button"
					:class="$style.stepButton"
					:aria-label="`Take one from ${track.name}`"
					:disabled="value <= 0"
					@click="actions.increment(track, date, -1)">
					−
				</button>
				<span :class="[$style.barCount, value === 0 && $style.zero]" aria-hidden="true">{{ value }}</span>
				<button type="button"
					:class="$style.stepButton"
					:aria-label="`Add one to ${track.name}`"
					@click="actions.increment(track, date, 1)">
					+
				</button>
				<span :class="$style.divider" aria-hidden="true" />
				<button type="button"
					:class="$style.noteButton"
					:aria-label="noteLabel"
					:title="noteLabel"
					@click="openEditor">
					<NcIconSvgWrapper :path="MDI_NOTE_TEXT_OUTLINE" :size="20" :class="$style.noteIcon" />
				</button>
			</span>
		</template>

		<!-- A type this web doesn't know: its stored value, read-only -->
		<span v-else :class="[$style.num, value === 0 && $style.zero]" aria-hidden="true">{{ value }}</span>

		<div v-if="note || editing"
			ref="noteBox"
			:class="[$style.noteBox, editing && $style.noteBoxEditing]"
			@click="openEditor">
			<div :class="$style.noteHead">
				{{ track.name }} · {{ dayLabel }}
			</div>
			<NoteField v-if="editing"
				:text="note"
				:track-name="track.name"
				:save="save"
				@close="editing = false" />
			<template v-else>
				<NoteText :text="note" />
				<div v-if="!locked" :class="$style.noteHint">
					Click to edit
				</div>
			</template>
		</div>
	</div>
</template>

<style module>
.cell {
	position: relative;
	display: flex;
	align-items: center;
	justify-content: center;
	min-width: 30px;
	min-height: 34px;
}

.cell:hover,
.cell:focus-within,
.editing {
	z-index: 3;
}

/* At rest the controls are invisible but still there for the keyboard */
.control {
	opacity: 0;
	pointer-events: none;
}

.cell:hover .control,
.cell:focus-within .control {
	opacity: 1;
	pointer-events: auto;
}

.cell:hover .hidesOnHover,
.cell:focus-within .hidesOnHover {
	opacity: 0;
}

.mark {
	color: var(--color-primary-element);
	font-size: 18px;
	font-weight: bold;
	line-height: 1;
}

.num {
	font-variant-numeric: tabular-nums;
}

.zero {
	color: var(--color-text-maxcontrast);
}

/* Beside the value, placed from the cell's centre rather than its corner, so it
   sits as close in Days down's wide cells as in Tracks down's 38px ones */
.dot {
	position: absolute;
	inset-block-start: 3px;
	inset-inline-start: calc(50% + 10px);
	width: 6px;
	height: 6px;
	border-radius: 50%;
	background: color-mix(in srgb, var(--color-main-text) 55%, transparent);
}

/* The floating bar: vertically centred on the row, and placed from the cell's
   centre so the checkbox or the count sits exactly where the value does at rest.
   It may cover the cells beside it for that moment, so cells stay narrow. */
.bar {
	--bar-padding: 2px;
	--step-size: 26px;
	--count-width: 34px;
	--checkbox-size: 30px;
	position: absolute;
	inset-block-start: 50%;
	inset-inline-start: 50%;
	transform: translateY(-50%);
	display: flex;
	align-items: center;
	padding: var(--bar-padding);
	border-radius: var(--border-radius-element, 8px);
	background: var(--color-main-background);
	box-shadow: 0 2px 8px var(--color-box-shadow);
	white-space: nowrap;
}

/* The checkbox's centre on the cell's */
.yesNoBar {
	margin-inline-start: calc(-1 * (var(--bar-padding) + var(--checkbox-size) / 2));
}

/* The count's centre on the cell's, "−" and "+" about 30px either side of it */
.counterBar {
	margin-inline-start: calc(-1 * (var(--bar-padding) + var(--step-size) + var(--count-width) / 2));
}

.checkboxSlot {
	display: flex;
	align-items: center;
	justify-content: center;
	width: var(--checkbox-size);
	height: var(--checkbox-size);
}

/* Nextcloud's checkbox draws its box on the label, with an uneven margin
   (3px before, 6px after) that would put it off the centre */
.cell .checkboxSlot input.checkbox + label {
	display: flex;
}

.cell .checkboxSlot input.checkbox + label::before {
	margin: 0;
}

.barCount {
	width: var(--count-width);
	text-align: center;
	font-variant-numeric: tabular-nums;
}

/* Nextcloud's core CSS draws every button that isn't an NcButton as a light
   primary button, with selectors a single class can't beat (button:not(.button-vue,
   …) is 0,1,1, and 0,2,1 with :hover, :focus or :disabled). So the cell's
   buttons are styled under .cell, as a scoped style would be. */
.cell .noteButton,
.cell .stepButton {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	/* Its margin rule adds a :not() (0,2,1), which only !important beats */
	margin: 0 !important;
	padding: 0;
	border: none;
	background: transparent;
	font-weight: normal;
	cursor: pointer;
}

.cell .noteButton:focus,
.cell .stepButton:focus {
	background: transparent;
}

.cell .stepButton {
	width: var(--step-size);
	min-width: var(--step-size);
	height: var(--step-size);
	min-height: var(--step-size);
	border-radius: 50%;
	color: var(--color-main-text);
	font-size: 16px;
	line-height: 1;
}

.cell .stepButton:hover:not(:disabled) {
	background: var(--color-background-hover);
}

.cell .stepButton:disabled {
	background: transparent;
	opacity: 0.4;
	cursor: default;
}

/* Today's size, grey at rest, the text colour under the pointer or with
   keyboard focus */
.cell .noteButton {
	width: 32px;
	min-width: 32px;
	height: 32px;
	min-height: 32px;
	border-radius: var(--border-radius-element, 8px);
	/* Lighter than the run text: --color-text-maxcontrast mixed a quarter of the
	   way into the background, which keeps 3:1 against it (WCAG's minimum for
	   icons) in light and dark themes */
	color: color-mix(in srgb, var(--color-text-maxcontrast) 75%, var(--color-main-background));
}

.cell .noteButton:hover,
.cell .noteButton:focus-visible {
	background: var(--color-background-hover);
	color: var(--color-main-text);
}

/* NcIconSvgWrapper is at least the default clickable area (34px or more), larger
   than the button, which pushed the icon off centre and stretched the bar. Its
   scoped rule is 0,2,0. */
.cell .noteButton .noteIcon {
	min-width: 0;
	min-height: 0;
}

.divider {
	width: 1px;
	height: 20px;
	margin-inline: 3px;
	background: var(--color-border);
}

/* Under the cell while the pointer is over it (or in the box), or while editing */
.noteBox {
	position: absolute;
	inset-block-start: 100%;
	inset-inline-start: 0;
	display: none;
	box-sizing: border-box;
	width: 190px;
	padding: 8px 10px;
	border-radius: var(--border-radius-large);
	background: var(--color-main-background);
	box-shadow: 0 2px 12px var(--color-box-shadow);
	color: var(--color-main-text);
	font-size: 13px;
	font-weight: normal;
	text-align: start;
	/* The table cell it sits in doesn't wrap */
	white-space: normal;
	cursor: text;
}

.locked .noteBox {
	cursor: default;
}

.cell:hover .noteBox,
.cell:focus-within .noteBox,
.noteBoxEditing {
	display: block;
}

.noteBoxEditing {
	width: 240px;
}

.noteHead {
	margin-block-end: 4px;
	color: var(--color-text-maxcontrast);
	font-size: 12px;
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
}

.noteHint {
	margin-block-start: 4px;
	color: var(--color-text-maxcontrast);
	font-size: 12px;
}

/* Touchscreens: no hover, so the controls always show, in the cell rather than
   floating (counter cells get wider; "−" and "+" are the same size, so the count
   stays centred), and notes are read and edited on Today instead */
@media (hover: none) {
	.control {
		opacity: 1;
		pointer-events: auto;
	}

	.hidesOnHover {
		display: none;
	}

	.bar {
		position: static;
		transform: none;
		margin: 0;
		padding: 0;
		box-shadow: none;
		background: transparent;
	}

	.noteButton,
	.divider,
	.noteBox {
		display: none !important;
	}
}
</style>
