<script setup>
import { computed, onMounted, ref } from 'vue'
import { api } from '../../api/http.js'
import PageHeader from '../../components/common/PageHeader.vue'
import { sessionStore } from '../../stores/session.js'
import { formatDateTime, formatMoney, formatNumber } from '../../utils/formatters.js'

const reportTypes = [
  { key: 'sales', label: 'Sales', permission: 'reports.sales.view' },
  { key: 'inventory', label: 'Inventory', permission: 'reports.inventory.view' },
  { key: 'procurement', label: 'Procurement', permission: 'reports.procurement.view' },
  { key: 'hr', label: 'HR', permission: 'reports.hr.view' },
  { key: 'payroll', label: 'Payroll', permission: 'reports.payroll.view' },
  { key: 'finance', label: 'Finance', permission: 'reports.finance.view' }
]
const available = computed(() => reportTypes.filter(item => sessionStore.can(item.permission)))
const active = ref('')
const report = ref(null)
const loading = ref(false)
const exporting = ref(false)
const error = ref('')
const today = new Intl.DateTimeFormat('en-CA', {
  timeZone: sessionStore.state.settings.timezone || 'Asia/Manila'
}).format(new Date())
const firstOfMonth = `${today.slice(0, 8)}01`
const filters = ref({ from: firstOfMonth, to: today, per_page: 25, page: 1 })

const filterOptions = {
  sales: [
    ['payment_method', 'Payment method', ['', 'Cash', 'Card', 'QR']],
    ['transaction_status', 'Transaction status', ['', 'Finalized', 'Refunded']]
  ],
  inventory: [
    ['stock_state', 'Stock state', ['all', 'low', 'out', 'above_max']],
    ['movement_type', 'Movement type', ['', 'Sale', 'Refund', 'Stock In', 'Stock Out', 'Adjustment', 'Purchase', 'Receiving']]
  ],
  procurement: [
    ['approval_status', 'Approval status', ['', 'Draft', 'Submitted', 'Approved', 'Rejected', 'Cancelled']],
    ['operational_status', 'Operational status', ['', 'Pending', 'Approved', 'Ordered', 'Partially Received', 'Fully Received', 'Cancelled']]
  ],
  hr: [
    ['employee_status', 'Employee status', ['', 'Active', 'On Leave', 'Terminated']],
    ['attendance_status', 'Attendance status', ['', 'Present', 'Late', 'Absent', 'On Leave']],
    ['hr_request_status', 'Request status', ['', 'Pending', 'Approved', 'Rejected']]
  ],
  payroll: [
    ['payroll_status', 'Payroll status', ['', 'Draft', 'Pending Approval', 'Approved', 'Paid']]
  ],
  finance: [
    ['direction', 'Direction', ['', 'In', 'Out']],
    ['finance_status', 'Finance status', ['', 'Posted', 'Unpaid', 'Partially Paid', 'Paid', 'Overdue']]
  ]
}

const textFilters = computed(() => ({
  sales: [['cashier', 'Cashier'], ['category', 'Category'], ['product_id', 'Product ID']],
  inventory: [['category', 'Category'], ['supplier_id', 'Supplier ID']],
  procurement: [['supplier_id', 'Supplier ID']],
  hr: [['department', 'Department']],
  payroll: [['department', 'Department'], ['employee_id', 'Employee ID']],
  finance: [['category', 'Category']]
})[active.value] || [])
const summaryEntries = computed(() => Object.entries(report.value?.summary || {}))

function params() {
  return Object.fromEntries(
    Object.entries(filters.value).filter(([, value]) => value !== '' && value !== null)
  )
}

function selectReport(type) {
  active.value = type
  report.value = null
  filters.value = { from: firstOfMonth, to: today, per_page: 25, page: 1 }
  load()
}

async function load(page = 1) {
  if (!active.value) return
  loading.value = true
  error.value = ''
  filters.value.page = page
  try {
    report.value = await api.get(`/reports/${active.value}`, params())
    sessionStore.updateSettings(report.value.settings)
  } catch (requestError) {
    error.value = requestError.requestId
      ? `${requestError.message} Reference: ${requestError.requestId}`
      : requestError.message
  } finally {
    loading.value = false
  }
}

function displayValue(column, value) {
  if (column.format === 'money') return formatMoney(value)
  if (column.key.includes('date') || column.key === 'timestamp') {
    return column.key === 'timestamp' ? formatDateTime(value) : (value || '—')
  }
  return value ?? '—'
}

function summaryValue(key, value) {
  if (typeof value === 'boolean') return value ? 'Yes' : 'No'
  if (key.endsWith('_records') || key.endsWith('_count')) return formatNumber(value)
  const moneyKeys = [
    'sales', 'refunds', 'transaction', 'tax', 'discount', 'valuation',
    'cost', 'pay', 'deductions', 'revenue', 'expenses', 'movement',
    'payable', 'receivable'
  ]
  return moneyKeys.some(token => key.includes(token))
    ? formatMoney(value)
    : formatNumber(value)
}

function label(key) {
  return key.replaceAll('_', ' ').replace(/\b\w/g, letter => letter.toUpperCase())
}

async function exportCsv() {
  exporting.value = true
  error.value = ''
  try {
    const blob = await api.download(`/reports/${active.value}/export`, params())
    const url = URL.createObjectURL(blob)
    const link = document.createElement('a')
    link.href = url
    link.download = `freshmart-${active.value}-report-${today}.csv`
    link.click()
    URL.revokeObjectURL(url)
  } catch (requestError) {
    error.value = requestError.message
  } finally {
    exporting.value = false
  }
}

onMounted(() => {
  if (available.value.length) selectReport(available.value[0].key)
})
</script>

<template>
  <div class="report-page">
    <PageHeader title="Reports" description="Permission-aware operational and financial reporting.">
      <button class="secondary-button print-hidden" :disabled="!report" @click="window.print()">Print report</button>
      <button
        v-if="sessionStore.can('reports.export')"
        class="primary-button print-hidden"
        :disabled="!report || exporting"
        @click="exportCsv"
      >{{ exporting ? 'Exporting…' : 'Export CSV' }}</button>
    </PageHeader>

    <nav class="report-tabs print-hidden" aria-label="Report type">
      <button
        v-for="item in available"
        :key="item.key"
        :class="{ active: active === item.key }"
        @click="selectReport(item.key)"
      >{{ item.label }}</button>
    </nav>

    <form class="report-filters print-hidden" @submit.prevent="load(1)">
      <label>From<input v-model="filters.from" type="date" required></label>
      <label>To<input v-model="filters.to" type="date" :min="filters.from" required></label>
      <label v-for="[key, title, options] in filterOptions[active] || []" :key="key">
        {{ title }}
        <select v-model="filters[key]">
          <option v-for="option in options" :key="option" :value="option">
            {{ option ? label(option) : 'All' }}
          </option>
        </select>
      </label>
      <label v-for="[key, title] in textFilters" :key="key">
        {{ title }}<input v-model.trim="filters[key]" type="search" maxlength="100">
      </label>
      <label>Rows<select v-model.number="filters.per_page"><option>25</option><option>50</option><option>100</option></select></label>
      <button class="primary-button">Run report</button>
    </form>

    <div v-if="error" class="form-error error-action" role="alert">
      <span>{{ error }}</span>
      <button class="secondary-button" @click="load(filters.page)">Retry</button>
    </div>
    <div v-if="loading" class="loading-panel" aria-live="polite">Loading report…</div>

    <template v-else-if="report">
      <div class="print-report-heading">
        <h2>{{ sessionStore.state.settings.business_name }} — {{ label(report.type) }} report</h2>
        <p v-if="sessionStore.state.settings.business_address || sessionStore.state.settings.business_contact">
          <span v-if="sessionStore.state.settings.business_address">{{ sessionStore.state.settings.business_address }}</span>
          <span v-if="sessionStore.state.settings.business_address && sessionStore.state.settings.business_contact"> · </span>
          <span v-if="sessionStore.state.settings.business_contact">{{ sessionStore.state.settings.business_contact }}</span>
        </p>
        <p>
          Generated {{ formatDateTime(report.generated_at) }}
          <span v-for="filter in report.filter_descriptions" :key="filter.key">
            · {{ filter.label }}: {{ filter.value }}
          </span>
        </p>
        <p>
          Currency: {{ sessionStore.state.settings.currency_code }} · Current tax:
          {{ sessionStore.state.settings.tax_rate }}%
          {{ sessionStore.state.settings.tax_inclusive ? 'inclusive' : 'exclusive' }}
        </p>
      </div>

      <section class="metrics-grid" aria-label="Report summary">
        <article v-for="[key, value] in summaryEntries" :key="key" class="metric-card">
          <span>{{ label(key) }}</span>
          <strong>{{ summaryValue(key, value) }}</strong>
        </article>
      </section>

      <div class="table-panel report-table">
        <div class="table-scroll">
          <table>
            <thead><tr><th v-for="column in report.columns" :key="column.key">{{ column.label }}</th></tr></thead>
            <tbody>
              <tr v-for="(row, index) in report.records.data" :key="row.id || row.source_id || index">
                <td v-for="column in report.columns" :key="column.key">
                  {{ displayValue(column, row[column.key]) }}
                </td>
              </tr>
              <tr v-if="!report.records.data.length">
                <td class="empty-cell" :colspan="report.columns.length">No records match these filters.</td>
              </tr>
            </tbody>
          </table>
        </div>
        <div v-if="report.records.last_page > 1" class="pagination print-hidden">
          <span>Page {{ report.records.current_page }} of {{ report.records.last_page }}</span>
          <div>
            <button :disabled="report.records.current_page === 1" @click="load(report.records.current_page - 1)">Previous</button>
            <button :disabled="report.records.current_page === report.records.last_page" @click="load(report.records.current_page + 1)">Next</button>
          </div>
        </div>
      </div>

      <ul v-if="report.notes.length" class="report-notes">
        <li v-for="note in report.notes" :key="note">{{ note }}</li>
      </ul>
    </template>
  </div>
</template>
