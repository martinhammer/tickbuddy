<script setup lang="ts">
import { computed, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import { useIsMobile } from '@nextcloud/vue/composables/useIsMobile'
import { fmtDate, userLocale } from '../dates.ts'
import { choiceLabel, numberText, trackValueText } from '../heatmap.ts'
import { MDI_MINUS, MDI_NOTE_TEXT_OUTLINE, MDI_PLUS } from '../icons.ts'
import { decimalsOf, formatNumber } from '../numberValue.ts'
import { hashFor } from '../routes.ts'
import { runText } from '../runText.ts'
import type { Track, TrackStats } from '../types.ts'
import ChoiceField from './ChoiceField.vue'
import NoteField from './NoteField.vue'
import NoteText from './NoteText.vue'
import NumberField from './NumberField.vue'

type SaveNote = (text: string) => Promise<boolean>
type SaveValue = (value: number | null) => Promise<boolean>

export interface Square {
	date: string
	/** The stored value, null without a tick */
	value: number | null
	fill: string
	/** The day shown, outlined */
	shown: boolean
}

const props = defineProps<{
	track: Track
	/** The value on the day shown, null without a tick */
	value: number | null
	/** Seven days, newest first: the last seven, or the day shown and the six after it */
	squares: Square[]
	stats: TrackStats | undefined
	/** The day's note, "" when there is none */
	note: string
	locked: boolean
	/** A Number's value or a Choice's place (null clears the day) */
	saveValue: SaveValue
	saveNote: SaveNote
}>()

const emit = defineEmits<{
	'set-yes-no': [value: boolean]
	increment: [delta: number]
	locked: []
}>()

const editing = ref(false)

const isYesNo = props.track.type === 'boolean'
const isCounter = props.track.type === 'counter'
const isNumber = props.track.type === 'number'
const isChoice = props.track.type === 'choice'

// Under 1024px a Number's unit sits inside its field, so the last column is
// only ever the + there and every row keeps its width
const isMobile = useIsMobile()
const unitInField = computed(() => isMobile.value && isNumber && !!props.track.unit)

// Yes/No and Counter read an empty day as 0
const count = computed(() => props.value ?? 0)

const analyticsLabel = `Show analytics for ${props.track.name}`
const fieldLabel = props.track.unit ? `${props.track.name}, ${props.track.unit}` : props.track.name

function squareLabel(square: Square): string {
	return `${props.track.name}, ${fmtDate(square.date)}: ${trackValueText(square.value, props.track)}`
}

// A Number or Choice day is open without a value (a Number's 0 is a reading),
// a Yes/No or Counter day at 0
function isOpen(square: Square): boolean {
	return isNumber || isChoice ? square.value === null : (square.value ?? 0) <= 0
}

function pick(place: number | null): void {
	props.saveValue(place)
}

// A locked day shows the read-only value instead of the controls; a click on
// it says why nothing changes.
function onControlClick(): void {
	if (props.locked) emit('locked')
}

function openNote(): void {
	if (!props.locked) editing.value = true
}
</script>

<template>
	<li :class="$style.row">
		<div :class="$style.main">
			<!-- The name and the note icon, then the squares and the run on the line under them -->
			<div :class="$style.line1">
				<div :class="$style.nameGroup">
					<!-- A click on the name opens the note field too, a mouse convenience;
						 the icon is the way in for the keyboard and screen readers -->
					<div :class="[$style.name, !locked && $style.nameEditable]"
						:title="track.name"
						@click="openNote">
						{{ track.name }}
					</div>
					<!-- Shown under the pointer, with focus and on touchscreens, only on a
						 row without a note; its space is always kept, so the squares never move -->
					<NcButton v-if="!locked && !note && !editing"
						variant="tertiary"
						size="small"
						:class="[$style.noteButton, $style.nameNoteButton]"
						:aria-label="`Add a note for ${track.name}`"
						:title="`Add a note for ${track.name}`"
						@click="openNote">
						<template #icon>
							<NcIconSvgWrapper :path="MDI_NOTE_TEXT_OUTLINE" :size="16" :class="$style.noteIcon" />
						</template>
					</NcButton>
					<span v-else :class="$style.iconSpace" />
				</div>
				<!-- The squares and the run are one link to the track's Analytics, one
					 Tab stop; the squares keep their own titles -->
				<a :class="$style.week"
					:href="hashFor({ screen: 'analytics', trackId: track.id })"
					:aria-label="analyticsLabel">
					<!-- Their own box, so the run wraps under them when the row is too narrow -->
					<span :class="$style.squares">
						<span v-for="square in squares"
							:key="square.date"
							role="img"
							:aria-label="squareLabel(square)"
							:title="squareLabel(square)"
							:class="[$style.square, square.shown && $style.squareShown, square.shown && isOpen(square) && $style.squareOpen]"
							:style="{ background: square.fill }" />
					</span>
					<span :class="$style.run" :title="analyticsLabel">{{ runText(stats) }}</span>
				</a>
			</div>

			<!-- Line 2, only with a note or while one is being written: the note
				 icon, then the note. A locked day shows the note read-only. -->
			<div v-if="note || (editing && !locked)" :class="$style.noteLine">
				<NcButton v-if="!locked"
					variant="tertiary"
					size="small"
					:class="$style.noteButton"
					:aria-label="note ? `Edit the note for ${track.name}` : `Add a note for ${track.name}`"
					:title="note ? `Edit the note for ${track.name}` : `Add a note for ${track.name}`"
					@click="openNote">
					<template #icon>
						<NcIconSvgWrapper :path="MDI_NOTE_TEXT_OUTLINE" :size="16" :class="$style.noteIcon" />
					</template>
				</NcButton>
				<span v-else :class="$style.noteIconStatic">
					<NcIconSvgWrapper :path="MDI_NOTE_TEXT_OUTLINE" :size="16" :class="$style.noteIcon" />
				</span>
				<NoteField v-if="editing"
					:text="note"
					:track-name="track.name"
					:save="saveNote"
					:class="$style.note"
					@close="editing = false" />
				<!-- A click on the text edits it, as the icon does; the icon is the way in for the keyboard -->
				<NoteText v-else
					:text="note"
					:class="[$style.note, !locked && $style.noteEditable]"
					@click="openNote" />
			</div>
		</div>

		<!-- Two columns, shared with every row through the list's grid: the value,
			 ending on one edge down the page, then the counter's + or a Number's unit -->
		<div :class="[$style.control, locked && $style.controlLocked]" @click="onControlClick">
			<div :class="[$style.value, isNumber && $style.valueNumber]">
				<!-- A locked day: the value only, where its control would be -->
				<span v-if="locked && isYesNo" :class="$style.checkSlot">
					<span :class="$style.mark"
						role="img"
						:aria-label="`${track.name}: ${count > 0 ? 'ticked' : 'not ticked'}`">{{ count > 0 ? '✓' : '' }}</span>
				</span>
				<span v-else-if="locked && isCounter"
					:class="[$style.count, count === 0 && $style.zero]"
					role="img"
					:aria-label="`${track.name}: ${count}`">{{ count }}</span>
				<!-- With the unit only where the field holds it (phones) -->
				<span v-else-if="locked && isNumber"
					:class="[$style.lockedValue, $style.lockedNumber]"
					role="img"
					:title="value === null ? undefined : numberText(value, track)"
					:aria-label="`${track.name}: ${trackValueText(value, track)}`">{{ value === null ? '' : unitInField ? numberText(value, track) : formatNumber(value, decimalsOf(track), userLocale) }}</span>
				<span v-else-if="locked && isChoice"
					:class="[$style.lockedValue, $style.lockedChoice]"
					role="img"
					:title="value === null ? undefined : choiceLabel(value, track)"
					:aria-label="`${track.name}: ${trackValueText(value, track)}`">{{ value === null ? '' : choiceLabel(value, track) }}</span>
				<NumberField v-else-if="isNumber"
					:track="track"
					:value="value"
					:save="saveValue"
					:label="fieldLabel"
					:unit="unitInField ? track.unit ?? undefined : undefined" />
				<ChoiceField v-else-if="isChoice"
					:track="track"
					:value="value"
					:label="track.name"
					@pick="pick" />
				<span v-else-if="isYesNo" :class="$style.checkSlot">
					<NcCheckboxRadioSwitch :model-value="count > 0"
						:aria-label="track.name"
						@update:model-value="(checked: boolean) => emit('set-yes-no', checked)" />
				</span>
				<template v-else-if="isCounter">
					<NcButton variant="tertiary"
						:aria-label="`Take one from ${track.name}`"
						:title="`Take one from ${track.name}`"
						:disabled="count <= 0"
						@click="emit('increment', -1)">
						<template #icon>
							<NcIconSvgWrapper :path="MDI_MINUS" />
						</template>
					</NcButton>
					<span :class="$style.count">{{ count }}</span>
				</template>
				<!-- In the fixed column, so its width never widens the last one -->
				<span v-else :class="$style.unsupported">Not supported yet</span>
			</div>
			<div :class="$style.tail">
				<span v-if="isNumber && track.unit && !unitInField" :class="$style.unit" :title="track.unit">{{ track.unit }}</span>
				<NcButton v-else-if="isCounter && !locked"
					variant="tertiary"
					:aria-label="`Add one to ${track.name}`"
					:title="`Add one to ${track.name}`"
					@click="emit('increment', 1)">
					<template #icon>
						<NcIconSvgWrapper :path="MDI_PLUS" />
					</template>
				</NcButton>
			</div>
		</div>
	</li>
</template>

<style module>
/* Not boxed: one line under each row, as in the Tracks page's archived list */
/* The list's three columns (TodayView's .rows): the name block, the value, the
   last column. The gaps are the list's only, never the subgrids' own: a
   subgrid's gap that differs from its parent's becomes extra margin. */
.row {
	display: grid;
	grid-column: 1 / -1;
	grid-template-columns: subgrid;
	align-items: center;
	padding-block: 12px;
	border-block-end: 1px solid var(--color-border);
}

/* 8px before the controls, with the list's gap included */
.main {
	padding-inline-end: calc(8px - var(--today-control-gap));
	min-width: 0;
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.line1 {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 10px;
	row-gap: 4px;
	min-width: 0;
}

/* The name and its note icon on a line of their own, at every width, so the
   squares and run start at the row's start on every row. Laid out as text, so
   the icon follows the name's last word when the name wraps. */
.nameGroup {
	flex: 0 1 100%;
	min-width: 0;
}

.name {
	display: inline;
	font-weight: 600;
	/* Wraps rather than being cut off */
	overflow-wrap: anywhere;
}

/* Doubled to outrank NcButton's own display (scoped, 0,2,0) */
.nameGroup .nameNoteButton.nameNoteButton,
.nameGroup .iconSpace {
	display: inline-flex;
	margin-inline-start: 2px;
	vertical-align: middle;
}

.nameEditable {
	cursor: pointer;
}

/* Hidden with opacity, not display, so its space is kept and Tab reaches it */
.nameNoteButton {
	opacity: 0;
}

.row:hover .nameNoteButton,
.row:focus-within .nameNoteButton {
	opacity: 1;
}

@media (hover: none) {
	.nameNoteButton {
		opacity: 1;
	}
}

/* The icon's space on a row with a note, or a locked day */
.iconSpace {
	width: var(--clickable-area-small, 24px);
	height: var(--clickable-area-small, 24px);
}

/* The squares, then the run: beside them when it fits, else on the line under
   them, at its start (the 9px are the squares' 3px gap and the run's 6px
   margin it had beside them). It shrinks, so even a run text wider than the
   whole block wraps within it rather than running under the controls. */
.week {
	flex: 0 1 auto;
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	column-gap: 9px;
	row-gap: 4px;
	min-width: 0;
	font-size: 13px;
	/* A link without a link's look: no underline, no hover styling */
	color: inherit;
	text-decoration: none;
	cursor: pointer;
}

.square {
	width: 11px;
	height: 11px;
	border-radius: 2px;
}

/* The day shown: drawn inside the square, so it is the same size as the others.
   Dashed while open, as on the dashboard widget's today. */
.squareShown {
	outline: 1.5px solid color-mix(in srgb, var(--color-primary-element) 60%, transparent);
	outline-offset: -1.5px;
}

.squareOpen {
	outline-style: dashed;
}

/* One neutral colour for a streak and a break alike */
.squares {
	flex: none;
	display: flex;
	gap: 3px;
}

.run {
	min-width: 0;
	color: var(--color-text-maxcontrast);
}

/* The icon, then the note as one block beside it, so wrapped lines indent past it */
.noteLine {
	display: flex;
	align-items: flex-start;
	gap: 4px;
	/* The run text's size, for the note and "Show more" */
	font-size: 13px;
	/* The small button's padding, so the icon sits under the name's first letter */
	margin-inline-start: -4px;
}

.note {
	flex: 1;
	min-width: 0;
	/* Centres the text's first line on the icon */
	padding-block-start: 4px;
}

.noteIconStatic {
	flex: none;
	display: flex;
	align-items: center;
	justify-content: center;
	width: var(--clickable-area-small, 24px);
	height: var(--clickable-area-small, 24px);
}

.noteEditable {
	cursor: text;
}

/* Grey at rest, the text colour under the pointer or with keyboard focus. On
   the icon: NcButton's scoped tertiary colour outranks a class on the button. */
.noteIcon {
	/* Lighter than the run text: --color-text-maxcontrast mixed a quarter of the
	   way into the background, which keeps 3:1 against it (WCAG's minimum for
	   icons) in light and dark themes */
	color: color-mix(in srgb, var(--color-text-maxcontrast) 75%, var(--color-main-background));
}

.noteButton:hover .noteIcon,
.noteButton:focus-visible .noteIcon {
	color: var(--color-main-text);
}

/* The value column and the last column, so the controls line up down the page */
.control {
	grid-column: 2 / -1;
	display: grid;
	grid-template-columns: subgrid;
	align-items: center;
}

/* Fills the value column and ends everything on its right edge: the − and the
   count, the checkbox centred on the count, the field and the drop-down at the
   column's full width */
.value {
	--number-field-width: var(--today-value-width);
	display: flex;
	align-items: center;
	justify-content: flex-end;
	gap: var(--today-control-gap);
	min-width: 0;
}

/* NcSelect draws its box 2px inside its own edges (measured: 136 × 30 in the
   140px column), so the Number field takes that box, and the same inset at the
   end, for the two to line up */
.valueNumber {
	--select-inset: 2px;
	--number-field-width: calc(var(--today-value-width) - 2 * var(--select-inset));
	--number-field-height: calc(var(--default-clickable-area) - 2 * var(--select-inset));
	padding-inline-end: var(--select-inset);
}

/* The counter's +, a Number's unit, or nothing; starts where the + starts */
.tail {
	justify-self: start;
	min-width: 0;
}

/* The count's width, so the checkbox (wider) sits centred on it */
.checkSlot {
	flex: none;
	display: flex;
	justify-content: center;
	width: 3ch;
}

.unit {
	display: block;
	max-width: 8ch;
	overflow: hidden;
	color: var(--color-text-maxcontrast);
	text-overflow: ellipsis;
	white-space: nowrap;
}

/* A locked Number or Choice: the value as text where the control's text sits */
.lockedValue {
	box-sizing: border-box;
	min-width: 0;
	overflow: hidden;
	font-variant-numeric: tabular-nums;
	font-weight: 600;
	text-overflow: ellipsis;
	white-space: nowrap;
}

/* The Number field's padding, so the value ends where the typed one did */
.lockedNumber {
	padding-inline-end: 8px;
}

/* The drop-down's width, the label where NcSelect draws it (its .vs__selected
   padding), so it doesn't jump to the other side when the day locks */
.lockedChoice {
	width: var(--today-value-width);
	padding-inline-start: calc(var(--border-radius-element) + var(--border-width-input-focused, 2px));
	text-align: start;
}

/* Fills the area, so a click anywhere in it says why nothing changes */
.controlLocked {
	align-self: stretch;
	cursor: default;
}

.mark {
	color: var(--color-primary-element);
	font-size: 18px;
	font-weight: bold;
	line-height: 1;
}

.count {
	min-width: 3ch;
	text-align: center;
	font-variant-numeric: tabular-nums;
	font-weight: 600;
}

.zero {
	color: var(--color-text-maxcontrast);
}

.unsupported {
	color: var(--color-text-maxcontrast);
	white-space: nowrap;
}
</style>
