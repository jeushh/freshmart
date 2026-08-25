<script setup>
import { computed, onMounted, ref } from 'vue'
import { api } from '../../api/http.js'
import { sessionStore } from '../../stores/session.js'
import PageHeader from '../../components/common/PageHeader.vue'
import WorkspaceTable from '../../components/common/WorkspaceTable.vue'
import { formatMoney } from '../../utils/formatters.js'

const orders = ref([])
const selected = ref(null)
const filters = ref({ search: '' })
const receiving = ref({})
const receivingNotes = ref('')
const error = ref('')
const message = ref('')
const listLoading = ref(false)
const detailLoading = ref(false)

const eligibleOrders = computed(() => orders.value.filter(isEligibleForReceiving))
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

function isEligibleForReceiving(order) {
  if (!sessionStore.can('procurement.stock.receive')) return false
  if (order.approval_status !== 'Approved') return false
  if (order.supplier_status === null) {
    return ['Approved', 'Ordered', 'Partially Received'].includes(order.status)
  }
  return order.supplier_status === 'Accepted' && ['Ordered', 'Partially Received'].includes(order.status)
}

function acceptedFor(item) {
  return Math.max(
    0,
    Number(item.delivered_quantity || 0)
      - Number(item.damaged_quantity || 0)
      - Number(item.rejected_quantity || 0)
  )
}

async function load() {
  try {
    error.value = ''
    listLoading.value = true
    await sessionStore.refreshSettings()
    const params = Object.fromEntries(Object.entries(filters.value).filter(([, value]) => value))
    const data = await api.get('/purchase-orders', {
      ...params,
      approval_status: 'Approved',
      per_page: 100
    })
    orders.value = data.orders.data
  } catch (requestError) {
    error.value = requestError.message
  } finally {
    listLoading.value = false
  }
}

async function open(id) {
  try {
    error.value = ''
    detailLoading.value = true
    selected.value = await api.get(`/purchase-orders/${id}`)
    receiving.value = Object.fromEntries(selected.value.items.map(item => [
      item.id,
      { purchase_order_item_id: item.id, delivered_quantity: 0, damaged_quantity: 0, rejected_quantity: 0 }
    ]))
    receivingNotes.value = ''
  } catch (requestError) {
    error.value = requestError.message
  } finally {
    detailLoading.value = false
  }
}

async function receiveStock() {
  try {
    error.value = ''
    message.value = ''
    const id = selected.value.order.id
    await api.post(`/purchase-orders/${id}/receive`, {
      notes: receivingNotes.value || null,
      items: Object.values(receiving.value)
    })
    message.value = 'Stock receiving recorded and inventory refreshed.'
    await open(id)
    await load()
  } catch (requestError) {
    error.value = requestError.message
  }
}

onMounted(load)
</script>

<template>
  <PageHeader title="Stock Receiving" description="Receive accepted quantities from approved supplier purchase orders." />
  <p v-if="error" class="form-error">{{ error }}</p>
  <p v-if="message" class="success-message">{{ message }}</p>

  <form class="filter-bar" @submit.prevent="load">
    <input v-model.trim="filters.search" placeholder="PO number or supplier">
    <button class="secondary-button" :disabled="listLoading">Apply filters</button>
  </form>

  <p v-if="listLoading" class="field-help">Loading purchase orders eligible for receiving…</p>
  <WorkspaceTable
    v-else
    :columns="[
      { key: 'po_number', label: 'PO number' },
      { key: 'supplier_name', label: 'Supplier' },
      { key: 'approval_status', label: 'Approval' },
      { key: 'supplier_status', label: 'Supplier status' },
      { key: 'status', label: 'Status' },
      { key: 'total_fulfilled', label: 'Fulfillment' },
      { key: 'expected_delivery_date', label: 'Expected' }
    ]"
    :rows="eligibleOrders"
    empty="No purchase orders are currently eligible for receiving."
  >
    <template #cell-approval_status="{ row }"><span class="status-badge">{{ row.approval_status }}</span></template>
    <template #cell-supplier_status="{ row }"><span class="status-badge">{{ row.supplier_status || 'Historical — not tracked' }}</span></template>
    <template #cell-status="{ row }"><span class="status-badge">{{ row.status }}</span></template>
    <template #cell-total_fulfilled="{ row }">{{ row.total_fulfilled }} / {{ row.total_ordered }}</template>
    <template #actions="{ row }"><button @click="open(row.id)">Receive</button></template>
  </WorkspaceTable>

  <p v-if="detailLoading" class="field-help">Loading purchase order details…</p>
  <section v-if="selected && !detailLoading" class="detail-panel">
    <div class="detail-heading">
      <div>
        <h2>{{ selected.order.po_number }}</h2>
        <p>{{ selected.order.supplier_name }} · PO status {{ selected.order.status }}</p>
        <p class="field-help">
          Approval: <strong>{{ selected.order.approval_status }}</strong>
          · Supplier: <strong>{{ selected.order.supplier_status || 'Historical — not tracked' }}</strong>
        </p>
      </div>
    </div>

    <WorkspaceTable
      :columns="[
        { key: 'sku', label: 'SKU' },
        { key: 'product_name', label: 'Product' },
        { key: 'quantity_ordered', label: 'Ordered' },
        { key: 'fulfilled_quantity', label: 'Already received' },
        { key: 'outstanding_quantity', label: 'Outstanding' },
        { key: 'current_stock', label: 'Current stock' },
        { key: 'unit_cost', label: 'Unit cost' }
      ]"
      :rows="selected.items"
    >
      <template #cell-unit_cost="{ row }">{{ formatMoney(row.unit_cost) }}</template>
    </WorkspaceTable>

    <form class="receiving-form" @submit.prevent="receiveStock">
      <h3>Receive stock</h3>
      <div v-for="item in selected.items" :key="item.id" class="receiving-line">
        <strong>{{ item.sku }} — {{ item.product_name }}</strong>
        <label>Outstanding<output>{{ item.outstanding_quantity }}</output></label>
        <label>Delivered<input v-model.number="receiving[item.id].delivered_quantity" type="number" min="0"></label>
        <label>Accepted<output>{{ acceptedFor(receiving[item.id]) }}</output></label>
        <label>Damaged<input v-model.number="receiving[item.id].damaged_quantity" type="number" min="0" :max="receiving[item.id].delivered_quantity"></label>
        <label>Rejected<input v-model.number="receiving[item.id].rejected_quantity" type="number" min="0" :max="receiving[item.id].delivered_quantity"></label>
      </div>
      <label class="review-notes">
        Receiving notes
        <textarea v-model.trim="receivingNotes" rows="2" maxlength="500" placeholder="Optional receiving notes"></textarea>
      </label>
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
