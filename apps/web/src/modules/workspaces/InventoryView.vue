<script setup>
import { onMounted, ref } from 'vue'
import { api } from '../../api/http.js'
import PageHeader from '../../components/common/PageHeader.vue'
import WorkspaceTable from '../../components/common/WorkspaceTable.vue'
import { sessionStore } from '../../stores/session.js'
import { formatMoney } from '../../utils/formatters.js'

const rows = ref([])
const error = ref('')
const search = ref('')
const blankForm = () => ({
  sku: '',
  name: '',
  category: 'General',
  price: 0,
  cost_price: 0,
  stock_quantity: 0,
  reorder_level: 5,
  unit: 'pc',
  supplier_id: null,
  status: 'Active'
})
const form = ref(blankForm())

async function load() {
  try {
    error.value = ''
    await sessionStore.refreshSettings()
    const data = await api.get('/workspace/inventory', { search: search.value, per_page: 100 })
    rows.value = data.products.data
  } catch (requestError) {
    error.value = requestError.message
  }
}

async function save() {
  try {
    error.value = ''
    await api.post('/workspace/products', form.value)
    form.value = blankForm()
    await load()
  } catch (requestError) {
    error.value = requestError.message
  }
}

async function adjust(row) {
  const quantity = Number(prompt(`Audited stock adjustment for ${row.name} (negative to remove):`, 0))
  if (!quantity) return
  try {
    error.value = ''
    await api.post(`/workspace/products/${row.id}/adjust`, { quantity })
    await load()
  } catch (requestError) {
    error.value = requestError.message
  }
}

onMounted(load)
</script>

<template>
  <PageHeader title="Inventory" description="Manage products and make audited stock adjustments." />
  <p v-if="error" class="form-error">{{ error }}</p>
  <form v-if="sessionStore.can('inventory.manage')" class="inline-form" @submit.prevent="save">
    <input v-model="form.sku" placeholder="SKU" required>
    <input v-model="form.name" placeholder="Product name" required>
    <input v-model.number="form.price" type="number" min="0" step=".01" placeholder="Price">
    <input v-model.number="form.cost_price" type="number" min="0" step=".01" placeholder="Cost">
    <label class="compact-label">Initial stock<input v-model.number="form.stock_quantity" type="number" min="0"></label>
    <button class="primary-button">Add product</button>
  </form>
  <div class="table-toolbar">
    <input v-model="search" placeholder="Search products">
    <button @click="load">Search</button>
  </div>
  <WorkspaceTable
    :columns="[
      { key: 'sku', label: 'SKU' },
      { key: 'name', label: 'Product' },
      { key: 'category', label: 'Category' },
      { key: 'price', label: 'Price' },
      { key: 'stock_quantity', label: 'Stock' },
      { key: 'reorder_level', label: 'Reorder' }
    ]"
    :rows="rows"
  >
    <template #cell-price="{ row }">{{ formatMoney(row.price) }}</template>
    <template #cell-stock_quantity="{ row }">
      <span :class="{ 'danger-text': row.stock_quantity <= row.reorder_level }">{{ row.stock_quantity }}</span>
    </template>
    <template v-if="sessionStore.can('inventory.manage')" #actions="{ row }">
      <button @click="adjust(row)">Adjust stock</button>
    </template>
  </WorkspaceTable>
</template>
