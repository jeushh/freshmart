<script setup>
import UiTableShell from '../ui/UiTableShell.vue'

defineProps({
  columns: { type: Array, required: true },
  rows: { type: Array, required: true },
  empty: { type: String, default: 'No records found.' }
})
</script>

<template>
  <UiTableShell :empty="!rows.length" :empty-title="empty">
    <table>
      <thead>
        <tr>
          <th v-for="column in columns" :key="column.key">{{ column.label }}</th>
          <th v-if="$slots.actions">Actions</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="row in rows" :key="row.id || JSON.stringify(row)">
          <td v-for="column in columns" :key="column.key">
            <slot :name="`cell-${column.key}`" :row="row">{{ row[column.key] ?? '—' }}</slot>
          </td>
          <td v-if="$slots.actions">
            <slot name="actions" :row="row"></slot>
          </td>
        </tr>
      </tbody>
    </table>
  </UiTableShell>
</template>
