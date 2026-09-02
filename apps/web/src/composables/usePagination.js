import { computed, ref } from 'vue'

const positiveInteger = (value, fallback) => {
  const parsed = Number.parseInt(value, 10)

  return Number.isInteger(parsed) && parsed > 0 ? parsed : fallback
}

const nonNegativeInteger = value => Math.max(0, Number.parseInt(value, 10) || 0)

export function usePagination({ perPage: initialPerPage = 20 } = {}) {
  const page = ref(1)
  const perPage = ref(positiveInteger(initialPerPage, 20))
  const total = ref(0)
  const lastPage = ref(1)

  const hasPreviousPage = computed(() => page.value > 1)
  const hasNextPage = computed(() => page.value < lastPage.value)

  function setMetadata(metadata = {}) {
    total.value = nonNegativeInteger(metadata.total)
    perPage.value = positiveInteger(metadata.per_page, perPage.value)
    lastPage.value = positiveInteger(metadata.last_page, 1)
    page.value = Math.min(
      positiveInteger(metadata.current_page, 1),
      lastPage.value
    )
  }

  function goToPage(requestedPage) {
    page.value = Math.min(
      Math.max(1, positiveInteger(requestedPage, 1)),
      lastPage.value
    )
  }

  function nextPage() {
    goToPage(page.value + 1)
  }

  function previousPage() {
    goToPage(page.value - 1)
  }

  function resetPage() {
    page.value = 1
  }

  return {
    page,
    perPage,
    total,
    lastPage,
    hasPreviousPage,
    hasNextPage,
    setMetadata,
    goToPage,
    nextPage,
    previousPage,
    resetPage
  }
}
