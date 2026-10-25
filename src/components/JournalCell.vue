<script setup lang="ts">
import { computed, ref } from 'vue'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import { MDI_NOTE_TEXT_OUTLINE } from '../icons.ts'
import type { JournalActions } from '../journal.ts'
import type { Track } from '../types.ts'
import NoteField from './NoteField.vue'
import NoteText from './NoteText.vue'

// One day of one track. At rest it reads like View journal did (✓, or the
// count); under the pointer or with keyboard focus the controls show (Edit
// journal's checkbox, or − and + floating over the count). On a touchscreen they
// always show. The controls are always in the DOM, so Tab reaches them.

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
	<div :class="[$style.cell, locked && $style.locked, editing && $style.editing, isCounter && $style.counterCell]"
		role="group"
		:aria-label="label"
		@click.capture="onClickCapture">
		<span v-if="note" :class="$style.dot" aria-hidden="true" />

		<template v-if="isYesNo">
			<span :class="$style.stack">
				<span :class="[$style.mark, !locked && $style.hidesOnHover]" aria-hidden="true">{{ value > 0 ? '✓' : '' }}</span>
				<span v-if="!locked" :class="[$style.control, $style.checkbox]">
					<input :id="`tick-${track.id}-${date}`"
						type="checkbox"
						class="checkbox"
						:checked="value > 0"
						:aria-label="`${track.name}, ${dayLabel}`"
						@change="onCheckboxChange">
					<label :for="`tick-${track.id}-${date}`" />
				</span>
			</span>
			<button v-if="!locked"
				type="button"
				:class="[$style.control, $style.noteButton, $style.cornerNote]"
				:aria-label="noteLabel"
				:title="noteLabel"
				@click="openEditor">
				<NcIconSvgWrapper :path="MDI_NOTE_TEXT_OUTLINE" :size="14" />
			</button>
		</template>

		<template v-else-if="isCounter">
			<span :class="[$style.num, value === 0 && $style.zero, !locked && $style.hidesOnHover]" aria-hidden="true">{{ value }}</span>
			<span v-if="!locked" :class="[$style.control, $style.pill]">
				<button type="button"
					:class="$style.pillButton"
					:aria-label="`Take one from ${track.name}`"
					:disabled="value <= 0"
					@click="actions.increment(track, date, -1)">
					−
				</button>
				<span :class="$style.pillCount" aria-hidden="true">{{ value }}</span>
				<button type="button"
					:class="$style.pillButton"
					:aria-label="`Add one to ${track.name}`"
					@click="actions.increment(track, date, 1)">
					+
				</button>
				<span :class="$style.divider" aria-hidden="true" />
				<button type="button"
					:class="[$style.pillButton, $style.noteButton]"
					:aria-label="noteLabel"
					:title="noteLabel"
					@click="openEditor">
					<NcIconSvgWrapper :path="MDI_NOTE_TEXT_OUTLINE" :size="14" />
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

.locked .mark,
.locked .num {
	opacity: 0.55;
}

.stack {
	display: grid;
	place-items: center;
}

.stack > * {
	grid-area: 1 / 1;
}

.mark {
	color: var(--color-primary-element);
	font-size: 18px;
	font-weight: bold;
	line-height: 1;
}

/* Nextcloud's checkbox draws its box on the label */
.checkbox {
	display: flex;
}

.num {
	font-variant-numeric: tabular-nums;
}

.zero {
	color: var(--color-text-maxcontrast);
}

.dot {
	position: absolute;
	inset-block-start: 3px;
	inset-inline-end: 3px;
	width: 6px;
	height: 6px;
	border-radius: 50%;
	background: color-mix(in srgb, var(--color-main-text) 55%, transparent);
}

/* Nextcloud's core CSS draws every button that isn't an NcButton as a light
   primary button, with selectors a single class can't beat (button:not(.button-vue,
   …) is 0,1,1, and 0,2,1 with :hover, :focus or :disabled). So the cell's
   buttons are styled under .cell, as a scoped style would be. */
.cell .noteButton,
.cell .pillButton {
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
.cell .pillButton:focus {
	background: transparent;
}

.cell .cornerNote {
	position: absolute;
	inset-block-start: 0;
	inset-inline-end: 0;
	width: 18px;
	min-width: 0;
	height: 18px;
	min-height: 0;
	border-radius: 4px;
}

/* Floats over the count, and may cover the days beside it for that moment, so
   cells stay narrow. Centred with physical left: the centring is the same
   either way round. */
.pill {
	position: absolute;
	inset-block-start: 50%;
	/* stylelint-disable-next-line csstools/use-logical */
	left: 50%;
	transform: translate(-50%, -50%);
	display: flex;
	align-items: center;
	gap: 1px;
	padding: 2px;
	border-radius: 16px;
	background: var(--color-main-background);
	box-shadow: 0 2px 8px var(--color-box-shadow);
	white-space: nowrap;
}

.cell .pillButton {
	width: 26px;
	min-width: 26px;
	height: 26px;
	min-height: 26px;
	border-radius: 50%;
	color: var(--color-main-text);
	font-size: 16px;
	line-height: 1;
}

.cell .pillButton:hover:not(:disabled) {
	background: var(--color-background-hover);
}

.cell .pillButton:disabled {
	background: transparent;
	opacity: 0.4;
	cursor: default;
}

/* Grey at rest, the text colour under the pointer or with keyboard focus.
   After .pillButton, so the note button in the pill is grey too. */
.cell .noteButton {
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

.pillCount {
	min-width: 2ch;
	text-align: center;
	font-variant-numeric: tabular-nums;
	font-weight: 600;
}

.divider {
	width: 1px;
	height: 18px;
	margin-inline: 2px;
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

/* Touchscreens: no hover, so the controls always show (counter cells get wider),
   and notes are read and edited on Today instead */
@media (hover: none) {
	.control {
		opacity: 1;
		pointer-events: auto;
	}

	.hidesOnHover {
		display: none;
	}

	.pill {
		position: static;
		transform: none;
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
