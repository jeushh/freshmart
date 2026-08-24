<script setup>
import { computed } from 'vue'
import { UiInput } from '../../../components/ui/index.js'
import { formatMoney } from '../../../utils/formatters.js'

const props = defineProps({ form: { type: Object, required: true }, purchaseOrder: { type: Object, default: null }, errors: { type: Object, default: () => ({}) }, maxQuantities: { type: Object, default: () => ({}) }, disabled: Boolean })
const model = computed(() => props.form)
const lines = computed(() => model.value.items || [])
function poLine(id) { return props.purchaseOrder?.items?.find(item => Number(item.purchase_order_item_id) === Number(id)) }
function total(line) { return Number(line.invoiced_quantity || 0) * Number(line.unit_cost || 0) }
function variance(line) { return Number(line.unit_cost || 0) - Number(poLine(line.purchase_order_item_id)?.po_unit_cost || 0) }
function maxQuantity(line) { return props.maxQuantities[line.purchase_order_item_id] ?? poLine(line.purchase_order_item_id)?.remaining_invoiceable_qty }
</script>

<template>
  <div class="invoice-editor">
    <div class="invoice-editor__fields">
      <UiInput v-model="model.invoice_number" label="Invoice number" maxlength="100" :disabled="disabled" :error="errors.invoice_number?.[0] || ''" />
      <UiInput v-model="model.invoice_date" label="Invoice date" type="date" :disabled="disabled" :error="errors.invoice_date?.[0] || ''" />
      <UiInput v-model="model.due_date" label="Due date" type="date" :disabled="disabled" :error="errors.due_date?.[0] || ''" />
      <UiInput v-model="model.notes" label="Notes" maxlength="1000" :disabled="disabled" :error="errors.notes?.[0] || ''" />
    </div>
    <div v-if="purchaseOrder" class="invoice-editor__context"><strong>Supplier:</strong> {{ purchaseOrder.order.supplier_name }} · <strong>PO:</strong> {{ purchaseOrder.order.po_number }}</div>
    <section class="invoice-editor__lines" aria-label="Supplier invoice lines">
      <article v-for="line in lines" :key="line.purchase_order_item_id" class="invoice-line">
        <template v-if="poLine(line.purchase_order_item_id)"><h3>{{ poLine(line.purchase_order_item_id).sku }} — {{ poLine(line.purchase_order_item_id).product_name }}</h3><div class="invoice-line__facts"><span>Ordered: {{ poLine(line.purchase_order_item_id).quantity_ordered }}</span><span>Accepted: {{ poLine(line.purchase_order_item_id).accepted_received_qty }}</span><span>Committed: {{ poLine(line.purchase_order_item_id).committed_invoice_qty }}</span><span>Remaining: {{ poLine(line.purchase_order_item_id).remaining_invoiceable_qty }}</span><span>PO cost: {{ formatMoney(poLine(line.purchase_order_item_id).po_unit_cost) }}</span></div><div class="invoice-line__inputs"><UiInput v-model="line.invoiced_quantity" label="Invoice quantity" type="number" min="0" :max="maxQuantity(line)" :disabled="disabled" required /><UiInput v-model="line.unit_cost" label="Invoice unit cost" type="number" min="0" step=".01" :disabled="disabled" required /><span><strong>Variance:</strong> {{ formatMoney(variance(line)) }}</span><span><strong>Line total:</strong> {{ formatMoney(total(line)) }}</span></div></template>
      </article>
    </section>
    <p class="invoice-editor__total"><strong>Estimated invoice total:</strong> {{ formatMoney(lines.reduce((sum, line) => sum + total(line), 0)) }}</p>
  </div>
</template>

<style scoped>
.invoice-editor { display: grid; gap: 1rem; }
.invoice-editor__fields, .invoice-line__inputs { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: .75rem; }
.invoice-editor__context { padding: .75rem; background: #f7faf8; border-radius: .5rem; }
.invoice-editor__lines { display: grid; gap: .75rem; }
.invoice-line { padding: 1rem; border: 1px solid #dfe5e0; border-radius: .5rem; }
.invoice-line h3 { margin: 0 0 .75rem; font-size: 1rem; }
.invoice-line__facts { display: flex; flex-wrap: wrap; gap: .5rem 1rem; margin-bottom: .75rem; color: #536059; }
.invoice-line__inputs span { align-self: end; padding-bottom: .65rem; }
.invoice-editor__total { margin: 0; }
@media (max-width: 640px) { .invoice-editor__fields, .invoice-line__inputs { grid-template-columns: 1fr; } .invoice-line__inputs span { padding-bottom: 0; } }
</style>
