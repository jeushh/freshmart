<script setup>
import { computed, onMounted, ref } from 'vue'
import { api } from '../../api/http.js'
import MetricChart from '../../components/common/MetricChart.vue'
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

const primaryMetricKeys = [
  'active_users',
  'today_sales',
  'today_transactions',
  'active_employees',
  'month_sales',
  'pending_hr_requests',
  'outstanding_payroll',
  'low_stock'
]

const salesFinanceMetricKeys = [
  'today_revenue',
  'month_revenue',
  'month_expenses',
  'month_refunds',
  'net_movement',
  'average_transaction',
  'accounts_payable'
]

const inventoryPurchasingMetricKeys = [
  'total_products',
  'out_of_stock',
  'pending_restock',
  'ready_for_receiving',
  'submitted_purchase_orders',
  'partially_received_orders',
  'fully_received_orders',
  'approved_restock_requests'
]

const metricLabelOverrides = {
  today_revenue: "Today's cash revenue"
}

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
    column: 0,
    order: 4,
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
    column: 1,
    order: 5,
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
    column: 0,
    order: 6,
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
    column: 1,
    order: 7,
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
    column: 0,
    order: 8,
    title: 'Low leave balances',
    description: 'Employees whose available leave balance is low.',
    permission: 'hr.employees.view|reports.hr.view',
    emptyTitle: 'No low leave balances',
    emptyDescription: 'No employees currently meet the low-balance threshold.',
    compactEmptyText: 'No employees currently meet the low-balance threshold.',
    columns: [
      ['employee_no', 'Employee number'],
      ['full_name', 'Employee'],
      ['leave_balance', 'Leave balance', 'number']
    ]
  },
  {
    key: 'supplier_activity',
    column: 1,
    order: 9,
    title: 'Supplier activity',
    description: 'Recent purchase-order activity grouped by supplier.',
    permission: 'restock.approve|procurement.purchase_orders.approve|reports.procurement.view',
    emptyTitle: 'No supplier activity',
    emptyDescription: 'No purchase-order activity is currently recorded.',
    compactEmptyText: 'No recent supplier activity.',
    columns: [
      ['name', 'Supplier'],
      ['purchase_orders', 'Purchase orders', 'number'],
      ['latest_order', 'Latest order', 'date']
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

const primaryMetrics = computed(() => metricRows(primaryMetricKeys))

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

const salesChart = computed(() => {
  if (!sessionStore.can('pos.access|sales.view|reports.sales.view')) return null
  return hasOwn(dashboard.value?.charts, 'sales_last_7_days')
    ? dashboard.value.charts.sales_last_7_days
    : null
})

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

const linkedEmployeeSection = computed(() =>
  employeeSection.value?.linked === true ? employeeSection.value : null
)

const secondaryPanels = computed(() => [
  {
    key: 'sales-finance',
    column: 0,
    order: 0,
    title: 'Sales & Finance',
    description: 'Authorized sales and financial totals in one compact view.',
    groups: [
      {
        key: 'sales-finance-summary',
        rows: [
          ...metricRows(salesFinanceMetricKeys),
          ...accountsReceivableRows()
        ]
      }
    ]
  },
  {
    key: 'human-resources',
    column: 1,
    order: 1,
    title: 'Human Resources',
    description: 'Authorized workforce, attendance, and payroll details.',
    groups: [
      {
        key: 'workforce',
        title: 'Workforce',
        rows: metricRows(['employees_on_leave'])
      },
      {
        key: 'attendance',
        title: 'Attendance today',
        rows: sectionRows(
          'attendance_today',
          'hr.attendance.view|reports.hr.view',
          [
            ['records', 'Records', 'number'],
            ['present', 'Present', 'number'],
            ['late', 'Late', 'number'],
            ['absent', 'Absent', 'number']
          ]
        )
      },
      {
        key: 'payroll',
        title: 'Payroll summary',
        rows: sectionRows(
          'payroll_summary',
          'payroll.manage|reports.payroll.view',
          [
            ['period_start', 'Period start', 'date'],
            ['period_end', 'Period end', 'date'],
            ['period_records', 'Period records', 'number'],
            ['period_net_pay', 'Period net pay', 'money'],
            ['outstanding_records', 'Outstanding records', 'number']
          ]
        )
      }
    ]
  },
  {
    key: 'inventory-purchasing',
    column: 0,
    order: 2,
    title: 'Inventory & Purchasing',
    description: 'Authorized stock and procurement totals.',
    groups: [
      {
        key: 'inventory-purchasing-summary',
        rows: metricRows(inventoryPurchasingMetricKeys)
      }
    ]
  },
  {
    key: 'system-health',
    column: 1,
    order: 3,
    title: 'System Health',
    description: 'Current authorized application, database, and access signals.',
    groups: [
      {
        key: 'system-health-summary',
        rows: [
          ...metricRows(['roles']),
          ...sectionRows(
            'system_health',
            'system.settings.manage|system.backups.manage',
            [
              ['environment', 'Environment'],
              ['debug', 'Debug mode', 'boolean'],
              ['database_integrity', 'Database integrity'],
              ['foreign_key_violations', 'Foreign-key violations', 'number'],
              ['cache_driver', 'Cache driver'],
              ['queue_driver', 'Queue driver']
            ]
          )
        ]
      }
    ]
  }
].map(panel => ({
  ...panel,
  groups: panel.groups.filter(group => group.rows.length)
})).filter(panel => panel.groups.length))

const dashboardColumns = computed(() => {
  const items = [
    ...secondaryPanels.value.map(panel => ({ ...panel, type: 'summary' })),
    ...listSections.value.map(section => ({ ...section, type: 'table' }))
  ]
  return [0, 1]
    .map(column => items.filter(item => item.column === column))
    .filter(column => column.length)
})

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

function metricRows(keys) {
  return keys
    .map(key => metricMap.value.get(key))
    .filter(Boolean)
    .map(metric => ({
      key: metric.key,
      label: metricLabelOverrides[metric.key] || metric.label,
      value: metricValue(metric)
    }))
}

function sectionRows(key, permission, fields) {
  if (!sessionStore.can(permission) || !hasOwn(dashboard.value?.sections, key)) return []
  const data = dashboard.value.sections[key]
  return fields
    .filter(([field]) => hasOwn(data, field))
    .map(([field, label, format]) => ({
      key: `${key}-${field}`,
      label,
      value: displayValue(data[field], format)
    }))
}

function accountsReceivableRows() {
  if (!accountsReceivable.value) return []
  if (accountsReceivable.value.supported === false) {
    return [{
      key: 'accounts-receivable',
      label: 'Accounts receivable',
      value: 'Not available',
      note: accountsReceivable.value.message || 'This information is not represented by the current system.'
    }]
  }
  return [{
    key: 'accounts-receivable',
    label: 'Accounts receivable',
    value: displayValue(accountsReceivable.value.total, 'money')
  }]
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
      <section v-if="primaryMetrics.length" class="fm-dashboard__kpis" aria-label="Key metrics">
        <UiKpiCard
          v-for="metric in primaryMetrics"
          :key="metric.key"
          :label="metric.label"
          :value="metric.value"
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
        v-if="dashboardColumns.length"
        class="fm-dashboard__columns"
        :class="{ 'fm-dashboard__columns--single': dashboardColumns.length === 1 }"
        aria-label="Dashboard summaries and recent activity"
      >
        <div
          v-for="column in dashboardColumns"
          :key="column[0].column"
          class="fm-dashboard__column"
        >
          <template v-for="item in column" :key="item.key">
            <UiSectionCard
              v-if="item.type === 'summary'"
              class="fm-dashboard__column-card fm-dashboard__summary-card"
              :style="{ '--fm-dashboard-order': item.order }"
              :title="item.title"
              :description="item.description"
            >
              <div class="fm-dashboard__summary-groups">
                <section
                  v-for="group in item.groups"
                  :key="group.key"
                  class="fm-dashboard__summary-group"
                >
                  <h3 v-if="group.title" class="fm-dashboard__summary-title">{{ group.title }}</h3>
                  <dl class="fm-dashboard__summary-list">
                    <template v-for="row in group.rows" :key="row.key">
                      <dt>{{ row.label }}</dt>
                      <dd>
                        <span>{{ row.value }}</span>
                        <small v-if="row.note">{{ row.note }}</small>
                      </dd>
                    </template>
                  </dl>
                </section>
              </div>
            </UiSectionCard>

            <UiTableShell
              v-else
              class="fm-dashboard__column-card fm-dashboard__table-card"
              :style="{ '--fm-dashboard-order': item.order }"
              :title="item.title"
              :description="item.description"
              :empty="item.rows.length === 0 && !item.compactEmptyText"
              :empty-title="item.emptyTitle"
              :empty-description="item.emptyDescription"
            >
              <table v-if="item.rows.length" class="fm-dashboard__table">
                <thead>
                  <tr>
                    <th v-for="[key, label] in item.columns" :key="key" scope="col">{{ label }}</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="(row, rowIndex) in item.rows" :key="rowKey(row, rowIndex)">
                    <td v-for="[key, , format] in item.columns" :key="key">
                      {{ displayValue(row[key], format) }}
                    </td>
                  </tr>
                </tbody>
              </table>
              <p v-else class="fm-dashboard__compact-empty">{{ item.compactEmptyText }}</p>
            </UiTableShell>
          </template>
        </div>
      </section>

      <section v-if="linkedEmployeeSection" class="ui-card fm-dashboard__employee-strip">
        <div>
          <p class="fm-dashboard__employee-eyebrow">Employee profile linked</p>
          <h2>Personal details are available in Self-Service</h2>
          <p>
            <template v-if="hasOwn(linkedEmployeeSection, 'leave_balance')">
              Current leave balance: {{ displayValue(linkedEmployeeSection.leave_balance, 'number') }}.
            </template>
            Review attendance, payroll, and HR requests in your personal workspace.
          </p>
        </div>
        <RouterLink class="ui-button ui-button--secondary" to="/self-service">
          Open self-service
        </RouterLink>
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
