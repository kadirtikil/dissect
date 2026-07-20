import './assets/main.css'

import { createApp } from 'vue'
import { createPinia } from 'pinia'

import App from './App.vue'

// No router: the package mounts this at an arbitrary prefix (/dissect by
// default), so a path-matching router would find no route and render nothing.
// It is a single screen either way.
const app = createApp(App)

app.use(createPinia())

app.mount('#app')
