<script setup>
import { onErrorCaptured, ref } from 'vue'

const fatalError = ref('')

onErrorCaptured(error => {
  fatalError.value = error?.message || 'The page could not be displayed.'
  return false
})
</script>

<template>
  <section v-if="fatalError" class="fatal-error">
    <div class="notice-panel error-page">
      <p class="error-code">!</p>
      <h1>FreshMart needs to reload this page</h1>
      <p>{{ fatalError }}</p>
      <button class="primary-button" @click="window.location.reload()">Reload application</button>
    </div>
  </section>
  <RouterView v-else />
</template>
