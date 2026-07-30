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
const filters = ref({ approval_status: '', status: '', supplier_id: '', search: '' })
const actionNotes = ref('')
const receiving = ref({})
const error = ref('')
const message = ref('')
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
const receivingValid = computed(() => {
  if (!selected.value) return false
  const entries = Object.values(receiving.value)
  return entries.some(item => Number(item.delivered_quantity) > 0) && entries.every(item => {
    const line = selected.value.items.find(candidate => candidate.id === item.purchase_order_item_id)
    const delivered = Number(item.delivered_quantity || 0)
    const damaged = Number(item.damaged_quantity || 0)
    const rejected = Number(item.rejected_quantity || 0)
    const accepted = delivered - damaged - rejected
    return delivered >= 0
      && damaged >= 0
      && rejected >= 0
      && accepted >= 0
      && accepted <= Number(line?.outstanding_quantity || 0)
  })
})
const canCancelSelected = computed(() => {
  if (!selected.value || selected.value.receivings.length) return false
  const state = selected.value.order.approval_status
  if (state === 'Approved') return sessionStore.can('procurement.purchase_orders.approve')
  return ['Draft', 'Submitted'].includes(state)
    && sessionStore.can('procurement.purchase_orders.manage')
})

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
    selected.value = await api.get(`/purchase-orders/${id}`)
    receiving.value = Object.fromEntries(selected.value.items.map(item => [
      item.id,
      { purchase_order_item_id: item.id, delivered_quantity: 0, damaged_quantity: 0, rejected_quantity: 0 }
    ]))
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

function acceptedFor(item) {
  return Math.max(
    0,
    Number(item.delivered_quantity || 0)
      - Number(item.damaged_quantity || 0)
      - Number(item.rejected_quantity || 0)
  )
}

async function receiveStock() {
  try {
    error.value = ''
    message.value = ''
    const id = selected.value.order.id
    await api.post(`/purchase-orders/${id}/receive`, {
      notes: actionNotes.value || null,
      items: Object.values(receiving.value)
    })
    message.value = 'Stock receiving recorded and inventory refreshed.'
    actionNotes.value = ''
    await load()
    await open(id)
  } catch (requestError) {
    error.value = requestError.message
  }
}

onMounted(load)
</script>

<template>
  <PageHeader title="Purchase Orders" description="Create, approve, track, and receive supplier orders." />
  <p v-if="error" class="form-error">{{ error }}</p>
  <p v-if="message" class="success-message">{{ message }}</p>

  <form v-if="sessionStore.can('procurement.purchase_orders.manage')" class="management-form" @submit.prevent="save">
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
      { key: 'status', label: 'Receiving' },
      { key: 'total_fulfilled', label: 'Fulfillment' },
      { key: 'expected_delivery_date', label: 'Expected' }
    ]"
    :rows="orders"
  >
    <template #cell-total_amount="{ row }">{{ formatMoney(row.total_amount) }}</template>
    <template #cell-approval_status="{ row }"><span class="status-badge">{{ row.approval_status }}</span></template>
    <template #cell-status="{ row }"><span class="status-badge">{{ row.status }}</span></template>
    <template #cell-total_fulfilled="{ row }">{{ row.total_fulfilled }} / {{ row.total_ordered }}</template>
    <template #actions="{ row }"><button @click="open(row.id)">Details</button></template>
  </WorkspaceTable>

  <section v-if="selected" class="detail-panel">
    <div class="detail-heading">
      <div>
        <h2>{{ selected.order.po_number }}</h2>
        <p>{{ selected.order.supplier_name }} · Server total {{ formatMoney(selected.order.total_amount) }}</p>
      </div>
      <div class="form-actions">
        <button
          v-if="sessionStore.can('procurement.purchase_orders.manage') && selected.order.approval_status === 'Draft'"
          @click="editSelected"
        >Edit</button>
        <button
          v-if="sessionStore.can('procurement.purchase_orders.manage') && selected.order.approval_status === 'Draft'"
          @click="transition('submit')"
        >Submit</button>
        <button
          v-if="sessionStore.can('procurement.purchase_orders.approve') && selected.order.approval_status === 'Submitted'"
          @click="transition('review', { decision: 'Approved', notes: actionNotes || null })"
        >Approve</button>
        <button
          v-if="sessionStore.can('procurement.purchase_orders.approve') && selected.order.approval_status === 'Submitted'"
          @click="transition('review', { decision: 'Rejected', notes: actionNotes || null })"
        >Reject</button>
        <button
          v-if="canCancelSelected"
          @click="transition('cancel', { notes: actionNotes || null })"
        >Cancel</button>
      </div>
    </div>
    <label class="review-notes">Action notes<input v-model.trim="actionNotes" maxlength="500"></label>

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

    <form
      v-if="sessionStore.can('procurement.stock.receive') && selected.order.approval_status === 'Approved' && ['Approved', 'Ordered', 'Partially Received'].includes(selected.order.status)"
      class="receiving-form"
      @submit.prevent="receiveStock"
    >
      <h3>Receive stock</h3>
      <div v-for="item in selected.items" :key="item.id" class="receiving-line">
        <strong>{{ item.sku }} — {{ item.product_name }}</strong>
        <label>Outstanding<output>{{ item.outstanding_quantity }}</output></label>
        <label>Delivered<input v-model.number="receiving[item.id].delivered_quantity" type="number" min="0"></label>
        <label>Accepted<output>{{ acceptedFor(receiving[item.id]) }}</output></label>
        <label>Damaged<input v-model.number="receiving[item.id].damaged_quantity" type="number" min="0" :max="receiving[item.id].delivered_quantity"></label>
        <label>Rejected<input v-model.number="receiving[item.id].rejected_quantity" type="number" min="0" :max="receiving[item.id].delivered_quantity"></label>
      </div>
      <p v-if="!receivingValid" class="field-help">
        Enter at least one valid delivery. Accepted units cannot exceed the outstanding quantity, and damaged plus rejected units cannot exceed delivered units.
      </p>
      <button class="primary-button" :disabled="!receivingValid">Record receiving</button>
    </form>

    <h3 v-if="selected.receivings.length">Receiving history</h3>
    <article v-for="history in selected.receivings" :key="history.id" class="receiving-history">
      <h4>Receiving #{{ history.id }} · {{ history.receiving_date }} · {{ history.received_by }}</h4>
      <p v-if="history.notes">{{ history.notes }}</p>
      <WorkspaceTable
        :columns="[
          { key: 'sku', label: 'SKU' },
          { key: 'delivered_quantity', label: 'Delivered' },
          { key: 'accepted_quantity', label: 'Accepted / fulfilled' },
          { key: 'damaged_quantity', label: 'Damaged' },
          { key: 'rejected_quantity', label: 'Rejected' },
          { key: 'unit_cost', label: 'Unit cost' },
          { key: 'accepted_cost', label: 'Accepted cost' }
        ]"
        :rows="history.items"
      >
        <template #cell-unit_cost="{ row }">{{ formatMoney(row.unit_cost) }}</template>
        <template #cell-accepted_cost="{ row }">{{ formatMoney(row.accepted_cost) }}</template>
      </WorkspaceTable>
    </article>
  </section>
</template>
