<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api } from '../../api/http.js'
import { UiButton, UiPageHeader, UiSelect, UiStatusBadge, UiTableShell } from '../../components/ui/index.js'

const route = useRoute(); const router = useRouter()
const invoices = ref([]); const suppliers = ref([]); const pagination = ref({ current_page: 1, last_page: 1, total: 0 })
const loading = ref(true); const error = ref('')
const filters = computed(() => ({ status: String(route.query.status || ''), supplier_id: String(route.query.supplier_id || ''), page: Math.max(1, Number(route.query.page || 1)), per_page: [10, 20, 50].includes(Number(route.query.per_page)) ? Number(route.query.per_page) : 20 }))
const requestFilters = computed(() => ({
  page: filters.value.page,
  per_page: filters.value.per_page,
  ...(filters.value.status ? { status: filters.value.status } : {}),
  ...(filters.value.supplier_id ? { supplier_id: filters.value.supplier_id } : {}),
}))
async function loadSuppliers() {
  try {
    const data = await api.get('/finance/purchase-orders', { per_page: 100 })
    const found = new Map(data.orders.data.filter(row => row.supplier_id && row.supplier_name).map(row => [String(row.supplier_id), row.supplier_name]))
    suppliers.value = [...found.entries()].map(([id, name]) => ({ id, name })).sort((a, b) => a.name.localeCompare(b.name))
  } catch { suppliers.value = [] }
}
async function load() {
  loading.value = true; error.value = ''
  try {
    const data = await api.get('/supplier-invoices', requestFilters.value)
    invoices.value = data.invoices.data; pagination.value = data.invoices
  } catch (requestError) { error.value = requestError.message } finally { loading.value = false }
}
function setQuery(changes) {
  const next = { ...route.query, ...changes }
  Object.keys(next).forEach(key => { if (next[key] === '' || next[key] === undefined || next[key] === null) delete next[key] })
  router.push({ query: next })
}
function applyFilters() { setQuery({ status: filters.value.status, supplier_id: filters.value.supplier_id, page: 1, per_page: filters.value.per_page }) }
function statusTone(status) { return status === 'Approved' ? 'success' : status === 'Void' ? 'danger' : status === 'Registered' ? 'info' : 'warning' }
watch(() => route.query, load, { deep: true })
onMounted(() => { loadSuppliers(); load() })
</script>

<template>
  <UiPageHeader title="Supplier Invoices" description="Manage structured supplier invoices using server-backed filters and pagination."><template #actions><RouterLink class="ui-button ui-button--primary" to="/finance/supplier-invoices/new">Create supplier invoice</RouterLink></template></UiPageHeader>
  <UiTableShell title="Invoice register" :loading="loading" :error="error" :empty="!invoices.length" empty-title="No supplier invoices" empty-description="No invoices match the selected filters." @retry="load">
    <template #toolbar><form class="filter-form" @submit.prevent="applyFilters"><UiSelect :model-value="filters.status" label="Status" @update:model-value="value => setQuery({ status: value, page: 1 })"><option value="">All statuses</option><option>Draft</option><option>Registered</option><option>Approved</option><option>Disputed</option><option>Void</option></UiSelect><UiSelect :model-value="filters.supplier_id" label="Supplier" @update:model-value="value => setQuery({ supplier_id: value, page: 1 })"><option value="">All suppliers</option><option v-for="supplier in suppliers" :key="supplier.id" :value="supplier.id">{{ supplier.name }}</option></UiSelect><UiSelect :model-value="String(filters.per_page)" label="Rows per page" @update:model-value="value => setQuery({ per_page: Number(value), page: 1 })"><option value="10">10</option><option value="20">20</option><option value="50">50</option></UiSelect><UiButton type="submit" variant="secondary">Apply filters</UiButton></form></template>
    <table><thead><tr><th>Supplier</th><th>PO</th><th>Invoice</th><th>Invoice date</th><th>Due date</th><th>Status</th><th>Action</th></tr></thead><tbody><tr v-for="invoice in invoices" :key="invoice.id"><td>{{ invoice.supplier_name }}</td><td>{{ invoice.po_number }}</td><td>{{ invoice.invoice_number || 'Draft — no number' }}</td><td>{{ invoice.invoice_date || '—' }}</td><td>{{ invoice.due_date || '—' }}</td><td><UiStatusBadge :status="invoice.status" :tone="statusTone(invoice.status)" /></td><td><RouterLink class="ui-button ui-button--secondary ui-button--sm" :to="`/finance/supplier-invoices/${invoice.id}`">View</RouterLink></td></tr></tbody></table>
    <template #footer><div class="pagination"><span>Page {{ pagination.current_page }} of {{ pagination.last_page }} · {{ pagination.total }} invoices</span><div><UiButton size="sm" variant="secondary" :disabled="pagination.current_page <= 1" @click="setQuery({ page: pagination.current_page - 1 })">Previous</UiButton><UiButton size="sm" variant="secondary" :disabled="pagination.current_page >= pagination.last_page" @click="setQuery({ page: pagination.current_page + 1 })">Next</UiButton></div></div></template>
  </UiTableShell>
</template>
<style scoped>.filter-form{display:flex;gap:.75rem;align-items:end;flex-wrap:wrap}.pagination{display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap}</style>
