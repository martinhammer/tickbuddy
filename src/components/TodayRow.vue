<script setup lang="ts">
import { ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import { fmtDate } from '../dates.ts'
import { valueText } from '../heatmap.ts'
import { MDI_MINUS, MDI_PENCIL_OUTLINE, MDI_PLUS } from '../icons.ts'
import { runText } from '../runText.ts'
import type { Track, TrackStats } from '../types.ts'
import NoteField from './NoteField.vue'
import NoteText from './NoteText.vue'

type SaveNote = (text: string) => Promise<boolean>

export interface Square {
	date: string
	value: number
	fill: string
}

const props = defineProps<{
	track: Track
	/** The value on the day shown */
	value: number
	/** The last seven days ending on the real today, today first */
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

// On a locked day the controls look disabled but still take the click, to say
// why nothing changes (a disabled control would swallow it). Capturing stops
// the click before the checkbox sees it.
function onLockedClick(event: Event): void {
	if (!props.locked) return
	event.preventDefault()
	event.stopPropagation()
	emit('locked')
}

function onIncrement(delta: number): void {
	if (props.locked) {
		emit('locked')
		return
	}
	emit('increment', delta)
}

function openNote(): void {
	if (!props.locked) editing.value = true
}
</script>

<template>
	<li :class="$style.row">
		<div :class="$style.main">
			<div :class="$style.name" :title="track.name">
				{{ track.name }}
			</div>
			<div :class="$style.week">
				<span v-for="(square, i) in squares"
					:key="square.date"
					role="img"
					:aria-label="squareLabel(square)"
					:title="squareLabel(square)"
					:class="[$style.square, i === 0 && $style.squareToday, i === 0 && square.value <= 0 && $style.squareOpen]"
					:style="{ background: square.fill }" />
				<span :class="$style.run">{{ runText(stats) }}</span>
			</div>
			<NoteField v-if="editing"
				:text="note"
				:track-name="track.name"
				:save="saveNote"
				:class="$style.note"
				@close="editing = false" />
			<!-- A click on the text edits it, as the pencil does; the pencil is the way in for the keyboard -->
			<NoteText v-else-if="note"
				:text="note"
				:class="[$style.note, !locked && $style.noteEditable]"
				@click="openNote" />
		</div>

		<NcButton v-if="!locked"
			variant="tertiary"
			:aria-label="note ? `Edit the note for ${track.name}` : `Add a note for ${track.name}`"
			:title="note ? `Edit the note for ${track.name}` : `Add a note for ${track.name}`"
			@click="openNote">
			<template #icon>
				<NcIconSvgWrapper :path="MDI_PENCIL_OUTLINE" />
			</template>
		</NcButton>

		<div :class="[$style.control, locked && $style.controlLocked]">
			<span v-if="isYesNo" @click.capture="onLockedClick">
				<NcCheckboxRadioSwitch :model-value="value > 0"
					:aria-label="track.name"
					:aria-disabled="locked ? 'true' : undefined"
					@update:model-value="(checked: boolean) => emit('set-yes-no', checked)" />
			</span>
			<template v-else-if="isCounter">
				<NcButton variant="tertiary"
					:aria-label="`Take one from ${track.name}`"
					:title="`Take one from ${track.name}`"
					:aria-disabled="locked ? 'true' : undefined"
					:disabled="!locked && value <= 0"
					@click="onIncrement(-1)">
					<template #icon>
						<NcIconSvgWrapper :path="MDI_MINUS" />
					</template>
				</NcButton>
				<span :class="$style.count">{{ value }}</span>
				<NcButton variant="tertiary"
					:aria-label="`Add one to ${track.name}`"
					:title="`Add one to ${track.name}`"
					:aria-disabled="locked ? 'true' : undefined"
					@click="onIncrement(1)">
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
	gap: 6px;
}

.name {
	font-weight: 600;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.week {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 3px;
	font-size: 13px;
}

.square {
	width: 11px;
	height: 11px;
	border-radius: 2px;
}

/* Drawn inside the square, so today is the same size as the other days. */
.squareToday {
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

.note {
	margin-block-start: 2px;
}

.noteEditable {
	cursor: text;
}

/* One width on every row, that of "− 0 +", with the checkbox centred where a
   counter's number sits, so controls and pencils line up down the page */
.control {
	flex: none;
	display: flex;
	align-items: center;
	justify-content: center;
	min-width: calc(2 * var(--default-clickable-area) + 3ch);
}

/* aria-disabled leaves the controls clickable (to say why nothing changes), so
   they only look disabled. */
.controlLocked {
	opacity: 0.5;
}

.count {
	min-width: 3ch;
	text-align: center;
	font-variant-numeric: tabular-nums;
	font-weight: 600;
}

.unsupported {
	color: var(--color-text-maxcontrast);
}

/* Phones: names wrap rather than being cut off */
@media (max-width: 1023px) {
	.name {
		white-space: normal;
		overflow-wrap: anywhere;
	}
}
</style>
