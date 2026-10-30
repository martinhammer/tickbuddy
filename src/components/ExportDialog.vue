<script setup lang="ts">
import { ref } from 'vue'
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { generateOcsUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import { ocsErrorMessage } from '../ocsError.ts'

const emit = defineEmits<{
	close: []
}>()

const exportPrivate = ref(false)
const exporting = ref(false)

async function doExport() {
	exporting.value = true
	try {
		const response = await axios.get(generateOcsUrl('/apps/tickbuddy/api/export'), {
			params: { includePrivate: exportPrivate.value },
		})
		const data = response.data.ocs.data
		const json = JSON.stringify(data, null, 2)
		const blob = new Blob([json], { type: 'application/json' })
		const url = URL.createObjectURL(blob)
		const a = document.createElement('a')
		a.href = url
		a.download = `tickbuddy-export-${new Date().toISOString().slice(0, 10)}.json`
		a.click()
		URL.revokeObjectURL(url)
		showSuccess('Export downloaded.')
		emit('close')
	} catch (e) {
		showError(ocsErrorMessage(e, 'Export failed'))
	} finally {
		exporting.value = false
	}
}
</script>

<template>
	<NcDialog :open="true"
		name="Export"
		size="small"
		@update:open="(open) => { if (!open) emit('close') }">
		<div :class="$style.body">
			<p :class="$style.intro">
				Export your tracks, ticks and notes as a JSON file for backup or transfer.
			</p>
			<NcCheckboxRadioSwitch v-model="exportPrivate" type="switch">
				Include private tracks
			</NcCheckboxRadioSwitch>
		</div>
		<template #actions>
			<NcButton variant="secondary" @click="emit('close')">
				Cancel
			</NcButton>
			<NcButton variant="primary"
				:disabled="exporting"
				@click="doExport">
				{{ exporting ? 'Exporting…' : 'Export as JSON' }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<style module>
.body {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding-block: 8px;
}

.intro {
	margin: 0;
}
</style>
