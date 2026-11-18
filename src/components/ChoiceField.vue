<script setup lang="ts">
import { computed, nextTick, onMounted, ref } from 'vue'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import type { Track } from '../types.ts'

// A Choice's day as Nextcloud's drop-down, on Today and in the Journal: the
// picked option or a dash, the track's options only, and its built-in ✕ to
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
		if (place !== props.value) emit('pick', place)
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

onMounted(async () => {
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
		placeholder="–"
		:class="$style.select"
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
</style>
