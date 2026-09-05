<script setup>
import { onMounted, ref } from 'vue'
import { api } from '../../api/http.js'
import { sessionStore } from '../../stores/session.js'
import { formatMoney } from '../../utils/formatters.js'
import {
  UiButton,
  UiConfirmDialog,
  UiInput,
  UiPageHeader,
  UiSectionCard,
  UiSelect,
  UiStatusBadge,
  UiTableShell
} from '../../components/ui/index.js'

const rows = ref([])
const employees = ref([])
const form = ref({
  employee_id: '',
  pay_period_start: '',
  pay_period_end: '',
  regular_hours: 80,
  overtime_hours: 0,
  allowances: 0,
  bonuses: 0,
  deductions: 0
})
const loading = ref(true)
const error = ref('')
const message = ref('')
const creating = ref(false)
const review = ref(null)
const submitting = ref(false)

async function load() {
  loading.value = true
  error.value = ''
  try {
    await sessionStore.refreshSettings()
    const [d, e] = await Promise.all([api.payroll(), api.employees({ per_page: 100 })])
    rows.value = d.data
    employees.value = e.data
  } catch (requestError) {
    error.value = requestError.message
  } finally {
    loading.value = false
  }
}

async function save() {
  creating.value = true
  error.value = ''
  message.value = ''
  try {
    await api.post('/payroll', form.value)
    message.value = 'Payroll record created.'
    await load()
  } catch (requestError) {
    error.value = requestError.message
  } finally {
    creating.value = false
  }
}

function startReview(row, decision) {
  review.value = { id: row.id, decision, summary: `${row.full_name} — period ending ${row.pay_period_end}` }
}

function cancelReview() {
  review.value = null
}

async function confirmReview() {
  if (!review.value) return
  submitting.value = true
  error.value = ''
  try {
    await api.post(`/payroll/${review.value.id}/review`, { decision: review.value.decision })
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
  <UiPageHeader title="Payroll" description="Prepare, approve, and mark payroll as paid." />

  <UiSectionCard
    title="New payroll record"
    description="Prepare a payroll record for an employee's pay period."
  >
    <form class="payroll-form" @submit.prevent="save">
      <div class="payroll-form__fields">
        <UiSelect v-model="form.employee_id" label="Employee" required>
          <option value="">Select employee</option>
          <option v-for="employee in employees" :key="employee.id" :value="employee.id">
            {{ employee.full_name }}
          </option>
        </UiSelect>
        <UiInput v-model="form.pay_period_start" type="date" label="Pay period start" required />
        <UiInput
          v-model="form.pay_period_end"
          type="date"
          :min="form.pay_period_start"
          label="Pay period end"
          required
        />
        <UiInput
          v-model.number="form.regular_hours"
          type="number"
          min="0"
          step=".25"
          label="Regular hours"
        />
        <UiInput
          v-model.number="form.overtime_hours"
          type="number"
          min="0"
          step=".25"
          label="Overtime hours"
        />
      </div>
      <p v-if="error" class="form-error" role="alert">{{ error }}</p>
      <p v-if="message" class="success-message">{{ message }}</p>
      <UiButton type="submit" :loading="creating" loading-label="Creating">Create payroll</UiButton>
    </form>
  </UiSectionCard>

  <UiTableShell
    title="Payroll records"
    :loading="loading"
    :error="error"
    :empty="!loading && !error && !rows.length"
    empty-title="No payroll records found"
    empty-description="Create a payroll record above to get started."
    @retry="load"
  >
    <table>
      <thead>
        <tr>
          <th>Employee</th>
          <th>Period end</th>
          <th>Net pay</th>
          <th>Status</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="row in rows" :key="row.id">
          <td>{{ row.full_name }}</td>
          <td>{{ row.pay_period_end }}</td>
          <td>{{ formatMoney(row.net_pay) }}</td>
          <td><UiStatusBadge :status="row.status" /></td>
          <td>
            <div v-if="row.status === 'Draft'" class="payroll-actions">
              <UiButton size="sm" @click="startReview(row, 'Approved')">Approve</UiButton>
            </div>
            <div v-else-if="row.status === 'Approved'" class="payroll-actions">
              <UiButton size="sm" @click="startReview(row, 'Paid')">Mark paid</UiButton>
            </div>
          </td>
        </tr>
      </tbody>
    </table>
  </UiTableShell>

  <UiConfirmDialog
    :open="!!review"
    :title="`${review?.decision} payroll`"
    :description="review?.summary"
    :confirm-label="`Confirm ${review?.decision}`"
    :loading="submitting"
    loading-label="Saving"
    @confirm="confirmReview"
    @cancel="cancelReview"
  >
    <p v-if="error" class="form-error" role="alert">{{ error }}</p>
  </UiConfirmDialog>
</template>

<style scoped>
.payroll-form {
  display: flex;
  flex-direction: column;
  gap: var(--fm-space-4);
}
.payroll-form__fields {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(min(100%, 14rem), 1fr));
  gap: var(--fm-space-5);
}
.payroll-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
}
</style>
