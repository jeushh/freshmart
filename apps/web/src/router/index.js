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
import FinanceView from '../modules/workspaces/FinanceView.vue'
import PosView from '../modules/workspaces/PosView.vue'
import AdminView from '../modules/workspaces/AdminView.vue'
import RolesView from '../modules/workspaces/RolesView.vue'
import SettingsView from '../modules/workspaces/SettingsView.vue'
import SelfServiceView from '../modules/workspaces/SelfServiceView.vue'

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
      { path: 'restock-requests', component: RestockRequestsView, meta: { permission: 'restock.request|restock.approve' } },
      {
        path: 'purchase-orders',
        component: PurchaseOrdersView,
        meta: { permission: 'procurement.purchase_orders.view' }
      },
      { path: 'finance', component: FinanceView, meta: { permission: 'finance.requests.view|finance.manage' } },
      { path: 'pos', component: PosView, meta: { permission: 'pos.access' } },
      {
        path: 'admin',
        component: AdminView,
        meta: { permission: 'system.users.manage|system.audit.view' }
      },
      { path: 'roles', component: RolesView, meta: { permission: 'system.roles.manage' } },
      { path: 'settings', component: SettingsView, meta: { permission: 'system.settings.manage' } },
      { path: 'self-service', component: SelfServiceView, meta: { permission: 'employee.self' } }
    ]
  }
]

const router = createRouter({ history: createWebHashHistory(), routes })

router.beforeEach(async to => {
  if (sessionStore.state.loading) await sessionStore.refresh()
  if (!to.meta.public && !sessionStore.state.authenticated) return '/login'
  if (to.path === '/login' && sessionStore.state.authenticated) return sessionStore.homePath()
  if (to.meta.permission && !sessionStore.can(to.meta.permission)) return sessionStore.homePath()
})

export default router
