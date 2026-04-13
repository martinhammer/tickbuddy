<script setup lang="ts">
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcContent from '@nextcloud/vue/components/NcContent'
import TickGrid from './components/TickGrid.vue'
</script>

<template>
	<NcContent app-name="tickbuddy">
		<NcAppContent>
			<TickGrid />
		</NcAppContent>
	</NcContent>
</template>
