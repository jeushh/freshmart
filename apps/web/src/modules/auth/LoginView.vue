<script setup>
import { ref } from 'vue'; import { useRouter } from 'vue-router'; import { sessionStore } from '../../stores/session.js'
const username=ref(''); const password=ref(''); const error=ref(''); const busy=ref(false); const router=useRouter()
async function submit(){ error.value=''; busy.value=true; try{ await sessionStore.login(username.value,password.value); router.replace(sessionStore.homePath()) }catch(e){ error.value=e.message }finally{ busy.value=false } }
</script>
<template><main class="login-page"><section class="login-card"><div class="brand login-brand"><span class="brand-mark">F</span><div><strong>FreshMart</strong><small>Business System</small></div></div><h1>Welcome back</h1><p>Sign in to continue to your workspace.</p><form @submit.prevent="submit"><label>Username<input v-model.trim="username" autocomplete="username" required></label><label>Password<input v-model="password" type="password" autocomplete="current-password" required></label><p v-if="error" class="form-error" role="alert">{{ error }}</p><button class="primary-button" :disabled="busy">{{ busy ? 'Signing in…' : 'Sign in' }}</button></form></section></main></template>
