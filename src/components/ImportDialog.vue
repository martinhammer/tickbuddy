<script setup lang="ts">
import { ref } from 'vue'
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { generateOcsUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { ocsErrorMessage } from '../ocsError.ts'

const emit = defineEmits<{
	close: []
	imported: []
}>()

const replaceMode = ref(false)
const importFile = ref<File | null>(null)
const importFileName = ref('')
const importFormat = ref<'tickmate' | 'json' | null>(null)
const importing = ref(false)
const confirmingReplace = ref(false)
const tickmateInputRef = ref<HTMLInputElement | null>(null)
const jsonInputRef = ref<HTMLInputElement | null>(null)

function onFileChange(event: Event, format: 'tickmate' | 'json') {
	const input = event.target as HTMLInputElement
	const file = input.files?.[0] ?? null
	importFile.value = file
	importFileName.value = file?.name ?? ''
	importFormat.value = file ? format : null
}

function doImport() {
	if (!importFile.value || !importFormat.value) return

	if (replaceMode.value) {
		confirmingReplace.value = true
		return
	}
	runImport()
}

function confirmReplaceImport() {
	confirmingReplace.value = false
	runImport()
}

function plural(n: number, one: string, many: string): string {
	return `${n} ${n === 1 ? one : many}`
}

async function runImport() {
	if (!importFile.value || !importFormat.value) return

	importing.value = true
	try {
		const formData = new FormData()
		formData.append('file', importFile.value)
		formData.append('mode', replaceMode.value ? 'replace' : 'merge')
		const url = importFormat.value === 'json'
			? generateOcsUrl('/apps/tickbuddy/api/import/json')
			: generateOcsUrl('/apps/tickbuddy/api/import')
		const response = await axios.post(url, formData)
		const data = response.data.ocs.data
		const tracks = plural(data.tracks, 'track', 'tracks')
		const ticks = plural(data.ticks, 'tick', 'ticks')
		showSuccess(data.notes > 0
			? `Imported ${tracks}, ${ticks} and ${plural(data.notes, 'note', 'notes')}.`
			: `Imported ${tracks} and ${ticks}.`)
		emit('imported')
	} catch (e) {
		showError(ocsErrorMessage(e, 'Import failed'))
	} finally {
		importing.value = false
	}
}
</script>

<template>
	<NcDialog v-if="!confirmingReplace"
		:open="true"
		name="Import"
		size="small"
		@update:open="(open) => { if (!open) emit('close') }">
		<div :class="$style.body">
			<input ref="tickmateInputRef"
				type="file"
				accept=".db"
				:class="$style.hiddenFileInput"
				@change="onFileChange($event, 'tickmate')">
			<input ref="jsonInputRef"
				type="file"
				accept=".json"
				:class="$style.hiddenFileInput"
				@change="onFileChange($event, 'json')">
			<div :class="$style.fileButtons">
				<NcButton variant="secondary" @click="jsonInputRef?.click()">
					{{ importFormat === 'json' ? importFileName : 'Tickbuddy backup file (.json)' }}
				</NcButton>
				<NcButton variant="secondary" @click="tickmateInputRef?.click()">
					{{ importFormat === 'tickmate' ? importFileName : 'Tickmate backup file (.db)' }}
				</NcButton>
			</div>
			<NcCheckboxRadioSwitch v-model="replaceMode" type="switch">
				Replace existing database
			</NcCheckboxRadioSwitch>
			<p :class="$style.hint">
				When on, all your tracks, ticks and notes are deleted and replaced with the imported data. When off, imported tracks are added after yours.
			</p>
		</div>
		<template #actions>
			<NcButton variant="secondary" @click="emit('close')">
				Cancel
			</NcButton>
			<NcButton variant="primary"
				:disabled="!importFile || importing"
				@click="doImport">
				{{ importing ? 'Importing…' : 'Import' }}
			</NcButton>
		</template>
	</NcDialog>

	<NcDialog v-else
		:open="true"
		name="Replace all data"
		size="small"
		:buttons="[
			{ label: 'Cancel', variant: 'secondary', callback: () => { confirmingReplace = false } },
			{ label: 'Import and replace', variant: 'primary', callback: confirmReplaceImport },
		]"
		@update:open="(open) => { if (!open) confirmingReplace = false }">
		<NcNoteCard type="warning">
			This will permanently delete all your existing tracks, ticks and notes and replace them with the imported data. This action cannot be undone.
		</NcNoteCard>
	</NcDialog>
</template>

<style module>
.body {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding-block: 8px;
}

.hiddenFileInput {
	display: none;
}

.fileButtons {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
}

.hint {
	color: var(--color-text-maxcontrast);
}
</style>
