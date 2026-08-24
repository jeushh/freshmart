<script setup>
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { api } from '../../api/http.js'
import { UiButton, UiPageHeader, UiStatusBadge, UiTableShell } from '../../components/ui/index.js'
import { formatMoney } from '../../utils/formatters.js'
const router = useRouter(); const payables = ref([]); const pagination = ref({ current_page: 1, last_page: 1, total: 0 }); const loading = ref(true); const error = ref('')
async function load(page = pagination.value.current_page) { loading.value = true; error.value = ''; try { const data = await api.get('/accounts-payable', { page, per_page: 20 }); payables.value = data.payables.data; pagination.value = data.payables } catch (requestError) { error.value = requestError.message } finally { loading.value = false } }
function statusLabel(row) { return row.overdue ? `${row.status} — Overdue` : row.status }
onMounted(() => load())
</script>
<template>
  <UiPageHeader title="Accounts Payable" description="Track legacy and structured supplier liabilities and their settlement status." />
  <UiTableShell title="Payables" :loading="loading" :error="error" :empty="!payables.length" empty-title="No accounts payable" empty-description="No payable records are available." @retry="load"><table><thead><tr><th>Supplier</th><th>PO</th><th>Invoice</th><th>Source</th><th>Total</th><th>Paid</th><th>Outstanding</th><th>Due date</th><th>Status</th><th>Action</th></tr></thead><tbody><tr v-for="payable in payables" :key="payable.id"><td>{{ payable.supplier_name || 'Not assigned' }}</td><td>{{ payable.po_number || '—' }}</td><td>{{ payable.invoice_number || '—' }}</td><td>{{ payable.source === 'structured' ? 'Structured' : 'Legacy' }}</td><td>{{ formatMoney(payable.total_amount) }}</td><td>{{ formatMoney(payable.amount_paid) }}</td><td>{{ formatMoney(payable.outstanding_balance) }}</td><td>{{ payable.due_date || '—' }}</td><td><UiStatusBadge :status="statusLabel(payable)" :tone="payable.overdue ? 'danger' : ''" /></td><td><UiButton size="sm" variant="secondary" @click="router.push(`/finance/accounts-payable/${payable.id}`)">View details</UiButton></td></tr></tbody></table><template #footer><div class="pagination"><span>Page {{ pagination.current_page }} of {{ pagination.last_page }} · {{ pagination.total }} payables</span><div><UiButton size="sm" variant="secondary" :disabled="pagination.current_page <= 1" @click="load(pagination.current_page - 1)">Previous</UiButton><UiButton size="sm" variant="secondary" :disabled="pagination.current_page >= pagination.last_page" @click="load(pagination.current_page + 1)">Next</UiButton></div></div></template></UiTableShell>
</template>
<style scoped>.pagination{display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap}</style>
