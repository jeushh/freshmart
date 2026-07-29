import { reactive, readonly } from 'vue'
import { api } from '../api/http.js'

const state = reactive({ loading: true, authenticated: false, user: null, permissions: [], landingPage: 'dashboard' })

async function refresh() {
  state.loading = true
  try {
    const data = await api.session()
    state.authenticated = Boolean(data.authenticated)
    state.user = data.authenticated ? { username: data.username, fullName: data.full_name, employeeId: data.employee_id } : null
    state.permissions = data.permissions || []
    state.landingPage = data.landing_page || 'dashboard'
  } catch (error) {
    state.authenticated = false
    state.user = null
    state.permissions = []
    console.error('Session check failed:', error)
  } finally { state.loading = false }
  return state.authenticated
}
async function login(username, password) { await api.login(username, password); return refresh() }
async function logout() { try { await api.logout() } finally { state.authenticated = false; state.user = null; state.permissions = [] } }
function can(permission) { return String(permission).split('|').some(p => state.permissions.includes(p)) }
function homePath() {
  const landing = {
    admin: ['/admin', 'system.users.manage|system.roles.manage|system.audit.view|system.settings.manage'],
    pos: ['/pos', 'pos.access'],
    hr: ['/employees', 'hr.employees.view'],
    finance: ['/finance', 'finance.requests.view|finance.manage'],
    employee: ['/self-service', 'employee.self'],
    inventory: ['/inventory', 'inventory.manage|restock.approve']
  }[state.landingPage]
  return landing && can(landing[1]) ? landing[0] : '/'
}
export const sessionStore = { state: readonly(state), refresh, login, logout, can, homePath }
