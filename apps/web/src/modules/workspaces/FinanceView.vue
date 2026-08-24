<script setup>
import { computed, onMounted, ref } from 'vue'
import { api } from '../../api/http.js'
import { UiErrorState, UiKpiCard, UiLoadingSkeleton, UiPageHeader, UiSectionCard, UiStatusBadge, UiTableShell } from '../../components/ui/index.js'
import { sessionStore } from '../../stores/session.js'
import { formatDateTime, formatMoney } from '../../utils/formatters.js'

const dashboard = ref(null)
const loading = ref(true)
const error = ref('')
const metricDefinitions = [
  ['today_revenue', "Today's cash revenue"], ['month_revenue', 'Current month revenue'],
  ['month_expenses', 'Current month expenses'], ['month_supplier_payments', 'Current month supplier payments'],
  ['net_movement', 'Net movement'], ['accounts_payable', 'Outstanding accounts payable'],
]
const metrics = computed(() => {
  const values = new Map((dashboard.value?.metrics || []).map(metric => [metric.key, metric]))
  return metricDefinitions.map(([key, label]) => ({ key, label, value: values.has(key) ? formatMoney(values.get(key).value) : '—' }))
})
const transactions = computed(() => dashboard.value?.sections?.recent_financial_transactions || [])
const shortcuts = computed(() => [
  sessionStore.can('finance.requests.view') && ['Finance Requests', '/finance/requests'],
  sessionStore.can('finance.manage') && ['Recent Transactions', '/finance/transactions'],
  sessionStore.can('finance.manage') && ['Supplier Invoices', '/finance/supplier-invoices'],
  sessionStore.can('finance.manage') && ['Accounts Payable', '/finance/accounts-payable'],
  sessionStore.can('reports.finance.view') && ['Reports', '/reports'],
].filter(Boolean))
async function load() {
  loading.value = true; error.value = ''
  try {
    dashboard.value = await api.get('/dashboard')
    if (dashboard.value.settings) sessionStore.updateSettings(dashboard.value.settings)
  } catch (requestError) { error.value = requestError.message } finally { loading.value = false }
}
onMounted(load)
</script>

<template>
  <UiPageHeader title="Finance Overview" description="A compact view of current financial activity and payable obligations.">
    <template v-if="sessionStore.can('finance.manage')" #actions><RouterLink class="ui-button ui-button--primary" to="/finance/supplier-invoices/new">Create supplier invoice</RouterLink></template>
  </UiPageHeader>
  <UiErrorState v-if="error" :message="error" :retrying="loading" @retry="load" />
  <template v-else>
    <section class="finance-kpis" aria-label="Finance summary">
      <UiKpiCard v-for="metric in metrics" :key="metric.key" :label="metric.label" :value="metric.value" :loading="loading" />
    </section>
    <section v-if="loading" class="ui-card"><UiLoadingSkeleton label="Loading finance overview" /></section>
    <div v-else class="finance-overview-grid">
      <UiTableShell title="Recent financial transactions" description="Latest authorized cash movements." :empty="!transactions.length" empty-title="No recent financial transactions" empty-description="No transactions are available for this authorized view.">
        <table><thead><tr><th>Date</th><th>Type</th><th>Direction</th><th>Amount</th></tr></thead><tbody>
          <tr v-for="transaction in transactions" :key="transaction.id"><td>{{ formatDateTime(transaction.created_at) }}</td><td>{{ transaction.transaction_type }}</td><td><UiStatusBadge :status="transaction.direction" :tone="transaction.direction === 'In' ? 'success' : 'warning'" /></td><td>{{ formatMoney(transaction.amount) }}</td></tr>
        </tbody></table>
      </UiTableShell>
      <UiSectionCard title="Finance workflows" description="Continue to an authorized finance workspace.">
        <nav class="shortcut-list" aria-label="Finance workflows"><RouterLink v-for="shortcut in shortcuts" :key="shortcut[1]" :to="shortcut[1]">{{ shortcut[0] }} <span aria-hidden="true">→</span></RouterLink></nav>
      </UiSectionCard>
    </div>
  </template>
</template>

<style scoped>
.finance-kpis{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:1rem;margin-bottom:1rem}.finance-overview-grid{display:grid;grid-template-columns:minmax(0,2fr) minmax(260px,1fr);gap:1rem}.shortcut-list{display:grid;gap:.5rem}.shortcut-list a{display:flex;justify-content:space-between;align-items:center;padding:.75rem;border:1px solid var(--fm-color-border,#dfe5e0);border-radius:.5rem;text-decoration:none}@media(max-width:900px){.finance-kpis{grid-template-columns:repeat(2,minmax(0,1fr))}.finance-overview-grid{grid-template-columns:1fr}}@media(max-width:520px){.finance-kpis{grid-template-columns:1fr}}
</style>
