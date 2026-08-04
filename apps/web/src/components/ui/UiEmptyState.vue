<script setup>
defineProps({
  title: { type: String, default: 'Nothing to show yet' },
  description: { type: String, default: '' }
})
</script>

<template>
  <div class="ui-state">
    <div class="ui-state__icon" aria-hidden="true">
      <slot name="icon">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none">
          <path d="M4 7.5h16v12H4zM8 4.5h8" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round" />
        </svg>
      </slot>
    </div>
    <h3 class="ui-state__title">{{ title }}</h3>
    <p v-if="description" class="ui-state__description">{{ description }}</p>
    <div v-if="$slots.actions">
      <slot name="actions"></slot>
    </div>
  </div>
</template>
