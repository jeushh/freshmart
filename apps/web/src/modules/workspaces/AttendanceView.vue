<script setup>
import { computed, onMounted, ref } from 'vue'
import { api } from '../../api/http.js'
import ServerPaginator from '../../components/common/ServerPaginator.vue'
import {
  UiButton,
  UiInput,
  UiPageHeader,
  UiSectionCard,
  UiSelect,
  UiStatusBadge,
  UiTableShell
} from '../../components/ui/index.js'
import { usePagination } from '../../composables/usePagination.js'
import { sessionStore } from '../../stores/session.js'

const rows = ref([])
const employees = ref([])
const error = ref('')
const formError = ref('')
const message = ref('')
const employeesError = ref('')
const loading = ref(false)
const creating = ref(false)
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

// Attendance statuses aren't in UiStatusBadge's default inferred-tone vocabulary,
// so tone is set explicitly here rather than teaching the shared component this
// file's own status set.
const STATUS_TONES = {
  Present: 'success',
  Late: 'warning',
  Absent: 'danger',
  'On Leave': 'info'
}
function statusTone(status) {
  return STATUS_TONES[status] || 'neutral'
}

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
  creating.value = true
  try {
    formError.value = ''
    message.value = ''
    await api.post('/attendance', form.value)
    message.value = 'Attendance entry saved.'
    await loadAttendance(1)
  } catch (caughtError) {
    formError.value = caughtError.message
  } finally {
    creating.value = false
  }
}

onMounted(() => Promise.all([loadAttendance(), loadEmployees()]))
</script>

<template>
  <UiPageHeader title="Attendance" description="Record and review employee time entries." />
  <p v-if="employeesError" class="form-error" role="alert">{{ employeesError }}</p>

  <UiSectionCard
    v-if="sessionStore.can('hr.attendance.edit')"
    title="New attendance entry"
    description="Record a time entry for an employee."
  >
    <form class="attendance-form" @submit.prevent="save">
      <div class="attendance-form__fields">
        <UiSelect v-model="form.employee_id" label="Employee" required>
          <option value="">Select employee</option>
          <option v-for="employee in employees" :key="employee.id" :value="employee.id">
            {{ employee.full_name }}
          </option>
        </UiSelect>
        <UiInput v-model="form.log_date" type="date" label="Date" required />
        <UiSelect v-model="form.status" label="Status">
          <option>Present</option>
          <option>Late</option>
          <option>Absent</option>
          <option>On Leave</option>
        </UiSelect>
        <UiInput v-model="form.time_in" type="time" label="Time in" />
        <UiInput v-model="form.time_out" type="time" label="Time out" />
      </div>
      <p v-if="formError" class="form-error" role="alert">{{ formError }}</p>
      <p v-if="message" class="success-message">{{ message }}</p>
      <UiButton type="submit" :loading="creating" loading-label="Saving">Save entry</UiButton>
    </form>
  </UiSectionCard>

  <UiTableShell
    title="Attendance log"
    :loading="loading"
    :error="error"
    :empty="!loading && !error && !rows.length"
    empty-title="No attendance records found"
    empty-description="Try different filters, or check back later."
    @retry="() => loadAttendance()"
  >
    <template #toolbar>
      <div class="filter-form">
        <UiSelect
          v-model="filters.employeeId"
          label="Employee"
          size="sm"
          :disabled="loading"
          @change="resetPageForFilterChange"
        >
          <option value="">All employees</option>
          <option v-for="employee in employees" :key="employee.id" :value="employee.id">
            {{ employee.full_name }}
          </option>
        </UiSelect>
        <UiInput
          v-model="filters.from"
          type="date"
          label="From"
          size="sm"
          :disabled="loading"
          @change="resetPageForFilterChange"
        />
        <UiInput
          v-model="filters.to"
          type="date"
          label="To"
          size="sm"
          :disabled="loading"
          @change="resetPageForFilterChange"
        />
        <UiButton v-if="hasFilters" type="button" variant="ghost" @click="clearFilters">
          Clear filters
        </UiButton>
      </div>
    </template>
    <table>
      <thead>
        <tr>
          <th>Date</th>
          <th>No.</th>
          <th>Employee</th>
          <th>Status</th>
          <th>In</th>
          <th>Out</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="row in rows" :key="row.id">
          <td>{{ row.log_date }}</td>
          <td>{{ row.employee_no }}</td>
          <td>{{ row.full_name }}</td>
          <td><UiStatusBadge :status="row.status" :tone="statusTone(row.status)" /></td>
          <td>{{ row.time_in || '—' }}</td>
          <td>{{ row.time_out || '—' }}</td>
        </tr>
      </tbody>
    </table>
    <template #footer>
      <ServerPaginator
        :current-page="page"
        :last-page="lastPage"
        :per-page="perPage"
        :total="total"
        @page-change="changePage"
      />
    </template>
  </UiTableShell>
</template>

<style scoped>
.attendance-form {
  display: flex;
  flex-direction: column;
  gap: var(--fm-space-4);
}
.attendance-form__fields {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(min(100%, 12rem), 1fr));
  gap: var(--fm-space-5);
}
.filter-form {
  display: flex;
  flex-wrap: wrap;
  gap: 0.75rem;
  align-items: end;
}
</style>
