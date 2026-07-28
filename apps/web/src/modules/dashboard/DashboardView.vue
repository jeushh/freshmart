<script setup>
import { computed } from 'vue'; import PageHeader from '../../components/common/PageHeader.vue'; import { sessionStore } from '../../stores/session.js'
const modules=[['Point of Sale','Process customer transactions.','/pos','pos.access'],['Employees','Manage employee records.','/employees','hr.employees.view'],['Attendance','Review time records.','/attendance','hr.attendance.view'],['Payroll','Prepare payroll periods.','/payroll','payroll.manage'],['Inventory','Control products and stock.','/inventory','inventory.manage|restock.approve'],['Finance','Review requests and payments.','/finance','finance.manage'],['Administration','Manage access and settings.','/admin','system.roles.manage']]
const visible=computed(()=>modules.filter(m=>sessionStore.can(m[3])))
</script>
<template><PageHeader title="Dashboard" description="Choose a workspace to continue."/><div class="module-grid"><RouterLink v-for="m in visible" :key="m[2]" :to="m[2]" class="module-card"><h2>{{m[0]}}</h2><p>{{m[1]}}</p><span>Open module →</span></RouterLink></div></template>
