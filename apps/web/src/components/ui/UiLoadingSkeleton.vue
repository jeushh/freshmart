<script setup>
defineProps({
  rows: { type: Number, default: 4 },
  label: { type: String, default: 'Loading content' }
})
</script>

<template>
  <div class="ui-skeleton" role="status" :aria-label="label" aria-busy="true">
    <span v-for="row in rows" :key="row" class="ui-skeleton__line" aria-hidden="true"></span>
    <span class="ui-sr-only">{{ label }}</span>
  </div>
</template>
