<script setup>
import { computed, onMounted, ref } from 'vue'
import { api } from '../../api/http.js'
import { UiButton, UiInput, UiPageHeader, UiSelect, UiStatusBadge, UiTableShell } from '../../components/ui/index.js'
import { sessionStore } from '../../stores/session.js'

const rows = ref([])
const employees = ref([])
const filters = ref({ status: '', request_type: '', employee_id: '', from: '', to: '' })
const loading = ref(true)
const error = ref('')
const review = ref(null)
const note = ref('')
const submitting = ref(false)

const canReview = computed(() => sessionStore.can('hr.requests.approve'))

async function load() {
  loading.value = true
  error.value = ''
  try {
    const params = Object.fromEntries(Object.entries(filters.value).filter(([, value]) => value))
    const data = await api.get('/hr/requests', { ...params, per_page: 100 })
    rows.value = data.requests.data
    employees.value = data.employees
  } catch (requestError) {
    error.value = requestError.message
  } finally {
    loading.value = false
  }
}

function startReview(row, decision) {
  review.value = { id: row.id, decision, summary: `${row.request_type} — ${row.full_name}` }
  note.value = ''
}

function cancelReview() {
  review.value = null
  note.value = ''
}

async function confirmReview() {
  if (!review.value) return
  submitting.value = true
  error.value = ''
  try {
    await api.post(`/hr/requests/${review.value.id}/review`, { decision: review.value.decision, notes: note.value.trim() || null })
    cancelReview()
    await load()
  } catch (requestError) {
    error.value = requestError.message
  } finally {
    submitting.value = false
  }
}

onMounted(load)
</script>

<template>
  <UiPageHeader title="HR Requests" description="Review leave, overtime, and other employee requests." />

  <UiTableShell
    title="Request queue"
    :loading="loading"
    :error="error"
    :empty="!loading && !error && !rows.length"
    empty-title="No HR requests found"
    empty-description="Try different filters, or check back later."
    @retry="load"
  >
    <template #toolbar>
      <form class="filter-form" @submit.prevent="load">
        <UiSelect v-model="filters.status" label="Status" size="sm">
          <option value="">All statuses</option>
          <option>Pending</option>
          <option>Approved</option>
          <option>Rejected</option>
        </UiSelect>
        <UiSelect v-model="filters.request_type" label="Request type" size="sm">
          <option value="">All request types</option>
          <option>Leave</option>
          <option>Overtime</option>
          <option>Other</option>
        </UiSelect>
        <UiSelect v-model="filters.employee_id" label="Employee" size="sm">
          <option value="">All employees</option>
          <option v-for="employee in employees" :key="employee.id" :value="employee.id">
            {{ employee.employee_no }} — {{ employee.full_name }}
          </option>
        </UiSelect>
        <UiInput v-model="filters.from" type="date" label="From" size="sm" />
        <UiInput v-model="filters.to" type="date" label="To" size="sm" />
        <UiButton type="submit" variant="secondary" size="sm">Apply filters</UiButton>
      </form>
    </template>
    <table>
      <thead>
        <tr>
          <th>Submitted</th>
          <th>Employee no.</th>
          <th>Employee</th>
          <th>Type</th>
          <th>Reason</th>
          <th>Start</th>
          <th>End</th>
          <th>Hours</th>
          <th>Status</th>
          <th v-if="canReview">Actions</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="row in rows" :key="row.id">
          <td>{{ row.created_at }}</td>
          <td>{{ row.employee_no }}</td>
          <td>{{ row.full_name }}</td>
          <td>{{ row.request_type }}</td>
          <td>{{ row.reason }}</td>
          <td>{{ row.start_date }}</td>
          <td>{{ row.end_date }}</td>
          <td>{{ row.hours }}</td>
          <td><UiStatusBadge :status="row.status" /></td>
          <td v-if="canReview">
            <div v-if="row.status === 'Pending'" class="request-actions">
              <UiButton size="sm" @click="startReview(row, 'Approved')">Approve</UiButton>
              <UiButton size="sm" variant="destructive" @click="startReview(row, 'Rejected')">Reject</UiButton>
            </div>
          </td>
        </tr>
      </tbody>
    </table>
  </UiTableShell>

  <section v-if="review" class="ui-card review-panel" aria-labelledby="review-title">
    <h2 id="review-title">{{ review.decision }} request</h2>
    <p>{{ review.summary }}</p>
    <UiInput v-model="note" label="Review note" maxlength="500" hint="Optional. This note will be recorded with this action." />
    <p v-if="error" class="form-error" role="alert">{{ error }}</p>
    <div class="request-actions">
      <UiButton :loading="submitting" loading-label="Saving" @click="confirmReview">Confirm {{ review.decision }}</UiButton>
      <UiButton variant="secondary" :disabled="submitting" @click="cancelReview">Cancel</UiButton>
    </div>
  </section>
</template>

<style scoped>
.filter-form {
  display: flex;
  flex-wrap: wrap;
  gap: 0.75rem;
  align-items: end;
}
.request-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
}
.review-panel {
  margin-top: var(--fm-space-4);
  padding: var(--fm-space-5);
  display: grid;
  gap: var(--fm-space-3);
}
</style>
