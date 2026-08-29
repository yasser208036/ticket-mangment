import { createPinia } from 'pinia'
import { createApp } from 'vue'
import './style.css'
import App from './App.vue'
import router from './router'

// Pinia must install before router starts guarded navigation.
createApp(App).use(createPinia()).use(router).mount('#app')
