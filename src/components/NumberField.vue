<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue'
import { showError } from '@nextcloud/dialogs'
import { userLocale } from '../dates.ts'
import { decimalsOf, formatNumber, parseNumber, sameAsStored } from '../numberValue.ts'
import type { Track } from '../types.ts'

// A Number's day as a small text field, on Today and in the Journal. At rest
// it holds the stored value with the track's decimals in the user's language;
// Enter or losing focus saves what was typed (empty clears the day), Escape
// puts the stored value back. Typed text that isn't a number, or a negative
// value on a track that doesn't take one, stays in the field with a toast.
// With a unit (Today on a phone), the unit sits inside the field's box, after
// the number, and the ring goes round both; it hides with focus, leaving the
// whole box to type in.

type Save = (value: number | null) => Promise<boolean>

const props = defineProps<{
	track: Track
	/** The stored value, null when the day has none */
	value: number | null
	/** Stores the value (null clears the day); resolves false when it wasn't saved */
	save: Save
	/** The field's accessible name */
	label: string
	/** Shown inside the box, after the number (Today on a phone) */
	unit?: string
}>()

const emit = defineEmits<{
	/** Enter, Escape or losing focus ended the edit */
	done: []
}>()

const input = ref<HTMLInputElement | null>(null)
const decimals = computed(() => decimalsOf(props.track))
const stored = computed(() => props.value === null ? '' : formatNumber(props.value, decimals.value, userLocale))
const text = ref(stored.value)
const focused = ref(false)
let busy = false
// Escape ends the edit without the blur that follows saving
let skipBlur = false

watch(stored, (shown) => {
	if (!focused.value) text.value = shown
})

// The input's minimum width in the box: the value as shown, or the "…" (figures
// are one width, so ch is exact for them); only the unit gives way
const valueChars = computed(() => ({ '--value-chars': String(text.value.length || 1) }))

// iOS's decimal pad has no minus key, so a track that takes negative values gets the normal keyboard
const inputMode = computed(() => props.track.allowNegative ? 'text' : 'decimal')

async function commit(): Promise<boolean> {
	if (busy) return false
	if (text.value === stored.value) return true
	const parsed = parseNumber(text.value, userLocale)
	if (parsed === 'invalid') {
		showError('Enter a valid number')
		return false
	}
	if (parsed !== null && parsed < 0 && !props.track.allowNegative) {
		showError(`${props.track.name} doesn't allow negative values, so the change wasn't saved.`)
		return false
	}
	if (sameAsStored(parsed, props.value, decimals.value)) {
		text.value = stored.value
		return true
	}
	busy = true
	const saved = await props.save(parsed)
	busy = false
	// The stored answer, formatted; on a failure the value as it was
	await nextTick()
	text.value = stored.value
	return saved
}

async function onEnter(): Promise<void> {
	if (await commit()) {
		skipBlur = true
		input.value?.blur()
	}
}

function onEscape(): void {
	text.value = stored.value
	skipBlur = true
	input.value?.blur()
}

async function onBlur(): Promise<void> {
	focused.value = false
	if (skipBlur) {
		skipBlur = false
	} else {
		await commit()
	}
	emit('done')
}

defineExpose({
	focus: () => input.value?.focus(),
})
</script>

<template>
	<!-- Without a unit the wrapper takes no box (display: contents) and the
		 input draws the ring; with one it is the box, and a click on the unit
		 focuses the input, as it is a label -->
	<label :class="unit ? [$style.box, $style.ring] : $style.contents">
		<input ref="input"
			v-model="text"
			:class="[$style.field, unit ? $style.inBox : $style.ring]"
			:style="unit ? valueChars : undefined"
			type="text"
			:inputmode="inputMode"
			autocomplete="off"
			placeholder="…"
			:aria-label="label"
			@focus="focused = true"
			@keydown.enter.prevent="onEnter"
			@keydown.esc.stop.prevent="onEscape"
			@blur="onBlur">
		<span v-if="unit" :class="$style.unit" :title="unit">{{ unit }}</span>
	</label>
</template>

<style module>
/* NcTextField's look on Nextcloud 32 and later (NcInputField's rules): no real
   border but a ring drawn with box-shadow, 1px at rest, the text colour under
   the pointer, 2px with focus. A shadow takes no room, so the text never moves.
   Core CSS gives every text input a 2px border and its own hover and focus
   rules, hence `border: none` and the !important on the states. Its height rule
   (input:not([type=radio]):not(…)…, eight :not()s, 0,8,1) outranks any class, so
   the height needs !important too: Today's control height by default, the
   Journal bar's --number-field-height. Right-aligned, with figures of one width
   so the values line up down the page. */
.field.field {
	box-sizing: border-box;
	width: var(--number-field-width, 9ch);
	min-width: 0;
	min-height: 0 !important;
	height: var(--number-field-height, var(--default-clickable-area)) !important;
	margin: 0 !important;
	padding-inline: 8px;
	border: none !important;
	border-radius: var(--border-radius-element);
	outline: none;
	background-color: var(--color-main-background);
	color: var(--color-main-text);
	font-variant-numeric: tabular-nums;
	text-align: end;
}

/* No ring of its own (core's included) unless it has .ring */
.field.field,
.field.field:hover,
.field.field:focus,
.field.field:active {
	box-shadow: none !important;
}

/* The ellipsis shows only at rest: with focus the empty field holds just the caret */
.field.field:focus::placeholder {
	color: transparent;
}

/* The ring, on the input, or on the box when the unit is inside it. Tripled to
   outrank the reset above in every state. */
.ring.ring.ring {
	--number-field-border: var(--color-border-maxcontrast);
	--number-field-ring: 0 -1px var(--number-field-border), 0 0 0 1px color-mix(in srgb, var(--number-field-border), 65% transparent);
	box-shadow: var(--number-field-ring) !important;
}

.ring.ring.ring:hover {
	box-shadow: 0 0 0 1px var(--color-main-text) !important;
}

.ring.ring.ring:focus,
.ring.ring.ring:active,
.ring.ring.ring:focus-within {
	box-shadow: 0 0 0 2px var(--color-main-text), 0 0 0 var(--number-field-focus-gap, 4px) var(--color-main-background) !important;
}

/* In the dark theme the ring's line sits under the field rather than over it */
:global([data-theme-dark]) .ring.ring.ring:not(:hover, :focus, :active, :focus-within) {
	--number-field-ring: 0 1px var(--number-field-border), 0 0 0 1px color-mix(in srgb, var(--number-field-border), 65% transparent);
}

@media (prefers-color-scheme: dark) {
	:global(body:not([data-theme-light])) .ring.ring.ring:not(:hover, :focus, :active, :focus-within) {
		--number-field-ring: 0 1px var(--number-field-border), 0 0 0 1px color-mix(in srgb, var(--number-field-border), 65% transparent);
	}
}

/* Without a unit the label takes no box: the input is laid out as before */
.contents {
	display: contents;
}

/* With a unit, the box takes the field's size and look; the number stays
   right-aligned before the unit */
.box {
	box-sizing: border-box;
	display: inline-flex;
	align-items: center;
	width: var(--number-field-width, 9ch);
	height: var(--number-field-height, var(--default-clickable-area));
	border-radius: var(--border-radius-element);
	background-color: var(--color-main-background);
	cursor: text;
}

.field.field.inBox {
	flex: 1 1 0;
	width: auto;
	/* The value in full (its 8px start and 4px end padding included) */
	min-width: calc(var(--value-chars, 1) * 1ch + 12px);
	height: 100% !important;
	padding-inline-end: 4px;
	background-color: transparent;
}

/* With focus the unit hides and the input has the whole box */
.field.field.inBox:focus {
	padding-inline-end: 8px;
}

.box:focus-within .unit {
	display: none;
}

/* In full after the value when it fits, else cut: the box is the limit */
.unit {
	flex: 0 1 auto;
	min-width: 0;
	overflow: hidden;
	padding-inline-end: 8px;
	color: var(--color-text-maxcontrast);
	text-overflow: ellipsis;
	white-space: nowrap;
}
</style>
