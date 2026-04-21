<script setup lang="ts">
import { ref } from 'vue'
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcAppNavigation from '@nextcloud/vue/components/NcAppNavigation'
import NcAppNavigationItem from '@nextcloud/vue/components/NcAppNavigationItem'
import NcAppNavigationSettings from '@nextcloud/vue/components/NcAppNavigationSettings'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcContent from '@nextcloud/vue/components/NcContent'
import AnalyticsView from './components/AnalyticsView.vue'
import TickGrid from './components/TickGrid.vue'

const currentView = ref<'journal' | 'readonly' | 'analytics'>('journal')
const showPrivate = ref(false)
</script>

<template>
	<NcContent app-name="tickbuddy">
		<NcAppNavigation>
			<template #list>
				<NcAppNavigationItem name="Journal entry"
					:active="currentView === 'journal'"
					@click="currentView = 'journal'" />
				<NcAppNavigationItem name="View only"
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
			<AnalyticsView v-else-if="currentView === 'analytics'" />
		</NcAppContent>
	</NcContent>
</template>
