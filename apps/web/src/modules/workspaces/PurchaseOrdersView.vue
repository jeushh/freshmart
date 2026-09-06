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
  UiSelect,
  UiStatusBadge,
  UiTableShell
} from '../../components/ui/index.js'

const orders = ref([])
const suppliers = ref([])
const products = ref([])
const restockRequests = ref([])
const selected = ref(null)
const filters = ref({ approval_status: '', status: '', supplier_status: '', supplier_id: '', search: '' })
const listError = ref('')
const formError = ref('')
const reviewError = ref('')
const cancelError = ref('')
const sendError = ref('')
const responseError = ref('')
const message = ref('')
const loading = ref(true)
const creating = ref(false)
const submitting = ref(false)

const review = ref(null)
const reviewNote = ref('')
const cancelDialogOpen = ref(false)
const cancelNote = ref('')
const sendDialogOpen = ref(false)

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
const formTitle = computed(() => (form.value.id ? 'Edit purchase order' : 'New purchase order'))
const formDescription = computed(() => (form.value.id
  ? 'Update this draft purchase order before submitting it for approval.'
  : 'Create a draft purchase order for a supplier, optionally sourced from an approved restock request.'))
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

async function load(refreshSelected = false) {
  loading.value = true
  try {
    listError.value = ''
    await sessionStore.refreshSettings()
    const params = Object.fromEntries(Object.entries(filters.value).filter(([, value]) => value))
    const data = await api.get('/purchase-orders', { ...params, per_page: 100 })
    orders.value = data.orders.data
    suppliers.value = data.suppliers
    products.value = data.products
    restockRequests.value = data.approved_restock_requests
    if (refreshSelected && selected.value) await open(selected.value.order.id)
  } catch (requestError) {
    listError.value = requestError.message
  } finally {
    loading.value = false
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
  creating.value = true
  try {
    formError.value = ''
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
    formError.value = requestError.message
  } finally {
    creating.value = false
  }
}

async function open(id) {
  try {
    formError.value = ''
    responseForm.value.open = false
    selected.value = await api.get(`/purchase-orders/${id}`)
  } catch (requestError) {
    formError.value = requestError.message
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

async function transition(action, payload = {}, errorRef = reviewError) {
  try {
    errorRef.value = ''
    message.value = ''
    const id = selected.value.order.id
    const data = await api.post(`/purchase-orders/${id}/${action}`, payload)
    selected.value = data
    message.value = `Purchase order ${action} completed.`
    await load()
    return true
  } catch (requestError) {
    errorRef.value = requestError.message
    return false
  }
}

function startReview(decision) {
  if (!selected.value) return
  review.value = { decision, summary: `${selected.value.order.po_number} — ${selected.value.order.supplier_name}` }
  reviewNote.value = ''
}

function dismissReview() {
  review.value = null
  reviewNote.value = ''
}

async function confirmReview() {
  if (!review.value) return
  submitting.value = true
  const ok = await transition('review', { decision: review.value.decision, notes: reviewNote.value.trim() || null })
  submitting.value = false
  if (ok) dismissReview()
}

function startCancelOrder() {
  if (!selected.value) return
  cancelDialogOpen.value = true
  cancelNote.value = ''
}

function dismissCancelOrder() {
  cancelDialogOpen.value = false
  cancelNote.value = ''
}

async function confirmCancelOrder() {
  submitting.value = true
  const ok = await transition('cancel', { notes: cancelNote.value.trim() || null }, cancelError)
  submitting.value = false
  if (ok) dismissCancelOrder()
}

function startSend() {
  if (!selected.value) return
  sendDialogOpen.value = true
}

async function confirmSend() {
  submitting.value = true
  const ok = await transition('send', {}, sendError)
  submitting.value = false
  if (ok) sendDialogOpen.value = false
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
  submitting.value = true
  try {
    responseError.value = ''
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
    responseError.value = requestError.message
  } finally {
    submitting.value = false
  }
}

onMounted(load)
</script>

<template>
  <UiPageHeader title="Purchase Orders" description="Create, approve, and track supplier orders." />
  <p v-if="formError" class="form-error" role="alert">{{ formError }}</p>
  <p v-if="message" class="success-message">{{ message }}</p>

  <UiSectionCard
    v-if="sessionStore.can('procurement.purchase_orders.manage')"
    :title="formTitle"
    :description="formDescription"
  >
    <form class="po-form" @submit.prevent="save">
      <div class="po-form__fields">
        <UiSelect v-model="form.supplier_id" label="Supplier" required>
          <option value="">Select supplier</option>
          <option v-for="supplier in suppliers" :key="supplier.id" :value="supplier.id">{{ supplier.name }}</option>
        </UiSelect>
        <UiSelect v-model="form.restock_request_id" label="Approved restock request" @change="useRestock">
          <option :value="null">None</option>
          <option v-for="restock in restockRequests" :key="restock.id" :value="restock.id">
            {{ restock.ref_number }} — {{ restock.product_name }}
          </option>
        </UiSelect>
        <UiInput v-model="form.expected_delivery_date" type="date" label="Expected delivery" />
        <label class="ui-field po-form__span">
          <span class="ui-field__label">Notes</span>
          <textarea v-model.trim="form.notes" class="ui-field-control" rows="2" maxlength="1000"></textarea>
        </label>
      </div>

      <h3 class="po-form__subheading">Line items</h3>
      <div v-for="(line, index) in form.items" :key="index" class="po-line-item">
        <UiSelect v-model="line.product_id" label="Product" required @change="setProduct(line)">
          <option value="">Select product</option>
          <option v-for="product in availableProducts" :key="product.id" :value="product.id">
            {{ product.sku }} — {{ product.name }}
          </option>
        </UiSelect>
        <UiInput v-model.number="line.quantity" type="number" min="1" label="Quantity" required />
        <UiInput v-model.number="line.unit_cost" type="number" min="0" step=".01" label="Unit cost" required />
        <div class="po-line-item__total">
          <span class="ui-field__label">Line total</span>
          <strong>{{ formatMoney(Number(line.quantity || 0) * Number(line.unit_cost || 0)) }}</strong>
        </div>
        <UiButton
          type="button"
          variant="secondary"
          size="sm"
          :disabled="form.items.length <= 1"
          @click="removeLine(index)"
        >Remove</UiButton>
      </div>

      <div class="form-actions">
        <UiButton type="button" variant="secondary" @click="addLine">Add line</UiButton>
        <strong>Display total: {{ formatMoney(formTotal) }}</strong>
        <UiButton type="submit" :loading="creating" loading-label="Saving">
          {{ form.id ? 'Update draft' : 'Create draft' }}
        </UiButton>
        <UiButton v-if="form.id" type="button" variant="secondary" @click="form = blankForm()">Cancel edit</UiButton>
      </div>
    </form>
  </UiSectionCard>

  <UiTableShell
    title="Purchase orders"
    :loading="loading"
    :error="listError"
    :empty="!loading && !listError && !orders.length"
    empty-title="No purchase orders found"
    empty-description="Try different filters, or create a purchase order above."
    @retry="load"
  >
    <template #toolbar>
      <form class="filter-form" @submit.prevent="load">
        <UiInput v-model.trim="filters.search" label="Search" size="sm" placeholder="PO number or supplier" />
        <UiSelect v-model="filters.approval_status" label="Approval" size="sm">
          <option value="">All approval states</option>
          <option>Draft</option>
          <option>Submitted</option>
          <option>Approved</option>
          <option>Rejected</option>
          <option>Cancelled</option>
        </UiSelect>
        <UiSelect v-model="filters.supplier_status" label="Supplier status" size="sm">
          <option value="">All supplier statuses</option>
          <option value="Not Sent">Not Sent</option>
          <option value="Sent">Sent</option>
          <option value="Accepted">Accepted</option>
          <option value="Rejected">Rejected</option>
        </UiSelect>
        <UiSelect v-model="filters.status" label="Receiving" size="sm">
          <option value="">All receiving states</option>
          <option>Pending</option>
          <option>Approved</option>
          <option>Ordered</option>
          <option>Partially Received</option>
          <option>Fully Received</option>
          <option>Cancelled</option>
        </UiSelect>
        <UiButton type="submit" variant="secondary" size="sm">Apply filters</UiButton>
      </form>
    </template>
    <table>
      <thead>
        <tr>
          <th>PO number</th>
          <th>Supplier</th>
          <th>Total</th>
          <th>Approval</th>
          <th>Supplier status</th>
          <th>Receiving</th>
          <th>Fulfillment</th>
          <th>Expected</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="row in orders" :key="row.id">
          <td>{{ row.po_number }}</td>
          <td>{{ row.supplier_name }}</td>
          <td>{{ formatMoney(row.total_amount) }}</td>
          <td><UiStatusBadge :status="row.approval_status" /></td>
          <td>
            <UiStatusBadge v-if="row.supplier_status" :status="row.supplier_status" />
            <span v-else class="field-help">Historical — not tracked</span>
          </td>
          <td><UiStatusBadge :status="row.status" /></td>
          <td>{{ row.total_fulfilled }} / {{ row.total_ordered }}</td>
          <td>{{ row.expected_delivery_date }}</td>
          <td><UiButton size="sm" @click="open(row.id)">Details</UiButton></td>
        </tr>
      </tbody>
    </table>
  </UiTableShell>

  <UiSectionCard
    v-if="selected"
    :title="selected.order.po_number"
    :description="`${selected.order.supplier_name} · Total ${formatMoney(selected.order.total_amount)}`"
  >
    <p class="field-help">
      Approval: <strong>{{ selected.order.approval_status }}</strong>
      · Supplier: <strong>{{ selected.order.supplier_status || 'Historical — not tracked' }}</strong>
      <span v-if="selected.order.sent_by"> · Sent by {{ selected.order.sent_by }} at {{ selected.order.sent_to_supplier_at }}</span>
      <span v-if="selected.order.supplier_responded_at"> · Responded at {{ selected.order.supplier_responded_at }}</span>
      <span v-if="selected.order.supplier_reference"> · Ref #{{ selected.order.supplier_reference }}</span>
    </p>
    <p v-if="selected.order.supplier_response_notes" class="field-help">
      Response notes: {{ selected.order.supplier_response_notes }}
    </p>

    <div class="form-actions">
      <UiButton
        v-if="sessionStore.can('procurement.purchase_orders.manage') && selected.order.approval_status === 'Draft'"
        variant="secondary"
        @click="editSelected"
      >Edit</UiButton>
      <UiButton
        v-if="sessionStore.can('procurement.purchase_orders.manage') && selected.order.approval_status === 'Draft'"
        @click="transition('submit')"
      >Submit</UiButton>
      <UiButton
        v-if="sessionStore.can('procurement.purchase_orders.approve') && selected.order.approval_status === 'Submitted'"
        @click="startReview('Approved')"
      >Approve</UiButton>
      <UiButton
        v-if="sessionStore.can('procurement.purchase_orders.approve') && selected.order.approval_status === 'Submitted'"
        variant="destructive"
        @click="startReview('Rejected')"
      >Reject</UiButton>
      <UiButton v-if="canMarkSent" @click="startSend">Mark Sent to Supplier</UiButton>
      <UiButton v-if="canRecordResponse" @click="openResponseModal">Record Supplier Response</UiButton>
      <UiButton v-if="canCancelSelected" variant="destructive" @click="startCancelOrder">Cancel</UiButton>
    </div>

    <div class="table-scroll">
      <table>
        <thead>
          <tr>
            <th>SKU</th>
            <th>Product</th>
            <th>Ordered</th>
            <th>Fulfilled</th>
            <th>Outstanding</th>
            <th>Current stock</th>
            <th>Unit cost</th>
            <th>Line total</th>
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
            <td>{{ formatMoney(item.line_total) }}</td>
          </tr>
        </tbody>
      </table>
    </div>
  </UiSectionCard>

  <UiConfirmDialog
    :open="!!review"
    :title="`${review?.decision} purchase order`"
    :description="review?.summary"
    :confirm-label="`Confirm ${review?.decision}`"
    :destructive="review?.decision === 'Rejected'"
    :loading="submitting"
    loading-label="Saving"
    @confirm="confirmReview"
    @cancel="dismissReview"
  >
    <UiInput
      v-model="reviewNote"
      label="Review note"
      maxlength="500"
      hint="Optional. This note will be recorded with this action."
    />
    <p v-if="reviewError" class="form-error" role="alert">{{ reviewError }}</p>
  </UiConfirmDialog>

  <UiConfirmDialog
    :open="cancelDialogOpen"
    title="Cancel purchase order"
    :description="selected ? `${selected.order.po_number} — ${selected.order.supplier_name}` : ''"
    confirm-label="Confirm cancel"
    destructive
    :loading="submitting"
    loading-label="Saving"
    @confirm="confirmCancelOrder"
    @cancel="dismissCancelOrder"
  >
    <UiInput
      v-model="cancelNote"
      label="Cancellation note"
      maxlength="500"
      hint="Optional. This note will be recorded with this action."
    />
    <p v-if="cancelError" class="form-error" role="alert">{{ cancelError }}</p>
  </UiConfirmDialog>

  <UiConfirmDialog
    :open="sendDialogOpen"
    title="Mark sent to supplier"
    :description="selected ? `${selected.order.po_number} — ${selected.order.supplier_name}` : ''"
    confirm-label="Confirm send"
    :loading="submitting"
    loading-label="Saving"
    @confirm="confirmSend"
    @cancel="sendDialogOpen = false"
  >
    <p class="field-help">This marks the order as sent to the supplier. This can't be undone from here.</p>
    <p v-if="sendError" class="form-error" role="alert">{{ sendError }}</p>
  </UiConfirmDialog>

  <UiConfirmDialog
    :open="responseForm.open"
    title="Record supplier response"
    :description="selected ? `${selected.order.po_number} — ${selected.order.supplier_name}` : ''"
    confirm-label="Save response"
    :loading="submitting"
    loading-label="Saving"
    @confirm="submitResponse"
    @cancel="responseForm.open = false"
  >
    <UiSelect v-model="responseForm.response" label="Decision" required>
      <option value="Accepted">Accepted</option>
      <option value="Rejected">Rejected</option>
    </UiSelect>
    <UiInput
      v-model.trim="responseForm.supplier_reference"
      label="Supplier reference #"
      maxlength="100"
      placeholder="e.g. SO-98765"
    />
    <UiInput v-model="responseForm.expected_delivery_date" type="date" label="Expected delivery date" />
    <label class="ui-field">
      <span class="ui-field__label">Response notes</span>
      <textarea
        v-model.trim="responseForm.notes"
        class="ui-field-control"
        rows="2"
        maxlength="1000"
        placeholder="Vendor communication notes..."
      ></textarea>
    </label>
    <p v-if="responseError" class="form-error" role="alert">{{ responseError }}</p>
  </UiConfirmDialog>
</template>

<style scoped>
.po-form {
  display: flex;
  flex-direction: column;
  gap: var(--fm-space-4);
}
.po-form__fields {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(min(100%, 14rem), 1fr));
  gap: var(--fm-space-5);
}
.po-form__span {
  grid-column: 1 / -1;
}
.po-form__subheading {
  margin: var(--fm-space-2) 0 0;
}
.po-line-item {
  display: grid;
  grid-template-columns: minmax(220px, 2fr) repeat(2, minmax(110px, 1fr)) minmax(110px, auto) auto;
  align-items: end;
  gap: var(--fm-space-4);
  padding: var(--fm-space-3) 0;
  border-bottom: 1px solid var(--fm-color-border);
}
.po-line-item__total {
  display: grid;
  gap: 6px;
}
.filter-form {
  display: flex;
  flex-wrap: wrap;
  gap: 0.75rem;
  align-items: end;
}
@media (max-width: 900px) {
  .po-form__fields,
  .po-line-item {
    grid-template-columns: 1fr;
  }
  .po-form__span {
    grid-column: auto;
  }
}
</style>
