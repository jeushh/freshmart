<script setup>
import { computed, onMounted, ref } from 'vue'
import { api } from '../../api/http.js'
import { sessionStore } from '../../stores/session.js'
import PageHeader from '../../components/common/PageHeader.vue'
import WorkspaceTable from '../../components/common/WorkspaceTable.vue'

const rows = ref([])
const products = ref([])
const filters = ref({ status: '', priority: '', search: '' })
const form = ref({ product_id: '', requested_quantity: 1, priority: 'Normal', reason: '', notes: '' })
const reviewNotes = ref('')
const error = ref('')
const message = ref('')
const selectedProduct = computed(() => products.value.find(product => product.id === Number(form.value.product_id)))

async function load() {
  try {
    error.value = ''
    const params = Object.fromEntries(Object.entries(filters.value).filter(([, value]) => value))
    const data = await api.get('/restock-requests', { ...params, per_page: 100 })
    rows.value = data.requests.data
    products.value = data.products
  } catch (requestError) {
    error.value = requestError.message
  }
}

function selectProduct() {
  const product = selectedProduct.value
  if (product) form.value.requested_quantity = Math.max(1, product.max_stock - product.stock_quantity)
}

async function createRequest() {
  try {
    error.value = ''
    message.value = ''
    await api.post('/restock-requests', form.value)
    message.value = 'Restock request submitted.'
    form.value = { product_id: '', requested_quantity: 1, priority: 'Normal', reason: '', notes: '' }
    await load()
  } catch (requestError) {
    error.value = requestError.message
  }
}

async function review(id, decision) {
  try {
    error.value = ''
    message.value = ''
    await api.post(`/restock-requests/${id}/review`, { decision, notes: reviewNotes.value || null })
    message.value = `Restock request ${decision.toLowerCase()}.`
    reviewNotes.value = ''
    await load()
  } catch (requestError) {
    error.value = requestError.message
  }
}

onMounted(load)
</script>

<template>
  <PageHeader title="Restock Requests" description="Request stock replenishment and review pending requests." />
  <p v-if="error" class="form-error">{{ error }}</p>
  <p v-if="message" class="success-message">{{ message }}</p>

  <form v-if="sessionStore.can('restock.request')" class="management-form" @submit.prevent="createRequest">
    <div class="form-grid">
      <label>
        Product
        <select v-model="form.product_id" required @change="selectProduct">
          <option value="">Select product</option>
          <option v-for="product in products" :key="product.id" :value="product.id">
            {{ product.sku }} — {{ product.name }}
          </option>
        </select>
      </label>
      <label>Requested quantity<input v-model.number="form.requested_quantity" type="number" min="1" required></label>
      <label>
        Priority
        <select v-model="form.priority"><option>Low</option><option>Normal</option><option>High</option><option>Urgent</option></select>
      </label>
      <label class="full-field">Reason<textarea v-model.trim="form.reason" rows="2" maxlength="500" required /></label>
    </div>
    <p v-if="selectedProduct" class="field-help">
      Current {{ selectedProduct.stock_quantity }} · Reorder {{ selectedProduct.reorder_level }} · Maximum {{ selectedProduct.max_stock }}
    </p>
    <button class="primary-button">Submit request</button>
  </form>

  <form class="filter-bar" @submit.prevent="load">
    <input v-model.trim="filters.search" placeholder="Reference, SKU, or product">
    <select v-model="filters.status">
      <option value="">All statuses</option>
      <option>Pending Approval</option><option>Approved</option><option>Rejected</option>
      <option>Purchase Order Created</option><option>Ordered</option>
      <option>Partially Received</option><option>Completed</option><option>Cancelled</option>
    </select>
    <select v-model="filters.priority">
      <option value="">All priorities</option><option>Low</option><option>Normal</option><option>High</option><option>Urgent</option>
    </select>
    <button class="secondary-button">Apply filters</button>
  </form>
  <label v-if="sessionStore.can('restock.approve')" class="review-notes">
    Review notes
    <input v-model.trim="reviewNotes" maxlength="500" placeholder="Optional notes">
  </label>

  <WorkspaceTable
    :columns="[
      { key: 'ref_number', label: 'Reference' },
      { key: 'product_name', label: 'Product' },
      { key: 'current_stock', label: 'Current' },
      { key: 'reorder_level', label: 'Reorder' },
      { key: 'requested_quantity', label: 'Requested' },
      { key: 'requested_by', label: 'Requester' },
      { key: 'priority', label: 'Priority' },
      { key: 'status', label: 'Status' }
    ]"
    :rows="rows"
  >
    <template #cell-status="{ row }"><span class="status-badge">{{ row.status }}</span></template>
    <template v-if="sessionStore.can('restock.approve')" #actions="{ row }">
      <button v-if="row.status === 'Pending Approval'" @click="review(row.id, 'Approved')">Approve</button>
      <button v-if="row.status === 'Pending Approval'" @click="review(row.id, 'Rejected')">Reject</button>
    </template>
  </WorkspaceTable>
</template>
