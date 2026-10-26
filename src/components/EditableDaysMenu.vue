<script setup lang="ts">
import { computed } from 'vue'
import NcActionCaption from '@nextcloud/vue/components/NcActionCaption'
import NcActionRadio from '@nextcloud/vue/components/NcActionRadio'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import { EDITABLE_DAYS, EDITABLE_DAYS_LABELS } from '../editableDays.ts'
import { MDI_CALENDAR_LOCK_OPEN_OUTLINE, MDI_CALENDAR_LOCK_OUTLINE } from '../icons.ts'
import { editableDays } from '../preferences.ts'
import type { EditableDays } from '../types.ts'

// The Editable days button in the Journal's toolbar, and on Today while the day
// shown is locked: one saved value per device class, shared by both.

const emit = defineEmits<{
	changed: []
}>()

const label = computed(() => `Editable days: ${EDITABLE_DAYS_LABELS[editableDays.value]}`)

function select(value: string | number): void {
	editableDays.value = value as EditableDays
	emit('changed')
}
</script>

<template>
	<NcActions :aria-label="label" :title="label">
		<template #icon>
			<NcIconSvgWrapper :path="editableDays === 'allDays' ? MDI_CALENDAR_LOCK_OPEN_OUTLINE : MDI_CALENDAR_LOCK_OUTLINE" />
		</template>
		<NcActionCaption name="Editable days" />
		<NcActionRadio v-for="option in EDITABLE_DAYS"
			:key="option"
			name="tickbuddy-editable-days"
			:value="option"
			:model-value="editableDays"
			@update:model-value="select">
			{{ EDITABLE_DAYS_LABELS[option] }}
		</NcActionRadio>
	</NcActions>
</template>
