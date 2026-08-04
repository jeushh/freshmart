<script setup>
import UiButton from './UiButton.vue'

defineProps({
  title: { type: String, default: 'Unable to load this content' },
  message: { type: String, default: 'Please try again.' },
  retryLabel: { type: String, default: 'Try again' },
  retrying: Boolean,
  showRetry: { type: Boolean, default: true }
})

defineEmits(['retry'])
</script>

<template>
  <div class="ui-state ui-state--error" role="alert">
    <div class="ui-state__icon" aria-hidden="true">
      <svg width="22" height="22" viewBox="0 0 24 24" fill="none">
        <path d="M12 8v5m0 3h.01M4.5 20h15L12 4z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
      </svg>
    </div>
    <h3 class="ui-state__title">{{ title }}</h3>
    <p class="ui-state__description">{{ message }}</p>
    <UiButton
      v-if="showRetry"
      variant="secondary"
      :loading="retrying"
      loading-label="Retrying"
      @click="$emit('retry')"
    >
      {{ retryLabel }}
    </UiButton>
  </div>
</template>
