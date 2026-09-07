<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { api } from '../../api/http.js'
import { sessionStore } from '../../stores/session.js'
import {
  UiAvatar,
  UiButton,
  UiErrorState,
  UiInput,
  UiPageHeader,
  UiSearchInput,
  UiSectionCard,
  UiSelect,
  UiStatusBadge,
  UiTableShell
} from '../../components/ui/index.js'
import { formatDateTime, formatMoney, formatNumber } from '../../utils/formatters.js'
import { PositionSalaryService } from '../../services/PositionSalaryService.js'

const canEdit = computed(() => sessionStore.can('hr.employees.edit'))

const rows = ref([])
const total = ref(0)
const loading = ref(true)
const loadError = ref('')
const search = ref('')
const departmentFilter = ref('')
const statusFilter = ref('')
const selectedId = ref(null)

const saving = ref(false)
const formError = ref('')
const formErrors = ref({})
const successMessage = ref('')
const form = ref(emptyForm())

const statusOptions = [
  { value: '', label: 'All statuses' },
  { value: 'Active', label: 'Active' },
  { value: 'On Leave', label: 'On Leave' },
  { value: 'Terminated', label: 'Terminated' }
]

const departmentOptions = ref([])
const positionOptions = ref([])
const loadingDepartments = ref(false)
const loadingPositions = ref(false)

async function loadDepartments() {
  loadingDepartments.value = true
  try {
    const data = await api.get('/departments')
    departmentOptions.value = data.map(d => ({ value: d.name, label: d.name }))
  } catch {
    // fallback handled by populateFromRows
  } finally {
    loadingDepartments.value = false
  }
}

async function loadPositions(department) {
  loadingPositions.value = true
  positionOptions.value = []
  if (!department) {
    loadingPositions.value = false
    return
  }
  try {
    const positions = PositionSalaryService.getDepartmentPositions(department)
    positionOptions.value = positions
  } catch {
    // fallback: fetch from API
  } finally {
    loadingPositions.value = false
  }
}

watch(
  () => form.value.department,
  async (newDepartment, oldDepartment) => {
    if (newDepartment === oldDepartment) return
    const previousPosition = form.value.position
    await loadPositions(newDepartment)
    const stillValid = positionOptions.value.some(p => p.value === previousPosition)
    form.value.position = stillValid ? previousPosition : ''
  }
)

function populateFromRows() {
  const values = new Set(rows.value.map(row => row.department).filter(Boolean))
  departmentOptions.value = Array.from(values).sort().map(v => ({ value: v, label: v }))
}

const filteredRows = computed(() => rows.value.filter(row => {
  if (departmentFilter.value && row.department !== departmentFilter.value) return false
  if (statusFilter.value && row.employment_status !== statusFilter.value) return false
  return true
}))

const selectedEmployee = computed(() => rows.value.find(row => row.id === selectedId.value) || null)

const selectedPositionSalary = computed(() => {
  if (!form.value.department || !form.value.position) return 0
  if (form.value.pay_type === 'hourly') {
    return PositionSalaryService.getPositionHourlyRate?.(form.value.position) ?? 0
  }
  const pos = positionOptions.value.find(p => p.value === form.value.position)
  if (pos) return pos.salary ?? 0
  return PositionSalaryService.getPositionSalary(form.value.position) ?? 0
})

const selectedPositionSalaryLabel = computed(() =>
  form.value.pay_type === 'hourly' ? 'Hourly Rate' : 'Basic Salary'
)

const selectedPositionSalaryPeriod = computed(() =>
  form.value.pay_type === 'hourly' ? '/ hour' : '/ month'
)

function emptyForm() {
  return {
    id: null,
    employee_code: '',
    name: '',
    email: '',
    phone: '',
    department: '',
    position: '',
    status: 'active',
    pay_type: 'monthly',
    basic_salary: 0,
    hourly_rate: 0,
    leave_balance: 15
  }
}

function requestMessage(requestError) {
  return requestError.requestId
    ? `${requestError.message} Reference: ${requestError.requestId}`
    : requestError.message
}

function fieldError(field) {
  return formErrors.value[field]?.[0] || ''
}

function statusTone(status) {
  const value = (status || '').toLowerCase()
  if (value === 'active') return 'success'
  if (value === 'on leave') return 'warning'
  if (value === 'terminated') return 'danger'
  return 'neutral'
}

function hasEmergencyContact(row) {
  return Boolean(row?.emergency_contact_name || row?.emergency_contact_phone)
}

function selectEmployee(row) {
  selectedId.value = selectedId.value === row.id ? null : row.id
}

function closeProfile() {
  selectedId.value = null
}

function scrollToSection(id) {
  document.getElementById(id)?.scrollIntoView({ block: 'start' })
}

const statusApiToForm = {
  Active: 'active',
  'On Leave': 'on_leave',
  Terminated: 'terminated'
}

function editEmployee(row) {
  formError.value = ''
  formErrors.value = {}
  successMessage.value = ''
  form.value = {
    id: row.id,
    employee_code: row.employee_no,
    name: row.full_name,
    email: row.email || '',
    phone: row.phone || '',
    department: row.department || '',
    position: row.position || '',
    status: statusApiToForm[row.employment_status] || 'active',
    pay_type: row.pay_type === 'Hourly' ? 'hourly' : 'monthly',
    basic_salary: row.basic_salary || 0,
    hourly_rate: row.hourly_rate || 0,
    leave_balance: row.leave_balance ?? 15
  }
  scrollToSection('add-employee')
}

function cancelEdit() {
  form.value = emptyForm()
  positionOptions.value = []
  formError.value = ''
  formErrors.value = {}
  successMessage.value = ''
}

async function load(showLoading = true) {
  if (showLoading) loading.value = true
  loadError.value = ''
  try {
    const params = { per_page: 100 }
    if (search.value.trim()) params.search = search.value.trim()
    const data = await api.employees(params)
    rows.value = data.data || []
    total.value = data.total ?? rows.value.length
    if (selectedId.value && !rows.value.some(row => row.id === selectedId.value)) {
      selectedId.value = null
    }
    populateFromRows()
    await loadDepartments()
  } catch (requestError) {
    loadError.value = requestMessage(requestError)
  } finally {
    if (showLoading) loading.value = false
  }
}

let searchTimer = null
watch(search, () => {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(() => load(false), 300)
})

async function save() {
  saving.value = true
  formError.value = ''
  formErrors.value = {}
  successMessage.value = ''
  const savedName = form.value.name
  const editingId = form.value.id

  try {
    const payload = { ...form.value }
    delete payload.basic_salary
    delete payload.hourly_rate
    if (editingId) {
      await api.put(`/employees/${editingId}`, payload)
      successMessage.value = `${savedName} has been updated.`
    } else {
      await api.post('/employees', payload)
      successMessage.value = `${savedName} has been added.`
    }
    form.value = emptyForm()
    positionOptions.value = []
    await load(false)
  } catch (requestError) {
    formError.value = requestMessage(requestError)
    formErrors.value = requestError.errors || {}
  } finally {
    saving.value = false
  }
}

onMounted(load)
</script>

<template>
  <div class="fm-employees">
    <UiPageHeader
      title="Employees"
      description="Search, review, and create employee records."
    >
      <template v-if="canEdit" #actions>
        <UiButton @click="scrollToSection('add-employee')">Add employee</UiButton>
      </template>
    </UiPageHeader>

    <UiErrorState
      v-if="loadError"
      title="Unable to load employees"
      :message="loadError"
      :retrying="loading"
      @retry="load"
    />

    <template v-else>
      <UiSectionCard
        v-if="selectedEmployee"
        class="fm-employees__profile"
        :title="selectedEmployee.full_name"
        :description="`Employee No. ${selectedEmployee.employee_no}`"
      >
        <template #actions>
          <div class="fm-employees__profile-actions">
            <UiButton v-if="canEdit" size="sm" @click="editEmployee(selectedEmployee)">Edit</UiButton>
            <UiButton variant="ghost" size="sm" @click="closeProfile">Close</UiButton>
          </div>
        </template>

        <div class="fm-employees__profile-header">
          <UiAvatar :name="selectedEmployee.full_name" size="lg" />
          <div class="fm-employees__profile-badges">
            <UiStatusBadge :status="selectedEmployee.department || '—'" tone="info" />
            <UiStatusBadge :status="selectedEmployee.position || '—'" tone="neutral" />
            <UiStatusBadge
              :status="selectedEmployee.employment_status"
              :tone="statusTone(selectedEmployee.employment_status)"
            />
          </div>
        </div>

        <div class="fm-employees__profile-grid">
          <section aria-labelledby="fm-employees-personal">
            <h3 id="fm-employees-personal">Personal information</h3>
            <dl>
              <div><dt>Full name</dt><dd>{{ selectedEmployee.full_name }}</dd></div>
              <div><dt>Email</dt><dd>{{ selectedEmployee.email || '—' }}</dd></div>
              <div><dt>Phone</dt><dd>{{ selectedEmployee.phone || '—' }}</dd></div>
            </dl>
          </section>

          <section aria-labelledby="fm-employees-employment">
            <h3 id="fm-employees-employment">Employment information</h3>
            <dl>
              <div><dt>Position</dt><dd>{{ selectedEmployee.position || '—' }}</dd></div>
              <div><dt>Department</dt><dd>{{ selectedEmployee.department || '—' }}</dd></div>
              <div><dt>Pay type</dt><dd>{{ selectedEmployee.pay_type }}</dd></div>
              <div>
                <dt>{{ selectedEmployee.pay_type === 'Hourly' ? 'Hourly rate' : 'Basic salary' }}</dt>
                <dd>{{ formatMoney(selectedEmployee.pay_type === 'Hourly' ? selectedEmployee.hourly_rate : selectedEmployee.basic_salary) }}</dd>
              </div>
              <div><dt>Leave balance</dt><dd>{{ formatNumber(selectedEmployee.leave_balance) }} days</dd></div>
              <div><dt>Hire date</dt><dd>{{ selectedEmployee.hire_date || '—' }}</dd></div>
            </dl>
          </section>

          <section aria-labelledby="fm-employees-account">
            <h3 id="fm-employees-account">Account information</h3>
            <dl>
              <div><dt>Employee No.</dt><dd class="fm-employees__monospace">{{ selectedEmployee.employee_no }}</dd></div>
              <div>
                <dt>Status</dt>
                <dd><UiStatusBadge :status="selectedEmployee.employment_status" :tone="statusTone(selectedEmployee.employment_status)" /></dd>
              </div>
              <div><dt>Record created</dt><dd>{{ formatDateTime(selectedEmployee.created_at) }}</dd></div>
            </dl>
          </section>

          <section v-if="hasEmergencyContact(selectedEmployee)" aria-labelledby="fm-employees-emergency">
            <h3 id="fm-employees-emergency">Emergency contact</h3>
            <dl>
              <div><dt>Name</dt><dd>{{ selectedEmployee.emergency_contact_name || '—' }}</dd></div>
              <div><dt>Phone</dt><dd>{{ selectedEmployee.emergency_contact_phone || '—' }}</dd></div>
            </dl>
          </section>
        </div>
      </UiSectionCard>

      <UiTableShell
        title="Employee directory"
        description="Every employee record available to your account."
        :loading="loading"
        :empty="!loading && filteredRows.length === 0"
        empty-title="No employees found"
        empty-description="Try a different search term or clear your filters."
      >
        <template #actions>
          <span class="fm-employees__record-count">{{ total }} {{ total === 1 ? 'employee' : 'employees' }}</span>
        </template>

        <template #toolbar>
          <div class="fm-employees__filters">
            <UiSearchInput
              v-model="search"
              label="Search employees"
              placeholder="Search by name or employee no."
              class="fm-employees__search"
            />
            <UiSelect v-model="departmentFilter" label="Department" size="sm">
              <option value="">All departments</option>
              <option v-for="department in departmentOptions" :key="department" :value="department">{{ department }}</option>
            </UiSelect>
            <UiSelect v-model="statusFilter" label="Status" size="sm">
              <option v-for="option in statusOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
            </UiSelect>
          </div>
        </template>

        <table class="fm-employees__table">
          <thead>
            <tr>
              <th scope="col">Employee</th>
              <th scope="col">Department</th>
              <th scope="col">Position</th>
              <th scope="col">Pay type</th>
              <th scope="col">Status</th>
              <th scope="col" class="fm-employees__actions-col">Actions</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in filteredRows" :key="row.id" :class="{ 'fm-employees__row--selected': row.id === selectedId }">
              <td>
                <div class="fm-employees__identity">
                  <UiAvatar :name="row.full_name" />
                  <div class="fm-employees__identity-copy">
                    <strong class="fm-employees__name">{{ row.full_name }}</strong>
                    <span class="fm-employees__employee-no">{{ row.employee_no }}</span>
                  </div>
                </div>
              </td>
              <td><UiStatusBadge :status="row.department || '—'" tone="info" /></td>
              <td><UiStatusBadge :status="row.position || '—'" tone="neutral" /></td>
              <td>{{ row.pay_type }}</td>
              <td><UiStatusBadge :status="row.employment_status" :tone="statusTone(row.employment_status)" /></td>
              <td>
                <UiButton variant="secondary" size="sm" @click="selectEmployee(row)">
                  {{ row.id === selectedId ? 'Hide profile' : 'View profile' }}
                </UiButton>
              </td>
            </tr>
          </tbody>
        </table>
      </UiTableShell>

      <UiSectionCard
        v-if="canEdit"
        id="add-employee"
        class="fm-employees__anchor"
        :title="form.id ? 'Edit employee' : 'Add employee'"
        :description="form.id ? `Update ${form.name || 'this employee'}'s record.` : 'Create a new employee record.'"
      >
        <form class="fm-employees__form" @submit.prevent="save">
          <fieldset class="fm-employees__fieldset">
            <legend>Personal information</legend>
            <UiInput v-model="form.name" label="Full name" placeholder="e.g. Maria Santos" :error="fieldError('name')" :disabled="saving" required />
            <UiInput v-model="form.email" type="email" label="Email" placeholder="name@example.com" :error="fieldError('email')" :disabled="saving" />
            <UiInput v-model="form.phone" label="Phone" placeholder="e.g. 0917 000 0000" :error="fieldError('phone')" :disabled="saving" />
          </fieldset>

          <fieldset class="fm-employees__fieldset">
            <legend>Employment information</legend>
            <UiSelect
              v-model="form.department"
              label="Department"
              :disabled="saving"
              :error="fieldError('department')"
              :loading="loadingDepartments"
            >
              <option value="">Select department</option>
              <option v-for="dept in departmentOptions" :key="dept.value" :value="dept.value">{{ dept.label }}</option>
            </UiSelect>
            <UiSelect
              v-model="form.position"
              label="Position"
              :disabled="saving || !form.department"
              :error="fieldError('position')"
              :loading="loadingPositions"
            >
              <option value="">Select position</option>
              <option v-for="pos in positionOptions" :key="pos.value" :value="pos.value">{{ pos.label }}</option>
            </UiSelect>
            <div v-if="form.department && form.position" class="ui-field">
              <span class="ui-field__label">{{ selectedPositionSalaryLabel }}</span>
              <span class="ui-field__control-wrap">
                <span class="ui-field-control fm-employees__salary-display">
                  {{ formatMoney(selectedPositionSalary) }}
                  <span class="fm-employees__salary-period">{{ selectedPositionSalaryPeriod }}</span>
                </span>
              </span>
            </div>
            <UiSelect v-model="form.pay_type" label="Pay type" :disabled="saving">
              <option value="monthly">Monthly</option>
              <option value="hourly">Hourly</option>
            </UiSelect>

            <UiInput
              v-model.number="form.leave_balance"
              type="number"
              min="0"
              step="0.5"
              label="Leave balance (days)"
              :error="fieldError('leave_balance')"
              :disabled="saving"
            />
          </fieldset>

          <fieldset class="fm-employees__fieldset">
            <legend>Account information</legend>
            <UiInput
              v-model="form.employee_code"
              label="Employee no."
              placeholder="e.g. EMP-0042"
              hint="Entered manually and must be unique."
              :error="fieldError('employee_code')"
              :disabled="saving"
              required
            />
            <UiSelect v-model="form.status" label="Status" :disabled="saving">
              <option value="active">Active</option>
              <option value="on_leave">On Leave</option>
              <option value="terminated">Terminated</option>
            </UiSelect>
          </fieldset>

          <div class="fm-employees__form-footer">
            <div aria-live="polite">
              <p v-if="formError" class="fm-employees__message fm-employees__message--error" role="alert">{{ formError }}</p>
              <p v-else-if="successMessage" class="fm-employees__message fm-employees__message--success">{{ successMessage }}</p>
              <p v-else-if="form.id" class="fm-employees__form-note">Changes are saved immediately.</p>
              <p v-else class="fm-employees__form-note">New employees are added immediately after creation.</p>
            </div>
            <div class="fm-employees__form-actions">
              <UiButton
                type="submit"
                :loading="saving"
                :loading-label="form.id ? 'Saving changes' : 'Adding employee'"
              >{{ form.id ? 'Save changes' : 'Add employee' }}</UiButton>
              <UiButton v-if="form.id" variant="secondary" type="button" :disabled="saving" @click="cancelEdit">Cancel edit</UiButton>
            </div>
          </div>
        </form>
      </UiSectionCard>
    </template>
  </div>
</template>

<style scoped>
.fm-employees {
  min-width: 0;
  display: grid;
  gap: var(--fm-space-6);
}

.fm-employees > .ui-page-header {
  margin-bottom: 0;
}

.fm-employees__anchor {
  scroll-margin-top: calc(var(--fm-shell-header-height) + var(--fm-space-4));
}

.fm-employees__record-count {
  display: inline-flex;
  align-items: center;
  min-height: var(--fm-control-height-sm);
  padding: 0 var(--fm-space-3);
  border-radius: var(--fm-radius-pill);
  background: var(--fm-color-slate-100);
  color: var(--fm-color-text-secondary);
  font-size: var(--fm-font-size-xs);
  font-weight: var(--fm-font-weight-semibold);
  white-space: nowrap;
}

.fm-employees__filters {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-end;
  gap: var(--fm-space-3);
  width: 100%;
}

.fm-employees__search {
  flex: 1 1 16rem;
  min-width: 12rem;
}

.fm-employees__filters .ui-field {
  flex: 0 0 auto;
  min-width: 10rem;
}

.fm-employees__table {
  width: 100%;
  border-collapse: collapse;
}

.fm-employees__table th,
.fm-employees__table td {
  padding: var(--fm-space-3) var(--fm-space-4);
  border-bottom: var(--fm-border-width) solid var(--fm-color-border);
  text-align: left;
  vertical-align: middle;
}

.fm-employees__table th {
  background: var(--fm-color-slate-50);
  color: var(--fm-color-text-muted);
  font-size: var(--fm-font-size-xs);
  font-weight: var(--fm-font-weight-semibold);
  letter-spacing: 0.05em;
  text-transform: uppercase;
  white-space: nowrap;
}

.fm-employees__table tbody tr:last-child td {
  border-bottom: 0;
}

.fm-employees__table tbody tr:hover {
  background: var(--fm-color-slate-50);
}

.fm-employees__row--selected {
  background: var(--fm-color-primary-50);
}

.fm-employees__actions-col {
  text-align: right;
}

.fm-employees__table td:last-child {
  text-align: right;
}

.fm-employees__salary-display {
  display: flex;
  align-items: center;
  gap: 0.25rem;
  background: var(--fm-color-slate-50);
  color: var(--fm-color-text);
  font-weight: var(--fm-font-weight-semibold);
  cursor: default;
}

.fm-employees__salary-period {
  font-weight: var(--fm-font-weight-normal);
  color: var(--fm-color-text-muted);
}

.fm-employees__identity {
  display: flex;
  align-items: center;
  gap: var(--fm-space-3);
  min-width: 0;
}

.fm-employees__identity-copy {
  display: grid;
  min-width: 0;
  gap: 0.125rem;
}

.fm-employees__name {
  color: var(--fm-color-text);
  font-size: var(--fm-font-size-sm);
  white-space: nowrap;
}

.fm-employees__employee-no {
  color: var(--fm-color-text-muted);
  font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
  font-size: var(--fm-font-size-xs);
}

.fm-employees__profile-actions {
  display: flex;
  align-items: center;
  gap: var(--fm-space-2);
}

.fm-employees__profile-header {
  display: flex;
  align-items: center;
  gap: var(--fm-space-4);
  margin-bottom: var(--fm-space-5);
}

.fm-employees__profile-badges {
  display: flex;
  flex-wrap: wrap;
  gap: var(--fm-space-2);
}

.fm-employees__profile-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(min(100%, 14rem), 1fr));
  gap: var(--fm-space-5);
}

.fm-employees__profile-grid h3 {
  margin: 0 0 var(--fm-space-3);
  color: var(--fm-color-text);
  font-size: var(--fm-font-size-sm);
  font-weight: var(--fm-font-weight-semibold);
  text-transform: uppercase;
  letter-spacing: 0.04em;
}

.fm-employees__profile-grid dl {
  display: grid;
  gap: var(--fm-space-3);
  margin: 0;
}

.fm-employees__profile-grid dl > div {
  display: grid;
  gap: 0.125rem;
}

.fm-employees__profile-grid dt {
  color: var(--fm-color-text-muted);
  font-size: var(--fm-font-size-xs);
}

.fm-employees__profile-grid dd {
  margin: 0;
  color: var(--fm-color-text);
  font-size: var(--fm-font-size-sm);
  font-weight: var(--fm-font-weight-medium);
}

.fm-employees__monospace {
  font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
}

.fm-employees__form {
  display: grid;
  gap: var(--fm-space-6);
}

.fm-employees__fieldset {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(min(100%, 14rem), 1fr));
  align-items: start;
  gap: var(--fm-space-5);
  margin: 0;
  padding: 0;
  border: 0;
}

.fm-employees__fieldset legend {
  grid-column: 1 / -1;
  padding: 0 0 var(--fm-space-2);
  color: var(--fm-color-text);
  font-size: var(--fm-font-size-sm);
  font-weight: var(--fm-font-weight-semibold);
  border-bottom: var(--fm-border-width) solid var(--fm-color-border);
  margin-bottom: var(--fm-space-1);
}

.fm-employees__form-footer {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--fm-space-4);
  padding-top: var(--fm-space-5);
  border-top: var(--fm-border-width) solid var(--fm-color-border);
}

.fm-employees__form-footer > div {
  min-width: 0;
}

.fm-employees__form-actions {
  display: flex;
  align-items: center;
  gap: var(--fm-space-2);
}

.fm-employees__form-note,
.fm-employees__message {
  margin: 0;
  font-size: var(--fm-font-size-sm);
}

.fm-employees__form-note {
  color: var(--fm-color-text-muted);
}

.fm-employees__message--error {
  color: var(--fm-color-danger-700);
}

.fm-employees__message--success {
  color: var(--fm-color-success-700);
}

@media (max-width: 44rem) {
  .fm-employees__table {
    min-width: 40rem;
  }

  .fm-employees__profile-header {
    flex-direction: column;
    align-items: flex-start;
  }

  .fm-employees__form-footer {
    align-items: stretch;
    flex-direction: column;
  }

  .fm-employees__form-actions {
    flex-direction: column;
  }

  .fm-employees__form-footer .ui-button {
    width: 100%;
  }
}
</style>
