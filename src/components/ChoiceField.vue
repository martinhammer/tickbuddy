<script setup lang="ts">
import { computed, nextTick, onMounted, ref } from 'vue'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import type { Track } from '../types.ts'

// A Choice's day as Nextcloud's drop-down, on Today and in the Journal: the
// picked option or an ellipsis, the track's options only, and its built-in ✕ to
// clear once an option is picked. It fills its container and no more: the
// parent sets the width (Today's control area, the Journal bar's slot).

interface Choice {
	id: number
	label: string
}

const props = defineProps<{
	track: Track
	/** The stored place, null when the day has none */
	value: number | null
	/** The field's accessible name */
	label: string
	/** Open the list as soon as it is mounted (the Journal mounts it on demand) */
	openOnMount?: boolean
}>()

const emit = defineEmits<{
	/** An option's place, or null for ✕ */
	pick: [place: number | null]
	open: []
	close: []
}>()

const root = ref<InstanceType<typeof NcSelect> | null>(null)

const choices = computed<Choice[]>(() => (props.track.options ?? []).map(o => ({ id: o.value, label: o.label })))

const selected = computed<Choice | null>({
	get: () => choices.value.find(c => c.id === props.value) ?? null,
	set: (choice) => {
		const place = choice?.id ?? null
		if (place === props.value) return
		emit('pick', place)
		// The ✕ pressed with the list open (the Journal) leaves it open, where
		// picking an option closes it: close it too. After a tick, as
		// vue-select's clearSelection puts the focus back in the search field
		// once the value is set. With the list closed (Today) focus stays.
		const el = root.value?.$el as HTMLElement | undefined
		if (place === null && el?.classList.contains('vs--open')) {
			nextTick(() => el.querySelector('input')?.blur())
		}
	},
})

// Focus alone no longer opens the list (vue-select leaves that to a click or
// a key), and a field focused without opening never emits close, which would
// keep the Journal's bar open: open it as a press on the field does
function open(): void {
	const el = root.value?.$el as HTMLElement | undefined
	el?.querySelector('.vs__selected-options')
		?.dispatchEvent(new MouseEvent('mousedown', { bubbles: true, cancelable: true }))
}

// A press on the ✕ would move the focus to it, and the search field losing
// focus closes the list (and the Journal's bar, unmounting this) before the
// click that clears. vue-select keeps the focus only for presses on the field
// and the ▾ (toggleDropdown), not for the ✕ beside them, so keep it here.
function keepFocusOnClear(event: MouseEvent): void {
	if ((event.target as Element | null)?.closest('.vs__clear')) event.preventDefault()
}

onMounted(async () => {
	(root.value?.$el as HTMLElement | undefined)?.addEventListener('mousedown', keepFocusOnClear)
	if (!props.openOnMount) return
	await nextTick()
	open()
})

defineExpose({ open })
</script>

<template>
	<NcSelect ref="root"
		v-model="selected"
		:options="choices"
		:aria-label-combobox="label"
		placeholder="…"
		:class="[$style.select, value !== null && $style.picked]"
		@open="emit('open')"
		@close="emit('close')" />
</template>

<style module>
/* NcSelect's own rule (.nc-select.v-select.select, 0,3,0) sets a 260px
   minimum; the selector has to outrank it */
.select:global(.nc-select.v-select.select) {
	width: 100%;
	min-width: 0;
	max-width: none;
}

/* vue-select's list keeps a 160px minimum (--vs-dropdown-min-width), which
   NcSelect never meets with its own 260px field; a narrower field (Today's
   140px) gets a list wider than itself. The list is mounted on <body>, so a
   module class can't reach it. */
:global(.nc-select__dropdown.vs__dropdown-menu) {
	--vs-dropdown-min-width: 0;
}

/* vue-select hides its ✕ while the list is open (v-show, showClearButton),
   and the Journal opens the list as soon as it mounts, so the ✕ would never
   show there. Show it whenever an option is picked: a press on it keeps the
   list open (keepFocusOnClear, above) and clears the day.
   Like open() above, this relies on vue-select's class names (.vs--open,
   .vs__clear): recheck after an @nextcloud/vue bump. */
.picked:global(.vs--open) :global(.vs__clear) {
	display: inline-block !important;
}
</style>
