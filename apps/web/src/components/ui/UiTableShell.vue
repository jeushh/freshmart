<script setup>
import UiEmptyState from './UiEmptyState.vue'
import UiErrorState from './UiErrorState.vue'
import UiLoadingSkeleton from './UiLoadingSkeleton.vue'

defineProps({
  title: { type: String, default: '' },
  description: { type: String, default: '' },
  loading: Boolean,
  loadingLabel: { type: String, default: 'Loading table' },
  empty: Boolean,
  emptyTitle: { type: String, default: 'No records found' },
  emptyDescription: { type: String, default: '' },
  error: { type: String, default: '' },
  retrying: Boolean
})

defineEmits(['retry'])
</script>

<template>
  <section class="ui-card ui-table-shell" :aria-busy="loading || undefined">
    <header v-if="title || description || $slots.actions" class="ui-table-shell__header">
      <div>
        <h2 v-if="title" class="ui-table-shell__title">{{ title }}</h2>
        <p v-if="description" class="ui-table-shell__description">{{ description }}</p>
      </div>
      <slot name="actions"></slot>
    </header>
    <div v-if="$slots.toolbar" class="ui-table-shell__toolbar">
      <slot name="toolbar"></slot>
    </div>
    <UiErrorState
      v-if="error"
      :message="error"
      :retrying="retrying"
      @retry="$emit('retry')"
    />
    <div v-else-if="loading" class="ui-state">
      <UiLoadingSkeleton :label="loadingLabel" />
    </div>
    <UiEmptyState
      v-else-if="empty"
      :title="emptyTitle"
      :description="emptyDescription"
    >
      <template v-if="$slots.emptyActions" #actions>
        <slot name="emptyActions"></slot>
      </template>
    </UiEmptyState>
    <div v-else class="ui-table-shell__scroll">
      <slot></slot>
    </div>
    <footer v-if="$slots.footer" class="ui-table-shell__footer">
      <slot name="footer"></slot>
    </footer>
  </section>
</template>
