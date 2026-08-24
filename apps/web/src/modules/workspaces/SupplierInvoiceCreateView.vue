<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { api } from '../../api/http.js'
import { UiButton, UiErrorState, UiPageHeader, UiSelect } from '../../components/ui/index.js'
import SupplierInvoiceEditor from './finance/SupplierInvoiceEditor.vue'

const router = useRouter(); const orders = ref([]); const selectedOrder = ref(null); const loading = ref(true); const loadingOrder = ref(false); const error = ref(''); const errors = ref({}); const saving = ref(false)
const form = ref({ purchase_order_id: '', invoice_number: '', invoice_date: '', due_date: '', notes: '', items: [] })
const canSave = computed(() => form.value.purchase_order_id && form.value.items.some(item => Number(item.invoiced_quantity) > 0))
async function loadOrders() { loading.value = true; error.value = ''; try { orders.value = (await api.get('/finance/purchase-orders', { per_page: 100 })).orders.data } catch (requestError) { error.value = requestError.message } finally { loading.value = false } }
async function selectOrder(id) {
  if (!id) { selectedOrder.value = null; form.value.items = []; return }
  const previousOrderId = selectedOrder.value?.order?.id || ''
  loadingOrder.value = true; error.value = ''
  try { const data = await api.get(`/finance/purchase-orders/${id}`); selectedOrder.value = data; form.value.items = data.items.map(item => ({ purchase_order_item_id: item.purchase_order_item_id, invoiced_quantity: Number(item.remaining_invoiceable_qty) > 0 ? 1 : 0, unit_cost: Number(item.po_unit_cost) })) } catch (requestError) { form.value.purchase_order_id = previousOrderId; error.value = requestError.message } finally { loadingOrder.value = false }
}
function payload() { return { invoice_number: form.value.invoice_number.trim() || null, invoice_date: form.value.invoice_date || null, due_date: form.value.due_date || null, notes: form.value.notes.trim() || null, items: form.value.items.filter(item => Number(item.invoiced_quantity) > 0).map(item => ({ purchase_order_item_id: Number(item.purchase_order_item_id), invoiced_quantity: Number(item.invoiced_quantity), unit_cost: Number(item.unit_cost) })) } }
async function create(registerNow = false) {
  if (!canSave.value) { error.value = 'Enter an invoiced quantity for at least one PO line.'; return }
  if (registerNow && !form.value.invoice_number.trim()) { error.value = 'Invoice number is required before registration.'; return }
  saving.value = true; error.value = ''; errors.value = {}
  try { const created = await api.post(`/purchase-orders/${form.value.purchase_order_id}/invoices`, payload()); const id = created.invoice.id; if (registerNow) await api.post(`/supplier-invoices/${id}/register`); await router.push(`/finance/supplier-invoices/${id}`) } catch (requestError) { error.value = requestError.message; errors.value = requestError.errors || {} } finally { saving.value = false }
}
onMounted(loadOrders)
</script>
<template>
  <UiPageHeader title="Create Supplier Invoice" description="Create a draft invoice or create and register it for the selected eligible purchase order." :breadcrumbs="[{ label: 'Supplier Invoices', to: '/finance/supplier-invoices' }, { label: 'Create Supplier Invoice' }]" />
  <UiErrorState v-if="error && !selectedOrder" :message="error" :retrying="loading" @retry="loadOrders" />
  <form v-else class="ui-card create-invoice" @submit.prevent="create(false)"><UiSelect v-model="form.purchase_order_id" label="Eligible purchase order" required :loading="loading || loadingOrder" @change="selectOrder(form.purchase_order_id)"><option value="">Select purchase order</option><option v-for="order in orders" :key="order.id" :value="order.id">{{ order.po_number }} — {{ order.supplier_name }} — {{ order.status }}</option></UiSelect><p v-if="error" class="form-error" role="alert">{{ error }}</p><SupplierInvoiceEditor v-if="selectedOrder" :form="form" :purchase-order="selectedOrder" :errors="errors" :disabled="saving" /><div class="create-invoice__actions"><UiButton type="submit" :disabled="!canSave" :loading="saving">Save draft</UiButton><UiButton type="button" variant="secondary" :disabled="!canSave || saving" @click="create(true)">Create and register</UiButton><RouterLink class="ui-button ui-button--ghost" to="/finance/supplier-invoices">Cancel</RouterLink></div></form>
</template>
<style scoped>.create-invoice{display:grid;gap:1rem;padding:1rem}.create-invoice__actions{display:flex;gap:.75rem;flex-wrap:wrap}</style>
