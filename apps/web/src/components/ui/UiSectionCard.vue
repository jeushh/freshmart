<script setup>
import UiLoadingSkeleton from './UiLoadingSkeleton.vue'

defineProps({
  title: { type: String, default: '' },
  description: { type: String, default: '' },
  loading: Boolean,
  loadingLabel: { type: String, default: 'Loading section' }
})
</script>

<template>
  <section class="ui-card ui-section-card" :aria-busy="loading || undefined">
    <header v-if="title || description || $slots.actions" class="ui-section-card__header">
      <div>
        <h2 v-if="title" class="ui-section-card__title">{{ title }}</h2>
        <p v-if="description" class="ui-section-card__description">{{ description }}</p>
      </div>
      <slot name="actions"></slot>
    </header>
    <div class="ui-section-card__body">
      <UiLoadingSkeleton v-if="loading" :label="loadingLabel" />
      <slot v-else></slot>
    </div>
    <footer v-if="$slots.footer" class="ui-section-card__footer">
      <slot name="footer"></slot>
    </footer>
  </section>
</template>
