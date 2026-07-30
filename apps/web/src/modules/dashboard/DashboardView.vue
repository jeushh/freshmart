<script setup>
import { computed } from 'vue'
import PageHeader from '../../components/common/PageHeader.vue'
import { sessionStore } from '../../stores/session.js'

const modules = [
  ['Point of Sale', 'Process customer transactions.', '/pos', 'pos.access'],
  ['Employees', 'Manage employee records.', '/employees', 'hr.employees.view'],
  ['Attendance', 'Review time records.', '/attendance', 'hr.attendance.view'],
  ['HR Requests', 'Review leave and overtime requests.', '/hr-requests', 'hr.requests.view'],
  ['Payroll', 'Prepare payroll periods.', '/payroll', 'payroll.manage'],
  ['Inventory', 'Control products and stock.', '/inventory', 'inventory.manage|restock.approve'],
  ['Restock Requests', 'Request and approve replenishment.', '/restock-requests', 'restock.request|restock.approve'],
  ['Purchase Orders', 'Order and receive supplier stock.', '/purchase-orders', 'procurement.purchase_orders.view'],
  ['Finance', 'Review requests and payments.', '/finance', 'finance.requests.view|finance.manage'],
  ['Self-Service', 'View your profile and submit requests.', '/self-service', 'employee.self'],
  ['Administration', 'Manage user accounts and audit activity.', '/admin', 'system.users.manage|system.audit.view'],
  ['Roles & Permissions', 'Configure role-based access.', '/roles', 'system.roles.manage'],
  ['System Settings', 'Manage approved application settings.', '/settings', 'system.settings.manage']
]
const visible = computed(() => modules.filter(module => sessionStore.can(module[3])))
</script>

<template>
  <PageHeader title="Dashboard" description="Choose a workspace to continue." />
  <div class="module-grid">
    <RouterLink v-for="module in visible" :key="module[2]" :to="module[2]" class="module-card">
      <h2>{{ module[0] }}</h2>
      <p>{{ module[1] }}</p>
      <span>Open module →</span>
    </RouterLink>
  </div>
</template>
