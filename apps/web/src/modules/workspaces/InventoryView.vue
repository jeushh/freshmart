<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import { api } from '../../api/http.js'
import PageHeader from '../../components/common/PageHeader.vue'
import WorkspaceTable from '../../components/common/WorkspaceTable.vue'
import { sessionStore } from '../../stores/session.js'
import { formatMoney } from '../../utils/formatters.js'

const rows = ref([])
const lowStockRows = ref([])
const movements = ref([])
const error = ref('')
const search = ref('')
const route = useRoute()
const section = computed(() => {
  if (route.path.endsWith('/low-stock')) return 'low-stock'
  if (route.path.endsWith('/movements')) return 'movements'
  return 'products'
})
const page = computed(() => ({
  products: {
    title: 'Products & Stock',
    description: 'Manage product information and make audited stock adjustments.'
  },
  'low-stock': {
    title: 'Low Stock',
    description: 'Monitor active products at or below their reorder levels.'
  },
  movements: {
    title: 'Inventory Movements',
    description: 'Review the latest audited changes to product stock.'
  }
})[section.value])
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
    const params = { per_page: 100 }
    const searchTerm = search.value.trim()
    if (searchTerm) params.search = searchTerm
    const data = await api.get('/workspace/inventory', params)
    rows.value = data.products.data
    lowStockRows.value = data.low_stock_products
    movements.value = data.inventory_movements
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
  <PageHeader :title="page.title" :description="page.description" />
  <p v-if="error" class="form-error">{{ error }}</p>
  <form v-if="section === 'products' && sessionStore.can('inventory.manage')" class="inline-form" @submit.prevent="save">
    <input v-model="form.sku" placeholder="SKU" required>
    <input v-model="form.name" placeholder="Product name" required>
    <input v-model.number="form.price" type="number" min="0" step=".01" placeholder="Price">
    <input v-model.number="form.cost_price" type="number" min="0" step=".01" placeholder="Cost">
    <label class="compact-label">Initial stock<input v-model.number="form.stock_quantity" type="number" min="0"></label>
    <button class="primary-button">Add product</button>
  </form>
  <div v-if="section === 'products'" class="table-toolbar">
    <input v-model="search" placeholder="Search products">
    <button @click="load">Search</button>
  </div>
  <WorkspaceTable
    v-if="section === 'products'"
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
  <WorkspaceTable
    v-else-if="section === 'low-stock'"
    :columns="[
      { key: 'sku', label: 'SKU' },
      { key: 'name', label: 'Product' },
      { key: 'stock_quantity', label: 'Current stock' },
      { key: 'reorder_level', label: 'Reorder level' },
      { key: 'max_stock', label: 'Maximum stock' },
      { key: 'unit', label: 'Unit' },
      { key: 'supplier_name', label: 'Supplier' }
    ]"
    :rows="lowStockRows"
  >
    <template #cell-stock_quantity="{ row }">
      <span class="danger-text">{{ row.stock_quantity }}</span>
    </template>
  </WorkspaceTable>
  <WorkspaceTable
    v-else
    :columns="[
      { key: 'created_at', label: 'Date' },
      { key: 'sku', label: 'SKU' },
      { key: 'product_name', label: 'Product' },
      { key: 'movement_type', label: 'Movement' },
      { key: 'quantity', label: 'Quantity' },
      { key: 'previous_stock', label: 'Previous' },
      { key: 'new_stock', label: 'New stock' },
      { key: 'performed_by', label: 'Performed by' }
    ]"
    :rows="movements"
  />
</template>
