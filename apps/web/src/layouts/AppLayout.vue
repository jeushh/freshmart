<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import AppSidebar from '../components/navigation/AppSidebar.vue'
import AppTopbar from '../components/navigation/AppTopbar.vue'
import { sessionStore } from '../stores/session.js'
const mobileOpen = ref(false)
const router = useRouter()
async function signOut() { await sessionStore.logout(); router.replace('/login') }
</script>
<template>
  <div class="app-shell">
    <AppSidebar :open="mobileOpen" @close="mobileOpen=false" />
    <div class="app-main">
      <AppTopbar @menu="mobileOpen=true" @logout="signOut" />
      <main class="page-container"><RouterView /></main>
    </div>
  </div>
</template>
