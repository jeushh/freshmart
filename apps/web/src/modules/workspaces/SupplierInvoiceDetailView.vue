<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { api } from '../../api/http.js'
import { UiButton, UiErrorState, UiInput, UiPageHeader, UiStatusBadge, UiTableShell } from '../../components/ui/index.js'
import SupplierInvoiceEditor from './finance/SupplierInvoiceEditor.vue'
import { formatMoney } from '../../utils/formatters.js'

const route = useRoute(); const detail = ref(null); const purchaseOrder = ref(null); const loading = ref(true); const error = ref(''); const errors = ref({}); const saving = ref(false); const action = ref(''); const actionNotes = ref('')
const form = ref({ invoice_number: '', invoice_date: '', due_date: '', notes: '', items: [] })
const invoice = computed(() => detail.value?.invoice || null)
const editable = computed(() => ['Draft', 'Disputed'].includes(invoice.value?.status))
const total = computed(() => detail.value?.items?.reduce((sum, item) => sum + Number(item.line_total || 0), 0) || 0)
const editMaximums = computed(() => Object.fromEntries((detail.value?.items || []).map(item => {
  const purchaseOrderLine = purchaseOrder.value?.items?.find(
    line => Number(line.purchase_order_item_id) === Number(item.purchase_order_item_id),
  )

  if (!purchaseOrderLine) return [item.purchase_order_item_id, undefined]

  return [
    item.purchase_order_item_id,
    Number(purchaseOrderLine.remaining_invoiceable_qty || 0)
      + (invoice.value?.status === 'Disputed' ? Number(item.invoiced_quantity || 0) : 0),
  ]
})))
async function load() {
  loading.value = true; error.value = ''; errors.value = {}
  try {
    detail.value = await api.get(`/supplier-invoices/${route.params.invoiceId}`)
    form.value = { invoice_number: detail.value.invoice.invoice_number || '', invoice_date: detail.value.invoice.invoice_date || '', due_date: detail.value.invoice.due_date || '', notes: detail.value.invoice.notes || '', items: detail.value.items.map(item => ({ purchase_order_item_id: item.purchase_order_item_id, invoiced_quantity: Number(item.invoiced_quantity), unit_cost: Number(item.unit_cost) })) }
    try { purchaseOrder.value = await api.get(`/finance/purchase-orders/${detail.value.invoice.purchase_order_id}`) } catch { purchaseOrder.value = null }
  } catch (requestError) { error.value = requestError.message } finally { loading.value = false }
}
function payload() { return { invoice_number: form.value.invoice_number.trim() || null, invoice_date: form.value.invoice_date || null, due_date: form.value.due_date || null, notes: form.value.notes.trim() || null, items: form.value.items.map(item => ({ purchase_order_item_id: Number(item.purchase_order_item_id), invoiced_quantity: Number(item.invoiced_quantity), unit_cost: Number(item.unit_cost) })) } }
async function save() { saving.value = true; error.value = ''; errors.value = {}; try { await api.put(`/supplier-invoices/${invoice.value.id}`, payload()); await load() } catch (requestError) { error.value = requestError.message; errors.value = requestError.errors || {} } finally { saving.value = false } }
function startAction(name) { action.value = name; actionNotes.value = '' }
function cancelAction() { action.value = ''; actionNotes.value = '' }
async function transition(name) {
  if (name === 'void' && !window.confirm('Void this supplier invoice? This action cannot be undone.')) return
  saving.value = true; error.value = ''
  try { await api.post(`/supplier-invoices/${invoice.value.id}/${name}`, name === 'dispute' ? { notes: actionNotes.value.trim() || null } : {}); cancelAction(); await load() } catch (requestError) { error.value = requestError.message } finally { saving.value = false }
}
watch(() => route.params.invoiceId, load)
onMounted(load)
</script>
<template>
  <UiPageHeader title="Supplier Invoice Detail" description="Review supplier invoice details and apply the available server-authorized workflow actions." :breadcrumbs="[{ label: 'Supplier Invoices', to: '/finance/supplier-invoices' }, { label: invoice?.invoice_number || 'Invoice detail' }]" />
  <UiErrorState v-if="error && !detail" :message="error" :retrying="loading" @retry="load" />
  <section v-else-if="loading" class="ui-card detail-loading">Loading supplier invoice…</section>
  <template v-else-if="invoice"><section class="ui-card invoice-summary"><div><h2>{{ invoice.invoice_number || 'Draft — no number' }}</h2><p>{{ invoice.supplier_name }} · {{ invoice.po_number }} · Total {{ formatMoney(total) }}</p><p v-if="invoice.notes">Notes: {{ invoice.notes }}</p></div><UiStatusBadge :status="invoice.status" /></section><p v-if="error" class="form-error" role="alert">{{ error }}</p>
    <form v-if="editable" class="ui-card invoice-form" @submit.prevent="save"><h2>Edit {{ invoice.status.toLowerCase() }} invoice</h2><SupplierInvoiceEditor :form="form" :purchase-order="purchaseOrder" :errors="errors" :max-quantities="editMaximums" :disabled="saving" /><UiButton type="submit" :loading="saving">Save invoice changes</UiButton></form>
    <UiTableShell v-else title="Invoice lines" :empty="!detail.items.length" empty-title="No invoice lines"><table><thead><tr><th>SKU</th><th>Product</th><th>Invoice quantity</th><th>Unit cost</th><th>Line total</th></tr></thead><tbody><tr v-for="line in detail.items" :key="line.purchase_order_item_id"><td>{{ line.sku }}</td><td>{{ line.product_name }}</td><td>{{ line.invoiced_quantity }}</td><td>{{ formatMoney(line.unit_cost) }}</td><td>{{ formatMoney(line.line_total) }}</td></tr></tbody></table></UiTableShell>
    <section class="ui-card invoice-actions"><h2>Invoice actions</h2><div class="invoice-actions__buttons"><UiButton v-if="invoice.status === 'Draft'" :loading="saving" @click="transition('register')">Register invoice</UiButton><UiButton v-if="invoice.status === 'Registered'" :loading="saving" @click="transition('approve')">Approve invoice</UiButton><UiButton v-if="invoice.status === 'Registered'" variant="secondary" :disabled="saving" @click="startAction('dispute')">Dispute invoice</UiButton><UiButton v-if="invoice.status === 'Disputed'" :loading="saving" @click="transition('resolve-dispute')">Resolve dispute</UiButton><UiButton v-if="['Draft', 'Registered', 'Disputed'].includes(invoice.status)" variant="destructive" :disabled="saving" @click="transition('void')">Void invoice</UiButton></div><div v-if="action === 'dispute'" class="dispute-form"><UiInput v-model="actionNotes" label="Dispute notes" maxlength="1000" hint="Optional notes recorded with the dispute." /><div class="invoice-actions__buttons"><UiButton :loading="saving" @click="transition('dispute')">Confirm dispute</UiButton><UiButton variant="secondary" :disabled="saving" @click="cancelAction">Cancel</UiButton></div></div></section>
  </template>
</template>
<style scoped>.detail-loading,.invoice-summary,.invoice-form,.invoice-actions{padding:1rem;margin-bottom:1rem}.invoice-summary{display:flex;justify-content:space-between;gap:1rem;align-items:start}.invoice-summary h2,.invoice-actions h2,.invoice-form h2{margin-top:0}.invoice-actions__buttons{display:flex;flex-wrap:wrap;gap:.5rem}.dispute-form{display:grid;gap:.75rem;margin-top:1rem}@media(max-width:600px){.invoice-summary{display:grid}}</style>
