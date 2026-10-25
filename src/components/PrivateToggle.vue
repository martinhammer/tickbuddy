<script setup lang="ts">
import NcButton from '@nextcloud/vue/components/NcButton'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import { MDI_EYE_OFF_OUTLINE, MDI_EYE_OUTLINE } from '../icons.ts'
import { showPrivate } from '../preferences.ts'

// The eye on Today, the Journal and Analytics: one saved value, shared by all three.
</script>

<template>
	<NcButton variant="tertiary"
		:aria-label="showPrivate ? 'Hide private tracks' : 'Show private tracks'"
		:title="showPrivate ? 'Hide private tracks' : 'Show private tracks'"
		:aria-pressed="String(showPrivate)"
		@click="showPrivate = !showPrivate">
		<template #icon>
			<NcIconSvgWrapper :path="showPrivate ? MDI_EYE_OUTLINE : MDI_EYE_OFF_OUTLINE" />
		</template>
	</NcButton>
</template>
