<script setup lang="ts">
import { computed, nextTick, ref } from 'vue'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import { userLocale } from '../dates.ts'
import { choiceLabel, trackValueText } from '../heatmap.ts'
import { MDI_NOTE_TEXT_OUTLINE } from '../icons.ts'
import type { JournalActions } from '../journal.ts'
import { decimalsOf, formatNumber } from '../numberValue.ts'
import type { Track } from '../types.ts'
import ChoiceField from './ChoiceField.vue'
import NoteField from './NoteField.vue'
import NoteText from './NoteText.vue'
import NumberField from './NumberField.vue'

// One day of one track. At rest it reads like View journal did (✓, or the
// count); under the pointer or with keyboard focus a floating bar shows the
// controls, with the checkbox or the count exactly where the value sits at rest,
// then the note button. On a touchscreen they always show, without the note
// button. The controls are always in the DOM, so Tab reaches them. A locked day
// keeps its at-rest look throughout, and a click on it says why.
//
// A Number shows only its value at rest (the unit is under the track's name);
// its bar holds the field where the value sits, then the unit. A Choice shows
// its option's label; its bar holds a button that looks like the drop-down and
// mounts the real one (NcSelect) when used, so a grid of hundreds of cells has
// one at most. On a touchscreen both show their value as text, and a tap turns
// it into the field, with the keyboard up, or opens the list.

const props = defineProps<{
	track: Track
	date: string
	actions: JournalActions
}>()

const editing = ref(false)
const noteBox = ref<HTMLElement | null>(null)

const isYesNo = computed(() => props.track.type === 'boolean')
const isCounter = computed(() => props.track.type === 'counter')
const isNumber = computed(() => props.track.type === 'number')
const isChoice = computed(() => props.track.type === 'choice')
const stored = computed(() => props.actions.valueOf(props.track.id, props.date))
// Yes/No and Counter read an empty day as 0; a Number's 0 is a reading
const value = computed(() => stored.value ?? 0)
const note = computed(() => props.actions.noteOf(props.track.id, props.date))
const locked = computed(() => props.actions.isLocked(props.date))
const dayLabel = computed(() => props.actions.dayLabel(props.date))

// A Number or Choice cell with a value: its note dot follows the text
const hasShownValue = computed(() => (isNumber.value || isChoice.value) && stored.value !== null)

// What a Number or Choice cell shows at rest
const shownValue = computed(() => {
	if (stored.value === null) return ''
	if (isNumber.value) return formatNumber(stored.value, decimalsOf(props.track), userLocale)
	return choiceLabel(stored.value, props.track)
})

const label = computed(() => {
	let shown: string
	if (isNumber.value || isChoice.value) {
		shown = stored.value === null ? 'no value' : trackValueText(stored.value, props.track)
	} else {
		shown = isYesNo.value ? (value.value > 0 ? 'ticked' : 'not ticked') : String(value.value)
	}
	return `${props.track.name}, ${dayLabel.value}: ${shown}${note.value ? ', has a note' : ''}`
})

const fieldLabel = computed(() => `${props.track.name}, ${dayLabel.value}`)

// The Number field has focus, or the Choice's list is mounted: the bar stays
// open even when the pointer leaves the cell
const active = ref(false)
const numberField = ref<InstanceType<typeof NumberField> | null>(null)

async function activate(): Promise<void> {
	if (locked.value) return
	active.value = true
	if (isNumber.value) {
		await nextTick()
		numberField.value?.focus()
	}
}

function saveValue(next: number | null): Promise<boolean> {
	return props.actions.setValue(props.track, props.date, next)
}

function pick(place: number | null): void {
	props.actions.setValue(props.track, props.date, place)
}

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
		<!-- At a fixed spot beside a ✓ or a count; a Number's value or a Choice's
			 label is wider, so there it follows the text (dotAfter, below) -->
		<span v-if="note && !hasShownValue" :class="$style.dot" aria-hidden="true" />

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

		<template v-else-if="isNumber || isChoice">
			<!-- At rest, and the tap target on a touchscreen; the mouse and the
				 keyboard use the bar, which covers it -->
			<span :class="$style.valueWrap">
				<span v-if="locked"
					:class="[isNumber ? $style.num : $style.label, note && $style.labelWithDot]"
					:title="isChoice ? shownValue : undefined"
					aria-hidden="true">{{ shownValue }}</span>
				<button v-else
					type="button"
					tabindex="-1"
					:class="[$style.valueButton, isNumber ? $style.num : $style.label, note && $style.labelWithDot, $style.hidesOnHover, active && $style.hidden]"
					:title="isChoice ? shownValue : undefined"
					aria-hidden="true"
					@click="activate">
					{{ shownValue }}
				</button>
				<!-- Outside the label, which is cut, so it always shows -->
				<span v-if="note && hasShownValue && !active" :class="[$style.dot, $style.dotAfter]" aria-hidden="true" />
			</span>
			<span v-if="!locked"
				:class="[$style.control, $style.bar, isNumber ? $style.numberBar : $style.choiceBar, active && $style.activeBar]">
				<template v-if="isNumber">
					<NumberField ref="numberField"
						:track="track"
						:value="stored"
						:save="saveValue"
						:label="fieldLabel"
						@focusin="active = true"
						@done="active = false" />
					<span v-if="track.unit" :class="$style.unit" :title="track.unit">{{ track.unit }}</span>
				</template>
				<template v-else>
					<span v-if="active" :class="$style.choiceSlot">
						<ChoiceField :track="track"
							:value="stored"
							:label="fieldLabel"
							open-on-mount
							@pick="pick"
							@close="active = false" />
					</span>
					<button v-else
						type="button"
						:class="$style.choiceButton"
						:aria-label="`${fieldLabel}: ${trackValueText(stored, track)}`"
						:title="shownValue || undefined"
						@click="activate">
						<span :class="$style.choiceButtonText">{{ shownValue || '–' }}</span>
					</button>
				</template>
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
	--bar-height: 40px;
	position: relative;
	display: flex;
	align-items: center;
	justify-content: center;
	min-width: 30px;
	min-height: 34px;
}

/* Above the grid's sticky first column (4), so the floating bar and the note
   box aren't drawn under it, and below its sticky header row (6), so nothing
   covers the header while scrolling. The layers are listed in the grids. */
.cell:hover,
.cell:has(:focus-visible),
.cell:has(.activeBar),
.editing {
	z-index: 5;
}

/* At rest the controls are invisible but still there for the keyboard */
.control {
	opacity: 0;
	pointer-events: none;
}

.cell:hover .control,
.cell:has(:focus-visible) .control,
.control.activeBar {
	opacity: 1;
	pointer-events: auto;
}

.cell:hover .hidesOnHover,
.cell:has(:focus-visible) .hidesOnHover,
.hidden {
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

.num {
	white-space: nowrap;
}

/* A Choice's label, cut to the cell; the full label in its tooltip */
.label {
	display: block;
	min-width: 0;
	/* A length rather than a percentage, which a table cell's width can't resolve:
	   each grid sets it to its column's width */
	max-width: var(--cell-label-width, 100%);
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
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

/* The value and, with a note, the dot right after it, so the dot follows the
   text's width instead of landing on it */
.valueWrap {
	position: relative;
	display: inline-flex;
	min-width: 0;
	max-width: 100%;
}

.dotAfter {
	inset-block-start: -2px;
	inset-inline-start: calc(100% + 2px);
}

/* Room for the dot after a cut label */
.labelWithDot {
	max-width: calc(var(--cell-label-width, 100%) - 8px);
}

/* The floating bar: vertically centred on the row, and placed from the cell's
   centre so the checkbox or the count sits exactly where the value does at rest.
   It may cover the cells beside it for that moment, so cells stay narrow. */
/* One height for every type's bar (--bar-height, on .cell, which the note box
   is placed from too), whatever its controls: the
   note button and the text controls (a Number's field, a Choice's drop-down)
   are --control-height and centred in it. --bar-padding is the space at the
   bar's ends, which each bar's centring below takes into account. */
.bar {
	--control-height: 32px;
	--bar-padding: 4px;
	--step-size: 26px;
	--count-width: 34px;
	--checkbox-size: 30px;
	position: absolute;
	inset-block-start: 50%;
	inset-inline-start: 50%;
	transform: translateY(-50%);
	display: flex;
	align-items: center;
	box-sizing: border-box;
	height: var(--bar-height);
	padding-block: 0;
	padding-inline: var(--bar-padding);
	border-radius: var(--border-radius-element, 8px);
	background: var(--color-main-background);
	box-shadow: 0 2px 8px var(--color-box-shadow);
	white-space: nowrap;
}

/* The field's centre on the cell's, the unit after it */
.numberBar {
	/* The field's ring is drawn outside its box (a box-shadow), where the Choice
	   button's border is inside it: 1px more padding, and a field 2px shorter,
	   so its ring at rest has the drop-down's outline exactly. The centring
	   below uses this padding, so the value stays put. */
	--bar-padding: 5px;
	--number-field-width: 9ch;
	--number-field-height: calc(var(--control-height) - 2px);
	/* No gap ring with focus, which would reach past the bar's padding */
	--number-field-focus-gap: 2px;
	gap: 4px;
	margin-inline-start: calc(-1 * (var(--bar-padding) + var(--number-field-width) / 2));
}

.unit {
	max-width: 8ch;
	overflow: hidden;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
	text-overflow: ellipsis;
}

/* The drop-down's centre on the cell's */
.choiceBar {
	--choice-width: 150px;
	margin-inline-start: calc(-1 * (var(--bar-padding) + var(--choice-width) / 2));
}

.choiceSlot {
	/* NcSelect takes its height from this variable: the button's height, so
	   the bar doesn't change when the real drop-down replaces it */
	--default-clickable-area: var(--control-height);
	display: block;
	width: var(--choice-width);
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
.cell .stepButton,
.cell .valueButton,
.cell .choiceButton {
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
.cell .stepButton:focus,
.cell .valueButton:hover,
.cell .valueButton:focus {
	background: transparent;
}

/* The at-rest value: as plain as the text it replaces */
.cell .valueButton {
	display: block;
	/* No max-width here: a label's own (.label, .labelWithDot) cuts it */
	min-height: 0;
	color: inherit;
	font-size: inherit;
	cursor: text;
}

/* Looks like the drop-down it stands for, which it mounts when used */
.cell .choiceButton {
	justify-content: flex-start;
	box-sizing: border-box;
	width: var(--choice-width);
	/* Core's button rule sets a 34px minimum */
	min-height: 0;
	height: var(--control-height);
	padding-inline: 12px;
	border: 2px solid var(--color-border-maxcontrast);
	border-radius: var(--border-radius-element, 8px);
	background: var(--color-main-background);
	color: var(--color-main-text);
	cursor: pointer;
}

.cell .choiceButton:hover,
.cell .choiceButton:focus {
	border-color: var(--color-main-text);
	background: var(--color-main-background);
}

.choiceButtonText {
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
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
	/* Under the floating bar rather than the cell, which can be shorter than the
	   bar: the bar is centred on the cell, so its bottom is half its height below
	   the cell's centre; then a small gap */
	inset-block-start: calc(50% + var(--bar-height) / 2 + 4px);
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

/* Bridges the gap to the bar, so the pointer moving down into the box doesn't
   leave the cell's hover and close it on the way */
.noteBox::before {
	content: '';
	position: absolute;
	inset-inline: 0;
	inset-block-end: 100%;
	height: 8px;
}

.locked .noteBox {
	cursor: default;
}

.cell:hover .noteBox,
.cell:has(:focus-visible) .noteBox,
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

	/* A Number or Choice shows its value as text; a tap turns it into the field
	   or opens the list, in the cell */
	.valueButton.hidesOnHover {
		display: block;
	}

	.valueButton.hidden,
	.numberBar:not(.activeBar),
	.choiceBar:not(.activeBar) {
		display: none;
	}

	.bar {
		position: static;
		transform: none;
		height: auto;
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
