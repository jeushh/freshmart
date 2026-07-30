<script setup>
import { computed } from 'vue'
import { sessionStore } from '../../stores/session.js'
defineProps({ open: Boolean })
defineEmits(['close'])
const items = [
  ['Dashboard','/',''], ['Point of Sale','/pos','pos.access'], ['Employees','/employees','hr.employees.view'],
  ['Attendance','/attendance','hr.attendance.view'], ['HR Requests','/hr-requests','hr.requests.view'],
  ['Payroll','/payroll','payroll.manage'], ['Inventory','/inventory','inventory.manage|restock.approve'],
  ['Restock Requests','/restock-requests','restock.request|restock.approve'],
  ['Purchase Orders','/purchase-orders','procurement.purchase_orders.view'],
  ['Reports','/reports','reports.sales.view|reports.inventory.view|reports.procurement.view|reports.hr.view|reports.payroll.view|reports.finance.view'],
  ['Finance','/finance','finance.requests.view|finance.manage'], ['Self-Service','/self-service','employee.self'],
  ['Administration','/admin','system.users.manage|system.audit.view'],
  ['Roles & Permissions','/roles','system.roles.manage'], ['System Settings','/settings','system.settings.manage']
]
const visible = computed(() => items.filter(([, , p]) => !p || sessionStore.can(p)))
</script>
<template>
  <div v-if="open" class="sidebar-backdrop" @click="$emit('close')"></div>
  <aside class="sidebar" :class="{ open }">
    <div class="brand"><span class="brand-mark">F</span><div><strong>FreshMart</strong><small>Business System</small></div></div>
    <nav aria-label="Main navigation">
      <RouterLink v-for="([label,path]) in visible" :key="path" :to="path" @click="$emit('close')">{{ label }}</RouterLink>
    </nav>
  </aside>
</template>
