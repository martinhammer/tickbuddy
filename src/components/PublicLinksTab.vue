<script setup lang="ts">
import { computed, nextTick, onMounted, ref, watch } from 'vue'
import { showError, showSuccess } from '@nextcloud/dialogs'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import { createPublicLink, fetchPublicLinks, renamePublicLink, revokePublicLink } from '../api.ts'
import { todayStr } from '../dates.ts'
import { EDITABLE_DAYS, EDITABLE_DAYS_LABELS } from '../editableDays.ts'
import {
	MDI_CALENDAR_OUTLINE,
	MDI_CHEVRON_DOWN,
	MDI_CHEVRON_RIGHT,
	MDI_INFORMATION_OUTLINE,
	MDI_LINK_VARIANT,
	MDI_PLUS,
} from '../icons.ts'
import { ocsErrorMessage } from '../ocsError.ts'
import { exampleBase, exampleDates, revokeText } from '../publicLinks.ts'
import { httpStatus } from '../strictHandling.ts'
import type { EditableDays, LinkPermission, PublicLink, Track } from '../types.ts'
import PublicLinkRow from './PublicLinkRow.vue'

// The Public links tab of a track's sidebar: the track's links (create, copy,
// rename, revoke), drawn after Files' share links, and "How to use public
// links". Mounted each time the tab is shown, so the list is as fresh as the
// last look and the form and the disclosure start closed.

// The server's limit (PublicLinkService::MAX_NAME_LENGTH)
const MAX_NAME_LENGTH = 255

const props = defineProps<{
	track: Track
}>()

const emit = defineEmits<{
	/** A link was created or revoked, so the track's count changed */
	changed: []
}>()

const links = ref<PublicLink[]>([])
const status = ref<'loading' | 'ready' | 'error'>('loading')
// For "Last used": the list's own time, so it reads the same until the next load
const loadedAt = ref(new Date())
const howToOpen = ref(false)
const revoking = ref<PublicLink | null>(null)
// Links whose copy button shows its tick, as Files' does after a copy
const copiedIds = ref(new Set<number>())
const COPIED_MS = 4000

// The create form replaces the list while open
const creating = ref(false)
const saving = ref(false)
const name = ref('')
const permission = ref<LinkPermission>('tick')
const allowedDays = ref<EditableDays>('activeAndPrevious')
const nameField = ref<HTMLInputElement | null>(null)

const dayOptions = EDITABLE_DAYS.map(id => ({ id, label: EDITABLE_DAYS_LABELS[id] }))
const dayOption = computed({
	get: () => dayOptions.find(o => o.id === allowedDays.value) ?? dayOptions[1],
	set: (option) => { if (option) allowedDays.value = option.id },
})

const canCreate = computed(() => name.value.trim() !== '' && !saving.value)

async function load(): Promise<void> {
	status.value = 'loading'
	const trackId = props.track.id
	try {
		const answer = await fetchPublicLinks(trackId)
		// Another track was opened meanwhile: its own load answers for it
		if (trackId !== props.track.id) return
		links.value = answer
		loadedAt.value = new Date()
		status.value = 'ready'
	} catch {
		if (trackId === props.track.id) status.value = 'error'
	}
}

watch(() => props.track.id, () => {
	creating.value = false
	howToOpen.value = false
	load()
})

onMounted(load)

// --- Copying ---

// Needs a secure context: over plain http the clipboard isn't there, and this is false
async function copyToClipboard(url: string): Promise<boolean> {
	try {
		await navigator.clipboard.writeText(url)
		return true
	} catch {
		return false
	}
}

async function copy(link: PublicLink): Promise<void> {
	if (!await copyToClipboard(link.url)) {
		showError('Could not copy the link')
		return
	}
	showSuccess(`Copied public link "${link.name}" to clipboard.`)
	copiedIds.value.add(link.id)
	setTimeout(() => copiedIds.value.delete(link.id), COPIED_MS)
}

// --- Creating ---

async function startCreating(): Promise<void> {
	name.value = ''
	permission.value = 'tick'
	allowedDays.value = 'activeAndPrevious'
	creating.value = true
	await nextTick()
	nameField.value?.focus()
}

function cancelCreating(): void {
	creating.value = false
}

// Escape cancels anywhere in the form, except where it only closes the select's list
function onFormEscape(event: KeyboardEvent): void {
	if ((event.target as HTMLElement | null)?.closest('.v-select.vs--open')) return
	event.stopPropagation()
	cancelCreating()
}

async function create(): Promise<void> {
	const trimmed = name.value.trim()
	if (!trimmed || saving.value) return
	saving.value = true
	let link: PublicLink
	try {
		link = await createPublicLink(props.track.id, trimmed, permission.value, allowedDays.value)
	} catch (e) {
		// Keep the form as it was, so it can be corrected and sent again
		showError(ocsErrorMessage(e, 'Could not create public link'))
		saving.value = false
		// Archived elsewhere meanwhile: the page's reload closes the sidebar
		if (httpStatus(e) === 423) emit('changed')
		return
	}
	saving.value = false
	creating.value = false
	links.value.push(link)
	emit('changed')
	showSuccess(await copyToClipboard(link.url)
		? `Created public link "${link.name}" and copied to clipboard.`
		: `Created public link "${link.name}".`)
}

// --- Renaming and revoking ---

async function rename(link: PublicLink, newName: string): Promise<void> {
	try {
		const renamed = await renamePublicLink(link.id, newName)
		link.name = renamed.name
	} catch (e) {
		// The row already shows the old name again
		showError(ocsErrorMessage(e, `Could not rename public link "${link.name}"`))
	}
}

async function confirmRevoke(): Promise<void> {
	const link = revoking.value
	revoking.value = null
	if (!link) return
	try {
		await revokePublicLink(link.id)
	} catch (e) {
		showError(ocsErrorMessage(e, `Could not revoke public link "${link.name}"`))
		return
	}
	links.value = links.value.filter(l => l.id !== link.id)
	emit('changed')
	showSuccess(`Revoked public link "${link.name}".`)
}

// --- How to use public links ---

// This server's address up to the token, from the first link; `…` stands for
// any token, so none is ever shown. One string per block, a line per command.
const examples = computed(() => {
	const base = exampleBase(links.value[0]?.url ?? '')
	const { yesterday, from, to } = exampleDates(todayStr())
	const short = '…/ping/…'
	// Addresses with a `?` are quoted: zsh refuses an unquoted one
	return {
		plain: `curl ${base}…`,
		tickMore: [
			`curl --data-urlencode "appendNote=2.3 GB in 4 min" ${short}`,
			`curl "${short}?date=${yesterday}"`,
			`curl "${short}?value=0"`,
		].join('\n'),
		// A Number or Choice needs a value: a plain call is refused
		number: `curl "${short}?value=72.4"`,
		choice: `curl "${short}?value=2"`,
		viewMore: [
			`curl "${short}?includeNotes=true"`,
			`curl "${short}?from=${from}&to=${to}"`,
		].join('\n'),
	}
})
</script>

<template>
	<div :class="$style.tab">
		<form v-if="creating"
			:class="$style.form"
			@submit.prevent="create"
			@keydown.esc="onFormEscape">
			<h3 :class="$style.formHeading">
				<NcIconSvgWrapper :path="MDI_LINK_VARIANT" />
				New public link
			</h3>
			<input ref="nameField"
				v-model="name"
				:class="$style.nameField"
				type="text"
				:maxlength="MAX_NAME_LENGTH"
				placeholder="Link name"
				aria-label="Link name">

			<fieldset :class="$style.fieldset">
				<legend :class="$style.legend">
					Permissions
				</legend>
				<NcCheckboxRadioSwitch v-model="permission"
					type="radio"
					value="tick"
					name="tickbuddy-public-link-permission">
					Tick only
					<template #description>
						Ticks this track; not allowed to read
					</template>
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch v-model="permission"
					type="radio"
					value="view"
					name="tickbuddy-public-link-permission">
					View only
					<template #description>
						Reads this track's ticks; not allowed to tick
					</template>
				</NcCheckboxRadioSwitch>
			</fieldset>

			<div :class="$style.fieldset">
				<div :class="$style.legend">
					Allowed days
				</div>
				<div :class="$style.days">
					<NcIconSvgWrapper :path="MDI_CALENDAR_OUTLINE" :class="$style.daysIcon" />
					<NcSelect v-model="dayOption"
						:options="dayOptions"
						:clearable="false"
						:searchable="false"
						aria-label-combobox="Allowed days"
						:class="$style.daysSelect" />
				</div>
			</div>

			<p :class="$style.hint">
				Permissions and allowed days can't be changed later.
			</p>

			<div :class="$style.formButtons">
				<NcButton variant="secondary" @click="cancelCreating">
					Cancel
				</NcButton>
				<NcButton variant="primary" type="submit" :disabled="!canCreate">
					Create
				</NcButton>
			</div>
		</form>

		<template v-else>
			<p :class="$style.help">
				Public links provide read or write access to this track, without an app password, to anyone or anything with the link. Useful for scripts and home automations. Also known as ping URLs or webhooks.
			</p>

			<NcLoadingIcon v-if="status === 'loading'" :size="32" :class="$style.loading" />
			<NcEmptyContent v-else-if="status === 'error'"
				name="Couldn't load the public links"
				description="Check your connection, then try again.">
				<template #action>
					<NcButton @click="load">
						Try again
					</NcButton>
				</template>
			</NcEmptyContent>
			<template v-else>
				<ul v-if="links.length > 0" :class="$style.list">
					<PublicLinkRow v-for="link in links"
						:key="link.id"
						:link="link"
						:now="loadedAt"
						:copied="copiedIds.has(link.id)"
						@copy="copy(link)"
						@rename="(newName) => rename(link, newName)"
						@revoke="revoking = link" />
				</ul>
				<!-- Not NcEmptyContent, which is too large for the sidebar -->
				<div v-else :class="$style.empty">
					<p :class="$style.emptyName">
						No public links yet
					</p>
					<p>Add one with New public link.</p>
				</div>

				<NcButton variant="primary" :class="$style.newButton" @click="startCreating">
					<template #icon>
						<NcIconSvgWrapper :path="MDI_PLUS" />
					</template>
					New public link
				</NcButton>

				<template v-if="links.length > 0">
					<NcButton variant="tertiary"
						alignment="start-reverse"
						:aria-expanded="String(howToOpen)"
						aria-controls="tickbuddy-public-links-how-to"
						:class="$style.howToToggle"
						@click="howToOpen = !howToOpen">
						<template #icon>
							<!-- directional: the closed chevron points left in right-to-left languages -->
							<NcIconSvgWrapper :path="howToOpen ? MDI_CHEVRON_DOWN : MDI_CHEVRON_RIGHT" directional />
						</template>
						<span :class="$style.howToLabel">
							How to use public links
							<NcIconSvgWrapper :path="MDI_INFORMATION_OUTLINE"
								:size="16"
								:class="$style.infoIcon"
								aria-hidden="true" />
						</span>
					</NcButton>
					<div v-show="howToOpen" id="tickbuddy-public-links-how-to" :class="$style.howTo">
						<p>Create one link for each script or device, so that each one can be revoked separately.</p>
						<p>
							<strong>A Tick only link</strong> ticks a Yes/No track for today; calling it again the same day changes nothing. On a Counter track, it adds 1 each time it's called. GET, POST and HEAD all tick, so don't paste the link into chats or emails: their link previews would call it.
						</p>
						<template v-if="track.type === 'number'">
							<p>On a Number track, like this one, a Tick only link needs a value: a call without one is refused. This records 72.4:</p>
							<pre :class="$style.code">{{ examples.number }}</pre>
						</template>
						<template v-else-if="track.type === 'choice'">
							<p>On a Choice track, like this one, a Tick only link needs a value: a call without one is refused. This picks the second option:</p>
							<pre :class="$style.code">{{ examples.choice }}</pre>
						</template>
						<pre v-else :class="$style.code">{{ examples.plain }}</pre>
						<p>Add a line to the day's note, tick another day, or set an exact value:</p>
						<pre :class="$style.code">{{ examples.tickMore }}</pre>
						<p>
							<strong>A View only link</strong> answers today's tick as JSON:
						</p>
						<pre :class="$style.code">{{ examples.plain }}</pre>
						<p>Include the notes, or ask for several days:</p>
						<pre :class="$style.code">{{ examples.viewMore }}</pre>
					</div>
				</template>
			</template>
		</template>

		<NcDialog v-if="revoking"
			:open="true"
			:name="`Revoke &quot;${revoking.name}&quot;`"
			size="small"
			:buttons="[
				{ label: 'Cancel', variant: 'secondary', callback: () => { revoking = null } },
				{ label: 'Revoke', variant: 'primary', callback: confirmRevoke },
			]"
			@update:open="(open) => { if (!open) revoking = null }">
			<p :class="$style.dialogText">
				{{ revokeText(revoking, track.name) }}
			</p>
		</NcDialog>
	</div>
</template>

<style module>
/* The sidebar body's padding, as the Details tab */
.tab {
	padding-block: 8px;
	padding-inline: var(--app-sidebar-padding);
}

.help,
.hint {
	color: var(--color-text-maxcontrast);
}

.loading {
	margin-block: 24px;
}

.list {
	margin-block: 8px;
}

.empty {
	margin-block: 12px;
	padding: 16px;
	border: 2px dashed var(--color-border);
	border-radius: var(--border-radius-large);
	color: var(--color-text-maxcontrast);
	text-align: center;
}

.emptyName {
	color: var(--color-main-text);
	font-weight: bold;
}

.newButton {
	margin-block-start: 8px;
}

.howToToggle {
	margin-block-start: 16px;
}

.howToLabel {
	display: inline-flex;
	align-items: center;
	gap: 4px;
}

.infoIcon {
	color: var(--color-text-maxcontrast);
}

.howTo {
	color: var(--color-text-maxcontrast);
	font-size: var(--font-size-small, 13px);
}

.howTo p {
	margin-block: 8px;
}

.howTo strong {
	color: var(--color-main-text);
}

/* Wraps anywhere, so a long address never makes the sidebar scroll sideways */
.code {
	margin: 0;
	padding: 8px;
	border-radius: var(--border-radius);
	background-color: var(--color-background-dark);
	color: var(--color-main-text);
	font-family: var(--font-face-monospace, monospace);
	white-space: pre-wrap;
	overflow-wrap: anywhere;
}

.form {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.formHeading {
	display: flex;
	align-items: center;
	gap: 8px;
	margin: 0;
	font-size: 16px;
	font-weight: bold;
}

/* Under the form's class, to outrank core's input styles */
.form .nameField {
	width: 100%;
	margin: 0;
}

.fieldset {
	margin: 0;
	padding: 0;
	border: none;
}

.legend {
	margin-block-end: 6px;
	font-weight: bold;
}

.days {
	display: flex;
	align-items: center;
	gap: 8px;
}

.daysIcon {
	flex: none;
	color: var(--color-text-maxcontrast);
}

.daysSelect {
	flex: 1 1 auto;
	min-width: 0;
}

/* Side by side at the start, as Details' Archive and Delete */
.formButtons {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
}

/* As the archive confirm's text */
.dialogText {
	margin-block: 1rem;
}
</style>
