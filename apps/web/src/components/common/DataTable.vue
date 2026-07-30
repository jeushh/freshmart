<script setup>
import { computed, ref, watch } from 'vue'
import UiTableShell from '../ui/UiTableShell.vue'

const props = defineProps({
  columns: { type: Array, required: true },
  rows: { type: Array, required: true },
  rowKey: { type: String, default: 'id' },
  emptyText: { type: String, default: 'No records found.' }
})

const query = ref('')
const page = ref(1)
const size = ref(10)
const filtered = computed(() => {
  const value = query.value.trim().toLowerCase()
  if (!value) return props.rows
  return props.rows.filter(row =>
    props.columns.some(column =>
      String(row[column.key] ?? '').toLowerCase().includes(value)
    )
  )
})
const pages = computed(() => Math.max(1, Math.ceil(filtered.value.length / size.value)))
const visible = computed(() =>
  filtered.value.slice((page.value - 1) * size.value, page.value * size.value)
)

watch([query, size, () => props.rows], () => page.value = 1)
watch(pages, count => {
  if (page.value > count) page.value = count
})
</script>

<template>
  <UiTableShell :empty="!visible.length" :empty-title="emptyText">
    <template #toolbar>
      <label class="search-field">
        <span>Search records</span>
        <input v-model="query" type="search" placeholder="Type to search…">
      </label>
      <label class="rows-field">
        <span>Rows</span>
        <select v-model.number="size">
          <option :value="5">5</option>
          <option :value="10">10</option>
          <option :value="25">25</option>
          <option :value="50">50</option>
        </select>
      </label>
    </template>
    <table>
      <thead>
        <tr>
          <th v-for="column in columns" :key="column.key">{{ column.label }}</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="row in visible" :key="row[rowKey]">
          <td v-for="column in columns" :key="column.key">
            <slot :name="`cell-${column.key}`" :row="row">{{ row[column.key] }}</slot>
          </td>
        </tr>
      </tbody>
    </table>
    <template #footer>
      <footer class="pagination">
        <span>
          Showing {{ filtered.length ? (page - 1) * size + 1 : 0 }}–{{ Math.min(page * size, filtered.length) }}
          of {{ filtered.length }}
        </span>
        <div>
          <button :disabled="page === 1" @click="page = 1">First</button>
          <button :disabled="page === 1" @click="page--">Previous</button>
          <strong>Page {{ page }} of {{ pages }}</strong>
          <button :disabled="page === pages" @click="page++">Next</button>
          <button :disabled="page === pages" @click="page = pages">Last</button>
        </div>
      </footer>
    </template>
  </UiTableShell>
</template>
