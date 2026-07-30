<script setup>
import UiLoadingSkeleton from './UiLoadingSkeleton.vue'

defineProps({
  label: { type: String, required: true },
  value: { type: [String, Number], default: '—' },
  supportingText: { type: String, default: '' },
  loading: Boolean
})
</script>

<template>
  <article class="ui-card ui-kpi-card" :aria-busy="loading || undefined">
    <UiLoadingSkeleton v-if="loading" :rows="3" label="Loading metric" />
    <template v-else>
      <header class="ui-kpi-card__header">
        <span class="ui-kpi-card__label">{{ label }}</span>
        <slot name="icon"></slot>
      </header>
      <strong class="ui-kpi-card__value">{{ value }}</strong>
      <footer v-if="supportingText || $slots.footer" class="ui-kpi-card__footer">
        <span v-if="supportingText" class="ui-kpi-card__supporting">{{ supportingText }}</span>
        <slot name="footer"></slot>
      </footer>
    </template>
  </article>
</template>
