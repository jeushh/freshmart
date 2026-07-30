<script setup>
import { computed, onMounted, ref } from 'vue'
import { api } from '../../api/http.js'
import MetricChart from '../../components/common/MetricChart.vue'
import PageHeader from '../../components/common/PageHeader.vue'
import { sessionStore } from '../../stores/session.js'
import { formatDateTime, formatMoney, formatNumber } from '../../utils/formatters.js'

const dashboard = ref(null)
const loading = ref(true)
const error = ref('')
const modules = [
  ['Point of Sale', 'Process customer transactions.', '/pos', 'pos.access'],
  ['Employees', 'Manage employee records.', '/employees', 'hr.employees.view'],
  ['Attendance', 'Review time records.', '/attendance', 'hr.attendance.view'],
  ['HR Requests', 'Review leave and overtime requests.', '/hr-requests', 'hr.requests.view'],
  ['Payroll', 'Prepare payroll periods.', '/payroll', 'payroll.manage'],
  ['Products & Stock', 'Control products and monitor stock.', '/inventory', 'inventory.manage|restock.approve'],
  ['Low Stock', 'Review products at or below reorder level.', '/inventory/low-stock', 'inventory.manage'],
  ['Inventory Movements', 'Trace audited stock changes.', '/inventory/movements', 'inventory.manage'],
  ['Restock Requests', 'Request and approve replenishment.', '/restock-requests', 'restock.request|restock.approve'],
  ['Purchase Orders', 'Order and receive supplier stock.', '/purchase-orders', 'procurement.purchase_orders.view'],
  ['Stock Receiving', 'Receive approved supplier deliveries.', '/stock-receiving', 'procurement.stock.receive'],
  ['Finance', 'Review requests and payments.', '/finance', 'finance.requests.view|finance.manage'],
  ['Reports', 'Analyze business performance.', '/reports', 'reports.sales.view|reports.inventory.view|reports.procurement.view|reports.hr.view|reports.payroll.view|reports.finance.view'],
  ['Self-Service', 'View your profile and submit requests.', '/self-service', 'employee.self'],
  ['Administration', 'Manage accounts and audit activity.', '/admin', 'system.users.manage|system.audit.view'],
  ['Roles & Permissions', 'Configure role-based access.', '/roles', 'system.roles.manage'],
  ['System Settings', 'Manage approved application settings.', '/settings', 'system.settings.manage']
]
const visible = computed(() => modules.filter(module => sessionStore.can(module[3])))

async function load() {
  loading.value = true
  error.value = ''
  try {
    dashboard.value = await api.get('/dashboard')
    sessionStore.updateSettings(dashboard.value.settings)
  } catch (requestError) {
    error.value = requestError.requestId
      ? `${requestError.message} Reference: ${requestError.requestId}`
      : requestError.message
  } finally {
    loading.value = false
  }
}

function title(value) {
  return value.replaceAll('_', ' ').replace(/\b\w/g, letter => letter.toUpperCase())
}

function display(value, key = '') {
  if (value === null || value === undefined || value === '') return '—'
  if (typeof value === 'boolean') return value ? 'Yes' : 'No'
  if (key.includes('amount') || key.includes('total') || key.includes('pay') || key.includes('obligation')) {
    return formatMoney(value)
  }
  if (key.includes('date') || key.includes('_at') || key === 'timestamp') return formatDateTime(value)
  if (typeof value === 'number') return formatNumber(value)
  return value
}

function simpleEntries(section) {
  return Object.entries(section || {}).filter(([, value]) => !Array.isArray(value) && (typeof value !== 'object' || value === null))
}

function nestedCollections(section) {
  return Object.entries(section || {}).filter(([, value]) => Array.isArray(value))
}

function nestedObjects(section) {
  return Object.entries(section || {}).filter(([, value]) =>
    value !== null && !Array.isArray(value) && typeof value === 'object'
  )
}

function columns(rows) {
  return rows.length ? Object.keys(rows[0]).filter(key => key !== 'id').slice(0, 7) : []
}

onMounted(load)
</script>

<template>
  <PageHeader title="Dashboard" :description="`Live overview for ${sessionStore.state.user?.fullName || 'your account'}.`" />
  <div v-if="error" class="form-error error-action" role="alert">
    <span>{{ error }}</span>
    <button class="secondary-button" @click="load">Retry</button>
  </div>
  <div v-if="loading" class="loading-panel">Loading dashboard…</div>

  <template v-else-if="dashboard">
    <section class="metrics-grid" aria-label="Key metrics">
      <article v-for="metric in dashboard.metrics" :key="metric.key" class="metric-card">
        <span>{{ metric.label }}</span>
        <strong>{{ metric.format === 'money' ? formatMoney(metric.value) : formatNumber(metric.value) }}</strong>
      </article>
      <article v-if="!dashboard.metrics.length" class="metric-card">
        <span>Welcome</span><strong>FreshMart</strong>
      </article>
    </section>

    <section v-if="Object.keys(dashboard.charts).length" class="dashboard-charts">
      <MetricChart v-for="(chart, key) in dashboard.charts" :key="key" :chart="chart" />
    </section>

    <section class="dashboard-sections">
      <article v-for="(section, key) in dashboard.sections" :key="key" class="dashboard-panel">
        <h2>{{ title(key) }}</h2>
        <template v-if="Array.isArray(section)">
          <div v-if="section.length" class="table-scroll">
            <table>
              <thead><tr><th v-for="column in columns(section)" :key="column">{{ title(column) }}</th></tr></thead>
              <tbody>
                <tr v-for="(row, index) in section" :key="row.id || index">
                  <td v-for="column in columns(section)" :key="column">{{ display(row[column], column) }}</td>
                </tr>
              </tbody>
            </table>
          </div>
          <p v-else class="empty-state">No activity to show yet.</p>
        </template>
        <template v-else>
          <dl class="summary-list">
            <template v-for="[itemKey, value] in simpleEntries(section)" :key="itemKey">
              <dt>{{ title(itemKey) }}</dt><dd>{{ display(value, itemKey) }}</dd>
            </template>
          </dl>
          <section v-for="[objectKey, object] in nestedObjects(section)" :key="objectKey">
            <h3>{{ title(objectKey) }}</h3>
            <dl class="summary-list">
              <template v-for="[itemKey, value] in simpleEntries(object)" :key="itemKey">
                <dt>{{ title(itemKey) }}</dt><dd>{{ display(value, itemKey) }}</dd>
              </template>
            </dl>
          </section>
          <section v-for="[collectionKey, rows] in nestedCollections(section)" :key="collectionKey">
            <h3>{{ title(collectionKey) }}</h3>
            <div v-if="rows.length" class="table-scroll">
              <table>
                <thead><tr><th v-for="column in columns(rows)" :key="column">{{ title(column) }}</th></tr></thead>
                <tbody>
                  <tr v-for="(row, index) in rows" :key="row.id || index">
                    <td v-for="column in columns(rows)" :key="column">{{ display(row[column], column) }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
            <p v-else class="empty-state">No records.</p>
          </section>
        </template>
      </article>
    </section>

    <h2 class="section-title">Workspaces</h2>
    <div class="module-grid">
      <RouterLink v-for="module in visible" :key="module[2]" :to="module[2]" class="module-card">
        <h2>{{ module[0] }}</h2>
        <p>{{ module[1] }}</p>
        <span>Open module →</span>
      </RouterLink>
    </div>
  </template>
</template>
