import { createApp } from 'vue'
import { createPinia } from 'pinia'
import { RouterLink } from 'vue-router'
import './assets/css/main.css'
import App from './App.vue'
import router from './router'
import { initNotificationNav } from './plugins/notificationNav'

const app = createApp(App)
app.use(createPinia())
app.use(router)
app.component('NuxtLink', RouterLink)
app.mount('#app')

// Lắng nghe deep-link điều hướng từ Service Worker (notificationclick)
initNotificationNav()
