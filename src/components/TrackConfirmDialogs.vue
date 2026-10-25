<script setup lang="ts">
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import type { Track } from '../types.ts'

// The archive and delete confirms. Delete offers "Archive instead" for an
// active track, with no second confirm.

defineProps<{
	archiving: Track | null
	deleting: Track | null
}>()

const emit = defineEmits<{
	'confirm-archive': []
	'confirm-delete': []
	'archive-instead': []
	cancel: []
}>()
</script>

<template>
	<NcDialog v-if="archiving"
		:open="true"
		:name="`Archive &quot;${archiving.name}&quot;`"
		size="small"
		:buttons="[
			{ label: 'Cancel', variant: 'secondary', callback: () => emit('cancel') },
			{ label: 'Archive', variant: 'primary', callback: () => emit('confirm-archive') },
		]"
		@update:open="(open) => { if (!open) emit('cancel') }">
		<p :class="$style.dialogText">
			Archived tracks are no longer tracked or shown in the journal and widgets. All their ticks are kept, and you can restore them at any time.
		</p>
	</NcDialog>

	<!-- The actions slot replaces the `buttons` prop, which can't place a button on the left -->
	<NcDialog v-if="deleting"
		:open="true"
		:name="`Delete &quot;${deleting.name}&quot;`"
		size="small"
		@update:open="(open) => { if (!open) emit('cancel') }">
		<NcNoteCard type="warning">
			This will permanently delete the track "{{ deleting.name }}" and all its ticks and notes. This action cannot be undone.
		</NcNoteCard>
		<p v-if="deleting.archivedAt === null" :class="$style.dialogText">
			To keep its history, archive it instead.
		</p>
		<template #actions>
			<NcButton v-if="deleting.archivedAt === null"
				variant="tertiary"
				:class="$style.archiveInstead"
				@click="emit('archive-instead')">
				Archive instead
			</NcButton>
			<NcButton variant="secondary" @click="emit('cancel')">
				Cancel
			</NcButton>
			<NcButton variant="primary" @click="emit('confirm-delete')">
				Delete
			</NcButton>
		</template>
	</NcDialog>
</template>

<style module>
/* Plain text in a dialog gets NcNoteCard's 1rem above and below, so it sits as far
   from the title and the buttons as the warning boxes in the other dialogs do */
.dialogText {
	margin-block: 1rem;
}

/* The dialog's action row is justify-content: end; this pushes the button to the left */
.archiveInstead {
	margin-inline-end: auto;
}
</style>
