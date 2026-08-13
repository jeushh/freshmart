<script setup>
import { computed, onMounted, ref } from 'vue'
import { api } from '../../api/http.js'
import { sessionStore } from '../../stores/session.js'
import PageHeader from '../../components/common/PageHeader.vue'
import WorkspaceTable from '../../components/common/WorkspaceTable.vue'
import { UiStatusBadge } from '../../components/ui/index.js'
import { formatMoney } from '../../utils/formatters.js'

const requests = ref([])
const transactions = ref([])
const purchaseOrders = ref([])
const invoices = ref([])
const payables = ref([])
const error = ref('')

const selectedPurchaseOrder = ref(null)
const selectedInvoice = ref(null)
const selectedInvoicePo = ref(null)
const selectedPayable = ref(null)
const paymentHistory = ref([])
const paymentSubmitting = ref(false)
const paymentAttemptKey = ref(null)

function localDate() {
  const now = new Date()
  const year = now.getFullYear()
  const month = String(now.getMonth() + 1).padStart(2, '0')
  const day = String(now.getDate()).padStart(2, '0')

  return `${year}-${month}-${day}`
}

const paymentForm = ref({
  amount: '',
  payment_method: 'Bank Transfer',
  reference_number: '',
  payment_date: localDate(),
  notes: '',
})

const invoiceFilters = ref({
  status: '',
  supplier_id: '',
})

const createForm = ref({
  purchase_order_id: '',
  invoice_number: '',
  invoice_date: '',
  due_date: '',
  notes: '',
  items: [],
})

const editForm = ref({
  invoice_number: '',
  invoice_date: '',
  due_date: '',
  notes: '',
  items: [],
})

const supplierOptions = computed(() => {
  const suppliers = new Map()

  for (const row of [...purchaseOrders.value, ...invoices.value]) {
    if (row.supplier_id && row.supplier_name) {
      suppliers.set(String(row.supplier_id), row.supplier_name)
    }
  }

  return [...suppliers.entries()]
    .map(([id, name]) => ({ id, name }))
    .sort((a, b) => a.name.localeCompare(b.name))
})

const filteredInvoices = computed(() => {
  return invoices.value.filter(row => {
    if (invoiceFilters.value.status && row.status !== invoiceFilters.value.status) {
      return false
    }

    if (
      invoiceFilters.value.supplier_id
      && String(row.supplier_id) !== String(invoiceFilters.value.supplier_id)
    ) {
      return false
    }

    return true
  })
})

const selectedInvoiceLines = computed(() => {
  if (!selectedInvoice.value) {
    return []
  }

  return selectedInvoice.value.items.map(item => {
    const poItem = selectedInvoicePo.value?.items?.find(
      row => Number(row.purchase_order_item_id) === Number(item.purchase_order_item_id),
    )

    return {
      ...item,
      quantity_ordered: poItem?.quantity_ordered ?? null,
      accepted_received_qty: poItem?.accepted_received_qty ?? null,
      committed_invoice_qty: poItem?.committed_invoice_qty ?? null,
      remaining_invoiceable_qty: poItem?.remaining_invoiceable_qty ?? null,
    }
  })
})

const selectedInvoiceTotal = computed(() => {
  return selectedInvoice.value?.items?.reduce(
    (total, item) => total + Number(item.line_total || 0),
    0,
  ) ?? 0
})

const createEstimatedTotal = computed(() => {
  return createForm.value.items.reduce((total, item) => {
    return total + Number(item.invoiced_quantity || 0) * Number(item.unit_cost || 0)
  }, 0)
})

const selectedInvoiceEditable = computed(() => {
  return ['Draft', 'Disputed'].includes(selectedInvoice.value?.invoice?.status)
})

async function loadSupplierFinance() {
  const [orderData, invoiceData, payableData] = await Promise.all([
    api.get('/finance/purchase-orders', { per_page: 100 }),
    api.get('/supplier-invoices', { per_page: 100 }),
    api.get('/accounts-payable', { per_page: 100 }),
  ])

  purchaseOrders.value = orderData.orders.data
  invoices.value = invoiceData.invoices.data
  payables.value = payableData.payables.data
}

async function load() {
  try {
    error.value = ''
    await sessionStore.refreshSettings()

    const pending = []

    if (sessionStore.can('finance.requests.view')) {
      pending.push(
        api.get('/workspace/finance/requests', { per_page: 100 })
          .then(data => { requests.value = data.requests.data }),
      )
    }

    if (sessionStore.can('finance.manage')) {
      pending.push(
        api.get('/workspace/finance/overview')
          .then(data => { transactions.value = data.transactions }),
      )

      pending.push(loadSupplierFinance())
    }

    await Promise.all(pending)
  } catch (requestError) {
    error.value = requestError.message
  }
}

async function decide(id, decision) {
  try {
    error.value = ''
    await api.post(`/workspace/finance/requests/${id}/review`, { decision })
    await load()
  } catch (requestError) {
    error.value = requestError.message
  }
}

async function selectPurchaseOrder(id) {
  try {
    error.value = ''
    selectedPurchaseOrder.value = null
    createForm.value.items = []

    if (!id) {
      return
    }

    const data = await api.get(`/finance/purchase-orders/${id}`)
    selectedPurchaseOrder.value = data

    createForm.value.items = data.items.map(item => ({
      purchase_order_item_id: item.purchase_order_item_id,
      invoiced_quantity: Number(item.remaining_invoiceable_qty) > 0 ? 1 : 0,
      unit_cost: Number(item.po_unit_cost),
    }))
  } catch (requestError) {
    error.value = requestError.message
  }
}

function purchaseOrderLine(id) {
  return selectedPurchaseOrder.value?.items?.find(
    item => Number(item.purchase_order_item_id) === Number(id),
  )
}

function selectedPoLine(id) {
  return selectedInvoicePo.value?.items?.find(
    item => Number(item.purchase_order_item_id) === Number(id),
  )
}

function lineTotal(line) {
  return Number(line.invoiced_quantity || 0) * Number(line.unit_cost || 0)
}

function lineVariance(line) {
  const poLine = purchaseOrderLine(line.purchase_order_item_id)
  return Number(line.unit_cost || 0) - Number(poLine?.po_unit_cost || 0)
}

function editLineVariance(line) {
  const poLine = selectedPoLine(line.purchase_order_item_id)
  return Number(line.unit_cost || 0) - Number(poLine?.po_unit_cost || 0)
}

function editMaxQuantity(line) {
  const poLine = selectedPoLine(line.purchase_order_item_id)

  if (!poLine) {
    return undefined
  }

  let ownReservedQuantity = 0

  if (selectedInvoice.value?.invoice?.status === 'Disputed') {
    const original = selectedInvoice.value.items.find(
      item => Number(item.purchase_order_item_id) === Number(line.purchase_order_item_id),
    )

    ownReservedQuantity = Number(original?.invoiced_quantity || 0)
  }

  return Number(poLine.remaining_invoiceable_qty || 0) + ownReservedQuantity
}

async function createInvoice(registerNow = false) {
  try {
    error.value = ''

    if (!createForm.value.purchase_order_id) {
      error.value = 'Select an eligible purchase order.'
      return
    }

    const invoiceNumber = createForm.value.invoice_number.trim()

    if (registerNow && !invoiceNumber) {
      error.value = 'Invoice number is required before registration.'
      return
    }

    const items = createForm.value.items
      .filter(item => Number(item.invoiced_quantity) > 0)
      .map(item => ({
        purchase_order_item_id: Number(item.purchase_order_item_id),
        invoiced_quantity: Number(item.invoiced_quantity),
        unit_cost: Number(item.unit_cost),
      }))

    if (items.length === 0) {
      error.value = 'Enter an invoiced quantity for at least one PO line.'
      return
    }

    const created = await api.post(
      `/purchase-orders/${createForm.value.purchase_order_id}/invoices`,
      {
        invoice_number: invoiceNumber || null,
        invoice_date: createForm.value.invoice_date || null,
        due_date: createForm.value.due_date || null,
        notes: createForm.value.notes.trim() || null,
        items,
      },
    )

    const invoiceId = Number(created.invoice.id)

    if (registerNow) {
      await api.post(`/supplier-invoices/${invoiceId}/register`)
    }

    await loadSupplierFinance()
    await openInvoice(invoiceId)

    createForm.value.invoice_number = ''
    createForm.value.invoice_date = ''
    createForm.value.due_date = ''
    createForm.value.notes = ''

    await selectPurchaseOrder(createForm.value.purchase_order_id)
  } catch (requestError) {
    error.value = requestError.message
  }
}

async function openInvoice(id) {
  try {
    error.value = ''

    const invoiceData = await api.get(`/supplier-invoices/${id}`)

    let poData = null
    try {
      poData = await api.get(
        `/finance/purchase-orders/${invoiceData.invoice.purchase_order_id}`,
      )
    } catch {
      // Historical or void invoices remain viewable even when their PO is no
      // longer currently eligible for new structured invoicing.
      poData = null
    }

    selectedInvoice.value = invoiceData
    selectedInvoicePo.value = poData

    editForm.value = {
      invoice_number: invoiceData.invoice.invoice_number ?? '',
      invoice_date: invoiceData.invoice.invoice_date ?? '',
      due_date: invoiceData.invoice.due_date ?? '',
      notes: invoiceData.invoice.notes ?? '',
      items: invoiceData.items.map(item => ({
        purchase_order_item_id: item.purchase_order_item_id,
        invoiced_quantity: Number(item.invoiced_quantity),
        unit_cost: Number(item.unit_cost),
      })),
    }
  } catch (requestError) {
    error.value = requestError.message
  }
}

async function saveInvoiceChanges() {
  try {
    error.value = ''

    if (!selectedInvoiceEditable.value) {
      return
    }

    const invoiceId = selectedInvoice.value.invoice.id

    await api.put(`/supplier-invoices/${invoiceId}`, {
      invoice_number: editForm.value.invoice_number.trim() || null,
      invoice_date: editForm.value.invoice_date || null,
      due_date: editForm.value.due_date || null,
      notes: editForm.value.notes.trim() || null,
      items: editForm.value.items.map(item => ({
        purchase_order_item_id: Number(item.purchase_order_item_id),
        invoiced_quantity: Number(item.invoiced_quantity),
        unit_cost: Number(item.unit_cost),
      })),
    })

    await loadSupplierFinance()
    await openInvoice(invoiceId)
  } catch (requestError) {
    error.value = requestError.message
  }
}

async function transitionInvoice(id, action) {
  try {
    error.value = ''

    await api.post(`/supplier-invoices/${id}/${action}`)

    await loadSupplierFinance()
    await openInvoice(id)

    if (createForm.value.purchase_order_id) {
      await selectPurchaseOrder(createForm.value.purchase_order_id)
    }
  } catch (requestError) {
    error.value = requestError.message
  }
}

function newIdempotencyKey() {
  if (globalThis.crypto?.randomUUID) {
    return globalThis.crypto.randomUUID()
  }

  return `supplier-payment-${Date.now()}-${Math.random().toString(36).slice(2)}`
}

function resetPaymentAttempt() {
  paymentAttemptKey.value = null
  paymentForm.value = {
    amount: selectedPayable.value
      ? Number(selectedPayable.value.outstanding_balance).toFixed(2)
      : '',
    payment_method: 'Bank Transfer',
    reference_number: '',
    payment_date: localDate(),
    notes: '',
  }
}

function procurementCloseTone(status) {
  if (status === 'Complete') {
    return 'success'
  }
  if (status === 'Open — Awaiting Delivery') {
    return 'info'
  }

  return 'warning'
}

async function openPayable(id) {
  try {
    error.value = ''
    const [payableData, historyData] = await Promise.all([
      api.get(`/accounts-payable/${id}`),
      api.get(`/accounts-payable/${id}/payments`),
    ])
    selectedPayable.value = payableData.payable
    paymentHistory.value = historyData.payments
    resetPaymentAttempt()
  } catch (requestError) {
    error.value = requestError.message
  }
}

async function recordSupplierPayment() {
  if (!selectedPayable.value || paymentSubmitting.value) {
    return
  }

  paymentAttemptKey.value ||= newIdempotencyKey()
  paymentSubmitting.value = true
  error.value = ''

  try {
    const payableId = selectedPayable.value.id
    await api.post(
      `/accounts-payable/${selectedPayable.value.id}/payments`,
      {
        amount: Number(paymentForm.value.amount),
        payment_method: paymentForm.value.payment_method,
        reference_number: paymentForm.value.reference_number.trim() || null,
        payment_date: paymentForm.value.payment_date,
        notes: paymentForm.value.notes.trim() || null,
        idempotency_key: paymentAttemptKey.value,
      },
    )

    const [payableData, historyData, overviewData] = await Promise.all([
      api.get(`/accounts-payable/${payableId}`),
      api.get(`/accounts-payable/${payableId}/payments`),
      api.get('/workspace/finance/overview'),
      loadSupplierFinance(),
    ])
    selectedPayable.value = payableData.payable
    paymentHistory.value = historyData.payments
    transactions.value = overviewData.transactions
    resetPaymentAttempt()
  } catch (requestError) {
    // Preserve the key so a retry after a timeout or unknown response remains idempotent.
    error.value = requestError.message
  } finally {
    paymentSubmitting.value = false
  }
}

onMounted(load)
</script>

<template>
  <PageHeader
    title="Finance"
    description="Review requests and track cash movement."
  />

  <p v-if="error" class="form-error">{{ error }}</p>

  <template v-if="sessionStore.can('finance.requests.view')">
    <h2>Finance requests</h2>

    <WorkspaceTable
      :columns="[
        { key: 'full_name', label: 'Employee' },
        { key: 'request_type', label: 'Type' },
        { key: 'amount', label: 'Amount' },
        { key: 'description', label: 'Description' },
        { key: 'status', label: 'Status' }
      ]"
      :rows="requests"
    >
      <template #cell-amount="{ row }">
        {{ formatMoney(row.amount) }}
      </template>

      <template
        v-if="sessionStore.can('finance.requests.approve')"
        #actions="{ row }"
      >
        <button
          v-if="row.status === 'Pending'"
          @click="decide(row.id, 'Approved')"
        >
          Approve
        </button>

        <button
          v-if="row.status === 'Pending'"
          @click="decide(row.id, 'Rejected')"
        >
          Reject
        </button>

        <button
          v-if="row.status === 'Approved'"
          @click="decide(row.id, 'Paid')"
        >
          Pay
        </button>
      </template>
    </WorkspaceTable>
  </template>

  <template v-if="sessionStore.can('finance.manage')">
    <h2 class="section-title">Recent transactions</h2>

    <WorkspaceTable
      :columns="[
        { key: 'created_at', label: 'Date' },
        { key: 'transaction_type', label: 'Type' },
        { key: 'description', label: 'Description' },
        { key: 'direction', label: 'Direction' },
        { key: 'amount', label: 'Amount' }
      ]"
      :rows="transactions"
    >
      <template #cell-amount="{ row }">
        {{ formatMoney(row.amount) }}
      </template>
    </WorkspaceTable>

    <h2 class="section-title">Supplier invoices</h2>

    <div class="inline-form invoice-filters">
      <select v-model="invoiceFilters.status">
        <option value="">All statuses</option>
        <option>Draft</option>
        <option>Registered</option>
        <option>Approved</option>
        <option>Disputed</option>
        <option>Void</option>
      </select>

      <select v-model="invoiceFilters.supplier_id">
        <option value="">All suppliers</option>
        <option
          v-for="supplier in supplierOptions"
          :key="supplier.id"
          :value="supplier.id"
        >
          {{ supplier.name }}
        </option>
      </select>
    </div>

    <section class="detail-panel finance-subsection">
      <h3>Create supplier invoice</h3>

      <form @submit.prevent="createInvoice(false)">
        <div class="form-grid">
          <label>
            Eligible purchase order
            <select
              v-model="createForm.purchase_order_id"
              required
              @change="selectPurchaseOrder(createForm.purchase_order_id)"
            >
              <option value="">Select PO</option>
              <option
                v-for="order in purchaseOrders"
                :key="order.id"
                :value="order.id"
              >
                {{ order.po_number }} — {{ order.supplier_name }} — {{ order.status }}
              </option>
            </select>
          </label>

          <label>
            Invoice number
            <input
              v-model.trim="createForm.invoice_number"
              maxlength="100"
              placeholder="Supplier invoice number"
            >
          </label>

          <label>
            Invoice date
            <input v-model="createForm.invoice_date" type="date">
          </label>

          <label>
            Due date
            <input v-model="createForm.due_date" type="date">
          </label>

          <label>
            Notes
            <input
              v-model.trim="createForm.notes"
              maxlength="1000"
              placeholder="Optional notes"
            >
          </label>
        </div>

        <template v-if="selectedPurchaseOrder">
          <p class="finance-summary">
            <strong>Supplier:</strong>
            {{ selectedPurchaseOrder.order.supplier_name }}
            ·
            <strong>PO:</strong>
            {{ selectedPurchaseOrder.order.po_number }}
            ·
            <strong>Status:</strong>
            {{ selectedPurchaseOrder.order.status }}
          </p>

          <div class="invoice-lines-editor">
            <div
              v-for="line in createForm.items"
              :key="line.purchase_order_item_id"
              class="invoice-line-editor"
            >
              <template v-if="purchaseOrderLine(line.purchase_order_item_id)">
                <strong>
                  {{ purchaseOrderLine(line.purchase_order_item_id).sku }}
                  —
                  {{ purchaseOrderLine(line.purchase_order_item_id).product_name }}
                </strong>

                <span>
                  Ordered:
                  {{ purchaseOrderLine(line.purchase_order_item_id).quantity_ordered }}
                </span>

                <span>
                  Accepted:
                  {{ purchaseOrderLine(line.purchase_order_item_id).accepted_received_qty }}
                </span>

                <span>
                  Committed:
                  {{ purchaseOrderLine(line.purchase_order_item_id).committed_invoice_qty }}
                </span>

                <span>
                  Remaining:
                  {{ purchaseOrderLine(line.purchase_order_item_id).remaining_invoiceable_qty }}
                </span>

                <span>
                  PO cost:
                  {{ formatMoney(purchaseOrderLine(line.purchase_order_item_id).po_unit_cost) }}
                </span>

                <label>
                  Invoice quantity
                  <input
                    v-model.number="line.invoiced_quantity"
                    type="number"
                    min="0"
                    :max="purchaseOrderLine(line.purchase_order_item_id).remaining_invoiceable_qty"
                  >
                </label>

                <label>
                  Invoice unit cost
                  <input
                    v-model.number="line.unit_cost"
                    type="number"
                    min="0"
                    step=".01"
                  >
                </label>

                <span>
                  Variance:
                  {{ formatMoney(lineVariance(line)) }}
                </span>

                <span>
                  Line total:
                  {{ formatMoney(lineTotal(line)) }}
                </span>
              </template>
            </div>
          </div>

          <p class="finance-summary">
            <strong>Estimated invoice total:</strong>
            {{ formatMoney(createEstimatedTotal) }}
          </p>
        </template>

        <div class="action-row">
          <button
            class="primary-button"
            type="submit"
            :disabled="!createForm.purchase_order_id"
          >
            Save draft
          </button>

          <button
            type="button"
            :disabled="!createForm.purchase_order_id"
            @click="createInvoice(true)"
          >
            Create &amp; register
          </button>
        </div>
      </form>
    </section>

    <WorkspaceTable
      :columns="[
        { key: 'supplier_name', label: 'Supplier' },
        { key: 'po_number', label: 'PO' },
        { key: 'invoice_number', label: 'Invoice' },
        { key: 'invoice_date', label: 'Invoice date' },
        { key: 'due_date', label: 'Due date' },
        { key: 'status', label: 'Status' }
      ]"
      :rows="filteredInvoices"
    >
      <template #cell-invoice_number="{ row }">
        {{ row.invoice_number || 'Draft — no number' }}
      </template>

      <template #actions="{ row }">
        <button @click="openInvoice(row.id)">
          View
        </button>

        <button
          v-if="row.status === 'Draft'"
          @click="transitionInvoice(row.id, 'register')"
        >
          Register
        </button>

        <button
          v-if="row.status === 'Registered'"
          @click="transitionInvoice(row.id, 'approve')"
        >
          Approve
        </button>

        <button
          v-if="row.status === 'Registered'"
          @click="transitionInvoice(row.id, 'dispute')"
        >
          Dispute
        </button>

        <button
          v-if="row.status === 'Disputed'"
          @click="transitionInvoice(row.id, 'resolve-dispute')"
        >
          Resolve dispute
        </button>

        <button
          v-if="['Draft', 'Registered', 'Disputed'].includes(row.status)"
          @click="transitionInvoice(row.id, 'void')"
        >
          Void
        </button>
      </template>
    </WorkspaceTable>

    <section
      v-if="selectedInvoice"
      class="detail-panel finance-subsection"
    >
      <h3>Supplier invoice detail</h3>

      <p class="finance-summary">
        <strong>Supplier:</strong>
        {{ selectedInvoice.invoice.supplier_name }}
        ·
        <strong>PO:</strong>
        {{ selectedInvoice.invoice.po_number }}
        ·
        <strong>Invoice:</strong>
        {{ selectedInvoice.invoice.invoice_number || 'Not assigned' }}
        ·
        <strong>Status:</strong>
        {{ selectedInvoice.invoice.status }}
        ·
        <strong>Total:</strong>
        {{ formatMoney(selectedInvoiceTotal) }}
      </p>

      <WorkspaceTable
        :columns="[
          { key: 'sku', label: 'SKU' },
          { key: 'quantity_ordered', label: 'Ordered' },
          { key: 'accepted_received_qty', label: 'Accepted' },
          { key: 'committed_invoice_qty', label: 'Committed' },
          { key: 'remaining_invoiceable_qty', label: 'Remaining' },
          { key: 'invoiced_quantity', label: 'Invoice qty' },
          { key: 'po_unit_cost', label: 'PO cost' },
          { key: 'unit_cost', label: 'Invoice cost' },
          { key: 'variance', label: 'Variance' },
          { key: 'line_total', label: 'Line total' }
        ]"
        :rows="selectedInvoiceLines"
      >
        <template #cell-po_unit_cost="{ row }">
          {{ formatMoney(row.po_unit_cost) }}
        </template>

        <template #cell-unit_cost="{ row }">
          {{ formatMoney(row.unit_cost) }}
        </template>

        <template #cell-variance="{ row }">
          {{ formatMoney(row.variance) }}
        </template>

        <template #cell-line_total="{ row }">
          {{ formatMoney(row.line_total) }}
        </template>
      </WorkspaceTable>

      <form
        v-if="selectedInvoiceEditable"
        class="finance-subsection"
        @submit.prevent="saveInvoiceChanges"
      >
        <h3>
          Edit {{ selectedInvoice.invoice.status.toLowerCase() }} invoice
        </h3>

        <div class="form-grid">
          <label>
            Invoice number
            <input
              v-model.trim="editForm.invoice_number"
              maxlength="100"
            >
          </label>

          <label>
            Invoice date
            <input v-model="editForm.invoice_date" type="date">
          </label>

          <label>
            Due date
            <input v-model="editForm.due_date" type="date">
          </label>

          <label>
            Notes
            <input
              v-model.trim="editForm.notes"
              maxlength="1000"
            >
          </label>
        </div>

        <div class="invoice-lines-editor">
          <div
            v-for="line in editForm.items"
            :key="line.purchase_order_item_id"
            class="invoice-line-editor"
          >
            <template v-if="selectedPoLine(line.purchase_order_item_id)">
              <strong>
                {{ selectedPoLine(line.purchase_order_item_id).sku }}
                —
                {{ selectedPoLine(line.purchase_order_item_id).product_name }}
              </strong>

              <span>
                Ordered:
                {{ selectedPoLine(line.purchase_order_item_id).quantity_ordered }}
              </span>

              <span>
                Accepted:
                {{ selectedPoLine(line.purchase_order_item_id).accepted_received_qty }}
              </span>

              <span>
                Committed:
                {{ selectedPoLine(line.purchase_order_item_id).committed_invoice_qty }}
              </span>

              <span>
                Remaining:
                {{ selectedPoLine(line.purchase_order_item_id).remaining_invoiceable_qty }}
              </span>

              <span>
                PO cost:
                {{ formatMoney(selectedPoLine(line.purchase_order_item_id).po_unit_cost) }}
              </span>

              <label>
                Invoice quantity
                <input
                  v-model.number="line.invoiced_quantity"
                  type="number"
                  min="1"
                  :max="editMaxQuantity(line)"
                  required
                >
              </label>

              <label>
                Invoice unit cost
                <input
                  v-model.number="line.unit_cost"
                  type="number"
                  min="0"
                  step=".01"
                  required
                >
              </label>

              <span>
                Variance:
                {{ formatMoney(editLineVariance(line)) }}
              </span>

              <span>
                Line total:
                {{ formatMoney(lineTotal(line)) }}
              </span>
            </template>
          </div>
        </div>

        <button class="primary-button" type="submit">
          Save invoice changes
        </button>
      </form>
    </section>

    <h2 class="section-title">Accounts payable</h2>

    <p>
      Record partial or full supplier settlements against the outstanding
      Accounts Payable balance.
    </p>

    <WorkspaceTable
      :columns="[
        { key: 'supplier_name', label: 'Supplier' },
        { key: 'po_number', label: 'PO' },
        { key: 'invoice_number', label: 'Invoice' },
        { key: 'source', label: 'Source' },
        { key: 'total_amount', label: 'Total' },
        { key: 'amount_paid', label: 'Paid' },
        { key: 'outstanding_balance', label: 'Outstanding' },
        { key: 'due_date', label: 'Due date' },
        { key: 'status', label: 'Status' }
      ]"
      :rows="payables"
    >
      <template #cell-invoice_number="{ row }">
        {{ row.invoice_number || '—' }}
      </template>

      <template #cell-source="{ row }">
        {{ row.source === 'structured' ? 'Structured' : 'Legacy' }}
      </template>

      <template #cell-total_amount="{ row }">
        {{ formatMoney(row.total_amount) }}
      </template>

      <template #cell-amount_paid="{ row }">
        {{ formatMoney(row.amount_paid) }}
      </template>

      <template #cell-outstanding_balance="{ row }">
        {{ formatMoney(row.outstanding_balance) }}
      </template>

      <template #cell-status="{ row }">
        {{ row.status }}<span v-if="row.overdue"> — Overdue</span>
      </template>

      <template #actions="{ row }">
        <button @click="openPayable(row.id)">
          {{ Number(row.outstanding_balance) > 0 ? 'Record payment' : 'View payments' }}
        </button>
      </template>
    </WorkspaceTable>

    <section
      v-if="selectedPayable"
      class="detail-panel finance-subsection"
    >
      <h3>Supplier payment settlement</h3>

      <p class="finance-summary">
        <strong>Supplier:</strong>
        {{ selectedPayable.supplier_name || 'Not assigned' }}
        ·
        <strong>PO:</strong>
        {{ selectedPayable.po_number || 'Not assigned' }}
        ·
        <strong>Invoice:</strong>
        {{ selectedPayable.invoice_number || 'Not assigned' }}
        ·
        <strong>Status:</strong>
        {{ selectedPayable.status }}
      </p>

      <div class="payment-balances">
        <span><strong>AP total:</strong> {{ formatMoney(selectedPayable.total_amount) }}</span>
        <span><strong>Already paid:</strong> {{ formatMoney(selectedPayable.amount_paid) }}</span>
        <span>
          <strong>Outstanding:</strong>
          {{ formatMoney(selectedPayable.outstanding_balance) }}
        </span>
      </div>

      <p class="procurement-close-status">
        <strong>Procurement close-out:</strong>
        <UiStatusBadge
          :status="selectedPayable.procurement_close_status"
          :tone="procurementCloseTone(selectedPayable.procurement_close_status)"
        />
      </p>

      <form
        v-if="Number(selectedPayable.outstanding_balance) > 0"
        class="finance-subsection"
        @submit.prevent="recordSupplierPayment"
      >
        <div class="form-grid">
          <label>
            Payment amount
            <input
              v-model="paymentForm.amount"
              type="number"
              min="0.01"
              :max="selectedPayable.outstanding_balance"
              step="0.01"
              required
            >
          </label>

          <label>
            Payment method
            <select v-model="paymentForm.payment_method" required>
              <option>Bank Transfer</option>
              <option>Check</option>
              <option>Cash</option>
              <option>Other</option>
            </select>
          </label>

          <label>
            Reference number
            <input
              v-model="paymentForm.reference_number"
              maxlength="255"
              placeholder="Optional reference"
            >
          </label>

          <label>
            Payment date
            <input v-model="paymentForm.payment_date" type="date" required>
          </label>

          <label>
            Notes
            <input
              v-model="paymentForm.notes"
              maxlength="1000"
              placeholder="Optional notes"
            >
          </label>
        </div>

        <div class="action-row">
          <button
            class="primary-button"
            type="submit"
            :disabled="paymentSubmitting || Number(paymentForm.amount) <= 0"
          >
            {{ paymentSubmitting ? 'Recording…' : 'Record supplier payment' }}
          </button>

          <button
            type="button"
            :disabled="paymentSubmitting"
            @click="resetPaymentAttempt"
          >
            Reset payment attempt
          </button>
        </div>
      </form>

      <h3 class="finance-subsection">Payment history</h3>

      <WorkspaceTable
        :columns="[
          { key: 'payment_date', label: 'Payment date' },
          { key: 'amount', label: 'Amount' },
          { key: 'payment_method', label: 'Method' },
          { key: 'reference_number', label: 'Reference' },
          { key: 'created_by', label: 'Created by' }
        ]"
        :rows="paymentHistory"
      >
        <template #cell-amount="{ row }">
          {{ formatMoney(row.amount) }}
        </template>

        <template #cell-reference_number="{ row }">
          {{ row.reference_number || '—' }}
        </template>
      </WorkspaceTable>
    </section>
  </template>
</template>

<style scoped>
.finance-subsection {
  margin-top: 1.5rem;
}

.invoice-filters {
  margin-bottom: 1rem;
}

.invoice-lines-editor {
  display: grid;
  gap: 0.75rem;
  margin: 1rem 0;
}

.invoice-line-editor {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(130px, 1fr));
  gap: 0.75rem;
  align-items: end;
}

.invoice-line-editor strong {
  grid-column: 1 / -1;
}

.finance-summary {
  margin: 1rem 0;
}

.payment-balances {
  display: flex;
  flex-wrap: wrap;
  gap: 0.75rem 1.5rem;
  margin: 1rem 0;
}

.procurement-close-status {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  margin: 1rem 0;
}

.action-row {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
}
</style>
