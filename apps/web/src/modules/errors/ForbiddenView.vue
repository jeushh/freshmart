<script setup>
import { sessionStore } from '../../stores/session.js'
</script>

<template>
  <section class="notice-panel error-page">
    <p class="error-code">403</p>
    <h1>Access not available</h1>
    <p>Your account does not have permission to open this area.</p>
    <RouterLink class="primary-button" :to="sessionStore.homePath()">Return to your workspace</RouterLink>
  </section>
</template>
