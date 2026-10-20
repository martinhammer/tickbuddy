import { createApp } from 'vue'
import TrackSettings from './components/TrackSettings.vue'
import { preferStrictHandling } from './api.ts'

preferStrictHandling()

const app = createApp(TrackSettings)
app.mount('#tickbuddy-settings')
