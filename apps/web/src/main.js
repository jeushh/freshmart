import { createApp } from 'vue'
import App from './App.vue'
import router from './router/index.js'
import './assets/tokens.css'
import './assets/base.css'
import './assets/ui.css'
import './assets/shell.css'

createApp(App).use(router).mount('#app')
