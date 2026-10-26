<script setup lang="ts">
import { computed, ref } from 'vue'
import axios from '@nextcloud/axios'
import { showError } from '@nextcloud/dialogs'
import { generateOcsUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { MDI_EYE_OUTLINE } from '../icons.ts'
import { ocsErrorMessage } from '../ocsError.ts'

// The server's limit (TrackService::MAX_NAME_LENGTH), so a longer paste is cut
// here instead of refused. maxlength counts UTF-16 units, so names with emoji
// stop a little sooner, never later.
const MAX_NAME_LENGTH = 255

const emit = defineEmits<{
	close: []
	created: []
}>()

const apiUrl = generateOcsUrl('/apps/tickbuddy/api/tracks')

const name = ref('')
const type = ref('boolean')
const isPrivate = ref(false)
const adding = ref(false)

const canAdd = computed(() => name.value.trim() !== '' && !adding.value)

async function add(): Promise<void> {
	const trimmed = name.value.trim()
	if (!trimmed || adding.value) return
	adding.value = true
	const params = new URLSearchParams()
	params.append('name', trimmed)
	params.append('type', type.value)
	let id: number
	try {
		const response = await axios.post(apiUrl, params)
		id = response.data.ocs.data.id
	} catch (e) {
		// Keep what was typed so the user can correct and retry.
		showError(ocsErrorMessage(e, 'Could not add track'))
		adding.value = false
		return
	}
	// The server takes no `private` on create, so this is a second call, as in Tickdroid.
	// If it fails the track stays, not private.
	if (isPrivate.value) {
		const privateParams = new URLSearchParams()
		privateParams.append('private', 'true')
		try {
			await axios.put(`${apiUrl}/${id}`, privateParams)
		} catch {
			showError('Track created, but it couldn\'t be made private. Open it to try again.')
		}
	}
	adding.value = false
	emit('created')
}
</script>

<template>
	<NcDialog :open="true"
		name="New track"
		size="small"
		@update:open="(open) => { if (!open) emit('close') }">
		<div :class="$style.form">
			<p :class="$style.intro">
				A track is a habit or event you record each day.
			</p>
			<NcTextField v-model="name"
				label="Name"
				:maxlength="MAX_NAME_LENGTH"
				placeholder="e.g. Exercise, Coffee, Reading…"
				autofocus
				@keyup.enter="add" />
			<fieldset :class="$style.type">
				<legend :class="$style.legend">
					Type
				</legend>
				<NcCheckboxRadioSwitch v-model="type"
					type="radio"
					value="boolean"
					name="tickbuddy-new-track-type">
					Yes/No
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch v-model="type"
					type="radio"
					value="counter"
					name="tickbuddy-new-track-type">
					Counter
				</NcCheckboxRadioSwitch>
				<p :class="$style.hint">
					The type can't be changed later.
				</p>
			</fieldset>
			<NcCheckboxRadioSwitch v-model="isPrivate" type="switch">
				Private
				<template #description>
					Hidden unless Show private tracks
					<NcIconSvgWrapper :path="MDI_EYE_OUTLINE" :size="16" inline />
					is selected. Never shown on the dashboard widget.
				</template>
			</NcCheckboxRadioSwitch>
		</div>
		<template #actions>
			<NcButton variant="secondary" @click="emit('close')">
				Cancel
			</NcButton>
			<NcButton variant="primary" :disabled="!canAdd" @click="add">
				Add track
			</NcButton>
		</template>
	</NcDialog>
</template>

<style module>
.form {
	display: flex;
	flex-direction: column;
	gap: 16px;
	padding-block: 8px;
}

.intro {
	margin: 0;
}

.type {
	margin: 0;
	padding: 0;
	border: none;
}

.legend {
	margin-block-end: 6px;
	font-weight: bold;
}

.hint {
	margin-block-start: 6px;
	color: var(--color-text-maxcontrast);
}
</style>
