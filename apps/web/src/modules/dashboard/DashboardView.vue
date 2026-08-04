<script setup>
import { computed, onMounted, ref } from 'vue'
import { api } from '../../api/http.js'
import MetricChart from '../../components/common/MetricChart.vue'
import UiEmptyState from '../../components/ui/UiEmptyState.vue'
import UiErrorState from '../../components/ui/UiErrorState.vue'
import UiKpiCard from '../../components/ui/UiKpiCard.vue'
import UiLoadingSkeleton from '../../components/ui/UiLoadingSkeleton.vue'
import UiPageHeader from '../../components/ui/UiPageHeader.vue'
import UiSectionCard from '../../components/ui/UiSectionCard.vue'
import UiTableShell from '../../components/ui/UiTableShell.vue'
import { sessionStore } from '../../stores/session.js'
import { formatDateTime, formatMoney, formatNumber } from '../../utils/formatters.js'

const dashboard = ref(null)
const loading = ref(true)
const error = ref('')

const metricPermissions = {
  active_users: 'system.users.manage|system.roles.manage',
  roles: 'system.users.manage|system.roles.manage',
  today_sales: 'pos.access|sales.view|reports.sales.view',
  today_transactions: 'pos.access|sales.view|reports.sales.view',
  average_transaction: 'pos.access|sales.view|reports.sales.view',
  month_sales: 'sales.view|reports.sales.view',
  month_refunds: 'pos.refund',
  active_employees: 'hr.employees.view|reports.hr.view',
  employees_on_leave: 'hr.employees.view|reports.hr.view',
  pending_hr_requests: 'hr.requests.view|reports.hr.view',
  outstanding_payroll: 'payroll.manage|reports.payroll.view',
  today_revenue: 'finance.manage|reports.finance.view',
  month_revenue: 'finance.manage|reports.finance.view',
  month_expenses: 'finance.manage|reports.finance.view',
  net_movement: 'finance.manage|reports.finance.view',
  accounts_payable: 'finance.manage|reports.finance.view',
  submitted_purchase_orders: 'restock.approve|procurement.purchase_orders.approve|reports.procurement.view',
  partially_received_orders: 'restock.approve|procurement.purchase_orders.approve|reports.procurement.view',
  fully_received_orders: 'restock.approve|procurement.purchase_orders.approve|reports.procurement.view',
  approved_restock_requests: 'restock.approve|procurement.purchase_orders.approve|reports.procurement.view',
  total_products: 'inventory.manage|reports.inventory.view',
  low_stock: 'inventory.manage|reports.inventory.view',
  out_of_stock: 'inventory.manage|reports.inventory.view',
  pending_restock: 'inventory.manage|reports.inventory.view',
  ready_for_receiving: 'inventory.manage|reports.inventory.view'
}

const listSectionDefinitions = [
  {
    key: 'recent_sales',
    title: 'Recent sales',
    description: 'Latest finalized transactions available to your account.',
    permission: 'pos.access|sales.view|reports.sales.view',
    emptyTitle: 'No recent sales',
    emptyDescription: 'No finalized sales are available for this authorized view.',
    columns: [
      ['timestamp', 'Date', 'datetime'],
      ['order_id', 'Order'],
      ['items', 'Items', 'number'],
      ['total', 'Total', 'money'],
      ['payment_method', 'Payment'],
      ['cashier_username', 'Cashier']
    ]
  },
  {
    key: 'recent_audit',
    title: 'Recent activity',
    description: 'Latest recorded administrative activity.',
    permission: 'system.audit.view',
    emptyTitle: 'No recent activity',
    emptyDescription: 'No audit events are currently recorded.',
    columns: [
      ['created_at', 'Date', 'datetime'],
      ['username', 'User'],
      ['action', 'Action'],
      ['entity_type', 'Entity']
    ]
  },
  {
    key: 'recent_financial_transactions',
    title: 'Recent financial transactions',
    description: 'Latest authorized cash movements.',
    permission: 'finance.manage|reports.finance.view',
    emptyTitle: 'No financial transactions',
    emptyDescription: 'No transactions are available for this authorized view.',
    columns: [
      ['created_at', 'Date', 'datetime'],
      ['transaction_type', 'Type'],
      ['direction', 'Direction'],
      ['amount', 'Amount', 'money'],
      ['description', 'Description']
    ]
  },
  {
    key: 'recent_inventory_movements',
    title: 'Recent inventory movements',
    description: 'Latest audited stock changes.',
    permission: 'inventory.manage|reports.inventory.view',
    emptyTitle: 'No inventory movements',
    emptyDescription: 'No stock movements are currently recorded.',
    columns: [
      ['created_at', 'Date', 'datetime'],
      ['sku', 'SKU'],
      ['movement_type', 'Movement'],
      ['quantity', 'Quantity', 'number'],
      ['previous_stock', 'Previous', 'number'],
      ['new_stock', 'New', 'number']
    ]
  },
  {
    key: 'low_leave_balances',
    title: 'Low leave balances',
    description: 'Employees whose available leave balance is low.',
    permission: 'hr.employees.view|reports.hr.view',
    emptyTitle: 'No low leave balances',
    emptyDescription: 'No employees currently meet the low-balance threshold.',
    columns: [
      ['employee_no', 'Employee number'],
      ['full_name', 'Employee'],
      ['leave_balance', 'Leave balance', 'number']
    ]
  },
  {
    key: 'supplier_activity',
    title: 'Supplier activity',
    description: 'Recent purchase-order activity grouped by supplier.',
    permission: 'restock.approve|procurement.purchase_orders.approve|reports.procurement.view',
    emptyTitle: 'No supplier activity',
    emptyDescription: 'No purchase-order activity is currently recorded.',
    columns: [
      ['name', 'Supplier'],
      ['purchase_orders', 'Purchase orders', 'number'],
      ['latest_order', 'Latest order', 'date']
    ]
  }
]

const summarySectionDefinitions = [
  {
    key: 'attendance_today',
    title: 'Attendance today',
    description: 'Authorized attendance totals for the current business day.',
    permission: 'hr.attendance.view|reports.hr.view',
    fields: [
      ['records', 'Records', 'number'],
      ['present', 'Present', 'number'],
      ['late', 'Late', 'number'],
      ['absent', 'Absent', 'number']
    ]
  },
  {
    key: 'payroll_summary',
    title: 'Payroll summary',
    description: 'Latest payroll period and outstanding obligation.',
    permission: 'payroll.manage|reports.payroll.view',
    fields: [
      ['period_start', 'Period start', 'date'],
      ['period_end', 'Period end', 'date'],
      ['period_records', 'Period records', 'number'],
      ['period_net_pay', 'Period net pay', 'money'],
      ['outstanding_records', 'Outstanding records', 'number'],
      ['outstanding_obligation', 'Outstanding obligation', 'money']
    ]
  },
  {
    key: 'system_health',
    title: 'System health',
    description: 'Current application and database health signals.',
    permission: 'system.settings.manage|system.backups.manage',
    fields: [
      ['environment', 'Environment'],
      ['debug', 'Debug mode', 'boolean'],
      ['database_integrity', 'Database integrity'],
      ['foreign_key_violations', 'Foreign-key violations', 'number'],
      ['cache_driver', 'Cache driver'],
      ['queue_driver', 'Queue driver']
    ]
  }
]

const quickActionDefinitions = [
  ['Manage users', '/admin', 'system.users.manage'],
  ['Review finance requests', '/finance', 'finance.requests.approve'],
  ['Review restock requests', '/restock-requests', 'restock.approve'],
  ['Receive stock', '/stock-receiving', 'procurement.stock.receive'],
  ['Review inventory', '/inventory', 'inventory.manage'],
  ['Review HR requests', '/hr-requests', 'hr.requests.approve'],
  ['Open Point of Sale', '/pos', 'pos.access'],
  ['Open self-service', '/self-service', 'employee.self'],
  ['Open reports', '/reports', 'reports.sales.view|reports.inventory.view|reports.procurement.view|reports.hr.view|reports.payroll.view|reports.finance.view']
]

const hasOwn = (object, key) => Object.prototype.hasOwnProperty.call(object || {}, key)

const authorizedMetrics = computed(() => (dashboard.value?.metrics || []).filter(metric =>
  metricPermissions[metric.key]
    && sessionStore.can(metricPermissions[metric.key])
    && metric.value !== null
    && metric.value !== undefined
))

const metricMap = computed(() => new Map(
  authorizedMetrics.value.map(metric => [metric.key, metric])
))

const listSections = computed(() => listSectionDefinitions
  .filter(definition =>
    sessionStore.can(definition.permission)
      && hasOwn(dashboard.value?.sections, definition.key)
      && Array.isArray(dashboard.value.sections[definition.key])
  )
  .map(definition => ({
    ...definition,
    rows: dashboard.value.sections[definition.key]
  })))

const summarySections = computed(() => summarySectionDefinitions
  .filter(definition =>
    sessionStore.can(definition.permission)
      && hasOwn(dashboard.value?.sections, definition.key)
  )
  .map(definition => ({
    ...definition,
    data: dashboard.value.sections[definition.key]
  })))

const salesChart = computed(() => {
  if (!sessionStore.can('pos.access|sales.view|reports.sales.view')) return null
  return hasOwn(dashboard.value?.charts, 'sales_last_7_days')
    ? dashboard.value.charts.sales_last_7_days
    : null
})

const inventorySummary = computed(() => [
  'total_products',
  'low_stock',
  'out_of_stock',
  'pending_restock',
  'ready_for_receiving'
].map(key => metricMap.value.get(key)).filter(Boolean))

const approvalQueues = computed(() => [
  'pending_hr_requests',
  'submitted_purchase_orders',
  'approved_restock_requests'
].map(key => metricMap.value.get(key)).filter(Boolean))

const accountsReceivable = computed(() =>
  sessionStore.can('finance.manage|reports.finance.view')
    && hasOwn(dashboard.value?.sections, 'accounts_receivable')
    ? dashboard.value.sections.accounts_receivable
    : null
)

const employeeSection = computed(() =>
  sessionStore.can('employee.self')
    && hasOwn(dashboard.value?.sections, 'employee')
    ? dashboard.value.sections.employee
    : null
)

const quickActions = computed(() => quickActionDefinitions
  .filter(([, , permission]) => sessionStore.can(permission))
  .map(([label, to]) => ({ label, to })))

const primaryQuickAction = computed(() => quickActions.value[0] || null)

const currentDate = computed(() => {
  if (!dashboard.value?.generated_at) return ''
  const parsed = new Date(dashboard.value.generated_at)
  if (Number.isNaN(parsed.getTime())) return ''
  return new Intl.DateTimeFormat(
    dashboard.value.settings?.currency_locale || 'en-PH',
    {
      dateStyle: 'full',
      timeZone: dashboard.value.timezone || dashboard.value.settings?.timezone || 'Asia/Manila'
    }
  ).format(parsed)
})

const greeting = computed(() => {
  if (!dashboard.value?.generated_at) return 'Dashboard'
  const parsed = new Date(dashboard.value.generated_at)
  const hour = Number(new Intl.DateTimeFormat('en', {
    hour: 'numeric',
    hourCycle: 'h23',
    timeZone: dashboard.value.timezone || 'Asia/Manila'
  }).format(parsed))
  const salutation = hour < 12 ? 'Good morning' : hour < 18 ? 'Good afternoon' : 'Good evening'
  return `${salutation}, ${sessionStore.state.user?.fullName || sessionStore.state.user?.username || 'FreshMart user'}`
})

const intro = computed(() => {
  if (sessionStore.can('system.users.manage|system.audit.view|system.settings.manage')) {
    return 'Monitor authorized system, operational, and business activity from one overview.'
  }
  if (sessionStore.can('finance.manage|finance.requests.approve|reports.finance.view')) {
    return 'Review the financial activity and requests available to your account.'
  }
  if (sessionStore.can('inventory.manage|restock.approve|procurement.stock.receive')) {
    return 'Track stock, procurement, and replenishment activity available to your account.'
  }
  if (sessionStore.can('hr.employees.view|hr.requests.approve|payroll.manage')) {
    return 'Review workforce activity and pending work available to your account.'
  }
  if (sessionStore.can('pos.access')) {
    return 'Review your authorized sales activity and continue serving customers.'
  }
  if (sessionStore.can('employee.self')) {
    return 'Review the personal employment information available to your account.'
  }
  return 'Review the information available to your account.'
})

async function load() {
  loading.value = true
  error.value = ''
  dashboard.value = null
  try {
    dashboard.value = await api.get('/dashboard')
    if (dashboard.value.settings) sessionStore.updateSettings(dashboard.value.settings)
  } catch (requestError) {
    error.value = requestError.requestId
      ? `${requestError.message} Reference: ${requestError.requestId}`
      : requestError.message
  } finally {
    loading.value = false
  }
}

function metricValue(metric) {
  return metric.format === 'money'
    ? formatMoney(metric.value)
    : formatNumber(metric.value)
}

function displayValue(value, format = '') {
  if (value === null || value === undefined || value === '') return '—'
  if (format === 'money') return formatMoney(value)
  if (format === 'number') return formatNumber(value)
  if (format === 'datetime') return formatDateTime(value)
  if (format === 'date') {
    const parsed = new Date(String(value).replace(' ', 'T'))
    if (Number.isNaN(parsed.getTime())) return value
    return new Intl.DateTimeFormat(
      dashboard.value?.settings?.currency_locale || 'en-PH',
      {
        dateStyle: 'medium',
        timeZone: dashboard.value?.timezone || 'Asia/Manila'
      }
    ).format(parsed)
  }
  if (format === 'boolean') return value ? 'Yes' : 'No'
  return value
}

function rowKey(row, index) {
  return row.id || row.order_id || row.employee_no || row.name || index
}

onMounted(load)
</script>

<template>
  <div class="fm-dashboard">
    <UiPageHeader
      :title="greeting"
      :eyebrow="currentDate"
      :description="intro"
    >
      <template v-if="dashboard && primaryQuickAction" #actions>
        <RouterLink class="ui-button ui-button--primary" :to="primaryQuickAction.to">
          {{ primaryQuickAction.label }}
        </RouterLink>
      </template>
    </UiPageHeader>

    <section v-if="loading" class="ui-card fm-dashboard__request-state">
      <UiLoadingSkeleton :rows="8" label="Loading dashboard" />
    </section>

    <UiErrorState
      v-else-if="error"
      title="Unable to load the dashboard"
      :message="error"
      :retrying="loading"
      @retry="load"
    />

    <template v-else-if="dashboard">
      <section v-if="authorizedMetrics.length" class="fm-dashboard__kpis" aria-label="Key metrics">
        <UiKpiCard
          v-for="metric in authorizedMetrics"
          :key="metric.key"
          :label="metric.label"
          :value="metricValue(metric)"
        />
      </section>

      <section v-if="salesChart" class="fm-dashboard__wide-section">
        <UiSectionCard
          title="Sales trend"
          description="Authorized finalized sales totals from the dashboard response."
        >
          <MetricChart :chart="salesChart" />
        </UiSectionCard>
      </section>

      <section
        v-if="inventorySummary.length || approvalQueues.length || summarySections.length || accountsReceivable || employeeSection"
        class="fm-dashboard__grid"
        aria-label="Dashboard summaries"
      >
        <UiSectionCard
          v-if="inventorySummary.length"
          title="Inventory and low stock"
          description="Current authorized inventory totals."
        >
          <dl class="fm-dashboard__summary-list">
            <template v-for="metric in inventorySummary" :key="metric.key">
              <dt>{{ metric.label }}</dt>
              <dd>{{ metricValue(metric) }}</dd>
            </template>
          </dl>
        </UiSectionCard>

        <UiSectionCard
          v-if="approvalQueues.length"
          title="Approval queues"
          description="Current authorized work awaiting attention."
        >
          <dl class="fm-dashboard__summary-list">
            <template v-for="metric in approvalQueues" :key="metric.key">
              <dt>{{ metric.label }}</dt>
              <dd>{{ metricValue(metric) }}</dd>
            </template>
          </dl>
        </UiSectionCard>

        <UiSectionCard
          v-for="section in summarySections"
          :key="section.key"
          :title="section.title"
          :description="section.description"
        >
          <dl class="fm-dashboard__summary-list">
            <template v-for="[key, label, format] in section.fields" :key="key">
              <template v-if="hasOwn(section.data, key)">
                <dt>{{ label }}</dt>
                <dd>{{ displayValue(section.data[key], format) }}</dd>
              </template>
            </template>
          </dl>
        </UiSectionCard>

        <UiSectionCard
          v-if="accountsReceivable"
          title="Accounts receivable"
          description="Receivables information exposed by the dashboard service."
        >
          <UiEmptyState
            v-if="accountsReceivable.supported === false"
            title="Accounts receivable is not available"
            :description="accountsReceivable.message || 'This information is not represented by the current system.'"
          />
          <dl v-else class="fm-dashboard__summary-list">
            <dt>Total</dt>
            <dd>{{ displayValue(accountsReceivable.total, 'money') }}</dd>
          </dl>
        </UiSectionCard>

        <UiSectionCard
          v-if="employeeSection"
          title="Employee overview"
          description="Personal employment information linked to your account."
        >
          <UiEmptyState
            v-if="employeeSection.linked === false"
            title="Employee profile is not available"
            description="Your account is not linked to an employee record."
          />
          <template v-else>
            <dl class="fm-dashboard__summary-list">
              <template v-if="hasOwn(employeeSection, 'leave_balance')">
                <dt>Leave balance</dt>
                <dd>{{ displayValue(employeeSection.leave_balance, 'number') }}</dd>
              </template>
              <template v-if="hasOwn(employeeSection, 'schedule_supported')">
                <dt>Schedule</dt>
                <dd>{{ employeeSection.schedule_supported ? 'Available' : 'Not available' }}</dd>
              </template>
            </dl>
            <dl v-if="employeeSection.attendance_summary" class="fm-dashboard__summary-list">
              <template v-for="(value, key) in employeeSection.attendance_summary" :key="key">
                <dt>{{ key.replaceAll('_', ' ') }}</dt>
                <dd>{{ displayValue(value, 'number') }}</dd>
              </template>
            </dl>
            <section v-if="hasOwn(employeeSection, 'current_payroll')" class="fm-dashboard__subsection">
              <h3>Current payroll</h3>
              <UiEmptyState
                v-if="!employeeSection.current_payroll"
                title="No payroll record"
                description="No payroll record is available for the linked employee."
              />
              <dl v-else class="fm-dashboard__summary-list">
                <dt>Period start</dt>
                <dd>{{ displayValue(employeeSection.current_payroll.pay_period_start, 'date') }}</dd>
                <dt>Period end</dt>
                <dd>{{ displayValue(employeeSection.current_payroll.pay_period_end, 'date') }}</dd>
                <dt>Net pay</dt>
                <dd>{{ displayValue(employeeSection.current_payroll.net_pay, 'money') }}</dd>
                <dt>Status</dt>
                <dd>{{ displayValue(employeeSection.current_payroll.status) }}</dd>
              </dl>
            </section>
            <section v-if="Array.isArray(employeeSection.recent_hr_requests)" class="fm-dashboard__subsection">
              <h3>Recent HR requests</h3>
              <UiEmptyState
                v-if="employeeSection.recent_hr_requests.length === 0"
                title="No recent HR requests"
                description="No HR requests are available for the linked employee."
              />
              <div v-else class="ui-table-shell__scroll">
                <table class="fm-dashboard__table">
                  <thead>
                    <tr>
                      <th scope="col">Date</th>
                      <th scope="col">Type</th>
                      <th scope="col">Status</th>
                      <th scope="col">Reason</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="request in employeeSection.recent_hr_requests" :key="request.id">
                      <td>{{ displayValue(request.created_at, 'datetime') }}</td>
                      <td>{{ displayValue(request.request_type) }}</td>
                      <td>{{ displayValue(request.status) }}</td>
                      <td>{{ displayValue(request.reason) }}</td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </section>
          </template>
        </UiSectionCard>
      </section>

      <section v-if="listSections.length" class="fm-dashboard__tables" aria-label="Recent dashboard activity">
        <UiTableShell
          v-for="section in listSections"
          :key="section.key"
          :title="section.title"
          :description="section.description"
          :empty="section.rows.length === 0"
          :empty-title="section.emptyTitle"
          :empty-description="section.emptyDescription"
        >
          <table class="fm-dashboard__table">
            <thead>
              <tr>
                <th v-for="[key, label] in section.columns" :key="key" scope="col">{{ label }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(row, index) in section.rows" :key="rowKey(row, index)">
                <td v-for="[key, , format] in section.columns" :key="key">
                  {{ displayValue(row[key], format) }}
                </td>
              </tr>
            </tbody>
          </table>
        </UiTableShell>
      </section>

      <UiSectionCard
        v-if="quickActions.length"
        title="Quick actions"
        description="Open a workspace available to your account."
      >
        <div class="fm-dashboard__actions">
          <RouterLink
            v-for="action in quickActions"
            :key="action.to"
            class="fm-dashboard__action"
            :to="action.to"
          >
            <span>{{ action.label }}</span>
            <span aria-hidden="true">→</span>
          </RouterLink>
        </div>
      </UiSectionCard>
    </template>
  </div>
</template>
