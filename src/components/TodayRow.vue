<script setup lang="ts">
import { ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import { fmtDate } from '../dates.ts'
import { valueText } from '../heatmap.ts'
import { MDI_MINUS, MDI_NOTE_TEXT_OUTLINE, MDI_PLUS } from '../icons.ts'
import { runText } from '../runText.ts'
import type { Track, TrackStats } from '../types.ts'
import NoteField from './NoteField.vue'
import NoteText from './NoteText.vue'

type SaveNote = (text: string) => Promise<boolean>

export interface Square {
	date: string
	value: number
	fill: string
	/** The day shown, outlined */
	shown: boolean
}

const props = defineProps<{
	track: Track
	/** The value on the day shown */
	value: number
	/** Seven days, newest first: the last seven, or the day shown and the six after it */
	squares: Square[]
	stats: TrackStats | undefined
	/** The day's note, "" when there is none */
	note: string
	locked: boolean
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

function squareLabel(square: Square): string {
	return `${props.track.name}, ${fmtDate(square.date)}: ${valueText(square.value, isCounter)}`
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
			<!-- Line 1: the name, then the squares and the run -->
			<div :class="$style.line1">
				<div :class="$style.name" :title="track.name">
					{{ track.name }}
				</div>
				<div :class="$style.week">
					<span v-for="square in squares"
						:key="square.date"
						role="img"
						:aria-label="squareLabel(square)"
						:title="squareLabel(square)"
						:class="[$style.square, square.shown && $style.squareShown, square.shown && square.value <= 0 && $style.squareOpen]"
						:style="{ background: square.fill }" />
					<span :class="$style.run">{{ runText(stats) }}</span>
				</div>
			</div>

			<!-- Line 2: the note icon, then the note or "Add a note". A locked day
				 shows the note read-only, and no line at all without one. -->
			<div v-if="!locked || note" :class="$style.noteLine">
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
				<NoteText v-else-if="note"
					:text="note"
					:class="[$style.note, !locked && $style.noteEditable]"
					@click="openNote" />
				<button v-else
					type="button"
					:class="$style.addNote"
					tabindex="-1"
					@click="openNote">
					Add a note
				</button>
			</div>
		</div>

		<div :class="[$style.control, locked && $style.controlLocked]" @click="onControlClick">
			<!-- A locked day: the value only, as the Journal shows it at rest -->
			<span v-if="locked && isYesNo"
				:class="$style.mark"
				role="img"
				:aria-label="`${track.name}: ${value > 0 ? 'ticked' : 'not ticked'}`">{{ value > 0 ? '✓' : '' }}</span>
			<span v-else-if="locked && isCounter"
				:class="[$style.count, value === 0 && $style.zero]"
				role="img"
				:aria-label="`${track.name}: ${value}`">{{ value }}</span>
			<NcCheckboxRadioSwitch v-else-if="isYesNo"
				:model-value="value > 0"
				:aria-label="track.name"
				@update:model-value="(checked: boolean) => emit('set-yes-no', checked)" />
			<template v-else-if="isCounter">
				<NcButton variant="tertiary"
					:aria-label="`Take one from ${track.name}`"
					:title="`Take one from ${track.name}`"
					:disabled="value <= 0"
					@click="emit('increment', -1)">
					<template #icon>
						<NcIconSvgWrapper :path="MDI_MINUS" />
					</template>
				</NcButton>
				<span :class="$style.count">{{ value }}</span>
				<NcButton variant="tertiary"
					:aria-label="`Add one to ${track.name}`"
					:title="`Add one to ${track.name}`"
					@click="emit('increment', 1)">
					<template #icon>
						<NcIconSvgWrapper :path="MDI_PLUS" />
					</template>
				</NcButton>
			</template>
			<span v-else :class="$style.unsupported">Not supported yet</span>
		</div>
	</li>
</template>

<style module>
/* Not boxed: one line under each row, as in the Tracks page's archived list */
.row {
	display: flex;
	align-items: center;
	gap: 8px;
	padding-block: 12px;
	border-block-end: 1px solid var(--color-border);
}

.main {
	flex: 1;
	min-width: 0;
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.line1 {
	display: flex;
	align-items: center;
	gap: 10px;
	min-width: 0;
}

/* Cut off first when line 1 runs out of room, so the squares and run always show */
.name {
	flex: 0 1 auto;
	min-width: 0;
	font-weight: 600;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.week {
	flex: none;
	display: flex;
	align-items: center;
	gap: 3px;
	font-size: 13px;
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
.run {
	margin-inline-start: 6px;
	color: var(--color-text-maxcontrast);
}

/* The icon, then the note as one block beside it, so wrapped lines indent past it */
.noteLine {
	display: flex;
	align-items: flex-start;
	gap: 4px;
	/* The run text's size, for the note, "Add a note" and "Show more" */
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

/* A link-style button, as "Show more". Nextcloud's core CSS draws every button
   that isn't an NcButton as a light primary button, with selectors a single
   class can't beat, so it is styled under .row, as a scoped style would be
   (see CLAUDE.md). The keyboard reaches the field through the icon button. */
.row .addNote,
.row .addNote:hover,
.row .addNote:focus {
	width: auto;
	min-height: 0;
	/* Its margin rule adds a :not() (0,2,1), which only !important beats */
	margin: 0 !important;
	padding: 4px 0 0;
	border: none;
	background: none;
	color: var(--color-text-maxcontrast);
	/* Buttons don't inherit these, so it would sit off the note text's line */
	font: inherit;
	line-height: inherit;
	text-align: start;
	cursor: pointer;
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

/* One width on every row, that of "− 0 +", with the checkbox centred where a
   counter's number sits, so the controls line up down the page */
.control {
	flex: none;
	display: flex;
	align-items: center;
	justify-content: center;
	min-width: calc(2 * var(--default-clickable-area) + 3ch);
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
}

/* Phones: line 1 wraps, the squares and run dropping under a name that
   wraps rather than being cut off */
@media (max-width: 1023px) {
	.line1 {
		flex-wrap: wrap;
		row-gap: 4px;
	}

	.name {
		flex-basis: 100%;
		white-space: normal;
		overflow-wrap: anywhere;
	}
}
</style>
