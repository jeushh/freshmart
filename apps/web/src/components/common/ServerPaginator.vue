<script setup>
import { computed } from 'vue'
import UiButton from '../ui/UiButton.vue'

const props = defineProps({
  currentPage: { type: Number, required: true },
  lastPage: { type: Number, required: true },
  perPage: { type: Number, required: true },
  total: { type: Number, required: true }
})

const emit = defineEmits(['page-change'])

const firstResult = computed(() => (
  props.total > 0 ? ((props.currentPage - 1) * props.perPage) + 1 : 0
))
const lastResult = computed(() => Math.min(props.currentPage * props.perPage, props.total))
const hasPreviousPage = computed(() => props.currentPage > 1)
const hasNextPage = computed(() => props.currentPage < props.lastPage)
const hasMultiplePages = computed(() => props.lastPage > 1)

function requestPage(page) {
  if (page >= 1 && page <= props.lastPage && page !== props.currentPage) {
    emit('page-change', page)
  }
}
</script>

<template>
  <div class="server-paginator" aria-label="Pagination">
    <p class="server-paginator__range">
      Showing {{ firstResult }}–{{ lastResult }} of {{ total }}
    </p>
    <div v-if="hasMultiplePages" class="server-paginator__controls">
      <UiButton
        size="sm"
        variant="secondary"
        :disabled="!hasPreviousPage"
        aria-label="Previous page"
        @click="requestPage(currentPage - 1)"
      >
        Previous
      </UiButton>
      <span class="server-paginator__page" aria-current="page">
        Page {{ currentPage }} of {{ lastPage }}
      </span>
      <UiButton
        size="sm"
        variant="secondary"
        :disabled="!hasNextPage"
        aria-label="Next page"
        @click="requestPage(currentPage + 1)"
      >
        Next
      </UiButton>
    </div>
  </div>
</template>

<style scoped>
.server-paginator {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
  padding-top: 1rem;
}

.server-paginator__range,
.server-paginator__page {
  color: var(--text-muted, #667085);
  font-size: 0.875rem;
}

.server-paginator__range {
  margin: 0;
}

.server-paginator__controls {
  display: flex;
  align-items: center;
  gap: 0.75rem;
}

@media (max-width: 640px) {
  .server-paginator {
    align-items: flex-start;
    flex-direction: column;
  }
}
</style>
