<script setup lang="ts">
import { ref } from 'vue'
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcAppNavigation from '@nextcloud/vue/components/NcAppNavigation'
import NcAppNavigationItem from '@nextcloud/vue/components/NcAppNavigationItem'
import NcAppNavigationSettings from '@nextcloud/vue/components/NcAppNavigationSettings'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcContent from '@nextcloud/vue/components/NcContent'
import TickGrid from './components/TickGrid.vue'

const showPrivate = ref(false)
</script>

<template>
	<NcContent app-name="tickbuddy">
		<NcAppNavigation>
			<template #list>
				<NcAppNavigationItem name="All tracks" active />
			</template>
			<template #footer>
				<NcAppNavigationSettings>
					<NcCheckboxRadioSwitch type="switch"
						v-model="showPrivate">
						Show private tracks
					</NcCheckboxRadioSwitch>
				</NcAppNavigationSettings>
			</template>
		</NcAppNavigation>
		<NcAppContent>
			<TickGrid :show-private="showPrivate" />
		</NcAppContent>
	</NcContent>
</template>
