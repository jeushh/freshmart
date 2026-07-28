<script setup>
import { sessionStore } from '../../stores/session.js'
defineEmits(['menu','logout'])
</script>
<template>
<header class="topbar">
  <button class="icon-button mobile-menu" aria-label="Open navigation" @click="$emit('menu')">☰</button>
  <div><strong>{{ sessionStore.state.user?.fullName || sessionStore.state.user?.username }}</strong><small>Signed in</small></div>
  <button class="secondary-button" @click="$emit('logout')">Sign out</button>
</header>
</template>
