<script setup lang="ts">
import { ref, onMounted } from 'vue'
import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcAppNavigation from '@nextcloud/vue/components/NcAppNavigation'
import NcAppNavigationItem from '@nextcloud/vue/components/NcAppNavigationItem'
import NcAppNavigationSettings from '@nextcloud/vue/components/NcAppNavigationSettings'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcContent from '@nextcloud/vue/components/NcContent'
import AnalyticsView from './components/AnalyticsView.vue'
import TickGrid from './components/TickGrid.vue'

type View = 'journal' | 'readonly' | 'analytics'

const currentView = ref<View>('journal')
const showPrivate = ref(false)

onMounted(async () => {
	try {
		const response = await axios.get(generateOcsUrl('/apps/tickbuddy/api/preferences'))
		const view = response.data.ocs.data.defaultView as View
		if (['journal', 'readonly', 'analytics'].includes(view)) {
			currentView.value = view
		}
	} catch {
		// use default
	}
})
</script>

<template>
	<NcContent app-name="tickbuddy">
		<NcAppNavigation>
			<template #list>
				<NcAppNavigationItem name="Edit journal"
					:active="currentView === 'journal'"
					@click="currentView = 'journal'" />
				<NcAppNavigationItem name="View journal"
					:active="currentView === 'readonly'"
					@click="currentView = 'readonly'" />
				<NcAppNavigationItem name="Analytics"
					:active="currentView === 'analytics'"
					@click="currentView = 'analytics'" />
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
			<TickGrid v-if="currentView === 'journal'"
				:show-private="showPrivate" />
			<TickGrid v-else-if="currentView === 'readonly'"
				:show-private="showPrivate"
				readonly />
			<AnalyticsView v-else-if="currentView === 'analytics'"
				:show-private="showPrivate" />
		</NcAppContent>
	</NcContent>
</template>
