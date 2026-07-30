<script setup>
import { onMounted, ref } from 'vue'
import { api } from '../../api/http.js'
import { sessionStore } from '../../stores/session.js'
import PageHeader from '../../components/common/PageHeader.vue'
import WorkspaceTable from '../../components/common/WorkspaceTable.vue'

const rows = ref([])
const employees = ref([])
const filters = ref({ status: '', request_type: '', employee_id: '', from: '', to: '' })
const reviewNotes = ref('')
const error = ref('')
const message = ref('')

async function load() {
  try {
    error.value = ''
    const params = Object.fromEntries(Object.entries(filters.value).filter(([, value]) => value))
    const data = await api.get('/hr/requests', { ...params, per_page: 100 })
    rows.value = data.requests.data
    employees.value = data.employees
  } catch (requestError) {
    error.value = requestError.message
  }
}

async function review(id, decision) {
  try {
    error.value = ''
    message.value = ''
    await api.post(`/hr/requests/${id}/review`, { decision, notes: reviewNotes.value || null })
    message.value = `Request ${decision.toLowerCase()}.`
    reviewNotes.value = ''
    await load()
  } catch (requestError) {
    error.value = requestError.message
  }
}

onMounted(load)
</script>

<template>
  <PageHeader title="HR Requests" description="Review leave, overtime, and other employee requests." />
  <p v-if="error" class="form-error">{{ error }}</p>
  <p v-if="message" class="success-message">{{ message }}</p>

  <form class="filter-bar" @submit.prevent="load">
    <select v-model="filters.status">
      <option value="">All statuses</option>
      <option>Pending</option><option>Approved</option><option>Rejected</option>
    </select>
    <select v-model="filters.request_type">
      <option value="">All request types</option>
      <option>Leave</option><option>Overtime</option><option>Other</option>
    </select>
    <select v-model="filters.employee_id">
      <option value="">All employees</option>
      <option v-for="employee in employees" :key="employee.id" :value="employee.id">
        {{ employee.employee_no }} — {{ employee.full_name }}
      </option>
    </select>
    <input v-model="filters.from" type="date" aria-label="From date">
    <input v-model="filters.to" type="date" aria-label="To date">
    <button class="secondary-button">Apply filters</button>
  </form>
  <label v-if="sessionStore.can('hr.requests.approve')" class="review-notes">
    Review notes
    <input v-model.trim="reviewNotes" maxlength="500" placeholder="Optional notes for the selected action">
  </label>

  <WorkspaceTable
    :columns="[
      { key: 'created_at', label: 'Submitted' },
      { key: 'employee_no', label: 'Employee no.' },
      { key: 'full_name', label: 'Employee' },
      { key: 'request_type', label: 'Type' },
      { key: 'reason', label: 'Reason' },
      { key: 'start_date', label: 'Start' },
      { key: 'end_date', label: 'End' },
      { key: 'hours', label: 'Hours' },
      { key: 'status', label: 'Status' }
    ]"
    :rows="rows"
  >
    <template #cell-status="{ row }"><span class="status-badge">{{ row.status }}</span></template>
    <template v-if="sessionStore.can('hr.requests.approve')" #actions="{ row }">
      <button v-if="row.status === 'Pending'" @click="review(row.id, 'Approved')">Approve</button>
      <button v-if="row.status === 'Pending'" @click="review(row.id, 'Rejected')">Reject</button>
    </template>
  </WorkspaceTable>
</template>
