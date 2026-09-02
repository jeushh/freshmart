<script setup>
import { computed, onMounted, ref } from 'vue'
import { api } from '../../api/http.js'
import PageHeader from '../../components/common/PageHeader.vue'
import ServerPaginator from '../../components/common/ServerPaginator.vue'
import WorkspaceTable from '../../components/common/WorkspaceTable.vue'
import UiButton from '../../components/ui/UiButton.vue'
import { usePagination } from '../../composables/usePagination.js'
import { sessionStore } from '../../stores/session.js'

const rows = ref([])
const employees = ref([])
const error = ref('')
const employeesError = ref('')
const loading = ref(false)
const filters = ref({
  employeeId: '',
  from: '',
  to: ''
})
const form = ref({
  employee_id: '',
  log_date: new Date().toISOString().slice(0, 10),
  status: 'Present',
  time_in: '',
  time_out: '',
  notes: ''
})
const {
  page,
  perPage,
  total,
  lastPage,
  goToPage,
  resetPage,
  setMetadata
} = usePagination()

const hasFilters = computed(() => Object.values(filters.value).some(Boolean))
const columns = [
  { key: 'log_date', label: 'Date' },
  { key: 'employee_no', label: 'No.' },
  { key: 'full_name', label: 'Employee' },
  { key: 'status', label: 'Status' },
  { key: 'time_in', label: 'In' },
  { key: 'time_out', label: 'Out' }
]

// Monotonic token for stale-response guard (Corrections 2 & 3).
// Each loadAttendance call captures the current sequence; results are
// only applied if the sequence still matches, preventing out-of-order
// responses from overwriting newer state. On failure the page is also
// restored so the paginator never disagrees with the displayed rows.
let requestSequence = 0

function attendanceParams() {
  return {
    page: page.value,
    per_page: perPage.value,
    ...(filters.value.employeeId && { employee_id: filters.value.employeeId }),
    ...(filters.value.from && { from: filters.value.from }),
    ...(filters.value.to && { to: filters.value.to })
  }
}

async function loadAttendance(targetPage = null) {
  const requestId = ++requestSequence
  const savedPage = page.value
  loading.value = true
  error.value = ''
  if (targetPage !== null) {
    goToPage(targetPage)
  }
  try {
    const response = await api.attendance(attendanceParams())

    if (requestId === requestSequence) {
      rows.value = response.data
      setMetadata(response)
    }
  } catch (caughtError) {
    if (requestId === requestSequence) {
      error.value = caughtError.message
      page.value = savedPage
    }
  } finally {
    if (requestId === requestSequence) {
      loading.value = false
    }
  }
}

async function loadEmployees() {
  try {
    const response = await api.employees({ per_page: 100 })
    employees.value = response.data
  } catch (caughtError) {
    employeesError.value = caughtError.message
  }
}

function clearFilters() {
  filters.value = { employeeId: '', from: '', to: '' }
  resetPage()
  return loadAttendance()
}

function changePage(requestedPage) {
  return loadAttendance(requestedPage)
}

function resetPageForFilterChange() {
  resetPage()
  return loadAttendance()
}

async function save() {
  try {
    error.value = ''
    await api.post('/attendance', form.value)
    await loadAttendance(1)
  } catch (caughtError) {
    error.value = caughtError.message
  }
}

onMounted(() => Promise.all([loadAttendance(), loadEmployees()]))
</script>

<template>
  <PageHeader title="Attendance" description="Record and review employee time entries." />
   <p v-if="error" class="form-error">{{ error }}</p>
   <p v-if="employeesError" class="form-error">{{ employeesError }}</p>

  <form
    v-if="sessionStore.can('hr.attendance.edit')"
    class="inline-form"
    @submit.prevent="save"
  >
    <select v-model="form.employee_id" required>
      <option value="">Employee</option>
      <option v-for="employee in employees" :key="employee.id" :value="employee.id">
        {{ employee.full_name }}
      </option>
    </select>
    <input v-model="form.log_date" type="date" required>
    <select v-model="form.status">
      <option>Present</option>
      <option>Late</option>
      <option>Absent</option>
      <option>On Leave</option>
    </select>
    <input v-model="form.time_in" type="time">
    <input v-model="form.time_out" type="time">
    <button class="primary-button">Save entry</button>
  </form>

  <div class="inline-form">
   <select
     v-model="filters.employeeId"
     aria-label="Filter attendance by employee"
     :disabled="loading"
     @change="resetPageForFilterChange"
   >
     <option value="">All employees</option>
     <option v-for="employee in employees" :key="employee.id" :value="employee.id">
       {{ employee.full_name }}
     </option>
   </select>
   <input
     v-model="filters.from"
     type="date"
     aria-label="Filter attendance from date"
     :disabled="loading"
     @change="resetPageForFilterChange"
   >
   <input
     v-model="filters.to"
     type="date"
     aria-label="Filter attendance to date"
     :disabled="loading"
     @change="resetPageForFilterChange"
   >
    <UiButton v-if="hasFilters" type="button" variant="ghost" @click="clearFilters">
      Clear filters
    </UiButton>
  </div>

  <p v-if="loading" class="field-help" aria-live="polite">Loading attendance…</p>
  <WorkspaceTable
    v-else
    :columns="columns"
    :rows="rows"
    empty="No attendance records found."
  />
  <ServerPaginator
    v-if="!loading"
    :current-page="page"
    :last-page="lastPage"
    :per-page="perPage"
    :total="total"
    @page-change="changePage"
  />
</template>
