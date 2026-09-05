<script setup>
import { computed, onMounted, ref } from 'vue'
import { api } from '../../api/http.js'
import { sessionStore } from '../../stores/session.js'
import PageHeader from '../../components/common/PageHeader.vue'
import WorkspaceTable from '../../components/common/WorkspaceTable.vue'
import { formatMoney } from '../../utils/formatters.js'

const orders = ref([])
const suppliers = ref([])
const products = ref([])
const restockRequests = ref([])
const selected = ref(null)
const filters = ref({ approval_status: '', status: '', supplier_status: '', supplier_id: '', search: '' })
const actionNotes = ref('')
const error = ref('')
const message = ref('')
const responseForm = ref({
  open: false,
  response: 'Accepted',
  supplier_reference: '',
  expected_delivery_date: '',
  notes: ''
})

const blankLine = () => ({ product_id: '', quantity: 1, unit_cost: 0 })
const blankForm = () => ({
  id: null,
  supplier_id: '',
  restock_request_id: null,
  expected_delivery_date: '',
  notes: '',
  items: [blankLine()]
})
const form = ref(blankForm())
const availableProducts = computed(() => products.value.filter(product =>
  !form.value.supplier_id || !product.supplier_id || product.supplier_id === Number(form.value.supplier_id)
))
const formTotal = computed(() => form.value.items.reduce(
  (sum, item) => sum + Number(item.quantity || 0) * Number(item.unit_cost || 0),
  0
))
const canCancelSelected = computed(() => {
  if (!selected.value || selected.value.receivings.length) return false
  const state = selected.value.order.approval_status
  if (state === 'Approved') return sessionStore.can('procurement.purchase_orders.approve')
  return ['Draft', 'Submitted'].includes(state)
    && sessionStore.can('procurement.purchase_orders.manage')
})

const canMarkSent = computed(() => {
  if (!selected.value) return false
  const order = selected.value.order
  return sessionStore.can('procurement.purchase_orders.manage')
    && order.approval_status === 'Approved'
    && order.status === 'Approved'
    && order.supplier_status === 'Not Sent'
})

const canRecordResponse = computed(() => {
  if (!selected.value) return false
  const order = selected.value.order
  return sessionStore.can('procurement.purchase_orders.manage')
    && order.approval_status === 'Approved'
    && order.status === 'Ordered'
    && order.supplier_status === 'Sent'
})

function supplierStatusText(status) {
  if (status === null || status === undefined) return 'Historical — not tracked'
  if (status === 'Not Sent') return 'Not Sent'
  if (status === 'Sent') return 'Sent to Supplier'
  if (status === 'Accepted') return 'Supplier Accepted'
  if (status === 'Rejected') return 'Supplier Rejected'
  return status
}

async function load(refreshSelected = false) {
  try {
    error.value = ''
    await sessionStore.refreshSettings()
    const params = Object.fromEntries(Object.entries(filters.value).filter(([, value]) => value))
    const data = await api.get('/purchase-orders', { ...params, per_page: 100 })
    orders.value = data.orders.data
    suppliers.value = data.suppliers
    products.value = data.products
    restockRequests.value = data.approved_restock_requests
    if (refreshSelected && selected.value) await open(selected.value.order.id)
  } catch (requestError) {
    error.value = requestError.message
  }
}

function setProduct(line) {
  const product = products.value.find(item => item.id === Number(line.product_id))
  if (product) line.unit_cost = Number(product.cost_price)
}

function addLine() {
  form.value.items.push(blankLine())
}

function removeLine(index) {
  if (form.value.items.length > 1) form.value.items.splice(index, 1)
}

function useRestock() {
  const restock = restockRequests.value.find(item => item.id === Number(form.value.restock_request_id))
  if (!restock) return
  form.value.supplier_id = restock.supplier_id || ''
  const product = products.value.find(item => item.id === restock.product_id)
  form.value.items = [{
    product_id: restock.product_id,
    quantity: restock.requested_quantity,
    unit_cost: Number(product?.cost_price || 0)
  }]
}

async function save() {
  try {
    error.value = ''
    message.value = ''
    const payload = {
      ...form.value,
      restock_request_id: form.value.restock_request_id || null,
      expected_delivery_date: form.value.expected_delivery_date || null
    }
    const data = form.value.id
      ? await api.put(`/purchase-orders/${form.value.id}`, payload)
      : await api.post('/purchase-orders', payload)
    selected.value = data
    message.value = form.value.id ? 'Purchase order updated.' : 'Purchase order created.'
    form.value = blankForm()
    await load()
  } catch (requestError) {
    error.value = requestError.message
  }
}

async function open(id) {
  try {
    error.value = ''
    responseForm.value.open = false
    selected.value = await api.get(`/purchase-orders/${id}`)
  } catch (requestError) {
    error.value = requestError.message
  }
}

function editSelected() {
  const detail = selected.value
  form.value = {
    id: detail.order.id,
    supplier_id: detail.order.supplier_id,
    restock_request_id: detail.order.restock_request_id,
    expected_delivery_date: detail.order.expected_delivery_date || '',
    notes: detail.order.notes || '',
    items: detail.items.map(item => ({
      product_id: item.product_id,
      quantity: item.quantity_ordered,
      unit_cost: Number(item.unit_cost)
    }))
  }
  window.scrollTo({ top: 0, behavior: 'smooth' })
}

async function transition(action, payload = {}) {
  try {
    error.value = ''
    message.value = ''
    const id = selected.value.order.id
    const data = await api.post(`/purchase-orders/${id}/${action}`, payload)
    selected.value = data
    message.value = `Purchase order ${action} completed.`
    actionNotes.value = ''
    await load()
  } catch (requestError) {
    error.value = requestError.message
  }
}

async function markSent() {
  await transition('send')
}

function openResponseModal() {
  responseForm.value = {
    open: true,
    response: 'Accepted',
    supplier_reference: '',
    expected_delivery_date: selected.value?.order?.expected_delivery_date || '',
    notes: ''
  }
}

async function submitResponse() {
  try {
    error.value = ''
    message.value = ''
    const id = selected.value.order.id
    const payload = {
      response: responseForm.value.response,
      supplier_reference: responseForm.value.supplier_reference || null,
      expected_delivery_date: responseForm.value.expected_delivery_date || null,
      notes: responseForm.value.notes || null
    }
    const data = await api.post(`/purchase-orders/${id}/supplier-response`, payload)
    selected.value = data
    message.value = `Supplier response (${responseForm.value.response}) recorded.`
    responseForm.value.open = false
    await load()
  } catch (requestError) {
    error.value = requestError.message
  }
}

onMounted(load)
</script>

<template>
  <PageHeader title="Purchase Orders" description="Create, approve, and track supplier orders." />
  <p v-if="error" class="form-error">{{ error }}</p>
  <p v-if="message" class="success-message">{{ message }}</p>

  <form
    v-if="sessionStore.can('procurement.purchase_orders.manage')"
    class="management-form"
    @submit.prevent="save"
  >
    <div class="form-grid">
      <label>
        Supplier
        <select v-model="form.supplier_id" required>
          <option value="">Select supplier</option>
          <option v-for="supplier in suppliers" :key="supplier.id" :value="supplier.id">{{ supplier.name }}</option>
        </select>
      </label>
      <label>
        Approved restock request
        <select v-model="form.restock_request_id" @change="useRestock">
          <option :value="null">None</option>
          <option v-for="restock in restockRequests" :key="restock.id" :value="restock.id">
            {{ restock.ref_number }} — {{ restock.product_name }}
          </option>
        </select>
      </label>
      <label>Expected delivery<input v-model="form.expected_delivery_date" type="date"></label>
      <label class="full-field">Notes<textarea v-model.trim="form.notes" rows="2" maxlength="1000" /></label>
    </div>

    <h3>Line items</h3>
    <div v-for="(line, index) in form.items" :key="index" class="line-item">
      <select v-model="line.product_id" required @change="setProduct(line)">
        <option value="">Select product</option>
        <option v-for="product in availableProducts" :key="product.id" :value="product.id">
          {{ product.sku }} — {{ product.name }}
        </option>
      </select>
      <input v-model.number="line.quantity" type="number" min="1" placeholder="Quantity" required>
      <input v-model.number="line.unit_cost" type="number" min="0" step=".01" placeholder="Unit cost" required>
      <strong>{{ formatMoney(Number(line.quantity || 0) * Number(line.unit_cost || 0)) }}</strong>
      <button class="secondary-button" type="button" @click="removeLine(index)">Remove</button>
    </div>
    <div class="form-actions">
      <button class="secondary-button" type="button" @click="addLine">Add line</button>
      <strong>Display total: {{ formatMoney(formTotal) }}</strong>
      <button class="primary-button">{{ form.id ? 'Update draft' : 'Create draft' }}</button>
      <button v-if="form.id" class="secondary-button" type="button" @click="form = blankForm()">Cancel edit</button>
    </div>
  </form>

  <form class="filter-bar" @submit.prevent="load">
    <input v-model.trim="filters.search" placeholder="PO number or supplier">
    <select v-model="filters.approval_status">
      <option value="">All approval states</option>
      <option>Draft</option><option>Submitted</option><option>Approved</option><option>Rejected</option><option>Cancelled</option>
    </select>
    <select v-model="filters.supplier_status">
      <option value="">All supplier statuses</option>
      <option value="Not Sent">Not Sent</option>
      <option value="Sent">Sent</option>
      <option value="Accepted">Accepted</option>
      <option value="Rejected">Rejected</option>
    </select>
    <select v-model="filters.status">
      <option value="">All receiving states</option>
      <option>Pending</option><option>Approved</option><option>Ordered</option>
      <option>Partially Received</option><option>Fully Received</option><option>Cancelled</option>
    </select>
    <button class="secondary-button">Apply filters</button>
  </form>

  <WorkspaceTable
    :columns="[
      { key: 'po_number', label: 'PO number' },
      { key: 'supplier_name', label: 'Supplier' },
      { key: 'total_amount', label: 'Total' },
      { key: 'approval_status', label: 'Approval' },
      { key: 'supplier_status', label: 'Supplier status' },
      { key: 'status', label: 'Receiving' },
      { key: 'total_fulfilled', label: 'Fulfillment' },
      { key: 'expected_delivery_date', label: 'Expected' }
    ]"
    :rows="orders"
  >
    <template #cell-total_amount="{ row }">{{ formatMoney(row.total_amount) }}</template>
    <template #cell-approval_status="{ row }"><span class="status-badge">{{ row.approval_status }}</span></template>
    <template #cell-supplier_status="{ row }"><span class="status-badge">{{ supplierStatusText(row.supplier_status) }}</span></template>
    <template #cell-status="{ row }"><span class="status-badge">{{ row.status }}</span></template>
    <template #cell-total_fulfilled="{ row }">{{ row.total_fulfilled }} / {{ row.total_ordered }}</template>
    <template #actions="{ row }"><button @click="open(row.id)">Details</button></template>
  </WorkspaceTable>

  <section v-if="selected" class="detail-panel">
    <div class="detail-heading">
      <div>
        <h2>{{ selected.order.po_number }}</h2>
        <p>{{ selected.order.supplier_name }} · Server total {{ formatMoney(selected.order.total_amount) }}</p>
        <p class="field-help">
          Supplier state: <strong>{{ supplierStatusText(selected.order.supplier_status) }}</strong>
          <span v-if="selected.order.sent_by"> · Sent by {{ selected.order.sent_by }} at {{ selected.order.sent_to_supplier_at }}</span>
          <span v-if="selected.order.supplier_responded_at"> · Responded at {{ selected.order.supplier_responded_at }}</span>
          <span v-if="selected.order.supplier_reference"> · Ref #{{ selected.order.supplier_reference }}</span>
        </p>
        <p v-if="selected.order.supplier_response_notes" class="field-help">
          Response notes: {{ selected.order.supplier_response_notes }}
        </p>
      </div>
      <div class="form-actions">
        <button
          v-if="sessionStore.can('procurement.purchase_orders.manage') && selected.order.approval_status === 'Draft'"
          class="secondary-button"
          @click="editSelected"
        >Edit</button>
        <button
          v-if="sessionStore.can('procurement.purchase_orders.manage') && selected.order.approval_status === 'Draft'"
          class="primary-button"
          @click="transition('submit')"
        >Submit</button>
        <button
          v-if="sessionStore.can('procurement.purchase_orders.approve') && selected.order.approval_status === 'Submitted'"
          class="primary-button"
          @click="transition('review', { decision: 'Approved', notes: actionNotes || null })"
        >Approve</button>
        <button
          v-if="sessionStore.can('procurement.purchase_orders.approve') && selected.order.approval_status === 'Submitted'"
          class="secondary-button danger-button"
          @click="transition('review', { decision: 'Rejected', notes: actionNotes || null })"
        >Reject</button>
        <button
          v-if="canMarkSent"
          class="primary-button"
          @click="markSent"
        >Mark Sent to Supplier</button>
        <button
          v-if="canRecordResponse"
          class="primary-button"
          @click="openResponseModal"
        >Record Supplier Response</button>
        <button
          v-if="canCancelSelected"
          class="secondary-button"
          @click="transition('cancel', { notes: actionNotes || null })"
        >Cancel</button>
      </div>
    </div>
    <label class="review-notes">Action notes<input v-model.trim="actionNotes" maxlength="500"></label>

    <div v-if="responseForm.open" class="management-form modal-box">
      <h3>Record Supplier Response</h3>
      <div class="form-grid">
        <label>
          Decision
          <select v-model="responseForm.response" required>
            <option value="Accepted">Accepted</option>
            <option value="Rejected">Rejected</option>
          </select>
        </label>
        <label>
          Supplier Reference #
          <input v-model.trim="responseForm.supplier_reference" placeholder="e.g. SO-98765" maxlength="100">
        </label>
        <label>
          Expected Delivery Date
          <input v-model="responseForm.expected_delivery_date" type="date">
        </label>
        <label class="full-field">
          Response Notes
          <textarea v-model.trim="responseForm.notes" rows="2" maxlength="1000" placeholder="Vendor communication notes..."></textarea>
        </label>
      </div>
      <div class="form-actions">
        <button class="primary-button" type="button" @click="submitResponse">Save response</button>
        <button class="secondary-button" type="button" @click="responseForm.open = false">Cancel</button>
      </div>
    </div>

    <WorkspaceTable
      :columns="[
        { key: 'sku', label: 'SKU' },
        { key: 'product_name', label: 'Product' },
        { key: 'quantity_ordered', label: 'Ordered' },
        { key: 'fulfilled_quantity', label: 'Fulfilled' },
        { key: 'outstanding_quantity', label: 'Outstanding' },
        { key: 'current_stock', label: 'Current stock' },
        { key: 'unit_cost', label: 'Unit cost' },
        { key: 'line_total', label: 'Line total' }
      ]"
      :rows="selected.items"
    >
      <template #cell-unit_cost="{ row }">{{ formatMoney(row.unit_cost) }}</template>
      <template #cell-line_total="{ row }">{{ formatMoney(row.line_total) }}</template>
    </WorkspaceTable>

  </section>
</template>

<style scoped>
.danger-button {
  background: linear-gradient(180deg, #dc2626, #b91c1c);
  border-color: #b91c1c;
  color: #fff;
}

.danger-button:hover {
  background: #991b1b;
  border-color: #991b1b;
}
</style>
