import { createRouter, createWebHashHistory } from 'vue-router'
import { sessionStore } from '../stores/session.js'
import LoginView from '../modules/auth/LoginView.vue'
import AppLayout from '../layouts/AppLayout.vue'
import DashboardView from '../modules/dashboard/DashboardView.vue'
import EmployeesView from '../modules/employees/EmployeesView.vue'
import AttendanceView from '../modules/workspaces/AttendanceView.vue'
import PayrollView from '../modules/workspaces/PayrollView.vue'
import HrRequestsView from '../modules/workspaces/HrRequestsView.vue'
import InventoryView from '../modules/workspaces/InventoryView.vue'
import RestockRequestsView from '../modules/workspaces/RestockRequestsView.vue'
import PurchaseOrdersView from '../modules/workspaces/PurchaseOrdersView.vue'
import StockReceivingView from '../modules/workspaces/StockReceivingView.vue'
import FinanceView from '../modules/workspaces/FinanceView.vue'
import FinanceRequestsView from '../modules/workspaces/FinanceRequestsView.vue'
import FinanceTransactionsView from '../modules/workspaces/FinanceTransactionsView.vue'
import SupplierInvoicesView from '../modules/workspaces/SupplierInvoicesView.vue'
import SupplierInvoiceCreateView from '../modules/workspaces/SupplierInvoiceCreateView.vue'
import SupplierInvoiceDetailView from '../modules/workspaces/SupplierInvoiceDetailView.vue'
import AccountsPayableView from '../modules/workspaces/AccountsPayableView.vue'
import AccountsPayableDetailView from '../modules/workspaces/AccountsPayableDetailView.vue'
import PosView from '../modules/workspaces/PosView.vue'
import AdminView from '../modules/workspaces/AdminView.vue'
import RolesView from '../modules/workspaces/RolesView.vue'
import SettingsView from '../modules/workspaces/SettingsView.vue'
import SelfServiceView from '../modules/workspaces/SelfServiceView.vue'
import ForbiddenView from '../modules/errors/ForbiddenView.vue'
import NotFoundView from '../modules/errors/NotFoundView.vue'
import ReportsView from '../modules/reports/ReportsView.vue'

const routes = [
  { path: '/login', component: LoginView, meta: { public: true } },
  {
    path: '/',
    component: AppLayout,
    children: [
      { path: '', component: DashboardView },
      { path: 'employees', component: EmployeesView, meta: { permission: 'hr.employees.view' } },
      { path: 'attendance', component: AttendanceView, meta: { permission: 'hr.attendance.view' } },
      { path: 'hr-requests', component: HrRequestsView, meta: { permission: 'hr.requests.view' } },
      { path: 'payroll', component: PayrollView, meta: { permission: 'payroll.manage' } },
      { path: 'inventory', component: InventoryView, meta: { permission: 'inventory.manage|restock.approve' } },
      { path: 'inventory/low-stock', component: InventoryView, meta: { permission: 'inventory.manage' } },
      { path: 'inventory/movements', component: InventoryView, meta: { permission: 'inventory.manage' } },
      { path: 'restock-requests', component: RestockRequestsView, meta: { permission: 'restock.request|restock.approve' } },
      {
        path: 'purchase-orders',
        component: PurchaseOrdersView,
        meta: { permission: 'procurement.purchase_orders.view' }
      },
      {
        path: 'stock-receiving',
        component: StockReceivingView,
        meta: { permission: 'procurement.stock.receive' }
      },
      { path: 'finance', component: FinanceView, meta: { permission: 'finance.requests.view|finance.manage' } },
      { path: 'finance/requests', component: FinanceRequestsView, meta: { permission: 'finance.requests.view' } },
      { path: 'finance/transactions', component: FinanceTransactionsView, meta: { permission: 'finance.manage' } },
      { path: 'finance/supplier-invoices', component: SupplierInvoicesView, meta: { permission: 'finance.manage' } },
      { path: 'finance/supplier-invoices/new', component: SupplierInvoiceCreateView, meta: { permission: 'finance.manage' } },
      { path: 'finance/supplier-invoices/:invoiceId', component: SupplierInvoiceDetailView, meta: { permission: 'finance.manage' } },
      { path: 'finance/accounts-payable', component: AccountsPayableView, meta: { permission: 'finance.manage' } },
      { path: 'finance/accounts-payable/:payableId', component: AccountsPayableDetailView, meta: { permission: 'finance.manage' } },
      { path: 'pos', component: PosView, meta: { permission: 'pos.access' } },
      {
        path: 'admin',
        component: AdminView,
        meta: { permission: 'system.users.manage|system.audit.view' }
      },
      { path: 'roles', component: RolesView, meta: { permission: 'system.roles.manage' } },
      { path: 'settings', component: SettingsView, meta: { permission: 'system.settings.manage' } },
      {
        path: 'reports',
        component: ReportsView,
        meta: {
          permission: 'reports.sales.view|reports.inventory.view|reports.procurement.view|reports.hr.view|reports.payroll.view|reports.finance.view'
        }
      },
      { path: 'forbidden', component: ForbiddenView },
      { path: 'self-service', component: SelfServiceView, meta: { permission: 'employee.self' } }
    ]
  },
  { path: '/:pathMatch(.*)*', component: NotFoundView }
]

const router = createRouter({ history: createWebHashHistory(), routes })

router.beforeEach(async to => {
  if (sessionStore.state.loading) await sessionStore.refresh()
  if (!to.meta.public && !sessionStore.state.authenticated) return '/login'
  if (to.path === '/login' && sessionStore.state.authenticated) return sessionStore.homePath()
  if (to.path === '/finance' && sessionStore.can('finance.requests.view') && !sessionStore.can('finance.manage')) {
    return { path: '/finance/requests', replace: true }
  }
  if (to.meta.permission && !sessionStore.can(to.meta.permission)) return '/forbidden'
})

export default router
