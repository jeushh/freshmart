<script setup>
import { computed, onMounted, ref } from 'vue'
import { api } from '../../api/http.js'
import { sessionStore } from '../../stores/session.js'
import { formatMoney } from '../../utils/formatters.js'
import {
  UiButton,
  UiConfirmDialog,
  UiInput,
  UiPageHeader,
  UiSectionCard,
  UiStatusBadge,
  UiTableShell
} from '../../components/ui/index.js'

const orders = ref([])
const selected = ref(null)
const filters = ref({ search: '' })
const receiving = ref({})
const receivingNotes = ref('')
const error = ref('')
const message = ref('')
const listLoading = ref(false)
const detailLoading = ref(false)
const confirmOpen = ref(false)
const submitting = ref(false)

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
const pendingLines = computed(() => {
  if (!selected.value) return []
  return selected.value.items
    .map(item => ({ item, entry: receiving.value[item.id] }))
    .filter(({ entry }) => entry && Number(entry.delivered_quantity) > 0)
    .map(({ item, entry }) => ({
      id: item.id,
      sku: item.sku,
      delivered: Number(entry.delivered_quantity),
      accepted: acceptedFor(entry),
      damaged: Number(entry.damaged_quantity || 0),
      rejected: Number(entry.rejected_quantity || 0)
    }))
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

function startReceive() {
  if (!receivingValid.value) return
  confirmOpen.value = true
}

function cancelReceive() {
  confirmOpen.value = false
}

async function confirmReceive() {
  if (!selected.value) return
  submitting.value = true
  error.value = ''
  message.value = ''
  try {
    const id = selected.value.order.id
    await api.post(`/purchase-orders/${id}/receive`, {
      notes: receivingNotes.value || null,
      items: Object.values(receiving.value)
    })
    message.value = 'Stock receiving recorded and inventory refreshed.'
    cancelReceive()
    await open(id)
    await load()
  } catch (requestError) {
    error.value = requestError.message
  } finally {
    submitting.value = false
  }
}

onMounted(load)
</script>

<template>
  <UiPageHeader title="Stock Receiving" description="Receive accepted quantities from approved supplier purchase orders." />

  <UiTableShell
    title="Purchase orders eligible for receiving"
    :loading="listLoading"
    :error="error"
    :empty="!listLoading && !error && !eligibleOrders.length"
    empty-title="No purchase orders are currently eligible for receiving"
    empty-description="Approved purchase orders will appear here once they're ready to receive."
    @retry="load"
  >
    <template #toolbar>
      <form class="filter-form" @submit.prevent="load">
        <UiInput v-model.trim="filters.search" label="Search" size="sm" placeholder="PO number or supplier" />
        <UiButton type="submit" variant="secondary" size="sm">Apply filters</UiButton>
      </form>
    </template>
    <table>
      <thead>
        <tr>
          <th>PO number</th>
          <th>Supplier</th>
          <th>Approval</th>
          <th>Supplier status</th>
          <th>Status</th>
          <th>Fulfillment</th>
          <th>Expected</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="order in eligibleOrders" :key="order.id">
          <td>{{ order.po_number }}</td>
          <td>{{ order.supplier_name }}</td>
          <td><UiStatusBadge :status="order.approval_status" /></td>
          <td>
            <UiStatusBadge v-if="order.supplier_status" :status="order.supplier_status" />
            <span v-else class="field-help">Historical — not tracked</span>
          </td>
          <td><UiStatusBadge :status="order.status" /></td>
          <td>{{ order.total_fulfilled }} / {{ order.total_ordered }}</td>
          <td>{{ order.expected_delivery_date }}</td>
          <td><UiButton size="sm" @click="open(order.id)">Receive</UiButton></td>
        </tr>
      </tbody>
    </table>
  </UiTableShell>

  <p v-if="detailLoading" class="field-help">Loading purchase order details…</p>

  <UiSectionCard
    v-if="selected && !detailLoading"
    :title="selected.order.po_number"
    :description="`${selected.order.supplier_name} · PO status ${selected.order.status}`"
  >
    <div class="receiving-detail">
      <p class="field-help">
        Approval: <strong>{{ selected.order.approval_status }}</strong>
        · Supplier: <strong>{{ selected.order.supplier_status || 'Historical — not tracked' }}</strong>
      </p>

      <div class="table-scroll">
        <table>
          <thead>
            <tr>
              <th>SKU</th>
              <th>Product</th>
              <th>Ordered</th>
              <th>Already received</th>
              <th>Outstanding</th>
              <th>Current stock</th>
              <th>Unit cost</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="item in selected.items" :key="item.id">
              <td>{{ item.sku }}</td>
              <td>{{ item.product_name }}</td>
              <td>{{ item.quantity_ordered }}</td>
              <td>{{ item.fulfilled_quantity }}</td>
              <td>{{ item.outstanding_quantity }}</td>
              <td>{{ item.current_stock }}</td>
              <td>{{ formatMoney(item.unit_cost) }}</td>
            </tr>
          </tbody>
        </table>
      </div>

      <h3 class="receiving-detail__subtitle">Receive stock</h3>
      <form class="receiving-form" @submit.prevent="startReceive">
        <div v-for="item in selected.items" :key="item.id" class="receiving-line">
          <strong class="receiving-line__name">{{ item.sku }} — {{ item.product_name }}</strong>
          <UiInput label="Outstanding" :model-value="item.outstanding_quantity" size="sm" disabled />
          <UiInput
            v-model.number="receiving[item.id].delivered_quantity"
            type="number"
            min="0"
            label="Delivered"
            size="sm"
          />
          <UiInput label="Accepted" :model-value="acceptedFor(receiving[item.id])" size="sm" disabled />
          <UiInput
            v-model.number="receiving[item.id].damaged_quantity"
            type="number"
            min="0"
            :max="receiving[item.id].delivered_quantity"
            label="Damaged"
            size="sm"
          />
          <UiInput
            v-model.number="receiving[item.id].rejected_quantity"
            type="number"
            min="0"
            :max="receiving[item.id].delivered_quantity"
            label="Rejected"
            size="sm"
          />
        </div>
        <label class="ui-field">
          <span class="ui-field__label">Receiving notes</span>
          <textarea
            v-model.trim="receivingNotes"
            class="ui-field-control"
            rows="2"
            maxlength="500"
            placeholder="Optional receiving notes"
          ></textarea>
        </label>
        <p v-if="!receivingValid" class="field-help">
          Enter at least one valid delivery. Accepted units cannot exceed the outstanding quantity, and damaged plus rejected units cannot exceed delivered units.
        </p>
        <p v-if="error" class="form-error" role="alert">{{ error }}</p>
        <p v-if="message" class="success-message">{{ message }}</p>
        <UiButton type="submit" :disabled="!receivingValid">Record receiving</UiButton>
      </form>

      <template v-if="selected.receivings.length">
        <h3 class="receiving-detail__subtitle">Receiving history</h3>
        <div class="receiving-history-list">
          <article v-for="history in selected.receivings" :key="history.id" class="receiving-history">
            <h4>Receiving #{{ history.id }} · {{ history.receiving_date }} · {{ history.received_by }}</h4>
            <p v-if="history.notes" class="field-help">{{ history.notes }}</p>
            <div class="table-scroll">
              <table>
                <thead>
                  <tr>
                    <th>SKU</th>
                    <th>Delivered</th>
                    <th>Accepted / fulfilled</th>
                    <th>Damaged</th>
                    <th>Rejected</th>
                    <th>Unit cost</th>
                    <th>Accepted cost</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="row in history.items" :key="row.id || JSON.stringify(row)">
                    <td>{{ row.sku }}</td>
                    <td>{{ row.delivered_quantity }}</td>
                    <td>{{ row.accepted_quantity }}</td>
                    <td>{{ row.damaged_quantity }}</td>
                    <td>{{ row.rejected_quantity }}</td>
                    <td>{{ formatMoney(row.unit_cost) }}</td>
                    <td>{{ formatMoney(row.accepted_cost) }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </article>
        </div>
      </template>
    </div>
  </UiSectionCard>

  <UiConfirmDialog
    :open="confirmOpen"
    :title="`Record receiving — ${selected?.order?.po_number}`"
    :description="selected?.order?.supplier_name"
    confirm-label="Confirm receiving"
    :loading="submitting"
    loading-label="Saving"
    @confirm="confirmReceive"
    @cancel="cancelReceive"
  >
    <ul class="receiving-summary">
      <li v-for="line in pendingLines" :key="line.id">
        {{ line.sku }} — Delivered {{ line.delivered }}, Accepted {{ line.accepted }}<template v-if="line.damaged">, Damaged {{ line.damaged }}</template><template v-if="line.rejected">, Rejected {{ line.rejected }}</template>
      </li>
    </ul>
    <p v-if="error" class="form-error" role="alert">{{ error }}</p>
  </UiConfirmDialog>
</template>

<style scoped>
.filter-form {
  display: flex;
  flex-wrap: wrap;
  gap: 0.75rem;
  align-items: end;
}
.receiving-detail {
  display: flex;
  flex-direction: column;
  gap: var(--fm-space-5);
}
.receiving-detail__subtitle {
  margin: 0;
  color: var(--fm-color-text);
}
.table-scroll {
  overflow-x: auto;
}
.receiving-form {
  display: flex;
  flex-direction: column;
  gap: var(--fm-space-4);
}
.receiving-line {
  display: grid;
  grid-template-columns: minmax(220px, 2fr) repeat(5, minmax(100px, 1fr));
  align-items: end;
  gap: var(--fm-space-3);
  padding-bottom: var(--fm-space-3);
  border-bottom: 1px solid var(--fm-color-border);
}
.receiving-line__name {
  align-self: center;
}
.receiving-history-list {
  display: flex;
  flex-direction: column;
  gap: var(--fm-space-4);
}
.receiving-history h4 {
  margin-bottom: var(--fm-space-2);
}
.receiving-summary {
  margin: 0;
  padding-left: 1.1rem;
  display: flex;
  flex-direction: column;
  gap: var(--fm-space-1);
}
@media (max-width: 900px) {
  .receiving-line {
    grid-template-columns: 1fr;
  }
}
</style>
