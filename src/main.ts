import { createApp } from 'vue'
import App from './App.vue'
import { preferStrictHandling } from './api.ts'

preferStrictHandling()

const app = createApp(App)
app.mount('#tickbuddy')
